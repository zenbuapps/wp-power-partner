<?php

declare (strict_types = 1);

namespace J7\PowerPartner\Product;

use J7\Powerhouse\Domains\Subscription\Shared\Enums\Action;
use J7\PowerPartner\Api\Fetch;
use J7\PowerPartner\Api\FetchPowerCloud;
use J7\PowerPartner\Domains\Email\Core\SubscriptionEmailHooks as EmailService;
use J7\PowerPartner\Plugin;
use J7\PowerPartner\Product\DataTabs\LinkedSites;
use J7\PowerPartner\ShopSubscription;
use J7\PowerPartner\Utils\Token;

/** Class SiteSync */
final class SiteSync {

	use \J7\WpUtils\Traits\SingletonTrait;

	const PRODUCT_TYPE_NAME = 'Power Partner 產品';

	const CREATE_SITE_RESPONSES_META_KEY      = 'pp_create_site_responses';
	const CREATE_SITE_RESPONSES_ITEM_META_KEY = '_pp_create_site_responses_item'; // 加上下劃線前綴，隱藏在前端顯示

	// the site id linked in cloud site
	const LINKED_SITE_IDS_META_KEY = 'pp_linked_site_ids'; // pp === Power Partner

	/**
	 * 訂閱 meta：此訂閱的站台網址（含 scheme）
	 *
	 * Issue #23：網域在兩種架構下都不存在於「開站 API 回應」裡——
	 *   - PowerCloud：網域是 FetchPowerCloud::site_sync() 本地生成的（$namespace . '.wpsite.pro'），
	 *     回應 body 只有 websiteId。原本只被塞進一次性的 email_payloads_tmp，寄完信就刪 → 永久遺失。
	 *   - WPCD：開站是非同步的，網域稍後才由 CloudServer 回調 /customer-notification 帶回來。
	 *
	 * 兩條路徑各自寫入這一個結構穩定的 meta，Token::get_subscription_tokens() 只讀它。
	 * 刻意不塞進 pp_create_site_responses：那份 meta 在父訂單上、data 型別是 mixed
	 * （PowerCloud assoc array / WPCD stdClass），且 WPCD 回調拿不到「這是第幾個 item」的資訊。
	 *
	 * 寫入語義是「第一個站先寫、之後不覆蓋」——##URL## 語義上是單數，
	 * 多商品訂閱時固定指向第一個站，避免同一封催繳信在不同時間點指向不同的站。
	 */
	const SITE_URL_META_KEY = 'pp_site_url';

	/**
	 * 訂單項目 meta：此項目開站成功的 unix timestamp（issue #24 冪等鍵）
	 *
	 * 綁在 item 而不是訂閱，因為：
	 *   (a) WPCD 的 pp_linked_site_ids 要等非同步回調才寫，開站當下是空的，擋不住重送；
	 *   (b) 一張訂單多個商品各開一站是合法的，訂閱層級的旗標會把第二個商品也擋掉；
	 *   (c) pp_linked_site_ids 會被後台 metabox / REST /link-site / 回調改動，不適合當冪等依據。
	 *
	 * 只在 HTTP 2xx 才寫入，讓「開站失敗後的合法重試」不被誤擋。
	 * `_` 前綴的用意與 CREATE_SITE_RESPONSES_ITEM_META_KEY 一致（隱藏在前端顯示）。
	 */
	const SITE_SYNC_DONE_META_KEY = '_pp_site_sync_completed_at';

	/** 併發鎖前綴（存於 wp_options），鍵為 parent order id（issue #24） */
	const SITE_SYNC_LOCK_PREFIX = 'pp_site_sync_lock_';

	/**
	 * 併發鎖的殘鎖判定門檻（秒）
	 *
	 * 必須大於開站 API 的 timeout（FetchPowerCloud / Fetch 都是 600 秒），
	 * 否則正常但緩慢的開站會被自己的殘鎖判定搶走鎖。900 = 600 + 300 的 PHP 前後處理餘裕。
	 */
	const SITE_SYNC_LOCK_TIMEOUT = 900;

	/** 鎖競爭時延後重試開站的 ActionScheduler hook（issue #24） */
	const RETRY_AFTER_LOCK_ACTION = 'pp_site_sync_retry_after_lock';

	/** Constructor */
	public function __construct() {
		\add_action(Action::INITIAL_PAYMENT_COMPLETE->get_action_hook(), [ $this, 'site_sync_by_subscription' ], 1, 2);

		\add_action('powerhouse_delay_send_email', [ $this, 'send_email' ], 10, 2);

		// 鎖競爭時的延後重試（issue #24）
		\add_action(self::RETRY_AFTER_LOCK_ACTION, [ $this, 'retry_site_sync_after_lock' ], 10, 1);
	}



	/**
	 * Do site sync
	 * 訂閱首次創建
	 *
	 * @param \WC_Subscription     $subscription Subscription object.
	 * @param array<string, mixed> $args 參數
	 * @return void
	 */
	public function site_sync_by_subscription(\WC_Subscription $subscription, array $args ): void { // phpcs:ignore

		$lock_key      = '';
		$lock_acquired = false;

		try {
			$order_ids = $subscription->get_related_orders();

			$parent_order = $subscription->get_parent();

			if (! ( $parent_order instanceof \WC_Order )) {
				Plugin::logger("訂閱 #{$subscription->get_id()} 的父訂單不是 WC_Order 實例", 'error');
				return;
			}
			$parent_order_id = $parent_order->get_id();

			// 確保只有一筆訂單 (parent order) 才會觸發 site sync，續訂不觸發
			if (count($order_ids) !== 1) {
				return;
			}

			if (reset($order_ids) !== $parent_order_id) {
				Plugin::logger(
					"訂閱 #{$subscription->get_id()} 父訂單 ID 不一致",
					'error'
				);
				return;
			}

			/**
			 * Issue #24：併發鎖。
			 *
			 * 上面三道守衛只擋「續訂」，擋不住「同一個 parent order 的付款完成事件重送」——
			 * 重送時 get_related_orders() 仍然只有 1 筆，一路暢通。
			 * 下面的冪等旗標擋不住併發：它的 TOCTOU 窗口橫跨整個開站 HTTP 呼叫（timeout 600 秒），
			 * 兩個同時到達的回呼會同時通過旗標檢查，再各自打一次 API。
			 *
			 * 鎖的 key 用 parent order id 而非 subscription id：冪等旗標是 item 層級，
			 * 鎖的粒度必須 ≥ 冪等鍵的粒度。存在「一張父訂單掛兩個訂閱」的情形，
			 * 此時兩個 site_sync_by_subscription 會對同一批 item 動作，鎖 subscription id 擋不住。
			 *
			 * 鎖必須在三道守衛「之後」才取，否則每一次續訂事件都會白搶一次鎖。
			 */
			$lock_key      = self::SITE_SYNC_LOCK_PREFIX . $parent_order_id;
			$lock_acquired = self::acquire_lock($lock_key, self::SITE_SYNC_LOCK_TIMEOUT);

			if (! $lock_acquired) {
				$locked_at = (int) \get_option($lock_key);
				$note      = \sprintf(
					'偵測到重複的開站請求：訂單 #%1$d 另一個開站程序仍在進行中（起始於 %2$s），本次略過，未呼叫開站 API',
					$parent_order_id,
					$locked_at ? \wp_date('Y-m-d H:i:s', $locked_at) : '未知時間'
				);
				$subscription->add_order_note($note);
				$parent_order->add_order_note($note);
				Plugin::logger(
					$note,
					'error',
					[
						'trigger'         => 'lock',
						'subscription_id' => $subscription->get_id(),
						'order_id'        => $parent_order_id,
						'locked_at'       => $locked_at,
					]
				);

				/**
				 * 排一次延後重試，不能就這樣放棄。
				 *
				 * INITIAL_PAYMENT_COMPLETE 對同一張訂單只會來這幾次，錯過就不會再有。
				 * 若前一個 request 是被 fatal / OOM 殺掉的，鎖會存活到 SITE_SYNC_LOCK_TIMEOUT，
				 * 而金流的重送 webhook 往往在那之前就到——直接 return 等於「客戶付了錢、永遠沒有站」。
				 *
				 * 重試時間排在鎖必然失效之後（timeout + 60 秒緩衝）：
				 *   - 前一個程序正常跑完 → 冪等旗標已落，重試被旗標擋下，只多一筆 order note
				 *   - 前一個程序已死 → 殘鎖可被接管，重試真的把站開出來
				 * $unique=true 讓多次重送只堆出一個重試。
				 */
				\as_schedule_single_action(
					\time() + self::SITE_SYNC_LOCK_TIMEOUT + MINUTE_IN_SECONDS,
					self::RETRY_AFTER_LOCK_ACTION,
					[ 'subscription_id' => $subscription->get_id() ],
					'',
					true
				);

				return;
			}

			$items     = $parent_order->get_items();
			$responses = [];

			foreach ($items as $item) {
				/** @var \WC_Order_Item_Product $item */
				$product_id = $item->get_variation_id() ?: $item->get_product_id();
				$product    = \wc_get_product($product_id);

				if ( ! $product ) {
					continue;
				}

				// 如果不是可變訂閱商品，就不處理
				// linked_site_id 是模板站 ID
				// $linked_site_ids[] 原本在這裡被賦值但全域沒有任何讀取端（與 LINKED_SITE_IDS_META_KEY 無關），已移除
				if ('subscription_variation' === $product->get_type()) {
					$variation_id   = $item->get_variation_id();
					$host_position  = \get_post_meta($variation_id, LinkedSites::HOST_POSITION_FIELD_NAME, true);
					$linked_site_id = \get_post_meta($variation_id, LinkedSites::LINKED_SITE_FIELD_NAME, true);
				} elseif ('subscription' === $product->get_type()) {
					$host_position  = \get_post_meta($product_id, LinkedSites::HOST_POSITION_FIELD_NAME, true);
					$linked_site_id = \get_post_meta($product_id, LinkedSites::LINKED_SITE_FIELD_NAME, true);
				} else {
					continue;
				}

				if (empty($linked_site_id)) {
					continue;
				}

				/**
				 * Issue #24：冪等旗標。
				 *
				 * 只在上一次「開站成功（2xx）」時存在；開站失敗不落旗標，合法重試不會被誤擋。
				 */
				$completed_at = $item->get_meta(self::SITE_SYNC_DONE_META_KEY, true);
				if (! empty($completed_at)) {
					$note = \sprintf(
						'已略過重複開站請求：訂單項目 #%1$d 已於 %2$s 開站成功，訂閱 #%3$d 本次不再呼叫開站 API',
						$item->get_id(),
						\wp_date('Y-m-d H:i:s', (int) $completed_at),
						$subscription->get_id()
					);
					$subscription->add_order_note($note);
					$parent_order->add_order_note($note);
					Plugin::logger(
						$note,
						'error',
						[
							'trigger'         => 'idempotency',
							'subscription_id' => $subscription->get_id(),
							'order_id'        => $parent_order_id,
							'item_id'         => $item->get_id(),
							'completed_at'    => (int) $completed_at,
						]
					);
					continue;
				}

				/** @var string $host_type */
				$host_type = \get_post_meta($product_id, LinkedSites::HOST_TYPE_FIELD_NAME, true);

				/**
				 * 觀測用：host_type 為空字串時，下面的硬比對會落到 else 分支走 WPCD——
				 * 與 LinkedSites::DEFAULT_HOST_TYPE 的「powercloud 為預設」相反，
				 * 也與停用/啟用路徑用的 resolve_host_type() 不一致。
				 *
				 * 本次不改路由：開站當下手上的是「模板站 id」而非「站台 id」，
				 * 語義與 resolve_host_type() 的合約不同，貿然對齊會改變所有未設 host_type
				 * 舊商品的路由行為。先留下可統計的訊號，待資料稽核後另案處理。
				 */
				if ('' === (string) $host_type) {
					Plugin::logger(
						"商品 #{$product_id} 未設定 host_type，開站路由落到 WPCD 分支",
						'error',
						[
							'product_id' => $product_id,
							'order_id'   => $parent_order_id,
						]
					);
				}

				// 根據 host_type 判斷是否為 WPCD (舊架構) 或是 PowerCloud (新架構) 開站
				$customer_user = \get_user_by('id', $parent_order->get_customer_id());
				$site_sync_params = [
					'site_url'        => \site_url(),
					'site_id'         => (string) $linked_site_id,
					'host_position'   => (string) $host_position,
					'partner_id'      => (string) \get_option(Plugin::$snake . '_partner_id', '0'),
					'customer'        => [
						'id'         => $parent_order->get_customer_id(),
						'first_name' => $parent_order->get_billing_first_name(),
						'last_name'  => $parent_order->get_billing_last_name(),
						'username'   => $customer_user ? $customer_user->user_login : 'admin',
						'email'      => $parent_order->get_billing_email(),
						'phone'      => $parent_order->get_billing_phone(),
					],
					'subscription_id' => $subscription->get_id(),
				];

				// 根據 host_type 選擇對應的 API
				// powercloud 為新架構（新架構是默認Host Type)
				if ($host_type === LinkedSites::DEFAULT_HOST_TYPE) {
					$response_obj = self::site_sync_powercloud( (int) $product_id, $subscription, $site_sync_params);
				} else {
					// wpcd 為舊架構
					// 舊架構：使用 Fetch::site_sync
					$response_obj = Fetch::site_sync($site_sync_params);
				}

				$status = (int) ( $response_obj->status ?? 0 );

				$response_entry = [
					'status'  => $status,
					'message' => (string) ( $response_obj->message ?? '' ),
					// WPCD 回 stdClass、PowerCloud 回 assoc array，正規化後兩者存進同一份 meta 才讀得動
					'data'    => self::normalize_response_data($response_obj->data ?? null),
				];

				$responses[] = $response_entry;

				/**
				 * 只寫「這個 item 自己那一筆」。
				 *
				 * 原本寫的是累積中的整個 $responses，多商品訂單時第 2 個 item 的 meta 會包含
				 * 第 1 個 item 的回應——而讀取端（DisableSiteScheduler / DisableHooks 的 fallback）
				 * 一律取第 0 筆，等於拿到別人的 websiteId → 停用時停錯站。
				 */
				$item->update_meta_data(
					self::CREATE_SITE_RESPONSES_ITEM_META_KEY,
					(string) \wp_json_encode([ $response_entry ])
				);

				// 條件必須與 site_sync_powercloud() 內的成功判斷一致，見該處註解
				if (self::is_successful_status($status)) {
					$item->update_meta_data(self::SITE_SYNC_DONE_META_KEY, (string) \time());
				}

				/**
				 * 立即落地。
				 *
				 * 多商品訂單若後續 item 拋例外，catch 之後就走不到迴圈外的 $parent_order->save()，
				 * 冪等旗標會遺失 → 重試時重開已成功的站。
				 */
				$item->save();
			}

			// 在所有 meta_data 添加完成後，統一保存一次
			// 這樣可以確保所有數據都被正確保存
			$subscription->save();

			Plugin::logger(
				"訂閱 #{$subscription->get_id()}  order_id: #{$parent_order_id}",
				'info',
				[
					'responses' => $responses,
				]
			);

			/**
			 * 把網站建立成功與否的資訊存到訂單的 meta data。
			 *
			 * 全部 item 都被冪等擋掉時 $responses 為空——此時「不可以」覆寫既有紀錄，
			 * 否則重送事件會把上一次成功的開站回應清成 []。
			 */
			if (count($responses) >= 1) {
				$response     = $responses[0];
				$first_status = $response['status'];

				/**
				 * PowerCloud 成功回 201、WPCD 回 200。
				 *
				 * 原本只認 200，導致每一筆成功的 PowerCloud 訂單備註都是 print_r 的除錯 dump，
				 * 而 $response['data'] 是 API 回應原文——對端若在 body 帶憑證
				 * （/websites 端點就會帶 adminPassword），會被寫進經銷商可見、
				 * 且出現在 WC 訂單備註 REST API 的欄位。
				 */
				if (self::is_successful_status($first_status)) {
					$note = '';
					foreach ($response['data'] as $key => $value) {
						$note .= $key . ': ' . ( \is_scalar($value) ? (string) $value : (string) \wp_json_encode($value) ) . '<br />';
					}
					if ('' === $note) {
						$note = "開站成功（HTTP {$first_status}），API 未回傳額外資訊";
					}
				} else {
					$note = \sprintf(
						'開站失敗，HTTP %1$d，訊息：%2$s，回應：%3$s',
						$first_status,
						$response['message'],
						(string) \wp_json_encode($response['data'])
					);
				}

				$parent_order->add_order_note($note);

				/**
				 * 與既有紀錄合併，不可直接覆寫。
				 *
				 * 部分重試（item 1 被冪等旗標略過、item 2 重新開站）時 $responses 只含 item 2，
				 * 直接覆寫會讓 get_first_site_response_data()——訂單列表欄位、metabox、
				 * 與 Token 的 ##URL## fallback 共用的那個 accessor——改為回報第二個站。
				 */
				$merged_responses = \array_merge(
					self::get_create_site_responses($parent_order),
					$responses
				);
				$parent_order->update_meta_data(self::CREATE_SITE_RESPONSES_META_KEY, (string) \wp_json_encode($merged_responses));
			}

			$parent_order->save();

			\do_action('pp_site_sync_by_subscription', $subscription);
		} catch (\Throwable $th) {
			$subscription->add_order_note('網站建立失敗：' . $th->getMessage());
			Plugin::logger(
				'訂閱 #' . $subscription->get_id() . ' 建立網站失敗',
				'error',
				[
					'error' => $th->getMessage(),
				],
				5
			);
		} finally {
			/**
			 * 正常結束、提前 return、拋例外三種路徑都要釋放。
			 * finally 也跑不到的只有 fatal error / OOM / process kill——
			 * 那時靠 SITE_SYNC_LOCK_TIMEOUT 的殘鎖清除機制回收。
			 */
			if ($lock_acquired) {
				self::release_lock($lock_key);
			}
		}
	}

	/**
	 * 取得開站併發鎖（issue #24）
	 *
	 * 為什麼不用 add_option()：WP 的 add_option() 先以 get_option() 做 PHP 層存在檢查（非原子），
	 * 再以 INSERT ... ON DUPLICATE KEY UPDATE 寫入（重複也不會失敗），兩個併發 request 會雙雙成功。
	 * 為什麼不用 wp_cache_add()：預設物件快取不跨 request，沒有 Redis/Memcached 時等於沒鎖。
	 * 唯一可靠的是 wp_options.option_name 的 UNIQUE index + INSERT IGNORE，
	 * 也就是 WP_Upgrader::create_lock() 的作法——但該類別只在 wp-admin 載入，前台 request 拿不到。
	 *
	 * @param string $lock_key 鎖的 option name
	 * @param int    $timeout  殘鎖判定門檻（秒）
	 * @return bool 是否取得鎖
	 */
	private static function acquire_lock( string $lock_key, int $timeout ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no') /* PP SITE SYNC LOCK */",
				$lock_key,
				(string) \time()
			)
		);

		if ($inserted) {
			// INSERT IGNORE 繞過 WP 的 options cache，不清 notoptions 會讓後續 get_option() 讀到 false
			\wp_cache_delete('notoptions', 'options');
			return true;
		}

		\wp_cache_delete($lock_key, 'options');
		\wp_cache_delete('notoptions', 'options');
		$existing = \get_option($lock_key);

		if (! $existing) {
			return false;
		}

		// 鎖還在有效期內 → 真的有另一個開站程序在跑
		if ( (int) $existing > ( \time() - $timeout )) {
			return false;
		}

		/**
		 * 逾時殘鎖（前一個 request 中途 fatal / OOM / 被 kill）→ 原子性接管。
		 *
		 * 不可用「delete 再 INSERT IGNORE」——那是 check-then-act：兩個 request 同時
		 * 看到同一筆殘鎖，會雙雙 delete、雙雙 insert 成功（B 的 delete 可能刪掉 A 剛插入的那筆），
		 * 兩邊都拿到鎖，正是這個鎖要防的重複開站。
		 *
		 * 改用條件式 UPDATE：WHERE option_value = 我讀到的那個舊值。
		 * MySQL 保證只有一個 request 的 UPDATE 會 affect 到 1 row，另一個 affect 0 row。
		 */
		$now = (string) \time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$taken = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s /* PP SITE SYNC LOCK TAKEOVER */",
				$now,
				$lock_key,
				(string) $existing
			)
		);

		\wp_cache_delete($lock_key, 'options');
		\wp_cache_delete('notoptions', 'options');

		if (1 !== (int) $taken) {
			// 另一個 request 搶先接管了這把殘鎖
			return false;
		}

		Plugin::logger(
			"開站鎖 {$lock_key} 已逾時，已原子性接管",
			'error',
			[
				'lock_key'  => $lock_key,
				'locked_at' => (int) $existing,
			]
		);

		return true;
	}

	/**
	 * 釋放開站併發鎖
	 *
	 * @param string $lock_key 鎖的 option name
	 * @return void
	 */
	private static function release_lock( string $lock_key ): void {
		\delete_option($lock_key);
	}

	/**
	 * 開站回應是否代表成功
	 *
	 * WPCD 成功回 200、PowerCloud 成功回 201，故以 2xx 區間判定
	 * （與 FetchPowerCloud::disable_site() / enable_site() 的既有慣例一致，見 issue #13）。
	 *
	 * ⚠️ 這個判斷同時決定三件事，三者必須永遠一致，否則會產生無法自動恢復的死局：
	 *   1. site_sync_powercloud() 是否綁定站台、寫 pp_site_url、排開站通知信
	 *   2. 是否對該 order item 落下冪等旗標（落了就永久擋住重試）
	 *   3. 訂單備註寫「開站成功」還是「開站失敗」
	 *
	 * @param int $status HTTP status code
	 * @return bool
	 */
	private static function is_successful_status( int $status ): bool {
		return $status >= 200 && $status < 300;
	}

	/**
	 * 開站回應的 data 正規化成陣列
	 *
	 * WPCD（Fetch::site_sync）回傳的是 json_decode 未帶 assoc 的 stdClass，
	 * PowerCloud（FetchPowerCloud::site_sync）回傳 assoc array。
	 * 兩者存進同一份 meta，讀取端的 is_array() 對 stdClass 判 false → 訂單備註變成空字串。
	 *
	 * @param mixed $data 原始 data
	 * @return array<string, mixed>
	 */
	private static function normalize_response_data( mixed $data ): array {
		if (is_array($data)) {
			/** @var array<string, mixed> $data */
			return $data;
		}

		if (! is_object($data)) {
			return [];
		}

		$decoded = \json_decode( (string) \wp_json_encode($data), true );

		/** @var array<string, mixed> $result */
		$result = is_array($decoded) ? $decoded : [];

		return $result;
	}

	/**
	 * 把 email_payloads_tmp 正規化成 FIFO 佇列
	 *
	 * 舊格式（單筆 assoc）自動升級為 [單筆]，涵蓋升級當下已排程但尚未執行的
	 * powerhouse_delay_send_email action——少了這個分支，那些排程會全部漏信。
	 *
	 * @param mixed $raw meta 原值
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_payload_queue( mixed $raw ): array {
		if (! is_array($raw) || ! $raw) {
			return [];
		}

		/** @var array<int, array<string, mixed>> $queue */
		$queue = \array_is_list($raw) ? $raw : [ $raw ];

		return $queue;
	}

	/**
	 * PowerCloud 開站
	 *
	 * @param int                  $product_id 商品 ID
	 * @param \WC_Subscription     $subscription 訂閱物件
	 * @param array<string, mixed> $site_sync_params 開站參數
	 *
	 * @return object{status: int, message: string, data: mixed} 回傳 API 回應物件
	 *
	 * @throws \InvalidArgumentException 當訂閱的父訂單無效時拋出異常
	 */
	private static function site_sync_powercloud( int $product_id, \WC_Subscription $subscription, array $site_sync_params ) {
		$parent_order = $subscription->get_parent();
		if (! $parent_order instanceof \WC_Order) {
			throw new \InvalidArgumentException('Invalid parent order in subscription.');
		}
		$open_site_plan_id = (string) \get_post_meta($product_id, LinkedSites::OPEN_SITE_PLAN_FIELD_NAME, true);
		$template_site_id  = (string) \get_post_meta($product_id, LinkedSites::LINKED_SITE_FIELD_NAME, true);

		// 新架構：使用 FetchPowerCloud::site_sync
		[$response_obj, $wordpress_obj] = FetchPowerCloud::site_sync($site_sync_params, $open_site_plan_id, $template_site_id);

		/**
		 * 發送 email 給用戶，告知網站已建立成功。
		 *
		 * 判斷必須與呼叫端落冪等旗標的條件完全一致——否則會出現
		 * 「旗標已落、但沒綁站也沒寄帳密」的死局：旗標一旦寫入，
		 * 後續每次合法重試都會被擋，客戶永遠拿不到站台。
		 * 用 2xx 而非 === 201 也與專案既有慣例一致（見 FetchPowerCloud::disable_site()）。
		 */
		if (self::is_successful_status( (int) $response_obj->status )) {
			// Store websiteId in pp_linked_site_ids for subscription binding
			$website_id = $response_obj->data['websiteId'] ?? '';
			if (!empty($website_id)) {
				$existing_site_ids = ShopSubscription::get_linked_site_ids((int) $subscription->get_id());
				$existing_site_ids_values = array_values($existing_site_ids);
				if (!in_array((string) $website_id, $existing_site_ids_values, true)) {
					$existing_site_ids_values[] = (string) $website_id;
				}
				ShopSubscription::update_linked_site_ids(
					(int) $subscription->get_id(),
					$existing_site_ids_values
				);
			}

			$site_url = 'https://' . $wordpress_obj->domain;

			/**
			 * Issue #23：把網域落地。
			 *
			 * $wordpress_obj->domain 是 FetchPowerCloud::site_sync() 本地生成的
			 * （$namespace . '.wpsite.pro'），PowerCloud 的回應 body 裡沒有它。
			 * 原本它只被塞進一次性的 email_payloads_tmp（寄信後即刪），
			 * 所以 ##URL## 在 PowerCloud 架構下永遠取不到值。
			 *
			 * 只在尚未寫入時寫——多商品訂閱時固定指向第一個站（見 SITE_URL_META_KEY 註解）。
			 */
			if ( '' === (string) $subscription->get_meta( self::SITE_URL_META_KEY, true ) ) {
				$subscription->update_meta_data( self::SITE_URL_META_KEY, $site_url );
			}

			$order_token = Token::get_order_tokens($parent_order);

			// 拿到 email payloads
			$email_payloads = \array_merge(
				$order_token,
				[
					'CUSTOMER_ID'                    => $parent_order->get_customer_id(),
					'REF_ORDER_ID'                   => $parent_order->get_id(),
					'WORDPRESSAPPWCSITESACCOUNTPAGE' => '',
					'IPV4'                           => '163.61.60.30',
					'DOMAIN'                         => $site_url,
					'FRONTURL'                       => $site_url,
					'ADMINURL'                       => $site_url . '/wp-admin',
					'URL'                            => $site_url, // issue #23：開站信模板也能用 ##URL##
					'SITEUSERNAME'                   => $wordpress_obj->wp_admin_email,
					'SITEPASSWORD'                   => $wordpress_obj->wp_admin_password,
					'NEW_SITE_ID'                    => '',
				]
			);

			/**
			 * Issue #24 殘留風險：一張訂單多商品各開一站是合法路徑，
			 * 但兩個 powerhouse_delay_send_email 排程的 args 完全相同
			 * （同一個 to、同一個 subscription_id），無法區分。
			 *
			 * 原本用單一 assoc meta，第二站直接覆蓋第一站 → 先跑的排程寄出第二站帳密並刪 meta
			 * → 第二個排程讀不到 → 第一站的帳密永久遺失。
			 *
			 * 改成 FIFO 佇列，不動 powerhouse_delay_send_email 的 hook 合約
			 * （該 hook 名不屬於本 plugin 的命名空間，改 args 會動到跨 plugin 合約）。
			 */
			$queue   = self::normalize_payload_queue($subscription->get_meta('email_payloads_tmp'));
			$queue[] = $email_payloads;
			$subscription->update_meta_data('email_payloads_tmp', $queue);
			$subscription->save();

			\as_schedule_single_action(
				\time() + 240,
				'powerhouse_delay_send_email',
				[
					'to'              => $wordpress_obj->wp_admin_email,
					'subscription_id' => $subscription->get_id(),
				]
			);
		}

		return $response_obj;
	}

	/**
	 * 讀取 pp_create_site_responses（唯一 accessor）
	 *
	 * 這份 meta 的實際結構是 list：[{"status":..,"message":..,"data":{..}}]。
	 * Issue #23 之前，Order.php 讀 [0]['data']、Token.php 讀 ['data']——
	 * 同一個 meta key 兩種讀法，後者必然取不到值。
	 * 所有讀取端一律走這裡，不要再各自 json_decode。
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_create_site_responses( \WC_Order $order ): array {
		$raw = $order->get_meta( self::CREATE_SITE_RESPONSES_META_KEY, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return [];
		}

		$decoded = \json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}

		/** @var array<int, array<string, mixed>> $responses */
		$responses = \array_values( \array_filter( $decoded, 'is_array' ) );

		return $responses;
	}

	/**
	 * 取得第 0 筆開站回應的 data
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed> 取不到時回傳空陣列
	 */
	public static function get_first_site_response_data( \WC_Order $order ): array {
		$responses = self::get_create_site_responses( $order );
		$first     = $responses[0] ?? [];
		$data      = $first['data'] ?? [];

		/** @var array<string, mixed> $result */
		$result = is_array( $data ) ? $data : [];

		return $result;
	}

	/**
	 * 從開站回應的 data 撈站台網址
	 *
	 * 欄位優先序刻意對齊既有的 DailyBillingCron::DOMAIN_KEYS / resolve_domain()，不發明第三套。
	 *
	 * @param array<string, mixed> $data 開站回應的 data
	 * @return string 缺 scheme 時補 https://；取不到時回傳空字串
	 */
	public static function extract_site_url( array $data ): string {
		foreach ( [ 'url', 'primaryDomain', 'domain', 'subDomain', 'wildcardDomain' ] as $key ) {
			$value = $data[ $key ] ?? null;
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = \trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}

			return \preg_match( '#^https?://#i', $value ) ? $value : 'https://' . $value;
		}

		return '';
	}

	/**
	 * Get the related order IDs for a subscription based on an order type.
	 *
	 * @param \WC_Subscription $subscription Subscription object.
	 * @param string           $order_type Can include 'any', 'parent', 'renewal', 'resubscribe' and/or 'switch'. Defaults to 'any'.
	 * @return array<int, int> List of related order IDs.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
	 * @deprecated 應該可以刪除了
	 */
	public function get_related_order_ids( $subscription, $order_type = 'any' ) {

		$related_order_ids = [];

		if (in_array($order_type, [ 'any', 'parent' ]) && $subscription->get_parent_id()) {
			$related_order_ids[ $subscription->get_parent_id() ] = $subscription->get_parent_id();
		}

		if ('parent' !== $order_type) {
			$relation_types = ( 'any' === $order_type ) ? [ 'renewal', 'resubscribe', 'switch' ] : [ $order_type ];

			foreach ($relation_types as $relation_type) {
				$related_order_ids = array_merge($related_order_ids, \WCS_Related_Order_Store::instance()->get_related_order_ids($subscription, $relation_type));
			}
		}

		return $related_order_ids;
	}

	/**
	 * 鎖競爭後的延後重試（issue #24）
	 *
	 * 只在 acquire_lock() 失敗時排程。此時鎖必然已失效（排程時間 = timeout + 60 秒），
	 * 所以這次一定拿得到鎖；真正決定「要不要重開」的是 order item 上的冪等旗標：
	 *   - 前一個程序成功跑完 → 旗標已落 → 逐項略過，只留一筆 order note
	 *   - 前一個程序中途死掉 → 旗標沒落 → 正常把站開出來
	 *
	 * 注意 site_sync_by_subscription() 的三道前置守衛在此仍然生效——
	 * 若這段期間內產生了續訂訂單，count($order_ids) !== 1 會擋下，這是正確行為。
	 *
	 * @param int|string $subscription_id 訂閱 ID
	 * @return void
	 */
	public function retry_site_sync_after_lock( int|string $subscription_id ): void {
		$subscription = \wcs_get_subscription($subscription_id);
		if (! ( $subscription instanceof \WC_Subscription )) {
			Plugin::logger(
				"鎖競爭重試：找不到訂閱 #{$subscription_id}",
				'error',
				[ 'subscription_id' => $subscription_id ]
			);
			return;
		}

		Plugin::logger(
			"鎖競爭重試：重新嘗試訂閱 #{$subscription->get_id()} 的開站",
			'info',
			[ 'subscription_id' => $subscription->get_id() ]
		);

		$this->site_sync_by_subscription($subscription, []);
	}

	/**
	 * 延遲寄送 email
	 *
	 * @param string     $to 收件者
	 * @param int|string $subscription_id 訂閱 ID
	 * @return void
	 */
	public function send_email( string $to, int|string $subscription_id ): void {
		$subscription = \wcs_get_subscription($subscription_id);
		if (! $subscription) {
			return;
		}

		$queue = self::normalize_payload_queue($subscription->get_meta('email_payloads_tmp'));
		if (! $queue) {
			return;
		}

		/** @var array<string, string> $payload */
		$payload = $queue[0];

		[ $success_emails, $failed_emails ] = EmailService::send_mail($to, $payload);

		/**
		 * 寄送失敗時「不」消費佇列（issue #21 與 #24 的交互作用）。
		 *
		 * 原因：send_mail() 除了 wp_mail 本身失敗，還會在關鍵站台變數缺失時「主動中止寄送」
		 * （issue #21 的防呆）。而這份 payload 是 wp_admin_password 唯一的存放處——
		 * 一旦 array_shift 後刪掉 meta，密碼就永久遺失，沒有任何補寄路徑。
		 * 保留 payload，讓管理員修正模板或設定後還能重試。
		 */
		if ($failed_emails) {
			Plugin::logger(
				"訂閱 #{$subscription->get_id()} 開站通知信未成功寄出，保留 email_payloads_tmp 以便補寄",
				'error',
				[
					'to'             => $to,
					'failed_emails'  => $failed_emails,
					'success_emails' => $success_emails,
					'queue_size'     => count($queue),
				],
				5
			);
			return;
		}

		\array_shift($queue);

		if ($queue) {
			// 同一訂閱還有其他站的帳密沒寄，留給下一個排程
			$subscription->update_meta_data('email_payloads_tmp', $queue);
		} else {
			$subscription->delete_meta_data('email_payloads_tmp');
		}
		$subscription->save();
	}
}
