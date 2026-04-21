# Architecture — PWA (`pwa/`)

## Executive summary

The `pwa/` deployable is the ERPify web UI: **Next.js 16 (App Router) + React 19 + TypeScript 6**, styled with **Tailwind 4 + Shadcn**, with business logic organised as **DDD + Hexagonal / Clean Architecture** under `src/context/<bounded-context>/{domain,application,infrastructure}`. Dependency injection is handled by **Inversify 8** (constructor injection of domain interfaces). Tests are split between **Vitest 4** (unit) and **Playwright 1.59** (e2e).

## Technology stack

| Category | Technology | Version |
|---|---|---|
| Runtime | Node.js (Alpine container) | **24** (`node:24-alpine`, digest-pinned) |
| Package manager | npm | lockfile: `pwa/package-lock.json` |
| Framework | Next.js (App Router, Turbopack dev) | **16.2.4** |
| UI runtime | React / React DOM | 19.2 |
| Language | TypeScript (`strict: true`) | 6 |
| Styling | Tailwind (CSS-first) + Shadcn | 4.2 / 4.3 |
| UI primitives | Base UI React, lucide-react, motion, tw-animate-css, tailwind-merge, cva | — |
| DI | Inversify + reflect-metadata | 8.1 / 0.2 |
| Forms | react-hook-form + `@hookform/resolvers` | 7.x / 5.2 |
| Unit tests | Vitest + Testing Library + jest-dom + jsdom | 4 / 16 / 6 / 29 |
| E2E tests | Playwright | 1.59 |
| Lint / format | ESLint 10 + eslint-config-next + Prettier 3.8 | — |
| Integrations in deps | `@google/genai`, `firebase-tools` | (present — verify usage before wiring) |

See [`project-context.md`](./project-context.md) for the constraint table including Next 16 / React 19 / Tailwind 4 gotchas.

## Architecture pattern

**DDD + Hexagonal + Clean Architecture.** Dependencies point inward to `domain/`.

```
pwa/src/
├── app/                         # Next.js App Router — routes & UI shells only
│   ├── layout.tsx
│   ├── page.tsx
│   ├── globals.css              # Tailwind 4 CSS-first config (@theme / @config)
│   └── backoffice/
│       ├── layout.tsx
│       ├── page.tsx
│       ├── BackOfficeLayoutClient.tsx
│       └── health/
├── components/
│   └── ui/                      # Shadcn primitives + shared UI
├── context/                     # Business logic by bounded context
│   ├── backoffice/
│   │   └── health/ { domain, application, infrastructure }
│   ├── frontoffice/
│   │   └── health/ { domain, application, infrastructure }
│   └── shared/
│       ├── domain/
│       └── infrastructure/      # Root / shared Inversify bindings
└── lib/                         # Glue / utilities only
```

- **App Router only** (`src/app/`) — no `pages/` directory.
- **No default exports under `src/context/**`** — named exports only. `page.tsx` / `layout.tsx` default exports are the Next-required exception.
- **Server vs Client**: default is Server Component; add `'use client'` only when needed (state, effects, browser APIs, event handlers). Use `import 'server-only'` for modules that must not leak to clients.

## Component architecture

- **Shadcn UI** primitives in `src/components/ui/`, extended in-repo (not forked upstream).
- **BEM class naming** (`block__element--modifier`) for custom classes on top of Tailwind utilities.
- Tailwind 4 is **CSS-first**: there is **no `tailwind.config.js`** — configuration lives in `src/app/globals.css` via `@theme {}` / `@config`.
- Class composition with `cn()` (clsx + tailwind-merge); never string-concatenate class names.
- Icons: `lucide-react`. Animations: `motion` / `tw-animate-css`.

## State management

- React hooks for local UI state.
- Cross-cutting state via **Inversify-wired services** injected into client components through the DI container — avoid adding Redux/Zustand/Jotai unless a story justifies it.
- Forms: `react-hook-form` + `@hookform/resolvers` at the resolver layer.

## Dependency injection (Inversify 8)

- `reflect-metadata` is imported **once** at the app entry.
- Requires `experimentalDecorators` + `emitDecoratorMetadata` in `pwa/tsconfig.json` (already set).
- Bindings live per bounded context (e.g. `src/context/<bc>/infrastructure/container.ts`) and are composed into a root container under `src/context/shared/infrastructure/`.
- Inject **domain interfaces** (from `domain/`) — never concrete infra classes — into application use cases.

## Data fetching & server integration

- Prefer **Server Components** + direct fetch / DI-resolved services over client-side fetch.
- Use React 19 `use(promise)` inside RSCs for streaming.
- **Server Actions** live in server-only modules (`'use server'`) — validate inputs at the boundary; never trust client-supplied IDs.
- API base URL: `NEXT_PUBLIC_SYMFONY_API_BASE_URL` (client). Internal SSR fetches: `SYMFONY_INTERNAL_URL`. See [`local-fullstack-traffic.md`](./local-fullstack-traffic.md).
- Mercure client subscribes via EventSource to **same-origin** `/.well-known/mercure`.

## Testing strategy

| Layer | Tool | Config | Command |
|---|---|---|---|
| Unit | **Vitest 4** + Testing Library | `pwa/vitest.config.ts` | `make pwa.test.unit` (optional `c='src/context/...'`) |
| Watch | Vitest | — | `make pwa.test.unit.watch` |
| E2E | **Playwright 1.59** | `pwa/playwright.config.ts` | `make pwa.test.e2e` |
| Reports | Playwright | — | `make pwa.test.e2e.reports` |

- **Playwright `baseURL: http://localhost:3000`** — e2e runs against `dev:e2e` on port 3000, **not** 80.
- Tests live under `tests/` mirroring `src/`.
- Query by role/label/text — avoid CSS class or test-ID selectors when accessible queries work.

Detailed rules: [`project-context.md` → Testing Rules](./project-context.md).

## Build & deployment

- **Dev bundler: Turbopack** (`next dev --turbo`). Webpack-only `next.config.*` entries silently no-op.
- Build: `make pwa.build` (`next build`). Start: `next start -p 80`.
- Dev-in-container runs Next on `:3000`; the `dunglas/frankenphp` front-end reverse-proxies HTML to it.
- Container image: built from `pwa/Dockerfile` using `node:24-alpine` (digest-pinned). Prod build env var: `NEXT_PUBLIC_SYMFONY_API_BASE_URL=https://localhost` by default (override per environment).

Full deploy flow: [`deployment-guide.md`](./deployment-guide.md) and [`pwa/docs/`](../pwa/docs/).

## Environment variables

| Var | Scope | Purpose |
|---|---|---|
| `NEXT_PUBLIC_SYMFONY_API_BASE_URL` | Public (client + server) | API base URL the browser uses |
| `SYMFONY_INTERNAL_URL` | Server-only | URL used for SSR / RSC fetches to Symfony |
| Others prefixed `NEXT_PUBLIC_` | Public | Must not contain secrets |
| All others | Server-only | Never read from a client component |
