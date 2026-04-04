# Erpify - Construction SaaS ERP/CRM

## Build & Test Commands

- `npm run dev`: Start dev server
- `npm run build`: Build production bundle
- `npm run test`: Run unit tests (Vitest)
- `npm run e2e`: Run E2E tests (Playwright)
- `npm run lint`: Run ESLint

## Architecture

- **DDD (Domain-Driven Design)**: Logic separated into `context/`.
- **Hexagonal Architecture**: Layers: `domain`, `application`, `infrastructure`.
- **Clean Architecture**: Dependency direction towards the domain.
- **SOLID & DRY**: Strictly enforced.

## Folder Structure

- `src/app/`: Next.js App Router.
- `src/context/`: Core business logic.
- `tests/`: Mirrors `src/` structure.

## Coding Standards

- Use functional components and hooks.
- Strict TypeScript types.
- Tailwind CSS for styling.
- Dependency Injection via container.
