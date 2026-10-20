@ignore @query
Feature: 查詢客戶資訊

  根據 user_ids 查詢客戶詳細資訊。
  需要登入：管理員可查任意用戶；一般會員（UserApp 前台）只能查到自己（原為 public，已收斂）。

  Background:
    Given 系統中有以下用戶：
      | userId | name     | email                | role          |
      | 1      | Admin    | admin@example.com    | administrator |
      | 10     | John Doe | john@example.com     | subscriber    |
      | 11     | Jane     | jane@example.com     | subscriber    |

  Rule: 前置（權限）- 需要登入

    Example: 未登入時拒絕
      Given 用戶未登入
      When 用戶發送 GET /wp-json/power-partner/customers?user_ids[]=10
      Then 回應狀態碼為 401
      And 回應不含任何 user_email

  Rule: 前置（參數）- user_ids 不可為空

    Example: 不帶 user_ids 時回傳 500
      Given 用戶以 userId 1 登入
      When 用戶發送 GET /wp-json/power-partner/customers
      Then 回應狀態碼為 500
      And 回應 message 為 "missing user ids"

  Rule: 後置（回應）- 管理員可查詢任意用戶

    Example: 查詢多個用戶的資訊
      Given 用戶以 userId 1 登入
      When 用戶發送 GET /wp-json/power-partner/customers?user_ids[]=10&user_ids[]=11
      Then 回應狀態碼為 200
      And 回應為陣列，包含 2 個客戶
      And 每個客戶包含 id, user_login, user_email, display_name
      And Header X-WP-Total 為 "2"
      And Header X-WP-TotalPages 為 "1"

  Rule: 後置（回應）- 一般會員只能查到自己

    Example: 查詢範圍包含自己時只回傳自己
      Given 用戶以 userId 10 登入
      When 用戶發送 GET /wp-json/power-partner/customers?user_ids[]=10&user_ids[]=11
      Then 回應狀態碼為 200
      And 回應為陣列，只包含 userId 10

    Example: 查詢範圍不含自己時回傳空陣列（不可退化成「全部用戶」）
      Given 用戶以 userId 10 登入
      When 用戶發送 GET /wp-json/power-partner/customers?user_ids[]=11
      Then 回應狀態碼為 200
      And 回應為空陣列
      And Header X-WP-Total 為 "0"
