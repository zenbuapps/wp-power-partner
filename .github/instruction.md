# `.github/` 目錄結構（Power Partner）

> 範本來源：`power-course/.github/`，已針對 power-partner 特性 adapt。
> 詳細範本架構規範請參閱來源專案的 `instruction.md`，本文件僅記錄本專案的差異與設定。

---

## 目錄結構

```
.github/
├── workflows/
│   ├── pipe.yml          # 核心 pipeline（claude → integration-tests）
│   ├── issue.yml         # Issue 開啟時自動展開需求（PM/DEV 模式）
│   └── release.yml       # tag push 自動建置 ZIP 並上傳到 GitHub Release
├── pipe.md               # pipe.yml 的中文規格書（放在 workflows/ 外避免 GitHub 掃描）
├── act/
│   └── test.yml          # 本機 act 多 job 結構驗證（不應用於正式 push）
├── actions/
│   └── claude-retry/
│       └── action.yml    # Claude Code Action 3 次重試（30s/60s backoff）
├── prompts/
│   ├── clarifier-interactive.md  # 互動澄清模式（≥5 題、HTML <details>）
│   ├── clarifier-pipeline.md     # 直接生成 specs（@claude 開工）
│   ├── planner.md                # 讀 specs 產實作計畫
│   ├── tdd-coordinator.md        # TDD Red/Green/Refactor 實作
│   └── claude-fix.md             # PHPUnit 失敗修復 prompt 模板（pipe.yml fix 1/2 step 載入）
├── templates/
│   ├── test-result-comment.md         # PHPUnit 結果留言
│   ├── acceptance-comment.md          # AI 驗收留言
│   └── pipeline-upgrade-comment.md    # Pipeline 升級通知
├── scripts/
│   └── upload-to-bunny.sh            # Smoke 媒體上 Bunny CDN
└── instruction.md
```

---

## 對應 power-partner 特性的 adapt 重點

| 範本來源 | power-partner 對應 |
|---------|-------------------|
| `wp-content/plugins/wp-power-course` | `wp-content/plugins/power-partner`（plugin 目錄與 wp-env 掛點） |
| `?page=power-course` (admin SPA) | `?page=power-partner`（無 HashRouter，單頁設定） |
| `inc/templates/` / `inc/assets/` | 不存在 → AI 驗收偵測模式僅 `js/src/`、`inc/classes/` |
| LC Bypass 注入 `'capability'` 後加 `'lc' => false` | **整段移除**——power-partner 的 `plugin.php` 已在 `init()` 內 hardcode `'lc' => false` |
| `vendor/bin/phpunit --testdox` | 加上 `-c phpunit.xml.dist --testsuite Integration`（對齊 composer scripts） |
| LMS / 課程描述 | 改為網站模板銷售（PowerCloud / WPCD 雙後端、Power Partner Admin SPA、UserApp Shadow DOM） |

`http://localhost:8895` port 與 `tests/e2e/playwright.config.ts` 的 `baseURL` 一致，未變動。

---

## 必要 Secrets

| Secret | 用途 |
|--------|------|
| `CLAUDE_CODE_OAUTH_TOKEN` | claude-code-action 必備 |
| `BUNNY_STORAGE_HOST` | Bunny Storage 端點 |
| `BUNNY_STORAGE_ZONE` | Bunny Storage zone |
| `BUNNY_STORAGE_PASSWORD` | Bunny Storage AccessKey |
| `BUNNY_CDN_URL` | Bunny CDN 公開 URL（給留言內截圖／影片連結用） |

`GITHUB_TOKEN` 由 GitHub Actions 預設提供，無需手動設定。

---

## 觸發指令快查（給用戶）

| 留言 | 行為 |
|------|------|
| `@claude` | 互動澄清（至少提 5 題） |
| `@claude 開工` / `確認` / `OK` / `沒問題` | clarifier → planner → tdd（不跑測試） |
| `@claude 全自動` | clarifier → planner → tdd → PHPUnit → AI 驗收 → 自動 PR |
| `@claude PR` | 略過 claude 階段，於現有分支直接跑 PHPUnit + AI 驗收 + 開 PR |

Issue 開啟時 body 含 `@claude 展開` / `@claude dev` 會走 `issue.yml` 自動展開需求。
