# GEOFlow Laravel 生产 Docker 部署

本文对应仓库中的生产编排文件：

- `docker-compose.prod.yml`
- `docker/Dockerfile.prod`
- `docker/nginx/Dockerfile.prod`
- `docker/nginx/default.conf`
- `docker/entrypoint.prod.sh`
- `.env.prod.example`

## 1. 方案说明

生产环境推荐使用：

- `web`: `nginx`
- `app`: `php-fpm`
- `queue`: `php artisan queue:work`
- `scheduler`: `php artisan schedule:work`
- `reverb`: `php artisan reverb:start`
- PostgreSQL/Redis：由 `../docker-helm.dofe.ai` 集中提供，GEOFlow 仅通过 `DB_*`/`REDIS_*` 连接

这套方案与当前开发用 `docker-compose.yml` 分离：

- 开发：`docker-compose.yml`，继续使用 `php artisan serve`
- 生产：`docker-compose.prod.yml`，改为 `nginx + php-fpm`

## 1.1 一键部署脚本

一键部署脚本仅用于全新、空数据库安装。如果希望在常见云服务器、VPS 或面板服务器上先做环境自检，再自动完成首次生产 Docker 部署，可以使用仓库中的参考脚本：

```bash
curl -fsSL https://raw.githubusercontent.com/yaojingang/GEOFlow/main/deploy-scripts/geoflow-docker-deploy.sh -o geoflow-docker-deploy.sh
bash geoflow-docker-deploy.sh
```

脚本会完成：

- 检查 CPU、内存、磁盘、Docker、Docker Compose 与端口占用
- 克隆或更新 GEOFlow 源码
- 生成 `.env.prod` 并写入生产默认配置
- 连接外部 PostgreSQL/Redis，并启动 Nginx、PHP-FPM、队列、调度和 Reverb
- 执行迁移、写入默认管理员、清理并重建 Laravel 缓存
- 调用 `deploy-scripts/geoflow-healthcheck.sh` 做部署后自检

如需部署成功后删除临时脚本，可使用：

```bash
GEOFLOW_SELF_DELETE=1 bash geoflow-docker-deploy.sh
```

完整变量说明见 `deploy-scripts/README.md`。

已有数据的实例禁止使用一键脚本升级，也禁止滚动升级。请完整执行 3.1 节的停机排空协议。

## 2. 准备环境文件

```bash
cp .env.prod.example .env.prod
vi .env.prod
```

至少确认这些值：

```env
APP_URL=https://your-domain.com
TRUSTED_PROXIES=*
APP_KEY=base64:replace-with-generated-key

DB_DATABASE=geo_flow
DB_USERNAME=geo_user
DB_PASSWORD=<external-postgres-password>
DB_HOST=postgres.shared

REDIS_HOST=redis.shared
REDIS_PASSWORD=<external-redis-password>
MODELS_BASE_URL=https://models.dofe.ai/v1
MODELS_API_KEY=<models-service-key>
GEOFLOW_MCP_ENABLED=true
GEOFLOW_MCP_TOKEN=<mcp-token>
WEB_PORT=18080
REVERB_EXPOSE_PORT=18081
```

说明：

- `APP_KEY` 必须在启动前写入 `.env.prod`，CI 部署通过 `GEOFLOW_APP_KEY` Secret 注入。手工首次部署可执行 `printf 'base64:%s\n' "$(openssl rand -base64 32)"` 生成；生产容器只读挂载 `.env.prod`，不会生成或轮换密钥。
- `TRUSTED_PROXIES` 用于反向代理、CDN、负载均衡或一级目录部署。若外层代理会传 `X-Forwarded-Proto` / `X-Forwarded-Host` / `X-Forwarded-Prefix`，生产环境通常可设为 `*` 或具体代理 IP。
- 如果部署在任意一级目录下，例如外部访问路径是 `/wiki`、`/docs`、`/site`，不要把目录写进 `ADMIN_BASE_PATH`；应由反向代理透传 `X-Forwarded-Prefix`，后台路径仍保持 `ADMIN_BASE_PATH=geo_admin`。
- `AUTO_MIGRATE=false`、`AUTO_INSTALL_ONCE=false` 固定用于常驻服务；迁移和首次安装由外部发布流程显式执行，重启不会修改数据库。
- 生产镜像不会在启动时执行 `composer install`
- **外部依赖凭据**：Compose 不创建 PostgreSQL/Redis，也不映射 `POSTGRES_*` 或持久卷；`DB_HOST`、`REDIS_HOST` 和密码必须由集中基础设施/CI Secret 提供。
- **建议仍使用 `--env-file .env.prod`**：便于插值端口、镜像和应用配置，并确保 models/MCP 变量进入 app、queue 等容器。

## 3. 启动步骤

下文统一使用前缀（请原样复制）：

```bash
export COMPOSE_PROD='docker compose --env-file .env.prod -f docker-compose.prod.yml'
```

首次部署建议按以下顺序：

```bash
$COMPOSE_PROD build
$COMPOSE_PROD run --rm --no-deps -e GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=true app php artisan migrate --force --no-interaction
$COMPOSE_PROD run --rm --no-deps -e GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=true app php artisan geoflow:install --no-interaction
$COMPOSE_PROD up -d app web queue scheduler reverb
```

fresh-install 确认只注入上述两条外部命令。迁移仅在单一 fresh migration batch 且业务表为空时接受此标志；已有部署仍需下一节的 drain confirmation。

### 3.1 受管图片删除升级门禁

升级到包含 `images.managed_path_hash` 的版本时，先保持 `GEOFLOW_MANAGED_IMAGE_DELETION_ENABLED=false`。已有数据或既有迁移历史的数据库必须使用 down → stop/drain → one-time confirmation → migrate → start-new → readiness → up → enable 的顺序。迁移会在任何 schema 变更前检查 `GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED=true`；未确认时会安全终止。全新空库仅在外部迁移命令上设置 `GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=true`。

滚动升级、migration-first、一键升级均无法覆盖已经通过旧版空 replay 检查的在途请求，因此明确禁止用于已有数据的实例。一次性确认仅表示运维人员已经完成排空，不会自动停止进程。

```bash
# 1. 先进入维护模式，再停止入口和所有旧版常驻进程。
$COMPOSE_PROD exec app php artisan down
$COMPOSE_PROD stop web queue scheduler reverb

# 2. 等待负载均衡连接、PHP 请求、队列任务和调度任务全部结束；确认零在途后停止 app。
# 请使用平台连接数、进程列表和队列监控完成确认。
$COMPOSE_PROD stop app

# 3. 仅在确认全部旧进程和在途请求已排空后构建新镜像。
$COMPOSE_PROD build

# 4. 仅向本次外部迁移命令注入排空确认，不修改常驻服务环境。
$COMPOSE_PROD run --rm --no-deps \
  -e GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED=true \
  -e GEOFLOW_MANAGED_IMAGE_DELETION_ENABLED=false \
  app php artisan migrate --force --no-interaction

# 5. 迁移成功后启动全部新版本进程。
$COMPOSE_PROD up -d app web queue scheduler reverb

# 6. 回填并检查受管图片身份；remaining、terminal、registry_failed 必须都为 0。
$COMPOSE_PROD run --rm app php artisan geoflow:managed-images:readiness

# 7. 运行只读安全审计，并逐项处理或确认 finding。
$COMPOSE_PROD run --rm app php artisan geoflow:security-audit

# 8. 退出维护模式并恢复流量。
$COMPOSE_PROD exec app php artisan up
```

readiness 命令会回填已有图片路径哈希，并在路径锁内对账注册表、文件状态和内容哈希。永久无效的历史路径会保留稳定终态哈希，并计入 `terminal`；文件缺失、身份不一致或无法安全读取会计入 `registry_failed`。确认输出表格的 `remaining`、`terminal`、`registry_failed` 都为 `0`，再运行 `geoflow:security-audit`。该审计命令严格只读，不回填哈希、不修改数据库、不访问 HTTP/DNS，也不启动外部进程。人工可读模式和 JSON 模式使用相同 finding 集合：

```bash
# 人工检查
$COMPOSE_PROD run --rm app php artisan geoflow:security-audit

# 自动化检查；JSON schema_version 固定为 1
$COMPOSE_PROD run --rm app php artisan geoflow:security-audit --json
```

退出码 `0` 表示没有 finding；退出码 `1` 表示发现问题、需要复核的私网出站例外，或审计无法安全完成。JSON 包含 `schema_version`、`status`、按 severity 汇总的 `summary` 和按稳定 code 排序的 `findings`，不会输出路径、URL、token、owner 或哈希原文。该命令用于 GEOFlow 运行数据与安全配置检查，依赖漏洞检查仍需单独执行 `composer audit`。

完成审计处理，再次确认运行中的容器全部来自新镜像，然后将 `GEOFLOW_MANAGED_IMAGE_DELETION_ENABLED=true` 写入生产环境配置，并重新创建会执行图片清理的新版本进程：

```bash
$COMPOSE_PROD up -d --force-recreate app queue scheduler
```

门禁关闭或回填未完成时，数据库记录仍可删除，物理图片文件会安全保留并记录清理失败日志。

不要使用单条 `up -d --build` 代替迁移流程；常驻服务不会自动迁移，健康检查也会拒绝仍有 pending migration 的发布。

## 4. 访问方式

- 前台与后台统一从 `web`（Nginx）进入
- 站点：`http://服务器IP:${WEB_PORT}` 或你的反向代理域名
- 后台：`/geo_admin/login`（或你的 `ADMIN_BASE_PATH`）
- Reverb：默认映射 `${REVERB_EXPOSE_PORT}:8080`

### 默认管理员（首次安装）

外部首次安装流程在迁移完成后显式执行 `php artisan geoflow:install`。该命令不会创建本地账号；它只写入安装状态，并且仅在显式开启演示数据时导入内容。常驻的 `app`、`queue`、`scheduler`、`reverb` 服务不会自动 seed。

```bash
# 全新空库迁移成功后执行首次安装命令：
docker compose --env-file .env.prod -f docker-compose.prod.yml run --rm app php artisan geoflow:install
```

认证统一由 SSO 提供。登录地址：站点根 URL + `/geo_admin/login`（默认；若改过 `ADMIN_BASE_PATH` 则把 `geo_admin` 换成你的前缀），随后会重定向到 SSO。应用数据库只保存由 SSO `sub` 同步的最小审计和归属投影。

### 初始化数据维护规则

后续新增默认站点配置、默认提示词、默认渠道、默认模板、演示分类或演示文章时，必须接入 `php artisan geoflow:install` 的首次空库安装路径，或通过明确的手动修复命令执行。不要把用户可修改的默认数据放到常规容器启动、迁移或每次升级都会自动执行的 seed 流程里，避免覆盖线上用户配置。

## 5. 关键差异

### 当前开发 Docker

- `uhub.service.ucloud.cn/techwu/php:8.4-cli-bookworm`
- `php artisan serve`
- 允许运行时 `composer install`
- 默认 `AUTO_MIGRATE=false`

### 当前生产 Docker

- `uhub.service.ucloud.cn/techwu/php:8.4-fpm-bookworm`
- `nginx` 直接服务静态文件，PHP 交给 `php-fpm`
- 依赖在构建期安装完成
- 通过 `docker/entrypoint.prod.sh` 执行进程启动与可选缓存优化；数据库迁移默认关闭

## 6. 运维建议

- 不要在 GEOFlow Compose 中声明或对外暴露 PostgreSQL/Redis；它们属于集中基础设施
- 建议在反向代理层只公开 `80/443`
- 若更新了 PHP 代码，因 OPcache `validate_timestamps=0`，请重新构建镜像
- 修改 `.env.prod` 后，执行：

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d
```

该命令仅用于没有代码、镜像或数据库迁移变化的配置重建。已有部署的代码更新统一执行 3.1 节的停机排空协议。

- 全新空库需要手动跑迁移时，把 fresh-install intent 限定在该外部命令：

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml run --rm \
  -e GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=true \
  app php artisan migrate --force
```

已有部署不得使用上述 fresh-install 命令；手动迁移也必须先完成 3.1 节的 down、停止排空和 drain confirmation。

## 7. 回滚与更新

已有部署的更新统一执行 3.1 节。`git pull` 与镜像构建应放在停机排空流程内，禁止用 `git pull` → `build` → `up -d` 直接替代该流程。

回滚：

- 切回目标 commit / tag
- 先确认目标版本与当前数据库 schema 兼容
- 使用与 3.1 节相同的停机排空边界重建并启动目标版本

## 8. 原理说明

- 静态文件：由 `web` 容器中的 Nginx 直接返回
- PHP 请求：Nginx 通过 FastCGI 转发给 `app:9000`
- Laravel 代码执行：由 `php-fpm` 进程解析并运行 `public/index.php`

## 9. 构建时拉取基础镜像失败（`not found` / `could not fetch content descriptor`）

若日志类似 `FROM uhub.service.ucloud.cn/techwu/php:8.4-fpm-bookworm` 或某层 `application/vnd.oci.image.layer...` **`from remote: not found`**，多为**仓库侧或镜像加速与 manifest 不一致**，而非项目 Dockerfile 写错。

建议按顺序尝试：

1. **直接重试** `docker compose --env-file .env.prod -f docker-compose.prod.yml build`（偶发 Hub 或链路问题）。
2. **单独拉基础镜像**，确认是拉取问题还是仅 BuildKit 缓存问题：  
   `docker pull uhub.service.ucloud.cn/techwu/php:8.4-fpm-bookworm`  
   若此处同样 `not found`，说明当前访问的 registry/加速源缺层，需换源或直连。
3. **检查本机 `/etc/docker/daemon.json` 的 `registry-mirrors`**：部分公共加速源对 `docker.io` 层同步不完整，可**暂时注释镜像加速**后重启 Docker，再 `docker pull` / `build`；或换成你环境稳定可用的镜像源策略。
4. **清理构建缓存后再构建**：  
   `docker builder prune -f`  
   必要时再 `docker system prune`（注意会删掉未使用镜像，执行前自行确认）。

仍失败时，把 **`docker pull uhub.service.ucloud.cn/techwu/php:8.4-fpm-bookworm` 的完整输出**与 **`daemon.json` 中与 registry 相关的配置**（可打码）一并排查网络与镜像源。
