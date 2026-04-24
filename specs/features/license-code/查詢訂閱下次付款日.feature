@ignore @query
Feature: 查詢訂閱下次付款日

  公開 API，查詢指定訂閱的下次付款時間（Unix timestamp）。
  無權限限制。

  Background:
    Given 系統中有以下訂閱：
      | subscriptionId | status    | next_payment        |
      | 500            | wc-active | 2026-04-11 00:00:00 |
      | 501            | wc-active | 2026-05-01 00:00:00 |

  Rule: 前置（參數）- ids 為必填且需為陣列

    Example: ids 不是陣列時回傳錯誤
      When 用戶發送 GET /wp-json/power-partner/subscriptions/next-payment?ids=500
      Then 回應為錯誤 "訂閱 id 須為陣列"

  Rule: 後置（回應）- 回傳每個訂閱的下次付款時間

    Example: 查詢多個訂閱的下次付款日
      When 用戶發送 GET /wp-json/power-partner/subscriptions/next-payment?ids[]=500&ids[]=501
      Then 回應狀態碼為 200
      And 回應包含：
        | id  | time       |
        | 500 | 1744329600 |
        | 501 | 1746057600 |
