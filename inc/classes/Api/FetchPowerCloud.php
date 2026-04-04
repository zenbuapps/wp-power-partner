<?php

declare(strict_types=1);

namespace J7\PowerPartner\Api;

use J7\PowerPartner\Bootstrap;
use J7\PowerPartner\Plugin;

/** Class FetchPowerCloud */
abstract class FetchPowerCloud
{
	const ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY = 'power_partner_allowed_template_options_powercloud';
	const OPEN_SITE_PLAN_OPTIONS_TRANSIENT_KEY   = 'power_partner_open_site_plan_options_powercloud';
	const ALLOWED_TEMPLATE_OPTIONS_CACHE_TIME = 7 * 24 * HOUR_IN_SECONDS;
	/**
	 * 發 API 開站
	 *
	 * @param array<string, mixed> $props 開站所需的參數
	 * @param string               $open_site_plan_id 開站方案 ID
	 * @param string               $template_site_id 模板站 ID (如果為空則開空站)
	 * @return array{
	 * 0:object{
	 *  status: int,
	 *  message: string,
	 *  data: mixed
	 * },
	 * 1: object{
	 *  domain: string,
	 *  name: string,
	 *  namespace: string,
	 *  wp_admin_user: string,
	 *  wp_admin_email: string,
	 *  wp_admin_password: string
	 * }
	 * } — The response or WP_Error on failure.
	 *
	 * @throws \Exception 當 API 請求失敗時拋出異常
	 */
	public static function site_sync(array $props, string $open_site_plan_id, string $template_site_id): array
	{
		/** @var array{id: int, first_name: string, last_name: string, username: string, email: string, phone: string} $customer */
		$customer           = $props['customer'];
		$current_user_id    = $customer['id'];

		$powercloud_api_key = self::get_powercloud_api_key((string) $current_user_id);

		if (empty($powercloud_api_key)) {
			throw new \Exception('PowerCloud API Key 不存在，請先登入 PowerCloud');
		}

		$template_sites    = \get_transient(self::ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY);
		$template_sites    = is_array($template_sites) ? $template_sites : [];
		$template_site_url = $template_sites[$template_site_id] ?? '';

		/** @var array{id: int, first_name: string, last_name: string, username: string, email: string, phone: string} $customer */
		$customer = $props['customer'];

		// 生成隨機密碼
		$db_root_password  = 'root-' . \wp_generate_password(32, false);
		$db_password       = 'db-' . \wp_generate_password(32, false);
		$wp_admin_password = 'admin-' . \wp_generate_password(16, false);

		// 生成 name 和 namespace (基於 customer username 或 email)
		$namespace = self::generate_namespace() . '-' . random_int(1000, 9999);
		$name      = $namespace;
		$domain    = $namespace . '.wpsite.pro';

		// 構建請求體
		$request_body = [
			'packageId'   => $open_site_plan_id,
			'namespace'   => $namespace,
			'wildcardDomain' => $domain,
			'mysql'       => [
				'auth' => [
					'rootPassword' => $db_root_password,
					'password'     => $db_password,
				],
			],
			'wordpress'   => [
				'autoInstall' => [
					'adminUser'     => $customer['username'],
					'adminPassword' => $wp_admin_password,
					'adminEmail'    => $customer['email'],
					'siteTitle'     => 'WordPress Site',
				],
			],
			'templateUrl' => $template_site_url,
		];

		$body = \wp_json_encode($request_body);
		if (false === $body) {
			throw new \Exception('site_sync PowerCloud: wp_json_encode failed');
		}

		$args = [
			'body'    => $body,
			'headers' => [
				'Content-Type' => 'application/json',
				'X-API-Key'    => is_string($powercloud_api_key) ? $powercloud_api_key : '',
			],
			'timeout' => 600,
		];

		$response = \wp_remote_post(Bootstrap::instance()->powercloud_api . '/wordpress', $args);

		if (\is_wp_error($response)) {
			throw new \Exception('site_sync PowerCloud API Error: ' . $response->get_error_message());
		}
		$response_body = json_decode($response['body'], true);
		$response_code = (int) \wp_remote_retrieve_response_code($response);

		// 構建標準化的響應格式
		$response_obj = (object) [
			'status'  => $response_code,
			'message' => $response_code >= 200 && $response_code < 300 ? '開站成功' : '開站失敗',
			'data'    => $response_body,
		];

		$wordpress_obj = (object) [
			'domain'            => $domain,
			'name'              => $name,
			'namespace'         => $namespace,
			'wp_admin_user'     => $customer['username'],
			'wp_admin_email'    => $customer['email'],
			'wp_admin_password' => $wp_admin_password,
		];

		\do_action('pp_after_site_sync_powercloud', $response_obj, $props);

		return [$response_obj, $wordpress_obj];
	}


	/**
	 * 發 API disable 暫停 WordPress 網站
	 *
	 * @param string $current_user_id 當前用戶 ID
	 * @param string $website_id      網站 ID
	 * @return void
	 */
	public static function disable_site(string $current_user_id, string $website_id): void
	{
		$powercloud_api_key = self::get_powercloud_api_key((string) $current_user_id);

		$args = [
			'method'  => 'PATCH',
			'headers' => [
				'Content-Type' => 'application/json',
				'X-API-Key'    => is_string($powercloud_api_key) ? $powercloud_api_key : '',
			],
			'timeout' => 600,
		];

		$response = wp_remote_request(Bootstrap::instance()->powercloud_api . "/wordpress/{$website_id}/stop", $args);

		if (is_wp_error($response)) {
			Plugin::logger(
				"disable_site error: {$response->get_error_message()}",
				'error',
				[
					'current_user_id' => $current_user_id,
					'websiteId'       => $website_id,
				]
			);
			return;
		}

		Plugin::logger(
			'disable_site success',
			'info',
			[
				'current_user_id' => $current_user_id,
				'websiteId'       => $website_id,
				'response'        => $response,
			]
		);
	}

	/**
	 * 發 API enable 啟用 WordPress 網站
	 *
	 * @param string $current_user_id 當前用戶 ID
	 * @param string $website_id      網站 ID
	 * @return void
	 */
	public static function enable_site(string $current_user_id, string $website_id): void
	{
		$powercloud_api_key = self::get_powercloud_api_key((string) $current_user_id);

		$args = [
			'method'  => 'PATCH',
			'headers' => [
				'Content-Type' => 'application/json',
				'X-API-Key'    => is_string($powercloud_api_key) ? $powercloud_api_key : '',
			],
			'timeout' => 600,
		];

		$response = wp_remote_request(Bootstrap::instance()->powercloud_api . "/wordpress/{$website_id}/start", $args);

		if (is_wp_error($response)) {
			Plugin::logger(
				'enable_site error: ' . $response->get_error_message(),
				'error',
				[
					'current_user_id' => $current_user_id,
					'websiteId'       => $website_id,
				]
			);
			return;
		}

		Plugin::logger(
			'enable_site success',
			'info',
			[
				'current_user_id' => $current_user_id,
				'websiteId'       => $website_id,
				'response'        => $response,
			]
		);
	}

	/**
	 * 取得經銷商允許的模板站（新架構 PowerCloud）
	 * 會先判斷 transient 是否有資料，如果沒有則發 API 取得
	 *
	 * @return array<string, string>
	 */
	public static function get_allowed_template_options(): array
	{

		$allowed_template_options = \get_transient(self::ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY);
		$allowed_template_options = is_array($allowed_template_options) ? $allowed_template_options : [];

		if (empty($allowed_template_options)) {
			$allowed_template_options = self::fetch_template_sites_by_user();
		}

		\set_transient(self::ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY, $allowed_template_options, self::ALLOWED_TEMPLATE_OPTIONS_CACHE_TIME);

		/** @var array<string, string> $allowed_template_options */
		return $allowed_template_options;
	}

	/**
	 * 取得合作夥伴的模板站（新架構 PowerCloud）
	 *
	 * @return array<string, string>
	 */
	public static function fetch_template_sites_by_user(): array
	{
		$_allowed_template_options = [];
		$current_user_id           = \get_current_user_id();
		$powercloud_api_key        = self::get_powercloud_api_key((string) $current_user_id);

		$args     = [
			'headers' => [
				'Content-Type' => 'application/json',
				'X-API-Key'    => is_string($powercloud_api_key) ? $powercloud_api_key : '',
			],
			'timeout' => 600,
		];
		$response = \wp_remote_get(Bootstrap::instance()->powercloud_api . '/templates/wordpress?page=1&limit=250', $args);

		Plugin::logger("[GET] /templates/wordpress", 'debug', [
			'response' => $response,
			'powercloud_api_key' => $powercloud_api_key
		]);

		if (\is_wp_error($response)) {
			return [];
		}

		$response_body = json_decode($response['body'], true);
		$response_body = is_array($response_body) ? $response_body : [];

		$template_sites = isset($response_body['data']) && is_array($response_body['data']) ? $response_body['data'] : [];

		foreach ($template_sites as $template_site) {
			if (is_array($template_site) && isset($template_site['primaryDomain']) && isset($template_site['id'])) {
				$_allowed_template_options[(string) $template_site['id']] = (string) $template_site['primaryDomain'];
			}
		}

		return $_allowed_template_options;
	}

	/**
	 * 取得開站方案（新架構 PowerCloud）
	 * 會先判斷 transient 是否有資料，如果沒有則發 API 取得
	 *
	 * @return array<string, string>
	 */
	public static function get_open_site_plan_options(): array
	{
		$open_site_plan_options = \get_transient(self::OPEN_SITE_PLAN_OPTIONS_TRANSIENT_KEY);
		$open_site_plan_options = is_array($open_site_plan_options) ? $open_site_plan_options : [];

		if (empty($open_site_plan_options)) {
			$open_site_plan_options = self::fetch_open_site_plan_options_by_user();
		}

		\set_transient(self::OPEN_SITE_PLAN_OPTIONS_TRANSIENT_KEY, $open_site_plan_options, self::ALLOWED_TEMPLATE_OPTIONS_CACHE_TIME);

		/** @var array<string, string> $open_site_plan_options */
		return $open_site_plan_options;
	}

	/**
	 * 取得開站方案列表（新架構 PowerCloud）
	 *
	 * @return array<string, string>
	 */
	public static function fetch_open_site_plan_options_by_user(): array
	{
		$_open_site_plan_options = [];
		$current_user_id         = \get_current_user_id();
		$powercloud_api_key      = self::get_powercloud_api_key((string) $current_user_id);

		if (empty($powercloud_api_key)) {
			return [];
		}

		$args     = [
			'headers' => [
				'Content-Type' => 'application/json',
				'X-API-Key'    => is_string($powercloud_api_key) ? $powercloud_api_key : '',
			],
			'timeout' => 600,
		];
		$response = \wp_remote_get(Bootstrap::instance()->powercloud_api . '/website-packages?page=1&limit=250&isActive=true', $args);

		if (\is_wp_error($response)) {
			return [];
		}

		$response_body = json_decode($response['body'], true);
		$response_body = is_array($response_body) ? $response_body : [];

		$website_packages = isset($response_body['data']) && is_array($response_body['data']) ? $response_body['data'] : [];

		foreach ($website_packages as $package) {
			if (is_array($package) && isset($package['id']) && isset($package['name'])) {
				$price = $package['price'] ?? '';
				$_open_site_plan_options[(string) $package['id']] = (string) $package['name'] . '-' . (string) $price;
			}
		}

		return $_open_site_plan_options;
	}

	/**
	 * 生成隨機 namespace
	 * 格式: {隨機形容詞}-{隨機動物}
	 *
	 * @return string
	 */
	private static function generate_namespace(): string
	{
		$random_adjs = [
			'bright',
			'curious',
			'dynamic',
			'eager',
			'fabulous',
			'fantastic',
			'friendly',
			'gorgeous',
			'happy',
			'incredible',
			'intelligent',
			'jolly',
			'kind',
			'lively',
			'magnificent',
			'mysterious',
			'neat',
			'optimistic',
			'perfect',
			'quaint',
			'remarkable',
			'smart',
			'splendid',
		];

		$random_animals = [
			'lion',
			'tiger',
			'bear',
			'elephant',
			'giraffe',
			'zebra',
			'kangaroo',
			'panda',
			'monkey',
			'dog',
			'cat',
			'rabbit',
			'horse',
			'sheep',
			'cow',
			'chicken',
			'duck',
			'goat',
			'wolf',
			'fox',
			'otter',
			'salmon',
			'whale',
			'shark',
			'turtle',
			'dolphin',
			'penguin',
		];

		$random_adj_index    = \array_rand($random_adjs);
		$random_animal_index = \array_rand($random_animals);

		return $random_adjs[$random_adj_index] . '-' . $random_animals[$random_animal_index];
	}

	/**
	 * 取得 PowerCloud API Key
	 *
	 * @param string $user_id 用戶 ID
	 * @return string|null API Key 或 null 如果不存在
	 */
	public static function get_powercloud_api_key(string $user_id): ?string
	{
		/** @var string|false $legacy 就版本的 key */
		$legacy = \get_transient(Main::POWERCLOUD_API_KEY_TRANSIENT_KEY . '_' . $user_id);
		$key = \get_transient(Main::POWERCLOUD_API_KEY_TRANSIENT_KEY);

		Plugin::logger('powercloud_api_key', 'debug', [
			'legacy' => $legacy,
			'key' => $key,
		]);

		if ($key) {
			return (string) $key;
		}
		return is_string($legacy) ? $legacy : null;
	}
}
