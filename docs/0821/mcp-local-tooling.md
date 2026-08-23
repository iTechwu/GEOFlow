# 本地 MCP 工具测试部署

## 目的

用于验证 AI 员工通过外部 MCP 工具读取 GEOFlow 页面、处理本地文件、保存 GEO 研究记忆、进行结构化思考和处理时区信息。服务以官方 MCP stdio 镜像运行，不加入 GEOFlow 生产 Compose，也不创建 PostgreSQL、Redis 或 RabbitMQ。

## 候选来源

| 项目 | GitHub stars（2026-08-23 检索） | 本地用途 | 选择结论 |
| --- | ---: | --- | --- |
| [modelcontextprotocol/servers](https://github.com/modelcontextprotocol/servers) | 89k+ | 官方参考服务集合 | 采用 fetch、filesystem、memory、sequentialthinking、time |
| [browserbase/mcp-server-browserbase](https://github.com/browserbase/mcp-server-browserbase) | 3.4k+ | 浏览器控制 | 暂不采用；依赖外部 Browserbase 账号，不是真本地部署 |
| [mark3labs/mcp-filesystem-server](https://github.com/mark3labs/mcp-filesystem-server) | 679 | Go 文件系统服务 | 作为替代实现候选，当前官方 filesystem 已满足需求 |
| [memory-graph/memory-graph](https://github.com/memory-graph/memory-graph) | 235 | 图谱记忆 | 暂不采用；需要额外数据库与更大测试面 |

Skill 检索结果中，[internet-court-skill](https://github.com/internet-court/internet-court-skill)（约 4.4k stars）面向代理间商业授权，[awesome-korean-agent-skills](https://github.com/J-nowcow/awesome-korean-agent-skills)（约 35 stars）是技能目录，[skills-manager](https://github.com/EfanWang/skills-manager)（约 30 stars）是技能安装管理器；它们都不是 GEO 内容工作流的低风险运行时依赖，因此本轮不把它们安装进项目或 Docker 镜像。浏览器测试继续使用本机已有的隔离浏览器 skill，避免把外部账号和浏览器会话引入测试服务。

## 启动与回归

先确认 GEOFlow 已运行于 `http://127.0.0.1:18080`，并拉取官方镜像：

```bash
docker pull mcp/fetch
docker pull mcp/filesystem
docker pull mcp/memory
docker pull mcp/sequentialthinking
docker pull mcp/time
```

运行 stdio MCP smoke：

```bash
node deploy-scripts/mcp-local-smoke.mjs
```

脚本为每次测试创建带随机后缀的容器名，并在成功、失败或中断时执行 `docker rm -f`；临时数据只写入 `/tmp/geoflow-mcp-local-<pid>`。fetch 使用 Docker host network 访问 `127.0.0.1:18080`，因此会保留 GEOFlow 的 Host 校验，不通过修改 Host 白名单绕过安全边界。

可替换 fetch 地址进行其他本地页面回归：

```bash
MCP_LOCAL_FETCH_URL=http://127.0.0.1:18080/up node deploy-scripts/mcp-local-smoke.mjs
```

## 当前验证范围

- fetch：真实读取 GEOFlow 首页并返回 Markdown
- filesystem：仅允许访问临时 `/projects` 根目录
- memory：写入临时 JSONL，不使用 PostgreSQL/Redis
- sequentialthinking：完成单步结构化思考调用
- time：返回 `Asia/Shanghai` 当前时间
- 每个服务均验证 `initialize`、`notifications/initialized`、`tools/list` 和至少一个真实工具调用

## 安全边界

- 不把 filesystem 根目录指向仓库、`.env`、Docker socket 或宿主机根目录。
- 不把官方 stdio 服务加入 GEOFlow 生产 Compose；AI 员工连接配置应由测试客户端单独管理。
- fetch 只用于本机测试，不能据此放宽 `EnsureTrustedForwardedHost` 或任意内网访问策略。
- Docker Hub 镜像应在 CI 中锁定 digest；本机 `latest` 仅用于开发验证。
