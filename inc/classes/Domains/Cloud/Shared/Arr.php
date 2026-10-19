<?php

declare(strict_types=1);

namespace J7\PowerPartner\Domains\Cloud\Shared;

/**
 * 解析外部 JSON（CloudServer 回應、option 內容）時的型別收斂工具
 */
abstract class Arr {

	/**
	 * 把任意值收斂成字串 key 的關聯陣列；不是陣列時回空陣列
	 *
	 * @param mixed $value 值
	 * @return array<string, mixed>
	 */
	public static function assoc( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$result = [];
		foreach ( $value as $key => $item ) {
			$result[ (string) $key ] = $item;
		}
		return $result;
	}

	/**
	 * 解析 JSON 字串成關聯陣列；失敗時回空陣列
	 *
	 * @param string $json JSON
	 * @return array<string, mixed>
	 */
	public static function from_json( string $json ): array {
		return self::assoc( json_decode( $json, true ) );
	}
}
