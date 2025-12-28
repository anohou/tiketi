#!/usr/bin/env bash
# Deployment wrapper script
exec "$(dirname "$0")/scripts/deploy.sh" "$@"
