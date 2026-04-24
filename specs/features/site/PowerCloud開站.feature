@ignore @command
Feature: PowerCloud開站

  透過 PowerCloud 新架構（api.wpsite.pro）呼叫開站 API，建立 WordPress 網站。
  使用 X-API-Key 認證，API endpoint 為 POST /wordpress。
  系統自動生成隨機 namespace 作為網域（{namespace}.wpsite.pro）。

  Background:
    Given 系統已設定 PowerCloud API URL 為 "https://api.wpsite.pro"
    And 當前用戶 user_id 為 1
    And transient "power_partner_powercloud_api_key_1" 值為 "pk_test_123"
    And 系統已快取模板站列表：
      | template_id | domain                |
      | tpl-1       | template.wpsite.pro   |

  Rule: 前置（狀態）- 必須存在有效的 PowerCloud API Key transient

    Example: API Key 不存在時拋出錯誤
      Given transient "power_partner_powercloud_api_key_1" 為空
      When 系統嘗試透過 PowerCloud 開站
      Then 拋出 Exception "PowerCloud API Key 不存在，請先登入 PowerCloud"

  Rule: 後置（狀態）- 呼叫 PowerCloud API POST /wordpress 建立網站

    Example: 開站成功（回傳 201）
      Given 開站參數如下：
        | open_site_plan_id | plan-1   |
        | template_site_id  | tpl-1    |
      And 客戶資訊為：
        | id | username | email            |
        | 10 | johndoe  | john@example.com |
      When 系統呼叫 PowerCloud API POST /wordpress
      Then API 使用 X-API-Key header 認證
      And 請求包含 packageId, name, namespace, domain, mysql, wordpress, templateUrl
      And domain 格式為 "{隨機形容詞}-{隨機動物}-{4位數字}.wpsite.pro"
      And 回傳 response_obj（status=201）與 wordpress_obj（含 domain, wp_admin_password 等）
      And 觸發 pp_after_site_sync_powercloud action

    Example: 開站失敗（非 2xx 回應）
      Given 開站參數已設定
      And PowerCloud API 回傳 400 錯誤
      When 系統呼叫 PowerCloud API POST /wordpress
      Then 回傳 response_obj status 為 400，message 為 "開站失敗"
