@ignore @command
Feature: 訂閱失敗排程關站

  當訂閱狀態從 active 變為失敗狀態時，排程在 N 天後停用所有已連結的網站。
  給用戶緩衝期，避免立即關站。

  Background:
    Given 系統中有以下外掛設定：
      | disable_site_after_n_days |
      | 7                         |
    And 系統中有以下訂閱：
      | subscription_id | status | linked_site_ids |
      | SUB-001         | active | site-1, site-2  |

  Rule: 前置（狀態）- 訂閱狀態從 active 變為失敗狀態（cancelled/on-hold/pending-cancel）

    Example: 訂閱狀態變為 cancelled
      Given 訂閱 "SUB-001" 狀態為 "active"
      When WooCommerce 觸發 subscription_failed hook，狀態變為 "cancelled"
      Then 系統排程關站

  Rule: 後置（狀態）- 取消既有的 pending 關站排程，建立新排程

    Example: 重複失敗時重新排程
      Given 訂閱 "SUB-001" 已有一個 pending 的關站排程
      When WooCommerce 再次觸發 subscription_failed hook
      Then 取消既有的關站排程
      And 建立新的 ActionScheduler 排程，延遲 7 天後執行
      And 訂閱備註記錄排程時間和 action_id

  Rule: 後置（狀態）- 排程到期後根據 host_type 停用網站

    Example: WPCD 架構關站
      Given 關站排程到期
      And 訂閱的商品 host_type 為 "wpcd"
      And 訂閱連結的 site_ids 為 "site-1, site-2"
      When ActionScheduler 執行關站 callback
      Then 對每個 site_id 呼叫 CloudServer disable-site API
      And 訂閱備註記錄停用原因

    Example: PowerCloud 架構關站
      Given 關站排程到期
      And 訂閱的商品 host_type 為 "powercloud"
      And 訂單項目 meta 中有 websiteId "ws-001"
      When ActionScheduler 執行關站 callback
      Then 呼叫 PowerCloud API PATCH /wordpress/ws-001/stop
      And 訂閱備註記錄停用原因
