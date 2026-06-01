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
  the API context plays "the other client", the browser observes live updates. 4/5 pass
  (list+detail update/delete); the create-live test is `test.fixme()` — see next item.
- **BLOCKER for create-live → `fix/shared-aggregate-id-mismatch` (must merge first).**
  On CREATE the persisted entity id (Doctrine `CustomIdGenerator` → UUID v7) differs from
  the id in `BankCreatedDomainEvent` (app-pre-generated UUID v4 via `SymfonyUuidGenerator`
  in `Bank::create`), so the Mercure payload's id never matches the row keyed by the real
  id. Pre-existing bug in the shared `Identifiable` trait; this feature is the first
  id-matching consumer. Update/delete unaffected. Full analysis + chosen fix in that
  worktree's `HANDOFF-aggregate-id-mismatch.md`. Re-enable the fixme test after it lands.
- **Infra fix included here:** `compose.yaml` `messenger_worker` was missing
  `MERCURE_URL`/`MERCURE_JWT_SECRET`, so async Mercure publishes failed (`Failed to send an
  update`) in every environment. Added, mirroring the `php` service.
