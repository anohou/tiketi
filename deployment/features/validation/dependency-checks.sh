#!/usr/bin/env bash
# Feature: Dependency Checks
# Validates minimum versions of Docker and Docker Compose


FEATURE_NAME="dependency-checks"

#######################################
# Check if feature is enabled
#######################################
dependency_checks_is_enabled() {
    local enabled=$(get_config "features.${FEATURE_NAME}.enabled" 2>/dev/null || echo "false")
    [[ "$enabled" == "true" ]]
}

#######################################
# Initialize feature
#######################################
dependency_checks_init() {
    dependency_checks_is_enabled || return 0

    log "INFO" "[${FEATURE_NAME}] Initializing..."

    # Load version requirements
    export MIN_DOCKER_VERSION=$(get_config "features.${FEATURE_NAME}.docker.min_version" 2>/dev/null || echo "20.10.0")
    export MIN_COMPOSE_VERSION=$(get_config "features.${FEATURE_NAME}.docker_compose.min_version" 2>/dev/null || echo "2.0.0")

    log "SUCCESS" "[${FEATURE_NAME}] Initialized"
}

#######################################
# Validate configuration
#######################################
dependency_checks_validate() {
    dependency_checks_is_enabled || return 0
    return 0
}

#######################################
# Compare versions (semver-style)
# Returns: 0 if $1 >= $2, 1 otherwise
#######################################
version_gte() {
    printf '%s\n%s' "$2" "$1" | sort -V -C
}

#######################################
# Hook: pre-validation
# Check dependency versions before deployment
#######################################
dependency_checks_hook_pre_validation() {
    dependency_checks_is_enabled || return 0

    log "INFO" "[${FEATURE_NAME}] Checking dependencies..."

    # Check Docker
    if ! command -v docker &>/dev/null; then
        log "ERROR" "[${FEATURE_NAME}] Docker not found"
        log "ERROR" "[${FEATURE_NAME}] Install Docker: https://docs.docker.com/get-docker/"
        return 1
    fi

    local docker_version=$(docker --version | grep -oP '\d+\.\d+\.\d+' | head -1)
    if ! version_gte "$docker_version" "$MIN_DOCKER_VERSION"; then
        log "ERROR" "[${FEATURE_NAME}] Docker $MIN_DOCKER_VERSION+ required (found: $docker_version)"
        log "ERROR" "[${FEATURE_NAME}] Upgrade Docker: https://docs.docker.com/engine/install/"
        return 1
    fi

    log "SUCCESS" "[${FEATURE_NAME}] Docker $docker_version ✓"

    # Check Docker Compose
    local compose_version=""
    if command -v docker compose &>/dev/null; then
        compose_version=$(docker compose version --short 2>/dev/null || echo "0.0.0")
    elif command -v docker-compose &>/dev/null; then
        compose_version=$(docker-compose --version 2>/dev/null | grep -oP '\d+\.\d+\.\d+' | head -1)
    else
        log "ERROR" "[${FEATURE_NAME}] Docker Compose not found"
        log "ERROR" "[${FEATURE_NAME}] Install: https://docs.docker.com/compose/install/"
        return 1
    fi

    if ! version_gte "$compose_version" "$MIN_COMPOSE_VERSION"; then
        log "WARNING" "[${FEATURE_NAME}] Docker Compose $MIN_COMPOSE_VERSION+ recommended (found: $compose_version)"
        log "WARNING" "[${FEATURE_NAME}] Some features may not work correctly"
    else
        log "SUCCESS" "[${FEATURE_NAME}] Docker Compose $compose_version ✓"
    fi

    log "SUCCESS" "[${FEATURE_NAME}] All dependencies OK"
}

#######################################
# Cleanup
#######################################
dependency_checks_cleanup() {
    return 0
}
