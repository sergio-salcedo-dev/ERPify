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

## Deferred from: code review of 1-2-errores-de-busqueda-invalida-en-el-pipeline-rfc-9457 (2026-06-07)

- **Precedencia de default-type sin guard para excepciones dual-marker `InvalidInput` + `InvalidSearchCriteria` (ambos → 400).** `firstMatchingMarker` resuelve por orden de implements-clause (documentado en `docs/api-error-contract.md` y pineado por `testMarkerOrderingFollowsImplementsClause`), así que el `type` por defecto de una excepción que implementara ambos markers con `type()` vacío dependería silenciosamente del orden de su cláusula `implements` — no del orden de declaración de los maps. Benigno hoy: el status (400) coincide en ambos y ninguna concreta de producción implementa los dos markers; las dos shipped llevan `TYPE` explícito. Revisitar si alguna vez aparece una excepción dual-marker (p. ej. añadir un guard/test que pinee el resultado). Low. (source: blind+edge)

## Deferred from: code review of 1-4-contrato-generico-filters-expuesto-en-el-endpoint-de-banks (2026-06-07)

- ~~**Coste de query sin límite más allá de los caps planos del contrato `filters[]`.**~~
  **Resuelto (2026-06-07, story 1.5):** gate NFR4 ejecutado — `EXPLAIN ANALYZE` sobre las 4 formas
  del camino único contra el dataset de fixtures: `in`/`eq` sobre `name_normalized` → Index Scan
  (índice UNIQUE), `id` → Index Scan (PK); `contains` → Seq Scan asumido conscientemente (LIKE con
  comodín inicial no indexable por btree; los caps de mapping acotan el peor caso). El plan del
  camino único es idéntico al del legacy retirado (mismo shape `IN` bindeado) → p95 sin regresión.
- ~~**`InvalidSearchValue` no señala el índice posicional del valor ofensor.**~~
  **Resuelto (2026-06-07, story 1.5):** `notAUuid(string $field, int $position)` — el context lleva
  campo + posición 0-based, nunca el valor; expuesto en el body Problem Details y asseverado en
  `FilterApplierTest` + Behat. La decisión de equivalencia de types legacy↔genérico quedó moot:
  los params legacy `names[]`/`ids[]` se retiraron del wire en la propia 1.5 (decisión de usuario
  2026-06-07 — el código no está desplegado en producción; fase *contract* adelantada).

## From: code review of fix/pwa-e2e-tooltip-and-test-types (2026-06-07)

- **E2E shortName assertions normalize with `.toLocaleUpperCase()`, not the API's rule.**
  `banks-real-api.spec.ts` and `banks-real-api-flows.spec.ts` pre-empt the API's canonicalization
  by upcasing the raw seeded input; the API's `NormalizedText::toAsciiUpper` also strips
  diacritics (`Any-Latin; Latin-ASCII; Upper()`). They only stay green because their seeded
  inputs are diacritic-free ASCII. Pre-existing; if a non-ASCII shortName is ever seeded, assert
  against the API-returned value instead (the pattern `banks-containment.spec.ts` now uses). Low.
  (source: adversarial review)

## Deferred from: code review of epics.md story 1.7 (2026-06-08)

Adversarial review (Blind Hunter / Edge Case Hunter / Acceptance Auditor) of the temporal-range +
`shortName` implementation. The `decision-needed` and `patch` findings (incl. a critical null-byte
→ 500 and the missing `shortName` half of AC3) were applied live; the two defense-in-depth items
below are deferred — programmer-error guards in `FieldMapping`, not reachable from the wire on `banks`.

- **A `requiresDateTimeValues` field that also allowed `eq`/`in` would bind an untyped string → 500.**
  `FilterApplier::eqCondition`/`inCondition` pass a `null` Doctrine type; only `rangeCondition`
  binds a typed `datetime_immutable`. The `FieldMapping` constructor forbids `contains` on a
  datetime field but not `eq`/`in`, so a future map declaring
  `new FieldMapping('b.createdAt', operators: [Eq, In], requiresDateTimeValues: true)` would send a
  raw string against a `timestamp` column → Postgres 22007 → 500. Not reachable today: `banks` wires
  only the four range operators onto its datetime fields. Harden by typing the eq/in binding for
  datetime fields, or rejecting that combination at construction. Low. (source: edge)

- **`requiresUuidValues` + `requiresDateTimeValues` both `true` is unguarded.** The constructor
  validates each flag against `contains` independently but never forbids the contradictory
  both-true combination (a value cannot be both a UUID and a datetime); such a field would run UUID
  pre-validation then datetime parsing and reject every value. Programmer error only, not reachable
  from the wire. Add a mutual-exclusion guard in the `FieldMapping` constructor. Low. (source: edge)

## Deferred from: code review of 1-8-orden-server-side-en-el-contrato-de-busqueda (2026-06-08)

Adversarial review (Blind Hunter / Edge Case Hunter / Acceptance Auditor) of server-side `sort`/`direction`
on `e0d8794..5d36330`. The `decision-needed` and `patch` findings are tracked in the story's Review Findings
section; the one item below is deferred.

- **Cursor keyset + `sort` change has no e2e coverage.** When a client changes `sort` while carrying a
  keyset cursor, the cursor (which encodes the previous order columns) no longer matches and the paginator
  degrades to offset. The degradation mechanism is verified to exist (`Paginator::buildCursorWhere` returns
  `null` when an order column is absent from the cursor → offset fallback), but no Behat scenario exercises
  the cursor→offset jump on a `sort` switch. The consumer-side contract (the PWA must discard the cursor on
  sort change — the learned debounce+pagination race rule) lands in Story 2.2; pin the server-side jump there
  alongside it. Low. (source: blind)

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

## Deferred from: code review of story-1.7 (2026-06-09, step-04)

Surfaced by the adversarial review of the temporal range operators. Both are Low; the live correctness
and security invariants (typed binding, strict parse, 400-not-500, asymmetric offset bound) were patched
in-loop. The third 1.7 deferral (the unguarded `FieldMapping` flag combinations) is already recorded
above under the original implementation note.

- **Harden `FilterApplier::parseStrict` with a reparse round-trip.** Validity currently rests on
  `DateTimeImmutable::getLastErrors()` warning/error counts after `createFromFormat`, plus the adjacency
  of the `createFromFormat`/`getLastErrors` calls (a process-global). Verified functionally correct on
  PHP 8.5.7 against every malformed/relative/null-byte/leap-second/out-of-range-offset case. Optional
  robustness: add `$dt->format($format) === $value` so correctness no longer depends on warning emission
  nor on no intervening datetime call mutating the global error state. Low.
- **`DROP INDEX IF EXISTS` in the migration `down()`.** `Version20260608165844::down()` drops
  `idx_bank_created_at` / `idx_bank_updated_at` without `IF EXISTS`, so a half-applied `up()` would abort
  the rollback on the missing one. The indexes are perf-only (NFR4) and have no behavior-test coverage —
  a forgotten migration fails no test. Add `IF EXISTS` (and consider a `doctrine:schema:validate` gate)
  if migration reversibility under partial application becomes a concern. Low.

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

## Datadog API — Goals B & C (split off from spec-datadog-api-foundation-apm, 2026-06-10)

Goal A (the `ddtrace` extension — **APM tracer only, profiler deferred** — in the FrankenPHP image, a
profile-gated `datadog-agent` sidecar, and the `DD_*` env scaffolding, all OFF by default) shipped on
`feat/api-datadog-apm`. The foundation makes the two surfaces below drop-in. Both are **billed** on Datadog (no real free tier), so
each stays opt-in behind the same `DD_TRACE_ENABLED`-style env gating + the `datadog` compose profile.

- **Goal B — Logs → Datadog (Monolog).** Ship the API's JSON logs to Datadog. The prep is in place:
  `api/config/packages/monolog.yaml` already has a commented service-handler block (the Sentry pattern)
  and prod already logs JSON to `php://stderr`. Two viable paths: (1) **agent log collection** — flip
  `DD_LOGS_ENABLED=true` on the `datadog-agent` + add the container-label/autodiscovery config so the
  agent tails stdout (no app change, no new dependency — preferred); or (2) a Monolog handler posting to
  the Datadog logs intake API (needs `DD_API_KEY` reachable by the app + CSP/egress review). Add a
  `DD_LOGS_ENABLED` toggle, document the cost, and wire trace-log correlation (`dd.trace_id`) once APM is on.
- **Goal C — Custom metrics (DogStatsD).** A `Metrics` port in `api/src/Shared/` (domain interface) with
  a DogStatsD adapter in `Shared/.../Infrastructure` sending UDP to `DD_DOGSTATSD_URL=udp://datadog-agent:8125`
  (the agent already has `DD_DOGSTATSD_NON_LOCAL_TRAFFIC=true`). Keep the domain pure (port only); the
  adapter is the sole Datadog-aware piece. Gate emission behind a `DD_METRICS_ENABLED` toggle; document
  the per-custom-metric billing. No business call sites in this goal beyond a smoke counter.
- **Continuous profiler — packaging gap found during Goal A.** `install-php-extensions ddtrace` (the
  authorized install route) installs the APM tracer only; it does **not** bundle the `datadog-profiling`
  extension on this FrankenPHP/ZTS build (verified: `php --ri datadog-profiling` → "Extension not
  present"). The profiler IS supported on ZTS + FrankenPHP worker mode since dd-trace-php 0.99.0, so this
  is a packaging gap, not a capability gap. To add it later (Ask First — it changes the image's install
  method to an external bootstrap, which the "no external code in build" constraint deliberately avoids):
  add a Dockerfile step `RUN curl -LO https://github.com/DataDog/dd-trace-php/releases/latest/download/datadog-setup.php && php datadog-setup.php --php-bin=all --enable-profiling`,
  then enable at runtime with `DD_PROFILING_ENABLED=true` and verify via `php --ri datadog-profiling`.
  There is no `datadog.profiling.enabled` ini key — the env var is the only toggle. The
  `DD_PROFILING_ENABLED` env is already wired (default false) on `php`/`messenger_worker`, so only the
  install step is missing. Profiler is billed; keep it off unless explicitly wanted.
- **Digest-pin the `datadog-agent` image (review follow-up, user-accepted).** Every other base image
  is sha256-digest-pinned (repo policy); the agent ships as the floating major tag
  `gcr.io/datadoghq/agent:7` (a deliberate, user-approved choice at planning time). Pin it by digest
  (`gcr.io/datadoghq/agent:7@sha256:…`) and let Dependabot track bumps, for reproducibility/supply-chain
  parity with frankenphp/postgres/node. Low priority — the agent is opt-in and off by default.
