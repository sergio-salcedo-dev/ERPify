# Claude Code — quick reference

Long-form reference for Claude Code. The root [`../CLAUDE.md`](../CLAUDE.md) keeps the load-bearing rules and a top-hits cheat sheet; this file holds the full command catalog, layout tables, "adding new code" recipes, and gotchas. `make help` is the source of truth (canonical list grouped by section); this file is curated for readability.

---

## Full command catalog

The root `Makefile` is the canonical interface, including the modules in `make/*.mk`. **Prefer `make` targets** over invoking `docker compose`, `composer`, `npm`, or linters directly: targets decide whether to exec inside the `php`/`pwa` container based on `ENV` (`dev`, `ci`, `staging`, `prod`) and `IN_CONTAINER`. **Always invoke from the repo root.**

### Stack

```bash
make app.dev             # Full dev stack (down → install → up --wait → fix ownership)
make docker.up           # Start stack detached, rebuild images (ENV=dev|staging|prod).
make docker.up.wait      # Same, with --wait health gate.
make docker.down         # Stop stack and remove orphans.
make docker.logs         # Follow compose logs (all services).
make docker.ps           # Compose ps.
make docker.info         # Show this checkout's resolved stack identity (project + host ports).
make php.bash            # Shell into the php container (also: make php.sh, make php.exec cmd='…').
make docker.down.clean-volumes  # Stop stack and REMOVE volumes (destructive).
make docker.prune        # Prune ALL Docker images/volumes/containers system-wide (destructive).
make prod.env.check      # Validate .env.prod.local has all required prod secrets (no placeholders).
make deploy.local        # Stand up the PROD profile at https://erpify.local (preflight → up → migrate → smoke → CA export + trust guidance).
sudo make deploy.local.trust  # Privileged client-trust steps (hosts + system CA + Chromium NSS); targets $SUDO_USER. Don't `sudo make deploy.local`.
make backup.prod         # Paired prod backup: pg_dump + object-storage archive (BACKUP_DIR, RETENTION_DAYS, BACKUP_SYNC_CMD). Runbook: docs/vps-deployment.md § Backups.
```

Prod/staging load secrets from a gitignored root `.env.prod.local` (copy from [`.env.prod.example`](../.env.prod.example)) via `--env-file`. Runbook: [`erpify-local-test-deployment.md`](erpify-local-test-deployment.md); security gate: [`../PRODUCTION_SECURITY_CHECKLIST.md`](../PRODUCTION_SECURITY_CHECKLIST.md).

### API / PHP

```bash
make composer c='req vendor/pkg'    # Run composer inside the container.
make sf c='about'                   # Symfony console (also: make sf.cc, make sf.routes f='…', make sf.about).
make php.test                       # PHPUnit + Behat. Pass c='…' for extra args.
make php.unit c='--filter SomeTest' # PHPUnit only.
make php.behat c='features/...'     # Behat only.
make php.bench                      # Opt-in performance-budget benchmarks (default php.unit skips).
make php.quality                    # Full sweep: PHPStan, Rector, PHP-CS-Fixer, PHPMD, PHPCS.
make php.stan                       # PHPStan only — REQUIRED on every PHP file you change.
make composer.update                # Safe dependency update (within composer.json ranges).
make composer.upgrade               # Force-upgrade direct deps to latest (bumps constraints across majors).
make db.migrate                     # Run pending Doctrine migrations.
make db.diff                        # Generate migration from entity/schema diff.
make db.status                      # Migration status.
make db.validate                    # Validate ORM mapping against the database.
make db.load.fixtures               # Load Hautelook Alice fixtures.
make db.reset                       # Drop → migrate → fixtures (destructive).
make db.shell                       # Interactive psql (CLI, any ENV — uses docker exec, no host port).
make db.tunnel                      # Expose prod/staging DB on 127.0.0.1:15432 for a GUI client (pre-prod only; db.tunnel.stop to remove).
make xdebug.enable                  # Toggle Xdebug in api/.env (also xdebug.disable, xdebug.status).
```

Individual linters: `php.rector[.dry-run]`, `php.cs-fixer[.dry-run]`, `php.md`, `php.cs[.dry-run]`, `php.psalm.taint` (Psalm security dataflow → SARIF, `api-taint` CI job), `composer.check.all`. Error-contract drift gate: `php.lint.error-contract` (FR50/FR51/NFR26). Bounded-context isolation gate: `php.lint.bounded-context` (Level 1 cross-context `Domain`/`Application`/`Infrastructure` import fails; Level 2 cross-context FK warns; seams in `api/.bounded-context-allowlist`).

### PWA / JS

```bash
make pwa.install                    # npm ci in pwa/.
make pwa.update                     # Safe dependency update (within semver ranges).
make pwa.upgrade                    # Force-upgrade all deps to latest (npm-check-updates).
make pwa.dev                        # Next dev server (Turbopack) on host :80 (needs pwa/.env.local).
make pwa.production.build           # Production build.
make pwa.production.start           # Serve the production build on :80.
make pwa.test                       # Vitest + Playwright.
make pwa.test.unit c='path/to.test' # Vitest single file.
make pwa.test.unit.watch            # Vitest watch mode.
make pwa.test.e2e                   # Playwright. Sharded: CI_SHARD=N CI_TOTAL_SHARDS=M make pwa.test.e2e.
make pwa.test.e2e.reports           # Open the Playwright HTML report.
make pwa.lint                       # ESLint --fix.
make pwa.format                     # Prettier --write.
make pwa.quality.dry-run            # ESLint + Prettier check (no writes).
```

### Aggregates and CI

```bash
make app.quality                    # All linters (PHP + PWA).
make app.test                       # All tests (PHP + PWA).
make app.update                     # Safe dep update for API + PWA (within constraints).
make app.upgrade                    # Force upgrade API + PWA deps to latest (across majors).
make ci                             # Full CI (ci.quality + ci.test).
make ci.api                         # API only: lint + tests.
make ci.pwa                         # PWA only: lint + unit + build (no E2E).
make super-lint.full                # SuperLinter over the whole repo (requires GITHUB_TOKEN).
make super-lint.fast                # SuperLinter on changed files only (faster).
make super-lint.slim                # SuperLinter on changed files only (slim image).
```

**Always start the stack with `make app.dev` or `make docker.up`.** Bare `docker compose up -d` skips composer install on cold checkouts and the `pwa.install.if-missing` guard, leaving the PWA container without dependencies.

---

## Services

| Service            | Image / Build                        | Port (host)                          | Purpose                                      |
|--------------------|--------------------------------------|--------------------------------------|----------------------------------------------|
| `php`              | `./api` (FrankenPHP worker)          | `:80` / `:443` / `:443/udp` (HTTP/3) | Symfony API + reverse proxy to PWA + Mercure |
| `pwa`              | `./pwa` (Next.js 16)                 | internal `:3000`                     | App Router HTML, served via FrankenPHP       |
| `database`         | `postgres:18-alpine` (sha256-pinned) | internal `:5432`                     | Main app DB                                  |
| `messenger_worker` | reuses `php` image                   | —                                    | Async Symfony Messenger consumer             |

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

| What                                                                         | Where                                                                    |
|------------------------------------------------------------------------------|--------------------------------------------------------------------------|
| Symfony kernel                                                               | `api/src/Kernel.php`                                                     |
| Bounded contexts (DDD)                                                       | `api/src/{Backoffice,Frontoffice,Shared}/<Module>/`                      |
| Domain layer (entities, VOs, ports)                                          | `<Module>/Domain/`                                                       |
| Application layer (use cases, DTOs)                                          | `<Module>/Application/`                                                  |
| Infrastructure (Doctrine, controllers, Messenger handlers, mailers, clients) | `<Module>/Infrastructure/`                                               |
| Cross-cutting kernel                                                         | `api/src/Shared/`                                                        |
| Shared search-filter plumbing (applier + per-repo field maps)                | `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/`             |
| Symfony config (services, routes, packages, Messenger transports)            | `api/config/`                                                            |
| Doctrine migrations                                                          | `api/migrations/` (generate via `make db.diff`, never edit applied ones) |
| Test fixtures (Hautelook Alice)                                              | `api/tests/DataFixtures/` and `api/tests/Fixtures/`                      |
| Unit tests                                                                   | `api/tests/Unit/`                                                        |
| Functional tests                                                             | `api/tests/Functional/`                                                  |
| Behat contexts                                                               | `api/tests/Behat/`                                                       |
| Behat features                                                               | `api/features/`                                                          |
| Performance budgets (opt-in)                                                 | `api/tests/Bench/`                                                       |
| Isolated tooling Composer installs                                           | `api/tools/{phpunit,behat,…}/`                                           |
| FrankenPHP Caddyfile + worker entry                                          | `api/frankenphp/`                                                        |

### `pwa/`

| What                       | Where                                                                    |
|----------------------------|--------------------------------------------------------------------------|
| Next.js App Router         | `pwa/src/app/`                                                           |
| Bounded contexts (DDD)     | `pwa/src/context/<bounded-context>/{domain,application,infrastructure}/` |
| Cross-cutting kernel       | `pwa/src/context/shared/`                                                |
| Reusable UI (Shadcn-based) | `pwa/src/components/`                                                    |
| Framework glue             | `pwa/src/lib/`                                                           |
| Unit tests (Vitest)        | `pwa/tests/` (mirrors `src/`)                                            |
| E2E tests (Playwright)     | `pwa/tests/e2e/`                                                         |

For a deeper, machine-generated tree, see [`source-tree-analysis.md`](source-tree-analysis.md).

---

## Adding new code — quick patterns

### New API endpoint

1. Domain: entities, value objects, repository interfaces in `<Module>/Domain/`. **No** Symfony/Doctrine/HTTP imports here.
2. Application: command/query + handler in `<Module>/Application/`. Use ports defined in `Domain/`.
3. Infrastructure: HTTP controller, Doctrine repository implementation, persistence mapping in `<Module>/Infrastructure/`.
4. Wire services in `api/config/services.yaml` (or per-module config) — autoconfigure handles most cases.
5. Schema changes: `make db.diff` → review the file in `api/migrations/` → `make db.migrate`.
6. Test: unit tests for domain/application in `api/tests/Unit/`; HTTP behaviour in a Behat scenario under `api/features/`.
7. Run `make php.stan` on every PHP file you changed; then `make php.quality` at the end.

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
7. Run `make pwa.quality` at the end.

### New Doctrine migration

`make db.diff` — review the generated file under `api/migrations/`. Never hand-edit an applied migration. You may only edit a migration created on the current feature branch; once merged into `main` it is immutable — generate a new one instead.

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
- **Prod boot requires secrets** — `APP_SECRET`, `CADDY_MERCURE_JWT_SECRET`, `POSTGRES_PASSWORD`. The prod overlay fails non-obviously if any are missing.
- **First boot is slow.** FrankenPHP's healthcheck has a 120s `start_period` because the entrypoint runs composer install (cold), waits for the DB, runs migrations, then starts the worker. Don't kill it early.
- **Port collisions on `:80` / `:443` / `:8000`** surface as a non-obvious Compose error. Free the port or override `HTTP_PORT` / `HTTPS_PORT`.
- **One stack per worktree, not one stack total.** `make/config.mk` derives `COMPOSE_PROJECT_NAME` from the checkout: the primary checkout keeps `erpify`; a linked worktree under `.claude/worktrees/` gets `erpify-<dir-slug>`. So each worktree's containers/networks/volumes are isolated and several stacks can run at once. Linked-worktree stacks publish host ports **ephemerally** (random free ports, not the fixed `80/443/15432/8025`) so they never collide — they are meant for `make php.*` / `make pwa.quality` / Behat (which exec into the container or use the internal network), not for browsing the UI. Browse from the primary checkout, run `make docker.info` to see a checkout's resolved project + ports, or opt a worktree run into a fixed, non-colliding port on demand — every `*_PORT` is `?=`, so a value you pass wins over the ephemeral `0`: `HTTPS_PORT=8443 make docker.up` (from inside the worktree; pick any free port ≠ main's `443`), then open `https://localhost:8443`. `HTTPS_PORT` also feeds `DEFAULT_URI` / `MERCURE_PUBLIC_URL`, so internal URLs stay consistent; set additional `*_PORT` vars (`HTTP_PORT`, `MAILPIT_UI_PORT`, `POSTGRES_PORT`, …) the same way for those surfaces. Per-worktree deterministic port offsets were deliberately **declined** — reach for this override, not new tooling. CI is unaffected (it runs in the primary checkout → `erpify`, fixed ports).
  - **Creating a worktree — `make worktree.create BRANCH=<branch>`.** Adds a linked worktree under `.claude/worktrees/` on a *new* branch off `BASE=` (default `main`). A random 4-char suffix is appended to **both** the branch and the dir slug, so the branch, dir, and its `erpify-<slug>` Compose project are always unique — `feat/foo` and `fix/foo` can coexist and re-running never collides. `NAME=<dir-base>` overrides the derived dir slug (still suffixed); `START=true` brings the new stack up via `make app.dev` (the sub-make builds its *own* `erpify-<slug>` project — `config.mk` hands the project name to compose via `-p` and doesn't export it, so a nested `make` re-derives its own), otherwise it just prints the `cd … && make app.dev` next step. After checkout the recipe seeds the worktree's `.claude/skills/` with the `bmad-*` skills from its own tracked `.agent/skills/` copy — `.claude/skills/bmad-*/` is gitignored, so without the seed `/bmad-*` slash commands are "Unknown command" inside the worktree (a worktree created with bare `git worktree add` still needs the manual `cp -a .agent/skills/bmad-*/ .claude/skills/`). Like the removal targets it is **local only** — `git worktree add -b` and a local branch, nothing pushed.
  - **Tearing worktrees down — `make worktree.remove NAME=<dir>` / `make worktree.remove-all`.** `worktree.remove` takes down the worktree's isolated `erpify-<slug>` stack + volumes, removes the worktree, prunes stale metadata, then deletes the branch it held; `worktree.remove-all` does the same for every linked worktree. Both are **local only** — they run `git worktree remove` + `git branch -d/-D`, never a remote deletion. `FORCE=true` discards a dirty worktree and force-deletes a not-fully-merged branch (a squash-merged branch reads as unmerged to git, so the common merged-PR cleanup needs `FORCE=true`). `make worktree.list` prints the `NAME` values. The teardown sub-make targets the *worktree's* `erpify-<slug>` project, not the caller's: `config.mk` hands `COMPOSE_PROJECT_NAME` to compose via `-p` and does **not** export it, so a nested `make` re-derives its own project instead of inheriting an env value its `?=` couldn't override. Degraded states are handled: if the worktree's dir was deleted out-of-band (sub-make teardown impossible), the recipe re-derives `erpify-<slug>` from the dir basename and runs `docker compose -p erpify-<slug> down --volumes` from the main checkout so the stack doesn't leak; and if no worktree matches but `NAME` is a local branch (a half-finished removal kept the squash-merged branch), it deletes just that branch — so the `re-run with FORCE=true` hint keeps working after the worktree entry is gone. A removal that fails with `Permission denied` means the worktree's containers wrote bind-mounted files as `root` (`pwa/.next`, `node_modules`, `api/var`, …): run `make worktree.chown` (sudo, dev/test only; mirrors `pwa.chown.next`, reclaiming the whole `.claude/worktrees/` folder at once — stale dirs git no longer tracks included) and retry the removal.
- **FrankenPHP file watchers stay scoped away from `var/`.** Dev watch surfaces are scoped on purpose: the worker `watch` and the site `hot_reload` defaults in `compose.dev.yaml` target `src/` (+ `config/` for hot_reload). A bare `watch`/`hot_reload` recursively watches all of `/app/api`, and the `var/cache/test/` rename storm during in-container test runs crashes the watcher, making frankenphp (PID 1) core-dump (~1 GB into the bind-mounted `api/`) and restart. Belt-and-braces: the `php`/`messenger_worker` services set `ulimits.core: 0` in `compose.yaml` and `api/.gitignore` covers `/core.*`. If you customize `FRANKENPHP_WORKER_CONFIG` / `FRANKENPHP_SITE_CONFIG`, keep the paths scoped (and note compose's `${VAR-default}` ends at the first `}` — no brace-globs in the defaults).
- **API tests services config must be YAML** (`api/config/services_test.yaml`) — never `services_test.php`. Symfony's test kernel only loads the YAML variant.
- **Rector silently privatizes `protected` methods on `final` classes** during `make php.quality`. If you intentionally leave a method `protected` on a `final` class, expect it to be rewritten — refactor the design or drop `final`.
- **PHP multi-line `if`/`while`/`match` formatting** — newline after the opening `(`; do not put the first operand on the same line as the keyword. PHP-CS-Fixer enforces this.
