@ignore @command
Feature: 首次付款建立授權碼

  訂閱首次付款成功後，根據商品設定的 linked_lc_products，
  呼叫 CloudServer API 建立授權碼（License Code），並綁定到訂閱。

  Background:
    Given 系統中有以下用戶：
      | userId | name     | email                | role       |
      | 10     | Customer | customer@example.com | subscriber |
    And 系統已設定 partner_id 為 "12345"
    And 系統中有以下訂閱商品：
      | productId | linked_lc_products                                       |
      | 100       | [{"product_slug": "power-course", "quantity": 2}]        |

  Rule: 前置（狀態）- 必須已設定 partner_id

    Example: 未設定 partner_id 時不建立授權碼
      Given 系統未設定 partner_id
      And 用戶 "Customer" 購買了商品 #100
      When WooCommerce 觸發 INITIAL_PAYMENT_COMPLETE hook
      Then 不會呼叫建立授權碼 API

  Rule: 前置（狀態）- 商品必須有設定 linked_lc_products

    Example: 商品未設定 linked_lc_products 時不建立授權碼
      Given 用戶 "Customer" 購買了未設定 linked_lc_products 的訂閱商品
      When WooCommerce 觸發 INITIAL_PAYMENT_COMPLETE hook
      Then 不會呼叫建立授權碼 API

  Rule: 後置（狀態）- 呼叫 CloudServer API 建立授權碼並綁定到訂閱

    Example: 客戶購買帶授權碼的訂閱商品
      Given 用戶 "Customer" 購買了商品 #100
      And 訂閱 #500 被創建
      When WooCommerce 觸發 INITIAL_PAYMENT_COMPLETE hook
      Then 系統呼叫 CloudServer API POST license-codes
      And 請求包含 product_slug: "power-course", quantity: 2, post_author: "12345"
      And 返回的 LC ID 被寫入訂閱 #500 的 lc_id meta
      And 訂閱 #500 的 linked_lc_products meta 被設定
      And 系統發送授權碼開通 Email 給 customer@example.com
