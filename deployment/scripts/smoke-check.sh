#!/usr/bin/env bash
# ╔══════════════════════════════════════════════════════════════════════════════╗
# ║  smoke-check.sh — Validate gated runtime before traffic promotion          ║
# ╚══════════════════════════════════════════════════════════════════════════════╝
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
source "${SCRIPT_DIR}/deploy.config.sh"

COMPOSE_FILE="${DEPLOY_COMPOSE_FILE:-${DEPLOY_DIR}/config/docker-compose.prod.yml}"
ENV_FILE="${DEPLOY_ENV_FILE:-${DEPLOY_DIR}/.env}"
RESTORED_URIS_FILE="${RESTORED_URIS_FILE:-${DEPLOY_DIR}/.deploy-state/restored-uris.txt}"
PROBE_URL_HEADER="X-Deploy-Smoke: 1"
RUN_EXTERNAL_SMOKE="${RUN_EXTERNAL_SMOKE:-false}"

log() { echo "[smoke] $(date '+%H:%M:%S') $*"; }
err() { echo "[smoke] ERROR: $*" >&2; exit 1; }

docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" ps nginx >/dev/null 2>&1 \
    || err "nginx service is not available"

find_first_asset_path() {
    docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" exec -T nginx sh -lc \
        "find /var/www/html/public/build -type f \\( -name '*.css' -o -name '*.js' \\) | head -n 1 | sed 's#^/var/www/html/public##'"
}

fetch_local_status() {
    local uri="$1"
    docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" exec -T nginx sh -lc \
        "wget --header='${PROBE_URL_HEADER}' -S -O /dev/null 'http://127.0.0.1:8080${uri}'" \
        2>&1 | awk '/^  HTTP\// {code=$2} END {print code}'
}

assert_local_fetch() {
    local uri="$1"
    local expected="${2:-200}"
    local status
    status="$(fetch_local_status "${uri}")"
    [[ "${status}" == "${expected}" ]] || err "Expected ${uri} to return ${expected}, got ${status:-unknown}"
    log "✓ ${uri} returned ${status}"
}

fetch_external_status() {
    local url="$1"

    if command -v curl >/dev/null 2>&1; then
        curl -k -sS -o /dev/null -w '%{http_code}' -H "${PROBE_URL_HEADER}" "${url}"
        return 0
    fi

    if command -v wget >/dev/null 2>&1; then
        wget --header="${PROBE_URL_HEADER}" -S -O /dev/null "${url}" 2>&1 \
            | awk '/^  HTTP\// {code=$2} END {print code}'
        return 0
    fi

    err "Neither curl nor wget is available for external smoke checks"
}

assert_external_fetch() {
    local url="$1"
    local expected="${2:-200}"
    local status

    status="$(fetch_external_status "${url}")"
    [[ "${status}" == "${expected}" ]] || err "Expected ${url} to return ${expected}, got ${status:-unknown}"
    log "✓ external ${url} returned ${status}"
}

assert_external_fetch_any() {
    local url="$1"
    shift
    local status expected

    status="$(fetch_external_status "${url}")"
    for expected in "$@"; do
        if [[ "${status}" == "${expected}" ]]; then
            log "✓ external ${url} returned ${status}"
            return 0
        fi
    done

    err "Expected ${url} to return one of [$*], got ${status:-unknown}"
}

external_readiness_url() {
    # Readiness is served at the host root, even when the app itself is mounted
    # behind a public path prefix and Traefik strips that prefix before nginx.
    printf '%s\n' "${APP_URL}" | sed -E 's#^(https?://[^/]+).*$#\1/readyz#'
}

ASSET_PATH="$(find_first_asset_path)"
[[ -n "${ASSET_PATH}" ]] || err "No image-baked asset found under public/build"
assert_local_fetch "${ASSET_PATH}" "200"

docker compose -f "${COMPOSE_FILE}" --env-file "${ENV_FILE}" exec -T php-fpm php artisan about --no-ansi >/dev/null \
    || err "Laravel boot smoke check failed"
log "✓ Laravel boots after cache rebuild"

assert_local_fetch "/storage/.deploy-smoke.txt" "200"

if [[ -f "${RESTORED_URIS_FILE}" ]]; then
    while IFS= read -r uri; do
        [[ -n "${uri}" ]] || continue
        assert_local_fetch "${uri}" "200"
    done < "${RESTORED_URIS_FILE}"
fi

if [[ "${RUN_EXTERNAL_SMOKE}" == "true" ]]; then
    assert_external_fetch_any "${APP_URL}" "200" "301" "302" "303" "307" "308"
    assert_external_fetch "$(external_readiness_url)" "200"
fi

log "✓ Smoke checks complete"
