#!/usr/bin/env bash

MYSQL_APP_DB_LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MYSQL_APP_DB_DEPLOY_DIR="$(cd "${MYSQL_APP_DB_LIB_DIR}/.." && pwd)"
MYSQL_APP_DB_GENERATED_ENV_PATH="${MYSQL_APP_DB_DEPLOY_DIR}/.env"
MYSQL_APP_DB_LOG_PREFIX="${MYSQL_APP_DB_LOG_PREFIX:-mysql-app-db}"

mysql_app_log() {
    echo "[${MYSQL_APP_DB_LOG_PREFIX}] $*"
}

mysql_app_warn() {
    echo "[${MYSQL_APP_DB_LOG_PREFIX}] WARN: $*" >&2
}

mysql_app_err() {
    echo "[${MYSQL_APP_DB_LOG_PREFIX}] ERROR: $*" >&2
    exit 1
}

mysql_app_require_command() {
    command -v "$1" >/dev/null 2>&1 || mysql_app_err "Required command not found: $1"
}

mysql_app_trim_value() {
    local value="$1"
    value="${value%$'\r'}"
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"
    printf '%s' "${value}"
}

mysql_app_get_env_value() {
    local file_path="$1"
    local key="$2"
    [[ -f "${file_path}" ]] || return 1

    local value
    value="$(grep -m1 "^${key}=" "${file_path}" 2>/dev/null | cut -d= -f2- || true)"
    value="${value%%[[:space:]]#*}"
    mysql_app_trim_value "${value}"
}

mysql_app_require_file() {
    local file_path="$1"
    local label="$2"
    [[ -n "${file_path}" ]] || mysql_app_err "${label} is not set"
    [[ -f "${file_path}" ]] || mysql_app_err "${label} not found: ${file_path}"
}

mysql_app_bool() {
    case "${1:-}" in
        true|True|TRUE|1|yes|Yes|YES|on|On|ON) printf 'true' ;;
        *) printf 'false' ;;
    esac
}

mysql_app_sql_literal() {
    local value="${1//\\/\\\\}"
    value="${value//\'/\'\'}"
    printf "'%s'" "${value}"
}

mysql_app_validate_db_identifier() {
    local name="$1"
    [[ -n "${name}" ]] || mysql_app_err "Database name is empty"
    [[ "${name}" =~ ^[A-Za-z0-9_]+$ ]] || mysql_app_err "Invalid database identifier '${name}'. Only letters, numbers, and underscores are allowed."
}

mysql_app_quote_identifier() {
    mysql_app_validate_db_identifier "$1"
    printf '`%s`' "$1"
}

mysql_app_account_sql() {
    local user="$1"
    local host="$2"
    printf '%s@%s' "$(mysql_app_sql_literal "${user}")" "$(mysql_app_sql_literal "${host}")"
}

mysql_app_grantee_expr() {
    local user="$1"
    local host="$2"
    printf 'CONCAT(CHAR(39), %s, CHAR(39), %s, CHAR(39), %s, CHAR(39))' \
        "$(mysql_app_sql_literal "${user}")" \
        "$(mysql_app_sql_literal "@")" \
        "$(mysql_app_sql_literal "${host}")"
}

mysql_app_mysql_exec() {
    local user="$1"
    local pass="$2"
    local database="$3"
    local sql="$4"
    local -a args

    args=(
        docker exec
        -i
        -e "MYSQL_PWD=${pass}"
        "${MYSQL_CONTAINER}"
        mysql
        --protocol=TCP
        --host=127.0.0.1
        --port="${APP_DB_PORT}"
        --user="${user}"
        --batch
        --skip-column-names
        --raw
    )

    if [[ -n "${database}" ]]; then
        args+=(--database="${database}")
    fi

    args+=(-e "${sql}")
    "${args[@]}"
}

mysql_app_mysql_admin_exec() {
    local database="$1"
    local sql="$2"
    mysql_app_mysql_exec "${APP_DB_ADMIN_USER}" "${APP_DB_ADMIN_PASS}" "${database}" "${sql}"
}

mysql_app_mysql_user_exec() {
    local user="$1"
    local pass="$2"
    local database="$3"
    local sql="$4"
    mysql_app_mysql_exec "${user}" "${pass}" "${database}" "${sql}"
}

mysql_app_load_admin_credentials() {
    mysql_app_require_file "${DB_ADMIN_ENV_PATH:-}" "DB_ADMIN_ENV_PATH"
    APP_DB_ADMIN_USER="$(mysql_app_get_env_value "${DB_ADMIN_ENV_PATH}" "MYSQL_ADMIN_USER")"
    APP_DB_ADMIN_PASS="$(mysql_app_get_env_value "${DB_ADMIN_ENV_PATH}" "MYSQL_ADMIN_PASSWORD")"

    [[ -n "${APP_DB_ADMIN_USER}" ]] || mysql_app_err "MYSQL_ADMIN_USER not found in ${DB_ADMIN_ENV_PATH}"
    [[ -n "${APP_DB_ADMIN_PASS}" ]] || mysql_app_err "MYSQL_ADMIN_PASSWORD not found in ${DB_ADMIN_ENV_PATH}"

    export APP_DB_ADMIN_USER APP_DB_ADMIN_PASS
}

mysql_app_load_app_role_credentials() {
    mysql_app_require_file "${PROJECT_ENV_PATH:-}" "PROJECT_ENV_PATH"

    APP_DB_RUNTIME_USER="$(mysql_app_get_env_value "${PROJECT_ENV_PATH}" "${MYSQL_ACCESS_RUNTIME_USERNAME_ENV}")"
    APP_DB_RUNTIME_PASS="$(mysql_app_get_env_value "${PROJECT_ENV_PATH}" "${MYSQL_ACCESS_RUNTIME_PASSWORD_ENV}")"
    APP_DB_MIGRATOR_USER="$(mysql_app_get_env_value "${PROJECT_ENV_PATH}" "${MYSQL_ACCESS_MIGRATOR_USERNAME_ENV}")"
    APP_DB_MIGRATOR_PASS="$(mysql_app_get_env_value "${PROJECT_ENV_PATH}" "${MYSQL_ACCESS_MIGRATOR_PASSWORD_ENV}")"
    APP_DB_DBA_USER="$(mysql_app_get_env_value "${PROJECT_ENV_PATH}" "${MYSQL_ACCESS_DBA_USERNAME_ENV}")"
    APP_DB_DBA_PASS="$(mysql_app_get_env_value "${PROJECT_ENV_PATH}" "${MYSQL_ACCESS_DBA_PASSWORD_ENV}")"
    APP_DB_BACKUP_USER="$(mysql_app_get_env_value "${PROJECT_ENV_PATH}" "${MYSQL_ACCESS_BACKUP_USERNAME_ENV}")"
    APP_DB_BACKUP_PASS="$(mysql_app_get_env_value "${PROJECT_ENV_PATH}" "${MYSQL_ACCESS_BACKUP_PASSWORD_ENV}")"

    APP_DB_ACCOUNT_HOST="$(mysql_app_get_env_value "${PROJECT_ENV_PATH}" "${MYSQL_ACCESS_ACCOUNT_HOST_ENV}")"
    [[ -n "${APP_DB_ACCOUNT_HOST}" ]] || APP_DB_ACCOUNT_HOST="$(mysql_app_get_env_value "${DB_ADMIN_ENV_PATH}" "MYSQL_ACCOUNT_HOST")"
    APP_DB_AUTH_PLUGIN="$(mysql_app_get_env_value "${PROJECT_ENV_PATH}" "${MYSQL_ACCESS_AUTH_PLUGIN_ENV}")"
    APP_DB_BACKUP_MODE="$(mysql_app_get_env_value "${PROJECT_ENV_PATH}" "${MYSQL_ACCESS_BACKUP_MODE_ENV}")"
    APP_DB_BACKUP_INCLUDE_ROUTINES="$(mysql_app_bool "$(mysql_app_get_env_value "${PROJECT_ENV_PATH}" "${MYSQL_ACCESS_BACKUP_INCLUDE_ROUTINES_ENV}")")"
    APP_DB_BACKUP_INCLUDE_EVENTS="$(mysql_app_bool "$(mysql_app_get_env_value "${PROJECT_ENV_PATH}" "${MYSQL_ACCESS_BACKUP_INCLUDE_EVENTS_ENV}")")"
    APP_DB_BACKUP_REQUIRE_LOCK_TABLES="$(mysql_app_bool "$(mysql_app_get_env_value "${PROJECT_ENV_PATH}" "${MYSQL_ACCESS_BACKUP_REQUIRE_LOCK_TABLES_ENV}")")"
    APP_DB_RUNTIME_ALLOW_TEMPORARY_TABLES="$(mysql_app_bool "$(mysql_app_get_env_value "${PROJECT_ENV_PATH}" "${MYSQL_ACCESS_RUNTIME_TEMP_TABLES_ENV}")")"

    [[ -n "${APP_DB_RUNTIME_USER}" ]] || mysql_app_err "${MYSQL_ACCESS_RUNTIME_USERNAME_ENV} not found in ${PROJECT_ENV_PATH}"
    [[ -n "${APP_DB_RUNTIME_PASS}" ]] || mysql_app_err "${MYSQL_ACCESS_RUNTIME_PASSWORD_ENV} not found in ${PROJECT_ENV_PATH}"
    [[ -n "${APP_DB_MIGRATOR_USER}" ]] || mysql_app_err "${MYSQL_ACCESS_MIGRATOR_USERNAME_ENV} not found in ${PROJECT_ENV_PATH}"
    [[ -n "${APP_DB_MIGRATOR_PASS}" ]] || mysql_app_err "${MYSQL_ACCESS_MIGRATOR_PASSWORD_ENV} not found in ${PROJECT_ENV_PATH}"
    [[ -n "${APP_DB_ACCOUNT_HOST}" ]] || mysql_app_err "${MYSQL_ACCESS_ACCOUNT_HOST_ENV} must be set and non-empty"

    [[ -n "${APP_DB_DBA_USER}" ]] || mysql_app_warn "${MYSQL_ACCESS_DBA_USERNAME_ENV} not set — DBA user skipped"
    [[ -n "${APP_DB_BACKUP_USER}" ]] || mysql_app_warn "${MYSQL_ACCESS_BACKUP_USERNAME_ENV} not set — backup user skipped"

    [[ -n "${APP_DB_DBA_USER}" && -z "${APP_DB_DBA_PASS}" ]] && mysql_app_err "${MYSQL_ACCESS_DBA_PASSWORD_ENV} not found in ${PROJECT_ENV_PATH}"
    [[ -n "${APP_DB_BACKUP_USER}" && -z "${APP_DB_BACKUP_PASS}" ]] && mysql_app_err "${MYSQL_ACCESS_BACKUP_PASSWORD_ENV} not found in ${PROJECT_ENV_PATH}"

    APP_DB_AUTH_PLUGIN="${APP_DB_AUTH_PLUGIN:-default}"
    [[ "${APP_DB_AUTH_PLUGIN}" == "default" ]] || mysql_app_err "Unsupported MYSQL_AUTH_PLUGIN=${APP_DB_AUTH_PLUGIN}. v1 supports only 'default'."
    APP_DB_BACKUP_MODE="${APP_DB_BACKUP_MODE:-logical_single_transaction}"
    [[ "${APP_DB_BACKUP_MODE}" == "logical_single_transaction" ]] || mysql_app_err "Unsupported MYSQL_BACKUP_MODE=${APP_DB_BACKUP_MODE}. v1 supports only 'logical_single_transaction'."

    export APP_DB_RUNTIME_USER APP_DB_RUNTIME_PASS \
        APP_DB_MIGRATOR_USER APP_DB_MIGRATOR_PASS \
        APP_DB_DBA_USER APP_DB_DBA_PASS \
        APP_DB_BACKUP_USER APP_DB_BACKUP_PASS \
        APP_DB_ACCOUNT_HOST APP_DB_AUTH_PLUGIN \
        APP_DB_BACKUP_MODE APP_DB_BACKUP_INCLUDE_ROUTINES APP_DB_BACKUP_INCLUDE_EVENTS \
        APP_DB_BACKUP_REQUIRE_LOCK_TABLES APP_DB_RUNTIME_ALLOW_TEMPORARY_TABLES
}

mysql_app_load_connection_details() {
    local host port env_db_name

    host="$(mysql_app_get_env_value "${PROJECT_ENV_PATH}" "DB_HOST")"
    port="$(mysql_app_get_env_value "${PROJECT_ENV_PATH}" "DB_PORT")"
    env_db_name="$(mysql_app_get_env_value "${PROJECT_ENV_PATH}" "DB_DATABASE")"

    if [[ -z "${host}" || -z "${port}" || -z "${env_db_name}" ]]; then
        [[ -f "${MYSQL_APP_DB_GENERATED_ENV_PATH}" ]] || mysql_app_err "DB_HOST/DB_PORT/DB_DATABASE not found in PROJECT_ENV_PATH or deployment/.env"
        [[ -n "${host}" ]] || host="$(mysql_app_get_env_value "${MYSQL_APP_DB_GENERATED_ENV_PATH}" "DB_HOST")"
        [[ -n "${port}" ]] || port="$(mysql_app_get_env_value "${MYSQL_APP_DB_GENERATED_ENV_PATH}" "DB_PORT")"
        [[ -n "${env_db_name}" ]] || env_db_name="$(mysql_app_get_env_value "${MYSQL_APP_DB_GENERATED_ENV_PATH}" "DB_DATABASE")"
    fi

    [[ -n "${host}" ]] || mysql_app_err "DB_HOST not found in PROJECT_ENV_PATH or deployment/.env"
    [[ -n "${port}" ]] || mysql_app_err "DB_PORT not found in PROJECT_ENV_PATH or deployment/.env"
    [[ -n "${env_db_name}" ]] || mysql_app_err "DB_DATABASE not found in PROJECT_ENV_PATH or deployment/.env"
    [[ "${env_db_name}" == "${DB_NAME}" ]] || mysql_app_err "DB_DATABASE (${env_db_name}) does not match configured DB_NAME (${DB_NAME})"

    APP_DB_HOST="${host}"
    APP_DB_PORT="${port}"
    APP_DB_DATABASE="${env_db_name}"

    export APP_DB_HOST APP_DB_PORT APP_DB_DATABASE
}

mysql_app_load_context() {
    APP_DB_TARGET_DB_NAME="${1:-${DB_NAME:?DB_NAME must be set in config.yml (database.db_name)}}"
    mysql_app_validate_db_identifier "${APP_DB_TARGET_DB_NAME}"
    mysql_app_require_command docker
    mysql_app_load_admin_credentials
    mysql_app_load_app_role_credentials
    mysql_app_load_connection_details

    export APP_DB_TARGET_DB_NAME
}

mysql_app_create_or_update_user() {
    local user="$1"
    local pass="$2"
    local account_sql
    account_sql="$(mysql_app_account_sql "${user}" "${APP_DB_ACCOUNT_HOST}")"
    mysql_app_mysql_admin_exec "" "
CREATE USER IF NOT EXISTS ${account_sql} IDENTIFIED BY $(mysql_app_sql_literal "${pass}");
ALTER USER ${account_sql} IDENTIFIED BY $(mysql_app_sql_literal "${pass}");
"
}

mysql_app_expected_db_privileges_for_role() {
    local role="$1"
    local privileges=""

    case "${role}" in
        runtime)
            privileges="${MYSQL_ACCESS_RUNTIME_DATABASE_PRIVILEGES}"
            if [[ "${APP_DB_RUNTIME_ALLOW_TEMPORARY_TABLES}" == "true" ]]; then
                privileges="${privileges}, ${MYSQL_ACCESS_RUNTIME_TEMP_TABLE_PRIVILEGES}"
            fi
            ;;
        migrator)
            privileges="${MYSQL_ACCESS_MIGRATOR_DATABASE_PRIVILEGES}"
            ;;
        backup)
            privileges="${MYSQL_ACCESS_BACKUP_DATABASE_PRIVILEGES}"
            if [[ "${APP_DB_BACKUP_INCLUDE_EVENTS}" == "true" ]]; then
                privileges="${privileges}, ${MYSQL_ACCESS_BACKUP_EVENTS_PRIVILEGES}"
            fi
            if [[ "${APP_DB_BACKUP_REQUIRE_LOCK_TABLES}" == "true" ]]; then
                privileges="${privileges}, ${MYSQL_ACCESS_BACKUP_LOCK_TABLES_PRIVILEGES}"
            fi
            ;;
        dba)
            privileges="ALL PRIVILEGES"
            ;;
        *)
            mysql_app_err "Unknown role: ${role}"
            ;;
    esac

    printf '%s\n' "${privileges}"
}

mysql_app_grant_user_privileges() {
    local user="$1"
    local role="$2"
    local account_sql db_ident privileges
    account_sql="$(mysql_app_account_sql "${user}" "${APP_DB_ACCOUNT_HOST}")"
    db_ident="$(mysql_app_quote_identifier "${APP_DB_TARGET_DB_NAME}")"
    privileges="$(mysql_app_expected_db_privileges_for_role "${role}")"
    mysql_app_mysql_admin_exec "" "GRANT ${privileges} ON ${db_ident}.* TO ${account_sql};"
}

mysql_app_ensure_database_exists() {
    local db_ident
    db_ident="$(mysql_app_quote_identifier "${APP_DB_TARGET_DB_NAME}")"
    mysql_app_mysql_admin_exec "" "CREATE DATABASE IF NOT EXISTS ${db_ident} CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
}

mysql_app_configure_roles() {
    mysql_app_create_or_update_user "${APP_DB_RUNTIME_USER}" "${APP_DB_RUNTIME_PASS}"
    mysql_app_grant_user_privileges "${APP_DB_RUNTIME_USER}" "runtime"

    mysql_app_create_or_update_user "${APP_DB_MIGRATOR_USER}" "${APP_DB_MIGRATOR_PASS}"
    mysql_app_grant_user_privileges "${APP_DB_MIGRATOR_USER}" "migrator"

    if [[ -n "${APP_DB_DBA_USER:-}" ]]; then
        mysql_app_create_or_update_user "${APP_DB_DBA_USER}" "${APP_DB_DBA_PASS}"
        mysql_app_grant_user_privileges "${APP_DB_DBA_USER}" "dba"
    fi

    if [[ -n "${APP_DB_BACKUP_USER:-}" ]]; then
        mysql_app_create_or_update_user "${APP_DB_BACKUP_USER}" "${APP_DB_BACKUP_PASS}"
        mysql_app_grant_user_privileges "${APP_DB_BACKUP_USER}" "backup"
    fi
}

mysql_app_schema_privileges_for_account() {
    local user="$1"
    local host="$2"
    local db_name="$3"
    mysql_app_mysql_admin_exec information_schema "
SELECT PRIVILEGE_TYPE
FROM SCHEMA_PRIVILEGES
WHERE GRANTEE = $(mysql_app_grantee_expr "${user}" "${host}")
  AND TABLE_SCHEMA = $(mysql_app_sql_literal "${db_name}")
ORDER BY PRIVILEGE_TYPE;
"
}

mysql_app_global_privileges_for_account() {
    local user="$1"
    local host="$2"
    mysql_app_mysql_admin_exec information_schema "
SELECT PRIVILEGE_TYPE
FROM USER_PRIVILEGES
WHERE GRANTEE = $(mysql_app_grantee_expr "${user}" "${host}")
  AND PRIVILEGE_TYPE <> 'USAGE'
ORDER BY PRIVILEGE_TYPE;
"
}

mysql_app_expected_schema_privileges() {
    local role="$1"
    local privileges
    privileges="$(mysql_app_expected_db_privileges_for_role "${role}")"
    if [[ "${privileges}" == "ALL PRIVILEGES" ]]; then
        printf '%s\n' "ALL PRIVILEGES"
        return 0
    fi
    printf '%s\n' "${privileges}" | tr ',' '\n' | sed 's/^[[:space:]]*//; s/[[:space:]]*$//' | tr '[:lower:]' '[:upper:]' | sort -u
}

mysql_app_verify_account_exists() {
    local user="$1"
    local exists
    exists="$(mysql_app_mysql_admin_exec mysql "SELECT 1 FROM user WHERE User = $(mysql_app_sql_literal "${user}") AND Host = $(mysql_app_sql_literal "${APP_DB_ACCOUNT_HOST}") LIMIT 1;")"
    [[ "${exists}" == "1" ]] || mysql_app_err "Expected MySQL account '${user}'@'${APP_DB_ACCOUNT_HOST}' was not found"
}

mysql_app_verify_account_privileges() {
    local user="$1"
    local role="$2"
    local actual_global actual_schema expected_schema unexpected missing show_grants

    mysql_app_verify_account_exists "${user}"
    actual_global="$(mysql_app_global_privileges_for_account "${user}" "${APP_DB_ACCOUNT_HOST}" || true)"
    if [[ -n "${actual_global}" ]]; then
        mysql_app_err "Critical drift for '${user}'@'${APP_DB_ACCOUNT_HOST}': unexpected global privileges on *.*: ${actual_global//$'\n'/, }"
    fi

    show_grants="$(mysql_app_mysql_admin_exec "" "SHOW GRANTS FOR $(mysql_app_account_sql "${user}" "${APP_DB_ACCOUNT_HOST}")" || true)"
    if printf '%s\n' "${show_grants}" | grep -F ' ON *.* ' | grep -Fv 'GRANT USAGE ON *.*' >/dev/null 2>&1; then
        mysql_app_err "Critical drift for '${user}'@'${APP_DB_ACCOUNT_HOST}': unexpected global grant detected in SHOW GRANTS"
    fi

    if [[ "${role}" == "dba" ]]; then
        printf '%s\n' "${show_grants}" | grep -F "GRANT ALL PRIVILEGES ON $(mysql_app_quote_identifier "${APP_DB_TARGET_DB_NAME}").*" >/dev/null 2>&1 || \
            mysql_app_err "Missing required database-scoped ALL PRIVILEGES grant for DBA account '${user}'@'${APP_DB_ACCOUNT_HOST}'"
        return 0
    fi

    actual_schema="$(mysql_app_schema_privileges_for_account "${user}" "${APP_DB_ACCOUNT_HOST}" "${APP_DB_TARGET_DB_NAME}" || true)"
    expected_schema="$(mysql_app_expected_schema_privileges "${role}")"
    unexpected="$(comm -13 <(printf '%s\n' "${expected_schema}" | sort -u) <(printf '%s\n' "${actual_schema}" | sed '/^$/d' | sort -u) || true)"
    missing="$(comm -23 <(printf '%s\n' "${expected_schema}" | sort -u) <(printf '%s\n' "${actual_schema}" | sed '/^$/d' | sort -u) || true)"

    if [[ -n "${unexpected}" ]]; then
        mysql_app_err "Privilege drift for '${user}'@'${APP_DB_ACCOUNT_HOST}' on $(mysql_app_quote_identifier "${APP_DB_TARGET_DB_NAME}").*: unexpected privilege(s): ${unexpected//$'\n'/, }"
    fi
    if [[ -n "${missing}" ]]; then
        mysql_app_err "Deployment failure for '${user}'@'${APP_DB_ACCOUNT_HOST}' on $(mysql_app_quote_identifier "${APP_DB_TARGET_DB_NAME}").*: missing privilege(s): ${missing//$'\n'/, }"
    fi
}

mysql_app_expect_success() {
    local user="$1"
    local pass="$2"
    local database="$3"
    local sql="$4"
    local label="$5"
    if ! mysql_app_mysql_user_exec "${user}" "${pass}" "${database}" "${sql}" >/dev/null 2>&1; then
        mysql_app_err "${label} failed for '${user}'@'${APP_DB_ACCOUNT_HOST}'"
    fi
}

mysql_app_expect_failure() {
    local user="$1"
    local pass="$2"
    local database="$3"
    local sql="$4"
    local label="$5"
    if mysql_app_mysql_user_exec "${user}" "${pass}" "${database}" "${sql}" >/dev/null 2>&1; then
        mysql_app_err "${label} unexpectedly succeeded for '${user}'@'${APP_DB_ACCOUNT_HOST}'"
    fi
}

mysql_app_backup_dump_flags() {
    local -a flags
    flags=(--single-transaction --quick --triggers --no-tablespaces)
    if [[ "${APP_DB_BACKUP_INCLUDE_ROUTINES}" == "true" ]]; then
        flags+=(--routines)
    fi
    if [[ "${APP_DB_BACKUP_INCLUDE_EVENTS}" == "true" ]]; then
        flags+=(--events)
    fi
    printf '%s\n' "${flags[@]}"
}

mysql_app_backup_dump_probe() {
    local -a dump_args
    local output
    while IFS= read -r flag; do
        [[ -n "${flag}" ]] && dump_args+=("${flag}")
    done < <(mysql_app_backup_dump_flags)

    if ! output="$(
        {
            docker exec -i -e "MYSQL_PWD=${APP_DB_BACKUP_PASS}" "${MYSQL_CONTAINER}" \
                mysqldump \
                --protocol=TCP \
                --host=127.0.0.1 \
                --port="${APP_DB_PORT}" \
                --user="${APP_DB_BACKUP_USER}" \
                "${dump_args[@]}" \
                "${APP_DB_TARGET_DB_NAME}" >/dev/null
        } 2>&1
    )"; then
        if [[ "${APP_DB_BACKUP_INCLUDE_ROUTINES}" == "true" ]] && grep -F "SHOW CREATE PROCEDURE" <<<"${output}" >/dev/null 2>&1; then
            mysql_app_err "Backup routine dump for '${APP_DB_BACKUP_USER}'@'${APP_DB_ACCOUNT_HOST}' requires MySQL's global SHOW_ROUTINE privilege. This conflicts with the database-scoped-only backup model. Disable MYSQL_BACKUP_INCLUDE_ROUTINES or widen privileges intentionally."
        fi
        printf '%s\n' "${output}" >&2
        return 1
    fi
}

mysql_app_mysqldump_to_file() {
    local user="$1"
    local pass="$2"
    local db_name="$3"
    local output_path="$4"
    local -a dump_args

    while IFS= read -r flag; do
        [[ -n "${flag}" ]] && dump_args+=("${flag}")
    done < <(mysql_app_backup_dump_flags)

    docker exec -i -e "MYSQL_PWD=${pass}" "${MYSQL_CONTAINER}" \
        mysqldump \
        --protocol=TCP \
        --host=127.0.0.1 \
        --port="${APP_DB_PORT}" \
        --user="${user}" \
        "${dump_args[@]}" \
        "${db_name}" | gzip -c > "${output_path}"
}

mysql_app_restore_archive_into_db() {
    local archive_path="$1"
    local db_name="$2"

    [[ -f "${archive_path}" ]] || mysql_app_err "Archive not found: ${archive_path}"
    gunzip -c "${archive_path}" | docker exec -i -e "MYSQL_PWD=${APP_DB_ADMIN_PASS}" "${MYSQL_CONTAINER}" \
        mysql \
        --protocol=TCP \
        --host=127.0.0.1 \
        --port="${APP_DB_PORT}" \
        --user="${APP_DB_ADMIN_USER}" \
        "${db_name}"
}

mysql_app_list_tenant_databases() {
    local prefix="$1"
    mysql_app_mysql_admin_exec information_schema "
SELECT SCHEMA_NAME
FROM SCHEMATA
WHERE SCHEMA_NAME LIKE $(mysql_app_sql_literal "${prefix}%")
ORDER BY SCHEMA_NAME;
"
}

mysql_app_run_permission_probes() {
    local probe_table="__codex_probe_access"
    local probe_view="__codex_probe_view"
    local probe_proc="__codex_probe_proc"

    mysql_app_mysql_admin_exec "${APP_DB_TARGET_DB_NAME}" "
DROP VIEW IF EXISTS ${probe_view};
DROP PROCEDURE IF EXISTS ${probe_proc};
DROP TABLE IF EXISTS ${probe_table};
CREATE TABLE ${probe_table} (id INT PRIMARY KEY, value INT NOT NULL);
INSERT INTO ${probe_table} (id, value) VALUES (1, 10);
CREATE VIEW ${probe_view} AS SELECT id, value FROM ${probe_table};
CREATE TRIGGER __codex_probe_trigger BEFORE INSERT ON ${probe_table} FOR EACH ROW SET NEW.value = NEW.value;
"

    mysql_app_expect_success "${APP_DB_RUNTIME_USER}" "${APP_DB_RUNTIME_PASS}" "${APP_DB_TARGET_DB_NAME}" "SELECT * FROM ${probe_view};" "Runtime SELECT probe"
    mysql_app_expect_success "${APP_DB_RUNTIME_USER}" "${APP_DB_RUNTIME_PASS}" "${APP_DB_TARGET_DB_NAME}" "INSERT INTO ${probe_table} (id, value) VALUES (2, 20);" "Runtime INSERT probe"
    mysql_app_expect_success "${APP_DB_RUNTIME_USER}" "${APP_DB_RUNTIME_PASS}" "${APP_DB_TARGET_DB_NAME}" "UPDATE ${probe_table} SET value = 21 WHERE id = 2;" "Runtime UPDATE probe"
    mysql_app_expect_success "${APP_DB_RUNTIME_USER}" "${APP_DB_RUNTIME_PASS}" "${APP_DB_TARGET_DB_NAME}" "DELETE FROM ${probe_table} WHERE id = 2;" "Runtime DELETE probe"
    mysql_app_expect_failure "${APP_DB_RUNTIME_USER}" "${APP_DB_RUNTIME_PASS}" "${APP_DB_TARGET_DB_NAME}" "CREATE TABLE __codex_runtime_forbidden (id INT);" "Runtime CREATE TABLE negative probe"
    mysql_app_expect_failure "${APP_DB_RUNTIME_USER}" "${APP_DB_RUNTIME_PASS}" "${APP_DB_TARGET_DB_NAME}" "ALTER TABLE ${probe_table} ADD COLUMN blocked INT NULL;" "Runtime ALTER TABLE negative probe"
    mysql_app_expect_failure "${APP_DB_RUNTIME_USER}" "${APP_DB_RUNTIME_PASS}" "${APP_DB_TARGET_DB_NAME}" "DROP TABLE ${probe_table};" "Runtime DROP TABLE negative probe"
    if [[ "${APP_DB_RUNTIME_ALLOW_TEMPORARY_TABLES}" == "true" ]]; then
        mysql_app_expect_success "${APP_DB_RUNTIME_USER}" "${APP_DB_RUNTIME_PASS}" "${APP_DB_TARGET_DB_NAME}" "CREATE TEMPORARY TABLE __codex_runtime_tmp (id INT);" "Runtime CREATE TEMPORARY TABLE probe"
    else
        mysql_app_expect_failure "${APP_DB_RUNTIME_USER}" "${APP_DB_RUNTIME_PASS}" "${APP_DB_TARGET_DB_NAME}" "CREATE TEMPORARY TABLE __codex_runtime_tmp (id INT);" "Runtime CREATE TEMPORARY TABLE negative probe"
    fi

    mysql_app_expect_success "${APP_DB_MIGRATOR_USER}" "${APP_DB_MIGRATOR_PASS}" "${APP_DB_TARGET_DB_NAME}" "CREATE TABLE __codex_migrator_probe (id INT PRIMARY KEY, note VARCHAR(32));" "Migrator CREATE TABLE probe"
    mysql_app_expect_success "${APP_DB_MIGRATOR_USER}" "${APP_DB_MIGRATOR_PASS}" "${APP_DB_TARGET_DB_NAME}" "ALTER TABLE __codex_migrator_probe ADD COLUMN extra INT NULL;" "Migrator ALTER TABLE probe"
    mysql_app_expect_success "${APP_DB_MIGRATOR_USER}" "${APP_DB_MIGRATOR_PASS}" "${APP_DB_TARGET_DB_NAME}" "CREATE INDEX idx_note ON __codex_migrator_probe (note);" "Migrator CREATE INDEX probe"
    mysql_app_expect_success "${APP_DB_MIGRATOR_USER}" "${APP_DB_MIGRATOR_PASS}" "${APP_DB_TARGET_DB_NAME}" "ALTER TABLE __codex_migrator_probe DROP INDEX idx_note;" "Migrator DROP INDEX probe"
    mysql_app_expect_success "${APP_DB_MIGRATOR_USER}" "${APP_DB_MIGRATOR_PASS}" "${APP_DB_TARGET_DB_NAME}" "DROP TABLE __codex_migrator_probe;" "Migrator DROP TABLE probe"

    if [[ -n "${APP_DB_BACKUP_USER:-}" ]]; then
        mysql_app_mysql_admin_exec "${APP_DB_TARGET_DB_NAME}" "
DROP PROCEDURE IF EXISTS ${probe_proc};
CREATE PROCEDURE ${probe_proc}() SELECT value FROM ${probe_table} LIMIT 1;
"
        mysql_app_expect_success "${APP_DB_BACKUP_USER}" "${APP_DB_BACKUP_PASS}" "${APP_DB_TARGET_DB_NAME}" "SELECT * FROM ${probe_view};" "Backup SELECT probe"
        mysql_app_expect_success "${APP_DB_BACKUP_USER}" "${APP_DB_BACKUP_PASS}" "${APP_DB_TARGET_DB_NAME}" "SHOW TRIGGERS FROM $(mysql_app_quote_identifier "${APP_DB_TARGET_DB_NAME}") LIKE '__codex_probe_trigger';" "Backup SHOW TRIGGERS probe"
        if [[ "${APP_DB_BACKUP_INCLUDE_ROUTINES}" == "true" ]]; then
            mysql_app_expect_success "${APP_DB_BACKUP_USER}" "${APP_DB_BACKUP_PASS}" "${APP_DB_TARGET_DB_NAME}" "CALL ${probe_proc}();" "Backup routine EXECUTE probe"
        fi
        mysql_app_expect_failure "${APP_DB_BACKUP_USER}" "${APP_DB_BACKUP_PASS}" "${APP_DB_TARGET_DB_NAME}" "INSERT INTO ${probe_table} (id, value) VALUES (3, 30);" "Backup INSERT negative probe"
        mysql_app_expect_failure "${APP_DB_BACKUP_USER}" "${APP_DB_BACKUP_PASS}" "${APP_DB_TARGET_DB_NAME}" "ALTER TABLE ${probe_table} ADD COLUMN blocked2 INT NULL;" "Backup ALTER TABLE negative probe"
        mysql_app_backup_dump_probe || mysql_app_err "Backup mysqldump probe failed for '${APP_DB_BACKUP_USER}'@'${APP_DB_ACCOUNT_HOST}'"
    fi

    mysql_app_mysql_admin_exec "${APP_DB_TARGET_DB_NAME}" "
DROP VIEW IF EXISTS ${probe_view};
DROP PROCEDURE IF EXISTS ${probe_proc};
DROP TRIGGER IF EXISTS __codex_probe_trigger;
DROP TABLE IF EXISTS ${probe_table};
"
}

mysql_app_verify_state() {
    local schema_exists
    schema_exists="$(mysql_app_mysql_admin_exec information_schema "SELECT 1 FROM SCHEMATA WHERE SCHEMA_NAME = $(mysql_app_sql_literal "${APP_DB_TARGET_DB_NAME}") LIMIT 1;")"
    [[ "${schema_exists}" == "1" ]] || mysql_app_err "Database '${APP_DB_TARGET_DB_NAME}' not found"

    mysql_app_verify_account_privileges "${APP_DB_RUNTIME_USER}" "runtime"
    mysql_app_verify_account_privileges "${APP_DB_MIGRATOR_USER}" "migrator"
    [[ -n "${APP_DB_DBA_USER:-}" ]] && mysql_app_verify_account_privileges "${APP_DB_DBA_USER}" "dba"
    [[ -n "${APP_DB_BACKUP_USER:-}" ]] && mysql_app_verify_account_privileges "${APP_DB_BACKUP_USER}" "backup"
}

mysql_app_reapply_and_verify() {
    mysql_app_ensure_database_exists
    mysql_app_configure_roles
    mysql_app_verify_state
    mysql_app_run_permission_probes
}

unset MYSQL_APP_DB_LIB_DIR MYSQL_APP_DB_DEPLOY_DIR
