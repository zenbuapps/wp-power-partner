<?php

declare(strict_types=1);

namespace J7\PowerPartner\Domains\LC\Core;

use J7\PowerPartner\Product\DataTabs\LinkedLC;
use J7\PowerPartner\Domains\Cloud\Services\CloudClient as CloudApi;
use J7\WpUtils\Classes\General;
use J7\PowerPartner\Api\Connect;
use J7\PowerPartner\Domains\LC\Services\ExpireHandler;
use J7\PowerPartner\Plugin;
use J7\Powerhouse\Domains\Subscription\Shared\Enums\Action;

/**
 * LC 生命週期
 *
 * Status:
 * active 已啟用
 * cancelled 已取消
 * expired 已過期
 * on-hold 保留
 * pending-cancel 待取消
 */
final class LifeCycle {
	use \J7\WpUtils\Traits\SingletonTrait;

	const DELAY_TIME = 4 * HOUR_IN_SECONDS; // 延遲多久才執行

	/** Constructor */
	public function __construct() {
		ExpireHandler::register();

		/** @category 訂閱首次付款成功後 */
		\add_action( Action::INITIAL_PAYMENT_COMPLETE->get_action_hook(), [ $this, 'create_lcs' ], 10, 2 );

		/** @category 訂閱從成功到失敗 */
		\add_action( Action::SUBSCRIPTION_FAILED->get_action_hook(), [ $this, 'subscription_failed' ], 10, 2 );

		/** @category 訂閱從失敗到成功 */
		\add_action( Action::SUBSCRIPTION_SUCCESS->get_action_hook(), [ $this, 'subscription_success' ], 10, 2 );
	}

	/**
	 * Subscription failed
	 * 如果用戶續訂失敗，則停用授權碼
	 *
	 * @param \WC_Subscription     $subscription 訂閱
	 * @param array<string, mixed> $args 參數
	 * @return void
	 */
	public function subscription_failed( \WC_Subscription $subscription, array $args ): void {
		$expire_handler = new ExpireHandler( $subscription );
		$expire_handler->maybe_unschedule('', true);
		$expire_handler->schedule_single( time() + self::DELAY_TIME );
	}




	/**
	 * Subscription success
	 * 如果手動調整訂閱成功，則讓授權碼變成可用
	 *
	 * @param \WC_Subscription     $subscription 訂閱
	 * @param array<string, mixed> $args 參數
	 * @return void
	 */
	public function subscription_success( \WC_Subscription $subscription, array $args ): void {
		$lc_ids = \get_post_meta($subscription->get_id(), 'lc_id', false);

		// 如果訂閱身上沒有授權碼 就不處理
		if ( ! $lc_ids || ! is_array($lc_ids) ) {
			return;
		}

		$expire_handler = new ExpireHandler( $subscription );
		$expire_handler->unschedule();

		// 沒有 action_id ，代表目前是[過期/停用]狀態，發API讓授權碼變成可用
		// 訂閱轉為成功，發API讓授權碼變成可用
		// ids 來自本站訂閱的 lc_id meta，作者不符時允許以 legacy 重送（見 CloudClient::LEGACY_ON_NOT_OWNER_ENDPOINTS）
		$api_instance = CloudApi::instance();
		$response     = $api_instance->remote_post(
			'license-codes/recover',
			[
				'ids' => $lc_ids,
			],
			[ 'legacy_on_not_owner' => true ]
		);
		if (!CloudApi::is_success($response)) {
			self::log_failure($subscription, '《重啟》', $lc_ids, $response);
			return;
		}

		$body = \wp_remote_retrieve_body($response);
		$data = General::json_parse($body, []);

		$subscription->add_order_note("站長路可《重啟》授權碼 ✅成功: \n" . \wp_json_encode($data, JSON_UNESCAPED_UNICODE));
	}

	/**
	 * 創建授權碼
	 *
	 * @param \WC_Subscription     $subscription subscription
	 * @param array<string, mixed> $args 參數
	 * @return void
	 */
	public function create_lcs( \WC_Subscription $subscription, $args ) {
		$partner_id = \get_option(Connect::PARTNER_ID_OPTION_NAME);
		if ( ! $partner_id ) {
			return;
		}

		/** @var \WC_Order $parent_order */
		$parent_order = $subscription->get_parent();

		/** @var array<int, array{product_slug:string, quantity:string}> $all_linked_lc_products */
		$all_linked_lc_products = [];

		/** @var array<int, \WC_Order_Item_Product> $items */
		$items = $parent_order->get_items();

		// 把訂單中每個商品的 LinkedLC 找出來拼成一個陣列
		foreach ($items as $item) {
			$product = $item->get_product();
			if (!( $product instanceof \WC_Product )) {
				continue;
			}
			$product_type = $product->get_type();

			if ( strpos( $product_type, 'variable' ) !== false ) {
				$variation_id       = $item->get_variation_id();
				$linked_lc_products = \get_post_meta($variation_id, LinkedLC::FIELD_NAME, true);
				if (!is_array($linked_lc_products)) {
					$linked_lc_products = [];
				}
				$all_linked_lc_products = $all_linked_lc_products + $linked_lc_products;
			} else {
				$product_id         = $product->get_id();
				$linked_lc_products = \get_post_meta($product_id, LinkedLC::FIELD_NAME, true);
				if (!is_array($linked_lc_products)) {
					$linked_lc_products = [];
				}
				$all_linked_lc_products = $all_linked_lc_products + $linked_lc_products;
			}
		}

		/**
		 * @var array<int, int> $all_lc_ids 創建的授權碼 id
		 */
		$all_lc_ids = [];

		// 打API給站長路可新增授權碼
		$api_instance = CloudApi::instance();

		foreach ($all_linked_lc_products as $linked_lc_product) {
			/** @var array<string, mixed> $params */
			$params                    = $linked_lc_product;
			$params['post_author']     = $partner_id;
			$params['subscription_id'] = $subscription->get_id();
			$params['customer_id']     = $subscription->get_customer_id();
			$response                  = $api_instance->remote_post(
				'license-codes',
				$params
			);

			// 非 2xx 也是失敗：回應裡沒有真的授權碼，不可把 id 寫進訂閱
			if (!CloudApi::is_success($response)) {
				self::log_failure($subscription, '《新增》', [], $response);
				continue;
			}

			$body = \wp_remote_retrieve_body($response);
			/** @var array<string, mixed> $data */
			$data = General::json_parse($body, []);

			$data_inner = is_array($data['data'] ?? null) ? $data['data'] : [];
			/**
			 * @var array<int, array{id: int, status: string, code: string, type: string, subscription_id: int, customer_id: int, expire_date: int, domain: string, product_slug: string, download_url: string, product_name: string}> $license_codes
			 */
			$license_codes = is_array($data_inner['license_codes'] ?? null) ? $data_inner['license_codes'] : [];
			if (!empty($license_codes)) {
				foreach ($license_codes as $license_code) {
					$all_lc_ids[] = $license_code['id'];
				}
			}

			$subscription->add_order_note("站長路可《新增》授權碼 ✅成功: \n" . \wp_json_encode($data, JSON_UNESCAPED_UNICODE));

			self::send_email_to_subscriber($subscription, $license_codes);
		}

		// 把資訊紀錄在 subscription
		$subscription->update_meta_data(LinkedLC::FIELD_NAME, $all_linked_lc_products);

		foreach ($all_lc_ids as $lc_id) {
			$subscription->add_meta_data('lc_id', (string) $lc_id);
		}
		$subscription->save();
	}

	/**
	 * 授權碼 API 失敗：寫訂單備註「❌失敗」+ error log
	 *
	 * @param \WC_Subscription               $subscription 訂閱
	 * @param string                         $action       動作名稱，例如「《重啟》」
	 * @param array<int|string, mixed>       $lc_ids       授權碼 ids（建立時為空）
	 * @param array<string, mixed>|\WP_Error $response     CloudServer 回應
	 * @return void
	 */
	public static function log_failure( \WC_Subscription $subscription, string $action, array $lc_ids, array|\WP_Error $response ): void {
		$message = CloudApi::failure_message($response);
		$subscription->add_order_note("站長路可{$action}授權碼 ❌失敗: \n{$message}");
		Plugin::logger(
			"站長路可{$action}授權碼失敗",
			'error',
			[
				'subscription_id' => $subscription->get_id(),
				'ids'             => $lc_ids,
				'error'           => $message,
			]
		);
	}

	/**
	 * 寄信給客戶
	 *
	 * phpcs:disable
	 * @param \WC_Subscription $subscription 訂閱
	 * @param array<int, array{id: int, status: string, code: string, type: string, subscription_id: int, customer_id: int, expire_date: int, domain: string, product_slug: string, download_url: string, product_name: string}> $license_codes 授權碼
	 * @return void
	 * phpcs:enable
	 */
	public static function send_email_to_subscriber( \WC_Subscription $subscription, array $license_codes ): void {
		$email = $subscription->get_billing_email();
		if ( ! $email ) {
			return;
		}

		$display_name = $subscription->get_billing_last_name() . $subscription->get_billing_first_name();

		$product_name = $license_codes[0]['product_name']; // 這批 license_codes 都是同樣產品

		$download_url = $license_codes[0]['download_url'];
		$doc_url      = match ($license_codes[0]['product_slug']) {
			'power-shop' => 'https://docs.wpsite.pro/powershop',
			'power-course' => 'https://docs.wpsite.pro/powercourse',
			default => '',
		};

		$subject = "您的《{$product_name}》授權碼已開通 - " . \get_bloginfo('name');

		$message  = "{$display_name} 您好:<br><br>";
		$date_created = $subscription->get_date_created();
		$date_str     = $date_created ? $date_created->date('Y-m-d') : '';
		$message     .= "您在 {$date_str} 訂購的授權碼 (訂閱編號 #{$subscription->get_id()}) 已經開通，以下是您的授權碼:<br><br>";

		$message .= "產品: {$product_name}<br><br>";
		if ($download_url) {
			$message .= "外掛下載連結: <a href='{$download_url}' target='_blank'>{$download_url}</a><br><br>";
		}
		if ($doc_url) {
			$message .= "外掛教學連結: <a href='{$doc_url}' target='_blank'>{$doc_url}</a><br><br>";
		}

		foreach ($license_codes as $license_code) {
			$message .= "授權碼: {$license_code['code']}<br>";
			$message .= '到期日: 跟隨訂閱<br>';
			$message .= '<br>';
		}

		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

		\wp_mail($email, $subject, $message, $headers);
	}
}
