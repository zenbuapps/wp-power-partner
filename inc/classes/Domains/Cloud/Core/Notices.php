<?php

declare(strict_types=1);

namespace J7\PowerPartner\Domains\Cloud\Core;

use J7\PowerPartner\Domains\Cloud\Services\ConnectionService;

/**
 * 需要經銷商動手時的 wp-admin 提示
 *
 * 判斷與文案統一由 ConnectionService::attention() 決定（AdminApp 的 MigrationAlert 讀同一份），
 * 這裡只負責顯示。
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
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		$attention = ConnectionService::attention();
		if ( null === $attention || ! self::in_scope( $attention['scope'] ) ) {
			return;
		}

		$url = \admin_url( 'admin.php?page=power-partner' );
		printf(
			'<div class="notice notice-warning"><p><strong>Power Partner</strong>：%1$s <a href="%2$s">前往 Power Partner</a></p></div>',
			\esc_html( $attention['message'] ),
			\esc_url( $url )
		);
	}

	/**
	 * @return bool
	 */
	public static function should_prompt_relogin(): bool {
		return null !== ConnectionService::attention();
	}

	/**
	 * 目前的 wp-admin 頁面是否在提示的顯示範圍內
	 *
	 * SCOPE_POWER_PARTNER 只在 Power Partner 自己的頁面與 wp-admin 首頁顯示：模板站或 clone 站可能帶著
	 * partner_id，「沒有可讀的憑證」這種提示會出現在終端客戶的後台，對方看不懂。
	 *
	 * @param string $scope ConnectionService::SCOPE_*
	 * @return bool
	 */
	public static function in_scope( string $scope ): bool {
		if ( ConnectionService::SCOPE_POWER_PARTNER !== $scope ) {
			return true;
		}
		$screen = \function_exists( 'get_current_screen' ) ? \get_current_screen() : null;
		if ( null === $screen ) {
			return false;
		}
		return 'dashboard' === $screen->id || str_contains( (string) $screen->id, 'power-partner' );
	}
}
