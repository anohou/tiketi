#!/usr/bin/env bash
# ╔══════════════════════════════════════════════════════════════════════════════╗
# ║  deploy.config.sh — Backward-compatibility shim                             ║
# ║                                                                             ║
# ║  All deployment variables are now defined in config/config.yml.             ║
# ║  This file simply delegates to lib/config.sh so that every script that      ║
# ║  already sources deploy.config.sh continues to work without any changes.   ║
# ║                                                                             ║
# ║  To change a setting, edit config/config.yml — not this file.              ║
# ╚══════════════════════════════════════════════════════════════════════════════╝

source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/config.sh"
