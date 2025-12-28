#!/usr/bin/env bash
# Feature: Audit Logging
# Logs all deployment events to audit log file


FEATURE_NAME="audit-logging"

#######################################
# Check if feature is enabled
#######################################
audit_logging_is_enabled() {
    local enabled=$(get_config "features.${FEATURE_NAME}.enabled" 2>/dev/null || echo "true")
    [[ "$enabled" == "true" ]]
}

#######################################
# Initialize feature
#######################################
audit_logging_init() {
    audit_logging_is_enabled || return 0

    log "INFO" "[${FEATURE_NAME}] Initializing..."

    # Get audit log configuration
    export AUDIT_LOG_FILE=$(get_config "features.${FEATURE_NAME}.file" 2>/dev/null || echo "/tmp/${PROJECT_NAME}-deployments.log")
    export AUDIT_LOG_RETENTION_DAYS=$(get_config "features.${FEATURE_NAME}.retention_days" 2>/dev/null || echo "90")
    export AUDIT_LOG_MAX_SIZE_MB=$(get_config "features.${FEATURE_NAME}.max_size_mb" 2>/dev/null || echo "100")

    # Create log directory and file
    local log_dir=$(dirname "$AUDIT_LOG_FILE")
    mkdir -p "$log_dir" 2>/dev/null || true
    touch "$AUDIT_LOG_FILE" 2>/dev/null || true
    chmod 640 "$AUDIT_LOG_FILE" 2>/dev/null || true

    # Rotate if needed
    rotate_audit_log

    # Cleanup old logs
    cleanup_old_audit_logs

    log "SUCCESS" "[${FEATURE_NAME}] Initialized (log: $AUDIT_LOG_FILE)"
}

#######################################
# Validate configuration
#######################################
audit_logging_validate() {
    audit_logging_is_enabled || return 0

    # Check if log file is writable
    if [[ ! -w "$AUDIT_LOG_FILE" ]] && [[ ! -w "$(dirname "$AUDIT_LOG_FILE")" ]]; then
        log "WARNING" "[${FEATURE_NAME}] Audit log not writable: $AUDIT_LOG_FILE"
    fi

    return 0
}

#######################################
# Rotate log if size exceeds limit
#######################################
rotate_audit_log() {
    [[ ! -f "$AUDIT_LOG_FILE" ]] && return 0

    local max_size_mb="${AUDIT_LOG_MAX_SIZE_MB:-100}"
    local max_size_bytes=$((max_size_mb * 1024 * 1024))

    local size
    if [[ "$OSTYPE" == "darwin"* ]]; then
        size=$(stat -f%z "$AUDIT_LOG_FILE" 2>/dev/null || echo "0")
    else
        size=$(stat -c%s "$AUDIT_LOG_FILE" 2>/dev/null || echo "0")
    fi

    if [[ $size -gt $max_size_bytes ]]; then
        mv "$AUDIT_LOG_FILE" "${AUDIT_LOG_FILE}.1" 2>/dev/null || true
        touch "$AUDIT_LOG_FILE"
        log "INFO" "[${FEATURE_NAME}] Rotated audit log (size: $((size / 1024 / 1024))MB)"
    fi
}

#######################################
# Cleanup old log entries
#######################################
cleanup_old_audit_logs() {
    [[ ! -f "$AUDIT_LOG_FILE" ]] && return 0

    local retention_days="${AUDIT_LOG_RETENTION_DAYS:-90}"
    local cutoff_date

    if [[ "$OSTYPE" == "darwin"* ]]; then
        cutoff_date=$(date -v-${retention_days}d +%Y-%m-%d 2>/dev/null || date +%Y-%m-%d)
    else
        cutoff_date=$(date -d "$retention_days days ago" +%Y-%m-%d 2>/dev/null || date +%Y-%m-%d)
    fi

    # Keep only recent logs
    awk -v cutoff="$cutoff_date" '$1 >= cutoff' "$AUDIT_LOG_FILE" > "${AUDIT_LOG_FILE}.tmp" 2>/dev/null || true

    if [[ -f "${AUDIT_LOG_FILE}.tmp" ]]; then
        mv "${AUDIT_LOG_FILE}.tmp" "$AUDIT_LOG_FILE"
    fi
}

#######################################
# Log deployment event
# Arguments:
#   $1 - Event type (START/END/ERROR)
#   $2 - Status (success/failure)
#   $3 - Metadata
#######################################
log_deployment_event() {
    audit_logging_is_enabled || return 0
    [[ ! -w "$AUDIT_LOG_FILE" ]] && return 0

    local event_type="$1"
    local status="${2:-}"
    local metadata="${3:-}"

    local timestamp=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    local user="${USER:-unknown}"
    local git_sha=$(git rev-parse --short HEAD 2>/dev/null || echo "unknown")

    echo "$timestamp|$event_type|$status|$user|${ENVIRONMENT:-unknown}|${ACTION:-unknown}|$git_sha|${IMAGE_TAG:-unknown}|$metadata" >> "$AUDIT_LOG_FILE"
}

#######################################
# Hook: pre-deploy
#######################################
audit_logging_hook_pre_deploy() {
    log_deployment_event "START" "" ""
}

#######################################
# Hook: on-success
#######################################
audit_logging_hook_on_success() {
    log_deployment_event "END" "success" "duration=${DEPLOYMENT_DURATION:-0}s"
}

#######################################
# Hook: on-failure
#######################################
audit_logging_hook_on_failure() {
    log_deployment_event "END" "failure" "duration=${DEPLOYMENT_DURATION:-0}s,error=${DEPLOYMENT_ERROR:-unknown}"
}

#######################################
# Cleanup
#######################################
audit_logging_cleanup() {
    return 0
}
