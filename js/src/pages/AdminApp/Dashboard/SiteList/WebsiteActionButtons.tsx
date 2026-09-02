import {
	DeleteOutlined,
	EllipsisOutlined,
	GlobalOutlined,
	PlayCircleOutlined,
	SettingOutlined,
	StopOutlined,
} from '@ant-design/icons'
import { Button, Dropdown, Modal, Popconfirm, Tooltip } from 'antd'
import type { MenuProps } from 'antd'
import { Link } from 'react-router'
import type { IWebsite } from './types'

interface WebsiteActionButtonsProps {
	record: IWebsite
	onStart: (id: string) => void
	onStop: (id: string) => void
	onDelete: (id: string) => void
	onChangeDomain: (website: IWebsite) => void

	/**
	 * 是否顯示「刪除網站」選項（來自設定 power_partner_allow_delete_site）
	 *
	 * ⚠️ 這是 UI 層防呆，與 Popconfirm / Modal.confirm 同層級，不是安全邊界。
	 * 刪除請求由前端 powerCloudInstance.delete('/wordpress/{id}') 直接打 PowerCloud API，
	 * 不經過 WP 後端，因此真正的權限控制必須由 PowerCloud API 端依 API key 實施，
	 * 前端無法保證。請勿把這個 prop 當成伺服器端授權檢查。
	 */
	allowDelete: boolean
}

const getDomain = (website: IWebsite): string => {
	return (
		website.primaryDomain ||
		website.domain ||
		website.subDomain ||
		website.wildcardDomain ||
		''
	)
}

const WebsiteActionButtons = ({
	record,
	onStart,
	onStop,
	onDelete,
	onChangeDomain,
	allowDelete,
}: WebsiteActionButtonsProps) => {
	const domain = getDomain(record)
	const isRunning = record.status === 'running'
	const isStopped = record.status === 'stopped'
	const isUpdating =
		record.status === 'creating' || record.status === 'updating'

	const buildDropdownItems = (): MenuProps['items'] => {
		const serviceItems: MenuProps['items'] = [
			{
				key: 'change-domain',
				icon: <GlobalOutlined />,
				label: '變更域名',
				disabled: isStopped,
				onClick: () => onChangeDomain(record),
			},
		]

		const items: MenuProps['items'] = [
			{
				key: 'service-group',
				type: 'group' as const,
				label: '服務管理',
				children: serviceItems,
			},
		]

		// 未開啟「允許刪除站台」時，連同 divider 與群組標題一起不渲染，避免留下空群組
		if (allowDelete) {
			const dangerItems: MenuProps['items'] = [
				{
					key: 'delete',
					icon: <DeleteOutlined />,
					label: '刪除網站',
					danger: true,
					onClick: () => {
						Modal.confirm({
							title: '刪除網站',
							content: `確定要刪除站台 ${domain} 嗎？此操作無法復原。`,
							okText: '確認刪除',
							cancelText: '取消',
							okButtonProps: { danger: true },
							onOk: () => onDelete(record.id),
						})
					},
				},
			]

			items.push(
				{ type: 'divider' as const },
				{
					key: 'danger-group',
					type: 'group' as const,
					label: '危險操作',
					children: dangerItems,
				}
			)
		}

		return items
	}

	return (
		<div
			className={`flex items-center justify-center gap-1 ${
				isUpdating ? 'pointer-events-none opacity-50' : ''
			}`}
		>
			<a
				href={`https://${domain}/wp-admin`}
				target="_blank"
				rel="noreferrer"
			>
				<Tooltip title="前往 WordPress 後台">
					<Button icon={<GlobalOutlined />} size="small" type="text" />
				</Tooltip>
			</a>

			<Link to={`/websites/edit/${record.id}`}>
				<Tooltip title="編輯">
					<Button icon={<SettingOutlined />} size="small" type="text" />
				</Tooltip>
			</Link>

			{isRunning && (
				<Popconfirm
					title="確認停止站台"
					description={`確定要停止站台 ${domain} 嗎？`}
					onConfirm={() => onStop(record.id)}
					okText="確認停止"
					cancelText="取消"
					okButtonProps={{ danger: true }}
				>
					<Tooltip title="停止站台">
						<Button
							icon={<StopOutlined />}
							size="small"
							type="text"
							danger
						/>
					</Tooltip>
				</Popconfirm>
			)}

			{isStopped && (
				<Popconfirm
					title="確認啟動站台"
					description={`確定要啟動站台 ${domain} 嗎？`}
					onConfirm={() => onStart(record.id)}
					okText="確認啟動"
					cancelText="取消"
				>
					<Tooltip title="啟動站台">
						<Button icon={<PlayCircleOutlined />} size="small" type="text" />
					</Tooltip>
				</Popconfirm>
			)}

			<Dropdown
				menu={{ items: buildDropdownItems() }}
				trigger={['click']}
				placement="bottomRight"
			>
				<Button icon={<EllipsisOutlined />} size="small" type="text" />
			</Dropdown>
		</div>
	)
}

export default WebsiteActionButtons
