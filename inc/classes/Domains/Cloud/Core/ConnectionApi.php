<?php

declare(strict_types=1);

namespace J7\PowerPartner\Domains\Cloud\Core;

use J7\PowerPartner\Domains\Cloud\Services\ConnectionService;
use J7\PowerPartner\Plugin;

/**
 * AdminApp 的登入 / 身分 / 登出 API
 *
 * 取代舊版前端「自己解密 account_info → 帶共用帳號打 cloud identity → POST /partner-id」的流程。
 * 帳密與憑證只在 PHP 之間流動，不回傳給瀏覽器。
 */
final class ConnectionApi {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		\add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$permission = static fn() => \current_user_can( 'manage_options' );

		\register_rest_route(
			Plugin::$kebab,
			'connection',
			[
				[
					'methods'             => 'GET',
					'callback'            => static fn() => \rest_ensure_response( ConnectionService::status() ),
					'permission_callback' => $permission,
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'post_connection_callback' ],
					'permission_callback' => $permission,
				],
				[
					'methods'             => 'DELETE',
					'callback'            => static function () {
						ConnectionService::disconnect();
						return \rest_ensure_response(
							[
								'status'  => 200,
								'message' => 'disconnected',
							]
						);
					},
					'permission_callback' => $permission,
				],
			]
		);
	}

	/**
	 * 登入
	 *
	 * 與舊版 cloud identity 相同：帳密錯誤也回 HTTP 200，失敗原因放在 body 的 status / message，
	 * 前端既有的錯誤顯示邏輯不必改。
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function post_connection_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$params   = $request->get_json_params() ?: $request->get_body_params();
		$email    = trim( (string) ( $params['email'] ?? '' ) );
		$password = (string) ( $params['password'] ?? '' );

		if ( '' === $email || '' === $password ) {
			return new \WP_REST_Response(
				[
					'status'  => 400,
					'message' => 'email and password are required',
				]
			);
		}

		$result = ConnectionService::connect( $email, $password );
		$response = new \WP_REST_Response( $result );
		// 回應雖不含帳密，但含身分與點數餘額，不該被任何中介快取
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
