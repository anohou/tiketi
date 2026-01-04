# Multi-Tenant Local Development Setup

This guide explains how to set up and test the multi-tenant functionality of Billeterie in your local development environment using Traefik as a reverse proxy.

## Table of Contents
- [Overview](#overview)
- [Prerequisites](#prerequisites)
- [Quick Start](#quick-start)
- [Detailed Setup](#detailed-setup)
- [Creating and Testing Tenants](#creating-and-testing-tenants)
- [Troubleshooting](#troubleshooting)
- [Testing Scenarios](#testing-scenarios)

---

## Overview

Billeterie uses `stancl/tenancy` for multi-tenancy with **separate databases** per tenant. This setup allows you to test multi-tenant subdomain routing locally:

- **Central/Landlord Domain**: `http://billeterie.localhost` - Admin interface for managing tenants
- **Tenant Subdomains**: `http://alpha.localhost`, `http://beta.localhost`, etc. - Individual tenant applications

## Prerequisites

1. **Docker and Docker Compose** installed
2. **Basic understanding** of multi-tenancy concepts
3. **Port availability**: Ensure ports 80, 8080, 3307, and 6379 are not in use

> **Note**: The `.localhost` TLD automatically resolves to `127.0.0.1` in most modern browsers, so no `/etc/hosts` configuration is needed.

---

## Quick Start

1. **Start Traefik** (reverse proxy for subdomain routing):
   ```bash
   cd deployment/docker
   docker compose -f docker-compose.traefik.local.yml up -d
   ```

2. **Deploy the application**:
   ```bash
   cd ..  # Back to deployment directory
   ./deploy.sh local deploy
   ```

3. **Access the application**:
   - Central domain: http://billeterie.localhost
   - Traefik dashboard: http://localhost:8080

4. **Create tenants** via the landlord interface and access them at `http://{tenant-id}.localhost`

---

## Detailed Setup

### Step 1: Start Traefik

Traefik is configured to automatically route requests to the appropriate service based on the hostname.

```bash
cd /Users/wyao/Workspace/1-anohou2/anohou-dev/billeterie/deployment/docker
docker compose -f docker-compose.traefik.local.yml up -d
```

**Verify Traefik is running**:
```bash
docker ps | grep traefik
```

You should see the `traefik_local` container running.

**Access the Traefik dashboard**: http://localhost:8080 or http://traefik.localhost

The dashboard shows:
- Active routers (billeterie-central, billeterie-tenants)
- Services and their health status
- Middleware configurations

### Step 2: Deploy the Billeterie Application

The deployment script will:
- Build the Docker image
- Start the application container
- Run database migrations
- Configure Laravel environment

```bash
cd /Users/wyao/Workspace/1-anohou2/anohou-dev/billeterie
./deployment/deploy.sh local deploy
```

**Expected output**:
```
✓ All required environment variables validated
✓ Local build complete: billeterie_local
✓ Storage directories configured with correct ownership
✓ Database connection successful
✓ Application is healthy!
```

### Step 3: Verify Setup

1. **Check containers are running**:
   ```bash
   docker ps
   ```
   You should see:
   - `traefik_local`
   - `dc_billeterie_local`
   - `billeterie_mysql_local`
   - `billeterie_redis_local`

2. **Access central domain**: http://billeterie.localhost

   You should see the Laravel application landing page or login screen.

3. **Check API health**: http://billeterie.localhost/api/health

   Should return `{"status": "ok"}` or similar.

### Step 4: Run Central Migrations

The central database (landlord) manages tenants and their domains.

```bash
docker exec dc_billeterie_local php artisan migrate --path=database/migrations/landlord --force
```

This creates:
- `tenants` table
- `domains` table
- User management tables (if applicable)

---

## Creating and Testing Tenants

### Create a Tenant via Artisan

```bash
docker exec -it dc_billeterie_local php artisan tinker
```

Then in the tinker shell:

```php
use App\Models\Tenant;

// Create a tenant
$tenant = Tenant::create([
    'id' => 'alpha',  // This becomes the subdomain
]);

// Add domain
$tenant->domains()->create([
    'domain' => 'alpha.localhost'
]);

// Run tenant migrations
php artisan tenants:migrate --tenants=alpha
```

Exit tinker with `exit` or `Ctrl+D`.

### Alternative: Create Tenant via Web Interface

1. **Access landlord admin**: http://billeterie.localhost/landlord/tenants
2. **Log in** with your admin credentials
3. **Click "Create Tenant"**
4. **Fill in the form**:
   - Tenant ID: `alpha` (used for subdomain and database name)
   - Domain: `alpha.localhost`
   - Additional tenant-specific fields
5. **Submit** - The system will:
   - Create database `t_alpha`
   - Run tenant migrations automatically
   - Seed initial data if configured

### Access Tenant Application

Once created, access the tenant at: http://alpha.localhost

You should see the tenant-specific application. The URL will show the tenant's subdomain, but the application is the same codebase with tenant-scoped data.

---

## Database Structure

### Central Database
- **Name**: `billeterie_dev` (configured in `.env.secrets.local`)
- **Contains**:
  - `tenants` - List of all tenants
  - `domains` - Domain mappings for tenants
  - Central admin users
  - Platform-wide settings

### Tenant Databases
- **Naming**: `t_{tenant_id}` (e.g., `t_alpha`, `t_beta`)
- **Contains**:
  - Tenant-specific data (users, orders, products, etc.)
  - Completely isolated from other tenants
  - Separate database per tenant ensures data isolation

### Connecting to Databases

**Central database**:
```bash
docker exec -it billeterie_mysql_local mysql -u billeterie_user -pbilleterie_pass billeterie_dev
```

**Tenant database** (example: alpha):
```bash
docker exec -it billeterie_mysql_local mysql -u billeterie_user -pbilleterie_pass t_alpha
```

---

## Troubleshooting

### Port Conflicts

**Issue**: Port 80 or 8080 already in use

**Solution**:
```bash
# Find what's using the port
lsof -i :80
lsof -i :8080

# Kill the process if safe
sudo kill -9 <PID>

# Or modify docker-compose.traefik.local.yml to use different ports
```

### 404 Not Found

**Issue**: Accessing tenant subdomain returns 404

**Possible causes**:
1. **Tenant not created**: Verify tenant exists in central database
   ```bash
   docker exec dc_billeterie_local php artisan tinker
   >>> App\Models\Tenant::with('domains')->get()
   ```

2. **Domain not added**: Ensure domain is properly registered
   ```bash
   >>> Stancl\Tenancy\Database\Models\Domain::all()
   ```

3. **Traefik routing issue**: Check Traefik dashboard at http://localhost:8080
   - Look for active routers
   - Verify routing rules match your subdomain

### Database Connection Errors

**Issue**: `SQLSTATE[HY000] [1045] Access denied`

**Solution**:
1. Verify database credentials in `.env.secrets.local`
2. Check central database connection:
   ```bash
   docker exec dc_billeterie_local php artisan db:show
   ```
3. Ensure MySQL container is running and healthy

### Cannot Access `.localhost` Domains

**Issue**: Browser cannot resolve `*.localhost`

**Solutions**:

1. **Try a different browser** - Chrome, Firefox, and Safari handle `.localhost` differently

2. **Add to `/etc/hosts`** (macOS/Linux):
   ```bash
   sudo nano /etc/hosts
   ```
   Add:
   ```
   127.0.0.1 billeterie.localhost
   127.0.0.1 alpha.localhost
   127.0.0.1 beta.localhost
   ```

3. **Use IP address** (temporary workaround):
   - Access via http://127.0.0.1 (will route to central)
   - Not ideal for multi-tenant testing

### Session/Cookie Issues

**Issue**: Not staying logged in or session shared across tenants

**Solution**:
- Verify `SESSION_DOMAIN` is set to `.localhost` in generated `.env` file
- Check cookie settings in browser dev tools
- Clear cookies and cache
- Ensure `SANCTUM_STATEFUL_DOMAINS` includes `.localhost`

### Traefik Not Routing

**Issue**: Traefik running but not routing requests

**Debugging steps**:

1. **Check Traefik logs**:
   ```bash
   docker logs traefik_local
   ```

2. **Verify app container labels**:
   ```bash
   docker inspect dc_billeterie_local | grep -A 20 Labels
   ```

3. **Check network connectivity**:
   ```bash
   docker network inspect traefik_local
   ```
   Ensure the app container is connected.

4. **Test direct access** (bypass Traefik):
   ```bash
   curl http://localhost:8000/api/health
   ```
   If this works, the issue is Traefik routing.

---

## Testing Scenarios

### Scenario 1: Data Isolation

**Objective**: Verify data in one tenant is not accessible from another

1. Create two tenants: `alpha` and `beta`
2. Access http://alpha.localhost and create test data (e.g., a user, order, or record)
3. Access http://beta.localhost
4. Verify the data from `alpha` is NOT visible in `beta`
5. Check databases directly:
   ```bash
   docker exec -it billeterie_mysql_local mysql -u billeterie_user -pbilleterie_pass
   ```
   ```sql
   USE t_alpha;
   SELECT * FROM users;

   USE t_beta;
   SELECT * FROM users;  -- Should be different
   ```

### Scenario 2: Session Isolation

**Objective**: Verify sessions are isolated between tenants

1. Log in to http://alpha.localhost
2. In a new browser tab, access http://beta.localhost
3. Verify you are NOT automatically logged in to beta
4. Log in to beta with a different account
5. Switch between tabs - each should maintain separate sessions

### Scenario 3: Custom Domains

**Objective**: Test tenant with custom domain (simulated locally)

1. Add custom domain to `/etc/hosts`:
   ```
   127.0.0.1 mycustomdomain.local
   ```

2. Create tenant with custom domain:
   ```php
   $tenant = Tenant::create(['id' => 'custom']);
   $tenant->domains()->create(['domain' => 'mycustomdomain.local']);
   ```

3. Access http://mycustomdomain.local
4. Verify tenant context is initialized correctly

### Scenario 4: File Storage Isolation

**Objective**: Verify file uploads are tenant-scoped

1. Upload a file in `alpha` tenant (e.g., profile picture, document)
2. Note the file path in storage
3. Try to access the file from `beta` tenant
4. Verify the file is NOT accessible
5. Check storage paths:
   ```bash
   docker exec dc_billeterie_local ls -la storage/app/public/
   ```
   Should see tenant-specific subdirectories

### Scenario 5: Tenant Creation Flow

**Test the complete tenant onboarding**:

1. Access landlord admin: http://billeterie.localhost/landlord/tenants
2. Create new tenant via web interface
3. Verify:
   - Database `t_{tenant_id}` created
   - Migrations ran successfully
   - Seeder created initial data
   - Domain is routable
4. Access tenant subdomain
5. Complete tenant-specific setup (if any)
6. Test core functionality

---

## Advanced: Adding More Tenants

### Bulk Tenant Creation

Create a seeder for generating test tenants:

```bash
docker exec dc_billeterie_local php artisan make:seeder TenantTestSeeder
```

Edit the seeder:
```php
use App\Models\Tenant;

public function run()
{
    $tenants = ['alpha', 'beta', 'gamma', 'delta'];

    foreach ($tenants as $tenantId) {
        $tenant = Tenant::create(['id' => $tenantId]);
        $tenant->domains()->create([
            'domain' => "{$tenantId}.localhost"
        ]);
    }
}
```

Run the seeder:
```bash
docker exec dc_billeterie_local php artisan db:seed --class=TenantTestSeeder
docker exec dc_billeterie_local php artisan tenants:migrate
```

---

## Stopping and Restarting

### Stop Everything

```bash
# Stop application
./deployment/deploy.sh local stop

# Stop Traefik
cd deployment/docker
docker compose -f docker-compose.traefik.local.yml down
```

### Restart

```bash
# Start Traefik first
cd deployment/docker
docker compose -f docker-compose.traefik.local.yml up -d

# Deploy application
cd ..
./deploy.sh local deploy
```

### Clean Restart (Remove Data)

```bash
# Stop and remove all containers and volumes
./deployment/deploy.sh local stop
docker compose -f deployment/docker/docker-compose.local.yml down -v

# Remove Traefik
docker compose -f deployment/docker/docker-compose.traefik.local.yml down

# Start fresh
docker compose -f deployment/docker/docker-compose.traefik.local.yml up -d
./deployment/deploy.sh local deploy
```

---

## Next Steps

- Review the [production deployment guide](../DEPLOYMENT.md) for production-specific configuration
- Explore tenant-specific features and customization
- Set up CI/CD for automated testing of multi-tenant scenarios
- Configure tenant-specific theming or branding
- Implement tenant-level feature flags

---

## Summary

You now have a fully functional multi-tenant local development environment with:
- ✅ Traefik routing for subdomains
- ✅ Separate databases per tenant
- ✅ Data and session isolation
- ✅ Easy tenant creation and management
- ✅ Production-like routing behavior

For questions or issues, refer to the troubleshooting section or check the Traefik dashboard for routing insights.
