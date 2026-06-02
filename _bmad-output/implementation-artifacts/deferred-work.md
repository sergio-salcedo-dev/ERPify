# Deferred work

Collected during quick-dev. Not part of the current story's shippable scope.

## From: spec-banks-mercure-realtime (2026-06-01, step-04 review)

- **Authorize endpoint AuthN/AuthZ.** `BankRealtimeAuthorizeController`
  (`GET /api/v1/backoffice/banks/realtime/authorize`) is a conscious **public**
  route: it mints a Mercure subscriber cookie for the bank topics with no
  `#[IsGranted]`/voter, consistent with the entire bank API being public today
  and with `mercure_subscribe.yaml` granting `subscribe: '*'`. When auth lands
  for backoffice, gate this controller and narrow the granted topics per
  user/role. Must be called out as a public route in the PR description.
- **RESOLVED — reconnect-on-cookie-expiry.** The premise above was wrong: the
  subscriber cookie is emitted without `Max-Age` (a *session* cookie), but the
  Mercure authorization **JWT inside it carries a ~1h `exp`** (Symfony's
  `default_cookie_lifetime` → `framework.session.cookie_lifetime`). So a tab left
  open past that window silently stops receiving updates on the next `EventSource`
  reconnect (network blip, HTTP/2 recycle, sleep/wake). Surfaced during the prod
  (`make deploy.local`) verification of this feature. Fixed by adding the planned
  `onError` re-authorize hook: `MercureSubscriber.subscribe` now accepts an
  `onError` callback, `BrowserMercureSubscriber` invokes it (debounced 30s) from
  `EventSource.onerror`, and `useBankRealtime` passes `authorize` so the imminent
  reconnect carries a fresh cookie. Covered by
  `tests/context/shared/infrastructure/RealTime/BrowserMercureSubscriber.test.ts`.
- **E2E coverage (Playwright).** Added `pwa/tests/e2e/backoffice/banks-realtime.spec.ts`:
  the API context plays "the other client", the browser observes live updates. All 5 pass
  (list create/update/delete + detail update/delete) — the create-live test is now active
  (see resolved blocker below).
- **RESOLVED blocker for create-live → `fix/shared-aggregate-id-mismatch` (merged).**
  On CREATE the persisted entity id used to differ from the id in `BankCreatedDomainEvent`
  (Doctrine `CustomIdGenerator` → UUID v7 vs. app-pre-generated UUID v4 via
  `SymfonyUuidGenerator` in `Bank::create`), so the Mercure payload's id never matched the
  row keyed by the real id. The fix (`fix(app): make app-assigned uuid v7 authoritative
  end-to-end`, commit `166a8a9`) makes the app-assigned UUID v7 the single source of truth
  end-to-end — Doctrine no longer overwrites it at flush, so the create event's aggregate id
  equals the persisted PK (locked by `BankCreateEventIdMatchesPersistedPkTest`). This branch
  was rebased onto that fix and the `test.fixme()` re-enabled.
- **Infra fix included here:** `compose.yaml` `messenger_worker` was missing
  `MERCURE_URL`/`MERCURE_JWT_SECRET`, so async Mercure publishes failed (`Failed to send an
  update`) in every environment. Added, mirroring the `php` service.

## Deferred from: code review of spec-banks-mercure-realtime (2026-06-01)

Post-merge adversarial review of PR #87 (`5753a4c`). Two `patch` items are tracked in the
spec's Review Findings section; the items below are the deferred (design-level / documented) ones.

- **Public `authorize` route + `private:true` = privacy-theater until auth lands.** No new
  exposure vs. the already-public bank REST API, but the in-code "no data leak" framing is
  optimistic. Gate the controller and narrow granted topics per user/role when backoffice auth
  arrives (already noted above).
- **RESOLVED (commit `6b2222e`) — EventSource `onerror` / re-authorize hook + subscriber JWT TTL.**
  The subscriber JWT carries a ~1h `exp`, so a long-lived tab stopped receiving updates on the next
  reconnect. Fixed upstream by the debounced (30s) `onError` → re-authorize hook in
  `BrowserMercureSubscriber` + `useBankRealtime`, covered by `BrowserMercureSubscriber.test.ts`.
  See the RESOLVED entry in the first section above.
- **RESOLVED — No event-id / `Last-Event-ID` replay.** Updates published during a reconnect gap were
  lost, so views could silently diverge from the DB. The realtime client now refetches on stream
  re-open to reconcile: `onReconnect` flows `MercureSubscribeOptions` → `BrowserMercureSubscriber`
  (skips the initial open) → `useMercureRealtime` → `useBankRealtime`, and the list/detail reconcile
  *silently* (no skeleton flash, current view preserved on a transient failure).
- **RESOLVED — Producer-soft-null + consumer-strict = silent drop.** Producer side: the exact realtime
  payload contract is locked by `BankRealtimePublisherHandlerTest` (PR #107), so a `toPrimitives()` drift
  fails CI instead of emitting a silent `null`. Consumer side: an unrecognised payload now routes through
  `telemetry.warn("unrecognized realtime payload")` in `useMercureRealtime` (and malformed JSON one layer
  down in `BrowserMercureSubscriber`) instead of being dropped without a trace.
- **Cross-event-type redelivery resurrection.** A late at-least-once redelivery of a `created` after
  a `delete` re-inserts the row on every list viewer (client merges are duplicate-safe per-event-type
  but not across types/ordering). Narrow window; would need sequence/versioning to fully close.
- **Cookie `SameSite=Strict` + cross-origin deployment.** The code supports a non-empty
  `NEXT_PUBLIC_SYMFONY_API_BASE_URL` (cross-origin), but a `SameSite=Strict` cookie is dropped on the
  cross-origin EventSource request, silently killing realtime. Untested. Default same-origin flow is fine.
- **RESOLVED — Handler test strength.** `BankRealtimePublisherHandlerTest` now decodes the payload and
  asserts its exact shape + idempotency (PR #107); the `BankDeleter` dispatch is covered end-to-end by the
  now-real Behat `delete.feature` (204 + 404, PR #108).
- **RESOLVED — `topicsKey` join("|") + unvalidated `id`.** The `"|"`-join was replaced by delimiter-safe
  JSON keying when the shared `useMercureRealtime` hook landed; the detail route `id` is now UUID-validated
  (`isUuid` from `@/lib/isUuid`) before it flows into the topic IRI (defense in depth).
- **RESOLVED (PR #110) — psalm baseline growth.** Both `$this->id` `PossiblyNullArgument` entries were
  baselined rather than fixed. Fixed at the source: `AggregateRoot::id()` is a non-null accessor guarding
  the "an identified aggregate always has its id" invariant; `Bank::rename()` / `delete()` use it, and the
  two psalm-baseline entries plus the Bank `argument.type` phpstan ignore were removed.

## Deferred from: code review of 2026-06-02-pwa-client-telemetry-seam-design (2026-06-02)

Adversarial review (Blind Hunter / Edge Case Hunter / Acceptance Auditor) of PR #113
(`feat/pwa-client-telemetry-seam`, HEAD `584c087`). The `decision-needed` and `patch`
items were handled live; the low-priority, follow-up-aligned items below are deferred.

- **Malformed-payload telemetry has no throttle/dedup.** `BrowserMercureSubscriber.onmessage`
  calls `telemetry.warn("malformed realtime payload", …)` once per bad event with no
  rate-limit. A misconfigured hub or buggy publisher emitting high-frequency malformed
  events floods the dev/staging console (prod stays silent). Add coalescing/rate-limiting
  when the real Sentry/Datadog sink adapter lands — the spec already defers the sink +
  PII scrubbing, and a console-only flood is low impact. Low. (source: edge)
- **`ConsoleTelemetry` does not serialize/scrub `cause`.** The `Telemetry` port doc states
  "Adapters serialize + scrub it; never assume PII-free", but `ConsoleTelemetry` forwards
  `cause` verbatim to `console.warn`/`console.error`. No live leak today (the three call
  sites pass a status-only `Error` or a JSON `SyntaxError`), and PII scrubbing is an
  explicit spec non-goal deferred to the network-sink adapter — but the seam already
  diverges from its own contract, so the future Sentry/Datadog adapter inherits a
  misleading precedent. Honor the scrub when the real adapter lands, or soften the port
  doc wording now. Low. (source: blind)
