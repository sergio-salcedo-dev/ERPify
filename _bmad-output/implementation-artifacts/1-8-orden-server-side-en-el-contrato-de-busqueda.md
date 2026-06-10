---
baseline_branch: feat/shared-search-filters-aj0w
baseline_commit: e0d8794
note_baseline: >-
  Esta historia NO se ramifica de `main`. El contrato de búsqueda filtrable (historias 1.1–1.7)
  vive exclusivamente en la rama no fusionada `feat/shared-search-filters-aj0w` (PR #180, commit
  e0d8794, Story 1.7 = done). En `main` `Shared/Domain/Search/` no contiene el vocabulario de
  filtros ni `SortDirection`. Desarrollar SOBRE esta rama (nuevo commit) o una rama apilada;
  ramificar de `main` hoy = los ficheros a editar no existen. Ver "Prerrequisito de baseline".
note_scope: >-
  Historia definida en `epics.md` (worktree, líneas 351–379), añadida en el commit 5336790
  ("docs(planning): add stories 1.7/1.8 to close epic-2 contract gap"). Cierra el último hueco
  del contrato API antes de migrar el filtrado de la PWA a server-side: la ordenación
  (`sort`/`direction`). Es **prerequisito de la Story 2.2**. NOTA: el checkout principal
  (`/home/dev/Projects/ERPify`) contiene una copia *divergente y obsoleta* de `_bmad-output/*`
  (un 1.7 distinto, "review"); la fuente de verdad es este worktree.
---

# Story 1.8: Orden server-side en el contrato de búsqueda

Status: done

_Ultimate context engine analysis completed — guía exhaustiva creada contra el estado real verificado de la rama `feat/shared-search-filters-aj0w` @ e0d8794 (2026-06-08)._

## Story

As a consumidor de la API de banks,
I want pedir el orden de la lista por un campo permitido y una dirección (`sort`/`direction`),
so that la PWA resuelva la ordenación en el servidor, consistente con la paginación keyset y el filtrado, sin reordenar client-side.

## Acceptance Criteria

**Given** el DTO `SearchQuery` (`Shared/Application/Http/Search`) y la clase de dominio `SearchCriteria` (`Shared/Domain/Search`), ambas hoy sin `sort`
**When** ganan `sort` (`?string`, default `null`) y `direction` (`?SortDirection`, default `null`)
**Then** `sort` ausente → el orden por defecto actual (`createdAt` ASC, fijado en `AbstractDoctrineSearchRepository::addOrderByFromQueryParams`) NO cambia y todos los call-sites existentes compilan sin tocarlos (retrocompatibilidad total: parámetros nuevos al final, con default)
**And** `direction` se valida contra el enum `SortDirection` **en mapping** (un valor fuera de `ASC`/`DESC`, o forma array, → 400 `validation-failed`, exactamente como ya ocurre con `paginationMode` — sin código nuevo, lo da el `#[MapQueryString]` + tipo enum)
**And** `SearchQuery::toCriteria()` traslada `sort`/`direction` a `SearchCriteria`.

**Given** que `addOrderByFromQueryParams` hoy interpola el campo en DQL vía `sprintf('%s.%s', $alias, $sort)` (vector de inyección en cuanto `$sort` deje de ser `null`)
**When** llega un `sort` de cliente
**Then** se resuelve contra una **allow-list de campos ordenables por repositorio** (`SortFieldMap`, análoga a `SearchFieldMap`): el nombre público → path DQL sale del mapa, el `sort` crudo NUNCA se interpola en DQL
**And** un `sort` fuera de la allow-list lanza `UnknownSortField` (concreta nueva, `extends DomainException implements InvalidSearchCriteria` → reusa el marker existente → 400 familia `invalid-search-criteria`, type `unknown-sort-field`), nunca un 500 ni un `InvalidArgumentException` del guard regex del `Paginator`
**And** ningún repositorio resuelve el `sort` ad hoc: la resolución vive en el seam base, los repos solo declaran su `sortFieldMap()`.

**Given** `DoctrineBankRepository::getSearchQueryBuilder` (hoy pasa `orderByField: null, direction: null`)
**When** propaga `criteria->sort`/`criteria->direction` a `addOrderByFromQueryParams`
**Then** Bank declara su allow-list de orden `{ name → b.nameNormalized, shortName → b.shortName, createdAt → b.createdAt, updatedAt → b.updatedAt }` (los 4 sortable que la PWA ya ofrece client-side; `id` y demás columnas quedan deliberadamente fuera)
**And** cada campo de orden está respaldado por un índice btree existente (NFR4): `name_normalized` (UNIQUE), `short_name` (UNIQUE), `idx_bank_created_at`, `idx_bank_updated_at` → **no se requiere migración nueva**
**And** `EXPLAIN ANALYZE` de la query ordenada usa el índice (Index Scan, no filesort/Seq Scan a escala real) y p95 del listado no regresa.

**Given** `api/features/backoffice/bank/search.feature` (extendido, nunca feature paralela) y los gates del repo
**When** corre la suite
**Then** cubre: orden por cada campo permitido (`name`/`shortName`/`createdAt`/`updatedAt`, asc y desc), 400 `unknown-sort-field` por campo de orden no permitido (`sort=id`), 400 `validation-failed` por `direction` inválida, y el caso default sin `sort` (orden `createdAt` ASC con desempate por `id`), con los escenarios existentes en verde
**And** `make php.stan` + `make php.psalm` (por archivo tocado), `make php.quality`, `make php.behat`, `make php.unit` en verde
**And** `api/docs/adding-endpoints.md` (params `sort`/`direction` + tokens wire + allow-list de orden), `docs/architecture-api.md` (receta: declarar un `sortFieldMap()`) y `docs/api-error-contract.md` (nuevo disparador `unknown-sort-field` de la familia `InvalidSearchCriteria`) quedan consistentes en el mismo PR
**And** sin marker nuevo (reusa `InvalidSearchCriteria`), por lo que `MarkerStatusMapContractTest` y `make php.lint.error-contract` siguen en verde (verificar, no asumir).

## Tasks / Subtasks

- [x] Task 0 — Prerrequisito de baseline y rama (AC: todos)
  - [x] Confirmar que se trabaja SOBRE `feat/shared-search-filters-aj0w` (PR #180, commit e0d8794), no sobre `main`. Reutilizar el worktree existente `.claude/worktrees/shared-search-filters-aj0w/` (nuevo commit en esa rama, push a PR #180). NUNCA ramificar de `main` (el contrato de búsqueda no existe allí).
  - [x] Verificar partida verde en el worktree: `make php.unit` (650 OK, 3 skipped), `make php.behat` (101 escenarios, 710 pasos OK).

- [x] Task 1 — RED: relocalizar `SortDirection` a Domain + ampliar `SearchCriteria`/`SearchQuery` (AC 1)
  - [x] Mover `SortDirection` de `Erpify\Shared\Infrastructure\Persistence` a `Erpify\Shared\Domain\Search` (enum puro `ASC='ASC'`/`DESC='DESC'`, cero dependencias → válido en Domain). Actualizados los **únicos 3 imports** existentes: el propio fichero (namespace), `AbstractDoctrineSearchRepository`, `Paginator`. (`QueryParam` se queda en Infrastructure: son nombres de query param, no dominio.)
  - [x] `SearchCriteria` (`Domain/Search/SearchCriteria.php`): añadidos `public ?string $sort = null` y `public ?SortDirection $direction = null` como named args al final (default `null` → retrocompat total). Ampliado `SearchCriteriaTest`.
  - [x] `SearchQuery` (`Application/Http/Search/SearchQuery.php`): añadidos `public ?string $sort = null` (con `#[Assert\Length(max: self::MAX_SORT_LENGTH=64)]` como defensa de forma; la allow-list real es semántica en el repo) y `public ?SortDirection $direction = null`. Propagados ambos en `toCriteria()`. Ampliado `SearchQueryTest` (toCriteria traslada sort/direction; null → null; sort demasiado largo → violación).

- [x] Task 2 — GREEN: `SortFieldMap` + `UnknownSortField` (AC 2)
  - [x] Creado `Shared/Infrastructure/Persistence/Doctrine/Search/SortFieldMap.php` (`final readonly`, `array<string,string>` nombre público → path DQL, método `pathFor(string $field): ?string`). Test unit `SortFieldMapTest` (path / null / mapa vacío).
  - [x] Creado `Shared/Domain/Search/Exception/UnknownSortField.php` espejo de `UnknownSearchField`: `final class … extends DomainException implements InvalidSearchCriteria`, `public const string TYPE = 'unknown-sort-field'`, named constructor `::named(string $field)` con `context: ['field' => $field]` (el campo viaja en context, NUNCA interpolado en el title). Test `UnknownSortFieldTest` espejo de `UnknownSearchFieldTest`.

- [x] Task 3 — GREEN: resolución de orden en el seam base (AC 2, 3)
  - [x] `AbstractDoctrineSearchRepository`: añadido `abstract protected function sortFieldMap(): SortFieldMap;` (espejo de `searchFieldMap()`; Bank es el único subtipo concreto — verificado). Reescrito `addOrderByFromQueryParams`:
    - `null === $orderByField` → default: `sprintf('%s.%s', $alias, QueryParam::CREATED_AT->value)` (campo CONSTANTE de confianza, no input).
    - `null !== $orderByField` → `$this->sortFieldMap()->pathFor($orderByField) ?? throw UnknownSortField::named($orderByField)` (el campo de cliente NUNCA se interpola; sale del mapa). Expresado como ternaria (PHPMD `ElseExpression` prohíbe el `else`).
    - `addOrderBy(sort: $path, order: ($direction ?? SortDirection::ASC)->value)`.
  - [x] INVARIANTE dura cumplida: el único `sprintf('%s.%s', …)` que toca un valor interpolado usa solo la constante `createdAt`; el campo de cliente jamás llega crudo a DQL.

- [x] Task 4 — GREEN: wiring de Bank (AC 3)
  - [x] `DoctrineBankRepository::getSearchQueryBuilder`: cambiado a `orderByField: $criteria->sort, direction: $criteria->direction`.
  - [x] Implementado `sortFieldMap(): SortFieldMap` con `{ 'name' => 'b.nameNormalized', 'shortName' => 'b.shortName', 'createdAt' => 'b.createdAt', 'updatedAt' => 'b.updatedAt' }`. Añadido `Bank::getNameNormalized()` (sin grupo serializador) para que el cursor keyset pueda leer la columna de orden — ver Completion Notes (desviación de diseño descubierta).

- [x] Task 5 — Behat + NFR4 (AC 4)
  - [x] Extendido `search.feature`: orden asc/desc por `name`/`shortName` (pin `data[0].id` desde fixtures Alice: name→022/003, shortName→016/003); `createdAt`/`updatedAt` asc/desc cubiertos como "allow-listed + ejecuta en ambas direcciones" (200 + 31 elems) porque las fixtures comparten instante y el desempate id ASC domina (no hay orden de fecha pinneable); 400 `unknown-sort-field` (`sort=id`), 400 `validation-failed` (`direction=sideways` y `direction=asc`), default sin sort (001).
  - [x] `EXPLAIN ANALYZE` (con `SET enable_seqscan=off`) registrado en Completion Notes: `name_normalized` → Index Scan (Presorted Key); `created_at DESC` → Index Scan Backward `idx_bank_created_at`. NFR4 OK, sin filesort.

- [x] Task 6 — Docs + gates (AC 4)
  - [x] `api/docs/adding-endpoints.md`: documentados `sort` (allow-list por campo, `sortFieldMap()`) y `direction` (tokens wire `ASC`/`DESC` mayúsculas = backing del enum; distintos de los operadores en minúscula). Nueva subsección "Ordering" + item de receta "Sort field map".
  - [x] `docs/architecture-api.md`: receta de "lista filtrable" ampliada con el paso "declarar `sortFieldMap()`"; flujo/validación/anti-patterns ampliados; anti-pattern explícito: jamás interpolar un `sort` de cliente en DQL.
  - [x] `docs/api-error-contract.md`: añadido `unknown-sort-field` como disparador de la familia `InvalidSearchCriteria` (sin marker nuevo). `make php.lint.error-contract` verde (5 tests; incluye `MarkerStatusMapContractTest`); `error_contract` feature en verde dentro del suite behat.
  - [x] Gates: `make php.stan` verde, `make php.quality` verde (Rector auto-reescribió 2 tests; PHPMD `ElseExpression` corregido), `make php.behat` verde, `make php.unit` verde.

### Review Findings

Code-review adversarial (Blind Hunter / Edge Case Hunter / Acceptance Auditor) sobre `e0d8794..5d36330`, 2026-06-08. Acceptance Auditor confirma los 4 AC y las decisiones pineadas satisfechos; las desviaciones documentadas (`getNameNormalized()`, escenarios temporales createdAt/updatedAt, `direction=asc` extra) son sólidas. Resumen del triaje: 2 decision-needed, 3 patch, 1 defer, 6 dismissed.

**Decision-needed (resueltas 2026-06-08 por Sergio → patch):**

- [x] [Review][Decision] Contrato ante `sort=""` (vacío) → **Resuelto: coalescer `''`→`null`** (tratar vacío como ausente → orden por defecto, no 400). Genera el patch **P4**. (source: edge)
- [x] [Review][Decision] `direction` sin `sort` → **Resuelto: documentar el comportamiento actual** (`direction` aplica al campo por defecto `createdAt`; no se ignora ni se rechaza). Genera el patch **P5** (solo docs). (source: blind+edge+auditor)

**Patch (aplicados y verificados 2026-06-08; gates verdes: php.stan, php.quality EXIT=0, php.unit 661, php.behat 116):**

- [x] [Review][Patch] (P1) Pineada la forma array `direction[]=ASC` → 400 `validation-failed` [api/features/backoffice/bank/search.feature] — escenario Behat verde: el enum nullable SÍ rechaza la forma array en mapping (la sospecha de coerción silenciosa array→null queda descartada y pineada). (source: edge)
- [x] [Review][Patch] (P2) Escenario adversarial de inyección por `sort` → 400 `unknown-sort-field`, 0 queries [api/features/backoffice/bank/search.feature] — valor con forma `createdAt); DROP TABLE bank; --` resuelto a null por la allow-list antes de SQL; verde. (source: blind)
- [x] [Review][Patch] (P3) `assertNotInstanceOf` vs `assertNull` — **no aplicado: el toolchain lo impone.** Cambié a `assertNull(...->direction)` pero Rector (en `php.quality`) lo reescribe automáticamente a `assertNotInstanceOf(SortDirection::class, ...)` para el caso enum (mismo comportamiento que el Debug Log original). Se mantiene la forma actual; caveat previsto materializado. PHPStan acepta ambas. (source: blind)
- [x] [Review][Patch] (P4, de Decision 1) `SearchQuery::toCriteria()` coalesce `'' → null` [api/src/Shared/Application/Http/Search/SearchQuery.php] — `?sort=` cae ahora en el orden por defecto (no 400). Cubierto por `testToCriteriaNormalizesAnEmptySortToNoOrdering` (unit) + escenario Behat (200 + orden por defecto). Verde. (Elegida la normalización en el borde HTTP en vez del seam: el `SearchCriteria` nunca transporta un nombre de campo vacío sin sentido.)
- [x] [Review][Patch] (P5, de Decision 2, solo docs) Documentado que `direction` sin `sort` aplica al campo por defecto y que `sort=` vacío → orden por defecto [docs/architecture-api.md, api/docs/adding-endpoints.md].

**Defer:**

- [x] [Review][Defer] Cursor keyset + cambio de `sort` sin test e2e [api/features/backoffice/bank/search.feature] — deferred → Story 2.2 (PWA descarta el cursor al cambiar orden). La degradación a offset ya está verificada en el `Paginator` (`buildCursorWhere` devuelve null si falta la columna del cursor); falta solo la cobertura e2e del salto cursor→offset al cambiar `sort`. (source: blind)

**Dismissed (6, no persistidos):** (1) Blind **High** «keyset no determinista por columnas que empatan» — falso positivo: `Paginator.php:187-189` añade siempre `id ASC` como desempate, también en el camino de sort de cliente; (2) «name/shortName sin desempate único» — mismo mecanismo + columnas UNIQUE; (3) «extracción de cursor de `getNameNormalized()` no verificada» — `Paginator.php:391-405` lee por PropertyAccess y los 4 paths tienen accessor; (4) ids de fixtures «frágiles» — convención Behat ya establecida en el repo; (5) asimetría `MAX_SORT_LENGTH` vs `direction` — `direction` está acotado por el enum en mapping; (6) evidencia `EXPLAIN ANALYZE` «solo documental» — aceptable por la estrategia de testing de la propia historia (índices verificados estructuralmente).

## Dev Notes

### Contexto y alcance (leer antes de tocar nada)

- Última pieza del **contrato API** antes de la fase 2 (Épica 2, PWA server-driven). 1.1–1.6 entregaron `filters[]`; 1.7 añadió rango temporal + `shortName` filtrable; **1.8 añade ordenación** (`sort`/`direction`). Sin ella, mover el filtrado de la PWA a server-side perdería la ordenación que hoy resuelve client-side (`banksFilterSort.ts`). Es **prerequisito de la Story 2.2**.
- **Fuera de alcance (no especular — NFR1/YAGNI):**
  - Multi-sort (varios campos de orden): la PWA ofrece un único campo de orden a la vez → `sort` escalar. No `sort[]`.
  - Ordenar por campos no expuestos / no indexados (sin consumidor o violaría NFR4).
  - PWA/fase 2 (Épica 2, stories 2.1/2.2): tipos TS, `buildSearchParams` con sort, y descartar el cursor al cambiar el orden. Aquí solo la capa API.
  - `nulls first/last`, orden por expresión, collation custom.

### Prerrequisito de baseline (BLOQUEANTE)

- El contrato que esta historia extiende **no está en `main`**. Vive en `feat/shared-search-filters-aj0w` (PR #180) @ `e0d8794` (Story 1.7 = done). Desarrollar SOBRE esa rama o una apilada. Los ficheros de historia y `sprint-status.yaml` de la Épica 1 figuran `??` (untracked) en el checkout principal: el trabajo aún no se ha fusionado.
- **Aviso de divergencia:** el checkout principal `/home/dev/Projects/ERPify/_bmad-output/` tiene una copia OBSOLETA con un 1.7 distinto ("operadores-de-rango-temporal", status `review`) y SIN 1.8. La fuente de verdad es este worktree (`epics.md` 351–379 + `sprint-status.yaml` con `1-8-orden-server-side-…: backlog`). No mezclar.

### Estado actual del código (verificado 2026-06-08 en el worktree @ e0d8794)

**`SortDirection` (HOY en Infrastructure, a MOVER a Domain):** `Erpify\Shared\Infrastructure\Persistence\SortDirection` — `enum: string { ASC='ASC'; DESC='DESC'; }`. Usos no-cache (3): el fichero, `AbstractDoctrineSearchRepository`, `Paginator`.

**`SearchCriteria` (`Domain/Search/SearchCriteria.php`, `final readonly`):** `cursor/page/limit/paginationMode/filters`. **Sin `sort`/`direction`.** Aquí entran (Domain → debe importar el `SortDirection` ya relocalizado a Domain; NUNCA desde Infrastructure).

**`SearchQuery` (`Application/Http/Search/SearchQuery.php`, `final readonly`):** `cursor/page/limit/paginationMode/filters[]` con `#[Assert]`; `paginationMode` es enum (`PaginationMode`) → **prueba viva de que un enum mal mapeado por `#[MapQueryString]` da 400** (escenario Behat "Unknown pagination mode returns 400", líneas 30-33; "Array-form pagination mode returns 400", 35-38). `direction` se modela igual. `toCriteria()` ya traslada cursor/page/limit/paginationMode/filters → añadir sort/direction. **Sin subclases** (banks usa este DTO directo, vía `BankSearchController` con `#[MapQueryString] SearchQuery $query = new SearchQuery()`).

**`AbstractDoctrineSearchRepository` (base, `abstract`):**
- `getPaginatedResults(criteria)` → `getSearchQueryBuilder(criteria)` → auto-aplica `filterApplier->apply(qb, criteria->filters, searchFieldMap())` → pagina. Patrón seam: el repo declara `abstract protected searchFieldMap(): SearchFieldMap`, nunca filtra ad hoc. **El orden debe seguir el mismo patrón: `abstract protected sortFieldMap(): SortFieldMap`.**
- `addOrderByFromQueryParams(qb, string $alias, ?string $orderByField, ?SortDirection $direction)` (líneas ~118-131): HOY `$sort = $orderByField ?? QueryParam::CREATED_AT->value; … addOrderBy(sprintf('%s.%s', $alias, $sort), $order->value)`. **El `sprintf` interpola el campo: en cuanto `$orderByField` deje de ser `null` y venga de cliente es inyección DQL.** Reescribir para que el campo de cliente salga SIEMPRE del `sortFieldMap()` (ver Task 3).
- Es el ÚNICO subtipo concreto: `DoctrineBankRepository` (verificado `grep -rln "extends AbstractDoctrineSearchRepository"`). Por eso `sortFieldMap()` puede ser `abstract` sin romper a nadie.

**`DoctrineBankRepository`:** `getSearchQueryBuilder` crea `qb('b')`, llama `addOrderByFromQueryParams(qb, alias:'b', orderByField: null, direction: null)`, `addLimit`. `searchFieldMap()` ya devuelve `name`/`shortName`/`id`/`createdAt`/`updatedAt`. Inyecta `NormalizedTextFieldNormalizer` + `AsciiUpperTextFieldNormalizer`.

**`Paginator` (defensa en profundidad, NO la allow-list semántica):** lee las columnas de orden del DQL (`getOrderByColumns`) y las valida con `ORDER_BY_IDENTIFIER_PATTERN = /^[A-Za-z_]\w*(?:\.[A-Za-z_]\w*)*$/`; algo fuera del patrón → `InvalidArgumentException` (¡500!). Esto bloquea sintaxis peligrosa pero **NO** un campo válido-pero-no-permitido (`b.storedObjectContentHash` pasaría el regex y llegaría a DQL). Por eso la allow-list semántica (400 antes de tocar SQL) es imprescindible y va ANTES. Además: el cursor keyset codifica TODAS las columnas de orden; al cambiar `sort`, `buildCursorWhere` no encuentra los campos previos → devuelve `null` → cae a offset (degradación elegante). El `Paginator` ya añade `id ASC` como desempate, así que cualquier orden primario sigue siendo keyset-estable y determinista.

**Bank — entity e índices:** `#[ORM\Table(name:'bank')]` con `#[ORM\Index(name:'idx_bank_created_at', columns:['created_at'])]` y `idx_bank_updated_at` (de 1.7); `name_normalized` y `short_name` son UNIQUE (índice). → los 4 campos sortable ya están indexados (NFR4) → **sin migración**.

### Decisiones pineadas para los huecos del diseño (no re-decidir)

1. **`SortDirection` → Domain.** Enum puro sin deps; `SearchCriteria` (Domain) lo necesita y Domain no puede importar de Infrastructure. Moverlo (no duplicarlo) a `Shared/Domain/Search/SortDirection.php`; actualizar los 3 imports. *Alternativa aceptable* (si el revisor lo prefiere): un VO `Sort{field,direction}` en Domain. La AC pide dos campos sueltos → seguimos la AC; KISS.
2. **Token wire de `direction`:** `ASC`/`DESC` (MAYÚSCULAS = backing del enum existente). Consciente: difiere de los operadores de filtro (minúsculas, D2); se documenta. `asc`/`direction[]=…` → 400 `validation-failed` (lo da el mapping del enum, igual que `paginationMode`).
3. **Capas de validación (pin de 1.4, sin cambios):** shape (`direction` fuera del enum, forma array) → mapping → 400 `validation-failed`; semántica (`sort` fuera de la allow-list) → seam/repo → 400 familia `invalid-search-criteria`. Ninguna validación de orden en controller ni use case.
4. **`name` ordena por `b.nameNormalized`, no `b.name`.** Razón doble: (a) `name_normalized` está indexado (NFR4); `name` no → filesort. (b) Da orden alfabético case/diacrítico-insensible, que es el comportamiento esperado de la lista (y el que la PWA hace client-side). Documentar que el público `name` ordena por la forma normalizada.
5. **`UnknownSortField` nueva (no reusar `UnknownSearchField`).** "Clase = el fallo" (precedente del repo); type `unknown-sort-field`. Reusa el marker `InvalidSearchCriteria` → 400, sin marker nuevo (NFR26 sin coste de mapa). *Alternativa aceptable:* reusar `UnknownSearchField` (menos superficie) si el revisor lo prefiere — pero su title/type hablan de "search field", confuso para orden. Pin: clase nueva.
6. **`sortFieldMap()` `abstract`** (espejo de `searchFieldMap()`, fuerza decisión consciente). Bank es el único concreto. Si en el futuro aparece otro `AbstractDoctrineSearchRepository`, deberá declarar el suyo (deny-by-default: `new SortFieldMap([])` → cualquier `sort` de cliente → 400).
7. **`SortFieldMap` clase nueva, no reusar `SearchFieldMap`.** Filtrar y ordenar son allow-lists independientes (un campo puede ser ordenable y no filtrable y viceversa). `SortFieldMap` solo necesita nombre→path (sin operadores/normalizer/flags) → SRP/KISS. Junto a `SearchFieldMap` en `Doctrine/Search/`.
8. **Sin migración.** Los 4 campos ya tienen índice btree. Verificar con `EXPLAIN ANALYZE` (NFR4), no añadir índices nuevos.

### Riesgos conocidos / contingencias

- **Inyección por `sort`.** El `sprintf('%s.%s')` actual es el vector. Mitigación dura: el campo de cliente SIEMPRE pasa por `sortFieldMap()->pathFor()`; jamás se interpola crudo. Test Behat de 400 `unknown-sort-field` + revisión de que el único `sprintf` restante usa la constante `createdAt`.
- **`#[MapQueryString]` + enum opcional.** Confirmado por precedente (`paginationMode`): valor inválido → 400 `validation-failed`, no 500. `?SortDirection $direction = null` con default null para "sin dirección". Si el dev observara un 500 (no esperado), NO degradar a string+Choice sin más: replicar exactamente el patrón `paginationMode`.
- **Determinismo en Behat.** Las fixtures Alice se crean a la vez → `createdAt`/`updatedAt` comparten segundo; el orden por fecha se desempata por `id` (lo añade el Paginator) → determinista pinneando por `id` más bajo/alto. `name`/`shortName` son distintos → orden determinista directo. Calcular los `id` esperados desde las fixtures (mismo enfoque que los pins de id existentes).
- **NFR4 / filesort.** Ordenar por columna sin índice = filesort → regresión p95. Por eso `name`→`name_normalized` (indexado) y solo se exponen campos indexados. `EXPLAIN ANALYZE` documentado.
- **Cursor keyset y cambio de orden (Épica 2).** El cursor codifica las columnas de orden; cambiar `sort` lo invalida (cae a offset). La PWA (Story 2.2) DEBE descartar el cursor al cambiar el orden (regla aprendida del race debounce+paginación). Nota para 2.2; el server ya degrada con seguridad.
- **Default API ≠ default PWA.** Sin `sort` el API ordena `createdAt` ASC; la PWA por defecto muestra `name` ASC (`DEFAULT_SORT` en `banksFilterSort.ts`). No es bug del API: 2.2 enviará `sort=name&direction=ASC` explícito. Documentar el default del API.

### Testing

- **Unit puro (sin contenedor/BD):** `SearchCriteriaTest` (+ sort/direction), `SearchQueryTest` (toCriteria traslada sort/direction; null→null), `SortFieldMapTest` (path/null), `UnknownSortFieldTest` (espejo de `UnknownSearchFieldTest`: type `unknown-sort-field`, context `{field}`, sin el valor en el title). `SortDirection` movido: si aporta, un test mínimo que pinee los backing `ASC`/`DESC`.
- **Behat (end-to-end, real Postgres):** primer cubridor del path HTTP→repo→DB con aserciones de nº de queries. Es la cobertura principal del orden y de los 400. No hay test funcional de repo de ordenación previo (`grep` confirma) → Behat manda; los trozos puros se cubren unit.
- **NFR4:** `EXPLAIN ANALYZE` en Completion Notes (con `enable_seqscan=off` si el dataset dev es pequeño, como 1.7).

#### Estilo/escenarios Behat (copiar de los existentes)

```gherkin
# Story 1.8: orden server-side. sort se valida contra la allow-list de orden del repo
# (name/shortName/createdAt/updatedAt → b.*). direction es enum ASC/DESC (mayúsculas).
Scenario: Sorting by name ascending orders banks by normalized name
  When I send a "GET" request to "/backoffice/banks?sort=name&direction=ASC&limit=1"
  Then the response status code should be 200
  And the JSON node "data" should have 1 elements
  # And the JSON node "data[0].id" should be equal to "<id del primer banco por nombre normalizado>"
  And 2 requests got executed only for doctrine connection "default"

Scenario: Sorting by name descending reverses the order
  When I send a "GET" request to "/backoffice/banks?sort=name&direction=DESC&limit=1"
  Then the response status code should be 200
  # And the JSON node "data[0].id" should be equal to "<id del último banco por nombre normalizado>"

Scenario: Sorting by a field outside the sort allow-list returns 400 unknown-sort-field
  When I send a "GET" request to "/backoffice/banks?sort=id&direction=ASC"
  Then the response status code should be 400
  And the header "Content-Type" should be equal to "application/problem+json"
  And the JSON node "type" should be equal to "unknown-sort-field"
  And the JSON node "status" should be equal to the number 400
  And 0 requests got executed across all doctrine connections

Scenario: An invalid sort direction returns 400 validation-failed
  When I send a "GET" request to "/backoffice/banks?sort=name&direction=sideways"
  Then the response status code should be 400
  And the JSON node "type" should be equal to "validation-failed"
  And 0 requests got executed across all doctrine connections

Scenario: Without sort the default order (createdAt asc, id tiebreak) is unchanged
  When I send a "GET" request to "/backoffice/banks?limit=1"
  Then the response status code should be 200
  And the JSON node "data" should have 1 elements
  # And the JSON node "data[0].id" should be equal to "<id más bajo; fixtures comparten createdAt → desempata id>"
```

(Los pasos `sort`/`shortName`/`createdAt`/`updatedAt` (asc/desc) se replican variando el campo. El paso vive en `api/tests/Behat/Context/HttpRequestContext.php`; aserciones `type`/`status` siguen el contrato RFC 9457.)

### Seguridad (checklist CLAUDE.md aplicado a esta historia)

- **Injection (la clave):** el `sort` de cliente NUNCA se interpola en DQL; se resuelve a un path de la allow-list por construcción. El único `sprintf('%s.%s')` restante usa la constante `createdAt`. `direction` es un enum cerrado. Defensa en profundidad extra: el regex del `Paginator`. ✔
- **Input validation:** shape (`direction` enum, forma) → mapping → 400; semántica (`sort` permitido) → seam → 400. ✔
- **AuthN/AuthZ:** sin cambios; endpoint de banks público hoy (deferred-work). No se expone ningún campo nuevo: ordenar por `name`/`shortName`/`createdAt`/`updatedAt` (ya serializados) no filtra datos. ✔
- **Mass assignment / output:** sin campos nuevos expuestos; respuesta JSON-only RFC 9457 en errores. ✔
- **Error contract (NFR26):** sin marker nuevo (reusa `InvalidSearchCriteria`); documentar el disparador `unknown-sort-field` y verificar `make php.lint.error-contract` + `MarkerStatusMapContractTest` + `api/features/shared/error_contract` verdes. ✔
- **Migración:** ninguna (índices ya existen). ✔
- **DoS/perf:** orden solo sobre columnas indexadas (NFR4); caps de paginación intactos. ✔

### Project Structure Notes

Delta esperado (sobre `feat/shared-search-filters-aj0w` @ e0d8794):

```
api/src/Shared/Infrastructure/Persistence/SortDirection.php                       [DELETE] (movido)
api/src/Shared/Domain/Search/SortDirection.php                                    [N] enum movido a Domain
api/src/Shared/Domain/Search/SearchCriteria.php                                   [M] +sort, +direction
api/src/Shared/Domain/Search/Exception/UnknownSortField.php                       [N] type unknown-sort-field
api/src/Shared/Application/Http/Search/SearchQuery.php                            [M] +sort, +direction, toCriteria
api/src/Shared/Infrastructure/Persistence/Doctrine/Search/SortFieldMap.php        [N] allow-list de orden
api/src/Shared/Infrastructure/Persistence/Doctrine/AbstractDoctrineSearchRepository.php [M] abstract sortFieldMap() + addOrderByFromQueryParams allow-list + import SortDirection desde Domain
api/src/Shared/Infrastructure/Persistence/Doctrine/Paginator.php                  [M] import SortDirection desde Domain (solo el use)
api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/DoctrineBankRepository.php  [M] propaga sort/direction + sortFieldMap()
api/tests/Unit/Shared/Domain/Search/SearchCriteriaTest.php                        [M]
api/tests/Unit/Shared/Application/Http/Search/SearchQueryTest.php                 [M]
api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/SortFieldMapTest.php  [N]
api/tests/Unit/Shared/Domain/Search/Exception/UnknownSortFieldTest.php            [N]
api/features/backoffice/bank/search.feature                                      [M] escenarios de orden
api/docs/adding-endpoints.md                                                     [M] sort/direction + allow-list
docs/architecture-api.md                                                         [M] receta sortFieldMap()
docs/api-error-contract.md                                                       [M] disparador unknown-sort-field
```

Sin migración. `api/config/reference.php` se regenera solo (no commitear cambios espurios).

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 1.8 (worktree, líneas 351–379)] — AC de orden, allow-list, marker reusado.
- [Source: _bmad-output/planning-artifacts/epics.md#Nota de decisión 2026-06-08] — 1.7/1.8 cierran el hueco de contrato para la Épica 2; prerequisito de 2.2.
- Código verificado (worktree `feat/shared-search-filters-aj0w` @ e0d8794): `AbstractDoctrineSearchRepository.php` (addOrderByFromQueryParams líneas 118-131; seam de filtros), `DoctrineBankRepository.php` (getSearchQueryBuilder, searchFieldMap), `SearchQuery.php` (paginationMode enum + toCriteria), `SearchCriteria.php`, `SortDirection.php`, `Paginator.php` (ORDER_BY_IDENTIFIER_PATTERN, keyset cursor), `QueryParam.php`, `Bank.php` (#[ORM\Index] created_at/updated_at), `search.feature` (escenarios 400 y de pins de id), `UnknownSearchField.php`/`InvalidSearchValue.php`/`InvalidSearchCriteria.php` (patrón excepción→400), `BankSearchController.php` (#[MapQueryString]).
- [Source: _bmad-output/implementation-artifacts/1-5-camino-unico-legacy-filters-en-bank-d8.md] — `SearchQuery`/`SearchCriteria` `final` sin `ids`; capas de validación.
- [Source: docs/project-context.md] — PHP 8.5, dominio puro (Domain no importa Infrastructure), testing (Postgres real, mothers nombrados), gates.
- [Source: docs/api-error-contract.md] — familia `InvalidSearchCriteria` → 400; gate `make php.lint.error-contract`.
- [Source: pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts] — orden client-side actual (`DEFAULT_SORT = {columnId:'name', direction:ASC}`); equivalencia a verificar en Story 2.2.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Claude Opus 4.8, 1M context) vía workflow `bmad-dev-story`.

### Debug Log References

- Partida verde en el worktree antes de tocar nada: `php.unit` 650 OK (3 skipped), `php.behat` 101 escenarios / 710 pasos OK.
- RED Task 1: 6 errores (`SortDirection` no en Domain, named param `$sort` desconocido). RED Task 2: 5 errores + 1 fallo (clases ausentes). Ambos pasaron a verde tras el GREEN.
- Behat de orden: primera pasada 58/4. Fallos diagnosticados vía `(on line N)` = paso fallido + `curl` directo al endpoint vivo: (a) `sort=name` → **500 `NoSuchPropertyException` "nameNormalized"** (el cursor keyset lee la columna de orden por PropertyAccess y `nameNormalized` no tenía accessor) → fix: `Bank::getNameNormalized()`; (b) `createdAt`/`updatedAt` DESC → `001`, no `031` (las fixtures comparten instante → desempate id ASC domina en ambas direcciones) → rediseño del escenario temporal a "ejecuta en ambas direcciones" (200 + 31).
- `php.quality`: PHPMD `ElseExpression` en `addOrderByFromQueryParams` (refactor a ternaria); Psalm `PossiblyUnusedMethod` en `getNameNormalized` (uso solo reflexivo) → congelado en `psalm-baseline.xml`, junto a `getName`/`getShortName` (mismo precedente). Rector auto-reescribió `assertNull(...->direction)` → `assertNotInstanceOf(SortDirection::class, ...)` en 2 tests (evita `method.alreadyNarrowedType`).

### Completion Notes List

- **Contrato:** `SearchQuery`/`SearchCriteria` ganan `sort` (`?string`, `Assert\Length(max:64)`) y `direction` (`?SortDirection`), ambos al final con default `null` → retrocompatibilidad total; `toCriteria()` los traslada. `SortDirection` movido a `Shared/Domain/Search` (enum puro, válido en Domain); 3 imports actualizados; `QueryParam` permanece en Infrastructure.
- **Seguridad (inyección):** el `sort` de cliente se resuelve SIEMPRE vía `SortFieldMap::pathFor()` o lanza `UnknownSortField` (400, familia `InvalidSearchCriteria`, type `unknown-sort-field`, sin marker nuevo) antes de tocar SQL. El único `sprintf('%s.%s')` restante usa la constante `createdAt`. `direction` inválida/forma array → 400 `validation-failed` en mapping (igual que `paginationMode`).
- **Allow-list de orden de Bank:** `{ name → b.nameNormalized, shortName → b.shortName, createdAt → b.createdAt, updatedAt → b.updatedAt }`; `id` deliberadamente fuera. `name` ordena por la forma normalizada (indexada, case/diacrítico-insensible).
- **Desviación de diseño (no anticipada en la historia):** ordenar por `b.nameNormalized` exige que el paginador keyset pueda LEER esa columna del entity por PropertyAccess. `nameNormalized` era privada sin getter → 500. Fix mínimo: `Bank::getNameNormalized()` SIN grupo serializador (no se filtra al JSON; verificado por el escenario "5 children" intacto). Documentado en `architecture-api.md` como nota de receta + anti-pattern ("sortear por un campo sin accessor").
- **NFR4 (sin migración):** `EXPLAIN ANALYZE` (con `SET enable_seqscan=off`, dataset dev pequeño): `ORDER BY name_normalized ASC, id ASC` → `Index Scan using uniq_…e1b35095` (Presorted Key: name_normalized); `ORDER BY created_at DESC, id ASC` → `Index Scan Backward using idx_bank_created_at`. Sin filesort/Seq Scan; los 4 campos ya estaban indexados.
- **Cobertura:** unit (`SortDirectionTest`, `SortFieldMapTest`, `UnknownSortFieldTest`, + `SearchCriteriaTest`/`SearchQueryTest` ampliados); behat `search.feature` +12 escenarios (name/shortName asc-desc con pin de id; createdAt/updatedAt asc-desc 200+31; 400 `unknown-sort-field` por `sort=id`; 400 `validation-failed` por `direction=sideways` y `=asc`; default sin sort). Gates finales: `php.stan` ✓, `php.quality` ✓, `php.psalm` ✓, `php.lint.error-contract` ✓ (5 tests, incl. `MarkerStatusMapContractTest`), `php.unit` ✓ (660), `php.behat` ✓ (113 escenarios / 765 pasos).
- **Nota para Story 2.2:** el default del API (`createdAt` ASC) difiere del default PWA (`name` ASC) → la lista server-driven debe enviar `sort=name&direction=ASC` explícito y descartar el cursor al cambiar el orden (el cursor codifica las columnas de orden; el server degrada a offset con seguridad).
- `api/config/reference.php` se regenera solo: NO se commitea ese cambio espurio.

### File List

**Nuevos (src):**
- `api/src/Shared/Domain/Search/SortDirection.php` (movido desde Infrastructure)
- `api/src/Shared/Domain/Search/Exception/UnknownSortField.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/SortFieldMap.php`

**Modificados (src):**
- `api/src/Shared/Domain/Search/SearchCriteria.php` (+sort, +direction)
- `api/src/Shared/Application/Http/Search/SearchQuery.php` (+sort, +direction, +MAX_SORT_LENGTH, toCriteria)
- `api/src/Shared/Infrastructure/Persistence/Doctrine/AbstractDoctrineSearchRepository.php` (abstract sortFieldMap() + reescritura de addOrderByFromQueryParams + import SortDirection desde Domain)
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Paginator.php` (import SortDirection desde Domain)
- `api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/DoctrineBankRepository.php` (propaga sort/direction + sortFieldMap())
- `api/src/Backoffice/Bank/Domain/Entity/Bank.php` (+getNameNormalized() sin grupo serializador)

**Eliminado (src):**
- `api/src/Shared/Infrastructure/Persistence/SortDirection.php` (movido a Domain)

**Tests:**
- `api/tests/Unit/Shared/Domain/Search/SortDirectionTest.php` (nuevo)
- `api/tests/Unit/Shared/Domain/Search/Exception/UnknownSortFieldTest.php` (nuevo)
- `api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/SortFieldMapTest.php` (nuevo)
- `api/tests/Unit/Shared/Domain/Search/SearchCriteriaTest.php` (ampliado)
- `api/tests/Unit/Shared/Application/Http/Search/SearchQueryTest.php` (ampliado)
- `api/features/backoffice/bank/search.feature` (+12 escenarios de orden)

**Docs / config:**
- `api/docs/adding-endpoints.md`, `docs/architecture-api.md`, `docs/api-error-contract.md` (sort/direction + sortFieldMap + unknown-sort-field)
- `api/tools/psalm/psalm-baseline.xml` (+getNameNormalized PossiblyUnusedMethod)
- `_bmad-output/implementation-artifacts/{1-8-…md, sprint-status.yaml}` (artefactos bmad)
- `api/config/reference.php` — auto-generado; NO commitear.

## Change Log

- 2026-06-08: Historia creada (create-story). Alcance: ordenación server-side `sort`/`direction` en el contrato de búsqueda genérico, con allow-list de orden por repositorio (`SortFieldMap`) análoga a `searchFieldMap`, reusando el marker `InvalidSearchCriteria` (type nuevo `unknown-sort-field`). `SortDirection` relocalizado a Domain. Sin migración (campos ya indexados). Baseline pineado a `feat/shared-search-filters-aj0w` @ e0d8794 (PR #180, Story 1.7 done). Prerequisito de Story 2.2.
- 2026-06-08: Implementación completada (dev-story). `sort`/`direction` en el contrato, `SortFieldMap` + `UnknownSortField` (type `unknown-sort-field`, sin marker nuevo), resolución de orden en el seam base con allow-list obligatoria (cero interpolación de input en DQL), wiring de Bank. Desviación de diseño descubierta y resuelta: `Bank::getNameNormalized()` (sin grupo serializador) para que el cursor keyset lea la columna de orden. NFR4 verificado por `EXPLAIN ANALYZE` (Index Scan, sin filesort), sin migración. Docs (adding-endpoints, architecture-api, api-error-contract) y `psalm-baseline.xml` actualizados. Todos los gates en verde (`php.quality`, `php.unit` 660, `php.behat` 113). Status → review.
