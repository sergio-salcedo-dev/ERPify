# Deployment Scripts

Automated deployment for ERPify with validation and health checks.

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
make cache.warmup             # Warm cache
make messenger.stop-workers   # Reload workers
make health                   # Health check
```

## Environment Variables

```bash
HEALTH_URL=https://app.example.com/api/v1/health  # Override health endpoint
DEPLOY_ENV=prod              # For CI/CD
HEALTH_CHECK=true            # Enable health validation
```

## Troubleshooting

| Issue | Solution |
|-------|----------|
| **"Makefile not found"** | Run from repo root: `cd /path/to/ERPify` |
| **Migrations fail** | Check status: `make db.status` then `make db.migrate` |
| **Health check fails** | Wait for containers: `sleep 5 && docker compose ps` |
| **Workers not reloading** | Restart: `docker compose restart messenger_worker` |

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
