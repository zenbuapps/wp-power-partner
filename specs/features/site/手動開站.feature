@ignore @command
Feature: 手動開站

  管理員透過後台手動呼叫 CloudServer 開站 API（僅支援 WPCD 舊架構）。

  Background:
    Given 系統中有以下用戶：
      | userId | name   | email             | role          |
      | 1      | Admin  | admin@example.com | administrator |
    And 系統已設定 partner_id 為 "12345"

  Rule: 前置（狀態）- 使用者需有 manage_options 權限

    Example: 非管理員嘗試手動開站時回傳 403
      Given 一個 subscriber 用戶已登入
      When 用戶發送 POST /wp-json/power-partner/manual-site-sync 請求：
        | site_id       | 100 |
        | host_position | jp  |
      Then 回應狀態碼為 403

  Rule: 後置（狀態）- 呼叫 CloudServer 開站 API

    Example: 管理員手動開站成功
      Given 用戶 "Admin" 已登入
      When 用戶發送 POST /wp-json/power-partner/manual-site-sync 請求：
        | site_id       | 100 |
        | host_position | jp  |
      Then 系統呼叫 CloudServer API POST /wp-json/power-partner-server/site-sync
      And 回應包含開站結果（status, message, data）

    Example: 開站 API 失敗時回傳錯誤訊息
      Given 用戶 "Admin" 已登入
      And CloudServer 開站 API 回傳錯誤
      When 用戶發送 POST /wp-json/power-partner/manual-site-sync 請求：
        | site_id       | 999 |
        | host_position | jp  |
      Then 回應 status 為 500
      And 回應 message 包含 "手動開站建立網站失敗"
