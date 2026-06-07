#!/usr/bin/env bash

MYSQL_ACCESS_DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MYSQL_ACCESS_POLICY_FILE="${MYSQL_ACCESS_DEPLOY_DIR}/config/mysql-access-policy.yml"

[[ -f "${MYSQL_ACCESS_POLICY_FILE}" ]] || {
    echo "[mysql-access] ERROR: mysql-access-policy.yml not found at ${MYSQL_ACCESS_POLICY_FILE}" >&2
    exit 1
}

_mysql_access_cfg() {
    local file="${MYSQL_ACCESS_POLICY_FILE}"
    local l1="$1" l2="${2:-}"
    local in_l1=false value=""

    while IFS= read -r raw_line; do
        local line="${raw_line%%  #*}"
        line="${line%"${line##*[! ]}"}"
        [[ -z "${line}" || "${line}" =~ ^[[:space:]]*# ]] && continue

        local stripped="${line#"${line%%[! ]*}"}"
        local indent=$(( ${#line} - ${#stripped} ))

        if [[ ${indent} -eq 0 ]]; then
            in_l1=false
            if [[ "${stripped}" == "${l1}:"* ]]; then
                in_l1=true
            fi
        elif [[ ${indent} -eq 2 && "${in_l1}" == true ]]; then
            if [[ "${stripped}" == "${l2}:"* ]]; then
                value="${stripped#*:}"
                value="${value#"${value%%[! ]*}"}"
                value="${value%\"}"; value="${value#\"}"; value="${value%\'}"; value="${value#\'}"
                printf '%s\n' "${value}"
                return 0
            fi
        fi
    done < "${file}"

    printf '\n'
}

MYSQL_ACCESS_RUNTIME_USERNAME_ENV="${MYSQL_ACCESS_RUNTIME_USERNAME_ENV:-$(_mysql_access_cfg role_env runtime_username)}"
MYSQL_ACCESS_RUNTIME_PASSWORD_ENV="${MYSQL_ACCESS_RUNTIME_PASSWORD_ENV:-$(_mysql_access_cfg role_env runtime_password)}"
MYSQL_ACCESS_MIGRATOR_USERNAME_ENV="${MYSQL_ACCESS_MIGRATOR_USERNAME_ENV:-$(_mysql_access_cfg role_env migrator_username)}"
MYSQL_ACCESS_MIGRATOR_PASSWORD_ENV="${MYSQL_ACCESS_MIGRATOR_PASSWORD_ENV:-$(_mysql_access_cfg role_env migrator_password)}"
MYSQL_ACCESS_DBA_USERNAME_ENV="${MYSQL_ACCESS_DBA_USERNAME_ENV:-$(_mysql_access_cfg role_env dba_username)}"
MYSQL_ACCESS_DBA_PASSWORD_ENV="${MYSQL_ACCESS_DBA_PASSWORD_ENV:-$(_mysql_access_cfg role_env dba_password)}"
MYSQL_ACCESS_BACKUP_USERNAME_ENV="${MYSQL_ACCESS_BACKUP_USERNAME_ENV:-$(_mysql_access_cfg role_env backup_username)}"
MYSQL_ACCESS_BACKUP_PASSWORD_ENV="${MYSQL_ACCESS_BACKUP_PASSWORD_ENV:-$(_mysql_access_cfg role_env backup_password)}"
MYSQL_ACCESS_RUNTIME_TEMP_TABLES_ENV="${MYSQL_ACCESS_RUNTIME_TEMP_TABLES_ENV:-$(_mysql_access_cfg runtime allow_temporary_tables_env)}"
MYSQL_ACCESS_ACCOUNT_HOST_ENV="${MYSQL_ACCESS_ACCOUNT_HOST_ENV:-$(_mysql_access_cfg account host_env)}"
MYSQL_ACCESS_AUTH_PLUGIN_ENV="${MYSQL_ACCESS_AUTH_PLUGIN_ENV:-$(_mysql_access_cfg account auth_plugin_env)}"
MYSQL_ACCESS_BACKUP_MODE_ENV="${MYSQL_ACCESS_BACKUP_MODE_ENV:-$(_mysql_access_cfg backup mode_env)}"
MYSQL_ACCESS_BACKUP_INCLUDE_ROUTINES_ENV="${MYSQL_ACCESS_BACKUP_INCLUDE_ROUTINES_ENV:-$(_mysql_access_cfg backup include_routines_env)}"
MYSQL_ACCESS_BACKUP_INCLUDE_EVENTS_ENV="${MYSQL_ACCESS_BACKUP_INCLUDE_EVENTS_ENV:-$(_mysql_access_cfg backup include_events_env)}"
MYSQL_ACCESS_BACKUP_REQUIRE_LOCK_TABLES_ENV="${MYSQL_ACCESS_BACKUP_REQUIRE_LOCK_TABLES_ENV:-$(_mysql_access_cfg backup require_lock_tables_env)}"
MYSQL_ACCESS_RUNTIME_DATABASE_PRIVILEGES="${MYSQL_ACCESS_RUNTIME_DATABASE_PRIVILEGES:-$(_mysql_access_cfg privileges runtime_database)}"
MYSQL_ACCESS_RUNTIME_TEMP_TABLE_PRIVILEGES="${MYSQL_ACCESS_RUNTIME_TEMP_TABLE_PRIVILEGES:-$(_mysql_access_cfg privileges runtime_temporary_tables)}"
MYSQL_ACCESS_MIGRATOR_DATABASE_PRIVILEGES="${MYSQL_ACCESS_MIGRATOR_DATABASE_PRIVILEGES:-$(_mysql_access_cfg privileges migrator_database)}"
MYSQL_ACCESS_BACKUP_DATABASE_PRIVILEGES="${MYSQL_ACCESS_BACKUP_DATABASE_PRIVILEGES:-$(_mysql_access_cfg privileges backup_database)}"
MYSQL_ACCESS_BACKUP_EVENTS_PRIVILEGES="${MYSQL_ACCESS_BACKUP_EVENTS_PRIVILEGES:-$(_mysql_access_cfg privileges backup_events)}"
MYSQL_ACCESS_BACKUP_LOCK_TABLES_PRIVILEGES="${MYSQL_ACCESS_BACKUP_LOCK_TABLES_PRIVILEGES:-$(_mysql_access_cfg privileges backup_lock_tables)}"

export MYSQL_ACCESS_RUNTIME_USERNAME_ENV MYSQL_ACCESS_RUNTIME_PASSWORD_ENV \
       MYSQL_ACCESS_MIGRATOR_USERNAME_ENV MYSQL_ACCESS_MIGRATOR_PASSWORD_ENV \
       MYSQL_ACCESS_DBA_USERNAME_ENV MYSQL_ACCESS_DBA_PASSWORD_ENV \
       MYSQL_ACCESS_BACKUP_USERNAME_ENV MYSQL_ACCESS_BACKUP_PASSWORD_ENV \
       MYSQL_ACCESS_RUNTIME_TEMP_TABLES_ENV MYSQL_ACCESS_ACCOUNT_HOST_ENV \
       MYSQL_ACCESS_AUTH_PLUGIN_ENV MYSQL_ACCESS_BACKUP_MODE_ENV \
       MYSQL_ACCESS_BACKUP_INCLUDE_ROUTINES_ENV MYSQL_ACCESS_BACKUP_INCLUDE_EVENTS_ENV \
       MYSQL_ACCESS_BACKUP_REQUIRE_LOCK_TABLES_ENV \
       MYSQL_ACCESS_RUNTIME_DATABASE_PRIVILEGES MYSQL_ACCESS_RUNTIME_TEMP_TABLE_PRIVILEGES \
       MYSQL_ACCESS_MIGRATOR_DATABASE_PRIVILEGES MYSQL_ACCESS_BACKUP_DATABASE_PRIVILEGES \
       MYSQL_ACCESS_BACKUP_EVENTS_PRIVILEGES MYSQL_ACCESS_BACKUP_LOCK_TABLES_PRIVILEGES

unset MYSQL_ACCESS_DEPLOY_DIR MYSQL_ACCESS_POLICY_FILE
unset -f _mysql_access_cfg
