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

## Sentry/Datadog sink adapter — prep gaps (deferred until DSN/SDK chosen)

Tracked here so the eventual `SentryTelemetry` / `DatadogTelemetry` drop-in is friction-free.
Each is intentionally **not** built now (no DSN, no SDK, no second sink) — empty adapters or a
single-branch factory today would be speculative. The seam is already swap-ready: one wrapped
adapter in `pwa/src/context/shared/infrastructure/Observability/index.ts`, all call sites typed to
the `Telemetry` port. These land *with* the adapter:

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

## From: code review of fix/pwa-e2e-tooltip-and-test-types (2026-06-07)

- **E2E shortName assertions normalize with `.toLocaleUpperCase()`, not the API's rule.**
  `banks-real-api.spec.ts` and `banks-real-api-flows.spec.ts` pre-empt the API's canonicalization
  by upcasing the raw seeded input; the API's `NormalizedText::toAsciiUpper` also strips
  diacritics (`Any-Latin; Latin-ASCII; Upper()`). They only stay green because their seeded
  inputs are diacritic-free ASCII. Pre-existing; if a non-ASCII shortName is ever seeded, assert
  against the API-returned value instead (the pattern `banks-containment.spec.ts` now uses). Low.
  (source: adversarial review)
