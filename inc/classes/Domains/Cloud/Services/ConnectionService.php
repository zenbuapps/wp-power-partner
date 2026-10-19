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

		$body = self::legacy_identity( $account['email'], $account['password'] );
		if ( 200 !== (int) ( $body['status'] ?? 0 ) ) {
			// 帳密已失效（經銷商改過密碼）→ 與舊版行為一致：請他重新登入
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
		$exchange = self::exchange( $email, $password );

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

		if ( in_array( $exchange['result'], [ self::EXCHANGE_BAD_PASSWORD, self::EXCHANGE_RATE_LIMITED ], true ) ) {
			return [
				'status'     => 401,
				'message'    => (string) $exchange['message'],
				'connection' => [ 'mode' => CloudClient::MODE_NONE ],
			];
		}

		// CloudServer 尚未支援 / 帳號不符資格 / 暫時錯誤 → 舊流程，與 3.5.x 行為相同
		$body = self::legacy_identity( $email, $password );
		if ( 200 !== (int) ( $body['status'] ?? 0 ) ) {
			return [
				'status'     => (int) ( $body['status'] ?? 500 ),
				'message'    => (string) ( $body['message'] ?? 'email or password invalid' ),
				'connection' => [ 'mode' => CloudClient::MODE_NONE ],
			];
		}

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
	 * @return array<string, mixed> CloudServer 回應 body（`{status, message, data}`）
	 */
	private static function legacy_identity( string $email, string $password ): array {
		$response = CloudClient::instance()->request_with_mode(
			CloudClient::MODE_LEGACY,
			'POST',
			'identity',
			[
				'email'    => $email,
				'password' => $password,
			]
		);

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
		$migration = self::migration();
		// 只有「重新登入就能解決」時才提示；不符資格（not_eligible）重新登入也沒用，不打擾經銷商
		$relogin_helps      = CredentialStore::is_dealer_invalid()
			|| ( self::MIGRATION_FAILED === $migration['status'] && self::EXCHANGE_BAD_PASSWORD === $migration['reason'] );
		$body['connection'] = [
			'mode'      => $mode,
			// 前端只在 failed 時顯示「請重新登入」，不符資格時回報為 none
			'migration' => $relogin_helps ? self::MIGRATION_FAILED : ( self::MIGRATION_FAILED === $migration['status'] ? self::MIGRATION_NONE : $migration['status'] ),
			'message'   => $relogin_helps ? '尚未完成新版安全連線，請重新登入一次以啟用專屬金鑰；目前功能不受影響。' : '',
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
