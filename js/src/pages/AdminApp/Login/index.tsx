import { Button, Form, Input, Alert } from 'antd'
import { useAtom } from 'jotai'

import { isReconnectingAtom } from '@/pages/AdminApp/Atom/atom'
import { useConnect } from '@/pages/AdminApp/hooks'
import { TAccountInfo } from '@/pages/AdminApp/types'

/**
 * 經銷商帳號登入
 *
 * 帳密送到本站 PHP 建立連線（useConnect），成功後自動切到 Dashboard。
 * 「重新登入」時（isReconnectingAtom）原本的連線仍有效，因此提供「取消」回到 Dashboard。
 */
const Login = () => {
	const [form] = Form.useForm()
	const [isReconnecting, setIsReconnecting] = useAtom(isReconnectingAtom)
	const { mutate: connect, isPending } = useConnect()

	const onFinish = () => {
		const values: TAccountInfo = form.getFieldsValue()
		connect(values)
	}

	return (
		<div className="w-full max-w-[20rem] relative m-auto mt-12">
			<Alert
				message="請輸入你在 站長路可 cloud.luke.cafe 的帳號密碼"
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

				{isReconnecting && (
					<Form.Item>
						<Button
							onClick={() => setIsReconnecting(false)}
							className="w-full"
							size="large"
							disabled={isPending}
						>
							取消，維持目前的連線
						</Button>
					</Form.Item>
				)}
			</Form>
		</div>
	)
}

export default Login
