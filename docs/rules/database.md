# Database Best Practices

## General Practices
- Use parameterized queries/prepared statements to prevent SQL injection
- Use transactions for operations that must succeed or fail together
- Index frequently queried columns
- Normalize database structure appropriately
- Use meaningful table and column names

## PostgreSQL Specific
- Use PostgreSQL-specific features when beneficial (JSONB, arrays, etc.)
- Use appropriate data types (avoid TEXT for everything)
- Use constraints (NOT NULL, UNIQUE, FOREIGN KEY) appropriately
- Use migrations for schema changes
- Never modify production database directly

## Query Optimization
- Avoid N+1 query problems
- Use JOINs instead of multiple queries when possible
- Use EXPLAIN ANALYZE to understand query performance
- Index foreign keys and frequently filtered columns
- Avoid SELECT * - specify needed columns

## Data Integrity
- Use database constraints to enforce data integrity
- Validate data at both application and database level
- Handle database errors gracefully
- Use appropriate isolation levels for transactions

## Deletion policy (hard delete by default)
- **Hard delete (`DELETE`) is the default.** GDPR's right to erasure (Art. 17) must always be
  satisfiable: any row that may carry personal data (uploads, free text, contact details) must be
  physically removable. A soft delete keeps the bytes and silently breaks that guarantee — `media`
  learned this and dropped its `deleted_at` column.
- **Model business lifecycle as explicit domain state, not as soft delete.** "Archived",
  "deactivated", "cancelled" are domain concepts with their own invariants and behavior — put them
  on the aggregate as a status, not in a generic `deleted_at` flag.
- **Soft delete is not an audit trail.** History is covered by the domain-event audit table — see
  [`../architecture-api.md`](../architecture-api.md).
- **Soft delete is allowed only when ALL of these hold** (justify it in the PR description):
  1. A legal/accounting retention duty applies to the row itself, and the audit table cannot satisfy it.
  2. The row holds no personal data — or a scheduled purge job enforces a bounded retention window.
  3. The `deleted_at IS NULL` filter is explicit in every repository query — no global/magic ORM filters.
  4. Unique constraints account for it (PostgreSQL partial unique indexes: `… WHERE deleted_at IS NULL`).

## Identifiers (UUID v7, app-assigned)
- **All entity ids are UUID v7**, generated in the application layer (`Uuid::generate()` (`Shared/Domain/Uuid`)
  on the API; the `uuidV7()` helper on the PWA). v7 keeps ids time-ordered for index locality and lets
  the whole stack — PKs, domain-event `aggregate_id`, correlation ids — share one version.
- **The application owns the id; Doctrine must not generate or overwrite it.** Entities using the shared
  `Identifiable` trait are mapped as a Doctrine *assigned* identifier: `#[ORM\Id]` + `#[ORM\Column]` with
  **no** `#[ORM\GeneratedValue]` / `CustomIdGenerator`. Re-adding a generator re-introduces the
  create-event id mismatch (Doctrine mints a divergent PK at flush) — pinned by
  `tests/Functional/Doctrine/IdentifiableAssignedIdentifierTest`.
- **Assign the id before persist.** Every `Identifiable` user (aggregates and the `StoredDomainEvent`
  audit row) must set its id prior to `persist()`/flush; a null id is a bug, not a Doctrine cue.
- Validate inbound ids as UUIDs (`#[Assert\Uuid(strict: true)]`) — version-agnostic, so v7 passes.

## Bounded-context data isolation (modular monolith)

ERPify is a **modular monolith on one physical PostgreSQL database**. The goal is
to **enforce context boundaries, not total isolation** — FKs and imports are not
bad *per se*; the defect is coupling to another context's **domain internals**.
The tool isn't the problem, the boundary it crosses is. Guiding principle:

> **Los contextos no pueden conocer las interioridades de otro, pero sí pueden
> referenciar sus identidades y reaccionar a sus eventos.** DDD no prohíbe
> dependencias; prohíbe el *conocimiento directo del dominio ajeno*.

Each context owns its tables under a schema or a strong naming convention
(`<context>_<table>`) and never reads/writes another's tables directly. Beyond
that, classify coupling in **three levels** — only Level 1 is review-blocking.
Complements the context map in [`../bounded-contexts.md`](../bounded-contexts.md)
and the data-modeling strategy in [`../product-roadmap.md`](../product-roadmap.md).

**🔴 Level 1 — prohibido (defecto que bloquea revisión):**

- **Cross-context domain import.** A file under `src/<Top>/<ContextA>/` importing
  `Erpify\<Top>\<ContextB>\Domain\…` or `…\Application\…` — i.e. knowing the
  foreign context's internals. The **only** allowed seams are that context's
  **published Application service interface** and its **integration-event**
  classes.
- **Cross-context repository query.** Injecting/using another context's
  repository, or a `JOIN` across contexts in a hot query
  (`$leadRepository->find($project->leadId)` from Projects ❌). Foreign data
  comes from a published Application service or a **read model fed by events**.

**🟡 Level 2 — desaconsejado (soft rule; default = referencia por ID):**

- **Cross-context FK between two business contexts** (`project.lead_id →
  crm_lead.id`). Prefer a bare **UUID v7 column with no `FOREIGN KEY`**;
  integrity is upheld by events/policies/ACL. A genuine FK here is a warning to
  **justify in the PR**, not an automatic block — it avoids the "ERPIFY_CORE
  giant graph" where a CRM change breaks Projects.

**🟢 Level 3 — permitido:**

- **Shared kernel** — identity (`User`), tenant (`company_id`), `Money`, `Uuid`,
  shared VOs. An FK toward an identity/tenant/shared table is fine.
- **ID-only references** across contexts (`project.leadId`) — no JOIN, no entity
  association, no foreign repository.
- **Integration via events** — `ProjectCreated → CRM updates its projection`.
  This is the canonical cross-context channel; Symfony **Messenger is the
  boundary enforcer**.
- **Read models** owned by the consumer, rebuilt from the emitter's events.

**One shared EntityManager — the boundary is the domain model, not the EM.**
ERPify uses a **single `EntityManager`** (one DB, one EM): it simplifies
transactions, Messenger + outbox, and migrations. It is a *modular monolith*, not
microservices, so it is **not** split into multiple EntityManagers. The informal
phrase "don't share EntityManagers between contexts" is **not a Doctrine term**;
what it really means is **don't share domain models between contexts**. The
defect is not the shared EM — it is one context **importing, mutating and
persisting** another's entities:

```php
// ❌ Projects knows, mutates and persists a CRM entity
use Erpify\Frontoffice\Crm\Domain\Entity\Lead;   // cross-context import (Level 1)
$lead = $this->leadRepository->find($leadId);     // foreign repository (Level 1)
$lead->markAsConverted();                          // mutating another context's domain
```

Even with the same EntityManager, that breaks the boundary. The conversion is
done by **CRM reacting to an event** (`projects.project.created`), not by Projects.

**Doctrine relations — two options:**

- **Strict (default between business contexts):** no association — store the id
  and communicate via events.
  ```php
  class Project { private string $leadId; }        // ✅ reference by id
  ```
- **Pragmatic (only toward the Platform / shared kernel):** a controlled
  `#[ORM\ManyToOne]` is acceptable **only** toward identity/platform, because
  every context references that core.
  ```php
  class Project {
      #[ORM\ManyToOne] private User $manager;       // ✅ User ∈ shared kernel
  }
  ```
  A `#[ORM\ManyToOne]` toward **another business context's** entity
  (`Project → Lead`) is Level 2 — discouraged; prefer the id.

**Platform / shared kernel** = the special context every context may reference:
`User`, `Tenant`/`company_id`, `Role`, `Permission`, `FeatureFlag`. An FK or a
`ManyToOne` toward it is Level 3 (allowed); a business context never gets that
treatment.

Don't over-tighten: a dogmatic "zero coupling" gate freezes development, forces
needless data duplication, and fights the framework. Symfony gives the
structural base (PSR-4 autoload, service isolation, Messenger event boundary,
per-context Doctrine mapping) — the gate itself is custom (see below).

**Tenant scoping is orthogonal and also at query level.** When `company_id`
lands (multi-tenant, Phase H of [`../saas-production-roadmap.md`](../saas-production-roadmap.md)),
every tenant-owned table carries it and every query is tenant-scoped via a
Doctrine filter / mandatory repository scope — never left to per-call discipline.
Indexes are tenant-led composites (`(company_id, …)`).

> **Enforcement status:** today this is enforced by **review**. A 3-level static
> gate (Level 1 = error, Level 2 = warning, Level 3 = allowlisted) is tracked as
> deferred work (`_bmad-output/implementation-artifacts/deferred-work.md`). Until
> it lands, call out compliance explicitly in any PR that adds a cross-context
> reference.
