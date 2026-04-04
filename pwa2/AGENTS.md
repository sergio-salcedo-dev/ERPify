# Agent Instructions for Erpify

## What to do

- Every time Claude makes a mistake → you add a rule
- Every time you repeat yourself → you add a workflow
- Every time something breaks → you add a guardrail

## Project Principles

- **BEM CSS Methodology**: Follow the Block Element Modifier (BEM) naming convention for all HTML/JSX class names (e.g., `block__element--modifier`).
- **Mobile First**: All UI components must be responsive and optimized for mobile first.
- **Tell, Don't Ask**: Encapsulate logic within objects/services.
- **Law of Demeter**: Minimize coupling between distant components.
- **Clean Code**: Meaningful names, small functions, no side effects in domain.

## Tech Stack

- Next.js 15 (App Router)
- Tailwind CSS 4
- TypeScript
- Vitest
- Playwright
- Inversify (DI Container)
- Shadcn UI

## Implementation Guidelines: DDD & Clean Architecture Rules

- **Domain Layer**: Pure logic, no external dependencies. Use Value Objects and Entities.
- **Application Layer**: Use cases. Orchestrates domain.
- **Infrastructure Layer**: Implementation of domain interfaces (repositories, API clients, storage).
- **Dependency Inversion**: High-level modules should not depend on low-level modules. Both should depend on abstractions.
- **Encapsulation**: Keep logic internal to the context.
- **Shared Kernel**: Common utilities, base classes, and shared value objects.
- Use `src/context/shared` for cross-cutting concerns.