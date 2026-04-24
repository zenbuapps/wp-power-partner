@ignore @command
Feature: 刪除合作夥伴連結

  管理員解除與 cloud.luke.cafe 的帳號連結。

  Background:
    Given 系統中有以下合作夥伴設定：
      | partner_id | account_info   |
      | P-123      | enc_data_xxx   |

  Rule: 前置（狀態）- 使用者需有 manage_options 權限

    Example: 非管理員無法操作
      Given 當前用戶不具備 "manage_options" 權限
      When 用戶嘗試刪除合作夥伴連結
      Then 操作失敗，回傳 403

  Rule: 後置（狀態）- 刪除所有合作夥伴相關 option 和 transient

    Example: 刪除成功
      Given 當前用戶為管理員
      When 用戶發送刪除合作夥伴連結
      Then wp_options "power_partner_partner_id" 被刪除
      And wp_options "power_partner_account_info" 被刪除
      And transient "power_partner_allowed_template_options" 被刪除
