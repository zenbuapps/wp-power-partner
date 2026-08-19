<?php

declare(strict_types=1);

namespace J7\PowerPartner\Domains\Billing\Services;

use J7\PowerPartner\Bootstrap;
use J7\PowerPartner\Plugin;

/**
 * 新架構（PowerCloud）每日計費資料推送 client
 *
 * 對端契約見 power-partner-server/specs/api.yml 的 POST /v2/powercloud-daily-billing。
 * 認證沿用既有 Fetch::disable_site() 的 Basic Auth 組法，不另造機制。
 */
abstract class BillingPushClient {

	/** @var string 接收端 endpoint（相對於 CloudServer base_url） */
	const ENDPOINT = '/wp-json/power-partner-server/v2/powercloud-daily-billing';

	/**
	 * 推送每日計費資料至 CloudServer
	 *
	 * @param array{partner_id: int, cloud_user_id: string, billing_date: string, sites: array<int, array{domain: string, status: string, dailyCost: float}>} $payload 推送內容
	 * @return bool 是否推送成功（HTTP 2xx 才視為成功）
	 */
	public static function push( array $payload ): bool {
		$body = \wp_json_encode( $payload );
		if ( false === $body ) {
			Plugin::logger( '每日計費推送失敗：wp_json_encode failed', 'error', [ 'billing_date' => $payload['billing_date'] ] );
			return false;
		}

		$url  = Bootstrap::instance()->base_url . self::ENDPOINT;
		$args = [
			'method'  => 'POST',
			'body'    => $body,
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Basic ' . \base64_encode( Bootstrap::instance()->username . ':' . Bootstrap::instance()->psw ), // phpcs:ignore
			],
			'timeout' => 120,
		];

		$response = \wp_remote_post( $url, $args );

		if ( \is_wp_error( $response ) ) {
			Plugin::logger(
				"每日計費推送失敗（連線錯誤）: {$response->get_error_message()}",
				'error',
				[
					'url'          => $url,
					'billing_date' => $payload['billing_date'],
				]
			);
			return false;
		}

		$response_code = (int) \wp_remote_retrieve_response_code( $response );
		if ( $response_code < 200 || $response_code >= 300 ) {
			Plugin::logger(
				'每日計費推送失敗（HTTP 非 2xx）',
				'error',
				[
					'url'           => $url,
					'billing_date'  => $payload['billing_date'],
					'response_code' => $response_code,
					'body'          => \wp_remote_retrieve_body( $response ),
				]
			);
			return false;
		}

		return true;
	}
}
