<?php

declare(strict_types=1);

namespace J7\PowerPartner\Domains\Billing\Core;

use J7\PowerPartner\Api\Connect;
use J7\PowerPartner\Api\FetchPowerCloud;
use J7\PowerPartner\Domains\Billing\Services\BillingPushClient;
use J7\PowerPartner\Plugin;

/**
 * 新架構（PowerCloud）每日計費推送排程
 *
 * 每日 UTC+8 05:00（= 21:00 UTC，WP 已將 PHP 時區設為 UTC）向 PowerCloud 取得本經銷商
 * 帳號下的網站全量清單，過濾出計費對象後推送給 CloudServer 由其扣點。
 *
 * 驗收標準：specs/features/billing/推送新架構網站計費資料.feature
 *
 * 排程寫法刻意採 singleton + as_next_scheduled_action() 守衛 + as_schedule_recurring_action()，
 * 而非 Powerhouse\Domains\AsSchedulerHandler\Shared\Base —— 後者的 constructor 是 item-scoped，
 * 套用在「站台層級每日推送」這種沒有自然 item 的場景並不合適。
 */
final class DailyBillingCron {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string 每日定期推送的 hook（無參數） */
	const CRON_HOOK = 'power_partner/3.4.0/billing/daily-push';

	/**
	 * 重試用的 hook（帶 billing_date / retried 參數）
	 *
	 * 刻意與 CRON_HOOK 分離：兩者共用 hook 會讓 as_next_scheduled_action() 的
	 * 「已存在排程」守衛把 pending 的重試誤判成定期排程，導致定期排程漏註冊。
	 *
	 * @var string
	 */
	const RETRY_HOOK = 'power_partner/3.4.0/billing/daily-push-retry';

	/** @var int UTC+8 與 UTC 的秒差 */
	const UTC8_OFFSET = 28800; // 8 * HOUR_IN_SECONDS

	/** @var int 每日觸發時刻（UTC 秒數，21:00 UTC = UTC+8 05:00） */
	const SCHEDULE_SECONDS_UTC = 75600; // 21 * HOUR_IN_SECONDS

	/** @var int 最多重試次數 */
	const MAX_RETRY = 3;

	/** @var int 重試間隔（秒） */
	const RETRY_INTERVAL = 1800; // 30 * MINUTE_IN_SECONDS

	/** @var string 唯一列入計費的網站狀態 */
	const BILLABLE_STATUS = 'running';

	/** @var array<int, string> domain 取值優先序（沿用既有前端慣例） */
	const DOMAIN_KEYS = [ 'primaryDomain', 'domain', 'subDomain', 'wildcardDomain' ];

	/** Constructor */
	public function __construct() {
		\add_action( 'init', [ $this, 'register_daily_action_scheduler' ] );
		\add_action( self::CRON_HOOK, [ __CLASS__, 'action_callback' ], 10, 1 );
		\add_action( self::RETRY_HOOK, [ __CLASS__, 'action_callback' ], 10, 1 );
	}

	/**
	 * 註冊每日定期排程（已存在時不重複註冊）
	 *
	 * @return void
	 */
	public function register_daily_action_scheduler(): void {
		if ( ! \function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		if ( \as_next_scheduled_action( self::CRON_HOOK ) ) {
			return;
		}

		\as_schedule_recurring_action( self::next_schedule_timestamp(), DAY_IN_SECONDS, self::CRON_HOOK );
	}

	/**
	 * ActionScheduler callback
	 *
	 * 定期排程不帶參數（callback 收到 0 個參數 → 使用預設值）；
	 * 重試排程帶 [ 'billing_date' => string, 'retried' => int ]。
	 *
	 * @param mixed $args 排程參數
	 * @return void
	 */
	public static function action_callback( mixed $args = [] ): void {
		self::run( is_array( $args ) ? $args : [] );
	}

	/**
	 * 執行一次計費推送
	 *
	 * $args 來自 ActionScheduler，內容不可信任（可能是舊版本排程留下的格式），
	 * 故型別保持寬鬆並於函式內逐項驗證。認得的鍵為 billing_date（string）與 retried（int）。
	 *
	 * @param array<string, mixed> $args 排程參數
	 * @return array{pushed: bool, reason: string, billing_date: string, billable_count: int, total_amount: float}
	 */
	public static function run( array $args = [] ): array {
		// billing_date 於首次觸發時決定，重試沿用同一個值 —— 它是接收端的冪等鍵，
		// 重試時漂移會導致同一天扣兩次、隔天不扣
		$billing_date = self::get_billing_date();
		if ( isset( $args['billing_date'] ) && is_string( $args['billing_date'] ) && '' !== $args['billing_date'] ) {
			$billing_date = $args['billing_date'];
		}
		$retried = isset( $args['retried'] ) ? (int) $args['retried'] : 0;

		// 前置：partner_id —— 接收端靠它辨識扣點對象，缺少時推送必然失敗，不送出。
		// 非數字亦視為未設定：契約要求 int，直接 cast 會送出 partner_id: 0 而扣到錯的人頭上
		$partner_id = \get_option( Connect::PARTNER_ID_OPTION_NAME );
		if ( empty( $partner_id ) || ! is_numeric( $partner_id ) ) {
			Plugin::logger(
				'新架構每日計費推送中止：partner_id 未設定或非數字',
				'error',
				[
					'billing_date' => $billing_date,
					'partner_id'   => is_scalar( $partner_id ) ? $partner_id : \wp_json_encode( $partner_id ),
				]
			);
			return self::result( false, 'no_partner_id', $billing_date );
		}

		// 前置：PowerCloud API Key —— 不得以空 key 呼叫 API，避免 401 被誤判為「該經銷商沒有站」
		$api_key = FetchPowerCloud::get_powercloud_api_key( (string) \get_current_user_id() );
		if ( empty( $api_key ) ) {
			Plugin::logger(
				'新架構每日計費推送中止：PowerCloud API Key 不存在',
				'error',
				[ 'billing_date' => $billing_date ]
			);
			return self::result( false, 'no_api_key', $billing_date );
		}

		// 取得網站全量清單。null = 取得失敗，不可退化為空陣列送出殘缺 payload
		$websites = FetchPowerCloud::fetch_websites();
		if ( null === $websites ) {
			Plugin::logger(
				'新架構每日計費推送中止：取得 PowerCloud 網站清單失敗，不送出不完整的 payload',
				'error',
				[
					'billing_date' => $billing_date,
					'retried'      => $retried,
				]
			);
			self::schedule_retry( $billing_date, $retried, 'fetch_failed' );
			return self::result( false, 'fetch_failed', $billing_date );
		}

		// 邊界：清單完全為空 → 跳過本日推送（不是失敗，不重試）
		if ( ! $websites ) {
			Plugin::logger(
				'新架構每日計費推送跳過：PowerCloud 網站清單為空，不送出空 payload',
				'info',
				[ 'billing_date' => $billing_date ]
			);
			return self::result( false, 'empty_list', $billing_date );
		}

		$cloud_user_ids = self::collect_cloud_user_ids( $websites );

		// cloud_user_id 是接收端 TOFU 身分綁定的依據，取不到就必定被拒絕扣點，不推送
		if ( ! $cloud_user_ids ) {
			Plugin::logger(
				'新架構每日計費推送中止：網站清單取不到 cloud_user_id',
				'error',
				[
					'billing_date'  => $billing_date,
					'website_count' => count( $websites ),
				]
			);
			return self::result( false, 'no_cloud_user_id', $billing_date );
		}

		// 同一把 API key 底下所有站應屬同一個 userId。出現多個代表該 key 權限範圍超出預期，
		// 推送會把不屬於本經銷商的站算到他頭上，必須中止而非靜默取第一筆
		if ( count( $cloud_user_ids ) > 1 ) {
			Plugin::logger(
				'新架構每日計費推送中止：網站清單出現多個相異 userId，API key 權限範圍異常',
				'error',
				[
					'billing_date'   => $billing_date,
					'cloud_user_ids' => $cloud_user_ids,
				]
			);
			return self::result( false, 'multiple_cloud_user_ids', $billing_date );
		}

		$sites        = self::build_sites( $websites );
		$total_amount = round( (float) array_sum( array_column( $sites, 'dailyCost' ) ), 2 );

		$payload = [
			'partner_id'    => (int) $partner_id,
			'cloud_user_id' => $cloud_user_ids[0],
			'billing_date'  => $billing_date,
			'sites'         => $sites,
		];

		if ( ! BillingPushClient::push( $payload ) ) {
			Plugin::logger(
				'新架構每日計費推送失敗',
				'error',
				[
					'billing_date'   => $billing_date,
					'retried'        => $retried,
					'billable_count' => count( $sites ),
					'total_amount'   => $total_amount,
				]
			);
			self::schedule_retry( $billing_date, $retried, 'push_failed' );
			return self::result( false, 'push_failed', $billing_date, count( $sites ), $total_amount );
		}

		Plugin::logger(
			sprintf(
				'新架構每日計費推送成功，billing_date: %1$s，計費站數: %2$d，總金額: %3$s',
				$billing_date,
				count( $sites ),
				number_format( $total_amount, 2, '.', '' )
			),
			'info',
			[
				'billing_date'   => $billing_date,
				'cloud_user_id'  => $cloud_user_ids[0],
				'website_count'  => count( $websites ),
				'billable_count' => count( $sites ),
				'total_amount'   => $total_amount,
			]
		);

		return self::result( true, 'ok', $billing_date, count( $sites ), $total_amount );
	}

	/**
	 * 取得業務日期（推送當下的 UTC+8 日期，YYYY-MM-DD）
	 *
	 * 刻意用 gmdate + 固定 8 小時位移，不受站台時區設定影響 ——
	 * 接收端以此為冪等鍵，口徑必須是 UTC+8 而非站台時區。
	 *
	 * @param int|null $timestamp UNIX timestamp（預設為現在）
	 * @return string
	 */
	public static function get_billing_date( ?int $timestamp = null ): string {
		$timestamp = $timestamp ?? time();
		return gmdate( 'Y-m-d', $timestamp + self::UTC8_OFFSET );
	}

	/**
	 * 取得下一次 21:00 UTC（= UTC+8 05:00）的時間戳
	 *
	 * @param int|null $now 現在時間（預設為現在）
	 * @return int
	 */
	private static function next_schedule_timestamp( ?int $now = null ): int {
		$now       = $now ?? time();
		$today_utc = (int) strtotime( gmdate( 'Y-m-d', $now ) . ' 00:00:00 UTC' );
		$scheduled = $today_utc + self::SCHEDULE_SECONDS_UTC;

		if ( $scheduled <= $now ) {
			$scheduled += DAY_IN_SECONDS;
		}

		return $scheduled;
	}

	/**
	 * 排程 30 分鐘後重試；已達上限則寄信通知站台管理員並寫 error log
	 *
	 * 3 次 × 30 分鐘最晚於 UTC+8 06:30 完成，仍在同一業務日內，不會污染接收端的冪等鍵。
	 *
	 * @param string $billing_date 業務日期（重試時不變）
	 * @param int    $retried      已重試次數
	 * @param string $reason       失敗原因
	 * @return void
	 */
	private static function schedule_retry( string $billing_date, int $retried, string $reason ): void {
		if ( $retried >= self::MAX_RETRY ) {
			self::notify_admin( $billing_date, $reason, $retried );
			return;
		}

		if ( ! \function_exists( 'as_schedule_single_action' ) ) {
			Plugin::logger( '無法排程計費推送重試：ActionScheduler 不可用', 'error', [ 'billing_date' => $billing_date ] );
			return;
		}

		$next_retry = $retried + 1;
		$timestamp  = time() + self::RETRY_INTERVAL;

		\as_schedule_single_action(
			$timestamp,
			self::RETRY_HOOK,
			[
				[
					'billing_date' => $billing_date,
					'retried'      => $next_retry,
				],
			]
		);

		Plugin::logger(
			sprintf(
				'新架構每日計費推送失敗（%1$s），已排程第 %2$d 次重試於 %3$s UTC',
				$reason,
				$next_retry,
				gmdate( 'Y-m-d H:i:s', $timestamp )
			),
			'warning',
			[
				'billing_date' => $billing_date,
				'retried'      => $retried,
				'reason'       => $reason,
			]
		);
	}

	/**
	 * 重試上限仍失敗：寄信通知站台管理員 + error log
	 *
	 * 漏推一天等於少收一天錢，必須有人看得見。
	 *
	 * @param string $billing_date 業務日期
	 * @param string $reason       失敗原因
	 * @param int    $retried      已重試次數
	 * @return void
	 */
	private static function notify_admin( string $billing_date, string $reason, int $retried ): void {
		$admin_email = (string) \get_option( 'admin_email' );
		$site_name   = (string) \get_bloginfo( 'name' );

		$subject = "【Power Partner】新架構網站計費資料推送失敗（{$billing_date}）";
		$message = sprintf(
			'<p>站台：%1$s</p><p>業務日期：%2$s</p><p>失敗原因：%3$s</p><p>已重試 %4$d 次仍失敗，本日新架構（PowerCloud）網站的計費資料未送達 cloud.luke.cafe，請盡快檢查。</p>',
			\esc_html( $site_name ),
			\esc_html( $billing_date ),
			\esc_html( $reason ),
			$retried
		);

		\wp_mail( $admin_email, $subject, $message, [ 'Content-Type: text/html; charset=UTF-8' ] );

		Plugin::logger(
			sprintf( '新架構每日計費推送已達重試上限（%1$d 次）仍失敗，已寄信通知 %2$s', $retried, $admin_email ),
			'error',
			[
				'billing_date' => $billing_date,
				'reason'       => $reason,
				'retried'      => $retried,
			]
		);
	}

	/**
	 * 收集網站清單中出現過的相異 cloud user id
	 *
	 * 以 userId 為主要來源；部分回應只帶巢狀的 user.id，沿用既有前端的 fallback 慣例
	 * （見 js/src/pages/AdminApp/Dashboard/SiteList/WebsiteEditor/WebsiteEditorForm.tsx 的 `userId ?? user?.id`）。
	 *
	 * @param array<int, mixed> $websites 網站清單
	 * @return array<int, string>
	 */
	private static function collect_cloud_user_ids( array $websites ): array {
		$ids = [];

		foreach ( $websites as $website ) {
			if ( ! is_array( $website ) ) {
				continue;
			}

			$raw = $website['userId'] ?? null;
			if ( ( null === $raw || '' === $raw ) && isset( $website['user'] ) && is_array( $website['user'] ) ) {
				$raw = $website['user']['id'] ?? null;
			}

			if ( ! is_scalar( $raw ) ) {
				continue;
			}

			$id = trim( (string) $raw );
			if ( '' === $id ) {
				continue;
			}

			$ids[ $id ] = true;
		}

		return array_keys( $ids );
	}

	/**
	 * 組出 payload 的 sites（只含 status 為 running 的網站）
	 *
	 * @param array<int, mixed> $websites 網站清單
	 * @return array<int, array{domain: string, status: string, dailyCost: float}>
	 */
	private static function build_sites( array $websites ): array {
		$sites = [];

		foreach ( $websites as $website ) {
			if ( ! is_array( $website ) ) {
				continue;
			}

			$status = isset( $website['status'] ) && is_scalar( $website['status'] ) ? (string) $website['status'] : '';
			if ( self::BILLABLE_STATUS !== $status ) {
				continue;
			}

			$domain  = self::resolve_domain( $website );
			$sites[] = [
				'domain'    => $domain,
				'status'    => $status,
				'dailyCost' => self::resolve_daily_cost( $website, $domain ),
			];
		}

		return $sites;
	}

	/**
	 * 取得網站的 domain（優先序：primaryDomain > domain > subDomain > wildcardDomain）
	 *
	 * @param array<string, mixed> $website 網站資料
	 * @return string
	 */
	private static function resolve_domain( array $website ): string {
		foreach ( self::DOMAIN_KEYS as $key ) {
			$value = $website[ $key ] ?? null;
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * 取得網站的 dailyCost；缺值或非數值時以 0 計並寫 warning log
	 *
	 * 欄位 dailyCost 在 API 型別中為 optional。寧可漏收也不要靜默算錯，且異常必須可見。
	 *
	 * @param array<string, mixed> $website 網站資料
	 * @param string               $domain  網站 domain（供 log 辨識）
	 * @return float
	 */
	private static function resolve_daily_cost( array $website, string $domain ): float {
		$raw = $website['dailyCost'] ?? null;

		if ( is_numeric( $raw ) ) {
			return round( (float) $raw, 2 );
		}

		Plugin::logger(
			"新架構每日計費：網站 {$domain} 的 dailyCost 缺值或非數值，以 0 計",
			'warning',
			[
				'domain'    => $domain,
				'dailyCost' => is_scalar( $raw ) ? $raw : \wp_json_encode( $raw ),
			]
		);

		return 0.0;
	}

	/**
	 * 組出統一的執行結果
	 *
	 * @param bool   $pushed         是否已推送成功
	 * @param string $reason         結果原因
	 * @param string $billing_date   業務日期
	 * @param int    $billable_count 計費站數
	 * @param float  $total_amount   總金額
	 * @return array{pushed: bool, reason: string, billing_date: string, billable_count: int, total_amount: float}
	 */
	private static function result(
		bool $pushed,
		string $reason,
		string $billing_date,
		int $billable_count = 0,
		float $total_amount = 0.0
	): array {
		return [
			'pushed'         => $pushed,
			'reason'         => $reason,
			'billing_date'   => $billing_date,
			'billable_count' => $billable_count,
			'total_amount'   => $total_amount,
		];
	}
}
