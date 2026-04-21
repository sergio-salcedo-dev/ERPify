# Integration Architecture

How the `api/` and `pwa/` parts communicate, and the traffic topology that makes it work.

## Parts

| Part | Role | Port(s) |
|---|---|---|
| `api/` (FrankenPHP) | Origin for all browser traffic; hosts Symfony API + serves Mercure Hub + reverse-proxies HTML | `:80` / `:443` (dev), `:443` (prod) |
| `pwa/` (Next.js) | Web UI rendering | `:3000` (inside the Compose network); `:80` on host for `dev-local` |
| PostgreSQL | API's primary data store | Internal Compose network |
| Mercure Hub | SSE hub (separate or embedded) | Behind `/.well-known/mercure` on the FrankenPHP origin |
| `messenger_worker` (prod/ci only) | Symfony Messenger consumer | N/A (no HTTP) |

## Traffic topology (default: Docker dev)

```
                       ┌─────────────────────────────┐
browser → localhost ──▶│     FrankenPHP (Caddy)      │
                       │   TLS + same-origin mux     │
                       └──────────────┬──────────────┘
                                      │
                ┌─────────────────────┼─────────────────────┐
                │                     │                     │
         GET / (HTML)           /api/*  (HTTP)         /.well-known/mercure  (SSE)
                │                     │                     │
                ▼                     ▼                     ▼
         pwa  :3000             Symfony Kernel          Mercure Hub
        (Next.js SSR/RSC)       (api/src/Kernel)        (JWT, topics)
                                      │
                                      ▼
                               PostgreSQL  (Doctrine)
                                      │
                                      ▼
                            Messenger transport (Doctrine)
                                      │
                                      ▼
                           messenger_worker  (prod/ci)
                                 │      │
                                 ▼      ▼
                             Mailer   Audit / async handlers
```

## Integration points

| # | From | To | Protocol | Notes |
|---|---|---|---|---|
| 1 | Browser → FrankenPHP | pwa Next SSR | HTTP (reverse-proxy) | HTML on `/`; FrankenPHP forwards to `pwa:3000` in the Compose network. |
| 2 | Browser → FrankenPHP | Symfony API | HTTP | Path `/api/*`. Same origin as HTML, so no CORS preflight in the default flow. |
| 3 | Browser → FrankenPHP | Mercure Hub | SSE / HTTP | Path `/.well-known/mercure`. JWT required. |
| 4 | Next SSR/RSC → Symfony API | Server-side fetch | HTTP | Uses `SYMFONY_INTERNAL_URL` (container-internal URL), **not** the browser URL. |
| 5 | Next client → Symfony API | Client-side fetch | HTTP | Uses `NEXT_PUBLIC_SYMFONY_API_BASE_URL`. In the Docker dev flow this is same-origin; in `dev-local` it is `http://localhost:8000`. |
| 6 | Symfony → PostgreSQL | Doctrine DBAL | TCP (Compose network) | — |
| 7 | Symfony → Messenger transport | Internal | Doctrine transport | At-least-once delivery; handlers must be idempotent. |
| 8 | `messenger_worker` → Mailer | Async | Symfony Messenger + Mailer | Email is async — see `domain-events-and-messenger.md`. |
| 9 | Symfony → Mercure Hub | Publish | HTTP + JWT | Server-side publish; topics scoped per bounded context. |

## Alternative flow: `dev-local` (host Next, containerised API)

```
browser → localhost:80  ─▶  Next.js  (on host, next dev)
                                  │
                                  └─▶  localhost:8000  ─▶  Symfony API (container)
```

- `make api-up-http` / `make dev.local` starts API on `:8000` only (no `pwa` container).
- **Required env in `pwa/.env.local`:**
  - `NEXT_PUBLIC_SYMFONY_API_BASE_URL=http://localhost:8000`
  - `SYMFONY_INTERNAL_URL=http://localhost:8000`
- Mercure and Symfony share `:8000`; there is no FrankenPHP proxy in this mode.
- Do **not** mix flows in a single session — switching requires clearing `pwa/.env.local` and rebuilding.

Full walkthrough: [`local-fullstack-traffic.md`](./local-fullstack-traffic.md).

## Authentication / authorization flow

_(Quick scan — not read from source. Cross-check against `api/src/**` controllers and Symfony security config before relying on details.)_

- CORS: `nelmio_cors.yaml` / `nelmio_cors.php` — same-origin default; no wildcard `*` for credentialed requests.
- Auth checks performed at the **Application layer** (use cases), not only at controller `#[IsGranted]`.
- Mercure subscriptions require the JWT configured via `CADDY_MERCURE_JWT_SECRET` in prod.

## Data flow: domain event → email (example)

1. Application use case in a bounded context emits a domain event (marker interface from `Shared/`).
2. Messenger transport persists the message (Doctrine transport).
3. `messenger_worker` consumes the message asynchronously.
4. Handler orchestrates a mailer action (and/or publishes a Mercure update).
5. Audit entry written per `docs/domain-events-and-messenger.md`.

See [`domain-events-and-messenger.md`](./domain-events-and-messenger.md) for the full contract.

## Shared dependencies

- Both parts speak the same HTTP origin (default flow) — avoids CORS, enables cookie auth.
- Mercure JWT secret (`CADDY_MERCURE_JWT_SECRET`) is shared between FrankenPHP/Caddy (verification) and Symfony publishers (signing).
- Postgres credentials (`POSTGRES_PASSWORD`) shared by API and, if used, fixtures tooling.

## Prod deployment notes

- `messenger_worker` and mail pipeline are separate Compose services in `compose.prod.yaml`.
- DNS, CORS origins, and Mercure cookie/CORS config: [`mercure-production-deployment.md`](../docs-info/mercure-production-deployment.md) and [`production-deployment.md`](../docs-info/production-deployment.md).
- After deploy, run the documented smoke tests per [`production-deployment.md`](../docs-info/production-deployment.md).
