#!/usr/bin/env bash
# Validation Functions Library
# Provides input validation for scaffolding script

set -euo pipefail

#######################################
# Validate domain name format
# Arguments:
#   $1 - Domain name to validate
# Returns:
#   0 if valid, 1 if invalid
#######################################
validate_domain() {
    local domain="$1"

    # Check if empty
    if [[ -z "$domain" ]]; then
        echo "❌ Domain cannot be empty"
        return 1
    fi

    # Basic DNS format check (alphanumeric, dots, hyphens)
    if [[ ! "$domain" =~ ^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$ ]]; then
        echo "❌ Invalid domain format: $domain"
        echo "   Domain must contain only alphanumeric characters, dots, and hyphens"
        return 1
    fi

    # Check for localhost (allowed)
    if [[ "$domain" == "localhost" ]] || [[ "$domain" == *.localhost ]]; then
        return 0
    fi

    # Check minimum length (at least one dot for non-localhost)
    if [[ ! "$domain" =~ \. ]]; then
        echo "❌ Invalid domain: $domain (must contain at least one dot for non-localhost)"
        return 1
    fi

    return 0
}

#######################################
# Validate project name (kebab-case)
# Arguments:
#   $1 - Project name to validate
# Returns:
#   0 if valid, 1 if invalid
#######################################
validate_project_name() {
    local name="$1"

    # Check if empty
    if [[ -z "$name" ]]; then
        echo "❌ Project name cannot be empty"
        return 1
    fi

    # Check kebab-case format (lowercase, hyphens, no spaces)
    if [[ ! "$name" =~ ^[a-z0-9]+(-[a-z0-9]+)*$ ]]; then
        echo "❌ Invalid project name: $name"
        echo "   Must be kebab-case (lowercase letters, numbers, hyphens only)"
        echo "   Examples: my-api, laravel-app, user-service"
        return 1
    fi

    # Check length (reasonable limits)
    if [[ ${#name} -lt 3 ]]; then
        echo "❌ Project name too short (minimum 3 characters)"
        return 1
    fi

    if [[ ${#name} -gt 50 ]]; then
        echo "❌ Project name too long (maximum 50 characters)"
        return 1
    fi

    return 0
}

#######################################
# Validate URL format
# Arguments:
#   $1 - URL to validate
# Returns:
#   0 if valid, 1 if invalid
#######################################
validate_url() {
    local url="$1"

    # Check if empty
    if [[ -z "$url" ]]; then
        echo "❌ URL cannot be empty"
        return 1
    fi

    # Basic URL format check (http:// or https://)
    if [[ ! "$url" =~ ^https?:// ]]; then
        echo "❌ Invalid URL: $url"
        echo "   URL must start with http:// or https://"
        return 1
    fi

    return 0
}

#######################################
# Validate registry format
# Arguments:
#   $1 - Registry host to validate
# Returns:
#   0 if valid, 1 if invalid
#######################################
validate_registry() {
    local registry="$1"

    # Check if empty
    if [[ -z "$registry" ]]; then
        echo "❌ Registry cannot be empty"
        return 1
    fi

    # Check for common registries or domain format
    if [[ "$registry" =~ ^(ghcr\.io|docker\.io|gcr\.io|quay\.io)$ ]] || validate_domain "$registry" 2>/dev/null; then
        return 0
    else
        echo "❌ Invalid registry: $registry"
        echo "   Must be a valid domain (e.g., ghcr.io, docker.io, registry.example.com)"
        return 1
    fi
}

#######################################
# Generate cryptographically secure random token
# Arguments:
#   $1 - Length of token (default: 40)
# Returns:
#   Secure random token
#######################################
generate_secure_token() {
    local length="${1:-40}"

    # Use openssl to generate secure random bytes
    # Convert to base64, remove special chars, take required length
    openssl rand -base64 64 | tr -dc 'a-zA-Z0-9' | head -c "$length"
    echo
}

#######################################
# Validate display name (human-readable)
# Arguments:
#   $1 - Display name to validate
# Returns:
#   0 if valid, 1 if invalid
#######################################
validate_display_name() {
    local name="$1"

    # Check if empty
    if [[ -z "$name" ]]; then
        echo "❌ Display name cannot be empty"
        return 1
    fi

    # Check length
    if [[ ${#name} -lt 3 ]]; then
        echo "❌ Display name too short (minimum 3 characters)"
        return 1
    fi

    if [[ ${#name} -gt 100 ]]; then
        echo "❌ Display name too long (maximum 100 characters)"
        return 1
    fi

    return 0
}

#######################################
# Validate GitHub repository format
# Arguments:
#   $1 - Repository in format "owner/repo"
# Returns:
#   0 if valid, 1 if invalid
#######################################
validate_github_repo() {
    local repo="$1"

    # Check if empty
    if [[ -z "$repo" ]]; then
        echo "❌ Repository cannot be empty"
        return 1
    fi

    # Check format: owner/repo
    if [[ ! "$repo" =~ ^[a-zA-Z0-9_-]+/[a-zA-Z0-9_-]+$ ]]; then
        echo "❌ Invalid repository format: $repo"
        echo "   Must be in format: owner/repository"
        echo "   Examples: anohou/my-api, username/laravel-app"
        return 1
    fi

    return 0
}

#######################################
# Validate project type (laravel-api-only or laravel-fullstack)
# Arguments:
#   $1 - Project type to validate
# Returns:
#   0 if valid, 1 if invalid
#######################################
validate_project_type() {
    local type="$1"

    # Check if empty
    if [[ -z "$type" ]]; then
        echo "❌ Project type cannot be empty"
        return 1
    fi

    # Check if valid type
    if [[ "$type" != "laravel-api-only" ]] && [[ "$type" != "laravel-fullstack" ]]; then
        echo "❌ Invalid project type: $type"
        echo "   Must be either 'laravel-api-only' or 'laravel-fullstack'"
        echo "   - laravel-api-only:   API-only project (no frontend builds)"
        echo "   - laravel-fullstack:  Full-stack app with Vue/React/Inertia (requires npm builds)"
        return 1
    fi

    return 0
}

#######################################
# Test suite for validators
# Run with: bash -c 'source lib/validators.sh && run_validator_tests'
#######################################
run_validator_tests() {
    local passed=0
    local failed=0

    echo "Running validator tests..."
    echo

    # Test domain validation
    echo "Testing domain validation..."
    if validate_domain "example.com" >/dev/null 2>&1; then ((passed++)); else ((failed++)); fi
    if validate_domain "api.example.com" >/dev/null 2>&1; then ((passed++)); else ((failed++)); fi
    if validate_domain "localhost" >/dev/null 2>&1; then ((passed++)); else ((failed++)); fi
    if ! validate_domain "invalid domain" >/dev/null 2>&1; then ((passed++)); else ((failed++)); fi

    # Test project name validation
    echo "Testing project name validation..."
    if validate_project_name "my-api" >/dev/null 2>&1; then ((passed++)); else ((failed++)); fi
    if validate_project_name "laravel-app" >/dev/null 2>&1; then ((passed++)); else ((failed++)); fi
    if ! validate_project_name "My_API" >/dev/null 2>&1; then ((passed++)); else ((failed++)); fi
    if ! validate_project_name "ab" >/dev/null 2>&1; then ((passed++)); else ((failed++)); fi

    # Test URL validation
    echo "Testing URL validation..."
    if validate_url "https://example.com" >/dev/null 2>&1; then ((passed++)); else ((failed++)); fi
    if validate_url "http://localhost:3000" >/dev/null 2>&1; then ((passed++)); else ((failed++)); fi
    if ! validate_url "example.com" >/dev/null 2>&1; then ((passed++)); else ((failed++)); fi

    # Test registry validation
    echo "Testing registry validation..."
    if validate_registry "ghcr.io" >/dev/null 2>&1; then ((passed++)); else ((failed++)); fi
    if validate_registry "docker.io" >/dev/null 2>&1; then ((passed++)); else ((failed++)); fi
    if ! validate_registry "invalid" >/dev/null 2>&1; then ((passed++)); else ((failed++)); fi

    # Test project type validation
    echo "Testing project type validation..."
    if validate_project_type "laravel-api-only" > /dev/null 2>&1; then ((passed++)); else ((failed++)); fi
    if validate_project_type "laravel-fullstack" > /dev/null 2>&1; then ((passed++)); else ((failed++)); fi
    if ! validate_project_type "invalid-type" > /dev/null 2>&1; then ((passed++)); else ((failed++)); fi
    if ! validate_project_type "laravel" > /dev/null 2>&1; then ((passed++)); else ((failed++)); fi

    # Test token generation
    echo "Testing token generation..."
    token=$(generate_secure_token 40)
    if [[ ${#token} -eq 40 ]] && [[ "$token" =~ ^[a-zA-Z0-9]+$ ]]; then ((passed++)); else ((failed++)); fi

    echo
    echo "Tests completed: $passed passed, $failed failed"

    if [[ $failed -eq 0 ]]; then
        echo "✅ All tests passed!"
        return 0
    else
        echo "❌ Some tests failed"
        return 1
    fi
}
