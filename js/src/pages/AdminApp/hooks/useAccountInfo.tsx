import { useAtomValue } from 'jotai'
import { useMemo } from 'react'

import { accountInfoAtom } from '@/pages/AdminApp/Atom/atom'
import { TAccountInfo } from '@/pages/AdminApp/types'
import { decrypt, LOCALSTORAGE_ACCOUNT_KEY } from '@/utils'

/**
 * 取得目前已連結的經銷商帳密
 *
 * 與 useGetUserIdentity 共用同一條取得鏈路，不新增任何儲存：
 * 1. 優先讀 accountInfoAtom —— useGetUserIdentity／Login 解密成功後寫入的記憶體副本。
 *    走 wp-option fallback（GET /power-partner/account-info）的站台 localStorage 是空的，
 *    只有這顆 atom 拿得到帳密。
 * 2. atom 還沒寫入時（例如 HMR 後）才退回 localStorage 的密文自行解密。
 *
 * ⚠️ decrypt() 的 catch 會 localStorage.removeItem + window.location.reload()，
 * 所以呼叫前一定要先確認密文非空，否則使用者會莫名其妙被整頁重整。
 */
export const useAccountInfo = (): TAccountInfo | null => {
	const accountInfo = useAtomValue(accountInfoAtom)

	return useMemo(() => {
		if (accountInfo?.email && accountInfo?.password) {
			return accountInfo
		}

		const cipher = localStorage.getItem(LOCALSTORAGE_ACCOUNT_KEY)

		if (!cipher) {
			return null
		}

		const decrypted = decrypt(cipher, true) as Partial<TAccountInfo>

		if (!decrypted?.email || !decrypted?.password) {
			return null
		}

		return {
			email: decrypted.email,
			password: decrypted.password,
		}
	}, [accountInfo])
}
