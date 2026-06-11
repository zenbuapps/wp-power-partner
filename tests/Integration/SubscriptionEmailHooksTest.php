<?php
/**
 * SubscriptionEmailHooks 整合測試（TDD Red 階段）
 *
 * 此測試檔描述「修改後的預期行為」，生產碼尚未改動，
 * 所以新行為的正向案例（#1,#2,#6,#7 active 分支）必須 FAIL。
 *
 * Issue #16：subscription_success 信改由 woocommerce_subscription_status_updated
 * active 分支觸發（目前 active 分支只取消催繳信，不排程成功信）。
 *
 * 測試的「即將實作的行為」：
 *  - active 分支（from ∈ [on-hold, pending-cancel, cancelled, expired]）：
 *    取消催繳信（既有）＋ 排程 subscription_success 信（新，10 分鐘最少緩衝）
 *  - on-hold 分支：排催繳信（既有）＋ 取消未寄成功信（新）
 *  - cancelled/expired 分支：排 end 信（既有）＋ 取消未寄成功信（新）
 *  - action_callback 狀態複查：成功信寄送當下須仍為 active 才寄
 */

declare( strict_types=1 );

namespace Tests\Integration;

use J7\PowerPartner\Domains\Email\Core\SubscriptionEmailHooks;
use J7\PowerPartner\Domains\Email\Services\SubscriptionEmailScheduler;
use J7\PowerPartner\Product\SiteSync;

/**
 * @group smoke
 * @group happy
 * @group error
 * @group edge
 */
class SubscriptionEmailHooksTest extends TestCase {

	/** @var string 排程 hook 名稱 */
	private const SCHEDULER_HOOK = 'power_partner/3.1.0/email/scheduler';

	/** @var string 成功信 action_name */
	private const ACTION_SUCCESS = 'subscription_success';

	/** @var string 催繳信 action_name */
	private const ACTION_FAILED = 'subscription_failed';

	/** @var string 結束信 action_name */
	private const ACTION_END = 'end';

	/** @var int 測試用客戶 ID */
	private int $customer_id;

	/** @var \WC_Order|null 測試用父訂單 */
	private ?\WC_Order $parent_order = null;

	/** @var string 測試用成功信 key */
	private string $success_email_key;

	/** @var string 測試用催繳信 key */
	private string $failed_email_key;

	/** @var string 測試用結束信 key */
	private string $end_email_key;

	// ========== 測試前置作業 ==========

	protected function configure_dependencies(): void {
		if ( ! class_exists( 'WC_Subscription' ) ) {
			return;
		}

		// 建立測試用客戶
		$this->customer_id = $this->factory()->user->create(
			[
				'role'       => 'customer',
				'user_email' => 'test-sub-email-hooks@example.com',
			]
		);

		// 建立唯一 email key
		$unique              = uniqid();
		$this->success_email_key = 'test_success_' . $unique;
		$this->failed_email_key  = 'test_failed_' . $unique;
		$this->end_email_key     = 'test_end_' . $unique;
	}

	/**
	 * 建立真實的 WC_Subscription 物件（含 parent order 與 pp_linked_site_ids meta）
	 *
	 * 注意：必須用 wcs_create_subscription() 建立，post+meta 手法無法通過 instanceof 守門。
	 *
	 * @param string $status 初始狀態（無 wc- 前綴）
	 * @return \WC_Subscription
	 */
	private function create_pp_subscription( string $status = 'active' ): \WC_Subscription {
		// 建立父訂單
		$order = wc_create_order(
			[
				'customer_id' => $this->customer_id,
				'status'      => 'processing',
			]
		);
		$this->assertInstanceOf( \WC_Order::class, $order, '建立父訂單失敗' );
		$order->set_billing_email( 'test-sub-email-hooks@example.com' );
		$order->save();

		$this->parent_order = $order;

		// 建立訂閱（真實 WC_Subscription 物件）
		$subscription = wcs_create_subscription(
			[
				'order_id'         => $order->get_id(),
				'status'           => $status,
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'customer_id'      => $this->customer_id,
			]
		);

		$this->assertInstanceOf( \WC_Subscription::class, $subscription, '建立訂閱失敗' );

		// 設定 pp_linked_site_ids meta，讓 is_site_sync() 回傳 true
		$subscription->update_meta_data( SiteSync::LINKED_SITE_IDS_META_KEY, 'test-site-001' );
		$subscription->save();

		return $subscription;
	}

	/**
	 * 建立含 subscription_success、subscription_failed、end 三種信的 settings，
	 * 並重新實例化 SubscriptionEmailHooks（Bootstrap 先建 singleton 時 emails 可能為空）
	 *
	 * 使用反射 API 重設 singleton，確保 action_callback 內部呼叫
	 * SubscriptionEmailHooks::instance() 時能取回含 emails 的實例。
	 *
	 * @param array<string, mixed> $extra_overrides 額外覆寫（例如 enabled='0'）
	 * @return SubscriptionEmailHooks 新的 hooks 實例（已成為 singleton）
	 */
	private function setup_hooks_with_all_emails( array $extra_overrides = [] ): SubscriptionEmailHooks {
		$emails = [
			array_merge(
				$this->make_email_config(
					[
						'key'         => $this->success_email_key,
						'action_name' => self::ACTION_SUCCESS,
						'days'        => '0',
						'enabled'     => '1',
					]
				),
				$extra_overrides['success'] ?? []
			),
			array_merge(
				$this->make_email_config(
					[
						'key'         => $this->failed_email_key,
						'action_name' => self::ACTION_FAILED,
						'days'        => '0',
						'enabled'     => '1',
					]
				),
				$extra_overrides['failed'] ?? []
			),
			array_merge(
				$this->make_email_config(
					[
						'key'         => $this->end_email_key,
						'action_name' => self::ACTION_END,
						'days'        => '0',
						'enabled'     => '1',
					]
				),
				$extra_overrides['end'] ?? []
			),
		];

		$this->setup_settings_with_emails( $emails );

		// 用反射 API 重設 singleton，讓接下來的 instance() 建立含 emails 的實例
		// 這樣 action_callback 內的 SubscriptionEmailHooks::instance() 也能取到正確 emails
		$reflection = new \ReflectionClass( SubscriptionEmailHooks::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		// 重新建立 singleton（含正確 emails）
		$hooks = SubscriptionEmailHooks::instance();

		// 守門：確保 emails 已含測試模板，否則測試無意義
		$success_emails = $hooks->get_emails( self::ACTION_SUCCESS );
		$this->assertNotEmpty(
			$success_emails,
			'SubscriptionEmailHooks::emails 中找不到 subscription_success 模板，請確認 setup_settings_with_emails 正確寫入 option'
		);

		return $hooks;
	}

	/**
	 * 在測試 tearDown 時重設 singleton，避免污染後續測試
	 */
	public function tear_down(): void {
		$reflection = new \ReflectionClass( SubscriptionEmailHooks::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		// 讓 Bootstrap 的 singleton 在下次使用時能夠重建
		parent::tear_down();
	}

	/**
	 * 查詢 ActionScheduler 中 pending 的指定 action_name 排程列表
	 *
	 * @param string $action_name 群組名稱（即 action_name）
	 * @return array<\ActionScheduler_Action>
	 */
	private function get_pending_actions( string $action_name ): array {
		return as_get_scheduled_actions(
			[
				'hook'     => self::SCHEDULER_HOOK,
				'group'    => $action_name,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 100,
			]
		);
	}

	/**
	 * 斷言某個 subscription_id + action_name 有 pending 的排程
	 *
	 * @param int    $subscription_id 訂閱 ID
	 * @param string $action_name     action 名稱
	 * @param string $message         失敗訊息
	 */
	private function assert_has_pending_action( int $subscription_id, string $action_name, string $message = '' ): void {
		$actions = $this->get_pending_actions( $action_name );

		$found = false;
		foreach ( $actions as $action ) {
			$args = $action->get_args();
			// args 是雙層包裹：[ [ 'email_key'=>..., 'subscription_id'=>..., 'action_name'=>... ] ]
			$inner = $args[0] ?? [];
			if ( isset( $inner['subscription_id'] ) && (int) $inner['subscription_id'] === $subscription_id
				&& isset( $inner['action_name'] ) && $inner['action_name'] === $action_name ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue(
			$found,
			$message ?: "預期找到 subscription_id={$subscription_id} 的 pending {$action_name} 排程，但找不到"
		);
	}

	/**
	 * 斷言某個 subscription_id + action_name 沒有 pending 的排程
	 *
	 * @param int    $subscription_id 訂閱 ID
	 * @param string $action_name     action 名稱
	 * @param string $message         失敗訊息
	 */
	private function assert_no_pending_action( int $subscription_id, string $action_name, string $message = '' ): void {
		$actions = $this->get_pending_actions( $action_name );

		$found = false;
		foreach ( $actions as $action ) {
			$args  = $action->get_args();
			$inner = $args[0] ?? [];
			if ( isset( $inner['subscription_id'] ) && (int) $inner['subscription_id'] === $subscription_id
				&& isset( $inner['action_name'] ) && $inner['action_name'] === $action_name ) {
				$found = true;
				break;
			}
		}

		$this->assertFalse(
			$found,
			$message ?: "預期沒有 subscription_id={$subscription_id} 的 pending {$action_name} 排程，但找到了"
		);
	}

	/**
	 * 取得某個 subscription_id + action_name 的第一筆 pending 排程 timestamp
	 *
	 * @param int    $subscription_id 訂閱 ID
	 * @param string $action_name     action 名稱
	 * @return int|null timestamp 或 null（找不到）
	 */
	private function get_pending_action_timestamp( int $subscription_id, string $action_name ): ?int {
		$actions = $this->get_pending_actions( $action_name );

		foreach ( $actions as $action ) {
			$args  = $action->get_args();
			$inner = $args[0] ?? [];
			if ( isset( $inner['subscription_id'] ) && (int) $inner['subscription_id'] === $subscription_id
				&& isset( $inner['action_name'] ) && $inner['action_name'] === $action_name ) {
				$schedule = $action->get_schedule();
				if ( $schedule instanceof \ActionScheduler_SimpleSchedule ) {
					return $schedule->get_date()->getTimestamp();
				}
			}
		}

		return null;
	}

	// ========== 冒煙測試（Smoke）==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_SubscriptionEmailHooks類別存在(): void {
		$this->assertTrue( class_exists( SubscriptionEmailHooks::class ) );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_能建立真實WC_Subscription物件(): void {
		$this->skip_if_no_subscriptions();

		$sub = $this->create_pp_subscription();
		$this->assertInstanceOf( \WC_Subscription::class, $sub );
		$this->assertGreaterThan( 0, $sub->get_id() );
	}

	// ========== 快樂路徑（Happy Flow）==========

	/**
	 * 測試案例 #1
	 * on-hold → active：排程一筆 subscription_success，timestamp ≥ time()+600（10 分鐘緩衝）
	 *
	 * 生產碼尚未實作 active 分支排程成功信，此案例必須 FAIL。
	 *
	 * @test
	 * @group happy
	 */
	public function test_on_hold轉active_應排程成功信且時間戳不早於10分鐘後(): void {
		$this->skip_if_no_subscriptions();

		$hooks        = $this->setup_hooks_with_all_emails();
		$subscription = $this->create_pp_subscription( 'on-hold' );
		$sub_id       = $subscription->get_id();

		$time_before = time();

		$hooks->on_status_updated( $subscription, 'active', 'on-hold' );

		// 預期：排程一筆 subscription_success
		$this->assert_has_pending_action( $sub_id, self::ACTION_SUCCESS );

		// 預期：timestamp 不早於 10 分鐘後（600 秒最少緩衝）
		$ts = $this->get_pending_action_timestamp( $sub_id, self::ACTION_SUCCESS );
		$this->assertNotNull( $ts, '找不到 pending subscription_success 排程的 timestamp' );
		$this->assertGreaterThanOrEqual(
			$time_before + 600,
			$ts,
			"subscription_success 排程時間 {$ts} 應不早於 time()+600=" . ( $time_before + 600 )
		);
	}

	/**
	 * 測試案例 #2
	 * pending-cancel → active：同樣排程成功信
	 *
	 * 生產碼尚未實作，此案例必須 FAIL。
	 *
	 * @test
	 * @group happy
	 */
	public function test_pending_cancel轉active_應排程成功信(): void {
		$this->skip_if_no_subscriptions();

		$hooks        = $this->setup_hooks_with_all_emails();
		$subscription = $this->create_pp_subscription( 'pending-cancel' );
		$sub_id       = $subscription->get_id();

		$hooks->on_status_updated( $subscription, 'active', 'pending-cancel' );

		$this->assert_has_pending_action( $sub_id, self::ACTION_SUCCESS );
	}

	/**
	 * 測試案例 #3
	 * pending → active（首次啟用）：不排程成功信
	 *
	 * from_status='pending' 不在允許清單，不觸發成功信——此案例應 PASS（現況正確）。
	 *
	 * @test
	 * @group happy
	 */
	public function test_pending轉active_首次啟用_不應排程成功信(): void {
		$this->skip_if_no_subscriptions();

		$hooks        = $this->setup_hooks_with_all_emails();
		$subscription = $this->create_pp_subscription( 'pending' );
		$sub_id       = $subscription->get_id();

		$hooks->on_status_updated( $subscription, 'active', 'pending' );

		$this->assert_no_pending_action( $sub_id, self::ACTION_SUCCESS );
	}

	/**
	 * 測試案例 #4a
	 * active → on-hold：不應排程成功信
	 *
	 * @test
	 * @group happy
	 */
	public function test_active轉on_hold_不應排程成功信(): void {
		$this->skip_if_no_subscriptions();

		$hooks        = $this->setup_hooks_with_all_emails();
		$subscription = $this->create_pp_subscription( 'active' );
		$sub_id       = $subscription->get_id();

		$hooks->on_status_updated( $subscription, 'on-hold', 'active' );

		$this->assert_no_pending_action( $sub_id, self::ACTION_SUCCESS );
	}

	/**
	 * 測試案例 #4b
	 * active → on-hold：應排程催繳信（既有行為）
	 *
	 * @test
	 * @group happy
	 */
	public function test_active轉on_hold_應排程催繳信(): void {
		$this->skip_if_no_subscriptions();

		$hooks        = $this->setup_hooks_with_all_emails();
		$subscription = $this->create_pp_subscription( 'active' );
		$sub_id       = $subscription->get_id();

		$hooks->on_status_updated( $subscription, 'on-hold', 'active' );

		$this->assert_has_pending_action( $sub_id, self::ACTION_FAILED );
	}

	/**
	 * 測試案例 #5a
	 * active → cancelled：不應排程成功信
	 *
	 * @test
	 * @group happy
	 */
	public function test_active轉cancelled_不應排程成功信(): void {
		$this->skip_if_no_subscriptions();

		$hooks        = $this->setup_hooks_with_all_emails();
		$subscription = $this->create_pp_subscription( 'active' );
		$sub_id       = $subscription->get_id();

		$hooks->on_status_updated( $subscription, 'cancelled', 'active' );

		$this->assert_no_pending_action( $sub_id, self::ACTION_SUCCESS );
	}

	/**
	 * 測試案例 #5b
	 * active → cancelled：應排程 end 信（既有行為）
	 *
	 * @test
	 * @group happy
	 */
	public function test_active轉cancelled_應排程結束信(): void {
		$this->skip_if_no_subscriptions();

		$hooks        = $this->setup_hooks_with_all_emails();
		$subscription = $this->create_pp_subscription( 'active' );
		$sub_id       = $subscription->get_id();

		$hooks->on_status_updated( $subscription, 'cancelled', 'active' );

		$this->assert_has_pending_action( $sub_id, self::ACTION_END );
	}

	// ========== 邊緣案例（Edge Cases）==========

	/**
	 * 測試案例 #6：震盪測試
	 * active→on-hold → on-hold→active → active→on-hold→active
	 * 模擬自動扣款流程：on-hold→active 排成功信，active→on-hold 取消成功信並排催繳，on-hold→active 取消催繳並重排成功信
	 *
	 * 最終：subscription_success pending 存在、subscription_failed pending 不存在
	 *
	 * 生產碼尚未實作 active 分支排程成功信，此案例必須 FAIL。
	 *
	 * @test
	 * @group edge
	 */
	public function test_震盪流程_最終成功信應存在且催繳信應不存在(): void {
		$this->skip_if_no_subscriptions();

		$hooks        = $this->setup_hooks_with_all_emails();
		$subscription = $this->create_pp_subscription( 'active' );
		$sub_id       = $subscription->get_id();

		// 第一次：active → on-hold（排催繳信）
		$hooks->on_status_updated( $subscription, 'on-hold', 'active' );
		$subscription->update_status( 'on-hold' );

		// 第二次：on-hold → active（取消催繳、排成功信）
		$hooks->on_status_updated( $subscription, 'active', 'on-hold' );
		$subscription->update_status( 'active' );

		// 第三次：active → on-hold（取消成功信、重排催繳）
		$hooks->on_status_updated( $subscription, 'on-hold', 'active' );
		$subscription->update_status( 'on-hold' );

		// 第四次：on-hold → active（最終復活，取消催繳、重排成功信）
		$hooks->on_status_updated( $subscription, 'active', 'on-hold' );
		$subscription->update_status( 'active' );

		// 核心不變式：最終成功信 pending 存在，催繳信 pending 不存在
		$this->assert_has_pending_action(
			$sub_id,
			self::ACTION_SUCCESS,
			'震盪後 subscription_success 排程應存在（新行為）'
		);
		$this->assert_no_pending_action(
			$sub_id,
			self::ACTION_FAILED,
			'震盪後 subscription_failed 排程應已被取消（新行為）'
		);
	}

	/**
	 * 測試案例 #7a：action_callback 狀態複查——訂閱仍為 active 時寄出成功信
	 *
	 * 使用 pre_wp_mail filter 攔截 wp_mail 呼叫。
	 * 生產碼在 action_callback 中目前沒有 active 守門邏輯，此案例可能 PASS（取決於現況）。
	 * 但我們仍需驗證行為正確。
	 *
	 * @test
	 * @group happy
	 */
	public function test_action_callback_訂閱仍為active時應寄出成功信(): void {
		$this->skip_if_no_subscriptions();

		$hooks        = $this->setup_hooks_with_all_emails();
		$subscription = $this->create_pp_subscription( 'active' );
		$sub_id       = $subscription->get_id();

		// 攔截 wp_mail 確認是否被呼叫
		$mail_called = false;
		add_filter(
			'pre_wp_mail',
			function () use ( &$mail_called ) {
				$mail_called = true;
				return true; // 攔截，不真的發信
			}
		);

		// 建立 args 模擬已排程的 subscription_success 動作
		$success_emails = $hooks->get_emails( self::ACTION_SUCCESS );
		$this->assertNotEmpty( $success_emails, '找不到 subscription_success email 模板' );

		$email = $success_emails[0];
		$args  = [
			'email_key'       => $email->key,
			'subscription_id' => $sub_id,
			'action_name'     => self::ACTION_SUCCESS,
		];

		// 直接呼叫 action_callback（subscription 狀態為 active）
		SubscriptionEmailScheduler::action_callback( $args );

		remove_all_filters( 'pre_wp_mail' );

		// 預期：訂閱為 active，成功信應被寄出
		// 注意：現行生產碼沒有 active 守門，is_failed() 回傳 false 則不阻擋——此案例應 PASS
		$this->assertTrue(
			$mail_called,
			'訂閱為 active 時，action_callback 應呼叫 wp_mail 寄出成功信'
		);
	}

	/**
	 * 測試案例 #7b：action_callback 狀態複查——訂閱為 on-hold 時跳過成功信（新行為）
	 *
	 * 生產碼尚未實作 active 守門，此案例必須 FAIL。
	 *
	 * @test
	 * @group edge
	 */
	public function test_action_callback_訂閱為on_hold時應跳過成功信不寄(): void {
		$this->skip_if_no_subscriptions();

		$hooks        = $this->setup_hooks_with_all_emails();
		$subscription = $this->create_pp_subscription( 'on-hold' );
		$sub_id       = $subscription->get_id();

		// 攔截 wp_mail，確認不被呼叫
		$mail_called = false;
		add_filter(
			'pre_wp_mail',
			function () use ( &$mail_called ) {
				$mail_called = true;
				return true;
			}
		);

		$success_emails = $hooks->get_emails( self::ACTION_SUCCESS );
		$this->assertNotEmpty( $success_emails, '找不到 subscription_success email 模板' );

		$email = $success_emails[0];
		$args  = [
			'email_key'       => $email->key,
			'subscription_id' => $sub_id,
			'action_name'     => self::ACTION_SUCCESS,
		];

		// 直接呼叫 action_callback（subscription 狀態為 on-hold）
		SubscriptionEmailScheduler::action_callback( $args );

		remove_all_filters( 'pre_wp_mail' );

		// 預期：訂閱為 on-hold，成功信不應被寄出（新行為：active 守門）
		$this->assertFalse(
			$mail_called,
			'訂閱為 on-hold 時，action_callback 應跳過成功信不寄（新行為守門）'
		);
	}

	/**
	 * 測試案例 #7c：action_callback 狀態複查——訂閱為 cancelled 時跳過成功信（新行為）
	 *
	 * 生產碼尚未實作 active 守門，此案例必須 FAIL。
	 *
	 * @test
	 * @group edge
	 */
	public function test_action_callback_訂閱為cancelled時應跳過成功信不寄(): void {
		$this->skip_if_no_subscriptions();

		$hooks        = $this->setup_hooks_with_all_emails();
		$subscription = $this->create_pp_subscription( 'cancelled' );
		$sub_id       = $subscription->get_id();

		$mail_called = false;
		add_filter(
			'pre_wp_mail',
			function () use ( &$mail_called ) {
				$mail_called = true;
				return true;
			}
		);

		$success_emails = $hooks->get_emails( self::ACTION_SUCCESS );
		$this->assertNotEmpty( $success_emails, '找不到 subscription_success email 模板' );

		$email = $success_emails[0];
		$args  = [
			'email_key'       => $email->key,
			'subscription_id' => $sub_id,
			'action_name'     => self::ACTION_SUCCESS,
		];

		SubscriptionEmailScheduler::action_callback( $args );

		remove_all_filters( 'pre_wp_mail' );

		// 預期：訂閱為 cancelled，成功信不應被寄出（新行為守門）
		$this->assertFalse(
			$mail_called,
			'訂閱為 cancelled 時，action_callback 應跳過成功信不寄（新行為守門）'
		);
	}

	/**
	 * 測試案例 #8：非開站訂閱（不設 pp_linked_site_ids）→ on-hold→active 不排程成功信
	 *
	 * is_site_sync() 守門，無 meta 應回傳 false，故不排程。
	 *
	 * @test
	 * @group edge
	 */
	public function test_非開站訂閱轉active_不應排程成功信(): void {
		$this->skip_if_no_subscriptions();

		$hooks = $this->setup_hooks_with_all_emails();

		// 建立訂閱但不設 pp_linked_site_ids
		$order = wc_create_order(
			[
				'customer_id' => $this->customer_id,
				'status'      => 'processing',
			]
		);
		$order->set_billing_email( 'test-sub-email-hooks@example.com' );
		$order->save();

		$subscription = wcs_create_subscription(
			[
				'order_id'         => $order->get_id(),
				'status'           => 'on-hold',
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'customer_id'      => $this->customer_id,
			]
		);
		$this->assertInstanceOf( \WC_Subscription::class, $subscription );
		// 故意不設 pp_linked_site_ids meta
		$sub_id = $subscription->get_id();

		$hooks->on_status_updated( $subscription, 'active', 'on-hold' );

		$this->assert_no_pending_action(
			$sub_id,
			self::ACTION_SUCCESS,
			'非開站訂閱不應排程 subscription_success'
		);
	}

	/**
	 * 測試案例 #9：無 enabled 成功信模板（enabled='0'）→ on-hold→active 不排程成功信
	 *
	 * @test
	 * @group edge
	 */
	public function test_停用的成功信模板_on_hold轉active不應排程成功信(): void {
		$this->skip_if_no_subscriptions();

		// 設定成功信為 enabled='0'
		$emails = [
			$this->make_email_config(
				[
					'key'         => $this->success_email_key,
					'action_name' => self::ACTION_SUCCESS,
					'days'        => '0',
					'enabled'     => '0', // 停用
				]
			),
			$this->make_email_config(
				[
					'key'         => $this->failed_email_key,
					'action_name' => self::ACTION_FAILED,
					'days'        => '0',
					'enabled'     => '1',
				]
			),
		];

		$this->setup_settings_with_emails( $emails );

		// 重設 singleton，確保新 instance() 讀到最新 option
		$reflection = new \ReflectionClass( SubscriptionEmailHooks::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		$hooks = SubscriptionEmailHooks::instance();

		// 確認成功信確實被停用
		$success_emails = $hooks->get_emails( self::ACTION_SUCCESS );
		$this->assertEmpty( $success_emails, '停用的 subscription_success 模板不應被取到' );

		$subscription = $this->create_pp_subscription( 'on-hold' );
		$sub_id       = $subscription->get_id();

		$hooks->on_status_updated( $subscription, 'active', 'on-hold' );

		$this->assert_no_pending_action(
			$sub_id,
			self::ACTION_SUCCESS,
			'停用的成功信模板：不應排程 subscription_success'
		);
	}

	/**
	 * 測試案例 #10：timestamp 驗證——days=1 的成功信不早於 time()+600（穩健下限）
	 *
	 * days=1 表示 1 天後發，timestamp 應不早於 time()+600（最少緩衝保證）。
	 *
	 * 生產碼尚未實作 active 分支排程成功信，此案例必須 FAIL。
	 *
	 * @test
	 * @group edge
	 */
	public function test_days等於1的成功信timestamp不早於10分鐘後(): void {
		$this->skip_if_no_subscriptions();

		$unique     = uniqid();
		$email_key  = 'test_success_d1_' . $unique;

		$emails = [
			$this->make_email_config(
				[
					'key'         => $email_key,
					'action_name' => self::ACTION_SUCCESS,
					'days'        => '1', // 1 天後
					'enabled'     => '1',
				]
			),
		];

		$this->setup_settings_with_emails( $emails );

		// 重設 singleton，讓新 instance() 讀到最新 option
		$reflection = new \ReflectionClass( SubscriptionEmailHooks::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		$hooks = SubscriptionEmailHooks::instance();

		$success_emails = $hooks->get_emails( self::ACTION_SUCCESS );
		$this->assertNotEmpty( $success_emails, '找不到 subscription_success email 模板' );

		$subscription = $this->create_pp_subscription( 'on-hold' );
		$sub_id       = $subscription->get_id();

		$time_before = time();
		$hooks->on_status_updated( $subscription, 'active', 'on-hold' );

		$ts = $this->get_pending_action_timestamp( $sub_id, self::ACTION_SUCCESS );

		$this->assertNotNull( $ts, '找不到 pending subscription_success 排程的 timestamp（新行為未實作）' );
		$this->assertGreaterThanOrEqual(
			$time_before + 600,
			$ts,
			"days=1 的成功信 timestamp {$ts} 應不早於 time()+600=" . ( $time_before + 600 )
		);
	}
}
