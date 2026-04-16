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
- **Jotai atoms**: `identityAtom`, `globalLoadingAtom`, `tabAtom`, `powercloudAtom`
- **TanStack Query**: 所有 API 查詢和 mutations

### API 層
- `api/axios.tsx` — WP REST Axios 實例（使用 `wpApiSettings.nonce`）
- `api/cloudAxios.tsx` — cloud.luke.cafe Axios 實例
- `api/powerCloudAxios.tsx` — api.wpsite.pro Axios 實例
- `api/resources/` — CRUD helpers（create, get, update, delete）

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
| `inc/classes/Api/Fetch.php` | WPCD API 整合 |
| `inc/classes/Product/DataTabs/LinkedSites.php` | 商品設定欄位 |
| `inc/classes/Domains/Email/Core/SubscriptionEmailHooks.php` | Email 觸發點 |
| `inc/classes/Domains/Settings/Core/WatchSettingHooks.php` | 設定變更處理 |
| `js/src/pages/AdminApp/Dashboard/index.tsx` | Admin Dashboard tabs |
