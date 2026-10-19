<?php
/**
 * 經銷商專屬應用程式密碼（Domains\Cloud）整合測試
 *
 * 核心約束：向後相容。本版 client 不論早於或晚於 CloudServer 升級上線，都必須照常運作：
 * - CloudServer 有 v3/connect → 換到專屬密碼（dealer 模式）
 * - 沒有（404）/ 不符資格 / 專屬密碼被拒 → 共用帳號（legacy 模式），行為與 3.5.x 相同
 * 並且任何情況下，憑證都不可出現在 REST 回應或頁面資料裡。
 */

declare( strict_types=1 );

namespace Tests\Integration;

use J7\PowerPartner\Api\Connect;
use J7\PowerPartner\Bootstrap;
use J7\PowerPartner\Domains\Cloud\Core\Migration;
use J7\PowerPartner\Domains\Cloud\Core\ProxyApi;
use J7\PowerPartner\Domains\Cloud\Services\CloudClient;
use J7\PowerPartner\Domains\Cloud\Services\ConnectionService;
use J7\PowerPartner\Domains\Cloud\Services\CredentialStore;
use J7\PowerPartner\Domains\Cloud\Shared\RawResponse;

/**
 * @group security
 */
class CloudConnectionTest extends TestCase {

	/**
	 * 舊版前端 CryptoJS.AES.encrypt(JSON.stringify({email, password}), 'power-partner') 的真實輸出
	 * （以專案 node_modules 的 crypto-js 產生），用來證明既有安裝的密文讀得回來
	 */
	private const OLD_CIPHER = 'U2FsdGVkX18jurO/JPfxROKprWmYHV/yuiBE3CHhdFXajd/kMtqhaXrGLiRRakYp7W8EhYN9Pq3W9u8mYzIc4MAtkwcPt/Kk4YGtaiO1VfI=';

	private const OLD_EMAIL    = 'dealer@test.com';
	private const OLD_PASSWORD = 'p@ss w0rd 中文';

	private const DEALER_LOGIN    = 'dealer_login';
	private const DEALER_PASSWORD = 'AbCd EfGh IjKl MnOp QrSt UvWx';

	/** @var int */
	private int $admin_id;

	/** @var int */
	private int $customer_id;

	/** @var array<int, array{url: string, method: string, headers: array<string, string>, body: array<string, mixed>, query: array<string, mixed>}> */
	private array $requests = [];

	/**
	 * 依「METHOD 路徑」回應的假 CloudServer；值為 [status, body(array|string), headers]
	 *
	 * @var array<string, array{0: int, 1: array<string, mixed>|string, 2?: array<string, string>}|callable>
	 */
	private array $routes = [];

	/** @var callable|null */
	private $http_filter = null;

	protected function configure_dependencies(): void {
		$this->admin_id    = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$this->customer_id = $this->factory()->user->create( [ 'role' => 'customer' ] );

		$this->clean();
		\update_option( Connect::PARTNER_ID_OPTION_NAME, '777' );

		$this->http_filter = function ( $pre, $args, $url ) {
			$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
			$path   = (string) \wp_parse_url( (string) $url, PHP_URL_PATH );
			$path   = (string) preg_replace( '#^.*/wp-json/power-partner-server/#', '', $path );
			$query  = [];
			parse_str( (string) \wp_parse_url( (string) $url, PHP_URL_QUERY ), $query );
			$body = json_decode( (string) ( $args['body'] ?? '' ), true );

			$this->requests[] = [
				'url'     => (string) $url,
				'method'  => $method,
				'path'    => $path,
				'headers' => (array) ( $args['headers'] ?? [] ),
				'body'    => is_array( $body ) ? $body : [],
				'query'   => $query,
			];

			$route = $this->routes[ "{$method} {$path}" ] ?? [ 404, [ 'code' => 'rest_no_route', 'message' => 'No route' ] ];
			if ( is_callable( $route ) ) {
				$route = $route( end( $this->requests ) );
			}
			[ $status, $resp_body ] = $route;
			$headers                = $route[2] ?? [ 'content-type' => 'application/json' ];
			return [
				'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( $headers ),
				'body'     => is_string( $resp_body ) ? $resp_body : (string) \wp_json_encode( $resp_body ),
				'response' => [
					'code'    => $status,
					'message' => '',
				],
				'cookies'  => [],
			];
		};
		\add_filter( 'pre_http_request', $this->http_filter, 10, 3 );

		rest_get_server();
	}

	public function tear_down(): void {
		\remove_filter( 'pre_http_request', $this->http_filter, 10 );
		\remove_all_filters( 'salt' );
		\wp_set_current_user( 0 );
		$this->clean();
		parent::tear_down();
	}

	private function clean(): void {
		foreach ( [
			CredentialStore::DEALER_OPTION,
			CredentialStore::LEGACY_ACCOUNT_OPTION,
			CredentialStore::OLD_ACCOUNT_INFO_OPTION,
			CredentialStore::DEALER_INVALID_OPTION,
			CredentialStore::LEGACY_REJECTED_OPTION,
			CredentialStore::LEGACY_DISABLED_OPTION,
			ConnectionService::MIGRATION_OPTION,
			ConnectionService::INSTALLATION_OPTION,
			ConnectionService::LOGIN_FAILURES_OPTION,
			Connect::PARTNER_ID_OPTION_NAME,
		] as $option ) {
			\delete_option( $option );
		}
	}

	/** @return array<string, mixed> */
	private function identity_data(): array {
		return [
			'user_id'                  => '777',
			'email'                    => self::OLD_EMAIL,
			'power_money_amount'       => '100.00',
			'partner_lv'               => [
				'key'      => '1',
				'title'    => '經銷商',
				'discount' => '0.9',
			],
			'allowed_template_options' => [ '12' => '模板 A' ],
		];
	}

	private function given_dealer(): void {
		CredentialStore::save_dealer(
			[
				'user_login'   => self::DEALER_LOGIN,
				'app_password' => self::DEALER_PASSWORD,
				'uuid'         => 'uuid-1',
				'partner_id'   => '777',
			]
		);
	}

	private function legacy_basic(): string {
		return 'Basic ' . base64_encode( Bootstrap::instance()->username . ':' . Bootstrap::instance()->psw ); // phpcs:ignore
	}

	private function dealer_basic(): string {
		return 'Basic ' . base64_encode( self::DEALER_LOGIN . ':' . self::DEALER_PASSWORD ); // phpcs:ignore
	}

	/**
	 * @param string $method method
	 * @param string $path   path
	 * @return array<int, array<string, mixed>>
	 */
	private function sent( string $method, string $path ): array {
		return array_values( array_filter( $this->requests, static fn( $r ) => $r['method'] === $method && $r['path'] === $path ) );
	}

	// ========== CredentialStore ==========

	/** @group happy */
	public function test_專屬密碼加密存放且讀得回來(): void {
		$this->given_dealer();

		$stored = (string) \get_option( CredentialStore::DEALER_OPTION );
		$this->assertStringNotContainsString( self::DEALER_PASSWORD, $stored );
		$this->assertStringNotContainsString( self::DEALER_LOGIN, $stored );
		$this->assertSame( self::DEALER_PASSWORD, CredentialStore::get_dealer()['app_password'] ?? null );

		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", CredentialStore::DEALER_OPTION ) );
		$this->assertContains( $autoload, [ 'no', 'off' ], '憑證不可 autoload' );
	}

	/** @group security */
	public function test_站台網域改變時專屬密碼視為未連線(): void {
		$this->given_dealer();
		// wp-env 以 WP_HOME 常數固定網址，改 option 無效，改用 filter 模擬「站台被 clone 到另一個網域」
		\add_filter( 'home_url', static fn() => 'https://cloned-customer-site.test' );

		$this->assertNull( CredentialStore::get_dealer(), '模板站被 clone 成終端客戶站時不可繼承經銷商憑證' );

		\remove_all_filters( 'home_url' );
		$this->assertNotNull( CredentialStore::get_dealer(), '回到原網域即可讀回（對照組）' );
	}

	/** @group error */
	public function test_salts重產後解不開視為未連線而非fatal(): void {
		$this->given_dealer();
		\add_filter( 'salt', static fn( $salt ) => $salt . '-rotated' );

		$this->assertNull( CredentialStore::get_dealer() );
	}

	/** @group happy */
	public function test_讀得回舊版前端CryptoJS密文(): void {
		$this->assertSame(
			[
				'email'    => self::OLD_EMAIL,
				'password' => self::OLD_PASSWORD,
			],
			CredentialStore::decrypt_old_account_info( self::OLD_CIPHER )
		);
	}

	/** @group happy */
	public function test_舊版密文在換成新格式後刪除(): void {
		\update_option( CredentialStore::OLD_ACCOUNT_INFO_OPTION, self::OLD_CIPHER );
		$this->assertSame( self::OLD_PASSWORD, CredentialStore::get_legacy_account()['password'] ?? null );

		CredentialStore::save_legacy_account( self::OLD_EMAIL, self::OLD_PASSWORD );

		$this->assertFalse( \get_option( CredentialStore::OLD_ACCOUNT_INFO_OPTION ), '可被任何人解開的舊密文必須刪除' );
		$this->assertSame( self::OLD_PASSWORD, CredentialStore::get_legacy_account()['password'] ?? null );
	}

	// ========== CloudClient ==========

	/** @group happy */
	public function test_沒有專屬密碼時用共用帳號(): void {
		$this->routes['GET apps'] = [ 200, [ 'status' => 200 ] ];

		CloudClient::instance()->remote_get( 'apps', [ 'user_id' => '777' ] );

		$this->assertSame( $this->legacy_basic(), $this->sent( 'GET', 'apps' )[0]['headers']['Authorization'] ?? null );
	}

	/** @group happy */
	public function test_有專屬密碼時用專屬密碼(): void {
		$this->given_dealer();
		$this->routes['GET apps'] = [ 200, [ 'status' => 200 ] ];

		CloudClient::instance()->remote_get( 'apps' );

		$this->assertSame( $this->dealer_basic(), $this->sent( 'GET', 'apps' )[0]['headers']['Authorization'] ?? null );
	}

	/**
	 * 向後相容的保險：專屬密碼被拒時當次改用共用帳號重送，功能不中斷
	 *
	 * @group error
	 */
	public function test_專屬密碼被拒時改用共用帳號重送並標記失效(): void {
		$this->given_dealer();
		$this->routes['POST v2/disable-site'] = function ( $request ) {
			return $request['headers']['Authorization'] === $this->dealer_basic()
				? [ 401, [ 'code' => 'incorrect_password', 'message' => 'x', 'data' => [ 'status' => 401 ] ] ]
				: [ 200, [ 'status' => 200 ] ];
		};

		$response = CloudClient::instance()->remote_post( 'v2/disable-site', [ 'site_id' => '1' ] );

		$this->assertSame( 200, \wp_remote_retrieve_response_code( $response ) );
		$this->assertCount( 2, $this->sent( 'POST', 'v2/disable-site' ) );
		$this->assertTrue( CredentialStore::is_dealer_invalid() );
		$this->assertSame( CloudClient::MODE_LEGACY, CloudClient::preferred_mode(), '剛被拒的一小時內直接用共用帳號' );
	}

	/**
	 * 越權（身分正確但無權操作）不可 fallback —— 換成共用帳號重送等於繞過 CloudServer 的歸屬檢查
	 *
	 * @group security
	 */
	public function test_歸屬錯誤不會改用共用帳號重送(): void {
		$this->given_dealer();
		$this->routes['POST change-domain'] = [ 403, [ 'code' => 'not_owner', 'message' => 'x', 'data' => [ 'status' => 403, 'error_code' => 'not_owner' ] ] ];

		$response = CloudClient::instance()->remote_post( 'change-domain', [ 'id' => 5 ] );

		$this->assertSame( 403, \wp_remote_retrieve_response_code( $response ) );
		$this->assertCount( 1, $this->sent( 'POST', 'change-domain' ) );
		$this->assertFalse( CredentialStore::is_dealer_invalid() );
	}

	/** @group happy */
	public function test_請求帶telemetry與Origin(): void {
		$this->routes['GET apps'] = [ 200, [] ];

		CloudClient::instance()->remote_get( 'apps' );

		$headers = $this->sent( 'GET', 'apps' )[0]['headers'];
		$this->assertArrayHasKey( 'X-PP-Client-Version', $headers );
		$this->assertSame( (string) \wp_parse_url( \site_url(), PHP_URL_HOST ), $headers['Origin'] ?? null );
	}

	// ========== ConnectionService::connect ==========

	/** @group happy */
	public function test_CloudServer支援v3時換到專屬密碼(): void {
		\update_option( CredentialStore::OLD_ACCOUNT_INFO_OPTION, self::OLD_CIPHER );
		$this->routes['POST v3/connect'] = [
			201,
			[
				'user_login'   => self::DEALER_LOGIN,
				'app_password' => self::DEALER_PASSWORD,
				'uuid'         => 'uuid-9',
				'identity'     => $this->identity_data(),
			],
		];

		$result = ConnectionService::connect( self::OLD_EMAIL, self::OLD_PASSWORD );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( CloudClient::MODE_DEALER, $result['connection']['mode'] );
		$this->assertSame( '777', $result['data']['user_id'] );
		$this->assertStringNotContainsString( self::DEALER_PASSWORD, (string) \wp_json_encode( $result ), '專屬密碼不可回傳給瀏覽器' );

		$this->assertSame( self::DEALER_PASSWORD, CredentialStore::get_dealer()['app_password'] ?? null );
		$this->assertNull( CredentialStore::get_legacy_account(), '換到專屬密碼後不再保留登入密碼' );
		$this->assertSame( '777', \get_option( Connect::PARTNER_ID_OPTION_NAME ) );

		$connect = $this->sent( 'POST', 'v3/connect' )[0];
		$this->assertArrayNotHasKey( 'Authorization', $connect['headers'], 'v3/connect 不可帶共用帳號（被撤銷後會讓整個請求 401）' );
		$this->assertSame( self::OLD_EMAIL, $connect['body']['login'] ?? null );
		$this->assertNotSame( '', (string) ( $connect['body']['installation_id'] ?? '' ) );
	}

	/**
	 * CloudServer 還沒升級（沒有 v3/connect）時，登入行為必須與 3.5.x 完全相同
	 *
	 * @group happy
	 */
	public function test_CloudServer尚未支援v3時照舊以identity登入(): void {
		$this->routes['POST identity'] = [ 200, [ 'status' => 200, 'message' => 'success', 'data' => $this->identity_data() ] ];

		$result = ConnectionService::connect( self::OLD_EMAIL, self::OLD_PASSWORD );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( CloudClient::MODE_LEGACY, $result['connection']['mode'] );
		$this->assertSame( ConnectionService::MIGRATION_PENDING, $result['connection']['migration'] );
		$this->assertSame( $this->legacy_basic(), $this->sent( 'POST', 'identity' )[0]['headers']['Authorization'] ?? null );
		$this->assertSame( self::OLD_PASSWORD, CredentialStore::get_legacy_account()['password'] ?? null );
		$this->assertSame( '777', \get_option( Connect::PARTNER_ID_OPTION_NAME ) );
	}

	/** @group error */
	public function test_帳密錯誤時不退回identity且不存任何東西(): void {
		$this->routes['POST v3/connect'] = [ 401, [ 'code' => 'auth_required', 'message' => '帳號或密碼錯誤', 'data' => [ 'status' => 401, 'error_code' => 'auth_required' ] ] ];

		$result = ConnectionService::connect( self::OLD_EMAIL, 'wrong' );

		$this->assertSame( 401, $result['status'] );
		$this->assertSame( [], $this->sent( 'POST', 'identity' ) );
		$this->assertNull( CredentialStore::get_dealer() );
		$this->assertNull( CredentialStore::get_legacy_account() );
	}

	/**
	 * 不符資格的帳號在 3.5.x 能登入，更新後也必須能（以共用帳號），且不提示「請重新登入」
	 *
	 * @group happy
	 */
	public function test_不符資格時照舊以identity登入且不提示重新登入(): void {
		$this->routes['POST v3/connect'] = [ 403, [ 'code' => 'not_eligible', 'message' => 'x', 'data' => [ 'status' => 403, 'error_code' => 'not_eligible' ] ] ];
		$this->routes['POST identity']   = [ 200, [ 'status' => 200, 'message' => 'success', 'data' => $this->identity_data() ] ];

		$result = ConnectionService::connect( self::OLD_EMAIL, self::OLD_PASSWORD );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( CloudClient::MODE_LEGACY, $result['connection']['mode'] );
		$this->assertNotSame( ConnectionService::MIGRATION_FAILED, $result['connection']['migration'] );
	}

	// ========== 與 CloudServer S1 的真實回應格式對齊（以下 JSON 由 server 整合測試實際擷取）==========

	/** @group contract */
	public function test_契約_v3connect成功回應包在data裡也能解析(): void {
		$this->routes['POST v3/connect'] = [
			200,
			'{"status":200,"message":"connected","data":{"user_login":"dealer_a","app_password":"P0yIKDcaL6cWjyAhGz14A53N","uuid":"20a1b06c-2469-4ea9-877b-998e429065af","identity":{"user_id":"125","email":"dealer_a@example.com","power_money_amount":"1,000.00","partner_lv":{"key":"1","title":"初階經銷商","discount":1},"allowed_template_options":[]}}}',
		];

		$result = ConnectionService::connect( 'dealer_a@example.com', 'pw' );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( CloudClient::MODE_DEALER, $result['connection']['mode'] );
		$this->assertSame( '125', $result['data']['user_id'] );
		$this->assertSame( 'P0yIKDcaL6cWjyAhGz14A53N', CredentialStore::get_dealer()['app_password'] ?? null );
		$this->assertSame( '20a1b06c-2469-4ea9-877b-998e429065af', CredentialStore::get_dealer()['uuid'] ?? null );
		$this->assertSame( '125', \get_option( Connect::PARTNER_ID_OPTION_NAME ) );
	}

	/** @group contract */
	public function test_契約_v3connect帳密錯誤與不具資格的真實回應(): void {
		$this->routes['POST v3/connect'] = [ 401, '{"code":"auth_required","message":"email or password invalid","data":{"status":401,"error_code":"auth_required"}}' ];
		$this->assertSame( ConnectionService::EXCHANGE_BAD_PASSWORD, ConnectionService::exchange( 'a', 'b' )['result'] );

		$this->routes['POST v3/connect'] = [ 403, '{"code":"not_eligible","message":"此帳號不具經銷商資格","data":{"status":403,"error_code":"not_eligible"}}' ];
		$this->assertSame( ConnectionService::EXCHANGE_NOT_ELIGIBLE, ConnectionService::exchange( 'a', 'b' )['result'] );

		$this->routes['POST v3/connect'] = [ 429, '{"code":"rate_limited","message":"too many","data":{"status":429,"error_code":"rate_limited"}}' ];
		$this->assertSame( ConnectionService::EXCHANGE_RATE_LIMITED, ConnectionService::exchange( 'a', 'b' )['result'] );

		$this->routes['POST v3/connect'] = [ 500, '{"code":"connect_failed","message":"x","data":{"status":500}}' ];
		$this->assertSame( ConnectionService::EXCHANGE_TEMPORARY, ConnectionService::exchange( 'a', 'b' )['result'] );
	}

	/** @group contract */
	public function test_契約_v3identity真實回應(): void {
		$this->given_dealer();
		$this->routes['GET v3/identity'] = [
			200,
			'{"status":200,"message":"user authenticated","data":{"user_id":"2","email":"dealer_a@example.com","power_money_amount":"1,000.00","partner_lv":{"key":"1","title":"初階經銷商","discount":1},"allowed_template_options":[]}}',
		];

		$result = ConnectionService::status();

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( '2', $result['data']['user_id'] );
		$this->assertSame( CloudClient::MODE_DEALER, $result['connection']['mode'] );
	}

	/**
	 * 專屬密碼被撤銷（core 回 incorrect_password）：標記失效；背景功能改走共用帳號不中斷
	 *
	 * @group contract
	 */
	public function test_契約_專屬密碼被撤銷時標記失效且背景請求改走共用帳號(): void {
		$this->given_dealer();
		$revoked = [ 401, '{"code":"incorrect_password","message":"The provided password is an invalid application password.","data":{"status":401}}' ];
		$this->routes['GET v3/identity']      = $revoked;
		$this->routes['POST v2/disable-site'] = function ( $request ) use ( $revoked ) {
			return $request['headers']['Authorization'] === $this->dealer_basic() ? $revoked : [ 200, [ 'status' => 200 ] ];
		};

		$status = ConnectionService::status();
		$this->assertTrue( CredentialStore::is_dealer_invalid() );
		$this->assertSame( CloudClient::MODE_NONE, $status['connection']['mode'], '沒有 legacy 帳密可查身分 → AdminApp 顯示登入畫面' );

		$this->assertTrue( \J7\PowerPartner\Api\Fetch::disable_site( '101' ), '停用網站等背景功能不可中斷' );
		$this->assertSame( $this->legacy_basic(), end( $this->requests )['headers']['Authorization'] ?? null );
	}

	// ========== ConnectionService::status ==========

	/**
	 * 3.5.x 的既有安裝只有舊密文：更新後打開 AdminApp 必須直接登入，不能跳回登入畫面
	 *
	 * @group happy
	 */
	public function test_既有安裝更新後以舊密文照常取得身分(): void {
		\update_option( CredentialStore::OLD_ACCOUNT_INFO_OPTION, self::OLD_CIPHER );
		$this->routes['POST identity'] = [ 200, [ 'status' => 200, 'message' => 'success', 'data' => $this->identity_data() ] ];

		$result = ConnectionService::status();

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( CloudClient::MODE_LEGACY, $result['connection']['mode'] );
		$this->assertSame( self::OLD_EMAIL, $this->sent( 'POST', 'identity' )[0]['body']['email'] ?? null );
	}

	/** @group happy */
	public function test_dealer模式以v3identity取得身分(): void {
		$this->given_dealer();
		$this->routes['GET v3/identity'] = [ 200, [ 'status' => 200, 'message' => 'success', 'data' => $this->identity_data() ] ];

		$result = ConnectionService::status();

		$this->assertSame( CloudClient::MODE_DEALER, $result['connection']['mode'] );
		$this->assertSame( $this->dealer_basic(), $this->sent( 'GET', 'v3/identity' )[0]['headers']['Authorization'] ?? null );
		$this->assertSame( [], $this->sent( 'POST', 'identity' ) );
	}

	/** @group happy */
	public function test_完全沒有連線時回401_none(): void {
		$result = ConnectionService::status();

		$this->assertSame( 401, $result['status'] );
		$this->assertSame( CloudClient::MODE_NONE, $result['connection']['mode'] );
		$this->assertSame( [], $this->requests );
	}

	/** @group happy */
	public function test_登出清除所有本地憑證並撤銷雲端密碼(): void {
		$this->given_dealer();
		CredentialStore::save_legacy_account( self::OLD_EMAIL, self::OLD_PASSWORD );
		$this->routes['DELETE v3/connect'] = [ 200, [ 'status' => 200 ] ];

		ConnectionService::disconnect();

		$this->assertCount( 1, $this->sent( 'DELETE', 'v3/connect' ) );
		$this->assertNull( CredentialStore::get_dealer() );
		$this->assertNull( CredentialStore::get_legacy_account() );
		$this->assertFalse( \get_option( Connect::PARTNER_ID_OPTION_NAME ) );
	}

	// ========== REST：/power-partner/connection ==========

	/** @group security */
	public function test_connection端點需要管理員(): void {
		$this->assertSame( 401, rest_do_request( new \WP_REST_Request( 'GET', '/power-partner/connection' ) )->get_status() );

		\wp_set_current_user( $this->customer_id );
		$this->assertSame( 403, rest_do_request( new \WP_REST_Request( 'GET', '/power-partner/connection' ) )->get_status() );
	}

	// ========== Proxy ==========

	/**
	 * @param string               $method method
	 * @param string               $route  本地路由（不含 namespace）
	 * @param array<string, mixed> $params 參數
	 * @return \WP_REST_Response
	 */
	private function proxy( string $method, string $route, array $params = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, "/power-partner/cloud/{$route}" );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) \wp_json_encode( $params ) );
		}
		return rest_do_request( $request );
	}

	/** @group security */
	public function test_proxy未登入一律拒絕(): void {
		$this->assertSame( 401, $this->proxy( 'GET', 'apps' )->get_status() );
		$this->assertSame( [], $this->requests );
	}

	/** @group security */
	public function test_proxy一般會員不能使用管理員路由(): void {
		\wp_set_current_user( $this->customer_id );

		$this->assertSame( 403, $this->proxy( 'GET', 'logs', [ 'user_id' => '777' ] )->get_status() );
		$this->assertSame( 403, $this->proxy( 'POST', 'v2/toggle-site', [ 'site_id' => 1 ] )->get_status() );
		$this->assertSame( [], $this->requests );
	}

	/** @group security */
	public function test_proxy以本站partner_id覆寫瀏覽器送來的身分參數(): void {
		\wp_set_current_user( $this->admin_id );
		$this->routes['GET apps'] = [ 200, [ 'status' => 200, 'data' => [ 'list' => [] ] ] ];

		$response = $this->proxy( 'GET', 'apps', [ 'user_id' => '999', 'offset' => '10' ] );

		$this->assertSame( 200, $response->get_status() );
		$sent = $this->sent( 'GET', 'apps' )[0];
		$this->assertSame( '777', $sent['query']['user_id'] ?? null );
		$this->assertSame( '10', $sent['query']['offset'] ?? null, '非身分參數原樣轉送' );
		$this->assertSame( $this->legacy_basic(), $sent['headers']['Authorization'] ?? null );
	}

	/** @group security */
	public function test_proxy一般會員只能查自己的站(): void {
		\wp_set_current_user( $this->customer_id );
		$this->routes['GET apps'] = [ 200, [ 'status' => 200, 'data' => [ 'list' => [] ] ] ];

		$this->proxy( 'GET', 'apps', [ 'user_id' => '777', 'customer_id' => '1' ] );

		$this->assertSame( (string) $this->customer_id, $this->sent( 'GET', 'apps' )[0]['query']['customer_id'] ?? null );
	}

	/** @group security */
	public function test_proxy一般會員不能改別人網站的網域(): void {
		\wp_set_current_user( $this->customer_id );
		$this->routes['GET apps'] = [ 200, [ 'status' => 200, 'data' => [ 'list' => [ [ 'ID' => 55 ] ] ] ] ];

		$response = $this->proxy( 'POST', 'change-domain', [ 'id' => 66, 'new_domain' => 'evil.test' ] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( [], $this->sent( 'POST', 'change-domain' ), '不屬於自己的站不可轉送' );
	}

	/** @group happy */
	public function test_proxy一般會員可以改自己網站的網域(): void {
		\wp_set_current_user( $this->customer_id );
		$this->routes['GET apps']           = [ 200, [ 'status' => 200, 'data' => [ 'list' => [ [ 'ID' => 55 ] ] ] ] ];
		$this->routes['POST change-domain'] = [ 200, [ 'status' => 200, 'message' => 'ok' ] ];

		$response = $this->proxy( 'POST', 'change-domain', [ 'id' => 55, 'new_domain' => 'mine.test' ] );

		$this->assertSame( 200, $response->get_status() );
		$sent = $this->sent( 'POST', 'change-domain' )[0];
		$this->assertSame( 55, $sent['body']['id'] ?? null );
		$this->assertSame( (string) $this->customer_id, $sent['body']['customer_id'] ?? null );
	}

	/** @group happy */
	public function test_proxy原樣轉送上游錯誤狀態與訊息(): void {
		\wp_set_current_user( $this->admin_id );
		$this->routes['POST v2/toggle-site'] = [ 403, [ 'status' => 403, 'message' => 'You are not the owner of this site #1' ] ];

		$response = $this->proxy( 'POST', 'v2/toggle-site', [ 'site_id' => 1 ] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'You are not the owner of this site #1', $response->get_data()['message'] ?? null );
	}

	/** @group happy */
	public function test_proxy明細在legacy模式由PHP補上經銷商帳密(): void {
		\wp_set_current_user( $this->admin_id );
		\update_option( CredentialStore::OLD_ACCOUNT_INFO_OPTION, self::OLD_CIPHER );
		$this->routes['POST logs/abc-1/detail'] = [ 200, [ 'status' => 200, 'data' => [] ] ];

		$this->proxy( 'POST', 'logs/abc-1/detail', [ 'format' => 'json', 'email' => 'browser@x', 'password' => 'browser' ] );

		$body = $this->sent( 'POST', 'logs/abc-1/detail' )[0]['body'];
		$this->assertSame( self::OLD_EMAIL, $body['email'] ?? null, '不採用瀏覽器送來的帳密' );
		$this->assertSame( 'json', $body['format'] ?? null );
	}

	/** @group happy */
	public function test_proxy明細在dealer模式不帶帳密(): void {
		\wp_set_current_user( $this->admin_id );
		$this->given_dealer();
		$this->routes['POST logs/abc-1/detail'] = [ 200, [ 'status' => 200, 'data' => [] ] ];

		$this->proxy( 'POST', 'logs/abc-1/detail', [ 'format' => 'json' ] );

		$sent = $this->sent( 'POST', 'logs/abc-1/detail' )[0];
		$this->assertArrayNotHasKey( 'password', $sent['body'] );
		$this->assertSame( $this->dealer_basic(), $sent['headers']['Authorization'] ?? null );
	}

	/** @group happy */
	public function test_proxy以原文輸出CSV(): void {
		$response = ProxyApi::to_rest_response(
			[
				'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary(
					[
						'content-type'        => 'text/csv; charset=utf-8',
						'content-disposition' => 'attachment; filename="log-abc.csv"; x=";rm -rf"',
					]
				),
				'body'     => "a,b\n1,2\n",
				'response' => [ 'code' => 200 ],
			]
		);

		$this->assertInstanceOf( RawResponse::class, $response );
		$this->assertSame( 'attachment; filename="log-abc.csv"', $response->get_headers()['Content-Disposition'] ?? null );

		ob_start();
		$served = RawResponse::serve( false, $response );
		$output = (string) ob_get_clean();
		$this->assertTrue( $served );
		$this->assertSame( "a,b\n1,2\n", $output );
	}

	// ========== Migration ==========

	/** @group happy */
	public function test_自動遷移成功後改用專屬密碼並刪除登入密碼(): void {
		\update_option( CredentialStore::OLD_ACCOUNT_INFO_OPTION, self::OLD_CIPHER );
		$this->routes['POST v3/connect'] = [
			200,
			[
				'user_login'   => self::DEALER_LOGIN,
				'app_password' => self::DEALER_PASSWORD,
				'identity'     => $this->identity_data(),
			],
		];

		Migration::run();

		$this->assertNotNull( CredentialStore::get_dealer() );
		$this->assertNull( CredentialStore::get_legacy_account() );
		$this->assertFalse( Migration::needs_migration() );
	}

	/** @group happy */
	public function test_CloudServer尚未支援時遷移保持pending明天再試(): void {
		\update_option( CredentialStore::OLD_ACCOUNT_INFO_OPTION, self::OLD_CIPHER );

		Migration::run();

		$this->assertSame( ConnectionService::MIGRATION_PENDING, ConnectionService::migration()['status'] );
		$this->assertTrue( Migration::needs_migration() );
		$this->assertNotNull( CredentialStore::get_legacy_account(), '尚未換到前登入密碼必須保留' );
	}

	/**
	 * 帳密錯誤時重試會觸發 CloudServer 的鎖帳號 —— 停止重試，改由 notice 請經銷商重新登入
	 *
	 * @group error
	 */
	public function test_遷移帳密錯誤時停止重試(): void {
		\update_option( CredentialStore::OLD_ACCOUNT_INFO_OPTION, self::OLD_CIPHER );
		$this->routes['POST v3/connect'] = [ 401, [ 'code' => 'auth_required', 'message' => 'x', 'data' => [ 'status' => 401, 'error_code' => 'auth_required' ] ] ];

		Migration::run();
		Migration::run();

		$this->assertCount( 1, $this->sent( 'POST', 'v3/connect' ) );
		$this->assertFalse( Migration::needs_migration() );
		$this->assertTrue( \J7\PowerPartner\Domains\Cloud\Core\Notices::should_prompt_relogin() );
	}

	// ========== 真實密碼不可在本站主機的 IP 上累積失敗（cloud.luke.cafe 的 Fluent Security 依 IP 封鎖）==========

	/** CloudServer `POST identity` 驗證真實密碼失敗時的真實回應：HTTP 200 包著 status 500 */
	private const IDENTITY_BAD_PASSWORD = [ 200, '{"status":500,"message":"email or password invalid","data":"<strong>Error:</strong> The password you entered is incorrect."}' ];

	/** 共用帳號被撤銷時 core 的回應：真實密碼根本沒被驗證 */
	private const SHARED_REVOKED = [ 401, '{"code":"incorrect_password","message":"The provided password is an invalid application password.","data":{"status":401}}' ];

	/** @group security */
	public function test_legacy帳密被拒一次後不再送出真實密碼(): void {
		\wp_set_current_user( $this->admin_id );
		CredentialStore::save_legacy_account( self::OLD_EMAIL, 'changed-on-cloud' );
		$this->routes['POST identity'] = self::IDENTITY_BAD_PASSWORD;

		$first = ConnectionService::status();

		$this->assertSame( 401, $first['status'] );
		$this->assertSame( CloudClient::MODE_LEGACY, $first['connection']['mode'], '不是「未連線」，AdminApp 要提示原因' );
		$this->assertStringContainsString( '重新登入', (string) $first['message'] );
		$this->assertTrue( CredentialStore::is_legacy_account_rejected() );

		ConnectionService::status();
		ConnectionService::status();
		$this->assertCount( 1, $this->sent( 'POST', 'identity' ), '之後每次打開 AdminApp 都不可再送出這組帳密' );

		$detail = $this->proxy( 'POST', 'logs/abc-1/detail', [ 'format' => 'json' ] );
		$this->assertSame( 401, $detail->get_status() );
		$this->assertSame( 'pp_relogin_required', $detail->get_data()['code'] ?? null );
		$this->assertSame( [], $this->sent( 'POST', 'logs/abc-1/detail' ), '點數明細也不可再送出帳密' );

		$this->assertFalse( Migration::needs_migration(), '自動遷移也不可再拿它去換專屬密碼' );
		$this->assertTrue( \J7\PowerPartner\Domains\Cloud\Core\Notices::should_prompt_relogin() );
	}

	/** @group security */
	public function test_點數明細回帳密錯誤時標記且不再送出(): void {
		\wp_set_current_user( $this->admin_id );
		CredentialStore::save_legacy_account( self::OLD_EMAIL, 'changed-on-cloud' );
		$this->routes['POST logs/abc-1/detail'] = [ 401, '{"status":401,"message":"email or password invalid"}' ];

		$this->proxy( 'POST', 'logs/abc-1/detail', [ 'format' => 'json' ] );
		$this->proxy( 'POST', 'logs/abc-1/detail', [ 'format' => 'json' ] );

		$this->assertTrue( CredentialStore::is_legacy_account_rejected() );
		$this->assertCount( 1, $this->sent( 'POST', 'logs/abc-1/detail' ) );
	}

	/**
	 * 共用帳號被撤銷時 core 回 401，但經銷商的真實密碼沒被驗證，不可誤判成「帳密錯誤」
	 *
	 * @group error
	 */
	public function test_共用帳號被撤銷不算經銷商帳密錯誤(): void {
		CredentialStore::save_legacy_account( self::OLD_EMAIL, self::OLD_PASSWORD );
		$this->routes['POST identity'] = self::SHARED_REVOKED;

		ConnectionService::status();

		$this->assertFalse( CredentialStore::is_legacy_account_rejected() );
	}

	/** @group security */
	public function test_遷移帳密錯誤時也標記帳密被拒(): void {
		\update_option( CredentialStore::OLD_ACCOUNT_INFO_OPTION, self::OLD_CIPHER );
		$this->routes['POST v3/connect'] = [ 401, [ 'code' => 'auth_required', 'message' => 'x', 'data' => [ 'status' => 401, 'error_code' => 'auth_required' ] ] ];

		Migration::run();

		$this->assertTrue( CredentialStore::is_legacy_account_rejected() );
		$this->assertSame( 401, ConnectionService::status()['status'] );
		$this->assertSame( [], $this->sent( 'POST', 'identity' ), '遷移失敗後打開 AdminApp 也不可再送出這組帳密' );
	}

	/** @group security */
	public function test_登入失敗第4次由本站擋下不送出(): void {
		$this->routes['POST v3/connect'] = [ 401, [ 'code' => 'auth_required', 'message' => 'x', 'data' => [ 'status' => 401, 'error_code' => 'auth_required' ] ] ];

		for ( $i = 0; $i < ConnectionService::LOGIN_MAX_FAILURES; $i++ ) {
			$this->assertSame( 401, ConnectionService::connect( self::OLD_EMAIL, 'wrong' )['status'] );
		}
		$blocked = ConnectionService::connect( self::OLD_EMAIL, 'wrong' );

		$this->assertSame( 429, $blocked['status'] );
		$this->assertSame( '嘗試次數過多，請 15 分鐘後再試', $blocked['message'] );
		$this->assertCount( ConnectionService::LOGIN_MAX_FAILURES, $this->sent( 'POST', 'v3/connect' ), '超過上限後不可再送出真實密碼' );

		\update_option(
			ConnectionService::LOGIN_FAILURES_OPTION,
			[
				'count' => ConnectionService::LOGIN_MAX_FAILURES,
				'since' => time() - ConnectionService::LOGIN_FAILURE_WINDOW - 1,
			],
			false
		);
		$this->assertFalse( ConnectionService::login_throttled(), '15 分鐘後恢復' );
	}

	/**
	 * 退回舊流程時，v3/connect 與 identity 是兩次真實密碼請求，只有真的失敗才計數
	 *
	 * @group security
	 */
	public function test_舊流程identity帳密錯誤也計入失敗次數(): void {
		$this->routes['POST identity'] = self::IDENTITY_BAD_PASSWORD;

		for ( $i = 0; $i < ConnectionService::LOGIN_MAX_FAILURES; $i++ ) {
			ConnectionService::connect( self::OLD_EMAIL, 'wrong' );
		}

		$this->assertSame( 429, ConnectionService::connect( self::OLD_EMAIL, 'wrong' )['status'] );
		$this->assertCount( ConnectionService::LOGIN_MAX_FAILURES, $this->sent( 'POST', 'identity' ) );
	}

	/** @group happy */
	public function test_登入成功清除失敗次數與帳密被拒標記(): void {
		CredentialStore::save_legacy_account( self::OLD_EMAIL, 'old' );
		CredentialStore::mark_legacy_account_rejected( 'bad_credentials' );
		$this->routes['POST identity'] = self::IDENTITY_BAD_PASSWORD;
		ConnectionService::connect( self::OLD_EMAIL, 'wrong' );
		ConnectionService::connect( self::OLD_EMAIL, 'wrong' );

		$this->routes['POST identity'] = [ 200, [ 'status' => 200, 'message' => 'success', 'data' => $this->identity_data() ] ];
		$this->assertSame( 200, ConnectionService::connect( self::OLD_EMAIL, self::OLD_PASSWORD )['status'] );

		$this->assertFalse( CredentialStore::is_legacy_account_rejected() );
		$this->assertFalse( ConnectionService::login_throttled() );
		$this->assertFalse( \get_option( ConnectionService::LOGIN_FAILURES_OPTION, false ) );
		$this->assertSame( 200, ConnectionService::status()['status'], '重新登入後恢復送出（新帳密）' );
	}

	/**
	 * proxy 的 identity 路由會把瀏覽器送來的真實密碼轉給 CloudServer，同樣受失敗次數上限保護
	 *
	 * @group security
	 */
	public function test_proxy_identity受本站失敗次數上限保護(): void {
		\wp_set_current_user( $this->admin_id );
		$this->routes['POST identity'] = self::IDENTITY_BAD_PASSWORD;

		for ( $i = 0; $i < ConnectionService::LOGIN_MAX_FAILURES; $i++ ) {
			$this->proxy( 'POST', 'identity', [ 'email' => 'a@b.c', 'password' => 'wrong' ] );
		}
		$blocked = $this->proxy( 'POST', 'identity', [ 'email' => 'a@b.c', 'password' => 'wrong' ] );

		$this->assertSame( 429, $blocked->get_status() );
		$this->assertCount( ConnectionService::LOGIN_MAX_FAILURES, $this->sent( 'POST', 'identity' ) );
	}

	// ========== CloudServer 停用 / 根除共用帳號之後不可無聲斷線 ==========

	/** CloudServer 試關共用帳號時的回應 */
	private const LEGACY_OFF = [ 401, '{"code":"legacy_auth_disabled","message":"legacy auth disabled","data":{"status":401,"error_code":"legacy_auth_disabled"}}' ];

	/**
	 * 經銷商等級暫時變 0 → 專屬密碼被拒成 rest_forbidden；共用帳號已停用時，不可因此「1 小時內全走 legacy」整站斷線
	 *
	 * @group error
	 */
	public function test_專屬密碼被拒且共用帳號已停用時下一個請求仍先試專屬密碼(): void {
		$this->given_dealer();
		$dealer_ok                            = false;
		$this->routes['POST v2/disable-site'] = function ( $request ) use ( &$dealer_ok ) {
			if ( $request['headers']['Authorization'] === $this->dealer_basic() ) {
				return $dealer_ok ? [ 200, [ 'status' => 200 ] ] : [ 403, [ 'code' => 'rest_forbidden', 'message' => 'x', 'data' => [ 'status' => 403 ] ] ];
			}
			return self::LEGACY_OFF;
		};

		$first = CloudClient::instance()->remote_post( 'v2/disable-site', [ 'site_id' => '1' ] );

		$this->assertSame( 403, \wp_remote_retrieve_response_code( $first ), '回傳專屬密碼的原始錯誤，不是共用帳號的' );
		$this->assertCount( 2, $this->sent( 'POST', 'v2/disable-site' ) );
		$this->assertTrue( CredentialStore::is_legacy_disabled() );
		$this->assertSame( CloudClient::MODE_DEALER, CloudClient::preferred_mode(), '共用帳號已停用，改走它沒有意義' );

		CloudClient::instance()->remote_post( 'v2/disable-site', [ 'site_id' => '1' ] );
		$sent = $this->sent( 'POST', 'v2/disable-site' );
		$this->assertCount( 3, $sent, '剛確認過共用帳號已停用，不再陪跑一次 legacy' );
		$this->assertSame( $this->dealer_basic(), $sent[2]['headers']['Authorization'] ?? null );

		$attention = ConnectionService::attention();
		$this->assertSame( 'disconnected', $attention['code'] ?? null );
		$this->assertStringNotContainsString( '不受影響', (string) ( $attention['message'] ?? '' ), '共用帳號停用後不可再說功能不受影響' );

		// 等級恢復：下一個請求就自己好了，notice 跟著消失
		$dealer_ok = true;
		$this->assertSame( 200, \wp_remote_retrieve_response_code( CloudClient::instance()->remote_post( 'v2/disable-site', [ 'site_id' => '1' ] ) ) );
		$this->assertFalse( CredentialStore::is_dealer_invalid() );
		$this->assertNull( ConnectionService::attention() );
	}

	/**
	 * 根除版刪掉共用帳號的密碼後，core 回 incorrect_password，同樣視為共用帳號已停用
	 *
	 * @group error
	 */
	public function test_根除後共用帳號回incorrect_password也視為已停用(): void {
		$this->given_dealer();
		$this->routes['GET apps'] = function ( $request ) {
			return $request['headers']['Authorization'] === $this->dealer_basic()
				? [ 403, [ 'code' => 'rest_forbidden', 'message' => 'x', 'data' => [ 'status' => 403 ] ] ]
				: self::SHARED_REVOKED;
		};

		CloudClient::instance()->remote_get( 'apps' );

		$this->assertTrue( CredentialStore::is_legacy_disabled() );
		$this->assertSame( CloudClient::MODE_DEALER, CloudClient::preferred_mode() );
	}

	/**
	 * 專屬密碼剛被拒、1 小時內暫走 legacy，期間 CloudServer 停用了共用帳號 → 當次改回專屬密碼
	 *
	 * @group error
	 */
	public function test_暫走共用帳號期間共用帳號被停用時當次改回專屬密碼(): void {
		$this->given_dealer();
		CredentialStore::mark_dealer_invalid( 'incorrect_password' );
		$this->assertSame( CloudClient::MODE_LEGACY, CloudClient::preferred_mode() );
		$this->routes['GET apps'] = function ( $request ) {
			return $request['headers']['Authorization'] === $this->dealer_basic() ? [ 200, [ 'status' => 200 ] ] : self::LEGACY_OFF;
		};

		$response = CloudClient::instance()->remote_get( 'apps' );

		$this->assertSame( 200, \wp_remote_retrieve_response_code( $response ) );
		$this->assertFalse( CredentialStore::is_dealer_invalid(), '專屬密碼又能用了，清掉失效標記' );
		$this->assertSame( CloudClient::MODE_DEALER, CloudClient::preferred_mode() );
	}

	/** @group happy */
	public function test_共用帳號恢復後清除停用標記(): void {
		CredentialStore::mark_legacy_disabled( 'legacy_auth_disabled' );
		$this->routes['GET apps'] = [ 200, [ 'status' => 200 ] ];

		CloudClient::instance()->remote_get( 'apps' );

		$this->assertFalse( CredentialStore::is_legacy_disabled(), '試關後又切回：沒有專屬密碼的站照常運作' );
	}

	/** @group error */
	public function test_共用帳號已停用時status不再送出identity(): void {
		CredentialStore::save_legacy_account( self::OLD_EMAIL, self::OLD_PASSWORD );
		CredentialStore::mark_legacy_disabled( 'legacy_auth_disabled' );

		$result = ConnectionService::status();

		$this->assertSame( 401, $result['status'] );
		$this->assertSame( '雲端連線已中斷，請重新登入。', $result['message'] );
		$this->assertSame( [], $this->sent( 'POST', 'identity' ) );
	}

	/** @group happy */
	public function test_提示_有partner_id卻讀不到任何憑證(): void {
		$attention = ConnectionService::attention();

		$this->assertSame( 'shared_only', $attention['code'] ?? null );
		$this->assertSame( '目前以共用帳號連線，共用帳號即將停用，請重新登入一次以換成專屬密碼（目前功能不受影響）。', $attention['message'] ?? null );

		\wp_set_current_user( $this->admin_id );
		ob_start();
		\J7\PowerPartner\Domains\Cloud\Core\Notices::instance()->render();
		$this->assertStringContainsString( '共用帳號即將停用', (string) ob_get_clean() );
	}

	/** @group happy */
	public function test_提示_不符資格(): void {
		CredentialStore::save_legacy_account( self::OLD_EMAIL, self::OLD_PASSWORD );
		ConnectionService::set_migration( ConnectionService::MIGRATION_FAILED, ConnectionService::EXCHANGE_NOT_ELIGIBLE );

		$attention = ConnectionService::attention();

		$this->assertSame( '此帳號目前不符合經銷商資格，請聯繫站長路可。', $attention['message'] ?? null );
		$this->assertFalse( $attention['relogin'] ?? true, '重新登入解決不了，AdminApp 不顯示「重新登入」' );
		$this->assertTrue( \J7\PowerPartner\Domains\Cloud\Core\Notices::should_prompt_relogin(), 'wp-admin 仍要提示' );
	}

	/** @group happy */
	public function test_提示_帳密失效的文案依共用帳號是否還能用而不同(): void {
		CredentialStore::save_legacy_account( self::OLD_EMAIL, 'old' );
		CredentialStore::mark_legacy_account_rejected( 'bad_credentials' );
		$this->assertSame( '雲端帳密已失效，請重新登入一次。目前功能不受影響。', ConnectionService::attention()['message'] ?? null );

		CredentialStore::mark_legacy_disabled( 'legacy_auth_disabled' );
		$this->assertSame( '雲端連線已中斷，請重新登入。', ConnectionService::attention()['message'] ?? null );
	}
}
