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
 * 排程寫法刻意採 singleton + as_next_scheduled_action() 守衛 + as_schedule_cron_action()，
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

	/**
	 * 每日觸發的 cron 運算式（21:00 UTC = UTC+8 05:00）
	 *
	 * 刻意用 wall-clock（cron）而非 interval 排程：interval 以「實際執行時間 + 24h」推算
	 * 下一次，佇列延遲會單向累積漂移，漂到跨越 UTC+8 午夜之後就會整天不計費。
	 *
	 * @var string
	 */
	const CRON_EXPRESSION = '0 21 * * *';

	/** @var string 排程型態版本（'2' = wall-clock cron 排程），用於一次性遷移舊的 interval 排程 */
	const SCHEDULE_VERSION = '2';

	/** @var string 排程型態版本 option */
	const SCHEDULE_VERSION_OPTION = 'power_partner_billing_schedule_version';

	/** @var string 已排程首推的外掛版本 option */
	const BOOTSTRAP_VERSION_OPTION = 'power_partner_billing_bootstrap_version';

	/** @var string 本地記錄的經銷商 id（dealerId）綁定值 option */
	const BOUND_DEALER_ID_OPTION = 'power_partner_billing_dealer_id';

	/** @var int 啟用／升級後首推的延遲秒數 */
	const BOOTSTRAP_DELAY = 60;

	/** @var int 實際執行時間與排程 slot 的容許落差，超過則寫 error log */
	const MAX_DRIFT_SECONDS = 21600; // 6 * HOUR_IN_SECONDS

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
		\add_action( 'init', [ $this, 'maybe_schedule_bootstrap_push' ], 11 );
		\add_action( self::CRON_HOOK, [ __CLASS__, 'action_callback' ], 10, 1 );
		\add_action( self::RETRY_HOOK, [ __CLASS__, 'action_callback' ], 10, 1 );
	}

	/**
	 * 註冊每日定期排程（已存在時不重複註冊）
	 *
	 * @return void
	 */
	public function register_daily_action_scheduler(): void {
		if ( ! \function_exists( 'as_next_scheduled_action' ) || ! \function_exists( 'as_schedule_cron_action' ) ) {
			return;
		}

		$next     = \as_next_scheduled_action( self::CRON_HOOK );
		$migrated = self::SCHEDULE_VERSION === (string) \get_option( self::SCHEDULE_VERSION_OPTION );

		// 已註冊且已是 wall-clock 排程
		if ( false !== $next && $migrated ) {
			return;
		}

		// 舊的 interval 排程改註冊為 cron 排程。起始時刻沿用原排程即將觸發的時間，
		// ActionScheduler 會自動對齊到下一個符合 cron 運算式的時刻，不會因遷移而多推一次
		$start = is_int( $next ) && $next > time() ? $next : self::next_schedule_timestamp();

		if ( false !== $next ) {
			\as_unschedule_all_actions( self::CRON_HOOK );
		}

		\as_schedule_cron_action( $start, self::CRON_EXPRESSION, self::CRON_HOOK );
		\update_option( self::SCHEDULE_VERSION_OPTION, self::SCHEDULE_VERSION, true );
	}

	/**
	 * 外掛啟用或版本升級後立刻排一次首推
	 *
	 * 接收端以 Trust On First Use 綁定身分：首次收到某 partner_id 的推送時，才把 payload 的
	 * dealer_id 存為該經銷商的綁定值。若等到隔日 05:00 才首推，功能發布當天全體經銷商
	 * 都處於「未綁定」狀態，會出現最長 24 小時、橫跨所有經銷商的搶綁窗口 —— 而 partner_id
	 * 可由未認證的 GET /partner-id 讀出。立即排一次可把窗口壓到約 1 分鐘。
	 *
	 * @return void
	 */
	public function maybe_schedule_bootstrap_push(): void {
		if ( ! \function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$version = (string) Plugin::$version;
		if ( '' === $version || $version === (string) \get_option( self::BOOTSTRAP_VERSION_OPTION ) ) {
			return;
		}

		// 先落地版本旗標再排程：否則每次 init 都會再排一次
		\update_option( self::BOOTSTRAP_VERSION_OPTION, $version, true );

		$billing_date = self::resolve_billing_date();

		\as_schedule_single_action(
			time() + self::BOOTSTRAP_DELAY,
			self::RETRY_HOOK,
			[
				[
					'billing_date' => $billing_date,
					'retried'      => 0,
				],
			]
		);

		Plugin::logger(
			sprintf( '新架構每日計費：外掛版本 %1$s 首次載入，已排程 %2$d 秒後推送一次以盡早建立身分綁定', $version, self::BOOTSTRAP_DELAY ),
			'info',
			[
				'billing_date' => $billing_date,
				'version'      => $version,
			]
		);
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
		$scheduled_date = null;
		if ( isset( $args['billing_date'] ) && is_string( $args['billing_date'] ) && '' !== $args['billing_date'] ) {
			$scheduled_date = $args['billing_date'];
		}

		$billing_date = $scheduled_date ?? self::resolve_billing_date();
		if ( null === $scheduled_date ) {
			self::warn_on_schedule_drift( $billing_date );
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
			// 刻意不寄信（與其餘設定類中止路徑不同）：本外掛裝了就無條件註冊排程，
			// 不問有沒有連結過帳號 —— 而 partner_id 只在後台按下「連結帳號」時才寫入。
			// 於是每一台「裝了外掛但從未連結」的站（含模板站與由它 clone 出來的站）
			// 都會每天寄一封，收信人卻沒有任何錢會漏：這類站根本不是運作中的經銷商站。
			// 噪音把真正該被看見的告警一起淹掉，故此路徑只留 error log。
			// 真經銷商若尚未連結，人就在後台，介面上直接看得到連結表單，不需要靠信提醒。
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
			self::notify_admin(
				$billing_date,
				'no_api_key',
				'<p>找不到全域 PowerCloud API Key，本日新架構（PowerCloud）網站的計費資料未送出。</p><p>請到後台 Power Partner 設定頁的「新架構權限」tab 重新認證，以寫入全域 key。</p><p>排程情境沒有登入者，只讀得到全域 key；若貴站當初只存了舊版的 per-user key，每日計費會從第一天起就永遠中止。</p>'
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

		// 本地綁定值比對：接收端首推即綁定 dealer_id，之後不符就拒絕扣點。
		// 本地留一份對照，讓「PowerCloud 帳號被換掉」或「本地狀態異常」在送出前就被擋下
		$bound = (string) \get_option( self::BOUND_DEALER_ID_OPTION, '' );

		// 邊界：清單完全為空 → 仍需推送空 sites，不可跳過。
		// 接收端的新架構合計 user meta 只在成功扣點時更新，且刻意設計成 stale 時沿用舊值不歸零
		// （歸零會讓大型經銷商掉回 7 天停用門檻而遭提前停用）。跳過推送會讓「推送失敗」與
		// 「經銷商把站全部刪光」在接收端看起來一模一樣 —— meta 都沒被更新 —— 後者會讓一個
		// 已無新架構站的經銷商被永久認定為大型經銷商，欠費時多拖 23 天才停用，且不會自我修復。
		// 照常推送空 sites 之後接收端會把 meta 更新為 0，兩種情況即可區分。
		if ( ! $websites ) {
			// 沒有站就取不到 dealerId；接收端要求 dealer_id 為非空字串，只能取本地綁定值。
			// 沒有綁定值代表從未成功推送過，接收端也還沒有任何合計 meta 需要更新為 0
			if ( '' === $bound ) {
				Plugin::logger(
					'新架構每日計費推送跳過：PowerCloud 網站清單為空，且本地尚無經銷商 id（dealerId）綁定值',
					'info',
					[ 'billing_date' => $billing_date ]
				);
				return self::result( false, 'empty_list', $billing_date );
			}

			Plugin::logger(
				'新架構每日計費：PowerCloud 網站清單為空，以本地綁定值推送空 sites，讓接收端把合計歸零',
				'info',
				[
					'billing_date' => $billing_date,
					'dealer_id'    => $bound,
				]
			);
		}

		$dealer_ids = $websites ? self::collect_dealer_ids( $websites ) : [ $bound ];

		// dealer_id 是接收端 TOFU 身分綁定的依據，取不到就必定被拒絕扣點，不推送
		if ( ! $dealer_ids ) {
			Plugin::logger(
				'新架構每日計費推送中止：網站清單取不到經銷商 id（dealerId）',
				'error',
				[
					'billing_date'  => $billing_date,
					'website_count' => count( $websites ),
				]
			);
			self::notify_admin(
				$billing_date,
				'no_dealer_id',
				'<p>PowerCloud 網站清單中所有網站都取不到經銷商 id（<code>user.dealerId</code>），本日計費資料未送出。</p><p>接收端以 dealer_id 做身分綁定比對，空值必定被拒絕扣點。這通常代表 PowerCloud 的 /websites 回應欄位已改版，請通知開發者確認。</p>'
			);
			return self::result( false, 'no_dealer_id', $billing_date );
		}

		// 同一把 API key 底下所有站應屬同一個經銷商（user.dealerId 相同）。出現多個代表該 key
		// 權限範圍超出預期，推送會把不屬於本經銷商的站算到他頭上，必須中止而非靜默取第一筆。
		// 注意識別的是「經銷商」不是「開站用戶」—— 同一經銷商底下有多個開站用戶是常態，
		// user.id / userId 本來就會出現多個相異值，拿來當識別會讓本守衛每天誤觸發
		if ( count( $dealer_ids ) > 1 ) {
			Plugin::logger(
				'新架構每日計費推送中止：網站清單出現多個相異經銷商 id（dealerId），API key 權限範圍異常',
				'error',
				[
					'billing_date' => $billing_date,
					'dealer_ids'   => $dealer_ids,
				]
			);
			self::notify_admin(
				$billing_date,
				'multiple_dealer_ids',
				sprintf(
					'<p>PowerCloud 網站清單出現多個相異經銷商 id（<code>user.dealerId</code>：%1$s），本日計費資料未送出。</p><p>正常情況下同一把 API key 底下所有站都屬同一個經銷商，出現多個代表這把 key 的<strong>權限範圍</strong>已超出預期（例如被換成管理員層級的 key），權限模型可能已變更。若照推會把不屬於貴站的網站算到貴站頭上，因此直接中止。請確認後台「新架構權限」tab 綁定的 API key 是否正確。</p>',
					\esc_html( implode( ', ', $dealer_ids ) )
				)
			);
			return self::result( false, 'multiple_dealer_ids', $billing_date );
		}

		// 多租戶守衛的旁路：collect_dealer_ids() 對取不到 id 的站一律略過，
		// build_sites() 又完全不看 dealerId，因此「API key 權限範圍意外放大、且多出來的站
		// user 為 null 或缺 dealerId」時，相異 id 集合仍只有一個 → 上面的守衛不觸發 →
		// 不屬於本經銷商的站被算進 payload 並以本經銷商身分推送，接收端只驗 TOFU 綁定值照扣。
		// 同一把 key 範圍內出現「沒有 owner 的可計費網站」本身就是異常訊號，中止而非靜默納入。
		// 注意：這裡只做「中止或放行」，不得改成用 dealer_id 過濾清單 ——
		// 既有規格明確不做二次過濾（見 feature 的「不做二次過濾」Rule）
		$orphan_count = $websites ? self::count_billable_sites_without_owner( $websites ) : 0;
		if ( $orphan_count > 0 ) {
			Plugin::logger(
				'新架構每日計費推送中止：可計費網站中有站解析不出經銷商 id（dealerId），API key 權限範圍可能已放大',
				'error',
				[
					'billing_date'  => $billing_date,
					'orphan_count'  => $orphan_count,
					'website_count' => count( $websites ),
				]
			);
			self::notify_admin(
				$billing_date,
				'billable_site_without_owner',
				sprintf(
					'<p>PowerCloud 網站清單中有 %1$d 個 status 為 <code>%2$s</code> 的網站，其經銷商 id（<code>user.dealerId</code>）為空，無法確認擁有者，本日計費資料未送出。</p><p>同一把 API key 底下的站都應該有 owner。出現沒有 owner 的站，代表這把 key 的<strong>權限範圍</strong>可能已放大到其他帳號 —— 這類站若照推，會被以貴站的身分算進計費（接收端只比對 dealer_id，比對得過就照扣）。</p><p>請確認後台「新架構權限」tab 綁定的 API key 是否正確。</p>',
					$orphan_count,
					self::BILLABLE_STATUS
				)
			);
			return self::result( false, 'billable_site_without_owner', $billing_date );
		}

		$dealer_id = $dealer_ids[0];

		if ( '' !== $bound && $bound !== $dealer_id ) {
			Plugin::logger(
				'新架構每日計費推送中止：經銷商 id（dealerId）與本地綁定值不符',
				'error',
				[
					'billing_date' => $billing_date,
					'bound'        => $bound,
					'dealer_id'    => $dealer_id,
				]
			);
			self::notify_admin(
				$billing_date,
				'dealer_id_changed',
				sprintf(
					'<p>本次從 PowerCloud 網站清單解析出的經銷商 id（dealerId：%1$s）與本地記錄的綁定值（%2$s）不符，本日計費資料未送出。</p><p>這代表 PowerCloud 帳號可能已更換，或本地狀態異常。接收端的身分綁定同樣不會自動換綁，硬推只會被拒絕，因此直接中止。</p><p>若確認是正常換綁，請聯絡 cloud.luke.cafe 管理員清除綁定，並刪除本站的 <code>%3$s</code> option 後重推。</p>',
					\esc_html( $dealer_id ),
					\esc_html( $bound ),
					self::BOUND_DEALER_ID_OPTION
				)
			);
			return self::result( false, 'dealer_id_changed', $billing_date );
		}

		$sites = $websites ? self::build_sites( $websites ) : [];

		// 清單非空卻篩不出任何可計費網站 —— 極可能是 PowerCloud 的狀態字典改版
		// （例如 running 改成 Running），照推會讓接收端以 0 點寫掉本業務日的冪等鍵，當天再也補不回來。
		// 與上面「清單完全為空」的正常路徑刻意分開：用 count($websites) 就能區分
		// 「經銷商真的沒站了」（正常，照推空 sites）與「狀態字典改版」（異常，要告警）
		if ( $websites && ! $sites ) {
			$statuses = self::collect_statuses( $websites );
			Plugin::logger(
				'新架構每日計費：網站清單非空卻沒有任何可計費網站，疑似 PowerCloud 狀態字典改版',
				'error',
				[
					'billing_date'    => $billing_date,
					'website_count'   => count( $websites ),
					'billable_status' => self::BILLABLE_STATUS,
					'actual_statuses' => $statuses,
				]
			);
			self::notify_admin(
				$billing_date,
				'no_billable_site',
				sprintf(
					'<p>PowerCloud 回報 %1$d 個網站，但沒有任何一個的 status 是 <code>%2$s</code>，本日計費金額為 0。</p><p>本次清單出現過的狀態值：<code>%3$s</code>。</p><p>若上述狀態值看起來只是大小寫或用字不同（例如 <code>Running</code>），代表 PowerCloud 的狀態字典已改版，計費過濾條件需要同步更新；請盡快通知開發者，否則每天都會以 0 點寫掉冪等鍵。</p>',
					count( $websites ),
					self::BILLABLE_STATUS,
					\esc_html( implode( ', ', $statuses ) )
				),
				0.0,
				0
			);
		}

		$total_amount = round( (float) array_sum( array_column( $sites, 'dailyCost' ) ), 2 );

		$payload = [
			'partner_id'   => (int) $partner_id,
			'dealer_id'    => $dealer_id,
			'billing_date' => $billing_date,
			'sites'        => $sites,
		];

		$push = BillingPushClient::push( $payload );

		if ( ! $push['success'] ) {
			// 身分綁定不符：重試三次也不會成功，且可能代表綁定已被他人搶走，須立即人工介入
			if ( $push['identity_mismatch'] ) {
				Plugin::logger(
					'新架構每日計費推送遭拒：接收端回報身分綁定不符，需人工介入',
					'error',
					[
						'billing_date'  => $billing_date,
						'dealer_id'     => $dealer_id,
						'response_code' => $push['response_code'],
					]
				);
				self::notify_admin(
					$billing_date,
					'identity_mismatch',
					sprintf(
						'<p>cloud.luke.cafe 以 HTTP 403 拒絕本次推送，原因是經銷商 id（dealer_id）與該經銷商<strong>已綁定</strong>的值不符。本次推送的 dealer_id 為 %1$s。</p><p>接收端的綁定採 Trust On First Use（首次收到即綁定），不會自動換綁。重試三次也不會成功，因此不進入重試流程。</p><p>可能原因：(1) 貴站的 PowerCloud 帳號已更換；(2) 有人以貴站的 partner_id 搶先完成綁定。請立刻聯絡 cloud.luke.cafe 管理員核對綁定值。</p>',
						\esc_html( $dealer_id )
					),
					$total_amount,
					count( $sites )
				);
				return self::result( false, 'identity_mismatch', $billing_date, count( $sites ), $total_amount );
			}

			// 其餘永久性錯誤：接收端以 data.error_code 標示。重試三次也不會成功，
			// 白重試只會讓管理員晚 90 分鐘才知道（資安審查 M2）
			if ( $push['permanent'] ) {
				Plugin::logger(
					sprintf( '新架構每日計費推送遭拒：接收端回報永久性錯誤 %1$s，不進入重試流程', $push['error_code'] ),
					'error',
					[
						'billing_date'  => $billing_date,
						'error_code'    => $push['error_code'],
						'response_code' => $push['response_code'],
					]
				);
				self::notify_admin(
					$billing_date,
					$push['error_code'],
					self::permanent_error_detail( $push['error_code'], $push['response_code'], $push['message'] ),
					$total_amount,
					count( $sites )
				);
				return self::result( false, $push['error_code'], $billing_date, count( $sites ), $total_amount );
			}

			Plugin::logger(
				'新架構每日計費推送失敗',
				'error',
				[
					'billing_date'   => $billing_date,
					'retried'        => $retried,
					'billable_count' => count( $sites ),
					'total_amount'   => $total_amount,
					'response_code'  => $push['response_code'],
				]
			);
			self::schedule_retry( $billing_date, $retried, 'push_failed', $total_amount, count( $sites ) );
			return self::result( false, 'push_failed', $billing_date, count( $sites ), $total_amount );
		}

		// 首推成功才建立本地綁定 —— 之後每次推送都會先與此值比對
		if ( '' === $bound ) {
			\update_option( self::BOUND_DEALER_ID_OPTION, $dealer_id, true );
			Plugin::logger(
				sprintf( '新架構每日計費：已記錄本地經銷商 id（dealerId）綁定值 %1$s', $dealer_id ),
				'info',
				[
					'billing_date' => $billing_date,
					'dealer_id'    => $dealer_id,
				]
			);
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
				'dealer_id'      => $dealer_id,
				'website_count'  => count( $websites ),
				'billable_count' => count( $sites ),
				'total_amount'   => $total_amount,
			]
		);

		return self::result( true, 'ok', $billing_date, count( $sites ), $total_amount );
	}

	/**
	 * 取得業務日期（指定時刻的 UTC+8 日期，YYYY-MM-DD）
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
	 * 取得本次排程觸發所對應的業務日期
	 *
	 * 刻意由「最近一次 21:00 UTC 排程時刻」回推，而非由執行當下推導 ——
	 * 21:00 UTC 距 UTC+8 午夜只有 19 小時緩衝，佇列積壓一旦吃掉這段緩衝，
	 * 由 time() 推導出的 billing_date 會直接跳到隔天，那個業務日就永遠收不到錢
	 * （接收端的冪等鍵已被隔天的推送佔用）。
	 *
	 * @param int|null $now 現在時間（預設為現在）
	 * @return string
	 */
	public static function resolve_billing_date( ?int $now = null ): string {
		return self::get_billing_date( self::current_schedule_slot( $now ?? time() ) );
	}

	/**
	 * 實際執行時間與所屬排程 slot 的落差（秒）
	 *
	 * @param int|null $now 現在時間（預設為現在）
	 * @return int
	 */
	public static function schedule_drift_seconds( ?int $now = null ): int {
		$now = $now ?? time();
		return $now - self::current_schedule_slot( $now );
	}

	/**
	 * 取得 $now 所屬的排程 slot（最近一次 21:00 UTC，含當下）
	 *
	 * @param int $now 現在時間
	 * @return int
	 */
	private static function current_schedule_slot( int $now ): int {
		$today_utc = (int) strtotime( gmdate( 'Y-m-d', $now ) . ' 00:00:00 UTC' );
		$slot      = $today_utc + self::SCHEDULE_SECONDS_UTC;

		if ( $slot > $now ) {
			$slot -= DAY_IN_SECONDS;
		}

		return $slot;
	}

	/**
	 * 排程漂移超過門檻時寫 error log
	 *
	 * 漂移逼近 24 小時時會開始整天漏推，必須在漏推之前就看得見。
	 *
	 * @param string $billing_date 業務日期
	 * @return void
	 */
	private static function warn_on_schedule_drift( string $billing_date ): void {
		$drift = self::schedule_drift_seconds();
		if ( $drift <= self::MAX_DRIFT_SECONDS ) {
			return;
		}

		Plugin::logger(
			sprintf(
				'新架構每日計費：本次觸發較排程時刻晚了 %1$d 分鐘，漂移逼近 24 小時後會開始整天漏推，請檢查 ActionScheduler 佇列',
				(int) round( $drift / MINUTE_IN_SECONDS )
			),
			'error',
			[
				'billing_date'  => $billing_date,
				'drift_seconds' => $drift,
				'max_drift'     => self::MAX_DRIFT_SECONDS,
			]
		);
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
	 * @param string     $billing_date 業務日期（重試時不變）
	 * @param int        $retried      已重試次數
	 * @param string     $reason       失敗原因
	 * @param float|null $total_amount 本次未送出的計費金額（算得出來時才傳）
	 * @param int|null   $site_count   本次未送出的可計費站數（算得出來時才傳）
	 * @return void
	 */
	private static function schedule_retry( string $billing_date, int $retried, string $reason, ?float $total_amount = null, ?int $site_count = null ): void {
		if ( $retried >= self::MAX_RETRY ) {
			self::notify_admin(
				$billing_date,
				$reason,
				sprintf(
					'<p>已重試 %1$d 次仍失敗，本日新架構（PowerCloud）網站的計費資料未送達 cloud.luke.cafe，請盡快檢查。</p>',
					$retried
				),
				$total_amount,
				$site_count
			);
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
	 * 永久性錯誤（接收端的 data.error_code）對應的通知信內文
	 *
	 * 代碼清單見 BillingPushClient::PERMANENT_ERROR_CODES。接收端未來新增代碼時，
	 * 這裡沒對到就走 default 的通用文案，不會漏寄信。
	 *
	 * 一律附上接收端回的 message —— 光給管理員一個 `missing_field` 之類的代碼，
	 * 他無從得知是哪一個參數出問題，等於查不下去。
	 *
	 * @param string $error_code    接收端回報的錯誤代碼
	 * @param int    $response_code HTTP status code
	 * @param string $message       接收端回報的 message（僅供顯示）
	 * @return string
	 */
	private static function permanent_error_detail( string $error_code, int $response_code, string $message = '' ): string {
		$detail = match ( $error_code ) {
			'partner_not_found'    => '<p>cloud.luke.cafe 找不到本站 <code>partner_id</code> 對應的經銷商帳號，本日計費資料未送出。</p><p>可能是 partner_id 設錯，或該帳號已被刪除。請至後台重新連結 cloud.luke.cafe 取得正確的 partner_id。</p>',
			'not_a_dealer'         => '<p>cloud.luke.cafe 回報本站 <code>partner_id</code> 對應的帳號<strong>不是經銷商</strong>，無法扣點，本日計費資料未送出。</p><p>請聯絡 cloud.luke.cafe 管理員確認該帳號的角色設定。</p>',
			'invalid_billing_date' => '<p>cloud.luke.cafe 認為本次推送的 <code>billing_date</code> 超出可接受範圍，本日計費資料未送出。</p><p>這通常代表排程嚴重落後，或本站的主機時間不正確。請檢查 ActionScheduler 佇列與主機時間，並通知開發者。</p>',
			'missing_field'        => '<p>cloud.luke.cafe 回報推送內容<strong>缺少</strong>必填欄位，本日計費資料未送出。</p><p>欄位整個沒送出，通常代表兩端的契約版本不一致（例如一端已改欄位名、另一端尚未更新）。請通知開發者核對 Power Partner 與 cloud.luke.cafe 的版本。</p>',
			'invalid_field'        => '<p>cloud.luke.cafe 回報推送內容有欄位<strong>型別或格式不符</strong>，本日計費資料未送出。</p><p>欄位有送出但值不合法，代表發送端組出的資料有誤。請檢查資料組裝邏輯與 PowerCloud 回應格式，並看下方 message 確認是哪一個參數。</p>',
			default                => '<p>cloud.luke.cafe 以永久性錯誤拒絕本次推送，本日計費資料未送出。</p><p>這類錯誤重試不會成功，請通知開發者確認。</p>',
		};

		$detail .= sprintf(
			'<p>接收端回報：HTTP %1$d，錯誤代碼 <code>%2$s</code>。重試三次也不會成功，因此不進入重試流程。</p>',
			$response_code,
			\esc_html( $error_code )
		);

		if ( '' !== $message ) {
			$detail .= sprintf( '<p>接收端 message：<code>%1$s</code></p>', \esc_html( self::truncate( $message ) ) );
		}

		return $detail;
	}

	/**
	 * 截斷過長的字串（供通知信顯示用）
	 *
	 * 接收端的 message 長度不受本站控制，中介設備也可能塞入大量內容，
	 * 原樣貼進信件會產生無法閱讀的巨信。
	 *
	 * @param string $text  原字串
	 * @param int    $limit 上限字元數
	 * @return string
	 */
	private static function truncate( string $text, int $limit = 300 ): string {
		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}

		return mb_substr( $text, 0, $limit ) . '…';
	}

	/**
	 * 推送中止／失敗：寄信通知站台管理員 + error log
	 *
	 * 漏推一天等於少收一天錢，必須有人看得見 —— 設定類的中止路徑（缺 partner_id、缺 API Key、
	 * 缺 dealer_id、多個 dealer_id）不會自行復原，只寫 log 等於沒人知道。
	 *
	 * @param string     $billing_date 業務日期
	 * @param string     $reason       原因
	 * @param string     $detail       信件內文（HTML，呼叫端自行組好並轉義）
	 * @param float|null $total_amount 本次未送出的計費金額（算得出來時才傳）
	 * @param int|null   $site_count   本次未送出的可計費站數（算得出來時才傳）
	 * @return void
	 */
	private static function notify_admin( string $billing_date, string $reason, string $detail, ?float $total_amount = null, ?int $site_count = null ): void {
		$admin_email = (string) \get_option( 'admin_email' );

		// 主旨帶站台網域：收信人多半同時是好幾個站的 admin_email，主旨全都一樣時
		// 收件匣裡分不出是哪一台出事，也搜尋不到。站名（blogname）不能用 ——
		// 沒改過站名的站全叫「我的網站」，唯一識別得靠網域
		$subject = sprintf(
			'【Power Partner】%1$s 新架構網站計費資料推送異常（%2$s）',
			self::site_host(),
			$billing_date
		);

		$message = self::site_identity_html( $billing_date, $reason, $total_amount, $site_count ) . $detail;

		\wp_mail( $admin_email, $subject, $message, [ 'Content-Type: text/html; charset=UTF-8' ] );

		Plugin::logger(
			sprintf( '新架構每日計費推送異常（%1$s），已寄信通知 %2$s', $reason, $admin_email ),
			'error',
			[
				'billing_date' => $billing_date,
				'reason'       => $reason,
			]
		);
	}

	/**
	 * 本站的網域（主旨與識別區塊用）
	 *
	 * @return string 解析不出時退回站名，再不然退回 '(未知站台)'
	 */
	private static function site_host(): string {
		$host = \wp_parse_url( (string) \site_url(), PHP_URL_HOST );
		if ( is_string( $host ) && '' !== $host ) {
			return $host;
		}

		$name = (string) \get_bloginfo( 'name' );

		return '' !== $name ? $name : '(未知站台)';
	}

	/**
	 * 信件開頭的站台識別區塊
	 *
	 * 原本只印 blogname 與原因代碼。blogname 預設值人人相同（「我的網站」），
	 * 收信人因此看不出是哪一台出事、要拿什麼身分去比對、也不知道漏了多少錢 ——
	 * 而這封信要求的動作（重新連結、換 API key、聯絡接收端核對綁定）每一項都要先知道這些。
	 * 唯一識別得靠網域；partner_id 與 dealer_id 是與接收端對帳時要報的兩個號碼。
	 *
	 * @param string     $billing_date 業務日期
	 * @param string     $reason       原因代碼
	 * @param float|null $total_amount 未送出的計費金額
	 * @param int|null   $site_count   未送出的可計費站數
	 * @return string
	 */
	private static function site_identity_html( string $billing_date, string $reason, ?float $total_amount, ?int $site_count ): string {
		$partner_id = \get_option( Connect::PARTNER_ID_OPTION_NAME );
		$bound      = (string) \get_option( self::BOUND_DEALER_ID_OPTION, '' );

		$rows = [
			'站台'       => sprintf(
				'<strong>%1$s</strong><br /><a href="%2$s" target="_blank">%2$s</a>',
				\esc_html( (string) \get_bloginfo( 'name' ) ),
				\esc_url( (string) \site_url() )
			),
			'後台'       => sprintf( '<a href="%1$s" target="_blank">開啟本站後台</a>', \esc_url( (string) \admin_url() ) ),
			'經銷商編號' => ( is_scalar( $partner_id ) && '' !== (string) $partner_id )
				? \esc_html( '#' . (string) $partner_id )
				: '<span style="color:#b00;">未設定</span>',
			'經銷商 id'  => '' !== $bound
				? sprintf( '<code>%s</code>', \esc_html( $bound ) )
				: '<span style="color:#666;">尚未綁定（從未成功推送過）</span>',
			'業務日期'   => \esc_html( $billing_date ),
			'原因代碼'   => sprintf( '<code>%s</code>', \esc_html( $reason ) ),
		];

		// 前置中止（缺 partner_id / 缺 API key）發生在抓網站清單之前，算不出金額。
		// 那時硬填 0 會讓收信人以為「今天本來就沒錢可收」而不急著處理，故整列不顯示
		if ( null !== $site_count ) {
			$rows['未送出站數'] = \esc_html( (string) $site_count );
		}
		if ( null !== $total_amount ) {
			$rows['未送出金額'] = sprintf(
				'<strong style="color:#b00;">%s 點</strong>（漏推一天等於少收一天錢）',
				\esc_html( number_format( $total_amount, 2 ) )
			);
		}

		$th_style = 'border:1px solid #ddd;padding:6px;text-align:left;background:#fafafa;white-space:nowrap;';
		$td_style = 'border:1px solid #ddd;padding:6px;';

		$html = '';
		foreach ( $rows as $label => $value ) {
			$html .= sprintf(
				'<tr><th style="%1$s">%2$s</th><td style="%3$s">%4$s</td></tr>',
				$th_style,
				\esc_html( $label ),
				$td_style,
				$value
			);
		}

		return sprintf(
			'<p style="margin:0 0 6px;"><strong>發生問題的站台</strong></p><table style="border-collapse:collapse;margin-bottom:16px;">%s</table>',
			$html
		);
	}

	/**
	 * 收集網站清單中出現過的相異經銷商 id（解析不出的站略過）
	 *
	 * 注意：略過的站不會出現在這個集合裡，所以「多個相異 dealerId」守衛看不到它們 ——
	 * 那條旁路由 count_billable_sites_without_owner() 補上。
	 *
	 * @param array<int, mixed> $websites 網站清單
	 * @return array<int, string>
	 */
	private static function collect_dealer_ids( array $websites ): array {
		$ids = [];

		foreach ( $websites as $website ) {
			if ( ! is_array( $website ) ) {
				continue;
			}

			$id = self::resolve_dealer_id( $website );
			if ( '' === $id ) {
				continue;
			}

			$ids[ $id ] = true;
		}

		return array_keys( $ids );
	}

	/**
	 * 解析單一網站的經銷商 id（取不到時回空字串）
	 *
	 * 取值一律是巢狀的 `user.dealerId`，**不是** `userId`、也不是 `user.id`。
	 * PowerCloud 的帳號是三層階層：
	 *
	 *   經銷商（dealer）  dealerId: 181f2bbe-1292-459a-a814-0baa72423636  ← 計費／綁定對象
	 *     └─ 開站用戶      user.id:  e77dcfa2-0687-49a5-a54a-50f721fef8bd  ← 只是操作者
	 *          └─ 網站      vibrant-panda-34812
	 *
	 * 一個經銷商底下可以有多個開站用戶，因此 `userId` / `user.id` 在同一批回應中本來就會出現
	 * 多個相異值。拿那兩個欄位當識別，會讓「多個相異識別值視為異常」的守衛每天誤觸發、
	 * 整天一行都推不出去，同時 TOFU 綁定也會綁到錯的 id。**請勿改回 userId。**
	 *
	 * `user` 的 API 型別是 `{...} | null`，故不預設它是陣列。刻意不做 fallback ——
	 * 解析不出經銷商 id 時寧可中止並通知，也不要猜一個 id 去扣別人的點。
	 *
	 * 鍵型保持 mixed —— 這是外部 API 解碼後的未信任資料，不保證是字串鍵。
	 *
	 * @param array<mixed, mixed> $website 網站資料
	 * @return string
	 */
	private static function resolve_dealer_id( array $website ): string {
		$user = $website['user'] ?? null;
		if ( ! is_array( $user ) ) {
			return '';
		}

		$raw = $user['dealerId'] ?? null;
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		return trim( (string) $raw );
	}

	/**
	 * 統計「可計費（status 為 running）但解析不出 owner（經銷商 id）」的網站數
	 *
	 * 只看可計費網站 —— 非 running 的站本來就不進 payload，缺 owner 不影響計費。
	 *
	 * @param array<int, mixed> $websites 網站清單
	 * @return int
	 */
	private static function count_billable_sites_without_owner( array $websites ): int {
		$count = 0;

		foreach ( $websites as $website ) {
			if ( ! is_array( $website ) ) {
				continue;
			}

			$status = isset( $website['status'] ) && is_scalar( $website['status'] ) ? (string) $website['status'] : '';
			if ( self::BILLABLE_STATUS !== $status ) {
				continue;
			}

			if ( '' === self::resolve_dealer_id( $website ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * 收集網站清單中出現過的相異 status
	 *
	 * 用於「清單非空卻沒有任何可計費網站」時的診斷：PowerCloud 一旦改了狀態字典，
	 * 只有把實際出現過的值寫進 log 才看得出來。
	 *
	 * @param array<int, mixed> $websites 網站清單
	 * @return array<int, string>
	 */
	private static function collect_statuses( array $websites ): array {
		$statuses = [];

		foreach ( $websites as $website ) {
			if ( ! is_array( $website ) ) {
				continue;
			}

			$status = isset( $website['status'] ) && is_scalar( $website['status'] ) ? (string) $website['status'] : '(none)';

			$statuses[ $status ] = true;
		}

		return array_keys( $statuses );
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
