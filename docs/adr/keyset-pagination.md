---
status: 'IMPLEMENTATION LOCKED 2026-06-10 — shipped (epic-1, stories 1.1–1.5, PR1–PR4)'
date: '2026-06-10'
scope: >-
  Rediseño de la paginación a cursor/keyset puro (contrato API limit+cursor,
  prev/next sin números de página, modos LIGHT/DETAILED, cursor HMAC corto con
  fingerprint de query, ordenación estable, índices, "ir a fecha", exportaciones
  async) + reestructuración de AbstractDoctrineRepository y
  AbstractDoctrineSearchRepository de herencia a composición.
relatedDecisions:
  - 'docs/adr/filters-search-criteria.md (filtros genéricos filters[], 2026-06-06 — cerrado, restricciones heredadas)'
---

# ADR — Keyset Pagination & Repository Restructuring

Decision record: rationale and the FR/NFR/AR/W/K inventory cited by ID from the living docs ([`architecture-api.md`](../architecture-api.md), [`runbooks/cursor-pagination.md`](../runbooks/cursor-pagination.md) describe the shipped system).

> **Post-freeze overrides (D-1 cycle, 2026-06-11)** — the planning truth diverged from this frozen snapshot on three points, all implemented: repositories-by-composition moved from PR2 to PR3; NFR3 refined (a UNIQUE column does not require a composite index); collation narrowed to column scope (AR23, with the AR23/AR24 refinements). On those points the shipped code, not this document, is authoritative.

## Project Context Analysis

### Requirements Overview

**Functional Requirements:**

Sin PRD formal (precedente del ADR de filtros 2026-06-06): la base de
requisitos es la especificación de paginación aportada por Sergio en sesión
(2026-06-10) más el análisis de código de la misma sesión (`Paginator.php` —
issue Sonar `php:S1448`, 21 métodos —, jerarquía
`AbstractDoctrineRepository`/`AbstractDoctrineSearchRepository`,
`PaginatorCursor` mutable con acoplamiento temporal). El análisis fue
refinado mediante elicitación avanzada (edge-case sweep + pre-mortem) en la
misma sesión. Dos bloques:

**Bloque A — Paginación keyset pura (contrato público):**

1. **FR1 — Contrato wire cursor-only**: `limit` + cursores opacos; se
   eliminan `page`, `currentPage`, `pageCount` y `MAX_PAGE` del contrato.
   Navegación exclusivamente next/previous (decisión de producto: en un ERP
   se filtra/busca/ordena; los saltos a página arbitraria se sustituyen por
   filtros potentes e "ir a fecha").
2. **FR2 — Cursor corto firmado y ligado a su query**: payload = valores de
   las claves de ordenación de la fila frontera + dirección + fingerprint.
   `fingerprint = hash(tenant + entity + normalizedFilters + sort +
   direction + limit)` — `limit` incluido (un cursor de `limit=50` no
   representa la misma ventana con `limit=500`); `normalizedFilters` es la
   representación canónica **sintáctica** estable (orden determinista por
   field/operator/value, computada sobre los `Filters` de dominio ya
   parseados y normalizados, nunca sobre la query string cruda). `tenant`
   entra como slot reservado (valor constante hasta la Fase H del roadmap
   SaaS). Cualquier mismatch → **422 `invalid-cursor`**, nunca degradación
   silenciosa. HMAC conservado; **sin compresión zlib** (~100–150 chars,
   apto para URL navegable).
3. **FR3 — Conteo bajo demanda**: `PaginationMode` LIGHT (default, sin
   COUNT) / DETAILED (COUNT explícito) se conserva tal cual;
   `estimatedTotal` explícitamente diferido (sin consumidor real).
4. **FR4 — Ordenación estable**: tie-break por `id` en todo ORDER BY
   (invariante ya implementado, se conserva); `SortDirection` enum de punta
   a punta (hoy `getWhereOperator` compara strings `'asc'`).
5. **FR5 — "Ir a fecha" como cursor sintetizado**: el servidor fabrica una
   posición de cursor desde un valor de la clave de ordenación
   (`transactionDate <= X`) — capacidad inherente del modelo keyset. Se
   diseña el seam; la UI es alcance posterior de la PWA.
6. **FR6 — Wire envelope nuevo**: `PaginationMeta` pasa de
   `{currentPage, pageCount, count, hasMorePages, cursor}` a
   `{hasNext, hasPrev, count?, links: {next, prev}}` con **shape
   constante** (`links.next`/`links.prev` siempre presentes, `null` cuando
   no aplican). Sintaxis alineada al profile "Cursor Pagination" de
   JSON:API y Zalando Rule 160 (research 2026-06-06). Breaking change
   asumido: el único consumidor es la PWA propia.
7. **FR7 — Exportaciones async**: el mismo motor keyset alimenta los
   workers de exportación vía Messenger (batches por cursor, nunca
   OFFSET) — se diseña el seam; la feature de exportación es alcance
   futuro.
8. **FR13 — Navegación direccional explícita `after`/`before`**: cada
   página emite dos cursores independientes (`afterCursor` desde la última
   fila, `beforeCursor` desde la primera), expuestos como `links.next`
   (`?after=…`) y `links.prev` (`?before=…`). El cliente nunca compone
   dirección + posición. Detalle de implementación contenido: el fetch de
   `before` invierte el ORDER BY en SQL y re-invierte en memoria —
   localizado en el ejecutor, invisible en el contrato, testeable como
   función pura. Elimina toda inferencia de dirección por comparación de
   páginas (origen del `firstItem`/`lastItem`/`alterOffset` actual).
9. **FR14 — Sin garantía de instantánea entre páginas (documentado)**: la
   paginación navega sobre el estado actual del conjunto;
   inserciones/borrados/mutaciones entre peticiones alteran el dataset
   visible (estándar de la industria SaaS). Resolverlo (snapshot tokens,
   repeatable reads, datasets materializados) queda fuera de alcance.
   Garantía que sí se da: sin duplicados ni saltos *causados por la propia
   paginación* (la anomalía de OFFSET que keyset elimina), y unicidad de
   ids dentro de cada página.
10. **FR15 — Versionado del formato de cursor**: el payload firmado lleva
    `v` (schema version), p. ej. `{"v":1,"dir":"after","values":{…},"fp":"…"}`.
    Cubre lo que el fingerprint no cubre: el identificador público de sort
    no cambia pero su definición interna sí (`SortFieldMap` redefine
    columnas, o cambia la serialización de valores). Bump de `v` ⇒ todos
    los cursores anteriores → 422 `invalid-cursor` ⇒ reinicio desde la
    primera página (coste asumido). La compatibilidad de cursores en vuelo
    es una **decisión explícita por release**, nunca accidental — y
    observable (422 en métricas vs. reinicios fantasma).

**Bloque B — Reestructuración de repositorios (herencia → composición):**

11. **FR8 — Motor de búsqueda inyectable**: extraer
    `getPaginatedResults`/`getQueryBuilderPaginatedResults` + heurística
    composite-PK a un `DoctrineSearchEngine` inyectado (patrón validado por
    `FilterApplier`). El motor aplica *siempre* sort (vía `SortFieldMap`),
    limit y filtros; el repositorio solo aporta su query builder base con
    joins. **Regla de fuente de verdad única**: el engine es el único punto
    que ve los `Filters` de dominio y entrega *el mismo objeto* a
    `FilterApplier` y a `FingerprintCanonicalizer` — el fingerprint jamás se
    computa re-parseando el wire. Propiedad testeable: los `Filters`
    aplicados al QueryBuilder y los hasheados son value-equal por
    construcción.
12. **FR9 — Repositorios sin base class de framework**: implementan solo
    sus puertos de dominio; `EntityManagerInterface` inyectado. Desaparecen
    `ServiceEntityRepository` (que regala 30+ métodos públicos fuera del
    puerto), `getEntityClassName()`, `QueryBuilderWithOptions` (canal
    lateral con `instanceof` y round-trip absurdo de `paginationMode`) y
    `PaginatorOption` (bolsa de opciones → `PaginatorConfig` readonly
    tipado).
13. **FR10 — Descomposición del `Paginator`**: `Cursor` y `Page<T>`
    inmutables; `paginate(): Page` explícito — elimina el
    `IteratorAggregate` perezoso que muta el cursor al iterar y el
    acoplamiento temporal documentado en `SearchResponder`. Colaboradores
    puros: `CursorCodec`, `FingerprintCanonicalizer`,
    `KeysetPredicateBuilder`, `OrderByColumns`, `CursorPositionExtractor`.
    Resuelve Sonar S1448 estructuralmente (21 métodos → orquestador
    delgado) y elimina las 3 supresiones PHPMD.
14. **FR11 — Eliminación de código muerto**: `addWhereInCaseInsensitive`,
    `addWhereBetweenDates`, `addWhereBetweenValues`, `sanitizeArray` (cero
    llamadores) y la doble llamada frágil de
    `generateUniqueParameterName`; **preservar el why** del naming estable
    de parámetros (caché SQL de Doctrine — comentario de producción).
15. **FR12 — Frontera transaccional → decisión separada, no bloqueante**:
    dirección preferida = flush en Application Service
    (`save()+save()+outbox→append()+flush()` único), no `persistAndFlush`
    en repositorio. Este ADR no la resuelve: registra el estado actual como
    interino consciente y abre decisión separada. Restricción vinculante:
    FR8–FR9 no deben cerrar la puerta (los repos exponen `save()` sin
    flush implícito obligatorio en el contrato del puerto).

**Non-goals (exclusiones explícitas — tan vinculantes como los FRs):**

- **Sin normalización semántica de filtros**: el canonicalizador es
  sintáctico **por diseño, no evolucionable a semántico sin ADR nuevo**.
  Razón: la equivalencia semántica es incorrecta en el dominio
  (`amount > 100 ≢ amount >= 101` sobre DECIMAL) y la asimetría de fallos
  la condena — falso positivo del fingerprint = 422 + reinicio (barato);
  falso negativo = datos incorrectos con apariencia válida (lo que el
  fingerprint existe para impedir). La dualidad `between` ya es
  estructuralmente imposible (operador eliminado a propósito). Si dos
  productores generan filtros equivalentes-pero-distintos, la
  normalización pertenece al productor.
- **Sin snapshot consistency** (FR14).
- **Sin abstracción de página** (números de página fuera del sistema).
- **Sin paginación híbrida** (el modo legacy muere en PR4; la válvula de
  transición es env-gated y temporal).
- **Sin degradación silenciosa del cursor** (toda invalidez es 422
  observable).

**Non-Functional Requirements:**

- **Seguridad**: HMAC con `hash_equals` conservado; cap de longitud
  pre-HMAC sustituye al cap anti zip-bomb (sin zlib no hay bomba);
  allow-lists de identificadores ORDER BY y parámetros bindeados intactos;
  fingerprint con slot de tenant (Fase H).
- **Contrato de errores (NFR26)**: las cuatro causas de invalidez — firma
  inválida, fingerprint mismatch, payload corrupto, versión expirada —
  producen el mismo **422 `invalid-cursor`** (familia
  `invalid-search-criteria`), indistinguibles para el cliente. Obliga a:
  fila en `docs/api-error-contract.md`, `MarkerStatusMapContractTest`,
  `make php.lint.error-contract` verde.
- **Rendimiento**: keyset O(1) por página independiente de la profundidad.
  **Sortable ⇒ índice compuesto `(columna, id)`** (no índice simple — los
  empates masivos lo degradan, y `TIMESTAMP(0)` los garantiza); tenant-led
  `(company_id, columna, id)` cuando llegue Fase H. Doble gate: (a) test
  de arquitectura en CI (existencia del índice por cada entrada de
  `sortFieldMap()`), (b) perf gate de staging que valida **robustez del
  plan bajo distribución realista** con dos perfiles — uniforme (~100k,
  corrección) y sesgado (skew 80/10, clustering temporal, skew por tenant)
  — porque `EXPLAIN` sobre tablas diminutas de CI miente. p95 del listado
  sin regresión.
- **Calidad**: resolución estructural (no supresión) de Sonar `php:S1448`;
  gates `make php.stan` + `make php.quality` (PHPMD sin
  baseline). **Regla de pureza de capa**: colaboradores deterministas
  (input → output), readonly, sin estado interno ni servicios instanciados
  dentro (el `PropertyAccess::createPropertyAccessor()` por llamada actual
  es el anti-ejemplo); solo `DoctrineSearchEngine` toca Doctrine y su única
  responsabilidad es orquestar — si necesita un método privado con lógica
  propia, esa lógica es un colaborador nuevo. Criterio de review: *¿este
  test necesita el kernel?*
- **Pureza de dominio**: 0 dependencias nuevas en `Domain/`; el puerto
  evoluciona a `Page` sin imports de framework; los arrays
  `firstItem`/`lastItem` del `SearchCursor` (detalle de persistencia
  filtrado al dominio) desaparecen del puerto.
- **Compatibilidad**: cero migraciones de BD. Breaking change del envelope
  coordinado con la PWA en el mismo ciclo (PR3); cursores en vuelo
  invalidados explícitamente (422 + `v`).
- **Multiempresa (forward-looking)**: el esquema actual NO tiene
  `company_id` — la Fase H del roadmap SaaS (añadida 2026-06-10) lo
  planifica; este diseño reserva el slot de tenant en el fingerprint y la
  posición líder en los índices compuestos para que la llegada del tenant
  no fuerce un segundo rediseño.

**Scale & Complexity:**

- Primary domain: API (`api/src/Shared/`) con cambio de contrato consumido
  por la PWA (`pwa/src/context/shared`).
- Complexity level: media — transversal a la fundación compartida, pero
  acotada: 2 repositorios concretos (Bank, BankAccount), 1 endpoint público
  (`GET /api/v1/backoffice/banks`), 1 consumidor (PWA propia), 29
  escenarios Behat como red de seguridad.
- Estimated architectural components: ~14 (VOs `Cursor`/`Page`/
  `PaginatorConfig`, `CursorCodec`, `FingerprintCanonicalizer`,
  `KeysetPredicateBuilder`, `OrderByColumns`, `CursorPositionExtractor`,
  `DoctrineSearchEngine`, `PaginationMeta` v2, marker/type de error,
  contract tests de índices, cambios PWA en tipos + builder + vistas).

### Technical Constraints & Dependencies

- Stack fijado (no renegociable): PHP 8.5 · Symfony 8.0 · Doctrine ORM 3.6 /
  DBAL 4.4 · PostgreSQL 18 · Next 16 / TS 6 estricto.
- **Derogaciones explícitas del ADR de filtros (2026-06-06)** — se
  superseden dos de sus pins:
  1. *"Conservar `Paginator` keyset/HMAC y modos LIGHT/DETAILED tal cual"*
     y *"el contrato de respuesta no cambia"* → este ADR rediseña el
     `Paginator` y el envelope. **Se conservan**: LIGHT/DETAILED, el seam
     `FilterApplier`/`SearchFieldMap`/`SortFieldMap` completo y la
     gramática `filters[]` (input de este diseño, no objeto de cambio).
  2. *"Fallo de firma silenciado (no oráculo)"* → 422 `invalid-cursor`
     explícito. Razón: `hash_equals` ya elimina el oráculo de timing; el
     silencio fabrica datos aparentemente correctos (cursor corrupto →
     página 1 sin avisar), peor que la excepción.
- `SearchQuery`/`SearchCriteria` son `final`: los cambios de contrato pasan
  por esas clases compartidas y el envelope, nunca por variantes por
  entidad.
- El `Paginator` actual es un port de `chiliz/doctrine-bundle` — sin
  obligación de preservar su forma interna, solo sus garantías (keyset,
  HMAC, estabilidad, LIGHT/DETAILED).
- Research JSON:API (2026-06-06): no se adopta el document format; la
  sintaxis se ancla a convenciones publicadas (profile Cursor Pagination,
  AIP-158, Zalando 160) sin envelope JSON:API.
- `SearchExceptionListener` legacy (priority 32) convive con
  `ExceptionResponder` (16) — verificar que no intercepta los errores
  nuevos de cursor.
- Default de `limit`: hoy `MAX_LIMIT = 1000` es también el default — pasa a
  default pequeño (25–50) con techo explícito; afecta a `SearchQuery`,
  `SearchCriteria` y fixtures/Behat que asuman 1000.

### Keyset Edge-Case Contract (vinculante para las decisiones)

1. **Cursor por valores, no por referencia**: borrar la fila frontera no
   invalida el cursor (transporta valores de las claves de ordenación);
   su *mutación* cae bajo FR14.
2. **Empates**: el cursor transporta todas las claves del ORDER BY incluida
   `id`; el predicado keyset es la cadena `col > :v OR (col = :v AND id >
   :i)` (DQL no soporta tuplas) ⇒ índice compuesto obligatorio.
3. **Envelope de shape constante**: `links.next`/`links.prev` siempre
   presentes, `null` cuando no aplican (última/primera página).
4. **Página vacía** (por filtro o fin de dataset, o `before`/`after` fuera
   de rango) → 200 con `items: []`, `hasNext/hasPrev: false`, `count: 0`
   en DETAILED — nunca error.
5. **Descarte client-side** de ambos cursores al cambiar
   `sort`/`direction`/`filters`/`limit` — regla de la PWA, defensa en
   profundidad sobre el fingerprint (la UX no depende del 422).
6. **Fingerprint completo** (FR2): tenant + entity + normalizedFilters +
   sort + direction + limit.
7. **Solo columnas NOT NULL son sortables** (`NULL > x` es unknown — el
   predicado keyset omitiría filas en silencio). Verificable en la
   construcción del `SortFieldMap`.
8. **Toda invalidez → 422 `invalid-cursor`**, cuatro causas
   indistinguibles: firma, fingerprint, payload, versión (deroga el
   silenciamiento del ADR previo).
9. **`after`+`before` simultáneos** → 422 `validation-failed` en mapping
   (mutuamente excluyentes); cursor sobredimensionado → cap de longitud en
   `#[Assert]` antes de tocar HMAC.
10. **Precisión de serialización**: los valores datetime del cursor se
    serializan a la precisión de la columna (`TIMESTAMP(0)` ⇒ segundos) —
    round-trip exacto cursor↔SQL (hoy `extractFields` emite microsegundos
    sobre columnas de segundos: desajuste de frontera).

### Cross-Cutting Concerns Identified

- **Contrato cross-deployable API↔PWA**: el envelope nuevo exige cambios
  sincronizados en tipos TS y componentes de lista. Regla dura en PR3:
  eliminar `currentPage`/`pageCount` de los tipos compartidos (que el
  compilador encuentre cada consumidor) **y barrido explícito de capas de
  mapeo ocultas** (boundary functions, adapters, hooks de paginación) — se
  eliminan junto al tipo, no se adaptan ("TS limpio pero UX legacy" es el
  fallo que el compilador no caza).
- **Errores RFC 9457**: todo error nuevo fluye por el pipeline existente;
  cero `JsonResponse` manual.
- **Observabilidad**: el cambio de envelope mata las métricas basadas en
  página. Métricas nuevas en PR3: `invalid_cursor_count` **dimensionada
  por causa** (firma/fingerprint/payload/versión — sin esto no se
  distingue un ataque de un deploy con bump de `v`),
  `cursor_version_distribution`, `next_navigation_count`,
  `prev_navigation_count`; profundidad aproximada por cadenas de
  navegación por correlation-id. Dashboards actualizados en el mismo PR.
- **Documentación obligatoria por PR** (CLAUDE.md):
  `docs/architecture-api.md` (sección filterable-search + receta),
  `api/docs/adding-endpoints.md`, `docs/api-error-contract.md` (marker),
  `docs/architecture-pwa.md` + `pwa/docs/`,
  `docs/saas-production-roadmap.md` (Fase H ya añadida 2026-06-10).
- **Tests como red**: los 29 escenarios Behat de search se actualizan
  **dentro de PR3** (el switch), no después — si Behat pasa en PR3, PR4 es
  puramente sustractivo.

### Risk Register (pre-mortem 2026-06-10)

| Riesgo | Prob. | Impacto | Mitigación |
|---|---|---|---|
| PR demasiado grande (big-bang) | Alta | Alta | Secuencia PR1–PR4 vinculante (abajo) |
| Migración incompleta PWA | Alta | Media | Hard removal de tipos + barrido de adapters ocultos (PR3) |
| Índices olvidados | Media | Alta | `SortFieldMapIndexContractTest` (CI) + perf gate staging doble perfil |
| Bugs en navegación `before` | Media | Alta | Tests de simetría + fixture de empates masivos |
| Canonicalización de filtros | Media | Media | `FingerprintCanonicalizer` pieza dedicada, sintáctica, suite propia |
| Observabilidad insuficiente | Media | Media | Métricas por causa + dashboards en PR3 |
| Divergencia QueryBuilder ↔ Fingerprint | Baja | Muy alta | Fuente de verdad única: mismo objeto `Filters` (FR8) + test de identidad |
| Diseño keyset incorrecto | Baja | Muy alta | Contrato de extremos 10 puntos + propiedad de simetría |
| HMAC/versionado defectuoso | Baja | Alta | `CursorCodec` puro con suite dedicada; 4 causas → un solo 422 |

**Secuencia de implementación vinculante (mitigación del riesgo nº 1):**

- **PR1** — VOs `Cursor`/`Page`/`PaginatorConfig` + `CursorCodec` +
  `FingerprintCanonicalizer` + `KeysetPredicateBuilder` +
  `CursorPositionExtractor` + `OrderByColumns` + suites unitarias puras.
  Sin cambio de contrato.
- **PR2** — `DoctrineSearchEngine` + migración interna de Bank y
  BankAccount + `SortFieldMapIndexContractTest`. Wire intacto (envelope
  viejo emitido desde el motor nuevo).
- **PR3** — el switch: envelope nuevo + PWA (tipos, builder, vistas,
  barrido de adapters) + Behat actualizado + métricas/dashboards + válvula
  de transición. Único PR con cambio de comportamiento observable.
- **PR4** — borrado del legado: `Paginator` viejo, `PaginatorCursor*`,
  `QueryBuilderWithOptions`, `PaginatorOption`, código muerto (FR11),
  válvula. Puramente sustractivo; PR3 es revertible sin tocar PR4.
- **Válvula de transición** `pagination_mode=legacy|cursor_v2`
  (mitigación del flip semántico de PR3), con tres condiciones: (a)
  env-gated `dev`/`staging` — inalcanzable en prod por construcción;
  (b) vida útil = ventana PR3→PR4; (c) cero tests propios del modo legacy
  más allá de los Behat existentes.
- Cada PR en su worktree (regla del repo); riesgo residual dominante tras
  mitigaciones: coordinación de ejecución de PR3 (alto), no el modelo.

## Starter Template Evaluation

### Primary Technology Domain

Brownfield — evolución de la fundación compartida (`api/src/Shared/` +
`pwa/src/context/shared`) del monorepo existente. No aplica scaffolding de
proyecto; la pregunta análoga al "starter" es: ¿librería keyset existente o
implementación propia del motor?

### Starter Options Considered

Candidatas nombradas por el research JSON:API (2026-06-06), verificadas
contra Packagist el 2026-06-10:

| Candidata | Estado verificado | Veredicto |
|---|---|---|
| `silarhi/cursor-pagination` 2.1.0 (2026-01-29) | Viva (PHP ≥8.2, ORM ^3, MIT) — pero es iteración **batch server-side** (chunks para workers), sin serialización de cursor para clientes, sin `before`/`after`, sin HMAC/fingerprint, sin envelope HTTP | Descartada para el contrato HTTP (alcance distinto). Referencia para el patrón de iteración de FR7, aunque el motor propio lo cubre sin dependencia nueva (YAGNI) |
| `paysera/lib-pagination` 1.5.0 (2025-01-14) | Requiere `symfony/property-access ^3‖^4‖^5‖^6` → conflicto Composer directo con Symfony 8 | Ininstalable — descartada |
| `mention/fast-doctrine-paginator` 2.0.0 (2023-12-06) | Sin release en ~2,5 años | Descartada por mantenimiento |

No existe librería que cubra el contrato completo (cursores direccionales
firmados + fingerprint + versionado + envelope): esas piezas son específicas
de este diseño y constituyen su valor.

### Selected Foundation: fundación existente de ERPify + implementación propia (no starter)

**Rationale for Selection:**
Mismo veredicto que el ADR de filtros (opción C del research Criteria):
absorber *ideas* publicadas (profile JSON:API Cursor Pagination, Zalando
Rule 160, Google AIP-158, el patrón keyset de use-the-index-luke), nunca
código ni dependencias de terceros para el mecanismo central. Fundación que
se conserva y sobre la que se construye: seam
`FilterApplier`/`SearchFieldMap`/`SortFieldMap`, pipeline RFC 9457 con su
gate, `PaginationMode`, 29 escenarios Behat, gates Make
(`php.stan`/`php.quality`), stacks por worktree.

**Initialization Command:**

```bash
make worktree.create BRANCH=feat/api-keyset-pagination   # PR1 — VOs + colaboradores puros
```

**Architectural Decisions Provided by the Existing Foundation:**

- **Language & Runtime:** PHP 8.5 / Symfony 8.0 (componentes) · TS 6 strict /
  Next 16 — fijado por `docs/project-context.md`, no renegociable.
- **Persistence & Search plumbing:** Doctrine QueryBuilder-first; seam de
  búsqueda compartido como punto de inserción; `PaginationMode`
  LIGHT/DETAILED conservado.
- **HTTP boundary:** `#[MapQueryString]` + `#[Assert]` sobre DTOs readonly
  con errores vía pipeline RFC 9457.
- **Testing Framework:** PHPUnit 13 + Behat (29 escenarios search) +
  Vitest/Playwright — infraestructura configurada.
- **Code Organization:** DDD + Hexagonal con bounded contexts.
- **Development Experience:** Make-first, stacks por worktree, gate de
  calidad PHPStan `level: max` (única autoridad de tipos; Psalm solo taint).

**Note:** la primera historia de implementación es PR1 de la secuencia
vinculante del Risk Register (VOs `Cursor`/`Page`/`PaginatorConfig` +
colaboradores puros + suites unitarias, sin cambio de contrato).

## Core Architectural Decisions

> **Rationale del conjunto**: lo que estas decisiones construyen no es
> "paginación" — es una **máquina de transiciones de navegación validadas
> criptográficamente sobre una relación ordenada mutable**. El backend no
> responde páginas; valida transiciones de estado. La propiedad residual
> inevitable — *navigation correctness ≠ dataset stability* — no es un bug:
> es la propiedad definitoria del sistema, acotada por FR14 y la semántica
> de affordance de K10.

### Decision Priority Analysis

**Critical Decisions (Block Implementation):**

| #  | Decisión | Elección |
|----|----------|----------|
| K1 | Modelo de paginación | Keyset puro — sin números de página (FR1); next/prev + filtros + "ir a fecha" |
| K2 | Navegación wire | Params `after=` / `before=` mutuamente excluyentes (ambos → 422 `validation-failed` en mapping). **Autoridad semántica única**: el param es el *routing intent*; el `dir` del payload es *integrity binding only* — se compara (discrepancia → 422 `invalid-cursor`), jamás se consulta como fallback lógico |
| K3 | Formato de cursor | `base64url(json{v, dir, values, fp})` + `.` + HMAC-SHA256 **truncado a 128 bits** (32 hex); sin zlib; cap de longitud `#[Assert]` (512) pre-HMAC |
| K4 | Fingerprint | `hash(tenant + entity + normalizedFilters + sort + direction + limit)` — canónico **sintáctico** sobre `Filters` de dominio normalizados; `FingerprintCanonicalizer` pieza dedicada con suite propia |
| K5 | Invalidez de cursor | 4 causas (firma · fingerprint · payload · versión) → un solo 422 `invalid-cursor`: excepción `InvalidCursor` en `Shared/Domain/Search/Exception/`, familia `InvalidSearchCriteria` (NFR26 completo) |
| K6 | Envelope | `{hasNext, hasPrev, count?, links: {next, prev}}` — shape constante. **`null` = "navegación no posible"** — nunca omitido, nunca `undefined`; TS `string \| null` (no opcional); prohibido `skip_null_values` en este responder; contract test del shape |
| K7 | Puerto de dominio | `Page<T>` readonly reemplaza `PaginatedResult`+`SearchCursor`: `items`, `hasNext`, `hasPrev`, `count?`, `nextCursor`/`prevCursor` **strings opacos nullable** (el dominio trata el cursor como un id) |
| K8 | Motor de búsqueda | `DoctrineSearchEngine` inyectable + colaboradores puros + `PaginatorConfig` readonly. **Invariante (refinado por elicitación): equivalencia por applied trace, no identidad por referencia** — cada etapa del pipeline devuelve un recibo inmutable de lo realmente aplicado (`AppliedFilters`, `AppliedSort`, `AppliedLimit`), compuestos en un `QueryExecutionTrace` sellado; el fingerprint deriva exclusivamente del trace. Es imposible por flujo de datos fingerprint-ear algo distinto de lo aplicado: la identidad `===` baja a assert de defensa en profundidad. El trace es el **single point of semantic truth** — su construcción (no su consumo) es el único modo de corrupción silenciosa restante, asegurado por el *trace equivalence stability test* (ver patrones) |
| K9 | Repositorios | Composición: solo sus puertos de dominio, `EntityManagerInterface` inyectado; mueren `ServiceEntityRepository`, `QueryBuilderWithOptions`, `PaginatorOption`, `getEntityClassName()` |

**Doctrine Boundary Contract (amplía K8/K9 — integridad del sistema):**

> *Doctrine is a side-effectful execution engine, not a deterministic
> function.* El QueryBuilder no es un transformador puro de AST: es un
> pipeline de compilación con estado, hydration con efectos, proxies que
> alteran identidad percibida y frontera de ejecución perezosa. Por tanto:
> **todos los invariantes del sistema (equivalencia por applied trace,
> sorting, predicado keyset) se imponen PRE-compilación; el estado
> post-compilación del QueryBuilder no forma parte de las garantías del
> sistema. El SQL compilado es un derivado NO normativo** — verificación de
> regresión en CI, nunca compatibilidad runtime (ver Format Patterns).
> Verificación: **precompiled AST snapshot test** — compara únicamente
> SQL string + parámetros bindeados + ordering; nunca objetos Doctrine.
> Dos builds que divergen en SQL/binds/orden rompen el test aunque los
> unit tests de colaboradores sigan verdes.

**Important Decisions (Shape Architecture):**

| #   | Decisión | Elección |
|-----|----------|----------|
| K10 | Flags de navegación | **Semántica de affordance**: `hasNext`/`hasPrev` afirman disponibilidad de enlace, no existencia garantizada de filas. Heurística sin queries extra: dirección navegada → trick +1; contraria → derivada (`after` ⇒ `hasPrev: true`; sin cursor ⇒ `false`; `before` ⇒ `hasNext: true`); cursor sintetizado ⇒ `hasPrev: true` conservador. Enlace hacia hueco lógico → 200 `items: []`. **Prohibición operativa: los flags NO deben usarse para inferir completitud del dataset** — analítica/conciliación/conteo usan DETAILED (`count`) o el seam analítico; un flag de navegación alimentando una decisión de negocio es un bug de uso |
| K11 | Límites | `limit` default **25**, techo **100** (wire). **Gate semántico: `wire_limit` es restricción de UX, no analítica** — una necesidad de lote grande nunca se resuelve subiendo el techo; dispara el diseño del seam analítico (deferred). Exportaciones FR7: batches internos exentos |
| K12 | Versionado | `v: 1` inicial; bump = decisión explícita por release |
| K13 | Válvula de transición | `dev`/`staging` only (`#[When(env)]`), vida = ventana PR3→PR4, cero tests propios |
| K14 | "Ir a fecha" | Seam server-side: sintetiza posición de cursor desde un valor de clave de ordenación — sin estado, misma maquinaria K3/K4 |
| K15 | `before` interno | Inversión de ORDER BY + re-reverse en memoria, contenida en el ejecutor — invisible en contrato, testeable pura |

**Deferred Decisions (Post-MVP):**

- `estimatedTotal` (sin consumidor; LIGHT/DETAILED cubre hoy).
- Frontera transaccional (FR12) — ADR separado; este solo garantiza no
  cerrar la puerta.
- Feature de exportaciones (FR7 deja el seam).
- **Analysis-mode navigation** — acceso a lotes grandes vía seam propio
  (iteración batch de FR7 o endpoint analítico), decisión de producto;
  trigger de reapertura: cualquier petición de subir el techo wire.
- Tenant real en fingerprint e índices — Fase H del roadmap.
- UI de "ir a fecha" — fase PWA posterior.
- Normalización semántica de filtros — non-goal que requiere ADR nuevo.

### Data Architecture

Cero migraciones de BD en PR1–PR4. Reglas: sortable ⇒ índice compuesto
`(columna, id)` — `SortFieldMapIndexContractTest` (CI) + perf gate de
staging doble perfil (uniforme + sesgado) validando robustez del plan;
solo columnas NOT NULL sortables; valores datetime del cursor a precisión
de columna (`TIMESTAMP(0)` ⇒ segundos). Verificar en PR2 la variante
compuesta con `id` de los índices existentes de Bank.

### Authentication & Security

Sin cambios authn/authz. Del mecanismo: HMAC-SHA256 truncado 128 bits con
`hash_equals`; cap de longitud pre-HMAC; fingerprint liga cursor a query
(slot tenant); derogación consciente del silenciamiento de firma → 422
observable; allow-lists y binding intactos; el cursor solo transporta
valores de claves de ordenación de la fila frontera (mismo nivel de
exposición que el contenido ya servido).

### API & Communication Patterns

Wire: `?after=` | `?before=` | `limit` (25/100) | `paginationMode` |
`sort`/`direction` | `filters[]` — solo cambian los tres primeros.
Envelope K6 con `links` autocontenidos (URLs con `after`/`before`
embebidos). Errores exclusivamente por RFC 9457. Observabilidad:
`invalid_cursor_count{cause}`, `cursor_version_distribution`,
`next/prev_navigation_count`.

### Frontend Architecture

PR3: tipos TS nuevos del envelope en `pwa/src/context/shared` (named
exports, `string | null` no opcional); hard removal de
`currentPage`/`pageCount` + barrido de adapters/hooks ocultos; descarte de
cursores al cambiar `sort`/`direction`/`filters`/`limit`; el cliente nunca
decodifica cursores. Sin librerías nuevas.

### Infrastructure & Deployment

Sin cambios de hosting/CI salvo: job de perf gate en staging (doble
perfil, plan JSON de `EXPLAIN`), dashboards en PR3, válvula env-gated
(K13). Cero dependencias Composer/npm nuevas.

### Decision Impact Analysis

**Implementation Sequence:** PR1 (K3, K4, K7, K12 — piezas puras + AST
snapshot harness) → PR2 (K8, K9, K15 + Boundary Contract + gate de
índices — wire intacto) → PR3 (K2, K5, K6, K10, K11, K13 + PWA + Behat +
observabilidad — el único flip) → PR4 (borrado del legado + válvula).

**Cross-Component Dependencies:** K5 ⇄ pipeline RFC 9457 (verificar
`SearchExceptionListener` legacy, priority 32); K4 ⇄ K8 (canonicalizador
consume exclusivamente el `QueryExecutionTrace` — recibos de aplicación,
nunca el input); K7 ⇄ K6 (responder lee la `Page`, sin acoplamiento
temporal); K11 → `SearchQuery`/`SearchCriteria`/fixtures/Behat; K2 ⇄ K3
(cross-check `dir`); Boundary Contract ⇄ todos los colaboradores
(invariantes pre-compilación, SQL no normativo).

## Implementation Patterns & Consistency Rules

### Pattern Categories Defined

**8 áreas críticas de conflicto entre agentes** pineadas: nombres y
ubicaciones exactos, formato del cursor, canonicalización/trace, envelope,
capas de validación (DAG), orden del pipeline del motor, reglas PWA,
enforcement. Lo no listado aquí lo fija `docs/project-context.md`.

### Naming Patterns

**FQCNs exactos (API) — no inventar variantes:**

| Pieza | FQCN |
|---|---|
| Puerto de dominio | `Erpify\Shared\Domain\Search\Page` (final readonly, `@template T`) |
| Excepción (K5) | `Erpify\Shared\Domain\Search\Exception\InvalidCursor` — type `invalid-cursor` |
| Motor (K8) | `Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\DoctrineSearchEngine` |
| Config (K8) | `…\Doctrine\Search\PaginatorConfig` (final readonly) |
| Trace (K8) | `…\Doctrine\Search\Keyset\QueryExecutionTrace` + recibos `AppliedFilters` · `AppliedSort` · `AppliedLimit` |
| Colaboradores keyset | subnamespace `…\Doctrine\Search\Keyset\`: `CursorCodec` · `FingerprintCanonicalizer` · `KeysetPredicateBuilder` · `OrderByColumns` · `CursorPositionExtractor` · `Cursor` (VO infra) |
| Policies (kernel único) | `…\Doctrine\Search\Keyset\WirePaginationPolicy` · `BatchIterationPolicy` (FR7/analítico, se materializa con su consumidor) |
| Envelope HTTP | `…\Infrastructure\Http\Responder\PaginationMeta` (v2, misma ubicación) |
| Mueren en PR4 | `PaginatedResult`, `SearchCursor`, `Paginator`, `PaginatorCursor*`, `PaginatorCursorFactory`, `QueryBuilderWithOptions`, `PaginatorOption` |

**Wire**: params `after`, `before` (lowercase); envelope camelCase
(`hasNext`, `hasPrev`, `count`, `links.next`, `links.prev`); payload del
cursor con claves cortas `v`, `dir`, `values`, `fp`. Métricas snake_case:
`invalid_cursor_count{cause}`, `cause ∈ {signature, version, payload,
fingerprint}`; `cursor_version_distribution`;
`next/prev_navigation_count`.

**PWA**: tipos en `pwa/src/context/shared/domain/Search/` —
`PaginationLinks`, `PageEnvelope` con `links: { next: string | null;
prev: string | null }` (**no opcionales**); named exports.

### Structure Patterns

- Tests espejo: colaboradores puros en
  `api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/`
  (sin kernel); `KeysetSqlSnapshotTest` en
  `api/tests/Functional/Shared/Persistence/` (necesita metadata del EM, no
  ejecución); `SortFieldMapIndexContractTest` junto a los contract tests
  existentes; simetría/empates en Behat `search.feature` (extender, no
  crear feature paralela).
- **El test más importante del sistema** (cierre de elicitación): el
  **`TraceEquivalenceStabilityTest`** — propiedad formal: mismo input
  (criteria + field maps + entity) ⇒ trace canónico-idéntico,
  byte-a-byte, en ejecuciones repetidas y entre refactors. El
  `QueryExecutionTrace` es el single point of semantic truth: un drift en
  su **construcción** es el único modo de corrupción silenciosa restante;
  este test lo convierte en rojo de CI. El SQL snapshot queda subordinado
  (detector de regresiones del Boundary Contract).
- Object mothers `CursorMother`, `PageMother`, `TraceMother` siguiendo el
  precedente `FilterMother`.

### Format Patterns

- **Cursor string**: `<base64url sin padding>.<hmac-sha256 truncado a 32
  hex>` — exactamente un punto separador.
- **Fingerprint**: `fp = xxh128(canonical(QueryExecutionTrace))` — hash
  rápido no criptográfico es correcto: la integridad la da el HMAC del
  conjunto.
- **Cadena canónica**: `tenant|entity|filters|sort|direction|limit`
  derivada de los **recibos** del trace (jamás del input): filtros
  ordenados por (field, operator, valor serializado), listas IN ordenadas,
  datetimes UTC a precisión de columna. Determinista, documentada con
  ejemplos en su propia suite.
- **La serialización es frontera de seguridad/corrección**: cualquier
  cambio en orden de claves, formato de datetime, *ordering semantics*,
  normalización, redondeo de timezone o locale (FR15 ampliado: `v` =
  versión del contrato completo de serialización + canonicalización)
  exige bump de `v` en el mismo PR — nunca silencioso. Runbook: pico de
  `invalid_cursor_count{cause=version|fingerprint}` tras deploy = bug de
  encoding o bump esperado, no ataque; verificar el bump, no rotar
  secretos.
- **SQL compilado = derivado NO normativo** (principio final, elicitación
  paso 5): el cursor se valida exclusivamente contra el
  `QueryExecutionTrace`; el SQL sirve solo para verificación en CI de
  consistencia estructural, nunca para compatibilidad runtime. Un diff de
  snapshot es evidencia a investigar; el criterio de bump de `v` es
  siempre "¿cambió la semántica observable?", jamás "¿cambió el texto
  SQL?". Alternativa descartada-con-razón: `QueryCompilationVersion` con
  governance manual — vía de reapertura solo si Doctrine introduce un
  cambio de comportamiento observable que ningún test capture.
- **Datetimes en `values`**: UTC, `Y-m-d\TH:i:sP`, precisión de columna.
- **`links`**: URLs relativas al mismo endpoint, preservando los query
  params vigentes y sustituyendo solo `after`/`before`.

### Communication Patterns

Sin eventos nuevos: PROHIBIDO transportar cursores en payloads de
Messenger o topics de Mercure (estado de navegación de un cliente, no
hecho de dominio). `InvalidCursor` se loguea por el pipeline estándar
(warning 4xx) con `cause` en contexto — nunca el cursor crudo en logs.

### Process Patterns

**Dos capas de validación en DAG estricta (sin retro-dependencias):**

1. **QueryValidationLayer** — shape en mapping (422 `validation-failed`:
   `after`+`before` simultáneos, caps, `limit` ∉ [1,100]) + semántica de
   sort/filters (familia existente). Produce los inputs del pipeline.
2. **CursorValidationLayer** — orden *intrínseco-primero*: firma → versión
   → payload → **fingerprint** (único check extrínseco, va último). Consume
   el trace **sellado**; ningún output de la capa 1 puede mutarse
   post-fingerprint (el sellado del trace es el cierre del DAG).

**Orden del pipeline del motor (fijo — K8 + Boundary Contract):**
1. Resolver sort vía `SortFieldMap` + tie-break `id` → recibo
   `AppliedSort`. 2. Aplicar `FilterApplier` → recibo `AppliedFilters`.
3. Aplicar limit → recibo `AppliedLimit`. 4. **Sellar
   `QueryExecutionTrace`** + computar fingerprint. 5. Validar cursor
   (intrínseco → extrínseco). 6. Construir predicado keyset
   (pre-compilación). 7. Ejecutar con trick +1 (`before`: invertir +
   re-reverse contenido). 8. Construir `Page` inmutable + codificar ambos
   cursores. Invariantes impuestos hasta el paso 6; nada posterior forma
   parte de las garantías.

**Kernel único + policy-scoped configuration** (cierre de elicitación):
el `KeysetPredicateBuilder` NO se comparte "sin contexto" entre policies —
cada policy (`WirePaginationPolicy`, `BatchIterationPolicy`) entrega su
configuración explícita al builder (límites, semántica de frontera,
emisión de cursores). Congela el riesgo de *accidental coupling through
reuse*: divergencias sutiles wire/batch que no rompen tests pero rompen el
modelo mental.

**PWA:** descartar ambos cursores al cambiar
`sort`/`direction`/`filters`/`limit`; nunca decodificar un cursor; usar
`links` tal cual.

### Enforcement Guidelines

**All AI Agents MUST:** `make php.stan` por archivo y
`make php.quality` al cierre; `make php.lint.error-contract` + fila en
`api-error-contract.md` + `MarkerStatusMapContractTest` en el PR de K5;
mantener verdes `TraceEquivalenceStabilityTest`,
`SortFieldMapIndexContractTest`, shape del envelope y SQL snapshot;
`make pwa.quality` + Vitest en PR3.

**Anti-Patterns (prohibidos):**

- ❌ Leer `dir` del payload para *decidir* dirección (integrity binding —
  K2).
- ❌ Computar el fingerprint desde el input (criteria/query string) en vez
  de los recibos del trace.
- ❌ Mutar cualquier input del trace después del sellado (rompe el DAG).
- ❌ Usar el SQL compilado (texto, hash, AST) como input de compatibilidad
  runtime.
- ❌ Usar `hasNext`/`hasPrev` para inferir completitud del dataset.
- ❌ `skip_null_values` o claves omitidas en el envelope.
- ❌ Subir el techo de `limit` para casos analíticos (gate semántico K11).
- ❌ Decodificar/fabricar cursores client-side.
- ❌ `OFFSET`/`setFirstResult` en el read-path de búsqueda.
- ❌ Compartir el `KeysetPredicateBuilder` entre policies sin configuración
  explícita por policy.
- ❌ Aserciones sobre objetos `QueryBuilder`/`Query` en tests del motor —
  solo SQL string + binds + ordering (Boundary Contract).
- ❌ Una segunda implementación de paginación (single pagination kernel).
- ❌ Catch de `InvalidCursor` fuera del pipeline RFC 9457.

### Pattern Examples

**Good — request/response:**

```text
GET /api/v1/backoffice/banks?filters[0][field]=name&filters[0][operator]=contains
    &filters[0][value]=banc&sort=name&direction=ASC&limit=25&after=eyJ2IjoxLCJkaXIi….a3f9c2…

200 {"data":[…25 items…],
     "pagination":{"hasNext":true,"hasPrev":true,"count":null,
                   "links":{"next":"…?filters…&after=…","prev":"…?filters…&before=…"}}}
```

**Good — simetría (Behat):** dataset con empates masivos → `next`×3 →
`prev`×3 → ids idénticos en orden inverso exacto.

**Anti-example:** un helper PWA `getPageNumber(envelope)` — el concepto no
existe; su aparición señala migración incompleta (Risk Register, causa 4).

## Project Structure & Boundaries

### Complete Project Directory Structure

Árbol **delta** sobre el monorepo (brownfield): `[N]` nuevo · `[M]`
modificado · `[D]` eliminado, organizado por PR de la secuencia vinculante.
Rutas verificadas contra el working tree el 2026-06-10 (nota: el
`AbstractSearchController` del deep-dive de mayo ya no existe — los
controllers inyectan `SearchResponder`).

**PR1 — piezas puras (`feat/api-keyset-pagination`), sin cambio de contrato:**

```text
api/src/Shared/
├── Domain/Search/
│   ├── Page.php                                      [N] puerto final readonly <T> (K7)
│   └── Exception/InvalidCursor.php                   [N] type invalid-cursor (K5)
└── Infrastructure/Persistence/Doctrine/Search/
    ├── PaginatorConfig.php                           [N] config readonly (K8)
    └── Keyset/                                       [N] subnamespace nuevo
        ├── Cursor.php                                [N] VO infra {v, dir, values, fp}
        ├── CursorCodec.php                           [N] base64url + HMAC-128 (K3)
        ├── QueryExecutionTrace.php                   [N] trace sellado (K8)
        ├── AppliedFilters.php · AppliedSort.php · AppliedLimit.php   [N] recibos
        ├── FingerprintCanonicalizer.php              [N] canónico sintáctico (K4)
        ├── KeysetPredicateBuilder.php                [N] cadena OR/AND keyset
        ├── OrderByColumns.php                        [N] VO columnas+dirección
        ├── CursorPositionExtractor.php               [N] valores frontera (precisión columna)
        └── WirePaginationPolicy.php                  [N] policy wire (kernel único)

api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/
├── CursorCodecTest · FingerprintCanonicalizerTest · KeysetPredicateBuilderTest
│   · CursorPositionExtractorTest · OrderByColumnsTest                [N]
├── TraceEquivalenceStabilityTest.php                 [N] el test más importante del sistema
└── Mother/CursorMother.php · TraceMother.php · PageMother.php        [N]
```

**PR2 — motor + repositorios (wire intacto, envelope viejo desde motor nuevo):**

```text
api/src/Shared/Infrastructure/Persistence/Doctrine/
├── Search/DoctrineSearchEngine.php                   [N] orquestador (K8, único query-shaper)
├── Search/FilterApplier.php                          [M] apply() devuelve AppliedFilters
├── AbstractDoctrineSearchRepository.php              [M] delega en el engine (puente interino)
└── AbstractDoctrineRepository.php                    [M] limpieza parcial de helpers muertos (FR11)

api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/
└── DoctrineBankRepository.php                        [M] composición: puertos + EM + engine (K9)
api/src/Backoffice/BankAccount/Infrastructure/Persistence/Doctrine/
└── DoctrineBankAccountRepository.php                 [M] composición (sin search: solo pierde la base)

api/tests/Functional/Shared/Persistence/
├── KeysetSqlSnapshotTest.php                         [N] SQL+binds+ordering (derivado NO normativo)
└── SortFieldMapIndexContractTest.php                 [N] sortable ⇒ índice compuesto (col, id)
```

**PR3 — el switch (único flip observable):**

```text
api/src/Shared/
├── Application/Http/Search/SearchQuery.php           [M] +after/+before excluyentes, -page; limit 25/100
├── Domain/Search/SearchCriteria.php                  [M] -page/-MAX_PAGE; +after/+before
├── Infrastructure/Http/Responder/PaginationMeta.php  [M] envelope K6 (hasNext/hasPrev/count/links)
├── Infrastructure/Http/Responder/SearchResponder.php [M] lee Page inmutable (fin del acoplamiento temporal)
└── Infrastructure/Http/LegacyPaginationValve.php     [N] #[When(env: dev|staging)] (K13)

api/tests/Unit/Shared/Application/Problem/MarkerStatusMapContractTest.php  [M]
api/features/backoffice/bank/search.feature           [M] simetría next×3/prev×3, empates, 422 invalid-cursor
docs/api-error-contract.md                            [M] fila invalid-cursor (NFR26)

pwa/src/context/shared/domain/Search/
├── Pagination.ts                                     [N] PageEnvelope, PaginationLinks (string | null)
└── index.ts                                          [M]
pwa/src/context/shared/infrastructure/Search/buildSearchParams.ts   [M] after/before
pwa/src/context/backoffice/bank/domain/BankRepository.ts            [M] HARD REMOVAL cursor/currentPage/hasMorePages
pwa/src/context/backoffice/bank/infrastructure/ApiBankRepository.ts [M] envelope nuevo
pwa/src/app/backoffice/banks/ + componentes de lista                [M] links.next/prev; barrido de adapters
```

**PR4 — borrado del legado (puramente sustractivo; PR3 revertible sin tocar PR4):**

```text
api/src/Shared/
├── Domain/Search/PaginatedResult.php · SearchCursor.php             [D]
├── Infrastructure/Persistence/Doctrine/Paginator.php                [D] Sonar S1448 muere aquí
├── Infrastructure/Persistence/PaginatorCursor.php
│   · PaginatorCursorInterface.php · PaginatorCursorFactory.php      [D]
├── Infrastructure/Persistence/Doctrine/QueryBuilderWithOptions.php
│   · PaginatorOption.php                                            [D]
├── Infrastructure/Persistence/Doctrine/AbstractDoctrineSearchRepository.php
│   · AbstractDoctrineRepository.php                                 [D] composición completa
└── Infrastructure/Http/LegacyPaginationValve.php                    [D] la válvula muere

docs/source-tree-analysis.md · docs/claude-code-quickref.md          [M] dir Keyset/, bases eliminadas
docs/architecture-api.md · docs/architecture-pwa.md · pwa/docs/      [M] receta y diagramas
```

### Architectural Boundaries

- **El engine es el único query-shaper**: controllers y use cases nunca
  tocan QueryBuilder/applier/codec; los repositorios solo aportan su query
  builder base con joins.
- **El trace se sella dentro del engine**: pre-validación de cursor,
  pre-compilación; post-sellado nada muta inputs del fingerprint.
- **Policies por consumidor**: `WirePaginationPolicy` hoy;
  `BatchIterationPolicy` se materializa con su consumidor (FR7) — kernel
  único, contratos separados, configuración explícita por policy.
- **PWA completamente opaca a cursores**: `links` tal cual; descarte por
  cambio de query como regla del cliente.

**Row Uniqueness Contract (cierre del paso 6 — segundo contrato del
sistema):** la corrección keyset no depende solo del trace; exige que la
query ejecutada produzca **cada fila lógica exactamente una vez, en un
orden total determinista**. El sistema es *trace + row-uniqueness
contract-driven*, no solo trace-driven. Amenazas reales con trace estable:
multiplicidad por fetch-join de colecciones to-many (duplicación
cartesiana en frontera), inyección implícita de DISTINCT por Doctrine,
semántica de NULL ordering por plataforma (mitigada por regla NOT-NULL
sortable), y multiplicidad join+paginación. Reglas: (a) el query builder
base de un repositorio de búsqueda NO hace fetch-join de colecciones
to-many en el read-path paginado (to-one sí; to-many → segunda query
batch); (b) orden total garantizado por columnas NOT NULL + tie-break
`id`; (c) el vector "row identity instability under stable trace" es la
**capa 3 y prioridad 1** de la validación del paso 7 — es el único fallo
que rompe keyset silenciosamente sin producir error alguno.

### Requirements to Structure Mapping

| FR/K | Ubicación física |
|---|---|
| FR1/K1, K2, K11 | `SearchQuery` + `SearchCriteria` (PR3) |
| FR2/K3, K4, FR15/K12 | `Keyset/{CursorCodec, FingerprintCanonicalizer, QueryExecutionTrace}` (PR1) |
| K5 | `Domain/Search/Exception/InvalidCursor` + `api-error-contract.md` (PR1/PR3) |
| FR6/K6 | `PaginationMeta` + `SearchResponder` (PR3) |
| FR8–FR9/K8–K9 | `DoctrineSearchEngine` + repositorios (PR2) |
| FR10 | `Page` + colaboradores `Keyset/` (PR1) + borrado `Paginator` (PR4) |
| FR11 | `AbstractDoctrineRepository` (PR2 parcial, PR4 total) |
| FR13/K15 | `KeysetPredicateBuilder` + ejecutor del engine |
| FR5/K14 | seam en el engine (cursor sintetizado) — sin endpoint nuevo en este alcance |
| FR7 | `BatchIterationPolicy` (diferido a su consumidor) |

### Integration Points

```text
query string
  → #[MapQueryString] SearchQuery       [QueryValidationLayer: shape]
  → toCriteria() → Searcher → repo (QB base con joins, sin to-many fetch-join)
  → DoctrineSearchEngine
      ├─ sort/filters/limit → recibos → QueryExecutionTrace (sellado)
      ├─ CursorValidationLayer (firma → versión → payload → fingerprint)
      ├─ KeysetPredicateBuilder (pre-compilación)
      └─ exec +1 (before: invertir + re-reverse)
  → Page inmutable → SearchResponder → envelope K6
```

Sin integraciones externas nuevas; Mercure/Messenger intactos.

### Development Workflow Integration

Un PR por fase en su worktree (`make worktree.create BRANCH=…`); gates por
PR según Enforcement Guidelines; PR2/PR3 actualizan los docs obligatorios
en el mismo PR (regla CLAUDE.md).

## Architecture Validation Results

> Ejecutada como **failure-mode simulation sobre PR3 en 3 capas**, con la
> capa 3 (result boundary drift) primero — el único vector que rompe
> keyset silenciosamente sin producir error.

### Failure-Mode Simulation (PR3 flip point)

**CAPA 3 — Result boundary drift (row identity instability under stable trace):**

| # | Vector simulado | Resultado | Refuerzo derivado |
|---|---|---|---|
| 3.1 | To-many fetch-join futuro (`leftJoin+addSelect` sobre colección): duplicación física → frontera dentro de una entidad → cursor salta hermanos | Rompía silenciosamente (la prohibición era documental) | **Guard runtime**: el engine inspecciona el QB al sellar el trace — join con `addSelect` sobre asociación to-many (`ClassMetadata`) → `LogicException` (programmer_error → critical) |
| 3.2 | DISTINCT implícito (el viejo DoctrinePaginator lo inyectaba) | Sin to-many joins no hay duplicados que ocultar | El engine jamás añade DISTINCT — la unicidad viene del contrato |
| 3.3 | Columna sortable nullable (NULL ordering por plataforma; predicado omite filas) | Regla NOT-NULL sin verificación | `SortFieldMapIndexContractTest` ampliado: asserta índice compuesto Y `nullable: false` vía ClassMetadata |
| 3.4 | Empates masivos + `before` (re-reverse) | Aguanta: tie-break `id` + simetría con fixture de empates | Cubierto |
| 3.5 | Mutación concurrente en frontera | Propiedad definida: FR14 + unicidad intra-página + affordance K10 | Cubierto |
| 3.6 | Microsegundos en memoria vs `TIMESTAMP(0)` | No alcanzable: cursor desde entidades hidratadas post-fetch | Nota en `CursorPositionExtractor` |

**CAPA 2 — Trace consistency:** drift de canonicalización → cubierto por
`TraceEquivalenceStabilityTest` + pin: numéricos serializados como strings
normalizados post-validación; productores equivalentes-distintos → 422 +
reinicio (asimetría conservadora, correcta); slot tenant = constante
pineada hasta Fase H (su cambio = bump de `v`); **bump de `v` olvidado =
riesgo residual humano** (runbook + review, no eliminable técnicamente).

**CAPA 1 — Cursor integrity (la mejor cubierta):** manipulación/payload/
versión/trasplante → 422 por causa; sobredimensionado → cap; válvula en
prod inalcanzable por construcción. **Acción pineada a PR3**: verificar
que `SearchExceptionListener` (priority 32) no intercepta `InvalidCursor`
antes que `ExceptionResponder` (16).

### Coherence Validation ✅

K1–K15 mutuamente compatibles y compatibles con el stack locked; 2
derogaciones del ADR de filtros explícitas y acotadas; el seam de filtros
se conserva como input. Sin dualidades: un kernel, un pipeline de errores,
una fuente semántica (trace) + un contrato de unicidad de filas.

### Requirements Coverage Validation ✅

FR1–FR15 trazados a ficheros (tabla del paso 6); NFRs cubiertos
(seguridad, NFR26, rendimiento con doble gate, calidad estructural,
pureza, multiempresa forward-looking); non-goals explícitos y vinculantes.

### Implementation Readiness Validation ✅

Decisiones con rationale y FQCNs exactos; árbol delta por PR sin
placeholders; patrones con ejemplos y anti-patterns; secuencia PR1–PR4
ejecutable sin ambigüedad operativa.

### Gap Analysis Results

- **Críticos:** ninguno.
- **Importantes (resueltos en esta validación):** guard runtime anti
  to-many fetch-join (3.1); assert NOT-NULL en contract test (3.3);
  serialización numérica canónica (2.1); verificación del listener legacy
  pineada a PR3.
- **Residuales aceptados:** disciplina de bump de `v` (humano, con
  runbook); affordance semantics dependiente de review (K10);
  coordinación de ejecución de PR3 (Risk Register, dominante).

### Architecture Completeness Checklist

**Requirements Analysis**

- [x] Project context thoroughly analyzed
- [x] Scale and complexity assessed
- [x] Technical constraints identified
- [x] Cross-cutting concerns mapped

**Architectural Decisions**

- [x] Critical decisions documented with versions
- [x] Technology stack fully specified
- [x] Integration patterns defined
- [x] Performance considerations addressed

**Implementation Patterns**

- [x] Naming conventions established
- [x] Structure patterns defined
- [x] Communication patterns specified
- [x] Process patterns documented

**Project Structure**

- [x] Complete directory structure defined
- [x] Component boundaries established
- [x] Integration points mapped
- [x] Requirements to structure mapping complete

### Architecture Readiness Assessment

**Overall Status:** READY FOR IMPLEMENTATION

**Confidence Level:** Alta — el modelo sobrevivió tres rondas de
elicitación adversarial (una corrigió al arquitecto: SQL como derivado no
normativo) y una simulación de fallos que encontró y cerró tres huecos
ejecutables.

**Key Strengths:**

- Invariantes convertidos en contratos verificables (trace stability,
  índices compuestos, unicidad de filas, identidad por construcción vía
  recibos).
- Fallos observables por diseño (422 por causa; programmer errors que
  despiertan on-call).
- Ningún estado de paginación implícito; ninguna degradación silenciosa.
- El riesgo restante es de ejecución, no de modelo.

**Areas for Future Enhancement:**

- `BatchIterationPolicy` con su consumidor real (FR7).
- Fase H: tenant real en fingerprint e índices.
- ADR de frontera transaccional (FR12).
- "Analysis-mode navigation" (trigger: cualquier petición de subir el
  techo wire).

### Implementation Handoff

**AI Agent Guidelines:**

- Seguir K1–K15, los patrones y los boundaries exactamente; ante duda,
  este documento manda; ante conflicto con CLAUDE.md/docs/rules, señalar
  el conflicto en vez de elegir.
- Respetar la secuencia PR1–PR4: **cualquier desviación del orden
  invalida la coherencia del modelo de validación** (regla formalizada en
  el cierre).

**First Implementation Priority:**

```bash
make worktree.create BRANCH=feat/api-keyset-pagination   # PR1
```

## Workflow Completion — Cierre formal (2026-06-10)

**Estado final: READY → IMPLEMENTATION LOCKED.**

**1. Freeze de arquitectura.** Queda fijado: kernel único de keyset
pagination · trace semántico como única fuente de verdad del cursor ·
fingerprint derivado exclusivamente del trace · SQL estrictamente no
normativo · envelope estable con semántica affordance-only · sistema dual
de policies (wire/batch) como extensión controlada. **No se admiten nuevas
decisiones estructurales sin reabrir el workflow completo.**

**2. Contrato de ejecución (PR discipline).** PR1 (kernel puro, sin
contrato) → PR2 (motor + repositorios, ejecución real) → PR3 (único switch
observable) → PR4 (eliminación del legado). La secuencia es la única vía
de materialización.

**3. Residual risk register (congelado).** Solo permanecen: disciplina de
versionado (governance del bump FR15) · coordinación de ejecución de PR3
(operativo, no arquitectónico) · evolución futura (Fase H, FR7, batch
analítico). Todo lo demás queda absorbido o eliminado por diseño.

**Lectura final:** el resultado es un sistema de navegación por estado
relacional determinista, donde la paginación deja de ser una técnica de UI
y pasa a ser una función verificable del estado de ejecución del query
pipeline. Su estabilidad no depende de convenciones de implementación,
sino de invariantes cerrados.
