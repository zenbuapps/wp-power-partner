# Execution Plan — 業務流程情境整合測試補強

> Discovery (Phase 01) 產出。本計畫的 scope 是「補三大缺口的 PHP 整合測試」，
> 不重寫既有業務規格、不動 E2E、不裝 coverage driver。
> 下游實作 agent：**test-creator**。

## 決策定案（2026-06-15 訪談）

| # | 決策 | 結論 |
|---|------|------|
| Q1 | 範圍邊界 | A — 聚焦三大真實缺口（SiteSync 開站本體 / LC 全生命週期 / DisableHooks 排程編排層）；既有已覆蓋不重造 |
| Q2 | 驗收門檻 | A — 情境清單逐條對應 test method + 全綠；不裝 pcov/xdebug、不動 CI |
| Q3 | 雙後端對等 | A — PowerCloud 等量覆蓋；WPCD 只補關鍵差異路徑（routing + 備註） |
| Q4 | 測試層級分工 | A — 內部編排邏輯放 PHP IT；E2E 不動 |
| Q5 | 延遲發信斷言 | A — 斷言 as_next_scheduled_action（hook+args+時間窗）+ 直接呼叫 send_email 驗三段 |

## 概覽

| 類型 | 數量 | 說明 |
|------|------|------|
| Create | 3 個 IT 測試檔 + 3 個 IT-coverage feature + 1 個 activity | 規格已由 Discovery 產出；test-creator 負責建測試檔 |
| Modify | 0 | 既有業務 feature / activity / 既有 IT 一律不動 |
| Delete | 0 | — |

## 缺口 → 目標 class/method → 測試檔 對照

| 缺口 | 目標 class | 目標 method | 既有 IT | 新測試檔 | 規格來源 |
|------|-----------|-------------|---------|---------|---------|
| #1 開站本體 | `Product\SiteSync` | `site_sync_by_subscription` / `site_sync_powercloud`(private, 經觸發) / `send_email` | 0（僅 ProductMeta 一行註解） | `tests/Integration/SiteSyncOrchestrationTest.php` | `features/it-coverage/開站編排整合測試.feature` |
| #2 授權碼生命週期 | `Domains\LC\Core\LifeCycle` | `create_lcs` / `subscription_failed` / `subscription_success` | 0 | `tests/Integration/LcLifeCycleTest.php` | `features/it-coverage/授權碼生命週期整合測試.feature` |
| #3 關站排程編排 | `Domains\Site\Core\DisableHooks` | `schedule_disable_site` / `cancel_disable_site_schedule` / `restart_all_stopped_sites_scheduler` | 0（action_callback 已由 SiteDisableRoutingTest 覆蓋，不重造） | `tests/Integration/DisableHooksSchedulingTest.php` | `features/it-coverage/關站排程編排整合測試.feature` |

## 不重造清單（既有已覆蓋，明確排除）

| 既有 IT | 已覆蓋範圍 | 排除理由 |
|---------|-----------|---------|
| `FetchPowerCloudTest` | `disable_site`/`enable_site` 的 2xx/401/500/WP_Error 回傳 + DisableSiteScheduler 備註（issue #13） | 停用/啟用 API 回傳值已測 |
| `SiteDisableRoutingTest` | `DisableSiteScheduler::action_callback` 路由 + WPCD 備註 + partner_id 守衛（issue #18） | 關站 callback 執行層已測 |
| `SubscriptionEmailHooksTest` (16) | Email 排程生命週期 | Email 系統已強覆蓋 |
| `RestApiTest`/`SettingsTest`/`ProductMetaTest`/`ShopSubscriptionTest`/`PluginBootstrapTest` | REST / 設定 / meta / bootstrap | 已強覆蓋 |
| 274 E2E | REST endpoint + 權限邊界 | Q4 定案 E2E 不動 |

## 共用測試慣例（三個新測試檔一致沿用，不引入新策略）

- **HTTP stub**：`pre_http_request` filter 攔截 `wp_remote_*`，回傳 `{response:{code}, body}` 或 `WP_Error`；記錄 `last_request` URL/args（沿用 FetchPowerCloudTest / SiteDisableRoutingTest 既有 mock_http pattern）
- **真訂閱**：`wcs_create_subscription` + `wc_create_order` + `add_product` + `pp_linked_site_ids` meta；`skip_if_no_subscriptions()` 守衛 WCS 可用性
- **設定**：transient `power_partner_powercloud_api_key`（`Main::POWERCLOUD_API_KEY_TRANSIENT_KEY`）+ option `Connect::PARTNER_ID_OPTION_NAME`
- **觸發**：直接呼叫目標方法（如 `(new SiteSync())->site_sync_by_subscription($sub, [])`）模擬 hook，不依賴實際 hook dispatch
- **排程斷言**：`as_next_scheduled_action($hook, $args)` 取時間戳，驗 hook + args + 時間窗（容許誤差）
- **備註斷言**：`wc_get_order_notes` + `preg_grep`
- **Email 斷言**：攔截 `wp_mail`（filter/mock）驗收件者與主旨，不實際寄出
- **基底**：全部 `extends Tests\Integration\TestCase`，沿用 `set_up`/`tear_down` 清理 transient/filter

## Phase 對應（本任務只走 IT，無 Entity/API/Frontend 變更）

### Phase 02: Entity Modeling
| 操作 | 目標 | 說明 |
|------|------|------|
| none | — | 無資料模型變更（純測試補強） |

### Phase 03: BDD Analysis
| 操作 | 目標 | 說明 |
|------|------|------|
| none | — | IT-coverage feature 已含完整 Rule+Example，無需再 derive |

### Phase 04: API Contract
| 操作 | 目標 | 說明 |
|------|------|------|
| none | — | 無新 endpoint（測的是既有內部編排，外部 API 以 mock 攔截） |

### Phase 05-08: Implementation（test-creator 執行）
| 操作 | 目標 | 說明 |
|------|------|------|
| create | `tests/Integration/SiteSyncOrchestrationTest.php` | 依「開站編排整合測試.feature」13 條 Rule 各對應 ≥1 test method |
| create | `tests/Integration/LcLifeCycleTest.php` | 依「授權碼生命週期整合測試.feature」11 條 Rule 各對應 ≥1 test method |
| create | `tests/Integration/DisableHooksSchedulingTest.php` | 依「關站排程編排整合測試.feature」11 條 Rule 各對應 ≥1 test method |

## 驗收標準（Q2=A）

1. 三個新測試檔建立完成，每條 feature Rule 至少對應一個 `test_*` method（含 `@group happy`/`@group edge`/`@group error` 標註，沿用既有慣例）
2. `vendor/bin/phpunit` 整合測試套件全綠（新測試 + 既有 141 test methods 不回歸）
3. 新測試一律 `extends Tests\Integration\TestCase`、沿用 `pre_http_request` mock pattern、含 `skip_if_no_subscriptions()` 守衛
4. 不修改任何 production code（純補測試）；若測試暴露既有 bug，回報但不在本任務修
5. 不動 E2E、不裝 coverage driver、不改 CI

## 風險與注意

- **private method 觸發**：`site_sync_powercloud` 為 private，經 `site_sync_by_subscription` 觸發測試，不直接呼叫
- **ExpireHandler 排程 hook 名稱**：LC 停用排程 hook 字串需從 `ExpireHandler` 實際常數取得（feature 暫記 "power_partner/3.1.0/lc/expire"，實作時以原始碼為準）
- **時間窗斷言容許誤差**：避免測試在 CI 上因執行延遲 flaky，時間斷言一律用範圍比對（±誤差）
- **WCS 依賴**：所有建真訂閱的測試以 `skip_if_no_subscriptions()` 守衛，WCS 未裝時自動 skip
