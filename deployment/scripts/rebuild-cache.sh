#!/usr/bin/env bash
# ╔══════════════════════════════════════════════════════════════════════════════╗
# ║  rebuild-cache.sh — Deterministic Laravel cache rebuild                    ║
# ╚══════════════════════════════════════════════════════════════════════════════╝
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
COMPOSE_FILE="${DEPLOY_COMPOSE_FILE:-${DEPLOY_DIR}/config/docker-compose.prod.yml}"
ENV_FILE="${DEPLOY_ENV_FILE:-${DEPLOY_DIR}/.env}"

log() { echo "[cache-rebuild] $(date '+%H:%M:%S') $*"; }
err() { echo "[cache-rebuild] ERROR: $*" >&2; exit 1; }

[[ -f "${COMPOSE_FILE}" ]] || err "Compose file not found: ${COMPOSE_FILE}"
[[ -f "${ENV_FILE}" ]] || err "Generated env file not found: ${ENV_FILE}"

run_artisan() {
    local label="$1"
    shift
    log "${label}: php artisan $*"
    docker compose \
        -f "${COMPOSE_FILE}" \
        --env-file "${ENV_FILE}" \
        exec -T php-fpm \
        php artisan "$@" \
        || err "${label} failed."
}

run_artisan "Clearing caches" optimize:clear --no-ansi
run_artisan "Building config cache" config:cache --no-ansi
run_artisan "Building route cache" route:cache --no-ansi
run_artisan "Building view cache" view:cache --no-ansi

log "event:cache intentionally skipped for this rollout"
log "✓ Cache rebuild complete"
