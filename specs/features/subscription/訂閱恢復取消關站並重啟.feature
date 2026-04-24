@ignore @command
Feature: 訂閱恢復取消關站並重啟

  當訂閱狀態從失敗狀態變回 active 時，取消關站排程並重新啟用所有已停止的網站。

  Background:
    Given 系統中有以下訂閱：
      | subscription_id | status    | linked_site_ids |
      | SUB-001         | cancelled | site-1          |

  Rule: 前置（狀態）- 訂閱狀態變回 active

    Example: 訂閱恢復成功
      Given 訂閱 "SUB-001" 狀態為 "cancelled"
      When WooCommerce 觸發 subscription_success hook，狀態變為 "active"
      Then 系統執行恢復流程

  Rule: 後置（狀態）- 取消 pending 的關站排程

    Example: 取消關站排程
      Given 訂閱 "SUB-001" 有 pending 的關站排程 action_id "A-100"
      When 訂閱恢復為 active
      Then 取消 action_id "A-100" 的排程
      And 記錄 log「取消排程停用網站」

  Rule: 後置（狀態）- WPCD 架構重新啟用網站

    Example: 重新啟用 WPCD 網站
      Given 訂閱 "SUB-001" 連結的 site_ids 為 "site-1"
      And 商品 host_type 為 "wpcd"
      When 訂閱恢復為 active
      Then 呼叫 CloudServer enable-site API，site_id 為 "site-1"

  Rule: 後置（狀態）- PowerCloud 架構重新啟動網站

    Example: 重新啟動 PowerCloud 網站
      Given 訂閱 "SUB-001" 的訂單項目 meta 中有 websiteId "ws-001"
      And 商品 host_type 為 "powercloud"
      When 訂閱恢復為 active
      Then 呼叫 PowerCloud API PATCH /wordpress/ws-001/start
