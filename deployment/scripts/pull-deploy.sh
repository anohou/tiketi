#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

source "${SCRIPT_DIR}/deploy.config.sh"

log() { printf '[pull-deploy] %s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }
err() { printf '[pull-deploy] ERROR: %s\n' "$*" >&2; exit 1; }

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || err "Required command not found: $1"
}

require_cmd curl
require_cmd jq
require_cmd grep
require_cmd cut
require_cmd tr

DEPLOY_STATE_REPO="${DEPLOY_STATE_REPO:-}"
DEPLOY_STATE_PATH="${DEPLOY_STATE_PATH:-}"
DEPLOY_STATE_REF="${DEPLOY_STATE_REF:-main}"
DEPLOY_STATE_TOKEN_FILE="${DEPLOY_STATE_TOKEN_FILE:-}"

[[ -n "${DEPLOY_STATE_REPO}" ]] || err "DEPLOY_STATE_REPO is required"
[[ -n "${DEPLOY_STATE_PATH}" ]] || err "DEPLOY_STATE_PATH is required"
[[ -n "${DEPLOY_STATE_TOKEN_FILE}" ]] || err "DEPLOY_STATE_TOKEN_FILE is required"
[[ -f "${DEPLOY_STATE_TOKEN_FILE}" ]] || err "Deployment-state token file not found: ${DEPLOY_STATE_TOKEN_FILE}"

deployment_state_token="$(tr -d '\r\n' < "${DEPLOY_STATE_TOKEN_FILE}")"
[[ -n "${deployment_state_token}" ]] || err "Deployment-state token file is empty: ${DEPLOY_STATE_TOKEN_FILE}"

state_url="https://api.github.com/repos/${DEPLOY_STATE_REPO}/contents/${DEPLOY_STATE_PATH}?ref=${DEPLOY_STATE_REF}"
state_payload="$(
    curl -fsSL \
        -H "Authorization: Bearer ${deployment_state_token}" \
        -H "Accept: application/vnd.github.raw" \
        "${state_url}"
)"

desired_image_ref="$(jq -r '.image_ref // .image // ""' <<<"${state_payload}")"
[[ -n "${desired_image_ref}" && "${desired_image_ref}" != "null" ]] || err "deployment-state payload is missing image_ref/image"

current_manifest="${DEPLOY_DIR}/.release-manifest.env"
current_image_ref=""
if [[ -f "${current_manifest}" ]]; then
    current_image_ref="$(grep -m1 '^DEPLOY_IMAGE_REF=' "${current_manifest}" | cut -d= -f2- || true)"
fi

if [[ -n "${current_image_ref}" && "${current_image_ref}" == "${desired_image_ref}" ]]; then
    log "No change detected: desired image already deployed"
    exit 0
fi

log "Deployment-state requested image: ${desired_image_ref}"
if [[ -n "${current_image_ref}" ]]; then
    log "Current deployed image: ${current_image_ref}"
else
    log "Current deployed image: (unknown)"
fi

exec env \
    DEPLOY_BUILD_SOURCE=remote \
    DEPLOY_IMAGE_REF="${desired_image_ref}" \
    DEPLOY_REQUESTED_BY=pull-deploy \
    "${SCRIPT_DIR}/deploy.sh" latest
