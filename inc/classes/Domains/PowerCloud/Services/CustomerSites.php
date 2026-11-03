<?php

declare(strict_types=1);

namespace J7\PowerPartner\Domains\PowerCloud\Services;

use J7\PowerPartner\Api\FetchPowerCloud;
use J7\PowerPartner\Domains\Cloud\Shared\Arr;
use J7\PowerPartner\Product\SiteSync;
use J7\PowerPartner\ShopSubscription;

/**
 * 判斷 PowerCloud 站台屬於哪位前台客戶，並決定客戶看得到哪些欄位
 *
 * PowerCloud API key 的範圍是整個經銷商帳號，API 不認識本站的客戶，所以歸屬只能由本站判斷：
 * 1. 站台綁在這位客戶的訂閱上（pp_linked_site_ids）→ 屬於他
 * 2. 站台綁在別人的訂閱上 → 不屬於他，就算 adminEmail 相同也一樣
 * 3. 沒有綁任何訂閱（例如後台手動開站）→ 比對 adminEmail
 *
 * 第 2 點不可省略：WooCommerce「我的帳號」改 email 不需要驗證，只比 adminEmail 的話，
 * 客戶把自己的 email 改成對方的，就能取得對方站台的管理員密碼。
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

			if ( isset( $linked_ids[ $id ] ) || self::is_owned_by_email( $id, $site, $email, $user_id ) ) {
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
	 * 站台的 adminEmail 與使用者相同，而且沒有綁在別人的訂閱上
	 *
	 * 這裡要比對訂閱的客戶，不可把「查到訂閱」直接當成別人的：linked_site_ids() 讀的是 WCS 的
	 * 客戶訂閱快取，快取過期時，客戶自己訂閱上的站也會走到這裡，不比對就會被誤判成別人的。
	 *
	 * @param string               $id      站台 id
	 * @param array<string, mixed> $site    站台原始資料
	 * @param string               $email   使用者 email（已正規化）
	 * @param int                  $user_id 使用者 ID
	 * @return bool
	 */
	private static function is_owned_by_email( string $id, array $site, string $email, int $user_id ): bool {
		if ( '' === $email || self::normalize_email( $site['adminEmail'] ?? null ) !== $email ) {
			return false;
		}

		$subscriptions = \wcs_get_subscriptions(
			[
				'subscriptions_per_page' => -1,
				'subscription_status'    => 'any',
				'meta_query'             => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'   => SiteSync::LINKED_SITE_IDS_META_KEY,
						'value' => $id,
					],
				],
			]
		);

		if ( [] === $subscriptions ) {
			return true;
		}

		foreach ( $subscriptions as $subscription ) {
			if ( (int) $subscription->get_customer_id() === $user_id ) {
				return true;
			}
		}

		return false;
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
