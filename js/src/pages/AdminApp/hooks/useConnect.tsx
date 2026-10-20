import { useMutation, useQueryClient } from '@tanstack/react-query'
import { notification } from 'antd'
import { useSetAtom } from 'jotai'

import { CONNECTION_QUERY_KEY } from './useGetUserIdentity'

import { axios } from '@/api'
import {
	globalLoadingAtom,
	isReconnectingAtom,
} from '@/pages/AdminApp/Atom/atom'
import { TAccountInfo, TConnectionResponse } from '@/pages/AdminApp/types'
import { getErrorMessage } from '@/utils'

const postConnection = async (
	values: TAccountInfo
): Promise<TConnectionResponse> => {
	const res = await axios.post<TConnectionResponse>(
		'/power-partner/connection',
		values
	)

	return res.data
}

/**
 * 以 cloud.luke.cafe 帳密建立本站與雲端的連線
 *
 * 帳密只送到本站 PHP（POST /power-partner/connection），由 PHP 向雲端驗證、
 * 保管連線憑證並寫入 partner_id；瀏覽器不加密、不落地任何帳密。
 * 成功時改寫連線快取，由 useGetUserIdentity 同步 atom 後自動切到 Dashboard。
 *
 * ⚠️ 成敗看 body 的 status：HTTP 200 包著非 200 的 status 代表帳密驗證失敗。
 */
export const useConnect = () => {
	const queryClient = useQueryClient()
	const setGlobalLoading = useSetAtom(globalLoadingAtom)
	const setIsReconnecting = useSetAtom(isReconnectingAtom)

	return useMutation({
		mutationFn: postConnection,
		onMutate: () => {
			setGlobalLoading({
				isLoading: true,
				label: '正在獲取用戶資料...',
			})
		},
		onSuccess: (res) => {
			if (res?.status === 200) {
				queryClient.setQueryData(CONNECTION_QUERY_KEY, res)
				setIsReconnecting(false)

				return
			}

			notification.error({
				message: res?.message || '連結帳號失敗，請確認帳號密碼後再試一次',
			})
		},
		onError: (error) => {
			notification.error({
				message: getErrorMessage(error, '連結帳號失敗，請稍後再試'),
			})
		},
		onSettled: () => {
			setGlobalLoading({
				isLoading: false,
				label: '',
			})
		},
	})
}
