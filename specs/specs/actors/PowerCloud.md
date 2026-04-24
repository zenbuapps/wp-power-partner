# PowerCloud

## 描述
api.wpsite.pro (新架構) 外部系統。提供 Kubernetes 容器化 WordPress 網站的建立、停止、啟動 API，以及模板站列表和開站方案列表 API。

## 關鍵屬性
- API Base URL: https://api.wpsite.pro
- 認證方式: X-API-Key header (使用者的 PowerCloud API Key，存於 transient)
- 提供 REST API endpoints: /wordpress (建站), /wordpress/{id}/stop (停止), /wordpress/{id}/start (啟動)
- 模板站 API: /templates/wordpress
- 開站方案 API: /website-packages
- 開站為同步回應，不需回調
