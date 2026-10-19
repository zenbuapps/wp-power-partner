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
		if ( null === $attention ) {
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
}
