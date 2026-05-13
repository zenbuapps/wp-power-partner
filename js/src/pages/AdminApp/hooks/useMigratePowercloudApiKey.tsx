import { useEffect, useRef } from 'react'
import { useSetAtom } from 'jotai'
import {
	EPowercloudIdentityStatusEnum,
	powercloudIdentityAtom,
} from '@/pages/AdminApp/Atom/powercloud.atom'
import { axios } from '@/api'
import { kebab, powercloud_api_key } from '@/utils'

const LEGACY_STORAGE_KEY = 'power-partner-powercloud-identity'

/**
 * 將舊版存在 localStorage 的 PowerCloud API key 遷移到 server transient。
 * 舊版使用 atomWithStorage，每個瀏覽器各自儲存；新版改為 server-side global key。
 * 升級後若 server 尚無 key 但 localStorage 有，則 POST 上去並清除舊資料。
 */
export const useMigratePowercloudApiKey = () => {
	const setIdentity = useSetAtom(powercloudIdentityAtom)
	const migratedRef = useRef(false)

	useEffect(() => {
		if (migratedRef.current) return
		migratedRef.current = true

		const raw = window.localStorage.getItem(LEGACY_STORAGE_KEY)
		if (!raw) return

		try {
			const parsed = JSON.parse(raw) as { apiKey?: string }
			const legacyKey = parsed?.apiKey || ''

			if (legacyKey && !powercloud_api_key) {
				axios
					.post(`/${kebab}/powercloud-api-key`, { api_key: legacyKey })
					.then(() => {
						setIdentity({
							status: EPowercloudIdentityStatusEnum.LOGGED_IN,
							message: '',
							apiKey: legacyKey,
						})
						window.localStorage.removeItem(LEGACY_STORAGE_KEY)
					})
					.catch((error) => {
						console.error('Migrate PowerCloud API key failed:', error)
					})
				return
			}

			window.localStorage.removeItem(LEGACY_STORAGE_KEY)
		} catch (error) {
			console.error('Parse legacy PowerCloud identity failed:', error)
			window.localStorage.removeItem(LEGACY_STORAGE_KEY)
		}
	}, [setIdentity])
}
