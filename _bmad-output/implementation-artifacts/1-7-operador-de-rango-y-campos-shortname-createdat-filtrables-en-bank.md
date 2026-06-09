---
baseline_branch: feat/shared-search-filters-aj0w
baseline_commit: 5336790
note_baseline: >-
  Esta historia NO se ramifica de `main`. El mecanismo de filtros completo (historias 1.1–1.6)
  vive exclusivamente en la rama no fusionada `feat/shared-search-filters-aj0w` (commit 5336790).
  En `main` (39f609f) `Shared/Domain/Search/` solo contiene PaginatedResult/PaginationMode/
  SearchCriteria/SearchCursor — nada del vocabulario de filtros. Ver "Prerrequisito de baseline".
note_scope: >-
  Historia NUEVA, no presente en epics.md (que acota la Épica 1 a 1.1–1.6). Ampliación de alcance
  decidida por Sergio el 2026-06-08. epics.md / correct-course deberían formalizarla; este fichero
  es la fuente de verdad para el dev.
---

# Story 1.7: Operadores de rango temporal (gt/gte/lt/lte) sobre createdAt/updatedAt en banks

Status: done

_Ultimate context engine analysis completed — guía exhaustiva creada contra el estado real verificado de la rama `feat/shared-search-filters-aj0w` @ 5336790 (2026-06-08)._

## Story

As a consumidor de la API de banks,
I want filtrar por rango temporal sobre `createdAt`/`updatedAt` con los operadores genéricos `gt`/`gte`/`lt`/`lte`,
so that pueda acotar resultados por fecha (p. ej. "creados a partir de X", "actualizados antes de Y", o un rango cerrado componiendo dos filtros) reutilizando el mismo contrato `filters[]` sin parámetros ad hoc.

## Acceptance Criteria

**Given** el enum `Erpify\Shared\Domain\Search\FilterOperator` (hoy `eq`/`in`/`contains`)
**When** se añaden los casos `Gt = 'gt'`, `Gte = 'gte'`, `Lt = 'lt'`, `Lte = 'lte'`
**Then** los backing strings minúsculos son el contrato wire (D2) y `FilterOperatorTest::testEveryOperatorIsPinnedByAWireToken` queda en verde con los 7 casos pineados en su data provider
**And** no se añade `between` — un rango cerrado se expresa con dos filtros sobre el mismo campo (`gte` + `lte`), que ya componen con AND por construcción (ver Story 1.4 "Two generic filters on the same field compose with AND"); esta equivalencia se documenta en lugar de añadir un operador redundante (NFR1/YAGNI).

**Given** el VO `Filter` y el DTO `FilterQuery`
**When** se añaden los named constructors `Filter::gt/gte/lt/lte(string $field, string $value): self` (valor escalar string) y se enrutan los 4 operadores nuevos en los dos `match` exhaustivos de `FilterQuery` (`validateValueShape()` → `validateScalarValue`, `toFilter()` → named constructor correspondiente)
**Then** un valor de rango es un único string (misma forma que `eq`/`contains`): no-vacío y ≤ `MAX_VALUE_LENGTH` (255) validado en mapping → 400 `validation-failed` + `violations[]`
**And** un valor de lista (`filters[N][value][]`) con un operador de rango produce 400 `validation-failed` (los operadores de rango son escalares).

**Given** `FieldMapping` (que hoy guarda `requiresUuidValues`)
**When** un campo declara que sus valores son temporales (patrón pineado: nuevo flag `requiresDateTimeValues` paralelo a `requiresUuidValues`, ver Decisiones pineadas) y lista solo operadores de rango
**Then** el constructor rechaza con `LogicException` la combinación `requiresDateTimeValues` + `Contains` (un `LIKE` sobre una columna timestamp rompe a nivel SQL, igual que el guard UUID existente)
**And** los campos `name`/`id` existentes NO listan los operadores de rango (su allow-list no los incluye), de modo que `filters[name][gt]` sigue devolviendo 400 `unsupported-search-operator` (regresión cubierta en Behat).

**Given** `FilterApplier` y su `match ($filter->operator)` exhaustivo
**When** se añaden las 4 ramas de rango (`Gt`/`Gte`/`Lt`/`Lte` → `>`/`>=`/`<`/`<=` sobre `mapping->dqlPath`)
**Then** el valor se vincula como parámetro **tipado** `Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE` (un string crudo contra una columna `timestamp` no tiene operador en Postgres → error 500; ver Riesgos), nunca interpolado, con el naming `xxh128` heredado
**And** cuando `requiresDateTimeValues`, cada valor se pre-valida como datetime ISO-8601/RFC 3339 (ATOM) estricto; un valor no parseable lanza `InvalidSearchValue::notADateTime(field, position)` → 400 familia `invalid-search-criteria` (nunca 22007/22008 → 500)
**And** `Filters` vacío sigue siendo no-op y varios filtros de rango sobre el mismo campo componen con AND.

**Given** `DoctrineBankRepository::searchFieldMap()`
**When** gana las entradas `'createdAt' => new FieldMapping('b.createdAt', operators: [Gt, Gte, Lt, Lte], requiresDateTimeValues: true)` y `'updatedAt' => new FieldMapping('b.updatedAt', …)`
**Then** `GET /api/v1/backoffice/banks?filters[0][field]=createdAt&filters[0][operator]=gte&filters[0][value]=2026-01-01T00:00:00%2B00:00` filtra correctamente
**And** los nombres públicos de campo son `createdAt`/`updatedAt` (las claves serializadas del grupo `timestamped`), nunca paths DQL
**And** el envelope de respuesta (items + pagination.cursor/hasMorePages) no cambia.

**Given** NFR4 (todo campo del field map respaldado por índice)
**When** se completa la historia
**Then** existe una migración (`make db.diff`) que añade índices btree sobre `bank(created_at)` y `bank(updated_at)`, declarados a nivel de `#[ORM\Table]` del entity `Bank` (NO en el trait compartido `Timestamped`, para no indexar todas las entidades timestamped)
**And** `EXPLAIN ANALYZE` sobre la query de rango usa Index/Bitmap Scan (no Seq Scan) y p95 del listado no regresa.

**Given** `api/features/backoffice/bank/search.feature` (extendido, nunca feature paralela)
**When** corre la suite Behat
**Then** cubre: rango feliz (`gte`/`gt`/`lt`/`lte` sobre createdAt/updatedAt), rango cerrado `gte`+`lte` AND, 400 `unsupported-search-operator` (`gt` sobre `name`), 400 `invalid-search-value` (datetime malformado sobre createdAt), 400 `validation-failed` (valor lista con `gt`)
**And** los escenarios existentes siguen en verde.

**Given** los gates de cierre
**When** se completa la historia
**Then** `make php.stan` + `make php.psalm` (por archivo tocado), `make php.quality`, `make php.behat`, `make php.unit` en verde
**And** `docs/architecture-api.md` (receta), `api/docs/` (gramática wire: operadores `gt|gte|lt|lte`, formato ISO-8601, allow-list por campo) y `docs/api-error-contract.md` (nuevo disparador de `invalid-search-value` por datetime) quedan consistentes en el mismo PR.

## Tasks / Subtasks

- [x] Task 0 — Prerrequisito de baseline y rama (AC: todos)
  - [x] Confirmar que se trabaja SOBRE `feat/shared-search-filters-aj0w` (commit 5336790), no sobre `main`. Reutilizar el worktree existente `.claude/worktrees/shared-search-filters-aj0w/` (nuevo commit en esa rama) o crear una rama apilada sobre ella. NUNCA ramificar de `main` (el mecanismo no existe allí).
  - [x] Verificar el estado de partida (debe estar verde): `make php.unit`, `make php.behat` en el worktree.

- [x] Task 1 — RED: enum + VO + tests de dominio puro (AC 1, 2)
  - [x] Añadir `Gt='gt'`, `Gte='gte'`, `Lt='lt'`, `Lte='lte'` a `FilterOperator` (`api/src/Shared/Domain/Search/FilterOperator.php`).
  - [x] Añadir los 4 wire tokens al data provider de `FilterOperatorTest` (`tests/Unit/Shared/Domain/Search/FilterOperatorTest.php`) — `testEveryOperatorIsPinnedByAWireToken` falla hasta hacerlo (red de seguridad de exhaustividad).
  - [x] Añadir named constructors `Filter::gt/gte/lt/lte` (escalar string) en `api/src/Shared/Domain/Search/Filter.php` + casos en `FilterTest` y, si aporta, `FilterMother::gt(...)`.

- [x] Task 2 — GREEN: shape validation y mapeo a dominio en el borde HTTP (AC 2)
  - [x] En `api/src/Shared/Application/Http/Search/FilterQuery.php`, enrutar los 4 operadores nuevos:
    - `validateValueShape()` (`match` exhaustivo) → `validateScalarValue($context)` (junto a `Eq, Contains`).
    - `toFilter()` (`match` exhaustivo) → `Filter::gt/gte/lt/lte($this->field, $this->scalarValue())`.
  - [x] Ampliar `FilterQueryTest`: aceptar `gt/gte/lt/lte` con string válido; rechazar valor lista; mapear cada operador a su named constructor.

- [x] Task 3 — GREEN: FieldMapping (allow-list + guard temporal) (AC 3)
  - [x] Añadir `requiresDateTimeValues` a `FieldMapping` (`api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FieldMapping.php`) paralelo a `requiresUuidValues`; guard: `requiresDateTimeValues && Contains ∈ operators` → `LogicException`. (Alternativa aceptable si el dev/revisor lo prefiere por limpieza: refactorizar ambos flags a un `enum ValueKind { Text, Uuid, DateTime }` — ver Decisiones pineadas; mantener PHPMD/clean-code feliz manda.)
  - [x] Ampliar `FieldMappingTest`: el guard temporal rechaza Contains; un campo datetime permite los 4 operadores de rango.

- [x] Task 4 — GREEN: FilterApplier (ramas de rango + binding tipado + pre-validación datetime) (AC 4)
  - [x] Añadir 4 ramas al `match ($filter->operator)` de `applyFilter()` (`api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FilterApplier.php`) → métodos `gtCondition`/`gteCondition`/`ltCondition`/`lteCondition` que producen `b.path > :p` etc.
  - [x] Vincular el valor como `Types::DATETIME_IMMUTABLE` (parsear el string validado a `DateTimeImmutable`). Recomendación de diseño: extender la tupla del match a `[$condition, $value, $type]` (`$type` = nombre de tipo Doctrine o `null` para los operadores existentes) y pasar el 3º arg a `setParameter`. El dev decide la forma exacta; la INVARIANTE pineada es: rango → parámetro tipado datetime, jamás string crudo contra columna timestamp.
  - [x] Cuando `requiresDateTimeValues`: pre-validar cada valor como ISO-8601/RFC 3339 ATOM estricto (rechazar formatos laxos/relativos); fallo → `InvalidSearchValue::notADateTime($field, $position)`. Añadir el named constructor `notADateTime` a `api/src/Shared/Domain/Search/Exception/InvalidSearchValue.php` (mismo patrón que `notAUuid`: context `{field, position}`, nunca el valor).

- [x] Task 5 — GREEN: field map de Bank + migración de índices (AC 5, 6)
  - [x] Añadir `'createdAt'` y `'updatedAt'` a `DoctrineBankRepository::searchFieldMap()` (`operators: [Gt, Gte, Lt, Lte]`, `requiresDateTimeValues: true`, sin normalizer).
  - [x] Declarar índices a nivel `#[ORM\Table(name: 'bank', indexes: [...])]` en el entity `Bank` sobre las columnas `created_at` y `updated_at`; generar la migración con `make db.diff` y aplicarla con `make db.migrate`. (Editar solo la migración nueva de esta rama; nunca las fusionadas.)

- [x] Task 6 — Integración real Postgres (AC 4, 5, 6)
  - [x] Ampliar `FilterApplierTest` (`tests/Functional/Shared/Persistence/FilterApplierTest.php`) contra Postgres real (nunca SQLite): correctitud de cada operador y frontera `gt` vs `gte` (inclusividad), binding tipado, datetime malformado → `InvalidSearchValue::notADateTime`, y precisión/zona (ver Riesgos).
  - [x] `EXPLAIN ANALYZE` de la query de rango → Index/Bitmap Scan sobre el índice nuevo; registrar el plan en Completion Notes (NFR4).

- [x] Task 7 — Behat (AC 7)
  - [x] Extender `api/features/backoffice/bank/search.feature` con los escenarios del AC 7 siguiendo el estilo Gherkin existente (ver Dev Notes → "Estilo Behat"). Mantener verdes los escenarios actuales y respetar el aislamiento de datos por fixtures.

- [x] Task 8 — Documentación + gates (AC 8)
  - [x] `docs/architecture-api.md`: ampliar la receta de "búsqueda filtrable" — el set de operadores ahora incluye rango; cómo declarar un campo tipado (`requiresDateTimeValues`); la equivalencia `gte`+`lte` ≡ rango cerrado (por qué no hay `between`).
  - [x] `api/docs/` (doc del endpoint de banks): operadores `gt|gte|lt|lte`, formato ISO-8601/RFC 3339 del valor, allow-list por campo (createdAt/updatedAt solo rango). Postman: ejemplo opcional.
  - [x] `docs/api-error-contract.md`: añadir el datetime malformado como disparador del type `invalid-search-value` (no hay marker nuevo → `MarkerStatusMapContractTest` y `make php.lint.error-contract` no cambian, pero verificar que siguen verdes).
  - [x] Gates: `make php.stan` + `make php.psalm` (por archivo), `make php.quality`, `make php.behat`, `make php.unit` en verde.

### Review Findings

_Code review adversarial (3 lentes: Blind Hunter · Edge Case Hunter · Acceptance Auditor) — 2026-06-09. Alcance: feature 1.7 (`e0d8794` + limpiezas `4885b85`, `2545085`). **Veredicto: 8/8 ACs satisfechos; sin hallazgos Critical/High/Medium de comportamiento.** El Edge Case Hunter verificó empíricamente sobre PHP 8.5.7 que los casos límite de parseo (`now`/relativos/date-only/byte nulo/leap second/offset fuera de rango/año>9999/precisión) se rechazan correctamente (400 `invalid-search-value`, nunca 500). 1 decisión · 3 parches de pulido (opcionales) · 3 diferidos · 9 descartados como ruido._

**Decisión (resuelta por Sergio → endurecer el guard → aplicada):**

- [x] [Review][Patch] Guard de offset endurecido a [−12h, +14h] — `FilterApplier::parseStrict` ahora rechaza por separado offsets `> +14h` y `< −12h` (antes `abs > 14h` admitía los inexistentes −13/−14h). Constantes `MAX_UTC_OFFSET_EAST_SECONDS` / `MIN_UTC_OFFSET_WEST_SECONDS`; nuevo caso de provider `-13:00` en `FilterApplierTemporalRangeTest`. [FilterApplier.php]

**Parches (aplicados):**

- [x] [Review][Patch] Documentada la precisión de segundo en los bounds (input fraccionario truncado al segundo en `TIMESTAMP(0)`; >6 dígitos fraccionarios rechazados) [docs/architecture-api.md, api/docs/adding-endpoints.md]
- [x] [Review][Patch] `testAcceptedRangeBoundsBindAsTypedUtcDateTimeImmutable` reforzado con dos instantes distintos + aserción de conjunto por-parámetro [FilterApplierTemporalRangeTest.php]
- [x] [Review][Patch] Cobertura de inclusividad sobre `updatedAt` añadida — plegada en los tests gte/gt existentes (no método nuevo, evita PHPMD `TooManyPublicMethods`) [FilterApplierTemporalRangeTest.php]

**Diferidos:**

- [x] [Review][Defer] Endurecer `parseStrict` con reparse round-trip (`$dt->format($fmt) === $value`) para no depender de `getLastErrors()` ni de la adyacencia de estado global — verificado funcionalmente correcto sobre PHP 8.5.7, robustez opcional [FilterApplier.php] — deferred
- [x] [Review][Defer] `down()` de la migración con `DROP INDEX IF EXISTS`; los índices son solo-rendimiento y sin cobertura por test de comportamiento (aceptable) [Version20260608165844.php] — deferred
- [x] [Review][Defer] Guards defensivos de `FieldMapping` (`requiresDateTimeValues`+`eq`/`in`; `requiresUuidValues`+`requiresDateTimeValues`) — ya registrados en `deferred-work.md`, inalcanzables desde el wire de banks — deferred

## Dev Notes

### Contexto y alcance (leer antes de tocar nada)

- Historia de **ampliación** de la Épica 1. El mecanismo de filtros genérico (`filters[N][field/operator/value]`) ya está completo (1.1–1.6, todas `done`) y soporta `eq`/`in`/`contains`. Esta historia añade **operadores de rango temporal** `gt`/`gte`/`lt`/`lte` con un **consumidor real**: filtrar banks por `createdAt`/`updatedAt` (únicos campos de Bank ya expuestos —grupo serializador `timestamped`— con necesidad plausible de rango). Decisión de Sergio (2026-06-08).
- **Fuera de alcance (no especular — FR1/NFR1, "solo operadores con consumidor real"):**
  - `between` (redundante con `gte`+`lte` AND; documentar la equivalencia es el entregable, no el operador).
  - Rango sobre `storedObjectByteSize` (INTEGER): NO está en ningún grupo serializador → ningún cliente lo ve → sin consumidor real. Excluido salvo que se exponga primero.
  - Rango lexicográfico sobre `name`/`shortName`: sin consumidor.
  - PWA/fase 2 (es Épica 2); OR/grupos; otros tipos de valor (numérico, etc.).
- **Esta historia NO está en `epics.md`** (que acota la Épica 1 a 1.1–1.6). Idealmente formalizar vía `correct-course` o añadiendo la sección a `epics.md`; este fichero es la fuente de verdad para implementar.

### Prerrequisito de baseline (BLOQUEANTE)

- El código que esta historia extiende **no está en `main`**. Verificado 2026-06-08:
  - `main` @ `39f609f`: `api/src/Shared/Domain/Search/` = solo `PaginatedResult.php`, `PaginationMode.php`, `SearchCriteria.php` (versión antigua, con `ids`, no `final`), `SearchCursor.php`. **No existe `FilterOperator`, `Filter`, `Filters`, ni `Exception/`, ni `Doctrine/Search/`.**
  - worktree `feat/shared-search-filters-aj0w` @ `5336790`: mecanismo completo (1.1–1.6).
- **Implicación:** desarrollar SOBRE `feat/shared-search-filters-aj0w` (nuevo commit) o una rama apilada sobre ella. Si la Épica 1 se fusiona a `main` antes de empezar, ramificar de `main` entonces. Ramificar de `main` hoy = los ficheros a editar no existen.
- Los ficheros de historia 1.5/1.6 y `sprint-status.yaml` figuran como `??` (untracked) en el checkout principal: el trabajo de la Épica 1 aún no se ha consolidado/fusionado.

### Estado actual del código (verificado 2026-06-08 en el worktree)

**Los tres `match` exhaustivos sobre `FilterOperator` (sin `default`) — el sistema de tipos obliga a tocar los tres al añadir casos:**

| Fichero | Sitio | Qué hacer |
|---|---|---|
| `Shared/Infrastructure/Persistence/Doctrine/Search/FilterApplier.php` | `applyFilter()` `match ($filter->operator)` | +4 ramas → `*Condition()` con binding tipado datetime |
| `Shared/Application/Http/Search/FilterQuery.php` | `validateValueShape()` `match` | enrutar los 4 a `validateScalarValue` |
| `Shared/Application/Http/Search/FilterQuery.php` | `toFilter()` `match` | +4 → `Filter::gt/gte/lt/lte(...)` |

Además, red de seguridad de pin: `FilterOperatorTest::testEveryOperatorIsPinnedByAWireToken` compara contra `FilterOperator::cases()` → falla si no añades los tokens al provider.

**Vocabulario de dominio (`Shared/Domain/Search/`):**
- `FilterOperator` (backed enum): `Eq='eq'`, `In='in'`, `Contains='contains'`. Único punto de extensión.
- `Filter` (`final readonly`): constructor privado + named constructors `eq`/`in`/`contains`; `value` es `string|list<string>`; transporta el **nombre público** del campo (nunca path DQL).
- `Filters` (`final readonly`, Countable/IteratorAggregate): conserva todos los filtros (varios sobre el mismo campo → AND aguas abajo); `none()`, `fromList()`, `isEmpty()`, `all()`.
- `SearchCriteria` (`final readonly`): `cursor/page/limit/paginationMode/filters` — **sin `ids`** (retirado en 1.5). Filtrado solo vía `filters`.
- `Exception/`: `UnknownSearchField` (type `unknown-search-field`), `UnsupportedSearchOperator` (`unsupported-search-operator`), `InvalidSearchValue` (`invalid-search-value`, named constructor `notAUuid(field, position)` — context `{field, position}`, nunca el valor). Las tres `extends DomainException implements InvalidSearchCriteria` (marker → 400).

**Borde HTTP (`Shared/Application/Http/Search/`):**
- `SearchQuery` (`final readonly`): `filters` (`array<int, FilterQuery>`), `MAX_FILTERS=20`, `#[Assert\Valid]` + `#[Assert\Count(max)]`, callback `validateFilterIndexes` (lista contigua desde 0), `toCriteria()` → `domainFilters()` mapea cada `FilterQuery::toFilter()`. **Sin subclases** (banks usa este DTO directamente).
- `FilterQuery` (`final readonly`): `field` (`#[Assert\NotBlank]`), `operator` (`?FilterOperator` `#[Assert\NotNull]`), `value` (`string|array<mixed>`). `MAX_IN_VALUES=100`, `MAX_VALUE_LENGTH=255`. `#[Assert\Callback] validateValueShape()` (escalar para eq/contains, lista para in). `toFilter()`.

**Infraestructura (`Shared/Infrastructure/Persistence/Doctrine/Search/`):**
- `FieldMapping` (`final readonly`): `dqlPath`, `?normalizer`, `private array $operators` (default `[Eq, In, Contains]`), `requiresUuidValues` (default false). Guard: `requiresUuidValues && Contains ∈ operators` → `LogicException`. `allows(FilterOperator): bool`. Lleva `@SuppressWarnings("PHPMD.BooleanArgumentFlag")`.
- `SearchFieldMap` (`final readonly`): `array<string, FieldMapping>` por nombre público; `mappingFor(field): ?FieldMapping`.
- `FieldNormalizer` (interface `normalize(string): string`) + `NormalizedTextFieldNormalizer` (envuelve `NormalizedText::normalize`). **Nota:** el contrato normalizer→string NO encaja para datetime (necesitamos `DateTimeImmutable` + tipo Doctrine) → el manejo temporal va por el flag `requiresDateTimeValues` + binding tipado en el applier, no por un normalizer.
- `FilterApplier` (`final readonly`): `apply(QueryBuilder, Filters, SearchFieldMap): void`. Por filtro: resuelve mapping (`?? throw UnknownSearchField`), comprueba `allows` (`throw UnsupportedSearchOperator`), si `requiresUuidValues` → `ensureUuidValues()`, luego `applyFilter()`. `eqCondition`=`path = :p`; `inCondition`=`path IN (:p)` (lista, no-vacía); `containsCondition`=`%escape%` + `LIKE` (o `LOWER(path) LIKE LOWER(:p)` sin normalizer). `uniqueParameterName()` = `'p'.hash('xxh128', $qb->getDQL()).count(params)` (mismo esquema que `AbstractDoctrineRepository::generateUniqueParameterName`). `setParameter()` actual SIN tipo → para rango hay que pasar `Types::DATETIME_IMMUTABLE`.
- Seam auto-apply: `AbstractDoctrineSearchRepository::getPaginatedResults()` llama `filterApplier->apply($qb, $criteria->filters, $this->searchFieldMap())` entre el QB del repo y la paginación. Los repos NUNCA invocan el applier; solo declaran `abstract protected function searchFieldMap(): SearchFieldMap`.

**Bank (`Backoffice/Bank/`):**
- `DoctrineBankRepository extends AbstractDoctrineSearchRepository`: inyecta `FilterApplier` + `NormalizedTextFieldNormalizer`. `searchFieldMap()` actual: `'name' => FieldMapping('b.nameNormalized', $this->normalizedText)`; `'id' => FieldMapping('b.id', operators: [Eq, In], requiresUuidValues: true)`. `getSearchQueryBuilder()` no filtra ad hoc (solo order/limit).
- Entity `Bank`: `name` (VARCHAR 255, grupos `bank:get`/`bank:search`), `nameNormalized` (unique, no expuesto), `shortName` (VARCHAR 50, expuesto, no filtrable hoy), `media`/`storedObject*` (no expuestos), y `createdAt`/`updatedAt` del trait `Timestamped` → **`TIMESTAMP(0) WITHOUT TIME ZONE`**, formato wire `DateTimeInterface::ATOM`, grupo `timestamped`. Nombres serializados públicos: `createdAt`, `updatedAt`.
- Índices `bank` actuales (`Version20260527115017`): UNIQUE en `name_normalized`, UNIQUE en `short_name`, INDEX en `logo_media_id`. **`created_at`/`updated_at` NO indexados** → Task 5 los añade (NFR4).

### Decisiones pineadas para los huecos del diseño (no re-decidir)

1. **Operadores:** `gt`/`gte`/`lt`/`lte`. Sin `between`. Tokens wire minúsculos (D2). Valor escalar string (ISO-8601), validado en mapping como los escalares existentes.
2. **Consumidor real:** `createdAt`/`updatedAt` de Bank. Solo estos dos campos ganan los operadores de rango; ningún otro campo (`name`/`id`) los lista.
3. **Tipo de valor temporal:** ISO-8601 / RFC 3339 **ATOM estricto** (p. ej. `2026-01-01T00:00:00+00:00`). Rechazar formatos laxos/relativos (`new DateTimeImmutable($v)` acepta "now"/"tomorrow" → vector indeseado; usar parseo estricto tipo `DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $v)` con verificación de errores). Malformado → `InvalidSearchValue::notADateTime(field, position)` → 400 `invalid-search-value`.
4. **Binding:** parámetro **tipado** `Types::DATETIME_IMMUTABLE`. INVARIANTE dura: nunca un string crudo contra columna `timestamp` (no hay operador `timestamp <op> text` con parámetro → 500). Recomendación: extender la tupla del `match` a `[$condition, $value, $type]`.
5. **`FieldMapping` temporal:** flag `requiresDateTimeValues` paralelo a `requiresUuidValues` (consistencia con el precedente). Guard: incompatible con `Contains`. *Alternativa aceptable* si el revisor lo prefiere (dos booleanos mutuamente excluyentes es leve smell): refactorizar a `enum ValueKind { Text, Uuid, DateTime }` y un único parámetro. Decide el dev; que `make php.quality` (PHPMD/cs-fixer) quede limpio manda. No usar `@psalm-suppress`/`NOSONAR` para tapar; reestructurar.
6. **Capas de validación (pin de 1.4, sin cambios):** shape (operador, escalar/lista, caps, índices) → mapping → 400 `validation-failed` + `violations[]`; semántica (campo fuera de allow-list, operador no permitido, datetime malformado) → applier → 400 familia `invalid-search-criteria`. Ninguna validación de filtros en controller ni use case.
7. **Índices:** a nivel `#[ORM\Table]` del entity `Bank` (NO en el trait `Timestamped`, que es compartido — indexarlo crearía índices en toda entidad timestamped). Generar con `make db.diff`.

### Riesgos conocidos / contingencias

- **`timestamp` vs parámetro string → 500.** Postgres no tiene `timestamp <op> text` con parámetro bindeado. Mitigación: binding `Types::DATETIME_IMMUTABLE` (pin 4). Test de integración que falle si se vincula como string.
- **Zona horaria y precisión.** La columna es `TIMESTAMP(0) WITHOUT TIME ZONE` (precisión de segundo, sin tz). Un valor ATOM con offset (`+02:00`) y subsegundos: DBAL convierte el `DateTimeImmutable` al formato de la plataforma usando la tz por defecto de PHP; un valor en otra tz puede desplazar la comparación, y los subsegundos se truncan a segundo. Mitigación: normalizar el `DateTimeImmutable` a la tz en que se almacena (probablemente UTC; los writes usan `new DateTimeImmutable()`), y `FilterApplierTest` debe aseverar fronteras con una tz conocida y precisión de segundo. Documentar el formato exacto aceptado en `api/docs/`.
- **`match` no exhaustivo tras añadir casos.** Es la red de seguridad, no un bug: PHPStan/`UnhandledMatchError` y `FilterOperatorTest` obligan a actualizar los tres sitios + el provider. Recórrelos todos antes de declarar GREEN.
- **NFR4 / Seq Scan.** Rango sin índice = Seq Scan → regresión p95. Mitigación: migración de índices (Task 5) + `EXPLAIN ANALYZE` (Task 6). `contains` ya asume Seq Scan; el rango NO debe.
- **Migración inmutable tras merge.** Solo editar la migración nueva de esta rama; las fusionadas son intocables (crear otra).
- **Enum compartido afecta a futuras listas.** Añadir casos al enum es seguro porque el field map es opt-in por operador (default `[Eq,In,Contains]`); ninguna lista existente acepta rango por accidente. Behat cubre `name` rechazando `gt`.

### Testing

- **Unit puro (sin contenedor/BD):** `FilterOperatorTest` (provider + pin), `FilterTest` (named constructors), `FiltersTest` (sin cambios), `FilterQueryTest` (shape escalar + `toFilter` para los 4), `FieldMappingTest` (guard datetime + allow).
- **Integración Postgres real (nunca SQLite):** `FilterApplierTest` — correctitud por operador, frontera inclusiva `gt`/`gte` vs `lt`/`lte`, binding tipado, datetime malformado → `notADateTime`, tz/precisión. Usar el patrón de transacción con rollback + sufijo único existente.
- **Behat:** `api/features/backoffice/bank/search.feature` (ver "Estilo Behat"). Fixtures Hautelook Alice; reset entre escenarios mutantes.
- **NFR4:** `EXPLAIN ANALYZE` documentado en Completion Notes.

#### Estilo Behat (copiar de los escenarios existentes)

```gherkin
Scenario: Generic gte filter over createdAt returns banks created on or after the bound
  When I send a "GET" request to "/backoffice/banks?filters[0][field]=createdAt&filters[0][operator]=gte&filters[0][value]=2026-01-01T00:00:00%2B00:00"
  Then the response status code should be 200
  # ... aseverar items esperados

Scenario: Closed date range composes gte and lte with AND
  When I send a "GET" request to "/backoffice/banks?filters[0][field]=createdAt&filters[0][operator]=gte&filters[0][value]=2026-01-01T00:00:00%2B00:00&filters[1][field]=createdAt&filters[1][operator]=lte&filters[1][value]=2026-12-31T23:59:59%2B00:00"
  Then the response status code should be 200

Scenario: Range operator on a field that does not allow it returns 400 unsupported-search-operator
  When I send a "GET" request to "/backoffice/banks?filters[0][field]=name&filters[0][operator]=gt&filters[0][value]=x"
  Then the response status code should be 400
  And the JSON node "type" should be equal to "unsupported-search-operator"

Scenario: Malformed datetime returns 400 invalid-search-value
  When I send a "GET" request to "/backoffice/banks?filters[0][field]=createdAt&filters[0][operator]=gte&filters[0][value]=not-a-date"
  Then the response status code should be 400
  And the JSON node "type" should be equal to "invalid-search-value"

Scenario: Range operator with a list value returns 400 validation-failed
  When I send a "GET" request to "/backoffice/banks?filters[0][field]=createdAt&filters[0][operator]=gt&filters[0][value][]=2026-01-01T00:00:00%2B00:00"
  Then the response status code should be 400
  And the JSON node "type" should be equal to "validation-failed"
```

(`+` en el offset se URL-encodea como `%2B`. Las aserciones de `type`/`violations[0].field` siguen el contrato RFC 9457; el paso vive en `api/tests/Behat/Context/HttpRequestContext.php`.)

### Seguridad (checklist CLAUDE.md aplicado a esta historia)

- **Injection:** valores siempre bindeados (parámetro tipado datetime); `dqlPath` lo escribe el repo; nombre de parámetro `xxh128` generado; el parseo estricto rechaza no-ISO antes de tocar SQL. ✔
- **Input validation:** shape en mapping (escalar no-vacío ≤255) + semántica datetime en applier → 400. IDs/valores nunca interpolados. ✔
- **AuthN/AuthZ:** sin cambios; el endpoint de banks es público hoy (deferred-work) — no se introduce nueva exposición: `createdAt`/`updatedAt` ya se serializan en el grupo `timestamped`. ✔
- **Mass assignment / output:** sin campos nuevos expuestos; respuesta JSON-only RFC 9457 en errores. ✔
- **Error contract (NFR26):** sin marker nuevo (reusa `InvalidSearchCriteria`/`invalid-search-value`); aun así documentar el nuevo disparador y verificar `make php.lint.error-contract` + `MarkerStatusMapContractTest` verdes. ✔
- **Migración:** solo añade índices (no destructiva); `down()` reversible (DROP INDEX); sin PII/secretos. ✔
- **DoS/perf:** índice + caps (`MAX_FILTERS=20`) acotan el coste. ✔

### Project Structure Notes

Delta esperado (sobre `feat/shared-search-filters-aj0w`):

```
api/src/Shared/Domain/Search/FilterOperator.php                                  [M] +4 casos
api/src/Shared/Domain/Search/Filter.php                                          [M] +4 named ctors
api/src/Shared/Domain/Search/Exception/InvalidSearchValue.php                    [M] +notADateTime()
api/src/Shared/Application/Http/Search/FilterQuery.php                           [M] 2 match
api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FieldMapping.php       [M] flag/guard temporal
api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FilterApplier.php      [M] +4 ramas + binding tipado + ensureDateTime
api/src/Backoffice/Bank/Domain/Entity/Bank.php                                   [M] #[ORM\Table indexes]
api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/DoctrineBankRepository.php [M] +createdAt/updatedAt
api/migrations/2026/Version<nuevo>.php                                           [N] índices created_at/updated_at
api/tests/Unit/Shared/Domain/Search/FilterOperatorTest.php                       [M]
api/tests/Unit/Shared/Domain/Search/FilterTest.php                               [M]
api/tests/Unit/Shared/Application/Http/Search/FilterQueryTest.php                [M]
api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/FieldMappingTest.php [M]
api/tests/Functional/Shared/Persistence/FilterApplierTest.php                    [M]
api/features/backoffice/bank/search.feature                                      [M]
docs/architecture-api.md                                                         [M] receta
api/docs/<endpoint de banks>.md                                                  [M] gramática wire
docs/api-error-contract.md                                                       [M] disparador datetime de invalid-search-value
```
(`FilterMother` opcional [M] si se añade `gt`.)

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Epic 1] — mecanismo, D1–D8, NFR1–NFR7, "solo operadores con consumidor real", capas de validación, anti-patterns.
- [Source: _bmad-output/implementation-artifacts/1-4-contrato-generico-filters-expuesto-en-el-endpoint-de-banks.md] — DTO `FilterQuery`, seam auto-apply, caps, AND-composition, `requiresUuidValues`.
- [Source: _bmad-output/implementation-artifacts/1-5-camino-unico-legacy-filters-en-bank-d8.md] — `SearchQuery`/`SearchCriteria` `final` sin `ids`; `InvalidSearchValue::notAUuid(field, position)`; NFR4 `EXPLAIN ANALYZE`.
- [Source: _bmad-output/implementation-artifacts/1-6-receta-de-generalizacion-y-cierre-documental.md] — receta en `docs/architecture-api.md`; anti-patterns.
- [Source: docs/project-context.md] — PHP 8.5, Doctrine 3/DBAL 4 (`Types`), reglas de dominio puro, testing (Postgres real, mothers nombrados), gates.
- [Source: docs/api-error-contract.md] — familia `InvalidSearchCriteria` → 400; types `unknown-search-field`/`unsupported-search-operator`/`invalid-search-value`; gate `make php.lint.error-contract`.
- Código verificado (worktree `feat/shared-search-filters-aj0w` @ 5336790): `FilterApplier.php`, `FieldMapping.php`, `FilterQuery.php`, `SearchQuery.php`, `SearchCriteria.php`, `DoctrineBankRepository.php`, `AbstractDoctrineSearchRepository.php`, `AbstractDoctrineRepository.php`, `Bank.php`, `Timestamped.php`, `search.feature`, `Version20260527115017.php`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Claude Code)

### Debug Log References

- `EXPLAIN ANALYZE` (NFR4) sobre `WHERE created_at >= '2026-01-01 00:00:00'`: con `enable_seqscan=off` → **Bitmap Index Scan on idx_bank_created_at** (el índice respalda el predicado de rango). Con el planner por defecto y el dataset dev (~31–63 filas) → Seq Scan por coste, correcto a esa escala; en producción usará el índice.
- Zona horaria del contenedor verificada `UTC`; `AggregateRoot::__construct` fija `createdAt`/`updatedAt` con `new DateTimeImmutable()` (sin listener `prePersist`), de modo que `setCreatedAt()` persiste el valor explícito en los tests de integración.

### Completion Notes List

- **Alcance entregado:** `gt`/`gte`/`lt`/`lte` sobre `createdAt`/`updatedAt` de Bank reutilizando `filters[]`. Sin `between` (≡ `gte`+`lte` AND, documentado). Desarrollado SOBRE `feat/shared-search-filters-aj0w` (no `main`); partida verde verificada.
- **Enum/VO/HTTP (T1–T2):** 4 casos en `FilterOperator` (tokens wire minúsculos), named ctors `Filter::gt/gte/lt/lte`, enrutados en los dos `match` exhaustivos de `FilterQuery`; pin de exhaustividad `FilterOperatorTest` en verde con 7 casos.
- **FieldMapping (T3):** elegido el booleano paralelo `requiresDateTimeValues` (no el enum `ValueKind`) por menor churn y PHPMD limpio bajo el `@SuppressWarnings("PHPMD.BooleanArgumentFlag")` ya existente; guard `requiresDateTimeValues + Contains → LogicException`.
- **FilterApplier (T4):** 4 ramas vía un único `rangeCondition(..., $comparison)` (el `match` exhaustivo es la única fuente del símbolo SQL); tupla extendida a `[$condition,$value,$type]`; binding **tipado** `Types::DATETIME_IMMUTABLE`; parseo **ATOM estricto** (`createFromFormat` + `getLastErrors`) normalizado a **UTC**; malformado/laxo (`now`) → `InvalidSearchValue::notADateTime(field,0)` (400). Guard de programador: rango sobre campo sin `requiresDateTimeValues` → `InvalidArgumentException`. `@SuppressWarnings("PHPMD.CouplingBetweenObjects")` por coupling inherente del seam (mismo precedente que `DoctrineBankRepository`).
- **Bank (T5):** `searchFieldMap()` gana `createdAt`/`updatedAt` (solo rango); índices btree a nivel `#[ORM\Table]` (no en el trait `Timestamped`); migración `Version20260608165844` reversible; `db.validate` en sync.
- **Tests (T6):** los tests de rango viven en una clase enfocada nueva `FilterApplierTemporalRangeTest` (en vez de inflar `FilterApplierTest` por encima de los umbrales PHPMD `TooManyMethods`/`ExcessiveClassLength`). Cubren inclusividad gt/gte/lt/lte, rango cerrado AND, normalización tz (`+02:00` ≡ UTC), binding tipado, datetime malformado/laxo y el guard de campo no-datetime; aislamiento por token único en el nombre + transacción con rollback.
- **Behat (T7):** 7 escenarios nuevos en `search.feature` (gte/lt felices, gt futuro→0, rango cerrado AND, `gt` sobre `name`→`unsupported-search-operator`, datetime malformado→`invalid-search-value`, valor lista→`validation-failed`).
- **Docs (T8):** `docs/architecture-api.md`, `api/docs/adding-endpoints.md`, `docs/api-error-contract.md` actualizados (sin marker nuevo → `php.lint.error-contract` verde). `psalm-baseline.xml`: retiradas 2 entradas obsoletas (`setCreatedAt`/`setUpdatedAt`) al pasar a usarse.
- **Gates (todos verdes):** `php.stan`, `php.psalm`, `php.quality`, `php.lint.error-contract`, `php.unit` (647), `php.behat` (95), `db.validate`.

### File List

Modificados:

- `api/src/Shared/Domain/Search/FilterOperator.php`
- `api/src/Shared/Domain/Search/Filter.php`
- `api/src/Shared/Domain/Search/Exception/InvalidSearchValue.php`
- `api/src/Shared/Application/Http/Search/FilterQuery.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FieldMapping.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FilterApplier.php`
- `api/src/Backoffice/Bank/Domain/Entity/Bank.php`
- `api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/DoctrineBankRepository.php`
- `api/tests/Unit/Shared/Domain/Search/FilterOperatorTest.php`
- `api/tests/Unit/Shared/Domain/Search/FilterTest.php`
- `api/tests/Unit/Shared/Application/Http/Search/FilterQueryTest.php`
- `api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/FieldMappingTest.php`
- `api/tools/psalm/psalm-baseline.xml`
- `api/features/backoffice/bank/search.feature`
- `docs/architecture-api.md`
- `api/docs/adding-endpoints.md`
- `docs/api-error-contract.md`

Nuevos:

- `api/migrations/2026/Version20260608165844.php`
- `api/tests/Functional/Shared/Persistence/FilterApplierTemporalRangeTest.php`

## Change Log

- 2026-06-09: Code review adversarial (3 lentes: Blind Hunter · Edge Case Hunter · Acceptance Auditor) + parches aplicados. 8/8 ACs OK, sin hallazgos Critical/High/Medium. Endurecido el guard de offset a [−12h, +14h]; reforzado el test de binding tipado (dos instantes distintos); cobertura de inclusividad sobre `updatedAt` (plegada en gte/gt); documentada la precisión de segundo; 2 diferidos a `deferred-work.md`. Gates verdes (stan/quality.dry-run/unit 669/behat 116); commits en PR #180 (`bf1ec1b`). Status → done.
- 2026-06-08: Implementada (dev-story). Operadores de rango temporal `gt/gte/lt/lte` sobre `createdAt`/`updatedAt` de Bank: binding tipado `datetime_immutable` + parseo ATOM estricto normalizado a UTC; índices btree (migración reversible); 7 escenarios Behat + `FilterApplierTemporalRangeTest`; docs actualizados. Todos los gates en verde. Status → review.
- 2026-06-08: Historia creada (create-story). Alcance acordado con Sergio: operadores de rango `gt/gte/lt/lte` sobre `createdAt`/`updatedAt` de Bank; sin `between`; consumidor real = filtrado por fecha. Baseline pineado a `feat/shared-search-filters-aj0w` (no `main`). Historia no presente en `epics.md` (ampliación de la Épica 1).
