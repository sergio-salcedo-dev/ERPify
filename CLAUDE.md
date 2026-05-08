# ERPify — Claude Code instructions

Monorepo with two deployables sharing one Compose stack: a Symfony HTTP API on FrankenPHP and a Next.js PWA. Nested `CLAUDE.md` files ([`api/CLAUDE.md`](api/CLAUDE.md), [`pwa/CLAUDE.md`](pwa/CLAUDE.md)) auto-load inside their subtree — this file is the monorepo-wide baseline.

**Stack:** PHP 8.5 · Symfony 7 · FrankenPHP (Caddy embedded) · PostgreSQL 18 · Doctrine ORM · Symfony Messenger · Mercure · Next.js 16 (App Router) · TypeScript · Tailwind 4 · Inversify · Vitest · Playwright · PHPUnit · Behat

> Full command catalog, repo layout tables, "adding new code" recipes, and gotchas → [`docs/claude-code-quickref.md`](docs/claude-code-quickref.md). Run `make help` for the live target list.

---

## Top-hits commands

Always invoke from the repo root. Targets decide whether to exec inside the `php`/`pwa` container based on `ENV` and `IN_CONTAINER`.

```bash
make dev                            # Full dev stack (--wait --build -d) + open browser.
make docker.up                      # Stack up detached (ENV=dev|staging|prod).
make docker.down                    # Stop stack and remove orphans.
make docker.bash                    # Shell into the php container.
make sf c='about'                   # Symfony console (also: make cc, make routes f='…').
make composer c='req vendor/pkg'    # Composer in container.
make php.unit c='--filter SomeTest' # PHPUnit (also: make php.behat, make php.test).
make php.stan                       # PHPStan — REQUIRED on every PHP file you change.
make php.lint                       # Full PHP lint sweep — REQUIRED at end of any PHP task.
make db.diff                        # Generate migration from entity diff (then make db.migrate).
make pwa.dev                        # Next dev (Turbopack, host :80) — pair with make api-up-http.
make pwa.test                       # Vitest + Playwright (also: make pwa.test.unit/.e2e).
make pwa.lint                       # ESLint + Prettier — REQUIRED at end of any PWA task.
make lint                           # All linters (PHP + PWA).
make test                           # All tests (PHP + PWA).
make ci                             # Full CI (ci.lint + ci.test).
```

**Always start the stack with `make dev` or `make docker.up`.** Bare `docker compose up -d` skips composer install on cold checkouts and the `pwa.install.if-missing` guard.

---

## Architecture

```
Browser → FrankenPHP :80/:443 ──┬─ /api/*                 → Symfony controllers (api/)
  (Caddy embedded)              ├─ /.well-known/mercure   → Mercure hub
                                └─ everything else        → reverse-proxy to pwa:3000 (Next.js)
                                                                   ↓
                                                        Doctrine → Postgres :5432
                                                                   ↓
                                                  Messenger (Doctrine transport) → messenger_worker
```

`make dev.local` skips the PWA container: API on host `:8000`, `next dev` on host `:80` (requires env vars in `pwa/.env.local` — see quickref). Full diagram and trade-offs in [`docs/integration-architecture.md`](docs/integration-architecture.md).

Both sides follow **DDD + Hexagonal / Clean Architecture**, with dependencies pointing inward. **Do not** import frameworks (Symfony, Doctrine, Next, Inversify, HTTP clients, ORM) inside `Domain/` — adapters go in `Infrastructure/`, orchestration in `Application/`. Full rule set: `.cursor/rules/*.mdc` (architecture, clean-code, database, frontend, php-standards, security, solid-principles, testing) and `pwa/AGENTS.md`.

---

## Required checks

- **PHP edits** → run `make php.stan` on every changed file before declaring the task done; fix anything reported. At the end, run `make php.lint` and fix anything new it reports.
- **PWA edits** → run `make pwa.lint` at the end.
- **HTTP error responses** → never bypass the RFC 9457 pipeline with manual `JsonResponse` error bodies. Adding a marker interface or changing its mapping requires updating [`docs/api-error-contract.md`](docs/api-error-contract.md) (NFR26). The drift gate is `make php.lint.error-contract`.
- **Migrations** → generate via `make db.diff`. You may only edit a migration created on the current feature branch. Once merged into `main`, it is immutable — create a new migration instead.

---

## Working principles

1. Don't assume. Don't hide confusion. Surface tradeoffs.
2. Minimum code that solves the problem. Nothing speculative.
3. Touch only what you must. Clean up only your own mess.
4. Define success criteria. Loop until verified.
5. Learn from your errors; don't repeat them. Follow up with a plan or doc when warranted.

---

## Parallelizing work with subagents

When a task decomposes into independent subtasks (different bounded contexts, different files, no shared state), spawn parallel subagents rather than working sequentially. Each subagent must receive a self-contained prompt with full context.

Example: plan → subagent A (API: domain entity + Doctrine mapping + migration in `api/`) + subagent B (PWA: route + component + Inversify wiring in `pwa/`) running in parallel → verify each (`make php.stan`, `make pwa.lint`) → commit.

Do not spawn subagents for tasks that share state mid-flight — e.g. two agents editing the same migration, the same `services.yaml`, the same Inversify container module, or both touching `api/src/Shared/`.

---

## Conventions

### Branch naming

| Type    | Format                  | Base                  |
|---------|-------------------------|-----------------------|
| Feature | `feat/<scope>-<slug>`   | `main`                |
| Fix     | `fix/<scope>-<slug>`    | `main`                |
| Hotfix  | `hotfix/<scope>-<slug>` | latest production tag |
| Chore   | `chore/<slug>`          | `main`                |
| Docs    | `docs/<slug>`           | `main`                |
| CI      | `ci/<slug>`             | `main`                |

`<scope>` is `api`, `pwa`, or a bounded context (`backoffice`, `frontoffice`, `shared`). Keep branches short-lived; rebase onto base rather than merging it back in.

### Commit messages

[Conventional Commits](https://www.conventionalcommits.org/): `<type>(<scope>): <subject>` — subject lower-case, imperative, no trailing period. Types: `feat | fix | docs | style | refactor | perf | test | build | ci | chore | revert`. Full pre-commit hook setup in [`docs/contribution-guide.md`](docs/contribution-guide.md).

### Do not touch

- `api/config/reference.php` — auto-generated.
- `api/vendor/`, `pwa/node_modules/` — package-manager managed.
- `api/var/` — Symfony runtime cache and logs; never commit.
- `api/migrations/` once merged — generate new ones via `make db.diff`. Editing migrations on the current feature branch is allowed; editing applied/merged migrations is not.

### Markdown link style

The repo's IDE Markdown linter rejects link targets that don't resolve to a concrete file. When writing or editing any `.md`:

- **Link only to concrete files.** No trailing-slash directory hrefs (`[api/docs/](api/docs/)`). Pick a representative file or use inline code: `` `api/docs/` ``.
- **Don't link to globs** like `[…](.cursor/rules/*.mdc)`. Use inline code: `` `.cursor/rules/*.mdc` ``, optionally followed by a link to one specific rule file.

Fix violations you spot while editing a file for another reason.

### Keeping docs up to date

Update the matching file as part of any PR that changes:

- **New Make targets or commands** → this file (`CLAUDE.md`), [`docs/claude-code-quickref.md`](docs/claude-code-quickref.md), the relevant `make/*.mk` module, and [`docs/development-guide-api.md`](docs/development-guide-api.md) / [`docs/development-guide-pwa.md`](docs/development-guide-pwa.md) when the workflow surface changes.
- **New `src/` directories or renamed ones** → [`docs/claude-code-quickref.md`](docs/claude-code-quickref.md), [`docs/architecture-api.md`](docs/architecture-api.md) or [`docs/architecture-pwa.md`](docs/architecture-pwa.md), and [`docs/source-tree-analysis.md`](docs/source-tree-analysis.md).
- **Architecture decisions** → [`docs/architecture-api.md`](docs/architecture-api.md) / [`docs/architecture-pwa.md`](docs/architecture-pwa.md), and [`docs/integration-architecture.md`](docs/integration-architecture.md) when cross-deployable.
- **Domain events / Messenger transports** → [`docs/architecture-api.md`](docs/architecture-api.md).
- **API endpoints, controllers, or response shapes** → `api/docs/` and [`docs/architecture-api.md`](docs/architecture-api.md).
- **Error contract (markers, status mapping, redaction, debug)** → [`docs/api-error-contract.md`](docs/api-error-contract.md). Adding a marker interface or changing its mapping is mandatory here (NFR26).
- **PWA module boundaries / Inversify bindings** → `pwa/docs/` and [`docs/architecture-pwa.md`](docs/architecture-pwa.md).
- **Deployment / Compose / CORS / Mercure / mailer** → [`docs/deployment-guide.md`](docs/deployment-guide.md) and [`pwa/docs/production-deployment.md`](pwa/docs/production-deployment.md).
- **Security-sensitive change** → `PRODUCTION_SECURITY_CHECKLIST.md` (authoritative — see `.cursor/rules/security.mdc`).

When a rule here conflicts with `.cursor/rules/*.mdc`, [`api/CLAUDE.md`](api/CLAUDE.md), [`pwa/CLAUDE.md`](pwa/CLAUDE.md), or `pwa/AGENTS.md`, flag the conflict rather than silently picking one.

---

## Docs to consult

- [`docs/claude-code-quickref.md`](docs/claude-code-quickref.md) — full command catalog, layout tables, recipes, gotchas.
- [`docs/index.md`](docs/index.md) — generated documentation index.
- [`docs/integration-architecture.md`](docs/integration-architecture.md) — how FrankenPHP / Next / Symfony share `localhost`.
- [`docs/architecture-api.md`](docs/architecture-api.md) — API layering, domain events, Messenger, audit table.
- [`docs/architecture-pwa.md`](docs/architecture-pwa.md) — PWA layering and module boundaries.
- [`docs/api-error-contract.md`](docs/api-error-contract.md) — RFC 9457 Problem Details: marker → status map, env-aware `debug`, redaction, performance budgets.
- [`docs/deployment-guide.md`](docs/deployment-guide.md), [`pwa/docs/production-deployment.md`](pwa/docs/production-deployment.md) — prod Compose, mailer, DNS, CORS, Mercure, smoke tests.
- [`docs/development-guide-api.md`](docs/development-guide-api.md), [`docs/development-guide-pwa.md`](docs/development-guide-pwa.md) — day-to-day workflows.
- [`docs/contribution-guide.md`](docs/contribution-guide.md), [`docs/source-tree-analysis.md`](docs/source-tree-analysis.md).
- [`api/README.md`](api/README.md), `api/docs/`, [`pwa/README.md`](pwa/README.md), `pwa/docs/` — deployable-specific details.
