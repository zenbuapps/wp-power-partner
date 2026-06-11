@ignore @command
Feature: 排程訂閱生命週期Email

  根據訂閱的生命週期事件（TRIAL_END, END, NEXT_PAYMENT, SUBSCRIPTION_FAILED 等），
  排程延遲發送 Email。使用 ActionScheduler 排程，發送時間依 Email 模板設定的
  days 與 operator（before/after）計算。

  Background:
    Given 系統中有以下 Email 模板：
      | key   | enabled | action_name         | subject        | body          | days | operator |
      | key-1 | 1       | subscription_failed | 付款失敗通知   | 親愛的客戶... | 0    | after    |
      | key-2 | 1       | next_payment        | 即將扣款通知   | 將於...       | 3    | before   |
      | key-3 | 0       | end                 | 訂閱結束       | 您的...       | 0    | after    |
    And 系統中有以下訂閱：
      | subscriptionId | status    | linked_site_ids | next_payment        |
      | 500            | wc-active | site-a          | 2026-04-11 00:00:00 |

  Rule: 前置（狀態）- 只對開站訂閱（有 pp_linked_site_ids）排程 Email

    Example: 非開站訂閱不排程
      Given 訂閱 #600 沒有 pp_linked_site_ids meta
      When WooCommerce 觸發訂閱生命週期 hook
      Then 不會建立 Email 排程

  Rule: 前置（狀態）- 只排程 enabled 的 Email 模板

    Example: disabled 的 Email 模板不排程
      Given Email 模板 key-3 的 enabled 為 "0"
      When 訂閱 #500 觸發 END hook
      Then 不會為 key-3 建立排程

  Rule: 後置（狀態）- 依 action_name 匹配事件並建立 ActionScheduler 排程

    Example: 訂閱失敗時排程 subscription_failed Email
      Given 訂閱 #500 狀態為 wc-active
      When WooCommerce 觸發 SUBSCRIPTION_FAILED hook
      Then 系統為 key-1 建立 ActionScheduler 排程
      And 排程執行時間為「事件時間 + 0 天」
      And 排程 hook 為 "power_partner/3.1.0/email/scheduler"
      And 排程參數包含 email_key: "key-1", subscription_id: 500, action_name: "subscription_failed"

    Example: 排程 next_payment 提前 3 天通知
      Given 訂閱 #500 的 next_payment 為 2026-04-11 00:00:00
      When WooCommerce 觸發 WATCH_NEXT_PAYMENT hook
      Then 系統為 key-2 建立 ActionScheduler 排程
      And 排程執行時間為「2026-04-11 - 3 天 = 2026-04-08 00:00:00」

  Rule: 後置（狀態）- 訂閱恢復（→ active）時排程成功信並取消催繳信

    Example: 訂閱從 on-hold 恢復時排程 subscription_success Email
      Given 系統中有以下 Email 模板：
        | key   | enabled | action_name          | subject      | body          | days | operator |
        | key-4 | 1       | subscription_success | 續訂成功通知 | 感謝您續訂... | 0    | after    |
      And 訂閱 #500 有 pending 的 subscription_failed Email 排程
      When 訂閱 #500 狀態從 on-hold 變更為 active
      Then 系統取消所有 subscription_failed 的 pending Email 排程
      And 系統為 key-4 建立 ActionScheduler 排程
      And 排程執行時間至少距現在 10 分鐘
      And 排程 hook 為 "power_partner/3.1.0/email/scheduler"
      And 排程參數包含 email_key: "key-4", subscription_id: 500, action_name: "subscription_success"

    Example: pending → active（首次付款啟用）不排程成功信
      Given 訂閱 #500 狀態為 wc-pending
      When 訂閱 #500 狀態從 pending 變更為 active
      Then 不會為 subscription_success 建立排程

    Example: 離開 active（→ on-hold）時取消未寄出的成功信
      Given 訂閱 #500 有 pending 的 subscription_success Email 排程
      When 訂閱 #500 狀態從 active 變更為 on-hold
      Then 系統取消所有 subscription_success 的 pending Email 排程

    Example: WCS 自動續訂震盪（active→on-hold→active）最終只寄成功信
      Given 訂閱 #500 狀態為 wc-active
      And 系統中有催繳信（key-1）與成功信（key-4）模板
      When WCS 觸發自動續訂流程（active → on-hold → active）
      Then 催繳信排程被建立後隨即被取消
      And 最終只有成功信排程存在

    Example: 寄送當下複查狀態 — 訂閱已非 active 時跳過
      Given 訂閱 #500 的成功信排程已到期，但訂閱當下狀態為 on-hold
      When ActionScheduler 執行成功信排程回調
      Then 系統跳過發送並記錄 log
      And 不發送 Email

  Rule: 後置（狀態）- 排程到期後，發送 Email 並進行 Token 替換

    Example: 排程到期發送 Email
      Given 排程到期，參數為 email_key: "key-1", subscription_id: 500
      When ActionScheduler 執行排程回調
      Then 系統從設定讀取 key-1 的 Email 模板
      And 取得訂閱 #500 的最後一筆訂單
      And 將 ##TOKEN## 格式的佔位符替換為實際值
      And 發送 Email 到訂閱客戶的 email
