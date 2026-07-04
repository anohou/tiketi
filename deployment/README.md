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
│   ├── systemd/
│   │   ├── pull-deploy.service
│   │   └── pull-deploy.timer
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
    ├── php-shell.sh
    ├── provision-db.sh
    ├── repair-tenant-bootstrap.sh
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
- `config/template.env` is rendered from the shared Laravel base template plus app-specific `.app_env` keys from `apps/<app>/laravel-deployment.enc.yml`, so fresh scaffold/sync reproduces app-specific runtime keys without hand patches.
- Laravel source deploys build locally from synced app source, but should default to the shared PHP runtime path with Docker target `production-shared`.
- The emergency override `DEPLOY_FULL_LOCAL_RUNTIME=true` forces the legacy full local runtime path with Docker target `production-local` for that run only.
- `scripts/provision-db.sh` provisions the app database and roles against the shared Postgres service.
- `scripts/repair-tenant-bootstrap.sh` repairs an incomplete multitenant tenant bootstrap after the central tenant row exists but tenant DB creation, migrations, or tenant admin creation did not complete.
- `scripts/deploy.sh` is the standard recreate-style deployment entrypoint for apps scaffolded from this kit.
- `scripts/zero-downtime-deploy.sh` is the opt-in Laravel blue/green entrypoint. It is guarded by `zero_downtime.enabled=true` in rendered `config/config.yml`.
- Laravel apps can define a central migration stage with `app.migration.command` and an optional tenant migration stage with `app.migration.tenant_command`.
- Zero-downtime deploys require `app.migration.tenant_preflight_command` whenever a tenant migration stage is configured, and both central and tenant stages complete before traffic is switched.
- `config/systemd/pull-deploy.service` and `config/systemd/pull-deploy.timer` are generic pull-deploy unit sources that the toolkit installer maps onto app-specific systemd unit names on the server.

## Shared Runtime Notes

- Runtime mode is configured in `apps/<app>/laravel-deployment.enc.yml`, not in `deployment-identity.yml`.
- `deployment.by_environment.<env>: source` decides whether the app is built from source on the server.
- Runtime mode only matters for `source` deploys. If an environment uses `image`, the server pulls a prebuilt app image and does not use the shared/local runtime build logic.
- `FORCE_DEPLOY_STRATEGY=source|image` can override the declared `deployment.by_environment.<env>` policy for one command invocation, but it does not persist back into app config.
- The shared runtime image is released separately from app deploys with:
  `make release-laravel-runtime-image PHP_VERSION=8.4 RUNTIME_VERSION=v1`
- Runtime releases default to `RUNTIME_IMAGE_PLATFORM=linux/amd64`, which matches the current deployment hosts.
- If the shared runtime tag was previously published from an `arm64` workstation, re-run the release command before app deploys so the host does not hit `InvalidBaseImagePlatform` or `exec format error`.
- A healthy shared-runtime Laravel deploy log should show:
  `Runtime mode: shared  |  Docker target: production-shared`
- The app image remains app-specific, for example `ghcr.io/anohou/ekkou-api:latest`; the shared runtime image is only the build base.

## Runtime Mode Config

Add runtime-mode defaults to `apps/<app>/laravel-deployment.enc.yml` under `shared.app.build`:

```yaml
shared:
  app:
    build:
      runtime_mode: shared
      runtime_image: ghcr.io/anohou/laravel-runtime:8.4-alpine-v1
      allow_full_local_runtime: true
```

Environment-specific overrides can also be set under `environments.<env>.app.build` when one environment needs different behavior:

```yaml
environments:
  prod:
    app:
      build:
        runtime_mode: shared
```

Field behavior:

- `runtime_mode`
  - `shared`: use Docker target `production-shared` and build the app image on top of the prebuilt Laravel runtime image referenced by `runtime_image`, for example `ghcr.io/anohou/laravel-runtime:8.4-alpine-v1`
  - `local`: use Docker target `production-local` and do not use the prebuilt shared runtime image; instead rebuild the PHP runtime inside this app's Docker build
- `runtime_image`
  - the prebuilt shared Laravel runtime image reference used when `runtime_mode: shared`
- `allow_full_local_runtime`
  - controls whether the emergency override `DEPLOY_FULL_LOCAL_RUNTIME=true` is allowed for that app

Defaults:

- `runtime_mode: shared`
- `runtime_image: ghcr.io/anohou/laravel-runtime:8.4-alpine-v1`
- `allow_full_local_runtime: true`
- `cache_mode: registry`
- `allow_cache_bypass: true`

Operational usage:

- Normal source deploy:
  `make deploy-app-source APP=<app> DEPLOYMENT=<deployment> ENV=<env>`
- Optional one-run cache warmup for source-based Laravel apps:
  `make warm-laravel-build-cache APP=<app> DEPLOYMENT=<deployment> ENV=<env>`
- One-run strategy override to deploy a prebuilt image even when the declared strategy is `source`:
  `FORCE_DEPLOY_STRATEGY=image make deploy-app-source APP=<app> DEPLOYMENT=<deployment> ENV=<env>`
- One-run strategy override to deploy from source even when the declared strategy is `image`:
  `FORCE_DEPLOY_STRATEGY=source make deploy-app-image APP=<app> DEPLOYMENT=<deployment> ENV=<env>`
- Emergency fallback to the legacy full-local runtime path for one run:
  `DEPLOY_FULL_LOCAL_RUNTIME=true make deploy-app-source APP=<app> DEPLOYMENT=<deployment> ENV=<env>`
- Force a fresh source rebuild even when the build fingerprint matches:
  `DEPLOY_FORCE_REBUILD=true make deploy-app-source APP=<app> DEPLOYMENT=<deployment> ENV=<env>`

Expected logs:

- shared mode:
  `Runtime mode: shared  |  Docker target: production-shared`
- local mode:
  `Runtime mode: local  |  Docker target: production-local`

## Zero-Downtime Notes

- Blue/green deploys use color-specific Compose projects, env files, runtime public directories, and readiness markers.
- Public traffic is switched by writing Traefik file-provider config into the shared rendered dynamic directory, normally `<host-root>/releases/config/traefik/dynamic/dynamic-<app>.yml`, which Traefik mounts under `/etc/traefik/dynamic`.
- Queue workers and scheduler are owned by one color at a time. The old color is drained before migrations; the new color starts workers only after the Traefik switch succeeds.
- Nginx injects `X-Deploy-Color` for deploy verification. Strip it at Cloudflare or another public edge layer if it should not be visible to end users.
- `sync-laravel-deployment` only refreshes this local deployment kit. It does not run Ansible and does not deploy to the target host by itself.
- `make deploy-app-source ...` and `make deploy-app-source-zero-downtime ...` refresh this local Laravel deployment kit automatically before they sync and deploy source.

## Notes

- This template is intended for new Laravel app scaffolds from `server-setup-toolkit`.
- App-specific identity, domains, DB name, runtime env keys, secret file paths, and Postgres credentials come from `apps/<app>/laravel-deployment.enc.yml`.
- For multitenant Laravel apps, central or landlord migrations remain in Laravel's normal migration path `database/migrations`, while tenant schema migrations belong in `database/migrations/tenant`.
- Existing apps generated from older Laravel templates can remain on their legacy deployment kits until explicitly migrated.
