import { TParamsBase } from '@/types'

/**
 * 點數紀錄的分類
 *
 * cron_powercloud 為新架構（PowerCloud）每日扣點，
 * 由伺服端 Utils\Log::CRON_POWERCLOUD 寫入，
 * 刻意與 cron 隔離（伺服端 6 小時重複執行守衛只查 type='cron'）。
 */
export type TLogType = 'cron' | 'cron_powercloud' | 'modify' | 'purchase'

export type DataType = {
	id: string
	title: string
	type: TLogType
	user_id: string
	modified_by: string
	date: string
	point_slug: 'power_money'
	point_changed: string
	new_balance: string
	expire_date?: string | null

	/**
	 * 該筆紀錄是否具備逐站扣點明細（伺服端旗標）
	 *
	 * ⚠️ 舊版伺服端不會回傳此欄位，取到的是 undefined，
	 * 因此判斷式一律寫成 has_detail === true，退回「不顯示下載入口」。
	 */
	has_detail?: boolean
}

export type TLogExtraParams = {
	user_id?: string
	modified_by?: string
	type?: TLogType
}

export type TLogParams = TParamsBase & {
	user_id?: string
	modified_by?: string
	type?: TLogType
}
