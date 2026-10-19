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
			CredentialStore::LEGACY_STATE_OPTION,
			ConnectionService::MIGRATION_OPTION,
			ConnectionService::INSTALLATION_OPTION,
			ConnectionService::REAL_PASSWORD_GATE_OPTION,
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

	/**
	 * 切換 wp-admin 目前頁面（Notices 的顯示範圍依它判斷）
	 *
	 * @param string $id screen id
	 * @return void
	 */
	private function screen( string $id ): void {
		if ( ! \function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		\set_current_screen( $id );
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

		// 429 會讓真實密碼閘門冷卻 30 分鐘（另有測試）；這裡只驗證回應格式的解析，先重置閘門
		\delete_option( ConnectionService::REAL_PASSWORD_GATE_OPTION );
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
	public function test_30分鐘內第2次帳密失敗後由本站擋下不送出(): void {
		$this->routes['POST v3/connect'] = [ 401, [ 'code' => 'auth_required', 'message' => 'x', 'data' => [ 'status' => 401, 'error_code' => 'auth_required' ] ] ];

		for ( $i = 0; $i < ConnectionService::REAL_PASSWORD_MAX_FAILURES; $i++ ) {
			$this->assertSame( 401, ConnectionService::connect( self::OLD_EMAIL, 'wrong' )['status'] );
		}
		$blocked = ConnectionService::connect( self::OLD_EMAIL, 'wrong' );

		$this->assertSame( 429, $blocked['status'] );
		$this->assertSame( '登入嘗試過於頻繁，請 30 分鐘後再試', $blocked['message'] );
		$this->assertCount( ConnectionService::REAL_PASSWORD_MAX_FAILURES, $this->sent( 'POST', 'v3/connect' ), '超過上限後不可再送出真實密碼' );

		\update_option(
			ConnectionService::REAL_PASSWORD_GATE_OPTION,
			[
				'failures'       => [ time() - ConnectionService::REAL_PASSWORD_WINDOW - 1, time() - ConnectionService::REAL_PASSWORD_WINDOW - 1 ],
				'cooldown_until' => 0,
			],
			false
		);
		$this->assertTrue( ConnectionService::may_send_real_password(), '滑出 30 分鐘視窗的失敗不再計入' );
	}

	/**
	 * 退回舊流程時，v3/connect 與 identity 是兩次真實密碼請求，只有真的失敗才計數
	 *
	 * @group security
	 */
	public function test_舊流程identity帳密錯誤也計入失敗次數(): void {
		$this->routes['POST identity'] = self::IDENTITY_BAD_PASSWORD;

		for ( $i = 0; $i < ConnectionService::REAL_PASSWORD_MAX_FAILURES; $i++ ) {
			ConnectionService::connect( self::OLD_EMAIL, 'wrong' );
		}

		$this->assertSame( 429, ConnectionService::connect( self::OLD_EMAIL, 'wrong' )['status'] );
		$this->assertCount( ConnectionService::REAL_PASSWORD_MAX_FAILURES, $this->sent( 'POST', 'identity' ) );
	}

	/**
	 * 成功只清除「帳密已被拒」標記，不清除失敗次數：Fluent Security 也不會因為一次成功就忘掉之前的失敗
	 *
	 * @group happy
	 */
	public function test_登入成功清除帳密被拒標記_失敗次數照滑動視窗計算(): void {
		CredentialStore::save_legacy_account( self::OLD_EMAIL, 'old' );
		CredentialStore::mark_legacy_account_rejected( 'bad_credentials' );
		$this->routes['POST identity'] = self::IDENTITY_BAD_PASSWORD;
		ConnectionService::connect( self::OLD_EMAIL, 'wrong' );

		$this->routes['POST identity'] = [ 200, [ 'status' => 200, 'message' => 'success', 'data' => $this->identity_data() ] ];
		$this->assertSame( 200, ConnectionService::connect( self::OLD_EMAIL, self::OLD_PASSWORD )['status'] );

		$this->assertFalse( CredentialStore::is_legacy_account_rejected() );
		$this->assertCount( 1, (array) ( \get_option( ConnectionService::REAL_PASSWORD_GATE_OPTION )['failures'] ?? [] ), '成功不抹掉先前的失敗' );
		$this->assertTrue( ConnectionService::may_send_real_password() );
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

		for ( $i = 0; $i < ConnectionService::REAL_PASSWORD_MAX_FAILURES; $i++ ) {
			$this->proxy( 'POST', 'identity', [ 'email' => 'a@b.c', 'password' => 'wrong' ] );
		}
		$blocked = $this->proxy( 'POST', 'identity', [ 'email' => 'a@b.c', 'password' => 'wrong' ] );

		$this->assertSame( 429, $blocked->get_status() );
		$this->assertCount( ConnectionService::REAL_PASSWORD_MAX_FAILURES, $this->sent( 'POST', 'identity' ) );
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
		$this->assertSame( '目前以共用帳號連線，共用帳號即將停用，請重新登入一次以換成專屬密碼。', $attention['message'] ?? null, '沒看過共用帳號成功，不可說功能不受影響' );

		CredentialStore::note_legacy_ok();
		$this->assertSame( '目前以共用帳號連線，共用帳號即將停用，請重新登入一次以換成專屬密碼（目前功能不受影響）。', ConnectionService::attention()['message'] ?? null );

		\wp_set_current_user( $this->admin_id );
		$this->screen( 'dashboard' );
		ob_start();
		\J7\PowerPartner\Domains\Cloud\Core\Notices::instance()->render();
		$this->assertStringContainsString( '共用帳號即將停用', (string) ob_get_clean() );
		$GLOBALS['current_screen'] = null;
	}

	/** @group happy */
	public function test_提示_不符資格(): void {
		CredentialStore::save_legacy_account( self::OLD_EMAIL, self::OLD_PASSWORD );
		ConnectionService::set_migration( ConnectionService::MIGRATION_FAILED, ConnectionService::EXCHANGE_NOT_ELIGIBLE );

		$attention = ConnectionService::attention();

		$this->assertSame( '此帳號目前不符合經銷商資格，共用帳號停用後將無法使用雲端功能，請聯繫站長路可。', $attention['message'] ?? null, '相容期與根除後都正確' );
		$this->assertFalse( $attention['relogin'] ?? true, '重新登入解決不了，AdminApp 不顯示「重新登入」' );
		$this->assertTrue( \J7\PowerPartner\Domains\Cloud\Core\Notices::should_prompt_relogin(), 'wp-admin 仍要提示' );
	}

	/** @group happy */
	public function test_提示_帳密失效的文案依共用帳號是否還能用而不同(): void {
		CredentialStore::save_legacy_account( self::OLD_EMAIL, 'old' );
		CredentialStore::mark_legacy_account_rejected( 'bad_credentials' );
		CredentialStore::note_legacy_ok();
		$this->assertSame( '雲端帳密已失效，請重新登入一次。目前功能不受影響。', ConnectionService::attention()['message'] ?? null );

		CredentialStore::mark_legacy_disabled( 'legacy_auth_disabled' );
		$this->assertSame( '雲端連線已中斷，請重新登入。', ConnectionService::attention()['message'] ?? null );
	}

	// ========== 自動遷移換到的帳號必須是本站綁定的經銷商 ==========

	/**
	 * 保存的帳密屬於另一個經銷商：保存下來的話之後每個請求都是 partner_mismatch（刻意不 fallback），整站斷線
	 *
	 * @group error
	 */
	public function test_遷移換到的帳號與本站partner不一致時不保存並撤銷(): void {
		\update_option( CredentialStore::OLD_ACCOUNT_INFO_OPTION, self::OLD_CIPHER );
		$this->routes['POST v3/connect']   = [
			200,
			[
				'user_login'   => 'other_dealer',
				'app_password' => 'OTHR PASS WORD',
				'uuid'         => 'uuid-other',
				'identity'     => [ 'user_id' => '888' ] + $this->identity_data(),
			],
		];
		$this->routes['DELETE v3/connect'] = [ 200, [ 'status' => 200 ] ];

		Migration::run();

		$this->assertNull( CredentialStore::get_dealer(), '不可保存別人的專屬密碼' );
		$revoke = $this->sent( 'DELETE', 'v3/connect' );
		$this->assertCount( 1, $revoke, '盡力撤銷，避免在 CloudServer 留下孤兒密碼' );
		$this->assertSame( 'Basic ' . base64_encode( 'other_dealer:OTHR PASS WORD' ), $revoke[0]['headers']['Authorization'] ?? null ); // phpcs:ignore

		$migration = ConnectionService::migration();
		$this->assertSame( ConnectionService::MIGRATION_FAILED, $migration['status'] );
		$this->assertSame( ConnectionService::EXCHANGE_PARTNER_MISMATCH, $migration['reason'] );
		$this->assertNotNull( CredentialStore::get_legacy_account(), '保留 legacy 帳密，功能照舊以共用帳號運作' );
		$this->assertSame( '777', \get_option( Connect::PARTNER_ID_OPTION_NAME ), '不可改掉本站綁定的經銷商' );
		$this->assertFalse( Migration::needs_migration() );
		$this->assertSame( '本站綁定的經銷商與登入帳號不同，請重新登入。', ConnectionService::attention()['message'] ?? null );
	}

	/**
	 * 手動登入以登入者為準：不比對、照常覆寫 partner_id
	 *
	 * @group happy
	 */
	public function test_手動登入不同經銷商時以登入者為準(): void {
		$this->routes['POST v3/connect'] = [
			200,
			[
				'user_login'   => 'other_dealer',
				'app_password' => 'OTHR PASS WORD',
				'identity'     => [ 'user_id' => '888' ] + $this->identity_data(),
			],
		];

		$result = ConnectionService::connect( 'other@x.test', 'pw' );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( '888', \get_option( Connect::PARTNER_ID_OPTION_NAME ) );
		$this->assertNotNull( CredentialStore::get_dealer() );
		$this->assertSame( [], $this->sent( 'DELETE', 'v3/connect' ) );
	}

	// ========== 客戶釋出授權碼前確認授權碼屬於自己 ==========

	/**
	 * @param array<int, int> $ids 這位客戶在這個經銷商底下擁有的授權碼 id
	 * @return void
	 */
	private function given_customer_license_codes( array $ids ): void {
		$this->routes['GET license-codes'] = [
			200,
			[
				'status'  => 200,
				'message' => 'success',
				'data'    => [
					'list'       => array_map( static fn( $id ) => [ 'id' => $id, 'status' => 'activated' ], $ids ),
					'pagination' => [ 'total' => count( $ids ) ],
				],
			],
		];
	}

	/**
	 * CloudServer legacy 模式只比對 customer_id：不同經銷商站上編號相同的客戶，可以互相釋出對方的授權碼
	 *
	 * @group security
	 */
	public function test_proxy一般會員不能釋出別人的授權碼(): void {
		\wp_set_current_user( $this->customer_id );
		$this->given_customer_license_codes( [ 10 ] );

		$response = $this->proxy( 'POST', 'license-codes/release', [ 'ids' => [ 10, 11 ] ] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'not_owner', $response->get_data()['code'] ?? null );
		$this->assertSame( [], $this->sent( 'POST', 'license-codes/release' ), '只要有一個不是自己的就不可轉送' );

		$query = $this->sent( 'GET', 'license-codes' )[0]['query'];
		$this->assertSame( '777', $query['author'] ?? null, '以本站經銷商查' );
		$this->assertSame( (string) $this->customer_id, $query['customer_id'] ?? null, '只查這位客戶自己的' );
		$this->assertSame( '-1', $query['posts_per_page'] ?? null );
	}

	/** @group happy */
	public function test_proxy一般會員可以釋出自己的授權碼(): void {
		\wp_set_current_user( $this->customer_id );
		$this->given_customer_license_codes( [ 10, 11 ] );
		$this->routes['POST license-codes/release'] = [ 200, [ 'code' => 'release_success', 'message' => 'ok', 'data' => [ 'ids' => [ 10, 11 ] ] ] ];

		$response = $this->proxy( 'POST', 'license-codes/release', [ 'ids' => [ 10, 11 ] ] );

		$this->assertSame( 200, $response->get_status() );
		$sent = $this->sent( 'POST', 'license-codes/release' )[0];
		$this->assertSame( [ 10, 11 ], $sent['body']['ids'] ?? null );
		$this->assertSame( (string) $this->customer_id, $sent['body']['customer_id'] ?? null );
	}

	/** @group error */
	public function test_proxy確認不了授權碼歸屬時不放行(): void {
		\wp_set_current_user( $this->customer_id );
		$this->routes['GET license-codes'] = [ 500, [ 'code' => 'x', 'message' => 'x' ] ];

		$response = $this->proxy( 'POST', 'license-codes/release', [ 'ids' => [ 10 ] ] );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( [], $this->sent( 'POST', 'license-codes/release' ) );
	}

	/** @group security */
	public function test_proxy釋出授權碼的ids格式不對時拒絕(): void {
		\wp_set_current_user( $this->customer_id );
		$this->given_customer_license_codes( [ 10 ] );

		$this->assertSame( 403, $this->proxy( 'POST', 'license-codes/release', [] )->get_status() );
		$this->assertSame( 403, $this->proxy( 'POST', 'license-codes/release', [ 'ids' => [ 'abc' ] ] )->get_status() );
		$this->assertSame( [], $this->sent( 'POST', 'license-codes/release' ) );
	}

	/** @group happy */
	public function test_proxy管理員釋出授權碼不需確認歸屬(): void {
		\wp_set_current_user( $this->admin_id );
		$this->routes['POST license-codes/release'] = [ 200, [ 'code' => 'release_success', 'message' => 'ok' ] ];

		$this->assertSame( 200, $this->proxy( 'POST', 'license-codes/release', [ 'ids' => [ 99 ] ] )->get_status() );
		$this->assertSame( [], $this->sent( 'GET', 'license-codes' ) );
	}

	// ========== 第四輪：只有明確的帳密錯誤才標記、共用的真實密碼閘門、提示範圍、根除後 not_owner ==========

	/** CloudServer 新契約：wp_authenticate 失敗但不是帳密錯誤（Fluent 封鎖 IP、驗證碼、2FA）時，回 CredentialThrottle 的 429 */
	private const RATE_LIMITED = [ 429, '{"code":"rate_limited","message":"too many attempts","data":{"status":429,"error_code":"rate_limited"}}' ];

	/** @group error */
	public function test_identity回429不寫帳密被拒標記並冷卻30分鐘(): void {
		CredentialStore::save_legacy_account( self::OLD_EMAIL, self::OLD_PASSWORD );
		$this->routes['POST identity'] = self::RATE_LIMITED;

		$first = ConnectionService::status();

		$this->assertSame( 429, $first['status'] );
		$this->assertStringContainsString( '30 分鐘後再試', (string) $first['message'] );
		$this->assertFalse( CredentialStore::is_legacy_account_rejected(), 'IP 被封鎖不是帳密錯誤' );
		$this->assertFalse( ConnectionService::may_send_real_password() );

		ConnectionService::status();
		$this->assertCount( 1, $this->sent( 'POST', 'identity' ), '冷卻期間每一次嘗試都會延長 Fluent 的封鎖，完全不送' );
	}

	/** @group error */
	public function test_identity的body_status429也算暫時性(): void {
		CredentialStore::save_legacy_account( self::OLD_EMAIL, self::OLD_PASSWORD );
		$this->routes['POST identity'] = [ 200, [ 'status' => 429, 'message' => 'email or password invalid' ] ];

		ConnectionService::status();

		$this->assertFalse( CredentialStore::is_legacy_account_rejected() );
		$this->assertFalse( ConnectionService::may_send_real_password() );
	}

	/** @group error */
	public function test_遷移遇到429維持pending不標failed(): void {
		\update_option( CredentialStore::OLD_ACCOUNT_INFO_OPTION, self::OLD_CIPHER );
		$this->routes['POST v3/connect'] = self::RATE_LIMITED;

		Migration::run();

		$this->assertSame( ConnectionService::MIGRATION_PENDING, ConnectionService::migration()['status'] );
		$this->assertFalse( CredentialStore::is_legacy_account_rejected() );
		$this->assertTrue( Migration::needs_migration(), '明天再試' );
		$this->assertFalse( ConnectionService::may_send_real_password() );
	}

	/** @group happy */
	public function test_帳密被拒標記24小時後允許重試一次(): void {
		CredentialStore::save_legacy_account( self::OLD_EMAIL, self::OLD_PASSWORD );
		\update_option(
			CredentialStore::LEGACY_REJECTED_OPTION,
			[
				'reason' => 'bad_credentials',
				'at'     => time() - CredentialStore::LEGACY_REJECTED_TTL - 1,
			],
			false
		);
		$this->assertFalse( CredentialStore::is_legacy_account_rejected(), '24 小時後過期' );
		$this->routes['POST identity'] = self::IDENTITY_BAD_PASSWORD;

		ConnectionService::status();
		ConnectionService::status();

		$this->assertCount( 1, $this->sent( 'POST', 'identity' ), '過期後只重試一次，失敗就再標記' );
		$this->assertTrue( CredentialStore::is_legacy_account_rejected() );
	}

	/** @group happy */
	public function test_帳密被拒標記過期後重試成功就清除(): void {
		CredentialStore::save_legacy_account( self::OLD_EMAIL, self::OLD_PASSWORD );
		\update_option(
			CredentialStore::LEGACY_REJECTED_OPTION,
			[
				'reason' => 'bad_credentials',
				'at'     => time() - CredentialStore::LEGACY_REJECTED_TTL - 1,
			],
			false
		);
		$this->routes['POST identity'] = [ 200, [ 'status' => 200, 'message' => 'success', 'data' => $this->identity_data() ] ];

		$this->assertSame( 200, ConnectionService::status()['status'] );
		$this->assertFalse( \get_option( CredentialStore::LEGACY_REJECTED_OPTION, false ), '之前是誤判（例如舊版 CloudServer 在 IP 被封鎖時也回帳密錯誤）' );
	}

	/** @group security */
	public function test_429後30分鐘內任何路徑都不送真實密碼(): void {
		\wp_set_current_user( $this->admin_id );
		\update_option( CredentialStore::OLD_ACCOUNT_INFO_OPTION, self::OLD_CIPHER );
		ConnectionService::record_real_password_outcome( [ 'response' => [ 'code' => 429 ], 'body' => '{}' ], ConnectionService::SEND_IDENTITY );

		$this->assertSame( '登入嘗試過於頻繁，請 30 分鐘後再試', ConnectionService::connect( self::OLD_EMAIL, self::OLD_PASSWORD )['message'] );
		ConnectionService::status();
		Migration::run();
		$detail   = $this->proxy( 'POST', 'logs/abc-1/detail', [ 'format' => 'json' ] );
		$identity = $this->proxy( 'POST', 'identity', [ 'email' => 'a@b.c', 'password' => 'x' ] );

		$this->assertSame( 429, $detail->get_status() );
		$this->assertSame( 429, $identity->get_status() );
		$this->assertSame( [], $this->requests, '登入、查身分、遷移、點數明細、proxy identity 全都不可送出' );
		$this->assertSame( ConnectionService::MIGRATION_PENDING, ConnectionService::migration()['status'], '遷移明天再試，不是 failed' );
	}

	/**
	 * v3/connect 暫時錯誤時會再打一次 identity：兩次各自計數
	 *
	 * @group security
	 */
	public function test_temporary退回舊流程的兩次請求各算一次(): void {
		$this->routes['POST v3/connect'] = [ 500, [ 'code' => 'x', 'message' => 'x' ] ];
		$this->routes['POST identity']   = self::IDENTITY_BAD_PASSWORD;

		ConnectionService::connect( self::OLD_EMAIL, 'wrong' );
		$blocked = ConnectionService::connect( self::OLD_EMAIL, 'wrong' );

		$this->assertSame( 429, $blocked['status'], '一次 connect 已經送出兩次真實密碼' );
		$this->assertCount( 1, $this->sent( 'POST', 'v3/connect' ) );
		$this->assertCount( 1, $this->sent( 'POST', 'identity' ) );
	}

	/**
	 * v3/connect 那一次失敗剛好讓閘門關上：退回舊流程的 identity 不可再送，回明確訊息
	 *
	 * @group security
	 */
	public function test_connect退回舊流程時identity被閘門擋下也回明確訊息(): void {
		ConnectionService::record_real_password_outcome( [ 'response' => [ 'code' => 401 ], 'body' => '{"code":"auth_required"}' ], ConnectionService::SEND_CONNECT );
		$this->routes['POST v3/connect'] = [ 500, [ 'code' => 'x', 'message' => 'x' ] ];
		$this->routes['POST identity']   = [ 200, [ 'status' => 200, 'message' => 'success', 'data' => $this->identity_data() ] ];

		$result = ConnectionService::connect( self::OLD_EMAIL, self::OLD_PASSWORD );

		$this->assertSame( 429, $result['status'] );
		$this->assertSame( '登入嘗試過於頻繁，請 30 分鐘後再試', $result['message'] );
		$this->assertSame( [], $this->sent( 'POST', 'identity' ) );
	}

	/** @group security */
	public function test_遷移的帳密失敗也計入同一個閘門(): void {
		\update_option( CredentialStore::OLD_ACCOUNT_INFO_OPTION, self::OLD_CIPHER );
		$this->routes['POST v3/connect'] = [ 401, [ 'code' => 'auth_required', 'message' => 'x', 'data' => [ 'status' => 401, 'error_code' => 'auth_required' ] ] ];

		Migration::run();
		ConnectionService::connect( self::OLD_EMAIL, 'wrong' );

		$this->assertSame( 429, ConnectionService::connect( self::OLD_EMAIL, 'wrong' )['status'] );
		$this->assertCount( 2, $this->sent( 'POST', 'v3/connect' ) );
	}

	/** @group happy */
	public function test_提示_不受影響只在24小時內看過共用帳號成功(): void {
		$this->given_dealer();
		CredentialStore::mark_dealer_invalid( 'incorrect_password' );
		$this->assertSame( '站長路可拒絕了本站的專屬金鑰，請重新登入一次。', ConnectionService::attention()['message'] ?? null, '根除後專屬密碼被撤銷時，不能靠「沒看過共用帳號失敗」推論它還能用' );

		CredentialStore::note_legacy_ok();
		$this->assertSame( '站長路可拒絕了本站的專屬金鑰，請重新登入一次。目前功能不受影響。', ConnectionService::attention()['message'] ?? null );

		\update_option(
			CredentialStore::LEGACY_STATE_OPTION,
			[
				'disabled_at' => 0,
				'reason'      => '',
				'ok_at'       => time() - CredentialStore::LEGACY_OK_FRESH - 1,
			],
			false
		);
		$this->assertSame( '站長路可拒絕了本站的專屬金鑰，請重新登入一次。', ConnectionService::attention()['message'] ?? null );
	}

	/** @group happy */
	public function test_提示_試關結束共用帳號恢復後中斷提示立即消失(): void {
		CredentialStore::mark_legacy_disabled( 'legacy_auth_disabled' );
		$this->assertSame( 'disconnected', ConnectionService::attention()['code'] ?? null );
		$this->routes['GET apps'] = [ 200, [ 'status' => 200 ] ];

		CloudClient::instance()->remote_get( 'apps' );

		$this->assertNotSame( 'disconnected', ConnectionService::attention()['code'] ?? null );
		$this->assertTrue( CredentialStore::legacy_ok_recently() );
	}

	/**
	 * 模板站 / clone 站帶著 partner_id 時，終端客戶的後台不可出現看不懂的提示
	 *
	 * @group happy
	 */
	public function test_提示_沒有憑證只在PowerPartner頁面與首頁顯示(): void {
		\wp_set_current_user( $this->admin_id );
		$notices = \J7\PowerPartner\Domains\Cloud\Core\Notices::instance();
		$render  = static function () use ( $notices ): string {
			ob_start();
			$notices->render();
			return (string) ob_get_clean();
		};

		$this->screen( 'edit-post' );
		$this->assertSame( '', $render(), '一般 wp-admin 頁面不顯示' );
		$this->screen( 'dashboard' );
		$this->assertStringContainsString( '共用帳號即將停用', $render() );
		$this->screen( 'powerhouse_page_power-partner' );
		$this->assertStringContainsString( '共用帳號即將停用', $render() );

		// 真的斷線的提示維持全域顯示
		CredentialStore::mark_legacy_disabled( 'legacy_auth_disabled' );
		$this->screen( 'edit-post' );
		$this->assertStringContainsString( '雲端連線已中斷', $render() );
		$GLOBALS['current_screen'] = null;
	}

	/**
	 * 根除後 recover / expire 收到 not_owner：重送 legacy 只會拿到 401，蓋掉真正原因
	 *
	 * @group error
	 */
	public function test_共用帳號已停用時not_owner回原始403不重送(): void {
		$this->given_dealer();
		CredentialStore::mark_legacy_disabled( 'legacy_auth_disabled' );
		$this->routes['POST license-codes/recover'] = [ 403, [ 'code' => 'not_owner', 'message' => '授權碼作者不是本人', 'data' => [ 'status' => 403, 'error_code' => 'not_owner' ] ] ];

		$response = CloudClient::instance()->remote_post( 'license-codes/recover', [ 'ids' => [ 1 ] ], [ 'legacy_on_not_owner' => true ] );

		$this->assertSame( 403, \wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( 'not_owner', CloudClient::error_code( $response ) );
		$this->assertCount( 1, $this->sent( 'POST', 'license-codes/recover' ) );
	}

	/** @group error */
	public function test_not_owner重送時才發現共用帳號已停用也回原始403(): void {
		$this->given_dealer();
		$this->routes['POST license-codes/recover'] = function ( $request ) {
			return $request['headers']['Authorization'] === $this->dealer_basic()
				? [ 403, [ 'code' => 'not_owner', 'message' => '授權碼作者不是本人', 'data' => [ 'status' => 403, 'error_code' => 'not_owner' ] ] ]
				: self::LEGACY_OFF;
		};

		$response = CloudClient::instance()->remote_post( 'license-codes/recover', [ 'ids' => [ 1 ] ], [ 'legacy_on_not_owner' => true ] );

		$this->assertSame( 'not_owner', CloudClient::error_code( $response ), '訂單備註要看得到真正原因' );
		$this->assertTrue( CredentialStore::is_legacy_disabled() );
	}

	// ========== 第五輪：帳號需要互動式驗證（login_challenge）、遷移長期 pending ==========

	/** CloudServer 契約：登入驗證碼、two-factor、email 2FA 讓 wp_authenticate 失敗時回 403 login_challenge */
	private const LOGIN_CHALLENGE = [ 403, '{"code":"login_challenge","message":"此帳號需要登入驗證碼或兩步驟驗證，請改用瀏覽器登入站長路可","data":{"status":403,"error_code":"login_challenge"}}' ];

	/** @return int 閘門目前的失敗次數 */
	private function gate_failures(): int {
		$gate = \get_option( ConnectionService::REAL_PASSWORD_GATE_OPTION, [] );
		return is_array( $gate ) ? count( (array) ( $gate['failures'] ?? [] ) ) : 0;
	}

	/** @group error */
	public function test_遷移收到login_challenge標成failed寫入標記且之後不再送出真實密碼(): void {
		\wp_set_current_user( $this->admin_id );
		\update_option( CredentialStore::OLD_ACCOUNT_INFO_OPTION, self::OLD_CIPHER );
		$this->routes['POST v3/connect'] = self::LOGIN_CHALLENGE;

		Migration::run();

		$migration = ConnectionService::migration();
		$this->assertSame( ConnectionService::MIGRATION_FAILED, $migration['status'] );
		$this->assertSame( ConnectionService::EXCHANGE_LOGIN_CHALLENGE, $migration['reason'] );
		$this->assertSame( CredentialStore::REJECTED_LOGIN_CHALLENGE, CredentialStore::legacy_account_rejected_reason() );
		$this->assertFalse( Migration::needs_migration() );
		$this->assertSame( 0, $this->gate_failures(), '不是帳密錯誤，不計入閘門' );
		$this->assertTrue( ConnectionService::may_send_real_password(), '也不是 429，不觸發冷卻' );

		ConnectionService::status();
		$detail = $this->proxy( 'POST', 'logs/abc-1/detail', [ 'format' => 'json' ] );
		Migration::run();

		$this->assertSame( 401, $detail->get_status() );
		$this->assertSame( [], $this->sent( 'POST', 'identity' ), 'status() 不可再送出（每試一次都會被 Fluent 計數、可能寄 2FA 信）' );
		$this->assertSame( [], $this->sent( 'POST', 'logs/abc-1/detail' ), '明細下載也不可再送出' );
		$this->assertCount( 1, $this->sent( 'POST', 'v3/connect' ) );

		$attention = ConnectionService::attention();
		$this->assertSame( '此帳號啟用了登入驗證碼或兩步驟驗證，無法自動換成專屬密碼，請聯繫站長路可協助。', $attention['message'] ?? null );
		$this->assertSame( ConnectionService::SCOPE_POWER_PARTNER, $attention['scope'] ?? null );
	}

	/**
	 * login_challenge 是永久性的：不適用 24 小時過期重試
	 *
	 * @group error
	 */
	public function test_login_challenge標記不會過期(): void {
		CredentialStore::save_legacy_account( self::OLD_EMAIL, self::OLD_PASSWORD );
		\update_option(
			CredentialStore::LEGACY_REJECTED_OPTION,
			[
				'reason' => CredentialStore::REJECTED_LOGIN_CHALLENGE,
				'at'     => time() - CredentialStore::LEGACY_REJECTED_TTL * 10,
			],
			false
		);
		$this->routes['POST identity'] = [ 200, [ 'status' => 200, 'message' => 'success', 'data' => $this->identity_data() ] ];

		ConnectionService::status();

		$this->assertTrue( CredentialStore::is_legacy_account_rejected() );
		$this->assertSame( [], $this->sent( 'POST', 'identity' ) );
	}

	/** @group error */
	public function test_status收到login_challenge寫入標記且不計入閘門(): void {
		CredentialStore::save_legacy_account( self::OLD_EMAIL, self::OLD_PASSWORD );
		$this->routes['POST identity'] = self::LOGIN_CHALLENGE;

		$result = ConnectionService::status();
		ConnectionService::status();

		$this->assertSame( 401, $result['status'] );
		$this->assertSame( '此帳號啟用了登入驗證碼或兩步驟驗證，無法自動換成專屬密碼，請聯繫站長路可協助。', $result['message'] );
		$this->assertSame( CredentialStore::REJECTED_LOGIN_CHALLENGE, CredentialStore::legacy_account_rejected_reason() );
		$this->assertCount( 1, $this->sent( 'POST', 'identity' ) );
		$this->assertSame( 0, $this->gate_failures() );
	}

	/** @group error */
	public function test_明細下載收到login_challenge寫入標記之後不再送出(): void {
		\wp_set_current_user( $this->admin_id );
		CredentialStore::save_legacy_account( self::OLD_EMAIL, self::OLD_PASSWORD );
		$this->routes['POST logs/abc-1/detail'] = self::LOGIN_CHALLENGE;

		$this->proxy( 'POST', 'logs/abc-1/detail', [ 'format' => 'json' ] );
		$this->proxy( 'POST', 'logs/abc-1/detail', [ 'format' => 'json' ] );

		$this->assertSame( CredentialStore::REJECTED_LOGIN_CHALLENGE, CredentialStore::legacy_account_rejected_reason() );
		$this->assertCount( 1, $this->sent( 'POST', 'logs/abc-1/detail' ) );
	}

	/** @group happy */
	public function test_手動登入收到login_challenge回傳server訊息_成功登入才清除標記(): void {
		CredentialStore::save_legacy_account( self::OLD_EMAIL, self::OLD_PASSWORD );
		CredentialStore::mark_legacy_account_rejected( CredentialStore::REJECTED_LOGIN_CHALLENGE );
		$this->routes['POST v3/connect'] = self::LOGIN_CHALLENGE;

		$failed = ConnectionService::connect( self::OLD_EMAIL, self::OLD_PASSWORD );

		$this->assertSame( 403, $failed['status'] );
		$this->assertSame( '此帳號需要登入驗證碼或兩步驟驗證，請改用瀏覽器登入站長路可', $failed['message'] );
		$this->assertSame( [], $this->sent( 'POST', 'identity' ), '不退回舊流程（identity 一樣過不了）' );
		$this->assertTrue( CredentialStore::is_legacy_account_rejected() );

		$this->routes['POST v3/connect'] = [
			200,
			[
				'user_login'   => self::DEALER_LOGIN,
				'app_password' => self::DEALER_PASSWORD,
				'identity'     => $this->identity_data(),
			],
		];
		$this->assertSame( 200, ConnectionService::connect( self::OLD_EMAIL, self::OLD_PASSWORD )['status'] );
		$this->assertFalse( CredentialStore::is_legacy_account_rejected() );
		$this->assertNull( ConnectionService::attention() );
	}

	/**
	 * login_challenge 只出現在真實密碼的路徑，不是「專屬密碼被拒」：不可觸發 dealer → legacy 重送
	 *
	 * @group security
	 */
	public function test_login_challenge不算專屬密碼被拒(): void {
		$response = [
			'headers'  => [],
			'body'     => self::LOGIN_CHALLENGE[1],
			'response' => [ 'code' => 403 ],
		];

		$this->assertTrue( ConnectionService::is_login_challenge( $response ) );
		$this->assertFalse( CloudClient::is_credential_rejected( $response ) );
		$this->assertNotContains( ConnectionService::LOGIN_CHALLENGE_CODE, CloudClient::CREDENTIAL_REJECTED_CODES );
	}

	/** @group happy */
	public function test_遷移連續停在pending滿3天才提示(): void {
		\update_option( CredentialStore::OLD_ACCOUNT_INFO_OPTION, self::OLD_CIPHER );
		$this->routes['POST v3/connect'] = self::RATE_LIMITED;
		Migration::run();
		$this->assertNull( ConnectionService::attention(), '剛開始 pending，不打擾經銷商' );

		$this->set_pending_since( time() - ConnectionService::MIGRATION_STUCK_AFTER + 3600 );
		$this->assertNull( ConnectionService::attention(), '未滿 3 天不顯示' );

		$this->set_pending_since( time() - ConnectionService::MIGRATION_STUCK_AFTER - 1 );
		$attention = ConnectionService::attention();
		$this->assertSame( 'migration_stuck', $attention['code'] ?? null );
		$this->assertSame( '已連續 3 天無法自動換成專屬密碼，請到 Power Partner 重新登入一次；若仍失敗請聯繫站長路可。', $attention['message'] ?? null );
		$this->assertSame( ConnectionService::SCOPE_POWER_PARTNER, $attention['scope'] ?? null );
	}

	/** @group happy */
	public function test_每天重試仍pending時保留起點_手動登入重新起算(): void {
		\update_option( CredentialStore::OLD_ACCOUNT_INFO_OPTION, self::OLD_CIPHER );
		$since = time() - ConnectionService::MIGRATION_STUCK_AFTER - 1;
		$this->set_pending_since( $since );
		$this->routes['POST v3/connect'] = [ 500, [ 'code' => 'x', 'message' => 'x' ] ];

		Migration::run();
		$this->assertSame( $since, ConnectionService::migration()['since'], '每日重試失敗不重設起點' );
		$this->assertSame( 'migration_stuck', ConnectionService::attention()['code'] ?? null );

		// 手動重新登入：CloudServer 尚無 v3/connect → 退回舊流程成功，遷移仍是 pending
		unset( $this->routes['POST v3/connect'] );
		\delete_option( ConnectionService::REAL_PASSWORD_GATE_OPTION );
		$this->routes['POST identity'] = [ 200, [ 'status' => 200, 'message' => 'success', 'data' => $this->identity_data() ] ];
		$this->assertSame( 200, ConnectionService::connect( self::OLD_EMAIL, self::OLD_PASSWORD )['status'] );
		$this->assertSame( ConnectionService::MIGRATION_PENDING, ConnectionService::migration()['status'] );
		$this->assertGreaterThan( $since, ConnectionService::migration()['since'], '手動重新登入 = 照提示做了，重新起算' );
		$this->assertNull( ConnectionService::attention() );
	}

	/**
	 * @param int $since pending 的起點
	 * @return void
	 */
	private function set_pending_since( int $since ): void {
		\update_option(
			ConnectionService::MIGRATION_OPTION,
			[
				'status' => ConnectionService::MIGRATION_PENDING,
				'reason' => ConnectionService::EXCHANGE_RATE_LIMITED,
				'at'     => time(),
				'since'  => $since,
			],
			false
		);
	}
}
