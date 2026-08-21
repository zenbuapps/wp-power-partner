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
	 * 永久性錯誤代碼：重試三次也不會成功，呼叫端須立即通知管理員而非進重試流程
	 *
	 * 接收端在回應 body 的 `data.error_code` 帶穩定代碼。
	 *
	 * **不得改回比對 message 文案。** 原本的實作比對「cloud_user_id」字串，接收端隨 dealer_id
	 * 改名把文案換成「dealer_id 與已綁定值不符」之後就整條失效 —— 文案不是契約的一部分，
	 * 改一次字就會靜默壞掉，而壞掉的後果是永久性錯誤被當成暫時性失敗白重試 90 分鐘才通知人。
	 *
	 * @var array<int, string>
	 */
	const PERMANENT_ERROR_CODES = [
		'identity_mismatch',
		'partner_not_found',
		'not_a_dealer',
		'invalid_billing_date',
		'missing_field',
		'invalid_field',
	];

	/**
	 * 推送每日計費資料至 CloudServer
	 *
	 * 回傳值刻意不只是 bool：接收端以 `data.error_code` 標示永久性錯誤（身分綁定不符、
	 * partner 不存在、非經銷商…），那些重試三次也不會成功，呼叫端必須與一般失敗分流。
	 *
	 * @param array{partner_id: int, dealer_id: string, billing_date: string, sites: array<int, array{domain: string, status: string, dailyCost: float}>} $payload 推送內容
	 * @return array{success: bool, response_code: int, error_code: string, message: string, identity_mismatch: bool, permanent: bool}
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
			$error         = self::resolve_error( $response_body );

			Plugin::logger(
				'每日計費推送失敗（HTTP 非 2xx）',
				'error',
				[
					'url'           => $url,
					'billing_date'  => $payload['billing_date'],
					'response_code' => $response_code,
					'error_code'    => $error['error_code'],
					'body'          => $response_body,
				]
			);
			return self::result( false, $response_code, $error['error_code'], $error['message'] );
		}

		return self::result( true, $response_code );
	}

	/**
	 * 取出接收端回應的穩定錯誤代碼（`data.error_code`）與人類可讀訊息（`message`）
	 *
	 * 代碼取不到時回空字串 —— 呼叫端據此維持既有的「一般失敗，進重試流程」行為。
	 * 中介的 WAF／反向代理擋下的 403 不會帶這個 JSON 結構，因此不會被誤判成永久性錯誤
	 * （誤判會導致該次失敗完全不重試）。
	 *
	 * `message` **僅供顯示**（寫進通知信讓管理員知道是哪個參數出問題），
	 * 一律不得拿來做流程判斷 —— 那正是本次被 dealer_id 改名打掉的舊做法。
	 *
	 * @param string $response_body 回應 body
	 * @return array{error_code: string, message: string}
	 */
	private static function resolve_error( string $response_body ): array {
		$decoded = json_decode( $response_body, true );
		if ( ! is_array( $decoded ) ) {
			return [
				'error_code' => '',
				'message'    => '',
			];
		}

		$error_code = '';
		if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
			$raw        = $decoded['data']['error_code'] ?? null;
			$error_code = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		}

		$raw_message = $decoded['message'] ?? null;

		return [
			'error_code' => $error_code,
			'message'    => is_scalar( $raw_message ) ? trim( (string) $raw_message ) : '',
		];
	}

	/**
	 * 組出統一的推送結果
	 *
	 * `identity_mismatch` 刻意要求「HTTP 403 且 error_code 為 identity_mismatch」兩者皆成立；
	 * 其餘永久性錯誤只認 error_code，呼叫端一律通知管理員且不重試。
	 *
	 * @param bool   $success       是否推送成功（HTTP 2xx）
	 * @param int    $response_code HTTP status code（連線錯誤時為 0）
	 * @param string $error_code    接收端回報的 data.error_code（取不到為空字串）
	 * @param string $message       接收端回報的 message，**僅供顯示**，不得用於流程判斷
	 * @return array{success: bool, response_code: int, error_code: string, message: string, identity_mismatch: bool, permanent: bool}
	 */
	private static function result( bool $success, int $response_code, string $error_code = '', string $message = '' ): array {
		return [
			'success'           => $success,
			'response_code'     => $response_code,
			'error_code'        => $error_code,
			'message'           => $message,
			'identity_mismatch' => 403 === $response_code && 'identity_mismatch' === $error_code,
			'permanent'         => in_array( $error_code, self::PERMANENT_ERROR_CODES, true ),
		];
	}
}
