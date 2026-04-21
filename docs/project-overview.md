# ERPify — Project Overview

## Purpose

Construction-industry SaaS ERP/CRM (per `pwa/CLAUDE.md`). The repository holds the full delivery: HTTP API, Web PWA, test suites, infrastructure Compose stack, and shared docs.

## Repository classification

- **Type:** Monorepo (multi-part)
- **Parts:** 2 — `api/` (backend) and `pwa/` (web)
- **Orchestration:** Docker Compose (`compose.yaml` + `compose.dev.yaml` / `compose.prod.yaml`) from the repo root
- **Canonical commands:** root `Makefile` + `make/*.mk`

## Tech stack summary

| Part | Role | Language / Runtime | Framework | Key infrastructure |
|---|---|---|---|---|
| `api/` | HTTP API + async workers | PHP 8.5 | Symfony 8.0.x | FrankenPHP (Caddy), Doctrine ORM 3.6 / DBAL 4.4, PostgreSQL, Symfony Messenger, Mercure Hub, Flysystem |
| `pwa/` | Web UI | TypeScript 6 / Node 24 | Next.js 16.2 (App Router) + React 19.2 | Tailwind 4.2, Shadcn, Inversify 8 DI, Vitest 4, Playwright 1.59 |

## Architecture type

**DDD + Hexagonal + Clean Architecture** on both parts. Each bounded context is split into `Domain / Application / Infrastructure` layers with dependencies pointing inward to `Domain`. See `.cursor/rules/architecture.mdc` and `docs/project-context.md`.

- **API bounded contexts:** `Backoffice/{Bank, Health}`, `Frontoffice/{Dev, Health, Mercure}`, `Shared/{Application, Domain, Infrastructure, Media, Storage}`.
- **PWA bounded contexts:** `backoffice/{health}`, `frontoffice/{health}`, `shared/{domain, infrastructure}`.

## Traffic model (dev, default)

Browser → `http(s)://localhost` → **FrankenPHP**:
- `/` HTML → reverse-proxied to Next.js on `:3000` (inside the `pwa` container).
- `/api/*` and `/.well-known/mercure` → handled by Symfony on the same origin.

Alternative flows:
- `make api-up-http` — API on host `:8000`, run `next dev` on host `:80` (set `NEXT_PUBLIC_SYMFONY_API_BASE_URL=http://localhost:8000` and `SYMFONY_INTERNAL_URL=http://localhost:8000` in `pwa/.env.local`).
- `make dev.local` — same as above but starts Next via make.

Full details: [`local-fullstack-traffic.md`](./local-fullstack-traffic.md).

## Detailed documentation

- **[`project-context.md`](./project-context.md)** — critical rules AI agents must follow (load first).
- [`architecture-api.md`](./architecture-api.md) — Symfony API architecture.
- [`architecture-pwa.md`](./architecture-pwa.md) — Next.js PWA architecture.
- [`integration-architecture.md`](./integration-architecture.md) — how `pwa` and `api` communicate.
- [`source-tree-analysis.md`](./source-tree-analysis.md) — annotated directory tree.
- [`development-guide-api.md`](./development-guide-api.md) / [`development-guide-pwa.md`](./development-guide-pwa.md) — per-part dev setup.
- [`deployment-guide.md`](./deployment-guide.md) — prod Compose, env vars, workers.
- [`contribution-guide.md`](./contribution-guide.md) — branches, commits, PRs, hooks.
- [`domain-events-and-messenger.md`](./domain-events-and-messenger.md) — async email, audit, worker.
- [`production-deployment.md`](./production-deployment.md), [`mercure-production-deployment.md`](./mercure-production-deployment.md).
- [`project-requirements.md`](./project-requirements.md) — original requirements.
