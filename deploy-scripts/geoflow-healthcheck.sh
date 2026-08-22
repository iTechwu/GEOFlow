#!/usr/bin/env bash
set -Eeuo pipefail

# GEOFlow production Docker healthcheck helper.
# Run from the repository root or set GEOFLOW_APP_DIR=/path/to/GEOFlow.

APP_DIR="${GEOFLOW_APP_DIR:-$(pwd)}"
COMPOSE_FILE="${GEOFLOW_COMPOSE_FILE:-docker-compose.prod.yml}"
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

runtime_env_value() {
  local key="$1"
  "${COMPOSE[@]}" exec -T app sh -c 'printenv "$1" 2>/dev/null || true' sh "$key" | tr -d '\r'
}

published_web_port() {
  local binding port
  binding="$("${COMPOSE[@]}" port web 80 2>/dev/null | head -n1)"
  [ -n "$binding" ] || fail "The web service does not expose container port 80."

  port="${binding##*:}"
  [[ "$port" =~ ^[0-9]+$ ]] || fail "Cannot determine the published web port from Docker Compose."
  printf '%s' "$port"
}

wait_for_services() {
  local required="app web queue scheduler reverb"
  local attempt service missing container_id health_status

  attempt=1
  while [ "$attempt" -le "$WAIT_ATTEMPTS" ]; do
    missing=""
    for service in $required; do
      container_id="$("${COMPOSE[@]}" ps -q "$service")"
      if [ -z "$container_id" ]; then
        missing="${missing} ${service}(missing)"
        continue
      fi

      if ! health_status="$("${DOCKER_CMD[@]}" inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}' "$container_id" 2>/dev/null)"; then
        missing="${missing} ${service}(inspect-failed)"
        continue
      fi
      if [ "$health_status" != "healthy" ]; then
        missing="${missing} ${service}(${health_status})"
      fi
    done

    if [ -z "$missing" ]; then
      for service in $required; do
        log "Service healthy: ${service}"
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
  base_url="$(runtime_env_value MODELS_INTERNAL_BASE_URL)"
  secret="$(runtime_env_value MODELS_INTERNAL_API_SECRET)"
  secret="${secret:-$(runtime_env_value INTERNAL_API_SECRET)}"
  required="${GEOFLOW_HEALTHCHECK_REQUIRE_MODELS_INTERNAL:-1}"

  if [ -z "$base_url" ] || [ -z "$secret" ]; then
    if [ "$required" = "1" ]; then
      fail "models internal check is required, but MODELS_INTERNAL_BASE_URL or MODELS_INTERNAL_API_SECRET (or legacy INTERNAL_API_SECRET) is missing."
    fi

    warn "models internal HMAC is not fully configured; skipping the optional management-plane check."
    return
  fi

  log "Checking models internal HMAC connectivity."
  "${COMPOSE[@]}" exec -T app php artisan geoflow:models-internal-check --no-interaction
}

check_models_gateway() {
  log "Checking models public Chat and Embedding connectivity."
  "${COMPOSE[@]}" exec -T app php artisan geoflow:models-gateway-check --no-interaction
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
  local web_port="$1"
  local enabled allow_system token url response maintenance_secret maintenance_cookie
  enabled="$(runtime_env_value GEOFLOW_MCP_ENABLED)"
  case "$enabled" in
    true) ;;
    false|"")
      log "MCP is disabled; skipping protocol smoke checks."
      return
      ;;
    *) fail "GEOFLOW_MCP_ENABLED must resolve to true or false in the running app container." ;;
  esac

  allow_system="$(runtime_env_value GEOFLOW_MCP_ALLOW_SYSTEM_TOKEN)"
  case "$allow_system" in
    true|false) ;;
    *) fail "GEOFLOW_MCP_ALLOW_SYSTEM_TOKEN must resolve to true or false in the running app container." ;;
  esac

  token="${GEOFLOW_MCP_SMOKE_TOKEN:-$(runtime_env_value GEOFLOW_MCP_READ_TOKEN)}"
  token="${token:-$(runtime_env_value GEOFLOW_MCP_TOKEN)}"

  if [ "$allow_system" = "false" ] && [ -z "${GEOFLOW_MCP_SMOKE_TOKEN:-}" ]; then
    fail "MCP system tokens are disabled; set GEOFLOW_MCP_SMOKE_TOKEN to a short-lived SSO token for deployment verification."
  fi
  [ -n "$token" ] || fail "MCP is enabled, but no read, write, or explicit smoke token is configured."
  case "$token" in
    *$'\r'*|*$'\n'*) fail "MCP smoke tokens must not contain line breaks." ;;
  esac

  MCP_HEADER_FILE="$(mktemp)"
  chmod 600 "$MCP_HEADER_FILE"
  printf 'Authorization: Bearer %s\n' "$token" > "$MCP_HEADER_FILE"

  maintenance_secret="${GEOFLOW_HEALTHCHECK_MAINTENANCE_SECRET:-}"
  case "$maintenance_secret" in
    *$'\r'*|*$'\n'*) fail "The maintenance healthcheck secret must not contain line breaks." ;;
  esac
  if [ -n "$maintenance_secret" ]; then
    maintenance_cookie="$(
      printf '%s' "$maintenance_secret" | "${COMPOSE[@]}" exec -T app php -r '
        $secret = stream_get_contents(STDIN);
        $expiresAt = time() + 3600;
        echo base64_encode((string) json_encode([
            "expires_at" => $expiresAt,
            "mac" => hash_hmac("sha256", (string) $expiresAt, $secret),
        ]));
      '
    )"
    [ -n "$maintenance_cookie" ] || fail "Failed to create the maintenance bypass cookie."
    printf 'Cookie: laravel_maintenance=%s\n' "$maintenance_cookie" >> "$MCP_HEADER_FILE"
  fi

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
  case "$COMPOSE_FILE" in
    docker-compose.prod.yml|docker-compose.prebuilt.yml) ;;
    *) fail "GEOFLOW_COMPOSE_FILE must select docker-compose.prod.yml or docker-compose.prebuilt.yml." ;;
  esac
  [ -f "${APP_DIR}/${COMPOSE_FILE}" ] || fail "${COMPOSE_FILE} not found in ${APP_DIR}"
  [ -f "${APP_DIR}/.env.prod" ] || fail ".env.prod not found in ${APP_DIR}"

  detect_docker_command
  cd "$APP_DIR"

  COMPOSE=("${DOCKER_CMD[@]}" compose --env-file .env.prod -f "$COMPOSE_FILE")
  local web_port

  log "Checking container status."
  "${COMPOSE[@]}" ps
  wait_for_services
  web_port="$(published_web_port)"
  check_http "$web_port"

  log "Checking Laravel database connection."
  if "${COMPOSE[@]}" exec -T app php artisan migrate:status --pending=1 --no-interaction >/dev/null; then
    log "Database connection is reachable and no migrations are pending."
  else
    fail "Laravel cannot read migration status or still has pending migrations. Run the gated migration step before releasing services."
  fi

  check_models_gateway
  check_models_internal
  check_mcp "$web_port"

  log "Recent application logs:"
  "${COMPOSE[@]}" logs --tail=80 app queue scheduler web || true
}

main "$@"
