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
- **E2E coverage (Playwright, 2 contexts).** Real cross-client assertions for
  the list/detail state merges and redirect-on-delete were deferred to E2E per
  the spec (out of unit scope). Tie into the live-stack E2E effort.
