<?php

declare(strict_types=1);

namespace J7\PowerPartner\Domains\Site\Services;

use J7\PowerPartner\Plugin;
use J7\Powerhouse\Domains\AsSchedulerHandler\Shared\Base;
use J7\PowerPartner\Api\Fetch;
use J7\PowerPartner\ShopSubscription;
use J7\PowerPartner\Product\DataTabs\LinkedSites;
use J7\PowerPartner\Product\SiteSync;
use J7\PowerPartner\Api\FetchPowerCloud;

/**
 * 排程禁用網站
 */
final class DisableSiteScheduler extends Base {

	/** @var string 排程的 hook */
	protected static string $hook = 'power_partner/3.1.0/site/disable';

	/**
	 * Constructor，每次傳入的資源實例可能不同
	 *
	 * @param \WC_Subscription $item 訂閱
	 * @throws \Exception 如果 $item 不是 \WC_Subscription 實例
	 */
	public function __construct(
		/** @var \WC_Subscription 訂閱 */
		protected $item,
	) {
		parent::__construct( $item );
	}

	/**
	 * 取得排程的參數，執行時會傳入 action_callback
	 *
	 * @return array{subscription_id: int}
	 * */
	protected function get_args(): array {
		return [
			'subscription_id' => $this->item->get_id(),
		];
	}

	/**
	 * 取得排程的 callback
	 *
	 * @param array{subscription_id: int} $args 排程的參數
	 * @return void
	 */
	public static function action_callback( $args ): void {
		$subscription_id = $args['subscription_id'];

		if (!$subscription_id) {
			Plugin::logger( '找不到 subscription_id', 'error', $args );
			return;
		}

		$subscription = \wcs_get_subscription( $subscription_id );
		if (!$subscription) {
			Plugin::logger( "訂閱 #{$subscription_id} 不存在", 'error', [ 'subscription_id' => $subscription_id ] );
			return;
		}

		$linked_site_ids = ShopSubscription::get_linked_site_ids( $subscription_id );
		$order_id        = $subscription->get_parent_id();

		// 從訂閱的父訂單獲取產品資訊，取得 host_type
		$parent_order = $subscription->get_parent();
		if ( ! $parent_order instanceof \WC_Order ) {
			Plugin::logger( "訂閱 #{$subscription_id} 找不到父訂單", 'error' );
			return;
		}

		$current_user_id = $parent_order->get_customer_id();

		$items = $parent_order->get_items();
		foreach ( $items as $item ) {
			/** @var \WC_Order_Item_Product $item */
			$product_id = $item->get_variation_id() ?: $item->get_product_id();
			$product    = \wc_get_product( $product_id );

			if ( ! $product || ! \in_array( $product->get_type(), [ 'subscription', 'subscription_variation' ], true ) ) {
				continue;
			}

			// 產品 host_type 欄位（migration 前的舊產品可能為空）
			$product_host_type = (string) \get_post_meta( $product_id, LinkedSites::HOST_TYPE_FIELD_NAME, true );

			// 有 pp_linked_site_ids：逐站依實際架構停用
			// 架構由 LinkedSites::resolve_host_type 判斷（明確 host_type 優先，空值時以 id 格式推斷：數字=WPCD、其餘=PowerCloud）
			// 不再「空值一律 powercloud」，避免把舊 WPCD 站（數字 id）誤導去 PowerCloud API 而靜默失敗（issue #18）
			if (!empty($linked_site_ids)) {
				foreach ($linked_site_ids as $site_id) {
					$site_id = (string) $site_id;
					if ('' === $site_id) {
						continue;
					}

					$host_type = LinkedSites::resolve_host_type($product_host_type, $site_id);

					if (LinkedSites::WPCD_HOST_TYPE === $host_type) {
						// WPCD 舊架構（cloud.luke.cafe）
						$reason  = "停用網站，訂閱ID: {$subscription_id}，上層訂單號碼: {$order_id}，網站ID: {$site_id}";
						$success = Fetch::disable_site($site_id, $reason);
						$note    = $success
							? "已停用網站，訂閱ID: {$subscription_id}，上層訂單號碼: {$order_id}，網站ID: {$site_id}"
							: "停用網站失敗，訂閱ID: {$subscription_id}，上層訂單號碼: {$order_id}，網站ID: {$site_id}，請檢查 WPCD API 與 partner_id 設定";
					} else {
						// PowerCloud 新架構（api.wpsite.pro）
						$success = FetchPowerCloud::disable_site((string) $current_user_id, $site_id);
						$note    = $success
							? "已停用網站，訂閱ID: {$subscription_id}，上層訂單號碼: {$order_id}，websiteId: {$site_id}"
							: "停用網站失敗，訂閱ID: {$subscription_id}，上層訂單號碼: {$order_id}，websiteId: {$site_id}，請檢查 PowerCloud API";
					}

					$subscription->add_order_note($note);
					$subscription->save();
					Plugin::logger($note, $success ? 'info' : 'error');
				}
				continue;
			}

			// Fallback: 無 pp_linked_site_ids 時，從 order item meta 提取 PowerCloud websiteId（相容舊資料）
			$website_id = null;
			$order_item = $item->get_meta(SiteSync::CREATE_SITE_RESPONSES_ITEM_META_KEY);

			if (is_string($order_item) && !empty($order_item)) {
				$responses = json_decode($order_item, true);
				if (is_array($responses) && !empty($responses)) {
					$first_response = $responses[0];
					if (is_array($first_response) && isset($first_response['data']) && is_array($first_response['data']) && isset($first_response['data']['websiteId'])) {
						$website_id = (string) $first_response['data']['websiteId'];
					}
				}
			}

			if (empty($website_id)) {
				Plugin::logger(
					"訂閱 #{$subscription_id} 的訂單項目 #{$item->get_id()} 找不到 websiteId",
					'error',
					[
						'order_item' => $order_item,
						'item_id'    => $item->get_id(),
					]
				);
				continue;
			}

			$success = FetchPowerCloud::disable_site((string) $current_user_id, $website_id);
			if ($success) {
				$note = "已停用網站，訂閱ID: {$subscription_id}，上層訂單號碼: {$order_id}，websiteId: {$website_id}";
			} else {
				$note = "停用網站失敗，訂閱ID: {$subscription_id}，上層訂單號碼: {$order_id}，websiteId: {$website_id}，請檢查 PowerCloud API";
			}
			$subscription->add_order_note($note);
			$subscription->save();
			Plugin::logger($note, $success ? 'info' : 'error');
			continue;
		}
	}

	/**
	 * 排程後，寫入 log
	 *
	 * @param int|null $action_id 排程的 action_id
	 * @param int      $timestamp 排程的時間
	 * @param string   $group     排程的群組
	 * @return void
	 */
	public function after_schedule_single( int|null $action_id, int $timestamp, string $group ): void {
		$date = \wp_date( 'Y-m-d H:i', $timestamp );
		$this->item->add_order_note( $action_id ? "已排程停用網站，預計於 {$date} 停用網站，action_id: {$action_id}" : "排程停用網站失敗，action_id: {$action_id}" );
		Plugin::logger( "訂閱 #{$this->item->get_id()} 排程停用網站", 'info', [ 'action_id' => $action_id ] );
	}

	/**
	 * 取消排程後，寫入 log
	 *
	 * @param int    $action_id 排程的 action_id
	 * @param string $group     排程的群組
	 * @return void
	 */
	public function after_unschedule( int $action_id, string $group ): void {
		Plugin::logger( "訂閱 #{$this->item->get_id()} 成功，取消排程停用網站", 'info', [ 'action_id' => $action_id ] );
	}

	/**
	 * 取得排程的 hook
	 *
	 * @return string
	 */
	public static function get_hook(): string {
		return self::$hook;
	}
}
