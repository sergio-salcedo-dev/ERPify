# ERPify — Documentation Index

> Generated 2026-04-21 by `bmad-document-project` (quick scan). Primary entry point for AI-assisted development.

## Project overview

- **Type:** Monorepo (multi-part: `api/` + `pwa/`)
- **Purpose:** Construction SaaS ERP/CRM.
- **Primary languages:** PHP 8.5 (API), TypeScript 6 (PWA)
- **Architecture:** DDD + Hexagonal / Clean across both parts.

## Quick reference by part

### `api/` — Symfony API (backend)

- **Tech stack:** Symfony 8.0, FrankenPHP (Caddy), Doctrine ORM 3.6 / DBAL 4.4, PostgreSQL, Symfony Messenger, Mercure, Flysystem, Intervention Image.
- **Root:** `api/`
- **Entry point:** `api/src/Kernel.php` via FrankenPHP → `api/public/index.php`
- **Bounded contexts:** `Backoffice/{Bank, Health}`, `Frontoffice/{Dev, Health, Mercure}`, `Shared/{Application, Domain, Infrastructure, Media, Storage}`

### `pwa/` — Next.js PWA (web)

- **Tech stack:** Next.js 16.2 (App Router, Turbopack), React 19.2, TypeScript 6, Tailwind 4, Shadcn, Inversify 8, Vitest 4, Playwright 1.59.
- **Root:** `pwa/`
- **Entry point:** `pwa/src/app/layout.tsx` + `pwa/src/app/page.tsx`
- **Bounded contexts:** `backoffice/{health}`, `frontoffice/{health}`, `shared/{domain, infrastructure}`

## Generated documentation

- **[Project Context for AI Agents](./project-context.md)** — ← Load first when generating code
- [Project Overview](./project-overview.md)
- [Source Tree Analysis](./source-tree-analysis.md)
- [Architecture — API](./architecture-api.md)
- [Architecture — PWA](./architecture-pwa.md)
- [Integration Architecture](./integration-architecture.md)
- [Development Guide — API](./development-guide-api.md)
- [Development Guide — PWA](./development-guide-pwa.md)
- [Deployment Guide](./deployment-guide.md)
- [Contribution Guide](./contribution-guide.md)
- API Contracts — API *(To be generated)*
- Data Models — API *(To be generated)*
- Component Inventory — PWA *(To be generated)*

## Existing documentation

- [Project Requirements](./project-requirements.md) — original product requirements
- [Local Fullstack Traffic](./local-fullstack-traffic.md) — how FrankenPHP/Next/Symfony share `localhost`
- [Domain Events & Messenger](./domain-events-and-messenger.md) — async email, audit table, worker behaviour
- [Production Deployment](./production-deployment.md) — prod Compose, `messenger_worker`, mailer, DNS, CORS
- [Mercure](./mercure.md) / [Mercure — Production Deployment](./mercure-production-deployment.md)
- [Media Upload](./media-upload.md) / [Object Storage](./object-storage.md)
- [`api/README.md`](../api/README.md) · [`api/docs/`](../api/docs/)
- [`pwa/README.md`](../pwa/README.md) · [`pwa/AGENTS.md`](../pwa/AGENTS.md) · [`pwa/CLAUDE.md`](../pwa/CLAUDE.md) · [`pwa/docs/`](../pwa/docs/)
- [`CLAUDE.md`](../CLAUDE.md) — project-wide Claude Code guidance
- [`.cursor/rules/*.mdc`](../.cursor/rules/) — authoritative coding rules (architecture, clean-code, database, frontend, php-standards, security, solid-principles, testing, role, commits)

## Getting started

```bash
# First time
cp api/.env.example api/.env
make docker.up        # full stack on http(s)://localhost
make db.migrate
make db.load.fixtures

# Common daily commands
make docker.up | docker.down | docker.logs | docker.ps | docker.health
make php.test | php.lint
make pwa.test | pwa.lint
make test     | lint     # both parts
make composer c='...'    # composer in container
make db.migrate | db.diff | db.status | db.shell
```

Per-part setup: [`development-guide-api.md`](./development-guide-api.md), [`development-guide-pwa.md`](./development-guide-pwa.md).

## For BMad / PRD workflows

When creating a brownfield PRD or feature plan, point the workflow to this index. For scoped features:

- UI-only → [`architecture-pwa.md`](./architecture-pwa.md)
- API-only → [`architecture-api.md`](./architecture-api.md)
- Full-stack → both + [`integration-architecture.md`](./integration-architecture.md)
