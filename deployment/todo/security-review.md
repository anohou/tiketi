# Security Review Items

## 🔴 HIGH PRIORITY - CORS Configuration for Production

**File**: `deployment/docker/nginx/default.conf`
**Lines**: 49, 57

**Current Configuration (Local Development)**:
```nginx
# Vite assets
location /build/ {
    add_header Access-Control-Allow-Origin *;
    ...
}

# Other static assets
location ~* \.(jpg|jpeg|gif|png|css|js|ico|xml|svg|woff|woff2|ttf|eot)$ {
    add_header Access-Control-Allow-Origin *;
    ...
}
```

**Issue**:
The wildcard `*` allows **any domain** to load these assets. While this is acceptable for local development with multiple `*.localhost` subdomains, it should be reviewed and potentially restricted for production.

**Recommended Production Configuration**:

### Option 1: Specific Tenant Pattern (Most Secure)
```nginx
# Only allow tenant subdomains from your production domain
add_header Access-Control-Allow-Origin "https://*.billeterie.anohou.dev";
```

### Option 2: Multiple Specific Origins
```nginx
# If you have a known list of tenant domains
map $http_origin $cors_origin {
    default "";
    "~^https://[a-z0-9-]+\.billeterie\.anohou\.dev$" $http_origin;
    "https://billeterie.anohou.dev" $http_origin;
}

location /build/ {
    add_header Access-Control-Allow-Origin $cors_origin;
    ...
}
```

### Option 3: Keep Wildcard (If Publicly Available Assets)
If your compiled assets contain no sensitive information and are meant to be publicly cacheable (e.g., via CDN), keeping `*` is acceptable.

**Action Required**:
- [ ] Review production CORS requirements before deploying
- [ ] Decide which option above fits your security model
- [ ] Update production Nginx configuration accordingly
- [ ] Test multi-tenant asset loading after changes

**Context**:
This was added to fix `ERR_BLOCKED_BY_CLIENT` errors when tenant subdomains (`test.localhost`) load assets from the central domain (`billeterie.localhost`). CORS headers are required for cross-origin script execution.

**Severity**: 🔴 **MEDIUM-HIGH**
- Risk: Potential for asset hotlinking or abuse if wildcard remains in production
- Impact: Low (assets are public anyway, but best practice is to restrict)
- Urgency: Must review before production deployment

---

**Date Added**: 2026-01-04
**Added By**: Multi-tenant local setup debugging session
