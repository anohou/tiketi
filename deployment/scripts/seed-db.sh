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
    echo "Requires manual human confirmation to prevent accidental overwrites."
    exit 0
fi

VERSION="${1:-$(cat "${DEPLOY_DIR}/.last-built-version" 2>/dev/null || echo "latest")}"
DEPLOY_IMAGE_REF="${DEPLOY_IMAGE_REF:-$(grep -m1 '^DEPLOY_IMAGE_REFERENCE=' "${DEPLOY_DIR}/.env" 2>/dev/null | cut -d= -f2- | tr -d '\r' || true)}"
SEED_IMAGE_REF="${DEPLOY_IMAGE_REF:-${APP_IMAGE}:${VERSION}}"

log "Warning: Running seeders on an existing database might duplicate records."
read -p "Are you sure you want to run the database seeders? (y/N): " confirm

if [[ "$confirm" != "y" && "$confirm" != "Y" ]]; then
    log "Seeder cancelled."
    exit 0
fi

# Load configs
source "${SCRIPT_DIR}/rbac.config.sh"
RUNTIME_USER=$(grep -m1 "^DB_USERNAME=" "${DEPLOY_DIR}/.env" | cut -d= -f2- | tr -d '\r')
APP_OWNER_ROLE="$(derive_owner_role "${RUNTIME_USER}")" || err "Failed to derive owner role"

MIG_USER=$(grep -m1 "^DB_MIGRATOR_USERNAME=" "${DEPLOY_DIR}/.env" | cut -d= -f2- | tr -d '\r')
MIG_PASS=$(grep -m1 "^DB_MIGRATOR_PASSWORD=" "${DEPLOY_DIR}/.env" | cut -d= -f2- | tr -d '\r')

log "Running database seeders using image ${SEED_IMAGE_REF} ..."

docker run --rm \
    --env-file "${DEPLOY_DIR}/.env" \
    --env "DB_USERNAME=${MIG_USER}" \
    --env "DB_PASSWORD=${MIG_PASS}" \
    --env "PGOPTIONS=-c role=${APP_OWNER_ROLE}" \
    --network "${DB_NETWORK}" \
    "${SEED_IMAGE_REF}" \
    php artisan db:seed --force --no-ansi \
    || err "Database seeding failed."

log "✓ Database seeded successfully!"
