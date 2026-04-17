#!/usr/bin/env bash

APP_DB_LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DB_DEPLOY_DIR="$(cd "${APP_DB_LIB_DIR}/.." && pwd)"
APP_DB_GENERATED_ENV_PATH="${APP_DB_DEPLOY_DIR}/.env"
APP_DB_LOG_PREFIX="${APP_DB_LOG_PREFIX:-postgres-app-db}"
APP_DB_TIMEOUT_SECONDS="${APP_DB_TIMEOUT_SECONDS:-}"
APP_DB_TIMEOUT_BIN="${APP_DB_TIMEOUT_BIN:-}"
APP_DB_CLIENT_MODE="${APP_DB_CLIENT_MODE:-auto}"

app_db_log() {
    echo "[${APP_DB_LOG_PREFIX}] $*"
}

app_db_warn() {
    echo "[${APP_DB_LOG_PREFIX}] WARN: $*" >&2
}

app_db_err() {
    echo "[${APP_DB_LOG_PREFIX}] ERROR: $*" >&2
    exit 1
}

app_db_require_command() {
    local command_name="$1"
    command -v "${command_name}" >/dev/null 2>&1 || app_db_err "Required command not found: ${command_name}"
}

app_db_require_any_command() {
    local name
    for name in "$@"; do
        if command -v "${name}" >/dev/null 2>&1; then
            return 0
        fi
    done
    app_db_err "Required command not found. Expected one of: $*"
}

app_db_has_command() {
    command -v "$1" >/dev/null 2>&1
}

app_db_require_timeout_support() {
    [[ -z "${APP_DB_TIMEOUT_SECONDS}" ]] && return 0

    if [[ -n "${APP_DB_TIMEOUT_BIN}" ]]; then
        command -v "${APP_DB_TIMEOUT_BIN}" >/dev/null 2>&1 || app_db_err "APP_DB_TIMEOUT_BIN is set but not executable via PATH: ${APP_DB_TIMEOUT_BIN}"
        return 0
    fi

    if command -v timeout >/dev/null 2>&1; then
        APP_DB_TIMEOUT_BIN="timeout"
        return 0
    fi

    if command -v gtimeout >/dev/null 2>&1; then
        APP_DB_TIMEOUT_BIN="gtimeout"
        return 0
    fi

    app_db_err "APP_DB_TIMEOUT_SECONDS is set but neither 'timeout' nor 'gtimeout' is available"
}

app_db_run() {
    if [[ -n "${APP_DB_TIMEOUT_SECONDS}" ]]; then
        app_db_require_timeout_support
        "${APP_DB_TIMEOUT_BIN}" --foreground "${APP_DB_TIMEOUT_SECONDS}" "$@"
    else
        "$@"
    fi
}

app_db_detect_client_mode() {
    case "${APP_DB_CLIENT_MODE}" in
        host|container)
            ;;
        auto)
            if app_db_has_command pg_dump && app_db_has_command pg_restore && app_db_has_command psql; then
                APP_DB_CLIENT_MODE="host"
            else
                APP_DB_CLIENT_MODE="container"
            fi
            ;;
        *)
            app_db_err "Unsupported APP_DB_CLIENT_MODE: ${APP_DB_CLIENT_MODE}"
            ;;
    esac
}

app_db_require_pg_client_suite() {
    app_db_detect_client_mode

    if [[ "${APP_DB_CLIENT_MODE}" == "host" ]]; then
        app_db_require_command pg_dump
        app_db_require_command pg_restore
        app_db_require_command psql
        return 0
    fi

    app_db_require_command docker
    app_db_run docker exec "${POSTGRES_CONTAINER}" sh -lc 'command -v pg_dump >/dev/null && command -v pg_restore >/dev/null && command -v psql >/dev/null' >/dev/null
}

app_db_client_connection_args() {
    local username="$1"
    local dbname="$2"

    if [[ "${APP_DB_CLIENT_MODE}" == "host" ]]; then
        printf -- '--host\n%s\n--port\n%s\n--username\n%s\n--dbname\n%s\n' "${APP_DB_HOST}" "${APP_DB_PORT}" "${username}" "${dbname}"
    else
        printf -- '--host\n127.0.0.1\n--port\n%s\n--username\n%s\n--dbname\n%s\n' "${APP_DB_PORT}" "${username}" "${dbname}"
    fi
}

app_db_client_exec() {
    local password="$1"
    shift

    if [[ "${APP_DB_CLIENT_MODE}" == "host" ]]; then
        PGPASSWORD="${password}" app_db_run "$@"
    else
        app_db_run docker exec -i -e PGPASSWORD="${password}" "${POSTGRES_CONTAINER}" "$@"
    fi
}

app_db_read_connection_args() {
    local username="$1"
    local dbname="$2"
    local target_name="$3"
    local line

    eval "${target_name}=()"
    while IFS= read -r line; do
        eval "${target_name}+=(\"\${line}\")"
    done < <(app_db_client_connection_args "${username}" "${dbname}")
}

app_db_pg_dump_to_file() {
    local password="$1"
    local username="$2"
    local dbname="$3"
    local format="$4"
    local output_path="$5"
    local -a connection_args

    app_db_read_connection_args "${username}" "${dbname}" connection_args

    if [[ "${APP_DB_CLIENT_MODE}" == "host" ]]; then
        app_db_client_exec "${password}" \
            pg_dump \
            --format="${format}" \
            --no-owner \
            --no-privileges \
            "${connection_args[@]}" \
            --file "${output_path}"
    else
        app_db_client_exec "${password}" \
            pg_dump \
            --format="${format}" \
            --no-owner \
            --no-privileges \
            "${connection_args[@]}" \
            > "${output_path}"
    fi
}

app_db_pg_restore_list() {
    local archive_path="$1"

    if [[ "${APP_DB_CLIENT_MODE}" == "host" ]]; then
        app_db_run pg_restore --list "${archive_path}"
    else
        app_db_run docker exec -i "${POSTGRES_CONTAINER}" pg_restore --list < "${archive_path}"
    fi
}

app_db_pg_restore_into_db() {
    local password="$1"
    local username="$2"
    local dbname="$3"
    local archive_path="$4"
    local -a connection_args

    app_db_read_connection_args "${username}" "${dbname}" connection_args

    if [[ "${APP_DB_CLIENT_MODE}" == "host" ]]; then
        app_db_client_exec "${password}" \
            pg_restore \
            --exit-on-error \
            --no-owner \
            --no-privileges \
            "${connection_args[@]}" \
            "${archive_path}"
    else
        app_db_client_exec "${password}" \
            pg_restore \
            --exit-on-error \
            --no-owner \
            --no-privileges \
            "${connection_args[@]}" \
            < "${archive_path}"
    fi
}

app_db_pg_dump_version() {
    if [[ "${APP_DB_CLIENT_MODE}" == "host" ]]; then
        app_db_run pg_dump --version
    else
        app_db_run docker exec "${POSTGRES_CONTAINER}" pg_dump --version
    fi
}

app_db_pg_restore_version() {
    if [[ "${APP_DB_CLIENT_MODE}" == "host" ]]; then
        app_db_run pg_restore --version
    else
        app_db_run docker exec "${POSTGRES_CONTAINER}" pg_restore --version
    fi
}

app_db_trim_value() {
    local value="$1"
    value="${value%$'\r'}"
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"
    printf '%s' "${value}"
}

app_db_get_env_value() {
    local file_path="$1"
    local key="$2"
    [[ -f "${file_path}" ]] || return 1

    local value
    value=$(grep -m1 "^${key}=" "${file_path}" 2>/dev/null | cut -d= -f2- || true)
    value="${value%%[[:space:]]#*}"
    value="$(app_db_trim_value "${value}")"
    printf '%s' "${value}"
}

app_db_require_file() {
    local file_path="$1"
    local label="$2"
    [[ -n "${file_path}" ]] || app_db_err "${label} is not set"
    [[ -f "${file_path}" ]] || app_db_err "${label} not found: ${file_path}"
}

app_db_sql_literal() {
    local value="${1//\'/\'\'}"
    printf "'%s'" "${value}"
}

app_db_load_admin_credentials() {
    app_db_require_file "${DB_ADMIN_ENV_PATH:-}" "DB_ADMIN_ENV_PATH"

    APP_DB_ADMIN_USER="$(app_db_get_env_value "${DB_ADMIN_ENV_PATH}" "POSTGRES_ADMIN_USER")"
    APP_DB_ADMIN_PASS="$(app_db_get_env_value "${DB_ADMIN_ENV_PATH}" "POSTGRES_ADMIN_PASSWORD")"

    [[ -n "${APP_DB_ADMIN_USER}" ]] || app_db_err "POSTGRES_ADMIN_USER not found in ${DB_ADMIN_ENV_PATH}"
    [[ -n "${APP_DB_ADMIN_PASS}" ]] || app_db_err "POSTGRES_ADMIN_PASSWORD not found in ${DB_ADMIN_ENV_PATH}"

    export APP_DB_ADMIN_USER APP_DB_ADMIN_PASS
}

app_db_load_app_role_credentials() {
    app_db_require_file "${PROJECT_ENV_PATH:-}" "PROJECT_ENV_PATH"

    APP_DB_RUNTIME_USER="$(app_db_get_env_value "${PROJECT_ENV_PATH}" "${RBAC_RUNTIME_USERNAME_ENV}")"
    APP_DB_RUNTIME_PASS="$(app_db_get_env_value "${PROJECT_ENV_PATH}" "${RBAC_RUNTIME_PASSWORD_ENV}")"
    APP_DB_MIGRATOR_USER="$(app_db_get_env_value "${PROJECT_ENV_PATH}" "${RBAC_MIGRATOR_USERNAME_ENV}")"
    APP_DB_MIGRATOR_PASS="$(app_db_get_env_value "${PROJECT_ENV_PATH}" "${RBAC_MIGRATOR_PASSWORD_ENV}")"
    APP_DB_DBA_USER="$(app_db_get_env_value "${PROJECT_ENV_PATH}" "${RBAC_DBA_USERNAME_ENV}")"
    APP_DB_DBA_PASS="$(app_db_get_env_value "${PROJECT_ENV_PATH}" "${RBAC_DBA_PASSWORD_ENV}")"
    APP_DB_BACKUP_USER="$(app_db_get_env_value "${PROJECT_ENV_PATH}" "${RBAC_BACKUP_USERNAME_ENV}")"
    APP_DB_BACKUP_PASS="$(app_db_get_env_value "${PROJECT_ENV_PATH}" "${RBAC_BACKUP_PASSWORD_ENV}")"
    APP_DB_TENANT_PROVISIONER_USER="$(app_db_get_env_value "${PROJECT_ENV_PATH}" "DB_PROVISIONER_USERNAME")"
    APP_DB_TENANT_PROVISIONER_PASS="$(app_db_get_env_value "${PROJECT_ENV_PATH}" "DB_PROVISIONER_PASSWORD")"

    [[ -n "${APP_DB_RUNTIME_USER}" ]] || app_db_err "${RBAC_RUNTIME_USERNAME_ENV} not found in ${PROJECT_ENV_PATH}"
    [[ -n "${APP_DB_RUNTIME_PASS}" ]] || app_db_err "${RBAC_RUNTIME_PASSWORD_ENV} not found in ${PROJECT_ENV_PATH}"
    [[ -n "${APP_DB_MIGRATOR_USER}" ]] || app_db_err "${RBAC_MIGRATOR_USERNAME_ENV} not found in ${PROJECT_ENV_PATH}"
    [[ -n "${APP_DB_MIGRATOR_PASS}" ]] || app_db_err "${RBAC_MIGRATOR_PASSWORD_ENV} not found in ${PROJECT_ENV_PATH}"

    if [[ -z "${APP_DB_DBA_USER}" ]]; then
        app_db_warn "${RBAC_DBA_USERNAME_ENV} not set — break-glass role skipped"
    fi

    if [[ -z "${APP_DB_BACKUP_USER}" ]]; then
        app_db_warn "${RBAC_BACKUP_USERNAME_ENV} not set — backup role skipped"
    fi

    if [[ -n "${APP_DB_TENANT_PROVISIONER_USER}" && -z "${APP_DB_TENANT_PROVISIONER_PASS}" ]]; then
        app_db_err "DB_PROVISIONER_PASSWORD not found in ${PROJECT_ENV_PATH}"
    fi

    APP_DB_OWNER_ROLE="$(derive_owner_role "${APP_DB_RUNTIME_USER}")" || app_db_err "Failed to derive owner role"

    export APP_DB_RUNTIME_USER APP_DB_RUNTIME_PASS \
        APP_DB_MIGRATOR_USER APP_DB_MIGRATOR_PASS \
        APP_DB_DBA_USER APP_DB_DBA_PASS \
        APP_DB_BACKUP_USER APP_DB_BACKUP_PASS \
        APP_DB_TENANT_PROVISIONER_USER APP_DB_TENANT_PROVISIONER_PASS \
        APP_DB_OWNER_ROLE
}

app_db_load_host_connection_details() {
    local host=""
    local port=""
    local env_db_name=""

    if [[ -n "${PROJECT_ENV_PATH:-}" && -f "${PROJECT_ENV_PATH}" ]]; then
        host="$(app_db_get_env_value "${PROJECT_ENV_PATH}" "DB_HOST")"
        port="$(app_db_get_env_value "${PROJECT_ENV_PATH}" "DB_PORT")"
        env_db_name="$(app_db_get_env_value "${PROJECT_ENV_PATH}" "DB_DATABASE")"
    fi

    if [[ -z "${host}" || -z "${port}" || -z "${env_db_name}" ]]; then
        if [[ -f "${APP_DB_GENERATED_ENV_PATH}" ]]; then
            [[ -n "${host}" ]] || host="$(app_db_get_env_value "${APP_DB_GENERATED_ENV_PATH}" "DB_HOST")"
            [[ -n "${port}" ]] || port="$(app_db_get_env_value "${APP_DB_GENERATED_ENV_PATH}" "DB_PORT")"
            [[ -n "${env_db_name}" ]] || env_db_name="$(app_db_get_env_value "${APP_DB_GENERATED_ENV_PATH}" "DB_DATABASE")"
        fi
    fi

    [[ -n "${host}" ]] || app_db_err "DB_HOST not found in PROJECT_ENV_PATH or deployment/.env"
    [[ -n "${port}" ]] || app_db_err "DB_PORT not found in PROJECT_ENV_PATH or deployment/.env"
    [[ -n "${env_db_name}" ]] || app_db_err "DB_DATABASE not found in PROJECT_ENV_PATH or deployment/.env"
    [[ "${env_db_name}" == "${DB_NAME}" ]] || app_db_err "DB_DATABASE (${env_db_name}) does not match configured DB_NAME (${DB_NAME})"

    APP_DB_HOST="${host}"
    APP_DB_PORT="${port}"
    APP_DB_DATABASE="${env_db_name}"

    export APP_DB_HOST APP_DB_PORT APP_DB_DATABASE
}

app_db_psql_host_query() {
    local user="$1"
    local pass="$2"
    local dbname="$3"
    local sql="$4"

    local -a connection_args
    app_db_read_connection_args "${user}" "${dbname}" connection_args

    app_db_client_exec "${pass}" \
        psql \
        --no-psqlrc \
        "${connection_args[@]}" \
        --set ON_ERROR_STOP=1 \
        -Atqc "${sql}"
}

app_db_psql_host_exec() {
    local user="$1"
    local pass="$2"
    local dbname="$3"
    shift 3
    local -a connection_args
    app_db_read_connection_args "${user}" "${dbname}" connection_args

    app_db_client_exec "${pass}" \
        psql \
        --no-psqlrc \
        "${connection_args[@]}" \
        --set ON_ERROR_STOP=1 \
        "$@"
}

app_db_build_privilege_predicate() {
    local function_name="$1"
    local role_sql="$2"
    local object_sql="$3"
    local privileges="$4"
    local predicate=""
    local privilege

    IFS=',' read -r -a app_db_privilege_parts <<< "${privileges}"
    for privilege in "${app_db_privilege_parts[@]}"; do
        privilege="${privilege#"${privilege%%[![:space:]]*}"}"
        privilege="${privilege%"${privilege##*[![:space:]]}"}"
        [[ -n "${privilege}" ]] || continue
        if [[ -n "${predicate}" ]]; then
            predicate="${predicate} AND "
        fi
        predicate="${predicate}${function_name}(${role_sql}, ${object_sql}, '$(printf '%s' "${privilege}" | tr '[:lower:]' '[:upper:]')')"
    done

    if [[ -z "${predicate}" ]]; then
        predicate="TRUE"
    fi

    printf '%s' "${predicate}"
}

app_db_psql_admin_query() {
    local dbname="$1"
    local sql="$2"

    app_db_psql_host_query "${APP_DB_ADMIN_USER}" "${APP_DB_ADMIN_PASS}" "${dbname}" "${sql}"
}

app_db_psql_container_query() {
    local dbname="$1"
    local sql="$2"

    app_db_run docker exec -e PGPASSWORD="${APP_DB_ADMIN_PASS}" "${POSTGRES_CONTAINER}" \
        psql -tAc "${sql}" --username "${APP_DB_ADMIN_USER}" --dbname "${dbname}" 2>/dev/null | tr -d '[:space:]'
}

app_db_psql_container_exec() {
    local dbname="$1"
    shift

    app_db_run docker exec -i -e PGPASSWORD="${APP_DB_ADMIN_PASS}" "${POSTGRES_CONTAINER}" \
        psql --set ON_ERROR_STOP=1 --username "${APP_DB_ADMIN_USER}" --dbname "${dbname}" "$@"
}

app_db_load_context() {
    APP_DB_TARGET_DB_NAME="${1:-${DB_NAME:?DB_NAME must be set in config.yml (database.db_name)}}"
    APP_DB_SCHEMA_NAME="${RBAC_SCHEMA_NAME}"

    app_db_load_admin_credentials
    app_db_load_app_role_credentials
    app_db_load_host_connection_details
    app_db_require_pg_client_suite

    export APP_DB_TARGET_DB_NAME APP_DB_SCHEMA_NAME
}

app_db_ensure_database_exists() {
    local target_db="$1"
    local db_exists

    db_exists="$(app_db_psql_container_query postgres "SELECT 1 FROM pg_database WHERE datname = $(app_db_sql_literal "${target_db}")")"
    if [[ "${db_exists}" != "1" ]]; then
        app_db_log "Database '${target_db}' does not exist. Creating..."
        app_db_run docker exec -e PGPASSWORD="${APP_DB_ADMIN_PASS}" "${POSTGRES_CONTAINER}" \
            psql --set ON_ERROR_STOP=1 --username "${APP_DB_ADMIN_USER}" --dbname postgres \
            -c "CREATE DATABASE \"${target_db}\";"
    fi
}

app_db_configure_roles() {
    local target_db="$1"
    local migrator_inherit_sql="INHERIT"

    if [[ "${RBAC_MIGRATOR_LOGIN_NOINHERIT}" == "true" ]]; then
        migrator_inherit_sql="NOINHERIT"
    fi

    app_db_psql_container_exec postgres <<EOF
REVOKE ALL ON DATABASE "${target_db}" FROM PUBLIC;

DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = $(app_db_sql_literal "${APP_DB_OWNER_ROLE}")) THEN CREATE ROLE ${APP_DB_OWNER_ROLE} NOLOGIN; END IF; END \$\$;

DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = $(app_db_sql_literal "${APP_DB_RUNTIME_USER}")) THEN CREATE ROLE ${APP_DB_RUNTIME_USER} WITH LOGIN PASSWORD $(app_db_sql_literal "${APP_DB_RUNTIME_PASS}") CONNECTION LIMIT ${RBAC_RUNTIME_CONNECTION_LIMIT}; ELSE ALTER ROLE ${APP_DB_RUNTIME_USER} WITH PASSWORD $(app_db_sql_literal "${APP_DB_RUNTIME_PASS}") CONNECTION LIMIT ${RBAC_RUNTIME_CONNECTION_LIMIT}; END IF; END \$\$;

DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = $(app_db_sql_literal "${APP_DB_MIGRATOR_USER}")) THEN CREATE ROLE ${APP_DB_MIGRATOR_USER} WITH LOGIN ${migrator_inherit_sql} PASSWORD $(app_db_sql_literal "${APP_DB_MIGRATOR_PASS}") CONNECTION LIMIT ${RBAC_MIGRATOR_CONNECTION_LIMIT}; ELSE ALTER ROLE ${APP_DB_MIGRATOR_USER} WITH ${migrator_inherit_sql} PASSWORD $(app_db_sql_literal "${APP_DB_MIGRATOR_PASS}") CONNECTION LIMIT ${RBAC_MIGRATOR_CONNECTION_LIMIT}; END IF; END \$\$;
$(if [[ -n "${APP_DB_DBA_USER:-}" ]]; then printf "DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = %s) THEN CREATE ROLE %s WITH LOGIN PASSWORD %s CONNECTION LIMIT %s; ELSE ALTER ROLE %s WITH PASSWORD %s CONNECTION LIMIT %s; END IF; END \$\$;\n" "$(app_db_sql_literal "${APP_DB_DBA_USER}")" "${APP_DB_DBA_USER}" "$(app_db_sql_literal "${APP_DB_DBA_PASS}")" "${RBAC_DBA_CONNECTION_LIMIT}" "${APP_DB_DBA_USER}" "$(app_db_sql_literal "${APP_DB_DBA_PASS}")" "${RBAC_DBA_CONNECTION_LIMIT}"; fi)
$(if [[ -n "${APP_DB_BACKUP_USER:-}" ]]; then printf "DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = %s) THEN CREATE ROLE %s WITH LOGIN PASSWORD %s CONNECTION LIMIT %s; ELSE ALTER ROLE %s WITH PASSWORD %s CONNECTION LIMIT %s; END IF; END \$\$;\n" "$(app_db_sql_literal "${APP_DB_BACKUP_USER}")" "${APP_DB_BACKUP_USER}" "$(app_db_sql_literal "${APP_DB_BACKUP_PASS}")" "${RBAC_BACKUP_CONNECTION_LIMIT}" "${APP_DB_BACKUP_USER}" "$(app_db_sql_literal "${APP_DB_BACKUP_PASS}")" "${RBAC_BACKUP_CONNECTION_LIMIT}"; fi)
$(if [[ -n "${APP_DB_TENANT_PROVISIONER_USER:-}" ]]; then printf "DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = %s) THEN CREATE ROLE %s WITH LOGIN CREATEDB PASSWORD %s CONNECTION LIMIT %s; ELSE ALTER ROLE %s WITH LOGIN CREATEDB PASSWORD %s CONNECTION LIMIT %s; END IF; END \$\$;\n" "$(app_db_sql_literal "${APP_DB_TENANT_PROVISIONER_USER}")" "${APP_DB_TENANT_PROVISIONER_USER}" "$(app_db_sql_literal "${APP_DB_TENANT_PROVISIONER_PASS}")" "${RBAC_MIGRATOR_CONNECTION_LIMIT}" "${APP_DB_TENANT_PROVISIONER_USER}" "$(app_db_sql_literal "${APP_DB_TENANT_PROVISIONER_PASS}")" "${RBAC_MIGRATOR_CONNECTION_LIMIT}"; fi)
EOF
}

app_db_apply_schema_and_grants() {
    local target_db="$1"

    app_db_psql_container_exec "${target_db}" <<EOF
REVOKE ALL ON SCHEMA public FROM PUBLIC;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;

CREATE SCHEMA IF NOT EXISTS ${APP_DB_SCHEMA_NAME} AUTHORIZATION ${APP_DB_OWNER_ROLE};

CREATE EXTENSION IF NOT EXISTS "uuid-ossp" SCHEMA ${APP_DB_SCHEMA_NAME};
CREATE EXTENSION IF NOT EXISTS "pgcrypto" SCHEMA ${APP_DB_SCHEMA_NAME};

GRANT CONNECT ON DATABASE "${target_db}" TO ${APP_DB_RUNTIME_USER};
GRANT CONNECT ON DATABASE "${target_db}" TO ${APP_DB_MIGRATOR_USER};
$(if [[ -n "${APP_DB_DBA_USER:-}" ]]; then printf 'GRANT CONNECT ON DATABASE "%s" TO %s;\n' "${target_db}" "${APP_DB_DBA_USER}"; fi)
$(if [[ -n "${APP_DB_BACKUP_USER:-}" ]]; then printf 'GRANT CONNECT ON DATABASE "%s" TO %s;\n' "${target_db}" "${APP_DB_BACKUP_USER}"; fi)
$(if [[ -n "${APP_DB_TENANT_PROVISIONER_USER:-}" ]]; then printf 'GRANT CONNECT ON DATABASE "%s" TO %s;\n' "${target_db}" "${APP_DB_TENANT_PROVISIONER_USER}"; fi)

GRANT USAGE ON SCHEMA ${APP_DB_SCHEMA_NAME} TO ${APP_DB_RUNTIME_USER};
GRANT ${RBAC_RUNTIME_TABLE_PRIVILEGES} ON ALL TABLES IN SCHEMA ${APP_DB_SCHEMA_NAME} TO ${APP_DB_RUNTIME_USER};
GRANT ${RBAC_RUNTIME_SEQUENCE_PRIVILEGES} ON ALL SEQUENCES IN SCHEMA ${APP_DB_SCHEMA_NAME} TO ${APP_DB_RUNTIME_USER};
ALTER DEFAULT PRIVILEGES FOR ROLE ${APP_DB_OWNER_ROLE} IN SCHEMA ${APP_DB_SCHEMA_NAME} GRANT ${RBAC_RUNTIME_TABLE_PRIVILEGES} ON TABLES TO ${APP_DB_RUNTIME_USER};
ALTER DEFAULT PRIVILEGES FOR ROLE ${APP_DB_OWNER_ROLE} IN SCHEMA ${APP_DB_SCHEMA_NAME} GRANT ${RBAC_RUNTIME_SEQUENCE_PRIVILEGES} ON SEQUENCES TO ${APP_DB_RUNTIME_USER};
ALTER ROLE ${APP_DB_RUNTIME_USER} SET search_path = ${APP_DB_SCHEMA_NAME};

REVOKE ${APP_DB_OWNER_ROLE} FROM ${APP_DB_MIGRATOR_USER};
GRANT ${APP_DB_OWNER_ROLE} TO ${APP_DB_MIGRATOR_USER} WITH INHERIT $(rbac_bool_to_sql "${RBAC_OWNER_MEMBERSHIP_INHERIT}"), SET $(rbac_bool_to_sql "${RBAC_OWNER_MEMBERSHIP_SET}");
ALTER ROLE ${APP_DB_MIGRATOR_USER} SET search_path = ${APP_DB_SCHEMA_NAME};
$(if [[ -n "${APP_DB_DBA_USER:-}" ]]; then cat <<DBA_BLOCK
GRANT ${RBAC_DBA_SCHEMA_PRIVILEGES} ON SCHEMA ${APP_DB_SCHEMA_NAME} TO ${APP_DB_DBA_USER};
GRANT ${RBAC_DBA_TABLE_PRIVILEGES} ON ALL TABLES IN SCHEMA ${APP_DB_SCHEMA_NAME} TO ${APP_DB_DBA_USER};
GRANT ${RBAC_DBA_SEQUENCE_PRIVILEGES} ON ALL SEQUENCES IN SCHEMA ${APP_DB_SCHEMA_NAME} TO ${APP_DB_DBA_USER};
ALTER DEFAULT PRIVILEGES FOR ROLE ${APP_DB_OWNER_ROLE} IN SCHEMA ${APP_DB_SCHEMA_NAME} GRANT ${RBAC_DBA_TABLE_PRIVILEGES} ON TABLES TO ${APP_DB_DBA_USER};
ALTER DEFAULT PRIVILEGES FOR ROLE ${APP_DB_OWNER_ROLE} IN SCHEMA ${APP_DB_SCHEMA_NAME} GRANT ${RBAC_DBA_SEQUENCE_PRIVILEGES} ON SEQUENCES TO ${APP_DB_DBA_USER};
ALTER ROLE ${APP_DB_DBA_USER} SET search_path = ${APP_DB_SCHEMA_NAME};
DBA_BLOCK
fi)
$(if [[ -n "${APP_DB_BACKUP_USER:-}" ]]; then cat <<BACKUP_BLOCK
GRANT USAGE ON SCHEMA ${APP_DB_SCHEMA_NAME} TO ${APP_DB_BACKUP_USER};
GRANT ${RBAC_BACKUP_TABLE_PRIVILEGES} ON ALL TABLES IN SCHEMA ${APP_DB_SCHEMA_NAME} TO ${APP_DB_BACKUP_USER};
GRANT ${RBAC_BACKUP_SEQUENCE_PRIVILEGES} ON ALL SEQUENCES IN SCHEMA ${APP_DB_SCHEMA_NAME} TO ${APP_DB_BACKUP_USER};
ALTER DEFAULT PRIVILEGES FOR ROLE ${APP_DB_OWNER_ROLE} IN SCHEMA ${APP_DB_SCHEMA_NAME} GRANT ${RBAC_BACKUP_TABLE_PRIVILEGES} ON TABLES TO ${APP_DB_BACKUP_USER};
ALTER DEFAULT PRIVILEGES FOR ROLE ${APP_DB_OWNER_ROLE} IN SCHEMA ${APP_DB_SCHEMA_NAME} GRANT ${RBAC_BACKUP_SEQUENCE_PRIVILEGES} ON SEQUENCES TO ${APP_DB_BACKUP_USER};
ALTER ROLE ${APP_DB_BACKUP_USER} SET search_path = ${APP_DB_SCHEMA_NAME};
BACKUP_BLOCK
fi)
EOF
}

app_db_verify_state() {
    local target_db="$1"
    local role

    [[ "$(app_db_psql_container_query postgres "SELECT 1 FROM pg_database WHERE datname = $(app_db_sql_literal "${target_db}")")" == "1" ]] || app_db_err "Database '${target_db}' not found"

    for role in "${APP_DB_OWNER_ROLE}" "${APP_DB_RUNTIME_USER}" "${APP_DB_MIGRATOR_USER}"; do
        [[ "$(app_db_psql_container_query postgres "SELECT 1 FROM pg_roles WHERE rolname = $(app_db_sql_literal "${role}")")" == "1" ]] || app_db_err "Role '${role}' not found"
    done

    [[ "$(app_db_psql_container_query "${target_db}" "SELECT 1 FROM pg_namespace WHERE nspname = $(app_db_sql_literal "${APP_DB_SCHEMA_NAME}")")" == "1" ]] || app_db_err "Schema '${APP_DB_SCHEMA_NAME}' not found in '${target_db}'"
}

app_db_backup_privilege_preflight() {
    local target_db="$1"
    local schema_name="$2"
    local db_lit schema_lit role_lit
    local missing_connect missing_schema missing_tables missing_sequences
    local table_predicate sequence_predicate

    [[ -n "${APP_DB_BACKUP_USER:-}" ]] || app_db_err "${RBAC_BACKUP_USERNAME_ENV} not found in ${PROJECT_ENV_PATH}"
    [[ -n "${APP_DB_BACKUP_PASS:-}" ]] || app_db_err "${RBAC_BACKUP_PASSWORD_ENV} not found in ${PROJECT_ENV_PATH}"

    db_lit="$(app_db_sql_literal "${target_db}")"
    schema_lit="$(app_db_sql_literal "${schema_name}")"
    role_lit="$(app_db_sql_literal "${APP_DB_BACKUP_USER}")"

    missing_connect="$(app_db_psql_admin_query postgres "SELECT CASE WHEN has_database_privilege(${role_lit}, ${db_lit}, 'CONNECT') THEN '' ELSE ${db_lit} END")"
    [[ -z "${missing_connect}" ]] || app_db_err "Backup-role preflight failed: missing CONNECT on database '${target_db}' for role '${APP_DB_BACKUP_USER}'"

    missing_schema="$(app_db_psql_admin_query "${target_db}" "SELECT CASE WHEN has_schema_privilege(${role_lit}, ${schema_lit}, 'USAGE') THEN '' ELSE ${schema_lit} END")"
    [[ -z "${missing_schema}" ]] || app_db_err "Backup-role preflight failed: missing USAGE on schema '${schema_name}' for role '${APP_DB_BACKUP_USER}'"

    table_predicate="$(app_db_build_privilege_predicate "has_table_privilege" "${role_lit}" "format('%I.%I', n.nspname, c.relname)" "${RBAC_BACKUP_TABLE_PRIVILEGES}")"
    missing_tables="$(app_db_psql_admin_query "${target_db}" "
SELECT COALESCE(string_agg(format('%I.%I', n.nspname, c.relname), ', ' ORDER BY c.relname), '')
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = ${schema_lit}
  AND c.relkind IN ('r', 'p')
  AND NOT (${table_predicate})
")"
    [[ -z "${missing_tables}" ]] || app_db_err "Backup-role preflight failed: missing table read privilege(s) for role '${APP_DB_BACKUP_USER}': ${missing_tables}"

    sequence_predicate="$(app_db_build_privilege_predicate "has_sequence_privilege" "${role_lit}" "format('%I.%I', n.nspname, c.relname)" "${RBAC_BACKUP_SEQUENCE_PRIVILEGES}")"
    missing_sequences="$(app_db_psql_admin_query "${target_db}" "
SELECT COALESCE(string_agg(format('%I.%I', n.nspname, c.relname), ', ' ORDER BY c.relname), '')
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = ${schema_lit}
  AND c.relkind = 'S'
  AND NOT (${sequence_predicate})
")"
    [[ -z "${missing_sequences}" ]] || app_db_err "Backup-role preflight failed: missing sequence privilege(s) for role '${APP_DB_BACKUP_USER}': ${missing_sequences}"
}

unset APP_DB_LIB_DIR APP_DB_DEPLOY_DIR
