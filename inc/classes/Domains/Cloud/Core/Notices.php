<?php

declare(strict_types=1);

namespace J7\PowerPartner\Domains\Cloud\Core;

use J7\PowerPartner\Domains\Cloud\Services\ConnectionService;
use J7\PowerPartner\Domains\Cloud\Services\CredentialStore;

/**
 * 需要經銷商動手時的 wp-admin 提示
 *
 * 只在「重新登入一次就能解決」時出現：自動遷移因帳密錯誤停止，或專屬密碼被 CloudServer 拒絕。
 * 兩種情況下功能都仍以共用帳號運作，所以文案明說「目前功能不受影響」，避免經銷商誤以為站壞了。
 */
final class Notices {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		\add_action( 'admin_notices', [ $this, 'render' ] );
	}

	/**
	 * @return void
	 */
	public function render(): void {
		if ( ! \current_user_can( 'manage_options' ) || ! self::should_prompt_relogin() ) {
			return;
		}

		$url = \admin_url( 'admin.php?page=power-partner' );
		printf(
			'<div class="notice notice-warning"><p><strong>Power Partner</strong>：新版安全連線尚未完成，請到 <a href="%1$s">Power Partner</a> 重新登入一次站長路可帳號以啟用專屬金鑰。目前功能不受影響。</p></div>',
			\esc_url( $url )
		);
	}

	/**
	 * @return bool
	 */
	public static function should_prompt_relogin(): bool {
		if ( CredentialStore::is_dealer_invalid() ) {
			return true;
		}
		$migration = ConnectionService::migration();
		return ConnectionService::MIGRATION_FAILED === $migration['status']
			&& ConnectionService::EXCHANGE_BAD_PASSWORD === $migration['reason'];
	}
}
