#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

exec env \
    DEPLOY_BUILD_SOURCE=local \
    DEPLOY_ALLOW_LOCAL_BUILD=true \
    "${SCRIPT_DIR}/deploy.sh" "$@"
