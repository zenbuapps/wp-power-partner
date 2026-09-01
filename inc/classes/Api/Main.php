<?php

declare(strict_types=1);

namespace J7\PowerPartner\Api;

use J7\PowerPartner\Plugin;
use J7\PowerPartner\Api\Fetch;
use J7\PowerPartner\Domains\Email\Core\SubscriptionEmailHooks as EmailService;
use J7\PowerPartner\Product\SiteSync;
use J7\PowerPartner\ShopSubscription;
use J7\WpUtils\Classes\WP;

/**
 * Class Api
 */
final class Main
{
	use \J7\WpUtils\Traits\SingletonTrait;

	const POWERCLOUD_API_KEY_TRANSIENT_KEY = 'power_partner_powercloud_api_key';

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		\add_action('rest_api_init', [$this, 'register_apis']);
	}

	/**
	 * Register customer notification API
	 *
	 * @return void
	 */
	public function register_apis(): void
	{
		\register_rest_route(
			Plugin::$kebab,
			'customer-notification',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'post_customer_notification_callback'],
				'permission_callback' => [$this, 'check_ip_permission'],
			]
		);

		\register_rest_route(
			Plugin::$kebab,
			'link-site',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'post_link_site_callback'],
				'permission_callback' => [$this, 'check_ip_permission'],
			]
		);

		\register_rest_route(
			Plugin::$kebab,
			'manual-site-sync',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'manual_site_sync_callback'],
				'permission_callback' => function () {
					return \current_user_can('manage_options');
				},
			]
		);

		\register_rest_route(
			Plugin::$kebab,
			'clear-template-sites-cache',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'clear_template_sites_cache_callback'],
				'permission_callback' => function () {
					return \current_user_can('manage_options');
				},
			]
		);

		\register_rest_route(
			Plugin::$kebab,
			'send-site-credentials-email',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'send_site_credentials_email_callback'],
				'permission_callback' => function () {
					return \current_user_can('manage_options');
				},
			]
		);

		\register_rest_route(
			Plugin::$kebab,
			'emails',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'post_emails_callback'],
				'permission_callback' => function () {
					return \current_user_can('manage_options');
				},
			]
		);

		\register_rest_route(
			Plugin::$kebab,
			'emails',
			[
				'methods'             => 'GET',
				'callback'            => [$this, 'get_emails_callback'],
				'permission_callback' => function () {
					return \current_user_can('manage_options');
				},
			]
		);

		\register_rest_route(
			Plugin::$kebab,
			'subscriptions',
			[
				'methods'             => 'GET',
				'callback'            => [$this, 'get_subscriptions_callback'],
				'permission_callback' => function () {
					return \current_user_can('manage_options');
				},
			]
		);

		\register_rest_route(
			Plugin::$kebab,
			'change-subscription',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'post_change_subscription_callback'],
				'permission_callback' => function () {
					return \current_user_can('manage_options');
				},
			]
		);

		\register_rest_route(
			Plugin::$kebab,
			'unbind-site',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'post_unbind_site_callback'],
				'permission_callback' => function () {
					return \current_user_can('manage_options');
				},
			]
		);

		\register_rest_route(
			Plugin::$kebab,
			'apps',
			[
				'methods'             => 'GET',
				'callback'            => [$this, 'get_apps_callback'],
				'permission_callback' => '__return_true',
			]
		);

		\register_rest_route(
			Plugin::$kebab,
			'settings',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'post_settings_callback'],
				'permission_callback' => function () {
					return \current_user_can('manage_options');
				},
			]
		);

		\register_rest_route(
			Plugin::$kebab,
			'powercloud-api-key',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'post_powercloud_api_key_callback'],
				'permission_callback' => function () {
					return \current_user_can('manage_options');
				},
			]
		);

		\register_rest_route(
			Plugin::$kebab,
			'powercloud-api-key',
			[
				'methods'             => 'DELETE',
				'callback'            => [$this, 'delete_powercloud_api_key_callback'],
				'permission_callback' => function () {
					return \current_user_can('manage_options');
				},
			]
		);
	}

	/**
	 * Post customer notification callback
	 * 發 Email 通知客戶
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_customer_notification_callback($request)
	{
		try {
			$body_params = $request->get_json_params();
			$customer_id = $body_params['CUSTOMER_ID'] ?? '0';
			$customer    = \get_user_by('id', $customer_id);
			if (! $customer || empty($customer_id)) {
				return \rest_ensure_response(
					[
						'status'  => 500,
						'message' => 'missing customer id',
					]
				);
			}
			$order_id       = $body_params['REF_ORDER_ID'] ?? '0';
			$order          = \wc_get_order($order_id);
			$customer_email = $customer->user_email;

			/**
			 * Issue #23：站台網址一律先正規化，供「存進 pp_site_url」與「##URL## token」共用。
			 *
			 * 回調可能只給裸網域（無 scheme），直接使用會讓 <a href="##URL##"> 渲染成相對連結。
			 * 兩個用途必須共用同一個值，否則這一封開站信裡的 ##URL## 會與之後每一封
			 * 生命週期信（讀 pp_site_url）指向不同的字串。
			 */
			$callback_site_url = SiteSync::extract_site_url(
				[
					'url'    => (string) ( $body_params['FRONTURL'] ?? '' ),
					'domain' => (string) ( $body_params['DOMAIN'] ?? '' ),
				]
			);

			if ($order instanceof \WC_Order) {
				$customer_email = $order->get_billing_email();
				$subscriptions  = \wcs_get_subscriptions_for_order($order->get_id());
				$subscription   = \reset($subscriptions); // 取得第一個訂閱

				$new_site_id = $body_params['NEW_SITE_ID'] ?? null;
				if ($subscription && $new_site_id) {
					/**
					 * Issue #23：把 WPCD 的站台網址落地到訂閱上。
					 *
					 * WPCD 開站是非同步的，網域在開站當下不存在，只有這個回調帶得回來。
					 * 必須在 update_linked_site_ids() 之前寫入——後者會 fire
					 * pp_linked_site_ids_updated（issue #22 的補排 hook），
					 * 讓監聽者拿到的訂閱狀態是完整的。
					 *
					 * 只在尚未寫入時寫，語義與 PowerCloud 分支一致（第一個站先寫、之後不覆蓋）。
					 * $callback_site_url 已在函式開頭正規化過（與 ##URL## token 共用同一個值）。
					 */
					if ( $callback_site_url && '' === (string) $subscription->get_meta( SiteSync::SITE_URL_META_KEY, true ) ) {
						$subscription->update_meta_data( SiteSync::SITE_URL_META_KEY, $callback_site_url );
						$subscription->save();
					}

					/**
					 * ⚠️ 必須是「附加」而不是「覆寫」。
					 *
					 * update_linked_site_ids() 收到的陣列就是綁定的完整清單——
					 * 原本這裡傳 [(string) $new_site_id]，等於宣告「這個訂閱只有這一個站」。
					 * 一張訂單兩個商品各開一站是合法路徑，第二次回調會把第一個站的 id 擠掉：
					 * 站 1 從此不會被停用/恢復，而 pp_site_url 是「第一個站先寫、之後不覆蓋」，
					 * 於是 ##URL## 仍指向站 1、綁定卻只剩站 2，兩邊指到不同的站。
					 * 更糟的是 pp_linked_site_ids_updated 會 fire，把這次「靜默遺失」
					 * 當成一次正常的綁定變更通知出去。
					 *
					 * 另外三個寫入點（PowerCloud 開站、/link-site、後台手動編輯）都是附加語義，
					 * 這裡對齊它們；in_array 去重讓 CloudServer 重送同一個 site id 不會長出重複列。
					 */
					$existing_site_ids = ShopSubscription::get_linked_site_ids((int) $subscription->get_id());
					$merged_site_ids   = array_values($existing_site_ids);
					if (! in_array((string) $new_site_id, $merged_site_ids, true)) {
						$merged_site_ids[] = (string) $new_site_id;
					}

					ShopSubscription::update_linked_site_ids(
						(int) $subscription->get_id(),
						$merged_site_ids
					);
				}
			}

			/**
			 * 這些欄位原本是直接 $body_params['X'] 取值，沒有任何預設（issue #21）。
			 * CloudServer 少送任一欄就是 PHP 8 undefined array key warning + null，
			 * 而 SubscriptionEmailHooks::send_mail() 的關鍵變數防呆會因此中止寄送——
			 * 客戶會從「收到一封有佔位符的信」變成「一封都收不到」。
			 * 補 ?? '' 讓型別穩定，並在下方對缺漏留 error log（問題在 CloudServer 端，不是外掛壞掉）。
			 */
			$tokens                                   = [];
			$tokens['FIRST_NAME']                     = $customer->first_name;
			$tokens['LAST_NAME']                      = $customer->last_name;
			$tokens['NICE_NAME']                      = $customer->user_nicename;
			$tokens['EMAIL']                          = $customer_email;
			$tokens['WORDPRESSAPPWCSITESACCOUNTPAGE'] = $body_params['WORDPRESSAPPWCSITESACCOUNTPAGE'] ?? '';
			$tokens['IPV4']                           = $body_params['IPV4'] ?? '';
			$tokens['DOMAIN']                         = $body_params['DOMAIN'] ?? '';
			$tokens['FRONTURL']                       = $body_params['FRONTURL'] ?? '';
			$tokens['ADMINURL']                       = $body_params['ADMINURL'] ?? '';
			$tokens['SITEUSERNAME']                   = $body_params['SITEUSERNAME'] ?? '';
			$tokens['SITEPASSWORD']                   = $body_params['SITEPASSWORD'] ?? '';
			// issue #23：與 pp_site_url 共用同一個已補 scheme 的值，兩者不可分歧
			$tokens['URL']                            = $callback_site_url ?: ( $tokens['FRONTURL'] ?: $tokens['DOMAIN'] );

			// 回調 payload 不全時留痕：這是 CloudServer 端的問題，但後果會落在終端客戶身上（收不到開通信）
			$missing_params = [];
			foreach ( [ 'DOMAIN', 'FRONTURL', 'ADMINURL', 'SITEUSERNAME', 'SITEPASSWORD' ] as $required_param ) {
				if ( '' === trim( (string) $tokens[ $required_param ] ) ) {
					$missing_params[] = $required_param;
				}
			}
			if ( $missing_params ) {
				Plugin::logger(
					'/customer-notification 回調 payload 缺少站台欄位：' . implode( ', ', $missing_params ),
					'error',
					[
						'order_id'        => $order_id,
						'customer_id'     => $customer_id,
						// 不用區塊內的 $new_site_id——它只在 $order instanceof WC_Order 時才定義
						'new_site_id'     => $body_params['NEW_SITE_ID'] ?? null,
						'missing_params'  => $missing_params,
						'received_params' => array_keys( $body_params ),
					],
					5
				);
			}

			[$success_emails, $failed_emails] = EmailService::send_mail($customer_email, $tokens);

			return \rest_ensure_response(
				[
					'status'  => 200,
					'message' => 'post customer notification success',
					'data'    => [
						'to'             => $customer_email,
						'success_emails' => $success_emails,
						'failed_emails'  => $failed_emails,
					],
				]
			);
		} catch (\Throwable $th) {
			return \rest_ensure_response(
				[
					'status'  => 500,
					'message' => 'post customer notification fail: ' . $th->getMessage(),
				]
			);
		}
	}

	/**
	 * Post link site callback
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_link_site_callback($request)
	{
		/**
		 * Body params
		 *
		 * @param string $subscription_id
		 * @param string $site_id
		 */
		$body_params     = $request->get_json_params();
		$subscription_id = $body_params['subscription_id'] ?? '';
		$site_id         = $body_params['site_id'] ?? '';
		$linked_site_ids = ShopSubscription::get_linked_site_ids($subscription_id);
		array_push($linked_site_ids, $site_id);
		$update_success = ShopSubscription::update_linked_site_ids($subscription_id, $linked_site_ids);

		if ($update_success) {
			return new \WP_REST_Response(
				[
					'status'  => 200,
					'message' => 'post link site success',
					'data'    => 'subscription id: ' . $subscription_id . ' linked site ids: ' . \implode(',', $linked_site_ids),
				],
				200
			);
		} else {
			return new \WP_REST_Response(
				[
					'status'  => 500,
					'message' => 'post link site fail',
					'data'    => 'subscription id: ' . $subscription_id . ' linked site ids: ' . \implode(',', $linked_site_ids),
				],
				500
			);
		}
	}

	/**
	 * Get subscriptions callback
	 *
	 * @param \WP_REST_Request $request Request
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function get_subscriptions_callback($request): \WP_REST_Response
	{
		$params = $request->get_query_params();

		try {
			WP::include_required_params($params, ['user_id']);
			$user_id = $params['user_id'] ?? 0;

			$subscriptions = \get_posts(
				[
					'numberposts' => -1,
					'post_type'   => 'shop_subscription',
					'post_status' => ['wc-on-hold', 'wc-active', 'wc-pending', 'wc-expired', 'wc-pending-cancel'], // wc-on-hold wc-active
					'meta_key'    => '_customer_user',
					'meta_value'  => $user_id,
				]
			);

			$formatted_subscriptions = array_map(
				function ($subscription) {
					return [
						'id'              => (string) $subscription->ID,
						'status'          => $subscription->post_status,
						'post_title'      => $subscription->post_title,
						'post_date'       => $subscription->post_date,
						'linked_site_ids' => array_values(ShopSubscription::get_linked_site_ids($subscription->ID)),
					];
				},
				$subscriptions
			);

			$response = new \WP_REST_Response($formatted_subscriptions);

			// set pagination in header
			$response->header('X-WP-Total', (string) count($formatted_subscriptions));
			$response->header('X-WP-TotalPages', '1');

			return $response;
		} catch (\Throwable $th) {
			return new \WP_REST_Response(
				[
					'code'    => 'get_subscriptions_fail',
					'message' => $th->getMessage(),
				],
				500
			);
		}
	}

	/**
	 * Post change subscription callback
	 * 將網站綁定到指定的訂閱(還有上層訂單)上
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_change_subscription_callback($request)
	{
		try {
			$body_params     = $request->get_json_params();
			$subscription_id = $body_params['subscription_id'] ?? '';
			$site_id         = $body_params['site_id'] ?? '';
			$linked_site_ids = $body_params['linked_site_ids'] ?? [];
			$subscription    = \wcs_get_subscription($subscription_id);
			if (! $subscription || empty($subscription_id || empty($site_id))) {
				return \rest_ensure_response(
					[
						'status'  => 500,
						'message' => 'missing subscription id or site id',
					]
				);
			}

			$parent_order = $subscription->get_parent();
			if (! $parent_order) {
				return \rest_ensure_response(
					[
						'status'  => 500,
						'message' => 'subscription has no parent order',
					]
				);
			}

			if (! is_array($linked_site_ids)) {
				return \rest_ensure_response(
					[
						'status'  => 500,
						'message' => 'linked_site_ids is not array',
					]
				);
			}

			$is_success = ShopSubscription::change_linked_site_ids($subscription_id, $linked_site_ids);

			if ($is_success) {
				return \rest_ensure_response(
					[
						'status'  => 200,
						'message' => 'post change subscription success, subscription id: ' . $subscription_id . ' linked site ids: ' . \implode(',', $linked_site_ids),
					]
				);
			} else {
				return \rest_ensure_response(
					[
						'status'  => 500,
						'message' => 'post change subscription fail',
					]
				);
			}
		} catch (\Throwable $th) {

			return \rest_ensure_response(
				[
					'status'  => 500,
					'message' => 'post change subscription fail: ' . $th->getMessage(),
				]
			);
		}
	}

	/**
	 * Post unbind site callback
	 * 從所有訂閱中解除綁定指定的 site ID
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_unbind_site_callback($request): \WP_REST_Response
	{
		try {
			$body_params = $request->get_json_params();
			$site_id     = $body_params['site_id'] ?? '';

			if (empty($site_id)) {
				return new \WP_REST_Response(
					[
						'status'  => 400,
						'message' => 'missing site_id',
					],
					400
				);
			}

			$is_success = ShopSubscription::remove_linked_site_ids([(string) $site_id]);

			if ($is_success) {
				return new \WP_REST_Response(
					[
						'status'  => 200,
						'message' => "unbind site {$site_id} success",
					],
					200
				);
			} else {
				return new \WP_REST_Response(
					[
						'status'  => 500,
						'message' => "unbind site {$site_id} fail",
					],
					500
				);
			}
		} catch (\Throwable $th) {
			return new \WP_REST_Response(
				[
					'status'  => 500,
					'message' => 'unbind site fail: ' . $th->getMessage(),
				],
				500
			);
		}
	}

	/**
	 * Get apps callback
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_apps_callback($request): \WP_REST_Response
	{
		$params  = $request->get_query_params();
		$app_ids = $params['app_ids'] ?? [];

		$apps = [];

		foreach ($app_ids as $app_id) {
			$args = [
				'post_type'      => ShopSubscription::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'meta_key'       => SiteSync::LINKED_SITE_IDS_META_KEY,
				'meta_value'     => $app_id,
			];

			$subscription_ids = \get_posts($args);

			$apps[] = [
				'app_id'           => (string) $app_id,
				'subscription_ids' => $subscription_ids,
			];
		}

		return new \WP_REST_Response(
			$apps,
			200
		);
	}


	/**
	 * Get emails callback
	 *
	 * @return \WP_REST_Response
	 */
	public function get_emails_callback(): \WP_REST_Response
	{
		$power_partner_settings = \get_option('power_partner_settings', []);
		$power_partner_settings = is_array($power_partner_settings) ? $power_partner_settings : [];
		$emails                 = $power_partner_settings['emails'] ?? [];

		return new \WP_REST_Response(
			$emails,
			200
		);
	}

	/**
	 * 儲存 emails callback
	 *
	 * @deprecated
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_emails_callback($request): \WP_REST_Response
	{
		$body_params = $request->get_json_params();
		$emails      = $body_params['emails'] ?? null;

		$power_partner_settings = \get_option('power_partner_settings', []);
		$power_partner_settings = is_array($power_partner_settings) ? $power_partner_settings : [];
		if (is_array($emails)) {
			$power_partner_settings['emails'] = $emails;
			\update_option('power_partner_settings', $power_partner_settings);
			return new \WP_REST_Response(
				[
					'status'  => 200,
					'message' => 'save emails success',
				],
				200
			);
		} else {
			return new \WP_REST_Response(
				[
					'status'  => 500,
					'message' => 'save emails fail, emails is not array',
					'data'    => $emails,
				],
				500
			);
		}
	}

	/**
	 * 發送站點帳號密碼郵件
	 * 供前端手動開站後調用
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function send_site_credentials_email_callback($request): \WP_REST_Response
	{

		try {
			$body_params = $request->get_json_params();

			// 獲取當前用戶
			$current_user_id = \get_current_user_id();
			$current_user    = \get_user_by('id', $current_user_id);

			if (! $current_user) {
				return new \WP_REST_Response(
					[
						'status'  => 500,
						'message' => '找不到當前用戶',
					],
					500
				);
			}

			// 獲取郵件相關資訊
			$admin_email = $body_params['adminEmail'] ?? $current_user->user_email;
			$domain      = $body_params['domain'] ?? '';
			$front_url   = $body_params['frontUrl'] ?? "https://{$domain}";
			$admin_url   = $body_params['adminUrl'] ?? "https://{$domain}/wp-admin";
			$username    = $body_params['username'] ?? 'admin';
			$password    = $body_params['password'] ?? '';
			$ip          = $body_params['ip'] ?? '';

			if (empty($domain) || empty($password)) {
				return new \WP_REST_Response(
					[
						'status'  => 400,
						'message' => '缺少必要參數：domain 或 password',
					],
					400
				);
			}

			// 準備 Token
			$tokens                 = [];
			$tokens['FIRST_NAME']   = $current_user->first_name ?: '網站使用者';
			$tokens['LAST_NAME']    = $current_user->last_name ?: '';
			$tokens['NICE_NAME']    = $current_user->user_nicename ?: '';
			$tokens['EMAIL']        = $admin_email;
			$tokens['DOMAIN']       = $domain;
			$tokens['FRONTURL']     = $front_url;
			$tokens['ADMINURL']     = $admin_url;
			$tokens['SITEUSERNAME'] = $username;
			$tokens['SITEPASSWORD'] = $password;
			$tokens['IPV4']         = $ip;
			$tokens['URL']          = $front_url; // issue #23：與前端 siteSyncTokens 的合約一致

			// 取得 site_sync 的 email 模板
			$email_service = EmailService::instance();
			$emails        = $email_service->get_emails('site_sync');

			if (empty($emails)) {
				return new \WP_REST_Response(
					[
						'status'  => 404,
						'message' => '找不到郵件模板，請先設定 action_name 為 site_sync 的郵件模板',
					],
					404
				);
			}

			/**
			 * 一律走 EmailService::send_mail()，不要在這裡自己 replace + wp_mail。
			 *
			 * 原本這裡是 send_mail() 的複製品，導致兩個實際後果：
			 *   1. 缺少 ##URL##（前端 siteSyncTokens 有列，此路徑卻不提供）
			 *   2. 繞過 issue #21 的關鍵站台變數防呆，可能把 ##XXX## 寄給客戶
			 */
			[ $success_emails, $failed_emails ] = EmailService::send_mail($admin_email, $tokens);

			return new \WP_REST_Response(
				[
					'status'  => 200,
					'message' => '郵件發送完成',
					'data'    => [
						'to'             => $admin_email,
						'success_emails' => $success_emails,
						'failed_emails'  => $failed_emails,
					],
				],
				200
			);
		} catch (\Throwable $th) {
			return new \WP_REST_Response(
				[
					'status'  => 500,
					'message' => '郵件發送失敗: ' . $th->getMessage(),
				],
				500
			);
		}
	}

	/**
	 * 更新設定
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_settings_callback($request): \WP_REST_Response
	{
		$body_params = $request->get_json_params();
		$body_params = WP::sanitize_text_field_deep($body_params, true, ['emails']);

		\update_option('power_partner_settings', $body_params);

		return new \WP_REST_Response(
			[
				'status'  => 200,
				'message' => 'update settings success',
				'data'    => $body_params,
			],
			200
		);
	}

	/**
	 * Manual site sync callback
	 * 手動開站
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function manual_site_sync_callback($request)
	{
		try {

			$body_params   = $request->get_json_params();
			$site_id       = (string) ($body_params['site_id'] ?? '');
			$host_position = (string) ($body_params['host_position'] ?? '');
			$partner_id    = (string) \get_option(Plugin::$snake . '_partner_id', '0');
			$customer_id   = \get_current_user_id();
			$customer      = \get_user_by('id', $customer_id);

			$response_obj = Fetch::site_sync(
				[
					'site_url'      => \site_url(),
					'site_id'       => $site_id,
					'host_position' => $host_position,
					'partner_id'    => $partner_id,
					'customer'      => [
						'id'         => $customer_id,
						'first_name' => $customer ? $customer->first_name : 'admin',
						'last_name'  => $customer ? $customer->last_name : '',
						'username'   => $customer ? $customer->user_login : 'admin',
						'email'      => $customer ? $customer->user_email : '',
						'phone'      => $customer ? (string) \get_user_meta($customer_id, 'billing_phone', true) : '',
					],
				]
			);

			return new \WP_REST_Response(
				[
					'status'  => $response_obj->status,
					'message' => $response_obj->message,
					'data'    => $response_obj->data,
				],
				200
			);
		} catch (\Throwable $th) {
			Plugin::logger(
				"手動開站建立網站失敗: {$th->getMessage()}",
				'error',
				[
					'params' => $request->get_params(),
				],
				5
			);

			return new \WP_REST_Response(
				[
					'status'  => 500,
					'message' => '手動開站建立網站失敗: ' . $th->getMessage(),
				],
				500
			);
		}
	}

	/**
	 * Clear template sites cache callback
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function clear_template_sites_cache_callback($request)
	{

		\delete_transient(Fetch::ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY);

		return new \WP_REST_Response(
			[
				'status'  => 200,
				'message' => 'clear template sites cache success',
			],
			200
		);
	}

	/**
	 * Check IP Permission
	 *
	 * @return bool
	 */
	public function check_ip_permission(): bool
	{
		if ('local' === \wp_get_environment_type() || 'staging' === \wp_get_environment_type()) {
			return true;
		}
		// 103.153.176.121 = 黃亦主機對外  199.99.88.1 = 黃亦主機打黃亦主機
		// 163.61.60.80 = 是方主機對外  是方主機打是方主機
		$fixed_ips = ['103.153.176.121', '199.99.88.1', '163.61.60.80'];

		// phpcs:disable
		if (in_array($_SERVER['REMOTE_ADDR'], $fixed_ips, true)) {
			return true;
		}

		// 內網
		if ($this->in_ip('10.0.0.0', '10.255.255.255')) {
			return true;
		}

		// 內網
		if ($this->in_ip('172.16.0.0', '172.31.255.255')) {
			return true;
		}

		// 內網
		if ($this->in_ip('192.168.0.0', '192.168.255.255')) {
			return true;
		}

		// 以前的版本
		return $this->in_ip('61.220.44.0', '61.220.44.10');
	}


	/**
	 * 檢查 REMOTE_ADDR IP 是否在指定範圍內
	 *
	 * @param string $from_ip 起始 IP
	 * @param string $to_ip 結束 IP
	 *
	 * @return bool
	 */
	private function in_ip(string $from_ip, string $to_ip): bool
	{
		// phpcs:ignore
		$request_ip_long = sprintf('%u', ip2long((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')));
		// 將起始和結束 IP 轉換為長整型
		$from_ip_long = sprintf('%u', ip2long($from_ip));
		$to_ip_long   = sprintf('%u', ip2long($to_ip));

		// 檢查發起請求的 IP 是否在允許的範圍內
		return ($request_ip_long >= $from_ip_long && $request_ip_long <= $to_ip_long);
	}


	/**
	 * Post powercloud API key callback
	 * Save PowerCloud API Key to transient (save to MySQL wp_options table)
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function post_powercloud_api_key_callback($request): \WP_REST_Response
	{
		$body_params = $request->get_json_params();
		$api_key     = \sanitize_text_field($body_params['api_key'] ?? '');

		if (empty($api_key)) {
			return new \WP_REST_Response(
				[
					'status'  => 400,
					'message' => 'api_key is required',
				],
				400
			);
		}

		// Get current logged in user ID
		$user_id = \get_current_user_id();
		if (! $user_id) {
			return new \WP_REST_Response(
				[
					'status'  => 401,
					'message' => 'User not authenticated',
				],
				401
			);
		}

		// Use transient to save (save to MySQL wp_options table)
		\set_transient(Main::POWERCLOUD_API_KEY_TRANSIENT_KEY, $api_key);


		return new \WP_REST_Response(
			[
				'status'  => 200,
				'message' => 'update powercloud api key success',
			],
			200
		);
	}

	/**
	 * Delete powercloud API key callback
	 * 清除全域 PowerCloud API Key transient
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function delete_powercloud_api_key_callback($request): \WP_REST_Response
	{
		\delete_transient(Main::POWERCLOUD_API_KEY_TRANSIENT_KEY);

		return new \WP_REST_Response(
			[
				'status'  => 200,
				'message' => 'delete powercloud api key success',
			],
			200
		);
	}
}
