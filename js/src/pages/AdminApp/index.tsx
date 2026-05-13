import Login from '@/pages/AdminApp/Login'
import Dashboard from '@/pages/AdminApp/Dashboard'
import {
	useGetUserIdentity,
	useMigratePowercloudApiKey,
} from '@/pages/AdminApp/hooks'
import { Spin } from 'antd'
import { globalLoadingAtom, identityAtom } from './Atom/atom'
import { useAtomValue } from 'jotai'

function AdminApp() {
	useMigratePowercloudApiKey()
	const mutation = useGetUserIdentity()
	const globalLoading = useAtomValue(globalLoadingAtom)
	const identity = useAtomValue(identityAtom)
	const identityData = identity?.data
	const status = identity?.status

	return (
		<div className="flex min-h-[24rem] tailwind">
			<Spin
				size="large"
				wrapperClassName="w-full"
				spinning={globalLoading?.isLoading || mutation?.isPending}
				tip={globalLoading?.isLoading ? globalLoading?.label : 'Loading...'}
			>
				{status === 200 && identityData ? <Dashboard /> : <Login />}
			</Spin>
		</div>
	)
}

export default AdminApp
