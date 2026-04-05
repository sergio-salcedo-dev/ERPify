# Secrets & Environment Variables

## Required Secrets — What Must Change

> [!CAUTION]
>
> The defaults baked into `compose.yaml` and `.env` are **intentionally insecure**
> placeholders meant for local development only.
> **Every value in this section is a security requirement, not a suggestion.**

| Variable | Insecure default | What to set |
|---|---|---|
| `APP_SECRET` | *(empty)* | 32-character cryptographically random hex string |
| `POSTGRES_PASSWORD` | `erpify_password` | Strong random password — avoid `@`, `#`, `/`, `?` to prevent URL-encoding issues |
| `CADDY_MERCURE_JWT_SECRET` | `!ChangeThisMercureHubJWTSecretKey!` | Random string ≥ 32 characters |

Generate secure values with `openssl`:

```console
# APP_SECRET
openssl rand -hex 32

# POSTGRES_PASSWORD (alphanumeric only, safe in URLs)
openssl rand -base64 24 | tr -d '+/='

# CADDY_MERCURE_JWT_SECRET
openssl rand -hex 32
```

**Never commit these values to git** — not in `.env`, `compose.yaml`,
`compose.prod.yaml`, or anywhere else that is version-controlled.

---

## Recommended: `.env.prod.local`

Store all production secrets in a `.env.prod.local` file **on the server
only**. This file must be in `.gitignore` and should never be copied off the
server except to an encrypted secrets vault.

```dotenv
# api/.env.prod.local  — server only, never committed
APP_ENV=prod
APP_SECRET=<32-char-hex>
SERVER_NAME=api.your-domain.com

POSTGRES_USER=erpify_prod
POSTGRES_PASSWORD=<strong-random-password>
POSTGRES_DB=erpify_prod
POSTGRES_VERSION=18

CADDY_MERCURE_JWT_SECRET=<32-char-hex>
```

Wire it into `compose.prod.yaml` via the `env_file` attribute:

```yaml
# compose.prod.yaml
services:
  php:
    image: ${IMAGES_PREFIX:-}app-php-prod
    build:
      context: .
      target: frankenphp_prod
    env_file:
      - .env.prod.local
    environment:
      APP_SECRET: ${APP_SECRET}
      MERCURE_PUBLISHER_JWT_KEY: ${CADDY_MERCURE_JWT_SECRET}
      MERCURE_SUBSCRIBER_JWT_KEY: ${CADDY_MERCURE_JWT_SECRET}
```

With this in place, Docker Compose reads the file and passes all variables to
the container without exposing them on the command line.

---

## Full Environment Variable Reference

All variables are consumed by `compose.yaml` through shell-environment
interpolation.

| Variable | Used by | Required | Dev default | Notes |
|---|---|---|---|---|
| `APP_ENV` | Symfony | Yes | `dev` | Must be `prod` in production |
| `APP_SECRET` | Symfony | Yes | *(empty)* | 32-char random hex |
| `APP_DEBUG` | Symfony | No | `0` in prod | Do not set to `1` in production |
| `SERVER_NAME` | Caddy | Yes | `localhost` | Your public domain, e.g. `api.example.com` |
| `POSTGRES_USER` | DB + php | Yes | `erpify_user` | Change to a dedicated prod user |
| `POSTGRES_PASSWORD` | DB + php | Yes | `erpify_password` | Strong random password — see [database.md](database.md) |
| `POSTGRES_DB` | DB + php | Yes | `erpify_db` | Change to a dedicated prod database name |
| `POSTGRES_VERSION` | DB + php | No | `18` | Must match the PostgreSQL image tag |
| `POSTGRES_PORT` | host only | No | `15432` | Omit/remove in production — never expose DB port |
| `POSTGRES_CHARSET` | php | No | `utf8` | |
| `CADDY_MERCURE_JWT_SECRET` | Caddy/Mercure | Yes | `!ChangeThisMercureHubJWTSecretKey!` | Random string ≥ 32 chars |
| `HTTP_PORT` | Caddy | No | `80` | |
| `HTTPS_PORT` | Caddy | No | `443` | |
| `HTTP3_PORT` | Caddy | No | `443` | |
| `IMAGES_PREFIX` | Compose | No | *(empty)* | Prefix for built image names |

### Async email & Symfony Messenger (production)

| Variable | Used by | Notes |
|---|---|---|
| `MESSENGER_TRANSPORT_DSN` | `php`, `messenger_worker` | Usually `doctrine://default?auto_setup=0`; queue name `async` is in `api/config/packages/messenger.yaml`. |
| `MAILER_DSN` | `php`, `messenger_worker` | Real SMTP/API DSN in production (not `null://null`). Set in env for both services. |
| `MAILER_FROM` | Symfony Mailer | Must be authorised by your mail provider. |
| `BANK_NOTIFICATION_EMAIL` | Bank notification handler | Recipient for bank create/update emails. |

Overview: [docs/production-deployment.md](../../../docs/production-deployment.md) and [domain-events-and-messenger.md](../../../docs/domain-events-and-messenger.md).

---

## Secret Rotation

If a secret is accidentally committed to git:

1. Immediately rotate it — generate a new value with `openssl`.
2. Update `.env.prod.local` on the server.
3. Restart the stack to pick up the new value.
4. Purge the secret from git history using `git filter-repo` or GitHub's
   "Secret scanning" remediation flow.
5. Revoke any tokens or sessions that may have used the exposed value.
