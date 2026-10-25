#!/bin/bash
set -e

echo "==> Building and starting containers..."
docker compose up -d --build

echo "==> Waiting for database to be ready..."
docker compose exec db sh -c 'until pg_isready -U tiketi -d transport; do sleep 1; done'

echo "==> Creating PostgreSQL schemas..."
docker compose exec db psql -U tiketi -d transport -c "CREATE SCHEMA IF NOT EXISTS app;"
docker compose exec db psql -U tiketi -d transport -c "CREATE SCHEMA IF NOT EXISTS public;" 2>/dev/null || true

echo "==> Installing composer dependencies..."
docker compose exec app composer install --no-interaction --prefer-dist

echo "==> Generating APP_KEY if missing..."
APP_KEY=$(grep '^APP_KEY=' .env | cut -d= -f2)
if [ -z "$APP_KEY" ]; then
    docker compose exec app php artisan key:generate
fi

echo "==> Running landlord migrations..."
docker compose exec app php artisan migrate --path=database/landlord_migrations --force

echo "==> Running seeders (creates test tenant + seeds data)..."
docker compose exec app php artisan db:seed --force

echo "==> Adding test.localhost to /etc/hosts (requires sudo)..."
if ! grep -q "test.localhost" /etc/hosts; then
    sudo sh -c 'echo "127.0.0.1 test.localhost" >> /etc/hosts'
    echo "    -> test.localhost added."
else
    echo "    -> test.localhost already in /etc/hosts."
fi

echo "==> Creating storage symlink..."
docker compose exec app php artisan storage:link 2>/dev/null || true

echo ""
echo "============================================================"
echo "  Application ready!"
echo "============================================================"
echo ""
echo "  Landlord   : http://localhost:8006"
echo "  Tenant test: http://test.localhost:8006/login"
echo ""
echo "  Identifiants tenant :"
echo "    Admin      : admin@transport.ci / password"
echo "    Vendeur    : guichet.abidjan@transport.ci / password"
echo "    Superviseur: superviseur@transport.ci / password"
echo ""
echo "  WebSocket Reverb : ws://localhost:6001"
echo "============================================================"
