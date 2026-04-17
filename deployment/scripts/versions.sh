#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
APP_ROOT="$(dirname "${DEPLOY_DIR}")"

log() { echo "[versions] $*"; }

CURRENT_BRANCH="$(git -C "${APP_ROOT}" rev-parse --abbrev-ref HEAD 2>/dev/null || echo "unknown")"
CURRENT_SHA="$(git -C "${APP_ROOT}" rev-parse HEAD 2>/dev/null || echo "unknown")"
CURRENT_SHORT_SHA="$(git -C "${APP_ROOT}" rev-parse --short HEAD 2>/dev/null || echo "unknown")"
LAST_BUILT_VERSION="$(cat "${DEPLOY_DIR}/.last-built-version" 2>/dev/null || echo "none")"
LAST_DEPLOYED_VERSION="$(cat "${DEPLOY_DIR}/.last-deployed-version" 2>/dev/null || echo "none")"
RELEASE_MANIFEST="${DEPLOY_DIR}/.release-manifest.env"
LAST_IMAGE_REF="none"

if [[ -f "${RELEASE_MANIFEST}" ]]; then
    LAST_IMAGE_REF="$(grep -m1 '^DEPLOY_IMAGE_REF=' "${RELEASE_MANIFEST}" | cut -d= -f2- || echo "none")"
    [[ -n "${LAST_IMAGE_REF}" ]] || LAST_IMAGE_REF="none"
fi

log "Current branch: ${CURRENT_BRANCH}"
log "Current git SHA: ${CURRENT_SHA}"
log "Current short SHA / default build version: ${CURRENT_SHORT_SHA}"
log "Last built version: ${LAST_BUILT_VERSION}"
log "Last deployed version: ${LAST_DEPLOYED_VERSION}"
log "Last deployed image ref: ${LAST_IMAGE_REF}"
echo
log "Useful commands:"
log "  Build current commit:         ./build.sh"
log "  Build explicit version:       ./build.sh ${CURRENT_SHORT_SHA}"
log "  Deploy last built/default:    ./deploy.sh"
log "  Deploy explicit version:      ./deploy.sh ${CURRENT_SHORT_SHA}"
log "  Emergency local deploy:       ./deploy-local.sh"
