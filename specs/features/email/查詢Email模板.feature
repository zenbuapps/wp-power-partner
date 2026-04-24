@ignore @query
Feature: 查詢Email模板

  管理員查詢系統中所有已設定的 Email 模板（包含已停用的）。

  Background:
    Given 系統中有以下用戶：
      | userId | name   | email             | role          |
      | 1      | Admin  | admin@example.com | administrator |
    And 系統中有以下 Email 模板：
      | key   | enabled | action_name  | subject        | body            | days | operator |
      | key-1 | 1       | site_sync    | 網站開好囉     | 你的網站...     | 0    | after    |
      | key-2 | 0       | next_payment | 即將扣款       | 親愛的客戶...   | 3    | before   |

  Rule: 前置（狀態）- 使用者需有 manage_options 權限

    Example: 非管理員嘗試查詢 Email 模板時回傳 403
      Given 一個 subscriber 用戶已登入
      When 用戶發送 GET /wp-json/power-partner/emails
      Then 回應狀態碼為 403

  Rule: 後置（回應）- 回傳所有 Email 模板（含已停用的）

    Example: 管理員查詢所有 Email 模板
      Given 用戶 "Admin" 已登入
      When 用戶發送 GET /wp-json/power-partner/emails
      Then 回應狀態碼為 200
      And 回應為陣列，包含 2 個 Email 模板
      And 每個模板包含 key, enabled, action_name, subject, body, days, operator
      And 第一個模板 key 為 "key-1"，action_name 為 "site_sync"
