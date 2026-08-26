<?php

declare (strict_types = 1);

namespace J7\PowerPartner\Utils;

use J7\PowerPartner\Product\SiteSync;
use J7\PowerPartner\Plugin;
use J7\PowerPartner\Domains\Subscription\Utils\Base as SubscriptionUtils;

/** Class Token */
abstract class Token {


	/**
	 * Replaces placeholder tokens in a script.
	 *
	 * A script is usually a server provisioning startup script
	 * Tokens are of the format ##TOKEN## and it is expected that
	 * the 'TOKEN' is uppercase.
	 *
	 * As of Version 4.2.5 of WPCD, this function also handles
	 * replacing similar tokens in EMAIL templates.
	 *
	 * @param string               $script The full text of the script contents.
	 * @param array<string, mixed> $tokens Key-value array of tokens to replace.
	 *
	 * @return string The updated script contents
	 */
	public static function replace( $script, $tokens ) {
		$updated_script = $script;

		foreach ( $tokens as $name => $value ) {
			if ( is_array( $value ) || empty( $value ) ) {
				continue;
			}

			$updated_script = str_replace( '##' . strtoupper( $name ) . '##', (string) $value, $updated_script );
		}

		return $updated_script;
	}

	/**
	 * Get order tokens
	 *
	 * @param \WC_Order $order Order
	 * @return array<string, mixed>
	 */
	public static function get_order_tokens( \WC_Order $order ): array {
		$customer = $order->get_user();

		$products = [];
		foreach ( $order->get_items() as $item_id => $item ) {
			$product_name = $item->get_name();
			$products[]   = $product_name;
		}
		$products_text = implode( ', ', $products );

		$tokens                         = [];
		$tokens['FIRST_NAME']           = $customer ? $customer->first_name : '';
		$tokens['LAST_NAME']            = $customer ? $customer->last_name : '';
		$tokens['NICE_NAME']            = $customer ? $customer->user_nicename : '';
		$tokens['EMAIL']                = $customer ? $customer->user_email : '';
		$tokens['ORDER_ID']             = $order->get_id();
		$tokens['ORDER_ITEMS']          = $products_text;
		$tokens['CHECKOUT_PAYMENT_URL'] = $order->get_checkout_payment_url();
		$tokens['VIEW_ORDER_URL']       = $order->get_view_order_url();
		$tokens['ORDER_STATUS']         = $order->get_status();
		$date_created                   = $order->get_date_created();
		$tokens['ORDER_DATE']           = $date_created ? $date_created->format( 'Y-m-d' ) : '';

		return $tokens;
	}

	/**
	 * Get subscription tokens
	 *
	 * @param \WC_Subscription $subscription Subscription
	 * @return array<string, mixed>
	 */
	public static function get_subscription_tokens( \WC_Subscription $subscription ): array {

		$tokens = [];

		/**
		 * Issue #23：##URL## 一律以訂閱上的 pp_site_url 為準。
		 *
		 * 這個 meta 由開站流程的兩條路徑寫入（PowerCloud 開站成功時、WPCD 的
		 * /customer-notification 回調時），是兩種架構共用、結構穩定的單一來源。
		 */
		$site_url = (string) $subscription->get_meta( SiteSync::SITE_URL_META_KEY, true );

		$order = $subscription->get_parent();

		/**
		 * 舊資料 fallback：3.5.1 以前沒有 pp_site_url，只能從父訂單的開站回應撈。
		 *
		 * 注意這條路對歷史資料多半撈不到值——PowerCloud 的回應 data 只有 websiteId、
		 * WPCD 的 data 結構未經驗證，兩者都沒有網域欄位。保留它是為了涵蓋
		 * 「對端未來開始回傳 url」與「WPCD 回應本來就帶網域」的情形。
		 *
		 * 讀法統一走 SiteSync::get_first_site_response_data()——這份 meta 的實際結構是
		 * list（[{status,message,data}]），原本這裡讀成 $arr['data'] 少了一層 [0]，必然取不到值。
		 */
		if ( '' === $site_url && $order instanceof \WC_Order ) {
			$site_url = SiteSync::extract_site_url( SiteSync::get_first_site_response_data( $order ) );
		}

		if ( '' !== $site_url ) {
			$tokens['URL'] = $site_url;

			return $tokens;
		}

		/**
		 * 已綁站卻取不到網址：這是真正的資料缺口，留 error log。
		 * 不中止寄送——##URL## 對所有非開站模板都可用，但沒有一個模板「必須」有它，
		 * 以缺 URL 擋掉一封催繳信，客戶會連催繳通知都收不到，比看到佔位符嚴重得多。
		 */
		if ( SubscriptionUtils::is_site_sync( $subscription ) ) {
			Plugin::logger(
				"訂閱 #{$subscription->get_id()} 已綁定站台卻取不到站台網址，##URL## 將以字面佔位符外顯",
				'error',
				[
					'subscription_id' => $subscription->get_id(),
					'parent_order_id' => $order instanceof \WC_Order ? $order->get_id() : null,
					'linked_site_ids' => $subscription->get_meta( SiteSync::LINKED_SITE_IDS_META_KEY, true ),
				]
			);
		}

		return $tokens;
	}
}
