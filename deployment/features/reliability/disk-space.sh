#!/usr/bin/env bash
# Feature: Disk Space Checks
# Ensures sufficient disk space before deployment


FEATURE_NAME="disk-space"

#######################################
# Check if feature is enabled
#######################################
disk_space_is_enabled() {
    local enabled=$(get_config "features.${FEATURE_NAME}.enabled" 2>/dev/null || echo "false")
    [[ "$enabled" == "true" ]]
}

#######################################
# Initialize feature
#######################################
disk_space_init() {
    disk_space_is_enabled || return 0

    log "INFO" "[${FEATURE_NAME}] Initializing..."

    # Load configuration
    export MIN_FREE_MB=$(get_config "features.${FEATURE_NAME}.min_free_mb" 2>/dev/null || echo "1000")

    log "SUCCESS" "[${FEATURE_NAME}] Initialized (min_free: ${MIN_FREE_MB}MB)"
}

#######################################
# Validate configuration
#######################################
disk_space_validate() {
    disk_space_is_enabled || return 0
    return 0
}

#######################################
# Hook: pre-deploy
# Check disk space before starting containers
#######################################
disk_space_hook_pre_deploy() {
    disk_space_is_enabled || return 0

    log "INFO" "[${FEATURE_NAME}] Checking disk space..."

    # Get Docker root directory
    local docker_root=$(docker info --format '{{.DockerRootDir}}' 2>/dev/null || echo "/var/lib/docker")

    # Get available space in MB
    local available_mb
    if [[ "$OSTYPE" == "darwin"* ]]; then
        # macOS
        available_mb=$(df -m "$docker_root" 2>/dev/null | tail -1 | awk '{print $4}')
    else
        # Linux
        available_mb=$(df -BM "$docker_root" 2>/dev/null | tail -1 | awk '{print $4}' | sed 's/M//')
    fi

    # Handle case where df fails
    if [[ -z "$available_mb" ]] || [[ ! "$available_mb" =~ ^[0-9]+$ ]]; then
        log "WARNING" "[${FEATURE_NAME}] Could not determine disk space"
        return 0  # Don't fail deployment
    fi

    if [[ $available_mb -lt $MIN_FREE_MB ]]; then
        log "ERROR" "[${FEATURE_NAME}] Insufficient disk space"
        log "ERROR" "[${FEATURE_NAME}] Required: ${MIN_FREE_MB}MB"
        log "ERROR" "[${FEATURE_NAME}] Available: ${available_mb}MB"
        log "ERROR" "[${FEATURE_NAME}] Free up space with: docker system prune -a"
        return 1
    fi

    log "SUCCESS" "[${FEATURE_NAME}] Disk space OK: ${available_mb}MB available"
}

#######################################
# Cleanup
#######################################
disk_space_cleanup() {
    return 0
}
