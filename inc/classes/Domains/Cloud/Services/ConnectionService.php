<?php

declare(strict_types=1);

namespace J7\PowerPartner\Domains\Cloud\Services;

use J7\PowerPartner\Api\Connect;
use J7\PowerPartner\Api\Fetch;
use J7\PowerPartner\Domains\Cloud\Shared\Arr;
use J7\PowerPartner\Plugin;

/**
 * 經銷商站與 CloudServer 的「連線」：登入、查身分、登出、換取專屬密碼
 *
 * 向後相容是這裡的核心約束：本版 client 可能早於 CloudServer 升級上線（CloudServer 還沒有
 * v3/connect），也可能在 CloudServer 升級後才上線。兩種順序都必須讓 AdminApp 照常登入、照常顯示。
 * - CloudServer 有 v3/connect → 換到專屬密碼（dealer 模式）
 * - 沒有（404）、或判定此帳號不符資格 → 退回舊流程 `POST identity`（legacy 模式），功能與舊版相同
 * ⚠️ 唯一的部署先後限制：本版必須在 power-partner-server #23 之後上線。真實密碼改由本站主機送出，
 * 舊版 CloudServer 在 IP 被 Fluent Security 封鎖時回的訊息與「密碼錯誤」相同；#23 起改回 429（見 send_with_real_password()）。
 *
 * 回應格式刻意與舊版 cloud `POST identity` 相同：`{status, message, data}`，另加 `connection` 欄位，
 * 讓 AdminApp 讀 identity 的程式碼不必改。
 */
abstract class ConnectionService {

	/** @var string 本站安裝識別（讓 CloudServer 能只撤銷「這個站」的那一把專屬密碼） */
	const INSTALLATION_OPTION = 'power_partner_cloud_installation';

	/** @var string 自動遷移狀態 */
	const MIGRATION_OPTION = 'power_partner_cloud_migration';

	const MIGRATION_DONE    = 'done';
	const MIGRATION_PENDING = 'pending';
	const MIGRATION_FAILED  = 'failed';
	const MIGRATION_NONE    = 'none';

	/**
	 * v3/connect 結果
	 */
	const EXCHANGE_OK               = 'ok';
	const EXCHANGE_UNSUPPORTED      = 'unsupported';      // CloudServer 尚無 v3/connect
	const EXCHANGE_NOT_ELIGIBLE     = 'not_eligible';     // 帳號不符經銷商資格
	const EXCHANGE_BAD_PASSWORD     = 'bad_credentials';  // 帳密錯誤
	const EXCHANGE_RATE_LIMITED     = 'rate_limited';
	const EXCHANGE_TEMPORARY        = 'temporary';        // 連線錯誤、5xx
	const EXCHANGE_PARTNER_MISMATCH = 'partner_mismatch'; // 換到的帳號不是本站綁定的經銷商（只在自動遷移比對）
	const EXCHANGE_LOGIN_CHALLENGE  = 'login_challenge';  // 帳號需要互動式驗證（驗證碼、2FA），自動送出永遠過不了

	/**
	 * @var string CloudServer 回「需要互動式驗證」的 data.error_code（HTTP 403）
	 *
	 * 登入驗證碼、two-factor、Fluent 的 email 2FA 都會讓 wp_authenticate() 失敗，但不是帳密錯誤。
	 * 永久性的：重試也不會成功，而且每試一次都會被 Fluent 計數、email 2FA 還會寄一封驗證信給經銷商。
	 * ⛔ 它只會出現在真實密碼的路徑上，不是「專屬密碼被拒」（不可加進 CloudClient::CREDENTIAL_REJECTED_CODES）。
	 */
	const LOGIN_CHALLENGE_CODE = 'login_challenge';

	/** @var int 遷移連續停在 pending 多久就提示經銷商（3 天） */
	const MIGRATION_STUCK_AFTER = 259200;

	/**
	 * @var string 本站唯一的「真實密碼閘門」狀態 `{failures: int[], cooldown_until: int}`
	 *
	 * CloudServer 以 `wp_authenticate()` 驗證真實密碼，cloud.luke.cafe 的 Fluent Security 依來源 IP
	 * 以滑動 30 分鐘計數（5 次）並封鎖整個 IP、封鎖期間每次嘗試都延長，而這些請求的來源是本站主機
	 * （同主機的經銷商站共用額度）。所有送出真實密碼的路徑都必須經過 send_with_real_password()：
	 * - 滑動 30 分鐘內失敗 2 次就不再送出
	 * - 收到任何 429（CloudServer 限流、IP 被封鎖、驗證碼、2FA）後 30 分鐘完全不送
	 */
	const REAL_PASSWORD_GATE_OPTION = 'power_partner_cloud_real_password_gate';

	/** @var int 滑動視窗內最多幾次失敗 */
	const REAL_PASSWORD_MAX_FAILURES = 2;

	/** @var int 失敗次數的滑動視窗（30 分鐘，與 Fluent Security 相同） */
	const REAL_PASSWORD_WINDOW = 1800;

	/** @var int 收到 429 後的冷卻時間（30 分鐘） */
	const REAL_PASSWORD_COOLDOWN = 1800;

	/** @var string 被閘門擋下時 send_with_real_password() 回的 WP_Error code */
	const GATE_ERROR_CODE = 'pp_real_password_gate';

	/** @var string 被閘門擋下時給經銷商的訊息 */
	const GATE_MESSAGE = '登入嘗試過於頻繁，請 30 分鐘後再試';

	/** 送出真實密碼的端點種類（判斷哪些回應算「帳密失敗」） */
	const SEND_CONNECT  = 'connect';
	const SEND_IDENTITY = 'identity';
	const SEND_DETAIL   = 'detail';

	/** notice 顯示範圍 */
	const SCOPE_GLOBAL        = 'global';
	const SCOPE_POWER_PARTNER = 'power_partner'; // 只在 Power Partner 頁面與 wp-admin 首頁

	/** @var string CloudServer 驗證真實密碼失敗時的固定訊息（`POST identity` 與點數明細兩個端點相同） */
	const BAD_CREDENTIALS_MESSAGE = 'email or password invalid';

	/**
	 * 目前的連線狀態與身分
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$client = CloudClient::instance();

		if ( CloudClient::MODE_DEALER === CloudClient::preferred_mode() ) {
			$response = $client->request_with_mode( CloudClient::MODE_DEALER, 'GET', 'v3/identity' );
			if ( CloudClient::is_credential_rejected( $response ) ) {
				CredentialStore::mark_dealer_invalid( CloudClient::error_code( $response ) );
			} else {
				$body = self::decode( $response );
				if ( 200 === (int) ( $body['status'] ?? \wp_remote_retrieve_response_code( $response ) ) && is_array( $body['data'] ?? null ) ) {
					CredentialStore::clear_dealer_invalid();
					return self::with_connection( $body, CloudClient::MODE_DEALER );
				}
			}
		}

		$account = CredentialStore::get_legacy_account();
		if ( null === $account ) {
			return self::not_connected();
		}

		// 帳密被拒過就不再送出：每次打開 AdminApp 都重送一次，失敗次數會累積在本站主機的 IP 上。
		// 共用帳號已停用時 `POST identity` 也打不進去，同樣不送
		if ( CredentialStore::is_legacy_account_rejected() || CredentialStore::legacy_disabled_recently() ) {
			return self::relogin_required();
		}

		$response = self::send_legacy_identity( $account['email'], $account['password'] );
		// 閘門擋下或 429（限流、IP 被封鎖）：暫時性的，不標記帳密
		if ( self::is_gated( $response ) || self::is_rate_limited( $response ) ) {
			return self::temporarily_unverifiable();
		}
		CloudClient::note_legacy_outcome( $response );
		// 需要互動式驗證：永久不再自動送出，請經銷商聯繫站長路可
		if ( self::is_login_challenge( $response ) ) {
			CredentialStore::mark_legacy_account_rejected( CredentialStore::REJECTED_LOGIN_CHALLENGE );
			return self::relogin_required();
		}
		$body = self::identity_body( $response );
		if ( 200 !== (int) ( $body['status'] ?? 0 ) ) {
			// 明確的「帳密錯誤」（經銷商改過密碼）→ 24 小時內不再重送，請他重新登入
			if ( self::is_password_rejected( $response ) ) {
				CredentialStore::mark_legacy_account_rejected( self::EXCHANGE_BAD_PASSWORD );
				return self::relogin_required();
			}
			// 暫時錯誤（連線失敗、5xx）→ 與舊版行為一致：顯示登入畫面
			return self::not_connected( (string) ( $body['message'] ?? '' ) );
		}

		// 過期後重試成功：之前的「帳密已被拒」是誤判
		CredentialStore::clear_legacy_account_rejected();
		return self::with_connection( $body, CloudClient::MODE_LEGACY );
	}

	/**
	 * 以 cloud.luke.cafe 帳密登入
	 *
	 * @param string $email    email 或帳號
	 * @param string $password 密碼
	 * @return array<string, mixed>
	 */
	public static function connect( string $email, string $password ): array {
		if ( ! self::may_send_real_password() ) {
			return self::gate_blocked();
		}

		$exchange = self::exchange( $email, $password );

		if ( self::EXCHANGE_RATE_LIMITED === $exchange['result'] ) {
			return self::gate_blocked();
		}

		if ( self::EXCHANGE_OK === $exchange['result'] ) {
			$identity = $exchange['identity'] ?? [];
			self::after_login( $identity );
			CredentialStore::delete_legacy_account();
			self::set_migration( self::MIGRATION_DONE );
			return self::with_connection(
				[
					'status'  => 200,
					'message' => 'success',
					'data'    => $identity,
				],
				CloudClient::MODE_DEALER
			);
		}

		if ( self::EXCHANGE_BAD_PASSWORD === $exchange['result'] ) {
			return [
				'status'     => 401,
				'message'    => (string) $exchange['message'],
				'connection' => [ 'mode' => CloudClient::MODE_NONE ],
			];
		}

		// 需要互動式驗證：回傳 CloudServer 的說明，不退回舊流程（identity 也一樣過不了）
		if ( self::EXCHANGE_LOGIN_CHALLENGE === $exchange['result'] ) {
			return self::login_challenge_response( (string) $exchange['message'] );
		}

		// CloudServer 尚未支援 / 帳號不符資格 / 暫時錯誤 → 舊流程，與 3.5.x 行為相同（又一次真實密碼請求，同樣過閘門）
		$response = self::send_legacy_identity( $email, $password );
		if ( self::is_gated( $response ) || self::is_rate_limited( $response ) ) {
			return self::gate_blocked();
		}
		if ( self::is_login_challenge( $response ) ) {
			return self::login_challenge_response( (string) ( self::decode( $response )['message'] ?? '' ) );
		}
		$body = self::identity_body( $response );
		if ( 200 !== (int) ( $body['status'] ?? 0 ) ) {
			return [
				'status'     => (int) ( $body['status'] ?? 500 ),
				'message'    => (string) ( $body['message'] ?? self::BAD_CREDENTIALS_MESSAGE ),
				'connection' => [ 'mode' => CloudClient::MODE_NONE ],
			];
		}

		CredentialStore::save_legacy_account( $email, $password );
		self::after_login( Arr::assoc( $body['data'] ?? null ) );
		self::set_migration(
			self::EXCHANGE_NOT_ELIGIBLE === $exchange['result'] ? self::MIGRATION_FAILED : self::MIGRATION_PENDING,
			(string) $exchange['result'],
			true // 手動重新登入 = 照提示做了，pending 天數重新起算
		);

		return self::with_connection( $body, CloudClient::MODE_LEGACY );
	}

	/**
	 * 登出：盡力撤銷 CloudServer 上這個站的專屬密碼，再清除本地所有憑證
	 *
	 * @return void
	 */
	public static function disconnect(): void {
		if ( null !== CredentialStore::get_dealer() ) {
			$response = CloudClient::instance()->request_with_mode( CloudClient::MODE_DEALER, 'DELETE', 'v3/connect', [], [ 'timeout' => 10 ] );
			if ( \is_wp_error( $response ) || (int) \wp_remote_retrieve_response_code( $response ) >= 300 ) {
				Plugin::logger( '登出時撤銷 CloudServer 專屬密碼失敗，仍清除本地憑證', 'warning', [ 'error_code' => CloudClient::error_code( $response ) ] );
			}
		}

		CredentialStore::delete_dealer();
		CredentialStore::delete_legacy_account();
		\delete_option( Connect::PARTNER_ID_OPTION_NAME );
		\delete_option( self::MIGRATION_OPTION );
		\delete_transient( Fetch::ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY );
	}

	/**
	 * 用登入帳密向 CloudServer 換取本站專屬應用程式密碼（成功時直接存好）
	 *
	 * @param string      $email               email 或帳號
	 * @param string      $password            密碼
	 * @param string|null $expected_partner_id 換到的帳號必須是這個經銷商，否則不保存並撤銷（自動遷移用；手動登入傳 null，以登入者為準）
	 * @return array{result: string, message: string, identity?: array<string, mixed>}
	 */
	public static function exchange( string $email, string $password, ?string $expected_partner_id = null ): array {
		$installation_id = self::installation_id();
		$response        = self::send_with_real_password(
			static fn() => CloudClient::instance()->request_with_mode(
				CloudClient::MODE_NONE,
				'POST',
				'v3/connect',
				[
					'login'           => $email,
					'password'        => $password,
					'site_url'        => \site_url(),
					'installation_id' => $installation_id,
					'client_version'  => (string) Plugin::$version,
				]
			),
			self::SEND_CONNECT
		);

		if ( self::is_gated( $response ) ) {
			return self::exchange_result( self::EXCHANGE_RATE_LIMITED, self::GATE_MESSAGE );
		}
		if ( \is_wp_error( $response ) ) {
			return self::exchange_result( self::EXCHANGE_TEMPORARY, $response->get_error_message() );
		}

		$status  = (int) \wp_remote_retrieve_response_code( $response );
		$body    = self::decode( $response );
		$code    = CloudClient::error_code( $response );
		$message = (string) ( $body['message'] ?? '' );

		if ( 404 === $status && 'rest_no_route' === $code ) {
			return self::exchange_result( self::EXCHANGE_UNSUPPORTED, $message );
		}
		// 429 一律是暫時性的（CloudServer 限流；IP 被封鎖、驗證碼、2FA 也以 429 回覆），不可當成帳密錯誤
		if ( self::is_rate_limited( $response ) ) {
			return self::exchange_result( self::EXCHANGE_RATE_LIMITED, self::GATE_MESSAGE );
		}
		if ( self::is_login_challenge( $response ) ) {
			return self::exchange_result( self::EXCHANGE_LOGIN_CHALLENGE, $message );
		}
		if ( 403 === $status && 'not_eligible' === $code ) {
			return self::exchange_result( self::EXCHANGE_NOT_ELIGIBLE, $message );
		}
		if ( 401 === $status ) {
			return self::exchange_result( self::EXCHANGE_BAD_PASSWORD, $message ?: 'email or password invalid' );
		}
		if ( $status < 200 || $status >= 300 ) {
			return self::exchange_result( self::EXCHANGE_TEMPORARY, $message );
		}

		$data         = is_array( $body['data'] ?? null ) ? Arr::assoc( $body['data'] ) : $body;
		$user_login   = (string) ( $data['user_login'] ?? '' );
		$app_password = (string) ( $data['app_password'] ?? '' );
		$identity     = Arr::assoc( $data['identity'] ?? null );
		if ( '' === $user_login || '' === $app_password ) {
			Plugin::logger( 'v3/connect 回應缺少 user_login / app_password，視為暫時錯誤', 'error', [ 'status' => $status ] );
			return self::exchange_result( self::EXCHANGE_TEMPORARY, 'invalid response' );
		}

		$partner_id = (string) ( $identity['user_id'] ?? '' );
		if ( null !== $expected_partner_id && '' !== $expected_partner_id && $partner_id !== $expected_partner_id ) {
			// 保存的話之後每個請求都會被 CloudServer 以 partner_mismatch 拒絕（而且刻意不 fallback），整站斷線
			self::revoke_unsaved( $user_login, $app_password );
			Plugin::logger(
				'自動遷移換到的帳號不是本站綁定的經銷商，不保存並撤銷這把專屬密碼',
				'warning',
				[
					'partner_id'     => $expected_partner_id,
					'got_partner_id' => $partner_id,
				]
			);
			return self::exchange_result( self::EXCHANGE_PARTNER_MISMATCH, '本站綁定的經銷商與登入帳號不同' );
		}

		$saved = CredentialStore::save_dealer(
			[
				'user_login'   => $user_login,
				'app_password' => $app_password,
				'uuid'         => (string) ( $data['uuid'] ?? '' ),
				'partner_id'   => $partner_id,
			]
		);
		if ( ! $saved ) {
			return self::exchange_result( self::EXCHANGE_TEMPORARY, 'failed to store credentials' );
		}

		return [
			'result'   => self::EXCHANGE_OK,
			'message'  => '',
			'identity' => $identity,
		];
	}

	/**
	 * 盡力撤銷一把剛換到、但不保存的專屬密碼，避免在 CloudServer 留下沒人用的密碼
	 *
	 * @param string $user_login   專屬帳號
	 * @param string $app_password 專屬應用程式密碼
	 * @return void
	 */
	private static function revoke_unsaved( string $user_login, string $app_password ): void {
		$response = CloudClient::instance()->request_with_mode(
			CloudClient::MODE_DEALER,
			'DELETE',
			'v3/connect',
			[],
			[
				'timeout'       => 10,
				'authorization' => 'Basic ' . base64_encode( "{$user_login}:{$app_password}" ), // phpcs:ignore
			]
		);
		if ( ! CloudClient::is_success( $response ) ) {
			Plugin::logger( '撤銷未保存的專屬密碼失敗', 'warning', [ 'error_code' => CloudClient::error_code( $response ) ] );
		}
	}

	/**
	 * 本站安裝識別；站台 host 改變（被 clone）時重新產生，避免複製站撤銷到原站的專屬密碼
	 *
	 * @return string
	 */
	public static function installation_id(): string {
		$host   = strtolower( (string) \wp_parse_url( \home_url(), PHP_URL_HOST ) );
		$stored = \get_option( self::INSTALLATION_OPTION, [] );
		if ( is_array( $stored ) && ( $stored['host'] ?? '' ) === $host && is_string( $stored['id'] ?? null ) && '' !== $stored['id'] ) {
			return $stored['id'];
		}

		$id = \wp_generate_uuid4();
		\update_option(
			self::INSTALLATION_OPTION,
			[
				'id'   => $id,
				'host' => $host,
			],
			false
		);
		return $id;
	}

	/**
	 * 自動遷移狀態
	 *
	 * @return array{status: string, reason: string, at: int, since: int} since = 連續停在 pending 的起點（其他狀態為 0）
	 */
	public static function migration(): array {
		$stored = \get_option( self::MIGRATION_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$status = (string) ( $stored['status'] ?? '' );
		if ( '' === $status ) {
			$status = null !== CredentialStore::get_dealer() ? self::MIGRATION_DONE : self::MIGRATION_NONE;
		}
		return [
			'status' => $status,
			'reason' => (string) ( $stored['reason'] ?? '' ),
			'at'     => (int) ( $stored['at'] ?? 0 ),
			'since'  => self::MIGRATION_PENDING === $status ? (int) ( $stored['since'] ?? $stored['at'] ?? 0 ) : 0,
		];
	}

	/**
	 * @param string $status  狀態
	 * @param string $reason  原因
	 * @param bool   $restart pending 天數是否重新起算（手動重新登入時）；否則連續的 pending 保留原本的起點
	 * @return void
	 */
	public static function set_migration( string $status, string $reason = '', bool $restart = false ): void {
		$previous = self::migration();
		$since    = 0;
		if ( self::MIGRATION_PENDING === $status ) {
			$since = ! $restart && self::MIGRATION_PENDING === $previous['status'] && $previous['since'] > 0 ? $previous['since'] : time();
		}
		\update_option(
			self::MIGRATION_OPTION,
			[
				'status' => $status,
				'reason' => $reason,
				'at'     => time(),
				'since'  => $since,
			],
			false
		);
	}

	/**
	 * CloudServer 是否回覆「這個帳號需要互動式驗證」（見 LOGIN_CHALLENGE_CODE）
	 *
	 * @param array<string, mixed>|\WP_Error $response 回應
	 * @return bool
	 */
	public static function is_login_challenge( array|\WP_Error $response ): bool {
		if ( \is_wp_error( $response ) ) {
			return false;
		}
		$http   = (int) \wp_remote_retrieve_response_code( $response );
		$status = (int) ( self::decode( $response )['status'] ?? $http );
		return ( 403 === $http || 403 === $status ) && self::LOGIN_CHALLENGE_CODE === CloudClient::error_code( $response );
	}

	/**
	 * 手動登入遇到 login_challenge：回傳 CloudServer 的說明
	 *
	 * @param string $message CloudServer 的 message
	 * @return array<string, mixed>
	 */
	private static function login_challenge_response( string $message ): array {
		return [
			'status'     => 403,
			'message'    => '' !== $message ? $message : '此帳號需要登入驗證碼或兩步驟驗證，無法在這裡登入，請聯繫站長路可協助。',
			'connection' => [ 'mode' => CloudClient::MODE_NONE ],
		];
	}

	/**
	 * 舊流程：以共用帳號呼叫 `POST identity` 驗證經銷商帳密（經過真實密碼閘門）
	 *
	 * @param string $email    email 或帳號
	 * @param string $password 密碼
	 * @return array<string, mixed>|\WP_Error CloudServer 回應；被閘門擋下時是 GATE_ERROR_CODE 的 WP_Error
	 */
	private static function send_legacy_identity( string $email, string $password ): array|\WP_Error {
		return self::send_with_real_password(
			static fn() => CloudClient::instance()->request_with_mode(
				CloudClient::MODE_LEGACY,
				'POST',
				'identity',
				[
					'email'    => $email,
					'password' => $password,
				]
			),
			self::SEND_IDENTITY
		);
	}

	/**
	 * `POST identity` 回應的 body（`{status, message, data}`）；body 沒有 status 時以 HTTP status 補上
	 *
	 * @param array<string, mixed>|\WP_Error $response 回應
	 * @return array<string, mixed>
	 */
	private static function identity_body( array|\WP_Error $response ): array {
		if ( \is_wp_error( $response ) ) {
			return [
				'status'  => 500,
				'message' => $response->get_error_message(),
			];
		}

		$body = self::decode( $response );
		if ( ! isset( $body['status'] ) ) {
			$body['status'] = (int) \wp_remote_retrieve_response_code( $response );
		}
		return $body;
	}

	/**
	 * CloudServer 是否明確回覆「經銷商真實密碼錯誤」
	 *
	 * 只認 CloudServer 驗證真實密碼失敗時的固定回應：`POST identity` 是 HTTP 200 包著 status 500，
	 * 點數明細是 HTTP 401，兩者 message 都是 BAD_CREDENTIALS_MESSAGE。共用帳號被撤銷時 core 也回 401，
	 * 但 message 不同、真實密碼根本沒被驗證，不算在內。429 一律不算（限流、IP 被封鎖、驗證碼、2FA）。
	 *
	 * @param array<string, mixed>|\WP_Error $response 回應
	 * @return bool
	 */
	public static function is_password_rejected( array|\WP_Error $response ): bool {
		if ( \is_wp_error( $response ) || self::is_rate_limited( $response ) ) {
			return false;
		}
		$body = self::decode( $response );
		if ( self::BAD_CREDENTIALS_MESSAGE !== ( $body['message'] ?? null ) ) {
			return false;
		}
		$http = (int) \wp_remote_retrieve_response_code( $response );
		return 401 === $http || ( $http >= 200 && $http < 300 && 500 === (int) ( $body['status'] ?? 0 ) );
	}

	/**
	 * 回應是否為 429（HTTP status 或 body 的 status）
	 *
	 * @param array<string, mixed>|\WP_Error $response 回應
	 * @return bool
	 */
	public static function is_rate_limited( array|\WP_Error $response ): bool {
		if ( \is_wp_error( $response ) ) {
			return false;
		}
		return 429 === (int) \wp_remote_retrieve_response_code( $response )
			|| 429 === (int) ( self::decode( $response )['status'] ?? 0 );
	}

	/**
	 * 送出一次帶經銷商真實密碼的請求：先過閘門，送出後記錄結果（見 REAL_PASSWORD_GATE_OPTION）
	 *
	 * @param callable(): (array<string, mixed>|\WP_Error) $send 實際送出請求
	 * @param string                                      $kind SEND_CONNECT / SEND_IDENTITY / SEND_DETAIL
	 * @return array<string, mixed>|\WP_Error 被閘門擋下時是 GATE_ERROR_CODE 的 WP_Error（沒有送出）
	 */
	public static function send_with_real_password( callable $send, string $kind ): array|\WP_Error {
		if ( ! self::may_send_real_password() ) {
			return new \WP_Error( self::GATE_ERROR_CODE, self::GATE_MESSAGE );
		}
		$response = $send();
		self::record_real_password_outcome( $response, $kind );
		return $response;
	}

	/**
	 * @param array<string, mixed>|\WP_Error $response send_with_real_password() 的回傳值
	 * @return bool 是否被閘門擋下（沒有送出）
	 */
	public static function is_gated( array|\WP_Error $response ): bool {
		return \is_wp_error( $response ) && self::GATE_ERROR_CODE === $response->get_error_code();
	}

	/**
	 * 閘門是否允許送出真實密碼
	 *
	 * @return bool
	 */
	public static function may_send_real_password(): bool {
		$gate = self::gate();
		if ( time() < $gate['cooldown_until'] ) {
			return false;
		}
		return count( $gate['failures'] ) < self::REAL_PASSWORD_MAX_FAILURES;
	}

	/**
	 * 記錄一次真實密碼請求的結果：429 → 冷卻 30 分鐘；帳密失敗 → 計入滑動視窗
	 *
	 * 每「送出一次」各自判斷（connect() 退回舊流程時的 v3/connect 與 identity 是兩次）。連線錯誤
	 * （WP_Error，CloudServer 沒收到）不計；成功不清除先前的失敗——Fluent Security 也不會。
	 *
	 * @param array<string, mixed>|\WP_Error $response 回應
	 * @param string                         $kind     SEND_CONNECT / SEND_IDENTITY / SEND_DETAIL
	 * @return void
	 */
	public static function record_real_password_outcome( array|\WP_Error $response, string $kind ): void {
		// 連線錯誤：CloudServer 沒收到。login_challenge：永久性的，由呼叫端寫停止標記，不算帳密失敗也不冷卻
		if ( \is_wp_error( $response ) || self::is_login_challenge( $response ) ) {
			return;
		}
		$gate = self::gate();
		if ( self::is_rate_limited( $response ) ) {
			$gate['cooldown_until'] = time() + self::REAL_PASSWORD_COOLDOWN;
		} elseif ( self::counts_as_password_failure( $response, $kind ) ) {
			$gate['failures'][] = time();
		} else {
			return;
		}
		\update_option( self::REAL_PASSWORD_GATE_OPTION, $gate, false );
	}

	/**
	 * 這次真實密碼請求是否算一次失敗（CloudServer 的 wp_authenticate() 可能失敗了）
	 *
	 * @param array<string, mixed> $response 回應
	 * @param string               $kind     SEND_CONNECT / SEND_IDENTITY / SEND_DETAIL
	 * @return bool
	 */
	private static function counts_as_password_failure( array $response, string $kind ): bool {
		$http = (int) \wp_remote_retrieve_response_code( $response );
		if ( self::SEND_DETAIL === $kind ) {
			// 明細在驗證通過之後才有 403 / 404，那些不是帳密失敗
			return 401 === $http;
		}
		if ( self::SEND_CONNECT === $kind ) {
			if ( $http >= 200 && $http < 300 ) {
				return false;
			}
			$code = CloudClient::error_code( $response );
			// 沒有路由 = 沒驗證；不符資格 = 驗證已通過
			return ! ( 404 === $http && 'rest_no_route' === $code ) && ! ( 403 === $http && 'not_eligible' === $code );
		}
		return 200 !== (int) ( self::decode( $response )['status'] ?? $http );
	}

	/**
	 * @return array{failures: array<int, int>, cooldown_until: int} 已移除視窗外的失敗
	 */
	private static function gate(): array {
		$stored   = \get_option( self::REAL_PASSWORD_GATE_OPTION, [] );
		$stored   = is_array( $stored ) ? $stored : [];
		$since    = time() - self::REAL_PASSWORD_WINDOW;
		$failures = [];
		foreach ( is_array( $stored['failures'] ?? null ) ? $stored['failures'] : [] as $at ) {
			if ( is_numeric( $at ) && (int) $at > $since ) {
				$failures[] = (int) $at;
			}
		}
		return [
			'failures'       => $failures,
			'cooldown_until' => (int) ( $stored['cooldown_until'] ?? 0 ),
		];
	}

	/**
	 * 被閘門擋下（或 CloudServer 回 429）時 connect() 的回應
	 *
	 * @return array<string, mixed>
	 */
	private static function gate_blocked(): array {
		return [
			'status'     => 429,
			'message'    => self::GATE_MESSAGE,
			'connection' => [ 'mode' => CloudClient::MODE_NONE ],
		];
	}

	/**
	 * 需要經銷商處理的連線問題；wp-admin notice 與 AdminApp 共用這個判斷
	 *
	 * 「目前功能不受影響」只在最近 24 小時內真的看過共用帳號請求成功時才說（CredentialStore::legacy_ok_recently()）：
	 * 根除後專屬密碼被撤銷時，status() 不會去試共用帳號，不能靠「沒看過它失敗」推論它還能用。
	 * CloudServer 停用共用帳號、本站也沒有可用的專屬密碼時，是真的斷線了，文案是「雲端連線已中斷」。
	 *
	 * @return array{code: string, message: string, relogin: bool, scope: string}|null relogin = 重新登入能解決；scope 見 SCOPE_*
	 */
	public static function attention(): ?array {
		$migration     = self::migration();
		$legacy_down   = CredentialStore::is_legacy_disabled();
		$dealer_usable = null !== CredentialStore::get_dealer() && ! CredentialStore::is_dealer_invalid();
		$unaffected    = CredentialStore::legacy_ok_recently() ? '目前功能不受影響。' : '';

		$rejected      = CredentialStore::legacy_account_rejected_reason();

		if ( ! $dealer_usable && self::MIGRATION_FAILED === $migration['status'] && self::EXCHANGE_PARTNER_MISMATCH === $migration['reason'] ) {
			return self::attention_item( 'partner_mismatch', '本站綁定的經銷商與登入帳號不同，請重新登入。', true );
		}
		// 需要互動式驗證：重新登入也一樣過不了，要站長路可協助。共用帳號停用後就是真的斷線，改成全域顯示
		if (
			! $dealer_usable
			&& ( CredentialStore::REJECTED_LOGIN_CHALLENGE === $rejected
				|| ( self::MIGRATION_FAILED === $migration['status'] && self::EXCHANGE_LOGIN_CHALLENGE === $migration['reason'] ) )
		) {
			return self::attention_item(
				'login_challenge',
				'此帳號啟用了登入驗證碼或兩步驟驗證，無法自動換成專屬密碼，請聯繫站長路可協助。',
				false,
				$legacy_down ? self::SCOPE_GLOBAL : self::SCOPE_POWER_PARTNER
			);
		}
		if ( ! $dealer_usable && self::MIGRATION_FAILED === $migration['status'] && self::EXCHANGE_NOT_ELIGIBLE === $migration['reason'] ) {
			return self::attention_item( 'not_eligible', '此帳號目前不符合經銷商資格，共用帳號停用後將無法使用雲端功能，請聯繫站長路可。', false );
		}
		if ( $legacy_down && ! $dealer_usable ) {
			return self::attention_item( 'disconnected', '雲端連線已中斷，請重新登入。', true );
		}
		if (
			( '' !== $rejected && CredentialStore::REJECTED_LOGIN_CHALLENGE !== $rejected )
			|| ( self::MIGRATION_FAILED === $migration['status'] && self::EXCHANGE_BAD_PASSWORD === $migration['reason'] )
		) {
			return self::attention_item( 'credentials_rejected', "雲端帳密已失效，請重新登入一次。{$unaffected}", true );
		}
		if ( CredentialStore::is_dealer_invalid() ) {
			return self::attention_item( 'dealer_rejected', "站長路可拒絕了本站的專屬金鑰，請重新登入一次。{$unaffected}", true );
		}
		// 遷移一直停在 pending（不論原因，例如每天都 429）：背景自己好不了，請經銷商手動試一次
		if (
			! $dealer_usable
			&& self::MIGRATION_PENDING === $migration['status']
			&& $migration['since'] > 0
			&& ( time() - $migration['since'] ) >= self::MIGRATION_STUCK_AFTER
		) {
			return self::attention_item( 'migration_stuck', '已連續 3 天無法自動換成專屬密碼，請到 Power Partner 重新登入一次；若仍失敗請聯繫站長路可。', true, self::SCOPE_POWER_PARTNER );
		}
		// 有 partner_id 卻讀不到任何憑證（salts 重產、站台 host 改變、帳密只存在瀏覽器的很早期站）：只靠寫死的共用帳號在運作。
		// 模板站或 clone 站也可能帶著 partner_id，終端客戶看不懂，所以只在 Power Partner 頁面與 wp-admin 首頁提示
		if (
			'' !== (string) \get_option( Connect::PARTNER_ID_OPTION_NAME, '' )
			&& null === CredentialStore::get_dealer()
			&& null === CredentialStore::get_legacy_account()
		) {
			$suffix = '' !== $unaffected ? '（目前功能不受影響）' : '';
			return self::attention_item( 'shared_only', "目前以共用帳號連線，共用帳號即將停用，請重新登入一次以換成專屬密碼{$suffix}。", true, self::SCOPE_POWER_PARTNER );
		}
		return null;
	}

	/**
	 * @param string $code    代碼
	 * @param string $message 文案
	 * @param bool   $relogin 重新登入能否解決
	 * @param string $scope   顯示範圍
	 * @return array{code: string, message: string, relogin: bool, scope: string}
	 */
	private static function attention_item( string $code, string $message, bool $relogin, string $scope = self::SCOPE_GLOBAL ): array {
		return [
			'code'    => $code,
			'message' => $message,
			'relogin' => $relogin,
			'scope'   => $scope,
		];
	}

	/**
	 * 保存的帳密已失效：AdminApp 顯示登入畫面並提示原因
	 *
	 * @return array<string, mixed>
	 */
	private static function relogin_required(): array {
		$message = self::attention()['message'] ?? '雲端連線需要重新登入。';
		return [
			'status'     => 401,
			'message'    => $message,
			'connection' => [
				'mode'      => CloudClient::MODE_LEGACY,
				'migration' => self::MIGRATION_FAILED,
				'message'   => $message,
			],
		];
	}

	/**
	 * 閘門擋下或 CloudServer 回 429：這次無法驗證帳密，與帳密對錯無關
	 *
	 * @return array<string, mixed>
	 */
	private static function temporarily_unverifiable(): array {
		$message = '站長路可暫時無法驗證帳密，請 30 分鐘後再試。' . ( CredentialStore::legacy_ok_recently() ? '目前功能不受影響。' : '' );
		return [
			'status'     => 429,
			'message'    => $message,
			'connection' => [
				'mode'    => CloudClient::MODE_LEGACY,
				'message' => $message,
			],
		];
	}

	/**
	 * 登入成功後同步本地狀態（與舊版前端在登入後呼叫 POST /partner-id 做的事相同）
	 *
	 * @param array<string, mixed> $identity CloudServer identity data
	 * @return void
	 */
	private static function after_login( array $identity ): void {
		$partner_id = (string) ( $identity['user_id'] ?? '' );
		if ( '' !== $partner_id ) {
			\update_option( Connect::PARTNER_ID_OPTION_NAME, $partner_id );
		}
		if ( is_array( $identity['allowed_template_options'] ?? null ) ) {
			\set_transient( Fetch::ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY, $identity['allowed_template_options'] );
		}
	}

	/**
	 * @param array<string, mixed> $body CloudServer 回應 body
	 * @param string               $mode 認證模式
	 * @return array<string, mixed>
	 */
	private static function with_connection( array $body, string $mode ): array {
		$migration          = self::migration();
		$attention          = self::attention();
		$body['connection'] = [
			'mode'      => $mode,
			// 前端只在 failed 時顯示「重新登入」；重新登入解決不了的（不符資格）回報為 none
			'migration' => ( $attention['relogin'] ?? false ) ? self::MIGRATION_FAILED : ( self::MIGRATION_FAILED === $migration['status'] ? self::MIGRATION_NONE : $migration['status'] ),
			'message'   => $attention['message'] ?? '',
		];
		return $body;
	}

	/**
	 * @param string $message 訊息
	 * @return array<string, mixed>
	 */
	private static function not_connected( string $message = '' ): array {
		return [
			'status'     => 401,
			'message'    => $message,
			'connection' => [ 'mode' => CloudClient::MODE_NONE ],
		];
	}

	/**
	 * @param string $result  結果
	 * @param string $message 訊息
	 * @return array{result: string, message: string}
	 */
	private static function exchange_result( string $result, string $message ): array {
		return [
			'result'  => $result,
			'message' => $message,
		];
	}

	/**
	 * @param array<string, mixed>|\WP_Error $response 回應
	 * @return array<string, mixed>
	 */
	private static function decode( array|\WP_Error $response ): array {
		if ( \is_wp_error( $response ) ) {
			return [];
		}
		return Arr::from_json( (string) \wp_remote_retrieve_body( $response ) );
	}
}
