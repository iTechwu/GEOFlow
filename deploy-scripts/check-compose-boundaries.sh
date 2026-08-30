#!/usr/bin/env bash
set -euo pipefail

# geo.dofe.ai Compose 边界检查：
# 禁止本仓库的 Docker Compose 创建、运行或内嵌 PostgreSQL / Redis / RabbitMQ 服务，
# 并禁止应用绕过 knowledge.dofe.ai 直连 Neo4j / MinIO。
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

FORBIDDEN='postgres|redis|rabbitmq|neo4j|minio'
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

if grep -ERni --include='*.php' \
  '(^|[^A-Z])(NEO4J|MINIO)_[A-Z0-9_]+|Laudis\\Neo4j|Aws\\S3\\S3Client' \
  "$ROOT/app" "$ROOT/config"; then
  violations=$((violations + 1))
fi

if [ "$violations" -ne 0 ]; then
  echo "[compose-boundary] FAIL: found $violations forbidden direct infrastructure dependency reference(s)." >&2
  exit 1
fi

echo "[compose-boundary] OK: shared services stay external and Neo4j/MinIO remain behind Knowledge."
