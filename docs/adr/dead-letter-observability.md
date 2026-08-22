# ADR — Dead-letter observability & operational replay of the `failed` transport

> **Status:** accepted · **Date:** 2026-06-22 · **Scope:** `api/src/Shared/Event` (Messenger failure-transport tooling).

## Context

Domain events are delivered asynchronously over a Doctrine Messenger transport; a handler that
exhausts its retries lands in `failure_transport: failed` (`doctrine://default?queue_name=failed`).
The only way to operate that dead-letter queue was the generic framework commands
(`messenger:failed:show|retry|remove`): no project view of what is stuck, no alarm when it grows, and
a documented footgun — under claim-based handler dedup, a naive `messenger:failed:retry` of an event
whose claim is still present short-circuits, so Messenger marks the message handled and drops it (see
[`domain-event-handler-idempotency.md`](./domain-event-handler-idempotency.md) D3).

The push+worker model puts *see / decide / replay* on the operator. This is a **pre-production**
requirement, not an urgent one: the only async consumer with an external effect is a non-critical
notification email and the claim store is already pruned. (Issue #350.)

## Decisions

### D1 — Read the failure transport behind an Application port, capability-probed

`Shared/Event/Application/DeadLetterReader` (`count()`, `entries(?limit)`) is implemented by
`MessengerDeadLetterReader` over the `@messenger.transport.failed` service. The transport is injected
as the read-only `ReceiverInterface` (inspection never sends — ISP) and probed at call time for
`ListableReceiverInterface` / `MessageCountAwareInterface`.

- **Why probe instead of type the intersection.** The dev/prod failure transport is the Doctrine
  transport (listable + countable); the **test** transport is `InMemoryTransport`, which is neither.
  Typing the constructor against the intersection would break container compilation under test. The
  guard keeps one wiring across every environment — an unlistable transport reads as empty.
- Discarded: **making the test `failed` transport Doctrine too** (fidelity at the cost of a DB-backed
  messenger table in tests and churn to shared test infra) and a **null adapter bound per-env** (more
  config than the one-line guard buys).

### D2 — Two commands, each named for the surface it extends

- `messenger:failed:status [--json] [--limit]` — aggregates the queue by message type and age. Slots
  into the `messenger:failed:*` family operators already know; `--json` is the scrapeable metric.
- `event:dedup:clear <eventId> <handler>` — releases one `(eventId, handler)` claim via the existing
  `DomainEventHandlerDeduplicator::release()`, the escape hatch for the replay footgun. `event:`
  namespace because dedup claims are event-specific.

This **resolves issue #258 option 1** (`app:dedup:clear`). Discarded #258 alternatives: an automatic
`WorkerMessageFailedEvent` listener (changes runtime behaviour, blurs the Messenger↔dedup boundary,
fragile handler extraction for multi-handler messages) and a two-phase claim (a structural change for
a pre-prod, single-consumer concern). The manual command is boring, zero-runtime-change and testable.

### D3 — Alarm is a scheduled structured log line, not a Sentry capture or an HTTP health endpoint

`ReportDeadLetterBacklogHandler` runs hourly off the existing `HandledDomainEventMaintenanceSchedule`
(`scheduler_maintenance` transport, already consumed) and emits one `error` log line when the queue
breaches `maxBacklog` (default 0 → any backlog) or `maxAgeHours` (default 24); silent otherwise.

- **Why a log, not Sentry.** The Monolog→Sentry bridge is deliberately left unwired
  (`config/packages/sentry.yaml`); Sentry captures unhandled throwables, not log lines. The scheduled
  check is the "cron" arm the requirement allows. Wiring Monolog→Sentry stays a separate, opt-in decision.
  **A log line is also the only arm that stays silent when nothing is wrong** — an exception is a fault
  signal, and a healthy backlog check is not a fault.
- **What throwing would and would not do.** It would *not* recurse: scheduler transports are not among
  the configured failure transports, so the failure listener short-circuits, and no retry strategy is
  registered — an uncaught handler exception costs one `critical` line, no re-send and no `failed` row.
  The reason to prefer a log is therefore the one above, not a recursion hazard.
- **Why no `/health/dead-letter` endpoint.** `messenger:failed:status --json` already exposes the
  metric and `Backoffice/Health` already owns HTTP health; a third surface for the same data buys
  nothing (YAGNI). Promote to an endpoint only if a scraper needs HTTP.

### D4 — The queue is bounded at 30 days, and the window is coupled to D3's alarm

`failed` was the only sibling of `audit_log` and `handled_domain_event` with no retention at all: durable on
purpose, so an operator can see, decide and replay — but "durable" had been standing in for "unbounded", and
nothing capped a table that only ever grows. A daily prune on the same maintenance schedule deletes rows past
**30 days**, matching `handled_domain_event` and the revoked-session sweep. Past that window the decision has
been made by not being made.

**The window is not a free parameter, because the prune and D3's alarm read the same rows.** The alarm reports
the age of the *oldest surviving* message, so a retention window near its `maxAgeHours` would cap that age:
the queue would go quiet because the pruner deleted the evidence, not because the backlog cleared. At 30 days
against 24 hours the margin is thirty-fold, asserted in the schedule's test with an absolute floor as well as
a ratio — a pure ratio degenerates to no constraint the day `maxAgeHours` follows `maxBacklog` to zero.

Be precise about what that margin buys: it **delays** the silence, it does not prevent it. `maxBacklog` is 0,
so the alarm fires on any resting row and goes quiet when the table empties — which at thirty days is exactly
what the prune does. What the margin guarantees is that nothing is deleted before the alarm has had ample
time to raise it.

**The window is the triage SLA**, which #525 asked for separately and which nothing else in the repo states:
a failure not looked at within 30 days is deleted. That is the whole deadline, and it is deliberately the same
number as the window rather than a second one to keep in sync.

**What it costs, named because `event_store` does not cover it.** The domain event survives — it is appended
before dispatch, atomically with the aggregate write. The *failure context* does not: the exception class and
message, the retry count and the redelivery stamp live only in `messenger_messages.headers` and go with the
row. The prune keeps the *what* and discards the *why*.

**The honest limit of the safety argument.** "The alarm will have fired for twenty-nine days first" is only as
good as the alarm's reach, and by D3 that reach is one line on container stderr: the Monolog→Sentry bridge is
unwired, the compose `logging:` blocks bound the driver by size alone (no TTL), and nothing scrapes
`messenger:failed:status`. So the
margin protects against the pruner *masking* a backlog; it does not deliver the backlog to anyone. Wiring an
alerting sink is D3's debt, not this decision's, and this decision does not pretend to have paid it.

Discarded: **operator-only deletion** (`messenger:failed:remove`) — it is the status quo, and the status quo
is a table nobody prunes; **pruning by `available_at`/`delivered_at`** — those track redelivery state, not age
since failure, so a redelivered message would reset its own clock; **a longer window matching `audit_log`'s
90-day `activity` tier** — that tier serves a trail somebody may need to reconstruct later, while a dead letter
is a work item, and a work item nobody claimed in a month is not going to be claimed in three.

The prune imitates `DbalAuditLogPruner` — advisory lock (I1 of
[`maintenance-job-execution-contract.md`](./maintenance-job-execution-contract.md)), batched,
`ORDER BY id … FOR UPDATE` — and not the bare `DELETE` of the dedup pruner, because it contends with the
transport's own consumer. Its statement carries a predicate the audit one has no analogue for: `async` and
`failed` are one physical table discriminated by `queue_name`, so a statement missing it deletes work that is
in flight.

## Consequences

- No schema change, no new transport, no compose change — the hourly tick and the daily prune both ride the
  existing maintenance schedule. `Shared/Event` folds into the deptrac `Shared.*` layers, so no gate edit.
- The GDPR argument for keeping person-aggregate events off persisted transports is **unchanged**: a 30-day
  sweep is not an erasure path, so a queued person id still outlives the erasure the application confirmed.
  The registry (`api/.persistent-transport-policy`) and its gate keep their teeth.
- The operational runbook (safe replay order *clear claim → retry*) lives in
  [`architecture-api.md`](../architecture-api.md), not here.
