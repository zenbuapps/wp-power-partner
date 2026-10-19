/**
 * 公開 API 測試（不需認證）
 *
 * 涵蓋: GetPartnerId, GetApps, GetSubscriptionsNextPayment（public），
 *       以及已從 public 收斂的 GetAccountInfo、GetCustomers（驗證匿名被拒）
 *
 * 對應 spec:
 *   spec/features/partner/查詢合作夥伴ID.feature — Rule: 無權限限制（public）
 *   spec/features/partner/查詢帳號資訊.feature — Rule: 需要 manage_options
 *   spec/features/subscription/查詢網站對應訂閱.feature — Rule: 無權限限制（public）
 *   spec/features/user/查詢客戶資訊.feature — Rule: 需要登入
 *   spec/features/license-code/查詢訂閱下次付款日.feature — Rule: 無權限限制（public）
 *
 * 全部以完全未認證（無 Cookie、無 X-WP-Nonce）的方式呼叫。
 */
import { test, expect, request as pwRequest } from '@playwright/test'

let baseURL: string

test.beforeAll(async ({}, testInfo) => {
	baseURL = testInfo.project.use.baseURL!
})

/** 以完全未認證的方式發送 GET 請求 */
async function publicGet(endpoint: string, searchParamsCb?: (url: URL) => void) {
	const ctx = await pwRequest.newContext()
	const url = new URL(`${baseURL}/wp-json/${endpoint}`)
	if (searchParamsCb) searchParamsCb(url)
	const res = await ctx.get(url.toString())
	const data = await res.json().catch(() => ({}))
	const headers = res.headers()
	await ctx.dispose()
	return { data, status: res.status(), headers }
}

// ─── GetPartnerId (Public) ───────────────────────────────────

test.describe('GET /partner-id — Public API', () => {

	// P0: 不需認證即可存取
	test('P0: 未認證可呼叫 partner-id（不回 401/403）', async () => {
		const res = await publicGet('power-partner/partner-id')
		// 有設定時回 200，未設定時可能回 500（partner_id is empty）
		expect([200, 500].includes(res.status)).toBe(true)
	})

	// P1: 回應格式
	test('P1: 回應結構包含 data 欄位', async () => {
		const res = await publicGet('power-partner/partner-id')
		if (res.status === 200) {
			expect(res.data).toHaveProperty('data')
		}
	})

	test('P1: 有設定時 partner_id 不為空', async () => {
		const res = await publicGet('power-partner/partner-id')
		if (res.status === 200) {
			const body = res.data as { data?: { partner_id?: string } }
			if (body.data?.partner_id !== undefined) {
				// 若有值，應為字串
				expect(typeof body.data.partner_id).toBe('string')
			}
		}
	})

	// P2: 未設定 partner_id 的情境（已在 partner-api.spec.ts 中測試設定/刪除，這裡僅驗證公開存取格式）
	test('P2: 回應格式一致，不論是否有設定', async () => {
		const res = await publicGet('power-partner/partner-id')
		// 格式應是 JSON object，不管有無設定
		expect(typeof res.data).toBe('object')
	})
})

// ─── GetAccountInfo (Admin only) ─────────────────────────────

// encrypted_account_info 是經銷商在 cloud.luke.cafe 的真實帳密，加密 passphrase 是公開的
// Plugin::$kebab，任何人拿到密文都解得開 —— 匿名必須被擋（管理員路徑見 01-admin/partner-api.spec.ts）
test.describe('GET /account-info — 匿名存取被拒', () => {

	test('P0: 未認證呼叫 account-info 回 401', async () => {
		const res = await publicGet('power-partner/account-info')
		expect(res.status).toBe(401)
	})

	test('P0: 未認證的回應不含 encrypted_account_info', async () => {
		const res = await publicGet('power-partner/account-info')
		expect(JSON.stringify(res.data)).not.toContain('encrypted_account_info')
	})
})

// ─── GetApps (Public) ────────────────────────────────────────

test.describe('GET /apps — Public API', () => {

	// P0: 不需認證即可存取
	test('P0: 未認證可查詢網站對應訂閱', async () => {
		const res = await publicGet('power-partner/apps')
		expect(res.status).toBe(200)
		expect(Array.isArray(res.data)).toBe(true)
	})

	// P1: 查詢不存在的 app_id
	test('P1: 查詢不存在的 app_id 回空結果（非錯誤）', async () => {
		const res = await publicGet('power-partner/apps', url => {
			url.searchParams.append('app_ids[]', '999999')
		})
		expect(res.status).toBe(200)
		expect(Array.isArray(res.data)).toBe(true)
	})

	test('P1: 回應結構正確（有資料時含 app_id 和 subscription_ids）', async () => {
		const res = await publicGet('power-partner/apps', url => {
			url.searchParams.append('app_ids[]', '1')
		})
		expect(res.status).toBe(200)
		const data = res.data as Array<Record<string, unknown>>
		if (data.length > 0) {
			expect(data[0]).toHaveProperty('app_id')
			expect(data[0]).toHaveProperty('subscription_ids')
			expect(Array.isArray(data[0].subscription_ids)).toBe(true)
		}
	})

	// P2: 多個 app_ids
	test('P2: 查詢多個 app_ids', async () => {
		const res = await publicGet('power-partner/apps', url => {
			url.searchParams.append('app_ids[]', '100')
			url.searchParams.append('app_ids[]', '200')
		})
		expect(res.status).toBe(200)
		expect(Array.isArray(res.data)).toBe(true)
	})
})

// ─── GetCustomers (Logged-in only) ───────────────────────────

// 原本公開，傳任意 user_ids 就回 user_login / user_email —— 匿名必須被擋。
// 登入後的範圍（管理員任意、一般會員只能查自己）由 PHPUnit 的 CredentialExposureHotfixTest 覆蓋
test.describe('GET /customers — 匿名存取被拒', () => {

	test('P0: 未認證以 user_ids 查詢客戶回 401', async () => {
		const res = await publicGet('power-partner/customers', url => {
			url.searchParams.append('user_ids[]', '1')
		})
		expect(res.status).toBe(401)
	})

	test('P0: 未認證的回應不含任何 user_email', async () => {
		const res = await publicGet('power-partner/customers', url => {
			url.searchParams.append('user_ids[]', '1')
		})
		expect(JSON.stringify(res.data)).not.toContain('user_email')
	})
})

// ─── GetSubscriptionsNextPayment (Public) ────────────────────

test.describe('GET /subscriptions/next-payment — Public API', () => {

	// P0: 不需認證即可存取
	test('P0: 未認證可查詢下次付款日', async () => {
		const res = await publicGet('power-partner/subscriptions/next-payment', url => {
			url.searchParams.append('ids[]', '1')
		})
		// 回 200（有或無訂閱皆可）
		expect(res.status).toBe(200)
	})

	// P1: 參數驗證（spec 規定 ids 必須為陣列）
	test('P1: ids 非陣列格式回 400', async () => {
		const res = await publicGet('power-partner/subscriptions/next-payment', url => {
			url.searchParams.set('ids', '1')
		})
		// spec: "訂閱 id 須為陣列"
		expect(res.status).toBe(400)
	})

	// P2: 查詢不存在的 ID
	test('P2: 查詢不存在的訂閱 ID 回空陣列', async () => {
		const res = await publicGet('power-partner/subscriptions/next-payment', url => {
			url.searchParams.append('ids[]', '999999')
		})
		expect(res.status).toBe(200)
		expect(Array.isArray(res.data)).toBe(true)
		expect((res.data as Array<unknown>).length).toBe(0)
	})

	test('P2: 多個訂閱 ID 批次查詢', async () => {
		const res = await publicGet('power-partner/subscriptions/next-payment', url => {
			url.searchParams.append('ids[]', '1')
			url.searchParams.append('ids[]', '2')
		})
		expect(res.status).toBe(200)
		expect(Array.isArray(res.data)).toBe(true)
	})

	test('P2: 回應中每個元素含 id 和 time（UNIX timestamp）', async () => {
		const res = await publicGet('power-partner/subscriptions/next-payment', url => {
			url.searchParams.append('ids[]', '1')
		})
		expect(res.status).toBe(200)
		const data = res.data as Array<Record<string, unknown>>
		if (data.length > 0) {
			expect(data[0]).toHaveProperty('id')
			expect(data[0]).toHaveProperty('time')
			// time 應為數字（UNIX timestamp）
			if (data[0].time !== null) {
				expect(typeof data[0].time).toBe('number')
			}
		}
	})
})
