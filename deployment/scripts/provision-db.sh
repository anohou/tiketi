#!/usr/bin/env bash
# ╔══════════════════════════════════════════════════════════════════════════════╗
# ║  provision-db.sh — Idempotent Application Database Provisioner              ║
# ║                                                                             ║
# ║  Creates, or ensures the existence of, this application's database and     ║
# ║  its isolated roles inside the shared Postgres instance.                    ║
# ║                                                                             ║
# ║  Safe to run on every deploy — all SQL operations are idempotent.          ║
# ╚══════════════════════════════════════════════════════════════════════════════╝
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/deploy.config.sh"
source "${SCRIPT_DIR}/rbac.config.sh"
source "${SCRIPT_DIR}/../lib/postgres-app-db.sh"

APP_DB_LOG_PREFIX="provision-db"

app_db_require_command docker
app_db_load_context "${DB_NAME:?DB_NAME must be set in config.yml (database.db_name)}"

app_db_log "Provisioning database '${APP_DB_TARGET_DB_NAME}' in container '${POSTGRES_CONTAINER}'"
app_db_log "  schema: ${APP_DB_SCHEMA_NAME} | owner: ${APP_DB_OWNER_ROLE}"

app_db_ensure_database_exists "${APP_DB_TARGET_DB_NAME}"
app_db_configure_roles "${APP_DB_TARGET_DB_NAME}"
app_db_apply_schema_and_grants "${APP_DB_TARGET_DB_NAME}"
app_db_log "Verifying provisioning in Postgres..."
app_db_verify_state "${APP_DB_TARGET_DB_NAME}"

app_db_log "✓ Database '${APP_DB_TARGET_DB_NAME}' provisioned and fully verified."
