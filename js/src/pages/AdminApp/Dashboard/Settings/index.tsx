import React from 'react'
import { InputNumber, Form, Switch, Typography } from 'antd'
import { disable_site_after_n_days, allow_delete_site } from '@/utils'
import ContentCard from '@/components/ContentCard'

const NAME = 'power_partner_disable_site_after_n_days'
const DEFAULT = disable_site_after_n_days
const ALLOW_DELETE_SITE_NAME = 'power_partner_allow_delete_site'
const { Item } = Form
const { Text } = Typography

const index = () => {
	return (
		<div className="flex flex-col gap-4">
			<ContentCard>
				<p>
					當訂閱轉為<span className="bg-gray-200 px-1 mx-1">非啟用</span>
					狀態後，幾天後會<span className="bg-gray-200 px-1 mx-1">禁用</span>
					關聯的網站
				</p>
				<Item
					name={NAME}
					className="m-0"
					initialValue={DEFAULT}
					rules={[
						{
							required: true,
							message: '請輸入天數',
						},
					]}
				>
					<InputNumber addonAfter="天" min={0} max={100} />
				</Item>
			</ContentCard>

			<ContentCard>
				<p>
					開啟後，
					<span className="bg-gray-200 px-1 mx-1">所有站台</span>
					列表的操作選單才會出現
					<span className="bg-gray-200 px-1 mx-1">刪除網站</span>
					，站台刪除後<span className="bg-gray-200 px-1 mx-1">無法復原</span>
					，請謹慎開啟
				</p>
				<Item
					name={ALLOW_DELETE_SITE_NAME}
					className="m-0"
					valuePropName="checked"
					initialValue={allow_delete_site}
				>
					<Switch checkedChildren="允許刪除" unCheckedChildren="禁止刪除" />
				</Item>
			</ContentCard>
		</div>
	)
}

export default index
