#!/usr/bin/env bash
# Deploy script - handles deployment logic

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOYMENT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
source "${SCRIPT_DIR}/lib/utils.sh"

# Load feature system
source "${DEPLOYMENT_ROOT}/features/loader.sh"

#######################################
# Validate required environment variables
# Ensures all variables needed by docker-compose are set
# Arguments:
#   $1 - Environment (local, staging, production)
#######################################
validate_required_vars() {
    local environment="$1"

    local required_vars=(
        "DOCKER_CONTAINER_NAME"
        "DOCKER_IMAGE_NAME"
        "COMPOSE_PROJECT_NAME"
        "DOCKER_PROJECT"
        "ENVIRONMENT"
    )

    # Add URL vars for staging/production
    if [[ "$environment" != "local" ]]; then
        required_vars+=("APP_URL_DOMAIN" "APP_URL_PATH")
    fi

    local missing_vars=()

    for var in "${required_vars[@]}"; do
        # Special case: APP_URL_PATH can be empty (for domain-based routing)
        if [[ "$var" == "APP_URL_PATH" ]]; then
            if [[ ! -v $var ]]; then
                missing_vars+=("$var")
            fi
        else
            if [[ -z "${!var:-}" ]]; then
                missing_vars+=("$var")
            fi
        fi
    done

    if [[ ${#missing_vars[@]} -gt 0 ]]; then
        log "ERROR" "Required environment variables not set:"
        for var in "${missing_vars[@]}"; do
            log "ERROR" "  - $var"
        done
        log "ERROR" "These variables must be exported before running docker-compose"
        exit 1
    fi

    log "SUCCESS" "All required environment variables validated"
}

deploy() {
    local environment="$1"
    local start_time=$(date +%s)

    log "INFO" "Starting deployment for: $environment"

    # Initialize configuration
    load_config "$environment"
    setup_environment "$environment"

    # === LOAD AND INITIALIZE FEATURES ===
    load_features
    init_features || exit 1

    # Export variables for docker-compose and features
    export ENVIRONMENT="$environment"
    export ACTION="deploy"
    export TRAEFIK_SWARM_NETWORK=$(get_config "infrastructure.traefik.network_name" "$environment")

    # Build names from config patterns using placeholders
    local container_pattern=$(get_config "docker.container_name_pattern" "$environment")
    local image_pattern=$(get_config "docker.image_name_pattern" "$environment")
    local compose_pattern=$(get_config "docker.compose_project_pattern" "$environment")

    # Substitute placeholders: {project_name} and {env}
    # Convert project name hyphens to underscores for Docker compatibility
    local docker_project_name=$(echo "$PROJECT_NAME" | tr '-' '_')
    log "INFO" "Docker project name (converted): $docker_project_name"
    export COMPOSE_PROJECT_NAME=$(echo "$compose_pattern" | sed "s/{project_name}/${docker_project_name}/g" | sed "s/{env}/${environment}/g")
    export DOCKER_IMAGE_NAME=$(echo "$image_pattern" | sed "s/{project_name}/${docker_project_name}/g" | sed "s/{env}/${environment}/g")
    export DOCKER_CONTAINER_NAME=$(echo "$container_pattern" | sed "s/{project_name}/${docker_project_name}/g" | sed "s/{env}/${environment}/g")

    # Export for docker-compose database/redis container names
    export DOCKER_PROJECT="$docker_project_name"

    export APP_URL_DOMAIN="$APP_DOMAIN"
    export APP_URL_PATH="${APP_URL_PATH:-}"

    # Validate all required environment variables are set
    validate_required_vars "$environment"

    # === HOOK: pre-validation ===
    exec_hook "pre-validation" "$environment" "deploy"

    # Validate features
    validate_features || exit 1

    # Verify secrets file(s) exists - support for multi-secrets
    local secrets_missing=0
    if [ -n "${SECRETS_FILES:-}" ]; then
        # Multi-secrets mode
        for secrets_file in $SECRETS_FILES; do
            if [ ! -f "$secrets_file" ]; then
                log "ERROR" "Secrets file not found: $secrets_file"
                secrets_missing=1
            fi
        done
    elif [ -n "${SECRETS_FILE:-}" ]; then
        # Single secrets file mode (legacy)
        if [ ! -f "$SECRETS_FILE" ]; then
            log "ERROR" "Secrets file not found: $SECRETS_FILE"
            secrets_missing=1
        fi
    else
        log "ERROR" "No secrets file configured"
        secrets_missing=1
    fi

    if [ $secrets_missing -eq 1 ]; then
        log "INFO" "Please create the required secrets file(s) before deploying."
        exit 1
    fi

    # Check Docker
    check_docker || exit 1

    # Prepare secrets file(s) for docker-compose
    # If using multi-secrets (SECRETS_FILES), merge them into a single file
    if [ -n "${SECRETS_FILES:-}" ]; then
        log "INFO" "Merging multiple secrets files..."
        MERGED_SECRETS="${DEPLOYMENT_ROOT}/.env.secrets.${environment}"
        > "$MERGED_SECRETS"  # Create empty file
        for secrets_file in $SECRETS_FILES; do
            if [ -f "$secrets_file" ]; then
                log "INFO" "  - Adding: $secrets_file"
                cat "$secrets_file" >> "$MERGED_SECRETS"
                echo "" >> "$MERGED_SECRETS"  # Add separator
            else
                log "WARNING" "Secrets file not found: $secrets_file"
            fi
        done
        log "INFO" "Merged secrets file: $MERGED_SECRETS ($(wc -l < "$MERGED_SECRETS") lines)"
        export SECRETS_MOUNT_FILE="$MERGED_SECRETS"
    elif [ -n "${SECRETS_FILE:-}" ]; then
        # Single secrets file
        log "INFO" "Using single secrets file: $SECRETS_FILE"
        export SECRETS_MOUNT_FILE="$SECRETS_FILE"
    else
        log "ERROR" "No secrets file available"
        exit 1
    fi

    # Build or pull image
    if [[ "$USE_REMOTE_IMAGE" == "true" ]]; then
        # Login to registry if credentials are provided (for private repos)
        if [ -n "${GHCR_USERNAME:-}" ] && [ -n "${GHCR_PAT:-}" ]; then
            local registry_host=$(get_config "docker.registry.host" "$environment")
            log "INFO" "Authenticating to Docker registry ($registry_host)..."
            echo "$GHCR_PAT" | docker login "$registry_host" -u "$GHCR_USERNAME" --password-stdin > /dev/null 2>&1 || {
                log "WARNING" "Registry authentication failed, attempting pull anyway..."
            }
        fi

        log "INFO" "Removing old image to force latest pull..."
        docker rmi "${FULL_REGISTRY_PATH}:${environment}" 2>/dev/null || true

        log "INFO" "Pulling image from registry: ${FULL_REGISTRY_PATH}:${environment}"
        docker pull "${FULL_REGISTRY_PATH}:${environment}" || {
            log "ERROR" "Failed to pull image"
            exit 1
        }


        # Export image name for docker-compose
        export DOCKER_IMAGE_NAME="${FULL_REGISTRY_PATH}:${environment}"
    elif [[ "$ALLOW_LOCAL_BUILD" == "true" ]]; then
        log "INFO" "Building Docker image locally"

        # Ensure composer dependencies are installed locally ONLY for local environment
        # Local environment uses volume mounts, so it needs vendor/ on the host
        # Staging/production use code baked into Docker image, so they don't need this
        if [[ "$environment" == "local" ]]; then
            log "INFO" "Installing Composer dependencies locally (required for volume mount)..."

            # Navigate to project root
            local project_root="${DEPLOYMENT_ROOT}/.."
            cd "$project_root" || {
                log "ERROR" "Failed to navigate to project root: $project_root"
                exit 1
            }

            # Check if composer is available
            if ! command -v composer &> /dev/null; then
                log "ERROR" "Composer not found. Please install Composer: https://getcomposer.org"
                exit 1
            fi

            # Run composer install with dev dependencies for local
            log "INFO" "Running: composer install --no-interaction --no-scripts (with dev dependencies)"
            composer install --no-interaction --no-scripts || {
                log "ERROR" "Composer install failed"
                exit 1
            }

            log "SUCCESS" "Composer dependencies installed for $environment environment"
        else
            log "INFO" "Skipping host composer install - using dependencies from Docker image"
        fi

        # Install npm dependencies for local development (frontend rebuilding)
        # Production/staging use pre-built assets from Docker image
        if [[ "$environment" == "local" ]]; then
            log "INFO" "Installing npm dependencies for local development..."

            # Check if npm is available
            if ! command -v npm &> /dev/null; then
                log "WARNING" "npm not found. Frontend assets cannot be rebuilt locally."
                log "WARNING" "Install Node.js from https://nodejs.org or use pre-built assets from Docker image"
            else
                log "INFO" "Running: npm install"
                npm install || {
                    log "WARNING" "npm install failed. Frontend may not rebuild correctly."
                }
                log "SUCCESS" "npm dependencies installed"
            fi
        fi

        # Return to deployment root
        cd "${DEPLOYMENT_ROOT}" || exit 1

        # Extract base path from APP_URL_PATH for Vite builds
        # For path-based routing, Vite needs the path + /build
        # For domain-based routing (root /), Vite needs /build/ to resolve dynamic assets correctly
        local base_path="/build/"
        if [[ -n "${APP_URL_PATH}" ]] && [[ "${APP_URL_PATH}" != "/" ]]; then
            # Laravel Vite plugin outputs to public/build/, so base path needs /build suffix
            base_path="${APP_URL_PATH}/build/"
        fi
        log "INFO" "Using base path for assets: ${base_path}"

        # Check if no-cache flag is requested
        local build_flags=""
        if [[ "${DOCKER_BUILD_NO_CACHE:-false}" == "true" ]]; then
            build_flags="--no-cache"
            log "INFO" "Building with --no-cache (fresh build, no layer caching)"
        fi

        # Pass VITE_ variables from secrets as build args
        if [ -f "$SECRETS_MOUNT_FILE" ]; then
            log "INFO" "Extracting VITE_ variables for frontend build..."
            while IFS='=' read -r key value || [ -n "$key" ]; do
                # Trim whitespace and quotes
                key=$(echo "$key" | tr -d '[:space:]')
                value=$(echo "$value" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//")

                if [[ $key == VITE_* ]] && [[ -n "$value" ]]; then
                    log "INFO" "  -> Adding build arg: $key"
                    build_flags+=" --build-arg $key=$value"
                fi
            done < <(grep "^VITE_" "$SECRETS_MOUNT_FILE")
        fi

        # Build with local tag only
        local local_image="${DOCKER_IMAGE_NAME}"
        docker build -t "${local_image}" \
            -f "${DEPLOYMENT_ROOT}/docker/Dockerfile" \
            --build-arg APP_ENV="$environment" \
            --build-arg APP_BASE_PATH="${base_path}" \
            ${build_flags} \
            "${DEPLOYMENT_ROOT}/.." || {
            log "ERROR" "Failed to build image"
            exit 1
        }

        # Export image name for docker-compose
        export DOCKER_IMAGE_NAME="${local_image}"
        log "SUCCESS" "Local build complete: ${DOCKER_IMAGE_NAME}"
    else
        log "ERROR" "Neither remote image pull nor local build is enabled"
        exit 1
    fi

    log "INFO" "Setting up Laravel storage directories..."
    cd "${DEPLOYMENT_ROOT}/.." || {
        log "ERROR" "Failed to navigate to project root"
        exit 1
    }

    # Create all required Laravel directories
    mkdir -p bootstrap/cache \
        storage/app/public \
        storage/app/private \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs

    # Fix ownership to deploying user (important for bind mounts)
    # This ensures the container can write to these directories
    chown -R $(whoami):$(whoami) storage bootstrap 2>/dev/null || true

    # Set permissions (775 allows both user and web server to write)
    chmod -R 775 storage bootstrap/cache 2>/dev/null || true

    log "SUCCESS" "Storage directories configured with correct ownership"

    # Clean up stale frontend build directory to force restoration from image backup
    # This prevents volume-mounted old builds from overriding fresh builds in the Docker image
    # Always do this to ensure we use the assets from the image we just built/pulled
    local build_dir="${DEPLOYMENT_ROOT}/../public/build"
    if [ -d "$build_dir" ]; then
        log "INFO" "Removing stale build directory to ensure fresh assets..."
        rm -rf "$build_dir"
        log "SUCCESS" "Stale build directory removed - container will restore fresh build from image"
    fi

    # === HOOK: post-validation ===
    exec_hook "post-validation"

    # === HOOK: pre-deploy ===
    exec_hook "pre-deploy"

    # Start containers
    log "INFO" "Starting containers with docker-compose"
    ${DOCKER_COMPOSE_CMD} -f "$COMPOSE_FILE" -p "$DOCKER_COMPOSE_PROJECT" up -d || {
        log "ERROR" "Failed to start containers"
        exit 1
    }

    # Wait for application to be healthy
    log "INFO" "Waiting for application to be healthy..."
    sleep 5

    # Construct APP_URL from domain and path (add slash since it was removed above)
    local app_url="https://${APP_URL_DOMAIN}/${APP_URL_PATH}"
    local health_url="/api/health"
    # Health check status (disabled by default in production due to queue workers)
    local health_check_enabled="${HEALTH_CHECK_ENABLED:-false}"
    if [ "$health_check_enabled" = "true" ]; then
        log "INFO" "Health check is enabled, checking $health_url"
    else
        log "INFO" "Health check skipped - verify manually at: ${app_url}${health_url}"
    fi

    # Test database connection (wait for container to fully boot)
    log "INFO" "Testing database connection..."
    sleep 10  # Wait for Laravel to fully bootstrap before testing DB
    # Service name from docker-compose
    local service_name="app"
    if ${DOCKER_COMPOSE_CMD} -f "$COMPOSE_FILE" -p "$DOCKER_COMPOSE_PROJECT" \
        exec -T "$service_name" php artisan db:show >/dev/null 2>&1; then
        log "SUCCESS" "Database connection successful"
    else
        log "WARNING" "Database connection test failed (container may still be booting)"
    fi

    # Print health status
    if docker exec "$DOCKER_CONTAINER_NAME" curl -f http://localhost/api/health &>/dev/null; then
        log "SUCCESS" "Application is healthy!"
    else
        log "WARNING" "Health check returned non-200 status"
    fi

    # === HOOK: post-deploy ===
    exec_hook "post-deploy"

    # Calculate deployment duration
    local end_time=$(date +%s)
    export DEPLOYMENT_DURATION=$((end_time - start_time))

    # === HOOK: on-success ===
    exec_hook "on-success"

    # Fix double slashes in URL
    local app_url="https://${APP_URL_DOMAIN}/${APP_URL_PATH}"
    app_url=$(echo "$app_url" | sed 's#//*#/#g' | sed 's#:/#://#')
    export APP_URL="${app_url}"

    # Check migration status and auto-run if configured
    log "INFO" "Checking migration status..."
    sleep 2
    local migration_status=$(${DOCKER_COMPOSE_CMD} -f "$COMPOSE_FILE" -p "$DOCKER_COMPOSE_PROJECT" \
        exec -T "$service_name" php artisan migrate:status 2>&1 || echo "error")

    local migrations_needed=false
    local migration_message=""
    local auto_migrate=$(get_config "deployment.database.auto_migrate_on_deploy" "$environment")

    if echo "$migration_status" | grep -q "Migration table not found\|No migrations found\|error"; then
        migrations_needed=true
        migration_message="Database migrations have not been run yet"
    elif echo "$migration_status" | grep -q "Pending"; then
        migrations_needed=true
        local pending_count=$(echo "$migration_status" | grep -c "Pending" || echo "0")
        migration_message="$pending_count pending migration(s) detected"
    fi

    # Auto-run migrations if enabled and needed
    if [ "$migrations_needed" = "true" ] && [ "$auto_migrate" = "true" ]; then
        log "INFO" "Auto-running migrations ($environment environment)..."
        if docker exec "$DOCKER_CONTAINER_NAME" php artisan migrate --force 2>&1; then
            log "SUCCESS" "Migrations completed successfully"
            migrations_needed=false
        else
            log "WARNING" "Migrations failed - manual intervention may be required"
        fi
    fi

    # Clear view cache to ensure fresh assets are served
    log "INFO" "Clearing view cache..."
    if docker exec "$DOCKER_CONTAINER_NAME" php artisan view:clear >/dev/null 2>&1; then
        log "SUCCESS" "View cache cleared"
    else
        log "WARNING" "Failed to clear view cache"
    fi

    # === DEPLOYMENT VERIFICATION ===
    log "INFO" "Verifying application deployment..."

    local verification_status="unknown"

    if "${SCRIPT_DIR}/verify-deployment.sh" "$environment"; then
        verification_status="success"
        log "SUCCESS" "Deployment verified successfully"
    else
        verification_status="failure"
        log "WARNING" "Deployment verification failed - check logs for details"
        if [ "${STRICT_VERIFICATION:-false}" = "true" ]; then
             exit 1
        fi
    fi

    # ═══════════════════════════════════════════════════════════════
    # FINAL DEPLOYMENT SUMMARY
    # ═══════════════════════════════════════════════════════════════
    echo ""
    log "SUCCESS" "╔═══════════════════════════════════════════════════════════╗"
    log "SUCCESS" "╔═══════════════════════════════════════════════════════════╗"
    if [ "$migrations_needed" = "true" ] || [ "$verification_status" != "success" ]; then
        log "WARNING" "║       DEPLOYMENT COMPLETE - ACTION REQUIRED               ║"
    else
        log "SUCCESS" "║       DEPLOYMENT COMPLETED SUCCESSFULLY                   ║"
    fi
    log "SUCCESS" "╚═══════════════════════════════════════════════════════════╝"
    echo ""

    # Status overview
    log "INFO" "Environment:     $environment"
    log "INFO" "Duration:        ${DEPLOYMENT_DURATION}s"
    log "INFO" "Container:       $DOCKER_CONTAINER_NAME"
    echo ""
    log "INFO" "Status Overview:"
    log "INFO" "  Container:     $(${DOCKER_COMPOSE_CMD} -f "$COMPOSE_FILE" -p "$DOCKER_COMPOSE_PROJECT" ps | grep -q "Up" && echo "✓ Running" || echo "✗ Stopped")"
    log "INFO" "  Database:      $(docker exec "$DOCKER_CONTAINER_NAME" php artisan db:show &>/dev/null && echo "✓ Connected" || echo "✗ Not connected")"
    log "INFO" "  Verification:  $([ "$verification_status" = "success" ] && echo "✓ Passed" || echo "⚠️  Failed/Warning")"
    if [ "$migrations_needed" = "false" ]; then
        log "INFO" "  Migrations:    ✓ Up to date"
    else
        log "INFO" "  Migrations:    ⚠️  Pending"
    fi

    # Actions required section
    if [ "$migrations_needed" = "true" ]; then
        echo ""
        log "WARNING" "⚠️  ACTION REQUIRED:"
        log "WARNING" "  → Run migrations: docker exec $DOCKER_CONTAINER_NAME php artisan migrate --force"
        echo ""
    fi

    # URLs
    echo ""
    log "INFO" "🌐 Application URLs:"
    log "INFO" "  → API:    ${app_url}"
    echo ""

    # Docker Image Info
    log "INFO" "🐳 Docker Image:"
    log "INFO" "  → Image:  ${DOCKER_IMAGE_NAME}"
    local image_id=$(docker images -q "${DOCKER_IMAGE_NAME}" 2>/dev/null | head -1)
    if [ -n "$image_id" ]; then
        log "INFO" "  → ID:     ${image_id:0:12}"
    fi
    echo ""

    # Quick commands
    log "INFO" "💡 Quick Commands:"
    log "INFO" "  → View logs:     docker logs $DOCKER_CONTAINER_NAME"
    log "INFO" "  → Shell access:  docker exec -it $DOCKER_CONTAINER_NAME bash"
    log "INFO" "  → Check status:  ./deployment/deploy.sh $environment ps"
    echo ""
}

deploy "$@"
