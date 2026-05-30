# Deployment Guide

This guide summarises how ERPify is deployed. For the detailed PWA prod playbook, see [`pwa/docs/production-deployment.md`](../pwa/docs/production-deployment.md); for FrankenPHP-specific options see [`api/docs/options.md`](../api/docs/options.md) and [`api/docs/tls.md`](../api/docs/tls.md).

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

Plus SMTP credentials and the `SERVER_NAME` / `NEXT_PUBLIC_SYMFONY_API_BASE_URL`
origin for the deployment host.

Secrets are delivered through a **gitignored root `.env.prod.local`** (copy from
[`../.env.prod.example`](../.env.prod.example)), loaded via `--env-file` for
`ENV=prod|staging` (wired in `make/config.mk`). The prod overlay declares each
required secret as `${VAR:?msg}`, so a missing value aborts `docker compose` by
name — never a weak fallback. Validate before deploying:

```bash
make prod.env.check
```

## Prod hardening (compose.prod.yaml)

- Every service runs `no-new-privileges`, drops all Linux caps and re-adds only
  the minimum, and carries parametrizable CPU/memory ceilings.
- Postgres is on an `internal` `backend` network with **no published host port**.
- `pwa` runs with a read-only root filesystem.
- TLS: a non-public host uses `CADDY_SERVER_EXTRA_DIRECTIVES=tls internal`
  (Caddy's own CA); a public domain clears it for automatic ACME — same overlay.

See [`../PRODUCTION_SECURITY_CHECKLIST.md`](../PRODUCTION_SECURITY_CHECKLIST.md).

## Reproducible local prod deploy (`erpify.local`)

To stand the prod profile up on a LAN box or laptop at `https://erpify.local`:

```bash
cp .env.prod.example .env.prod.local   # fill in the CHANGE_ME secrets
make deploy.local                      # preflight → up → migrate → smoke
```

Step-by-step (incl. internal-CA trust and `/etc/hosts`) and VPS promotion:
[`erpify-local-test-deployment.md`](erpify-local-test-deployment.md).

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
5. Run smoke tests per [`pwa/docs/production-deployment.md`](../pwa/docs/production-deployment.md).

## CI/CD

- GitHub Actions workflows under `.github/workflows/`:
  - `ci.yml` — lint + test pipeline (runs `make ci.quality` + `make ci.test`).
- Static security analysis (CodeQL): run locally with `make codeql.run` — the GitHub workflow was removed because Code Scanning on private repos requires GitHub Advanced Security.
- SuperLinter (container-based): `make super-lint.full` (requires `GITHUB_TOKEN`).
- Dependabot tracks Docker digests at `/api` and `/pwa` and composer/npm dependency updates.

## Rollback

- Images are immutable (digest-pinned). Redeploy the previous image tag.
- Roll back DB changes only if the migration is reversible — otherwise restore from the most recent Postgres backup and replay.

## Operational notes

- `make docker.down.clean-volumes` drops volumes and is **destructive** — never on prod without explicit confirmation.
- Do not run `db.reset` outside dev/ci.
- DNS, CORS origins, and Mercure cookie/CORS config: see [`pwa/docs/production-deployment.md`](../pwa/docs/production-deployment.md).
- Xdebug must be disabled in prod images.
