<?php

declare (strict_types = 1);

namespace J7\PowerPartner\Compatibility;

use J7\PowerPartner\Plugin;
use J7\Powerhouse\Domains\Subscription\Shared\Enums\Action;
use J7\Powerhouse\Domains\Subscription\Shared\Enums\Status;
use J7\PowerPartner\Domains\Settings\Core\WatchSettingHooks;
use J7\PowerPartner\Product\SiteSync;
use J7\PowerPartner\ShopSubscription;

/** Class Compatibility 不同版本間的相容性設定 */
final class Compatibility {
	use \J7\WpUtils\Traits\SingletonTrait;

	const AS_COMPATIBILITY_ACTION = 'power_partner_compatibility_scheduler';
	const OPTION_NAME             = 'power_partner_compatibility_scheduled';

	/** Issue #22 一次性補排的守門 option（不可用 $previous_version，見 backfill_issue22_subscription_emails 註解） */
	const ISSUE22_BACKFILL_OPTION = 'power_partner_issue22_backfilled';

	/** Issue #22 分批補排的 ActionScheduler hook */
	const ISSUE22_BACKFILL_ACTION = 'power_partner_issue22_backfill_batch';

	/** Issue #22 每批處理的訂閱數 */
	const ISSUE22_BACKFILL_BATCH_SIZE = 50;

	/** Constructor */
	public function __construct() {
		/**
		 * Issue #22 分批補排的 handler 必須綁在下面的 early return「之前」。
		 *
		 * 補排是跨多個 request 的：第一批排程之後，OPTION_NAME 已等於當前版本，
		 * 之後每個 request 都會走 early return——若綁在 return 之後，
		 * 後續批次的 ActionScheduler action 永遠找不到 callback，補排會停在第一批。
		 */
		\add_action( self::ISSUE22_BACKFILL_ACTION, [ __CLASS__, 'run_issue22_backfill_batch' ], 10, 1 );

		$scheduled_version = \get_option(self::OPTION_NAME);
		if (is_string($scheduled_version) && $scheduled_version === Plugin::$version) {
			return;
		}

		\delete_option(self::OPTION_NAME);

		// 升級成功後執行
		\add_action( 'upgrader_process_complete', [ __CLASS__, 'compatibility' ]);

		// 排程只執行一次的兼容設定
		\add_action( 'init', [ __CLASS__, 'compatibility_action_scheduler' ] );
		\add_action( self::AS_COMPATIBILITY_ACTION, [ __CLASS__, 'compatibility' ]);
	}


	/**
	 * 排程只執行一次的兼容設定
	 *
	 * @return void
	 */
	public static function compatibility_action_scheduler(): void {
		\as_enqueue_async_action( self::AS_COMPATIBILITY_ACTION, [] );
	}


	/**
	 * 執行排程
	 *
	 * @return void
	 */
	public static function compatibility(): void {
		/**
		 * ============== START 相容性代碼 ==============
		 */

		$previous_version = \get_option(self::OPTION_NAME, '0.0.1');
		$previous_version = is_string($previous_version) ? $previous_version : '0.0.1';

		// 3.1.0 之前版本要取消 email schedule 跟 cron 的排程
		if (version_compare($previous_version, '3.1.0', '<=')) {
			self::cancel_email_schedule();
			WatchSettingHooks::reschedule_all_subscription_email('power_partner_send_email');
			self::reschedule_disable_site_scheduler();
		}

		// issue #22：一次性補排既有訂閱的 next_payment / trial_end 信
		self::backfill_issue22_subscription_emails();

		/**
		 * ============== END 相容性代碼 ==============
		 */

		// ❗不要刪除此行，註記已經執行過相容設定
		\update_option(self::OPTION_NAME, Plugin::$version);
		\wp_cache_flush();
		Plugin::logger(Plugin::$version . ' 已執行兼容性設定', 'info', []);
	}

	/**
	 * 一次性補排既有訂閱的 next_payment / trial_end 信（issue #22）
	 *
	 * 3.5.1 以前，這幾種信對「新成立的訂閱」從來沒有排進 ActionScheduler——
	 * 排程的唯一入口是 woocommerce_subscription_date_updated，而它 fire 時
	 * pp_linked_site_ids 還沒寫入，schedule_email() 的 is_site_sync() 守門直接 return。
	 * 新的補排機制（監聽 pp_linked_site_ids_updated）只救「未來會綁定或重綁」的訂閱，
	 * 既有的受害訂閱要靠這裡補。
	 *
	 * 復用同一個 hook 而不是自己排程：單一程式路徑、天然 idempotent（下游的
	 * maybe_unschedule + schedule_single 是淨零成長），且狀態守門與「寄送時點已過就跳過」
	 * 全部生效。
	 *
	 * ⚠️ 不可改用 WatchSettingHooks::reschedule_all_subscription_email()——
	 *    它第一件事是 as_unschedule_all_actions($hook)，會把催繳信 / 成功信 / 結束信 /
	 *    customer_cancelled 一起清空，而那些信它不會重建。
	 *
	 * ⚠️ 守門用獨立 option key，不可用 $previous_version：constructor 在
	 *    版本不同時會 delete_option(self::OPTION_NAME)，所以 compatibility() 內讀到的
	 *    $previous_version 永遠是 '0.0.1'，version_compare 區塊每次升版都會跑。
	 *
	 * @return void
	 */
	private static function backfill_issue22_subscription_emails(): void {
		if (\get_option(self::ISSUE22_BACKFILL_OPTION)) {
			return;
		}

		// 立刻寫旗標再排程：compatibility() 綁在 upgrader_process_complete 上，
		// 任何外掛/佈景更新都會觸發，不先寫旗標會重複排程
		\update_option(self::ISSUE22_BACKFILL_OPTION, Plugin::$version);

		\as_enqueue_async_action(self::ISSUE22_BACKFILL_ACTION, [ 'page' => 1 ]);
	}

	/**
	 * 分批執行 issue #22 的補排
	 *
	 * 為什麼要分批：wcs_get_subscriptions() 會為每一列 hydrate 一個完整的 WC_Subscription
	 * 物件，再對每一筆做 ActionScheduler 寫入。訂閱數千筆的站台一次撈完會 OOM 或撞
	 * max_execution_time，而 compatibility() 是綁在 upgrader_process_complete 上的——
	 * 那是同步的 wp-admin 請求，任何外掛更新都會觸發。
	 *
	 * 每批處理完就排下一批，讓 ActionScheduler 自己控制節奏。
	 *
	 * @param int|string $page 頁碼（從 1 開始）
	 * @return void
	 */
	public static function run_issue22_backfill_batch( int|string $page = 1 ): void {
		if (! \function_exists('wcs_get_subscriptions')) {
			return;
		}

		$page          = max(1, (int) $page);
		$subscriptions = \wcs_get_subscriptions(
			[
				'subscription_status'    => [ 'active', 'on-hold' ],
				'subscriptions_per_page' => self::ISSUE22_BACKFILL_BATCH_SIZE,
				'paged'                  => $page,
				'meta_query'             => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => SiteSync::LINKED_SITE_IDS_META_KEY,
						'compare' => 'EXISTS',
					],
				],
			]
		);

		if (! $subscriptions) {
			Plugin::logger('issue #22 一次性補排完成（已無更多訂閱）', 'info', [ 'last_page' => $page ]);
			return;
		}

		$count = 0;
		foreach ($subscriptions as $subscription) {
			$site_ids = ShopSubscription::get_linked_site_ids( (int) $subscription->get_id() );
			// 傳真實的 site ids，符合 hook 的公開契約（新值 / 舊值）
			\do_action(
				ShopSubscription::LINKED_SITE_IDS_UPDATED_ACTION,
				$subscription,
				\array_values($site_ids),
				\array_values($site_ids)
			);
			++$count;
		}

		Plugin::logger(
			"issue #22 補排第 {$page} 批完成，處理 {$count} 筆訂閱",
			'info',
			[
				'page'  => $page,
				'count' => $count,
			]
		);

		// 還有可能有下一批
		if ($count >= self::ISSUE22_BACKFILL_BATCH_SIZE) {
			\as_enqueue_async_action(self::ISSUE22_BACKFILL_ACTION, [ 'page' => $page + 1 ]);
		}
	}

	/**
	 * 取消 email schedule 跟 cron 的排程
	 *
	 * @return void
	 */
	private static function cancel_email_schedule(): void {
		$hook = 'power_partner_daily_check';
		// 檢查是否已經有排程任務
		if (\as_has_scheduled_action($hook)) {
			// 已排程就取消
			\as_unschedule_all_actions($hook);
		}
	}



	/**
	 * 重新排程 disable site 的排程
	 *
	 * @return void
	 */
	private static function reschedule_disable_site_scheduler(): void {
		global $wpdb;

		try {

			$wpdb->query('START TRANSACTION');

			/** @var \ActionScheduler_DBStore $store */
			$store      = \ActionScheduler::store();
			$action_ids = $store->query_actions(
			[
				'hook'   => 'power_partner_disable_site',
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			]
					);

			foreach ($action_ids as $action_id) {
				$action          = $store->fetch_action($action_id);
				$subscription_id = $action->get_args()[0];
				$subscription    = \wcs_get_subscription($subscription_id);
				if ($subscription) {
					\do_action(
					Action::SUBSCRIPTION_FAILED->get_action_hook(),
					$subscription,
							[
								'from_status' => Status::CANCELLED->value,
								'to_status'   => Status::CANCELLED->value,
							]
								);
				}
			}

			\as_unschedule_all_actions('power_partner_disable_site');

			$wpdb->query('COMMIT');

		} catch (\Throwable $th) {
			$wpdb->query('ROLLBACK');
			Plugin::logger('ROLLBACK 重新排程 EMAILS 失敗: ' . $th->getMessage(), 'critical', [], 5);
		}
	}
}
