@ignore @query
Feature: 查詢客戶資訊

  公開 API，根據 user_ids 查詢客戶詳細資訊。
  無權限限制。

  Background:
    Given 系統中有以下用戶：
      | userId | name     | email                | role       |
      | 10     | John Doe | john@example.com     | subscriber |
      | 11     | Jane     | jane@example.com     | subscriber |

  Rule: 前置（參數）- user_ids 不可為空

    Example: 不帶 user_ids 時回傳 500
      When 用戶發送 GET /wp-json/power-partner/customers
      Then 回應狀態碼為 500
      And 回應 message 為 "missing user ids"

  Rule: 後置（回應）- 回傳客戶詳細資訊

    Example: 查詢多個用戶的資訊
      When 用戶發送 GET /wp-json/power-partner/customers?user_ids[]=10&user_ids[]=11
      Then 回應狀態碼為 200
      And 回應為陣列，包含 2 個客戶
      And 每個客戶包含 id, user_login, user_email, display_name
      And Header X-WP-Total 為 "2"
      And Header X-WP-TotalPages 為 "1"
