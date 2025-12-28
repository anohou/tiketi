#!/bin/sh
# Container startup script
# Runs as www-data user

set -e

echo "[Startup] API Container Starting..."

# Install/update composer dependencies FIRST (before generate-env which uses artisan)
# Skip if vendor directory exists (CI/CD built images have this already)
if [ ! -d "vendor" ]; then
    echo "[Startup] Installing composer dependencies..."
    if [ "$APP_ENV" = "production" ]; then
        composer install --no-interaction --no-progress --prefer-dist --optimize-autoloader
    else
        composer install --no-interaction --no-progress --prefer-dist
    fi
else
    echo "[Startup] Composer dependencies already installed (CI/CD image)"
fi

# Generate .env file from YAML config and secrets (now vendor exists)
echo "[Startup] Generating .env file..."
/usr/local/bin/generate-env.sh

# Verify frontend assets for full-stack apps
# Use APP_TYPE environment variable to determine if frontend validation is needed
if [ "${APP_TYPE:-api-only}" = "fullstack" ]; then
    echo "[Startup] Full-stack application detected - verifying frontend assets..."

    if [ ! -d "public/build" ] || [ ! -f "public/build/manifest.json" ]; then
        echo "[Startup] Frontend assets missing (likely overwritten by volume mount)"

        # Restore from backup if available
        if [ -d "/opt/vite-build-backup" ] && [ "$(ls -A /opt/vite-build-backup 2>/dev/null)" ]; then
            echo "[Startup] Restoring frontend assets from image backup..."
            mkdir -p public/build
            cp -r /opt/vite-build-backup/* public/build/

            if [ -f "public/build/manifest.json" ]; then
                echo "[Startup] ✓ Frontend assets restored successfully"
            else
                echo "[WARNING] Failed to restore frontend assets"
            fi
        else
            echo "[WARNING] No backup found - frontend assets not available"
            echo "[WARNING] Container will start but frontend may not work correctly"
        fi

        ls -la public/ || echo "public/ directory not found"
        ls -la public/build/ 2>/dev/null || echo "public/build/ directory not found"
    else
        echo "[Startup] ✓ Frontend assets verified (manifest.json found)"
    fi
else
    echo "[Startup] API-only application - skipping frontend asset check"
fi


# Wait for database (only if secrets are mounted and not local, and not skipped)
if [ "${SKIP_DB_CHECK:-false}" != "true" ] && [ "$APP_ENV" != "local" ] && ls /var/www/html/.env.secrets* > /dev/null 2>&1; then
    echo "[Startup] Waiting for database..."
    timeout=10  # Reduced from 60 to 10 seconds
    elapsed=0
    until php artisan db:show > /dev/null 2>&1; do
        echo "[Startup] Database not ready, waiting..."
        sleep 2
        elapsed=$((elapsed + 2))
        if [ $elapsed -ge $timeout ]; then
            echo "[Startup] Database wait timeout (${timeout}s) - continuing anyway"
            break
        fi
    done
    if [ $elapsed -lt $timeout ]; then
        echo "[Startup] Database is ready"
    fi
elif [ "${SKIP_DB_CHECK:-false}" = "true" ]; then
    echo "[Startup] Database check skipped (SKIP_DB_CHECK=true)"
fi

# Run migrations (if enabled)
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "[Startup] Running migrations..."
    php artisan migrate --force
fi

# Clear file-based caches (no database connection needed)
echo "[Startup] Clearing file-based caches..."
php artisan config:clear
php artisan route:clear
# Note: cache:clear requires DB/Redis - skip on startup

# Ensure storage directories and bootstrap/cache exist with .gitignore files
echo "[Startup] Ensuring storage directories exist..."
for dir in storage/app/public storage/app/private storage/framework/cache storage/framework/sessions storage/framework/testing storage/framework/views storage/logs bootstrap/cache; do
    mkdir -p "$dir"
    if [ ! -f "$dir/.gitignore" ]; then
        echo "*" > "$dir/.gitignore"
        echo "!.gitignore" >> "$dir/.gitignore"
    fi
done

# Set permissions
echo "[Startup] Setting permissions..."
# Files are already owned by sail from Docker build
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

echo "[Startup] Starting Supervisor..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
