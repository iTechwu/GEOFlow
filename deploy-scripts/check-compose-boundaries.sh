#!/usr/bin/env bash
set -euo pipefail

# GEOFlow Compose 边界检查：
# 禁止本仓库的 Docker Compose 创建、运行或内嵌 PostgreSQL / Redis / RabbitMQ 服务。
# 这些依赖由 ../docker-helm.dofe.ai 集中管理（见 AGENTS.md / CLAUDE.md）。
#
# 检查项：
#   1) 2 空格缩进的顶层 key（服务名或持久卷名）以 postgres/redis/rabbitmq 开头；
#   2) image: 值包含 postgres/redis/rabbitmq；
#   3) depends_on 引用 postgres/redis/rabbitmq 开头的服务；
#   4) 顶层 init/db-init/migrate 服务（迁移必须由外部发布流程显式执行）。

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [ "$#" -gt 0 ]; then
  FILES=("$@")
else
  FILES=(
    "$ROOT/docker-compose.yml"
    "$ROOT/docker-compose.prod.yml"
    "$ROOT/docker-compose.prebuilt.yml"
  )
fi

FORBIDDEN='postgres|redis|rabbitmq'
FORBIDDEN_INIT='init|db[-_]?init|migrat(e|ion)'
violations=0

for file in "${FILES[@]}"; do
  rel="${file#$ROOT/}"
  if [ ! -f "$file" ]; then
    echo "[compose-boundary] missing $rel" >&2
    violations=$((violations + 1))
    continue
  fi

  # 1) 顶层服务/卷名
  if grep -Eni '^[[:space:]]{2}('"$FORBIDDEN"')[a-zA-Z0-9_-]*:' "$file"; then
    violations=$((violations + 1))
  fi

  # 2) image 值
  if grep -Eni '^[[:space:]]*image:[[:space:]]*[^#]*('"$FORBIDDEN"')' "$file"; then
    violations=$((violations + 1))
  fi

  # 3) depends_on 引用
  if grep -Eni '^[[:space:]]{4,}('"$FORBIDDEN"')[a-zA-Z0-9_-]*:' "$file"; then
    violations=$((violations + 1))
  fi

  # 4) 数据库初始化/迁移服务
  if grep -Eni '^[[:space:]]{2}('"$FORBIDDEN_INIT"'):' "$file"; then
    violations=$((violations + 1))
  fi
done

if [ "$violations" -ne 0 ]; then
  echo "[compose-boundary] FAIL: found $violations forbidden external-dependency or initialization service reference(s) in Compose files." >&2
  exit 1
fi

echo "[compose-boundary] OK: no PostgreSQL/Redis/RabbitMQ or initialization service definitions in Compose files."
