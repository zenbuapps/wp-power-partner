<?php

declare(strict_types=1);

namespace J7\PowerPartner\Domains\Cloud\Core;

use J7\PowerPartner\Api\Connect;
use J7\PowerPartner\Domains\Cloud\Services\CloudClient;
use J7\PowerPartner\Domains\Cloud\Services\ConnectionService;
use J7\PowerPartner\Domains\Cloud\Services\CredentialStore;
use J7\PowerPartner\Domains\Cloud\Shared\Arr;
use J7\PowerPartner\Domains\Cloud\Shared\RawResponse;
use J7\PowerPartner\Plugin;

/**
 * 瀏覽器 → 本站 → CloudServer 的轉送層
 *
 * 舊版前端直接帶著共用帳號的 Basic 憑證打 CloudServer，憑證因此外洩。改由本站 PHP 加上認證後轉送，
 * 瀏覽器只需要本站的 wp_rest nonce。
 *
 * 規則：
 * - 路由逐條宣告（不用 catch-all regex），只轉送前端實際用到的端點
 * - audience：admin = manage_options（AdminApp）；customer = 其他已登入者（前台 UserApp）
 * - 身分參數一律由 PHP 覆寫，不信任瀏覽器：partner 身分 → 本站 partner_id；customer → 自己的 user id
 * - customer 改網域 / 切 SSL / 釋出授權碼前，先向 CloudServer 確認對象屬於自己（CUSTOMER_OWNERSHIP_ROUTES）
 * - 上游 status 與 JSON body 原樣回傳，前端拿到的 res.data 與直連時相同
 */
final class ProxyApi {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string 本地路由前綴 */
	const ROUTE_PREFIX = 'cloud/';

	const AUDIENCE_ADMIN    = 'admin';
	const AUDIENCE_CUSTOMER = 'customer';

	/**
	 * 轉送宣告：[本地/上游路徑 regex, method, audience, 身分參數]
	 *
	 * 身分參數：該端點代表經銷商身分的欄位名，PHP 一律以本站 partner_id 覆寫（缺少時補上）。
	 * customer audience 另外強制 customer_id。
	 *
	 * @var array<int, array{0: string, 1: string, 2: string, 3: string}>
	 */
	const ROUTES = [
		[ 'identity', 'POST', self::AUDIENCE_ADMIN, '' ],
		[ 'apps', 'GET', self::AUDIENCE_CUSTOMER, 'user_id' ],
		[ 'logs', 'GET', self::AUDIENCE_ADMIN, 'user_id' ],
		// 刻意與 CloudServer 相同用 [\w\-]*（不是 \d+）：非法值要由 CloudServer 回 400，不是在這裡變 404
		[ 'logs/(?P<log_id>[\w\-]*)/detail', 'POST', self::AUDIENCE_ADMIN, '' ],
		[ 'license-codes', 'GET', self::AUDIENCE_CUSTOMER, 'author' ],
		[ 'license-codes', 'POST', self::AUDIENCE_ADMIN, 'post_author' ],
		[ 'license-codes/products', 'GET', self::AUDIENCE_ADMIN, 'user_id' ],
		[ 'license-codes/release', 'POST', self::AUDIENCE_CUSTOMER, '' ],
		[ 'v2/change-customer', 'POST', self::AUDIENCE_ADMIN, 'partner_id' ],
		[ 'v2/toggle-site', 'POST', self::AUDIENCE_ADMIN, 'partner_id' ],
		[ 'change-domain', 'POST', self::AUDIENCE_CUSTOMER, '' ],
		[ 'toggle-ssl', 'POST', self::AUDIENCE_CUSTOMER, '' ],
	];

	const OWNERSHIP_APP           = 'app';
	const OWNERSHIP_LICENSE_CODES = 'license_codes';

	/**
	 * customer 操作前必須先向 CloudServer 確認歸屬的端點 => 要確認的對象
	 *
	 * - OWNERSHIP_APP：body 的 `id` 是 WPCD app id（CloudServer 舊版沒有歸屬檢查）
	 * - OWNERSHIP_LICENSE_CODES：body 的 `ids` 是授權碼 id（CloudServer legacy 模式只比對 customer_id，
	 *   不同經銷商站上編號相同的客戶可以互相釋出對方的碼）
	 *
	 * @var array<string, string>
	 */
	const CUSTOMER_OWNERSHIP_ROUTES = [
		'change-domain'         => self::OWNERSHIP_APP,
		'toggle-ssl'            => self::OWNERSHIP_APP,
		'license-codes/release' => self::OWNERSHIP_LICENSE_CODES,
	];

	/** @var array<int, string> customer 存取時必須強制 customer_id 的端點 */
	const CUSTOMER_SCOPED_ROUTES = [ 'apps', 'license-codes', 'license-codes/release', 'change-domain', 'toggle-ssl' ];

	/**
	 * @var array<int, string> body 帶經銷商真實密碼的端點：一律經過本站的真實密碼閘門
	 *
	 * CloudServer 以 wp_authenticate() 驗證真實密碼，失敗次數會被 cloud.luke.cafe 的 Fluent Security
	 * 記在本站主機的 IP 上（見 ConnectionService::REAL_PASSWORD_GATE_OPTION）
	 */
	const REAL_PASSWORD_ROUTES = [ 'identity' ];

	/** Constructor */
	public function __construct() {
		\add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		\add_filter( 'rest_pre_serve_request', [ RawResponse::class, 'serve' ], 10, 2 );
	}

	/**
	 * 註冊轉送路由
	 *
	 * @return void
	 */
	public function register_routes(): void {
		foreach ( self::ROUTES as [ $path, $method, $audience, $identity_param ] ) {
			\register_rest_route(
				Plugin::$kebab,
				self::ROUTE_PREFIX . $path,
				[
					'methods'             => $method,
					'callback'            => fn( \WP_REST_Request $request ) => $this->forward( $request, $path, $method, $identity_param ),
					'permission_callback' => self::AUDIENCE_ADMIN === $audience
						? static fn() => \current_user_can( 'manage_options' )
						: 'is_user_logged_in',
				]
			);
		}
	}

	/**
	 * 轉送一個請求
	 *
	 * @param \WP_REST_Request $request        請求
	 * @param string           $path           路由宣告的路徑（可能含 regex）
	 * @param string           $method         HTTP method
	 * @param string           $identity_param 經銷商身分參數名
	 * @return \WP_REST_Response
	 */
	private function forward( \WP_REST_Request $request, string $path, string $method, string $identity_param ): \WP_REST_Response {
		$partner_id = (string) \get_option( Connect::PARTNER_ID_OPTION_NAME, '' );
		if ( '' === $partner_id ) {
			return new \WP_REST_Response(
				[
					'code'    => 'pp_not_connected',
					'message' => '尚未連結站長路可帳號',
				],
				409
			);
		}

		$params = Arr::assoc( 'GET' === $method ? $request->get_query_params() : ( $request->get_json_params() ?: $request->get_body_params() ) );

		if ( '' !== $identity_param ) {
			$params[ $identity_param ] = $partner_id;
		}

		$is_customer = ! \current_user_can( 'manage_options' );
		$upstream    = $path;
		if ( str_contains( $path, '(?P<log_id>' ) ) {
			$upstream = 'logs/' . rawurlencode( (string) $request->get_param( 'log_id' ) ) . '/detail';
		}

		if ( $is_customer && in_array( $path, self::CUSTOMER_SCOPED_ROUTES, true ) ) {
			$params['customer_id'] = (string) \get_current_user_id();
		}

		$ownership = $is_customer ? ( self::CUSTOMER_OWNERSHIP_ROUTES[ $path ] ?? null ) : null;
		if ( null !== $ownership ) {
			$denied = self::OWNERSHIP_APP === $ownership
				? $this->deny_unless_customer_owns( (int) ( $params['id'] ?? 0 ), $partner_id )
				: $this->deny_unless_customer_owns_license_codes( $params['ids'] ?? null, $partner_id );
			if ( null !== $denied ) {
				return $denied;
			}
		}

		if ( $upstream !== $path ) {
			$response = $this->forward_log_detail( $upstream, $params );
			if ( $response instanceof \WP_REST_Response ) {
				return $response;
			}
		} elseif ( in_array( $path, self::REAL_PASSWORD_ROUTES, true ) ) {
			// 不走 request() 的自動 fallback：dealer 被拒時會把同一組真實密碼再送一次
			$response = ConnectionService::send_with_real_password(
				static fn() => CloudClient::instance()->request_with_mode( CloudClient::preferred_mode(), $method, $upstream, $params, [ 'timeout' => 120 ] ),
				ConnectionService::SEND_IDENTITY
			);
			if ( ConnectionService::is_gated( $response ) ) {
				return self::gate_blocked();
			}
		} else {
			$response = CloudClient::instance()->request( $method, $upstream, $params, [ 'timeout' => 120 ] );
		}

		return self::to_rest_response( $response );
	}

	/**
	 * 被本站真實密碼閘門擋下（沒有送出）
	 *
	 * @return \WP_REST_Response
	 */
	private static function gate_blocked(): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'status'  => 429,
				'code'    => ConnectionService::GATE_ERROR_CODE,
				'message' => ConnectionService::GATE_MESSAGE,
			],
			429
		);
	}

	/**
	 * 單筆點數紀錄明細：legacy 模式的 CloudServer 要求 body 帶經銷商帳密，dealer 模式不需要
	 *
	 * 不能走 CloudClient::request() 的自動 fallback：fallback 到 legacy 時 body 必須補上帳密，
	 * 否則 CloudServer 會回「缺少帳密」而不是明細。
	 *
	 * @param string               $upstream 上游路徑
	 * @param array<string, mixed> $params   body
	 * @return array<string, mixed>|\WP_Error|\WP_REST_Response 本站直接回覆（不轉送）時為 WP_REST_Response
	 */
	private function forward_log_detail( string $upstream, array $params ): array|\WP_Error|\WP_REST_Response {
		unset( $params['email'], $params['password'] );
		$client = CloudClient::instance();
		$args   = [ 'timeout' => 120 ];

		if ( CloudClient::MODE_DEALER === CloudClient::preferred_mode() ) {
			$response = $client->request_with_mode( CloudClient::MODE_DEALER, 'POST', $upstream, $params, $args );
			if ( ! CloudClient::is_credential_rejected( $response ) ) {
				return $response;
			}
			CredentialStore::mark_dealer_invalid( CloudClient::error_code( $response ) );
		}

		$account = CredentialStore::get_legacy_account();
		if ( null !== $account ) {
			// 帳密被拒過就不再送出，避免在本站主機的 IP 上累積失敗
			if ( CredentialStore::is_legacy_account_rejected() ) {
				return new \WP_REST_Response(
					[
						'status'  => 401,
						'code'    => 'pp_relogin_required',
						'message' => ConnectionService::attention()['message'] ?? '雲端帳密已失效，請重新登入一次。',
					],
					401
				);
			}
			$params['email']    = $account['email'];
			$params['password'] = $account['password'];

			$response = ConnectionService::send_with_real_password(
				static fn() => $client->request_with_mode( CloudClient::MODE_LEGACY, 'POST', $upstream, $params, $args ),
				ConnectionService::SEND_DETAIL
			);
			if ( ConnectionService::is_gated( $response ) ) {
				return self::gate_blocked();
			}
			if ( ConnectionService::is_password_rejected( $response ) ) {
				CredentialStore::mark_legacy_account_rejected( ConnectionService::EXCHANGE_BAD_PASSWORD );
			} elseif ( ConnectionService::is_login_challenge( $response ) ) {
				CredentialStore::mark_legacy_account_rejected( CredentialStore::REJECTED_LOGIN_CHALLENGE );
			}
			return $response;
		}

		// 沒有帳密可補：不帶真實密碼，照舊轉送（CloudServer 會回缺少帳密）
		return $client->request_with_mode( CloudClient::MODE_LEGACY, 'POST', $upstream, $params, $args );
	}

	/**
	 * customer 只能操作自己的站：向 CloudServer 取「這個經銷商 × 這個客戶」的站台清單確認
	 *
	 * @param int    $app_id     WPCD app id
	 * @param string $partner_id 經銷商 id
	 * @return \WP_REST_Response|null 不屬於自己時回 403，屬於自己回 null
	 */
	private function deny_unless_customer_owns( int $app_id, string $partner_id ): ?\WP_REST_Response {
		$denied = new \WP_REST_Response(
			[
				'code'    => 'not_owner',
				'message' => '你沒有權限操作這個網站',
			],
			403
		);
		if ( $app_id <= 0 ) {
			return $denied;
		}

		$response = CloudClient::instance()->request(
			'GET',
			'apps',
			[
				'user_id'     => $partner_id,
				'customer_id' => (string) \get_current_user_id(),
				'numberposts' => -1,
			],
			[ 'timeout' => 60 ]
		);
		if ( \is_wp_error( $response ) || 200 !== (int) \wp_remote_retrieve_response_code( $response ) ) {
			return self::ownership_unverifiable( '暫時無法確認網站歸屬，請稍後再試' );
		}

		$body = Arr::from_json( (string) \wp_remote_retrieve_body( $response ) );
		$list = Arr::assoc( Arr::assoc( $body['data'] ?? null )['list'] ?? null );
		foreach ( $list as $app ) {
			if ( (int) ( Arr::assoc( $app )['ID'] ?? 0 ) === $app_id ) {
				return null;
			}
		}
		return $denied;
	}

	/**
	 * customer 只能釋出自己的授權碼：向 CloudServer 取「這個經銷商 × 這個客戶」的授權碼清單確認
	 *
	 * @param mixed  $ids        body 的 ids
	 * @param string $partner_id 經銷商 id
	 * @return \WP_REST_Response|null 任一個不屬於自己時回 403，全部屬於自己回 null
	 */
	private function deny_unless_customer_owns_license_codes( mixed $ids, string $partner_id ): ?\WP_REST_Response {
		$denied = new \WP_REST_Response(
			[
				'code'    => 'not_owner',
				'message' => '你沒有權限釋出這些授權碼',
			],
			403
		);
		if ( ! is_array( $ids ) || [] === $ids ) {
			return $denied;
		}

		$wanted = [];
		foreach ( $ids as $id ) {
			if ( ! is_numeric( $id ) || (int) $id <= 0 ) {
				return $denied;
			}
			$wanted[] = (int) $id;
		}

		$response = CloudClient::instance()->request(
			'GET',
			'license-codes',
			[
				'author'         => $partner_id,
				'customer_id'    => (string) \get_current_user_id(),
				'posts_per_page' => -1,
			],
			[ 'timeout' => 60 ]
		);
		if ( \is_wp_error( $response ) || 200 !== (int) \wp_remote_retrieve_response_code( $response ) ) {
			return self::ownership_unverifiable( '暫時無法確認授權碼歸屬，請稍後再試' );
		}

		$body  = Arr::from_json( (string) \wp_remote_retrieve_body( $response ) );
		$owned = [];
		foreach ( Arr::assoc( Arr::assoc( $body['data'] ?? null )['list'] ?? null ) as $license_code ) {
			$owned[ (int) ( Arr::assoc( $license_code )['id'] ?? 0 ) ] = true;
		}
		foreach ( $wanted as $id ) {
			if ( ! isset( $owned[ $id ] ) ) {
				return $denied;
			}
		}
		return null;
	}

	/**
	 * 確認不了歸屬時一律不放行
	 *
	 * @param string $message 訊息
	 * @return \WP_REST_Response
	 */
	private static function ownership_unverifiable( string $message ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'code'    => 'pp_upstream_unavailable',
				'message' => $message,
			],
			502
		);
	}

	/**
	 * 把上游回應轉成 REST 回應：status 與 JSON body 原樣、CSV 原文輸出
	 *
	 * @param array<string, mixed>|\WP_Error $response 上游回應
	 * @return \WP_REST_Response
	 */
	public static function to_rest_response( array|\WP_Error $response ): \WP_REST_Response {
		if ( \is_wp_error( $response ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'pp_upstream_unavailable',
					'message' => "無法連線到站長路可：{$response->get_error_message()}",
				],
				502
			);
		}

		$status       = (int) \wp_remote_retrieve_response_code( $response );
		$body         = (string) \wp_remote_retrieve_body( $response );
		$content_type = self::header( $response, 'content-type' );

		if ( $status >= 200 && $status < 300 && str_starts_with( strtolower( $content_type ), 'text/csv' ) ) {
			$headers     = [ 'Content-Type' => $content_type ];
			$disposition = self::header( $response, 'content-disposition' );
			if ( 1 === preg_match( '/filename="?([\w.\-]+)"?/', $disposition, $m ) ) {
				$headers['Content-Disposition'] = "attachment; filename=\"{$m[1]}\"";
			}
			return new RawResponse( $body, $status, $headers );
		}

		$decoded = json_decode( $body, true );
		if ( null === $decoded && '' !== trim( $body ) ) {
			$decoded = [
				'code'    => 'pp_invalid_upstream_response',
				'message' => '站長路可回應格式錯誤',
			];
		}

		return new \WP_REST_Response( $decoded, $status ?: 502 );
	}

	/**
	 * 取單一 header；同名 header 重複時取第一個
	 *
	 * @param array<string, mixed> $response 上游回應
	 * @param string               $name     header 名稱
	 * @return string
	 */
	private static function header( array $response, string $name ): string {
		$value = \wp_remote_retrieve_header( $response, $name );
		return is_array( $value ) ? (string) ( $value[0] ?? '' ) : $value;
	}
}
