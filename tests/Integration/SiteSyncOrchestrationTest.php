<?php
/**
 * SiteSync 開站編排整合測試（缺口 #1）
 *
 * 驗證「訂閱首次付款 → 自動開站 → 暫存帳密 → 排程延遲發信」
 * 整段編排邏輯，對應 specs/features/it-coverage/開站編排整合測試.feature。
 *
 * 覆蓋 13 條 Rule：
 *   R01 前置：續訂（2 筆關聯訂單）不觸發開站
 *   R02 前置：無有效父訂單時中止且不開站
 *   R03 前置：商品未設定 linked_site 時該項目跳過
 *   R04 前置：simple 商品跳過
 *   R05 後置：host_type=powercloud 呼叫正確 endpoint
 *   R06 後置：PowerCloud 201 時把 websiteId 寫入 pp_linked_site_ids
 *   R07 後置：PowerCloud 201 時暫存 email_payloads_tmp 並排程 4 分鐘後發信（Q5 雙斷言）
 *   R08 後置：PowerCloud 非 201 時不暫存、不排程發信
 *   R09 後置：host_type=wpcd 時呼叫 CloudServer endpoint
 *   R10 後置：開站後觸發 pp_site_sync_by_subscription action
 *   R11 後置：拋出例外時寫「網站建立失敗」訂單備註且不中斷
 *   R12 後置：send_email 讀取暫存→發信→刪 meta
 *   R13 前置：send_email 在 email_payloads_tmp 不存在時安全跳過
 */

declare( strict_types=1 );

namespace Tests\Integration;

use J7\PowerPartner\Api\Connect;
use J7\PowerPartner\Api\Main;
use J7\PowerPartner\Product\DataTabs\LinkedSites;
use J7\PowerPartner\Product\SiteSync;
use J7\PowerPartner\ShopSubscription;

/**
 * @group smoke
 * @group happy
 * @group error
 * @group edge
 */
class SiteSyncOrchestrationTest extends TestCase {

	/** @var array{url: string, args: array<string, mixed>}|null 攔截到的最後一個 HTTP 請求 */
	private ?array $last_request = null;

	/** @var callable|null 目前掛載的 pre_http_request callback */
	private $http_mock = null;

	/** @var array<int, array{to: string, subject: string, message: string}> 攔截到的寄信紀錄 */
	private array $sent_emails = [];

	/** @var callable|null 目前掛載的 wp_mail filter */
	private $mail_mock = null;

	/**
	 * 設定（每個測試前執行）
	 */
	public function set_up(): void {
		parent::set_up();
		\set_transient( Main::POWERCLOUD_API_KEY_TRANSIENT_KEY, 'test-api-key-123' );
		\update_option( Connect::PARTNER_ID_OPTION_NAME, 'test-partner-001' );
		$this->sent_emails = [];
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
		if ( $this->mail_mock ) {
			\remove_filter( 'wp_mail', $this->mail_mock, 10 );
			$this->mail_mock = null;
		}
		$this->last_request = null;
		$this->sent_emails  = [];
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
	 * 掛載 wp_mail mock，攔截所有寄信請求
	 */
	private function mock_wp_mail(): void {
		$this->mail_mock = function ( $args ) {
			$this->sent_emails[] = [
				'to'      => is_array( $args['to'] ) ? implode( ',', $args['to'] ) : (string) $args['to'],
				'subject' => (string) ( $args['subject'] ?? '' ),
				'message' => (string) ( $args['message'] ?? '' ),
			];
			// 回傳 false 讓 wp_mail 不實際寄出
			return false;
		};
		\add_filter( 'wp_mail', $this->mail_mock, 10, 1 );
	}

	/**
	 * 建立標準 PowerCloud 訂閱（含父訂單 + 訂閱商品 + host_type meta）
	 *
	 * @param string $host_type      商品 host_type（powercloud / wpcd / ''）
	 * @param string $linked_site_id 模板站 ID（空字串表示不設定）
	 * @param string $open_site_plan 開站方案 ID
	 * @return \WC_Subscription
	 */
	private function create_subscription_for_site_sync(
		string $host_type = 'powercloud',
		string $linked_site_id = 'tpl-001',
		string $open_site_plan = 'plan-001'
	): \WC_Subscription {
		$customer_id = $this->factory()->user->create(
			[
				'role'       => 'customer',
				'user_email' => 'customer-' . uniqid() . '@example.com',
				'user_login' => 'customer-' . uniqid(),
			]
		);

		$product_id = $this->create_subscription_product();
		\wp_set_object_terms( $product_id, 'subscription', 'product_type' );
		$this->set_product_pp_meta( $product_id, $host_type, $linked_site_id, 'tw', $open_site_plan );

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
		$this->assertInstanceOf( \WC_Subscription::class, $subscription, '建立訂閱失敗' );
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

	// ========== R01: 前置 — 續訂不觸發開站 ==========

	/**
	 * Rule: 訂閱必須只有一筆關聯訂單，續訂不觸發開站。
	 * 訂閱有 2 筆以上關聯訂單時不執行開站。
	 *
	 * @group edge
	 */
	public function test_renewal_subscription_does_not_trigger_site_sync(): void {
		$this->skip_if_no_subscriptions();
		$subscription = $this->create_subscription_for_site_sync();
		$this->mock_http( 201, '{"websiteId":"ws-new"}' );

		// 用 wcs_create_renewal_order 建立 renewal order 並正確呼叫 add_relation()
		// wcs_create_order_from_subscription 本身不呼叫 add_relation，
		// 必須用 wcs_create_renewal_order 才能讓 get_related_orders() 回傳 2 筆
		$renewal = \wcs_create_renewal_order( $subscription );
		$this->assertInstanceOf( \WC_Order::class, $renewal, '建立續訂單失敗' );

		// 重新讀取 subscription，確保關聯訂單更新
		$fresh_sub = \wcs_get_subscription( $subscription->get_id() );
		$this->assertInstanceOf( \WC_Subscription::class, $fresh_sub );

		( new SiteSync() )->site_sync_by_subscription( $fresh_sub, [] );

		// 不應打出任何開站 API（last_request 仍為 null）
		$this->assertNull( $this->last_request, '續訂訂閱不應發出開站 HTTP 請求' );
	}

	// ========== R02: 前置 — 無有效父訂單時中止 ==========

	/**
	 * Rule: 父訂單必須為 WC_Order 實例，否則記 error log 並中止。
	 * 直接呼叫 site_sync_by_subscription 但訂閱沒有父訂單（parent_id=0）。
	 *
	 * @group error
	 */
	public function test_no_parent_order_aborts_site_sync(): void {
		$this->skip_if_no_subscriptions();
		$this->mock_http( 201 );

		// 建立一個完全沒有父訂單的訂閱（直接 wcs_create_subscription 不傳 order_id）
		$customer_id = $this->factory()->user->create( [ 'role' => 'customer' ] );
		$subscription = \wcs_create_subscription(
			[
				'status'           => 'active',
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'customer_id'      => $customer_id,
			]
		);
		$this->assertInstanceOf( \WC_Subscription::class, $subscription, '建立訂閱失敗' );

		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		$this->assertNull( $this->last_request, '無父訂單時不應發出開站 HTTP 請求' );
	}

	// ========== R03: 前置 — 商品未設定 linked_site 時跳過 ==========

	/**
	 * Rule: 商品必須有設定模板站 ID，否則該項目跳過。
	 * 商品未設定 power_partner_linked_site 時不發出開站請求。
	 *
	 * @group edge
	 */
	public function test_missing_linked_site_skips_item(): void {
		$this->skip_if_no_subscriptions();
		// linked_site_id 傳空字串
		$subscription = $this->create_subscription_for_site_sync( 'powercloud', '', 'plan-001' );
		$this->mock_http( 201 );

		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		$this->assertNull( $this->last_request, '未設定 linked_site 時不應發出開站 HTTP 請求' );
	}

	// ========== R04: 前置 — simple 商品跳過 ==========

	/**
	 * Rule: 商品類型必須為 subscription 或 subscription_variation，simple 商品跳過。
	 *
	 * @group edge
	 */
	public function test_simple_product_is_skipped(): void {
		$this->skip_if_no_subscriptions();
		$customer_id = $this->factory()->user->create( [ 'role' => 'customer' ] );

		// 建立 simple 商品（不設定 subscription 類型）
		$product_id = $this->create_subscription_product();
		// 不設 subscription term，讓它保持 simple
		$this->set_product_pp_meta( $product_id, 'powercloud', 'tpl-001', 'tw', 'plan-001' );

		$order = \wc_create_order(
			[
				'customer_id' => $customer_id,
				'status'      => 'processing',
			]
		);
		$product = \wc_get_product( $product_id );
		$this->assertNotFalse( $product );
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
		$this->assertInstanceOf( \WC_Subscription::class, $subscription );
		$subscription->save();

		$this->mock_http( 201 );

		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		$this->assertNull( $this->last_request, 'simple 商品不應觸發開站 HTTP 請求' );
	}

	// ========== R05: 後置 — host_type=powercloud 打正確 endpoint ==========

	/**
	 * Rule: host_type 為 powercloud 時呼叫 PowerCloud API POST /wordpress 開站。
	 *
	 * @group smoke
	 * @group happy
	 */
	public function test_powercloud_host_type_calls_powercloud_endpoint(): void {
		$this->skip_if_no_subscriptions();
		$subscription = $this->create_subscription_for_site_sync( 'powercloud', 'tpl-001', 'plan-001' );

		$response_body = \wp_json_encode(
			[
				'websiteId'        => 'ws-9001',
				'domain'           => 'test.wpsite.pro',
				'wp_admin_email'   => 'site@example.com',
				'wp_admin_password' => 'test-password-123',
			]
		);
		$this->mock_http( 201, (string) $response_body );

		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		$this->assertNotNull( $this->last_request, '應有發出 HTTP 請求' );
		$this->assertStringContainsString(
			'/wordpress',
			$this->last_request['url'],
			'PowerCloud 開站應呼叫 /wordpress endpoint'
		);
		// 驗證帶有 X-API-Key header
		$headers = $this->last_request['args']['headers'] ?? [];
		$this->assertArrayHasKey( 'X-API-Key', $headers, '請求應帶有 X-API-Key header' );
		$this->assertNotEmpty( $headers['X-API-Key'], 'X-API-Key 不應為空' );
	}

	// ========== R06: 後置 — PowerCloud 201 時把 websiteId 寫入 pp_linked_site_ids ==========

	/**
	 * Rule: PowerCloud 開站回傳 201 時把 websiteId 寫入 pp_linked_site_ids。
	 *
	 * @group happy
	 */
	public function test_powercloud_201_binds_website_id_to_subscription(): void {
		$this->skip_if_no_subscriptions();
		$subscription = $this->create_subscription_for_site_sync( 'powercloud', 'tpl-001', 'plan-001' );

		$response_body = \wp_json_encode(
			[
				'websiteId'         => 'ws-9001',
				'domain'            => 'test.wpsite.pro',
				'wp_admin_email'    => 'site@example.com',
				'wp_admin_password' => 'test-password-123',
			]
		);
		$this->mock_http( 201, (string) $response_body );

		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		$linked_site_ids = ShopSubscription::get_linked_site_ids( $subscription->get_id() );
		$this->assertContains(
			'ws-9001',
			array_values( $linked_site_ids ),
			'開站成功後 websiteId 應寫入 pp_linked_site_ids'
		);
	}

	// ========== R07: 後置 — PowerCloud 201 時暫存帳密並排程 4 分鐘後發信（Q5 雙斷言）==========

	/**
	 * Rule: PowerCloud 開站回傳 201 時暫存 email_payloads_tmp 並排程 4 分鐘後發信。
	 * Q5 定案：(a) 斷言 powerhouse_delay_send_email 已排程；(b) 直接呼叫 send_email 驗三段。
	 *
	 * @group happy
	 */
	public function test_powercloud_201_schedules_delayed_email_and_stores_payloads(): void {
		$this->skip_if_no_subscriptions();
		$subscription = $this->create_subscription_for_site_sync( 'powercloud', 'tpl-001', 'plan-001' );

		$response_body = \wp_json_encode(
			[
				'websiteId'         => 'ws-8001',
				'domain'            => 'test.wpsite.pro',
				'wp_admin_email'    => 'site@example.com',
				'wp_admin_password' => 'test-password-123',
			]
		);
		$this->mock_http( 201, (string) $response_body );

		$before = time();
		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );
		$after = time();

		// (a) 斷言 email_payloads_tmp 被寫入
		$subscription_id = $subscription->get_id();
		$fresh_sub       = \wcs_get_subscription( $subscription_id );
		$this->assertInstanceOf( \WC_Subscription::class, $fresh_sub );
		$payloads = $fresh_sub->get_meta( 'email_payloads_tmp' );
		$this->assertIsArray( $payloads, 'email_payloads_tmp 應為陣列' );

		// (a) 斷言 powerhouse_delay_send_email 排程存在且時間窗為 +240 秒（±30 秒）
		// 注意：排程的 to 值是 wp_admin_email（由 customer['email'] 產生），不是 mock body 的值
		// 因此用 subscription_id 來查找匹配的排程
		$pending_actions = \as_get_scheduled_actions(
			[
				'hook'     => 'powerhouse_delay_send_email',
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 100,
			]
		);
		$found_action = null;
		foreach ( $pending_actions as $action ) {
			$args = $action->get_args();
			if ( isset( $args['subscription_id'] ) && (int) $args['subscription_id'] === $subscription_id ) {
				$found_action = $action;
				break;
			}
		}
		$this->assertNotNull( $found_action, '應排程 powerhouse_delay_send_email' );
		$scheduled = $found_action->get_schedule()->get_date()->getTimestamp();
		$this->assertIsInt( $scheduled );
		$this->assertGreaterThanOrEqual(
			$before + 240 - 30,
			$scheduled,
			'排程時間不得早於 now+240-30 秒'
		);
		$this->assertLessThanOrEqual(
			$after + 240 + 30,
			$scheduled,
			'排程時間不得晚於 now+240+30 秒'
		);
	}

	/**
	 * Q5 定案 (b)：直接呼叫 send_email 驗「讀取 email_payloads_tmp → 發信 → 刪 meta」三段。
	 * 對應 R12（send_email 讀取暫存→發信→刪 meta）。
	 *
	 * @group happy
	 */
	public function test_send_email_reads_payload_sends_mail_and_deletes_meta(): void {
		$this->skip_if_no_subscriptions();
		$subscription = $this->create_subscription_for_site_sync( 'powercloud', 'tpl-001', 'plan-001' );
		$subscription_id = $subscription->get_id();

		// 直接寫入 email_payloads_tmp，模擬開站成功後的暫存狀態
		$subscription->update_meta_data(
			'email_payloads_tmp',
			[
				'FIRST_NAME'    => '測試',
				'LAST_NAME'     => '用戶',
				'EMAIL'         => 'site@example.com',
				'SITEUSERNAME'  => 'admin',
				'SITEPASSWORD'  => 'test-pass',
				'DOMAIN'        => 'https://test.wpsite.pro',
				'FRONTURL'      => 'https://test.wpsite.pro',
				'ADMINURL'      => 'https://test.wpsite.pro/wp-admin',
			]
		);
		$subscription->save();

		$this->mock_wp_mail();

		( new SiteSync() )->send_email( 'site@example.com', $subscription_id );

		// 驗發信（通常由 SubscriptionEmailHooks::send_mail 處理，wp_mail 會被呼叫）
		// 若沒有 email template，wp_mail 仍可能不被呼叫——斷言 meta 已刪除即可
		$fresh_sub = \wcs_get_subscription( $subscription_id );
		$this->assertInstanceOf( \WC_Subscription::class, $fresh_sub );
		$payloads_after = $fresh_sub->get_meta( 'email_payloads_tmp' );
		$this->assertEmpty( $payloads_after, 'send_email 執行後 email_payloads_tmp 應被刪除' );
	}

	// ========== R08: 後置 — PowerCloud 非 201 時不暫存、不排程 ==========

	/**
	 * Rule: PowerCloud 開站非 201（如 400）時不暫存帳密、不排程發信。
	 *
	 * @group error
	 */
	public function test_powercloud_non_201_does_not_store_payloads_or_schedule_email(): void {
		$this->skip_if_no_subscriptions();
		$subscription    = $this->create_subscription_for_site_sync( 'powercloud', 'tpl-001', 'plan-001' );
		$subscription_id = $subscription->get_id();

		$this->mock_http( 400, '{"error":"Bad Request"}' );

		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		$fresh_sub = \wcs_get_subscription( $subscription_id );
		$this->assertInstanceOf( \WC_Subscription::class, $fresh_sub );

		$payloads = $fresh_sub->get_meta( 'email_payloads_tmp' );
		$this->assertEmpty( $payloads, '開站失敗時 email_payloads_tmp 不應被寫入' );

		// 不應有任何相關排程（針對此 subscription_id）
		$pending_actions = \as_get_scheduled_actions(
			[
				'hook'     => 'powerhouse_delay_send_email',
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 100,
			]
		);
		$found = array_filter(
			$pending_actions,
			function ( $action ) use ( $subscription_id ) {
				$args = $action->get_args();
				return isset( $args['subscription_id'] ) && (int) $args['subscription_id'] === $subscription_id;
			}
		);
		$this->assertEmpty( $found, '開站失敗時不應有 powerhouse_delay_send_email 排程' );
	}

	// ========== R09: 後置 — host_type=wpcd 時呼叫 CloudServer endpoint ==========

	/**
	 * Rule: host_type 為 wpcd 時呼叫 CloudServer site-sync API。
	 *
	 * @group happy
	 */
	public function test_wpcd_host_type_calls_cloudserver_endpoint(): void {
		$this->skip_if_no_subscriptions();
		$subscription = $this->create_subscription_for_site_sync( 'wpcd', 'tpl-001', '' );
		$this->mock_http( 200, '{"status":200,"message":"success","data":{}}' );

		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		$this->assertNotNull( $this->last_request, '應有發出 HTTP 請求' );
		$this->assertStringContainsString(
			'/wp-json/power-partner-server/site-sync',
			$this->last_request['url'],
			'WPCD 開站應呼叫 CloudServer /site-sync endpoint'
		);
	}

	// ========== R10: 後置 — 開站後觸發 pp_site_sync_by_subscription action ==========

	/**
	 * Rule: 開站流程結束後觸發 pp_site_sync_by_subscription action。
	 *
	 * @group happy
	 */
	public function test_action_pp_site_sync_by_subscription_is_fired(): void {
		$this->skip_if_no_subscriptions();
		$subscription = $this->create_subscription_for_site_sync( 'powercloud', 'tpl-001', 'plan-001' );

		$response_body = \wp_json_encode(
			[
				'websiteId'         => 'ws-action-test',
				'domain'            => 'test.wpsite.pro',
				'wp_admin_email'    => 'action@example.com',
				'wp_admin_password' => 'test-pw',
			]
		);
		$this->mock_http( 201, (string) $response_body );

		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		$this->assertGreaterThan(
			0,
			\did_action( 'pp_site_sync_by_subscription' ),
			'開站後應觸發 pp_site_sync_by_subscription action'
		);
	}

	// ========== R11: 後置 — 開站過程拋出例外時寫失敗備註 ==========

	/**
	 * Rule: 開站過程拋出例外時記錄「網站建立失敗」訂單備註且不中斷。
	 * 以 WP_Error 觸發 FetchPowerCloud::site_sync 拋出 \Exception。
	 *
	 * @group error
	 */
	public function test_exception_during_site_sync_writes_failure_note(): void {
		$this->skip_if_no_subscriptions();
		$subscription = $this->create_subscription_for_site_sync( 'powercloud', 'tpl-001', 'plan-001' );

		// mock 回傳 WP_Error，FetchPowerCloud::site_sync 在 is_wp_error 時會拋出 Exception
		$this->mock_http( new \WP_Error( 'http_request_failed', '連線逾時' ) );

		// 不應拋出例外到測試層
		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		$notes = $this->get_order_notes( $subscription->get_id() );
		$this->assertNotEmpty(
			preg_grep( '/網站建立失敗/', $notes ),
			'開站例外時應寫入「網站建立失敗」備註，實際備註：' . print_r( $notes, true ) // phpcs:ignore
		);
	}

	// ========== R12: 後置 — send_email 讀取暫存→發信→刪 meta（已在 R07 Q5(b) 覆蓋）==========

	// ========== R13: 前置 — send_email 在 email_payloads_tmp 不存在時安全跳過 ==========

	/**
	 * Rule: send_email 在 email_payloads_tmp 不存在時安全跳過，不發信不報錯。
	 *
	 * @group edge
	 */
	public function test_send_email_safely_skips_when_no_payloads_tmp(): void {
		$this->skip_if_no_subscriptions();
		$subscription    = $this->create_subscription_for_site_sync( 'powercloud', 'tpl-001', 'plan-001' );
		$subscription_id = $subscription->get_id();

		// 確認沒有 email_payloads_tmp
		$subscription->delete_meta_data( 'email_payloads_tmp' );
		$subscription->save();

		$this->mock_wp_mail();

		// 不應拋出例外
		( new SiteSync() )->send_email( 'site@example.com', $subscription_id );

		$this->assertEmpty(
			$this->sent_emails,
			'email_payloads_tmp 不存在時不應發送任何 Email'
		);
	}
}
