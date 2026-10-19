<?php

declare( strict_types=1 );

namespace J7\PowerPartner\Domains\LC\Core;

use J7\WpUtils\Classes\WP;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Classes\General;
use J7\PowerPartner\Domains\Cloud\Services\CloudClient as CloudApi;
use J7\Powerhouse\Domains\Subscription\Shared\Enums\Status;

/** Class Api */
final class Api extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string 命名空間 */
	protected $namespace = 'power-partner';

	/** @var array<int, array{endpoint: string, method: string, permission_callback?:callable}> APIs */
	protected $apis = [
		[
			'endpoint' => 'license-codes/update',
			'method'   => 'post',
		],
		[
			'endpoint' => 'license-codes',
			'method'   => 'delete',
		],
		[
			'endpoint'            => 'subscriptions/next-payment',
			'method'              => 'get',
			'permission_callback' => '__return_true',
		],
	];

	/**
	 * 更新 License Codes
	 * 可能會修改到訂閱綁定
	 *
	 * @param \WP_REST_Request $request 包含請求參數的 REST 請求對象。
	 * @return \WP_REST_Response 返回包含操作結果的 REST 響應對象。
	 * @throws \Exception 如果更新授權碼到站長路可失敗
	 * @phpstan-ignore-next-line
	 */
	public function post_license_codes_update_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$body_params = $request->get_json_params();

		/**
		 * @var array{ids: array<int, int>, post_status: string, domain?: string, product_slug?: string, post_author?:int, subscription_id?:int, customer_id?:int} $body_params
		 */
		$body_params = WP::sanitize_text_field_deep( $body_params );

		$body_params = $this->handle_bind_subscription($body_params);

		// 發給站長路可變更 LC
		$api_instance = CloudApi::instance();
		$response     = $api_instance->remote_post(
			'license-codes/update',
			$body_params
		);

		if ( \is_wp_error($response)) {
			throw new \Exception("更新授權碼到站長路可失敗，{$response->get_error_message()}");
		}

		$body = \wp_remote_retrieve_body($response);
		$data = General::json_parse($body, []);

		return new \WP_REST_Response(
			$data,
			200
			);
	}

	/**
	 * 處理連結授權碼到訂閱
	 *
	 * @param array{ids: array<int, int>, post_status: string, domain?: string, product_slug?: string, post_author?:int, subscription_id?:int, customer_id?:int, recover?:boolean} $body_params 參數
	 * @return array{ids: array<int, int>, post_status: string, domain?: string, product_slug?: string, post_author?:int, subscription_id?:int, customer_id?:int, recover?:boolean} 連結成功回傳 $body_params
	 * @throws \Exception 如果訂閱不存在
	 */
	public function handle_bind_subscription( array $body_params ): array {
		$lc_ids          = $body_params['ids'] ?? []; // @phpstan-ignore-line
		$subscription_id = $body_params['subscription_id'] ?? null;

		if (!$subscription_id) {
			// 沒有指定綁定訂閱，那就是設定 LC 使用時間，所以要移除 LC 的訂閱綁定
			foreach ($lc_ids as $lc_id) {
				// 這個 lc_id 之前可能綁定再其他訂閱，所以要先找到 有沒有關聯訂閱，有就先解綁
				$related_subscription_ids = $this->get_related_subscriptions( (int) $lc_id);
				foreach ($related_subscription_ids as $related_subscription_id) {
					$related_subscription = \wcs_get_subscription($related_subscription_id);
					if ($related_subscription) {
						// 只刪除 lc_id 相同的 meta data
						$related_subscription->delete_meta_data_value('lc_id', $lc_id);
						$related_subscription->save();
					}
				}
			}
			return $body_params;
		}

		$subscription = \wcs_get_subscription($subscription_id);
		if (!$subscription) {
			throw new \Exception("#{$subscription_id} 訂閱不存在");
		}

		foreach ($lc_ids as $lc_id) {
			// 這個 lc_id 之前可能綁定再其他訂閱，所以要先找到 有沒有關聯訂閱，有就先解綁
			$related_subscription_ids = $this->get_related_subscriptions( (int) $lc_id);
			foreach ($related_subscription_ids as $related_subscription_id) {
				$related_subscription = \wcs_get_subscription($related_subscription_id);
				if ($related_subscription) {
					// 只刪除 lc_id 相同的 meta data
					$related_subscription->delete_meta_data_value('lc_id', $lc_id);
					$related_subscription->save();
				}
			}
			// 只刪除 lc_id 相同的 meta data
			$subscription->delete_meta_data_value('lc_id', $lc_id);
			$subscription->add_meta_data('lc_id', (string) $lc_id);
			$subscription->save();
		}

		$subscription_status = $subscription->get_status();
		$is_success_status   = $subscription_status === Status::ACTIVE->value;
		// 原本 $body_params['post_status'] 是 follow_subscription
		$body_params['post_status'] = $is_success_status ? 'available' : 'expired';

		if ($is_success_status) {
			$body_params['recover'] = true;
		}

		return $body_params;
	}

	/**
	 * 找尋相關的訂閱
	 *
	 * @param int $lc_id license code id
	 * @return array<int, int>
	 */
	public function get_related_subscriptions( int $lc_id ): array {
		$args  = [
			'post_type'      => 'shop_subscription',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => 'lc_id',
			'meta_value'     => $lc_id,
		];
		$query = new \WP_Query($args);
		return array_map(static fn( $post ): int => $post instanceof \WP_Post ? $post->ID : (int) $post, $query->posts);
	}

	/**
	 * 刪除 License Codes
	 * 可能會修改到訂閱綁定
	 *
	 * @param \WP_REST_Request $request 包含請求參數的 REST 請求對象。
	 * @return \WP_REST_Response 返回包含操作結果的 REST 響應對象。
	 * @throws \Exception 如果刪除授權碼到站長路可失敗
	 * @phpstan-ignore-next-line
	 */
	public function delete_license_codes_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$body_params = $request->get_json_params();

		/**
		 * @var array{ids: array<int, int>, post_status: string, domain?: string, product_slug?: string, post_author?:int, subscription_id?:int, customer_id?:int} $body_params
		 */
		$body_params = WP::sanitize_text_field_deep( $body_params );
		$lc_ids      = $body_params['ids'];
		foreach ($lc_ids as $lc_id) {
			$related_subscription_ids = $this->get_related_subscriptions( (int) $lc_id);
			foreach ($related_subscription_ids as $related_subscription_id) {
				$related_subscription = \wcs_get_subscription($related_subscription_id);
				if ($related_subscription) {
					// 只刪除 lc_id 相同的 meta data
					$related_subscription->delete_meta_data_value('lc_id', $lc_id);
					$related_subscription->save();
				}
			}
		}

		// 發給站長路可變更 LC
		$api_instance = CloudApi::instance();
		$response     = $api_instance->remote_delete(
			'license-codes',
			$body_params
		);

		if (\is_wp_error($response)) {
			throw new \Exception("刪除授權碼到站長路可失敗，{$response->get_error_message()}");
		}

		$body = \wp_remote_retrieve_body($response);
		$data = General::json_parse($body, []);

		return new \WP_REST_Response(
			$data,
			200
			);
	}

	/**
	 * 取得訂閱下次付款日
	 *
	 * @param \WP_REST_Request $request 包含請求參數的 REST 請求對象。
	 *
	 * @return \WP_REST_Response|\WP_Error 返回包含操作結果的 REST 響應對象。
	 * @throws \Exception 如果訂閱 ID 無效或不存在
	 * @phpstan-ignore-next-line
	 */
	public function get_subscriptions_next_payment_callback( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params = $request->get_params();

		WP::include_required_params($params, [ 'ids' ]);

		$subscription_ids = $params['ids'];
		if (!is_array($subscription_ids)) {
			return new \WP_Error('invalid_subscription_ids', '訂閱 id 須為陣列');
		}

		$results = [];
		foreach ($subscription_ids as $subscription_id) {
			$subscription = \wcs_get_subscription($subscription_id);
			if ($subscription) {
				$results[] = [
					'id'   => $subscription_id,
					'time' => $subscription->get_time( 'next_payment' ),
				];
			}
		}

		return new \WP_REST_Response(
			$results,
			200
		);
	}
}
