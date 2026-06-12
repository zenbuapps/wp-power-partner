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
│  │  Fetch       │    │  ActionSched │    │  Powerhouse   │  │   │
│  │  FetchPC     │    │  (async)     │    │  CloudApi     │  │   │
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
                                  │                 └── SubscriptionEmailHooks::schedule_site_sync_email()
                                  │
                                  └──── LC\LifeCycle::create_lcs()
                                             └── CloudApi::remote_post('license-codes', ...)
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
                                             └── CloudApi: license-codes/recover

  woocommerce_subscription_status_updated ── SubscriptionEmailHooks::on_status_updated()
         ├── → on-hold:            排程 subscription_failed 催繳信（最少延遲 10 分鐘，先清舊排程）
         │                          + 取消未寄出的成功信
         ├── → cancelled/expired:  排程 end 訂閱結束信 + 取消未寄出的催繳信 + 取消未寄出的成功信
         └── → active (復活，from ∈ on-hold/pending-cancel/cancelled/expired，不含 pending):
                                    取消未寄出的催繳信
                                    + 排程 subscription_success 成功信（最少延遲 10 分鐘）
         （寄送當下 SubscriptionEmailScheduler::action_callback 會複查狀態：
           催繳信須仍為 on-hold、end 信須仍為 cancelled/expired、成功信須仍為 active，否則跳過）

  SUBSCRIPTION_SUCCESS (Powerhouse hook) ─── DisableHooks / LC\LifeCycle（網站恢復/授權碼恢復）
         注意：Email 不走此路徑（Email 改由 woocommerce_subscription_status_updated 觸發）
```

---

## Domain Modules

### `Domains\Email`
管理所有外發 Email 排程與發送。

- **`Core\SubscriptionEmailHooks`** — Singleton。將每個 `Action` enum hook 連接到排程發信。從 `power_partner_settings['emails']` 讀取模板。
- **`DTOs\Email`** — 繼承 `J7\WpUtils\Classes\DTO`。建構時驗證 `action_name`、`operator`、`days`。`unique` 自動為 true（trial_end/next_payment/end）。
- **`Models\SubscriptionEmail`** — 結合 Email DTO + Subscription 計算最終發送 timestamp。使用 Powerhouse 的 `Times` DTO。
- **`Services\SubscriptionEmailScheduler`** — 繼承 `Powerhouse\Domains\AsSchedulerHandler\Shared\Base`。hook: `power_partner/3.1.0/email/scheduler`。`register()` 必須在 Bootstrap 中呼叫。

### `Domains\Site`
管理網站停用/恢復排程。

- **`Core\DisableHooks`** — Singleton。`SUBSCRIPTION_FAILED` → 排程停用；`SUBSCRIPTION_SUCCESS` → 取消排程 + 重新啟用。PowerCloud 啟用失敗（非 2xx / WP_Error）時寫入「重新啟用網站失敗」訂單備註 + error log。
- **`Services\DisableSiteScheduler`** — ActionScheduler 包裝。args 包含 `{subscription_id}`。PowerCloud 停用依 `FetchPowerCloud::disable_site()` 回傳值寫入「已停用網站」或「停用網站失敗」訂單備註（issue #13：API 失敗不再被誤記為成功）。

### `Domains\LC`（License Codes）
管理授權碼生命週期，透過 `cloud.luke.cafe` API（Powerhouse CloudApi）。

- **`Core\LifeCycle`** — `INITIAL_PAYMENT_COMPLETE` 建立 LCs；`SUBSCRIPTION_FAILED` 排程停用（延遲 4 小時）；`SUBSCRIPTION_SUCCESS` 恢復。
- **`Core\Api`** — 管理端授權碼 REST API。
- **`Services\ExpireHandler`** — ActionScheduler 包裝，延遲 LC 停用。

### `Domains\Settings`
監聽設定變更並重新排程。

- **`Core\WatchSettingHooks`** — `update_option_power_partner_settings` 時：(a) N-days 值變更則重排所有 `disable_site` actions，(b) Email 時間變更則非同步重排所有訂閱 Email。使用 transaction 確保原子性。

---

## API 層

### `Api\Fetch`（WPCD / 舊架構）
Abstract class。透過 HTTP Basic Auth 與 `cloud.luke.cafe` 通訊。

- `site_sync(array $props)` — POST `/wp-json/power-partner-server/site-sync`
- `disable_site(string $site_id, string $reason)` — POST `.../v2/disable-site`
- `enable_site(string $site_id)` — POST `.../v2/enable-site`
- `get_allowed_template_options()` — 取得 + 快取模板列表（7 天 transient）

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
- `GET/POST/DELETE /partner-id`
- `GET /account-info`
- 注意: 底部有 `new Connect()` 額外初始化

### `Api\User` — 客戶資料
- `GET /customers-by-search`
- `GET /customers`

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
在訂閱商品新增欄位，連結 cloud.luke.cafe 的授權碼商品。

---

## 環境設定

`Utils\Base::set_api_auth(Bootstrap $bootstrap)` 根據環境設定 API 帳密:

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
