#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/deploy.config.sh"
source "${SCRIPT_DIR}/mysql-access.config.sh"
source "${SCRIPT_DIR}/../lib/mysql-app-db.sh"

MYSQL_APP_DB_LOG_PREFIX="dump-mysql-db"

usage() {
    cat <<'EOF'
Usage: ./scripts/dump-db.sh [--include-tenant-dbs]

Creates a gzip-compressed logical backup of the configured MySQL application database.
Use --include-tenant-dbs to also dump databases matching TENANT_DB_PREFIX.
EOF
}

INCLUDE_TENANT_DBS=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --include-tenant-dbs)
            INCLUDE_TENANT_DBS=true
            shift
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            mysql_app_err "Unknown argument: $1"
            ;;
    esac
done

mysql_app_require_command hostname
mysql_app_require_command shasum
mysql_app_load_context "${DB_NAME:?DB_NAME must be set in config.yml (database.db_name)}"
[[ -n "${BACKUP_OUTPUT_DIR:-}" ]] || mysql_app_err "BACKUP_OUTPUT_DIR is not set in config.yml (backup.output_dir)"
[[ -n "${APP_DB_BACKUP_USER:-}" ]] || mysql_app_err "${MYSQL_ACCESS_BACKUP_USERNAME_ENV} not found in ${PROJECT_ENV_PATH}"
[[ -n "${APP_DB_BACKUP_PASS:-}" ]] || mysql_app_err "${MYSQL_ACCESS_BACKUP_PASSWORD_ENV} not found in ${PROJECT_ENV_PATH}"

mkdir -p "${BACKUP_OUTPUT_DIR}"

timestamp_compact="$(date -u +'%Y%m%dT%H%M%SZ')"
timestamp_utc="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
execution_host="$(hostname)"
execution_container="${MYSQL_CONTAINER:-}"

write_checksum() {
    local archive_path="$1"
    local checksum_path="$2"
    (cd "${BACKUP_OUTPUT_DIR}" && shasum -a 256 "$(basename "${archive_path}")" > "$(basename "${checksum_path}")")
}

dump_one_database() {
    local db_name="$1"
    local scope="$2"
    local artifact_base archive_path checksum_path metadata_path checksum_value archive_size_bytes

    artifact_base="${APP_SLUG}-${db_name}_${timestamp_compact}"
    archive_path="${BACKUP_OUTPUT_DIR}/${artifact_base}.sql.gz"
    checksum_path="${BACKUP_OUTPUT_DIR}/${artifact_base}.sha256"
    metadata_path="${BACKUP_OUTPUT_DIR}/${artifact_base}.metadata.json"

    mysql_app_mysqldump_to_file "${APP_DB_BACKUP_USER}" "${APP_DB_BACKUP_PASS}" "${db_name}" "${archive_path}"
    write_checksum "${archive_path}" "${checksum_path}"
    checksum_value="$(awk '{print $1}' "${checksum_path}")"
    archive_size_bytes="$(wc -c < "${archive_path}" | tr -d '[:space:]')"

    cat > "${metadata_path}" <<EOF
{
  "app_slug": "${APP_SLUG}",
  "db_name": "${db_name}",
  "timestamp_utc": "${timestamp_utc}",
  "archive_format": "sql.gz",
  "archive_size_bytes": ${archive_size_bytes},
  "sha256": "${checksum_value}",
  "execution_context": {
    "host": "${execution_host//\"/\\\"}",
    "container": "${execution_container//\"/\\\"}"
  },
  "backup_scope": "${scope}"
}
EOF
}

mysql_app_run_permission_probes
dump_one_database "${DB_NAME}" "application_database"

if [[ "${INCLUDE_TENANT_DBS}" == "true" ]]; then
    [[ -n "${TENANT_DB_PREFIX:-}" ]] || mysql_app_err "TENANT_DB_PREFIX is required when --include-tenant-dbs is used"
    while IFS= read -r tenant_db; do
        [[ -n "${tenant_db}" ]] || continue
        dump_one_database "${tenant_db}" "tenant_database"
    done < <(mysql_app_list_tenant_databases "${TENANT_DB_PREFIX}")
fi
