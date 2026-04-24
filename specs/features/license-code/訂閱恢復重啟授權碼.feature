@ignore @command
Feature: 訂閱恢復重啟授權碼

  訂閱狀態從失敗狀態變回 active 時，取消停用排程並呼叫 CloudServer API 重新啟用授權碼。

  Background:
    Given 系統中有以下用戶：
      | userId | name     | email                | role       |
      | 10     | Customer | customer@example.com | subscriber |
    And 系統中有以下訂閱：
      | subscriptionId | status       | lc_ids   |
      | 500            | wc-cancelled | 101, 102 |

  Rule: 前置（狀態）- 訂閱上有綁定 lc_id meta

    Example: 訂閱沒有綁定授權碼時不處理
      Given 訂閱 #600 沒有 lc_id meta
      When WooCommerce 觸發 SUBSCRIPTION_SUCCESS hook
      Then 不會呼叫授權碼恢復 API

  Rule: 後置（狀態）- 取消停用排程並呼叫 recover API

    Example: 訂閱恢復後重新啟用授權碼
      Given 訂閱 #500 狀態為 wc-cancelled 且綁定了授權碼 101, 102
      And 訂閱 #500 有 pending 的授權碼過期排程
      When WooCommerce 觸發 SUBSCRIPTION_SUCCESS hook（cancelled -> active）
      Then 系統取消 pending 的授權碼過期排程
      And 系統呼叫 CloudServer API POST license-codes/recover
      And 請求包含 ids: [101, 102]
      And 訂閱 #500 新增訂單備註「站長路可《重啟》授權碼 成功」
