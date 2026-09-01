<?php
/**
 * 開站冪等與併發鎖整合測試（issue #24）
 *
 * 「付款完成事件重送」在 PHP 層就是同一個 handler 被呼叫兩次（兩個獨立 request）。
 * 因此模擬方式是連續呼叫兩次 site_sync_by_subscription()，且第二次前重新
 * wcs_get_subscription() 取得全新物件——這是關鍵，模擬第二個 request 的乾淨物件狀態，
 * 否則第一次的 in-memory meta 會污染測試（等於作弊）。
 *
 * 涵蓋：
 *   - 重送時只呼叫一次開站 API、不重複綁定、不重複排程、不覆寫既有回應
 *   - 開站失敗後的合法重試不被誤擋（只在 2xx 落旗標）
 *   - 多商品訂單各自開站互不擋掉、item meta 只存自己那筆
 *   - 併發鎖：擋下、逾時回收、正常釋放、例外釋放、守衛擋下時不留殘鎖
 *   - email_payloads_tmp FIFO 佇列不互相覆蓋
 *
 * @package power-partner
 */

declare( strict_types=1 );

namespace Tests\Integration;

use J7\PowerPartner\Api\Connect;
use J7\PowerPartner\Api\Main;
use J7\PowerPartner\Product\SiteSync;
use J7\PowerPartner\ShopSubscription;

/**
 * @group smoke
 * @group happy
 * @group error
 * @group edge
 */
class SiteSyncIdempotencyTest extends TestCase {

	/** @var int 攔截到的 HTTP 請求次數 */
	private int $request_count = 0;

	/** @var array{url: string, args: array<string, mixed>}|null 最後一個 HTTP 請求 */
	private ?array $last_request = null;

	/** @var callable|null 目前掛載的 pre_http_request callback */
	private $http_mock = null;

	/** @var array<int, array{to: string, subject: string, message: string}> 攔截到的寄信紀錄 */
	private array $sent_emails = [];

	public function set_up(): void {
		parent::set_up();
		\set_transient( Main::POWERCLOUD_API_KEY_TRANSIENT_KEY, 'test-api-key-123' );
		\update_option( Connect::PARTNER_ID_OPTION_NAME, 'test-partner-001' );
		$this->request_count = 0;
		$this->last_request  = null;
		$this->sent_emails   = [];
	}

	public function tear_down(): void {
		if ( null !== $this->http_mock ) {
			\remove_filter( 'pre_http_request', $this->http_mock, 10 );
			$this->http_mock = null;
		}
		parent::tear_down();
	}

	/**
	 * 掛載會計數的 HTTP mock
	 *
	 * @param int|\WP_Error $status_or_error HTTP status code 或 WP_Error
	 * @param string        $body            回應 body
	 * @return void
	 */
	private function mock_http( int|\WP_Error $status_or_error, string $body = '{}' ): void {
		if ( null !== $this->http_mock ) {
			\remove_filter( 'pre_http_request', $this->http_mock, 10 );
		}

		$this->http_mock = function ( $pre, $args, $url ) use ( $status_or_error, $body ) {
			++$this->request_count;
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
	 * 攔截寄信
	 *
	 * @return void
	 */
	private function mock_wp_mail(): void {
		\add_filter(
			'wp_mail',
			function ( $args ) {
				$this->sent_emails[] = [
					'to'      => is_array( $args['to'] ) ? implode( ',', $args['to'] ) : (string) $args['to'],
					'subject' => (string) ( $args['subject'] ?? '' ),
					'message' => (string) ( $args['message'] ?? '' ),
				];
				return false;
			},
			10,
			1
		);
	}

	/**
	 * 建立 PowerCloud 訂閱（可指定商品數量，模擬多商品訂單）
	 *
	 * @param int    $product_count 商品數量
	 * @param string $host_type     商品 host_type
	 * @return \WC_Subscription
	 */
	private function create_subscription( int $product_count = 1, string $host_type = 'powercloud' ): \WC_Subscription {
		$customer_id = $this->factory()->user->create(
			[
				'role'       => 'customer',
				'user_email' => 'customer-' . uniqid() . '@example.com',
				'user_login' => 'customer-' . uniqid(),
			]
		);

		$order = \wc_create_order(
			[
				'customer_id' => $customer_id,
				'status'      => 'processing',
			]
		);
		$this->assertInstanceOf( \WC_Order::class, $order );

		for ( $i = 0; $i < $product_count; $i++ ) {
			$product_id = $this->create_subscription_product();
			\wp_set_object_terms( $product_id, 'subscription', 'product_type' );
			$this->set_product_pp_meta( $product_id, $host_type, 'tpl-' . ( $i + 1 ), 'tw', 'plan-001' );

			$product = \wc_get_product( $product_id );
			$this->assertNotFalse( $product );
			$order->add_product( $product );
		}

		$order->set_billing_email( 'customer-' . uniqid() . '@example.com' );
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
		$this->assertInstanceOf( \WC_Subscription::class, $subscription );
		$subscription->save();

		return $subscription;
	}

	/**
	 * 模擬「付款完成事件重送」：取全新訂閱物件後再跑一次
	 *
	 * @param int $subscription_id 訂閱 ID
	 * @return void
	 */
	private function replay_payment_complete( int $subscription_id ): void {
		$fresh = \wcs_get_subscription( $subscription_id );
		$this->assertInstanceOf( \WC_Subscription::class, $fresh );
		( new SiteSync() )->site_sync_by_subscription( $fresh, [] );
	}

	/**
	 * 取得訂單備註
	 *
	 * @param int $order_id 訂單／訂閱 ID
	 * @return array<string>
	 */
	private function get_order_notes( int $order_id ): array {
		$notes = \wc_get_order_notes(
			[
				'order_id' => $order_id,
				'limit'    => 50,
			]
		);
		return array_map( static fn( $note ) => (string) $note->content, $notes );
	}

	// ========== 冪等 ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_付款完成事件重送時只呼叫一次開站API(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_subscription();
		$this->mock_http( 201, (string) \wp_json_encode( [ 'websiteId' => 'ws-9001' ] ) );

		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );
		$this->assertSame( 1, $this->request_count, '第一次應呼叫開站 API' );

		$this->replay_payment_complete( $subscription->get_id() );

		$this->assertSame( 1, $this->request_count, '重送時不應再次呼叫開站 API（issue #24）' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_付款完成事件重送時寫入已略過重複開站的訂單備註(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_subscription();
		$this->mock_http( 201, (string) \wp_json_encode( [ 'websiteId' => 'ws-9002' ] ) );

		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );
		$this->replay_payment_complete( $subscription->get_id() );

		$notes = $this->get_order_notes( $subscription->get_id() );
		$this->assertNotEmpty(
			preg_grep( '/已略過重複開站請求/', $notes ),
			'應寫入「已略過重複開站請求」訂單備註'
		);
		$this->assertNotEmpty(
			preg_grep( '/訂單項目 #/', $notes ),
			'備註應指出是哪一個訂單項目'
		);
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_付款完成事件重送時不重複綁定pp_linked_site_ids(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_subscription();
		$this->mock_http( 201, (string) \wp_json_encode( [ 'websiteId' => 'ws-9003' ] ) );

		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );
		$this->replay_payment_complete( $subscription->get_id() );

		$this->assertCount(
			1,
			ShopSubscription::get_linked_site_ids( $subscription->get_id() ),
			'重送不應產生第二筆綁定'
		);
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_付款完成事件重送時不重複排程開通信(): void {
		$this->skip_if_no_subscriptions();

		$subscription    = $this->create_subscription();
		$subscription_id = $subscription->get_id();
		$this->mock_http( 201, (string) \wp_json_encode( [ 'websiteId' => 'ws-9004' ] ) );

		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );
		$this->replay_payment_complete( $subscription_id );

		$matched = 0;
		foreach ( \as_get_scheduled_actions(
			[
				'hook'     => 'powerhouse_delay_send_email',
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 100,
			]
		) as $action ) {
			$args = $action->get_args();
			if ( isset( $args['subscription_id'] ) && (int) $args['subscription_id'] === $subscription_id ) {
				++$matched;
			}
		}

		$this->assertSame( 1, $matched, '重送不應排程第二封開通信' );
	}

	/**
	 * 全部 item 被冪等擋下時 $responses 為空，不可覆寫既有紀錄
	 *
	 * @test
	 * @group edge
	 */
	public function test_付款完成事件重送時不覆寫既有的pp_create_site_responses(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_subscription();
		$this->mock_http( 201, (string) \wp_json_encode( [ 'websiteId' => 'ws-9005' ] ) );

		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );
		$this->replay_payment_complete( $subscription->get_id() );

		$parent_order = \wc_get_order( $subscription->get_parent_id() );
		$this->assertInstanceOf( \WC_Order::class, $parent_order );

		$responses = SiteSync::get_create_site_responses( $parent_order );
		$this->assertCount( 1, $responses, '重送不應把既有回應清成空陣列或追加第二筆' );
		$this->assertSame( 'ws-9005', $responses[0]['data']['websiteId'] ?? null );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_開站失敗後的合法重試不會被冪等旗標擋下(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_subscription();

		$this->mock_http( 400, (string) \wp_json_encode( [ 'message' => 'bad request' ] ) );
		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );
		$this->assertSame( 1, $this->request_count );
		$this->assertEmpty(
			ShopSubscription::get_linked_site_ids( $subscription->get_id() ),
			'開站失敗不應綁定站台'
		);

		// 換成成功回應再試一次
		$this->mock_http( 201, (string) \wp_json_encode( [ 'websiteId' => 'ws-9006' ] ) );
		$this->replay_payment_complete( $subscription->get_id() );

		$this->assertSame( 2, $this->request_count, '開站失敗不落冪等旗標，合法重試應照常呼叫 API' );
		$this->assertCount( 1, ShopSubscription::get_linked_site_ids( $subscription->get_id() ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_多商品訂單的每個項目各自開一站不互相擋掉(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_subscription( 2 );
		$this->mock_http( 201, (string) \wp_json_encode( [ 'websiteId' => 'ws-9007' ] ) );

		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		$this->assertSame( 2, $this->request_count, '兩個商品應各開一站——冪等鍵是 item 層級，不是訂閱層級' );
	}

	/**
	 * item meta 只保存自己那一筆（相鄰缺陷 N2 的回歸網）
	 *
	 * @test
	 * @group edge
	 */
	public function test_訂單項目meta只保存自己那一筆開站回應(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_subscription( 2 );
		$this->mock_http( 201, (string) \wp_json_encode( [ 'websiteId' => 'ws-9008' ] ) );

		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		$parent_order = \wc_get_order( $subscription->get_parent_id() );
		$this->assertInstanceOf( \WC_Order::class, $parent_order );

		$checked = 0;
		foreach ( $parent_order->get_items() as $item ) {
			$raw = $item->get_meta( SiteSync::CREATE_SITE_RESPONSES_ITEM_META_KEY, true );
			if ( ! is_string( $raw ) || '' === $raw ) {
				continue;
			}
			$decoded = json_decode( $raw, true );
			$this->assertIsArray( $decoded );
			$this->assertCount(
				1,
				$decoded,
				'每個 item 的 meta 只應含自己那一筆——原本存的是累積中的整個 $responses，'
				. '讀取端取第 0 筆會拿到別人的 websiteId → 停用時停錯站'
			);
			++$checked;
		}

		$this->assertSame( 2, $checked, '兩個 item 都應寫入自己的回應' );
	}

	// ========== 併發鎖 ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_併發鎖存在時開站請求被擋下且不呼叫API(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_subscription();
		$order_id     = $subscription->get_parent_id();

		// 模擬另一個開站程序正在進行中
		\add_option( SiteSync::SITE_SYNC_LOCK_PREFIX . $order_id, (string) time(), '', false );

		$this->mock_http( 201, (string) \wp_json_encode( [ 'websiteId' => 'ws-9009' ] ) );
		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		$this->assertSame( 0, $this->request_count, '鎖存在時不應呼叫開站 API' );
		$this->assertNotEmpty(
			preg_grep( '/偵測到重複的開站請求/', $this->get_order_notes( $subscription->get_id() ) ),
			'應寫入「偵測到重複的開站請求」訂單備註'
		);
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_併發鎖逾時後可重新取得並開站(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_subscription();
		$order_id     = $subscription->get_parent_id();

		// 殘鎖：超過 SITE_SYNC_LOCK_TIMEOUT
		\add_option(
			SiteSync::SITE_SYNC_LOCK_PREFIX . $order_id,
			(string) ( time() - SiteSync::SITE_SYNC_LOCK_TIMEOUT - 1 ),
			'',
			false
		);

		$this->mock_http( 201, (string) \wp_json_encode( [ 'websiteId' => 'ws-9010' ] ) );
		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		$this->assertSame( 1, $this->request_count, '逾時殘鎖應被清除並重新取得鎖' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_開站正常結束後釋放併發鎖(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_subscription();
		$this->mock_http( 201, (string) \wp_json_encode( [ 'websiteId' => 'ws-9011' ] ) );

		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		$this->assertFalse(
			\get_option( SiteSync::SITE_SYNC_LOCK_PREFIX . $subscription->get_parent_id() ),
			'正常結束後應釋放鎖'
		);
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_開站拋出例外時仍會釋放併發鎖(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_subscription();
		$this->mock_http( new \WP_Error( 'http_request_failed', '連線失敗' ) );

		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		$this->assertFalse(
			\get_option( SiteSync::SITE_SYNC_LOCK_PREFIX . $subscription->get_parent_id() ),
			'拋例外時 finally 仍應釋放鎖，否則前台 request 會被卡住 15 分鐘'
		);
	}

	/**
	 * 鎖必須在三道既有守衛「之後」才取，否則續訂事件會留下殘鎖
	 *
	 * @test
	 * @group edge
	 */
	public function test_續訂訂閱被守衛擋下時不會留下併發鎖(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_subscription();
		$order_id     = $subscription->get_parent_id();

		// 加一筆續訂訂單，讓 count($order_ids) !== 1 的守衛生效
		$renewal = \wc_create_order( [ 'status' => 'processing' ] );
		$this->assertInstanceOf( \WC_Order::class, $renewal );
		\WCS_Related_Order_Store::instance()->add_relation( $renewal, $subscription, 'renewal' );

		$this->mock_http( 201, (string) \wp_json_encode( [ 'websiteId' => 'ws-9012' ] ) );
		$fresh = \wcs_get_subscription( $subscription->get_id() );
		$this->assertInstanceOf( \WC_Subscription::class, $fresh );
		( new SiteSync() )->site_sync_by_subscription( $fresh, [] );

		$this->assertSame( 0, $this->request_count, '續訂不應觸發開站' );
		$this->assertFalse(
			\get_option( SiteSync::SITE_SYNC_LOCK_PREFIX . $order_id ),
			'被守衛擋下時不應留下殘鎖——否則同一個 process 內的後續開站會被連鎖擋掉'
		);
	}

	// ========== email_payloads_tmp FIFO 佇列 ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_多商品訂單兩站的開通信payload各自保留不互相覆蓋(): void {
		$this->skip_if_no_subscriptions();

		$subscription    = $this->create_subscription( 2 );
		$subscription_id = $subscription->get_id();
		$this->mock_http( 201, (string) \wp_json_encode( [ 'websiteId' => 'ws-9013' ] ) );

		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		$fresh = \wcs_get_subscription( $subscription_id );
		$this->assertInstanceOf( \WC_Subscription::class, $fresh );
		$queue = $fresh->get_meta( 'email_payloads_tmp' );

		$this->assertIsArray( $queue );
		$this->assertTrue( array_is_list( $queue ), 'email_payloads_tmp 應為 FIFO 佇列（list）' );
		$this->assertCount( 2, $queue, '兩個站的 payload 應各自保留，不可互相覆蓋' );
		$this->assertNotSame(
			$queue[0]['SITEPASSWORD'] ?? null,
			$queue[1]['SITEPASSWORD'] ?? null,
			'兩個站應有各自的密碼'
		);

		// 第一次寄信取走第 0 筆、留下第 1 筆
		$this->mock_wp_mail();
		( new SiteSync() )->send_email( 'site@example.com', $subscription_id );

		$after_first = \wcs_get_subscription( $subscription_id );
		$this->assertInstanceOf( \WC_Subscription::class, $after_first );
		$remaining = $after_first->get_meta( 'email_payloads_tmp' );
		$this->assertIsArray( $remaining );
		$this->assertCount( 1, $remaining, '寄出第一封後應還剩一筆' );

		// 第二次寄信取走最後一筆並刪 meta
		( new SiteSync() )->send_email( 'site@example.com', $subscription_id );

		$after_second = \wcs_get_subscription( $subscription_id );
		$this->assertInstanceOf( \WC_Subscription::class, $after_second );
		$this->assertEmpty(
			$after_second->get_meta( 'email_payloads_tmp' ),
			'佇列清空後應刪除 meta（維持既有語義）'
		);
	}

	/**
	 * 升級相容：舊格式（單筆 assoc）的 meta 仍能正常寄出
	 *
	 * 發版當下可能已有排好但未執行的 powerhouse_delay_send_email，其 meta 是舊格式。
	 *
	 * @test
	 * @group edge
	 */
	public function test_舊格式的email_payloads_tmp仍能正常寄出(): void {
		$this->skip_if_no_subscriptions();

		$subscription    = $this->create_subscription();
		$subscription_id = $subscription->get_id();

		// 舊格式：單筆 assoc，不是 list
		$subscription->update_meta_data(
			'email_payloads_tmp',
			[
				'FRONTURL'     => 'https://legacy.wpsite.pro',
				'ADMINURL'     => 'https://legacy.wpsite.pro/wp-admin',
				'SITEUSERNAME' => 'legacy@example.com',
				'SITEPASSWORD' => 'legacy-pass',
			]
		);
		$subscription->save();

		$this->mock_wp_mail();
		( new SiteSync() )->send_email( 'legacy@example.com', $subscription_id );

		$fresh = \wcs_get_subscription( $subscription_id );
		$this->assertInstanceOf( \WC_Subscription::class, $fresh );
		$this->assertEmpty(
			$fresh->get_meta( 'email_payloads_tmp' ),
			'舊格式寄出後應刪除 meta'
		);
	}

	// ========== code review 修正後的行為 ==========

	/**
	 * 寄信被 issue #21 防呆擋下時，payload 必須保留（不可消費佇列）
	 *
	 * payload 是 wp_admin_password 唯一的存放處，消費掉就永久遺失、沒有補寄路徑。
	 *
	 * @test
	 * @group error
	 */
	public function test_開站通知信被防呆擋下時應保留payload以便補寄(): void {
		$this->skip_if_no_subscriptions();

		// 模板需要 SITEPASSWORD，但 payload 給不出來 → send_mail() 會主動中止寄送
		$this->setup_settings_with_emails(
			[
				$this->make_email_config(
					[
						'key'         => 'test_site_sync_' . uniqid(),
						'action_name' => 'site_sync',
						'body'        => '<p>密碼：##SITEPASSWORD##</p>',
						'enabled'     => '1',
					]
				),
			]
		);

		$reflection = new \ReflectionClass( \J7\PowerPartner\Domains\Email\Core\SubscriptionEmailHooks::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		$subscription    = $this->create_subscription();
		$subscription_id = $subscription->get_id();

		$subscription->update_meta_data(
			'email_payloads_tmp',
			[
				[
					'FRONTURL'     => 'https://x.wpsite.pro',
					'SITEPASSWORD' => '',
				],
			]
		);
		$subscription->save();

		$this->mock_wp_mail();
		( new SiteSync() )->send_email( 'site@example.com', $subscription_id );

		$fresh = \wcs_get_subscription( $subscription_id );
		$this->assertInstanceOf( \WC_Subscription::class, $fresh );
		$this->assertNotEmpty(
			$fresh->get_meta( 'email_payloads_tmp' ),
			'寄送被防呆擋下時必須保留 payload——它是 wp_admin_password 唯一的存放處'
		);

		$recipients = array_column( $this->sent_emails, 'to' );
		$this->assertNotContains(
			'site@example.com',
			$recipients,
			'防呆應中止寄給客戶（不寄半成品）'
		);
		$this->assertContains(
			(string) \get_option( 'admin_email' ),
			$recipients,
			'但要寄告警信給經銷商——否則沒有任何人知道客戶收不到帳密'
		);

		$property->setValue( null, null );
	}

	/**
	 * 部分重試時不可用「只含新項目」的回應覆寫既有紀錄
	 *
	 * @test
	 * @group edge
	 */
	public function test_部分重試時應合併而非覆寫pp_create_site_responses(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_subscription( 2 );
		$order_id     = $subscription->get_parent_id();

		$this->mock_http( 201, (string) \wp_json_encode( [ 'websiteId' => 'ws-first' ] ) );
		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		$parent_order = \wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $parent_order );
		$this->assertCount( 2, SiteSync::get_create_site_responses( $parent_order ) );

		// 清掉第二個 item 的冪等旗標，模擬「item 1 已完成、item 2 需重試」
		$items = array_values( $parent_order->get_items() );
		$items[1]->delete_meta_data( SiteSync::SITE_SYNC_DONE_META_KEY );
		$items[1]->save();

		$this->mock_http( 201, (string) \wp_json_encode( [ 'websiteId' => 'ws-second' ] ) );
		$this->replay_payment_complete( $subscription->get_id() );

		$after = \wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $after );

		$this->assertGreaterThanOrEqual(
			2,
			count( SiteSync::get_create_site_responses( $after ) ),
			'部分重試不應把既有紀錄覆寫成只剩重試的那一筆'
		);
	}

	/**
	 * 鎖競爭時應排一次延後重試，不可就此放棄
	 *
	 * @test
	 * @group error
	 */
	public function test_鎖競爭時應排程延後重試(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_subscription();
		$order_id     = $subscription->get_parent_id();

		\add_option( SiteSync::SITE_SYNC_LOCK_PREFIX . $order_id, (string) time(), '', false );

		$this->mock_http( 201, (string) \wp_json_encode( [ 'websiteId' => 'ws-lock' ] ) );
		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		$matched = 0;
		foreach ( \as_get_scheduled_actions(
			[
				'hook'     => SiteSync::RETRY_AFTER_LOCK_ACTION,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 100,
			]
		) as $action ) {
			$args = $action->get_args();
			if ( (int) ( $args['subscription_id'] ?? 0 ) === $subscription->get_id() ) {
				++$matched;
			}
		}

		$this->assertSame(
			1,
			$matched,
			'鎖競爭時應排一次延後重試——否則前一個程序若已 fatal，客戶付了錢永遠沒有站'
		);
	}

	/**
	 * 延後重試在前一次已成功時應被冪等旗標擋下
	 *
	 * @test
	 * @group edge
	 */
	public function test_延後重試在前次已成功時應被冪等旗標擋下(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_subscription();
		$this->mock_http( 201, (string) \wp_json_encode( [ 'websiteId' => 'ws-retry' ] ) );

		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );
		$this->assertSame( 1, $this->request_count );

		( new SiteSync() )->retry_site_sync_after_lock( $subscription->get_id() );

		$this->assertSame(
			1,
			$this->request_count,
			'前一次已成功時，延後重試應被冪等旗標擋下，不可再開一個站'
		);
	}

	// ========== 逾時接管後的鎖歸屬 ==========

	/**
	 * 殘鎖被接管後，原持有者的釋放不可刪掉接管者的鎖
	 *
	 * A 取鎖後開站極慢（> SITE_SYNC_LOCK_TIMEOUT），B 判定殘鎖並原子接管；
	 * A 完成時若無條件 delete_option()，就會把 B 正在持有的鎖刪掉，
	 * 保護窗口提前結束，下一個付款重送事件會在 B 尚未落冪等旗標時再開一個站。
	 *
	 * @test
	 * @group error
	 */
	public function test_殘鎖被接管後原持有者釋放不可刪掉接管者的鎖(): void {
		$this->skip_if_no_subscriptions();

		$lock_key = SiteSync::SITE_SYNC_LOCK_PREFIX . 999001;

		$reflection = new \ReflectionClass( SiteSync::class );
		$acquire    = $reflection->getMethod( 'acquire_lock' );
		$acquire->setAccessible( true );
		$release = $reflection->getMethod( 'release_lock' );
		$release->setAccessible( true );

		// A 取得鎖
		$a_value = $acquire->invoke( null, $lock_key, SiteSync::SITE_SYNC_LOCK_TIMEOUT );
		$this->assertNotNull( $a_value, 'A 應取得鎖' );

		// 讓 A 的鎖看起來已逾時（模擬 A 還活著但跑很久）
		\update_option( $lock_key, (string) ( time() - SiteSync::SITE_SYNC_LOCK_TIMEOUT - 10 ) );
		\wp_cache_delete( $lock_key, 'options' );

		// B 接管殘鎖
		$b_value = $acquire->invoke( null, $lock_key, SiteSync::SITE_SYNC_LOCK_TIMEOUT );
		$this->assertNotNull( $b_value, 'B 應能接管逾時殘鎖' );

		// A 這時才跑完，釋放它「以為」自己持有的鎖
		$release->invoke( null, $lock_key, (string) $a_value );

		\wp_cache_delete( $lock_key, 'options' );
		$this->assertNotFalse(
			\get_option( $lock_key ),
			'A 的釋放不可刪掉 B 的鎖——條件式 DELETE 應該 affect 0 row'
		);

		// B 自己釋放才真的清掉
		$release->invoke( null, $lock_key, (string) $b_value );
		\wp_cache_delete( $lock_key, 'options' );
		$this->assertFalse( \get_option( $lock_key ), 'B 釋放後鎖應消失' );
	}

	// ========== 開站回應的讀取優先序 ==========

	/**
	 * 先失敗後成功時，accessor 應回報成功那一筆
	 *
	 * pp_create_site_responses 改為「合併」而非覆寫後，第 0 筆會永遠是那次失敗的回應，
	 * 訂單列表欄位 / metabox / ##URL## fallback 會一直顯示錯誤資訊。
	 *
	 * @test
	 * @group edge
	 */
	public function test_先失敗後成功時accessor應回報成功那一筆(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_subscription();
		$order_id     = $subscription->get_parent_id();

		// 第一次開站失敗（非 2xx → 不落冪等旗標）
		$this->mock_http( 500, (string) \wp_json_encode( [ 'message' => 'boom' ] ) );
		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		// 事件重送，這次成功
		$this->mock_http( 201, (string) \wp_json_encode( [ 'websiteId' => 'ws-ok', 'url' => 'https://ok.wpsite.pro' ] ) );
		$this->replay_payment_complete( $subscription->get_id() );

		$parent_order = \wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $parent_order );

		$this->assertCount(
			2,
			SiteSync::get_create_site_responses( $parent_order ),
			'前置條件：兩筆回應都應保留（合併而非覆寫）'
		);

		$data = SiteSync::get_first_site_response_data( $parent_order );
		$this->assertSame(
			'ws-ok',
			$data['websiteId'] ?? null,
			'accessor 應回報第一筆成功的回應，而不是第 0 筆失敗的'
		);
	}

	// ========== 訂單備註的憑證遮罩 ==========

	/**
	 * 開站回應帶憑證時，訂單備註不可出現明文
	 *
	 * 訂單備註是經銷商可見、且會出現在 WooCommerce 訂單備註 REST API 的欄位。
	 *
	 * @test
	 * @group error
	 */
	public function test_開站回應帶憑證時訂單備註不可出現明文(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_subscription();
		$order_id     = $subscription->get_parent_id();

		$this->mock_http(
			201,
			(string) \wp_json_encode(
				[
					'websiteId' => 'ws-secret',
					'wordpress' => [
						'autoInstall' => [
							'adminUser'     => 'someone',
							'adminPassword' => 'SuperSecret123',
						],
					],
					'apiKey'    => 'ak_live_should_not_leak',
				]
			)
		);
		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		$notes = implode( "\n", $this->get_order_notes( $order_id ) );

		$this->assertStringNotContainsString( 'SuperSecret123', $notes, '訂單備註不可出現站台後台密碼明文' );
		$this->assertStringNotContainsString( 'ak_live_should_not_leak', $notes, '訂單備註不可出現 API key 明文' );
		$this->assertStringContainsString( 'ws-secret', $notes, '非敏感欄位仍應正常顯示' );

		// 存進 meta 的原文不動——DisableHooks 的相容 fallback 要讀 websiteId
		$parent_order = \wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $parent_order );
		$data = SiteSync::get_first_site_response_data( $parent_order );
		$this->assertSame( 'SuperSecret123', $data['wordpress']['autoInstall']['adminPassword'] ?? null, 'meta 應保留原文' );
	}

	// ========== FIFO 佇列的頭部阻塞 ==========

	/**
	 * 頭部 payload 持續失敗時，不可把後面的站一起卡死
	 *
	 * 排程數量與 payload 數量是一對一的，「失敗就原地保留不消費」會把排程額度
	 * 用光而佇列原地不動——第二個站的帳密永遠寄不出去。
	 *
	 * @test
	 * @group error
	 */
	public function test_頭部payload失敗不可卡住後面的站(): void {
		$this->skip_if_no_subscriptions();

		$this->setup_settings_with_emails(
			[
				$this->make_email_config(
					[
						'key'         => 'test_site_sync_' . uniqid(),
						'action_name' => 'site_sync',
						'body'        => '<p>密碼：##SITEPASSWORD##</p>',
						'enabled'     => '1',
					]
				),
			]
		);

		$reflection = new \ReflectionClass( \J7\PowerPartner\Domains\Email\Core\SubscriptionEmailHooks::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		$subscription    = $this->create_subscription();
		$subscription_id = $subscription->get_id();

		// 站 1 缺 SITEPASSWORD（必失敗）、站 2 完整（應寄得出去）
		$subscription->update_meta_data(
			'email_payloads_tmp',
			[
				[
					'FRONTURL'     => 'https://site-1.wpsite.pro',
					'ADMINURL'     => 'https://site-1.wpsite.pro/wp-admin',
					'SITEUSERNAME' => 'u1',
					'SITEPASSWORD' => '',
				],
				[
					'FRONTURL'     => 'https://site-2.wpsite.pro',
					'ADMINURL'     => 'https://site-2.wpsite.pro/wp-admin',
					'SITEUSERNAME' => 'u2',
					'SITEPASSWORD' => 'pw-2',
				],
			]
		);
		$subscription->save();

		$this->mock_wp_mail();
		// wp_mail 在測試環境的實際投遞會失敗，這裡短路成「投遞成功」，
		// 讓本測試只驗證佇列推進邏輯（mock_wp_mail 的 wp_mail filter 先跑，記錄照常）
		\add_filter( 'pre_wp_mail', '__return_true', 10, 1 );

		$site_sync = new SiteSync();
		$site_sync->send_email( 'site@example.com', $subscription_id );  // 排程 1：站 1 失敗
		$site_sync->send_email( 'site@example.com', $subscription_id );  // 排程 2：應輪到站 2

		$bodies = implode( "\n", array_column( $this->sent_emails, 'message' ) );
		$this->assertStringContainsString(
			'pw-2',
			$bodies,
			'站 1 失敗不可卡住站 2——第二個排程應該處理到站 2 的 payload'
		);

		$fresh = \wcs_get_subscription( $subscription_id );
		$this->assertInstanceOf( \WC_Subscription::class, $fresh );
		$queue = $fresh->get_meta( 'email_payloads_tmp' );
		\remove_filter( 'pre_wp_mail', '__return_true', 10 );
		$this->assertIsArray( $queue );
		$this->assertCount( 1, $queue, '站 2 已寄出、站 1 仍待重試，佇列應剩 1 筆' );
		$this->assertSame( 'https://site-1.wpsite.pro', $queue[0]['FRONTURL'] ?? null, '留下的應是失敗的站 1' );
	}

	/**
	 * 連續失敗達上限後應丟棄，避免佇列永遠卡住
	 *
	 * @test
	 * @group edge
	 */
	public function test_連續失敗達上限後應丟棄payload(): void {
		$this->skip_if_no_subscriptions();

		$this->setup_settings_with_emails(
			[
				$this->make_email_config(
					[
						'key'         => 'test_site_sync_' . uniqid(),
						'action_name' => 'site_sync',
						'body'        => '<p>密碼：##SITEPASSWORD##</p>',
						'enabled'     => '1',
					]
				),
			]
		);

		$reflection = new \ReflectionClass( \J7\PowerPartner\Domains\Email\Core\SubscriptionEmailHooks::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		$subscription    = $this->create_subscription();
		$subscription_id = $subscription->get_id();

		$subscription->update_meta_data(
			'email_payloads_tmp',
			[
				[
					'FRONTURL'     => 'https://x.wpsite.pro',
					'SITEPASSWORD' => '',
				],
			]
		);
		$subscription->save();

		$this->mock_wp_mail();

		$site_sync = new SiteSync();
		for ( $i = 0; $i < SiteSync::EMAIL_PAYLOAD_MAX_ATTEMPTS; $i++ ) {
			$site_sync->send_email( 'site@example.com', $subscription_id );
		}

		$fresh = \wcs_get_subscription( $subscription_id );
		$this->assertInstanceOf( \WC_Subscription::class, $fresh );
		$this->assertEmpty(
			$fresh->get_meta( 'email_payloads_tmp' ),
			'連續失敗達上限後應丟棄，否則佇列永遠卡住'
		);

		$notes = implode( "\n", $this->get_order_notes( $subscription_id ) );
		$this->assertStringContainsString( '停止重試', $notes, '放棄時應留下訂單備註，讓經銷商知道要手動補寄' );
	}

	// ========== branch review 修正 ==========

	/**
	 * 計算某訂閱的 pending 鎖競爭重試排程數
	 *
	 * @param int $subscription_id 訂閱 ID
	 * @return int
	 */
	private function count_retry_actions( int $subscription_id ): int {
		$matched = 0;
		foreach ( \as_get_scheduled_actions(
			[
				'hook'     => SiteSync::RETRY_AFTER_LOCK_ACTION,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 100,
			]
		) as $action ) {
			$args = $action->get_args();
			if ( (int) ( $args['subscription_id'] ?? 0 ) === $subscription_id ) {
				++$matched;
			}
		}
		return $matched;
	}

	/**
	 * 設定 site_sync 信件模板並重建 singleton
	 *
	 * @param array<int, array<string, mixed>> $configs 模板設定
	 * @return void
	 */
	private function setup_site_sync_emails( array $configs ): void {
		$this->setup_settings_with_emails( $configs );

		$reflection = new \ReflectionClass( \J7\PowerPartner\Domains\Email\Core\SubscriptionEmailHooks::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	/**
	 * 不同訂閱同時撞鎖時，必須各自排到重試
	 *
	 * ActionScheduler 的 $unique 判斷（ActionScheduler_DBStore::build_where_clause_for_insert）
	 * 只比對 hook 與 group_id，args 完全不進 WHERE。group 留空即 group_id = 0，
	 * 用它去重等於「全站只允許一個 pp_site_sync_retry_after_lock」——
	 * 訂閱 A 等重試的這 960 秒內，訂閱 B 的重試會被靜默丟棄（as_schedule_single_action 回 0，
	 * 而回傳值沒有任何人檢查），B 的客戶付了錢永遠沒有站。
	 *
	 * @test
	 * @group error
	 */
	public function test_不同訂閱同時撞鎖時應各自排到重試(): void {
		$this->skip_if_no_subscriptions();

		$sub_a = $this->create_subscription();
		$sub_b = $this->create_subscription();

		\add_option( SiteSync::SITE_SYNC_LOCK_PREFIX . $sub_a->get_parent_id(), (string) time(), '', false );
		\add_option( SiteSync::SITE_SYNC_LOCK_PREFIX . $sub_b->get_parent_id(), (string) time(), '', false );

		$this->mock_http( 201, (string) \wp_json_encode( [ 'websiteId' => 'ws-multi' ] ) );

		$site_sync = new SiteSync();
		$site_sync->site_sync_by_subscription( $sub_a, [] );
		$site_sync->site_sync_by_subscription( $sub_b, [] );

		$this->assertSame( 1, $this->count_retry_actions( $sub_a->get_id() ), 'A 應排到重試' );
		$this->assertSame(
			1,
			$this->count_retry_actions( $sub_b->get_id() ),
			'B 也必須排到重試——去重若用 AS 的 $unique（只看 hook + group_id），B 會被 A 擋掉而永遠不開站'
		);
	}

	/**
	 * 同一訂閱重複撞鎖時仍然只排一次重試
	 *
	 * 去重從 $unique 換成 as_has_scheduled_action() 之後，這條原本的保證不可退化。
	 *
	 * @test
	 * @group edge
	 */
	public function test_同一訂閱重複撞鎖仍只排一次重試(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_subscription();
		\add_option( SiteSync::SITE_SYNC_LOCK_PREFIX . $subscription->get_parent_id(), (string) time(), '', false );

		$this->mock_http( 201, (string) \wp_json_encode( [ 'websiteId' => 'ws-dup' ] ) );

		$site_sync = new SiteSync();
		$site_sync->site_sync_by_subscription( $subscription, [] );
		$this->replay_payment_complete( $subscription->get_id() );

		$this->assertSame(
			1,
			$this->count_retry_actions( $subscription->get_id() ),
			'同一訂閱多次撞鎖只該堆出一個重試'
		);
	}

	/**
	 * 開站回應 2xx 但缺 websiteId 時，必須留下可追查的告警
	 *
	 * 綁定站台比「HTTP 2xx」多一個條件：回應要真的帶回 websiteId。
	 * 缺了它 pp_linked_site_ids 是空的 → is_site_sync() 為 false →
	 * 停用/恢復、所有生命週期信、issue #22 補排全部靜默失效，
	 * 而冪等旗標仍會落下（刻意的：放行重試會讓 PowerCloud 再建一個站，計費不可逆）。
	 * 唯一能讓這件事被發現的就是這則告警。
	 *
	 * @test
	 * @group error
	 */
	public function test_開站回應缺websiteId時應留下可追查的告警(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_subscription();

		// 201 但 body 沒有 websiteId：對端契約異動、body 被截斷、或非同步受理都會長這樣
		$this->mock_http( 201, '{}' );
		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		$this->assertEmpty(
			ShopSubscription::get_linked_site_ids( $subscription->get_id() ),
			'前置條件：沒有 websiteId 就綁不了站'
		);

		$notes = implode( "\n", $this->get_order_notes( $subscription->get_id() ) );
		$this->assertStringContainsString(
			'websiteId',
			$notes,
			'綁不了站必須寫訂單備註——否則這個訂閱的停用/恢復與生命週期信全部失效，卻沒有任何人會知道'
		);

		$order = \wc_get_order( $subscription->get_parent_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$items = $order->get_items();
		$item  = reset( $items );
		$this->assertNotFalse( $item );
		$this->assertNotEmpty(
			$item->get_meta( SiteSync::SITE_SYNC_DONE_META_KEY, true ),
			'冪等旗標仍要落下：放行重試會讓 PowerCloud 再建一個站，計費與客戶困惑都不可逆'
		);
	}

	/**
	 * 寄信拋出例外時，佇列仍要前進並排定重試
	 *
	 * payload 已被 array_shift 取出，但佇列新狀態要等函式尾端的 save() 才落地。
	 * 例外若不接住，meta 不會改寫、不會排重試，而這個 powerhouse_delay_send_email
	 * 排程已經被消耗掉——排程數與 payload 數是一對一的，雙站訂單會因此剩下一份
	 * 沒有排程會去讀的 payload，而那是明文 wp_admin_password 唯一的存放處。
	 *
	 * @test
	 * @group error
	 */
	public function test_寄信拋出例外時佇列仍要前進並排定重試(): void {
		$this->skip_if_no_subscriptions();

		$this->setup_site_sync_emails(
			[
				$this->make_email_config(
					[
						'key'         => 'test_site_sync_' . uniqid(),
						'action_name' => 'site_sync',
						'body'        => '<p>前台：##FRONTURL##</p>',
						'enabled'     => '1',
					]
				),
			]
		);

		$subscription    = $this->create_subscription();
		$subscription_id = $subscription->get_id();
		$subscription->update_meta_data(
			'email_payloads_tmp',
			[
				[
					'FRONTURL'     => 'https://boom.wpsite.pro',
					'ADMINURL'     => 'https://boom.wpsite.pro/wp-admin',
					'SITEUSERNAME' => 'u1',
					'SITEPASSWORD' => 'pw-1',
				],
			]
		);
		$subscription->save();

		// 寄信外掛自己 throw 是真實情境
		$thrower = static function () {
			throw new \RuntimeException( 'mail plugin exploded' );
		};
		\add_filter( 'pre_wp_mail', $thrower, 10, 1 );

		( new SiteSync() )->send_email( 'site@example.com', $subscription_id );

		\remove_filter( 'pre_wp_mail', $thrower, 10 );

		$fresh = \wcs_get_subscription( $subscription_id );
		$this->assertInstanceOf( \WC_Subscription::class, $fresh );
		$queue = $fresh->get_meta( 'email_payloads_tmp' );
		$this->assertIsArray( $queue );
		$this->assertCount( 1, $queue, '例外不可讓 payload 消失，也不可讓佇列停在原地' );
		$this->assertSame(
			1,
			(int) ( $queue[0][ SiteSync::EMAIL_PAYLOAD_ATTEMPTS_KEY ] ?? 0 ),
			'例外要與 wp_mail 回 false 走同一條計數路徑'
		);
		$this->assertTrue(
			\as_has_scheduled_action(
				'powerhouse_delay_send_email',
				[
					'to'              => 'site@example.com',
					'subscription_id' => $subscription_id,
				]
			),
			'例外後必須補一個排程，否則這份 payload 再也沒有人會去讀'
		);
	}

	/**
	 * 重試時不可重寄「上一輪已寄達」的模板
	 *
	 * payload 是整份重排的，站台設兩個以上 site_sync 模板時（A 寄達、B 失敗），
	 * 沒有跳過機制就會在每次重試把 A 再寄給客戶一次，
	 * 最多讓客戶收到 EMAIL_PAYLOAD_MAX_ATTEMPTS 封內容相同的帳密信。
	 *
	 * @test
	 * @group error
	 */
	public function test_重試時不可重寄已寄達的模板(): void {
		$this->skip_if_no_subscriptions();

		$this->setup_site_sync_emails(
			[
				// A：不用站台變數 → 防呆不會擋，會真的寄出去
				$this->make_email_config(
					[
						'key'         => 'tpl_a_' . uniqid(),
						'action_name' => 'site_sync',
						'body'        => '<p>歡迎光臨 MARKER-A</p>',
						'enabled'     => '1',
					]
				),
				// B：用到 payload 給不出的 SITEPASSWORD → 被防呆擋下 → 整份 payload 重排
				$this->make_email_config(
					[
						'key'         => 'tpl_b_' . uniqid(),
						'action_name' => 'site_sync',
						'body'        => '<p>密碼：##SITEPASSWORD##</p>',
						'enabled'     => '1',
					]
				),
			]
		);

		$subscription    = $this->create_subscription();
		$subscription_id = $subscription->get_id();
		$subscription->update_meta_data(
			'email_payloads_tmp',
			[
				[
					'FRONTURL'     => 'https://partial.wpsite.pro',
					'SITEPASSWORD' => '',
				],
			]
		);
		$subscription->save();

		$bodies   = [];
		$recorder = static function ( $return, $atts ) use ( &$bodies ) {
			$bodies[] = (string) ( $atts['message'] ?? '' );
			return true;
		};
		\add_filter( 'pre_wp_mail', $recorder, 10, 2 );

		$site_sync = new SiteSync();
		$site_sync->send_email( 'site@example.com', $subscription_id );  // 第 1 輪：A 寄達、B 被擋
		$after_first = count( array_filter( $bodies, static fn( $b ) => str_contains( $b, 'MARKER-A' ) ) );

		$site_sync->send_email( 'site@example.com', $subscription_id );  // 第 2 輪：重試
		$after_second = count( array_filter( $bodies, static fn( $b ) => str_contains( $b, 'MARKER-A' ) ) );

		\remove_filter( 'pre_wp_mail', $recorder, 10 );

		$this->assertSame( 1, $after_first, '第一輪 A 應寄出' );
		$this->assertSame(
			1,
			$after_second,
			'重試不可再寄一次 A——payload 是整份重排的，客戶會收到重複的帳密信'
		);
	}

	/**
	 * 防呆中止的重試不可重複寄告警給經銷商
	 *
	 * 防呆中止是確定性的：payload 的 tokens 每輪都一樣，必然再次全擋。
	 * 不去重的話同一件事會寄出 EMAIL_PAYLOAD_MAX_ATTEMPTS 封相同告警，
	 * 把經銷商真正需要看的那一封淹掉。
	 *
	 * @test
	 * @group edge
	 */
	public function test_防呆重試時不可重複寄告警給經銷商(): void {
		$this->skip_if_no_subscriptions();

		$this->setup_site_sync_emails(
			[
				$this->make_email_config(
					[
						'key'         => 'test_site_sync_' . uniqid(),
						'action_name' => 'site_sync',
						'body'        => '<p>密碼：##SITEPASSWORD##</p>',
						'enabled'     => '1',
					]
				),
			]
		);

		$subscription    = $this->create_subscription();
		$subscription_id = $subscription->get_id();
		$subscription->update_meta_data(
			'email_payloads_tmp',
			[
				[
					'FRONTURL'     => 'https://alert.wpsite.pro',
					'SITEPASSWORD' => '',
				],
			]
		);
		$subscription->save();

		$admin_email = (string) \get_option( 'admin_email' );
		$recipients  = [];
		$recorder    = static function ( $return, $atts ) use ( &$recipients ) {
			$to           = $atts['to'] ?? '';
			$recipients[] = is_array( $to ) ? implode( ',', $to ) : (string) $to;
			return true;
		};
		\add_filter( 'pre_wp_mail', $recorder, 10, 2 );

		$site_sync = new SiteSync();
		for ( $i = 0; $i < SiteSync::EMAIL_PAYLOAD_MAX_ATTEMPTS; $i++ ) {
			$site_sync->send_email( 'site@example.com', $subscription_id );
		}

		\remove_filter( 'pre_wp_mail', $recorder, 10 );

		$alerts = count( array_filter( $recipients, static fn( $r ) => $r === $admin_email ) );
		$this->assertSame(
			1,
			$alerts,
			'告警只該在第一次發——重試必然被同樣擋下，重複告警會把真正要看的那一封淹掉'
		);
	}

	/**
	 * WPCD 回調的第二個站不可擠掉第一個站
	 *
	 * update_linked_site_ids() 收到的陣列就是綁定的完整清單。傳 [$new_site_id]
	 * 等於宣告「這個訂閱只有這一個站」，一張訂單兩個商品各開一站時，
	 * 第二次回調會把站 1 的 id 擠掉：站 1 從此不會被停用/恢復，
	 * 而 pp_site_url 是「第一個站先寫、之後不覆蓋」，於是 ##URL## 指向站 1、綁定卻只剩站 2。
	 *
	 * @test
	 * @group error
	 */
	public function test_WPCD回調第二個站不可擠掉第一個站(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_subscription( 1, 'wpcd' );
		$order        = \wc_get_order( $subscription->get_parent_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );

		$customer_id = $order->get_customer_id();

		$callback = static function ( int $customer_id, int $order_id, string $site_id, string $domain ) {
			$request = new \WP_REST_Request( 'POST', '/power-partner/customer-notification' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body(
				(string) \wp_json_encode(
					[
						'CUSTOMER_ID'   => $customer_id,
						'REF_ORDER_ID'  => $order_id,
						'NEW_SITE_ID'   => $site_id,
						'DOMAIN'        => $domain,
						'FRONTURL'      => 'https://' . $domain,
						'ADMINURL'      => 'https://' . $domain . '/wp-admin',
						'SITEUSERNAME'  => 'admin',
						'SITEPASSWORD'  => 'pw',
						'IPV4'          => '1.2.3.4',
					]
				)
			);
			Main::instance()->post_customer_notification_callback( $request );
		};

		$callback( $customer_id, $order->get_id(), '101', 'site-1.example.com' );
		$callback( $customer_id, $order->get_id(), '202', 'site-2.example.com' );

		$linked = array_values( ShopSubscription::get_linked_site_ids( $subscription->get_id() ) );
		sort( $linked, SORT_STRING );

		$this->assertSame(
			[ '101', '202' ],
			$linked,
			'第二個站要附加而不是覆寫——被擠掉的站從此不會被停用/恢復，錢卻照扣'
		);
	}

	/**
	 * 同一個 site id 重送回調不可長出重複列
	 *
	 * @test
	 * @group edge
	 */
	public function test_WPCD回調重送同一個站不重複綁定(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_subscription( 1, 'wpcd' );
		$order        = \wc_get_order( $subscription->get_parent_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );

		for ( $i = 0; $i < 2; $i++ ) {
			$request = new \WP_REST_Request( 'POST', '/power-partner/customer-notification' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body(
				(string) \wp_json_encode(
					[
						'CUSTOMER_ID'  => $order->get_customer_id(),
						'REF_ORDER_ID' => $order->get_id(),
						'NEW_SITE_ID'  => '303',
						'DOMAIN'       => 'dup.example.com',
						'FRONTURL'     => 'https://dup.example.com',
						'ADMINURL'     => 'https://dup.example.com/wp-admin',
						'SITEUSERNAME' => 'admin',
						'SITEPASSWORD' => 'pw',
					]
				)
			);
			Main::instance()->post_customer_notification_callback( $request );
		}

		$this->assertSame(
			[ '303' ],
			array_values( ShopSubscription::get_linked_site_ids( $subscription->get_id() ) ),
			'CloudServer 重送同一個 site id 不該長出重複列'
		);
	}

	/**
	 * 開站回應寫進 log 時不可留下明文憑證
	 *
	 * 訂單備註、訂單列表欄位、metabox 三處都已經過 mask_sensitive()，
	 * 但 plugin log 也是經銷商看得到的顯示層（Query Monitor / log 檢視器），
	 * 而且那則是 info 等級——最不會被清掉的一份。
	 * 對照常見陷阱 12：API key 禁止 raw 落地 log，同一個原則。
	 *
	 * @test
	 * @group error
	 */
	public function test_開站回應寫進log時不可留下明文憑證(): void {
		$this->skip_if_no_subscriptions();

		CapturingLogHandler::$entries = [];
		$capture                      = static fn() => [ new CapturingLogHandler() ];
		\add_filter( 'woocommerce_register_log_handlers', $capture, 10, 1 );

		$subscription = $this->create_subscription();
		$this->mock_http(
			201,
			(string) \wp_json_encode(
				[
					'websiteId' => 'ws-log-mask',
					'wordpress' => [
						'autoInstall' => [
							'adminUser'     => 'admin',
							'adminPassword' => 'SuperSecret123',
						],
					],
				]
			)
		);

		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		\remove_filter( 'woocommerce_register_log_handlers', $capture, 10 );

		$dump = (string) \wp_json_encode( CapturingLogHandler::$entries );

		$this->assertStringContainsString(
			'ws-log-mask',
			$dump,
			'前置條件：開站回應必須真的有寫進 log，否則下面的斷言是空的'
		);
		$this->assertStringNotContainsString(
			'SuperSecret123',
			$dump,
			'log 也是顯示層——明文密碼落地等於留下唯一一份沒有存取控制假設的副本'
		);
	}
}

/**
 * 測試用的 WC log handler：把每一則 log 收進靜態陣列供斷言
 *
 * WC::logger() 內部是 `new \WC_Logger()`（不帶 handlers），
 * 因此每次呼叫都會重跑 woocommerce_register_log_handlers filter。
 */
class CapturingLogHandler implements \WC_Log_Handler_Interface {

	/** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
	public static array $entries = [];

	/**
	 * Handle a log entry.
	 *
	 * @param int                  $timestamp Log timestamp.
	 * @param string               $level     Log level.
	 * @param string               $message   Log message.
	 * @param array<string, mixed> $context   Additional information.
	 * @return bool
	 */
	public function handle( $timestamp, $level, $message, $context ) {
		self::$entries[] = [
			'level'   => (string) $level,
			'message' => (string) $message,
			'context' => (array) $context,
		];
		return true;
	}
}
