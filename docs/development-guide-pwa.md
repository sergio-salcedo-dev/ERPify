# Development Guide — PWA (`pwa/`)

All commands below are run from the **repo root** via the root `Makefile`. The Make layer decides whether to exec inside the `pwa` container.

## Prerequisites

- Docker + Docker Compose (v2) — default flow runs Next inside the container.
- GNU Make.
- **Node.js 24 + npm on the host** — required. Vitest and Playwright run host-only via `$(pwa_cmd)`; there is no container variant (rationale: `make/CONVENTIONS.md` §8). Also needed for the `dev-local` / host-Next flow below. Match the container runtime (`node:24-alpine`).

## First-time setup (Docker flow, default)

```bash
make docker.up                 # full stack on http(s)://localhost (FrankenPHP fronts Next)
make pwa.install               # install npm deps into the pwa container
```

Browser opens at `http://localhost` (and `https://localhost`). Accept the dev certificate if prompted.

## Alternative: host Next + containerised API (`dev-local`)

```bash
make api-up-http               # API on :8000 only
# pwa/.env.local must contain:
#   NEXT_PUBLIC_SYMFONY_API_BASE_URL=http://localhost:8000
#   SYMFONY_INTERNAL_URL=http://localhost:8000
make dev.local                 # next dev on host :80 against API :8000
```

Do not mix flows in one session; switch by clearing `pwa/.env.local` and rebuilding.

Details: [`local-fullstack-traffic.md`](./local-fullstack-traffic.md).

## Run / build / tests

| Task | Command | Notes |
|---|---|---|
| Next dev (container) | `make pwa.dev` | Turbopack on `:3000` inside `pwa`; proxied by FrankenPHP |
| Production build | `make pwa.build` | `next build` |
| Install deps | `make pwa.install` | `npm ci` |
| Unit tests | `make pwa.test.unit` | Vitest; optional `c='path/to/file.test.ts'` |
| Unit — watch | `make pwa.test.unit.watch` | |
| E2E tests | `make pwa.test.e2e` | Playwright — targets **`:3000`** |
| Playwright reports | `make pwa.test.e2e.reports` | |
| Unit + E2E | `make pwa.test` | |

## Lint / format

```bash
make pwa.lint                       # ESLint + Prettier check
make pwa.lint.fix                   # ESLint --fix
make pwa.format.fix                 # Prettier --write
```

ESLint 10 + `eslint-config-next` + Prettier are **authoritative** — do not hand-format against them.

## Directory discipline

```
pwa/src/
├── app/                    # Next.js App Router — routes & UI shells only (no business logic)
├── components/ui/          # Shadcn primitives + shared UI
├── context/                # Business logic per bounded context
│   └── <bc>/
│       ├── domain/         # Interfaces + entities/value objects (framework-free)
│       ├── application/    # Use cases, orchestration
│       └── infrastructure/ # Adapters, Inversify bindings
└── lib/                    # Glue / utilities only
```

- **App Router only.** No `pages/` directory.
- **No default exports under `src/context/**`** — named exports only (Next's `page.tsx`/`layout.tsx` are the exception).
- Shared cross-cutting code belongs in `src/context/shared`, not ad-hoc folders.

## Dependency injection

- **Inversify 8** with constructor injection of **domain interfaces**.
- `reflect-metadata` imported **once** at the app entry.
- `tsconfig.json` already has `experimentalDecorators` + `emitDecoratorMetadata`.
- Bindings live per bounded context (e.g. `src/context/<bc>/infrastructure/container.ts`), composed into the root container under `src/context/shared/infrastructure/`.

## Styling & UI

- **Tailwind 4**: CSS-first. **No `tailwind.config.js`** — configuration lives in `pwa/src/app/globals.css` via `@theme {}` / `@config`.
- Shadcn UI primitives in `src/components/ui/`; extend locally, do not fork upstream.
- BEM class naming (`block__element--modifier`) on top of Tailwind utilities.
- Compose classes with `cn()` (clsx + tailwind-merge) — never string-concatenate class names.
- Mobile-first. Accessibility: semantic HTML, keyboard nav, visible focus, color contrast.

## Forms

- `react-hook-form` + `@hookform/resolvers`.
- Validate at the resolver layer; do not trust client-supplied IDs in Server Actions.

## Server vs Client boundary

- Default is **Server Component**. Add `'use client'` only when state, effects, browser APIs, or event handlers are required.
- Mark server-only modules with `import 'server-only'`.
- Server Actions (`'use server'`) live in server-only modules.
- Prefer Server Components + direct fetch / DI-resolved services over client-side fetch.

## Environment variables

| Var | Scope | Purpose |
|---|---|---|
| `NEXT_PUBLIC_SYMFONY_API_BASE_URL` | Client + server | API base URL the browser uses |
| `SYMFONY_INTERNAL_URL` | Server only | URL used for SSR / RSC fetches |
| `NEXT_PUBLIC_*` | Public | Must not contain secrets |
| Any other var | Server only | Never read from a client component |

## Critical rules to load before coding

Load [`project-context.md`](./project-context.md) before generating code. Key callouts for the PWA:

- Next 16 / React 19 / Tailwind 4 / Inversify 8 / TS 6 are beyond most training data — **read existing code before inventing patterns**.
- **Playwright targets `:3000`**, not `:80`. `baseURL: http://localhost:3000`.
- No `React.FC`, no `enzyme`, no shallow rendering; use Testing Library with role/label/text queries.
- Turbopack is the dev bundler; Webpack-specific `next.config.*` entries silently no-op.
- `reflect-metadata` imported once; don't re-import per module.
- Mercure client must subscribe via same-origin `/.well-known/mercure`.
