@ignore @command
Feature: 關站排程編排整合測試（缺口 #3：DisableHooks 排程/取消/重啟編排層）

  把「訂閱失敗排程關站 → 訂閱恢復取消排程 → 訂閱恢復重新啟用網站」的編排層補上 PHP 整合測試。
  目標 class：J7\PowerPartner\Domains\Site\Core\DisableHooks
  目標 method：schedule_disable_site() / cancel_disable_site_schedule() / restart_all_stopped_sites_scheduler()
  既有 IT 引用：0（這三個編排方法全無）。

  範圍邊界（避免與既有重造）：
  - SiteDisableRoutingTest（issue #18, 10 tests）已覆蓋 DisableSiteScheduler::action_callback（被執行的關站 callback + host_type 路由）→ 不重造。
  - 本檔聚焦「排程是否被正確建立/取消」與「啟用路徑 restart 的逐站 enable + 失敗備註」。

  測試慣例（沿用既有，不引入新策略）：
  - HTTP：pre_http_request filter 攔截 wp_remote_*，記錄 last_request URL，回傳指定 status / WP_Error
  - 真訂閱：wcs_create_subscription + 父訂單 add_product + pp_linked_site_ids meta；skip_if_no_subscriptions() 守衛
  - 設定：transient power_partner_powercloud_api_key + option Connect::PARTNER_ID_OPTION_NAME
  - 觸發：直接呼叫 (new DisableHooks())->schedule_disable_site($subscription, []) 等方法
  - 排程斷言：透過 DisableSiteScheduler 的 as_* 排程查詢驗時間窗與存在性
  - 備註斷言：wc_get_order_notes
  - 測試檔落點：tests/Integration/DisableHooksSchedulingTest.php

  Background:
    Given 系統已設定 PowerCloud API key transient "power_partner_powercloud_api_key" 為 "test-api-key-123"
    And 系統已設定 partner_id option 為 "test-partner-001"
    And 系統中有以下外掛設定：
      | power_partner_disable_site_after_n_days |
      | 7                                       |

  Rule: 後置（狀態）- 訂閱失敗時建立「N 天後」的關站排程

    Example: 失敗後排程時間為當下 +7 天
      Given 訂閱 "SUB-SCHED" 連結網站 "ws-001" 且商品 host_type 為 "powercloud"
      When 系統執行 schedule_disable_site
      Then 系統建立一個關站排程
      And 該排程的執行時間約為當下 +7 天（容許 ±1 小時誤差）

  Rule: 後置（狀態）- 設定的天數改變時排程時間跟著改變

    Example: 設定改為 3 天時排程為當下 +3 天
      Given 外掛設定 power_partner_disable_site_after_n_days 為 3
      And 訂閱 "SUB-SCHED3" 連結網站 "ws-002" 且商品 host_type 為 "powercloud"
      When 系統執行 schedule_disable_site
      Then 該關站排程的執行時間約為當下 +3 天（容許 ±1 小時誤差）

  Rule: 後置（狀態）- 設定缺失時採用預設 7 天

    Example: 未設定天數時預設排程為當下 +7 天
      Given 外掛設定未含 power_partner_disable_site_after_n_days
      And 訂閱 "SUB-SCHEDDEF" 連結網站 "ws-003" 且商品 host_type 為 "powercloud"
      When 系統執行 schedule_disable_site
      Then 該關站排程的執行時間約為當下 +7 天（容許 ±1 小時誤差）

  Rule: 後置（狀態）- 重複失敗時先取消既有排程再建立新排程（不重複堆疊）

    Example: 第二次失敗時排程數量維持為 1
      Given 訂閱 "SUB-REPEAT" 已有一個 pending 的關站排程
      When 系統再次執行 schedule_disable_site
      Then 該訂閱的 pending 關站排程數量為 1

  Rule: 後置（狀態）- 訂閱恢復時取消 pending 的關站排程

    Example: 恢復後關站排程被取消
      Given 訂閱 "SUB-CANCEL" 已有一個 pending 的關站排程
      When 系統執行 cancel_disable_site_schedule
      Then 該訂閱沒有任何 pending 的關站排程

  Rule: 前置（狀態）- restart 找不到父訂單時記 error 並中止（不發 enable 請求）

    Example: 無父訂單時 restart 不發出 enable 請求
      Given 訂閱 "SUB-RNOPARENT" 沒有有效父訂單
      And 已掛載 HTTP mock
      When 系統執行 restart_all_stopped_sites_scheduler
      Then 不發出任何啟用網站的 HTTP 請求

  Rule: 後置（狀態）- restart 對有 pp_linked_site_ids 的 PowerCloud 站逐站呼叫 enable

    Example: PowerCloud 站恢復時打 start endpoint
      Given 訂閱 "SUB-RPC" 連結網站 "ws-100" 且商品 host_type 為 "powercloud"
      And HTTP mock 回傳 HTTP 200
      When 系統執行 restart_all_stopped_sites_scheduler
      Then 發出的 HTTP 請求 URL 指向 PowerCloud "/wordpress/ws-100/start"

  Rule: 後置（狀態）- restart 對 WPCD 站（空 host_type + 數字 id）走 CloudServer enable（issue #18 對齊）

    Example: 舊 WPCD 站恢復時走 CloudServer 而非 PowerCloud
      Given 訂閱 "SUB-RWPCD" 連結網站 "1376977" 且商品 host_type 為空
      And HTTP mock 回傳 HTTP 200
      When 系統執行 restart_all_stopped_sites_scheduler
      Then 發出的 HTTP 請求 URL 指向 CloudServer enable API
      And 不指向 PowerCloud "/wordpress/1376977/start"

  Rule: 後置（狀態）- restart 啟用失敗時寫「重新啟用網站失敗」備註（issue #18 啟用失敗回報）

    Example: PowerCloud 啟用回傳 500 時寫失敗備註
      Given 訂閱 "SUB-RFAILPC" 連結網站 "ws-200" 且商品 host_type 為 "powercloud"
      And HTTP mock 回傳 HTTP 500
      When 系統執行 restart_all_stopped_sites_scheduler
      Then 訂閱備註含「重新啟用網站失敗」且含 "ws-200"

  Rule: 後置（狀態）- restart 無 pp_linked_site_ids 時 fallback 從 order item meta 取 websiteId（相容舊資料）

    Example: 舊資料訂閱從 order item meta 取 websiteId 啟用
      Given 訂閱 "SUB-RFALLBACK" 沒有 pp_linked_site_ids
      And 其 order item meta "_pp_create_site_responses_item" 含 websiteId "ws-300"
      And HTTP mock 回傳 HTTP 200
      When 系統執行 restart_all_stopped_sites_scheduler
      Then 發出的 HTTP 請求 URL 指向 PowerCloud "/wordpress/ws-300/start"
