import { useMutation, useQueryClient } from '@tanstack/react-query'
import { notification } from 'antd'
import { useSetAtom } from 'jotai'

import {
	CONNECTION_QUERY_KEY,
	DISCONNECTED_RESPONSE,
} from './useGetUserIdentity'

import { axios } from '@/api'
import { globalLoadingAtom } from '@/pages/AdminApp/Atom/atom'
import { getErrorMessage } from '@/utils'

/**
 * 解除本站與雲端的帳號綁定
 *
 * 由 PHP（DELETE /power-partner/connection）撤銷連線憑證並清除 partner_id。
 * 成功後把連線快取改寫成「未連線」，由 useGetUserIdentity 同步 atom 後切回登入畫面。
 *
 * ⚠️ 失敗時不切回登入畫面：PHP 端的連線仍在，畫面若顯示已解除，
 * 使用者會以為站台已斷線，重新整理後卻又自動登入。
 */
export const useDisconnect = () => {
	const queryClient = useQueryClient()
	const setGlobalLoading = useSetAtom(globalLoadingAtom)

	return useMutation({
		mutationFn: () => axios.delete('/power-partner/connection'),
		onMutate: () => {
			setGlobalLoading({
				isLoading: true,
				label: '正在解除帳號綁定...',
			})
		},
		onSuccess: () => {
			queryClient.setQueryData(CONNECTION_QUERY_KEY, DISCONNECTED_RESPONSE)
		},
		onError: (error) => {
			notification.error({
				message: getErrorMessage(error, '解除帳號綁定失敗，請稍後再試'),
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
