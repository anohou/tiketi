#!/usr/bin/env bash
# Feature: Slack Notifications
# Sends deployment notifications to Slack


FEATURE_NAME="slack"

#######################################
# Check if feature is enabled
#######################################
slack_is_enabled() {
    local enabled=$(get_config "features.${FEATURE_NAME}.enabled" 2>/dev/null || echo "false")
    [[ "$enabled" == "true" ]]
}

#######################################
# Initialize feature
#######################################
slack_init() {
    slack_is_enabled || return 0

    log "INFO" "[${FEATURE_NAME}] Initializing..."

    # Get webhook URL from environment
    local webhook_env=$(get_config "features.${FEATURE_NAME}.webhook_url_env" 2>/dev/null || echo "SLACK_WEBHOOK_URL")
    export SLACK_WEBHOOK_URL="${!webhook_env:-}"

    if [[ -z "$SLACK_WEBHOOK_URL" ]]; then
        log "WARNING" "[${FEATURE_NAME}] Webhook URL not configured - notifications disabled"
        return 0
    fi

    log "SUCCESS" "[${FEATURE_NAME}] Initialized"
}

#######################################
# Validate configuration
#######################################
slack_validate() {
    slack_is_enabled || return 0
    return 0
}

#######################################
# Send Slack notification
# Arguments:
#   $1 - Status (success/failure/info/warning)
#   $2 - Title
#   $3 - Description
#######################################
send_slack_notification() {
    [[ -z "${SLACK_WEBHOOK_URL:-}" ]] && return 0

    local status="$1"
    local title="$2"
    local description="$3"

    # Select color
    local color
    case "$status" in
        success) color="good" ;;
        failure) color="danger" ;;
        warning) color="warning" ;;
        *) color="#3447003" ;;
    esac

    # Send notification
    curl -X POST "$SLACK_WEBHOOK_URL" \
        -H 'Content-Type: application/json' \
        --silent --show-error --fail \
        -d "{
            \"text\": \"$title\",
            \"attachments\": [{
                \"color\": \"$color\",
                \"text\": \"$description\",
                \"fields\": [
                    {\"title\": \"Environment\", \"value\": \"${ENVIRONMENT:-unknown}\", \"short\": true},
                    {\"title\": \"Action\", \"value\": \"${ACTION:-deploy}\", \"short\": true},
                    {\"title\": \"User\", \"value\": \"${USER:-CI/CD}\", \"short\": true}
                ],
                \"footer\": \"Deployment System\",
                \"ts\": $(date +%s)
            }]
        }" 2>&1 | log "DEBUG" || true
}

#######################################
# Hook: pre-deploy
#######################################
slack_hook_pre_deploy() {
    slack_is_enabled || return 0

    send_slack_notification "info" "Deployment Started" \
        "🚀 Starting deployment to ${ENVIRONMENT}"
}

#######################################
# Hook: on-success
#######################################
slack_hook_on_success() {
    slack_is_enabled || return 0

    send_slack_notification "success" "Deployment Successful" \
        "✅ Deployment to ${ENVIRONMENT} completed in ${DEPLOYMENT_DURATION:-unknown}s"
}

#######################################
# Hook: on-failure
#######################################
slack_hook_on_failure() {
    slack_is_enabled || return 0

    send_slack_notification "failure" "Deployment Failed" \
        "❌ Deployment to ${ENVIRONMENT} failed"
}

#######################################
# Cleanup
#######################################
slack_cleanup() {
    return 0
}
