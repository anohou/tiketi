#!/usr/bin/env sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
repo_root=$(CDPATH= cd -- "$script_dir/.." && pwd)

pattern='(gh[pousr]_[A-Za-z0-9]{20,}|AIza[0-9A-Za-z\-_]{35}|AKIA[0-9A-Z]{16}|sk_live_[A-Za-z0-9]{16,}|xox[baprs]-[A-Za-z0-9-]{10,}|-----BEGIN (RSA|EC|DSA|OPENSSH) PRIVATE KEY-----)'

found=0

find "$repo_root" \
  \( -path "$repo_root/.git" -o -path "$repo_root/vendor" -o -path "$repo_root/node_modules" -o -path "$repo_root/storage" -o -path "$repo_root/public/build" -o -path "$repo_root/bootstrap/cache" \) -prune -o \
  -type f \( -name '*.php' -o -name '*.js' -o -name '*.ts' -o -name '*.tsx' -o -name '*.vue' -o -name '*.json' -o -name '*.env' -o -name '*.yml' -o -name '*.yaml' -o -name '*.md' -o -name '*.sh' \) -print | while IFS= read -r file; do
  if grep -I -nE "$pattern" "$file" >/dev/null 2>&1; then
    echo "Potential secret pattern found in $file"
    grep -I -nE "$pattern" "$file"
    found=1
  fi
done

if [ "$found" -ne 0 ]; then
  echo "Secret scan failed. Review the matches above before pushing."
  exit 1
fi

echo "Secret scan passed."
