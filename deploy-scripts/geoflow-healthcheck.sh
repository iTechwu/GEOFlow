#!/usr/bin/env bash
set -Eeuo pipefail

# GEOFlow production Docker healthcheck helper.
# Run from the repository root or set GEOFLOW_APP_DIR=/path/to/GEOFlow.

APP_DIR="${GEOFLOW_APP_DIR:-$(pwd)}"
WAIT_ATTEMPTS="${GEOFLOW_HEALTHCHECK_ATTEMPTS:-30}"
WAIT_SECONDS="${GEOFLOW_HEALTHCHECK_INTERVAL_SECONDS:-2}"
MCP_HEADER_FILE=""

log() {
  printf '\033[1;34m[geoflow-check]\033[0m %s\n' "$*"
}

warn() {
  printf '\033[1;33m[warn]\033[0m %s\n' "$*" >&2
}

fail() {
  printf '\033[1;31m[error]\033[0m %s\n' "$*" >&2
  exit 1
}

cleanup() {
  if [ -n "$MCP_HEADER_FILE" ] && [ -f "$MCP_HEADER_FILE" ]; then
    rm -f "$MCP_HEADER_FILE"
  fi
}
trap cleanup EXIT

detect_docker_command() {
  if docker info >/dev/null 2>&1; then
    DOCKER_CMD=(docker)
  elif command -v sudo >/dev/null 2>&1 && sudo docker info >/dev/null 2>&1; then
    DOCKER_CMD=(sudo docker)
  else
    fail "Docker is not available to this user."
  fi

  if ! "${DOCKER_CMD[@]}" compose version >/dev/null 2>&1; then
    fail "Docker Compose v2 plugin is required."
  fi
}

read_env_value() {
  local key="$1"
  local file="${APP_DIR}/.env.prod"
  grep "^${key}=" "$file" 2>/dev/null | tail -n1 | cut -d= -f2-
}

wait_for_services() {
  local required="app web queue scheduler reverb"
  local attempt service missing

  attempt=1
  while [ "$attempt" -le "$WAIT_ATTEMPTS" ]; do
    missing=""
    for service in $required; do
      if ! "${COMPOSE[@]}" ps --status running --services | grep -qx "$service"; then
        missing="${missing} ${service}"
      fi
    done

    if [ -z "$missing" ]; then
      for service in $required; do
        log "Service running: ${service}"
      done
      return
    fi

    [ "$attempt" -lt "$WAIT_ATTEMPTS" ] && sleep "$WAIT_SECONDS"
    attempt=$((attempt + 1))
  done

  fail "Required services did not become ready:${missing}."
}

check_http() {
  local web_port="$1"
  local url="http://127.0.0.1:${web_port}/up"
  local attempt

  if ! command -v curl >/dev/null 2>&1; then
    fail "curl is required for HTTP and MCP health checks."
  fi

  attempt=1
  while [ "$attempt" -le "$WAIT_ATTEMPTS" ]; do
    if curl -fsS --max-time 10 "$url" >/dev/null 2>&1; then
      log "HTTP health endpoint passed: ${url}"
      return
    fi

    [ "$attempt" -lt "$WAIT_ATTEMPTS" ] && sleep "$WAIT_SECONDS"
    attempt=$((attempt + 1))
  done

  fail "HTTP health endpoint failed: ${url}. Check the web/app containers and reverse proxy configuration."
}

check_models_internal() {
  local base_url secret required
  base_url="$(read_env_value MODELS_INTERNAL_BASE_URL)"
  secret="$(read_env_value MODELS_INTERNAL_API_SECRET)"
  required="${GEOFLOW_HEALTHCHECK_REQUIRE_MODELS_INTERNAL:-1}"

  if [ -z "$base_url" ] || [ -z "$secret" ]; then
    if [ "$required" = "1" ]; then
      fail "models internal check is required, but MODELS_INTERNAL_BASE_URL or MODELS_INTERNAL_API_SECRET is missing."
    fi

    warn "models internal HMAC is not fully configured; skipping the optional management-plane check."
    return
  fi

  log "Checking models internal HMAC connectivity."
  "${COMPOSE[@]}" exec -T app php artisan geoflow:models-internal-check --no-interaction
}

assert_mcp_response() {
  local check="$1"

  "${COMPOSE[@]}" exec -T app php -r '
    $payload = json_decode(stream_get_contents(STDIN), true);
    $check = $argv[1] ?? "";
    $valid = is_array($payload) && ! array_key_exists("error", $payload);

    if ($check === "initialize") {
        $valid = $valid && ($payload["result"]["protocolVersion"] ?? null) === "2025-06-18";
    } elseif ($check === "tools") {
        $names = array_column($payload["result"]["tools"] ?? [], "name");
        $valid = $valid && in_array("geoflow.catalog", $names, true);
    } elseif ($check === "catalog") {
        $valid = $valid && array_key_exists("structuredContent", $payload["result"] ?? []);
    } else {
        $valid = false;
    }

    if (! $valid) {
        fwrite(STDERR, "Invalid MCP {$check} response.\n");
        exit(1);
    }
  ' "$check"
}

mcp_request() {
  local url="$1"
  local payload="$2"

  curl -fsS --max-time 20 \
    --header "@${MCP_HEADER_FILE}" \
    --header 'Content-Type: application/json' \
    --data "$payload" \
    "$url"
}

check_mcp() {
  local enabled allow_system token web_port url response
  enabled="$(read_env_value GEOFLOW_MCP_ENABLED)"
  [ "$enabled" = "true" ] || {
    log "MCP is disabled; skipping protocol smoke checks."
    return
  }

  allow_system="$(read_env_value GEOFLOW_MCP_ALLOW_SYSTEM_TOKEN)"
  token="${GEOFLOW_MCP_SMOKE_TOKEN:-$(read_env_value GEOFLOW_MCP_READ_TOKEN)}"
  token="${token:-$(read_env_value GEOFLOW_MCP_TOKEN)}"

  if [ "$allow_system" = "false" ] && [ -z "${GEOFLOW_MCP_SMOKE_TOKEN:-}" ]; then
    fail "MCP system tokens are disabled; set GEOFLOW_MCP_SMOKE_TOKEN to a short-lived SSO token for deployment verification."
  fi
  [ -n "$token" ] || fail "MCP is enabled, but no read, write, or explicit smoke token is configured."

  MCP_HEADER_FILE="$(mktemp)"
  chmod 600 "$MCP_HEADER_FILE"
  printf 'Authorization: Bearer %s\n' "$token" > "$MCP_HEADER_FILE"

  web_port="$(read_env_value WEB_PORT)"
  web_port="${web_port:-18080}"
  url="http://127.0.0.1:${web_port}/mcp"

  log "Checking MCP initialize."
  response="$(mcp_request "$url" '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}')"
  printf '%s' "$response" | assert_mcp_response initialize

  log "Checking MCP tools/list."
  response="$(mcp_request "$url" '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}')"
  printf '%s' "$response" | assert_mcp_response tools

  log "Checking MCP geoflow.catalog read tool."
  response="$(mcp_request "$url" '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"geoflow.catalog","arguments":{}}}')"
  printf '%s' "$response" | assert_mcp_response catalog

  log "MCP protocol smoke checks passed."
}

main() {
  [ -d "$APP_DIR" ] || fail "APP_DIR does not exist: ${APP_DIR}"
  [ -f "${APP_DIR}/docker-compose.prod.yml" ] || fail "docker-compose.prod.yml not found in ${APP_DIR}"
  [ -f "${APP_DIR}/.env.prod" ] || fail ".env.prod not found in ${APP_DIR}"

  detect_docker_command
  cd "$APP_DIR"

  COMPOSE=("${DOCKER_CMD[@]}" compose --env-file .env.prod -f docker-compose.prod.yml)
  local web_port
  web_port="$(read_env_value WEB_PORT)"
  web_port="${web_port:-18080}"

  log "Checking container status."
  "${COMPOSE[@]}" ps
  wait_for_services
  check_http "$web_port"

  log "Checking Laravel database connection."
  if "${COMPOSE[@]}" exec -T app php artisan migrate:status --pending=1 --no-interaction >/dev/null; then
    log "Database connection is reachable and no migrations are pending."
  else
    fail "Laravel cannot read migration status or still has pending migrations. Run the gated migration step before releasing services."
  fi

  check_models_internal
  check_mcp

  log "Recent application logs:"
  "${COMPOSE[@]}" logs --tail=80 app queue scheduler web || true
}

main "$@"
