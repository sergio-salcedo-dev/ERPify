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
- **All entity ids are UUID v7**, generated in the application layer (`SymfonyUuidGenerator::generate()`
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
