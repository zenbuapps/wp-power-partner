<?php
/**
 * OrderView
 */

declare(strict_types=1);

namespace J7\PowerPartner;

use J7\PowerPartner\Product\SiteSync;

/*
* PENDING
1. List 顯示開站狀態
2. List 顯示開站時間
*/

/**
 * Class OrderView
 */
final class Order {
	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Constructor.
	 */
	public function __construct() {
		\add_filter( 'manage_edit-shop_order_columns', [ $this, 'add_order_column' ] );
		\add_action( 'manage_shop_order_posts_custom_column', [ $this, 'render_order_column' ] );
		\add_action( 'add_meta_boxes', [ $this, 'add_metabox' ] );
	}

	/**
	 * Add order column.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function add_order_column( array $columns ): array {
		$columns[ SiteSync::CREATE_SITE_RESPONSES_META_KEY ] = '開站狀態';
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

		if ( SiteSync::CREATE_SITE_RESPONSES_META_KEY === $column ) {
			$order_id = $post->ID;
			$order    = \wc_get_order( $order_id );
			// wc_get_order() 也可能回 WC_Order_Refund，accessor 要求 WC_Order
			if ( ! $order instanceof \WC_Order ) {
				return;
			}

			// issue #23：讀法統一走 SiteSync 的單一 accessor
			$data = SiteSync::get_first_site_response_data( $order );

			if ( ! empty( $data ) ) {
				foreach ( $data as $key => $value ) {
					echo '<span>' . esc_html( (string) $key ) . ': ' . esc_html( (string) $value ) . '</span><br />';
				}
			}
		}
	}

	/**
	 * Add metabox to order page
	 *
	 * @return void
	 */
	public function add_metabox(): void {
		global $post;
		$order_id = $post->ID;
		$order    = \wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$responses_string = $order->get_meta( SiteSync::CREATE_SITE_RESPONSES_META_KEY );
		if ( ! $responses_string ) {
			return;
		}

		\add_meta_box( SiteSync::CREATE_SITE_RESPONSES_META_KEY . '_metabox', '此訂單的開站狀態', [ $this, SiteSync::CREATE_SITE_RESPONSES_META_KEY . '_callback' ], 'shop_order', 'side', 'high' );
	}

	/**
	 * Callback for metabox
	 *
	 * @return void
	 */
	public function pp_create_site_responses_callback(): void {
		global $post;
		$order_id = $post->ID;
		$order    = \wc_get_order( $order_id );
		// wc_get_order() 也可能回 WC_Order_Refund，accessor 要求 WC_Order
		if ( ! $order instanceof \WC_Order ) {
			echo '找不到訂單 #' . $order_id; // phpcs:ignore
			return;
		}
		// issue #23：讀法統一走 SiteSync 的單一 accessor
		$data = SiteSync::get_first_site_response_data( $order );

		if ( ! empty( $data ) ) {
			foreach ( $data as $key => $value ) {
				echo '<span>' . esc_html( (string) $key ) . ': ' . esc_html( (string) $value ) . '</span><br />';
			}
		}
	}
}
