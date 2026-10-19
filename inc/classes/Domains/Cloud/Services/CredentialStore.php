<?php

declare(strict_types=1);

namespace J7\PowerPartner\Domains\Cloud\Services;

use J7\PowerPartner\Domains\Cloud\Shared\Arr;
use J7\PowerPartner\Plugin;

/**
 * CloudServer（power-partner-server）憑證的本地存放處
 *
 * 存兩種東西：
 * 1. 經銷商專屬應用程式密碼（dealer）—— 由 CloudServer `POST v3/connect` 發給本站，
 *    取代寫死在外掛裡的共用帳號 powerpartner。
 * 2. 經銷商的 cloud.luke.cafe 登入帳密（legacy account）—— 只有在 CloudServer 尚未支援 v3/connect、
 *    或專屬密碼尚未取得時才需要：legacy 模式的 `POST identity` 與 `logs/{id}/detail` 必須帶它，
 *    自動遷移也要用它去換專屬密碼。換到專屬密碼後即刪除。
 *
 * ⛔ 兩者都不可 localize 到前端，也不可經由任何 REST 回應吐回瀏覽器 —— 共用帳號就是這樣外洩的。
 *
 * 加密：sodium secretbox，金鑰由 wp-config salts 衍生，並把站台 host 一起封進密文。
 * - salts 被重產（安全外掛常做）→ 解不開 → 視為「未連線」，不 fatal
 * - 模板站被 clone 成另一個網域 → host 不符 → 視為「未連線」，避免終端客戶站繼承經銷商憑證
 * 只防「資料庫單獨外洩」，擋不住能讀 wp-config 的人；那已超出外掛能防的範圍。
 */
abstract class CredentialStore {

	/** @var string 經銷商專屬應用程式密碼（加密） */
	const DEALER_OPTION = 'power_partner_cloud_dealer_credentials';

	/** @var string 經銷商登入帳密（加密），legacy fallback 與自動遷移用 */
	const LEGACY_ACCOUNT_OPTION = 'power_partner_cloud_legacy_account';

	/**
	 * @var string 舊版前端以 CryptoJS + 公開 passphrase 加密存放的帳密（v3.5.x 以前寫入）
	 *
	 * 只讀不寫：新版不再產生這種格式，成功換成新格式後刪除。
	 */
	const OLD_ACCOUNT_INFO_OPTION = 'power_partner_account_info';

	/** @var string 專屬密碼被 CloudServer 拒絕時記錄的時間與原因（admin notice 用） */
	const DEALER_INVALID_OPTION = 'power_partner_cloud_dealer_invalid';

	/** @var string 衍生金鑰時的 context，避免與其他用到同一組 salt 的用途撞 key */
	const KEY_CONTEXT = '|power-partner/cloud-credentials/v1';

	/**
	 * 取得經銷商專屬應用程式密碼
	 *
	 * @return array{user_login: string, app_password: string, uuid: string, partner_id: string}|null
	 */
	public static function get_dealer(): ?array {
		$data = self::read( self::DEALER_OPTION );
		if ( null === $data ) {
			return null;
		}

		$user_login   = (string) ( $data['user_login'] ?? '' );
		$app_password = (string) ( $data['app_password'] ?? '' );
		if ( '' === $user_login || '' === $app_password ) {
			return null;
		}

		return [
			'user_login'   => $user_login,
			'app_password' => $app_password,
			'uuid'         => (string) ( $data['uuid'] ?? '' ),
			'partner_id'   => (string) ( $data['partner_id'] ?? '' ),
		];
	}

	/**
	 * 儲存經銷商專屬應用程式密碼
	 *
	 * @param array{user_login: string, app_password: string, uuid?: string, partner_id?: string} $credentials 憑證
	 * @return bool
	 */
	public static function save_dealer( array $credentials ): bool {
		$saved = self::write(
			self::DEALER_OPTION,
			[
				'user_login'   => (string) $credentials['user_login'],
				'app_password' => (string) $credentials['app_password'],
				'uuid'         => (string) ( $credentials['uuid'] ?? '' ),
				'partner_id'   => (string) ( $credentials['partner_id'] ?? '' ),
			]
		);
		if ( $saved ) {
			\delete_option( self::DEALER_INVALID_OPTION );
		}
		return $saved;
	}

	/**
	 * 刪除經銷商專屬應用程式密碼
	 *
	 * @return void
	 */
	public static function delete_dealer(): void {
		\delete_option( self::DEALER_OPTION );
		\delete_option( self::DEALER_INVALID_OPTION );
	}

	/**
	 * 標記專屬密碼被 CloudServer 拒絕（經銷商在雲端撤銷、帳號被停用…）
	 *
	 * 不在這裡刪除密碼：可能只是 CloudServer 暫時異常，刪了就只能請經銷商重新登入。
	 * CloudClient 會改用 legacy 重送，功能不中斷；admin notice 提醒經銷商重新連線。
	 *
	 * @param string $reason 原因（CloudServer 的 error code）
	 * @return void
	 */
	public static function mark_dealer_invalid( string $reason ): void {
		\update_option(
			self::DEALER_INVALID_OPTION,
			[
				'reason' => $reason,
				'at'     => time(),
			],
			false
		);
	}

	/**
	 * 專屬密碼是否已被標記為失效
	 *
	 * @return bool
	 */
	public static function is_dealer_invalid(): bool {
		return false !== \get_option( self::DEALER_INVALID_OPTION, false );
	}

	/**
	 * 取得經銷商登入帳密
	 *
	 * 優先讀新格式；沒有時退回解密舊版前端寫入的 CryptoJS 密文。
	 *
	 * @return array{email: string, password: string}|null
	 */
	public static function get_legacy_account(): ?array {
		$data = self::read( self::LEGACY_ACCOUNT_OPTION );
		if ( null === $data ) {
			$data = self::decrypt_old_account_info( (string) \get_option( self::OLD_ACCOUNT_INFO_OPTION, '' ) );
		}
		if ( null === $data ) {
			return null;
		}

		$email    = (string) ( $data['email'] ?? '' );
		$password = (string) ( $data['password'] ?? '' );
		if ( '' === $email || '' === $password ) {
			return null;
		}

		return [
			'email'    => $email,
			'password' => $password,
		];
	}

	/**
	 * 儲存經銷商登入帳密（新格式），並刪除舊版可被任何人解開的密文
	 *
	 * @param string $email    email
	 * @param string $password 密碼
	 * @return bool
	 */
	public static function save_legacy_account( string $email, string $password ): bool {
		$saved = self::write(
			self::LEGACY_ACCOUNT_OPTION,
			[
				'email'    => $email,
				'password' => $password,
			]
		);
		if ( $saved ) {
			\delete_option( self::OLD_ACCOUNT_INFO_OPTION );
		}
		return $saved;
	}

	/**
	 * 刪除經銷商登入帳密（新舊格式都刪）
	 *
	 * @return void
	 */
	public static function delete_legacy_account(): void {
		\delete_option( self::LEGACY_ACCOUNT_OPTION );
		\delete_option( self::OLD_ACCOUNT_INFO_OPTION );
	}

	/**
	 * 解開舊版前端以 CryptoJS.AES.encrypt(JSON, passphrase) 產生的密文
	 *
	 * 格式為 OpenSSL 相容：base64("Salted__" + 8 bytes salt + ciphertext)，
	 * key / iv 由 EVP_BytesToKey(MD5) 自 passphrase + salt 衍生，AES-256-CBC。
	 * passphrase 是公開的 Plugin::$kebab —— 這正是它必須被換掉的原因。
	 *
	 * @param string $cipher 密文
	 * @return array<string, mixed>|null
	 */
	public static function decrypt_old_account_info( string $cipher ): ?array {
		if ( '' === $cipher ) {
			return null;
		}

		$raw = base64_decode( $cipher, true );
		if ( false === $raw || strlen( $raw ) < 32 || 'Salted__' !== substr( $raw, 0, 8 ) ) {
			return null;
		}

		$salt       = substr( $raw, 8, 8 );
		$ciphertext = substr( $raw, 16 );
		$passphrase = Plugin::$kebab;

		$derived = '';
		$block   = '';
		while ( strlen( $derived ) < 48 ) {
			$block    = md5( $block . $passphrase . $salt, true );
			$derived .= $block;
		}

		$plain = openssl_decrypt( $ciphertext, 'aes-256-cbc', substr( $derived, 0, 32 ), OPENSSL_RAW_DATA, substr( $derived, 32, 16 ) );
		if ( false === $plain ) {
			return null;
		}

		$decoded = json_decode( $plain, true );
		return is_array( $decoded ) ? Arr::assoc( $decoded ) : null;
	}

	/**
	 * 讀取並解密 option
	 *
	 * @param string $option option name
	 * @return array<string, mixed>|null 不存在、解不開、host 不符時回 null
	 */
	private static function read( string $option ): ?array {
		$stored = \get_option( $option, '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}

		$raw = base64_decode( $stored, true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		try {
			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				self::key()
			);
		} catch ( \Throwable $e ) {
			$plain = false;
		}

		if ( false === $plain ) {
			Plugin::logger( "CloudServer 憑證解密失敗（salts 可能已重產），視為未連線：{$option}", 'warning' );
			return null;
		}

		$payload = json_decode( $plain, true );
		if ( ! is_array( $payload ) || ! is_array( $payload['data'] ?? null ) ) {
			return null;
		}

		if ( ( $payload['host'] ?? '' ) !== self::host() ) {
			Plugin::logger(
				"CloudServer 憑證的站台 host 不符（站台可能被複製或改網域），視為未連線：{$option}",
				'warning',
				[
					'stored_host'  => (string) ( $payload['host'] ?? '' ),
					'current_host' => self::host(),
				]
			);
			return null;
		}

		return Arr::assoc( $payload['data'] );
	}

	/**
	 * 加密並寫入 option（autoload = no）
	 *
	 * @param string               $option option name
	 * @param array<string, mixed> $data   資料
	 * @return bool
	 */
	private static function write( string $option, array $data ): bool {
		$plain = \wp_json_encode(
			[
				'host' => self::host(),
				'data' => $data,
			]
		);
		if ( false === $plain ) {
			return false;
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$stored = base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) );

		\update_option( $option, $stored, false );
		return \get_option( $option ) === $stored;
	}

	/**
	 * @return string 32 bytes secretbox key
	 */
	private static function key(): string {
		return sodium_crypto_generichash( \wp_salt( 'auth' ) . \wp_salt( 'secure_auth' ) . self::KEY_CONTEXT, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * @return string 目前站台的 host（不含 scheme / port 以外的部分）
	 */
	private static function host(): string {
		return strtolower( (string) \wp_parse_url( \home_url(), PHP_URL_HOST ) );
	}
}
