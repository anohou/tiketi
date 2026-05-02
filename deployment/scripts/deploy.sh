#!/usr/bin/env bash
# ╔══════════════════════════════════════════════════════════════════════════════╗
# ║  deploy.sh — Steady-state production deploy for runtime-public releases    ║
# ╚══════════════════════════════════════════════════════════════════════════════╝
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
source "${SCRIPT_DIR}/deploy.config.sh"

VERSION="${1:-$(cat "${DEPLOY_DIR}/.last-built-version" 2>/dev/null || echo "latest")}"
IMAGE_SOURCE_MODE="${DEPLOY_BUILD_SOURCE:-${IMAGE_SOURCE_MODE:-}}"
ALLOW_LOCAL_BUILD="${DEPLOY_ALLOW_LOCAL_BUILD:-${ALLOW_LOCAL_BUILD:-${BUILD_ALLOW_LOCAL_BUILD:-false}}}"
DEPLOY_IMAGE_REF="${DEPLOY_IMAGE_REF:-}"
DEPLOY_IMAGE_DIGEST="${DEPLOY_IMAGE_DIGEST:-}"
DEPLOY_REQUESTED_BY="${DEPLOY_REQUESTED_BY:-unknown}"
DEPLOY_RUN_URL="${DEPLOY_RUN_URL:-}"
DEPLOY_ACTUAL_IMAGE_REF=""

COMPOSE_FILE="${DEPLOY_DIR}/config/docker-compose.prod.yml"
ENV_FILE="${DEPLOY_DIR}/.env"
STATE_DIR="${DEPLOY_DIR}/.deploy-state/current"
ROLLBACK_DIR="${DEPLOY_DIR}/.deploy-state/rollback-target"
PUBLIC_SNAPSHOT_ROOT="${DEPLOY_DIR}/.deploy-state/public-snapshots"
LOCK_DIR="${DEPLOY_DIR}/.deploy-lock"
PERSISTENT_PUBLIC_ROOT="${DEPLOY_DIR}/persistent-public/root"
RUNTIME_PUBLIC_ROOT="${DEPLOY_DIR}/runtime-public"
STORAGE_ROOT="$(cd "${DEPLOY_DIR}/../storage" && pwd)"
STORAGE_PUBLIC_DIR="${STORAGE_ROOT}/app/public"
DEPLOY_GATE_DIR="${STORAGE_ROOT}/framework/deploy"
READY_MARKER="${DEPLOY_GATE_DIR}/ready"
SMOKE_PROBE_FILE="${STORAGE_PUBLIC_DIR}/.deploy-smoke.txt"
RESTORED_URIS_FILE="${STATE_DIR}/restored-uris.txt"
ROLLBACK_ATTEMPTED=false
MUTATION_STARTED=false
DEPLOY_SUCCEEDED=false
CHECKSUM_BIN=""
CHECKSUM_ARGS=()

declare -a COMPOSE_PROFILE_ARGS=()

log() { echo "[deploy] $(date '+%H:%M:%S') $*"; }
warn() { echo "[deploy] WARN: $*" >&2; }
err() { echo "[deploy] ERROR: $*" >&2; exit 1; }

compose() {
    docker compose \
        "${COMPOSE_PROFILE_ARGS[@]}" \
        -f "${COMPOSE_FILE}" \
        --env-file "${ENV_FILE}" \
        "$@"
}

resolve_image_source_mode() {
    if [[ -n "${USE_REMOTE_IMAGE:-}" ]]; then
        if [[ "${USE_REMOTE_IMAGE}" == "true" ]]; then
            echo "remote"
        else
            echo "local"
        fi
        return 0
    fi

    if [[ -n "${IMAGE_SOURCE_MODE}" ]]; then
        echo "${IMAGE_SOURCE_MODE}"
        return 0
    fi

    echo "${BUILD_DEFAULT_SOURCE:-remote}"
}

resolve_image_reference() {
    if [[ -n "${DEPLOY_IMAGE_REF}" ]]; then
        echo "${DEPLOY_IMAGE_REF}"
        return 0
    fi

    if [[ -n "${DEPLOY_IMAGE_DIGEST}" ]]; then
        echo "${APP_IMAGE}@${DEPLOY_IMAGE_DIGEST}"
        return 0
    fi

    echo "${APP_IMAGE}:${VERSION}"
}

resolve_pulled_digest_reference() {
    local image_ref="$1"
    local digest_ref=""

    digest_ref="$(
        docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "${image_ref}" 2>/dev/null \
            | grep -E "^${APP_IMAGE}@sha256:" \
            | head -n 1
    )"

    [[ -n "${digest_ref}" ]] || err "Immutable digest reference required, but no RepoDigest was found for ${image_ref}"
    printf '%s\n' "${digest_ref}"
}

detect_host_tools() {
    if command -v sha256sum >/dev/null 2>&1; then
        CHECKSUM_BIN="sha256sum"
        CHECKSUM_ARGS=()
    elif command -v shasum >/dev/null 2>&1; then
        CHECKSUM_BIN="shasum"
        CHECKSUM_ARGS=(-a 256)
    else
        err "sha256sum or shasum is required"
    fi
}

checksum_file() {
    "${CHECKSUM_BIN}" "${CHECKSUM_ARGS[@]}" "$1" | awk '{print $1}'
}

compose_profiles() {
    local profiles=()
    [[ "${QUEUE_WORKER_ENABLED:-true}" == "true" ]] && profiles+=(--profile with-queue)
    [[ "${SCHEDULER_ENABLED:-true}" == "true" ]] && profiles+=(--profile with-scheduler)
    [[ "${REVERB_ENABLED:-false}" == "true" ]] && profiles+=(--profile with-reverb)
    printf '%s\n' "${profiles[@]}"
}

acquire_lock() {
    mkdir -p "${DEPLOY_DIR}"
    if ! mkdir "${LOCK_DIR}" 2>/dev/null; then
        err "Another deploy is already running (lock: ${LOCK_DIR})"
    fi
    printf '%s\n' "$$" > "${LOCK_DIR}/pid"
}

release_lock() {
    rm -rf "${LOCK_DIR}"
}

ensure_runtime_directories() {
    mkdir -p \
        "${STATE_DIR}" \
        "${ROLLBACK_DIR}" \
        "${PUBLIC_SNAPSHOT_ROOT}" \
        "${PERSISTENT_PUBLIC_ROOT}" \
        "${RUNTIME_PUBLIC_ROOT}" \
        "${STORAGE_PUBLIC_DIR}" \
        "${STORAGE_ROOT}/framework/cache/data" \
        "${STORAGE_ROOT}/framework/sessions" \
        "${STORAGE_ROOT}/framework/testing" \
        "${STORAGE_ROOT}/framework/views" \
        "${STORAGE_ROOT}/logs" \
        "${DEPLOY_GATE_DIR}"
    : > "${RESTORED_URIS_FILE}"
}

preflight() {
    command -v docker >/dev/null 2>&1 || err "docker is required"
    docker compose version >/dev/null 2>&1 || err "docker compose is required"
    command -v tar >/dev/null 2>&1 || err "tar is required"
    command -v find >/dev/null 2>&1 || err "find is required"
    command -v awk >/dev/null 2>&1 || err "awk is required"
    command -v sed >/dev/null 2>&1 || err "sed is required"
    command -v df >/dev/null 2>&1 || err "df is required"
    command -v cp >/dev/null 2>&1 || err "cp is required"
    command -v mv >/dev/null 2>&1 || err "mv is required"
    command -v readlink >/dev/null 2>&1 || err "readlink is required"
    [[ -f "${COMPOSE_FILE}" ]] || err "Compose file not found"
    [[ -f "${DEPLOY_DIR}/config/config.yml" ]] || err "Deployment config file not found"
    [[ -f "${DEPLOY_DIR}/config/template.env" ]] || err "Env template not found"
    mkdir -p "${PERSISTENT_PUBLIC_ROOT}" || err "Failed to create persistent public root"
    mkdir -p "${RUNTIME_PUBLIC_ROOT}" || err "Failed to create runtime public root"
    mkdir -p "${PUBLIC_SNAPSHOT_ROOT}" || err "Failed to create public snapshot root"
}

remove_ready_marker() {
    rm -f "${READY_MARKER}"
}

promote_traffic() {
    mkdir -p "${DEPLOY_GATE_DIR}"
    printf '%s\n' "${DEPLOY_ACTUAL_IMAGE_REF}" > "${READY_MARKER}"
    chmod 664 "${READY_MARKER}" || true
    log "Traffic promotion result: ready marker written to ${READY_MARKER}"
}

write_storage_probe() {
    mkdir -p "${STORAGE_PUBLIC_DIR}"
    printf 'deploy=%s\nimage=%s\ntime=%s\n' \
        "${VERSION}" \
        "${DEPLOY_ACTUAL_IMAGE_REF}" \
        "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" > "${SMOKE_PROBE_FILE}"
    chmod 664 "${SMOKE_PROBE_FILE}" 2>/dev/null || true
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

compose_checksum() {
    checksum_file "${COMPOSE_FILE}"
}

write_release_manifest() {
    local manifest="$1"
    local image_ref="$2"
    local version="$3"
    local status="$4"
    {
        echo "APP_NAME=${APP_NAME}"
        echo "APP_SLUG=${APP_SLUG}"
        echo "DEPLOY_TIMESTAMP=$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
        echo "DEPLOY_BUILD_SOURCE=${IMAGE_SOURCE_MODE}"
        echo "DEPLOY_IMAGE_REF=${image_ref}"
        echo "DEPLOY_VERSION=${version}"
        echo "DEPLOY_REQUESTED_BY=${DEPLOY_REQUESTED_BY}"
        echo "DEPLOY_RUN_URL=${DEPLOY_RUN_URL}"
        echo "DEPLOY_HOST=$(hostname)"
        echo "DEPLOY_COMPOSE_SHA256=$(compose_checksum)"
        echo "DEPLOY_STATUS=${status}"
    } > "${manifest}"
    chmod 600 "${manifest}" || true
}

snapshot_rollback_target() {
    mkdir -p "${ROLLBACK_DIR}"
    if [[ -f "${ENV_FILE}" ]]; then
        cp "${ENV_FILE}" "${ROLLBACK_DIR}/previous.env"
    fi

    local previous_manifest="${DEPLOY_DIR}/.last-known-good.env"
    if [[ ! -f "${previous_manifest}" && -f "${DEPLOY_DIR}/.release-manifest.env" ]]; then
        previous_manifest="${DEPLOY_DIR}/.release-manifest.env"
    fi
    if [[ -f "${previous_manifest}" ]]; then
        cp "${previous_manifest}" "${ROLLBACK_DIR}/previous-release.env"
    else
        : > "${ROLLBACK_DIR}/previous-release.env"
    fi

    local previous_version previous_image_ref
    previous_version="$(cat "${DEPLOY_DIR}/.last-deployed-version" 2>/dev/null || true)"
    previous_image_ref="$(grep -m1 '^DEPLOY_IMAGE_REF=' "${ROLLBACK_DIR}/previous-release.env" | cut -d= -f2- || true)"
    [[ -n "${previous_image_ref}" ]] || [[ -z "${previous_version}" ]] || previous_image_ref="${APP_IMAGE}:${previous_version}"
    {
        echo "PREVIOUS_VERSION=${previous_version}"
        echo "PREVIOUS_IMAGE_REF=${previous_image_ref}"
    } > "${ROLLBACK_DIR}/target.env"
}

append_unique_line() {
    local file="$1"
    local line="$2"
    grep -Fx -- "${line}" "${file}" >/dev/null 2>&1 || printf '%s\n' "${line}" >> "${file}"
}

record_persistent_public_uris() {
    local rel
    : > "${RESTORED_URIS_FILE}"
    [[ -d "${PERSISTENT_PUBLIC_ROOT}" ]] || return 0
    while IFS= read -r rel; do
        [[ -n "${rel}" ]] || continue
        append_unique_line "${RESTORED_URIS_FILE}" "/${rel}"
    done < <(cd "${PERSISTENT_PUBLIC_ROOT}" && find . -type f ! -name '.gitkeep' | sed 's#^\./##' | LC_ALL=C sort)
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

    if [[ "${DEPLOY_APPLY_SELINUX_LABELS:-auto}" == "false" ]]; then
        return 0
    fi

    if command -v selinuxenabled >/dev/null 2>&1 && selinuxenabled; then
        command -v chcon >/dev/null 2>&1 || err "SELinux is enabled but chcon is unavailable"
        chcon -Rt svirt_sandbox_file_t "${STORAGE_ROOT}" "${PERSISTENT_PUBLIC_ROOT}" "${RUNTIME_PUBLIC_ROOT}" 2>/dev/null \
            || warn "Failed to apply SELinux labels to restored paths; continuing with existing labels. Set DEPLOY_APPLY_SELINUX_LABELS=false to skip relabeling."
    fi
}

prepare_runtime_public_from_image() {
    local image_ref="$1"
    mkdir -p "${RUNTIME_PUBLIC_ROOT}" || err "Failed to create runtime public root"
    snapshot_runtime_public_before_replace
    find "${RUNTIME_PUBLIC_ROOT}" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
    log "Preparing runtime public directory from image ${image_ref} ..."
    docker run --rm "${image_ref}" sh -lc '
        cd /var/www/html/public
        find . -mindepth 1 -maxdepth 1 -exec tar cpf - {} +
    ' | tar xmf - -C "${RUNTIME_PUBLIC_ROOT}" --no-same-owner --no-same-permissions \
        || err "Failed to extract public assets from image ${image_ref}"
}

snapshot_runtime_public_before_replace() {
    local snapshot_dir snapshot_id

    [[ -d "${RUNTIME_PUBLIC_ROOT}" ]] || return 0
    find "${RUNTIME_PUBLIC_ROOT}" -mindepth 1 -maxdepth 1 -print -quit | grep -q . || return 0

    mkdir -p "${PUBLIC_SNAPSHOT_ROOT}" || err "Failed to create public snapshot root"
    snapshot_id="$(date -u +%Y%m%dT%H%M%SZ)-${VERSION}"
    snapshot_dir="${PUBLIC_SNAPSHOT_ROOT}/${snapshot_id}"
    if [[ -e "${snapshot_dir}" ]]; then
        snapshot_dir="${snapshot_dir}-$$"
    fi

    mkdir -p "${snapshot_dir}" || err "Failed to create public snapshot ${snapshot_dir}"
    (
        cd "${RUNTIME_PUBLIC_ROOT}"
        tar cpf - --exclude='./storage' .
    ) | tar xpf - -C "${snapshot_dir}" --no-same-owner --no-same-permissions \
        || err "Failed to snapshot runtime public directory before replacement"

    log "Runtime public snapshot saved to ${snapshot_dir}"
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

resolve_image() {
    IMAGE_SOURCE_MODE="$(resolve_image_source_mode)"
    case "${IMAGE_SOURCE_MODE}" in
        remote)
            log "Step 2/8 — Pulling image ${DEPLOY_ACTUAL_IMAGE_REF} from registry ..."
            docker pull "${DEPLOY_ACTUAL_IMAGE_REF}" \
                || err "Failed to pull image ${DEPLOY_ACTUAL_IMAGE_REF} from registry."
            if [[ "${REGISTRY_DEPLOY_BY_DIGEST:-false}" == "true" ]]; then
                DEPLOY_ACTUAL_IMAGE_REF="$(resolve_pulled_digest_reference "${DEPLOY_ACTUAL_IMAGE_REF}")"
                log "Resolved immutable remote image reference: ${DEPLOY_ACTUAL_IMAGE_REF}"
            fi
            ;;
        local)
            [[ "${DEPLOY_ALLOW_EMERGENCY_BUILD:-${BUILD_ALLOW_LOCAL_BUILD:-false}}" == "true" ]] \
                || err "Emergency local builds are disabled by config."
            if [[ "${BUILD_LOCAL_BUILD_REQUIRE_EXPLICIT_FLAG:-false}" == "true" && "${ALLOW_LOCAL_BUILD}" != "true" ]]; then
                err "Local image builds require explicit DEPLOY_ALLOW_LOCAL_BUILD=true."
            fi
            [[ "${ALLOW_LOCAL_BUILD}" == "true" ]] \
                || err "Local image builds are disabled. Set DEPLOY_ALLOW_LOCAL_BUILD=true to use emergency local builds."
            log "Building or reusing the local image from the synced source tree ..."
            BUILD_MODE=emergency-local bash "${SCRIPT_DIR}/build.sh" "${VERSION}"
            ;;
        *)
            err "Unsupported image source mode: ${IMAGE_SOURCE_MODE}. Use 'remote' or 'local'."
            ;;
    esac
}

run_migrations() {
    log "Step 4/8 — Running migrations ..."
    source "${SCRIPT_DIR}/rbac.config.sh"
    local runtime_user app_owner_role mig_user mig_pass
    runtime_user="$(grep -m1 '^DB_USERNAME=' "${ENV_FILE}" | cut -d= -f2-)"
    app_owner_role="$(derive_owner_role "${runtime_user}")" || err "Failed to derive owner role"
    mig_user="$(grep -m1 '^DB_MIGRATOR_USERNAME=' "${ENV_FILE}" | cut -d= -f2-)"
    mig_pass="$(grep -m1 '^DB_MIGRATOR_PASSWORD=' "${ENV_FILE}" | cut -d= -f2-)"

    docker run --rm \
        --env-file "${ENV_FILE}" \
        --env "DB_USERNAME=${mig_user}" \
        --env "DB_PASSWORD=${mig_pass}" \
        --env "PGOPTIONS=-c role=${app_owner_role}" \
        --network "${DB_NETWORK}" \
        "${DEPLOY_ACTUAL_IMAGE_REF}" \
        php artisan migrate --force --no-ansi \
        || err "Migrations failed — aborting deploy."
}

automatic_rollback() {
    [[ "${ROLLBACK_ATTEMPTED}" == false ]] || return 1
    ROLLBACK_ATTEMPTED=true
    [[ -f "${ROLLBACK_DIR}/target.env" ]] || {
        warn "Automatic rollback skipped: no rollback target metadata found"
        return 1
    }

    # shellcheck disable=SC1090
    source "${ROLLBACK_DIR}/target.env"
    [[ -n "${PREVIOUS_IMAGE_REF:-}" ]] || {
        warn "Automatic rollback skipped: no previous image reference recorded"
        return 1
    }

    log "Automatic rollback triggered ..."
    if [[ -f "${ROLLBACK_DIR}/previous.env" ]]; then
        cp "${ROLLBACK_DIR}/previous.env" "${ENV_FILE}"
    else
        bash "${SCRIPT_DIR}/generate-env.sh" \
            "APP_VERSION=${PREVIOUS_VERSION}" \
            "DEPLOY_IMAGE_REFERENCE=${PREVIOUS_IMAGE_REF}"
    fi

    prepare_runtime_public_from_image "${PREVIOUS_IMAGE_REF}"
    overlay_persistent_public_into_runtime
    ensure_runtime_public_storage_link
    normalize_permissions
    record_persistent_public_uris

    remove_ready_marker
    compose up -d --remove-orphans --force-recreate || {
        warn "Automatic rollback failed while recreating containers"
        return 1
    }

    DEPLOY_COMPOSE_FILE="${COMPOSE_FILE}" DEPLOY_ENV_FILE="${ENV_FILE}" DEPLOY_COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_BASE}" \
        bash "${SCRIPT_DIR}/rebuild-cache.sh" || return 1
    bash "${SCRIPT_DIR}/healthcheck.sh" || return 1
    RESTORED_URIS_FILE="${RESTORED_URIS_FILE}" DEPLOY_COMPOSE_FILE="${COMPOSE_FILE}" DEPLOY_ENV_FILE="${ENV_FILE}" \
        bash "${SCRIPT_DIR}/smoke-check.sh" || return 1
    promote_traffic
    verify_promoted_readiness
    RUN_EXTERNAL_SMOKE=true RESTORED_URIS_FILE="${RESTORED_URIS_FILE}" \
        DEPLOY_COMPOSE_FILE="${COMPOSE_FILE}" DEPLOY_ENV_FILE="${ENV_FILE}" \
        bash "${SCRIPT_DIR}/smoke-check.sh" || return 1

    write_release_manifest "${DEPLOY_DIR}/.release-manifest.env" "${PREVIOUS_IMAGE_REF}" "${PREVIOUS_VERSION:-unknown}" "rolled-back"
    [[ -n "${PREVIOUS_VERSION:-}" ]] && echo "${PREVIOUS_VERSION}" > "${DEPLOY_DIR}/.last-deployed-version"
    log "Rollback status: success (${PREVIOUS_IMAGE_REF})"
}

on_exit() {
    local status=$?
    if [[ "${DEPLOY_SUCCEEDED}" != true && "${MUTATION_STARTED}" == true ]]; then
        warn "Deployment failed after mutation; attempting automatic rollback ..."
        automatic_rollback || warn "Automatic rollback did not complete cleanly"
    fi
    release_lock || true
    exit "${status}"
}

trap on_exit EXIT

while IFS= read -r profile; do
    [[ -n "${profile}" ]] && COMPOSE_PROFILE_ARGS+=("${profile}")
done < <(compose_profiles)

detect_host_tools
acquire_lock
ensure_runtime_directories
preflight
DEPLOY_ACTUAL_IMAGE_REF="$(resolve_image_reference)"

log "═══════════════════════════════════════════"
log "  Deploying ${APP_NAME:-application} (${APP_SLUG:-app})"
log "═══════════════════════════════════════════"

snapshot_rollback_target

log "Step 0/8 — Ensuring required Docker networks ..."
ensure_docker_networks || err "Required Docker networks are not ready"

log "Step 1/8 — Provisioning database ..."
bash "${SCRIPT_DIR}/provision-db.sh"

resolve_image
log "Step 2.5/8 — Generating .env ..."
bash "${SCRIPT_DIR}/generate-env.sh" "APP_VERSION=${VERSION}" "DEPLOY_IMAGE_REFERENCE=${DEPLOY_ACTUAL_IMAGE_REF}"

log "Step 3/8 — Validating compose configuration ..."
compose config > /dev/null || err "docker compose config validation failed"

remove_ready_marker
MUTATION_STARTED=true
export APP_VERSION="${VERSION}"

run_migrations
prepare_runtime_public_from_image "${DEPLOY_ACTUAL_IMAGE_REF}"
overlay_persistent_public_into_runtime
ensure_runtime_public_storage_link
normalize_permissions
record_persistent_public_uris

log "Step 5/8 — Starting services in validation mode ..."
compose up -d --remove-orphans --force-recreate

log "Step 5.1/8 — Linking storage ..."
ensure_runtime_public_storage_link
write_storage_probe

log "Step 6/8 — Preparing persistent public overlay ..."
overlay_persistent_public_into_runtime
ensure_runtime_public_storage_link
normalize_permissions
record_persistent_public_uris

log "Step 7/8 — Rebuilding caches ..."
DEPLOY_COMPOSE_FILE="${COMPOSE_FILE}" DEPLOY_ENV_FILE="${ENV_FILE}" DEPLOY_COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_BASE}" \
    bash "${SCRIPT_DIR}/rebuild-cache.sh"

log "Step 7.5/8 — Health and smoke validation ..."
bash "${SCRIPT_DIR}/healthcheck.sh"
RESTORED_URIS_FILE="${RESTORED_URIS_FILE}" bash "${SCRIPT_DIR}/smoke-check.sh"

log "Step 8/8 — Promoting validated release ..."
promote_traffic
verify_promoted_readiness
RUN_EXTERNAL_SMOKE=true RESTORED_URIS_FILE="${RESTORED_URIS_FILE}" \
    DEPLOY_COMPOSE_FILE="${COMPOSE_FILE}" DEPLOY_ENV_FILE="${ENV_FILE}" \
    bash "${SCRIPT_DIR}/smoke-check.sh"
echo "${VERSION}" > "${DEPLOY_DIR}/.last-deployed-version"
write_release_manifest "${DEPLOY_DIR}/.release-manifest.env" "${DEPLOY_ACTUAL_IMAGE_REF}" "${VERSION}" "success"
cp "${DEPLOY_DIR}/.release-manifest.env" "${DEPLOY_DIR}/.last-known-good.env"
cp "${COMPOSE_FILE}" "${DEPLOY_DIR}/.last-known-good-compose.yml"

DEPLOY_SUCCEEDED=true
log "Persistent public root: ${PERSISTENT_PUBLIC_ROOT}"
log "Runtime public root: ${RUNTIME_PUBLIC_ROOT}"
log "Health-check results: success"
log "Smoke-check results: success"
log "Final deployment status: success"
log "✓ Deploy complete — ${DEPLOY_ACTUAL_IMAGE_REF}"
