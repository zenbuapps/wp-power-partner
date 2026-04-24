@ignore @command
Feature: WPCD開站回調通知客戶

  CloudServer（cloud.luke.cafe）完成 WPCD 開站後，
  回調通知本系統，觸發發送帳密 Email 給客戶並綁定 site_id 到訂閱。

  Background:
    Given 系統中有以下用戶：
      | userId | name     | email                | role       |
      | 10     | Customer | customer@example.com | subscriber |
    And 系統中有以下 Email 模板：
      | action_name | enabled | subject              | body                                    |
      | site_sync   | 1       | 你的網站開好囉       | 網站: ##FRONTURL## 密碼: ##SITEPASSWORD## |

  Rule: 前置（狀態）- 請求 IP 必須在白名單內

    Example: 非白名單 IP 嘗試呼叫時回傳 403
      Given 請求來自 IP 1.2.3.4（不在白名單）
      When 發送 POST /wp-json/power-partner/customer-notification
      Then 回應狀態碼為 403

  Rule: 前置（參數）- CUSTOMER_ID 必須對應有效的 WordPress 用戶

    Example: CUSTOMER_ID 無效時回傳 500
      Given 請求來自白名單 IP
      When 發送 POST /wp-json/power-partner/customer-notification 請求：
        | CUSTOMER_ID | 99999 |
      Then 回應 status 為 500
      And 回應 message 為 "missing customer id"

  Rule: 後置（狀態）- 發送帳密 Email 並綁定 site_id 到訂閱

    Example: CloudServer 開站完成後成功通知客戶
      Given 請求來自白名單 IP
      And 用戶 "Customer" 有訂單 #600 與訂閱 #500
      When 發送 POST /wp-json/power-partner/customer-notification 請求：
        | CUSTOMER_ID  | 10                            |
        | REF_ORDER_ID | 600                           |
        | NEW_SITE_ID  | site-abc                      |
        | IPV4         | 163.61.60.30                  |
        | DOMAIN       | mysite.wpsite.pro             |
        | FRONTURL     | https://mysite.wpsite.pro     |
        | ADMINURL     | https://mysite.wpsite.pro/wp-admin |
        | SITEUSERNAME | admin                         |
        | SITEPASSWORD | p@ssw0rd                      |
      Then 回應 status 為 200
      And 系統發送 site_sync Email 到 customer@example.com
      And Email 內容中 ##FRONTURL## 被替換為 "https://mysite.wpsite.pro"
      And Email 內容中 ##SITEPASSWORD## 被替換為 "p@ssw0rd"
      And 訂閱 #500 的 pp_linked_site_ids 包含 "site-abc"
