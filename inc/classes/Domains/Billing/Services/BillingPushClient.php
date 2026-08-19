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
	 * 回傳值刻意不只是 bool：接收端以 HTTP 403 表示「cloud_user_id 與已綁定值不符」，
	 * 那是需要人工介入的身分綁定問題，重試三次也不會成功，呼叫端必須與一般失敗分流。
	 *
	 * @param array{partner_id: int, cloud_user_id: string, billing_date: string, sites: array<int, array{domain: string, status: string, dailyCost: float}>} $payload 推送內容
	 * @return array{success: bool, response_code: int, identity_mismatch: bool}
	 */
	public static function push( array $payload ): array {
		$body = \wp_json_encode( $payload );
		if ( false === $body ) {
			Plugin::logger( '每日計費推送失敗：wp_json_encode failed', 'error', [ 'billing_date' => $payload['billing_date'] ] );
			return self::result( false, 0 );
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
			return self::result( false, 0 );
		}

		$response_code = (int) \wp_remote_retrieve_response_code( $response );
		if ( $response_code < 200 || $response_code >= 300 ) {
			$response_body = (string) \wp_remote_retrieve_body( $response );

			Plugin::logger(
				'每日計費推送失敗（HTTP 非 2xx）',
				'error',
				[
					'url'           => $url,
					'billing_date'  => $payload['billing_date'],
					'response_code' => $response_code,
					'body'          => $response_body,
				]
			);
			return self::result( false, $response_code, self::is_identity_mismatch( $response_code, $response_body ) );
		}

		return self::result( true, $response_code );
	}

	/**
	 * 判斷回應是否為接收端的身分綁定不符（TOFU 比對失敗）
	 *
	 * 接收端只在這個情境回 403；仍一併比對訊息內容，避免把中介的 WAF／反向代理擋下的
	 * 403 誤判成綁定問題（誤判成綁定問題會導致該次失敗完全不重試）。
	 *
	 * @param int    $response_code HTTP status code
	 * @param string $response_body 回應 body
	 * @return bool
	 */
	private static function is_identity_mismatch( int $response_code, string $response_body ): bool {
		if ( 403 !== $response_code ) {
			return false;
		}

		$decoded = json_decode( $response_body, true );
		$message = '';
		if ( is_array( $decoded ) && isset( $decoded['message'] ) && is_scalar( $decoded['message'] ) ) {
			$message = (string) $decoded['message'];
		}

		return str_contains( $message, 'cloud_user_id' ) || str_contains( $message, '綁定' );
	}

	/**
	 * 組出統一的推送結果
	 *
	 * @param bool $success           是否推送成功（HTTP 2xx）
	 * @param int  $response_code     HTTP status code（連線錯誤時為 0）
	 * @param bool $identity_mismatch 是否為身分綁定不符
	 * @return array{success: bool, response_code: int, identity_mismatch: bool}
	 */
	private static function result( bool $success, int $response_code, bool $identity_mismatch = false ): array {
		return [
			'success'           => $success,
			'response_code'     => $response_code,
			'identity_mismatch' => $identity_mismatch,
		];
	}
}
