# Architecture — PWA (`pwa/`)

## Executive summary

The `pwa/` deployable is a Next.js 16.2 (App Router) + React 19.2 + TypeScript 6 application styled with Tailwind 4 (CSS-first config) and Shadcn primitives. Business logic is wired through **Inversify 8** for runtime DI and organised by **bounded context** under `src/context/`, mirroring the API's DDD layering (`domain / application / infrastructure`). Tests are split between Vitest (unit) and Playwright (E2E).

## Technology stack

| Category          | Technology                              | Version |
|-------------------|-----------------------------------------|---------|
| Runtime           | Node                                    | 24      |
| Language          | TypeScript                              | 6.0     |
| Framework         | Next.js (App Router, Turbopack dev)     | 16.2    |
| UI runtime        | React / React DOM                       | 19.2    |
| Styling           | Tailwind CSS                            | 4.2     |
| Component lib     | Shadcn                                  | 4.7     |
| Headless UI       | @base-ui/react                          | 1.4     |
| Icons             | lucide-react                            | 1.x     |
| Animation         | motion                                  | 12      |
| Forms             | @hookform/resolvers                     | 5.x     |
| DI                | Inversify (+ reflect-metadata)          | 8.1     |
| Class utilities   | class-variance-authority, clsx, tailwind-merge | — |
| Unit tests        | Vitest (jsdom)                          | 4.1     |
| Testing library   | @testing-library/react, @testing-library/jest-dom | 16/6 |
| E2E               | Playwright                              | 1.59    |
| Linting           | ESLint + eslint-config-next             | 10 / 16 |
| Formatting        | Prettier                                | 3.8     |

## Architecture pattern

**DDD + Hexagonal / Clean Architecture**, mirrored from the API. Dependencies point inward to `domain/`. The `app/` directory is the App Router shell only — business logic lives in `src/context/<bounded-context>/{domain,application,infrastructure}/`. Inversify wires concrete adapters to ports declared in `domain/` / `application/`.

### Bounded contexts

```text
pwa/src/context/
├── backoffice/
│   └── health/{application,domain,infrastructure}
├── frontoffice/
│   └── health/{application,domain,infrastructure}
└── shared/
    ├── domain/
    └── infrastructure/
        ├── DependencyInjection/   # Inversify container modules
        ├── HttpClient/            # Fetch wrapper
        └── ui/                    # Shared UI bindings
```

`src/components/` holds presentational components only:
- `ui/` — Shadcn primitives.
- `erpify/` — project-specific components.

`src/lib/` is glue/utility only — never business logic.

## Layer responsibilities

| Layer | Contains | Must NOT depend on |
|---|---|---|
| `domain/` | Entities, value objects, repository / port **interfaces**, domain errors | React, Next, Inversify, fetch, third-party SDKs |
| `application/` | Use cases, DTOs, orchestration | Infrastructure implementations (only their interfaces) |
| `infrastructure/` | HTTP clients, Inversify bindings, Next-aware adapters, presentational hooks bridging React to use cases | — (outermost) |

## Routing

- **App Router** under `src/app/`.
- Entry: `app/layout.tsx` (root layout) + `app/page.tsx` (root page).
- `app/globals.css` holds Tailwind 4's CSS-first `@theme` / `@config` directives.
- `app/backoffice/` is the backoffice route segment (e.g. `/backoffice/health`).

## Dependency injection

- Inversify 8 container assembled in `src/context/shared/infrastructure/DependencyInjection/`.
- `tsconfig.json`: `strict: true`, `experimentalDecorators: true`, `emitDecoratorMetadata: true` (required for Inversify class decorators).
- `reflect-metadata` imported once at the React entry point (`app/layout.tsx`).

## Data fetching & integration

- Same-origin under FrankenPHP in dev/prod: `/api/*` is served by Symfony on `localhost`.
- Standalone Next dev (`make dev.local`): point at `http://localhost:8000` via `NEXT_PUBLIC_SYMFONY_API_BASE_URL` and `SYMFONY_INTERNAL_URL` in `pwa/.env.local`.
- Server-side fetches use `SYMFONY_INTERNAL_URL` (Compose-internal); client-side fetches use the public URL.
- Mercure SSE consumed at `/.well-known/mercure` (same origin, JWT subscribed).

## Error consumption

The PWA consumes the API's [RFC 9457 Problem Details](./api-error-contract.md) contract. Routing/UI logic determines the semantic category from `type` (opaque, stable identifier) — never from message text or status code alone. `correlation-id` from the body (or the `X-Correlation-Id` response header) is the link to server-side log lines for support tickets.

## Configuration

- Build/dev: `next.config.*` (Turbopack-aware), `eslint.config.*`, `tsconfig.json`.
- Tailwind 4: configured via CSS in `app/globals.css` (no separate `tailwind.config.*` required).
- Tests: `vitest.config.ts`, `playwright.config.ts`.
- Env: `pwa/.env.local` for host overrides; `NEXT_PUBLIC_*` vars are inlined at build time.

## Testing strategy

| Layer | Tool | Entry |
|---|---|---|
| Unit | **Vitest 4** (jsdom) | `pwa/vitest.config.ts`, run via `make pwa.test.unit` |
| E2E | **Playwright 1.59** | `pwa/playwright.config.ts`, run via `make pwa.test.e2e` |
| Watch | Vitest | `make pwa.test.unit.watch` |
| Reports | Playwright HTML | `make pwa.test.e2e.reports` |
| Lint / format | ESLint + Prettier | `make pwa.lint`, `make pwa.lint.eslint.fix`, `make pwa.format.prettier.fix` |

`tests/` mirrors `src/`. Tests are colocated by bounded context.

## Source tree

See [`source-tree-analysis.md`](./source-tree-analysis.md) for the full annotated tree.

## Development & deployment

- Dev setup: [`development-guide-pwa.md`](./development-guide-pwa.md).
- Prod deploy: [`deployment-guide.md`](./deployment-guide.md).
