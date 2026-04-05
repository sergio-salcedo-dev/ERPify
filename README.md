# ERPify

This repository is a **monorepo**: one Git project holds the backend API, the Next.js client, Playwright end-to-end tests, and shared documentation.

| Area | Role | Details |
|------|------|---------|
| [`api/`](api/) | Symfony HTTP API | FrankenPHP (Caddy inside `php`) + Docker Compose. See [api/README.md](api/README.md). |
| [`pwa/`](pwa/) | Next.js web app | Served on **:3000** inside Docker; the browser uses **http(s)://localhost** (FrankenPHP reverse-proxies HTML to Next, `/api*` stays in Symfony). See [pwa/README.md](pwa/README.md). |
| [`docs/`](docs/) | Repo-wide docs | [docs/project-requirements.md](docs/project-requirements.md), [docs/local-fullstack-traffic.md](docs/local-fullstack-traffic.md). |

Canonical Compose files: **[`compose.yaml`](compose.yaml)** and **[`compose.override.yaml`](compose.override.yaml)** at the repo root (`php` build **`context: ./api`**, `pwa` build **`context: ./pwa`**). Run **`docker compose`** from the **repository root** (or use the root [`Makefile`](Makefile)) and [`api/.env.example`](api/.env.example) for local env.

## Prerequisites

- **Docker** and **Docker Compose** (v2).
- **GNU Make** matches the targets below.
- **`jq`** is optional but recommended.

See [docs/project-requirements.md](docs/project-requirements.md).

## Quick start (full stack: API + PWA + Postgres)

1. `cp api/.env.example api/.env` and edit `api/.env` as needed.
2. **`make dev-up`** — same as **`docker compose -f compose.yaml -f compose.override.yaml up --wait --build --detach`**, then opens **http://localhost** and **https://localhost** in your browser (`OPEN_BROWSER=0` to skip). For a quicker start without rebuilding images, use **`make up-wait`** or **`make stack-up`** and open the URLs yourself; **`make start`** runs **`build`** then **`up`**.
3. Accept the dev certificate for HTTPS if prompted. The UI is Next.js; **`/api/...`** and **`/.well-known/mercure`** are handled by Symfony on the same host.
4. `make down` to stop.

The PWA image is built with **`NEXT_PUBLIC_SYMFONY_API_BASE_URL=https://localhost`** by default (same origin as the page). Override at build time with env if needed (see [pwa/docs/production-deployment.md](pwa/docs/production-deployment.md)).

## API + database only (no PWA container)

Useful when you run **`npm run dev`** on the host and only need the API on **:8000**:

```bash
make api-up-http
```

Then set **`NEXT_PUBLIC_SYMFONY_API_BASE_URL=http://localhost:8000`** and **`SYMFONY_INTERNAL_URL=http://localhost:8000`** in **`pwa/.env.local`**.

## Local Next.js + Docker API

**`make dev-local`** starts **`api-up-http`** then **`npm run dev`** (Next listens on host **:80** per `pwa/package.json`). Use **`pwa/.env.local`** as above for **:8000** API URLs.

## Useful Make targets

| Target | Purpose |
|--------|---------|
| `make dev-up` | Dev stack: **`up --wait --build -d`** + open HTTP/HTTPS (`OPEN_BROWSER=0` to skip) |
| `make prod-up` | Prod images: **`compose.yaml` + `compose.prod.yaml`** (no override), **`up --wait --build -d`** + browser. Requires **`APP_SECRET`**, **`CADDY_MERCURE_JWT_SECRET`**, **`POSTGRES_PASSWORD`** in the environment |
| `make open-local` | Open **http://localhost** and **https://localhost** only |
| `make up-wait` | Start **php**, **database**, **pwa** with health checks (no **`--build`**) |
| `make stack-up` | Same as **`up-wait`** |
| `make stack-down` / `make down` | Stop the stack |
| `make stack-logs` / `make logs` | Follow Compose logs |
| `make health` | **`curl`** **`HEALTH_URL`** (default **https://localhost/api/v1/health**) |

Run **`make`** or **`make help`** for a short quick start plus all targets by section (`make -s help` omits `make:` directory banners).

## Documentation

- **Traffic flow** (FrankenPHP, Next, Symfony): [docs/local-fullstack-traffic.md](docs/local-fullstack-traffic.md)
- **Next.js app**: [pwa/README.md](pwa/README.md)
- **Symfony / Docker / TLS**: [api/README.md](api/README.md) and [api/docs/](api/docs/)
- **Host tooling**: [docs/project-requirements.md](docs/project-requirements.md)
