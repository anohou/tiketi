#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/deploy.config.sh"

EXPECTED_COLOR="${1:-}"
HOST="${2:-${APP_DOMAIN}}"
PATH_TO_CHECK="${3:-/readyz}"

err() { echo "[active-color] ERROR: $*" >&2; exit 1; }
log() { echo "[active-color] $*"; }
warn() { echo "[active-color] WARN: $*" >&2; }

[[ -n "${EXPECTED_COLOR}" ]] || err "Expected color is required"

attempts="${TRAEFIK_FILE_RELOAD_MAX_SECONDS:-15}"
last_headers=""
for ((i=1; i<=attempts; i++)); do
    if [[ -n "${DIRECT_ORIGIN_IP:-}" ]]; then
        last_headers="$(curl -k -sS -D - -o /dev/null --max-time 3 --resolve "${HOST}:443:${DIRECT_ORIGIN_IP}" "https://${HOST}${PATH_TO_CHECK}" 2>/dev/null || true)"
    else
        last_headers="$(curl -k -sS -D - -o /dev/null --max-time 5 "https://${HOST}${PATH_TO_CHECK}" 2>/dev/null || true)"
    fi
    if printf '%s\n' "${last_headers}" | tr -d '\r' | grep -qi "^X-Deploy-Color: ${EXPECTED_COLOR}$"; then
        log "Confirmed ${HOST}${PATH_TO_CHECK} is served by ${EXPECTED_COLOR}"
        exit 0
    fi
    sleep 1
done

if [[ -z "${DIRECT_ORIGIN_IP:-}" ]]; then
    dynamic_dir="${TRAEFIK_DYNAMIC_DIR:-$(dirname "${DEPLOY_RUNTIME_ROOT}")/current/config/traefik/dynamic}"
    dynamic_file="${TRAEFIK_DYNAMIC_FILE:-${dynamic_dir}/dynamic-${COMPOSE_PROJECT_BASE}.yml}"
    expected_service="${COMPOSE_PROJECT_BASE}-${EXPECTED_COLOR}-http"

    if [[ -f "${dynamic_file}" ]] && grep -q "${expected_service}" "${dynamic_file}"; then
        warn "Could not observe X-Deploy-Color via public HTTPS for ${HOST}${PATH_TO_CHECK}; dynamic Traefik config points to ${expected_service}."
        exit 0
    fi
fi

printf '%s\n' "${last_headers}" >&2
err "Timed out waiting for route to serve color ${EXPECTED_COLOR} for ${HOST}${PATH_TO_CHECK}"
