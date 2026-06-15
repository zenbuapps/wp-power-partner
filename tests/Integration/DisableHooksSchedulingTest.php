<?php
/**
 * DisableHooks 關站排程編排整合測試（缺口 #3）
 *
 * 驗證「訂閱失敗排程關站 → 訂閱恢復取消排程 → 恢復重新啟用網站」
 * 的編排層，對應 specs/features/it-coverage/關站排程編排整合測試.feature。
 *
 * 覆蓋 10 條 Rule：
 *   R01 後置：訂閱失敗時建立「N 天後」的關站排程
 *   R02 後置：設定天數改變時排程時間跟著改變
 *   R03 後置：設定缺失時採用預設 7 天
 *   R04 後置：重複失敗時先取消既有排程再建立新排程（不重複堆疊）
 *   R05 後置：訂閱恢復時取消 pending 的關站排程
 *   R06 前置：restart 找不到父訂單時記 error 並中止（不發 enable 請求）
 *   R07 後置：restart 對有 pp_linked_site_ids 的 PowerCloud 站逐站呼叫 enable
 *   R08 後置：restart 對 WPCD 站（空 host_type + 數字 id）走 CloudServer enable
 *   R09 後置：restart 啟用失敗時寫「重新啟用網站失敗」備註
 *   R10 後置：restart 無 pp_linked_site_ids 時 fallback 從 order item meta 取 websiteId
 *
 * 範圍邊界：
 *   SiteDisableRoutingTest 已覆蓋 DisableSiteScheduler::action_callback（callback 執行層 + host_type 路由），
 *   本檔聚焦「排程是否被正確建立/取消」與「啟用路徑 restart 的逐站 enable + 失敗備註」。
 */

declare( strict_types=1 );

namespace Tests\Integration;

use J7\PowerPartner\Api\Connect;
use J7\PowerPartner\Api\Main;
use J7\PowerPartner\Domains\Site\Core\DisableHooks;
use J7\PowerPartner\Domains\Site\Services\DisableSiteScheduler;
use J7\PowerPartner\Product\DataTabs\LinkedSites;
use J7\PowerPartner\Product\SiteSync;

/**
 * @group smoke
 * @group happy
 * @group error
 * @group edge
 */
class DisableHooksSchedulingTest extends TestCase {

	/** @var array{url: string, args: array<string, mixed>}|null 攔截到的最後一個 HTTP 請求 */
	private ?array $last_request = null;

	/** @var callable|null 目前掛載的 pre_http_request callback */
	private $http_mock = null;

	/** DisableSiteScheduler hook 名稱（從原始碼常數取得） */
	private const DISABLE_HOOK = 'power_partner/3.1.0/site/disable';

	/**
	 * 設定（每個測試前執行）
	 */
	public function set_up(): void {
		parent::set_up();
		\set_transient( Main::POWERCLOUD_API_KEY_TRANSIENT_KEY, 'test-api-key-123' );
		\update_option( Connect::PARTNER_ID_OPTION_NAME, 'test-partner-001' );
		// 設定預設 7 天
		$this->set_power_partner_settings( [ 'power_partner_disable_site_after_n_days' => 7 ] );
		// 確保 DisableSiteScheduler 已向 ActionScheduler 註冊
		DisableSiteScheduler::register();
	}

	/**
	 * 清理（每個測試後執行）
	 */
	public function tear_down(): void {
		\delete_transient( Main::POWERCLOUD_API_KEY_TRANSIENT_KEY );
		\delete_option( Connect::PARTNER_ID_OPTION_NAME );
		$this->clear_power_partner_settings();

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
	 * 建立含 pp_linked_site_ids 的訂閱（含父訂單 + 訂閱商品 + host_type meta）
	 *
	 * @param string $host_type      商品 host_type（powercloud / wpcd / ''）
	 * @param string $linked_site_id 連結站 id（數字 = WPCD、其餘 = PowerCloud）
	 * @return \WC_Subscription
	 */
	private function create_subscription_with_site(
		string $host_type = 'powercloud',
		string $linked_site_id = 'ws-001'
	): \WC_Subscription {
		$customer_id = $this->factory()->user->create( [ 'role' => 'customer' ] );

		$product_id = $this->create_subscription_product();
		\wp_set_object_terms( $product_id, 'subscription', 'product_type' );

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

		// 設定 pp_linked_site_ids
		if ( '' !== $linked_site_id ) {
			$subscription->update_meta_data( SiteSync::LINKED_SITE_IDS_META_KEY, $linked_site_id );
		}
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

	/**
	 * 取得訂閱的 pending 關站排程數量
	 *
	 * 注意：Base::schedule_single 傳入 AS 的 args 格式為 [ $this->args ]（包一層陣列）
	 * 所以 as_get_scheduled_actions 拿回的 action->get_args() 會是 [ ['subscription_id' => id] ]
	 *
	 * @param int $subscription_id 訂閱 ID
	 * @return int
	 */
	private function count_pending_disable_schedules( int $subscription_id ): int {
		$pending_actions = \as_get_scheduled_actions(
			[
				'hook'     => self::DISABLE_HOOK,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 100,
			]
		);
		$found = array_filter(
			$pending_actions,
			function ( $action ) use ( $subscription_id ) {
				$args = $action->get_args();
				// Base 傳入的格式：[ ['subscription_id' => id] ]
				$inner = $args[0] ?? $args;
				$sid   = is_array( $inner ) ? ( $inner['subscription_id'] ?? null ) : null;
				return $sid !== null && (int) $sid === $subscription_id;
			}
		);
		return count( $found );
	}

	// ========== R01: 後置 — 訂閱失敗時建立「N 天後」的關站排程 ==========

	/**
	 * Rule: 訂閱失敗時建立「N 天後」的關站排程（N=7）。
	 *
	 * @group smoke
	 * @group happy
	 */
	public function test_schedule_disable_site_creates_schedule_after_n_days(): void {
		$this->skip_if_no_subscriptions();
		$subscription    = $this->create_subscription_with_site( 'powercloud', 'ws-001' );
		$subscription_id = $subscription->get_id();

		$before = time();
		( new DisableHooks() )->schedule_disable_site( $subscription, [] );
		$after = time();

		$this->assertGreaterThan(
			0,
			$this->count_pending_disable_schedules( $subscription_id ),
			'訂閱失敗後應建立關站排程'
		);

		// 驗時間窗（7 天 ±1 小時）
		// Base::schedule_single 傳入 AS 的 args 格式為 [ $this->args ]（包一層陣列）
		// 需用 as_get_scheduled_actions 手動過濾 subscription_id
		$all_pending = \as_get_scheduled_actions(
			[
				'hook'     => self::DISABLE_HOOK,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 100,
			]
		);
		$found_action = null;
		foreach ( $all_pending as $action ) {
			$args  = $action->get_args();
			$inner = $args[0] ?? $args;
			$sid   = is_array( $inner ) ? ( $inner['subscription_id'] ?? null ) : null;
			if ( $sid !== null && (int) $sid === $subscription_id ) {
				$found_action = $action;
				break;
			}
		}
		$this->assertNotNull( $found_action, '應有排程關站 action' );
		$scheduled = $found_action->get_schedule()->get_date()->getTimestamp();

		$seven_days = 7 * DAY_IN_SECONDS;
		$tolerance  = HOUR_IN_SECONDS;

		$this->assertGreaterThanOrEqual(
			$before + $seven_days - $tolerance,
			$scheduled,
			'排程時間不得早於 now+7days-1h'
		);
		$this->assertLessThanOrEqual(
			$after + $seven_days + $tolerance,
			$scheduled,
			'排程時間不得晚於 now+7days+1h'
		);
	}

	// ========== R02: 後置 — 設定天數改變時排程時間跟著改變 ==========

	/**
	 * Rule: 設定改為 3 天時排程為當下 +3 天。
	 *
	 * @group happy
	 */
	public function test_schedule_disable_site_respects_custom_day_setting(): void {
		$this->skip_if_no_subscriptions();
		// 改設定為 3 天
		$this->set_power_partner_settings( [ 'power_partner_disable_site_after_n_days' => 3 ] );

		$subscription    = $this->create_subscription_with_site( 'powercloud', 'ws-002' );
		$subscription_id = $subscription->get_id();

		$before = time();
		( new DisableHooks() )->schedule_disable_site( $subscription, [] );
		$after = time();

		$all_pending = \as_get_scheduled_actions(
			[
				'hook'     => self::DISABLE_HOOK,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 100,
			]
		);
		$found_action = null;
		foreach ( $all_pending as $action ) {
			$args  = $action->get_args();
			$inner = $args[0] ?? $args;
			$sid   = is_array( $inner ) ? ( $inner['subscription_id'] ?? null ) : null;
			if ( $sid !== null && (int) $sid === $subscription_id ) {
				$found_action = $action;
				break;
			}
		}
		$this->assertNotNull( $found_action, '應有排程關站 action' );
		$scheduled = $found_action->get_schedule()->get_date()->getTimestamp();

		$three_days = 3 * DAY_IN_SECONDS;
		$tolerance  = HOUR_IN_SECONDS;

		$this->assertGreaterThanOrEqual(
			$before + $three_days - $tolerance,
			$scheduled,
			'排程時間不得早於 now+3days-1h'
		);
		$this->assertLessThanOrEqual(
			$after + $three_days + $tolerance,
			$scheduled,
			'排程時間不得晚於 now+3days+1h'
		);
	}

	// ========== R03: 後置 — 設定缺失時採用預設 7 天 ==========

	/**
	 * Rule: 未設定天數時預設排程為當下 +7 天。
	 *
	 * @group edge
	 */
	public function test_schedule_disable_site_defaults_to_7_days_when_setting_missing(): void {
		$this->skip_if_no_subscriptions();
		// 清除設定，模擬缺失狀態
		\delete_option( 'power_partner_settings' );

		$subscription    = $this->create_subscription_with_site( 'powercloud', 'ws-003' );
		$subscription_id = $subscription->get_id();

		$before = time();
		( new DisableHooks() )->schedule_disable_site( $subscription, [] );
		$after = time();

		$all_pending = \as_get_scheduled_actions(
			[
				'hook'     => self::DISABLE_HOOK,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 100,
			]
		);
		$found_action = null;
		foreach ( $all_pending as $action ) {
			$args  = $action->get_args();
			$inner = $args[0] ?? $args;
			$sid   = is_array( $inner ) ? ( $inner['subscription_id'] ?? null ) : null;
			if ( $sid !== null && (int) $sid === $subscription_id ) {
				$found_action = $action;
				break;
			}
		}
		$this->assertNotNull( $found_action, '應有排程關站 action' );
		$scheduled = $found_action->get_schedule()->get_date()->getTimestamp();

		$seven_days = 7 * DAY_IN_SECONDS;
		$tolerance  = HOUR_IN_SECONDS;

		$this->assertGreaterThanOrEqual(
			$before + $seven_days - $tolerance,
			$scheduled,
			'排程時間不得早於 now+7days-1h（預設 7 天）'
		);
		$this->assertLessThanOrEqual(
			$after + $seven_days + $tolerance,
			$scheduled,
			'排程時間不得晚於 now+7days+1h（預設 7 天）'
		);
	}

	// ========== R04: 後置 — 重複失敗時先取消既有排程再建立新排程（不重複堆疊）==========

	/**
	 * Rule: 重複失敗時先取消既有排程再建立新排程，排程數量維持為 1。
	 *
	 * @group edge
	 */
	public function test_schedule_disable_site_does_not_stack_duplicate_schedules(): void {
		$this->skip_if_no_subscriptions();
		$subscription    = $this->create_subscription_with_site( 'powercloud', 'ws-004' );
		$subscription_id = $subscription->get_id();

		// 第一次失敗
		( new DisableHooks() )->schedule_disable_site( $subscription, [] );
		$count_after_first = $this->count_pending_disable_schedules( $subscription_id );
		$this->assertSame( 1, $count_after_first, '第一次失敗後應有 1 個 pending 排程' );

		// 第二次失敗
		( new DisableHooks() )->schedule_disable_site( $subscription, [] );
		$count_after_second = $this->count_pending_disable_schedules( $subscription_id );
		$this->assertSame( 1, $count_after_second, '第二次失敗後排程數量應仍維持為 1（不堆疊）' );
	}

	// ========== R05: 後置 — 訂閱恢復時取消 pending 的關站排程 ==========

	/**
	 * Rule: 訂閱恢復時取消 pending 的關站排程。
	 *
	 * @group happy
	 */
	public function test_cancel_disable_site_schedule_removes_pending_schedule(): void {
		$this->skip_if_no_subscriptions();
		$subscription    = $this->create_subscription_with_site( 'powercloud', 'ws-005' );
		$subscription_id = $subscription->get_id();

		// 先建立排程
		( new DisableHooks() )->schedule_disable_site( $subscription, [] );
		$this->assertGreaterThan( 0, $this->count_pending_disable_schedules( $subscription_id ), '前提：應有 pending 排程' );

		// 取消排程
		( new DisableHooks() )->cancel_disable_site_schedule( $subscription, [] );

		$count_after = $this->count_pending_disable_schedules( $subscription_id );
		$this->assertSame( 0, $count_after, '恢復後應沒有任何 pending 的關站排程' );
	}

	// ========== R06: 前置 — restart 找不到父訂單時記 error 並中止 ==========

	/**
	 * Rule: restart 找不到父訂單時記 error 並中止，不發 enable 請求。
	 *
	 * @group error
	 */
	public function test_restart_aborts_when_no_parent_order(): void {
		$this->skip_if_no_subscriptions();
		$this->mock_http( 200 );

		// 建立沒有父訂單的訂閱
		$customer_id = $this->factory()->user->create( [ 'role' => 'customer' ] );
		$subscription = \wcs_create_subscription(
			[
				'status'           => 'active',
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'customer_id'      => $customer_id,
			]
		);
		$this->assertInstanceOf( \WC_Subscription::class, $subscription );

		( new DisableHooks() )->restart_all_stopped_sites_scheduler( $subscription, [] );

		$this->assertNull( $this->last_request, '無父訂單時不應發出任何啟用網站的 HTTP 請求' );
	}

	// ========== R07: 後置 — restart 對 PowerCloud 站逐站呼叫 enable ==========

	/**
	 * Rule: restart 對有 pp_linked_site_ids 的 PowerCloud 站逐站呼叫 enable。
	 *
	 * @group happy
	 */
	public function test_restart_calls_powercloud_enable_for_powercloud_site(): void {
		$this->skip_if_no_subscriptions();
		$subscription = $this->create_subscription_with_site( 'powercloud', 'ws-100' );
		$this->mock_http( 200 );

		( new DisableHooks() )->restart_all_stopped_sites_scheduler( $subscription, [] );

		$this->assertNotNull( $this->last_request, '應有發出 HTTP 請求' );
		$this->assertStringContainsString(
			'/wordpress/ws-100/start',
			$this->last_request['url'],
			'PowerCloud 站恢復時應打 /start endpoint'
		);
	}

	// ========== R08: 後置 — restart 對 WPCD 站（空 host_type + 數字 id）走 CloudServer enable ==========

	/**
	 * Rule: restart 對 WPCD 站（空 host_type + 數字 id）走 CloudServer enable（issue #18 對齊）。
	 *
	 * @group happy
	 */
	public function test_restart_calls_cloudserver_enable_for_wpcd_site(): void {
		$this->skip_if_no_subscriptions();
		// host_type 為空、site_id 為數字 → 推斷為 WPCD
		$subscription = $this->create_subscription_with_site( '', '1376977' );
		$this->mock_http( 200 );

		( new DisableHooks() )->restart_all_stopped_sites_scheduler( $subscription, [] );

		$this->assertNotNull( $this->last_request, '應有發出 HTTP 請求' );
		$this->assertStringContainsString(
			'/wp-json/power-partner-server/v2/enable-site',
			$this->last_request['url'],
			'舊 WPCD 站（空 host_type + 數字 id）恢復時應走 CloudServer enable API'
		);
		$this->assertStringNotContainsString(
			'/wordpress/1376977/start',
			$this->last_request['url'],
			'WPCD 站不可被導去 PowerCloud /start API'
		);
	}

	// ========== R09: 後置 — restart 啟用失敗時寫「重新啟用網站失敗」備註 ==========

	/**
	 * Rule: restart 啟用失敗時寫「重新啟用網站失敗」備註（issue #18 啟用失敗回報）。
	 *
	 * @group error
	 */
	public function test_restart_writes_failure_note_when_enable_fails(): void {
		$this->skip_if_no_subscriptions();
		$subscription    = $this->create_subscription_with_site( 'powercloud', 'ws-200' );
		$subscription_id = $subscription->get_id();

		$this->mock_http( 500, '{"message":"Internal Server Error"}' );

		( new DisableHooks() )->restart_all_stopped_sites_scheduler( $subscription, [] );

		$notes = $this->get_order_notes( $subscription_id );
		$this->assertNotEmpty(
			preg_grep( '/重新啟用網站失敗/', $notes ),
			'啟用失敗時應寫入「重新啟用網站失敗」備註，實際備註：' . print_r( $notes, true ) // phpcs:ignore
		);
		$this->assertNotEmpty(
			preg_grep( '/ws-200/', $notes ),
			'失敗備註應包含 websiteId ws-200，實際備註：' . print_r( $notes, true ) // phpcs:ignore
		);
	}

	// ========== R10: 後置 — restart 無 pp_linked_site_ids 時 fallback 從 order item meta 取 websiteId ==========

	/**
	 * Rule: restart 無 pp_linked_site_ids 時 fallback 從 order item meta 取 websiteId（相容舊資料）。
	 *
	 * @group edge
	 */
	public function test_restart_falls_back_to_order_item_meta_when_no_linked_site_ids(): void {
		$this->skip_if_no_subscriptions();
		$customer_id = $this->factory()->user->create( [ 'role' => 'customer' ] );

		$product_id = $this->create_subscription_product();
		\wp_set_object_terms( $product_id, 'subscription', 'product_type' );
		\update_post_meta( $product_id, LinkedSites::HOST_TYPE_FIELD_NAME, 'powercloud' );

		$order = \wc_create_order(
			[
				'customer_id' => $customer_id,
				'status'      => 'processing',
			]
		);
		$this->assertInstanceOf( \WC_Order::class, $order );

		$product = \wc_get_product( $product_id );
		$this->assertNotFalse( $product );
		$order->add_product( $product );

		// 在 order item 上寫入舊版 _pp_create_site_responses_item meta
		$items = $order->get_items();
		$item  = reset( $items );
		$this->assertInstanceOf( \WC_Order_Item_Product::class, $item );

		$response_data = \wp_json_encode(
			[
				[
					'status'  => 200,
					'message' => 'success',
					'data'    => [
						'websiteId' => 'ws-300',
					],
				],
			]
		);
		$item->update_meta_data( SiteSync::CREATE_SITE_RESPONSES_ITEM_META_KEY, (string) $response_data );
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
		// 不設定 pp_linked_site_ids，模擬舊資料
		$subscription->save();

		$this->mock_http( 200 );

		( new DisableHooks() )->restart_all_stopped_sites_scheduler( $subscription, [] );

		$this->assertNotNull( $this->last_request, '應有發出 HTTP 請求' );
		$this->assertStringContainsString(
			'/wordpress/ws-300/start',
			$this->last_request['url'],
			'舊資料 fallback 時應從 order item meta 取 websiteId ws-300，並打 /start endpoint'
		);
	}
}
