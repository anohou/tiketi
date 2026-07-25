#!/usr/bin/env sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
repo_root=$(CDPATH= cd -- "$script_dir/.." && pwd)

failed=0

# Check for .env files being committed (critical security check)
if git diff --cached --name-only 2>/dev/null | grep -E '\.env($|\..*$)' >/dev/null 2>&1; then
  echo "❌ Error: .env files should never be committed to git"
  echo "These contain secrets and should be in .gitignore:"
  git diff --cached --name-only 2>/dev/null | grep -E '\.env($|\..*$)' | sed 's/^/  - /'
  failed=1
fi

if [ "$failed" -ne 0 ]; then
  echo ""
  echo "Environment check failed. Remove .env files from staging before pushing."
  exit 1
fi

echo "✓ Environment check passed"

