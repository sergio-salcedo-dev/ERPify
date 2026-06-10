---
stepsCompleted: [1, 2, 3, 4]
status: 'complete'
completedAt: '2026-06-10'
inputDocuments:
  - '_bmad-output/planning-artifacts/architecture-keyset-pagination.md'
  - 'docs/saas-production-roadmap.md (Fase H — contexto tenant)'
---

# ERPify - Epic Breakdown

## Overview

This document provides the complete epic and story breakdown for ERPify, decomposing the requirements from the PRD, UX Design if it exists, and Architecture requirements into implementable stories.

**Nota de origen:** no existe PRD formal para este alcance (precedente del ADR de filtros 2026-06-06). La fuente única de requisitos es el ADR `architecture-keyset-pagination.md` (status: IMPLEMENTATION LOCKED, 2026-06-10), que embebe FRs, NFRs, non-goals, contrato de extremos keyset y secuencia de implementación vinculante PR1–PR4. Alcance: rediseño de la paginación a keyset puro (`after`/`before`) + reestructuración de repositorios de herencia a composición.

## Requirements Inventory

### Functional Requirements

**Bloque A — Paginación keyset pura (contrato público):**

- FR1: Contrato wire cursor-only — `limit` + cursores opacos; se eliminan `page`, `currentPage`, `pageCount` y `MAX_PAGE` del contrato. Navegación exclusivamente next/previous.
- FR2: Cursor corto firmado y ligado a su query — payload = valores de las claves de ordenación de la fila frontera + dirección + fingerprint `hash(tenant + entity + normalizedFilters + sort + direction + limit)`; `normalizedFilters` canónico sintáctico sobre `Filters` de dominio; slot de tenant reservado (constante hasta Fase H). Mismatch → 422 `invalid-cursor`, nunca degradación silenciosa. HMAC conservado; sin compresión zlib (~100–150 chars).
- FR3: Conteo bajo demanda — `PaginationMode` LIGHT (default, sin COUNT) / DETAILED (COUNT explícito) se conserva tal cual; `estimatedTotal` diferido.
- FR4: Ordenación estable — tie-break por `id` en todo ORDER BY; `SortDirection` enum de punta a punta.
- FR5: "Ir a fecha" como cursor sintetizado — el servidor fabrica una posición de cursor desde un valor de la clave de ordenación; se diseña el seam (UI = alcance posterior de la PWA).
- FR6: Wire envelope nuevo — `PaginationMeta` pasa a `{hasNext, hasPrev, count?, links: {next, prev}}` con shape constante (`links.next`/`links.prev` siempre presentes, `null` cuando no aplican). Breaking change asumido (único consumidor: la PWA propia).
- FR7: Exportaciones async — el mismo motor keyset alimenta workers de exportación vía Messenger (batches por cursor, nunca OFFSET); se diseña el seam, la feature es alcance futuro.
- FR13: Navegación direccional explícita `after`/`before` — cada página emite dos cursores independientes expuestos como `links.next` (`?after=…`) y `links.prev` (`?before=…`). El fetch de `before` invierte el ORDER BY en SQL y re-invierte en memoria, contenido en el ejecutor.
- FR14: Sin garantía de instantánea entre páginas (documentado) — la paginación navega sobre el estado actual del conjunto. Garantía que sí se da: sin duplicados ni saltos causados por la propia paginación, y unicidad de ids dentro de cada página.
- FR15: Versionado del formato de cursor — el payload firmado lleva `v` (schema version). Bump de `v` ⇒ todos los cursores anteriores → 422 `invalid-cursor`. La compatibilidad de cursores en vuelo es decisión explícita por release, observable en métricas.

**Bloque B — Reestructuración de repositorios (herencia → composición):**

- FR8: Motor de búsqueda inyectable — `DoctrineSearchEngine` extrae `getPaginatedResults`/heurística composite-PK; aplica siempre sort, limit y filtros; el repositorio solo aporta su query builder base con joins. Fuente de verdad única: el fingerprint deriva exclusivamente del `QueryExecutionTrace` (recibos de lo realmente aplicado), jamás re-parseando el wire.
- FR9: Repositorios sin base class de framework — implementan solo sus puertos de dominio; `EntityManagerInterface` inyectado. Desaparecen `ServiceEntityRepository`, `getEntityClassName()`, `QueryBuilderWithOptions` y `PaginatorOption` (→ `PaginatorConfig` readonly tipado).
- FR10: Descomposición del `Paginator` — `Cursor` y `Page<T>` inmutables; `paginate(): Page` explícito; colaboradores puros `CursorCodec`, `FingerprintCanonicalizer`, `KeysetPredicateBuilder`, `OrderByColumns`, `CursorPositionExtractor`. Resuelve Sonar S1448 estructuralmente y elimina las 3 supresiones PHPMD.
- FR11: Eliminación de código muerto — `addWhereInCaseInsensitive`, `addWhereBetweenDates`, `addWhereBetweenValues`, `sanitizeArray` y la doble llamada frágil de `generateUniqueParameterName`; preservar el why del naming estable de parámetros (caché SQL de Doctrine).
- FR12: Frontera transaccional → decisión separada, no bloqueante — este alcance no la resuelve; restricción vinculante: FR8–FR9 no deben cerrar la puerta (repos exponen `save()` sin flush implícito obligatorio en el contrato del puerto).

**Non-goals (exclusiones explícitas — tan vinculantes como los FRs):**

- Sin normalización semántica de filtros (canonicalizador sintáctico por diseño, no evolucionable sin ADR nuevo).
- Sin snapshot consistency (FR14).
- Sin abstracción de página (números de página fuera del sistema).
- Sin paginación híbrida (el modo legacy muere en PR4; válvula env-gated y temporal).
- Sin degradación silenciosa del cursor (toda invalidez es 422 observable).

### NonFunctional Requirements

- NFR1 (Seguridad): HMAC-SHA256 truncado 128 bits con `hash_equals`; cap de longitud pre-HMAC (512, `#[Assert]`); allow-lists de identificadores ORDER BY y parámetros bindeados intactos; fingerprint con slot de tenant; el cursor solo transporta valores de claves de ordenación de la fila frontera; nunca el cursor crudo en logs.
- NFR2 (Contrato de errores, NFR26 del repo): las cuatro causas de invalidez (firma, fingerprint, payload, versión) producen el mismo 422 `invalid-cursor` (familia `invalid-search-criteria`), indistinguibles para el cliente. Obliga a: fila en `docs/api-error-contract.md`, `MarkerStatusMapContractTest`, `make php.lint.error-contract` verde.
- NFR3 (Rendimiento): keyset O(1) por página independiente de la profundidad. Sortable ⇒ estabilidad de orden bajo igualdad del sort key. **Refinamiento 2026-06-10 (verificación de readiness):** el contract test prescribe la *propiedad*, no la forma física del índice — columna UNIQUE → su índice único de una columna la satisface (no hay empates posibles); columna sortable NO única → exige índice compuesto `(columna, id)`. Doble gate: (a) test de arquitectura en CI (propiedad por cada entrada de `sortFieldMap()` + `nullable: false`), (b) perf gate de staging con doble perfil (uniforme ~100k + sesgado skew 80/10). p95 del listado sin regresión.
- NFR4 (Calidad): resolución estructural (no supresión) de Sonar `php:S1448`; gates `make php.stan` + `make php.psalm` + `make php.quality` (PHPMD sin baseline). Regla de pureza de capa: colaboradores deterministas, readonly, sin estado interno; solo `DoctrineSearchEngine` toca Doctrine. Criterio de review: ¿este test necesita el kernel?
- NFR5 (Pureza de dominio): 0 dependencias nuevas en `Domain/`; el puerto evoluciona a `Page` sin imports de framework; los arrays `firstItem`/`lastItem` del `SearchCursor` desaparecen del puerto.
- NFR6 (Compatibilidad): cero migraciones de BD; cero dependencias Composer/npm nuevas. Breaking change del envelope coordinado con la PWA en el mismo ciclo (PR3); cursores en vuelo invalidados explícitamente (422 + `v`).
- NFR7 (Multiempresa forward-looking): el diseño reserva el slot de tenant en el fingerprint y la posición líder en los índices compuestos para que la llegada de `company_id` (Fase H del roadmap SaaS) no fuerce un segundo rediseño.

### Additional Requirements

**De las decisiones arquitectónicas (K1–K15) y patrones del ADR:**

- AR1 (K2): params `after=`/`before=` mutuamente excluyentes (ambos → 422 `validation-failed` en mapping). El param es el routing intent; el `dir` del payload es integrity binding only (discrepancia → 422 `invalid-cursor`).
- AR2 (K3): formato de cursor `base64url(json{v, dir, values, fp})` + `.` + HMAC truncado 32 hex; exactamente un punto separador.
- AR3 (K8 refinado): equivalencia por applied trace — recibos inmutables `AppliedFilters`/`AppliedSort`/`AppliedLimit` compuestos en `QueryExecutionTrace` sellado; fingerprint `xxh128(canonical(trace))`; cadena canónica `tenant|entity|filters|sort|direction|limit` derivada de los recibos.
- AR4 (Doctrine Boundary Contract): invariantes impuestos pre-compilación; el SQL compilado es derivado NO normativo — verificación de regresión en CI (`KeysetSqlSnapshotTest`: solo SQL string + binds + ordering), nunca compatibilidad runtime. **Principio explícito (2026-06-10): el sistema es correct-by-result, no correct-by-plan-stability** — el plan físico del planner (elección de índices, join order, decisiones cost-based por estadísticas) es derivado no normativo igual que el texto SQL: puede variar entre releases/entornos sin constituir regresión mientras la propiedad de orden se preserve. "Estabilizar el plan" jamás es un objetivo de corrección; un cambio de plan se evalúa en el perf gate (rendimiento), nunca en los gates semánticos.
- AR5 (Row Uniqueness Contract): el query builder base de un repositorio de búsqueda NO hace fetch-join de colecciones to-many en el read-path paginado (to-one sí; to-many → segunda query batch). Guard runtime en el engine: join con `addSelect` sobre asociación to-many → `LogicException` (programmer_error → critical). El engine jamás añade DISTINCT.
- AR6 (K10): semántica de affordance — `hasNext`/`hasPrev` afirman disponibilidad de enlace, no existencia garantizada de filas; heurística sin queries extra (trick +1 + derivación). Prohibido usarlos para inferir completitud del dataset. Enlace hacia hueco lógico → 200 `items: []`.
- AR7 (K11): `limit` default 25, techo 100 (wire). Gate semántico: el techo nunca se sube para casos analíticos. Afecta a `SearchQuery`, `SearchCriteria` y fixtures/Behat que asuman el default actual de 1000.
- AR8 (K13): válvula de transición `pagination_mode=legacy|cursor_v2` env-gated `dev`/`staging` (`#[When(env)]`), vida útil = ventana PR3→PR4, cero tests propios.
- AR9 (K14): "ir a fecha" = seam server-side que sintetiza posición de cursor desde un valor de clave de ordenación, misma maquinaria K3/K4, sin endpoint nuevo en este alcance.
- AR10 (Validación en DAG): capa 1 `QueryValidationLayer` (shape en mapping) → capa 2 `CursorValidationLayer` con orden intrínseco-primero: firma → versión → payload → fingerprint. Orden del pipeline del motor fijo (8 pasos, sellado del trace en el paso 4).
- AR11 (Kernel único + policies): `WirePaginationPolicy` hoy; `BatchIterationPolicy` se materializa con su consumidor (FR7); el `KeysetPredicateBuilder` recibe configuración explícita por policy — prohibida una segunda implementación de paginación.
- AR12 (FQCNs exactos pineados): `Erpify\Shared\Domain\Search\Page`, `…\Domain\Search\Exception\InvalidCursor`, `…\Infrastructure\Persistence\Doctrine\Search\DoctrineSearchEngine`, `…\Search\PaginatorConfig`, subnamespace `…\Search\Keyset\` para colaboradores, `…\Infrastructure\Http\Responder\PaginationMeta` (v2). No inventar variantes.
- AR13 (Tests pineados): `TraceEquivalenceStabilityTest` (el test más importante del sistema: mismo input ⇒ trace canónico byte-a-byte), `KeysetOrderStabilityPropertyTest` (gate **normativo** de la propiedad de estabilidad de orden — conductual, dataset + asserts, independiente del índice; ver Story 1.2), `SortFieldMapIndexContractTest` (forma del índice + NOT NULL vía ClassMetadata — gate de performance, subordinado a la propiedad), `KeysetSqlSnapshotTest` (derivado no normativo), simetría next×3/prev×3 con fixture de empates masivos en Behat `search.feature` (extender, no crear feature paralela); object mothers `CursorMother`/`PageMother`/`TraceMother`. Jerarquía: propiedad > forma > snapshot.
- AR14 (Observabilidad, PR3): métricas `invalid_cursor_count{cause}` (signature/version/payload/fingerprint), `cursor_version_distribution`, `next/prev_navigation_count`; dashboards actualizados en el mismo PR; runbook del pico post-deploy.
- AR15 (PWA, PR3): tipos en `pwa/src/context/shared/domain/Search/` (`PageEnvelope`, `PaginationLinks` con `string | null` no opcional, named exports); hard removal de `currentPage`/`pageCount` + barrido explícito de adapters/hooks ocultos; descarte client-side de ambos cursores al cambiar `sort`/`direction`/`filters`/`limit`; el cliente nunca decodifica cursores; sin librerías nuevas.
- AR16 (Secuencia vinculante; **revisada por D-1 2026-06-11**): PR1 (piezas puras, sin cambio de contrato) → PR2 (motor *off-wire*: engine + guard + migración + suites directas; **repos intactos**, wire intacto) → PR3 (el switch: envelope + **repos por composición delegando en el engine** + PWA + Behat + métricas + válvula — único flip observable) → PR4 (borrado del legado, puramente sustractivo; PR3 revertible sin tocar PR4). Cada PR en su worktree. Cualquier desviación del orden invalida el modelo de validación.
- AR17 (Verificaciones pineadas a PR concreto — estado tras la verificación de readiness 2026-06-10): PR2 añade los compuestos `(created_at, id)`/`(updated_at, id)` de Bank (verificado: faltan; las columnas UNIQUE ya satisfacen la propiedad — ver NFR3 refinado); la verificación del listener legacy queda **resuelta**: `SearchExceptionListener` fue retirado cuando `ProblemDetailsFactory` absorbió sus mappings — en PR3 basta confirmar que `InvalidCursor` fluye por `ExceptionResponder` (`PRIORITY = 16`); datetimes del cursor a precisión de columna (`TIMESTAMP(0)` ⇒ segundos, confirmado en DDL), UTC `Y-m-d\TH:i:sP`.
- AR18 (Docs obligatorios por PR, regla CLAUDE.md): `docs/architecture-api.md`, `api/docs/adding-endpoints.md`, `docs/api-error-contract.md` (marker), `docs/architecture-pwa.md` + `pwa/docs/`, `docs/source-tree-analysis.md` + `docs/claude-code-quickref.md` (PR4).
- AR19 (Brownfield, sin starter): implementación propia sobre la fundación existente (seam `FilterApplier`/`SearchFieldMap`/`SortFieldMap`, pipeline RFC 9457, `PaginationMode`, escenarios Behat de search — 52 bloques a 2026-06-10). Librerías candidatas evaluadas y descartadas. Primera acción: `make worktree.create BRANCH=feat/api-keyset-pagination`.
- AR20 (Prohibiciones operativas): no `OFFSET`/`setFirstResult` en el read-path; no transportar cursores en Messenger/Mercure; no catch de `InvalidCursor` fuera del pipeline RFC 9457; no `skip_null_values` en el responder; no aserciones sobre objetos Doctrine en tests del motor; no perseguir estabilidad del plan físico como proxy de corrección (correct-by-result, AR4).
- AR21 (Semantic Authority Rule — K2/K3, requisito independiente): el parámetro wire (`after`/`before`) es la **única autoridad semántica de dirección**. El campo `dir` del payload del cursor es exclusivamente *integrity binding* — se compara (discrepancia → 422 `invalid-cursor`) pero jamás puede utilizarse para decidir lógica de navegación, ni como fallback. Evita reintroducir una segunda fuente de verdad de dirección en el futuro.
- AR22 (QueryExecutionTrace as sole semantic source — corrección clave de la elicitación, requisito independiente): el cursor se valida **exclusivamente** contra una representación semántica estable (`QueryExecutionTrace`). Ningún artefacto derivado de compilación (SQL generado, aliases, ordering interno del ORM) forma parte del contrato de compatibilidad runtime. El criterio de bump de `v` es siempre "¿cambió la semántica observable?", jamás "¿cambió el texto SQL?".
- AR23 (Invariante de plataforma — ordenación determinista de columnas sortables; **SELLADO 2026-06-10, Opción A a alcance de columna**): regla dura — **columna de texto sortable ⇒ `COLLATE "C"` declarado en la propia columna**. La **fuente de verdad única es el esquema**: la columna transporta su semántica de ordenación; la imagen Docker, los args de initdb y el default del clúster dejan de ser relevantes para el contrato keyset (se elimina la triple-suposición imagen/initdb/clúster). Justificación del alcance de columna frente al pin de clúster: `POSTGRES_INITDB_ARGS` solo afecta a clústeres nuevos — los volúmenes dev existentes y la BD del VPS ya están inicializados, de modo que el pin de initdb fabricaría el propio drift que pretende evitar; la collation por columna viaja con la migración a todos los entornos y sobrevive a una futura BD gestionada. Es semánticamente correcto: `name_normalized` y `short_name` son claves de ordenación ASCII-normalizadas por construcción — el orden byte-wise ES su orden definido. Enforcement: `SortFieldMapIndexContractTest` asserta la collation declarada de toda columna de texto sortable; la migración de infraestructura de PR2 aplica el `COLLATE "C"`. **(a) `id`**: PK UUID, comparación byte-wise determinista, inmutable — ya determinista por contrato. El pin de initdb a nivel de clúster queda como defensa en profundidad opcional y explícitamente NO-fuente-de-verdad.
- AR24 (Gobernanza del `QueryExecutionTrace` — el verdadero API de paginación; añadido 2026-06-10): el sistema depende de que el trace capture **completamente el espacio de decisión del orden** — es el precio correcto de haberlo hecho fuente única (AR22). **Regla de completitud**: toda futura dimensión que influya en el orden o la visibilidad del result set (política de orden multi-tenant — slot ya reservado para Fase H —, visibilidad de soft-deleted, sesgo de sharding key, scoping por permisos) DEBE entrar en el trace como recibo (→ cadena canónica → fingerprint), o quedará fuera del fingerprint y producirá cursores criptográficamente válidos sobre semántica distinta — exactamente el fallo silencioso que el sistema existe para impedir. **Criterio de review obligatorio** para cualquier PR que toque semántica de búsqueda/orden/visibilidad: *"¿esta dimensión entra en el `QueryExecutionTrace`? ¿exige bump de `v`?"* — la evolución del trace tiene la disciplina de un cambio de API pública (FR15: bump explícito por release; `TraceEquivalenceStabilityTest` como gate; runbook AR14). Control organizativo, no técnico: ningún test puede detectar una dimensión que nunca entró al trace.

### UX Design Requirements

N/A — no existe documento de UX Design para este alcance. El cambio es de contrato API + adaptación PWA (tipos y componentes de lista); la UI de "ir a fecha" queda explícitamente como alcance posterior (decisión diferida del ADR).

### FR Coverage Map

| FR | Épica | Fase de materialización |
|---|---|---|
| FR1 (contrato cursor-only) | Epic 1 | PR3 (Story 1.3) |
| FR2 (cursor firmado + fingerprint) | Epic 1 | PR1 piezas (Story 1.1) → PR3 activación (Story 1.3) |
| FR3 (LIGHT/DETAILED conservado) | Epic 1 | PR2–PR3 (verificación, sin cambio) |
| FR4 (ordenación estable, tie-break `id`) | Epic 1 | PR1–PR2 (`OrderByColumns`, engine) |
| FR5 (seam "ir a fecha") | Epic 1 | PR1 piezas (Story 1.1) → PR3 verificación del seam (Story 1.3); UI diferida |
| FR6 (envelope nuevo) | Epic 1 | PR3 (Stories 1.3–1.4) |
| FR7 (seam exportaciones async) | Epic 1 | PR1–PR2 (policy seam; feature diferida) |
| FR8 (DoctrineSearchEngine + trace) | Epic 1 | PR2 (Story 1.2) |
| FR9 (repos por composición) | Epic 1 | **PR3 (Story 1.3)** — trasladado desde PR2 por D-1 (PR2 = engine off-wire; la composición solo tiene sentido cuando el engine entra al runtime path) |
| FR10 (descomposición Paginator) | Epic 1 | PR1 piezas (Story 1.1) → PR4 muerte del viejo (Story 1.5) |
| FR11 (código muerto) | Epic 1 | **PR3 (Story 1.3)** parcial (era PR2, trasladado por D-1) → PR4 total (Story 1.5) |
| FR12 (frontera transaccional no bloqueada) | Epic 1 | **PR3 (Story 1.3)** — `save()` sin flush implícito acompaña a la composición (era PR2, trasladado por D-1) |
| FR13 (after/before direccionales) | Epic 1 | PR1 piezas (Story 1.1) → PR3 wire (Story 1.3) |
| FR14 (sin snapshot, documentado) | Epic 1 | PR3 (docs + Behat, Stories 1.3–1.4) |
| FR15 (versionado `v` del cursor) | Epic 1 | PR1 (Story 1.1) |

Non-goals, NFR1–NFR7 y AR1–AR22: transversales a Epic 1; cada historia hereda los que tocan sus ficheros (se detalla en los acceptance criteria por historia).

## Epic List

### Epic 1: Sustitución completa del modelo de paginación por navegación cursor-only

El consumidor del API (la PWA y sus usuarios de backoffice) navega cualquier listado filtrado/ordenado mediante enlaces `next`/`prev` autocontenidos con cursores opacos firmados — con latencia O(1) independiente de la profundidad, conteo bajo demanda, errores de cursor siempre observables (422 `invalid-cursor`) — y desaparece por completo el paradigma de páginas numeradas: del contrato wire, de los tipos de la PWA y del código (legado de herencia en repositorios incluido). Los seams de "ir a fecha" y exportaciones async quedan diseñados y listos para sus futuros consumidores.

**FRs covered:** FR1–FR15 (todos), non-goals vinculantes, NFR1–NFR7, AR1–AR22.

**Estructura interna:** 5 historias alineadas exactamente a la secuencia vinculante PR1–PR4 del ADR (1.1 = PR1 kernel puro · 1.2 = PR2 engine off-wire + guard + migración + suites directas · 1.3 = PR3 API flip del contrato **+ repos por composición** · 1.4 = PR3 PWA + Behat + observabilidad · 1.5 = PR4 limpieza final). Las historias 1.3 y 1.4 se coordinan en el mismo worktree/PR (breaking change sincronizado API↔PWA del mismo ciclo).

## Epic 1: Sustitución completa del modelo de paginación por navegación cursor-only

El consumidor del API (la PWA y sus usuarios de backoffice) navega cualquier listado filtrado/ordenado mediante enlaces `next`/`prev` autocontenidos con cursores opacos firmados — con latencia O(1) independiente de la profundidad, conteo bajo demanda y errores de cursor siempre observables (422 `invalid-cursor`) — y desaparece por completo el paradigma de páginas numeradas: del contrato wire, de los tipos de la PWA y del código (legado de herencia en repositorios incluido).

### Story 1.1: Kernel keyset puro — VOs, colaboradores deterministas y suites unitarias (PR1)

As a desarrollador de ERPify,
I want el kernel keyset completo como piezas puras verificadas (VOs inmutables + colaboradores deterministas + suites unitarias sin kernel), sin ningún cambio de contrato,
So that el flip posterior del contrato (PR3) se apoye exclusivamente en componentes ya probados y el riesgo quede confinado a la integración, no al mecanismo.

**Acceptance Criteria:**

**Given** el monorepo en un worktree nuevo (`make worktree.create BRANCH=feat/api-keyset-pagination`)
**When** la historia se completa
**Then** existen exactamente los FQCNs pineados en AR12: `Erpify\Shared\Domain\Search\Page` (final readonly, `@template T`), `Erpify\Shared\Domain\Search\Exception\InvalidCursor`, `…\Infrastructure\Persistence\Doctrine\Search\PaginatorConfig` y el subnamespace `…\Search\Keyset\` con `Cursor`, `CursorCodec`, `QueryExecutionTrace`, `AppliedFilters`, `AppliedSort`, `AppliedLimit`, `FingerprintCanonicalizer`, `KeysetPredicateBuilder`, `OrderByColumns`, `CursorPositionExtractor`, `WirePaginationPolicy` — sin inventar variantes
**And** `Domain/` no gana ninguna dependencia nueva (NFR5); todos los colaboradores son readonly, deterministas, sin estado interno ni servicios instanciados dentro (NFR4).

**Given** un cursor codificado por `CursorCodec`
**When** se decodifica
**Then** el round-trip es exacto y el formato wire es `base64url(json{v, dir, values, fp})` + `.` + HMAC-SHA256 truncado a 32 hex, con exactamente un punto separador (AR2/K3)
**And** la verificación de firma usa `hash_equals` y existe cap de longitud 512 pre-HMAC (NFR1)
**And** los datetimes de `values` se serializan en UTC `Y-m-d\TH:i:sP` a precisión de columna (`TIMESTAMP(0)` ⇒ segundos), con round-trip exacto cursor↔SQL (AR17).

**Given** un cursor con firma inválida, versión desconocida, payload corrupto o fingerprint discrepante
**When** se valida en orden intrínseco-primero (firma → versión → payload → fingerprint, AR10)
**Then** se lanza `InvalidCursor` con la causa correspondiente (`signature`/`version`/`payload`/`fingerprint`) y las cuatro causas comparten el type `invalid-cursor` (K5, NFR2)
**And** el payload lleva `v: 1` y el campo `dir` se compara como integrity binding pero jamás decide lógica de navegación (AR21, FR15/K12).

**Given** el mismo input (criteria + field maps + entity)
**When** se construye el `QueryExecutionTrace` en ejecuciones repetidas
**Then** el `TraceEquivalenceStabilityTest` verifica representación canónica byte-a-byte idéntica (AR13 — el test más importante del sistema)
**And** la cadena canónica es `tenant|entity|filters|sort|direction|limit` derivada de los recibos (jamás del input), con filtros ordenados por (field, operator, valor serializado), listas IN ordenadas y `fp = xxh128(canonical(trace))` (AR3/AR22)
**And** el slot de tenant es la constante pineada (NFR7).

**Given** columnas de ORDER BY con tie-break `id` y una posición de cursor
**When** `KeysetPredicateBuilder` construye el predicado
**Then** produce la cadena `col > :v OR (col = :v AND id > :i)` (extendida a N claves) con parámetros bindeados, pre-compilación (FR4, AR4)
**And** el builder recibe configuración explícita de `WirePaginationPolicy` — nunca se comparte sin contexto entre policies (AR11).

**Given** la suite completa del repo
**When** corre CI
**Then** los escenarios Behat existentes de `search.feature` (52 bloques a 2026-06-10: 47 Scenario + 5 Outline) pasan sin modificación alguna (cero cambio de contrato wire)
**And** las suites unitarias nuevas viven en `api/tests/Unit/…/Keyset/` sin necesitar el kernel, con object mothers `CursorMother`/`TraceMother`/`PageMother` (AR13)
**And** `make php.stan`, `make php.psalm` y `make php.quality` quedan verdes.

### Story 1.2: Motor de búsqueda keyset inyectable OFF-WIRE — engine + guard + migración + suites directas (PR2)

> **D-1 (2026-06-11):** los repositorios por composición se trasladaron a la Story 1.3 (PR3). Esta historia entrega solo el engine off-wire; los repos quedan intactos.

As a desarrollador de ERPify,
I want que `DoctrineSearchEngine` exista como engine keyset de especificación — único query-shaper del read-path, gobernado por el trace sellado y el Row Uniqueness Contract, verificado por tests directos (property/snapshot/contract) y NO conectado al wire (el `Paginator` legacy sigue sirviendo el HTTP),
So that la corrección de la ejecución keyset quede sellada y demostrada antes del flip observable de PR3, sin exponer ningún cambio al consumidor en esta historia.

**Acceptance Criteria:**

**Given** una búsqueda paginada de Bank
**When** el engine la ejecuta
**Then** sigue el pipeline fijo de 8 pasos (sort→`AppliedSort`, filtros→`AppliedFilters`, limit→`AppliedLimit`, sellado del trace + fingerprint, validación de cursor, predicado keyset, exec con trick +1, `Page` inmutable) con invariantes impuestos hasta el paso 6 (AR10)
**And** `FilterApplier.apply()` devuelve `AppliedFilters` y el fingerprint deriva exclusivamente del trace sellado — ningún output de la capa de validación muta post-fingerprint (FR8, AR3, AR22)
**And** el fetch de `before` invierte el ORDER BY en SQL y re-invierte en memoria, contenido en el ejecutor (FR13/K15).

**Given** un query builder base cuyo join con `addSelect` apunta a una asociación to-many — definida como asociación con `ClassMetadata::isCollectionValuedAssociation()` true (OneToMany / ManyToMany según el mapping de Doctrine), nunca por heurística de nombres
**When** el engine inspecciona el QueryBuilder **en el momento de sellar el trace** (paso 4 del pipeline — antes de validar el cursor y antes de cualquier compilación)
**Then** lanza `LogicException` clasificada como programmer error (→ `exception_category` critical, despierta on-call) — guard runtime del Row Uniqueness Contract (AR5)
**And** el fallo NUNCA es `InvalidCursor` ni ningún 422: es un bug del repositorio, no un error del cliente — prohibido que el pipeline RFC 9457 lo degrade a error de petición
**And** los joins to-one con `addSelect` están permitidos; las colecciones to-many se cargan por segunda query batch fuera del read-path paginado
**And** el engine jamás añade DISTINCT
**And** controllers y use cases nunca tocan QueryBuilder/applier/codec: los repositorios solo aportan su query builder base con joins.

**Given** `DoctrineBankRepository` y `DoctrineBankAccountRepository`
**When** la historia se completa
**Then** implementan solo sus puertos de dominio con `EntityManagerInterface` inyectado — sin `ServiceEntityRepository`, sin `getEntityClassName()`, sin `QueryBuilderWithOptions`, sin `PaginatorOption` (FR9/K9)
**And** el contrato del puerto expone `save()` sin flush implícito obligatorio (FR12 — puerta abierta)
**And** los helpers muertos accesibles se eliminan de `AbstractDoctrineRepository` preservando el comentario del naming estable de parámetros (FR11 parcial).

**Given** cada entrada de `sortFieldMap()` de un repositorio de búsqueda
**When** corre `SortFieldMapIndexContractTest` en CI
**Then** asserta vía ClassMetadata la **propiedad de estabilidad de orden bajo igualdad del sort key, no la forma física del índice**: columna UNIQUE → su índice único de una columna satisface la regla; columna sortable no única → exige índice compuesto `(columna, id)`; en ambos casos `nullable: false`; y toda columna de texto sortable declara `COLLATE "C"` (NFR3 refinado, AR13, AR23)
**And** se añaden `(created_at, id)` y `(updated_at, id)` en Bank como índices secundarios — los simples existentes (`idx_bank_created_at`/`idx_bank_updated_at`) se conservan — y se aplica `COLLATE "C"` a `name_normalized` y `short_name`, todo en una única migración de infraestructura (verificación 2026-06-10: los compuestos faltan; las UNIQUE satisfacen la forma pero requieren el pin de collation)
**And** esa migración es evolución de infraestructura (índices + determinismo de ordenación), no contractual: no cambia esquema lógico, entidad ni semántica de dominio, por lo que **no reabre el pin "cero migraciones" de NFR6** (su alcance es cero migraciones funcionales/estructurales de dominio).

**Given** un dataset Bank sembrado para máxima adversidad de empates: ~50 filas insertadas en orden físico aleatorio respecto a sus ids (heap order ≠ orden lógico — elimina el falso verde por correlación con el orden de inserción), con perfil sesgado espejo del perf gate (~80% de filas en un único grupo de empate de `created_at`/`updated_at` a precisión de segundo, generadas desde datetimes PHP que difieren solo en microsegundos; resto disperso) y valores de texto de alfabeto seguro `[a-z0-9]` (el orden binario en memoria coincide con la collation)
**When** corre `KeysetOrderStabilityPropertyTest` (funcional, Postgres real, en `api/tests/Functional/Shared/Persistence/`) contra `DoctrineSearchEngine` directamente — sin HTTP, el wire de PR2 sigue intacto — por cada entrada de `sortFieldMap()` × ASC/DESC, con `limit` menor que el grupo de empate dominante para que cada frontera de página caiga *dentro* de un empate
**Then** valida la propiedad de estabilidad **solo con dataset + asserts, sin consultar índices, ClassMetadata ni planes de ejecución**:
  1. **Oráculo**: un full-scan único con `ORDER BY (col, id)` explícito produce la secuencia de referencia — contiene cada id sembrado exactamente una vez, re-ejecutado es byte-idéntico (determinismo) y coincide con la ordenación total en memoria por `(valor, id)` (totalidad del comparador)
  2. **Partición exacta**: la caminata completa con cursores `after` hasta agotar el dataset reconstruye la secuencia oráculo exactamente — sin duplicados, sin huecos, longitud N (aquí rompen conductualmente: multiplicidad por fetch-join, omisión por NULL y pérdida del tie-break `id`)
  3. **Frontera intra-empate** (el enunciado literal de la propiedad): para cada fila del grupo de empate dominante usada como frontera, el cursor extraído de esa fila reanuda exactamente en la fila siguiente del oráculo
  4. **Simetría**: la caminata inversa con `before` desde el final devuelve la secuencia oráculo invertida exacta
  5. **Precisión**: los datetimes que difieren solo en microsegundos forman un único grupo de empate y el round-trip cursor↔SQL es exacto a segundos (AR17); en las columnas UNIQUE (`name`/`shortName`) los grupos degeneran a tamaño 1 y la propiedad se valida trivialmente con el mismo recorrido
**And** el test pasa idéntico con o sin los índices compuestos presentes (ejecutable antes de la migración de índices): la propiedad es de corrección y no depende de la forma física — este es el gate normativo; `SortFieldMapIndexContractTest` (forma) y el perf gate de staging (robustez del plan) quedan subordinados a él (AR13: propiedad > forma > snapshot)
**And** las infra-asunciones del oráculo quedan **selladas por contrato, no documentadas**: `id` PK UUID byte-wise (determinista por contrato) y collation `COLLATE "C"` declarada en las columnas de texto sortables (AR23, aplicada en la migración de esta historia y assertada por `SortFieldMapIndexContractTest`) — con el pin de columna, el oráculo en memoria es exacto por contrato y el alfabeto seguro del dataset pasa de necesidad a redundancia defensiva
**And** la validez del test es correct-by-result (AR4): un cambio de plan físico del planner que preserve la propiedad no es regresión y no debe perseguirse su estabilidad.

**Given** el SQL compilado del read-path
**When** corre `KeysetSqlSnapshotTest`
**Then** compara únicamente SQL string + parámetros bindeados + ordering — nunca objetos Doctrine (AR4, AR20)
**And** el snapshot es derivado NO normativo: detector de regresiones, jamás contrato de compatibilidad runtime (AR22).

**Given** la suite Behat completa
**When** corre CI
**Then** los escenarios Behat existentes (52 bloques) pasan sin modificación: el envelope viejo se emite desde el motor nuevo (wire intacto)
**And** `make php.stan` + `make php.psalm` + `make php.quality` verdes; docs obligatorios del PR actualizados (AR18).

### Story 1.3: Flip del contrato API — wire cursor-only con envelope nuevo + repos por composición (PR3, lado API)

> **D-1 (2026-06-11):** la reestructuración de repositorios de herencia a composición (FR9, FR11 parcial, FR12) se trasladó aquí desde PR2 — es la pieza que conecta el `DoctrineSearchEngine` (ya entregado off-wire en PR2) al runtime path, así que solo cobra sentido en el flip observable de PR3.

As a consumidor del API de listados (la PWA),
I want navegar `GET /api/v1/backoffice/banks` exclusivamente con `limit` + `after`/`before` opacos y recibir el envelope `{hasNext, hasPrev, count?, links}`,
So that la navegación sea O(1) a cualquier profundidad, los enlaces sean autocontenidos y toda invalidez de cursor sea observable — sin rastro de páginas numeradas.

**Acceptance Criteria:**

**Given** `DoctrineBankRepository` y `DoctrineBankAccountRepository` (hoy por herencia + `Paginator` legacy)
**When** se completa el flip de PR3
**Then** implementan solo sus puertos de dominio con `EntityManagerInterface` inyectado — sin `ServiceEntityRepository`, sin `getEntityClassName()`, sin `QueryBuilderWithOptions`, sin `PaginatorOption` — y delegan el read-path paginado en el `DoctrineSearchEngine` de PR2 (FR9/K9)
**And** el contrato del puerto expone `save()` sin flush implícito obligatorio (FR12 — puerta abierta), y los helpers muertos accesibles se eliminan de `AbstractDoctrineRepository` preservando el comentario del naming estable de parámetros (FR11 parcial)
**And** el `Paginator` legacy deja de servir el read-path de Bank/BankAccount (su borrado total es PR4/Story 1.5) — este es el único punto donde el engine nuevo pasa a gobernar la ejecución real.

**Given** una petición con `filters[]`/`sort`/`direction` válidos y sin cursor
**When** se ejecuta
**Then** responde 200 con `pagination: {hasNext, hasPrev, count, links: {next, prev}}` de shape constante — `links.next`/`links.prev` siempre presentes, `null` cuando no aplican, prohibido `skip_null_values` (FR6/K6, AR20)
**And** los `links` son URLs relativas al mismo endpoint preservando los query params vigentes y sustituyendo solo `after`/`before`
**And** `page`, `currentPage`, `pageCount` y `MAX_PAGE` desaparecen de `SearchQuery`/`SearchCriteria`/`PaginationMeta` (FR1)
**And** `limit` tiene default 25 y techo 100; `limit` ∉ [1,100] → 422 `validation-failed` (AR7/K11); fixtures/Behat que asumían 1000 se actualizan.

**Given** una petición con `after` y `before` simultáneos
**When** se mapea el DTO
**Then** responde 422 `validation-failed` en mapping — capa 1 del DAG (AR1/K2, AR10)
**And** la dirección de navegación la decide exclusivamente el parámetro wire; un `dir` discrepante en el payload → 422 `invalid-cursor` (AR21).

**Given** un cursor inválido por cualquiera de las cuatro causas
**When** se valida
**Then** responde 422 `invalid-cursor` (familia `invalid-search-criteria`) indistinguible para el cliente, vía pipeline RFC 9457 — cero `JsonResponse` manual (NFR2)
**And** existe la fila en `docs/api-error-contract.md`, `MarkerStatusMapContractTest` actualizado y `make php.lint.error-contract` verde
**And** se confirma que `InvalidCursor` fluye por `ExceptionResponder` (`PRIORITY = 16`) — el `SearchExceptionListener` legacy ya fue retirado (AR17 resuelto en la verificación de readiness)
**And** `InvalidCursor` se loguea con `cause` en contexto, nunca el cursor crudo (NFR1).

**Given** navegación `after` hacia un hueco lógico (filas borradas) o fin del dataset
**When** se ejecuta
**Then** responde 200 con `items: []`, flags de affordance coherentes — nunca error (AR6/K10)
**And** `hasNext`/`hasPrev` se computan con trick +1 en la dirección navegada y derivación en la contraria, sin queries extra
**And** en modo DETAILED `count` llega poblado; en LIGHT, `count: null` (FR3).

**Given** el seam de "ir a fecha"
**When** el servidor sintetiza una posición de cursor desde un valor de clave de ordenación
**Then** usa la misma maquinaria K3/K4 sin endpoint nuevo, con `hasPrev: true` conservador (FR5/K14, AR9).

**Given** el entorno `dev` o `staging`
**When** se activa la válvula `pagination_mode=legacy|cursor_v2`
**Then** permite emitir el envelope viejo — `#[When(env)]`, inalcanzable en prod por construcción, sin tests propios (AR8/K13)
**And** `make php.stan` + `make php.psalm` + `make php.quality` verdes; docs API obligatorios actualizados (AR18), incluyendo documentar explícitamente la no-garantía de instantánea entre páginas y la garantía que sí se da — sin duplicados ni saltos causados por la propia paginación, unicidad de ids intra-página (FR14: el "documentado" es parte del requisito).

**Given** la rama con PR1 y PR2 ya integrados en `main`
**When** se revierte el merge de PR3 (junto con Story 1.4, mismo PR)
**Then** el comportamiento wire previo (envelope legacy, params de página) queda completamente restaurado sin requerir revertir PR1 ni PR2 — la activación del contrato cursor-only está íntegramente encapsulada en los cambios de PR3 (AR16)
**And** ningún cambio de PR3 modifica piezas de PR1/PR2 de forma que el revert las rompa (el kernel y el engine siguen compilando y pasando sus suites con PR3 revertido)
**And** esta propiedad es un criterio de review del PR: cualquier cambio que la rompa invalida la estrategia de rollout y debe rediseñarse antes del merge.

### Story 1.4: Migración PWA, red Behat y observabilidad del switch (PR3, lado consumidor)

As a usuaria del backoffice de ERPify,
I want que las listas (bancos) naveguen con los enlaces `next`/`prev` del envelope nuevo, con cobertura e2e de simetría y métricas de cursor en producción,
So that el breaking change llegue sincronizado, verificable y observable en el mismo ciclo — sin capas de mapeo legacy ocultas.

**Acceptance Criteria:**

**Given** `pwa/src/context/shared/domain/Search/`
**When** la historia se completa
**Then** existen `PageEnvelope` y `PaginationLinks` con `links: { next: string | null; prev: string | null }` — `string | null` no opcional, named exports (AR15)
**And** `currentPage`/`cursor`/`hasMorePages` sufren hard removal de `BankRepository.ts` y tipos compartidos, con barrido explícito de adapters/hooks/boundary functions ocultos — se eliminan, no se adaptan
**And** no existe ningún helper tipo `getPageNumber(envelope)` (anti-example del ADR); cero librerías nuevas
**And** el control de paginación de las listas sustituye al paginador numerado por navegación `next`/`prev` reutilizando los patrones visuales existentes del toolbar de listas — un enlace `null` se renderiza como control deshabilitado, nunca oculto; cualquier rediseño visual mayor del control se difiere a la fase C del overhaul UI/UX del backoffice (sin spec UX en este alcance)
**And** la compilación TS estricta y `make pwa.quality` + Vitest quedan verdes.

**Given** un cambio de `sort`, `direction`, `filters` o `limit` en una lista
**When** el cliente reconstruye la query
**Then** descarta ambos cursores (defensa en profundidad sobre el fingerprint — la UX no depende del 422)
**And** el cliente usa `links` tal cual y jamás decodifica ni fabrica cursores (AR15, AR20)
**And** `buildSearchParams.ts` soporta `after`/`before` mutuamente excluyentes.

**Given** un dataset con empates masivos en la clave de ordenación
**When** corre el escenario Behat de simetría (extendiendo `search.feature`, no una feature paralela)
**Then** `next`×3 seguido de `prev`×3 devuelve ids idénticos en orden inverso exacto (AR13)
**And** los escenarios existentes (52 bloques a 2026-06-10 — la cifra "29" del ADR quedó desactualizada; el esfuerzo real es mayor) quedan actualizados al envelope nuevo y verdes dentro de este PR — si Behat pasa aquí, PR4 es puramente sustractivo
**And** hay escenario de 422 `invalid-cursor` y de página vacía → 200.

**Given** el deploy del switch
**When** se publican las métricas
**Then** existen `invalid_cursor_count{cause}` con `cause ∈ {signature, version, payload, fingerprint}`, `cursor_version_distribution`, `next_navigation_count` y `prev_navigation_count` (AR14)
**And** los dashboards se actualizan en el mismo PR y el runbook documenta: pico de `invalid_cursor_count{cause=version|fingerprint}` post-deploy = bug de encoding o bump esperado — verificar el bump, no rotar secretos
**And** los docs PWA obligatorios (`docs/architecture-pwa.md`, `pwa/docs/`) se actualizan (AR18).

### Story 1.5: Borrado del legado de paginación (PR4)

As a desarrollador de ERPify,
I want eliminar todo el aparato legacy de paginación (Paginator, cursores mutables, bases abstractas, válvula) sin ningún cambio de comportamiento,
So that quede un único kernel keyset, muera Sonar S1448 estructuralmente y el modelo de páginas numeradas desaparezca también del código.

**Acceptance Criteria:**

**Given** el árbol `api/src/Shared/`
**When** la historia se completa
**Then** se eliminan `Paginator.php`, `PaginatorCursor.php`, `PaginatorCursorInterface.php`, `PaginatorCursorFactory.php`, `QueryBuilderWithOptions.php`, `PaginatorOption.php`, `PaginatedResult.php`, `SearchCursor.php`, `AbstractDoctrineSearchRepository.php`, `AbstractDoctrineRepository.php` y `LegacyPaginationValve.php` (FR10/FR11 total, AR8)
**And** no queda ninguna referencia a los símbolos borrados (grep limpio + compilación verde)
**And** el código muerto restante de FR11 desaparece, preservando documentado el why del naming estable de parámetros.

**Given** el quality gate
**When** corre el análisis
**Then** Sonar `php:S1448` queda resuelto estructuralmente (no suprimido) y las 3 supresiones PHPMD asociadas se eliminan (NFR4)
**And** `make php.stan` + `make php.psalm` + `make php.quality` verdes sin baselines nuevas.

**Given** la suite completa
**When** corre CI
**Then** los escenarios Behat (ya migrados en Story 1.4) pasan sin modificación — el PR es puramente sustractivo y PR3 sería revertible sin tocar este PR (AR16)
**And** `docs/source-tree-analysis.md`, `docs/claude-code-quickref.md`, `docs/architecture-api.md`, `docs/architecture-pwa.md` y `pwa/docs/` reflejan el directorio `Keyset/`, las bases eliminadas y la receta nueva (AR18).
