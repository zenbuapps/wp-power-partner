<?php
/**
 * 開站流程端對端整合測試（issue #21 / #22 / #23 / #24 合流驗證）
 *
 * 既有測試把四個修復分段驗過了：開站綁定在 SiteSyncOrchestrationTest、
 * 冪等與佇列在 SiteSyncIdempotencyTest、##URL## 在 SubscriptionTokenTest、
 * 補排在 SubscriptionEmailHooksTest。但那些測試多半各自「手動塞好前一段的產物」
 * 再驗自己這一段——沒有任何一條把「付款 → 開站 → 綁定 → 補排 → 寄信」
 * 一路跑完，驗證四個修復的產出彼此真的接得上。
 *
 * 這個檔補的就是那條線。斷言一律落在「終端客戶／經銷商實際看得到的東西」上：
 *   - 客戶收到幾封信、信裡還有沒有 ##XXX## 佔位符（issue #21 的原始症狀）
 *   - 信裡的網址是不是真的網址（issue #23 的原始症狀）
 *   - 扣款前提醒有沒有排進 ActionScheduler（issue #22 的原始症狀）
 *   - 事件重送後是不是只有一個站、一封信（issue #24 的原始症狀）
 *
 * 這些是 mock 層級的端對端——HTTP 與 wp_mail 都被攔截，
 * 不觸及真實 PowerCloud API。
 */

declare( strict_types=1 );

namespace Tests\Integration;

use J7\PowerPartner\Api\Connect;
use J7\PowerPartner\Api\Main;
use J7\PowerPartner\Domains\Email\Core\SubscriptionEmailHooks;
use J7\PowerPartner\Product\SiteSync;
use J7\PowerPartner\ShopSubscription;

/**
 * @group smoke
 * @group happy
 * @group e2e
 */
class SiteSyncEndToEndTest extends TestCase {

	/** @var string Powerhouse Email 排程 hook 名稱 */
	private const SCHEDULER_HOOK = 'power_partner/3.1.0/email/scheduler';

	/** @var array<int, array{to: string, subject: string, message: string}> 攔截到的寄信紀錄 */
	private array $sent_emails = [];

	/** @var callable|null 目前掛載的 wp_mail filter */
	private $mail_mock = null;

	/** @var callable|null 目前掛載的 pre_http_request callback */
	private $http_mock = null;

	/** @var int 攔截到的開站 API 呼叫次數 */
	private int $http_call_count = 0;

	/** @var string 本次測試的開站信 key */
	private string $site_sync_email_key = '';

	/** @var string 本次測試的續約提醒信 key */
	private string $next_payment_email_key = '';

	/**
	 * 設定（每個測試前執行）
	 */
	public function set_up(): void {
		parent::set_up();
		\set_transient( Main::POWERCLOUD_API_KEY_TRANSIENT_KEY, 'test-api-key-e2e' );
		\update_option( Connect::PARTNER_ID_OPTION_NAME, 'test-partner-e2e' );

		$unique                       = uniqid();
		$this->site_sync_email_key    = 'e2e_site_sync_' . $unique;
		$this->next_payment_email_key = 'e2e_next_payment_' . $unique;

		$this->sent_emails     = [];
		$this->http_call_count = 0;
	}

	/**
	 * 清理（每個測試後執行）
	 */
	public function tear_down(): void {
		\delete_transient( Main::POWERCLOUD_API_KEY_TRANSIENT_KEY );
		\delete_option( Connect::PARTNER_ID_OPTION_NAME );

		if ( $this->http_mock ) {
			\remove_filter( 'pre_http_request', $this->http_mock, 10 );
			$this->http_mock = null;
		}
		if ( $this->mail_mock ) {
			\remove_filter( 'wp_mail', $this->mail_mock, 10 );
			$this->mail_mock = null;
		}

		// 重設 SubscriptionEmailHooks singleton，避免污染後續測試
		$reflection = new \ReflectionClass( SubscriptionEmailHooks::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		$this->sent_emails     = [];
		$this->http_call_count = 0;
		parent::tear_down();
	}

	// ========== Mock 基礎設施 ==========

	/**
	 * 掛載 HTTP mock，攔截開站請求並計次
	 *
	 * @param int    $status HTTP status code
	 * @param string $body   回應 body
	 */
	private function mock_http( int $status, string $body = '{}' ): void {
		$this->http_mock = function ( $pre, $args, $url ) use ( $status, $body ) {
			++$this->http_call_count;
			return [
				'headers'  => [],
				'body'     => $body,
				'response' => [
					'code'    => $status,
					'message' => '',
				],
				'cookies'  => [],
				'filename' => null,
			];
		};
		\add_filter( 'pre_http_request', $this->http_mock, 10, 3 );
	}

	/**
	 * 掛載 wp_mail mock，攔截「客戶實際會收到的信」
	 *
	 * 攔在 wp_mail filter 上是刻意的——Token::replace() 與 wpautop() 都在
	 * 呼叫 wp_mail 之前完成，所以這裡拿到的 subject / message
	 * 就是終端客戶信箱裡那封信的內容。
	 */
	private function mock_wp_mail(): void {
		$this->mail_mock = function ( $args ) {
			$this->sent_emails[] = [
				'to'      => is_array( $args['to'] ?? '' ) ? implode( ',', $args['to'] ) : (string) ( $args['to'] ?? '' ),
				'subject' => (string) ( $args['subject'] ?? '' ),
				'message' => (string) ( $args['message'] ?? '' ),
			];
			return $args;
		};
		\add_filter( 'wp_mail', $this->mail_mock, 10, 1 );
	}

	/**
	 * 寫入含「開站信 + 續約提醒信」的設定，並重設 singleton 讓 hooks 讀得到
	 *
	 * 開站信的 body 刻意塞滿站台 token——issue #21 的症狀就是這些 token
	 * 沒被取代而以字面外顯，不放進 body 就驗不到那個 bug。
	 */
	private function setup_emails(): SubscriptionEmailHooks {
		$this->setup_settings_with_emails(
			[
				$this->make_email_config(
					[
						'key'         => $this->site_sync_email_key,
						'action_name' => 'site_sync',
						'enabled'     => '1',
						'days'        => '0',
						'operator'    => 'after',
						'subject'     => '##FIRST_NAME## 您的網站已開通',
						'body'        => '<p>前台：##FRONTURL##</p><p>後台：##ADMINURL##</p>'
										. '<p>帳號：##SITEUSERNAME##</p><p>密碼：##SITEPASSWORD##</p>'
										. '<p>網址：##URL##</p>',
					]
				),
				$this->make_email_config(
					[
						'key'         => $this->next_payment_email_key,
						'action_name' => 'next_payment',
						'enabled'     => '1',
						'days'        => '7',
						'operator'    => 'before',
						'subject'     => '扣款前提醒',
						'body'        => '<p>您的訂閱即將扣款</p>',
					]
				),
			]
		);

		$reflection = new \ReflectionClass( SubscriptionEmailHooks::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		$hooks = SubscriptionEmailHooks::instance();

		$this->assertNotEmpty(
			$hooks->get_emails( 'site_sync' ),
			'設定中找不到 site_sync 模板，測試前置失敗'
		);
		$this->assertNotEmpty(
			$hooks->get_emails( 'next_payment' ),
			'設定中找不到 next_payment 模板，測試前置失敗'
		);

		return $hooks;
	}

	/**
	 * 建立一筆「可開站的 PowerCloud 訂閱」，含客戶姓名與未來的扣款日
	 *
	 * @param int $item_count 訂單要放幾個開站商品（驗多商品情境時給 2）
	 * @return \WC_Subscription
	 */
	private function create_payable_subscription( int $item_count = 1 ): \WC_Subscription {
		$unique      = uniqid();
		$customer_id = $this->factory()->user->create(
			[
				'role'       => 'customer',
				'user_email' => "e2e-{$unique}@example.com",
				'user_login' => "e2e-{$unique}",
				'first_name' => '小明',
				'last_name'  => '王',
			]
		);

		$order = \wc_create_order(
			[
				'customer_id' => $customer_id,
				'status'      => 'processing',
			]
		);
		$this->assertInstanceOf( \WC_Order::class, $order, '建立父訂單失敗' );

		for ( $i = 0; $i < $item_count; $i++ ) {
			$product_id = $this->create_subscription_product();
			\wp_set_object_terms( $product_id, 'subscription', 'product_type' );
			$this->set_product_pp_meta( $product_id, 'powercloud', 'tpl-e2e-' . $i, 'tw', 'plan-e2e' );
			$product = \wc_get_product( $product_id );
			$this->assertNotFalse( $product, '取得商品失敗' );
			$order->add_product( $product );
		}

		$order->set_billing_email( "e2e-{$unique}@example.com" );
		$order->set_billing_first_name( '小明' );
		$order->set_billing_last_name( '王' );
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

		/**
		 * 設定下次扣款日——這一步會 fire woocommerce_subscription_date_updated，
		 * 也就是 issue #22 描述的「排程唯一入口」。此刻 pp_linked_site_ids 還沒寫入，
		 * is_site_sync() 為 false，守門會直接 return：真實的 bug 時序在此重現。
		 */
		$subscription->update_dates(
			[ 'next_payment' => \gmdate( 'Y-m-d H:i:s', \time() + 30 * DAY_IN_SECONDS ) ]
		);
		$subscription->save();

		return $subscription;
	}

	/**
	 * 標準的 PowerCloud 開站成功回應
	 *
	 * @param string $website_id websiteId
	 * @return string JSON body
	 */
	private function powercloud_created_body( string $website_id = 'ws-e2e-001' ): string {
		return (string) \wp_json_encode(
			[
				'success'   => true,
				'message'   => 'created',
				'websiteId' => $website_id,
			]
		);
	}

	/**
	 * 查詢某訂閱的 pending Email 排程筆數
	 *
	 * @param int    $subscription_id 訂閱 ID
	 * @param string $action_name     action 名稱（即 AS group）
	 * @return int
	 */
	private function count_pending_emails( int $subscription_id, string $action_name ): int {
		$actions = \as_get_scheduled_actions(
			[
				'hook'     => self::SCHEDULER_HOOK,
				'group'    => $action_name,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 100,
			]
		);

		$count = 0;
		foreach ( $actions as $action ) {
			$args  = $action->get_args();
			$inner = $args[0] ?? [];
			if ( isset( $inner['subscription_id'] ) && (int) $inner['subscription_id'] === $subscription_id ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * 取出本次攔截到的開站通知信（依 subject 特徵過濾掉告警信）
	 *
	 * @return array<int, array{to: string, subject: string, message: string}>
	 */
	private function get_site_sync_emails(): array {
		return array_values(
			array_filter(
				$this->sent_emails,
				static fn( array $mail ): bool => str_contains( $mail['subject'], '您的網站已開通' )
			)
		);
	}

	/**
	 * 斷言一封信裡沒有任何未取代的 ##TOKEN## 佔位符
	 *
	 * 這是 issue #21 / #23 共同的客戶可見症狀，直接對信件全文斷言，
	 * 比檢查中間產物（payload / meta）更貼近「客戶到底看到什麼」。
	 *
	 * @param array{subject: string, message: string} $mail    信件
	 * @param string                                  $context 失敗訊息前綴
	 */
	private function assert_no_placeholder( array $mail, string $context ): void {
		$full = $mail['subject'] . "\n" . $mail['message'];
		$this->assertDoesNotMatchRegularExpression(
			'/##[A-Z_]+##/',
			$full,
			"{$context}：信件仍含未取代的佔位符（issue #21 / #23 的原始症狀）\n實際內容：\n{$full}"
		);
	}

	// ========== E2E-01：快樂路徑一次跑完四個修復 ==========

	/**
	 * 一筆訂單付款 → 開站 → 綁定 → 補排 → 寄信，四個 issue 的修復在同一條流程上都成立。
	 *
	 * 這是本檔最重要的一條：前面所有分段測試都是「假設上一段的產物正確」，
	 * 只有這一條驗證上一段真的餵得出下一段要的東西。
	 *
	 * @group smoke
	 * @group happy
	 */
	public function test_端對端_開站到寄信_四個修復同時成立(): void {
		$this->skip_if_no_subscriptions();
		$this->setup_emails();

		$subscription    = $this->create_payable_subscription();
		$subscription_id = $subscription->get_id();

		// issue #22 的 bug 狀態：此刻還沒綁站，扣款前提醒是 0 筆
		$this->assertSame(
			0,
			$this->count_pending_emails( $subscription_id, 'next_payment' ),
			'前置條件不成立：綁站之前不該有 next_payment 排程'
		);

		$this->mock_http( 201, $this->powercloud_created_body( 'ws-e2e-happy' ) );
		$this->mock_wp_mail();

		// ---- 付款完成 → 開站 ----
		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		$fresh = \wcs_get_subscription( $subscription_id );
		$this->assertInstanceOf( \WC_Subscription::class, $fresh );

		// issue #24：只打了一次開站 API
		$this->assertSame( 1, $this->http_call_count, 'issue #24：一次付款只該呼叫一次開站 API' );

		// 綁定成立
		$this->assertContains(
			'ws-e2e-happy',
			array_values( ShopSubscription::get_linked_site_ids( $subscription_id ) ),
			'開站成功後 websiteId 應綁上訂閱'
		);

		// issue #23：站台網址被持久化，不再只活在一次性的 payload 裡
		$site_url = (string) $fresh->get_meta( SiteSync::SITE_URL_META_KEY, true );
		$this->assertNotSame( '', $site_url, 'issue #23：開站成功後應寫入 pp_site_url' );
		$this->assertStringStartsWith( 'https://', $site_url, 'pp_site_url 應含 scheme' );

		// issue #22：綁定完成觸發補排，扣款前提醒從 0 筆變成有
		$this->assertGreaterThan(
			0,
			$this->count_pending_emails( $subscription_id, 'next_payment' ),
			'issue #22：綁站後應補排 next_payment 提醒信'
		);

		// ---- 4 分鐘後的延遲寄信 ----
		$this->assertSame( [], $this->get_site_sync_emails(), '開站當下不該寄開通信（issue #21 移除的路徑 A）' );

		( new SiteSync() )->send_email( (string) $fresh->get_billing_email(), $subscription_id );

		$mails = $this->get_site_sync_emails();

		// issue #21：只有一封，不是兩封
		$this->assertCount( 1, $mails, 'issue #21：開通信只該寄出一封' );

		// issue #21 + #23：客戶看到的信裡沒有任何 ##XXX##
		$this->assert_no_placeholder( $mails[0], 'issue #21/#23' );

		// issue #23：##URL## 換成的就是持久化的那個網址
		$this->assertStringContainsString(
			$site_url,
			$mails[0]['message'],
			'issue #23：信件內容應含實際站台網址'
		);
	}

	// ========== E2E-02：付款事件重送 ==========

	/**
	 * 付款完成事件重送兩次，全流程跑完仍然只有一個站、一封信，且信是乾淨的。
	 *
	 * issue #24 的既有測試驗的是「API 只被呼叫一次」；這一條往後多走一步，
	 * 驗證重送之後客戶端的最終結果（信的封數與內容）也沒被破壞。
	 *
	 * @group edge
	 */
	public function test_端對端_付款事件重送後仍只有一個站與一封乾淨的信(): void {
		$this->skip_if_no_subscriptions();
		$this->setup_emails();

		$subscription    = $this->create_payable_subscription();
		$subscription_id = $subscription->get_id();

		$this->mock_http( 201, $this->powercloud_created_body( 'ws-e2e-dup' ) );
		$this->mock_wp_mail();

		$site_sync = new SiteSync();

		// 同一個付款完成事件被送了兩次
		$site_sync->site_sync_by_subscription( $subscription, [] );
		$reloaded = \wcs_get_subscription( $subscription_id );
		$this->assertInstanceOf( \WC_Subscription::class, $reloaded );
		$site_sync->site_sync_by_subscription( $reloaded, [] );

		$this->assertSame( 1, $this->http_call_count, 'issue #24：事件重送不該重複呼叫開站 API' );

		$linked = array_values( ShopSubscription::get_linked_site_ids( $subscription_id ) );
		$this->assertSame( [ 'ws-e2e-dup' ], $linked, 'issue #24：重送不該重複綁定站台' );

		$fresh = \wcs_get_subscription( $subscription_id );
		$this->assertInstanceOf( \WC_Subscription::class, $fresh );

		$site_sync->send_email( (string) $fresh->get_billing_email(), $subscription_id );

		$mails = $this->get_site_sync_emails();
		$this->assertCount( 1, $mails, 'issue #24：重送後客戶仍只該收到一封開通信' );
		$this->assert_no_placeholder( $mails[0], 'issue #24 重送後' );
	}

	// ========== E2E-03：多商品訂單兩站各自寄信 ==========

	/**
	 * 一張訂單兩個開站商品，兩個站的帳密各自寄達，兩封都沒有佔位符。
	 *
	 * 這條同時壓住 issue #24 的 FIFO 佇列（舊版第二站會覆蓋第一站的 payload，
	 * 導致第一站帳密永久遺失）與 issue #23 的「第一個站先寫、之後不覆蓋」。
	 *
	 * @group edge
	 */
	public function test_端對端_多商品訂單兩站的帳密各自寄達且都無佔位符(): void {
		$this->skip_if_no_subscriptions();
		$this->setup_emails();

		$subscription    = $this->create_payable_subscription( 2 );
		$subscription_id = $subscription->get_id();

		$this->mock_http( 201, $this->powercloud_created_body( 'ws-e2e-multi' ) );
		$this->mock_wp_mail();

		$site_sync = new SiteSync();
		$site_sync->site_sync_by_subscription( $subscription, [] );

		$this->assertSame( 2, $this->http_call_count, '兩個開站商品應各打一次 API' );

		$fresh = \wcs_get_subscription( $subscription_id );
		$this->assertInstanceOf( \WC_Subscription::class, $fresh );

		$queue = $fresh->get_meta( 'email_payloads_tmp' );
		$this->assertIsArray( $queue );
		$this->assertCount( 2, $queue, 'issue #24：兩個站的 payload 應各自保留在佇列中' );

		$to = (string) $fresh->get_billing_email();

		// 兩個排程各消費一份 payload
		$site_sync->send_email( $to, $subscription_id );
		$site_sync->send_email( $to, $subscription_id );

		$mails = $this->get_site_sync_emails();
		$this->assertCount( 2, $mails, 'issue #24：兩個站應各寄一封開通信' );

		foreach ( $mails as $index => $mail ) {
			$this->assert_no_placeholder( $mail, "issue #24 第 " . ( $index + 1 ) . ' 封' );
		}

		// issue #23：pp_site_url 固定指向第一個站，不被第二站覆蓋
		$site_url = (string) $fresh->get_meta( SiteSync::SITE_URL_META_KEY, true );
		$this->assertSame(
			$queue[0]['DOMAIN'] ?? null,
			$site_url,
			'issue #23：pp_site_url 應鎖定第一個站的網域'
		);
	}

	// ========== E2E-04：公開擴充點不再夾帶寄信 ==========

	/**
	 * pp_site_sync_by_subscription 仍然照 fire（公開擴充點沒被砍掉），
	 * 但它不再導致任何一封開通信被寄出——這正是 issue #21 的修法。
	 *
	 * 分開驗這兩件事很重要：只驗「沒有重複寄信」的話，
	 * 把 action 整個刪掉也會通過，那會打破第三方監聽者的合約。
	 *
	 * @group happy
	 */
	public function test_端對端_公開擴充點照常觸發但不再夾帶開通信(): void {
		$this->skip_if_no_subscriptions();
		$this->setup_emails();

		$subscription = $this->create_payable_subscription();

		$fired = 0;
		$spy   = static function () use ( &$fired ): void {
			++$fired;
		};
		\add_action( 'pp_site_sync_by_subscription', $spy, 10, 1 );

		$this->mock_http( 201, $this->powercloud_created_body( 'ws-e2e-hook' ) );
		$this->mock_wp_mail();

		( new SiteSync() )->site_sync_by_subscription( $subscription, [] );

		\remove_action( 'pp_site_sync_by_subscription', $spy, 10 );

		$this->assertSame( 1, $fired, 'pp_site_sync_by_subscription 應仍為公開擴充點並照常 fire' );
		$this->assertSame(
			[],
			$this->get_site_sync_emails(),
			'issue #21：擴充點觸發時不該再夾帶寄出任何開通信'
		);
	}

	// ========== E2E-05：開站失敗不留下半套狀態 ==========

	/**
	 * 開站失敗（非 2xx）時，四個修復的產物一個都不該落地——
	 * 不綁站、不寫 pp_site_url、不補排、不寄信，而且冪等旗標不落（合法重試放行）。
	 *
	 * 這條是反向護欄：確認冪等鍵沒有把「失敗後的正當重試」一起擋掉。
	 *
	 * @group error
	 */
	public function test_端對端_開站失敗不留下半套狀態且允許重試(): void {
		$this->skip_if_no_subscriptions();
		$this->setup_emails();

		$subscription    = $this->create_payable_subscription();
		$subscription_id = $subscription->get_id();

		$this->mock_http( 400, (string) \wp_json_encode( [ 'message' => 'validation failed' ] ) );
		$this->mock_wp_mail();

		$site_sync = new SiteSync();
		$site_sync->site_sync_by_subscription( $subscription, [] );

		$fresh = \wcs_get_subscription( $subscription_id );
		$this->assertInstanceOf( \WC_Subscription::class, $fresh );

		$this->assertSame( [], array_values( ShopSubscription::get_linked_site_ids( $subscription_id ) ), '開站失敗不該綁定站台' );
		$this->assertSame( '', (string) $fresh->get_meta( SiteSync::SITE_URL_META_KEY, true ), 'issue #23：開站失敗不該寫入 pp_site_url' );
		$this->assertSame( 0, $this->count_pending_emails( $subscription_id, 'next_payment' ), 'issue #22：沒綁站就不該補排' );
		$this->assertSame( [], $this->get_site_sync_emails(), '開站失敗不該寄開通信' );
		$this->assertEmpty( $fresh->get_meta( 'email_payloads_tmp' ), '開站失敗不該留下 payload' );

		// 換成成功回應重試——冪等旗標不該擋下這次合法重試
		\remove_filter( 'pre_http_request', $this->http_mock, 10 );
		$this->http_call_count = 0;
		$this->mock_http( 201, $this->powercloud_created_body( 'ws-e2e-retry' ) );

		$reloaded = \wcs_get_subscription( $subscription_id );
		$this->assertInstanceOf( \WC_Subscription::class, $reloaded );
		$site_sync->site_sync_by_subscription( $reloaded, [] );

		$this->assertSame( 1, $this->http_call_count, 'issue #24：開站失敗後的重試應被放行' );
		$this->assertContains(
			'ws-e2e-retry',
			array_values( ShopSubscription::get_linked_site_ids( $subscription_id ) ),
			'重試成功後應完成綁定'
		);

		$final = \wcs_get_subscription( $subscription_id );
		$this->assertInstanceOf( \WC_Subscription::class, $final );
		$site_sync->send_email( (string) $final->get_billing_email(), $subscription_id );

		$mails = $this->get_site_sync_emails();
		$this->assertCount( 1, $mails, '重試成功後應寄出一封開通信' );
		$this->assert_no_placeholder( $mails[0], '失敗後重試' );
	}
}
