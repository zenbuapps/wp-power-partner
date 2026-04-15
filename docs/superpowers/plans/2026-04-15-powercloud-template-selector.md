# PowerCloud Template Selector Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an optional template selector dropdown to the PowerCloud manual site creation form so resellers can choose a website template when creating sites.

**Architecture:** Single React component modification. Fetch templates from PowerCloud API `/templates/wordpress` using existing axios instance and TanStack Query pattern. Pass selected template's domain URL as `templateUrl` in the POST /wordpress body.

**Tech Stack:** React 18, Ant Design 5 (Select), TanStack Query v5, Axios, TypeScript

---

## File Structure

- **Modify:** `js/src/pages/AdminApp/Dashboard/ManualSiteSync/index.tsx` — Add template type, fetch query, Select field, and templateUrl to submission

No new files needed. Single file modification.

---

### Task 1: Add Template Type and Fetch Query

**Files:**
- Modify: `js/src/pages/AdminApp/Dashboard/ManualSiteSync/index.tsx:51-68` (after `IPowercloudPackage` interface)

- [ ] **Step 1: Add `IPowercloudTemplate` interface**

Add after the `IPowercloudPackage` interface (line 68):

```typescript
interface IPowercloudTemplate {
	id: string
	primaryDomain: string
	domain?: string
	subDomain?: string
	wildcardDomain: string
}
```

- [ ] **Step 2: Add `templateUrl` to `TPowercloudOpenSiteParams`**

Modify the existing type at line 205-224 to add the optional `templateUrl` field:

```typescript
type TPowercloudOpenSiteParams = {
	packageId: string
	namespace: string
	wildcardDomain: string
	mysql: {
		auth: {
			rootPassword: string
			password: string
		}
	}
	wordpress: {
		autoInstall: {
			adminUser: string
			adminPassword: string
			adminEmail: string
			siteTitle: string
		}
	}
	ip?: string
	templateUrl?: string
}
```

- [ ] **Step 3: Add template fetch query in `PowercloudOpenSite`**

Inside the `PowercloudOpenSite` component (after the packages query at line 239-245), add:

```typescript
// 取得模板列表
const { data: templatesData, isLoading: isLoadingTemplates } = useQuery({
	queryKey: ['powercloud-template-list'],
	queryFn: () =>
		powerCloudInstance.get('/templates/wordpress?page=1&limit=250'),
})

const websiteTemplates: IPowercloudTemplate[] =
	(templatesData?.data?.data as IPowercloudTemplate[]) || []
```

- [ ] **Step 4: Commit**

```bash
git add js/src/pages/AdminApp/Dashboard/ManualSiteSync/index.tsx
git commit -m "feat: add template type and fetch query for PowerCloud site creation"
```

---

### Task 2: Add Template Select Dropdown to Form

**Files:**
- Modify: `js/src/pages/AdminApp/Dashboard/ManualSiteSync/index.tsx` — the JSX return of `PowercloudOpenSite`

- [ ] **Step 1: Add helper function to resolve template domain**

Add inside `PowercloudOpenSite` component, after the template query:

```typescript
const getTemplateDomain = (template: IPowercloudTemplate): string =>
	template.primaryDomain ||
	template.domain ||
	template.subDomain ||
	template.wildcardDomain
```

- [ ] **Step 2: Add template Select field to the form JSX**

Between the "選擇方案" `Form.Item` (ends around line 378) and the "開站完成後信息寄送Email" `Form.Item` (starts around line 380), add:

```tsx
<Form.Item
	label="網站模板"
	name={['templateId']}
	help="選擇一個模板來創建網站"
>
	<Select
		placeholder="請選擇網站模板"
		loading={isLoadingTemplates}
		options={websiteTemplates.map((tpl) => ({
			label: getTemplateDomain(tpl),
			value: tpl.id,
		}))}
		allowClear
		showSearch
		filterOption={(input, option) =>
			(option?.label as string)
				?.toLowerCase()
				.includes(input.toLowerCase()) ?? false
		}
		disabled={isPending}
		getPopupContainer={() => containerRef.current as HTMLElement}
	/>
</Form.Item>
```

- [ ] **Step 3: Commit**

```bash
git add js/src/pages/AdminApp/Dashboard/ManualSiteSync/index.tsx
git commit -m "feat: add template selector dropdown to PowerCloud site creation form"
```

---

### Task 3: Include templateUrl in Form Submission

**Files:**
- Modify: `js/src/pages/AdminApp/Dashboard/ManualSiteSync/index.tsx` — `handleFinish` function in `PowercloudOpenSite`

- [ ] **Step 1: Update `handleFinish` to include `templateUrl`**

Replace the current `handleFinish` function (lines 319-351) with:

```typescript
const handleFinish = () => {
	form
		.validateFields()
		.then((values) => {
			// 生成隨機配置（只調用一次）
			const wpsiteConfig = generateRandomWpsiteProConfig()

			const { adminEmail, templateId, ...data } = values

			// 解析模板 URL
			const selectedTemplate = templateId
				? websiteTemplates.find((tpl) => tpl.id === templateId)
				: undefined
			const templateUrl = selectedTemplate
				? getTemplateDomain(selectedTemplate)
				: undefined

			createWordPress({
				...data,
				namespace: wpsiteConfig.namespace,
				wildcardDomain: wpsiteConfig.domain,
				wordpress: {
					autoInstall: {
						siteTitle: 'WordPress Site',
						adminUser: adminEmail || identity.data?.email || '',
						adminPassword: handleGenerateRandomPassword('wordpress'),
						adminEmail: adminEmail || identity.data?.email || '',
					},
				},
				mysql: {
					auth: {
						rootPassword: handleGenerateRandomPassword('mysql-root'),
						password: handleGenerateRandomPassword('mysql'),
					},
				},
				...(templateUrl ? { templateUrl } : {}),
			})
		})
		.catch((error) => {
			console.log('表單驗證失敗:', error)
		})
}
```

- [ ] **Step 2: Commit**

```bash
git add js/src/pages/AdminApp/Dashboard/ManualSiteSync/index.tsx
git commit -m "feat: include templateUrl in PowerCloud site creation submission"
```

---

### Task 4: Manual Verification

- [ ] **Step 1: Build and verify no TypeScript errors**

Run: `cd /Users/powerhouse/Documents/works/ai-projects/powerhouse/power-partner && pnpm build`
Expected: Build succeeds with no errors

- [ ] **Step 2: Visual verification**

1. Start dev server: `pnpm dev`
2. Navigate to Admin Dashboard → 手動開站 → 新架構 → 開站 tab
3. Verify: template dropdown appears between "選擇方案" and "開站完成後信息寄送Email"
4. Verify: dropdown is searchable, clearable, shows template domains
5. Verify: form submits successfully with and without a template selected

- [ ] **Step 3: Final commit if any adjustments needed**

```bash
git add js/src/pages/AdminApp/Dashboard/ManualSiteSync/index.tsx
git commit -m "fix: template selector adjustments after manual verification"
```
