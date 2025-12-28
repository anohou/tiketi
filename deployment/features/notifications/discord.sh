#!/usr/bin/env bash
# Feature: Discord Notifications
# Sends deployment notifications to Discord


FEATURE_NAME="discord"

#######################################
# Discord color codes
#######################################
readonly COLOR_SUCCESS=3066993   # Green
readonly COLOR_FAILURE=15158332  # Red
readonly COLOR_INFO=3447003      # Blue
readonly COLOR_WARNING=15105570  # Orange

#######################################
# Check if feature is enabled
#######################################
discord_is_enabled() {
    local enabled=$(get_config "features.${FEATURE_NAME}.enabled" 2>/dev/null || echo "false")
    [[ "$enabled" == "true" ]]
}

#######################################
# Initialize feature
#######################################
discord_init() {
    discord_is_enabled || return 0

    log "INFO" "[${FEATURE_NAME}] Initializing..."

    # Get webhook URL from environment
    local webhook_env=$(get_config "features.${FEATURE_NAME}.webhook_url_env" 2>/dev/null || echo "DISCORD_WEBHOOK_URL")
    export DISCORD_WEBHOOK_URL="${!webhook_env:-}"

    if [[ -z "$DISCORD_WEBHOOK_URL" ]]; then
        log "WARNING" "[${FEATURE_NAME}] Webhook URL not configured - notifications disabled"
        return 0
    fi

    log "SUCCESS" "[${FEATURE_NAME}] Initialized"
}

#######################################
# Validate configuration
#######################################
discord_validate() {
    discord_is_enabled || return 0
    return 0
}

#######################################
# Send Discord notification
# Arguments:
#   $1 - Status (success/failure/info/warning)
#   $2 - Title
#   $3 - Description
#   $4 - Extra fields (optional JSON)
#######################################
send_discord_notification() {
    [[ -z "${DISCORD_WEBHOOK_URL:-}" ]] && return 0

    local status="$1"
    local title="$2"
    local description="$3"
    local extra_fields="${4:-}"

    # Select emoji and color
    local emoji color
    case "$status" in
        success) emoji="✅"; color=$COLOR_SUCCESS ;;
        failure) emoji="❌"; color=$COLOR_FAILURE ;;
        warning) emoji="⚠️"; color=$COLOR_WARNING ;;
        *) emoji="ℹ️"; color=$COLOR_INFO ;;
    esac

    # Build base fields
    local timestamp=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    local fields="[
        {\"name\": \"Environment\", \"value\": \"\`${ENVIRONMENT:-unknown}\`\", \"inline\": true},
        {\"name\": \"Action\", \"value\": \"\`${ACTION:-deploy}\`\", \"inline\": true},
        {\"name\": \"User\", \"value\": \"${USER:-CI/CD}\", \"inline\": true}
    ]"

    # Merge extra fields if provided
    if [[ -n "$extra_fields" ]]; then
        fields=$(echo "[$fields,$extra_fields]" | jq -s 'add' 2>/dev/null || echo "$fields")
    fi

    # Send notification
    curl -X POST "$DISCORD_WEBHOOK_URL" \
        -H 'Content-Type: application/json' \
        --silent --show-error --fail \
        -d "{
            \"embeds\": [{
                \"title\": \"$emoji $title\",
                \"description\": \"$description\",
                \"color\": $color,
                \"fields\": $fields,
                \"timestamp\": \"$timestamp\",
                \"footer\": {\"text\": \"Deployment System\"}
            }]
        }" 2>&1 | log "DEBUG" || true  # Don't fail deployment if notification fails
}

#######################################
# Hook: pre-deploy
#######################################
discord_hook_pre_deploy() {
    discord_is_enabled || return 0

    local extra_fields="{\"name\": \"Image\", \"value\": \"\`${IMAGE_TAG:-building}\`\", \"inline\": false}"
    send_discord_notification "info" "Deployment Started" \
        "🚀 Starting deployment to **${ENVIRONMENT}**" \
        "$extra_fields"
}

#######################################
# Hook: on-success
#######################################
discord_hook_on_success() {
    discord_is_enabled || return 0

    local duration="${DEPLOYMENT_DURATION:-unknown}"
    local app_url="${APP_URL:-}"

    local description="✨ Deployment to **${ENVIRONMENT}** completed in ${duration}s"

    local extra_fields="{\"name\": \"Duration\", \"value\": \"${duration}s\", \"inline\": true}"

    if [[ -n "$app_url" ]]; then
        extra_fields="$extra_fields,{\"name\": \"URL\", \"value\": \"$app_url\", \"inline\": false}"
    fi

    send_discord_notification "success" "Deployment Successful" \
        "$description" \
        "$extra_fields"
}

#######################################
# Hook: on-failure
#######################################
discord_hook_on_failure() {
    discord_is_enabled || return 0

    local duration="${DEPLOYMENT_DURATION:-unknown}"
    local error_msg="${DEPLOYMENT_ERROR:-Check logs for details}"

    local description="💥 Deployment to **${ENVIRONMENT}** failed after ${duration}s"

    local extra_fields="{\"name\": \"Duration\", \"value\": \"${duration}s\", \"inline\": true}"
    extra_fields="$extra_fields,{\"name\": \"Error\", \"value\": \"\`\`\`${error_msg:0:100}\`\`\`\", \"inline\": false}"

    send_discord_notification "failure" "Deployment Failed" \
        "$description" \
        "$extra_fields"
}

#######################################
# Cleanup
#######################################
discord_cleanup() {
    return 0
}
