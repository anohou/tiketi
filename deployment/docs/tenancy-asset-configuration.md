# Tenancy Asset Configuration

## Overview
This document explains the `asset_helper_tenancy` configuration in `config/tenancy.php` and why it must be set to `false` for this application.

## Configuration

**File**: `config/tenancy.php`
**Line**: ~142

```php
'filesystem' => [
    // ... other config ...

    /**
     * By default, asset() calls are made multi-tenant too. You can use global_asset() and mix()
     * for global, non-tenant-specific assets. However, you might have some issues when using
     * packages that use asset() calls inside the tenant app. To avoid such issues, you can
     * disable asset() helper tenancy and explicitly use tenant_asset() calls in places
     * where you want to use tenant-specific assets (product images, avatars, etc).
     */
    'asset_helper_tenancy' => false,  // ⚠️ MUST be false for this app
],
```

---

## What Does This Setting Do?

When `asset_helper_tenancy` is **enabled** (`true`), the `stancl/tenancy` package modifies Laravel's `asset()` helper to be tenant-aware by prefixing asset URLs with the tenant identifier.

### Example Behavior

Given a tenant with `id = "test"`:

#### With `asset_helper_tenancy => true` (Default)
```php
asset('build/assets/app.js')
// Generates: http://tiketi.localhost/tenanttest/build/assets/app.js
//                                        ^^^^^^^^^^^
//                                        tenant prefix added
```

#### With `asset_helper_tenancy => false` (Our Configuration)
```php
asset('build/assets/app.js')
// Generates: http://tiketi.localhost/build/assets/app.js
//                                        No prefix - global path
```

---

## Why This Application Requires `false`

### Reason 1: Global Vite Assets
This application uses **Vite** for frontend compilation. Vite builds are:
- Compiled once during deployment
- Stored globally at `/public/build/`
- **Shared across all tenants** (not tenant-specific)

Setting this to `true` would cause the browser to look for assets at:
```
/tenanttest/build/assets/app.js  ❌ Does not exist
```

Instead of:
```
/build/assets/app.js  ✅ Correct location
```

### Reason 2: 404 Errors Lead to CORS Issues
When `asset_helper_tenancy => true`:
1. Browser requests `/tenanttest/build/assets/app.js`
2. Nginx can't find it → passes to Laravel
3. Laravel returns a 404 error page
4. 404 response **lacks CORS headers** → Browser blocks the script with `ERR_BLOCKED_BY_CLIENT`

---

## When Would You Use `true`?

You would enable `asset_helper_tenancy => true` **only if** you store tenant-specific assets in separate directories, such as:

```
/storage/app/tenant123/logo.png
/storage/app/tenant123/banner.jpg
/storage/app/tenant456/logo.png
/storage/app/tenant456/banner.jpg
```

### Use Cases for `true`:
- Custom tenant logos
- Tenant-uploaded product images
- Tenant-specific branding files
- Custom CSS per tenant

---

## Alternative: Using `tenant_asset()` for Tenant-Specific Files

With `asset_helper_tenancy => false`, you can still serve tenant-specific assets by using the `tenant_asset()` helper:

```php
// Global assets (Vite builds, shared CSS/JS)
asset('build/assets/app.js')
// → http://tiketi.localhost/build/assets/app.js

// Tenant-specific assets
tenant_asset('uploads/logo.png')
// → http://tiketi.localhost/tenanttest/uploads/logo.png
```

This gives you **fine-grained control** over which assets are global vs. tenant-specific.

---

## Impact on Multi-Tenant Routing

This configuration is **critical** for the multi-tenant subdomain setup:

- **Central Domain**: `http://tiketi.localhost` (landlord)
- **Tenant Domains**: `http://test.localhost`, `http://alpha.localhost`, etc.

When a user accesses a tenant domain (e.g., `http://test.localhost/login`):
1. HTML page is served from tenant context
2. JavaScript/CSS assets are loaded from the **central domain** (`tiketi.localhost`)
3. Setting `asset_helper_tenancy => false` ensures correct URLs without tenant prefixes

---

## Related Configuration

This setting works in conjunction with:

### 1. Nginx CORS Headers
**File**: `deployment/docker/nginx/default.conf`

```nginx
location /build/ {
    add_header Access-Control-Allow-Origin *;  # Allows cross-origin loading
    # ...
}
```

### 2. Session Domain Configuration
**File**: `deployment/deployment.config.yml`

```yaml
environments:
  local:
    laravel:
      session:
        domain: null  # Host-only cookies (not .localhost)
```

---

## Troubleshooting

### Assets Not Loading (404 Errors)
**Symptom**: Browser shows 404 for `/tenanttest/build/assets/...`
**Cause**: `asset_helper_tenancy` is set to `true`
**Fix**: Set to `false` in `config/tenancy.php`

### CORS Errors on Asset Loading
**Symptom**: `ERR_BLOCKED_BY_CLIENT` or "No 'Access-Control-Allow-Origin' header"
**Cause**: Missing CORS headers on static assets
**Fix**: Ensure Nginx configuration includes `Access-Control-Allow-Origin` header (see `deployment/todo/security-review.md`)

---

## References

- [stancl/tenancy Documentation - Asset Helper Tenancy](https://tenancyforlaravel.com/docs/v3/tenancy-bootstrappers/#filesystem-tenancy-boostrapper)
- `config/tenancy.php` lines 98-143
- `deployment/todo/security-review.md` (CORS configuration)
- `deployment/MULTITENANT_LOCAL_SETUP.md` (Full setup guide)

---

**Last Updated**: 2026-01-04
**Reviewed By**: Multi-tenant debugging session
