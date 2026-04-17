#!/usr/bin/env bash
# ╔══════════════════════════════════════════════════════════════════════════════╗
# ║  generate-env.sh — Template-driven .env generator                          ║
# ║                                                                             ║
# ║  Strategy: manifest + lookup (NOT append-all)                              ║
# ║    1. Build key→value lookup from all secret sources (priority order)      ║
# ║    2. Walk template.env line-by-line, preserving structure                 ║
# ║    3. For each KEY=: substitute from lookup, or keep template default      ║
# ║    Only keys declared in template.env can appear in .env — no leakage.    ║
# ║                                                                             ║
# ║  Priority (highest wins):                                                  ║
# ║    CLI args > config.yml values > project.env > common.env > template     ║
# ║                                                                             ║
# ║  Usage: ./generate-env.sh [KEY=VALUE ...]                                  ║
# ╚══════════════════════════════════════════════════════════════════════════════╝
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
source "${SCRIPT_DIR}/deploy.config.sh"

TEMPLATE="${DEPLOY_DIR}/config/template.env"
OUTPUT_ENV="${DEPLOY_DIR}/.env"

linfo() { echo -e "\033[0;34m[generate-env]\033[0m $1" >&2; }
warn()  { echo -e "\033[0;33m[generate-env]\033[0m WARN: $1" >&2; }
error() { echo -e "\033[0;31m[generate-env]\033[0m ERROR: $1" >&2; exit 1; }

[[ -f "$TEMPLATE" ]] || error "template.env not found at ${TEMPLATE}"

LOOKUP_FILE="$(mktemp)"
trap 'rm -f "$LOOKUP_FILE"' EXIT

_set() {
    local key="$1" val="$2"
    { grep -v "^${key}=" "$LOOKUP_FILE" 2>/dev/null || true; } > "${LOOKUP_FILE}.tmp"
    mv "${LOOKUP_FILE}.tmp" "$LOOKUP_FILE"
    printf '%s=%s\n' "$key" "$val" >> "$LOOKUP_FILE"
}
_has() { grep -q "^${1}=" "$LOOKUP_FILE" 2>/dev/null; }
_get() { grep -m1 "^${1}=" "$LOOKUP_FILE" 2>/dev/null | cut -d= -f2- || echo ""; }

_load_file() {
    local file="$1" label="$2"
    if [[ -f "$file" ]]; then
        linfo "Loading: ${label}"
        local count=0
        while IFS= read -r line; do
            [[ -z "$line" || "$line" =~ ^[[:space:]]*# ]] && continue
            local key="${line%%=*}"
            local val="${line#*=}"
            val="${val%$'\r'}"
            val="${val%% #*}"
            val="${val%"${val##*[![:space:]]}"}"
            [[ -z "$key" ]] && continue
            _set "$key" "$val"
            (( count++ )) || true
        done < "$file"
        linfo "  → ${count} keys loaded"
    else
        warn "${label} not found at ${file} — skipping"
    fi
}

# ── 1. Build lookup (lowest → highest priority) ───────────────────────────────
# Layer 1: common server secrets (SMTP, shared tokens)
[[ -n "${COMMON_ENV_PATH:-}" ]] && _load_file "$COMMON_ENV_PATH" "common.env"

# Layer 2: project-specific secrets (APP_KEY, DB role creds, app-specific keys)
[[ -n "${PROJECT_ENV_PATH:-}" ]] && _load_file "$PROJECT_ENV_PATH" "$(basename "$PROJECT_ENV_PATH")"

# Layer 3: config.yml-derived values (override secret files)
_set APP_NAME              "${APP_NAME}"
_set APP_SLUG              "${APP_SLUG}"
_set APP_IMAGE             "${APP_IMAGE}"
_set APP_URL               "${APP_URL}"
_set ASSET_URL             ""
_set APP_DOMAIN            "${APP_DOMAIN}"
_set TENANT_WILDCARD_DOMAIN "${TENANT_WILDCARD_DOMAIN:-}"
_set TENANT_DB_PREFIX      "${TENANT_DB_PREFIX:-}"
_set FRONTEND_URL          "${FRONTEND_URL:-}"
_set CORS_ALLOWED_ORIGINS  "${CORS_ALLOWED_ORIGINS:-}"
_set TRAEFIK_SWARM_NETWORK "${TRAEFIK_SWARM_NETWORK}"
_set TRAEFIK_CERT_RESOLVER "${TRAEFIK_CERT_RESOLVER:-letsencrypt}"
_set DB_NETWORK            "${DB_NETWORK}"
_set NGINX_IMAGE           "${NGINX_IMAGE}"
_set COMPOSE_PROJECT_NAME  "${COMPOSE_PROJECT_NAME}"
_set REVERB_ENABLED        "${REVERB_ENABLED:-false}"
_set REVERB_PATH           "${REVERB_PATH:-/app}"
_set REVERB_PORT           "${REVERB_PORT:-6001}"
_set QUEUE_SLEEP           "${QUEUE_SLEEP}"
_set QUEUE_TRIES           "${QUEUE_TRIES}"
_set QUEUE_TIMEOUT         "${QUEUE_TIMEOUT}"
_set QUEUE_MAX_JOBS        "${QUEUE_MAX_JOBS}"
_set QUEUE_MAX_TIME        "${QUEUE_MAX_TIME}"
linfo "Config-derived values injected (app identity, image, networks, queue runtime)"

# Layer 3.5: Auto-map Postgres RBAC creds → Laravel creds (avoids key duplication)
# The project env file uses DB_USERNAME/DB_PASSWORD directly, so no mapping needed.
# These are kept in case the postgres infra env file uses different key names.
if _has "APP_RUNTIME_USER"; then
    _set DB_USERNAME "$(_get APP_RUNTIME_USER)"
    linfo "Auto-mapped DB_USERNAME = APP_RUNTIME_USER"
fi
if _has "APP_RUNTIME_PASSWORD"; then
    _set DB_PASSWORD "$(_get APP_RUNTIME_PASSWORD)"
fi
if _has "APP_MIGRATOR_USER"; then
    _set DB_MIGRATOR_USERNAME "$(_get APP_MIGRATOR_USER)"
    linfo "Auto-mapped DB_MIGRATOR_USERNAME = APP_MIGRATOR_USER"
fi
if _has "APP_MIGRATOR_PASSWORD"; then
    _set DB_MIGRATOR_PASSWORD "$(_get APP_MIGRATOR_PASSWORD)"
fi

# Layer 4: CLI KEY=VALUE overrides (highest priority)
for override in "$@"; do
    _set "${override%%=*}" "${override#*=}"
done
[[ $# -gt 0 ]] && linfo "CLI overrides applied: $*"

# ── 2. Generate .env by walking template line-by-line ─────────────────────────
linfo "Building ${OUTPUT_ENV} ..."

{
    echo "# ┌──────────────────────────────────────────────────────────────────────────┐"
    printf "# │  Auto-generated — %s\n" "$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
    echo "# │  DO NOT EDIT MANUALLY. Source of truth: deployment/config/template.env"
    echo "# └──────────────────────────────────────────────────────────────────────────┘"
    echo ""
} > "$OUTPUT_ENV"

while IFS= read -r line; do
    if [[ -z "$line" ]]; then echo ""; continue; fi
    if [[ "$line" =~ ^[[:space:]]*# ]]; then echo "$line"; continue; fi
    key="${line%%=*}"
    template_val="${line#*=}"
    if _has "$key"; then
        echo "${key}=$(_get "$key")"
    else
        echo "${key}=${template_val}"
    fi
done < "$TEMPLATE" >> "$OUTPUT_ENV"

chmod 600 "$OUTPUT_ENV"

# ── 3. Validate required keys are non-empty ───────────────────────────────────
REQUIRED_KEYS=(
    "APP_KEY"
    "APP_URL"
    "APP_DOMAIN"
    "DB_HOST"
    "DB_DATABASE"
    "DB_USERNAME"
    "DB_PASSWORD"
    "DB_MIGRATOR_USERNAME"
    "DB_MIGRATOR_PASSWORD"
)

missing=()
for key in "${REQUIRED_KEYS[@]}"; do
    value=$(grep -E "^${key}=" "$OUTPUT_ENV" | tail -1 | cut -d= -f2-)
    [[ -z "$value" ]] && missing+=("$key")
done

if [[ ${#missing[@]} -gt 0 ]]; then
    error "Required keys are missing or empty: ${missing[*]}"
fi

total_keys=$(grep -c "^[^#]" "$OUTPUT_ENV" 2>/dev/null || echo "?")
echo -e "\033[0;32m[generate-env]\033[0m ✓ .env generated — ${total_keys} keys → ${OUTPUT_ENV}" >&2
