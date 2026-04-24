@ignore @command
Feature: 訂閱失敗排程停用授權碼

  訂閱狀態從 active 變為失敗狀態時，排程 4 小時後呼叫 CloudServer API 停用授權碼。
  使用 ActionScheduler，hook 為 "power_partner/3.1.0/lc/expire"。

  Background:
    Given 系統中有以下用戶：
      | userId | name     | email                | role       |
      | 10     | Customer | customer@example.com | subscriber |
    And 系統中有以下訂閱：
      | subscriptionId | status    | lc_ids    |
      | 500            | wc-active | 101, 102  |

  Rule: 前置（狀態）- 訂閱上有綁定 lc_id meta

    Example: 訂閱沒有綁定授權碼時不排程
      Given 訂閱 #600 沒有 lc_id meta
      When WooCommerce 觸發 SUBSCRIPTION_FAILED hook
      Then 不會建立授權碼過期排程

  Rule: 後置（狀態）- 排程 4 小時後呼叫 CloudServer expire API

    Example: 訂閱失敗後排程停用授權碼
      Given 訂閱 #500 狀態為 wc-active 且綁定了授權碼 101, 102
      When WooCommerce 觸發 SUBSCRIPTION_FAILED hook（active -> cancelled）
      Then 系統取消既有的 LC 過期排程
      And 系統建立 ActionScheduler 排程，4 小時後執行
      And 排程 hook 為 "power_partner/3.1.0/lc/expire"
      And 排程到期後呼叫 CloudServer API POST license-codes/expire
      And 請求包含 ids: [101, 102]
