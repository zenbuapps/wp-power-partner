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

    Example: 已存在排程時不重複註冊
      Given 已存在一個 hook 為 "power_partner/3.4.0/billing/daily-push" 的排程
      When 系統執行排程註冊
      Then hook 為 "power_partner/3.4.0/billing/daily-push" 的排程數量為 1

    Example: 尚無排程時註冊於 UTC+8 05:00
      Given 不存在 hook 為 "power_partner/3.4.0/billing/daily-push" 的排程
      When 系統執行排程註冊
      Then 系統以 21:00 UTC 為起始時間、間隔 1 天註冊定期排程

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

  Rule: 後置（狀態）- 網站清單的範圍即為計費集合，不做二次過濾
    # 已確認：帶經銷商 API key 呼叫 /websites 只回該 key 所屬帳號自己的站，
    # 因此回應全量即為本經銷商的計費集合，不需再用 cloud_user_id 過濾

    Example: 不以 cloud_user_id 對清單做過濾
      Given PowerCloud 回應以下網站：
        | domain       | status  | dailyCost | userId       |
        | a.wpsite.pro | running | 10.50     | cu-1111-aaaa |
        | b.wpsite.pro | running | 20.00     | cu-1111-aaaa |
      When 排程觸發計費推送
      Then 推送的網站清單共 2 筆

  Rule: 後置（狀態）- 只有 status 為 running 的網站列入計費
    # creating / stopped / deleting 一律不計費，與舊架構「停用不計費」語義一致

    Scenario Outline: 網站狀態與是否列入推送清單
      Given PowerCloud 回應以下網站：
        | domain       | status   | dailyCost | userId       |
        | a.wpsite.pro | <status> | 10.50     | cu-1111-aaaa |
      When 排程觸發計費推送
      Then 推送清單中 status 為 running 的網站數為 <billable>

      Examples: 四種狀態
        | status   | billable | 說明   |
        | running  | 1        | 計費   |
        | creating | 0        | 不計費 |
        | stopped  | 0        | 不計費 |
        | deleting | 0        | 不計費 |

  Rule: 後置（狀態）- cloud_user_id 取自網站清單的 userId 欄位，且為推送的必要欄位
    # 接收端以 cloud_user_id 做 TOFU 身分綁定（首次收到即綁定，後續比對，不符則拒絕扣點），
    # 因此它不是稽核用的選填欄位 —— 取不到就不能推送，否則接收端必定拒絕。

    Example: 從網站清單取得 cloud_user_id
      Given PowerCloud 回應以下網站：
        | domain       | status  | dailyCost | userId       |
        | a.wpsite.pro | running | 10.50     | cu-1111-aaaa |
        | b.wpsite.pro | stopped | 99.00     | cu-1111-aaaa |
      When 排程觸發計費推送
      Then 推送的 cloud_user_id 為 "cu-1111-aaaa"

  Rule: 後置（狀態）- payload 帶 billing_date，為推送當下的 UTC+8 日期（YYYY-MM-DD）
    # 業務日在發送端一次決定，重試時沿用同一個 billing_date 不重新計算，
    # 避免排程延遲跨越午夜時歸屬漂移，造成同一天扣兩次、隔天不扣

    Example: billing_date 為觸發當下的 UTC+8 日期
      Given 系統當前 UTC+8 時間為 "2026-08-19 05:00:00"
      When 排程觸發計費推送
      Then 推送的 billing_date 為 "2026-08-19"

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
        | domain       | status  | dailyCost | userId       |
        | a.wpsite.pro | running | 10.50     | cu-1111-aaaa |
        | b.wpsite.pro | running | 20.00     | cu-1111-aaaa |
        | c.wpsite.pro | running | 0.25      | cu-1111-aaaa |
        | d.wpsite.pro | stopped | 99.00     | cu-1111-aaaa |
      And CloudServer 回應 HTTP 200
      When 排程觸發計費推送
      Then 記錄 info log，計費站數為 3，總金額為 30.75

  # ========== 錯誤處理 ==========

  Rule: 錯誤處理 - 無法取得 cloud_user_id 時中止推送並寫 error log
    # 例如清單中所有站台皆無 userId 欄位。不得以空字串送出 ——
    # 接收端以 cloud_user_id 做 TOFU 綁定比對，空值必定被拒絕扣點。

    Example: 所有站台皆無 userId 時不推送
      Given PowerCloud 回應的網站皆無 userId 欄位
      When 排程觸發計費推送
      Then 系統沒有推送至 CloudServer
      And 記錄 error log

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
        | domain       | status  | dailyCost   | userId       |
        | a.wpsite.pro | running | 10.50       | cu-1111-aaaa |
        | b.wpsite.pro | running | <dailyCost> | cu-1111-aaaa |
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

  # ========== 邊界條件 ==========

  Rule: 邊界條件 - 網站清單為空時跳過本日推送，不送出空 payload

    Example: 清單為空時不推送
      Given PowerCloud 的 /websites 回應 total 為 0
      When 排程觸發計費推送
      Then 系統沒有推送至 CloudServer
      And 記錄 info log

  Rule: 邊界條件 - 網站清單出現多個相異 userId 時視為異常，中止推送並寫告警 log
    # 正常情況下同一把 API key 底下所有站應屬同一個 userId。
    # 若出現多個，代表該 key 的權限範圍超出預期（例如管理員層級 key），
    # 此時推送會把不屬於本經銷商的站算到他頭上，必須中止而非靜默取第一筆

    Example: 出現兩個相異 userId 時中止推送
      Given PowerCloud 回應以下網站：
        | domain       | status  | dailyCost | userId       |
        | a.wpsite.pro | running | 10.50     | cu-1111-aaaa |
        | b.wpsite.pro | running | 20.00     | cu-9999-zzzz |
      When 排程觸發計費推送
      Then 系統沒有推送至 CloudServer
      And 記錄告警 log

  Rule: 邊界條件 - 全部網站都不是 running 時仍需推送，且金額為 0
    # 讓接收端寫下當日 log 與冪等鍵，維持每日對帳的連續性

    Example: 全部非 running 仍推送
      Given PowerCloud 回應以下網站：
        | domain       | status   | dailyCost | userId       |
        | a.wpsite.pro | creating | 10.50     | cu-1111-aaaa |
        | b.wpsite.pro | stopped  | 20.00     | cu-1111-aaaa |
        | c.wpsite.pro | deleting | 0.25      | cu-1111-aaaa |
      When 排程觸發計費推送
      Then 系統推送至 CloudServer
      And 推送清單的總金額為 0.00
