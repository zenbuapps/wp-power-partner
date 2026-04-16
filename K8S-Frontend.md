# feature/k8s-frontend — 變更摘要

共 **41 commits**（相對於 master），分為 5 個功能迭代：

---

## 1. UI 對齊重構

> 統一 PowerCloud 站台列表與管理介面的視覺風格，對齊 `nestjs-helm-admin`

- 新增 `ContentCard` 可重用容器元件，包裝所有 tab 頁面
- 新增 `WebsiteListFilter` 篩選元件
- 新增 `WebsiteActionButtons` 與共用型別定義
- 重構 `PowercloudContent`：加入篩選、卡片佈局、欄位對齊
- 調整 `AccountIcon` 樣式以對齊 admin header

---

## 2. Memo 欄位

> 在管理端與用戶端的 PowerCloud 站台列表新增備注欄位

- `IWebsite` 介面新增 `memo` 欄位
- Admin 站台列表新增 memo 欄
- User 站台列表新增 memo 欄

---

## 3. Website Editor（編輯站台功能）

> 前端新增完整的網站編輯頁面，支援基本資料與方案修改

- 擴充 `IWebsite` 型別加入 `phpVersion`、`labels`、`packageId`、`userId`
- 新增 `WebsitePackageSelector`、`UserSelector`、`LabelSelector` 選擇器元件
- 新增 `useUpdateWebsite` hook，呼叫 `PATCH /websites` 與 `PATCH /php-version`
- 新增 `WebsiteEditorForm`（含摘要卡片 + 可編輯欄位）
- 新增 `WebsiteEditor` 頁面（含麵包屑 + 資料 fetch）
- Dashboard 新增 `HashRouter` 路由 `/websites/edit/:id`
- `WebsiteActionButtons` 新增 Edit 按鈕（Link 至編輯頁）
- Admin 站台列表新增「建立網站」按鈕
- 修復 `react-router-dom` v7 相容性問題
- 修復 WebsiteEditor API 回應資料取值邏輯

---

## 4. Template Selector（模板站選擇器）

> 手動建站表單加入模板站下拉選擇

- PowerCloud 手動建站表單新增 template selector
- 修正 template selector 對齊 codebase 命名規範

---

## 5. Subscription Binding（訂閱綁定）

> 在 PowerCloud 站台列表顯示綁定訂閱，並支援解綁操作

**後端：**
- 自動建站時將 PowerCloud `websiteId` 寫入 `pp_linked_site_ids`
- 啟用/停用站台優先使用 `pp_linked_site_ids` 作為依據
- 新增 `POST /unbind-site` REST endpoint

**前端：**
- 新增 `IWebsiteWithSubscription` 型別與 `useSubscriptionApps` hook
- 新增 `SubscriptionBinding` inline 元件（顯示綁定訂閱 + 解綁按鈕）
- PowerCloud 站台列表新增「訂閱綁定」欄位

**修復：**
- WPCD `enable_site` 加入 `host_type` 判斷，避免誤呼叫
- 穩定化 debounce 行為
- 改善 `SiteList` filter 狀態管理、錯誤處理、空狀態顯示
