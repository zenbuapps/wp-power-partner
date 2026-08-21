import { atom } from 'jotai'
import { TAccountInfo, TIdentity } from '@/pages/AdminApp/types'

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

/**
 * 目前已連結的經銷商帳密（僅存在記憶體中，不落地）
 *
 * 來源與 useGetUserIdentity 完全相同：
 * localStorage 的密文 → 沒有時退回 GET /power-partner/account-info 的
 * encrypted_account_info，兩者擇一後 decrypt(cipher, true) 解出。
 * 這裡只是把「已經解出來的結果」留在記憶體供其他功能重用
 * （例如點數紀錄明細下載必須把帳密放進 POST body），
 * 不新增任何持久化儲存、也不擴大暴露面。
 */
export const accountInfoAtom = atom<TAccountInfo | null>(null)
