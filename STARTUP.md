# 🚌 Billeterie — Startup Guide

> Multi-tenant ticketing platform built with Laravel + Inertia.js + Vue 3 + stancl/tenancy (separate databases per tenant).

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Prerequisites](#2-prerequisites)
3. [Development Setup](#3-development-setup)
4. [Creating Tenants (Dev)](#4-creating-tenants-dev)
5. [Starting Services (Dev)](#5-starting-services-dev)
6. [Production Deployment](#6-production-deployment)
7. [Creating Tenants (Production)](#7-creating-tenants-production)
8. [User Roles & Default Credentials](#8-user-roles--default-credentials)
9. [Troubleshooting](#9-troubleshooting)

---

## 1. Architecture Overview

```
┌─────────────────────────────────────────────────────┐
│               CENTRAL DATABASE (Landlord)            │
│  DB: tiketi (local) / transport_central (prod)       │
│  Tables: tenants, domains, users (super-admins)      │
└──────────────────┬──────────────────────────────────┘
                   │ stancl/tenancy routes by hostname
       ┌───────────┴───────────┐
       ▼                       ▼
┌─────────────┐         ┌─────────────┐
│ Tenant DB A │         │ Tenant DB B │
│ app_tenant_ │         │ app_tenant_ │
│ bil_<id>    │         │ bil_<id>    │
│             │         │             │
│ users       │         │ users       │
│ stations    │         │ stations    │
│ routes      │         │ routes      │
│ vehicles    │         │ vehicles    │
│ trips       │         │ trips       │
│ tickets     │         │ tickets     │
│ ...         │         │ ...         │
└─────────────┘         └─────────────┘

Domain routing:
  localhost        → Central (Landlord) app
  test.localhost   → Tenant app (tenant ID = "test")
  agency.transport.ci → Tenant app (production)
```

**Key concepts:**
- The **central domain** (`localhost` / `admin.transport.ci`) is the landlord — it manages tenants.
- Each **tenant subdomain** (`test.localhost`, `agency-a.transport.ci`) gets its own **isolated MySQL database** named `app_tenant_bil_<tenant_id>`.
- All cache operations are **automatically tenant-scoped** via Redis tags (requires Redis).

---

## 2. Prerequisites

### Required on all environments

| Tool | Min. Version | Notes |
|---|---|---|
| PHP | 8.2+ | With extensions: pdo_mysql, mbstring, xml, bcmath, openssl, tokenizer, fileinfo |
| Composer | 2.x | |
| Node.js | 18+ | |
| MySQL / MariaDB | 8.0+ | DB user **must** have `CREATE DATABASE` privilege |
| Redis | 7+ | **Required** — cache tagging via `stancl/tenancy` needs it |

### macOS (Development)

```bash
# Install Homebrew if not present
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

# Install dependencies
brew install php composer node mysql redis

# Start services
brew services start mysql
brew services start redis

# Verify Redis is working
redis-cli ping   # → PONG
```

### Ubuntu / Debian (Production)

```bash
sudo apt update
sudo apt install -y php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-xml \
    php8.2-bcmath php8.2-zip php8.2-curl php8.2-redis \
    mysql-server redis-server nginx certbot python3-certbot-nginx

# Enable & start services
sudo systemctl enable mysql redis nginx
sudo systemctl start mysql redis nginx
```

---

## 3. Development Setup

### Step 1 — Clone & install dependencies

```bash
git clone <repo-url> billeterie
cd billeterie

composer install
npm install
```

### Step 2 — Environment file

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` with your local values:

```env
APP_NAME=Billeterie
APP_ENV=local
APP_URL=http://localhost:8000

# Central DB (Landlord)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tiketi           # Central/landlord database name
DB_USERNAME=root
DB_PASSWORD=your_password    # Must have CREATE DATABASE privilege

# Sessions (database driver is fine for local)
SESSION_DRIVER=database

# Cache — MUST be redis (stancl/tenancy uses cache tagging)
CACHE_STORE=redis
REDIS_CLIENT=predis          # Pure PHP, no extension needed
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# WebSockets (Reverb)
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=687642
REVERB_APP_KEY=dk94gvkr3yw6gyeicilu
REVERB_APP_SECRET=d4dqhrehpmemiqtwddxt
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

> [!IMPORTANT]
> `CACHE_STORE=redis` is **mandatory**. `stancl/tenancy` automatically adds tenant tags to all cache operations. The `database` and `file` drivers do not support tagging and will throw: `This cache store does not support tagging`.

### Step 3 — Create the central (landlord) database

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS tiketi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### Step 4 — Run landlord migrations

The landlord migrations live in `database/landlord_migrations/` and create the `tenants` and `domains` tables in the central DB.

```bash
php artisan migrate --path=database/landlord_migrations
```

### Step 5 — Run tenant migrations (per tenant)

Tenant-specific migrations (stations, routes, vehicles, trips, tickets, etc.) live in `database/migrations/`. They run **inside each tenant's database** via:

```bash
# After creating a tenant (see step 4 below)
php artisan tenants:migrate
```

### Step 6 — Build frontend assets

```bash
npm run dev      # Development (hot reload via Vite)
# OR
npm run build    # Production build
```

---

## 4. Creating Tenants (Dev)

### Option A — Artisan (recommended for local)

```bash
php artisan tinker
```

Then in tinker:

```php
// Create a tenant with ID "test" → domain "test.localhost"
$tenant = App\Models\Tenant::create(['id' => 'test']);
$tenant->domains()->create(['domain' => 'test.localhost']);

// The above automatically:
// 1. Creates database: app_tenant_bil_test
// 2. Runs all tenant migrations in that database
// 3. Runs TenantSeeder inside that database
```

### Option B — Seed a demo tenant

```bash
php artisan db:seed --class=Database\\Seeders\\DatabaseSeeder
```

> This requires a `DatabaseSeeder` that calls `TenantSeeder`. Check `database/seeders/DatabaseSeeder.php`.

### What TenantSeeder creates

When a tenant is seeded (`TenantSeeder.php`), it creates inside the tenant DB:

- **Users** (see credentials in [Section 8](#8-user-roles--default-credentials))
- **Stations**: Abidjan, Yamoussoukro, Bouaké, Katiola, Korhogo, Adzopé, Abengourou, Agnibilékrou, Bondoukou…
- **Vehicle types**: Minibus 15, Bus 30, Bus 50 (2+2 and 3+2), Bus 70 (2+2 and 3+2), Double-decker 80
- **Vehicles**: Multiple vehicles per type
- **Routes**: Abidjan → Korhogo, Abidjan → Bondoukou
- **Route fares**: All segment combinations with prices
- **Trips**: Today's and tomorrow's scheduled departures

### Re-seed a specific tenant

```bash
php artisan tenants:seed --tenants=test
```

---

## 5. Starting Services (Dev)

Open **4 separate terminal tabs**:

```bash
# Tab 1 — Laravel HTTP server
php artisan serve
# → http://localhost:8000 (landlord / central)
# → http://test.localhost:8000 (tenant "test")

# Tab 2 — Vite asset bundler (hot reload)
npm run dev

# Tab 3 — Reverb WebSocket server (real-time seat map)
php artisan reverb:start

# Tab 4 — Queue worker (background jobs: tenant creation, emails…)
php artisan queue:work
```

> [!TIP]
> For `test.localhost` to resolve, add it to `/etc/hosts`:
> ```
> 127.0.0.1   test.localhost
> ```

### Accessing the platform (local)

| URL | Description |
|---|---|
| `http://localhost:8000` | Central / Landlord app |
| `http://test.localhost:8000` | Tenant "test" app |
| `http://test.localhost:8000/login` | Tenant login page |

---

## 6. Production Deployment

### Step 1 — DNS Records

```
# Central admin domain
A record:  admin.transport.ci    → YOUR_SERVER_IP

# Wildcard for all tenant subdomains
A record:  *.transport.ci        → YOUR_SERVER_IP
```

### Step 2 — Server setup (Ubuntu / Nginx)

```bash
# Clone to server
git clone <repo-url> /var/www/billeterie
cd /var/www/billeterie

# Install PHP dependencies (no dev)
composer install --optimize-autoloader --no-dev

# Build frontend assets
npm install && npm run build

# Set permissions
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### Step 3 — Production `.env`

```env
APP_NAME=Billeterie
APP_ENV=production
APP_DEBUG=false
APP_URL=https://transport.ci
APP_KEY=                        # Run: php artisan key:generate

# Central DB
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=transport_central   # Central/landlord database
DB_USERNAME=svc_app_rw          # Must have CREATE DATABASE privilege
DB_PASSWORD=your_secure_password

# Tenant DB prefix — creates databases named: app_tenant_bil_<id>
TENANT_DB_PREFIX=app_tenant_bil_

# Sessions
SESSION_DRIVER=database
SESSION_DOMAIN=.transport.ci    # Leading dot allows all subdomains

# Cache — REQUIRED: redis (not file or database)
CACHE_STORE=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=your_redis_password
REDIS_PORT=6379

# Queue
QUEUE_CONNECTION=database

# WebSockets
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=your_app_id
REVERB_APP_KEY=your_app_key
REVERB_APP_SECRET=your_app_secret
REVERB_HOST=transport.ci
REVERB_PORT=8080
REVERB_SCHEME=https

# Mail
MAIL_MAILER=smtp
MAIL_HOST=smtp.yourmail.com
MAIL_PORT=587
MAIL_USERNAME=no-reply@transport.ci
MAIL_PASSWORD=your_mail_password
MAIL_FROM_ADDRESS=no-reply@transport.ci
MAIL_FROM_NAME="Billeterie"
```

### Step 4 — Nginx configuration

Create `/etc/nginx/sites-available/billeterie`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name transport.ci *.transport.ci;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name transport.ci *.transport.ci;

    root /var/www/billeterie/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/transport.ci/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/transport.ci/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/billeterie /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### Step 5 — SSL (wildcard certificate)

```bash
sudo certbot --nginx -d transport.ci -d *.transport.ci
# Note: wildcard requires DNS challenge (not HTTP challenge)
# Follow certbot prompts to add a TXT record to your DNS provider
```

### Step 6 — Create central database & run landlord migrations

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS transport_central CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

cd /var/www/billeterie
php artisan migrate --path=database/landlord_migrations --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Step 7 — Supervisor (process manager)

Install Supervisor to keep services alive:

```bash
sudo apt install supervisor
```

Create `/etc/supervisor/conf.d/billeterie.conf`:

```ini
[program:billeterie-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/billeterie/artisan queue:work --sleep=3 --tries=3 --max-time=3600
directory=/var/www/billeterie
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/billeterie/storage/logs/queue.log

[program:billeterie-reverb]
command=php /var/www/billeterie/artisan reverb:start --host=0.0.0.0 --port=8080
directory=/var/www/billeterie
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/billeterie/storage/logs/reverb.log
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
```

### Step 8 — Scheduler (cron)

```bash
sudo crontab -e -u www-data
```

Add:
```cron
* * * * * cd /var/www/billeterie && php artisan schedule:run >> /dev/null 2>&1
```

---

## 7. Creating Tenants (Production)

### Via Artisan (CLI)

```bash
cd /var/www/billeterie

php artisan tinker
```

```php
// Create agency "tsrci" → database: app_tenant_bil_tsrci
// Domain: tsrci.transport.ci
$tenant = App\Models\Tenant::create(['id' => 'tsrci']);
$tenant->domains()->create(['domain' => 'tsrci.transport.ci']);

// To also seed default data:
\Artisan::call('tenants:seed', ['--tenants' => 'tsrci']);
```

### What happens automatically

1. ✅ Database `app_tenant_bil_tsrci` is created
2. ✅ All tenant migrations are executed in the new database
3. ✅ Domain `tsrci.transport.ci` is registered

### Re-migrate all tenants (after schema changes)

```bash
php artisan tenants:migrate --force
```

### Re-seed a specific tenant

```bash
php artisan tenants:seed --tenants=tsrci
```

---

## 8. User Roles & Default Credentials

After running `TenantSeeder`, each tenant database contains these users (all with password `password`):

| Role | Email | Access |
|---|---|---|
| `admin` | `admin@transport.ci` | Full tenant administration |
| `supervisor` | `superviseur@transport.ci` | Trip supervision, ticket inspection |
| `seller` | `guichet.abidjan@transport.ci` | Ticket sales (Abidjan station) |
| `seller` | `guichet.korhogo@transport.ci` | Ticket sales (Korhogo station) |
| `accountant` | `comptable@transport.ci` | Financial reports |
| `executive` | `dg@transport.ci` | Read-only dashboard, analytics |

> [!CAUTION]
> Change all default passwords **immediately** after first login in production.

---

## 9. Troubleshooting

### ❌ "This cache store does not support tagging"

`CACHE_STORE` is set to `database` or `file`, which don't support tags. Fix:

```env
CACHE_STORE=redis
REDIS_CLIENT=predis   # or phpredis if the PHP extension is installed
```

Ensure Redis is running: `redis-cli ping` → `PONG`

Then:
```bash
php artisan config:clear
php artisan cache:clear
```

---

### ❌ "Class Redis not found"

The `phpredis` PHP extension is not installed. Either install it or switch to `predis`:

```bash
composer require predis/predis
```
```env
REDIS_CLIENT=predis
```

---

### ❌ Tenant domain not resolving (local dev)

Add to `/etc/hosts`:
```
127.0.0.1   test.localhost
127.0.0.1   agency-a.localhost
```

---

### ❌ "Could not connect to WebSocket server"

Ensure Reverb is running:
```bash
php artisan reverb:start
```

Check firewall allows port 8080 (production):
```bash
sudo ufw allow 8080/tcp
```

---

### ❌ Permission errors on storage

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

---

### ❌ Tenant DB not created

Verify the MySQL user has `CREATE DATABASE` privilege:
```sql
GRANT ALL PRIVILEGES ON *.* TO 'your_user'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
```

---

### ❌ Queue jobs not processing

```bash
# Start a worker manually to see errors
php artisan queue:work --verbose

# Or restart supervisor
sudo supervisorctl restart billeterie-queue:*
```

---

## Quick Reference — Common Commands

```bash
# Clear all caches
php artisan optimize:clear

# List all tenants
php artisan tinker --execute="App\Models\Tenant::all()->pluck('id')"

# Run migrations for all tenants
php artisan tenants:migrate --force

# Run migrations for one tenant
php artisan tenants:migrate --tenants=test --force

# Run seeder for one tenant
php artisan tenants:seed --tenants=test

# Check Redis connection
redis-cli ping

# Monitor queues in real-time
php artisan queue:monitor

# View logs
tail -f storage/logs/laravel.log
```
