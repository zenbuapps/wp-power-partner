import { useMutation } from '@tanstack/react-query'
import { Button, Form, Input, Alert, notification } from 'antd'
import { useAtomValue, useSetAtom } from 'jotai'
import ContentCard from '@/components/ContentCard'

import { powerCloudAxios, axios } from '@/api'
import { globalLoadingAtom } from '@/pages/AdminApp/Atom/atom'
import {
	EPowercloudIdentityStatusEnum,
	powercloudIdentityAtom,
} from '@/pages/AdminApp/Atom/powercloud.atom'
import { TAccountInfo } from '@/pages/AdminApp/types'
import { renderHTML, kebab } from '@/utils'

const Login = () => {
	const [form] = Form.useForm()
	const setIdentity = useSetAtom(powercloudIdentityAtom)
	const setGlobalLoading = useSetAtom(globalLoadingAtom)

	// powercloud login
	const { mutate: powercloudLogin, isPending: isPendingPowercloudLogin } =
		useMutation({
			mutationFn: (values: { email: string; password: string }) =>
				powerCloudAxios.post('/auth/api-key', values),
			onMutate: (values: { email: string; password: string }) => {
				setGlobalLoading({
					isLoading: true,
					label: '正在獲取 Powercloud API Key...',
				})
			},
			onSettled: () => {
				setGlobalLoading({
					isLoading: false,
					label: '',
				})
			},
		})

	const onFinish = () => {
		const values: TAccountInfo = form.getFieldsValue()
		powercloudLogin(values, {
			onSuccess: async (res) => {
				const apiKey = res?.data?.apiKey

				setIdentity({
					status: EPowercloudIdentityStatusEnum.LOGGED_IN,
					message: '',
					apiKey,
				})

				// 保存 apiKey 到 WordPress usermeta
				try {
					await axios.post(`/${kebab}/powercloud-api-key`, {
						api_key: apiKey,
					})
				} catch (error: any) {
					console.error('Failed to save API key to WordPress:', error)
					notification.warning({
						message: '登入成功，但保存 API Key 到 WordPress 失敗',
						description:
							error?.response?.data?.message || error?.message || '請稍後再試',
					})
				}
			},
		})
	}

	const isPending = isPendingPowercloudLogin
	return (
		<div className="w-full max-w-[20rem] relative m-auto mt-12">
			<Alert
				message="請輸入你在 Powercloud 的帳號密碼"
				type="info"
				showIcon
				className="mb-8"
			/>
			<Form form={form} autoComplete="off" layout="vertical">
				<Form.Item<TAccountInfo>
					label="E-mail"
					name="email"
					rules={[
						{ required: true, message: '請輸入 Email' },
						{ type: 'email', message: '請輸入有效的 Email' },
					]}
				>
					<Input size="large" disabled={isPending} />
				</Form.Item>

				<Form.Item<TAccountInfo>
					label="Password"
					name="password"
					rules={[{ required: true, message: '請輸入密碼' }]}
				>
					<Input.Password size="large" disabled={isPending} />
				</Form.Item>

				<Form.Item>
					<Button
						type="primary"
						onClick={onFinish}
						className="w-full"
						size="large"
						loading={isPending}
					>
						連結帳號
					</Button>
				</Form.Item>
			</Form>
		</div>
	)
}

const Logout = () => {
	const setIdentity = useSetAtom(powercloudIdentityAtom)

	const { mutate: deleteApiKey, isPending: isPendingDelete } = useMutation({
		mutationFn: () => axios.delete(`/${kebab}/powercloud-api-key`),
	})

	const handleLogout = () => {
		deleteApiKey(undefined, {
			onSuccess: () => {
				setIdentity({
					status: EPowercloudIdentityStatusEnum.UN_LOGIN,
					message: '',
					apiKey: '',
				})
			},
			onError: (error: any) => {
				notification.error({
					message: '登出失敗',
					description:
						error?.response?.data?.message || error?.message || '請稍後再試',
				})
			},
		})
	}
	return (
		<div>
			<Button
				variant="outlined"
				danger
				onClick={handleLogout}
				loading={isPendingDelete}
			>
				登出
			</Button>
		</div>
	)
}

const index = () => {
	const powercloudIdentity = useAtomValue(powercloudIdentityAtom)
	if (powercloudIdentity.status === EPowercloudIdentityStatusEnum.UN_LOGIN)
		return (
			<ContentCard>
				<Login />
			</ContentCard>
		)
	return (
		<ContentCard>
			<Logout />
		</ContentCard>
	)
}

export default index
