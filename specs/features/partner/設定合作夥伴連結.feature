@ignore @command
Feature: 設定合作夥伴連結

  管理員透過 cloud.luke.cafe 完成帳號連結後，儲存 partner_id 與帳號資訊。

  Background:
    Given 系統中合作夥伴設定為空

  Rule: 前置（狀態）- 使用者需有 manage_options 權限

    Example: 非管理員無法操作
      Given 當前用戶不具備 "manage_options" 權限
      When 用戶嘗試設定合作夥伴連結
      Then 操作失敗，回傳 403

  Rule: 前置（參數）- partner_id 不可為空

    Example: partner_id 為空
      Given 當前用戶為管理員
      When 用戶發送設定合作夥伴連結，partner_id 為空
      Then 操作失敗，錯誤為「partner_id is empty」

  Rule: 後置（狀態）- 儲存 partner_id、帳號資訊、模板站快取

    Example: 設定成功
      Given 當前用戶為管理員
      When 用戶發送設定合作夥伴連結：
        | partner_id | encrypted_account_info | allowed_template_options       |
        | P-123      | enc_data_xxx           | {"1": "Site A", "2": "Site B"} |
      Then wp_options "power_partner_partner_id" 值為 "P-123"
      And wp_options "power_partner_account_info" 值為 "enc_data_xxx"
      And transient "power_partner_allowed_template_options" 被設定為模板站列表
