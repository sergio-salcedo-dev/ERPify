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
  physically removable. A soft delete keeps the bytes and silently breaks that guarantee — which is why
  `bank_account`, whose holder name and IBAN are personal data, is physically deleted and carries no
  `deleted_at` column.
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
- **`audit_log` is a justified retention exception — append-only + scheduled prune, not soft delete.**
  The operational audit trail (`Shared/Audit`, raw DBAL) takes no `UPDATE`/`DELETE` on the write path; it
  admits a **closed set of three first-class mutation policies** (ADR
  [`audit-activity-log.md`](../adr/audit-activity-log.md), D4). The first is the **retention prune** — its
  *only* sanctioned `DELETE`: a daily Symfony Scheduler job (`AuditLogPruner`, on the
  `scheduler_audit_maintenance` transport) with **differentiated per-level windows** (`security` kept
  longer than `activity`, `AuditRetentionPolicy` enforcing `security > activity`), deleting in `id`-keyed
  batches under a Postgres advisory lock so a sweep neither holds a long lock nor races a second worker.
  The row carries PII (`actor_id`, `ip`, `user_agent`), so the bounded window is also GDPR data
  minimisation; outright erasure is the second policy — the `audit:gdpr:erase` command's in-place
  anonymising `UPDATE` (`actor_id` → a fresh random UUID per subject; `ip`/`user_agent` → `[REDACTED]`),
  never row deletion. The third is the resource axis, which pseudonymises `resource_id` and — only where
  `actor_type = anonymous`, the one case where the row records no discriminant for whose address it holds —
  writes the same `[REDACTED]` sentinel over `ip`/`user_agent`. Two mutation paths sharing one normative
  sentinel, not two redaction policies.

## Identifiers (UUID v7, app-assigned)
- **All entity ids are UUID v7**, generated in the application layer (`Uuid::generate()` (`Shared/Uuid/Domain`)
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

## Persistence mechanism (ORM-mapped entity vs raw-DBAL table)

The persistence mechanism is chosen **per aggregate / per table**, not globally — see the criteria
table in [`../adr/bank-bankaccount-modeling.md`](../adr/bank-bankaccount-modeling.md). Two mechanisms
coexist; pick by what the row *is*:

- **Business aggregate → Doctrine ORM entity (the default).** A row whose **current state** you load,
  mutate and flush is an `#[ORM\Entity]` (e.g. `Bank`, `BankAccount`), persisted through the shared
  EntityManager. New aggregates use ORM — this is unchanged and remains the norm.
- **Append-only log / projection / counter / checkpoint → raw DBAL, no ORM entity.** `event_store`,
  `projection_checkpoint`, `bank_count` and `handled_domain_event` are read/written through the DBAL
  **`default`** `Connection` (so they join the aggregate's write transaction) and mapped by hand to
  readonly DTOs (`StoredEvent`) — never hydrated as managed objects. Full design:
  [`../adr/event-store-and-projections.md`](../adr/event-store-and-projections.md).

**Why a log is deliberately *not* an ORM entity** (an omission would be a bug; this is a choice): an
append-only log is immutable, so the ORM's whole reason to exist — UnitOfWork change-tracking,
dirty-checking, the identity map — is dead weight over rows that never mutate; idempotent append needs
`INSERT … ON CONFLICT (event_id) DO NOTHING` (a Postgres upsert, awkward through the UnitOfWork); the
payload is opaque JSON with no per-event class to map; and replay must stream rows to lightweight DTOs,
not hydrate thousands of managed objects.

**Schema stays known to Doctrine via a `postGenerateSchema` listener, not a mapping.** Each raw-DBAL
table owns a `*SchemaListener` (`EventStoreSchemaListener`, `ProjectionCheckpointSchemaListener`,
`BankCountSchemaListener`, `HandledDomainEventSchemaListener`) that injects the table + indexes into the
in-memory schema, so `make db.diff` generates and keeps its migration. These tables are deliberately
**absent** from the `config/packages/doctrine.yaml` ORM mappings — the schema tool sees them, the ORM
does not.

**`auto_mapping: false` is intentional — the mapping list is an allowlist of what the ORM owns.**
`config/packages/doctrine.yaml` declares each ORM tree by hand (`Backoffice`, `Iam`,
`Organization`); Doctrine does **not** auto-discover `#[ORM\Entity]` anywhere under `src/`. Two
reasons: the DDD layout scatters entities across contexts (there is no conventional `src/Entity` for
auto-mapping to find), and — the deciding one — keeping the list explicit makes *what Doctrine ORM
manages* a reviewable line in the diff instead of a side effect of an attribute appearing somewhere.
When an aggregate stops being ORM-persisted (as the domain-event log did when it became the raw-DBAL
`event_store`), its entity **and** its `doctrine.yaml` mapping are removed together — a mapping pointing
at an entity-less directory is dead config Doctrine rejects.

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
  `Erpify\<Top>\<ContextB>\Domain\…`, `…\Application\…`, or `…\Infrastructure\…`
  — i.e. knowing the foreign context's internals (a concrete adapter is as much
  an internal as the domain model). The **only** allowed seams are that context's
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

- **Shared kernel** — `Money`, `Uuid`, `Role`, `Permission` and the other value
  objects under `src/Shared/`. An FK toward a genuinely shared table is fine.
  **`User` is NOT one of them in this codebase**: it is the aggregate of the
  `Iam/Identity` bounded context, so a reference to it crosses a context and
  follows Level 2 — a bare UUID column, no `FOREIGN KEY`. Measured: the schema
  holds exactly two foreign keys and neither points at `identity_user`.
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
  `#[ORM\ManyToOne]` is acceptable **only** toward the shared kernel proper
  (`src/Shared/`), because every context references that core. **Not toward a
  person:** `User` belongs to `Iam/Identity`, so it takes the strict form above.
  ```php
  class Membership {
      #[ORM\Column(type: Types::GUID)] private string $userId;  // ✅ id, no FK
  }
  ```
  A `#[ORM\ManyToOne]` toward **another business context's** entity
  (`Project → Lead`) is Level 2 — discouraged; prefer the id.

**Platform / shared kernel** = the special context every context may reference:
`Role`, `Permission`, `Money`, `Uuid` and the other value objects under
`src/Shared/`. An FK or a `ManyToOne` toward one of those is Level 3 (allowed);
a business context never gets that treatment — **and `User` is a business
context's aggregate, not platform.** Referencing a person is Level 2: an id
column with no foreign key.

That choice is what makes erasing a person a **distributed obligation** rather
than something the database cascades, which is why every persisted person id
must name the file that erases it in
[`../../api/.person-reference-policy`](../../api/.person-reference-policy),
enforced by `make php.lint.person-reference`. Reversing it — an
`ON DELETE CASCADE` toward `identity_user`, which would make an orphan
unrepresentable — was **raised and declined** (2026-08-01): schema-level coupling
across a context boundary buys referential integrity at the price of the
isolation the modular monolith rests on, and the erasure chain already owns the
guarantee explicitly.

Don't over-tighten: a dogmatic "zero coupling" gate freezes development, forces
needless data duplication, and fights the framework. Symfony gives the
structural base (PSR-4 autoload, service isolation, Messenger event boundary,
per-context Doctrine mapping) — the gate itself is custom (see below).

**Tenant scoping is orthogonal and also at query level.** When `company_id`
lands (multi-tenant, Phase H of [`../saas-production-roadmap.md`](../saas-production-roadmap.md)),
every tenant-owned table carries it and every query is tenant-scoped via a
Doctrine filter / mandatory repository scope — never left to per-call discipline.
Indexes are tenant-led composites (`(company_id, …)`).

> **Enforcement status:** a 3-level static gate is wired into `make php.quality`
> as `make php.lint.bounded-context` (PHPUnit gate that tokenizes imports —
> `api/tests/Unit/Shared/Architecture/BoundedContextGateTest.php`). **Level 1**
> (cross-context `Domain\`/`Application\`/`Infrastructure\` import — including
> injecting a foreign repository) **fails** the build; imports are read via
> `token_get_all` so grouped/aliased/multiline `use` cannot evade it.
> `Erpify\Shared\…` is always importable, and genuinely published seams (a
> context's Application service interface, its integration-event classes) are
> declared in `api/.bounded-context-allowlist`. **Level 2** (a cross-context
> Doctrine FK whose `targetEntity` resolves to another business context) is
> printed as a non-blocking warning — justify it in the PR. **Level 3** is
> allowed. ID-only references are plain strings with no import and are invisible
> to the gate by construction; the gate enforces the import seam, not total
> runtime independence.
