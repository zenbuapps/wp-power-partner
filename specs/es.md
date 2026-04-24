# Event Storming: Power Partner

> Power Partner 是一個 WordPress 外掛，安裝後可讓 WooCommerce 訂閱商品與 cloud.luke.cafe / PowerCloud 的模板網站連結。當用戶訂閱首次付款成功後，系統會自動複製模板站並將網站帳密寄給用戶；訂閱失敗時排程關站與停用授權碼，訂閱恢復時重新啟用。
>
> **版本:** 3.2.5 | **文件日期:** 2026-03-15

---

## Actors

- **Admin** [人]: WordPress 管理員，擁有 `manage_options` 權限，負責設定外掛、管理 Email 模板、手動開站、管理授權碼
- **Customer** [人]: 在前台購買訂閱商品的用戶，購買後自動獲得網站與授權碼
- **WooCommerce** [系統]: 負責訂單與訂閱的生命週期管理，觸發各類 hook
- **CloudServer** [外部系統]: cloud.luke.cafe (WPCD 舊架構) 提供開站、關站、啟用 API 以及授權碼管理
- **PowerCloud** [外部系統]: api.wpsite.pro (新架構) 提供開站、停止、啟動 WordPress 網站 API
- **ActionScheduler** [系統]: WordPress Action Scheduler，負責延遲執行排程任務（寄信、關站、停用授權碼）

---

## Aggregates

### Settings（外掛設定）
> wp_options `power_partner_settings`

| 屬性 | 說明 |
|------|------|
| `power_partner_disable_site_after_n_days` | 訂閱失敗後幾天關站，預設 7 |
| `emails` | Email 模板陣列，每個包含 enabled/key/action_name/subject/body/days/operator |

### PartnerConnection（合作夥伴連結）
> wp_options `power_partner_partner_id` + `power_partner_account_info`

| 屬性 | 說明 |
|------|------|
| `partner_id` | cloud.luke.cafe 的合作夥伴 ID |
| `account_info` | 加密的合作夥伴帳號資訊 |

### SubscriptionProduct（訂閱商品 - 開站設定）
> WooCommerce product / variation post_meta

| 屬性 | 說明 |
|------|------|
| `power_partner_host_type` | 主機架構類型: `powercloud`(預設) / `wpcd` |
| `power_partner_host_position` | 主機地區: jp/tw/us_west/uk_london/sg/hk/canada |
| `power_partner_linked_site` | 連結的模板站 ID |
| `power_partner_open_site_plan` | PowerCloud 開站方案 ID |
| `linked_lc_products` | 關聯的授權碼商品陣列 `[{product_slug, quantity}]` |

### ShopSubscription（WooCommerce 訂閱）
> WooCommerce post_type `shop_subscription` + post_meta

| 屬性 | 說明 |
|------|------|
| `pp_linked_site_ids` | 多值 meta，此訂閱連結的雲端網站 ID 列表 |
| `is_power_partner_site_sync` | 標記為 Power Partner 開站訂閱 |
| `pp_create_site_responses` | 開站 API 回應 JSON（存於父訂單） |
| `_pp_create_site_responses_item` | 開站 API 回應 JSON（存於訂單項目） |
| `lc_id` | 多值 meta，此訂閱綁定的授權碼 ID 列表 |
| `linked_lc_products` | 此訂閱關聯的授權碼商品設定 |
| `email_payloads_tmp` | PowerCloud 開站後暫存的 Email Token（發信後刪除） |

### PowerCloudApiKey（PowerCloud API 金鑰）
> wp_options transient `power_partner_powercloud_api_key_{user_id}` (TTL 30 天)

| 屬性 | 說明 |
|------|------|
| `api_key` | 使用者的 PowerCloud API Key |

---

## Commands

### SaveSettings
- **Actor:** Admin
- **Aggregate:** Settings
- **Predecessors:** 無
- **參數:** `{ power_partner_disable_site_after_n_days: number, emails: Email[] }`
- **Description:**
  - What: 更新外掛設定（關站天數、Email 模板）
  - Why: 讓管理員調整訂閱失敗後的關站延遲與客戶通知信件
  - When: 管理員在後台儲存設定時

#### Rules
- 前置（狀態）: 使用者需有 `manage_options` 權限
- 後置（狀態）: `power_partner_settings` option 被更新
- 後置（狀態）: 若 `disable_site_after_n_days` 變更，所有 pending 的關站排程會被重新計算
- 後置（狀態）: 若 Email 排程相關設定（trial_end/next_payment/end）變更，所有訂閱 Email 排程會重新排程

### SaveEmails
- **Actor:** Admin
- **Aggregate:** Settings
- **Predecessors:** 無
- **參數:** `{ emails: Email[] }`
- **Description:**
  - What: 單獨更新 Email 模板列表（已標記 @deprecated）
  - Why: 向後相容，建議改用 SaveSettings
  - When: 管理員透過舊版 Email 設定 API 時

#### Rules
- 前置（狀態）: 使用者需有 `manage_options` 權限
- 前置（參數）: `emails` 必須為陣列
- 後置（狀態）: `power_partner_settings.emails` 被更新

### SetPartnerId
- **Actor:** Admin
- **Aggregate:** PartnerConnection
- **Predecessors:** 無
- **參數:** `{ partner_id: string, encrypted_account_info: string, allowed_template_options: object }`
- **Description:**
  - What: 設定合作夥伴連結，儲存 partner_id 與帳號資訊
  - Why: 必須先連結合作夥伴帳號才能使用開站功能
  - When: 管理員在後台透過 cloud.luke.cafe 完成帳號連結後回調

#### Rules
- 前置（狀態）: 使用者需有 `manage_options` 權限
- 前置（參數）: `partner_id` 不可為空
- 後置（狀態）: `power_partner_partner_id` option 被更新
- 後置（狀態）: `power_partner_account_info` option 被更新
- 後置（狀態）: `power_partner_allowed_template_options` transient 被設定

### DeletePartnerId
- **Actor:** Admin
- **Aggregate:** PartnerConnection
- **Predecessors:** 無
- **參數:** 無
- **Description:**
  - What: 刪除合作夥伴連結
  - Why: 讓管理員能解除帳號連結
  - When: 管理員在後台執行登出合作夥伴帳號

#### Rules
- 前置（狀態）: 使用者需有 `manage_options` 權限
- 後置（狀態）: `power_partner_partner_id` option 被刪除
- 後置（狀態）: `power_partner_account_info` option 被刪除
- 後置（狀態）: `power_partner_allowed_template_options` transient 被刪除

### SavePowerCloudApiKey
- **Actor:** Admin
- **Aggregate:** PowerCloudApiKey
- **Predecessors:** 無
- **參數:** `{ api_key: string }`
- **Description:**
  - What: 儲存使用者的 PowerCloud API Key
  - Why: PowerCloud 新架構開站需要透過 API Key 認證
  - When: 管理員在後台輸入 PowerCloud API Key

#### Rules
- 前置（狀態）: 使用者需已登入且有 `manage_options` 權限
- 前置（參數）: `api_key` 不可為空
- 後置（狀態）: transient `power_partner_powercloud_api_key_{user_id}` 被設定（TTL 30天）

### SiteSyncBySubscription（系統觸發 - 訂閱首次付款成功）
- **Actor:** WooCommerce
- **Aggregate:** ShopSubscription
- **Predecessors:** INITIAL_PAYMENT_COMPLETE hook
- **參數:** `{ subscription: WC_Subscription, args: array }`
- **Description:**
  - What: 當訂閱首次付款成功時，自動根據商品設定複製模板站
  - Why: 這是核心功能 — 客戶付款後自動建立 WordPress 網站
  - When: WooCommerce 觸發 `powerhouse/subscription/initial_payment_complete` hook 且只有一筆關聯訂單

#### Rules
- 前置（狀態）: 訂閱必須只有一筆關聯訂單（parent order），續訂不觸發
- 前置（狀態）: 商品必須有設定 `power_partner_linked_site`（模板站 ID）
- 前置（狀態）: 商品類型必須為 `subscription` 或 `subscription_variation`
- 後置（狀態）: 根據 host_type 呼叫 CloudServer 或 PowerCloud 開站 API
- 後置（狀態）: 開站結果存入訂單 meta `pp_create_site_responses` 與訂單項目 meta
- 後置（狀態）: 訂閱標記 `is_power_partner_site_sync` meta
- 後置（狀態）: 觸發 `pp_site_sync_by_subscription` action（用於排程寄信）
- 後置（狀態）: PowerCloud 開站成功(201)後，排程 4 分鐘後寄送帳密 Email

### ManualSiteSync
- **Actor:** Admin
- **Aggregate:** ShopSubscription
- **Predecessors:** 無
- **參數:** `{ site_id: string, host_position: string }`
- **Description:**
  - What: 管理員手動觸發開站（使用 WPCD 舊架構 API）
  - Why: 讓管理員能手動為客戶建立網站（除錯或補開）
  - When: 管理員在後台手動開站介面操作

#### Rules
- 前置（狀態）: 使用者需有 `manage_options` 權限
- 後置（狀態）: 呼叫 CloudServer site_sync API

### CustomerNotification（系統觸發 - WPCD 回調）
- **Actor:** CloudServer
- **Aggregate:** ShopSubscription
- **Predecessors:** WPCD 完成開站後回調
- **參數:** `{ CUSTOMER_ID, REF_ORDER_ID, NEW_SITE_ID, IPV4, DOMAIN, FRONTURL, ADMINURL, SITEUSERNAME, SITEPASSWORD, ... }`
- **Description:**
  - What: WPCD 開站完成後回調此 API，將網站帳密 Email 給客戶
  - Why: WPCD 舊架構的開站是異步的，完成後需要通知客戶
  - When: CloudServer 完成網站建立後主動呼叫

#### Rules
- 前置（狀態）: 請求 IP 必須在白名單內（或 local/staging 環境）
- 前置（參數）: `CUSTOMER_ID` 必須對應有效的 WordPress 用戶
- 後置（狀態）: 發送 `site_sync` 類型的 Email 給客戶
- 後置（狀態）: 若有 `NEW_SITE_ID` 且有對應訂閱，將 site_id 加入訂閱的 `pp_linked_site_ids`

### LinkSite（系統觸發 - WPCD 回調）
- **Actor:** CloudServer
- **Aggregate:** ShopSubscription
- **Predecessors:** WPCD 開站後回調
- **參數:** `{ subscription_id: string, site_id: string }`
- **Description:**
  - What: 將雲端網站 ID 綁定到訂閱上
  - Why: WPCD 異步開站完成後，需要將網站與訂閱建立關聯
  - When: CloudServer 開站成功後回調

#### Rules
- 前置（狀態）: 請求 IP 必須在白名單內
- 後置（狀態）: `site_id` 被加入訂閱的 `pp_linked_site_ids` meta

### ChangeSubscription
- **Actor:** Admin
- **Aggregate:** ShopSubscription
- **Predecessors:** 無
- **參數:** `{ subscription_id: string, site_id: string, linked_site_ids: string[] }`
- **Description:**
  - What: 將網站重新綁定到指定的訂閱上
  - Why: 管理員需要能修正錯誤綁定或轉移網站
  - When: 管理員在後台操作網站搬遷

#### Rules
- 前置（狀態）: 使用者需有 `manage_options` 權限
- 前置（參數）: `subscription_id` 與 `site_id` 不可為空
- 前置（參數）: `linked_site_ids` 必須為陣列
- 前置（狀態）: 訂閱必須存在且有父訂單
- 後置（狀態）: 原本綁定這些 site_id 的訂閱會被解綁
- 後置（狀態）: 新的 site_id 列表會綁定到目標訂閱

### SendSiteCredentialsEmail
- **Actor:** Admin
- **Aggregate:** ShopSubscription
- **Predecessors:** 無
- **參數:** `{ domain: string, password: string, adminEmail?: string, frontUrl?: string, adminUrl?: string, username?: string, ip?: string }`
- **Description:**
  - What: 手動發送站點帳號密碼 Email 給管理員
  - Why: 前端手動開站後需要發送帳密信
  - When: 管理員在後台 PowerCloud 手動開站完成後

#### Rules
- 前置（狀態）: 使用者需有 `manage_options` 權限且已登入
- 前置（參數）: `domain` 與 `password` 不可為空
- 前置（狀態）: 必須存在 `action_name` 為 `site_sync` 的 Email 模板
- 後置（狀態）: 使用 `site_sync` 模板發送 Email

### ClearTemplateSitesCache
- **Actor:** Admin
- **Aggregate:** Settings (transient)
- **Predecessors:** 無
- **參數:** 無
- **Description:**
  - What: 清除模板站列表快取
  - Why: 當站長調整模板站後，需要清除快取以取得最新列表
  - When: 管理員手動點選清除快取按鈕

#### Rules
- 前置（狀態）: 使用者需有 `manage_options` 權限
- 後置（狀態）: `power_partner_allowed_template_options` transient 被刪除

### ScheduleDisableSite（系統觸發 - 訂閱失敗）
- **Actor:** WooCommerce
- **Aggregate:** ShopSubscription
- **Predecessors:** SUBSCRIPTION_FAILED hook (active -> cancelled/on-hold/pending-cancel)
- **參數:** `{ subscription: WC_Subscription }`
- **Description:**
  - What: 訂閱失敗後，排程在 N 天後停用所有已連結的網站
  - Why: 給用戶緩衝期，避免立即關站造成困擾
  - When: WooCommerce 訂閱狀態從 active 變為 cancelled/on-hold/pending-cancel

#### Rules
- 前置（狀態）: 訂閱狀態從 active 變為失敗狀態（cancelled/on-hold/pending-cancel）
- 後置（狀態）: 取消所有 pending 的關站排程
- 後置（狀態）: 建立新的 ActionScheduler 排程，延遲 `disable_site_after_n_days` 天後執行關站
- 後置（狀態）: 排程到期後，根據 host_type 呼叫 CloudServer 或 PowerCloud 停站 API

### CancelDisableSiteAndRestart（系統觸發 - 訂閱恢復）
- **Actor:** WooCommerce
- **Aggregate:** ShopSubscription
- **Predecessors:** SUBSCRIPTION_SUCCESS hook (failed -> active)
- **參數:** `{ subscription: WC_Subscription }`
- **Description:**
  - What: 訂閱恢復後，取消關站排程並重新啟用所有已停止的網站
  - Why: 用戶完成續訂後需要恢復網站使用
  - When: WooCommerce 訂閱狀態從失敗狀態變回 active

#### Rules
- 前置（狀態）: 訂閱狀態變回 active
- 後置（狀態）: 取消 pending 的關站排程
- 後置（狀態）: 對所有 WPCD 連結的 site_id 呼叫 enable_site API
- 後置（狀態）: 對所有 PowerCloud 連結的 websiteId 呼叫 start API

### ScheduleExpireLicenseCodes（系統觸發 - 訂閱失敗）
- **Actor:** WooCommerce
- **Aggregate:** ShopSubscription
- **Predecessors:** SUBSCRIPTION_FAILED hook
- **參數:** `{ subscription: WC_Subscription }`
- **Description:**
  - What: 訂閱失敗後，排程 4 小時後停用此訂閱綁定的所有授權碼
  - Why: 給用戶緩衝期，避免立即停用授權碼
  - When: 訂閱狀態變為失敗

#### Rules
- 前置（狀態）: 訂閱上有綁定 `lc_id` meta
- 後置（狀態）: 取消既有的 LC 過期排程
- 後置（狀態）: 建立新的 ActionScheduler 排程，4 小時後呼叫 CloudServer expire API

### RecoverLicenseCodes（系統觸發 - 訂閱恢復）
- **Actor:** WooCommerce
- **Aggregate:** ShopSubscription
- **Predecessors:** SUBSCRIPTION_SUCCESS hook
- **參數:** `{ subscription: WC_Subscription }`
- **Description:**
  - What: 訂閱恢復後，取消停用排程並呼叫 API 重新啟用授權碼
  - Why: 用戶完成續訂後需要恢復授權碼
  - When: 訂閱狀態變回 active

#### Rules
- 前置（狀態）: 訂閱上有綁定 `lc_id` meta
- 後置（狀態）: 取消 pending 的 LC 過期排程
- 後置（狀態）: 呼叫 CloudServer `license-codes/recover` API

### CreateLicenseCodes（系統觸發 - 訂閱首次付款成功）
- **Actor:** WooCommerce
- **Aggregate:** ShopSubscription
- **Predecessors:** INITIAL_PAYMENT_COMPLETE hook
- **參數:** `{ subscription: WC_Subscription }`
- **Description:**
  - What: 訂閱首次付款成功後，根據商品設定的 `linked_lc_products` 在 CloudServer 建立授權碼
  - Why: 客戶購買帶有授權碼的訂閱商品時需要自動發碼
  - When: 訂閱首次付款完成

#### Rules
- 前置（狀態）: 必須已設定 `partner_id`
- 前置（狀態）: 商品必須有設定 `linked_lc_products`
- 後置（狀態）: 呼叫 CloudServer `license-codes` API 建立授權碼
- 後置（狀態）: 將 LC ID 寫入訂閱 `lc_id` meta
- 後置（狀態）: 將 `linked_lc_products` 寫入訂閱 meta
- 後置（狀態）: 發送授權碼開通 Email 給客戶

### UpdateLicenseCodes
- **Actor:** Admin
- **Aggregate:** ShopSubscription
- **Predecessors:** 無
- **參數:** `{ ids: int[], post_status: string, domain?: string, product_slug?: string, post_author?: int, subscription_id?: int, customer_id?: int }`
- **Description:**
  - What: 更新授權碼狀態、綁定的訂閱等
  - Why: 管理員需要能手動調整授權碼
  - When: 管理員在後台授權碼管理介面操作

#### Rules
- 前置（狀態）: 使用者需有 `manage_options` 權限
- 後置（狀態）: 若有指定 `subscription_id`，解除 LC 原本的訂閱綁定，綁定到新訂閱
- 後置（狀態）: 若無 `subscription_id`，解除 LC 所有訂閱綁定
- 後置（狀態）: 呼叫 CloudServer `license-codes/update` API
- 後置（狀態）: 若訂閱狀態為 active，LC 設為 available 並觸發 recover

### DeleteLicenseCodes
- **Actor:** Admin
- **Aggregate:** ShopSubscription
- **Predecessors:** 無
- **參數:** `{ ids: int[] }`
- **Description:**
  - What: 刪除授權碼並解除與訂閱的綁定
  - Why: 管理員需要能刪除錯誤或多餘的授權碼
  - When: 管理員在後台授權碼管理介面操作

#### Rules
- 前置（狀態）: 使用者需有 `manage_options` 權限
- 後置（狀態）: 解除所有相關訂閱的 `lc_id` 綁定
- 後置（狀態）: 呼叫 CloudServer `license-codes` DELETE API

### ScheduleSubscriptionEmail（系統觸發 - 訂閱生命週期事件）
- **Actor:** WooCommerce / ActionScheduler
- **Aggregate:** ShopSubscription
- **Predecessors:** 各種訂閱生命週期 hook
- **參數:** `{ subscription: WC_Subscription, action: Action }`
- **Description:**
  - What: 根據訂閱生命週期事件排程發送 Email
  - Why: 自動化客戶通知（如付款成功、付款失敗、試用期結束等）
  - When: 各訂閱生命週期事件觸發時

#### Rules
- 前置（狀態）: 訂閱必須是 Power Partner 開站訂閱（有 `pp_linked_site_ids` meta）
- 前置（狀態）: 必須存在對應 `action_name` 且啟用的 Email 模板
- 後置（狀態）: 建立 ActionScheduler 排程，根據 Email 設定的 days/operator 計算排程時間
- 後置（狀態）: 排程到期後使用 Token 替換發送 Email 給客戶

---

## Read Models

### GetEmails
- **Actor:** Admin
- **Aggregates:** Settings
- **回傳欄位:** `Email[]` — 每個含 enabled, key, action_name, subject, body, days, operator
- **Description:** 取得所有 Email 模板

#### Rules
- 前置（狀態）: 使用者需有 `manage_options` 權限

### GetPartnerId
- **Actor:** Admin / Customer
- **Aggregates:** PartnerConnection
- **回傳欄位:** `{ partner_id: string }`
- **Description:** 取得當前設定的合作夥伴 ID

#### Rules
- 無權限限制（public）

### GetAccountInfo
- **Actor:** Admin / Customer
- **Aggregates:** PartnerConnection
- **回傳欄位:** `{ encrypted_account_info: string }`
- **Description:** 取得加密的合作夥伴帳號資訊

#### Rules
- 無權限限制（public）

### GetSubscriptions
- **Actor:** Admin
- **Aggregates:** ShopSubscription
- **回傳欄位:** `{ id, status, post_title, post_date, linked_site_ids[] }[]`
- **Description:** 取得指定用戶的所有訂閱清單（含綁定的網站 ID）

#### Rules
- 前置（狀態）: 使用者需有 `manage_options` 權限
- 前置（參數）: `user_id` 為必填

### GetApps
- **Actor:** Admin / Customer
- **Aggregates:** ShopSubscription
- **回傳欄位:** `{ app_id, subscription_ids[] }[]`
- **Description:** 根據網站 ID 列表查詢對應的訂閱 ID

#### Rules
- 無權限限制（public）
- 前置（參數）: `app_ids` 為陣列

### GetCustomersBySearch
- **Actor:** Admin
- **Aggregates:** WordPress Users
- **回傳欄位:** `{ id, display_name }[]`
- **Description:** 根據 ID 或搜尋字串查詢客戶

#### Rules
- 前置（狀態）: 使用者需有 `manage_options` 權限
- 前置（參數）: `id` 或 `search` 至少需提供一個

### GetCustomers
- **Actor:** Admin / Customer
- **Aggregates:** WordPress Users
- **回傳欄位:** `{ id, user_login, user_email, display_name }[]`
- **Description:** 根據 user_ids 列表取得使用者資訊

#### Rules
- 無權限限制（public）
- 前置（參數）: `user_ids` 不可為空

### GetSubscriptionsNextPayment
- **Actor:** Admin / Customer
- **Aggregates:** ShopSubscription
- **回傳欄位:** `{ id, time }[]`
- **Description:** 取得指定訂閱們的下次付款時間

#### Rules
- 無權限限制（public）
- 前置（參數）: `ids` 為必填且需為陣列
