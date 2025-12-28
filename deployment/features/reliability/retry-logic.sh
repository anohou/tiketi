#!/usr/bin/env bash
# Feature: Retry Logic with Backoff
# Provides exponential backoff retry mechanism for operations


FEATURE_NAME="retry-logic"

#######################################
# Check if feature is enabled
#######################################
retry_logic_is_enabled() {
    local enabled=$(get_config "features.${FEATURE_NAME}.enabled" 2>/dev/null || echo "false")
    [[ "$enabled" == "true" ]]
}

#######################################
# Initialize feature
#######################################
retry_logic_init() {
    retry_logic_is_enabled || return 0

    log "INFO" "[${FEATURE_NAME}] Initializing..."

    # Load retry configuration
    export RETRY_MAX_ATTEMPTS=$(get_config "features.${FEATURE_NAME}.max_attempts" 2>/dev/null || echo "5")
    export RETRY_INITIAL_TIMEOUT=$(get_config "features.${FEATURE_NAME}.initial_timeout" 2>/dev/null || echo "1")
    export RETRY_MAX_TIMEOUT=$(get_config "features.${FEATURE_NAME}.max_timeout" 2>/dev/null || echo "32")

    log "SUCCESS" "[${FEATURE_NAME}] Initialized (max_attempts: $RETRY_MAX_ATTEMPTS)"
}

#######################################
# Validate configuration
#######################################
retry_logic_validate() {
    retry_logic_is_enabled || return 0

    # Validate numeric values
    if [[ ! "$RETRY_MAX_ATTEMPTS" =~ ^[0-9]+$ ]] || [[ $RETRY_MAX_ATTEMPTS -lt 1 ]]; then
        log "ERROR" "[${FEATURE_NAME}] Invalid max_attempts: $RETRY_MAX_ATTEMPTS"
        return 1
    fi

    return 0
}

#######################################
# Retry with exponential backoff
# Arguments:
#   $1 - Command to execute
#   $2 - Max attempts (optional, uses config default)
# Returns: 0 on success, 1 on failure
#######################################
retry_with_backoff() {
    retry_logic_is_enabled || {
        # If disabled, just run command once
        eval "$1"
        return $?
    }

    local command="$1"
    local max_attempts="${2:-$RETRY_MAX_ATTEMPTS}"
    local timeout=$RETRY_INITIAL_TIMEOUT
    local attempt=1

    while [ $attempt -le $max_attempts ]; do
        log "INFO" "[${FEATURE_NAME}] Attempt $attempt/$max_attempts..."

        if eval "$command"; then
            log "SUCCESS" "[${FEATURE_NAME}] Command succeeded on attempt $attempt"
            return 0
        fi

        if [ $attempt -eq $max_attempts ]; then
            log "ERROR" "[${FEATURE_NAME}] All $max_attempts attempts failed"
            return 1
        fi

        # Exponential backoff: 1s, 2s, 4s, 8s, 16s, 32s...
        log "WARNING" "[${FEATURE_NAME}] Attempt failed, retrying in ${timeout}s..."
        sleep $timeout

        # Double timeout for next attempt (with cap)
        timeout=$((timeout * 2))
        if [ $timeout -gt $RETRY_MAX_TIMEOUT ]; then
            timeout=$RETRY_MAX_TIMEOUT
        fi

        attempt=$((attempt + 1))
    done

    return 1
}

#######################################
# Export function for use in deployment
#######################################

#######################################
# Cleanup
#######################################
retry_logic_cleanup() {
    return 0
}
