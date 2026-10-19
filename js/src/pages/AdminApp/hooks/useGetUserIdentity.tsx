import { useQuery } from '@tanstack/react-query'
import { notification } from 'antd'
import { useSetAtom } from 'jotai'
import { useEffect } from 'react'

import { axios } from '@/api'
import {
	connectionAtom,
	defaultConnection,
	globalLoadingAtom,
	identityAtom,
} from '@/pages/AdminApp/Atom/atom'
import { TConnectionResponse, TIdentity } from '@/pages/AdminApp/types'
import { getErrorMessage } from '@/utils'

/**
 * 連線狀態的 query key
 *
 * 這份快取是 identityAtom / connectionAtom 的唯一來源：
 * 登入（useConnect）與解除綁定（useDisconnect）都是改寫這份快取，
 * 再由 useGetUserIdentity 同步到 atom，不各自寫 atom，避免兩邊不一致。
 */
export const CONNECTION_QUERY_KEY = ['connection']

/** 沒有任何連線時的回應，與 PHP 端 GET /connection 未連線時的回應相同 */
export const DISCONNECTED_RESPONSE: TConnectionResponse = {
	status: 401,
	connection: { mode: 'none' },
}

/** 未連線（顯示登入畫面即可，不是錯誤，不該跳錯誤通知） */
const isDisconnected = (res: TConnectionResponse) =>
	res.status === 401 && (res.connection?.mode ?? 'none') === 'none'

const toIdentity = (res: TConnectionResponse): TIdentity => ({
	status: res.status,
	message: res.message ?? '',
	data: res.status === 200 ? (res.data ?? null) : null,
})

const getConnection = async (): Promise<TConnectionResponse> => {
	const res = await axios.get<TConnectionResponse>('/power-partner/connection')

	return res.data
}

/**
 * 取得本站與雲端的連線狀態與經銷商身分
 *
 * 帳密與專屬金鑰都由 PHP 保管，瀏覽器只問「現在連上了誰」：
 * - status 200：已連線，data 與舊版 POST /identity 回應的 data 相同 → 顯示 Dashboard
 * - status 401 + mode none：尚未連線 → 顯示 Login（不跳錯誤通知）
 * - 其他：連線存在但驗證失敗（例如雲端暫時無法連線）→ 顯示 Login 並提示 message
 */
export const useGetUserIdentity = () => {
	const setIdentity = useSetAtom(identityAtom)
	const setConnection = useSetAtom(connectionAtom)
	const setGlobalLoading = useSetAtom(globalLoadingAtom)

	// 與舊版相同，每次載入頁面只問一次；不因網路重連等事件自動重抓，
	// 否則一次暫時性的雲端失敗就會把正在操作的經銷商踢回登入畫面
	const query = useQuery({
		queryKey: CONNECTION_QUERY_KEY,
		queryFn: getConnection,
		staleTime: Infinity,
	})
	const { data, error, isPending } = query

	useEffect(() => {
		setGlobalLoading(
			isPending
				? { isLoading: true, label: '正在獲取用戶資料...' }
				: { isLoading: false, label: '' }
		)
	}, [isPending, setGlobalLoading])

	useEffect(() => {
		if (!data) {
			return
		}

		setIdentity(toIdentity(data))
		setConnection(data.connection ?? defaultConnection)

		if (data.status !== 200 && !isDisconnected(data)) {
			notification.error({
				message: data.message || '取得經銷商帳號資訊失敗，請重新登入',
			})
		}
	}, [data, setIdentity, setConnection])

	useEffect(() => {
		if (!error) {
			return
		}

		notification.error({
			message: getErrorMessage(
				error,
				'無法取得經銷商連線狀態，請重新整理頁面後再試'
			),
		})
	}, [error])

	return query
}
