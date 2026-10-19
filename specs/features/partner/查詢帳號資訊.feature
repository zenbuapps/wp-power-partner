@ignore @query
Feature: 查詢帳號資訊

  取得加密的合作夥伴帳號資訊，供 AdminApp 自動登入使用。

  ⚠️ encrypted_account_info 是經銷商在 cloud.luke.cafe 的真實帳密，加密 passphrase 是公開的
  Plugin::$kebab，任何人拿到密文都解得開，因此只允許 manage_options 讀取（原為 public，已收斂）。

  Background:
    Given 系統中有以下合作夥伴設定：
      | account_info   |
      | enc_data_xxx   |

  Rule: 前置（權限）- 需要 manage_options

    Example: 未登入時拒絕
      Given 用戶未登入
      When 用戶查詢帳號資訊
      Then 回應狀態碼為 401
      And 回應不含 encrypted_account_info

    Example: 非管理員時拒絕
      Given 用戶以 subscriber 身分登入
      When 用戶查詢帳號資訊
      Then 回應狀態碼為 403

  Rule: 後置（回應）- 管理員取得加密帳號資訊

    Example: 查詢成功
      Given 用戶以 administrator 身分登入
      And 系統已設定 account_info 為 "enc_data_xxx"
      When 用戶查詢帳號資訊
      Then 回傳 encrypted_account_info 為 "enc_data_xxx"

    Example: 未設定帳號資訊
      Given 用戶以 administrator 身分登入
      And 系統未設定 account_info
      When 用戶查詢帳號資訊
      Then 回傳 encrypted_account_info 為 null
