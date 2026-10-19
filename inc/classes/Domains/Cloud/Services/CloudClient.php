<?php

declare(strict_types=1);

namespace J7\PowerPartner\Domains\Cloud\Services;

use J7\PowerPartner\Api\Connect;
use J7\PowerPartner\Api\Fetch;
use J7\PowerPartner\Bootstrap;
use J7\PowerPartner\Domains\Cloud\Shared\Arr;
use J7\PowerPartner\Plugin;

/**
 * 呼叫 CloudServer（power-partner-server）的唯一出口
 *
 * 認證模式每次請求自動選擇：
 * - dealer：本站已取得經銷商專屬應用程式密碼 → 用它（CloudServer 以已驗證的使用者推導經銷商身分）
 * - legacy：尚未取得 → 用寫死的共用帳號（過渡期，CloudServer 關閉 legacy 前都可用）
 *
 * dealer 被 CloudServer 以「憑證類」錯誤拒絕時，標記失效並**當次改用 legacy 重送**——
 * 向後相容原則：在 legacy 關閉前，任何一步遷移出錯都不能讓開站、停用、扣點、授權碼中斷。
 *
 * 公開方法的簽名刻意與 Powerhouse `J7\Powerhouse\Api\Base` 相同（remote_get / remote_post /
 * remote_delete），授權碼流程原本用那個 client，換過來時呼叫端不必改。
 */
final class CloudClient {
	use \J7\WpUtils\Traits\SingletonTrait;

	const MODE_DEALER = 'dealer';
	const MODE_LEGACY = 'legacy';

	/**
	 * 不帶 Authorization —— 給公開端點（v3/connect）用
	 *
	 * 公開端點也不能順手帶共用帳號：共用密碼一旦被 CloudServer 撤銷，core 會在 permission
	 * callback 之前就對整個請求回 401，連公開端點都打不進去。
	 */
	const MODE_NONE = 'none';

	/** @var string CloudServer REST namespace 路徑 */
	const API_PATH = '/wp-json/power-partner-server/';

	/** @var int 專屬密碼被拒後，多久內不再嘗試（1 小時；避免每個請求都先失敗一次） */
	const DEALER_RETRY_AFTER = 3600;

	/**
	 * 代表「憑證本身被拒」的 WP REST 錯誤代碼，收到時才 fallback 到 legacy
	 *
	 * ⛔ 不可加入 partner_mismatch / not_owner 這類「身分正確但無權操作」的代碼：
	 * 那是 CloudServer 正確地拒絕越權，換成 legacy 重送等於繞過檢查。
	 * （授權碼作者不符的窄例外另見 LEGACY_ON_NOT_OWNER_ENDPOINTS，需呼叫端明確開啟）
	 *
	 * @var array<int, string>
	 */
	const CREDENTIAL_REJECTED_CODES = [
		'incorrect_password',
		'invalid_username',
		'invalid_email',
		'application_passwords_disabled',
		'application_passwords_disabled_for_user',
		'rest_forbidden',
		'rest_not_logged_in',
		'auth_required',
	];

	/**
	 * 作者不符（not_owner）時允許以 legacy 重送一次的端點 —— 窄例外，還要呼叫端以 `legacy_on_not_owner` 明確開啟
	 *
	 * 只給授權碼生命週期（LifeCycle 重啟、ExpireHandler 停用）用：ids 來自本站訂閱的 `lc_id` meta，
	 * 不是瀏覽器輸入；存量授權碼的 post_author 可能不是本經銷商（例如在 CloudServer 後台建立後才綁到訂閱），
	 * 而這兩個操作 legacy 本來就允許。legacy 關閉後這條路自然失效，必須在那之前修正這批授權碼的 post_author。
	 *
	 * @var array<int, string>
	 */
	const LEGACY_ON_NOT_OWNER_ENDPOINTS = [
		'license-codes/recover',
		'license-codes/expire',
	];

	/** @var string 最後一次請求實際使用的模式（測試與 log 用） */
	public string $last_mode = '';

	/**
	 * 發送 GET 請求
	 *
	 * @param string                                           $endpoint   相對於 power-partner-server namespace 的路徑
	 * @param array<string, mixed>                             $url_params query 參數
	 * @param array{timeout?: int, legacy_on_not_owner?: bool} $args       timeout（秒）；legacy_on_not_owner 見 LEGACY_ON_NOT_OWNER_ENDPOINTS
	 * @return array<string, mixed>|\WP_Error
	 */
	public function remote_get( string $endpoint, array $url_params = [], array $args = [] ): array|\WP_Error {
		return $this->request( 'GET', $endpoint, $url_params, $args );
	}

	/**
	 * 發送 POST 請求
	 *
	 * @param string                                           $endpoint    相對於 power-partner-server namespace 的路徑
	 * @param array<string, mixed>                             $body_params JSON body
	 * @param array{timeout?: int, legacy_on_not_owner?: bool} $args        timeout（秒）；legacy_on_not_owner 見 LEGACY_ON_NOT_OWNER_ENDPOINTS
	 * @return array<string, mixed>|\WP_Error
	 */
	public function remote_post( string $endpoint, array $body_params = [], array $args = [] ): array|\WP_Error {
		return $this->request( 'POST', $endpoint, $body_params, $args );
	}

	/**
	 * 發送 DELETE 請求
	 *
	 * @param string                                           $endpoint    相對於 power-partner-server namespace 的路徑
	 * @param array<string, mixed>                             $body_params JSON body
	 * @param array{timeout?: int, legacy_on_not_owner?: bool} $args        timeout（秒）；legacy_on_not_owner 見 LEGACY_ON_NOT_OWNER_ENDPOINTS
	 * @return array<string, mixed>|\WP_Error
	 */
	public function remote_delete( string $endpoint, array $body_params = [], array $args = [] ): array|\WP_Error {
		return $this->request( 'DELETE', $endpoint, $body_params, $args );
	}

	/**
	 * 目前會優先使用的認證模式
	 *
	 * @return string self::MODE_DEALER | self::MODE_LEGACY
	 */
	public static function preferred_mode(): string {
		if ( null === CredentialStore::get_dealer() ) {
			return self::MODE_LEGACY;
		}

		$invalid = \get_option( CredentialStore::DEALER_INVALID_OPTION, false );
		if ( is_array( $invalid ) && ( time() - (int) ( $invalid['at'] ?? 0 ) ) < self::DEALER_RETRY_AFTER ) {
			return self::MODE_LEGACY;
		}

		return self::MODE_DEALER;
	}

	/**
	 * 指定模式的 Authorization header 值
	 *
	 * @param string $mode 認證模式
	 * @return string
	 */
	public static function authorization( string $mode ): string {
		if ( self::MODE_NONE === $mode ) {
			return '';
		}
		if ( self::MODE_DEALER === $mode ) {
			$dealer = CredentialStore::get_dealer();
			if ( null !== $dealer ) {
				return 'Basic ' . base64_encode( "{$dealer['user_login']}:{$dealer['app_password']}" ); // phpcs:ignore
			}
		}

		return 'Basic ' . base64_encode( Bootstrap::instance()->username . ':' . Bootstrap::instance()->psw ); // phpcs:ignore
	}

	/**
	 * 發送請求，必要時以 legacy 重送
	 *
	 * @param string                                           $method   HTTP method
	 * @param string                                           $endpoint 相對路徑
	 * @param array<string, mixed>                             $params   GET 為 query、其他為 JSON body
	 * @param array{timeout?: int, legacy_on_not_owner?: bool} $args     timeout（秒）；legacy_on_not_owner 見 LEGACY_ON_NOT_OWNER_ENDPOINTS
	 * @return array<string, mixed>|\WP_Error
	 */
	public function request( string $method, string $endpoint, array $params = [], array $args = [] ): array|\WP_Error {
		$mode     = self::preferred_mode();
		$response = $this->request_with_mode( $mode, $method, $endpoint, $params, $args );

		if ( self::MODE_DEALER === $mode && self::is_credential_rejected( $response ) ) {
			$code = self::error_code( $response );
			CredentialStore::mark_dealer_invalid( $code );
			Plugin::logger(
				'CloudServer 拒絕經銷商專屬密碼，改用共用帳號重送',
				'warning',
				[
					'endpoint'   => $endpoint,
					'error_code' => $code,
				]
			);
			$response = $this->request_with_mode( self::MODE_LEGACY, $method, $endpoint, $params, $args );
		} elseif ( self::MODE_DEALER === $mode && self::should_retry_not_owner_as_legacy( $endpoint, $response, $args ) ) {
			// 不是憑證問題，不標記專屬密碼失效
			Plugin::logger(
				'CloudServer 以 not_owner 拒絕本站訂閱的授權碼操作（授權碼作者不是本經銷商），暫以共用帳號重送；關閉 legacy 前請修正這批授權碼的 post_author',
				'warning',
				[
					'endpoint'   => $endpoint,
					'ids'        => $params['ids'] ?? [],
					'partner_id' => (string) \get_option( Connect::PARTNER_ID_OPTION_NAME, '' ),
				]
			);
			$response = $this->request_with_mode( self::MODE_LEGACY, $method, $endpoint, $params, $args );
		}

		return $response;
	}

	/**
	 * 是否符合「作者不符時以 legacy 重送」的窄例外：呼叫端明確開啟 + 白名單端點 + 403 not_owner
	 *
	 * @param string                                           $endpoint 相對路徑
	 * @param array<string, mixed>|\WP_Error                   $response dealer 模式的回應
	 * @param array{timeout?: int, legacy_on_not_owner?: bool} $args     呼叫端參數
	 * @return bool
	 */
	private static function should_retry_not_owner_as_legacy( string $endpoint, array|\WP_Error $response, array $args ): bool {
		if ( true !== ( $args['legacy_on_not_owner'] ?? false ) ) {
			return false;
		}
		if ( ! in_array( ltrim( $endpoint, '/' ), self::LEGACY_ON_NOT_OWNER_ENDPOINTS, true ) ) {
			return false;
		}
		if ( \is_wp_error( $response ) || 403 !== (int) \wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}
		return 'not_owner' === self::error_code( $response );
	}

	/**
	 * 回應是否為 HTTP 2xx
	 *
	 * CloudServer 的業務錯誤（403 not_owner、401 legacy_auth_disabled…）回來時不是 WP_Error，
	 * 只看 is_wp_error() 會把它們當成成功。
	 *
	 * @param array<string, mixed>|\WP_Error $response 回應
	 * @return bool
	 */
	public static function is_success( array|\WP_Error $response ): bool {
		if ( \is_wp_error( $response ) ) {
			return false;
		}
		$status = (int) \wp_remote_retrieve_response_code( $response );
		return $status >= 200 && $status < 300;
	}

	/**
	 * 失敗回應的說明文字（訂單備註與 log 用）
	 *
	 * WP_Error 維持只回 message（與既有備註相同）；HTTP 錯誤帶 status、錯誤代碼與上游 message。
	 *
	 * @param array<string, mixed>|\WP_Error $response 回應
	 * @return string
	 */
	public static function failure_message( array|\WP_Error $response ): string {
		if ( \is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		$status  = (int) \wp_remote_retrieve_response_code( $response );
		$code    = self::error_code( $response );
		$body    = Arr::from_json( (string) \wp_remote_retrieve_body( $response ) );
		$message = is_string( $body['message'] ?? null ) ? $body['message'] : '';

		$text = "HTTP {$status}";
		if ( '' !== $code ) {
			$text .= " {$code}";
		}
		if ( '' !== $message ) {
			$text .= "：{$message}";
		}
		return $text;
	}

	/**
	 * 回應是否代表「憑證本身被拒」
	 *
	 * @param array<string, mixed>|\WP_Error $response 回應
	 * @return bool
	 */
	public static function is_credential_rejected( array|\WP_Error $response ): bool {
		if ( \is_wp_error( $response ) ) {
			return false;
		}
		$status = (int) \wp_remote_retrieve_response_code( $response );
		if ( 401 !== $status && 403 !== $status ) {
			return false;
		}
		return in_array( self::error_code( $response ), self::CREDENTIAL_REJECTED_CODES, true );
	}

	/**
	 * 取出回應的錯誤代碼：優先 data.error_code（CloudServer 契約），其次 WP REST 的 code
	 *
	 * @param array<string, mixed>|\WP_Error $response 回應
	 * @return string
	 */
	public static function error_code( array|\WP_Error $response ): string {
		if ( \is_wp_error( $response ) ) {
			return (string) $response->get_error_code();
		}
		$body       = Arr::from_json( (string) \wp_remote_retrieve_body( $response ) );
		$error_code = Arr::assoc( $body['data'] ?? null )['error_code'] ?? null;
		if ( is_string( $error_code ) && '' !== $error_code ) {
			return $error_code;
		}
		return is_string( $body['code'] ?? null ) ? $body['code'] : '';
	}

	/**
	 * 以指定模式送出一次請求（不做 fallback）
	 *
	 * 「查身分」這類請求必須用這個：自動 fallback 到 legacy 會查到共用帳號自己的身分。
	 *
	 * @param string                                           $mode     認證模式
	 * @param string                                           $method   HTTP method
	 * @param string                                           $endpoint 相對路徑
	 * @param array<string, mixed>                             $params   GET 為 query、其他為 JSON body
	 * @param array{timeout?: int, legacy_on_not_owner?: bool} $args     timeout（秒）；這裡不處理 legacy_on_not_owner
	 * @return array<string, mixed>|\WP_Error
	 */
	public function request_with_mode( string $mode, string $method, string $endpoint, array $params = [], array $args = [] ): array|\WP_Error {
		$this->last_mode = $mode;

		$url = Bootstrap::instance()->base_url . self::API_PATH . ltrim( $endpoint, '/' );

		$headers       = Fetch::cloud_headers();
		$authorization = self::authorization( $mode );
		if ( '' === $authorization ) {
			unset( $headers['Authorization'] );
		} else {
			$headers['Authorization'] = $authorization;
		}
		// Powerhouse 的 client 一直帶著 Origin，CloudServer 的部分端點（如授權碼網域比對）依賴它
		$headers['Origin'] = (string) \wp_parse_url( \site_url(), PHP_URL_HOST );

		$body = null;
		if ( 'GET' === $method ) {
			if ( $params ) {
				$url = \add_query_arg( $params, $url );
			}
		} elseif ( $params ) {
			$body = \wp_json_encode( $params );
			if ( false === $body ) {
				return new \WP_Error( 'pp_json_encode_failed', 'wp_json_encode failed' );
			}
		}

		$request_args = [
			'method'  => $method,
			'timeout' => (float) ( $args['timeout'] ?? 30 ),
			'headers' => $headers,
		];
		if ( null !== $body ) {
			$request_args['body'] = $body;
		}

		return \wp_remote_request( $url, $request_args );
	}
}
