#!/usr/bin/env bash
# Feature: Secrets Rotation Detection
# Detects when secrets files have changed


FEATURE_NAME="secrets-rotation"

#######################################
# Check if feature is enabled
#######################################
secrets_rotation_is_enabled() {
    local enabled=$(get_config "features.${FEATURE_NAME}.enabled" 2>/dev/null || echo "false")
    [[ "$enabled" == "true" ]]
}

#######################################
# Initialize feature
#######################################
secrets_rotation_init() {
    secrets_rotation_is_enabled || return 0

    log "INFO" "[${FEATURE_NAME}] Initializing..."

    # Load configuration
    export NOTIFY_ON_ROTATION=$(get_config "features.${FEATURE_NAME}.notification" 2>/dev/null || echo "true")

    log "SUCCESS" "[${FEATURE_NAME}] Initialized"
}

#######################################
# Validate configuration
#######################################
secrets_rotation_validate() {
    secrets_rotation_is_enabled || return 0
    return 0
}

#######################################
# Get hash of secrets file
#######################################
get_secrets_hash() {
    local secrets_file="$1"

    [[ ! -f "$secrets_file" ]] && echo "" && return 1

    # SHA-256 hash of entire file
    sha256sum "$secrets_file" 2>/dev/null | cut -d' ' -f1 || echo ""
}

#######################################
# Hook: post-validation
# Check for secrets rotation after secrets are merged
#######################################
secrets_rotation_hook_post_validation() {
    secrets_rotation_is_enabled || return 0

    local secrets_file="${SECRETS_MOUNT_FILE:-}"

    # Skip if no secrets file
    if [[ -z "$secrets_file" ]] || [[ ! -f "$secrets_file" ]]; then
        return 0
    fi

    log "INFO" "[${FEATURE_NAME}] Checking for secrets rotation..."

    # Compute hash of current secrets
    local new_hash=$(get_secrets_hash "$secrets_file")

    if [[ -z "$new_hash" ]]; then
        log "WARNING" "[${FEATURE_NAME}] Could not compute secrets hash"
        return 0
    fi

    # Get hash from running container (if exists)
    local old_hash=""
    if docker ps -q -f name="${DOCKER_CONTAINER_NAME}" &>/dev/null; then
        old_hash=$(docker inspect "${DOCKER_CONTAINER_NAME}" \
            --format='{{index .Config.Labels "secrets.hash"}}' 2>/dev/null || echo "")
    fi

    # Compare hashes
    if [[ -n "$old_hash" ]] && [[ "$old_hash" != "$new_hash" ]]; then
        log "WARNING" "[${FEATURE_NAME}] ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
        log "WARNING" "[${FEATURE_NAME}]  🔄 SECRETS CHANGED"
        log "WARNING" "[${FEATURE_NAME}] ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
        log "WARNING" "[${FEATURE_NAME}] Secrets file hash changed"
        log "WARNING" "[${FEATURE_NAME}] Old hash: ${old_hash:0:16}..."
        log "WARNING" "[${FEATURE_NAME}] New hash: ${new_hash:0:16}..."
        log "WARNING" "[${FEATURE_NAME}] Container will be restarted to apply new secrets"
        log "WARNING" "[${FEATURE_NAME}] ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

        # Send notification if Discord is enabled
        if [[ "$NOTIFY_ON_ROTATION" == "true" ]] && type -t send_discord_notification &>/dev/null; then
            local extra_fields="{\"name\": \"Old Hash\", \"value\": \"\`${old_hash:0:16}...\`\", \"inline\": true}"
            extra_fields="$extra_fields,{\"name\": \"New Hash\", \"value\": \"\`${new_hash:0:16}...\`\", \"inline\": true}"
            extra_fields="$extra_fields,{\"name\": \"Secrets File\", \"value\": \"\`$secrets_file\`\", \"inline\": false}"

            send_discord_notification "warning" "Secrets Rotated" \
                "🔄 Secrets file changed - container will be restarted" \
                "$extra_fields"
        fi

        # Stop old container (new one will start with new secrets)
        log "INFO" "[${FEATURE_NAME}] Stopping old container..."
        ${DOCKER_COMPOSE_CMD:-docker-compose} -f "${COMPOSE_FILE}" -p "${DOCKER_COMPOSE_PROJECT}" down 2>/dev/null || true

        log "SUCCESS" "[${FEATURE_NAME}] Old container stopped - new one will start with updated secrets"
    else
        log "INFO" "[${FEATURE_NAME}] Secrets unchanged since last deployment"
    fi

    # Store new hash for docker-compose to use
    export SECRETS_HASH="$new_hash"
    export DEPLOYMENT_TIMESTAMP=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
}

#######################################
# Cleanup
#######################################
secrets_rotation_cleanup() {
    return 0
}
