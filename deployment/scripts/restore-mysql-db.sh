#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/deploy.config.sh"
source "${SCRIPT_DIR}/mysql-access.config.sh"
source "${SCRIPT_DIR}/../lib/mysql-app-db.sh"

MYSQL_APP_DB_LOG_PREFIX="restore-mysql-db"

archive_path=""
target_db=""
force_recreate=false
restore_scope=""
tenant_db=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --archive)
            archive_path="$2"
            shift 2
            ;;
        --target-db)
            target_db="$2"
            shift 2
            ;;
        --force-recreate)
            force_recreate=true
            shift
            ;;
        --central-only)
            restore_scope="central"
            shift
            ;;
        --tenant-db)
            restore_scope="tenant"
            tenant_db="$2"
            shift 2
            ;;
        --help|-h)
            exit 0
            ;;
        *)
            mysql_app_err "Unknown argument: $1"
            ;;
    esac
done

[[ -n "${archive_path}" ]] || mysql_app_err "--archive is required"
[[ -f "${archive_path}" ]] || mysql_app_err "Archive not found: ${archive_path}"
[[ -n "${restore_scope}" ]] || mysql_app_err "Choose an explicit restore scope: --central-only or --tenant-db <name>"

mysql_app_load_context "${DB_NAME:?DB_NAME must be set in config.yml (database.db_name)}"

if [[ -z "${target_db}" ]]; then
    if [[ "${restore_scope}" == "tenant" ]]; then
        target_db="${tenant_db}_restore_$(date -u +'%Y%m%dT%H%M%SZ')"
    else
        target_db="${DB_NAME}_restore_$(date -u +'%Y%m%dT%H%M%SZ')"
    fi
fi

mysql_app_validate_db_identifier "${target_db}"
target_exists="$(mysql_app_mysql_admin_exec information_schema "SELECT 1 FROM SCHEMATA WHERE SCHEMA_NAME = $(mysql_app_sql_literal "${target_db}") LIMIT 1;" || true)"
if [[ "${target_exists}" == "1" && "${force_recreate}" != "true" ]]; then
    mysql_app_err "Target database '${target_db}' already exists. Use --force-recreate to replace it."
fi
if [[ "${force_recreate}" == "true" ]]; then
    mysql_app_mysql_admin_exec "" "DROP DATABASE IF EXISTS $(mysql_app_quote_identifier "${target_db}");"
fi

mysql_app_mysql_admin_exec "" "CREATE DATABASE IF NOT EXISTS $(mysql_app_quote_identifier "${target_db}") CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
mysql_app_restore_archive_into_db "${archive_path}" "${target_db}"
APP_DB_TARGET_DB_NAME="${target_db}"
mysql_app_configure_roles
mysql_app_verify_state
mysql_app_run_permission_probes
mysql_app_log "Restore complete into '${target_db}'."
