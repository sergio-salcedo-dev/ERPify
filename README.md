# ERPify

This repository is a **monorepo**: one Git project holds the backend API, the Next.js client, Playwright end-to-end tests, and shared documentation.

| Area             | Role             | Details                                                                                                                                                                                                                                                                                                      |
|------------------|------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| [`api/`](api/)   | Symfony HTTP API | FrankenPHP (Caddy inside `php`) + Docker Compose. See [api/README.md](api/README.md).                                                                                                                                                                                                                        |
| [`pwa/`](pwa/)   | Next.js web app  | Served on **:3000** inside Docker; the browser uses **http(s)://localhost** (FrankenPHP reverse-proxies HTML to Next, `/api*` stays in Symfony). See [pwa/README.md](pwa/README.md).                                                                                                                         |
| [`docs/`](docs/) | Repo-wide docs   | [docs/deployment-guide.md](docs/deployment-guide.md) (prod: Messenger, mailer, DNS), [docs/architecture-api.md](docs/architecture-api.md), [docs/integration-architecture.md](docs/integration-architecture.md), [docs/project-overview.md](docs/project-overview.md). |

Canonical Compose files: **[`compose.yaml`](compose.yaml)**, **[`compose.dev.yaml`](compose.dev.yaml)**, and **[`compose.prod.yaml`](compose.prod.yaml)** at the repo root (`php` build **`context: ./api`**, `pwa` build **`context: ./pwa`**). **`compose.dev.yaml`** runs the PWA with **`next dev`** and a bind mount (hot reload). Always run Compose from the **repository root** (or via **`make`** targets) and use [`api/.env.example`](api/.env.example) for local env.

## Prerequisites

- **Docker** and **Docker Compose** (v2).
- **GNU Make** matches the targets below.
- **`jq`** is optional but recommended.

See [docs/project-overview.md](docs/project-overview.md).

## Quick start (full stack: API + PWA + Postgres)

1. `cp api/.env.example api/.env` and edit `api/.env` as needed.
2. **`make dev`** — full dev stack with **`docker compose`** (**`compose.yaml`** + **`compose.dev.yaml`**), **`up --wait --build --detach`**, then opens **http://localhost** and **https://localhost** in your browser (`OPEN_BROWSER=0` to skip). For a quicker start without rebuilding, use **`make docker.up.wait`**; **`make docker.up`** rebuilds detached without the browser step.
3. Accept the dev certificate for HTTPS if prompted. The UI is Next.js; **`/api/...`** and **`/.well-known/mercure`** are handled by Symfony on the same host.
4. **`make docker.down`** to stop.

The PWA image is built with **`NEXT_PUBLIC_SYMFONY_API_BASE_URL=https://localhost`** by default (same origin as the page). Override at build time with env if needed (see [pwa/docs/production-deployment.md](pwa/docs/production-deployment.md)).

## API + database only (no PWA container)

Useful when you run **`npm run dev`** on the host and only need the API on **:8000**:

```bash
make api-up-http
```

Then set **`NEXT_PUBLIC_SYMFONY_API_BASE_URL=http://localhost:8000`** and **`SYMFONY_INTERNAL_URL=http://localhost:8000`** in **`pwa/.env.local`**.

## Local Next.js + Docker API

**`make dev.local`** starts **`api-up-http`** then **`make pwa.dev`** (Next listens on host **:80** per `pwa/package.json`). Use **`pwa/.env.local`** as above for **:8000** API URLs.

## Useful Make targets

The root `Makefile` is the canonical interface — it includes the modules in `make/*.mk` and is **ENV-aware** (`ENV=dev|staging|prod` switches the Compose overlay). Prefer `make` targets over invoking `docker compose`, `composer`, `npm`, or linters directly.

| Target | Purpose |
|--------|---------|
| `make dev` | Full dev stack: **`up --wait --build -d`** + open HTTP/HTTPS (`OPEN_BROWSER=0` to skip) |
| `make docker.up` | Stack up detached, rebuild images (ENV-aware) |
| `make docker.up.wait` | Stack up detached with **`--wait`** health gate (no **`--build`**) |
| `make docker.down` | Stop the stack and remove orphans |
| `make docker.clean` | Stop stack and **remove volumes** (destructive) |
| `make docker.logs` | Follow Compose logs (all services) |
| `make docker.health` | GET **`HEALTH_URL`** (default **https://localhost/api/v1/health**) |
| `make docker.bash` / `make docker.sh` | Shell into the **`php`** container |
| `make prod-up` | Prod overlay (**`compose.yaml` + `compose.prod.yaml`**), **`up --wait --build -d`** + browser. Requires **`APP_SECRET`**, **`CADDY_MERCURE_JWT_SECRET`**, **`POSTGRES_PASSWORD`** |
| `make api-up-http` | API + DB only on host **:8000** (no PWA container) |
| `make dev.local` | API + DB on :8000 + Next dev on host (needs `pwa/.env.local`) |
| `make open-local` | Open **http://localhost** and **https://localhost** only |
| `make lint` / `make test` / `make ci` | All linters / all tests / full CI (PHP + PWA) |

Run **`make`** or **`make help`** for the full target list grouped by section (`make -s help` omits `make:` directory banners). Pass extra args to composer / Symfony / PHPUnit / Vitest with **`c='…'`**, e.g. **`make composer c='req vendor/pkg'`**.

## Container images

Base images in **`api/Dockerfile`** and **`pwa/Dockerfile`** are **pinned to `sha256` digests** (FrankenPHP, Debian slim, Node Alpine) so upstream tag changes cannot silently enter builds. Dependabot tracks digest bumps for both **`/api`** and **`/pwa`** (see [`.github/dependabot.yml`](.github/dependabot.yml)).

## Documentation

- **Docs index** (entry point, generated by `bmad-document-project`): [docs/index.md](docs/index.md)
- **Project overview** (purpose, scope, prerequisites): [docs/project-overview.md](docs/project-overview.md)
- **Deployment** (DNS, TLS, Compose, `messenger_worker`, mailer, CORS, Mercure, smoke tests): [docs/deployment-guide.md](docs/deployment-guide.md) and [pwa/docs/production-deployment.md](pwa/docs/production-deployment.md)
- **Integration architecture** (FrankenPHP ↔ Next ↔ Symfony traffic flow): [docs/integration-architecture.md](docs/integration-architecture.md)
- **API architecture** (domain events, Messenger, audit table, worker behaviour): [docs/architecture-api.md](docs/architecture-api.md)
- **PWA architecture**: [docs/architecture-pwa.md](docs/architecture-pwa.md)
- **Development guides**: [docs/development-guide-api.md](docs/development-guide-api.md), [docs/development-guide-pwa.md](docs/development-guide-pwa.md)
- **Contribution guide**: [docs/contribution-guide.md](docs/contribution-guide.md)
- **Source tree**: [docs/source-tree-analysis.md](docs/source-tree-analysis.md)
- **Deployable READMEs**: [api/README.md](api/README.md) (Symfony / Docker / TLS, plus [api/docs/](api/docs/)), [pwa/README.md](pwa/README.md) (Next.js app)
