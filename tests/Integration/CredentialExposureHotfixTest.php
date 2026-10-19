<?php
/**
 * 共用帳密外洩止血（C0 hotfix）整合測試
 *
 * 背景：寫死的 powerpartner 應用程式密碼經 wp_localize_script 輸出到每個前台頁面而外洩，
 * 且 GET /account-info 公開回傳經銷商真實帳密的密文（passphrase 公開，可直接解開）。
 * 本檔鎖住止血行為：
 * - GET /account-info 只允許 manage_options
 * - GET /customers 需要登入，非管理員只能查到自己（且不可退化成「全部用戶」）
 * - 未登入訪客的頁面資料不含共用憑證 t；前台不再觸發模板列表 API
 * - CloudServer 請求帶 X-PP-Client-Version / X-PP-Site（撤銷共用帳號前的 telemetry 依據）
 * - 模板列表抓取失敗會寫負面快取，避免每次載入都重打 CloudServer
 * - 授權碼商品查詢送的是 CloudServer 上的 partner_id，不是本站使用者 id
 */

declare( strict_types=1 );

namespace Tests\Integration;

use J7\PowerPartner\Api\Connect;
use J7\PowerPartner\Api\Fetch;
use J7\PowerPartner\Bootstrap;
use J7\PowerPartner\Plugin;
use J7\PowerPartner\Product\DataTabs\LinkedLC;

/**
 * @group security
 */
class CredentialExposureHotfixTest extends TestCase {

	/** @var int 管理員 */
	private int $admin_id;

	/** @var int 一般會員 A */
	private int $member_a_id;

	/** @var int 一般會員 B */
	private int $member_b_id;

	/** @var array<int, array{url: string, args: array<string, mixed>}> 攔截到的 HTTP 請求 */
	private array $http_requests = [];

	/** @var callable|null pre_http_request filter */
	private $http_filter = null;

	protected function configure_dependencies(): void {
		$this->admin_id    = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$this->member_a_id = $this->factory()->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'member-a@test.com',
			]
		);
		$this->member_b_id = $this->factory()->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'member-b@test.com',
			]
		);

		\update_option( Connect::PARTNER_ID_OPTION_NAME, '777' );
		\update_option( Plugin::$snake . '_account_info', 'cipher-text-of-dealer-credentials' );
		\delete_transient( Fetch::ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY );
		\delete_transient( LinkedLC::CLOUD_PRODUCTS_TRANSIENT_KEY );

		rest_get_server();
	}

	public function tear_down(): void {
		if ( null !== $this->http_filter ) {
			\remove_filter( 'pre_http_request', $this->http_filter, 10 );
		}
		\wp_set_current_user( 0 );
		$GLOBALS['current_screen'] = null; // phpcs:ignore
		\delete_option( Connect::PARTNER_ID_OPTION_NAME );
		\delete_option( Plugin::$snake . '_account_info' );
		\delete_transient( Fetch::ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY );
		\delete_transient( LinkedLC::CLOUD_PRODUCTS_TRANSIENT_KEY );
		parent::tear_down();
	}

	/**
	 * 攔截所有對外 HTTP，回傳指定的假回應
	 *
	 * @param array<string, mixed>|\WP_Error $response 假回應
	 */
	private function mock_http( $response ): void {
		$this->http_filter = function ( $pre, $args, $url ) use ( $response ) {
			$this->http_requests[] = [
				'url'  => (string) $url,
				'args' => (array) $args,
			];
			return $response;
		};
		\add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
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
	 * 取出 wp_localize_script 寫進頁面的 power_partner_data.env
	 *
	 * @return array<string, mixed>
	 */
	private function localized_env(): array {
		$data = (string) \wp_scripts()->get_data( Plugin::$kebab, 'data' );
		$this->assertNotSame( '', $data, 'enqueue_script 應已 localize 頁面資料' );
		$this->assertSame( 1, preg_match( '/var ' . Plugin::$snake . '_data = (\{.*?\});/s', $data, $m ), '找不到 ' . Plugin::$snake . '_data' );
		$decoded = json_decode( $m[1], true );
		$this->assertIsArray( $decoded );
		return (array) ( $decoded['env'] ?? [] );
	}

	private function reset_scripts(): void {
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore
	}

	// ========== GET /account-info ==========

	/** @group security */
	public function test_account_info_未登入回401且不含密文(): void {
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/power-partner/account-info' ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertStringNotContainsString( 'cipher-text-of-dealer-credentials', (string) \wp_json_encode( $response->get_data() ) );
	}

	/** @group security */
	public function test_account_info_一般會員回403(): void {
		\wp_set_current_user( $this->member_a_id );

		$response = rest_do_request( new \WP_REST_Request( 'GET', '/power-partner/account-info' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	/** @group happy */
	public function test_account_info_管理員可取得密文(): void {
		\wp_set_current_user( $this->admin_id );

		$response = rest_do_request( new \WP_REST_Request( 'GET', '/power-partner/account-info' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'cipher-text-of-dealer-credentials', $response->get_data()['data']['encrypted_account_info'] ?? null );
	}

	// ========== GET /customers ==========

	/**
	 * @param array<int, int|string> $user_ids 查詢的 id
	 * @return \WP_REST_Response
	 */
	private function get_customers( array $user_ids ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', '/power-partner/customers' );
		$request->set_query_params( [ 'user_ids' => array_map( 'strval', $user_ids ) ] );
		return rest_do_request( $request );
	}

	/**
	 * @param \WP_REST_Response $response 回應
	 * @return array<int, string>
	 */
	private function returned_ids( \WP_REST_Response $response ): array {
		return array_map( static fn( $row ) => (string) $row['id'], (array) $response->get_data() );
	}

	/** @group security */
	public function test_customers_未登入回401(): void {
		$response = $this->get_customers( [ $this->member_a_id ] );

		$this->assertSame( 401, $response->get_status() );
		$this->assertStringNotContainsString( 'member-a@test.com', (string) \wp_json_encode( $response->get_data() ) );
	}

	/** @group happy */
	public function test_customers_一般會員查自己可取得自己(): void {
		\wp_set_current_user( $this->member_a_id );

		$response = $this->get_customers( [ $this->member_a_id ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ (string) $this->member_a_id ], $this->returned_ids( $response ) );
	}

	/** @group security */
	public function test_customers_一般會員混查他人時只回自己(): void {
		\wp_set_current_user( $this->member_a_id );

		$response = $this->get_customers( [ $this->member_a_id, $this->member_b_id, $this->admin_id ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ (string) $this->member_a_id ], $this->returned_ids( $response ) );
	}

	/**
	 * get_users 的 include 為空陣列時會回傳全部用戶 —— 收斂後變空不可退化成全撈
	 *
	 * @group security
	 */
	public function test_customers_一般會員只查他人時回空陣列而非全部用戶(): void {
		\wp_set_current_user( $this->member_a_id );

		$response = $this->get_customers( [ $this->member_b_id ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $response->get_data() );
		$this->assertSame( '0', $response->get_headers()['X-WP-Total'] ?? null );
	}

	/** @group happy */
	public function test_customers_管理員可查任意用戶(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->get_customers( [ $this->member_a_id, $this->member_b_id ] );

		$this->assertSame( 200, $response->get_status() );
		$ids = $this->returned_ids( $response );
		sort( $ids );
		$expected = [ (string) $this->member_a_id, (string) $this->member_b_id ];
		sort( $expected );
		$this->assertSame( $expected, $ids );
	}

	// ========== 頁面資料（wp_localize_script） ==========

	/** @group security */
	public function test_前台未登入訪客的頁面資料不含共用憑證(): void {
		$this->reset_scripts();
		$this->assertNotSame( '', Bootstrap::instance()->t, '前提：Bootstrap 已設定共用憑證' );

		Bootstrap::instance()->frontend_enqueue_script();

		$env = $this->localized_env();
		$this->assertSame( '', $env['t'] ?? null );
		$this->assertStringNotContainsString( Bootstrap::instance()->t, (string) \wp_scripts()->get_data( Plugin::$kebab, 'data' ) );
	}

	/**
	 * 過渡期內 UserApp 仍以 t 呼叫 cloudAxios，登入會員必須拿得到，否則前台網站列表會壞
	 *
	 * @group happy
	 */
	public function test_前台登入會員的頁面資料仍含共用憑證(): void {
		$this->reset_scripts();
		\wp_set_current_user( $this->member_a_id );

		Bootstrap::instance()->frontend_enqueue_script();

		$this->assertSame( Bootstrap::instance()->t, $this->localized_env()['t'] ?? null );
	}

	/** @group security */
	public function test_前台載入不會觸發模板列表API(): void {
		$this->reset_scripts();
		$this->mock_http( $this->http_response( 200, '{"data":{"list":[]}}' ) );

		Bootstrap::instance()->frontend_enqueue_script();

		$this->assertSame( [], $this->http_requests, '前台每個 page view 都會跑 enqueue_script，不得打 CloudServer' );
		$this->assertSame( [], $this->localized_env()['allowed_template_options'] ?? null );
	}

	/** @group happy */
	public function test_後台載入仍會取得模板列表(): void {
		$this->reset_scripts();
		\set_current_screen( 'dashboard' );
		$this->mock_http( $this->http_response( 200, '{"data":{"list":[{"ID":12,"post_title":"模板 A"}]}}' ) );

		Bootstrap::instance()->enqueue_script();

		$this->assertSame( [ '12' => '模板 A' ], $this->localized_env()['allowed_template_options'] ?? null );
	}

	// ========== CloudServer request headers ==========

	/** @group happy */
	public function test_cloud_headers帶認證與telemetry欄位(): void {
		$headers = Fetch::cloud_headers();

		$this->assertSame( 'application/json', $headers['Content-Type'] ?? null );
		$this->assertStringStartsWith( 'Basic ', (string) ( $headers['Authorization'] ?? '' ) );
		$this->assertSame( (string) Plugin::$version, $headers['X-PP-Client-Version'] ?? null );
		$this->assertSame( \site_url(), $headers['X-PP-Site'] ?? null );
	}

	/** @group happy */
	public function test_開站停用啟用與模板請求都帶telemetry欄位(): void {
		$this->mock_http( $this->http_response( 200, '{"status":200,"data":{"list":[]}}' ) );

		Fetch::disable_site( '101' );
		Fetch::enable_site( '101' );
		Fetch::fetch_template_sites_by_user();
		Fetch::site_sync( [ 'site_id' => '1' ] );

		$this->assertCount( 4, $this->http_requests );
		foreach ( $this->http_requests as $request ) {
			$headers = (array) ( $request['args']['headers'] ?? [] );
			$this->assertSame( (string) Plugin::$version, $headers['X-PP-Client-Version'] ?? null, $request['url'] );
			$this->assertSame( \site_url(), $headers['X-PP-Site'] ?? null, $request['url'] );
		}
	}

	// ========== 模板列表負面快取 ==========

	/** @group error */
	public function test_模板列表連線失敗時寫負面快取且不重打(): void {
		$this->mock_http( new \WP_Error( 'http_request_failed', 'timeout' ) );

		$this->assertSame( [], Fetch::get_allowed_template_options() );
		$this->assertSame( [], Fetch::get_allowed_template_options() );

		$this->assertCount( 1, $this->http_requests, '第二次應命中負面快取' );
		$this->assertSame( [], \get_transient( Fetch::ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY ) );
	}

	/** @group error */
	public function test_模板列表回應非成功格式時寫負面快取(): void {
		$this->mock_http( $this->http_response( 401, '{"code":"incorrect_password","message":"x","data":{"status":401}}' ) );

		Fetch::get_allowed_template_options();
		Fetch::get_allowed_template_options();

		$this->assertCount( 1, $this->http_requests );
	}

	/** @group error */
	public function test_模板列表在未連線時不發請求也不寫快取(): void {
		\delete_option( Connect::PARTNER_ID_OPTION_NAME );
		$this->mock_http( $this->http_response( 200, '{"data":{"list":[]}}' ) );

		$this->assertSame( [], Fetch::get_allowed_template_options() );

		$this->assertSame( [], $this->http_requests );
		$this->assertFalse( \get_transient( Fetch::ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY ), '連線後應能立刻抓到，不可被空快取擋住' );
	}

	/** @group happy */
	public function test_模板列表成功時寫入結果(): void {
		$this->mock_http( $this->http_response( 200, '{"data":{"list":[{"ID":5,"post_title":"T5"}]}}' ) );

		$this->assertSame( [ '5' => 'T5' ], Fetch::get_allowed_template_options() );
		$this->assertSame( [ '5' => 'T5' ], \get_transient( Fetch::ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY ) );
	}

	// ========== 授權碼商品查詢 ==========

	/** @group happy */
	public function test_授權碼商品查詢送CloudServer的partner_id而非本站使用者id(): void {
		\wp_set_current_user( $this->admin_id );
		$this->mock_http( $this->http_response( 200, '[]' ) );

		LinkedLC::get_cloud_products();

		$this->assertCount( 1, $this->http_requests );
		$query = [];
		parse_str( (string) wp_parse_url( $this->http_requests[0]['url'], PHP_URL_QUERY ), $query );
		$this->assertSame( '777', $query['user_id'] ?? null );
		$this->assertSame( (string) Plugin::$version, $this->http_requests[0]['args']['headers']['X-PP-Client-Version'] ?? null );
	}
}
