#!/usr/bin/env sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
repo_root=$(CDPATH= cd -- "$script_dir/.." && pwd)

echo "Validating database migrations..."

issues=0

# Check for migrations without down() methods
if git rev-parse --verify HEAD >/dev/null 2>&1; then
  # Get migrations from HEAD to current state (already committed)
  migration_files=$(find "$repo_root/database/migrations" -name '*.php' 2>/dev/null || true)
  
  for file in $migration_files; do
    if ! grep -q "public function down()" "$file"; then
      echo "⚠️  Warning: Migration $file missing down() method"
      issues=$((issues + 1))
    fi
  done
fi

# Check staged migrations for destructive patterns
staged_migrations=$(git diff --cached --name-only 2>/dev/null | grep -E 'database/migrations/.*\.php$' || true)

if [ -n "$staged_migrations" ]; then
  echo ""
  echo "Checking staged migrations for destructive operations..."
  
  for file in $staged_migrations; do
    if [ -f "$file" ]; then
      if git diff --cached "$file" 2>/dev/null | grep -qE '^\+.*\b(dropIfExists|drop|truncate)\b'; then
        echo "⚠️  Notice: Destructive operation detected in $file"
        echo "  Ensure this is intentional and documented"
        issues=$((issues + 1))
      fi
    fi
  done
fi

if [ "$issues" -gt 0 ]; then
  echo ""
  echo "Migration validation complete: $issues issue(s) found (informational only)"
else
  echo "✓ Migration validation passed"
fi

# Always exit 0 for CI (report only, not blocking)
exit 0
