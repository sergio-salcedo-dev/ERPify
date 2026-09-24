# Production deployment (ERPify PWA + Symfony API)

Step-by-step guidance for deploying the monorepo safely. For a shorter checklist, see [production-ready.md](./production-ready.md).

## Happy path

1. Build container images (**`frankenphp_prod`** and **PWA** standalone) and push to your registry.
2. Configure **secrets** in your platform (never commit `.env` with real values).
3. Run the stack on a **private network**; expose **`php`** (FrankenPHP on **80/443**) or place a load balancer in front with **TLS**.
4. Set **CORS** on the API to the **exact** public origin(s) (`CORS_ALLOW_ORIGINS` — comma-separated, no `*`).
5. Set PWA **server** env **`SYMFONY_INTERNAL_URL`** to an internal URL the Next server can reach (e.g. **`http://php:80`** in Compose).
6. At **image build** time, set **`NEXT_PUBLIC_API_BASE_URL`** to the same **public** origin users use in the browser (same-host **`https://app.example.com`** avoids mixed content).
7. Smoke-test: PWA loads, **`/api/v1/health`** returns Symfony JSON, HTTPS valid.

## Secrets and configuration

- Store `APP_SECRET`, database URLs, Mercure JWT material, and third-party keys in a secret manager (Kubernetes Secrets, AWS Secrets Manager, Vault, etc.).
- **`NEXT_PUBLIC_API_BASE_URL`** — public origin for **browser** `fetch` (paths like **`/api/v1/...`**). With the default Docker layout this is the **FrankenPHP** host (e.g. **`https://app.example.com`**). Must be **HTTPS** if the page is **HTTPS**. **Must share the page origin** for Mercure realtime to work: the subscriber authorization cookie is `SameSite=Strict`, so a cross-origin API base URL silently kills the `EventSource` subscription (the cookie is never sent cross-site). See [Realtime (Mercure): same-origin requirement](../../docs/integration-architecture.md#realtime-mercure-same-origin-requirement).
- **`SYMFONY_INTERNAL_URL`** — **server-only**; base URL for server-side fetches (e.g. **`http://php:80`** in Compose).
- Rotate credentials on a schedule; do not use default passwords from compose examples on the public internet.

## Transport and edge

- **Self-hosted Compose**: root [`compose.prod.yaml`](../../compose.prod.yaml) builds **`php`** and **`pwa`**. FrankenPHP in **`php`** terminates TLS (e.g. Let’s Encrypt via **`SERVER_NAME`**) and proxies HTML to Next; see [api/docs/production-ready/tls.md](../../api/docs/production-ready/tls.md).
- **Kubernetes / cloud LB**: you can terminate TLS at the ingress and forward HTTP to **`php`**; then configure **`SERVER_NAME=:80`** (or equivalent) so inner Caddy does not fight the edge — see [api/docs/production-ready/tls.md](../../api/docs/production-ready/tls.md).
- Traffic between **pwa** and **php** on the Docker network may stay **HTTP** if the network is private.

## Symfony API (FrankenPHP)

- Use **`APP_ENV=prod`** and **`APP_DEBUG=0`**. Use production images and [`compose.prod.yaml`](../../compose.prod.yaml), not dev bind mounts.
- Do **not** publish PostgreSQL to the public internet.
- **CORS**: Comma-separated **`CORS_ALLOW_ORIGINS`** in `api/.env` (see `api/.env.example`), via [api/config/packages/nelmio_cors.php](../../api/config/packages/nelmio_cors.php). List **exact** origins; never use `*`.

## Next.js PWA

- Run with **`NODE_ENV=production`**. The **`pwa`** image listens on **3000** internally only; browsers hit **`php`** on **443** (or your LB).
- Align **`NEXT_PUBLIC_API_BASE_URL`** (build arg) with the public site URL and **CORS** when origins differ.
- **Sentry** — set **`NEXT_PUBLIC_SENTRY_DSN`** (build arg, **required** in prod via `compose.prod.yaml`) to the **`erpify-pwa-prod`** project DSN; the dev image uses **`erpify-pwa-dev`**. It is public (write-only ingest key), baked into the bundle. Errors + perf tracing (~0.2 sample) capture client, server, and edge runtimes; PII is off and events are scrubbed (`beforeSend`). Events POST to the same-origin **`/monitoring`** tunnel, so no CSP `connect-src` change and no ad-blocker drops. Source-map upload is wired and **opt-in**: set `SENTRY_AUTH_TOKEN` (a real secret; scope a personal token to `project:releases` — an org-scoped `sntrys_` token comes with a fixed scope set) in the root `.env.prod.local`, plus `SENTRY_ORG` unless it is an org-scoped `sntrys_` token, and `docker compose --env-file .env.prod.local build` hands the token to the build as the BuildKit secret `sentry_auth_token` (`compose.prod.yaml`) — never a build arg, because `docker history` prints those. The build uploads the maps, then deletes the client ones from `.next/static` so no browser can fetch them; server maps stay in `.next/server` inside the image, which Next never serves. With no token, or a personal token and no `SENTRY_ORG`, the build succeeds, says upload is off, and prod traces stay minified; a malformed secret mount fails the build (the exact cases: [`../../docs/rules/security.md`](../../docs/rules/security.md) → "Build-time secrets", run by [`../tests/read-sentry-token.test.ts`](../tests/read-sentry-token.test.ts)), and upload failures are printed in the build log. Invariants gated by [`../tests/sentry-sourcemap-exposure.test.ts`](../tests/sentry-sourcemap-exposure.test.ts). The release is the build arg **`SENTRY_RELEASE`**, the commit SHA that `make` exports for `ENV=prod|staging` and shares with the API services. It is baked into both bundles. A bare `docker compose … build` does not go through `make` and bakes no release unless you export `SENTRY_RELEASE=$(git rev-parse HEAD)` first. **`SENTRY_REPOSITORY`** (`owner/name`, optional, needs upload on and Sentry's GitHub integration) associates that release with its commit. Details: [`../../docs/deployment-guide.md`](../../docs/deployment-guide.md) → Observability.

## Docker / images

- The Node process runs as **`nextjs`** in the **pwa** image (`EXPOSE 3000`). FrankenPHP/Caddy runs inside **`php`** (not a separate edge container in the default compose).
- Prefer **minimal** base images; **pin** tags or digests. Scan images in CI.

## Ports (host)

| Service    | Default host ports           | Override via                                        |
| ---------- | ---------------------------- | --------------------------------------------------- |
| FrankenPHP | **80**, **443**, UDP **443** | **`HTTP_PORT`**, **`HTTPS_PORT`**, **`HTTP3_PORT`** |
| Postgres   | **15432** → 5432 (dev only)  | **`POSTGRES_PORT`**                                 |

> Postgres is published to the host only via `compose.dev.yaml`. The base
> `compose.yaml` and `compose.prod.yaml` do not publish the database port,
> so it is reachable only on the internal Compose network in production.

## Monorepo commands (reference)

- **Full stack**: `make docker.up.wait` — **`php`**, **`database`**, **`pwa`** ([`compose.yaml`](../../compose.yaml) + override).
- **Production build**: `ENV=prod make docker.up` (from repo root). Going through `make` is what supplies the Sentry release. A bare `docker compose -f compose.yaml -f compose.prod.yaml build` builds the same images with no release.
