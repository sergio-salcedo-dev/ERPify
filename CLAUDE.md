# CLAUDE.md

Guidance for Claude Code (claude.ai/code) in this repository. Nested `CLAUDE.md` files exist for deployable-specific context ([`api/CLAUDE.md`](api/CLAUDE.md), [`pwa/CLAUDE.md`](pwa/CLAUDE.md)) and auto-load when working inside that subtree — this file is the monorepo-wide baseline.

## Repository shape

Monorepo with two deployables and a shared Compose stack driven from the repo root:

-   `api/` — Symfony HTTP API served by FrankenPHP (Caddy embedded). Source in `api/src/` split into `Backoffice/`, `Frontoffice/`, and `Shared/` (each internally layered `Domain` / `Application` / `Infrastructure`). `Kernel.php` is the Symfony kernel. See [`api/CLAUDE.md`](api/CLAUDE.md) for API-specific guidance.
-   `pwa/` — Next.js 16 (App Router) + TypeScript + Tailwind 4 + Inversify DI. Domain logic under `src/context/<bounded-context>/{domain,application,infrastructure}`; `src/app/` is the App Router, `src/components/` is UI, `src/lib/` is glue. Tests in `tests/` mirror `src/`. See [`pwa/CLAUDE.md`](pwa/CLAUDE.md) for PWA-specific guidance.
-   `compose.yaml` + overlays (`compose.dev.yaml`, `compose.prod.yaml`) at the root. `php` builds from `./api`, `pwa` builds from `./pwa`. Base images are **sha256-pinned**; Dependabot tracks digest bumps. **Always run Compose from the repo root.**

Traffic model in dev: the browser hits `http(s)://localhost`. FrankenPHP reverse-proxies HTML to Next.js (`:3000` inside the `pwa` container); `/api/*` and `/.well-known/mercure` are handled by Symfony on the same origin. See [`docs/integration-architecture.md`](docs/integration-architecture.md). The `make dev.local` flow runs `next dev` on the host (port 80) against the API on `:8000` — this requires `NEXT_PUBLIC_SYMFONY_API_BASE_URL=http://localhost:8000` and `SYMFONY_INTERNAL_URL=http://localhost:8000` in `pwa/.env.local`.

## Make entry points

The root `Makefile` is the canonical interface — it includes the modules in `make/*.mk`. **Prefer `make` targets** over invoking `docker compose`, `composer`, `npm`, or linters directly; the targets decide whether to exec inside the `php`/`pwa` container based on `ENV` (`dev`, `ci`, `staging`, `prod`) and `IN_CONTAINER`. Run `make help` for the full list grouped by section.

Stack:

-   `make dev` — full dev stack (`--wait --build -d`) + browser open. `OPEN_BROWSER=0` to skip.
-   `make docker.up` / `docker.up.wait` — start stack detached (with/without `--build`). Pass `ENV=staging|prod` to switch overlays.
-   `make docker.down`, `docker.logs`, `docker.ps`, `docker.health`, `docker.bash`, `docker.clean` (destructive: drops volumes).
-   `make dev.local` — API + DB on :8000 + `next dev` on host :80.
-   `make api-up-http` — API + DB only on :8000 (no PWA container).
-   `make prod-up` — prod overlay; requires `APP_SECRET`, `CADDY_MERCURE_JWT_SECRET`, `POSTGRES_PASSWORD`.

API / PHP:

-   `make composer c='…'` — run composer inside the container (e.g. `c='req vendor/pkg'`).
-   `make sf c='…'` — Symfony console; `make cc` for cache:clear, `make routes` for debug:router.
-   `make php.test` = `php.unit` (PHPUnit) + `php.behat` (Behat). Pass extra args with `c=`, e.g. `make php.unit c='--filter SomeTest'`.
-   `make php.lint` = full sweep (PHPStan, Rector, PHP-CS-Fixer, PHPMD, PHPCS, Psalm auto-fixes). Individual: `php.stan`, `php.rector[.dry-run]`, `php.cs-fixer[.dry-run]`, `php.md`, `php.cs[.dry-run]`, `php.psalm`, `php.psalm.taint`, `php.psalm.baseline`, `composer.checks`. CI-fast variant: `ci.php.lint` (skips PHPStan).
-   DB (Doctrine): `db.migrate`, `db.diff`, `db.status`, `db.validate`, `db.load.fixtures` (Hautelook Alice), `db.reset` (destructive: drop → migrate → fixtures), `db.shell` (psql).
-   Xdebug: `xdebug.enable`, `xdebug.disable`, `xdebug.status`.

PWA / JS:

-   `make pwa.install`, `make pwa.dev` (Next dev with Turbopack on :80), `make pwa.build`.
-   `make pwa.test` = `pwa.test.unit` (Vitest) + `pwa.test.e2e` (Playwright). Single file: `make pwa.test.unit c='path/to/file.test.ts'`. `make pwa.test.unit.watch` for watch mode. `make pwa.test.e2e.reports` opens the Playwright report.
-   `make pwa.lint` = ESLint + Prettier check; fixers: `pwa.lint.eslint.fix`, `pwa.format.prettier.fix`.

Aggregates: `make lint`, `make test`, `make ci` (`ci.lint` + `ci.test`), `make ci.api`, `make ci.pwa`. SuperLinter via Docker: `make super-lint` (requires `GITHUB_TOKEN`); `super-lint.quick` for changed files only.

## Conventions enforced in this repo

Both sides follow **DDD + Hexagonal / Clean Architecture**, with dependencies pointing inward toward the domain. This is load-bearing — do not add framework imports (Symfony, Next, Inversify, HTTP clients, ORM) inside `Domain/`; put adapters in `Infrastructure/` and orchestration in `Application/`. The full rule set lives in `.cursor/rules/*.mdc` (architecture, clean-code, database, frontend, php-standards, security, solid-principles, testing) and `pwa/AGENTS.md` — consult them before non-trivial changes.

## Docs to consult

-   [`docs/index.md`](docs/index.md) — generated documentation index.
-   [`docs/integration-architecture.md`](docs/integration-architecture.md) — how FrankenPHP / Next / Symfony share `localhost`.
-   [`docs/architecture-api.md`](docs/architecture-api.md) — API layering, domain events, Messenger (`messenger_worker`), audit table.
-   [`docs/architecture-pwa.md`](docs/architecture-pwa.md) — PWA layering and module boundaries.
-   [`docs/deployment-guide.md`](docs/deployment-guide.md) and [`pwa/docs/production-deployment.md`](pwa/docs/production-deployment.md) — prod Compose, mailer, DNS, CORS, Mercure, smoke tests.
-   [`docs/development-guide-api.md`](docs/development-guide-api.md), [`docs/development-guide-pwa.md`](docs/development-guide-pwa.md) — day-to-day workflows.
-   [`docs/contribution-guide.md`](docs/contribution-guide.md), [`docs/source-tree-analysis.md`](docs/source-tree-analysis.md).
-   [`api/README.md`](api/README.md), [`api/docs/`](api/docs/), [`pwa/README.md`](pwa/README.md) — deployable-specific details.
