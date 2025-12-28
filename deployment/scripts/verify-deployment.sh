#!/bin/bash

# verify-deployment.sh
# Verifies the health of the deployed application without exposing public endpoints.

set -e

source "$(dirname "${BASH_SOURCE[0]}")/lib/utils.sh"

verify_deployment() {
    local environment="$1"
    local container_name="dc_${PROJECT_NAME}_${environment}"

    log "INFO" "Starting deployment verification for: $container_name"

    # 1. Verify Container Status
    log "INFO" "Checking container status..."
    if [ "$(docker inspect -f '{{.State.Running}}' "$container_name" 2>/dev/null)" != "true" ]; then
        log "ERROR" "Container $container_name is not running."
        return 1
    fi
    log "SUCCESS" "Container is running."

    # 2. Verify Web Server Connectivity (Internal)
    log "INFO" "Checking web server connectivity..."
    # We check if the server responds to a request (even 404 is fine, implies server is up)
    if docker exec "$container_name" curl -s -o /dev/null -w "%{http_code}" http://localhost:9000/ | grep -qE "(200|301|302|404)"; then
        log "SUCCESS" "Web server is accepting connections."
    else
        log "ERROR" "Web server failed to respond."
        return 1
    fi

    # 3. Verify Application Integrity & Environment
    log "INFO" "Checking application environment..."
    if docker exec "$container_name" php artisan about --only=environment --json > /dev/null 2>&1; then
        # Capture and display critical info
        local app_info=$(docker exec "$container_name" php artisan about --only=environment --json)
        local debug_mode=$(echo "$app_info" | grep -o '"debug":[^,]*' | cut -d: -f2)
        local env_name=$(echo "$app_info" | grep -o '"application_env":[^,]*' | cut -d: -f2 | tr -d '"')

        log "SUCCESS" "Application booted successfully (Env: $env_name, Debug: $debug_mode)"
    else
        log "ERROR" "Failed to retrieve application information (php artisan about failed)."
        return 1
    fi

    # 4. Verify Database Connectivity
    log "INFO" "Checking database connectivity..."
    if docker exec "$container_name" php artisan migrate:status > /dev/null 2>&1; then
        log "SUCCESS" "Database connection established."
    else
        log "ERROR" "Database connection failed."
        return 1
    fi

    # 5. Verify Cache Access
    log "INFO" "Checking cache accessibility..."
    # We try to clear the cache as a connectivity test
    if docker exec "$container_name" php artisan cache:clear > /dev/null 2>&1; then
        log "SUCCESS" "Cache is accessible."
    else
        log "WARNING" "Cache check failed or not configured."
        # Not fatal for now, but good to know
    fi

    log "SUCCESS" "Deployment verification completed successfully."
    return 0
}

# Allow direct execution
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    if [ -z "$1" ]; then
        echo "Usage: $0 <environment>"
        exit 1
    fi
    verify_deployment "$1"
fi
