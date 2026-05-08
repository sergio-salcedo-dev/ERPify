# Claude Code — quick reference

Long-form reference content for Claude Code. The root [`../CLAUDE.md`](../CLAUDE.md) keeps the load-bearing rules and a top-hits command cheat sheet; this file holds the full command catalog, layout tables, "adding new code" recipes, and gotchas. Run `make help` for the canonical list grouped by section — this file is curated for readability, `make help` is the source of truth.

---

## Full command catalog

The root `Makefile` is the canonical interface — it includes the modules in `make/*.mk`. **Prefer `make` targets** over invoking `docker compose`, `composer`, `npm`, or linters directly; the targets decide whether to exec inside the `php`/`pwa` container based on `ENV` (`dev`, `ci`, `staging`, `prod`) and `IN_CONTAINER`. **Always invoke from the repo root.**

### Stack

```bash
make dev                 # Full dev stack (--wait --build -d) + open browser. OPEN_BROWSER=0 to skip.
make docker.up           # Start stack detached, rebuild images (ENV=dev|staging|prod).
make docker.up.wait      # Same, with --wait health gate.
make docker.down         # Stop stack and remove orphans.
make docker.logs         # Follow compose logs (all services).
make docker.ps           # Compose ps.
make docker.health       # GET HEALTH_URL, require HTTP 200 + JSON status ok.
make docker.bash         # Shell into the php container.
make docker.clean        # Stop stack and REMOVE volumes (destructive).
make dev.local           # API + DB on :8000 + Next dev on host :80 (needs pwa/.env.local).
make api-up-http         # API + DB only on :8000 (no PWA container).
make prod-up             # Production overlay; requires APP_SECRET, CADDY_MERCURE_JWT_SECRET, POSTGRES_PASSWORD.
```

### API / PHP

```bash
make composer c='req vendor/pkg'    # Run composer inside the container.
make sf c='about'                   # Symfony console (also: make cc, make routes f='…').
make php.test                       # PHPUnit + Behat. Pass c='…' for extra args.
make php.unit c='--filter SomeTest' # PHPUnit only.
make php.behat c='features/...'     # Behat only.
make php.bench                      # Opt-in performance-budget benchmarks (NFR2; default php.unit skips).
make php.lint                       # Full sweep: PHPStan, Rector, PHP-CS-Fixer, PHPMD, PHPCS, Psalm.
make php.stan                       # PHPStan only — REQUIRED on every PHP file you change.
make db.migrate                     # Run pending Doctrine migrations.
make db.diff                        # Generate migration from entity/schema diff.
make db.status                      # Migration status.
make db.validate                    # Validate ORM mapping against the database.
make db.load.fixtures               # Load Hautelook Alice fixtures.
make db.reset                       # Drop → migrate → fixtures (destructive).
make db.shell                       # Interactive psql.
make xdebug.enable                  # Toggle Xdebug in api/.env (also xdebug.disable, xdebug.status).
```

Individual linters: `php.rector[.dry-run]`, `php.cs-fixer[.dry-run]`, `php.md`, `php.cs[.dry-run]`, `php.psalm`, `php.psalm.taint`, `php.psalm.baseline`, `composer.checks`. Error-contract drift gate: `php.lint.error-contract` (FR50/FR51/NFR26).

### PWA / JS

```bash
make pwa.install                    # npm ci in pwa/.
make pwa.dev                        # Next dev (Turbopack, host :80) — pair with make api-up-http.
make pwa.build                      # Production build.
make pwa.test                       # Vitest + Playwright.
make pwa.test.unit c='path/to.test' # Vitest single file.
make pwa.test.unit.watch            # Vitest watch mode.
make pwa.test.e2e                   # Playwright. Sharded: CI_SHARD=N CI_TOTAL_SHARDS=M make pwa.test.e2e.
make pwa.test.e2e.reports           # Open the Playwright HTML report.
make pwa.lint                       # ESLint + Prettier check.
make pwa.lint.eslint.fix            # ESLint --fix.
make pwa.format.prettier.fix        # Prettier --write.
```

### Aggregates and CI

```bash
make lint                           # All linters (PHP + PWA).
make test                           # All tests (PHP + PWA).
make ci                             # Full CI (ci.lint + ci.test).
make ci.api                         # API only: lint + tests.
make ci.pwa                         # PWA only: lint + unit + build (no E2E).
make super-lint                     # SuperLinter via Docker (requires GITHUB_TOKEN).
make super-lint.quick               # SuperLinter on changed files only.
```

**Always start the stack with `make dev` or `make docker.up`.** Bare `docker compose up -d` skips composer install on cold checkouts and the `pwa.install.if-missing` guard, leaving the PWA container without dependencies.

---

## Services

| Service             | Image / Build                | Port (host)                          | Purpose                                       |
|---------------------|------------------------------|--------------------------------------|-----------------------------------------------|
| `php`               | `./api` (FrankenPHP worker)  | `:80` / `:443` / `:443/udp` (HTTP/3) | Symfony API + reverse proxy to PWA + Mercure  |
| `pwa`               | `./pwa` (Next.js 16)         | internal `:3000`                     | App Router HTML, served via FrankenPHP        |
| `database`          | `postgres:18-alpine` (sha256-pinned) | internal `:5432`             | Main app DB                                   |
| `messenger_worker`  | reuses `php` image           | —                                    | Async Symfony Messenger consumer              |

Compose base images are sha256-pinned; Dependabot tracks digest bumps. `compose.yaml` + `compose.dev.yaml` / `compose.prod.yaml` overlays live at the repo root.

---

## Repository layout

### Root

```
api/            Symfony HTTP API (see api/CLAUDE.md)
pwa/            Next.js PWA (see pwa/CLAUDE.md)
compose.yaml    Base Compose; overlays compose.dev.yaml / compose.prod.yaml
make/           Makefile modules (api.mk, db.mk, php-*.mk, pwa.mk, ci.mk, …)
docs/           Cross-deployable docs (architecture, integration, deployment, guides)
scripts/        Utility scripts
```

### `api/`

| What                                    | Where                                                        |
|-----------------------------------------|--------------------------------------------------------------|
| Symfony kernel                          | `api/src/Kernel.php`                                         |
| Bounded contexts (DDD)                  | `api/src/{Backoffice,Frontoffice,Shared}/<Module>/`          |
| Domain layer (entities, VOs, ports)     | `<Module>/Domain/`                                           |
| Application layer (use cases, DTOs)     | `<Module>/Application/`                                      |
| Infrastructure (Doctrine, controllers, Messenger handlers, mailers, clients) | `<Module>/Infrastructure/` |
| Cross-cutting kernel                    | `api/src/Shared/`                                            |
| Symfony config (services, routes, packages, Messenger transports) | `api/config/`              |
| Doctrine migrations                     | `api/migrations/` (generate via `make db.diff`, never edit applied ones) |
| Test fixtures (Hautelook Alice)         | `api/tests/DataFixtures/` and `api/tests/Fixtures/`          |
| Unit tests                              | `api/tests/Unit/`                                            |
| Functional tests                        | `api/tests/Functional/`                                      |
| Behat contexts                          | `api/tests/Behat/`                                           |
| Behat features                          | `api/features/`                                              |
| Performance budgets (opt-in)            | `api/tests/Bench/`                                           |
| Isolated tooling Composer installs      | `api/tools/{phpunit,behat,…}/`                               |
| FrankenPHP Caddyfile + worker entry     | `api/frankenphp/`                                            |

### `pwa/`

| What                                    | Where                                                        |
|-----------------------------------------|--------------------------------------------------------------|
| Next.js App Router                      | `pwa/src/app/`                                               |
| Bounded contexts (DDD)                  | `pwa/src/context/<bounded-context>/{domain,application,infrastructure}/` |
| Cross-cutting kernel                    | `pwa/src/context/shared/`                                    |
| Reusable UI (Shadcn-based)              | `pwa/src/components/`                                        |
| Framework glue                          | `pwa/src/lib/`                                               |
| Unit tests (Vitest)                     | `pwa/tests/` (mirrors `src/`)                                |
| E2E tests (Playwright)                  | `pwa/tests/e2e/`                                             |

For a deeper, machine-generated tree, see [`source-tree-analysis.md`](source-tree-analysis.md).

---

## Adding new code — quick patterns

### New API endpoint

1. Domain: model entities, value objects, and repository interfaces in `<Module>/Domain/`. **No** Symfony/Doctrine/HTTP imports here.
2. Application: command/query + handler in `<Module>/Application/`. Use ports defined in `Domain/`.
3. Infrastructure: HTTP controller, Doctrine repository implementation, persistence mapping in `<Module>/Infrastructure/`.
4. Wire services in `api/config/services.yaml` (or per-module config) — autoconfigure handles most cases.
5. Schema changes: `make db.diff` → review the file in `api/migrations/` → `make db.migrate`.
6. Test: unit tests for domain/application in `api/tests/Unit/`; HTTP behaviour in a Behat scenario under `api/features/`.
7. Run `make php.stan` on every PHP file you changed; then `make php.lint` at the end.

See [`../api/docs/adding-endpoints.md`](../api/docs/adding-endpoints.md) for the search-endpoint walkthrough.

### New async job

1. Define a Messenger message DTO under `<Module>/Application/` (or `Infrastructure/Messaging/`).
2. Add the handler in `<Module>/Infrastructure/Messaging/` — keep handlers thin; delegate to an Application service.
3. Route the message in `api/config/packages/messenger.yaml` if it needs a non-default transport.
4. Dispatch from an Application service via `MessageBusInterface`.
5. Audit / domain-event flow (when applicable): see [`architecture-api.md`](architecture-api.md).

### New PWA route + component

1. Add the route under `pwa/src/app/<segment>/` (server component by default; mark `'use client'` only when needed).
2. Domain logic (use cases, ports, types) goes under `pwa/src/context/<bounded-context>/{domain,application}/`.
3. Adapters (HTTP clients, storage, framework glue) go under `<bounded-context>/infrastructure/`.
4. Inversify bindings live in the matching container module — keep `domain/` framework-free.
5. Reusable UI in `pwa/src/components/` (Shadcn + Tailwind, BEM class naming `block__element--modifier`).
6. Test: Vitest under `pwa/tests/` mirroring source; Playwright scenarios under `pwa/tests/e2e/`.
7. Run `make pwa.lint` at the end.

### New Doctrine migration

`make db.diff` — review the generated file under `api/migrations/`. Never hand-edit a migration that has been applied. You may only edit a migration that was created on the current feature branch; once merged into `main` it is immutable — generate a new migration instead.

---

## Testing layers

| Layer       | Tool                        | Command              | Scope                                          |
|-------------|-----------------------------|----------------------|------------------------------------------------|
| Unit (PHP)  | PHPUnit                     | `make php.unit`      | Domain + Application — isolated, no I/O        |
| Functional  | PHPUnit                     | `make php.unit`      | Repositories, integration with the kernel      |
| Acceptance  | Behat                       | `make php.behat`     | Full HTTP flows against a real DB (preferred)  |
| Performance | PHPUnit `--group benchmark` | `make php.bench`     | Opt-in budgets (NFR2); default suite skips     |
| Unit (PWA)  | Vitest                      | `make pwa.test.unit` | Domain + Application + components              |
| E2E         | Playwright                  | `make pwa.test.e2e`  | Cross-browser journeys against the live stack  |

Behat is **preferred** over PHPUnit functional tests for HTTP behaviour. Do not commit with failing tests. If a test is wrong, fix the test too — never skip or delete it to make red go away. The error-contract drift gate (`make php.lint.error-contract`) enforces FR50/FR51/NFR26 — do not bypass it.

---

## Known limitations / gotchas

- **Always run `make` from the repo root.** Compose, target paths, and `IN_CONTAINER` detection assume it. Bare `docker compose up -d` skips composer install on cold checkouts and the `pwa.install.if-missing` guard.
- **Prod boot requires secrets** — `APP_SECRET`, `CADDY_MERCURE_JWT_SECRET`, `POSTGRES_PASSWORD`. `make prod-up` fails non-obviously if any are missing.
- **`make dev.local` requires `pwa/.env.local`** with `NEXT_PUBLIC_SYMFONY_API_BASE_URL=http://localhost:8000` and `SYMFONY_INTERNAL_URL=http://localhost:8000`. Without these, the host Next dev server can't reach the API on `:8000`.
- **First boot is slow.** FrankenPHP's healthcheck has a 120s `start_period` because the entrypoint runs composer install (cold), waits for the DB, runs migrations, then starts the worker. Don't kill it early.
- **Port collisions on `:80` / `:443` / `:8000`** will surface as a non-obvious Compose error. Free the port or override `HTTP_PORT` / `HTTPS_PORT`.
- **API tests services config must be YAML** (`api/config/services_test.yaml`) — never `services_test.php`. Symfony's test kernel only loads the YAML variant.
- **Rector silently privatizes `protected` methods on `final` classes** during `make php.lint`. If you intentionally leave a method `protected` on a `final` class, expect it to be rewritten — refactor the design or drop `final`.
- **PHP multi-line `if`/`while`/`match` formatting** — newline after the opening `(`; do not put the first operand on the same line as the keyword. PHP-CS-Fixer enforces this.
