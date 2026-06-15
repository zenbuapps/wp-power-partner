<?php
/**
 * 停用/啟用網站架構路由測試（issue #18）
 *
 * 驗證 DisableSiteScheduler 不再單純依產品 host_type 欄位、也不再
 * 「空值預設 powercloud」，而是：
 *  - 產品 host_type 有明確值（wpcd / powercloud）→ 直接採用
 *  - host_type 為空 → 依連結站 id 格式推斷（純數字 = WPCD、其餘 = PowerCloud）
 *
 * 核心症狀：舊 WPCD 站（數字 id、產品 host_type 空）停用時被導去
 * PowerCloud API（api.wpsite.pro）而靜默失敗，站照跑、卡照扣。
 *
 * 同時驗證 WPCD 停用路徑現在依回應寫成功/失敗備註，且 partner_id 為空時擋下。
 */

declare( strict_types=1 );

namespace Tests\Integration;

use J7\PowerPartner\Api\Connect;
use J7\PowerPartner\Api\Main;
use J7\PowerPartner\Domains\Site\Services\DisableSiteScheduler;
use J7\PowerPartner\Product\DataTabs\LinkedSites;
use J7\PowerPartner\Product\SiteSync;

/**
 * @group smoke
 * @group happy
 * @group error
 * @group edge
 */
class SiteDisableRoutingTest extends TestCase {

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
		\update_option( Connect::PARTNER_ID_OPTION_NAME, 'test-partner-001' );
	}

	/**
	 * 清理（每個測試後執行）
	 */
	public function tear_down(): void {
		\delete_transient( Main::POWERCLOUD_API_KEY_TRANSIENT_KEY );
		\delete_option( Connect::PARTNER_ID_OPTION_NAME );
		if ( $this->http_mock ) {
			\remove_filter( 'pre_http_request', $this->http_mock, 10 );
			$this->http_mock = null;
		}
		$this->last_request = null;
		parent::tear_down();
	}

	/**
	 * 掛載 HTTP mock，攔截所有 wp_remote_* 請求並記錄目標 URL
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
	 * 建立含訂閱商品與 pp_linked_site_ids 的真實訂閱
	 *
	 * @param string $host_type      產品 host_type meta（'' 表示不寫 meta，模擬 legacy 產品）
	 * @param string $linked_site_id 連結站 id（數字 = WPCD、其餘 = PowerCloud）
	 * @return \WC_Subscription
	 */
	private function create_subscription( string $host_type, string $linked_site_id ): \WC_Subscription {
		$customer_id = $this->factory()->user->create( [ 'role' => 'customer' ] );

		$product_id = $this->create_subscription_product();
		\wp_set_object_terms( $product_id, 'subscription', 'product_type' );
		// host_type 為空字串時不寫入 meta，模擬 migration 前的舊產品
		if ( '' !== $host_type ) {
			\update_post_meta( $product_id, LinkedSites::HOST_TYPE_FIELD_NAME, $host_type );
		}

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

		$subscription->update_meta_data( SiteSync::LINKED_SITE_IDS_META_KEY, $linked_site_id );
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

	// ========== LinkedSites::resolve_host_type 推斷邏輯 ==========

	/**
	 * @group smoke
	 */
	public function test_resolve_host_type_honors_explicit_wpcd(): void {
		$this->assertSame(
			LinkedSites::WPCD_HOST_TYPE,
			LinkedSites::resolve_host_type( 'wpcd', '999999' )
		);
	}

	/**
	 * 明確 powercloud 即使連結 id 是數字也採用，不被 id 格式覆寫（避免反向誤導）
	 *
	 * @group smoke
	 */
	public function test_resolve_host_type_honors_explicit_powercloud_even_for_numeric_id(): void {
		$this->assertSame(
			LinkedSites::DEFAULT_HOST_TYPE,
			LinkedSites::resolve_host_type( 'powercloud', '3022602' )
		);
	}

	/**
	 * @group happy
	 */
	public function test_resolve_host_type_empty_numeric_id_infers_wpcd(): void {
		$this->assertSame(
			LinkedSites::WPCD_HOST_TYPE,
			LinkedSites::resolve_host_type( '', '1376977' )
		);
	}

	/**
	 * @group happy
	 */
	public function test_resolve_host_type_empty_non_numeric_id_infers_powercloud(): void {
		$this->assertSame(
			LinkedSites::DEFAULT_HOST_TYPE,
			LinkedSites::resolve_host_type( '', 'wp-abc123' )
		);
	}

	// ========== DisableSiteScheduler 架構路由 ==========

	/**
	 * issue #18 核心：產品 host_type 空 + 數字 id → 必須送往 WPCD API，
	 * 不可被導去 PowerCloud。
	 *
	 * @group smoke
	 * @group happy
	 */
	public function test_empty_host_type_numeric_id_routes_to_wpcd(): void {
		$this->skip_if_no_subscriptions();
		$subscription = $this->create_subscription( '', '1376977' );
		$this->mock_http( 200 );

		DisableSiteScheduler::action_callback( [ 'subscription_id' => $subscription->get_id() ] );

		$this->assertNotNull( $this->last_request, '應發出 HTTP 請求' );
		$this->assertStringContainsString(
			'/wp-json/power-partner-server/v2/disable-site',
			$this->last_request['url'],
			'舊 WPCD 站（空 host_type + 數字 id）必須送往 WPCD disable API'
		);
		$this->assertStringNotContainsString(
			'/wordpress/1376977/stop',
			$this->last_request['url'],
			'不可被導去 PowerCloud API（issue #18 核心症狀）'
		);
	}

	/**
	 * 無回歸：明確 powercloud + 數字 id → 仍送 PowerCloud
	 *
	 * @group happy
	 */
	public function test_explicit_powercloud_routes_to_powercloud(): void {
		$this->skip_if_no_subscriptions();
		$subscription = $this->create_subscription( 'powercloud', '3022602' );
		$this->mock_http( 200 );

		DisableSiteScheduler::action_callback( [ 'subscription_id' => $subscription->get_id() ] );

		$this->assertNotNull( $this->last_request, '應發出 HTTP 請求' );
		$this->assertStringContainsString(
			'/wordpress/3022602/stop',
			$this->last_request['url'],
			'明確設定 powercloud 的站必須送往 PowerCloud API'
		);
	}

	/**
	 * 空 host_type + 非數字 id → PowerCloud（2026 空值預設開的新站）
	 *
	 * @group edge
	 */
	public function test_empty_host_type_non_numeric_id_routes_to_powercloud(): void {
		$this->skip_if_no_subscriptions();
		$subscription = $this->create_subscription( '', 'wp-abc123' );
		$this->mock_http( 200 );

		DisableSiteScheduler::action_callback( [ 'subscription_id' => $subscription->get_id() ] );

		$this->assertNotNull( $this->last_request, '應發出 HTTP 請求' );
		$this->assertStringContainsString(
			'/wordpress/wp-abc123/stop',
			$this->last_request['url'],
			'空 host_type + 非數字 id 應推斷為 PowerCloud'
		);
	}

	// ========== WPCD 停用路徑回應檢查（次要問題 1 & 2）==========

	/**
	 * WPCD 路徑 API 成功（2xx）→ 寫「已停用網站」備註
	 *
	 * @group happy
	 */
	public function test_wpcd_path_writes_success_note_on_2xx(): void {
		$this->skip_if_no_subscriptions();
		$subscription = $this->create_subscription( 'wpcd', '1376977' );
		$this->mock_http( 200 );

		DisableSiteScheduler::action_callback( [ 'subscription_id' => $subscription->get_id() ] );

		$notes = $this->get_order_notes( $subscription->get_id() );
		$this->assertNotEmpty(
			preg_grep( '/已停用網站/', $notes ),
			'WPCD API 成功時應寫入「已停用網站」備註，實際備註：' . print_r( $notes, true ) // phpcs:ignore
		);
		$this->assertEmpty(
			preg_grep( '/停用網站失敗/', $notes ),
			'WPCD API 成功時不得寫入失敗備註'
		);
	}

	/**
	 * WPCD 路徑 API 失敗（500）→ 寫「停用網站失敗」備註，不再無條件記成功
	 *
	 * @group error
	 */
	public function test_wpcd_path_writes_failure_note_on_500(): void {
		$this->skip_if_no_subscriptions();
		$subscription = $this->create_subscription( 'wpcd', '1376977' );
		$this->mock_http( 500, '{"message":"Internal Server Error"}' );

		DisableSiteScheduler::action_callback( [ 'subscription_id' => $subscription->get_id() ] );

		$notes = $this->get_order_notes( $subscription->get_id() );
		$this->assertNotEmpty(
			preg_grep( '/停用網站失敗/', $notes ),
			'WPCD API 失敗時應寫入「停用網站失敗」備註，實際備註：' . print_r( $notes, true ) // phpcs:ignore
		);
		$this->assertEmpty(
			preg_grep( '/已停用網站/', $notes ),
			'WPCD API 失敗時不得寫入成功備註'
		);
	}

	/**
	 * partner_id 為空時，WPCD 停用不送出 HTTP、直接記失敗（次要問題 2）
	 *
	 * @group edge
	 */
	public function test_wpcd_path_aborts_when_partner_id_empty(): void {
		$this->skip_if_no_subscriptions();
		\delete_option( Connect::PARTNER_ID_OPTION_NAME );
		$subscription = $this->create_subscription( 'wpcd', '1376977' );
		$this->mock_http( 200 );

		DisableSiteScheduler::action_callback( [ 'subscription_id' => $subscription->get_id() ] );

		$this->assertNull(
			$this->last_request,
			'partner_id 為空時不可送出停用請求（避免被後端拒絕後仍記成功）'
		);
		$notes = $this->get_order_notes( $subscription->get_id() );
		$this->assertNotEmpty(
			preg_grep( '/停用網站失敗/', $notes ),
			'partner_id 為空時應寫入失敗備註，實際備註：' . print_r( $notes, true ) // phpcs:ignore
		);
	}
}
