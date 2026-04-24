# WooCommerce

## 描述
WooCommerce + Woo Subscriptions 系統。負責訂單與訂閱的生命週期管理，透過各類 WordPress action hook 觸發 Power Partner 的業務邏輯。

## 關鍵屬性
- 管理訂閱狀態變更（active/cancelled/on-hold/pending-cancel/expired）
- 透過 Powerhouse 外掛的統一 hook 觸發訂閱生命週期事件
- 核心 hook: `powerhouse/subscription/initial_payment_complete`, `powerhouse/subscription/subscription_failed`, `powerhouse/subscription/subscription_success`
