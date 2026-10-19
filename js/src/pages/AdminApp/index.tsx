import Login from '@/pages/AdminApp/Login'
import Dashboard from '@/pages/AdminApp/Dashboard'
import {
	useGetUserIdentity,
	useMigratePowercloudApiKey,
} from '@/pages/AdminApp/hooks'
import { Spin } from 'antd'
import {
	globalLoadingAtom,
	identityAtom,
	isReconnectingAtom,
} from './Atom/atom'
import { useAtomValue } from 'jotai'

function AdminApp() {
	useMigratePowercloudApiKey()
	const { isPending } = useGetUserIdentity()
	const globalLoading = useAtomValue(globalLoadingAtom)
	const identity = useAtomValue(identityAtom)
	const isReconnecting = useAtomValue(isReconnectingAtom)
	const identityData = identity?.data
	const status = identity?.status

	return (
		<div className="flex min-h-[24rem] tailwind">
			<Spin
				size="large"
				wrapperClassName="w-full"
				spinning={globalLoading?.isLoading || isPending}
				tip={globalLoading?.isLoading ? globalLoading?.label : 'Loading...'}
			>
				{status === 200 && identityData && !isReconnecting ? (
					<Dashboard />
				) : (
					<Login />
				)}
			</Spin>
		</div>
	)
}

export default AdminApp
