#!/usr/bin/env bash
# Feature: Input Validation
# Validates user inputs against allowed values


FEATURE_NAME="input-validation"

#######################################
# Check if feature is enabled
#######################################
input_validation_is_enabled() {
    local enabled=$(get_config "features.${FEATURE_NAME}.enabled" 2>/dev/null || echo "true")
    [[ "$enabled" == "true" ]]
}

#######################################
# Initialize feature
#######################################
input_validation_init() {
    input_validation_is_enabled || return 0

    log "INFO" "[${FEATURE_NAME}] Initializing..."

    # Load allowed values from config
    local valid_envs=$(get_config "features.${FEATURE_NAME}.valid_environments" 2>/dev/null || echo "local,staging,production")
    local valid_acts=$(get_config "features.${FEATURE_NAME}.valid_actions" 2>/dev/null || echo "deploy,stop,logs,ps,test-db,clean,url")

    # Convert to arrays
    IFS=',' read -ra VALID_ENVIRONMENTS <<< "$valid_envs"
    IFS=',' read -ra VALID_ACTIONS <<< "$valid_acts"

    export VALID_ENVIRONMENTS VALID_ACTIONS

    log "SUCCESS" "[${FEATURE_NAME}] Initialized"
}

#######################################
# Validate configuration
#######################################
input_validation_validate() {
    input_validation_is_enabled || return 0

    # Configuration is valid if we got here
    return 0
}

#######################################
# Hook: pre-validation
# Validates environment and action inputs
#######################################
input_validation_hook_pre_validation() {
    input_validation_is_enabled || return 0

    local environment="${ENVIRONMENT:-}"
    local action="${ACTION:-deploy}"

    log "INFO" "[${FEATURE_NAME}] Validating inputs..."

    # Sanitize inputs (remove special characters)
    environment=$(echo "$environment" | tr -cd '[:alnum:]-_')
    action=$(echo "$action" | tr -cd '[:alnum:]-_')

    # Validate environment
    local valid=0
    for env in "${VALID_ENVIRONMENTS[@]}"; do
        env=$(echo "$env" | tr -d ' ')  # Trim whitespace
        [[ "$environment" == "$env" ]] && valid=1 && break
    done

    if [[ $valid -eq 0 ]]; then
        log "ERROR" "[${FEATURE_NAME}] Invalid environment: $environment"
        log "ERROR" "[${FEATURE_NAME}] Valid environments: ${VALID_ENVIRONMENTS[*]}"
        return 1
    fi

    # Validate action
    valid=0
    for act in "${VALID_ACTIONS[@]}"; do
        act=$(echo "$act" | tr -d ' ')  # Trim whitespace
        [[ "$action" == "$act" ]] && valid=1 && break
    done

    if [[ $valid -eq 0 ]]; then
        log "ERROR" "[${FEATURE_NAME}] Invalid action: $action"
        log "ERROR" "[${FEATURE_NAME}] Valid actions: ${VALID_ACTIONS[*]}"
        return 1
    fi

    log "SUCCESS" "[${FEATURE_NAME}] Input validation passed"
}

#######################################
# Cleanup
#######################################
input_validation_cleanup() {
    return 0
}
