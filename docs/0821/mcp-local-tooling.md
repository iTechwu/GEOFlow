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

当前已验证 digest：

```bash
docker pull mcp/fetch@sha256:1a7a0996a565a0b8ca5c41b42830d4e5f334d33f851596bbd9debb2beedb22d3
docker pull mcp/filesystem@sha256:35fcf0217ca0d5bf7b0a5bd68fb3b89e08174676c0e0b4f431604512cf7b3f67
docker pull mcp/memory@sha256:db0c2db07a44b6797eba7a832b1bda142ffc899588aae82c92780cbb2252407f
docker pull mcp/sequentialthinking@sha256:cd3174b2ecf37738654cf7671fb1b719a225c40a78274817da00c4241f465e5f
docker pull mcp/time@sha256:9c46a918633fb474bf8035e3ee90ebac6bcf2b18ccb00679ac4c179cba0ebfcf
```

运行 stdio MCP smoke：

```bash
node deploy-scripts/mcp-local-smoke.mjs
```

脚本默认使用随机临时根目录，并为每次测试创建带随机后缀的容器名；成功、失败或 `SIGINT`/`SIGTERM` 中断时都会执行 `docker rm -f` 并清理默认临时目录。显式设置 `MCP_LOCAL_ROOT` 时目录由调用方负责管理。默认镜像按已验证 digest 锁定，可通过 `MCP_FETCH_IMAGE`、`MCP_FILESYSTEM_IMAGE`、`MCP_MEMORY_IMAGE`、`MCP_SEQUENTIALTHINKING_IMAGE`、`MCP_TIME_IMAGE` 覆盖。fetch 使用 Docker host network 访问 `127.0.0.1:18080`，因此会保留 GEOFlow 的 Host 校验，不通过修改 Host 白名单绕过安全边界。

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
