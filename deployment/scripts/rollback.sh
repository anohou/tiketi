#!/usr/bin/env bash
# ╔══════════════════════════════════════════════════════════════════════════════╗
# ║  rollback.sh — Redeploy the previous known-good release                    ║
# ╚══════════════════════════════════════════════════════════════════════════════╝
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
source "${SCRIPT_DIR}/deploy.config.sh"

COMPOSE_FILE="${DEPLOY_DIR}/config/docker-compose.prod.yml"
ENV_FILE="${DEPLOY_DIR}/.env"
STATE_DIR="${DEPLOY_DIR}/.deploy-state/current"
PERSISTENT_PUBLIC_ROOT="${DEPLOY_DIR}/persistent-public/root"
RUNTIME_PUBLIC_ROOT="${DEPLOY_DIR}/runtime-public"
STORAGE_ROOT="$(cd "${DEPLOY_DIR}/../storage" && pwd)"
DEPLOY_GATE_DIR="${STORAGE_ROOT}/framework/deploy"
READY_MARKER="${DEPLOY_GATE_DIR}/ready"
RESTORED_URIS_FILE="${STATE_DIR}/restored-uris.txt"
DEPLOY_IMAGE_REF="${DEPLOY_IMAGE_REF:-}"
declare -a COMPOSE_PROFILE_ARGS=()

log() { echo "[rollback] $(date '+%H:%M:%S') $*"; }
err() { echo "[rollback] ERROR: $*" >&2; exit 1; }

compose_profiles() {
    local profiles=()
    [[ "${QUEUE_WORKER_ENABLED:-true}" == "true" ]] && profiles+=(--profile with-queue)
    [[ "${SCHEDULER_ENABLED:-true}" == "true" ]] && profiles+=(--profile with-scheduler)
    printf '%s\n' "${profiles[@]}"
}

compose() {
    docker compose \
        "${COMPOSE_PROFILE_ARGS[@]}" \
        -f "${COMPOSE_FILE}" \
        --env-file "${ENV_FILE}" \
        "$@"
}

target_version=""
target_image_ref=""

if [[ -n "${1:-}" ]]; then
    target_version="$1"
    target_image_ref="${DEPLOY_IMAGE_REF:-${APP_IMAGE}:${target_version}}"
elif [[ -f "${DEPLOY_DIR}/.last-known-good.env" ]]; then
    # shellcheck disable=SC1090
    source "${DEPLOY_DIR}/.last-known-good.env"
    target_version="${DEPLOY_VERSION:-}"
    target_image_ref="${DEPLOY_IMAGE_REF:-}"
fi

[[ -n "${target_version}" ]] || target_version="$(cat "${DEPLOY_DIR}/.last-deployed-version" 2>/dev/null || true)"
[[ -n "${target_image_ref}" ]] || [[ -z "${target_version}" ]] || target_image_ref="${APP_IMAGE}:${target_version}"
[[ -n "${target_image_ref}" ]] || err "No rollback target found. Specify version explicitly: ./rollback.sh <version>"

prepare_runtime_public_from_image() {
    mkdir -p "${RUNTIME_PUBLIC_ROOT}" || err "Failed to create runtime public root"
    find "${RUNTIME_PUBLIC_ROOT}" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
    log "Preparing runtime public directory from image ${target_image_ref} ..."
    docker run --rm "${target_image_ref}" sh -lc '
        cd /var/www/html/public
        find . -mindepth 1 -maxdepth 1 -exec tar cpf - {} +
    ' | tar xmf - -C "${RUNTIME_PUBLIC_ROOT}" --no-same-owner --no-same-permissions \
        || err "Failed to extract public assets from image ${target_image_ref}"
}

overlay_persistent_public_into_runtime() {
    mkdir -p "${RUNTIME_PUBLIC_ROOT}" || err "Failed to create runtime public root"
    if find "${PERSISTENT_PUBLIC_ROOT}" -mindepth 1 -print -quit | grep -q .; then
        cp -R "${PERSISTENT_PUBLIC_ROOT}/." "${RUNTIME_PUBLIC_ROOT}/" \
            || err "Failed to overlay persistent public artifacts into runtime public root"
    fi
}

ensure_runtime_public_storage_link() {
    mkdir -p "${RUNTIME_PUBLIC_ROOT}" || err "Failed to create runtime public root"
    rm -rf "${RUNTIME_PUBLIC_ROOT}/storage"
    ln -s "../storage/app/public" "${RUNTIME_PUBLIC_ROOT}/storage" \
        || err "Failed to create runtime public storage symlink"
}

normalize_permissions() {
    local host_uid host_gid
    host_uid="$(id -u)"
    host_gid="$(id -g)"

    docker run --rm -v "${STORAGE_ROOT}:/storage" alpine sh -c \
        "chown -R 1000:${host_gid} /storage && chmod -R 775 /storage" 2>/dev/null || true

    if [[ -d "${PERSISTENT_PUBLIC_ROOT}" ]]; then
        chown -R "${host_uid}:${host_gid}" "${PERSISTENT_PUBLIC_ROOT}" 2>/dev/null || true
        find "${PERSISTENT_PUBLIC_ROOT}" -mindepth 1 -type d -exec chmod 755 {} \; 2>/dev/null || true
        find "${PERSISTENT_PUBLIC_ROOT}" -mindepth 1 -type f -exec chmod 644 {} \; 2>/dev/null || true
    fi

    if [[ -d "${RUNTIME_PUBLIC_ROOT}" ]]; then
        chown -R "${host_uid}:${host_gid}" "${RUNTIME_PUBLIC_ROOT}" 2>/dev/null || true
        find "${RUNTIME_PUBLIC_ROOT}" -mindepth 1 -type d -exec chmod 755 {} \; 2>/dev/null || true
        find "${RUNTIME_PUBLIC_ROOT}" -mindepth 1 -type f -exec chmod 644 {} \; 2>/dev/null || true
    fi
}

record_persistent_public_uris() {
    local rel
    mkdir -p "${STATE_DIR}"
    : > "${RESTORED_URIS_FILE}"
    [[ -d "${PERSISTENT_PUBLIC_ROOT}" ]] || return 0
    while IFS= read -r rel; do
        [[ -n "${rel}" ]] || continue
        printf '/%s\n' "${rel}" >> "${RESTORED_URIS_FILE}"
    done < <(cd "${PERSISTENT_PUBLIC_ROOT}" && find . -type f ! -name '.gitkeep' | sed 's#^\./##' | LC_ALL=C sort)
}

verify_promoted_readiness() {
    [[ -f "${READY_MARKER}" ]] || err "Traffic promotion marker was not written to ${READY_MARKER}"

    log "Verifying promoted readiness through nginx ..."
    DEPLOY_COMPOSE_FILE="${COMPOSE_FILE}" \
    DEPLOY_ENV_FILE="${ENV_FILE}" \
    HEALTH_URL="http://127.0.0.1/readyz" \
    HEALTH_EXPECTED_STATUS="200" \
        bash "${SCRIPT_DIR}/healthcheck.sh"
}

while IFS= read -r profile; do
    [[ -n "${profile}" ]] && COMPOSE_PROFILE_ARGS+=("${profile}")
done < <(compose_profiles)

log "═══════════════════════════════════════════"
log "  Rolling back to ${target_image_ref}"
log "═══════════════════════════════════════════"

rm -f "${READY_MARKER}"
docker image inspect "${target_image_ref}" >/dev/null 2>&1 || docker pull "${target_image_ref}" >/dev/null

bash "${SCRIPT_DIR}/generate-env.sh" \
    "APP_VERSION=${target_version}" \
    "DEPLOY_IMAGE_REFERENCE=${target_image_ref}"

prepare_runtime_public_from_image
overlay_persistent_public_into_runtime
ensure_runtime_public_storage_link
normalize_permissions
record_persistent_public_uris

compose up -d --remove-orphans --force-recreate

DEPLOY_COMPOSE_FILE="${COMPOSE_FILE}" DEPLOY_ENV_FILE="${ENV_FILE}" \
    bash "${SCRIPT_DIR}/rebuild-cache.sh"
bash "${SCRIPT_DIR}/healthcheck.sh"
RESTORED_URIS_FILE="${RESTORED_URIS_FILE}" DEPLOY_COMPOSE_FILE="${COMPOSE_FILE}" DEPLOY_ENV_FILE="${ENV_FILE}" \
    bash "${SCRIPT_DIR}/smoke-check.sh"

mkdir -p "$(dirname "${READY_MARKER}")"
printf '%s\n' "${target_image_ref}" > "${READY_MARKER}"
chmod 664 "${READY_MARKER}" || true
verify_promoted_readiness
RUN_EXTERNAL_SMOKE=true RESTORED_URIS_FILE="${RESTORED_URIS_FILE}" \
    DEPLOY_COMPOSE_FILE="${COMPOSE_FILE}" DEPLOY_ENV_FILE="${ENV_FILE}" \
    bash "${SCRIPT_DIR}/smoke-check.sh"

echo "${target_version}" > "${DEPLOY_DIR}/.last-deployed-version"
if [[ -f "${DEPLOY_DIR}/.last-known-good.env" ]]; then
    cp "${DEPLOY_DIR}/.last-known-good.env" "${DEPLOY_DIR}/.release-manifest.env"
fi

log "✓ Rollback complete — now running ${target_image_ref}"
