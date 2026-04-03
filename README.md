# ERPify

This repository is a **monorepo**: one Git project holds the backend API, a planned Next.js client, Playwright end-to-end tests, and shared documentation. Everything is versioned together so contracts and releases stay aligned.

| Area | Role | Details |
|------|------|---------|
| [`api/`](api/) | Symfony HTTP API | FrankenPHP / Caddy via Docker Compose. See [api/README.md](api/README.md). |
| [`pwa/`](pwa/) | Next.js web app | Planned UI that talks to the API. See [pwa/README.md](pwa/README.md). |
| [`e2e/`](e2e/) | Playwright E2E tests | Planned cross-stack browser tests. See [e2e/README.md](e2e/README.md). |
| [`docs/`](docs/) | Repo-wide docs | e.g. [docs/project-requirements.md](docs/project-requirements.md) (host prerequisites). |

Root [`Makefile`](Makefile) and [`.env.example`](.env.example) target the API: run Docker Compose from the repo root without `cd api` for most workflows.

## Prerequisites

**Docker** and **Docker Compose** (v2). **GNU Make** matches the targets below. **`jq`** is optional but recommended for `make health`. See [docs/project-requirements.md](docs/project-requirements.md).

## Quick start (API)

1. `cp .env.example api/.env` and edit `api/.env` as needed.
2. `make start` (or `make up-wait` after images exist).
3. Open [https://localhost](https://localhost) and accept the dev certificate.
4. `make down` to stop.

## Useful Make targets

Run `make` or `make help` for the full list. 

## Documentation

- **Next.js app (planned)**: [pwa/README.md](pwa/README.md)
- **Playwright E2E (planned)**: [e2e/README.md](e2e/README.md)
- **Symfony Docker template** (TLS, production, Xdebug, etc.): [api/README.md](api/README.md) and [api/docs/](api/docs/)
- **Host tooling**: [docs/project-requirements.md](docs/project-requirements.md)
