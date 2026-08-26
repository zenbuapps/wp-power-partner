---
name: power-partner
description: "Power Partner WordPress plugin 開發指引。包含 WooCommerce 訂閱自動建站、PowerCloud/WPCD 後端整合、授權碼分發、雙 React App（Admin/User）架構。當修改 inc/classes/**/*.php 或 js/src/**/*.tsx 相關功能，或詢問 cloud.luke.cafe API、PowerCloud API、訂閱生命週期等問題時自動啟用。"
user-invocable: false
---

# power-partner — 開發指引

> WordPress Plugin，讓網站擁有者銷售 WordPress 網站模板（WooCommerce 訂閱制）。訂閱成立時自動透過 PowerCloud/WPCD 後端開站、發送授權碼，訂閱失敗時自動停用網站。

## When to Activate

當使用者在此專案中：
- 修改 `inc/classes/**/*.php`（開站流程、訂閱管理、授權碼邏輯）
- 修改 `js/src/**/*.tsx`（React AdminApp 或 UserApp）
- 新增開站模板、授權碼分發、Email 通知功能
- 詢問 cloud.luke.cafe API、PowerCloud API、WooCommerce Subscriptions、Jotai 狀態管理相關問題

## 開站流程

```
WooCommerce 訂閱首次付款 (INITIAL_PAYMENT_COMPLETE)
  → SiteSync::site_sync_by_subscription($subscription, $args)
  → 檢查 count($order_ids) === 1（僅父訂單）
  → 遍歷 order items，讀取商品 host_type
  → [powercloud] FetchPowerCloud::site_sync($params, $plan_id, $template_id)
      └── POST api.wpsite.pro/wordpress（X-API-Key 認證）
      └── HTTP 201 → 暫存 email_payloads_tmp
      └── as_schedule_single_action(time()+240, 'powerhouse_delay_send_email')
  → [wpcd] Fetch::site_sync($params)
      └── POST cloud.luke.cafe/wp-json/power-partner-server/site-sync（Basic Auth）
  → 儲存回應到 order item meta + order meta
  → do_action('pp_site_sync_by_subscription')
      └── 公開擴充點，PP 內部已無監聽者（issue #21 移除了 site_sync 排程）
  → LC\LifeCycle::create_lcs()（同時觸發）
      └── CloudApi::remote_post('license-codes', ...)
      └── send_email_to_subscriber()
```

## 訂閱狀態對應

| WooCommerce 訂閱狀態變化 | 判定 | 動作 |
|---|---|---|
| Active → Cancelled/On-Hold/Pending-Cancel | **FAILED** | 排程停用網站（N天後）+ 排程停用授權碼（4小時後） |
| Failed → Active | **SUCCESS** | 取消停用排程 + 重新啟用網站 + 恢復授權碼 |
| Active → Expired | **NOT failed** | 自然結束，不觸發停用 |

## 命名慣例

| 類型 | 慣例 | 範例 |
|------|------|------|
| PHP Namespace | PascalCase | `J7\PowerPartner\Domains\Email\Core` |
| PHP 類別 | `final class` + SingletonTrait | `final class SubscriptionEmailHooks` |
| PHP 常數 | UPPER_SNAKE | `LINKED_SITE_IDS_META_KEY` |
| React 元件 | PascalCase | `SiteListTable`, `EmailSetting` |
| Hook | use 前綴 | `useTable`, `useAjax`, `useChangeCustomer` |
| Text Domain | snake_case | `power_partner` |

## 常用指令

```bash
composer install           # PHP 依賴
pnpm install               # Node 依賴
pnpm dev                   # Vite 開發伺服器
pnpm build:wp              # WordPress 建置
vendor/bin/phpcs            # PHP 代碼檢查
vendor/bin/phpstan analyse  # PHPStan 靜態分析
pnpm release:patch         # 發佈 patch 版本
```
