#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
source "${SCRIPT_DIR}/deploy.config.sh"

STATE_ROOT="${DEPLOY_DIR}/.deploy"
ACTIVE_COLOR_FILE="${STATE_ROOT}/active-color"
PREVIOUS_COLOR_FILE="${STATE_ROOT}/previous-color"
CURRENT_STATE_FILE="${STATE_ROOT}/current-state"
log() { echo "[rollback-zero-downtime] $(date '+%H:%M:%S') $*"; }
err() { echo "[rollback-zero-downtime] ERROR: $*" >&2; exit 1; }

LOCK_DIR="${DEPLOY_DIR}/.deploy-lock"
mkdir -p "${LOCK_DIR}"
LOCK_FILE="${LOCK_DIR}/zero-downtime.lock"

write_state() {
    local state="$1"
    mkdir -p "${STATE_ROOT}"
    {
        printf 'deploy_id=rollback-%s\n' "$(date -u +%Y%m%dT%H%M%SZ)"
        printf 'active_color=%s\n' "${ACTIVE_COLOR:-}"
        printf 'target_color=%s\n' "${PREVIOUS_COLOR:-}"
        printf 'state=%s\n' "${state}"
        printf 'updated_at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    } > "${CURRENT_STATE_FILE}"
}

compose_profiles() {
    [[ "${QUEUE_WORKER_ENABLED:-true}" == "true" ]] && printf '%s\n' "--profile" "with-queue"
    [[ "${SCHEDULER_ENABLED:-true}" == "true" ]] && printf '%s\n' "--profile" "with-scheduler"
    [[ "${REVERB_ENABLED:-false}" == "true" ]] && printf '%s\n' "--profile" "with-reverb"
}

compose_for() {
    local env_file="$1"
    local project_name="$2"
    shift 2
    local profiles=()
    while IFS= read -r profile; do
        [[ -n "${profile}" ]] && profiles+=("${profile}")
    done < <(compose_profiles)
    docker compose -p "${project_name}" "${profiles[@]}" -f "${DEPLOY_DIR}/config/docker-compose.prod.yml" --env-file "${env_file}" "$@"
}

verify_color_internal() {
    local env_file="$1" color="$2" headers status actual
    local project_name="$3"
    headers="$(compose_for "${env_file}" "${project_name}" exec -T nginx sh -lc "wget -S -O /dev/null --timeout=3 http://127.0.0.1:8080/readyz" 2>&1 || true)"
    status="$(printf '%s\n' "${headers}" | awk '/^  HTTP\// {code=$2} END {print code}')"
    actual="$(printf '%s\n' "${headers}" | tr -d '\r' | awk 'tolower($0) ~ /^[[:space:]]*x-deploy-color:/ {print $2; exit}')"
    [[ "${status}" == "200" && "${actual}" == "${color}" ]]
}

(
    flock 9
    ACTIVE_COLOR="$(cat "${ACTIVE_COLOR_FILE}" 2>/dev/null || true)"
    PREVIOUS_COLOR="$(cat "${PREVIOUS_COLOR_FILE}" 2>/dev/null || true)"
    case "${ACTIVE_COLOR}" in blue|green) ;; *) err "No active zero-downtime color recorded" ;; esac
    case "${PREVIOUS_COLOR}" in blue|green|legacy) ;; *) err "No previous zero-downtime color recorded" ;; esac

    ACTIVE_ENV_FILE="${DEPLOY_DIR}/.env.${ACTIVE_COLOR}"
    if [[ "${PREVIOUS_COLOR}" == "legacy" ]]; then
        PREVIOUS_ENV_FILE="${DEPLOY_DIR}/.env"
        PREVIOUS_PROJECT="${COMPOSE_PROJECT_BASE}"
    else
        PREVIOUS_ENV_FILE="${DEPLOY_DIR}/.env.${PREVIOUS_COLOR}"
        PREVIOUS_PROJECT="${COMPOSE_PROJECT_BASE}-${PREVIOUS_COLOR}"
    fi
    [[ -f "${PREVIOUS_ENV_FILE}" ]] || err "Previous color env file not found: ${PREVIOUS_ENV_FILE}"

    write_state ROLLING_BACK
    if [[ "${PREVIOUS_COLOR}" == "legacy" ]]; then
        if ! compose_for "${PREVIOUS_ENV_FILE}" "${PREVIOUS_PROJECT}" exec -T nginx sh -lc "wget -q -O /dev/null --timeout=3 http://127.0.0.1:8080/readyz" >/dev/null 2>&1; then
            write_state ROLLBACK_BLOCKED_PREVIOUS_UNHEALTHY
            err "Legacy previous stack is not healthy; refusing to switch traffic to it"
        fi
    elif ! verify_color_internal "${PREVIOUS_ENV_FILE}" "${PREVIOUS_COLOR}" "${PREVIOUS_PROJECT}"; then
        write_state ROLLBACK_BLOCKED_PREVIOUS_UNHEALTHY
        err "Previous color ${PREVIOUS_COLOR} is not healthy; refusing to switch traffic to it"
    fi

    compose_for "${ACTIVE_ENV_FILE}" "${COMPOSE_PROJECT_BASE}-${ACTIVE_COLOR}" stop queue-worker scheduler >/dev/null 2>&1 || true
    if [[ "${PREVIOUS_COLOR}" == "legacy" ]]; then
        dynamic_file="${TRAEFIK_DYNAMIC_FILE:-${TRAEFIK_DYNAMIC_DIR:-$(dirname "${DEPLOY_RUNTIME_ROOT}")/current/config/traefik/dynamic}/dynamic-${COMPOSE_PROJECT_BASE}.yml}"
        rm -f "${dynamic_file}"
        sleep "${TRAEFIK_FILE_RELOAD_MAX_SECONDS:-15}"
    else
        bash "${SCRIPT_DIR}/traefik-switch.sh" "${PREVIOUS_COLOR}" "${PREVIOUS_PROJECT}" "${PREVIOUS_ENV_FILE}"
        bash "${SCRIPT_DIR}/check-traefik-active-color.sh" "${PREVIOUS_COLOR}" "${APP_DOMAIN}" "/readyz"
    fi
    services=()
    [[ "${QUEUE_WORKER_ENABLED:-true}" == "true" ]] && services+=(queue-worker)
    [[ "${SCHEDULER_ENABLED:-true}" == "true" ]] && services+=(scheduler)
    [[ "${#services[@]}" -eq 0 ]] || compose_for "${PREVIOUS_ENV_FILE}" "${PREVIOUS_PROJECT}" up -d "${services[@]}"

    printf '%s\n' "${PREVIOUS_COLOR}" > "${ACTIVE_COLOR_FILE}"
    printf '%s\n' "${ACTIVE_COLOR}" > "${PREVIOUS_COLOR_FILE}"
    write_state ROLLED_BACK
    log "✓ Rolled back to ${PREVIOUS_COLOR}"
) 9>"${LOCK_FILE}"
