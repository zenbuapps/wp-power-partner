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
	const EXCHANGE_OK            = 'ok';
	const EXCHANGE_UNSUPPORTED   = 'unsupported';     // CloudServer 尚無 v3/connect
	const EXCHANGE_NOT_ELIGIBLE  = 'not_eligible';    // 帳號不符經銷商資格
	const EXCHANGE_BAD_PASSWORD  = 'bad_credentials'; // 帳密錯誤
	const EXCHANGE_RATE_LIMITED  = 'rate_limited';
	const EXCHANGE_TEMPORARY     = 'temporary';       // 連線錯誤、5xx

	/**
	 * @var string 本站送出經銷商真實密碼的失敗次數 `{count, since}`
	 *
	 * CloudServer 以 `wp_authenticate()` 驗證真實密碼，cloud.luke.cafe 的 Fluent Security 依來源 IP
	 * 計數（30 分鐘 5 次）並封鎖整個 IP，而這些請求的來源是本站主機。上限刻意比它低，留出安全邊際。
	 */
	const LOGIN_FAILURES_OPTION = 'power_partner_cloud_login_failures';

	/** @var int 視窗內最多幾次真實密碼失敗 */
	const LOGIN_MAX_FAILURES = 3;

	/** @var int 失敗次數的計算視窗（15 分鐘） */
	const LOGIN_FAILURE_WINDOW = 900;

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
					return self::with_connection( $body, CloudClient::MODE_DEALER );
				}
			}
		}

		$account = CredentialStore::get_legacy_account();
		if ( null === $account ) {
			return self::not_connected();
		}

		// 帳密被拒過就不再送出：每次打開 AdminApp 都重送一次，失敗次數會累積在本站主機的 IP 上
		if ( CredentialStore::is_legacy_account_rejected() ) {
			return self::relogin_required();
		}

		$response = self::send_legacy_identity( $account['email'], $account['password'] );
		$body     = self::identity_body( $response );
		if ( 200 !== (int) ( $body['status'] ?? 0 ) ) {
			// 帳密已失效（經銷商改過密碼）→ 記下來不再重送，請他重新登入
			if ( self::is_password_rejected( $response ) ) {
				CredentialStore::mark_legacy_account_rejected( self::EXCHANGE_BAD_PASSWORD );
				return self::relogin_required();
			}
			// 暫時錯誤（連線失敗、5xx）→ 與舊版行為一致：顯示登入畫面
			return self::not_connected( (string) ( $body['message'] ?? '' ) );
		}

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
		if ( self::login_throttled() ) {
			return [
				'status'     => 429,
				'message'    => '嘗試次數過多，請 15 分鐘後再試',
				'connection' => [ 'mode' => CloudClient::MODE_NONE ],
			];
		}

		$exchange = self::exchange( $email, $password );

		if ( self::EXCHANGE_BAD_PASSWORD === $exchange['result'] ) {
			self::record_login_failure();
		}

		if ( self::EXCHANGE_OK === $exchange['result'] ) {
			self::clear_login_failures();
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

		if ( in_array( $exchange['result'], [ self::EXCHANGE_BAD_PASSWORD, self::EXCHANGE_RATE_LIMITED ], true ) ) {
			return [
				'status'     => 401,
				'message'    => (string) $exchange['message'],
				'connection' => [ 'mode' => CloudClient::MODE_NONE ],
			];
		}

		// CloudServer 尚未支援 / 帳號不符資格 / 暫時錯誤 → 舊流程，與 3.5.x 行為相同
		$response = self::send_legacy_identity( $email, $password );
		$body     = self::identity_body( $response );
		if ( 200 !== (int) ( $body['status'] ?? 0 ) ) {
			if ( self::is_password_rejected( $response ) ) {
				self::record_login_failure();
			}
			return [
				'status'     => (int) ( $body['status'] ?? 500 ),
				'message'    => (string) ( $body['message'] ?? self::BAD_CREDENTIALS_MESSAGE ),
				'connection' => [ 'mode' => CloudClient::MODE_NONE ],
			];
		}

		self::clear_login_failures();
		CredentialStore::save_legacy_account( $email, $password );
		self::after_login( Arr::assoc( $body['data'] ?? null ) );
		self::set_migration(
			self::EXCHANGE_NOT_ELIGIBLE === $exchange['result'] ? self::MIGRATION_FAILED : self::MIGRATION_PENDING,
			(string) $exchange['result']
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
	 * @param string $email    email 或帳號
	 * @param string $password 密碼
	 * @return array{result: string, message: string, identity?: array<string, mixed>}
	 */
	public static function exchange( string $email, string $password ): array {
		$response = CloudClient::instance()->request_with_mode(
			CloudClient::MODE_NONE,
			'POST',
			'v3/connect',
			[
				'login'           => $email,
				'password'        => $password,
				'site_url'        => \site_url(),
				'installation_id' => self::installation_id(),
				'client_version'  => (string) Plugin::$version,
			]
		);

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
		if ( 429 === $status ) {
			return self::exchange_result( self::EXCHANGE_RATE_LIMITED, $message ?: '登入嘗試次數過多，請稍後再試' );
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

		$saved = CredentialStore::save_dealer(
			[
				'user_login'   => $user_login,
				'app_password' => $app_password,
				'uuid'         => (string) ( $data['uuid'] ?? '' ),
				'partner_id'   => (string) ( $identity['user_id'] ?? '' ),
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
	 * @return array{status: string, reason: string, at: int}
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
		];
	}

	/**
	 * @param string $status 狀態
	 * @param string $reason 原因
	 * @return void
	 */
	public static function set_migration( string $status, string $reason = '' ): void {
		\update_option(
			self::MIGRATION_OPTION,
			[
				'status' => $status,
				'reason' => $reason,
				'at'     => time(),
			],
			false
		);
	}

	/**
	 * 舊流程：以共用帳號呼叫 `POST identity` 驗證經銷商帳密
	 *
	 * @param string $email    email 或帳號
	 * @param string $password 密碼
	 * @return array<string, mixed>|\WP_Error CloudServer 回應
	 */
	private static function send_legacy_identity( string $email, string $password ): array|\WP_Error {
		return CloudClient::instance()->request_with_mode(
			CloudClient::MODE_LEGACY,
			'POST',
			'identity',
			[
				'email'    => $email,
				'password' => $password,
			]
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
	 * 但 message 不同、真實密碼根本沒被驗證，不算在內。
	 *
	 * @param array<string, mixed>|\WP_Error $response 回應
	 * @return bool
	 */
	public static function is_password_rejected( array|\WP_Error $response ): bool {
		if ( \is_wp_error( $response ) ) {
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
	 * 本站送出真實密碼的失敗次數是否已達上限（見 LOGIN_FAILURES_OPTION）
	 *
	 * @return bool
	 */
	public static function login_throttled(): bool {
		return self::login_failures()['count'] >= self::LOGIN_MAX_FAILURES;
	}

	/**
	 * 記一次真實密碼失敗
	 *
	 * @return void
	 */
	public static function record_login_failure(): void {
		$failures = self::login_failures();
		\update_option(
			self::LOGIN_FAILURES_OPTION,
			[
				'count' => $failures['count'] + 1,
				'since' => $failures['count'] > 0 ? $failures['since'] : time(),
			],
			false
		);
	}

	/**
	 * 登入成功時清除失敗次數
	 *
	 * @return void
	 */
	public static function clear_login_failures(): void {
		if ( false !== \get_option( self::LOGIN_FAILURES_OPTION, false ) ) {
			\delete_option( self::LOGIN_FAILURES_OPTION );
		}
	}

	/**
	 * @return array{count: int, since: int} 視窗已過時視為 0
	 */
	private static function login_failures(): array {
		$stored = \get_option( self::LOGIN_FAILURES_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$since  = (int) ( $stored['since'] ?? 0 );
		if ( time() - $since >= self::LOGIN_FAILURE_WINDOW ) {
			return [
				'count' => 0,
				'since' => 0,
			];
		}
		return [
			'count' => (int) ( $stored['count'] ?? 0 ),
			'since' => $since,
		];
	}

	/**
	 * 需要經銷商重新登入才能解決的連線問題；wp-admin notice 與 AdminApp 共用這個判斷
	 *
	 * @return array{code: string, message: string}|null
	 */
	public static function attention(): ?array {
		$migration = self::migration();
		if (
			CredentialStore::is_legacy_account_rejected()
			|| ( self::MIGRATION_FAILED === $migration['status'] && self::EXCHANGE_BAD_PASSWORD === $migration['reason'] )
		) {
			return [
				'code'    => 'credentials_rejected',
				'message' => '雲端帳密已失效，請重新登入一次。目前功能不受影響。',
			];
		}
		if ( CredentialStore::is_dealer_invalid() ) {
			return [
				'code'    => 'dealer_rejected',
				'message' => '尚未完成新版安全連線，請重新登入一次以啟用專屬金鑰；目前功能不受影響。',
			];
		}
		return null;
	}

	/**
	 * 保存的帳密已失效：AdminApp 顯示登入畫面並提示原因
	 *
	 * @return array<string, mixed>
	 */
	private static function relogin_required(): array {
		$message = self::attention()['message'] ?? '雲端帳密已失效，請重新登入一次。';
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
			// 前端只在 failed 時顯示提示；不需要經銷商處理的 failed 回報為 none
			'migration' => null !== $attention ? self::MIGRATION_FAILED : ( self::MIGRATION_FAILED === $migration['status'] ? self::MIGRATION_NONE : $migration['status'] ),
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
