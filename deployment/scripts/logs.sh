#!/usr/bin/env bash
# Logs script - view container logs

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/utils.sh"

show_logs() {
    local environment="$1"
    shift || true

    log "INFO" "Viewing logs for: $DOCKER_CONTAINER_NAME"

    docker logs "$DOCKER_CONTAINER_NAME" "$@"
}

show_logs "$@"
