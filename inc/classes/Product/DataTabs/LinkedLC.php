<?php
/**
 * 在 wp-admin 商品編輯頁
 * 對 wc-tabs 新增欄位
 * 只對 簡單訂閱商品 & 可變訂閱商品 新增
 * 連結的 License Code 商品
 */

declare (strict_types = 1);

namespace J7\PowerPartner\Product\DataTabs;

use J7\PowerPartner\Plugin;
use J7\PowerPartner\Utils\Base;
use J7\WpUtils\Classes\General;
use J7\PowerPartner\Api\Connect;
use J7\PowerPartner\Domains\Cloud\Services\CloudClient;

/**
 * Class LinkedLC
 */
final class LinkedLC {
	use \J7\WpUtils\Traits\SingletonTrait;

	const LC_PRODUCT_SELECTOR                        = 'linked_lc_product_selector';
	const FIELD_NAME                                 = 'linked_lc_products';
	const CLOUD_PRODUCTS_TRANSIENT_KEY               = 'pp_cloud_products';
	const CLEAR_CLOUD_PRODUCTS_TRANSIENT_ACTION_NAME = 'clear_' . self::CLOUD_PRODUCTS_TRANSIENT_KEY;
	const CACHE_TIME                                 = 24 * HOUR_IN_SECONDS;

	/**
	 * Constructor
	 */
	public function __construct() {
		\add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ], 30, 1 );

		\add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'custom_field_subscription' ], 30, 1 );
		\add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_subscription' ], 20 );

		\add_action( 'woocommerce_product_after_variable_attributes', [ __CLASS__, 'custom_field_variable_subscription' ], 20, 3 );
		\add_action( 'woocommerce_save_product_variation', [ __CLASS__, 'save_variable_subscription' ], 20, 2 );

		// 清除 transient callback
		\add_action( 'admin_post_' . self::CLEAR_CLOUD_PRODUCTS_TRANSIENT_ACTION_NAME, [ __CLASS__, 'clear_cloud_products_transient_callback' ] );
	}

	/**
	 * 輸出連接授權碼的欄位
	 *
	 * @return void
	 */
	public static function custom_field_subscription(): void {
		global $post;
		$product_id                 = $post->ID;
		$default_linked_lc_products = \get_post_meta($product_id, self::FIELD_NAME, true);
		if (!\is_array($default_linked_lc_products)) {
			$default_linked_lc_products = [];
		}
		$default_linked_lc_products_json_encode = htmlspecialchars(\json_encode($default_linked_lc_products), ENT_QUOTES, 'UTF-8'); // @phpstan-ignore-line

		// 清除快取的連結授權碼商品
		$action_url = \add_query_arg( 'action', self::CLEAR_CLOUD_PRODUCTS_TRANSIENT_ACTION_NAME, \admin_url( 'admin-post.php?' ) );

		// render 商品 & 授權碼數量選擇器
		printf(
		/*html*/"
			<div class='flex hidden items-center py-4 show_if_subscription'>
				<div class='w-[150px] pl-3 mt-2 self-start'>關聯授權碼</div>
				<div class='%1\$s'>
					<div class='%2\$s' data-field_name='%3\$s' data-default_linked_lc_products='%4\$s'></div>
				</div>
				<a href='%5\$s' class='self-start ml-2 button'>清除快取</a>
			</div>
			",
		'w-[calc((100%-180px)/2)]',
		self::LC_PRODUCT_SELECTOR,
		self::FIELD_NAME,
		$default_linked_lc_products_json_encode,
		$action_url
		);
	}

	/**
	 * Save for subscription
	 *
	 * @param int $product_id product id
	 * @return void
	 */
	public static function save_subscription( $product_id ): void {
		// phpcs:disable
		if ( isset( $_POST[ self::FIELD_NAME ] ) ) {
			$linked_lc_products = $_POST[ self::FIELD_NAME ];
			if ( is_array( $linked_lc_products ) ) {
				/** @var array<array{product_slug: string, quantity: string}> $linked_lc_products */
				$formatted_linked_lc_products = self::format_linked_lc_products($linked_lc_products);
				\update_post_meta( $product_id, self::FIELD_NAME, $formatted_linked_lc_products );
			}
		}
		// phpcs:enable
	}

	/**
	 * Custom field for variable subscription
	 * Add custom field to product tab
	 *
	 * @param int      $loop loop
	 * @param array    $variation_data variation data
	 * @param \WP_Post $variation variation post object
	 *
	 * @return void
	 * @phpstan-ignore-next-line
	 */
	public static function custom_field_variable_subscription( $loop, $variation_data, $variation ): void { // phpcs:ignore

		$variation_id               = $variation->ID;
		$default_linked_lc_products = \get_post_meta($variation_id, self::FIELD_NAME, true);
		if (!\is_array($default_linked_lc_products)) {
			$default_linked_lc_products = [];
		}

		$default_linked_lc_products_json_encode = htmlspecialchars(\json_encode($default_linked_lc_products), ENT_QUOTES, 'UTF-8'); // @phpstan-ignore-line
		// 商品 & 授權碼數量選擇器
		printf(
		/*html*/"
			<div class='hidden clear-both show_if_variable-subscription'>
				<div class=''>關聯授權碼</div>
				<div class='%1\$s'>
					<div class='%2\$s' data-field_name='%3\$s' data-default_linked_lc_products='%4\$s'></div>
				</div>
			</div>
			",
		'w-full',
		self::LC_PRODUCT_SELECTOR,
		self::FIELD_NAME . '[' . $loop . ']',
		$default_linked_lc_products_json_encode
		);
	}

	/**
	 * Save for variable subscription
	 *
	 * @param int $variation_id variation id
	 * @param int $loop loop
	 * @return void
	 */
	public static function save_variable_subscription( $variation_id, $loop ): void {
		// phpcs:disable
		$field_data = $_POST[ self::FIELD_NAME ] ?? null;
		if ( is_array( $field_data ) && isset( $field_data[ $loop ] ) ) {
			$linked_lc_products = $field_data[ $loop ];
			if ( is_array( $linked_lc_products ) ) {
				/** @var array<array{product_slug: string, quantity: string}> $linked_lc_products */
				$formatted_linked_lc_products = self::format_linked_lc_products($linked_lc_products);
				\update_post_meta( $variation_id, self::FIELD_NAME, $formatted_linked_lc_products );
			}
		}
		// phpcs:enable
	}

	/**
	 * 載入 linked_lc_product_selector 組件 js
	 * 只在商品編輯介面載入
	 *
	 * @param string $hook 目前頁面
	 * @return void
	 */
	public static function enqueue_scripts( $hook ): void {

		if (!in_array($hook, [ 'post.php', 'post-new.php' ], true)) {
			return;
		}

		\wp_enqueue_style(
			self::LC_PRODUCT_SELECTOR,
			Plugin::$url . '/inc/assets/dist/css/index.css',
			[],
			Plugin::$version,
		);

		\wp_enqueue_script(
		self::LC_PRODUCT_SELECTOR,
		Plugin::$url . '/inc/assets/dist/linked_lc_product_selector.js',
		[ 'jquery' ],
		Plugin::$version,
		[
			'strategy'  => 'defer',
			'in_footer' => true,
		]
		);

		Plugin::instance()->add_module_handle(self::LC_PRODUCT_SELECTOR, 'defer');

		$cloud_products = self::get_cloud_products();

		\wp_localize_script(
			self::LC_PRODUCT_SELECTOR,
			self::LC_PRODUCT_SELECTOR . '_data',
			[
				'cloud_products' => $cloud_products,
			]
		);
	}

	/**
	 * 取得站長路可授權碼商品
	 *
	 * @return array<array{slug: string, label: string, rate: float}>
	 */
	public static function get_cloud_products(): array {
		/**
		 * @var array<array{slug: string, label: string, rate: float}>|false $cloud_products
		 */
		$cloud_products = \get_transient(self::CLOUD_PRODUCTS_TRANSIENT_KEY);
		if (false !== $cloud_products) {
			return (array) $cloud_products;
		}

		// user_id 是 CloudServer 上的經銷商 id（決定授權碼牌價等級），不是本站的使用者 id。
		// 尚未連線（沒有 partner_id）時維持舊行為送本站使用者 id，避免更新後商品編輯頁的授權碼選單變空
		$partner_id = (string) \get_option( Connect::PARTNER_ID_OPTION_NAME, '' );
		$response   = CloudClient::instance()->remote_get(
			'license-codes/products',
			[
				'user_id' => '' !== $partner_id ? $partner_id : \get_current_user_id(),
			],
			[ 'timeout' => 120 ]
		);

		if (\is_wp_error($response)) {
			\set_transient(self::CLOUD_PRODUCTS_TRANSIENT_KEY, [], self::CACHE_TIME);
			return [];
		}

		$body = \wp_remote_retrieve_body($response);

		$default_data_value = [
			[
				'slug'  => '',
				'label' => '請選擇',
				'rate'  => null,
			],
		];

		$parsed = (array) General::json_parse($body, $default_data_value);
		if (isset($parsed['code'])) {
			$parsed = [];
		}

		/** @var array<array{slug: string, label: string, rate: float}> $parsed */
		\set_transient(self::CLOUD_PRODUCTS_TRANSIENT_KEY, $parsed, self::CACHE_TIME);

		return $parsed;
	}


	/**
	 * 格式化連結的授權碼商品
	 * 避免重複
	 *
	 * @param array<array{product_slug: string, quantity: string}> $linked_lc_products 原始資料
	 * @return list<array{product_slug: string, quantity: int}> 格式化後的資料
	 */
	public static function format_linked_lc_products( array $linked_lc_products ): array {
		$merged = [];

		foreach ($linked_lc_products as $product) {
			$slug            = $product['product_slug'];
			$qty             = (int) ( $merged[ $slug ] ?? 0 );
			$merged[ $slug ] = ( $qty + (int) $product['quantity'] );
		}

		$result = [];
		foreach ($merged as $slug => $quantity) {
			$result[] = [
				'product_slug' => $slug,
				'quantity'     => $quantity,
			];
		}

		return $result;
	}

	/**
	 * 清除快取的授權碼商品
	 *
	 * @return void
	 */
	public static function clear_cloud_products_transient_callback(): void {
		\delete_transient(self::CLOUD_PRODUCTS_TRANSIENT_KEY);
		$current_url = \wp_get_referer();
		\wp_safe_redirect( $current_url ?: \admin_url() );
		exit;
	}
}
