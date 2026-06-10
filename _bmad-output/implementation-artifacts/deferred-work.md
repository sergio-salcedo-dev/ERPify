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

## Nuevos diferidos (post-migración)

- **2026-06-10 — Falta guard de longitud canónica para `shortName` (API).** Surgido en el
  review adversarial de `fix/pwa-shortname-normalize-asserts` (bmad-quick-dev, gh-203). Severidad:
  Low, pre-existente — NO causado por ese cambio (sólo toca tests e2e).
  `CreateBankCommand`/`UpdateBankCommand` validan `shortName` con `Length(max: 50)` sobre el input
  **crudo**, pero `Bank::create()`/`rename()` persisten `NormalizedText::toAsciiUpper($shortName)`
  en una columna `VARCHAR(50)`. La regla ICU `Any-Latin; Latin-ASCII; Upper()` puede **expandir**
  caracteres (`ß→SS`, `Æ→AE`, `Œ→OE`, `½→ 1/2`), así que un input de 50 chars con esos caracteres
  puede canonicalizar a >50 y reventar la columna con un 500 ("value too long") en vez de un 422
  limpio. El campo `name` ya tiene su guard equivalente (`validateNormalizedNameLength` en
  `Bank.php`); `shortName` no.
  - [ ] Añadir un guard de invariante sobre la longitud del `shortName` **canonicalizado** (callback
    de validación en `Bank` o assert en el VO), paralelo al de `name`, para que el caso de
    expansión devuelva 422 en vez de 500.
