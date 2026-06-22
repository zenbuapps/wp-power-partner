<?php

declare(strict_types=1);

namespace J7\PowerPartner\Domains\Email\Services;

use J7\PowerPartner\Plugin;
use J7\Powerhouse\Domains\AsSchedulerHandler\Shared\Base;
use J7\PowerPartner\Domains\Email\Models\SubscriptionEmail;
use J7\Powerhouse\Domains\Subscription\Shared\Enums\Action;
use J7\Powerhouse\Domains\Subscription\Shared\Enums\Status;
use J7\PowerPartner\Utils\Token;
use J7\PowerPartner\Domains\Email\Core\SubscriptionEmailHooks;
use J7\Powerhouse\Domains\Subscription\Utils\Base as PowerhouseSubscriptionUtils;

/**
 * 排程寄信
 */
final class SubscriptionEmailScheduler extends Base {

	/** @var string 排程的 hook */
	protected static string $hook = 'power_partner/3.1.0/email/scheduler';

	/**
	 * Constructor，每次傳入的資源實例可能不同
	 *
	 * @param SubscriptionEmail $item 訂閱
	 * @throws \Exception 如果 $item 不是 \WC_Subscription 實例
	 */
	public function __construct(
		/** @var SubscriptionEmail 訂閱Email */
		protected $item,
	) {
		parent::__construct( $item );
	}

	/**
	 * 取得排程的 callback
	 *
	 * @param array{email_key: string, subscription_id: int, action_name: string} $args 排程的參數
	 * @return void
	 */
	public static function action_callback( $args ): void {

		$email_key       = $args['email_key'];
		$subscription_id = $args['subscription_id'];
		$action_name     = $args['action_name'];

		if (!$email_key || !$subscription_id) {
			Plugin::logger(  'send_email 找不到 email_key 或 subscription_id', 'error', $args );
			return;
		}

		$service      = SubscriptionEmailHooks::instance();
		$email        = $service->get_email( $email_key );
		$subscription = \wcs_get_subscription( $subscription_id );
		if ( !$email || !$subscription ) {
			Plugin::logger( 'send_email 找不到 email 或 subscription', 'error', $args );
			return;
		}

		// 寄送當下做最終狀態檢查，排程後訂閱狀態可能已經改變（例如自動續訂短暫 on-hold 後馬上回到 active）
		$subscription_status = $subscription->get_status();
		$status_enum         = Status::tryFrom( $subscription_status );

		// 續訂成功信(subscription_success)只在訂閱仍為 active(已啟用) 時寄送
		if ( $email->action_name === Action::SUBSCRIPTION_SUCCESS->value && Status::ACTIVE !== $status_enum ) {
			Plugin::logger(
				"訂閱 #{$subscription->get_id()} 已不在 active(已啟用) 狀態，不寄送續訂成功信",
				'info',
				[
					'subscription_status' => $subscription_status,
					'email'               => $email->to_array(),
				]
				);
			return;
		}

		// 催繳信(subscription_failed)只在訂閱仍為 on-hold(待處理) 時寄送
		if ( $email->action_name === Action::SUBSCRIPTION_FAILED->value && Status::ON_HOLD !== $status_enum ) {
			Plugin::logger(
				"訂閱 #{$subscription->get_id()} 已不在 on-hold(待處理) 狀態，不寄送催繳信",
				'info',
				[
					'subscription_status' => $subscription_status,
					'email'               => $email->to_array(),
				]
				);
			return;
		}

		// 「即將扣款」信(next_payment / watch_next_payment) 在訂閱已 pending-cancel/cancelled/expired
		// (待取消/已取消/已過期) 時不寄送——這些狀態不會再有下次自動扣款，預告扣款會誤導客戶(issue #20)。
		// 防禦排程清除遺漏的漏網信件(主清除在 SubscriptionEmailHooks::on_status_updated)。
		if (
			in_array( $email->action_name, [ Action::NEXT_PAYMENT->value, Action::WATCH_NEXT_PAYMENT->value ], true )
			&& in_array( $status_enum, [ Status::PENDING_CANCEL, Status::CANCELLED, Status::EXPIRED ], true )
		) {
			Plugin::logger(
				"訂閱 #{$subscription->get_id()} 已為 {$subscription_status}(不會再扣款)，不寄送即將扣款信",
				'info',
				[
					'subscription_status' => $subscription_status,
					'email'               => $email->to_array(),
				]
				);
			return;
		}

		// 訂閱結束信(end)只在訂閱已為 cancelled/expired(已取消/已過期) 時寄送
		if ( $email->action_name === Action::END->value && ! in_array( $status_enum, [ Status::CANCELLED, Status::EXPIRED ], true ) ) {
			Plugin::logger(
				"訂閱 #{$subscription->get_id()} 已不在 cancelled/expired(已取消/已過期) 狀態，不寄送訂閱結束信",
				'info',
				[
					'subscription_status' => $subscription_status,
					'email'               => $email->to_array(),
				]
				);
			return;
		}

		$last_order = PowerhouseSubscriptionUtils::get_last_order( $subscription );
		if ( ! $last_order) {
			return;
		}

		$tokens = array_merge( Token::get_order_tokens( $last_order ), Token::get_subscription_tokens( $subscription ) );

		$admin_email = (string) \get_option('admin_email');
		$headers     = [];
		$headers[]   = 'Content-Type: text/html; charset=UTF-8';
		$headers[]   = "Bcc: {$admin_email}";

		$success = \wp_mail(
			$last_order->get_billing_email(),
			Token::replace( $email->subject, $tokens ),
			Token::replace( $email->body, $tokens ),
			$headers,
		);

		$log_args = (object) [
			'sent_status' => $success ? '成功' : '失敗',
			'level'       => $success ? 'info' : 'error',
		];

		Plugin::logger( "訂閱 #{$subscription->get_id()} 寄信{$log_args->sent_status} email_action {$email->action_name} email_key {$email->key}", $log_args->level, $args );
	}

	/**
	 * 取得排程的 hook
	 *
	 * @return string
	 */
	public static function get_hook(): string {
		return self::$hook;
	}

	/**
	 * 排程後，寫入 log
	 *
	 * @param int|null $action_id 排程的 action_id
	 * @param int      $timestamp 排程的時間
	 * @param string   $group     排程的群組
	 * @return void
	 */
	public function after_schedule_single( int|null $action_id, int $timestamp, string $group ): void {
		Plugin::logger( "訂閱 #{$this->item->subscription->get_id()} 排程寄信 action_name #{$this->item->dto->action_name} action_id #{$action_id}", 'info', $this->item->get_scheduler_args() );
	}

	/**
	 * 取消排程後，寫入 log
	 *
	 * @param int|null $action_id 排程的 action_id
	 * @param string   $group 排程的群組
	 * @return void
	 */
	public function after_unschedule( int|null $action_id, string $group ): void {
		Plugin::logger( "訂閱 #{$this->item->subscription->get_id()} 取消排程寄信 action_name #{$this->item->dto->action_name} action_id #{$action_id}", 'debug', $this->item->get_scheduler_args() );
	}

	/**
	 * 取得排程的參數，執行時會傳入 action_callback
	 *
	 * @return array{email_key: string, subscription_id: int, action_name: string}
	 * */
	protected function get_args(): array {
		return $this->item->get_scheduler_args();
	}
}
