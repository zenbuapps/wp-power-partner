import { LoadingOutlined } from '@ant-design/icons'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { FormInstance, notification } from 'antd'
import { useSetAtom } from 'jotai'

import { axios } from '@/api'
import { allowDeleteSiteAtom } from '@/pages/AdminApp/Atom/settings.atom'
import { DataType } from '@/pages/AdminApp/Dashboard/EmailSetting/types'

export type TFormValues = {
	power_partner_disable_site_after_n_days: number
	power_partner_allow_delete_site: boolean
	emails: DataType[]
}

const useSave = (form: FormInstance<TFormValues>) => {
	const queryClient = useQueryClient()
	const setAllowDeleteSite = useSetAtom(allowDeleteSiteAtom)
	const [api, contextHolder] = notification.useNotification({
		placement: 'bottomRight',
		stack: { threshold: 1 },
		duration: 10,
	})

	const mutation = useMutation({
		mutationFn: (values: TFormValues) =>
			axios.post('/power-partner/settings', values),
		onMutate: () => {
			api.open({
				key: 'save-settings',
				message: '儲存 設定 中...',
				duration: 0,
				icon: <LoadingOutlined className="text-primary" />,
			})
		},
		onError: (err) => {
			console.log('err', err)
			api.error({
				key: 'save-settings',
				message: 'OOPS! 儲存 設定 時發生問題',
			})
		},
		onSuccess: (data, variables) => {
			const status = data?.data?.status
			const message = data?.data?.message

			if (200 === status) {
				api.success({
					key: 'save-settings',
					message: '儲存 設定 成功',
				})

				// 同步回 atom，讓「允許刪除站台」開關存檔後立即生效，不必重整頁面
				setAllowDeleteSite(!!variables.power_partner_allow_delete_site)
				queryClient.invalidateQueries({ queryKey: ['emails'] })
			} else {
				api.error({
					key: 'save-settings',
					message: 'OOPS! 儲存 設定 時發生問題',
					description: message,
				})
			}
		},
	})

	return {
		contextHolder,
		mutation,
	}
}

export default useSave
