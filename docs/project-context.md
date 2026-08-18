---
project_name: 'ERPify'
user_name: 'Sergio'
status: 'complete'
optimized_for_llm: true
---

# Project Context for AI Agents

_What an agent gets wrong about **this** stack because its training data is older than the stack. Nothing else._

Every normative rule this repo has lives in `CLAUDE.md`, [`api/CLAUDE.md`](../api/CLAUDE.md), [`pwa/CLAUDE.md`](../pwa/CLAUDE.md) and `docs/rules/*.md`, all of which load automatically in their subtree. Restating them here produced a second, unversioned copy that went stale faster than the original and told agents the opposite of what the code did. So this file no longer restates them: it carries versions, the traps those versions set for a model trained before them, and pointers to whoever owns the rest.

## Who owns what

| Looking for | Read |
| --- | --- |
| Monorepo conventions, worktrees, branches, commits, PR gates, required checks | `CLAUDE.md` |
| API layering, Symfony/Doctrine specifics, the gate catalogue | [`api/CLAUDE.md`](../api/CLAUDE.md) |
| PWA layering, component boundaries, Inversify wiring | [`pwa/CLAUDE.md`](../pwa/CLAUDE.md) |
| Architecture, clean code, database, security, testing, commits, frontend rules | `docs/rules/*.md` — start at [`docs/rules/architecture.md`](rules/architecture.md) |
| How the two deployables share `localhost` | [`docs/integration-architecture.md`](integration-architecture.md) |
| Day-to-day workflows and the Playwright/e2e traffic model | [`docs/development-guide-api.md`](development-guide-api.md), [`docs/development-guide-pwa.md`](development-guide-pwa.md) |
| Full command catalogue | [`docs/claude-code-quickref.md`](claude-code-quickref.md) |

**When a line here disagrees with any of those, they win.** This file is descriptive; they are normative.

## Versions, and what they cost a model trained before them

Every version below is pinned by `make php.lint.project-context` against the manifest that owns it. A number here that the manifest contradicts fails the build — see *What the gate does not prove* at the end.

### API (`api/`)

| Concern | Version | What your training data gets wrong |
| --- | --- | --- |
| Runtime | **PHP 8.5** | Floor is `"php": "^8.5"`. Assume 8.3 idioms are forward-compatible; do **not** invent 8.5-specific syntax from memory. |
| Framework | **Symfony 8.1** | Require individual components — the `symfony/symfony` metapackage is in `conflict` and must never be added. |
| ORM | **Doctrine ORM 3.6**, DBAL 4.4 | Breaking vs 2.x, and the single most common source of generated code that does not run: `flush()` takes no arguments (`flush($entity)` is gone), `Query::iterate()` → `toIterable()`, `fetchAll()` → `fetchAllAssociative()`, `Connection::query()` → `executeQuery()`, `ResultStatement` removed. |
| Autoload | PSR-4 | `Erpify\` → `api/src/`, `Erpify\Tests\` → `api/tests/`. `symfony/polyfill-ctype\|iconv\|php72..84` are in `replace` — adding one back breaks the tree. |
| Unit tests | **PHPUnit 13** | Config `api/tools/phpunit/phpunit.dist.xml`. Attributes, not annotations. `expectExceptionMessage()` is deprecated — pick `expectExceptionMessageIs` / `…IsOrContains` / `…Matches` deliberately. |
| Acceptance | **Behat 4** (`@alpha`) | Lives in `api/composer.json` `require-dev`, configured by `api/behat.dist.php`, run from `api/vendor/bin/behat`. The Behat 3 `behat.yml` shape does not apply. |
| Static analysis | PHPStan 2 (`level: max`), Rector 2 | The two are coupled: Rector reflects into PHPStan internals, so they move together or not at all. |
| Style | PHP-CS-Fixer 3.95, PHPCS 4, PHPMD | The tools are authoritative; do not hand-format against them. |

### PWA (`pwa/`)

| Concern | Version | What your training data gets wrong |
| --- | --- | --- |
| Runtime | **Node 26** | `pwa/Dockerfile` pins a digest, not a tag, and it is Debian trixie — not Alpine. |
| Framework | **Next.js 16.3** (App Router, Turbopack) | Beyond most training cutoffs — read existing `src/app/` patterns rather than recalling 14.x. Turbopack is the dev bundler, so Webpack-only `next.config.*` entries silently no-op. |
| UI runtime | **React 19.2** | `use()` unwraps promises; `React.FC` is out of favour — plain functions with typed props. |
| Language | **TypeScript 6** | `strict: true`. Inversify needs `experimentalDecorators` + `emitDecoratorMetadata`; both are already set. |
| Styling | **Tailwind 4.3** | CSS-first: there is **no** `tailwind.config.js` and generating one is wrong. Configuration lives in `pwa/src/app/globals.css` under `@theme inline`. |
| DI | **Inversify 8** | Constructor injection of domain interfaces; `reflect-metadata` imported exactly once at the app entry. |
| Unit tests | **Vitest 4** | The v4 config API differs from v1/v2. |
| E2E | **Playwright 1.62** | Runs on the **host**, never in a container, and its `baseURL` is environment-resolved — the default run is not the containerised Next. The resolution and its cookie consequences are in [`docs/development-guide-pwa.md`](development-guide-pwa.md); do not infer them. |
| Lint | ESLint 10.8, Prettier 3.9 | Run through `make pwa.quality`. |

### Infrastructure

| Concern | Value | Note |
| --- | --- | --- |
| Compose | `compose.yaml` + `compose.dev.yaml` / `compose.prod.yaml` | Run from repo root only. `ENV=dev\|staging\|prod` selects the overlay; there is no `ci` environment — `make/config.mk` falls back to dev. |
| Database | **PostgreSQL 18** | Migrations via `make db.diff` / `db.migrate`; fixtures via Hautelook Alice. |
| Commands | root `Makefile` + `make/*.mk` | Prefer `make <target>` over raw `docker compose` / `composer` / `npm`; the Make layer decides container routing. Pass arguments with `c='…'`. |
| Base images | digest-pinned | `dunglas/frankenphp`, `debian`, `node`. Dependabot bumps the digests — do not unpin. |
| Prod env | `APP_SECRET`, `CADDY_MERCURE_JWT_SECRET`, `POSTGRES_PASSWORD` | Missing any → prod start fails. |

### Ports

| Flow | Host | Service |
| --- | --- | --- |
| Docker dev (default) | `http(s)://localhost` → `:80`/`:443` | FrankenPHP; HTML proxied to Next in-container, `/api/*` and `/.well-known/mercure` stay on PHP |

Next listens on `:3000` **inside** the container only — no compose file publishes it. A host `:3000` during an e2e run is Playwright's own `dev:e2e`, not the stack.

## What the gate does not prove

`make php.lint.project-context` compares every version claimed above against `api/composer.json` and `pwa/package.json`, in both directions: a number that drifts fails, and a registry line whose claim no longer appears in this file fails too. That is the whole of it.

It says **nothing** about whether a sentence here is true. The third column is prose, and prose is where every measured drift in this file's history landed — ten of ten, against zero wrong version numbers. Digests are unchecked (this page names no digest value). A claim about behaviour is only ever verified by reading the code, which is why the normative rules were moved out rather than gated in place: what cannot be falsified is not maintained here.
