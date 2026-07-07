<?php

declare(strict_types=1);

namespace J7\PowerPartner\Domains\Email\DTOs;

use J7\WpUtils\Classes\DTO;
use J7\PowerPartner\Domains\Email\Shared\Enums;
use J7\Powerhouse\Domains\Subscription\Shared\Enums\Action;

/**
 * Class Email
 * 都用 string 是因為都是存在 option table 裡面，不需要多餘轉換
 *  */
final class Email extends DTO {
	/** @var string 信件 key 給前端 render 使用 */
	public string $key;

	/** @var string 是否啟用 */
	public string $enabled;

	/** @var string 信件主旨 */
	public string $subject;

	/** @var string 信件內容 */
	public string $body;

	/** @var string 信件動作名稱 */
	public string $action_name;

	/** @var string 信件天數 */
	public string $days;

	/** @var string 信件運算子 'after' | 'before' */
	public string $operator;

	/** @var bool 是否只發一次 */
	public bool $unique = false;

	/**
	 * @return void Validate
	 * @throws \Exception 如果驗證失敗
	 *  */
	protected function validate(): void {
		Enums\Operator::from( $this->operator );

		// site_sync 與 customer_cancelled 不是 Powerhouse Action enum 的成員，
		// 但都是本外掛合法的信件觸發點，需一併放行（customer_cancelled 見 issue #20）。
		$non_enum_actions = [ 'site_sync', 'customer_cancelled' ];
		if ( !Action::tryFrom( $this->action_name ) && !in_array( $this->action_name, $non_enum_actions, true ) ) {
			throw new \Exception('Invalid action_name, got: ' . $this->action_name);
		}

		if ( !Enums\Enabled::tryFrom( $this->enabled ) ) {
			$this->enabled = \wc_string_to_bool( $this->enabled ) ? Enums\Enabled::ENABLED->value : Enums\Enabled::DISABLED->value;
		}

		if ( !is_numeric( $this->days ) ) {
			throw new \Exception('Invalid days, only accept numeric, got: ' . $this->days);
		}
	}

	/**
	 * 建立 Email DTO
	 *
	 * @param array<string, mixed> $data 資料
	 * @return self
	 */
	public static function create( $data ): self {
		$unique_actions = [
			Action::TRIAL_END->value,
			Action::WATCH_TRIAL_END->value,
			Action::END->value,
			Action::WATCH_END->value,
			Action::NEXT_PAYMENT->value,
			Action::WATCH_NEXT_PAYMENT->value,
		];

		$action_name = $data['action_name'] ?? '';

		if (in_array($action_name, $unique_actions, true)) {
			$data['unique'] = true;
		}

		return new self( $data );
	}
}
