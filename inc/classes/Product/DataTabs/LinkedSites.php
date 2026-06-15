<?php
/**
 * 在 wp-admin 商品編輯頁
 * 對 wc-tabs 新增欄位
 * 只對 簡單訂閱商品 & 可變訂閱商品 新增
 * 連結的開站網站
 */

declare (strict_types = 1);

namespace J7\PowerPartner\Product\DataTabs;

use J7\PowerPartner\Api\Connect;
use J7\PowerPartner\Api\Fetch;
use J7\PowerPartner\Api\FetchPowerCloud;
use J7\PowerPartner\Plugin;
/**
 * Class LinkedSites
 */
final class LinkedSites {
	use \J7\WpUtils\Traits\SingletonTrait;

	const HOST_TYPE_FIELD_NAME      = 'power_partner_host_type'; // wpcd | powercloud
	const HOST_POSITION_FIELD_NAME  = 'power_partner_host_position';
	const LINKED_SITE_FIELD_NAME    = 'power_partner_linked_site';
	const OPEN_SITE_PLAN_FIELD_NAME = 'power_partner_open_site_plan';
	const PRODUCT_TYPE_SLUG         = 'power_partner';
	const DEFAULT_HOST_POSITION     = 'jp';
	const DEFAULT_HOST_TYPE         = 'powercloud'; // 預設為新架構
	const WPCD_HOST_TYPE            = 'wpcd';

	const CLEAR_ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_ACTION_NAME            = 'clear_' . Fetch::ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY;
	const CLEAR_ALLOWED_TEMPLATE_OPTIONS_POWERCLOUD_TRANSIENT_ACTION_NAME = 'clear_' . FetchPowerCloud::ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY;
	const CLEAR_OPEN_SITE_PLAN_OPTIONS_POWERCLOUD_TRANSIENT_ACTION_NAME   = 'clear_' . FetchPowerCloud::OPEN_SITE_PLAN_OPTIONS_TRANSIENT_KEY;

	/**
	 * @var array<string, string> slug, name
	 */
	public $host_positions = [
		'jp'        => '日本',
		'tw'        => '台灣',
		'us_west'   => '美西',
		'uk_london' => '英國倫敦',
		'sg'        => '新加坡',
		'hk'        => '香港',
		'canada'    => '加拿大',
	];

	/**
	 * Constructor
	 */
	public function __construct() {

		// \add_action( 'woocommerce_subscriptions_product_options_pricing', array( $this, 'custom_field' ), 20 );
		\add_action( 'woocommerce_product_options_general_product_data', [ $this, 'custom_field_subscription' ], 20, 1 );
		\add_action( 'woocommerce_process_product_meta', [ $this, 'save_subscription' ], 20 );

		\add_action( 'woocommerce_product_after_variable_attributes', [ $this, 'custom_field_variable_subscription' ], 20, 3 );
		\add_action( 'woocommerce_save_product_variation', [ $this, 'save_variable_subscription' ], 20, 2 );

		\add_action( 'admin_post_' . self::CLEAR_ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_ACTION_NAME, [ $this, 'clear_allowed_template_options_transient_callback' ] );
		\add_action('admin_post_' . self::CLEAR_ALLOWED_TEMPLATE_OPTIONS_POWERCLOUD_TRANSIENT_ACTION_NAME, [ $this, 'clear_allowed_template_options_transient_callback' ]);

		\add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_tab_scripts' ], 20 );
	}

	/**
	 * 解析「既有連結站」的實際架構
	 *
	 * 停用/啟用的對象是已開好的站，其架構的 ground truth 是「連結站 id 格式」，
	 * 而非產品 host_type 欄位（該欄位只決定「新站開在哪」，且 migration 前的舊產品多為空）。
	 *
	 * 規則：
	 *  1. 產品 host_type 有明確值（wpcd / powercloud）→ 直接採用，不覆寫站長設定。
	 *  2. host_type 為空（或非法值）→ 依連結站 id 格式推斷：
	 *     純數字 = WPCD 舊架構（WPCD site_id 為數字 DB id），其餘（slug / UUID）= PowerCloud 新架構。
	 *
	 * 為什麼不沿用「空 = powercloud」：host_type 欄位是後來才加的，2026-02 PowerCloud 遷移前
	 * 開的舊站產品多半為空，這些站全在 WPCD；數字 site id 丟到 PowerCloud API 永遠 404
	 * → 停用靜默失敗、站照跑、卡照扣（issue #18）。同時，2026 後以空值預設開的站才是 PowerCloud
	 * （id 為非數字 slug），故空值時改以 id 格式區分兩個世代。
	 *
	 * 注意：只在 host_type 為空時才用 id 格式推斷；明確標記 powercloud 的站即使 id 為數字也採用
	 * 其標記，避免「數字一律 WPCD」把正常 PowerCloud 站反向誤導。
	 *
	 * @param string $product_host_type 產品 meta 的 host_type（可能為空）
	 * @param string $site_id           連結站 id
	 * @return string self::WPCD_HOST_TYPE 或 self::DEFAULT_HOST_TYPE（powercloud）
	 */
	public static function resolve_host_type( string $product_host_type, string $site_id ): string {
		if ( self::WPCD_HOST_TYPE === $product_host_type || self::DEFAULT_HOST_TYPE === $product_host_type ) {
			return $product_host_type;
		}

		return \ctype_digit( $site_id ) ? self::WPCD_HOST_TYPE : self::DEFAULT_HOST_TYPE;
	}

	/**
	 * Custom field for subscription
	 * Add custom field to product tab
	 *
	 * @return void
	 */
	public function custom_field_subscription(): void {
		global $post;
		$product_id          = $post->ID;
		$host_position_value = (string) \get_post_meta( $product_id, self::HOST_POSITION_FIELD_NAME, true );
		$host_position_value = empty( $host_position_value ) ? self::DEFAULT_HOST_POSITION : $host_position_value;

		$host_type_value = (string) \get_post_meta( $product_id, self::HOST_TYPE_FIELD_NAME, true );
		if ( empty( $host_type_value ) ) {
			$host_type_value = self::DEFAULT_HOST_TYPE;
		}

		$tab1_options = [
			'jp'        => '日本',
			'tw'        => '台灣',
			'us_west'   => '美西',
			'uk_london' => '英國倫敦',
			'sg'        => '新加坡',
			'hk'        => '香港',
			'canada'    => '加拿大',
		];

		$tab2_options = [
			'tw' => '台灣',
		];

		$active_tab = ( 'wpcd' === $host_type_value ) ? 'tab1' : 'tab2';

		$partner_id = \get_option( Connect::PARTNER_ID_OPTION_NAME );

		echo '<div class="hidden options_group subscription_pricing show_if_subscription">';

		if ( ! empty( $partner_id ) ) {
			echo '<div class="power-partner-host-tabs-wrapper">';
			echo '<div class="power-partner-tabs-container">';

			echo '<div class="power-partner-tab-buttons">';
			echo '<button type="button" class="power-partner-tab-button' . ( 'tab2' === $active_tab ? ' active' : '' ) . '" data-tab="tab2-subscription">新架構</button>';
			echo '<button type="button" class="power-partner-tab-button' . ( 'tab1' === $active_tab ? ' active' : '' ) . '" data-tab="tab1-subscription">舊架構</button>';
			echo '</div>';

			$linked_site_value                    = (string) \get_post_meta( $product_id, self::LINKED_SITE_FIELD_NAME, true );
			$open_site_plan_value                 = (string) \get_post_meta( $product_id, self::OPEN_SITE_PLAN_FIELD_NAME, true );
			$action_url                           = \add_query_arg( 'action', self::CLEAR_ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_ACTION_NAME, \admin_url( 'admin-post.php?' ) );
			$action_url_powercloud_template       = \add_query_arg( 'action', self::CLEAR_ALLOWED_TEMPLATE_OPTIONS_POWERCLOUD_TRANSIENT_ACTION_NAME, \admin_url( 'admin-post.php?' ) );
			$action_url_powercloud_open_site_plan = \add_query_arg( 'action', self::CLEAR_OPEN_SITE_PLAN_OPTIONS_POWERCLOUD_TRANSIENT_ACTION_NAME, \admin_url( 'admin-post.php?' ) );

			$tab1_template_options      = [ '' => '請選擇' ] + Fetch::get_allowed_template_options();
			$tab2_template_options      = [ '' => '請選擇' ] + FetchPowerCloud::get_allowed_template_options();
			$tab_open_site_plan_options = [ '' => '請選擇' ] + FetchPowerCloud::get_open_site_plan_options();

			// Tab 1 內容 (舊架構 - wpcd)
			echo '<div class="power-partner-tab-content' . ( 'tab1' === $active_tab ? ' active' : '' ) . '" id="tab1-subscription">';
			echo '<input type="hidden" name="' . \esc_attr( self::HOST_TYPE_FIELD_NAME ) . '" value="wpcd" class="host-type-field" data-tab="tab1-subscription">';
			\woocommerce_wp_radio(
				[
					'id'            => self::HOST_POSITION_FIELD_NAME . '-tab1',
					'name'          => self::HOST_POSITION_FIELD_NAME,
					'label'         => '主機種類',
					'wrapper_class' => 'form-field [&_ul]:!flex [&_ul]:gap-x-4',
					'desc_tip'      => true,
					'description'   => '不同地區的主機，預設為日本',
					'options'       => $tab1_options,
					'value'         => ( isset( $tab1_options[ $host_position_value ] ) ) ? $host_position_value : '',
				]
			);
			\woocommerce_wp_select(
				[
					'id'                => self::LINKED_SITE_FIELD_NAME . '-tab1',
					'name'              => self::LINKED_SITE_FIELD_NAME,
					'label'             => '連結的網站 id',
					'wrapper_class'     => 'form-field',
					'desc_tip'          => false,
					'description'       => '如果想要更多模板站，請聯繫站長路可',
					'value'             => $linked_site_value,
					'options'           => $tab1_template_options,
					'custom_attributes' => ( 'tab1' === $active_tab ? [] : [ 'disabled' => 'disabled' ] ),
				]
			);
			\woocommerce_wp_note(
				[
					'label'         => '只有當站長幫你調整模板站後，才有需要清除快取，否則無須清除。',
					'wrapper_class' => 'form-field',
					'message'       => '<br /><a href="' . $action_url . '"><button type="button" class="button" style="height: 38px; margin-top: 2px;">清除快取</button></a>',
				]
			);
			echo '</div>';

			// Tab 2 內容 (新架構 - powercloud)
			echo '<div class="power-partner-tab-content' . ( 'tab2' === $active_tab ? ' active' : '' ) . '" id="tab2-subscription">';
			echo '<input type="hidden" name="' . \esc_attr( self::HOST_TYPE_FIELD_NAME ) . '" value="powercloud" class="host-type-field" data-tab="tab2-subscription">';
			\woocommerce_wp_radio(
				[
					'id'            => self::HOST_POSITION_FIELD_NAME . '-tab2',
					'name'          => self::HOST_POSITION_FIELD_NAME,
					'label'         => '主機種類',
					'wrapper_class' => 'form-field [&_ul]:!flex [&_ul]:gap-x-4',
					'desc_tip'      => true,
					'description'   => '不同地區的主機，預設為台灣',
					'options'       => $tab2_options,
					'value'         => ( isset( $tab2_options[ $host_position_value ] ) ) ? $host_position_value : 'tw',
				]
			);
			\woocommerce_wp_select(
				[
					'id'                => self::LINKED_SITE_FIELD_NAME . '-tab2',
					'name'              => self::LINKED_SITE_FIELD_NAME,
					'label'             => '連結的網站 id',
					'wrapper_class'     => 'form-field',
					'desc_tip'          => false,
					'description'       => '如果想要更多模板站，請聯繫站長路可',
					'value'             => $linked_site_value,
					'options'           => $tab2_template_options,
					'custom_attributes' => ( 'tab2' === $active_tab ? [] : [ 'disabled' => 'disabled' ] ),
				]
			);
			\woocommerce_wp_note(
				[
					'label'         => '只有當站長幫你調整模板站後，才有需要清除快取，否則無須清除。',
					'wrapper_class' => 'form-field',
					'message'       => '<br /><a href="' . $action_url_powercloud_template . '"><button type="button" class="button" style="height: 38px; margin-top: 2px;">清除快取</button></a>',
				]
			);
			\woocommerce_wp_select(
				[
					'id'                => self::OPEN_SITE_PLAN_FIELD_NAME,
					'label'             => '開站方案',
					'wrapper_class'     => 'form-field',
					'desc_tip'          => false,
					'description'       => '如果想要更多開站方案，請聯繫站長路可',
					'value'             => $open_site_plan_value,
					'options'           => $tab_open_site_plan_options,
					'custom_attributes' => ( 'tab2' === $active_tab ? [] : [ 'disabled' => 'disabled' ] ),
				]
			);
			\woocommerce_wp_note(
				[
					'label'         => '只有當站長幫你調整開站方案後，才有需要清除快取，否則無須清除。',
					'wrapper_class' => 'form-field',
					'message'       => '<br /><a href="' . $action_url_powercloud_open_site_plan . '"><button type="button" class="button" style="height: 38px; margin-top: 2px;">清除快取</button></a>',
				]
			);
			echo '</div>';

			echo '</div>'; // .power-partner-tabs-container
			echo '</div>'; // .power-partner-host-tabs-wrapper
		} else {
			\woocommerce_wp_note(
				[
					'label'         => '連結的網站 id',
					'wrapper_class' => 'form-field',
					'message'       => '<span style="font-size:1rem;">🚩 請先連結你在 https://cloud.luke.cafe/ 的帳號，可以前往 <a target="_blank" href="' . \admin_url( 'admin.php?page=power-partner' ) . '">Power Partner 分頁</a> 進行連結，才可以設定開站</span>',
				]
			);
		}

		echo '</div>';
	}

	/**
	 * Render linked site for subscription product
	 * 顯示連結的網站
	 *
	 * @param  int $product_id product id
	 *
	 * @return void
	 */
	public static function render_linked_site_subscription( $product_id ) {

		$linked_site_value = (string) \get_post_meta( $product_id, self::LINKED_SITE_FIELD_NAME, true );

		$action_url = \add_query_arg( 'action', self::CLEAR_ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_ACTION_NAME, \admin_url( 'admin-post.php?' ) );
		\woocommerce_wp_select(
			[
				'id'            => self::LINKED_SITE_FIELD_NAME,
				'label'         => '連結的網站 id',
				'wrapper_class' => 'form-field',
				'desc_tip'      => false,
				'description'   => '<a href="' . $action_url . '"><button type="button" class="button">清除快取</button></a>',
				'value'         => $linked_site_value,
				'options'       => [ '' => '請選擇' ] + Fetch::get_allowed_template_options(),
			]
		);

		echo '<p class="description" style="
		padding-left: 155px;
		top: -10px;
		position: relative;
		">如果想要更多模板站，請聯繫站長路可，只有當站長幫你調整模板站後，才有需要清除快取，否則無須清除。</p>';
	}

	/**
	 * Save for subscription
	 *
	 * @param int $product_id product id
	 * @return void
	 */
	public function save_subscription( $product_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST[ self::HOST_POSITION_FIELD_NAME ] ) ) {
			$host_position = \sanitize_text_field( (string) \wp_unslash( $_POST[ self::HOST_POSITION_FIELD_NAME ] ) );
			\update_post_meta( $product_id, self::HOST_POSITION_FIELD_NAME, $host_position );
		}

		// 保存 host_type
		if ( isset( $_POST[ self::HOST_TYPE_FIELD_NAME ] ) ) {
			$host_type = \sanitize_text_field( (string) \wp_unslash( $_POST[ self::HOST_TYPE_FIELD_NAME ] ) );
			if ( in_array( $host_type, [ 'wpcd', 'powercloud' ], true ) ) {
				\update_post_meta( $product_id, self::HOST_TYPE_FIELD_NAME, $host_type );
			}
		}

		if ( isset( $_POST[ self::LINKED_SITE_FIELD_NAME ] ) ) {
			$linked_site = \sanitize_text_field( (string) \wp_unslash( $_POST[ self::LINKED_SITE_FIELD_NAME ] ) );
			\update_post_meta( $product_id, self::LINKED_SITE_FIELD_NAME, $linked_site );
		}

		if ( isset( $_POST[ self::OPEN_SITE_PLAN_FIELD_NAME ] ) ) {
			$open_site_plan = \sanitize_text_field( (string) \wp_unslash( $_POST[ self::OPEN_SITE_PLAN_FIELD_NAME ] ) );
			\update_post_meta( $product_id, self::OPEN_SITE_PLAN_FIELD_NAME, $open_site_plan );
		}
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
	public function custom_field_variable_subscription( $loop, $variation_data, $variation ): void { // phpcs:ignore
		$variation_id        = $variation->ID;
		$host_position_value = (string) \get_post_meta( $variation_id, self::HOST_POSITION_FIELD_NAME, true );
		$host_position_value = empty( $host_position_value ) ? self::DEFAULT_HOST_POSITION : $host_position_value;

		// 取得 host_type，如果沒有則根據 host_position 判斷
		$host_type_value = (string) \get_post_meta( $variation_id, self::HOST_TYPE_FIELD_NAME, true );
		if ( empty( $host_type_value ) ) {
			// 如果沒有保存的 host_type，使用預設值
			$host_type_value = self::DEFAULT_HOST_TYPE;
		}

		$field_id           = self::HOST_POSITION_FIELD_NAME . '[' . $loop . ']';
		$host_type_field_id = self::HOST_TYPE_FIELD_NAME . '[' . $loop . ']';
		$tab_id             = 'host-position-tabs-' . $loop;

		// 定義兩個 tab 的選項
		$tab1_options = [
			'jp'        => '日本',
			'tw'        => '台灣',
			'us_west'   => '美西',
			'uk_london' => '英國倫敦',
			'sg'        => '新加坡',
			'hk'        => '香港',
			'canada'    => '加拿大',
		];

		$tab2_options = [
			'tw' => '台灣',
		];

		// 根據 host_type 判斷當前應該顯示哪個 tab
		// tab1 = 舊架構 (wpcd), tab2 = 新架構 (powercloud)
		$active_tab = ( 'wpcd' === $host_type_value ) ? 'tab1' : 'tab2';

		echo '<div class="hidden form-row form-row-full show_if_variable-subscription power-partner-host-tabs-wrapper" data-loop="' . \esc_attr( (string) $loop ) . '">';
		echo '<div class="power-partner-tabs-container">';

		// Tab 按鈕
		echo '<div class="power-partner-tab-buttons">';
		echo '<button type="button" class="power-partner-tab-button' . ( 'tab2' === $active_tab ? ' active' : '' ) . '" data-tab="tab2-' . \esc_attr( (string) $loop ) . '">新架構</button>';
		echo '<button type="button" class="power-partner-tab-button' . ( 'tab1' === $active_tab ? ' active' : '' ) . '" data-tab="tab1-' . \esc_attr( (string) $loop ) . '">舊架構</button>';
		echo '</div>';

		// 取得模板選項
		$linked_site_value                    = (string) \get_post_meta( $variation_id, self::LINKED_SITE_FIELD_NAME, true );
		$open_site_plan_value                 = (string) \get_post_meta( $variation_id, self::OPEN_SITE_PLAN_FIELD_NAME, true );
		$action_url                           = \add_query_arg( 'action', self::CLEAR_ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_ACTION_NAME, \admin_url( 'admin-post.php?' ) );
		$action_url_powercloud_template       = \add_query_arg( 'action', self::CLEAR_ALLOWED_TEMPLATE_OPTIONS_POWERCLOUD_TRANSIENT_ACTION_NAME, \admin_url( 'admin-post.php?' ) );
		$action_url_powercloud_open_site_plan = \add_query_arg( 'action', self::CLEAR_OPEN_SITE_PLAN_OPTIONS_POWERCLOUD_TRANSIENT_ACTION_NAME, \admin_url( 'admin-post.php?' ) );

		// 舊架構的模板選項
		$tab1_template_options = [ '' => '請選擇' ] + Fetch::get_allowed_template_options();

		// 新架構的模板選項
		$tab2_template_options = [ '' => '請選擇' ] + FetchPowerCloud::get_allowed_template_options();

		// 新架構開站方案選擇
		$tab_open_site_plan_options = [ '' => '請選擇' ] + FetchPowerCloud::get_open_site_plan_options();

		// Tab 1 內容 (舊架構 - wpcd)
		echo '<div class="power-partner-tab-content' . ( 'tab1' === $active_tab ? ' active' : '' ) . '" id="tab1-' . \esc_attr( (string) $loop ) . '">';
		// 隱藏的 host_type 欄位
		echo '<input type="hidden" name="' . \esc_attr( $host_type_field_id ) . '" value="wpcd" class="host-type-field" data-tab="tab1-' . \esc_attr( (string) $loop ) . '">';
		\woocommerce_wp_radio(
			[
				'id'            => $field_id . '-tab1',
				'name'          => $field_id,
				'label'         => '主機種類',
				'wrapper_class' => 'form-field [&_ul]:!flex [&_ul]:gap-x-4',
				'desc_tip'      => true,
				'description'   => '不同地區的主機，預設為日本',
				'options'       => $tab1_options,
				'value'         => ( isset( $tab1_options[ $host_position_value ] ) ) ? $host_position_value : '',
			]
		);

		// 模板選擇（舊架構）
		\woocommerce_wp_select(
			[
				'id'                => self::LINKED_SITE_FIELD_NAME . '[' . $loop . ']',
				'label'             => '連結的網站 id',
				'wrapper_class'     => 'form-field',
				'desc_tip'          => false,
				'description'       => '如果想要更多模板站，請聯繫站長路可',
				'value'             => $linked_site_value,
				'options'           => $tab1_template_options,
				'custom_attributes' => ( 'tab1' === $active_tab ? [] : [ 'disabled' => 'disabled' ] ),
			]
		);

		\woocommerce_wp_note(
			[
				'label'         => '只有當站長幫你調整模板站後，才有需要清除快取，否則無須清除。',
				'wrapper_class' => 'form-field',
				'message'       => '<br /><a href="' . $action_url . '"><button type="button" class="button" style="height: 38px; margin-top: 2px;">清除快取</button></a>',
			]
		);
		echo '</div>';

		// Tab 2 內容 (新架構 - powercloud)
		echo '<div class="power-partner-tab-content' . ( 'tab2' === $active_tab ? ' active' : '' ) . '" id="tab2-' . \esc_attr( (string) $loop ) . '">';
		// 隱藏的 host_type 欄位
		echo '<input type="hidden" name="' . \esc_attr( $host_type_field_id ) . '" value="powercloud" class="host-type-field" data-tab="tab2-' . \esc_attr( (string) $loop ) . '">';
		\woocommerce_wp_radio(
			[
				'id'            => $field_id . '-tab2',
				'name'          => $field_id,
				'label'         => '主機種類',
				'wrapper_class' => 'form-field [&_ul]:!flex [&_ul]:gap-x-4',
				'desc_tip'      => true,
				'description'   => '不同地區的主機，預設為台灣',
				'options'       => $tab2_options,
				'value'         => ( isset( $tab2_options[ $host_position_value ] ) ) ? $host_position_value : 'tw',
			]
		);

		// 模板選擇（新架構）
		\woocommerce_wp_select(
			[
				'id'                => self::LINKED_SITE_FIELD_NAME . '[' . $loop . ']',
				'label'             => '連結的網站 id',
				'wrapper_class'     => 'form-field',
				'desc_tip'          => false,
				'description'       => '如果想要更多模板站，請聯繫站長路可',
				'value'             => $linked_site_value,
				'options'           => $tab2_template_options,
				'custom_attributes' => ( 'tab2' === $active_tab ? [] : [ 'disabled' => 'disabled' ] ),
			]
		);

		\woocommerce_wp_note(
			[
				'label'         => '只有當站長幫你調整模板站後，才有需要清除快取，否則無須清除。',
				'wrapper_class' => 'form-field',
				'message'       => '<br /><a href="' . $action_url_powercloud_template . '"><button type="button" class="button" style="height: 38px; margin-top: 2px;">清除快取</button></a>',
			]
		);

		// 開站方案選擇
		\woocommerce_wp_select(
			[
				'id'                => self::OPEN_SITE_PLAN_FIELD_NAME . '[' . $loop . ']',
				'label'             => '開站方案',
				'wrapper_class'     => 'form-field',
				'desc_tip'          => false,
				'description'       => '如果想要更多開站方案，請聯繫站長路可',
				'value'             => $open_site_plan_value,
				'options'           => $tab_open_site_plan_options,
				'custom_attributes' => ( 'tab2' === $active_tab ? [] : [ 'disabled' => 'disabled' ] ),
			]
		);

		\woocommerce_wp_note(
			[
				'label'         => '只有當站長幫你調整開站方案後，才有需要清除快取，否則無須清除。',
				'wrapper_class' => 'form-field',
				'message'       => '<br /><a href="' . $action_url_powercloud_open_site_plan . '"><button type="button" class="button" style="height: 38px; margin-top: 2px;">清除快取</button></a>',
			]
		);

		echo '</div>';

		echo '</div>'; // .power-partner-tabs-container
		echo '</div>'; // .power-partner-host-tabs-wrapper
	}

	/**
	 * Render linked site for variable subscription product
	 * 顯示連結的網站
	 *
	 * @param  int $variation_id variation id
	 * @param  int $loop loop
	 *
	 * @return void
	 */
	public static function render_linked_site_variable_subscription( $variation_id, $loop ) {

		$linked_site_value = (string) \get_post_meta( $variation_id, self::LINKED_SITE_FIELD_NAME, true );

		$action_url = \add_query_arg( 'action', self::CLEAR_ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_ACTION_NAME, \admin_url( 'admin-post.php?' ) );

		\woocommerce_wp_select(
			[
				'id'            => self::LINKED_SITE_FIELD_NAME . '[' . $loop . ']',
				'label'         => '連結的網站 id',
				'wrapper_class' => 'form-field form-row show_if_variable-subscription hidden',
				'desc_tip'      => false,
				'description'   => '如果想要更多模板站，請聯繫站長路可',
				'value'         => $linked_site_value,
				'options'       => [ '' => '請選擇' ] + Fetch::get_allowed_template_options(),
			]
		);

		\woocommerce_wp_note(
			[
				'label'         => '只有當站長幫你調整模板站後，才有需要清除快取，否則無須清除。',
				'wrapper_class' => 'form-field form-row form-row-last show_if_variable-subscription hidden',
				'message'       => '<br /><a href="' . $action_url . '"><button type="button" class="button" style="height: 38px;
				margin-top: 2px;">清除快取</button></a>',
			]
		);
	}


	/**
	 * Save for variable subscription
	 *
	 * @param int $variation_id variation id
	 * @param int $loop loop
	 * @return void
	 */
	public function save_variable_subscription( $variation_id, $loop ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$host_positions_raw = isset( $_POST[ self::HOST_POSITION_FIELD_NAME ] ) ? \map_deep( \wp_unslash( $_POST[ self::HOST_POSITION_FIELD_NAME ] ), 'sanitize_text_field' ) : null;
		if ( is_array( $host_positions_raw ) && isset( $host_positions_raw[ $loop ] ) ) {
			\update_post_meta( $variation_id, self::HOST_POSITION_FIELD_NAME, (string) $host_positions_raw[ $loop ] );
		}

		// 保存 host_type
		$host_types_raw = isset( $_POST[ self::HOST_TYPE_FIELD_NAME ] ) ? \map_deep( \wp_unslash( $_POST[ self::HOST_TYPE_FIELD_NAME ] ), 'sanitize_text_field' ) : null;
		if ( is_array( $host_types_raw ) && isset( $host_types_raw[ $loop ] ) ) {
			$host_type = (string) $host_types_raw[ $loop ];
			// 驗證 host_type 值
			if ( in_array( $host_type, [ 'wpcd', 'powercloud' ], true ) ) {
				\update_post_meta( $variation_id, self::HOST_TYPE_FIELD_NAME, $host_type );
			}
		}

		$linked_sites_raw = isset( $_POST[ self::LINKED_SITE_FIELD_NAME ] ) ? \map_deep( \wp_unslash( $_POST[ self::LINKED_SITE_FIELD_NAME ] ), 'sanitize_text_field' ) : null;
		if ( is_array( $linked_sites_raw ) && isset( $linked_sites_raw[ $loop ] ) ) {
			\update_post_meta( $variation_id, self::LINKED_SITE_FIELD_NAME, (string) $linked_sites_raw[ $loop ] );
		}

		$open_site_plans_raw = isset( $_POST[ self::OPEN_SITE_PLAN_FIELD_NAME ] ) ? \map_deep( \wp_unslash( $_POST[ self::OPEN_SITE_PLAN_FIELD_NAME ] ), 'sanitize_text_field' ) : null;
		if ( is_array( $open_site_plans_raw ) && isset( $open_site_plans_raw[ $loop ] ) ) {
			\update_post_meta( $variation_id, self::OPEN_SITE_PLAN_FIELD_NAME, (string) $open_site_plans_raw[ $loop ] );
		}
	}



	/**
	 * Enqueue tab scripts and styles
	 *
	 * @param string $hook current page hook
	 * @return void
	 */
	public function enqueue_tab_scripts( $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		// 添加內聯 CSS - 使用 WooCommerce 原生樣式
		$css = '
		.power-partner-host-tabs-wrapper {
			clear: both;
			margin: 0 0 1em;
		}
		.power-partner-tabs-container {
			border: 1px solid #c3c4c7;
			border-radius: 4px;
			overflow: hidden;
			background: #fff;
		}
		.power-partner-tab-buttons {
			display: flex;
			border-bottom: 1px solid #c3c4c7;
			background: #f6f7f7;
			margin: 0;
		}
		.power-partner-tab-button {
			flex: 1;
			padding: 10px 15px;
			background: transparent;
			border: none;
			border-bottom: 2px solid transparent;
			border-right: 1px solid #c3c4c7;
			cursor: pointer;
			font-size: 13px;
			font-weight: 600;
			color: #50575e;
			transition: all 0.2s ease;
			margin: 0;
		}
		.power-partner-tab-button:last-child {
			border-right: none;
		}
		.power-partner-tab-button:hover {
			background: #f0f0f1;
			color: #2271b1;
		}
		.power-partner-tab-button.active {
			background: #fff;
			color: #2271b1;
			border-bottom-color: #2271b1;
		}
		.power-partner-tab-content {
			display: none;
			padding: 15px;
			background: #fff;
		}
		.power-partner-tab-content.active {
			display: block;
		}
		.power-partner-tab-content .form-field {
			margin: 0;
			padding: 0;
		}
		';

		\wp_add_inline_style( 'woocommerce_admin_styles', $css );

		// 添加內聯 JavaScript
		$js = "
		(function($) {
			$(document).ready(function() {
				// Tab 切換功能
				$(document).on('click', '.power-partner-tab-button', function(e) {
					e.preventDefault();
					var \$button = $(this);
					var tabId = \$button.data('tab');
					var \$wrapper = \$button.closest('.power-partner-tabs-container');

					// 更新按鈕狀態
					\$wrapper.find('.power-partner-tab-button').removeClass('active');
					\$button.addClass('active');

					// 更新內容顯示
					\$wrapper.find('.power-partner-tab-content').removeClass('active');
					\$wrapper.find('#' + tabId).addClass('active');

					// 更新隱藏的 host_type 欄位和 select 欄位
					\$wrapper.find('.host-type-field').prop('disabled', true);
					\$wrapper.find('select[name*=\"linked_site\"]').prop('disabled', true);
					\$wrapper.find('select[name*=\"open_site_plan\"]').prop('disabled', true);
					\$wrapper.find('#' + tabId + ' .host-type-field').prop('disabled', false);
					\$wrapper.find('#' + tabId + ' select[name*=\"linked_site\"]').prop('disabled', false);
					\$wrapper.find('#' + tabId + ' select[name*=\"open_site_plan\"]').prop('disabled', false);
				});

				// 當 radio 改變時，切換到對應的 tab 並確保同步
				$(document).on('change', '.power-partner-host-tabs-wrapper input[type=\"radio\"]', function() {
					var \$radio = $(this);
					var \$wrapper = \$radio.closest('.power-partner-host-tabs-wrapper');
					var \$tabContent = \$radio.closest('.power-partner-tab-content');
					var tabId = \$tabContent.attr('id');
					var \$button = \$wrapper.find('.power-partner-tab-button[data-tab=\"' + tabId + '\"]');
					var selectedValue = \$radio.val();
					var fieldName = \$radio.attr('name');

					// 確保所有相同 name 的 radio 中只有當前選中的被選中
					\$wrapper.find('input[type=\"radio\"][name=\"' + fieldName + '\"]').not(\$radio).prop('checked', false);

					// 切換到對應的 tab
					if (\$button.length) {
						\$wrapper.find('.power-partner-tab-button').removeClass('active');
						\$button.addClass('active');
						\$wrapper.find('.power-partner-tab-content').removeClass('active');
						\$tabContent.addClass('active');

						// 更新隱藏的 host_type 欄位和 select 欄位
						\$wrapper.find('.host-type-field').prop('disabled', true);
						\$wrapper.find('select[name*=\"linked_site\"]').prop('disabled', true);
						\$wrapper.find('select[name*=\"open_site_plan\"]').prop('disabled', true);
						\$tabContent.find('.host-type-field').prop('disabled', false);
						\$tabContent.find('select[name*=\"linked_site\"]').prop('disabled', false);
						\$tabContent.find('select[name*=\"open_site_plan\"]').prop('disabled', false);
					}
				});

				// 初始化時，只啟用當前 active tab 的 host_type 欄位和 select 欄位
				$('.power-partner-host-tabs-wrapper').each(function() {
					var \$wrapper = $(this);
					var \$activeTab = \$wrapper.find('.power-partner-tab-content.active');
					\$wrapper.find('.host-type-field').prop('disabled', true);
					\$wrapper.find('select[name*=\"linked_site\"]').prop('disabled', true);
					\$wrapper.find('select[name*=\"open_site_plan\"]').prop('disabled', true);
					\$activeTab.find('.host-type-field').prop('disabled', false);
					\$activeTab.find('select[name*=\"linked_site\"]').prop('disabled', false);
					\$activeTab.find('select[name*=\"open_site_plan\"]').prop('disabled', false);
				});
			});
		})(jQuery);
		";

		\wp_add_inline_script( 'jquery', $js );
	}

	/**
	 * Clear allowed template options transient callback
	 *
	 * @return void
	 */
	public function clear_allowed_template_options_transient_callback() {
		// 清除兩個架構的快取
		\delete_transient( Fetch::ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY );
		\delete_transient( FetchPowerCloud::ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY );
		\wp_safe_redirect( \admin_url( 'edit.php?post_type=product' ) );
		exit;
	}
}
