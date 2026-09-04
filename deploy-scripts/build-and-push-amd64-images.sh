#!/usr/bin/env bash
#
# 在本机（Mac/Apple Silicon 亦可）构建 linux/amd64 生产镜像并推送到镜像仓库。
#
# 用法：
#   bash deploy-scripts/build-and-push-amd64-images.sh
#   VERSION=20260617 REGISTRY_USERNAME=user REGISTRY_PASSWORD=secret bash deploy-scripts/build-and-push-amd64-images.sh
#   PUSH_LATEST=1 VERSION=20260617 REGISTRY_USERNAME=user REGISTRY_PASSWORD=secret bash deploy-scripts/build-and-push-amd64-images.sh
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

REGISTRY="${REGISTRY:-registry.cn-beijing.aliyuncs.com}"
NS="${NS:-geo_flow}"
VERSION="${VERSION:-$(git rev-parse --short=12 HEAD 2>/dev/null || date +%Y%m%d%H%M%S)}"
PLATFORM="${DOCKER_PLATFORM:-linux/amd64}"
BUILDER_NAME="${DOCKER_BUILDX_BUILDER:-geoflow-builder}"
PUSH_LATEST="${PUSH_LATEST:-0}"
REGISTRY_USERNAME="${REGISTRY_USERNAME:-}"
REGISTRY_PASSWORD="${REGISTRY_PASSWORD:-}"
DOCKER_CONFIG_CREATED="0"

COMPOSER_IMAGE="${COMPOSER_IMAGE:-uhub.service.ucloud.cn/techwu/php:8.4-cli-bookworm}"
COMPOSER_VERSION="${COMPOSER_VERSION:-2.10.2}"
COMPOSER_SHA256="${COMPOSER_SHA256:-5ee7125f8a30a34d246cefdc0bc85b8a783b28f2aec968994118512350d28027}"
COMPOSER_PACKAGIST_MIRROR="${COMPOSER_PACKAGIST_MIRROR-https://mirrors.aliyun.com/composer}"
PHP_FPM_IMAGE="${PHP_FPM_IMAGE:-uhub.service.ucloud.cn/techwu/php:8.4-fpm-bookworm}"
PECL_REDIS_VERSION="${PECL_REDIS_VERSION:-6.3.0}"
PECL_REDIS_SHA256="${PECL_REDIS_SHA256:-cb8f81df1a275599e4f8ddcfec7e1f65ed1953e6f5673649149fd680ebff4cad}"
NGINX_IMAGE="${NGINX_IMAGE:-uhub.service.ucloud.cn/techwu/nginx:1.31.1-alpine}"

cd "${PROJECT_DIR}"

fail() {
  echo ">>> 错误: $*" >&2
  exit 1
}

cleanup_docker_config() {
  local temp_root="${TMPDIR:-/tmp}"

  [ "$DOCKER_CONFIG_CREATED" = "1" ] || return 0
  case "$DOCKER_CONFIG" in
    "$temp_root"/geoflow-build-docker-config.*) ;;
    *) echo ">>> 警告: 拒绝清理非预期 DOCKER_CONFIG 路径" >&2; return 0 ;;
  esac
  [ ! -L "$DOCKER_CONFIG" ] || { echo ">>> 警告: 拒绝清理符号链接 DOCKER_CONFIG 路径" >&2; return 0; }
  [ ! -d "$DOCKER_CONFIG" ] || find "$DOCKER_CONFIG" -depth -delete
}

prepare_docker_config() {
  if [ -n "${DOCKER_CONFIG:-}" ]; then
    return
  fi

  DOCKER_CONFIG="$(mktemp -d "${TMPDIR:-/tmp}/geoflow-build-docker-config.XXXXXX")"
  chmod 700 "$DOCKER_CONFIG"
  export DOCKER_CONFIG
  DOCKER_CONFIG_CREATED="1"
  trap cleanup_docker_config EXIT
}

case "${VERSION}" in
  *[!A-Za-z0-9._-]*|'') fail "VERSION 只能包含字母、数字、点、下划线和连字符" ;;
esac

[ -n "${REGISTRY_USERNAME}" ] || fail "必须通过 CI Secret 提供 REGISTRY_USERNAME"
[ -n "${REGISTRY_PASSWORD}" ] || fail "必须通过 CI Secret 提供 REGISTRY_PASSWORD"
prepare_docker_config

echo ">>> Registry: ${REGISTRY}"
echo ">>> Namespace: ${NS}"
echo ">>> Version: ${VERSION}"
echo ">>> Platform: ${PLATFORM}"

printf '%s' "${REGISTRY_PASSWORD}" | docker login --username "${REGISTRY_USERNAME}" --password-stdin "${REGISTRY}"

docker buildx create --name "${BUILDER_NAME}" --use 2>/dev/null || docker buildx use "${BUILDER_NAME}"
docker buildx inspect --bootstrap

APP_TAGS=(-t "${REGISTRY}/${NS}/geoflow-app-prod:${VERSION}")
WEB_TAGS=(-t "${REGISTRY}/${NS}/geoflow-web-prod:${VERSION}")
if [ "${PUSH_LATEST}" = "1" ]; then
  APP_TAGS+=(-t "${REGISTRY}/${NS}/geoflow-app-prod:latest")
  WEB_TAGS+=(-t "${REGISTRY}/${NS}/geoflow-web-prod:latest")
fi

echo ">>> 构建并推送 app 镜像"
docker buildx build --platform "${PLATFORM}" \
  -f docker/Dockerfile.prod \
  "${APP_TAGS[@]}" \
  --label "org.opencontainers.image.revision=${VERSION}" \
  --build-arg COMPOSER_IMAGE="${COMPOSER_IMAGE}" \
  --build-arg COMPOSER_VERSION="${COMPOSER_VERSION}" \
  --build-arg COMPOSER_SHA256="${COMPOSER_SHA256}" \
  --build-arg COMPOSER_PACKAGIST_MIRROR="${COMPOSER_PACKAGIST_MIRROR}" \
  --build-arg PHP_FPM_IMAGE="${PHP_FPM_IMAGE}" \
  --build-arg PECL_REDIS_VERSION="${PECL_REDIS_VERSION}" \
  --build-arg PECL_REDIS_SHA256="${PECL_REDIS_SHA256}" \
  --push .

echo ">>> 构建并推送 web 镜像"
docker buildx build --platform "${PLATFORM}" \
  -f docker/nginx/Dockerfile.prod \
  "${WEB_TAGS[@]}" \
  --label "org.opencontainers.image.revision=${VERSION}" \
  --build-arg NGINX_IMAGE="${NGINX_IMAGE}" \
  --push .

echo ">>> 推送完成"
echo "    ${REGISTRY}/${NS}/geoflow-app-prod:${VERSION}"
echo "    ${REGISTRY}/${NS}/geoflow-web-prod:${VERSION}"
if [ "${PUSH_LATEST}" = "1" ]; then
  echo "    ${REGISTRY}/${NS}/geoflow-app-prod:latest"
  echo "    ${REGISTRY}/${NS}/geoflow-web-prod:latest"
fi
