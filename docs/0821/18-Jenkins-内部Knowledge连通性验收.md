# Jenkins 内部 Knowledge 连通性验收

CI 机上的 Knowledge API 容器默认只绑定宿主机 `127.0.0.1:3110`，因此 Jenkins 容器不能通过公网 `knowledge.dofe.ai` 访问。GEOFlow 与 Knowledge 加入同一受控 Docker 网络后，使用服务名直连：

```dotenv
KNOWLEDGE_INTERNAL_API_URL=http://dofe-knowledge-api:3110
KNOWLEDGE_INTERNAL_SSO_ISSUER=<SSO 在该网络中的内部 issuer；没有则留空>
GEOFLOW_OUTBOUND_PRIVATE_TARGETS=dofe-knowledge-api:3110
KNOWLEDGE_READ_MODE=primary
```

内部 API 不应加入公网 Nginx，也不应把数据库、Redis 或 RabbitMQ 服务复制到 GEOFlow Compose。`KNOWLEDGE_INTERNAL_API_URL` 只改变访问路径，鉴权仍使用 Knowledge SSO client credentials；若 SSO 没有内部入口，则保留 `KNOWLEDGE_SSO_ISSUER=https://sso.ixicai.cn/api`，并确保 CI 机具备该出口。

在 Jenkins 部署阶段执行：

```bash
docker compose --env-file "$ENV_FILE" -f docker-compose.prod.yml config --quiet
docker compose --env-file "$ENV_FILE" -f docker-compose.prod.yml exec -T app \
  php artisan geoflow:knowledge-check --json
```

验收必须同时满足：API/SSO 配置完整、服务令牌获取成功、Knowledge 搜索返回合法 `list`。任一步失败，文章生成与 GEO 诊断均应失败并进入队列重试；不允许用本地 `knowledge_bases/knowledge_chunks` 或模板 fallback 冒充成功。
