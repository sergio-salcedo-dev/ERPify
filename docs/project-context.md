---
project_name: 'ERPify'
user_name: 'Sergio'
date: '2026-08-17'
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

| Concern                   | Technology (version)                                                                          | Key Constraint for Code Generation                                                                                                                                                                                                                                                                                                                                       |
|---------------------------|-----------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Runtime                   | **PHP 8.5**                                                                                   | Floor: `"php": "^8.5"`. 8.5 is bleeding-edge — assume 8.3 idioms are forward-compatible; do **not** invent 8.5-specific syntax from training data. Required exts: bcmath, sodium, ctype, curl, fileinfo, gd, iconv, intl, json, pdo, xml, opcache.                                                                                                                             |
| Framework                 | **Symfony 8.1.x**                                                                             | Require **individual components** — `symfony/symfony` metapackage is in `conflict` and must never be added. `extra.symfony.require: 8.1.*`. Flex `allow-contrib: true`; `auto-scripts` run `cache:clear` + `assets:install` on install/update.                                                                                                                           |
| Routing / DI / Validation | Symfony attributes                                                                            | Use `#[Route]`, autowiring, `#[AsCommand]`, attribute constraints. No YAML route files in `src/`. Explicit `services.yaml` entries are the exception, not the default.                                                                                                                                                                                                   |
| HTTP server               | **FrankenPHP** (Caddy embedded, Docker tag `dunglas/frankenphp:1-php8.5` pinned by digest)    | Caddy terminates TLS and reverse-proxies HTML `/` to Next `:3000`; `/api/*` and `/.well-known/mercure` stay on PHP. No separate web server.                                                                                                                                                                                                                              |
| ORM                       | **Doctrine ORM 3.6**, DBAL 4.4, Migrations 3.9 (migrations-bundle 4.0), Persistence 4.2       | Breaking vs 2.x/legacy: `EntityManager::flush($entity)` removed (flush takes no args); `Query::iterate()` → `toIterable()`; DBAL 4 removed `fetchAll()` → `fetchAllAssociative()`; `Connection::query()` → `executeQuery()`; `ResultStatement` gone. Responses go through the project's own `ResourceResponder` / `JsonResponder`, not `AbstractController::json()` — neither the base controller nor the Serializer is used in `api/src`. |
| Database                  | **PostgreSQL** (Compose service)                                                              | Use Doctrine migrations (`make db.migrate` / `db.diff`). Fixtures via Hautelook Alice. Never modify prod DB directly.                                                                                                                                                                                                                                                    |
| Async / Events            | Symfony Messenger 8 + Doctrine transport                                                      | `messenger_worker` is a **separate Compose service in prod/ci** — not run in the web container. See `docs/architecture-api.md` for transport, serializer, and audit-table semantics before generating handlers.                                                                                                                                               |
| Realtime                  | Symfony Mercure 0.8 (+ mercure-bundle 0.5)                                                    | Served at `/.well-known/mercure` on the FrankenPHP origin. Prod requires `CADDY_MERCURE_JWT_SECRET`.                                                                                                                                                                                                                                                                     |
| Mail                      | symfony/mailer 8                                                                              | Async via Messenger — see `docs/architecture-api.md` (Async & messaging).                                                                                                                                                                                                                                                                                                                             |
| CORS                      | nelmio/cors-bundle 2.6                                                                        | No wildcard `*` origins (see `docs/rules/security.md`).                                                                                                                                                                                                                                                                                                                  |
| Autoload                  | PSR-4                                                                                         | `Erpify\\ → api/src/`, `Erpify\Tests\\ → api/tests/`. Polyfills `symfony/polyfill-ctype\|iconv\|php72..84` are `replace`d — **do not** add them transitively.                                                                                                                                                                                                            |
| Unit tests                | **PHPUnit 13**                                                                                | Config: `api/tools/phpunit/phpunit.dist.xml`.                                                                                                                                                                                                                                                                                                                                          |
| E2E tests                 | **Behat 4** in the app Composer tree                                                          | Declared in `api/composer.json` under `require-dev`; configured by `api/behat.dist.php`. Runs from `api/vendor/bin/behat`, like PHPUnit.                                                                                                                                                                                                                                 |
| Static analysis           | PHPStan 2 (`level: max`, sole gate), Rector 2                                                 | Configs: `api/tools/phpstan/phpstan.neon`, `api/tools/rector/rector.php`. Psalm was removed entirely — no taint / security-dataflow analyser remains. **Never** add `vimeo/psalm` or a `psalm/*` plugin back.                                                                                                                                                                                 |
| Style                     | PHP-CS-Fixer 3.95, PHPCS 4, PHPMD                                                             | Config: `api/tools/ecs/.php-cs-fixer.dist.php`. PSR-12 + `declare(strict_types=1);`.                                                                                                                                                                                                                                                                                                    |
| Hygiene                   | composer-unused, composer-require-checker, `roave/security-advisories: dev-latest`            | Run via `make composer.check.all`.                                                                                                                                                                                                                                                                                                                                       |

### PWA (`pwa/`) — Next.js / React

| Concern              | Technology (version)                                                             | Key Constraint for Code Generation                                                                                                                                                                                |
|----------------------|----------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Runtime (container)  | **Node 26** (Debian trixie, digest-pinned)                                       | `pwa/Dockerfile` pins `node@sha256:…` (currently `node:26.7.0-trixie`) — not a tag, and not Alpine. `engines.node` is `^24.15.0 \|\| >=26.0.0`; keep it in step with the pin.                                       |
| Package manager      | **npm**                                                                          | Lockfile: `pwa/package-lock.json`. Do not switch to pnpm/yarn or generate their lockfiles.                                                                                                                        |
| Framework            | **Next.js 16.3** (App Router, Turbopack)                                         | Beyond most training data — prefer reading existing `src/app/` patterns over memory. Turbopack is the dev bundler; Webpack-specific `next.config.*` entries silently no-op. Server Actions API differs from 14.x. |
| UI runtime           | **React 19.2**                                                                   | Use `use()` for promise unwrapping; `React.FC` is out of favor — use plain function components with typed props.                                                                                                  |
| Language             | **TypeScript 6** (`strict: true`)                                                | Strict mode is ON in `pwa/tsconfig.json`. Decorators need `experimentalDecorators` + `emitDecoratorMetadata` (required by Inversify). `target: ES2025`.                                                           |
| Styling              | **Tailwind 4.3** (via `@tailwindcss/postcss`) + Shadcn 4.16                      | **No `tailwind.config.js`** — Tailwind 4 is CSS-first. Configuration lives in `pwa/src/app/globals.css` via `@theme {}` / `@config`. Do **not** generate v3-style JS config.                                      |
| UI kit               | Shadcn, Base UI React, tw-animate-css, tailwind-merge, cva, lucide-react         | BEM class naming (`block__element--modifier`), mobile-first.                                                                                                                                                      |
| DI                   | **Inversify 8** + reflect-metadata                                               | Constructor injection of **domain interfaces** (defined in `src/context/<bc>/domain`). `reflect-metadata` is imported once per **independent entry** — `src/app/layout.tsx`, `src/context/shared/dependency-injection/infrastructure/Container.ts` and `tests/setup.ts` — because a Vitest run reaches the container without the app layout. The module is idempotent: do not "deduplicate" those, and do not add a fourth inside a component. Use `@injectable()` + `@inject()`.                    |
| Forms                | react-hook-form + `@hookform/resolvers`                                          | —                                                                                                                                                                                                                 |
| Unit tests           | **Vitest 4**                                                                     | Config: `pwa/vitest.config.ts` (v4 config API differs from v1/v2). Command: `make pwa.test.unit c='src/context/foo/bar.test.ts'`.                                                                                 |
| E2E tests            | **Playwright 1.62**                                                              | Config: `pwa/playwright.config.ts`. Runs on the **host**, never in a container. `baseURL` = `PLAYWRIGHT_BASE_URL` ?? (`CI` ? `https://localhost` : `http://127.0.0.1:3000`). The Compose stack is a prerequisite either way (the target seeds through it and the API fixtures default to `https://localhost`); what changes is the **front-end**: by default Playwright serves it from a host-spawned `dev:e2e` on `:3000`, not the containerised Next behind FrankenPHP. |
| Testing libs         | @testing-library/react 16, jest-dom 7, jsdom 30                                   | —                                                                                                                                                                                                                 |
| Lint / format        | ESLint 10.8 + `eslint-config-next` 16.2 + `eslint-config-prettier`, Prettier 3.9 | Run via `make pwa.quality`.                                                                                                                                                                                       |
| Integrations in deps | `@google/genai`                                                                  | Present — do not assume usage; check code before wiring.                                                                                                                                                          |

### Infrastructure / Dev

| Concern                        | Value                                                             | Key Constraint                                                                                                                                |
|--------------------------------|-------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------|
| Compose entrypoint             | `compose.yaml` + `compose.dev.yaml` / `compose.prod.yaml`         | Run from **repo root only**. Switch overlays with `ENV=dev\|ci\|staging\|prod`.                                                               |
| Canonical commands             | Root `Makefile` + `make/*.mk`                                     | Prefer `make <target>` over raw `docker compose` / `composer` / `npm`. The Make layer handles container routing via `ENV` and `IN_CONTAINER`. |
| Passthrough args               | `c=`                                                              | Examples: `make composer c='req vendor/pkg'`, `make php.unit c='--filter SomeTest'`, `make pwa.test.unit c='path/to/file.test.ts'`.           |
| Base images (pinned by digest) | `dunglas/frankenphp:1-php8.5`, `debian:trixie`, `node:26-trixie` | Dependabot tracks `/api` and `/pwa` — do not unpin.                                                                                           |
| Prod required env              | `APP_SECRET`, `CADDY_MERCURE_JWT_SECRET`, `POSTGRES_PASSWORD`     | Missing any → prod start fails.                                                                                                               |

### Ports

| Flow                        | Host                              | Service                                                |
|-----------------------------|-----------------------------------|--------------------------------------------------------|
| Docker dev (default)        | `http://localhost` → `:80`/`:443` | FrankenPHP (HTML proxied to Next `:3000` in-container) |
| Next container (e2e target) | `:3000`                           | `next dev --turbo -p 3000` (`dev:e2e`)                 |

## Critical Implementation Rules

### Language-Specific Rules

#### PHP (api/)

- `declare(strict_types=1);` at the top of every PHP file. No exceptions.
- PSR-12 extended coding style; enforced by PHP-CS-Fixer (`api/tools/ecs/.php-cs-fixer.dist.php`) and PHPCS.
- Type declarations on **every** parameter, return type, and property. Use union/intersection/DNF types where PHP 8.x allows.
- Prefer modern constructs: `readonly` properties/classes, promoted constructor params, `match`, nullsafe `?->`, named args for clarity, first-class callable syntax.
- Enums (backed or pure) over string/int constants for closed sets.
- Exceptions for error flow — never return codes/`false`/`null`-sentinels for errors. Create domain-specific exception types in `Domain/` (no Symfony `HttpException` inside `Domain/`).
- No `eval`, no `extract`, no variable-variables, no `@` error suppression.
- No global state: `global`, static mutable state, and service-locator patterns are forbidden; use constructor DI.
- Early returns; max nesting 3–4 levels; functions small and single-purpose.
- Never import framework/ORM/HTTP classes inside `Domain/` — domain stays pure (see `docs/rules/architecture.md`).
- Namespace root is `Erpify\` → `api/src/`. Tests: `Erpify\Tests\` → `api/tests/`. Top-level boundaries today are `Backoffice`, `Frontoffice`, `Iam`, `Organization`, `Shared` (plus `Kernel.php`) — a new feature joins one of them or earns a new one deliberately; no other file belongs at the root.
- Doctrine entities live in `Domain/Entity` and may carry **passive persistence/validation metadata** (`#[ORM\…]`, `#[Assert\…]`) — documented exception in `docs/rules/architecture.md` (example: `api/src/Backoffice/Bank/Domain/Entity/Bank.php`). Serializer `#[Groups]` are **not** carried on entities: the HTTP wire contract is owned by per-view Resource DTOs (`Application/Resource/`) mapped from the entity in `Infrastructure/Http/` — the entity is never serialized (see `docs/adr/api-resource-dtos.md`). Behavioral framework code (`EntityManagerInterface`, `Request`/`Response`, Messenger envelopes) stays out of `Domain/`.

#### TypeScript (pwa/)

- `strict: true` in `pwa/tsconfig.json` — respect it; no `// @ts-ignore` or `any` without a written reason.
- Decorators require `experimentalDecorators` + `emitDecoratorMetadata` (already set) — Inversify depends on this; do not flip off.
- `target: ES2025`. Use modern ES features (async/await, optional chaining, nullish coalescing, top-level `await` only where Next allows it).
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
- **Controllers**: thin, and they extend **nothing** — invokable `final readonly class` delegating to an Application-layer use case. No `AbstractController`, no `$this->json(...)`: the response goes through `ResourceResponder` / `JsonResponder` (zero `extends AbstractController` in `api/src`).
- **DI**: autowiring + autoconfiguration default. Register explicit services in `services.yaml` only when autowiring can't resolve (tagged iterators, multiple implementations, factories). Bind domain interfaces to infra implementations via `_defaults` + `bind:` or `instanceof`.
- **No behavioral framework types in `Domain/`**: no `Request`, `Response`, `EntityManagerInterface`, `SerializerInterface`, `HttpException`. Those belong in `Application/` (orchestration) or `Infrastructure/` (adapters). Documented exception: entities may carry passive **persistence/validation** metadata (`#[ORM]`, `#[Assert]`) — **not** `#[Groups]`, which belongs on the per-view Resource DTOs (zero `#[Groups]` exist in `api/src`). See [`rules/architecture.md`](./rules/architecture.md).
- **Validation**: Symfony Validator attributes on request DTOs in `Application/Http` (enforced by `#[MapRequestPayload]` / `#[MapQueryString]` at mapping time) **and** on domain entities as invariants, enforced by the shared `Validator::ensure(...)` before save.
- **Serialization**: never expose domain entities over HTTP. The wire contract is a per-view Resource DTO built by a `*ResourceMapper` and emitted through `ResourceResponder` / `JsonResponder`. There is no Serializer in `api/src` — zero `#[Groups]`, zero `SerializerInterface`.
- **Messenger**:
    - Commands/queries/events implement marker interfaces from `Shared/` — do **not** couple handlers to `Symfony\Messenger\*` envelopes in domain code.
    - The **`messenger_worker`** is a separate Compose service in prod/ci — handlers must be idempotent and tolerate re-delivery.
    - See `docs/architecture-api.md` before touching async email, audit, or transport config.
- **Mercure**: publish via the Mercure hub at `/.well-known/mercure`. Topics must be scoped per bounded context; never broadcast raw domain entities.
- **CORS**: edit `api/config/packages/nelmio_cors.php` (PHP config, not YAML) — never wildcard `*` for credentialed requests (see `docs/rules/security.md`).
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
    - `reflect-metadata` is imported once per independent entry — the app layout, the container module and the Vitest setup. Idempotent, so leave the three alone; never add one inside a component.
    - Bindings live per bounded context (e.g. `src/context/<bc>/infrastructure/container.ts`) and are composed into a root container.
    - Inject **domain interfaces** (from `domain/`), never concrete infra classes, into application use cases.
- **Directory discipline**:
    - `src/app/` — routing & UI shells only.
    - `src/components/` — presentational + shared UI (Shadcn primitives).
    - `src/context/<bc>/{domain,application,infrastructure}` — business logic. Shared cross-cutting code goes in `src/context/shared`, not ad-hoc folders.
    - No `src/lib/` — pure helpers/hooks are capability modules under `src/context/shared/<capability>/`.
- **UI**:
    - Shadcn UI + Tailwind 4 + BEM class naming (`block__element--modifier`). Mobile-first.
    - Icons from `lucide-react`; animations via `tw-animate-css` / CSS.
    - Use `clsx` + `tailwind-merge` (`cn()` helper) — never hand-concatenate class strings.
- **Forms**: `react-hook-form` + `@hookform/resolvers`. Validate at the resolver layer.
- **Next config**: `next.config.*` is Turbopack-aware. Webpack-only config blocks are silently ignored — don't assume they run in dev.
- **Env**:
    - `NEXT_PUBLIC_*` is public. Anything else is server-only — never read it from a client component.
    - API base URL: `NEXT_PUBLIC_API_BASE_URL`. Internal SSR fetches: `SYMFONY_INTERNAL_URL`. See `docs/integration-architecture.md`.
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

- Config: `api/tools/phpunit/phpunit.dist.xml`. Run via `make php.unit` (optional `c='--filter ClassName'`).
- Test namespace root: `Erpify\Tests\` → `api/tests/`. Mirror `src/` structure.
- Use attributes, not doc-comments: `#[Test]`, `#[DataProvider(...)]`, `#[Group('slow')]`.
- `declare(strict_types=1);` in every test file. Typed fixtures.
- Prefer in-memory repositories implementing domain interfaces over PHPUnit mock builders for domain collaborators.
- Integration tests touching Doctrine use a **real Postgres** test DB (Compose), not SQLite. Wrap each test in a transaction or reset via migrations/fixtures.
- Never commit tests that hit the network. Mock the HTTP client at the transport level.

#### PHP — Behat (api/)

- Declared in `api/composer.json` (`require-dev`); configured by `api/behat.dist.php`. Run via `make php.behat`.
- Feature files describe business behavior, not endpoints. Step definitions live in `api/tests/Behat/`.
- Drive the app via HTTP (Mink/BrowserKit) — do not bootstrap the Symfony kernel directly from Behat steps.
- Fixtures: Hautelook Alice via `make db.load.fixtures`. Reset DB between mutating scenarios.

#### JS — Vitest 4 (pwa/)

- Config: `pwa/vitest.config.ts`. Run via `make pwa.test.unit` (optional `c='src/context/foo/bar.test.ts'`; watch: `make pwa.test.unit.watch`).
- Test files under `tests/` mirroring `src/`. Name `*.test.ts` / `*.test.tsx`.
- Use `@testing-library/react` + `@testing-library/jest-dom`. Query by role/label/text — never by CSS class or test ID when an accessible query works.
- No shallow rendering. Render real components.
- Mock at module boundary with `vi.mock(...)`; prefer injecting fakes via the Inversify container over global mocks.
- Async: use `findBy*` / `waitFor` — never `setTimeout` sleeps.

#### JS — Playwright 1.62 (pwa/)

- Config: `pwa/playwright.config.ts`. Run via `make pwa.test.e2e`. Reports: `make pwa.test.e2e.reports`.
- `baseURL` comes from `PLAYWRIGHT_BASE_URL` ?? (`CI` ? `https://localhost` : `http://127.0.0.1:3000`) — never hard-code it in a spec. Nothing in the Make layer sets either variable, so the **default** run resolves `:3000` and `useWebServer` is true: Playwright serves the front-end from its own `dev:e2e` (skipping the spawn if something already answers on `:3000`). The API side is the Compose stack regardless — `pwa.test.e2e` seeds through `docker compose exec`, the spawned Next gets `NEXT_PUBLIC_API_BASE_URL=https://localhost`, and `apiBaseURL()` in `pwa/tests/e2e/fixtures/api.ts` falls back to the same.
- **Anything needing same-origin HTTPS must be told so explicitly**: a cookie scoped to `https://localhost` is not sent to a browser sitting on `http://127.0.0.1:3000`. `PLAYWRIGHT_BASE_URL=https://localhost make pwa.test.e2e` is the documented form (see the header of `pwa/tests/e2e/backoffice/banks-realtime.spec.ts` for the Mercure `SameSite=Strict` case); from a worktree use that stack's mapped 443 port, since Playwright is on the host and cannot reach the internal Docker network.
- Each spec independent: create its own data, clean up after. No ordering between specs.
- Locators: role/text based (`getByRole`, `getByLabel`). CSS/XPath selectors last resort.
- Never sleep. Use `expect(locator).toBeVisible()` / `toHaveText()` auto-waiting.
- Share login via Playwright `storageState`, not sequential runs.

#### Coverage & gates

- Critical business logic in `Domain/` and `context/<bc>/domain` **must** be unit-tested. Adapters covered by integration/e2e.
- All existing and new tests must pass 100% before a story is done (`docs/rules/testing.md`, `bmad-agent-dev` principle).
- CI runs `make ci.test` — verify locally with `make app.test` before pushing.

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

- **API (`api/src/`)**: `Backoffice/ | Frontoffice/ | Iam/ | Organization/ | Shared/` top-level, each with `Domain/ Application/ Infrastructure/`. New features choose a bounded context and create the three folders if needed — don't sprinkle files at the root of a context.
- **PWA (`pwa/src/`)**: `app/` (routes), `components/` (UI), `context/<bc>/{domain,application,infrastructure}` (business logic). Shared cross-cutting code (helpers, hooks, ports + adapters) lives in `src/context/shared` capability modules, not ad-hoc folders.
- Tests mirror source trees (`api/tests/`, `pwa/tests/`).

#### Code Quality - Linting / Formatting — tools are authoritative

- **PHP**: PHP-CS-Fixer (`api/tools/ecs/.php-cs-fixer.dist.php`), PHPCS, PHPStan 2 (`api/tools/phpstan/phpstan.neon`, `level: max` — sole type gate), Rector 2, PHPMD. Run all via `make php.quality`. Don't hand-format against these tools.
- **JS/TS**: ESLint 10 + `eslint-config-next` + `eslint-config-prettier`, Prettier 3.9. Run via `make pwa.quality`; fix via `make pwa.lint` / `make pwa.format`. Don't hand-format.
- **All files**: `.editorconfig` wins. LF line endings, no mixed line endings, keep files small — conventions only; no hook enforces them locally.
- Aggregates: `make app.quality` (both sides), `make ci` (`ci.quality` + `ci.test`).

#### UI / CSS (pwa/)

- Tailwind utility-first. No inline `style=` unless dynamic value requires it.
- BEM for custom classes: `block__element--modifier`. Mobile-first breakpoints.
- Compose classes with `cn()` (clsx + tailwind-merge) — never string-concatenate class names.
- Shadcn primitives are the base — extend via `components/ui/` customizations, don't fork upstream files in place.
- Accessibility: semantic HTML, proper ARIA, keyboard nav, visible focus, sufficient color contrast. Every interactive element is reachable via keyboard.

#### Documentation

- Public APIs (controller routes, message types, domain services) get a one-line purpose docblock only when the name alone is insufficient.
- Non-obvious decisions, workarounds, and invariants get a short `// why: ...` comment with the reason.
- Keep `api/README.md`, and `docs/` in sync when behavior changes. `PRODUCTION_SECURITY_CHECKLIST.md` is authoritative — update it on any security-sensitive change (see `docs/rules/security.md`).

### Development Workflow Rules

#### Make-first execution

- The root `Makefile` + `make/*.mk` is the canonical interface. Prefer `make <target>` over raw `docker compose` / `composer` / `npm` / linter calls.
- Run Make targets from **repo root**, never from `api/` or `pwa/`.
- Environment overlay: `ENV=dev|ci|staging|prod` (default `dev`). `IN_CONTAINER` is handled by the Make layer — do not invoke container exec directly.
- Common: `make docker.up | docker.down | docker.logs | docker.ps | php.bash | docker.down.clean-volumes`. `docker.down.clean-volumes` is **destructive** (drops volumes) — confirm before use.
- Passthrough: `c='...'` — e.g. `make composer c='req vendor/pkg'`, `make php.unit c='--filter X'`, `make pwa.test.unit c='src/context/foo/bar.test.ts'`.
- DB: `make db.migrate | db.diff | db.status | db.validate | db.load.fixtures | db.shell`. `db.reset` is **destructive** (drop → migrate → fixtures) — only on dev/ci.

#### Branches

- `main` is the trunk. Never force-push to `main`.
- Feature branches: `feat/<scope>-<slug>` (e.g. `feat/invoice-export`).
- Fix branches: `fix/<scope>-<slug>`.
- Chore/CI/docs: `chore/...`, `ci/...`, `docs/...`.
- Keep branches short-lived; rebase onto `main` rather than merging `main` in repeatedly.

#### Commits (Conventional Commits — convention, not a gate)

- Format: `<type>(<scope>): <subject>` — subject **lower-case**, imperative, no trailing period.
- Types: `feat | fix | docs | style | refactor | perf | test | build | ci | chore | revert`.
- Optional body explains **why**; reference issues in the footer (`Closes #123`).
- **Nothing enforces this locally.** No `.pre-commit-config.yaml` exists anywhere in the repo and no git hook is installed, so a malformed subject, a trailing-whitespace diff, or a committed secret is caught by review and CI — never by your commit. `docs/contribution-guide.md` still prints a `pre-commit install` recipe: running it does **not** fail, it installs a hook that then aborts every subsequent commit with `No .pre-commit-config.yaml file was found`. Treat the hooks as absent until someone lands a config; write as if they existed, and never assume they caught anything.
- Create new commits rather than amending; prefer small, focused commits.
- Before committing, run security checks per `docs/rules/security.md` and update `PRODUCTION_SECURITY_CHECKLIST.md` when security-relevant files change.

#### Pull requests

- Target `main`. Title mirrors the primary commit's Conventional Commit subject.
- Body: **what** changed, **why**, and a **test plan** (bulleted checklist). Include screenshots for UI changes.
- CI must be green (`make ci` equivalent + SuperLinter via `make super-lint.fast` if touched).
- At least one review. Security-sensitive changes require the checklist update in the PR body.
- Don't push directly to `main`. Don't force-push shared branches without coordinating.

#### Deployment

- Dev: `make docker.up` from repo root.
- CI/Staging/Prod: `make docker.up ENV=ci|staging|prod` — overlays `compose.dev.yaml` or `compose.prod.yaml` accordingly.
- **Prod requirements** (missing any → start fails): `APP_SECRET`, `CADDY_MERCURE_JWT_SECRET`, `POSTGRES_PASSWORD`.
- Prod Compose runs a separate `messenger_worker` service and a mailer pipeline — see `docs/deployment-guide.md` before deploying behavior changes that touch async flows.
- Base images are pinned by sha256 digest (`dunglas/frankenphp:1-php8.5`, `debian:trixie`, `node:26-trixie`); Dependabot at `/api` and `/pwa` handles digest bumps. Do not unpin.
- DNS, CORS origins, and Mercure cookie/CORS config per `docs/deployment-guide.md` and `pwa/docs/production-deployment.md`. After deploy, run the documented smoke tests.

#### Local traffic model

- **Docker dev (default)**: browser → `http(s)://localhost` → FrankenPHP. HTML `/` is proxied to Next (`:3000` in-container). `/api/*` and `/.well-known/mercure` stay on PHP. See `docs/integration-architecture.md`.

### Critical Don't-Miss Rules

#### Architecture anti-patterns — will break the repo's invariants

- ❌ Importing Symfony / Doctrine / HTTP / DI-container types inside `Domain/` (API) or `src/context/<bc>/domain/` (PWA). Domain stays pure.
- ❌ Cross-context reach-ins: `Backoffice` code accessing `Frontoffice/Domain` or `Frontoffice/Infrastructure` directly. Cross via Application services or domain events only.
- ❌ Adding `symfony/symfony` metapackage to `api/composer.json` — it is in `conflict`. Require individual components.
- ❌ Adding `symfony/polyfill-*` packages that are already in the `replace` block.
- ❌ Creating a `tailwind.config.js` in `pwa/`. Tailwind 4 config lives in CSS (`@theme`/`@config`).
- ❌ Creating a `pwa/pages/` directory. App Router only.
- ❌ Default exports under `src/context/**`. Named exports only (Next's `page.tsx`/`layout.tsx` are the exception).
- ❌ Using `React.FC`, `enzyme`, shallow rendering, or class components.
- ❌ Invoking `docker compose`, `composer`, or `npm` directly from `api/` or `pwa/` subdirs. Go through `make` from repo root.

#### Runtime gotchas

- Playwright's `baseURL` is environment-resolved, and the default front-end is **not** the containerised Next: with no `PLAYWRIGHT_BASE_URL` and no `CI` the browser sits on a host-spawned `dev:e2e` at `http://127.0.0.1:3000` while the API stays on `https://localhost`. Cross-origin from the cookie's point of view — set `PLAYWRIGHT_BASE_URL=https://localhost` for anything that needs same-origin.
- Doctrine ORM 3 / DBAL 4: no `flush($entity)`, no `fetchAll()`, no `Connection::query()`, no `iterate()`. Use `toIterable()`, `fetchAllAssociative()`, `executeQuery()`.
- Turbopack is the dev bundler. Webpack-only `next.config.*` blocks silently no-op.
- `messenger_worker` is a **separate Compose service** in prod/ci. Handlers must be idempotent; delivery is at-least-once.
- `reflect-metadata` belongs at an **entry**, never in a component: `src/app/layout.tsx`, `Container.ts` and `tests/setup.ts` each import it because each can be reached without the others.
- Mercure client must hit **same-origin** `/.well-known/mercure`. Don't hardcode cross-origin URLs.

#### Security (authoritative: `docs/rules/security.md`)

- Never commit secrets: `.env`, credentials, API keys, tokens. **Nothing scans your commit** (no pre-commit config, no installed hook) — reviewing the diff yourself is the only control.
- No debug artifacts (`var_dump`, `print_r`, `dd()`, `console.log`) in committed code.
- SQL only via Doctrine DBAL parameterized APIs or ORM. No string-concatenated SQL. No `eval`.
- CORS: no wildcard `*` for credentialed requests.
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
- Create a new commit rather than amending a pushed one.
- Never force-push `main` or shared branches without coordination.
- Never destructive-delete (`rm -rf`, `db.reset`, `docker.down.clean-volumes`, `git reset --hard`) without explicit confirmation.
- Never introduce a new package manager, build tool, or framework without an approved story.

---

## Usage Guidelines

**For AI Agents:**

- Read this file before implementing any code.
- Follow all rules exactly as documented. When in doubt, prefer the more restrictive option.
- Defer to the codebase over training-data defaults — PHP 8.5, Next 16, React 19, Tailwind 4, Doctrine 3/DBAL 4, and Inversify 8 are all beyond common training cutoffs.
- When a rule here conflicts with `docs/rules/*.md`, `pwa/CLAUDE.md`, or `api/CLAUDE.md`, flag the conflict rather than silently picking one.

**For Humans:**

- Keep this file lean and focused on non-obvious agent needs. Don't restate what the code already shows.
- Update when the stack changes (new major versions, new bounded contexts, new tooling).
- Review quarterly and delete rules that have become obvious or no longer apply.

**Verify before trusting a line — nothing here is gated.** No check reads this file, so it drifts silently while every gate stays green, and it drifts *toward* confident wrongness: a version number merely ages, but a stale behavioural claim teaches the opposite of what the code does. Re-measuring is cheaper than trusting:

```bash
# paths this file asserts, for the prefixes below
grep -oE '`(api|pwa|docs|tools)/[a-zA-Z0-9._/-]+`' docs/project-context.md \
  | tr -d '`' | grep -vE '(^|/)vendor/|/$|\.\.\.' | sort -u \
  | while read -r p; do [ -e "$p" ] || echo "MISSING $p"; done
# versions come from the manifests, never from memory
python3 -c "import json;d=json.load(open('pwa/package.json'));print({**d['dependencies'],**d['devDependencies']})"
# base images are digests, not tags — resolve them upstream, don't read the tag off this file
grep -E '^FROM .*@sha256:' api/Dockerfile pwa/Dockerfile
```

**What a clean run does not cover**, so nobody reads it as an all-clear:

- Only paths **written with** an `api|pwa|docs|tools` prefix. Everything else this file names is invisible to it — `Makefile`, `make/*.mk`, `compose*.yaml`, `next.config.*`, `.editorconfig`, `PRODUCTION_SECURITY_CHECKLIST.md` — and so is a doc-relative path such as `` `rules/architecture.md` ``, which resolves only from `docs/`.
- Only paths whose characters fit `[a-zA-Z0-9._/-]`, so a glob like `` `docs/rules/*.md` `` never matches — and the Markdown rule in `CLAUDE.md` requires writing globs that way.
- Only paths in **inline code**. A Markdown link target is never backticked, so no `](…)` in this file is ever checked.
- Deliberate skips: `vendor/` (gitignored, absent in a fresh worktree), trailing-slash directories (prose), and `...` (branch-name patterns).
- **Nothing about whether a claim is true.** A path can resolve while the sentence around it describes behaviour the code no longer has; only reading the code catches that, and behavioural claims are where the damage is.

Last Updated: 2026-08-17
