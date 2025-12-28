#!/bin/bash
# Health Check Validation Feature
# Verifies that /api/health endpoint exists before deployment

FEATURE_NAME="health-check"

health_check_is_enabled() {
    local enabled=$(get_config "features.${FEATURE_NAME}.enabled" "${ENVIRONMENT:-}")
    [ "$enabled" = "true" ]
}

health_check_init() {
    export HEALTH_ENDPOINT=$(get_config "health.endpoint" "${ENVIRONMENT:-}")
    export HEALTH_TEMPLATE_PATH="${DEPLOYMENT_ROOT}/templates/health-controller.php"
    return 0
}

health_check_hook_post_validation() {
    log "INFO" "Checking if health endpoint exists..."

    # Check if route is actually registered in Laravel using artisan
    # This is more reliable than grepping files - won't be fooled by comments
    local route_check=$(${DOCKER_COMPOSE_CMD} -f "$COMPOSE_FILE" -p "$DOCKER_COMPOSE_PROJECT" \
        exec -T app php artisan route:list --path=health 2>&1 || echo "error")

    if echo "$route_check" | grep -qi "health\|error"; then
        # Route exists or we couldn't check (container not ready yet)
        # During build, container may not be up yet, so we'll rely on the HTTP check later
        if echo "$route_check" | grep -q "GET.*health"; then
            log "SUCCESS" "Health endpoint found in route list"
            export SKIP_HEALTH_CHECK="false"
        else
            # Container not ready yet or route not found
            # We'll do a file-based check as fallback
            local routes_file="${DEPLOYMENT_ROOT}/../routes/api.php"

            if [ -f "$routes_file" ]; then
                # Check for non-commented route definition
                if grep -v "^[[:space:]]*\/\/" "$routes_file" | grep -q "Route.*['\"]health['\"]"; then
                    log "INFO" "Health route found in api.php (not yet verified in container)"
                    export SKIP_HEALTH_CHECK="false"
                else
                    log_plain "WARNING" "═══════════════════════════════════════════════════════════"
                    log_plain "WARNING" "⚠️  HEALTH ENDPOINT NOT FOUND"
                    log_plain "WARNING" "═══════════════════════════════════════════════════════════"
                    echo "The health check endpoint ${HEALTH_ENDPOINT:-/api/health} is not defined."
                    echo ""
                    echo "Add this to your routes/api.php:"
                    echo ""
                    echo "  Route::get('/health', [App\\Http\\Controllers\\HealthController::class, 'check']);"
                    echo ""
                    echo "Or use this simple inline version:"
                    echo ""
                    echo "  Route::get('/health', function () {"
                    echo "      return response()->json(["
                    echo "          'status' => 'healthy',"
                    echo "          'timestamp' => now()->toIso8601String(),"
                    echo "          'app' => ["
                    echo "              'name' => config('app.name'),"
                    echo "              'env' => config('app.env'),"
                    echo "              'debug' => config('app.debug'),"
                    echo "          ],"
                    echo "          'database' => DB::connection()->getPdo() ? 'connected' : 'disconnected',"
                    echo "          'cache' => Cache::has('health-check') || Cache::put('health-check', true, 10) ? 'accessible' : 'inaccessible',"
                    echo "      ]);"
                    echo "  });"
                    echo ""
                    echo "For a full controller implementation, see:"
                    echo "  ${HEALTH_TEMPLATE_PATH}"
                    echo ""
                    log_plain "WARNING" "═══════════════════════════════════════════════════════════"

                    # Skip HTTP health check if route doesn't exist
                    export SKIP_HEALTH_CHECK="true"

                    # For staging/local, this is just a warning - continue deployment
                    # For production, you might want to fail here
                    if [ "$ENVIRONMENT" = "production" ]; then
                        log "ERROR" "Health endpoint is required for production deployments"
                        return 1
                    fi
                fi
            else
                log "WARNING" "routes/api.php not found - skipping health route check"
                export SKIP_HEALTH_CHECK="true"
            fi
        fi
    else
        # This case means `route_check` did not contain "health" or "error",
        # implying `php artisan route:list --path=health` returned nothing,
        # which means the route is not registered.
        log "WARNING" "═══════════════════════════════════════════════════════════"
        log "WARNING" "⚠️  HEALTH ENDPOINT NOT FOUND"
        log "WARNING" "═══════════════════════════════════════════════════════════"
        log "WARNING" "The health check endpoint ${HEALTH_ENDPOINT:-/api/health} is not defined."
        log "WARNING" ""
        log "WARNING" "Add this to your routes/api.php:"
        log "WARNING" ""
        log "WARNING" "  Route::get('/health', [App\\Http\\Controllers\\HealthController::class, 'check']);"
        log "WARNING" ""
        log "WARNING" "Or use this simple inline version:"
        log "WARNING" ""
        log "WARNING" "  Route::get('/health', function () {"
        log "WARNING" "      return response()->json(["
        log "WARNING" "          'status' => 'healthy',"
        log "WARNING" "          'timestamp' => now()->toIso8601String(),"
        log "WARNING" "          'app' => ["
        log "WARNING" "              'name' => config('app.name'),"
        log "WARNING" "              'env' => config('app.env'),"
        log "WARNING" "              'debug' => config('app.debug'),"
        log "WARNING" "          ],"
        log "WARNING" "          'database' => DB::connection()->getPdo() ? 'connected' : 'disconnected',"
        log "WARNING" "          'cache' => Cache::has('health-check') || Cache::put('health-check', true, 10) ? 'accessible' : 'inaccessible',"
        log "WARNING" "      ]);"
        log "WARNING" "  });"
        log "WARNING" ""
        log "WARNING" "For a full controller implementation, see:"
        log "WARNING" "  ${HEALTH_TEMPLATE_PATH}"
        log "WARNING" ""
        log "WARNING" "═══════════════════════════════════════════════════════════"

        # Skip HTTP health check if route doesn't exist
        export SKIP_HEALTH_CHECK="true"

        # For staging/local, this is just a warning - continue deployment
        # For production, you might want to fail here
        if [ "$ENVIRONMENT" = "production" ]; then
            log "ERROR" "Health endpoint is required for production deployments"
            return 1
        fi
    fi

    return 0
}

# Export functions
