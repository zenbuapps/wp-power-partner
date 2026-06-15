@ignore @command
Feature: 開站編排整合測試（缺口 #1：SiteSync 開站本體）

  把「訂閱首次付款 → 自動開站 → 暫存帳密 → 排程延遲發信」整段編排補上 PHP 整合測試。
  目標 class：J7\PowerPartner\Product\SiteSync
  目標 method：site_sync_by_subscription() / site_sync_powercloud()（private，經 hook 觸發）/ send_email()
  既有 IT 引用：0（site_sync_by_subscription 僅在 ProductMetaTest 出現一行註解）。

  測試慣例（沿用既有，不引入新策略）：
  - HTTP：pre_http_request filter 攔截 wp_remote_*，回傳 {response:{code}, body} 或 WP_Error
  - 真訂閱：wcs_create_subscription + wc_create_order + add_product；skip_if_no_subscriptions() 守衛
  - 觸發：直接呼叫 (new SiteSync())->site_sync_by_subscription($subscription, []) 模擬 INITIAL_PAYMENT_COMPLETE
  - 排程斷言：as_next_scheduled_action('powerhouse_delay_send_email', $args) 取得時間戳，驗 hook + args + 時間窗
  - 測試檔落點：tests/Integration/SiteSyncOrchestrationTest.php

  Background:
    Given 系統已設定 PowerCloud API key transient "power_partner_powercloud_api_key" 為 "test-api-key-123"
    And 系統已設定 partner_id option 為 "test-partner-001"
    And 系統中有以下訂閱商品：
      | product_id | product_type | host_type  | linked_site | open_site_plan | host_position |
      | 101        | subscription | powercloud | tpl-001     | plan-001       | tw            |

  Rule: 前置（狀態）- 訂閱必須只有一筆關聯訂單（parent order），續訂不觸發開站

    Example: 訂閱有 2 筆以上關聯訂單時不執行開站
      Given 訂閱 "SUB-RENEWAL" 已有 1 筆續訂訂單（共 2 筆關聯訂單）
      And 已掛載 HTTP mock 攔截所有外部請求
      When 系統執行 site_sync_by_subscription
      Then 不發出任何開站 HTTP 請求
      And 訂單 meta "pp_create_site_responses" 不被寫入

  Rule: 前置（狀態）- 父訂單必須為 WC_Order 實例，否則記 error log 並中止

    Example: 訂閱無有效父訂單時中止且不開站
      Given 訂閱 "SUB-NOPARENT" 沒有有效的父訂單
      And 已掛載 HTTP mock
      When 系統執行 site_sync_by_subscription
      Then 不發出任何開站 HTTP 請求

  Rule: 前置（狀態）- 商品必須有設定模板站 ID（power_partner_linked_site），否則該項目跳過

    Example: 商品未設定 linked_site 時該項目不開站
      Given 訂閱 "SUB-NOTPL" 的商品未設定 power_partner_linked_site
      And 已掛載 HTTP mock
      When 系統執行 site_sync_by_subscription
      Then 不發出開站 HTTP 請求

  Rule: 前置（狀態）- 商品類型必須為 subscription 或 subscription_variation，simple 商品跳過

    Example: 訂單含 simple 商品時該項目被跳過
      Given 訂閱 "SUB-SIMPLE" 的訂單含一個 simple 類型商品
      And 已掛載 HTTP mock
      When 系統執行 site_sync_by_subscription
      Then simple 商品項目不觸發開站

  Rule: 後置（狀態）- host_type 為 powercloud 時呼叫 PowerCloud API POST /wordpress 開站

    Example: PowerCloud 開站成功時打正確的 API endpoint
      Given 訂閱 "SUB-PC" 的商品 host_type 為 "powercloud"
      And HTTP mock 回傳 HTTP 201 且 body 含 websiteId 與 wordpress 帳密欄位
      When 系統執行 site_sync_by_subscription
      Then 發出的 HTTP 請求 URL 指向 PowerCloud "/wordpress"
      And 請求帶有 X-API-Key header

  Rule: 後置（狀態）- PowerCloud 開站回傳 201 時把 websiteId 寫入 pp_linked_site_ids

    Example: 開站成功後 websiteId 綁定到訂閱
      Given 訂閱 "SUB-PCID" 的商品 host_type 為 "powercloud"
      And HTTP mock 回傳 HTTP 201 且 body 的 websiteId 為 "ws-9001"
      When 系統執行 site_sync_by_subscription
      Then 訂閱的 pp_linked_site_ids（經 ShopSubscription::get_linked_site_ids 讀取）包含 "ws-9001"

  Rule: 後置（狀態）- PowerCloud 開站回傳 201 時暫存 email_payloads_tmp 並排程 4 分鐘後發信

    Example: 開站成功後排程延遲發信且暫存帳密
      Given 訂閱 "SUB-PCMAIL" 的商品 host_type 為 "powercloud"
      And HTTP mock 回傳 HTTP 201 且 body 含 wp_admin_email "site@example.com" 與 wp_admin_password
      When 系統執行 site_sync_by_subscription
      Then 訂閱 meta "email_payloads_tmp" 被寫入且為陣列
      And 已排程一個 "powerhouse_delay_send_email" action
      And 該排程的 args 含 to "site@example.com" 與正確的 subscription_id
      And 該排程的執行時間約為當下 +240 秒（容許 ±30 秒誤差）

  Rule: 後置（狀態）- PowerCloud 開站非 201（如 400）時不暫存帳密、不排程發信

    Example: 開站回傳 400 時不排程發信
      Given 訂閱 "SUB-PC400" 的商品 host_type 為 "powercloud"
      And HTTP mock 回傳 HTTP 400
      When 系統執行 site_sync_by_subscription
      Then 訂閱 meta "email_payloads_tmp" 不存在
      And 沒有排程任何 "powerhouse_delay_send_email" action

  Rule: 後置（狀態）- host_type 為 wpcd 時呼叫 CloudServer site-sync API（關鍵差異路徑）

    Example: WPCD 開站走 CloudServer endpoint
      Given 訂閱 "SUB-WPCD" 的商品 host_type 為 "wpcd"
      And HTTP mock 回傳成功回應
      When 系統執行 site_sync_by_subscription
      Then 發出的 HTTP 請求 URL 指向 CloudServer "/wp-json/power-partner-server/site-sync"

  Rule: 後置（狀態）- 開站流程結束後觸發 pp_site_sync_by_subscription action

    Example: 開站成功後 action 被觸發
      Given 訂閱 "SUB-ACTION" 的商品 host_type 為 "powercloud"
      And HTTP mock 回傳 HTTP 201
      When 系統執行 site_sync_by_subscription
      Then did_action("pp_site_sync_by_subscription") 大於 0

  Rule: 後置（狀態）- 開站過程拋出例外時記錄「網站建立失敗」訂單備註且不中斷

    Example: 開站拋例外時寫失敗備註
      Given 訂閱 "SUB-THROW" 的開站過程會拋出例外（HTTP mock 回傳 WP_Error）
      When 系統執行 site_sync_by_subscription
      Then 訂閱備註含「網站建立失敗」字樣

  Rule: 後置（狀態）- send_email 讀取 email_payloads_tmp 發信後刪除該 meta

    Example: 延遲發信 callback 讀取暫存→發信→刪 meta
      Given 訂閱 "SUB-SEND" 的 meta "email_payloads_tmp" 已存有帳密 payload
      When 系統執行 SiteSync::send_email("site@example.com", 訂閱ID)
      Then 系統發送一封 Email 給 "site@example.com"
      And 訂閱 meta "email_payloads_tmp" 已被刪除

  Rule: 前置（狀態）- send_email 在 email_payloads_tmp 不存在時安全跳過

    Example: 暫存不存在時 send_email 不發信不報錯
      Given 訂閱 "SUB-NOSEND" 沒有 meta "email_payloads_tmp"
      When 系統執行 SiteSync::send_email("site@example.com", 訂閱ID)
      Then 不發送任何 Email
      And 不拋出例外
