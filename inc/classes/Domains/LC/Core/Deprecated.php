<?php

declare(strict_types=1);

namespace J7\PowerPartner\Domains\LC\Core;

use J7\PowerPartner\Domains\Cloud\Services\CloudClient as CloudApi;
use J7\WpUtils\Classes\General;

/**
 * 3.1.0 以前的排程
 *
 * Status:
 * active 已啟用
 * cancelled 已取消
 * expired 已過期
 * on-hold 保留
 * pending-cancel 待取消
 *
 * @deprecated 3.1.0 之後棄用
 */
final class Deprecated {
	use \J7\WpUtils\Traits\SingletonTrait;

	const EXPIRE_ACTION = 'power_partner_lc_expire';
	const DELAY_TIME    = 4 * HOUR_IN_SECONDS; // 延遲多久才執行


	/** Constructor */
	public function __construct() {
		\add_action(self::EXPIRE_ACTION, [ $this, 'process_expire_lcs' ], 10, 2);
	}

	/**
	 * 處理過期授權碼
	 *
	 * @param array<int, int> $lc_ids 授權碼 id
	 * @param int             $subscription_id 訂閱 id
	 * @return void
	 */
	public function process_expire_lcs( array $lc_ids, int $subscription_id ): void {
		$subscription = \wcs_get_subscription($subscription_id);
		if ( ! $subscription ) {
			return;
		}

		$action_id = $subscription->get_meta('power_partner_lc_expire_action_id');
		// 如果沒有 action_id 就不處理
		if (!$action_id) {
			return;
		}

		// 發API停用授權碼
		// 訂閱失敗，發API停用授權碼
		$api_instance = CloudApi::instance();
		$response     = $api_instance->remote_post(
			'license-codes/expire',
			[
				'ids' => $lc_ids,
			]
		);
		if (!CloudApi::is_success($response)) {
			LifeCycle::log_failure($subscription, '《過期》', $lc_ids, $response);
			return;
		}

		$body = \wp_remote_retrieve_body($response);
		$data = General::json_parse($body, []);

		$subscription->add_order_note("站長路可《過期》授權碼 成功: \n" . (string) \wp_json_encode($data, JSON_UNESCAPED_UNICODE));
	}
}
