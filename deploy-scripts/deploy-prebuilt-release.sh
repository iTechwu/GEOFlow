#!/usr/bin/env bash
set -Eeuo pipefail

# Deploy immutable prebuilt images on the CI host. PostgreSQL, Redis and
# RabbitMQ remain external services managed by docker-helm.dofe.ai.

APP_DIR="${GEOFLOW_APP_DIR:-$(pwd)}"
DRAIN_TIMEOUT="${GEOFLOW_DRAIN_TIMEOUT_SECONDS:-300}"
RELEASE_MODE="${GEOFLOW_RELEASE_MODE:-upgrade}"
RELEASE_STARTED="0"
MAINTENANCE_SECRET=""
RESOLVED_IMAGES=""

log() {
  printf '\033[1;34m[geoflow-release]\033[0m %s\n' "$*"
}

fail() {
  printf '\033[1;31m[error]\033[0m %s\n' "$*" >&2
  exit 1
}

seal_maintenance_mode() {
  if "${COMPOSE[@]}" exec -T app php artisan down --no-interaction --quiet >/dev/null 2>&1; then
    return 0
  fi

  "${COMPOSE[@]}" run --rm --no-deps \
    -e AUTO_MIGRATE=false \
    -e AUTO_INSTALL_ONCE=false \
    app php artisan down --no-interaction --quiet >/dev/null 2>&1
}

on_exit() {
  local exit_code="$?"
  local seal_failed="0"

  trap - EXIT INT TERM
  if [ "$RELEASE_STARTED" = "1" ]; then
    set +e
    log "Release failed; sealing the maintenance bypass before exit."
    if ! seal_maintenance_mode; then
      seal_failed="1"
      printf '\033[1;31m[error]\033[0m Failed to seal the maintenance bypass; remove the stored maintenance secret before accepting traffic.\n' >&2
    fi
    "${COMPOSE[@]}" logs --tail=120 app queue scheduler web >&2 || true
  fi

  MAINTENANCE_SECRET=""
  if [ "$exit_code" -eq 0 ] && [ "$seal_failed" = "1" ]; then
    exit_code="1"
  fi

  exit "$exit_code"
}
trap on_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

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

validate_resolved_images() {
  local resolved_images="$1"
  local image final_component version_tag
  local found="0"
  local release_tag=""

  while IFS= read -r image; do
    [ -n "$image" ] || continue
    found="1"
    image_is_immutable "$image" || fail "Release images must use immutable version or digest references, not latest"

    case "$image" in
      *@sha256:*) continue ;;
    esac

    final_component="${image##*/}"
    version_tag="${final_component##*:}"
    if [ -z "$release_tag" ]; then
      release_tag="$version_tag"
    elif [ "$version_tag" != "$release_tag" ]; then
      fail "Release images must use the same version tag"
    fi
  done <<< "$resolved_images"

  [ "$found" = "1" ] || fail "Docker Compose did not resolve any release images"
}

image_revision() {
  docker image inspect \
    --format '{{ index .Config.Labels "org.opencontainers.image.revision" }}' \
    "$1" 2>/dev/null
}

validate_pulled_image_revisions() {
  local resolved_images="$1"
  local image revision
  local release_revision=""
  local found="0"

  while IFS= read -r image; do
    [ -n "$image" ] || continue
    found="1"
    revision="$(image_revision "$image" | tr -d '\r\n')" || fail "Cannot inspect the pulled release image"
    case "$revision" in
      ''|'<no value>') fail "Every release image must contain an OCI revision label" ;;
    esac

    if [ -z "$release_revision" ]; then
      release_revision="$revision"
    elif [ "$revision" != "$release_revision" ]; then
      fail "Release images must use the same OCI revision"
    fi
  done <<< "$resolved_images"

  [ "$found" = "1" ] || fail "Docker Compose did not resolve any release images"
}

validate_release() {
  [ -d "$APP_DIR" ] || fail "GEOFLOW_APP_DIR does not exist: ${APP_DIR}"
  [ -f "${APP_DIR}/.env.prod" ] || fail ".env.prod not found in ${APP_DIR}"
  [ -f "${APP_DIR}/docker-compose.prebuilt.yml" ] || fail "docker-compose.prebuilt.yml not found in ${APP_DIR}"
  [ -x "${APP_DIR}/deploy-scripts/geoflow-healthcheck.sh" ] || fail "geoflow-healthcheck.sh is missing or not executable"
  command -v docker >/dev/null 2>&1 || fail "Docker is required"
  command -v openssl >/dev/null 2>&1 || fail "OpenSSL is required to generate an ephemeral maintenance secret"
  docker info >/dev/null 2>&1 || fail "Docker is not usable by this CI runner"
  docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 is required"

  case "$DRAIN_TIMEOUT" in
    ''|*[!0-9]*) fail "GEOFLOW_DRAIN_TIMEOUT_SECONDS must be a positive integer" ;;
  esac
  [ "$DRAIN_TIMEOUT" -gt 0 ] || fail "GEOFLOW_DRAIN_TIMEOUT_SECONDS must be a positive integer"

  case "$RELEASE_MODE" in
    fresh|upgrade) ;;
    *) fail "GEOFLOW_RELEASE_MODE must be fresh or upgrade" ;;
  esac

  docker compose --env-file "${APP_DIR}/.env.prod" -f "${APP_DIR}/docker-compose.prebuilt.yml" config --quiet
  RESOLVED_IMAGES="$(docker compose --env-file "${APP_DIR}/.env.prod" -f "${APP_DIR}/docker-compose.prebuilt.yml" config --images)"
  validate_resolved_images "$RESOLVED_IMAGES"
  bash "${APP_DIR}/deploy-scripts/check-compose-boundaries.sh"
}

service_is_running() {
  local service="$1"
  "${COMPOSE[@]}" ps --status running --services | grep -qx "$service"
}

remove_legacy_init_container() {
  local container="geoflow-init-prod"
  local running

  if ! docker inspect "$container" >/dev/null 2>&1; then
    return
  fi

  running="$(docker inspect --format '{{.State.Running}}' "$container")"
  [ "$running" != "true" ] || fail "Legacy init container is still running: ${container}"
  log "Removing stopped legacy init container: ${container}"
  docker rm "$container" >/dev/null
}

enter_maintenance_and_drain() {
  local service

  if service_is_running app; then
    log "Entering Laravel maintenance mode."
    "${COMPOSE[@]}" exec -T app php artisan down --secret="$MAINTENANCE_SECRET" --no-interaction --quiet
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
    -e AUTO_MIGRATE=false \
    -e AUTO_INSTALL_ONCE=false \
    app php artisan migrate --force --no-interaction
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
    -e AUTO_MIGRATE=false \
    -e AUTO_INSTALL_ONCE=false \
    app php artisan migrate --force --no-interaction

  log "Initializing GEOFlow application data once."
  "${COMPOSE[@]}" run --rm --no-deps \
    -e GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=true \
    -e GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED=false \
    -e AUTO_MIGRATE=false \
    -e AUTO_INSTALL_ONCE=false \
    app php artisan geoflow:install --no-interaction
}

run_readiness_gates() {
  log "Checking managed-image readiness."
  "${COMPOSE[@]}" run --rm --no-deps app php artisan geoflow:managed-images:readiness --no-interaction

  log "Running the read-only production security audit."
  "${COMPOSE[@]}" run --rm --no-deps app php artisan geoflow:security-audit --no-interaction
}

ensure_maintenance_mode() {
  log "Persisting maintenance mode for pre-release verification."
  "${COMPOSE[@]}" run --rm --no-deps app php artisan down --secret="$MAINTENANCE_SECRET" --no-interaction --quiet
}

start_release() {
  log "Starting the new release in maintenance mode."
  "${COMPOSE[@]}" up -d --no-deps app queue scheduler reverb web
}

main() {
  validate_release
  cd "$APP_DIR"
  COMPOSE=(docker compose --env-file .env.prod -f docker-compose.prebuilt.yml)
  remove_legacy_init_container

  log "Pulling immutable production images."
  "${COMPOSE[@]}" pull
  validate_pulled_image_revisions "$RESOLVED_IMAGES"

  MAINTENANCE_SECRET="$(openssl rand -hex 32)"
  [ -n "$MAINTENANCE_SECRET" ] || fail "Failed to generate the ephemeral maintenance secret"
  RELEASE_STARTED="1"
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

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  main "$@"
fi
