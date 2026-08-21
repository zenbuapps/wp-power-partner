import { DownloadOutlined } from '@ant-design/icons'
import { Button, Table, TableProps, Tag, Typography } from 'antd'
import { useAtomValue } from 'jotai'

import { useDownloadDetail } from './hooks'
import { DataType, TLogParams } from './types'

import ContentCard from '@/components/ContentCard'
import { useTable } from '@/hooks'
import { identityAtom } from '@/pages/AdminApp/Atom/atom'

const { Paragraph } = Typography

const LogTypeTag: React.FC<{ record: DataType }> = ({ record }) => {
	const type = record?.type || ''
	switch (type) {
		case 'cron':
			return <Tag color="purple">每日扣點</Tag>
		case 'cron_powercloud':
			return <Tag color="geekblue">每日扣點（新架構）</Tag>
		case 'modify':
			return <Tag color="magenta">管理員直接修改</Tag>
		case 'purchase':
			return <Tag color="cyan">儲值</Tag>
		default:
			return <></>
	}
}

const index = () => {
	const identity = useAtomValue(identityAtom)
	const user_id = identity.data?.user_id || ''
	const { download, isDownloading } = useDownloadDetail()
	const { tableProps } = useTable<TLogParams, DataType>({
		resource: 'logs',
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

	// ⚠️ 舊版伺服端不回傳 has_detail，整欄每一格都會是空的，
	// 只留一個空表頭會讓使用者以為功能壞了 —— 伺服端沒回傳這個欄位時，
	// 連欄位本身都不要加。
	//
	// ⚠️ 判斷的是「伺服端有沒有回傳 has_detail 這個欄位」，不是「有沒有任一筆為 true」：
	// logs 走伺服端分頁（預設 numberposts=10），若改看 true，
	// 某一頁剛好全是儲值／管理員修改這類沒有明細的紀錄時整欄就會消失、翻到下一頁又出現，
	// 表格欄位數會在翻頁之間跳動。
	const supportsDetail = (tableProps.dataSource || []).some(
		(record) => !!record && 'has_detail' in record
	)

	const columns: TableProps<DataType>['columns'] = [
		{
			title: '日期',
			dataIndex: 'date',
			width: 160,
		},
		{
			title: '分類',
			dataIndex: 'type',
			width: 144,
			render: (_, record) => <LogTypeTag record={record} />,
		},
		{
			title: 'Power Money 變化',
			dataIndex: 'point_changed',
			width: 144,
			align: 'right',
		},
		{
			title: '餘額',
			dataIndex: 'new_balance',
			width: 208,
			align: 'right',
		},
		{
			title: '說明',
			dataIndex: 'title',
			render: (value: string) => (
				<Paragraph
					copyable
					ellipsis={{
						rows: 2,
						expandable: true,
						symbol: '更多',
					}}
					className="whitespace-break-spaces !m-0"
				>
					{value}
				</Paragraph>
			),
		},
	]

	if (supportsDetail) {
		columns.push({
			title: '逐站明細',
			dataIndex: 'has_detail',
			width: 128,
			align: 'center',

			// ⚠️ 同一頁內仍可能混著沒有明細的紀錄（例如儲值、管理員直接修改），
			// 逐格還是要判斷 has_detail === true；欄位存在只代表伺服端會回傳這個旗標。
			render: (_, record) =>
				record?.has_detail === true ? (
					<Button
						type="link"
						size="small"
						icon={<DownloadOutlined />}
						loading={isDownloading(record.id)}
						onClick={() => download(record)}
					>
						下載 CSV
					</Button>
				) : null,
		})
	}

	return (
		<ContentCard>
			<Table rowKey="id" {...tableProps} columns={columns} />
		</ContentCard>
	)
}

export default index
