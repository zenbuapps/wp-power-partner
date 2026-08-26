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

	/**
	 * 站台通知信的關鍵 token（issue #21）
	 *
	 * 這些是「客戶拿不到就等於沒開站」的資訊（對照 Plugin::DEFAULT_EMAIL_BODY，其中就含這四個）。
	 * 模板有用到、但 tokens 給不出值時寧可不寄，也不要寄一封滿是 ##XXX## 的半成品給終端客戶。
	 *
	 * DOMAIN / IPV4 刻意不列入——缺了信仍然可用。
	 * FIRST_NAME / LAST_NAME 這類也不列入——訪客結帳本來就可能為空，列入會把整封信擋掉，
	 * 變成「客戶完全收不到開通資訊」，比看到佔位符更糟。
	 *
	 * @var array<string>
	 */
	private const REQUIRED_SITE_TOKENS = [ 'FRONTURL', 'ADMINURL', 'SITEUSERNAME', 'SITEPASSWORD' ];

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

		/**
		 * issue #21：這裡原本綁了 pp_site_sync_by_subscription → schedule_site_sync_email()，
		 * 會在開站當下立刻排一封 site_sync 信。但那條路徑的 tokens 只有
		 * Token::get_order_tokens() + Token::get_subscription_tokens()（後者只產 URL 一個 key），
		 * 結構上拿不到 FRONTURL / ADMINURL / SITEUSERNAME / SITEPASSWORD / IPV4，
		 * 而 Token::replace() 對空值是 continue，缺值會以字面 ##XXX## 直接寄給客戶；
		 * 又因為 days 被 UI 鎖 0，這封「壞信」還比正確的那封（240 秒後）先到。
		 *
		 * 開站通知信一律改由帶完整站台 payload 的兩條路徑負責：
		 *   - PowerCloud：Product\SiteSync::send_email()（讀 email_payloads_tmp，time()+240）
		 *   - WPCD：Api\Main::post_customer_notification_callback()（CloudServer 回調 body params）
		 *
		 * ⚠️ Product\SiteSync 的 do_action('pp_site_sync_by_subscription') 保留（公開擴充點）。
		 * ⚠️ 目前 WPCD 沒有重複寄信的唯一原因，就是 schedule_email() 的 is_site_sync() 守門——
		 *    PowerCloud 在開站當下同步寫入 pp_linked_site_ids（守門會過），WPCD 要等 REST 回調（守門擋住）。
		 *    任何放寬該守門的修改，都必須先確認這裡沒有 site_sync 的排程綁定。
		 */

		// 以下時間點，用監聽的 hook 來發信，且只發一次，如果有修改要取消排程，重新排程
		$mapper = [
			Action::TRIAL_END->value          => Action::WATCH_TRIAL_END,
			Action::WATCH_TRIAL_END->value    => Action::WATCH_TRIAL_END,
			Action::NEXT_PAYMENT->value       => Action::WATCH_NEXT_PAYMENT,
			Action::WATCH_NEXT_PAYMENT->value => Action::WATCH_NEXT_PAYMENT,
		];

		/**
		 * 「客戶續訂失敗後」(subscription_failed)、「客戶續訂成功後」(subscription_success)
		 * 與「訂閱結束」(end) 三種信，改由真實的訂閱「狀態轉換」觸發，不再走 Powerhouse 的 Action hook。原因：
		 *   - subscription_failed 原本綁在 Powerhouse「→ cancelled/expired」事件上，
		 *     導致「已取消」被當成「續訂失敗」而誤寄催繳信。改為進入 on-hold(待處理) 時才寄。
		 *   - subscription_success 原本綁在 Powerhouse「cancelled/expired → active」事件上，
		 *     但 stock WCS 的 can_be_updated_to('active') 不允許這種轉換，此信從外掛上線至今從未寄出過。
		 *     改為由 on-hold/pending-cancel 等狀態恢復為 active(續訂成功) 時才寄。
		 *   - end 原本綁在 watch_end(end 日期被更新) 上，連把訂閱設成「待取消」會動到 end 日期
		 *     都會誤觸發停用通知。改為真正進入 cancelled/expired(已取消/已過期) 時才寄。
		 * 觸發改寫見 on_status_updated()。
		 * 注意：網站重啟(Site\Core\DisableHooks)與授權碼邏輯(LC\Core\LifeCycle) 仍綁 Powerhouse hook，與此互不影響。
		 */
		$rebound_actions = [
			Action::SUBSCRIPTION_FAILED->value,
			Action::SUBSCRIPTION_SUCCESS->value,
			Action::END->value,
			Action::WATCH_END->value,
		];

		// 取得訂閱生命週期勾點
		foreach (Action::cases() as $action) {

			// 上述三種信改用訂閱狀態轉換觸發，這裡略過不綁 Powerhouse Action hook
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

		// 用真實的訂閱狀態轉換觸發 subscription_failed / subscription_success / end 三種信，並在狀態離開時取消對應的未寄信件
		\add_action('woocommerce_subscription_status_updated', [ $this, 'on_status_updated' ], 10, 3);

		/**
		 * 「客戶自行取消訂閱通知」(customer_cancelled)：終端客戶於「我的帳號」頁自行取消訂閱時，
		 * 寄通知信給經銷商本人（站台 admin_email，收件人分流見 SubscriptionEmailScheduler::action_callback()）。
		 *
		 * 綁在 WCS 的 woocommerce_customer_changed_subscription_to_cancelled hook 上——此 hook 由
		 * WCS_User_Change_Status_Handler::change_users_subscription() 在「客戶前台操作」時才 fire
		 * （先 cancel_order() 再 do_action，且只帶 $subscription 一個參數），因此天然排除：
		 *   - 管理員後台改狀態（走 woocommerce_subscription_status_updated，不走此 hook）
		 *   - 金流自動扣款失敗導致的狀態變動（同上）
		 * 注意：hook 名取自「客戶請求的狀態」（My Account 取消一律請求 cancelled），不是落地狀態——
		 * cancel_order() 依剩餘預付期落地 pending-cancel 或 cancelled，兩種情形 fire 的都是
		 * ..._to_cancelled，單一綁定即涵蓋；..._to_pending-cancel 在 WCS 現行取消流程永不觸發
		 * （change_users_subscription() 的 switch 無此 case），故不綁。
		 * 此信立即寄（days=0）、不 unique（每次取消都寄），見 issue #20。
		 */
		\add_action('woocommerce_customer_changed_subscription_to_cancelled', [ $this, 'schedule_customer_cancelled_email' ], 10, 1);
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
	 * 客戶自行取消訂閱後，排程通知信給經銷商
	 *
	 * 綁定於 WCS 的 woocommerce_customer_changed_subscription_to_cancelled hook（見 constructor），
	 * 只在終端客戶於「我的帳號」頁自行取消訂閱時觸發（落地 pending-cancel 或 cancelled 皆 fire 此 hook），
	 * 排除管理員後台操作與金流扣款失敗。同一次取消只 fire 一次，不會重複排程。
	 * 收件人為經銷商本人（站台 admin_email），分流邏輯見 SubscriptionEmailScheduler::action_callback()。
	 * 此信立即寄、不 unique（每次取消都寄），見 issue #20。
	 *
	 * schedule_email() 內建 is_site_sync() 守門（非開站訂閱不寄）與 maybe_unschedule，此處不重複檢查。
	 *
	 * @param mixed $subscription 訂閱（WCS hook 傳入，仍做 instanceof 守門）
	 * @return void
	 */
	public function schedule_customer_cancelled_email( $subscription ): void {
		if ( ! ( $subscription instanceof \WC_Subscription ) ) {
			return;
		}

		foreach ( $this->get_emails( 'customer_cancelled' ) as $email ) {
			$this->schedule_email( $email, $subscription );
		}
	}

	/**
	 * 用真實的訂閱狀態轉換觸發 subscription_failed / subscription_success / end 三種信
	 *
	 * 對應客戶心智(也修正先前綁錯觸發點造成的誤寄/重複寄)：
	 *   - 進入 on-hold(待處理)                 → 寄「客戶續訂失敗後」(subscription_failed) 催繳信，並取消尚未寄出的成功信
	 *   - 進入 cancelled/expired(已取消/已過期) → 寄「訂閱結束」(end) 停用通知，並取消尚未寄出的催繳信與成功信
	 *   - 復活回到 active(續訂成功)             → 寄「客戶續訂成功後」(subscription_success) 信，並取消尚未寄出的催繳信
	 * 設成「待取消」(pending-cancel) 不在此觸發，避免預付期還沒到就誤寄停用通知。
	 * 首次付款啟用(pending → active) 不寄成功信——成功信只在「從 on-hold/pending-cancel 等狀態恢復」時寄。
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

		// 進入 on-hold(待處理)：重置並排程「客戶續訂失敗後」催繳信，取消未寄出的成功信
		// 注意：WCS 每次排程續訂(自動扣款也一樣)都會先把訂閱短暫轉成 on-hold，
		// 扣款成功後馬上轉回 active 並由下方 active 分支取消排程。
		// 給最少 10 分鐘緩衝，避免 days=0 的催繳信在付款完成前被 ActionScheduler 搶先寄出。
		if ( 'on-hold' === $to_status ) {
			$this->unschedule_failed_emails( $subscription );
			$this->unschedule_success_emails( $subscription );
			foreach ( $this->get_emails( Action::SUBSCRIPTION_FAILED->value ) as $email ) {
				$this->schedule_email( $email, $subscription, 10 * MINUTE_IN_SECONDS );
			}
			return;
		}

		// 進入 cancelled/expired(已取消/已過期)：取消未寄出的催繳信、成功信與「即將扣款」信，排程「訂閱結束」停用通知
		// 已取消/過期不會再有下次扣款，未寄出的「即將扣款」(next_payment) 信若不清除會誤寄(修復見 commit 4d3763c)。
		if ( in_array( $to_status, [ 'cancelled', 'expired' ], true ) ) {
			$this->unschedule_failed_emails( $subscription );
			$this->unschedule_success_emails( $subscription );
			$this->unschedule_next_payment_emails( $subscription );
			foreach ( $this->get_emails( Action::END->value ) as $email ) {
				$this->schedule_email( $email, $subscription );
			}
			return;
		}

		// 進入 pending-cancel(待取消)：取消未寄出的「即將扣款」信。
		// 待取消訂閱在預付期結束後就停止，期末不會再自動扣款，因此不該再寄「即將扣款」通知(修復見 commit 4d3763c)。
		// 不在此排程 end 信(維持原設計，避免預付期未到就誤寄停用通知)。
		if ( 'pending-cancel' === $to_status ) {
			$this->unschedule_next_payment_emails( $subscription );
			return;
		}

		// 復活回到 active(續訂成功)：取消未寄出的催繳信，排程「客戶續訂成功後」成功信
		// 與催繳信相同，給最少 10 分鐘緩衝 + 寄送當下狀態複查(須仍為 active)，
		// 避免自動續訂的 active → on-hold → active 震盪期間誤寄或與催繳信同時寄出。
		if ( 'active' === $to_status && in_array( $from_status, [ 'on-hold', 'pending-cancel', 'cancelled', 'expired' ], true ) ) {
			$this->unschedule_failed_emails( $subscription );
			foreach ( $this->get_emails( Action::SUBSCRIPTION_SUCCESS->value ) as $email ) {
				$this->schedule_email( $email, $subscription, 10 * MINUTE_IN_SECONDS );
			}
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
	 * 取消尚未寄出的「客戶續訂成功後」(subscription_success) 成功信
	 *
	 * @param \WC_Subscription $subscription 訂閱
	 * @return void
	 */
	private function unschedule_success_emails( \WC_Subscription $subscription ): void {
		foreach ( $this->get_emails( Action::SUBSCRIPTION_SUCCESS->value ) as $email ) {
			$subscription_email           = new SubscriptionEmail( $email, $subscription );
			$subscription_email_scheduler = new SubscriptionEmailScheduler( $subscription_email );
			$subscription_email_scheduler->unschedule( $email->action_name );
		}
	}

	/**
	 * 取消尚未寄出的「即將扣款」(next_payment / watch_next_payment) 信
	 *
	 * 「即將扣款」信由 next_payment 與 watch_next_payment 兩種 action_name 觸發排程，
	 * 兩者皆 unique，這裡一併清除以涵蓋兩種設定。
	 *
	 * @param \WC_Subscription $subscription 訂閱
	 * @return void
	 */
	private function unschedule_next_payment_emails( \WC_Subscription $subscription ): void {
		$next_payment_emails = array_merge(
			$this->get_emails( Action::NEXT_PAYMENT->value ),
			$this->get_emails( Action::WATCH_NEXT_PAYMENT->value )
		);
		foreach ( $next_payment_emails as $email ) {
			$subscription_email           = new SubscriptionEmail( $email, $subscription );
			$subscription_email_scheduler = new SubscriptionEmailScheduler( $subscription_email );
			$subscription_email_scheduler->unschedule( $email->action_name );
		}
	}

	/**
	 * 找出「模板有用到、但 tokens 給不出值」的關鍵站台 token（issue #21）
	 *
	 * 三個條件同時成立才算缺少：
	 *   1. token 在 REQUIRED_SITE_TOKENS 白名單內
	 *   2. 模板（subject + body）真的有用到它——模板沒用到就不該因為 tokens 不全而擋信
	 *   3. tokens 給不出非空值
	 *
	 * @param string               $content 替換前的 subject + body
	 * @param array<string, mixed> $tokens  取代字串（key 比對不分大小寫，與 Token::replace() 的 strtoupper 行為一致）
	 * @return array<string> 缺少的 token 名稱
	 */
	private static function get_missing_required_tokens( string $content, array $tokens ): array {
		$upper_tokens = array_change_key_case( $tokens, CASE_UPPER );
		$missing      = [];

		foreach ( self::REQUIRED_SITE_TOKENS as $token_name ) {
			if ( ! str_contains( $content, "##{$token_name}##" ) ) {
				continue;
			}

			$value = $upper_tokens[ $token_name ] ?? '';
			if ( is_array( $value ) || '' === trim( (string) $value ) ) {
				$missing[] = $token_name;
			}
		}

		return $missing;
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

			/**
			 * 防呆：模板需要站台變數，但 tokens 給不出來時中止寄送（issue #21）
			 *
			 * 必須在 Token::replace() 之前檢查——replace() 對空值是 continue（保留字面佔位符），
			 * 替換之後就分不出「本來就沒有這個 token」與「有但值是空的」。
			 */
			$missing_tokens = self::get_missing_required_tokens( $subject . ' ' . $body, $tokens );
			if ( $missing_tokens ) {
				Plugin::logger(
					'開站通知信缺少關鍵站台變數，已中止寄送：' . implode( ', ', $missing_tokens ),
					'error',
					[
						'to'                  => $to,
						'email_key'           => $email->key,
						'action_name'         => $email->action_name,
						'missing_tokens'      => $missing_tokens,
						'provided_token_keys' => array_keys( $tokens ),
					],
					5
				);
				$failed_emails[] = $email->action_name;
				continue;
			}

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
