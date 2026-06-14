# Deferred work

Collected during quick-dev. Not part of the current story's shippable scope.

> **2026-06-10 — items vivos migrados a GitHub issues.** El registro vivo ya no
> está en este fichero: cada obligación pendiente tiene su issue (lista abajo).
> Este fichero permanece como sink del workflow quick-dev — los nuevos diferidos
> se siguen apuntando aquí y se migran a issues periódicamente.

## Migrado a issues (2026-06-10)

| Issue                                                           | Item                                                                                                                                                                                            |
|-----------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| [#194](https://github.com/sergio-salcedo-dev/ERPify/issues/194) | Gatear el endpoint público de autorización Mercure cuando llegue la auth de backoffice (incluye el item "privacy-theater" del review de PR #87)                                                 |
| [#196](https://github.com/sergio-salcedo-dev/ERPify/issues/196) | Subida de source-maps a Sentry (`SENTRY_AUTH_TOKEN` + `withSentryConfig`)                                                                                                                       |
| [#197](https://github.com/sergio-salcedo-dev/ERPify/issues/197) | Rate-limiting del túnel público `/monitoring` (fusiona el item 2026-06-09 y la tunnel-abuse note 2026-06-08)                                                                                    |
| [#198](https://github.com/sergio-salcedo-dev/ERPify/issues/198) | Gate `tsc --noEmit` en `pwa.quality`/CI                                                                                                                                                         |
| [#199](https://github.com/sergio-salcedo-dev/ERPify/issues/199) | Hardening del contrato `filters[]`: guards de `FieldMapping` (datetime+eq/in, flags mutuamente excluyentes) + round-trip en `parseStrict`                                                       |
| [#200](https://github.com/sergio-salcedo-dev/ERPify/issues/200) | Cobertura e2e de cursor keyset + cambio de `sort` — reasignado de la extinta Story 2.2 a **PR3 del ciclo keyset** (ojo: bajo el contrato del ADR es 422 `invalid-cursor`, no fallback a offset) |
| [#202](https://github.com/sergio-salcedo-dev/ERPify/issues/202) | Guard de precedencia default-type para excepciones dual-marker `InvalidInput` + `InvalidSearchCriteria`                                                                                         |
| [#203](https://github.com/sergio-salcedo-dev/ERPify/issues/203) | Asserts e2e de shortName normalizan con `.toLocaleUpperCase()` en vez de la regla del API                                                                                                       |
| [#204](https://github.com/sergio-salcedo-dev/ERPify/issues/204) | API Sentry: headers de auth custom en `RedactionDenylist` + scrub de breadcrumbs                                                                                                                |
| [#205](https://github.com/sergio-salcedo-dev/ERPify/issues/205) | PWA Sentry: denylist amplio, PII no-secreta, presupuesto de nodos en `scrubDeep`/`serializeCause`                                                                                               |
| [#206](https://github.com/sergio-salcedo-dev/ERPify/issues/206) | Guard de paridad del stub `sentryNextjs.ts` (fusiona los dos items duplicados 2026-06-08/09)                                                                                                    |

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

## Migrado a issues (2026-06-12)

Triaje del backlog sin-issue de los reviews de keyset (auditado contra `main`): 8 items ya
estaban resueltos en `main` por PR1–PR4 (codec arity guard, dedup `id` en `OrderByColumns`,
precisión `TIMESTAMP(0)`, `hasNext` de página `before` vacía, dirección de índice, `nullable`
sortable, frontera intra-empate del property test, y la migración inmutable de collation) y 2 se
arreglaron en PR #229 (`KeysetSqlSnapshotTest` cierra su conexión DBAL paralela; `SortFieldMapIndexContractTest`
deriva los campos del `SortFieldMap` de producción) — todos retirados. El resto se migró a issues:

| Issue | Item |
|-------|------|
| [#232](https://github.com/sergio-salcedo-dev/ERPify/issues/232) | keyset: `resolveLimit` no aplica `policy.defaultLimit` (default del wire inerte) |
| [#233](https://github.com/sergio-salcedo-dev/ERPify/issues/233) | keyset: `RowUniquenessGuard` falla-abierto fuera del caso addSelect (cartesiano / to-many no seleccionado) |
| [#234](https://github.com/sergio-salcedo-dev/ERPify/issues/234) | keyset: `qualify()` reescribe el DQL del predicado por regex (acoplado a `id` bare) |
| [#235](https://github.com/sergio-salcedo-dev/ERPify/issues/235) | keyset: `entityName()` colapsa al nombre corto → colisión de fingerprint multi-contexto |

## Deferred from: code review of iam-user-management-frontend plan — group 1 cores (2026-06-14)

- Empty-page recovery in `useResourceList` (useResourceList.ts:178-183) can issue redundant `follow()` calls on a tail-emptied page. Bounded (terminates at offset 0; mock-only deterministic data). Harden with a visited-link/attempt guard and clamp `searchAt` offset to the result length.
- `useQueryState.reset()` (createQueryState.ts:32-35) does not reset `pageSize` although its doc describes a "single reset" over filter/sort/pageSize. Either reset page size or adjust the doc — low priority, page size is arguably a viewing preference.

## Deferred from: code review of iam-user-management-frontend plan — group 2 user module (2026-06-14)

- `UserEditSchema` (pwa/src/context/backoffice/user/application/schemas/UserEditSchema.ts) is unused — referenced only in a `UserFormSchema` comment; the form validates with `UserFormSchema`. It documents the intended API edit contract but is dead per "minimum code, nothing speculative." Decide later: wire edit-mode validation to it, or remove it.

## Deferred from: code review of iam-user-management-frontend plan — group 3 users UI (2026-06-14)

- Stale `focusedRow` after an optimistic delete in `UsersStackedList`/`BanksStackedList` (roving tabindex can land past the shrunk array, losing keyboard focus). Pre-existing pattern shared with the Bank reference — clamp `focusedRow` on `users.length` change in both as a cross-cutting a11y fix.
- `query.pageSize as UsersPageSize` cast in the users list page launders the type; safe today (page size only set from the constrained dropdown). Replace with a `USERS_PAGE_SIZE_OPTIONS` membership guard if/when page size becomes URL/storage-hydrated.
