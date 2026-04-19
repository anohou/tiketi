#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
STATE_FILE="${DEPLOY_DIR}/.deploy/current-state"
MIGRATION_MARKER="${DEPLOY_DIR}/.deploy/migration-complete"

err() { echo "[resume-zero-downtime] ERROR: $*" >&2; exit 1; }

[[ -f "${STATE_FILE}" ]] || err "No zero-downtime state file found"
[[ -f "${MIGRATION_MARKER}" ]] || err "No migration-complete marker found; refusing to resume without migration proof"

state="$(grep -m1 '^state=' "${STATE_FILE}" | cut -d= -f2- || true)"
case "${state}" in
    MIGRATING|FAILED|STARTING_WEB|WARMING|VALIDATING)
        ;;
    *)
        err "Refusing to resume from state '${state}'. Resume is intended for post-migration failures."
        ;;
esac

exec env \
    DEPLOY_BUILD_SOURCE=local \
    DEPLOY_ALLOW_LOCAL_BUILD=true \
    RESUME_AFTER_MIGRATION=true \
    "${SCRIPT_DIR}/zero-downtime-deploy.sh" "$@"
