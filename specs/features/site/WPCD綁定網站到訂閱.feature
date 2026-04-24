@ignore @command
Feature: WPCD綁定網站到訂閱

  CloudServer 開站成功後，回調本系統綁定 site_id 到指定訂閱。
  僅限白名單 IP 呼叫。

  Background:
    Given 系統中有以下用戶：
      | userId | name     | email                | role       |
      | 10     | Customer | customer@example.com | subscriber |
    And 系統中有訂閱 #500

  Rule: 前置（狀態）- 請求 IP 必須在白名單內

    Example: 非白名單 IP 嘗試呼叫時回傳 403
      Given 請求來自 IP 1.2.3.4（不在白名單）
      When 發送 POST /wp-json/power-partner/link-site
      Then 回應狀態碼為 403

  Rule: 後置（狀態）- site_id 被加入訂閱的 pp_linked_site_ids meta

    Example: CloudServer 開站成功後綁定網站 ID
      Given 請求來自白名單 IP
      When 發送 POST /wp-json/power-partner/link-site 請求：
        | subscription_id | 500      |
        | site_id         | site-xyz |
      Then 回應 status 為 200
      And 訂閱 #500 的 pp_linked_site_ids 包含 "site-xyz"
