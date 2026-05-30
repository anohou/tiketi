#!/usr/bin/env sh
set -euo pipefail

# Run PHP quality checks (composer validate + Pint)
if command -v composer >/dev/null 2>&1; then
  composer validate --strict
else
  echo "composer not found; skipping PHP composer validate"
fi

if [ -x ./vendor/bin/pint ] || [ -f ./vendor/bin/pint ]; then
  ./vendor/bin/pint --test
else
  echo "pint not available; skipping PHP style checks"
fi

# Run PHPunit tests (non-interactive)
if command -v php >/dev/null 2>&1 && [ -f artisan ]; then
  php artisan test --no-ansi
else
  echo "php or artisan not available; skipping phpunit tests"
fi
