#!/usr/bin/env bash
# Stop script - stops running containers

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/utils.sh"

stop() {
    local environment="$1"

    log "INFO" "Stopping containers for: $environment"

    ${DOCKER_COMPOSE_CMD} -f "${COMPOSE_FILE}" -p "${PROJECT_NAME}" down || {
        log "ERROR" "Failed to stop containers"
        exit 1
    }

    log "SUCCESS" "Containers stopped"
}

stop "$@"
