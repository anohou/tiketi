#!/bin/sh
# Generate Laravel .env file from YAML config and external secrets
# This script runs inside the container during startup

set -e

APP_ENV="${APP_ENV:-local}"
CONFIG_FILE="/var/www/html/deployment/deployment.config.yml"
SECRETS_FILE="/var/www/html/.env.secrets"
OUTPUT_FILE="/var/www/html/.env"

# Ensure we're in the right directory
cd /var/www/html

echo "[Generate-Env] Generating .env for environment: $APP_ENV"
echo "[Generate-Env] Current directory: $(pwd)"
echo "[Generate-Env] Artisan exists: $([ -f artisan ] && echo 'yes' || echo 'no')"

# Load secrets from the merged file created by deploy.sh
# deploy.sh merges secrets from deployment.config.yml paths into deployment/.env.secrets.{env}
# This directory is bind-mounted, so it's accessible at /var/www/html/deployment/
MERGED_SECRETS_FILE="/var/www/html/deployment/.env.secrets.${APP_ENV}"

if [ -f "$MERGED_SECRETS_FILE" ]; then
    echo "[Generate-Env] Loading merged secrets from: $MERGED_SECRETS_FILE"
    cat "$MERGED_SECRETS_FILE" > "$OUTPUT_FILE"
    echo "" >> "$OUTPUT_FILE"  # Add newline separator
else
    echo "[Generate-Env] No merged secrets file found at: $MERGED_SECRETS_FILE"
    echo "[Generate-Env] Creating empty .env (secrets should be merged by deploy.sh)"
    echo "# Generated environment file" > "$OUTPUT_FILE"
fi

# Add Laravel configuration from deployment.config.yml
# We can't source utils.sh here because it's a bash script and this runs under /bin/sh
# Instead, we use Python directly to read YAML config
echo "[Generate-Env] Adding Laravel config from deployment.config.yml..."

# Helper function: Get config value using Python
get_laravel_config() {
    local config_path="$1"
    python3 <<EOF
import yaml
import sys

try:
    with open('$CONFIG_FILE', 'r') as f:
        config = yaml.safe_load(f)

    # Get base value from laravel section
    keys = '$config_path'.split('.')
    value = config.get('laravel', {})
    for key in keys[1:]:  # Skip 'laravel' prefix
        if isinstance(value, dict):
            value = value.get(key)
        else:
            value = None
            break

    # Try to get environment override
    env_value = config.get('environments', {}).get('$APP_ENV', {}).get('laravel', {})
    for key in keys[1:]:
        if isinstance(env_value, dict):
            env_value = env_value.get(key)
        else:
            env_value = None
            break

    # Environment override takes precedence
    final_value = env_value if env_value is not None else value

    if final_value is not None:
        print(final_value)
except Exception:
    pass
EOF
}

# Helper function: Add config value to .env if not already present
add_if_missing() {
    local env_var="$1"
    local config_path="$2"

    # Skip if already in .env (secrets take precedence)
    if grep -q "^${env_var}=" "$OUTPUT_FILE" 2>/dev/null; then
        return
    fi

    # Get value from config
    local value=$(get_laravel_config "$config_path")

    # Add to .env if value exists
    if [ -n "$value" ] && [ "$value" != "null" ] && [ "$value" != "None" ]; then
        # Quote value if it contains spaces or parentheses
        if echo "$value" | grep -q "[ ()]"; then
            echo "${env_var}=\"${value}\"" >> "$OUTPUT_FILE"
        else
            echo "${env_var}=${value}" >> "$OUTPUT_FILE"
        fi
        echo "[Generate-Env]   Added ${env_var}=${value}"
    fi
}

echo "" >> "$OUTPUT_FILE"
echo "# Laravel Configuration (from deployment.config.yml)" >> "$OUTPUT_FILE"

# Core application settings
add_if_missing "APP_URL" "laravel.core.url"
add_if_missing "APP_TIMEZONE" "laravel.core.timezone"
add_if_missing "APP_LOCALE" "laravel.core.locale"
add_if_missing "APP_FALLBACK_LOCALE" "laravel.core.fallback_locale"
add_if_missing "APP_FAKER_LOCALE" "laravel.core.faker_locale"

# Session configuration
add_if_missing "SESSION_DOMAIN" "laravel.session.domain"
add_if_missing "SESSION_SECURE_COOKIE" "laravel.session.secure_cookie"
add_if_missing "SESSION_SAME_SITE" "laravel.session.same_site"
add_if_missing "SESSION_LIFETIME" "laravel.session.lifetime"
add_if_missing "SESSION_ENCRYPT" "laravel.session.encrypt"

# CORS configuration
add_if_missing "CORS_ALLOWED_ORIGINS" "laravel.cors.allowed_origins"

# Sanctum configuration
add_if_missing "SANCTUM_STATEFUL_DOMAINS" "laravel.sanctum.stateful_domains"

# Cache configuration
add_if_missing "CACHE_DRIVER" "laravel.cache.driver"
add_if_missing "CACHE_STORE" "laravel.cache.store"

# Logging configuration
add_if_missing "LOG_LEVEL" "laravel.logging.level"

# Queue configuration (background jobs: database, redis, sync, etc.)
add_if_missing "QUEUE_CONNECTION" "laravel.queue.connection"

# Broadcast configuration (websockets: pusher, redis, null for disabled)
add_if_missing "BROADCAST_CONNECTION" "laravel.broadcast.connection"

# Add basic Laravel configuration (only if not already set by secrets or config)
echo "" >> "$OUTPUT_FILE"
echo "# Laravel Configuration" >> "$OUTPUT_FILE"

# Read from config if not already set
add_if_missing "APP_NAME" "laravel.core.name"

if ! grep -q "^APP_ENV=" "$OUTPUT_FILE" 2>/dev/null; then
    echo "APP_ENV=$APP_ENV" >> "$OUTPUT_FILE"
fi

# Don't add APP_KEY here - it should come from secrets or be generated later
if ! grep -q "^APP_DEBUG=" "$OUTPUT_FILE" 2>/dev/null; then
    echo "APP_DEBUG=false" >> "$OUTPUT_FILE"
fi

# Generate key only if APP_KEY doesn't exist at all
if ! grep -q "^APP_KEY=" "$OUTPUT_FILE"; then
    echo "[Generate-Env] No APP_KEY found, generating application key..."
    php artisan key:generate --force
else
    echo "[Generate-Env] APP_KEY already set in secrets file"
fi

# Database Configuration Fallback
if ! grep -q "^DB_DATABASE=" "$OUTPUT_FILE"; then
    echo "[Generate-Env] DB_DATABASE not found in secrets, generating fallback..."

    # Get project name directly from config
    PROJECT_NAME=$(python3 <<EOF
import yaml
try:
    with open('$CONFIG_FILE', 'r') as f:
        config = yaml.safe_load(f)
    print(config.get('project', {}).get('name', ''))
except:
    pass
EOF
)

    if [ -n "$PROJECT_NAME" ]; then
        # Convert - to _
        PROJECT_NAME_CLEAN=$(echo "$PROJECT_NAME" | tr '-' '_')

        # Determine suffix based on environment
        SUFFIX=""
        case "$APP_ENV" in
            local) SUFFIX="_dev" ;;
            staging) SUFFIX="_staging" ;;
            production) SUFFIX="_production" ;;
            *) SUFFIX="_${APP_ENV}" ;;
        esac

        DEFAULT_DB="${PROJECT_NAME_CLEAN}${SUFFIX}"

        echo "DB_DATABASE=${DEFAULT_DB}" >> "$OUTPUT_FILE"
        echo "[Generate-Env]   Added fallback DB_DATABASE=${DEFAULT_DB}"
    fi
fi

echo "[Generate-Env] Environment file generated successfully"
