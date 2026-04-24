@ignore @command
Feature: 儲存設定

  管理員更新外掛設定，包括關站天數和 Email 模板。
  設定變更會觸發相關排程的重新計算。

  Background:
    Given 系統中有以下外掛設定：
      | disable_site_after_n_days | emails                   |
      | 7                         | [{action_name: site_sync}] |

  Rule: 前置（狀態）- 使用者需有 manage_options 權限

    Example: 非管理員無法操作
      Given 當前用戶不具備 "manage_options" 權限
      When 用戶嘗試儲存設定
      Then 操作失敗，回傳 403

  Rule: 後置（狀態）- 更新 power_partner_settings option

    Example: 儲存成功
      Given 當前用戶為管理員
      When 用戶發送儲存設定：
        | disable_site_after_n_days | emails                                    |
        | 14                        | [{action_name: site_sync, enabled: "1"}]   |
      Then wp_options "power_partner_settings" 被更新

  Rule: 後置（狀態）- 若 disable_site_after_n_days 變更，重新排程所有 pending 關站排程

    Example: 關站天數從 7 變為 14
      Given 原設定 disable_site_after_n_days 為 7
      And 有 2 個 pending 的關站排程
      When 管理員將 disable_site_after_n_days 改為 14
      Then 所有 pending 的關站排程被重新計算為 14 天後

  Rule: 後置（狀態）- 若 Email 排程相關設定變更，異步重新排程所有訂閱 Email

    Example: Email 模板的 days 或 operator 變更
      Given 原 Email 模板 action_name "next_payment" 的 days 為 "3"
      When 管理員將 days 改為 "7"
      Then 系統透過 ActionScheduler 異步執行 Email 重新排程
      And 取消所有現有 Email 排程
      And 對所有符合條件的訂閱重新建立排程
