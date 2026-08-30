# geo.dofe.ai

geo.dofe.ai 是一套面向 GEO（生成式引擎优化）的开源智能内容工程与多站点分发系统。它把知识库、素材库、提示词、AI 生成任务、审核发布、数据分析、geo.dofe.ai Agent 目标站点包、WordPress REST 渠道、通用 HTTP API 渠道和远端静态页面分发串联为一条可持续运营的工作链路，帮助团队把可信资料沉淀为可管理、可发布、可追踪、可同步到多端的 GEO 内容资产。

[![PHP](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12-red)](https://laravel.com/)
[![PostgreSQL](https://img.shields.io/badge/Database-PostgreSQL%20%2B%20pgvector-336791)](https://www.postgresql.org/)
[![Docker](https://img.shields.io/badge/Docker-Compose-blue)](https://docs.docker.com/compose/)
[![License](https://img.shields.io/badge/License-Apache--2.0-blue.svg)](LICENSE)
[![GitHub stars](https://img.shields.io/github/stars/yaojingang/GEOFlow?style=social)](https://github.com/yaojingang/GEOFlow/stargazers)
[![GitHub forks](https://img.shields.io/github/forks/yaojingang/GEOFlow?style=social)](https://github.com/yaojingang/GEOFlow/network/members)
[![GitHub issues](https://img.shields.io/github/issues/yaojingang/GEOFlow)](https://github.com/yaojingang/GEOFlow/issues)

---

## 目录

- [功能总览](#功能总览)
- [工作链路](#工作链路)
- [快速开始](#快速开始)
- [接入方式：后台、REST API 与 MCP](#接入方式后台rest-api-与-mcp)
- [部署](#部署)
- [环境要求](#环境要求)
- [安全基线](#安全基线)
- [界面预览](#界面预览)
- [适用场景](#适用场景)
- [geo.dofe.ai Agent Skill](#geoflow-agent-skill)
- [开发与测试](#开发与测试)
- [版本与文档](#版本与文档)
- [开源协议](#开源协议)

---

## 功能总览

| 模块 | 能力 |
|------|------|
| 🤖 多模型内容生成 | 兼容 OpenAI 风格接口和 Gemini 原生接口，支持 chat / embedding 模型、Provider URL 自动适配、智能模型切换、失败重试和调用统计 |
| 🧠 知识库与 RAG | 结构化规则切片、可选 LLM 语义规划（只规划边界、从原文稳定重建）、自动策略回退；embedding 写入 pgvector 向量（半精度索引），文章生成时召回相关资料 |
| 🏢 企业知识库 | 项目化的草稿生成工作流：多份来源上传 / 粘贴、文本 / Markdown / HTML / CSV / JSON / XML / PDF / Word / Excel 解析、队列异步生成、历史修订追踪、待确认项与来源摘要 |
| 🗂 素材与提示词体系 | 标题库、关键词库、图片库、作者库、知识库、正文提示词、特殊提示词集中管理；知识库详情页内置 Markdown 编辑器与重新切片 |
| 📦 任务自动化 | 任务创建、生成数量、草稿池、审核开关、发布节奏、队列执行、失败重试、发布范围控制和任务文章筛选 |
| 📋 审核与文章管理 | 草稿、审核、发布、回收站、作者、分类、SEO 字段和任务来源统一管理 |
| 📡 多站点分发 | geo.dofe.ai Agent、WordPress REST 与通用 HTTP API 三类渠道；密钥管理、测试连接、目标站点包、静态 / 伪静态模式、远端设置同步、远端文章编辑 / 删除、队列与日志 |
| 🧾 目标站点包 | 为每个渠道生成预配置 PHP Agent 包，内置首页、详情页、静态资源、sitemap、`llms.txt` / TXT 地图和 Schema |
| 📊 数据分析 | `/admin/analytics` 集中展示系统总览、单站内容运营、任务健康、素材健康、多站分发状态、访问日志、Top 内容、AI 爬虫识别和趋势图 |
| 🔍 SEO 与 LLM 抓取友好输出 | 文章 SEO 元信息、Open Graph、JSON-LD Schema（统一 `Js::encode` 防注入）、GFM Markdown、独立 CSS、图片同步、sitemap 和 TXT 地图 |
| 🎨 前台与主题 | 默认主题、主题包、预览路径、后台主题切换；主题复刻为 package-only 审查包交付与沙箱预览；Agent 渠道可同步站点标题、版权、主题和分类设置 |
| 🔌 MCP 服务 | 内置 MCP Server（`POST /mcp`），向 AI Agent 开放任务、素材、文章、企业知识、URL 导入、分发只读、线索、分析与系统诊断等工具，按令牌权限过滤工具发现 |
| 🌍 后台多语言 | 中文、英文、日语、西班牙语、俄语、葡萄牙语（巴西）切换，覆盖 2.x 全部模块 |
| 🔔 版本提醒 | 后台按 `version.json` 检查 GitHub 新版本并提醒管理员 |

---

## 工作链路

```text
后台管理页面
    ↓
AI 配置 / 素材库 / 提示词 / 任务配置
    ↓
调度器 / 队列 / Worker 执行 AI 生成
    ↓
草稿 / 审核 / 发布
    ↓
本地前台文章与 SEO 页面
    ↓
分发队列 / 目标站点 Agent
    ↓
远端静态首页、详情页、sitemap、TXT 地图与 llms.txt
```

系统分层：

| 层级 | 说明 |
|------|------|
| Web / Admin | Laravel 路由与控制器；前台文章站点、Blade 后台、数据分析、分发管理、素材与任务入口 |
| API / MCP / Agent | REST API v1（Sanctum 令牌 + scope）、MCP Server（`POST /mcp`）、目标站点 PHP Agent（分发健康检查、文章接收、远端设置同步、静态文件生成） |
| Scheduler / Queue / Reverb | Laravel Scheduler 扫描入队；`queue:work` / Horizon 消费生成与分发任务；Reverb 按需启用 WebSocket |
| Domain & Jobs | `app/Services`、`app/Jobs`、`app/Http/Controllers` 承载 AI 生成、RAG、企业知识、发布、分发、MCP 工具和日志分析规则 |
| Persistence | PostgreSQL（推荐 pgvector 镜像）+ Redis（队列 / 缓存）+ 目标站点本地 JSON / 静态文件 |

---

## 快速开始

### 方式一：Docker（开发 / 演示）

```bash
# 全新空库：先显式执行迁移与首次安装，再启动本地应用栈
make dev-init
make dev
```

- 前台默认访问：`http://localhost:18080`（端口由 **`APP_PORT`** 控制，默认 `18080`）
- 后台登录：`http://localhost:18080/geo_admin/login`（前缀由 **`ADMIN_BASE_PATH`** 控制，默认 `geo_admin`）
- PostgreSQL 与 Redis 由外部基础设施统一管理，本仓库 Compose 只通过 `.env.local` 连接外部服务，不内嵌数据库容器
- Vite 热更新端口为 `127.0.0.1:5173`；Blade / PHP 修改刷新页面即生效，JS / CSS 修改热更新

`make dev-init` 是全新空库的一次性外部操作；日常应用容器启动不会自动迁移数据库。后台使用 SSO 登录，需在 `.env.local` 中为 `SSO_CLIENT_ID`、`SSO_CLIENT_SECRET`、`INTERNAL_API_SECRET` 和回调地址配置可用的身份提供方；未配置时前台仍可访问，但不能完成后台登录。

常用开发命令：

```bash
make dev-up    # 后台启动
make dev-logs  # 跟随所有服务日志
make dev-shell # 进入 app 容器执行 artisan、测试等命令
make dev-down  # 停止并移除容器，保留数据库卷目录
```

### 方式二：本地 PHP 环境

**前置要求：** PHP **8.3+**（启用 `pdo_pgsql`、`redis` 等扩展）、本机 PostgreSQL 与 Redis、Composer 2.x、Node.js。

```bash
# 1. 克隆仓库
git clone https://github.com/yaojingang/GEOFlow.git
cd geo.dofe.ai

# 2. 环境与依赖
cp .env.example .env
# 本地 PHP 进程不在 Compose 网络内：DB_HOST / REDIS_HOST 改为 127.0.0.1，
# 端口改为本机服务端口；HTTP 调试保持 SESSION_SECURE_COOKIE=false

composer install --no-interaction --prefer-dist
npm ci
npm run build
php artisan key:generate

# 3. 数据库与存储
GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=true php artisan migrate --force
php artisan geoflow:install   # 首次空库安装
php artisan storage:link

# 4. 开发用 HTTP（仅本地调试；生产请用 Nginx + PHP-FPM，站点根目录 public/）
php artisan serve --host=127.0.0.1 --port=8080
```

另开终端启动常驻进程（与 Docker 中 `queue` / `scheduler` / `reverb` 对应）：

```bash
php artisan queue:work redis --queue=geoflow,distribution,default --sleep=1 --tries=1 --timeout=300
php artisan schedule:work
php artisan reverb:start
```

- 后台：`http://127.0.0.1:8080/geo_admin/login`（若修改了 `ADMIN_BASE_PATH` 请替换路径）
- 生产可用 `php artisan horizon` 替代 `queue:work`（需按项目配置托管进程）

### 后台三步上手

登录后台后，按仪表盘里的「快速开始」完成第一轮验证：

1. **配置 API**：至少添加一个可用 chat 模型；需要知识库 RAG 召回时，再添加一个 embedding 模型，并选择适合的知识库切片策略。
2. **配置素材库**：准备知识库、标题库、关键词库、图片库和作者。知识库建议先用真实、可验证的业务资料。
3. **新建任务**：选择标题库、素材、模型、生成数量、发布频率和发布范围，先让文章进入草稿或审核流程，再逐步开启自动发布与多站点分发。

---

## 接入方式：后台、REST API 与 MCP

geo.dofe.ai 提供三种等价的操作入口，适合不同角色：

| 入口 | 面向 | 说明 |
|------|------|------|
| 管理后台 | 运营人员 | Blade 后台，覆盖全部功能；SSO 登录，无本地账号密码 |
| REST API v1 | 系统集成 | `/api/v1` 前缀，Sanctum 令牌 + scope 鉴权，覆盖目录、任务、素材、文章等资源的读写 |
| MCP Server | AI Agent | `POST /mcp`，MCP 身份令牌鉴权，按令牌权限过滤工具发现，按凭证隔离限流并记录审计 |

MCP 工具面覆盖：任务管理与执行取消、素材读写、文章操作、企业知识（项目、来源、发布、安全删除、租户列表）、URL 异步导入、分发只读、线索、前台主题能力发现、分析数据与系统只读诊断。

---

## 部署

### 共享基础设施约束

**本仓库的 Dockerfile 与 Compose 文件不创建、不运行、不内嵌 PostgreSQL、Redis 或 RabbitMQ，也不直连 Neo4j 或 MinIO。** PostgreSQL、Redis、RabbitMQ 由外部基础设施统一管理；企业知识检索、对象与图谱基础设施统一通过 `knowledge.dofe.ai` 的租户/空间授权 API 使用，底层 MinIO 与 Neo4j 凭据只属于 Knowledge。仓库内置 `make check-boundaries` 校验该约束。

### Docker（生产）

生产环境使用 **`docker-compose.prod.yml`**（**Nginx + PHP-FPM**），而不是 `php artisan serve`。

全新空库首次部署时，可使用参考部署脚本自动完成环境自检、Docker 检测、`.env.prod` 生成、容器部署和健康检查：

```bash
curl -fsSL https://raw.githubusercontent.com/yaojingang/geo.dofe.ai/main/deploy-scripts/geoflow-docker-deploy.sh -o geoflow-docker-deploy.sh
bash geoflow-docker-deploy.sh
```

脚本说明见 [`deploy-scripts/README.md`](deploy-scripts/README.md)。手动部署：

```bash
cp .env.prod.example .env.prod
vi .env.prod

docker compose --env-file .env.prod -f docker-compose.prod.yml build
docker compose --env-file .env.prod -f docker-compose.prod.yml run --rm --no-deps -e GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=true app php artisan migrate --force --no-interaction
docker compose --env-file .env.prod -f docker-compose.prod.yml run --rm --no-deps -e GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=true app php artisan geoflow:install --no-interaction
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d app web queue scheduler reverb
```

- 前台 / 后台统一经 `web`（Nginx）访问，PHP 由 `app`（php-fpm）解析
- **首次安装**：迁移和 `geoflow:install` 由上述外部命令显式执行，仅用于全新空库
- **已有部署升级**：禁止直接 `git pull` → `build` → `up -d`。请完整执行 [`docs/deployment/DEPLOYMENT.md` 3.1 节](docs/deployment/DEPLOYMENT.md#31-受管图片删除升级门禁)的停机排空、安全迁移和 readiness 流程
- 详细说明见 [`docs/deployment/DEPLOYMENT.md`](docs/deployment/DEPLOYMENT.md)

### 开发 Compose 服务一览

| 服务 | 作用 |
|------|------|
| `assets` | 一次性构建前端资产 |
| `vite` | 前端开发服务器与热更新 |
| `app` | `php artisan serve`，映射 **`${APP_PORT:-18080}:8080`** |
| `queue` | `queue:work redis` |
| `scheduler` | `schedule:work` |
| `reverb` | WebSocket，映射 **`${REVERB_EXPOSE_PORT:-18081}:8080`** |

### 入口脚本（`docker/entrypoint.sh`）常用变量

| 变量 | 默认 | 含义 |
|------|------|------|
| `COMPOSER_ON_START` | `true` | 容器启动时执行 `composer install` |
| `AUTO_MIGRATE` | `false` | 兼容开关；默认关闭，迁移由外部流程显式执行 |
| `AUTO_INIT_ONCE` | `false` | 兼容开关；运行服务不得开启 |
| `AUTO_INSTALL_ONCE` | `false` | 已完成迁移后单独执行一次 `geoflow:install`，常驻服务不建议开启 |

入口脚本会在 `.env` 中没有有效 `APP_KEY` 时自动执行 `key:generate --force`。Compose 将 **`./storage`** 与 **`./.env`** 挂载进容器，应用代码在镜像内。

### 源码部署补充

- **目录权限**：`chmod -R ug+rwx storage bootstrap/cache`
- **认证与用户**：仅使用 SSO。访问后台会重定向到 SSO；本地数据库只保留按 SSO `sub` 同步的审计与数据归属投影，不创建本地用户名或密码，首次安装也不生成管理员
- **安装行为**：`geoflow:install` 只在空库首次安装时写入安装状态，仅在显式开启 `GEOFLOW_SEED_FRONTEND_DEMO=true` 时导入演示内容
- **生产 Web**：使用 Nginx / Apache + PHP-FPM，网站根目录指向项目 **`public/`**，勿将仓库根目录直接暴露为文档根

---

## 环境要求

| 组件 | 说明 |
|------|------|
| PHP | **8.3+**（Docker 镜像默认 8.4） |
| 框架 | Laravel 12（Horizon / Reverb / Sanctum / laravel/ai） |
| 扩展 | Laravel 常规扩展；PostgreSQL 需 `pdo_pgsql`；Redis 队列需 `redis` |
| Composer | 2.x |
| 数据库 | **PostgreSQL**（推荐 **pgvector** 镜像，与 Compose 配置一致） |
| Redis | 队列、缓存等（本地极简调试可将 `QUEUE_CONNECTION` 改为 `sync`，生产不推荐） |

---

## 安全基线

2.1.x 起内置的安全机制：

- **出站请求统一安全网关**：分发、URL 导入、主题参考抓取、AI、更新检查与归档下载统一经过 URL 规范化、DNS 全量校验、IP 固定、重定向控制、响应体上限和错误脱敏，阻断 SSRF 绕过路径
- **受管图片边界**：内容寻址文件名、受管目录校验与注册表 fencing，物理删除默认关闭并设有升级门禁
- **结构化数据防注入**：所有主题 JSON-LD 统一 `Js::encode` 输出
- **主题代码隔离**：关闭可执行主题的在线编辑；主题复刻预览使用确定性页面 + sandbox CSP，发布为 package-only 审查包
- **敏感权限收敛**：分发、URL 导入、主题和复刻相关管理路由统一要求超级管理员权限
- **MCP 防护**：无效身份令牌提前拒绝、鉴权超时限制、按凭证隔离限流、按令牌权限过滤工具发现、审计身份诊断
- **API 幂等**：durable state、owner lease 与 fingerprint version，异常记录进入显式人工处理路径
- **只读安全审计**：`php artisan geoflow:security-audit`（`--json` 输出稳定 schema），任一 finding 返回退出码 `1`，不发起网络请求或数据修复

---

## 界面预览

<table>
  <tr>
    <td width="34%" rowspan="3"><img src="docs/images/screenshots/analytics.png" alt="geo.dofe.ai 中文数据分析" /><br /><sub>数据分析</sub></td>
    <td width="33%" rowspan="2"><img src="docs/images/screenshots/site-settings.png" alt="geo.dofe.ai 中文网站设置" /><br /><sub>网站设置</sub></td>
    <td width="33%"><img src="docs/images/screenshots/home.png" alt="geo.dofe.ai 中文后台首页" /><br /><sub>后台首页</sub></td>
  </tr>
  <tr>
    <td width="33%"><img src="docs/images/screenshots/task-management.png" alt="geo.dofe.ai 中文任务管理" /><br /><sub>任务管理</sub></td>
  </tr>
  <tr>
    <td width="33%"><img src="docs/images/screenshots/ai-config.png" alt="geo.dofe.ai 中文 AI 模型配置" /><br /><sub>AI 模型配置</sub></td>
    <td width="33%"><img src="docs/images/screenshots/materials.png" alt="geo.dofe.ai 中文素材管理" /><br /><sub>素材管理</sub></td>
  </tr>
</table>

---

## 适用场景

geo.dofe.ai 适合这些真实且可落地的场景：

- **独立 GEO 官网**：把官网内容、产品资料、FAQ、案例和品牌知识组织成可持续更新的内容系统，提升 AI 搜索可见度和品牌信源覆盖
- **官网中的 GEO 子频道**：在现有官网下搭建独立的资讯、知识或解决方案频道，通过导航、子域名或目录与主站打通
- **独立 GEO 信源站点**：面向某个行业、主题或问题域，持续沉淀高质量文章、榜单、解读、指南和资料
- **内部 GEO 内容管理后台**：统一管理模型、素材、标题、图片、知识库、审核和发布，减少分散工具切换
- **多站点 / 多栏目部署**：用同一套系统管理多个站点、栏目或主题模板，让生产、模板切换、分发和维护标准化
- **自动化信源管理与内容分发**：对知识库、专题内容、资讯更新和分发流程做工程化管理

建议的使用顺序：

1. 先确定真实的业务目标和目标读者
2. 先建设知识库，再建设自动化流程
3. 先确保内容真实、可核验、可维护
4. 再用模型、任务和模板能力去提效

这套系统的收益建立在**真实、优质、持续维护的知识库**之上。我们不鼓励利用系统制造信息噪音、批量污染互联网或堆积虚假内容。geo.dofe.ai 的本质是帮助团队更高效地管理、生产和分发可信内容，而不是替代事实、替代判断或替代内容质量本身。

---

## geo.dofe.ai Agent Skill

仓库在 [`.agents/skills/geoflow`](.agents/skills/geoflow/) 内提供统一的 geo.dofe.ai Skill。支持 Agent Skills 的工具打开本项目后可以直接发现它；在 Codex 中可通过 `$geoflow` 调用。

| 模式 | 适用范围 |
|------|----------|
| `development` | Laravel 后端、管理后台、API、CLI、队列、迁移和测试 |
| `operations` | 通过受支持的 CLI、API v1 或登录后的管理界面执行系统操作 |
| `public_frontend` | 默认网站、Blade 主题、首页模块、线索表单和前台页面 |
| `channel_frontend` | geo.dofe.ai Agent 目标站点包、渠道能力检查、同步预览和渠道前台设置 |
| `legacy_migration` | 旧版根目录 PHP 模板、历史包体和旧 Skill 标识迁移 |

它统一替代 `yao-geoflow-cli`、`yao-geoflow-design` 和 `yao-geoflow-template`。安装或升级为 Codex 全局 Skill：

```bash
bash .agents/skills/geoflow/scripts/install_codex_skill.sh
```

安装器只复制公开清单中的文件，校验暂存包，将当前 `geoflow` 和三个旧 Skill 移到唯一的 `~/.codex/skill-backups/geoflow-<时间戳>.<后缀>/`，随后在同一文件系统内切换新版本。完成后重启 Codex。依赖矩阵、回滚命令和平台边界见 [Skill 安装说明](.agents/skills/geoflow/README.md#installation)。

---

## 开发与测试

```bash
composer test        # PHPUnit（等价于 php artisan test）
./vendor/bin/pint    # 代码风格
make test-boundaries # 校验 Compose 未内嵌 PostgreSQL / Redis / RabbitMQ
make test-mcp-smoke  # MCP smoke 的协议、失败和清理路径（无需真实 Docker）
npm run test:browser # Playwright 浏览器测试
```

提交约定：提交信息使用中文，遵循 Conventional Commits；每个可独立交付的改动单独提交。

---

## 版本与文档

- 当前版本：**v2.1.1**（2026-07-17），更新内容见 [`docs/CHANGELOG.md`](docs/CHANGELOG.md)（[English](docs/CHANGELOG_en.md)）
- 部署手册：[`docs/deployment/DEPLOYMENT.md`](docs/deployment/DEPLOYMENT.md)
- 生产初始化排障：[`docs/deployment/docker-prod-init-troubleshooting.md`](docs/deployment/docker-prod-init-troubleshooting.md)

### 2.1 系列亮点

- **企业知识库工作流**：项目化来源管理、多格式解析、队列异步草稿生成、历史修订与待确认项追踪
- **MCP Server 开放**：AI Agent 可通过 `POST /mcp` 使用任务、素材、文章、企业知识、URL 导入、分发、线索、分析与诊断工具，带完整的令牌权限、限流与审计
- **知识检索增强**：知识分块使用 pgvector 半精度向量索引，召回更快、存储更省
- **安全加固**：出站 SSRF 网关、受管图片注册表与删除门禁、JSON-LD 防注入、主题 package-only 交付、只读安全审计命令

---

## 开源协议

本项目采用 [Apache License 2.0](LICENSE)。该协议允许个人和企业在遵守许可证声明、版权保留、修改说明、专利授权和免责声明等条款的前提下使用、修改、分发和商用 geo.dofe.ai。

---

## ⭐ Star 趋势

[![Star History Chart](https://api.star-history.com/svg?repos=yaojingang/GEOFlow&type=Date)](https://star-history.com/#yaojingang/GEOFlow&Date)
