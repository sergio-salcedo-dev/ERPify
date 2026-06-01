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
- **EventSource observability / reconnect-on-cookie-expiry.** The browser
  `EventSource` auto-reconnects and the subscriber cookie is a session cookie,
  so the happy path is covered. If a TTL is later added to the cookie, add an
  `onerror` re-authorize hook in `BrowserMercureSubscriber` / `useBankRealtime`.
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
