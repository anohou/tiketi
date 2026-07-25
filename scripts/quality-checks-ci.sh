#!/usr/bin/env sh
set -eu

echo "Running full CI quality checks..."
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

if [ -x ./vendor/bin/pint ] || [ -f ./vendor/bin/pint ]; then
  echo "Checking PHP code style..."
  ./vendor/bin/pint --test || echo "⚠️  Pint style issues found (informational only)"
else
  echo "pint not available; skipping PHP style checks"
fi

if command -v php >/dev/null 2>&1 && [ -f artisan ]; then
  echo "Running all tests..."
  php artisan test --no-ansi
else
  echo "php or artisan not available; skipping phpunit tests"
fi

if [ -x ./scripts/migration-check.sh ]; then
  echo "Validating database migrations..."
  ./scripts/migration-check.sh
else
  echo "migration check script not found; skipping"
fi

if [ -f composer.json ] && command -v php >/dev/null 2>&1; then
  if [ -f ./vendor/bin/phpstan ] || [ -f ./vendor/bin/larastan ]; then
    echo "Running static analysis..."
    if [ -f ./vendor/bin/phpstan ]; then
      ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G
    else
      ./vendor/bin/larastan analyse --no-progress --memory-limit=1G
    fi
  else
    echo "phpstan/larastan not installed; skipping static analysis"
  fi
else
  echo "composer.json or php not available; skipping static analysis"
fi

echo ""
echo "✓ CI checks completed"
