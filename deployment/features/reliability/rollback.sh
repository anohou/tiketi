#!/usr/bin/env bash
# Feature: Rollback Mechanism
# Provides automated rollback on deployment failure


FEATURE_NAME="rollback"

#######################################
# Check if feature is enabled
#######################################
rollback_is_enabled() {
    local enabled=$(get_config "features.${FEATURE_NAME}.enabled" 2>/dev/null || echo "false")
    [[ "$enabled" == "true" ]]
}

#######################################
# Initialize feature
#######################################
rollback_init() {
    rollback_is_enabled || return 0

    log "INFO" "[${FEATURE_NAME}] Initializing..."

    # Load configuration
    export AUTO_ROLLBACK=$(get_config "features.${FEATURE_NAME}.auto_rollback" 2>/dev/null || echo "true")
    export ROLLBACK_BACKUP_PATH=$(get_config "features.${FEATURE_NAME}.backup_path" 2>/dev/null || echo "/tmp/deployment-backups")
    export ROLLBACK_RETENTION_DAYS=$(get_config "features.${FEATURE_NAME}.retention_days" 2>/dev/null || echo "7")

    # Create backup directory
    mkdir -p "$ROLLBACK_BACKUP_PATH" 2>/dev/null || true

    log "SUCCESS" "[${FEATURE_NAME}] Initialized (auto_rollback: $AUTO_ROLLBACK)"
}

#######################################
# Validate configuration
#######################################
rollback_validate() {
    rollback_is_enabled || return 0

    # Check if backup path is writable
    if [[ ! -w "$ROLLBACK_BACKUP_PATH" ]] && [[ ! -w "$(dirname "$ROLLBACK_BACKUP_PATH")" ]]; then
        log "WARNING" "[${FEATURE_NAME}] Backup path not writable: $ROLLBACK_BACKUP_PATH"
    fi

    return 0
}

#######################################
# Save current deployment state
#######################################
save_deployment_state() {
    rollback_is_enabled || return 0

    local container="${DOCKER_CONTAINER_NAME:-}"

    # Skip if no container exists
    if [[ -z "$container" ]] || ! docker ps -a -q -f name="^${container}$" &>/dev/null; then
        return 0
    fi

    local state_file="${ROLLBACK_BACKUP_PATH}/${container}-$(date +%s).json"

    log "INFO" "[${FEATURE_NAME}] Saving deployment state..."

    # Save container state
    docker inspect "$container" > "$state_file" 2>/dev/null || {
        log "WARNING" "[${FEATURE_NAME}] Could not save deployment state"
        return 0
    }

    log "SUCCESS" "[${FEATURE_NAME}] State saved: $state_file"
    echo "$state_file"
}

#######################################
# Perform rollback to previous state
#######################################
perform_rollback() {
    rollback_is_enabled || return 0

    [[ "$AUTO_ROLLBACK" != "true" ]] && return 0

    log "WARNING" "[${FEATURE_NAME}] ==========================================="
    log "WARNING" "[${FEATURE_NAME}]  INITIATING ROLLBACK"
    log "WARNING" "[${FEATURE_NAME}] ==========================================="

    # Find most recent backup
    local latest_backup=$(ls -t "${ROLLBACK_BACKUP_PATH}/${DOCKER_CONTAINER_NAME}"-*.json 2>/dev/null | head -1)

    if [[ -z "$latest_backup" ]] || [[ ! -f "$latest_backup" ]]; then
        log "ERROR" "[${FEATURE_NAME}] No previous state found - cannot rollback"
        log "ERROR" "[${FEATURE_NAME}] Manual intervention required"
        return 1
    fi

    # Get previous image
    local prev_image=$(jq -r '.[0].Config.Image' "$latest_backup" 2>/dev/null || echo "")
    local prev_id=$(jq -r '.[0].Id' "$latest_backup" 2>/dev/null || echo "")

    if [[ -z "$prev_image" ]]; then
        log "ERROR" "[${FEATURE_NAME}] Cannot extract previous image from backup"
        return 1
    fi

    log "INFO" "[${FEATURE_NAME}] Previous image: $prev_image"
    log "INFO" "[${FEATURE_NAME}] Stopping failed deployment..."

    # Stop failed container
    ${DOCKER_COMPOSE_CMD:-docker-compose} -f "${COMPOSE_FILE}" -p "${DOCKER_COMPOSE_PROJECT}" down 2>/dev/null || true

    # Try to start previous container if it still exists
    if [[ -n "$prev_id" ]] && docker ps -a -q --filter "id=$prev_id" &>/dev/null; then
        log "INFO" "[${FEATURE_NAME}] Restarting previous container..."
        docker start "$prev_id" 2>/dev/null || {
            log "WARNING" "[${FEATURE_NAME}] Could not restart previous container"
            log "INFO" "[${FEATURE_NAME}] Attempting fresh deployment of previous image..."

            # Deploy previous image
            export FULL_REGISTRY_PATH="$prev_image"
            ${DOCKER_COMPOSE_CMD:-docker-compose} -f "${COMPOSE_FILE}" -p "${DOCKER_COMPOSE_PROJECT}" up -d
        }
    else
        log "INFO" "[${FEATURE_NAME}] Deploying previous image..."
        export FULL_REGISTRY_PATH="$prev_image"
        ${DOCKER_COMPOSE_CMD:-docker-compose} -f "${COMPOSE_FILE}" -p "${DOCKER_COMPOSE_PROJECT}" up -d
    fi

    log "SUCCESS" "[${FEATURE_NAME}] Rollback completed"

    # Send notification
    if type -t send_discord_notification &>/dev/null; then
        local extra_fields="{\"name\": \"Previous Image\", \"value\": \"\`$prev_image\`\", \"inline\": false}"
        send_discord_notification "warning" "Deployment Rolled Back" \
            "⏮️ Deployment failed - rolled back to previous version" \
            "$extra_fields"
    fi

    return 0
}

#######################################
# Cleanup old backups
#######################################
cleanup_old_backups() {
    rollback_is_enabled || return 0

    local retention_days="${ROLLBACK_RETENTION_DAYS:-7}"

    log "INFO" "[${FEATURE_NAME}] Cleaning up backups older than $retention_days days..."

    find "$ROLLBACK_BACKUP_PATH" -name "*.json" -type f -mtime +$retention_days -delete 2>/dev/null || true
}

#######################################
# Hook: pre-deploy
# Save current state before deployment
#######################################
rollback_hook_pre_deploy() {
    rollback_is_enabled || return 0

    # Save current state
    export ROLLBACK_STATE_FILE=$(save_deployment_state)

    # Cleanup old backups
    cleanup_old_backups
}

#######################################
# Hook: on-failure
# Perform rollback if deployment fails
#######################################
rollback_hook_on_failure() {
    rollback_is_enabled || return 0

    perform_rollback
}

#######################################
# Hook: on-success
# Remove backup on successful deployment
#######################################
rollback_hook_on_success() {
    rollback_is_enabled || return 0

    # Remove backup file (deployment succeeded)
    if [[ -n "${ROLLBACK_STATE_FILE:-}" ]] && [[ -f "$ROLLBACK_STATE_FILE" ]]; then
        rm -f "$ROLLBACK_STATE_FILE"
    fi
}

#######################################
# Cleanup
#######################################
rollback_cleanup() {
    return 0
}
