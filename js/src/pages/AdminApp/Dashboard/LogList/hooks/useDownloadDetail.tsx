import { notification } from 'antd'
import { useCallback, useRef, useState } from 'react'

import { DataType } from '../types'

import { useAccountInfo } from '@/pages/AdminApp/hooks/useAccountInfo'
import { apiTimeout, cloudApiUrl, t } from '@/utils'

/**
 * 單次請求的逾時上限（毫秒）
 *
 * ⚠️ 沿用 apiTimeout —— 這個功能從 cloudAxios 改成原生 fetch，
 * cloudAxios 原本自帶的 timeout 保護跟著消失了，這裡要把它補回來，
 * 且數值必須與其他 axios instance 一致，否則同一個雲端站會有兩套逾時標準。
 */
const DOWNLOAD_TIMEOUT_MS = parseInt(apiTimeout, 10)

/**
 * 明細端點的失敗回應 body
 *
 * 有兩種形狀：
 * - 端點自產：{ status, message }（400 / 401 / 403 / 404）
 * - WP permission_callback 擋下：{ code, message, data: { status } }
 * 兩者的 HTTP 401 意義不同，靠 code 欄位分辨。
 */
type TDetailErrorBody = {
	code?: string
	message?: string
	status?: number
}

/** format=json 的成功回應（只取判斷完整性需要的欄位） */
type TDetailProbe = {
	status: number
	message: string
	data: {
		schema: number
		source: 'wpcd' | 'powercloud' | null
		is_complete: boolean
		incomplete_reason: null | 'legacy_title_truncated'
	}
}

type TNotice = {
	message: string
	description: string
}

/**
 * 依 HTTP 狀態碼給出可辨識的錯誤提示
 *
 * ⚠️ 401 與 403 的訊息不可合併：
 * 401 代表帳密失效、使用者該重新登入；403 代表帳密有效但這筆紀錄不是他的。
 * 訊息若一樣，使用者無從得知該重新登入還是該換一筆紀錄。
 *
 * ⚠️ 401 與 403 都要再用 body.code 分流一次：
 * WP 的 rest_authorization_required_code() 在 is_user_logged_in() 為 true 時
 * 回的是 403 + code: 'rest_forbidden'，那是共用連線憑證權限不足，
 * 與「這筆紀錄不是你的」無關 —— 提示若指向後者，使用者會一直換紀錄重試，換幾筆都不會好。
 */
const getErrorNotice = (
	status: number,
	body: TDetailErrorBody | null
): TNotice => {
	if (status === 400) {
		return {
			message: '下載明細失敗：請求參數有誤',
			description: body?.message || '請重新整理頁面後再試一次。',
		}
	}

	// 有 code 欄位 = 本站與雲端共用的連線憑證被擋下（不是使用者的帳密問題）
	if (status === 401 && body?.code) {
		return {
			message: '下載明細失敗：連線憑證驗證失敗',
			description: '請重新整理頁面後再試；若仍然失敗，請聯絡站長路可。',
		}
	}

	if (status === 401) {
		return {
			message: '下載明細失敗：帳號密碼已失效，請重新登入',
			description:
				'你連結的經銷商帳號密碼已無法通過驗證，請重新登入經銷商帳號後再下載明細。',
		}
	}

	// 有 code 欄位 = WP permission_callback 擋下共用連線憑證（rest_forbidden），
	// 不是「這筆紀錄不是你的」—— 換一筆紀錄重試沒有任何幫助
	if (status === 403 && body?.code) {
		return {
			message: '下載明細失敗：連線憑證權限不足',
			description:
				'本站與雲端共用的連線憑證未通過權限檢查，換一筆紀錄也不會改善。請重新整理頁面後再試；若仍然失敗，請聯絡站長路可。',
		}
	}

	if (status === 403) {
		return {
			message: '下載明細失敗：你沒有這筆紀錄的存取權限',
			description:
				'這筆點數紀錄不在你的經銷商帳號名下，請改選你自己帳號的紀錄。',
		}
	}

	if (status === 404) {
		return {
			message: '下載明細失敗：找不到這筆點數紀錄',
			description: '這筆紀錄可能已被刪除，請重新整理列表後再試一次。',
		}
	}

	return {
		message: `下載明細失敗：伺服端發生錯誤（${status}）`,
		description: body?.message || '請稍後再試，若持續失敗請聯絡站長路可。',
	}
}

const readErrorBody = async (
	response: Response
): Promise<TDetailErrorBody | null> => {
	try {
		return (await response.json()) as TDetailErrorBody
	} catch {
		return null
	}
}

/**
 * 從 Content-Disposition 解析檔名
 *
 * ⚠️ 經銷商站打的是雲端站，屬跨來源請求；Content-Disposition 確實不在 CORS
 * 的預設安全名單內，但伺服端已用 rest_exposed_cors_headers filter
 * （SiteSync::expose_cors_headers()）把它明確 expose，瀏覽器會把這個標頭交給
 * fetch() —— 也就是說這條解析路徑是活的，正常情況下拿到的是伺服端
 * Detail::csv_filename() 給的檔名，不要當成恆為 null 的死碼刪掉。
 *
 * 下方的自組檔名是防禦性退路（伺服端版本較舊而未 expose、標頭格式異常等），
 * 不是主路徑；解析不到時一律退回自組檔名，不可讓下載失敗。
 */
const parseFilenameFromDisposition = (disposition: string | null): string => {
	if (!disposition) {
		return ''
	}

	const utf8Matched = disposition.match(/filename\*=UTF-8''([^;]+)/i)

	if (utf8Matched?.[1]) {
		try {
			return decodeURIComponent(utf8Matched[1])
		} catch {
			return ''
		}
	}

	const quotedMatched = disposition.match(/filename="([^"]+)"/i)

	if (quotedMatched?.[1]) {
		return quotedMatched[1]
	}

	const bareMatched = disposition.match(/filename=([^;]+)/i)

	return bareMatched?.[1]?.trim() || ''
}

/** 與伺服端 Detail::csv_filename() 相同的命名規則 */
const getFallbackFilename = (record: DataType): string => {
	const recordDate = String(record?.date || '').slice(0, 10)
	const datePart = /^\d{4}-\d{2}-\d{2}$/.test(recordDate)
		? recordDate
		: new Date().toISOString().slice(0, 10)

	return `power-money-log-${record?.id}-${datePart}.csv`
}

/** 移除 anchor 與釋放 blob URL 的共同延遲（毫秒） */
const SAVE_CLEANUP_DELAY_MS = 1000

const saveBlob = (blob: Blob, filename: string) => {
	const objectUrl = URL.createObjectURL(blob)
	const link = document.createElement('a')
	link.href = objectUrl
	link.download = filename
	link.style.display = 'none'
	document.body.appendChild(link)

	try {
		link.click()
	} finally {
		// ⚠️ 移除 anchor 與 revoke 必須用同一套延遲：
		// 立刻 revoke 在部分瀏覽器會中斷還沒開始寫入的下載，
		// click() 後同步移除 anchor 在 Firefox 同樣是已知的下載中斷風險
		// （FileSaver.js 長年以 setTimeout 迴避），兩者一起延後才一致。
		// 放在 finally 是因為 click() 可能拋錯 —— 若不保證執行，
		// 會留下孤兒 <a> 且 blob URL 洩漏到頁面重整為止。
		window.setTimeout(() => {
			link.remove()
			URL.revokeObjectURL(objectUrl)
		}, SAVE_CLEANUP_DELAY_MS)
	}
}

/**
 * 明細完整性提示
 *
 * ⚠️「明細已遺失」與「這筆本來就沒有明細」是兩件不同的事，
 * 兩者的 list 都是空陣列，只看筆數會把兩件事混為一談：
 * - is_complete === false：明細曾經存在但已被截斷、無法復原 → 不可用於對帳
 * - source === null：這筆紀錄本來就沒有逐站扣點明細（例如儲值、管理員調整）
 *
 * ⚠️ 探測失敗（probe 為 null / 缺 data）時不可靜音：
 * 該筆若其實是 is_complete false，CSV 已經存下卻沒有任何警告，
 * 對帳者會把截斷的明細當成完整帳目核對。無法確認時要「降級提示」，不是不提示。
 */
const notifyCompleteness = (probe: TDetailProbe | null) => {
	const detail = probe?.data

	if (!detail) {
		notification.warning({
			message: '檔案已存下，但無法確認明細完整性',
			description:
				'完整性探測的回應無法解析，因此無法判斷這份 CSV 是完整明細還是已被截斷的舊格式紀錄。對帳前請先自行核對筆數，或稍後重新下載一次確認。',
			duration: 0,
		})

		return
	}

	if (detail?.is_complete === false) {
		notification.warning({
			message: '明細已遺失，此檔案不可用於對帳',
			description:
				'此紀錄為舊格式，明細內嵌於說明欄且已達長度上限被截斷，無法復原。檔案已存下，但內容只有這段說明、不含任何逐站明細，請勿當成完整帳目核對。',
			duration: 0,
		})

		return
	}

	if (detail?.source === null) {
		notification.info({
			message: '此紀錄沒有逐站扣點明細',
			description:
				'檔案已存下，但這筆紀錄本來就不含逐站扣點明細（與明細已遺失不同）。',
		})
	}
}

/**
 * 下載某筆點數紀錄的逐站扣點明細 CSV
 *
 * ⚠️ 不能用 <a download>：端點是 POST 且帳密要放在 body，
 * 一般連結送不出 body；即使改回 GET，Basic Auth 標頭也掛不上連結。
 * 因此一律 fetch → 檢查 Content-Type → blob → createObjectURL
 * → 程式化觸發存檔 → revokeObjectURL。
 *
 * ⚠️ 明細內容刻意不進 react-query 快取（列表快取是 24 小時），
 * 否則等於把本次要消除的體積問題搬到瀏覽器端，所以這裡用裸 async handler。
 */
export const useDownloadDetail = () => {
	const accountInfo = useAccountInfo()
	const [downloadingMap, setDownloadingMap] = useState<Record<string, boolean>>(
		{}
	)

	// 重複點擊的守衛用 ref，不用 state ——
	// state 更新是非同步的，連點兩下會在 re-render 前就送出第二次請求
	const inFlightRef = useRef<Record<string, boolean>>({})

	const isDownloading = useCallback(
		(logId: string) => downloadingMap[logId] === true,
		[downloadingMap]
	)

	const download = useCallback(
		async (record: DataType) => {
			const logId = String(record?.id || '')

			if (!logId || inFlightRef.current[logId]) {
				return
			}

			if (!accountInfo?.email || !accountInfo?.password) {
				notification.error({
					message: '下載明細失敗：取不到經銷商帳號資訊',
					description: '請重新整理頁面，或重新登入經銷商帳號後再試一次。',
				})

				return
			}

			inFlightRef.current[logId] = true
			setDownloadingMap((prev) => ({ ...prev, [logId]: true }))

			const endpoint = `${cloudApiUrl}/logs/${logId}/detail`

			// permission_callback 仍是 Basic Auth，共用憑證標頭一定要帶；
			// body 帳密是第二道歸屬驗證，兩者不可互相取代。
			// body 不帶 user_id —— 伺服端刻意忽略，帶了會誤導下一位維護者。
			const headers = {
				Authorization: `Basic ${t}`,
				'Content-Type': 'application/json',
			}
			const credentials = {
				email: accountInfo.email,
				password: accountInfo.password,
			}

			// ⚠️ 沒有逾時保護的話，伺服端接了連線卻不回應時（860 站的 CSV 串流本來就慢，
			// 正是最可能發生的情境）fetch 會無限等待 → finally 永遠不執行 →
			// downloadingMap 與 inFlightRef 都清不掉 → 按鈕永遠 loading，
			// 使用者連重試都被擋掉，唯一出路是整頁重整。
			const abortController = new AbortController()
			let timedOut = false
			let timeoutId = 0

			// 兩個階段各自重新計時：JSON 預飛耗掉的時間不從 CSV 的預算扣，
			// 否則本來就慢的 CSV 會被誤判逾時。
			// 計時器不在階段結束時清掉 —— csvResponse.blob() 仍在讀串流，
			// 那段時間同樣需要被 signal 保護，統一在 finally 清除。
			const armTimeout = () => {
				window.clearTimeout(timeoutId)
				timeoutId = window.setTimeout(() => {
					timedOut = true
					abortController.abort()
				}, DOWNLOAD_TIMEOUT_MS)
			}

			try {
				// STEP 1 先以 JSON 探測完整性 ——
				// CSV 回應沒有任何 is_complete 標頭，明細是否已遺失只能從 JSON 讀出來。
				// numberposts=1 讓探測回應維持極小，不會把明細搬進畫面。
				armTimeout()

				const probeResponse = await fetch(endpoint, {
					method: 'POST',
					headers,
					signal: abortController.signal,
					body: JSON.stringify({
						...credentials,
						format: 'json',
						numberposts: 1,
					}),
				})

				if (!probeResponse.ok) {
					notification.error(
						getErrorNotice(
							probeResponse.status,
							await readErrorBody(probeResponse)
						)
					)

					return
				}

				// 探測解析失敗時退回 null（notifyCompleteness 容許），
				// 不讓一次格式異常擋掉使用者真正想要的 CSV
				const probe = (await probeResponse
					.json()
					.catch(() => null)) as TDetailProbe | null

				// STEP 2 取 CSV
				armTimeout()

				const csvResponse = await fetch(endpoint, {
					method: 'POST',
					headers,
					signal: abortController.signal,
					body: JSON.stringify({
						...credentials,
						format: 'csv',
					}),
				})

				if (!csvResponse.ok) {
					notification.error(
						getErrorNotice(csvResponse.status, await readErrorBody(csvResponse))
					)

					return
				}

				// HTTP 200 不代表拿到的是 CSV。
				// 少了這道檢查，使用者會存下一個副檔名正確、內容卻是錯誤訊息的 .csv 而不自知。
				const contentType = (csvResponse.headers.get('content-type') || '')
					.trim()
					.toLowerCase()

				if (!contentType.startsWith('text/csv')) {
					notification.error({
						message: '下載明細失敗：伺服端回應的不是 CSV',
						description: `預期 text/csv，實際收到 ${
							contentType || '（未提供 Content-Type）'
						}，為避免存下錯誤內容已中止下載。`,
					})

					return
				}

				// 伺服端已經寫入 UTF-8 BOM，這裡直接沿用回應的 blob，
				// 不可以再補一次 BOM（會變成兩個，Excel 第一欄會出現亂碼）
				const blob = await csvResponse.blob()
				const filename =
					parseFilenameFromDisposition(
						csvResponse.headers.get('content-disposition')
					) || getFallbackFilename(record)

				saveBlob(blob, filename)
				notifyCompleteness(probe)
			} catch (error) {
				// 逾時與一般網路錯誤要分開講：
				// 使用者需要知道「是太久沒回應」而不是「壞掉了」，
				// 前者重試或縮小範圍有機會成功，後者才是真的異常。
				const isTimeout = timedOut || (error as Error)?.name === 'AbortError'

				notification.error(
					isTimeout
						? {
								message: '下載明細失敗：伺服端太久沒有回應',
								description: `單一階段（完整性探測／CSV 下載）等待超過 ${Math.round(
									DOWNLOAD_TIMEOUT_MS / 1000
								)} 秒仍未收到完整回應，已中止本次下載，檔案未被儲存。兩個階段各自重新計時，因此整體等待時間可能是這個秒數的兩倍。站台數量多時明細會比較久，請稍後再試一次；若持續失敗請聯絡站長路可。`,
							}
						: {
								message: '下載明細失敗：無法完成下載',
								description:
									'可能是網路中斷或伺服端回應異常，檔案未被儲存，請稍後再試一次。',
							}
				)
			} finally {
				window.clearTimeout(timeoutId)
				delete inFlightRef.current[logId]
				setDownloadingMap((prev) => {
					const next = { ...prev }
					delete next[logId]

					return next
				})
			}
		},
		[accountInfo]
	)

	return {
		download,
		isDownloading,
	}
}
