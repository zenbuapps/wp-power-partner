import { Button, Col, Form, Input, Row, Select, Tag, Typography } from 'antd'
import { useEffect, useState } from 'react'

import LabelSelector from '../components/LabelSelector'
import UserSelector from '../components/UserSelector'
import WebsitePackageSelector from '../components/WebsitePackageSelector'
import type { IWebsite } from '../types'
import useUpdateWebsite from './useUpdateWebsite'

const { Text } = Typography

const statusColorMap: Record<string, string> = {
	creating: 'processing',
	running: 'success',
	stopped: 'warning',
	updating: 'processing',
	deleting: 'error',
}

const statusTextMap: Record<string, string> = {
	creating: '建置中',
	running: '運行中',
	stopped: '已停止',
	updating: '處理中',
	deleting: '刪除中',
}

const statusOptions = [
	{ label: '運行中', value: 'running' },
	{ label: '已停止', value: 'stopped' },
	{ label: '建置中', value: 'creating' },
	{ label: '處理中', value: 'updating' },
]

const phpVersionOptions = [
	{ label: 'PHP 7.4', value: 'php7.4' },
	{ label: 'PHP 8.0', value: 'php8.0' },
	{ label: 'PHP 8.1', value: 'php8.1' },
	{ label: 'PHP 8.2', value: 'php8.2' },
	{ label: 'PHP 8.3', value: 'php8.3' },
	{ label: 'PHP 8.4', value: 'php8.4' },
	{ label: 'PHP 8.5', value: 'php8.5' },
]

const getDomain = (website: IWebsite): string => {
	return (
		website.primaryDomain ||
		website.domain ||
		website.subDomain ||
		website.wildcardDomain ||
		''
	)
}

interface WebsiteEditorFormProps {
	websiteData: IWebsite
}

const WebsiteEditorForm = ({ websiteData }: WebsiteEditorFormProps) => {
	const [form] = Form.useForm()
	const { updateWebsite, updatePhpVersion } = useUpdateWebsite()
	const [submitting, setSubmitting] = useState(false)

	const domain = getDomain(websiteData)

	useEffect(() => {
		form.setFieldsValue({
			packageId: websiteData.packageId ?? websiteData.package?.id ?? '',
			userId: websiteData.userId ?? websiteData.user?.id ?? '',
			status: websiteData.status,
			phpVersion: websiteData.phpVersion ?? undefined,
			labelIds: websiteData.labels?.map((l) => l.id) ?? [],
			memo: websiteData.memo ?? '',
		})
	}, [websiteData, form])

	const handleFinish = async (values: {
		packageId: string
		userId: string
		status: string
		phpVersion?: string
		labelIds?: string[]
		memo?: string
	}) => {
		setSubmitting(true)

		try {
			const isPhpVersionChanged =
				!!values.phpVersion &&
				values.phpVersion !== websiteData.phpVersion

			if (isPhpVersionChanged) {
				await updatePhpVersion.mutateAsync({
					id: websiteData.id,
					values: { phpVersion: values.phpVersion! },
				})
			}

			const requestValues = {
				packageId: values.packageId,
				userId: values.userId,
				labelIds: values.labelIds ?? [],
				memo: values.memo || null,
				...(!isPhpVersionChanged && { status: values.status }),
			}

			await updateWebsite.mutateAsync({
				id: websiteData.id,
				values: requestValues,
			})
		} finally {
			setSubmitting(false)
		}
	}

	return (
		<div className="rounded-xl border border-gray-300 border-solid p-6">
			{/* 唯讀摘要卡片 */}
			<div className="grid grid-cols-1 gap-4 rounded-lg bg-gray-50 p-4 xl:grid-cols-4 mb-6">
				<div className="space-y-1">
					<span className="block text-sm text-gray-500">網站域名</span>
					<Text copyable ellipsis>
						{domain}
					</Text>
				</div>
				<div className="space-y-1">
					<span className="block text-sm text-gray-500">
						WordPress 管理員 Email
					</span>
					<Text copyable>{websiteData.adminEmail ?? ''}</Text>
				</div>
				<div className="space-y-1">
					<span className="block text-sm text-gray-500">
						WordPress 密碼
					</span>
					<Text copyable={{ text: websiteData.adminPassword ?? '' }}>
						•••••••••
					</Text>
				</div>
				<div className="space-y-1">
					<span className="block text-sm text-gray-500">網站狀態</span>
					<Tag
						bordered={false}
						color={statusColorMap[websiteData.status] || 'default'}
					>
						{statusTextMap[websiteData.status] || websiteData.status}
					</Tag>
				</div>
				<div className="space-y-1">
					<span className="block text-sm text-gray-500">每日扣款</span>
					<span className="block font-semibold text-green-600">
						${Number(websiteData.dailyCost ?? 0).toFixed(2)}
					</span>
				</div>
				<div className="space-y-1">
					<span className="block text-sm text-gray-500">IP 地址</span>
					<Text copyable ellipsis>
						{websiteData.ipAddress || '尚未分配'}
					</Text>
				</div>
				<div className="space-y-1">
					<span className="block text-sm text-gray-500">容器數量</span>
					<span>{websiteData.phpPodSize}</span>
				</div>
				<div className="space-y-1">
					<span className="block text-sm text-gray-500">PHP 版本</span>
					<span>
						{websiteData.phpVersion ?? 'php8.1（預設）'}
					</span>
				</div>
			</div>

			{/* 可編輯表單 */}
			<Form
				form={form}
				layout="vertical"
				onFinish={handleFinish}
			>
				<Row gutter={[16, 0]}>
					<Col xs={24} xl={12}>
						<Form.Item
							label="網站方案"
							name="packageId"
							rules={[{ required: true, message: '請選擇網站方案' }]}
						>
							<WebsitePackageSelector placeholder="請選擇網站方案" />
						</Form.Item>
					</Col>
					<Col xs={24} xl={12}>
						<Form.Item
							label="所屬用戶"
							name="userId"
							rules={[{ required: true, message: '請選擇用戶' }]}
						>
							<UserSelector placeholder="請選擇用戶" />
						</Form.Item>
					</Col>
					<Col xs={24} xl={12}>
						<Form.Item
							label="狀態"
							name="status"
							rules={[{ required: true, message: '請選擇狀態' }]}
						>
							<Select options={statusOptions} />
						</Form.Item>
					</Col>
					<Col xs={24} xl={12}>
						<Form.Item label="PHP 版本" name="phpVersion">
							<Select
								options={phpVersionOptions}
								placeholder="選擇 PHP 版本（預設 php8.1）"
								allowClear
							/>
						</Form.Item>
					</Col>
					<Col xs={24}>
						<Form.Item label="標籤" name="labelIds">
							<LabelSelector placeholder="選擇標籤" />
						</Form.Item>
					</Col>
					<Col xs={24}>
						<Form.Item
							label="備註"
							name="memo"
							rules={[
								{ max: 500, message: '備註最多 500 個字元' },
							]}
						>
							<Input.TextArea
								rows={3}
								placeholder="請輸入備註"
								maxLength={500}
								showCount
							/>
						</Form.Item>
					</Col>
				</Row>

				<div className="pt-4 flex justify-end">
					<Button
						type="primary"
						htmlType="submit"
						loading={submitting}
					>
						{submitting ? '更新中...' : '更新'}
					</Button>
				</div>
			</Form>
		</div>
	)
}

export default WebsiteEditorForm
