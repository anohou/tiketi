#!/bin/bash
# Feature: Secrets Completeness Validation
# Validates that all required secrets are present before deployment

FEATURE_NAME="secrets-completeness"

#######################################
# Check if feature is enabled
#######################################
secrets_completeness_is_enabled() {
    local enabled=$(get_config "features.${FEATURE_NAME}.enabled" "${ENVIRONMENT:-}")
    [ "$enabled" = "true" ]
}

#######################################
# Initialize feature
#######################################
secrets_completeness_init() {
    return 0
}

#######################################
# Pre-Build Hook: Validate Required Secrets
#######################################
secrets_completeness_hook_pre_build() {
    log "INFO" "Validating secrets completeness..."

    local secrets_file="${DEPLOYMENT_ROOT}/.env.secrets.${ENVIRONMENT}"

    if [ ! -f "$secrets_file" ]; then
        log "ERROR" "Secrets file not found: $secrets_file"
        return 1
    fi

    # Critical secrets required for deployment
    # These MUST be present in the secrets file
    local required_secrets=(
        "APP_KEY"
        "DB_HOST"
        "DB_DATABASE"
        "DB_USERNAME"
        "DB_PASSWORD"
    )

    local missing_secrets=()

    # Check each required secret
    for secret in "${required_secrets[@]}"; do
        if ! grep -q "^${secret}=" "$secrets_file"; then
            missing_secrets+=("$secret")
        fi
    done

    # If any secrets are missing, fail the deployment
    if [ ${#missing_secrets[@]} -gt 0 ]; then
        log_plain "ERROR" "═══════════════════════════════════════════════════════════"
        log_plain "ERROR" "⚠️  REQUIRED SECRETS MISSING"
        log_plain "ERROR" "═══════════════════════════════════════════════════════════"
        echo "The following required secrets are missing from:"
        echo "  $secrets_file"
        echo ""
        for secret in "${missing_secrets[@]}"; do
            echo "  ✗ $secret"
        done
        echo ""
        echo "Add these secrets before deploying. Typical locations:"
        echo "  • Common: /srv/apps/.config/${ENVIRONMENT}/laravel/common-values.${ENVIRONMENT}.new.env"
        echo "  • Project: /srv/apps/.config/${ENVIRONMENT}/laravel/${APP_NAME}.env"
        echo ""
        log_plain "ERROR" "═══════════════════════════════════════════════════════════"
        return 1
    fi

    # All secrets present
    log "SUCCESS" "All required secrets present (${#required_secrets[@]}/${#required_secrets[@]} validated)"

    # Optional: Warn about empty secrets
    local empty_secrets=()
    for secret in "${required_secrets[@]}"; do
        value=$(grep "^${secret}=" "$secrets_file" | cut -d= -f2-)
        if [ -z "$value" ]; then
            empty_secrets+=("$secret")
        fi
    done

    if [ ${#empty_secrets[@]} -gt 0 ]; then
        log "WARNING" "The following secrets are present but EMPTY:"
        for secret in "${empty_secrets[@]}"; do
            log "WARNING" "  - $secret"
        done
        log "WARNING" "This may cause deployment issues."
    fi

    return 0
}

# Export functions
