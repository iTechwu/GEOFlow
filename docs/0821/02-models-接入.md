# models.dofe.ai 接入说明

## 配置

```dotenv
MODELS_BASE_URL=https://models.dofe.ai/v1
MODELS_API_KEY=<由 models 项目签发的服务密钥>
```

两个变量必须同时设置。GEOFlow 会把它们注册为 Laravel AI 的运行时 Provider，模型名称仍来自 `ai_models.model_id`，因此可以在 models 中切换 alias，而无需重新构建 GEOFlow 镜像。

## 请求路径

- Chat：`POST {MODELS_BASE_URL}/chat/completions`
- Embedding：`POST {MODELS_BASE_URL}/embeddings`
- 模型列表和协议能力由 models 项目维护；GEOFlow 不复制 Provider 配置。

生产环境使用 `/v1` 公共 OpenAI 兼容入口和服务密钥。若 models 为 GEOFlow 分配专用租户，可在后续版本切换到 `/internal/v1`，由调用方实现 HMAC `Authorization: Bearer <timestamp>:<hmac>`、`x-service-name`、`x-team-id`，并把 Provider API key 单独放在 `x-api-key`；本次没有伪造内部签名客户端。

## 迁移步骤

1. 在 models 项目创建 GEOFlow 专用 team/API key，并限制可见模型与额度。
2. 将 `MODELS_BASE_URL`、`MODELS_API_KEY` 写入 CI 机密存储，不写入镜像、Git 或日志。
3. 在 GEOFlow 管理后台的 AI 模型表中，仅维护 models 的公开 alias 和类型（chat/embedding）。API key 字段留空。
4. 先执行后台“测试连接”，再执行一条标题生成和一次知识库 Embedding 更新。
5. 观察 models 的用量与 GEOFlow 的 `storage/logs`，确认没有请求回退到旧 ixicai key。

## 失败策略

- 无可用 models 配置时，保留当前按管理员 ixicai key 的兼容路径。
- models 请求失败时，标题和知识库流程继续使用现有 fallback；正文 Worker 按现有队列失败/重试策略处理。
- 不在 GEOFlow 日志记录 API key、完整 Authorization、Prompt 原文或模型上游响应中的敏感字段。
