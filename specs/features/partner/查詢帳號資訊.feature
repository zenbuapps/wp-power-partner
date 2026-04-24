@ignore @query
Feature: 查詢帳號資訊

  取得加密的合作夥伴帳號資訊，用於前端顯示連結狀態。

  Background:
    Given 系統中有以下合作夥伴設定：
      | account_info   |
      | enc_data_xxx   |

  Rule: 無權限限制（public API）

    Example: 查詢成功
      Given 系統已設定 account_info 為 "enc_data_xxx"
      When 用戶查詢帳號資訊
      Then 回傳 encrypted_account_info 為 "enc_data_xxx"

    Example: 未設定帳號資訊
      Given 系統未設定 account_info
      When 用戶查詢帳號資訊
      Then 回傳 encrypted_account_info 為 null
