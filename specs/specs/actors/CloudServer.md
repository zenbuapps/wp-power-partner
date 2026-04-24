# CloudServer

## 描述
cloud.luke.cafe (WPCD 舊架構) 外部系統。提供 WordPress 網站的建立、停用、啟用 API，以及授權碼（License Code）的建立、過期、恢復、更新、刪除 API。

## 關鍵屬性
- API Base URL: https://cloud.luke.cafe
- 認證方式: Basic Auth (username:password base64 encoded)
- 提供 REST API endpoints: site-sync, disable-site, enable-site, template-sites
- 授權碼 API 由 Powerhouse 外掛的 CloudApi 代理呼叫
- WPCD 開站完成後會回調 Power Partner 的 customer-notification 和 link-site API
