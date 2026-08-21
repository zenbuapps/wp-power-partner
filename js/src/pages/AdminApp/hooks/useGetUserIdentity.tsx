import { useEffect, useState } from 'react'
import { cloudAxios, axios } from '@/api'
import {
  accountInfoAtom,
  identityAtom,
  globalLoadingAtom,
} from '@/pages/AdminApp/Atom/atom'
import { useSetAtom } from 'jotai'
import { notification } from 'antd'
import { renderHTML, LOCALSTORAGE_ACCOUNT_KEY, decrypt } from '@/utils'
import { TAccountInfo, TIdentity } from '@/pages/AdminApp/types'
import { useMutation, useQuery } from '@tanstack/react-query'
import { AxiosResponse } from 'axios'

/**
 * 登入流程
 * 看 localStorage 有沒有 account 資訊
 * 有 -> 直接自動登入
 * 沒有 -> 看 經銷站的 option 有沒有紀錄  account 資訊
 *
 * 有 -> 直接自動登入
 * 沒有 -> 輸入帳密
 */

type TGetAccountInfo = {
  status: number
  message: string
  data: {
    encrypted_account_info: string
  }
}

export const useGetUserIdentity = () => {
  const setIdentity = useSetAtom(identityAtom)
  const setGlobalLoading = useSetAtom(globalLoadingAtom)
  const setAccountInfo = useSetAtom(accountInfoAtom)
  const accountInLocalStorage = localStorage.getItem(LOCALSTORAGE_ACCOUNT_KEY)

  const { data, isPending } = useQuery<AxiosResponse<TGetAccountInfo>>({
    queryKey: ['account-info'],
    queryFn: () => axios.get('/power-partner/account-info'),
    enabled: !accountInLocalStorage,
  })

  useEffect(() => {
    if (isPending) {
      setGlobalLoading({
        isLoading: true,
        label: '正在獲取用戶資料...',
      })
    }
  }, [isPending])

  const encryptedAccountInfo =
    accountInLocalStorage || data?.data?.data?.encrypted_account_info

  const mutation = useMutation<unknown, unknown, TAccountInfo, unknown>({
    mutationKey: ['identity'],
    mutationFn: (values: TAccountInfo) => cloudAxios.post('/identity', values),
    onMutate: (values: TAccountInfo) => {
      setGlobalLoading({
        isLoading: true,
        label: '正在獲取用戶資料...',
      })
    },
    onError: (err: any) => {
      console.log('err', err)
    },
    onSuccess: (res: AxiosResponse<TIdentity>) => {
      const theIdentity = res?.data as TIdentity
      setIdentity(theIdentity)
      if (theIdentity?.status !== 200) {
        notification.error({
          message: theIdentity?.message,
          description: renderHTML(JSON.stringify(theIdentity?.data || '')),
        })
      }
    },
    onSettled: () => {
      setGlobalLoading({
        isLoading: false,
        label: '',
      })
    },
    staleTime: 1000 * 60 * 60 * 24,
    gcTime: 1000 * 60 * 60 * 24,
  } as any)

  useEffect(() => {
    if (encryptedAccountInfo) {
      const theAccountInfo: TAccountInfo = decrypt(encryptedAccountInfo, true)

      // 解出來的帳密留一份在記憶體，讓需要帶帳密的功能（例如明細下載）
      // 不必再自己解一次密文（decrypt 失敗會清 localStorage 並重整整頁）
      if (theAccountInfo?.email && theAccountInfo?.password) {
        setAccountInfo(theAccountInfo)
      }
      mutation.mutate(theAccountInfo)
    }
  }, [encryptedAccountInfo])

  useEffect(() => {
    if (!isPending) {
      setGlobalLoading({
        isLoading: false,
        label: '',
      })
    }
  }, [isPending])

  return mutation
}
