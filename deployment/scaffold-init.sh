#!/usr/bin/env bash
# Enterprise-Grade Deployment Scaffolding Script
# Automatically generates deployment infrastructure for Laravel projects

set -euo pipefail

# Script metadata
VERSION="1.0.0"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Load utility libraries
source "${SCRIPT_DIR}/lib/common.sh"
source "${SCRIPT_DIR}/lib/validators.sh"
source "${SCRIPT_DIR}/lib/template-processor.sh"
source "${SCRIPT_DIR}/lib/url-utils.sh"
source "${SCRIPT_DIR}/lib/traefik-utils.sh"

# Color codes for output
COLOR_RESET='\033[0m'
COLOR_BOLD='\033[1m'
COLOR_GREEN='\033[0;32m'
COLOR_BLUE='\033[0;34m'
COLOR_YELLOW='\033[0;33m'
COLOR_RED='\033[0;31m'
COLOR_CYAN='\033[0;36m'

#######################################
# Print colored message
# Arguments:
#   $1 - Color code
#   $2 - Message
#######################################
print_color() {
    echo -e "${1}${2}${COLOR_RESET}"
}

#######################################
# Print section header
# Arguments:
#   $1 - Header text
#######################################
print_header() {
    echo
    print_color "$COLOR_BOLD$COLOR_CYAN" "═══════════════════════════════════════════════════════════"
    print_color "$COLOR_BOLD$COLOR_CYAN" "  $1"
    print_color "$COLOR_BOLD$COLOR_CYAN" "═══════════════════════════════════════════════════════════"
    echo
}

#######################################
# Print success message
#######################################
print_success() {
    print_color "$COLOR_GREEN" "✅ $1"
}

#######################################
# Print error message
#######################################
print_error() {
    print_color "$COLOR_RED" "❌ $1"
}

#######################################
# Print warning message
#######################################
print_warning() {
    print_color "$COLOR_YELLOW" "⚠️  $1"
}

#######################################
# Print info message
#######################################
print_info() {
    print_color "$COLOR_BLUE" "ℹ️  $1"
}

# Configuration variables
TARGET_PROJECT_DIR=""
DRY_RUN=false
FORCE=false
NON_INTERACTIVE=false

# User input variables (using prefixed variables for Bash 3.2 compatibility)
# Variables will be named CONF_<VARIABLE_NAME>
CONF_KEYS=""

# Default values
DEFAULT_REGISTRY_HOST="ghcr.io"
DEFAULT_REGISTRY_NAMESPACE="$(git config user.name 2>/dev/null || echo 'myorg')"

#######################################
# Show usage information
#######################################
show_usage() {
    cat << EOF
Laravel Deployment Scaffolding Script v${VERSION}

Usage: $(basename "$0") [OPTIONS]

Generate deployment infrastructure for Laravel projects

OPTIONS:
    -t, --target DIR         Target project directory (default: current directory)
    -d, --dry-run           Preview changes without creating files
    -f, --force             Overwrite existing files without prompting
    -n, --non-interactive   Run in non-interactive mode (requires all -v options)
    -h, --help              Show this help message
    -v, --variable KEY=VAL  Set configuration variable (can be used multiple times)

EXAMPLES:
    # Interactive mode (recommended)
    ./scaffold-init.sh

    # Scaffold a specific project
    ./scaffold-init.sh --target /path/to/my-laravel-app

    # Dry run to preview changes
    ./scaffold-init.sh --dry-run

    # Non-interactive mode
    ./scaffold-init.sh --non-interactive \\
        --target /path/to/project \\
        -v PROJECT_NAME=my-api \\
        -v PROJECT_DISPLAY_NAME="My API"

EOF
}

#######################################
# Parse command line arguments
#######################################
parse_args() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            -t|--target)
                TARGET_PROJECT_DIR="$2"
                shift 2
                ;;
            -d|--dry-run)
                DRY_RUN=true
                shift
                ;;
            -f|--force)
                FORCE=true
                shift
                ;;
            -n|--non-interactive)
                NON_INTERACTIVE=true
                shift
                ;;
            -v|--variable)
                # Parse KEY=VALUE
                if [[ "$2" =~ ^([A-Z_]+)=(.*)$ ]]; then
                    local key="${BASH_REMATCH[1]}"
                    local val="${BASH_REMATCH[2]}"

                    # Support old variable names with deprecation warnings
                    case "$key" in
                        STAGING_URL)
                            print_warning "STAGING_URL is deprecated, use STAGING_FULL_URL"
                            key="STAGING_FULL_URL"
                            ;;
                        PRODUCTION_URL)
                            print_warning "PRODUCTION_URL is deprecated, use PRODUCTION_FULL_URL"
                            key="PRODUCTION_FULL_URL"
                            ;;
                        USE_URL_PATHS)
                            print_warning "USE_URL_PATHS is deprecated, use ENABLE_PATH_ROUTING"
                            key="ENABLE_PATH_ROUTING"
                            ;;
                    esac

                    eval "CONF_$key=\"\$val\""
                    CONF_KEYS="$CONF_KEYS $key"
                fi
                shift 2
                ;;
            -h|--help)
                show_usage
                exit 0
                ;;
            *)
                print_error "Unknown option: $1"
                show_usage
                exit 1
                ;;
        esac
    done
}

#######################################
# Prompt user for input with validation
# Arguments:
#   $1 - Variable name
#   $2 - Prompt text
#   $3 - Default value
#   $4 - Validation function
#######################################
prompt_input() {
    local var_name="$1"
    local prompt="$2"
    local default="$3"
    local validator="${4:-}"

    # Check if value already set (e.g. via -v)
    local current_val
    eval "current_val=\"\${CONF_$var_name:-}\""

    # Skip if non-interactive and value already set
    if [[ "$NON_INTERACTIVE" == true ]] && [[ -n "$current_val" ]]; then
        return 0
    fi

    while true; do
        local input=""
        if [[ -n "$default" ]]; then
            read -p "$(print_color "$COLOR_CYAN" "$prompt") [${default}]: " input
            input="${input:-$default}"
        else
            read -p "$(print_color "$COLOR_CYAN" "$prompt"): " input
        fi

        # Validate if validator provided
        if [[ -n "$validator" ]]; then
            if $validator "$input" 2>/tmp/scaffold-error.txt; then
                eval "CONF_$var_name=\"\$input\""
                CONF_KEYS="$CONF_KEYS $var_name"
                break
            else
                cat /tmp/scaffold-error.txt
                continue
            fi
        else
            eval "CONF_$var_name=\"\$input\""
            CONF_KEYS="$CONF_KEYS $var_name"
            break
        fi
    done
}

#######################################
# Collect project configuration from user
#######################################
collect_configuration() {
    print_header "Project Configuration"

    print_info "This wizard will guide you through setting up deployment infrastructure for your Laravel project."
    echo

    # Project name
    prompt_input "PROJECT_NAME" \
        "Project name (kebab-case, e.g., my-api)" \
        "$(basename "${TARGET_PROJECT_DIR}")" \
        validate_project_name

    # Display name
    local default_display="${CONF_PROJECT_NAME}"
    default_display="${default_display//-/ }"  # Replace hyphens with spaces
    # Manual capitalization for Bash 3.2 (since ^ is 4.0+)
    local first_char=$(echo "${default_display:0:1}" | tr '[:lower:]' '[:upper:]')
    default_display="${first_char}${default_display:1}"

    prompt_input "PROJECT_DISPLAY_NAME" \
        "Display name (human-readable)" \
        "$default_display" \
        validate_display_name

    print_header "Project Type Configuration"

    print_info "Please specify your Laravel project type:"
    echo "  📦 laravel-api-only:   API-only project (no frontend builds)"
    echo "  🎨 laravel-fullstack:  Full-stack app with Vue/React/Inertia (requires npm builds)"
    echo

    prompt_input "PROJECT_TYPE" \
        "Project type (laravel-api-only or laravel-fullstack)" \
        "laravel-api-only" \
        validate_project_type

    # Store for later use and set derived values
    if [[ "${CONF_PROJECT_TYPE}" == "laravel-fullstack" ]]; then
        print_info "Full-stack project selected - will include Node.js build setup"
        echo

        # Ask about Node.js version
        prompt_input "NODE_VERSION" \
            "Node.js version for frontend builds" \
            "20" \
            ""

        # Ask about package manager
        prompt_input "PACKAGE_MANAGER" \
            "Package manager (npm or pnpm)" \
            "npm" \
            ""

        CONF_APP_TYPE="fullstack"
        CONF_DOCKERFILE_TEMPLATE="Dockerfile.fullstack.template"
        CONF_KEYS="$CONF_KEYS NODE_VERSION PACKAGE_MANAGER"
    else
        print_info "API-only project selected - no frontend build steps"
        CONF_APP_TYPE="api-only"
        CONF_DOCKERFILE_TEMPLATE="Dockerfile.api.template"
        # Set default values for template processor (won't be used but avoids errors)
        CONF_NODE_VERSION="20"
        CONF_PACKAGE_MANAGER="npm"
    fi

    CONF_KEYS="$CONF_KEYS PROJECT_TYPE APP_TYPE DOCKERFILE_TEMPLATE"
    echo

    print_header "Container Registry Configuration"

    # Registry host
    prompt_input "REGISTRY_HOST" \
        "Container registry host (e.g., ghcr.io, docker.io)" \
        "$DEFAULT_REGISTRY_HOST" \
        validate_registry

    # Registry namespace
    prompt_input "REGISTRY_NAMESPACE" \
        "Registry namespace/organization" \
        "$DEFAULT_REGISTRY_NAMESPACE" \
        ""

    print_header "Environment Configuration"

    print_info "Staging Environment"

    # Staging domain
    prompt_input "STAGING_DOMAIN" \
        "Staging domain" \
        "staging.example.com" \
        validate_domain

    # Staging session domain
    local staging_base_domain
    staging_base_domain=$(echo "${CONF_STAGING_DOMAIN}" | awk -F. '{print $(NF-1)"."$NF}')

    prompt_input "STAGING_SESSION_DOMAIN" \
        "Staging session cookie domain (use leading dot for subdomains)" \
        ".${staging_base_domain}" \
        ""

    print_info "Production Environment"

    # Production domain
    prompt_input "PRODUCTION_DOMAIN" \
        "Production domain" \
        "api.example.com" \
        validate_domain

    # Production session domain
    local prod_base_domain
    prod_base_domain=$(echo "${CONF_PRODUCTION_DOMAIN}" | awk -F. '{print $(NF-1)"."$NF}')

    prompt_input "PRODUCTION_SESSION_DOMAIN" \
        "Production session cookie domain (use leading dot for subdomains)" \
        ".${prod_base_domain}" \
        ""

    # CORS origins for production
    prompt_input "PRODUCTION_CORS_ORIGINS" \
        "Production CORS origins (comma-separated)" \
        "https://${CONF_PRODUCTION_DOMAIN}" \
        ""

    # Sanctum domains for production
    prompt_input "PRODUCTION_SANCTUM_DOMAINS" \
        "Production Sanctum stateful domains (comma-separated)" \
        "${CONF_PRODUCTION_DOMAIN}" \
        ""

    # Determine URL paths for each environment
    print_header "Configuring URL Routing"

    print_info "Determining URL paths for staging and production..."
    echo ""

    # Determine staging URL path
    determine_url_path "STAGING"

    # Determine production URL path
    determine_url_path "PRODUCTION"

    # Add URL path and domain variables to CONF_KEYS
    CONF_KEYS="$CONF_KEYS STAGING_DOMAIN PRODUCTION_DOMAIN STAGING_URL_PATH PRODUCTION_URL_PATH"

    # Determine routing types
    CONF_STAGING_ROUTING_TYPE=$(get_routing_type "$CONF_STAGING_URL_PATH" 6)
    CONF_PRODUCTION_ROUTING_TYPE=$(get_routing_type "$CONF_PRODUCTION_URL_PATH" 12)
    CONF_KEYS="$CONF_KEYS STAGING_ROUTING_TYPE PRODUCTION_ROUTING_TYPE"

    # Set computed Docker variables for docker-compose files
    # Convert project name to Docker-safe format (hyphens to underscores)
    local docker_project_name=$(echo "$CONF_PROJECT_NAME" | tr '-' '_')

    CONF_DOCKER_IMAGE_NAME="${docker_project_name}_staging"  # Will be overridden per env
    CONF_DOCKER_CONTAINER_NAME="dc_${docker_project_name}_staging"  # Will be overridden per env
    CONF_COMPOSE_PROJECT_NAME="${docker_project_name}_staging"  # Will be overridden per env
    CONF_TRAEFIK_SWARM_NETWORK="traefik_swarm_network"
    CONF_ENVIRONMENT="staging"  # Will be overridden per env
    CONF_SECRETS_MOUNT_FILE="../config/staging-secrets.env"  # Will be overridden per env

    CONF_KEYS="$CONF_KEYS DOCKER_IMAGE_NAME DOCKER_CONTAINER_NAME COMPOSE_PROJECT_NAME TRAEFIK_SWARM_NETWORK ENVIRONMENT SECRETS_MOUNT_FILE"

     echo ""
    print_success "Staging routing:    ${CONF_STAGING_ROUTING_TYPE}"
    print_success "Production routing: ${CONF_PRODUCTION_ROUTING_TYPE}"
}

#######################################
# Show configuration summary
#######################################
show_summary() {
    print_header "Configuration Summary"

    cat << EOF
$(print_color "$COLOR_BOLD" "Project:")
  Name:         ${CONF_PROJECT_NAME:-test}
  Display:      ${CONF_PROJECT_DISPLAY_NAME:-Test}
  Type:         ${CONF_APP_TYPE:-api-only}

$(print_color "$COLOR_BOLD" "Registry:")
  Host:         ${CONF_REGISTRY_HOST:-ghcr.io}
  Namespace:    ${CONF_REGISTRY_NAMESPACE:-test}
  Full Image:   ${CONF_REGISTRY_HOST:-ghcr.io}/${CONF_REGISTRY_NAMESPACE:-test}/${CONF_PROJECT_NAME:-test}

$(print_color "$COLOR_BOLD" "URLs:")
  Local:      https://${CONF_APP_DOMAIN:-localhost}:${CONF_APP_PORT:-8000}
  Staging:    https://${CONF_STAGING_DOMAIN:-staging.example.com}
  Production: https://${CONF_PRODUCTION_DOMAIN:-example.com}

$(print_color "$COLOR_BOLD" "Cookies:")
  Staging:    ${CONF_STAGING_SESSION_DOMAIN:-${CONF_STAGING_DOMAIN:-staging.example.com}}
  Production: ${CONF_PRODUCTION_SESSION_DOMAIN:-${CONF_PRODUCTION_DOMAIN:-example.com}}

$(print_color "$COLOR_BOLD" "Staging:")
  Routing Type: ${CONF_STAGING_ROUTING_TYPE:-path-based}
  Domain:       ${CONF_STAGING_DOMAIN:-staging.example.com}
  URL Path:     ${CONF_STAGING_URL_PATH:-(auto-generated)}
  Full URL:     https://${CONF_STAGING_DOMAIN:-staging.example.com}${CONF_STAGING_URL_PATH}

$(print_color "$COLOR_BOLD" "Production:")
  Routing Type: ${CONF_PRODUCTION_ROUTING_TYPE:-domain-based}
  Domain:       ${CONF_PRODUCTION_DOMAIN:-example.com}
  URL Path:     ${CONF_PRODUCTION_URL_PATH:-(none - domain-based)}
  Full URL:     https://${CONF_PRODUCTION_DOMAIN:-example.com}${CONF_PRODUCTION_URL_PATH}
  CORS:         ${CONF_PRODUCTION_CORS_ORIGINS:-*}
  Sanctum:      ${CONF_PRODUCTION_SANCTUM_DOMAINS:-localhost}

$(print_color "$COLOR_BOLD" "Target Directory:")
  ${TARGET_PROJECT_DIR}

EOF
}

#######################################
# Generate deployment files
#######################################
generate_deployment_files() {
    print_header "Generating Deployment Files"

    local template_dir="${SCRIPT_DIR}/templates"

    # Generate Traefik labels BEFORE building vars_args (so they're included)
    print_info "Generating Traefik routing configuration..."

    CONF_STAGING_TRAEFIK_LABELS=$(generate_traefik_router_labels \
        "staging" \
        "$CONF_PROJECT_NAME" \
        "$CONF_STAGING_DOMAIN" \
        "$CONF_STAGING_URL_PATH" 6)

    CONF_PRODUCTION_TRAEFIK_LABELS=$(generate_traefik_router_labels \
        "production" \
        "$CONF_PROJECT_NAME" \
        "$CONF_PRODUCTION_DOMAIN" \
        "$CONF_PRODUCTION_URL_PATH" 12)

    CONF_KEYS="$CONF_KEYS STAGING_TRAEFIK_LABELS PRODUCTION_TRAEFIK_LABELS"

    print_success "Generated Traefik labels for ${CONF_STAGING_ROUTING_TYPE} (staging)"
    print_success "Generated Traefik labels for ${CONF_PRODUCTION_ROUTING_TYPE} (production)"

    # Prepare variable arguments for template processor
    local vars_args=()
    # Filter unique keys from CONF_KEYS
    local unique_keys=$(echo "$CONF_KEYS" | tr ' ' '\n' | sort -u | tr '\n' ' ')
    for key in $unique_keys; do
        if [[ -n "$key" ]]; then
            local val
            eval "val=\"\${CONF_$key}\""
            vars_args+=("${key}=${val}")
        fi
    done

    # Create deployment directory
    local deploy_dir="${TARGET_PROJECT_DIR}/deployment"
    if [[ "$DRY_RUN" == false ]]; then
        mkdir -p "$deploy_dir"
        mkdir -p "$deploy_dir/docker"
        mkdir -p "$deploy_dir/scripts"
        mkdir -p "$deploy_dir/config"
        mkdir -p "$deploy_dir/templates"
        mkdir -p "$deploy_dir/lib"
    fi

    # Process main configuration file
    print_info "Generating deployment.config.yml..."
    if [[ "$DRY_RUN" == false ]]; then
        process_template \
            "${template_dir}/deployment.config.yml.template" \
            "${deploy_dir}/deployment.config.yml" \
            "${vars_args[@]}"
        print_success "Created deployment/deployment.config.yml"
    else
        print_warning "[DRY RUN] Would create deployment/deployment.config.yml"
    fi

    # Generate local-secrets.env
    print_info "Generating local-secrets.env..."
    if [[ "$DRY_RUN" == false ]]; then
        if [[ -f "${template_dir}/local-secrets.env.template" ]]; then
            process_template \
                "${template_dir}/local-secrets.env.template" \
                "${deploy_dir}/config/local-secrets.env" \
                "${vars_args[@]}"
            print_success "Created deployment/config/local-secrets.env"
        else
            print_warning "local-secrets.env template not found - skipping"
        fi
    else
        print_warning "[DRY RUN] Would create deployment/config/local-secrets.env"
    fi

    # Generate Traefik labels for docker-compose files
    print_info "Generating Traefik routing configuration..."

    CONF_STAGING_TRAEFIK_LABELS=$(generate_traefik_router_labels \
        "staging" \
        "$CONF_PROJECT_NAME" \
        "$CONF_STAGING_DOMAIN" \
        "$CONF_STAGING_URL_PATH" 6)

    CONF_PRODUCTION_TRAEFIK_LABELS=$(generate_traefik_router_labels \
        "production" \
        "$CONF_PROJECT_NAME" \
        "$CONF_PRODUCTION_DOMAIN" \
        "$CONF_PRODUCTION_URL_PATH" 12)

    CONF_KEYS="$CONF_KEYS STAGING_TRAEFIK_LABELS PRODUCTION_TRAEFIK_LABELS"

    print_success "Generated Traefik labels for ${CONF_STAGING_ROUTING_TYPE} (staging)"
    print_success "Generated Traefik labels for ${CONF_PRODUCTION_ROUTING_TYPE} (production)"

    # Process Docker Compose files
    for env in local staging production; do
        print_info "Generating docker-compose.${env}.yml..."
        if [[ "$DRY_RUN" == false ]]; then
            if [[ -f "${template_dir}/docker-compose.${env}.yml.template" ]]; then
                process_template \
                    "${template_dir}/docker-compose.${env}.yml.template" \
                    "${deploy_dir}/docker/docker-compose.${env}.yml" \
                    "${vars_args[@]}"
                print_success "Created deployment/docker/docker-compose.${env}.yml"
            else
                print_warning "docker-compose.${env}.yml template not found - skipping"
            fi
        else
            print_warning "[DRY RUN] Would create deployment/docker/docker-compose.${env}.yml"
        fi
    done

    # Copy Dockerfile (select correct template based on project type)
    print_info "Generating Dockerfile..."
    if [[ "$DRY_RUN" == false ]]; then
        local dockerfile_template="${CONF_DOCKERFILE_TEMPLATE:-Dockerfile.api.template}"

        if [[ -f "${template_dir}/${dockerfile_template}" ]]; then
            process_template \
                "${template_dir}/${dockerfile_template}" \
                "${deploy_dir}/docker/Dockerfile" \
                "${vars_args[@]}"
            print_success "Created deployment/docker/Dockerfile (from ${dockerfile_template})"
        else
            print_error "Template not found: ${dockerfile_template}"
            exit 1
        fi
    else
        print_warning "[DRY RUN] Would create deployment/docker/Dockerfile"
    fi

    # Copy .dockerignore template (critical for preventing Pail errors)
    print_info "Generating .dockerignore..."
    if [[ "$DRY_RUN" == false ]]; then
        if [[ -f "${template_dir}/.dockerignore.template" ]]; then
            cp "${template_dir}/.dockerignore.template" "${TARGET_PROJECT_DIR}/.dockerignore"
            print_success "Created .dockerignore"
        else
            print_warning ".dockerignore template not found - skipping"
        fi
    else
        print_warning "[DRY RUN] Would create .dockerignore"
    fi

    # Copy supervisord.conf template (for fullstack projects)
    if [[ "${CONF_APP_TYPE}" == "fullstack" ]]; then # Changed from CONF_PROJECT_TYPE to CONF_APP_TYPE for consistency
        print_info "Generating supervisord.conf..."
        if [[ "$DRY_RUN" == false ]]; then
            if [[ -f "${SCRIPT_DIR}/docker/supervisord.conf" ]]; then
                cp "${SCRIPT_DIR}/docker/supervisord.conf" "${deploy_dir}/docker/supervisord.conf"
                print_success "Created deployment/docker/supervisord.conf"
            else
                print_warning "supervisord.conf source not found - skipping"
            fi
        else
            print_warning "[DRY RUN] Would create deployment/docker/supervisord.conf"
        fi

        # Copy nginx configuration for fullstack
        print_info "Generating nginx configuration..."
        if [[ "$DRY_RUN" == false ]]; then
            mkdir -p "${deploy_dir}/docker/nginx"
            if [[ -f "${template_dir}/docker/nginx/default.conf.template" ]]; then
                cp "${template_dir}/docker/nginx/default.conf.template" "${deploy_dir}/docker/nginx/default.conf"
                print_success "Created deployment/docker/nginx/default.conf"
            else
                print_warning "nginx default.conf template not found - skipping"
            fi
        else
            print_warning "[DRY RUN] Would create deployment/docker/nginx/default.conf"
        fi

        # Configure Laravel for proxy support (fullstack apps behind Traefik)
        print_info "Configuring Laravel proxy support..."
        if [[ "$DRY_RUN" == false ]]; then
            # Copy TrustProxies middleware if it doesn't exist
            if [[ ! -f "${TARGET_PROJECT_DIR}/app/Http/Middleware/TrustProxies.php" ]]; then
                mkdir -p "${TARGET_PROJECT_DIR}/app/Http/Middleware"
                if [[ -f "${template_dir}/laravel-stubs/TrustProxies.php.stub" ]]; then
                    cp "${template_dir}/laravel-stubs/TrustProxies.php.stub" \
                       "${TARGET_PROJECT_DIR}/app/Http/Middleware/TrustProxies.php"
                    print_success "Created app/Http/Middleware/TrustProxies.php"
                fi
            else
                print_info "TrustProxies middleware already exists - skipping"
            fi

            # Check if AppServiceProvider needs update
            needs_update=false
            if [[ -f "${TARGET_PROJECT_DIR}/app/Providers/AppServiceProvider.php" ]]; then
                if ! grep -q "forceRootUrl" "${TARGET_PROJECT_DIR}/app/Providers/AppServiceProvider.php" 2>/dev/null; then
                    needs_update=true
                fi
            fi

            # Check if bootstrap/app.php needs update
            needs_bootstrap_update=false
            if [[ -f "${TARGET_PROJECT_DIR}/bootstrap/app.php" ]]; then
                if ! grep -q "trustProxies" "${TARGET_PROJECT_DIR}/bootstrap/app.php" 2>/dev/null; then
                    needs_bootstrap_update=true
                fi
            fi

            # Show warnings if manual updates needed
            if [[ "$needs_update" == true ]] || [[ "$needs_bootstrap_update" == true ]]; then
                echo ""
                print_warning "⚠️  MANUAL LARAVEL CONFIGURATION REQUIRED"
                print_info "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
                print_info "Your fullstack Laravel app needs proxy configuration to"
                print_info "work correctly behind Traefik. See detailed instructions:"
                print_info ""
                print_info "  📄 ${deploy_dir}/templates/laravel-stubs/README.md"
                print_info ""
                if [[ "$needs_update" == true ]]; then
                    print_info "  → Update app/Providers/AppServiceProvider.php"
                fi
                if [[ "$needs_bootstrap_update" == true ]]; then
                    print_info "  → Update bootstrap/app.php"
                fi
                print_info ""
                print_info "Full docs: ${deploy_dir}/docs/LARAVEL_PROXY_SETUP.md"
                print_info "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
                echo ""
            fi
        fi
    fi

    # Create .github/workflows directory
    local github_dir="${TARGET_PROJECT_DIR}/.github/workflows"
    if [[ "$DRY_RUN" == false ]]; then
        mkdir -p "$github_dir"
    fi

    # Process GitHub Actions workflows
    print_info "Generating GitHub Actions workflows..."
    if [[ "$DRY_RUN" == false ]]; then
        process_template \
            "${template_dir}/build-and-push.yml.template" \
            "${github_dir}/build-and-push.yml" \
            "${vars_args[@]}"
        print_success "Created .github/workflows/build-and-push.yml"

        process_template \
            "${template_dir}/deploy.yml.template" \
            "${github_dir}/deploy.yml" \
            "${vars_args[@]}"
        print_success "Created .github/workflows/deploy.yml"
    else
        print_warning "[DRY RUN] Would create .github/workflows/build-and-push.yml"
        print_warning "[DRY RUN] Would create .github/workflows/deploy.yml"
    fi

    # Copy supporting files (no template processing needed)
    print_info "Copying supporting files..."
    if [[ "$DRY_RUN" == false ]]; then
        # Copy .env template
        cp "${template_dir}/.env.template" "${deploy_dir}/templates/"
        print_success "Created deployment/templates/.env.template"



        # Copy deployment scripts from current deployment
        cp -r "${SCRIPT_DIR}/scripts/"* "${deploy_dir}/scripts/"
        print_success "Copied deployment scripts"

        # Copy features directory
        if [[ -d "${SCRIPT_DIR}/features" ]]; then
            cp -r "${SCRIPT_DIR}/features" "${deploy_dir}/"
            print_success "Copied deployment features"
        fi

        # Copy docker scripts
        mkdir -p "${deploy_dir}/docker/scripts"
        cp "${SCRIPT_DIR}/docker/scripts/"* "${deploy_dir}/docker/scripts/"
        print_success "Copied Docker scripts"

        # Copy docker config files
        if [[ -d "${SCRIPT_DIR}/docker/config" ]]; then
            cp -r "${SCRIPT_DIR}/docker/config" "${deploy_dir}/docker/"
        fi
        print_success "Copied Docker configuration"

        # Copy utilities
        cp "${SCRIPT_DIR}/lib/"* "${deploy_dir}/lib/"
        print_success "Copied utility libraries"

        # Copy this scaffolding script
        cp "${SCRIPT_DIR}/scaffold-init.sh" "${deploy_dir}/"
        chmod +x "${deploy_dir}/scaffold-init.sh"
        print_success "Copied scaffolding script"

        # Create deploy.sh wrapper
        cat > "${deploy_dir}/deploy.sh" << 'EOF'
#!/usr/bin/env bash
# Deployment wrapper script
exec "$(dirname "$0")/scripts/deploy.sh" "$@"
EOF
        chmod +x "${deploy_dir}/deploy.sh"
        print_success "Created deployment/deploy.sh"
    fi
}

#######################################
# Show post-installation instructions
#######################################
show_next_steps() {
    print_header "Next Steps"

    cat << EOF
$(print_success "Deployment infrastructure has been generated successfully!")

$(print_color "$COLOR_BOLD" "1. Review Generated Files")
   - deployment/deployment.config.yml
   - .github/workflows/build-and-push.yml
   - .github/workflows/deploy.yml

$(print_color "$COLOR_BOLD" "2. Create Local Secrets File")
   cd ${TARGET_PROJECT_DIR}/deployment
   mkdir -p config
   cat > config/local-secrets.env << 'SECRETS'
DB_HOST=host.docker.internal
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=root
DB_PASSWORD=password
APP_KEY=
SECRETS

$(print_color "$COLOR_BOLD" "3. Configure GitHub Secrets")
   Add these secrets to your GitHub repository:
   - SSH_PRIVATE_KEY
   - SERVER_USER
   - SERVER_HOST
   - STAGING_DEPLOY_PATH
   - PRODUCTION_DEPLOY_PATH



$(print_color "$COLOR_BOLD" "5. Test Local Deployment")
   cd ${TARGET_PROJECT_DIR}/deployment
   ./deploy.sh local deploy

$(print_color "$COLOR_BOLD" "6. Build and Deploy")
   # Build Docker image
   gh workflow run build-and-push.yml -f environment=staging

   # Deploy to staging
   gh workflow run deploy.yml \\
     -f environment=staging \\
     -f action=deploy \\
     -f build_mode=remote

$(print_color "$COLOR_BOLD" "📚 Documentation")
   Full documentation: deployment/README.md
   Scaffolding guide: deployment/SCAFFOLDING.md

EOF
}

#######################################
# Main function
#######################################
main() {
    # Parse arguments
    parse_args "$@"

    # Set default target directory
    if [[ -z "$TARGET_PROJECT_DIR" ]]; then
        TARGET_PROJECT_DIR="$(pwd)"
    fi

    # Resolve to absolute path
    TARGET_PROJECT_DIR="$(cd "$TARGET_PROJECT_DIR" && pwd)"

    # Show banner
    print_header "Laravel Deployment Scaffolding v${VERSION}"

    # Verify target is a Laravel project
    if [[ ! -f "${TARGET_PROJECT_DIR}/artisan" ]]; then
        print_error "Target directory does not appear to be a Laravel project"
        print_error "Missing artisan file: ${TARGET_PROJECT_DIR}/artisan"
        exit 1
    fi

    print_success "Detected Laravel project in: ${TARGET_PROJECT_DIR}"

    # Collect configuration
    if [[ "$NON_INTERACTIVE" == false ]]; then
        collect_configuration
    else
        # Check required variables (allow FULL_URL as alternative to DOMAIN)
        local required_vars="PROJECT_NAME PROJECT_DISPLAY_NAME REGISTRY_HOST REGISTRY_NAMESPACE"

        for var in $required_vars; do
            local val
            eval "val=\"\${CONF_$var:-}\""
            if [[ -z "$val" ]]; then
                print_error "Missing required variable in non-interactive mode: $var"
                exit 1
            fi
        done

        # Check STAGING: either DOMAIN or FULL_URL must be provided
        if [[ -z "${CONF_STAGING_DOMAIN:-}" ]] && [[ -z "${CONF_STAGING_FULL_URL:-}" ]]; then
            print_error "Missing required variable in non-interactive mode: STAGING_DOMAIN or STAGING_FULL_URL"
            exit 1
        fi

        # Check PRODUCTION: either DOMAIN or FULL_URL must be provided
        if [[ -z "${CONF_PRODUCTION_DOMAIN:-}" ]] && [[ -z "${CONF_PRODUCTION_FULL_URL:-}" ]]; then
            print_error "Missing required variable in non-interactive mode: PRODUCTION_DOMAIN or PRODUCTION_FULL_URL"
            exit 1
        fi



        #  Determine URL paths if not already set
        if [[ -z "${CONF_STAGING_URL_PATH:-}" ]] && [[ -z "${CONF_STAGING_FULL_URL:-}" ]]; then
            determine_url_path "STAGING"
        fi
        if [[ -z "${CONF_PRODUCTION_URL_PATH:-}" ]] && [[ -z "${CONF_PRODUCTION_FULL_URL:-}" ]]; then
            determine_url_path "PRODUCTION"
        fi

        # Parse FULL_URLs if provided
        if [[ -n "${CONF_STAGING_FULL_URL:-}" ]]; then
            parse_full_url "${CONF_STAGING_FULL_URL}" "STAGING"
        fi
        if [[ -n "${CONF_PRODUCTION_FULL_URL:-}" ]]; then
            parse_full_url "${CONF_PRODUCTION_FULL_URL}" "PRODUCTION"
        fi

        # Add URL path and domain variables to CONF_KEYS
        CONF_KEYS="$CONF_KEYS STAGING_DOMAIN PRODUCTION_DOMAIN STAGING_URL_PATH PRODUCTION_URL_PATH"

        # Determine routing types
        CONF_STAGING_ROUTING_TYPE=$(get_routing_type "${CONF_STAGING_URL_PATH:-}")
        CONF_PRODUCTION_ROUTING_TYPE=$(get_routing_type "${CONF_PRODUCTION_URL_PATH:-}")
        CONF_KEYS="$CONF_KEYS STAGING_ROUTING_TYPE PRODUCTION_ROUTING_TYPE"

        # Set derived values based on PROJECT_TYPE (for non-interactive mode)
        if [[ "${CONF_PROJECT_TYPE:-laravel-api-only}" == "laravel-fullstack" ]]; then
            CONF_APP_TYPE="fullstack"
            CONF_DOCKERFILE_TEMPLATE="Dockerfile.fullstack.template"
            # Set defaults if not provided
            if [[ -z "${CONF_NODE_VERSION:-}" ]]; then
                CONF_NODE_VERSION="20"
                CONF_KEYS="$CONF_KEYS NODE_VERSION"
            fi
            if [[ -z "${CONF_PACKAGE_MANAGER:-}" ]]; then
                CONF_PACKAGE_MANAGER="npm"
                CONF_KEYS="$CONF_KEYS PACKAGE_MANAGER"
            fi
        else
            CONF_APP_TYPE="api-only"
            CONF_DOCKERFILE_TEMPLATE="Dockerfile.api.template"
            # Set defaults to avoid template errors
            CONF_NODE_VERSION="20"
            CONF_PACKAGE_MANAGER="npm"
        fi

        CONF_KEYS="$CONF_KEYS APP_TYPE DOCKERFILE_TEMPLATE"
    fi

    # Show summary
    show_summary

    # Confirm before proceeding
    if [[ "$DRY_RUN" == false ]] && [[ "$NON_INTERACTIVE" == false ]]; then
        echo
        printf "$(print_color "$COLOR_YELLOW" "Proceed with generating files? (y/N): ")"
        read -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            print_warning "Aborted by user"
            exit 0
        fi
    fi

    # Generate files
    generate_deployment_files

    # Show next steps
    if [[ "$DRY_RUN" == false ]]; then
        show_next_steps
    else
        echo
        print_warning "DRY RUN MODE - No files were created"
        print_info "Run without --dry-run to generate files"
    fi

    echo
    print_success "Scaffolding complete!"
}

# Run main function
main "$@"
