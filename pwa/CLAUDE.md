# pwa/CLAUDE.md — ERPify PWA (Next.js 16 App Router)

PWA-scoped guidance. Root [`../CLAUDE.md`](../CLAUDE.md) is authoritative for monorepo conventions, the Docker stack, and the full `make` target list — this file only covers PWA specifics. Also consult [`AGENTS.md`](AGENTS.md) and `../.cursor/rules/frontend.mdc`.

## Stack

-   **Next.js 16** (App Router) + **TypeScript** (strict).
-   **Tailwind 4** + **Shadcn UI**. Styling follows **BEM** class naming (`block__element--modifier`), mobile-first.
-   **Inversify** for DI — constructor-inject interfaces defined in `domain`.
-   **Vitest** for unit tests, **Playwright** for E2E.

## Folder structure

-   `src/app/` — Next.js App Router (routes, layouts, route handlers). Keep components here thin; push logic down.
-   `src/context/<bounded-context>/{domain,application,infrastructure}/` — DDD core. Dependencies point inward:
    -   `domain/` — pure types, value objects, interfaces. **No** Next, Inversify, HTTP, or ORM imports.
    -   `application/` — use cases / orchestration; depends only on `domain`.
    -   `infrastructure/` — adapters (HTTP clients, storage, framework glue).
-   `src/context/shared/` — cross-cutting code. Don't scatter shared utilities elsewhere.
-   `src/components/` — reusable UI (Shadcn-based). `src/lib/` — framework glue.
-   `tests/` — mirrors `src/` structure.

## Make targets (run from repo root)

-   `make pwa.install` — `npm ci`. Auto-cleans the empty root-owned `pwa/node_modules/` that the dev compose volume leaves on the host.
-   `make pwa.install.if-missing` — guard used as a prerequisite of `make dev` / `make dev.local`; runs `pwa.install` only when `pwa/node_modules/` is missing or unhealthy.
-   `make pwa.dev` — Next dev (Turbopack, host :80). Pair with `make api-up-http` or use `make dev.local` (runs both).
-   `make pwa.build` — production build.
-   `make pwa.test` = `pwa.test.unit` (Vitest) + `pwa.test.e2e` (Playwright).
    -   Single file: `make pwa.test.unit c='path/to/file.test.ts'`.
    -   Watch mode: `make pwa.test.unit.watch`. Report viewer: `make pwa.test.e2e.reports`.
    -   E2E sharding: `CI_SHARD=N CI_TOTAL_SHARDS=M make pwa.test.e2e`.
-   `make pwa.lint` — ESLint + Prettier check. Fixers: `pwa.lint.eslint.fix`, `pwa.format.prettier.fix`.
-   `make pwa.clean` — remove `node_modules`, `package-lock.json`, `.next` (destructive).

Full-stack targets (`make dev`, `make docker.up`, `make docker.down`, …) live in the root `Makefile` — see root `CLAUDE.md`.

## Env

-   **Docker stack** (default): `NEXT_PUBLIC_SYMFONY_API_BASE_URL=https://localhost`, `SYMFONY_INTERNAL_URL=http://php:80` (set in Compose).
-   **`make dev.local`** (host Next + Docker API on :8000): set in `pwa/.env.local`:
    -   `NEXT_PUBLIC_SYMFONY_API_BASE_URL=http://localhost:8000`
    -   `SYMFONY_INTERNAL_URL=http://localhost:8000`

## Rules that bite

-   `Domain/` must not import from Next, Inversify, `fetch`, or any infrastructure.
-   New bounded contexts follow the `domain`/`application`/`infrastructure` split — don't flatten into `src/app/` or `src/lib/`.
-   Prefer functional components + hooks; strict TS types (no `any` unless justified).
-   BEM class names — `.card__header--highlighted`, not arbitrary utility clusters that escape the component.
