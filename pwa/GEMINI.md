# ERPify PWA Project Instructions

Specific guidance for the Next.js PWA. Reference the root [GEMINI.md](../GEMINI.md) for monorepo-wide mandates.

## Core Mandates

- **Layering:** Adhere strictly to the three-layer split within `src/context/<bounded-context>/`:
  - `domain/`: Pure types, Value Objects, Interfaces. **No imports from Next, Inversify, fetch, or infrastructure.**
  - `application/`: Use cases, Orchestration. Depends only on `domain`.
  - `infrastructure/`: Adapters (HTTP clients, storage), Inversify bindings, framework glue.
- **DI:** Use Inversify for constructor injection. Define interfaces in `domain` and bind them in `infrastructure`.
- **Styling:** Tailwind 4 with strict **BEM** naming convention (`block__element--modifier`). Avoid arbitrary utility clusters.

## Technical Standards

- **Next.js:** Version 16 (App Router).
- **TypeScript:** Strict mode is mandatory. No `any` without justification.
- **Components:** Functional components + hooks. Use `src/components/` for reusable UI (Shadcn-based).
- **Folder Structure:**
  - `src/app/`: Next.js App Router (routes, layouts). Keep logic minimal.
  - `src/context/`: DDD core logic split by bounded context.
  - `src/lib/`: Framework-specific glue.

## Essential Commands

Run from the repository root:

- `make pwa.quality`: Runs ESLint and Prettier. Fixers: `pwa.lint` (ESLint --fix), `pwa.format` (Prettier --write).
- `make pwa.test.unit`: Vitest unit tests. Mirror `src/` structure in `tests/`.
- `make pwa.test.e2e`: Playwright end-to-end tests.
- `make pwa.install`: `npm ci`. Use `pwa.install.if-missing` for a faster check.

## File Constraints

- **Node Modules:** `pwa/node_modules/` is managed by npm; never edit manually.
- **Context Boundaries:** Do not flatten domain logic into `src/app/` or `src/lib/`. New bounded contexts must follow the three-layer split.
