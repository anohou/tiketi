#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/deploy.config.sh"
source "${SCRIPT_DIR}/rbac.config.sh"
source "${SCRIPT_DIR}/../lib/postgres-app-db.sh"

TENANT_ID="${TENANT_ID:-}"
TENANT_ADMIN_EMAIL="${TENANT_ADMIN_EMAIL:-}"
TENANT_ADMIN_NAME="${TENANT_ADMIN_NAME:-}"
TENANT_ADMIN_ROLE="${TENANT_ADMIN_ROLE:-admin}"
TENANT_ADMIN_PASSWORD="${TENANT_ADMIN_PASSWORD:-}"
TENANT_SEEDER_CLASS="${TENANT_SEEDER_CLASS:-Database\\Seeders\\TenantSeeder}"
TENANT_SKIP_SEED="${TENANT_SKIP_SEED:-false}"
TENANT_SKIP_ADMIN_CREATE="${TENANT_SKIP_ADMIN_CREATE:-false}"

log() {
    echo "[repair-tenant-bootstrap] $(date '+%H:%M:%S') $*"
}

err() {
    echo "[repair-tenant-bootstrap] ERROR: $*" >&2
    exit 1
}

bool_is_true() {
    case "${1:-}" in
        true|True|TRUE|1|yes|Yes|YES|on|On|ON)
            return 0
            ;;
        *)
            return 1
            ;;
    esac
}

quote_ident() {
    local identifier="${1//\"/\"\"}"
    printf '"%s"' "${identifier}"
}

generate_password() {
    app_db_require_command openssl
    printf 'Aa1!%s' "$(openssl rand -hex 8)"
}

[[ -n "${TENANT_ID}" ]] || err "TENANT_ID is required."
[[ "${DB_CONNECTION:-pgsql}" == "pgsql" || "${DB_CONNECTION:-pgsql}" == "postgres" || "${DB_CONNECTION:-pgsql}" == "postgresql" ]] \
    || err "repair-tenant-bootstrap.sh currently supports PostgreSQL deployments only."
[[ -n "${TENANT_DB_PREFIX:-}" ]] || err "TENANT_DB_PREFIX must be configured."

tenant_db_name="${TENANT_DB_PREFIX}${TENANT_ID}"
[[ "${tenant_db_name}" =~ ^[A-Za-z0-9_-]+$ ]] || err "Derived tenant database name '${tenant_db_name}' contains unsupported characters."
[[ "${#tenant_db_name}" -le 63 ]] || err "Derived tenant database name '${tenant_db_name}' exceeds PostgreSQL's 63-byte identifier limit."

app_db_load_context "${DB_NAME:?DB_NAME must be set in config.yml (database.db_name)}"

[[ -n "${APP_DB_TENANT_PROVISIONER_USER:-}" ]] || err "DB_PROVISIONER_USERNAME must be present in ${PROJECT_ENV_PATH}."
[[ -n "${APP_DB_TENANT_PROVISIONER_PASS:-}" ]] || err "DB_PROVISIONER_PASSWORD must be present in ${PROJECT_ENV_PATH}."

tenant_id_sql="$(app_db_sql_literal "${TENANT_ID}")"
tenant_name=""
tenant_email=""
tenant_domain=""
tenant_row="$(
    app_db_psql_container_exec "${DB_NAME}" -At -F $'\t' -c "
SELECT
  COALESCE(t.name, ''),
  COALESCE(t.email, ''),
  COALESCE((
    SELECT d.domain
    FROM ${APP_DB_SCHEMA_NAME}.domains d
    WHERE d.tenant_id = t.id
    ORDER BY d.id
    LIMIT 1
  ), '')
FROM ${APP_DB_SCHEMA_NAME}.tenants t
WHERE t.id = ${tenant_id_sql}
LIMIT 1;
"
)"
[[ -n "${tenant_row}" ]] || err "Tenant '${TENANT_ID}' does not exist in ${APP_DB_SCHEMA_NAME}.tenants."
IFS=$'\t' read -r tenant_name tenant_email tenant_domain <<<"${tenant_row}"

if [[ -z "${TENANT_ADMIN_EMAIL}" ]]; then
    if [[ -n "${tenant_email}" ]]; then
        TENANT_ADMIN_EMAIL="${tenant_email}"
    else
        TENANT_ADMIN_EMAIL="admin@${TENANT_ID}.com"
    fi
fi

if [[ -z "${TENANT_ADMIN_NAME}" ]]; then
    if [[ -n "${tenant_name}" ]]; then
        TENANT_ADMIN_NAME="Admin ${tenant_name}"
    else
        TENANT_ADMIN_NAME="Admin ${TENANT_ID}"
    fi
fi

generated_admin_password=false
if ! bool_is_true "${TENANT_SKIP_ADMIN_CREATE}"; then
    if [[ -z "${TENANT_ADMIN_PASSWORD}" ]]; then
        TENANT_ADMIN_PASSWORD="$(generate_password)"
        generated_admin_password=true
    fi
fi

log "Reconciling central app database roles via provision-db.sh ..."
bash "${SCRIPT_DIR}/provision-db.sh"

tenant_db_sql="$(app_db_sql_literal "${tenant_db_name}")"
tenant_db_ident="$(quote_ident "${tenant_db_name}")"
runtime_ident="$(quote_ident "${APP_DB_RUNTIME_USER}")"
migrator_ident="$(quote_ident "${APP_DB_MIGRATOR_USER}")"
provisioner_ident="$(quote_ident "${APP_DB_TENANT_PROVISIONER_USER}")"
schema_ident="$(quote_ident "${APP_DB_SCHEMA_NAME}")"

if [[ "$(app_db_psql_container_query postgres "SELECT 1 FROM pg_database WHERE datname = ${tenant_db_sql}")" != "1" ]]; then
    log "Creating missing tenant database '${tenant_db_name}' ..."
    app_db_psql_container_exec postgres -c "CREATE DATABASE ${tenant_db_ident} WITH OWNER ${provisioner_ident} TEMPLATE template0;"
else
    log "Tenant database '${tenant_db_name}' already exists. Reapplying grants."
fi

app_db_psql_container_exec postgres <<EOF
GRANT CONNECT, TEMPORARY ON DATABASE ${tenant_db_ident} TO ${runtime_ident};
GRANT CONNECT, TEMPORARY ON DATABASE ${tenant_db_ident} TO ${migrator_ident};
GRANT CONNECT, TEMPORARY ON DATABASE ${tenant_db_ident} TO ${provisioner_ident};
EOF

app_db_psql_container_exec "${tenant_db_name}" <<EOF
CREATE SCHEMA IF NOT EXISTS ${schema_ident} AUTHORIZATION ${provisioner_ident};
GRANT USAGE, CREATE ON SCHEMA ${schema_ident} TO ${runtime_ident};
GRANT USAGE, CREATE ON SCHEMA ${schema_ident} TO ${migrator_ident};
ALTER ROLE ${runtime_ident} SET search_path = ${schema_ident};
ALTER ROLE ${migrator_ident} SET search_path = ${schema_ident};
EOF

log "Running tenant migrations for '${TENANT_ID}' ..."
ARTISAN_TTY=false "${SCRIPT_DIR}/artisan.sh" tenants:migrate \
    --tenants="${TENANT_ID}" \
    --force \
    --no-interaction

if ! bool_is_true "${TENANT_SKIP_SEED}"; then
    log "Running tenant seeder '${TENANT_SEEDER_CLASS}' for '${TENANT_ID}' ..."
    ARTISAN_TTY=false "${SCRIPT_DIR}/artisan.sh" tenants:seed \
        --tenants="${TENANT_ID}" \
        --class="${TENANT_SEEDER_CLASS}" \
        --force \
        --no-interaction
fi

if ! bool_is_true "${TENANT_SKIP_ADMIN_CREATE}"; then
    log "Creating or updating tenant admin '${TENANT_ADMIN_EMAIL}' ..."
    ARTISAN_TTY=false "${SCRIPT_DIR}/artisan.sh" tenants:run admin:create \
        --tenants="${TENANT_ID}" \
        --option="name=${TENANT_ADMIN_NAME}" \
        --option="email=${TENANT_ADMIN_EMAIL}" \
        --option="role=${TENANT_ADMIN_ROLE}" \
        --option="password=${TENANT_ADMIN_PASSWORD}" \
        --option="update=1" \
        --option="force=1" \
        --no-interaction
fi

[[ "$(app_db_psql_container_query "${tenant_db_name}" "SELECT 1 FROM pg_tables WHERE schemaname = $(app_db_sql_literal "${APP_DB_SCHEMA_NAME}") AND tablename = 'users'")" == "1" ]] \
    || err "Tenant bootstrap verification failed: users table not found in ${tenant_db_name}."
[[ "$(app_db_psql_container_query "${tenant_db_name}" "SELECT 1 FROM pg_tables WHERE schemaname = $(app_db_sql_literal "${APP_DB_SCHEMA_NAME}") AND tablename = 'sessions'")" == "1" ]] \
    || err "Tenant bootstrap verification failed: sessions table not found in ${tenant_db_name}."

if ! bool_is_true "${TENANT_SKIP_ADMIN_CREATE}"; then
    admin_email_sql="$(app_db_sql_literal "${TENANT_ADMIN_EMAIL}")"
    admin_count="$(app_db_psql_container_query "${tenant_db_name}" "SELECT COUNT(*) FROM ${schema_ident}.users WHERE email = ${admin_email_sql}")"
    [[ "${admin_count}" != "0" ]] || err "Tenant bootstrap verification failed: admin user '${TENANT_ADMIN_EMAIL}' was not created."
fi

log "Tenant bootstrap repair completed for '${TENANT_ID}'."
if [[ -n "${tenant_domain}" ]]; then
    log "Tenant domain: ${tenant_domain}"
fi
if ! bool_is_true "${TENANT_SKIP_ADMIN_CREATE}"; then
    log "Tenant admin email: ${TENANT_ADMIN_EMAIL}"
    log "Tenant admin role: ${TENANT_ADMIN_ROLE}"
    if [[ "${generated_admin_password}" == "true" ]]; then
        log "Generated tenant admin password: ${TENANT_ADMIN_PASSWORD}"
    else
        log "Tenant admin password was provided explicitly."
    fi
fi
