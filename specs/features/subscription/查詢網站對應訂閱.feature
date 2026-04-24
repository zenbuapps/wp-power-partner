@ignore @query
Feature: 查詢網站對應訂閱

  根據網站 ID 列表查詢對應的訂閱 ID，用於前端網站列表顯示所屬訂閱。

  Background:
    Given 系統中有以下訂閱：
      | subscription_id | linked_site_ids |
      | SUB-001         | site-1          |
      | SUB-002         | site-2          |

  Rule: 無權限限制（public API）

    Example: 查詢網站對應的訂閱
      Given 網站 "site-1" 綁定在訂閱 "SUB-001" 上
      When 用戶查詢 app_ids 為 ["site-1", "site-2"]
      Then 回傳：
        | app_id | subscription_ids |
        | site-1 | [SUB-001]        |
        | site-2 | [SUB-002]        |

    Example: 網站未綁定任何訂閱
      Given 網站 "site-99" 未綁定任何訂閱
      When 用戶查詢 app_ids 為 ["site-99"]
      Then 回傳：
        | app_id  | subscription_ids |
        | site-99 | []               |
