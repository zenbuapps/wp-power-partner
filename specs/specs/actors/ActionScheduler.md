# ActionScheduler

## 描述
WordPress Action Scheduler 系統。負責延遲執行排程任務，包括寄信、關站、停用授權碼等。

## 關鍵屬性
- 使用 WordPress Action Scheduler 函式庫
- 支援單次排程 (as_schedule_single_action)、異步排程 (as_enqueue_async_action) 與定期排程 (as_schedule_recurring_action)
- 排程 hooks: power_partner/3.1.0/site/disable, power_partner/3.1.0/lc/expire, power_partner/3.1.0/email/scheduler
- 定期排程 hook: power_partner/3.4.0/billing/daily-push（每日 UTC+8 05:00 推送新架構計費資料）
- 每個排程任務帶有參數 (subscription_id 等) 用於回調時查詢資源
- 定期排程註冊前須以 as_next_scheduled_action() 守衛，避免重複註冊
