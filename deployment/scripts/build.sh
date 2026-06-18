#!/usr/bin/env bash
# ╔══════════════════════════════════════════════════════════════════════════════╗
# ║  build.sh — Build and tag the Docker image                                 ║
# ║  Usage: ./build.sh [version-tag]                                           ║
# ║  Example: ./build.sh 1.2.0                                                 ║
# ╚══════════════════════════════════════════════════════════════════════════════╝
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
APP_ROOT="$(dirname "${DEPLOY_DIR}")"

source "${SCRIPT_DIR}/deploy.config.sh"
source "${DEPLOY_DIR}/lib/build-fingerprint.sh"

CHECKSUM_BIN=""
CHECKSUM_ARGS=()
TIMINGS_LOG_FILE="${DEPLOY_DIR}/.build-timings.log"
BUILD_STATE_FILE="${DEPLOY_DIR}/.last-build-state.env"
BUILD_TIMER_START=0
BUILD_STEP_START=0
BUILD_TOTAL_DURATION=0
declare -a BUILD_TIMING_LINES=()
declare -a VITE_BUILD_ARGS=()
declare -a BUILD_ARGS=()
declare -a DOCKER_BUILD_ARGS=()
DOCKER_BUILD_TARGET=""
EFFECTIVE_RUNTIME_MODE=""
EFFECTIVE_RUNTIME_IMAGE=""
BUILD_INPUT_FINGERPRINT=""
EFFECTIVE_CACHE_MODE="${BUILD_CACHE_MODE:-disabled}"
DEPLOY_WARM_BUILD_CACHE="${DEPLOY_WARM_BUILD_CACHE:-false}"
DEPLOY_EXPORT_BUILD_CACHE="${DEPLOY_EXPORT_BUILD_CACHE:-}"
RESOLVED_CACHE_FROM_ARG=""

detect_checksum_tool() {
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
    if ((${#CHECKSUM_ARGS[@]} > 0)); then
        "${CHECKSUM_BIN}" "${CHECKSUM_ARGS[@]}" "$1" | awk '{print $1}'
    else
        "${CHECKSUM_BIN}" "$1" | awk '{print $1}'
    fi
}

checksum_stream() {
    if ((${#CHECKSUM_ARGS[@]} > 0)); then
        "${CHECKSUM_BIN}" "${CHECKSUM_ARGS[@]}" | awk '{print $1}'
    else
        "${CHECKSUM_BIN}" | awk '{print $1}'
    fi
}

now_epoch() {
    date +%s
}

format_duration() {
    local total="$1"
    local minutes seconds

    minutes=$((total / 60))
    seconds=$((total % 60))

    printf '%02dm%02ds' "${minutes}" "${seconds}"
}

start_build_timer() {
    BUILD_TIMER_START="$(now_epoch)"
}

start_step_timer() {
    BUILD_STEP_START="$(now_epoch)"
}

finish_step_timer() {
    local step_name="$1"
    local elapsed

    elapsed=$(( $(now_epoch) - BUILD_STEP_START ))
    BUILD_TIMING_LINES+=("${step_name}|${elapsed}")
}

finish_build_timer() {
    BUILD_TOTAL_DURATION=$(( $(now_epoch) - BUILD_TIMER_START ))
}

print_timing_summary() {
    local line step_name elapsed

    log "Build timing summary:"
    for line in "${BUILD_TIMING_LINES[@]}"; do
        step_name="${line%%|*}"
        elapsed="${line#*|}"
        log "  ${step_name}: $(format_duration "${elapsed}")"
    done
    log "  Total: $(format_duration "${BUILD_TOTAL_DURATION}")"
}

write_timing_log() {
    local line step_name elapsed

    mkdir -p "${DEPLOY_DIR}"
    {
        printf 'timestamp=%s version=%s build_mode=%s git_sha=%s source_fingerprint=%s total_seconds=%s\n' \
            "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" \
            "${VERSION}" \
            "${BUILD_MODE}" \
            "${GIT_SHA:-none}" \
            "${SOURCE_FINGERPRINT:0:12}" \
            "${BUILD_TOTAL_DURATION}"

        for line in "${BUILD_TIMING_LINES[@]}"; do
            step_name="${line%%|*}"
            elapsed="${line#*|}"
            printf '  step=%s seconds=%s\n' "${step_name}" "${elapsed}"
        done
    } >> "${TIMINGS_LOG_FILE}"
}

should_exclude_from_fingerprint() {
    local rel="$1"

    case "${rel}" in
        .git|.git/*|.github|.github/*|.idea|.idea/*|.vscode|.vscode/*|.claude|.claude/*)
            return 0
            ;;
        vendor|vendor/*|node_modules|node_modules/*)
            return 0
            ;;
        storage/framework/cache|storage/framework/cache/*|storage/framework/sessions|storage/framework/sessions/*|storage/framework/views|storage/framework/views/*)
            return 0
            ;;
        storage/logs|storage/logs/*|storage/debugbar|storage/debugbar/*)
            return 0
            ;;
        storage/app/private|storage/app/private/*|storage/app/public|storage/app/public/*|storage/app/purifier|storage/app/purifier/*)
            return 0
            ;;
        bootstrap/cache|bootstrap/cache/*|bootstrap/ssr|bootstrap/ssr/*)
            return 0
            ;;
        public/build|public/build/*|public/storage|public/storage/*|public/hot)
            return 0
            ;;
        deployment/.deploy|deployment/.deploy/*|deployment/.deploy-lock|deployment/.deploy-lock/*|deployment/.deploy-state|deployment/.deploy-state/*)
            return 0
            ;;
        deployment/runtime-public|deployment/runtime-public/*|deployment/runtime-public-blue|deployment/runtime-public-blue/*|deployment/runtime-public-green|deployment/runtime-public-green/*|deployment/runtime-public-*|deployment/runtime-public-*/*)
            return 0
            ;;
        deployment/persistent-public|deployment/persistent-public/*|deployment.working|deployment.working/*)
            return 0
            ;;
    coverage|coverage/*|dist|dist/*|build|build/*)
            return 0
            ;;
        .DS_Store|*.log|*.log.*)
            return 0
            ;;
        deployment/.env|deployment/.env.*|deployment/.last-*|deployment/.release-manifest.env)
            return 0
            ;;
        deployment/.last-known-good.env|deployment/.last-known-good-compose.yml|deployment/.build-timings.log)
            return 0
            ;;
    esac

    return 1
}

compute_source_fingerprint() {
    start_step_timer
    build_fingerprint_compute_source_tree_fingerprint "${APP_ROOT}"
    finish_step_timer "compute_source_fingerprint"
}

compute_build_input_fingerprint() {
    build_fingerprint_compute_build_input_fingerprint "${APP_ROOT}"
}

collect_vite_build_args_from_file() {
    local file="$1"
    local key value

    [[ -f "${file}" ]] || return 0
    while IFS='=' read -r key value || [[ -n "${key}" ]]; do
        [[ "${key}" == VITE_* ]] || continue
        [[ -n "${value}" ]] || continue
        value="${value%$'\r'}"
        value="${value%% #*}"
        value="${value#"${value%%[![:space:]]*}"}"
        value="${value%"${value##*[![:space:]]}"}"
        value="${value%\"}"; value="${value#\"}"; value="${value%\'}"; value="${value#\'}"
        if [[ "${key}" == "VITE_REVERB_APP_KEY" ]]; then
            key="PUBLIC_REVERB_APP_KEY"
        fi
        VITE_BUILD_ARGS+=(--build-arg "${key}=${value}")
    done < "${file}"
}

collect_vite_build_args() {
    collect_vite_build_args_from_file "${COMMON_ENV_PATH:-}"
    collect_vite_build_args_from_file "${PROJECT_ENV_PATH:-}"
}

collect_asset_build_arg() {
    local asset_build_enabled="${ASSET_BUILD_ENABLED:-true}"
    BUILD_ARGS+=(--build-arg "ASSET_BUILD_ENABLED=${asset_build_enabled}")
}

collect_runtime_build_arg() {
    BUILD_ARGS+=(--build-arg "LARAVEL_RUNTIME_IMAGE=${EFFECTIVE_RUNTIME_IMAGE}")
}

# ── Version tag ────────────────────────────────────────────────────────────────
start_build_timer
detect_checksum_tool
collect_vite_build_args
collect_asset_build_arg
GIT_SHA="$(git -C "${APP_ROOT}" rev-parse --short HEAD 2>/dev/null || true)"
SOURCE_FINGERPRINT="$(compute_source_fingerprint)"
BUILD_INPUT_FINGERPRINT="$(compute_build_input_fingerprint)"
DEFAULT_VERSION="${GIT_SHA:-${SOURCE_FINGERPRINT:0:12}}"
VERSION="${1:-${DEFAULT_VERSION}}"
TIMESTAMP=$(date -u '+%Y%m%dT%H%M%SZ')
BUILD_MODE="${BUILD_MODE:-local}"
ALLOW_DIRTY_LOCAL_BUILD="${ALLOW_DIRTY_LOCAL_BUILD:-false}"
BUILT_IMAGE=false

log()  { echo "[build] $*"; }
err()  { echo "[build] ERROR: $*" >&2; exit 1; }

if git -C "${APP_ROOT}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    if [[ "${BUILD_REQUIRE_CLEAN_GIT_FOR_LOCAL_BUILD:-false}" == "true" && "${ALLOW_DIRTY_LOCAL_BUILD}" != "true" ]]; then
        if ! git -C "${APP_ROOT}" diff --quiet || ! git -C "${APP_ROOT}" diff --cached --quiet; then
            err "Refusing local build with a dirty git worktree. Commit/stash changes or set ALLOW_DIRTY_LOCAL_BUILD=true."
        fi
    fi
else
    log "No git metadata found at ${APP_ROOT}; skipping clean-worktree enforcement."
fi

existing_image_matches_source() {
    local image_ref="$1"
    local existing_sha existing_fingerprint existing_input_fingerprint existing_mode existing_runtime_mode existing_runtime_image

    existing_sha="$(docker image inspect "${image_ref}" --format '{{ index .Config.Labels "build.git-sha" }}' 2>/dev/null || true)"
    existing_fingerprint="$(docker image inspect "${image_ref}" --format '{{ index .Config.Labels "build.source-fingerprint" }}' 2>/dev/null || true)"
    existing_input_fingerprint="$(docker image inspect "${image_ref}" --format '{{ index .Config.Labels "build.input-fingerprint" }}' 2>/dev/null || true)"
    existing_mode="$(docker image inspect "${image_ref}" --format '{{ index .Config.Labels "build.mode" }}' 2>/dev/null || true)"
    existing_runtime_mode="$(docker image inspect "${image_ref}" --format '{{ index .Config.Labels "build.runtime-mode" }}' 2>/dev/null || true)"
    existing_runtime_image="$(docker image inspect "${image_ref}" --format '{{ index .Config.Labels "build.runtime-image" }}' 2>/dev/null || true)"

    [[ "${existing_mode}" == "${BUILD_MODE}" ]] || return 1
    [[ "${existing_runtime_mode}" == "${EFFECTIVE_RUNTIME_MODE}" ]] || return 1
    [[ "${existing_runtime_image}" == "${EFFECTIVE_RUNTIME_IMAGE}" ]] || return 1

    if [[ -n "${existing_input_fingerprint}" ]]; then
        [[ "${existing_input_fingerprint}" == "${BUILD_INPUT_FINGERPRINT}" ]] || return 1
        return 0
    fi

    if [[ -n "${existing_fingerprint}" ]]; then
        [[ "${existing_fingerprint}" == "${SOURCE_FINGERPRINT}" ]] || return 1
        return 0
    fi

    [[ -n "${GIT_SHA}" ]] || return 1
    [[ -n "${existing_sha}" ]] || return 1
    [[ "${existing_sha}" == "${GIT_SHA}" ]] || return 1
}

bool_is_true() {
    case "${1:-}" in
        true|True|TRUE|1|yes|Yes|YES|on|On|ON)
            return 0
            ;;
        *)
            return 1
            ;;
    esac
}

build_cache_ref_inspect_status() {
    local tmp_output

    tmp_output="$(mktemp)"
    if docker buildx imagetools inspect "${BUILD_CACHE_REF}" >"${tmp_output}" 2>&1; then
        rm -f "${tmp_output}"
        return 0
    fi
    cat "${tmp_output}"
    rm -f "${tmp_output}"
    return 1
}

ensure_registry_cache_prereqs() {
    docker buildx version >/dev/null 2>&1 || err "Docker Buildx is required when build.cache_mode=registry. Use DEPLOY_BYPASS_BUILD_CACHE=true if allowed."
    [[ -n "${BUILD_CACHE_REF}" ]] || err "build.cache_ref is required when build.cache_mode=registry."
}

resolve_cache_from_arg() {
    local inspect_output=""
    local inspect_status=0

    set +e
    inspect_output="$(build_cache_ref_inspect_status)"
    inspect_status=$?
    set -e

    if [[ "${inspect_status}" -eq 0 ]]; then
        RESOLVED_CACHE_FROM_ARG="type=registry,ref=${BUILD_CACHE_REF}"
        return 0
    fi

    if [[ "${inspect_output}" == *"unauthorized"* || "${inspect_output}" == *"denied"* || "${inspect_output}" == *"insufficient_scope"* || "${inspect_output}" == *"requested access to the resource is denied"* ]]; then
        err "Unable to authenticate to the build cache registry ref ${BUILD_CACHE_REF}. Use DEPLOY_BYPASS_BUILD_CACHE=true if allowed."
    fi

    if [[ "${inspect_output}" != *"not found"* && "${inspect_output}" != *"no such manifest"* && "${inspect_output}" != *"name unknown"* ]]; then
        err "Unable to verify build cache registry ref ${BUILD_CACHE_REF}. Use DEPLOY_BYPASS_BUILD_CACHE=true if allowed."
    fi

    log "Cache ref ${BUILD_CACHE_REF} is missing or unavailable for import; continuing without --cache-from."
    return 1
}

resolve_runtime_mode() {
    local configured_mode="${BUILD_RUNTIME_MODE:-shared}"
    local allow_full_local_runtime="${BUILD_ALLOW_FULL_LOCAL_RUNTIME:-true}"

    case "${configured_mode}" in
        shared|local)
            ;;
        *)
            err "Unsupported BUILD_RUNTIME_MODE=${configured_mode}. Use shared or local."
            ;;
    esac

    if bool_is_true "${DEPLOY_FULL_LOCAL_RUNTIME:-false}"; then
        if ! bool_is_true "${allow_full_local_runtime}"; then
            err "Full local runtime builds are disabled for this app."
        fi
        log "Emergency runtime override enabled via DEPLOY_FULL_LOCAL_RUNTIME=true"
        EFFECTIVE_RUNTIME_MODE="local"
        return 0
    fi

    EFFECTIVE_RUNTIME_MODE="${configured_mode}"
}

resolve_runtime_image() {
    EFFECTIVE_RUNTIME_IMAGE="${BUILD_RUNTIME_IMAGE:-ghcr.io/anohou/laravel-runtime:8.4-alpine-v1}"
}

resolve_docker_build_target() {
    case "${EFFECTIVE_RUNTIME_MODE}" in
        shared)
            DOCKER_BUILD_TARGET="production-shared"
            ;;
        local)
            DOCKER_BUILD_TARGET="production-local"
            ;;
        *)
            err "Unsupported effective runtime mode: ${EFFECTIVE_RUNTIME_MODE}"
            ;;
    esac
}

ensure_shared_runtime_image_available() {
    if [[ "${EFFECTIVE_RUNTIME_MODE}" != "shared" ]]; then
        return 0
    fi

    start_step_timer
    if docker image inspect "${EFFECTIVE_RUNTIME_IMAGE}" >/dev/null 2>&1; then
        log "Shared runtime image already available locally; refreshing from registry: ${EFFECTIVE_RUNTIME_IMAGE}"
    else
        log "Shared runtime image missing locally; pulling ${EFFECTIVE_RUNTIME_IMAGE} ..."
    fi

    if ! docker pull "${EFFECTIVE_RUNTIME_IMAGE}"; then
        finish_step_timer "ensure_shared_runtime_image"
        err "Unable to refresh shared runtime image ${EFFECTIVE_RUNTIME_IMAGE}. Use DEPLOY_FULL_LOCAL_RUNTIME=true for the emergency full-local path."
    fi

    if ! docker image inspect "${EFFECTIVE_RUNTIME_IMAGE}" >/dev/null 2>&1; then
        finish_step_timer "ensure_shared_runtime_image"
        err "Shared runtime image ${EFFECTIVE_RUNTIME_IMAGE} is unavailable after pull. Use DEPLOY_FULL_LOCAL_RUNTIME=true for the emergency full-local path."
    fi

    log "Shared runtime image ready: ${EFFECTIVE_RUNTIME_IMAGE}"
    finish_step_timer "ensure_shared_runtime_image"
}

run_plain_docker_build() {
    log "Cache path: plain docker build"
    docker build "${DOCKER_BUILD_ARGS[@]}"
}

run_registry_cache_build() {
    local -a buildx_args
    local export_cache=false

    ensure_registry_cache_prereqs
    if [[ -z "${DEPLOY_EXPORT_BUILD_CACHE}" ]]; then
        if bool_is_true "${DEPLOY_WARM_BUILD_CACHE}"; then
            export_cache=true
        fi
    elif bool_is_true "${DEPLOY_EXPORT_BUILD_CACHE}"; then
        export_cache=true
    fi

    if resolve_cache_from_arg; then
        buildx_args=(--cache-from "${RESOLVED_CACHE_FROM_ARG}")
        log "Registry cache import: enabled"
    else
        buildx_args=()
        log "Registry cache import: unavailable"
        if [[ "${export_cache}" != "true" ]]; then
            log "Registry cache export: disabled"
            run_plain_docker_build
            return 0
        fi
    fi

    if [[ "${export_cache}" == "true" ]]; then
        buildx_args+=("--cache-to" "type=registry,ref=${BUILD_CACHE_REF},mode=max")
        log "Registry cache export: enabled"
    else
        log "Registry cache export: disabled"
    fi

    log "Cache mode: registry"
    log "Cache ref: ${BUILD_CACHE_REF}"
    docker buildx build --load "${buildx_args[@]}" "${DOCKER_BUILD_ARGS[@]}"
}

resolve_runtime_mode
resolve_runtime_image
resolve_docker_build_target
collect_runtime_build_arg
ensure_shared_runtime_image_available

if bool_is_true "${DEPLOY_WARM_BUILD_CACHE}"; then
    [[ "${EFFECTIVE_CACHE_MODE}" == "registry" ]] || err "Warm-cache mode requires build.cache_mode=registry."
    if bool_is_true "${DEPLOY_BYPASS_BUILD_CACHE:-false}"; then
        err "DEPLOY_BYPASS_BUILD_CACHE=true is not supported with warm-cache mode."
    fi
fi

if bool_is_true "${DEPLOY_BYPASS_BUILD_CACHE:-false}"; then
    if ! bool_is_true "${BUILD_ALLOW_CACHE_BYPASS:-false}"; then
        err "DEPLOY_BYPASS_BUILD_CACHE=true is not allowed for this app because build.allow_cache_bypass=false."
    fi
fi

log "Building image: ${APP_IMAGE}:${VERSION}"
log "Git SHA: ${GIT_SHA:-none}  |  Source fingerprint: ${SOURCE_FINGERPRINT:0:12}  |  Timestamp: ${TIMESTAMP}  |  Build mode: ${BUILD_MODE}"
log "Runtime mode: ${EFFECTIVE_RUNTIME_MODE}  |  Docker target: ${DOCKER_BUILD_TARGET}  |  Runtime image: ${EFFECTIVE_RUNTIME_IMAGE}"
log "Build input fingerprint: ${BUILD_INPUT_FINGERPRINT:0:12}"

start_step_timer
reuse_existing_image=false
if bool_is_true "${DEPLOY_FORCE_REBUILD:-false}"; then
    log "DEPLOY_FORCE_REBUILD=true requested; bypassing same-tag image reuse"
elif existing_image_matches_source "${APP_IMAGE}:${VERSION}"; then
    log "Skipping docker build; ${APP_IMAGE}:${VERSION} already matches the current build input fingerprint in ${BUILD_MODE} mode"
    reuse_existing_image=true
fi

if [[ "${reuse_existing_image}" != "true" ]]; then

# ── Docker build ───────────────────────────────────────────────────────────────
DOCKER_BUILD_ARGS=(
    --file "${DEPLOY_DIR}/config/Dockerfile"
    --target "${DOCKER_BUILD_TARGET}"
    --tag "${APP_IMAGE}:${VERSION}"
    --tag "${APP_IMAGE}:latest"
    --build-arg BUILDKIT_INLINE_CACHE=1
    --build-arg "IMAGE_TITLE=${APP_IMAGE}"
    --build-arg "IMAGE_SOURCE=${IMAGE_SOURCE:-}"
    --build-arg "IMAGE_REVISION=${GIT_SHA}"
    --build-arg "BUILD_MODE=${BUILD_MODE}"
)

if ((${#BUILD_ARGS[@]} > 0)); then
    DOCKER_BUILD_ARGS+=("${BUILD_ARGS[@]}")
fi
if ((${#VITE_BUILD_ARGS[@]} > 0)); then
    DOCKER_BUILD_ARGS+=("${VITE_BUILD_ARGS[@]}")
fi

DOCKER_BUILD_ARGS+=(
    --label "build.version=${VERSION}"
    --label "build.git-sha=${GIT_SHA}"
    --label "build.source-fingerprint=${SOURCE_FINGERPRINT}"
    --label "build.input-fingerprint=${BUILD_INPUT_FINGERPRINT}"
    --label "build.timestamp=${TIMESTAMP}"
    --label "build.mode=${BUILD_MODE}"
    --label "build.runtime-mode=${EFFECTIVE_RUNTIME_MODE}"
    --label "build.runtime-image=${EFFECTIVE_RUNTIME_IMAGE}"
    --progress plain
    "${APP_ROOT}"
)

    if [[ "${EFFECTIVE_CACHE_MODE}" == "registry" ]] && ! bool_is_true "${DEPLOY_BYPASS_BUILD_CACHE:-false}"; then
        run_registry_cache_build
    else
        if [[ "${EFFECTIVE_CACHE_MODE}" == "registry" ]]; then
            log "Build cache bypass requested; using plain docker build without explicit registry cache flags"
        else
            log "Cache mode: disabled"
        fi
        run_plain_docker_build
    fi

    BUILT_IMAGE=true

fi
finish_step_timer "docker_build_or_reuse"

if [[ "${BUILT_IMAGE}" == "true" ]]; then
    log "✓ Image built: ${APP_IMAGE}:${VERSION}"
else
    log "✓ Reusing existing image: ${APP_IMAGE}:${VERSION}"
fi
write_build_state "${BUILD_STATE_FILE}" "${BUILD_INPUT_FINGERPRINT}" "${VERSION}" "${APP_IMAGE}" "${BUILD_MODE}" "${EFFECTIVE_RUNTIME_MODE}" "${EFFECTIVE_RUNTIME_IMAGE}"

# ── Persist version for downstream scripts ────────────────────────────────────
start_step_timer
echo "${VERSION}" > "${DEPLOY_DIR}/.last-built-version"
log "  Version saved to .last-built-version"
finish_step_timer "persist_version"

# ── Optional: show image size ─────────────────────────────────────────────────
start_step_timer
docker image inspect "${APP_IMAGE}:${VERSION}" \
    --format '  Image size: {{.Size | printf "%.0f"}} bytes'
finish_step_timer "inspect_image"

finish_build_timer
print_timing_summary
write_timing_log
