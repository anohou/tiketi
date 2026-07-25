#!/usr/bin/env sh
set -euo pipefail

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
repo_root=$(CDPATH= cd -- "$script_dir/.." && pwd)

failed=0

# Check for .env files being committed
if git diff --cached --name-only 2>/dev/null | grep -qE '\.env($|\..*$)'; then
  echo "❌ Error: .env files should never be committed to git"
  echo "These contain secrets and should be in .gitignore:"
  git diff --cached --name-only | grep -E '\.env($|\..*$)' | sed 's/^/  - /'
  failed=1
fi

# Verify .env.example exists
if [ ! -f "$repo_root/.env.example" ]; then
  echo "⚠️  Warning: .env.example not found; skipping undocumented env var check"
else
  # Extract documented env var keys and check for undocumented ones in staged files
  documented=$(grep "^[A-Z_][A-Z0-9_]*=" "$repo_root/.env.example" 2>/dev/null | cut -d= -f1 | sort | uniq)
  
  # Check staged PHP/JS files (limit to reasonable count)
  staged_files=$(git diff --cached --name-only 2>/dev/null | grep -E '\.(php|js|jsx|ts|tsx)$' | head -20 || true)
  
  if [ -n "$staged_files" ]; then
    for file in $staged_files; do
      if [ -f "$file" ]; then
        # Find env() calls
        grep -oE "env\(['\"]([A-Z_][A-Z0-9_]*)['\"]" "$file" 2>/dev/null | sed -E "s/env\(['\"]([A-Z_][A-Z0-9_]*)['\"].*/\1/" | sort | uniq | while read -r key; do
          if ! echo "$documented" | grep -q "^$key$"; then
            echo "❌ Error: Undocumented env var in $file: $key"
            echo "  Add it to .env.example before pushing"
            failed=1
          fi
        done
      fi
    done
  fi
fi

if [ "$failed" -ne 0 ]; then
  echo ""
  echo "Environment check failed. Fix the issues above and try again."
  exit 1
fi

echo "✓ Environment check passed"

