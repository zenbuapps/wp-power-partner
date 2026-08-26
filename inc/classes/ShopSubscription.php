<?php
/**
 * ShopSubscription 相關
 */

declare(strict_types=1);

namespace J7\PowerPartner;

use J7\PowerPartner\Product\SiteSync;
use J7\PowerPartner\Utils\Base;
use J7\PowerPartner\Product\DataTabs\LinkedSites;


/**
 * Class ShopSubscription
 *
 * Status:
 * active 已啟用
 * cancelled 已取消
 * expired 已過期
 * on-hold 保留
 * pending-cancel 待取消
 */
final class ShopSubscription {
	use \J7\WpUtils\Traits\SingletonTrait;

	const IS_POWER_PARTNER_SUBSCRIPTION = 'is_power_partner_site_sync';
	const POST_TYPE                     = 'shop_subscription';

	/**
	 * 訂閱綁定的網站 id 真的變更後觸發（issue #22）
	 *
	 * 這是 pp_linked_site_ids 的唯一收斂點——四個寫入路徑全部經過 update_linked_site_ids()：
	 *   1. PowerCloud 開站成功 201（Product\SiteSync::site_sync_powercloud()，同步）
	 *   2. WPCD 回調 /customer-notification（Api\Main::post_customer_notification_callback()）
	 *   3. WPCD 回調 /link-site（Api\Main::post_link_site_callback()）
	 *   4. 管理員後台手動編輯（ShopSubscription::save()）
	 *
	 * ⚠️ 監聽者請務必自行吞掉例外——路徑 1 是在 SiteSync::site_sync_by_subscription() 的
	 *    try/catch(\Throwable) 內同步呼叫，且在 email_payloads_tmp 寫入與 240 秒延遲排程「之前」，
	 *    往上拋的例外會被誤記成「網站建立失敗」訂單備註，並連帶殺掉正確的那封開站通知信。
	 */
	const LINKED_SITE_IDS_UPDATED_ACTION = 'pp_linked_site_ids_updated';


	/** Constructor */
	public function __construct() {

		\add_action( 'woocommerce_subscription_payment_complete', [ $this, 'add_meta' ], 0, 1 );
		// \add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		\add_action( 'save_post', [ $this, 'save' ] );
		\add_filter( 'manage_edit-' . self::POST_TYPE . '_columns', [ $this, 'add_order_column' ], 99, 1 );
		\add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ $this, 'render_order_column' ] );
	}

	/**
	 * Add post meta
	 * 加入 post meta 識別是網站訂閱
	 *
	 * @param \WC_Subscription $subscription subscription
	 * @return void
	 */
	public function add_meta( $subscription ) {
		$subscription = \wcs_get_subscription( $subscription );
		if ( ! ( $subscription instanceof \WC_Subscription ) ) {
			return;
		}
		$parent_order = $subscription->get_parent();
		if ( ! ( $parent_order instanceof \WC_Order ) ) {
			return;
		}
		$items = $parent_order->get_items();

		$is_site_sync = false;
		foreach ( $items as $item ) {
			/**
			 * Type
			 *
			 * @var \WC_Order_Item_Product $item
			 */
			$product_id = $item->get_product_id();
			$product    = \wc_get_product( $product_id );
			if ( ! ( $product instanceof \WC_Product ) ) {
				continue;
			}
			$product_type = $product->get_type();

			$linked_site_id = null;
			if ( 'variable-subscription' === $product_type ) {
				$variation_id   = $item->get_variation_id();
				$linked_site_id = \get_post_meta( $variation_id, LinkedSites::LINKED_SITE_FIELD_NAME, true );
			} elseif ( 'subscription' === $product_type ) {
				$linked_site_id = \get_post_meta( $product_id, LinkedSites::LINKED_SITE_FIELD_NAME, true );
			}

			if ( (bool) $linked_site_id) {
				$is_site_sync = true;
				break;
			}
		}

		if ( $is_site_sync ) {
			$subscription->add_meta_data( self::IS_POWER_PARTNER_SUBSCRIPTION, '1', true );
			$subscription->save();
		}
	}


	/**
	 * Get linked site ids
	 * 取得訂單網站的 site id
	 *
	 * @param int $subscription_id subscription id
	 * @return array<int|string, mixed>
	 */
	public static function get_linked_site_ids( $subscription_id ): array {

		$subscription = \wcs_get_subscription( $subscription_id );

		if ( ! $subscription ) {
			return [];
		}
		$meta_data       = $subscription->get_meta( SiteSync::LINKED_SITE_IDS_META_KEY, false );
		$linked_site_ids = [];
		if ( is_array( $meta_data ) ) {
			foreach ( $meta_data as $meta ) {
				if ( $meta instanceof \WC_Meta_Data ) {
					$meta_id                     = (int) $meta->__get( 'id' );
					$value                       = $meta->__get( 'value' );
					$linked_site_ids[ $meta_id ] = (string) $value;
				}
			}
		}

		return array_unique( $linked_site_ids );
	}

	/**
	 * Update linked site ids
	 * 更新訂單網站的 site id
	 *
	 * @param int                      $subscription_id subscription id
	 * @param array<int|string, mixed> $linked_site_ids linked site ids
	 * @return bool
	 */
	public static function update_linked_site_ids( $subscription_id, $linked_site_ids ) {
		$subscription = \wcs_get_subscription( $subscription_id );

		if ( ! $subscription ) {
			return false;
		}

		$old_linked_site_ids = self::get_linked_site_ids( $subscription_id );

		// 檢查是否有變更
		if ( self::is_same_site_ids( $old_linked_site_ids, $linked_site_ids ) ) {
			return false;
		}

		// 刪除所有舊的 meta（使用 delete_meta_data 會刪除所有相同 key 的值）
		$subscription->delete_meta_data( SiteSync::LINKED_SITE_IDS_META_KEY );

		// 添加新的 meta（每個 site_id 一筆）
		foreach ( $linked_site_ids as $site_id ) {
			$subscription->add_meta_data( SiteSync::LINKED_SITE_IDS_META_KEY, (string) $site_id, false );
		}

		// 記錄變更
		$subscription->add_order_note(
			\sprintf(
				/* translators: %1$s: old linked site ids, %2$s: new linked site ids */
				__( '更新了此訂閱的連結的網站 id: %1$s -> %2$s', 'power_partner' ),
				\implode( ', ', array_values( $old_linked_site_ids ) ),
				\implode( ', ', $linked_site_ids )
			)
		);

		$subscription->save();

		/**
		 * 訂閱綁定的網站 id 變更後（issue #22）
		 *
		 * 只在真的有變更時 fire——上方的 is_same_site_ids() 提前 return 已天然去重。
		 * 此時 meta 已寫入且已持久化，監聽者呼叫 is_site_sync() 必為 true。
		 *
		 * @param \WC_Subscription         $subscription        訂閱（meta 已寫入並已 save）
		 * @param array<int|string, mixed> $linked_site_ids     新的 site ids
		 * @param array<int|string, mixed> $old_linked_site_ids 舊的 site ids
		 */
		\do_action( self::LINKED_SITE_IDS_UPDATED_ACTION, $subscription, $linked_site_ids, $old_linked_site_ids );

		return true;
	}

	/**
	 * Check if two site id arrays are the same
	 * 檢查兩個 site id 陣列是否相同
	 *
	 * @param array<int|string, mixed> $old_ids Old site ids
	 * @param array<int|string, mixed> $new_ids New site ids
	 * @return bool
	 */
	private static function is_same_site_ids( array $old_ids, array $new_ids ): bool {
		$normalized_old_ids = array_values( array_map( static fn( $v ): int => (int) $v, $old_ids ) );
		sort( $normalized_old_ids );

		$normalized_new_ids = array_values( array_map( static fn( $v ): int => (int) $v, $new_ids ) );
		sort( $normalized_new_ids );

		return $normalized_old_ids === $normalized_new_ids;
	}

	/**
	 * Change linked site ids
	 * 變更訂閱的 site ids
	 *
	 * @param int                      $subscription_id subscription id
	 * @param array<int|string, mixed> $linked_site_ids linked site ids
	 * @return bool
	 */
	public static function change_linked_site_ids( $subscription_id, $linked_site_ids ) {
		try {
			self::remove_linked_site_ids( $linked_site_ids );
			self::update_linked_site_ids( $subscription_id, $linked_site_ids );

			return true;
		} catch ( \Throwable $th ) {
			return false;
		}
	}

	/**
	 * Remove linked site ids
	 * 移除訂單網站的 site id
	 *
	 * @param array<int|string, mixed> $linked_site_ids site ids to remove
	 * @return bool
	 */
	public static function remove_linked_site_ids( $linked_site_ids ): bool {
		try {
			// 移除原本連結的訂閱
			$subscription_ids_to_check = [];

			foreach ( $linked_site_ids as $site_id ) {
				$args = [
					'post_type'      => self::POST_TYPE,
					'posts_per_page' => -1,
					'post_status'    => 'any',
					'fields'         => 'ids',
					'meta_key'       => SiteSync::LINKED_SITE_IDS_META_KEY,
					'meta_value'     => (string) $site_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				];

				$subscription_ids          = \get_posts( $args );
				$subscription_ids_to_check = [
					...$subscription_ids_to_check,
					...$subscription_ids,
				];
			}

			foreach ( $subscription_ids_to_check as $subscription_id ) {
				$old_linked_site_ids    = self::get_linked_site_ids( $subscription_id );
				$filter_linked_site_ids = array_filter(
					$old_linked_site_ids,
					function ( $old_site_id ) use ( $linked_site_ids ) {
						return ! in_array( $old_site_id, $linked_site_ids );
					}
				);

				self::update_linked_site_ids( $subscription_id, $filter_linked_site_ids );
			}

			return true;
		} catch ( \Throwable $th ) {

			return false;
		}
	}

	/**
	 * Add meta box
	 * 加入 meta box
	 *
	 * @param string $post_type post type
	 * @return void
	 */
	public function add_meta_box( $post_type ) {
		$post_types = [ self::POST_TYPE ];

		if ( in_array( $post_type, $post_types, true ) ) {
			\add_meta_box(
				SiteSync::LINKED_SITE_IDS_META_KEY . '_meta_box',
				__( '此訂閱連結的網站 id', 'power_partner' ),
				[ $this, 'render_meta_box_content' ],
				$post_type,
				'advanced',
				'high'
			);
		}
	}

	/**
	 * Render meta box content
	 * 顯示 meta box 內容
	 *
	 * @param \WP_Post $post post
	 * @return void
	 */
	public function render_meta_box_content( $post ) {

		echo '只能移除，不能新增';

		$linked_site_ids = self::get_linked_site_ids( $post->ID );

		$options = [];

		foreach ( $linked_site_ids as $site_id ) {
			$site_id_str             = (string) $site_id;
			$options[ $site_id_str ] = '#' . $site_id_str;
		}

		\wp_nonce_field( SiteSync::LINKED_SITE_IDS_META_KEY . '_action', SiteSync::LINKED_SITE_IDS_META_KEY . '_nonce' );

		\woocommerce_wp_select(  // 使用方法 & 設定項可以看 WooCommerce 代碼
			[
				'id'                => SiteSync::LINKED_SITE_IDS_META_KEY,
				'name'              => SiteSync::LINKED_SITE_IDS_META_KEY . '[]',  // 🚩這邊要加上 []，不然 POST 給後端，會抓到 single string 而不是 array
				'style'             => 'width:25rem;',
				'class'             => '',
				'label'             => '',
				'value'             => $linked_site_ids, // 這邊就放你從後端拿的資料
				'options'           => $options, // key => value 的陣列
				'custom_attributes' => [
					'multiple'         => 'multiple', // 🚩 這是要給 selectWoo 抓的
					'data-allow-clear' => 'true', // select2 設定項，請自行查閱
				],
			]
		);

		// phpcs:disable
		?>
		<script>
			(function($) {
				$('#<?php echo SiteSync::LINKED_SITE_IDS_META_KEY;?>').selectWoo();
			})(jQuery)
		</script>
		<?php
		// phpcs:enable
	}

	/**
	 * Save
	 * 儲存
	 *
	 * @param int $post_id post id
	 * @return void
	 */
	public function save( $post_id ) {

		$nonce = (string) ( $_POST[SiteSync::LINKED_SITE_IDS_META_KEY . '_nonce'] ?? '' ); // phpcs:ignore
		$linked_site_ids = $_POST[SiteSync::LINKED_SITE_IDS_META_KEY] ?? []; // phpcs:ignore

		// Verify that the nonce is valid.
		if ( ! \wp_verify_nonce( $nonce, SiteSync::LINKED_SITE_IDS_META_KEY . '_action' ) ) {
			return;
		}

		if ( ! is_array( $linked_site_ids ) ) {
			$linked_site_ids = [];
		}

		$old_linked_site_ids = self::get_linked_site_ids( $post_id );

		if ( $old_linked_site_ids === $linked_site_ids ) {
			return;
		}

		self::update_linked_site_ids( $post_id, $linked_site_ids );
	}

	/**
	 * Add order column.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function add_order_column( array $columns ): array {
		$columns[ SiteSync::LINKED_SITE_IDS_META_KEY ] = '綁定的網站 ids';
		return $columns;
	}

	/**
	 * Render order column.
	 *
	 * @param string $column Column.
	 * @return void
	 */
	public function render_order_column( $column ): void {
		global $post;

		if ( SiteSync::LINKED_SITE_IDS_META_KEY === $column ) {
			$subscription_id = $post->ID;
			$linked_site_ids = self::get_linked_site_ids( $subscription_id );

			echo \esc_html( implode( ', ', $linked_site_ids ) );
		}
	}
}
