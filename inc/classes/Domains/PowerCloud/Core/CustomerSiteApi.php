<?php

declare(strict_types=1);

namespace J7\PowerPartner\Domains\PowerCloud\Core;

use J7\PowerPartner\Api\FetchPowerCloud;
use J7\PowerPartner\Domains\PowerCloud\Services\CustomerSites;
use J7\PowerPartner\Plugin;

/**
 * 前台「我的網站」新架構分頁：瀏覽器 → 本站 → PowerCloud
 *
 * PowerCloud API key 可以操作經銷商底下的所有站台，不可送到客戶的瀏覽器。
 * 由 PHP 持 key 呼叫 API，只回傳屬於當前使用者的站台與 CustomerSites::PUBLIC_FIELDS。
 * 寫入操作先確認歸屬，而且不轉送上游回應（回應可能帶站台憑證）。
 */
final class CustomerSiteApi {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string 路由前綴 */
	const ROUTE_PREFIX = 'powercloud/sites';

	/** @var string 站台 id 的路由 regex（PowerCloud websiteId 是 UUID） */
	const SITE_ID_PATTERN = '(?P<id>[A-Za-z0-9\-]+)';

	/** @var string 與 UserApp 變更域名表單相同的規則；D：`$` 不接受結尾換行 */
	const DOMAIN_PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$/D';

	/** @var int RFC 1035 網域長度上限 */
	const DOMAIN_MAX_LENGTH = 253;

	/** Constructor */
	public function __construct() {
		\add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * 註冊路由
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$routes = [
			[ self::ROUTE_PREFIX, 'GET', [ $this, 'get_sites_callback' ] ],
			[ self::ROUTE_PREFIX . '/' . self::SITE_ID_PATTERN . '/start', 'POST', [ $this, 'post_start_callback' ] ],
			[ self::ROUTE_PREFIX . '/' . self::SITE_ID_PATTERN . '/stop', 'POST', [ $this, 'post_stop_callback' ] ],
			[ self::ROUTE_PREFIX . '/' . self::SITE_ID_PATTERN . '/domain', 'POST', [ $this, 'post_domain_callback' ] ],
		];

		foreach ( $routes as [ $path, $method, $callback ] ) {
			\register_rest_route(
				Plugin::$kebab,
				$path,
				[
					'methods'             => $method,
					'callback'            => $callback,
					'permission_callback' => 'is_user_logged_in',
				]
			);
		}
	}

	/**
	 * 取得當前使用者的站台
	 *
	 * @return \WP_REST_Response
	 */
	public function get_sites_callback(): \WP_REST_Response {
		$owned = $this->owned_sites();
		if ( $owned instanceof \WP_REST_Response ) {
			return $owned;
		}

		$sites = array_map( [ CustomerSites::class, 'to_public' ], array_values( $owned ) );

		return new \WP_REST_Response(
			[
				'data'  => $sites,
				'total' => count( $sites ),
			],
			200
		);
	}

	/**
	 * 啟動站台
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function post_start_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->patch_owned_site( $request, 'start' );
	}

	/**
	 * 停止站台
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function post_stop_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->patch_owned_site( $request, 'stop' );
	}

	/**
	 * 變更站台網域
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function post_domain_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$domain = $request->get_param( 'domain' );
		if ( ! is_string( $domain ) || strlen( $domain ) > self::DOMAIN_MAX_LENGTH || 1 !== preg_match( self::DOMAIN_PATTERN, $domain ) ) {
			return self::error( 'invalid_domain', '請輸入不含 http(s):// 的合格的網址', 400 );
		}

		// 網域變更可能要等數分鐘：瀏覽器或反向代理先斷線時，仍要讓這個請求跑完
		\ignore_user_abort( true );

		return $this->patch_owned_site( $request, 'domain', [ 'domain' => $domain ] );
	}

	/**
	 * 確認站台屬於當前使用者後，對 PowerCloud 發出 PATCH
	 *
	 * @param \WP_REST_Request     $request 請求
	 * @param string               $action  start / stop / domain
	 * @param array<string, mixed> $body    請求內容
	 * @return \WP_REST_Response
	 */
	private function patch_owned_site( \WP_REST_Request $request, string $action, array $body = [] ): \WP_REST_Response {
		$owned = $this->owned_sites();
		if ( $owned instanceof \WP_REST_Response ) {
			return $owned;
		}

		$site_id = (string) $request->get_param( 'id' );
		if ( ! isset( $owned[ $site_id ] ) ) {
			return self::error( 'not_owner', '你沒有權限操作這個網站', 403 );
		}

		$result = FetchPowerCloud::patch_website( (string) \get_current_user_id(), $site_id, $action, $body );
		if ( $result['ok'] ) {
			return new \WP_REST_Response( [ 'success' => true ], 200 );
		}

		// status 0：沒有收到上游回應（含逾時），操作可能仍在進行，前端不能直接說失敗
		if ( 0 === $result['status'] ) {
			return self::error( 'pp_upstream_unavailable', '暫時無法確認操作結果，請稍後重新整理列表確認', 502 );
		}

		return self::error( 'pp_upstream_error', '' !== $result['message'] ? $result['message'] : '操作失敗，請稍後再試', 502 );
	}

	/**
	 * 取得當前使用者的站台；無法取得時回傳要給瀏覽器的錯誤
	 *
	 * @return array<string, array<string, mixed>>|\WP_REST_Response
	 */
	private function owned_sites(): array|\WP_REST_Response {
		$user_id = \get_current_user_id();

		if ( empty( FetchPowerCloud::get_powercloud_api_key( (string) $user_id ) ) ) {
			return self::error( 'pp_powercloud_not_connected', '此網站尚未連結新架構帳號', 409 );
		}

		// 清單不完整時不可當成「沒有站台」或「不屬於你」
		return CustomerSites::owned_by( $user_id ) ?? self::error( 'pp_upstream_unavailable', '暫時無法取得網站資料，請稍後再試', 502 );
	}

	/**
	 * 組出錯誤回應
	 *
	 * @param string $code    錯誤代碼
	 * @param string $message 訊息
	 * @param int    $status  HTTP status
	 * @return \WP_REST_Response
	 */
	private static function error( string $code, string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'code'    => $code,
				'message' => $message,
			],
			$status
		);
	}
}
