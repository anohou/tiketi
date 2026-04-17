#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/deploy.config.sh"
source "${SCRIPT_DIR}/rbac.config.sh"
source "${SCRIPT_DIR}/../lib/postgres-app-db.sh"

APP_DB_LOG_PREFIX="restore-db"

usage() {
    cat <<'EOF'
Usage:
  ./scripts/restore-db.sh --list-backups
  ./scripts/restore-db.sh --archive <file> --central-only [--target-db <name>] [--force-recreate]
  ./scripts/restore-db.sh --archive <file> --tenant-db <name> [--target-db <name>] [--force-recreate]
  ./scripts/restore-db.sh --archive <file> --central-only --in-place --confirm "I_UNDERSTAND_THIS_WILL_DESTROY_<DB_NAME>"
  ./scripts/restore-db.sh --archive <file> --tenant-db <name> --in-place --confirm "I_UNDERSTAND_THIS_WILL_DESTROY_<TENANT_DB>"
EOF
}

archive_path=""
target_db=""
force_recreate=false
in_place=false
confirm_value=""
list_backups=false
restore_scope=""
tenant_db=""

if [[ $# -eq 0 ]]; then
    usage >&2
    echo "" >&2
    app_db_err "No arguments provided. Use --help to see usage."
fi

while [[ $# -gt 0 ]]; do
    case "$1" in
        --archive)
            [[ $# -ge 2 ]] || app_db_err "--archive requires a value"
            archive_path="$2"
            shift 2
            ;;
        --target-db)
            [[ $# -ge 2 ]] || app_db_err "--target-db requires a value"
            target_db="$2"
            shift 2
            ;;
        --force-recreate)
            force_recreate=true
            shift
            ;;
        --list-backups)
            list_backups=true
            shift
            ;;
        --central-only)
            restore_scope="central"
            shift
            ;;
        --tenant-db)
            [[ $# -ge 2 ]] || app_db_err "--tenant-db requires a value"
            restore_scope="tenant"
            tenant_db="$2"
            shift 2
            ;;
        --in-place)
            in_place=true
            shift
            ;;
        --confirm)
            [[ $# -ge 2 ]] || app_db_err "--confirm requires a value"
            confirm_value="$2"
            shift 2
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            app_db_err "Unknown argument: $1"
            ;;
    esac
done

if [[ "${list_backups}" == "true" ]]; then
    [[ -z "${archive_path}" ]] || app_db_err "--list-backups cannot be combined with --archive"
    [[ -z "${target_db}" ]] || app_db_err "--list-backups cannot be combined with --target-db"
    [[ "${force_recreate}" == "false" ]] || app_db_err "--list-backups cannot be combined with --force-recreate"
    [[ "${in_place}" == "false" ]] || app_db_err "--list-backups cannot be combined with --in-place"
    [[ -z "${confirm_value}" ]] || app_db_err "--list-backups cannot be combined with --confirm"
    [[ -z "${restore_scope}" ]] || app_db_err "--list-backups cannot be combined with restore scope flags"
    [[ -n "${BACKUP_OUTPUT_DIR:-}" ]] || app_db_err "BACKUP_OUTPUT_DIR is not set in config.yml (backup.output_dir)"

    if [[ ! -d "${BACKUP_OUTPUT_DIR}" ]]; then
        app_db_err "Backup directory not found: ${BACKUP_OUTPUT_DIR}"
    fi

    app_db_log "Available backup archives in ${BACKUP_OUTPUT_DIR}:"
    if ! find "${BACKUP_OUTPUT_DIR}" -maxdepth 1 -type f -name '*.dump' | sort; then
        app_db_err "Failed to list backup archives in ${BACKUP_OUTPUT_DIR}"
    fi
    exit 0
fi

[[ -n "${archive_path}" ]] || app_db_err "--archive is required"
[[ -f "${archive_path}" ]] || app_db_err "Archive not found: ${archive_path}"
[[ -n "${restore_scope}" ]] || app_db_err "Choose an explicit restore scope: --central-only or --tenant-db <name>"

app_db_require_command hostname

app_db_load_context "${DB_NAME:?DB_NAME must be set in config.yml (database.db_name)}"

app_db_pg_restore_list "${archive_path}" >/dev/null

if [[ "${restore_scope}" == "tenant" ]]; then
    [[ -n "${tenant_db}" ]] || app_db_err "--tenant-db requires a database name"
    [[ -n "${TENANT_DB_PREFIX:-}" ]] || app_db_err "TENANT_DB_PREFIX is required for tenant restores"
    case "${tenant_db}" in
        "${TENANT_DB_PREFIX}"*)
            ;;
        *)
            app_db_err "Tenant database '${tenant_db}' must start with TENANT_DB_PREFIX '${TENANT_DB_PREFIX}'"
            ;;
    esac
fi

if [[ "${in_place}" == "true" ]]; then
    [[ -z "${target_db}" ]] || app_db_err "--target-db cannot be combined with --in-place"
    if [[ "${restore_scope}" == "tenant" ]]; then
        target_db="${tenant_db}"
    else
        target_db="${DB_NAME}"
    fi
    expected_confirmation="I_UNDERSTAND_THIS_WILL_DESTROY_${target_db}"
    [[ "${confirm_value}" == "${expected_confirmation}" ]] || app_db_err "In-place restore requires exact confirmation: ${expected_confirmation}"
else
    [[ -z "${confirm_value}" ]] || app_db_err "--confirm is only valid with --in-place"
    if [[ -z "${target_db}" ]]; then
        if [[ "${restore_scope}" == "tenant" ]]; then
            target_db="${tenant_db}_restore_$(date -u +'%Y%m%dT%H%M%SZ')"
        else
            target_db="${DB_NAME}_restore_$(date -u +'%Y%m%dT%H%M%SZ')"
        fi
    fi
fi

target_exists="$(app_db_psql_admin_query postgres "SELECT 1 FROM pg_database WHERE datname = $(app_db_sql_literal "${target_db}")")"

if [[ "${in_place}" == "true" ]]; then
    app_db_log "Creating required safety backup before destructive in-place restore..."
    bash "${SCRIPT_DIR}/dump-db.sh"

    app_db_log "Terminating active connections to '${target_db}'..."
    terminate_failures="$(app_db_psql_admin_query postgres "
WITH terminated AS (
    SELECT pg_terminate_backend(pid) AS terminated
    FROM pg_stat_activity
    WHERE datname = $(app_db_sql_literal "${target_db}")
      AND pid <> pg_backend_pid()
)
SELECT COUNT(*) FROM terminated WHERE terminated IS NOT TRUE
")"
    [[ "${terminate_failures}" == "0" ]] || app_db_err "Failed to terminate all active connections to '${target_db}'"

    remaining_connections="$(app_db_psql_admin_query postgres "SELECT COUNT(*) FROM pg_stat_activity WHERE datname = $(app_db_sql_literal "${target_db}") AND pid <> pg_backend_pid()")"
    [[ "${remaining_connections}" == "0" ]] || app_db_err "Active connections remain on '${target_db}' after termination attempt"
else
    if [[ "${target_exists}" == "1" && "${force_recreate}" != "true" ]]; then
        app_db_err "Target database '${target_db}' already exists. Use --force-recreate to replace it."
    fi
fi

if [[ "${target_exists}" == "1" ]]; then
    app_db_log "Dropping existing database '${target_db}' from maintenance DB context..."
    app_db_psql_host_exec \
        "${APP_DB_ADMIN_USER}" \
        "${APP_DB_ADMIN_PASS}" \
        postgres \
        --set ON_ERROR_STOP=1 \
        -c "DROP DATABASE \"${target_db}\";"
fi

app_db_log "Creating fresh target database '${target_db}' from maintenance DB context..."
app_db_psql_host_exec \
    "${APP_DB_ADMIN_USER}" \
    "${APP_DB_ADMIN_PASS}" \
    postgres \
    --set ON_ERROR_STOP=1 \
    -c "CREATE DATABASE \"${target_db}\";"

app_db_log "Restoring archive into '${target_db}'..."
app_db_pg_restore_into_db "${APP_DB_ADMIN_PASS}" "${APP_DB_ADMIN_USER}" "${target_db}" "${archive_path}"

app_db_log "Reapplying roles, grants, default privileges, and verification..."
app_db_configure_roles "${target_db}"
app_db_apply_schema_and_grants "${target_db}"
app_db_verify_state "${target_db}"

if [[ "${in_place}" != "true" ]]; then
    app_db_log "Running automatic post-restore comparison validation for '${target_db}'..."
    if ! bash "${SCRIPT_DIR}/compare-dbs.sh" --restore-validation --target-db "${target_db}"; then
        app_db_err "Restore completed for '${target_db}', but post-restore comparison validation failed. The restored database was left in place for investigation."
    fi
fi

app_db_log "Restore complete into '${target_db}'."
