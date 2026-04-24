@ignore @command
Feature: 變更訂閱綁定

  管理員將網站重新綁定到指定的訂閱上，用於修正錯誤綁定或轉移網站。

  Background:
    Given 系統中有以下訂閱：
      | subscription_id | linked_site_ids |
      | SUB-001         | site-1, site-2  |
      | SUB-002         |                 |

  Rule: 前置（狀態）- 使用者需有 manage_options 權限

    Example: 非管理員無法操作
      Given 當前用戶不具備 "manage_options" 權限
      When 用戶嘗試變更訂閱綁定
      Then 操作失敗，回傳 403

  Rule: 前置（參數）- subscription_id 與 site_id 不可為空

    Example: 缺少必要參數
      Given 當前用戶為管理員
      When 用戶發送變更訂閱綁定，subscription_id 為空
      Then 操作失敗，錯誤為「missing subscription id or site id」

  Rule: 前置（狀態）- 訂閱必須存在且有父訂單

    Example: 訂閱無父訂單
      Given 訂閱 "SUB-003" 存在但無父訂單
      When 管理員嘗試變更訂閱綁定
      Then 操作失敗，錯誤為「subscription has no parent order」

  Rule: 後置（狀態）- 原本綁定這些 site_id 的訂閱會被解綁

    Example: 網站從原訂閱解綁再綁定到新訂閱
      Given 訂閱 "SUB-001" 綁定了 "site-1"
      When 管理員將 "site-1" 綁定到訂閱 "SUB-002"
      Then 訂閱 "SUB-001" 的 linked_site_ids 不再包含 "site-1"
      And 訂閱 "SUB-002" 的 linked_site_ids 包含 "site-1"
      And 兩個訂閱都記錄變更備註
