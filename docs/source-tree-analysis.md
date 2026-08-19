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
│   │   │   │   └── Infrastructure/ # Controller, Http, Messenger (event subscribers `<Effect>On<Event>`, see docs/rules/cqrs-naming.md), Persistence, Projection, Security
│   │   │   ├── BankAccount/        # BankAccount context — references Bank by id only (no object graph crosses the boundary; see docs/adr/bank-bankaccount-modeling.md)
│   │   │   │   ├── Application/    # Use cases, DTOs
│   │   │   │   ├── Domain/         # Entity, Event, Exception, Repository (framework-free)
│   │   │   │   └── Infrastructure/ # Audit, Controller, Persistence
│   │   │   └── Health/
│   │   │       └── Infrastructure/Controller
│   │   ├── Frontoffice/
│   │   │   ├── Dev/Infrastructure/Controller
│   │   │   ├── Health/Infrastructure/Controller
│   │   │   └── Mercure/            # Mercure publishing & JWT (Domain + Infrastructure/Controller)
│   │   ├── Iam/                    # identity & access — promoted from Backoffice/Identity (adr/auth-rbac-subsystem.md D2 trigger)
│   │   │   ├── Identity/           # User aggregate + session firewall + parked RBAC core (Permission/AuthorizationPolicy/PermissionVoter) — adr/auth-rbac-subsystem.md, adr/rbac-authorization-model.md
│   │   │   ├── Invitation/         # reserved skeleton — identity/invitation epic
│   │   │   └── Session/            # reserved skeleton — identity/invitation epic
│   │   ├── Organization/           # tenancy — multi-tenant-ready, operation deferred (adr/identity-invitation-lifecycle.md D2)
│   │   │   ├── Organization/       # tenant aggregate — one org per installation, CLI bootstrap (organization:provision / :administrator:create)
│   │   │   └── Membership/         # authoritative user↔org link (belonging only — roles are authoritative on User); references User/Organization by id
│   │   └── Shared/                 # minimal Kernel + capability modules — see docs/adr/shared-module-organization.md
│   │       ├── Kernel/             # DDD building blocks — [Domain] Aggregate, Entity (Identifiable/Timestamped), Enum (Currency), ValueObject (NormalizedText); [Application] Result
│   │       ├── ErrorContract/      # RFC 9457 pipeline — [Domain] Exception taxonomy; [Application] ProblemDetailsFactory/ProblemDetails/RedactionDenylist; [Infrastructure] ProblemDetailsResponder + ExceptionResponder/RateLimitListener
│   │       ├── Uuid/               # identity VO — [Domain] Uuid + InvalidUuidException
│   │       ├── Http/               # HTTP adapters — [Infrastructure] CorrelationIdListener, content-addressed cache, JSON/Resource responders
│   │       ├── Serialization/      # serialization adapters — [Infrastructure] JsonDecoder + ResourceNormalizer
│   │       ├── Persistence/        # persistence adapters — [Infrastructure] DoctrineConnectionResetListener + QueryParam
│   │       ├── Clock/              # time: Clock port (Domain) + Symfony/native adapters (Infrastructure)
│   │       ├── Event/              # event backbone (Domain/Application/Infrastructure): DomainEvent + EventBus, raw-DBAL event_store, mapper/serializer/upcaster, projection runner; see docs/adr/event-store-and-projections.md
│   │       ├── Audit/              # operational/actor audit axis (Domain/Application/Infrastructure): AuditLogger seam + AuditPolicy, raw-DBAL audit_log, hybrid capture (Infrastructure/Http: kernel.terminate access-log → activity, AccessDenied → security); see docs/adr/audit-activity-log.md
│   │       ├── Mailer/             # notification mail: port (Application) + plain-text adapter (Infrastructure)
│   │       ├── Monitoring/         # Sentry before_send adapters (event scrubbing/filtering)
│   │       ├── Search/             # filters + keyset engine + cursor envelope (Domain/Application/Infrastructure)
│   │       └── Validation/         # Validator helper (Application) + EnumType constraint (Infrastructure)
│   ├── config/
│   │   ├── bundles.php
│   │   ├── services.yaml           # DI autoconfigure defaults
│   │   ├── services_test.yaml      # Test-only service overrides (YAML, not PHP)
│   │   ├── routes.yaml             # Attribute routing entry
│   │   └── packages/               # Doctrine, Doctrine migrations, Messenger, Mercure, Mailer, Nelmio CORS (PHP), Validator, Property Info, Cache, Framework, Routing, Monolog, Hautelook Alice
│   ├── migrations/
│   │   └── 2026/                   # Doctrine migrations (timestamped per year)
│   ├── tests/                      # PHPUnit (mirrors src/) + Functional + Behat context
│   ├── features/                   # Behat .feature files (BDD)
│   ├── behat.dist.php              # Behat 4 config (suites, contexts, gherkin mode)
│   ├── tools/                      # Per-tool configs + isolated Composer trees (phpstan, phpunit, phpmd, …)
│   ├── public/                     # FrankenPHP doc-root
│   ├── frankenphp/                 # Caddyfile + worker entry
│   ├── docs/                       # API-specific docs (upstream symfony-docker + local additions)
│   ├── composer.json
│   ├── phpunit.xml.dist
│   ├── tools/phpstan/phpstan.neon  # PHPStan (level: max) — sole type-checking gate
│   ├── rector.php
│   ├── .php-cs-fixer.php
│   └── Dockerfile                  # FrankenPHP-based image (digest-pinned base)
│
├── pwa/                            # Next.js PWA (Part: pwa)
│   ├── src/
│   │   ├── proxy.ts                # Next request-forwarding proxy entry
│   │   ├── app/                    # App Router (entry: layout.tsx + page.tsx)
│   │   │   ├── layout.tsx
│   │   │   ├── page.tsx
│   │   │   ├── globals.css         # Tailwind 4 CSS-first config (@theme / @config)
│   │   │   ├── error.tsx / global-error.tsx / not-found.tsx / forbidden.tsx / unauthorized.tsx  # route error boundaries
│   │   │   ├── _components/        # App-shell components (route-private)
│   │   │   ├── (auth)/             # Auth route group (login, register, forgot/reset-password)
│   │   │   ├── (errors)/           # Error/maintenance route group (offline, rate-limited, unauthenticated, …)
│   │   │   ├── backoffice/         # Backoffice shell + 32 feature route segments (banks, users, invoices, … — many scaffolded shells)
│   │   │   └── status/             # Public status page (reuses FrontOffice health use case)
│   │   ├── components/
│   │   │   ├── ui/                 # Base UI primitives (@base-ui/react) + shared UI
│   │   │   └── erpify/             # Project-specific components
│   │   └── context/                # Business logic by bounded context — each capability is a vertical slice carrying only the DDD layers it needs
│   │       ├── backoffice/         # bank, bankaccount, health, user  — {application,domain,infrastructure}
│   │       ├── frontoffice/        # health  — {application,domain,infrastructure}
│   │       └── shared/             # capability modules (the dissolved shared kernel): access · date-time-provider · debug-token ·
│   │                               #   dependency-injection (Inversify) · dev-tools · environment · error (ProblemDetails) ·
│   │                               #   http-client · keyboard · navigation (safeHref) · notification · observability · rate-limit ·
│   │                               #   real-time (Mercure) · resource · routing · search · storage · styling · system-status ·
│   │                               #   theme · uuid · validation (Zod) · view-state
│   ├── tests/                      # Mirrors src/ (Vitest unit + Playwright e2e) + repo-guardrail tests (data-testid uniqueness, env allowlist, …)
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
│   ├── index.md                    # ← Start here (generated index)
│   ├── project-context.md          # ← AI agent rules (load before generating code)
│   ├── project-overview.md
│   ├── architecture-api.md
│   ├── architecture-pwa.md
│   ├── integration-architecture.md
│   ├── bounded-contexts.md
│   ├── api-error-contract.md       # RFC 9457 Problem Details contract
│   ├── background-jobs-and-scheduling.md
│   ├── source-tree-analysis.md
│   ├── development-guide-api.md
│   ├── development-guide-pwa.md
│   ├── deployment-guide.md         # (+ vps-deployment.md, erpify-local-test-deployment.md)
│   ├── contribution-guide.md
│   ├── claude-code-quickref.md     # Full command catalog & recipes
│   ├── product-roadmap.md          # (+ saas-production-roadmap.md)
│   ├── adr/                        # Architecture decision records (bank-bankaccount-modeling, event-store-and-projections, …)
│   └── rules/                      # Authoritative coding rules (architecture, security, testing, …)
│
├── .github/workflows/
│   ├── ci.yml                      # Lint + test pipeline
│   └── codeql.yml                  # CodeQL security scanning
│
├── _bmad/                          # BMad module config
├── _bmad-output/                   # BMad outputs (planning + implementation artifacts)
├── design-artifacts/               # WDS design pipeline (product brief → trigger map → UX scenarios → design system → development)
├── docs-info/                      # Supplementary topic notes (mercure, local-fullstack-traffic, production-deployment, …)
├── binaries/ scripts/              # Local scripts/tools
├── make/                           # Make modules (ci, codeql, composer, config, db, deploy, docker, git, help, php, php-quality, php-test, pwa, super-lint, symfony, worktree, xdebug)
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

| Path                                                          | Purpose                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
|---------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `api/src/{Backoffice,Frontoffice,Iam,Organization,Shared}/*/Domain/` | Pure domain — no framework imports                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| `api/src/Shared/ErrorContract/Domain/Exception/`              | Marker interfaces + `DomainException` base — see [`api-error-contract.md`](./api-error-contract.md)                                                                                                                                                                                                                                                                                                                                                                 |
| `api/src/Shared/ErrorContract/Application/`                   | `ProblemDetails` VO + `ProblemDetailsFactory` (single throwable→wire mapping site)                                                                                                                                                                                                                                                                                                                                                                                  |
| `api/src/Shared/Event/{Domain,Application,Infrastructure}/`   | Event backbone: `EventBus` port + `SymfonyMessengerEventBus`, hardened `DomainEvent` (`fromPrimitives` + injectable identity), raw-DBAL reproducible `event_store` (mapper/serializer/upcaster), and the projection runner (projector ≠ reactor, checkpointed catch-up + rebuild). Committed atomically with persistence at the use-case boundary; gate `make php.lint.event-bus`, see [`adr/event-store-and-projections.md`](./adr/event-store-and-projections.md) |
| `api/src/Shared/ErrorContract/Infrastructure/Http/`           | `ProblemDetailsResponder`, `EventListener/ExceptionResponder`, `EventListener/RateLimitListener`                                                                                                                                                                                                                                                                                                                                                                    |
| `api/src/Shared/Http/Infrastructure/`                         | `CorrelationIdListener`, content-addressed cache, JSON/Resource responders                                                                                                                                                                                                                                                                                                                                                                                          |
| `api/src/Shared/Search/Infrastructure/Persistence/Doctrine/`  | `FilterApplier` + `SearchFieldMap` — shared search-filter plumbing (mandatory allow-list); `DoctrineSearchEngine` (the sole keyset query-shaper, 8-step pipeline) + `RowUniquenessGuard`, composing the pure `Keyset/` kernel. It governs the live HTTP read-path (Bank), producing a link-agnostic `Page` with opaque cursors; `Shared/Search/Infrastructure/Http/SearchResponder` is the single compositor of the cursor-only envelope                            |
| `api/src/*/Application/`                                      | Use cases, DTOs, orchestration                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| `api/src/*/Infrastructure/`                                   | Doctrine, HTTP, Messenger adapters                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| `api/config/packages/`                                        | All bundle config (Doctrine, Messenger, Mercure, …)                                                                                                                                                                                                                                                                                                                                                                                                                 |
| `api/migrations/2026/`                                        | Doctrine migrations (timestamped per year)                                                                                                                                                                                                                                                                                                                                                                                                                          |
| `api/features/`                                               | Behat `.feature` files                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| `pwa/src/app/`                                                | App Router routes & UI shells only                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| `pwa/src/context/<bc>/{domain,application,infrastructure}/`   | Business logic per bounded context                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| `pwa/src/components/ui/`                                      | Base UI primitives (`@base-ui/react`) + shared UI                                                                                                                                                                                                                                                                                                                                                                                                                   |
| `pwa/src/components/erpify/`                                  | Project-specific components                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| `pwa/src/context/shared/dependency-injection/infrastructure/` | Inversify container wiring                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| `pwa/src/context/shared/<capability>/`                        | Capability modules — the dissolved shared kernel (e.g. navigation, http-client, error, real-time, search); each is a vertical slice carrying only the DDD layers it needs                                                                                                                                                                                                                                                                                           |
| `docs/`                                                       | Primary AI retrieval source — start at `index.md`                                                                                                                                                                                                                                                                                                                                                                                                                   |
| `docs/rules/`                                                 | Authoritative coding rules                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| `make/`                                                       | Make modules included by root `Makefile`                                                                                                                                                                                                                                                                                                                                                                                                                            |
