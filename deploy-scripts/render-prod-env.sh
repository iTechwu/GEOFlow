#!/usr/bin/env bash
set -Eeuo pipefail

# Render .env.prod from the committed template and CI-provided environment
# variables. Secret values are never printed.

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEMPLATE="${GEOFLOW_ENV_TEMPLATE:-${ROOT}/.env.prod.example}"
TARGET="${1:-${ROOT}/.env.prod}"
TMP_FILE=""
WORK_FILE=""

fail() {
  printf '[geoflow-env] error: %s\n' "$*" >&2
  exit 1
}

cleanup() {
  if [ -n "$TMP_FILE" ] && [ -f "$TMP_FILE" ]; then
    rm -f "$TMP_FILE"
  fi
  if [ -n "$WORK_FILE" ] && [ -f "$WORK_FILE" ]; then
    rm -f "$WORK_FILE"
  fi
}
trap cleanup EXIT

validate_value() {
  local key="$1"
  local value="$2"

  case "$value" in
    *$'\n'*|*$'\r'*) fail "${key} must be a single-line value" ;;
  esac
}

set_env_value() {
  local file="$1"
  local key="$2"
  local value="$3"
  local replaced="0"
  local line

  validate_value "$key" "$value"
  TMP_FILE="$(mktemp "${file}.tmp.XXXXXX")"
  while IFS= read -r line || [ -n "$line" ]; do
    if [[ "$line" == "${key}="* ]]; then
      printf '%s=%s\n' "$key" "$value" >> "$TMP_FILE"
      replaced="1"
    else
      printf '%s\n' "$line" >> "$TMP_FILE"
    fi
  done < "$file"

  if [ "$replaced" = "0" ]; then
    printf '%s=%s\n' "$key" "$value" >> "$TMP_FILE"
  fi

  chmod 600 "$TMP_FILE"
  mv "$TMP_FILE" "$file"
  TMP_FILE=""
}

required_keys=(
  APP_KEY APP_URL
  DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD
  REDIS_HOST REDIS_PASSWORD
  MODELS_BASE_URL MODELS_API_KEY
  MODELS_INTERNAL_BASE_URL MODELS_INTERNAL_API_SECRET
  SSO_API_URL SSO_ISSUER SSO_CLIENT_ID SSO_CLIENT_SECRET SSO_REDIRECT_URI
  REVERB_APP_SECRET
  GEOFLOW_APP_IMAGE GEOFLOW_WEB_IMAGE
  DOCKER_COMMON_NETWORK_NAME
)

optional_keys=(
  APP_DEBUG TRUSTED_PROXIES ADMIN_BASE_PATH WEB_PORT REVERB_EXPOSE_PORT
  DB_PORT REDIS_PORT MODELS_SERVICE_NAME
  SSO_INTERNAL_API_URL SSO_DISCOVERY_URL SSO_SERVICE_NAME SSO_SCOPE
  GEOFLOW_MCP_ENABLED GEOFLOW_MCP_TOKEN GEOFLOW_MCP_READ_TOKEN
  GEOFLOW_MCP_ALLOW_SYSTEM_TOKEN GEOFLOW_MCP_SERVER_NAME
)

[ -f "$TEMPLATE" ] || fail "template not found: ${TEMPLATE}"

for key in "${required_keys[@]}"; do
  value="${!key:-}"
  [ -n "$value" ] || fail "required CI variable is missing: ${key}"
  validate_value "$key" "$value"
done

for key in "${optional_keys[@]}"; do
  if [ -n "${!key+x}" ]; then
    validate_value "$key" "${!key}"
  fi
done

mcp_enabled="${GEOFLOW_MCP_ENABLED:-true}"
allow_system_token="${GEOFLOW_MCP_ALLOW_SYSTEM_TOKEN:-true}"
if [ "$mcp_enabled" = "true" ] && [ "$allow_system_token" != "false" ] && [ -z "${GEOFLOW_MCP_TOKEN:-}" ]; then
  fail "GEOFLOW_MCP_TOKEN is required when MCP and system-token access are enabled"
fi

umask 077
mkdir -p "$(dirname "$TARGET")"
WORK_FILE="$(mktemp "${TARGET}.render.XXXXXX")"
cp "$TEMPLATE" "$WORK_FILE"
chmod 600 "$WORK_FILE"

for key in "${required_keys[@]}"; do
  set_env_value "$WORK_FILE" "$key" "${!key}"
done

for key in "${optional_keys[@]}"; do
  if [ -n "${!key+x}" ]; then
    set_env_value "$WORK_FILE" "$key" "${!key}"
  fi
done

mv "$WORK_FILE" "$TARGET"
WORK_FILE=""

printf '[geoflow-env] rendered %s with mode 0600\n' "$TARGET"
