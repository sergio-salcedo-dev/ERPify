# Design — Behat event-observability contexts: outbox vocabulary, consumer effects, endpoint-local features

> **Date:** 2026-06-15 · **Branch:** `refactor/shared-behat-event-observability` · **Status:** draft
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
implementation reads the `InMemoryTransport` (`getSent()` envelopes) instead of an entity repository:

- "queue" ≡ Messenger **transport name** (`async`, `failed`, `sync`).
- `getMessages()` aggregates `getSent()` across the registered in-memory transports, tagging each envelope
  with its transport name; `on the queue :queueName` filters to one.
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
| — | **new** `BehatOutboxFactoryInterface` | parity with the reference factory-dispatch step; no concrete factories ship |

`LoggerContext` unchanged. `domain_event` (`StoredDomainEvent`) assertions use generic `EntityManagerContext`
steps (`there should have N "StoredDomainEvent" entities found by "name=..."`, `last inserted … should
match`); the event **payload** is asserted on the outbox event (dot-path), so nothing is lost.

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
Then I dispatch the :fqcn outbox event using factory with parameters: <PyString JSON>
Then I remove event :number from the outbox
Then I remove event of type :fqcn from the outbox
Then I print all outbox events  /  I print last outbox event
```

- **Count = pending, drains on ack.** `was created` / selection read the transport's **pending** queue
  (`InMemoryTransport::get()`, non-destructive); `ack()` `unset`s the queued message, so after a `consume`
  the count drops to **0**. This is the in-memory analog of the reference deleting the outbox row on consume,
  and it lets a scenario assert the full `publish → consume → ack` cycle (`0 outbox events … on queue …`
  after consuming), not just "handler ran". Property inspection of an event happens *before* the consume.
- **Selected-event JSON** = `DomainEvent::toPrimitives()` (canonical, getter-independent), wrapped in `Json`.
- **`I dispatch … with:`** reconstructs the typed event from JSON via **reflection over the constructor**,
  test-side only — **no `fromPrimitives()` added to the domain**. The reflection (`initEventDto` /
  `castParameter`) is extracted into a dedicated **`EventHydrator`** support class, not embedded in the
  context: it will grow with enums / UUIDs / `DateTimeImmutable` / value objects, and is easier to maintain
  and unit-test in isolation. `AbstractBankChangedDomainEvent` params (`bankId`,
  `occurredOn:DateTimeImmutable`, `name`, `shortName`, `createdAt`, `updatedAt`, nullable logo/storedObject)
  map cleanly; `occurredOn` casts to `DateTimeImmutable`; absent **optional** params fall back to the
  constructor default. Dispatch goes through the default bus, so `PersistDomainEventMiddleware` still writes
  the `domain_event` row and the in-memory transport receives it.
- **Factory dispatch** + `BehatOutboxFactoryInterface` ported for parity; no tagged factories ship (YAGNI —
  the reflection path covers Bank events). The step throws the reference's helpful "no factory found" error
  if used without one.

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

Port of the reference `NotifierContext`. Named `NotificationContext` (not `MailerContext`) on purpose: email
is the only channel today, but ERPify is heading toward Email/SMS/WhatsApp/Push, and the broader name is the
right home for all of them — better to start with that framing than rename later. Approach: **Symfony Mailer
null transport + recording subscriber** (full real path: handler → `PlainTextNotificationMailer` → Symfony
`MailerInterface`).

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
`getData()` = the JSON payload `{"type":"bank.created","bank":{…}}`), reusing the same `JsonToolTrait`
dot-path helpers:

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
  (`type=bank.deleted`). **No email** (handler skips delete).

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
`NotificationContext`, `MercureContext`; drop `MessengerContext` and `MessengerConsumeContext`. Bank features that gain a consume
step pay the idempotency claim against `handled_domain_event` (the known +N-per-wrapped-write pattern);
update each `.feature`'s `N requests got executed` budget to the **observed** count, not by reasoning about
middleware.

## Out of scope

- Production code changes beyond test wiring: no `fromPrimitives()` on domain events (D2 uses test-side
  reflection), no `messenger.yaml` routing change, no touch to the `php.lint.event-bus` gate, CQRS (#263),
  or the audit-logger epic.
- Concrete `BehatOutboxFactoryInterface` factories (interface ported for parity; the reflection dispatch
  covers Bank events).

## Verification

- `make php.behat` green; bank create/update/delete features show the full lifecycle (HTTP → outbox →
  consume → email/store) inline.
- No `messenger_messages` / `domain_event` SQL string in any `OutboxContext`/feature; outbox addressed only
  by logical queue name.
- `make php.quality` clean (PHPStan `level: max`, cs-fixer, rector, phpmd — watch the anon-readonly-class and
  long-variable gotchas in the new contexts).
