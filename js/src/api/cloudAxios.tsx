/* eslint-disable quote-props */
import { notification } from 'antd'
import axios, { AxiosInstance } from 'axios'

import { applyCurrentNonce, retryWithFreshNonce } from './nonce'

import { apiTimeout, cloudProxyUrl } from '@/utils'

/**
 * 雲端（cloud.luke.cafe power-partner-server）API instance
 *
 * 打的是本站 PHP proxy（/wp-json/power-partner/cloud/<path>），以 X-WP-Nonce
 * 走 WordPress cookie 認證；雲端認證由 PHP 加上後轉送，瀏覽器不帶 Authorization。
 * proxy 會把上游的 status 與 JSON body 原樣回傳，所以 res.data 的形狀與直連時相同。
 * nonce 由 request interceptor 每次帶上，過期時自動換新重送一次（見 ./nonce）。
 */
const instance: AxiosInstance = axios.create({
	baseURL: cloudProxyUrl,
	timeout: parseInt(apiTimeout, 10),
	headers: {
		'Content-Type': 'application/json',
	},
})

instance.interceptors.request.use(applyCurrentNonce)

instance.interceptors.response.use(
	function (response) {
		// Any status code that lie within the range of 2xx cause this function to trigger

		// const type = response?.data?.type
		// const method = response?.config?.method || ''
		// const statusText = response?.statusText
		// const typeText = getTypeText(type, method, statusText)
		// if (method !== 'get') {
		//   console.log(`${typeText} success`)
		// }

		return response
	},
	async function (error) {
		// nonce 過期（分頁開太久）時換新後重送一次；
		// 重送若失敗，由重送那一輪跳通知並 reject，await 會把錯誤往上拋，這裡不重複通知
		const retriedResponse = await retryWithFreshNonce(instance, error)

		if (retriedResponse) {
			return retriedResponse
		}

		const message =
			error?.response?.data?.message || 'OOPS! 發生錯誤 請稍後再試'

		notification.error({
			message,
		})

		return Promise.reject(error)
	}
)

export default instance
