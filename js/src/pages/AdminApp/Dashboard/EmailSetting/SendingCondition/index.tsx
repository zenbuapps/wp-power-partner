import React, { useEffect } from 'react'
import { EmailComponentProps } from '@/pages/AdminApp/Dashboard/EmailSetting/types'
import { Select, Form, InputNumber, Space, Tooltip } from 'antd'
import { InfoCircleFilled } from '@ant-design/icons'
import { focusEmailIndexAtom } from '@/pages/AdminApp/Dashboard/EmailSetting/atom'
import { useSetAtom } from 'jotai'

const { Item } = Form

// @param string $date_type 'date_created', 'trial_end', 'next_payment', 'renewal_order_created', 'end' or 'end_of_prepaid_term'

const actions = [
	{
		label: '下單開站後',
		value: 'site_sync',
		helper: '下單開站後馬上發送',
	},
	{
		label: '客戶續訂失敗後',
		value: 'subscription_failed',
		helper: '續訂失敗後 N 天發送',
	},
	{
		label: '客戶續訂成功後',
		value: 'subscription_success',
		helper: '續訂成功後 N 天發送',
	},
	{
		label: '下次付款(含成功&失敗)',
		value: 'next_payment',
		helper: '續訂不論成功或失敗 前/後 N 天發送',
	},
	{
		label: '試用期結束',
		value: 'trial_end',
		helper: '試用期結束 前/後 N 天發送',
	},
	{
		label: '新的續訂訂單創建',
		value: 'renewal_order_created',
		helper: '上次續訂訂單日期後 N 天發送',
	},
	{
		label: '訂閱結束',
		value: 'end',
		helper: '訂閱結束後 N 天發送',
	},
	{
		label: '客戶手動取消訂閱後',
		value: 'customer_cancelled',
		helper:
			'客戶於「我的帳號」自行取消訂閱後立即發送給經銷商（網站管理員信箱），非通知終端客戶；後台取消或扣款失敗不觸發',
	},
]

const actionNameOptions = actions.map(({ label, value }) => ({
	label,
	value,
}))

const SendingCondition = ({
	record,
	index,
	containerRef,
}: EmailComponentProps) => {
	const form = Form.useFormInstance()
	const setFocusEmailIndex = useSetAtom(focusEmailIndexAtom)
	const actionNameName = ['emails', index, 'action_name']
	const daysName = ['emails', index, 'days']
	const operatorName = ['emails', index, 'operator']

	const watchActionName = Form.useWatch(actionNameName, form)

	const operatorOptions = [
		{ label: '天後發出', value: 'after' },
		{
			label: '天前發出',
			value: 'before',
			disabled: [
				'site_sync',
				'subscription_failed',
				'subscription_success',
				'date_created',
				'renewal_order_created',
				'customer_cancelled',
			].includes(watchActionName),
		},
	]

	useEffect(() => {
		if (['site_sync', 'customer_cancelled'].includes(watchActionName)) {
			form.setFieldValue(daysName, 0)
			form.setFieldValue(operatorName, 'after')
		}
		setFocusEmailIndex({
			index,
			actionName: watchActionName,
		})
	}, [watchActionName])

	return (
		<div className="flex">
			<Tooltip
				title={
					actions.find((action) => action.value === watchActionName)?.helper
				}
				getPopupContainer={() => containerRef?.current || document.body}
			>
				<InfoCircleFilled className="text-primary mr-2" />
			</Tooltip>
			<Space.Compact block>
				<Item
					name={actionNameName}
					initialValue={record?.action_name || actionNameOptions?.[0]?.value}
					className="mb-0"
					shouldUpdate
				>
					<Select
						className="!w-[200px]"
						options={actionNameOptions}
						getPopupContainer={() => containerRef?.current || document.body}
					/>
				</Item>
				<Item
					name={daysName}
					initialValue={record?.days || 0}
					className="mb-0"
					shouldUpdate
				>
					<InputNumber
						className="w-16"
						min={0}
						disabled={['site_sync', 'customer_cancelled'].includes(
							watchActionName
						)}
					/>
				</Item>
				<Item
					name={operatorName}
					initialValue={record?.operator || operatorOptions?.[0]?.value}
					className="mb-0"
					shouldUpdate
				>
					<Select
						className="w-32"
						options={operatorOptions}
						getPopupContainer={() => containerRef?.current || document.body}
					/>
				</Item>
			</Space.Compact>
		</div>
	)
}

export default SendingCondition
