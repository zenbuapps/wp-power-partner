@ignore @command
Feature: 發送站點帳密郵件

  管理員手動發送站點帳密 Email，使用 site_sync Email 模板，
  將 ##TOKEN## 格式的佔位符替換為實際值後發送。

  Background:
    Given 系統中有以下用戶：
      | userId | name   | email             | role          |
      | 1      | Admin  | admin@example.com | administrator |
    And 系統中有以下 Email 模板：
      | action_name | enabled | subject                         | body                                    |
      | site_sync   | 1       | 你的網站 ##DOMAIN## 開好囉       | 密碼: ##SITEPASSWORD## URL: ##FRONTURL## |

  Rule: 前置（狀態）- 使用者需有 manage_options 權限

    Example: 非管理員嘗試發送時回傳 403
      Given 一個 subscriber 用戶已登入
      When 用戶發送 POST /wp-json/power-partner/send-site-credentials-email
      Then 回應狀態碼為 403

  Rule: 前置（參數）- domain 與 password 為必填

    Example: 缺少 domain 時回傳 400
      Given 用戶 "Admin" 已登入
      When 用戶發送 POST /wp-json/power-partner/send-site-credentials-email 請求：
        | domain   |          |
        | password | p@ssw0rd |
      Then 回應狀態碼為 400
      And 回應 message 包含 "缺少必要參數"

  Rule: 前置（狀態）- 必須存在 site_sync Email 模板

    Example: 無 site_sync 模板時回傳 404
      Given 用戶 "Admin" 已登入
      And 系統中沒有啟用的 site_sync Email 模板
      When 用戶發送 POST /wp-json/power-partner/send-site-credentials-email 請求：
        | domain   | mysite.wpsite.pro |
        | password | p@ssw0rd          |
      Then 回應狀態碼為 404
      And 回應 message 包含 "找不到郵件模板"

  Rule: 後置（狀態）- 使用 site_sync 模板發送 Email

    Example: 管理員成功發送帳密 Email
      Given 用戶 "Admin" 已登入
      When 用戶發送 POST /wp-json/power-partner/send-site-credentials-email 請求：
        | domain   | mysite.wpsite.pro |
        | password | p@ssw0rd          |
        | username | admin             |
        | ip       | 163.61.60.30      |
      Then 回應 status 為 200
      And 系統發送 Email 到 admin@example.com
      And Email 主旨中 ##DOMAIN## 被替換為 "mysite.wpsite.pro"
      And Email 內容中 ##SITEPASSWORD## 被替換為 "p@ssw0rd"
