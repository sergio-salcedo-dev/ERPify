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
- **No event-id / `Last-Event-ID` replay.** Updates published during a reconnect gap are lost; the
  view can silently diverge from the DB with no resync. Either set stable `Update` ids + a Mercure
  history store, or refetch the list/detail once on (re)connect to reconcile.
- **Producer-soft-null + consumer-strict = silent drop.** `bankPayload()` coerces missing fields to
  `null`; the PWA `isBankPrimitives` rejects any `null`, dropping the event with no log. If the event
  shape ever drifts, live updates vanish invisibly. Log on the producer or make the payload non-null.
- **Cross-event-type redelivery resurrection.** A late at-least-once redelivery of a `created` after
  a `delete` re-inserts the row on every list viewer (client merges are duplicate-safe per-event-type
  but not across types/ordering). Narrow window; would need sequence/versioning to fully close.
- **Cookie `SameSite=Strict` + cross-origin deployment.** The code supports a non-empty
  `NEXT_PUBLIC_SYMFONY_API_BASE_URL` (cross-origin), but a `SameSite=Strict` cookie is dropped on the
  cross-origin EventSource request, silently killing realtime. Untested. Default same-origin flow is fine.
- **Handler test strength.** `BankRealtimePublisherHandlerTest` asserts on JSON substrings (mirrors the
  existing demo-controller test), and idempotency / `?? null` paths and the `BankDeleter` dispatch are
  not unit-asserted (the latter deferred to an un-diffed Behat `delete.feature` — spot-check it exists).
- **`topicsKey` join("|") + unvalidated `id`.** The detail route `id` flows unvalidated into the topic
  IRI and the `"|"`-joined effect key. Validate `id` as a UUID before subscribing (defense in depth).
- **psalm baseline growth.** A 2nd `$this->id` `PossiblyNullArgument` entry was baselined rather than
  fixed. Consider a non-null id accessor on the aggregate (clears all create/rename/delete entries at
  once) to honor the no-paper-over rule — but it touches the pre-existing pattern, so out of PR #87 scope.
