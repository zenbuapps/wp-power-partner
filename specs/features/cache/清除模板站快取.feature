@ignore @command
Feature: 清除模板站快取

  管理員清除模板站列表的 transient 快取，強制下次查詢時重新從 API 取得。

  Background:
    Given 系統中有以下用戶：
      | userId | name   | email             | role          |
      | 1      | Admin  | admin@example.com | administrator |
    And transient power_partner_allowed_template_options 存在

  Rule: 前置（狀態）- 使用者需有 manage_options 權限

    Example: 非管理員嘗試清除快取時回傳 403
      Given 一個 subscriber 用戶已登入
      When 用戶發送 POST /wp-json/power-partner/clear-template-sites-cache
      Then 回應狀態碼為 403

  Rule: 後置（狀態）- transient 被刪除

    Example: 管理員成功清除模板站快取
      Given 用戶 "Admin" 已登入
      When 用戶發送 POST /wp-json/power-partner/clear-template-sites-cache
      Then 回應 status 為 200
      And transient power_partner_allowed_template_options 被刪除
