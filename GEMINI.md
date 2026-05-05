# ERPify Project Instructions

This file serves as the foundational guide for Gemini CLI within the ERPify monorepo. It captures architectural mandates, coding standards, and project-specific workflows.

## Core Mandates

- **Architectural Integrity:** Rigorously adhere to **DDD + Hexagonal / Clean Architecture**. Dependencies must point inward toward the `Domain/` layer.
- **Framework Separation:** No framework imports (Symfony, Next, Inversify, HTTP clients, ORM) inside `Domain/`. Use adapters in `Infrastructure/` and orchestration in `Application/`.
- **Tooling First:** Always prefer `make` targets over direct tool invocation. They are ENV-aware and handle container execution context.
- **Validation:** No task is complete without verification. Run static analysis after every change and full linting before completion.
- **Strategic Re-evaluation:** If a fix fails more than 3 times, stop and propose a different architectural approach.

## Repository Structure

- `api/`: Symfony HTTP API (FrankenPHP). Layered: `Domain` / `Application` / `Infrastructure`.
- `pwa/`: Next.js 16 web app. Layered: `domain` / `application` / `infrastructure`.
- `make/`: Shared Make modules defining the canonical interface.

## Memory & Instruction Routing

Follow these rules for persisting project context:
- **Project Instructions (`./GEMINI.md`):** Team-shared conventions, architecture rules, or repo-wide workflows.
- **Subdirectory Instructions (e.g., `./api/GEMINI.md`):** Scoped instructions for a specific part of the project.
- **Private Project Memory (`~/.gemini/tmp/erpify/memory/MEMORY.md`):** Personal local setup, machine-specific notes, or private workflows (not committed).
- **Global Personal Memory (`~/.gemini/GEMINI.md`):** Cross-project personal preferences (e.g., preferred test framework).

**Rule:** Never duplicate facts across these tiers. Each fact lives in exactly one place.

## Technical Standards

### API (PHP/Symfony)
- **Static Analysis:** `make php.stan` is mandatory for all changed files.
- **Linting:** `make php.lint` for full cleanup (Rector, PHP-CS-Fixer, Psalm auto-fixes).
- **Database:** Migrations via `make db.diff`. Only edit migrations on the current branch. Applied migrations are immutable.

### PWA (TS/Next.js)
- **DI:** Use Inversify for constructor injection. Bindings live in `infrastructure`.
- **Styling:** Tailwind 4 with BEM naming (`block__element--modifier`).
- **Testing:** `make pwa.test.unit` (Vitest) and `make pwa.test.e2e` (Playwright).

## Workflows

### Subagents
- Use `invoke_agent` for independent tasks (e.g., one for `api`, one for `pwa`).
- Do not run parallel agents on the same bounded context or `Shared/` folders.

### Commit Conventions
- **Format:** `<type>(<scope>): <subject>` (lower-case, imperative, no trailing period).
- **Scopes:** `api`, `pwa`, or a bounded context (e.g., `bank`, `auth`).
- **Types:** `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`.
- **Example:** `feat(api): add domain exception taxonomy`

## Subdirectory Instructions

- [API Specific Instructions](api/GEMINI.md)
- [PWA Specific Instructions](pwa/GEMINI.md)
