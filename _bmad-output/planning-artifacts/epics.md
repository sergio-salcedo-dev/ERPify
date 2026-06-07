---
stepsCompleted: [1, 2, 3, 4]
status: 'complete'
completedAt: '2026-06-07'
inputDocuments:
  - '_bmad-output/planning-artifacts/architecture.md'
  - '_bmad-output/planning-artifacts/research/technical-php-criteria-vs-searchcriteria-erpify-research-2026-06-06.md'
  - 'docs/project-context.md'
---

# ERPify - Epic Breakdown

## Overview

Este documento contiene el desglose completo de épicas e historias para ERPify — mecanismo compartido de filtros de búsqueda (convergencia SearchCriteria, opción C del research) — descomponiendo los requisitos del research técnico (que actúa como PRD) y del Architecture Decision Document en historias implementables.

## Requirements Inventory

### Functional Requirements

- FR1: Vocabulario de filtros en dominio — `Shared/Domain/Search` gana `Filter`, `FilterOperator` (enum backed: `eq`, `in`, `contains`) y `Filters`; `SearchCriteria` los transporta de forma retrocompatible (named args, default vacío). Solo operadores con consumidor real; el enum es el punto de extensión documentado.
- FR2: Contrato HTTP genérico validado — `filters[N][field/operator/value]` modelado como DTOs anidados (`FilterQuery[]`) vía `#[MapQueryString]` + `#[Assert]`, validación en mapping (4xx Problem Details, nunca `ValueError`/500).
- FR3: Applier QueryBuilder con allow-list obligatoria — `Shared/Infrastructure` gana `FilterApplier` sobre QueryBuilder con `SearchFieldMap` por repositorio como parámetro requerido (campo público → path DQL + normalizador opcional). Issue #2 upstream irrelevante por construcción.
- FR4: Retrocompatibilidad Bank (Parallel Change) — `names[]`/`ids[]` conviven con `filters[]` y mapean internamente a `Filters` (camino único); el contrato de respuesta (items + pagination.cursor/hasMorePages) no cambia. **[OBSOLETO en su mitad legacy — decisión de usuario 2026-06-07: ver nota en Story 1.5; el contrato de respuesta sí permanece intacto.]**
- FR5: Error de búsqueda inválida — campo desconocido/no filtrable u operador no permitido → excepción de dominio (`UnknownSearchField` / `UnsupportedSearchOperator`, marker `InvalidSearchCriteria`) mapeada a 400 en el pipeline RFC 9457.
- FR6: Cliente PWA (fase 2) — builder tipado de query params en `pwa/src/context/shared` (espejo TS de Criteria: `Filter`/`FilterOperator` como union + const); banks pasa de filtrado client-side a server-driven; cursor siempre opaco y descartado al cambiar cualquier filtro.
- FR7: Generalización (fase 3) — nueva lista filtrable = ≤ 2 clases nuevas + 1 field map, sin tocar `Shared/`; receta documentada en `docs/architecture-api.md`.

### NonFunctional Requirements

- NFR1: Pureza de dominio — 0 dependencias nuevas en `Domain/` (la regla solo excepciona `symfony/uid`); sin `lambdish/phunctional`; VOs `final readonly` con named constructors y cero imports.
- NFR2: Seguridad — allow-list impuesta por la firma del applier (no opcional); parámetros Doctrine siempre bindeados (naming hasheado `xxh128`); escape de `%`/`_` en CONTAINS; cursor HMAC opaco intacto; fallo de firma silenciado (no oráculo).
- NFR3: Contrato de errores (NFR26 del repo) — todo error de entrada → 4xx RFC 9457; el marker nuevo exige actualizar `docs/api-error-contract.md`, `MarkerStatusMapContractTest` y pasar `make php.lint.error-contract` en el mismo PR.
- NFR4: Rendimiento — conservar `Paginator` keyset/HMAC y modos LIGHT/DETAILED tal cual; p95 del listado sin regresión tras fase 1; todo campo expuesto en field map respaldado por índice (verificación `EXPLAIN ANALYZE`).
- NFR5: Compatibilidad — cero migraciones de BD; cero cambios de esquema; el contrato existente de Bank no se rompe en ninguna fase (estrategia Strangler Fig + Parallel Change expand–migrate–contract).
- NFR6: Dependencias — únicas adiciones de runtime permitidas: `phpstan/phpdoc-parser` + `phpdocumentor/type-resolver` (promoción dev → prod, requisito de `#[MapQueryString]` con arrays de DTOs anidados); prohibido cualquier paquete `codelytv/*` y vendorizar código upstream.
- NFR7: Calidad — gates del repo: `make php.stan` + `make php.psalm` (ambos, por archivo tocado), `make php.quality` completo, Behat extendido; PHPMD sin baseline (mothers nombrados, jamás clases anónimas readonly). Fase 2: `make pwa.quality` + Vitest.

### Additional Requirements

**Decisiones arquitectónicas vinculantes (D1–D8):**

- D1: Gramática wire exacta `filters[N][field]` / `filters[N][operator]` / `filters[N][value]` (escalar) o `filters[N][value][]` (lista); índices contiguos desde 0; otra forma → 400 `validation-failed`.
- D2: Tokens wire minúsculos del operador: `eq` · `in` · `contains` — el backing string del enum ES el contrato.
- D3: Marker nuevo `InvalidSearchCriteria` → 400, default type `invalid-search-criteria`; coste NFR26 asumido conscientemente.
- D4: `filters[]` vive en `SearchQuery` base — toda lista (presente y futura) lo hereda; el riesgo de sobre-exposición lo neutraliza el field map obligatorio.
- D5: `FilterQuery::value` polimórfico `string|list<string>` validado por operador en mapping (IN exige lista no vacía; EQ/CONTAINS exigen string). Riesgo conocido de discrepancia PHPStan↔Psalm — gate doble obligatorio.
- D6: CONTAINS = normalizar → escapar `%`/`_` → `LIKE :param` bindeado; campos sin normalizador: `LOWER(path) LIKE LOWER(:param)`.
- D7: Interface `FieldNormalizer` con implementaciones nombradas; primera: `NormalizedTextFieldNormalizer` (envuelve `NormalizedText`). El normalizador de un campo aplica en TODOS sus operadores (equivalencia `names[]` ≡ `filters[name][in]` garantizada).
- D8: Camino único legacy Bank — `names[]`/`ids[]` → `Filters` → applier; `DoctrineBankRepository` elimina `addWhereIn` ad hoc; `BankSearchCriteria` se elimina.

**Estructura, naming y seam:**

- Brownfield, sin starter: no hay historia de inicialización; el trabajo arranca con `make worktree.create BRANCH=feat/shared-search-filters`.
- FQCNs exactos pineados (no inventar variantes): `Erpify\Shared\Domain\Search\{Filter,Filters,FilterOperator}`, `Erpify\Shared\Domain\Exception\InvalidSearchCriteria`, `Erpify\Shared\Domain\Search\Exception\{UnknownSearchField,UnsupportedSearchOperator}`, `Erpify\Shared\Application\Http\Search\FilterQuery`, `Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\{FilterApplier,SearchFieldMap,FieldMapping,FieldNormalizer,NormalizedTextFieldNormalizer}`.
- Convención de excepciones fijada como precedente: clase = el fallo, sin sufijo `Exception`; `type` kebab-case (`unknown-search-field`, `unsupported-search-operator`).
- Seam auto-apply: `AbstractDoctrineSearchRepository` gana `abstract protected function searchFieldMap(): SearchFieldMap` y aplica `FilterApplier` automáticamente antes de paginar; los repositorios NUNCA llaman al applier ni filtran ad hoc.
- Boundaries: `FilterApplier` invocado EXCLUSIVAMENTE por la base; `SearchFieldMap` construido EXCLUSIVAMENTE en cada repositorio concreto; `Domain/Search` no conoce DQL ni field maps.
- Caps como constantes públicas validadas en mapping: `SearchQuery::MAX_FILTERS = 20` · `FilterQuery::MAX_IN_VALUES = 100`. `filters` ausente/vacío → sin filtrado (no error). Varios filtros sobre el mismo campo → AND.
- Capa de validación pineada: shape (operador inexistente, value incoherente, caps, índices) → mapping → 400 `validation-failed` + `violations[]`; semántica (campo fuera de allow-list, operador no permitido) → applier → 400 familia `invalid-search-criteria`. Ninguna validación de filtros en controller ni use case.
- `FieldMapping` declara operadores permitidos por campo (default: los tres); Bank: `id` → solo `eq`/`in`.
- Paginación (`page`/`limit`/`cursor`/`paginationMode`) y orden (`sort`/`direction`) sin cambios — fuera del vocabulario de filtros.
- Sin eventos de dominio nuevos: PROHIBIDO transportar `Filters`/`SearchCriteria` en Messenger o Mercure.

**Secuencia de fases (refinada — wiring HTTP movido de fase 0 a fase 1):**

- Fase 0 — Núcleo sin cambio de contrato HTTP (inalcanzable desde HTTP): VOs Domain + marker + applier + field map + normalizador + tests unit/integración Postgres real + NFR26 completo.
- Fase 1 — Bank pilota (expand): `FilterQuery` + `SearchQuery.filters[]` + método abstracto + auto-apply + promoción de deps + D8 + Behat ampliado + verificación de que el legacy `SearchExceptionListener` (priority 32) no intercepta las excepciones nuevas.
- Fase 2 — Cliente PWA (migrate): tipos TS + `buildSearchParams` + banks server-driven + Vitest.
- Fase 3 — Generalización: receta documentada; cero ficheros nuevos en `Shared/`.
- Un PR por fase (0 y 1 pueden compartir si el tamaño lo permite). Decisiones diferidas explícitamente fuera de alcance: OR/grupos, operadores adicionales, fase *contract* del Parallel Change, alineación JSON:API.

**Testing:**

- Unit puro para `Domain/Search` (sin contenedor/BD); unit para `FilterQuery`; integración del applier contra Postgres real (nunca SQLite), cubriendo binding, normalización y rechazo de campos fuera de allow-list.
- Object mothers con sufijo `Mother` (`FilterMother`, `FiltersMother`) — primer precedente del repo, clases nombradas.
- Behat: extender `api/features/backoffice/bank/search.feature` (no crear feature paralela): equivalencia `names[]` ≡ `filters[name][in]`, 400s (operador inválido, campo desconocido), CONTAINS con diacríticos, frontera moderada 1 filtro IN × 100 valores (límite efectivo `min(caps, max_input_vars, longitud URL)`).
- `MarkerStatusMapContractTest` actualizado con el marker nuevo.

**Documentación obligatoria por PR (regla CLAUDE.md):**

- Fase 0/1: `docs/api-error-contract.md` (fila `InvalidSearchCriteria`), `docs/architecture-api.md` (patrón + receta "añadir una lista filtrable"), `api/docs/` (forma del endpoint, incl. límite `max_input_vars`), `docs/source-tree-analysis.md` + `docs/claude-code-quickref.md` (directorio nuevo `Doctrine/Search/`).
- Fase 2: `pwa/docs/` + `docs/architecture-pwa.md` (builder).
- Cierre del research: retirar `php-criteria-main/` del working tree (material de estudio, no se committea).

### UX Design Requirements

No existe documento de UX Design para este mecanismo (alcance API-céntrico; la fase 2 PWA reutiliza la UI existente de la lista de banks sin cambios visuales — solo cambia el origen del filtrado a server-driven).

### FR Coverage Map

- FR1: Epic 1 - Vocabulario de filtros en dominio (`Filter`/`Filters`/`FilterOperator`)
- FR2: Epic 1 - Contrato HTTP genérico (`FilterQuery[]` + `SearchQuery` base)
- FR3: Epic 1 - `FilterApplier` + `SearchFieldMap` (allow-list obligatoria)
- FR4: Epic 1 - Retrocompatibilidad Bank (camino único D8, Behat de equivalencia)
- FR5: Epic 1 - Error de búsqueda inválida → 400 (marker `InvalidSearchCriteria` + NFR26)
- FR6: Epic 2 - Cliente PWA (builder TS + banks server-driven)
- FR7: Epic 1 - Generalización (seam abstracto + receta en `docs/architecture-api.md`; la fase 3 no requiere trabajo propio en `Shared/`)

## Epic List

### Epic 1: Búsqueda filtrable genérica en la API (Bank como piloto)

Cualquier consumidor de la API puede filtrar la lista de banks con el contrato genérico `filters[N][field/operator/value]` — validado, seguro (allow-list por construcción) y con errores 400 RFC 9457 — mientras los parámetros legacy `names[]`/`ids[]` siguen funcionando idénticos. El mecanismo queda listo para que cualquier lista futura sea filtrable con ≤ 2 clases + 1 field map (receta documentada).
**FRs covered:** FR1, FR2, FR3, FR4, FR5, FR7
**Alcance:** fases 0 + 1 de la arquitectura, con historias ordenadas (núcleo → wiring HTTP → Bank pilota → docs); los PRs por fase se respetan a nivel de historia. Standalone: capacidad servidor completa verificable vía Behat.

### Epic 2: Filtrado server-driven en la PWA

Los usuarios de la PWA filtran la lista de banks contra el servidor (deja de filtrarse client-side), a través del builder TS compartido que serializa la gramática exacta del contrato — reutilizable por toda lista futura. El cursor se descarta al cambiar cualquier filtro (regla aprendida del race debounce+paginación).
**FRs covered:** FR6
**Alcance:** fase 2 (migrate del Parallel Change). Standalone: consume el contrato entregado por Epic 1, no requiere nada futuro.

## Epic 1: Búsqueda filtrable genérica en la API (Bank como piloto)

Cualquier consumidor de la API puede filtrar la lista de banks con el contrato genérico `filters[N][field/operator/value]` — validado, seguro (allow-list por construcción) y con errores 400 RFC 9457 — mientras los parámetros legacy `names[]`/`ids[]` siguen funcionando idénticos. El mecanismo queda listo para que cualquier lista futura sea filtrable con ≤ 2 clases + 1 field map (receta documentada). Historias 1.1–1.3 = fase 0 (núcleo, inalcanzable desde HTTP); historias 1.4–1.6 = fase 1 (expand del Parallel Change).

### Story 1.1: Vocabulario de filtros en el dominio compartido

As a desarrollador del monorepo,
I want un vocabulario tipado e inmutable de filtros (`Filter`, `FilterOperator`, `Filters`) transportado por `SearchCriteria`,
So that cualquier búsqueda exprese filtrado con tipado estático extremo a extremo sin acoplarse a ningún framework.

**Acceptance Criteria:**

**Given** el namespace `Erpify\Shared\Domain\Search`
**When** se implementan `Filter` (VO `final readonly`), `Filters` (colección inmutable) y `FilterOperator` (enum backed: `Eq = 'eq'`, `In = 'in'`, `Contains = 'contains'`)
**Then** ninguna de las tres clases tiene dependencias externas (ni framework, ni ORM, ni `lambdish/phunctional` — NFR1)
**And** se construyen vía named constructors y `Filter` transporta el nombre PÚBLICO del campo (nunca paths DQL).

**Given** la clase `SearchCriteria` existente
**When** gana el parámetro `filters` como named arg con default `Filters` vacío
**Then** todos los call-sites existentes compilan sin cambios (retrocompatibilidad total)
**And** ningún comportamiento existente cambia (Bank intacto, Behat existente en verde).

**Given** las clases nuevas
**When** se ejecutan los tests unitarios puros (`FilterTest`, `FiltersTest`, `FilterOperatorTest`) sin contenedor ni BD
**Then** cubren construcción, inmutabilidad, colección vacía y los valores wire del enum
**And** existen `FilterMother` y `FiltersMother` como clases nombradas bajo el subnamespace `Mother/` (primer precedente del repo — jamás clases anónimas readonly, gotcha PHPMD).

**Given** los gates de calidad
**When** se cierra la historia
**Then** `make php.stan` y `make php.psalm` pasan sobre cada archivo tocado.

### Story 1.2: Errores de búsqueda inválida en el pipeline RFC 9457

As a consumidor de la API,
I want que cualquier búsqueda inválida produzca un 400 Problem Details (nunca un 500),
So that pueda corregir mi petición con información precisa y el contrato de errores se mantenga uniforme.

**Acceptance Criteria:**

**Given** `Erpify\Shared\Domain\Exception`
**When** se crea el marker `InvalidSearchCriteria` (junto a los 7 markers existentes)
**Then** `ProblemDetailsFactory` lo mapea a status 400 en `MARKER_STATUS_MAP` y a default type `invalid-search-criteria` en `MARKER_DEFAULT_TYPE_MAP`
**And** el marker queda bajo el guard de `TaxonomyArchitectureTest`.

**Given** `Erpify\Shared\Domain\Search\Exception`
**When** se crean las excepciones concretas `UnknownSearchField` y `UnsupportedSearchOperator` (sin sufijo `Exception` — precedente fijado por la arquitectura)
**Then** implementan el marker `InvalidSearchCriteria`
**And** exponen types kebab-case `unknown-search-field` y `unsupported-search-operator`.

**Given** el contract test `MarkerStatusMapContractTest`
**When** se actualiza con el marker nuevo
**Then** pasa en verde
**And** `make php.lint.error-contract` pasa en verde.

**Given** `docs/api-error-contract.md`
**When** se añade la fila del marker `InvalidSearchCriteria` (obligación NFR26)
**Then** documento y código quedan consistentes en el mismo PR.

### Story 1.3: Applier de filtros sobre QueryBuilder con allow-list obligatoria

As a desarrollador de un repositorio de búsqueda,
I want un applier genérico que traduzca `Filters` a `andWhere` parametrizados, gobernado por un `SearchFieldMap` obligatorio,
So that ningún campo no autorizado sea filtrable y ningún valor llegue sin bindear a SQL.

**Acceptance Criteria:**

**Given** el subdirectorio nuevo `Shared/Infrastructure/Persistence/Doctrine/Search/`
**When** se implementan `FilterApplier`, `SearchFieldMap`, `FieldMapping`, `FieldNormalizer` (interface) y `NormalizedTextFieldNormalizer` (envuelve `NormalizedText`)
**Then** la firma `apply(QueryBuilder, Filters, SearchFieldMap)` hace imposible invocar el applier sin allow-list (enforcement por construcción, NFR2).

**Given** un filtro cuyo `field` no tiene entrada en el `SearchFieldMap`
**When** el applier lo procesa
**Then** lanza `UnknownSearchField`
**And** un operador no incluido en los permitidos del `FieldMapping` (default: los tres) lanza `UnsupportedSearchOperator`.

**Given** un filtro CONTAINS sobre un campo con normalizador
**When** el applier lo procesa
**Then** normaliza el valor, escapa `%` y `_`, y genera `LIKE :param` bindeado sobre el path DQL del map
**And** campos sin normalizador usan `LOWER(path) LIKE LOWER(:param)`
**And** el normalizador del campo se aplica también en EQ e IN (equivalencia futura `names[]` ≡ `filters[name][in]` garantizada).

**Given** el QueryBuilder de un repositorio
**When** el applier añade condiciones
**Then** solo usa `andWhere` + parámetros bindeados con el naming hasheado `xxh128` heredado de `AbstractDoctrineRepository` (nunca interpolación de `field`/`value`)
**And** `Filters` vacío es un no-op silencioso
**And** varios filtros sobre el mismo campo componen con AND
**And** paginación, orden, joins y COUNT siguen siendo monopolio del `Paginator` y de `getSearchQueryBuilder()`.

**Given** `FilterApplierTest` de integración
**When** corre contra Postgres real (nunca SQLite)
**Then** cubre binding de parámetros, normalización diacrítica, escape de comodines, rechazo de campo fuera de allow-list y rechazo de operador no permitido.

### Story 1.4: Contrato genérico `filters[]` expuesto en el endpoint de banks

As a consumidor de la API de banks,
I want filtrar con `filters[N][field/operator/value]` validado en mapping,
So that pueda componer búsquedas server-side sin esperar parámetros ad hoc nuevos.

**Acceptance Criteria:**

**Given** `api/composer.json`
**When** se promocionan `phpstan/phpdoc-parser` y `phpdocumentor/type-resolver` de transitivas dev a `require` (versiones ya locked — NFR6)
**Then** `#[MapQueryString]` mapea arrays de DTOs anidados en runtime.

**Given** el DTO `FilterQuery` con constraints `#[Assert]` (D5)
**When** llega un `value` incoherente con el operador (IN exige lista no vacía con items no vacíos; EQ/CONTAINS exigen string no vacío), un operador fuera del enum (estrictamente lowercase), caps excedidos (`SearchQuery::MAX_FILTERS = 20`, `FilterQuery::MAX_IN_VALUES = 100`) o índices no contiguos desde 0
**Then** la respuesta es 400 `validation-failed` con `violations[]` (nunca `ValueError`/500)
**And** ninguna validación de filtros vive en controller ni use case (capa pineada: shape → mapping).

**Given** `SearchQuery` base
**When** gana `filters[]` (`@param list<FilterQuery> $filters`) y `toCriteria()` los traslada a `Filters`
**Then** `filters` ausente o vacío → sin filtrado (no es error)
**And** paginación (`page`/`limit`/`cursor`/`paginationMode`) y orden (`sort`/`direction`) quedan intactos.

**Given** `AbstractDoctrineSearchRepository`
**When** gana `abstract protected function searchFieldMap(): SearchFieldMap` y auto-aplica `FilterApplier` antes de paginar
**Then** `DoctrineBankRepository` implementa su map en el mismo cambio: `name` → `b.nameNormalized` + `NormalizedTextFieldNormalizer`; `id` → `b.id` con operadores solo `eq`/`in`
**And** no existe ningún estado intermedio roto (método abstracto + implementación en el mismo PR)
**And** ningún repositorio invoca el applier directamente.

**Given** `GET /api/v1/backoffice/banks?filters[0][field]=name&filters[0][operator]=contains&filters[0][value]=banc`
**When** se ejecuta la petición
**Then** filtra correctamente con normalización diacrítica
**And** el envelope de respuesta (items + pagination.cursor/hasMorePages) no cambia (NFR5)
**And** los parámetros legacy `names[]`/`ids[]` siguen funcionando por su camino actual (expand: ambos caminos conviven).

**Given** `api/features/backoffice/bank/search.feature` (extendido, nunca feature paralela)
**When** corre la suite Behat
**Then** cubre: filtros genéricos felices (eq/in/contains), 400 por campo desconocido, 400 por operador inválido, CONTAINS con diacríticos, frontera moderada 1 filtro IN × 100 valores
**And** verifica que el legacy `SearchExceptionListener` (priority 32) no intercepta las excepciones nuevas
**And** los 29 escenarios existentes siguen en verde.

**Given** `api/docs/`
**When** se documenta la forma del endpoint
**Then** incluye la gramática wire exacta y el límite efectivo `min(caps, max_input_vars, longitud de URL)`.

### Story 1.5: Camino único legacy→Filters en Bank (D8)

> **Nota de decisión (2026-06-07, Sergio, durante la implementación):** el código no está desplegado en
> producción y la PWA aún filtra client-side (cero consumidores de `names[]`/`ids[]`), así que la fase
> *contract* del Parallel Change se adelantó a esta historia: los params legacy se **retiraron del wire**
> en lugar de mapearse internamente (`ids[]` se eliminó también del contrato base `SearchQuery`/`SearchCriteria`;
> ambas clases pasaron a `final`). Los ACs vigentes viven reescritos en el story file
> `1-5-camino-unico-legacy-filters-en-bank-d8.md`; los de abajo se conservan como redacción original.
> Quedan sin efecto: la mitad legacy de FR4, el AC de equivalencia Behat (mutó a pins del contrato único)
> y la decisión diferida "fase contract post-fase-2" (consumida aquí).

As a mantenedor del contexto Bank,
I want que `names[]`/`ids[]` mapeen internamente a `Filters` y desaparezca el filtrado ad hoc,
So that exista un solo camino de filtrado con equivalencia garantizada y el coste de mantenimiento no se duplique.

**Acceptance Criteria:**

**Given** `BankSearchQuery`
**When** mapea internamente `names[]` → `filters[name][in]` e `ids[]` → `filters[id][in]`
**Then** el wire legacy sigue aceptándose sin ningún cambio de contrato (Parallel Change: expand intacto)
**And** la combinación de params legacy + `filters[]` genéricos en la misma petición compone con AND.

**Given** `BankSearcher` y `BankSearchRepository`
**When** sus firmas pasan a `SearchCriteria` base
**Then** `BankSearchCriteria` se elimina (y el directorio `Bank/Domain/Search/` desaparece)
**And** `DoctrineBankRepository` elimina los `addWhereIn` ad hoc (el filtrado entra solo por el auto-apply del seam).

**Given** la suite Behat ampliada
**When** corre
**Then** demuestra la equivalencia `names[]` ≡ `filters[name][in]` (mismos resultados, misma normalización diacrítica) ANTES de que el código ad hoc se elimine
**And** los 29 escenarios existentes siguen en verde.

**Given** los gates de cierre
**When** se completa la historia
**Then** `make php.stan` + `make php.psalm` + `make php.quality` + `make php.behat` en verde
**And** p95 del listado sin regresión y todo campo del map respaldado por índice (verificación `EXPLAIN ANALYZE` — NFR4).

### Story 1.6: Receta de generalización y cierre documental

As a futuro desarrollador (humano o agente) que añade una lista filtrable,
I want una receta canónica documentada y el árbol documental al día,
So that la siguiente entidad use el mecanismo sin modificar `Shared/` (≤ 2 clases + 1 field map — FR7).

**Acceptance Criteria:**

**Given** `docs/architecture-api.md`
**When** se documenta el patrón de búsqueda filtrable y la receta "añadir una lista filtrable"
**Then** incluye el ejemplo canónico de `searchFieldMap()` y los anti-patterns prohibidos (matching()/Collections\Criteria, filtrado ad hoc, validación fuera de capa, JsonResponse manual)
**And** es la fuente única para humanos y agentes.

**Given** el subdirectorio nuevo `Doctrine/Search/`
**When** se actualizan `docs/source-tree-analysis.md` y `docs/claude-code-quickref.md`
**Then** reflejan la estructura real del árbol (regla CLAUDE.md de docs por PR).

**Given** `php-criteria-main/` presente en el working tree como material de estudio
**When** se cierra la decisión del research
**Then** se retira del working tree (nunca se committea).

## Epic 2: Filtrado server-driven en la PWA

Los usuarios de la PWA filtran la lista de banks contra el servidor (deja de filtrarse client-side), a través del builder TS compartido que serializa la gramática exacta del contrato — reutilizable por toda lista futura. El cursor se descarta al cambiar cualquier filtro. Fase 2 (migrate del Parallel Change); consume el contrato entregado por la Epic 1.

### Story 2.1: Vocabulario y builder de filtros compartido en la PWA

As a desarrollador de la PWA,
I want tipos `Filter`/`FilterOperator` y un `buildSearchParams` en `context/shared` que serialicen exactamente la gramática del contrato,
So that toda lista presente y futura componga filtros server-side sin duplicar serialización.

**Acceptance Criteria:**

**Given** `pwa/src/context/shared/domain/Search/`
**When** se crean `Filter.ts` e `index.ts`
**Then** `FilterOperator` se define como union type + const (`'eq' | 'in' | 'contains'`) — nunca TS `enum`
**And** solo named exports (regla de `src/context/**`)
**And** los subdirectorios siguen la convención PascalCase con `index.ts` de los siblings existentes.

**Given** `pwa/src/context/shared/infrastructure/Search/buildSearchParams.ts`
**When** serializa una lista de filtros
**Then** produce exactamente la gramática wire D1/D2: `filters[N][field]`, `filters[N][operator]`, `filters[N][value]` (escalar) o `filters[N][value][]` (lista), con índices contiguos desde 0
**And** una lista vacía o ausente no produce ningún query param de filtro.

**Given** `pwa/tests/context/shared/infrastructure/Search/buildSearchParams.test.ts`
**When** corre la suite Vitest
**Then** cubre: filtro escalar (eq/contains), filtro de lista (in), varios filtros combinados, y ausencia de filtros
**And** TS 6 strict y `make pwa.quality` pasan en verde.

### Story 2.2: Lista de banks filtrada server-driven

As a usuario del backoffice,
I want que el filtrado de la lista de banks se resuelva en el servidor,
So that los resultados sean consistentes con la paginación y escalen con el volumen de datos.

**Acceptance Criteria:**

**Given** la interfaz de dominio `pwa/src/context/backoffice/bank/domain/BankRepository.ts`
**When** su firma de búsqueda acepta filtros
**Then** `ApiBankRepository.ts` los serializa vía `buildSearchParams` hacia `GET /api/v1/backoffice/banks`
**And** el filtrado client-side se elimina por completo (server-driven).

**Given** un cambio en cualquier filtro activo
**When** se reconstruye la query de búsqueda
**Then** el cursor actual se descarta (regla aprendida del race debounce+paginación de banks)
**And** el cursor permanece opaco — nunca se interpreta ni se fabrica client-side.

**Given** la UI existente de la lista de banks
**When** se completa la migración
**Then** no hay cambios visuales — solo cambia el origen del filtrado
**And** los tests unit y e2e existentes se adaptan y pasan (mockear el hook realtime donde aplique el flake conocido; esperar el badge de filtro activo antes de paginar en e2e).

**Given** las obligaciones documentales de la fase 2
**When** se cierra la historia
**Then** `pwa/docs/` y `docs/architecture-pwa.md` documentan el builder y el patrón server-driven
**And** `make pwa.quality` + Vitest pasan en verde.
