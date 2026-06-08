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

> **Update (2026-06-08):** Sentry is now chosen (SaaS). The **API** half shipped on
> branch `feat/shared-sentry` (spec `spec-sentry-api-observability.md`): `sentry/sentry-symfony`,
> prod-only bundle, env-gated DSN, repo Sentry MCP in `.mcp.json`. The **PWA** half below is the
> next spec on the same branch — `@sentry/nextjs` framework integration (instrumentation, source
> maps, CSP/`tunnelRoute`) **plus** the `SentryTelemetry` port adapter + `createTelemetry()` factory
> described here. Use `tunnelRoute` to avoid widening the CSP `connect-src`.

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
