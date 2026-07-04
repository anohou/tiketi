#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
source "${SCRIPT_DIR}/deploy.config.sh"

COMPOSE_FILE="${DEPLOY_DIR}/config/docker-compose.prod.yml"
ACTIVE_COLOR_FILE="${DEPLOY_DIR}/.deploy/active-color"
PHP_SHELL_COLOR="${PHP_SHELL_COLOR:-$(cat "${ACTIVE_COLOR_FILE}" 2>/dev/null || true)}"

if [[ -z "${PHP_SHELL_ENV_FILE:-}" && -n "${PHP_SHELL_COLOR}" && -f "${DEPLOY_DIR}/.env.${PHP_SHELL_COLOR}" ]]; then
    ENV_FILE="${DEPLOY_DIR}/.env.${PHP_SHELL_COLOR}"
    PHP_SHELL_COMPOSE_PROJECT="${PHP_SHELL_COMPOSE_PROJECT:-${COMPOSE_PROJECT_BASE}-${PHP_SHELL_COLOR}}"
else
    ENV_FILE="${PHP_SHELL_ENV_FILE:-${DEPLOY_DIR}/.env}"
    PHP_SHELL_COMPOSE_PROJECT="${PHP_SHELL_COMPOSE_PROJECT:-${COMPOSE_PROJECT_BASE}}"
fi

err() { echo "[php-shell] ERROR: $*" >&2; exit 1; }

[[ -f "${COMPOSE_FILE}" ]] || err "Compose file not found: ${COMPOSE_FILE}"
[[ -f "${ENV_FILE}" ]] || err "Generated env file not found: ${ENV_FILE}. Run deploy/generate-env first."

exec docker compose \
    -p "${PHP_SHELL_COMPOSE_PROJECT}" \
    -f "${COMPOSE_FILE}" \
    --env-file "${ENV_FILE}" \
    exec php-fpm \
    sh
