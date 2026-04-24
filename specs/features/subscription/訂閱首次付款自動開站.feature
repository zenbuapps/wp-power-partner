@ignore @command
Feature: 訂閱首次付款自動開站

  當訂閱首次付款成功時，根據商品設定的模板站自動建立 WordPress 網站。
  支援 WPCD (舊架構) 和 PowerCloud (新架構) 兩種開站方式。

  Background:
    Given 系統中有以下訂閱商品：
      | product_id | product_type            | host_type  | linked_site | host_position | open_site_plan |
      | 101        | subscription            | powercloud | tpl-001     | tw            | plan-001       |
      | 102        | subscription_variation  | wpcd       | tpl-002     | jp            |                |
    And 系統中有以下合作夥伴設定：
      | partner_id | base_url                 |
      | P-123      | https://cloud.luke.cafe  |

  Rule: 前置（狀態）- 訂閱必須只有一筆關聯訂單（parent order），續訂不觸發

    Example: 續訂付款不觸發開站
      Given 訂閱 "SUB-001" 已有 2 筆關聯訂單
      When WooCommerce 觸發 initial_payment_complete hook
      Then 不執行開站流程

  Rule: 前置（狀態）- 商品必須有設定模板站 ID（power_partner_linked_site）

    Example: 商品未設定模板站則跳過
      Given 訂閱 "SUB-002" 的商品未設定 power_partner_linked_site
      When WooCommerce 觸發 initial_payment_complete hook
      Then 該商品項目不執行開站

  Rule: 前置（狀態）- 商品類型必須為 subscription 或 subscription_variation

    Example: 非訂閱商品不處理
      Given 訂閱 "SUB-003" 的訂單中含有 simple 類型商品
      When WooCommerce 觸發 initial_payment_complete hook
      Then simple 類型商品項目被跳過

  Rule: 後置（狀態）- host_type 為 powercloud 時，呼叫 PowerCloud API 開站

    Example: PowerCloud 開站成功
      Given 訂閱 "SUB-004" 的商品 host_type 為 "powercloud"
      And 商品的 open_site_plan 為 "plan-001"
      And 商品的 linked_site 為 "tpl-001"
      When WooCommerce 觸發 initial_payment_complete hook
      Then 系統呼叫 PowerCloud API POST /wordpress 開站
      And 開站結果存入訂單 meta "pp_create_site_responses"
      And 開站結果存入訂單項目 meta "_pp_create_site_responses_item"
      And 訂閱標記 "is_power_partner_site_sync" meta 為 "1"
      And 排程 4 分鐘後發送帳密 Email 給客戶

  Rule: 後置（狀態）- host_type 為 wpcd 時，呼叫 CloudServer API 開站

    Example: WPCD 開站成功
      Given 訂閱 "SUB-005" 的商品 host_type 為 "wpcd"
      And 商品的 linked_site 為 "tpl-002"
      And 商品的 host_position 為 "jp"
      When WooCommerce 觸發 initial_payment_complete hook
      Then 系統呼叫 CloudServer API POST /wp-json/power-partner-server/site-sync
      And 開站結果存入訂單 meta "pp_create_site_responses"
      And 訂閱標記 "is_power_partner_site_sync" meta 為 "1"
      And 觸發 pp_site_sync_by_subscription action 排程寄信

  Rule: 後置（狀態）- 開站失敗時記錄錯誤

    Example: 開站 API 回傳錯誤
      Given 訂閱 "SUB-006" 的商品已設定開站參數
      And CloudServer API 回傳錯誤
      When WooCommerce 觸發 initial_payment_complete hook
      Then 訂閱備註記錄「網站建立失敗」及錯誤訊息
      And 記錄 error 等級 log
