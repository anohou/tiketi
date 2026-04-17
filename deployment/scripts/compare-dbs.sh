#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/deploy.config.sh"
source "${SCRIPT_DIR}/rbac.config.sh"
source "${SCRIPT_DIR}/../lib/postgres-app-db.sh"

APP_DB_LOG_PREFIX="compare-dbs"

KEY_TABLES=(
    migrations
    users
    posts
    categories
    items
    item_categories
    item_elements
    text_widgets
    comments
    post_views
    upvote_downvotes
)

usage() {
    cat <<'EOF'
Usage:
  ./scripts/compare-dbs.sh --source-db <name> --target-db <name>
  ./scripts/compare-dbs.sh --restore-validation --target-db <name>
  ./scripts/compare-dbs.sh --list-checks
  ./scripts/compare-dbs.sh --help
EOF
}

list_checks() {
    cat <<'EOF'
Built-in comparison checks:
  1. Source database exists
  2. Target database exists
  3. Schema exists in both databases
  4. Schema table inventory count matches
  5. Full schema table inventory matches exactly
  6. Exact app.migrations contents match when the table exists in both databases
  7. Exact key-table schema shape matches when the table exists in both databases
  8. Built-in key-table row counts match when the table exists in both databases
  9. Missing non-migration key tables are reported as SKIP
  10. Runtime role can log into the target database
  11. Runtime role search_path matches the configured schema
  12. Runtime role can read app.migrations on the target database
  13. Backup role can log into the target database
  14. Backup role pg_dump smoke check succeeds against the target database
EOF
}

source_db=""
target_db=""
schema_name=""
restore_validation=false
list_checks_only=false

if [[ $# -eq 0 ]]; then
    usage >&2
    echo "" >&2
    app_db_err "No arguments provided. Use --help to see usage."
fi

while [[ $# -gt 0 ]]; do
    case "$1" in
        --source-db)
            [[ $# -ge 2 ]] || app_db_err "--source-db requires a value"
            source_db="$2"
            shift 2
            ;;
        --target-db)
            [[ $# -ge 2 ]] || app_db_err "--target-db requires a value"
            target_db="$2"
            shift 2
            ;;
        --schema)
            [[ $# -ge 2 ]] || app_db_err "--schema requires a value"
            schema_name="$2"
            shift 2
            ;;
        --restore-validation)
            restore_validation=true
            shift
            ;;
        --list-checks)
            list_checks_only=true
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

if [[ "${list_checks_only}" == "true" ]]; then
    [[ -z "${source_db}" ]] || app_db_err "--list-checks cannot be combined with --source-db"
    [[ -z "${target_db}" ]] || app_db_err "--list-checks cannot be combined with --target-db"
    [[ -z "${schema_name}" ]] || app_db_err "--list-checks cannot be combined with --schema"
    [[ "${restore_validation}" == "false" ]] || app_db_err "--list-checks cannot be combined with --restore-validation"
    list_checks
    exit 0
fi

app_db_load_context "${DB_NAME:?DB_NAME must be set in config.yml (database.db_name)}"

schema_name="${schema_name:-${RBAC_SCHEMA_NAME}}"
if [[ "${restore_validation}" == "true" ]]; then
    source_db="${source_db:-${DB_NAME}}"
fi

[[ -n "${source_db}" ]] || app_db_err "--source-db is required unless --restore-validation is used"
[[ -n "${target_db}" ]] || app_db_err "--target-db is required"

required_failures=0
skipped_checks=0
passed_checks=0

pass_check() {
    passed_checks=$((passed_checks + 1))
    app_db_log "PASS: $*"
}

skip_check() {
    skipped_checks=$((skipped_checks + 1))
    app_db_log "SKIP: $*"
}

fail_check() {
    required_failures=$((required_failures + 1))
    app_db_log "FAIL: $*"
}

db_exists() {
    local dbname="$1"
    [[ "$(app_db_psql_admin_query postgres "SELECT 1 FROM pg_database WHERE datname = $(app_db_sql_literal "${dbname}")")" == "1" ]]
}

schema_exists() {
    local dbname="$1"
    local schema="$2"
    [[ "$(app_db_psql_admin_query "${dbname}" "SELECT 1 FROM pg_namespace WHERE nspname = $(app_db_sql_literal "${schema}")")" == "1" ]]
}

schema_table_count() {
    local dbname="$1"
    local schema="$2"
    app_db_psql_admin_query "${dbname}" "
SELECT COUNT(*)
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = $(app_db_sql_literal "${schema}")
  AND c.relkind IN ('r', 'p')
"
}

schema_table_inventory() {
    local dbname="$1"
    local schema="$2"
    app_db_psql_admin_query "${dbname}" "
SELECT COALESCE(string_agg(c.relname, E'\n' ORDER BY c.relname), '')
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = $(app_db_sql_literal "${schema}")
  AND c.relkind IN ('r', 'p')
"
}

table_exists() {
    local dbname="$1"
    local schema="$2"
    local table_name="$3"
    [[ "$(app_db_psql_admin_query "${dbname}" "
SELECT 1
FROM information_schema.tables
WHERE table_schema = $(app_db_sql_literal "${schema}")
  AND table_name = $(app_db_sql_literal "${table_name}")
LIMIT 1
")" == "1" ]]
}

table_row_count() {
    local dbname="$1"
    local schema="$2"
    local table_name="$3"
    app_db_psql_admin_query "${dbname}" "SELECT COUNT(*) FROM \"${schema}\".\"${table_name}\""
}

migrations_fingerprint() {
    local dbname="$1"
    local schema="$2"
    app_db_psql_admin_query "${dbname}" "
SELECT COALESCE(string_agg(format('%s|%s', migration, batch), E'\n' ORDER BY migration, batch), '')
FROM \"${schema}\".\"migrations\"
"
}

table_schema_fingerprint() {
    local dbname="$1"
    local schema="$2"
    local table_name="$3"
    app_db_psql_admin_query "${dbname}" "
SELECT COALESCE(
    string_agg(
        format(
            '%s|%s|%s|%s|%s',
            ordinal_position,
            column_name,
            pg_catalog.format_type(a.atttypid, a.atttypmod),
            is_nullable,
            COALESCE(pg_get_expr(ad.adbin, ad.adrelid), '')
        ),
        E'\n' ORDER BY ordinal_position
    ),
    ''
)
FROM information_schema.columns c
JOIN pg_catalog.pg_namespace n
  ON n.nspname = c.table_schema
JOIN pg_catalog.pg_class cls
  ON cls.relname = c.table_name
 AND cls.relnamespace = n.oid
JOIN pg_catalog.pg_attribute a
  ON a.attrelid = cls.oid
 AND a.attname = c.column_name
 AND a.attnum > 0
 AND NOT a.attisdropped
LEFT JOIN pg_catalog.pg_attrdef ad
  ON ad.adrelid = cls.oid
 AND ad.adnum = a.attnum
WHERE c.table_schema = $(app_db_sql_literal "${schema}")
  AND c.table_name = $(app_db_sql_literal "${table_name}")
"
}

line_count_for_payload() {
    local payload="$1"
    if [[ -z "${payload}" ]]; then
        echo "0"
    else
        printf '%s\n' "${payload}" | wc -l | tr -d '[:space:]'
    fi
}

inventory_difference() {
    local left_payload="$1"
    local right_payload="$2"
    comm -23 \
        <(printf '%s\n' "${left_payload}" | sed '/^$/d' | sort) \
        <(printf '%s\n' "${right_payload}" | sed '/^$/d' | sort) \
        | paste -sd ', ' -
}

if db_exists "${source_db}"; then
    pass_check "Source database '${source_db}' exists"
else
    fail_check "Source database '${source_db}' does not exist"
fi

if db_exists "${target_db}"; then
    pass_check "Target database '${target_db}' exists"
else
    fail_check "Target database '${target_db}' does not exist"
fi

if [[ "${required_failures}" -eq 0 ]]; then
    if schema_exists "${source_db}" "${schema_name}"; then
        pass_check "Schema '${schema_name}' exists in source database '${source_db}'"
    else
        fail_check "Schema '${schema_name}' does not exist in source database '${source_db}'"
    fi

    if schema_exists "${target_db}" "${schema_name}"; then
        pass_check "Schema '${schema_name}' exists in target database '${target_db}'"
    else
        fail_check "Schema '${schema_name}' does not exist in target database '${target_db}'"
    fi
fi

if [[ "${required_failures}" -eq 0 ]]; then
    source_table_count="$(schema_table_count "${source_db}" "${schema_name}")"
    target_table_count="$(schema_table_count "${target_db}" "${schema_name}")"
    if [[ "${source_table_count}" == "${target_table_count}" ]]; then
        pass_check "Schema table count matches (${source_table_count})"
    else
        fail_check "Schema table count mismatch: source=${source_table_count}, target=${target_table_count}"
    fi

    source_inventory="$(schema_table_inventory "${source_db}" "${schema_name}")"
    target_inventory="$(schema_table_inventory "${target_db}" "${schema_name}")"
    if [[ "${source_inventory}" == "${target_inventory}" ]]; then
        pass_check "Schema table inventory matches exactly"
    else
        source_only_tables="$(inventory_difference "${source_inventory}" "${target_inventory}")"
        target_only_tables="$(inventory_difference "${target_inventory}" "${source_inventory}")"
        fail_check "Schema table inventory mismatch: source_only=[${source_only_tables:-none}] target_only=[${target_only_tables:-none}]"
    fi

    if table_exists "${source_db}" "${schema_name}" "migrations" && table_exists "${target_db}" "${schema_name}" "migrations"; then
        source_migrations="$(migrations_fingerprint "${source_db}" "${schema_name}")"
        target_migrations="$(migrations_fingerprint "${target_db}" "${schema_name}")"
        if [[ "${source_migrations}" == "${target_migrations}" ]]; then
            pass_check "Exact migration set matches ($(line_count_for_payload "${source_migrations}") entries)"
        else
            fail_check "Exact migration set mismatch between '${source_db}' and '${target_db}'"
        fi
    else
        fail_check "Migration table '${schema_name}.migrations' is not present in both databases"
    fi

    for table_name in "${KEY_TABLES[@]}"; do
        if [[ "${table_name}" == "migrations" ]]; then
            continue
        fi
        source_has_table=false
        target_has_table=false

        if table_exists "${source_db}" "${schema_name}" "${table_name}"; then
            source_has_table=true
        fi
        if table_exists "${target_db}" "${schema_name}" "${table_name}"; then
            target_has_table=true
        fi

        if [[ "${source_has_table}" != "true" || "${target_has_table}" != "true" ]]; then
            skip_check "Key table '${schema_name}.${table_name}' is not present in both databases"
            continue
        fi

        source_schema_shape="$(table_schema_fingerprint "${source_db}" "${schema_name}" "${table_name}")"
        target_schema_shape="$(table_schema_fingerprint "${target_db}" "${schema_name}" "${table_name}")"
        if [[ "${source_schema_shape}" == "${target_schema_shape}" ]]; then
            pass_check "Schema shape matches for '${schema_name}.${table_name}'"
        else
            fail_check "Schema shape mismatch for '${schema_name}.${table_name}'"
        fi

        source_rows="$(table_row_count "${source_db}" "${schema_name}" "${table_name}")"
        target_rows="$(table_row_count "${target_db}" "${schema_name}" "${table_name}")"
        if [[ "${source_rows}" == "${target_rows}" ]]; then
            pass_check "Row count matches for '${schema_name}.${table_name}' (${source_rows})"
        else
            fail_check "Row count mismatch for '${schema_name}.${table_name}': source=${source_rows}, target=${target_rows}"
        fi
    done
fi

if [[ "${required_failures}" -eq 0 ]]; then
    runtime_search_path=""
    if runtime_search_path="$(app_db_psql_host_query "${APP_DB_RUNTIME_USER}" "${APP_DB_RUNTIME_PASS}" "${target_db}" "show search_path" 2>/dev/null)"; then
        if [[ "${runtime_search_path}" == "${schema_name}" ]]; then
            pass_check "Runtime role search_path on '${target_db}' is '${schema_name}'"
        else
            fail_check "Runtime role search_path mismatch on '${target_db}': ${runtime_search_path}"
        fi
    else
        fail_check "Runtime role could not log into '${target_db}' to read search_path"
    fi

    runtime_migrations_count=""
    if runtime_migrations_count="$(app_db_psql_host_query "${APP_DB_RUNTIME_USER}" "${APP_DB_RUNTIME_PASS}" "${target_db}" "SELECT COUNT(*) FROM \"${schema_name}\".\"migrations\"" 2>/dev/null)"; then
        pass_check "Runtime role can read '${schema_name}.migrations' on '${target_db}' (${runtime_migrations_count} rows)"
    else
        fail_check "Runtime role could not read '${schema_name}.migrations' on '${target_db}'"
    fi

    backup_version_output=""
    if backup_version_output="$(app_db_psql_host_query "${APP_DB_BACKUP_USER}" "${APP_DB_BACKUP_PASS}" "${target_db}" "select version()" 2>/dev/null)"; then
        [[ -n "${backup_version_output}" ]] && pass_check "Backup role can log into '${target_db}'"
    else
        fail_check "Backup role could not log into '${target_db}'"
    fi

    if app_db_pg_dump_to_file "${APP_DB_BACKUP_PASS}" "${APP_DB_BACKUP_USER}" "${target_db}" "custom" "/dev/null" >/dev/null 2>&1; then
        pass_check "Backup role pg_dump smoke check succeeded for '${target_db}'"
    else
        fail_check "Backup role pg_dump smoke check failed for '${target_db}'"
    fi
fi

app_db_log "Summary: source=${source_db} target=${target_db} passed=${passed_checks} skipped=${skipped_checks} failed=${required_failures}"

if [[ "${required_failures}" -ne 0 ]]; then
    exit 1
fi
