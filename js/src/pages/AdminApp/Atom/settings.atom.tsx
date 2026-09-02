import { atom } from 'jotai'

import { allow_delete_site } from '@/utils'

/**
 * 是否允許在「所有站台」列表顯示「刪除網站」按鈕
 *
 * 預設值取自 WP option power_partner_settings.power_partner_allow_delete_site，
 * 由「設定」tab 的開關控制；存檔後 useSave 會即時寫回此 atom。
 */
export const allowDeleteSiteAtom = atom<boolean>(allow_delete_site)
