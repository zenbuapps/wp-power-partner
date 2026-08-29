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

	/**
	 * 開站通知信最多嘗試幾次（issue #24）
	 *
	 * 排程數量與 payload 數量是一對一的（每開一個站排一個 powerhouse_delay_send_email），
	 * 所以「失敗就原地保留不消費」會把排程額度用光而佇列原地不動——
	 * 多商品訂單時，頭部那筆若持續失敗，後面的站永遠輪不到（head-of-line blocking）。
	 * 改為「失敗 → 移到隊尾 + 排一次延後重試」，並以此上限保證重試次數有限。
	 */
	const EMAIL_PAYLOAD_MAX_ATTEMPTS = 3;

	/** Payload 內部欄位：已嘗試寄送次數。不是 token，寄信前會被剝掉 */
	const EMAIL_PAYLOAD_ATTEMPTS_KEY = '_pp_send_attempts';

	/**
	 * Payload 內部欄位：這份 payload 已經寄達過的 email key。不是 token，寄信前會被剝掉
	 *
	 * 重試是把「整份 payload」重排，所以站台設了多個 site_sync 模板時
	 * （A 寄達、B 失敗），沒有這份清單就會在每次重試把 A 再寄給客戶一次。
	 * 記 key 而不是 action_name——所有開站模板的 action_name 都是 'site_sync'，分不出是哪一封。
	 */
	const EMAIL_PAYLOAD_SENT_KEYS_KEY = '_pp_sent_email_keys';

	/** 開站通知信寄送失敗後的重試間隔（秒） */
	const EMAIL_RETRY_DELAY = 600;

	/**
	 * 顯示層遮罩用的敏感 key 關鍵字（不分大小寫子字串比對）
	 *
	 * 開站回應的 data 是對端 API 的回應原文，可能把建立請求裡的憑證原樣 echo 回來
	 * （PowerCloud 的建站請求帶 wordpress.autoInstall.adminPassword）。
	 * 訂單備註與訂單列表欄位都是經銷商可見、且訂單備註會出現在 WooCommerce 的
	 * 訂單備註 REST API，明文寫進去等於把客戶站台的後台密碼放在沒有存取控制假設的地方。
	 *
	 * 用「key 關鍵字」而不是允許清單：對端欄位會演進，允許清單漏一個就是洩漏，
	 * 關鍵字漏一個只是多顯示一個非敏感欄位——兩種錯誤的代價不對稱。
	 *
	 * ⚠️ 只用於「顯示」。存進 meta 的 data 保持原文，因為 DisableHooks /
	 *    DisableSiteScheduler 的相容 fallback 要從裡面讀 websiteId。
	 *
	 * @var array<string>
	 */
	const SENSITIVE_KEY_PATTERNS = [ 'password', 'passwd', 'secret', 'token', 'apikey', 'api_key', 'credential', 'privatekey', 'private_key' ];

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

		$lock_key   = '';
		$lock_value = null;

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
			$lock_key   = self::SITE_SYNC_LOCK_PREFIX . $parent_order_id;
			$lock_value = self::acquire_lock($lock_key, self::SITE_SYNC_LOCK_TIMEOUT);

			if (null === $lock_value) {
				// 鎖值是 `{timestamp}:{隨機}`，取前半（舊版純時間戳同樣取得到）
				$locked_at = (int) \explode( ':', (string) \get_option($lock_key) )[0];
				$note      = \sprintf(
					'偵測到重複的開站請求：訂單 #%1$d 另一個開站程序仍在進行中（起始於 %2$s），本次略過，未呼叫開站 API',
					$parent_order_id,
					$locked_at ? \wp_date('Y-m-d H:i:s', $locked_at) : '未知時間'
				);
				$subscription->add_order_note($note);
				$parent_order->add_order_note($note);
				Plugin::logger(
					// 防護「生效」不是故障：付款事件重送在部分金流是常態，記 error 會淹沒真正的錯誤
					$note,
					'warning',
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
				 *
				 * ⚠️ 去重要用 as_has_scheduled_action()，不可用 as_schedule_single_action()
				 *    的 $unique 參數。ActionScheduler 的 unique 判斷
				 *    （ActionScheduler_DBStore::build_where_clause_for_insert）只比對
				 *    hook 與 group_id，args 完全不進 WHERE：
				 *        WHERE status IN ('pending','in-progress') AND hook = %s AND group_id = %d
				 *    group 留空即 group_id = 0，等於「全站只允許一個 pp_site_sync_retry_after_lock」。
				 *    訂閱 A 正在等重試的這 960 秒內，訂閱 B（不同客戶）若也撞到鎖，
				 *    B 的 insert 會回 0 被靜默丟棄，而回傳值沒有任何人檢查
				 *    → B 的客戶付了錢、永遠沒有站，且不留痕跡。
				 *    as_has_scheduled_action() 才會把 args 納入查詢（AS functions.php:411）。
				 */
				$retry_args = [ 'subscription_id' => $subscription->get_id() ];

				if (! \as_has_scheduled_action(self::RETRY_AFTER_LOCK_ACTION, $retry_args)) {
					\as_schedule_single_action(
						\time() + self::SITE_SYNC_LOCK_TIMEOUT + MINUTE_IN_SECONDS,
						self::RETRY_AFTER_LOCK_ACTION,
						$retry_args
					);
				}

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
						// 同上：冪等旗標擋下重送是預期路徑，不是錯誤
						$note,
						'warning',
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
					/**
					 * log 也是顯示層，一樣要遮罩。
					 *
					 * $responses 的 data 是對端 API 回應原文，而 PowerCloud 的建站請求帶
					 * wordpress.autoInstall.adminPassword，回應可能原樣 echo 回來。
					 * 訂單備註、訂單列表欄位、metabox 三處都已經過 mask_sensitive()，
					 * 唯獨這裡是明文——而 plugin log 經銷商看得到（Query Monitor / log 檢視器），
					 * 等於留下唯一一份明文副本，且是 info 等級（最不會被清）。
					 * 對照 CLAUDE.md 陷阱 12：API key 禁止 raw 落地 log，同一個原則。
					 */
					'responses' => self::mask_sensitive($responses),
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
				// 憑證類欄位在寫進訂單備註前一律遮罩（見 SENSITIVE_KEY_PATTERNS）
				$display_data = self::mask_sensitive($response['data']);
				$display_data = is_array($display_data) ? $display_data : [];

				if (self::is_successful_status($first_status)) {
					$note = '';
					foreach ($display_data as $key => $value) {
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
						(string) \wp_json_encode($display_data)
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
			if (null !== $lock_value) {
				self::release_lock($lock_key, $lock_value);
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
	 * ⚠️ 回傳的是「我寫進去的那個值」而不是 bool——release_lock() 必須拿它做條件式刪除，
	 * 　　否則逾時接管之後，原持有者的 finally 會把接管者的鎖刪掉（見 release_lock() 註解）。
	 *
	 * 鎖值格式是 `{unix_timestamp}:{隨機字串}`：
	 *   - 前半供逾時判定（(int) 或 explode 都取得到）
	 *   - 後半是持有者身分，讓 release_lock() 的條件式刪除能分辨「這是不是我的鎖」。
	 *     只用時間戳不夠——秒級精度下，接管者寫入的值可能與被接管者完全相同，
	 *     條件式刪除就會誤判成自己的而刪掉別人的鎖。
	 *
	 * @param string $lock_key 鎖的 option name
	 * @param int    $timeout  殘鎖判定門檻（秒）
	 * @return string|null 取得鎖時回傳寫入的鎖值（`{timestamp}:{隨機}`），沒取得回傳 null
	 */
	private static function acquire_lock( string $lock_key, int $timeout ): ?string {
		global $wpdb;

		$now = \time() . ':' . \wp_generate_password( 12, false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no') /* PP SITE SYNC LOCK */",
				$lock_key,
				$now
			)
		);

		if ($inserted) {
			// INSERT IGNORE 繞過 WP 的 options cache，不清 notoptions 會讓後續 get_option() 讀到 false
			\wp_cache_delete('notoptions', 'options');
			return $now;
		}

		\wp_cache_delete($lock_key, 'options');
		\wp_cache_delete('notoptions', 'options');
		$existing = \get_option($lock_key);

		if (! $existing) {
			return null;
		}

		// 鎖還在有效期內 → 真的有另一個開站程序在跑
		// 鎖值是 `{timestamp}:{隨機}`，取前半判定逾時（舊版純時間戳的鎖值同樣取得到）
		$locked_at = (int) \explode( ':', (string) $existing )[0];
		if ( $locked_at > ( \time() - $timeout )) {
			return null;
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
			return null;
		}

		Plugin::logger(
			"開站鎖 {$lock_key} 已逾時，已原子性接管",
			'warning',
			[
				'lock_key'  => $lock_key,
				'locked_at' => $locked_at,
			]
		);

		return $now;
	}

	/**
	 * 釋放開站併發鎖
	 *
	 * ⚠️ 必須是條件式刪除（DELETE ... WHERE option_value = 我寫進去的那個值），
	 *    不可用 delete_option()。理由與接管用條件式 UPDATE 的理由是同一個：
	 *
	 *    A 於 T0 取得鎖，開站 API 慢（timeout 600 秒，ActionScheduler 下沒有 max_execution_time）
	 *    跑到 T0+901；B 在 T0+901 判定殘鎖並原子接管（鎖值改成 T0+901）；A 在 T0+905 完成，
	 *    finally 若無條件 delete_option() 就會把「B 正在持有」的鎖刪掉——保護窗口提前消失，
	 *    此時抵達的第三個付款重送事件會拿到全新的鎖，而 B 還沒寫下冪等旗標，於是重複開站。
	 *
	 *    條件式刪除讓 A 的 DELETE affect 0 row（option_value 已經不是 A 的值），鎖留給 B。
	 *
	 * @param string $lock_key   鎖的 option name
	 * @param string $lock_value acquire_lock() 回傳的鎖值
	 * @return void
	 */
	private static function release_lock( string $lock_key, string $lock_value ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$wpdb->options}` WHERE `option_name` = %s AND `option_value` = %s /* PP SITE SYNC LOCK RELEASE */",
				$lock_key,
				$lock_value
			)
		);

		\wp_cache_delete($lock_key, 'options');
		\wp_cache_delete('notoptions', 'options');
	}

	/**
	 * 開站回應是否代表成功
	 *
	 * WPCD 成功回 200、PowerCloud 成功回 201，故以 2xx 區間判定
	 * （與 FetchPowerCloud::disable_site() / enable_site() 的既有慣例一致，見 issue #13）。
	 *
	 * ⚠️ 這個判斷同時決定三件事，三者必須永遠一致，否則會產生無法自動恢復的死局：
	 *   1. site_sync_powercloud() 是否寫 pp_site_url、排開站通知信
	 *   2. 是否對該 order item 落下冪等旗標（落了就永久擋住重試）
	 *   3. 訂單備註寫「開站成功」還是「開站失敗」
	 *
	 * ⚠️ 綁定站台（pp_linked_site_ids）比這三者「多一個條件」：回應要真的帶回 websiteId。
	 *    2xx 卻沒有 websiteId 時，上述三件事照做、綁定做不了——那是不對稱但刻意的選擇，
	 *    見 site_sync_powercloud() 內該分支的 else 註解（放行重試會重複開站與計費）。
	 *    該路徑會寫 critical log + 訂單/訂閱備註，要求經銷商手動補綁，不是靜默通過。
	 *
	 * @param int $status HTTP status code
	 * @return bool
	 */
	public static function is_successful_status( int $status ): bool {
		return $status >= 200 && $status < 300;
	}

	/**
	 * 顯示前遮罩憑證類欄位（見 SENSITIVE_KEY_PATTERNS）
	 *
	 * @param mixed  $value 原始值
	 * @param string $key   該值所在的 key（頂層呼叫留空）
	 * @return mixed 遮罩後的值
	 */
	public static function mask_sensitive( mixed $value, string $key = '' ) {
		if ( '' !== $key ) {
			foreach ( self::SENSITIVE_KEY_PATTERNS as $pattern ) {
				if ( false !== \stripos( $key, $pattern ) ) {
					return '***';
				}
			}
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		$masked = [];
		foreach ( $value as $k => $v ) {
			$masked[ $k ] = self::mask_sensitive( $v, (string) $k );
		}

		return $masked;
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
			$website_id = is_array($response_obj->data) ? ( $response_obj->data['websiteId'] ?? '' ) : '';
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
			} else {
				/**
				 * 2xx 但回應裡沒有 websiteId —— 必須主動告警，這是無法自動恢復的狀態。
				 *
				 * FetchPowerCloud::site_sync() 的 data 是 json_decode($body, true)，
				 * 空 body / 非 JSON / 對端改欄位名都會讓它變成 null 或缺 key；
				 * 而成功判定已從 === 201 放寬為 2xx，200 / 202（非同步受理）也會走到這裡。
				 *
				 * 此時 pp_linked_site_ids 是空的 → is_site_sync() 為 false →
				 * 停用/恢復、所有生命週期信、issue #22 補排全部失效，
				 * 而呼叫端仍會落下冪等旗標，之後每一次合法重試都被擋。
				 *
				 * 刻意「照樣落旗標」而不是放行重試：重試會讓 PowerCloud 再建一個站
				 * （計費、資源、客戶收到第二組帳密都是不可逆的），而缺的只是一個 id——
				 * 經銷商可從 PowerCloud 後台以 namespace 查到 websiteId，
				 * 用後台 metabox 或 POST /change-subscription 手動補綁即可收斂。
				 * 代價不對稱，所以選「可修復但需人工」而不是「自動但可能重複開站」。
				 */
				$note = \sprintf(
					'開站 API 回應成功（HTTP %1$d）但未帶 websiteId，訂閱 #%2$d 未能綁定站台。'
					. '此訂閱的停用/恢復與生命週期信都不會作用，請至 PowerCloud 後台以 namespace「%3$s」查出 websiteId 後手動綁定。',
					(int) $response_obj->status,
					$subscription->get_id(),
					(string) ( $wordpress_obj->namespace ?? '' )
				);
				$subscription->add_order_note($note);
				$parent_order->add_order_note($note);
				Plugin::logger(
					$note,
					'critical',
					[
						'subscription_id' => $subscription->get_id(),
						'order_id'        => $parent_order->get_id(),
						'status'          => (int) $response_obj->status,
						'namespace'       => (string) ( $wordpress_obj->namespace ?? '' ),
						// data 是對端原文，可能 echo 回 adminPassword，遮罩後才進 log
						'response_data'   => self::mask_sensitive(self::normalize_response_data($response_obj->data ?? null)),
					],
					5
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
	 * 取得「代表這張訂單」的那一筆開站回應 data
	 *
	 * 優先序：第一筆 HTTP 2xx 的回應 → 沒有成功的才退回第 0 筆。
	 *
	 * 為什麼不是單純的第 0 筆：site_sync_by_subscription() 對 pp_create_site_responses
	 * 是「與既有紀錄合併」而非覆寫（多商品部分重試時才不會把第一個站的紀錄擠掉），
	 * 於是「第一次開站失敗（未落冪等旗標）→ 合法重試成功」的訂單，
	 * meta 會長成 [失敗, 成功]，第 0 筆永遠是那筆失敗的。
	 * 這個 accessor 同時餵給訂單列表欄位、metabox 與 Token 的 ##URL## fallback，
	 * 取到失敗那筆會讓後台一直顯示錯誤資訊、經銷商誤以為站沒開成功。
	 * （master 是無條件覆寫，最新的勝出；改成合併後必須由讀取端補上這個優先序。）
	 *
	 * 全部都失敗時仍回第 0 筆——錯誤內容本身是有價值的追查資訊，不該被吞成空陣列。
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed> 取不到時回傳空陣列
	 */
	public static function get_first_site_response_data( \WC_Order $order ): array {
		$responses = self::get_create_site_responses( $order );

		foreach ( $responses as $response ) {
			if ( ! self::is_successful_status( (int) ( $response['status'] ?? 0 ) ) ) {
				continue;
			}

			$data = $response['data'] ?? [];
			if ( is_array( $data ) && $data ) {
				/** @var array<string, mixed> $data */
				return $data;
			}
		}

		$first = $responses[0] ?? [];
		$data  = $first['data'] ?? [];

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

		/**
		 * 先把頭部取出來，佇列一定會前進。
		 *
		 * 舊版是「失敗就原地 return 不消費」，用意是保住 payload（它是 wp_admin_password
		 * 唯一的存放處，刪掉就永久遺失）。但排程與 payload 是一對一的，原地不動等於
		 * 把排程額度燒掉而佇列沒動——多商品訂單時，頭部持續失敗會讓後面的站一起卡死，
		 * 而且沒有任何機制會再回來讀這份 meta。
		 *
		 * 現在改成：失敗 → 計數 +1、payload 移到隊尾、排一次延後重試；
		 * 連續失敗達 EMAIL_PAYLOAD_MAX_ATTEMPTS 次才真的丟棄（並記 critical）。
		 * 保住 payload 的原意仍在，只是有了明確的終止條件與後續路徑。
		 */
		/** @var array<string, mixed> $payload */
		$payload  = (array) \array_shift($queue);
		$attempts = (int) ( $payload[ self::EMAIL_PAYLOAD_ATTEMPTS_KEY ] ?? 0 );

		// 前幾輪已經寄達的模板，這一輪要跳過（見 EMAIL_PAYLOAD_SENT_KEYS_KEY）
		$sent_keys_raw = $payload[ self::EMAIL_PAYLOAD_SENT_KEYS_KEY ] ?? [];
		$sent_keys     = is_array($sent_keys_raw) ? array_values(array_map(static fn( $v ): string => (string) $v, $sent_keys_raw)) : [];

		// 內部欄位不是 token，寄信前剝掉，免得被當成 ##_PP_SEND_ATTEMPTS## 之類處理
		$tokens = $payload;
		unset( $tokens[ self::EMAIL_PAYLOAD_ATTEMPTS_KEY ], $tokens[ self::EMAIL_PAYLOAD_SENT_KEYS_KEY ] );

		$success_emails = [];
		$failed_emails  = [];
		$aborted_emails = [];
		$success_keys   = [];

		/**
		 * ⚠️ 例外必須在這裡接住，不能讓它往上拋。
		 *
		 * payload 已經被 array_shift 取出，但佇列的新狀態要等函式尾端的
		 * $subscription->save() 才落地。send_mail() 一旦拋例外
		 * （Token::replace() 撞到非預期值、寄信外掛自己 throw、Email DTO 出錯），
		 * meta 不會被改寫、不會排重試，而這個 powerhouse_delay_send_email 排程
		 * 已經被消耗掉了——排程數與 payload 數是一對一的，
		 * 雙站訂單因此會剩下一份「沒有任何排程會再去讀」的 payload，
		 * 而那是明文 wp_admin_password 唯一的存放處。
		 *
		 * 接住後走與「wp_mail 回 false」完全相同的計數/重排路徑。
		 */
		try {
			/** @var array<string, string> $tokens */
			[ $success_emails, $failed_emails, $aborted_emails, $success_keys ] = EmailService::send_mail(
				$to,
				$tokens,
				[
					// 前幾輪已寄達的模板不再寄一次（多模板站台才有差別）
					'skip_keys'              => $sent_keys,
					// 防呆告警只在第一次發：tokens 每輪都一樣，重試必然被同樣擋下，
					// 不去重的話經銷商會為同一件事收到 EMAIL_PAYLOAD_MAX_ATTEMPTS 封相同的信
					'notify_dealer_on_abort' => 0 === $attempts,
				]
			);
		} catch (\Throwable $th) {
			$success_emails = [];
			$failed_emails  = [ 'exception' ];
			$aborted_emails = [];
			$success_keys   = [];

			Plugin::logger(
				"訂閱 #{$subscription->get_id()} 開站通知信寄送過程拋出例外",
				'error',
				[
					'to'    => $to,
					'error' => $th->getMessage(),
				],
				5
			);
		}

		// 累積「已寄達」的模板，讓下一輪重試跳過它們
		$sent_keys = array_values( array_unique( array_merge( $sent_keys, $success_keys ) ) );

		if ($failed_emails) {
			++$attempts;

			if ($attempts < self::EMAIL_PAYLOAD_MAX_ATTEMPTS) {
				/**
				 * 放回隊尾，讓同一訂閱的其他站先寄出去。
				 *
				 * 這裡刻意「不分辨失敗原因」——防呆中止雖然重試必然再失敗，
				 * 但 payload 是 wp_admin_password 唯一的存放處，提前丟棄等於
				 * 讓經銷商連手動補寄的原始資料都沒有。噪音（重複告警）已由
				 * notify_dealer_on_abort 去重解決，代價遠低於資料遺失。
				 */
				$payload[ self::EMAIL_PAYLOAD_ATTEMPTS_KEY ]  = $attempts;
				$payload[ self::EMAIL_PAYLOAD_SENT_KEYS_KEY ] = $sent_keys;
				$queue[] = $payload;

				\as_schedule_single_action(
					\time() + self::EMAIL_RETRY_DELAY,
					'powerhouse_delay_send_email',
					[
						'to'              => $to,
						'subscription_id' => $subscription->get_id(),
					]
				);

				Plugin::logger(
					"訂閱 #{$subscription->get_id()} 開站通知信未成功寄出（第 {$attempts} 次），已移到佇列尾端並排定重試",
					'error',
					[
						'to'             => $to,
						'attempts'       => $attempts,
						'failed_emails'  => $failed_emails,
						'aborted_emails' => $aborted_emails,
						'success_emails' => $success_emails,
						'sent_keys'      => $sent_keys,
						'queue_size'     => count($queue),
					],
					5
				);
			} else {
				/**
				 * 達到上限才丟棄。這裡是唯一會讓 wp_admin_password 消失的路徑，
				 * 所以記 critical + 寫訂單備註——經銷商需要知道有客戶沒收到帳密，
				 * 得手動用 POST /send-site-credentials-email 補寄。
				 *
				 * 備註要講清楚是哪一種失敗，否則經銷商不知道該修模板還是查寄信設定。
				 */
				if ($aborted_emails) {
					$reason = \sprintf(
						'連續 %1$d 次寄送失敗，系統已停止重試。失敗原因是信件模板用到了這個站台架構拿不到的變數（%2$s），請先修正模板',
						$attempts,
						\implode(', ', $aborted_emails)
					);
				} elseif ($sent_keys) {
					$reason = \sprintf(
						'連續 %1$d 次寄送失敗，系統已停止重試。部分模板曾寄達（%2$s），仍有模板未寄出',
						$attempts,
						\implode(', ', $sent_keys)
					);
				} else {
					$reason = \sprintf('連續 %d 次寄送失敗，系統已停止重試', $attempts);
				}

				Plugin::logger(
					"訂閱 #{$subscription->get_id()} 開站通知信已放棄此筆（避免卡住佇列後方的站）：{$reason}",
					'critical',
					[
						'to'             => $to,
						'attempts'       => $attempts,
						'failed_emails'  => $failed_emails,
						'aborted_emails' => $aborted_emails,
						'success_emails' => $success_emails,
						'queue_size'     => count($queue),
					],
					5
				);

				$subscription->add_order_note(
					\sprintf(
						'開站通知信未能完整寄出：%1$s。請確認信件模板與寄信設定後，於後台手動補寄帳密給 %2$s',
						$reason,
						$to
					)
				);
			}
		}

		if ($queue) {
			// 同一訂閱還有其他站的帳密沒寄，留給下一個排程
			$subscription->update_meta_data('email_payloads_tmp', $queue);
		} else {
			$subscription->delete_meta_data('email_payloads_tmp');
		}
		$subscription->save();
	}
}
