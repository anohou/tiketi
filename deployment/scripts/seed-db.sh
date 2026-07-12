#!/usr/bin/env bash
# ╔══════════════════════════════════════════════════════════════════════════════╗
# ║  seed-db.sh — Explicit Database Seeder Utility                              ║
# ║                                                                              ║
# ║  Securely triggers Laravel database seeders on the production database.      ║
# ║  Requires manual human confirmation to prevent accidental overwrites.        ║
# ╚══════════════════════════════════════════════════════════════════════════════╝
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
source "${SCRIPT_DIR}/deploy.config.sh"

log() { echo "[seed-db] $(date '+%H:%M:%S') $*"; }
err() { echo "[seed-db] ERROR: $*" >&2; exit 1; }

if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
    echo "Usage: ./seed-db.sh [version-tag]"
    echo ""
    echo "Securely triggers Laravel database seeders on the production database."
    echo "Optional env:"
    echo "  SEEDER_CLASS=<SeederClass>   Run a specific Laravel seeder class"
    echo "  AUTO_APPROVE=true            Skip the interactive confirmation prompt"
    echo "Requires manual human confirmation to prevent accidental overwrites."
    exit 0
fi

VERSION="${1:-$(cat "${DEPLOY_DIR}/.last-built-version" 2>/dev/null || echo "latest")}"
DEPLOY_IMAGE_REF="${DEPLOY_IMAGE_REF:-$(grep -m1 '^DEPLOY_IMAGE_REFERENCE=' "${DEPLOY_DIR}/.env" 2>/dev/null | cut -d= -f2- | tr -d '\r' || true)}"
SEED_IMAGE_REF="${DEPLOY_IMAGE_REF:-${APP_IMAGE}:${VERSION}}"
SEEDER_CLASS="${SEEDER_CLASS:-DatabaseSeeder}"

if [[ ! "${SEEDER_CLASS}" =~ ^[A-Za-z_\\][A-Za-z0-9_\\]*$ ]]; then
    err "SEEDER_CLASS must be a valid Laravel seeder class name"
fi

if [[ -x "${SCRIPT_DIR}/generate-env.sh" ]]; then
    log "Generating deployment env ..."
    bash "${SCRIPT_DIR}/generate-env.sh" </dev/null
fi

[[ -f "${DEPLOY_DIR}/.env" ]] || err "Missing ${DEPLOY_DIR}/.env after env generation"

if [[ "${AUTO_APPROVE:-false}" != "true" ]]; then
    log "Warning: Running seeders on an existing database might duplicate records."
    read -p "Are you sure you want to run the database seeders? (y/N): " confirm

    if [[ "$confirm" != "y" && "$confirm" != "Y" ]]; then
        log "Seeder cancelled."
        exit 0
    fi
fi

db_connection="$(grep -m1 '^DB_CONNECTION=' "${DEPLOY_DIR}/.env" | cut -d= -f2- | tr -d '\r')"
APP_OWNER_ROLE=""
if [[ "${db_connection}" == "pgsql" ]]; then
    source "${SCRIPT_DIR}/rbac.config.sh"
    RUNTIME_USER="$(grep -m1 "^DB_USERNAME=" "${DEPLOY_DIR}/.env" | cut -d= -f2- | tr -d '\r')"
    APP_OWNER_ROLE="$(derive_owner_role "${RUNTIME_USER}")" || err "Failed to derive owner role"
fi

MIG_USER="$(require_project_secret_value "DB_MIGRATOR_USERNAME")"
MIG_PASS="$(require_project_secret_value "DB_MIGRATOR_PASSWORD")"

log "Running seeder ${SEEDER_CLASS} using image ${SEED_IMAGE_REF} ..."

docker run --rm \
    --env-file "${DEPLOY_DIR}/.env" \
    --env "DB_USERNAME=${MIG_USER}" \
    --env "DB_PASSWORD=${MIG_PASS}" \
    --network "${DB_NETWORK}" \
    "${SEED_IMAGE_REF}" \
    sh -lc "$(
        if [[ "${db_connection}" == "pgsql" ]]; then
            printf 'PGOPTIONS=%q php artisan db:seed --force --no-ansi --class=%q' "-c role=${APP_OWNER_ROLE}" "${SEEDER_CLASS}"
        else
            printf 'php artisan db:seed --force --no-ansi --class=%q' "${SEEDER_CLASS}"
        fi
    )" \
    || err "Database seeding failed."

log "✓ Database seeded successfully with ${SEEDER_CLASS}!"
