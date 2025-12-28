#!/usr/bin/env bash
# Feature: Telegram Notifications
# Sends deployment notifications to Telegram


FEATURE_NAME="telegram"

#######################################
# Check if feature is enabled
#######################################
telegram_is_enabled() {
    local enabled=$(get_config "features.${FEATURE_NAME}.enabled" 2>/dev/null || echo "false")
    [[ "$enabled" == "true" ]]
}

#######################################
# Initialize feature
#######################################
telegram_init() {
    telegram_is_enabled || return 0

    log "INFO" "[${FEATURE_NAME}] Initializing..."

    # Get bot token and chat ID from environment
    local bot_token_env=$(get_config "features.${FEATURE_NAME}.bot_token_env" 2>/dev/null || echo "TELEGRAM_BOT_TOKEN")
    local chat_id_env=$(get_config "features.${FEATURE_NAME}.chat_id_env" 2>/dev/null || echo "TELEGRAM_CHAT_ID")

    export TELEGRAM_BOT_TOKEN="${!bot_token_env:-}"
    export TELEGRAM_CHAT_ID="${!chat_id_env:-}"

    if [[ -z "$TELEGRAM_BOT_TOKEN" ]] || [[ -z "$TELEGRAM_CHAT_ID" ]]; then
        log "WARNING" "[${FEATURE_NAME}] Bot token or chat ID not configured - notifications disabled"
        return 0
    fi

    log "SUCCESS" "[${FEATURE_NAME}] Initialized"
}

#######################################
# Validate configuration
#######################################
telegram_validate() {
    telegram_is_enabled || return 0
    return 0
}

#######################################
# Send Telegram notification
# Arguments:
#   $1 - Status (success/failure/info/warning)
#   $2 - Title
#   $3 - Description
#######################################
send_telegram_notification() {
    [[ -z "${TELEGRAM_BOT_TOKEN:-}" ]] || [[ -z "${TELEGRAM_CHAT_ID:-}" ]] && return 0

    local status="$1"
    local title="$2"
    local description="$3"

    # Select emoji
    local emoji
    case "$status" in
        success) emoji="✅" ;;
        failure) emoji="❌" ;;
        info) emoji="ℹ️" ;;
        warning) emoji="⚠️" ;;
    esac

    # Build message with HTML formatting
    local message="<b>$emoji $title</b>%0A%0A"
    message+="$description%0A%0A"
    message+="<b>Environment:</b> <code>${ENVIRONMENT:-unknown}</code>%0A"
    message+="<b>Action:</b> <code>${ACTION:-deploy}</code>%0A"
    message+="<b>User:</b> ${USER:-CI/CD}%0A"
    message+="<b>Time:</b> $(date '+%Y-%m-%d %H:%M:%S')"

    # Send notification
    curl -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" \
        --silent --show-error \
        -d "chat_id=${TELEGRAM_CHAT_ID}" \
        -d "text=${message}" \
        -d "parse_mode=HTML" \
        -d "disable_web_page_preview=true" 2>&1 | log "DEBUG" || true
}

#######################################
# Hook: pre-deploy
#######################################
telegram_hook_pre_deploy() {
    telegram_is_enabled || return 0

    send_telegram_notification "info" "Deployment Started" \
        "Starting deployment to ${ENVIRONMENT}"
}

#######################################
# Hook: on-success
#######################################
telegram_hook_on_success() {
    telegram_is_enabled || return 0

    send_telegram_notification "success" "Deployment Successful" \
        "Deployment to ${ENVIRONMENT} completed in ${DEPLOYMENT_DURATION:-unknown}s"
}

#######################################
# Hook: on-failure
#######################################
telegram_hook_on_failure() {
    telegram_is_enabled || return 0

    send_telegram_notification "failure" "Deployment Failed" \
        "Deployment to ${ENVIRONMENT} failed"
}

#######################################
# Cleanup
#######################################
telegram_cleanup() {
    return 0
}
