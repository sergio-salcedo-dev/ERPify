# ERPify — Project Overview

## Purpose

Construction-industry SaaS ERP/CRM (per `pwa/CLAUDE.md`). The repository holds the full delivery: HTTP API, Web PWA, test suites, infrastructure Compose stack, and shared docs.

## Domain & functional scope

Verticalized ERP for the construction industry (public and private works), covering the full lifecycle of a *job/site* (*obra*): from commercial prospecting and initial economic study, through on-site execution, invoicing, cost control, and financial close. The design is driven by the real needs of civil engineers, commercial managers, finance managers, and developers — not a generic horizontal ERP.

**Modeling principle — real subdomains, ubiquitous language, no generic abstractions.** ERPify rejects technical umbrellas like "CRM", "Sales module" or a god-entity `Customer`. The construction user thinks in *leads*, *tenders* (licitaciones), *proposals*, *sites* (obras) and *certifications* — so each capability is a bounded context named in that language. Actors are modeled as a thin shared **`Party`** identity (legal data, referenced by id) with each context owning its **role** (`Account`, `Supplier`, `Employee`…), never a fat shared actor. The **canonical model** — contexts, aggregates, invariants and the integration events that wire them — lives in [`bounded-contexts.md`](./bounded-contexts.md); this section is only the high-level map.

Functional pillars (→ owning context):

- **Actor directory** — thin `Party` identity + per-context roles: clients, suppliers, subcontractors, employees, self-employed (*autónomos*).
- **Commercial** — leads, opportunities (private funnel), campaigns, client-interaction history.
- **TenderManagement** — public tenders: deadlines, bid/no-bid, documents, award tracking.
- **CommercialProposal** — client-facing economic/technical offers, tied to the pavement study.
- **Projects** — public/private works: phases, milestones, tasks, progress.
- **SiteOperations** — on-site execution: site diary, **certifications** (progress billing), measurements, incidents, quality, safety (PRL).
- **Budgeting & pavement configurator** — per-job budgets, versioning, the layer-by-layer cost configurator.
- **Procurement & Inventory** — suppliers, purchase orders, delivery notes (*albaranes*), stock movements.
- **Resources** — plant & equipment (machinery, vehicles) and their assignment/cost.
- **Finance** — invoicing, payments, treasury, cashflow, per-project cost & margin.
- **Cost Allocation** — configurable rules distributing direct/indirect costs across jobs.
- **Workforce** — people, subcontractors, time tracking, labor cost.
- **External Portals** — segmented self-service per actor type (client/supplier/subcontractor/employee).
- **Platform** — notifications, dynamic feature flags, automation, reporting, audit.
- **Domain events** — every state change records an event; the frontend reacts to e.g. `commercial.opportunity.won`, `site-operations.certification.approved`, `finance.invoice.paid`, `finance.project.budget-exceeded`, `procurement.delivery.scheduled`.

Target personas (each shapes a distinct surface): **civil engineers** (pavement configurator, economic studies, site execution), **commercial managers** (tenders, proposals, pipeline, marketing), **finance managers** (treasury, cashflow, cost allocation, invoicing), **developers** (maintain & extend).

> Forward-looking scope. Most of this is roadmap, not shipped — the implemented bounded contexts (currently `Bank`/Finance and `Health`) are the source of truth. Sequence and phasing: [`product-roadmap.md`](./product-roadmap.md); granular model: [`bounded-contexts.md`](./bounded-contexts.md).

## Repository classification

- **Type:** Monorepo (multi-part)
- **Parts:** 2 — `api/` (backend) and `pwa/` (web)
- **Orchestration:** Docker Compose (`compose.yaml` + `compose.dev.yaml` / `compose.prod.yaml`) from the repo root
- **Canonical commands:** root `Makefile` + `make/*.mk`

## Tech stack summary

| Part | Role | Language / Runtime | Framework | Key infrastructure |
|---|---|---|---|---|
| `api/` | HTTP API + async workers | PHP 8.5 | Symfony 8.0.x | FrankenPHP (Caddy), Doctrine ORM 3.6 / DBAL 4.4, PostgreSQL 18, Symfony Messenger, Mercure Hub, Monolog, Symfony UID |
| `pwa/` | Web UI | TypeScript 6 / Node 24 | Next.js 16.2 (App Router) + React 19.2 | Tailwind 4.2, Shadcn 4, Inversify 8.1, Base UI 1.4, Vitest 4.1, Playwright 1.59 |

## Architecture type

**DDD + Hexagonal + Clean Architecture** on both parts. Each bounded context is split into `Domain / Application / Infrastructure` layers with dependencies pointing inward to `Domain`. See `docs/rules/architecture.md` and `docs/project-context.md`.

- **API bounded contexts:** `Backoffice/{Audit, Bank, BankAccount, Health}`, `Frontoffice/{Dev, Health}`, `Iam/{Identity, Invitation, Session}`, `Organization/{Membership, Organization}`, plus the `Shared/` capability modules over a minimal `Kernel/`.
- **PWA bounded contexts:** `backoffice/{health}`, `frontoffice/{health}`, `shared/{domain, infrastructure}`.

## Cross-cutting contracts

- **Error contract** (`/api/*`): RFC 9457 Problem Details with marker-interface exception taxonomy, per-request `correlation-id`, per-error `instance` UUIDv7, tiered structured logging. See [`api-error-contract.md`](./api-error-contract.md).

## Traffic model (dev, default)

Browser → `http(s)://localhost` → **FrankenPHP**:

- `/` HTML → reverse-proxied to Next.js on `:3000` (inside the `pwa` container).
- `/api/*` and `/.well-known/mercure` → handled by Symfony on the same origin.

Full details: [`integration-architecture.md`](./integration-architecture.md).

## Detailed documentation

- **[`project-context.md`](./project-context.md)** — critical rules AI agents must follow (load first).
- [`architecture-api.md`](./architecture-api.md) — Symfony API architecture.
- [`architecture-pwa.md`](./architecture-pwa.md) — Next.js PWA architecture.
- [`integration-architecture.md`](./integration-architecture.md) — how `pwa` and `api` communicate.
- [`api-error-contract.md`](./api-error-contract.md) — RFC 9457 Problem Details contract.
- [`source-tree-analysis.md`](./source-tree-analysis.md) — annotated directory tree.
- [`development-guide-api.md`](./development-guide-api.md) / [`development-guide-pwa.md`](./development-guide-pwa.md) — per-part dev setup.
- [`deployment-guide.md`](./deployment-guide.md) — prod Compose, env vars, workers.
- [`contribution-guide.md`](./contribution-guide.md) — branches, commits, PRs, hooks.
