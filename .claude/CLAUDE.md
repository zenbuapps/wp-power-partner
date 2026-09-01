# Power Partner — AI Agent 開發指引

**Last Updated:** 2026-09-01
**Plugin Version:** 3.5.2
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
| `power_partner_compatibility_scheduled` | string | 已跑過相容設定的版本號。⚠️ constructor 在版本不同時會先 `delete_option()`，所以 `compatibility()` 裡讀到的 `$previous_version` 永遠是 `'0.0.1'`——`version_compare('3.1.0')` 區塊事實上每次升版都會執行 |
| `power_partner_issue22_backfilled` | string | issue #22 一次性補排的守門（值為當時版本號）。**不可改用 `$previous_version` 判斷**，理由同上。⚠️ **必須等 `as_enqueue_async_action()` 回傳非 0 才寫入**——AS 未初始化時它回 0 且不排任何東西，而 `compatibility()` 也綁在 `upgrader_process_complete`（同步 wp-admin request）上；先寫旗標會讓補排永久跳過（旗標無清除路徑、後台無手動入口）。防重複排程改由 `as_has_scheduled_action()` 負責 |
| `pp_site_sync_lock_{order_id}` | string | issue #24 開站併發鎖，`autoload=no`。值的格式是 `{unix_timestamp}:{隨機}`——前半供逾時判定，後半是持有者身分，讓釋放能做條件式刪除。正常路徑由 `try/finally` 清除，fatal/OOM 留下的殘鎖由逾時接管機制回收 |

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
| `email_payloads_tmp` | `shop_subscription` | 暫存: 延遲發信後刪除。FIFO 佇列，payload 內含兩個內部欄位 `_pp_send_attempts`（重試計數）與 `_pp_sent_email_keys`（已寄達的 email **key**），寄信前都會從 tokens 剝掉（見常見陷阱 18） |
| `pp_site_url` | `shop_subscription` | 站台網址（含 scheme）。issue #23：網域在兩種架構下都**不存在於開站 API 回應**——PowerCloud 是 `FetchPowerCloud::site_sync()` 本地生成的 `$namespace.'.wpsite.pro'`（原本只在一次性的 `email_payloads_tmp`，寄完信就刪）、WPCD 要等 `/customer-notification` 回調帶回。兩條路徑各自寫入此 meta，`##URL##` 只讀它。**第一個站先寫、之後不覆蓋** |
| `_pp_site_sync_completed_at` | order item | issue #24 冪等鍵：此項目開站成功的 unix timestamp。**只在 HTTP 2xx 才寫入**，讓開站失敗後的合法重試不被誤擋。綁在 item 而非訂閱——WPCD 的 `pp_linked_site_ids` 要等非同步回調才寫、一張訂單多商品各開一站是合法的、且該 meta 會被後台與兩個 REST 回調改動。⚠️ 綁定站台的條件比這個**多一項**（回應要真的帶回 `websiteId`），2xx 但缺 id 時旗標照落、綁定做不了，見常見陷阱 23 |
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
| `pp_linked_site_ids_updated` | `$subscription, $new_ids, $old_ids` | `pp_linked_site_ids` **真的變更**後（四個寫入點共同收斂：PowerCloud 開站 201 / WPCD `/customer-notification` / WPCD `/link-site` / 後台手動編輯）。無變更時不 fire。**監聽者必須自吞例外**——PowerCloud 路徑是在 `site_sync_by_subscription()` 的 try/catch 內、且在 `email_payloads_tmp` 寫入之前同步呼叫，往上拋會被誤記成「網站建立失敗」並殺掉開站通知信。⛔ **不可拿來假造事件**：`Compatibility` 的一次性補排改為直接呼叫 `SubscriptionEmailHooks::backfill_subscription_emails()`，不 fire 這個 hook——否則升級時會對站上每一筆訂閱各送出一次「綁定變更了」的假事件給第三方監聽者 |

**ActionScheduler hooks（本外掛自有）**

| Hook | Args | 用途 |
|---|---|---|
| `pp_site_sync_retry_after_lock` | `subscription_id` | issue #24：搶不到開站鎖時排一次延後重試（`timeout + 60` 秒後）。真正決定要不要重開的是 item 上的冪等旗標。⛔ **去重必須用 `as_has_scheduled_action($hook, $args)`，不可用 `as_schedule_single_action()` 的 `$unique`**——AS 的 unique 判斷（`ActionScheduler_DBStore::build_where_clause_for_insert`）只比對 `hook` + `group_id`，args 完全不進 WHERE；group 留空即 `group_id = 0`，等於「全站只允許一個」，訂閱 A 等重試期間訂閱 B 的重試會被靜默丟棄（回 0 且無人檢查回傳值），B 的客戶付了錢永遠沒有站 |
| `power_partner_issue22_backfill_batch` | `page` | issue #22：一次性補排的分批處理，每批 50 筆，處理滿一批就排下一頁。handler 必須綁在 `Compatibility::__construct()` 的 early return **之前**，否則第二批之後找不到 callback |
| `power_partner_compatibility_scheduler` | — | 相容設定的非同步入口 |
| `powerhouse_delay_send_email` | `to`, `subscription_id` | PowerCloud 開站後 240 秒寄帳密；寄送失敗時也用它排 `EMAIL_RETRY_DELAY` 後的重試。**hook 名不屬於本外掛命名空間，改 args 會動到跨 plugin 合約** |

---

## Email 系統

### Email DTO 欄位

```
string $key, $enabled, $subject, $body, $action_name, $days, $operator; bool $unique
```

### action_name 值

| 值 | 發送時機 |
|---|---|
| `site_sync` | 開站完成後。**只由帶完整站台 payload 的兩條路徑寄出**：PowerCloud 走 `SiteSync::send_email()`（讀 `email_payloads_tmp`，延遲 240 秒）、WPCD 走 `/customer-notification` 回調。**不再由 `pp_site_sync_by_subscription` 排程**（issue #21：該路徑的 tokens 只有 order + subscription，拿不到站台變數，會寄出滿是 `##XXX##` 的半成品且比正確的那封先到）。`send_mail()` 的 `REQUIRED_SITE_TOKENS` 防呆若把「所有」模板都擋下（一封都沒寄成功），會另外寄一封告警到站台 `admin_email` 通知經銷商——否則客戶收不到帳密而沒有任何人知道（見常見陷阱 22） |
| `subscription_failed` | 訂閱進入 on-hold（待處理/催繳階段），寄送當下仍須為 on-hold 才會真的寄出；回到 active 或進入 cancelled/expired 時取消排程 |
| `subscription_success` | 訂閱從 on-hold（待處理）/ pending-cancel（待取消）/ cancelled / expired 恢復為 active 時觸發（**不含** pending → active 首次付款）；每次成功續訂寄一封；10 分鐘緩衝 + 寄送當下須仍為 active；離開 active 時自動取消未寄成功信（issue #16） |
| `end` | 訂閱進入 cancelled/expired（已取消/已過期），寄送當下仍須為 cancelled/expired |
| `trial_end` / `next_payment` | 訂閱里程碑時。`next_payment`（即將扣款）在訂閱進入 pending-cancel/cancelled/expired 時取消排程，且寄送當下複查狀態：pending-cancel/cancelled/expired 不寄（期末不再扣款，修復見 commit 4d3763c；註：該 commit 訊息誤引 "issue #20"，實際無對應 issue——#20 是 customer_cancelled feature） |
| `watch_trial_end` / `watch_next_payment` | 前/後 N 天（unique，設定變更時重排）。`watch_next_payment` 同 `next_payment` 的取消排程與寄送狀態複查（commit 4d3763c） |
| `watch_end` | **已停用**（v3.3.7 起 `end` 改由狀態轉換觸發，UI 從未提供此選項） |
| `customer_cancelled` | 終端客戶於「我的帳號」**自行**取消訂閱時觸發（issue #20）。收件人是**經銷商**（站台 `admin_email`，不 Bcc），非終端客戶；立即寄出（UI 鎖 days=0/after）、不 unique（每次取消都寄）、寄送當下不複查狀態（取消是歷史事實）。管理員後台取消與金流扣款失敗**不**觸發。觸發 hook 是 WCS `woocommerce_customer_changed_subscription_to_cancelled`——hook 名取自「客戶請求的狀態」（取消一律請求 cancelled），非落地狀態；落地 pending-cancel 或 cancelled 皆 fire 同一 hook，`_to_pending-cancel` 永不觸發（不綁）。客戶照舊另收 `end` 信（若有啟用），互不影響 |

**issue #22 補排**：`next_payment` / `trial_end` 兩種信的唯一排程入口是 `woocommerce_subscription_date_updated`，而新訂閱 fire 該事件時 `pp_linked_site_ids` 尚未寫入 → `schedule_email()` 的 `is_site_sync()` 守門直接 return → **新訂閱的第一個週期排不進去**（下次續訂成功時 WCS 會重算 `next_payment` 而自然補排，所以不是「永遠」）。修法是監聽 `pp_linked_site_ids_updated` 補排一次（`SubscriptionEmailHooks::backfill_subscription_emails()`，白名單 `BACKFILL_ACTIONS`）。⛔ **白名單絕不可加入 `site_sync`**——該 hook 在 PowerCloud 路徑是開站流程內同步 fire，加了會原地重現 issue #21 並擴散到 WPCD。⚠️ **也不含 `watch_next_payment` / `watch_trial_end`**：Powerhouse 的 `Times` DTO 沒有這兩個屬性，`SubscriptionEmail::get_timestamp()` 的 `isset($times->{$action_name})` 必為 false，一律落到 `time() + shift`——operator=before/days=0 時被 `$skip_if_past` 丟掉（補了也是空轉），operator=after 時則排出「從補排當下起算 N 天」的錯誤錨點。它們只出現在解綁時的清除白名單 `UNBIND_UNSCHEDULE_ACTIONS`（清除查的是 pending action，不經過 `get_timestamp()`，對 watch_* 完全有效）。補排時若「寄送時點已過」一律跳過（`schedule_email()` 的 `$skip_if_past`），避免被 `max()` 夾成「現在」而立刻寄出「N 天後將扣款」。

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

5. **`pp_create_site_responses` 的結構是 list，寫入用合併、讀取要「成功優先」** — 實際存的是 `[{status,message,data}]`。issue #23 之前 `Order.php` 讀 `[0]['data']`、`Token.php` 讀 `['data']`（少一層），後者必然取不到值。現已收斂到 `SiteSync::get_create_site_responses()` / `get_first_site_response_data()` / `extract_site_url()`，**不要再各自 `json_decode`**。寫入端是 `array_merge(既有, 本次)` 而非覆寫（多商品部分重試時才不會擠掉第一個站的紀錄），**因此讀取端必須挑「第一筆 2xx」**——否則「開站失敗 → 合法重試成功」的訂單，第 0 筆永遠是那次失敗的，後台欄位、metabox 與 `##URL##` fallback 會一直顯示錯誤資訊。全部失敗時才退回第 0 筆（錯誤內容本身是追查線索）。注意 `data` 型別在兩架構不同：PowerCloud 是 assoc array、WPCD 是 stdClass。

6. **僅首次付款觸發開站** — `SiteSync::site_sync_by_subscription()` 檢查 `count($order_ids) === 1`（僅父訂單）。續訂**不會**觸發新建站。

7. **v2→v3 相容代碼** — `Bootstrap::compatibility_settings()` 標記 `@deprecated v4`，下個大版本刪除。

8. **ActionScheduler 註冊順序** — Scheduler `::register()` 必須在任何可能觸發排程的 action 之前呼叫（Bootstrap 中已正確設定，不要重排）。

9. **PowerCloud 開站回應 201** — 成功回應碼是 HTTP 201（非 200），`SiteSync::site_sync_powercloud()` 依此判斷是否發送 Email。

10. **延遲寄信 4 分鐘** — PowerCloud 開站後透過 `as_schedule_single_action(time() + 240, ...)` 延遲 4 分鐘發送帳密 Email，暫存資料在 `email_payloads_tmp` meta。

11. **Connect.php 底部有 `new Connect()`** — 這是舊代碼，Connect 類別同時使用 SingletonTrait 和底部 `new Connect()` 初始化。

12. **PowerCloud 停用/啟用回傳 bool** — `FetchPowerCloud::disable_site()` / `enable_site()` 只有 HTTP 2xx 才回傳 `true`（issue #13）。呼叫端必須依回傳值決定訂單備註與 log 等級，不可無條件記成功。另外 PowerCloud API key 禁止 raw 落地 log，一律經 `mask_api_key()` 遮罩（len + sha256 前綴）。

13. **停用/啟用架構判斷靠 `resolve_host_type`，不靠產品欄位** — 既有站的架構 ground truth 是**連結 site id 格式**，不是產品 `power_partner_host_type`（該欄位只決定新站開哪）。`DisableSiteScheduler` / `DisableHooks` 一律**逐站**呼叫 `LinkedSites::resolve_host_type($product_host_type, $site_id)`：**純數字 site id 一律 WPCD（覆寫產品 host_type，含被誤設為 powercloud 的情形）；非純數字（UUID）才參考明確 host_type，為空時預設 PowerCloud**。禁止讓產品欄位覆寫 id 格式——舊 WPCD 站（數字 id）若被導去 PowerCloud API，`/wordpress/{id}/start|stop` 回 HTTP 400 "Validation failed (uuid is expected)"、停用/啟用靜默失敗、卡照扣（issue #18；sopro.tw 訂閱 #38621：產品被設成 powercloud 但連結站是數字 WPCD id）。`Fetch::disable_site()` / `enable_site()` 也已改為回傳 bool 並檢查 HTTP status，`partner_id` 為空時直接回 `false` 不送出。

14. **WPCD site id 是數字、PowerCloud websiteId 是 UUID（非純數字）** — `resolve_host_type` 的 `ctype_digit()` 是判斷既有站架構的**主訊號**：純數字 → 一定 WPCD（覆寫產品 host_type），其餘 → PowerCloud。此不變量由站長確認。若未來 PowerCloud 改發純數字 websiteId，此判別會失效，需改用更強訊號（如 stored create-response shape：WPCD 存 `site_id`/`server_id`、PowerCloud 存 `data.websiteId`）。


15. **計費推送異常一律只寫 log，不寄信給任何人** — `DailyBillingCron` 的所有中止／失敗路徑走 `log_alert()`（原 `notify_admin()`，已移除 `wp_mail`）。**經銷商與服務商信箱兩者都不寄**：寄給經銷商（站台 `admin_email`）沒用，每一種原因的處置——進後台改設定、找開發者、聯絡接收端管理員——都不是收一封信能解決的，排程又由 `Bootstrap.php` 無條件註冊、不問有沒有連結過帳號，模板站與 clone 站全都照跑照寄，噪音會把真正該被看見的告警淹掉；改寄服務商信箱（`info@morepower.club`，v3.5.1 加入後隨即移除）同樣沒用，換收件人沒換掉問題，處置一樣是回站上翻 log、對兩端契約。**新增任何告警路徑一律呼叫 `log_alert()`，不得再引入 `wp_mail`；要加回寄信前先回答「收信人拿到信之後，會做什麼是光看 log 做不到的」。** 代價：異常只能靠 log 或接收端的 stale 資料發現。


16. **告警 log 的 context 由 `alert_context()` 統一組出** — 內容為站台網址／partner_id／已綁定 dealer_id／業務日／原因代碼；`partner_id` 與 `dealer_id` 是與接收端對帳時要報的兩個號碼，`site_url` 讓 log 被集中收集時仍分得出是哪一台（blogname 不能用——沒改過站名的站全叫「我的網站」）。金額（`total_amount`）與站數（`site_count`）只在**算得出來**時才帶——前置中止（`no_api_key`）發生在抓網站清單之前，硬填 0 會讓讀 log 的人誤以為「今天本來就沒錢可收」。唯一不走 `log_alert()` 的是 `no_partner_id`：只留原地的 error log，因為「裝了外掛但從未連結」的站每天都會走到這裡，而這類站沒有任何錢會漏，不值得多一筆帶完整 context 的告警。

17. **開站有冪等鍵與併發鎖，動 `site_sync_by_subscription()` 前先讀懂順序** — `count($order_ids) !== 1` 只擋「續訂」，擋不住「付款完成事件重送」（重送時 related orders 仍是 1 筆）。現在有兩層：**併發鎖**（`pp_site_sync_lock_{order_id}`，裸 `INSERT IGNORE` + `try/finally`，timeout 900 秒 > API 的 600 秒）擋同一瞬間的併發；**item 層級冪等旗標**（`_pp_site_sync_completed_at`）擋事後重送。**鎖必須在三道既有守衛之後才取**——否則每次續訂事件都白搶一次鎖，且會在同一個 PHP process 留下殘鎖連鎖擋掉後續開站。`add_option()` **不能當鎖**（它是 `INSERT ... ON DUPLICATE KEY UPDATE`，重複不會失敗），`wp_cache_add()` 也不行（預設物件快取不跨 request）。 **鎖值格式是 `{timestamp}:{隨機}`**：前半判逾時，後半是持有者身分。釋放必須是條件式 `DELETE ... WHERE option_value = 我寫進去的值`，不可用 `delete_option()`——逾時接管後，原持有者（還活著、只是跑很久）的 `finally` 會把接管者的鎖刪掉，保護窗口提前消失。身分只用時間戳也不夠：秒級精度下接管者與被接管者的值可能完全相同。


18. **`email_payloads_tmp` 是 FIFO 佇列，不是單筆** — 一張訂單多商品各開一站時，兩個 `powerhouse_delay_send_email` 排程的 args 完全相同（同一個 `to` + `subscription_id`），無法區分。改成佇列前，第二站會覆蓋第一站 → 先跑的排程寄出第二站帳密並刪 meta → 第一站帳密永久遺失。`normalize_payload_queue()` 的 `array_is_list()` 分支負責相容舊格式（升級當下已排程但未執行的 action），**不可移除**。 **寄送失敗時不可原地保留不消費**：排程數量與 payload 數量是一對一的，原地不動等於燒掉一個排程而佇列沒前進，頭部持續失敗會把後面的站一起卡死（head-of-line blocking）。現在的作法是「失敗 → 計數 +1、payload 移到隊尾、排一次 `EMAIL_RETRY_DELAY` 後的重試」，連續 `EMAIL_PAYLOAD_MAX_ATTEMPTS` 次才丟棄並記 critical + 寫訂單備註（那是明文 `wp_admin_password` 唯一的存放處，丟棄前必須讓經銷商知道要手動補寄）。內部欄位 `_pp_send_attempts` 與 `_pp_sent_email_keys` 在寄信前會從 tokens 剝掉。

    ⚠️ **重試是「整份 payload」重排，所以必須跳過上一輪已寄達的模板**：站台可以設多個 `site_sync` 模板，A 寄達、B 失敗時若不跳過，客戶會收到最多 `EMAIL_PAYLOAD_MAX_ATTEMPTS` 封內容相同的帳密信。已寄達清單記在 `_pp_sent_email_keys`，經 `send_mail()` 的 `$options['skip_keys']` 傳回去。**記 email `key` 不是 `action_name`**——所有開站模板的 action_name 都是 `'site_sync'`，分不出是哪一封。

    ⚠️ **`send_mail()` 的例外必須接住**：payload 已被 `array_shift` 取出，但佇列新狀態要等函式尾端的 `save()` 才落地。例外若往上拋，meta 不改寫、不排重試，而排程已被消耗——雙站訂單會剩下一份「沒有排程會再去讀」的 payload。接住後走與 `wp_mail` 回 false 相同的計數/重排路徑。

    ⚠️ **不可因為「防呆中止是確定性失敗」就提前丟棄 payload**：它仍是 `wp_admin_password` 唯一的存放處，提前丟等於連手動補寄的原始資料都沒有。噪音（重複告警）由 `$options['notify_dealer_on_abort']` 去重解決（只在 `$attempts === 0` 時為 true），代價遠低於資料遺失。


19. **開站回應的 `data` 型別在兩架構不同** — PowerCloud 是 assoc array、WPCD 是 **stdClass**（`Fetch::site_sync()` 的 `json_decode` 沒帶 assoc）。寫入 meta 前一律經 `normalize_response_data()` 正規化，否則讀取端的 `is_array()` 對 stdClass 判 false（WPCD 訂單備註會變成空字串）。另外成功判定要用 **2xx 區間**，不是 `=== 200`——PowerCloud 成功回 201。


20. **開站回應的 `data` 是對端 API 原文，顯示層一律遮罩** — PowerCloud 的建站請求帶 `wordpress.autoInstall.adminPassword`，回應可能原樣 echo 回來。訂單備註是經銷商可見、且會出現在 WooCommerce 訂單備註 REST API 的欄位，訂單列表欄位與 metabox 同樣是顯示層。三處都必須經 `SiteSync::mask_sensitive()`（依 `SENSITIVE_KEY_PATTERNS` 的 key 關鍵字遞迴遮罩）。用關鍵字而不是允許清單——對端欄位會演進，允許清單漏一個就是洩漏，關鍵字漏一個只是多顯示一個非敏感欄位。⚠️ **只遮顯示，不遮儲存**：存進 meta 的 `data` 必須保留原文，`DisableHooks` / `DisableSiteScheduler` 的相容 fallback 要從裡面讀 `websiteId`。


21. **`is_same_site_ids()` 必須用字串比較，不可 `(int)` 正規化** — PowerCloud 的 websiteId 是 UUID，`(int)` 之後全部變成 `0`：UUID-A → UUID-B 的換綁會被判成「無變更」，`update_linked_site_ids()` 直接 `return false`，meta 不寫入（綁定靜默遺失）、`pp_linked_site_ids_updated` 也不 fire（補排與第三方監聽者全部收不到）。排序要指定 `SORT_STRING`——預設的 `SORT_REGULAR` 會把純數字字串當數值比，UUID 與數字 id 混在同一筆訂閱時排序不穩定，會讓相同集合被判成不同。數字 id 的既有行為不受影響（`'101'` 與 `101` 經 strval 後同樣是 `'101'`）。


22. **開站通知信被防呆全數擋下時要主動告警經銷商** — `send_mail()` 的 `REQUIRED_SITE_TOKENS` 防呆是對的（寧可不寄，也不要把 `##SITEPASSWORD##` 寄給終端客戶），但它把失敗模式從「客戶收到有佔位符的信」變成「客戶一封都收不到」，而 `/customer-notification` 仍回 200、CloudServer 不會重送——沒有任何人會知道。因此「有模板被擋下且一封都沒寄成功」時，直接以 `wp_mail` 寄告警到站台 `admin_email`（**不是** `DailyBillingCron` 的 `ALERT_MAIL_TO`：改模板、查回調、手動補寄都是經銷商這一側的事）。只在全滅時發，避免多模板情境的雜訊；不可再走一次 `send_mail()`，會遞迴。**重試路徑要把它關掉**（`$options['notify_dealer_on_abort'] = false`）——tokens 每輪都一樣，防呆必然再次全擋，不去重會寄出 `EMAIL_PAYLOAD_MAX_ATTEMPTS` 封相同告警把真正要看的那封淹掉。

    ⚠️ **與第 15 條「不得再引入 `wp_mail`」的關係**：第 15 條的檢驗是「收信人拿到信之後，會做什麼是光看 log 做不到的」。這一條**通過**該檢驗，所以是刻意保留的例外——經銷商收到信後的動作明確、唯一且有時效性：**立刻手動補寄帳密給正在等的客戶**（明文 `wp_admin_password` 只存在於訂單備註），而經銷商沒有理由、也不會每天去翻 log。計費告警通不過，是因為它的處置（回站上翻 log、對兩端契約）本來就從 log 開始，換個收件匣並沒有換掉問題。⛔ 之後**新增任何告警路徑前，一律先回答同一個問題**；答不出來就只寫 `log_alert()`。


23. **開站 2xx 不等於綁定成功——綁定多一個 `websiteId` 條件** — `site_sync_powercloud()` 只在 `$response_obj->data['websiteId']` 非空時才寫 `pp_linked_site_ids`，但 `pp_site_url`、開站通知信排程、以及呼叫端的冪等旗標全都只看 HTTP 2xx。`FetchPowerCloud::site_sync()` 的 `data` 是 `json_decode($body, true)`，空 body / 非 JSON / 對端改欄位名都會讓它變成 `null`；而成功判定已從 `=== 201` 放寬為 2xx（200 / 202 也會進來）。缺 id 時 `is_site_sync()` 為 false → 停用/恢復、所有生命週期信、issue #22 補排全部**靜默**失效。**旗標仍然照落是刻意的**：放行重試會讓 PowerCloud 再建一個站（計費、資源、客戶收到第二組帳密都不可逆），而缺的只是一個 id——經銷商可從 PowerCloud 後台以 `namespace` 查出 websiteId 手動補綁。代價不對稱，所以選「可修復但需人工」。⛔ 但**絕不可靜默通過**：該分支必須寫 critical log + 訂單/訂閱備註（含 namespace），否則沒有任何人會知道。


24. **`update_linked_site_ids()` 收到的是「完整清單」，四個寫入點一律附加語義** — 傳 `[$new_site_id]` 等於宣告「這個訂閱只有這一個站」。`/customer-notification` 原本就是這樣傳，一張訂單兩個商品各開一站時，第二次回調會擠掉站 1：站 1 從此不會被停用/恢復（錢照扣），而 `pp_site_url` 是「第一個站先寫、之後不覆蓋」，於是 `##URL##` 指向站 1、綁定卻只剩站 2；更糟的是 `pp_linked_site_ids_updated` 會 fire，把這次靜默遺失當成一次正常變更通知出去。四個寫入點（PowerCloud 開站、`/customer-notification`、`/link-site`、後台手動編輯）除了後台手動編輯（本來就是全量覆寫的 UI）之外，一律「先 `get_linked_site_ids()` 再 `in_array` 去重附加」。
