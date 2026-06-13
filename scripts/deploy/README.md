# Deployment Scripts

This directory holds the scripts for the prod / staging profile:

| Script            | Run via                        | Purpose                                                                                                                              |
|-------------------|--------------------------------|--------------------------------------------------------------------------------------------------------------------------------------|
| `deploy-local.sh` | `make deploy.local`            | **First stand-up** on a host: preflight → `docker.up` → migrate → smoke → internal-CA export + trust guidance.                       |
| `trust-local.sh`  | `sudo make deploy.local.trust` | **Privileged client-trust** steps (`/etc/hosts`, system CA store, Chromium NSS). Splits the root-requiring work out of the stand-up. |
| `deploy.sh`       | `./scripts/deploy/deploy.sh`   | **Post-deploy operations on an already-running stack**: migrations, cache warmup, worker reload, health checks (+ a `--ci` mode).    |
| `backup-prod.sh`  | `make backup.prod`             | **Paired backup** of the two stateful volumes: `pg_dump -Fc` first, object-storage volume archive after (one shared timestamp).     |
| `restore-prod.sh` | `STAMP=<s> make restore.prod`  | **Paired restore** (inverse of the backup) — verifies both artifacts, then objects + DB. **Destructive**; a real-domain target needs `ALLOW_PROD_RESTORE=1` + a typed confirmation.  |

`deploy.sh` does **not** bring the stack up — run `make deploy.local` for the
initial stand-up, then use `deploy.sh` for subsequent redeploys. The full
runbook (secret setup, TLS trust, VPS promotion) is in
`docs/erpify-local-test-deployment.md`. The rest of this file documents
`deploy.sh`.

## Quick Commands

**Simple Deployment** (migrations → cache → workers)

```bash
./scripts/deploy/deploy.sh --simple
```

**Advanced** (with health checks)

```bash
./scripts/deploy/deploy.sh --advanced
```

**Options:**

- `--dry-run` — Test without making changes
- `--check-only` — Validate environment only
- `--skip-migrations` — Skip DB migrations
- `--ci` — CI/CD mode (structured output)

## Deployment Steps

1. **Database Migrations** — Updates schema with `--all-or-nothing`
2. **Cache Warmup** — Compiles Symfony cache (routes, services)
3. **Worker Reload** — Signals workers to restart gracefully

## Production Workflow

Assumes the stack is already up (`make deploy.local` on first stand-up).

```bash
# 1. Validate environment
./scripts/deploy/deploy.sh --check-only

# 2. Test deployment
./scripts/deploy/deploy.sh --dry-run

# 3. Deploy
./scripts/deploy/deploy.sh --advanced

# 4. Verify
docker compose logs -f php messenger_worker
```

## Manual Commands

If scripts fail, run directly from repository root:

```bash
make db.migrate                # Run migrations
make sf.cache.warmup           # Warm cache
make sf.messenger.stop-workers # Reload workers
```

## Environment Variables

```bash
DEPLOY_ENV=prod              # ENV passed to every `make` call (default: prod). Use staging/dev to switch overlay.
SERVER_NAME=erpify.local     # Host used to derive the default health URL (default: erpify.local)
HEALTH_URL=https://app.example.com/api/v1/health  # Override the health endpoint outright
```

> `DEPLOY_ENV=prod`/`staging` makes the script run `make prod.env.check` first and
> load secrets via `--env-file` (see `make/config.mk`); a missing/incomplete
> `.env.prod.local` aborts before the stack is touched.

## Troubleshooting

| Issue                     | Solution                                              |
|---------------------------|-------------------------------------------------------|
| **"Makefile not found"**  | Run from repo root: `cd /path/to/ERPify`              |
| **Migrations fail**       | Check status: `make db.status` then `make db.migrate` |
| **Health check fails**    | Wait for containers: `sleep 5 && docker compose ps`   |
| **Workers not reloading** | Restart: `docker compose restart messenger_worker`    |

## CI/CD Integration

Use `--ci` flag for GitHub Actions, GitLab CI, Jenkins:

```bash
./scripts/deploy/deploy.sh --ci
```

**GitHub Actions:**

```yaml
- name: Deploy
  env:
    DEPLOY_ENV: prod
  run: |
    cd ${{ github.workspace }}
    ./scripts/deploy/deploy.sh --ci
```

**GitLab CI:**

```yaml
deploy:
  stage: deploy
  script:
    - DEPLOY_ENV=prod ./scripts/deploy/deploy.sh --ci
  only:
    - main
```

## Notes

- Always test with `--dry-run` before production
- Run from repository root or use full path
- Check logs: `docker compose logs php`
- Monitor workers post-deploy for issues
