# Deferred work

Collected during quick-dev. Not part of the current story's shippable scope.

> **2026-06-10 — items vivos migrados a GitHub issues.** El registro vivo ya no
> está en este fichero: cada obligación pendiente tiene su issue (lista abajo).
> Este fichero permanece como sink del workflow quick-dev — los nuevos diferidos
> se siguen apuntando aquí y se migran a issues periódicamente.

## Migrado a issues (2026-06-10)

| Issue | Item |
|---|---|
| [#194](https://github.com/sergio-salcedo-dev/ERPify/issues/194) | Gatear el endpoint público de autorización Mercure cuando llegue la auth de backoffice (incluye el item "privacy-theater" del review de PR #87) |
| [#195](https://github.com/sergio-salcedo-dev/ERPify/issues/195) | Sink de Datadog como segunda entrada de `CompositeTelemetry` (absorbe las notas históricas de prep: CSP `connect-src`, client token + allowlist, severity map) |
| [#196](https://github.com/sergio-salcedo-dev/ERPify/issues/196) | Subida de source-maps a Sentry (`SENTRY_AUTH_TOKEN` + `withSentryConfig`) |
| [#197](https://github.com/sergio-salcedo-dev/ERPify/issues/197) | Rate-limiting del túnel público `/monitoring` (fusiona el item 2026-06-09 y la tunnel-abuse note 2026-06-08) |
| [#198](https://github.com/sergio-salcedo-dev/ERPify/issues/198) | Gate `tsc --noEmit` en `pwa.quality`/CI |
| [#199](https://github.com/sergio-salcedo-dev/ERPify/issues/199) | Hardening del contrato `filters[]`: guards de `FieldMapping` (datetime+eq/in, flags mutuamente excluyentes) + round-trip en `parseStrict` |
| [#200](https://github.com/sergio-salcedo-dev/ERPify/issues/200) | Cobertura e2e de cursor keyset + cambio de `sort` — reasignado de la extinta Story 2.2 a **PR3 del ciclo keyset** (ojo: bajo el contrato del ADR es 422 `invalid-cursor`, no fallback a offset) |
| [#201](https://github.com/sergio-salcedo-dev/ERPify/issues/201) | Evicción acotada en el mapa interno de `ThrottledTelemetry` (review PR #120) |
| [#202](https://github.com/sergio-salcedo-dev/ERPify/issues/202) | Guard de precedencia default-type para excepciones dual-marker `InvalidInput` + `InvalidSearchCriteria` |
| [#203](https://github.com/sergio-salcedo-dev/ERPify/issues/203) | Asserts e2e de shortName normalizan con `.toLocaleUpperCase()` en vez de la regla del API |
| [#204](https://github.com/sergio-salcedo-dev/ERPify/issues/204) | API Sentry: headers de auth custom en `RedactionDenylist` + scrub de breadcrumbs |
| [#205](https://github.com/sergio-salcedo-dev/ERPify/issues/205) | PWA Sentry: denylist amplio, PII no-secreta, presupuesto de nodos en `scrubDeep`/`serializeCause` |
| [#206](https://github.com/sergio-salcedo-dev/ERPify/issues/206) | Guard de paridad del stub `sentryNextjs.ts` (fusiona los dos items duplicados 2026-06-08/09) |
| [#207](https://github.com/sergio-salcedo-dev/ERPify/issues/207) | `DROP INDEX IF EXISTS` en `Version20260608165844::down()` |

## Resueltos antes de la migración (histórico)

- **Coste de query sin límite más allá de los caps planos del contrato `filters[]`** —
  resuelto 2026-06-07 (story 1.5): gate NFR4 ejecutado, `EXPLAIN ANALYZE` sobre las 4 formas:
  `in`/`eq` sobre `name_normalized` → Index Scan (índice UNIQUE), `id` → Index Scan (PK);
  **`contains` → Seq Scan asumido conscientemente** (LIKE con comodín inicial no indexable por
  btree; los caps de mapping acotan el peor caso) — postura de perf vigente, no mera historia.
  Plan idéntico al legacy retirado → p95 sin regresión.
- **`InvalidSearchValue` no señala el índice posicional del valor ofensor** — resuelto
  2026-06-07 (story 1.5): `notAUuid(string $field, int $position)` — el context lleva campo +
  posición 0-based, **nunca el valor**. Los params legacy `names[]`/`ids[]` se retiraron del
  wire en la propia 1.5 (decisión de usuario 2026-06-07 — el código no estaba desplegado en
  producción; fase *contract* adelantada).
- Los dos items `patch` del review post-merge de PR #87 (`5753a4c`) quedaron registrados en la
  sección **Review Findings** de `spec-banks-mercure-realtime`.
- **Sentry sink PWA** — shipped 2026-06-08 (spec-pwa-sentry): `serializeCause()` + scrub
  recursivo, `createTelemetry()`, túnel same-origin `/monitoring`, severity map.

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
