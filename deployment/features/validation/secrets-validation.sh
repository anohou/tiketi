#!/usr/bin/env bash
# Feature: Secrets Validation
# Validates that required environment variables exist in secrets file


FEATURE_NAME="secrets-validation"

#######################################
# Check if feature is enabled
#######################################
secrets_validation_is_enabled() {
    local enabled=$(get_config "features.${FEATURE_NAME}.enabled" 2>/dev/null || echo "true")
    [[ "$enabled" == "true" ]]
}

#######################################
# Initialize feature
#######################################
secrets_validation_init() {
    secrets_validation_is_enabled || return 0

    log "INFO" "[${FEATURE_NAME}] Initializing..."

    # Load required vars from config
    local required_vars=$(get_config "features.${FEATURE_NAME}.required_vars" 2>/dev/null || \
        echo "DB_HOST,DB_DATABASE,DB_USERNAME,DB_PASSWORD,APP_KEY")

    # Convert to array
    IFS=',' read -ra REQUIRED_SECRET_VARS <<< "$required_vars"
    export REQUIRED_SECRET_VARS

    log "SUCCESS" "[${FEATURE_NAME}] Initialized (${#REQUIRED_SECRET_VARS[@]} required vars)"
}

#######################################
# Validate configuration
#######################################
secrets_validation_validate() {
    secrets_validation_is_enabled || return 0
    return 0
}

#######################################
# Hook: post-validation
# Validate secrets file after it's been merged
#######################################
secrets_validation_hook_post_validation() {
    secrets_validation_is_enabled || return 0

    local secrets_file="${SECRETS_MOUNT_FILE:-}"

    if [[ -z "$secrets_file" ]] || [[ ! -f "$secrets_file" ]]; then
        log "ERROR" "[${FEATURE_NAME}] Secrets file not found: $secrets_file"
        return 1
    fi

    log "INFO" "[${FEATURE_NAME}] Validating secrets file..."

    local missing=()
    for var in "${REQUIRED_SECRET_VARS[@]}"; do
        var=$(echo "$var" | tr -d ' ')  # Trim whitespace
        if ! grep -q "^${var}=" "$secrets_file" 2>/dev/null; then
            missing+=("$var")
        fi
    done

    if [[ ${#missing[@]} -gt 0 ]]; then
        log "ERROR" "[${FEATURE_NAME}] Missing required secrets:"
        for var in "${missing[@]}"; do
            log "ERROR" "  - $var"
        done
        log "ERROR" "[${FEATURE_NAME}] Secrets file: $secrets_file"
        return 1
    fi

    log "SUCCESS" "[${FEATURE_NAME}] All ${#REQUIRED_SECRET_VARS[@]} required secrets present"
}

#######################################
# Cleanup
#######################################
secrets_validation_cleanup() {
    return 0
}
