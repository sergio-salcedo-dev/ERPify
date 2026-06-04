# Deferred work

Collected during quick-dev. Not part of the current story's shippable scope.

## Deferred from: spec errores de mutación persistentes — borrado (2026-06-04, segunda sesión UX)

Split por presupuesto de spec (elección de Sergio: A+B juntos, C diferido). El spec en curso
cubre el componente de error persistente + flujos de borrado single y masivo. Queda diferido:

- **C) Formularios crear/editar (`BankForm`)**: los errores de guardado adoptan la superficie
  persistente transversal (sobre el formulario) — dismiss ×, sustitución por reintento,
  limpieza en éxito, copia (mensaje · type+status · correlation id · JSON íntegro), campos
  según wire (`debug` env-aware). Hoy `BankForm` ya renderiza `ProblemDisplay` inline; el
  delta es adoptar el componente/semántica persistente que A define (reutilizar su API, no
  duplicar). Contrato: sección «Errores de mutación — superficie persistente» de
  `_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-06-03/EXPERIENCE.md`.

## Deferred from: spec split de precondiciones de borrado (2026-06-04)

El trabajo "confirm destructivo con precondición fallida" se partió por presupuesto de
spec: **Spec A (API, `spec-api-bank-in-use-409.md`) va primero**; el objetivo PWA queda
aquí para derivar su spec inmediatamente después. Decisiones ya clarificadas con Sergio
(NO re-preguntar):

> **⚠️ SUPERSEDIDO PARCIALMENTE (2026-06-04, sesión UX «errores de mutación persistentes», post-merge de PR #144).**
> El contrato cambió: el patrón confirm-con-error-embebido queda REVOCADO. Antes de derivar
> el spec PWA, leer la sección «Errores de mutación — superficie persistente» de
> `_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-06-03/EXPERIENCE.md` — la spine gana.
> La frase de la PR #144 («Spec B se deriva de deferred-work.md … sin re-preguntas») es registro
> histórico: ya no es cierta tal cual. Triage de las decisiones de abajo:
>
> - **SOBREVIVE:** recuperación tipada por `problem.type`; prohibido sintetizar problems
>   client-side; type `bank-in-use` como contrato; pre-check masivo de existencia (fail-open
>   si la sonda falla ≠ 404); rollback parcial que restaura fila **y selección**; detalle tras
>   Refresh → `ViewStatus.NOT_FOUND` → EmptyState not-found; «deshabilitar/desmontar nunca
>   deja el foco en `<body>`».
> - **REVOCADO:** `ProblemDisplay`/problem verbatim **dentro del dialog**; «Delete deshabilitado
>   + Refresh list» en el dialog; «el dialog permanece abierto» en masivo; foco al Delete
>   rehabilitado del dialog; toast como única superficie del error en lista/tarjetas.
> - **REUBICADO:** el error y la acción de recuperación viven en la superficie persistente
>   contextual al origen (sobre tabla/grid; bajo H1 en detalle; sobre el form); el confirm se
>   cierra solo al fallar y el foco va al error; el toast queda como señal transitoria
>   complementaria. Ciclo de vida: dismiss explícito o sustitución por reintento; éxito limpia.
>   Copia: mensaje, type+status, correlation id, JSON íntegro (campos según wire — `debug`
>   env-aware).
> - **Los tests/e2e descritos abajo deben re-derivarse** contra el contrato nuevo (p. ej.
>   «single 404→Refresh→foco vecina» pasa a «404→error persistente→Refresh→foco según spine»).

- **Recuperación tipada por `problem.type`** en el dialog de borrado (`DeleteBankButton`,
  `BanksBulkBar`): `bank-not-found` → Delete deshabilitado + acción "Refresh list" (en
  detalle: "Refresh"); `bank-in-use` (el type lo fija Spec A — contrato) → deshabilitado +
  problem verbatim SIN Refresh (la recuperación vive fuera). Prohibido sintetizar problems
  client-side.
- **Single desde lista tras Refresh:** fila y dialog se desmontan juntos; foco a la fila
  vecina (seam `pendingFocusIdRef` de page.tsx:185-217) + anuncio en live region. El
  "dialog permanece abierto" del contrato lo realiza el caso masivo.
- **Detalle tras Refresh:** `loadBank()` → `ViewStatus.NOT_FOUND` → EmptyState not-found
  con "Back to banks" (ya existente, [id]/page.tsx:75-94).
- **Masivo:** confirm pesimista con **pre-check de existencia** (sondas `FindBank`
  allSettled por id seleccionado; error de sonda ≠ 404 → fail-open al intento); algún 404
  → nada se borra, problem de la sonda embebido, disable + Refresh list; tras refresh:
  selección recalculada (23→22), frase re-derivada, Delete rehabilitado **con foco**;
  selección a 0 → bar+dialog desaparecen, foco al contenedor + anuncio.
- **Rollback parcial post-intento:** rechazos 404 NO resucitan la fila (ya no existe);
  el resto restaura fila **y selección** (flujo 2: "selección intacta para reintentar" —
  hoy page.tsx:279 la vacía y no la repone) + toast RFC 9457.
- **Focos:** deshabilitar nunca deja el foco en `<body>` — mover a la acción de
  recuperación o al `ProblemDisplay` (`tabIndex={-1}`); refresh-ok → Delete; fallo
  persiste → ProblemDisplay; cerrar → invocador (default Base UI).
- **Tests:** ampliar `bankListDelete` / `banksBulkActions` / `bankDetailDelete` +
  DeleteBankButton (mocks `_mocks.ts`, container con `FindBank`); e2e nuevo
  `banks-delete-preconditions.spec.ts` (API mockeada): single 404→Refresh→foco vecina;
  bulk 23→pre-check→Refresh→22→"22 banks deleted"; 409 sin Refresh.
- Fuera de alcance entonces y ahora: UI de cuentas asociadas, Shift-range, `/`,
  "List updated", peek.

## UX entity-list redesign — plan executed in a single PR (#137, 2026-06-04)

Source contract: `_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-06-03/`
(`DESIGN.md` + `EXPERIENCE.md`, updated 2026-06-04 — spines win on conflict). The
original split (PR 1 contención + deferred PRs 2–6) was collapsed into PR #137 by
direct instruction; everything below shipped there:

- **PR 2 — contraste:** StatusBadge dot-first (etiqueta neutra + dots
  `--erpify-status-dot-*` oscurecidos, C1/C2); tokens light + dark.
- **PR 3 — tooltip-si-truncado:** `components/ui/tooltip.tsx` (Base UI),
  `TooltipProvider delay=200` en el layout, `useIsTruncated` + `TruncatedText`
  (hover de celda + foco de FILA vía `focusScopeSelector`, sin tabIndex en spans;
  `openOnRowFocus` en una sola celda por fila; Esc con precedencia sobre
  limpiar-selección), `title=` retirado de celdas truncadas.
- **PR 4 — densidad + sticky:** `useStoredPreference` (hydration-safe, arregla
  también el mismatch del skeleton), `DensityToggle` + clave compartida
  `erpify.list.density`, sombra scroll-driven gated `prefers-reduced-motion`.
- **PR 5 — jerarquía + selección:** Code → Name → Status → Updated (lg+) →
  Created (xl+) → Actions; badge "New"/"Active" en columna/región Status (nunca
  inline con el nombre); ⋯ siempre visible + Copy/Edit reveal; live region
  siempre montada con anuncios coalescidos; tri-state `aria-checked="mixed"`
  page-scoped; foco a la fila vecina tras borrado optimista; Esc limpia
  selección sólo sin capa transitoria; confirm masivo lista 3 nombres + "+N".
- **PR 6 — e2e de regresión:** `banks-containment.spec.ts` (alturas constantes,
  tooltips por hover/foco, precedencia de Esc, mixed, H1 íntegro, contador
  n/255 + toast clamp, stacked móvil sin scroll horizontal). Añadir (review de
  `fix/pwa-banks-cards-shortname-tooltip`, 2026-06-04): hover sobre el
  shortName de tarjeta abre su tooltip (el test jsdom solo fija las clases
  `relative z-10`, no el hit-testing real) y el trade-off aceptado de que el
  click sobre el shortName de tarjeta NO navega al detalle (el resto de la
  tarjeta sí) — ninguno de los dos es verificable en jsdom.
- **Decisiones del update 2026-06-04 (revisión de Sergio sobre PR #137):**
  detalle H1 íntegro sin clamp; badge a la región de estado; tarjeta con
  regiones de controles/datos y checkbox SIEMPRE visible; Name del formulario
  como `SingleLineTextarea` auto-grow + contador n/255; vista apilada `< md`
  (`BanksStackedList`) con contrato de teclado de la tabla.
- **Follow-ups del gate cerrados:** contrato de teclado de la vista apilada
  (fijado en spine e implementado); reduced-motion de Sonner (el colapso global
  de `globals.css` ya anula sus animaciones — verificado).
- **Código-review PR1 cerrado aquí:** comentario lint-narration de
  `banks/page.tsx` reescrito a intención; hydration mismatch del
  skeleton/vista resuelto por `useStoredPreference`.

Still deferred from the UX contract (consciously): RecordSheet peek (`o`, v2
opcional), selección por rango Shift+↑/↓ `[ASSUMPTION]`, y la conducta del
confirm con precondición fallida (Delete deshabilitado + "Refresh list") que
pertenece al flujo de `fix/pwa-bank-delete-flash`.

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

- **RESOLVED — Malformed-payload telemetry now coalesced.** `telemetry.warn("malformed
  realtime payload", …)` (and every other call site) now routes through `ThrottledTelemetry`,
  the wrapped singleton in `infrastructure/Observability/index.ts`: identical
  (level, scope, message) diagnostics collapse to one emit per 10s window, and a
  `(+N suppressed)` tally rides the next emit so nothing is silently dropped. A misbehaving
  hub / buggy publisher can no longer flood the dev/staging console, and a metered
  Sentry/Datadog sink is protected the same way once it lands. Covered by
  `tests/context/shared/infrastructure/Observability/ThrottledTelemetry.test.ts`.
- **RESOLVED — `cause` scrub-contract divergence.** The port doc previously claimed
  "Adapters serialize + scrub it" while `ConsoleTelemetry` forwarded `cause` verbatim — a
  contract the console adapter never honored. Resolved by making the contract honest in
  `Telemetry.ts`: a *local* adapter (console) MAY forward `cause` as-is (the browser console
  is the developer's own machine, not a 3rd party), while any *external/network* adapter
  (Sentry/Datadog) MUST serialize + scrub before transmission — that scrub is owned by the
  network adapter when it lands (see the sink-adapter prep section below). The console adapter
  no longer diverges from its own contract.

## Sentry/Datadog sink adapter — prep gaps (deferred until DSN/SDK chosen)

Tracked here so the eventual `SentryTelemetry` / `DatadogTelemetry` drop-in is friction-free.
Each is intentionally **not** built now (no DSN, no SDK, no second sink) — empty adapters or a
single-branch factory today would be speculative. The seam is already swap-ready: one wrapped
adapter in `pwa/src/context/shared/infrastructure/Observability/index.ts`, all call sites typed to
the `Telemetry` port. These land *with* the adapter:

- **`serializeCause()` / scrub helper.** A `domain/Observability` utility that normalizes an
  unknown `cause` (name → message → stack → nested `cause` chain, size-bounded) and scrubs
  PII/secrets before any external transmission. The console adapter keeps forwarding the raw
  object for local debugging; the network adapter consumes this helper. (closes the scrub side
  of the RESOLVED `cause` entry above)
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

## Deferred from: spec-pwa-dark-mode review (2026-06-03)

Adversarial review (Blind Hunter / Edge Case Hunter / Acceptance Auditor) of `feat/pwa-dark-mode-vcmp`.
Patch findings were applied live in the same change; one design-level item deferred.

- **RESOLVED (spec-pwa-dark-mode-2, 2026-06-04) — `color-scheme: dark` leaks to the marketing/landing
  surface.** With dark chosen, `.dark` lands on `<html>` globally; the landing kept its raw light palette
  (out of scope by spec v1 "Never"), but native widgets/scrollbars followed `color-scheme: dark` there —
  visually inconsistent. Resolved by the owner renegotiating the v1 "Never": the landing, Navbar, Footer
  and `/status` surfaces were migrated to ERPify tokens (dark-aware by construction) and `ThemeToggle`
  mounted in the Navbar, so the marketing surface now follows the chosen theme instead of fighting it.
  (source: edge E9)

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
