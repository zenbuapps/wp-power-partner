/**
 * 公開 API 權限邊界測試
 *
 * 涵蓋: 已從 public 收斂為需要登入的 GetPartnerId、GetApps、GetSubscriptionsNextPayment，
 *       以及更早收斂的 GetAccountInfo（manage_options）、GetCustomers（需要登入）
 *
 * 對應 spec:
 *   spec/features/partner/查詢合作夥伴ID.feature — Rule: 需要登入
 *   spec/features/partner/查詢帳號資訊.feature — Rule: 需要 manage_options
 *   spec/features/subscription/查詢網站對應訂閱.feature — Rule: 需要登入
 *   spec/features/user/查詢客戶資訊.feature — Rule: 需要登入
 *   spec/features/license-code/查詢訂閱下次付款日.feature — Rule: 需要登入
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

// ─── GetPartnerId (Logged-in only) ──────────────────────────

// partner_id 是 Trust-On-First-Use 綁定鑰匙，匿名可讀會造成搶綁窗口（finding #247）
test.describe('GET /partner-id — 匿名存取被拒', () => {

	test('P0: 未認證呼叫 partner-id 回 401', async () => {
		const res = await publicGet('power-partner/partner-id')
		expect(res.status).toBe(401)
	})

	test('P0: 未認證的回應不含 partner_id 值', async () => {
		const res = await publicGet('power-partner/partner-id')
		const body = JSON.stringify(res.data)
		// 回應不應洩漏 partner_id（WP REST 401 只含 code/message/data.status）
		expect(body).not.toMatch(/"partner_id"\s*:\s*"\d+"/)
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

// ─── GetApps (Logged-in only) ───────────────────────────────

// 訂閱與站台的對應關係是內部業務資料，匿名不應存取
test.describe('GET /apps — 匿名存取被拒', () => {

	test('P0: 未認證呼叫 apps 回 401', async () => {
		const res = await publicGet('power-partner/apps')
		expect(res.status).toBe(401)
	})

	test('P0: 帶 app_ids 未認證仍回 401', async () => {
		const res = await publicGet('power-partner/apps', url => {
			url.searchParams.append('app_ids[]', '999999')
		})
		expect(res.status).toBe(401)
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

// ─── GetSubscriptionsNextPayment (Logged-in only) ────────────

// 付款時間戳是財務資訊，訂閱 ID 為可猜測的序號，匿名不應存取
test.describe('GET /subscriptions/next-payment — 匿名存取被拒', () => {

	test('P0: 未認證呼叫回 401', async () => {
		const res = await publicGet('power-partner/subscriptions/next-payment', url => {
			url.searchParams.append('ids[]', '1')
		})
		expect(res.status).toBe(401)
	})

	test('P0: 未認證的回應不含付款時間', async () => {
		const res = await publicGet('power-partner/subscriptions/next-payment', url => {
			url.searchParams.append('ids[]', '1')
		})
		const body = JSON.stringify(res.data)
		expect(body).not.toContain('"time"')
	})
})
