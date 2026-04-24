@ignore @command
Feature: WPCD啟用網站

  透過 WPCD 舊架構呼叫啟用網站 API，重新啟動已停用的 WordPress 網站。
  API endpoint 為 POST /wp-json/power-partner-server/v2/enable-site。

  Background:
    Given 系統已設定 WPCD 連線資訊：
      | base_url                | username | password |
      | https://cloud.luke.cafe | admin    | secret   |
    And 系統已設定 partner_id 為 "P-123"

  Rule: 後置（狀態）- 呼叫 CloudServer enable-site API

    Example: 啟用網站成功
      Given 網站 site_id 為 "site-abc"
      When 系統呼叫 CloudServer API POST /wp-json/power-partner-server/v2/enable-site
      Then 請求包含 site_id: "site-abc", partner_id: "P-123"
      And API 使用 Basic Auth 認證
      And 回傳啟用結果

    Example: CloudServer API 回傳錯誤
      Given 網站 site_id 為 "site-abc"
      And CloudServer API 回傳錯誤
      When 系統呼叫 CloudServer API POST /wp-json/power-partner-server/v2/enable-site
      Then 回傳 status 為 500，message 包含錯誤訊息
