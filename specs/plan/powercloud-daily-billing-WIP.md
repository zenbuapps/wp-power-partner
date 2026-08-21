# 新架構（PowerCloud）每日扣點 — 部署指南

> **狀態：程式碼完成，兩端已 merge 進各自的 master，尚未 push、尚未部署。**
> 更新於 2026-08-21。跨 `power-partner`（發送端）與 `power-partner-server`（接收端）。
> 設計依據見同目錄 `powercloud-daily-billing-execution-plan.md`。

---

## 1. 已進 master 的內容

**power-partner（發送端，部署於各經銷商站）**

```
1cb436a  Merge branch 'feat/powercloud-daily-billing'
6c003fe  fix(billing): 經銷商識別改用 dealerId，錯誤分流改用 error_code   (+908 −271)
8d052b6  fix(billing): 資安審查 9 項修復（1 Critical、5 High、3 Medium）
1715ed6  feat(billing): 新架構每日計費資料推送端
```
測試 79 / 320 綠、phpstan 7 errors（同基準）、phpcs Domains/Billing 零違規。

**power-partner-server（接收端，cloud.luke.cafe 單站）**

```
e04868c  fix(billing): 新架構扣點改綁 dealerId，並新增 error_code 跨系統契約  (+527 −143)
5c74f2c  Merge branch 'feat/powercloud-daily-billing'
2d2b531  feat(billing): TOFU 首次綁定時留痕並通知管理員核對
4a161a9  feat(billing): 新架構每日扣點接收端
```
測試 89 / 389 綠。

---

## 2. 上線前必做（缺任一項都會有經銷商 0 扣點且平台端無訊號）

| # | 動作 | 不做的後果 |
|---|---|---|
| 1 | **先部署接收端**（cloud.luke.cafe，`e04868c`），確認 `/wp-json/power-partner-server/v2/powercloud-daily-billing` 存在 | 推送打到 404 → 判為暫時性失敗 → 重試 3 次全敗 → 每個經銷商管理員各收一封失敗信，當天全體漏扣 |
| 2 | **`npm run release` 發版 power-partner** | 經銷商站的更新檢查靠版本號。沒發版 = 沒人收到 = 排程不存在。平台端看起來像「這些經銷商沒有新架構站」，與「有站但沒推」**無法分辨** |
| 3 | **逐站確認**後台 Power Partner →「新架構權限」tab 已認證 | cron 以使用者 0 執行，只讀得到**全域** API key。沒認證過、只有舊版 per-user key、或站上 Redis/Memcached 快取被清過（key 存在 transient），該站每天 05:00 中止、永久 0 扣點，**只有他自己收得到通知信** |
| 4 | **打一次真實 API** 確認 `limit=250` 有效（見 §3） | 唯一可能造成**全體**失敗、且無法從程式碼確認的假設 |
| 5 | 準備接收首綁通知信（主旨含「身分綁定建立」） | 這是唯一的上線名冊，也是「有人搶先綁走某經銷商身分」的唯一偵測手段 |

### ⚠️ 明確**不是**前置條件：log 表 schema 升級

`LogSchema` / `details` 欄位 / 版本化 migration 屬於**還沒合併**的明細分離工作流，**不在 master**。
已驗證 master 版扣點零依賴：

```bash
git show HEAD:inc/classes/PowerCloud/DailyBilling.php \
  | grep -E "LogSchema|DETAILS_|PointLog|'details'|is_ready"   # → 零命中
```

它寫的是既有的 `wp_power_partner_server_logs` 舊 schema，該表在外掛啟用時就建好、既有 CRON 每天都在寫。
**不要因為它延後部署。**

---

## 3. 唯一的外部未知

`FetchPowerCloud::fetch_websites()` 用 `limit=250`。若 `api.wpsite.pro` 實際把單頁上限壓在 100，
程式會判定「筆數對不上 total」而回失敗（刻意的保護，寧可不推也不推殘缺清單）→ 全體每天 `fetch_failed`。

佐證偏樂觀：同一支 API 的 `/templates/wordpress`、`/website-packages` 目前 production 已在用 `limit=250` 且正常。

```bash
curl -s -H "X-API-Key: <key>" "https://api.wpsite.pro/websites?page=1&limit=250" \
  | python -c "
import sys,json
d = json.load(sys.stdin); rows = d.get('data', [])
print('本頁筆數      :', len(rows), ' ← total 358 時應回 250')
print('相異 dealerId :', len({(r.get('user') or {}).get('dealerId') for r in rows}), ' ← 必須是 1')
print('dailyCostDate :', {r.get('dailyCostDate') for r in rows})
"
```

`相異 dealerId` 若大於 1，代表該 key 跨多個經銷商，payload 設計前提要重談。

---

## 4. 跨系統契約（兩端已逐字驗證一致，上線後不可改名）

payload：`partner_id` / `dealer_id` / `billing_date` / `sites[{domain, status, dailyCost}]`

`data.error_code` — 發送端據此決定重試或立即告警：

| error_code | HTTP | 語義 | 發送端處置 |
|---|---|---|---|
| `missing_field` | 400 | 必填 key 不存在 → 多半兩端版本不一致 | 通知，不重試 |
| `invalid_field` | 400 | 欄位有帶但型別/格式不符 → 發送端組資料有誤 | 通知，不重試 |
| `invalid_billing_date` | 400 | 日期格式錯或超出允許區間 | 通知，不重試 |
| `partner_not_found` | 404 | partner_id 對應用戶不存在 | 通知，不重試 |
| `not_a_dealer` | 500 | 非初階/高階經銷商 | 通知，不重試 |
| `identity_mismatch` | 403 | TOFU 綁定不符 | 立即通知，不重試 |
| **（刻意無代碼）** | 500 | 扣點交易失敗，已回滾 | **照常重試**（DB 暫時性故障） |

**不得改回比對 message 文案。** 文案會變（改名時就改過一次），改了沒有測試抓得到。
兩端各有反向測試鎖住這條（發送端 `test_identity_mismatch_not_inferred_from_message_text` 等三條）。

---

## 5. 本次修掉的 Critical bug（供日後對照）

PowerCloud 階層：

```
經銷商（dealer）  user.dealerId   ← 身分識別，同一經銷商底下所有站一致
  └─ 開站用戶     user.id         ← 原本錯取這個
       └─ 網站
```

原實作取 `userId`。實測某帳號 358 個站分屬多個 userId，會撞上「多個相異識別值視為異常」的守衛而
**每天中止推送、一行都推不出去**。測試抓不到，因為 mock 用扁平的 `userId` 且每筆相同；真實回應是
巢狀的 `user.dealerId`，且 `dailyCost` 是**字串**（`"7.67"`）。

迴歸測試：`test_pushes_when_multiple_users_share_one_dealer`（發送端）。

---

## 6. 上線後驗證

**時程**：外掛升級後 **60 秒內**先推一次（盡早建立身分綁定），之後每天 **UTC+8 05:00**
（`as_schedule_cron_action` + wall-clock cron 運算式，**不會累積漂移**）。失敗在 +30/+60/+90 分鐘重試三次。

**A. 今天誰扣成功了**（換成當天日期與實際表前綴）

```sql
SELECT user_id, point_changed, new_balance, date, LEFT(title, 70) AS title
FROM wp_power_partner_server_logs
WHERE type = 'cron_powercloud' AND title LIKE '%[powercloud:2026-08-22]%'
ORDER BY user_id;
```

每個已上線經銷商各一列。`point_changed` 為負數，超過一千會帶千分位（`-2,745.86`）——**這是正常的**，
程式讀回時會 `str_replace(',', '')`。零列 = 全體都沒推到 → 查 §2 的第 1、2 項。

**B. 誰綁定了、快照新不新**

```sql
SELECT u.ID, u.user_email,
  MAX(CASE WHEN um.meta_key='power_partner_server_powercloud_dealer_id'    THEN um.meta_value END) AS dealer_id,
  MAX(CASE WHEN um.meta_key='power_partner_server_powercloud_billing_date' THEN um.meta_value END) AS last_billing_date,
  MAX(CASE WHEN um.meta_key='power_partner_server_powercloud_site_count'   THEN um.meta_value END) AS site_count,
  MAX(CASE WHEN um.meta_key='power_partner_server_powercloud_daily_deduct' THEN um.meta_value END) AS daily_deduct
FROM wp_users u JOIN wp_usermeta um ON um.user_id = u.ID
WHERE um.meta_key LIKE 'power_partner_server_powercloud_%'
GROUP BY u.ID, u.user_email ORDER BY u.ID;
```

`last_billing_date` 停在兩天前 = 該經銷商推送已斷。

**C. 缺席比對（程式沒做，要人工看）**

把 B 的名單與「你認知有在賣新架構站的經銷商」對照。**名單上沒有的人 = 從沒推成功過**——
這是目前偵測的唯一盲區（他在平台端零痕跡，且有「不修就不用付錢」的誘因）。上線後前兩週每天看一次。

**D. 經銷商站端**

- 排程：WooCommerce → 狀態 → 排程動作 → 搜尋 `power_partner/3.4.0/billing/daily-push`
  預期一筆 **Pending**、下次執行 21:00 UTC。查無 = 外掛沒更新到。
- 記錄：WooCommerce → 狀態 → 記錄檔 → `power_partner-YYYY-MM-DD-*.log` → 搜尋「新架構每日計費」

**E. 信箱**

| 主旨 | 意義 | 收件人 |
|---|---|---|
| 【Power Partner】新架構網站計費資料推送異常（日期） | 該站當天失敗，信中有原因代碼 | 該經銷商站管理員 |
| 經銷商 #N 身分綁定建立 | 該經銷商首次成功推送 | 平台管理員 |
| 身分綁定不符 / identity_mismatch | 有衝突，需人工介入 | 平台管理員 |

---

## 7. 已知但不擋上線

| # | 問題 | 影響 | 建議 |
|---|---|---|---|
| 1 | **純舊架構經銷商每天收誤報信** — 只賣 WPCD 站的人有 partner_id 但沒 PowerCloud key，每天收「請重新認證」 | 不漏錢，但告警疲勞 → 日後真正的 `no_api_key` 被當雜訊 | 檢查 key 前先判斷本站是否使用新架構，或加 N 天寄信去重 |
| 2 | **紀錄表無索引** — 冪等查詢 `title LIKE` + `FOR UPDATE` 會全表掃描並鎖列 | 既有 CRON 用同樣寫法跑很久了，非新增缺陷、不會扣錯錢（最壞逾時 → 重試自癒），但隨表成長惡化 | 下次 DDL 順手加：`ALTER TABLE wp_power_partner_server_logs ADD INDEX idx_pp_user_type_id (user_id, type(32), id), ALGORITHM=INPLACE;`　⚠️ `type(32)` 前綴不可省，tinytext 直接寫會 ERROR 1170 |
| 3 | **單一經銷商約 790～860 站時硬失敗** — 逐站明細塞進 title（上限 65,535 bytes），超過**不是截斷是整筆寫不進去**（`wpdb::process_fields()` 在 PHP 端就放棄 insert）→ 交易回滾 → 天天扣不到 | 目前最大 358 站（約 45%），需成長 2.2 倍才踩到 | 容量待辦：明細移出 title，只留摘要 |
| 4 | **後台「分類」欄新架構扣點顯示空白** — 兩端前端各缺一個 case | 純外觀，旁邊「說明」欄有完整中文，金額餘額全部正確 | 下次例行改版順手補，不值得為它重新 build 推版 |
| 5 | **排程停擺滿 24 小時會靜默漏掉一整個業務日** — 業務日由「執行當下所屬時段」回推，延遲 23 小時仍算對，24 小時就跳到下一天，漂移警告剛好在這區間不觸發 | 觸發前提是該站超過 24 小時完全沒有訪客請求（WP-Cron 靠流量觸發），真發生的話該站續訂扣款、通知信也一起停了 | 平台端允許 7 天內回溯，可人工補推 |
| 6 | **部署瞬間可能建立兩個排程**（`maybe_register` 沒帶 `$unique`） | 最壞同一天推兩次，接收端交易鎖冪等會回 `already_deducted`，**不會重複扣點** | 順手加參數的 hardening |

---

## 8. 待站長決定（非技術問題）

1. **上線當天會多扣一個業務日** — 部署後 60 秒首推用當天日期，隔天 05:00 正規排程用隔天日期。不是重複扣，但財務上多收一天。
2. **新架構不套經銷商等級折扣** — 舊架構 WPCD 站會套 LV1/LV2 折扣，新架構直接用 PowerCloud 的 `dailyCost`。兩種站待遇不同。
3. **硬編碼的共用 Basic Auth 憑證 + 未認證的 `/partner-id`** — `Utils\Base:52-56` 的 production 帳密隨每份外掛散布且已進 git 歷史，接收端 `check_basic_auth` 預設要求 `manage_options`。根治需輪換憑證並協調所有經銷商站。
4. **`Log.php` 的 `point_changed` / `new_balance` 以千分位寫入 tinytext** — 既有缺陷。改成 `number_format($v, 2, '.', '')` 向前相容、不需資料遷移（讀取端的 `str_replace(',', '')` 對新舊格式都正確），但會動到舊架構扣點路徑，需獨立回歸驗證。
5. **`dailyCostDate` 是否拿來當 `billing_date`** — PowerCloud 自己標記了成本歸屬日，用它可讓日期來自資料而非執行時刻。前提是同一批回應該值唯一（待 §3 確認）。

---

## 9. 同一個 repo 上的另一個工作流

兩個 repo 的工作區都還有「點數 Log 明細分離與 CSV 下載」的未完成改動
（`power-partner-server/specs/plan/log-detail-csv-execution-plan.md`），**未 commit**。

本次已驗證兩端的 commit 都不含該工作流的任何內容：

```bash
git show e04868c | grep -E "LogSchema|DETAILS_|PointLogDetail|'details'"   # → 零命中
```

唯一耦合是 `LogSchema::is_ready()`，但它不在 master（見 §2 的說明）。

---

## 10. 測試指令

**Docker 不是必要的。** 本機 MySQL 的 runner 比 wp-env 快約 26 倍：

```bash
SP="C:/Users/User/AppData/Local/Temp/claude/C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-partner/f4c9ee05-02b7-4a52-9136-70af62f4eb05/scratchpad"

# 發送端
PLUGIN="$PWD" PHPUNIT_CONFIG="phpunit.xml.dist" PPS_TEST_DB="wordpress_test_ppsend" \
  bash "$SP/run-tests-any.sh" --filter DailyBillingPushTest

# 接收端
PLUGIN="$PWD" PHPUNIT_CONFIG="phpunit.integration.xml" PPS_TEST_DB="wordpress_test_ppsrecv" \
  bash "$SP/run-tests-any.sh" --filter "PowerCloud"
```

wp-env 版本需 Docker Desktop 已啟動，容器**認 port 不認 hash**
（power-partner 8895/8898、power-partner-server 8996/8997）。

**已知測試隔離缺陷**（未修，屬明細分離工作流範圍）：`BillingTestCase::set_up()` 用 `DELETE FROM`
清 log 表，但那時已在 `WP_UnitTestCase` 的交易內、未 commit。只有「該測試會走到扣點」時清理才生效；
提早 return 的測試（400/403/404）會被 `tear_down` 的 ROLLBACK 把 DELETE 一起回滾，讓前一個測試的
log 復活。症狀 `Failed asserting that 42 is identical to 1`。
應急 `TRUNCATE TABLE wp_power_partner_server_logs;`；正解是 `clean_log_table()` 改用 `TRUNCATE`
包在 `with_real_ddl()` 內。
