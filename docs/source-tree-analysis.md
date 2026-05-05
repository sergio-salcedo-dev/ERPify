# Source Tree Analysis

Annotated layout of the ERPify monorepo. Only critical directories are shown; generated output (`node_modules`, `vendor`, `var`, `.next`) is omitted.

```text
ERPify/
├── api/                            # Symfony API (Part: api)
│   ├── src/
│   │   ├── Kernel.php              # Symfony kernel (entry)
│   │   ├── Backoffice/
│   │   │   ├── Bank/               # Bank bounded context
│   │   │   │   ├── Domain/         # Entities, value objects, domain services (framework-free)
│   │   │   │   ├── Application/    # Use cases, DTOs, command/query handlers
│   │   │   │   └── Infrastructure/ # Doctrine mappings, HTTP controllers, adapters
│   │   │   └── Health/
│   │   ├── Frontoffice/
│   │   │   ├── Dev/
│   │   │   ├── Health/
│   │   │   └── Mercure/            # Mercure publishing & JWT
│   │   └── Shared/
│   │       ├── Application/        # Cross-context application services
│   │       ├── Domain/             # Shared value objects, base interfaces
│   │       ├── Infrastructure/     # Shared adapters
│   │       ├── Media/              # Image processing (Intervention Image)
│   │       └── Storage/            # Flysystem adapters
│   ├── config/
│   │   ├── bundles.php
│   │   ├── services.yaml           # DI autoconfigure defaults
│   │   ├── routes.yaml             # Attribute routing entry
│   │   └── packages/               # Doctrine, Messenger, Mercure, Mailer, Flysystem, CORS, Validator, Cache
│   ├── migrations/
│   │   └── 2026/                   # Doctrine migrations (timestamped)
│   ├── tests/                      # PHPUnit (mirrors src/)
│   ├── tools/
│   │   └── behat/                  # Isolated Composer tree for Behat (see project-context.md)
│   ├── public/                     # FrankenPHP doc-root
│   ├── docs/                       # API-specific docs
│   ├── composer.json
│   ├── phpunit.xml.dist
│   ├── phpstan.neon
│   ├── psalm.xml
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
│   │   │   └── backoffice/         # Backoffice route segment
│   │   │       ├── BackOfficeLayoutClient.tsx
│   │   │       ├── layout.tsx
│   │   │       ├── page.tsx
│   │   │       └── health/
│   │   ├── components/
│   │   │   └── ui/                 # Shadcn primitives + shared UI
│   │   ├── context/                # Business logic by bounded context
│   │   │   ├── backoffice/
│   │   │   │   └── health/
│   │   │   ├── frontoffice/
│   │   │   │   └── health/
│   │   │   └── shared/
│   │   │       ├── domain/
│   │   │       └── infrastructure/ # Inversify container wiring
│   │   └── lib/                    # Glue/util only
│   ├── tests/                      # Mirrors src/ (Vitest unit + Playwright e2e)
│   ├── docs/                       # PWA-specific docs (prod deploy, etc.)
│   ├── package.json
│   ├── tsconfig.json               # strict: true, experimentalDecorators, emitDecoratorMetadata
│   ├── vitest.config.ts
│   ├── playwright.config.ts
│   ├── next.config.*               # Turbopack-aware
│   ├── .eslintrc* / eslint.config.*
│   └── Dockerfile                  # node:24-alpine (digest-pinned)
│
├── docs/                           # Repo-wide docs (primary AI retrieval source)
│   ├── index.md                    # ← Start here
│   ├── project-context.md          # ← AI agent rules (load before generating code)
│   ├── project-overview.md
│   ├── architecture-api.md
│   ├── architecture-pwa.md
│   ├── integration-architecture.md
│   ├── source-tree-analysis.md
│   ├── development-guide-api.md
│   ├── development-guide-pwa.md
│   ├── deployment-guide.md
│   ├── contribution-guide.md
│   ├── domain-events-and-messenger.md
│   ├── local-fullstack-traffic.md
│   ├── media-upload.md
│   ├── mercure.md
│   ├── mercure-production-deployment.md
│   ├── object-storage.md
│   ├── production-deployment.md
│   └── project-requirements.md
│
├── .github/workflows/
│   └── ci.yml                      # Lint + test pipeline (CodeQL runs locally via `make codeql.run`)
│
├── .cursor/rules/                  # Authoritative coding rules (architecture, security, testing, …)
├── _bmad/ _bmad-output/            # BMad module config, outputs
├── binaries/                       # Local scripts/tools
├── scripts/                        # Dev/ops scripts
├── make/                           # Make modules (ci, composer, docker, js*, npm, php*, super-linter, utils)
├── Makefile                        # Canonical entrypoint — includes make/*.mk
├── compose.yaml                    # Base Compose (php, pwa, postgres, mercure)
├── compose.dev.yaml                # Dev overlay (bind mounts, hot reload)
├── compose.prod.yaml               # Prod overlay (messenger_worker, mailer)
├── CLAUDE.md                       # Project-wide Claude Code guidance
└── README.md                       # Repo entry point
```

## Entry points

- **API HTTP**: FrankenPHP → `api/public/index.php` → `Erpify\Kernel` (`api/src/Kernel.php`).
- **API async workers**: `messenger_worker` Compose service running Symfony Messenger consumer.
- **PWA**: `pwa/src/app/layout.tsx` + `pwa/src/app/page.tsx` (App Router defaults).

## Integration points

All browser traffic terminates at **FrankenPHP on `localhost`**. `/` is reverse-proxied to Next (`:3000` in-container); `/api/*` and `/.well-known/mercure` stay on PHP. See [`integration-architecture.md`](./integration-architecture.md) and [`local-fullstack-traffic.md`](../docs-info/local-fullstack-traffic.md).

## Critical folders — quick reference

| Path | Purpose |
|---|---|
| `api/src/{Backoffice,Frontoffice,Shared}/*/Domain/` | Pure domain — no framework imports |
| `api/src/*/Application/` | Use cases, DTOs, orchestration |
| `api/src/*/Infrastructure/` | Doctrine, HTTP, Messenger adapters |
| `api/config/packages/` | All bundle config (Doctrine, Messenger, Mercure, …) |
| `api/migrations/2026/` | Doctrine migrations (timestamped per year) |
| `api/tools/behat/` | Isolated Behat Composer tree — never add Behat deps to `api/composer.json` |
| `pwa/src/app/` | App Router routes & UI shells only |
| `pwa/src/context/<bc>/{domain,application,infrastructure}/` | Business logic per bounded context |
| `pwa/src/components/ui/` | Shadcn primitives + shared UI |
| `pwa/src/lib/` | Glue / utilities only |
| `docs/` | Primary AI retrieval source — start at `index.md` |
| `.cursor/rules/` | Authoritative coding rules (architecture, security, testing, …) |
| `make/` | Make modules included by root `Makefile` |
