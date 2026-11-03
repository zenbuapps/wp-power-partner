import { CloudOutlined, GlobalOutlined } from '@ant-design/icons'
import { Tabs, TabsProps } from 'antd'

import Powercloud from './Powercloud'
import WPCD from './WPCD'

import { powercloud_connected } from '@/utils'

const index = () => {
	if (!powercloud_connected) {
		return <WPCD />
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

	return <Tabs items={siteTypeItems} />
}

export default index
