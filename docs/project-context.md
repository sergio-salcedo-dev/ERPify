---
project_name: 'ERPify'
user_name: 'Sergio'
date: '2026-04-21'
sections_completed: ['technology_stack', 'language_rules', 'framework_rules', 'testing_rules', 'quality_rules', 'workflow_rules', 'anti_patterns']
status: 'complete'
optimized_for_llm: true
---

# Project Context for AI Agents

_Load this before generating code. Every line is a constraint or a decision, not a description. If a rule is not here, defer to the existing codebase._

---

## Technology Stack & Versions

Monorepo with two deployables driven from repo root: `api/` (Symfony/FrankenPHP) and `pwa/` (Next.js). Compose orchestrates both.

### API (`api/`) — PHP / Symfony

| Concern | Technology (version) | Key Constraint for Code Generation |
|---|---|---|
| Runtime | **PHP 8.5** | Floor: `"php": "^8.5"`. 8.5 is bleeding-edge — assume 8.3 idioms are forward-compatible; do **not** invent 8.5-specific syntax from training data. Required exts: bcmath, sodium, ctype, curl, fileinfo, gd, iconv, json, pdo, xml, opcache. |
| Framework | **Symfony 8.0.x** | Require **individual components** — `symfony/symfony` metapackage is in `conflict` and must never be added. `extra.symfony.require: 8.0.*`. Flex `allow-contrib: true`; `auto-scripts` run `cache:clear` + `assets:install` on install/update. |
| Routing / DI / Validation | Symfony 8 attributes | Use `#[Route]`, autowiring, `#[AsCommand]`, attribute constraints. No YAML route files in `src/`. Explicit `services.yaml` entries are the exception, not the default. |
| HTTP server | **FrankenPHP** (Caddy embedded, Docker tag `dunglas/frankenphp:1-php8.5` pinned by digest) | Caddy terminates TLS and reverse-proxies HTML `/` to Next `:3000`; `/api/*` and `/.well-known/mercure` stay on PHP. No separate web server. |
| ORM | **Doctrine ORM 3.6**, DBAL 4.4, Migrations 4.0, Persistence 4.1 | Breaking vs 2.x/legacy: `EntityManager::flush($entity)` removed (flush takes no args); `Query::iterate()` → `toIterable()`; DBAL 4 removed `fetchAll()` → `fetchAllAssociative()`; `Connection::query()` → `executeQuery()`; `ResultStatement` gone. `AbstractController::json()` preferred over manual `new JsonResponse(json_encode(...))` so Serializer groups apply. |
| Database | **PostgreSQL** (Compose service) | Use Doctrine migrations (`make db.migrate` / `db.diff`). Fixtures via Hautelook Alice. Never modify prod DB directly. |
| Async / Events | Symfony Messenger 8 + Doctrine transport | `messenger_worker` is a **separate Compose service in prod/ci** — not run in the web container. See `docs/domain-events-and-messenger.md` for transport, serializer, and audit-table semantics before generating handlers. |
| Realtime | Symfony Mercure 0.7 (+ bundle 0.4) | Served at `/.well-known/mercure` on the FrankenPHP origin. Prod requires `CADDY_MERCURE_JWT_SECRET`. |
| Mail | symfony/mailer 8 | Async via Messenger — see domain-events doc. |
| Storage / Media | league/flysystem 3.33 (+ bundle 3.7), Intervention Image 4 | Use Flysystem adapters; do not hit local FS directly. |
| CORS | nelmio/cors-bundle 2.6 | No wildcard `*` origins (see `.cursor/rules/security.mdc`). |
| Autoload | PSR-4 | `Erpify\\ → api/src/`, `Erpify\Tests\\ → api/tests/`. Polyfills `symfony/polyfill-ctype|iconv|php72..84` are `replace`d — **do not** add them transitively. |
| Unit tests | **PHPUnit 13** | Config: `api/phpunit.xml.dist`. |
| E2E tests | **Behat 3** in isolated tree | Lives exclusively under `api/tools/behat/composer.json`. **Never** `composer require behat/*` into `api/composer.json`. Install via `composer behat-tools-install`. |
| Static analysis | PHPStan 2, Psalm 6.16, Rector 2 | Configs: `api/phpstan.neon`, `api/psalm.xml`, `api/rector.php`. |
| Style | PHP-CS-Fixer 3.95, PHPCS 4, PHPMD | Config: `api/.php-cs-fixer.php`. PSR-12 + `declare(strict_types=1);`. |
| Hygiene | composer-unused, composer-require-checker, `roave/security-advisories: dev-latest` | Run via `make composer.checks`. |

### PWA (`pwa/`) — Next.js / React

| Concern | Technology (version) | Key Constraint for Code Generation |
|---|---|---|
| Runtime (container) | **Node 24** (Alpine, digest-pinned) | Base image `node:24-alpine`. Pin any `engines.node` updates here. |
| Package manager | **npm** | Lockfile: `pwa/package-lock.json`. Do not switch to pnpm/yarn or generate their lockfiles. |
| Framework | **Next.js 16.2.4** (App Router, Turbopack) | Beyond most training data — prefer reading existing `src/app/` patterns over memory. Turbopack is the dev bundler; Webpack-specific `next.config.*` entries silently no-op. Server Actions API differs from 14.x. |
| UI runtime | **React 19.2** | Use `use()` for promise unwrapping; `React.FC` is out of favor — use plain function components with typed props. |
| Language | **TypeScript 6** (`strict: true`) | Strict mode is ON in `pwa/tsconfig.json`. Decorators need `experimentalDecorators` + `emitDecoratorMetadata` (required by Inversify). `target: ES2017`. |
| Styling | **Tailwind 4.2** (via `@tailwindcss/postcss`) + Shadcn 4.3 | **No `tailwind.config.js`** — Tailwind 4 is CSS-first. Configuration lives in `pwa/src/app/globals.css` via `@theme {}` / `@config`. Do **not** generate v3-style JS config. |
| UI kit | Shadcn, Base UI React, tw-animate-css, tailwind-merge, cva, lucide-react, motion | BEM class naming (`block__element--modifier`), mobile-first. |
| DI | **Inversify 8** + reflect-metadata | Constructor injection of **domain interfaces** (defined in `src/context/<bc>/domain`). `reflect-metadata` must be imported **once** at the app entry point. Use `@injectable()` + `@inject()`. |
| Forms | react-hook-form + `@hookform/resolvers` | — |
| Unit tests | **Vitest 4** | Config: `pwa/vitest.config.ts` (v4 config API differs from v1/v2). Command: `make pwa.test.unit c='src/context/foo/bar.test.ts'`. |
| E2E tests | **Playwright 1.59** | Config: `pwa/playwright.config.ts`. `baseURL: http://localhost:3000` (**not `:80`**) — `dev:e2e` runs Next on `:3000`. |
| Testing libs | @testing-library/react 16, jest-dom 6, jsdom | — |
| Lint / format | ESLint 10.2 + `eslint-config-next` 16.2 + `eslint-config-prettier`, Prettier 3.8 | Run via `make pwa.lint`. |
| Integrations in deps | `@google/genai`, `firebase-tools` | Present — do not assume usage; check code before wiring. |

### Infrastructure / Dev

| Concern | Value | Key Constraint |
|---|---|---|
| Compose entrypoint | `compose.yaml` + `compose.dev.yaml` / `compose.prod.yaml` | Run from **repo root only**. Switch overlays with `ENV=dev\|ci\|staging\|prod`. |
| Canonical commands | Root `Makefile` + `make/*.mk` | Prefer `make <target>` over raw `docker compose` / `composer` / `npm`. The Make layer handles container routing via `ENV` and `IN_CONTAINER`. |
| Passthrough args | `c=` | Examples: `make composer c='req vendor/pkg'`, `make php.unit c='--filter SomeTest'`, `make pwa.test.unit c='path/to/file.test.ts'`. |
| Base images (pinned by digest) | `dunglas/frankenphp:1-php8.5`, `debian:13-slim`, `node:24-alpine` | Dependabot tracks `/api` and `/pwa` — do not unpin. |
| Prod required env | `APP_SECRET`, `CADDY_MERCURE_JWT_SECRET`, `POSTGRES_PASSWORD` | Missing any → prod start fails. |

### Ports

| Flow | Host | Service |
|---|---|---|
| Docker dev (default) | `http://localhost` → `:80`/`:443` | FrankenPHP (HTML proxied to Next `:3000` in-container) |
| Next container (e2e target) | `:3000` | `next dev --turbo -p 3000` (`dev:e2e`) |
| `dev-local` (host Next vs host Symfony) | `:80` (Next), `:8000` (Symfony) | Requires `NEXT_PUBLIC_SYMFONY_API_BASE_URL=http://localhost:8000` and `SYMFONY_INTERNAL_URL=http://localhost:8000` in `pwa/.env.local` |

## Critical Implementation Rules

### Language-Specific Rules

#### PHP (api/)

- `declare(strict_types=1);` at the top of every PHP file. No exceptions.
- PSR-12 extended coding style; enforced by PHP-CS-Fixer (`api/.php-cs-fixer.php`) and PHPCS.
- Type declarations on **every** parameter, return type, and property. Use union/intersection/DNF types where PHP 8.x allows.
- Prefer modern constructs: `readonly` properties/classes, promoted constructor params, `match`, nullsafe `?->`, named args for clarity, first-class callable syntax.
- Enums (backed or pure) over string/int constants for closed sets.
- Exceptions for error flow — never return codes/`false`/`null`-sentinels for errors. Create domain-specific exception types in `Domain/` (no Symfony `HttpException` inside `Domain/`).
- No `eval`, no `extract`, no variable-variables, no `@` error suppression.
- No global state: `global`, static mutable state, and service-locator patterns are forbidden; use constructor DI.
- Early returns; max nesting 3–4 levels; functions small and single-purpose.
- Never import framework/ORM/HTTP classes inside `Domain/` — domain stays pure (see `.cursor/rules/architecture.mdc`).
- Namespace root is `Erpify\` → `api/src/`. Tests: `Erpify\Tests\` → `api/tests/`. Keep `Backoffice` / `Frontoffice` / `Shared` top-level boundaries.
- Doctrine entities live in `Infrastructure/` (or designated persistence folder), not `Domain/`. Domain objects are POPOs; mapping is via XML/attributes in the infra layer.

#### TypeScript (pwa/)

- `strict: true` in `pwa/tsconfig.json` — respect it; no `// @ts-ignore` or `any` without a written reason.
- Decorators require `experimentalDecorators` + `emitDecoratorMetadata` (already set) — Inversify depends on this; do not flip off.
- `target: ES2017`. Use modern ES features (async/await, optional chaining, nullish coalescing, top-level `await` only where Next allows it).
- Prefer `const`; never `var`. Arrow functions for callbacks; named `function` for top-level exports where a clearer stack trace helps.
- **No default exports** for modules under `src/context/**` — named exports only. App Router `page.tsx` / `layout.tsx` default exports are the exception Next.js requires.
- Interfaces (not `type` aliases) for DI contracts in `domain/`; `type` for unions/utility types.
- No `React.FC` — type props explicitly: `function Button(props: ButtonProps)` or `({ ... }: ButtonProps)`.
- Path imports: prefer the project's `@/` alias (Next default) over deep relative `../../../`.
- Do not import React Server Component code into Client Components or vice-versa — respect Next 16 `'use client'` boundaries. Server Actions live in server-only modules.
- No `console.log` in committed code; use a logger abstraction if one exists, otherwise structured errors.
- Error handling: throw typed errors; never swallow with empty `catch`. Async boundaries (route handlers, server actions) must convert thrown domain errors to HTTP responses.
- ESLint (`eslint-config-next` + Prettier) is authoritative — do not hand-format against it.

### Framework-Specific Rules

#### Symfony 8 (api/)

- **Kernel**: `Erpify\Kernel` in `api/src/Kernel.php` — do not move or rename.
- **Routing**: attribute-only (`#[Route]`) on controllers. No YAML route files in `src/`. Group controllers per bounded context under `Backoffice/` or `Frontoffice/`.
- **Controllers**: thin. Extend `AbstractController`; delegate to an Application-layer use case. Return via `$this->json(...)` so Serializer groups apply — do **not** hand-roll `new JsonResponse(json_encode(...))`.
- **DI**: autowiring + autoconfiguration default. Register explicit services in `services.yaml` only when autowiring can't resolve (tagged iterators, multiple implementations, factories). Bind domain interfaces to infra implementations via `_defaults` + `bind:` or `instanceof`.
- **No framework types in `Domain/`**: no `Request`, `Response`, `EntityManagerInterface`, `SerializerInterface`, `HttpException`, Symfony validator constraints, Doctrine annotations. Those belong in `Application/` (orchestration) or `Infrastructure/` (adapters).
- **Validation**: Symfony Validator attributes on DTOs in `Application/` or request-layer DTOs — not on domain entities.
- **Serialization**: use Serializer groups (`#[Groups]`) on DTOs; never expose domain entities directly over HTTP.
- **Messenger**:
    - Commands/queries/events implement marker interfaces from `Shared/` — do **not** couple handlers to `Symfony\Messenger\*` envelopes in domain code.
    - The **`messenger_worker`** is a separate Compose service in prod/ci — handlers must be idempotent and tolerate re-delivery.
    - See `docs/domain-events-and-messenger.md` before touching async email, audit, or transport config.
- **Mercure**: publish via the Mercure hub at `/.well-known/mercure`. Topics must be scoped per bounded context; never broadcast raw domain entities.
- **CORS**: edit `nelmio_cors.yaml` — never wildcard `*` for credentialed requests (see `.cursor/rules/security.mdc`).
- **Flysystem**: always go through configured adapters; never `file_get_contents` / `fopen` on user-facing paths.
- **Env**: access via `$_ENV` / Symfony's env-var processors — never `getenv()` directly. Secrets go through Symfony Secrets vault in prod.
- **Console commands**: use `#[AsCommand]`. Place under `Infrastructure/Cli/` or a dedicated `Command/` folder — not in `Domain/`.

#### Next.js 16 + React 19 (pwa/)

- **App Router only** — `src/app/`. No `pages/` directory. Route segments: `page.tsx`, `layout.tsx`, `loading.tsx`, `error.tsx`, `route.ts`.
- **Server vs Client boundary**:
    - Default is Server Component. Add `'use client'` only when required (state, effects, browser APIs, event handlers).
    - Never import client components from server-only modules in a way that pulls client hooks into the RSC payload.
    - Server-only code: mark with `import 'server-only'` where sensitive (DB/secret access).
- **Data fetching**: prefer Server Components + direct fetch/DI-resolved services over client-side fetch. Use React 19 `use(promise)` inside RSC for streaming.
- **Server Actions**: `'use server'` directives in dedicated server modules. Validate inputs at the boundary; never trust client-supplied IDs.
- **State**: React hooks for local UI state. Cross-cutting state uses the project's Inversify-wired services; avoid adding Redux/Zustand/Jotai unless already present.
- **DI (Inversify 8)**:
    - `reflect-metadata` is imported **once** at the app entry.
    - Bindings live per bounded context (e.g. `src/context/<bc>/infrastructure/container.ts`) and are composed into a root container.
    - Inject **domain interfaces** (from `domain/`), never concrete infra classes, into application use cases.
- **Directory discipline**:
    - `src/app/` — routing & UI shells only.
    - `src/components/` — presentational + shared UI (Shadcn primitives).
    - `src/context/<bc>/{domain,application,infrastructure}` — business logic. Shared cross-cutting code goes in `src/context/shared`, not ad-hoc folders.
    - `src/lib/` — glue/util only.
- **UI**:
    - Shadcn UI + Tailwind 4 + BEM class naming (`block__element--modifier`). Mobile-first.
    - Icons from `lucide-react`; animations via `motion` / `tw-animate-css`.
    - Use `clsx` + `tailwind-merge` (`cn()` helper) — never hand-concatenate class strings.
- **Forms**: `react-hook-form` + `@hookform/resolvers`. Validate at the resolver layer.
- **Next config**: `next.config.*` is Turbopack-aware. Webpack-only config blocks are silently ignored — don't assume they run in dev.
- **Env**:
    - `NEXT_PUBLIC_*` is public. Anything else is server-only — never read it from a client component.
    - API base URL: `NEXT_PUBLIC_SYMFONY_API_BASE_URL`. Internal SSR fetches: `SYMFONY_INTERNAL_URL`. See `docs/local-fullstack-traffic.md`.
- **Images**: use `next/image` with explicit `width`/`height` or `fill`.
- **Mercure (client)**: subscribe via EventSource to same-origin `/.well-known/mercure` — don't hardcode a cross-origin URL.

### Testing Rules

#### General

- AAA pattern (Arrange / Act / Assert). One behavior per test.
- Tests must be fast, independent, repeatable. No shared mutable state; no ordering dependencies.
- Name tests by behavior, not method: `it_rejects_invoices_older_than_30_days`, not `testCreate1`.
- Domain logic unit-tested directly — no container, no DB. Infrastructure gets integration tests. End-to-end flows get Behat (API) or Playwright (PWA).
- Prefer in-tree fakes of domain interfaces over mock-builder mocks. Mock at the **outer** boundary (HTTP client, filesystem, mailer transport).
- No snapshot tests for business logic — snapshots acceptable only for rendered UI shape stability.

#### PHP — PHPUnit 13 (api/)

- Config: `api/phpunit.xml.dist`. Run via `make php.unit` (optional `c='--filter ClassName'`).
- Test namespace root: `Erpify\Tests\` → `api/tests/`. Mirror `src/` structure.
- Use attributes, not doc-comments: `#[Test]`, `#[DataProvider(...)]`, `#[Group('slow')]`.
- `declare(strict_types=1);` in every test file. Typed fixtures.
- Prefer in-memory repositories implementing domain interfaces over PHPUnit mock builders for domain collaborators.
- Integration tests touching Doctrine use a **real Postgres** test DB (Compose), not SQLite. Wrap each test in a transaction or reset via migrations/fixtures.
- Never commit tests that hit the network. Mock the HTTP client at the transport level.

#### PHP — Behat (api/)

- Isolated tree `api/tools/behat/` with its own `composer.json`. Never add Behat deps to `api/composer.json`.
- Install via `composer behat-tools-install`. Run via `make php.behat`.
- Feature files describe business behavior, not endpoints. Step definitions live in the Behat tree.
- Drive the app via HTTP (Mink/BrowserKit) — do not bootstrap the Symfony kernel directly from Behat steps.
- Fixtures: Hautelook Alice via `make db.load.fixtures`. Reset DB between mutating scenarios.

#### JS — Vitest 4 (pwa/)

- Config: `pwa/vitest.config.ts`. Run via `make pwa.test.unit` (optional `c='src/context/foo/bar.test.ts'`; watch: `make pwa.test.unit.watch`).
- Test files under `tests/` mirroring `src/`. Name `*.test.ts` / `*.test.tsx`.
- Use `@testing-library/react` + `@testing-library/jest-dom`. Query by role/label/text — never by CSS class or test ID when an accessible query works.
- No shallow rendering. Render real components.
- Mock at module boundary with `vi.mock(...)`; prefer injecting fakes via the Inversify container over global mocks.
- Async: use `findBy*` / `waitFor` — never `setTimeout` sleeps.

#### JS — Playwright 1.59 (pwa/)

- Config: `pwa/playwright.config.ts`. Run via `make pwa.test.e2e`. Reports: `make pwa.test.e2e.reports`.
- `baseURL: http://localhost:3000` — Playwright targets `dev:e2e` on `:3000`, **not** `:80`.
- Each spec independent: create its own data, clean up after. No ordering between specs.
- Locators: role/text based (`getByRole`, `getByLabel`). CSS/XPath selectors last resort.
- Never sleep. Use `expect(locator).toBeVisible()` / `toHaveText()` auto-waiting.
- Share login via Playwright `storageState`, not sequential runs.

#### Coverage & gates

- Critical business logic in `Domain/` and `context/<bc>/domain` **must** be unit-tested. Adapters covered by integration/e2e.
- All existing and new tests must pass 100% before a story is done (`.cursor/rules/testing.mdc`, `bmad-agent-dev` principle).
- CI runs `make ci.test` — verify locally with `make test` before pushing.

### Code Quality & Style Rules

#### Universal

- **DDD + Hexagonal discipline is load-bearing.** Dependencies point inward: `Infrastructure → Application → Domain`. `Domain` imports nothing from framework, ORM, HTTP, or DI container.
- Bounded contexts are real boundaries: cross-context calls go through published Application services or domain events — never reach into another context's `Domain/` or `Infrastructure/`.
- SOLID is enforced (SRP, OCP, LSP, ISP, DIP). Prefer composition over inheritance. Inject interfaces, not concretes.
- DRY/KISS/YAGNI. Don't abstract for hypothetical futures. Three similar lines > a premature abstraction.
- Early returns; max nesting 3–4 levels; functions small, single-purpose, ideally under ~40 lines.
- No magic numbers/strings — named constants or enums.
- Remove dead code and commented-out code before committing.
- Comments explain **why**, never **what**. Default to no comment.
- Tell-don't-ask, respect Demeter's Law, encapsulate state.
- No feature flags or backwards-compatibility shims unless an explicit requirement demands them.

#### Naming

- Descriptive names that reveal intent. Avoid non-standard abbreviations.
- Verbs for methods, nouns for classes/values.
- Booleans as questions: `isActive`, `hasPermission`, `canApprove`.
- Collections are plural: `users`, `invoices`.
- File names follow language/framework convention: PHP PSR (PascalCase class files), TS components PascalCase (`InvoiceTable.tsx`), non-component TS kebab-case or camelCase consistent with existing siblings.
- Test files mirror source: `Foo.php` → `FooTest.php`; `foo.ts` → `foo.test.ts`.

#### Layout

- **API (`api/src/`)**: `Backoffice/ | Frontoffice/ | Shared/` top-level, each with `Domain/ Application/ Infrastructure/`. New features choose a bounded context and create the three folders if needed — don't sprinkle files at the root of a context.
- **PWA (`pwa/src/`)**: `app/` (routes), `components/` (UI), `context/<bc>/{domain,application,infrastructure}` (business logic), `lib/` (glue). Shared cross-cutting code lives in `src/context/shared`, not ad-hoc folders.
- Tests mirror source trees (`api/tests/`, `pwa/tests/`).

#### Linting / Formatting — tools are authoritative

- **PHP**: PHP-CS-Fixer (`api/.php-cs-fixer.php`), PHPCS, PHPStan 2 (`api/phpstan.neon`), Psalm 6.16, Rector 2, PHPMD. Run all via `make php.lint`. Don't hand-format against these tools.
- **JS/TS**: ESLint 10 + `eslint-config-next` + `eslint-config-prettier`, Prettier 3.8. Run via `make pwa.lint`; fix via `make pwa.lint.fix` / `make pwa.format.fix`. Don't hand-format.
- **All files**: `.editorconfig` wins. LF line endings (enforced by pre-commit). Max file size 1MB (pre-commit). No mixed line endings.
- Aggregates: `make lint` (both sides), `make ci` (`ci.lint` + `ci.test`).

#### UI / CSS (pwa/)

- Tailwind utility-first. No inline `style=` unless dynamic value requires it.
- BEM for custom classes: `block__element--modifier`. Mobile-first breakpoints.
- Compose classes with `cn()` (clsx + tailwind-merge) — never string-concatenate class names.
- Shadcn primitives are the base — extend via `components/ui/` customizations, don't fork upstream files in place.
- Accessibility: semantic HTML, proper ARIA, keyboard nav, visible focus, sufficient color contrast. Every interactive element is reachable via keyboard.

#### Documentation

- Public APIs (controller routes, message types, domain services) get a one-line purpose docblock only when the name alone is insufficient.
- Non-obvious decisions, workarounds, and invariants get a short `// why: ...` comment with the reason.
- Keep `pwa/AGENTS.md`, `api/README.md`, and `docs/` in sync when behavior changes. `PRODUCTION_SECURITY_CHECKLIST.md` is authoritative — update it on any security-sensitive change (see `.cursor/rules/security.mdc`).

### Development Workflow Rules

#### Make-first execution

- The root `Makefile` + `make/*.mk` is the canonical interface. Prefer `make <target>` over raw `docker compose` / `composer` / `npm` / linter calls.
- Run Make targets from **repo root**, never from `api/` or `pwa/`.
- Environment overlay: `ENV=dev|ci|staging|prod` (default `dev`). `IN_CONTAINER` is handled by the Make layer — do not invoke container exec directly.
- Common: `make docker.up | docker.down | docker.logs | docker.ps | docker.health | docker.bash | docker.clean`. `docker.clean` is **destructive** (drops volumes) — confirm before use.
- Passthrough: `c='...'` — e.g. `make composer c='req vendor/pkg'`, `make php.unit c='--filter X'`, `make pwa.test.unit c='src/context/foo/bar.test.ts'`.
- DB: `make db.migrate | db.diff | db.status | db.validate | db.load.fixtures | db.shell`. `db.reset` is **destructive** (drop → migrate → fixtures) — only on dev/ci.

#### Branches

- `main` is the trunk. Never force-push to `main`.
- Feature branches: `feat/<scope>-<slug>` (e.g. `feat/invoice-export`).
- Fix branches: `fix/<scope>-<slug>`.
- Chore/CI/docs: `chore/...`, `ci/...`, `docs/...`.
- Keep branches short-lived; rebase onto `main` rather than merging `main` in repeatedly.

#### Commits (Conventional Commits, enforced)

- Format: `<type>(<scope>): <subject>` — subject **lower-case**, imperative, no trailing period.
- Types: `feat | fix | docs | style | refactor | perf | test | build | ci | chore | revert`.
- Optional body explains **why**; reference issues in the footer (`Closes #123`).
- Pre-commit validates the message (commitlint via pre-commit hooks). **Never** skip with `--no-verify` unless the user explicitly authorizes.
- Create new commits rather than amending; prefer small, focused commits.
- Before committing, run security checks per `.cursor/rules/security.mdc` and update `PRODUCTION_SECURITY_CHECKLIST.md` when security-relevant files change.

#### Pre-commit hooks

- Setup once: `pip install pre-commit && pre-commit install && pre-commit install --hook-type commit-msg && detect-secrets scan > .secrets.baseline`.
- Runs on every commit: trailing whitespace, EOF fixer, YAML/JSON/TOML validation, large-file / merge-conflict / case-conflict / mixed-line-ending / private-key / AWS-credential / secret detection, Conventional Commit validation, PHP syntax checks.
- If a hook fails: **fix the underlying issue**, re-stage, and create a **new** commit. Never `--amend` after a hook failure.

#### Pull requests

- Target `main`. Title mirrors the primary commit's Conventional Commit subject.
- Body: **what** changed, **why**, and a **test plan** (bulleted checklist). Include screenshots for UI changes.
- CI must be green (`make ci` equivalent + SuperLinter via `make ci.superlint` if touched).
- At least one review. Security-sensitive changes require the checklist update in the PR body.
- Don't push directly to `main`. Don't force-push shared branches without coordinating.

#### Deployment

- Dev: `make docker.up` from repo root.
- CI/Staging/Prod: `make docker.up ENV=ci|staging|prod` — overlays `compose.dev.yaml` or `compose.prod.yaml` accordingly.
- **Prod requirements** (missing any → start fails): `APP_SECRET`, `CADDY_MERCURE_JWT_SECRET`, `POSTGRES_PASSWORD`.
- Prod Compose runs a separate `messenger_worker` service and a mailer pipeline — see `docs/production-deployment.md` before deploying behavior changes that touch async flows.
- Base images are pinned by sha256 digest (`dunglas/frankenphp:1-php8.5`, `debian:13-slim`, `node:24-alpine`); Dependabot at `/api` and `/pwa` handles digest bumps. Do not unpin.
- DNS, CORS origins, and Mercure cookie/CORS config per `docs/mercure-production-deployment.md` and `docs/production-deployment.md`. After deploy, run the documented smoke tests.

#### Local traffic model — don't confuse the two

- **Docker dev (default)**: browser → `http(s)://localhost` → FrankenPHP. HTML `/` is proxied to Next (`:3000` in-container). `/api/*` and `/.well-known/mercure` stay on PHP. See `docs/local-fullstack-traffic.md`.
- **`dev-local`**: host-run Next on `:80` + host-run Symfony on `:8000`. Requires `NEXT_PUBLIC_SYMFONY_API_BASE_URL=http://localhost:8000` and `SYMFONY_INTERNAL_URL=http://localhost:8000` in `pwa/.env.local`. Pick one flow per session — don't mix.

### Critical Don't-Miss Rules

#### Architecture anti-patterns — will break the repo's invariants

- ❌ Importing Symfony / Doctrine / HTTP / DI-container types inside `Domain/` (API) or `src/context/<bc>/domain/` (PWA). Domain stays pure.
- ❌ Cross-context reach-ins: `Backoffice` code accessing `Frontoffice/Domain` or `Frontoffice/Infrastructure` directly. Cross via Application services or domain events only.
- ❌ Adding `symfony/symfony` metapackage to `api/composer.json` — it is in `conflict`. Require individual components.
- ❌ Adding `behat/*` deps to `api/composer.json`. They live exclusively under `api/tools/behat/`.
- ❌ Adding `symfony/polyfill-*` packages that are already in the `replace` block.
- ❌ Creating a `tailwind.config.js` in `pwa/`. Tailwind 4 config lives in CSS (`@theme`/`@config`).
- ❌ Creating a `pwa/pages/` directory. App Router only.
- ❌ Default exports under `src/context/**`. Named exports only (Next's `page.tsx`/`layout.tsx` are the exception).
- ❌ Using `React.FC`, `enzyme`, shallow rendering, or class components.
- ❌ Invoking `docker compose`, `composer`, or `npm` directly from `api/` or `pwa/` subdirs. Go through `make` from repo root.

#### Runtime gotchas

- Playwright e2e targets **port 3000** (`dev:e2e`), **not 80**. `baseURL: http://localhost` silently fails every spec.
- Doctrine ORM 3 / DBAL 4: no `flush($entity)`, no `fetchAll()`, no `Connection::query()`, no `iterate()`. Use `toIterable()`, `fetchAllAssociative()`, `executeQuery()`.
- Turbopack is the dev bundler. Webpack-only `next.config.*` blocks silently no-op.
- `messenger_worker` is a **separate Compose service** in prod/ci. Handlers must be idempotent; delivery is at-least-once.
- `reflect-metadata` must be imported **once** at the PWA app entry.
- Mercure client must hit **same-origin** `/.well-known/mercure`. Don't hardcode cross-origin URLs.
- In `dev-local`, both `NEXT_PUBLIC_SYMFONY_API_BASE_URL` and `SYMFONY_INTERNAL_URL` must be `http://localhost:8000`.

#### Security (authoritative: `.cursor/rules/security.mdc`)

- Never commit secrets: `.env`, credentials, API keys, tokens. Pre-commit scans block most — still review diffs.
- No debug artifacts (`var_dump`, `print_r`, `dd()`, `console.log`) in committed code.
- SQL only via Doctrine DBAL parameterized APIs or ORM. No string-concatenated SQL. No `eval`.
- CORS: no wildcard `*` for credentialed requests.
- File uploads: validate MIME, size, extension; store via Flysystem; never trust client-provided paths.
- CSRF protection on state-changing form endpoints.
- Error messages to clients must not leak stack traces, SQL, or internal paths.
- Auth checks at the Application layer — don't rely solely on controller-level `#[IsGranted]`.
- Xdebug disabled in prod images.
- Update `PRODUCTION_SECURITY_CHECKLIST.md` on any security-relevant diff.

#### Performance gotchas

- N+1 queries: explicit `JOIN`/`addSelect` in repository queries. Profile with `EXPLAIN ANALYZE`.
- No `SELECT *` in custom DQL/SQL — specify columns.
- Index foreign keys and frequently filtered columns in migrations.
- Avoid `OFFSET` pagination on large tables — use keyset pagination.
- PWA: `next/image` with width/height or `fill`; `dynamic(..., { ssr: false })` for heavy client-only components.
- Prefer server-side `Promise.all` over client-side fetch waterfalls.
- No premature caching — profile first.

#### Process gotchas

- Never `--no-verify` without explicit authorization.
- Never amend after a hook failure — create a new commit.
- Never force-push `main` or shared branches without coordination.
- Never destructive-delete (`rm -rf`, `db.reset`, `docker.clean`, `git reset --hard`) without explicit confirmation.
- Never introduce a new package manager, build tool, or framework without an approved story.

---

## Usage Guidelines

**For AI Agents:**
- Read this file before implementing any code.
- Follow all rules exactly as documented. When in doubt, prefer the more restrictive option.
- Defer to the codebase over training-data defaults — PHP 8.5, Next 16, React 19, Tailwind 4, Doctrine 3/DBAL 4, and Inversify 8 are all beyond common training cutoffs.
- When a rule here conflicts with `.cursor/rules/*.mdc`, `pwa/CLAUDE.md`, or `pwa/AGENTS.md`, flag the conflict rather than silently picking one.

**For Humans:**
- Keep this file lean and focused on non-obvious agent needs. Don't restate what the code already shows.
- Update when the stack changes (new major versions, new bounded contexts, new tooling).
- Review quarterly and delete rules that have become obvious or no longer apply.

Last Updated: 2026-04-21
