@ignore @command
Feature: PowerCloud前臺變更域名

  前臺客戶網站列表（新架構分頁 /my-websites/）讓客戶自助變更自己 PowerCloud 站台的網域。
  操作欄提供「變更域名」icon button（鉛筆 EditOutlined），點擊彈出 modal 輸入新網域，
  送出後呼叫本站 POST /power-partner/powercloud/sites/{websiteId}/domain，由 PHP 確認站台屬於該客戶後，
  呼叫 PowerCloud 外部 API PATCH /wordpress/{websiteId}/domain，body 為 { domain: <新網域> }。
  瀏覽器不持有 API key；SSL 重簽等由 PowerCloud 後端處理。

  Background:
    Given 系統已設定 PowerCloud API URL 為 "https://api.wpsite.pro"
    And 當前用戶 user_id 為 1
    And transient "power_partner_powercloud_api_key_1" 值為 "pk_test_123"
    And 當前客戶在前臺新架構網站列表，且站台屬於該客戶

  Rule: 前置（狀態）- 站台狀態必須為 running 才能變更域名

    Example: 站台運行中時可開啟變更域名
      Given 網站 websiteId 為 "ws-abc"，狀態為 "running"
      When 客戶檢視操作欄
      Then 「變更域名」按鈕為可點擊狀態

    Example: 站台已停止時變更域名被停用
      Given 網站 websiteId 為 "ws-abc"，狀態為 "stopped"
      When 客戶檢視操作欄
      Then 「變更域名」按鈕為 disabled 狀態

    Example: 站台建置中時變更域名被停用
      Given 網站 websiteId 為 "ws-abc"，狀態為 "creating"
      When 客戶檢視操作欄
      Then 「變更域名」按鈕為 disabled 狀態

    Example: 站台刪除中時變更域名被停用
      Given 網站 websiteId 為 "ws-abc"，狀態為 "deleting"
      When 客戶檢視操作欄
      Then 「變更域名」按鈕為 disabled 狀態

  Rule: 前置（參數）- 新網域必須為合法 FQDN 且不含 http(s):// 前綴

    Example: 新網域為空時操作失敗
      Given 網站 websiteId 為 "ws-abc"，狀態為 "running"
      And 客戶開啟變更域名 modal 但未輸入新網域
      When 客戶送出變更域名
      Then 操作失敗，錯誤為"請輸入新的 domain name"

    Example: 新網域含 http(s):// 前綴時操作失敗
      Given 網站 websiteId 為 "ws-abc"，狀態為 "running"
      And 客戶輸入新網域 "https://example.com"
      When 客戶送出變更域名
      Then 操作失敗，錯誤為"請輸入不含 http(s):// 的合格的網址"

    Example: 新網域格式不合法時操作失敗
      Given 網站 websiteId 為 "ws-abc"，狀態為 "running"
      And 客戶輸入新網域 "not a domain"
      When 客戶送出變更域名
      Then 操作失敗，錯誤為"請輸入不含 http(s):// 的合格的網址"

    Example: 繞過前臺表單直接呼叫本站 API 時由 PHP 擋下
      Given 網站 websiteId 為 "ws-abc"，狀態為 "running"
      When 客戶直接呼叫本站 API，新網域為 "a.com;id"
      Then 回應 400，錯誤代碼為 "invalid_domain"
      And 系統不呼叫 PowerCloud API

  Rule: 前置（權限）- 只能變更屬於自己的站台

    Example: 變更別人的站台被拒絕
      Given 網站 websiteId 為 "ws-other" 不屬於當前客戶
      When 客戶直接呼叫本站 API 變更該站台的網域
      Then 回應 403，錯誤代碼為 "not_owner"
      And 系統不呼叫 PowerCloud API

  Rule: 後置（互動）- 開啟 modal 時顯示動態 DNS A record 指向提醒

    Example: 開啟 modal 顯示帶站台 IP 的 DNS 提醒
      Given 網站 websiteId 為 "ws-abc"，狀態為 "running"，ipAddress 為 "1.2.3.4"
      When 客戶開啟變更域名 modal
      Then modal 顯示提醒"請先將網域 DNS 設定中的 A 紀錄 (A Record) 指向 1.2.3.4，再變更網域"
      And modal 顯示當前域名供對照

  Rule: 後置（狀態）- 送出合法新網域時呼叫 PowerCloud API PATCH /wordpress/{websiteId}/domain

    Example: 變更域名成功後列表更新並顯示成功明細
      Given 網站 websiteId 為 "ws-abc"，狀態為 "running"，當前域名為 "old.wpsite.pro"
      And 客戶輸入合法新網域 "new.example.com"
      When 客戶送出變更域名
      Then 系統呼叫 PowerCloud API PATCH /wordpress/ws-abc/domain，body 為 { domain: "new.example.com" }
      And API 由本站 PHP 以 X-API-Key header 認證，瀏覽器不帶 key
      And 重新整理列表（refetch）顯示新網域
      And 顯示成功通知"old.wpsite.pro 已成功變更為 new.example.com"

    Example: 變更域名送出後顯示進行中通知
      Given 網站 websiteId 為 "ws-abc"，狀態為 "running"，當前域名為 "old.wpsite.pro"
      And 客戶輸入合法新網域 "new.example.com"
      When 客戶送出變更域名
      Then 顯示進行中通知"正在將 old.wpsite.pro 變更為 new.example.com，網域變更有可能需要等待 2~3 分鐘左右的時間，請先不要關閉視窗"

    Example: PowerCloud API 回傳錯誤時顯示失敗明細
      Given 網站 websiteId 為 "ws-abc"，狀態為 "running"，當前域名為 "old.wpsite.pro"
      And 客戶輸入合法新網域 "new.example.com"
      And PowerCloud API 回傳錯誤
      When 客戶送出變更域名
      Then 顯示失敗通知"old.wpsite.pro 變更為 new.example.com 失敗"
      And 列表維持原網域不變

    Example: 沒有收到 PowerCloud 回應時不說失敗
      Given 網站 websiteId 為 "ws-abc"，狀態為 "running"，當前域名為 "old.wpsite.pro"
      And 客戶輸入合法新網域 "new.example.com"
      And 請求逾時，或本站回應 pp_upstream_unavailable
      When 客戶送出變更域名
      Then 顯示警告通知"new.example.com 的變更可能仍在處理中，請數分鐘後重新整理列表確認"
