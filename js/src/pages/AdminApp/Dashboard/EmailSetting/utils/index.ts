/* eslint-disable no-shadow */
/* eslint-disable no-unused-vars */
import { DataType } from '@/pages/AdminApp/Dashboard/EmailSetting/types'
import { TBase } from '@/types'
import { MessageInstance } from 'antd/es/message/interface'
import { nanoid } from 'nanoid'

export const getEmailTemplate = () =>
	({
		key: nanoid(),
		enabled: false,
		subject: '這裡填你的信件主旨 ##FIRST_NAME##',
		body: `
		<p>嗨 ##FIRST_NAME##</p>
	<p>你的網站開好囉，<a href="https://docs.wpsite.pro/wp" target="_blank">點此可以打開網站的使用說明書</a></p>
	<p><br></p>
	<p>另外如果要將網站換成正式的網域，請<a href="https://docs.wpsite.pro/wp/wordpress-kuai-su-ru-men/jiang-zan-shi-wang-yu-huan-cheng-zheng-shi-wang-yu-rang-wang-zhan-zheng-shi-shang-xian" target="_blank">參考這篇教學</a></p>
	<p><br></p>
	<p>有網站的問題都可以或是私訊<a href="https://wpsite.pro" target="_blank">架站小幫手網站</a> 的右下角對話框</p>
	<p>&nbsp;</p>
	<p>--- 以下是你的網站資訊 ---</p>
	<p><br></p
	<p>網站暫時網址：##FRONTURL##</p>
	<p>之後可換成你自己的網址</p>
	<p><br></p>
	<p>網站後台：##ADMINURL##</p>
	<p><br></p>
	<p>帳號：##SITEUSERNAME##</p>
	<p><br></p>
	<p>密碼：##SITEPASSWORD##</p>
	<p><br></p>
	<p><strong>進去後請記得改成自己的密碼喔</strong></p>
	<p><br></p>
	<br>
	<p>網站主機ip：##IPV4##</p>
	<p>&nbsp;</p>
	<p>這封信很重要，不要刪掉，這樣之後才找得到喔</p><p>&nbsp;</p><p><br></p>
		`,
		action_name: 'subscription_failed',
		days: 0,
		operator: 'after',
	}) as DataType

export const baseTokens: TBase[] = [
	{
		label: '姓',
		value: '##FIRST_NAME##',
	},
	{
		label: '名',
		value: '##LAST_NAME##',
	},
	{
		label: '暱稱',
		value: '##NICE_NAME##',
	},
	{
		label: 'Email',
		value: '##EMAIL##',
	},
]

export const siteSyncTokens: TBase[] = [
	...baseTokens,
	{
		// issue #23：後端已把 URL 帶進開站信 payload，UI 也要露出來，否則合約不一致
		label: '網站 URL',
		value: '##URL##',
	},
	{
		label: 'WordPress帳戶頁',
		value: '##WORDPRESSAPPWCSITESACCOUNTPAGE##',
	},
	{
		label: 'IPV4',
		value: '##IPV4##',
	},
	{
		label: '網域',
		value: '##DOMAIN##',
	},
	{
		label: '前台網址',
		value: '##FRONTURL##',
	},
	{
		label: '後台網址',
		value: '##ADMINURL##',
	},
	{
		label: '網站帳號',
		value: '##SITEUSERNAME##',
	},
	{
		label: '網站密碼',
		value: '##SITEPASSWORD##',
	},
]

export const orderTokens: TBase[] = [
	...baseTokens,
	{
		label: '訂單編號',
		value: '##ORDER_ID##',
	},
	{
		label: '訂單商品',
		value: '##ORDER_ITEMS##',
	},
	{
		label: '付款網址',
		value: '##CHECKOUT_PAYMENT_URL##',
	},
	{
		label: '訂單網址',
		value: '##VIEW_ORDER_URL##',
	},
	{
		label: '訂單狀態',
		value: '##ORDER_STATUS##',
	},
	{
		label: '訂單日期',
		value: '##ORDER_DATE##',
	},
	{
		label: '網站 URL',
		value: '##URL##',
	},
]

export const handleCopy = (token: TBase, messageApi: MessageInstance) => () => {
	if (!navigator?.clipboard) {
		messageApi.open({
			type: 'error',
			content: `剪貼簿不可用，請手動複製 ${token.value}`,
		})
		return
	}

	navigator?.clipboard
		?.writeText(token.value)
		.then(() => {
			messageApi.open({
				type: 'success',
				content: `已複製 ${token.label} 至剪貼簿`,
			})
		})
		.catch((err) => {
			messageApi.open({
				type: 'error',
				content: `複製 ${token.label} 時出錯了，請再試一次`,
			})
			console.error('Error in copying text: ', err)
		})
}
