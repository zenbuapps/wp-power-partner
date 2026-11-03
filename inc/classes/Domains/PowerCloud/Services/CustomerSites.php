<?php

declare(strict_types=1);

namespace J7\PowerPartner\Domains\PowerCloud\Services;

use J7\PowerPartner\Api\FetchPowerCloud;
use J7\PowerPartner\Domains\Cloud\Shared\Arr;
use J7\PowerPartner\ShopSubscription;

/**
 * 判斷 PowerCloud 站台屬於哪位前台客戶，並決定客戶看得到哪些欄位
 *
 * PowerCloud API key 的範圍是整個經銷商帳號，API 不認識本站的客戶，所以歸屬只能由本站判斷：
 * 1. 站台綁在這位客戶的訂閱上（pp_linked_site_ids）→ 屬於他
 * 2. 站台的 adminEmail 與這位客戶的 email 相同 → 屬於他
 *
 * 兩條是「或」：經銷商用自己的帳號代客戶下單時，訂閱屬於經銷商，adminEmail 卻是客戶填的帳單 email
 * （SiteSync 取 $parent_order->get_billing_email()），雙方都要看得到，否則更新後客戶的站會消失。
 *
 * 已知限制：WordPress 不允許兩個帳號用同一個 email，但註冊與改 email 都不驗證信箱。
 * 如果某站的 adminEmail 沒有任何帳號在用（例如原客戶後來改了 email），別人用它註冊就能取得該站。
 */
abstract class CustomerSites {

	/**
	 * 客戶看得到的欄位（UserApp 實際用到的）
	 *
	 * 用允許清單而不是遮罩清單：/websites 另外帶資料庫、SSH 等憑證，對端新增欄位時預設也不外流。
	 * adminPassword 是客戶自己站台的登入密碼，開站信也會寄給客戶，UserApp 提供複製。
	 */
	const PUBLIC_FIELDS = [
		'id',
		'name',
		'domain',
		'primaryDomain',
		'subDomain',
		'wildcardDomain',
		'status',
		'ipAddress',
		'adminEmail',
		'adminPassword',
		'memo',
		'createdAt',
	];

	/**
	 * 取得屬於指定使用者的站台（原始資料，含憑證，不可直接回給瀏覽器）
	 *
	 * @param int $user_id 使用者 ID
	 * @return array<string, array<string, mixed>>|null 以站台 id 為 key；清單抓取失敗時為 null，不可當成「沒有站台」
	 */
	public static function owned_by( int $user_id ): ?array {
		$user = \get_userdata( $user_id );
		if ( ! ( $user instanceof \WP_User ) ) {
			return [];
		}

		$websites = FetchPowerCloud::fetch_websites( (string) $user_id );
		if ( null === $websites ) {
			return null;
		}

		$linked_ids = self::linked_site_ids( $user_id );
		$email      = self::normalize_email( $user->user_email );
		$owned      = [];

		foreach ( $websites as $website ) {
			$site = Arr::assoc( $website );
			$id   = self::scalar_string( $site['id'] ?? null );
			if ( '' === $id ) {
				continue;
			}

			$is_email_match = '' !== $email && self::normalize_email( $site['adminEmail'] ?? null ) === $email;

			if ( isset( $linked_ids[ $id ] ) || $is_email_match ) {
				$owned[ $id ] = $site;
			}
		}

		return $owned;
	}

	/**
	 * 只留下客戶看得到的欄位
	 *
	 * @param array<string, mixed> $site 站台原始資料
	 * @return array<string, mixed>
	 */
	public static function to_public( array $site ): array {
		return array_intersect_key( $site, array_flip( self::PUBLIC_FIELDS ) );
	}

	/**
	 * 這位使用者所有訂閱綁定的站台 id
	 *
	 * @param int $user_id 使用者 ID
	 * @return array<string, true>
	 */
	private static function linked_site_ids( int $user_id ): array {
		$linked_ids = [];
		foreach ( \wcs_get_users_subscriptions( $user_id ) as $subscription ) {
			foreach ( ShopSubscription::get_linked_site_ids( $subscription->get_id() ) as $site_id ) {
				$linked_ids[ self::scalar_string( $site_id ) ] = true;
			}
		}
		return $linked_ids;
	}

	/**
	 * 正規化 email
	 *
	 * @param mixed $email email
	 * @return string
	 */
	private static function normalize_email( mixed $email ): string {
		return strtolower( trim( self::scalar_string( $email ) ) );
	}

	/**
	 * 把外部資料的值收斂成字串，不是純量時回空字串
	 *
	 * @param mixed $value 外部資料的值
	 * @return string
	 */
	private static function scalar_string( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
