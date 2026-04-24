@ignore @command
Feature: 刪除授權碼

  管理員刪除授權碼，解除訂閱綁定並呼叫 CloudServer API 刪除。

  Background:
    Given 系統中有以下用戶：
      | userId | name   | email             | role          |
      | 1      | Admin  | admin@example.com | administrator |
    And 系統中有以下訂閱：
      | subscriptionId | status    | lc_ids    |
      | 500            | wc-active | 101, 102  |

  Rule: 前置（狀態）- 使用者需有 manage_options 權限

    Example: 非管理員嘗試刪除授權碼時回傳 403
      Given 一個 subscriber 用戶已登入
      When 用戶發送 DELETE /wp-json/power-partner/license-codes
      Then 回應狀態碼為 403

  Rule: 後置（狀態）- 解除訂閱綁定並呼叫 CloudServer 刪除 API

    Example: 管理員刪除授權碼 101
      Given 用戶 "Admin" 已登入
      And 授權碼 101 綁定在訂閱 #500
      When 用戶發送 DELETE /wp-json/power-partner/license-codes 請求：
        | ids | [101] |
      Then 訂閱 #500 的 lc_id meta 不再包含 101
      And 系統呼叫 CloudServer API DELETE license-codes
      And 回應狀態碼為 200
