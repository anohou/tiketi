#!/usr/bin/env bash
# Feature: Permission Checks
# Validates file permissions for security


FEATURE_NAME="permission-checks"

#######################################
# Check if feature is enabled
#######################################
permission_checks_is_enabled() {
    local enabled=$(get_config "features.${FEATURE_NAME}.enabled" 2>/dev/null || echo "false")
    [[ "$enabled" == "true" ]]
}

#######################################
# Initialize feature
#######################################
permission_checks_init() {
    permission_checks_is_enabled || return 0

    log "INFO" "[${FEATURE_NAME}] Initializing..."

    # Load configuration
    export PERMISSION_MODE=$(get_config "features.${FEATURE_NAME}.mode" 2>/dev/null || echo "warning")
    export SECRETS_PERMISSION=$(get_config "features.${FEATURE_NAME}.secrets_mode" 2>/dev/null || echo "600")
    export SCRIPTS_PERMISSION=$(get_config "features.${FEATURE_NAME}.scripts_mode" 2>/dev/null || echo "755")

    log "SUCCESS" "[${FEATURE_NAME}] Initialized (mode: $PERMISSION_MODE)"
}

#######################################
# Validate configuration
#######################################
permission_checks_validate() {
    permission_checks_is_enabled || return 0

    if [[ "$PERMISSION_MODE" != "warning" ]] && [[ "$PERMISSION_MODE" != "error" ]]; then
        log "ERROR" "[${FEATURE_NAME}] Invalid mode: $PERMISSION_MODE (must be 'warning' or 'error')"
        return 1
    fi

    return 0
}

#######################################
# Hook: pre-validation
# Check file permissions before deployment
#######################################
permission_checks_hook_pre_validation() {
    permission_checks_is_enabled || return 0

    log "INFO" "[${FEATURE_NAME}] Checking file permissions..."

    local issues=0

    # Check secrets file permissions
    local secrets_files=(
        "deployment/config/local-secrets.env"
        "${SECRETS_MOUNT_FILE:-}"
    )

    for secrets_file in "${secrets_files[@]}"; do
        if [[ -z "$secrets_file" ]] || [[ ! -f "$secrets_file" ]]; then
            continue
        fi

        local perms
        if [[ "$OSTYPE" == "darwin"* ]]; then
            perms=$(stat -f "%A" "$secrets_file" 2>/dev/null || echo "")
        else
            perms=$(stat -c "%a" "$secrets_file" 2>/dev/null || echo "")
        fi

        if [[ -n "$perms" ]] && [[ "$perms" != "$SECRETS_PERMISSION" ]] && [[ "$perms" != "400" ]]; then
            log "WARNING" "[${FEATURE_NAME}] ⚠️  Secrets file has insecure permissions: $perms"
            log "WARNING" "[${FEATURE_NAME}]   File: $secrets_file"
            log "WARNING" "[${FEATURE_NAME}]   Recommended: $SECRETS_PERMISSION (owner read/write only)"
            log "WARNING" "[${FEATURE_NAME}]   Fix: chmod $SECRETS_PERMISSION $secrets_file"
            ((issues++))
        fi
    done

    # Check script permissions
    local scripts=(
        deployment/deploy.sh
        deployment/scripts/*.sh
        deployment/features/**/*.sh
    )

    for script_pattern in "${scripts[@]}"; do
        for script in $script_pattern; do
            if [[ -f "$script" ]]; then
                if [[ ! -x "$script" ]]; then
                    log "WARNING" "[${FEATURE_NAME}] ⚠️  Script not executable: $script"
                    log "WARNING" "[${FEATURE_NAME}]   Fix: chmod +x $script"
                    ((issues++))
                fi
            fi
        done
    done

    if [[ $issues -eq 0 ]]; then
        log "SUCCESS" "[${FEATURE_NAME}] All file permissions OK"
    else
        local message="Found $issues permission issue(s)"

        if [[ "$PERMISSION_MODE" == "error" ]]; then
            log "ERROR" "[${FEATURE_NAME}] $message - deployment blocked"
            return 1
        else
            log "WARNING" "[${FEATURE_NAME}] $message (non-critical, deployment will continue)"
        fi
    fi

    return 0
}

#######################################
# Cleanup
#######################################
permission_checks_cleanup() {
    return 0
}
