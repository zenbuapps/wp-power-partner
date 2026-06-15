@ignore @command
Feature: 授權碼生命週期整合測試（缺口 #2：LC LifeCycle）

  把「首次付款建立授權碼 → 訂閱失敗排程停用 → 訂閱恢復重啟」整段授權碼生命週期補上 PHP 整合測試。
  目標 class：J7\PowerPartner\Domains\LC\Core\LifeCycle
  目標 method：create_lcs() / subscription_failed() / subscription_success()
  既有 IT 引用：0（LifeCycle / create_lcs / subscription_failed / subscription_success 全無）。

  測試慣例（沿用既有，不引入新策略）：
  - HTTP：pre_http_request filter 攔截 CloudApi（Powerhouse\Api\Base）的 remote_post，回傳 license-codes JSON 或 WP_Error
  - 真訂閱：wcs_create_subscription + 父訂單 add_product；skip_if_no_subscriptions() 守衛
  - 觸發：直接呼叫 (new LifeCycle())->create_lcs($subscription, []) 等方法
  - 排程斷言：透過 ExpireHandler 的 as_* 排程查詢（hook "power_partner/3.1.0/lc/expire" 或實際常數）驗 4 小時時間窗
  - Email 斷言：以 mock_wp_mail 或攔截 wp_mail 驗收件者/主旨；不實際寄出
  - 測試檔落點：tests/Integration/LcLifeCycleTest.php

  Background:
    Given 系統已設定 partner_id option 為 "12345"
    And 系統中有以下用戶：
      | user_id | role       | email                |
      | 10      | subscriber | customer@example.com |
    And 系統中有以下訂閱商品：
      | product_id | linked_lc_products                                |
      | 100        | [{"product_slug":"power-course","quantity":2}]    |

  Rule: 前置（狀態）- 未設定 partner_id 時不建立授權碼

    Example: partner_id 為空時 create_lcs 不呼叫 API
      Given 系統未設定 partner_id option
      And 訂閱 "SUB-NOPID" 的訂單含商品 #100
      And 已掛載 HTTP mock
      When 系統執行 create_lcs
      Then 不發出建立授權碼的 HTTP 請求

  Rule: 前置（狀態）- 商品未設定 linked_lc_products 時不建立授權碼

    Example: 商品無 linked_lc_products 時不呼叫 API
      Given 訂閱 "SUB-NOLC" 的訂單含一個未設定 linked_lc_products 的商品
      And 已掛載 HTTP mock
      When 系統執行 create_lcs
      Then 不發出建立授權碼的 HTTP 請求

  Rule: 後置（狀態）- 呼叫 CloudServer POST license-codes 並帶正確參數

    Example: 建立授權碼時請求帶 product_slug/quantity/post_author
      Given 訂閱 "SUB-CREATE" 的訂單含商品 #100
      And HTTP mock 回傳成功且 body 含一個 license_code（id 為 777）
      When 系統執行 create_lcs
      Then 發出的請求 URL 指向 CloudServer "license-codes"
      And 請求 body 含 product_slug "power-course"、quantity 2、post_author "12345"
      And 請求 body 含 subscription_id 與 customer_id

  Rule: 後置（狀態）- 建立成功後把回傳的 LC ID 寫入訂閱 lc_id meta（multi-value）

    Example: 建立成功後 lc_id 綁定到訂閱
      Given 訂閱 "SUB-BIND" 的訂單含商品 #100
      And HTTP mock 回傳的 license_codes 含 id 777 與 778
      When 系統執行 create_lcs
      Then 訂閱的 lc_id meta（get_post_meta 第三參數 false）包含 "777" 與 "778"
      And 訂閱的 linked_lc_products meta 被寫入

  Rule: 後置（狀態）- 建立成功後寄送授權碼開通 Email 給客戶

    Example: 建立成功後寄開通信給訂閱帳單信箱
      Given 訂閱 "SUB-MAIL" 的帳單信箱為 "customer@example.com"
      And HTTP mock 回傳含 product_name 與 code 的 license_codes
      When 系統執行 create_lcs
      Then 系統寄出一封主旨含「授權碼已開通」的 Email 給 "customer@example.com"

  Rule: 後置（狀態）- 建立 API 回傳 WP_Error 時寫失敗備註且不中斷整批

    Example: 單筆建立失敗時記錄失敗備註
      Given 訂閱 "SUB-CFAIL" 的訂單含商品 #100
      And HTTP mock 回傳 WP_Error
      When 系統執行 create_lcs
      Then 訂閱備註含「《新增》授權碼」與失敗標記
      And 訂閱不綁定任何 lc_id

  Rule: 前置（狀態）- 訂閱沒有綁定 lc_id 時 subscription_success 不呼叫恢復 API

    Example: 無 lc_id 時恢復流程跳過
      Given 訂閱 "SUB-NORESUME" 沒有 lc_id meta
      And 已掛載 HTTP mock
      When 系統執行 subscription_success
      Then 不發出授權碼恢復的 HTTP 請求

  Rule: 後置（狀態）- 訂閱失敗時排程 4 小時後停用授權碼，並先取消既有排程

    Example: 訂閱失敗後建立 4 小時延遲的停用排程
      Given 訂閱 "SUB-FAIL" 綁定了授權碼 101 與 102
      When 系統執行 subscription_failed
      Then 系統建立一個授權碼過期排程
      And 該排程的執行時間約為當下 +4 小時（容許 ±5 分鐘誤差）

  Rule: 後置（狀態）- 訂閱恢復時取消停用排程並呼叫 CloudServer license-codes/recover

    Example: 訂閱恢復後重啟授權碼並寫成功備註
      Given 訂閱 "SUB-RECOVER" 綁定了授權碼 101 與 102
      And 訂閱有一個 pending 的授權碼過期排程
      And HTTP mock 回傳恢復成功
      When 系統執行 subscription_success
      Then 系統取消 pending 的授權碼過期排程
      And 發出的請求 URL 指向 CloudServer "license-codes/recover"
      And 請求 body 含 ids 101 與 102
      And 訂閱備註含「《重啟》授權碼」與成功標記

  Rule: 後置（狀態）- 恢復 API 回傳 WP_Error 時寫失敗備註

    Example: 恢復失敗時記錄失敗備註
      Given 訂閱 "SUB-RFAIL" 綁定了授權碼 101
      And HTTP mock 回傳 WP_Error
      When 系統執行 subscription_success
      Then 訂閱備註含「《重啟》授權碼」與失敗標記
