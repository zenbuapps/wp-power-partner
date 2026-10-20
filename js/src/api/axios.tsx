import axios, { AxiosInstance } from 'axios'

import { applyCurrentNonce, retryWithFreshNonce } from './nonce'

import { apiUrl, apiTimeout } from '@/utils'

const instance: AxiosInstance = axios.create({
	baseURL: apiUrl,
	timeout: parseInt(apiTimeout, 10),
	headers: {
		'Content-Type': 'application/json',
	},
})

// nonce 每次請求時才讀（換新後與 cloudAxios 共用新值），過期時自動換新重送一次（見 ./nonce）
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
		// Any status codes that falls outside the range of 2xx cause this function to trigger

		// 重送若失敗，由重送那一輪處理，await 會把錯誤往上拋
		const retriedResponse = await retryWithFreshNonce(instance, error)

		if (retriedResponse) {
			return retriedResponse
		}

		console.log('error', error)

		return Promise.reject(error)
	}
)

export default instance
