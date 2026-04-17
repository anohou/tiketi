# Port Configuration Note

Due to OrbStack using ports 80, 8080, and 8081, Traefik has been configured to use alternative ports:

- **HTTP**: Port `8888` (instead of 80)
- **Dashboard**: Port `9999` (instead of 8080)

## Accessing the Application

Since Traefik is on port 8888, you'll need to include the port in URLs:

- **Central domain**: http://tiketi.localhost:8888
- **Tenant subdomains**: http://alpha.localhost:8888, http://beta.localhost:8888
- **Traefik dashboard**: http://localhost:9999

## Why This Change?

OrbStack (your Docker Desktop alternative) binds to ports 80, 8080, and 8081 for its own proxy functionality. Rather than disable OrbStack's features, we use higher ports that don't conflict.

## Alternative: Using Standard Ports

If you prefer to use standard ports (80, 8080), you can:

1. **Stop OrbStack's  built-in proxy** (if you don't need it)
2. **Edit** `docker-compose.traefik.local.yml` and change:
   ```yaml
   ports:
     - "80:81"      # HTTP
     - "8080:8080"  # Dashboard
   ```
3. **Restart Traefik**:
   ```bash
   docker compose -f docker-compose.traefik.local.yml down
   docker compose -f docker-compose.traefik.local.yml up -d
   ```

For now, the setup uses ports 8888 and 9999 which work without any conflicts.
