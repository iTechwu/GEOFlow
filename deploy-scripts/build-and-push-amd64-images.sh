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

COMPOSER_IMAGE="${COMPOSER_IMAGE:-composer:2}"
PHP_FPM_IMAGE="${PHP_FPM_IMAGE:-uhub.service.ucloud.cn/techwu/php:8.4-fpm-bookworm}"
PECL_REDIS_VERSION="${PECL_REDIS_VERSION:-6.3.0}"
NGINX_IMAGE="${NGINX_IMAGE:-nginx:1.31.1-alpine}"

cd "${PROJECT_DIR}"

fail() {
  echo ">>> 错误: $*" >&2
  exit 1
}

case "${VERSION}" in
  *[!A-Za-z0-9._-]*|'') fail "VERSION 只能包含字母、数字、点、下划线和连字符" ;;
esac

[ -n "${REGISTRY_USERNAME}" ] || fail "必须通过 CI Secret 提供 REGISTRY_USERNAME"
[ -n "${REGISTRY_PASSWORD}" ] || fail "必须通过 CI Secret 提供 REGISTRY_PASSWORD"

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
  --build-arg COMPOSER_IMAGE="${COMPOSER_IMAGE}" \
  --build-arg PHP_FPM_IMAGE="${PHP_FPM_IMAGE}" \
  --build-arg PECL_REDIS_VERSION="${PECL_REDIS_VERSION}" \
  --push .

echo ">>> 构建并推送 web 镜像"
docker buildx build --platform "${PLATFORM}" \
  -f docker/nginx/Dockerfile.prod \
  "${WEB_TAGS[@]}" \
  --build-arg NGINX_IMAGE="${NGINX_IMAGE}" \
  --push .

echo ">>> 推送完成"
echo "    ${REGISTRY}/${NS}/geoflow-app-prod:${VERSION}"
echo "    ${REGISTRY}/${NS}/geoflow-web-prod:${VERSION}"
if [ "${PUSH_LATEST}" = "1" ]; then
  echo "    ${REGISTRY}/${NS}/geoflow-app-prod:latest"
  echo "    ${REGISTRY}/${NS}/geoflow-web-prod:latest"
fi
