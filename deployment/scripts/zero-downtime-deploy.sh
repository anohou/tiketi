#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
source "${SCRIPT_DIR}/deploy.config.sh"

VERSION="${1:-$(cat "${DEPLOY_DIR}/.last-built-version" 2>/dev/null || echo "latest")}"
COMPOSE_FILE="${DEPLOY_DIR}/config/docker-compose.prod.yml"
STATE_ROOT="${DEPLOY_DIR}/.deploy"
LOG_ROOT="${STATE_ROOT}/logs"
PUBLIC_SNAPSHOT_ROOT="${DEPLOY_DIR}/.deploy-state/public-snapshots"
ACTIVE_COLOR_FILE="${STATE_ROOT}/active-color"
PREVIOUS_COLOR_FILE="${STATE_ROOT}/previous-color"
CURRENT_STATE_FILE="${STATE_ROOT}/current-state"
MIGRATION_MARKER_FILE="${STATE_ROOT}/migration-complete"
PERSISTENT_PUBLIC_ROOT="${DEPLOY_DIR}/persistent-public/root"
STORAGE_ROOT="$(cd "${DEPLOY_DIR}/../storage" && pwd)"
STORAGE_PUBLIC_DIR="${STORAGE_ROOT}/app/public"
DEPLOY_GATE_DIR="${STORAGE_ROOT}/framework/deploy"
DEPLOY_ID="${DEPLOY_ID:-$(date -u +%Y%m%dT%H%M%SZ)}"
DEPLOY_LOG_DIR="${LOG_ROOT}/${DEPLOY_ID}"
IMAGE_SOURCE_MODE="${DEPLOY_BUILD_SOURCE:-${IMAGE_SOURCE_MODE:-}}"
ALLOW_LOCAL_BUILD="${DEPLOY_ALLOW_LOCAL_BUILD:-${ALLOW_LOCAL_BUILD:-${BUILD_ALLOW_LOCAL_BUILD:-false}}}"
DEPLOY_IMAGE_REF="${DEPLOY_IMAGE_REF:-}"
DEPLOY_IMAGE_DIGEST="${DEPLOY_IMAGE_DIGEST:-}"
DEPLOY_ACTUAL_IMAGE_REF=""
RESUME_AFTER_MIGRATION="${RESUME_AFTER_MIGRATION:-false}"
CENTRAL_MIGRATION_COMPLETED=false

log() { echo "[zero-downtime] $(date '+%H:%M:%S') $*"; }
warn() { echo "[zero-downtime] WARN: $*" >&2; }
err() { echo "[zero-downtime] ERROR: $*" >&2; exit 1; }

trim_shell_value() {
    local value="${1:-}"
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"
    printf '%s' "${value}"
}

write_state() {
    local state="$1"
    mkdir -p "${STATE_ROOT}"
    {
        printf 'deploy_id=%s\n' "${DEPLOY_ID}"
        printf 'active_color=%s\n' "${ACTIVE_COLOR:-}"
        printf 'target_color=%s\n' "${TARGET_COLOR:-}"
        printf 'state=%s\n' "${state}"
        printf 'updated_at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    } > "${CURRENT_STATE_FILE}"
}

compose_profiles() {
    [[ "${QUEUE_WORKER_ENABLED:-true}" == "true" ]] && printf '%s\n' "--profile" "with-queue"
    [[ "${SCHEDULER_ENABLED:-true}" == "true" ]] && printf '%s\n' "--profile" "with-scheduler"
    [[ "${REVERB_ENABLED:-false}" == "true" ]] && printf '%s\n' "--profile" "with-reverb"
}

compose_for() {
    local env_file="$1"
    local project_name="$2"
    shift 2
    local profiles=()
    while IFS= read -r profile; do
        [[ -n "${profile}" ]] && profiles+=("${profile}")
    done < <(compose_profiles)
    docker compose -p "${project_name}" "${profiles[@]}" -f "${COMPOSE_FILE}" --env-file "${env_file}" "$@"
}

project_exists() {
    local env_file="$1"
    local project_name="$2"

    docker compose -p "${project_name}" -f "${COMPOSE_FILE}" --env-file "${env_file}" ps -q 2>/dev/null | grep -q .
}

promote_target_env() {
    cp "${TARGET_ENV_FILE}" "${DEPLOY_DIR}/.env" || err "Failed to promote ${TARGET_ENV_FILE} into ${DEPLOY_DIR}/.env"
}

retire_legacy_stack() {
    if ! project_exists "${TARGET_ENV_FILE}" "${COMPOSE_PROJECT_BASE}"; then
        return 0
    fi

    log "Retiring legacy compose project ${COMPOSE_PROJECT_BASE} to avoid stale routers and env drift"
    docker compose -p "${COMPOSE_PROJECT_BASE}" -f "${COMPOSE_FILE}" --env-file "${TARGET_ENV_FILE}" down --remove-orphans \
        || err "Failed to retire legacy compose project ${COMPOSE_PROJECT_BASE}"
}

env_value_from_file() {
    local file_path="$1"
    local key="$2"
    [[ -f "${file_path}" ]] || return 1
    awk -F= -v wanted="${key}" '
        $1 == wanted {
            value = substr($0, index($0, "=") + 1)
            sub(/\r$/, "", value)
            print value
            exit 0
        }
    ' "${file_path}"
}

load_migration_credentials() {
    MIGRATION_DB_USERNAME="$(require_project_secret_value "DB_MIGRATOR_USERNAME")"
    MIGRATION_DB_PASSWORD="$(require_project_secret_value "DB_MIGRATOR_PASSWORD")"
    export MIGRATION_DB_USERNAME MIGRATION_DB_PASSWORD
}

assert_runtime_db_engine_matches_config() {
    local db_connection expected_connection
    db_connection="$(env_value_from_file "${TARGET_ENV_FILE}" "DB_CONNECTION")"
    case "${APP_DB_ENGINE:-postgres}" in
        postgres) expected_connection="pgsql" ;;
        mysql) expected_connection="mysql" ;;
        *) err "Unsupported APP_DB_ENGINE=${APP_DB_ENGINE}" ;;
    esac
    [[ "${db_connection}" == "${expected_connection}" ]] || err "Runtime DB_CONNECTION=${db_connection} does not match configured database.engine=${APP_DB_ENGINE}"
}

run_migration_command_in_image() {
    local command_string="$1"
    local use_migrator_credentials="$2"
    local db_connection runtime_user app_owner_role

    command_string="$(trim_shell_value "${command_string}")"
    [[ -n "${command_string}" ]] || return 0

    assert_runtime_db_engine_matches_config
    db_connection="$(env_value_from_file "${TARGET_ENV_FILE}" "DB_CONNECTION")"

    if [[ "${use_migrator_credentials}" == "true" ]]; then
        load_migration_credentials
        if [[ "${db_connection}" == "pgsql" ]]; then
            source "${SCRIPT_DIR}/rbac.config.sh"
            runtime_user="$(env_value_from_file "${TARGET_ENV_FILE}" "DB_USERNAME")"
            app_owner_role="$(derive_owner_role "${runtime_user}")" || err "Failed to derive owner role"
            docker run --rm \
                --env-file "${TARGET_ENV_FILE}" \
                --env "DB_USERNAME=${MIGRATION_DB_USERNAME}" \
                --env "DB_PASSWORD=${MIGRATION_DB_PASSWORD}" \
                --env "PGOPTIONS=-c role=${app_owner_role}" \
                --network "${DB_NETWORK}" \
                "${DEPLOY_ACTUAL_IMAGE_REF}" \
                sh -lc "${command_string}"
            return 0
        fi

        docker run --rm \
            --env-file "${TARGET_ENV_FILE}" \
            --env "DB_USERNAME=${MIGRATION_DB_USERNAME}" \
            --env "DB_PASSWORD=${MIGRATION_DB_PASSWORD}" \
            --network "${DB_NETWORK}" \
            "${DEPLOY_ACTUAL_IMAGE_REF}" \
            sh -lc "${command_string}"
        return 0
    fi

    docker run --rm \
        --env-file "${TARGET_ENV_FILE}" \
        --network "${DB_NETWORK}" \
        "${DEPLOY_ACTUAL_IMAGE_REF}" \
        sh -lc "${command_string}"
}

run_migration_stage() {
    local stage_name="$1"
    local command_string="$2"
    local use_migrator_credentials="$3"

    command_string="$(trim_shell_value "${command_string}")"
    [[ -n "${command_string}" ]] || return 0

    log "Running ${stage_name} migration stage..."
    if ! run_migration_command_in_image "${command_string}" "${use_migrator_credentials}"; then
        if [[ "${stage_name}" == "tenant" && "${CENTRAL_MIGRATION_COMPLETED}" == "true" ]]; then
            err "Tenant migration stage failed after central migration succeeded."
        fi
        err "${stage_name^} migration stage failed."
    fi

    if [[ "${stage_name}" == "central" ]]; then
        CENTRAL_MIGRATION_COMPLETED=true
    fi
}

run_migration_preflight_stage() {
    local stage_name="$1"
    local command_string="$2"
    local use_migrator_credentials="$3"
    local log_file="${4:-}"
    local run_safety_check="${5:-false}"

    command_string="$(trim_shell_value "${command_string}")"
    [[ -n "${command_string}" ]] || return 0

    log "Running ${stage_name} migration preflight..."
    if [[ -n "${log_file}" ]]; then
        if ! run_migration_command_in_image "${command_string}" "${use_migrator_credentials}" | tee "${log_file}"; then
            if [[ "${stage_name}" == "tenant" ]]; then
                err "Tenant migration preflight failed before any migration mutation."
            fi
            err "${stage_name^} migration preflight failed."
        fi
    else
        if ! run_migration_command_in_image "${command_string}" "${use_migrator_credentials}"; then
            if [[ "${stage_name}" == "tenant" ]]; then
                err "Tenant migration preflight failed before any migration mutation."
            fi
            err "${stage_name^} migration preflight failed."
        fi
    fi

    if [[ "${run_safety_check}" == "true" ]]; then
        ALLOW_UNSAFE_MIGRATIONS="${ALLOW_UNSAFE_MIGRATIONS:-false}" bash "${SCRIPT_DIR}/check-migration-safety.sh" "${log_file}"
    fi
}

preflight_migration_command() {
    case "${APP_FRAMEWORK:-laravel}" in
        laravel)
            printf '%s\n' "php artisan migrate --pretend --force --no-ansi"
            ;;
        *)
            printf '%s\n' ""
            ;;
    esac
}

resolve_image_source_mode() {
    [[ -n "${IMAGE_SOURCE_MODE}" ]] && { echo "${IMAGE_SOURCE_MODE}"; return; }
    echo "${BUILD_DEFAULT_SOURCE:-remote}"
}

resolve_image_reference() {
    [[ -n "${DEPLOY_IMAGE_REF}" ]] && { echo "${DEPLOY_IMAGE_REF}"; return; }
    [[ -n "${DEPLOY_IMAGE_DIGEST}" ]] && { echo "${APP_IMAGE}@${DEPLOY_IMAGE_DIGEST}"; return; }
    echo "${APP_IMAGE}:${VERSION}"
}

resolve_pulled_digest_reference() {
    local image_ref="$1" digest_ref
    digest_ref="$(
        docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "${image_ref}" 2>/dev/null \
            | grep -E "^${APP_IMAGE}@sha256:" \
            | head -n 1
    )"
    [[ -n "${digest_ref}" ]] || err "Immutable digest reference required, but no RepoDigest was found for ${image_ref}"
    printf '%s\n' "${digest_ref}"
}

resolve_image() {
    DEPLOY_ACTUAL_IMAGE_REF="$(resolve_image_reference)"
    case "$(resolve_image_source_mode)" in
        remote)
            docker pull "${DEPLOY_ACTUAL_IMAGE_REF}" || err "Failed to pull image ${DEPLOY_ACTUAL_IMAGE_REF}"
            if [[ "${REGISTRY_DEPLOY_BY_DIGEST:-false}" == "true" ]]; then
                DEPLOY_ACTUAL_IMAGE_REF="$(resolve_pulled_digest_reference "${DEPLOY_ACTUAL_IMAGE_REF}")"
            fi
            ;;
        local)
            [[ "${DEPLOY_ALLOW_EMERGENCY_BUILD:-${BUILD_ALLOW_LOCAL_BUILD:-false}}" == "true" ]] || err "Local builds are disabled"
            [[ "${ALLOW_LOCAL_BUILD}" == "true" ]] || err "Local builds require DEPLOY_ALLOW_LOCAL_BUILD=true"
            BUILD_MODE=emergency-local bash "${SCRIPT_DIR}/build.sh" "${VERSION}"
            ;;
        *)
            err "Unsupported image source mode"
            ;;
    esac
}

generate_color_env() {
    local color="$1" project="$2" env_file="$3"
    DEPLOY_OUTPUT_ENV="${env_file}" \
    COMPOSE_PROJECT_NAME="${project}" \
    DEPLOY_COLOR="${color}" \
    DEPLOY_RUNTIME_PUBLIC_DIR="runtime-public-${color}" \
    TRAEFIK_DOCKER_ENABLE=false \
        bash "${SCRIPT_DIR}/generate-env.sh" \
            "APP_VERSION=${VERSION}" \
            "DEPLOY_IMAGE_REFERENCE=${DEPLOY_ACTUAL_IMAGE_REF}" \
            "COMPOSE_PROJECT_NAME=${project}" \
            "DEPLOY_COLOR=${color}" \
            "DEPLOY_RUNTIME_PUBLIC_DIR=runtime-public-${color}" \
            "DEPLOY_ENV_FILE_NAME=.env.${color}" \
            "NGINX_CONF_FILE=nginx.${color}.conf" \
            "TRAEFIK_DOCKER_ENABLE=false"
}

render_color_nginx_config() {
    local color="$1"
    local output="${DEPLOY_DIR}/config/nginx.${color}.conf"
    cp "${DEPLOY_DIR}/config/nginx.conf" "${output}"
    awk -v color="${color}" '
        index($0, "set $deploy_color \"single\";") {
            sub(/set \$deploy_color "single";/, "set $deploy_color \"" color "\";")
        }
        { print }
    ' "${output}" > "${output}.tmp"
    mv "${output}.tmp" "${output}"
    sed -i "s#/var/www/html/storage/framework/deploy/ready#/var/www/html/storage/framework/deploy/${COMPOSE_PROJECT_BASE}-${color}.ready#g" "${output}"
}

prepare_runtime_public_from_image() {
    local color="$1"
    local runtime_public_root="${DEPLOY_DIR}/runtime-public-${color}"
    mkdir -p "${runtime_public_root}" "${STORAGE_PUBLIC_DIR}"
    snapshot_runtime_public_before_replace "${color}" "${runtime_public_root}"
    find "${runtime_public_root}" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
    docker run --rm "${DEPLOY_ACTUAL_IMAGE_REF}" sh -lc '
        cd /var/www/html/public
        find . -mindepth 1 -maxdepth 1 -exec tar cpf - {} +
    ' | tar xmf - -C "${runtime_public_root}" --no-same-owner --no-same-permissions \
        || err "Failed to extract public assets from ${DEPLOY_ACTUAL_IMAGE_REF}"

    if find "${PERSISTENT_PUBLIC_ROOT}" -mindepth 1 -print -quit 2>/dev/null | grep -q .; then
        cp -R "${PERSISTENT_PUBLIC_ROOT}/." "${runtime_public_root}/" \
            || err "Failed to overlay persistent public artifacts"
    fi
    rm -rf "${runtime_public_root}/storage"
    ln -s "../storage/app/public" "${runtime_public_root}/storage" \
        || err "Failed to create public storage symlink"
}

snapshot_runtime_public_before_replace() {
    local color="$1"
    local runtime_public_root="$2"
    local snapshot_dir snapshot_id

    [[ -d "${runtime_public_root}" ]] || return 0
    find "${runtime_public_root}" -mindepth 1 -maxdepth 1 -print -quit | grep -q . || return 0

    mkdir -p "${PUBLIC_SNAPSHOT_ROOT}" || err "Failed to create public snapshot root"
    snapshot_id="$(date -u +%Y%m%dT%H%M%SZ)-${color}-${VERSION}"
    snapshot_dir="${PUBLIC_SNAPSHOT_ROOT}/${snapshot_id}"
    if [[ -e "${snapshot_dir}" ]]; then
        snapshot_dir="${snapshot_dir}-$$"
    fi

    mkdir -p "${snapshot_dir}" || err "Failed to create public snapshot ${snapshot_dir}"
    (
        cd "${runtime_public_root}"
        tar cpf - --exclude='./storage' .
    ) | tar xpf - -C "${snapshot_dir}" --no-same-owner --no-same-permissions \
        || err "Failed to snapshot runtime public directory before replacement"

    log "Runtime public snapshot saved to ${snapshot_dir}"
}

normalize_target_permissions() {
    local color="$1"
    local host_uid host_gid runtime_public_root
    host_uid="$(id -u)"
    host_gid="$(id -g)"
    runtime_public_root="${DEPLOY_DIR}/runtime-public-${color}"

    docker run --rm -v "${STORAGE_ROOT}:/storage" alpine sh -c \
        "chown -R 1000:${host_gid} /storage && chmod -R 775 /storage" 2>/dev/null || true

    if [[ -d "${PERSISTENT_PUBLIC_ROOT}" ]]; then
        chown -R "${host_uid}:${host_gid}" "${PERSISTENT_PUBLIC_ROOT}" 2>/dev/null || true
        find "${PERSISTENT_PUBLIC_ROOT}" -mindepth 1 -type d -exec chmod 755 {} \; 2>/dev/null || true
        find "${PERSISTENT_PUBLIC_ROOT}" -mindepth 1 -type f -exec chmod 644 {} \; 2>/dev/null || true
    fi

    if [[ -d "${runtime_public_root}" ]]; then
        chown -R "${host_uid}:${host_gid}" "${runtime_public_root}" 2>/dev/null || true
        find "${runtime_public_root}" -mindepth 1 -type d -exec chmod 755 {} \; 2>/dev/null || true
        find "${runtime_public_root}" -mindepth 1 -type f -exec chmod 644 {} \; 2>/dev/null || true
    fi
}

run_artisan_in_image() {
    local command=("$@")
    run_migration_command_in_image "php artisan ${command[*]}" "${MIGRATION_USE_MIGRATOR_CREDENTIALS:-true}"
}

run_migration_preflight() {
    local log_file="${DEPLOY_LOG_DIR}/migrate-pretend.log"
    local preflight_command
    local central_command tenant_command tenant_preflight_command
    mkdir -p "${DEPLOY_LOG_DIR}"
    central_command="$(trim_shell_value "${MIGRATION_COMMAND:-}")"
    tenant_command="$(trim_shell_value "${TENANT_MIGRATION_COMMAND:-}")"
    tenant_preflight_command="$(trim_shell_value "${TENANT_MIGRATION_PREFLIGHT_COMMAND:-}")"

    if [[ -n "${tenant_preflight_command}" && -z "${tenant_command}" ]]; then
        err "Tenant migration preflight is configured without a tenant migration command."
    fi
    if [[ -n "${tenant_command}" && -z "${tenant_preflight_command}" ]]; then
        err "Tenant migration preflight is required for zero-downtime deploys before any migration mutation."
    fi

    if [[ -n "${central_command}" ]]; then
        preflight_command="$(preflight_migration_command)"
        [[ -n "${preflight_command}" ]] || {
            warn "Migration preflight is not implemented for framework ${APP_FRAMEWORK:-unknown}; skipping"
            preflight_command=""
        }
        if [[ -n "${preflight_command}" ]]; then
            run_migration_preflight_stage "central" "${preflight_command}" "${MIGRATION_USE_MIGRATOR_CREDENTIALS:-true}" "${log_file}" "true"
        fi
    fi

    if [[ -n "${tenant_preflight_command}" ]]; then
        run_migration_preflight_stage "tenant" "${tenant_preflight_command}" "${TENANT_MIGRATION_USE_MIGRATOR_CREDENTIALS:-false}"
    fi
}

run_migrations() {
    run_migration_stage "central" "${MIGRATION_COMMAND:-}" "${MIGRATION_USE_MIGRATOR_CREDENTIALS:-true}"
    run_migration_stage "tenant" "${TENANT_MIGRATION_COMMAND:-}" "${TENANT_MIGRATION_USE_MIGRATOR_CREDENTIALS:-false}"
    printf 'deploy_id=%s\ncolor=%s\ncompleted_at=%s\n' "${DEPLOY_ID}" "${TARGET_COLOR}" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "${MIGRATION_MARKER_FILE}"
}

stop_scheduler_and_drain_workers() {
    write_state WORKERS_STOPPING
    compose_for "${ACTIVE_ENV_FILE}" "${ACTIVE_PROJECT}" stop scheduler >/dev/null 2>&1 || true
    compose_for "${ACTIVE_ENV_FILE}" "${ACTIVE_PROJECT}" exec -T php-fpm php artisan queue:restart --no-ansi >/dev/null 2>&1 || true

    local queue_services=()
    [[ "${QUEUE_WORKER_ENABLED:-true}" == "true" ]] && queue_services+=(queue-worker)
    [[ "${#queue_services[@]}" -eq 0 ]] && return 0

    case "${QUEUE_DRAIN_TIMEOUT_ACTION:-abort}" in
        abort)
            compose_for "${ACTIVE_ENV_FILE}" "${ACTIVE_PROJECT}" stop -t "${QUEUE_DRAIN_SECONDS:-60}" "${queue_services[@]}" >/dev/null 2>&1 \
                || err "Queue drain timed out after ${QUEUE_DRAIN_SECONDS}s; aborting with old web live. Set QUEUE_DRAIN_TIMEOUT_ACTION=proceed to interrupt remaining workers."
            ;;
        proceed)
            if ! compose_for "${ACTIVE_ENV_FILE}" "${ACTIVE_PROJECT}" stop -t "${QUEUE_DRAIN_SECONDS:-60}" "${queue_services[@]}" >/dev/null 2>&1; then
                warn "Queue drain timed out; force-stopping remaining workers because QUEUE_DRAIN_TIMEOUT_ACTION=proceed"
                compose_for "${ACTIVE_ENV_FILE}" "${ACTIVE_PROJECT}" kill "${queue_services[@]}" >/dev/null 2>&1 || true
            fi
            ;;
        *)
            err "Unsupported QUEUE_DRAIN_TIMEOUT_ACTION=${QUEUE_DRAIN_TIMEOUT_ACTION}"
            ;;
    esac
}

mark_ready() {
    mkdir -p "${DEPLOY_GATE_DIR}"
    printf '%s\n' "${DEPLOY_ACTUAL_IMAGE_REF}" > "${DEPLOY_GATE_DIR}/${COMPOSE_PROJECT_BASE}-${TARGET_COLOR}.ready"
}

warm_and_validate() {
    local attempts="${HEALTHCHECK_ATTEMPTS:-30}" interval="${HEALTHCHECK_INTERVAL_SECONDS:-5}" timeout="${HEALTHCHECK_TIMEOUT_SECONDS:-3}"
    for _ in 1 2; do
        compose_for "${TARGET_ENV_FILE}" "${TARGET_PROJECT}" exec -T nginx sh -lc "wget -q -O /dev/null --timeout=${timeout} http://127.0.0.1:8080/readyz" >/dev/null 2>&1 || true
        compose_for "${TARGET_ENV_FILE}" "${TARGET_PROJECT}" exec -T nginx sh -lc "wget -q -O /dev/null --timeout=${timeout} --header='Host: ${APP_DOMAIN}' http://127.0.0.1:8080/" >/dev/null 2>&1 || true
    done

    for ((i=1; i<=attempts; i++)); do
        headers="$(compose_for "${TARGET_ENV_FILE}" "${TARGET_PROJECT}" exec -T nginx sh -lc "wget -S -O /dev/null --timeout=${timeout} http://127.0.0.1:8080/readyz" 2>&1 || true)"
        status="$(printf '%s\n' "${headers}" | awk '/^  HTTP\// {code=$2} END {print code}')"
        color="$(printf '%s\n' "${headers}" | tr -d '\r' | awk 'tolower($0) ~ /^[[:space:]]*x-deploy-color:/ {print $2; exit}')"
        if [[ "${status}" == "200" && "${color}" == "${TARGET_COLOR}" ]]; then
            return 0
        fi
        [[ "${status}" == "500" ]] && err "Target color returned HTTP 500 during validation"
        sleep "${interval}"
    done
    err "Target color did not become healthy within validation budget"
}

verify_dynamic_wildcard_config() {
    if [[ -z "${TENANT_WILDCARD_DOMAIN:-}" || "${TENANT_WILDCARD_DOMAIN}" == "null" ]]; then
        return 0
    fi
    local dynamic_file="${TRAEFIK_DYNAMIC_FILE:-${TRAEFIK_DYNAMIC_DIR:-$(dirname "${DEPLOY_RUNTIME_ROOT}")/releases/config/traefik/dynamic}/dynamic-${COMPOSE_PROJECT_BASE}.yml}"
    [[ -f "${dynamic_file}" ]] || return 0
    grep -q 'HostRegexp' "${dynamic_file}" || err "Traefik dynamic config does not contain a tenant HostRegexp rule"
    grep -q "${APP_DOMAIN}" "${dynamic_file}" || err "Traefik dynamic config does not contain APP_DOMAIN ${APP_DOMAIN}"
}

post_switch_smoke() {
    bash "${SCRIPT_DIR}/check-traefik-active-color.sh" "${TARGET_COLOR}" "${APP_DOMAIN}" "/readyz"

    IFS=',' read -r -a hosts <<< "${TENANT_SMOKE_HOSTS:-}"
    for host in "${hosts[@]}"; do
        host="$(printf '%s' "${host}" | xargs)"
        [[ -z "${host}" ]] && continue
        bash "${SCRIPT_DIR}/check-traefik-active-color.sh" "${TARGET_COLOR}" "${host}" "/readyz"
    done

    curl -fsS --max-time 10 "https://${APP_DOMAIN}/readyz" >/dev/null || warn "Cloudflare-facing smoke failed for ${APP_DOMAIN}/readyz"
}

start_target_workers() {
    write_state STARTING_WORKERS
    local services=()
    [[ "${QUEUE_WORKER_ENABLED:-true}" == "true" ]] && services+=(queue-worker)
    [[ "${SCHEDULER_ENABLED:-true}" == "true" ]] && services+=(scheduler)
    [[ "${#services[@]}" -eq 0 ]] && return 0
    compose_for "${TARGET_ENV_FILE}" "${TARGET_PROJECT}" up -d "${services[@]}"
}

select_colors() {
    ACTIVE_COLOR="$(cat "${ACTIVE_COLOR_FILE}" 2>/dev/null || true)"
    case "${ACTIVE_COLOR}" in
        blue) TARGET_COLOR="green" ;;
        green) TARGET_COLOR="blue" ;;
        *)
            if [[ -f "${DEPLOY_DIR}/.env" ]]; then
                ACTIVE_COLOR="legacy"
                TARGET_COLOR="blue"
            else
                ACTIVE_COLOR="blue"
                TARGET_COLOR="green"
            fi
            ;;
    esac

    if [[ "${ACTIVE_COLOR}" == "legacy" ]]; then
        ACTIVE_PROJECT="${COMPOSE_PROJECT_BASE}"
        ACTIVE_ENV_FILE="${DEPLOY_DIR}/.env"
    else
        ACTIVE_PROJECT="${COMPOSE_PROJECT_BASE}-${ACTIVE_COLOR}"
        ACTIVE_ENV_FILE="${DEPLOY_DIR}/.env.${ACTIVE_COLOR}"
    fi
    TARGET_PROJECT="${COMPOSE_PROJECT_BASE}-${TARGET_COLOR}"
    TARGET_ENV_FILE="${DEPLOY_DIR}/.env.${TARGET_COLOR}"
}

rollback_traefik_if_possible() {
    local dynamic_dir="${TRAEFIK_DYNAMIC_DIR:-$(dirname "${DEPLOY_RUNTIME_ROOT}")/releases/config/traefik/dynamic}"
    local backup="${TRAEFIK_DYNAMIC_FILE:-${dynamic_dir}/dynamic-${COMPOSE_PROJECT_BASE}.yml}.previous"
    local active_file="${TRAEFIK_DYNAMIC_FILE:-${dynamic_dir}/dynamic-${COMPOSE_PROJECT_BASE}.yml}"
    if [[ -f "${backup}" ]]; then
        cp "${backup}" "${active_file}" || true
    else
        rm -f "${active_file}" || true
    fi
}

on_error() {
    local status=$?
    local state
    state="$(grep -m1 '^state=' "${CURRENT_STATE_FILE}" 2>/dev/null | cut -d= -f2- || true)"
    if [[ "${state}" == "SWITCHING" || "${state}" == "POST_SWITCH_SMOKE" ]]; then
        rollback_traefik_if_possible
    fi
    write_state FAILED || true
    exit "${status}"
}

trap on_error ERR

[[ "${ZERO_DOWNTIME_ENABLED:-false}" == "true" ]] || err "ZERO_DOWNTIME_ENABLED must be true to use Laravel zero-downtime deploy"
command -v flock >/dev/null 2>&1 || err "flock is required"
command -v docker >/dev/null 2>&1 || err "docker is required"
command -v curl >/dev/null 2>&1 || err "curl is required"
command -v tar >/dev/null 2>&1 || err "tar is required"
command -v find >/dev/null 2>&1 || err "find is required"

mkdir -p \
    "${STATE_ROOT}" \
    "${LOG_ROOT}" \
    "${DEPLOY_GATE_DIR}" \
    "${PERSISTENT_PUBLIC_ROOT}" \
    "${STORAGE_PUBLIC_DIR}" \
    "${STORAGE_ROOT}/framework/cache/data" \
    "${STORAGE_ROOT}/framework/sessions" \
    "${STORAGE_ROOT}/framework/testing" \
    "${STORAGE_ROOT}/framework/views" \
    "${STORAGE_ROOT}/logs"
LOCK_DIR="${DEPLOY_DIR}/.deploy-lock"
mkdir -p "${LOCK_DIR}"
LOCK_FILE="${LOCK_DIR}/zero-downtime.lock"

(
    flock 9
    select_colors

    log "Deploy ${DEPLOY_ID}: active=${ACTIVE_COLOR} target=${TARGET_COLOR}"
    write_state PREPARING
    ensure_docker_networks || err "Required Docker networks are not ready"
    bash "${SCRIPT_DIR}/provision-db.sh"
    resolve_image

    generate_color_env "${TARGET_COLOR}" "${TARGET_PROJECT}" "${TARGET_ENV_FILE}"
    render_color_nginx_config "${TARGET_COLOR}"
    export DEPLOY_ENV_FILE_NAME=".env.${TARGET_COLOR}"
    export NGINX_CONF_FILE="nginx.${TARGET_COLOR}.conf"
    export DEPLOY_RUNTIME_PUBLIC_DIR="runtime-public-${TARGET_COLOR}"
    compose_for "${TARGET_ENV_FILE}" "${TARGET_PROJECT}" config >/dev/null

    if [[ "${RESUME_AFTER_MIGRATION}" != "true" ]]; then
        if [[ -f "${ACTIVE_ENV_FILE}" ]]; then
            stop_scheduler_and_drain_workers
        fi
        write_state MIGRATION_PREFLIGHT
        run_migration_preflight
        write_state MIGRATING
        run_migrations
    else
        [[ -f "${MIGRATION_MARKER_FILE}" ]] || err "Cannot resume: migration marker not found"
        log "Resuming after migration marker; migrations will not be re-run"
    fi

    write_state STARTING_WEB
    prepare_runtime_public_from_image "${TARGET_COLOR}"
    normalize_target_permissions "${TARGET_COLOR}"
    mark_ready
    compose_for "${TARGET_ENV_FILE}" "${TARGET_PROJECT}" up -d php-fpm nginx
    if [[ "${REVERB_ENABLED:-false}" == "true" ]]; then
        compose_for "${TARGET_ENV_FILE}" "${TARGET_PROJECT}" up -d reverb
    fi

    write_state WARMING
    DEPLOY_COMPOSE_FILE="${COMPOSE_FILE}" DEPLOY_ENV_FILE="${TARGET_ENV_FILE}" DEPLOY_COMPOSE_PROJECT_NAME="${TARGET_PROJECT}" bash "${SCRIPT_DIR}/rebuild-cache.sh"

    write_state VALIDATING
    warm_and_validate

    write_state SWITCHING
    TRAEFIK_DYNAMIC_DIR="${TRAEFIK_DYNAMIC_DIR:-$(dirname "${DEPLOY_RUNTIME_ROOT}")/releases/config/traefik/dynamic}" \
        bash "${SCRIPT_DIR}/traefik-switch.sh" "${TARGET_COLOR}" "${TARGET_PROJECT}" "${TARGET_ENV_FILE}"
    verify_dynamic_wildcard_config

    write_state POST_SWITCH_SMOKE
    post_switch_smoke

    start_target_workers

    write_state DRAINING
    sleep "${DEPLOY_DRAIN_SECONDS:-30}"
    promote_target_env
    retire_legacy_stack
    printf '%s\n' "${TARGET_COLOR}" > "${ACTIVE_COLOR_FILE}"
    printf '%s\n' "${ACTIVE_COLOR}" > "${PREVIOUS_COLOR_FILE}"
    printf 'deploy_id=%s\ncolor=%s\nimage=%s\ncompleted_at=%s\n' \
        "${DEPLOY_ID}" "${TARGET_COLOR}" "${DEPLOY_ACTUAL_IMAGE_REF}" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        > "${STATE_ROOT}/last-successful-deploy"

    write_state SUCCEEDED
    log "✓ Zero-downtime deploy complete: ${TARGET_COLOR} (${DEPLOY_ACTUAL_IMAGE_REF})"
) 9>"${LOCK_FILE}"
