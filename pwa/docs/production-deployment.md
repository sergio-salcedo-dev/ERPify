# Production deployment (ERPify PWA + Symfony API)

Step-by-step guidance for deploying the monorepo safely. For a shorter checklist, see [production-ready.md](./production-ready.md).

## Happy path

1. Build container images (**`frankenphp_prod`** and **PWA** standalone) and push to your registry.
2. Configure **secrets** in your platform (never commit `.env` with real values).
3. Run the stack on a **private network**; expose **`php`** (FrankenPHP on **80/443**) or place a load balancer in front with **TLS**.
4. Set **CORS** on the API to the **exact** public origin(s) (`CORS_ALLOW_ORIGINS` — comma-separated, no `*`).
5. Set PWA **server** env **`SYMFONY_INTERNAL_URL`** to an internal URL the Next server can reach (e.g. **`http://php:80`** in Compose).
6. At **image build** time, set **`NEXT_PUBLIC_SYMFONY_API_BASE_URL`** to the same **public** origin users use in the browser (same-host **`https://app.example.com`** avoids mixed content).
7. Smoke-test: PWA loads, **`/api/v1/health`** returns Symfony JSON, HTTPS valid.

## Secrets and configuration

- Store `APP_SECRET`, database URLs, Mercure JWT material, and third-party keys in a secret manager (Kubernetes Secrets, AWS Secrets Manager, Vault, etc.).
- **`NEXT_PUBLIC_SYMFONY_API_BASE_URL`** — public origin for **browser** `fetch` (paths like **`/api/v1/...`**). With the default Docker layout this is the **FrankenPHP** host (e.g. **`https://app.example.com`**). Must be **HTTPS** if the page is **HTTPS**.
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
- Align **`NEXT_PUBLIC_SYMFONY_API_BASE_URL`** (build arg) with the public site URL and **CORS** when origins differ.

## Docker / images

- The Node process runs as **`nextjs`** in the **pwa** image (`EXPOSE 3000`). FrankenPHP/Caddy runs inside **`php`** (not a separate edge container in the default compose).
- Prefer **minimal** base images; **pin** tags or digests. Scan images in CI.

## Ports (host)

| Service    | Default host ports           | Override via                                        |
| ---------- | ---------------------------- | --------------------------------------------------- |
| FrankenPHP | **80**, **443**, UDP **443** | **`HTTP_PORT`**, **`HTTPS_PORT`**, **`HTTP3_PORT`** |
| Postgres   | **15432** → 5432             | **`POSTGRES_PORT`**                                 |

## Monorepo commands (reference)

- **Full stack**: `make up-wait` — **`php`**, **`database`**, **`pwa`** ([`compose.yaml`](../../compose.yaml) + override).
- **Production build**: `docker compose -f compose.yaml -f compose.prod.yaml build` (from repo root).
- **API on :8000 only** (host Next against Docker API): `make api-up-http` — set **`pwa/.env.local`** to **`http://localhost:8000`** for API URLs.
- **Local Next + API on 8000**: `make dev-local` — see [`pwa/.env.example`](../.env.example).
