<?php
/**
 * LC 授權碼生命週期整合測試（缺口 #2）
 *
 * 驗證「首次付款建立授權碼 → 訂閱失敗排程停用 → 訂閱恢復重啟」
 * 整段授權碼生命週期，對應 specs/features/it-coverage/授權碼生命週期整合測試.feature。
 *
 * 覆蓋 10 條 Rule：
 *   R01 前置：未設定 partner_id 時不建立授權碼
 *   R02 前置：商品未設定 linked_lc_products 時不建立授權碼
 *   R03 後置：呼叫 CloudServer POST license-codes 並帶正確參數
 *   R04 後置：建立成功後把回傳的 LC ID 寫入訂閱 lc_id meta（multi-value）
 *   R05 後置：建立成功後寄送授權碼開通 Email 給客戶
 *   R06 後置：建立 API 回傳 WP_Error 時寫失敗備註且不中斷整批
 *   R07 前置：訂閱沒有綁定 lc_id 時 subscription_success 不呼叫恢復 API
 *   R08 後置：訂閱失敗時排程 4 小時後停用授權碼，並先取消既有排程
 *   R09 後置：訂閱恢復時取消停用排程並呼叫 CloudServer license-codes/recover
 *   R10 後置：恢復 API 回傳 WP_Error 時寫失敗備註
 */

declare( strict_types=1 );

namespace Tests\Integration;

use J7\PowerPartner\Api\Connect;
use J7\PowerPartner\Domains\LC\Core\LifeCycle;
use J7\PowerPartner\Domains\LC\Services\ExpireHandler;
use J7\PowerPartner\Product\DataTabs\LinkedLC;

/**
 * @group smoke
 * @group happy
 * @group error
 * @group edge
 */
class LcLifeCycleTest extends TestCase {

	/** @var array{url: string, args: array<string, mixed>}|null 攔截到的最後一個 HTTP 請求 */
	private ?array $last_request = null;

	/** @var callable|null 目前掛載的 pre_http_request callback */
	private $http_mock = null;

	/** @var array<int, array{to: string, subject: string}> 攔截到的寄信紀錄 */
	private array $sent_emails = [];

	/** @var callable|null 目前掛載的 wp_mail filter */
	private $mail_mock = null;

	/** ExpireHandler hook 名稱（從原始碼常數取得） */
	private const LC_EXPIRE_HOOK = 'power_partner/3.1.0/lc/expire';

	/**
	 * 設定（每個測試前執行）
	 */
	public function set_up(): void {
		parent::set_up();
		\update_option( Connect::PARTNER_ID_OPTION_NAME, '12345' );
		$this->sent_emails = [];
		// 確保 ExpireHandler 已向 ActionScheduler 註冊
		ExpireHandler::register();
	}

	/**
	 * 清理（每個測試後執行）
	 */
	public function tear_down(): void {
		\delete_option( Connect::PARTNER_ID_OPTION_NAME );

		if ( $this->http_mock ) {
			\remove_filter( 'pre_http_request', $this->http_mock, 10 );
			$this->http_mock = null;
		}
		if ( $this->mail_mock ) {
			\remove_filter( 'wp_mail', $this->mail_mock, 10 );
			$this->mail_mock = null;
		}
		$this->last_request = null;
		$this->sent_emails  = [];
		parent::tear_down();
	}

	/**
	 * 掛載 HTTP mock，攔截所有 wp_remote_* 請求
	 *
	 * @param int|\WP_Error $status_or_error HTTP status code 或 WP_Error
	 * @param string        $body            回應 body
	 */
	private function mock_http( int|\WP_Error $status_or_error, string $body = '{}' ): void {
		$this->http_mock = function ( $pre, $args, $url ) use ( $status_or_error, $body ) {
			$this->last_request = [
				'url'  => $url,
				'args' => $args,
			];
			if ( $status_or_error instanceof \WP_Error ) {
				return $status_or_error;
			}
			return [
				'headers'  => [],
				'body'     => $body,
				'response' => [
					'code'    => $status_or_error,
					'message' => '',
				],
				'cookies'  => [],
				'filename' => null,
			];
		};
		\add_filter( 'pre_http_request', $this->http_mock, 10, 3 );
	}

	/**
	 * 掛載 wp_mail mock，攔截所有寄信請求
	 */
	private function mock_wp_mail(): void {
		$this->mail_mock = function ( $args ) {
			$this->sent_emails[] = [
				'to'      => is_array( $args['to'] ) ? implode( ',', $args['to'] ) : (string) $args['to'],
				'subject' => (string) ( $args['subject'] ?? '' ),
			];
			return false;
		};
		\add_filter( 'wp_mail', $this->mail_mock, 10, 1 );
	}

	/**
	 * 建立含 linked_lc_products 的訂閱商品與訂閱
	 *
	 * @param array<int, array{product_slug: string, quantity: int}> $lc_products linked_lc_products 設定
	 * @param string $billing_email 帳單 Email
	 * @return \WC_Subscription
	 */
	private function create_subscription_with_lc_products(
		array $lc_products = [],
		string $billing_email = 'customer@example.com'
	): \WC_Subscription {
		$customer_id = $this->factory()->user->create(
			[
				'role'       => 'customer',
				'user_email' => $billing_email,
			]
		);

		$product_id = $this->create_subscription_product();
		\wp_set_object_terms( $product_id, 'subscription', 'product_type' );

		if ( ! empty( $lc_products ) ) {
			\update_post_meta( $product_id, LinkedLC::FIELD_NAME, $lc_products );
		}

		$order = \wc_create_order(
			[
				'customer_id' => $customer_id,
				'status'      => 'processing',
			]
		);
		$this->assertInstanceOf( \WC_Order::class, $order, '建立父訂單失敗' );

		$product = \wc_get_product( $product_id );
		$this->assertNotFalse( $product, '取得商品失敗' );
		$order->add_product( $product );
		$order->set_billing_first_name( '測試' );
		$order->set_billing_last_name( '用戶' );
		$order->set_billing_email( $billing_email );
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
		$this->assertInstanceOf( \WC_Subscription::class, $subscription, '建立訂閱失敗' );
		$subscription->set_billing_email( $billing_email );
		$subscription->save();

		return $subscription;
	}

	/**
	 * 取得訂閱的所有訂單備註內容
	 *
	 * @param int $subscription_id 訂閱 ID
	 * @return array<string>
	 */
	private function get_order_notes( int $subscription_id ): array {
		$notes = \wc_get_order_notes(
			[
				'order_id' => $subscription_id,
				'limit'    => 50,
			]
		);
		return array_map( static fn( $note ) => (string) $note->content, $notes );
	}

	/**
	 * 建立標準 license_codes API 回應 body
	 *
	 * @param array<int, array{id: int, code: string, product_slug: string, product_name: string}> $codes
	 * @return string
	 */
	private function make_lc_response_body( array $codes ): string {
		return (string) \wp_json_encode(
			[
				'status' => 200,
				'data'   => [
					'license_codes' => array_map(
						function ( array $code ) {
							return array_merge(
								[
									'id'              => 777,
									'status'          => 'active',
									'code'            => 'CODE-001',
									'type'            => 'plugin',
									'subscription_id' => 0,
									'customer_id'     => 0,
									'expire_date'     => 0,
									'domain'          => '',
									'product_slug'    => 'power-course',
									'download_url'    => 'https://example.com/download',
									'product_name'    => 'Power Course',
								],
								$code
							);
						},
						$codes
					),
				],
			]
		);
	}

	// ========== R01: 前置 — 未設定 partner_id 時不建立授權碼 ==========

	/**
	 * Rule: 未設定 partner_id 時不建立授權碼。
	 *
	 * @group edge
	 */
	public function test_create_lcs_skips_when_no_partner_id(): void {
		$this->skip_if_no_subscriptions();
		\delete_option( Connect::PARTNER_ID_OPTION_NAME );

		$lc_products  = [ [ 'product_slug' => 'power-course', 'quantity' => 2 ] ];
		$subscription = $this->create_subscription_with_lc_products( $lc_products );
		$this->mock_http( 200 );

		( new LifeCycle() )->create_lcs( $subscription, [] );

		$this->assertNull( $this->last_request, '未設定 partner_id 時不應發出建立授權碼的 HTTP 請求' );
	}

	// ========== R02: 前置 — 商品未設定 linked_lc_products 時不建立授權碼 ==========

	/**
	 * Rule: 商品未設定 linked_lc_products 時不建立授權碼。
	 *
	 * @group edge
	 */
	public function test_create_lcs_skips_when_no_linked_lc_products(): void {
		$this->skip_if_no_subscriptions();
		// 不傳 lc_products，讓商品沒有 linked_lc_products
		$subscription = $this->create_subscription_with_lc_products( [] );
		$this->mock_http( 200 );

		( new LifeCycle() )->create_lcs( $subscription, [] );

		$this->assertNull( $this->last_request, '商品無 linked_lc_products 時不應發出建立授權碼的 HTTP 請求' );
	}

	// ========== R03: 後置 — 呼叫 CloudServer POST license-codes 帶正確參數 ==========

	/**
	 * Rule: 呼叫 CloudServer POST license-codes 並帶正確參數（product_slug/quantity/post_author）。
	 *
	 * @group smoke
	 * @group happy
	 */
	public function test_create_lcs_posts_to_correct_endpoint_with_params(): void {
		$this->skip_if_no_subscriptions();
		$lc_products  = [ [ 'product_slug' => 'power-course', 'quantity' => 2 ] ];
		$subscription = $this->create_subscription_with_lc_products( $lc_products );

		$response_body = $this->make_lc_response_body( [ [ 'id' => 777, 'product_slug' => 'power-course' ] ] );
		$this->mock_http( 200, $response_body );

		( new LifeCycle() )->create_lcs( $subscription, [] );

		$this->assertNotNull( $this->last_request, '應有發出 HTTP 請求' );
		$this->assertStringContainsString(
			'license-codes',
			$this->last_request['url'],
			'請求 URL 應指向 license-codes endpoint'
		);

		// 驗請求 body 含正確參數
		$body = $this->last_request['args']['body'] ?? '';
		if ( is_string( $body ) ) {
			$decoded = \json_decode( $body, true );
		} else {
			$decoded = $body;
		}
		$this->assertIsArray( $decoded, '請求 body 應為可解析的陣列' );
		$this->assertSame( 'power-course', $decoded['product_slug'] ?? null, '請求應含 product_slug' );
		$this->assertSame( 2, (int) ( $decoded['quantity'] ?? 0 ), '請求應含 quantity=2' );
		$this->assertSame( '12345', (string) ( $decoded['post_author'] ?? '' ), '請求應含 post_author=partner_id' );
		$this->assertSame( $subscription->get_id(), (int) ( $decoded['subscription_id'] ?? 0 ), '請求應含 subscription_id' );
		$this->assertSame( $subscription->get_customer_id(), (int) ( $decoded['customer_id'] ?? 0 ), '請求應含 customer_id' );
	}

	// ========== R04: 後置 — 建立成功後 LC ID 寫入 lc_id meta（multi-value）==========

	/**
	 * Rule: 建立成功後把回傳的 LC ID 寫入訂閱 lc_id meta（multi-value）。
	 *
	 * @group happy
	 */
	public function test_create_lcs_binds_lc_ids_to_subscription(): void {
		$this->skip_if_no_subscriptions();
		$lc_products  = [ [ 'product_slug' => 'power-course', 'quantity' => 2 ] ];
		$subscription = $this->create_subscription_with_lc_products( $lc_products );

		$response_body = $this->make_lc_response_body(
			[
				[ 'id' => 777, 'code' => 'CODE-777' ],
				[ 'id' => 778, 'code' => 'CODE-778' ],
			]
		);
		$this->mock_http( 200, $response_body );

		( new LifeCycle() )->create_lcs( $subscription, [] );

		$lc_ids = \get_post_meta( $subscription->get_id(), 'lc_id', false );
		$this->assertIsArray( $lc_ids, 'lc_id meta 應為陣列' );
		$this->assertContains( '777', $lc_ids, 'lc_id 應包含 777' );
		$this->assertContains( '778', $lc_ids, 'lc_id 應包含 778' );

		// linked_lc_products meta 應被寫入
		$fresh_sub = \wcs_get_subscription( $subscription->get_id() );
		$this->assertInstanceOf( \WC_Subscription::class, $fresh_sub );
		$linked = $fresh_sub->get_meta( LinkedLC::FIELD_NAME );
		$this->assertNotEmpty( $linked, 'linked_lc_products meta 應被寫入' );
	}

	// ========== R05: 後置 — 建立成功後寄開通 Email 給客戶 ==========

	/**
	 * Rule: 建立成功後寄送授權碼開通 Email 給客戶（帳單信箱）。
	 *
	 * @group happy
	 */
	public function test_create_lcs_sends_activation_email_to_customer(): void {
		$this->skip_if_no_subscriptions();
		$billing_email = 'customer-lc@example.com';
		$lc_products   = [ [ 'product_slug' => 'power-course', 'quantity' => 1 ] ];
		$subscription  = $this->create_subscription_with_lc_products( $lc_products, $billing_email );

		$response_body = $this->make_lc_response_body(
			[
				[
					'id'           => 777,
					'product_name' => 'Power Course',
					'product_slug' => 'power-course',
					'code'         => 'CODE-001',
					'download_url' => 'https://example.com/download',
				],
			]
		);
		$this->mock_http( 200, $response_body );
		$this->mock_wp_mail();

		( new LifeCycle() )->create_lcs( $subscription, [] );

		$this->assertNotEmpty( $this->sent_emails, '應發送 Email 給客戶' );
		$found = array_filter(
			$this->sent_emails,
			fn( $email ) => strpos( $email['to'], $billing_email ) !== false
		);
		$this->assertNotEmpty( $found, "應寄信給帳單信箱 {$billing_email}" );
		// 主旨應含「授權碼已開通」
		$subjects = array_column( iterator_to_array( new \ArrayIterator( array_values( $found ) ) ), 'subject' );
		$matching_subjects = array_filter( $subjects, fn( $s ) => strpos( $s, '授權碼已開通' ) !== false );
		$this->assertNotEmpty( $matching_subjects, '主旨應含「授權碼已開通」' );
	}

	// ========== R06: 後置 — 建立 API 回傳 WP_Error 時寫失敗備註且不中斷 ==========

	/**
	 * Rule: 建立 API 回傳 WP_Error 時寫失敗備註且不中斷整批。
	 *
	 * @group error
	 */
	public function test_create_lcs_writes_failure_note_on_wp_error(): void {
		$this->skip_if_no_subscriptions();
		$lc_products  = [ [ 'product_slug' => 'power-course', 'quantity' => 2 ] ];
		$subscription = $this->create_subscription_with_lc_products( $lc_products );

		$this->mock_http( new \WP_Error( 'http_request_failed', 'Connection refused' ) );

		( new LifeCycle() )->create_lcs( $subscription, [] );

		$notes = $this->get_order_notes( $subscription->get_id() );
		$this->assertNotEmpty(
			preg_grep( '/《新增》授權碼/', $notes ),
			'建立授權碼失敗時備註應含「《新增》授權碼」，實際備註：' . print_r( $notes, true ) // phpcs:ignore
		);
		$this->assertNotEmpty(
			preg_grep( '/❌|失敗/', $notes ),
			'建立授權碼失敗時備註應含失敗標記，實際備註：' . print_r( $notes, true ) // phpcs:ignore
		);

		// 訂閱不應綁定任何 lc_id
		$lc_ids = \get_post_meta( $subscription->get_id(), 'lc_id', false );
		$this->assertEmpty( $lc_ids, '建立失敗時訂閱不應綁定任何 lc_id' );
	}

	// ========== R07: 前置 — 無 lc_id 時 subscription_success 不呼叫恢復 API ==========

	/**
	 * Rule: 訂閱沒有綁定 lc_id 時 subscription_success 不呼叫恢復 API。
	 *
	 * @group edge
	 */
	public function test_subscription_success_skips_when_no_lc_ids(): void {
		$this->skip_if_no_subscriptions();
		$subscription = $this->create_subscription_with_lc_products( [] );
		// 確認沒有 lc_id
		$this->assertEmpty( \get_post_meta( $subscription->get_id(), 'lc_id', false ) );

		$this->mock_http( 200 );

		( new LifeCycle() )->subscription_success( $subscription, [] );

		$this->assertNull( $this->last_request, '無 lc_id 時不應發出授權碼恢復的 HTTP 請求' );
	}

	// ========== R08: 後置 — 訂閱失敗時排程 4 小時後停用授權碼 ==========

	/**
	 * Rule: 訂閱失敗時排程 4 小時後停用授權碼，並先取消既有排程。
	 *
	 * @group happy
	 */
	public function test_subscription_failed_schedules_lc_expire_in_4_hours(): void {
		$this->skip_if_no_subscriptions();
		$subscription    = $this->create_subscription_with_lc_products( [] );
		$subscription_id = $subscription->get_id();

		// 寫入 lc_ids，讓 ExpireHandler 有資料
		\add_post_meta( $subscription_id, 'lc_id', '101' );
		\add_post_meta( $subscription_id, 'lc_id', '102' );

		$before = time();
		( new LifeCycle() )->subscription_failed( $subscription, [] );
		$after = time();

		// 驗排程存在（4 小時 ±5 分鐘）
		// Base::schedule_single 傳入 AS 的 args 格式為 [ $this->args ]（包一層陣列）
		// 需用 as_get_scheduled_actions 手動過濾 subscription_id
		$all_pending = \as_get_scheduled_actions(
			[
				'hook'     => self::LC_EXPIRE_HOOK,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 100,
			]
		);
		$found_action = null;
		foreach ( $all_pending as $action ) {
			$args  = $action->get_args();
			$inner = $args[0] ?? $args;
			$sid   = is_array( $inner ) ? ( $inner['subscription_id'] ?? null ) : null;
			if ( $sid !== null && (int) $sid === $subscription_id ) {
				$found_action = $action;
				break;
			}
		}
		$this->assertNotNull( $found_action, '應建立授權碼過期排程（hook: ' . self::LC_EXPIRE_HOOK . '）' );
		$scheduled = $found_action->get_schedule()->get_date()->getTimestamp();

		$four_hours = 4 * HOUR_IN_SECONDS;
		$tolerance  = 5 * MINUTE_IN_SECONDS;

		$this->assertGreaterThanOrEqual(
			$before + $four_hours - $tolerance,
			$scheduled,
			'排程時間不得早於 now+4h-5min'
		);
		$this->assertLessThanOrEqual(
			$after + $four_hours + $tolerance,
			$scheduled,
			'排程時間不得晚於 now+4h+5min'
		);
	}

	/**
	 * Rule: 重複失敗時先取消既有排程再建立新排程（subscription_failed 先 maybe_unschedule 再 schedule_single）。
	 *
	 * @group edge
	 */
	public function test_subscription_failed_cancels_existing_schedule_before_creating_new(): void {
		$this->skip_if_no_subscriptions();
		$subscription    = $this->create_subscription_with_lc_products( [] );
		$subscription_id = $subscription->get_id();

		\add_post_meta( $subscription_id, 'lc_id', '101' );

		// 第一次失敗
		( new LifeCycle() )->subscription_failed( $subscription, [] );
		// 第二次失敗
		( new LifeCycle() )->subscription_failed( $subscription, [] );

		// 只應有一個 pending 排程
		$pending_actions = \as_get_scheduled_actions(
			[
				'hook'   => self::LC_EXPIRE_HOOK,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			]
		);
		$found = array_filter(
			$pending_actions,
			function ( $action ) use ( $subscription_id ) {
				$args  = $action->get_args();
				// Base::schedule_single 包一層陣列：[ ['subscription_id' => id, ...] ]
				$inner = $args[0] ?? $args;
				$sid   = is_array( $inner ) ? ( $inner['subscription_id'] ?? null ) : null;
				return $sid !== null && (int) $sid === $subscription_id;
			}
		);
		$this->assertCount( 1, $found, '重複失敗後應只有一個 pending LC 過期排程' );
	}

	// ========== R09: 後置 — 訂閱恢復時取消排程並呼叫 license-codes/recover ==========

	/**
	 * Rule: 訂閱恢復時取消停用排程並呼叫 CloudServer license-codes/recover。
	 *
	 * @group happy
	 */
	public function test_subscription_success_cancels_schedule_and_calls_recover_api(): void {
		$this->skip_if_no_subscriptions();
		$subscription    = $this->create_subscription_with_lc_products( [] );
		$subscription_id = $subscription->get_id();

		// 寫入 lc_ids
		\add_post_meta( $subscription_id, 'lc_id', '101' );
		\add_post_meta( $subscription_id, 'lc_id', '102' );

		// 建立一個 pending 排程，模擬之前的失敗排程
		( new LifeCycle() )->subscription_failed( $subscription, [] );

		// 驗排程存在
		$pending_before = \as_next_scheduled_action( self::LC_EXPIRE_HOOK );
		$this->assertNotFalse( $pending_before, '前提：應有 pending 的 LC 過期排程' );

		// 設定 recover API mock
		$recover_response = \wp_json_encode(
			[
				'status' => 200,
				'data'   => [ 'recovered' => 2 ],
			]
		);
		$this->mock_http( 200, (string) $recover_response );

		( new LifeCycle() )->subscription_success( $subscription, [] );

		// 驗排程已取消
		// Base 包一層陣列，需手動過濾
		$remaining = \as_get_scheduled_actions(
			[
				'hook'     => self::LC_EXPIRE_HOOK,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 100,
			]
		);
		$found_after = array_filter(
			$remaining,
			function ( $action ) use ( $subscription_id ) {
				$args  = $action->get_args();
				$inner = $args[0] ?? $args;
				$sid   = is_array( $inner ) ? ( $inner['subscription_id'] ?? null ) : null;
				return $sid !== null && (int) $sid === $subscription_id;
			}
		);
		$this->assertCount( 0, $found_after, '恢復後 pending 的 LC 過期排程應被取消' );

		// 驗 recover API 已被呼叫
		$this->assertNotNull( $this->last_request, '應有發出恢復授權碼的 HTTP 請求' );
		$this->assertStringContainsString(
			'license-codes/recover',
			$this->last_request['url'],
			'請求 URL 應指向 license-codes/recover endpoint'
		);

		// 驗請求 body 含 ids
		$body = $this->last_request['args']['body'] ?? '';
		if ( is_string( $body ) ) {
			$decoded = \json_decode( $body, true );
		} else {
			$decoded = $body;
		}
		$this->assertIsArray( $decoded );
		$ids = $decoded['ids'] ?? [];
		$this->assertContains( '101', $ids, '請求 body 應含 lc_id 101' );
		$this->assertContains( '102', $ids, '請求 body 應含 lc_id 102' );

		// 驗訂閱備註含成功標記
		$notes = $this->get_order_notes( $subscription_id );
		$this->assertNotEmpty(
			preg_grep( '/《重啟》授權碼/', $notes ),
			'恢復成功後備註應含「《重啟》授權碼」，實際備註：' . print_r( $notes, true ) // phpcs:ignore
		);
		$this->assertNotEmpty(
			preg_grep( '/✅|成功/', $notes ),
			'恢復成功後備註應含成功標記，實際備註：' . print_r( $notes, true ) // phpcs:ignore
		);
	}

	// ========== R10: 後置 — 恢復 API 回傳 WP_Error 時寫失敗備註 ==========

	/**
	 * Rule: 恢復 API 回傳 WP_Error 時寫失敗備註。
	 *
	 * @group error
	 */
	public function test_subscription_success_writes_failure_note_on_recover_wp_error(): void {
		$this->skip_if_no_subscriptions();
		$subscription    = $this->create_subscription_with_lc_products( [] );
		$subscription_id = $subscription->get_id();

		\add_post_meta( $subscription_id, 'lc_id', '101' );

		$this->mock_http( new \WP_Error( 'http_request_failed', '連線逾時' ) );

		( new LifeCycle() )->subscription_success( $subscription, [] );

		$notes = $this->get_order_notes( $subscription_id );
		$this->assertNotEmpty(
			preg_grep( '/《重啟》授權碼/', $notes ),
			'恢復失敗後備註應含「《重啟》授權碼」，實際備註：' . print_r( $notes, true ) // phpcs:ignore
		);
		$this->assertNotEmpty(
			preg_grep( '/❌|失敗/', $notes ),
			'恢復失敗後備註應含失敗標記，實際備註：' . print_r( $notes, true ) // phpcs:ignore
		);
	}
}
