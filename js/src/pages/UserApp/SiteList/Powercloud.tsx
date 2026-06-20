import {
	EditOutlined,
	GlobalOutlined,
	LinkOutlined,
	LoadingOutlined,
	PlayCircleOutlined,
	ReloadOutlined,
	StopOutlined,
} from '@ant-design/icons'
import { useQuery, useMutation } from '@tanstack/react-query'
import {
	Alert,
	Button,
	Empty,
	Form,
	Input,
	Modal,
	notification,
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
import { useEffect, useMemo, useRef, useState } from 'react'

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

/** 變更域名 mutation variables（oldDomain 用於產生通知明細文案） */
type TChangeDomainVariables = {
	id: string
	oldDomain: string
	newDomain: string
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

	// Shadow DOM 容器與通知 context（App2 前臺包在 react-shadow Shadow Root 內，
	// antd 彈層需綁定 containerRef 才不會逸出而樣式失效）
	const containerRef = useRef<HTMLDivElement>(null)
	const [api, contextHolder] = notification.useNotification({
		placement: 'bottomRight',
		duration: 10,
		getContainer: () => containerRef.current as HTMLElement,
	})

	// 變更域名 Modal 狀態與表單
	const [isChangeDomainModalOpen, setIsChangeDomainModalOpen] = useState(false)
	const [selectedWebsite, setSelectedWebsite] = useState<IWebsite | null>(null)
	const [form] = Form.useForm()

	/**
	 * 變更域名 mutation（詳細進度通知）
	 * 使用相同 notification key（`cd-${id}`）讓進行中通知被成功/失敗覆蓋
	 */
	const { mutate: changeDomain, isPending: isChangingDomain } = useMutation({
		mutationFn: ({ id, newDomain }: TChangeDomainVariables) => {
			return powerCloudInstance.patch(`/wordpress/${id}/domain`, {
				domain: newDomain,
			})
		},
		onMutate: ({ id, oldDomain, newDomain }: TChangeDomainVariables) => {
			setIsChangeDomainModalOpen(false)
			api.open({
				key: `cd-${id}`,
				duration: 0,
				icon: <LoadingOutlined className="text-primary" />,
				message: '域名變更中...',
				description: `正在將 ${oldDomain} 變更為 ${newDomain}，網域變更有可能需要等待 2~3 分鐘左右的時間，請先不要關閉視窗`,
			})
		},
		onSuccess: (
			_data,
			{ id, oldDomain, newDomain }: TChangeDomainVariables
		) => {
			api.success({
				key: `cd-${id}`,
				message: '域名變更成功',
				description: `${oldDomain} 已成功變更為 ${newDomain}`,
			})
			refetch()
			form.resetFields()
		},
		onError: (_error, { id, oldDomain, newDomain }: TChangeDomainVariables) => {
			api.error({
				key: `cd-${id}`,
				message: '域名變更失敗',
				description: `${oldDomain} 變更為 ${newDomain} 失敗`,
			})
		},
	})

	/**
	 * 開啟變更域名 Modal
	 * @param website 目標站台
	 */
	const handleShowChangeDomainModal = (website: IWebsite) => {
		setSelectedWebsite(website)
		setIsChangeDomainModalOpen(true)
		form.setFieldsValue({ newDomain: '' })
	}

	/** 送出變更域名（前置表單驗證後觸發 mutation） */
	const handleChangeDomain = () => {
		form.validateFields().then((values) => {
			if (selectedWebsite) {
				changeDomain({
					id: selectedWebsite.id,
					oldDomain: getDomain(selectedWebsite),
					newDomain: values.newDomain,
				})
			}
		})
	}

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
						<Tooltip
							title="前往後台"
							getPopupContainer={() => containerRef.current as HTMLElement}
						>
							<Button
								type="link"
								size="small"
								icon={<GlobalOutlined />}
								href={`https://${getDomain(record)}/wp-admin`}
								target="_blank"
							/>
						</Tooltip>
						<Tooltip
							title="變更域名"
							getPopupContainer={() => containerRef.current as HTMLElement}
						>
							<Button
								type="link"
								size="small"
								icon={<EditOutlined />}
								disabled={record.status !== 'running'}
								onClick={() => handleShowChangeDomainModal(record)}
							/>
						</Tooltip>
						{record.status === 'stopped' && (
							<Popconfirm
								title="確認啟動站台"
								description={`確定要啟動站台 ${getDomain(record)} 嗎？`}
								onConfirm={() => startWebsite(record.id)}
								okText="確認啟動"
								cancelText="取消"
								getPopupContainer={() => containerRef.current as HTMLElement}
							>
								<Tooltip
									title="啟動站台"
									getPopupContainer={() => containerRef.current as HTMLElement}
								>
									<Button
										type="link"
										size="small"
										icon={<PlayCircleOutlined />}
									/>
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
								getPopupContainer={() => containerRef.current as HTMLElement}
							>
								<Tooltip
									title="停止站台"
									getPopupContainer={() => containerRef.current as HTMLElement}
								>
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
		<div ref={containerRef}>
			{contextHolder}
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
				getContainer={() => containerRef.current as HTMLElement}
			>
				<Form form={form} layout="vertical" className="mt-8">
					<Alert
						message="提醒："
						description={`請先將網域 DNS 設定中的 A 紀錄 (A Record) 指向 ${selectedWebsite?.ipAddress}，再變更網域`}
						type="info"
						showIcon
						className="mb-4"
					/>
					<div className="mb-6">
						<p className="mt-0 mb-2 text-sm font-medium">當前域名</p>
						<div className="px-3 py-2 bg-gray-100 rounded-md border border-gray-300">
							<Text copyable>
								{selectedWebsite ? getDomain(selectedWebsite) : ''}
							</Text>
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

export default Powercloud
