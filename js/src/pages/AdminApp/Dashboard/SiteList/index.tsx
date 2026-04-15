import {
	CloudOutlined,
	CopyOutlined,
	GlobalOutlined,
	LinkOutlined,
	PlusOutlined,
	ReloadOutlined,
	SyncOutlined,
} from '@ant-design/icons'
import { useMutation, useQuery } from '@tanstack/react-query'
import {
	Alert,
	Button,
	Form,
	Input,
	InputNumber,
	message,
	Modal,
	Popconfirm,
	Space,
	Spin,
	Table,
	Tabs,
	TabsProps,
	Tag,
	Tooltip,
	Typography,
} from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { useAtomValue, useSetAtom } from 'jotai'
import { useCallback, useEffect, useState } from 'react'

import {
	EPowercloudIdentityStatusEnum,
	powercloudIdentityAtom,
} from '../../Atom/powercloud.atom'
import { setTabAtom, TabKeyEnum } from '../../Atom/tab.atom'

import { powerCloudAxios, usePowerCloudAxiosWithApiKey } from '@/api'
import {
	SiteListTable,
	useCustomers,
	useTable,
} from '@/components/SiteListTable'
import ContentCard from '@/components/ContentCard'
import { globalLoadingAtom, identityAtom } from '@/pages/AdminApp/Atom/atom'
import WebsiteActionButtons from './WebsiteActionButtons'
import WebsiteListFilter, {
	defaultFilters,
	WebsiteFilters,
} from './WebsiteListFilter'
import type { IWebsite, IWebsiteResponse } from './types'
import { useSubscriptionApps } from './hooks/useSubscriptionApps'
import { SubscriptionBinding } from './SubscriptionBinding'

const { Text, Link } = Typography

// 網站狀態對應的顏色
const statusColorMap: Record<string, string> = {
	creating: 'processing',
	running: 'success',
	stopped: 'warning',
	deleting: 'error',
}

// 網站狀態對應的文字
const statusTextMap: Record<string, string> = {
	creating: '建置中',
	running: '運行中',
	stopped: '已停止',
	deleting: '刪除中',
}

// 容器數量編輯組件
const PodSizeEditor = ({
	initialValue,
	domain,
	packagePrice,
	onUpdate,
	disabled,
}: {
	initialValue: number
	domain: string
	packagePrice?: string
	onUpdate: (value: number) => void
	disabled?: boolean
}) => {
	const [value, setValue] = useState(initialValue)

	const dailyCostPerPod = +(+(packagePrice ?? 0) / 365).toFixed(2)
	const dailyCost = +(dailyCostPerPod * (1 + 0.6 * (value - 1))).toFixed(2)

	useEffect(() => {
		setValue(initialValue)
	}, [initialValue])

	return (
		<div className="flex gap-2 items-center">
			<InputNumber
				min={1}
				max={10}
				value={value}
				onChange={(v) => setValue(v ?? 1)}
				size="small"
				disabled={disabled}
			/>
			<Tooltip title="更新容器數量">
				<Popconfirm
					title="確認更新容器數量"
					description={
						<div className="flex flex-col gap-1">
							<div>
								確定要將站台 <strong>{domain}</strong> 的容器數量更新為{' '}
								<strong>{value}</strong> 個嗎？
							</div>
							<div className="mt-2 text-xs text-gray-500">
								<div>
									計算公式：每日扣款價格 X 1 + 每日扣款價格 X 額外容器數量 X 0.6
								</div>
								<div>
									= {dailyCostPerPod} X 1 + {dailyCostPerPod} X ({value} - 1) X
									0.6
								</div>
								<div>= NT$ {dailyCost}/日</div>
							</div>
							<div className="mt-2 font-medium">
								每日預計扣款：
								<span className="text-blue-600">NT$ {dailyCost}/日</span>
							</div>
						</div>
					}
					onConfirm={() => onUpdate(value)}
					okText="確認更新"
					cancelText="取消"
					disabled={disabled}
				>
					<Button
						type="link"
						size="small"
						icon={<SyncOutlined />}
						disabled={disabled}
					/>
				</Popconfirm>
			</Tooltip>
		</div>
	)
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

const PowercloudContent = () => {
	const setTab = useSetAtom(setTabAtom)
	const powerCloudInstance = usePowerCloudAxiosWithApiKey(powerCloudAxios)
	const [pagination, setPagination] = useState({ page: 1, limit: 10 })
	const [searchFilters, setSearchFilters] = useState<WebsiteFilters | null>(
		null
	)
	const [isChangeDomainModalOpen, setIsChangeDomainModalOpen] = useState(false)
	const [selectedWebsite, setSelectedWebsite] = useState<IWebsite | null>(null)
	const [form] = Form.useForm()

	const { data, isLoading, refetch, isFetching } = useQuery({
		queryKey: [
			'powercloud-websites',
			pagination.page,
			pagination.limit,
			searchFilters,
		],
		queryFn: () => {
			const params = new URLSearchParams({
				page: String(pagination.page),
				limit: String(pagination.limit),
			})
			if (searchFilters) {
				if (searchFilters.websiteKeyword)
					params.set('websiteKeyword', searchFilters.websiteKeyword)
				if (searchFilters.userKeyword)
					params.set('userKeyword', searchFilters.userKeyword)
				if (searchFilters.status) params.set('status', searchFilters.status)
				if (searchFilters.startDailyCostPrice != null)
					params.set(
						'startDailyCostPrice',
						String(searchFilters.startDailyCostPrice)
					)
				if (searchFilters.endDailyCostPrice != null)
					params.set(
						'endDailyCostPrice',
						String(searchFilters.endDailyCostPrice)
					)
				if (searchFilters.startDate)
					params.set('startDate', searchFilters.startDate)
				if (searchFilters.endDate) params.set('endDate', searchFilters.endDate)
			}
			return powerCloudInstance.get<IWebsiteResponse>(
				`/websites?${params.toString()}`
			)
		},
	})

	const { mutate: deleteWebsite } = useMutation({
		mutationFn: (id: string) => {
			return powerCloudInstance.delete(`/wordpress/${id}`)
		},
	})

	const { mutate: startWebsite } = useMutation({
		mutationFn: (id: string) => {
			return powerCloudInstance.patch(`/wordpress/${id}/start`)
		},
	})

	const { mutate: stopWebsite } = useMutation({
		mutationFn: (id: string) => {
			return powerCloudInstance.patch(`/wordpress/${id}/stop`)
		},
	})

	const { mutate: updatePodSize } = useMutation({
		mutationFn: ({ id, phpPodSize }: { id: string; phpPodSize: number }) => {
			return powerCloudInstance.patch(`/wordpress/${id}/pod-size`, {
				phpPodSize,
			})
		},
	})

	const { mutate: changeDomain, isPending: isChangingDomain } = useMutation({
		mutationFn: ({ id, newDomain }: { id: string; newDomain: string }) => {
			return powerCloudInstance.patch(`/wordpress/${id}/domain`, {
				domain: newDomain,
			})
		},
		onSuccess: () => {
			message.success('域名變更成功')
			setIsChangeDomainModalOpen(false)
			form.resetFields()
			refetch()
		},
		onError: (error: any) => {
			message.error(
				`域名變更失敗: ${error?.response?.data?.message || error.message}`
			)
		},
	})

	const websites = data?.data?.data || []
	const total = data?.data?.total || 0

	// Batch query subscription mapping for all loaded websites
	const websiteIds = websites.map((w: IWebsite) => w.id)
	const {
		subscriptionMap,
		isFetching: isAppsFetching,
		refetch: refetchApps,
	} = useSubscriptionApps({ websiteIds })

	const handleDelete = (id: string) => {
		deleteWebsite(id)
	}

	const handleStop = (id: string) => {
		stopWebsite(id)
	}

	const handleStart = (id: string) => {
		startWebsite(id)
	}

	const handlePodSizeChange = (id: string, value: number) => {
		updatePodSize({ id, phpPodSize: value })
	}

	const handleShowChangeDomainModal = (website: IWebsite) => {
		setSelectedWebsite(website)
		setIsChangeDomainModalOpen(true)
		form.setFieldsValue({ newDomain: '' })
	}

	const handleChangeDomain = () => {
		form.validateFields().then((values) => {
			if (selectedWebsite) {
				changeDomain({
					id: selectedWebsite.id,
					newDomain: values.newDomain,
				})
			}
		})
	}

	const handleSearch = useCallback(
		(filters: WebsiteFilters) => {
			setSearchFilters(filters)
			setPagination((prev) => ({ ...prev, page: 1 }))
		},
		[]
	)

	const columns: ColumnsType<IWebsite> = [
		{
			title: '網站資訊',
			key: 'siteInfo',
			ellipsis: true,
			width: 280,
			render: (_, record) => {
				const domain = getDomain(record)
				return (
					<Space direction="vertical" size={0}>
						<div className="flex items-center gap-1">
							<Link
								href={`https://${domain}`}
								target="_blank"
								style={{ fontSize: 14 }}
							>
								<LinkOutlined /> {domain}
							</Link>
							<Text
								copyable={{ text: domain, icon: <CopyOutlined /> }}
								style={{ fontSize: 12 }}
							/>
						</div>
						<div className="flex items-center gap-1">
							<Text className="text-xs text-gray-500">{record.namespace}</Text>
							<Text
								copyable={{
									text: record.namespace,
									icon: <CopyOutlined style={{ fontSize: 10 }} />,
								}}
								style={{ fontSize: 10 }}
							/>
						</div>
					</Space>
				)
			},
		},
		{
			title: '狀態',
			dataIndex: 'status',
			key: 'status',
			width: 100,
			render: (status: string) => (
				<Tag bordered={false} color={statusColorMap[status] || 'default'}>
					{statusTextMap[status] || status}
				</Tag>
			),
		},
		{
			title: '管理員電子郵件',
			dataIndex: 'adminEmail',
			key: 'adminEmail',
			ellipsis: true,
			width: 220,
			render: (email: string) => (
				<Text copyable ellipsis>
					{email}
				</Text>
			),
		},
		{
			title: '管理員密碼',
			key: 'adminPassword',
			width: 180,
			render: (_, record) => (
				<Text copyable={{ text: record.adminPassword }}>•••••••••••</Text>
			),
		},
		{
			title: 'IP 地址',
			dataIndex: 'ipAddress',
			key: 'ipAddress',
			ellipsis: true,
			width: 150,
			render: (ipAddress: string) =>
				ipAddress ? (
					<Text copyable={{ text: ipAddress }} ellipsis>
						{ipAddress}
					</Text>
				) : (
					<Text type="secondary">-</Text>
				),
		},
		{
			title: '網站方案',
			dataIndex: 'package',
			key: 'package',
			width: 160,
			render: (pkg: IWebsite['package']) =>
				pkg ? (
					<div className="flex flex-col">
						<Text className="text-gray-600">{pkg.name}</Text>
						<Text className="text-xs text-gray-400">
							$NT {pkg.price}/年
						</Text>
					</div>
				) : (
					<Text type="secondary">-</Text>
				),
		},
		{
			title: '網站擁有者',
			dataIndex: 'user',
			key: 'user',
			width: 160,
			render: (user: IWebsite['user']) => (
				<Text className="text-blue-500">
					{user ? `${user.firstName ?? ''} ${user.lastName ?? ''}`.trim() : '-'}
				</Text>
			),
		},
		{
			title: '每日扣款',
			dataIndex: 'dailyCost',
			key: 'dailyCost',
			width: 130,
			sorter: true,
			render: (dailyCost: number) => {
				return <Text className="font-medium">${dailyCost}</Text>
			},
		},
		{
			title: '容器數量',
			dataIndex: 'phpPodSize',
			key: 'phpPodSize',
			width: 160,
			render: (phpPodSize: number, record) => {
				const isDisabled =
					record.status === 'creating' || record.status === 'stopped'
				return (
					<PodSizeEditor
						initialValue={phpPodSize ?? 1}
						domain={getDomain(record)}
						packagePrice={record.package?.price}
						onUpdate={(value) => handlePodSizeChange(record.id, value)}
						disabled={isDisabled}
					/>
				)
			},
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
			title: '對應訂閱',
			key: 'subscription',
			width: 200,
			render: (_: unknown, record: IWebsite) => {
				const subscriptionIds = subscriptionMap[record.id] || []
				return (
					<SubscriptionBinding
						websiteId={record.id}
						subscriptionIds={subscriptionIds}
						onBindingChange={() => refetchApps()}
					/>
				)
			},
		},
		{
			title: '建立時間',
			dataIndex: 'createdAt',
			key: 'createdAt',
			width: 180,
			sorter: true,
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
			width: 150,
			render: (_, record) => (
				<WebsiteActionButtons
					record={record}
					onStart={handleStart}
					onStop={handleStop}
					onDelete={handleDelete}
					onChangeDomain={handleShowChangeDomainModal}
				/>
			),
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

	return (
		<div className="space-y-4">
			<div className="flex justify-end">
				<Button
					type="primary"
					icon={<PlusOutlined />}
					onClick={() => setTab(TabKeyEnum.MANUAL_SITE_SYNC)}
				>
					新增網站
				</Button>
			</div>
			<ContentCard>
				<WebsiteListFilter onSearch={handleSearch} />
			</ContentCard>

			{!websites.length && !searchFilters ? (
				<ContentCard>
					<div style={{ padding: '60px 0', textAlign: 'center' }}>
						<Text type="secondary">尚無網站資料，請前往「手動開站」建立您的第一個網站</Text>
					</div>
				</ContentCard>
			) : (
				<ContentCard>
					<div
						style={{
							marginBottom: 16,
							display: 'flex',
							justifyContent: 'space-between',
							alignItems: 'center',
						}}
					>
						<Text type="secondary">共 {total || 0} 個網站</Text>
						<Button
							icon={<ReloadOutlined spin={isFetching} />}
							onClick={() => {
								refetch()
								refetchApps()
							}}
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
						scroll={{ x: 'max-content' }}
						pagination={{
							current: pagination.page,
							pageSize: pagination.limit,
							total,
							showSizeChanger: true,
							showQuickJumper: true,
							pageSizeOptions: ['10', '20', '50'],
							showTotal: (total, range) =>
								`顯示 ${range[0]}-${range[1]} 共 ${total} 筆記錄`,
							onChange: (page, pageSize) => {
								setPagination({ page, limit: pageSize })
							},
						}}
					/>
				</ContentCard>
			)}

			<Modal
				title="變更域名 (Domain Name)"
				open={isChangeDomainModalOpen}
				onCancel={() => {
					setIsChangeDomainModalOpen(false)
					form.resetFields()
				}}
				onOk={handleChangeDomain}
				confirmLoading={isChangingDomain}
				okText="確認變更域名"
				cancelText="取消"
				okButtonProps={{ danger: true }}
			>
				<Form form={form} layout="vertical" className="mt-8">
					<Alert
						message="提醒："
						description="請先將網域 DNS 設定中的 A 紀錄 (A Record) 指向正確的 IP，再變更網域"
						type="info"
						showIcon
						className="mb-4"
					/>
					<div className="mb-6">
						<p className="mt-0 mb-2 text-sm font-medium">當前域名</p>
						<div className="px-3 py-2 bg-gray-100 rounded-md border border-gray-300">
							<Text copyable>{selectedWebsite?.domain}</Text>
						</div>
					</div>
					<Form.Item
						label="新域名"
						name="newDomain"
						rules={[
							{ required: true, message: '請輸入新的 domain name' },
							{
								pattern:
									/^(?!http(s)?:\/\/)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$/g,
								message: (
									<>
										請輸入不含 <Tag>http(s)://</Tag> 的合格的網址
									</>
								),
							},
						]}
					>
						<Input placeholder="example.com" />
					</Form.Item>
				</Form>
			</Modal>
		</div>
	)
}

const Powercloud = () => {
	const powercloudIdentity = useAtomValue(powercloudIdentityAtom)
	const setTab = useSetAtom(setTabAtom)

	const handleRedirectToPowercloudAuth = () =>
		setTab(TabKeyEnum.POWERCLOUD_AUTH)

	if (
		powercloudIdentity.status !== EPowercloudIdentityStatusEnum.LOGGED_IN ||
		!powercloudIdentity.apiKey
	) {
		return (
			<Button
				color="primary"
				variant="link"
				onClick={handleRedirectToPowercloudAuth}
			>
				登入新架構
			</Button>
		)
	}
	return <PowercloudContent />
}

const WPCD = () => {
	const identity = useAtomValue(identityAtom)
	const setGlobalLoading = useSetAtom(globalLoadingAtom)

	const user_id = identity.data?.user_id || ''

	const { tableProps } = useTable({
		resource: 'apps',
		defaultParams: {
			user_id,
			offset: 0,
			numberposts: 10,
		},
		queryOptions: {
			enabled: !!user_id,
			staleTime: 1000 * 60 * 60 * 24,
			gcTime: 1000 * 60 * 60 * 24,
		},
	})

	// 取得所有網站的 customer 資料

	const all_customer_ids =
		tableProps?.dataSource
			?.map((site) => site.customer_id)
			.filter((value, i, self) => self.indexOf(value) === i) || [] // remove duplicates

	const customerResult = useCustomers({ user_ids: all_customer_ids })

	useEffect(() => {
		if (!tableProps?.loading) {
			setGlobalLoading({
				isLoading: false,
				label: '',
			})
		}
	}, [tableProps?.loading])

	return (
		<SiteListTable
			tableProps={tableProps}
			customerResult={customerResult}
			isAdmin
		/>
	)
}

const siteTypeItems: TabsProps['items'] = [
	{
		key: 'powercloud',
		icon: <CloudOutlined />,
		label: '新架構',
		children: <Powercloud />,
		forceRender: false,
	},
	{
		key: 'wpcd',
		icon: <GlobalOutlined />,
		label: '舊架構',
		children: <WPCD />,
		forceRender: false,
	},
]

const index = () => {
	return <Tabs items={siteTypeItems} />
}

export default index
