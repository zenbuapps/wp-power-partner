<?php

declare(strict_types=1);

namespace J7\PowerPartner\Domains\Cloud\Shared;

/**
 * 原樣輸出 body 的 REST 回應（例如 CloudServer 回的 CSV）
 *
 * WP REST 預設會把 data 做 JSON encode；CSV 這類非 JSON 內容要在 `rest_pre_serve_request`
 * 自己 echo（headers 已由 serve_request 依 get_headers() 送出，會覆蓋預設的 JSON Content-Type）。
 */
final class RawResponse extends \WP_REST_Response {

	/**
	 * @param string                $raw_body 原樣輸出的內容
	 * @param int                   $status   HTTP status
	 * @param array<string, string> $headers  headers
	 */
	public function __construct( public readonly string $raw_body, int $status = 200, array $headers = [] ) {
		parent::__construct( null, $status, $headers );
	}

	/**
	 * 掛在 rest_pre_serve_request：遇到 RawResponse 就自行輸出
	 *
	 * @param bool              $served  是否已輸出
	 * @param \WP_HTTP_Response $result  回應
	 * @return bool
	 */
	public static function serve( $served, $result ): bool {
		if ( $served || ! $result instanceof self ) {
			return (bool) $served;
		}
		echo $result->raw_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 上游原文（CSV），不是 HTML
		return true;
	}
}
