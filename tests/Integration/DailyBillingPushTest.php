<?php
/**
 * 新架構（PowerCloud）每日計費推送 整合測試
 *
 * 驗收標準：specs/features/billing/推送新架構網站計費資料.feature
 * 流程圖：  specs/activities/新架構每日計費推送流程.activity
 *
 * 覆蓋的 Rule：
 *   前置（參數）- 重試時沿用首次觸發的 billing_date
 *   前置（狀態）- 每日 UTC+8 05:00（21:00 UTC）以 wall-clock cron 觸發、同時只允許一個排程、
 *                既有 interval 排程遷移為 cron 排程
 *   前置（狀態）- 外掛啟用／版本升級後立刻排一次首推（壓縮 TOFU 搶綁窗口）
 *   前置（狀態）- API Key / partner_id 缺漏時中止推送
 *   後置（狀態）- 分頁拉完全量、total 只認第 1 頁、分頁以 id 去重、不做二次過濾、只計 running、
 *                cloud_user_id、billing_date 取自最近一次排程 slot、首推成功後記錄本地綁定值、
 *                domain 優先序、Basic Auth
 *   後置（事件）- 成功寫 info log（含計費站數與總金額）、排程漂移超過門檻寫 error log
 *   錯誤處理    - 無 cloud_user_id / 清單取得失敗 / 推送重試 3 次 / 重試上限寄信 /
 *                dailyCost 異常值 / API Key 不落地 log / /websites 回應原文不落地 log /
 *                設定類中止一律通知管理員 / 本地綁定值不符 / 接收端回 403 綁定不符
 *   邊界條件    - 清單為空仍推送空 sites（無本地綁定值才跳過）、多個相異 userId 中止、
 *                可計費網站解析不出 owner 時中止、全部非 running 仍推送金額 0、
 *                清單非空卻無可計費網站時告警
 */

declare( strict_types=1 );

namespace Tests\Integration;

use J7\PowerPartner\Api\Connect;
use J7\PowerPartner\Api\FetchPowerCloud;
use J7\PowerPartner\Api\Main;
use J7\PowerPartner\Domains\Billing\Core\DailyBillingCron;

/**
 * @group smoke
 * @group happy
 * @group error
 * @group edge
 */
class DailyBillingPushTest extends TestCase {

	private const API_KEY       = 'pk_test_123';
	private const PARTNER_ID    = '174';
	private const CLOUD_USER_ID = 'cu-1111-aaaa';

	/** @var array<int, array{url: string, args: array<string, mixed>}> 攔截到的所有 HTTP 請求 */
	private array $requests = [];

	/** @var array<int, array{message: string, level: string, context: string}> 攔截到的所有 log */
	private array $logs = [];

	/** @var callable|null 目前掛載的 pre_http_request callback */
	private $http_mock = null;

	/** @var callable|null 目前掛載的 woocommerce_logger_log_message callback */
	private $log_mock = null;

	/** @var callable|null 目前掛載的 pre_wp_mail callback */
	private $mail_mock = null;

	/** @var array<int, array<string, mixed>> 攔截到的所有寄信 */
	private array $mails = [];

	/**
	 * 設定（每個測試前執行）
	 */
	public function set_up(): void {
		parent::set_up();

		\set_transient( Main::POWERCLOUD_API_KEY_TRANSIENT_KEY, self::API_KEY );
		\update_option( Connect::PARTNER_ID_OPTION_NAME, self::PARTNER_ID );

		$this->requests = [];
		$this->logs     = [];
		$this->mails    = [];

		$this->capture_logs();
		$this->capture_mails();

		// 每個測試都從「沒有任何排程」開始，避免 bootstrap 時 init 註冊的排程干擾斷言
		\as_unschedule_all_actions( DailyBillingCron::CRON_HOOK );
		\as_unschedule_all_actions( DailyBillingCron::RETRY_HOOK );

		// bootstrap 的 init 可能已寫入這些 option，逐一清掉以免干擾斷言
		\delete_option( DailyBillingCron::BOUND_CLOUD_USER_ID_OPTION );
		\delete_option( DailyBillingCron::BOOTSTRAP_VERSION_OPTION );
		\delete_option( DailyBillingCron::SCHEDULE_VERSION_OPTION );
	}

	/**
	 * 清理（每個測試後執行）
	 */
	public function tear_down(): void {
		\delete_transient( Main::POWERCLOUD_API_KEY_TRANSIENT_KEY );
		\delete_option( Connect::PARTNER_ID_OPTION_NAME );
		\delete_option( DailyBillingCron::BOUND_CLOUD_USER_ID_OPTION );
		\delete_option( DailyBillingCron::BOOTSTRAP_VERSION_OPTION );
		\delete_option( DailyBillingCron::SCHEDULE_VERSION_OPTION );

		if ( $this->http_mock ) {
			\remove_filter( 'pre_http_request', $this->http_mock, 10 );
			$this->http_mock = null;
		}
		if ( $this->log_mock ) {
			\remove_filter( 'woocommerce_logger_log_message', $this->log_mock, 10 );
			$this->log_mock = null;
		}
		if ( $this->mail_mock ) {
			\remove_filter( 'pre_wp_mail', $this->mail_mock, 10 );
			$this->mail_mock = null;
		}

		\as_unschedule_all_actions( DailyBillingCron::CRON_HOOK );
		\as_unschedule_all_actions( DailyBillingCron::RETRY_HOOK );

		parent::tear_down();
	}

	// ========================================================================
	// Helpers
	// ========================================================================

	/**
	 * 攔截 Plugin::logger 寫出的所有 log（透過 WC_Logger 的 filter）
	 */
	private function capture_logs(): void {
		$this->log_mock = function ( $message, $level = 'info', $context = [], $handler = null ) {
			$this->logs[] = [
				'message' => (string) $message,
				'level'   => (string) $level,
				'context' => (string) \wp_json_encode( $context ),
			];
			return $message;
		};
		\add_filter( 'woocommerce_logger_log_message', $this->log_mock, 10, 4 );
	}

	/**
	 * 攔截 wp_mail，不實際寄出
	 */
	private function capture_mails(): void {
		$this->mail_mock = function ( $return, $atts ) {
			$this->mails[] = is_array( $atts ) ? $atts : [];
			return true;
		};
		\add_filter( 'pre_wp_mail', $this->mail_mock, 10, 2 );
	}

	/**
	 * 組出 wp_remote_* 的假回應
	 *
	 * @param int                 $code HTTP status code
	 * @param array<mixed>|string $body 回應 body
	 * @return array<string, mixed>
	 */
	private function http_response( int $code, array|string $body = [] ): array {
		return [
			'headers'  => [],
			'body'     => is_string( $body ) ? $body : (string) \wp_json_encode( $body ),
			'response' => [
				'code'    => $code,
				'message' => '',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/**
	 * 掛載 HTTP mock：/websites 依 page 分頁回應、powercloud-daily-billing 回指定狀態
	 *
	 * @param array<int, array<string, mixed>> $websites     PowerCloud 網站清單（全量）
	 * @param array<string, mixed>             $opts         total（覆寫總數）、page_errors（[page => int|WP_Error]）、
	 *                                                       error_body（page_errors 的回應 body）、
	 *                                                       websites_body（覆寫 /websites 回應 body，用於模擬 envelope 改版）、
	 *                                                       page_responses（[page => body]，逐頁完全自訂，用於模擬缺 total／忽略 page）、
	 *                                                       cloud_status（int|WP_Error）、cloud_body（CloudServer 回應 body）
	 */
	private function mock_http( array $websites, array $opts = [] ): void {
		$total          = $opts['total'] ?? count( $websites );
		$page_errors    = $opts['page_errors'] ?? [];
		$error_body     = $opts['error_body'] ?? [ 'message' => 'error' ];
		$websites_body  = $opts['websites_body'] ?? null;
		$page_responses = $opts['page_responses'] ?? null;
		$cloud_status   = $opts['cloud_status'] ?? 200;
		$cloud_body     = $opts['cloud_body'] ?? [ 'status' => 200 ];

		$this->http_mock = function ( $pre, $args, $url ) use (
			$websites,
			$total,
			$page_errors,
			$error_body,
			$websites_body,
			$page_responses,
			$cloud_status,
			$cloud_body
		) {
			$this->requests[] = [
				'url'  => (string) $url,
				'args' => is_array( $args ) ? $args : [],
			];

			if ( str_contains( (string) $url, 'powercloud-daily-billing' ) ) {
				if ( $cloud_status instanceof \WP_Error ) {
					return $cloud_status;
				}
				return $this->http_response( (int) $cloud_status, $cloud_body );
			}

			if ( str_contains( (string) $url, '/websites' ) ) {
				$query = [];
				parse_str( (string) \wp_parse_url( (string) $url, PHP_URL_QUERY ), $query );
				$page  = (int) ( $query['page'] ?? 1 );
				$limit = (int) ( $query['limit'] ?? 250 );

				if ( isset( $page_errors[ $page ] ) ) {
					$err = $page_errors[ $page ];
					return $err instanceof \WP_Error ? $err : $this->http_response( (int) $err, $error_body );
				}

				if ( null !== $websites_body ) {
					return $this->http_response( 200, $websites_body );
				}

				if ( null !== $page_responses ) {
					return $this->http_response( 200, $page_responses[ $page ] ?? [ 'data' => [] ] );
				}

				return $this->http_response(
					200,
					[
						'data'  => array_values( array_slice( $websites, ( $page - 1 ) * $limit, $limit ) ),
						'total' => $total,
					]
				);
			}

			return $this->http_response( 200, [] );
		};
		\add_filter( 'pre_http_request', $this->http_mock, 10, 3 );
	}

	/**
	 * 建立一筆 PowerCloud 網站資料
	 *
	 * @param array<string, mixed> $overrides 覆寫欄位
	 * @return array<string, mixed>
	 */
	private function make_website( array $overrides = [] ): array {
		return array_merge(
			[
				'id'             => 'ws-' . \wp_generate_password( 8, false ),
				'primaryDomain'  => 'a.wpsite.pro',
				'status'         => 'running',
				'dailyCost'      => 10.5,
				'userId'         => self::CLOUD_USER_ID,
			],
			$overrides
		);
	}

	/**
	 * 取得所有打向 PowerCloud /websites 的請求
	 *
	 * @return array<int, array{url: string, args: array<string, mixed>}>
	 */
	private function websites_requests(): array {
		return array_values(
			array_filter(
				$this->requests,
				static fn( $r ) => str_contains( $r['url'], '/websites' )
			)
		);
	}

	/**
	 * 取得推送到 CloudServer 的請求（沒有則 null）
	 *
	 * @return array{url: string, args: array<string, mixed>}|null
	 */
	private function push_request(): ?array {
		foreach ( $this->requests as $request ) {
			if ( str_contains( $request['url'], 'powercloud-daily-billing' ) ) {
				return $request;
			}
		}
		return null;
	}

	/**
	 * 取得推送的 payload（沒推送則 null）
	 *
	 * @return array<string, mixed>|null
	 */
	private function push_payload(): ?array {
		$request = $this->push_request();
		if ( null === $request ) {
			return null;
		}
		$decoded = json_decode( (string) ( $request['args']['body'] ?? '' ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * 斷言沒有推送到 CloudServer
	 *
	 * @param string $message 失敗訊息
	 */
	private function assert_not_pushed( string $message = '不應推送至 CloudServer' ): void {
		$this->assertNull( $this->push_request(), $message );
	}

	/**
	 * 斷言存在指定等級、訊息包含指定字串的 log
	 *
	 * @param string $level  log 等級
	 * @param string $needle 訊息應包含的字串（空字串表示不檢查內容）
	 */
	private function assert_log( string $level, string $needle = '' ): void {
		$matched = array_filter(
			$this->logs,
			static fn( $log ) => $log['level'] === $level && ( '' === $needle || str_contains( $log['message'], $needle ) )
		);
		$this->assertNotEmpty(
			$matched,
			"找不到 level={$level} 且包含「{$needle}」的 log，實際 log：" . \wp_json_encode( $this->logs )
		);
	}

	/**
	 * 取得 pending 的排程時間戳（沒有則 null）
	 *
	 * @param string $hook hook 名稱
	 * @return int|null
	 */
	private function next_scheduled( string $hook ): ?int {
		$next = \as_next_scheduled_action( $hook );
		return is_int( $next ) ? $next : null;
	}

	/**
	 * 取得 pending 排程列表
	 *
	 * @param string $hook hook 名稱
	 * @return array<\ActionScheduler_Action>
	 */
	private function pending_actions( string $hook ): array {
		return \as_get_scheduled_actions(
			[
				'hook'     => $hook,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			]
		);
	}

	// ========================================================================
	// 前置（狀態）- 排程註冊
	// ========================================================================

	/**
	 * Rule: 排程於每日 UTC+8 05:00（21:00 UTC）觸發，且採 wall-clock（cron）排程不累積漂移
	 *
	 * @group smoke
	 * @group happy
	 */
	public function test_registers_recurring_action_at_21_utc(): void {
		DailyBillingCron::instance()->register_daily_action_scheduler();

		$next = $this->next_scheduled( DailyBillingCron::CRON_HOOK );
		$this->assertNotNull( $next, '應註冊每日推送排程' );
		$this->assertSame( '21:00:00', gmdate( 'H:i:s', $next ), '排程起始時間應為 21:00 UTC（= UTC+8 05:00）' );
		$this->assertGreaterThanOrEqual( time(), $next, '排程時間不應在過去' );

		$actions = $this->pending_actions( DailyBillingCron::CRON_HOOK );
		$this->assertCount( 1, $actions );
		$schedule = $actions[ array_key_first( $actions ) ]->get_schedule();
		$this->assertTrue( $schedule->is_recurring(), '應為定期排程' );
		$this->assertInstanceOf(
			\ActionScheduler_CronSchedule::class,
			$schedule,
			'須為 wall-clock 排程：interval 排程以「實際執行時間 + 24h」推算下一次，延遲會單向累積漂移'
		);
		$this->assertSame(
			DailyBillingCron::CRON_EXPRESSION,
			(string) $schedule->get_recurrence(),
			'cron 運算式應為每日 21:00 UTC'
		);
	}

	/**
	 * Rule: 既有的 interval 排程須遷移為 wall-clock（cron）排程，且不重複產生排程
	 *
	 * @group edge
	 */
	public function test_migrates_legacy_interval_schedule_to_cron(): void {
		\as_schedule_recurring_action(
			$this->next_21_utc(),
			DAY_IN_SECONDS,
			DailyBillingCron::CRON_HOOK
		);

		DailyBillingCron::instance()->register_daily_action_scheduler();

		$actions = $this->pending_actions( DailyBillingCron::CRON_HOOK );
		$this->assertCount( 1, $actions, '遷移後排程數量仍應為 1' );

		$schedule = $actions[ array_key_first( $actions ) ]->get_schedule();
		$this->assertInstanceOf( \ActionScheduler_CronSchedule::class, $schedule, 'interval 排程應被換成 cron 排程' );
		$this->assertSame(
			'21:00:00',
			gmdate( 'H:i:s', (int) $schedule->get_date()->getTimestamp() ),
			'遷移後仍應落在 21:00 UTC'
		);
	}

	/**
	 * 下一個 21:00 UTC 時間戳
	 */
	private function next_21_utc(): int {
		$today = (int) strtotime( gmdate( 'Y-m-d', time() ) . ' 00:00:00 UTC' );
		$slot  = $today + 21 * HOUR_IN_SECONDS;
		return $slot <= time() ? $slot + DAY_IN_SECONDS : $slot;
	}

	/**
	 * Rule: 已存在排程時不重複註冊
	 *
	 * @group edge
	 */
	public function test_does_not_register_duplicate_schedule(): void {
		DailyBillingCron::instance()->register_daily_action_scheduler();
		DailyBillingCron::instance()->register_daily_action_scheduler();
		DailyBillingCron::instance()->register_daily_action_scheduler();

		$this->assertCount(
			1,
			$this->pending_actions( DailyBillingCron::CRON_HOOK ),
			'重複註冊時排程數量仍應為 1'
		);
	}

	// ========================================================================
	// 前置（狀態）- 中止條件
	// ========================================================================

	/**
	 * Rule: PowerCloud API Key 不存在時中止推送，且不呼叫任何 API
	 *
	 * @group error
	 */
	public function test_aborts_when_api_key_missing(): void {
		\delete_transient( Main::POWERCLOUD_API_KEY_TRANSIENT_KEY );
		$this->mock_http( [ $this->make_website() ] );

		$result = DailyBillingCron::run();

		$this->assertFalse( $result['pushed'] );
		$this->assertSame( 'no_api_key', $result['reason'] );
		$this->assertEmpty( $this->websites_requests(), '不應呼叫 PowerCloud API' );
		$this->assert_not_pushed();
		$this->assert_log( 'error' );
	}

	/**
	 * Rule: partner_id 未設定時中止推送
	 *
	 * @group error
	 */
	public function test_aborts_when_partner_id_empty(): void {
		\delete_option( Connect::PARTNER_ID_OPTION_NAME );
		$this->mock_http( [ $this->make_website() ] );

		$result = DailyBillingCron::run();

		$this->assertFalse( $result['pushed'] );
		$this->assertSame( 'no_partner_id', $result['reason'] );
		$this->assert_not_pushed();
		$this->assert_log( 'error' );
	}

	/**
	 * Rule 延伸: partner_id 非數字時等同未設定 —— 接收端契約要求 int，
	 * 直接 cast 會送出 partner_id: 0 扣到錯的人頭上
	 *
	 * @group edge
	 */
	public function test_aborts_when_partner_id_not_numeric(): void {
		\update_option( Connect::PARTNER_ID_OPTION_NAME, 'not-a-number' );
		$this->mock_http( [ $this->make_website() ] );

		$result = DailyBillingCron::run();

		$this->assertFalse( $result['pushed'] );
		$this->assertSame( 'no_partner_id', $result['reason'] );
		$this->assert_not_pushed( 'partner_id 非數字時不得送出 partner_id: 0' );
		$this->assert_log( 'error' );
	}

	// ========================================================================
	// 後置（狀態）- 取清單與 payload
	// ========================================================================

	/**
	 * Rule: 總數超過單頁上限時續拉後續分頁
	 *
	 * @group happy
	 */
	public function test_fetches_all_pages_when_total_exceeds_page_limit(): void {
		$websites = [];
		for ( $i = 0; $i < 300; $i++ ) {
			$websites[] = $this->make_website( [ 'primaryDomain' => "site{$i}.wpsite.pro" ] );
		}
		$this->mock_http( $websites );

		$result = DailyBillingCron::run();

		$this->assertTrue( $result['pushed'] );
		$this->assertCount( 2, $this->websites_requests(), '300 筆 / 每頁 250 應呼叫 2 次' );
		$payload = $this->push_payload();
		$this->assertNotNull( $payload );
		$this->assertCount( 300, $payload['sites'], '應推送全量 300 筆' );
	}

	/**
	 * Rule: 總數未超過單頁上限時只拉一次
	 *
	 * @group happy
	 */
	public function test_fetches_single_page_when_total_within_limit(): void {
		$this->mock_http(
			[
				$this->make_website( [ 'primaryDomain' => 'a.wpsite.pro' ] ),
				$this->make_website( [ 'primaryDomain' => 'b.wpsite.pro' ] ),
				$this->make_website( [ 'primaryDomain' => 'c.wpsite.pro' ] ),
			]
		);

		DailyBillingCron::run();

		$this->assertCount( 1, $this->websites_requests(), 'total 3 只需呼叫 1 次' );
		$request = $this->websites_requests()[0];
		$this->assertStringContainsString( 'page=1', $request['url'] );
		$this->assertStringContainsString( 'limit=250', $request['url'] );
	}

	/**
	 * Rule: 網站清單的範圍即為計費集合，不以 cloud_user_id 二次過濾
	 *
	 * @group happy
	 */
	public function test_does_not_filter_list_by_cloud_user_id(): void {
		$this->mock_http(
			[
				$this->make_website(
					[
						'primaryDomain' => 'a.wpsite.pro',
						'dailyCost'     => 10.5,
					]
				),
				$this->make_website(
					[
						'primaryDomain' => 'b.wpsite.pro',
						'dailyCost'     => 20.0,
					]
				),
			]
		);

		DailyBillingCron::run();

		$payload = $this->push_payload();
		$this->assertNotNull( $payload );
		$this->assertCount( 2, $payload['sites'] );
	}

	/**
	 * Rule: 只有 status 為 running 的網站列入計費
	 *
	 * @dataProvider provide_site_status
	 * @group happy
	 *
	 * @param string $status   網站狀態
	 * @param int    $billable 應列入推送清單的數量
	 */
	public function test_only_running_sites_are_billable( string $status, int $billable ): void {
		$this->mock_http( [ $this->make_website( [ 'status' => $status ] ) ] );

		DailyBillingCron::run();

		$payload = $this->push_payload();
		$this->assertNotNull( $payload, '仍應推送（清單非空）' );
		$this->assertCount( $billable, $payload['sites'], "status={$status} 的計費站數應為 {$billable}" );
	}

	/**
	 * 四種網站狀態
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public function provide_site_status(): array {
		return [
			'running 計費'   => [ 'running', 1 ],
			'creating 不計費' => [ 'creating', 0 ],
			'stopped 不計費'  => [ 'stopped', 0 ],
			'deleting 不計費' => [ 'deleting', 0 ],
		];
	}

	/**
	 * Rule: cloud_user_id 取自網站清單的 userId 欄位
	 *
	 * @group happy
	 */
	public function test_cloud_user_id_comes_from_website_list(): void {
		$this->mock_http(
			[
				$this->make_website(
					[
						'primaryDomain' => 'a.wpsite.pro',
						'status'        => 'running',
					]
				),
				$this->make_website(
					[
						'primaryDomain' => 'b.wpsite.pro',
						'status'        => 'stopped',
						'dailyCost'     => 99.0,
					]
				),
			]
		);

		DailyBillingCron::run();

		$payload = $this->push_payload();
		$this->assertNotNull( $payload );
		$this->assertSame( self::CLOUD_USER_ID, $payload['cloud_user_id'] );
	}

	/**
	 * Rule: payload 帶 partner_id（int）與 billing_date（UTC+8 的 YYYY-MM-DD）
	 *
	 * @group smoke
	 * @group happy
	 */
	public function test_payload_carries_partner_id_and_billing_date(): void {
		$this->mock_http( [ $this->make_website() ] );

		DailyBillingCron::run();

		$payload = $this->push_payload();
		$this->assertNotNull( $payload );
		$this->assertSame( 174, $payload['partner_id'], 'partner_id 應為 int' );
		$this->assertSame(
			DailyBillingCron::get_billing_date(),
			$payload['billing_date'],
			'billing_date 應為推送當下的 UTC+8 日期'
		);
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $payload['billing_date'] );
	}

	/**
	 * Rule: billing_date 為 UTC+8 日期（跨日邊界）
	 *
	 * @group edge
	 */
	public function test_billing_date_uses_utc8_day_boundary(): void {
		// 2026-08-18 15:59:59 UTC = 2026-08-18 23:59:59 UTC+8
		$this->assertSame(
			'2026-08-18',
			DailyBillingCron::get_billing_date( (int) strtotime( '2026-08-18 15:59:59 UTC' ) )
		);
		// 2026-08-18 16:00:00 UTC = 2026-08-19 00:00:00 UTC+8
		$this->assertSame(
			'2026-08-19',
			DailyBillingCron::get_billing_date( (int) strtotime( '2026-08-18 16:00:00 UTC' ) )
		);
		// 2026-08-18 21:00:00 UTC = 2026-08-19 05:00:00 UTC+8（排程觸發時刻）
		$this->assertSame(
			'2026-08-19',
			DailyBillingCron::get_billing_date( (int) strtotime( '2026-08-18 21:00:00 UTC' ) )
		);
	}

	/**
	 * Rule: 重試時沿用首次觸發決定的 billing_date
	 *
	 * @group edge
	 */
	public function test_retry_reuses_original_billing_date(): void {
		$this->mock_http( [ $this->make_website() ] );

		DailyBillingCron::run(
			[
				'billing_date' => '2026-08-19',
				'retried'      => 1,
			]
		);

		$payload = $this->push_payload();
		$this->assertNotNull( $payload );
		$this->assertSame( '2026-08-19', $payload['billing_date'], '重試不得重新計算 billing_date' );
	}

	/**
	 * Rule: payload 每筆網站帶 domain、status、dailyCost，domain 取值有優先序
	 *
	 * @dataProvider provide_domain_priority
	 * @group happy
	 *
	 * @param string $primary_domain  primaryDomain
	 * @param string $domain          domain
	 * @param string $sub_domain      subDomain
	 * @param string $wildcard_domain wildcardDomain
	 * @param string $expected        期望取到的 domain
	 */
	public function test_domain_priority(
		string $primary_domain,
		string $domain,
		string $sub_domain,
		string $wildcard_domain,
		string $expected
	): void {
		$this->mock_http(
			[
				$this->make_website(
					[
						'primaryDomain'  => $primary_domain,
						'domain'         => $domain,
						'subDomain'      => $sub_domain,
						'wildcardDomain' => $wildcard_domain,
					]
				),
			]
		);

		DailyBillingCron::run();

		$payload = $this->push_payload();
		$this->assertNotNull( $payload );
		$this->assertSame( $expected, $payload['sites'][0]['domain'] );
		$this->assertSame( 'running', $payload['sites'][0]['status'] );
		$this->assertEqualsWithDelta( 10.5, (float) $payload['sites'][0]['dailyCost'], 0.001 );
	}

	/**
	 * domain 取值優先序：primaryDomain > domain > subDomain > wildcardDomain
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
	 */
	public function provide_domain_priority(): array {
		return [
			'primaryDomain 最優先'   => [ 'p.wpsite.pro', 'd.pro', 's.pro', 'w.pro', 'p.wpsite.pro' ],
			'退到 domain'          => [ '', 'd.pro', 's.pro', 'w.pro', 'd.pro' ],
			'退到 subDomain'       => [ '', '', 's.pro', 'w.pro', 's.pro' ],
			'退到 wildcardDomain'   => [ '', '', '', 'w.pro', 'w.pro' ],
		];
	}

	/**
	 * Rule: 以 Basic Auth POST 推送至 CloudServer 的計費 endpoint
	 *
	 * @group smoke
	 * @group happy
	 */
	public function test_pushes_with_basic_auth_to_billing_endpoint(): void {
		$this->mock_http( [ $this->make_website() ] );

		DailyBillingCron::run();

		$request = $this->push_request();
		$this->assertNotNull( $request, '應推送至 CloudServer' );
		$this->assertStringContainsString(
			'/wp-json/power-partner-server/v2/powercloud-daily-billing',
			$request['url']
		);
		$this->assertSame( 'POST', strtoupper( (string) ( $request['args']['method'] ?? 'POST' ) ) );
		$headers = $request['args']['headers'] ?? [];
		$this->assertArrayHasKey( 'Authorization', $headers );
		$this->assertStringStartsWith( 'Basic ', (string) $headers['Authorization'] );
		$this->assertSame( 'application/json', $headers['Content-Type'] ?? '' );
	}

	// ========================================================================
	// 後置（事件）
	// ========================================================================

	/**
	 * Rule: 推送成功（HTTP 2xx）寫入 info log，含推送站數與總金額
	 *
	 * @group happy
	 */
	public function test_logs_info_with_billable_count_and_total_on_success(): void {
		$this->mock_http(
			[
				$this->make_website(
					[
						'primaryDomain' => 'a.wpsite.pro',
						'status'        => 'running',
						'dailyCost'     => 10.5,
					]
				),
				$this->make_website(
					[
						'primaryDomain' => 'b.wpsite.pro',
						'status'        => 'running',
						'dailyCost'     => 20.0,
					]
				),
				$this->make_website(
					[
						'primaryDomain' => 'c.wpsite.pro',
						'status'        => 'running',
						'dailyCost'     => 0.25,
					]
				),
				$this->make_website(
					[
						'primaryDomain' => 'd.wpsite.pro',
						'status'        => 'stopped',
						'dailyCost'     => 99.0,
					]
				),
			]
		);

		$result = DailyBillingCron::run();

		$this->assertTrue( $result['pushed'] );
		$this->assertSame( 3, $result['billable_count'] );
		$this->assertEqualsWithDelta( 30.75, $result['total_amount'], 0.001 );
		$this->assert_log( 'info', '計費站數: 3' );
		$this->assert_log( 'info', '總金額: 30.75' );
	}

	// ========================================================================
	// 錯誤處理
	// ========================================================================

	/**
	 * Rule: 無法取得 cloud_user_id 時中止推送並寫 error log
	 *
	 * @group error
	 */
	public function test_aborts_when_no_cloud_user_id(): void {
		$site = $this->make_website();
		unset( $site['userId'] );
		$this->mock_http( [ $site ] );

		$result = DailyBillingCron::run();

		$this->assertFalse( $result['pushed'] );
		$this->assertSame( 'no_cloud_user_id', $result['reason'] );
		$this->assert_not_pushed( '取不到 cloud_user_id 時不得以空值推送' );
		$this->assert_log( 'error' );
	}

	/**
	 * Rule: 取得網站清單失敗時不送出不完整的 payload，並排程 30 分鐘後重試
	 *
	 * @group error
	 */
	public function test_does_not_push_partial_list_when_a_page_fails(): void {
		$websites = [];
		for ( $i = 0; $i < 300; $i++ ) {
			$websites[] = $this->make_website( [ 'primaryDomain' => "site{$i}.wpsite.pro" ] );
		}
		$this->mock_http( $websites, [ 'page_errors' => [ 2 => 500 ] ] );

		$result = DailyBillingCron::run();

		$this->assertFalse( $result['pushed'] );
		$this->assertSame( 'fetch_failed', $result['reason'] );
		$this->assert_not_pushed( '第二頁失敗時不得送出僅含第一頁的 payload' );

		$next = $this->next_scheduled( DailyBillingCron::RETRY_HOOK );
		$this->assertNotNull( $next, '應排程重試' );
		$this->assertEqualsWithDelta( time() + 30 * MINUTE_IN_SECONDS, $next, 60 );
	}

	/**
	 * Rule: 推送失敗時最多重試 3 次、間隔 30 分鐘
	 *
	 * @dataProvider provide_retry_sequence
	 * @group error
	 *
	 * @param int  $retried        已重試次數
	 * @param bool $should_retry   是否應排程下次重試
	 */
	public function test_retry_sequence( int $retried, bool $should_retry ): void {
		$this->mock_http( [ $this->make_website() ], [ 'cloud_status' => 500 ] );

		$result = DailyBillingCron::run(
			[
				'billing_date' => '2026-08-19',
				'retried'      => $retried,
			]
		);

		$this->assertFalse( $result['pushed'] );
		$this->assertSame( 'push_failed', $result['reason'] );

		$next = $this->next_scheduled( DailyBillingCron::RETRY_HOOK );

		if ( $should_retry ) {
			$this->assertNotNull( $next, "已重試 {$retried} 次時應排程下次重試" );
			$this->assertEqualsWithDelta( time() + 30 * MINUTE_IN_SECONDS, $next, 60 );

			$actions = $this->pending_actions( DailyBillingCron::RETRY_HOOK );
			$args    = $actions[ array_key_first( $actions ) ]->get_args()[0];
			$this->assertSame( $retried + 1, $args['retried'], '重試次數應遞增' );
			$this->assertSame( '2026-08-19', $args['billing_date'], '重試須沿用同一個 billing_date' );
		} else {
			$this->assertNull( $next, '已達重試上限時不應再排程' );
		}
	}

	/**
	 * 重試序列（最多 3 次）
	 *
	 * @return array<string, array{0: int, 1: bool}>
	 */
	public function provide_retry_sequence(): array {
		return [
			'首次失敗，排第 1 次重試' => [ 0, true ],
			'排第 2 次重試'      => [ 1, true ],
			'排第 3 次重試'      => [ 2, true ],
			'已達上限不再重試'      => [ 3, false ],
		];
	}

	/**
	 * Rule: 重試次數達上限仍失敗時，寄信通知站台管理員並寫 error log
	 *
	 * @group error
	 */
	public function test_mails_admin_when_retry_exhausted(): void {
		$this->mock_http( [ $this->make_website() ], [ 'cloud_status' => 500 ] );

		DailyBillingCron::run(
			[
				'billing_date' => '2026-08-19',
				'retried'      => 3,
			]
		);

		$this->assertNotEmpty( $this->mails, '達重試上限應寄出通知信' );
		$admin_email = (string) \get_option( 'admin_email' );
		$to          = $this->mails[0]['to'] ?? '';
		$to_list     = is_array( $to ) ? $to : [ $to ];
		$this->assertContains( $admin_email, $to_list, '收件人應為站台 admin_email' );

		$this->assert_log( 'error' );
		$this->assertNull( $this->next_scheduled( DailyBillingCron::RETRY_HOOK ), '不應再排程重試' );
	}

	/**
	 * Rule: 網站的 dailyCost 缺值或非數值時，該站以 0 計並寫 warning log
	 *
	 * @dataProvider provide_invalid_daily_cost
	 * @group edge
	 *
	 * @param mixed $daily_cost 異常的 dailyCost 值（'__MISSING__' 表示欄位不存在）
	 */
	public function test_invalid_daily_cost_falls_back_to_zero( mixed $daily_cost ): void {
		$site_b = $this->make_website(
			[
				'primaryDomain' => 'b.wpsite.pro',
				'dailyCost'     => $daily_cost,
			]
		);
		if ( '__MISSING__' === $daily_cost ) {
			unset( $site_b['dailyCost'] );
		}

		$this->mock_http(
			[
				$this->make_website(
					[
						'primaryDomain' => 'a.wpsite.pro',
						'dailyCost'     => 10.5,
					]
				),
				$site_b,
			]
		);

		$result = DailyBillingCron::run();

		$payload = $this->push_payload();
		$this->assertNotNull( $payload );

		$by_domain = [];
		foreach ( $payload['sites'] as $site ) {
			$by_domain[ $site['domain'] ] = $site;
		}
		$this->assertEqualsWithDelta( 0.0, (float) $by_domain['b.wpsite.pro']['dailyCost'], 0.001 );
		$this->assertEqualsWithDelta( 10.5, $result['total_amount'], 0.001 );
		$this->assert_log( 'warning' );
	}

	/**
	 * dailyCost 異常值
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function provide_invalid_daily_cost(): array {
		return [
			'欄位不存在' => [ '__MISSING__' ],
			'null'   => [ null ],
			'非數值字串' => [ 'abc' ],
		];
	}

	/**
	 * Rule: API Key 一律不得以原文寫入 log
	 *
	 * @group error
	 */
	public function test_api_key_never_written_to_log_in_plain_text(): void {
		$this->mock_http( [ $this->make_website() ] );

		DailyBillingCron::run();

		$this->assertNotEmpty( $this->logs, '流程應至少寫出一筆 log' );

		$all = (string) \wp_json_encode( $this->logs );
		$this->assertStringNotContainsString( self::API_KEY, $all, 'log 不得含 API Key 原文' );
		$this->assertStringContainsString( 'len=', $all, 'log 應含遮罩格式 len=' );
		$this->assertStringContainsString( 'sha256:', $all, 'log 應含遮罩格式 sha256:' );
	}

	// ========================================================================
	// 邊界條件
	// ========================================================================

	/**
	 * Rule: 網站清單為空時仍需推送空 sites（不是跳過）
	 *
	 * 這條看似多餘的推送有明確理由，請勿「優化」掉：
	 * 接收端的新架構合計 user meta 只在成功扣點時更新，且刻意設計成 stale 時沿用舊值不歸零
	 *（歸零會讓大型經銷商掉回 7 天停用門檻而遭提前停用）。
	 * 若清單為空就不推送，「推送失敗」與「經銷商把站全部刪光」在接收端看起來完全一樣 ——
	 * meta 都沒被更新。後者會讓一個已經沒有任何新架構站的經銷商被永久認定為大型經銷商，
	 * 欠費時多拖 23 天才停用，而且不會自我修復（沒有站就永遠不推送）。
	 * 照常推送空 sites 之後，接收端會把 meta 更新為 0，兩種情況就能區分。
	 *
	 * @group edge
	 */
	public function test_pushes_empty_sites_when_list_empty_and_binding_known(): void {
		\update_option( DailyBillingCron::BOUND_CLOUD_USER_ID_OPTION, self::CLOUD_USER_ID );
		$this->mock_http( [] );

		$result = DailyBillingCron::run();

		$this->assertTrue( $result['pushed'], '清單為空仍須推送，否則接收端的合計 meta 會永久凍結在舊值' );
		$this->assertSame( 0, $result['billable_count'] );

		$payload = $this->push_payload();
		$this->assertNotNull( $payload );
		$this->assertSame( [], $payload['sites'] );
		$this->assertSame(
			self::CLOUD_USER_ID,
			$payload['cloud_user_id'],
			'清單為空時取不到 userId，cloud_user_id 改用本地綁定值'
		);
	}

	/**
	 * Rule: 清單為空且從未成功推送過時跳過本日推送
	 * 沒有本地綁定值就湊不出 cloud_user_id（接收端要求非空字串），
	 * 而且從未推送成功代表接收端也還沒有任何合計 meta 需要更新為 0
	 *
	 * @group edge
	 */
	public function test_skips_push_when_list_empty_and_no_local_binding(): void {
		$this->mock_http( [] );

		$result = DailyBillingCron::run();

		$this->assertFalse( $result['pushed'] );
		$this->assertSame( 'empty_list', $result['reason'] );
		$this->assert_not_pushed( '沒有 cloud_user_id 可用時不得送出 payload' );
		$this->assert_log( 'info' );
		$this->assertNull( $this->next_scheduled( DailyBillingCron::RETRY_HOOK ), '清單為空不是失敗，不應重試' );
	}

	/**
	 * Rule: 網站清單出現多個相異 userId 時視為異常，中止推送並寫告警 log
	 *
	 * @group edge
	 */
	public function test_aborts_when_multiple_distinct_user_ids(): void {
		$this->mock_http(
			[
				$this->make_website(
					[
						'primaryDomain' => 'a.wpsite.pro',
						'userId'        => 'cu-1111-aaaa',
					]
				),
				$this->make_website(
					[
						'primaryDomain' => 'b.wpsite.pro',
						'userId'        => 'cu-9999-zzzz',
						'dailyCost'     => 20.0,
					]
				),
			]
		);

		$result = DailyBillingCron::run();

		$this->assertFalse( $result['pushed'] );
		$this->assertSame( 'multiple_cloud_user_ids', $result['reason'] );
		$this->assert_not_pushed( '多個 userId 時不得靜默取第一筆推送' );
		$this->assert_log( 'error' );
	}

	/**
	 * Rule: 全部網站都不是 running 時仍需推送，且金額為 0
	 *
	 * @group edge
	 */
	public function test_pushes_zero_amount_when_no_running_sites(): void {
		$this->mock_http(
			[
				$this->make_website(
					[
						'primaryDomain' => 'a.wpsite.pro',
						'status'        => 'creating',
						'dailyCost'     => 10.5,
					]
				),
				$this->make_website(
					[
						'primaryDomain' => 'b.wpsite.pro',
						'status'        => 'stopped',
						'dailyCost'     => 20.0,
					]
				),
				$this->make_website(
					[
						'primaryDomain' => 'c.wpsite.pro',
						'status'        => 'deleting',
						'dailyCost'     => 0.25,
					]
				),
			]
		);

		$result = DailyBillingCron::run();

		$this->assertTrue( $result['pushed'], '全部非 running 仍應推送，維持每日對帳連續性' );
		$this->assertEqualsWithDelta( 0.0, $result['total_amount'], 0.001 );
		$payload = $this->push_payload();
		$this->assertNotNull( $payload );
		$this->assertSame( [], $payload['sites'] );
	}

	// ========================================================================
	// FetchPowerCloud::fetch_websites() 契約
	// ========================================================================

	/**
	 * fetch_websites 成功時回傳全量清單
	 *
	 * @group happy
	 */
	public function test_fetch_websites_returns_full_list(): void {
		$websites = [];
		for ( $i = 0; $i < 251; $i++ ) {
			$websites[] = $this->make_website( [ 'primaryDomain' => "site{$i}.wpsite.pro" ] );
		}
		$this->mock_http( $websites );

		$result = FetchPowerCloud::fetch_websites();

		$this->assertIsArray( $result );
		$this->assertCount( 251, $result );
		$this->assertCount( 2, $this->websites_requests() );
	}

	/**
	 * fetch_websites 失敗時回傳 null（與「清單為空」的空陣列區分）
	 *
	 * @group error
	 */
	public function test_fetch_websites_returns_null_on_http_error(): void {
		$this->mock_http( [ $this->make_website() ], [ 'page_errors' => [ 1 => 500 ] ] );

		$this->assertNull( FetchPowerCloud::fetch_websites(), 'HTTP 錯誤應回傳 null 而非空陣列' );
	}

	/**
	 * fetch_websites 在清單為空時回傳空陣列
	 *
	 * @group edge
	 */
	public function test_fetch_websites_returns_empty_array_when_no_sites(): void {
		$this->mock_http( [] );

		$this->assertSame( [], FetchPowerCloud::fetch_websites() );
	}

	/**
	 * fetch_websites 在 API Key 不存在時回傳 null 且不發出請求
	 *
	 * @group error
	 */
	public function test_fetch_websites_returns_null_without_api_key(): void {
		\delete_transient( Main::POWERCLOUD_API_KEY_TRANSIENT_KEY );
		$this->mock_http( [ $this->make_website() ] );

		$this->assertNull( FetchPowerCloud::fetch_websites() );
		$this->assertEmpty( $this->websites_requests(), '無 API Key 時不應發出請求' );
	}

	// ========================================================================
	// C1 身分綁定（TOFU）— 啟用即推、本地綁定值、綁定不符
	// ========================================================================

	/**
	 * Rule: 外掛啟用或版本升級後立刻排一次單次推送
	 * 接收端首次收到推送才建立 TOFU 身分綁定，等到隔日 05:00 會留下最長 24 小時的搶綁窗口
	 *
	 * @group smoke
	 * @group error
	 */
	public function test_schedules_bootstrap_push_on_version_change(): void {
		DailyBillingCron::instance()->maybe_schedule_bootstrap_push();

		$next = $this->next_scheduled( DailyBillingCron::RETRY_HOOK );
		$this->assertNotNull( $next, '啟用／升級後應立刻排一次首推，把搶綁窗口壓到約 1 分鐘' );
		$this->assertEqualsWithDelta( time() + DailyBillingCron::BOOTSTRAP_DELAY, $next, 30 );

		$actions = $this->pending_actions( DailyBillingCron::RETRY_HOOK );
		$args    = $actions[ array_key_first( $actions ) ]->get_args()[0];
		$this->assertSame( 0, $args['retried'], '首推是第 0 次，失敗仍應走既有重試流程' );
		$this->assertSame( DailyBillingCron::resolve_billing_date(), $args['billing_date'] );
	}

	/**
	 * Rule: 同一版本只排一次首推（避免每次 init 重複排程）
	 *
	 * @group edge
	 */
	public function test_bootstrap_push_scheduled_once_per_version(): void {
		DailyBillingCron::instance()->maybe_schedule_bootstrap_push();
		DailyBillingCron::instance()->maybe_schedule_bootstrap_push();
		DailyBillingCron::instance()->maybe_schedule_bootstrap_push();

		$this->assertCount(
			1,
			$this->pending_actions( DailyBillingCron::RETRY_HOOK ),
			'同一版本重複呼叫時首推排程數量仍應為 1'
		);
	}

	/**
	 * Rule: 首次推送成功後把 cloud_user_id 存為本地綁定值
	 *
	 * @group happy
	 */
	public function test_records_local_binding_after_first_successful_push(): void {
		$this->mock_http( [ $this->make_website() ] );

		$result = DailyBillingCron::run();

		$this->assertTrue( $result['pushed'] );
		$this->assertSame(
			self::CLOUD_USER_ID,
			\get_option( DailyBillingCron::BOUND_CLOUD_USER_ID_OPTION ),
			'首推成功後應記下本次使用的 cloud_user_id'
		);
	}

	/**
	 * Rule: 本次解析出的 cloud_user_id 與本地綁定值不符時中止推送並通知管理員
	 * 代表 PowerCloud 帳號被換或本地狀態異常，不可自動改推新值
	 *
	 * @group error
	 */
	public function test_aborts_when_cloud_user_id_differs_from_local_binding(): void {
		\update_option( DailyBillingCron::BOUND_CLOUD_USER_ID_OPTION, 'cu-0000-old' );
		$this->mock_http( [ $this->make_website() ] );

		$result = DailyBillingCron::run();

		$this->assertFalse( $result['pushed'] );
		$this->assertSame( 'cloud_user_id_changed', $result['reason'] );
		$this->assert_not_pushed( '本地綁定值不符時不得推送' );
		$this->assert_log( 'error' );
		$this->assertNotEmpty( $this->mails, '綁定值變動須立即通知管理員' );
		$this->assertNull( $this->next_scheduled( DailyBillingCron::RETRY_HOOK ), '非暫時性錯誤，不應重試' );
		$this->assertSame(
			'cu-0000-old',
			\get_option( DailyBillingCron::BOUND_CLOUD_USER_ID_OPTION ),
			'不得以新值覆寫既有綁定'
		);
	}

	/**
	 * Rule: 接收端回 403 且訊息表示身分綁定不符時，立即通知管理員、不進入一般重試
	 *
	 * @group error
	 */
	public function test_identity_mismatch_response_notifies_admin_without_retry(): void {
		$this->mock_http(
			[ $this->make_website() ],
			[
				'cloud_status' => 403,
				'cloud_body'   => [
					'status'  => 403,
					'message' => 'cloud_user_id 與已綁定值不符',
					'data'    => [],
				],
			]
		);

		$result = DailyBillingCron::run();

		$this->assertFalse( $result['pushed'] );
		$this->assertSame( 'identity_mismatch', $result['reason'] );
		$this->assertNotEmpty( $this->mails, '身分綁定不符可能代表綁定被搶，須立即通知' );
		$this->assertNull(
			$this->next_scheduled( DailyBillingCron::RETRY_HOOK ),
			'綁定不符重試三次也不會成功，不得當成一般 push_failed'
		);
		$this->assert_log( 'error' );
	}

	// ========================================================================
	// H1 /websites 回應原文不得落地 log（回應含站台憑證）
	// ========================================================================

	/**
	 * Rule: /websites 回應格式異常時只記可診斷指紋，不記 body 原文
	 * 該回應帶 adminPassword / databaseRootPassword 等憑證，原文落地 wc-logs 等同外洩
	 *
	 * @group error
	 */
	public function test_websites_response_body_never_logged_when_envelope_changes(): void {
		$secret = 'admin-SUPER-SECRET-pw';
		$this->mock_http(
			[],
			[
				'websites_body' => [
					[
						'id'                   => 'ws-1',
						'primaryDomain'        => 'a.wpsite.pro',
						'status'               => 'running',
						'adminEmail'           => 'a@example.com',
						'adminPassword'        => $secret,
						'databaseRootPassword' => 'root-' . $secret,
					],
				],
			]
		);

		$this->assertNull( FetchPowerCloud::fetch_websites(), 'envelope 改版應視為格式異常回傳 null' );

		$all = (string) \wp_json_encode( $this->logs );
		$this->assertStringNotContainsString( $secret, $all, 'log 不得含站台憑證原文' );
		$this->assert_log( 'error', '回應格式異常' );
		$this->assertStringContainsString( 'body_length', $all, 'log 應保留 body 長度供診斷' );
		$this->assertStringContainsString( 'body_keys', $all, 'log 應保留頂層鍵名供診斷' );
		$this->assertStringContainsString( 'list:1', $all, '裸陣列 envelope 應可從鍵名指紋看出' );
	}

	/**
	 * Rule: /websites 回 HTTP 非 2xx 時同樣只記指紋，不記 body 原文
	 *
	 * @group error
	 */
	public function test_websites_http_error_body_never_logged(): void {
		$secret = 'admin-SUPER-SECRET-pw';
		$this->mock_http(
			[ $this->make_website() ],
			[
				'page_errors' => [ 1 => 500 ],
				'error_body'  => [ 'adminPassword' => $secret ],
			]
		);

		$this->assertNull( FetchPowerCloud::fetch_websites() );

		$all = (string) \wp_json_encode( $this->logs );
		$this->assertStringNotContainsString( $secret, $all, 'log 不得含回應 body 原文' );
		$this->assert_log( 'error', 'http error' );
		$this->assertStringContainsString( 'body_length', $all );
	}

	// ========================================================================
	// H2 清單非空但沒有任何可計費網站 → 視為狀態字典改版的異常
	// ========================================================================

	/**
	 * Rule: 網站清單非空卻篩不出任何 running 網站時，提升為 error log + 通知管理員，
	 * 並把本次出現過的相異 status 值寫進 log（狀態字典改版才看得見）
	 *
	 * 仍照既有規格推送（讓接收端寫下當日冪等鍵，維持對帳連續性）
	 *
	 * @group error
	 */
	public function test_alerts_when_list_not_empty_but_no_billable_site(): void {
		$this->mock_http(
			[
				$this->make_website(
					[
						'primaryDomain' => 'a.wpsite.pro',
						'status'        => 'Running',
					]
				),
				$this->make_website(
					[
						'primaryDomain' => 'b.wpsite.pro',
						'status'        => 'restarting',
					]
				),
			]
		);

		$result = DailyBillingCron::run();

		$this->assertTrue( $result['pushed'], '既有規格：全部非 running 仍推送' );
		$this->assertSame( 0, $result['billable_count'] );
		$this->assert_log( 'error', '沒有任何可計費網站' );

		$all = (string) \wp_json_encode( $this->logs );
		$this->assertStringContainsString( 'Running', $all, 'log 應列出本次出現過的相異 status' );
		$this->assertStringContainsString( 'restarting', $all );
		$this->assertNotEmpty( $this->mails, '狀態字典疑似改版須通知管理員' );
	}

	/**
	 * Rule 反面: 清單中有可計費網站時不得誤報
	 *
	 * @group happy
	 */
	public function test_does_not_alert_when_at_least_one_billable_site(): void {
		$this->mock_http(
			[
				$this->make_website(
					[
						'primaryDomain' => 'a.wpsite.pro',
						'status'        => 'running',
					]
				),
				$this->make_website(
					[
						'primaryDomain' => 'b.wpsite.pro',
						'status'        => 'stopped',
					]
				),
			]
		);

		DailyBillingCron::run();

		$this->assertEmpty( $this->mails, '有可計費網站時不應通知管理員' );
	}

	// ========================================================================
	// H3 設定類中止路徑一律通知管理員
	// ========================================================================

	/**
	 * Rule: 四條設定類中止路徑都必須通知管理員，不可只寫 log 靜默 return
	 *
	 * @dataProvider provide_config_abort
	 * @group error
	 *
	 * @param string $scenario 情境
	 * @param string $reason   期望的中止原因
	 * @param string $needle   通知信中應出現的關鍵字
	 */
	public function test_config_abort_notifies_admin( string $scenario, string $reason, string $needle ): void {
		$websites = [ $this->make_website() ];

		switch ( $scenario ) {
			case 'no_partner_id':
				\delete_option( Connect::PARTNER_ID_OPTION_NAME );
				break;
			case 'no_api_key':
				\delete_transient( Main::POWERCLOUD_API_KEY_TRANSIENT_KEY );
				break;
			case 'no_cloud_user_id':
				$site = $this->make_website();
				unset( $site['userId'] );
				$websites = [ $site ];
				break;
			case 'multiple_cloud_user_ids':
				$websites = [
					$this->make_website( [ 'userId' => 'cu-1111-aaaa' ] ),
					$this->make_website(
						[
							'primaryDomain' => 'b.wpsite.pro',
							'userId'        => 'cu-9999-zzzz',
						]
					),
				];
				break;
		}

		$this->mock_http( $websites );

		$result = DailyBillingCron::run();

		$this->assertFalse( $result['pushed'] );
		$this->assertSame( $reason, $result['reason'] );
		$this->assertNotEmpty( $this->mails, "中止原因 {$reason} 須通知管理員" );

		$admin_email = (string) \get_option( 'admin_email' );
		$to          = $this->mails[0]['to'] ?? '';
		$to_list     = is_array( $to ) ? $to : [ $to ];
		$this->assertContains( $admin_email, $to_list, '收件人應為站台 admin_email' );

		$this->assertStringContainsString(
			$needle,
			(string) ( $this->mails[0]['message'] ?? '' ),
			"通知信應說明 {$reason} 的處置方式"
		);
	}

	/**
	 * 四條設定類中止路徑
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function provide_config_abort(): array {
		return [
			'partner_id 未設定'      => [ 'no_partner_id', 'no_partner_id', 'partner_id' ],
			'API Key 不存在'         => [ 'no_api_key', 'no_api_key', '新架構權限' ],
			'取不到 cloud_user_id'   => [ 'no_cloud_user_id', 'no_cloud_user_id', 'cloud_user_id' ],
			'多個相異 cloud_user_id' => [ 'multiple_cloud_user_ids', 'multiple_cloud_user_ids', '權限' ],
		];
	}

	// ========================================================================
	// M1 billing_date 綁定排程 slot，不由執行當下推導
	// ========================================================================

	/**
	 * Rule: 定期排程的 billing_date 取自「最近一次 21:00 UTC 排程時刻」，
	 * 執行延遲跨過 UTC+8 午夜時不得漂到隔天（否則該業務日永遠收不到錢）
	 *
	 * @group edge
	 */
	public function test_billing_date_snaps_to_latest_schedule_slot(): void {
		// 準時觸發：2026-08-19 21:00 UTC slot = UTC+8 2026-08-20 05:00
		$this->assertSame(
			'2026-08-20',
			DailyBillingCron::resolve_billing_date( (int) strtotime( '2026-08-19 21:00:00 UTC' ) )
		);
		// slot 前一秒仍屬上一個業務日
		$this->assertSame(
			'2026-08-19',
			DailyBillingCron::resolve_billing_date( (int) strtotime( '2026-08-19 20:59:59 UTC' ) )
		);
		// 延遲 19.5 小時（UTC+8 2026-08-21 00:30）仍須歸屬原本的 2026-08-20
		$this->assertSame(
			'2026-08-20',
			DailyBillingCron::resolve_billing_date( (int) strtotime( '2026-08-20 16:30:00 UTC' ) )
		);
		// 下一個 slot 才推進到 2026-08-21
		$this->assertSame(
			'2026-08-21',
			DailyBillingCron::resolve_billing_date( (int) strtotime( '2026-08-20 21:00:00 UTC' ) )
		);
	}

	// ========================================================================
	// M2 total 只認第 1 頁；M3 分頁去重
	// ========================================================================

	/**
	 * Rule: total 只從第 1 頁取，後續頁缺 total 不得讓清單靜默截斷
	 *
	 * 對端「只在第 1 頁給 total」是很常見的實作。舊寫法在迴圈內每頁重讀 total，
	 * 第 2 頁 fallback 成 count($page_data) = 250 → 累計 500 >= 250 → break，
	 * 1000 站只送 500 站、函式回報成功、沒有任何 error log。少送站 = 少收錢。
	 *
	 * @group error
	 */
	public function test_fetch_websites_takes_total_from_first_page_only(): void {
		$websites = [];
		for ( $i = 0; $i < 1000; $i++ ) {
			$websites[] = $this->make_website(
				[
					'id'            => "ws-{$i}",
					'primaryDomain' => "site{$i}.wpsite.pro",
				]
			);
		}

		$pages = [];
		for ( $page = 1; $page <= 4; $page++ ) {
			$body = [ 'data' => array_slice( $websites, ( $page - 1 ) * 250, 250 ) ];
			// 只有第 1 頁帶 total
			if ( 1 === $page ) {
				$body['total'] = 1000;
			}
			$pages[ $page ] = $body;
		}

		$this->mock_http( [], [ 'page_responses' => $pages ] );

		$result = FetchPowerCloud::fetch_websites();

		$this->assertIsArray( $result );
		$this->assertCount( 1000, $result, '後續頁沒有 total 不得截斷清單' );
		$this->assertCount( 4, $this->websites_requests() );
	}

	/**
	 * Rule: 第 1 頁缺 total 時視為取得失敗，不得只送第一頁
	 *
	 * @group error
	 */
	public function test_fetch_websites_returns_null_when_first_page_has_no_total(): void {
		$this->mock_http(
			[],
			[
				'page_responses' => [
					1 => [ 'data' => [ $this->make_website() ] ],
				],
			]
		);

		$this->assertNull( FetchPowerCloud::fetch_websites(), '缺 total 無法確認是否取完，須視為失敗' );
		$this->assert_log( 'error', 'total' );
	}

	/**
	 * Rule: 分頁結果須以 id 去重；某頁沒有帶來任何新資料即判定分頁停滯
	 *
	 * 這是唯一會「向客戶多收錢」的失敗模式：對端若因改版／快取層／WAF 剝掉 query string
	 * 而忽略 page 參數，每頁都回同一批 250 筆，舊寫法只看累計筆數，
	 * total = 1000 會跑 4 圈把同一批站累加 4 次 → payload 把同 250 個 domain 各送 4 次
	 * → 接收端逐筆加總 → 該經銷商被多扣 4 倍。
	 *
	 * @group error
	 */
	public function test_fetch_websites_returns_null_when_pagination_repeats_same_data(): void {
		$page_one = [];
		for ( $i = 0; $i < 250; $i++ ) {
			$page_one[] = $this->make_website(
				[
					'id'            => "ws-{$i}",
					'primaryDomain' => "site{$i}.wpsite.pro",
				]
			);
		}

		// 對端忽略 page 參數：每一頁都回同一批資料
		$this->mock_http(
			[],
			[
				'page_responses' => [
					1 => [
						'data'  => $page_one,
						'total' => 1000,
					],
					2 => [ 'data' => $page_one ],
					3 => [ 'data' => $page_one ],
					4 => [ 'data' => $page_one ],
				],
			]
		);

		$this->assertNull(
			FetchPowerCloud::fetch_websites(),
			'重複資料不得累加成 1000 筆送出，否則客戶會被多扣 4 倍'
		);
		$this->assert_log( 'error', '分頁停滯' );
	}

	/**
	 * Rule: 分頁區間重疊時以 id 去重，不重複計費
	 *
	 * @group edge
	 */
	public function test_fetch_websites_dedupes_overlapping_pages(): void {
		$all = [];
		for ( $i = 0; $i < 450; $i++ ) {
			$all[] = $this->make_website(
				[
					'id'            => "ws-{$i}",
					'primaryDomain' => "site{$i}.wpsite.pro",
				]
			);
		}

		// 第 2 頁與第 1 頁重疊 50 筆（0-249 / 200-449）
		$this->mock_http(
			[],
			[
				'page_responses' => [
					1 => [
						'data'  => array_slice( $all, 0, 250 ),
						'total' => 450,
					],
					2 => [ 'data' => array_slice( $all, 200, 250 ) ],
				],
			]
		);

		$result = FetchPowerCloud::fetch_websites();

		$this->assertIsArray( $result );
		$this->assertCount( 450, $result, '重疊的 50 筆不得重複計入' );

		$ids = array_column( $result, 'id' );
		$this->assertSame( count( $ids ), count( array_unique( $ids ) ), '清單不得含重複 id' );
	}

	// ========================================================================
	// M4 多租戶守衛：可計費網站必須解析得出 owner
	// ========================================================================

	/**
	 * Rule: 可計費（running）網站中出現解析不出 user id 的站時中止推送
	 *
	 * collect_cloud_user_ids() 對取不到 id 的站一律略過，build_sites() 又完全不看 userId，
	 * 因此「API key 權限範圍意外放大、且多出來的站 userId 與 user 皆為 null」時，
	 * 相異 id 集合仍只有一個 → 多租戶守衛不觸發 → 不屬於本經銷商的站被算進 payload
	 * 並以本經銷商的身分推送，接收端只驗 TOFU 綁定值（相符）照扣。
	 *
	 * @group error
	 */
	public function test_aborts_when_billable_site_has_no_resolvable_owner(): void {
		$orphan = $this->make_website( [ 'primaryDomain' => 'b.wpsite.pro' ] );
		unset( $orphan['userId'] );
		$orphan['user'] = null;

		$this->mock_http(
			[
				$this->make_website( [ 'primaryDomain' => 'a.wpsite.pro' ] ),
				$orphan,
			]
		);

		$result = DailyBillingCron::run();

		$this->assertFalse( $result['pushed'] );
		$this->assertSame( 'billable_site_without_owner', $result['reason'] );
		$this->assert_not_pushed( '沒有 owner 的可計費網站不得靜默納入 payload' );
		$this->assert_log( 'error' );
		$this->assertNotEmpty( $this->mails, '權限範圍疑似放大須通知管理員' );
	}

	/**
	 * Rule 邊界: 非 running 的站沒有 userId 不影響推送（它本來就不進 payload）
	 *
	 * @group edge
	 */
	public function test_does_not_abort_when_only_non_billable_site_has_no_owner(): void {
		$orphan = $this->make_website(
			[
				'primaryDomain' => 'b.wpsite.pro',
				'status'        => 'stopped',
			]
		);
		unset( $orphan['userId'] );

		$this->mock_http(
			[
				$this->make_website( [ 'primaryDomain' => 'a.wpsite.pro' ] ),
				$orphan,
			]
		);

		$result = DailyBillingCron::run();

		$this->assertTrue( $result['pushed'], '非計費對象缺 userId 不應中止推送' );
		$this->assertSame( 1, $result['billable_count'] );
	}

	/**
	 * Rule 反向: 既有規格「不以 cloud_user_id 二次過濾清單」不得被本守衛改寫
	 *
	 * @group edge
	 */
	public function test_owner_guard_does_not_filter_the_list(): void {
		$this->mock_http(
			[
				$this->make_website( [ 'primaryDomain' => 'a.wpsite.pro' ] ),
				$this->make_website( [ 'primaryDomain' => 'b.wpsite.pro' ] ),
			]
		);

		DailyBillingCron::run();

		$payload = $this->push_payload();
		$this->assertNotNull( $payload );
		$this->assertCount( 2, $payload['sites'], '守衛只做「中止或放行」，不得過濾清單' );
	}

	/**
	 * Rule: 實際執行時間與排程 slot 的落差超過門檻時寫 error log
	 *
	 * @group edge
	 */
	public function test_schedule_drift_is_measured_against_slot(): void {
		$this->assertSame(
			0,
			DailyBillingCron::schedule_drift_seconds( (int) strtotime( '2026-08-19 21:00:00 UTC' ) )
		);
		$this->assertSame(
			1800,
			DailyBillingCron::schedule_drift_seconds( (int) strtotime( '2026-08-19 21:30:00 UTC' ) )
		);
		$this->assertSame(
			19 * HOUR_IN_SECONDS + 1800,
			DailyBillingCron::schedule_drift_seconds( (int) strtotime( '2026-08-20 16:30:00 UTC' ) )
		);
		$this->assertGreaterThan(
			DailyBillingCron::MAX_DRIFT_SECONDS,
			DailyBillingCron::schedule_drift_seconds( (int) strtotime( '2026-08-20 16:30:00 UTC' ) ),
			'19.5 小時漂移應超過告警門檻'
		);
	}
}
