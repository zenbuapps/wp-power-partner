<?php
/**
 * FetchPowerCloud 整合測試（issue #13）
 *
 * 驗證 disable_site / enable_site 依 HTTP status 回報成敗：
 *  - 2xx → true
 *  - 4xx / 5xx → false（不再誤判為成功）
 *  - WP_Error → false
 * 以及 DisableSiteScheduler::action_callback() 依 API 結果
 * 寫入「已停用網站」/「停用網站失敗」訂單備註。
 */

declare( strict_types=1 );

namespace Tests\Integration;

use J7\PowerPartner\Api\FetchPowerCloud;
use J7\PowerPartner\Api\Main;
use J7\PowerPartner\Domains\Site\Services\DisableSiteScheduler;
use J7\PowerPartner\Product\SiteSync;

/**
 * @group smoke
 * @group happy
 * @group error
 * @group edge
 */
class FetchPowerCloudTest extends TestCase {

	/** @var array{url: string, args: array<string, mixed>}|null 攔截到的最後一個 HTTP 請求 */
	private ?array $last_request = null;

	/** @var callable|null 目前掛載的 pre_http_request callback */
	private $http_mock = null;

	/**
	 * 設定（每個測試前執行）
	 */
	public function set_up(): void {
		parent::set_up();
		\set_transient( Main::POWERCLOUD_API_KEY_TRANSIENT_KEY, 'test-api-key-123' );
	}

	/**
	 * 清理（每個測試後執行）
	 */
	public function tear_down(): void {
		\delete_transient( Main::POWERCLOUD_API_KEY_TRANSIENT_KEY );
		if ( $this->http_mock ) {
			\remove_filter( 'pre_http_request', $this->http_mock, 10 );
			$this->http_mock = null;
		}
		$this->last_request = null;
		parent::tear_down();
	}

	/**
	 * 掛載 HTTP mock，攔截所有 wp_remote_* 請求
	 *
	 * @param int|\WP_Error $status_or_error HTTP status code 或 WP_Error
	 * @param string        $body            回應 body
	 */
	private function mock_http( int|\WP_Error $status_or_error, string $body = '{}' ): void {
		$this->http_mock = function ( $pre, $args, $url ) use ( $status_or_error, $body ) {
			$this->last_request = [
				'url'  => $url,
				'args' => $args,
			];
			if ( $status_or_error instanceof \WP_Error ) {
				return $status_or_error;
			}
			return [
				'headers'  => [],
				'body'     => $body,
				'response' => [
					'code'    => $status_or_error,
					'message' => '',
				],
				'cookies'  => [],
				'filename' => null,
			];
		};
		\add_filter( 'pre_http_request', $this->http_mock, 10, 3 );
	}

	/**
	 * 建立含訂閱商品（powercloud host_type）與 pp_linked_site_ids 的真實訂閱
	 *
	 * @return \WC_Subscription
	 */
	private function create_pp_subscription_with_product(): \WC_Subscription {
		$customer_id = $this->factory()->user->create( [ 'role' => 'customer' ] );

		// 訂閱商品（product_type term 設為 subscription，action_callback 才會處理）
		$product_id = $this->create_subscription_product();
		\wp_set_object_terms( $product_id, 'subscription', 'product_type' );
		$this->set_product_pp_meta( $product_id, 'powercloud' );

		$order = \wc_create_order(
			[
				'customer_id' => $customer_id,
				'status'      => 'processing',
			]
		);
		$this->assertInstanceOf( \WC_Order::class, $order, '建立父訂單失敗' );

		$product = \wc_get_product( $product_id );
		$this->assertNotFalse( $product, '取得商品失敗' );
		$order->add_product( $product );
		$order->save();

		$subscription = \wcs_create_subscription(
			[
				'order_id'         => $order->get_id(),
				'status'           => 'active',
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'customer_id'      => $customer_id,
			]
		);
		$this->assertInstanceOf( \WC_Subscription::class, $subscription, '建立訂閱失敗' );

		$subscription->update_meta_data( SiteSync::LINKED_SITE_IDS_META_KEY, '3022602' );
		$subscription->save();

		return $subscription;
	}

	/**
	 * 取得訂閱的所有訂單備註內容
	 *
	 * @param int $subscription_id 訂閱 ID
	 * @return array<string>
	 */
	private function get_order_notes( int $subscription_id ): array {
		$notes = \wc_get_order_notes(
			[
				'order_id' => $subscription_id,
				'limit'    => 50,
			]
		);
		return array_map( static fn( $note ) => (string) $note->content, $notes );
	}

	// ========== disable_site ==========

	/**
	 * @group smoke
	 * @group happy
	 */
	public function test_disable_site_returns_true_on_2xx(): void {
		$this->mock_http( 200 );

		$result = FetchPowerCloud::disable_site( '1', '3022602' );

		$this->assertTrue( $result, '2xx 回應應回傳 true' );
		$this->assertNotNull( $this->last_request, '應有發出 HTTP 請求' );
		$this->assertStringContainsString( '/wordpress/3022602/stop', $this->last_request['url'] );
	}

	/**
	 * @group error
	 */
	public function test_disable_site_returns_false_on_500(): void {
		$this->mock_http( 500, '{"message":"Internal Server Error"}' );

		$this->assertFalse(
			FetchPowerCloud::disable_site( '1', '3022602' ),
			'5xx 回應不可誤判為成功'
		);
	}

	/**
	 * @group error
	 */
	public function test_disable_site_returns_false_on_401(): void {
		$this->mock_http( 401, '{"message":"Unauthorized"}' );

		$this->assertFalse(
			FetchPowerCloud::disable_site( '1', '3022602' ),
			'401 回應不可誤判為成功'
		);
	}

	/**
	 * @group error
	 */
	public function test_disable_site_returns_false_on_wp_error(): void {
		$this->mock_http( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		$this->assertFalse(
			FetchPowerCloud::disable_site( '1', '3022602' ),
			'WP_Error 應回傳 false'
		);
	}

	// ========== enable_site ==========

	/**
	 * @group smoke
	 * @group happy
	 */
	public function test_enable_site_returns_true_on_2xx(): void {
		$this->mock_http( 200 );

		$result = FetchPowerCloud::enable_site( '1', '3022602' );

		$this->assertTrue( $result, '2xx 回應應回傳 true' );
		$this->assertNotNull( $this->last_request, '應有發出 HTTP 請求' );
		$this->assertStringContainsString( '/wordpress/3022602/start', $this->last_request['url'] );
	}

	/**
	 * @group error
	 */
	public function test_enable_site_returns_false_on_500(): void {
		$this->mock_http( 500, '{"message":"Internal Server Error"}' );

		$this->assertFalse(
			FetchPowerCloud::enable_site( '1', '3022602' ),
			'5xx 回應不可誤判為成功'
		);
	}

	/**
	 * @group error
	 */
	public function test_enable_site_returns_false_on_wp_error(): void {
		$this->mock_http( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		$this->assertFalse(
			FetchPowerCloud::enable_site( '1', '3022602' ),
			'WP_Error 應回傳 false'
		);
	}

	// ========== DisableSiteScheduler 訂單備註 ==========

	/**
	 * @group happy
	 */
	public function test_scheduler_writes_success_note_when_api_succeeds(): void {
		$this->skip_if_no_subscriptions();
		$subscription = $this->create_pp_subscription_with_product();
		$this->mock_http( 200 );

		DisableSiteScheduler::action_callback( [ 'subscription_id' => $subscription->get_id() ] );

		$notes = $this->get_order_notes( $subscription->get_id() );
		$this->assertNotEmpty(
			preg_grep( '/已停用網站/', $notes ),
			'API 成功時應寫入「已停用網站」備註，實際備註：' . print_r( $notes, true ) // phpcs:ignore
		);
		$this->assertEmpty(
			preg_grep( '/停用網站失敗/', $notes ),
			'API 成功時不得寫入失敗備註'
		);
	}

	/**
	 * @group error
	 */
	public function test_scheduler_writes_failure_note_when_api_fails(): void {
		$this->skip_if_no_subscriptions();
		$subscription = $this->create_pp_subscription_with_product();
		$this->mock_http( 500, '{"message":"Internal Server Error"}' );

		DisableSiteScheduler::action_callback( [ 'subscription_id' => $subscription->get_id() ] );

		$notes = $this->get_order_notes( $subscription->get_id() );
		$this->assertNotEmpty(
			preg_grep( '/停用網站失敗/', $notes ),
			'API 失敗時應寫入「停用網站失敗」備註，實際備註：' . print_r( $notes, true ) // phpcs:ignore
		);
		$this->assertEmpty(
			preg_grep( '/已停用網站/', $notes ),
			'API 失敗時不得寫入成功備註（issue #13 核心症狀）'
		);
	}

	/**
	 * @group edge
	 */
	public function test_scheduler_writes_failure_note_on_wp_error(): void {
		$this->skip_if_no_subscriptions();
		$subscription = $this->create_pp_subscription_with_product();
		$this->mock_http( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		DisableSiteScheduler::action_callback( [ 'subscription_id' => $subscription->get_id() ] );

		$notes = $this->get_order_notes( $subscription->get_id() );
		$this->assertNotEmpty(
			preg_grep( '/停用網站失敗/', $notes ),
			'連線失敗時應寫入「停用網站失敗」備註'
		);
	}
}
