#!/usr/bin/env bash
# URL parsing and path determination utilities for scaffold-init.sh

set -euo pipefail

#######################################
# Generate secure random token
# Arguments:
#   $1 - Length (default: 40)
# Outputs:
#   Random alphanumeric string
#######################################
generate_secure_token() {
    local length="${1:-40}"
    LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c "$length"
}

#######################################
# Parse full URL into components
# Arguments:
#   $1 - Full URL (e.g., "https://domain.com/path")
#   $2 - Variable prefix ("STAGING" or "PRODUCTION")
# Sets:
#   CONF_{prefix}_DOMAIN
#   CONF_{prefix}_URL_PATH
#   CONF_{prefix}_PROTOCOL
#######################################
parse_full_url() {
    local url="$1"
    local var_prefix="$2"

    # Extract protocol (http or https)
    local protocol
    protocol=$(echo "$url" | sed -E 's|(https?)://.*|\1|')

    # Extract domain and path
    local domain_and_path
    domain_and_path=$(echo "$url" | sed -E 's|https?://||')

    local domain
    domain=$(echo "$domain_and_path" | cut -d'/' -f1)

    local path
    path=$(echo "$domain_and_path" | sed -E "s|^${domain}||")

    # Normalize path
    if [[ "$path" == "/" ]] || [[ -z "$path" ]]; then
        path=""  # Domain-based routing
    else
        path="${path%/}"  # Remove trailing slash
        [[ "$path" != /* ]] && path="/$path"  # Ensure leading /
    fi

    # Set variables
    eval "CONF_${var_prefix}_DOMAIN=\"${domain}\""
    eval "CONF_${var_prefix}_URL_PATH=\"${path}\""
    eval "CONF_${var_prefix}_PROTOCOL=\"${protocol}\""

    print_info "Parsed ${var_prefix}_FULL_URL:"
    print_info "  Domain: ${domain}"
    print_info "  Path: ${path:-'(none - domain-based routing)'}"
}

#######################################
# Determine URL path based on priority
# Arguments:
#   $1 - Environment ("STAGING" or "PRODUCTION")
# Reads:
#   CONF_{env}_FULL_URL
#   CONF_{env}_DOMAIN
#   CONF_{env}_URL_PATH
#   CONF_ENABLE_PATH_ROUTING
#   CONF_PROJECT_NAME
#   TARGET_PROJECT_DIR
# Sets:
#   CONF_{env}_URL_PATH (if not already set)
#######################################
determine_url_path() {
    local env="$1"
    local domain_var="CONF_${env}_DOMAIN"
    local path_var="CONF_${env}_URL_PATH"
    local full_url_var="CONF_${env}_FULL_URL"

    print_info "Determining URL path for ${env}..."

    # Priority 1: Full URL provided - parse it
    if [[ -n "${!full_url_var:-}" ]]; then
        print_info "Using ${env}_FULL_URL"
        parse_full_url "${!full_url_var}" "$env"
        return
    fi

    # Priority 2: Explicit path provided
    if [[ -n "${!path_var:-}" ]]; then
        print_info "Using explicit ${env}_URL_PATH: ${!path_var}"
        local path
        case "${!path_var}" in
            "auto")
                # Generate deterministic path (project-env)
                path="/${CONF_PROJECT_NAME}-${env,,}"
                eval "${path_var}=\"${path}\""
                print_success "Generated deterministic path: ${path}"
                ;;
            "none"|"/")
                # Domain-based routing (no path)
                eval "${path_var}=\"\""
                print_success "Domain-based routing (no path)"
                ;;
            *)
                # Use provided path as-is
                path="${!path_var}"
                [[ "$path" != /* ]] && path="/$path"  # Ensure leading /
                eval "${path_var}=\"${path}\""
                print_success "Using custom path: ${path}"
                ;;
        esac
        return
    fi

    # Priority 3: Check existing deployment.config.yml
    local config_file="${TARGET_PROJECT_DIR}/deployment/deployment.config.yml"
    if [[ -f "$config_file" ]]; then
        local existing_path
        existing_path=$(grep "${env,,}_url_path:" "$config_file" 2>/dev/null | \
                       sed -E 's/.*: "?([^"]+)"?/\1/' | head -1)
        if [[ -n "$existing_path" ]] && [[ "$existing_path" != "/" ]]; then
            eval "${path_var}=\"${existing_path}\""
            print_success "Preserved existing path from config: ${existing_path}"
            return
        fi
    fi

    # Priority 4: Default behavior based on ENABLE_PATH_ROUTING
    if [[ "${CONF_ENABLE_PATH_ROUTING:-true}" == "true" ]]; then
        # Generate random token path (current behavior - backward compatible)
        local token
        token=$(generate_secure_token 40)
        local path="/${CONF_PROJECT_NAME}-${env,,}-${token}"
        eval "${path_var}=\"${path}\""
        print_success "Generated secure path: ${path}"
    else
        # Domain-based routing (no path)
        eval "${path_var}=\"\""
        print_success "Domain-based routing (ENABLE_PATH_ROUTING=false)"
    fi
}
