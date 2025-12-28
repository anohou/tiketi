#!/usr/bin/env bash
# PS script - show container status

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/utils.sh"

show_status() {
    local environment="$1"

    log "INFO" "Container status for: $environment"

    ${DOCKER_COMPOSE_CMD} -f "$COMPOSE_FILE" -p "$DOCKER_COMPOSE_PROJECT" ps
}

show_status "$@"
