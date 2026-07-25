#!/usr/bin/env sh
set -eu

echo "Running pre-push quality checks..."
echo ""

if command -v composer >/dev/null 2>&1; then
  echo "Validating composer manifest..."
  composer validate --strict
  
  echo "Checking composer dependencies for security issues..."
  composer audit --locked
else
  echo "composer not found; skipping PHP composer validate"
fi

if command -v npm >/dev/null 2>&1 && [ -f package-lock.json ]; then
  echo "Checking npm dependencies for security issues..."
  npm audit --omit=dev --audit-level=high
else
  echo "npm or package-lock.json not found; skipping production dependency audit"
fi

if [ -x ./scripts/env-check.sh ]; then
  echo "Validating environment variables..."
  ./scripts/env-check.sh
else
  echo "env check script not found; skipping"
fi

if [ -x ./scripts/secret-scan.sh ]; then
  echo "Scanning for secrets..."
  ./scripts/secret-scan.sh
else
  echo "secret scan script not found; skipping"
fi

if command -v php >/dev/null 2>&1 && [ -f artisan ]; then
  echo "Running all PHP tests..."
  APP_ENV=testing \
  CACHE_STORE=array \
  SESSION_DRIVER=array \
  QUEUE_CONNECTION=sync \
  php artisan test --no-ansi
else
  echo "php or artisan not available; skipping PHP tests"
fi

echo ""
echo "✓ Pre-push checks passed"
