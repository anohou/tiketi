#!/usr/bin/env bash
# Clean script - remove containers and images

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/utils.sh"

clean() {
    local environment="$1"

    log "WARNING" "========================================"
    log "WARNING" "  CLEANUP OPERATION FOR: $environment"
    log "WARNING" "========================================"
    echo ""
    log "INFO" "This will DELETE the following:"
    echo "  ✗ Container: ${DOCKER_CONTAINER_NAME}"
    echo "  ✗ Image: ${DOCKER_IMAGE_NAME}"
    echo "  ✗ Docker volumes (if any)"
    echo "  ✗ Docker networks created by this deployment"
    echo ""
    log "INFO" "This will NOT affect:"
    echo "  ✓ External databases (MySQL, PostgreSQL, etc.)"
    echo "  ✓ Files outside Docker containers"
    echo "  ✓ Server configuration"
    echo ""

    # Require confirmation
    read -p "Are you sure you want to continue? Type 'yes' to confirm: " -r
    echo
    if [[ ! $REPLY =~ ^yes$ ]]; then
        log "INFO" "Cleanup cancelled"
        exit 0
    fi

    log "INFO" "Cleaning up for: $environment"

    # Stop and remove containers
    ${DOCKER_COMPOSE_CMD} -f "$COMPOSE_FILE" -p "$DOCKER_COMPOSE_PROJECT" down -v || true

    #  Remove local image if exists
    if docker images | grep -q "$DOCKER_IMAGE_NAME"; then
        log "INFO" "Removing local image: $DOCKER_IMAGE_NAME"
        docker rmi "$DOCKER_IMAGE_NAME" || true
    fi

    log "SUCCESS" "Cleanup completed"
}

clean "$@"
