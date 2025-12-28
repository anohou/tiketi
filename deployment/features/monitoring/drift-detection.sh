#!/usr/bin/env bash
# Feature: Configuration Drift Detection
# Detects when running container differs from expected configuration


FEATURE_NAME="drift-detection"

#######################################
# Check if feature is enabled
#######################################
drift_detection_is_enabled() {
    local enabled=$(get_config "features.${FEATURE_NAME}.enabled" 2>/dev/null || echo "false")
    [[ "$enabled" == "true" ]]
}

#######################################
# Initialize feature
#######################################
drift_detection_init() {
    drift_detection_is_enabled || return 0

    log "INFO" "[${FEATURE_NAME}] Initialized"
}

#######################################
# Validate configuration
#######################################
drift_detection_validate() {
    drift_detection_is_enabled || return 0
    return 0
}

#######################################
# Hook: pre-deploy
# Check for configuration drift before deployment
#######################################
drift_detection_hook_pre_deploy() {
    drift_detection_is_enabled || return 0

    local container="${DOCKER_CONTAINER_NAME:-}"

    # Skip if container doesn't exist
    if [[ -z "$container" ]] || ! docker ps -q -f name="^${container}$" &>/dev/null; then
        return 0
    fi

    log "INFO" "[${FEATURE_NAME}] Checking for configuration drift..."

    local drift_found=0

    # Check environment variable
    local expected_env="${ENVIRONMENT:-}"
    local actual_env=$(docker inspect "$container" \
        --format='{{range .Config.Env}}{{println .}}{{end}}' 2>/dev/null | \
        grep '^APP_ENV=' | cut -d'=' -f2 || echo "")

    if [[ -n "$expected_env" ]] && [[ -n "$actual_env" ]] && [[ "$actual_env" != "$expected_env" ]]; then
        log "WARNING" "[${FEATURE_NAME}] ENV drift: expected=$expected_env, actual=$actual_env"
        ((drift_found++))
    fi

    # Check image
    local expected_image="${FULL_REGISTRY_PATH}:${DEPLOY_ENV}"
    local actual_image=$(docker inspect "$container" --format='{{.Config.Image}}' 2>/dev/null || echo "")

    if [[ -n "$expected_image" ]] && [[ -n "$actual_image" ]] && [[ "$actual_image" != "$expected_image" ]]; then
        log "WARNING" "[${FEATURE_NAME}] IMAGE drift: expected=$expected_image, actual=$actual_image"
        ((drift_found++))
    fi

    if [[ $drift_found -gt 0 ]]; then
        log "WARNING" "[${FEATURE_NAME}] ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
        log "WARNING" "[${FEATURE_NAME}]  CONFIGURATION DRIFT DETECTED"
        log "WARNING" "[${FEATURE_NAME}] ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
        log "WARNING" "[${FEATURE_NAME}] Running container differs from expected configuration"
        log "WARNING" "[${FEATURE_NAME}] Issues found: $drift_found"
        log "WARNING" "[${FEATURE_NAME}] This deployment will sync configuration"
        log "WARNING" "[${FEATURE_NAME}] ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    else
        log "SUCCESS" "[${FEATURE_NAME}] No configuration drift detected"
    fi

    return 0  # Don't fail deployment, just warn
}

#######################################
# Cleanup
#######################################
drift_detection_cleanup() {
    return 0
}
