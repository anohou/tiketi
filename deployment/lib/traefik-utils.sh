#!/usr/bin/env bash
# Traefik configuration generation utilities for scaffold-init.sh

set -euo pipefail

#######################################
# Generate Traefik router labels
# Arguments:
#   $1 - Environment (staging/production)
#   $2 - Project name
#   $3 - Domain
#   $4 - URL path (optional, empty for domain-based)
#   $5 - Indent spaces (optional, default: 6)
# Outputs:
#   Traefik label lines for docker-compose
#######################################
generate_traefik_router_labels() {
    local env="$1"
    local project="$2"
    local domain="$3"
    local url_path="$4"
    local indent_spaces="${5:-6}"  # Default to 6 spaces if not specified

    local router_name="${project}-${env}"
    local indent=$(printf "%${indent_spaces}s" "")  # Create indent string

    if [[ -n "$url_path" ]]; then
        # Path-based routing with StripPrefix middleware
        cat <<EOF
${indent}- "traefik.http.routers.${router_name}.rule=Host(\`${domain}\`) && PathPrefix(\`${url_path}\`)"
${indent}- "traefik.http.middlewares.${router_name}-strip.stripprefix.prefixes=${url_path}"
${indent}- "traefik.http.routers.${router_name}.middlewares=${router_name}-strip"
EOF
    else
        # Domain-based routing (Host only, no StripPrefix)
        echo "${indent}- \"traefik.http.routers.${router_name}.rule=Host(\`${domain}\`)\""
    fi
}

#######################################
# Determine routing type
# Arguments:
#   $1 - URL path (empty for domain-based)
# Outputs:
#   "path-based" or "domain-based"
#######################################
get_routing_type() {
    local url_path="$1"
    if [[ -n "$url_path" ]]; then
        echo "path-based"
    else
        echo "domain-based"
    fi
}
