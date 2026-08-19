<?php
/**
 * 新架構（PowerCloud）每日計費推送 整合測試
 *
 * 驗收標準：specs/features/billing/推送新架構網站計費資料.feature
 * 流程圖：  specs/activities/新架構每日計費推送流程.activity
 *
 * 覆蓋的 Rule：
 *   前置（參數）- 重試時沿用首次觸發的 billing_date
 *   前置（狀態）- 每日 UTC+8 05:00（21:00 UTC）觸發、同時只允許一個排程
 *   前置（狀態）- API Key / partner_id 缺漏時中止推送
 *   後置（狀態）- 分頁拉完全量、不做二次過濾、只計 running、cloud_user_id、billing_date、
 *                domain 優先序、Basic Auth
 *   後置（事件）- 成功寫 info log（含計費站數與總金額）
 *   錯誤處理    - 無 cloud_user_id / 清單取得失敗 / 推送重試 3 次 / 重試上限寄信 /
 *                dailyCost 異常值 / API Key 不落地 log
 *   邊界條件    - 清單為空跳過、多個相異 userId 中止、全部非 running 仍推送金額 0
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
	 *                                                       cloud_status（int|WP_Error）
	 */
	private function mock_http( array $websites, array $opts = [] ): void {
		$total        = $opts['total'] ?? count( $websites );
		$page_errors  = $opts['page_errors'] ?? [];
		$cloud_status = $opts['cloud_status'] ?? 200;

		$this->http_mock = function ( $pre, $args, $url ) use ( $websites, $total, $page_errors, $cloud_status ) {
			$this->requests[] = [
				'url'  => (string) $url,
				'args' => is_array( $args ) ? $args : [],
			];

			if ( str_contains( (string) $url, 'powercloud-daily-billing' ) ) {
				if ( $cloud_status instanceof \WP_Error ) {
					return $cloud_status;
				}
				return $this->http_response( (int) $cloud_status, [ 'status' => 200 ] );
			}

			if ( str_contains( (string) $url, '/websites' ) ) {
				$query = [];
				parse_str( (string) \wp_parse_url( (string) $url, PHP_URL_QUERY ), $query );
				$page  = (int) ( $query['page'] ?? 1 );
				$limit = (int) ( $query['limit'] ?? 250 );

				if ( isset( $page_errors[ $page ] ) ) {
					$err = $page_errors[ $page ];
					return $err instanceof \WP_Error ? $err : $this->http_response( (int) $err, [ 'message' => 'error' ] );
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
	 * Rule: 排程於每日 UTC+8 05:00（21:00 UTC）觸發、間隔 1 天
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
		$this->assertSame( DAY_IN_SECONDS, (int) $schedule->get_recurrence(), '間隔應為 1 天' );
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
	 * Rule: 網站清單為空時跳過本日推送，不送出空 payload
	 *
	 * @group edge
	 */
	public function test_skips_push_when_website_list_empty(): void {
		$this->mock_http( [] );

		$result = DailyBillingCron::run();

		$this->assertFalse( $result['pushed'] );
		$this->assertSame( 'empty_list', $result['reason'] );
		$this->assert_not_pushed( '清單為空時不得送出空 payload' );
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
}
