#!/usr/bin/env bash
# ╔══════════════════════════════════════════════════════════════════════════════╗
# ║  cleanup.sh — Remove stopped containers and dangling images                ║
# ║  Usage: ./cleanup.sh                                                       ║
# ╚══════════════════════════════════════════════════════════════════════════════╝
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/deploy.config.sh"

log()  { echo "[cleanup] $*"; }

log "Removing stopped containers matching '${ORPHAN_PATTERN}' ..."
STOPPED=$(docker ps -a --filter "status=exited" --format '{{.Names}}' \
    | grep -E "${ORPHAN_PATTERN}" 2>/dev/null || true)

if [[ -n "$STOPPED" ]]; then
    echo "$STOPPED" | xargs docker rm -v
    log "✓ Removed: ${STOPPED//$'\n'/, }"
else
    log "  No stopped orphan containers found."
fi

log "Pruning dangling images ..."
docker image prune -f

log "✓ Cleanup complete."
