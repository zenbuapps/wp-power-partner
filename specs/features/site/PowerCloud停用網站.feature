@ignore @command
Feature: PowerCloud停用網站

  透過 PowerCloud 新架構呼叫停用網站 API，暫停 WordPress 網站運作。
  API endpoint 為 PATCH /wordpress/{websiteId}/stop。

  Background:
    Given 系統已設定 PowerCloud API URL 為 "https://api.wpsite.pro"
    And 當前用戶 user_id 為 1
    And transient "power_partner_powercloud_api_key_1" 值為 "pk_test_123"

  Rule: 後置（狀態）- 呼叫 PowerCloud API PATCH /wordpress/{websiteId}/stop

    Example: 停用網站成功
      Given 網站 websiteId 為 "ws-abc"
      When 系統呼叫 PowerCloud API PATCH /wordpress/ws-abc/stop
      Then API 使用 X-API-Key header 認證
      And 記錄 logger "disable_site success"

    Example: PowerCloud API 回傳錯誤
      Given 網站 websiteId 為 "ws-abc"
      And PowerCloud API 回傳錯誤
      When 系統呼叫 PowerCloud API PATCH /wordpress/ws-abc/stop
      Then 記錄 logger "disable_site error: {錯誤訊息}"
