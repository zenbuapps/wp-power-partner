<?php

declare(strict_types=1);

namespace J7\PowerPartner\Domains\Cloud\Core;

use J7\PowerPartner\Api\Connect;
use J7\PowerPartner\Domains\Cloud\Services\ConnectionService;
use J7\PowerPartner\Domains\Cloud\Services\CredentialStore;
use J7\PowerPartner\Plugin;

/**
 * 背景自動遷移：把「共用帳號 + 經銷商登入帳密」換成「經銷商專屬應用程式密碼」
 *
 * 讓既有經銷商不需要做任何事。每日嘗試一次，直到成功或確定不可能成功：
 * - CloudServer 尚未支援 v3/connect（404）→ 明天再試（client 可能比 CloudServer 先升級）
 * - 帳密錯誤（401，經銷商改過密碼）→ 停止，不再重試（重試會觸發 CloudServer 的鎖帳號），
 *   改由 admin notice 請經銷商重新登入一次；在那之前一律走共用帳號，功能不受影響
 * - 不符經銷商資格（403 not_eligible）→ 停止，維持共用帳號（由 CloudServer 管理員決定是否放行）
 * - 換到的帳號不是本站綁定的經銷商 → 不保存、撤銷那把密碼、停止，請經銷商重新登入（以登入者為準）
 * - 429 / 5xx / 連線錯誤 → 明天再試
 *
 * 不寄信：通知經銷商由營運方自行處理。
 */
final class Migration {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string ActionScheduler hook（每日） */
	const HOOK = 'power_partner/3.6.0/cloud/migrate';

	/** @var int 外掛更新後第一次嘗試的延遲秒數（5 分鐘） */
	const FIRST_RUN_DELAY = 300;

	/** Constructor */
	public function __construct() {
		\add_action( 'init', [ $this, 'maybe_schedule' ] );
		\add_action( self::HOOK, [ __CLASS__, 'run' ] );
	}

	/**
	 * 尚未完成遷移時確保每日排程存在；完成後移除排程
	 *
	 * @return void
	 */
	public function maybe_schedule(): void {
		if ( ! \function_exists( 'as_next_scheduled_action' ) || ! \function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		$scheduled = false !== \as_next_scheduled_action( self::HOOK );

		if ( ! self::needs_migration() ) {
			if ( $scheduled && \function_exists( 'as_unschedule_all_actions' ) ) {
				\as_unschedule_all_actions( self::HOOK );
			}
			return;
		}

		if ( ! $scheduled ) {
			\as_schedule_recurring_action( time() + self::FIRST_RUN_DELAY, DAY_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * 是否還需要遷移
	 *
	 * @return bool
	 */
	public static function needs_migration(): bool {
		if ( null !== CredentialStore::get_dealer() && ! CredentialStore::is_dealer_invalid() ) {
			return false;
		}
		if ( null === CredentialStore::get_legacy_account() ) {
			return false;
		}
		// 帳密已被拒：再送一次只會在本站主機的 IP 上多累積一次失敗
		if ( CredentialStore::is_legacy_account_rejected() ) {
			return false;
		}
		$migration = ConnectionService::migration();
		return ConnectionService::MIGRATION_FAILED !== $migration['status'];
	}

	/**
	 * 執行一次遷移嘗試
	 *
	 * @return void
	 */
	public static function run(): void {
		if ( ! self::needs_migration() ) {
			return;
		}

		$account = CredentialStore::get_legacy_account();
		if ( null === $account ) {
			return;
		}

		// 保存的帳密可能屬於另一個經銷商（例如站台換過綁定），換到的專屬密碼必須是本站綁定的那一位
		$exchange = ConnectionService::exchange( $account['email'], $account['password'], (string) \get_option( Connect::PARTNER_ID_OPTION_NAME, '' ) );

		switch ( $exchange['result'] ) {
			case ConnectionService::EXCHANGE_OK:
				CredentialStore::delete_legacy_account();
				ConnectionService::set_migration( ConnectionService::MIGRATION_DONE );
				Plugin::logger( '已自動換成經銷商專屬應用程式密碼', 'info' );
				return;

			case ConnectionService::EXCHANGE_BAD_PASSWORD:
			case ConnectionService::EXCHANGE_NOT_ELIGIBLE:
			case ConnectionService::EXCHANGE_PARTNER_MISMATCH:
				if ( ConnectionService::EXCHANGE_BAD_PASSWORD === $exchange['result'] ) {
					CredentialStore::mark_legacy_account_rejected( $exchange['result'] );
				}
				ConnectionService::set_migration( ConnectionService::MIGRATION_FAILED, $exchange['result'] );
				Plugin::logger(
					'自動換取經銷商專屬密碼失敗，停止重試，維持共用帳號運作',
					'warning',
					[
						'reason'  => $exchange['result'],
						'message' => $exchange['message'],
					]
				);
				return;

			default:
				ConnectionService::set_migration( ConnectionService::MIGRATION_PENDING, $exchange['result'] );
				Plugin::logger(
					'暫時無法換取經銷商專屬密碼，明天再試',
					'info',
					[
						'reason'  => $exchange['result'],
						'message' => $exchange['message'],
					]
				);
		}
	}
}
