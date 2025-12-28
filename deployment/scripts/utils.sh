#!/usr/bin/env bash
# Core utility functions for deployment
# This file is sourced by all deployment scripts

set -euo pipefail

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOYMENT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

#######################################
# Logging function
# Arguments:
#   $1 - Log level (INFO, ERROR, WARNING, SUCCESS)
#   $2 - Message
#######################################
log() {
    local level="$1"
    local message="$2"
    local timestamp
    timestamp=$(date '+%Y-%m-%d %H:%M:%S')

    case "$level" in
        INFO)
            echo -e "${BLUE}[INFO]${NC} ${timestamp} - ${message}"
            ;;
        ERROR)
            echo -e "${RED}[ERROR]${NC} ${timestamp} - ${message}" >&2
            ;;
        WARNING)
            echo -e "${YELLOW}[WARNING]${NC} ${timestamp} - ${message}"
            ;;
        SUCCESS)
            echo -e "${GREEN}[SUCCESS]${NC} ${timestamp} - ${message}"
            ;;
        *)
            echo "${timestamp} - ${message}"
            ;;
    esac
}

#######################################
# Plain log without timestamp (for copy-paste friendly output)
# Arguments:
#   $1 - Log level (INFO, ERROR, WARNING, SUCCESS)
#   $2 - Message
#######################################
log_plain() {
    local level="$1"
    local message="$2"

    case "$level" in
        INFO)
            echo -e "${BLUE}[INFO]${NC} ${message}"
            ;;
        ERROR)
            echo -e "${RED}[ERROR]${NC} ${message}" >&2
            ;;
        WARNING)
            echo -e "${YELLOW}[WARNING]${NC} ${message}"
            ;;
        SUCCESS)
            echo -e "${GREEN}[SUCCESS]${NC} ${message}"
            ;;
        *)
            echo "$message"
            ;;
    esac
}

#######################################
# Load configuration from YAML file
# Arguments:
#   $1 - Environment (local, staging, production)
#######################################
load_config() {
    local environment="$1"
    local config_file="${DEPLOYMENT_ROOT}/deployment.config.yml"

    if [[ ! -f "$config_file" ]]; then
        log "ERROR" "Configuration file not found: $config_file"
        exit 1
    fi

    log "INFO" "Loading configuration from: $config_file"

    # Try to use yq if available (preferred)
    if command -v yq &> /dev/null; then
        log "INFO" "Using yq for YAML parsing"
        export CONFIG_PARSER="yq"
    # Try python3 with PyYAML
    elif command -v python3 &> /dev/null && python3 -c "import yaml" 2>/dev/null; then
        log "INFO" "Using python3 for YAML parsing"
        export CONFIG_PARSER="python3"
    else
        log "WARNING" "Using basic grep/sed for YAML parsing (limited functionality)"
        export CONFIG_PARSER="basic"
    fi

    export CONFIG_FILE="$config_file"
    export ENVIRONMENT="$environment"
}

#######################################
# Get configuration value
# Arguments:
#   $1 - Configuration key path (e.g., "project.name")
#   $2 - Environment (optional, uses global ENVIRONMENT if not provided)
#######################################
get_config() {
    local key="$1"
    local env="${2:-${ENVIRONMENT:-}}"
    local value=""

    # If CONFIG_PARSER not initialized yet, return empty
    if [ -z "${CONFIG_PARSER:-}" ]; then
        echo ""
        return 0
    fi

    case "$CONFIG_PARSER" in
        yq)
            # Try environment-specific override first
            value=$(yq eval ".environments.${env}.${key} // .${key}" "$CONFIG_FILE" 2>/dev/null || echo "")
            ;;
        python3)
            value=$(python3 -c "
import yaml, sys
with open('$CONFIG_FILE') as f:
    config = yaml.safe_load(f)
try:
    # Try environment-specific first
    keys = '$key'.split('.')
    val = config.get('environments', {}).get('$env', {})
    for k in keys:
        val = val.get(k, None)
        if val is None:
            break
    if val is None:
        # Fall back to global
        val = config
        for k in keys:
            val = val.get(k, None)
            if val is None:
                break
    # Convert boolean to lowercase string for shell compatibility
    if isinstance(val, bool):
        val = str(val).lower()
    print(val if val is not None else '')
except:
    print('')
" 2>/dev/null || echo "")
            ;;
        basic)
            # Basic grep/sed fallback
            value=$(grep -A1 "^${key}:" "$CONFIG_FILE" | tail -1 | sed 's/^[[:space:]]*//' || echo "")
            ;;
    esac

    echo "$value"
}

#######################################
# Apply placeholder substitutions
# Arguments:
#   $1 - String with placeholders
#######################################
apply_placeholders() {
    local input="$1"

    # Replace common placeholders
    input="${input//\{project_name\}/${PROJECT_NAME}}"
    input="${input//\{env\}/${ENVIRONMENT}}"
    input="${input//\{base_path\}/${INFRASTRUCTURE_BASE_PATH}}"
    input="${input//\{secrets_base_path\}/${INFRASTRUCTURE_SECRETS_PATH}}"
    input="${input//\{traefik_network\}/${TRAEFIK_NETWORK}}"

    echo "$input"
}

#######################################
# Setup environment variables
# Arguments:
#   $1 - Environment name
#######################################
setup_environment() {
    local env="$1"

    log "INFO" "Setting up environment: $env"

    # Detect Docker Compose command (v1 vs v2)
    if command -v docker-compose &> /dev/null; then
        export DOCKER_COMPOSE_CMD="docker-compose"
        log "INFO" "Using Docker Compose v1: docker-compose"
    elif docker compose version &> /dev/null; then
        export DOCKER_COMPOSE_CMD="docker compose"
        log "INFO" "Using Docker Compose v2: docker compose"
    else
        log "ERROR" "Docker Compose not found (neither 'docker-compose' nor 'docker compose')"
        exit 1
    fi

    # Project configuration
    export PROJECT_NAME=$(get_config "project.name" "$env")
    export PROJECT_DISPLAY_NAME=$(get_config "project.display_name" "$env")

    # Infrastructure
    export INFRASTRUCTURE_BASE_PATH=$(get_config "infrastructure.base_path" "$env")
    export INFRASTRUCTURE_SECRETS_PATH=$(get_config "infrastructure.secrets_base_path" "$env")
    export TRAEFIK_NETWORK=$(get_config "infrastructure.traefik.network_name" "$env")

    # Docker configuration - read patterns from config
    local container_pattern=$(get_config "docker.container_name_pattern" "$env")
    local image_pattern=$(get_config "docker.image_name_pattern" "$env")
    local compose_pattern=$(get_config "docker.compose_project_pattern" "$env")

    # Convert project name hyphens to underscores for Docker compatibility
    local docker_project_name=$(echo "$PROJECT_NAME" | tr '-' '_')

    # Build names from patterns
    export DOCKER_CONTAINER_NAME=$(echo "$container_pattern" | sed "s/{project_name}/${docker_project_name}/g" | sed "s/{env}/${env}/g")
    export DOCKER_IMAGE_NAME=$(echo "$image_pattern" | sed "s/{project_name}/${docker_project_name}/g" | sed "s/{env}/${env}/g")
    export DOCKER_COMPOSE_PROJECT=$(echo "$compose_pattern" | sed "s/{project_name}/${docker_project_name}/g" | sed "s/{env}/${env}/g")

    # Docker registry
    export REGISTRY_HOST=$(get_config "docker.registry.host" "$env")
    export REGISTRY_NAMESPACE=$(get_config "docker.registry.namespace" "$env")
    export REGISTRY_IMAGE=$(get_config "docker.registry.image" "$env")
    export FULL_REGISTRY_PATH="${REGISTRY_HOST}/${REGISTRY_NAMESPACE}/${REGISTRY_IMAGE}"

    # Health check configuration
    export HEALTH_ENDPOINT=$(get_config "health.endpoint" "$env")
    export HEALTH_TIMEOUT=$(get_config "health.timeout" "$env")
    export HEALTH_MAX_ATTEMPTS=$(get_config "health.max_attempts" "$env")

    # Deployment configuration
    export USE_REMOTE_IMAGE=$(get_config "deployment.image.use_remote" "$env")
    export ALLOW_LOCAL_BUILD=$(get_config "deployment.image.allow_local_build" "$env")

    # Domain configuration
    export APP_DOMAIN=$(get_config "docker.url_domain" "$env")
    export APP_URL_PATH=$(get_config "docker.url_path" "$env")

    # Secrets files - support both array (secrets_files) and single (secrets_file)
    # First try to get secrets_files array
    local secrets_files_raw=$(get_config "docker.secrets_files" "$env")

    if [ -n "$secrets_files_raw" ] && [ "$secrets_files_raw" != "null" ] && [ "$secrets_files_raw" != "None" ]; then
        # Parse the list output from Python (format: ['file1', 'file2'])
        # Convert Python list to space-separated file paths
        SECRETS_FILES=$(echo "$secrets_files_raw" | sed "s/\[//g; s/\]//g; s/'//g; s/,/ /g" | xargs)
        if [ -n "$SECRETS_FILES" ]; then
            # Apply placeholders to each file
            local expanded_files=""
            for file in $SECRETS_FILES; do
                expanded_file=$(apply_placeholders "$file")
                expanded_files="$expanded_files $expanded_file"
            done
            export SECRETS_FILES=$(echo "$expanded_files" | xargs)  # Trim spaces

            # Load GHCR credentials from first secrets file
            first_secrets=$(echo "$SECRETS_FILES" | awk '{print $1}')
            if [ -f "$first_secrets" ]; then
                export GHCR_USERNAME=$(grep '^GHCR_USERNAME=' "$first_secrets" 2>/dev/null | cut -d'=' -f2- | tr -d '"' | xargs)
                export GHCR_PAT=$(grep '^GHCR_PAT=' "$first_secrets" 2>/dev/null | cut -d'=' -f2- | tr -d '"' | xargs)
            fi
        fi
    else
        # Fallback to single secrets_file
        local secrets_file=$(get_config "docker.secrets_file" "$env")
        if [ -n "$secrets_file" ] && [ "$secrets_file" != "null" ] && [ "$secrets_file" != "None" ]; then
            export SECRETS_FILE=$(apply_placeholders "$secrets_file")

            # Load GHCR credentials from single secrets file
            if [ -f "$SECRETS_FILE" ]; then
                export GHCR_USERNAME=$(grep '^GHCR_USERNAME=' "$SECRETS_FILE" 2>/dev/null | cut -d'=' -f2- | tr -d '"' | xargs)
                export GHCR_PAT=$(grep '^GHCR_PAT=' "$SECRETS_FILE" 2>/dev/null | cut -d'=' -f2- | tr -d '"' | xargs)
            fi
        fi
    fi

    # Compose file
    export COMPOSE_FILE="${DEPLOYMENT_ROOT}/docker/docker-compose.${env}.yml"

    log "SUCCESS" "Environment setup complete"
    log "INFO" "Project: ${PROJECT_NAME} (${env})"
    log "INFO" "Container: ${DOCKER_CONTAINER_NAME}"
    log "INFO" "Registry: ${FULL_REGISTRY_PATH}"
}

#######################################
# Wait for container health check
# Arguments:
#   $1 - Container name
#   $2 - Health endpoint URL
#######################################
wait_for_health() {
    local container="$1"
    local health_url="$2"
    local max_attempts="${HEALTH_MAX_ATTEMPTS:-30}"
    local timeout="${HEALTH_TIMEOUT:-30}"
    local interval=2
    local attempt=0

    log "INFO" "Waiting for health check at: $health_url"
    log "INFO" "Max attempts: $max_attempts, Timeout: ${timeout}s"

    while [[ $attempt -lt $max_attempts ]]; do
        attempt=$((attempt + 1))

        # Check if container is running
        if ! docker ps --format '{{.Names}}' | grep -q "^${container}$"; then
            log "ERROR" "Container $container is not running"
            return 1
        fi

        # Try health check
        if docker exec "$container" curl -sf "http://localhost:9000$health_url" > /dev/null 2>&1; then
            log "SUCCESS" "Health check passed on attempt $attempt"
            return 0
        fi

        log "INFO" "Health check attempt $attempt/$max_attempts failed, retrying in ${interval}s..."
        sleep "$interval"
    done

    log "ERROR" "Health check failed after $max_attempts attempts"
    return 1
}

#######################################
# Test database connection
# Arguments:
#   $1 - Container name
#######################################
test_database() {
    local container="$1"

    log "INFO" "Testing database connection..."

    if docker exec "$container" php artisan migrate:status > /dev/null 2>&1; then
        log "SUCCESS" "Database connection successful"
        return 0
    else
        log "ERROR" "Database connection failed"
        return 1
    fi
}

#######################################
# Get container logs
# Arguments:
#   $1 - Container name
#   $2... - Additional docker logs arguments
#######################################
get_logs() {
    local container="$1"
    shift

    docker logs "$container" "$@"
}

#######################################
# Check if Docker is available
#######################################
check_docker() {
    if ! command -v docker &> /dev/null; then
        log "ERROR" "Docker is not installed or not in PATH"
        return 1
    fi

    if ! docker info > /dev/null 2>&1; then
        log "ERROR" "Docker daemon is not running"
        return 1
    fi

    log "SUCCESS" "Docker is available"
    return 0
}

#######################################
# Export all functions for subshells
#######################################
export -f log
export -f load_config
export -f get_config
export -f apply_placeholders
export -f setup_environment
export -f wait_for_health
export -f test_database
export -f get_logs
export -f check_docker
