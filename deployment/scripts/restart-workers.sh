#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
source "${SCRIPT_DIR}/deploy.config.sh"

COLOR="${COLOR:-${1:-}}"
err() { echo "[restart-workers] ERROR: $*" >&2; exit 1; }
log() { echo "[restart-workers] $*"; }

LOCK_DIR="${DEPLOY_DIR}/.deploy-lock"
mkdir -p "${LOCK_DIR}"
LOCK_FILE="${LOCK_DIR}/zero-downtime.lock"

case "${COLOR}" in
    blue|green) ;;
    *) err "COLOR must be blue or green" ;;
esac

ENV_FILE="${DEPLOY_DIR}/.env.${COLOR}"
PROJECT="${COMPOSE_PROJECT_BASE}-${COLOR}"
[[ -f "${ENV_FILE}" ]] || err "Color env file not found: ${ENV_FILE}"

compose_profiles=()
services=()
if [[ "${QUEUE_WORKER_ENABLED:-true}" == "true" ]]; then
    compose_profiles+=(--profile with-queue)
    services+=(queue-worker)
fi
if [[ "${SCHEDULER_ENABLED:-true}" == "true" ]]; then
    compose_profiles+=(--profile with-scheduler)
    services+=(scheduler)
fi
[[ "${REVERB_ENABLED:-false}" == "true" ]] && compose_profiles+=(--profile with-reverb)
[[ "${#services[@]}" -gt 0 ]] || err "Queue worker and scheduler services are disabled for this app"

(
    flock 9
    docker compose -p "${PROJECT}" "${compose_profiles[@]}" -f "${DEPLOY_DIR}/config/docker-compose.prod.yml" --env-file "${ENV_FILE}" up -d "${services[@]}"
    mkdir -p "${DEPLOY_DIR}/.deploy"
    {
        printf 'deploy_id=%s\n' "$(grep -m1 '^deploy_id=' "${DEPLOY_DIR}/.deploy/current-state" 2>/dev/null | cut -d= -f2- || true)"
        printf 'active_color=%s\n' "$(cat "${DEPLOY_DIR}/.deploy/active-color" 2>/dev/null || true)"
        printf 'target_color=%s\n' "${COLOR}"
        printf 'state=FAILED_WORKERS_RESTORED\n'
        printf 'updated_at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    } > "${DEPLOY_DIR}/.deploy/current-state"
    log "Restarted queue workers and scheduler for ${PROJECT}"
) 9>"${LOCK_FILE}"
