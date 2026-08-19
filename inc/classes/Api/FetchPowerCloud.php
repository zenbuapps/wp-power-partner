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
	/** @var int /websites 單頁筆數上限 */
	const WEBSITES_PAGE_LIMIT = 250;
	/** @var int /websites 分頁保護上限，避免對端回報錯誤 total 時無限迴圈 */
	const WEBSITES_MAX_PAGES  = 100;
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
	 * @return bool 是否停用成功（HTTP 2xx 才視為成功）
	 */
	public static function disable_site(string $current_user_id, string $website_id): bool
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
			return false;
		}

		$response_code = (int) \wp_remote_retrieve_response_code($response);
		if ($response_code < 200 || $response_code >= 300) {
			Plugin::logger(
				'disable_site http error',
				'error',
				[
					'current_user_id' => $current_user_id,
					'websiteId'       => $website_id,
					'response_code'   => $response_code,
					'body'            => \wp_remote_retrieve_body($response),
				]
			);
			return false;
		}

		Plugin::logger(
			'disable_site success',
			'info',
			[
				'current_user_id' => $current_user_id,
				'websiteId'       => $website_id,
				'response_code'   => $response_code,
			]
		);
		return true;
	}

	/**
	 * 發 API enable 啟用 WordPress 網站
	 *
	 * @param string $current_user_id 當前用戶 ID
	 * @param string $website_id      網站 ID
	 * @return bool 是否啟用成功（HTTP 2xx 才視為成功）
	 */
	public static function enable_site(string $current_user_id, string $website_id): bool
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
			return false;
		}

		$response_code = (int) \wp_remote_retrieve_response_code($response);
		if ($response_code < 200 || $response_code >= 300) {
			Plugin::logger(
				'enable_site http error',
				'error',
				[
					'current_user_id' => $current_user_id,
					'websiteId'       => $website_id,
					'response_code'   => $response_code,
					'body'            => \wp_remote_retrieve_body($response),
				]
			);
			return false;
		}

		Plugin::logger(
			'enable_site success',
			'info',
			[
				'current_user_id' => $current_user_id,
				'websiteId'       => $website_id,
				'response_code'   => $response_code,
			]
		);
		return true;
	}

	/**
	 * 取得本 API Key 所屬帳號下的網站全量清單（依回應 total 分頁拉完）
	 *
	 * 回傳值語義（呼叫端務必區分）：
	 *  - array：成功。內容為全量網站，空陣列代表該帳號確實沒有站
	 *  - null ：失敗（無 API Key／連線錯誤／非 2xx／回應格式異常／分頁未拉完）
	 *
	 * 失敗時**不可**退化成空陣列 —— 少送站等於少收錢，呼叫端必須據此進入重試流程，
	 * 不得以殘缺清單推送計費資料。
	 *
	 * 元素型別刻意保持 mixed —— 這是外部 API 解碼後的未信任資料，
	 * 呼叫端必須逐筆 is_array() 後才取用欄位。
	 *
	 * @param string|null $user_id 用戶 ID（預設取當前用戶；cron 情境為 '0'，會 fallback 到全域 key）
	 * @return array<int, mixed>|null
	 */
	public static function fetch_websites( ?string $user_id = null ): ?array
	{
		$user_id            = null === $user_id ? (string) \get_current_user_id() : $user_id;
		$powercloud_api_key = self::get_powercloud_api_key($user_id);

		if (empty($powercloud_api_key)) {
			Plugin::logger('fetch_websites 中止：PowerCloud API Key 不存在，不以空 key 呼叫 API', 'error');
			return null;
		}

		$args = [
			'headers' => [
				'Content-Type' => 'application/json',
				'X-API-Key'    => $powercloud_api_key,
			],
			'timeout' => 600,
		];

		$websites = [];
		$seen     = [];
		$total    = 0;
		$page     = 1;

		do {
			$url      = sprintf(
				'%1$s/websites?page=%2$d&limit=%3$d',
				Bootstrap::instance()->powercloud_api,
				$page,
				self::WEBSITES_PAGE_LIMIT
			);
			$response = \wp_remote_get($url, $args);

			if (\is_wp_error($response)) {
				Plugin::logger('fetch_websites wp_error', 'error', [
					'page'               => $page,
					'error'              => $response->get_error_message(),
					'powercloud_api_key' => self::mask_api_key($powercloud_api_key),
				]);
				return null;
			}

			// /websites 的回應本身帶站台憑證（adminPassword / databaseRootPassword 等），
			// 原文寫進 wc-logs 等同把該經銷商全部網站的明文密碼外洩，只留可診斷指紋
			$raw_body    = (string) \wp_remote_retrieve_body($response);
			$fingerprint = self::body_fingerprint($raw_body);

			$response_code = (int) \wp_remote_retrieve_response_code($response);
			if ($response_code < 200 || $response_code >= 300) {
				Plugin::logger('fetch_websites http error', 'error', [
					'page'               => $page,
					'response_code'      => $response_code,
					'body_length'        => $fingerprint['body_length'],
					'body_keys'          => $fingerprint['body_keys'],
					'powercloud_api_key' => self::mask_api_key($powercloud_api_key),
				]);
				return null;
			}

			$response_body = json_decode($raw_body, true);
			if (!is_array($response_body) || !isset($response_body['data']) || !is_array($response_body['data'])) {
				Plugin::logger('fetch_websites 回應格式異常', 'error', [
					'page'               => $page,
					'response_code'      => $response_code,
					'body_length'        => $fingerprint['body_length'],
					'body_keys'          => $fingerprint['body_keys'],
					'powercloud_api_key' => self::mask_api_key($powercloud_api_key),
				]);
				return null;
			}

			$page_data = array_values($response_body['data']);

			// total 只認第 1 頁 —— 對端「只在第 1 頁給 total」是常見實作，
			// 每頁重讀會讓後續頁 fallback 成該頁筆數，累計數瞬間「達標」而 break，
			// 1000 站只送 500 站且回報成功、無任何 error log（少送站 = 少收錢）
			if (1 === $page) {
				if (!isset($response_body['total']) || !is_numeric($response_body['total'])) {
					Plugin::logger('fetch_websites 回應缺少 total，無法確認是否取完清單', 'error', [
						'page'        => $page,
						'body_length' => $fingerprint['body_length'],
						'body_keys'   => $fingerprint['body_keys'],
					]);
					return null;
				}
				$total = (int) $response_body['total'];
			}

			// 逐筆去重 —— 對端若因改版／快取層／WAF 剝掉 query string 而忽略 page 參數，
			// 每頁都回同一批資料，只看累計筆數會把同一批站累加 N 次，
			// payload 送出重複 domain 後接收端逐筆加總，該經銷商會被多扣 N 倍
			$added = 0;
			foreach ($page_data as $website) {
				$key = self::website_key($website);
				if (isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				$websites[] = $website;
				++$added;
			}

			// 已取完 total 筆
			if (count($websites) >= $total) {
				break;
			}

			// 尚未取完卻沒有帶來任何新資料（空頁，或對端回同一批）：
			// 分頁停滯，視為取得失敗（不可送出殘缺或重複的清單）
			if (!$added) {
				Plugin::logger('fetch_websites 分頁停滯，清單不完整', 'error', [
					'page'       => $page,
					'page_count' => count($page_data),
					'fetched'    => count($websites),
					'total'      => $total,
				]);
				return null;
			}

			// 回傳筆數不足單頁上限即為最後一頁；此時仍未達 total 代表 total 與實際資料不一致
			if (count($page_data) < self::WEBSITES_PAGE_LIMIT) {
				Plugin::logger('fetch_websites 已無後續分頁但未取滿 total，清單不完整', 'error', [
					'page'       => $page,
					'page_count' => count($page_data),
					'fetched'    => count($websites),
					'total'      => $total,
				]);
				return null;
			}

			++$page;
		} while ($page <= self::WEBSITES_MAX_PAGES);

		if (count($websites) < $total) {
			Plugin::logger('fetch_websites 超過最大分頁數仍未取完，清單不完整', 'error', [
				'max_pages' => self::WEBSITES_MAX_PAGES,
				'fetched'   => count($websites),
				'total'     => $total,
			]);
			return null;
		}

		Plugin::logger('[GET] /websites', 'debug', [
			'count'              => count($websites),
			'total'              => $total,
			'pages'              => $page,
			'powercloud_api_key' => self::mask_api_key($powercloud_api_key),
		]);

		return $websites;
	}

	/**
	 * 取得經銷商允許的模板站（新架構 PowerCloud）
	 * 會先判斷 transient 是否有資料，如果沒有則發 API 取得
	 * 只在 fetch 成功且結果非空時寫入 transient（永不到期，手動清除快取或站長調整模板時才更新）
	 *
	 * @return array<string, string>
	 */
	public static function get_allowed_template_options(): array
	{
		$allowed_template_options = \get_transient(self::ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY);

		if (is_array($allowed_template_options) && ! empty($allowed_template_options)) {
			/** @var array<string, string> $allowed_template_options */
			return $allowed_template_options;
		}

		$allowed_template_options = self::fetch_template_sites_by_user();

		if (! empty($allowed_template_options)) {
			\set_transient(self::ALLOWED_TEMPLATE_OPTIONS_TRANSIENT_KEY, $allowed_template_options);
		}

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
			'powercloud_api_key' => self::mask_api_key($powercloud_api_key)
		]);

		if (\is_wp_error($response)) {
			Plugin::logger('fetch_template_sites_by_user wp_error', 'error', [
				'error' => $response->get_error_message(),
			]);
			return [];
		}

		$response_code = (int) \wp_remote_retrieve_response_code($response);
		if ($response_code < 200 || $response_code >= 300) {
			Plugin::logger('fetch_template_sites_by_user http error', 'error', [
				'response_code' => $response_code,
				'body'          => \wp_remote_retrieve_body($response),
			]);
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
	 * 只在 fetch 成功且結果非空時寫入 transient（永不到期，手動清除快取或站長調整方案時才更新）
	 *
	 * @return array<string, string>
	 */
	public static function get_open_site_plan_options(): array
	{
		$open_site_plan_options = \get_transient(self::OPEN_SITE_PLAN_OPTIONS_TRANSIENT_KEY);

		if (is_array($open_site_plan_options) && ! empty($open_site_plan_options)) {
			/** @var array<string, string> $open_site_plan_options */
			return $open_site_plan_options;
		}

		$open_site_plan_options = self::fetch_open_site_plan_options_by_user();

		if (! empty($open_site_plan_options)) {
			\set_transient(self::OPEN_SITE_PLAN_OPTIONS_TRANSIENT_KEY, $open_site_plan_options);
		}

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
			Plugin::logger('fetch_open_site_plan_options_by_user wp_error', 'error', [
				'error' => $response->get_error_message(),
			]);
			return [];
		}

		$response_code = (int) \wp_remote_retrieve_response_code($response);
		if ($response_code < 200 || $response_code >= 300) {
			Plugin::logger('fetch_open_site_plan_options_by_user http error', 'error', [
				'response_code' => $response_code,
				'body'          => \wp_remote_retrieve_body($response),
			]);
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
	 * 取得網站的去重鍵
	 *
	 * 以 id 為主；id 缺漏或非純量時退回整筆資料的雜湊 ——
	 * 少了鍵就無法去重，而重複資料會讓經銷商被重複扣款，寧可用較弱的鍵也不能不去重。
	 *
	 * @param mixed $website 網站資料（外部 API 解碼後的未信任資料）
	 * @return string
	 */
	private static function website_key(mixed $website): string
	{
		if (is_array($website) && isset($website['id']) && is_scalar($website['id'])) {
			$id = trim((string) $website['id']);
			if ('' !== $id) {
				return 'id:' . $id;
			}
		}

		return 'hash:' . sha1((string) \wp_json_encode($website));
	}

	/**
	 * 回應 body 的可診斷指紋：只留長度與頂層鍵名，不含任何 value
	 *
	 * 專供 /websites 這種「回應本身帶憑證」的端點使用 —— 該端點回應含 adminEmail、
	 * adminPassword、databaseUsername、databasePassword、databaseRootPassword，
	 * 原文寫進 wc-logs 後對任何 manage_woocommerce 使用者可讀，也會進站台備份。
	 * 指紋足以辨識「envelope 從 {data,total} 改成裸陣列或改名」這類格式改版。
	 *
	 * @param string $body 回應 body 原文
	 * @return array{body_length: int, body_keys: array<int, string>}
	 */
	private static function body_fingerprint(string $body): array
	{
		$decoded = json_decode($body, true);

		if (!is_array($decoded)) {
			$keys = [];
		} elseif (array_is_list($decoded)) {
			// 裸陣列：鍵名全是流水號，記筆數即可
			$keys = [ 'list:' . count($decoded) ];
		} else {
			$keys = array_map('strval', array_keys($decoded));
		}

		return [
			'body_length' => strlen($body),
			'body_keys'   => $keys,
		];
	}

	/**
	 * 遮罩 API Key，只記錄長度與 sha256 前綴供辨識，避免完整 key 落地 log
	 *
	 * @param mixed $key API Key
	 * @return string
	 */
	private static function mask_api_key(mixed $key): string
	{
		if (!is_string($key) || '' === $key) {
			return '(empty)';
		}
		return sprintf('len=%d sha256:%s', strlen($key), substr(hash('sha256', $key), 0, 8));
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
			'legacy' => self::mask_api_key($legacy),
			'key' => self::mask_api_key($key),
		]);

		if ($key) {
			return (string) $key;
		}
		return is_string($legacy) ? $legacy : null;
	}
}
