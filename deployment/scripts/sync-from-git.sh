#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
APP_ROOT="$(dirname "${DEPLOY_DIR}")"

source "${SCRIPT_DIR}/deploy.config.sh"

APP_NAME="${APP_SLUG:-$(basename "${APP_ROOT}")}"
RUNTIME_ROOT="${DEPLOY_RUNTIME_ROOT:-/srv/apps/anohou-apps/production}"
CI_SOURCE_ROOT="${DEPLOY_CI_SOURCE_ROOT:-/srv/apps/anohou-apps/ci-sources}"
LIVE_ROOT="${RUNTIME_ROOT}/${APP_NAME}"
SOURCE_ROOT="${CI_SOURCE_ROOT}/${APP_NAME}"
REQUESTED_REF="${1:-}"

log() { echo "[sync-from-git] $*"; }
err() { echo "[sync-from-git] ERROR: $*" >&2; exit 1; }

usage() {
    cat <<EOF
Usage: ./sync-from-git.sh [git-ref]

Fetch the latest source into the CI checkout and rsync it into the live runtime
directory without turning the live directory into a git repository.

Works when launched from either:
  ${LIVE_ROOT}/deployment/scripts
  ${SOURCE_ROOT}/deployment/scripts
EOF
}

resolve_paths() {
    local current_parent

    current_parent="$(basename "$(dirname "${APP_ROOT}")")"

    case "${current_parent}" in
        production)
            [[ "${APP_ROOT}" == "${LIVE_ROOT}" ]] || log "Current runtime root differs from configured runtime root; using configured paths."
            ;;
        ci-sources)
            [[ "${APP_ROOT}" == "${SOURCE_ROOT}" ]] || log "Current CI source root differs from configured CI source root; using configured paths."
            ;;
        *)
            log "Current location is outside the configured production/ci-sources roots; using configured paths."
            ;;
    esac
}

ensure_source_checkout() {
    mkdir -p "${CI_SOURCE_ROOT}"

    if [[ -d "${SOURCE_ROOT}/.git" ]]; then
        return 0
    fi

    [[ -n "${REPOSITORY_GIT_URL:-}" ]] || err "REPOSITORY_GIT_URL is not configured."

    log "Creating CI source checkout at ${SOURCE_ROOT}"
    rm -rf "${SOURCE_ROOT}"
    git clone "${REPOSITORY_GIT_URL}" "${SOURCE_ROOT}" >/dev/null
}

resolve_target_ref() {
    if [[ -n "${REQUESTED_REF}" ]]; then
        printf '%s\n' "${REQUESTED_REF}"
        return 0
    fi

    if [[ -d "${SOURCE_ROOT}/.git" ]]; then
        local current_branch
        current_branch="$(git -C "${SOURCE_ROOT}" symbolic-ref --quiet --short HEAD 2>/dev/null || true)"
        if [[ -n "${current_branch}" ]]; then
            printf '%s\n' "${current_branch}"
            return 0
        fi
    fi

    printf '%s\n' "${REPOSITORY_DEFAULT_REF:-main}"
}

update_source_checkout() {
    local target_ref="$1"

    log "Fetching latest git updates in ${SOURCE_ROOT}"
    git -C "${SOURCE_ROOT}" fetch origin --prune

    if git -C "${SOURCE_ROOT}" show-ref --verify --quiet "refs/remotes/origin/${target_ref}"; then
        git -C "${SOURCE_ROOT}" checkout --force "${target_ref}" >/dev/null 2>&1 || \
            git -C "${SOURCE_ROOT}" checkout --force -B "${target_ref}" "origin/${target_ref}" >/dev/null
        git -C "${SOURCE_ROOT}" reset --hard "origin/${target_ref}" >/dev/null
    else
        git -C "${SOURCE_ROOT}" fetch origin "${target_ref}" >/dev/null 2>&1 || \
            err "Unable to fetch git ref '${target_ref}' from origin."
        git -C "${SOURCE_ROOT}" checkout --force FETCH_HEAD >/dev/null
        git -C "${SOURCE_ROOT}" reset --hard FETCH_HEAD >/dev/null
    fi

    log "Source checkout now at $(git -C "${SOURCE_ROOT}" rev-parse --short HEAD)"
}

sync_into_live_root() {
    mkdir -p "${LIVE_ROOT}"

    log "Syncing source into runtime directory ${LIVE_ROOT}"
    rsync -rl --delete --omit-dir-times --no-perms \
        --exclude='.git/' \
        --exclude='.github/' \
        --exclude='node_modules/' \
        --exclude='vendor/' \
        --exclude='.env' \
        --exclude='.env.local' \
        --exclude='.env.staging' \
        --exclude='.env.production' \
        --exclude='.env.testing' \
        --exclude='.env.example' \
        --exclude='.env.secrets.*' \
        --exclude='storage/framework/cache/' \
        --exclude='storage/framework/cache/**' \
        --exclude='storage/framework/deploy/' \
        --exclude='storage/framework/deploy/**' \
        --exclude='storage/framework/sessions/' \
        --exclude='storage/framework/sessions/**' \
        --exclude='storage/framework/views/' \
        --exclude='storage/framework/views/**' \
        --exclude='storage/logs/**' \
        --exclude='storage/debugbar/**' \
        --exclude='bootstrap/cache/**' \
        --exclude='bootstrap/ssr/**' \
        --exclude='public/hot' \
        --exclude='public/storage' \
        --exclude='public/build' \
        --exclude='.phpunit.result.cache' \
        --exclude='.idea/' \
        --exclude='.vscode/' \
        --exclude='.claude/' \
        --exclude='.DS_Store' \
        --exclude='*.log' \
        --exclude='*.log.*' \
        --exclude='**/logs/' \
        --exclude='__pycache__/' \
        --exclude='*.pyc' \
        --exclude='dist/' \
        --exclude='build/' \
        --exclude='coverage/' \
        --exclude='yarn.lock' \
        --exclude='storage/app/private/**' \
        --exclude='storage/app/public/**' \
        --exclude='storage/app/purifier/**' \
        --exclude='deployment/.env' \
        --exclude='deployment/.last-built-version' \
        --exclude='deployment/.last-deployed-version' \
        --exclude='deployment/.release-manifest.env' \
        "${SOURCE_ROOT}/" "${LIVE_ROOT}/"
}

if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
    usage
    exit 0
fi

resolve_paths
ensure_source_checkout
TARGET_REF="$(resolve_target_ref)"
update_source_checkout "${TARGET_REF}"
sync_into_live_root

log "Live runtime directory updated from git."
log "CI source: ${SOURCE_ROOT}"
log "Runtime path: ${LIVE_ROOT}"
log "Next step, if desired: ${LIVE_ROOT}/deployment/scripts/deploy-local.sh"
