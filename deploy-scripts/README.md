# GEOFlow 部署脚本 / Deployment Scripts

这个目录用于存放 GEOFlow 的参考部署脚本，方便技术人员在常见云服务器、VPS、Docker 主机或面板服务器上快速完成环境自检和生产部署。

脚本默认走仓库现有的 `docker-compose.prod.yml` 生产链路，不绕开项目标准部署方式。

## 脚本清单

| 脚本 | 用途 |
| --- | --- |
| `geoflow-docker-deploy.sh` | 生产 Docker 首次空库一键部署脚本。会自检服务器、准备 `.env.prod`、连接外部 PostgreSQL/Redis、部署 Web、App、队列、调度和 Reverb，并在最后执行健康检查。 |
| `geoflow-healthcheck.sh` | 部署后健康检查脚本。可单独检查容器状态、Laravel 健康端点和数据库连接。 |
| `build-and-push-amd64-images.sh` | 用 CI Secret 登录镜像仓库，构建并推送带不可变版本标签的 amd64 生产镜像。 |
| `deploy-prebuilt-release.sh` | CI 机已有数据实例的停机排空发布：拉取不可变镜像、迁移、readiness、安全审计、启动与最终验收。 |
| `render-prod-env.sh` | 从模板和 CI Secret 白名单生成权限为 `0600` 的 `.env.prod`，不打印机密值。 |
| `start-docker-pull-tunnel.sh` | **本机 Mac**：SSH 反向隧道，把 Clash HTTP 代理暴露给 ECS。 |
| `pull-images-once-via-tunnel.sh` | **ECS 一次性拉镜像**：经隧道 + `skopeo`，**不重启 docker**，不影响运行中容器。 |
| `build-once-via-tunnel.sh` | **ECS 一次性 build**：临时代理仅作用于本次 `docker compose build`，**不重启 docker**。 |
| `sync-images-from-local.sh` | **本机 Mac**：本机 `docker pull` 后 `ssh docker load` 到 ECS，无需隧道、无需改 ECS Docker。 |

## 推荐服务器配置

测试最低配置：

- 2 核 CPU
- 2 GB 内存，建议额外配置 2 GB swap
- 20 GB 可用磁盘
- Ubuntu 22.04+ / Debian 12+ / Rocky Linux 9+ / Alibaba Cloud Linux 3+
- 可以稳定访问 Docker 镜像源、GitHub 和你配置的 AI API 服务商

正式生产建议：

- 2-4 核 CPU
- 4-8 GB 内存
- 40-80 GB SSD
- 使用 Nginx、Caddy、宝塔、1Panel、SLB 或 CDN 做 HTTPS 反向代理
- PostgreSQL 和 Redis 不直接暴露到公网

## 一键部署

仅在全新空数据库的服务器执行：

```bash
curl -fsSL https://raw.githubusercontent.com/yaojingang/GEOFlow/main/deploy-scripts/geoflow-docker-deploy.sh -o geoflow-docker-deploy.sh
bash geoflow-docker-deploy.sh
```

已有数据的实例禁止使用该脚本升级，也禁止滚动升级。请执行 `docs/deployment/DEPLOYMENT.md` 3.1 节的 down、停止并排空全部旧进程和在途请求、一次性确认、迁移、全量启动新版本、readiness、启用删除门禁流程。

脚本会要求确认：

- 对外访问的 `APP_URL`
- Web 端口，默认 `18080`
- Reverb 端口，默认 `18081`
- 后台入口路径，默认 `geo_admin`

部署完成后：

- 前台：`APP_URL`
- 后台：`APP_URL/geo_admin/login`
- 默认管理员：`admin`
- 默认密码：`password`

首次登录后请立即修改默认密码。

## 非交互部署

适合云服务器初始化脚本、镜像模板或 CI：

```bash
GEOFLOW_NONINTERACTIVE=1 \
GEOFLOW_APP_URL=https://example.com \
GEOFLOW_APP_DIR=/opt/geoflow \
GEOFLOW_WEB_PORT=18080 \
GEOFLOW_REVERB_PORT=18081 \
GEOFLOW_ADMIN_BASE_PATH=geo_admin \
bash geoflow-docker-deploy.sh
```

常用变量：

| 变量 | 默认值 | 说明 |
| --- | --- | --- |
| `GEOFLOW_REPO_URL` | `https://github.com/yaojingang/GEOFlow.git` | 源码仓库地址 |
| `GEOFLOW_BRANCH` | `main` | 部署分支 |
| `GEOFLOW_APP_DIR` | `/opt/geoflow` | 服务器部署目录 |
| `GEOFLOW_INSTALL_DOCKER` | `auto` | `1` 自动安装 Docker；`0` 缺少 Docker 时直接失败 |
| `GEOFLOW_DB_HOST` | `dofe-postgres` | 外部 PostgreSQL 主机，由 docker-helm 提供 |
| `GEOFLOW_REDIS_HOST` | `dofe-redis` | 外部 Redis 主机，由 docker-helm 提供 |
| `GEOFLOW_DB_PASSWORD` | 必填 | 外部 PostgreSQL 密码，不由本脚本创建 |
| `GEOFLOW_REDIS_PASSWORD` | 必填 | 外部 Redis 密码，不由本脚本创建 |
| `MODELS_API_KEY` | 必填 | models.dofe.ai 服务密钥 |
| `MODELS_CHAT_SMOKE_MODEL` | 必填 | 部署验收使用的 models Chat 模型 ID |
| `MODELS_EMBEDDING_SMOKE_MODEL` | 必填 | 部署验收使用的 models Embedding 模型 ID |
| `MODELS_INTERNAL_BASE_URL` | `https://models.dofe.ai` | models 管理面地址 |
| `MODELS_INTERNAL_API_SECRET` | 必填 | models 管理面服务间 HMAC 密钥 |
| `GEOFLOW_MCP_ENABLED` | `.env.prod` 默认值 | 是否开放 `/mcp`；开启时必须同时提供 Token |
| `GEOFLOW_MCP_TOKEN` | MCP 开启时必填 | MCP 全量（读+写）Bearer Token |
| `GEOFLOW_MCP_READ_TOKEN` | 可选 | MCP 只读 Bearer Token（仅 catalog / tasks.list / tasks.get） |
| `GEOFLOW_TRUSTED_PROXIES` | `*` | 反向代理、CDN、二级目录部署时的可信代理设置 |
| `GEOFLOW_SELF_DELETE` | `0` | 设置为 `1` 时，部署成功后删除当前执行的部署脚本 |

## CI 构建生产镜像

```bash
REGISTRY=registry.example.com \
NS=geo_flow \
VERSION="$GITHUB_SHA" \
REGISTRY_USERNAME="$REGISTRY_USERNAME" \
REGISTRY_PASSWORD="$REGISTRY_PASSWORD" \
bash deploy-scripts/build-and-push-amd64-images.sh
```

默认只推送 `VERSION` 不可变标签，便于审计和回滚。仅在明确需要兼容浮动标签时设置 `PUSH_LATEST=1`。脚本通过 `--password-stdin` 登录，不会把密码写入命令行参数。

## CI 发布预构建镜像

先在 CI 机的部署目录准备不入库的 `.env.prod`，其中 `GEOFLOW_APP_IMAGE` 和 `GEOFLOW_WEB_IMAGE` 必须指向同一个版本的不可变标签或 digest。然后执行：

```bash
GEOFLOW_APP_DIR=/opt/geoflow \
bash /opt/geoflow/deploy-scripts/deploy-prebuilt-release.sh
```

该脚本只管理 GEOFlow 的 `app/web/queue/scheduler/reverb/init` 容器。它不会创建、停止或删除集中管理的 PostgreSQL、Redis、RabbitMQ 服务与卷。发布失败后应用保持维护模式，需处理失败原因后重跑；数据库迁移不能通过简单切回旧镜像自动回滚。

默认 `GEOFLOW_RELEASE_MODE=upgrade`，用于已有数据库的停机排空升级。仅在首次空库上线时显式设置 `GEOFLOW_RELEASE_MODE=fresh`；该模式要求全部 GEOFlow 常驻服务尚未运行，并将 fresh-install 确认严格限定到一次性 init 容器。

CI 可先将生产 Secret 映射为同名环境变量，再生成配置：

```bash
bash deploy-scripts/render-prod-env.sh /opt/geoflow/.env.prod
```

生成器会拒绝缺少 APP、外部 PostgreSQL/Redis、models 公共与管理面、SSO、Reverb、镜像和外部 Docker 网络配置的发布。值必须是单行字符串，生成过程不会输出 Secret。

## 执行后自删除

如果你把部署脚本下载到临时目录，部署成功后希望自动删除它：

```bash
GEOFLOW_SELF_DELETE=1 bash geoflow-docker-deploy.sh
```

这个动作只会删除当前执行的脚本文件，不会删除已部署的 GEOFlow 源码目录。

## 手动健康检查

部署后、改域名、改 HTTPS 或改反向代理后，可以执行：

```bash
cd /opt/geoflow
bash deploy-scripts/geoflow-healthcheck.sh
```

## 一级目录部署

如果网站部署在一级目录下，例如：

```text
https://example.com/wiki
```

建议配置：

```env
APP_URL=https://example.com/wiki
TRUSTED_PROXIES=*
ADMIN_BASE_PATH=geo_admin
```

不要把 `ADMIN_BASE_PATH` 写成 `wiki/geo_admin`。一级目录应由反向代理处理，并透传：

```nginx
proxy_set_header X-Forwarded-Prefix /wiki;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_set_header X-Forwarded-Host $host;
```

## 注意事项

- 脚本是部署辅助工具，不替代服务器安全加固。
- 不要把 PostgreSQL 和 Redis 暴露到公网。
- 更新前请备份 `.env.prod`、`storage/` 和 PostgreSQL 数据。
- 如果大陆服务器拉取 Docker 镜像较慢，建议先在 Docker daemon 层配置稳定镜像源，再执行脚本。

---

This folder contains reference scripts for technical operators who want a faster, repeatable GEOFlow deployment path.

## Scripts

| Script | Purpose |
| --- | --- |
| `geoflow-docker-deploy.sh` | First install on a fresh empty production database. It checks the server, prepares `.env.prod`, connects to externally managed PostgreSQL/Redis, deploys web, app, queue, scheduler and Reverb, then runs a healthcheck. |
| `geoflow-healthcheck.sh` | Post-deployment healthcheck. It validates Docker Compose services, the Laravel health endpoint and database connectivity. |
| `build-and-push-amd64-images.sh` | Logs in with CI secrets and publishes versioned amd64 production images. |
| `deploy-prebuilt-release.sh` | Performs a stopped-and-drained release of immutable prebuilt images with migration, readiness, audit and smoke gates. |
| `render-prod-env.sh` | Renders a mode-0600 production environment file from an allowlist of CI variables without printing secrets. |

## Recommended Server Profile

Minimum for testing:

- 2 vCPU
- 2 GB RAM plus 2 GB swap
- 20 GB free disk
- Ubuntu 22.04+/Debian 12+/Rocky Linux 9+/Alibaba Cloud Linux 3+
- Stable outbound network access to Docker registry, GitHub and your AI provider APIs

Recommended for production:

- 2-4 vCPU
- 4-8 GB RAM
- 40-80 GB SSD
- Reverse proxy or cloud load balancer for HTTPS
- PostgreSQL and Redis ports not exposed to the public Internet

## One-Command First Install

On a fresh server with an empty database, run:

```bash
curl -fsSL https://raw.githubusercontent.com/yaojingang/GEOFlow/main/deploy-scripts/geoflow-docker-deploy.sh -o geoflow-docker-deploy.sh
bash geoflow-docker-deploy.sh
```

Do not use this script to upgrade an existing deployment, and do not perform a rolling upgrade. Follow the stopped-and-drained upgrade protocol in section 3.1 of `docs/deployment/DEPLOYMENT.md`.

The script will ask for:

- Public `APP_URL`
- Web port, default `18080`
- Reverb port, default `18081`
- Admin base path, default `geo_admin`

After deployment:

- Site: `APP_URL`
- Admin: `APP_URL/geo_admin/login`
- Default username: `admin`
- Default password: `password`

Change the default admin password immediately after first login.

## Non-Interactive Deployment

For CI, image templates or scripted server initialization:

```bash
GEOFLOW_NONINTERACTIVE=1 \
GEOFLOW_APP_URL=https://example.com \
GEOFLOW_APP_DIR=/opt/geoflow \
GEOFLOW_WEB_PORT=18080 \
GEOFLOW_REVERB_PORT=18081 \
GEOFLOW_ADMIN_BASE_PATH=geo_admin \
bash geoflow-docker-deploy.sh
```

Optional variables:

| Variable | Default | Description |
| --- | --- | --- |
| `GEOFLOW_REPO_URL` | `https://github.com/yaojingang/GEOFlow.git` | Source repository URL |
| `GEOFLOW_BRANCH` | `main` | Branch to deploy |
| `GEOFLOW_APP_DIR` | `/opt/geoflow` | Server installation directory |
| `GEOFLOW_INSTALL_DOCKER` | `auto` | `1` to install Docker automatically, `0` to fail if Docker is missing |
| `GEOFLOW_DB_HOST` | `dofe-postgres` | External PostgreSQL host, provided by docker-helm |
| `GEOFLOW_REDIS_HOST` | `dofe-redis` | External Redis host, provided by docker-helm |
| `GEOFLOW_DB_PASSWORD` | required | External PostgreSQL password, not created by this script |
| `GEOFLOW_REDIS_PASSWORD` | required | External Redis password, not created by this script |
| `MODELS_API_KEY` | required | models.dofe.ai service key |
| `MODELS_CHAT_SMOKE_MODEL` | required | models Chat model ID used by the deployment gate |
| `MODELS_EMBEDDING_SMOKE_MODEL` | required | models Embedding model ID used by the deployment gate |
| `MODELS_INTERNAL_BASE_URL` | `https://models.dofe.ai` | models management-plane base URL |
| `MODELS_INTERNAL_API_SECRET` | required | models management-plane service-to-service HMAC secret |
| `GEOFLOW_MCP_ENABLED` | `.env.prod` default | Whether to open `/mcp`; a Token is required when enabled |
| `GEOFLOW_MCP_TOKEN` | required when MCP enabled | MCP full (read+write) Bearer Token |
| `GEOFLOW_MCP_READ_TOKEN` | optional | MCP read-only Bearer Token (catalog / tasks.list / tasks.get only) |
| `GEOFLOW_TRUSTED_PROXIES` | `*` | Trusted proxy setting for reverse proxy/CDN/subdirectory deployments |
| `GEOFLOW_SELF_DELETE` | `0` | Set to `1` to remove the deployment script after a successful deployment |

## Self-Delete Mode

If you download the script to a temporary location and want it removed after deployment:

```bash
GEOFLOW_SELF_DELETE=1 bash geoflow-docker-deploy.sh
```

This only removes the executed script file. It does not remove the deployed GEOFlow source code.

## Manual Healthcheck

Run after DNS, HTTPS or reverse proxy changes:

```bash
cd /opt/geoflow
bash deploy-scripts/geoflow-healthcheck.sh
```

## Subdirectory Deployment

If the site is deployed under a first-level path, for example:

```text
https://example.com/wiki
```

Use:

```env
APP_URL=https://example.com/wiki
TRUSTED_PROXIES=*
ADMIN_BASE_PATH=geo_admin
```

Do not set `ADMIN_BASE_PATH=wiki/geo_admin`. The reverse proxy should strip or forward the prefix correctly and pass:

```nginx
proxy_set_header X-Forwarded-Prefix /wiki;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_set_header X-Forwarded-Host $host;
```

## Notes

- The scripts are references, not a replacement for server security hardening.
- Do not expose PostgreSQL or Redis to the public Internet.
- Keep `.env.prod`, `storage/`, and PostgreSQL data backed up before upgrades.
- If Docker image pulling is slow or unstable in mainland China, use the one-shot tunnel scripts below (no `docker restart`) or configure a registry mirror in `daemon.json`.

## 国内一次性拉镜像（不重启 Docker、不中断服务）

改 `/etc/systemd/system/docker.service.d/` 并 `systemctl restart docker` **会短暂影响所有容器**。推荐用下面两种方式之一：

### 方式 A：隧道 + skopeo（ECS 拉，走你本机网络）

**终端 1（Mac，保持不关）：**

```bash
REMOTE_PROXY_PORT=1080 LOCAL_PROXY_PORT=1082 \
ECS_HOST=ecs-user@you-ip \
bash deploy-scripts/start-docker-pull-tunnel.sh
```

**终端 2（ECS）：**

```bash
DOCKER_TUNNEL_PROXY_PORT=1080 bash deploy-scripts/pull-images-once-via-tunnel.sh --env-file .env.prod
DOCKER_TUNNEL_PROXY_PORT=1080 bash deploy-scripts/build-once-via-tunnel.sh --env-file .env.prod
sudo docker-compose --env-file .env.prod -f docker-compose.prod.yml up -d
```

- `skopeo` 只在本进程走 `HTTP_PROXY`，**不动 Docker daemon**
- `build-once-via-tunnel.sh` 用临时 `DOCKER_CONFIG`，仅本次 build 走代理
- `up -d` 若镜像已齐，通常不再拉取

### 方式 B：本机拉取 + 管道导入（更简单，不用隧道）

```bash
ECS_HOST=ecs-user@你的IP bash deploy-scripts/sync-images-from-local.sh
```

本机用 Clash 拉镜像，经 SSH `docker save | docker load` 灌进 ECS。ECS 零配置。

Apple Silicon 本机默认拉 `linux/amd64`（脚本已设 `DOCKER_PLATFORM=linux/amd64`）。
