#!/usr/bin/env bash
# Test database connection

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/utils.sh"

test_db_connection() {
    local environment="$1"

    log "INFO" "Testing database connection for: $environment"

    if test_database "$DOCKER_CONTAINER_NAME"; then
        log "SUCCESS" "Database connection successful"
        exit 0
    else
        log "ERROR" "Database connection failed"
        exit 1
    fi
}

test_db_connection "$@"
