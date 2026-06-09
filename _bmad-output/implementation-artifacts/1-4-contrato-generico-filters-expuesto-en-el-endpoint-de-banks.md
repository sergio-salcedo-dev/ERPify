---
baseline_commit: 96682ba7f887f754ed5cec600db6ed7c62ca60f4
---

# Story 1.4: Contrato genérico `filters[]` expuesto en el endpoint de banks

Status: done

_Ultimate context engine analysis completed — comprehensive developer guide created (2026-06-07)._

## Story

As a consumidor de la API de banks,
I want filtrar con `filters[N][field/operator/value]` validado en mapping,
so that pueda componer búsquedas server-side sin esperar parámetros ad hoc nuevos.

## Acceptance Criteria

1. **Given** `api/composer.json`
   **When** se promocionan `phpstan/phpdoc-parser` y `phpdocumentor/type-resolver` de transitivas dev a `require` (versiones ya locked — NFR6)
   **Then** `#[MapQueryString]` mapea arrays de DTOs anidados en runtime.

2. **Given** el DTO `FilterQuery` con constraints `#[Assert]` (D5)
   **When** llega un `value` incoherente con el operador (IN exige lista no vacía con items no vacíos; EQ/CONTAINS exigen string no vacío), un operador fuera del enum (estrictamente lowercase), caps excedidos (`SearchQuery::MAX_FILTERS = 20`, `FilterQuery::MAX_IN_VALUES = 100`) o índices no contiguos desde 0
   **Then** la respuesta es 400 `validation-failed` con `violations[]` (nunca `ValueError`/500)
   **And** ninguna validación de filtros vive en controller ni use case (capa pineada: shape → mapping).

3. **Given** `SearchQuery` base
   **When** gana `filters[]` (`@param list<FilterQuery> $filters`) y `toCriteria()` los traslada a `Filters`
   **Then** `filters` ausente o vacío → sin filtrado (no es error)
   **And** paginación (`page`/`limit`/`cursor`/`paginationMode`) y orden (`sort`/`direction`) quedan intactos.

4. **Given** `AbstractDoctrineSearchRepository`
   **When** gana `abstract protected function searchFieldMap(): SearchFieldMap` y auto-aplica `FilterApplier` antes de paginar
   **Then** `DoctrineBankRepository` implementa su map en el mismo cambio: `name` → `b.nameNormalized` + `NormalizedTextFieldNormalizer`; `id` → `b.id` con operadores solo `eq`/`in`
   **And** no existe ningún estado intermedio roto (método abstracto + implementación en el mismo PR)
   **And** ningún repositorio invoca el applier directamente.

5. **Given** `GET /api/v1/backoffice/banks?filters[0][field]=name&filters[0][operator]=contains&filters[0][value]=banc`
   **When** se ejecuta la petición
   **Then** filtra correctamente con normalización diacrítica
   **And** el envelope de respuesta (items + pagination.cursor/hasMorePages) no cambia (NFR5)
   **And** los parámetros legacy `names[]`/`ids[]` siguen funcionando por su camino actual (expand: ambos caminos conviven).

6. **Given** `api/features/backoffice/bank/search.feature` (extendido, nunca feature paralela)
   **When** corre la suite Behat
   **Then** cubre: filtros genéricos felices (eq/in/contains), 400 por campo desconocido, 400 por operador inválido, CONTAINS con diacríticos, frontera moderada 1 filtro IN × 100 valores
   **And** las excepciones nuevas fluyen por `ExceptionResponder`/`ProblemDetailsFactory` sin interceptación legacy — _nota: el `SearchExceptionListener` (priority 32) citado en la épica YA NO EXISTE (hallazgo de la 1.2, re-verificado 2026-06-07: cero referencias en `api/src` + `api/config`); los asserts de `type`/`status` en los escenarios 400 SON la verificación de este AC_
   **And** los escenarios existentes de `search.feature` siguen en verde (15 declarados / 20 ejecuciones con outlines — el "29" de la épica era una cifra obsoleta del research).

7. **Given** `api/docs/`
   **When** se documenta la forma del endpoint
   **Then** incluye la gramática wire exacta y el límite efectivo `min(caps, max_input_vars, longitud de URL)`.

## Tasks / Subtasks

- [x] Task 0: Continuar en la rama de la épica (NO crear worktree nuevo) (prep)
  - [x] `cd .claude/worktrees/shared-search-filters-aj0w` — la 1.4 abre la **fase 1** sobre la misma rama `feat/shared-search-filters-aj0w` donde vive la fase 0 completa (1.1 `18e1a2f` · 1.2 `f53e3b8`+`b59a28f` · 1.3 `753e051`+`2a3ad6c`+`96682ba`). Verificar con `git branch --show-current` y `git status` (working tree limpio verificado 2026-06-07)
  - [x] NO rebasar sobre `main` (3 commits por delante, todos `pwa/` — cero riesgo de conflicto con `api/`); no hay PR abierta para la rama (verificado vía `gh pr list`): la épica permite que fases 0 y 1 compartan PR — la decisión de abrir una o dos PRs es del usuario, no de esta historia
  - [x] Levantar el stack del worktree si no está arriba: `make docker.up` (reintentar ante flake transitorio de health-check)
- [x] Task 1: Promoción de dependencias NFR6 (AC: 1)
  - [x] `make composer c='require phpstan/phpdoc-parser:^2.3 phpdocumentor/type-resolver:^2.0'` — HOY ninguno está declarado en `composer.json` (ni en `require-dev`): son transitivas puras de tooling dev, locked en `packages-dev` (`phpstan/phpdoc-parser` 2.3.2 · `phpdocumentor/type-resolver` 2.0.0). La promoción debe MOVERLAS a la sección `packages` (prod) del lock sin bump de versión
  - [x] Verificación REAL del AC (no fiarse de Behat): Behat corre con `require-dev` instalado, así que los tests pasarían incluso sin la promoción — el AC se verifica inspeccionando `api/composer.lock` (ambos paquetes en `packages`, no `packages-dev`) y `api/composer.json` (ambos en `require`)
  - [x] Gotcha `make composer.check.all`: composer-unused puede marcar ambos como "unused" (se usan reflexivamente vía property-info del Serializer, no por import). Si muerde, registrar la excepción por el mecanismo que el repo ya use para falsos positivos de composer-unused (buscar config existente en `composer.json`/`composer-unused.php`); composer-require-checker no afecta (chequea lo inverso) — resultado: `composer.check.unused` exit 0 (los ✗ son informativos); `composer.check.missing-deps` falla con 7 símbolos PREEXISTENTES en baseline (verificado con stash — no lo introduce esta historia)
- [x] Task 2: RED→GREEN — DTO `FilterQuery` con validación D5 completa (AC: 2)
  - [x] RED: `api/tests/Unit/Shared/Application/Http/Search/FilterQueryTest.php` [N] — espejo de las convenciones de `SearchQueryTest` (Validator real: `createValidatorBuilder()` + attribute mapping, sin contenedor). Cobertura mínima: eq/contains válidos pasan; in válido (lista) pasa; `field` blank → violación; `operator` null → violación; eq/contains con array → violación en `value`; in con string escalar → violación; in con lista vacía → violación; in con item blank → violación; in con > `MAX_IN_VALUES` (101) items → violación; item > 255 chars → violación; scalar > 255 chars → violación; in con claves string/no-list → violación; `toFilter()` produce el `Filter` correcto por operador (eq→`Filter::eq`, in→`Filter::in`, contains→`Filter::contains`)
  - [x] `api/src/Shared/Application/Http/Search/FilterQuery.php` [N] — `final readonly`, junto a `SearchQuery` (FQCN pineado `Erpify\Shared\Application\Http\Search\FilterQuery`). Constructor promovido: `string $field = ''` con `#[Assert\NotBlank]`; `?FilterOperator $operator = null` con `#[Assert\NotNull]` (nullable + default para que un key ausente produzca violación limpia, no `MissingConstructorArgumentsException`; un token inválido o uppercase lo rechaza el denormalizer del enum backed → violations — mismo mecanismo ya probado por el escenario `paginationMode=unknownPaginationMode`); `string|array $value = ''` — nota: el docblock quedó `@param string|array<mixed> $value` (tipo honesto PRE-validación; `list<string>` solo post-callback — PHPStan `alwaysFalse` lo exigía)
  - [x] `final public const int MAX_IN_VALUES = 100` — constante pública junto al patrón `MAX_PAGE`/`MAX_LIMIT` de `SearchQuery`
  - [x] Coherencia operador↔shape vía `#[Assert\Callback]` (NO `Assert\When` — requeriría `symfony/expression-language`, que NO está en require): In → `\is_array` + `array_is_list` + no vacía + `count ≤ MAX_IN_VALUES` + cada item string no-blank ≤ 255; Eq/Contains → `\is_string` + no-blank + ≤ 255; operator null → skip (NotNull ya lo cubre). Violaciones con `->atPath('value')`/`value[N]`. El cap 255 por valor espeja el `Assert\Length(max: 255)` del legacy `names[]` (equivalencia también en límites). Psalm `PossiblyUnusedMethod` sobre el callback reflexivo → entrada en `psalm-baseline.xml` (mecanismo establecido del repo: el precedente `Bank::validateNormalizedNameLength` vive ahí)
  - [x] `toFilter(): Filter` — match sobre el operador hacia las named constructors de `Filter` (post-validación; si operator es null aquí es error de programador → `LogicException`)
  - [x] GREEN: `make php.unit c='--filter FilterQueryTest'` — 23 tests / 43 asserts OK; stan+psalm verdes (vía `PHP_SERVICE=messenger_worker` — restart-loop del contenedor php, gotcha conocido)
- [x] Task 3: RED→GREEN — `filters[]` en `SearchQuery` base + transporte Bank completo (AC: 3)
  - [x] RED: ampliar `api/tests/Unit/Shared/Application/Http/Search/SearchQueryTest.php` [M]: default `[]` → `toCriteria()->filters->isEmpty()`; cascade `#[Assert\Valid]` (un FilterQuery inválido anidado produce violación con path `filters[0].…`); > `MAX_FILTERS` (21) → violación; claves no contiguas (p. ej. `[1 => …]`) → violación; traducción `toCriteria()` (lista de FilterQuery → `Filters` con los `Filter` esperados); params existentes intactos (cursor/page/limit/paginationMode/ids)
  - [x] RED: test del transporte Bank (no existía `BankSearchQueryTest` — creado en `api/tests/Unit/Backoffice/Bank/Application/Http/`): `BankSearchQuery::toCriteria()` TRANSPORTA los filters a `BankSearchCriteria` — guard crítico contra el bug silencioso de "filters validados pero descartados en la subclase"
  - [x] `SearchQuery` [M]: param promovido `array $filters = []` con `@param array<int, FilterQuery> $filters` (tipo honesto pre-validación — índices sparse posibles), `#[Assert\Valid]` + `#[Assert\Count(max: self::MAX_FILTERS)]`; `final public const int MAX_FILTERS = 20`; `#[Assert\Callback] validateFilterIndexes` que exige `array_is_list` (D1); helper `final protected function domainFilters(): Filters` usado por `toCriteria()` — evita duplicar la traducción en subclases. Callback reflexivo → entrada baseline Psalm (mismo mecanismo que Task 2)
  - [x] `BankSearchQuery` [M]: param NO promovido `array $filters = []` re-declarado y reenviado a `parent::__construct(..., $filters)` con docblock `@param array<int, FilterQuery> $filters` en el constructor CONCRETO (el PhpStanExtractor lee el docblock del constructor instanciado) + why-comment en el código
  - [x] `BankSearchCriteria` [M]: gana `Filters $filters = new Filters()` reenviado a `parent::__construct`; `BankSearchQuery::toCriteria()` los pasa vía `$this->domainFilters()`
  - [x] GREEN: 44 tests / 82 asserts OK (FilterQuery+SearchQuery+BankSearchQuery); suite unit completa 609 OK / 3 skips (sin regresiones); stan+psalm verdes
- [x] Task 4: RED — Behat: extender `search.feature` con el contrato genérico (AC: 5, 6)
  - [x] Añadir a `api/features/backoffice/bank/search.feature` (JAMÁS feature paralela; espejo del estilo existente, con asserts de conteo de queries Doctrine — felices: mismo conteo que el escenario `names[]` (2); 400s: `0 requests`). Fixtures disponibles (31 banks, `api/tests/DataFixtures/Fixtures/Bank.yaml`): BBVA (`…0020`), CaixaBank (`…0021`), Banco Santander (`…0019`), Banco Sabadell (`…0022`), Société Générale (`…0015`), Sociedad Anónima (`…0031`)
  - [x] Felices: EQ genérico (`filters[0][field]=name&filters[0][operator]=eq&filters[0][value]=BBVA` → 200, 1 item — eq normaliza, case-insensitive); IN genérico (`value[]=BBVA&value[]=CaixaBank` → 200, 2 items); CONTAINS (`value=banc` → 200, 2 items: Banco Santander + Banco Sabadell — verificado contra fixtures: ningún otro nameNormalized contiene `banc`); CONTAINS con diacríticos en el VALOR (`value=G%C3%A9n%C3%A9rale` → 200, 1 item: Société Générale); id feliz (`filters[0][field]=id&filters[0][operator]=in&filters[0][value][]=11111111-1111-7000-8000-000000000020` → 200, 1 item)
  - [x] 400 semánticos (capa applier, `0 requests`): campo desconocido `shortName` → 400 `type=unknown-search-field` (columna real fuera del map — allow-list ≠ esquema); operador no permitido `id`+`contains` → 400 `type=unsupported-search-operator`
  - [x] 400 de shape (capa mapping): Scenario Outline operador inválido (`like`/`EQ`/`IN`) → 400; value incoherente (eq con lista; in con escalar) → 400 `validation-failed` + `violations[]` (`violations[0].field=filters[0].value` asseverado). Caps e índices no contiguos cubiertos en unit (Tasks 2–3)
  - [x] Frontera moderada: 1 filtro IN × 100 valores (99 generados + `BBVA`) → 200, 1 item — step dedicado nuevo en `HttpRequestContext` (`with a :field in-filter of :count generated values plus value :real`); `max_input_vars` default 1000 sin override verificado
  - [x] Legacy intacto: NO añadir escenarios de equivalencia `names[]` ≡ `filters[name][in]` ni de combinación legacy+genérico (son ACs de la 1.5); los 15 escenarios existentes ya cubren el camino legacy
  - [x] Confirmar RED: split EXACTO al previsto — 8 fallan (5 felices + frontera IN×100 + 2 semánticos), los 5 de shape ya pasan tras la Task 3, lo que prueba el mapping anidado e2e (`violations[0].field=filters[0].value` ✅). 33 ejecuciones totales (25 pass / 8 fail)
- [x] Task 5: GREEN — seam auto-apply + field map de Bank (AC: 4, 5)
  - [x] `AbstractDoctrineSearchRepository` [M]: constructor gana `private readonly FilterApplier $filterApplier` (autowired — autodiscovery ya registra el applier; cero cambios en `services.yaml`); `abstract protected function searchFieldMap(): SearchFieldMap;` (con docblock del caso "mapa vacío"); en `getPaginatedResults()`: apply DESPUÉS de `getSearchQueryBuilder()` y ANTES de paginar. `getQueryBuilderPaginatedResults()` fuera del seam deliberadamente (why-comment en el código)
  - [x] `DoctrineBankRepository` [M]: constructor nuevo que re-declara los params del padre + `private readonly NormalizedTextFieldNormalizer $normalizedText` (inyección — espejo del ejemplo canónico); `searchFieldMap()`: `name` → `b.nameNormalized`+normalizador, `id` → `b.id` solo eq/in (why-comment del no-contains). Legacy `addWhereIdsIn`/`addWhereIn` intactos (D8 = 1.5)
  - [x] GREEN: `search.feature` 33/33 escenarios / 164 steps al primer intento (conteos de queries Doctrine clavados: felices 2, 400s 0)
- [x] Task 6: Caso borde UUID — cerrar el hueco 4xx del campo `id` (AC: 4, 6 — hallazgo del análisis, no de la épica)
  - [x] Contexto verificado: `bank.id` es columna **UUID** Postgres (`Types::GUID` en `Identifiable`, `CREATE TABLE bank (... id UUID NOT NULL ...)`); el legacy se protege con `#[Assert\Uuid]` en `ids[]`, pero `filters[0][field]=id&...[value]=banana` es shape-válido y llegaría bindeado a Postgres → previsible error 22P02 → 500 (violación de "todo error de entrada → 4xx")
  - [x] RED primero: el escenario Behat con `value=not-a-uuid` CONFIRMÓ el 500 real (log `[critical] API error response built` — 22P02 tal como se predijo) → el guard procede
  - [x] Guard implementado: `FieldMapping.requiresUuidValues` (bool opcional); `FilterApplier::ensureUuidValues()` valida eq escalar / in item a item con `Uuid::isValid()` y lanza `InvalidSearchValue::notAUuid($field)` [N] (type `invalid-search-value`, marker existente, context solo con el campo — sin echo del valor). Map de Bank e2e verde: `400 invalid-search-value`, 0 SQL. Tests: 3 nuevos en `FilterApplierTest` (19/19) + escenario Behat (34/34)
  - [x] Desviación consciente registrada; `MarkerStatusMapContractTest` intacto (cero markers nuevos); `docs/api-error-contract.md` SÍ enumera los types concretos → añadido `InvalidSearchValue → invalid-search-value` a la frase de la familia; `make php.lint.error-contract` verde. Limpieza Psalm: baseline actualizada (callback nuevos + params del step Behat reflexivo + entrada `__construct` obsoleta retirada)
- [x] Task 7: Documentación del endpoint (AC: 7)
  - [x] `api/docs/adding-endpoints.md` [M] — paso 5 nuevo del skeleton (`searchFieldMap()` obligatorio + `requiresUuidValues`) + sección "Generic `filters[]` wire contract" (gramática D1/D2, caps, límite efectivo `min(caps, max_input_vars=1000, URL length)`, capas de error con los 3 types concretos, no-filters/AND) + convención del docblock `@param` en subclases. Patrón completo + receta → 1.6 (conflicto documental ya resuelto en 1.3, no re-litigado)
  - [x] `api/docs/postman/erpify-api.postman_collection.json` [M] — request "Search banks": 3 params `filters[0][…]` disabled con descriptions de gramática/caps/límite + referencia a adding-endpoints.md en la description (edición quirúrgica respetando el formato: diff de 7 líneas; JSON validado)
- [x] Task 8: Gates de calidad y regresión (AC: 1–7)
  - [x] `make php.stan` y `make php.psalm` sobre CADA archivo tocado — ambos verdes (vía `PHP_SERVICE=messenger_worker`; el narrow runtime del callback satisface a los dos en la zona D5)
  - [x] `make php.unit` — 612 OK / 3 skips preexistentes (+35 sobre baseline 577)
  - [x] `make php.behat` — 85 escenarios / 640 steps en verde (+14/+60 sobre baseline 71/580)
  - [x] `make php.quality` completo exit 0, idempotente en segunda pasada. 3 violaciones PHPMD resueltas con los precedentes del repo: `@SuppressWarnings` CouplingBetweenObjects en `DoctrineBankRepository` (precedente: la propia base), BooleanArgumentFlag en `FieldMapping::__construct` (precedente: `EnumType`), TooManyPublicMethods en `SearchQueryTest` (precedente: `FilterApplierTest`). `composer.check.unused` exit 0; `composer.check.missing-deps` rojo PREEXISTENTE en baseline (verificado con stash)
  - [x] Commit `f1ed854` `feat(api): expose generic filters contract on banks search endpoint` (21 ficheros, +998/−172; staging explícito; `reference.php` auto-regenerado restaurado y fuera del commit)

### Review Findings

- [x] [Review][Patch] Combo `requiresUuidValues: true` + operador `contains` permitido no se rechaza en `FieldMapping` — decisión del usuario (2026-06-07): guard fail-fast en el constructor (`LogicException` si `requiresUuidValues` y `Contains ∈ operators`) + test unit; coherente con los guards "fail loudly" de `FilterApplier`. Sin el guard, un map futuro con ese combo daría 400 perpetuo con valores parciales y error SQL → 500 con UUID válido (`LIKE` sobre columna `uuid` Postgres). RESUELTO: guard + docblock en `FieldMapping`, `FieldMappingTest` nuevo (4 tests), nota de incompatibilidad en `adding-endpoints.md` paso 5. [api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FieldMapping.php] (source: blind+edge)
- [x] [Review][Patch] Frase del step Behat IN×100 engañosa: «in-filter of :count generated values plus value :real» genera `count−1` valores + 1 real (= `count` totales), no `count+1` — el comportamiento es el que el escenario quiere (frontera exacta en `MAX_IN_VALUES=100`), pero la redacción del step y su docblock prometen N generados MÁS el real. RESUELTO: frase renombrada a «in-filter of :count values, the last being :real» (step + docblock + feature); 34/34 escenarios en verde. [api/tests/Behat/Context/HttpRequestContext.php:200-220] (source: blind)
- [x] [Review][Defer] Coste de query sin límite más allá de los caps planos — 20 filtros `contains` componen 20 `LIKE '%…%'` AND no indexables; los caps (20×100×255) acotan el transporte, no el plan de query [api/src/Shared/Application/Http/Search/SearchQuery.php] — deferred: el gate formal de performance (`EXPLAIN ANALYZE` + p95, NFR4) está pineado en la story 1.5 (source: blind)
- [x] [Review][Defer] `InvalidSearchValue` no señala el índice posicional del valor ofensor — en un IN de hasta 100 valores el 400 `invalid-search-value` solo lleva el campo (shape de context pineado en la story: sin echo del valor; añadir el índice no echaría el valor y haría el error depurable) [api/src/Shared/Domain/Search/Exception/InvalidSearchValue.php] — deferred: la forma/equivalencia de errores entre camino legacy y genérico se decide en la 1.5 (source: blind)

## Dev Notes

### Contexto y alcance (leer antes de tocar nada)

Primera historia de la **fase 1** (expand del Parallel Change) del mecanismo compartido de filtros. La fase 0 (1.1 vocabulario + 1.2 errores + 1.3 applier) está COMPLETA y sin mergear en la rama `feat/shared-search-filters-aj0w` (worktree `.claude/worktrees/shared-search-filters-aj0w`). Esta historia hace el mecanismo **alcanzable desde HTTP por primera vez**: DTO `FilterQuery` + `filters[]` en `SearchQuery` base + seam auto-apply + field map de Bank, todo en el mismo cambio (sin estado intermedio roto). Tras ella, `GET /api/v1/backoffice/banks` acepta el contrato genérico CONVIVIENDO con `names[]`/`ids[]` (el camino único D8 es la 1.5; la receta documental es la 1.6).

**Fuera del alcance de esta historia (NO hacer):**

- ❌ NO mapear `names[]`/`ids[]` a `Filters` ni eliminar `addWhereIn`/`addWhereIdsIn`/`BankSearchCriteria` (D8 — story 1.5). En esta historia el QB de Bank sigue construyendo el filtrado legacy y el applier AÑADE el genérico (AND natural entre ambos).
- ❌ NO añadir escenarios Behat de equivalencia `names[]` ≡ `filters[name][in]` ni de combinación legacy+genérico (ACs de la 1.5).
- ❌ NO tocar `docs/architecture-api.md` (patrón + receta = story 1.6); el alcance documental aquí es `api/docs/` (Task 7).
- ❌ NO añadir `sort`/`direction` ni tocar paginación/cursor/orden — fuera del vocabulario de filtros.
- ❌ NO modificar `Filter`/`Filters`/`FilterOperator` (1.1) ni `InvalidSearchCriteria`/`UnknownSearchField`/`UnsupportedSearchOperator` (1.2). `FilterApplier`/`FieldMapping` solo se tocan si la Task 6 confirma el hueco UUID (desviación consciente y registrada).
- ❌ NO registrar nada en `services.yaml` (autodiscovery cubre `FilterApplier` y `NormalizedTextFieldNormalizer` — verificado).
- ❌ NO añadir operadores nuevos, OR/grupos, ni transportar `Filters`/`SearchCriteria` por Messenger/Mercure.
- ❌ NO usar `Assert\When`/expression-language (dep ausente) ni crear feature Behat paralela.

### Estado actual del código que se toca (verificado 2026-06-07 en el worktree)

**`SearchQuery`** (`Shared/Application/Http/Search/`) — único fichero del directorio. `readonly class` (NO final — Bank la extiende) con `MAX_PAGE=10_000`, `MAX_LIMIT=1_000` y constructor promovido: `?string $cursor` (Length≤8192), `?int $page=1` (Positive, ≤MAX_PAGE), `?int $limit=MAX_LIMIT` (Positive, ≤MAX_LIMIT), `PaginationMode $paginationMode=LIGHT`, `?array $ids=null` (`Assert\All([Uuid(strict)])`). `toCriteria(): SearchCriteria` con named args. NO tiene `filters` aún.

**`BankSearchQuery`** (`Backoffice/Bank/Application/Http/`) — `final readonly ... extends SearchQuery`; re-declara TODOS los params del padre como no-promovidos + añade `public ?array $names` (`Assert\All([Type('string'), Length(max:255)])`); `#[Override] toCriteria(): BankSearchCriteria`. ⚠️ Es la clase que `#[MapQueryString]` instancia — su docblock de constructor manda para el denormalizer.

**`BankSearchCriteria`** (`Backoffice/Bank/Domain/Search/`) — `final readonly ... extends SearchCriteria`, añade `public ?array $names = []`; HOY no acepta `filters` (el default vacío del padre se aplica siempre) → sin la modificación de la Task 3 los filters se PERDERÍAN silenciosamente.

**`SearchCriteria`** (`Shared/Domain/Search/`) — ya transporta `public Filters $filters = new Filters()` (entregado en 1.1). No se toca.

**`AbstractDoctrineSearchRepository`** (`Shared/Infrastructure/Persistence/Doctrine/`) — constructor `(ManagerRegistry $registry, private readonly PaginatorCursorFactory $paginatorCursorFactory)`; `getPaginatedResults(SearchCriteria $criteria)` = `getSearchQueryBuilder($criteria)` (abstracto) → `getQueryBuilderPaginatedResults(...)` (Paginator keyset/HMAC). El seam de esta historia se inserta ENTRE ambos.

**`DoctrineBankRepository`** — SIN constructor propio (hereda); `getSearchQueryBuilder()` exige `instanceof BankSearchCriteria` (se mantiene en 1.4 — las firmas pasan a la base en 1.5), construye QB con alias `'b'`, `addWhereIdsIn` + `addWhereIn('nameNormalized', normalizados)` legacy, `addOrderByFromQueryParams` y `addLimit`. Implementa `BankRepository`/`BankSearchRepository`/`BankStoredObjectQueries` vía `#[AsAlias]`.

**`BankSearchController`** — `#[Route('/banks', name: 'backoffice_bank_search', methods: ['GET'])]`, `__invoke(#[MapQueryString] BankSearchQuery $query = new BankSearchQuery())`, responde vía `AbstractSearchController::buildResponse()` (envelope `items` + `pagination{currentPage,pageCount,count,hasMorePages,cursor}` — NO cambia, NFR5).

**Piezas 1.3 que se consumen tal cual** (`Shared/Infrastructure/Persistence/Doctrine/Search/`): `FilterApplier::apply(QueryBuilder, Filters, SearchFieldMap): void` (no-op con Filters vacío; lanza `UnknownSearchField`/`UnsupportedSearchOperator`; `InvalidArgumentException` ante degenerados irrepresentables desde el wire — los `#[Assert]` de la Task 2 son justo lo que los hace irrepresentables); `SearchFieldMap(array $mappings)` con `mappingFor(): ?FieldMapping`; `FieldMapping(string $dqlPath, ?FieldNormalizer $normalizer = null, array $operators = [los tres])` + `allows()`; `NormalizedTextFieldNormalizer` (servicio autodiscovered).

**Pipeline de errores** (sin wiring nuevo): `ValidationFailedException` de `#[MapQueryString]` → `ProblemDetailsFactory` desenvuelve `getPrevious()` y re-mapea el wrapper 422 de Symfony a **400 `validation-failed` + `violations[]` `{field,message,code}`** (documentado en `docs/api-error-contract.md` y en `api/docs/adding-endpoints.md`); excepciones con marker `InvalidSearchCriteria` → 400, fila ya presente en la tabla del contrato (1.2). El `SearchExceptionListener` legacy NO existe (cero referencias — verificado).

**Deps**: `phpstan/phpdoc-parser` 2.3.2 y `phpdocumentor/type-resolver` 2.0.0 locked en `packages-dev` como transitivas (de phpdocumentor/reflection-docblock, composer-unused, etc.) y NO declarados en `composer.json`. Symfony locked: http-kernel v8.0.13, serializer v8.0.10, validator v8.0.10.

**Behat**: suite en `api/tools/behat/` aislado (instalar con `make php.behat.install` si falta vendor); `search.feature` = 15 escenarios declarados (13 + 2 outlines = 20 ejecuciones). Steps disponibles: URLs literales con query string (`?names[]=BBVA` — los arrays van inline, no hay step de array params), `the JSON node :node should be equal to :text`, `... should have :number elements`, `the header :name should be equal to :value`, `:n requests got executed only for doctrine connection "default"` / `... across all doctrine connections`. Fixtures Alice: 31 banks deterministas (ids `11111111-1111-7000-8000-0000000000NN`).

**`max_input_vars`**: sin override en `api/frankenphp/conf.d/*` ni Dockerfile → default PHP 1000. Es el dato para la doc del límite efectivo (Task 7) y para el escenario frontera (102 vars ≈ OK).

### Decisiones arquitectónicas vinculantes (no inventar variantes)

| Pieza | Valor pineado |
|---|---|
| DTO nuevo | `Erpify\Shared\Application\Http\Search\FilterQuery` (`final readonly`) |
| Caps | `SearchQuery::MAX_FILTERS = 20` · `FilterQuery::MAX_IN_VALUES = 100` (constantes públicas, validadas en mapping) |
| Gramática wire (D1) | `filters[N][field]` / `filters[N][operator]` / `filters[N][value]` (escalar) o `filters[N][value][]` (lista); índices contiguos desde 0; otra forma → 400 `validation-failed` |
| Operadores (D2) | backing strings del enum = contrato: `eq` · `in` · `contains`, estrictamente lowercase |
| `value` (D5) | `string\|list<string>` validado por operador EN MAPPING (constraint compuesta en el DTO) |
| Capas de validación | shape → mapping → 400 `validation-failed`+`violations[]`; semántica (campo/operador no permitidos) → applier → 400 familia `invalid-search-criteria`; NADA en controller/use case |
| Seam (D4 + FR7) | `filters[]` en `SearchQuery` BASE; `abstract protected function searchFieldMap(): SearchFieldMap` en `AbstractDoctrineSearchRepository` + auto-apply en `getPaginatedResults()`; los repos NUNCA invocan el applier |
| Map de Bank | `name` → `b.nameNormalized` + `NormalizedTextFieldNormalizer` · `id` → `b.id`, operadores solo `eq`/`in` |
| `filters` ausente/vacío | sin filtrado, no error (no-op silencioso del applier ya implementado) |
| Mismo campo N veces | AND (todos se aplican) |
| Envelope respuesta | intacto (NFR5); paginación/orden intactos |

### Decisiones pineadas para los huecos del diseño (no re-decidir)

1. **Inyección, no instanciación**: `FilterApplier` entra por constructor en `AbstractDoctrineSearchRepository` (autowired, único subtipo existente = `DoctrineBankRepository`, que NO define constructor → cero ripple); `NormalizedTextFieldNormalizer` entra por constructor en `DoctrineBankRepository` (espejo literal del ejemplo canónico `$this->normalizedText` del Architecture Decision Document — lo que la receta 1.6 consagrará). Razón: regla del repo "constructor DI", cero config extra, testabilidad.
2. **`operator` nullable con default null + `#[Assert\NotNull]`** (en vez de no-nullable sin default): un key ausente produce violación estructurada en `violations[]`, no `MissingConstructorArgumentsException`; espejo del patrón `ids`/`names` nullable. Token inválido/uppercase → lo rechaza el denormalizer de enum backed → violations (precedente: escenario `paginationMode=unknownPaginationMode` ya en verde).
3. **Callback, no constraint custom ni `Assert\When`**: la coherencia operador↔shape va en UN `#[Assert\Callback]` del propio `FilterQuery` con narrows runtime `\is_string()`/`\is_array()` — narra el tipo a PHPStan Y Psalm (salida pineada en 1.3 para la zona D5) y evita una clase Constraint nueva. `array_is_list` también para `value[]` (lista real, no mapa).
4. **Cap 255 por valor** (escalar y por item de IN): espejo del `Assert\Length(max:255)` del legacy `names[]` — mantiene la equivalencia también en límites y acota el parámetro bindeado. `field` solo `NotBlank` (un field largo solo es una clave de lookup fallida → 400 del applier; no añadir constraints especulativas).
5. **Traducción única DTO→Domain**: `SearchQuery::domainFilters(): Filters` (protected) — `toCriteria()` de base y de Bank la reutilizan; `FilterQuery::toFilter()` mapea a las named constructors de `Filter`. Defensivo: `array_values()` antes de `Filters::fromList()` (spread sobre claves string fatal — la validación ya lo impide, cinturón barato).
6. **Contigüidad de índices = `array_is_list($this->filters)`** en `#[Assert\Callback]` de `SearchQuery` (el denormalizer PRESERVA las claves del query string, así que `filters[3]` sin `filters[0..2]` llega como `[3 => …]` y la callback lo convierte en violación → 400, cumpliendo D1).
7. **Hueco UUID (Task 6)**: guard en la capa APPLIER conducido por el field map (`FieldMapping::requiresUuidValues` + `InvalidSearchValue` bajo el marker existente). Alternativas descartadas: validarlo en `BankSearchQuery` (la capa pineada prohíbe semántica per-field en mapping y un futuro endpoint sin subclase quedaría desprotegido — D4); normalizador que lanza (el normalizador no conoce el `field` para el context de la excepción y abusa de su contrato). RED-first obligatorio: implementar el guard SOLO si el test confirma el 500.
8. **Sin test funcional WebTestCase nuevo**: la estrategia del repo para endpoints es Behat (verificado: cero WebTestCase de search); el mapping anidado se prueba e2e por Behat y las constraints por unit con Validator real.

### Riesgos conocidos / contingencias

- **Denormalización del union `string|array` en `FilterQuery::value`**: el ObjectNormalizer debería aceptar ambos shapes del query string tal cual (strings o arrays de strings). Si el type-enforcement del Serializer peleara con el union, la contingencia pineada es tipar `mixed $value = ''` y dejar TODO el narrow en el callback (el wire sigue idéntico; solo cambia el tipo PHP del param) — documentarlo en Completion Notes si se activa.
- **Discrepancia PHPStan↔Psalm en D5**: gate doble por archivo OBLIGATORIO; ante tug-of-war, recordar el precedente del repo: rector gana sobre Psalm, jamás `@psalm-suppress`, reestructurar el código hasta que ninguna herramienta opine.
- **El conteo "29 escenarios" de la épica es obsoleto** (cifra del research previa al working tree actual): el real es 15 declarados / 20 ejecuciones en `search.feature` y 71 escenarios / 580 steps de suite (baseline 1.3). Pinear los conteos NUEVOS observados al cerrar.
- **Behat verde ≠ NFR6 cumplido** (Task 1): el entorno de test instala require-dev; la promoción se verifica en el LOCK, no en los tests.

### Testing

- **Unit** (Validator real sin contenedor, espejo de `SearchQueryTest`): `FilterQueryTest` [N] + `SearchQueryTest` [M] + transporte de `BankSearchQuery` (ver Tasks 2–3). Convenciones: `declare(strict_types=1)`, namespace espejo, `/** @internal */`, `#[CoversClass(...)]`, `final`, AAA, un comportamiento por test; data providers nombrados como el fixer quiera (`provide…Cases`).
- **Integración**: `FilterApplierTest` [M] SOLO si la Task 6 confirma el guard (test del rechazo no-UUID contra Postgres real, espejo de los 16 tests existentes — transacción + rollback, sufijos únicos).
- **E2E Behat**: Task 4 — la lista exacta de escenarios con datos de fixtures está en la task. Ejecutar con `make php.behat` (stack del worktree arriba; `make php.behat.install` si falta vendor).
- Smoke manual opcional: `curl -k 'https://localhost:<HTTPS_PORT>/api/v1/backoffice/banks?filters[0][field]=name&filters[0][operator]=contains&filters[0][value]=banc' -H 'Accept: application/json'` (puerto del worktree vía `make docker.info`; recordar que sin `Accept` adecuado Caddy enruta raro — gotcha conocido del repo).

### Gotchas del repo que muerden en esta historia

- **Doble gate stan+psalm por archivo** (zona D5); `make php.behat.install` antes de `php.stan` si falta el vendor de Behat (PHPStan lo usa de bootstrap).
- **PHPMD sin baseline** (solo `make php.quality` completo lo ejecuta): jamás `new readonly class` anónimas; si `TooManyPublicMethods`/`CouplingBetweenObjects` muerde en tests, el precedente de suppression es `@SuppressWarnings("PHPMD.…")` (usado en `ProblemDetailsFactoryTest`/`FilterApplierTest`); OOM 137 transitorio → reintentar.
- **`php` container restart-loop**: si `docker compose exec php` falla (exit 139), correr contra el worker sano: `make php.stan PHP_SERVICE=messenger_worker`.
- PHPStan constant-folda literales de enum en asserts (`method.alreadyNarrowedType`) — derivar expectativas de un provider si muerde (lección 1.1).
- Los asserts Behat de conteo deben filtrar por los datos del fixture set (31 banks deterministas en env test) — no contar tablas enteras.
- `api/config/reference.php` se auto-regenera durante los gates — restaurar, JAMÁS committear. Línea máx. 120 chars. Jamás `--amend`/`--no-verify`; commit nuevo si un hook falla.
- El fixer/rector pueden re-formatear los ficheros nuevos en `php.quality` — segunda pasada idempotente antes de dar el gate por bueno (precedente 1.3).

### Seguridad (checklist CLAUDE.md aplicado a esta historia)

Esta historia ABRE la superficie HTTP del mecanismo — el checklist completo aplica:

- **Input validation (el check central)**: todo el contrato `filters[]` entra por `#[MapQueryString]` + `#[Assert]` en mapping (shape) y por la allow-list del applier (semántica). Caps en mapping (20 filtros × 100 values × 255 chars) acotan el peor caso ANTES de tocar BD. La Task 6 cierra el único hueco 4xx detectado (UUID malformado sobre `id`).
- **Injection**: cubierto por construcción en 1.3 (paths del map repo-authored, valores SIEMPRE bindeados, escape `%`/`_`/`\` en CONTAINS) — esta historia NO añade interpolación nueva; el seam solo pasa objetos.
- **DoS/plan de query**: caps + columnas del map respaldadas por índice (`name_normalized` UNIQUE, `id` PK — verificado en migración); `max_input_vars` 1000 acota el transporte y queda documentado (Task 7). La verificación `EXPLAIN ANALYZE`/p95 formal es gate de la 1.5 (NFR4).
- **Sin oráculo de esquema**: los errores llevan el campo PÚBLICO pedido, nunca paths DQL ni columnas (heredado de 1.2/1.3; mantener en `InvalidSearchValue` si nace — context sin echo del value).
- **AuthN/AuthZ**: el endpoint de banks sigue siendo público consciente (precedente documentado en deferred-work) — sin cambios; declarar el "no aplica" en el PR.
- Sin secretos, sin migraciones, sin mass assignment, sin cambios CORS/Mercure/headers — declarar los "no aplica" en el PR.

### Project Structure Notes

Delta exacto de esta historia (espejo del árbol de fase 1 del Architecture Decision Document, con dos matices: el guard UUID condicional de la Task 6 y `adding-endpoints.md` como hogar de la forma del endpoint):

```text
api/composer.json · api/composer.lock                      [M] promoción phpdoc-parser + type-resolver a require
api/src/Shared/Application/Http/Search/
├── FilterQuery.php                                        [N] DTO anidado + MAX_IN_VALUES=100 + toFilter()
└── SearchQuery.php                                        [M] +filters[] + MAX_FILTERS=20 + domainFilters() en toCriteria()
api/src/Shared/Infrastructure/Persistence/Doctrine/
└── AbstractDoctrineSearchRepository.php                   [M] +FilterApplier inyectado + abstract searchFieldMap() + auto-apply
api/src/Backoffice/Bank/
├── Application/Http/BankSearchQuery.php                   [M] +filters reenviado (docblock @param CRÍTICO)
├── Domain/Search/BankSearchCriteria.php                   [M] +filters transportado al padre
└── Infrastructure/Persistence/Doctrine/DoctrineBankRepository.php  [M] +constructor + searchFieldMap()
api/src/Shared/Infrastructure/Persistence/Doctrine/Search/
├── FieldMapping.php                                       [M condicional Task 6] +requiresUuidValues
└── FilterApplier.php                                      [M condicional Task 6] +guard UUID
api/src/Shared/Domain/Search/Exception/
└── InvalidSearchValue.php                                 [N condicional Task 6] marker existente, type invalid-search-value

api/tests/Unit/Shared/Application/Http/Search/
├── FilterQueryTest.php                                    [N]
└── SearchQueryTest.php                                    [M]
api/tests/Unit/Backoffice/Bank/Application/Http/
└── BankSearchQueryTest.php                                [N si no existe] transporte de filters
api/tests/Functional/Shared/Persistence/
└── FilterApplierTest.php                                  [M condicional Task 6]
api/tests/Behat/Context/HttpRequestContext.php             [M opcional] step del IN×100
api/features/backoffice/bank/search.feature                [M] escenarios del contrato genérico

api/docs/adding-endpoints.md                               [M] gramática + caps + límite efectivo + searchFieldMap()
api/docs/postman/erpify-api.postman_collection.json        [M] params filters[0][…] en Search banks
docs/api-error-contract.md                                 [M condicional Task 6] type concreto nuevo si el doc los enumera
```

- La épica situaba la forma del endpoint en `api/docs/` sin fichero concreto — el hogar correcto existente es `api/docs/adding-endpoints.md` (sección "Search endpoints", ya documenta el boundary y el mapeo `ValidationFailedException`→400) + la colección Postman (ya documenta los query params actuales del request "Search banks").
- Desviación consciente potencial del árbol de fase 1: Task 6 ([M] sobre piezas de la 1.3 + excepción nueva) — justificada arriba; misma rama sin mergear, sin estado roto.
- Cero migraciones, cero `services.yaml`, cero cambios Compose/Make/CI/`.env` (NFR5). El autoload PSR-4 ya cubre todo.
- Para la **story 1.5** (anotar al crearla): con 1.4 entregada, `BankSearchQuery` ya valida y transporta filters; 1.5 reduce su papel a mapear `names[]`/`ids[]` → `Filters` (componiendo con los genéricos vía AND), elimina `BankSearchCriteria` + `addWhereIn`/`addWhereIdsIn` ad hoc y pasa las firmas a `SearchCriteria` base; el Behat de equivalencia debe escribirse ANTES de borrar el camino ad hoc; gate NFR4 (`EXPLAIN ANALYZE` + p95) vive allí.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 1.4] — historia y ACs canónicos; FR2/FR4 parciales; NFR6.
- [Source: _bmad-output/planning-artifacts/architecture.md#Core Architectural Decisions] — D1/D2/D4/D5 (gramática, tokens, base, value polimórfico).
- [Source: _bmad-output/planning-artifacts/architecture.md#Format Patterns] — caps, contigüidad, capa de validación pineada por tipo de error.
- [Source: _bmad-output/planning-artifacts/architecture.md#Structure Patterns / Architectural Boundaries] — seam auto-apply, applier solo invocable desde la base, map solo construible en repositorios.
- [Source: _bmad-output/planning-artifacts/architecture.md#Pattern Examples] — ejemplo canónico de `searchFieldMap()` (con normalizador inyectado) y anti-patterns.
- [Source: _bmad-output/implementation-artifacts/1-3-applier-de-filtros-sobre-querybuilder-con-allow-list-obligatoria.md#Project Structure Notes / Completion Notes] — notas explícitas "para la story 1.4": seam lee `SearchCriteria->filters` y aplica ANTES de paginar; `Filters::fromList()` exige list-ness garantizada en mapping; AC del `SearchExceptionListener` obsoleto; wiring del applier quedó sin decidir (se pinea inyección aquí).
- [Source: api/docs/adding-endpoints.md#Search endpoints] — boundary actual del search endpoint y mapeo `ValidationFailedException` → 400 `validation-failed`.
- [Source: docs/api-error-contract.md] — tabla markers (fila `InvalidSearchCriteria` → 400 ya presente) y bridge de `ValidationFailedException`.
- [Source: api/tests/DataFixtures/Fixtures/Bank.yaml] — 31 banks deterministas para los escenarios.
- [Source: api/migrations/2026/Version20260527115017.php + api/src/Shared/Domain/Entity/Identifiable.php] — `id UUID NOT NULL` / `Types::GUID` (evidencia del hueco de la Task 6).
- [Source: docs/project-context.md#Framework-Specific Rules] — validación en mapping, thin controllers, constructor DI, Doctrine 3/DBAL 4.
- [Source: research/technical-php-criteria-vs-searchcriteria-erpify-research-2026-06-06.md] — verificación Symfony 8.1 doc: `#[MapQueryString]` + arrays de DTOs anidados requieren phpdoc-parser + type-resolver en runtime (base del AC1).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Claude Opus 4.8, 1M context) — Claude Code

### Debug Log References

- Contenedor `php` en restart-loop durante toda la historia — todos los gates ejecutados vía `PHP_SERVICE=messenger_worker` (gotcha conocido del repo, no investigado de nuevo).
- `composer require` movió ambos paquetes de `packages-dev` a `packages` sin bump (2.3.2 / 2.0.0) — verificado por inspección del lock con script Python.
- `composer.check.missing-deps` falla con 7 símbolos desconocidos — verificado PREEXISTENTE en baseline (stash de composer.json/lock + re-run): no lo introduce esta historia, no se toca.
- PHPStan exigió el tipo honesto pre-validación en los docblocks (`string|array<mixed>` para `FilterQuery::value`, `array<int, FilterQuery>` para `filters`): con `list<string>` declarado, los narrows del callback eran `alwaysFalse`/`alreadyNarrowedType`.
- Psalm `PossiblyUnusedMethod`/`PossiblyUnusedParam` sobre métodos invocados reflexivamente (callbacks de Validator, step de Behat): resuelto por el mecanismo establecido del repo — entradas en `psalm-baseline.xml` (precedente: `Bank::validateNormalizedNameLength` y los steps existentes de `HttpRequestContext`); además se RETIRÓ la entrada obsoleta `__construct` de `AbstractDoctrineSearchRepository` (Psalm `UnusedBaselineEntry` — el constructor ahora tiene call-site explícito).
- RED Behat del hueco UUID: `filters[0][field]=id&...[value]=not-a-uuid` produjo el 500 predicho (log `[critical] API error response built` — 22P02) → guard implementado, no especulado.
- Primera edición de la colección Postman re-serializó el JSON entero (783 líneas) — revertida y rehecha quirúrgicamente respetando el formato original (diff final: 7 líneas).
- `api/config/reference.php` auto-regenerado durante los gates — restaurado, jamás committeado.

### Completion Notes List

- Fase 1 (expand) abierta: el contrato genérico `filters[N][field|operator|value]` está VIVO en `GET /api/v1/backoffice/banks`, conviviendo con `names[]`/`ids[]` (camino legacy intacto — D8/camino único es la 1.5).
- `FilterQuery` (D5 completo): `field` NotBlank, `operator` enum nullable+NotNull (token inválido/uppercase → 400 vía denormalizer), `value` polimórfico validado por operador en `#[Assert\Callback]` (shape, blank, caps `MAX_IN_VALUES=100`, 255 chars/valor espejo de `names[]`, list-ness de `value[]`); `toFilter()` → named constructors de `Filter`.
- `SearchQuery` base: `filters[]` con `#[Assert\Valid]` + `Count(max: MAX_FILTERS=20)` + callback `array_is_list` (D1 índices contiguos); `domainFilters()` (final protected) como traducción única DTO→Domain reutilizada por `BankSearchQuery::toCriteria()`. `BankSearchCriteria` transporta `Filters` al padre (sin esto se perdían silenciosamente).
- Seam auto-apply: `AbstractDoctrineSearchRepository` inyecta `FilterApplier` (autowired), declara `abstract searchFieldMap()` y aplica entre `getSearchQueryBuilder()` y la paginación; `getQueryBuilderPaginatedResults()` queda fuera del seam deliberadamente. `DoctrineBankRepository`: constructor nuevo con `NormalizedTextFieldNormalizer` inyectado (espejo del ejemplo canónico) y map `name`→`b.nameNormalized`+normalizador / `id`→`b.id` eq/in+`requiresUuidValues`.
- Hallazgo cerrado (no estaba en la épica): valor no-UUID sobre `id` producía 500 (columna UUID Postgres, 22P02) — confirmado RED-first y cerrado con `FieldMapping::requiresUuidValues` + `FilterApplier::ensureUuidValues()` + excepción nueva `InvalidSearchValue` (type `invalid-search-value`, marker `InvalidSearchCriteria` existente → 400 sin coste NFR26 de marker; context solo con el campo, sin echo del valor). Desviación consciente sobre piezas de la 1.3 (misma rama sin mergear). Decisión validada con el usuario antes de implementar.
- NFR6: `phpstan/phpdoc-parser` ^2.3 + `phpdocumentor/type-resolver` ^2.0 promocionados a `require` (lock: movidos a `packages` sin bump). El mapping anidado quedó probado e2e ANTES del seam: los escenarios shape-400 pasaron en cuanto existieron los DTOs.
- Behat: 14 escenarios nuevos en `search.feature` (felices eq/in/contains, diacríticos en el valor, id in, frontera IN×100 con step dedicado nuevo en `HttpRequestContext`, 2 semánticos con type asseverado y 0 SQL, outline de operadores inválidos `like|EQ|IN`, 2 de value incoherente con `violations[0].field`, uuid malformado). El AC del `SearchExceptionListener` se satisface por los asserts de type/status (el listener no existe — hallazgo 1.2 re-verificado).
- Docs: `api/docs/adding-endpoints.md` (paso `searchFieldMap()` + sección del contrato wire con caps y límite efectivo `min(caps, max_input_vars=1000, URL length)`) + colección Postman (3 params disabled con descriptions) + `docs/api-error-contract.md` (type concreto `invalid-search-value` en la frase de la familia; `make php.lint.error-contract` verde; `MarkerStatusMapContractTest` intacto — cero markers nuevos).
- Seguridad (checklist CLAUDE.md): superficie HTTP nueva validada íntegramente en mapping + allow-list por construcción; sin interpolación nueva (el seam solo pasa objetos); caps acotan el peor caso antes de BD; errores sin oráculo de esquema (campo público, nunca paths DQL ni el valor); columnas del map respaldadas por índice (UNIQUE/PK); endpoint sigue siendo público consciente. No aplica: authn/authz, secretos, migraciones, CORS/Mercure/headers, mass assignment — declarar en el PR.
- Para la **story 1.5**: `BankSearchQuery` ya valida y transporta filters; queda mapear `names[]`/`ids[]`→`Filters` (AND con los genéricos), eliminar `BankSearchCriteria` + `addWhereIn`/`addWhereIdsIn` + el instanceof-check, pasar firmas a `SearchCriteria` base, Behat de equivalencia ANTES de borrar el camino ad hoc, y el gate NFR4 (`EXPLAIN ANALYZE` + p95). Nota: el guard UUID ya cubre la equivalencia de errores de `ids[]` inválidos solo parcialmente — `ids[]=invalid` da `validation-failed` (mapping) y `filters[id][in]=invalid` da `invalid-search-value` (applier); si la 1.5 exige equivalencia EXACTA de types, decidirlo allí.
- Gates finales: stan ✅ psalm ✅ unit 612/612 (+35) ✅ behat 85/85 (+14) ✅ php.quality exit 0 ×2 idempotente ✅ error-contract ✅. Commit `f1ed854` en `feat/shared-search-filters-aj0w` (fase 0 + 1.4 conviven en la rama; la decisión de PR única o separada es del usuario).

### File List

- `api/composer.json` (modificado — promoción NFR6)
- `api/composer.lock` (modificado — paquetes movidos a `packages`)
- `api/src/Shared/Application/Http/Search/FilterQuery.php` (nuevo)
- `api/src/Shared/Application/Http/Search/SearchQuery.php` (modificado — filters[] + MAX_FILTERS + callback + domainFilters())
- `api/src/Shared/Domain/Search/Exception/InvalidSearchValue.php` (nuevo — Task 6)
- `api/src/Shared/Infrastructure/Persistence/Doctrine/AbstractDoctrineSearchRepository.php` (modificado — seam auto-apply + abstract searchFieldMap())
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FieldMapping.php` (modificado — requiresUuidValues, Task 6)
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FilterApplier.php` (modificado — ensureUuidValues, Task 6)
- `api/src/Backoffice/Bank/Application/Http/BankSearchQuery.php` (modificado — filters reenviado + docblock)
- `api/src/Backoffice/Bank/Domain/Search/BankSearchCriteria.php` (modificado — transporta Filters)
- `api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/DoctrineBankRepository.php` (modificado — constructor + searchFieldMap())
- `api/tests/Unit/Shared/Application/Http/Search/FilterQueryTest.php` (nuevo — 23 tests)
- `api/tests/Unit/Shared/Application/Http/Search/SearchQueryTest.php` (modificado — 6 tests nuevos)
- `api/tests/Unit/Backoffice/Bank/Application/Http/BankSearchQueryTest.php` (nuevo — 3 tests)
- `api/tests/Functional/Shared/Persistence/FilterApplierTest.php` (modificado — 3 tests del guard UUID)
- `api/tests/Behat/Context/HttpRequestContext.php` (modificado — step del in-filter generado)
- `api/features/backoffice/bank/search.feature` (modificado — 14 escenarios nuevos)
- `api/tools/psalm/psalm-baseline.xml` (modificado — callbacks reflexivos + params del step + entrada obsoleta retirada)
- `api/docs/adding-endpoints.md` (modificado — contrato wire + searchFieldMap() en el skeleton)
- `api/docs/postman/erpify-api.postman_collection.json` (modificado — params filters[0][…])
- `docs/api-error-contract.md` (modificado — type concreto invalid-search-value)

## Change Log

- 2026-06-07: Story 1.4 implementada y verificada (commit `f1ed854`, rama `feat/shared-search-filters-aj0w`). 21 ficheros, +998/−172. Contrato genérico `filters[]` vivo en el endpoint de banks (expand); hueco UUID→500 detectado en análisis, confirmado RED-first y cerrado con guard en el applier (decisión validada con el usuario). 35 tests unit/integración nuevos + 14 escenarios Behat. Todos los gates en verde (php.quality ×2 idempotente). Status → review.
- 2026-06-07: Code review adversarial (3 capas: Blind Hunter / Edge Case Hunter / Acceptance Auditor). 0 violaciones de AC; 13 hallazgos descartados con evidencia (2 falsificados con sondas wire en vivo: índices sparse → 400 contiguous, `filters[0]=foo` escalar → 400 con violations). 2 patches aplicados: guard `LogicException` en `FieldMapping` contra el combo `requiresUuidValues`+`contains` (+`FieldMappingTest`, 4 tests, + nota en `adding-endpoints.md`) y frase veraz del step Behat IN×100. 2 defer registrados en deferred-work.md (coste de query → NFR4/1.5; índice posicional en `invalid-search-value` → 1.5). Gates post-patch: stan ✅ psalm ✅ unit 616/616 ✅ behat search.feature 34/34 ✅ php.quality ×2 ✅. Status → done.
