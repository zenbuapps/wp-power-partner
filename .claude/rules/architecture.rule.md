---
paths:
  - "**/*.php"
---

# Power Partner — PHP 架構規則

## 高層架構

```
┌─────────────────────────────────────────────────────────────────┐
│                     PARTNER'S WORDPRESS SITE                    │
│                                                                  │
│  WooCommerce Subscription ──────────────────────────────────┐   │
│  (customer purchases)                                        │   │
│                                                              ▼   │
│  ┌──────────────┐    ┌──────────────┐    ┌───────────────┐  │   │
│  │  SiteSync    │    │  DisableHooks│    │  LC\LifeCycle │  │   │
│  │  (provision) │    │  (suspend)   │    │  (lic codes)  │  │   │
│  └──────┬───────┘    └──────┬───────┘    └───────┬───────┘  │   │
│         │                   │                    │           │   │
│         ▼                   ▼                    ▼           │   │
│  ┌──────────────┐    ┌──────────────┐    ┌───────────────┐  │   │
│  │  Fetch       │    │  ActionSched │    │  Domains\Cloud│  │   │
│  │  FetchPC     │    │  (async)     │    │  CloudClient  │  │   │
│  └──────┬───────┘    └──────────────┘    └───────┬───────┘  │   │
└─────────┼────────────────────────────────────────┼──────────┘
          │                                         │
          ▼                                         ▼
┌──────────────────────┐              ┌───────────────────────┐
│  cloud.luke.cafe     │              │  api.wpsite.pro        │
│  (WPCD / legacy)     │              │  (PowerCloud / new)    │
│  + license codes     │              │                        │
└──────────────────────┘              └───────────────────────┘
```

> `Fetch`（WPCD）、`BillingPushClient`、`LinkedLC`、LC 4 檔呼叫 cloud.luke.cafe 的 power-partner-server 時，**全部經 `Domains\Cloud\Services\CloudClient`**（圖中 `Fetch` → cloud.luke.cafe 的箭頭也是）。`FetchPowerCloud` 打 api.wpsite.pro，不經它。瀏覽器則不直連 cloud.luke.cafe，改打本站 `/power-partner/cloud/*`，由 `Cloud\Core\ProxyApi` 經 `CloudClient` 轉送。

---

## 訂閱生命週期 Hook 對應

```
Customer buys subscription
         │
         ▼
  INITIAL_PAYMENT_COMPLETE ──────┬──── SiteSync::site_sync_by_subscription()
                                  │         └── [powercloud] FetchPowerCloud::site_sync()
                                  │         └── [wpcd]       Fetch::site_sync()
                                  │         └── do_action('pp_site_sync_by_subscription')
                                  │                 └── (公開擴充點，PP 內部已無監聽者，見 issue #21)
                                  │         └── [powercloud] email_payloads_tmp + as_schedule_single_action(+240s)
                                  │                 └── SiteSync::send_email() → SubscriptionEmailHooks::send_mail()
                                  │
                                  └──── LC\LifeCycle::create_lcs()
                                             └── CloudClient::remote_post('license-codes', ...)
                                             └── send_email_to_subscriber()

         │
         │  (time passes...)
         │
  SUBSCRIPTION_FAILED ────────────┬──── DisableHooks::schedule_disable_site()
  (Powerhouse: → cancelled/expired)│         └── DisableSiteScheduler::schedule_single(now + N days)
                                   │
                                   └──── LC\LifeCycle::subscription_failed()
                                             └── ExpireHandler::schedule_single(now + 4h)

         │
         │  (N days later via ActionScheduler)
         │
  [ASYNC] disable_site_hook ──────────── FetchPowerCloud::disable_site() OR Fetch::disable_site()

         │
         │  (customer pays, subscription recovers)
         │
  SUBSCRIPTION_SUCCESS ───────────┬──── DisableHooks::cancel_disable_site_schedule()
                                   ├──── DisableHooks::restart_all_stopped_sites_scheduler()
                                   │         └── Fetch::enable_site() / FetchPowerCloud::enable_site()
                                   └──── LC\LifeCycle::subscription_success()
                                             └── ExpireHandler::unschedule()
                                             └── CloudClient: license-codes/recover

  woocommerce_subscription_status_updated ── SubscriptionEmailHooks::on_status_updated()
         ├── → on-hold:            排程 subscription_failed 催繳信（最少延遲 10 分鐘，先清舊排程）
         │                          + 取消未寄出的成功信
         ├── → pending-cancel:     取消未寄出的「即將扣款」(next_payment) 信（期末不再扣款，commit 4d3763c）
         ├── → cancelled/expired:  排程 end 訂閱結束信 + 取消未寄出的催繳信 + 成功信 + 即將扣款信（commit 4d3763c）
         └── → active (復活，from ∈ on-hold/pending-cancel/cancelled/expired，不含 pending):
                                    取消未寄出的催繳信
                                    + 排程 subscription_success 成功信（最少延遲 10 分鐘）
         （寄送當下 SubscriptionEmailScheduler::action_callback 會複查狀態：
           催繳信須仍為 on-hold、end 信須仍為 cancelled/expired、成功信須仍為 active；
           即將扣款信在 pending-cancel/cancelled/expired 時跳過，否則照寄）

  woocommerce_customer_changed_subscription_to_cancelled ── SubscriptionEmailHooks::schedule_customer_cancelled_email()
         （客戶於「我的帳號」自行取消訂閱，issue #20）
         └── 排程 customer_cancelled 通知信給經銷商（收件人 admin_email、不 Bcc，立即寄、不 unique、寄送當下不複查狀態）
         注意：hook 名取自「客戶請求的狀態」（取消一律請求 cancelled），非落地狀態——
               落地 pending-cancel 或 cancelled 皆 fire 此 hook（..._to_pending-cancel 永不觸發，不綁）；
               管理員後台取消與金流失敗只走 woocommerce_subscription_status_updated，不觸發此信

  SUBSCRIPTION_SUCCESS (Powerhouse hook) ─── DisableHooks / LC\LifeCycle（網站恢復/授權碼恢復）
         注意：Email 不走此路徑（Email 改由 woocommerce_subscription_status_updated 觸發）
```

---

## Domain Modules

### `Domains\Email`
管理所有外發 Email 排程與發送。

- **`Core\SubscriptionEmailHooks`** — Singleton。將每個 `Action` enum hook 連接到排程發信。從 `power_partner_settings['emails']` 讀取模板。另綁 WCS `woocommerce_customer_changed_subscription_to_cancelled` → `schedule_customer_cancelled_email()`（issue #20，客戶自行取消 → 通知經銷商）。**不再綁 `pp_site_sync_by_subscription`**（issue #21：該路徑的 tokens 只有 order + subscription，結構上拿不到 FRONTURL / ADMINURL / SITEUSERNAME / SITEPASSWORD，會寄出滿是 `##XXX##` 的半成品且比正確的那封先到）。`send_mail()` 在替換 token 前會檢查 `REQUIRED_SITE_TOKENS`（FRONTURL / ADMINURL / SITEUSERNAME / SITEPASSWORD）——**模板有用到但值為空時中止寄送並記 error**，計入 `failed_emails`；模板沒用到則不受影響。
- **`DTOs\Email`** — 繼承 `J7\WpUtils\Classes\DTO`。建構時驗證 `action_name`、`operator`、`days`（enum 外白名單：`site_sync`、`customer_cancelled`）。`unique` 自動為 true（trial_end/next_payment/end）。
- **`Models\SubscriptionEmail`** — 結合 Email DTO + Subscription 計算最終發送 timestamp。使用 Powerhouse 的 `Times` DTO。
- **`Services\SubscriptionEmailScheduler`** — 繼承 `Powerhouse\Domains\AsSchedulerHandler\Shared\Base`。hook: `power_partner/3.1.0/email/scheduler`。`register()` 必須在 Bootstrap 中呼叫。

### `Domains\Site`
管理網站停用/恢復排程。

- **`Core\DisableHooks`** — Singleton。`SUBSCRIPTION_FAILED` → 排程停用；`SUBSCRIPTION_SUCCESS` → 取消排程 + 重新啟用。重新啟用時逐站以 `LinkedSites::resolve_host_type()` 判斷架構（與停用對齊），WPCD / PowerCloud 啟用失敗（非 2xx / WP_Error）時皆寫入「重新啟用網站失敗」訂單備註 + error log（issue #18）。
- **`Services\DisableSiteScheduler`** — ActionScheduler 包裝。args 包含 `{subscription_id}`。停用時**逐站**以 `LinkedSites::resolve_host_type($product_host_type, $site_id)` 判斷架構（**純數字 site id 一律 WPCD，覆寫產品 host_type；非純數字 UUID 才參考明確 host_type，空值預設 PowerCloud**），不靠產品欄位覆寫 id 格式（issue #18；#38621：產品被誤設 powercloud 但站是數字 WPCD id → PowerCloud 回 400 "uuid is expected"）。WPCD 與 PowerCloud 路徑皆依回傳值寫入「已停用網站」或「停用網站失敗」訂單備註（issue #13 / #18：API 失敗不再被誤記為成功）。

### `Domains\LC`（License Codes）
管理授權碼生命週期，透過 `cloud.luke.cafe` API，經 `Cloud\Services\CloudClient` 發送（原本用 Powerhouse 的 `Api\Base`；`CloudClient` 的 `remote_get/post/delete` 簽名與它相同，LC 4 檔以 `use ...CloudClient as CloudApi` 別名換入）。

- **`Core\LifeCycle`** — `INITIAL_PAYMENT_COMPLETE` 建立 LCs；`SUBSCRIPTION_FAILED` 排程停用（延遲 4 小時）；`SUBSCRIPTION_SUCCESS` 恢復（帶 `legacy_on_not_owner`）。成敗看 HTTP 2xx（`CloudClient::is_success()`），失敗統一走 `log_failure()`：備註「❌失敗」+ error log；建立失敗時不寫 `lc_id`。
- **`Core\Api`** — 管理端授權碼 REST API。
- **`Services\ExpireHandler`** — ActionScheduler 包裝，延遲 LC 停用（`license-codes/expire`，帶 `legacy_on_not_owner`；失敗走 `LifeCycle::log_failure()`）。

### `Domains\Cloud`（CloudServer 連線，v3.6.0）
本站與 CloudServer（cloud.luke.cafe 的 power-partner-server）之間的認證、連線與轉送。**最高原則：漸進、向後相容，任何一版更新後都不能 403 或功能中斷**——本版 client 可能早於、也可能晚於 CloudServer 升級，兩種順序都要能用。**唯一例外：3.6.0 必須在 power-partner-server #23 部署之後才發版**（真實密碼改由本站主機送出，舊版 CloudServer 在 IP 被 Fluent Security 封鎖時無法分辨，見 CLAUDE.md「CloudServer 連線」）。

- **`Services\CloudClient`** — Singleton，呼叫 CloudServer 的**唯一出口**。`remote_get/post/delete(string $endpoint, array $params, array $args)` 簽名與 Powerhouse `Api\Base` 相同；`request()` 自動選模式（`MODE_DEALER` / `MODE_LEGACY`），dealer 收到 `CREDENTIAL_REJECTED_CODES` 時標記失效並**當次改用 legacy 重送**；legacy 也回 `LEGACY_DISABLED_CODES`（共用帳號已停用 / 已撤銷）時記下 `CredentialStore::mark_legacy_disabled()`、回傳 dealer 的原始錯誤，之後一律先試 dealer（`note_legacy_outcome()` / `is_legacy_unusable()`）；dealer 成功時清除失效標記；`request_with_mode()` 指定模式、不 fallback（`v3/connect` 用 `MODE_NONE` 不帶 Authorization）。唯一的窄例外：`$args['legacy_on_not_owner'] = true` 且端點在 `LEGACY_ON_NOT_OWNER_ENDPOINTS`（`license-codes/recover`、`license-codes/expire`）時，dealer 收到 403 `not_owner` 以 legacy 重送一次（不標記失效、寫 warning log；只有 LifeCycle 重啟與 ExpireHandler 停用開啟，理由見 CLAUDE.md 陷阱 27）。`is_success()`（HTTP 2xx）/ `failure_message()` 供呼叫端判定成敗——不可只看 `is_wp_error()`。所有請求帶 `Fetch::cloud_headers()` 的 telemetry 與 `Origin`（CloudServer 部分端點的授權碼網域比對依賴它）。`last_mode` 供測試與 log 使用。
- **`Services\CredentialStore`** — Abstract，加密存放兩種憑證（option 名見 CLAUDE.md）：經銷商專屬應用程式密碼（`get/save/delete_dealer`、`mark_dealer_invalid`）與 legacy 登入帳密（`get/save/delete_legacy_account`；`mark_legacy_account_rejected` / `is_legacy_account_rejected`：CloudServer 明確回過「帳密錯誤」就 24 小時內不再送出，見 CLAUDE.md 常見陷阱 38）；共用帳號健康狀態 `mark_legacy_disabled` / `note_legacy_ok` / `is_legacy_disabled` / `legacy_disabled_recently` / `legacy_ok_recently`（`power_partner_cloud_legacy_state`）。sodium secretbox，金鑰由 `wp_salt('auth')` + `wp_salt('secure_auth')` + context 衍生，host 封進密文；解不開或 host 不符回 `null`（視為未連線，不 fatal）。`decrypt_old_account_info()` 能讀舊版 CryptoJS 密文（EVP_BytesToKey + AES-256-CBC）。
- **`Services\ConnectionService`** — Abstract。`status()` / `connect()` / `disconnect()` / `exchange()` / `installation_id()` / `migration()`。`exchange()` 打 `POST v3/connect`，結果以 `EXCHANGE_*` 常數表示（`ok` / `unsupported` / `not_eligible` / `bad_credentials` / `rate_limited` / `temporary`）。`connect()` 在 `unsupported` / `not_eligible` / `temporary` 時退回舊 `POST identity`，成功後保存 legacy 帳密；所有送出真實密碼的路徑都經過 `send_with_real_password( $send, SEND_* )`：閘門 `may_send_real_password()`（滑動 30 分鐘失敗 2 次、429 後冷卻 30 分鐘），送出後 `record_real_password_outcome()`；被擋下時回 `GATE_ERROR_CODE` 的 WP_Error（`is_gated()`）。`is_password_rejected()` 只認 CloudServer「帳密錯誤」的固定回應、排除 429；`is_rate_limited()` 看 HTTP 或 body 的 429。`attention()` 是「需要經銷商處理」的唯一判斷，`Notices` 與 AdminApp 的 `connection.message` 都讀它。
- **`Core\ConnectionApi`** — `GET/POST/DELETE /power-partner/connection`，`manage_options`。
- **`Core\ProxyApi`** — Singleton。`ROUTES` 逐條宣告 `/power-partner/cloud/*`，分 admin（`manage_options`）/ customer（`is_user_logged_in`）兩種 audience。身分參數由 PHP 覆寫、customer 強制 `customer_id`、`change-domain` / `toggle-ssl` 先確認站台歸屬、`license-codes/release` 先確認每一個授權碼都屬於自己（`CUSTOMER_OWNERSHIP_ROUTES`）；上游 status 與 JSON body 原樣回傳。`forward_log_detail()` 處理 `logs/{id}/detail` 的 dealer / legacy 帳密差異；帳密被拒過就直接回 401 `pp_relogin_required`，不送出。`REAL_PASSWORD_ROUTES`（`identity`）的 body 帶真實密碼，與登入共用同一個失敗上限。完整路由表見 CLAUDE.md「REST API Routes」。
- **`Shared\RawResponse`** — 繼承 `WP_REST_Response`，`serve()` 掛在 `rest_pre_serve_request`，CSV 這類非 JSON 內容原文輸出（headers 已由 `serve_request` 依 `get_headers()` 送出，會蓋掉預設的 JSON `Content-Type`）。
- **`Shared\Arr`** — `assoc()` / `from_json()`，把外部 JSON 與 option 內容收斂成字串 key 的關聯陣列（PHPStan 用）。
- **`Core\Migration`** — Singleton。hook `power_partner/3.6.0/cloud/migrate`（AS 每日 recurring，`init` 時視 `needs_migration()` 排程或移除）。把 legacy 帳密換成專屬密碼；404 / 429 / 5xx 明天再試，401 與 `not_eligible` 標 `failed` 停止。401 另外標記帳密被拒，之後不再送出。`exchange()` 帶本站 partner_id 比對，換到別的經銷商時不保存、撤銷並標 `failed`（`EXCHANGE_PARTNER_MISMATCH`）。
- **`Core\Notices`** — Singleton。`admin_notices`：`ConnectionService::attention()` 有值時，對 `manage_options` 顯示它的文案。判斷與文案都不寫在這裡。

`Bootstrap` 在 `Api\User::instance()` 之後依序啟動 `ConnectionApi` → `ProxyApi` → `Migration` → `Notices`。**不寄任何信**，異常只寫 log 或顯示 notice。

### `Domains\Settings`
監聽設定變更並重新排程。

- **`Core\WatchSettingHooks`** — `update_option_power_partner_settings` 時：(a) N-days 值變更則重排所有 `disable_site` actions，(b) Email 時間變更則非同步重排所有訂閱 Email。使用 transaction 確保原子性。

---

## API 層

### `Api\Fetch`（WPCD / 舊架構）
Abstract class。透過 `Cloud\Services\CloudClient`（HTTP Basic Auth：經銷商專屬應用程式密碼優先、共用帳號 fallback）與 `cloud.luke.cafe` 通訊。

- `cloud_headers(): array` — `CloudClient` 組 request 的底：`Content-Type`、`X-PP-Client-Version`、`X-PP-Site`（telemetry，不參與驗證）、共用帳號的 `Authorization`（實際送出前由 `CloudClient` 依模式覆寫）。**不可直接拿它去打 `wp_remote_*`**
- `site_sync(array $props)` — POST `/wp-json/power-partner-server/site-sync`
- `disable_site(string $site_id, string $reason): bool` — POST `.../v2/disable-site`，HTTP 2xx 才回傳 `true`；`partner_id` 為空時直接回 `false` 不送出（issue #18）
- `enable_site(string $site_id): bool` — POST `.../v2/enable-site`，HTTP 2xx 才回傳 `true`；`partner_id` 為空時直接回 `false` 不送出（issue #18）
- `get_allowed_template_options()` — 取得 + 快取模板列表（transient `power_partner_allowed_template_options`；成功無 TTL，失敗寫空陣列 5 分鐘負面快取 `ALLOWED_TEMPLATE_OPTIONS_FAILURE_TTL`；尚未連線時不發請求也不寫快取）。前台不呼叫，`Bootstrap` 只在 `is_admin()` 才取
- `fetch_template_sites_by_user()` — GET `.../template-sites?user_id={partner_id}`；沒有 `partner_id` 回 `null`

### `Api\FetchPowerCloud`（新架構）
Abstract class。透過 `X-API-Key` header 與 `api.wpsite.pro` 通訊。

- `site_sync(array $props, string $plan_id, string $template_id)` — POST `/wordpress`
- `disable_site(string $user_id, string $websiteId): bool` — PATCH `/wordpress/{id}/stop`，HTTP 2xx 才回傳 `true`（issue #13）
- `enable_site(string $user_id, string $websiteId): bool` — PATCH `/wordpress/{id}/start`，HTTP 2xx 才回傳 `true`（issue #13）
- `get_allowed_template_options()` — GET `/templates/wordpress`
- `get_open_site_plan_options()` — GET `/website-packages`

### `Api\Main` — 核心 REST routes
在 `rest_api_init` 註冊。包含大部分 endpoints。

### `Api\Connect` — Partner 連線
- `GET/POST/DELETE /partner-id`（新版 AdminApp 的登入/登出改走 `Cloud\Core\ConnectionApi` 的 `/connection`；`POST /partner-id` 仍接受舊參數 `encrypted_account_info` 純為舊版相容）
- `GET /account-info` — 需 `manage_options`（3.5.5 起；原為 public，回傳可被任何人解密的經銷商帳密）
- 注意: 底部有 `new Connect()` 額外初始化

### `Api\User` — 客戶資料
- `GET /customers-by-search`
- `GET /customers` — 需登入（3.5.5 起；原為 public）。非管理員只能查自己，`user_ids` 為空（含被過濾到空）時在 `get_users()` 之前回空陣列，不可讓空 `include` 撈出全部使用者

---

## 商品設定（DataTabs）

### `Product\DataTabs\LinkedSites`
在 WooCommerce 商品編輯器（General tab）新增自訂欄位，支援 subscription 和 variable-subscription。

- `power_partner_host_type` — `powercloud`（新）或 `wpcd`（舊）
- `power_partner_host_position` — 伺服器區域（jp, tw, us_west, uk_london, sg, hk, canada）
- `power_partner_linked_site` — 模板站 ID
- `power_partner_open_site_plan` — PowerCloud 方案 ID

UI 使用 tab widget（新架構 / 舊架構），inline JS/CSS 處理切換邏輯。切換 tab 時 disabled 非活動 tab 的 hidden inputs。

### `Product\DataTabs\LinkedLC`
在訂閱商品新增欄位，連結 cloud.luke.cafe 的授權碼商品。取授權碼商品列表（`GET license-codes/products`）時 `user_id` 送 CloudServer 上的經銷商 id（`power_partner_partner_id`，決定授權碼牌價等級），**不是本站使用者 id**；尚未連線（沒有 partner_id）時維持舊行為送本站使用者 id，避免更新後商品編輯頁的授權碼選單變空。經 `CloudClient`。

---

## 環境設定

`Utils\Base::set_api_auth(Bootstrap $bootstrap)` 根據環境設定 API base URL 與帳密。**帳密（`username` / `psw`，共用帳號 powerpartner）寫死在這裡，現在只剩 legacy fallback 用途**（`CloudClient::MODE_LEGACY`），要等 CloudServer 關閉 legacy 後才能移除；`Bootstrap::$t` 仍被賦值但**不再 localize 到前端**。base URL 與 PowerCloud API 仍照下表使用:

| `wp_get_environment_type()` | Base URL | PowerCloud API |
|---|---|---|
| `local` | `http://cloud.local` | `http://localhost:5000` |
| `staging` | `https://test1.powerhouse.cloud` | `https://api.wpsite.pro` |
| *(production)* | `https://cloud.luke.cafe` | `https://api.wpsite.pro` |

---

## Token 系統

`Utils\Token::replace(string $script, array $tokens): string`
替換 `##UPPERCASEKEY##` 模式。keys 自動大寫。Array 和空值跳過。

Token 組裝方法:
- `Token::get_order_tokens(\WC_Order)` — 訂單相關 tokens
- `Token::get_subscription_tokens(\WC_Subscription)` — 訂閱相關 tokens（含 site URL）
