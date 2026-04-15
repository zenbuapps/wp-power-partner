import {
	LinkOutlined,
	ReloadOutlined,
	SettingOutlined,
	StopOutlined,
	SyncOutlined,
} from '@ant-design/icons'
import { useQuery, useMutation } from '@tanstack/react-query'
import {
	Button,
	Empty,
	Popconfirm,
	Space,
	Spin,
	Table,
	Tag,
	Tooltip,
	Typography,
} from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { useSetAtom } from 'jotai'
import { useEffect, useMemo, useState } from 'react'

import { powerCloudAxios, usePowerCloudAxiosWithApiKey } from '@/api'
import { globalLoadingAtom } from '@/pages/UserApp/atom'
import { currentUserEmail } from '@/utils'

const { Text, Link } = Typography

const statusColorMap: Record<string, string> = {
	creating: 'processing',
	running: 'success',
	stopped: 'warning',
	deleting: 'error',
}

const statusTextMap: Record<string, string> = {
	creating: '建置中',
	running: '運行中',
	stopped: '已停止',
	deleting: '刪除中',
}

interface IWebsite {
	id: string
	name: string
	domain?: string
	primaryDomain?: string
	subDomain?: string
	wildcardDomain: string
	namespace: string
	status: string
	adminUsername: string
	adminEmail: string
	adminPassword: string
	databaseName: string
	databaseUsername: string
	databasePassword: string | null
	databaseRootPassword: string | null
	package: {
		id: string
		name: string
		description: string
		price: string
		wordpressSize: string
		mysqlSize: string
	} | null
	user: {
		id: string
		firstName: string
		lastName: string
		email: string
	} | null
	phpPodSize: number
	ipAddress: string
	memo?: string
	createdAt: string
	updatedAt: string
}

interface IWebsiteResponse {
	data: IWebsite[]
	total: number
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

const Powercloud = () => {
	const powerCloudInstance = usePowerCloudAxiosWithApiKey(powerCloudAxios)
	const setGlobalLoading = useSetAtom(globalLoadingAtom)

	// 一次拉足夠多的資料，前端用 adminEmail 過濾當前客戶的網站
	const [pagination] = useState({ page: 1, limit: 100 })

	const { data, isLoading, refetch, isFetching } = useQuery({
		queryKey: [
			'powercloud-websites-user',
			pagination.page,
			pagination.limit,
		],
		queryFn: () =>
			powerCloudInstance.get<IWebsiteResponse>(
				`/websites?page=${pagination.page}&limit=${pagination.limit}`
			),
	})

	const { mutate: startWebsite } = useMutation({
		mutationFn: (id: string) => {
			return powerCloudInstance.patch(`/wordpress/${id}/start`)
		},
		onSuccess: () => refetch(),
	})

	const { mutate: stopWebsite } = useMutation({
		mutationFn: (id: string) => {
			return powerCloudInstance.patch(`/wordpress/${id}/stop`)
		},
		onSuccess: () => refetch(),
	})

	// 用 adminEmail 過濾當前客戶的網站
	const allWebsites = data?.data?.data || []
	const websites = useMemo(
		() =>
			allWebsites.filter(
				(site) =>
					site.adminEmail?.toLowerCase() === currentUserEmail.toLowerCase()
			),
		[allWebsites, currentUserEmail]
	)

	useEffect(() => {
		if (!isLoading) {
			setGlobalLoading({
				isLoading: false,
				label: '',
			})
		}
	}, [isLoading])

	const columns: ColumnsType<IWebsite> = [
		{
			title: '網站名稱',
			dataIndex: 'name',
			key: 'name',
			ellipsis: true,
			width: 300,
			render: (name: string, record) => (
				<Space direction="vertical" size={0}>
					<Link
						href={`https://${getDomain(record)}`}
						target="_blank"
						style={{ fontSize: 14 }}
					>
						<LinkOutlined /> {getDomain(record)}
					</Link>
					<Text className="text-xs text-gray-500">{name}</Text>
				</Space>
			),
		},
		{
			title: '狀態',
			dataIndex: 'status',
			key: 'status',
			width: 100,
			render: (status: string) => (
				<Tag color={statusColorMap[status] || 'default'}>
					{statusTextMap[status] || status}
				</Tag>
			),
		},
		{
			title: 'IP 位址',
			dataIndex: 'ipAddress',
			key: 'ipAddress',
			width: 150,
			render: (ipAddress: string) => (
				<Text copyable={{ text: ipAddress }}>{ipAddress}</Text>
			),
		},
		{
			title: 'WordPress 管理員信箱',
			dataIndex: 'adminEmail',
			key: 'adminEmail',
			ellipsis: true,
			width: 250,
			render: (email: string) => (
				<Text copyable ellipsis>
					{email}
				</Text>
			),
		},
		{
			title: 'WordPress 管理員密碼',
			key: 'adminPassword',
			width: 250,
			render: (_, record) => (
				<Text copyable={{ text: record.adminPassword }}>••••••••</Text>
			),
		},
		{
			title: '備註',
			dataIndex: 'memo',
			key: 'memo',
			width: 150,
			ellipsis: true,
			render: (memo?: string) => (
				<Text ellipsis={{ tooltip: memo }}>{memo || '-'}</Text>
			),
		},
		{
			title: '建立時間',
			dataIndex: 'createdAt',
			key: 'createdAt',
			width: 200,
			render: (date: string) => (
				<Text type="secondary">
					{new Date(date).toLocaleString('zh-TW', {
						year: 'numeric',
						month: '2-digit',
						day: '2-digit',
						hour: '2-digit',
						minute: '2-digit',
					})}
				</Text>
			),
		},
		{
			title: '操作',
			key: 'actions',
			fixed: 'right',
			width: 120,
			render: (_, record) => {
				return (
					<Space>
						<Tooltip title="前往後台">
							<Button
								type="link"
								size="small"
								icon={<SettingOutlined />}
								href={`https://${getDomain(record)}/wp-admin`}
								target="_blank"
							/>
						</Tooltip>
						{record.status === 'stopped' && (
							<Popconfirm
								title="確認啟動站台"
								description={`確定要啟動站台 ${getDomain(record)} 嗎？`}
								onConfirm={() => startWebsite(record.id)}
								okText="確認啟動"
								cancelText="取消"
							>
								<Tooltip title="啟動站台">
									<Button type="link" size="small" icon={<SyncOutlined />} />
								</Tooltip>
							</Popconfirm>
						)}
						{record.status === 'running' && (
							<Popconfirm
								title="確認停止站台"
								description={`確定要停止站台 ${getDomain(record)} 嗎？`}
								onConfirm={() => stopWebsite(record.id)}
								okText="確認停止"
								cancelText="取消"
								okButtonProps={{ danger: true }}
							>
								<Tooltip title="停止站台">
									<Button
										type="link"
										size="small"
										danger
										icon={<StopOutlined />}
									/>
								</Tooltip>
							</Popconfirm>
						)}
					</Space>
				)
			},
		},
	]

	if (isLoading) {
		return (
			<div style={{ textAlign: 'center', padding: '60px 0' }}>
				<Spin size="large" />
				<div style={{ marginTop: 16 }}>
					<Text type="secondary">載入網站列表中...</Text>
				</div>
			</div>
		)
	}

	if (!websites.length) {
		return (
			<Empty description="尚無新架構網站資料" style={{ padding: '60px 0' }} />
		)
	}

	return (
		<div>
			<div
				style={{
					marginBottom: 16,
					display: 'flex',
					justifyContent: 'space-between',
					alignItems: 'center',
				}}
			>
				<Text type="secondary">共 {websites.length} 個網站</Text>
				<Button
					icon={<ReloadOutlined spin={isFetching} />}
					onClick={() => refetch()}
					loading={isFetching}
				>
					重新整理
				</Button>
			</div>
			<Table
				columns={columns}
				dataSource={websites}
				rowKey="id"
				loading={isFetching}
				scroll={{ x: 1000 }}
				pagination={{
					showSizeChanger: true,
					showQuickJumper: true,
					pageSizeOptions: ['10', '20', '50'],
					showTotal: (total, range) =>
						`第 ${range[0]}-${range[1]} 筆，共 ${total} 筆`,
				}}
			/>
		</div>
	)
}

export default Powercloud
