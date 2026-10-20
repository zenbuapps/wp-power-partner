<?php

declare(strict_types=1);

namespace J7\PowerPartner\Api;

use J7\PowerPartner\Bootstrap;
use J7\PowerPartner\Domains\Cloud\Services\CloudClient;
use J7\PowerPartner\Plugin;

/** Class Fetch */
abstract class Fetch {

	const ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY = 'power_partner_allowed_template_options';

	/**
	 * 模板列表抓取失敗時的負面快取秒數
	 *
	 * 成功的結果不設 TTL（由 POST /partner-id 與「清除快取」維護），但失敗時若不寫快取，
	 * 每一次載入 AdminApp / 商品編輯頁都會再打一次 CloudServer（timeout 120 秒）。
	 * CloudServer 撤銷共用帳號或經銷商尚未重新連線時，失敗會是常態，必須擋住重打。
	 */
	const ALLOWED_TEMPLATE_OPTIONS_FAILURE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * 呼叫 CloudServer（power-partner-server）共用的 request headers
	 *
	 * X-PP-Client-Version / X-PP-Site 供 CloudServer 統計「還有哪些站用舊版、舊憑證」，
	 * 是撤銷共用帳號前判斷能否關閉的依據，不參與任何驗證。
	 *
	 * Authorization 這裡給的是共用帳號（legacy）；實際送出前由 CloudClient 依認證模式覆寫，
	 * 呼叫 CloudServer 一律經過 CloudClient，不要直接拿這組 headers 去 wp_remote_*。
	 *
	 * @return array<string, string>
	 */
	public static function cloud_headers(): array {
		return [
			'Content-Type'        => 'application/json',
			'Authorization'       => 'Basic ' . \base64_encode( Bootstrap::instance()->username . ':' . Bootstrap::instance()->psw ), // phpcs:ignore
			'X-PP-Client-Version' => (string) Plugin::$version,
			'X-PP-Site'           => \site_url(),
		];
	}

	/**
	 * 發 API 開站
	 *
	 * @param array{
	 *     site_url: string,
	 *     site_id: string, // 複製的模板站 id，如果是 0 就是開空的 WP
	 *     server_id?: string, // 指定的伺服器 id，不帶就是隨機
	 *     host_position: string,
	 *     partner_id: string,
	 *          customer: array{
	 *     id: int,
	 *     first_name: string,
	 *     last_name: string,
	 *     username: string,
	 *     email: string,
	 *     phone: string,
	 *     },
	 *     subscription_id?: int,
	 * } $props 開站所需的參數
	 *
	 * @return object{
	 * status: int,
	 * message: string,
	 * data: mixed,
	 * }
	 *
	 * @throws \Exception When the request fails.
	 */
	public static function site_sync( array $props ) {
		$response = CloudClient::instance()->remote_post( 'site-sync', $props, [ 'timeout' => 600 ] );

		if (\is_wp_error($response)) {
			throw new \Exception("開站失敗: {$response->get_error_message()}");
		}

		/** @var object{status: int, message: string, data: mixed} $response_obj */
		$response_obj = json_decode( (string) \wp_remote_retrieve_body( $response ) );

		\do_action( 'pp_after_site_sync', $response_obj );

		return $response_obj;
	}


	/**
	 * 發 API 關站 disable
	 *
	 * @param string $site_id 網站 ID
	 * @param string $reason  停用原因
	 * @return bool 是否停用成功（HTTP 2xx 才視為成功；partner_id 為空或連線失敗回 false）
	 */
	public static function disable_site( string $site_id, string $reason = '停用網站' ): bool {
		$partner_id = \get_option( Connect::PARTNER_ID_OPTION_NAME );
		if ( empty( $partner_id ) ) {
			Plugin::logger(
				'disable_site 中止：partner_id 未設定，WPCD 後端無法辨識，停用請求不送出',
				'error',
				[ 'site_id' => $site_id ]
			);
			return false;
		}

		$response = CloudClient::instance()->remote_post(
			'v2/disable-site',
			[
				'site_id'    => $site_id,
				'partner_id' => $partner_id,
				'reason'     => $reason,
			],
			[ 'timeout' => 600 ]
		);

		if ( \is_wp_error( $response ) ) {
			Plugin::logger(
				"disable_site error: {$response->get_error_message()}",
				'error',
				[ 'site_id' => $site_id ]
			);
			return false;
		}

		$response_code = (int) \wp_remote_retrieve_response_code( $response );
		if ( $response_code < 200 || $response_code >= 300 ) {
			Plugin::logger(
				'disable_site http error',
				'error',
				[
					'site_id'       => $site_id,
					'response_code' => $response_code,
					'body'          => \wp_remote_retrieve_body( $response ),
				]
			);
			return false;
		}

		Plugin::logger(
			'disable_site success',
			'info',
			[
				'site_id'       => $site_id,
				'response_code' => $response_code,
			]
		);
		return true;
	}

	/**
	 * 發 API 啟用 WordPress 網站
	 *
	 * @param string $site_id 網站 ID
	 * @return bool 是否啟用成功（HTTP 2xx 才視為成功；partner_id 為空或連線失敗回 false）
	 */
	public static function enable_site( string $site_id ): bool {
		$partner_id = \get_option( Connect::PARTNER_ID_OPTION_NAME );
		if ( empty( $partner_id ) ) {
			Plugin::logger(
				'enable_site 中止：partner_id 未設定，WPCD 後端無法辨識，啟用請求不送出',
				'error',
				[ 'site_id' => $site_id ]
			);
			return false;
		}

		$response = CloudClient::instance()->remote_post(
			'v2/enable-site',
			[
				'site_id'    => $site_id,
				'partner_id' => $partner_id,
			],
			[ 'timeout' => 600 ]
		);

		if ( \is_wp_error( $response ) ) {
			Plugin::logger(
				"enable_site error: {$response->get_error_message()}",
				'error',
				[ 'site_id' => $site_id ]
			);
			return false;
		}

		$response_code = (int) \wp_remote_retrieve_response_code( $response );
		if ( $response_code < 200 || $response_code >= 300 ) {
			Plugin::logger(
				'enable_site http error',
				'error',
				[
					'site_id'       => $site_id,
					'response_code' => $response_code,
					'body'          => \wp_remote_retrieve_body( $response ),
				]
			);
			return false;
		}

		Plugin::logger(
			'enable_site success',
			'info',
			[
				'site_id'       => $site_id,
				'response_code' => $response_code,
			]
		);
		return true;
	}

	/**
	 * 取得經銷商允許的模板站
	 * 會先判斷 transient 是否有資料，如果沒有則發 API 取得
	 *
	 * @return array<string, string>
	 */
	public static function get_allowed_template_options(): array {
		$allowed_template_options = \get_transient( self::ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY );

		if ( false === $allowed_template_options ) {
			$allowed_template_options = [];
			$result                   = self::fetch_template_sites_by_user();
			// null = 尚未連線（沒有 partner_id），根本沒發請求，不需要負面快取
			if ( null === $result ) {
				return [];
			}
			if (\is_wp_error($result)) {
				\set_transient( self::ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY, [], self::ALLOWED_TEMPLATE_OPTIONS_FAILURE_TTL );
				return [];
			}

			/** @var object{data?: object{list?: array<object{ID: int, post_title: string}>}}|null $result_obj */
			$result_obj     = $result;
			$template_sites = $result_obj->data->list ?? null;

			if (!$template_sites) {
				\set_transient( self::ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY, [], self::ALLOWED_TEMPLATE_OPTIONS_FAILURE_TTL );
				return [];
			}

			foreach ( $template_sites as $site ) {
				$allowed_template_options[ (string) $site->ID ] = $site->post_title;
			}

			\set_transient( self::ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY, $allowed_template_options );
		}

		/** @var array<string, string> $allowed_template_options */
		return $allowed_template_options;
	}

	/**
	 * 取得合作夥伴的模板站
	 *
	 * @return mixed — The response object, null, or WP_Error on failure.
	 */
	public static function fetch_template_sites_by_user() {
		$partner_id = \get_option( Connect::PARTNER_ID_OPTION_NAME );
		if ( ! $partner_id ) {
			return null;
		}

		$response = CloudClient::instance()->remote_get( 'template-sites', [ 'user_id' => (string) $partner_id ], [ 'timeout' => 120 ] );

		if ( \is_wp_error( $response ) ) {
			return new \WP_Error(
				'fetch_template_sites_by_user_error',
				$response->get_error_message(),
				[
					'status' => 500,
				]
			);
		}

		return json_decode( (string) \wp_remote_retrieve_body( $response ) );
	}
}
