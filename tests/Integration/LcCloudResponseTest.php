<?php
/**
 * 授權碼生命週期對 CloudServer 回應的判定
 *
 * 1. 成敗看 HTTP status，不是只看 is_wp_error()：CloudServer 的業務錯誤（403 not_owner、
 *    401 legacy_auth_disabled、500…）回來時不是 WP_Error，原本會被記成「✅成功」。
 * 2. 存量授權碼的 post_author 可能不是本經銷商：dealer 模式的 recover / expire 收到
 *    403 not_owner 時，以共用帳號重送一次（窄例外，見 CloudClient::LEGACY_ON_NOT_OWNER_ENDPOINTS）。
 */

declare( strict_types=1 );

namespace Tests\Integration;

use J7\PowerPartner\Api\Connect;
use J7\PowerPartner\Bootstrap;
use J7\PowerPartner\Domains\Cloud\Services\CloudClient;
use J7\PowerPartner\Domains\Cloud\Services\CredentialStore;
use J7\PowerPartner\Domains\LC\Core\Api;
use J7\PowerPartner\Domains\LC\Core\LifeCycle;
use J7\PowerPartner\Domains\LC\Services\ExpireHandler;
use J7\PowerPartner\Product\DataTabs\LinkedLC;

/**
 * @group lc
 * @group security
 */
class LcCloudResponseTest extends TestCase {

	private const DEALER_LOGIN    = 'dealer_login';
	private const DEALER_PASSWORD = 'AbCd EfGh IjKl MnOp QrSt UvWx';

	/** @var array<int, array{method: string, path: string, headers: array<string, string>, body: array<string, mixed>}> */
	private array $requests = [];

	/**
	 * 依「METHOD 路徑」回應的假 CloudServer；值為 [status, body] 或依請求決定的 callable
	 *
	 * @var array<string, array{0: int, 1: array<string, mixed>}|callable>
	 */
	private array $routes = [];

	/** @var array<int, array{message: string, level: string, context: string}> */
	private array $logs = [];

	/** @var callable|null */
	private $http_filter = null;

	/** @var callable|null */
	private $log_filter = null;

	/** @var callable|null */
	private $mail_filter = null;

	protected function configure_dependencies(): void {
		$this->clean();
		\update_option( Connect::PARTNER_ID_OPTION_NAME, '777' );
		ExpireHandler::register();

		$this->http_filter = function ( $pre, $args, $url ) {
			$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
			$path   = (string) preg_replace( '#^.*/wp-json/power-partner-server/#', '', (string) \wp_parse_url( (string) $url, PHP_URL_PATH ) );
			$body   = json_decode( (string) ( $args['body'] ?? '' ), true );

			$this->requests[] = [
				'method'  => $method,
				'path'    => $path,
				'headers' => (array) ( $args['headers'] ?? [] ),
				'body'    => is_array( $body ) ? $body : [],
			];

			$route = $this->routes[ "{$method} {$path}" ] ?? [ 404, [ 'code' => 'rest_no_route', 'message' => 'No route' ] ];
			if ( is_callable( $route ) ) {
				$route = $route( end( $this->requests ) );
			}
			return [
				'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( [ 'content-type' => 'application/json' ] ),
				'body'     => (string) \wp_json_encode( $route[1] ),
				'response' => [
					'code'    => $route[0],
					'message' => '',
				],
				'cookies'  => [],
			];
		};
		\add_filter( 'pre_http_request', $this->http_filter, 10, 3 );

		$this->log_filter = function ( $message, $level = 'info', $context = [] ) {
			$this->logs[] = [
				'message' => (string) $message,
				'level'   => (string) $level,
				'context' => (string) \wp_json_encode( $context ),
			];
			return $message;
		};
		\add_filter( 'woocommerce_logger_log_message', $this->log_filter, 10, 3 );

		// 不實際寄出開通信
		$this->mail_filter = static fn() => false;
		\add_filter( 'pre_wp_mail', $this->mail_filter );
	}

	public function tear_down(): void {
		\remove_filter( 'pre_http_request', $this->http_filter, 10 );
		\remove_filter( 'woocommerce_logger_log_message', $this->log_filter, 10 );
		\remove_filter( 'pre_wp_mail', $this->mail_filter );
		\wp_set_current_user( 0 );
		$this->clean();
		parent::tear_down();
	}

	private function clean(): void {
		foreach ( [
			CredentialStore::DEALER_OPTION,
			CredentialStore::DEALER_INVALID_OPTION,
			Connect::PARTNER_ID_OPTION_NAME,
		] as $option ) {
			\delete_option( $option );
		}
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

	private function dealer_basic(): string {
		return 'Basic ' . base64_encode( self::DEALER_LOGIN . ':' . self::DEALER_PASSWORD ); // phpcs:ignore
	}

	private function legacy_basic(): string {
		return 'Basic ' . base64_encode( Bootstrap::instance()->username . ':' . Bootstrap::instance()->psw ); // phpcs:ignore
	}

	/**
	 * CloudServer 的錯誤回應（error_code 放在 data，與 CloudServer 契約相同）
	 *
	 * @param int    $status     HTTP status
	 * @param string $error_code 錯誤代碼
	 * @return array{0: int, 1: array<string, mixed>}
	 */
	private function error( int $status, string $error_code ): array {
		return [
			$status,
			[
				'code'    => 'rest_' . $error_code,
				'message' => "{$error_code} message",
				'data'    => [
					'status'     => $status,
					'error_code' => $error_code,
				],
			],
		];
	}

	/**
	 * dealer 打過來回 $dealer_response，legacy 打過來回 $legacy_response
	 *
	 * @param array{0: int, 1: array<string, mixed>} $dealer_response dealer 模式的回應
	 * @param array{0: int, 1: array<string, mixed>} $legacy_response legacy 模式的回應
	 * @return callable
	 */
	private function by_mode( array $dealer_response, array $legacy_response ): callable {
		return function ( $request ) use ( $dealer_response, $legacy_response ) {
			return ( $request['headers']['Authorization'] ?? '' ) === $this->dealer_basic() ? $dealer_response : $legacy_response;
		};
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
	 * @param array<int, array{product_slug: string, quantity: int}> $lc_products linked_lc_products
	 * @return \WC_Subscription
	 */
	private function create_subscription( array $lc_products = [] ): \WC_Subscription {
		$customer_id = $this->factory()->user->create( [ 'role' => 'customer' ] );
		$product_id  = $this->create_subscription_product();
		if ( $lc_products ) {
			\update_post_meta( $product_id, LinkedLC::FIELD_NAME, $lc_products );
		}

		$order = \wc_create_order( [ 'customer_id' => $customer_id ] );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$product = \wc_get_product( $product_id );
		$this->assertNotFalse( $product );
		$order->add_product( $product );
		$order->set_billing_email( 'lc-customer@example.com' );
		$order->save();

		$subscription = \wcs_create_subscription(
			[
				'order_id'         => $order->get_id(),
				'status'           => 'active',
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'customer_id'      => $customer_id,
			]
		);
		$this->assertInstanceOf( \WC_Subscription::class, $subscription );
		$subscription->set_billing_email( 'lc-customer@example.com' );
		$subscription->save();
		return $subscription;
	}

	/**
	 * @param int $subscription_id 訂閱 id
	 * @return array<int, string>
	 */
	private function notes( int $subscription_id ): array {
		$notes = \wc_get_order_notes(
			[
				'order_id' => $subscription_id,
				'limit'    => 50,
			]
		);
		return array_map( static fn( $note ) => (string) $note->content, $notes );
	}

	/**
	 * @param array<int, string> $notes 備註
	 * @param string             $regex 正規式
	 * @return array<int, string>
	 */
	private function grep( array $notes, string $regex ): array {
		return array_values( (array) preg_grep( $regex, $notes ) );
	}

	/** @return array<int, array{message: string, level: string, context: string}> */
	private function error_logs(): array {
		return array_values( array_filter( $this->logs, static fn( $log ) => 'error' === $log['level'] ) );
	}

	// ========== 作者不符時以共用帳號重送（窄例外） ==========

	/**
	 * 存量授權碼的作者不是本經銷商：遷移到 dealer 模式後重啟不可失敗（客戶補繳後授權要回來）
	 *
	 * @group happy
	 */
	public function test_重啟遇到作者不符時以共用帳號重送且不標記專屬密碼失效(): void {
		$this->skip_if_no_subscriptions();
		$this->given_dealer();
		$subscription = $this->create_subscription();
		\add_post_meta( $subscription->get_id(), 'lc_id', '101' );
		$this->routes['POST license-codes/recover'] = $this->by_mode( $this->error( 403, 'not_owner' ), [ 200, [ 'status' => 200, 'data' => [ 'recovered' => 1 ] ] ] );

		( new LifeCycle() )->subscription_success( $subscription, [] );

		$sent = $this->sent( 'POST', 'license-codes/recover' );
		$this->assertCount( 2, $sent, 'dealer 被 not_owner 擋下後應以共用帳號重送一次' );
		$this->assertSame( $this->dealer_basic(), $sent[0]['headers']['Authorization'] ?? null );
		$this->assertSame( $this->legacy_basic(), $sent[1]['headers']['Authorization'] ?? null );
		$this->assertSame( [ '101' ], $sent[1]['body']['ids'] ?? null, '重送的 ids 與原請求相同' );

		$notes = $this->notes( $subscription->get_id() );
		$this->assertNotEmpty( $this->grep( $notes, '/《重啟》授權碼 ✅成功/' ), '重送成功應記成功：' . print_r( $notes, true ) ); // phpcs:ignore
		$this->assertEmpty( $this->grep( $notes, '/❌/' ) );

		$this->assertFalse( CredentialStore::is_dealer_invalid(), 'not_owner 不是憑證問題，不可標記專屬密碼失效' );
		$this->assertSame( CloudClient::MODE_DEALER, CloudClient::preferred_mode() );

		$warnings = array_filter( $this->logs, static fn( $log ) => 'warning' === $log['level'] && str_contains( $log['message'], 'not_owner' ) );
		$this->assertNotEmpty( $warnings, '要留 log 供關閉 legacy 前修正 post_author' );
		$this->assertStringContainsString( '101', (string) reset( $warnings )['context'] );
		$this->assertStringContainsString( '777', (string) reset( $warnings )['context'] );
	}

	/** @group happy */
	public function test_停用遇到作者不符時以共用帳號重送(): void {
		$this->skip_if_no_subscriptions();
		$this->given_dealer();
		$subscription = $this->create_subscription();
		$this->routes['POST license-codes/expire'] = $this->by_mode( $this->error( 403, 'not_owner' ), [ 200, [ 'status' => 200 ] ] );

		ExpireHandler::action_callback(
			[
				'lc_ids'          => [ '101' ],
				'subscription_id' => $subscription->get_id(),
			]
		);

		$this->assertCount( 2, $this->sent( 'POST', 'license-codes/expire' ) );
		$notes = $this->notes( $subscription->get_id() );
		$this->assertNotEmpty( $this->grep( $notes, '/《過期》授權碼 成功/' ), print_r( $notes, true ) ); // phpcs:ignore
		$this->assertEmpty( $this->grep( $notes, '/❌/' ) );
		$this->assertFalse( CredentialStore::is_dealer_invalid() );
	}

	/**
	 * 沒有明確開啟的呼叫不可重送：建立授權碼的身分來自本站設定，被擋代表真的越權
	 *
	 * @group security
	 */
	public function test_沒有明確開啟的呼叫遇到作者不符不重送(): void {
		$this->given_dealer();
		$this->routes['POST license-codes/recover'] = $this->by_mode( $this->error( 403, 'not_owner' ), [ 200, [ 'status' => 200 ] ] );

		$response = CloudClient::instance()->remote_post( 'license-codes/recover', [ 'ids' => [ '101' ] ] );

		$this->assertSame( 403, \wp_remote_retrieve_response_code( $response ) );
		$this->assertCount( 1, $this->sent( 'POST', 'license-codes/recover' ) );
	}

	/** @group security */
	public function test_開啟旗標但端點不在白名單時不重送(): void {
		$this->given_dealer();
		$this->routes['POST license-codes/update'] = $this->by_mode( $this->error( 403, 'not_owner' ), [ 200, [ 'status' => 200 ] ] );

		$response = CloudClient::instance()->remote_post( 'license-codes/update', [ 'ids' => [ '101' ] ], [ 'legacy_on_not_owner' => true ] );

		$this->assertSame( 403, \wp_remote_retrieve_response_code( $response ) );
		$this->assertCount( 1, $this->sent( 'POST', 'license-codes/update' ) );
	}

	/** @group security */
	public function test_身分不符不重送(): void {
		$this->skip_if_no_subscriptions();
		$this->given_dealer();
		$subscription = $this->create_subscription();
		\add_post_meta( $subscription->get_id(), 'lc_id', '101' );
		$this->routes['POST license-codes/recover'] = $this->by_mode( $this->error( 403, 'partner_mismatch' ), [ 200, [ 'status' => 200 ] ] );

		( new LifeCycle() )->subscription_success( $subscription, [] );

		$this->assertCount( 1, $this->sent( 'POST', 'license-codes/recover' ), 'partner_mismatch 不在窄例外內' );
		$this->assertNotEmpty( $this->grep( $this->notes( $subscription->get_id() ), '/《重啟》授權碼 ❌失敗.*partner_mismatch/s' ) );
	}

	// ========== 成敗看 HTTP status ==========

	/**
	 * legacy 關閉之後重送也會失敗：必須記失敗，而不是「✅成功」讓沒有人發現
	 *
	 * @group error
	 */
	public function test_重啟作者不符且共用帳號也被拒時寫失敗備註與error_log(): void {
		$this->skip_if_no_subscriptions();
		$this->given_dealer();
		$subscription = $this->create_subscription();
		\add_post_meta( $subscription->get_id(), 'lc_id', '101' );
		$this->routes['POST license-codes/recover'] = $this->by_mode( $this->error( 403, 'not_owner' ), $this->error( 401, 'legacy_auth_disabled' ) );

		( new LifeCycle() )->subscription_success( $subscription, [] );

		$notes = $this->notes( $subscription->get_id() );
		$this->assertNotEmpty( $this->grep( $notes, '/《重啟》授權碼 ❌失敗.*HTTP 401 legacy_auth_disabled/s' ), print_r( $notes, true ) ); // phpcs:ignore
		$this->assertEmpty( $this->grep( $notes, '/✅/' ) );
		$this->assertNotEmpty( $this->error_logs() );
	}

	/** @group error */
	public function test_重啟收到500時寫失敗備註(): void {
		$this->skip_if_no_subscriptions();
		$subscription = $this->create_subscription();
		\add_post_meta( $subscription->get_id(), 'lc_id', '101' );
		$this->routes['POST license-codes/recover'] = [ 500, [ 'message' => 'boom' ] ];

		( new LifeCycle() )->subscription_success( $subscription, [] );

		$notes = $this->notes( $subscription->get_id() );
		$this->assertNotEmpty( $this->grep( $notes, '/《重啟》授權碼 ❌失敗.*HTTP 500.*boom/s' ), print_r( $notes, true ) ); // phpcs:ignore
		$this->assertEmpty( $this->grep( $notes, '/✅/' ) );
		$this->assertNotEmpty( $this->error_logs() );
	}

	/** @group error */
	public function test_停用收到非2xx時寫失敗備註(): void {
		$this->skip_if_no_subscriptions();
		$subscription = $this->create_subscription();
		$this->routes['POST license-codes/expire'] = [ 500, [ 'message' => 'boom' ] ];

		ExpireHandler::action_callback(
			[
				'lc_ids'          => [ '101' ],
				'subscription_id' => $subscription->get_id(),
			]
		);

		$notes = $this->notes( $subscription->get_id() );
		$this->assertNotEmpty( $this->grep( $notes, '/《過期》授權碼 ❌失敗.*HTTP 500/s' ), print_r( $notes, true ) ); // phpcs:ignore
		$this->assertEmpty( $this->grep( $notes, '/《過期》授權碼 成功/' ) );
		$this->assertNotEmpty( $this->error_logs() );
	}

	/**
	 * 回應不是 2xx 就沒有真的授權碼：即使 body 長得像成功，也不可把 id 綁到訂閱、不可寄開通信
	 *
	 * @group error
	 */
	public function test_建立授權碼收到非2xx時不寫lc_id(): void {
		$this->skip_if_no_subscriptions();
		$subscription                     = $this->create_subscription( [ [ 'product_slug' => 'power-course', 'quantity' => 1 ] ] );
		$this->routes['POST license-codes'] = [
			403,
			[
				'code'    => 'rest_forbidden',
				'message' => 'nope',
				'data'    => [
					'status'        => 403,
					'license_codes' => [ [ 'id' => 999 ] ],
				],
			],
		];

		( new LifeCycle() )->create_lcs( $subscription, [] );

		$this->assertEmpty( \get_post_meta( $subscription->get_id(), 'lc_id', false ), '失敗時不可寫入不存在的 lc_id' );
		$notes = $this->notes( $subscription->get_id() );
		$this->assertNotEmpty( $this->grep( $notes, '/《新增》授權碼 ❌失敗.*HTTP 403/s' ), print_r( $notes, true ) ); // phpcs:ignore
		$this->assertEmpty( $this->grep( $notes, '/✅/' ) );
	}

	/**
	 * AdminApp 的「更新授權碼」：上游拒絕時要讓畫面顯示失敗，不是「更新授權碼成功」
	 *
	 * @group error
	 */
	public function test_後台更新授權碼時原樣回傳上游的錯誤狀態(): void {
		\wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );
		Api::instance();
		rest_get_server();
		$this->routes['POST license-codes/update'] = $this->error( 403, 'not_owner' );

		$request = new \WP_REST_Request( 'POST', '/power-partner/license-codes/update' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) \wp_json_encode( [ 'ids' => [ 101 ], 'post_status' => 'available' ] ) );
		$response = \rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'not_owner message', $response->get_data()['message'] ?? null );
	}

	/** @group happy */
	public function test_後台更新授權碼成功時照舊回200(): void {
		\wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );
		Api::instance();
		rest_get_server();
		$this->routes['POST license-codes/update'] = [ 201, [ 'message' => 'ok' ] ];

		$request = new \WP_REST_Request( 'POST', '/power-partner/license-codes/update' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) \wp_json_encode( [ 'ids' => [ 101 ], 'post_status' => 'available' ] ) );
		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}
}
