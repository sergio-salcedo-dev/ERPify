# Source Tree Analysis

Annotated layout of the ERPify monorepo. Only critical directories are shown; generated output (`node_modules`, `vendor`, `var`, `.next`) is omitted.

```text
ERPify/
├── api/                            # Symfony API (Part: api)
│   ├── src/
│   │   ├── Kernel.php              # Symfony kernel (entry)
│   │   ├── Backoffice/
│   │   │   ├── Bank/               # Bank bounded context
│   │   │   │   ├── Application/    # Use cases, DTOs (Application/Http/)
│   │   │   │   ├── Domain/         # Entity, Event, Exception, Repository (framework-free)
│   │   │   │   └── Infrastructure/ # Controller, Messenger (event subscribers `<Effect>On<Event>`, see docs/rules/cqrs-naming.md), Persistence, Request, Serializer, Storage
│   │   │   └── Health/
│   │   │       └── Infrastructure/Controller
│   │   ├── Frontoffice/
│   │   │   ├── Dev/Infrastructure/Controller
│   │   │   ├── Health/Infrastructure/Controller
│   │   │   └── Mercure/            # Mercure publishing & JWT (Domain + Infrastructure/Controller)
│   │   └── Shared/                 # kernel trio + capability modules — see docs/adr/shared-module-organization.md
│   │       ├── Application/        # kernel: Problem (RFC 9457 mapping), UseCase (Result)
│   │       ├── Domain/             # kernel: Aggregate, Entity, Enum, Exception (base + markers), Uuid, ValueObject
│   │       ├── Infrastructure/     # kernel: Http (CorrelationId + Problem responders, ExceptionResponder), Persistence, Serializer
│   │       ├── Clock/              # time: Clock port (Domain) + Symfony/native adapters (Infrastructure)
│   │       ├── Event/              # event backbone (Domain/Application/Infrastructure): DomainEvent + EventBus, raw-DBAL event_store, mapper/serializer/upcaster, projection runner; see docs/adr/event-store-and-projections.md
│   │       ├── Mailer/             # notification mail: port (Application) + plain-text adapter (Infrastructure)
│   │       ├── Media/              # in-DB media (Intervention Image) — full DDD layering
│   │       ├── Monitoring/         # Sentry before_send adapters (event scrubbing/filtering)
│   │       ├── Search/             # filters + keyset engine + cursor envelope (Domain/Application/Infrastructure)
│   │       ├── Storage/            # Flysystem object storage (Domain/Application/Infrastructure)
│   │       └── Validation/         # Validator helper (Application) + EnumType constraint (Infrastructure)
│   ├── config/
│   │   ├── bundles.php
│   │   ├── services.yaml           # DI autoconfigure defaults
│   │   ├── services_test.yaml      # Test-only service overrides (YAML, not PHP)
│   │   ├── routes.yaml             # Attribute routing entry
│   │   └── packages/               # Doctrine, Doctrine migrations, Messenger, Mercure, Mailer, Flysystem, Media, Nelmio CORS (PHP), Validator, Property Info, Cache, Framework, Routing, Monolog, Hautelook Alice
│   ├── migrations/
│   │   └── 2026/                   # Doctrine migrations (timestamped per year)
│   ├── tests/                      # PHPUnit (mirrors src/) + Functional + Behat context
│   ├── features/                   # Behat .feature files (BDD)
│   ├── tools/
│   │   └── behat/                  # Isolated Composer tree for Behat (Symfony 8 / Behat 3 conflict isolation)
│   ├── public/                     # FrankenPHP doc-root
│   ├── frankenphp/                 # Caddyfile + worker entry
│   ├── docs/                       # API-specific docs (upstream symfony-docker + local additions)
│   ├── composer.json
│   ├── phpunit.xml.dist
│   ├── tools/phpstan/phpstan.neon  # PHPStan (level: max) — sole type-checking gate
│   ├── tools/psalm/psalm-taint.xml # Psalm taint-only (security dataflow → SARIF)
│   ├── rector.php
│   ├── .php-cs-fixer.php
│   └── Dockerfile                  # FrankenPHP-based image (digest-pinned base)
│
├── pwa/                            # Next.js PWA (Part: pwa)
│   ├── src/
│   │   ├── app/                    # App Router (entry: layout.tsx + page.tsx)
│   │   │   ├── layout.tsx
│   │   │   ├── page.tsx
│   │   │   ├── globals.css         # Tailwind 4 CSS-first config (@theme / @config)
│   │   │   ├── backoffice/         # Backoffice route segment
│   │   │   │   └── health/
│   │   │   └── status/             # Public status page (Atlassian-style, reuses FrontOffice health use case)
│   │   ├── components/
│   │   │   ├── ui/                 # Shadcn primitives + shared UI
│   │   │   └── erpify/             # Project-specific components
│   │   ├── context/                # Business logic by bounded context
│   │   │   ├── backoffice/health/{application,domain,infrastructure}
│   │   │   ├── frontoffice/health/{application,domain,infrastructure}
│   │   │   └── shared/
│   │   │       ├── domain/
│   │   │       └── infrastructure/{DependencyInjection,HttpClient,ui}  # Inversify wiring + HTTP client + shared UI
│   │   └── lib/                    # Glue/util only
│   ├── tests/                      # Mirrors src/ (Vitest unit + Playwright e2e)
│   ├── docs/                       # PWA-specific docs (prod deploy, etc.)
│   ├── package.json
│   ├── tsconfig.json               # strict: true, experimentalDecorators, emitDecoratorMetadata (Inversify)
│   ├── vitest.config.ts
│   ├── playwright.config.ts
│   ├── next.config.*               # Turbopack-aware
│   ├── eslint.config.*
│   └── Dockerfile                  # node:24-alpine (digest-pinned)
│
├── docs/                           # Repo-wide docs (primary AI retrieval source)
│   ├── index.md                    # ← Start here
│   ├── project-context.md          # ← AI agent rules (load before generating code)
│   ├── project-overview.md
│   ├── architecture-api.md
│   ├── architecture-pwa.md
│   ├── integration-architecture.md
│   ├── api-error-contract.md       # RFC 9457 Problem Details contract
│   ├── source-tree-analysis.md
│   ├── development-guide-api.md
│   ├── development-guide-pwa.md
│   ├── deployment-guide.md
│   └── contribution-guide.md
│
├── .github/workflows/
│   └── ci.yml                      # Lint + test pipeline
│
├── docs/rules/                  # Authoritative coding rules (architecture, security, testing, …)
├── _bmad/                          # BMad module config
├── _bmad-output/                   # BMad outputs (planning + implementation artifacts)
├── binaries/ scripts/              # Local scripts/tools
├── make/                           # Make modules (api, ci, codeql, composer, config, db, dev, docker, git, help, js*, npm, php*, pwa, super-linter, utils)
├── Makefile                        # Canonical entrypoint — includes make/*.mk
├── compose.yaml                    # Base Compose (php, pwa, postgres, messenger_worker)
├── compose.dev.yaml                # Dev overlay (bind mounts, hot reload)
├── compose.prod.yaml               # Prod overlay (worker, mailer)
├── CLAUDE.md                       # Project-wide Claude Code guidance
└── README.md                       # Repo entry point
```

## Entry points

- **API HTTP**: FrankenPHP → `api/public/index.php` → `Erpify\Kernel` (`api/src/Kernel.php`).
- **API async workers**: `messenger_worker` Compose service running `php bin/console messenger:consume async --time-limit=3600`.
- **PWA**: `pwa/src/app/layout.tsx` + `pwa/src/app/page.tsx` (App Router defaults).

## Integration points

All browser traffic terminates at **FrankenPHP on `localhost`**. `/` is reverse-proxied to Next (`:3000` in-container); `/api/*` and `/.well-known/mercure` stay on PHP. See [`integration-architecture.md`](./integration-architecture.md).

## Critical folders — quick reference

| Path                                                         | Purpose                                                                                             |
|--------------------------------------------------------------|-----------------------------------------------------------------------------------------------------|
| `api/src/{Backoffice,Frontoffice,Shared}/*/Domain/`          | Pure domain — no framework imports                                                                  |
| `api/src/Shared/Domain/Exception/`                           | Marker interfaces + `DomainException` base — see [`api-error-contract.md`](./api-error-contract.md) |
| `api/src/Shared/Application/Problem/`                        | `ProblemDetails` VO + `ProblemDetailsFactory` (single throwable→wire mapping site)                  |
| `api/src/Shared/Event/{Domain,Application,Infrastructure}/`   | Event backbone: `EventBus` port + `SymfonyMessengerEventBus`, hardened `DomainEvent` (`fromPrimitives` + injectable identity), raw-DBAL reproducible `event_store` (mapper/serializer/upcaster), and the projection runner (projector ≠ reactor, checkpointed catch-up + rebuild). Committed atomically with persistence at the use-case boundary; gate `make php.lint.event-bus`, see [`adr/event-store-and-projections.md`](./adr/event-store-and-projections.md) |
| `api/src/Shared/Infrastructure/Http/`                        | `CorrelationIdListener`, `ProblemDetailsResponder`, `EventListener/ExceptionResponder`              |
| `api/src/Shared/Search/Infrastructure/Persistence/Doctrine/` | `FilterApplier` + `SearchFieldMap` — shared search-filter plumbing (mandatory allow-list); `DoctrineSearchEngine` (the sole keyset query-shaper, 8-step pipeline) + `RowUniquenessGuard`, composing the pure `Keyset/` kernel. It governs the live HTTP read-path (Bank), producing a link-agnostic `Page` with opaque cursors; `Shared/Search/Infrastructure/Http/SearchResponder` is the single compositor of the cursor-only envelope |
| `api/src/*/Application/`                                     | Use cases, DTOs, orchestration                                                                      |
| `api/src/*/Infrastructure/`                                  | Doctrine, HTTP, Messenger adapters                                                                  |
| `api/config/packages/`                                       | All bundle config (Doctrine, Messenger, Mercure, …)                                                 |
| `api/migrations/2026/`                                       | Doctrine migrations (timestamped per year)                                                          |
| `api/tools/behat/`                                           | Isolated Behat Composer tree — never add Behat deps to `api/composer.json`                          |
| `api/features/`                                              | Behat `.feature` files                                                                              |
| `pwa/src/app/`                                               | App Router routes & UI shells only                                                                  |
| `pwa/src/context/<bc>/{domain,application,infrastructure}/`  | Business logic per bounded context                                                                  |
| `pwa/src/components/ui/`                                     | Shadcn primitives                                                                                   |
| `pwa/src/components/erpify/`                                 | Project-specific components                                                                         |
| `pwa/src/context/shared/infrastructure/DependencyInjection/` | Inversify container wiring                                                                          |
| `pwa/src/lib/`                                               | Glue / utilities only                                                                               |
| `docs/`                                                      | Primary AI retrieval source — start at `index.md`                                                   |
| `docs/rules/`                                                | Authoritative coding rules                                                                          |
| `make/`                                                      | Make modules included by root `Makefile`                                                            |
