#!/usr/bin/env bash
set -euo pipefail

LOG_FILE="${1:-}"
ALLOW_UNSAFE_MIGRATIONS="${ALLOW_UNSAFE_MIGRATIONS:-false}"

err() { echo "[migration-safety] ERROR: $*" >&2; exit 1; }
log() { echo "[migration-safety] $*"; }

[[ -n "${LOG_FILE}" ]] || err "Usage: $0 <migrate-pretend.log>"
[[ -f "${LOG_FILE}" ]] || err "Migration pretend log not found: ${LOG_FILE}"

unsafe_pattern='DROP[[:space:]]+COLUMN|DROP[[:space:]]+TABLE|CHANGE[[:space:]]+|MODIFY[[:space:]]+|RENAME[[:space:]]+COLUMN|RENAME[[:space:]]+TABLE|ALTER[[:space:]]+TABLE.*SET[[:space:]]+NOT[[:space:]]+NULL|ALTER[[:space:]]+TABLE.*ALTER[[:space:]]+COLUMN.*NOT[[:space:]]+NULL|ALTER[[:space:]]+TABLE.*ALTER[[:space:]]+.*SET[[:space:]]+NOT[[:space:]]+NULL'

if grep -Eiq "${unsafe_pattern}" "${LOG_FILE}"; then
    if [[ "${ALLOW_UNSAFE_MIGRATIONS}" == "true" ]]; then
        log "WARNING: Unsafe migration override enabled. Zero-downtime compatibility is not guaranteed."
        grep -Ein "${unsafe_pattern}" "${LOG_FILE}" || true
        exit 0
    fi

    grep -Ein "${unsafe_pattern}" "${LOG_FILE}" || true
    err "Unsafe migration detected for zero-downtime deploy. This migration may break the currently running color. Run during a maintenance window with ALLOW_UNSAFE_MIGRATIONS=true, or split it into expand/contract steps. See ${LOG_FILE}"
fi

log "Migration pretend output passed unsafe DDL scan."
