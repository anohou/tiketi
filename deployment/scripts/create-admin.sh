#!/usr/bin/env bash
# ╔══════════════════════════════════════════════════════════════════════════════╗
# ║  create-admin.sh — Secure Admin Account Generator                          ║
# ║                                                                              ║
# ║  Creates an admin user safely without hardcoding passwords.                 ║
# ║  Usage: ./create-admin.sh [args passed to the configured admin command]     ║
# ╚══════════════════════════════════════════════════════════════════════════════╝
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

log() { echo "[admin] $(date '+%H:%M:%S') $*"; }
err() { echo "[admin] ERROR: $*" >&2; exit 1; }

CREATE_ADMIN_MODE="artisan_command"
CREATE_ADMIN_COMMAND="admin:create"
CREATE_ADMIN_DEFAULT_ROLE="superadmin"

if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
    echo "Usage: ./create-admin.sh [admin-command-options]"
    echo ""
    echo "Configured mode: ${CREATE_ADMIN_MODE}"
    if [[ "${CREATE_ADMIN_MODE}" == "artisan_command" ]]; then
        echo "Runs: php artisan ${CREATE_ADMIN_COMMAND}"
        echo ""
        echo "Examples:"
        echo "  ./create-admin.sh"
        echo "  ./create-admin.sh --name='Admin User' --email=admin@example.com"
        echo "  ./create-admin.sh --update"
    else
        echo "Creates a Filament admin user. Arguments: [Name] [Email] [Password]"
    fi
    exit 0
fi

case "${CREATE_ADMIN_MODE}" in
    artisan_command)
        log "Running configured admin command: php artisan ${CREATE_ADMIN_COMMAND}"
        if [[ $# -eq 0 && -n "${CREATE_ADMIN_DEFAULT_ROLE}" ]]; then
            set -- "--role=${CREATE_ADMIN_DEFAULT_ROLE}"
        fi
        ARTISAN_TTY="${ARTISAN_TTY:-true}" "${SCRIPT_DIR}/artisan.sh" "${CREATE_ADMIN_COMMAND}" "$@"
        log "✓ Admin command completed successfully."
        ;;

    filament)
        NAME="${1:-}"
        EMAIL="${2:-}"
        PASSWORD="${3:-}"

        if [[ -z "$NAME" ]]; then
            read -r -p "Enter Admin Name (e.g. John Doe): " NAME
        fi

        if [[ -z "$EMAIL" ]]; then
            read -r -p "Enter Admin Email (e.g. admin@example.com): " EMAIL
        fi

        if [[ -z "$PASSWORD" ]]; then
            read -r -s -p "Enter Admin Password: " PASSWORD
            echo ""
        fi

        if [[ -z "$NAME" || -z "$EMAIL" || -z "$PASSWORD" ]]; then
            err "All fields (Name, Email, Password) are required."
        fi

        WHITELIST=$(grep -m1 "^ADMIN_EMAIL_WHITELIST=" "${DEPLOY_DIR}/.env" 2>/dev/null | cut -d= -f2- | tr -d '\r' || echo "")
        WHITELIST="${WHITELIST%\"}"; WHITELIST="${WHITELIST#\"}"
        WHITELIST="${WHITELIST%\'}"; WHITELIST="${WHITELIST#\'}"

        if [[ -n "$WHITELIST" ]]; then
            MATCH_FOUND=false
            IFS=',' read -ra PATTERNS <<< "$WHITELIST"
            for PATTERN in "${PATTERNS[@]}"; do
                PATTERN="$(printf '%s' "${PATTERN}" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
                if [[ "$EMAIL" == $PATTERN ]]; then
                    MATCH_FOUND=true
                    break
                fi
            done

            if [[ "$MATCH_FOUND" != "true" ]]; then
                err "Email '${EMAIL}' is not allowed."
            fi
        fi

        log "Creating Filament admin user: ${EMAIL} ..."
        "${SCRIPT_DIR}/artisan.sh" make:filament-user \
            --name="${NAME}" \
            --email="${EMAIL}" \
            --password="${PASSWORD}"
        log "✓ Admin user created successfully. You can now log in."
        ;;

    disabled)
        err "Admin creation is disabled for this app."
        ;;

    *)
        err "Unsupported create_admin mode: ${CREATE_ADMIN_MODE}"
        ;;
esac
