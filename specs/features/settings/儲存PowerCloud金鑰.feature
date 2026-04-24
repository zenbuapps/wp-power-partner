@ignore @command
Feature: 儲存PowerCloud金鑰

  管理員儲存 PowerCloud API Key，用於新架構的開站認證。

  Background:
    Given 系統中的 PowerCloud API Key transient 為空

  Rule: 前置（狀態）- 使用者需已登入且有 manage_options 權限

    Example: 未登入用戶
      Given 當前用戶未登入
      When 用戶嘗試儲存 PowerCloud 金鑰
      Then 操作失敗，錯誤為「User not authenticated」，回傳 401

  Rule: 前置（參數）- api_key 不可為空

    Example: api_key 為空
      Given 當前用戶為管理員
      When 用戶發送儲存 PowerCloud 金鑰，api_key 為空
      Then 操作失敗，錯誤為「api_key is required」，回傳 400

  Rule: 後置（狀態）- transient 以 user_id 區分儲存，TTL 30 天

    Example: 儲存成功
      Given 當前用戶為管理員，user_id 為 1
      When 用戶發送儲存 PowerCloud 金鑰，api_key 為 "pk_test_123"
      Then transient "power_partner_powercloud_api_key_1" 值為 "pk_test_123"
      And transient TTL 為 30 天
