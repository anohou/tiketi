# Laravel Deployment Kit

Reusable toolkit-managed deployment kit for Laravel applications running on PostgreSQL.

## Layout

```text
deployment/
├── README.md
├── config/
│   ├── Dockerfile
│   ├── config.yml
│   ├── docker-compose.prod.yml
│   ├── nginx.conf
│   ├── php.ini
│   ├── rbac.yml
│   ├── template.env
│   └── www.conf
├── lib/
│   ├── config.sh
│   ├── postgres-app-db.sh
│   └── rbac.sh
├── persistent-public/
│   └── root/
└── scripts/
    ├── artisan.sh
    ├── build.sh
    ├── cleanup.sh
    ├── clear-cache.sh
    ├── compare-dbs.sh
    ├── create-admin.sh
    ├── deploy-local.sh
    ├── deploy.config.sh
    ├── deploy.sh
    ├── dump-db.sh
    ├── generate-env.sh
    ├── healthcheck.sh
    ├── provision-db.sh
    ├── rbac.config.sh
    ├── rebuild-cache.sh
    ├── check-migration-safety.sh
    ├── check-traefik-active-color.sh
    ├── restart-workers.sh
    ├── resume-zero-downtime-deploy.sh
    ├── rollback-zero-downtime.sh
    ├── restore-db.sh
    ├── rollback.sh
    ├── seed-db.sh
    ├── smoke-check.sh
    ├── sync-from-git.sh
    ├── traefik-switch.sh
    ├── zero-downtime-deploy.sh
    └── versions.sh
```

## Toolkit Contract

- `config/config.yml` is rendered by `server-setup-toolkit` per app and environment.
- `config/template.env` is the allowlist for runtime env keys; only keys listed there are emitted into `.env`.
- `scripts/provision-db.sh` provisions the app database and roles against the shared Postgres service.
- `scripts/deploy.sh` is the standard recreate-style deployment entrypoint for apps scaffolded from this kit.
- `scripts/zero-downtime-deploy.sh` is the opt-in Laravel blue/green entrypoint. It is guarded by `zero_downtime.enabled=true` in rendered `config/config.yml`.

## Zero-Downtime Notes

- Blue/green deploys use color-specific Compose projects, env files, runtime public directories, and readiness markers.
- Public traffic is switched by writing Traefik file-provider config into the host-side rendered dynamic directory, normally `<app-root>/current/config/traefik/dynamic/dynamic-<app>.yml`, which Traefik mounts under `/etc/traefik/dynamic`.
- Queue workers and scheduler are owned by one color at a time. The old color is drained before migrations; the new color starts workers only after the Traefik switch succeeds.
- Nginx injects `X-Deploy-Color` for deploy verification. Strip it at Cloudflare or another public edge layer if it should not be visible to end users.
- `sync-laravel-deployment` only refreshes this local deployment kit. It does not run Ansible and does not deploy to the target host by itself.

## Notes

- This template is intended for new Laravel app scaffolds from `server-setup-toolkit`.
- App-specific identity, domains, DB name, secret file paths, and Postgres credentials come from `apps/<app>/laravel-deployment.enc.yml`.
- Existing apps generated from older Laravel templates can remain on their legacy deployment kits until explicitly migrated.
