@ignore @query
Feature: 搜尋客戶

  管理員透過 ID 或關鍵字搜尋 WordPress 用戶。

  Background:
    Given 系統中有以下用戶：
      | userId | name     | email                | role          |
      | 1      | Admin    | admin@example.com    | administrator |
      | 10     | John Doe | john@example.com     | subscriber    |
      | 11     | Jane     | jane@example.com     | subscriber    |

  Rule: 前置（狀態）- 使用者需有 manage_options 權限

    Example: 非管理員嘗試搜尋時回傳 403
      Given 用戶 "John Doe" 已登入
      When 用戶發送 GET /wp-json/power-partner/customers-by-search?search=john
      Then 回應狀態碼為 403

  Rule: 前置（參數）- id 或 search 至少需提供一個

    Example: 不帶任何參數時回傳 400
      Given 用戶 "Admin" 已登入
      When 用戶發送 GET /wp-json/power-partner/customers-by-search
      Then 回應 status 為 400
      And 回應 message 為 "missing id or search parameter"

  Rule: 後置（回應）- 以 ID 查詢客戶

    Example: 使用 ID 查詢特定客戶
      Given 用戶 "Admin" 已登入
      When 用戶發送 GET /wp-json/power-partner/customers-by-search?id=10
      Then 回應 status 為 200
      And 回應 data 包含 userId 10 的客戶資訊

  Rule: 後置（回應）- 以關鍵字搜尋客戶

    Example: 使用關鍵字搜尋客戶
      Given 用戶 "Admin" 已登入
      When 用戶發送 GET /wp-json/power-partner/customers-by-search?search=john
      Then 回應 status 為 200
      And 回應 data 包含 "John Doe" 的客戶資訊

    Example: 搜尋無結果時回傳 404
      Given 用戶 "Admin" 已登入
      When 用戶發送 GET /wp-json/power-partner/customers-by-search?search=nonexistent
      Then 回應 status 為 404
