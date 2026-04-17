#!/usr/bin/env bash
# ╔══════════════════════════════════════════════════════════════════════════════╗
# ║  create-admin.sh — Secure Admin Account Generator                          ║
# ║                                                                              ║
# ║  Creates a Filament admin user safely without hardcoding passwords.         ║
# ║  Usage: ./create-admin.sh [Name] [Email] [Password]                          ║
# ║  If arguments are omitted, it prompts interactively using hidden input.      ║
# ╚══════════════════════════════════════════════════════════════════════════════╝
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

log() { echo "[admin] $(date '+%H:%M:%S') $*"; }
err() { echo "[admin] ERROR: $*" >&2; exit 1; }

# Parse arguments or prompt interactively
if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
    echo "Usage: ./create-admin.sh [Name] [Email] [Password]"
    echo ""
    echo "Creates a Filament admin user safely without hardcoding passwords."
    echo "If arguments are omitted, it prompts interactively using hidden input."
    exit 0
fi

NAME="${1:-}"
EMAIL="${2:-}"
PASSWORD="${3:-}"

if [[ -z "$NAME" ]]; then
    read -p "Enter Admin Name (e.g. John Doe): " NAME
fi

if [[ -z "$EMAIL" ]]; then
    read -p "Enter Admin Email (e.g. admin@example.com): " EMAIL
fi

if [[ -z "$PASSWORD" ]]; then
    read -s -p "Enter Admin Password: " PASSWORD
    echo "" # Add newline after hidden input
fi

if [[ -z "$NAME" || -z "$EMAIL" || -z "$PASSWORD" ]]; then
    err "All fields (Name, Email, Password) are required."
fi

# ── Whitelist Validation ───────────────────────────────────────────────────────
# Load environment configuration safely to pull the whitelist constraint
WHITELIST=$(grep -m1 "^ADMIN_EMAIL_WHITELIST=" "${DEPLOY_DIR}/.env" 2>/dev/null | cut -d= -f2- | tr -d '\r' || echo "")
# Remove any surrounding quotes
WHITELIST="${WHITELIST%\"}"; WHITELIST="${WHITELIST#\"}"
WHITELIST="${WHITELIST%\'}"; WHITELIST="${WHITELIST#\'}"

if [[ -n "$WHITELIST" ]]; then
    MATCH_FOUND=false
    # Read comma-separated patterns into an array
    IFS=',' read -ra PATTERNS <<< "$WHITELIST"
    for PATTERN in "${PATTERNS[@]}"; do
        # Strip leading/trailing whitespaces securely in bash
        PATTERN="$(echo -e "${PATTERN}" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
        
        # Native bash pattern matching automatically evaluates * wildcards
        if [[ "$EMAIL" == $PATTERN ]]; then
            MATCH_FOUND=true
            break
        fi
    done

    if [[ "$MATCH_FOUND" != "true" ]]; then
        err "Email '${EMAIL}' is not allowed."
    fi
fi
# ──────────────────────────────────────────────────────────────────────────────

log "Creating admin user: ${EMAIL} ..."

# Execute invisibly inside the already-running container
"${SCRIPT_DIR}/artisan.sh" make:filament-user \
    --name="${NAME}" \
    --email="${EMAIL}" \
    --password="${PASSWORD}"

log "✓ Admin user created successfully. You can now log in."
