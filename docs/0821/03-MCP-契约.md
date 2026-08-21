# GEOFlow MCP 契约

## 端点与鉴权

- URL：`POST /mcp`
- 协议：JSON-RPC 2.0，Streamable HTTP 兼容的无状态请求响应模式
- 鉴权：`Authorization: Bearer <token>`，支持两个部署级令牌：
  - `GEOFLOW_MCP_TOKEN`：全量（读+写）令牌，等价于任务写权限；
  - `GEOFLOW_MCP_READ_TOKEN`（可选）：只读令牌，仅允许 `catalog` / `tasks.list` / `tasks.get`，调用写工具返回 `isError: true`。
- 开关：`GEOFLOW_MCP_ENABLED=true` 且至少配置一个 Token 时才开放；否则返回 404/401
- 建议由 CI 反向代理强制 HTTPS、来源 IP 白名单和请求体大小限制。

## 初始化示例

```json
{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}
```

响应声明 MCP `2025-06-18`、`tools` 能力和 `GEOFLOW_MCP_SERVER_NAME`。

## 工具

| 工具 | 作用 | 写入 |
| --- | --- | --- |
| `geoflow.catalog` | 读取模型、Prompt、素材库、知识库、作者和分类 | 否 |
| `geoflow.tasks.list` | 按状态/关键词分页读取任务及队列进度 | 否 |
| `geoflow.tasks.get` | 读取单任务监控详情 | 否 |
| `geoflow.tasks.start` | 激活任务，可立即入队一条生成任务 | 是 |
| `geoflow.tasks.stop` | 暂停任务并取消待处理工作 | 是 |
| `geoflow.tasks.enqueue` | 入队一条任务 Job | 是 |

写工具应由 CI Agent 做人工审批或策略审批；写 Token 等同于任务写权限。

### 幂等键

三个写工具都接受可选参数 `idempotency_key`（`[A-Za-z0-9][A-Za-z0-9._:-]{0,119}`）。
携带相同 `idempotency_key` 且参数一致的重试会命中缓存并返回首次结果，不会重复入队；
键相同但参数不同则返回 `isError: true` 的冲突错误。幂等缓存复用 `api_idempotency_keys` 表，
路由键为 `mcp:tools.call:<tool>`。

### 权限模型（任务所有权）

MCP 是部署级无状态令牌，不绑定 SSO 管理员，因此**不按 `sso_owner_admin_id` 隔离**：
持有写令牌即可读取与操作该部署内全部 GEOFlow 任务，等价于「部署管理员全局权限」。
这是有意的运维令牌模型；必须配合反向代理 IP 白名单、短期令牌轮换和 CI 策略审批使用。
如后续需要按租户隔离，应在令牌声明中携带服务账号/可操作任务范围，并在 Service 层校验。

### 审计

写工具每次调用写入 `mcp_audit_logs`：令牌 SHA-256 指纹、作用域、工具名、目标 `task_id`、
幂等键、结果（success/error）、请求 IP，以及 `task_id`/`enqueue_now`/`job_type` 白名单字段与
参数整体 SHA-256 哈希。不落原始 Bearer Token，也不落 `payload`/Prompt/正文原文。

## 冒烟

```bash
curl -fsS "$GEOFLOW_URL/mcp" \
  -H "Authorization: Bearer $GEOFLOW_MCP_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'

curl -fsS "$GEOFLOW_URL/mcp" \
  -H "Authorization: Bearer $GEOFLOW_MCP_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'
```
