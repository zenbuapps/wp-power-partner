# 實作計劃：前臺新架構（PowerCloud）客戶網站列表「變更域名」

> Issue #19 ｜ 範圍模式：**HOLD SCOPE**（純前臺 UI 補件，後端零改動，預估 1 個檔案）
> 釐清結論：`Q1=A Q2=A Q3=A Q4=A Q5=A Q6=B Q7=A`

## 概述

在前臺新架構（PowerCloud）客戶網站列表 `Powercloud.tsx` 的「操作」欄補上「變更域名」功能，讓客戶自助變更自己 PowerCloud 站台的網域。後端 API（`PATCH /wordpress/{id}/domain`）與後臺實作皆已存在，本計劃只 mirror 既有流程到前臺，**不動任何 PHP / REST endpoint**。

## 需求重述

- 為前臺新架構列表每一站台的操作欄加上「變更域名」入口（鉛筆 `EditOutlined` icon button + Tooltip，比照舊架構前臺）。
- 點擊彈出 Modal：顯示動態 DNS A record 提醒（帶 `record.ipAddress`）、當前域名供對照、新網域輸入框（驗證合法 FQDN 且不含 `http(s)://`）。
- 送出後呼叫既有外部 API `PATCH {PowerCloud}/wordpress/{id}/domain`，body `{ domain: <新網域> }`，`X-API-Key` 認證（沿用 `usePowerCloudAxiosWithApiKey`）。
- 採**詳細進度通知**（Q6=B）：送出時顯示「進行中」通知 → 成功顯示成功明細並 `refetch()` → 失敗顯示失敗明細、列表維持原狀。
- 僅 `running`（運行中）可操作（Q5=A）；`stopped` / `creating` / `deleting` 一律 disabled。
- 不額外限制網域類型（Q2=A），SSL 重簽等由 PowerCloud 後端處理（Q7=A）。

## 已知風險（來自研究）

- **風險：Shadow DOM 彈層逸出** — App2（前臺）以 `react-shadow` + `StyleProvider` 包在 Shadow Root 內（見 `App2.tsx`）。antd 的 `Modal` / `notification` / `Tooltip` 預設掛到 `document.body`，會逸出 shadow root 導致 CSS-in-JS 樣式失效。
  緩解：比照舊架構前臺 `SiteListTable/index.tsx` 的做法——建立 `containerRef`、`notification.useNotification` 搭配 `getContainer`、`Modal` 加 `getContainer`、Tooltip 加 `getPopupContainer`，全部指向 `containerRef.current`。
- **風險：重複送出 / 非同步中途關閉** — 改網域需等 2~3 分鐘。緩解：`onMutate` 即關閉 Modal 並開啟 `duration:0` 進行中通知，成功/失敗用**相同 notification key** 覆蓋；提交按鈕 `confirmLoading={isPending}`。
- **風險：成功後列表顯示舊網域（stale）** — 緩解：`onSuccess` 呼叫 `refetch()`，與後臺一致。
- **未發現額外後端風險**（API 與後臺共用、已上線）。

## 架構變更

- **唯一改動檔案**：`js/src/pages/UserApp/SiteList/Powercloud.tsx`
  - 新增 imports、`containerRef`、notification context、Modal/Form 狀態、`changeDomain` mutation、操作欄按鈕、Modal JSX。
- **不新增** 共用元件 / 不動後端 / 不動其他檔案（維持 HOLD SCOPE）。
  - 註：網域驗證 regex 目前已在後臺 `index.tsx` 與舊架構 `ChangeDomainModal.tsx` 各有一份；本計劃沿用同一 regex inline，**不**順手抽共用 util（避免擴大範圍，留待後續重構）。

## 資料流分析

### 變更域名流程

```
使用者輸入新網域 ──▶ 前端驗證 ──▶ onMutate ──▶ PATCH API ──▶ onSuccess/onError ──▶ refetch + 通知
      │                  │              │             │                │                    │
      ▼                  ▼              ▼             ▼                ▼                    ▼
   [空字串?]         [格式不合?]     [關 Modal]    [網路錯誤?]      [HTTP !2xx?]         [清單未更新?]
   →required        →pattern        +進行中通知   →onError         →onError/明細失敗     →refetch()
   →阻擋送出         →阻擋送出                     →失敗通知         成功→成功明細+refetch  →顯示新網域
   [含http(s)://?]                                [API key 缺?]
   →pattern→阻擋                                  →axios 422/丟錯→onError
```

## 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
| --------- | ------------ | -------- | -------- | ----------- |
| Form 驗證 | 新網域為空 | 驗證錯誤 | `rules.required` → 「請輸入新的 domain name」，不送出 | ✅ 表單下方紅字 |
| Form 驗證 | 含 `http(s)://` / 非法格式 | 驗證錯誤 | `rules.pattern` → 「請輸入不含 http(s):// 的合格的網址」，不送出 | ✅ 表單下方紅字 |
| `changeDomain` mutation | API 回 4xx/5xx | HTTP 錯誤 | `onError` → 失敗通知「{old} 變更為 {new} 失敗」，列表不動 | ✅ 失敗通知 |
| `changeDomain` mutation | 網路逾時 / 中斷 | 網路錯誤 | `onError`（同上） | ✅ 失敗通知 |
| `changeDomain` mutation | PowerCloud API key 缺失 | axios 丟錯 | `onError`（同上） | ✅ 失敗通知 |
| 操作欄按鈕 | 站台非 running | 狀態前置 | `disabled` 不可點 + Tooltip 說明 | ✅ 按鈕 disabled |

> 無「處理方式=無 且 靜默」項目 → 無 CRITICAL GAP。

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
| ---------- | -------- | ------- | ------- | ----------- | -------- |
| 提交按鈕重複點擊 | 重複送出同一網域 | ✅ onMutate 關 Modal + confirmLoading | E2E/手動 | 是（進行中通知） | 等通知結果 |
| 等待中關閉視窗 | 使用者誤關 | ✅ 進行中通知文案提醒勿關 + `duration:0` | 手動 | 是 | 後端仍處理，refetch 後顯示 |
| Shadow DOM 彈層逸出 | Modal/通知無樣式 | ✅ getContainer/getPopupContainer 指向 ref | 手動目視 | 是 | — |
| 成功後 stale 列表 | 顯示舊網域 | ✅ onSuccess refetch | E2E/手動 | 是 | refetch |

## 實作步驟

### 第一階段：補上變更域名 UI 與 mutation（檔案：`js/src/pages/UserApp/SiteList/Powercloud.tsx`）

1. **擴充 imports**
   - 行動：`@ant-design/icons` 加 `EditOutlined`、`LoadingOutlined`；`antd` 加 `Modal`、`Form`、`Input`、`Alert`、`Tag`、`notification`；`react` 加 `useRef`。
   - 依賴：無 ｜ 風險：低

2. **建立 Shadow DOM 容器與通知 context**
   - 行動：在元件內加 `const containerRef = useRef<HTMLDivElement>(null)`；`const [api, contextHolder] = notification.useNotification({ placement: 'bottomRight', duration: 10, getContainer: () => containerRef.current as HTMLElement })`。
   - 原因：確保 Modal/通知在 shadow root 內正確渲染與套用樣式，比照 `SiteListTable/index.tsx`。
   - 依賴：步驟 1 ｜ 風險：中（shadow DOM 是本案唯一技術陷阱）

3. **新增 Modal 狀態與表單**
   - 行動：`const [isChangeDomainModalOpen, setIsChangeDomainModalOpen] = useState(false)`、`const [selectedWebsite, setSelectedWebsite] = useState<IWebsite | null>(null)`、`const [form] = Form.useForm()`。
   - 依賴：步驟 1 ｜ 風險：低

4. **新增 `changeDomain` mutation（詳細通知）**
   - 行動：`useMutation`：
     - `mutationFn: ({ id, newDomain }) => powerCloudInstance.patch(\`/wordpress/${id}/domain\`, { domain: newDomain })`
     - `onMutate({ id, oldDomain, newDomain })`：`setIsChangeDomainModalOpen(false)`；`api.open({ key: \`cd-${id}\`, duration: 0, icon: <LoadingOutlined/>, message: '域名變更中...', description: \`正在將 ${oldDomain} 變更為 ${newDomain}，網域變更有可能需要等待 2~3 分鐘左右的時間，請先不要關閉視窗\` })`
     - `onSuccess(_, { id, oldDomain, newDomain })`：`api.success({ key: \`cd-${id}\`, message: '域名變更成功', description: \`${oldDomain} 已成功變更為 ${newDomain}\` })`；`refetch()`；`form.resetFields()`
     - `onError(_, { id, oldDomain, newDomain })`：`api.error({ key: \`cd-${id}\`, message: '域名變更失敗', description: \`${oldDomain} 變更為 ${newDomain} 失敗\` })`
   - 註：使用相同 `key` 讓進行中通知被成功/失敗覆蓋；mutation variables 需帶 `oldDomain`（用 `getDomain(record)`）以產生明細文案。
   - 依賴：步驟 2、3 ｜ 風險：中

5. **新增開啟 Modal / 送出 handler**
   - 行動：`handleShowChangeDomainModal(website)` → `setSelectedWebsite(website)`、`setIsChangeDomainModalOpen(true)`、`form.setFieldsValue({ newDomain: '' })`；`handleChangeDomain()` → `form.validateFields().then(values => selectedWebsite && changeDomain({ id: selectedWebsite.id, oldDomain: getDomain(selectedWebsite), newDomain: values.newDomain }))`。
   - 依賴：步驟 3、4 ｜ 風險：低

6. **操作欄加入「變更域名」按鈕**
   - 行動：在 actions `render` 的 `<Space>` 內，「前往後台」之後加：
     ```tsx
     <Tooltip title="變更域名" getPopupContainer={() => containerRef.current as HTMLElement}>
       <Button type="link" size="small" icon={<EditOutlined />}
         disabled={record.status !== 'running'}
         onClick={() => handleShowChangeDomainModal(record)} />
     </Tooltip>
     ```
   - 原因：Q5=A 只有 running 可操作；用 `Button` 才能 `disabled`。其餘既有 Tooltip/Popconfirm 一併補 `getPopupContainer`（與本案 shadow DOM 修正一致；屬最小必要）。
   - 依賴：步驟 5 ｜ 風險：低

7. **加入 Modal JSX 與容器 ref**
   - 行動：將最外層 `return` 的 `<div>` 加 `ref={containerRef}`，內部開頭放 `{contextHolder}`；列表後加 `Modal`：
     - `title="變更域名 (Domain Name)"`、`open={isChangeDomainModalOpen}`、`onOk={handleChangeDomain}`、`onCancel`（關閉 + `form.resetFields()`）、`confirmLoading={changeDomain isPending}`、`okText="確認變更域名"`、`okButtonProps={{ danger: true }}`、`getContainer={() => containerRef.current as HTMLElement}`。
     - Modal 內 `Form`（`form` layout vertical）：
       - `Alert` info：`描述 = \`請先將網域 DNS 設定中的 A 紀錄 (A Record) 指向 ${selectedWebsite?.ipAddress}，再變更網域\``（Q4=A 動態帶 IP）。
       - 當前域名區塊：`<Text copyable>{getDomain(selectedWebsite)}</Text>`（對照用）。
       - `Form.Item name="newDomain"` rules：`{ required: true, message: '請輸入新的 domain name' }` 與 `{ pattern: /^(?!http(s)?:\/\/)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$/g, message: <>請輸入不含 <Tag>http(s)://</Tag> 的合格的網址</> }`（沿用後臺/舊架構同一 regex）。
   - 依賴：步驟 4、5、6 ｜ 風險：中（shadow DOM 容器掛載）

## 測試策略

> 本案為純前臺 React 變更：**無 PHP 改動 → 無 Pest/PHPUnit 整合測試**；現有 E2E（`tests/e2e/`）皆為 API 層（Playwright `request`），不涵蓋此 UI。專案目前**未配置 JS 單元測試 runner**（無 vitest/jest）。因此自動化關卡以 lint/型別/建置為主，行為驗證以 browser-tester / 手動為主。

- **靜態關卡（必跑、CI 可驗）**：
  - `pnpm lint`（ESLint）
  - `pnpm build`（Vite 型別 + 打包通過，無 TS error）
- **行為驗證（建議）**：`@zenbu-powers:browser-tester`（git-diff 驅動瀏覽器模擬）或手動，對照 `specs/features/site/PowerCloud前臺變更域名.feature` 的 Examples 逐項確認：
  1. running → 按鈕可點；stopped/creating/deleting → disabled。
  2. 空 / 含 `http(s)://` / 非法格式 → 對應錯誤訊息，不送出。
  3. 開 Modal → Alert 顯示帶 `ipAddress` 的提醒 + 當前域名對照。
  4. 合法網域送出 → `PATCH /wordpress/{id}/domain` body `{domain}`、`X-API-Key` 認證 → 進行中通知 → 成功明細 → `refetch()` 顯示新網域。
  5. API 失敗 → 失敗明細，列表維持原網域。
  6. Modal / 通知 / Tooltip 在前臺 Shadow DOM 內樣式正確（目視）。
- **關鍵邊界情況**：重複點擊送出、等待中關閉視窗、shadow DOM 彈層樣式、成功後 stale 列表。
- **測試指令**：`pnpm lint`、`pnpm build`；E2E（非本案重點）：`cd tests/e2e && npx playwright test`。

## 依賴項目

- 既有：`antd`（Modal/Form/Input/Alert/notification/Tooltip/Tag）、`@tanstack/react-query` v5、`@/api`（`powerCloudAxios` + `usePowerCloudAxiosWithApiKey`）、`@/utils`（`currentUserEmail`）。
- 外部服務：PowerCloud API `PATCH /wordpress/{id}/domain`（已存在、後臺共用）。
- 無新增 npm 套件。

## 風險與緩解措施

- **中**：Shadow DOM 彈層逸出 → 以 `containerRef` + `getContainer` / `getPopupContainer` 全面綁定（步驟 2、6、7）。
- **中**：非同步等待期間重複送出 / 誤關視窗 → onMutate 關 Modal + 同 key 通知覆蓋 + 文案提醒（步驟 4）。
- **低**：成功後列表 stale → onSuccess `refetch()`。
- **低**：regex 重複（技術債）→ 本案不抽共用，沿用既有同一份；留註記供後續重構。

## 錯誤處理策略

採「**表單前置驗證擋非法輸入 + mutation 三段式通知（onMutate/onSuccess/onError）對使用者明確回饋**」。所有失敗皆使用者可見（紅字或失敗通知），無靜默失敗；失敗時不更動列表，使用者可重試。

## 限制條件（本計劃不做）

- ❌ 不修改任何 PHP / REST endpoint / PowerCloud API（後端零改動）。
- ❌ 不限制網域類型（自訂 vs wildcard 子網域）（Q2=A）。
- ❌ 不在前臺處理 SSL 重簽 / 通知信（Q7=A，由 PowerCloud 後端負責）。
- ❌ 不抽取共用網域驗證 util（維持 HOLD SCOPE，沿用既有 inline regex）。
- ❌ 不改動 WPCD（舊架構）前臺或後臺既有行為。
- ❌ 不新增 npm 依賴。

## 成功標準

- [ ] 前臺新架構列表每站台操作欄出現「變更域名」入口（鉛筆 icon + Tooltip）。
- [ ] 站台非 `running`（stopped/creating/deleting）時該入口 disabled。
- [ ] 開啟 Modal 顯示帶 `record.ipAddress` 的 DNS A record 提醒與當前域名對照。
- [ ] 空 / 含 `http(s)://` / 非法格式皆擋下並顯示對應錯誤訊息。
- [ ] 合法新網域送出 → `PATCH /wordpress/{id}/domain` body `{ domain }` + `X-API-Key` → 進行中通知 → 成功明細 → `refetch()` 顯示新網域。
- [ ] API 失敗顯示失敗明細且列表維持原網域。
- [ ] Modal / 通知 / Tooltip 在 Shadow DOM 內樣式正確。
- [ ] `pnpm lint`、`pnpm build` 通過。
- [ ] 與舊架構 WPCD 前臺體驗一致。

## 預估複雜度：低～中

（單檔、mirror 既有實作；唯一技術陷阱是 Shadow DOM 彈層容器綁定。）
