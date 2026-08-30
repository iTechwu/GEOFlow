#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FIXTURE_DIR="$(mktemp -d "${TMPDIR:-/tmp}/geoflow-compose-boundary.XXXXXX")"
trap 'rm -rf "$FIXTURE_DIR"' EXIT

assert_passes() {
  local name="$1"
  local body="$2"
  local file="${FIXTURE_DIR}/${name}.yml"
  printf 'services:\n%s\n' "$body" > "$file"
  bash "${ROOT}/deploy-scripts/check-compose-boundaries.sh" "$file" >/dev/null
}

assert_fails() {
  local name="$1"
  local body="$2"
  local file="${FIXTURE_DIR}/${name}.yml"
  printf 'services:\n%s\n' "$body" > "$file"
  if bash "${ROOT}/deploy-scripts/check-compose-boundaries.sh" "$file" >/dev/null 2>&1; then
    printf 'Expected boundary rejection for %s\n' "$name" >&2
    exit 1
  fi
}

assert_passes app $'  app:\n    image: example/app:1'
assert_fails postgres $'  postgres:\n    image: postgres:17'
assert_fails init $'  init:\n    image: example/app:1'
assert_fails migrate $'  migrate:\n    image: example/app:1'
assert_fails neo4j $'  neo4j:\n    image: neo4j:5'
assert_fails minio $'  minio:\n    image: minio/minio:latest'

printf '[compose-boundary-test] PASS\n'
