<?php
/**
 * customer_cancelled 通知信整合測試（issue #20）
 *
 * 驗證「終端客戶於『我的帳號』頁自行取消訂閱時，寄通知信給經銷商（站台 admin_email）」的後端行為：
 *  - WCS 的 woocommerce_customer_changed_subscription_to_cancelled hook 觸發時，
 *    排程一封 customer_cancelled 信（開站訂閱才排）。hook 名取自「客戶請求的狀態」
 *    （取消一律請求 cancelled），落地 pending-cancel 或 cancelled 皆 fire 同一 hook。
 *  - 管理員後台改狀態（只走 woocommerce_subscription_status_updated）不排程 customer_cancelled，
 *    但既有的 end 信仍照常排程（兩條路徑互不影響）。
 *  - action_callback 寄送時，customer_cancelled 收件人為站台 admin_email 且不 Bcc 自己；
 *    其餘信件維持原行為——寄給終端客戶(billing email) 並 Bcc 站台管理員。
 *  - customer_cancelled 不 unique：同一訂閱重複取消會排多封。
 *  - Email DTO 放行 customer_cancelled，且其 unique 為 false。
 *
 * 生產碼已實作，故本檔所有案例應為綠燈。
 */

declare( strict_types=1 );

namespace Tests\Integration;

use J7\PowerPartner\Domains\Email\Core\SubscriptionEmailHooks;
use J7\PowerPartner\Domains\Email\Services\SubscriptionEmailScheduler;
use J7\PowerPartner\Domains\Email\DTOs\Email;
use J7\PowerPartner\Product\SiteSync;

/**
 * @group smoke
 * @group happy
 * @group error
 * @group edge
 */
class CustomerCancelledEmailTest extends TestCase {

	/** @var string 排程 hook 名稱 */
	private const SCHEDULER_HOOK = 'power_partner/3.1.0/email/scheduler';

	/** @var string 客戶取消通知信 action_name */
	private const ACTION_CUSTOMER_CANCELLED = 'customer_cancelled';

	/** @var string 結束信 action_name */
	private const ACTION_END = 'end';

	/** @var string 開站信 action_name（作為一般信件對照組） */
	private const ACTION_SITE_SYNC = 'site_sync';

	/** @var string WCS：客戶自行取消訂閱時觸發的 hook（以請求狀態命名，落地 pending-cancel 或 cancelled 皆 fire 此 hook） */
	private const HOOK_TO_CANCELLED = 'woocommerce_customer_changed_subscription_to_cancelled';

	/** @var string 測試用終端客戶 email（刻意與 admin_email 不同） */
	private const CUSTOMER_EMAIL = 'customer-cc@example.com';

	/** @var int 測試用客戶 ID */
	private int $customer_id;

	/** @var \WC_Order|null 測試用父訂單 */
	private ?\WC_Order $parent_order = null;

	/** @var string 測試用 customer_cancelled 信 key */
	private string $cancelled_email_key;

	/** @var string 測試用 end 信 key */
	private string $end_email_key;

	/** @var string 測試用 site_sync 信 key */
	private string $site_sync_email_key;

	// ========== 測試前置作業 ==========

	protected function configure_dependencies(): void {
		if ( ! class_exists( 'WC_Subscription' ) ) {
			return;
		}

		$this->customer_id = $this->factory()->user->create(
			[
				'role'       => 'customer',
				'user_email' => self::CUSTOMER_EMAIL,
			]
		);

		$unique                    = uniqid();
		$this->cancelled_email_key = 'test_cancelled_' . $unique;
		$this->end_email_key       = 'test_end_' . $unique;
		$this->site_sync_email_key = 'test_site_sync_' . $unique;
	}

	/**
	 * 在測試 tearDown 時重設 singleton，避免污染後續測試
	 */
	public function tear_down(): void {
		$reflection = new \ReflectionClass( SubscriptionEmailHooks::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		parent::tear_down();
	}

	/**
	 * 建立真實的 WC_Subscription 物件（含 parent order 與 pp_linked_site_ids meta）
	 *
	 * 注意：必須用 wcs_create_subscription() 建立，post+meta 手法無法通過 instanceof 守門。
	 *
	 * @param string $status         初始狀態（無 wc- 前綴）
	 * @param bool   $with_site_meta 是否設定 pp_linked_site_ids（true 才是開站訂閱）
	 * @return \WC_Subscription
	 */
	private function create_pp_subscription( string $status = 'active', bool $with_site_meta = true ): \WC_Subscription {
		$order = wc_create_order(
			[
				'customer_id' => $this->customer_id,
				'status'      => 'processing',
			]
		);
		$this->assertInstanceOf( \WC_Order::class, $order, '建立父訂單失敗' );
		$order->set_billing_email( self::CUSTOMER_EMAIL );
		$order->save();

		$this->parent_order = $order;

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

		if ( $with_site_meta ) {
			// 設定 pp_linked_site_ids meta，讓 is_site_sync() 回傳 true
			$subscription->update_meta_data( SiteSync::LINKED_SITE_IDS_META_KEY, 'test-site-cc-001' );
			$subscription->save();
		}

		return $subscription;
	}

	/**
	 * 建立含 customer_cancelled、end、site_sync 三種信的 settings，
	 * 並用反射重設 singleton，確保接下來的 instance() 建立含 emails 的實例
	 * （並在 constructor 重新綁定 customer 取消 hook）。
	 *
	 * @return SubscriptionEmailHooks 新的 hooks 實例（已成為 singleton）
	 */
	private function setup_hooks_with_emails(): SubscriptionEmailHooks {
		$emails = [
			$this->make_email_config(
				[
					'key'         => $this->cancelled_email_key,
					'action_name' => self::ACTION_CUSTOMER_CANCELLED,
					'days'        => '0',
					'enabled'     => '1',
				]
			),
			$this->make_email_config(
				[
					'key'         => $this->end_email_key,
					'action_name' => self::ACTION_END,
					'days'        => '0',
					'enabled'     => '1',
				]
			),
			$this->make_email_config(
				[
					'key'         => $this->site_sync_email_key,
					'action_name' => self::ACTION_SITE_SYNC,
					'days'        => '0',
					'enabled'     => '1',
				]
			),
		];

		$this->setup_settings_with_emails( $emails );

		$reflection = new \ReflectionClass( SubscriptionEmailHooks::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		$hooks = SubscriptionEmailHooks::instance();

		// 守門：確保 emails 已含測試模板，否則測試無意義
		$this->assertNotEmpty(
			$hooks->get_emails( self::ACTION_CUSTOMER_CANCELLED ),
			'SubscriptionEmailHooks::emails 中找不到 customer_cancelled 模板，請確認 setup_settings_with_emails 正確寫入 option'
		);

		return $hooks;
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
	 * 計算某訂閱 + action_name 的 pending 排程數量（可再以 email_key 收斂，完全隔離殘留排程）
	 *
	 * @param int         $subscription_id 訂閱 ID
	 * @param string      $action_name     action 名稱
	 * @param string|null $email_key       若提供，僅計數 email_key 相符者
	 * @return int
	 */
	private function count_pending_actions( int $subscription_id, string $action_name, ?string $email_key = null ): int {
		$count = 0;
		foreach ( $this->get_pending_actions( $action_name ) as $action ) {
			$args  = $action->get_args();
			$inner = $args[0] ?? [];
			if ( ! isset( $inner['subscription_id'] ) || (int) $inner['subscription_id'] !== $subscription_id ) {
				continue;
			}
			if ( ! isset( $inner['action_name'] ) || $inner['action_name'] !== $action_name ) {
				continue;
			}
			if ( null !== $email_key && ( ! isset( $inner['email_key'] ) || $inner['email_key'] !== $email_key ) ) {
				continue;
			}
			++$count;
		}
		return $count;
	}

	/**
	 * 斷言某個 subscription_id + action_name 有 pending 的排程
	 *
	 * @param int    $subscription_id 訂閱 ID
	 * @param string $action_name     action 名稱
	 * @param string $message         失敗訊息
	 */
	private function assert_has_pending_action( int $subscription_id, string $action_name, string $message = '' ): void {
		$this->assertGreaterThan(
			0,
			$this->count_pending_actions( $subscription_id, $action_name ),
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
		$this->assertSame(
			0,
			$this->count_pending_actions( $subscription_id, $action_name ),
			$message ?: "預期沒有 subscription_id={$subscription_id} 的 pending {$action_name} 排程，但找到了"
		);
	}

	// ========== 冒煙測試（Smoke）==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_SubscriptionEmailHooks有schedule_customer_cancelled_email方法(): void {
		$this->assertTrue(
			method_exists( SubscriptionEmailHooks::class, 'schedule_customer_cancelled_email' ),
			'SubscriptionEmailHooks 應提供 schedule_customer_cancelled_email 方法'
		);
	}

	// ========== 快樂路徑（Happy Flow）==========

	/**
	 * 測試案例 #1
	 * 客戶取消、有剩餘預付期（訂閱落地 pending-cancel）：WCS 仍 fire ..._to_cancelled
	 * （hook 名取自請求狀態，非落地狀態），應排程 customer_cancelled 信，
	 * 且排程 args 的 action_name / email_key 正確。
	 *
	 * @test
	 * @group happy
	 */
	public function test_客戶取消落地pending_cancel_應排程customer_cancelled信(): void {
		$this->skip_if_no_subscriptions();

		$this->setup_hooks_with_emails();
		$subscription = $this->create_pp_subscription( 'pending-cancel' );
		$sub_id       = $subscription->get_id();

		do_action( self::HOOK_TO_CANCELLED, $subscription );

		$this->assert_has_pending_action( $sub_id, self::ACTION_CUSTOMER_CANCELLED );

		// 進一步驗證排程 args 內容（action_name 與 email_key 皆為本測試模板）
		$this->assertSame(
			1,
			$this->count_pending_actions( $sub_id, self::ACTION_CUSTOMER_CANCELLED, $this->cancelled_email_key ),
			'應恰好排程一封本測試的 customer_cancelled 信'
		);
	}

	/**
	 * 測試案例 #2
	 * 客戶取消、無剩餘預付期（訂閱落地 cancelled）：fire WCS customer hook，
	 * 應排程 customer_cancelled 信。
	 *
	 * @test
	 * @group happy
	 */
	public function test_客戶取消落地cancelled_應排程customer_cancelled信(): void {
		$this->skip_if_no_subscriptions();

		$this->setup_hooks_with_emails();
		$subscription = $this->create_pp_subscription( 'cancelled' );
		$sub_id       = $subscription->get_id();

		do_action( self::HOOK_TO_CANCELLED, $subscription );

		$this->assert_has_pending_action( $sub_id, self::ACTION_CUSTOMER_CANCELLED );
	}

	// ========== 邊緣案例（Edge Cases）==========

	/**
	 * 測試案例 #3
	 * 非開站訂閱（無 pp_linked_site_ids）→ 客戶取消不排程 customer_cancelled 信。
	 *
	 * is_site_sync() 守門，schedule_email() 內建，故無 meta 應直接跳過。
	 *
	 * @test
	 * @group edge
	 */
	public function test_非開站訂閱_客戶取消不應排程customer_cancelled信(): void {
		$this->skip_if_no_subscriptions();

		$this->setup_hooks_with_emails();
		$subscription = $this->create_pp_subscription( 'cancelled', false );
		$sub_id       = $subscription->get_id();

		do_action( self::HOOK_TO_CANCELLED, $subscription );

		$this->assert_no_pending_action(
			$sub_id,
			self::ACTION_CUSTOMER_CANCELLED,
			'非開站訂閱不應排程 customer_cancelled'
		);
	}

	/**
	 * 測試案例 #4
	 * 管理員後台改狀態：只 fire woocommerce_subscription_status_updated（to=cancelled），
	 * 不應排程 customer_cancelled（該信只綁客戶前台 hook），但既有 end 信仍照排。
	 *
	 * 直接呼叫 on_status_updated()（即 woocommerce_subscription_status_updated 的 handler），
	 * 證明兩條路徑互不影響——管理員路徑不會觸發 customer_cancelled。
	 *
	 * @test
	 * @group edge
	 */
	public function test_管理員改狀態_不應排程customer_cancelled但end照排(): void {
		$this->skip_if_no_subscriptions();

		$hooks        = $this->setup_hooks_with_emails();
		$subscription = $this->create_pp_subscription( 'active' );
		$sub_id       = $subscription->get_id();

		// 模擬管理員後台把訂閱改為 cancelled：只走狀態轉換 handler，不 fire 客戶 hook
		$hooks->on_status_updated( $subscription, 'cancelled', 'active' );

		$this->assert_no_pending_action(
			$sub_id,
			self::ACTION_CUSTOMER_CANCELLED,
			'管理員改狀態不應排程 customer_cancelled（只綁客戶前台 hook）'
		);
		$this->assert_has_pending_action(
			$sub_id,
			self::ACTION_END,
			'管理員改狀態進入 cancelled 應照常排程 end 信'
		);
	}

	/**
	 * 測試案例 #5a
	 * action_callback 收件人分流：customer_cancelled 寄給站台 admin_email，且 headers 無 Bcc。
	 *
	 * 使用 pre_wp_mail filter 攔截 wp_mail，取出 $atts。刻意用 cancelled 訂閱，
	 * 一併驗證 customer_cancelled 不受狀態複查阻擋（取消是歷史事實）。
	 *
	 * @test
	 * @group happy
	 */
	public function test_action_callback_customer_cancelled寄給admin且無Bcc(): void {
		$this->skip_if_no_subscriptions();

		$hooks        = $this->setup_hooks_with_emails();
		$subscription = $this->create_pp_subscription( 'cancelled' );
		$sub_id       = $subscription->get_id();

		$admin_email = (string) get_option( 'admin_email' );
		$this->assertNotSame(
			self::CUSTOMER_EMAIL,
			$admin_email,
			'測試前提：admin_email 需與終端客戶 email 不同，否則無法分辨收件人'
		);

		$captured = null;
		add_filter(
			'pre_wp_mail',
			function ( $null, $atts ) use ( &$captured ) {
				$captured = $atts;
				return true; // 攔截，不真的發信
			},
			10,
			2
		);

		$args = [
			'email_key'       => $this->cancelled_email_key,
			'subscription_id' => $sub_id,
			'action_name'     => self::ACTION_CUSTOMER_CANCELLED,
		];
		SubscriptionEmailScheduler::action_callback( $args );

		remove_all_filters( 'pre_wp_mail' );

		$this->assertNotNull( $captured, 'customer_cancelled 應呼叫 wp_mail（被 pre_wp_mail 攔截）' );
		$this->assertSame(
			$admin_email,
			$captured['to'],
			'customer_cancelled 收件人應為站台 admin_email（經銷商本人）'
		);
		$this->assertEmpty(
			$this->find_bcc_headers( $captured['headers'] ),
			'customer_cancelled 不應加 Bcc header（避免同一封信寄兩次）'
		);
	}

	/**
	 * 測試案例 #5b（對照組）
	 * action_callback 收件人分流：一般信件（site_sync）維持原行為——
	 * 寄給終端客戶(billing email)，並 Bcc 站台 admin_email。
	 *
	 * @test
	 * @group happy
	 */
	public function test_action_callback_一般信寄給客戶並Bcc管理員(): void {
		$this->skip_if_no_subscriptions();

		$hooks        = $this->setup_hooks_with_emails();
		$subscription = $this->create_pp_subscription( 'active' );
		$sub_id       = $subscription->get_id();

		$admin_email = (string) get_option( 'admin_email' );

		$captured = null;
		add_filter(
			'pre_wp_mail',
			function ( $null, $atts ) use ( &$captured ) {
				$captured = $atts;
				return true;
			},
			10,
			2
		);

		$args = [
			'email_key'       => $this->site_sync_email_key,
			'subscription_id' => $sub_id,
			'action_name'     => self::ACTION_SITE_SYNC,
		];
		SubscriptionEmailScheduler::action_callback( $args );

		remove_all_filters( 'pre_wp_mail' );

		$this->assertNotNull( $captured, '一般信件應呼叫 wp_mail（被 pre_wp_mail 攔截）' );
		$this->assertSame(
			self::CUSTOMER_EMAIL,
			$captured['to'],
			'一般信件收件人應為終端客戶 billing email'
		);
		$this->assertNotEmpty(
			$this->find_bcc_headers( $captured['headers'] ),
			'一般信件應 Bcc 站台管理員'
		);
		$bcc_headers = $this->find_bcc_headers( $captured['headers'] );
		$this->assertStringContainsString(
			$admin_email,
			implode( "\n", $bcc_headers ),
			'一般信件的 Bcc 應為站台 admin_email'
		);
	}

	/**
	 * 測試案例 #6
	 * 重複取消：fire 客戶 hook 兩次 → 排兩封 customer_cancelled 信（不 unique）。
	 *
	 * customer_cancelled 的 unique 為 false，maybe_unschedule 不動作，
	 * 兩次排程即使 timestamp 相同，ActionScheduler 仍允許重複 pending action。
	 * 以 email_key 收斂計數，完全隔離其他排程來源。
	 *
	 * @test
	 * @group edge
	 */
	public function test_重複取消_應排程兩封customer_cancelled信(): void {
		$this->skip_if_no_subscriptions();

		$this->setup_hooks_with_emails();
		$subscription = $this->create_pp_subscription( 'cancelled' );
		$sub_id       = $subscription->get_id();

		do_action( self::HOOK_TO_CANCELLED, $subscription );
		do_action( self::HOOK_TO_CANCELLED, $subscription );

		$this->assertSame(
			2,
			$this->count_pending_actions( $sub_id, self::ACTION_CUSTOMER_CANCELLED, $this->cancelled_email_key ),
			'重複取消兩次應排兩封 customer_cancelled 信（不 unique）'
		);
	}

	// ========== 錯誤 / DTO 驗證（Error）==========

	/**
	 * 測試案例 #7a
	 * Email::create(['action_name' => 'customer_cancelled', ...]) 通過 validate，且 unique 為 false。
	 *
	 * @test
	 * @group error
	 */
	public function test_Email_create_customer_cancelled通過驗證且非unique(): void {
		$config = $this->make_email_config(
			[
				'key'         => 'cc_dto_' . uniqid(),
				'action_name' => self::ACTION_CUSTOMER_CANCELLED,
				'days'        => '0',
			]
		);

		$email = Email::create( $config );

		$this->assertInstanceOf( Email::class, $email );
		$this->assertSame( self::ACTION_CUSTOMER_CANCELLED, $email->action_name );
		$this->assertFalse( $email->unique, 'customer_cancelled 不應被強制設為 unique（每次取消都寄）' );
	}

	/**
	 * 測試案例 #7b
	 * 無效 action_name 仍應丟出例外（白名單放行只擴充 customer_cancelled，不放行任意值）。
	 *
	 * @test
	 * @group error
	 */
	public function test_Email_create_無效action_name應丟例外(): void {
		$this->expectException( \Exception::class );

		Email::create(
			$this->make_email_config(
				[
					'key'         => 'invalid_dto_' . uniqid(),
					'action_name' => 'totally_invalid_action',
					'days'        => '0',
				]
			)
		);
	}

	// ========== 私有 Helper ==========

	/**
	 * 從 wp_mail headers（陣列或字串）中挑出 Bcc 標頭
	 *
	 * @param array<string>|string $headers wp_mail 的 headers
	 * @return array<string> 所有 Bcc 標頭（可能為空）
	 */
	private function find_bcc_headers( $headers ): array {
		$lines = is_array( $headers ) ? $headers : preg_split( '/\r\n|\r|\n/', (string) $headers );
		$lines = is_array( $lines ) ? $lines : [];

		return array_values(
			array_filter(
				$lines,
				static fn( $line ) => is_string( $line ) && stripos( $line, 'bcc:' ) === 0
			)
		);
	}
}
