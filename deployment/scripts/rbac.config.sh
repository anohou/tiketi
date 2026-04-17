#!/usr/bin/env bash
# ╔══════════════════════════════════════════════════════════════════════════════╗
# ║  rbac.config.sh — Backward-compatibility shim                              ║
# ║                                                                             ║
# ║  All RBAC role/privilege defaults are defined in config/rbac.yml.          ║
# ║  This file simply delegates to lib/rbac.sh so scripts can source a stable   ║
# ║  entrypoint just like deploy.config.sh.                                     ║
# ╚══════════════════════════════════════════════════════════════════════════════╝

source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/rbac.sh"
