<?php
/**
 * Token::get_subscription_tokens() 整合測試（issue #23）
 *
 * 刻意獨立於 TokenTest——後者是純字串替換的測試，不該為了這幾條把
 * WooCommerce Subscriptions 依賴帶進去。
 *
 * 涵蓋：
 *   - ##URL## 以訂閱的 pp_site_url 為準
 *   - 舊資料 fallback 走 SiteSync::get_first_site_response_data()（[0]['data']，不是 ['data']）
 *   - 讀法已收斂：舊的「頂層 data」錯誤形狀不再被採用
 *   - 取不到網址時不回傳 URL key（維持 Token::replace() 的 continue 語義）
 *   - extract_site_url() 的欄位優先序與 scheme 補齊
 *
 * @package power-partner
 */

declare(strict_types=1);

namespace Tests\Integration;

use J7\PowerPartner\Utils\Token;
use J7\PowerPartner\Product\SiteSync;

/**
 * @group smoke
 * @group happy
 * @group edge
 */
class SubscriptionTokenTest extends TestCase {

	/** @var int 測試用客戶 ID */
	private int $customer_id;

	protected function configure_dependencies(): void {
		if ( ! class_exists( 'WC_Subscription' ) ) {
			return;
		}

		$this->customer_id = $this->factory()->user->create(
			[
				'role'       => 'customer',
				'user_email' => 'test-sub-token@example.com',
			]
		);
	}

	/**
	 * 建立含父訂單的真實訂閱
	 *
	 * @return array{0:\WC_Subscription,1:\WC_Order}
	 */
	private function create_subscription_with_order(): array {
		$order = wc_create_order(
			[
				'customer_id' => $this->customer_id,
				'status'      => 'processing',
			]
		);
		$this->assertInstanceOf( \WC_Order::class, $order );

		$subscription = wcs_create_subscription(
			[
				'order_id'         => $order->get_id(),
				'status'           => 'active',
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'customer_id'      => $this->customer_id,
			]
		);
		$this->assertInstanceOf( \WC_Subscription::class, $subscription );

		return [ $subscription, $order ];
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_訂閱有pp_site_url時取得URL_Token(): void {
		$this->skip_if_no_subscriptions();

		[ $subscription ] = $this->create_subscription_with_order();
		$subscription->update_meta_data( SiteSync::SITE_URL_META_KEY, 'https://abc.wpsite.pro' );
		$subscription->save();

		$tokens = Token::get_subscription_tokens( $subscription );

		$this->assertSame( 'https://abc.wpsite.pro', $tokens['URL'] ?? null );
	}

	/**
	 * 舊資料 fallback：pp_site_url 不存在時，從父訂單開站回應的第 0 筆撈
	 *
	 * @test
	 * @group edge
	 */
	public function test_訂閱無pp_site_url時回退讀父訂單開站回應的第0筆(): void {
		$this->skip_if_no_subscriptions();

		[ $subscription, $order ] = $this->create_subscription_with_order();

		// 實際寫入結構是 list（[{status,message,data}]）
		$order->update_meta_data(
			SiteSync::CREATE_SITE_RESPONSES_META_KEY,
			(string) wp_json_encode(
				[
					[
						'status'  => 201,
						'message' => '開站成功',
						'data'    => [ 'domain' => 'legacy.wpsite.pro' ],
					],
				]
			)
		);
		$order->save();

		$tokens = Token::get_subscription_tokens( $subscription );

		$this->assertSame(
			'https://legacy.wpsite.pro',
			$tokens['URL'] ?? null,
			'fallback 應讀 [0][data]，並在缺 scheme 時補 https://'
		);
	}

	/**
	 * 讀法收斂的回歸網：舊的「頂層 data」錯誤形狀不應再被採用
	 *
	 * issue #23 之前 Token.php 讀的是 $arr['data']（少一層 [0]）。
	 * 這條確保那個讀法確實已經移除。
	 *
	 * @test
	 * @group edge
	 */
	public function test_舊的頂層data讀法已不再使用(): void {
		$this->skip_if_no_subscriptions();

		[ $subscription, $order ] = $this->create_subscription_with_order();

		$order->update_meta_data(
			SiteSync::CREATE_SITE_RESPONSES_META_KEY,
			(string) wp_json_encode( [ 'data' => [ 'url' => 'https://wrong.example' ] ] )
		);
		$order->save();

		$tokens = Token::get_subscription_tokens( $subscription );

		$this->assertNotSame(
			'https://wrong.example',
			$tokens['URL'] ?? null,
			'頂層 data 是錯誤形狀，不應被採用'
		);
	}

	/**
	 * 取不到網址時不應回傳 URL key
	 *
	 * 刻意不回傳空字串——Token::replace() 對空值是 continue，
	 * 回傳空字串與不回傳的行為相同，但不回傳語義更清楚。
	 *
	 * @test
	 * @group edge
	 */
	public function test_無pp_site_url且無開站回應時不回傳URL_Token(): void {
		$this->skip_if_no_subscriptions();

		[ $subscription ] = $this->create_subscription_with_order();

		$tokens = Token::get_subscription_tokens( $subscription );

		$this->assertArrayNotHasKey( 'URL', $tokens );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_extract_site_url_欄位優先序(): void {
		$this->assertSame(
			'https://b.example',
			SiteSync::extract_site_url(
				[
					'domain'        => 'a.example',
					'primaryDomain' => 'b.example',
				]
			),
			'primaryDomain 優先序高於 domain'
		);

		$this->assertSame(
			'https://c.example',
			SiteSync::extract_site_url( [ 'url' => 'https://c.example' ] ),
			'已含 scheme 時不應重複前綴'
		);

		$this->assertSame(
			'http://d.example',
			SiteSync::extract_site_url( [ 'url' => 'http://d.example' ] ),
			'http 也算已含 scheme'
		);

		$this->assertSame( '', SiteSync::extract_site_url( [] ), '取不到時回傳空字串' );
		$this->assertSame( '', SiteSync::extract_site_url( [ 'domain' => '   ' ] ), '純空白視為無值' );
		$this->assertSame( '', SiteSync::extract_site_url( [ 'domain' => [ 'nested' ] ] ), '非純量值應跳過' );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_get_create_site_responses_對壞掉的JSON安全回傳空陣列(): void {
		$this->skip_if_no_subscriptions();

		[ , $order ] = $this->create_subscription_with_order();

		$order->update_meta_data( SiteSync::CREATE_SITE_RESPONSES_META_KEY, 'not-a-json' );
		$order->save();

		$this->assertSame( [], SiteSync::get_create_site_responses( $order ) );
		$this->assertSame( [], SiteSync::get_first_site_response_data( $order ) );
	}
}
