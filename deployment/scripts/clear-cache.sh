#!/usr/bin/env bash
# ╔══════════════════════════════════════════════════════════════════════════════╗
# ║  clear-cache.sh — Application Cache Wiping Utility                          ║
# ║                                                                              ║
# ║  Completely clears Laravel's config, views, routes, and compiled services.   ║
# ║  Safely connects to the running PHP-FPM container to execute optimize:clear ║
# ╚══════════════════════════════════════════════════════════════════════════════╝
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

log() { echo "[cache] $(date '+%H:%M:%S') $*"; }
err() { echo "[cache] ERROR: $*" >&2; exit 1; }

log "Clearing all application cache buffers inside running container..."

"${SCRIPT_DIR}/artisan.sh" optimize:clear --no-ansi \
    || err "Cache wipe failed."

log "✓ Cache flawlessly wiped."
