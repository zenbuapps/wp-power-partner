<?php
/**
 * ShopSubscription 整合測試
 *
 * 覆蓋 ShopSubscription 的核心邏輯：
 * - get_linked_site_ids() 讀取 multi-value meta
 * - update_linked_site_ids() 更新 meta
 * - is_same_site_ids() 比較邏輯（private，透過 update 測試）
 * - change_linked_site_ids() 先移除再更新
 * - remove_linked_site_ids() 跨訂閱移除
 *
 * 注意：這些測試需要 WooCommerce Subscriptions，無法安裝時自動跳過
 */

declare( strict_types=1 );

namespace Tests\Integration;

use J7\PowerPartner\ShopSubscription;
use J7\PowerPartner\Product\SiteSync;

/**
 * @group smoke
 * @group happy
 * @group error
 * @group edge
 */
class ShopSubscriptionTest extends TestCase {

	protected function configure_dependencies(): void {
		// 這些測試需要 WooCommerce Subscriptions
		if ( ! class_exists( 'WC_Subscription' ) ) {
			return;
		}
	}

	/**
	 * 建立一個模擬訂閱貼文（不需要完整的 WC_Subscription）
	 * 使用 shop_subscription post type 但只操作 post meta
	 *
	 * @return int post id
	 */
	private function create_subscription_post(): int {
		return $this->factory()->post->create(
			[
				'post_type'   => 'shop_subscription',
				'post_status' => 'wc-active',
				'post_title'  => '測試訂閱',
			]
		);
	}

	// ========== 冒煙測試（Smoke）==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_ShopSubscription類別存在(): void {
		$this->assertTrue( class_exists( ShopSubscription::class ) );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_LINKED_SITE_IDS_META_KEY常數值正確(): void {
		$this->assertSame( 'pp_linked_site_ids', SiteSync::LINKED_SITE_IDS_META_KEY );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_CREATE_SITE_RESPONSES_META_KEY常數值正確(): void {
		$this->assertSame( 'pp_create_site_responses', SiteSync::CREATE_SITE_RESPONSES_META_KEY );
	}

	// ========== 快樂路徑（Happy Flow）==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_is_same_site_ids_空陣列相同(): void {
		$this->skip_if_no_subscriptions();

		// 透過 update_linked_site_ids 間接測試 is_same_site_ids
		$post_id = $this->create_subscription_post();
		$result  = ShopSubscription::update_linked_site_ids( $post_id, [] );

		// 初始沒有 site id，更新為空陣列，is_same_site_ids 為 true → 不更新 → 返回 false
		$this->assertFalse( $result, '空陣列相同時不應更新，應回傳 false' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_update_linked_site_ids_第一次設定成功(): void {
		$this->skip_if_no_subscriptions();

		$post_id     = $this->create_subscription_post();
		$site_ids    = [ '123', '456' ];

		// 使用 add_post_meta 直接操作模擬 WC_Subscription meta
		// 這裡只測試 post meta 層面的邏輯
		foreach ( $site_ids as $site_id ) {
			add_post_meta( $post_id, SiteSync::LINKED_SITE_IDS_META_KEY, $site_id, false );
		}

		$stored = get_post_meta( $post_id, SiteSync::LINKED_SITE_IDS_META_KEY );
		$this->assertCount( 2, $stored );
		$this->assertContains( '123', $stored );
		$this->assertContains( '456', $stored );
	}

	// ========== 錯誤處理（Error Handling）==========

	/**
	 * @test
	 * @group error
	 */
	public function test_get_linked_site_ids_不存在的訂閱ID回傳空陣列(): void {
		$this->skip_if_no_subscriptions();

		// 使用不存在的訂閱 ID
		$result = ShopSubscription::get_linked_site_ids( 9999999 );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_update_linked_site_ids_不存在的訂閱回傳false(): void {
		$this->skip_if_no_subscriptions();

		$result = ShopSubscription::update_linked_site_ids( 9999999, [ '123' ] );

		$this->assertFalse( $result );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_remove_linked_site_ids_空陣列不崩潰(): void {
		$this->skip_if_no_subscriptions();

		$result = ShopSubscription::remove_linked_site_ids( [] );

		$this->assertTrue( $result );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_change_linked_site_ids_不存在的訂閱_因為沒有例外所以回傳true(): void {
		$this->skip_if_no_subscriptions();

		// change_linked_site_ids 內部 try/catch，只要沒有例外就回傳 true
		// 即使傳入不存在的 subscription_id，也不會拋出例外
		// （update_linked_site_ids 會回傳 false 但被忽略）
		$result = ShopSubscription::change_linked_site_ids( 9999999, [ '123' ] );

		$this->assertTrue( $result, 'change_linked_site_ids 無例外時應回傳 true' );
	}

	// ========== 邊緣案例（Edge Cases）==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_pp_linked_site_ids_多值meta的存取行為(): void {
		$post_id = $this->factory()->post->create( [ 'post_type' => 'shop_subscription' ] );

		// 模擬 multi-value meta（每個 site_id 一筆）
		add_post_meta( $post_id, 'pp_linked_site_ids', '100', false );
		add_post_meta( $post_id, 'pp_linked_site_ids', '200', false );
		add_post_meta( $post_id, 'pp_linked_site_ids', '300', false );

		// 確認不使用 single=true 才能取到多值
		$all_values    = get_post_meta( $post_id, 'pp_linked_site_ids' );
		$single_value  = get_post_meta( $post_id, 'pp_linked_site_ids', true );

		$this->assertCount( 3, $all_values, '使用 single=false 應取到 3 個值' );
		$this->assertSame( '100', $single_value, '使用 single=true 只取到第一個值' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_pp_linked_site_ids_重複的site_id存入後能讀回(): void {
		$post_id = $this->factory()->post->create( [ 'post_type' => 'shop_subscription' ] );

		// 允許重複值的 multi-value meta
		add_post_meta( $post_id, 'pp_linked_site_ids', '100', false );
		add_post_meta( $post_id, 'pp_linked_site_ids', '100', false );

		$values = get_post_meta( $post_id, 'pp_linked_site_ids' );
		// 原始存入 2 個相同值
		$this->assertCount( 2, $values );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_CREATE_SITE_RESPONSES_meta_JSON格式(): void {
		$post_id = $this->factory()->post->create( [ 'post_type' => 'shop_order' ] );

		$responses = [
			[
				'status'  => 201,
				'message' => 'success',
				'data'    => [
					'websiteId' => 'wp-abc123',
					'domain'    => 'example.wpsite.pro',
				],
			],
		];

		update_post_meta( $post_id, SiteSync::CREATE_SITE_RESPONSES_META_KEY, wp_json_encode( $responses ) );

		$stored = get_post_meta( $post_id, SiteSync::CREATE_SITE_RESPONSES_META_KEY, true );
		$parsed = json_decode( $stored, true );

		$this->assertIsArray( $parsed );
		$this->assertCount( 1, $parsed );
		$this->assertSame( 201, $parsed[0]['status'] );
		$this->assertSame( 'wp-abc123', $parsed[0]['data']['websiteId'] );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_pp_linked_site_ids_為超出整數上限的值時不崩潰(): void {
		$post_id = $this->factory()->post->create( [ 'post_type' => 'shop_subscription' ] );

		// 超出 PHP_INT_MAX 的值（以字串形式儲存）
		$huge_id = '99999999999999999999';
		add_post_meta( $post_id, 'pp_linked_site_ids', $huge_id, false );

		$values = get_post_meta( $post_id, 'pp_linked_site_ids' );
		$this->assertContains( $huge_id, $values );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_pp_linked_site_ids_包含Unicode字元的site_id(): void {
		$post_id = $this->factory()->post->create( [ 'post_type' => 'shop_subscription' ] );

		$unicode_id = 'site-台灣-123';
		add_post_meta( $post_id, 'pp_linked_site_ids', $unicode_id, false );

		$values = get_post_meta( $post_id, 'pp_linked_site_ids' );
		$this->assertContains( $unicode_id, $values );
	}

	// ========== issue #22：pp_linked_site_ids_updated hook ==========

	/**
	 * 建立真實 WC_Subscription（hook 測試需要，裸 post 過不了 wcs_get_subscription()）
	 *
	 * @return \WC_Subscription
	 */
	private function create_real_subscription(): \WC_Subscription {
		// wcs_create_subscription() 沒有 customer_id 會回 WP_Error
		$customer_id = $this->factory()->user->create( [ 'role' => 'customer' ] );

		$order = wc_create_order(
			[
				'customer_id' => $customer_id,
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
				'customer_id'      => $customer_id,
			]
		);
		$this->assertInstanceOf( \WC_Subscription::class, $subscription );

		return $subscription;
	}

	/**
	 * 綁定真的變更時應觸發 pp_linked_site_ids_updated，且 callback 收到的訂閱 meta 已寫入
	 *
	 * @test
	 * @group happy
	 */
	public function test_update_linked_site_ids_有變更時應觸發pp_linked_site_ids_updated(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_real_subscription();
		$sub_id       = $subscription->get_id();

		$received = null;
		add_action(
			ShopSubscription::LINKED_SITE_IDS_UPDATED_ACTION,
			function ( $sub ) use ( &$received ) {
				$received = $sub;
			},
			10,
			1
		);

		$result = ShopSubscription::update_linked_site_ids( $sub_id, [ '777' ] );

		$this->assertTrue( $result, 'update_linked_site_ids 應回傳 true' );
		$this->assertInstanceOf( \WC_Subscription::class, $received, 'hook 應被觸發並帶入 WC_Subscription' );
		$this->assertSame(
			$sub_id,
			$received->get_id(),
			'callback 收到的應是同一筆訂閱'
		);
		$this->assertNotEmpty(
			$received->get_meta( SiteSync::LINKED_SITE_IDS_META_KEY, true ),
			'fire 時 meta 應已寫入並持久化，否則監聽者的 is_site_sync() 會是 false'
		);
	}

	/**
	 * 綁定沒有變更時不應觸發 hook（避免管理員按了儲存但沒改東西也重排程）
	 *
	 * @test
	 * @group edge
	 */
	public function test_update_linked_site_ids_無變更時不應觸發pp_linked_site_ids_updated(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_real_subscription();
		$sub_id       = $subscription->get_id();

		ShopSubscription::update_linked_site_ids( $sub_id, [ '888' ] );

		$fired = 0;
		add_action(
			ShopSubscription::LINKED_SITE_IDS_UPDATED_ACTION,
			function () use ( &$fired ) {
				++$fired;
			},
			10,
			1
		);

		// 綁同一組 id，is_same_site_ids() 會提前 return false
		$result = ShopSubscription::update_linked_site_ids( $sub_id, [ '888' ] );

		$this->assertFalse( $result, '內容未變更時 update_linked_site_ids 應回傳 false' );
		$this->assertSame( 0, $fired, '內容未變更時不應 fire hook' );
	}

	/**
	 * PowerCloud UUID 換綁時必須被認出是「變更」
	 *
	 * is_same_site_ids() 若以 (int) 正規化，所有 UUID 都會變成 0，
	 * 於是 UUID-A → UUID-B 會被判成無變更：meta 不寫入（綁定靜默遺失）、
	 * pp_linked_site_ids_updated 也不 fire（issue #22 的補排收不到事件）。
	 *
	 * @test
	 * @group error
	 */
	public function test_update_linked_site_ids_UUID換綁應被視為變更(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_real_subscription();
		$sub_id       = $subscription->get_id();

		$uuid_a = 'a1b2c3d4-1111-4aaa-8bbb-000000000001';
		$uuid_b = 'a1b2c3d4-2222-4aaa-8bbb-000000000002';

		ShopSubscription::update_linked_site_ids( $sub_id, [ $uuid_a ] );

		$fired = 0;
		add_action(
			ShopSubscription::LINKED_SITE_IDS_UPDATED_ACTION,
			function () use ( &$fired ) {
				++$fired;
			},
			10,
			1
		);

		$result = ShopSubscription::update_linked_site_ids( $sub_id, [ $uuid_b ] );

		$this->assertTrue( $result, 'UUID 換綁應被視為變更（(int) 正規化會把兩個 UUID 都變成 0）' );
		$this->assertSame( 1, $fired, 'UUID 換綁應 fire 一次 hook' );

		$ids = array_values( ShopSubscription::get_linked_site_ids( $sub_id ) );
		$this->assertSame( [ $uuid_b ], $ids, '換綁後應真的寫入新的 UUID' );
	}

	/**
	 * 數字 site id 的既有行為不可因為改字串比較而改變
	 *
	 * @test
	 * @group edge
	 */
	public function test_update_linked_site_ids_數字id的相同判定行為不變(): void {
		$this->skip_if_no_subscriptions();

		$subscription = $this->create_real_subscription();
		$sub_id       = $subscription->get_id();

		ShopSubscription::update_linked_site_ids( $sub_id, [ '101', '202' ] );

		// 同一組 id、順序不同、型別不同（int vs string）→ 仍應判定為無變更
		$this->assertFalse(
			ShopSubscription::update_linked_site_ids( $sub_id, [ 202, 101 ] ),
			'數字 id 的順序與型別差異不應被當成變更'
		);

		// 真的多一個站 → 應判定為變更
		$this->assertTrue(
			ShopSubscription::update_linked_site_ids( $sub_id, [ '101', '202', '303' ] ),
			'新增一個站應被視為變更'
		);
	}
}
