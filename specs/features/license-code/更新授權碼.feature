@ignore @command
Feature: 更新授權碼

  管理員更新授權碼的綁定訂閱或狀態，同步呼叫 CloudServer API 更新。

  Background:
    Given 系統中有以下用戶：
      | userId | name   | email             | role          |
      | 1      | Admin  | admin@example.com | administrator |
    And 系統中有以下訂閱：
      | subscriptionId | status    | lc_ids |
      | 500            | wc-active | 101    |
      | 501            | wc-active |        |

  Rule: 前置（狀態）- 使用者需有 manage_options 權限

    Example: 非管理員嘗試更新授權碼時回傳 403
      Given 一個 subscriber 用戶已登入
      When 用戶發送 POST /wp-json/power-partner/license-codes/update
      Then 回應狀態碼為 403

  Rule: 後置（狀態）- 綁定到新訂閱時解除原訂閱綁定

    Example: 將授權碼 101 從訂閱 #500 綁定到訂閱 #501
      Given 用戶 "Admin" 已登入
      And 授權碼 101 目前綁定在訂閱 #500
      When 用戶發送 POST /wp-json/power-partner/license-codes/update 請求：
        | ids             | [101]               |
        | post_status     | follow_subscription |
        | subscription_id | 501                 |
      Then 訂閱 #500 的 lc_id meta 不再包含 101
      And 訂閱 #501 的 lc_id meta 包含 101
      And 因為訂閱 #501 狀態為 active，post_status 被改為 "available"
      And 系統呼叫 CloudServer API POST license-codes/update

  Rule: 後置（狀態）- 不指定 subscription_id 時解除所有訂閱綁定

    Example: 將授權碼 101 設為獨立使用（不跟隨訂閱）
      Given 用戶 "Admin" 已登入
      And 授權碼 101 目前綁定在訂閱 #500
      When 用戶發送 POST /wp-json/power-partner/license-codes/update 請求：
        | ids         | [101]     |
        | post_status | available |
      Then 訂閱 #500 的 lc_id meta 不再包含 101
      And 系統呼叫 CloudServer API POST license-codes/update
