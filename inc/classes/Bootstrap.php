<?php

declare (strict_types = 1);

namespace J7\PowerPartner;

use J7\PowerPartner\Utils\Base;
use J7\PowerPartner\Api\Fetch;
use J7\PowerPartner\Api\Connect;

use Kucrut\Vite;

/** Bootstrap */
final class Bootstrap {
	use \J7\WpUtils\Traits\SingletonTrait;

	public string $username       = ''; // phpcs:ignore
	public string $psw           = ''; // phpcs:ignore
	public string $base_url      = ''; // phpcs:ignore
	public string $powercloud_api = ''; // phpcs:ignore
	public string $t             = ''; // phpcs:ignore

	/** Constructor */
	public function __construct() {

		if ( 'local' === \wp_get_environment_type() ) {
			Test\Retry::instance();
		}

		Domains\Email\Core\SubscriptionEmailHooks::instance();
		Domains\Site\Core\DisableHooks::instance();
		// 環境變數，調整 api auth
		Base::set_api_auth( $this );

		Api\Main::instance();
		Api\Connect::instance();
		Api\User::instance();
		Order::instance();
		Product\DataTabs\LinkedSites::instance();
		Product\DataTabs\LinkedLC::instance();

		Product\SiteSync::instance();
		ShopSubscription::instance();
		Shortcode::instance();
		Domains\LC\Core\Api::instance();
		Domains\LC\Core\LifeCycle::instance();
		Domains\LC\Core\Deprecated::instance();
		Domains\Settings\Core\WatchSettingHooks::instance();
		Compatibility\Compatibility::instance();

		\add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_script' ], 99 );
		\add_action( 'wp_enqueue_scripts', [ $this, 'frontend_enqueue_script' ], 99 );

		// v2 to v3 欄位遷移  相容設定
		\add_action( 'admin_init', [ $this, 'compatibility_settings' ], 99 );
		\add_action( 'admin_notices', [ $this, 'notice' ] );
	}

	/**
	 * Admin Enqueue script
	 * You can load the script on demand
	 *
	 * @param string $hook current page hook
	 *
	 * @return void
	 */
	public function admin_enqueue_script( $hook ): void {
		if ( 'powerhouse_page_power-partner' !== $hook ) {
			return;
		}
		$this->enqueue_script();
	}

	/**
	 * Enqueue script
	 * You can load the script on demand
	 *
	 * @return void
	 */
	public function enqueue_script(): void {

		Vite\enqueue_asset(
			Plugin::$dir . '/js/dist',
			'js/src/main.tsx',
			[
				'handle'    => Plugin::$kebab,
				'in-footer' => true,
			]
		);

		$post_id                  = \get_the_ID();
		$permalink                = $post_id ? \get_permalink( $post_id ) : '';
		$allowed_template_options = Fetch::get_allowed_template_options();

		$power_partner_settings = (array) \get_option( 'power_partner_settings', [] );

		$powercloud_api_key = '';
		if ( \current_user_can( 'manage_options' ) ) {
			$stored_key         = \get_transient( Api\Main::POWERCLOUD_API_KEY_TRANSIENT_KEY );
			$powercloud_api_key = \is_string( $stored_key ) ? $stored_key : '';
		}

		\wp_localize_script(
			Plugin::$kebab,
			Plugin::$snake . '_data',
			[
				'env' => [
					'siteUrl'                   => \site_url(),
					'ajaxUrl'                   => \admin_url( 'admin-ajax.php' ),
					'userId'                    => \wp_get_current_user()->data->ID ?? null,
					'userEmail'                 => \wp_get_current_user()->user_email ?? '',
					'postId'                    => $post_id,
					'permalink'                 => $permalink,
					'APP_NAME'                  => Plugin::$app_name,
					'KEBAB'                     => Plugin::$kebab,
					'SNAKE'                     => Plugin::$snake,
					'BASE_URL'                  => Base::BASE_URL,
					'APP1_SELECTOR'             => Base::APP1_SELECTOR,
					'APP2_SELECTOR'             => Base::APP2_SELECTOR,
					'API_TIMEOUT'               => Base::API_TIMEOUT,
					'nonce'                     => \wp_create_nonce( Plugin::$kebab ),
					'allowed_template_options'  => $allowed_template_options,
					'partner_id'                => \get_option( Connect::PARTNER_ID_OPTION_NAME ),
					'disable_site_after_n_days' => (int) ( $power_partner_settings['power_partner_disable_site_after_n_days'] ?? '7' ),
					't'                         => $this->t,
					'cloudBaseUrl'              => $this->base_url,
					'POWERCLOUD_API'            => $this->powercloud_api,
					'powercloud_api_key'        => $powercloud_api_key,
					'is_kiwissec'               => strpos( \site_url(), 'kiwissec.io' ) !== false,
					'myAccountUrl'              => \get_permalink( \wc_get_page_id( 'myaccount' ) ),
				],
			]
		);

		\wp_localize_script(
			Plugin::$kebab,
			'wpApiSettings',
			[
				'root'  => \untrailingslashit( \esc_url_raw( rest_url() ) ),
				'nonce' => \wp_create_nonce( 'wp_rest' ),
			]
		);
	}

	/**
	 * Frontend Enqueue script
	 * You can load the script on demand
	 * 按需載入?
	 * 前台 shortcode 會用到，所以先不用按需載入
	 *
	 * @return void
	 */
	public function frontend_enqueue_script(): void {
		$this->enqueue_script();
	}

	/**
	 * 相容設定
	 * 將 v2 的設定遷移到 v3
	 *
	 * @deprecated v4 可以刪除
	 *
	 * @return void
	 */
	public function compatibility_settings(): void {
		$v2_settings                                = (array) \get_option( 'power_plugins_settings', [] );
		$v2_power_partner_disable_site_after_n_days = $v2_settings['power_partner_disable_site_after_n_days'] ?? '';
		$v2_emails                                  = \get_option( 'power_partner_emails', [] );

		if ( !$v2_power_partner_disable_site_after_n_days && !$v2_emails ) {
			return;
		}

		// update to v3
		\update_option(
			'power_partner_settings',
			[
				'power_partner_disable_site_after_n_days' => $v2_power_partner_disable_site_after_n_days,
				'emails'                                  => $v2_emails,
			]
			);

		// remove v2 option
		\delete_option( 'power_plugins_settings' );
		\delete_option( 'power_partner_emails' );
	}

	/**
	 * 通知
	 * 將 v2 的設定遷移到 v3
	 *
	 * @deprecated v4 可以刪除
	 *
	 * @return void
	 */
	public function notice(): void {
		if (class_exists('J7\WpToolkit\Plugin')) {
			echo '<div class="notice notice-info is-dismissible"><p>Power Partner v3.0.0 以上版本已經不再依賴 WP Toolkit，可以直接刪除 WP Toolkit</p></div>';
		}
	}
}
