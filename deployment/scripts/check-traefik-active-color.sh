#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/deploy.config.sh"

EXPECTED_COLOR="${1:-}"
HOST="${2:-${APP_DOMAIN}}"
PATH_TO_CHECK="${3:-/readyz}"

err() { echo "[active-color] ERROR: $*" >&2; exit 1; }
log() { echo "[active-color] $*"; }

[[ -n "${EXPECTED_COLOR}" ]] || err "Expected color is required"
[[ -n "${DIRECT_ORIGIN_IP:-}" ]] || err "DIRECT_ORIGIN_IP is required for direct-origin color checks"

attempts="${TRAEFIK_FILE_RELOAD_MAX_SECONDS:-15}"
last_headers=""
for ((i=1; i<=attempts; i++)); do
    last_headers="$(curl -k -sS -D - -o /dev/null --max-time 3 --resolve "${HOST}:443:${DIRECT_ORIGIN_IP}" "https://${HOST}${PATH_TO_CHECK}" 2>/dev/null || true)"
    if printf '%s\n' "${last_headers}" | tr -d '\r' | grep -qi "^X-Deploy-Color: ${EXPECTED_COLOR}$"; then
        log "Confirmed ${HOST}${PATH_TO_CHECK} is served by ${EXPECTED_COLOR}"
        exit 0
    fi
    sleep 1
done

printf '%s\n' "${last_headers}" >&2
err "Timed out waiting for Traefik to serve color ${EXPECTED_COLOR} for ${HOST}${PATH_TO_CHECK}"
