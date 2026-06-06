# Deferred work

Collected during quick-dev. Not part of the current story's shippable scope.

## UX entity-list redesign — restos del contrato (2026-06-04)

Source contract: `_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-06-03/`
(`DESIGN.md` + `EXPERIENCE.md` — borrados del working tree el 2026-06-06, recuperables
del historial git). El plan completo se envió en PR #137 y follow-ups (#144, #150,
#151, #155, #157). Queda pendiente del contrato, conscientemente:

- **e2e tooltip de tarjeta (review de `fix/pwa-banks-cards-shortname-tooltip`,
  2026-06-04):** hover sobre el shortName de tarjeta abre su tooltip (el test jsdom
  solo fija las clases `relative z-10`, no el hit-testing real) y el trade-off
  aceptado de que el click sobre el shortName de tarjeta NO navega al detalle (el
  resto de la tarjeta sí) — ninguno de los dos es verificable en jsdom. Sigue sin
  cubrirse en `banks-containment.spec.ts`.
- **RecordSheet peek (`o`, v2 opcional):** el componente `RecordSheet.tsx` existe
  con unit test pero no está cableado en ninguna página.
- **Selección por rango Shift+↑/↓** `[ASSUMPTION]`.

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
- **Cross-event-type redelivery resurrection.** A late at-least-once redelivery of a `created` after
  a `delete` re-inserts the row on every list viewer (client merges are duplicate-safe per-event-type
  but not across types/ordering). Narrow window; would need sequence/versioning to fully close.
- **Cookie `SameSite=Strict` + cross-origin deployment.** The code supports a non-empty
  `NEXT_PUBLIC_SYMFONY_API_BASE_URL` (cross-origin), but a `SameSite=Strict` cookie is dropped on the
  cross-origin EventSource request, silently killing realtime. Untested. Default same-origin flow is fine.

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

## Deferred from: code review of spec-pwa-dark-mode-2 (2026-06-04, full-PR pass)

Second adversarial pass over the **complete** PR #136 diff (`main...fdc5b65`, v1+v2 together), with
live verification against the worktree stack on `:8443` (banks 200 same-origin + clean console,
landing/`/status` dark, toggle cycle + persistence).

- **SSR fallback `https://localhost` has no port — fails on worktree stacks without
  `SYMFONY_INTERNAL_URL` propagated to the pwa container.** `serverApiBase()` falls back to the
  literal when both `SYMFONY_INTERNAL_URL` and `NEXT_PUBLIC_API_BASE_URL` are unset, pointing SSR
  fetches at `:443` — the same failure mode the PR fixed browser-side, persisting server-side on
  incomplete env config. Pre-existing (the literal lived in `browserApiBase()` on `main`); in
  Docker/prod both vars are set. Guard: guarantee `SYMFONY_INTERNAL_URL` in every stack overlay, or
  derive host:port from the incoming request. Low. (source: edge,
  `HttpClient.ts:41`)

## Deferred from: spec-pwa-dark-mode-2 review (2026-06-04)

Adversarial review (Blind Hunter / Edge Case Hunter / Acceptance Auditor) of the dark-mode v2
iteration on PR #136. Patch findings were applied live; the items below are deferred.

- **Light-mode semantic tokens as visible text are sub-AA design-system-wide.** `text-success`
  (`#10b981`, ~2.5:1) and `text-warning` (`#d97706`, ~3.2:1) on light surfaces fail WCAG AA for
  text, and this is the *established convention* across `StatusBadge`, `SystemStatusBanner`,
  `ProblemDisplay` and now the `/status` page (pre-existing; the v2 migration matched it). The
  dark-mode side was fixed in-PR via `--erpify-danger-strong` (`text-danger-strong`); the proper
  light-mode fix needs AA-safe text variants (darkened `-strong` values or new `-text` tokens) in
  the **light** block — that trips the v2 spec's "tokens del modo claro" Ask-First gate, so it is
  an owner decision. Scope: pick AA-safe light text values for success/warning (danger already
  passes at `#dc2626`), sweep the call sites, and verify `StatusBadge` tinted-pill variants.
  Medium. (source: edge+auditor, spec-pwa-dark-mode-2)
- **`mercureUrl()` does not `.trim()` `NEXT_PUBLIC_API_BASE_URL` while `browserApiBase()` does.**
  A whitespace-padded value makes fetch and EventSource resolve different origins (fetch trimmed,
  EventSource not). Pre-existing divergence in `BrowserMercureSubscriber.ts:17` /
  `useMercureRealtime.ts:33`, untouched by the v2 same-origin fix. One-line alignment when those
  files are next edited. Low. (source: edge, spec-pwa-dark-mode-2)

## Deferred from: spec-api-domain-event-id-generation review (2026-06-04)

Adversarial review (Blind Hunter / Edge Case Hunter / Acceptance Auditor) of
`chore/api-domain-event-id-generation-vjpc`. Patch findings were applied live; the items below are deferred.

- **No `UNIQUE(event_id)` on `domain_event` — double-append writes duplicate audit rows.** If the same
  event object reaches `DoctrineDomainEventStore::append()` twice (sync persist + app-level bus retry),
  two rows share one `event_id`, eroding its dedupe value. Pre-existing (caller-minted ids had the same
  exposure); schema change was out of scope for the eventId refactor ("Never: no cambiar el esquema de
  `domain_event`"). Scope: migration adding a unique index + idempotent upsert/ignore in `append()`.
  Low-medium. (source: edge, spec-api-domain-event-id-generation)
- **Two UUID-generation entry points with overlapping responsibility.** `Shared/Domain/Uuid/Uuid::generate()`
  (domain events) and `Shared/Infrastructure/Uuid/SymfonyUuidGenerator::generate()` (entity PKs via
  `BankCreator`, `DoctrineDomainEventStore` row ids, `MediaRegistrar`) now coexist doing the same v7 mint.
  Entity-PK generation was explicitly out of scope. Scope: route PK minting through the domain `Uuid`
  (or grow it into the planned UUID value-object base) and retire `SymfonyUuidGenerator` + its static
  `UuidGenerator` port. Low. (source: blind, spec-api-domain-event-id-generation)

## Deferred from: spec-pwa-banks-bulk-restore-stale-snapshot review (2026-06-06)

Adversarial review (Blind Hunter / Edge Case Hunter / Acceptance Auditor) of the re-probe-validated
bulk-delete restore. The patch finding (call-count assertions) was applied live; the item below is deferred.

- **Ventana residual (escala microtarea) en la restauración validada del bulk delete.**
  (`pwa/src/app/backoffice/banks/page.tsx`, `runBulkDelete`): el re-probe cierra la ventana original
  (round-trips completos de probe/delete), pero un `onDeleted` de Mercure procesado entre la
  resolución del re-probe y el `setBanks` síncrono aún puede resucitar la fila — y re-añadir su
  selección — hasta el siguiente reconcile. Cerrarla del todo exigiría trackear los ids borrados por
  Mercure durante la ventana del bulk, descartado conscientemente en el spec (Never: refs acoplados
  al handler realtime). Magnitud: microtarea vs. los round-trips de antes; autocurable en el
  siguiente evento/reconnect. Low. (source: blind+edge, spec-pwa-banks-bulk-restore-stale-snapshot)

## Deferred from: spec-pr-158-sonar-dup-density review (2026-06-06)

Dedup CPD del PR #158: solo se refactorizaron los dos specs implicados en los bloques que Sonar
marcó como código nuevo. Queda pendiente, conscientemente fuera del alcance del PR:

- **Barrido suite-wide de fixtures/factorías en los specs de banks.** Ocho specs más bajo
  `pwa/tests/app/backoffice/banks/` (p. ej. `bankDetailDelete.test.tsx`, `bankEditStaleBank.test.tsx`,
  `banksListRetry.test.tsx`, `deleteBankButtonSpinner.test.tsx`) siguen re-declarando las fixtures
  ACME/BETA ahora canónicas en `_fixtures.ts`, y `bankListDelete.test.tsx` conserva sus factorías
  `vi.mock` artesanales en lugar de las de `_mocks.ts` (`routerMock`/`containerMock`/
  `toastNotifierMock`). Código viejo — Sonar no lo cuenta en el PR; deduplicarlo es higiene, no gate.
  Low. (source: adversarial review, spec-pr-158-sonar-dup-density)
