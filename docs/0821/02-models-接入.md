# models.dofe.ai 接入说明

## 配置

```dotenv
MODELS_BASE_URL=https://models.dofe.ai/v1
MODELS_API_KEY=<由 models 项目签发的服务密钥>
# 可选：models 管理端点的服务间 HMAC；启用 geoflow:models-internal-check 时必须配置
MODELS_INTERNAL_BASE_URL=https://models.dofe.ai
MODELS_INTERNAL_API_SECRET=<models 内部服务密钥>
MODELS_SERVICE_NAME=geoflow

# Jenkins 与 Knowledge 在同一 Docker 网络时使用；不配置时回退到公网 API。
KNOWLEDGE_INTERNAL_API_URL=http://dofe-knowledge-api:3110
# 若 SSO 也有内部服务名，可配置；否则沿用 KNOWLEDGE_SSO_ISSUER。
KNOWLEDGE_INTERNAL_SSO_ISSUER=<可选的内部 SSO issuer>
```

`MODELS_BASE_URL` 与 `MODELS_API_KEY` 两个公共数据面变量必须同时设置。GEOFlow 会把它们注册为 Laravel AI 的运行时 Provider，模型名称仍来自 `ai_models.model_id`，因此可以在 models 中切换 alias，而无需重新构建 GEOFlow 镜像。管理面变量是可选的，仅用于 `/internal/*` 检查和后续管理能力。

生产环境必须使用 HTTPS。仅当 CI 同机验收必须连接固定本地服务时，才可同时设置 `GEOFLOW_MODELS_ALLOW_INSECURE_LOCAL=true`，并将该服务的精确 `host:port` 加入 `GEOFLOW_OUTBOUND_PRIVATE_TARGETS`。专用开关只接受 `127.0.0.1`、`host.docker.internal` 或 `dofe-models-api-local`，统一出站策略仍会解析并校验目标地址；两项配置缺一不可。

## 请求路径

- Chat：`POST {MODELS_BASE_URL}/chat/completions`
- Embedding：`POST {MODELS_BASE_URL}/embeddings`
- 管理端点：`GET {MODELS_INTERNAL_BASE_URL}/internal/models`，使用服务间 HMAC 和 `x-service-name`。
- 模型列表和协议能力由 models 项目维护；GEOFlow 不复制 Provider 配置。
- 所有管理端点请求通过 GEOFlow 的 `SafeOutboundHttpClient`；若 `MODELS_INTERNAL_BASE_URL` 是私网地址，必须在 `GEOFLOW_OUTBOUND_PRIVATE_TARGETS` 中按精确 `host:port` 放行。

生产环境的数据面使用 `/v1` 公共 OpenAI 兼容入口和 `MODELS_API_KEY`。models 的 `/internal/*` 是管理面，当前由 `ModelsInternalClient` 访问模型目录并提供连通性检查；不要把 `/internal/*` 配置为聊天或 Embedding Provider 地址。

## 迁移步骤

1. 在 models 项目创建 GEOFlow 专用 API key，并限制可见模型与额度；如需管理面检查，再创建内部 HMAC 密钥。
2. 将 `MODELS_BASE_URL`、`MODELS_API_KEY`、`MODELS_INTERNAL_BASE_URL`、`MODELS_INTERNAL_API_SECRET` 写入 CI 机密存储，不写入镜像、Git 或日志。
3. 在 GEOFlow 管理后台的 AI 模型表中，仅维护 models 的公开 alias 和类型（chat/embedding）。API key 字段留空。
4. 先执行后台“测试连接”，再执行一条标题生成和一次知识库 Embedding 更新。
5. 在 app 容器执行 `php artisan geoflow:models-internal-check` 验证管理面 HMAC；需要查看目录时追加 `--list`。
6. 观察 models 的用量与 GEOFlow 的 `storage/logs`，确认没有请求回退到旧 ixicai key。

## 失败策略

- 无可用 models 公共配置时，相关生成任务失败并按队列重试；不回退到旧 ixicai key。
- Knowledge API、SSO 或 models 任一必需依赖不可达时，文章生成和 GEO 诊断 fail closed；不得使用本地知识表或 fallback 内容冒充成功。
- 不在 GEOFlow 日志记录 API key、完整 Authorization、Prompt 原文或模型上游响应中的敏感字段。
