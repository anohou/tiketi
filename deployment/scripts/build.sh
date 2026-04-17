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

CHECKSUM_BIN=""
CHECKSUM_ARGS=()
TIMINGS_LOG_FILE="${DEPLOY_DIR}/.build-timings.log"
BUILD_TIMER_START=0
BUILD_STEP_START=0
BUILD_TOTAL_DURATION=0
declare -a BUILD_TIMING_LINES=()
declare -a VITE_BUILD_ARGS=()

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
    "${CHECKSUM_BIN}" "${CHECKSUM_ARGS[@]}" "$1" | awk '{print $1}'
}

checksum_stream() {
    "${CHECKSUM_BIN}" "${CHECKSUM_ARGS[@]}" | awk '{print $1}'
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
        coverage|coverage/*|dist|dist/*|build|build/*)
            return 0
            ;;
        deployment/.last-built-version|deployment/.last-deployed-version|deployment/.build-timings.log)
            return 0
            ;;
    esac

    return 1
}

compute_source_fingerprint() {
    start_step_timer
    (
        cd "${APP_ROOT}"
        while IFS= read -r -d '' entry; do
            local rel target checksum
            rel="${entry#./}"
            should_exclude_from_fingerprint "${rel}" && continue

            if [[ -L "${entry}" ]]; then
                target="$(readlink "${entry}")"
                printf 'symlink\t%s\t%s\n' "${rel}" "${target}"
            elif [[ -f "${entry}" ]]; then
                checksum="$(checksum_file "${entry}")"
                printf 'file\t%s\t%s\n' "${rel}" "${checksum}"
            fi
        done < <(find . -mindepth 1 \( -type f -o -type l \) -print0 | LC_ALL=C sort -z)
    ) | checksum_stream
    finish_step_timer "compute_source_fingerprint"
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
        VITE_BUILD_ARGS+=(--build-arg "${key}=${value}")
    done < "${file}"
}

collect_vite_build_args() {
    collect_vite_build_args_from_file "${COMMON_ENV_PATH:-}"
    collect_vite_build_args_from_file "${PROJECT_ENV_PATH:-}"
}

# ── Version tag ────────────────────────────────────────────────────────────────
start_build_timer
detect_checksum_tool
collect_vite_build_args
GIT_SHA="$(git -C "${APP_ROOT}" rev-parse --short HEAD 2>/dev/null || true)"
SOURCE_FINGERPRINT="$(compute_source_fingerprint)"
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
    local existing_sha existing_fingerprint existing_mode

    existing_sha="$(docker image inspect "${image_ref}" --format '{{ index .Config.Labels "build.git-sha" }}' 2>/dev/null || true)"
    existing_fingerprint="$(docker image inspect "${image_ref}" --format '{{ index .Config.Labels "build.source-fingerprint" }}' 2>/dev/null || true)"
    existing_mode="$(docker image inspect "${image_ref}" --format '{{ index .Config.Labels "build.mode" }}' 2>/dev/null || true)"

    [[ "${existing_mode}" == "${BUILD_MODE}" ]] || return 1

    if [[ -n "${existing_fingerprint}" ]]; then
        [[ "${existing_fingerprint}" == "${SOURCE_FINGERPRINT}" ]] || return 1
        return 0
    fi

    [[ -n "${GIT_SHA}" ]] || return 1
    [[ -n "${existing_sha}" ]] || return 1
    [[ "${existing_sha}" == "${GIT_SHA}" ]] || return 1
}

log "Building image: ${APP_IMAGE}:${VERSION}"
log "Git SHA: ${GIT_SHA:-none}  |  Source fingerprint: ${SOURCE_FINGERPRINT:0:12}  |  Timestamp: ${TIMESTAMP}  |  Build mode: ${BUILD_MODE}"

start_step_timer
if existing_image_matches_source "${APP_IMAGE}:${VERSION}"; then
    log "Skipping docker build; ${APP_IMAGE}:${VERSION} already matches the current source fingerprint in ${BUILD_MODE} mode"
else

# ── Docker build ───────────────────────────────────────────────────────────────
docker build \
    --file  "${DEPLOY_DIR}/config/Dockerfile" \
    --tag   "${APP_IMAGE}:${VERSION}" \
    --tag   "${APP_IMAGE}:latest" \
    --build-arg BUILDKIT_INLINE_CACHE=1 \
    --build-arg IMAGE_TITLE="${APP_IMAGE}" \
    --build-arg IMAGE_SOURCE="${IMAGE_SOURCE:-}" \
    --build-arg IMAGE_REVISION="${GIT_SHA}" \
    --build-arg BUILD_MODE="${BUILD_MODE}" \
    "${VITE_BUILD_ARGS[@]}" \
    --label "build.version=${VERSION}" \
    --label "build.git-sha=${GIT_SHA}" \
    --label "build.source-fingerprint=${SOURCE_FINGERPRINT}" \
    --label "build.timestamp=${TIMESTAMP}" \
    --label "build.mode=${BUILD_MODE}" \
    --progress plain \
    "${APP_ROOT}"

    BUILT_IMAGE=true

fi
finish_step_timer "docker_build_or_reuse"

if [[ "${BUILT_IMAGE}" == "true" ]]; then
    log "✓ Image built: ${APP_IMAGE}:${VERSION}"
else
    log "✓ Reusing existing image: ${APP_IMAGE}:${VERSION}"
fi

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
