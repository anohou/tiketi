#!/usr/bin/env bash
# URL script - show service URL

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/utils.sh"

show_url() {
    local environment="$1"

    local url
    if [[ "$environment" == "local" ]]; then
        url="http://${APP_DOMAIN}"
    else
        url="https://${APP_DOMAIN}"
    fi

    if [[ -n "$APP_URL_PATH" ]]; then
        url="${url}/${APP_URL_PATH}"
    fi

    log "SUCCESS" "Service URL: $url"
    echo "$url"
}

show_url "$@"
