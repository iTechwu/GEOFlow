# Docker 真实环境回归记录

## 结论

- 验收时间：2026-08-22（Asia/Shanghai）。
- 运行入口：`http://127.0.0.1:18080`。
- 结论：本机 Docker 运行链路可用，GEOFlow MCP 读写与文章工作流通过真实 HTTP 回归；生产发布仍需在 CI 机按受保护流程执行。

## 部署边界

- PostgreSQL、Redis、RabbitMQ 使用 `common_network` 上由 `../docker-helm.dofe.ai` 管理的外部容器，GEOFlow Compose 未创建、初始化或持有这些依赖的 volume。
- 生产服务：`geoflow-web-prod`、`geoflow-app-prod`、`geoflow-queue-prod`、`geoflow-scheduler-prod`、`geoflow-reverb-prod`；`geoflow-init-prod` 仅执行迁移/启动检查并成功退出。
- 本机为 arm64，镜像按现有 PHP/Node 基础镜像构建为 amd64 并由 Docker emulation 运行。CI 机应使用目标 amd64 builder，避免仿真性能损耗。

## 本轮修复

1. 为 app、queue、scheduler、reverb 补充 `.env.prod` 运行时注入，避免 Compose 服务缺少外部 Redis/数据库配置。
2. 生产环境的 Redis 连接使用 `dofe-redis`，不再使用容器内 `127.0.0.1`。
3. 镜像构建阶段移除不带运行时环境的 config/routes 缓存，并在入口重新生成配置缓存。
4. 统一应用源码为目录 `755`、文件 `644`，修复宿主机 `0600` 文件导致 PHP-FPM 无法读取的问题。

## MCP 真实回归

认证后的 `initialize`、`tools/list` 与 `geoflow.catalog` 均返回 200。临时测试数据在外部 PostgreSQL 中创建并在回归结束后按主键清理，最终 catalog、tasks、articles 均为空。

| 流程 | 结果 |
| --- | --- |
| 任务 create/get | 通过 |
| 任务 start/stop | 通过 |
| 任务 enqueue | 通过；空标题库被业务校验拒绝并回收 pending job |
| 文章 create/get/update | 通过 |
| 文章 review/publish/list/trash | 通过 |
| MCP PHPUnit | 19 tests、46 assertions 全部通过 |

## 浏览器回归

- AgentSpace 本机页面使用隔离 Playwright 浏览器打开，创建了 `GEO 管理智能体`，备注名为 `GEO Manager`，工作说明明确要求通过 GEOFlow MCP 完成任务、文章审核和发布；页面显示创建成功且无 console error/失败网络请求。
- GEOFlow 首页在 1440×1000 与 390×844 视口均返回 200，页面无水平溢出。
- GEOFlow admin 登录入口在没有 SSO 管理员会话时按设计重定向到 SSO；本机没有伪造生产 SSO 回调。浏览器观测到的证书错误来自外部 SSO 资源，不影响 GEOFlow 首页或 MCP HTTP 请求。
- 当前会话未提供 Chrome DevTools MCP 服务，因此浏览器证据使用 Playwright Chromium 采集，包含截图、console、HTTP 状态和布局指标。

## 后续放行条件

1. CI 机使用 amd64 builder 重新构建并推送不可变 digest。
2. 按既有 CI/发布流程执行迁移、健康检查和 SSO 回调验证；本机不启动 Jenkins。
3. 为真实环境准备有效 AI 模型与标题库后，再执行生成文章队列的成功路径；本轮已验证空标题库时的 fail-closed 行为。
