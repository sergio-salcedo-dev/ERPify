# Production deployment (monorepo)

This guide describes how to run **ERPify** in production: **FrankenPHP** (Symfony API + TLS), **Next.js PWA**, **PostgreSQL**, **Symfony Messenger** (async workers), and **mail delivery**. It assumes Docker Compose at the **repository root** (`compose.yaml` + `compose.prod.yaml`).

For deeper topics, use the linked guides in [`api/docs/production-ready/`](../api/docs/production-ready/production.md) (TLS, secrets, database, hardening) and [pwa/docs/production-deployment.md](../pwa/docs/production-deployment.md) (PWA build args). Domain-event and queue behaviour is detailed in [domain-events-and-messenger.md](domain-events-and-messenger.md).

**Flysystem object storage** (bank `storedObjectUrl`, future modules): full configuration, volume mount example, backups, and smoke tests — **[object-storage.md](object-storage.md)**.

---

## Architecture (Compose)

| Service | Role |
|---------|------|
| **`php`** | FrankenPHP + Caddy: public **80/443**, reverse-proxies HTML/`_next` to the PWA, serves **`/api/*`** and **Mercure** (`/.well-known/mercure`). Runs migrations on startup. |
| **`pwa`** | Next.js on **:3000** (internal only); not exposed directly in the default layout. |
| **`database`** | PostgreSQL. In production, **do not publish** the host port to the internet. |
| **`messenger_worker`** | Long-lived **`messenger:consume async`** process. **Required** if you use async routing (e.g. bank notification emails). Uses the same **`DATABASE_URL`** and **`MESSENGER_TRANSPORT_DSN`** as the API. |

Commands and env substitution use **`api/.env`** (and optionally a host-level **`.env`** next to Compose, depending on your setup). Run Compose from the **repo root**:

```bash
cd /path/to/ERPify
docker compose -f compose.yaml -f compose.prod.yaml up --wait --build --detach
```

(`make prod-up` does the same merge; ensure required secrets are in the environment.)

---

## DNS and public URLs

1. **A (or AAAA) record**  
   Point your **public hostname** (e.g. `app.example.com`) at the server’s public IP **before** first TLS issuance. Let’s Encrypt needs a resolvable name (not a bare IP for HTTP-01).

2. **`SERVER_NAME` (Caddy / FrankenPHP)**  
   Set to the hostnames Caddy should serve and request certificates for, e.g. `app.example.com`. The default in `compose.yaml` is `localhost`; production **must** override this. See [api/docs/production-ready/server-setup.md](../api/docs/production-ready/server-setup.md) and [tls.md](../api/docs/production-ready/tls.md).

3. **`DEFAULT_URI` / Symfony URL generation**  
   Align with your canonical **HTTPS** origin (e.g. `https://app.example.com`) so generated URLs and redirects are correct.

4. **Mercure**  
   **`MERCURE_PUBLIC_URL`** must be a URL **browsers** can reach (typically `https://app.example.com/.well-known/mercure` when TLS terminates on the same host). Keep **`CADDY_MERCURE_JWT_SECRET`** (or equivalent publisher/subscriber keys) in sync with Caddy/Symfony config. See [secrets.md](../api/docs/production-ready/secrets.md). Step-by-step: [mercure-production-deployment.md](mercure-production-deployment.md); architecture: [mercure.md](mercure.md).

5. **PWA ↔ API (same site)**  
   When the browser talks to the same host for pages and `/api`, set **`NEXT_PUBLIC_SYMFONY_API_BASE_URL`** at **image build time** to that public origin (e.g. `https://app.example.com`). See [pwa/docs/production-deployment.md](../pwa/docs/production-deployment.md).

6. **`CORS_ALLOW_ORIGINS`**  
   In `api/.env`, list **exact** allowed origins (comma-separated, no `*`). If the PWA is served from the same origin as the API (default Compose layout), include that origin. See `api/.env.example`.

---

## Secrets and environment (checklist)

Never commit real secrets. Minimum production set:

| Variable | Notes |
|----------|--------|
| **`APP_SECRET`** | Random 32+ hex chars; required for Symfony. |
| **`APP_ENV`** | `prod`; **`APP_DEBUG`** must not be `1`. |
| **`POSTGRES_*`** | Strong password; avoid raw `@`, `#`, `/`, `?` in passwords used inside `DATABASE_URL`, or URL-encode them. |
| **`DATABASE_URL`** | Same database for **`php`** and **`messenger_worker`** (Compose defaults derive from `POSTGRES_*`). |
| **`CADDY_MERCURE_JWT_SECRET`** | Random string; wires Mercure keys in Compose. |
| **`MESSENGER_TRANSPORT_DSN`** | Typically `doctrine://default?auto_setup=0`; **`messenger_messages`** table must exist via migrations. |
| **`MAILER_DSN`** | Real transport in production (SMTP, API bridge, etc.). **`null://null`** only for labs. |
| **`MAILER_FROM`** | Must be a domain/address your provider allows (SPF/DKIM/DMARC on that domain). |
| **`BANK_NOTIFICATION_EMAIL`** | Operational inbox for bank create/update notifications (async handler). |
| **`OBJECT_STORAGE_LOCAL_PATH`** | **Production:** absolute path to the Flysystem local root for content-addressable files (`objects/{hash}`). Must be on a **persistent volume** (see [object-storage.md](object-storage.md)). Optional in dev (defaults under `var/storage/objects`). |
| **`MEDIA_PUBLIC_BASE_URL`** | Optional. If set, **`logoUrl`** and **`storedObjectUrl`** in JSON use this origin; needed when clients require stable absolute asset URLs. |

`compose.prod.yaml` passes **`APP_SECRET`** and **`MAILER_DSN`** into **`messenger_worker`**; other worker variables come from the base **`compose.yaml`** merge (e.g. **`DATABASE_URL`**, **`MESSENGER_TRANSPORT_DSN`**, **`BANK_NOTIFICATION_EMAIL`**). If you add env-only overrides in production, ensure **both** **`php`** and **`messenger_worker`** stay aligned on DB and Messenger DSN.

Full variable tables: [api/docs/production-ready/secrets.md](../api/docs/production-ready/secrets.md).

---

## Symfony Messenger in production

- **Run at least one consumer** for the **`async`** transport (`messenger_worker` in Compose). Without it, HTTP requests still succeed and **domain events are still audited**, but **async handlers** (e.g. emails) remain in **`messenger_messages`**.

- **Deploys / new code**  
  After shipping new code, restart workers or run **`php bin/console messenger:stop-workers`** so processes reload. If you use multiple nodes, use a **shared** cache for stop signals (see [Symfony: Deploying Messenger](https://symfony.com/doc/current/messenger.html#deploying-to-production)).

- **Failures**  
  Failed messages go to the **`failed`** transport (Doctrine). Inspect with **`messenger:failed:show`**, retry with **`messenger:failed:retry`**.

- **Scaling**  
  You may run **multiple** worker replicas **if** they all consume the same Doctrine queue (competing consumers). Monitor DB load and processing lag.

More detail: [domain-events-and-messenger.md](domain-events-and-messenger.md).

---

## Mailer in production

- Set **`MAILER_DSN`** to your provider (examples: **`smtp://user:pass@host:465`** with appropriate scheme/options, or a Symfony Mailer bridge package). Notification code uses the **`NotificationMailer`** port (default **`PlainTextNotificationMailer`**), which uses Symfony **`MailerInterface`**.

- **`MAILER_FROM`** and **`BANK_NOTIFICATION_EMAIL`** are configured via env (see `api/config/services.yaml` defaults). Use addresses you control; configure **SPF**, **DKIM**, and **DMARC** for the From domain to improve deliverability.

- **Dev-only Mailpit** (`compose.override.yaml`) is **not** merged in **`compose.prod.yaml`**; production mail must use a real DSN.

---

## Database migrations

Migrations run from the **`php`** container entrypoint after the database is healthy. **`MESSENGER_TRANSPORT_DSN`** uses **`auto_setup=0`**, so **`messenger_messages`** and **`domain_event`** (and related indexes) must come from **Doctrine migrations**, not auto DDL. Confirm pending migrations are applied after each release (`doctrine:migrations:status`).

---

## Smoke tests after go-live

1. **`GET /api/v1/health`** (and backoffice health if you use it) over HTTPS.  
2. Load the PWA from the public URL; confirm **`NEXT_PUBLIC_SYMFONY_API_BASE_URL`** matches reality (no mixed content).  
3. Create or update a bank via the API; confirm a row in **`domain_event`** with name **`erpify.backoffice.bank.created`** or **`erpify.backoffice.bank.updated`**, and that the worker delivers mail (inbox or provider logs).  
4. **`docker compose … logs messenger_worker`** — no repeating fatal errors.  
5. **Object storage (if you use bank `stored_object` or similar):** confirm **`OBJECT_STORAGE_LOCAL_PATH`** is mounted and writable; upload once and **`GET /api/v1/stored-objects/{hash}`** returns **200** (see [object-storage.md](object-storage.md)).

---

## Related documentation

| Topic | Document |
|-------|----------|
| Production index (TLS, DB, hardening, scaling) | [api/docs/production-ready/production.md](../api/docs/production-ready/production.md) |
| DNS, starting the stack | [api/docs/production-ready/server-setup.md](../api/docs/production-ready/server-setup.md) |
| Secrets & env reference | [api/docs/production-ready/secrets.md](../api/docs/production-ready/secrets.md) |
| PWA build / public API URL | [pwa/docs/production-deployment.md](../pwa/docs/production-deployment.md) |
| Domain events, audit table, Messenger flow | [domain-events-and-messenger.md](domain-events-and-messenger.md) |
| Flysystem paths, prod volumes, backups, URLs | [object-storage.md](object-storage.md) |
| Local traffic (FrankenPHP → Next) | [local-fullstack-traffic.md](local-fullstack-traffic.md) |
