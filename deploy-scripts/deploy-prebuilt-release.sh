#!/usr/bin/env bash
set -Eeuo pipefail

# Deploy immutable prebuilt images on the CI host. PostgreSQL, Redis and
# RabbitMQ remain external services managed by docker-helm.dofe.ai.

APP_DIR="${GEOFLOW_APP_DIR:-$(pwd)}"
DRAIN_TIMEOUT="${GEOFLOW_DRAIN_TIMEOUT_SECONDS:-300}"
RELEASE_MODE="${GEOFLOW_RELEASE_MODE:-upgrade}"
RELEASE_STARTED="0"
MAINTENANCE_SECRET=""

log() {
  printf '\033[1;34m[geoflow-release]\033[0m %s\n' "$*"
}

fail() {
  printf '\033[1;31m[error]\033[0m %s\n' "$*" >&2
  exit 1
}

on_error() {
  local line="$1"

  if [ "$RELEASE_STARTED" = "1" ]; then
    log "Release failed; keeping the application in maintenance mode."
    "${COMPOSE[@]}" exec -T app php artisan down --no-interaction >/dev/null 2>&1 || true
    "${COMPOSE[@]}" logs --tail=120 app queue scheduler web >&2 || true
  fi

  printf '\033[1;31m[error]\033[0m Release failed near line %s.\n' "$line" >&2
}
trap 'on_error $LINENO' ERR

read_env_value() {
  local key="$1"
  grep "^${key}=" "${APP_DIR}/.env.prod" 2>/dev/null | tail -n1 | cut -d= -f2-
}

image_is_immutable() {
  local image="$1"
  local final_component="${image##*/}"

  case "$image" in
    *@sha256:*) return 0 ;;
  esac

  case "$final_component" in
    *:latest|*:latest@*) return 1 ;;
    *:*) return 0 ;;
    *) return 1 ;;
  esac
}

validate_release() {
  local app_image web_image

  [ -d "$APP_DIR" ] || fail "GEOFLOW_APP_DIR does not exist: ${APP_DIR}"
  [ -f "${APP_DIR}/.env.prod" ] || fail ".env.prod not found in ${APP_DIR}"
  [ -f "${APP_DIR}/docker-compose.prebuilt.yml" ] || fail "docker-compose.prebuilt.yml not found in ${APP_DIR}"
  [ -x "${APP_DIR}/deploy-scripts/geoflow-healthcheck.sh" ] || fail "geoflow-healthcheck.sh is missing or not executable"
  command -v docker >/dev/null 2>&1 || fail "Docker is required"
  command -v openssl >/dev/null 2>&1 || fail "OpenSSL is required to generate an ephemeral maintenance secret"
  docker info >/dev/null 2>&1 || fail "Docker is not usable by this CI runner"
  docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 is required"

  app_image="$(read_env_value GEOFLOW_APP_IMAGE)"
  web_image="$(read_env_value GEOFLOW_WEB_IMAGE)"
  [ -n "$app_image" ] || fail "GEOFLOW_APP_IMAGE is required in .env.prod"
  [ -n "$web_image" ] || fail "GEOFLOW_WEB_IMAGE is required in .env.prod"

  image_is_immutable "$app_image" || fail "Release images must use immutable version or digest references, not latest"
  image_is_immutable "$web_image" || fail "Release images must use immutable version or digest references, not latest"

  case "$DRAIN_TIMEOUT" in
    ''|*[!0-9]*) fail "GEOFLOW_DRAIN_TIMEOUT_SECONDS must be a positive integer" ;;
  esac
  [ "$DRAIN_TIMEOUT" -gt 0 ] || fail "GEOFLOW_DRAIN_TIMEOUT_SECONDS must be a positive integer"

  case "$RELEASE_MODE" in
    fresh|upgrade) ;;
    *) fail "GEOFLOW_RELEASE_MODE must be fresh or upgrade" ;;
  esac

  bash "${APP_DIR}/deploy-scripts/check-compose-boundaries.sh"
  docker compose --env-file "${APP_DIR}/.env.prod" -f "${APP_DIR}/docker-compose.prebuilt.yml" config --quiet
}

service_is_running() {
  local service="$1"
  "${COMPOSE[@]}" ps --status running --services | grep -qx "$service"
}

enter_maintenance_and_drain() {
  local service

  if service_is_running app; then
    log "Entering Laravel maintenance mode."
    "${COMPOSE[@]}" exec -T app php artisan down --secret="$MAINTENANCE_SECRET" --no-interaction
  fi

  log "Stopping ingress and background workers with a ${DRAIN_TIMEOUT}s timeout."
  "${COMPOSE[@]}" stop -t "$DRAIN_TIMEOUT" web queue scheduler reverb || true
  "${COMPOSE[@]}" stop -t "$DRAIN_TIMEOUT" app || true

  for service in app web queue scheduler reverb; do
    if service_is_running "$service"; then
      fail "Old service is still running after drain: ${service}"
    fi
  done
}

run_upgrade() {
  log "Running migrations with a one-time drained-upgrade confirmation."
  "${COMPOSE[@]}" run --rm --no-deps \
    -e GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=false \
    -e GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED=true \
    -e GEOFLOW_MANAGED_IMAGE_DELETION_ENABLED=false \
    -e AUTO_INSTALL_ONCE=false \
    init php artisan about
}

run_fresh_install() {
  local service

  for service in app web queue scheduler reverb; do
    if service_is_running "$service"; then
      fail "Fresh install requires all GEOFlow services to be stopped: ${service} is running"
    fi
  done

  log "Running the one-time fresh database installation."
  "${COMPOSE[@]}" run --rm --no-deps \
    -e GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=true \
    -e GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED=false \
    init php artisan about
}

run_readiness_gates() {
  log "Checking managed-image readiness."
  "${COMPOSE[@]}" run --rm --no-deps app php artisan geoflow:managed-images:readiness --no-interaction

  log "Running the read-only production security audit."
  "${COMPOSE[@]}" run --rm --no-deps app php artisan geoflow:security-audit --no-interaction
}

ensure_maintenance_mode() {
  log "Persisting maintenance mode for pre-release verification."
  "${COMPOSE[@]}" run --rm --no-deps app php artisan down --secret="$MAINTENANCE_SECRET" --no-interaction
}

start_release() {
  log "Starting the new release in maintenance mode."
  "${COMPOSE[@]}" up -d --no-deps app queue scheduler reverb web
}

main() {
  validate_release
  cd "$APP_DIR"
  COMPOSE=(docker compose --env-file .env.prod -f docker-compose.prebuilt.yml)

  log "Pulling immutable production images."
  "${COMPOSE[@]}" pull

  RELEASE_STARTED="1"
  MAINTENANCE_SECRET="$(openssl rand -hex 32)"
  [ -n "$MAINTENANCE_SECRET" ] || fail "Failed to generate the ephemeral maintenance secret"
  if [ "$RELEASE_MODE" = "fresh" ]; then
    run_fresh_install
  else
    enter_maintenance_and_drain
    run_upgrade
  fi
  run_readiness_gates
  ensure_maintenance_mode
  start_release

  GEOFLOW_APP_DIR="$APP_DIR" \
    GEOFLOW_COMPOSE_FILE=docker-compose.prebuilt.yml \
    GEOFLOW_HEALTHCHECK_MAINTENANCE_SECRET="$MAINTENANCE_SECRET" \
    bash deploy-scripts/geoflow-healthcheck.sh

  log "Leaving Laravel maintenance mode after all release gates passed."
  "${COMPOSE[@]}" exec -T app php artisan up --no-interaction
  RELEASE_STARTED="0"
  log "Release completed successfully."
}

main "$@"
