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
    ├── restore-db.sh
    ├── rollback.sh
    ├── seed-db.sh
    ├── smoke-check.sh
    ├── sync-from-git.sh
    └── versions.sh
```

## Toolkit Contract

- `config/config.yml` is rendered by `server-setup-toolkit` per app and environment.
- `config/template.env` is the allowlist for runtime env keys; only keys listed there are emitted into `.env`.
- `scripts/provision-db.sh` provisions the app database and roles against the shared Postgres service.
- `scripts/deploy.sh` is the standard recreate-style deployment entrypoint for apps scaffolded from this kit.

## Notes

- This template is intended for new Laravel app scaffolds from `server-setup-toolkit`.
- App-specific identity, domains, DB name, secret file paths, and Postgres credentials come from `apps/<app>/laravel-deployment.enc.yml`.
- Existing apps generated from older Laravel templates can remain on their legacy deployment kits until explicitly migrated.
