#!/usr/bin/env bash
# Feature Loader - Orchestrates all pluggable features


FEATURES_DIR="${DEPLOYMENT_ROOT}/features"
declare -a LOADED_FEATURES=()

#######################################
# Load all feature modules
#######################################
load_features() {
    log "INFO" "=========================================="
    log "INFO" "  LOADING FEATURE MODULES"
    log "INFO" "=========================================="

    local feature_files=(
        # Validation category features
        "validation/input-validation.sh"
        "validation/health-check.sh"
        "validation/secrets-completeness.sh"
        "validation/secrets-validation.sh"
        "validation/dependency-checks.sh"

        # Security category
        "security/image-digest.sh"
        "security/secrets-rotation.sh"
        "security/permission-checks.sh"

        # Reliability category
        "reliability/rollback.sh"
        "reliability/retry-logic.sh"
        "reliability/disk-space.sh"

        # Monitoring category
        "monitoring/audit-logging.sh"
        "monitoring/drift-detection.sh"

        # Notifications category
        "notifications/discord.sh"
        "notifications/slack.sh"
        "notifications/telegram.sh"

        # Advanced category
        "advanced/zero-downtime.sh"
    )

    for feature_file in "${feature_files[@]}"; do
        local feature_path="${FEATURES_DIR}/${feature_file}"
        local feature_name=$(basename "$feature_file" .sh)

        if [[ -f "$feature_path" ]]; then
            # Source the feature module
            source "$feature_path"

            # Check if enabled using feature-specific function
            local enabled_func="${feature_name//-/_}_is_enabled"
            if type -t "$enabled_func" &>/dev/null && "$enabled_func"; then
                log "SUCCESS" "  ✓ Loaded: ${feature_file}"
                LOADED_FEATURES+=("${feature_name//-/_}")
            else
                log "INFO" "  ⏭  Skipped: ${feature_file} (disabled)"
            fi
        else
            log "WARNING" "  ⚠  Missing: ${feature_file}"
        fi
    done

    log "INFO" "Loaded ${#LOADED_FEATURES[@]} feature(s)"
}

#######################################
# Initialize all loaded features
#######################################
init_features() {
    log "INFO" "Initializing features..."

    if [ ${#LOADED_FEATURES[@]} -eq 0 ]; then
        log "WARNING" "No features loaded to initialize"
        return 0
    fi

    for feature in "${LOADED_FEATURES[@]}"; do
        local init_func="${feature}_init"
        if type -t "$init_func" &>/dev/null; then
            log "INFO" "  → Initializing: $feature"
            "$init_func" || {
                log "ERROR" "Failed to initialize: $feature"
                return 1
            }
        fi
    done

    log "SUCCESS" "Features initialized"
}

#######################################
# Validate all feature configurations
#######################################
validate_features() {
    log "INFO" "Validating feature configurations..."

    for feature in "${LOADED_FEATURES[@]}"; do
        local validate_func="${feature}_validate"
        if type -t "$validate_func" &>/dev/null; then
            "$validate_func" || {
                log "ERROR" "Validation failed: $feature"
                return 1
            }
        fi
    done

    log "SUCCESS" "All features valid"
}

#######################################
# Execute features at specific hook point
# Arguments:
#   $1 - Hook name (pre-deploy, post-deploy, etc.)
#   $@ - Additional arguments to pass to hooks
#######################################
exec_hook() {
    local hook="$1"
    shift  # Remove hook name from arguments

    for feature in "${LOADED_FEATURES[@]}"; do
        local hook_func="${feature}_hook_${hook//-/_}"

        # Check if feature has this hook
        if type -t "$hook_func" &>/dev/null; then
            "$hook_func" "$@" || {
                log "WARNING" "Hook failed: ${feature}/${hook} (non-critical)"
            }
        fi
    done
}

#######################################
# Cleanup all features
#######################################
cleanup_features() {
    if [ ${#LOADED_FEATURES[@]} -eq 0 ]; then
        return 0
    fi

    for feature in "${LOADED_FEATURES[@]}"; do
        local cleanup_func="${feature}_cleanup"
        if type -t "$cleanup_func" &>/dev/null; then
            "$cleanup_func" 2>/dev/null || true
        fi
    done
}

# Trap cleanup on exit
trap cleanup_features EXIT
