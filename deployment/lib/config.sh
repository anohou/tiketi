#!/usr/bin/env bash
# ╔══════════════════════════════════════════════════════════════════════════════╗
# ║  lib/config.sh — Pure-bash config.yml loader                               ║
# ║                                                                             ║
# ║  Reads deployment/config/config.yml and exports shell variables.            ║
# ║  Existing environment variables always take priority (CI/CD override).      ║
# ║                                                                             ║
# ║  Usage (source, never execute directly):                                    ║
# ║    source "$(dirname "${BASH_SOURCE[0]}")/lib/config.sh"                   ║
# ║                                                                             ║
# ║  No external dependencies — pure bash, works on any POSIX shell.           ║
# ╚══════════════════════════════════════════════════════════════════════════════╝

_DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
_CONFIG_FILE="${_DEPLOY_DIR}/config/config.yml"

[[ -f "${_CONFIG_FILE}" ]] || {
    echo "[config] ERROR: config.yml not found at ${_CONFIG_FILE}" >&2
    exit 1
}

# ── Minimal YAML key reader ────────────────────────────────────────────────────
_cfg() {
    local file="${_CONFIG_FILE}"
    local l1="$1" l2="${2:-}" l3="${3:-}"
    local in_l1=false in_l2=false value=""

    while IFS= read -r raw_line; do
        local line="${raw_line%%  #*}"
        line="${line%"${line##*[! ]}"}"
        [[ -z "$line" || "$line" =~ ^[[:space:]]*# ]] && continue

        local stripped="${line#"${line%%[! ]*}"}"
        local indent=$(( ${#line} - ${#stripped} ))

        if [[ $indent -eq 0 ]]; then
            in_l1=false; in_l2=false
            if [[ "$stripped" == "${l1}:"* ]]; then
                in_l1=true
                if [[ -z "$l2" ]]; then
                    value="${stripped#*:}"
                    value="${value#"${value%%[! ]*}"}"
                    value="${value%\"}"; value="${value#\"}"; value="${value%\'}"; value="${value#\'}"
                    echo "$value"; return 0
                fi
            fi
        elif [[ $indent -eq 2 && "$in_l1" == true ]]; then
            in_l2=false
            if [[ "$stripped" == "${l2}:"* ]]; then
                in_l2=true
                if [[ -z "$l3" ]]; then
                    value="${stripped#*:}"
                    value="${value#"${value%%[! ]*}"}"
                    value="${value%\"}"; value="${value#\"}"; value="${value%\'}"; value="${value#\'}"
                    echo "$value"; return 0
                fi
            fi
        elif [[ $indent -eq 4 && "$in_l2" == true ]]; then
            if [[ -z "$l3" || "$stripped" == "${l3}:"* ]]; then
                value="${stripped#*:}"
                value="${value#"${value%%[! ]*}"}"
                value="${value%\"}"; value="${value#\"}"; value="${value%\'}"; value="${value#\'}"
                echo "$value"; return 0
            fi
        fi
    done < "$file"
    echo ""
}

# ── Load values (env var wins if already set) ─────────────────────────────────

# app.*
APP_NAME="${APP_NAME:-$(_cfg app name)}"
APP_SLUG="${APP_SLUG:-$(_cfg app slug)}"
APP_URL="${APP_URL:-$(_cfg app url)}"
APP_DOMAIN="${APP_DOMAIN:-$(_cfg app domain)}"
APP_IMAGE="${APP_IMAGE:-$(_cfg app image)}"
COMPOSE_PROJECT_BASE="${COMPOSE_PROJECT_NAME:-$(_cfg app compose_project)}"
TENANT_WILDCARD_DOMAIN="${TENANT_WILDCARD_DOMAIN:-$(_cfg app tenant_wildcard_domain)}"
TENANT_DB_PREFIX="${TENANT_DB_PREFIX:-$(_cfg app tenant_db_prefix)}"

# build.*
BUILD_DEFAULT_SOURCE="${BUILD_DEFAULT_SOURCE:-$(_cfg build default_source)}"
BUILD_ALLOW_LOCAL_BUILD="${BUILD_ALLOW_LOCAL_BUILD:-$(_cfg build allow_local_build)}"
BUILD_REQUIRE_CLEAN_GIT_FOR_LOCAL_BUILD="${BUILD_REQUIRE_CLEAN_GIT_FOR_LOCAL_BUILD:-$(_cfg build require_clean_git_for_local_build)}"
BUILD_LOCAL_BUILD_REQUIRE_EXPLICIT_FLAG="${BUILD_LOCAL_BUILD_REQUIRE_EXPLICIT_FLAG:-$(_cfg build local_build_require_explicit_flag)}"

# registry.*
REGISTRY_PROVIDER="${REGISTRY_PROVIDER:-$(_cfg registry provider)}"
REGISTRY_IMAGE="${REGISTRY_IMAGE:-$(_cfg registry image)}"
REGISTRY_DEFAULT_TAG="${REGISTRY_DEFAULT_TAG:-$(_cfg registry default_tag)}"
REGISTRY_DEPLOY_BY_DIGEST="${REGISTRY_DEPLOY_BY_DIGEST:-$(_cfg registry deploy_by_digest)}"

# repository.*
REPOSITORY_GIT_URL="${REPOSITORY_GIT_URL:-$(_cfg repository git_url)}"
REPOSITORY_DEFAULT_REF="${REPOSITORY_DEFAULT_REF:-$(_cfg repository default_ref)}"

# deploy.*
DEPLOY_STRATEGY="${DEPLOY_STRATEGY:-$(_cfg deploy strategy)}"
DEPLOY_ALLOW_EMERGENCY_BUILD="${DEPLOY_ALLOW_EMERGENCY_BUILD:-$(_cfg deploy allow_emergency_build)}"
DEPLOY_AUDIT_LOG_PATH="${DEPLOY_AUDIT_LOG_PATH:-$(_cfg deploy audit_log_path)}"
DEPLOY_RUNTIME_ROOT="${DEPLOY_RUNTIME_ROOT:-$(_cfg deploy runtime_root)}"
DEPLOY_CI_SOURCE_ROOT="${DEPLOY_CI_SOURCE_ROOT:-$(_cfg deploy ci_source_root)}"

# secrets.*
COMMON_ENV_PATH="${COMMON_ENV_PATH:-$(_cfg secrets common_env_path)}"
DB_ADMIN_ENV_PATH="${DB_ADMIN_ENV_PATH:-$(_cfg secrets db_admin_env_path)}"
PROJECT_ENV_PATH="${PROJECT_ENV_PATH:-$(_cfg secrets project_env_path)}"

# database.*
POSTGRES_CONTAINER="${POSTGRES_CONTAINER:-$(_cfg database postgres_container)}"
DB_NAME="${DB_NAME:-$(_cfg database db_name)}"

# backup.*
BACKUP_OUTPUT_DIR="${BACKUP_OUTPUT_DIR:-$(_cfg backup output_dir)}"

# networks.*
TRAEFIK_SWARM_NETWORK="${TRAEFIK_SWARM_NETWORK:-$(_cfg networks traefik_swarm_network)}"
DB_NETWORK="${DB_NETWORK:-$(_cfg networks db)}"

# traefik.*
TRAEFIK_CERT_RESOLVER="${TRAEFIK_CERT_RESOLVER:-$(_cfg traefik cert_resolver)}"

# services.*
NGINX_IMAGE="${NGINX_IMAGE:-$(_cfg services nginx image)}"
QUEUE_WORKER_ENABLED="${QUEUE_WORKER_ENABLED:-$(_cfg services queue_worker enabled)}"
SCHEDULER_ENABLED="${SCHEDULER_ENABLED:-$(_cfg services scheduler enabled)}"
REVERB_ENABLED="${REVERB_ENABLED:-$(_cfg services reverb enabled)}"
REVERB_PATH="${REVERB_PATH:-$(_cfg services reverb path)}"
REVERB_PORT="${REVERB_PORT:-$(_cfg services reverb port)}"

# create_admin.*
CREATE_ADMIN_MODE="${CREATE_ADMIN_MODE:-$(_cfg create_admin mode)}"
CREATE_ADMIN_COMMAND="${CREATE_ADMIN_COMMAND:-$(_cfg create_admin command)}"
CREATE_ADMIN_SYNC_COMMAND="${CREATE_ADMIN_SYNC_COMMAND:-$(_cfg create_admin sync_command)}"
CREATE_ADMIN_COMMAND_TEMPLATE="${CREATE_ADMIN_COMMAND_TEMPLATE:-$(_cfg create_admin command_template)}"
CREATE_ADMIN_MODEL="${CREATE_ADMIN_MODEL:-$(_cfg create_admin model)}"
CREATE_ADMIN_DEFAULT_ROLE="${CREATE_ADMIN_DEFAULT_ROLE:-$(_cfg create_admin default_role)}"
CREATE_ADMIN_DEFAULT_ACTIVE="${CREATE_ADMIN_DEFAULT_ACTIVE:-$(_cfg create_admin default_active)}"
CREATE_ADMIN_COLUMN_NAME="${CREATE_ADMIN_COLUMN_NAME:-$(_cfg create_admin columns name)}"
CREATE_ADMIN_COLUMN_EMAIL="${CREATE_ADMIN_COLUMN_EMAIL:-$(_cfg create_admin columns email)}"
CREATE_ADMIN_COLUMN_PASSWORD="${CREATE_ADMIN_COLUMN_PASSWORD:-$(_cfg create_admin columns password)}"
CREATE_ADMIN_COLUMN_ROLE="${CREATE_ADMIN_COLUMN_ROLE:-$(_cfg create_admin columns role)}"
CREATE_ADMIN_COLUMN_ACTIVE="${CREATE_ADMIN_COLUMN_ACTIVE:-$(_cfg create_admin columns active)}"
CREATE_ADMIN_COLUMN_EMAIL_VERIFIED_AT="${CREATE_ADMIN_COLUMN_EMAIL_VERIFIED_AT:-$(_cfg create_admin columns email_verified_at)}"

# runtime.*
QUEUE_SLEEP="${QUEUE_SLEEP:-$(_cfg runtime queue sleep)}"
QUEUE_TRIES="${QUEUE_TRIES:-$(_cfg runtime queue tries)}"
QUEUE_TIMEOUT="${QUEUE_TIMEOUT:-$(_cfg runtime queue timeout)}"
QUEUE_MAX_JOBS="${QUEUE_MAX_JOBS:-$(_cfg runtime queue max_jobs)}"
QUEUE_MAX_TIME="${QUEUE_MAX_TIME:-$(_cfg runtime queue max_time)}"

# healthcheck.*
HEALTH_URL="${HEALTH_URL:-$(_cfg healthcheck url)}"
HEALTH_TIMEOUT="${HEALTH_TIMEOUT:-$(_cfg healthcheck timeout)}"
HEALTH_EXPECTED_STATUS="${HEALTH_EXPECTED_STATUS:-$(_cfg healthcheck expected_status)}"

# cleanup.*
ORPHAN_PATTERN="${ORPHAN_PATTERN:-$(_cfg cleanup orphan_pattern)}"

# ── Export everything ─────────────────────────────────────────────────────────
export APP_NAME APP_SLUG APP_URL APP_DOMAIN APP_IMAGE COMPOSE_PROJECT_BASE \
       TENANT_WILDCARD_DOMAIN TENANT_DB_PREFIX \
       BUILD_DEFAULT_SOURCE BUILD_ALLOW_LOCAL_BUILD \
       BUILD_REQUIRE_CLEAN_GIT_FOR_LOCAL_BUILD BUILD_LOCAL_BUILD_REQUIRE_EXPLICIT_FLAG \
       REGISTRY_PROVIDER REGISTRY_IMAGE REGISTRY_DEFAULT_TAG REGISTRY_DEPLOY_BY_DIGEST \
       REPOSITORY_GIT_URL REPOSITORY_DEFAULT_REF \
       DEPLOY_STRATEGY DEPLOY_ALLOW_EMERGENCY_BUILD DEPLOY_AUDIT_LOG_PATH \
       DEPLOY_RUNTIME_ROOT DEPLOY_CI_SOURCE_ROOT \
       COMMON_ENV_PATH DB_ADMIN_ENV_PATH PROJECT_ENV_PATH \
       POSTGRES_CONTAINER DB_NAME BACKUP_OUTPUT_DIR \
       TRAEFIK_SWARM_NETWORK DB_NETWORK \
       TRAEFIK_CERT_RESOLVER \
       NGINX_IMAGE QUEUE_WORKER_ENABLED SCHEDULER_ENABLED REVERB_ENABLED REVERB_PATH REVERB_PORT \
       CREATE_ADMIN_MODE CREATE_ADMIN_COMMAND CREATE_ADMIN_SYNC_COMMAND \
       CREATE_ADMIN_COMMAND_TEMPLATE CREATE_ADMIN_MODEL CREATE_ADMIN_DEFAULT_ROLE \
       CREATE_ADMIN_DEFAULT_ACTIVE CREATE_ADMIN_COLUMN_NAME CREATE_ADMIN_COLUMN_EMAIL \
       CREATE_ADMIN_COLUMN_PASSWORD CREATE_ADMIN_COLUMN_ROLE CREATE_ADMIN_COLUMN_ACTIVE \
       CREATE_ADMIN_COLUMN_EMAIL_VERIFIED_AT \
       QUEUE_SLEEP QUEUE_TRIES QUEUE_TIMEOUT QUEUE_MAX_JOBS QUEUE_MAX_TIME \
       HEALTH_URL HEALTH_TIMEOUT HEALTH_EXPECTED_STATUS \
       ORPHAN_PATTERN

export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_BASE}"

# ── Network readiness helper ──────────────────────────────────────────────────
ensure_docker_networks() {
    local ok=true

    if docker network inspect "${TRAEFIK_SWARM_NETWORK}" > /dev/null 2>&1; then
        echo "[config] ✓ Traefik network '${TRAEFIK_SWARM_NETWORK}' exists" >&2
    else
        echo "[config] Traefik network '${TRAEFIK_SWARM_NETWORK}' not found — creating ..." >&2
        if docker network create --driver bridge --label "managed-by=deployment-kit" "${TRAEFIK_SWARM_NETWORK}" > /dev/null; then
            echo "[config] ✓ Created network: ${TRAEFIK_SWARM_NETWORK}" >&2
        else
            echo "[config] ERROR: Failed to create Traefik network '${TRAEFIK_SWARM_NETWORK}'" >&2
            ok=false
        fi
    fi

    # DB network is owned by the postgres infra kit — must pre-exist
    if docker network inspect "${DB_NETWORK}" > /dev/null 2>&1; then
        echo "[config] ✓ DB network '${DB_NETWORK}' exists" >&2
    else
        echo "[config] ERROR: DB network '${DB_NETWORK}' not found." >&2
        echo "[config]   The postgres infra kit must be started first." >&2
        ok=false
    fi

    [[ "$ok" == true ]]
}

unset _DEPLOY_DIR _CONFIG_FILE
unset -f _cfg
