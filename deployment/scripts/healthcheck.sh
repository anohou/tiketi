#!/usr/bin/env bash
# ╔══════════════════════════════════════════════════════════════════════════════╗
# ║  healthcheck.sh — Wait for php-fpm, then verify nginx HTTP endpoint        ║
# ╚══════════════════════════════════════════════════════════════════════════════╝
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/deploy.config.sh"

PROJECT_PREFIX="${COMPOSE_PROJECT_NAME:-${COMPOSE_PROJECT_BASE}}"

if [[ -n "${1:-}" ]]; then
    TARGET_CONTAINER="$1"
else
    # Auto-detect the running php-fpm container for this project
    TARGET_CONTAINER=$(docker ps --format '{{.Names}}' | grep -E "^${PROJECT_PREFIX}.*php-fpm-1$" | head -1)
    if [[ -z "$TARGET_CONTAINER" ]]; then
        echo "[healthcheck] ERROR: No running php-fpm container found for project '${PROJECT_PREFIX}'." >&2
        exit 1
    fi
    echo "[healthcheck] Auto-detected running container: ${TARGET_CONTAINER}"
fi

TIMEOUT="${2:-${HEALTH_TIMEOUT:-60}}"

log()  { echo "[healthcheck] $*"; }
err()  { echo "[healthcheck] ERROR: $*" >&2; exit 1; }

resolve_http_path() {
    local configured_url path

    configured_url="${HEALTH_URL:-http://127.0.0.1/up}"
    path="$(printf '%s' "${configured_url}" | sed -E 's#^[a-zA-Z]+://[^/]+##')"

    if [[ -z "${path}" ]]; then
        path="/"
    fi

    printf '%s\n' "${path}"
}

# Derive nginx container name from php-fpm name
if [[ "${TARGET_CONTAINER}" == *-php-fpm-1 ]]; then
    PHP_FPM_CONTAINER="${TARGET_CONTAINER}"
    NGINX_CONTAINER="${TARGET_CONTAINER%-php-fpm-1}-nginx-1"
elif [[ "${TARGET_CONTAINER}" == *-nginx-1 ]]; then
    NGINX_CONTAINER="${TARGET_CONTAINER}"
    PHP_FPM_CONTAINER="${TARGET_CONTAINER%-nginx-1}-php-fpm-1"
else
    PHP_FPM_CONTAINER="${TARGET_CONTAINER}-php-fpm-1"
    NGINX_CONTAINER="${TARGET_CONTAINER}-nginx-1"
fi

# ── Wait for php-fpm to become healthy ───────────────────────────────────────
if ! docker inspect --format='{{.State.Health.Status}}' "$PHP_FPM_CONTAINER" > /dev/null 2>&1; then
    log "Container '$PHP_FPM_CONTAINER' has no healthcheck. Waiting 10s as grace period ..."
    sleep 10
else
    log "Waiting for '${PHP_FPM_CONTAINER}' to become healthy (timeout: ${TIMEOUT}s) ..."
    elapsed=0
    while [[ $elapsed -lt $TIMEOUT ]]; do
        STATUS=$(docker inspect --format='{{.State.Health.Status}}' "$PHP_FPM_CONTAINER" 2>/dev/null || echo "not-found")
        if [[ "$STATUS" == "healthy" ]]; then
            log "✓ php-fpm container is healthy"
            break
        elif [[ "$STATUS" == "unhealthy" ]]; then
            err "Container reported 'unhealthy' status!"
        fi
        sleep 3
        elapsed=$((elapsed + 3))
    done
    [[ $elapsed -lt $TIMEOUT ]] || err "Timed out waiting for container to become healthy."
fi

docker inspect "$NGINX_CONTAINER" > /dev/null 2>&1 \
    || err "Nginx container '${NGINX_CONTAINER}' is not running."

# ── Poll configured HTTP endpoint ─────────────────────────────────────────────
HTTP_PATH="$(resolve_http_path)"
EXPECTED_STATUS="${HEALTH_EXPECTED_STATUS:-200}"
log "Waiting for HTTP '${HTTP_PATH}' on '${NGINX_CONTAINER}' (expecting ${EXPECTED_STATUS}, timeout: ${TIMEOUT}s) ..."
elapsed=0
while [[ $elapsed -lt $TIMEOUT ]]; do
    RESPONSE_HEADERS="$(
        docker exec "$NGINX_CONTAINER" sh -lc \
            "wget -S -O /dev/null 'http://127.0.0.1:8080${HTTP_PATH}'" \
            2>&1 || true
    )"
    STATUS_CODE="$(printf '%s\n' "${RESPONSE_HEADERS}" | awk '/^  HTTP\// {code=$2} END {print code}')"
    if [[ "${STATUS_CODE}" == "${EXPECTED_STATUS}" ]]; then
        log "✓ HTTP check passed: ${HTTP_PATH} (${STATUS_CODE})"
        break
    fi
    sleep 3
    elapsed=$((elapsed + 3))
done
[[ $elapsed -lt $TIMEOUT ]] || err "Timed out waiting for HTTP endpoint '${HTTP_PATH}' (last status: ${STATUS_CODE:-unknown})."
