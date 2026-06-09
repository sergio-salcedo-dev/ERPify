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

## Deferred from: code review of spec-banks-mercure-realtime (2026-06-01)

Post-merge adversarial review of PR #87 (`5753a4c`). Two `patch` items are tracked in the
spec's Review Findings section; the items below are the deferred (design-level / documented) ones.

- **Public `authorize` route + `private:true` = privacy-theater until auth lands.** No new
  exposure vs. the already-public bank REST API, but the in-code "no data leak" framing is
  optimistic. Gate the controller and narrow granted topics per user/role when backoffice auth
  arrives (already noted above).

## Sentry/Datadog sink adapter — prep gaps

**UPDATE (2026-06-08, spec-pwa-sentry):** the **Sentry** sink shipped. `serializeCause()` +
recursive scrub (`domain/Observability/{serializeCause,redaction}.ts`), the `createTelemetry()`
factory (DSN-gated `SentryTelemetry` fanned out via `CompositeTelemetry`, preserving the
console's call-time env gate), and the `warn`/`error` → Sentry severity map are all DONE. The
SDK uses the same-origin `/monitoring` **tunnel**, so the **CSP `connect-src` widening below is
NO LONGER required for Sentry** — it only resurfaces if Datadog uses direct ingest. What remains
deferred:

- **Datadog sink (next task).** Add `DatadogTelemetry` as a second `CompositeTelemetry` entry +
  its `NEXT_PUBLIC_DATADOG_CLIENT_TOKEN` (allowlist + `.env.example` + `pwa/CLAUDE.md` table) and
  `DATADOG_API_KEY` (server-only secret). Reuse `serializeCause` / `redaction` as-is.
- **Sentry source-map upload.** `SENTRY_AUTH_TOKEN` (server-only build/CI secret) + flip
  `next.config.ts` `withSentryConfig` `sourcemaps.disable` off + `org`/`project` slugs (differ per
  env: `erpify-pwa-dev` / `erpify-pwa-prod`) + a `PRODUCTION_SECURITY_CHECKLIST.md` entry. Until
  then prod stack traces are minified.

Historical prep notes (most now satisfied by the Sentry work; kept for the Datadog drop-in):

- **`serializeCause()` / scrub helper.** A `domain/Observability` utility that normalizes an
  unknown `cause` (name → message → stack → nested `cause` chain, size-bounded) and scrubs
  PII/secrets before any external transmission. The console adapter keeps forwarding the raw
  object for local debugging; the network adapter consumes this helper (the `Telemetry.ts`
  port contract already assigns the scrub duty to external/network adapters).
- **Adapter-selection factory.** Replace the hardcoded `new ThrottledTelemetry(new
  ConsoleTelemetry())` with a `createTelemetry()` keyed on `NEXT_PUBLIC_APP_ENV` + DSN presence
  (console in dev/staging; Sentry/Datadog — or a `CompositeTelemetry` fan-out — in prod). Caveat:
  `ConsoleTelemetry` gates env at *call* time today (deliberately test-friendly); a
  construction-time selection must preserve that seam or the per-call `vi.stubEnv` tests break.
- **CSP `connect-src` widening.** `pwa/next.config.ts#headers()` must allow the ingest host
  (Sentry/Datadog DSN). Do **not** widen it before the host is known (security review item).
- **DSN / client-token secrets.** The future `NEXT_PUBLIC_SENTRY_DSN` / `NEXT_PUBLIC_DATADOG_CLIENT_TOKEN`
  names are documented here only — deliberately kept out of `pwa/.env.example`, whose raw text the
  `NEXT_PUBLIC_` allowlist guard (`tests/next-public-env-allowlist.test.ts`) scans and would fail the
  build on. They reach `.env.example` + `ALLOWED_PUBLIC_ENV_VARS` together with the adapter; wire real
  secret handling + a `PRODUCTION_SECURITY_CHECKLIST.md` entry then.
- **`warn` / `error` → vendor severity mapping.** Trivial level map (`warn`→warning,
  `error`→error); belongs with the adapter.

## Deferred from: code review of PR #120 (2026-06-03)

Adversarial review (Blind Hunter / Edge Case Hunter / Acceptance Auditor) of `feat/pwa-telemetry-throttle`.
The `decision-needed` and `patch` findings were applied live in the same PR; the one design-level item
below is deferred.

- **`ThrottledTelemetry` backing map is not actively evicted.** `record` keeps one `KeyState` per
  (level, scope, message) key for the life of the singleton. Safe today — all telemetry call sites use
  static-literal messages and a closed `TelemetrySurface` scope set, so cardinality is bounded — but the
  bound is a call-site convention, not enforced in `ThrottledTelemetry`. The moment a future call site
  interpolates a dynamic value into a `message`/`scope`, the map grows unbounded in long-lived tabs. Add
  TTL/size-bounded eviction (or assert key cardinality) if/when a dynamic-keyed call site lands. Low.
  (source: blind+edge)

## Project-wide `tsc --noEmit` gate (re-deferred 2026-06-07)

Carried over from spec-pwa-typesafe-enum-mappings (step-04 review). The TS2741 that surfaced it
(`bankTruncationTooltips.test.tsx` rendering `<BanksCards>` without `onBankDeleteFailed`) is fixed,
but the structural gap remains: `next build` typechecks the whole tree yet drops diagnostics from
files matching `*.test.*` / `*.spec.*` / `__tests__/` / `__mocks__/` (Next's `runTypeCheck`
filename filter — fixtures/helpers under `tests/` ARE still gated), and Vitest does not typecheck
at all, so type errors in spec/test-named files are invisible to CI. Decide whether to add an
`npm run typecheck` (`tsc --noEmit`) script + make target wired into `pwa.quality` / CI. Notes for
the implementer: `pwa/tsconfig.json` sets `incremental: true` and the dev container can leave a
root-owned `tsconfig.tsbuildinfo` on the host (the primary checkout has one today), so host runs
may hit EACCES — pass `--incremental false` (or run in the container). Tree verified clean at
`42d47b6` (`npx tsc --noEmit --incremental false` exits 0); re-verify at gate-add time.

## Deferred from: code review of spec-sentry-api-observability (2026-06-08, step-04)

Surfaced by the adversarial review of the API Sentry integration; both are real but lower-priority
and intentionally out of the shipped scope (the live PII vectors — `query_string`, nested bodies,
the boot crash — were patched in-loop).

- **Broaden `RedactionDenylist` for secret-bearing custom headers.** `send_default_pii: false` already
  filters the 5 SDK defaults (`Authorization`, `Cookie`, `Set-Cookie`, `X-Forwarded-For`, `X-Real-IP`),
  and the scrubber strips the 7 denylist keys. Custom auth headers outside both — `X-Api-Key`,
  `X-Auth-Token`, `Proxy-Authorization`, `Api-Key` — are not scrubbed. Low risk today (the API has no
  custom auth headers yet). When auth lands, add these to `RedactionDenylist::KEYS` **with their four
  casing test rows each** (the enum's own contract, enforced by `RedactionDenylistTest`).
- **Scrub Sentry breadcrumbs.** DBAL and HTTP-client tracing breadcrumbs can carry secrets (an outbound
  URL with a token in its query, a SQL string). The `before_send` scrubber only walks event `extra` +
  `request`, not `$event->getBreadcrumbs()`. Add a breadcrumb pass (walk each breadcrumb's metadata
  through the denylist) if/when breadcrumb content proves sensitive in practice.

## Deferred from: code review of spec-pwa-sentry.md (2026-06-09)

- **Denylist too narrow (Parity with API).** The `REDACTION_DENYLIST` uses exact, case-insensitive matches. Variations like `user_password` or `new_token` are not caught. Deferred to maintain parity with the API's current implementation.
- **Public `/monitoring` tunnel lacks rate limiting.** The Caddyfile unconditionally routes `/monitoring*` to Next.js. Potential DoS vector if not rate-limited at the infrastructure or application layer.
- **Non-secret PII not scrubbed (Parity with API).** Common PII like `email`, `phone_number`, and `address` are not in the current denylist. Sentry receives this data by default if present in request surfaces.
- **`sentryNextjs.ts` stub maintenance liability.** The manual unit-test stub for the Sentry SDK is a maintenance risk; no automated guard ensures it matches the actual SDK export surface.
## Deferred from: code review of spec-pwa-sentry (2026-06-08, step-04)

Adversarial review (Blind Hunter / Edge Case Hunter / Acceptance Auditor) of `feat/pwa-sentry-eocz`.
The load-bearing findings (whitespace-DSN gate, breadcrumbs/user/url-query + transaction-event scrub,
depth-cap secret passthrough, `{value:{value}}` double-wrap) were fixed live in the same change.
These low-severity items are deferred:

- **Sentry test-stub parity guard.** `pwa/tests/stubs/sentryNextjs.ts` (aliased in `vitest.config.ts`)
  can silently diverge from the real `@sentry/nextjs` export surface — a new named import used in `src/`
  that the stub lacks would be `undefined` at unit-test runtime while passing. Add a guard test asserting
  the stub's exported names ⊇ the SDK named imports referenced under `src/`. Low.
- **`scrubDeep` / `serializeCause` node-count budget.** Both are depth-bounded but have no node/key
  budget, so a very large *shallow* non-Error cause (e.g. 100k keys) is walked in full and attached to
  the event. Sentry bounds payload size itself, so this is bounded in practice; add an explicit node cap
  if a high-cardinality cause ever lands. Low.
- **Tunnel-abuse note.** `tunnelRoute: "/monitoring"` is a same-origin POST relay to Sentry ingest — an
  accepted Sentry tradeoff (anyone can POST events, bounded by Sentry-side rate limits). No app/route
  collision today (verified vs `proxy.ts` matcher + `app/`). Revisit (rate-limit the route) only if quota
  abuse is observed. Low.
