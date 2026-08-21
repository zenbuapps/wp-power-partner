# Execution Plan — 新架構（PowerCloud）每日扣點

> AIBDD Phase 01 Discovery 產出。訪談紀錄：`specs/clarify/2026-08-19-1710.md`
>
> **跨 repo 文件**：本檔在 `power-partner` 與 `power-partner-server` 兩個 repo 各存一份，
> 內容相同，修改時請同步。

## 問題陳述

新架構（PowerCloud）開站至今從未計費。根因：接收端 `Utils\WPCD::get_app_ids()` 是寫死的 SQL，
只撈 `post_type='wpcd_app' AND app_type='wordpress-app'`；新架構的站在 api.wpsite.pro，
cloud.luke.cafe 沒有對應 post，因此完全不進 `Partner::deduct_sites_points()` 的計費迴圈。

## 職責分工

| 端 | repo | 職責 |
|---|---|---|
| 發送端 | `power-partner`（經銷商站） | 每日 UTC+8 05:00 向 PowerCloud 取得網站清單、過濾、推送 |
| 接收端 | `power-partner-server`（cloud.luke.cafe） | 接收 → 依 partner_id 扣點 → 寫 log |

## 決策定案（2026-08-19 訪談）

| # | 決策 | 結論 | 決策者 |
|---|------|------|--------|
| Q1 | 是否共用既有扣點路徑 | **獨立路徑 + 獨立 log type `cron_powercloud`**，不共用 6 小時守衛 | orchestrator |
| Q2 | `dealer_id` 來源 | 取自 `/websites` 回應的 `user.dealerId`；無站台時以本地綁定值推送空 sites | orchestrator |
| Q3 | `dailyCost` 定價權威 | **直接採用，不套經銷商等級折扣** | 使用者 |
| Q4 | 計費的 site status | **只有 `running` 計費** | 使用者 |
| Q5 | 冪等鍵 | `partner_id` + `billing_date`(UTC+8)，重複推送回 200 `already_deducted` | orchestrator |
| Q6 | 認證方式 | Basic Auth + body 帶 `partner_id`，**外加 TOFU 身分綁定**（見下） | orchestrator |
| Q7 | 推送失敗重試 | 最多 3 次、間隔 30 分鐘；仍失敗寄信給站台 admin + error log | orchestrator |
| Q8 | 點數不足時的行為 | **本期不自動停用新架構站**；通知信數字改為新舊合計 | **使用者（已裁決）** |
| Q9 | log 粒度 | 一個經銷商一天一筆，title 內含各站明細 | orchestrator |
| Q10 | 業務日期 | 發送端帶 `billing_date`，**接收端須驗證**（見下），重試時不變 | orchestrator |
| Q11 | `consecutive_negative_days` | **新架構路徑不碰**，由既有 00:30 cron 統一判定 | orchestrator |
| Q12 | 大型經銷商判定口徑 | `is_high_volume_partner()` 扣點量**納入新架構**，與通知信同一口徑 | orchestrator（Q8 衍生） |

### Q2 更正（2026-08-20）：識別值是 `user.dealerId`，不是 `userId`

訪談當下（2026-08-19）誤以為 `/websites` 的 `userId` 就是經銷商識別，欄位名也定成語義模糊的
`cloud_user_id`，實作因此合理地取了 `userId`。站長提供真實回應後確認取錯了層級。

PowerCloud 的階層是「經銷商 `dealerId` → 開站用戶 `user.id` → 網站」。同一經銷商底下有多個
開站用戶，取 `userId` 會得到多個相異值而觸發中止守衛 —— 一天都推不出去，且 TOFU 會綁到錯的 id。

定案：payload 欄位改名為 `dealer_id`，值取自 `website.user.dealerId`（`user` 型別為 `{…} | null`，
取不到一律中止並通知，不做 fallback）。詳見 `specs/features/billing/推送新架構網站計費資料.feature`
的「dealer_id 取自網站清單的 user.dealerId 欄位」Rule。

### Q6 補充：TOFU 身分綁定（必須實作，非選配）

Basic Auth 帳密由所有經銷商站共用，身分辨識實際只靠 `partner_id`（可猜的 WP user_id）。
**存在實質攻擊動機**：填入競爭對手的 `partner_id` 狂發推送 → 扣光對手點數 →
連續負數達停用門檻 → 對手服務中斷、客戶斷線，攻擊者從中得利。

Trust On First Use 綁定：
1. 首次收到某 `partner_id` 的推送時，將 `dealer_id` 寫入該用戶 meta 作為綁定值
2. 後續每次推送比對；**不符則拒絕扣點（403）+ error log + 通知管理員**
3. 換綁時由管理員手動清除該 meta

`dealer_id` 是 PowerCloud 的不公開 UUID，攻擊者須同時知道兩者。
成本僅一個 meta 加一次比對，遠低於金鑰註冊流程。該 meta 對帳與 debug 亦可用。

### Q10 補充：接收端須驗證 `billing_date`（必須實作）

`billing_date` 同時是冪等鍵，不得無條件信任 —— 否則發送端每次傳不同的隨機日期
即可繞過冪等保護重複扣點。驗證項目：

- 格式須為 `YYYY-MM-DD`，否則 400
- 不得為未來日期（以接收端 UTC+8 當日為準，允許 +1 天容差吸收時區邊界）
- 不得早於當日 7 天以上（防補扣舊帳）
- 違反時拒絕、寫 error log，不扣點

## 最關鍵的正確性風險（實作時務必保留）

既有 `Partner::can_cron()` 有「同一經銷商 6 小時內不重複扣點」守衛，查的是點數紀錄中
`type='cron'` 的最後一筆。既有 cron 跑 16:30 UTC（= UTC+8 00:30），新架構推送在
UTC+8 05:00，**相距僅 4.5 小時 < 6 小時**。

更嚴重的是該守衛開頭有 `if ('production' !== wp_get_environment_type()) return true;` ——
**守衛只在 production 生效**。若兩條路徑合併或沿用同一個 log type：

1. 本地 / staging 測起來完全正常、測試全綠
2. 上 production 後每天被靜默擋掉，一毛扣不到
3. 擋下時只寫 warning log，不拋例外、不回錯誤

這是一個**整合測試永遠測不出來、只有對帳時才會發現**的失效模式。因此 log type 隔離是
**必要條件而非偏好**，且需要一條明確模擬 production 環境的驗收情境。

> ⚠️ `can_cron()` 的 `time() > ( strtotime($date) - 2 * 60 * 60 )` 算式**是正確的**，請勿「修正」。
> 紀錄的 date 存 UTC+8 字串，`strtotime()` 以 UTC 解析後比真實時刻晚 8 小時，
> 故「真實 + 6h」等價於「`strtotime(date) - 2h`」。

## 次要正確性風險：純新架構經銷商的負數天數累計

Q11 讓 `consecutive_negative_days` 完全交給既有 00:30 cron 判定。此決策的前提**已驗證成立**：

1. `Cron::deduct_all_partners_sites_points()` 用 `Point::get_user_ids_by_partner_lvs([1, 2])`，
   遍歷**全體 LV1/LV2 經銷商**，與持有站台數無關 —— 純新架構經銷商（0 個 `wpcd_app`）仍會被掃到
2. `deduct_points_with_transaction()` 全函式**只有一個**提早 return（`if (!$this->can_cron)`），
   **沒有**「0 站就跳過」的分支，扣 0 點仍會走到負數判定

**實作階段必須驗證的殘留假設**：扣 0 點時 `$this->updated_point` 是否確實等於當前餘額
（而非 `null` / `false` / `0`）。若 `update_user_points()` 在 points 為 0 時提早返回或回傳非餘額值，
`$this->updated_point < 0` 的判定會失準，純新架構經銷商的負數天數將永遠不會累加 ——
導致其永遠不會被停用、也收不到低點數通知。

驗收情境須設計為「經銷商只有新架構站、無 WPCD 站、餘額為負」。

## Phase 02: Entity Modeling

| 操作 | 目標 | 說明 |
|------|------|------|
| modify | `Utils\Log` 類常數 | 新增 `CRON_POWERCLOUD = 'cron_powercloud'`（現有僅 `PURCHASE`/`MODIFY`/`CRON`）。**必要條件**，見上方風險說明 |
| create | user meta：TOFU 綁定值 | 例 `pp_powercloud_dealer_id`。首次推送寫入，後續比對 |
| create | user meta：新架構每日扣點量 / 計費站數 / 對應 billing_date | 供既有 CRON 計算「新舊合計」用（低點數通知、大型經銷商判定） |
| none | 自訂 log 表結構 | 沿用既有 `power_partner_server_logs`，欄位不變 |

> **冪等鍵不另建資料表**：以「查詢 log 表中 `user_id` + `type='cron_powercloud'` + `date` 落在該業務日」判定。
>
> **合計數字不從 log title 反解**：新架構的每日扣點量與站數在推送處理時直接寫入 user meta，
> 既有 CRON 讀 meta 即可。解析 log title 字串脆弱且與 log 格式耦合。

## Phase 03: BDD Analysis（補 Examples）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | `power-partner/specs/features/billing/推送新架構網站計費資料.feature` | Rules 已定，待補 Examples |
| create | `power-partner-server/specs/features/points/新架構每日扣點.feature` | Rules 已定，待補 Examples |
| modify | `power-partner-server/specs/features/points/每日自動扣點.feature` | 已加入：與新架構的關係說明（勿合併的理由）、新舊合計的 4 條 Rule、純新架構經銷商負數累計的邊界 Rule。以上皆為 Phase 01 骨架，Examples 待 Phase 03 補 |

## Phase 04: API Contract

| 操作 | 目標 | 說明 |
|------|------|------|
| create | `POST /v2/powercloud-daily-billing` | **已寫入** `power-partner-server/specs/api.yml`（契約歸 provider 持有） |
| none | `power-partner/specs/api/api.yml` | 發送端只描述自己提供的 endpoint，不描述外呼，沿用既有慣例 |

## Phase 05-07: Implementation

### 接收端 `power-partner-server`（**必須先上線**）

| 操作 | 目標 | 說明 |
|------|------|------|
| modify | `inc/classes/Utils/Log.php` | 新增 `CRON_POWERCLOUD` 常數 |
| modify | `inc/classes/Api/SiteSync.php` | 於 `register_apis()` 註冊 `v2/powercloud-daily-billing`，`permission_callback` 用 `[Auth::class, 'check_basic_auth']` |
| create | 新架構扣點處理類 | 建議獨立成類，勿塞進 `Partner::deduct_points_with_transaction()`。含 TOFU 綁定、`billing_date` 驗證、冪等檢查、扣點、寫 log、寫合計用 meta |
| modify | `Partner::collect_low_points_notice()` | 網站數 / 每日扣點改為**新舊合計**（讀新架構 meta）|
| modify | `Partner::is_high_volume_partner()` | 扣點量判定**納入新架構**。否則純新架構的大型經銷商會被算成接近 0，第 7 天就自動停用而非享有 30 天緩衝 —— 對最大的客戶造成最嚴重誤傷 |
| modify | `Cron::notify_admin_high_volume_negative_partners()` | 確認信中顯示的每日扣點 / 網站數與判定口徑一致，避免「判定為大型、數字卻近 0」的矛盾畫面 |
| create | `inc/tests/` 對應測試 | 含 production 環境語義、TOFU 拒絕、`billing_date` 越界、純新架構經銷商負數累計等情境 |

**既有介面直接使用，不另造**：

| 用途 | 介面 | 位置 |
|---|---|---|
| 扣點 | `Point::deduct_points_to_user( $user_id, $args, $points, $points_slug )` | `inc/classes/Utils/Point.php:175` |
| 寫 log | `Log::insert_user_log( $user_id, $args, $points, $points_slug )` | `inc/classes/Utils/Log.php:154` |
| 查 log（冪等檢查） | `Log::get_logs( $args )`，**須帶 `row_lock => true`** | `inc/classes/Utils/Log.php:44` |
| 點數 slug | `Point::POINT_SLUG = 'power_money'` | `inc/classes/Utils/Point.php:16` |
| 等級折扣 | `Point::get_discount_by_user_id()` — **新架構不呼叫**（Q3 定案） | `inc/classes/Utils/Point.php:55` |

> **冪等實作務必對齊 `row_lock` 並發鎖 pattern**，不可用「先查有沒有、沒有就寫入」的
> check-then-act —— 同時收到兩個推送時會雙雙通過檢查而重複扣點。

### 發送端 `power-partner`（接收端上線後才能推）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | `FetchPowerCloud::fetch_websites()` | `GET /websites?page=N&limit=250`，依回應的 `total` 分頁拉完全量 |
| create | 每日推送排程類 | 見下方排程實作建議 |
| create | 推送 client | `POST {base_url}/wp-json/power-partner-server/v2/powercloud-daily-billing`，沿用 `Fetch::disable_site()` 的 Basic Auth 組法 |
| modify | `inc/classes/Bootstrap.php` | 掛載新排程類 |
| create | `tests/Integration/` 對應測試 | 沿用既有 `pre_http_request` filter 攔截 `wp_remote_*` 的 mock pattern |

**排程實作建議**：`Powerhouse\Domains\AsSchedulerHandler\Shared\Base` 雖支援 `schedule_recurring()`，
但其 constructor 是 item-scoped（`__construct(protected $item)` + 抽象 `get_args()`），
套用在「站台層級每日推送」這種沒有自然 item 的場景很彆扭。
建議改照接收端 `Cron.php` 的既有寫法：singleton + `as_next_scheduled_action()` 守衛 +
`as_schedule_recurring_action()`，這是同一形狀問題在本專案的既有先例。

排程時間換算：UTC+8 05:00 = **21:00 UTC**（WP 已將 PHP 時區設為 UTC）。

## 實作順序（跨 repo 契約，順序不可顛倒）

1. 接收端：`Log::CRON_POWERCLOUD` 常數 + endpoint + 扣點邏輯 + 測試
2. 接收端：部署上線
3. 發送端：`fetch_websites()` + 排程 + 推送 client + 測試
4. 發送端：部署上線（此時才會開始產生推送）
5. 上線後首日對帳：確認 production 環境下確實有 `cron_powercloud` 紀錄產生

> 步驟 5 不可省略 —— 這是唯一能證明 6 小時守衛沒有擋掉新架構扣點的驗證點。

## 明確不在本次範圍

| 項目 | 理由 |
|---|---|
| 新架構站的自動停用 | **使用者已裁決本期不做**。本站無 PowerCloud 憑證，需反向由經銷商站執行，屬新的雙向協定。已知悉並接受的 trade-off：欠費經銷商的新架構站會繼續運行，站長仍須持續向 PowerCloud 支付成本。建議獨立成後續需求（回應結構可預留停用指示的擴充位置，本期不實作） |
| 舊架構扣點邏輯本身 | 完全不動，含 `can_cron()` 的時間算式。僅修改「合計口徑」相關的三個方法 |
| `consecutive_negative_days` / 停用門檻的觸發時機 | 維持既有 cron 單一權威（Q11） |
| 補扣歷史未計費的新架構站 | 使用者未提及，未自行擴大範圍 |

## 待使用者確認的假設

| # | 假設 | 若被推翻的改動範圍 |
|---|------|------------------|
| 1 | 網站 `dailyCost` 缺值或非數值時該站以 0 計並寫 warning log（寧可漏收也不靜默算錯） | 改動點僅該條錯誤處理 Rule |

> Q8 已由使用者裁決，不再是假設。Q6 的 Basic Auth 信任模型問題已由 TOFU 綁定解決，不再列為未決風險。
