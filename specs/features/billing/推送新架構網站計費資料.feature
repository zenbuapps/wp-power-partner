@ignore @command
Feature: 推送新架構網站計費資料（PushPowerCloudBillingData）

  每日 UTC+8 05:00 由 ActionScheduler 觸發，向 PowerCloud 取得本經銷商帳號下的網站全量清單，
  過濾出計費對象後，推送給 cloud.luke.cafe 由其扣點。

  ## 背景：為什麼需要這條流程
  接收端（power-partner-server）既有的每日扣點只掃描本地 `wpcd_app` post（舊架構 WPCD 站）。
  新架構（PowerCloud）的站存在 api.wpsite.pro，cloud.luke.cafe 沒有對應 post，
  因此完全不在計費集合內 —— 新架構開站至今從未計費。本流程負責把計費資料送過去。

  ## 定價權威（重要，勿誤判為 bug）
  新舊架構的定價權威**不對稱**，這是刻意決策：
  - 舊架構：接收端自行計算（地區費率 × 經銷商等級折扣，另有 Special enum 覆寫）
  - 新架構：由本流程帶 `dailyCost` 過去，接收端**直接採用，不再套等級折扣**
  理由：`dailyCost` 已是 PowerCloud 對該站算出的最終金額，再乘折扣等於雙重打折。

  Background:
    Given 系統已設定 PowerCloud API URL 為 "https://api.wpsite.pro"
    And 系統已設定 CloudServer API URL 為 "https://cloud.luke.cafe"
    And option "power_partner_partner_id" 為 "174"
    And transient "power_partner_powercloud_api_key" 為 "pk_test_123"
    And 系統當前 UTC+8 時間為 "2026-08-19 05:00:00"

  # ========== 前置（參數）==========

  Rule: 前置（參數）- 本流程由排程觸發，不接受外部參數
    # 重試時沿用首次觸發決定的 billing_date，不重新計算

    Example: 重試時沿用首次觸發的 billing_date
      Given 首次觸發時決定的 billing_date 為 "2026-08-19"
      And 首次推送失敗
      When 排程於 "2026-08-20 00:10:00" 重試
      Then 推送的 billing_date 仍為 "2026-08-19"

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 排程於每日 UTC+8 05:00 觸發，且同時間只允許一個排程存在
    # 註冊前需以 as_next_scheduled_action() 守衛，避免重複註冊產生多次推送
    # 採 wall-clock（cron）排程而非 interval 排程：interval 以「實際執行時間 + 24h」推算下一次，
    # 佇列延遲會單向累積漂移，漂到跨越 UTC+8 午夜之後就會整天不計費

    Example: 已存在排程時不重複註冊
      Given 已存在一個 hook 為 "power_partner/3.4.0/billing/daily-push" 的排程
      When 系統執行排程註冊
      Then hook 為 "power_partner/3.4.0/billing/daily-push" 的排程數量為 1

    Example: 尚無排程時註冊於 UTC+8 05:00
      Given 不存在 hook 為 "power_partner/3.4.0/billing/daily-push" 的排程
      When 系統執行排程註冊
      Then 系統以 21:00 UTC 為起始時間、cron 運算式 "0 21 * * *" 註冊定期排程

    Example: 既有的 interval 排程遷移為 wall-clock 排程
      Given 已存在一個以固定間隔 1 天註冊的 "power_partner/3.4.0/billing/daily-push" 排程
      When 系統執行排程註冊
      Then 該排程被換成 cron 運算式 "0 21 * * *" 的排程
      And hook 為 "power_partner/3.4.0/billing/daily-push" 的排程數量為 1
      And 排程時刻仍為 21:00 UTC

  Rule: 前置（狀態）- 外掛啟用或版本升級後立刻排一次首推
    # 接收端以 Trust On First Use 綁定身分：首次收到某 partner_id 的推送時，才把 payload 的
    # dealer_id 存為該經銷商的綁定值，之後必須相符才受理。
    # 若等到隔日 UTC+8 05:00 才首推，功能發布當天全體經銷商都處於「未綁定」狀態，
    # 會出現一個橫跨所有經銷商、最長 24 小時的搶綁窗口 —— 而 partner_id 可由未認證的
    # GET /partner-id 讀出、Basic Auth 帳密隨外掛散布，攻擊門檻極低。
    # 立即排一次可把窗口從 24 小時壓到約 1 分鐘。

    Example: 版本變更後排定首推
      Given 尚未針對目前外掛版本排過首推
      When 系統載入外掛
      Then 系統排程 60 秒後推送一次
      And 該排程的 retried 為 0（失敗仍走既有重試流程）
      And 該排程的 billing_date 為目前排程 slot 對應的業務日

    Example: 同一版本只排一次首推
      Given 已針對目前外掛版本排過首推
      When 系統再次載入外掛
      Then 首推排程數量仍為 1

  Rule: 前置（狀態）- PowerCloud API Key 不存在時中止推送
    # 不得以空 key 呼叫 API，避免產生無意義的 401 與誤判為「該經銷商沒有站」

    Example: API Key 不存在時不呼叫任何 API
      Given transient "power_partner_powercloud_api_key" 不存在
      When 排程觸發計費推送
      Then 系統沒有呼叫 PowerCloud API
      And 系統沒有推送至 CloudServer
      And 記錄 error log

  Rule: 前置（狀態）- partner_id 未設定時中止推送
    # 接收端靠 partner_id 辨識扣點對象，缺少時推送必然失敗，不送出

    Example: partner_id 為空時不推送
      Given option "power_partner_partner_id" 為空
      When 排程觸發計費推送
      Then 系統沒有推送至 CloudServer
      And 記錄 error log

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 取得 PowerCloud 網站清單時必須分頁拉完全量
    # 回應為 { data: IWebsite[], total: number }，須依 total 確認已取完，不可只取第一頁

    Example: 總數超過單頁上限時續拉後續分頁
      Given PowerCloud 的 /websites 回應 total 為 300，每頁上限為 250
      When 排程觸發計費推送
      Then 系統呼叫 /websites 共 2 次
      And 推送的網站清單共 300 筆

    Example: 總數未超過單頁上限時只拉一次
      Given PowerCloud 的 /websites 回應 total 為 3，每頁上限為 250
      When 排程觸發計費推送
      Then 系統呼叫 /websites 共 1 次

  Rule: 後置（狀態）- total 只從第 1 頁取，第 1 頁缺 total 視為取得失敗
    # 「只在第 1 頁給 total」是很常見的對端實作。若每頁重讀 total，
    # 第 2 頁缺值就會 fallback 成該頁筆數（250），累計 500 >= 250 → 判定取完 → break，
    # 1000 站只送 500 站，而且函式回報成功、沒有任何 error log。少送站 = 少收錢，且完全靜默。
    # 另以「回傳筆數不足單頁上限即為最後一頁」與 total 互為佐證。

    Example: 只有第 1 頁帶 total 時仍拉完全量
      Given PowerCloud 的 /websites 第 1 頁回應 total 為 1000
      And 第 2、3、4 頁的回應不含 total 欄位
      When 系統取得網站清單
      Then 系統呼叫 /websites 共 4 次
      And 取得的網站清單共 1000 筆

    Example: 第 1 頁缺 total 時視為取得失敗
      Given PowerCloud 的 /websites 第 1 頁回應不含 total 欄位
      When 系統取得網站清單
      Then 取得網站清單回傳 null
      And 記錄 error log

    Example: 未取滿 total 卻已無後續分頁時視為清單不完整
      Given PowerCloud 的 /websites 第 1 頁回應 total 為 1000
      And 第 2 頁只回 10 筆（不足單頁上限 250）
      When 系統取得網站清單
      Then 取得網站清單回傳 null
      And 記錄 error log

  Rule: 後置（狀態）- 分頁結果必須以 id 去重，沒有帶來新資料的分頁視為停滯
    # 這是唯一會「向客戶多收錢」的失敗模式，優先於其他分頁問題。
    # 對端若因改版／快取層／WAF 剝掉 query string 而忽略 page 參數，每頁都回同一批 250 筆，
    # 只看累計筆數的話 total = 1000 會跑 4 圈把同一批站累加 4 次 →
    # payload 把同 250 個 domain 各送 4 次 → 接收端逐筆加總 → 該經銷商被多扣 4 倍。
    # WEBSITES_MAX_PAGES 只擋無限迴圈，擋不了重複資料。
    # 去重鍵取 id；id 缺漏時退回整筆資料的雜湊 —— 寧可用較弱的鍵，也不能不去重。

    Example: 對端忽略 page 參數時視為取得失敗
      Given PowerCloud 的 /websites 第 1 頁回應 total 為 1000、資料 250 筆
      And 後續每一頁都回同一批 250 筆
      When 系統取得網站清單
      Then 取得網站清單回傳 null
      And 記錄 error log

    Example: 分頁區間重疊時以 id 去重
      Given PowerCloud 的 /websites 第 1 頁回第 0-249 筆、total 為 450
      And 第 2 頁回第 200-449 筆（與第 1 頁重疊 50 筆）
      When 系統取得網站清單
      Then 取得的網站清單共 450 筆
      And 清單中不含重複的 id

  Rule: 後置（狀態）- 網站清單的範圍即為計費集合，不做二次過濾
    # 已確認：帶經銷商 API key 呼叫 /websites 只回該 key 所屬帳號自己的站，
    # 因此回應全量即為本經銷商的計費集合，不需再用 dealer_id 過濾

    Example: 不以 dealer_id 對清單做過濾
      Given PowerCloud 回應以下網站：
        | domain       | status  | dailyCost | user.dealerId |
        | a.wpsite.pro | running | 10.50     | 181f2bbe-…   |
        | b.wpsite.pro | running | 20.00     | 181f2bbe-…   |
      When 排程觸發計費推送
      Then 推送的網站清單共 2 筆

  Rule: 後置（狀態）- 只有 status 為 running 的網站列入計費
    # creating / stopped / deleting 一律不計費，與舊架構「停用不計費」語義一致

    Scenario Outline: 網站狀態與是否列入推送清單
      Given PowerCloud 回應以下網站：
        | domain       | status   | dailyCost | user.dealerId |
        | a.wpsite.pro | <status> | 10.50     | 181f2bbe-…   |
      When 排程觸發計費推送
      Then 推送清單中 status 為 running 的網站數為 <billable>

      Examples: 四種狀態
        | status   | billable | 說明   |
        | running  | 1        | 計費   |
        | creating | 0        | 不計費 |
        | stopped  | 0        | 不計費 |
        | deleting | 0        | 不計費 |

  Rule: 後置（狀態）- dealer_id 取自網站清單的 user.dealerId 欄位，且為推送的必要欄位
    # 接收端以 dealer_id 做 TOFU 身分綁定（首次收到即綁定，後續比對，不符則拒絕扣點），
    # 因此它不是稽核用的選填欄位 —— 取不到就不能推送，否則接收端必定拒絕。
    #
    # ## 為什麼是 user.dealerId，不是 userId 或 user.id（重要，勿改回去）
    # PowerCloud 的帳號是三層階層：
    #
    #   經銷商（dealer）  dealerId: 181f2bbe-1292-459a-a814-0baa72423636  ← 計費／綁定對象
    #     └─ 開站用戶      user.id:  e77dcfa2-0687-49a5-a54a-50f721fef8bd  ← 只是操作者
    #          └─ 網站      vibrant-panda-34812
    #
    # 真實 GET /websites 的單筆長這樣（站長 2026-08-20 提供，total: 358）：
    #   {
    #     "id": "58c46391-…", "primaryDomain": "vibrant-panda-34812.wpsite.pro",
    #     "status": "running", "dailyCost": "7.67", "dailyCostDate": "2026-08-20",
    #     "userId": "e77dcfa2-…",
    #     "user": { "id": "e77dcfa2-…", "role": "dealer", "dealerId": "181f2bbe-…", "email": "…" }
    #   }
    #
    # 一個經銷商底下可以有多個開站用戶，因此 userId / user.id 在同一批回應中本來就會出現
    # 多個相異值。取那兩個欄位當識別，會讓下面「多個相異 dealerId 視為異常」的守衛每天
    # 誤觸發、整天一行都推不出去，同時 TOFU 綁定也會綁到錯的 id。
    #
    # user 的型別是 { … } | null，取值時不得假設它是物件。刻意不做 fallback ——
    # 解析不出經銷商 id 時寧可中止並通知，也不要猜一個 id 去扣別人的點。

    Example: 從網站清單取得 dealer_id
      Given PowerCloud 回應以下網站：
        | domain       | status  | dailyCost | user.id     | user.dealerId |
        | a.wpsite.pro | running | 10.50     | e77dcfa2-… | 181f2bbe-…   |
        | b.wpsite.pro | stopped | 99.00     | e77dcfa2-… | 181f2bbe-…   |
      When 排程觸發計費推送
      Then 推送的 dealer_id 為 "181f2bbe-1292-459a-a814-0baa72423636"
      And 推送的 dealer_id 不是 "e77dcfa2-0687-49a5-a54a-50f721fef8bd"

  Rule: 後置（狀態）- 首次推送成功後把 dealer_id 存為本地綁定值
    # 接收端的 TOFU 綁定只存在對端；本地留一份對照，讓「PowerCloud 帳號被換掉」或
    # 「本地狀態異常」在送出前就被擋下，而不是送出後才被對端以 403 拒絕

    Example: 首推成功後記錄綁定值
      Given option "power_partner_billing_dealer_id" 不存在
      And PowerCloud 回應的網站 user.dealerId 皆為 "181f2bbe-1292-459a-a814-0baa72423636"
      When 排程觸發計費推送並成功
      Then option "power_partner_billing_dealer_id" 為 "181f2bbe-1292-459a-a814-0baa72423636"

  Rule: 後置（狀態）- payload 帶 billing_date，取自最近一次排程 slot（21:00 UTC）對應的 UTC+8 日期
    # 業務日在發送端一次決定，重試時沿用同一個 billing_date 不重新計算，
    # 避免排程延遲跨越午夜時歸屬漂移，造成同一天扣兩次、隔天不扣
    #
    # 刻意由「最近一次 21:00 UTC 排程時刻」回推，而非由執行當下 time() 推導：
    # 21:00 UTC 距 UTC+8 午夜只有 19 小時緩衝，佇列積壓一旦吃掉這段緩衝，
    # 由 time() 推導的 billing_date 會直接跳到隔天 —— 該業務日的冪等鍵被隔天的推送佔用，
    # 那一天就永遠收不到錢，而且不會有任何錯誤訊號

    Example: billing_date 為觸發當下的 UTC+8 日期
      Given 系統當前 UTC+8 時間為 "2026-08-19 05:00:00"
      When 排程觸發計費推送
      Then 推送的 billing_date 為 "2026-08-19"

    Scenario Outline: 執行時刻與業務日的對應
      Given 排程 slot 為每日 21:00 UTC
      When 排程於 "<執行時刻>" 執行
      Then 推送的 billing_date 為 "<billing_date>"

      Examples: slot 歸屬
        | 執行時刻                  | billing_date | 說明                              |
        | 2026-08-19 21:00:00 UTC | 2026-08-20   | 準時觸發                           |
        | 2026-08-19 20:59:59 UTC | 2026-08-19   | slot 前一秒仍屬上一個業務日            |
        | 2026-08-20 16:30:00 UTC | 2026-08-20   | 延遲 19.5 小時仍歸屬原本的業務日        |
        | 2026-08-20 21:00:00 UTC | 2026-08-21   | 下一個 slot 才推進                  |

  Rule: 後置（事件）- 實際執行時間與排程 slot 落差超過門檻時寫 error log
    # 漂移逼近 24 小時後會開始整天漏推，必須在漏推之前就看得見。
    # 只在定期排程（不帶 billing_date 參數）的路徑檢查 —— 重試與首推本來就不在 slot 上

    Example: 漂移超過門檻寫 error log
      Given 排程 slot 為 "2026-08-19 21:00:00 UTC"
      When 排程延遲至 "2026-08-20 16:30:00 UTC" 才執行
      Then 記錄 error log，內容含漂移分鐘數

  Rule: 後置（狀態）- payload 每筆網站帶 domain、status、dailyCost
    # domain 取值優先序沿用既有前端慣例：primaryDomain > domain > subDomain > wildcardDomain

    Scenario Outline: domain 取值優先序
      Given PowerCloud 回應的網站欄位為：
        | primaryDomain   | domain   | subDomain   | wildcardDomain   |
        | <primaryDomain> | <domain> | <subDomain> | <wildcardDomain> |
      When 排程觸發計費推送
      Then 推送該站的 domain 為 "<expected>"

      Examples: 優先序
        | primaryDomain | domain | subDomain | wildcardDomain | expected | 說明             |
        | p.wpsite.pro  | d.pro  | s.pro     | w.pro          | p.wpsite.pro | primaryDomain 最優先 |
        |               | d.pro  | s.pro     | w.pro          | d.pro    | 退到 domain       |
        |               |        | s.pro     | w.pro          | s.pro    | 退到 subDomain    |
        |               |        |           | w.pro          | w.pro    | 退到 wildcardDomain |

  Rule: 後置（狀態）- 以 Basic Auth 推送至 CloudServer
    # 沿用既有 Fetch::disable_site() 的認證慣例，不另造機制

    Example: 推送時帶 Basic Auth header
      When 排程觸發計費推送
      Then 系統以 POST 呼叫 "https://cloud.luke.cafe/wp-json/power-partner-server/v2/powercloud-daily-billing"
      And 請求帶 Authorization 為 Basic 認證

  # ========== 後置（事件）==========

  Rule: 後置（事件）- 推送成功（HTTP 2xx）寫入 info log，含推送站數與總金額

    Example: 推送成功寫 info log
      Given PowerCloud 回應以下網站：
        | domain       | status  | dailyCost | user.dealerId |
        | a.wpsite.pro | running | 10.50     | 181f2bbe-…   |
        | b.wpsite.pro | running | 20.00     | 181f2bbe-…   |
        | c.wpsite.pro | running | 0.25      | 181f2bbe-…   |
        | d.wpsite.pro | stopped | 99.00     | 181f2bbe-…   |
      And CloudServer 回應 HTTP 200
      When 排程觸發計費推送
      Then 記錄 info log，計費站數為 3，總金額為 30.75

  # ========== 錯誤處理 ==========

  Rule: 錯誤處理 - 無法取得 dealer_id 時中止推送並寫 error log
    # 例如清單中所有站台的 user 皆為 null，或有 user 但缺 dealerId 欄位。
    # 不得以空字串送出 —— 接收端以 dealer_id 做 TOFU 綁定比對，空值必定被拒絕扣點。
    # 頂層 userId 存不存在不影響判定：它不是識別值。

    Scenario Outline: 解析不出 dealerId 時不推送
      Given PowerCloud 回應的網站皆為 "<user 形狀>"（頂層 userId 仍存在）
      When 排程觸發計費推送
      Then 系統沒有推送至 CloudServer
      And 記錄 error log

      Examples: 兩種取不到 dealerId 的形狀（API 型別為 user: { … } | null）
        | user 形狀        | 說明                    |
        | user 為 null     | 回應沒有帶 user 物件      |
        | user 缺 dealerId | 有 user 但少了 dealerId  |

  Rule: 錯誤處理 - 設定類的中止路徑一律寄信通知站台管理員
    # 這四條路徑不會自行復原、也不進重試流程，只寫 log 等於沒人知道；
    # 漏推一天等於少收一天錢。
    # 其中 no_api_key 最嚴重：排程情境沒有登入者，只讀得到全域 key，
    # 因此「只存了舊版 per-user key」的站台從第一天起就永遠中止且無人知情。

    Scenario Outline: 中止原因與通知內容
      Given 中止原因為 "<reason>"
      When 排程觸發計費推送
      Then 系統沒有推送至 CloudServer
      And 寄出通知信給站台 admin_email
      And 信件內容含 "<關鍵字>"

      Examples: 四條設定類中止路徑
        | reason              | 關鍵字     | 說明                                       |
        | no_partner_id       | partner_id | 請重新連結 cloud.luke.cafe                  |
        | no_api_key          | 新架構權限   | 請到「新架構權限」tab 重新認證以寫入全域 key       |
        | no_dealer_id        | dealer_id  | 疑似 /websites 回應欄位改版                  |
        | multiple_dealer_ids | 權限        | API key 權限範圍異常，權限模型可能已變更          |

  Rule: 錯誤處理 - dealer_id 與本地綁定值不符時中止推送並通知管理員
    # 代表 PowerCloud 帳號被換，或本地狀態異常。接收端同樣不會自動換綁，
    # 硬推只會被拒絕，因此直接中止；也不得以新值覆寫本地綁定

    Example: 綁定值不符時中止
      Given option "power_partner_billing_dealer_id" 為 "00000000-old0-old0-old0-000000000000"
      And PowerCloud 回應的網站 user.dealerId 皆為 "181f2bbe-1292-459a-a814-0baa72423636"
      When 排程觸發計費推送
      Then 系統沒有推送至 CloudServer
      And 記錄 error log
      And 寄出通知信給站台 admin_email
      And 系統沒有排程下次重試
      And option "power_partner_billing_dealer_id" 仍為 "00000000-old0-old0-old0-000000000000"

  Rule: 錯誤處理 - 永久性錯誤一律立即通知管理員，不進入一般重試
    # 判定依據是接收端回應 body 的 data.error_code，**不是 message 文案**。
    #
    # ## 為什麼不比對文案（重要，勿改回去）
    # 原本的實作比對 message 是否含「cloud_user_id」。接收端隨 dealer_id 改名把文案換成
    # 「dealer_id 與已綁定值不符」之後，這條判斷就整個失效了 —— 文案不是契約的一部分，
    # 改一次字就靜默壞掉，而壞掉的後果是永久性錯誤被當成暫時性失敗白重試 90 分鐘才通知人。
    #
    # 身分綁定不符（identity_mismatch）額外要求 **HTTP 403 與 error_code 兩者皆成立**；
    # 其餘代碼只認 error_code。
    #
    # 取不到 error_code 時**維持既有行為**（一般 push_failed → 進重試流程）：
    # 中介 WAF／反向代理擋下的 403 不會帶這個 JSON 結構，不得被誤判成永久性錯誤而完全不重試。
    # 反過來，不在清單內的代碼（例如限流 rate_limited）也一律走重試，不得因為「有代碼」就不重試。

    Scenario Outline: 永久性錯誤代碼的處置
      Given CloudServer 回應 HTTP <status> 且 data.error_code 為 "<error_code>"
      When 排程觸發計費推送
      Then 寄出通知信給站台 admin_email
      And 通知信內容含錯誤代碼 "<error_code>"
      And 記錄 error log
      And 系統沒有排程下次重試

      Examples: 接收端定義的永久性錯誤
        | error_code           | status | 說明                              |
        | identity_mismatch    | 403    | TOFU 綁定不符，可能有人搶先綁定       |
        | partner_not_found    | 404    | partner_id 設錯或帳號已刪除          |
        | not_a_dealer         | 500    | 該帳號不是經銷商，無法扣點            |
        | invalid_billing_date | 400    | 排程嚴重落後或主機時間不正確          |
        | missing_field        | 400    | 兩端契約版本不一致                   |

    Scenario Outline: 取不到／不認得 error_code 時維持既有重試行為
      Given CloudServer 回應 "<回應>"
      When 排程觸發計費推送
      And 結果原因為 "push_failed"
      Then 系統排程 30 分鐘後重試

      Examples: 三種不得判為永久性錯誤的回應
        | 回應                                      | 說明                              |
        | HTTP 403，文案含「dealer_id」但無 error_code | 文案不是契約，不得據以判定           |
        | HTTP 403，body 為 HTML（非 JSON）           | 中介 WAF／反向代理擋下，屬暫時性      |
        | HTTP 429，error_code 為 rate_limited       | 不在永久性清單內，屬暫時性           |

  Rule: 錯誤處理 - 取得網站清單失敗時進入重試流程，不送出不完整的 payload
    # 送出殘缺清單會導致接收端少扣點，且因冪等鍵已寫入而無法當日補扣

    Example: 第二頁取得失敗時不送出僅含第一頁的 payload
      Given PowerCloud 的 /websites 回應 total 為 300
      And 第 2 頁請求回應 HTTP 500
      When 排程觸發計費推送
      Then 系統沒有推送至 CloudServer
      And 系統排程 30 分鐘後重試

  Rule: 錯誤處理 - 推送失敗時最多重試 3 次、間隔 30 分鐘
    # 3 次 × 30 分鐘最晚於 UTC+8 06:30 完成，仍在同一業務日內，不會污染接收端的冪等鍵

    Scenario Outline: 重試次數與排程時間
      Given 已重試 <retried> 次
      And CloudServer 回應 HTTP 500
      When 排程觸發計費推送
      Then 系統排程下次重試的行為為 "<behavior>"

      Examples: 重試序列
        | retried | behavior              | 說明                     |
        | 0       | 排程 30 分鐘後重試      | 首次失敗，第 1 次重試     |
        | 1       | 排程 30 分鐘後重試      | 第 2 次重試              |
        | 2       | 排程 30 分鐘後重試      | 第 3 次重試              |
        | 3       | 不再重試並寄信通知      | 已達上限                 |

  Rule: 錯誤處理 - 重試次數達上限仍失敗時，寄信通知站台管理員並寫 error log
    # 漏推一天等於少收一天錢，必須有人看得見

    Example: 第 3 次重試仍失敗時寄信
      Given 已重試 3 次
      And CloudServer 回應 HTTP 500
      When 排程觸發計費推送
      Then 寄出通知信給站台 admin_email
      And 記錄 error log
      And 系統沒有排程下次重試

  Rule: 錯誤處理 - 網站的 dailyCost 缺值或非數值時，該站以 0 計並寫 warning log
    # dailyCost 在 API 型別中為 optional。寧可漏收也不要靜默算錯，且異常必須可見。
    # 待使用者確認：若希望改為「缺值即中止整批推送」，改動點僅此 Rule

    Scenario Outline: dailyCost 異常值處理
      Given PowerCloud 回應以下網站：
        | domain       | status  | dailyCost   | user.dealerId |
        | a.wpsite.pro | running | 10.50       | 181f2bbe-…   |
        | b.wpsite.pro | running | <dailyCost> | 181f2bbe-…   |
      When 排程觸發計費推送
      Then 推送 b.wpsite.pro 的 dailyCost 為 0.00
      And 推送清單的總金額為 10.50
      And 記錄 warning log

      Examples: 異常值
        | dailyCost  | 說明           |
        | （缺欄位）  | 欄位不存在      |
        | null       | null            |
        | abc        | 非數值字串      |

  Rule: 錯誤處理 - API Key 一律不得以原文寫入 log
    # 沿用 FetchPowerCloud::mask_api_key()（len + sha256 前綴）

    Example: log 中不出現 API Key 原文
      Given transient "power_partner_powercloud_api_key" 為 "pk_test_123"
      When 排程觸發計費推送
      Then log 內容不含 "pk_test_123"
      And log 內容含遮罩格式 "len=" 與 "sha256:"

  Rule: 錯誤處理 - /websites 的回應原文一律不得寫入 log，只記可診斷指紋
    # 該端點的回應本身帶站台憑證：adminEmail、adminPassword、databaseUsername、
    # databasePassword、databaseRootPassword（見 SiteList/types.ts）。
    # 原文寫進 wp-content/uploads/wc-logs/ 之後，對任何 manage_woocommerce 使用者
    # 都可從「WooCommerce → 狀態 → 日誌」讀到，也會一併進入站台備份。
    # 觸發不需要攻擊者 —— PowerCloud 只要把 envelope 從 {data, total} 改成裸陣列或改名，
    # 就會走進「回應格式異常」分支，把該經銷商全部網站的明文密碼寫進 log。
    # 指紋 = HTTP status + body 長度 + 頂層鍵名（只有鍵名，不含值），足以看出 envelope 改版。

    Scenario Outline: 取清單失敗時的 log 內容
      Given PowerCloud 的 /websites 回應為 "<回應>"
      When 系統取得網站清單
      Then 取得網站清單回傳 null
      And log 內容不含回應 body 原文
      And log 內容含 "body_length" 與 "body_keys"

      Examples: 兩條會記 log 的失敗分支
        | 回應                        | 說明                          |
        | HTTP 500                   | http error 分支                |
        | HTTP 200 但 envelope 為裸陣列 | 回應格式異常分支，鍵名記為 "list:N" |

  # ========== 邊界條件 ==========

  Rule: 邊界條件 - 網站清單為空時仍需推送空 sites，讓接收端把合計歸零
    # 這條看似多餘的推送有明確理由，請勿「優化」掉。
    #
    # 接收端的新架構合計 user meta 只在成功扣點時更新，且刻意設計成 stale 時沿用舊值不歸零
    #（歸零會讓大型經銷商掉回 7 天停用門檻而遭提前停用，是 fail-dangerous）。
    # 這個 fail-safe 方向正確，但它分不出兩種情況 —— 兩者在接收端看起來都是「meta 沒被更新」：
    #   1. 推送失敗（網路／API key 掛了）→ 應沿用舊值，保住 30 天緩衝
    #   2. 經銷商把新架構的站全部刪光／轉走 → meta 應更新為 0，回到 7 天門檻
    # 若清單為空就跳過推送，情況 2 會讓一個已無新架構站的經銷商被**永久**認定為大型經銷商，
    # 欠費時多拖 23 天才停用；而且不會自我修復（沒有站就永遠不推送，不推送就永遠 stale）。
    # 照常推送空 sites 之後接收端會把 meta 更新為 0，兩種情況即可區分，fail-safe 也完整保留。
    #
    # 代價：完全沒有新架構站的經銷商每天會在接收端多一筆 0 點紀錄。已評估可接受 ——
    # 正確的停用判定優先於 log 量。

    Example: 清單為空時推送空 sites
      Given PowerCloud 的 /websites 回應 total 為 0
      And option "power_partner_billing_dealer_id" 為 "181f2bbe-1292-459a-a814-0baa72423636"
      When 排程觸發計費推送
      Then 系統推送至 CloudServer
      And 推送的 sites 為空陣列
      And 推送的 dealer_id 為 "181f2bbe-1292-459a-a814-0baa72423636"

    Example: 清單為空且從未成功推送過時跳過
      Given PowerCloud 的 /websites 回應 total 為 0
      And option "power_partner_billing_dealer_id" 不存在
      When 排程觸發計費推送
      Then 系統沒有推送至 CloudServer
      And 記錄 info log
      # 沒有本地綁定值就湊不出 dealer_id（接收端要求非空字串），
      # 而且從未推送成功代表接收端也還沒有任何合計 meta 需要更新為 0

  Rule: 邊界條件 - 同一經銷商底下有多個開站用戶時照常推送
    # production 的實際樣貌：一個經銷商 358 個站分屬多個開站用戶（user.id 相異、
    # user.dealerId 相同）。識別值取 user.dealerId 才會得到單一值；若誤取 userId／user.id，
    # 下一條守衛會每天誤判為「權限範圍異常」而中止，一行都推不出去。
    # 這條與下一條互為對照，缺一不可 —— 少了這條，取值改回 userId 也不會有測試變紅。

    Example: 三個開站用戶共用一個 dealerId 時正常推送
      Given PowerCloud 回應以下網站：
        | domain       | status  | dailyCost | user.id     | user.dealerId |
        | a.wpsite.pro | running | 10.50     | e77dcfa2-… | 181f2bbe-…   |
        | b.wpsite.pro | running | 20.00     | a1b2c3d4-… | 181f2bbe-…   |
        | c.wpsite.pro | running | 0.25      | f9e8d7c6-… | 181f2bbe-…   |
      When 排程觸發計費推送
      Then 系統推送至 CloudServer
      And 推送的網站清單共 3 筆
      And 推送清單的總金額為 30.75
      And 推送的 dealer_id 為 "181f2bbe-1292-459a-a814-0baa72423636"

  Rule: 邊界條件 - 網站清單出現多個相異 dealerId 時視為異常，中止推送並寫告警 log
    # 正常情況下同一把 API key 底下所有站應屬同一個經銷商（user.dealerId 相同）。
    # 若出現多個，代表該 key 的權限範圍超出預期（例如管理員層級 key），
    # 此時推送會把不屬於本經銷商的站算到他頭上，必須中止而非靜默取第一筆。
    # 注意判定的是「經銷商」不是「開站用戶」—— 見上一條 Rule。

    Example: 出現兩個相異 dealerId 時中止推送
      Given PowerCloud 回應以下網站：
        | domain       | status  | dailyCost | user.id     | user.dealerId |
        | a.wpsite.pro | running | 10.50     | e77dcfa2-… | 181f2bbe-…   |
        | b.wpsite.pro | running | 20.00     | a1b2c3d4-… | 99999999-…   |
      When 排程觸發計費推送
      Then 系統沒有推送至 CloudServer
      And 記錄告警 log

  Rule: 邊界條件 - 可計費網站解析不出擁有者（經銷商 id）時視為異常，中止推送並通知管理員
    # 這是上一條多租戶守衛的旁路：相異 dealerId 集合刻意略過解析不出 id 的站，
    # 而計費清單完全不看 dealerId。因此當 API key 權限範圍意外放大（正是守衛要防的事）、
    # 且多出來的站 user 為 null（或缺 dealerId）時，相異 id 集合仍只有一個 → 守衛不觸發 →
    # 不屬於本經銷商的站被算進 payload 並以本經銷商身分推送，
    # 接收端只驗 TOFU 綁定的 dealer_id（相符），照扣。
    # 同一把 API key 底下出現「沒有 owner 的可計費網站」本身就是異常訊號。
    #
    # 注意：本守衛只做「中止或放行」，不得改成用 dealer_id 過濾清單 ——
    # 既有規格明確不做二次過濾（見「網站清單的範圍即為計費集合」Rule）。

    Scenario Outline: running 網站缺 owner 時中止推送
      Given PowerCloud 回應以下網站：
        | domain       | status  | dailyCost | user.dealerId |
        | a.wpsite.pro | running | 10.50     | 181f2bbe-…   |
        | b.wpsite.pro | running | 20.00     | <缺 dealerId 的形狀> |
      When 排程觸發計費推送
      Then 系統沒有推送至 CloudServer
      And 記錄 error log
      And 寄出通知信給站台 admin_email

      Examples: 兩種形狀（頂層 userId 仍存在，證明它不是識別值）
        | 缺 dealerId 的形狀 |
        | user 為 null      |
        | user 缺 dealerId  |

    Scenario Outline: 非 running 網站缺 owner 不影響推送
      Given PowerCloud 回應以下網站：
        | domain       | status  | dailyCost | user.dealerId |
        | a.wpsite.pro | running | 10.50     | 181f2bbe-…   |
        | b.wpsite.pro | stopped | 20.00     | <缺 dealerId 的形狀> |
      When 排程觸發計費推送
      Then 系統推送至 CloudServer
      And 推送清單中 status 為 running 的網站數為 1
      And 推送的 dealer_id 為 "181f2bbe-1292-459a-a814-0baa72423636"

      Examples: 兩種形狀
        | 缺 dealerId 的形狀 |
        | user 為 null      |
        | user 缺 dealerId  |

  Rule: 邊界條件 - 全部網站都不是 running 時仍需推送，且金額為 0
    # 讓接收端寫下當日 log 與冪等鍵，維持每日對帳的連續性

    Example: 全部非 running 仍推送
      Given PowerCloud 回應以下網站：
        | domain       | status   | dailyCost | user.dealerId |
        | a.wpsite.pro | creating | 10.50     | 181f2bbe-…   |
        | b.wpsite.pro | stopped  | 20.00     | 181f2bbe-…   |
        | c.wpsite.pro | deleting | 0.25      | 181f2bbe-…   |
      When 排程觸發計費推送
      Then 系統推送至 CloudServer
      And 推送清單的總金額為 0.00

  Rule: 邊界條件 - 清單非空卻篩不出任何可計費網站時，視為異常並通知管理員
    # 仍照上一條規則推送（那是刻意的），但必須留下訊號。
    # 接收端對空 sites 不特判：total = 0 照樣寫入帶冪等標記的點數紀錄 ——
    # 該 (partner_id, billing_date) 的冪等鍵就此消耗，當天之後任何正確推送都只會得到
    # already_deducted + 200，那一天永久收不到錢。
    # 觸發不需要攻擊者：PowerCloud 把狀態字串從 running 改成 Running / active，
    # 或滾動更新期間全部站標成 restarting，都會走到這裡。
    # 因此必須把本次清單出現過的相異 status 值寫進 log，狀態字典改版才看得見。
    #
    # 與「網站清單為空時仍需推送空 sites」不衝突，兩者互補，用清單筆數區分：
    #   清單筆數 = 0                → 照常推送空 sites（正常情況：經銷商真的沒站了）
    #   清單筆數 > 0 且可計費數 = 0  → 異常（狀態字典改版）：error log + 通知管理員，仍照常推送

    Example: 全部狀態都不認得時提升為 error 並通知
      Given PowerCloud 回應以下網站：
        | domain       | status     | dailyCost | user.dealerId |
        | a.wpsite.pro | Running    | 10.50     | 181f2bbe-…   |
        | b.wpsite.pro | restarting | 20.00     | 181f2bbe-…   |
      When 排程觸發計費推送
      Then 系統推送至 CloudServer
      And 推送清單的總金額為 0.00
      And 記錄 error log，內容含出現過的相異 status "Running" 與 "restarting"
      And 寄出通知信給站台 admin_email

    Example: 至少有一個可計費網站時不得誤報
      Given PowerCloud 回應以下網站：
        | domain       | status  | dailyCost | user.dealerId |
        | a.wpsite.pro | running | 10.50     | 181f2bbe-…   |
        | b.wpsite.pro | stopped | 20.00     | 181f2bbe-…   |
      When 排程觸發計費推送
      Then 系統沒有寄出通知信
