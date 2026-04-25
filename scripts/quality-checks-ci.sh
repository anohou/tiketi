#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

manifest_path="public/build/manifest.json"
manifest_backup=""

cleanup() {
    if [[ -n "$manifest_backup" && -f "$manifest_backup" ]]; then
        mkdir -p "$(dirname "$manifest_path")"
        mv "$manifest_backup" "$manifest_path"
    fi
}

trap cleanup EXIT

if [[ -f "$manifest_path" ]]; then
    manifest_backup="$(mktemp "${TMPDIR:-/tmp}/tiketi-manifest.XXXXXX.json")"
    mv "$manifest_path" "$manifest_backup"
fi

env -i \
    HOME="${HOME}" \
    PATH="${PATH}" \
    TMPDIR="${TMPDIR:-/tmp}" \
    LANG="${LANG:-C.UTF-8}" \
    LC_ALL="${LC_ALL:-C.UTF-8}" \
    COMPOSER_PROCESS_TIMEOUT="${COMPOSER_PROCESS_TIMEOUT:-0}" \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_NO_AUDIT=1 \
    /bin/bash --noprofile --norc -c '
        set -euo pipefail
        cd "$1"
        composer validate --strict
        ./vendor/bin/pint --test
        php artisan test --no-ansi
    ' bash "$repo_root"
