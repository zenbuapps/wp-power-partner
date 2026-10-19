---
paths:
  - "**/*.{ts,tsx,scss,json,cjs}"
---

# Power Partner — 前端與開發規則

## 前置需求

- PHP 8.1+ (extensions: `json`, `mbstring`, `openssl`)
- Composer 2.x
- Node.js 18+ 和 pnpm 10+
- WordPress + WooCommerce >= 7.6 + Woo Subscriptions >= 5.9 + Powerhouse >= 3.3.23

---

## 初始設定

```bash
composer install     # PHP 依賴
pnpm install         # JS 依賴
```

---

## 日常開發

### Vite dev server
```bash
pnpm dev
```
使用 `@kucrut/vite-for-wp`，WordPress 透過 `asset-manifest.json` 載入正確的 HMR scripts。

### 環境類型
在 `wp-config.php` 設定:
```php
define('WP_ENVIRONMENT_TYPE', 'local');   // 或 'staging'
```
控制 API endpoints（見 `Utils\Base::set_api_auth()`）。

---

## 建置

```bash
pnpm build        # 標準建置（vite.config.ts）
pnpm build:wp     # WordPress 專用建置（vite.config-for-wp.ts）
```
輸出: `js/dist/`

---

## 代碼品質

### PHP
```bash
composer lint              # PHPCS
vendor/bin/phpcbf           # 自動修正
vendor/bin/phpstan analyse  # 靜態分析
```

### JavaScript / TypeScript
```bash
pnpm lint          # ESLint
pnpm lint:fix      # ESLint + PHPCBF 自動修正
pnpm format        # Prettier
```

---

## 測試

### PHP 單元測試
```bash
vendor/bin/pest      # Pest + wp-pest
vendor/bin/phpunit   # PHPUnit
```

整合測試（`tests/Integration/`）經 wp-env（Docker）跑：

```bash
WP_ENV_CORE="WordPress/WordPress#7.1" WP_ENV_TESTS_PORT=8898 npx wp-env start
WP_ENV_CORE="WordPress/WordPress#7.1" WP_ENV_TESTS_PORT=8898 npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-partner -- vendor/bin/phpunit -c phpunit.xml.dist --filter <X> --colors=never --testdox
```
- 最新版 WooCommerce 需要 WP >= 7.0，`.wp-env.json` 釘的 6.9 會讓外掛啟用失敗、wp-env rollback；所以要帶 `WP_ENV_CORE="WordPress/WordPress#7.1"`。power-partner 測試 port 用 **8898**（預設 8896 會與 power-partner-server 撞）。**每次 `wp-env run` 都要帶同一組環境變數**
- 全 suite 一次跑會 OOM，**分檔跑**；`RestApiTest` 與 `SubscriptionEmailHooksTest` 合跑會交叉污染，各自單檔跑才準
- Cloud 相關：`CloudConnectionTest`（`CredentialStore` / `CloudClient` / `ConnectionService` / `ProxyApi` / `Migration`，用 `pre_http_request` 模擬 CloudServer，含真實 CryptoJS 舊密文 fixture）、`CredentialExposureHotfixTest`（頁面資料不含憑證、`/account-info` 與 `/customers` 權限、模板列表負面快取、`LinkedLC` 送 partner_id）
- 已知與程式無關的失敗：`DailyBillingPushTest::test_payload_carries_partner_id_and_billing_date` 在每天 UTC 16:00–21:00 必定失敗（測試用現在 +8h 比對、payload 取最近一次 21:00 UTC 排程時刻），見 CLAUDE.md 常見陷阱 37

### E2E 測試
```bash
cd tests/e2e
pnpm install
npx playwright test
```
E2E 使用 Playwright，測試分三層：
- `01-admin/` — Admin API 測試
- `02-frontend/` — 前端權限邊界
- `03-integration/` — 整合流程測試

---

## 版本管理

版本儲存在**兩處**，必須同步:
- `package.json` → `"version": "3.2.17"`
- `plugin.php` → `* Version: 3.2.17`

```bash
pnpm sync:version   # 從 package.json 同步到 plugin.php
```

---

## 發佈流程

```bash
pnpm release:patch   # 3.2.5 → 3.2.6
pnpm release:minor   # 3.2.5 → 3.3.0
pnpm release:major   # 3.2.5 → 4.0.0
pnpm zip             # 手動建立 ZIP
```

使用 `release-it`（設定在 `release/.release-it.cjs`）。

---

## 前端架構

### 雙 App 入口
- `App1` (Admin): `pages/AdminApp/` — 無 Shadow DOM, Ant Design `ConfigProvider` + `StyleProvider`
- `App2` (Frontend): `pages/UserApp/` — Shadow DOM (`react-shadow`), inline styles

### 核心依賴
| 依賴 | 用途 |
|---|---|
| `antd` + `antd-toolkit` | UI 元件庫 |
| `@refinedev/core` + `@refinedev/antd` | 資料 CRUD 框架 |
| `@tanstack/react-query` v5 | 伺服器狀態管理 |
| `jotai` | 客戶端狀態 (atoms) |
| `axios` | HTTP client |
| `react-quill` | Rich text 編輯器（Email body） |
| `react-shadow` | App2 Shadow DOM |
| `zod` | 資料驗證 |
| `@blocknote/*` | 區塊編輯器 |
| `react-router` v7 | 路由 |

### 狀態管理
- **Jotai atoms**: `identityAtom`, `connectionAtom`, `isReconnectingAtom`, `globalLoadingAtom`, `tabAtom`, `powercloudAtom`
  - `identityAtom` / `connectionAtom` 由同一份 `GET /power-partner/connection` 回應寫入（`useGetUserIdentity`），不各自寫
  - `isReconnectingAtom`：已連線時切到 Login 畫面的旗標，**不可為了「重新登入」先呼叫 `DELETE /connection`**（放棄登入會整站斷線）
- **TanStack Query**: 所有 API 查詢和 mutations。連線狀態的快取 key 是 `CONNECTION_QUERY_KEY`（`['connection']`），`useConnect` / `useDisconnect` 都是改寫這份快取

### API 層
- `api/axios.tsx` — WP REST Axios 實例（每次請求經 `applyCurrentNonce` 帶 `wpApiSettings.nonce`）
- `api/cloudAxios.tsx` — cloud.luke.cafe 的 Axios 實例，**baseURL 是本站 PHP proxy**（`cloudProxyUrl` = `{wpApiSettings.root}/power-partner/cloud`），呼叫路徑與 CloudServer 相同；雲端認證由 PHP 補上，**瀏覽器不帶任何雲端憑證**。路由與 audience 見 CLAUDE.md `/cloud/*` 表，新增呼叫前先確認 `ProxyApi::ROUTES` 有宣告，沒有的路徑會 404
- `api/nonce.tsx` — nonce 管理：`applyCurrentNonce`（request interceptor）、`retryWithFreshNonce`（response error）、`refreshNonce()`、`fetchWithNonce()`（原生 `fetch` 用）。nonce 過期（HTTP 403 + `rest_cookie_invalid_nonce`）時打 admin-ajax `action=rest-nonce` 換新、寫回 `wpApiSettings.nonce` 並**只重送一次**（`ppNonceRetried` 防迴圈）；多個請求同時過期共用同一次換新，換新有 30 秒逾時。重送失敗由重送那一輪通知並 reject，呼叫端不可再通知一次
- `api/powerCloudAxios.tsx` — api.wpsite.pro Axios 實例
- `api/resources/` — CRUD helpers（create, get, update, delete）
- **不要**再從 `window.power_partner_data.env` 讀 `t` / `cloudBaseUrl`（已移除）；不要把帳密存進 localStorage（開機時 `main.tsx` 會清掉殘留的 `power-partner-account`）

### AdminApp 連線流程
- `hooks/useGetUserIdentity` — `GET /power-partner/connection`；HTTP 200 包著 body 的 `status`：`200` 已連線 → Dashboard；`401` + `mode: 'none'` 未連線 → Login（不跳錯誤通知）
- `hooks/useConnect` — `POST /power-partner/connection {email, password}`；**成敗看 body 的 `status`**，帳密只送到本站 PHP，瀏覽器不加密、不落地
- `hooks/useDisconnect` — `DELETE /power-partner/connection`；失敗時不切回登入畫面（PHP 端連線仍在）
- `Dashboard/MigrationAlert` — `connection.mode === 'legacy' && migration === 'failed'` 時顯示「請重新登入一次」，目前功能不受影響
- 已刪除：`hooks/useAccountInfo`、`accountInfoAtom`、`utils/functions/common.tsx` 的 `encrypt` / `decrypt`（CryptoJS）。顯示 API 失敗訊息用 `getErrorMessage(error, fallback)`（同檔），它會取 WP REST / proxy 原樣轉送的上游錯誤 `message`

### TypeScript 型別
- `types/custom/` — 自訂型別
- `types/wcRestApi/` — WooCommerce REST API 型別
- `types/wcStoreApi/` — WooCommerce Store API 型別
- `types/wpRestApi/` — WordPress REST API 型別

---

## 新增功能

### 新增 React 元件/頁面
```
js/src/pages/AdminApp/Dashboard/
└── MyFeature/
    ├── index.tsx               # 主元件
    ├── hooks/                  # Feature-specific hooks
    └── types.ts                # Feature-specific types
```
在 `js/src/pages/AdminApp/Dashboard/index.tsx` 新增 tab。

### 新增 PHP Domain 功能
```
inc/classes/Domains/
└── MyDomain/
    ├── Core/
    │   ├── MyHooks.php          # WordPress hooks
    │   └── MyApi.php            # REST endpoints
    ├── DTOs/
    ├── Models/
    ├── Services/
    └── Shared/Enums/
```

---

## 除錯

### React App 不載入?
1. 檢查 browser console
2. 確認 Vite dev server 執行中
3. 確認 `js/dist/` 存在（production）或 `WP_ENVIRONMENT_TYPE=local`
4. 檢查 mount point HTML（`#power-partner-connect-app`）

### API 呼叫失敗?
1. 檢查 `wpApiSettings.nonce` 是否存在
2. 檢查 CORS headers
3. IP whitelist routes 檢查 `REMOTE_ADDR`

### 雲端（cloudAxios）呼叫失敗?
1. `409 pp_not_connected` — 本站沒有 `power_partner_partner_id`，請先在 AdminApp 登入
2. `404` — 該路徑沒在 `Cloud\Core\ProxyApi::ROUTES` 宣告（proxy 逐條註冊，沒有 catch-all）
3. `403 rest_cookie_invalid_nonce` — nonce 過期，`api/nonce.tsx` 會換新並重送一次；仍失敗代表已登出或 admin-ajax `rest-nonce` 被擋
4. `403 not_owner` — customer 操作的站台不屬於自己（`change-domain` / `toggle-ssl`）
5. `502 pp_upstream_unavailable` — 本站連不上 CloudServer，或 customer 的歸屬確認請求失敗
6. 上游 401/403 — 看 `Plugin::logger` 是否有「CloudServer 拒絕經銷商專屬密碼，改用共用帳號重送」；`power_partner_cloud_dealer_invalid` 有值代表專屬密碼已被標記失效（1 小時內走 legacy），請經銷商重新登入
7. 要確認目前走哪種模式：`CloudClient::preferred_mode()`，或 `GET /power-partner/connection` 回應的 `connection.mode`
8. 解不開憑證（`CredentialStore` log「解密失敗」/「host 不符」）— salts 被重產或站被 clone，視為未連線，重新登入即可

### PowerCloud 開站失敗?
1. 確認 `power_partner_powercloud_api_key` transient 存在
2. 請管理員在**新架構權限** tab 重新認證
3. 檢查 `power_partner` WC logs

### Email 沒寄出?
1. 確認 `power_partner_settings['emails']` 有 enabled 的 `site_sync` 模板
2. 檢查 ActionScheduler pending actions
3. 驗證 WP mail 設定

---

## 關鍵檔案對照表

| 檔案 | 修改時機 |
|---|---|
| `plugin.php` | 版本號、必要外掛版本、預設 Email body |
| `inc/classes/Bootstrap.php` | 新增 singleton 到啟動鏈 |
| `inc/classes/Utils/Base.php` | 環境設定、API endpoints |
| `inc/classes/Api/Main.php` | 核心 REST endpoints |
| `inc/classes/Api/FetchPowerCloud.php` | PowerCloud API 整合 |
| `inc/classes/Api/Fetch.php` | WPCD API 整合、`cloud_headers()` telemetry |
| `inc/classes/Domains/Cloud/Services/CloudClient.php` | CloudServer 認證模式、`CREDENTIAL_REJECTED_CODES`、fallback 規則 |
| `inc/classes/Domains/Cloud/Services/CredentialStore.php` | 專屬密碼 / legacy 帳密的加密存放、舊 CryptoJS 密文解密 |
| `inc/classes/Domains/Cloud/Services/ConnectionService.php` | 登入 / 登出 / 查身分 / `v3/connect` 與退回舊流程 |
| `inc/classes/Domains/Cloud/Core/ProxyApi.php` | 新增或調整瀏覽器可呼叫的 CloudServer 路徑與 audience |
| `inc/classes/Domains/Cloud/Core/Migration.php` | 自動遷移（AS 每日） |
| `js/src/api/nonce.tsx` | nonce 換新重送、`fetchWithNonce` |
| `inc/classes/Product/DataTabs/LinkedSites.php` | 商品設定欄位 |
| `inc/classes/Domains/Email/Core/SubscriptionEmailHooks.php` | Email 觸發點 |
| `inc/classes/Domains/Settings/Core/WatchSettingHooks.php` | 設定變更處理 |
| `js/src/pages/AdminApp/Dashboard/index.tsx` | Admin Dashboard tabs |
