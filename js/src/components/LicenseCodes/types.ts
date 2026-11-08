export type TStatus = 'available' | 'activated' | 'deactivated' | 'expired'

export type DataType = {
	id: number
	post_status: TStatus
	code: string
	is_subscription: boolean
	subscription_id: number
	customer_id: number
	expire_date: number
	domain: string
	product_slug: string
	product_name: string
	post_date	:string
	post_modified: string
	rate: number
	rates: {
		lv_0: number
		lv_1: number
		lv_2: number
	}
	limit_type: 'unlimited' | 'fixed' | 'assigned'
	limit_value: number | string
	limit_unit: 'days' | 'months' | 'years'
}

export type TParams = {
	customer_id?: string | number
	search: string
}
