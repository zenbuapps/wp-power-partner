# Power Partner — AI Agent 開發指引

**Last Updated:** 2026-04-09
**Plugin Version:** 3.2.17
**Namespace:** `J7\PowerPartner`

---

## 外掛功能

Power Partner 是 WordPress 外掛，讓網站擁有者能以 WooCommerce 訂閱商品形式**銷售 WordPress 網站模板**。客戶購買訂閱後：

1. 自動從模板建立 WordPress 網站（透過 WPCD 舊架構或 PowerCloud 新架構後端）
2. 使用可設定的 HTML Email 模板（`##TOKEN##` 替換格式）將帳密寄給客戶
3. 訂閱失敗時，N 天後自動停用已建立的網站
4. 訂閱恢復時，自動重新啟用網站
5. 同時建立授權碼（Power Shop、Power Course 等）並寄送給客戶

---

## 技術棧

| 層級 | 技術 |
|---|---|
| PHP | PHP >= 8.1, `declare(strict_types=1)` |
| PHP 依賴 | Composer PSR-4（`kucrut/vite-for-wp`, `j7-dev/wp-plugin-trait`） |
| WP 整合 | WooCommerce >= 7.6, Woo Subscriptions >= 5.9, Powerhouse >= 3.3.23 |
| 非同步 | ActionScheduler（WooCommerce 內建） |
| 前端建置 | Vite + `@kucrut/vite-for-wp` |
| 前端框架 | React 18 (TSX), Ant Design 5, Jotai, TanStack Query v5, Refine |
| HTTP | Axios (JS), `wp_remote_*` (PHP) |
| 樣式 | Tailwind CSS, Ant Design CSS-in-JS (`@ant-design/cssinjs`) |
| 代碼品質 | PHPStan, PHPCS (WPCS), ESLint, Prettier |

---

## 專案結構

```
power-partner/
├── plugin.php                      # 外掛入口、版本、必要外掛、啟用 hook
├── inc/classes/
│   ├── Bootstrap.php               # 編排器 singleton — 初始化所有子類別
│   ├── Order.php                   # WC 訂單管理欄位 + metabox
│   ├── ShopSubscription.php        # shop_subscription meta（pp_linked_site_ids）
│   ├── Shortcode.php               # [power_partner_current_user_site_list]
│   ├── Admin/Menu/Setting.php      # 管理頁面 HTML 掛載點
│   ├── Api/
│   │   ├── Main.php                # 核心 REST endpoints
│   │   ├── Connect.php             # partner-id + account-info endpoints
│   │   ├── Fetch.php               # Abstract: WPCD API（舊架構）
│   │   ├── FetchPowerCloud.php     # Abstract: PowerCloud API（新架構）
│   │   └── User.php                # 客戶搜尋 endpoints
│   ├── Domains/
│   │   ├── Email/Core/SubscriptionEmailHooks.php    # 訂閱生命週期 → 排程發信
│   │   ├── Site/Core/DisableHooks.php               # 訂閱失敗 → 停用/恢復網站
│   │   ├── LC/Core/LifeCycle.php                    # 授權碼 建立/停用/恢復
│   │   └── Settings/Core/WatchSettingHooks.php      # 設定變更時重新排程
│   ├── Product/
│   │   ├── SiteSync.php            # INITIAL_PAYMENT_COMPLETE 觸發開站
│   │   └── DataTabs/LinkedSites.php  # 商品欄位: host_type, template, plan
│   └── Utils/
│       ├── Base.php                # 環境 API 設定、常數、mail_to
│       └── Token.php               # ##TOKEN## 替換
├── js/src/
│   ├── main.tsx                    # 入口: 掛載 App1 (admin) + App2 (frontend)
│   ├── pages/AdminApp/             # Dashboard tabs + Login
│   ├── pages/UserApp/              # 客戶網站列表 + 授權碼
│   └── api/                        # Axios 實例 + CRUD resource helpers
├── spec/                           # 規格文件
├── tests/e2e/                      # Playwright E2E 測試
└── release/                        # release-it 設定和腳本
```

---

## 雙主機後端

| `host_type` 值 | 類別 | API base | 認證 |
|---|---|---|---|
| `powercloud` *（預設）* | `Api\FetchPowerCloud` | `https://api.wpsite.pro` | `X-API-Key` header |
| `wpcd` | `Api\Fetch` | `https://cloud.luke.cafe` | HTTP Basic Auth |

PowerCloud API key 儲存方式:
- 全域: transient `power_partner_powercloud_api_key`（無 TTL）
- Per-user 舊版: transient `power_partner_powercloud_api_key_{user_id}`（無 TTL）
- `FetchPowerCloud::get_powercloud_api_key()` 優先使用全域 key，fallback 到 per-user key

---

## WordPress Options

| Key | Type | Description |
|---|---|---|
| `power_partner_settings` | array | `{power_partner_disable_site_after_n_days: int, emails: Email[]}` |
| `power_partner_partner_id` | string | cloud.luke.cafe 的 Partner ID |
| `power_partner_account_info` | string | 加密帳號資訊 |

## WordPress Transients

| Key | TTL | Description |
|---|---|---|
| `power_partner_allowed_template_options` | 7 天 | WPCD 模板列表 `{id: title}` |
| `power_partner_allowed_template_options_powercloud` | 7 天 | PowerCloud 模板列表 `{id: domain}` |
| `power_partner_open_site_plan_options_powercloud` | 7 天 | PowerCloud 方案列表 `{id: name-price}` |
| `power_partner_powercloud_api_key` | 永久 | 全域 PowerCloud API key |
| `power_partner_powercloud_api_key_{user_id}` | 永久 | Per-user PowerCloud API key（舊版） |

## Post Meta

| Key | Post type | Notes |
|---|---|---|
| `pp_linked_site_ids` | `shop_subscription` | **Multi-value** — 使用 `ShopSubscription::get_linked_site_ids()` |
| `pp_create_site_responses` | `shop_order` | JSON: 開站 API 回應 |
| `_pp_create_site_responses_item` | order item | JSON: 逐項開站回應 |
| `is_power_partner_site_sync` | `shop_subscription` | `'1'` 標記為 PP 訂閱 |
| `lc_id` | `shop_subscription` | Multi-value: 授權碼 IDs |
| `email_payloads_tmp` | `shop_subscription` | 暫存: 延遲發信後刪除 |
| `pp_site_url` | `shop_subscription` | 站台網址（含 scheme）。issue #23：網域在兩種架構下都**不存在於開站 API 回應**——PowerCloud 是 `FetchPowerCloud::site_sync()` 本地生成的 `$namespace.'.wpsite.pro'`（原本只在一次性的 `email_payloads_tmp`，寄完信就刪）、WPCD 要等 `/customer-notification` 回調帶回。兩條路徑各自寫入此 meta，`##URL##` 只讀它。**第一個站先寫、之後不覆蓋** |
| `power_partner_host_type` | product/variation | `'powercloud'` 或 `'wpcd'` |
| `power_partner_host_position` | product/variation | 區域: `jp`, `tw`, `us_west`, `uk_london`, `sg`, `hk`, `canada` |
| `power_partner_linked_site` | product/variation | 模板站 ID |
| `power_partner_open_site_plan` | product/variation | PowerCloud 方案 ID |

---

## REST API Routes

**Namespace:** `power-partner` → `/wp-json/power-partner/`

| Method | Route | Auth | Description |
|---|---|---|---|
| POST | `/customer-notification` | IP whitelist | WPCD 回調：寄信通知客戶帳密 |
| POST | `/link-site` | IP whitelist | 綁定 site ID 到訂閱 |
| POST | `/manual-site-sync` | `manage_options` | 手動開站 |
| POST | `/clear-template-sites-cache` | `manage_options` | 清除模板/方案 transients |
| POST | `/send-site-credentials-email` | `manage_options` | 手動寄送帳密 Email |
| GET | `/emails` | `manage_options` | 取得 Email 模板 |
| POST | `/emails` | `manage_options` | 儲存 Email 模板 *(deprecated → 用 /settings)* |
| GET | `/subscriptions` | `manage_options` | 列出用戶的訂閱 |
| POST | `/change-subscription` | `manage_options` | 重新綁定 site IDs |
| GET | `/apps` | public | 查詢 site IDs 對應的訂閱 |
| POST | `/settings` | `manage_options` | 儲存 `power_partner_settings` |
| POST | `/powercloud-api-key` | `manage_options` | 儲存 PowerCloud API key |
| GET | `/partner-id` | public | 取得 partner ID |
| POST | `/partner-id` | `manage_options` | 設定 partner ID + 更新模板快取 |
| DELETE | `/partner-id` | `manage_options` | 移除 partner ID + 清除快取 |
| GET | `/account-info` | public | 取得加密帳號資訊 |
| GET | `/customers-by-search` | `manage_options` | 搜尋用戶 |
| GET | `/customers` | public | 以 ID 陣列查詢用戶 |

**IP Whitelist** (`/customer-notification`, `/link-site`):
- 固定: `103.153.176.121`, `199.99.88.1`, `163.61.60.80`
- 私有範圍: `10.x.x.x`, `172.16-31.x.x`, `192.168.x.x`
- 舊版範圍: `61.220.44.0-61.220.44.10`
- `local` / `staging` 環境跳過檢查

---

## 自訂 Actions

| Action | Args | 時機 |
|---|---|---|
| `pp_site_sync_by_subscription` | `$subscription` | 開站成功後（所有後端）。**純公開擴充點，PP 內部已無監聽者**——issue #21 移除了原本掛在此的 `site_sync` 信排程 |
| `pp_after_site_sync` | `$response_obj` | WPCD API 回應後 |
| `pp_after_site_sync_powercloud` | `$response_obj, $props` | PowerCloud API 回應後 |
| `pp_linked_site_ids_updated` | `$subscription, $new_ids, $old_ids` | `pp_linked_site_ids` **真的變更**後（四個寫入點共同收斂：PowerCloud 開站 201 / WPCD `/customer-notification` / WPCD `/link-site` / 後台手動編輯）。無變更時不 fire。**監聽者必須自吞例外**——PowerCloud 路徑是在 `site_sync_by_subscription()` 的 try/catch 內、且在 `email_payloads_tmp` 寫入之前同步呼叫，往上拋會被誤記成「網站建立失敗」並殺掉開站通知信 |

---

## Email 系統

### Email DTO 欄位

```
string $key, $enabled, $subject, $body, $action_name, $days, $operator; bool $unique
```

### action_name 值

| 值 | 發送時機 |
|---|---|
| `site_sync` | 開站完成後。**只由帶完整站台 payload 的兩條路徑寄出**：PowerCloud 走 `SiteSync::send_email()`（讀 `email_payloads_tmp`，延遲 240 秒）、WPCD 走 `/customer-notification` 回調。**不再由 `pp_site_sync_by_subscription` 排程**（issue #21：該路徑的 tokens 只有 order + subscription，拿不到站台變數，會寄出滿是 `##XXX##` 的半成品且比正確的那封先到） |
| `subscription_failed` | 訂閱進入 on-hold（待處理/催繳階段），寄送當下仍須為 on-hold 才會真的寄出；回到 active 或進入 cancelled/expired 時取消排程 |
| `subscription_success` | 訂閱從 on-hold（待處理）/ pending-cancel（待取消）/ cancelled / expired 恢復為 active 時觸發（**不含** pending → active 首次付款）；每次成功續訂寄一封；10 分鐘緩衝 + 寄送當下須仍為 active；離開 active 時自動取消未寄成功信（issue #16） |
| `end` | 訂閱進入 cancelled/expired（已取消/已過期），寄送當下仍須為 cancelled/expired |
| `trial_end` / `next_payment` | 訂閱里程碑時。`next_payment`（即將扣款）在訂閱進入 pending-cancel/cancelled/expired 時取消排程，且寄送當下複查狀態：pending-cancel/cancelled/expired 不寄（期末不再扣款，修復見 commit 4d3763c；註：該 commit 訊息誤引 "issue #20"，實際無對應 issue——#20 是 customer_cancelled feature） |
| `watch_trial_end` / `watch_next_payment` | 前/後 N 天（unique，設定變更時重排）。`watch_next_payment` 同 `next_payment` 的取消排程與寄送狀態複查（commit 4d3763c） |

**issue #22 補排**：`next_payment` / `watch_next_payment` / `trial_end` / `watch_trial_end` 四種信的唯一排程入口是 `woocommerce_subscription_date_updated`，而新訂閱 fire 該事件時 `pp_linked_site_ids` 尚未寫入 → `schedule_email()` 的 `is_site_sync()` 守門直接 return → **新訂閱的第一個週期排不進去**（下次續訂成功時 WCS 會重算 `next_payment` 而自然補排，所以不是「永遠」）。修法是監聽 `pp_linked_site_ids_updated` 補排一次（`SubscriptionEmailHooks::backfill_subscription_emails()`，白名單 `BACKFILL_ACTIONS`）。⛔ **白名單絕不可加入 `site_sync`**——該 hook 在 PowerCloud 路徑是開站流程內同步 fire，加了會原地重現 issue #21 並擴散到 WPCD。補排時若「寄送時點已過」一律跳過（`schedule_email()` 的 `$skip_if_past`），避免被 `max()` 夾成「現在」而立刻寄出「N 天後將扣款」。
| `watch_end` | **已停用**（v3.3.7 起 `end` 改由狀態轉換觸發，UI 從未提供此選項） |
| `customer_cancelled` | 終端客戶於「我的帳號」**自行**取消訂閱時觸發（issue #20）。收件人是**經銷商**（站台 `admin_email`，不 Bcc），非終端客戶；立即寄出（UI 鎖 days=0/after）、不 unique（每次取消都寄）、寄送當下不複查狀態（取消是歷史事實）。管理員後台取消與金流扣款失敗**不**觸發。觸發 hook 是 WCS `woocommerce_customer_changed_subscription_to_cancelled`——hook 名取自「客戶請求的狀態」（取消一律請求 cancelled），非落地狀態；落地 pending-cancel 或 cancelled 皆 fire 同一 hook，`_to_pending-cancel` 永不觸發（不綁）。客戶照舊另收 `end` 信（若有啟用），互不影響 |

注意：`subscription_failed` / `subscription_success` / `end` 三種信由 `woocommerce_subscription_status_updated`（`SubscriptionEmailHooks::on_status_updated()`）觸發，`customer_cancelled` 由 WCS customer hook（`SubscriptionEmailHooks::schedule_customer_cancelled_email()`）觸發，皆**不走** Powerhouse Action hook；其餘仍走 Powerhouse。WCS 每次排程續訂都會短暫 active → on-hold → active，催繳信與成功信因此固定有最少 10 分鐘排程緩衝 + 寄送當下狀態複查（催繳信須仍 on-hold、成功信須仍 active）；兩者亦互為反向取消，防止震盪期間同時寄出。`SUBSCRIPTION_SUCCESS` Powerhouse hook 仍用於 DisableHooks / LC，Email 不走此路徑。

### 支援的 ##TOKEN## 值

`##FIRST_NAME##` `##LAST_NAME##` `##NICE_NAME##` `##EMAIL##`
`##DOMAIN##` `##FRONTURL##` `##ADMINURL##`
`##SITEUSERNAME##` `##SITEPASSWORD##` `##IPV4##`
`##ORDER_ID##` `##ORDER_ITEMS##` `##ORDER_STATUS##` `##ORDER_DATE##`
`##URL##`（站台網址，來源 `pp_site_url`；開站信 payload 也已帶入）
`##CHECKOUT_PAYMENT_URL##` `##VIEW_ORDER_URL##`

---

## 常見陷阱

1. **Multi-value meta** — `pp_linked_site_ids` 每個訂閱有多行。永遠使用 `ShopSubscription::get_linked_site_ids()`，不要用 `get_post_meta($id, 'pp_linked_site_ids', true)`。

2. **PowerCloud 需要 API key** — 如果 transient 不存在，`FetchPowerCloud::site_sync()` 會拋出異常。管理員必須先在**新架構權限** tab 認證。

3. **模板選項快取 7 天** — 在商品編輯器使用「清除快取」按鈕或呼叫 `POST /clear-template-sites-cache`。

4. **Email 順序** — `Token::replace()` 在 `wpautop()` 之前執行，不可反轉順序。

5. **`pp_create_site_responses` 的結構是 list，讀法只有一個入口** — 實際存的是 `[{status,message,data}]`。issue #23 之前 `Order.php` 讀 `[0]['data']`、`Token.php` 讀 `['data']`（少一層），後者必然取不到值。現已收斂到 `SiteSync::get_create_site_responses()` / `get_first_site_response_data()` / `extract_site_url()`，**不要再各自 `json_decode`**。注意 `data` 型別在兩架構不同：PowerCloud 是 assoc array、WPCD 是 stdClass。

5. **僅首次付款觸發開站** — `SiteSync::site_sync_by_subscription()` 檢查 `count($order_ids) === 1`（僅父訂單）。續訂**不會**觸發新建站。

6. **v2→v3 相容代碼** — `Bootstrap::compatibility_settings()` 標記 `@deprecated v4`，下個大版本刪除。

7. **ActionScheduler 註冊順序** — Scheduler `::register()` 必須在任何可能觸發排程的 action 之前呼叫（Bootstrap 中已正確設定，不要重排）。

8. **PowerCloud 開站回應 201** — 成功回應碼是 HTTP 201（非 200），`SiteSync::site_sync_powercloud()` 依此判斷是否發送 Email。

9. **延遲寄信 4 分鐘** — PowerCloud 開站後透過 `as_schedule_single_action(time() + 240, ...)` 延遲 4 分鐘發送帳密 Email，暫存資料在 `email_payloads_tmp` meta。

10. **Connect.php 底部有 `new Connect()`** — 這是舊代碼，Connect 類別同時使用 SingletonTrait 和底部 `new Connect()` 初始化。

11. **PowerCloud 停用/啟用回傳 bool** — `FetchPowerCloud::disable_site()` / `enable_site()` 只有 HTTP 2xx 才回傳 `true`（issue #13）。呼叫端必須依回傳值決定訂單備註與 log 等級，不可無條件記成功。另外 PowerCloud API key 禁止 raw 落地 log，一律經 `mask_api_key()` 遮罩（len + sha256 前綴）。

12. **停用/啟用架構判斷靠 `resolve_host_type`，不靠產品欄位** — 既有站的架構 ground truth 是**連結 site id 格式**，不是產品 `power_partner_host_type`（該欄位只決定新站開哪）。`DisableSiteScheduler` / `DisableHooks` 一律**逐站**呼叫 `LinkedSites::resolve_host_type($product_host_type, $site_id)`：**純數字 site id 一律 WPCD（覆寫產品 host_type，含被誤設為 powercloud 的情形）；非純數字（UUID）才參考明確 host_type，為空時預設 PowerCloud**。禁止讓產品欄位覆寫 id 格式——舊 WPCD 站（數字 id）若被導去 PowerCloud API，`/wordpress/{id}/start|stop` 回 HTTP 400 "Validation failed (uuid is expected)"、停用/啟用靜默失敗、卡照扣（issue #18；sopro.tw 訂閱 #38621：產品被設成 powercloud 但連結站是數字 WPCD id）。`Fetch::disable_site()` / `enable_site()` 也已改為回傳 bool 並檢查 HTTP status，`partner_id` 為空時直接回 `false` 不送出。

13. **WPCD site id 是數字、PowerCloud websiteId 是 UUID（非純數字）** — `resolve_host_type` 的 `ctype_digit()` 是判斷既有站架構的**主訊號**：純數字 → 一定 WPCD（覆寫產品 host_type），其餘 → PowerCloud。此不變量由站長確認。若未來 PowerCloud 改發純數字 websiteId，此判別會失效，需改用更強訊號（如 stored create-response shape：WPCD 存 `site_id`/`server_id`、PowerCloud 存 `data.websiteId`）。

14. **計費推送異常告警一律走 `raise_alert()`，收件人是服務商不是經銷商** — `DailyBillingCron` 的所有中止／失敗路徑呼叫 `raise_alert()`（原 `notify_admin()`），它同時寫 error log 與寄信到 `ALERT_MAIL_TO`（`info@morepower.club`，可用 filter `power_partner_billing_alert_mail_to` 覆寫；回傳空字串即停寄）。**不得改用站台 `admin_email`**——那是經銷商，而每一種原因的處置（進後台改設定、查兩端契約、聯絡接收端管理員）都是服務商這邊要做的事，寄給經銷商只會讓他收到看不懂也修不了的信。log 與信兩份都要留：信可能被擋或誤刪，log 是留在站上的那一份。

15. **告警內容必須同時回答「是誰」與「什麼問題」** — 信件主旨 `【Power Partner 計費異常】{網域} / {reason}（{業務日}）`；內文由 `alert_mail_html()` 組成三個區塊：**① 哪一台站**（網域／站名／前後台連結／站台管理員 display_name + email／partner_id／已綁定 dealer_id）、**② 發生什麼事**（業務日／原因代碼／處置說明）、**③ 未送出的計費**。log 的結構化 context 由 `alert_context()` 組出（站台網址／partner_id／dealer_id／業務日／原因代碼）。網域是唯一識別，**blogname 不能用**——沒改過站名的站全叫「我的網站」。金額（`total_amount`）與站數（`site_count`）只在**算得出來**時才帶，第 ③ 區塊整段省略——前置中止（`no_api_key`）發生在抓網站清單之前，硬填 0 會讓收信人誤以為「今天本來就沒錢可收」。唯一不走 `raise_alert()` 的是 `no_partner_id`：只留原地的 error log，因為「裝了外掛但從未連結」的站（含模板站與 clone 站）每天都會走到這裡，告警信集中在同一個信箱，這條路徑照寄量最大而這類站沒有任何錢會漏。
