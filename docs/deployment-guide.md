# Deployment Guide

This guide summarises how ERPify is deployed. For the detailed prod playbook, see [`production-deployment.md`](./production-deployment.md); for Mercure specifics see [`mercure-production-deployment.md`](./mercure-production-deployment.md) and [`mercure.md`](./mercure.md).

## Infrastructure requirements

- Host with Docker + Docker Compose (v2).
- TLS termination via FrankenPHP/Caddy (dev uses Caddy's local CA; prod uses real certs).
- PostgreSQL (Compose service in this repo).
- Mercure Hub (served at `/.well-known/mercure` on the FrankenPHP origin).
- Outbound SMTP (for async mailer).

## Environments

Switch overlay via `ENV=dev|ci|staging|prod` (default `dev`).

| Env | Compose files |
|---|---|
| `dev` | `compose.yaml` + `compose.dev.yaml` |
| `ci` | `compose.yaml` + overlay chosen by CI |
| `staging` | `compose.yaml` + `compose.prod.yaml` |
| `prod` | `compose.yaml` + `compose.prod.yaml` |

## Prod services

Defined in `compose.prod.yaml` on top of the base stack:

- `php` — FrankenPHP + Symfony API (terminates TLS, reverse-proxies `/` to `pwa:3000`).
- `pwa` — Next.js production (`next start -p 80` inside the container).
- `postgres` — PostgreSQL.
- `messenger_worker` — **separate** Symfony Messenger consumer (handlers must be idempotent; at-least-once delivery).
- Mailer pipeline (async via Messenger).
- Mercure Hub — behind `/.well-known/mercure` (JWT-signed).

## Required env (prod fails to start without these)

- `APP_SECRET`
- `CADDY_MERCURE_JWT_SECRET`
- `POSTGRES_PASSWORD`

Plus SMTP credentials and any `NEXT_PUBLIC_SYMFONY_API_BASE_URL` override needed for the public origin.

## Deploy process (operator view)

1. Build/push images (base images are **digest-pinned**: `dunglas/frankenphp:1-php8.5`, `debian:13-slim`, `node:24-alpine`; do not unpin).
2. On the host, pull images and run:
   ```bash
   ENV=prod make docker.up
   ```
3. Apply DB migrations:
   ```bash
   ENV=prod make db.migrate
   ```
4. Watch `messenger_worker` logs: `ENV=prod make docker.logs` (filter by service).
5. Run smoke tests per [`production-deployment.md`](./production-deployment.md).

## CI/CD

- GitHub Actions workflows under `.github/workflows/`:
  - `ci.yml` — lint + test pipeline (runs `make ci.lint` + `make ci.test`).
  - `codeql.yml` — static security analysis.
- SuperLinter (container-based): `make ci.superlint` (requires `GITHUB_TOKEN`).
- Dependabot tracks Docker digests at `/api` and `/pwa` and composer/npm dependency updates.

## Rollback

- Images are immutable (digest-pinned). Redeploy the previous image tag.
- Roll back DB changes only if the migration is reversible — otherwise restore from the most recent Postgres backup and replay.

## Operational notes

- `make docker.clean` drops volumes and is **destructive** — never on prod without explicit confirmation.
- Do not run `db.reset` outside dev/ci.
- DNS, CORS origins, and Mercure cookie/CORS config: see [`mercure-production-deployment.md`](./mercure-production-deployment.md) and [`production-deployment.md`](./production-deployment.md).
- Xdebug must be disabled in prod images.
