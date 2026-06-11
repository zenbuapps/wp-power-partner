# Power Partner — 讓每個人都可以輕鬆地販售網站模板

> **Version:** 3.2.4 | **Requires:** PHP 8.1+, WP 5.7+, WooCommerce 7.6+, Woo Subscriptions 5.9+, Powerhouse 3.3.23+

Power Partner is a WordPress plugin that lets you **sell WordPress website templates as WooCommerce subscription products**. After installation and connecting your `cloud.luke.cafe` partner account, your WooCommerce subscription products can be linked to template sites. When a customer completes their first subscription payment, a WordPress site is automatically provisioned from the template and the credentials are emailed directly to the customer.

---

## Features

- **Automatic Site Provisioning** — On subscription creation, clones a template site for the customer (supports both WPCD legacy and PowerCloud new architecture)
- **Dual Hosting Backend** — `powercloud` (default, via `api.wpsite.pro`) and `wpcd` (legacy, via `cloud.luke.cafe`) can be selected per product
- **Subscription Lifecycle Automation** — Automatically suspends provisioned sites when a subscription fails; re-enables when it recovers
- **Configurable Email Templates** — Rich HTML email system with `##TOKEN##` substitution (customer name, site URL, credentials, etc.) triggered at configurable lifecycle points
- **License Code Management** — Integrates with `cloud.luke.cafe` to create, expire, and recover plugin license codes tied to subscriptions
- **Admin Dashboard** — React SPA (Ant Design) with tabs for: site list, email config, manual provisioning, settings, license codes, PowerCloud auth
- **Customer Site List** — Frontend shortcode `[power_partner_current_user_site_list]` shows a customer's provisioned sites
- **WooCommerce Integration** — Custom columns on orders and subscriptions, meta boxes, and product data tabs for configuration

---

## Requirements

| Dependency | Minimum Version |
|---|---|
| PHP | 8.1 |
| WordPress | 5.7 |
| WooCommerce | 7.6.0 |
| Woo Subscriptions | 5.9.0 |
| Powerhouse (zenbuapps) | 3.3.23 |

---

## Installation

1. Install and activate the required plugins (WooCommerce, Woo Subscriptions, Powerhouse).
2. Upload or install the `power-partner` plugin and activate it.
3. Go to **Powerhouse → Power Partner** in wp-admin.
4. Log in with your `cloud.luke.cafe` partner credentials to connect your account.
5. Go to a subscription product, open the **General** tab, and configure:
   - **Host Type** — `powercloud` (new) or `wpcd` (legacy)
   - **Host Region** — JP / TW / US West / etc.
   - **Template Site** — select from your allowed templates
   - **Plan** — (PowerCloud only) select an infrastructure plan
6. Set up email templates under the **Email 設定** tab.
7. Configure **Settings** (e.g. how many days before suspending a site after subscription failure).

---

## Development Setup

### Requirements
- Node.js + pnpm
- PHP 8.1+ with Composer
- A local WordPress install with WooCommerce and Woo Subscriptions

### Install Dependencies

```bash
# PHP dependencies
composer install

# Install JS dependencies
pnpm install
```

### Development

```bash
# Start Vite dev server (port 5176)
pnpm dev

# Production build → js/dist/
pnpm build

# WP-specific production build
pnpm build:wp
```

### Code Quality

```bash
# PHP lint
composer lint          # phpcs

# JS lint
pnpm lint

# Format JS
pnpm format
```

### Release

```bash
pnpm sync:version      # Sync version: package.json → plugin.php
pnpm release:patch     # Bump patch, build, create GitHub release
pnpm release:minor     # Bump minor version
pnpm release:major     # Bump major version
pnpm zip               # Create distributable ZIP
```

---

## Architecture Overview

```
plugin.php                      # Plugin class, required plugins, activate hook
inc/classes/
├── Bootstrap.php               # Singleton orchestrator — wires all classes
├── Order.php                   # Admin order columns + metabox (site-sync status)
├── ShopSubscription.php        # Subscription meta helpers (linked_site_ids)
├── Shortcode.php               # [power_partner_current_user_site_list]
├── Admin/Menu/Setting.php      # Admin page HTML mount point
├── Api/
│   ├── Main.php                # Core REST endpoints
│   ├── Connect.php             # Partner ID / account-info endpoints
│   ├── Fetch.php               # Abstract: WPCD API (legacy)
│   ├── FetchPowerCloud.php     # Abstract: PowerCloud API (new)
│   └── User.php                # Customer search endpoints
├── Domains/
│   ├── Email/                  # Email DTO, scheduling, hooks
│   ├── Site/                   # Site disable/enable scheduling
│   ├── LC/                     # License code lifecycle
│   └── Settings/               # Watches settings changes, reschedules
├── Product/
│   ├── SiteSync.php            # Fires on INITIAL_PAYMENT_COMPLETE
│   └── DataTabs/
│       ├── LinkedSites.php     # Product fields: host, template, plan
│       └── LinkedLC.php        # Product fields: linked license products
└── Utils/
    ├── Base.php                # Env-based API auth, constants
    └── Token.php               # ##TOKEN## replacement

js/src/
├── main.tsx                    # Mounts App1 + App2
├── App1.tsx                    # Admin app (Ant Design)
├── App2.tsx                    # Frontend app (Shadow DOM)
└── pages/
    ├── AdminApp/               # Dashboard + Login
    └── UserApp/                # Customer site list
```

### Subscription Lifecycle

```
Customer purchases subscription
        ↓
INITIAL_PAYMENT_COMPLETE
        ↓
SiteSync::site_sync_by_subscription()
        ↓
[PowerCloud] FetchPowerCloud::site_sync()   OR   [WPCD] Fetch::site_sync()
        ↓
Site provisioned → pp_site_sync_by_subscription fired
        ↓
Email scheduled → sent 4 min later via ActionScheduler

--- Later ---

Subscription FAILS (active → cancelled/on-hold)
        ↓
DisableHooks: schedule site disable in N days
LC\LifeCycle: schedule license code expiry in 4h

Subscription RECOVERS (failed → active)
        ↓
DisableHooks: cancel disable schedule, re-enable all sites
LC\LifeCycle: cancel expiry, recover license codes via API
```

---

## Key Concepts

### Subscription "Failure" Definition

> Active → Cancelled / On-Hold / Pending-Cancel = **FAILED**  
> Active → Expired = **NOT failed** (natural end of subscription)

Use the `Action` enum from Powerhouse for all subscription lifecycle hooks:
```php
use J7\Powerhouse\Domains\Subscription\Shared\Enums\Action;
\add_action(Action::SUBSCRIPTION_FAILED->get_action_hook(), [$this, 'my_callback'], 10, 2);
```

### Linked Site IDs

Each subscription stores the IDs of its provisioned sites as **multi-value post meta** under the key `pp_linked_site_ids`. Always use helper methods:
```php
ShopSubscription::get_linked_site_ids($subscription_id);   // read
ShopSubscription::update_linked_site_ids($id, $ids);       // write
ShopSubscription::change_linked_site_ids($id, $ids);       // atomic move
```

### Email Tokens

Email subject and body support `##TOKEN##` substitution:
- `##FIRST_NAME##`, `##ADMINURL##`, `##SITEPASSWORD##`, `##IPV4##`, `##ORDER_ID##`, etc.
- See `Utils\Token::replace()` and `Utils\Token::get_order_tokens()` for the full list.

---

## Shortcode

```
[power_partner_current_user_site_list]
```

Renders a React app (App2) showing the current logged-in customer's provisioned sites and license codes. Uses Shadow DOM for style isolation.

---

## REST API (Summary)

**Namespace:** `/wp-json/power-partner/`

| Route | Method | Auth | Purpose |
|---|---|---|---|
| `/customer-notification` | POST | IP whitelist | Email customer site credentials (WPCD callback) |
| `/link-site` | POST | IP whitelist | Link site ID to subscription |
| `/settings` | POST | admin | Save plugin settings |
| `/emails` | GET/POST | admin | Get/save email templates |
| `/manual-site-sync` | POST | admin | Manually provision a site |
| `/partner-id` | GET/POST/DELETE | public/admin | Manage partner connection |
| `/subscriptions` | GET | admin | List user subscriptions |
| `/change-subscription` | POST | admin | Reassign site to subscription |
| `/powercloud-api-key` | POST | admin | Store PowerCloud API key |
| `/apps` | GET | public | Get subscription IDs for site IDs |

---

## WordPress Options

| Option | Description |
|---|---|
| `power_partner_settings` | `{power_partner_disable_site_after_n_days, emails[]}` |
| `power_partner_partner_id` | cloud.luke.cafe partner ID |
| `power_partner_account_info` | Encrypted account data |

## WordPress Transients

| Transient | TTL | Description |
|---|---|---|
| `power_partner_allowed_template_options` | 7 days | WPCD template list |
| `power_partner_allowed_template_options_powercloud` | 7 days | PowerCloud template list |
| `power_partner_open_site_plan_options_powercloud` | 7 days | PowerCloud plan list |
| `power_partner_powercloud_api_key_{user_id}` | 30 days | PowerCloud API key per user |

---

## Subscription Status Logic

| Status | Meaning |
|---|---|
| `wc-active` | 已啟用 — site is live |
| `wc-cancelled` | 已取消 — triggers site disable schedule |
| `wc-on-hold` | 保留 — triggers site disable schedule |
| `wc-pending-cancel` | 待取消 — triggers site disable schedule |
| `wc-expired` | 已過期 — natural end, does NOT trigger site disable |

---

## Changelog

### Unreleased

#### fix(email): 修正「客戶續訂成功後」信從未寄出的問題（issue #16）

**問題根源**：`subscription_success` 信原本綁定 Powerhouse `SUBSCRIPTION_SUCCESS` hook，但 stock WCS 不允許 cancelled/expired → active 的狀態轉換，導致此 hook 在正常流程中從未觸發，此信件型別自上線以來實際上從未寄出。

**行為變更**：

- 訂閱從 on-hold（待處理）/ pending-cancel（待取消）等狀態恢復為 active 時觸發成功信排程，**每次成功續訂都會寄一封**（符合 UI 標籤「續訂成功後 N 天發送」語意）
- pending → active（首次付款啟用）**不觸發**成功信，避免與開站信重疊
- 防線機制：最少延遲 10 分鐘 + 寄送當下狀態複查（須仍為 active）+ 離開 active 時自動取消未寄成功信
- 反向對稱取消：催繳信與成功信互相取消，防止 WCS 自動續訂 active → on-hold → active 震盪期間誤寄或兩封同時寄出

**站長注意**：已勾選「客戶續訂成功後」Email 模板的站長，此為行為改變（過去靜默無作用，現在會實際寄出）。如不需要可至 **Powerhouse → Power Partner → Email 設定** 停用該模板。

---

## Contributing

All PHP must pass PHPCS (WPCS rules) and PHPStan (configured in `phpstan.neon`).  
All JS/TS must pass ESLint before committing.
