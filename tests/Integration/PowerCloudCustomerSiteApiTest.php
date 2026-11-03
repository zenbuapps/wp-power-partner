<?php
/**
 * 前台客戶的 PowerCloud 站台：瀏覽器不持有 API key
 *
 * 舊版把 PowerCloud API key 送到每個前台頁面，任何登入的客戶都能拿來操作經銷商底下的所有站台。
 * 本檔鎖住：
 * - key 只給 wp-admin 的管理員
 * - 站台歸屬由 PHP 判斷：訂閱綁定優先，沒綁任何訂閱才比對 adminEmail
 * - 回應只含 PUBLIC_FIELDS，寫入操作不轉送上游回應
 */

declare( strict_types=1 );

namespace Tests\Integration;

use J7\PowerPartner\Api\Main;
use J7\PowerPartner\Bootstrap;
use J7\PowerPartner\Plugin;
use J7\PowerPartner\Product\SiteSync;

/**
 * @group security
 */
class PowerCloudCustomerSiteApiTest extends TestCase {

	private const API_KEY = 'sk-test-powercloud-key';

	private const SITES_ROUTE = '/power-partner/powercloud/sites';

	private const SITE_OWN       = 'aaaaaaaa-0000-4000-8000-000000000001';
	private const SITE_LINKED    = 'aaaaaaaa-0000-4000-8000-000000000002';
	private const SITE_OTHER     = 'aaaaaaaa-0000-4000-8000-000000000003';
	private const SITE_HIJACK    = 'aaaaaaaa-0000-4000-8000-000000000004';
	private const SITE_CANCELLED = 'aaaaaaaa-0000-4000-8000-000000000005';

	/** @var int 管理員 */
	private int $admin_id;

	/** @var int 客戶 A */
	private int $customer_id;

	/** @var int 客戶 B */
	private int $other_customer_id;

	/** @var array<int, array{url: string, args: array<string, mixed>}> 攔截到的 HTTP 請求 */
	private array $http_requests = [];

	/** @var array<int, array<string, mixed>> /websites 回傳的站台 */
	private array $websites = [];

	/** @var array<string, mixed>|\WP_Error|null PATCH 的假回應；null 為成功 */
	private $patch_response = null;

	/** @var array<string, mixed>|\WP_Error|null GET /websites 的假回應；null 為依 $websites 分頁 */
	private $list_response = null;

	/** @var callable pre_http_request filter */
	private $http_filter;

	protected function configure_dependencies(): void {
		$this->admin_id          = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$this->customer_id       = $this->factory()->user->create(
			[
				'role'       => 'customer',
				'user_email' => 'customer@test.com',
			]
		);
		$this->other_customer_id = $this->factory()->user->create(
			[
				'role'       => 'customer',
				'user_email' => 'other@test.com',
			]
		);

		\set_transient( Main::POWERCLOUD_API_KEY_TRANSIENT_KEY, self::API_KEY );

		$this->websites = [
			$this->website( self::SITE_OWN, 'CUSTOMER@test.com' ),
			$this->website( self::SITE_OTHER, 'other@test.com' ),
		];

		$this->http_filter = function ( $pre, $args, $url ) {
			$this->http_requests[] = [
				'url'  => (string) $url,
				'args' => (array) $args,
			];
			return 'PATCH' === ( $args['method'] ?? '' ) ? $this->fake_patch() : $this->fake_list( (string) $url );
		};
		\add_filter( 'pre_http_request', $this->http_filter, 10, 3 );

		rest_get_server();
	}

	public function tear_down(): void {
		\remove_filter( 'pre_http_request', $this->http_filter, 10 );
		\wp_set_current_user( 0 );
		$GLOBALS['current_screen'] = null; // phpcs:ignore
		$GLOBALS['wp_scripts']     = null; // phpcs:ignore
		\delete_transient( Main::POWERCLOUD_API_KEY_TRANSIENT_KEY );
		parent::tear_down();
	}

	/**
	 * @param string $id          站台 id
	 * @param string $admin_email adminEmail
	 * @return array<string, mixed>
	 */
	private function website( string $id, string $admin_email ): array {
		return [
			'id'                   => $id,
			'name'                 => 'site',
			'wildcardDomain'       => 'site.wpsite.pro',
			'status'               => 'running',
			'ipAddress'            => '1.2.3.4',
			'adminEmail'           => $admin_email,
			'adminPassword'        => "admin-password-{$id}",
			'databasePassword'     => 'database-password-secret',
			'databaseRootPassword' => 'database-root-secret',
			'sshPassword'          => 'ssh-password-secret',
			'memo'                 => 'memo',
			'createdAt'            => '2026-01-01T00:00:00.000Z',
		];
	}

	/**
	 * @param int    $code HTTP status
	 * @param string $body body
	 * @return array<string, mixed>
	 */
	private function http_response( int $code, string $body ): array {
		return [
			'headers'  => [],
			'body'     => $body,
			'response' => [
				'code'    => $code,
				'message' => '',
			],
			'cookies'  => [],
		];
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private function fake_patch() {
		return $this->patch_response ?? $this->http_response(
			200,
			(string) \wp_json_encode( [ 'adminPassword' => 'password-in-upstream-response' ] )
		);
	}

	/**
	 * @param string $url 請求網址
	 * @return array<string, mixed>|\WP_Error
	 */
	private function fake_list( string $url ) {
		if ( null !== $this->list_response ) {
			return $this->list_response;
		}
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$page  = (int) ( $query['page'] ?? 1 );
		$limit = (int) ( $query['limit'] ?? 100 );

		return $this->http_response(
			200,
			(string) \wp_json_encode(
				[
					'data'  => array_slice( $this->websites, ( $page - 1 ) * $limit, $limit ),
					'total' => count( $this->websites ),
				]
			)
		);
	}

	/**
	 * @param int           $customer_id 客戶
	 * @param array<string> $site_ids    綁定的站台
	 * @param string        $status      訂閱狀態
	 * @return \WC_Subscription
	 */
	private function subscription( int $customer_id, array $site_ids, string $status = 'active' ): \WC_Subscription {
		$this->skip_if_no_subscriptions();
		$order = \wc_create_order( [ 'customer_id' => $customer_id ] );
		$this->assertInstanceOf( \WC_Order::class, $order );

		$subscription = \wcs_create_subscription(
			[
				'order_id'         => $order->get_id(),
				'status'           => $status,
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'customer_id'      => $customer_id,
			]
		);
		$this->assertInstanceOf( \WC_Subscription::class, $subscription );

		foreach ( $site_ids as $site_id ) {
			$subscription->add_meta_data( SiteSync::LINKED_SITE_IDS_META_KEY, $site_id, false );
		}
		$subscription->save();

		return $subscription;
	}

	/**
	 * 取出 wp_localize_script 寫進頁面的 env
	 *
	 * @return array<string, mixed>
	 */
	private function localized_env(): array {
		$data = (string) \wp_scripts()->get_data( Plugin::$kebab, 'data' );
		$this->assertSame( 1, preg_match( '/var ' . Plugin::$snake . '_data = (\{.*?\});/s', $data, $m ) );
		$decoded = json_decode( $m[1], true );
		$this->assertIsArray( $decoded );
		return (array) ( $decoded['env'] ?? [] );
	}

	private function get_sites(): \WP_REST_Response {
		return rest_do_request( new \WP_REST_Request( 'GET', self::SITES_ROUTE ) );
	}

	/**
	 * @param string               $site_id 站台 id
	 * @param string               $action  start / stop / domain
	 * @param array<string, mixed> $body    body
	 * @return \WP_REST_Response
	 */
	private function post_action( string $site_id, string $action, array $body = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', self::SITES_ROUTE . "/{$site_id}/{$action}" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) \wp_json_encode( $body ) );
		return rest_do_request( $request );
	}

	/**
	 * @param \WP_REST_Response $response 回應
	 * @return array<int, string>
	 */
	private function returned_site_ids( \WP_REST_Response $response ): array {
		$ids = array_map( static fn( $site ) => (string) $site['id'], (array) ( $response->get_data()['data'] ?? [] ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * @return array<int, array{url: string, args: array<string, mixed>}>
	 */
	private function patch_requests(): array {
		return array_values( array_filter( $this->http_requests, static fn( $request ) => 'PATCH' === ( $request['args']['method'] ?? '' ) ) );
	}

	// ========== 頁面資料 ==========

	public function test_前台登入客戶的頁面資料不含key(): void {
		\wp_set_current_user( $this->customer_id );

		Bootstrap::instance()->frontend_enqueue_script();
		$env = $this->localized_env();

		$this->assertSame( '', $env['powercloud_api_key'] );
		$this->assertTrue( $env['powercloud_connected'] );
		$this->assertStringNotContainsString( self::API_KEY, (string) \wp_json_encode( $env ) );
	}

	public function test_前台管理員的頁面資料也不含key(): void {
		\wp_set_current_user( $this->admin_id );

		Bootstrap::instance()->frontend_enqueue_script();

		$this->assertSame( '', $this->localized_env()['powercloud_api_key'] );
	}

	public function test_前台訪客看不出是否連結新架構(): void {
		Bootstrap::instance()->frontend_enqueue_script();
		$env = $this->localized_env();

		$this->assertSame( '', $env['powercloud_api_key'] );
		$this->assertFalse( $env['powercloud_connected'] );
	}

	public function test_本站沒有key時前台不顯示新架構(): void {
		\delete_transient( Main::POWERCLOUD_API_KEY_TRANSIENT_KEY );
		\wp_set_current_user( $this->customer_id );

		Bootstrap::instance()->frontend_enqueue_script();

		$this->assertFalse( $this->localized_env()['powercloud_connected'] );
	}

	public function test_wp_admin的管理員仍可取得key(): void {
		\wp_set_current_user( $this->admin_id );
		\set_current_screen( 'dashboard' );

		Bootstrap::instance()->enqueue_script();

		$this->assertSame( self::API_KEY, $this->localized_env()['powercloud_api_key'] );
	}

	public function test_wp_admin的非管理員不可取得key(): void {
		\wp_set_current_user( $this->customer_id );
		\set_current_screen( 'dashboard' );

		Bootstrap::instance()->enqueue_script();

		$this->assertSame( '', $this->localized_env()['powercloud_api_key'] );
	}

	// ========== GET /powercloud/sites ==========

	public function test_訪客取得站台回401且不呼叫API(): void {
		$response = $this->get_sites();

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( [], $this->http_requests );
	}

	public function test_客戶只取得自己的站台且不分大小寫比對email(): void {
		\wp_set_current_user( $this->customer_id );

		$response = $this->get_sites();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ self::SITE_OWN ], $this->returned_site_ids( $response ) );
		$this->assertSame( 1, $response->get_data()['total'] );
	}

	public function test_回應只含公開欄位且保留客戶自己站台的管理員密碼(): void {
		\wp_set_current_user( $this->customer_id );

		$response = $this->get_sites();
		$json     = (string) \wp_json_encode( $response->get_data() );

		$this->assertStringContainsString( 'admin-password-' . self::SITE_OWN, $json );
		$this->assertStringNotContainsString( 'admin-password-' . self::SITE_OTHER, $json );
		$this->assertStringNotContainsString( 'database-password-secret', $json );
		$this->assertStringNotContainsString( 'database-root-secret', $json );
		$this->assertStringNotContainsString( 'ssh-password-secret', $json );
		$this->assertStringNotContainsString( self::API_KEY, $json );
	}

	public function test_呼叫API時由PHP帶key(): void {
		\wp_set_current_user( $this->customer_id );

		$this->get_sites();

		$this->assertSame( self::API_KEY, $this->http_requests[0]['args']['headers']['X-API-Key'] ?? null );
	}

	public function test_綁在自己訂閱上的站台即使email不同也屬於自己(): void {
		$this->websites[] = $this->website( self::SITE_LINKED, 'old-email@test.com' );
		$this->subscription( $this->customer_id, [ self::SITE_LINKED ] );
		\wp_set_current_user( $this->customer_id );

		$this->assertSame( [ self::SITE_OWN, self::SITE_LINKED ], $this->returned_site_ids( $this->get_sites() ) );
	}

	/**
	 * 最常見的情況：客戶自己開的站，email 相同、也綁在自己的訂閱上
	 */
	public function test_綁在自己訂閱上且email相同的站台屬於自己(): void {
		$this->websites[] = $this->website( self::SITE_LINKED, 'customer@test.com' );
		$this->subscription( $this->customer_id, [ self::SITE_LINKED ] );
		\wp_set_current_user( $this->customer_id );

		$this->assertSame( [ self::SITE_OWN, self::SITE_LINKED ], $this->returned_site_ids( $this->get_sites() ) );
	}

	/**
	 * WCS 的客戶訂閱快取過期時，不可把客戶自己訂閱上的站誤判成別人的
	 */
	public function test_訂閱快取過期時email相同的自己站台仍屬於自己(): void {
		$this->websites[] = $this->website( self::SITE_LINKED, 'customer@test.com' );
		$this->subscription( $this->customer_id, [ self::SITE_LINKED ] );
		\update_user_meta( $this->customer_id, '_wcs_subscription_ids_cache', [] );
		\wp_set_current_user( $this->customer_id );

		$this->assertSame( [ self::SITE_OWN, self::SITE_LINKED ], $this->returned_site_ids( $this->get_sites() ) );
	}

	/**
	 * WooCommerce「我的帳號」改 email 不需要驗證，不可藉此取得別人訂閱上的站台
	 */
	public function test_綁在別人訂閱上的站台即使email相同也不屬於自己(): void {
		$this->websites[] = $this->website( self::SITE_HIJACK, 'customer@test.com' );
		$this->subscription( $this->other_customer_id, [ self::SITE_HIJACK ] );
		\wp_set_current_user( $this->customer_id );

		$response = $this->get_sites();

		$this->assertSame( [ self::SITE_OWN ], $this->returned_site_ids( $response ) );
		$this->assertStringNotContainsString( 'admin-password-' . self::SITE_HIJACK, (string) \wp_json_encode( $response->get_data() ) );
	}

	public function test_綁在別人已取消訂閱上的站台也不屬於自己(): void {
		$this->websites[] = $this->website( self::SITE_CANCELLED, 'customer@test.com' );
		$subscription     = $this->subscription( $this->other_customer_id, [ self::SITE_CANCELLED ] );
		$subscription->update_status( 'cancelled' );
		\wp_set_current_user( $this->customer_id );

		$this->assertSame( [ self::SITE_OWN ], $this->returned_site_ids( $this->get_sites() ) );
	}

	public function test_超過一頁的站台也找得到(): void {
		$this->websites = [];
		for ( $i = 0; $i < 300; $i++ ) {
			$this->websites[] = $this->website( sprintf( 'bbbbbbbb-0000-4000-8000-%012d', $i ), 'nobody@test.com' );
		}
		$this->websites[] = $this->website( self::SITE_OWN, 'customer@test.com' );
		\wp_set_current_user( $this->customer_id );

		$this->assertSame( [ self::SITE_OWN ], $this->returned_site_ids( $this->get_sites() ) );
	}

	public function test_本站沒有key回409且不呼叫API(): void {
		\delete_transient( Main::POWERCLOUD_API_KEY_TRANSIENT_KEY );
		\wp_set_current_user( $this->customer_id );

		$response = $this->get_sites();

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'pp_powercloud_not_connected', $response->get_data()['code'] );
		$this->assertSame( [], $this->http_requests );
	}

	public function test_清單抓取失敗回502而不是空清單(): void {
		$this->list_response = $this->http_response( 500, '{"message":"boom"}' );
		\wp_set_current_user( $this->customer_id );

		$response = $this->get_sites();

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'pp_upstream_unavailable', $response->get_data()['code'] );
	}

	// ========== start / stop / domain ==========

	public function test_訪客操作站台回401且不呼叫API(): void {
		foreach ( [ 'start', 'stop', 'domain' ] as $action ) {
			$this->assertSame( 401, $this->post_action( self::SITE_OWN, $action, [ 'domain' => 'new.example.com' ] )->get_status() );
		}
		$this->assertSame( [], $this->http_requests );
	}

	public function test_客戶可以啟動與停止自己的站台(): void {
		\wp_set_current_user( $this->customer_id );

		$this->assertSame( 200, $this->post_action( self::SITE_OWN, 'start' )->get_status() );
		$this->assertSame( 200, $this->post_action( self::SITE_OWN, 'stop' )->get_status() );

		$patches = $this->patch_requests();
		$this->assertCount( 2, $patches );
		$this->assertStringEndsWith( '/wordpress/' . self::SITE_OWN . '/start', $patches[0]['url'] );
		$this->assertStringEndsWith( '/wordpress/' . self::SITE_OWN . '/stop', $patches[1]['url'] );
		$this->assertSame( self::API_KEY, $patches[0]['args']['headers']['X-API-Key'] );
	}

	public function test_客戶可以變更自己站台的網域(): void {
		\wp_set_current_user( $this->customer_id );

		$response = $this->post_action( self::SITE_OWN, 'domain', [ 'domain' => 'new.example.com' ] );

		$this->assertSame( 200, $response->get_status() );
		$patches = $this->patch_requests();
		$this->assertCount( 1, $patches );
		$this->assertStringEndsWith( '/wordpress/' . self::SITE_OWN . '/domain', $patches[0]['url'] );
		$this->assertSame( [ 'domain' => 'new.example.com' ], json_decode( (string) $patches[0]['args']['body'], true ) );
	}

	public function test_客戶不可操作別人的站台(): void {
		\wp_set_current_user( $this->customer_id );

		foreach ( [ 'start', 'stop', 'domain' ] as $action ) {
			$response = $this->post_action( self::SITE_OTHER, $action, [ 'domain' => 'new.example.com' ] );
			$this->assertSame( 403, $response->get_status(), $action );
			$this->assertSame( 'not_owner', $response->get_data()['code'], $action );
		}
		$this->assertSame( [], $this->patch_requests() );
	}

	public function test_前台管理員一樣只能操作自己的站台(): void {
		\wp_set_current_user( $this->admin_id );

		$this->assertSame( 403, $this->post_action( self::SITE_OTHER, 'stop' )->get_status() );
		$this->assertSame( [], $this->patch_requests() );
	}

	public function test_清單抓取失敗時不放行寫入操作(): void {
		$this->list_response = new \WP_Error( 'http_request_failed', 'timeout' );
		\wp_set_current_user( $this->customer_id );

		$this->assertSame( 502, $this->post_action( self::SITE_OWN, 'stop' )->get_status() );
		$this->assertSame( [], $this->patch_requests() );
	}

	/**
	 * @dataProvider invalid_domains
	 *
	 * @param mixed $domain 不合法的網域
	 */
	public function test_不合法的網域回400且不呼叫API( $domain ): void {
		\wp_set_current_user( $this->customer_id );

		$response = $this->post_action( self::SITE_OWN, 'domain', [ 'domain' => $domain ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_domain', $response->get_data()['code'] );
		$this->assertSame( [], $this->http_requests );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function invalid_domains(): array {
		return [
			'含 scheme'  => [ 'https://example.com' ],
			'含空白'      => [ 'not a domain' ],
			'結尾換行'     => [ "example.com\n" ],
			'指令注入'     => [ 'a.com;id' ],
			'路徑'       => [ 'example.com/../x' ],
			'沒有點'      => [ 'localhost' ],
			'非字串'      => [ [ 'example.com' ] ],
			'超過 253 字' => [ str_repeat( 'a.', 130 ) . 'com' ],
		];
	}

	public function test_上游成功時不轉送回應內容(): void {
		\wp_set_current_user( $this->customer_id );

		$response = $this->post_action( self::SITE_OWN, 'start' );

		$this->assertSame( [ 'success' => true ], $response->get_data() );
	}

	public function test_上游拒絕時只回message不轉送其他內容(): void {
		$this->patch_response = $this->http_response(
			400,
			(string) \wp_json_encode(
				[
					'message'       => [ 'domain 已被使用' ],
					'adminPassword' => 'password-in-upstream-response',
				]
			)
		);
		\wp_set_current_user( $this->customer_id );

		$response = $this->post_action( self::SITE_OWN, 'domain', [ 'domain' => 'new.example.com' ] );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'pp_upstream_error', $response->get_data()['code'] );
		$this->assertSame( 'domain 已被使用', $response->get_data()['message'] );
		$this->assertStringNotContainsString( 'password-in-upstream-response', (string) \wp_json_encode( $response->get_data() ) );
	}

	/**
	 * 網域變更可能要數分鐘：沒收到回應時前端不能說「失敗」，這個錯誤代碼是它的判斷依據
	 */
	public function test_沒收到上游回應時回可區分的錯誤代碼(): void {
		$this->patch_response = new \WP_Error( 'http_request_failed', 'cURL error 28: timeout' );
		\wp_set_current_user( $this->customer_id );

		$response = $this->post_action( self::SITE_OWN, 'domain', [ 'domain' => 'new.example.com' ] );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'pp_upstream_unavailable', $response->get_data()['code'] );
	}

	public function test_log不含上游回應裡的憑證(): void {
		$logs   = [];
		$filter = static function ( $message, $level, $context ) use ( &$logs ) {
			$logs[] = (string) $message . \wp_json_encode( $context );
			return $message;
		};
		\add_filter( 'woocommerce_logger_log_message', $filter, 10, 3 );
		$this->patch_response = $this->http_response(
			500,
			(string) \wp_json_encode(
				[
					'message'       => 'boom',
					'adminPassword' => 'password-in-upstream-response',
				]
			)
		);
		\wp_set_current_user( $this->customer_id );

		$this->post_action( self::SITE_OWN, 'stop' );
		\remove_filter( 'woocommerce_logger_log_message', $filter, 10 );

		$this->assertStringNotContainsString( 'password-in-upstream-response', implode( "\n", $logs ) );
		$this->assertStringNotContainsString( self::API_KEY, implode( "\n", $logs ) );
	}
}
