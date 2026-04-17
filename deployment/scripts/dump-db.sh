#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/deploy.config.sh"
source "${SCRIPT_DIR}/rbac.config.sh"
source "${SCRIPT_DIR}/../lib/postgres-app-db.sh"

APP_DB_LOG_PREFIX="dump-db"

usage() {
    cat <<'EOF'
Usage: ./scripts/dump-db.sh [--also-plain-sql]
       ./scripts/dump-db.sh --include-tenant-dbs [--also-plain-sql]

Creates a portable backup of the configured application database.
Use --include-tenant-dbs to also dump databases matching TENANT_DB_PREFIX.
EOF
}

ALSO_PLAIN_SQL=false
INCLUDE_TENANT_DBS=false

if [[ $# -eq 0 ]]; then
    usage >&2
    echo "" >&2
    echo "[dump-db] No arguments provided. Proceeding with default dump behavior." >&2
fi

while [[ $# -gt 0 ]]; do
    case "$1" in
        --also-plain-sql)
            ALSO_PLAIN_SQL=true
            shift
            ;;
        --include-tenant-dbs)
            INCLUDE_TENANT_DBS=true
            shift
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

app_db_require_command hostname
app_db_require_any_command sha256sum shasum

app_db_load_context "${DB_NAME:?DB_NAME must be set in config.yml (database.db_name)}"

[[ -n "${BACKUP_OUTPUT_DIR:-}" ]] || app_db_err "BACKUP_OUTPUT_DIR is not set in config.yml (backup.output_dir)"
[[ -n "${APP_DB_BACKUP_USER:-}" ]] || app_db_err "${RBAC_BACKUP_USERNAME_ENV} not found in ${PROJECT_ENV_PATH}"
[[ -n "${APP_DB_BACKUP_PASS:-}" ]] || app_db_err "${RBAC_BACKUP_PASSWORD_ENV} not found in ${PROJECT_ENV_PATH}"

mkdir -p "${BACKUP_OUTPUT_DIR}"

timestamp_compact="$(date -u +'%Y%m%dT%H%M%SZ')"
timestamp_utc="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
pg_dump_version="$(app_db_pg_dump_version)"
pg_restore_version="$(app_db_pg_restore_version)"
execution_host="$(hostname)"
execution_container="${POSTGRES_CONTAINER:-}"

write_checksum() {
    local archive_path="$1"
    local checksum_path="$2"

    if command -v sha256sum >/dev/null 2>&1; then
        (cd "${BACKUP_OUTPUT_DIR}" && sha256sum "$(basename "${archive_path}")" > "$(basename "${checksum_path}")")
    else
        (
            cd "${BACKUP_OUTPUT_DIR}"
            hash_value="$(shasum -a 256 "$(basename "${archive_path}")" | awk '{print $1}')"
            printf '%s  %s\n' "${hash_value}" "$(basename "${archive_path}")" > "$(basename "${checksum_path}")"
        )
    fi
}

dump_one_database() {
    local db_name="$1"
    local scope="$2"
    local artifact_base archive_path checksum_path metadata_path plain_sql_path checksum_value archive_size_bytes postgres_server_version

    artifact_base="${APP_SLUG}-${db_name}_${timestamp_compact}"
    archive_path="${BACKUP_OUTPUT_DIR}/${artifact_base}.dump"
    checksum_path="${BACKUP_OUTPUT_DIR}/${artifact_base}.sha256"
    metadata_path="${BACKUP_OUTPUT_DIR}/${artifact_base}.metadata.json"
    plain_sql_path="${BACKUP_OUTPUT_DIR}/${artifact_base}.sql"

    if [[ "${scope}" == "application_database" ]]; then
        app_db_log "Running backup-role privilege preflight for '${db_name}'..."
        app_db_backup_privilege_preflight "${db_name}" "${RBAC_SCHEMA_NAME}"
    fi

    app_db_log "Creating custom-format archive at ${archive_path}"
    app_db_pg_dump_to_file "${APP_DB_BACKUP_PASS}" "${APP_DB_BACKUP_USER}" "${db_name}" "custom" "${archive_path}"

    if ! app_db_pg_restore_list "${archive_path}" >/dev/null; then
        rm -f "${archive_path}"
        app_db_err "Archive validation failed; invalid archive removed: ${archive_path}"
    fi

    write_checksum "${archive_path}" "${checksum_path}"

    checksum_value="$(awk '{print $1}' "${checksum_path}")"
    archive_size_bytes="$(wc -c < "${archive_path}" | tr -d '[:space:]')"
    postgres_server_version="$(app_db_psql_host_query "${APP_DB_BACKUP_USER}" "${APP_DB_BACKUP_PASS}" "${db_name}" "select version()")"

    cat > "${metadata_path}" <<EOF
{
  "app_slug": "${APP_SLUG}",
  "db_name": "${db_name}",
  "timestamp_utc": "${timestamp_utc}",
  "archive_format": "custom",
  "archive_size_bytes": ${archive_size_bytes},
  "sha256": "${checksum_value}",
  "pg_dump_version": "${pg_dump_version//\"/\\\"}",
  "pg_restore_version": "${pg_restore_version//\"/\\\"}",
  "postgres_server_version": "${postgres_server_version//\"/\\\"}",
  "execution_context": {
    "host": "${execution_host//\"/\\\"}",
    "container": "${execution_container//\"/\\\"}",
    "service": ""
  },
  "backup_scope": "${scope}"
}
EOF

    if [[ "${ALSO_PLAIN_SQL}" == "true" ]]; then
        app_db_log "Creating optional plain SQL export at ${plain_sql_path}"
        app_db_pg_dump_to_file "${APP_DB_BACKUP_PASS}" "${APP_DB_BACKUP_USER}" "${db_name}" "plain" "${plain_sql_path}"
    fi

    app_db_log "Backup complete:"
    app_db_log "  archive: ${archive_path}"
    app_db_log "  checksum: ${checksum_path}"
    app_db_log "  metadata: ${metadata_path}"
    if [[ "${ALSO_PLAIN_SQL}" == "true" ]]; then
        app_db_log "  plain SQL: ${plain_sql_path}"
    fi
}

dump_one_database "${DB_NAME}" "application_database"

if [[ "${INCLUDE_TENANT_DBS}" == "true" ]]; then
    [[ -n "${TENANT_DB_PREFIX:-}" ]] || app_db_err "TENANT_DB_PREFIX is required when --include-tenant-dbs is used"
    app_db_log "Enumerating tenant databases with prefix '${TENANT_DB_PREFIX}'..."
    while IFS= read -r tenant_db; do
        [[ -n "${tenant_db}" ]] || continue
        dump_one_database "${tenant_db}" "tenant_database"
    done < <(app_db_psql_admin_query postgres "SELECT datname FROM pg_database WHERE datname LIKE $(app_db_sql_literal "${TENANT_DB_PREFIX}%") ORDER BY datname")
fi

cat <<'EOF'
WARNING:
Default backups contain ONLY the application database.
Use --include-tenant-dbs to include tenant databases.
It does NOT include PostgreSQL globals (roles, users, tablespaces).
EOF
