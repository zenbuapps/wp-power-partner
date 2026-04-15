import {
	MoneyCollectOutlined,
	ClusterOutlined,
	InfoCircleOutlined,
	MailOutlined,
	CodeOutlined,
	BarcodeOutlined,
	SettingOutlined,
	CloudOutlined,
} from '@ant-design/icons'
import { Tabs, TabsProps, Form, Button } from 'antd'
import { HashRouter, Routes, Route } from 'react-router-dom'
import AccountIcon from './AccountIcon'
import SiteList from './SiteList'
import LogList from './LogList'
import Description from './Description'
import EmailSetting from './EmailSetting'
import ManualSiteSync from './ManualSiteSync'
import Settings from './Settings'
import LicenseCodes from './LicenseCodes'
import PowercloudAuth from './PowercloudAuth'
import WebsiteEditor from './SiteList/WebsiteEditor'
import useSave, {
	TFormValues,
} from '@/pages/AdminApp/Dashboard/EmailSetting/hooks/useSave'

import { windowWidth } from '@/utils'
import { TabKeyEnum, setTabAtom } from '../Atom/tab.atom'
import { useAtomValue, useSetAtom } from 'jotai'
import { tabAtom } from '../Atom/tab.atom'

const items: TabsProps['items'] = [
	{
		key: TabKeyEnum.SITE_LIST,
		icon: <ClusterOutlined />,
		label: '所有站台',
		children: <SiteList />,
		forceRender: false,
	},
	{
		key: TabKeyEnum.LOG_LIST,
		icon: <MoneyCollectOutlined />,
		label: '點數 Log',
		children: <LogList />,
		forceRender: false,
	},
	{
		key: TabKeyEnum.EMAIL,
		icon: <MailOutlined />,
		label: 'Email 設定',
		children: <EmailSetting />,
		forceRender: true,
	},
	{
		key: TabKeyEnum.MANUAL_SITE_SYNC,
		icon: <CodeOutlined />,
		label: '手動開站',
		children: <ManualSiteSync />,
		forceRender: true,
	},
	{
		key: TabKeyEnum.SETTINGS,
		icon: <SettingOutlined />,
		label: '設定',
		children: <Settings />,
		forceRender: true,
	},
	{
		key: TabKeyEnum.LICENSE_CODES,
		icon: <BarcodeOutlined />,
		label: '授權碼管理',
		children: <LicenseCodes isAdmin />,
		forceRender: false,
	},
	{
		key: TabKeyEnum.DESCRIPTION,
		icon: <InfoCircleOutlined />,
		label: '其他資訊',
		children: <Description />,
		forceRender: false,
	},
	{
		key: TabKeyEnum.POWERCLOUD_AUTH,
		icon: <CloudOutlined />,
		label: '新架構權限',
		children: <PowercloudAuth />,
		forceRender: false,
	},
]

const TabsDashboard = () => {
	const [form] = Form.useForm()
	const { mutation, contextHolder } = useSave(form)
	const { mutate: saveSettings, isPending } = mutation
	const activeKey = useAtomValue(tabAtom)
	const setActiveKey = useSetAtom(setTabAtom)
	const handleSave = () => {
		form
			.validateFields()
			.then((settings: TFormValues) => {
				saveSettings(settings)
			})
			.catch((error) => {
				console.log(error)
			})
	}
	return (
		<Form form={form} layout="vertical">
			{contextHolder}
			<Tabs
				activeKey={activeKey}
				onChange={(key) => setActiveKey(key as TabKeyEnum)}
				className={`${windowWidth < 1200 ? 'mt-16' : ''}`}
				type="card"
				tabBarExtraContent={<AccountIcon />}
				items={items}
			/>
			{[TabKeyEnum.EMAIL, TabKeyEnum.SETTINGS].includes(activeKey) && (
				<Button
					type="primary"
					className="mt-4"
					onClick={handleSave}
					loading={isPending}
				>
					儲存
				</Button>
			)}
		</Form>
	)
}

const index = () => {
	return (
		<HashRouter>
			<Routes>
				<Route path="/" element={<TabsDashboard />} />
				<Route
					path="/websites/edit/:id"
					element={<WebsiteEditor />}
				/>
			</Routes>
		</HashRouter>
	)
}

export default index
