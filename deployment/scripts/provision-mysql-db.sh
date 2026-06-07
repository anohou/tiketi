#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/deploy.config.sh"
source "${SCRIPT_DIR}/mysql-access.config.sh"
source "${SCRIPT_DIR}/../lib/mysql-app-db.sh"

MYSQL_APP_DB_LOG_PREFIX="provision-mysql-db"

mysql_app_load_context "${DB_NAME:?DB_NAME must be set in config.yml (database.db_name)}"
mysql_app_log "Provisioning MySQL database '${APP_DB_TARGET_DB_NAME}' in container '${MYSQL_CONTAINER}'"
mysql_app_reapply_and_verify
mysql_app_log "✓ Database '${APP_DB_TARGET_DB_NAME}' provisioned and verified."
