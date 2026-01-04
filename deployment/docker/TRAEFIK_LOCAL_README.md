# Traefik Local Setup for Multi-Tenant Testing

This Docker Compose file provides a Traefik reverse proxy for local multi-tenant testing.

## Quick Start

```bash
# Start Traefik
docker compose -f docker-compose.traefik.local.yml up -d

# Verify it's running
docker ps | grep traefik

# Access dashboard
open http://localhost:8080
```

## What It Does

- **Routes HTTP traffic** on port 80 to appropriate containers based on hostname
- **Enables multi-tenant subdomains**: `alpha.localhost`, `beta.localhost`, etc.
- **Provides dashboard** at http://localhost:8080 for monitoring and debugging

## Configuration

- **HTTP Entrypoint**: Port 80
- **Dashboard**: Port 8080 (insecure mode for local dev)
- **Network**: `traefik_local` (shared with app containers)
- **Provider**: Docker (watches containers for routing labels)

## Usage with Billeterie

1. Start Traefik first:
   ```bash
   docker compose -f docker-compose.traefik.local.yml up -d
   ```

2. Deploy the application:
   ```bash
   cd ..
   ./deploy.sh local deploy
   ```

3. Access:
   - Central: http://billeterie.localhost
   - Tenants: http://{tenant-id}.localhost
   - Dashboard: http://localhost:8080

## Stopping

```bash
docker compose -f docker-compose.traefik.local.yml down
```

## Troubleshooting

### Port 80 Already in Use

```bash
# Find what's using port 80
lsof -i :80

# Stop the conflicting service
sudo kill -9 <PID>
```

### Dashboard Not Accessible

Verify Traefik is running:
```bash
docker logs traefik_local
```

### Routing Not Working

1. Check Traefik dashboard: http://localhost:8080
2. Verify app container has Traefik labels:
   ```bash
   docker inspect dc_billeterie_local | grep -A 20 Labels
   ```
3. Ensure both containers are on `traefik_local` network:
   ```bash
   docker network inspect traefik_local
   ```

## See Also

- [Multi-Tenant Local Setup Guide](../MULTITENANT_LOCAL_SETUP.md) - Complete guide for multi-tenant testing
- [Deployment Guide](../../DEPLOYMENT.md) - Production deployment documentation
