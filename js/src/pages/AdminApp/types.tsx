/** 登入表單的欄位（cloud.luke.cafe 的帳號密碼，只在送出 POST /connection 時使用，不落地） */
export type TAccountInfo = {
	email: string
	password: string
}

export type TIdentityData = {
	user_id: string // partner_id 在 cloud 上的 user_id
	power_money_amount: string
	email: string
	partner_lv?: {
		key: string
		title: string
		discount: string
	}
	allowed_template_options: {
		[key: string]: string
	}
} | null

export type TIdentity = {
	status: number
	message: string
	data: TIdentityData
}

/**
 * 本站與雲端的連線方式
 * - dealer：經銷商專屬應用程式密碼
 * - legacy：舊版共用憑證（遷移完成前的 fallback，功能照常）
 * - none：尚未連線，需要登入
 */
export type TConnectionMode = 'dealer' | 'legacy' | 'none'

/** 舊版連線遷移到專屬密碼的進度 */
export type TConnectionMigration = 'done' | 'pending' | 'failed' | 'none'

export type TConnection = {
	mode: TConnectionMode
	migration?: TConnectionMigration
	message?: string
}

/**
 * GET / POST /power-partner/connection 的回應 body
 *
 * ⚠️ 成敗看 body 的 status，不看 HTTP status：
 * 未連線時是 HTTP 200 包著 { status: 401 }，與舊版 /identity 失敗時
 * HTTP 200 包著 { status: 500 } 的風格一致。
 */
export type TConnectionResponse = {
	status: number
	message?: string
	data?: TIdentityData
	connection?: TConnection
}
