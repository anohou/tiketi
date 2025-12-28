#!/bin/sh
# Template-Based .env Generator for Laravel
# Processes .env.template and resolves placeholders from secrets and config

set -e

# Environment variables
APP_ENV="${APP_ENV:-local}"
PROJECT_ROOT="/var/www/html"
CONFIG_FILE="${PROJECT_ROOT}/deployment/deployment.config.yml"
TEMPLATE_FILE="${PROJECT_ROOT}/deployment/templates/.env.template"
SECRETS_FILE="${PROJECT_ROOT}/deployment/.env.secrets.${APP_ENV}"
OUTPUT_FILE="${PROJECT_ROOT}/.env"

echo "[Gen-Env] ═══════════════════════════════════════════════════════════"
echo "[Gen-Env] Template-Based .env Generation"
echo "[Gen-Env] ═══════════════════════════════════════════════════════════"
echo "[Gen-Env] Environment: $APP_ENV"
echo "[Gen-Env] Template:    $TEMPLATE_FILE"
echo "[Gen-Env] Secrets:     $SECRETS_FILE"
echo "[Gen-Env] Output:      $OUTPUT_FILE"
echo "[Gen-Env] ═══════════════════════════════════════════════════════════"

# Ensure template exists
if [ ! -f "$TEMPLATE_FILE" ]; then
    echo "[Gen-Env] ERROR: Template file not found: $TEMPLATE_FILE"
    echo "[Gen-Env] Please ensure deployment/templates/.env.template exists in your project."
    exit 1
fi

# Load secrets into environment
if [ -f "$SECRETS_FILE" ]; then
    echo "[Gen-Env] Loading secrets from: $SECRETS_FILE"
    # Source secrets (they'll be available as shell variables)
    set -a  # Auto-export all variables
    . "$SECRETS_FILE"
    set +a
else
    echo "[Gen-Env] WARNING: Secrets file not found: $SECRETS_FILE"
fi

# Get project name from config
PROJECT_NAME=$(python3 <<'EOF'
import yaml, sys
try:
    with open('/var/www/html/deployment/deployment.config.yml') as f:
        config = yaml.safe_load(f)
    name = config.get('project', {}).get('name', 'laravel')
    # Convert hyphens to underscores
    print(name.replace('-', '_'))
except:
    print('laravel')
EOF
)

echo "[Gen-Env] Project: $PROJECT_NAME"

#######################################
# Helper: Get config value from YAML
#######################################
get_config_value() {
    local path="$1"
    python3 <<EOF
import yaml
import sys

try:
    with open('${CONFIG_FILE}') as f:
        config = yaml.safe_load(f)

    # Try environment-specific value first
    keys = '${path}'.split('.')
    env_value = config.get('environments', {}).get('${APP_ENV}', {})
    for key in keys:
        if isinstance(env_value, dict):
            env_value = env_value.get(key)
        else:
            env_value = None
            break

    # Fall back to base value
    if env_value is None:
        value = config
        for key in keys:
            if isinstance(value, dict):
                value = value.get(key)
            else:
                value = None
                break
    else:
        value = env_value

    # Output the value
    if value is not None and value != '' and str(value) != 'None':
        # Convert Python boolean to string
        if isinstance(value, bool):
            print(str(value))
        else:
            print(value)
except Exception as e:
    pass
EOF
}

#######################################
# Helper: Generate value based on type
#######################################
generate_value() {
    local gen_type="$1"

    case "$gen_type" in
        laravel-key)
            # Generate Laravel APP_KEY
            php artisan key:generate --show 2>/dev/null || echo ""
            ;;
        uuid)
            # Generate UUID (if uuidgen available)
            if command -v uuidgen >/dev/null 2>&1; then
                uuidgen | tr '[:upper:]' '[:lower:]'
            else
                echo ""
            fi
            ;;
        *)
            echo ""
            ;;
    esac
}

#######################################
# Helper: Resolve a single placeholder type
#######################################
resolve_single_type() {
    local type_spec="$1"
    local type="${type_spec%%:*}"
    local path="${type_spec#*:}"

    case "$type" in
        SECRET)
            # Get from environment (loaded from secrets file)
            eval echo "\${$path}"
            ;;
        CONFIG)
            # Get from YAML config
            get_config_value "$path"
            ;;
        DEFAULT)
            # Use literal value
            echo "$path"
            ;;
        GENERATE)
            # Auto-generate value
            generate_value "$path"
            ;;
        FALLBACK)
            # Generate fallback (expand internal variables in pattern)
            # Use __PROJECT__ and __ENV__ to avoid conflict with ${} placeholders
            echo "$path" | sed "s/__PROJECT__/${PROJECT_NAME}/g; s/__ENV__/${APP_ENV}/g"
            ;;
        ENV)
            # Special case: just return APP_ENV
            echo "$APP_ENV"
            ;;
        *)
            # Unknown type - return empty
            echo ""
            ;;
    esac
}

#######################################
# Helper: Resolve placeholder with fallback chain
#######################################
resolve_placeholder() {
    local placeholder="$1"

    # Handle ${ENV} special case (no fallback chain)
    if [ "$placeholder" = "ENV" ]; then
        echo "$APP_ENV"
        return
    fi

    # Split by | for fallback chain
    local IFS='|'
    set -- $placeholder

    for type_spec in "$@"; do
        value=$(resolve_single_type "$type_spec")

        # Check if we got a non-empty, non-null value
        if [ -n "$value" ] && [ "$value" != "null" ] && [ "$value" != "None" ]; then
            echo "$value"
            return
        fi
    done

    # All options exhausted - return empty
    echo ""
}

#######################################
# Helper: Process a line and resolve all placeholders
#######################################
process_line() {
    local line="$1"
    local result="$line"

    # Find all ${...} placeholders in the line
    while echo "$result" | grep -q '\${[^}]*}'; do
        # Extract first placeholder
        placeholder=$(echo "$result" | grep -o '\${[^}]*}' | head -1)
        placeholder_content=$(echo "$placeholder" | sed 's/\${//; s/}//')

        # Resolve placeholder
        value=$(resolve_placeholder "$placeholder_content")

        # Escape special characters for sed
        escaped_ph=$(echo "$placeholder" | sed 's/[[\.*^$/]/\\&/g')
        escaped_val=$(echo "$value" | sed 's/[\/&]/\\&/g')

        # Replace placeholder with value
        result=$(echo "$result" | sed "s/$escaped_ph/$escaped_val/")
    done

    echo "$result"
}

#######################################
# Main: Process Template
#######################################

# Start fresh
> "$OUTPUT_FILE"

echo "[Gen-Env] Processing template..."

# First pass: Resolve all placeholders
line_count=0
while IFS= read -r line || [ -n "$line" ]; do
   line_count=$((line_count + 1))

    # Skip empty lines and comments (but write them to output)
    if [ -z "$line" ] || echo "$line" | grep -q '^[[:space:]]*#'; then
        echo "$line" >> "$OUTPUT_FILE"
        continue
    fi

    # Process line and resolve placeholders
    processed=$(process_line "$line")
    echo "$processed" >> "$OUTPUT_FILE"

done < "$TEMPLATE_FILE"

echo "[Gen-Env] Processed $line_count lines from template"

# Second pass: Resolve variable references (e.g., ${APP_NAME})
echo "[Gen-Env] Resolving nested variable references..."

# Create temp file for second pass
TEMP_FILE="${OUTPUT_FILE}.tmp"
cp "$OUTPUT_FILE" "$TEMP_FILE"

# Read variables from first pass
> "$OUTPUT_FILE"

while IFS= read -r line || [ -n "$line" ]; do
    # Skip comments and empty lines
    if [ -z "$line" ] || echo "$line" | grep -q '^[[:space:]]*#'; then
        echo "$line" >> "$OUTPUT_FILE"
        continue
    fi

    result="$line"

    # Find ${VAR_NAME} references
    while echo "$result" | grep -q '\${[A-Z_][A-Z0-9_]*}'; do
        # Extract variable name
        var_ref=$(echo "$result" | grep -o '\${[A-Z_][A-Z0-9_]*}' | head -1)
        var_name=$(echo "$var_ref" | sed 's/\${//; s/}//')

        # Get value from temp file
        var_value=$(grep "^${var_name}=" "$TEMP_FILE" | head -1 | cut -d= -f2- | sed 's/^"//; s/"$//')

        # Replace reference
        escaped_ref=$(echo "$var_ref" | sed 's/[[\.*^$/]/\\&/g')
        escaped_val=$(echo "$var_value" | sed 's/[\/&]/\\&/g')
        result=$(echo "$result" | sed "s/$escaped_ref/$escaped_val/")
    done

    echo "$result" >> "$OUTPUT_FILE"

done < "$TEMP_FILE"

rm "$TEMP_FILE"

echo "[Gen-Env] Nested references resolved"

# Verify critical variables
echo "[Gen-Env] Verifying critical variables..."

CRITICAL_VARS="APP_ENV APP_KEY DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD"
missing_vars=""

for var in $CRITICAL_VARS; do
    if ! grep -q "^${var}=" "$OUTPUT_FILE" || [ -z "$(grep "^${var}=" "$OUTPUT_FILE" | cut -d= -f2-)" ]; then
        missing_vars="$missing_vars $var"
    fi
done

if [ -n "$missing_vars" ]; then
    echo "[Gen-Env] WARNING: Missing or empty critical variables:$missing_vars"
    echo "[Gen-Env] Check your secrets file: $SECRETS_FILE"
fi

# Generate APP_KEY if still missing
if ! grep -q "^APP_KEY=.\+" "$OUTPUT_FILE"; then
    echo "[Gen-Env] APP_KEY missing or empty, generating..."
    php artisan key:generate --force
fi

echo "[Gen-Env] ═══════════════════════════════════════════════════════════"
echo "[Gen-Env] ✓ Environment file generated successfully"
echo "[Gen-Env] ═══════════════════════════════════════════════════════════"
