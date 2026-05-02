#!/usr/bin/env bash
# ╔══════════════════════════════════════════════════════════════════════════════╗
# ║  artisan.sh — Run Laravel artisan inside the php-fpm container             ║
# ║                                                                              ║
# ║  Convenience wrapper for executing artisan commands against the running      ║
# ║  production compose stack.                                                   ║
# ╚══════════════════════════════════════════════════════════════════════════════╝
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
source "${SCRIPT_DIR}/deploy.config.sh"

COMPOSE_FILE="${DEPLOY_DIR}/config/docker-compose.prod.yml"
ACTIVE_COLOR_FILE="${DEPLOY_DIR}/.deploy/active-color"
ARTISAN_COLOR="${ARTISAN_COLOR:-$(cat "${ACTIVE_COLOR_FILE}" 2>/dev/null || true)}"

if [[ -z "${ARTISAN_ENV_FILE:-}" && -n "${ARTISAN_COLOR}" && -f "${DEPLOY_DIR}/.env.${ARTISAN_COLOR}" ]]; then
    ENV_FILE="${DEPLOY_DIR}/.env.${ARTISAN_COLOR}"
    ARTISAN_COMPOSE_PROJECT="${ARTISAN_COMPOSE_PROJECT:-${COMPOSE_PROJECT_BASE}-${ARTISAN_COLOR}}"
else
    ENV_FILE="${ARTISAN_ENV_FILE:-${DEPLOY_DIR}/.env}"
    ARTISAN_COMPOSE_PROJECT="${ARTISAN_COMPOSE_PROJECT:-${COMPOSE_PROJECT_BASE}}"
fi

log() { echo "[artisan] $(date '+%H:%M:%S') $*"; }
err() { echo "[artisan] ERROR: $*" >&2; exit 1; }

usage() {
    cat <<'EOF'
Usage: ./artisan.sh <artisan-command> [args...]

Runs php artisan inside the running php-fpm container for the production
deployment compose stack.

Examples:
  ./artisan.sh about
  ./artisan.sh optimize:clear --no-ansi
EOF
}

if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
    usage
    exit 0
fi

[[ $# -gt 0 ]] || {
    usage >&2
    err "No artisan command provided."
}
[[ -f "${COMPOSE_FILE}" ]] || err "Compose file not found: ${COMPOSE_FILE}"
[[ -f "${ENV_FILE}" ]] || err "Generated env file not found: ${ENV_FILE}. Run deploy/generate-env first."

log "Running artisan command in php-fpm container: $*"

exec_args=(exec)
if [[ "${ARTISAN_TTY:-false}" != "true" ]]; then
    exec_args+=(-T)
fi

docker compose \
    -p "${ARTISAN_COMPOSE_PROJECT}" \
    -f "${COMPOSE_FILE}" \
    --env-file "${ENV_FILE}" \
    "${exec_args[@]}" php-fpm \
    php artisan "$@"
