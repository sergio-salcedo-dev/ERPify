# Design — Behat event-observability contexts: outbox vocabulary, consumer effects, endpoint-local features

> **Date:** 2026-06-15 · **Delivery:** D8 planned four PRs; delivered as one combined branch by request · **Status:** implemented
> **Reference:** `chz_projects/event-bundle-dev/src/Behat/{OutboxContext,NotifierContext,SymfonyMessengerConsumeContext,SymfonyMessengerContext}.php`
> **Scope:** `api/tests/Behat/*`, `api/features/backoffice/bank/*`, `api/config/services_test.yaml`,
> `api/config/packages/*.yaml` (test mailer DSN), `api/tools/behat/behat.yml.dist`.

## Problem

The Behat seam for the event-driven outbox (shipped in #277) has defects of *meaning*:

1. **`OutboxContext` asserts the wrong thing.** Despite its name it reads the `domain_event` store
   (`StoredDomainEvent`), which the event-driven ADR calls an *audit log a posteriori, not an outbox*. The
   outbox is the Messenger transport. Assertions must address it by **logical queue name**, never SQL on a
   concrete table, so a Doctrine → Redis swap needs at most minor PHP changes and **no `.feature` changes**.
2. **No consumer-effect coverage.** No scenario consumes a published event and asserts the handler did its
   job. `BankChangedNotifyEmailHandler` (notification email on bank created/updated) is covered only at unit
   level with a recording fake; the full *publish → consume → mail* pipeline is untested.
3. **Event observability lives in a side feature.** `features/shared/domain_events/event_publication.feature`
   holds the bank-write event assertions, so reading `create.feature` does not show everything that happens
   at `POST /backoffice/banks`.

The reference project already solved this shape. ERPify's `tests/Behat/Support/PostProcess/JsonToolTrait`
descends from the same Chiliz lineage and **already exposes every helper** the reference `OutboxContext` uses
(`jsonPropertyShouldHaveElements`, `jsonPropertyShouldNotExist`, `propertyPostProcessName/Value`, dot-path
with array indices). So the step vocabulary ports over with **no changes to the trait**.

## Key adaptation: in-memory transport, not an outbox table

The reference `OutboxContext` reads a real Doctrine `OutboxEvent` entity table (their outbox is a custom
table). ERPify uses Symfony Messenger directly, whose test double is `in-memory://?serialize=true`
(`config/packages/messenger.yaml`, `when@test`). So ERPify keeps the **same Gherkin step vocabulary** but the
implementation reads the `InMemoryTransport` **pending queue** instead of an entity repository:

- "queue" ≡ Messenger **transport name** — `async` and `failed` only. `sync` (`sync://`) is a `SyncTransport`,
  not an `InMemoryTransport`: it has no queue (messages handle inline), so it is never inspectable. Aggregation
  must `instanceof InMemoryTransport`-filter, or iterating the configured transports throws on `sync`.
- Count and selection read `InMemoryTransport::get()` — the **pending** queue, decoded fresh, non-destructive.
  Aggregate `get()` across the registered in-memory transports, tagging each envelope with its transport name;
  `on the queue :queueName` filters to one. **Not `getSent()`**: `getSent()` returns every envelope ever sent
  and is never drained by `ack()`, so it cannot express the post-consume `0` that D6 asserts (verified in
  `vendor/symfony/messenger/.../InMemoryTransport.php`: `ack()` `unset`s from `$queue` only, never `$sent`).
- reset ≡ `transport->reset()` per transport (the in-memory transport survives the request and leaks across
  scenarios otherwise — known gotcha).

This satisfies the transport-agnostic goal *better* than the reference: the test double is in-memory
regardless of the production DSN, so swapping Doctrine → Redis touches neither features nor context.

## Decisions

### D1 — Context taxonomy (end state)

| Today | Action | End state |
|---|---|---|
| `OutboxContext` (reads `domain_event`) | **rewrite** | full reference `OutboxContext` vocabulary over the in-memory transport |
| `MessengerContext` (producer `getSent` steps + `is processed`) | **delete** | producer assertions superseded by `OutboxContext` vocabulary; `is processed` → `MessengerConsumerContext` |
| `MessengerConsumeContext` | **rename + extend** → `MessengerConsumerContext` | generic `messenger:consume` with options + command-output assertions + `is processed` |
| — | **new** `NotificationContext` | notification effects (port of reference `NotifierContext`); email today, named for the coming Email/SMS/WhatsApp/Push surface |
| — | **new** `MercureContext` + recording `HubInterface` test double (`services_test.yaml`) | asserts the realtime `Update` published on consume (SNS-analog vocabulary); recording hub also stops the publisher hanging — **prerequisite for D3/D4** |
| — | **new** `EventHydrator` support class | encapsulates the test-side reflection that rebuilds a domain event from JSON (D2) |

`LoggerContext` unchanged. The reference factory-dispatch step and its `BehatOutboxFactoryInterface` are
**not ported** — no real case needs a factory today and an always-throwing "no factory found" step is
speculative surface (working principle #2). Add it only when a non-reflectable event appears.

`domain_event` (`StoredDomainEvent`) assertions use generic `EntityManagerContext` steps (`there should have
N "StoredDomainEvent" entities found by "name=..."`, `last inserted … should match`). The event payload's
**nested** properties are asserted on the **pending** outbox event via dot-path (D2) — valid only before the
consume drains it. `EntityManagerContext` matches scalar columns, not dot-paths into the `payload` jsonb, so
a nested assertion against the *persisted* row (post-drain) has no generic step today; no current scenario
needs one, so it stays out of scope rather than "nothing is lost".

### D2 — `OutboxContext`: port the reference vocabulary, drop "table" from the wording

Stateful selection (`I got the event number N …` sets the "current" event; later `The outbox event property
…` steps read it). The reference says "from the outbox **table**"; ERPify has **no outbox table** (it reads
the `InMemoryTransport`), so the wording drops "table" to keep the abstraction honest — `from the outbox` /
`from queue "async"`:

```
@BeforeScenario  /  Given I reset the outbox context
Then :number outbox event(s) was/were created
Then :number outbox event(s) was/were created on the queue :queueName
Then I got the event number :number from the outbox
Then I got the event number :number on queue :queueName from the outbox
Then The outbox event should be of type :fqcn
Then The outbox event property :property should be equal to :value
Then The outbox event property :property should be null
Then The outbox event property :property should not be null
Then The outbox event property :property should have :count element(s)
Then The outbox event property :property should exist
Then The outbox event property :property should not exist
Then there should (not) have been an outbox event created containing: <table>
Then I dispatch the :fqcn outbox event with: <PyString JSON>
Then I remove event :number from the outbox
Then I remove event of type :fqcn from the outbox
Then I print all outbox events  /  I print last outbox event
```

- **Count = pending, drains on ack.** `was created` / selection read the transport's **pending** queue
  (`InMemoryTransport::get()`, non-destructive — **not `getSent()`**, which retains acked envelopes); `ack()`
  `unset`s the queued message, so after a `consume` the count drops to **0**. This is the in-memory analog of
  the reference deleting the outbox row on consume, and it lets a scenario assert the full
  `publish → consume → ack` cycle (`0 outbox events … on queue …` after consuming), not just "handler ran".
  Property inspection of an event happens *before* the consume.
- **Selected-event JSON** = `DomainEvent::toPrimitives()` (canonical, getter-independent), wrapped in `Json`.
  Note `toPrimitives()` does **not** carry `occurredOn` (it lives on the envelope, not the payload), so the
  read shape and the dispatch shape differ — see the next bullet.
- **`I dispatch … with:`** reconstructs the typed event from JSON via **reflection over the constructor**,
  test-side only — **no `fromPrimitives()` added to the domain**. The reflection (`initEventDto` /
  `castParameter`) is extracted into a dedicated **`EventHydrator`** support class, not embedded in the
  context, so it can be unit-tested in isolation. For Bank today it needs only one cast (`occurredOn` →
  `DateTimeImmutable`); the enum / UUID / value-object handling is added when a second aggregate needs it, not
  pre-built. **The dispatch JSON is a superset of `toPrimitives()`**: `AbstractBankChangedDomainEvent`'s
  constructor requires `occurredOn` (2nd param, `DateTimeImmutable`, **no default**), which `toPrimitives()`
  omits — so the PyString must include an explicit `occurredOn` or the hydrate fails on the missing required
  arg. Only the four logo / stored-object params are genuinely optional and fall back to the constructor
  default; `bankId`, `name`, `shortName`, `createdAt`, `updatedAt` are required and present in `toPrimitives()`.
  Dispatch goes through the default bus, so `PersistDomainEventMiddleware` still writes the `domain_event` row
  and the in-memory transport receives it.

### D3 — `MessengerConsumerContext`: real consume + command-output assertions

Rename `MessengerConsumeContext` → `MessengerConsumerContext`. Add a small command-runner capability (the
reference's `SymfonyCommandContext` base) so the consumer example ports directly:

```
When I execute "messenger:consume" with options: <PyString JSON>   # receivers, --limit, --time-limit, -vv
When I consume :count message(s) from the :transportName transport [with time limit :seconds]
Then the command should succeed
Then the output should contain :text
Then message :number sent to :transportName is processed                # lightweight in-process, moved here
```

Keeps the existing safeguards (5s `--time-limit` backstop, stale stop-worker-listener purge). The runner
captures the `BufferedOutput` so `the command should succeed` (exit 0) and `the output should contain`
(e.g. `"… was handled successfully (acknowledging to transport)"`) can assert the worker actually ran the
handlers.

### D4 — `NotificationContext`: assert the email handler effect via the real mailer path

Port of the reference `NotifierContext`. **Decided: the context is `NotificationContext`, not `MailerContext`.**
The behaviour the scenario verifies is *a notification was sent*, not *an email object exists* — that the bank
write produced an outbound notification. Symfony Mailer is the transport that happens to carry it today; that
it's `MailerInterface` under the hood is an implementation detail, not the contract. When SMS / WhatsApp / Push
/ Slack arrive, the same context grows channel verbs and the name still reads true — so the name is fixed now,
not deferred. Approach for the email channel: **Symfony Mailer null transport + recording subscriber** (full
real path: handler → `PlainTextNotificationMailer` → Symfony `MailerInterface`).

- Test config: `MAILER_DSN=null://null` (test env only).
- A static recording collector (the `ChatterMock` pattern) fed by a Behat event subscriber on Symfony
  Mailer's `MessageEvent`, capturing the `Email` objects; cleared `@BeforeScenario`.

```
@BeforeScenario  /  Given I reset the notification context
Then :number notification email(s) was/were sent
Then I get the sent notification email number :value
Then The notification email recipient should be :value
Then The notification email subject should be equal to :value
Then The notification email body should contain :text
```

Divergence from `NotifierContext`: ERPify notification emails are **plain text** (key/value lines), not a
JSON subject/body, so the email steps assert recipient + subject + body-contains + count rather than
dot-path JSON properties. The structured dot-path vocabulary lives on the **outbox event** (D2) and the
**Mercure update** (D5), where the JSON payload actually is. Future channels add their own verbs under the
same context.

### D5 — `MercureContext` + recording Mercure hub in test (prerequisite for D3/D4)

**This is foundational, not optional: it gates D3 and D4.** Any scenario that consumes a bank event runs
`BankRealtimePublisherHandler` too; without the recording hub the consume tests depend indirectly on a live
Mercure hub (network I/O, ~5s hang). So D5 must land before the consume-based assertions of D3/D4 are usable.

`BankRealtimePublisherHandler` (created/updated/deleted) depends on `Symfony\Component\Mercure\HubInterface`
and publishes to the real hub. Consuming `async` runs *all* handlers, so the realtime publisher would hit
the hub and hang ~5s in Behat (no test double exists today). Bind a **recording** `HubInterface` in
`services_test.yaml` (the `ChatterMock` static-collector pattern) that captures the published `Update`s
instead of doing network I/O — this both removes the hang and exposes them for assertion.

`MercureContext` is the ERPify analog of the reference's SNS assertions (the "no SNS" mapping — the realtime
hub *is* the structured downstream sink). It reads each captured `Update` (`getTopics()` ≈ TopicArn,
`getData()` = the JSON payload), reusing the same `JsonToolTrait` dot-path helpers.

**The delete payload has a different shape — assert it accordingly.** `BankRealtimePublisherHandler`
publishes two shapes:

- created / updated → `{"type":"bank.created"|"bank.updated","bank":{id,name,shortName,createdAt,updatedAt,accountCount}}`
  — assert on `bank.*` (logo / stored-object fields are intentionally dropped from the realtime payload).
- deleted → `{"type":"bank.deleted","id":"<uuid>"}` — **no `bank` object**; assert `type` and the top-level
  `id`, never `bank.shortName`.

Topics: created publishes to the collection topic only; updated and deleted publish to both the collection
topic and the per-bank topic (`MercureBankTopic::forBank($bankId)`), so `should have 2 topic(s)` holds for
update/delete.

```
@BeforeScenario  /  Given I reset the mercure context
Then :number Mercure update(s) was/were published
Then I get the published Mercure update number :value
Then The Mercure update topic :number should be equal to :value
Then The Mercure update should have :count topic(s)
Then The Mercure update property :property should be equal to :value
Then The Mercure update property :property should be null / not null / have :count element(s) / exist / not exist
```

### D6 — Dissolve `event_publication.feature` into endpoint features

Delete `features/shared/domain_events/event_publication.feature`; relocate so each endpoint feature is
self-contained executable documentation of the endpoint — `publish → consume → ack → observable effect`:

- **`create.feature`** — outbox event (count + type + payload via `The outbox event property …`),
  `StoredDomainEvent` row via `EntityManagerContext`, then `consume → 0 outbox events on queue "async"` (ack)
  + notification email (`[ERPify] Bank created`) + Mercure update (`type=bank.created`). The `422`
  rejected-write + logger assertion lands here too.
- **`update.feature`** — outbox `BankUpdatedDomainEvent`, store row, consume → ack + email
  (`[ERPify] Bank updated`) + Mercure update (`type=bank.updated`, includes the per-bank topic).
- **`delete.feature`** — outbox `BankDeletedDomainEvent`, store row, consume → ack + Mercure update
  (`type=bank.deleted`, top-level `id` not a `bank` object, 2 topics). **No email** (handler skips delete).

Canonical shape:

```gherkin
When I send a POST request to "/backoffice/banks" with body: """{"name":"Event Bus Bank","shortName":"EBB"}"""
Then the response status code should be 201
And 1 outbox event was created on the queue "async"
And I got the event number 1 on queue "async" from the outbox
And The outbox event should be of type "Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent"
And The outbox event property "shortName" should be equal to "EBB"
And there should have 1 "StoredDomainEvent" entities found by "name=erpify.backoffice.bank.created"
When I consume 1 message from the "async" transport
Then the command should succeed
And 0 outbox events were created on the queue "async"
And 1 notification email was sent
And The notification email subject should be equal to "[ERPify] Bank created"
And 1 Mercure update was published
And The Mercure update property "bank.shortName" should be equal to "EBB"
```

### D7 — Registration & query budgets

`tools/behat/behat.yml.dist`: register `OutboxContext` (rewritten), `MessengerConsumerContext`,
`NotificationContext`, `MercureContext`; drop `MessengerContext` and `MessengerConsumeContext`. Registration
happens per-PR (D8), not in one cut-over.

**Query-budget decision — write-path budgets stay per-endpoint; consume cost is asserted once.**

- Each endpoint feature's `N requests got executed` assertion measures the **synchronous write path only** and
  is positioned **before** any `consume` step. It therefore does **not** change when a consume step is added,
  and its current values stay put (create 8, update 9, delete 8). The endpoint scenarios that go on to
  `consume → ack → email/Mercure` assert those **effects**, not a query count.
- The query cost of consuming is asserted in **exactly one dedicated scenario**. A consume runs *every* `async`
  handler in-process on the counted `default` connection, so its delta is multi-sourced — the
  `handled_domain_event` claim `INSERT` (`DbalDomainEventHandlerDeduplicator::claim`), the realtime handler's
  `BankAccountCountEnricher` count DQL, the email path's reads, the ack — and is pinned to the **observed**
  count, never reasoned from middleware.
- Rationale (why this over "re-pin every endpoint"): folding consume cost into each endpoint's single budget
  turns every future `async` handler into a cascade of feature breakages that validate no business behaviour.
  Isolating it means a new handler breaks **one** scenario, deliberately, and that one number gets re-pinned.

Before relying on the dedicated scenario, confirm the in-process `messenger:consume` queries actually land on
the counted `default` connection — the `SqlQueryContext` seed-on-side-connection precedent shows some
connections are untracked; verify by dumping, don't assume.

### D8 — Delivery: land in four PRs, each green on its own

The full change touches four contexts, two test doubles, env + service config, and every bank feature at
once — too big for one PR and risky against the fragile query budgets. Split so `make php.behat` stays green
after each:

| PR | Lands | Depends on | Notes |
|---|---|---|---|
| **PR1** | Recording Mercure `HubInterface` (`services_test.yaml`) + `MessengerConsumerContext` (real `messenger:consume`, command-output steps) | — | Additive: new context + hub binding, no feature edits. The hub double is the D5 prerequisite, so it ships first and unblocks any consume. Add a guard that the recording hub is actually bound (a missing bind silently reintroduces the ~5s hang, not an error). |
| **PR2** | `NotificationContext` + `MAILER_DSN=null://null` (test env) + recording `MessageEvent` subscriber | — | Additive; independent of PR1. Reset the static collector `@BeforeScenario` and filter the email count to the notification channel so stray framework mail can't inflate it. |
| **PR3** | `OutboxContext` rewrite (pending-transport vocabulary) + delete `MessengerContext` | — | **Cannot land "rewrite only."** Removing the old `domain event named … should be stored` / `message was sent` steps breaks `create.feature` (37–39) and `event_publication.feature`, so PR3 migrates those existing callers to the new outbox vocabulary **in lockstep**. No consume/email/Mercure assertions yet. |
| **PR4** | D6 lifecycle inline (`consume → ack → email → Mercure`) across create/update/delete; delete `event_publication.feature`; add the **one** dedicated consume-cost scenario (D7) | PR1, PR2, PR3 | The only PR that asserts the full pipeline, so it needs all three doubles/contexts first. Endpoint write-path budgets (8/9/8) stay unchanged — the consume cost is pinned in the dedicated scenario, not re-folded per feature. Also handles the commented-out `create_with_logo` / `create_with_stored_object` outbox blocks (revive or delete — don't leave stale). |

PR1 and PR2 are parallelizable; PR3 is independent but breaking-in-lockstep; PR4 is the integration step.

The `message … is processed` step (D3) relocates from `MessengerContext` to `MessengerConsumerContext` **in
PR3** — atomically with deleting `MessengerContext`. Defining it in both at once (e.g. adding it to the new
context in PR1 while the old one survives) is an ambiguous-step definition and fails the suite. So PR1's
`MessengerConsumerContext` ships only the consume + command-output steps; `is processed` joins it in PR3.

## Out of scope

- Production code changes beyond test wiring: no `fromPrimitives()` on domain events (D2 uses test-side
  reflection), no `messenger.yaml` routing change, no touch to the `php.lint.event-bus` gate, CQRS (#263),
  or the audit-logger epic.
- `BehatOutboxFactoryInterface` and the `… using factory with parameters` step — dropped entirely (not just
  the concrete factories). The reflection dispatch covers Bank events; add the factory seam when a real
  non-reflectable case appears.

## Verification

- `make php.behat` green; bank create/update/delete features show the full lifecycle (HTTP → outbox →
  consume → email/store) inline.
- No `messenger_messages` / `domain_event` SQL string in any `OutboxContext`/feature; outbox addressed only
  by logical queue name.
- `make php.quality` clean (PHPStan `level: max`, cs-fixer, rector, phpmd — watch the anon-readonly-class and
  long-variable gotchas in the new contexts).

## Implementation notes (delivered as one branch)

Findings that refined the decisions during implementation — all verified empirically, not reasoned:

- **D3 — consume runs the `Worker` class directly, not `messenger:consume` via a console application.**
  Running the CLI command on the already-booted Behat kernel resets `ResetInterface` services between
  messages, and `in-memory://` is one of them — the reset wipes the pending queue *before* the worker
  reads it (observed: the queue drains, the worker logs no "Received message", no handler runs, no error).
  `--no-reset` does not help (a reset still fires on the booted kernel). `MessengerConsumerContext`
  therefore constructs a real `Worker` against the exact transport instance the rest of the suite reads,
  on a private dispatcher carrying only this run's message-limit + time-limit. Same worker, bus middleware
  and handlers — only the CLI wrapper is dropped. `the command should succeed` / `the output should
  contain` assert the worker's `ConsoleLogger`-buffered output.
- **D4 — the mailer is async, so `MessageEvent` fires twice per email** (once queued onto the bus, once on
  the real send). `RecordingMailerSubscriber` records only `!isQueued()` so each notification counts once.
  `MAILER_DSN=null://null` and `DEFAULT_NOTIFICATION_EMAIL` are set in `.env.test` (the latter so the
  recipient is a deterministic, non-personal address).
- **D7 — no dedicated consume-cost scenario.** Dumping the connection stats after an isolated consume shows
  **0** tracked queries: the in-process worker's queries land on a connection `TestDebugDataHolder` does
  not record (the same untracked-side-connection precedent the section warned about). A query budget over
  the consume would assert nothing meaningful — and, conversely, the consume cost *cannot* leak into the
  endpoint write-path budgets (which assert before the consume and are untracked anyway), so the budgets
  stay 8 / 9 / 8 and there is nothing to isolate.
- **D6 — gherkin lint, no per-file disables.** The inline lifecycle adds a second action phase and exceeds
  the default 21-step budget. `keyword-order` is kept clean by writing the consume phase with `And` (one
  `When` per scenario); `scenario-size.maxSteps` was raised 21 → 35 in `tools/gherkinlint/gherkinlint.json`
  to match the new convention. No `@gherkinlint-disable-rule` comments.
- **D2 — `EventHydrator` dispatch** maps `AbstractBankChangedDomainEvent` cleanly (the PyString is a superset
  of `toPrimitives()`, carrying `occurredOn`). Note `BankDeletedDomainEvent`'s constructor parameter is
  `aggregateId` (it extends `DomainEvent` directly), not `bankId`, so a dispatch payload for it keys on
  `aggregateId`; the Bank write features exercise the outbox via HTTP, not dispatch, so this is parity-only.

## Review Findings

Adversarial review (Blind Hunter + Edge Case Hunter + Acceptance Auditor), 2026-06-16. PR #316.

- [x] [Review][Patch] (resolved from Decision ①) Hardened each consume scenario with `And the output should contain "handled successfully (acknowledging to transport)"` — `the command should succeed` only reads the worker exit code, which stays 0 even on handler failure. Verification correction: the proposed `0 outbox events … on queue "failed"` was **dropped as vacuous** — `runWorker` builds a private dispatcher with no failure-transport listener, so a failed handler is `reject()`ed off `async` (never routed to `failed`); the `handled successfully` INFO line is logged only on the ack/success path, so it is what actually catches a silent failure. [`features/backoffice/bank/{create,update,delete}.feature`]
- [x] [Review][Patch] (resolved from Decision ②) Added `api/tests/Unit/Behat/Support/EventHydratorTest.php` (DateTimeImmutable cast, required-arg-missing throw, optional-default fallback, missing-class throw, `aggregateId`-keyed delete event) **and** `api/features/backoffice/bank/dispatch_event.feature` exercising `I dispatch the :fqcn outbox event with:` end-to-end (dispatch → consume → email + Mercure) — the dispatch path is no longer dead surface.
- [x] [Review][Patch] Rewrote the static-state rationale comments that claimed the doubles bridge "the consume worker's command application / separate containers" — D3's final impl drives `Worker` directly in the same kernel. [`api/tests/Behat/Support/Mercure/RecordingHub.php`, `api/tests/Behat/Support/Notification/RecordedEmails.php`]
- [x] [Review][Patch] Guarded `messagesOnQueue()` to fail loudly when the named queue is not an inspectable in-memory outbox queue (`sync`/typo) — previously `0 outbox events … on queue "<typo>"` passed falsely. [`api/tests/Behat/Context/OutboxContext.php`]
- [x] [Review][Defer] New `*property … should be null` / `should have :count elements` steps error (uncaught `evaluate()` throw) on an absent dot-path instead of asserting — pre-existing `JsonToolTrait`/`JsonInspector` `THROW_ON_INVALID_PROPERTY_PATH` semantic (`should not exist` correctly try/catches it). No current scenario hits it. [`api/tests/Behat/Context/{OutboxContext,MercureContext}.php`] — tracked in GitHub issue #320
- [x] [Review][Patch] (post-sync with `main`/#315) `MercureContext`/`NotificationContext` no longer silently default to `array_key_last(...)` when several updates/emails were recorded and none was selected — they now fail loudly demanding an explicit `I get the … number N`. Covered by `MercureContextTest` / `NotificationContextTest`. [`api/tests/Behat/Context/{MercureContext,NotificationContext}.php`, `api/tests/Unit/Behat/Context/`]
- [x] [Review][Defer] Non-queue-qualified outbox steps (`:number outbox event was created`, `I got the event number :number from the outbox`) concatenate `async`+`failed` under one global 1-based index, so anything on `failed` would shift the numbering. Safe today (`failed` empty in the happy path; features use the queue-qualified variants). [`api/tests/Behat/Context/OutboxContext.php`] — tracked in GitHub issue #319
