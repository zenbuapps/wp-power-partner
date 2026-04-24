@ignore @query
Feature: 查詢合作夥伴ID

  取得當前設定的合作夥伴 ID。

  Background:
    Given 系統中有以下合作夥伴設定：
      | partner_id |
      | P-123      |

  Rule: 無權限限制（public API）

    Example: 查詢成功
      Given 系統已設定 partner_id 為 "P-123"
      When 用戶查詢合作夥伴 ID
      Then 回傳 partner_id 為 "P-123"

    Example: 未設定 partner_id
      Given 系統未設定 partner_id
      When 用戶查詢合作夥伴 ID
      Then 操作失敗，錯誤為「fail, partner_id is empty」
