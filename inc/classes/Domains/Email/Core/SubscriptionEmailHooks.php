<?php

declare(strict_types=1);

namespace J7\PowerPartner\Domains\Email\Core;

use J7\PowerPartner\Plugin;
use J7\PowerPartner\Domains\Email\DTOs\Email;
use J7\PowerPartner\Domains\Email\Models\SubscriptionEmail;
use J7\PowerPartner\Domains\Subscription\Utils\Base as SubscriptionUtils;
use J7\PowerPartner\Domains\Email\Services\SubscriptionEmailScheduler;
use J7\Powerhouse\Domains\Subscription\Shared\Enums\Action;
use J7\Powerhouse\Domains\Subscription\Utils\Base as PowerhouseSubscriptionUtils;
use J7\PowerPartner\Utils\Token;


/**
 * SubscriptionEmailHooks
 * 需要用 $is_power_partner_subscription = $subscription->get_meta( SiteSync::LINKED_SITE_IDS_META_KEY, true ); 判斷是否為開站訂閱
 *  */
final class SubscriptionEmailHooks {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var object{subject:string, body:string} $default Default email */
	public object $default;

	/** @var array<Email> $emails Emails */
	public array $emails;

	/** Constructor */
	public function __construct() {

		$power_partner_settings = \get_option('power_partner_settings', []);
		$power_partner_settings = is_array($power_partner_settings) ? $power_partner_settings : [];
		$emails_array           = isset($power_partner_settings['emails']) && is_array($power_partner_settings['emails']) ? $power_partner_settings['emails'] : [];

		$this->emails = [];
		foreach ($emails_array as $email_data) {
			if ( is_array( $email_data ) ) {
				/** @var array<string, mixed> $email_data */
				$this->emails[] = Email::create($email_data);
			}
		}

		$this->default = (object) [
			'subject' => '這裡填你的信件主旨 ##FIRST_NAME##',
			'body'    => Plugin::DEFAULT_EMAIL_BODY,
		];

		SubscriptionEmailScheduler::register();

		// 網站訂閱創建後
		\add_action('pp_site_sync_by_subscription', [ $this, 'schedule_site_sync_email' ], 10, 1);

		// 以下時間點，用監聽的 hook 來發信，且只發一次，如果有修改要取消排程，重新排程
		$mapper = [
			Action::TRIAL_END->value          => Action::WATCH_TRIAL_END,
			Action::WATCH_TRIAL_END->value    => Action::WATCH_TRIAL_END,
			Action::NEXT_PAYMENT->value       => Action::WATCH_NEXT_PAYMENT,
			Action::WATCH_NEXT_PAYMENT->value => Action::WATCH_NEXT_PAYMENT,
		];

		/**
		 * 「客戶續訂失敗後」(subscription_failed) 與「訂閱結束」(end) 兩種信，
		 * 改由真實的訂閱「狀態轉換」觸發，不再走 Powerhouse 的 Action hook。原因：
		 *   - subscription_failed 原本綁在 Powerhouse「→ cancelled/expired」事件上，
		 *     導致「已取消」被當成「續訂失敗」而誤寄催繳信。改為進入 on-hold(待處理) 時才寄。
		 *   - end 原本綁在 watch_end(end 日期被更新) 上，連把訂閱設成「待取消」會動到 end 日期
		 *     都會誤觸發停用通知。改為真正進入 cancelled/expired(已取消/已過期) 時才寄。
		 * 觸發改寫見 on_status_updated()。
		 * 注意：授權碼停用邏輯(LC\Core\LifeCycle) 仍綁 Powerhouse subscription_failed，與此互不影響。
		 */
		$rebound_actions = [
			Action::SUBSCRIPTION_FAILED->value,
			Action::END->value,
			Action::WATCH_END->value,
		];

		// 取得訂閱生命週期勾點
		foreach (Action::cases() as $action) {

			// 上述兩種信改用訂閱狀態轉換觸發，這裡略過不綁 Powerhouse Action hook
			if (in_array($action->value, $rebound_actions, true)) {
				continue;
			}

			if (isset($mapper[ $action->value ])) {
				\add_action(
					$mapper[ $action->value ]->get_action_hook(),
					function ( $subscription, $args ) use ( $action ) {
						$this->schedule_subscription_email_once($subscription, $args, $action);
					},
				10,
				2
				);

				continue;
			}

			\add_action(
				$action->get_action_hook(),
					function ( $subscription, $args ) use ( $action ) {
						$this->schedule_subscription_email($subscription, $args, $action);
					},
					10,
					2
				);

		}

		// 用真實的訂閱狀態轉換觸發 subscription_failed / end 兩種信，並在復活/結束時取消未寄出的催繳信
		\add_action('woocommerce_subscription_status_updated', [ $this, 'on_status_updated' ], 10, 3);
	}

	/**
	 * 訂閱生命週期發信，只發一次
	 * 如果修改，就要重新排程
	 *
	 * @param \WC_Subscription     $subscription 訂閱
	 * @param array<string, mixed> $args 參數
	 * @param Action               $action 動作
	 * @return void
	 */
	public function schedule_subscription_email_once( \WC_Subscription $subscription, array $args, Action $action ): void {
		$emails = $this->get_emails($action->value);

		foreach ($emails as $email) {
			$this->schedule_email($email, $subscription);
		}
	}

	/**
	 * Get emails
	 * 預設只拿 enabled 的 email
	 *
	 * @param string $action_name Action name 'subscription_failed' | 'subscription_success' | 'site_sync'
	 * @return array<Email>
	 */
	public function get_emails( string $action_name = '' ): array {

		$enabled_emails = [];

		// 預設只拿 enabled 的 email
		foreach ($this->emails as $email) {
			if (!\wc_string_to_bool($email->enabled)) {
				continue;
			}

			if (! $action_name) {
				$enabled_emails[] = $email;
				continue;
			}

			if ($email->action_name === $action_name) {
				$enabled_emails[] = $email;
			}
		}

		return $enabled_emails;
	}

	/**
	 * 排程寄信
	 *
	 * @param Email            $email 信件
	 * @param \WC_Subscription $subscription 訂閱
	 * @param int              $min_delay 最少延遲秒數，排程時間不會早於 time() + $min_delay
	 * @return void
	 */
	private function schedule_email( Email $email, \WC_Subscription $subscription, int $min_delay = 0 ): void {
		if (!SubscriptionUtils::is_site_sync($subscription)) {
			return;
		}

		$last_order = PowerhouseSubscriptionUtils::get_last_order($subscription);
		if (!$last_order) {
			return;
		}

		$subscription_email           = new SubscriptionEmail($email, $subscription);
		$subscription_email_scheduler = new SubscriptionEmailScheduler($subscription_email);
		$timestamp                    = max( $subscription_email->get_timestamp(), time() + $min_delay );
		$subscription_email_scheduler->maybe_unschedule($email->action_name, $email->unique);
		$subscription_email_scheduler->schedule_single($timestamp, $email->action_name);
	}

	/**
	 * 訂閱生命週期發信
	 *
	 * @param \WC_Subscription     $subscription 訂閱
	 * @param array<string, mixed> $args 參數
	 * @param Action               $action 動作
	 * @return void
	 */
	public function schedule_subscription_email( \WC_Subscription $subscription, array $args, Action $action ): void {
		$emails = $this->get_emails($action->value);

		foreach ($emails as $email) {
			$this->schedule_email($email, $subscription);
		}
	}

	/**
	 * Get email by key
	 *
	 * @param string $key 唯一 key
	 * @return Email|null
	 */
	public function get_email( string $key ): Email|null {
		foreach ($this->emails as $email) {
			if ($email->key === $key) {
				return $email;
			}
		}
		return null;
	}

	/**
	 * 網站訂閱創建後發信
	 *
	 * @param \WC_Subscription $subscription 訂閱
	 * @return void
	 */
	public function schedule_site_sync_email( $subscription ): void {
		$emails = $this->get_emails('site_sync');
		foreach ($emails as $email) {
			$this->schedule_email($email, $subscription);
		}
	}

	/**
	 * 用真實的訂閱狀態轉換觸發 subscription_failed / end 兩種信
	 *
	 * 對應客戶心智(也修正先前綁錯觸發點造成的誤寄/重複寄)：
	 *   - 進入 on-hold(待處理)                 → 寄「客戶續訂失敗後」(subscription_failed) 催繳信
	 *   - 進入 cancelled/expired(已取消/已過期) → 寄「訂閱結束」(end) 停用通知，並取消尚未寄出的催繳信
	 *   - 復活回到 active(續訂成功)             → 取消尚未寄出的催繳信
	 * 設成「待取消」(pending-cancel) 不在此觸發，避免預付期還沒到就誤寄停用通知。
	 *
	 * @param mixed  $subscription 訂閱
	 * @param string $to_status    新狀態(無 wc- 前綴)
	 * @param string $from_status  舊狀態(無 wc- 前綴)
	 * @return void
	 */
	public function on_status_updated( $subscription, $to_status, $from_status ): void {
		if ( ! ( $subscription instanceof \WC_Subscription ) ) {
			return;
		}

		// 進入 on-hold(待處理)：重置並排程「客戶續訂失敗後」催繳信
		// 注意：WCS 每次排程續訂(自動扣款也一樣)都會先把訂閱短暫轉成 on-hold，
		// 扣款成功後馬上轉回 active 並由下方 active 分支取消排程。
		// 給最少 10 分鐘緩衝，避免 days=0 的催繳信在付款完成前被 ActionScheduler 搶先寄出。
		if ( 'on-hold' === $to_status ) {
			$this->unschedule_failed_emails( $subscription );
			foreach ( $this->get_emails( Action::SUBSCRIPTION_FAILED->value ) as $email ) {
				$this->schedule_email( $email, $subscription, 10 * MINUTE_IN_SECONDS );
			}
			return;
		}

		// 進入 cancelled/expired(已取消/已過期)：取消未寄出的催繳信，排程「訂閱結束」停用通知
		if ( in_array( $to_status, [ 'cancelled', 'expired' ], true ) ) {
			$this->unschedule_failed_emails( $subscription );
			foreach ( $this->get_emails( Action::END->value ) as $email ) {
				$this->schedule_email( $email, $subscription );
			}
			return;
		}

		// 復活回到 active：取消未寄出的催繳信
		if ( 'active' === $to_status && in_array( $from_status, [ 'on-hold', 'pending-cancel', 'cancelled', 'expired' ], true ) ) {
			$this->unschedule_failed_emails( $subscription );
		}
	}

	/**
	 * 取消尚未寄出的「客戶續訂失敗後」(subscription_failed) 催繳信
	 *
	 * @param \WC_Subscription $subscription 訂閱
	 * @return void
	 */
	private function unschedule_failed_emails( \WC_Subscription $subscription ): void {
		foreach ( $this->get_emails( Action::SUBSCRIPTION_FAILED->value ) as $email ) {
			$subscription_email           = new SubscriptionEmail( $email, $subscription );
			$subscription_email_scheduler = new SubscriptionEmailScheduler( $subscription_email );
			$subscription_email_scheduler->unschedule( $email->action_name );
		}
	}

	/**
	 * Send mail
	 *
	 * @param string               $to 收件者
	 * @param array<string, mixed> $tokens 取代字串
	 * @return array{0:array<string>,1:array<string>} 成功與失敗的 email action names
	 */
	public static function send_mail( string $to, array $tokens ): array {
		// 取得 site_sync 的 email 模板
		$email_service = self::instance();
		$emails        = $email_service->get_emails( 'site_sync' );

		$success_emails = [];
		$failed_emails  = [];
		foreach ( $emails as $email ) {
			// 取得 subject
			$subject = $email->subject;
			$subject = empty( $subject ) ? $email_service->default->subject : $subject;

			// 取得 message
			$body = $email->body;
			$body = empty( $body ) ? $email_service->default->body : $body;

			// Replace tokens in email..
			$subject = Token::replace( $subject, $tokens );
			$body    = Token::replace( $body, $tokens );

			$email_headers = [ 'Content-Type: text/html; charset=UTF-8' ];
			$result        = \wp_mail(
				$to,
				$subject,
				\wpautop( $body ),
				$email_headers
			);

			if ( $result ) {
				$success_emails[] = $email->action_name;
			} else {
				$failed_emails[] = $email->action_name;
			}
		}

		return [ $success_emails, $failed_emails ];
	}
}
