# ERPify — Documentation Index

> Updated 2026-05-31. Primary entry point for repo-wide docs. AI agents: load [`project-context.md`](./project-context.md) before generating code. RFC 9457 error contract: [`api-error-contract.md`](./api-error-contract.md). Deep-dives: 2.

## Project at a glance

- **Type:** Monorepo (multi-part: `api/` + `pwa/`)
- **Purpose:** Construction-industry SaaS ERP/CRM.
- **Primary languages:** PHP 8.5 (API), TypeScript 6 (PWA)
- **Architecture:** DDD + Hexagonal / Clean across both parts.

### `api/` — Symfony API (backend)

- **Tech stack:** Symfony 8.0, FrankenPHP (Caddy), Doctrine ORM 3.6 / DBAL 4.4, PostgreSQL, Symfony Messenger, Mercure, Flysystem, Intervention Image.
- **Root:** `api/`
- **Entry point:** `api/src/Kernel.php` via FrankenPHP → `api/public/index.php`
- **Bounded contexts:** `Backoffice/{Bank, Health}`, `Frontoffice/{Dev, Health, Mercure}`, `Shared/{Application, Domain, Guzzle, Infrastructure, Media, Storage}`

### `pwa/` — Next.js PWA (web)

- **Tech stack:** Next.js 16.2 (App Router, Turbopack), React 19.2, TypeScript 6, Tailwind 4, Shadcn, Inversify 8, Vitest 4, Playwright 1.59.
- **Root:** `pwa/`
- **Entry point:** `pwa/src/app/layout.tsx` + `pwa/src/app/page.tsx`
- **Bounded contexts:** `backoffice/{health}`, `frontoffice/{health}`, `shared/{domain, infrastructure}`

## Files

### AI agent context (load first)

- **[project-context.md](./project-context.md)** — Authoritative constraints for AI code generation
- **[claude-code-quickref.md](./claude-code-quickref.md)** — Full command catalog, repo layout tables, "adding new code" recipes, gotchas (companion to root `CLAUDE.md`)
- **[project-scan-report.json](./project-scan-report.json)** — Scan metadata from initial doc generation

### Project overview

- **[project-overview.md](./project-overview.md)** — High-level repo purpose, parts, and tech stack
- **[source-tree-analysis.md](./source-tree-analysis.md)** — Annotated monorepo directory layout

### Architecture

- **[architecture-api.md](./architecture-api.md)** — API layering, stack, Doctrine, Messenger, Mercure
- **[architecture-pwa.md](./architecture-pwa.md)** — PWA layering, Next.js, Inversify DI, testing
- **[integration-architecture.md](./integration-architecture.md)** — How API and PWA share `localhost`
- **[adr-bank-bankaccount-modeling.md](./adr-bank-bankaccount-modeling.md)** — ADR: id-based cross-module references (Bank/BankAccount), schema-aware FK, per-aggregate persistence strategy (state vs event sourcing)
- **[api-error-contract.md](./api-error-contract.md)** — RFC 9457 Problem Details: marker→status map, correlation-id, instance UUIDv7, logging tiers, `exception_category` SRE taxonomy
- **[sentry-domain-error-filtering.md](./sentry-domain-error-filtering.md)** — deferred: drop/sample `domain_error` noise in Sentry (`ignore_exceptions` vs `before_send`), with the trade-off
- **[sentry-boot-probe-noise.md](./sentry-boot-probe-noise.md)** — fixed: silencing the container boot DB-probe flood (`SENTRY_DSN=` on the entrypoint `SELECT 1` wait), safe in dev + prod
- **[sentry-messenger-worker-dev-cache-crash.md](./sentry-messenger-worker-dev-cache-crash.md)** — fixed: dev Messenger worker crashed on a recompiled DI container (shared `var/cache/dev` deleted under the long-lived worker); fix = `APP_DEBUG=0` + private cache volume on the worker

### Deep-Dive Documentation

Detailed exhaustive analysis of specific areas:

- **[deep-dive-api-shared-foundation.md](./deep-dive-api-shared-foundation.md)** — Exhaustive analysis of `api/src/Shared/` (Application, Domain, Infrastructure, Media, Storage, Guzzle; 80 files, ~4,517 LOC) — Generated 2026-05-08
- **[deep-dive-pwa-shared-infrastructure.md](./deep-dive-pwa-shared-infrastructure.md)** — Comprehensive analysis of PWA `shared/infrastructure` (DI container + HTTP transport + shared UI atoms/molecules/organisms; 11 files, ~640 LOC) — Generated 2026-05-08

### Development & contribution

- **[contribution-guide.md](./contribution-guide.md)** — Branches, commits, PR conventions
- **[deployment-guide.md](./deployment-guide.md)** — Docker Compose envs and prod services
- **[erpify-local-test-deployment.md](./erpify-local-test-deployment.md)** — Step-by-step: run the prod profile at `https://erpify.local` (internal TLS) on a local box
- **[vps-deployment.md](./vps-deployment.md)** — Promote to a public VPS + remote database access (CLI / GUI over SSH)
- **[background-jobs-and-scheduling.md](./background-jobs-and-scheduling.md)** — Decision record: supervised `messenger_worker` over host crontab; how to add periodic jobs (Symfony Scheduler) and scale to many daemons
- **[saas-production-roadmap.md](./saas-production-roadmap.md)** — Forward plan: registry publishing, safe migrations, zero-downtime, rollback, staging/prod split (planning only)
- **[development-guide-api.md](./development-guide-api.md)** — Day-to-day API workflow via `make`
- **[development-guide-pwa.md](./development-guide-pwa.md)** — Day-to-day PWA workflow via `make`

## Related references (outside this folder)

- [CLAUDE.md](../CLAUDE.md) — Repo-wide Claude Code guidance
- [api/CLAUDE.md](../api/CLAUDE.md) · [api/README.md](../api/README.md) · `api/docs/` — API-specific docs
- [pwa/CLAUDE.md](../pwa/CLAUDE.md) · [pwa/README.md](../pwa/README.md) · `pwa/docs/` — PWA-specific docs
- `docs/rules/*.md` — Authoritative coding rules (architecture, clean-code, database, frontend, php-standards, security, solid-principles, testing)

## Getting started

```bash
# First time
cp api/.env.example api/.env
make docker.up        # full stack on http(s)://localhost
make db.migrate
make db.load.fixtures

# Common daily commands
make docker.up | docker.down | docker.logs | docker.ps |
make php.test | php.quality
make pwa.test | pwa.quality
make app.test | app.quality  # both parts
make composer c='...'    # composer in container
make db.migrate | db.diff | db.status | db.shell
```

Per-part setup: [`development-guide-api.md`](./development-guide-api.md), [`development-guide-pwa.md`](./development-guide-pwa.md).

## For BMad / PRD workflows

When creating a brownfield PRD or feature plan, point the workflow to this index. For scoped features:

- UI-only → [`architecture-pwa.md`](./architecture-pwa.md)
- API-only → [`architecture-api.md`](./architecture-api.md)
- Full-stack → both + [`integration-architecture.md`](./integration-architecture.md)
