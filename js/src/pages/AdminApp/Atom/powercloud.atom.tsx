import { atom } from 'jotai'
import { powercloud_api_key } from '@/utils'

export enum EPowercloudIdentityStatusEnum {
  UN_LOGIN = 'unLogin',
  LOGGED_IN = 'loggedIn',
}

export type TPowercloudIdentity = {
	status: EPowercloudIdentityStatusEnum
	message: string
  apiKey: string
}

export const defaultPowercloudIdentity: TPowercloudIdentity = {
  status: powercloud_api_key
    ? EPowercloudIdentityStatusEnum.LOGGED_IN
    : EPowercloudIdentityStatusEnum.UN_LOGIN,
  message: '',
  apiKey: powercloud_api_key,
}

export const powercloudIdentityAtom = atom<TPowercloudIdentity>(
	defaultPowercloudIdentity
)
