# Message & use-case naming — "CQRS-shaped, pre-bus"

ERPify has **no `CommandBus`/`QueryBus`** yet — they are deferred to #263. Naming must not promise a
dispatch capability the runtime lacks. This rule fixes how messages and use cases are named in that
pre-bus window, and how the names converge when the bus lands. Decision record:
[`../adr/event-driven-architecture.md`](../adr/event-driven-architecture.md) (D5); the publication
boundary it builds on is enforced by `make php.lint.event-bus`.

## Two planes

- **Semantics** — intent: a write vs a read. Stable, independent of runtime.
- **Execution** — runtime: direct method call vs bus/transport dispatch. The `Handler` suffix asserts
  "dispatched by a bus/transport." The name therefore tracks the *execution* plane and must **never run
  ahead of it**. A `*CommandHandler` with no `CommandBus` is scenery (*atrezo*): a name promising a
  dispatch the system cannot perform. CQRS is separation of *execution*, not of *names*.

## The five message categories

| # | Category | Name shape | Lives in | Dispatched by a bus/transport today? | Example |
|---|----------|-----------|----------|--------------------------------------|---------|
| 1 | **Command** (write intent) | `<Verb><Noun>Command` | `*/Application/Command/` | No — data-carrying intent, consumed by a use case via direct call | `CreateBankCommand`, `UpdateBankCommand` |
| 2 | **Query** (read intent) | `<Verb><Noun>Query` | `*/Application/Query/` | No — same, direct call | `SearchBanksQuery`, `SearchBankAccountsQuery` |
| 3 | **Domain-event subscriber** | `<Effect>On<Event>` | `*/Infrastructure/Messenger/` | **Yes** — `#[AsMessageHandler]`, transport-routed (N:M) | `RefreshRealtimeOnBankChanged`, `SendEmailOnBankChanged` |
| 4 | **Audit / observability subscriber** | `<Effect>On<X>` | `*/Infrastructure/Audit/` | **Yes** — `#[AsMessageHandler]`; message is **not** a `DomainEvent` | `RecordAuditLogOnBankAccountsViewed` |
| 5 | **Scheduled / maintenance handler** | `<Verb><Noun>Handler` | `Shared/Infrastructure/Messenger/Maintenance/` | **Yes** — reacts to a Scheduler tick / command-style message (1:1) | `PruneHandledDomainEventsHandler` |

- Category 3's `On<Event>` may name a concrete event or the change umbrella a subscriber covers —
  `OnBankChanged` is honest because one class carries an `#[AsMessageHandler]` method per lifecycle event
  (`onBankCreated`/`onBankUpdated`/`onBankDeleted`), legibly grouping the reaction instead of splitting
  into three classes. The `AbstractBankChangedDomainEvent` supertype groups the created/updated pair
  (delete extends `DomainEvent` directly), letting those two collapse to one method where wanted.
- Category 4's producer is an explicit, reviewed exception in `api/.event-dispatch-allowlist`
  (`BankAccountSearcher` → `BankAccountsViewedAuditEvent`): best-effort, must **not** ride the
  transactional `EventBus`. It migrates to an `AuditLogger` port when that subsystem is built.
- Category 5 keeps `*Handler` because the suffix is *true* there — a transport-routed message with
  exactly one handler (1:1). It is the only `*Handler` that is honest pre-bus.

## Direct-execution use cases — not a message category

The write/read use cases that Command/Query intents feed are invoked by **direct method call**, never a
bus. They keep verb-noun names — `BankCreator`, `BankUpdater`, `BankDeleter`, `BankFinder`,
`BankDetailFinder`, `BankSearcher` — and publish their domain events through the `EventBus` port.
Renaming them to `*CommandHandler`/`*QueryHandler` now is the scenery anti-pattern: it asserts bus
dispatch that does not exist. They convert to handlers only when the bus lands (#263) — name and runtime
migrating *together*, so the `wrapInTransaction` boundary moves to the bus middleware in the same step.

## Banned

- `*CommandHandler` / `*QueryHandler` on any class invoked by direct call (no bus ⇒ false promise) — use
  `Creator`/`Updater`/`Deleter`/`Finder`/`Searcher`.
- `*Subscriber` / `*Listener` for a Messenger consumer — collides with Symfony's `EventDispatcher`
  vocabulary; `#[AsMessageHandler]` *is* Messenger's "subscriber." Use `<Effect>On<Event>`.
- A generic `*Handler` name for a **domain-event** subscriber — it hides the N:M event reaction that
  `<Effect>On<Event>` makes legible (`*Handler` is reserved for category 5).
- Story / NFR / ticket IDs in any class or message name.

## New aggregate / use case — template

- write intent → `Application/Command/<Verb><Noun>Command`
- read intent → `Application/Query/<Verb><Noun>Query`
- write use case → `Application/<Noun>{Creator|Updater|Deleter}` — direct call, publishes via `EventBus`
- read use case → `Application/<Noun>{Finder|Searcher}`
- a side effect of a domain event → `Infrastructure/Messenger/<Effect>On<Event>` — `#[AsMessageHandler]`,
  idempotent, or claims via `DomainEventHandlerDeduplicator` for a non-idempotent external effect
- an audit / observability trail → `Infrastructure/Audit/<Effect>On<X>` — producer allowlisted

Persistence strategy (state-oriented default vs event-sourced) is a separate per-aggregate decision,
presented to the user before modeling: [`../adr/bank-bankaccount-modeling.md`](../adr/bank-bankaccount-modeling.md).

## Convergence — when the bus lands (#263)

`Bank` is the **reference example, not a mandatory template**; per-aggregate persistence still governs
structure. Two forces drive the legacy `Creator`/`Finder` set to the mono-dialect end state, no third:

- **Primary (gated):** generation control — once the `CommandBus` exists, no *new* `Creator`/`Finder`
  use case may be born; new write/read use cases are bus handlers. A `deptrac.baseline`-style ratchet
  freezes the legacy set so a regression fails the build.
- **Secondary (opportunistic, free):** boy-scout — a legacy use case *touched* for another reason
  converts to a handler. This is the only force that *shrinks* the legacy set; freezing generation alone
  fossilises it.
- **Never:** a dated sweep of cold code.

End state: a closed, mono-dialect creation space plus a closed, shrinking legacy set with a cold fossil
tail — the cognitive cost is bounded because the tail sits off the active paths.

## Why this shape (not full-CQRS naming now)

Naming `Creator → CommandHandler` up front was rejected in adversarial review: a `*Handler` with no bus
is scenery. The intent plane (Command/Query) is stable and is named now; the execution plane — and the
`Handler` suffix that signals it — waits for the runtime. The full record, including the per-path
transactional / enforcement / publication invariants that govern the migration, is in
[`../adr/event-driven-architecture.md`](../adr/event-driven-architecture.md) (D5).
