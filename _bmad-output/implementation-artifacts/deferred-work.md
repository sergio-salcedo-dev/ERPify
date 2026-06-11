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
| [#201](https://github.com/sergio-salcedo-dev/ERPify/issues/201) | Evicción acotada en el mapa interno de `ThrottledTelemetry` (review PR #120)                                                                                                                    |
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

## Deferred from: code review of story-1.1 (2026-06-10)

- **Codec accepts `values` with wrong arity / missing ORDER BY column / non-scalar elements** → en el cableado de PR2, `KeysetPredicateBuilder` lanza `InvalidArgumentException` (500) en vez de `InvalidCursor` (422). El codec no puede validar aridad (no conoce las columnas — diferido al engine por AR16). PR2 debe garantizar aridad antes de invocar al builder, o mapear el `InvalidArgumentException` a `InvalidCursor` para preservar el contrato 422 `invalid-cursor`.
- **`OrderByColumns::fromSorts` sólo deduplica `id` cuando es la última clave** → un sort multi-clave con `id` en posición no-última re-añadiría la columna tie-break, produciendo `id` duplicado en ORDER BY/predicado. Sin caller en PR1 (`fromPrimarySort` pasa una sola clave); guardar antes de que PR2 cablee sorts multi-clave reales.
- **Floor de microsegundos vs redondeo de Postgres `TIMESTAMP(0)` + drift float en la frontera** → `CursorPositionExtractor` trunca (floor) a segundos; Postgres `TIMESTAMP(0)` redondea. Para filas ya persistidas la columna ya está a precisión de segundo, así que el riesgo es bajo, pero verificar con round-trip real contra Postgres (Behat) en PR2/PR3 que las filas frontera no se saltan/duplican en empates sub-segundo; idem precisión JSON de columnas float usadas como clave de orden.

## Deferred from: bounded-context isolation strategy (2026-06-11)

- **Static gate for bounded-context isolation (3 levels)** → make the isolation
  rules now documented in [`docs/rules/database.md`](../../docs/rules/database.md#bounded-context-data-isolation-modular-monolith)
  and [`docs/architecture-api.md`](../../docs/architecture-api.md) **machine-verified**.
  The gate must **enforce boundaries, not total isolation** — model the three
  levels, do NOT make it a dogmatic "zero coupling" check (that freezes dev,
  forces data duplication, fights the framework):
  - **🔴 Level 1 → ERROR (fail build).** (a) Cross-context **import**: a file
    under `src/<Top>/<ContextA>/` with `use Erpify\<Top>\<ContextB>\Domain\…`
    or `…\Application\…`. **Allowlist seams:** the other context's published
    Application service interface + its integration-event classes (define a
    marker interface / namespace convention to recognize them). `Shared/` is
    always importable. (b) Cross-context **repository query**: another context's
    `*Repository` injected/used outside its own context.
  - **🟡 Level 2 → WARNING (report, don't fail).** Cross-context **FK** between
    two business contexts — scan Doctrine `#[ORM\ManyToOne]`/`JoinColumn`
    targets and generated migration `FOREIGN KEY` DDL; warn when the target
    entity lives in a different top-level/business context so it gets justified
    in review. Do not block.
  - **🟢 Level 3 → ALLOWLISTED (no signal).** FK/refs toward shared kernel &
    identity (`User`, tenant/`company_id`, `Money`, `Uuid`), ID-only columns,
    event-based integration, read models.
  Wire as a `make php.lint.*` target next to `make php.lint.error-contract`; add
  to "Required checks" in `CLAUDE.md` once it exists. A PHPStan rule may be a
  cleaner home for the AST import/repository checks than a grep gate.

## Deferred from: code review of story-1.2 (2026-06-11)

- **`KeysetSqlSnapshotTest` no cierra la conexión DBAL paralela de `setUp()`** → un `tearDown()` con `parent::tearDown()` hace que rector `NoSetupWithParentCallOverrideRector` desnude `#[Override]` (la mitad psalm del antiguo conflicto rector↔psalm ya no aplica: el análisis general de Psalm fue retirado, así que no se exige baseline de `MissingOverrideAttribute`). Leak *low* mitigado por refcounting de PHP. Alternativa: cerrar dentro de `inRolledBackTransaction()` sin método override.
- **`resolveLimit` nunca aplica `policy.defaultLimit` (25)** → `limit` ausente (`SearchCriteria` default `MAX_LIMIT`=1000) → `min(1000, maxLimit=100)`=100, no 25; `WirePaginationPolicy::defaultLimit` queda inerte. Decidir en PR3 dónde vive el default (adapter HTTP vs engine).
- **Página `before` vacía devuelve `hasNext=false`** → debería ser `true` (la página de la que vienes es "next"); la rama vacía de `buildPage` puentea la lógica `isBefore ? hadCursor`. Off-wire y sin cursor accionable hoy; corregir en PR3.
- **`RowUniquenessGuard` falla-abierto fuera del caso addSelect-alias-líder** → no caza (a) cartesiano multi-root `from(A)->from(B)`, (b) joins to-many no seleccionados que también multiplican filas bajo `LIMIT`. Endurecer hacia fail-closed; excede el scope addSelect del AC2. (El caso `addSelect('a.field')`/`PARTIAL` SÍ se resolvió como patch P2.)
- **Índice compuesto `(col, id)` ASC no sirve scan limpio para `(col DESC, id ASC)`** → tie-break siempre ASC produce orden mixto; `SortFieldMapIndexContractTest` asserta existencia del índice, no su dirección. Gap de perf (no de corrección); evaluar en el perf gate de PR3.
- **El engine no impone `nullable: false` en columnas sortables** → confía en `sortFieldMap`; solo lo verifica el contract test hardcodeado a Bank. Una columna sortable nullable futura corrompería el walk y pasaría el engine.
- **`SortFieldMapIndexContractTest` refleja los campos de Bank a mano** → no los deriva del `SortFieldMap` del repo; AC4 dice "por cada entrada de `sortFieldMap()`". Un campo sortable nuevo escapa al contrato salvo edición manual del provider.
- **AC5 invariante (3) "frontera intra-empate" asserted solo transitivamente** → subsumida por la igualdad partición==oráculo; añadir aserción explícita de cursor-frontera para cumplir la letra del AC.
- **`qualify()` reescribe el DQL del predicado por regex** → acoplado a que el builder emita `id` bare; seguro hoy (Bank pre-cualifica con `b.`), latente para paths de sort bare en reuso genérico. Preferible pasar el alias al `KeysetPredicateBuilder`.
- **`entityName()` colapsa al nombre corto de clase** → dos entidades homónimas en distintos contextos producirían el mismo segmento del fingerprint (integrity binding del cursor). Single-tenant/Bank hoy; usar FQCN si entra multi-contexto/multi-tenant.
- **Migración `down()` revierte collation a `pg_catalog."default"`** → en vez de la heredada original; no es inverso fiel si el clúster usa locale no-`C`. Low; `down()` rara vez corre en prod.
