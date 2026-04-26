#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

CONFIG_FILE="${DEPLOY_STATE_CONFIG_FILE:-/srv/apps/anohou/prod/config/tiketi-deploy-state.env}"
STATE_DIR="${DEPLOY_DIR}/.deploy-state/pull"
DESIRED_JSON="${STATE_DIR}/desired.json"
CURRENT_IMAGE_FILE="${STATE_DIR}/current-image"
TMP_JSON="${STATE_DIR}/desired.json.tmp.$$"

log() { echo "[pull-deploy] $(date '+%Y-%m-%d %H:%M:%S') $*"; }
err() { echo "[pull-deploy] ERROR: $*" >&2; exit 1; }

if [[ -f "${CONFIG_FILE}" ]]; then
    # shellcheck disable=SC1090
    source "${CONFIG_FILE}"
fi

APP_EXPECTED="${DEPLOY_STATE_APP:-tiketi}"
ENV_EXPECTED="${DEPLOY_STATE_ENVIRONMENT:-production}"
IMAGE_PREFIX="${DEPLOY_STATE_IMAGE_PREFIX:-ghcr.io/anohou/tiketi@sha256:}"
DEPLOY_COMMAND="${DEPLOY_COMMAND:-${SCRIPT_DIR}/zero-downtime-deploy.sh}"

require_tool() {
    command -v "$1" >/dev/null 2>&1 || err "$1 is required"
}

run_deploy_command() {
    local desired_image="$1"

    if [[ -f "${DEPLOY_COMMAND}" ]]; then
        DEPLOY_BUILD_SOURCE=remote \
        DEPLOY_IMAGE_REF="${desired_image}" \
        DEPLOY_REQUESTED_BY="${DEPLOY_REQUESTED_BY:-systemd-timer}" \
            /usr/bin/bash "${DEPLOY_COMMAND}"
        return
    fi

    DEPLOY_BUILD_SOURCE=remote \
    DEPLOY_IMAGE_REF="${desired_image}" \
    DEPLOY_REQUESTED_BY="${DEPLOY_REQUESTED_BY:-systemd-timer}" \
        "${DEPLOY_COMMAND}"
}

read_token() {
    if [[ -n "${DEPLOY_STATE_GITHUB_TOKEN:-}" ]]; then
        printf '%s' "${DEPLOY_STATE_GITHUB_TOKEN}"
        return 0
    fi

    if [[ -n "${DEPLOY_STATE_TOKEN_FILE:-}" && -f "${DEPLOY_STATE_TOKEN_FILE}" ]]; then
        tr -d '\r\n' < "${DEPLOY_STATE_TOKEN_FILE}"
        return 0
    fi

    printf ''
}

fetch_deployment_state() {
    local token url
    token="$(read_token)"

    mkdir -p "${STATE_DIR}"
    rm -f "${TMP_JSON}"

    if [[ -n "${DEPLOY_STATE_URL:-}" ]]; then
        url="${DEPLOY_STATE_URL}"
    else
        [[ -n "${DEPLOY_STATE_REPO:-}" ]] || err "DEPLOY_STATE_REPO is required when DEPLOY_STATE_URL is not set"
        [[ -n "${DEPLOY_STATE_PATH:-}" ]] || err "DEPLOY_STATE_PATH is required when DEPLOY_STATE_URL is not set"
        url="${DEPLOY_STATE_API_URL:-https://api.github.com}/repos/${DEPLOY_STATE_REPO}/contents/${DEPLOY_STATE_PATH}?ref=${DEPLOY_STATE_REF:-main}"
    fi

    log "Fetching deployment-state from ${DEPLOY_STATE_REPO:-${DEPLOY_STATE_URL:-unknown}}/${DEPLOY_STATE_PATH:-}"

    if [[ -n "${token}" ]]; then
        curl -fsSL \
            -H "Authorization: Bearer ${token}" \
            -H "Accept: application/vnd.github.raw+json" \
            "${url}" \
            -o "${TMP_JSON}"
    else
        curl -fsSL \
            -H "Accept: application/vnd.github.raw+json" \
            "${url}" \
            -o "${TMP_JSON}"
    fi

    jq -e . "${TMP_JSON}" >/dev/null || err "Deployment-state is not valid JSON"
    mv "${TMP_JSON}" "${DESIRED_JSON}"
}

validate_state() {
    local app environment image
    app="$(jq -er '.app // empty' "${DESIRED_JSON}")" || err "Deployment-state missing app"
    environment="$(jq -er '.environment // empty' "${DESIRED_JSON}")" || err "Deployment-state missing environment"
    image="$(jq -er '.image // empty' "${DESIRED_JSON}")" || err "Deployment-state missing image"

    [[ "${app}" == "${APP_EXPECTED}" ]] || err "Deployment-state app '${app}' does not match '${APP_EXPECTED}'"
    [[ "${environment}" == "${ENV_EXPECTED}" ]] || err "Deployment-state environment '${environment}' does not match '${ENV_EXPECTED}'"
    [[ "${image}" == "${IMAGE_PREFIX}"* ]] || err "Deployment image must start with immutable digest prefix '${IMAGE_PREFIX}'"
    [[ "${image}" != *":latest"* ]] || err "Deployment image must not use latest"
    [[ "${image}" =~ ^ghcr\.io/anohou/tiketi@sha256:[0-9a-f]{64}$ ]] || err "Deployment image must be ghcr.io/anohou/tiketi@sha256:<64 hex chars>"

    printf '%s\n' "${image}"
}

current_image() {
    if [[ -f "${CURRENT_IMAGE_FILE}" ]]; then
        sed -n '1p' "${CURRENT_IMAGE_FILE}"
        return 0
    fi

    grep -m1 '^image=' "${DEPLOY_DIR}/.deploy/last-successful-deploy" 2>/dev/null | cut -d= -f2- || true
}

mark_current_image() {
    local image="$1"
    mkdir -p "${STATE_DIR}"
    printf '%s\n' "${image}" > "${CURRENT_IMAGE_FILE}.tmp.$$"
    mv "${CURRENT_IMAGE_FILE}.tmp.$$" "${CURRENT_IMAGE_FILE}"
}

main() {
    require_tool curl
    require_tool jq

    [[ -x "${DEPLOY_COMMAND}" ]] || err "Deploy command is not executable: ${DEPLOY_COMMAND}"

    fetch_deployment_state

    local desired current
    desired="$(validate_state)"
    current="$(current_image)"

    if [[ "${desired}" == "${current}" ]]; then
        log "No change detected: desired image already deployed (${desired})"
        exit 0
    fi

    log "Image change detected"
    log "Current: ${current:-none}"
    log "Desired: ${desired}"
    log "Starting zero-downtime deploy"

    run_deploy_command "${desired}"

    mark_current_image "${desired}"
    log "Deployment succeeded; current image updated to ${desired}"
}

trap 'rm -f "${TMP_JSON}" "${CURRENT_IMAGE_FILE}.tmp.$$" 2>/dev/null || true' EXIT
main "$@"
