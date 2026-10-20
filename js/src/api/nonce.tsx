import {
	AxiosInstance,
	AxiosResponse,
	InternalAxiosRequestConfig,
	isAxiosError,
} from 'axios'

import { ajaxUrl, apiUrl } from '@/utils'

/**
 * ppNonceRetried：已因 nonce 過期換新並重送過一次，只重試一次，防止無限迴圈。
 * 自訂欄位會被 axios 的 mergeConfig 保留，重送那一輪的 interceptor 讀得到。
 */
declare module 'axios' {
	interface AxiosRequestConfig {
		ppNonceRetried?: boolean
	}
}

/** WordPress REST cookie 認證帶 nonce 的標頭 */
export const NONCE_HEADER = 'X-WP-Nonce'

/** nonce 過期時 WordPress 回的錯誤碼（固定是 HTTP 403） */
const INVALID_NONCE_CODE = 'rest_cookie_invalid_nonce'

/** 換新 nonce 的逾時上限；沒有它的話 admin-ajax 卡住時，等著重送的請求會永遠 pending */
const REFRESH_TIMEOUT_MS = 30 * 1000

/** wp_create_nonce() 產生的英數字串；已登出時 admin-ajax 回 '0'，不可當成 nonce */
const NONCE_PATTERN = /^[a-z0-9]{6,64}$/i

/** 目前有效的 wp_rest nonce；一律從 wpApiSettings 讀，換新後所有讀取者立即拿到新值 */
export const getNonce = (): string => window?.wpApiSettings?.nonce || ''

const hasInvalidNonceCode = (body: unknown): boolean =>
	typeof body === 'object' &&
	body !== null &&
	'code' in body &&
	body.code === INVALID_NONCE_CODE

const writeBackNonce = (nonce: string) => {
	if (window.wpApiSettings) {
		window.wpApiSettings.nonce = nonce

		return
	}

	window.wpApiSettings = { root: apiUrl, nonce }
}

const requestNewNonce = async (): Promise<string | null> => {
	const abortController = new AbortController()
	const timeoutId = window.setTimeout(
		() => abortController.abort(),
		REFRESH_TIMEOUT_MS
	)

	try {
		const url = new URL(ajaxUrl, window.location.href)
		url.searchParams.set('action', 'rest-nonce')

		const response = await fetch(url.toString(), {
			credentials: 'same-origin',
			cache: 'no-store',
			signal: abortController.signal,
		})
		const nonce = (await response.text()).trim()

		return response.ok && NONCE_PATTERN.test(nonce) ? nonce : null
	} catch {
		return null
	} finally {
		window.clearTimeout(timeoutId)
	}
}

let pendingRefresh: Promise<string | null> | null = null

/**
 * 向 WordPress 換一個新的 wp_rest nonce，成功時寫回 wpApiSettings.nonce
 *
 * 打的是 core 內建的 admin-ajax `rest-nonce`（WP 5.3+，已登入者可用，直接回 nonce 字串）。
 * 舊版直連雲端用的 Basic Auth 不會過期，改走本站 proxy 後依賴 nonce（有效期 12–24 小時），
 * 分頁開太久就會 403；換新 nonce 是為了讓這種情況不必重新整理頁面。
 *
 * 失敗（已登出、逾時、被擋）回 null，不拋錯，呼叫端照原本流程處理原錯誤。
 * 多個請求同時過期時共用同一次換新。
 */
export const refreshNonce = (): Promise<string | null> => {
	if (!pendingRefresh) {
		pendingRefresh = requestNewNonce()
			.then((nonce) => {
				if (nonce) {
					writeBackNonce(nonce)
				}

				return nonce
			})
			.finally(() => {
				pendingRefresh = null
			})
	}

	return pendingRefresh
}

/**
 * axios request interceptor：每次送出前帶上目前的 nonce
 *
 * 不寫在 instance 的預設標頭：預設值在模組載入時就固定了，
 * 換新後另一個 instance 仍會帶著過期的 nonce 再被擋一次。
 */
export const applyCurrentNonce = (
	config: InternalAxiosRequestConfig
): InternalAxiosRequestConfig => {
	config.headers.set(NONCE_HEADER, getNonce())

	return config
}

/**
 * axios response 錯誤處理：nonce 過期造成的 403，換新 nonce 後以同一個 instance 重送一次
 *
 * 回傳 null = 不是 nonce 過期、已經重送過、或換新失敗，呼叫端照原本流程處理原錯誤。
 *
 * ⚠️ 重送的請求會再經過同一組 interceptor（帶 ppNonceRetried，不會再重試）。
 * 它若失敗，由那一輪負責通知並 reject，這裡的 await 會把同一個錯誤往上拋；
 * 呼叫端不可再通知一次，否則同一個錯誤會跳兩次通知。
 */
export const retryWithFreshNonce = async (
	instance: AxiosInstance,
	error: unknown
): Promise<AxiosResponse | null> => {
	if (
		!isAxiosError(error) ||
		!error.config ||
		error.config.ppNonceRetried ||
		error.response?.status !== 403 ||
		!hasInvalidNonceCode(error.response?.data)
	) {
		return null
	}

	if (!(await refreshNonce())) {
		return null
	}

	return instance.request({ ...error.config, ppNonceRetried: true })
}

/**
 * 帶目前 nonce 的同源 fetch；nonce 過期（403 + rest_cookie_invalid_nonce）時換新後重送一次
 *
 * ⚠️ 用 clone() 檢查錯誤碼，原 response 的 body 保持未讀，呼叫端照樣能讀錯誤內容。
 * 重送沿用呼叫端的 signal，逾時保護同樣涵蓋重送。
 */
export const fetchWithNonce = async (
	input: string,
	init: RequestInit = {}
): Promise<Response> => {
	const send = () => {
		const headers = new Headers(init.headers)
		headers.set(NONCE_HEADER, getNonce())

		return fetch(input, { credentials: 'same-origin', ...init, headers })
	}

	const response = await send()

	if (response.status !== 403) {
		return response
	}

	const body: unknown = await response
		.clone()
		.json()
		.catch(() => null)

	if (!hasInvalidNonceCode(body) || !(await refreshNonce())) {
		return response
	}

	return send()
}
