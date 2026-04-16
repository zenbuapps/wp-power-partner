<?php
/**
 * Plugin Name:       Power Partner | 讓每個人都可以輕鬆地販售網站模板
 * Plugin URI:        https://cloud.luke.cafe/plugins/power-partner/
 * Description:       Power Partner 是一個 WordPress 套件，安裝後，可以讓你的 Woocommerce 商品與 cloud.luke.cafe 的模板網站連結，並且可以讓使用者自訂商品的價格，當用戶在您的網站下單後，會自動在 cloud.luke.cafe 創建網站，並且自動發送通知給用戶跟您。
 * Version:           3.3.0
 * Requires at least: 5.7
 * Requires PHP:      8.1
 * Author:            J7
 * Author URI:        https://github.com/j7-dev
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       power_partner
 * Domain Path:       /languages
 * Tags: WPCD
 */

declare (strict_types = 1);

namespace J7\PowerPartner;

if ( \class_exists( 'J7\PowerPartner\Plugin' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

/** Class Plugin */
final class Plugin {
	use \J7\WpUtils\Traits\PluginTrait;
	use \J7\WpUtils\Traits\SingletonTrait;

	const DEFAULT_EMAIL_BODY = '
	<p>嗨 ##FIRST_NAME##</p>
	<p>你的網站開好囉，<a href="https://docs.wpsite.pro/wp" target="_blank">點此可以打開網站的使用說明書</a></p>
	<p><br></p>
	<p>另外如果要將網站換成正式的網域，請<a href="https://docs.wpsite.pro/wp/wordpress-kuai-su-ru-men/jiang-zan-shi-wang-yu-huan-cheng-zheng-shi-wang-yu-rang-wang-zhan-zheng-shi-shang-xian" target="_blank">參考這篇教學</a></p>
	<p><br></p>
	<p>有網站的問題都可以或是私訊<a href="https://wpsite.pro" target="_blank">架站小幫手網站</a> 的右下角對話框</p>
	<p>&nbsp;</p>
	<p>--- 以下是你的網站資訊 ---</p>
	<p><br></p
	<p>網站暫時網址：##FRONTURL##</p>
	<p>之後可換成你自己的網址</p>
	<p><br></p>
	<p>網站後台：##ADMINURL##</p>
	<p><br></p>
	<p>帳號：##SITEUSERNAME##</p>
	<p><br></p>
	<p>密碼：##SITEPASSWORD##</p>
	<p><br></p>
	<p><strong>進去後請記得改成自己的密碼喔</strong></p>
	<p><br></p>
	<br>
	<p>網站主機ip：##IPV4##</p>
	<p>&nbsp;</p>
	<p>這封信很重要，不要刪掉，這樣之後才找得到喔</p><p>&nbsp;</p><p><br></p>';

	/** Constructor */
	public function __construct() {
		$this->required_plugins = [
			[
				'name'     => 'Powerhouse',
				'slug'     => 'powerhouse',
				'source'   => 'https://github.com/zenbuapps/wp-powerhouse/releases/latest/download/powerhouse.zip',
				'version'  => '3.3.23',
				'required' => true,
			],
			[
				'name'     => 'WooCommerce',
				'slug'     => 'woocommerce',
				'required' => true,
				'version'  => '7.6.0',
			],
			[
				'name'     => 'Woo Subscriptions',
				'slug'     => 'woocommerce-subscriptions',
				// 'source'   => '',
				'required' => true,
				'version'  => '5.9.0',
			],
		];

		$this->init(
			[
				'app_name'         => 'Power Partner',
				'github_repo'      => 'https://github.com/zenbuapps/wp-power-partner',
				'callback'         => [ Bootstrap::class, 'instance' ],
				'lc'               => false,
				'submenu_callback' => [ Admin\Menu\Setting::class, 'render_page' ],
			]
		);
	}

	/**
	 * Activate
	 *
	 * @return void
	 */
	public function activate(): void {
		\add_option(
			'power_partner_settings',
			[
				'power_partner_disable_site_after_n_days' => 7,
				'emails'                                  => [
					[
						'enabled'     => '1',
						'key'         => 'power_partner_default_site_sync',
						'action_name' => 'site_sync',
						'subject'     => '這裡填你的信件主旨 ##FIRST_NAME##',
						'body'        => self::DEFAULT_EMAIL_BODY,
						'days'        => '0',
						'operator'    => 'after',
					],
				],
			]
			);
	}

	/**
	 * 記錄 log
	 *
	 * @param string               $message 記錄訊息
	 * @param string               $level 記錄等級
	 * @param array<string, mixed> $args 記錄參數
	 * @param int                  $limit 記錄數量
	 * @return void
	 */
	public static function logger( $message = '', $level = 'info', $args = [], $limit = 0 ): void {
		\J7\WpUtils\Classes\WC::logger($message, $level, $args, 'power_partner', $limit );
	}
}

Plugin::instance();
