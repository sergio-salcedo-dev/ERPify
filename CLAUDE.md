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
-   `make php.lint` = full sweep (PHPStan, Rector, PHP-CS-Fixer, PHPMD, PHPCS, Psalm auto-fixes). Individual: `php.stan`, `php.rector[.dry-run]`, `php.cs-fixer[.dry-run]`, `php.md`, `php.cs[.dry-run]`, `php.psalm`, `php.psalm.taint`, `php.psalm.baseline`, `composer.checks`.
-   DB (Doctrine): `db.migrate`, `db.diff`, `db.status`, `db.validate`, `db.load.fixtures` (Hautelook Alice), `db.reset` (destructive: drop → migrate → fixtures), `db.shell` (psql).
-   Xdebug: `xdebug.enable`, `xdebug.disable`, `xdebug.status`.

PWA / JS:

-   `make pwa.install`, `make pwa.dev` (Next dev with Turbopack on :80), `make pwa.build`.
-   `make pwa.test` = `pwa.test.unit` (Vitest) + `pwa.test.e2e` (Playwright). Single file: `make pwa.test.unit c='path/to/file.test.ts'`. `make pwa.test.unit.watch` for watch mode. `make pwa.test.e2e.reports` opens the Playwright report.
-   `make pwa.lint` = ESLint + Prettier check; fixers: `pwa.lint.eslint.fix`, `pwa.format.prettier.fix`.

Aggregates: `make lint`, `make test`, `make ci` (`ci.lint` + `ci.test`), `make ci.api`, `make ci.pwa`. SuperLinter via Docker: `make super-lint` (requires `GITHUB_TOKEN`); `super-lint.quick` for changed files only.

## Conventions enforced in this repo

Both sides follow **DDD + Hexagonal / Clean Architecture**, with dependencies pointing inward toward the domain. This is load-bearing — do not add framework imports (Symfony, Next, Inversify, HTTP clients, ORM) inside `Domain/`; put adapters in `Infrastructure/` and orchestration in `Application/`. The full rule set lives in `.cursor/rules/*.mdc` (architecture, clean-code, database, frontend, php-standards, security, solid-principles, testing) and `pwa/AGENTS.md` — consult them before non-trivial changes.

**Required checks after editing PHP:** run `make php.stan` on every PHP file you changed before declaring the task 
done. Fix any reported issues. At the end of the task, also run `make php.lint` and fix anything it reports but no 
phpstan errors.

## Docs to consult

-   [`docs/index.md`](docs/index.md) — generated documentation index.
-   [`docs/integration-architecture.md`](docs/integration-architecture.md) — how FrankenPHP / Next / Symfony share `localhost`.
-   [`docs/architecture-api.md`](docs/architecture-api.md) — API layering, domain events, Messenger (`messenger_worker`), audit table.
-   [`docs/architecture-pwa.md`](docs/architecture-pwa.md) — PWA layering and module boundaries.
-   [`docs/deployment-guide.md`](docs/deployment-guide.md) and [`pwa/docs/production-deployment.md`](pwa/docs/production-deployment.md) — prod Compose, mailer, DNS, CORS, Mercure, smoke tests.
-   [`docs/development-guide-api.md`](docs/development-guide-api.md), [`docs/development-guide-pwa.md`](docs/development-guide-pwa.md) — day-to-day workflows.
-   [`docs/contribution-guide.md`](docs/contribution-guide.md), [`docs/source-tree-analysis.md`](docs/source-tree-analysis.md).
-   [`api/README.md`](api/README.md), [`api/docs/`](api/docs/), [`pwa/README.md`](pwa/README.md), [`pwa/docs/`](pwa/docs/) — deployable-specific details.

## Working principles

1. Don't assume. Don't hide confusion. Surface tradeoffs.
2. Minimum code that solves the problem. Nothing speculative.
3. Touch only what you must. Clean up only your own mess.
4. Define success criteria. Loop until verified.

---

## Parallelizing work with subagents

When a task decomposes into independent subtasks (different bounded contexts, different files, no shared state), spawn parallel subagents rather than working sequentially. Each subagent must receive a self-contained prompt with full context.

Example pattern: plan → subagent A (API: domain entity + Doctrine mapping + migration in `api/`) + subagent B (PWA: route + component + Inversify wiring in `pwa/`) running in parallel → verify each (`make php.stan`, `make pwa.lint`) → commit.

Do not spawn subagents for tasks that share state mid-flight (e.g. two agents editing the same migration, the same `services.yaml`, the same Inversify container module, or both touching `Shared/`).

---

## Conventions

### Branch naming

| Type    | Format                  | Base                    |
|---------|-------------------------|-------------------------|
| Feature | `feat/<scope>-<slug>`   | `main`                  |
| Fix     | `fix/<scope>-<slug>`    | `main`                  |
| Hotfix  | `hotfix/<scope>-<slug>` | latest production tag   |
| Chore   | `chore/<slug>`          | `main`                  |
| Docs    | `docs/<slug>`           | `main`                  |
| CI      | `ci/<slug>`             | `main`                  |

`<scope>` is `api`, `pwa`, or a bounded context (`backoffice`, `frontoffice`, `shared`). Keep branches short-lived; rebase onto base rather than merging it back in.

### Commit messages

[Conventional Commits](https://www.conventionalcommits.org/):

`<type>(<scope>): <subject>` — subject lower-case, imperative, no trailing period.

Types: `feat | fix | docs | style | refactor | perf | test | build | ci | chore | revert`. Scope is typically `api`, `pwa`, or a bounded context.

[//]: # (### Pre-commit hook — enforce commit messages by commitlint via pre-commit)

[//]: # ()
[//]: # (Setup once:)

[//]: # ()
[//]: # (```bash)

[//]: # (pip install pre-commit)

[//]: # (pre-commit install)

[//]: # (pre-commit install --hook-type commit-msg)

[//]: # (detect-secrets scan > .secrets.baseline)

[//]: # (```)

[//]: # ()
[//]: # (Runs on every commit: trailing whitespace, EOF fixer, YAML/JSON/TOML validation, merge-conflict/case-conflict/mixed-line-ending/private-key/AWS-credential/secret detection, Conventional Commit validation, PHP syntax checks. Hooks that shell into the `php`/`pwa` containers &#40;e.g. `make php.lint`, `make pwa.lint`&#41; require the stack to be running — start it with `make docker.up` if it isn't. If a hook fails, fix the underlying issue, re-stage, and create a **new** commit. Never `--amend` after a hook failure. Never `--no-verify` without explicit authorization.)

### Do not touch

- `api/vendor/` — Composer-managed, never edit manually
- `pwa/node_modules/` — npm-managed
- `api/var/` — Symfony runtime cache and logs, never commit
- `api/migrations/` — generate only via `make db.diff`, never hand-edit. You may only edit a migration that was created on the current feature branch. Once a migration is merged into `main` it is immutable — create a new migration instead.

[//]: # (- `compose.yaml` base-image digests and `replace`d polyfills in `api/composer.json` — Dependabot owns those bumps.)

### Keeping docs up to date

Update the matching file as part of any PR that changes:

- **New Make targets or commands** → this file (`CLAUDE.md`), the relevant `make/*.mk` module, and [`docs/development-guide-api.md`](docs/development-guide-api.md) / [`docs/development-guide-pwa.md`](docs/development-guide-pwa.md) when the workflow surface changes
- **New `src/` directories or renamed ones** → this file (`CLAUDE.md`), [`docs/architecture-api.md`](docs/architecture-api.md) or [`docs/architecture-pwa.md`](docs/architecture-pwa.md), and [`docs/source-tree-analysis.md`](docs/source-tree-analysis.md)
- **Architecture decisions** → [`docs/architecture-api.md`](docs/architecture-api.md) / [`docs/architecture-pwa.md`](docs/architecture-pwa.md), and [`docs/integration-architecture.md`](docs/integration-architecture.md) when cross-deployable
- **Domain events / Messenger transports** → [`docs/architecture-api.md`](docs/architecture-api.md) (and any `docs/domain-events-and-messenger.md` referenced from it)
- **API endpoints, controllers, or response shapes** → [`api/docs/`](api/docs/) and [`docs/architecture-api.md`](docs/architecture-api.md)
- **PWA module boundaries / Inversify bindings** → [`pwa/docs/`](pwa/docs/) and [`docs/architecture-pwa.md`](docs/architecture-pwa.md)
- **Deployment / Compose / CORS / Mercure / mailer** → [`docs/deployment-guide.md`](docs/deployment-guide.md) and [`pwa/docs/production-deployment.md`](pwa/docs/production-deployment.md)
- **Security-sensitive change** → `PRODUCTION_SECURITY_CHECKLIST.md` (authoritative — see `.cursor/rules/security.mdc`)

When a rule here conflicts with `.cursor/rules/*.mdc`, [`api/CLAUDE.md`](api/CLAUDE.md), [`pwa/CLAUDE.md`](pwa/CLAUDE.md), or `pwa/AGENTS.md`, flag the conflict rather than silently picking one.
