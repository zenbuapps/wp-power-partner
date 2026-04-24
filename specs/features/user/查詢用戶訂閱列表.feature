@ignore @query
Feature: 查詢用戶訂閱列表

  管理員查詢指定用戶的訂閱列表，過濾掉已取消的訂閱。

  Background:
    Given 系統中有以下用戶：
      | userId | name     | email                | role          |
      | 1      | Admin    | admin@example.com    | administrator |
      | 10     | Customer | customer@example.com | subscriber    |
    And 系統中有以下訂閱：
      | subscriptionId | customerId | status       | linked_site_ids |
      | 500            | 10         | wc-active    | site-a          |
      | 501            | 10         | wc-on-hold   | site-b, site-c  |
      | 502            | 10         | wc-cancelled |                 |

  Rule: 前置（狀態）- 使用者需有 manage_options 權限

    Example: 非管理員嘗試查詢時回傳 403
      Given 用戶 "Customer" 已登入
      When 用戶發送 GET /wp-json/power-partner/subscriptions?user_id=10
      Then 回應狀態碼為 403

  Rule: 前置（參數）- user_id 為必填

    Example: 缺少 user_id 時回傳 500
      Given 用戶 "Admin" 已登入
      When 用戶發送 GET /wp-json/power-partner/subscriptions
      Then 回應狀態碼為 500

  Rule: 後置（回應）- 回傳訂閱清單（含 linked_site_ids），過濾 cancelled

    Example: 查詢用戶 10 的訂閱列表
      Given 用戶 "Admin" 已登入
      When 用戶發送 GET /wp-json/power-partner/subscriptions?user_id=10
      Then 回應狀態碼為 200
      And 回應為陣列，包含狀態為 on-hold, active, pending, expired, pending-cancel 的訂閱
      And 不包含 wc-cancelled 的訂閱
      And 每個訂閱包含 id, status, post_title, post_date, linked_site_ids
      And Header X-WP-Total 與 X-WP-TotalPages 已設定
