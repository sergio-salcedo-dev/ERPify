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

## Consequences

- No schema change, no new transport, no compose change — the hourly tick rides the existing
  maintenance schedule. `Shared/Event` folds into the deptrac `Shared.*` layers, so no gate edit.
- The operational runbook (safe replay order *clear claim → retry*) lives in
  [`architecture-api.md`](../architecture-api.md), not here.
