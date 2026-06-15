<?php

declare(strict_types=1);

namespace J7\PowerPartner\Api;

use J7\PowerPartner\Bootstrap;
use J7\PowerPartner\Plugin;

/** Class Fetch */
abstract class Fetch {

	const ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY = 'power_partner_allowed_template_options';

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
		$body = \wp_json_encode( $props );
		if ( false === $body ) {
			throw new \Exception('開站失敗: wp_json_encode failed');
		}
		$args     = [
			'body'    => $body,
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Basic ' . \base64_encode( Bootstrap::instance()->username . ':' . Bootstrap::instance()->psw ), // phpcs:ignore
			],
			'timeout' => 600,
		];
		$response = \wp_remote_post( Bootstrap::instance()->base_url . '/wp-json/power-partner-server/site-sync', $args );

		if (\is_wp_error($response)) {
			throw new \Exception("開站失敗: {$response->get_error_message()}");
		}

		/** @var object{status: int, message: string, data: mixed} $response_obj */
		$response_obj = json_decode( $response['body'] );

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

		$body = \wp_json_encode(
			[
				'site_id'    => $site_id,
				'partner_id' => $partner_id,
				'reason'     => $reason,
			]
		);
		if ( false === $body ) {
			Plugin::logger( 'disable_site wp_json_encode failed', 'error', [ 'site_id' => $site_id ] );
			return false;
		}
		$args     = [
			'body'    => $body,
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Basic ' . \base64_encode( Bootstrap::instance()->username . ':' . Bootstrap::instance()->psw ), // phpcs:ignore
			],
			'timeout' => 600,
		];
		$response = \wp_remote_post( Bootstrap::instance()->base_url . '/wp-json/power-partner-server/v2/disable-site', $args );

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

		$body = \wp_json_encode(
			[
				'site_id'    => $site_id,
				'partner_id' => $partner_id,
			]
		);
		if ( false === $body ) {
			Plugin::logger( 'enable_site wp_json_encode failed', 'error', [ 'site_id' => $site_id ] );
			return false;
		}
		$args     = [
			'body'    => $body,
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Basic ' . \base64_encode( Bootstrap::instance()->username . ':' . Bootstrap::instance()->psw ), // phpcs:ignore
			],
			'timeout' => 600,
		];
		$response = \wp_remote_post( Bootstrap::instance()->base_url . '/wp-json/power-partner-server/v2/enable-site', $args );

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
			if (\is_wp_error($result)) {
				return [];
			}

			/** @var object{data?: object{list?: array<object{ID: int, post_title: string}>}}|null $result_obj */
			$result_obj     = $result;
			$template_sites = $result_obj->data->list ?? null;

			if (!$template_sites) {
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

		$args = [
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Basic ' . \base64_encode( Bootstrap::instance()->username . ':' . Bootstrap::instance()->psw ), // phpcs:ignore
			],
			'timeout' => 120,
		];

		$response = \wp_remote_get( Bootstrap::instance()->base_url . '/wp-json/power-partner-server/template-sites?user_id=' . (string) $partner_id, $args );

		if ( \is_wp_error( $response ) ) {
			return new \WP_Error(
				'fetch_template_sites_by_user_error',
				$response->get_error_message(),
				[
					'status' => 500,
				]
			);
		}

		return json_decode( $response['body'] );
	}
}
