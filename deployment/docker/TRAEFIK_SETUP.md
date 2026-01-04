# Using Existing Traefik

This directory contains `docker-compose.traefik.local.yml`, but **you don't need to use it**.

## Your Setup

You already have **Traefik running** in Docker Swarm mode:
- Network: `traefik_swarm_network`
- Version: Traefik v3.2.2
- Port: 80 (standard HTTP)

The `docker-compose.local.yml` has been configured to use this existing Traefik instance.

## Quick Start

```bash
# Deploy billeterie - it will automatically connect to existing Traefik
cd /Users/wyao/Workspace/1-anohou2/anohou-dev/billeterie
./deployment/deploy.sh local deploy
```

## Access URLs

Once deployed, access via:
- **Central domain**: http://billeterie.localhost
- **Tenant subdomains**: http://alpha.localhost, http://beta.localhost, etc.

No need to include port numbers - the existing Traefik handles routing on port 80!

## Why We Don't Need docker-compose.traefik.local.yml

Your local environment already has Traefik configured via Docker Swarm. Other projects like `ekkou` are already using it. By connecting `billeterie` to the same `traefik_swarm_network`, all your projects share one Traefik instance.

## If You Want to See Traefik Dashboard

The existing Traefik may or may not have the dashboard enabled. Check:
```bash
curl http://localhost:8080/api/version
```

If it doesn't work, the dashboard isn't exposed. You can see Traefik routing status through Docker commands:
```bash
docker service ls
docker inspect $(docker ps -q --filter "name=traefik")
```
