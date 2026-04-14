import { Avatar, Badge, Dropdown, MenuProps, Tooltip } from 'antd'
import {
	identityAtom,
	globalLoadingAtom,
	defaultIdentity,
} from '@/pages/AdminApp/Atom/atom'
import { useAtom } from 'jotai'
import {
	UserOutlined,
	PoweroffOutlined,
	MailOutlined,
	SyncOutlined,
	CrownFilled,
	WalletOutlined,
} from '@ant-design/icons'
import { LOCALSTORAGE_ACCOUNT_KEY } from '@/utils'
import { LoadingText } from '@/components'
import { axios } from '@/api'
import { useQueryClient } from '@tanstack/react-query'
import { useRef } from 'react'

const DEPOSIT_LINK = 'https://cloud.luke.cafe/product/partner-top-up/'

const index = () => {
	const containerRef = useRef<HTMLDivElement>(null)
	const [identity, setIdentity] = useAtom(identityAtom)
	const powerMoney = identity.data?.power_money_amount || '0.00'
	const email = identity.data?.email
	const user_id = identity.data?.user_id || ''
	const partnerLvTitle = identity.data?.partner_lv?.title || ''
	const partnerLvKey = identity.data?.partner_lv?.key || '0'
	const [globalLoading, setGlobalLoading] = useAtom(globalLoadingAtom)
	const queryClient = useQueryClient()

	const handleDisconnect = async () => {
		setGlobalLoading({
			isLoading: true,
			label: '正在解除帳號綁定...',
		})
		try {
			await axios.delete('/power-partner/partner-id')
		} catch (error) {
			console.log('error', error)
		}
		localStorage.removeItem(LOCALSTORAGE_ACCOUNT_KEY)
		setIdentity(defaultIdentity)
		setGlobalLoading({
			isLoading: false,
			label: '',
		})
	}

	const handleRefetch = () => {
		;[
			'apps',
			'logs',
			'license-codes',
			'identity',
			'subscriptions/next-payment',
		].forEach((key) => {
			queryClient.invalidateQueries({
				queryKey: [key],
			})
		})
	}

	const items: MenuProps['items'] = [
		{
			key: 'user_id',
			label: `#${user_id}`,
			icon: <UserOutlined />,
		},
		{
			key: 'email',
			label: <span className="text-xs">{email || ''}</span>,
			icon: <MailOutlined />,
		},
		{
			key: 'wallet',
			label: (
				<a target="_blank" rel="noopener noreferrer" href={DEPOSIT_LINK}>
					前往儲值
				</a>
			),
			icon: <WalletOutlined />,
		},
		{
			type: 'divider',
		},
		{
			key: 'disconnect',
			label: <span onClick={handleDisconnect}>解除帳號綁定</span>,
			icon: <PoweroffOutlined className="text-red-500" />,
			danger: true,
		},
	]

	return (
		<div
			className="ml-4 xl:mr-4 flex items-center gap-4"
			ref={containerRef}
		>
			<Tooltip
				title="刷新資料"
				getPopupContainer={() => containerRef.current as HTMLElement}
			>
				<SyncOutlined
					spin={globalLoading?.isLoading}
					onClick={handleRefetch}
					className="cursor-pointer text-gray-500 hover:text-primary"
				/>
			</Tooltip>

			{partnerLvTitle && (
				<Tooltip
					title={
						partnerLvKey === '2'
							? '您已是最高階經銷商'
							: '升級為高階經銷商，享受更高主機折扣'
					}
					getPopupContainer={() => containerRef.current as HTMLElement}
				>
					<a
						target="_blank"
						rel="noopener noreferrer"
						href={DEPOSIT_LINK}
						className="flex items-center gap-1 rounded-full bg-amber-50 px-3 py-1 text-sm no-underline"
					>
						<CrownFilled
							className={`${
								partnerLvKey === '2' ? 'text-yellow-500' : 'text-gray-300'
							}`}
						/>
						<LoadingText
							isLoading={globalLoading?.isLoading}
							content={
								<span className="text-gray-700 text-sm">{partnerLvTitle}</span>
							}
						/>
					</a>
				</Tooltip>
			)}

			<Tooltip
				title="前往儲值"
				getPopupContainer={() => containerRef.current as HTMLElement}
			>
				<a
					target="_blank"
					rel="noopener noreferrer"
					href={DEPOSIT_LINK}
					className="flex items-center gap-1 text-sm no-underline"
				>
					<span className="text-yellow-500 font-bold">¥</span>
					<LoadingText
						isLoading={globalLoading?.isLoading}
						content={
							<span className="text-gray-700 font-medium">{powerMoney}</span>
						}
					/>
				</a>
			</Tooltip>

			<Dropdown
				menu={{ items }}
				placement="bottomRight"
				trigger={['click']}
				getPopupContainer={() => containerRef.current as HTMLElement}
			>
				<Badge dot status="success" offset={[-4, 4]}>
					<Avatar
						size={36}
						className="cursor-pointer"
						style={{ backgroundColor: '#1677ff', color: '#fff', fontWeight: 600 }}
					>
						{(email || 'U').charAt(0).toUpperCase()}
					</Avatar>
				</Badge>
			</Dropdown>
		</div>
	)
}

export default index
