import { atom } from 'jotai'

import { TConnection, TIdentity } from '@/pages/AdminApp/types'

export const defaultIdentity: TIdentity = {
	status: 100,
	message: 'un-login',
	data: null,
}

export const identityAtom = atom<TIdentity>(defaultIdentity)

export const globalLoadingAtom = atom({
	isLoading: false,
	label: '',
})

export const defaultConnection: TConnection = {
	mode: 'none',
	migration: 'none',
}

/**
 * 本站與雲端的連線狀態（GET / POST /power-partner/connection 回應的 connection 欄位）
 *
 * 與 identityAtom 由同一份回應寫入（useGetUserIdentity），目前只用於
 * 判斷是否顯示「尚未完成新版安全連線」提示。
 */
export const connectionAtom = atom<TConnection>(defaultConnection)

/**
 * 是否正在「重新登入」
 *
 * 已連線（Dashboard 可用）時切換到登入畫面用的旗標。
 * ⚠️ 刻意不先解除綁定：使用者若中途放棄登入，站台仍維持原本的連線，
 * 開站、停用/恢復網站、授權碼等功能不受影響；登入成功時由 POST /connection 直接覆寫。
 */
export const isReconnectingAtom = atom(false)
