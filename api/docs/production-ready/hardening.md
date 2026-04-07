# Production Hardening Checklist

Work through every item on this list before going live. Each item links to the
relevant section of the production documentation for context.

---

## Application

- [ ] `APP_ENV=prod` is set — enables Symfony's production optimisations
  (OPcache, classmap autoloader, no debug toolbar, no profiler).
- [ ] `APP_SECRET` is a 32-character random hex string generated with
  `openssl rand -hex 32`. See [secrets.md](secrets.md).
- [ ] `APP_DEBUG` is not explicitly set (it defaults to `0` in `prod`).
  Never set it to `1` on production.
- [ ] The Symfony cache has been warmed up inside the production image
  (`composer run-script post-install-cmd` in the `frankenphp_prod` Dockerfile
  stage does this automatically).

---

## Database

- [ ] `POSTGRES_PASSWORD` is a strong random value — not the placeholder
  `!ChangeMe!`. See [database.md](database.md#credentials).
- [ ] `POSTGRES_USER` and `POSTGRES_DB` are renamed from the dev defaults
  (`app`) to production-specific names (e.g. `erpify_prod`).
- [ ] The PostgreSQL service does **not** publish any port to the host.
  There is no `ports:` block under `database` in `compose.yaml` or
  `compose.prod.yaml`.
- [ ] Automated daily backups are configured, tested, and shipped off-site.
  A restore drill has been completed. See [database.md](database.md#backups).
- [ ] `doctrine:fixtures:load` is confirmed unavailable in prod — running
  `bin/console doctrine:fixtures:load` must output "command not found", not
- [ ] `hautelook:fixtures:load` is confirmed unavailable in prod — running
  `bin/console hautelook:fixtures:load` must output "command not found", not
  execute.
- [ ] Schema is in sync with entities:
  `bin/console doctrine:migrations:status` shows no pending migrations after
  deploy.

---

## Secrets & Configuration

- [ ] No production secret appears in any committed file (`.env`,
  `compose.yaml`, `compose.prod.yaml`, source code, or CI logs).
- [ ] `.env.prod.local` exists only on the server, is in `.gitignore`, and has
  file permissions `600` (readable only by the owner). See [secrets.md](secrets.md).
- [ ] `CADDY_MERCURE_JWT_SECRET` is a strong random value, not the placeholder.
- [ ] All secrets have been rotated if they were ever accidentally committed or
  logged.

---

## TLS / HTTPS

- [ ] `SERVER_NAME` is set to a real public domain name (not `localhost`).
  See [tls.md](tls.md).
- [ ] An `A` DNS record for the domain already points to the server IP
  before the first start.
- [ ] Ports 80 and 443 (TCP) and 443 (UDP/HTTP3) are open in the server
  firewall.
- [ ] A valid TLS certificate was obtained successfully. Check with:
  `docker compose logs php | grep -i certificate`.
- [ ] HTTP redirects to HTTPS automatically (Caddy default behaviour — no
  action needed unless `SERVER_NAME=:80`).

---

## Docker Image

- [ ] The production image was built with `--pull --no-cache` to include the
  latest base image security patches. See [build.md](build.md).
- [ ] `compose.prod.yaml` targets `frankenphp_prod`, not `frankenphp_dev`.
- [ ] Xdebug is **not** present in the production image — it is only installed
  in the `frankenphp_dev` Dockerfile stage.
- [ ] No bind-mount of the source code exists in `compose.prod.yaml` (unlike
  `compose.override.yaml` which mounts `./:/app` for hot-reload in dev).

---

## Network & Host

- [ ] Only the following ports are published by Docker:
  - `80/tcp` (HTTP, redirected to HTTPS)
  - `443/tcp` (HTTPS)
  - `443/udp` (HTTP/3)
- [ ] The server firewall (e.g. `ufw`, `iptables`, cloud security group)
  blocks all other inbound ports except SSH (22/tcp or a custom port).
- [ ] SSH root login is disabled in `/etc/ssh/sshd_config`
  (`PermitRootLogin no`).
- [ ] SSH uses key-based authentication only (`PasswordAuthentication no`).
- [ ] The server OS and Docker Engine are up to date with security patches.

---

## Post-Deploy Smoke Tests

After bringing the production stack up, verify end-to-end:

```console
# Health endpoint
curl -sf https://api.your-domain.com/api/v1/backoffice/health | jq .
# Expected: {"status":"ok","service":"Back office", ...}

# Bank list (should return HTTP 200 and JSON array)
curl -sf https://api.your-domain.com/api/v1/backoffice/banks | jq .

# TLS grade (external tool)
# https://www.ssllabs.com/ssltest/analyze.html?d=api.your-domain.com
```
