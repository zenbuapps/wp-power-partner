# `pipe.yml` 結構速查

> 對應檔案：`.github/workflows/pipe.yml`
> **兩個 Job**：`claude`（釐清 → 規劃 → 實作）→ `integration-tests`（測試 → 修復 → AI 驗收 → PR）
> **專案脈絡**：Power Partner — 網站模板銷售客戶端外掛（PowerCloud / WPCD 雙後端整合）

---

## 一、觸發方式與模式對照

**觸發事件**：`issue_comment` / `pull_request_review_comment` / `pull_request_review`，body 須含 `@claude`。
**Concurrency**：同一 issue/PR 的新 `@claude` 會取消舊的。

### 關鍵字 → 模式對照

| 留言 | 開工（clarifier → tdd） | 整合測試 + AI 驗收 |
|------|------------------------|-------------------|
| `@claude`（需求還需釐清） | ❌ 僅提澄清問題 | ❌ |
| `@claude`（需求已清楚） | ✅ 由 clarifier 自動升級 pipeline 並一路跑到 tdd | ❌ 需再打 `@claude PR` |
| `@claude 開工`（含 確認/OK/沒問題/開始/go/start） | ✅ | ❌ 需再打 `@claude PR` |
| `@claude 全自動` | ✅ | ✅ 自動 |
| `@claude PR` | ❌ 跳過 | ✅ 於現有分支直接跑 |

**解析優先序**：`全自動` > `PR` > `開工等` > 互動。

---

## 二、Job 1：`claude`

**Runner** `ubuntu-latest` / **Timeout** 180 min / **Permissions**：`contents`/`pull-requests`/`issues: write`、`id-token: write`、`actions: read`

### Job Outputs

| output | 意義 |
|--------|------|
| `branch_name` / `issue_num` | 本輪 `issue/{N}-{timestamp}` 分支與 issue 編號 |
| `initial_sha` | 進入 workflow 時的 HEAD（用於偵測變更） |
| `claude_ok` | clarifier + (planner/tdd) 整體成敗；skipped 視為 OK |
| `has_changes` | 是否有 commit 或 working tree 變動 |
| `agent_name` | `clarifier` / `clarifier+planner` / `...+tdd-coordinator` / `pr-only` |
| `pipeline_mode` / `full_auto_mode` / `pr_mode` | 模式旗標 |
| `run_integration_tests` | `full_auto_mode OR pr_mode` → 控制 Job 2 觸發 |

### Steps 流程

| 段 | 核心動作 |
|----|---------|
| **A** 前置 | eyes reaction → checkout → `resolve_branch`（找或建 `issue/{N}-*`）→ HTTPS → `save_sha` |
| **B** 模式解析 | `parse_agent` 設 `PIPELINE_MODE`/`FULL_AUTO_MODE`/`PR_MODE` → `fetch_context`（issue 上下文）→ 組 clarifier prompt（`PR_MODE=true` 則跳過） |
| **C** Clarifier | `claude-retry` composite action，agent=`wp-workflows:clarifier`，`max_turns=200`(pipeline)/`120`(interactive)；`PR_MODE=true` 跳過 |
| **D** 橋接 | `detect_specs`（比對 `specs/` diff）→ `dynamic_upgrade`（interactive + 生成 specs → 升級 pipeline_mode）→ 通知留言 |
| **E** Planner | `specs_available && pipeline_mode` 才跑；agent=`wp-workflows:planner`，`max_turns=120` |
| **F** TDD | `planner_ok=true` 才跑；agent=`wp-workflows:tdd-coordinator`，`max_turns=200` |
| **G** 收尾 | `check_result` 匯整 outputs → 若有變更 `git push --force-with-lease` 兜底推送 |

---

## 三、Job 2：`integration-tests`

**依賴** `needs: claude` / **Timeout** 150 min

### 啟動條件

```yaml
run_integration_tests == 'true' &&
(
  pr_mode == 'true'                           # PR 模式旁路 claude_ok/has_changes
  OR
  (claude_ok == 'true' && has_changes == 'true')
)
```

### Steps 流程

| 段 | 核心動作 |
|----|---------|
| **H** 環境 | checkout(branch_name) → Node 20 / pnpm / composer → 建 uploads → wp-env start（3 次重試，delay 15/45/90s） |
| **I** PHPUnit 3 循環 | `test_cycle_1` 失敗 → `claude_fix_1` → `test_cycle_2` 失敗 → `claude_fix_2` → `test_cycle_3`（final，無修復）。指令：`npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-partner -- vendor/bin/phpunit -c phpunit.xml.dist --testsuite Integration`。所有步驟 `continue-on-error: true`，fix 走 `anthropics/claude-code-action@v1` |
| **J** 彙整 | `final_result` parse PHPUnit summary（`OK (...)` 或 `Tests: ...`）→ 發測試結果留言 |
| **K** AI 驗收 | `detect_smoke` 檢查 diff 有無動到 `js/src/`、`inc/classes/` → 標記 `lc_bypass_applied`（plugin.php 已內建 `'lc' => false`，無需注入）→ 建置前端（`pnpm build && pnpm build:wp`）→ Playwright 裝 chromium → `run_ai_acceptance`（agent=`wp-workflows:browser-tester`，admin slug=`power-partner`） |
| **L** 媒體 | `collect_smoke_media` 集中到 `/tmp/smoke-media` → 上傳 Bunny CDN（`ci/{branch}/smoke-test`）→ Artifact 備份 7 天 → 發 Smoke Test 報告留言（條件已修正為 `collect_smoke_media.outputs.has_media`） |
| **M** PR 守門 | `run_ai_acceptance.outcome != 'failure'` → `自動建立 PR`（gh pr create，body 含測試 badge + AI 驗收 badge + `Closes #N`）；反之發「驗收失敗不自動開 PR」通知 |

### Job Outputs

`final_result_*` 系列：`status` / `cycle` / `fix_count` / `test_total/passed/failures/errors/assertions/skipped/incomplete/warnings`

---

## 四、外部依賴資產

| 類型 | 路徑 |
|------|------|
| Composite action | `./.github/actions/claude-retry` |
| Prompt 模板 | `.github/prompts/{clarifier-pipeline,clarifier-interactive,planner,tdd-coordinator}.md` |
| 留言模板 | `.github/templates/{pipeline-upgrade-comment,test-result-comment,acceptance-comment}.md` |
| Shell script | `.github/scripts/upload-to-bunny.sh` |
| Marketplace | `https://github.com/j7-dev/wp-workflows.git`（提供 4 個 agents） |
| Secrets | `CLAUDE_CODE_OAUTH_TOKEN`、`BUNNY_STORAGE_{HOST,ZONE,PASSWORD}`、`BUNNY_CDN_URL` |

---

## 五、power-partner 特有事項（Adapt Notes）

### 5.1 `.wp-env.json` mapping
power-partner 的 `.wp-env.json` 將 `"."` 列為 plugin，wp-env 會掛到
`wp-content/plugins/power-partner`，因此 PHPUnit 指令的 `--env-cwd` 必須對齊：
```
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-partner -- vendor/bin/phpunit ...
```

### 5.2 LC Bypass 已內建（不需注入）
power-course 的範本會 `node -e` 注入一行 `'lc' => false` 到 `plugin.php`。
power-partner 的 `plugin.php` 已 hardcode 在 `init()` 陣列：
```php
$this->init( [ ..., 'lc' => false, ... ] );
```
因此 K 段「LC Bypass」整段移除，僅保留 `.e2e-progress.json` 的 `lc_bypass_applied = true` 標記。

### 5.3 PHPUnit 指令需顯式指定 config + testsuite
`composer.json` 的 `test` script 是 `--testsuite Integration`；CI 統一加上
`-c phpunit.xml.dist --testsuite Integration` 確保鎖定 dist 設定檔（避免遺留 `phpunit.xml` 干擾）。
所有 `tests/Integration/` 下符合 `*Test.php` 的測試一律執行，不再以 `<groups>` 過濾，避免新測試漏跑誤判綠燈。

### 5.4 Admin 入口
- 設定頁：`admin.php?page=power-partner`
- 商品編輯器（subscription 類型）會顯示「Power Partner 開站設定」欄位

### 5.5 雙 React App 入口
- App1（Admin）：`pages/AdminApp/`
- App2（Frontend）：`pages/UserApp/`（Shadow DOM）
變更偵測模式 `^(js/src/|inc/classes/)` 已涵蓋兩者。

---

## 六、Gotchas

1. **無 `'capability'` 行**：power-partner 沒有 LC Bypass 注入步驟（已內建），不要從 power-course 範本重複貼回。
2. **`parse_agent` 英文關鍵字太寬**：`grep -qiE '...|OK|...|go|start'` 大小寫不敏感，一般對話中的 `ok`/`go` 會誤觸。建議加字邊界或限定起始位置。
3. **Claude fix prompt 寫死在 workflow**（I 段兩處約 60 行）：可搬到 `.github/prompts/claude-fix.md`。
4. **AI 驗收 prompt 寫死 `http://localhost:8895`**：與 `tests/e2e/playwright.config.ts` 的 `baseURL` 耦合，改 port 兩處要同步。
5. **`--env-cwd` 路徑必須是 `power-partner`**：若未來 plugin 目錄改名，I 段三處與 actions 都要同步替換。

---

## 七、修改自查清單

- [ ] 新增 `env.` / `steps.<id>.outputs.` 引用，名稱是否拼對？
- [ ] 跨 job 走 `needs.<job>.outputs.`，Job 1 `outputs:` 區塊同步新增？
- [ ] Stage gating 改動時，B/D/E/F/G 五段一起看
- [ ] Prompt / 留言模板的 `{{ISSUE_NUM}}` placeholder 有對應？
- [ ] Secrets 是否在 repo settings 備齊？
- [ ] `wp-env run tests-cli --env-cwd=wp-content/plugins/power-partner` 路徑對齊？
- [ ] AI 驗收 prompt 內 `admin.php?page=power-partner` 是否正確？
