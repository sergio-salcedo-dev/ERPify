# Event Catalog

The registry of every event ERPify emits — the contract surface a consumer (a projector, a
real-time client, a future BI/CRM/public-API integration) depends on. This is **current-state
reference**; the *why* behind the event backbone lives in the ADRs
([`event-driven-architecture.md`](../adr/event-driven-architecture.md),
[`event-store-and-projections.md`](../adr/event-store-and-projections.md)) and the runtime wiring in
[`architecture-api.md`](../architecture-api.md) (*Async & messaging*).

> **Source of truth & upkeep.** The authoritative list is the set of concrete
> `Erpify\Shared\Event\Domain\DomainEvent` subclasses, discovered at compile time by
> `RegisterDomainEventsPass` (which fails the build on an `eventName` collision). That pass does **not**
> write this file — this catalog is hand-maintained. When you add, rename, or version an event, update
> it here too. The *Adding or evolving an event* checklist at the foot keeps the two in sync.

## Reading this catalog

An event is observed on three independent surfaces; do not conflate their shapes:

| Surface | What it is | Contract owner |
|---------|-----------|----------------|
| **`event_store`** | The permanent, append-only domain-event log (replay, projections, integrations). | `DomainEvent::toPrimitives()` + the row envelope (§ Domain events, § Stored event row). |
| **Mercure** | The real-time wire message pushed to connected PWA clients. | A per-aggregate reactor (`RefreshRealtimeOnBankChanged`, `RefreshRealtimeOnBankAccountChanged`) — a **different** shape from the stored payload (§ Realtime wire contract). |
| **Audit log** | Durable access-audit rows in `audit_log` (the operational / actor audit axis), written through the `AuditLogger` seam. | Non-`DomainEvent` messages (§ Non-domain signals). |

Two invariants hold everywhere:

- **Canonical key = `(eventName, eventVersion)`**, never the PHP FQCN (which is refactor-fragile). The
  store, the mapper, and every subscriber key off the stable `eventName` string.
- **Envelope ⊥ payload.** `aggregateId` / `eventId` / `occurredOn` live on the store row, **never** in
  `toPrimitives()`. The payload carries domain state only.

## Naming & conventions

- **`eventName`** — `erpify.<context>.<aggregate>.<fact>`, past tense (`erpify.backoffice.bank.created`).
  Stable across class renames.
- **`aggregateType`** — `<Context>.<Aggregate>` (`Backoffice.Bank`), declared once per aggregate.
- **`eventVersion`** — schema version of the payload; defaults to `1`, bumped only when the payload
  shape changes (paired with an `Upcaster` — § Versioning).
- **Payload policy** — a **state event** (full snapshot) by default; a delta/thin shape only when a
  real consumer needs the diff ([ADR D10](../adr/event-store-and-projections.md)).

## Domain events

The `event_store` axis. Two aggregates emit today — **Backoffice / Bank** and
**Backoffice / BankAccount** — each event recorded inside its own aggregate and published by its use
case through the `EventBus` port inside the write transaction (atomic with the aggregate row — no
dual-write). All route to the **`async`** transport (Doctrine outbox → `messenger_worker`,
at-least-once).

### Backoffice.Bank (`aggregateType: Backoffice.Bank`)

| `eventName` | ver | Producer (use case) | Payload | Consumers |
|-------------|:---:|---------------------|---------|-----------|
| `erpify.backoffice.bank.created` | 1 | `BankCreator` | `BankSnapshot` (full) | Mercure · email · `bank_count` (+1) |
| `erpify.backoffice.bank.updated` | 1 | `BankUpdater` | `BankSnapshot` (full) | Mercure · email |
| `erpify.backoffice.bank.deleted` | 1 | `BankDeleter` | *empty* `[]` (id in envelope) | Mercure · `bank_count` (−1) |

Source: [`api/src/Backoffice/Bank/Domain/Event/`](../../api/src/Backoffice/Bank/Domain/Event/BankCreatedDomainEvent.php).

### Iam.Identity (`aggregateType: Iam.Identity`)

The identity lifecycle events are recorded by the `User`/`PasswordResetToken` aggregates and reach the
`event_store` outbox, but stay **unrouted** until a consumer exists (wire-on-consumer). The one consumed —
and therefore `async`-routed — event today:

| `eventName` | ver | Producer (use case) | Payload | Consumers |
|-------------|:---:|---------------------|---------|-----------|
| `erpify.iam.identity.password-reset-completed` | 1 | `CompletePasswordReset` (recorded by `User::resetPassword()`) | *empty* `[]` (user id in envelope — PII-free by design) | password-changed email |

Its payload is deliberately the aggregate id alone: the reactor resolves the recipient in-module at
handling time, so nothing secret or personal ever rides the transport, and a user hard-deleted before the
async send is simply skipped (never resurrected into a mail).

#### `BankSnapshot` — the shared payload

Created and updated carry the same value object so they stay byte-identical in the store; they share
the VO, **not** a supertype (which would couple their schemas). Source:
[`BankSnapshot.php`](../../api/src/Backoffice/Bank/Domain/Event/BankSnapshot.php).

| Field | Type | Notes |
|-------|------|-------|
| `name` | `string` | |
| `shortName` | `string` | |
| `createdAt` | `string` | ISO-8601 |
| `updatedAt` | `string` | ISO-8601 |
| `logoMediaId` | `string\|null` | |
| `logoContentHash` | `string\|null` | |
| `storedObjectContentHash` | `string\|null` | |
| `storedObjectMimeType` | `string\|null` | |

`erpify.backoffice.bank.updated` is a **full snapshot by design**, not a delta: its consumer (Mercure)
pushes the whole new state to the client. `erpify.backoffice.bank.deleted` carries an **empty payload**
— a deletion is identified by its `aggregateId` (envelope) alone.

### Backoffice.BankAccount (`aggregateType: Backoffice.BankAccount`)

Recorded inside the `BankAccount` aggregate; every payload is **PII-free** — never the IBAN, holder
name, BIC or alias, which are read through the authenticable HTTP endpoint, never the event log. The
only consumer is the realtime reactor: no email, no projector.

| `eventName` | ver | Producer (use case) | Payload | Consumers |
|-------------|:---:|---------------------|---------|-----------|
| `erpify.backoffice.bankaccount.created` | 1 | `BankAccountCreator` | `BankAccountSnapshot` (full) | Mercure |
| `erpify.backoffice.bankaccount.updated` | 1 | `BankAccountUpdater` | `BankAccountSnapshot` (full) | Mercure |
| `erpify.backoffice.bankaccount.status_changed` | 1 | `BankAccountStatusChanger` | `{ bankId, fromStatus, toStatus }` (delta) | Mercure |
| `erpify.backoffice.bankaccount.deleted` | 1 | `BankAccountDeleter` | `BankAccountSnapshot` (full) | Mercure |

Source: [`BankAccountCreatedDomainEvent.php`](../../api/src/Backoffice/BankAccount/Domain/Event/BankAccountCreatedDomainEvent.php).

#### `BankAccountSnapshot` — the shared payload

Composed by `created` / `updated` / `deleted` through the `CarriesBankAccountSnapshot` trait —
horizontal composition, so each event stays a direct `DomainEvent` child with no shared supertype while
the VO owns the serialization contract. `status_changed` does **not** compose it: a transition is
described by its endpoints (`fromStatus` / `toStatus`), not the current snapshot. Source:
[`BankAccountSnapshot.php`](../../api/src/Backoffice/BankAccount/Domain/Event/BankAccountSnapshot.php).

| Field | Type | Notes |
|-------|------|-------|
| `bankId` | `string` | Owning bank id — the realtime collection topics are keyed by it |
| `status` | `string` | |
| `createdAt` | `string` | ISO-8601 |
| `updatedAt` | `string` | ISO-8601 |

Unlike `erpify.backoffice.bank.deleted` (empty payload), `erpify.backoffice.bankaccount.deleted`
**carries the snapshot**: the realtime publisher addresses the per-bank collection topic by the owning
`bankId`, read off the snapshot rather than re-queried from a just-deleted row.

## Realtime wire contract (Mercure)

A **separate contract** from the stored payload: a per-aggregate reactor publishes a thin refetch
signal to the hub, always **private** (`private = true`). The PWA reconciles by refetching through the
authenticable read — it never renders state from the wire message. Delivery is at-least-once; clients
reconcile by `id`, so a re-applied update or delete is a no-op.

### Bank

Owned by the reactor
[`RefreshRealtimeOnBankChanged`](../../api/src/Backoffice/Bank/Infrastructure/Messenger/RefreshRealtimeOnBankChanged.php).
The wire shape drops logo / stored-object metadata and adds `accountCount`, resolved at publish time
via `BankAccountCountEnricher::countFor()`.

Topics ([`MercureBankTopic`](../../api/src/Backoffice/Bank/Domain/MercureBankTopic.php)):

- `COLLECTION` = `urn:erpify:backoffice:banks` — list-level changes.
- `forBank(id)` = `urn:erpify:backoffice:bank:<id>` — a single bank's detail.
- `DETAIL_TEMPLATE` = `urn:erpify:backoffice:bank:{id}` (RFC 6570) — subscriber authorization only
  ([`BankRealtimeAuthorizeController`](../../api/src/Backoffice/Bank/Infrastructure/Controller/BankRealtimeAuthorizeController.php)).

| Triggering event | Topics | Message |
|------------------|--------|---------|
| created | `COLLECTION` | `{ "type": "bank.created", "bank": { id, name, shortName, createdAt, updatedAt, accountCount } }` |
| updated | `COLLECTION`, `forBank(id)` | `{ "type": "bank.updated", "bank": { …same fields… } }` |
| deleted | `COLLECTION`, `forBank(id)` | `{ "type": "bank.deleted", "id": "<bankId>" }` |

### BankAccount

Owned by the reactor
[`RefreshRealtimeOnBankAccountChanged`](../../api/src/Backoffice/BankAccount/Infrastructure/Messenger/RefreshRealtimeOnBankAccountChanged.php).
The broadcast is **PII-free** — `{ type, id, bankId }` only, never IBAN / holder name / BIC / alias.
Every change publishes to **all three** topics at once (global collection, the owning bank's accounts
collection, the account's detail). The `status_changed` domain event folds into the wire
`bank_account.updated` type — there is **no distinct `status_changed` wire type** (a transition and a
descriptive edit signal the same "refetch").

Topics ([`MercureBankAccountTopic`](../../api/src/Backoffice/BankAccount/Domain/MercureBankAccountTopic.php)):

- `COLLECTION` = `urn:erpify:backoffice:bankaccounts` — global list-level changes across every bank's
  accounts; consumed by the standalone accounts list.
- `COLLECTION_TEMPLATE` = `urn:erpify:backoffice:bank:{bankId}:accounts` (RFC 6570); `forBank(bankId)`
  is the concrete per-bank accounts collection — consumed by a bank's nested accounts list.
- `DETAIL_TEMPLATE` = `urn:erpify:backoffice:bankaccount:{id}` (RFC 6570); `forAccount(id)` is the
  concrete per-account topic — consumed by the standalone detail page.
- Subscriber authorization
  ([`BankAccountRealtimeAuthorizeController`](../../api/src/Backoffice/BankAccount/Infrastructure/Controller/BankAccountRealtimeAuthorizeController.php))
  grants all three (the global collection plus both templates) in the subscriber cookie.

| Triggering event | Topics | Message |
|------------------|--------|---------|
| created | `COLLECTION`, `forBank(bankId)`, `forAccount(id)` | `{ "type": "bank_account.created", "id": "<accountId>", "bankId": "<bankId>" }` |
| updated · status_changed | `COLLECTION`, `forBank(bankId)`, `forAccount(id)` | `{ "type": "bank_account.updated", "id": "<accountId>", "bankId": "<bankId>" }` |
| deleted | `COLLECTION`, `forBank(bankId)`, `forAccount(id)` | `{ "type": "bank_account.deleted", "id": "<accountId>", "bankId": "<bankId>" }` |

## Non-domain signals

The operational / actor audit axis records no business fact, stays out of the domain language, and never
enters the `event_store` — so it is not a `DomainEvent`. It is also **not a Messenger message**: an
`AuditLogger->log(...)` call writes its `audit_log` row **synchronously** via `AuditLogWriter`, with no
queue (ADR D3.1). A `BANK_ACCOUNTS_VIEWED` `activity` entry from `BankAccountSearcher`, for example, is
best-effort (a write miss is swallowed inside `AuditLogger` and never 5xxs the read) and carries a PII-free
payload (`action`, `level`, `actor_type`, `correlation_id`, `resource_type`/`resource_id` — never the IBAN).

Source: [`BankAccountSearcher.php`](../../api/src/Backoffice/BankAccount/Application/BankAccountSearcher.php)
(producer) and the [`AuditLogger`](../../api/src/Shared/Audit/Application/AuditLogger.php) seam. The full
audit axis is documented in [`audit-activity-log.md`](../adr/audit-activity-log.md).

## Read models & projections

Derived, replayable read state — distinct from reactors. A **projector** is a deterministic,
idempotent derivation replayable from `sequence` 0; a **reactor** (`<Effect>On<Event>`) is a
non-deterministic external effect (email, Mercure) that runs **live only** and is **never** re-run on
replay ([ADR D6](../adr/event-store-and-projections.md)).

**`bank_count`** — the reference projector
([`BankCountProjector`](../../api/src/Backoffice/Bank/Application/Projection/BankCountProjector.php)):

- Subscribes to `erpify.backoffice.bank.created` (`+1`) and `erpify.backoffice.bank.deleted` (`−1`).
- Read model `BankCountReadModel` (DBAL upsert; not `COUNT(*)`).
- Read path: `GET /api/v1/backoffice/banks/count` → `{ "total": <int> }`
  ([`BankCountController`](../../api/src/Backoffice/Bank/Infrastructure/Controller/BankCountController.php)).
- Rebuild: `make sf c='event:projection:rebuild bank_count'` (or `--all`) — truncate + checkpoint 0 +
  catch-up; the total then matches `COUNT(*)`, proving reproducibility.

Catch-up is driven by `ProjectionRunner` under a per-projection checkpoint (`SELECT … FOR UPDATE` →
exactly-once, ordered) — the same mechanism live and on rebuild. Live, it is fired by
[`RunProjectionsOnDomainEvent`](../../api/src/Shared/Event/Infrastructure/Messenger/RunProjectionsOnDomainEvent.php),
which handles **every** `DomainEvent` and calls `catchUpAll()` from the permanent log (never the
delivered message — so a missed or duplicated delivery cannot corrupt a read model).

## Consumer matrix

### Bank

| Consumer | Kind | created | updated | deleted | Effect |
|----------|------|:---:|:---:|:---:|--------|
| `RefreshRealtimeOnBankChanged` | reactor | ● | ● | ● | Mercure publish (live only) |
| `SendEmailOnBankChanged` | reactor | ● | ● | | Notification email; idempotent via `(eventId, handler)` claim |
| `BankCountProjector` | projector | ● | | ● | `bank_count` read model `±1` |
| `RunProjectionsOnDomainEvent` | trigger | ● | ● | ● | Fires projector catch-up for any event |

### BankAccount

| Consumer | Kind | created | updated | status_changed | deleted | Effect |
|----------|------|:---:|:---:|:---:|:---:|--------|
| `RefreshRealtimeOnBankAccountChanged` | reactor | ● | ● | ● | ● | Mercure publish (live only) |
| `RunProjectionsOnDomainEvent` | trigger | ● | ● | ● | ● | Fires projector catch-up for any event (no BankAccount projector today) |

### Iam.Identity

| Consumer | Kind | password-reset-completed | Effect |
|----------|------|:---:|--------|
| `SendEmailOnPasswordResetCompleted` | reactor | ● | Password-changed notification email (no token, no link); idempotent via `(eventId, handler)` claim |

## Stored event row

What a `DomainEvent` becomes in the `event_store` (raw DBAL; full rationale in
[ADR D4](../adr/event-store-and-projections.md)). Integrators read the log by `sequence`.

| Column | Meaning |
|--------|---------|
| `sequence` | `BIGINT IDENTITY` — global deterministic order; the replay/checkpoint cursor. |
| `event_id` | UUID v7, `UNIQUE` — stable identity (idempotent re-append). |
| `aggregate_id` | the subject's id (envelope). |
| `aggregate_type` | e.g. `Backoffice.Bank`. |
| `aggregate_version` | per-stream `MAX+1`; `UNIQUE` = optimistic concurrency. |
| `event_name`, `event_version` | the canonical key. |
| `payload` | JSONB — `toPrimitives()` (domain state only). |
| `metadata` | JSONB `{}` — reserved (`correlation_id`/`causation_id`/actor). |
| `tenant_id` | UUID, `NULL` today — reserved for multi-tenant isolation. |
| `occurred_on` | TIMESTAMPTZ — **domain** time (envelope). |
| `recorded_on` | TIMESTAMPTZ — **system** time (when persisted). |

The same event is also enqueued on the Doctrine transport in that one transaction — permanent log +
ephemeral delivery, atomic ([ADR D8](../adr/event-store-and-projections.md)).

## Versioning & evolution

- `eventVersion` is the payload schema version (default `1`). Bump it when an event's `toPrimitives()`
  shape changes.
- A stored `payload` is **never rewritten**; an `Upcaster` transforms an old version forward **on read**
  (chain empty / `NullUpcaster` today — written when the first event evolves).
- Adding a field a consumer needs the diff for goes on **that one event** (a delta shape or a
  `changedFields` entry), never a speculative global field ([ADR D10](../adr/event-store-and-projections.md)).

## Adding or evolving an event

To keep this catalog true:

1. **New event** — add `<Fact>DomainEvent extends DomainEvent` under
   `<Context>/<Aggregate>/Domain/Event/` with `eventName` `erpify.<ctx>.<agg>.<fact>` and
   `aggregateType`. `record()` it in the aggregate; `publish(...)` it from the use case via `EventBus`
   inside the write transaction.
2. **Route it** — add its FQCN under `routing:` → `async` in
   [`messenger.yaml`](../../api/config/packages/messenger.yaml) (the default bus is `allow_no_handlers:
   false`, so an unrouted/unhandled event fails fast).
3. **Wire consumers** — reactors as `<Effect>On<Event>` (`#[AsMessageHandler]`); projectors implementing
   `Projector` with their `subscribedTo()` and a read model.
4. **Evolve a payload** — bump `eventVersion` and add the matching `Upcaster`.
5. **Update this file** and its `index.md` entry.
