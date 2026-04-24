@ignore @command
Feature: WPCD開站

  透過 WPCD 舊架構（cloud.luke.cafe）呼叫開站 API，建立 WordPress 網站。
  使用 Basic Auth 認證，API endpoint 為 /wp-json/power-partner-server/site-sync。

  Background:
    Given 系統已設定 WPCD 連線資訊：
      | base_url              | username | password |
      | https://cloud.luke.cafe | admin   | secret   |
    And 系統已設定 partner_id 為 "P-123"

  Rule: 前置（參數）- site_id（模板站 ID）不可為空

    Example: 模板站 ID 為空時不呼叫 API
      Given 開站參數 site_id 為空
      When 系統嘗試透過 WPCD 開站
      Then 不會呼叫 CloudServer API

  Rule: 後置（狀態）- 呼叫 CloudServer site-sync API 並回傳結果

    Example: 開站成功
      Given 開站參數如下：
        | site_url      | https://partner-site.com |
        | site_id       | template-1               |
        | host_position | jp                       |
        | partner_id    | P-123                    |
      And 客戶資訊為：
        | id | first_name | last_name | username | email              | phone      |
        | 10 | John       | Doe       | johndoe  | john@example.com   | 0912345678 |
      When 系統呼叫 CloudServer API POST /wp-json/power-partner-server/site-sync
      Then API 使用 Basic Auth 認證
      And 回傳 status 為 200，message 為開站結果
      And 觸發 pp_after_site_sync action

    Example: CloudServer API 回傳錯誤
      Given 開站參數已設定
      And CloudServer API 回傳錯誤
      When 系統呼叫 CloudServer API POST /wp-json/power-partner-server/site-sync
      Then 拋出 Exception "開站失敗: {錯誤訊息}"
