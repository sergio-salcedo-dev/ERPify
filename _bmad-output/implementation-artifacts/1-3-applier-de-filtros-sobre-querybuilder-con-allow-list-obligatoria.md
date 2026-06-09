---
baseline_commit: b59a28f5b3efcb0db49cfc6cbe4926cc58334222
---

# Story 1.3: Applier de filtros sobre QueryBuilder con allow-list obligatoria

Status: done

_Ultimate context engine analysis completed — comprehensive developer guide created (2026-06-07)._

## Story

As a desarrollador de un repositorio de búsqueda,
I want un applier genérico que traduzca `Filters` a `andWhere` parametrizados, gobernado por un `SearchFieldMap` obligatorio,
so that ningún campo no autorizado sea filtrable y ningún valor llegue sin bindear a SQL.

## Acceptance Criteria

1. **Given** el subdirectorio nuevo `Shared/Infrastructure/Persistence/Doctrine/Search/`
   **When** se implementan `FilterApplier`, `SearchFieldMap`, `FieldMapping`, `FieldNormalizer` (interface) y `NormalizedTextFieldNormalizer` (envuelve `NormalizedText`)
   **Then** la firma `apply(QueryBuilder, Filters, SearchFieldMap)` hace imposible invocar el applier sin allow-list (enforcement por construcción, NFR2).

2. **Given** un filtro cuyo `field` no tiene entrada en el `SearchFieldMap`
   **When** el applier lo procesa
   **Then** lanza `UnknownSearchField`
   **And** un operador no incluido en los permitidos del `FieldMapping` (default: los tres) lanza `UnsupportedSearchOperator`.

3. **Given** un filtro CONTAINS sobre un campo con normalizador
   **When** el applier lo procesa
   **Then** normaliza el valor, escapa `%` y `_`, y genera `LIKE :param` bindeado sobre el path DQL del map
   **And** campos sin normalizador usan `LOWER(path) LIKE LOWER(:param)`
   **And** el normalizador del campo se aplica también en EQ e IN (equivalencia futura `names[]` ≡ `filters[name][in]` garantizada).

4. **Given** el QueryBuilder de un repositorio
   **When** el applier añade condiciones
   **Then** solo usa `andWhere` + parámetros bindeados con el naming hasheado `xxh128` heredado de `AbstractDoctrineRepository` (nunca interpolación de `field`/`value`)
   **And** `Filters` vacío es un no-op silencioso
   **And** varios filtros sobre el mismo campo componen con AND
   **And** paginación, orden, joins y COUNT siguen siendo monopolio del `Paginator` y de `getSearchQueryBuilder()`.

5. **Given** `FilterApplierTest` de integración
   **When** corre contra Postgres real (nunca SQLite)
   **Then** cubre binding de parámetros, normalización diacrítica, escape de comodines, rechazo de campo fuera de allow-list y rechazo de operador no permitido.

## Tasks / Subtasks

- [x] Task 0: Continuar en la rama de fase 0 (NO crear worktree nuevo) y limpiar el pendiente de la 1.2
  - [x] `cd .claude/worktrees/shared-search-filters-aj0w` — la fase 0 (1.1–1.3) comparte rama/PR `feat/shared-search-filters-aj0w`; la 1.2 dejó el commit `f53e3b8`. Verificar con `git branch --show-current`
  - [x] ⚠️ El working tree NO está limpio: los parches del code review de la 1.2 quedaron SIN committear (`api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` + `docs/api-error-contract.md` — ver Change Log de la 1.2). Committearlos como commit PROPIO antes de empezar la 1.3, p. ej. `test(api): pin unsupported search operator through factory and fix contract doc refs` (staging explícito de solo esos 2 ficheros)
  - [x] Levantar el stack del worktree si no está arriba: `make docker.up` (reintentar ante flake transitorio de health-check)
- [x] Task 1: RED — `FilterApplierTest` de integración contra Postgres real (AC: 5; fija el contrato completo antes de implementar)
  - [x] `api/tests/Functional/Shared/Persistence/FilterApplierTest.php` (namespace `Erpify\Tests\Functional\Shared\Persistence` — ruta pineada por la arquitectura; espejo de convenciones de `DomainEventStoreIdempotencyTest`/`BankCreateEventIdMatchesPersistedPkTest`: `KernelTestCase`, transacción + rollback en `finally`, sin DAMA, datos únicos por sufijo derivado de un UUID)
  - [x] QueryBuilder de los tests: `$entityManager->createQueryBuilder()->select('b')->from(Bank::class, 'b')` (no acoplarse a `DoctrineBankRepository`); banks vía `Bank::create(Uuid::generate(), $name, $shortName)` + persist/flush dentro de la transacción
  - [x] Field map de los tests (espejo del futuro map de Bank): `'name' => new FieldMapping('b.nameNormalized', new NormalizedTextFieldNormalizer())` · `'shortName' => new FieldMapping('b.shortName')` (SIN normalizador — ejercita el fallback LOWER) · `'id' => new FieldMapping('b.id', operators: [FilterOperator::Eq, FilterOperator::In])`
  - [x] Cobertura mínima (un comportamiento por test): EQ con normalizador (valor con diacríticos/mayúsculas matchea la fila); IN con normalizador (equivalencia futura `names[]`); CONTAINS con normalizador y término diacrítico (`'ÑANDÚ'` encuentra `'Bánçó Ñandú …'`); CONTAINS sin normalizador (fallback `LOWER(path) LIKE LOWER(:param)` case-insensitive sobre `shortName`); escape de `%` (fila con `%` literal matchea, fila con otro carácter en esa posición NO — el comodín no actúa); escape de `_` (ídem un solo carácter); campo fuera del map → `UnknownSearchField`; `contains` sobre `id` → `UnsupportedSearchOperator`; `Filters::none()` → DQL idéntico antes/después (no-op); dos CONTAINS sobre `name` → AND (solo la fila que satisface ambos); binding: tras `apply`, los parámetros existen, sus nombres empiezan por `p` y NI el valor buscado NI el nombre público del campo aparecen en el DQL generado
  - [x] Guards degenerados (cierre de los Review Findings diferidos de la 1.1): `Filter::in('name', [])` → `InvalidArgumentException`; `Filter::contains('name', '   ')` (normaliza a `''`) → `InvalidArgumentException`
  - [x] Confirmar RED: la suite falla por clases inexistentes (errores, no failures espurios)
- [x] Task 2: `FieldNormalizer` + `NormalizedTextFieldNormalizer` (AC: 1, 3)
  - [x] `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FieldNormalizer.php` — interface con un único método `normalize(string $value): string`
  - [x] `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/NormalizedTextFieldNormalizer.php` — `final readonly`, implementa la interface delegando en `NormalizedText::normalize($value)` (import de `Erpify\Shared\Domain\ValueObject\NormalizedText`: Infrastructure → Domain, dirección correcta)
- [x] Task 3: `FieldMapping` + `SearchFieldMap` (AC: 1, 2)
  - [x] `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FieldMapping.php` — `final readonly`; `__construct(public string $dqlPath, public ?FieldNormalizer $normalizer = null, array $operators = [FilterOperator::Eq, FilterOperator::In, FilterOperator::Contains])` (los enum cases SON expresiones constantes válidas como default); helper `allows(FilterOperator $operator): bool`
  - [x] `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/SearchFieldMap.php` — `final readonly`; `__construct(array $mappings)` con `@param array<string, FieldMapping>` (clave = nombre PÚBLICO del campo); accessor único `mappingFor(string $field): ?FieldMapping` (null si no mapeado — quien LANZA es el applier, fiel a los docblocks de las excepciones de la 1.2)
- [x] Task 4: `FilterApplier` (AC: 1, 2, 3, 4)
  - [x] `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FilterApplier.php` — `final readonly`, sin estado, `apply(QueryBuilder $queryBuilder, Filters $filters, SearchFieldMap $fieldMap): void`; early-return si `$filters->isEmpty()`
  - [x] Por filtro, en este orden: 1) `mappingFor` → null ⇒ `UnknownSearchField::named($filter->field)`; 2) `allows` → false ⇒ `UnsupportedSearchOperator::forField($filter->field, $filter->operator)`; 3) narrow del value (`\is_string`/`\is_array` según operador — zona D5 de discrepancia PHPStan↔Psalm, ver Dev Notes); 4) guard degenerado (IN lista vacía / CONTAINS normalizado a `''` ⇒ `InvalidArgumentException`); 5) normalizar (si hay normalizador: a TODOS los operadores; en IN, item a item); 6) bind + `andWhere`
  - [x] Naming de parámetros: REPLICAR la fórmula privada de `AbstractDoctrineRepository::generateUniqueParameterName` — `'p' . \hash('xxh128', $queryBuilder->getDQL()) . \count($queryBuilder->getParameters())` — como método privado del applier, con el mismo why-comment (estabilidad de los SQL cache files). NO ampliar visibilidad en la clase base (el applier no es un repositorio) — duplicación consciente de 1 línea en 2 sitios documentados
  - [x] Ramas DQL (el path sale SIEMPRE del map, jamás del filtro): EQ → `{path} = :p…`; IN → `{path} IN (:p…)` (array bindeado — ORM infiere el tipo array); CONTAINS con normalizador → `{path} LIKE :p…` con valor `'%' . escape(normalize($v)) . '%'`; CONTAINS sin normalizador → `LOWER({path}) LIKE LOWER(:p…)` con `'%' . escape($v) . '%'`
  - [x] Escape LIKE (D6): `\` → `\\` PRIMERO, después `%` → `\%` y `_` → `\_` (backslash es el carácter de escape por defecto de Postgres; el valor va bindeado, sin problema de literales SQL)
  - [x] GREEN: `make php.unit c='--filter FilterApplierTest'` en verde
- [x] Task 5: Obligación documental del directorio nuevo (regla CLAUDE.md de docs por PR)
  - [x] `docs/source-tree-analysis.md` — añadir la fila/entrada de `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/` (applier + field map del mecanismo de filtros) junto a las entradas existentes de `Shared/Infrastructure`
  - [x] `docs/claude-code-quickref.md` — reflejar el subdirectorio nuevo donde el doc liste el layout de `api/src/`
  - [x] Alcance MÍNIMO (una entrada por doc): el patrón completo + receta "añadir una lista filtrable" en `docs/architecture-api.md` es de la story 1.6 — ver nota de conflicto en Project Structure Notes
- [x] Task 6: Gates de calidad y regresión (AC: 1–5)
  - [x] `make php.stan` y `make php.psalm` sobre cada archivo tocado (AMBOS — discrepancias conocidas, especialmente con el union `string|list<string>`)
  - [x] `make php.unit` — suite completa en verde (baseline: 560 OK de la 1.2 + 1 pin añadido por sus parches de review = 561 + 3 skips preexistentes, antes de los tests nuevos de esta historia)
  - [x] `make php.behat` — 71 escenarios / 29 de `search.feature` en verde (regresión trivial: nada invoca el applier todavía; `make php.behat.install` primero si falta el vendor — PHPStan también lo usa de bootstrap)
  - [x] `make php.quality` completo (PHPMD sin baseline; reintentar ante OOM 137 transitorio)
  - [x] Commit convencional en la rama de fase 0, p. ej. `feat(api): add filter applier over querybuilder with mandatory allow-list` (staging explícito de solo los ficheros de la historia; jamás `api/config/reference.php`)

### Review Findings

- [x] [Review][Patch] EQ y los ítems de IN aceptan valores que normalizan a vacío (CONTAINS sí los guarda) — decisión del usuario: añadir guards equivalentes ahora como defensa en profundidad (`InvalidArgumentException` + tests espejo) [api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FilterApplier.php] (source: blind+edge) — aplicado en `96682ba` (helper `normalizedNotBlank` compartido por los tres operadores + 2 tests)
- [x] [Review][Patch] La rama de escape de backslash literal (`'\\' → '\\\\'`) no tiene test de integración — `%` y `_` tienen tests con fila señuelo pero el backslash (precisamente el carácter de escape de LIKE en Postgres) no; añadir el test espejo con valor literal `\` para pinear la rama contra Postgres real [api/tests/Functional/Shared/Persistence/FilterApplierTest.php] (source: blind+edge) — aplicado en `96682ba` (`testContainsEscapesBackslash`, señuelo `ab` que un patrón sin escapar sí matchearía)

## Dev Notes

### Contexto y alcance (leer antes de tocar nada)

Tercera y ÚLTIMA historia de la **fase 0** del mecanismo compartido de filtros (research opción C). La fase 0 es **estrictamente sin cambio de contrato HTTP**: el applier creado aquí NO es invocado por nadie en producción todavía — el auto-apply del seam llega en la 1.4. Esta historia completa el núcleo: con ella, fase 0 queda entregada (vocabulario 1.1 + rama de error 1.2 + applier/allow-list 1.3) y el PR de fase 0 queda listo para abrirse.

**Trabajo en curso — misma rama:** worktree `.claude/worktrees/shared-search-filters-aj0w`, rama `feat/shared-search-filters-aj0w` (1.1 → `18e1a2f`, 1.2 → `f53e3b8`). ⚠️ Los parches del review de la 1.2 están aplicados pero SIN committear (2 ficheros) — Task 0 los committea aparte antes de empezar.

**Fuera del alcance de esta historia (NO hacer):**

- ❌ NO tocar `AbstractDoctrineSearchRepository` — el método abstracto `searchFieldMap()` y el auto-apply son la story 1.4 (la arquitectura describe el seam completo, pero su wiring es fase 1; método abstracto + implementación de Bank deben caer en el MISMO PR para no dejar estado intermedio roto).
- ❌ NO tocar `DoctrineBankRepository` (su `searchFieldMap()` llega en 1.4; sus `addWhereIn` ad hoc se eliminan en 1.5).
- ❌ NO tocar `SearchQuery`, `BankSearchQuery`, ni crear `FilterQuery` (story 1.4, fase 1). Nada de `Application/Http`.
- ❌ NO leer `SearchCriteria->filters` desde ningún sitio: el applier recibe `Filters` directamente, no `SearchCriteria`.
- ❌ NO modificar `Filter`/`Filters`/`FilterOperator` (1.1) ni `InvalidSearchCriteria`/`UnknownSearchField`/`UnsupportedSearchOperator` (1.2) — consumir tal cual; las named constructors son el contrato estable.
- ❌ NO registrar el applier en `services.yaml` ni inyectarlo en ningún servicio (sin consumidor hasta 1.4; el autodiscovery de Symfony ya lo registra solo).
- ❌ Cero dependencias nuevas en `api/composer.json` (la promoción phpdoc-parser/type-resolver es de la 1.4).
- ❌ NO añadir Behat (la superficie HTTP no cambia; los escenarios de filtros llegan con la 1.4).
- ❌ NO añadir operadores más allá de `eq`/`in`/`contains`, ni OR/grupos (deferred explícito).
- ❌ NO transportar `Filters`/`SearchCriteria` en Messenger/Mercure (read-path síncrono).
- ❌ NO borrar ni copiar código de `php-criteria-main/` (gitignored; su retirada es la 1.6; sin licencia declarada = solo ideas).

### Estado actual del código que se consume (verificado 2026-06-07 en el worktree)

**Vocabulario 1.1 (`Erpify\Shared\Domain\Search`)** — firmas exactas que el applier consume:

- `Filter`: `final readonly`, propiedades públicas `field` (nombre PÚBLICO, jamás path DQL), `operator` (`FilterOperator`), `value` (`string|list<string>`). Named constructors `eq()`/`in()`/`contains()` fijan el shape del value por operador. SIN validación interna: `Filter::in('x', [])` y `Filter::contains('x', '')` construyen — el applier es quien los rechaza (Review Finding diferido de la 1.1, decisión 2026-06-07).
- `Filters`: `isEmpty()`, `all(): list<Filter>`, `Countable` + `IteratorAggregate`. `Filters::none()` para el caso vacío.
- `FilterOperator`: enum backed `Eq='eq'`, `In='in'`, `Contains='contains'`.

**Excepciones 1.2 (`Erpify\Shared\Domain\Search\Exception`)** — el applier es su PRIMER call-site de producción (sus docblocks ya dicen "Thrown by the filter applier"):

- `UnknownSearchField::named(string $field): self` — campo sin entrada en el map.
- `UnsupportedSearchOperator::forField(string $field, FilterOperator $operator): self` — pasa la INSTANCIA del enum; la excepción ya extrae `->value` internamente para el context.
- Ambas implementan el marker `InvalidSearchCriteria` → el pipeline RFC 9457 ya las mapea a 400 sin wiring (verificado con pins de integración en `ProblemDetailsFactoryTest`).

**`AbstractDoctrineRepository`** (`Shared/Infrastructure/Persistence/Doctrine/`) — referencia, NO se toca:

- `generateUniqueParameterName()` es **PRIVADO** y el applier NO es un repositorio — no se puede heredar ni llamar. Fórmula exacta a replicar: `'p' . \hash('xxh128', $queryBuilder->getDQL()) . \count($queryBuilder->getParameters())`. El why-comment original explica que el nombre debe ser estable entre ejecuciones para no multiplicar SQL cache files de Doctrine — replicar también la idea del comentario. NO ampliar visibilidad en la base.
- Patrón de referencia de `addWhereIn`: calcular nombre (con el DQL/params actuales) → `setParameter` → `andWhere`. Cada `andWhere` cambia el DQL y cada bind incrementa el count ⇒ el siguiente nombre nunca colisiona, incluso con el mismo campo dos veces.
- El `sprintf` del legacy interpola SOLO alias+field de autoría del repo — el applier hace lo equivalente: interpola SOLO `FieldMapping->dqlPath` (autoría del repositorio vía map, nunca input del cliente).

**`NormalizedText`** (`Shared/Domain/ValueObject`): `NormalizedText::normalize(string $raw): string` — estático, `trim` + transliteración ICU `Any-Latin; Latin-ASCII; Lower()`. `'  BÁnÇó  '` → `'banco'`. Es lo que `Bank::create()` usa para poblar `nameNormalized` ⇒ normalizar el valor de búsqueda con el MISMO método garantiza el match exacto en EQ/IN (equivalencia futura `names[]` ≡ `filters[name][in]`).

**`Bank`** (entidad para el test de integración): `Bank::create(string $id, string $name, string $shortName)` — normaliza `name` → `nameNormalized` (column UNIQUE, lowercase sin diacríticos) y `shortName` → ASCII upper (column UNIQUE). ⚠️ Columnas únicas: SIEMPRE sufijo único por fila (patrón de `BankCreateEventIdMatchesPersistedPkTest`: `$suffix = \strtoupper(\substr(\str_replace('-', '', $id), 0, 8))`).

**Convención de tests funcionales de persistencia** (`api/tests/Functional/Shared/Persistence/`): `KernelTestCase`, `self::bootKernel()`, `EntityManagerInterface` del contenedor, `$connection->beginTransaction()` + rollback en `finally` (la suite NO tiene DAMA y comparte la BD dev — no dejar filas), `#[CoversClass]`, `/** @internal */`, clase `final`.

### Decisiones arquitectónicas vinculantes (no inventar variantes)

| Pieza | FQCN |
|---|---|
| Applier | `Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\FilterApplier` |
| Allow-list | `Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\SearchFieldMap` |
| Entrada del map | `Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\FieldMapping` |
| Normalizador (interface) | `Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\FieldNormalizer` |
| 1ª implementación | `Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\NormalizedTextFieldNormalizer` |

- **Firma pineada**: `apply(QueryBuilder, Filters, SearchFieldMap)` — el `SearchFieldMap` requerido hace la allow-list imposible de omitir (NFR2). Subdirectorio `Doctrine/Search/` (no flat).
- **D6 — CONTAINS**: normalizar → escapar `%`/`_` → `LIKE :param` bindeado sobre el path DQL del map. Campos SIN normalizador: `LOWER(path) LIKE LOWER(:param)`.
- **D7 — Normalizador**: el `FieldNormalizer` de un campo se aplica al valor en TODOS sus operadores (eq, in item a item, contains).
- **Boundaries**: `FilterApplier` será invocado EXCLUSIVAMENTE por `AbstractDoctrineSearchRepository` (en 1.4); `SearchFieldMap` se construirá EXCLUSIVAMENTE en cada repositorio concreto; `Domain/Search` NO conoce DQL ni field maps (la traducción nombre público → path es monopolio de estas clases). En esta historia el único call-site es el test.
- **Monopolios intactos**: el applier solo añade `andWhere` + binds. Paginación, orden, joins, COUNT y `setMaxResults` son del `Paginator` y de `getSearchQueryBuilder()` — el applier no los toca.
- `FieldMapping` declara operadores permitidos por campo, default los tres (Bank restringirá `id` → `eq`/`in` en la 1.4 — aquí solo se ejercita en el map del test).
- Varios filtros sobre el mismo campo → AND (todos se aplican). `Filters` vacío → no-op silencioso.

### Decisiones pineadas para los huecos del diseño (no re-decidir)

1. **Naming xxh128**: método privado del applier replicando la fórmula de la base (duplicación consciente de 1 línea; ambos sitios con why-comment). Razón: el helper de la base es privado y el applier no es repositorio; extraer un util compartido sería más código que el problema.
2. **Valores degenerados** (cierra los Review Findings diferidos de la 1.1): `in` con lista vacía → `InvalidArgumentException`; `contains` cuyo valor (tras normalizar, o tras trim si no hay normalizador) queda `''` → `InvalidArgumentException`. Razón: tras la 1.4 son irrepresentables desde el wire (`#[Assert]` los rechaza en mapping) ⇒ si llegan al applier es error de programador → fallo RUIDOSO con excepción nativa, NO excepción de dominio (no es input corregible por el cliente: no debe ser 400) y NO silencioso (IN vacío genera SQL roto/semántica rota; `LIKE '%%'` ampliaría resultados sin avisar). `eq ''` NO se guarda: semántica exacta inofensiva (no matchea nada).
3. **API de `SearchFieldMap`**: accessor único `mappingFor(string $field): ?FieldMapping`. Quien lanza `UnknownSearchField` es el APPLIER (los docblocks de la 1.2 lo pinean), no el map. Sin `has()`+`get()` dobles (evita doble lookup y métodos extra para PHPMD).
4. **`apply(): void`** — muta el QueryBuilder recibido; no devolver el QB (no invitar a chaining; el legacy devuelve QB pero ese contrato es de la base, no del applier).
5. **Narrow del value (zona D5)**: en cada rama del operador, comprobar `\is_string()`/`\is_array()` y lanzar `InvalidArgumentException` si no cuadra — el check runtime narra el tipo a AMBOS analizadores sin `@var` y dobla como guard defensivo. NO perseguir a un analizador hasta romper el otro.
6. **Escape LIKE**: `\` primero (`\` → `\\`), luego `%` → `\%` y `_` → `\_`. Backslash es el escape por defecto de Postgres en LIKE; sin cláusula `ESCAPE` explícita. El patrón viaja como parámetro bindeado.

### Diseño recomendado (guía; las restricciones de arriba mandan)

```php
// FilterApplier.php — esqueleto orientativo
final readonly class FilterApplier
{
    public function apply(QueryBuilder $queryBuilder, Filters $filters, SearchFieldMap $fieldMap): void
    {
        if ($filters->isEmpty()) {
            return;
        }

        foreach ($filters as $filter) {
            $mapping = $fieldMap->mappingFor($filter->field)
                ?? throw UnknownSearchField::named($filter->field);

            if (!$mapping->allows($filter->operator)) {
                throw UnsupportedSearchOperator::forField($filter->field, $filter->operator);
            }

            match ($filter->operator) {
                FilterOperator::Eq => /* narrow string → normalizar → = :param */,
                FilterOperator::In => /* narrow lista no vacía → normalizar items → IN (:param) */,
                FilterOperator::Contains => /* narrow string → normalizar → guard '' → escapar → %…% → LIKE */,
            };
        }
    }

    private function uniqueParameterName(QueryBuilder $queryBuilder): string
    {
        // Mismo esquema estable que AbstractDoctrineRepository::generateUniqueParameterName
        // (nombre derivado del DQL para no multiplicar SQL cache files de Doctrine).
        return 'p' . \hash('xxh128', $queryBuilder->getDQL()) . \count($queryBuilder->getParameters());
    }
}
```

- Orden por condición: nombre → `setParameter` → `andWhere` (como `addWhereIn` de la base).
- `NormalizedTextFieldNormalizer`: una línea — `return NormalizedText::normalize($value);`. No envolver `NormalizedText::from()` (eso construye el par display/normalized; aquí solo hace falta la mitad normalizada).
- Sin constructor en `FilterApplier` (estateless); `new FilterApplier()` en el test. La 1.4 decidirá su wiring en el seam.
- PHP 8.5 bleeding-edge: idioms 8.3-forward (readonly, match, nullsafe, first-class callables); NO inventar sintaxis 8.5 de memoria.

### Testing

- **Un único fichero de test en esta historia**: `api/tests/Functional/Shared/Persistence/FilterApplierTest.php` — integración contra Postgres REAL (jamás SQLite; el contenedor `database` del stack del worktree). La arquitectura pinea esta ruta exacta; las clases nuevas obtienen sus call-sites Psalm a través de él (lección 1.1/1.2: Psalm analiza tests).
- Convenciones: `declare(strict_types=1)`, namespace espejo, `/** @internal */`, `#[CoversClass(FilterApplier::class)]`, `final`, métodos `testXxx(): void` por comportamiento, AAA. Transacción + rollback en `finally`; sufijos únicos por fila (columnas UNIQUE de bank).
- Ejecutar el filtro: `make php.unit c='--filter FilterApplierTest'` (desde repo root del WORKTREE; necesita el stack arriba). Suite completa: `make php.unit`.
- Datos sugeridos: `Bank::create($id, 'Bánçó Ñandú ' . $suffix, 'BNU' . $suffix)` para diacríticos; un par `'Banco 100% Legal …'` / `'Banco 100x Legal …'` para el escape de `%`; un par con un solo carácter de diferencia para `_`. Para asserts de resultados usar `$queryBuilder->getQuery()->getResult()` y comparar ids.
- Asserts de no-interpolación (AC4): tras `apply`, `$queryBuilder->getParameters()->count() > 0` y `self::assertStringNotContainsString('nandu', $queryBuilder->getDQL())` (el valor NUNCA aparece en el DQL; solo `:p…`).
- El rechazo (`UnknownSearchField`/`UnsupportedSearchOperator`) y los guards (`InvalidArgumentException`) se prueban en el mismo fichero con `$this->expectException(...)` — no necesitan ejecutar la query.

### Gotchas del repo que muerden en esta historia

- **Doble gate obligatorio**: PHPStan y Psalm discrepan en el union `string|list<string>` (precedente documentado). Pasar AMBOS por archivo; el narrow con `\is_string()`/`\is_array()` (decisión pineada 5) es la salida que satisface a los dos.
- **PHPMD sin baseline**: solo `make php.quality` completo lo ejecuta. JAMÁS clases anónimas `new readonly class` (PDepend aborta `php.md` con Error 3). Si `CouplingBetweenObjects` muerde en el test (≥13 imports), el precedente de suppression es `@SuppressWarnings("PHPMD.CouplingBetweenObjects")` (ya usado en `MarkerStatusMapContractTest`/`ProblemDetailsFactoryTest`); el paso phpmd puede fallar transitoriamente con OOM (Error 137) — reintentar antes de investigar.
- **`php` container restart-loop**: si `docker compose exec php` falla (zend_mm_heap corrupted / exit 139), ejecutar contra el worker sano: `make php.stan PHP_SERVICE=messenger_worker`.
- **Behat aislado**: `make php.behat.install` antes de `php.stan`/`php.behat` si `api/tools/behat/vendor/` falta (PHPStan lo usa de bootstrap — lección 1.1).
- **El fixer renombra data providers** a `provide<TestName>Cases` — no luchar contra ello. PHPStan constant-folda literales de enum en asserts (`method.alreadyNarrowedType`) — si muerde, derivar la expectativa de un provider (lección 1.1).
- El test funcional comparte la BD dev del stack del worktree: si un assert de conteo global falla, sospechar de filas preexistentes — los asserts deben filtrar SIEMPRE por los ids/sufijos creados en el propio test, nunca contar tablas enteras.
- `make php.lint.error-contract` NO aplica aquí (ningún marker cambia). No tocar `docs/api-error-contract.md` en esta historia.
- Línea máx. 120 caracteres; comentarios solo de why no obvio; jamás `--amend`/`--no-verify`; commit NUEVO si un hook falla; `api/config/reference.php` jamás al staging.

### Seguridad (checklist CLAUDE.md aplicado a esta historia)

Esta ES la historia de seguridad del mecanismo — el punto de inyección vive aquí:

- **Injection (el check central)**: NINGÚN valor del cliente se interpola en DQL. El único `sprintf`/interpolación admisible es `FieldMapping->dqlPath` + el nombre de parámetro generado — ambos de autoría del repositorio (el path sale del map construido en código, jamás del `Filter`). `field` y `value` viajan: el primero solo como clave de lookup del map, el segundo SIEMPRE como parámetro bindeado. El test de no-interpolación (AC4) lo pinea.
- **Escape de comodines (D6)**: `%`/`_` escapados tras normalizar — un valor de búsqueda no puede convertirse en patrón arbitrario (no se puede degradar el plan de query con `%a%b%c%` inyectado).
- **Allow-list por construcción (NFR2)**: parámetro requerido en la firma; campo fuera del map → `UnknownSearchField` → 400 vía pipeline (sin oráculo de esquema: el mensaje lleva el campo PÚBLICO pedido, nunca paths DQL ni columnas).
- Sin superficie HTTP nueva (sigue inalcanzable desde el wire), sin authn/authz, sin mass assignment, sin secretos, sin migraciones — declarar los "no aplica" en el PR de fase 0.

### Project Structure Notes

Delta exacto de esta historia (cierra el árbol de fase 0 del Architecture Decision Document):

```text
api/src/Shared/Infrastructure/Persistence/Doctrine/Search/   [N] subdirectorio nuevo
├── FilterApplier.php                                        [N] andWhere + binds (xxh128)
├── SearchFieldMap.php                                       [N] allow-list obligatoria
├── FieldMapping.php                                         [N] path DQL + operadores + normalizador
├── FieldNormalizer.php                                      [N] interface
└── NormalizedTextFieldNormalizer.php                        [N] envuelve NormalizedText::normalize

api/tests/Functional/Shared/Persistence/
└── FilterApplierTest.php                                    [N] integración Postgres real

docs/source-tree-analysis.md                                 [M] entrada del subdirectorio nuevo
docs/claude-code-quickref.md                                 [M] entrada del subdirectorio nuevo
```

- Autoload PSR-4 ya cubre ambos lados; sin cambios de config, Compose, Make ni `.env`. Sin migraciones ni esquema (NFR5). `composer.json` intacto.
- **Conflicto documental detectado y resuelto así**: la regla CLAUDE.md "directorio `src/` nuevo → actualizar quickref + architecture-api + source-tree EN EL MISMO PR" choca con la asignación de la épica (source-tree + quickref y el patrón/receta de `architecture-api.md` a la story 1.6, fase 1). Como `Doctrine/Search/` nace en el PR de fase 0 (1.1–1.3), esta historia añade las entradas MÍNIMAS en source-tree-analysis + quickref (Task 5) para cumplir la regla del repo; el patrón completo + receta en `architecture-api.md` sigue siendo de la 1.6 (que verificará las entradas en vez de duplicarlas). Señalado aquí para que nadie lo re-litigue en el PR.
- Para la **story 1.4** (anotar al crearla): el seam debe leer `SearchCriteria->filters` y llamar `apply()` ANTES de paginar; `Filters::fromList()` exige list-ness garantizada en mapping (Review Finding diferido de la 1.1 sobre claves string); el AC del legacy `SearchExceptionListener` quedó obsoleto (el listener ya no existe — hallazgo de la 1.2).

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 1.3] — historia y ACs canónicos; FR3/NFR2.
- [Source: _bmad-output/planning-artifacts/architecture.md#Naming Patterns] — FQCNs pineados de las 5 piezas.
- [Source: _bmad-output/planning-artifacts/architecture.md#Process Patterns] — D6 (CONTAINS), D7 (normalizador en todos los operadores), xxh128, no-op vacío.
- [Source: _bmad-output/planning-artifacts/architecture.md#Architectural Boundaries] — applier solo invocable desde la base (1.4), map solo construible en repositorios, Domain sin DQL.
- [Source: _bmad-output/planning-artifacts/architecture.md#Pattern Examples] — ejemplo canónico de `searchFieldMap()` (espejo del map del test) y anti-patterns prohibidos.
- [Source: _bmad-output/implementation-artifacts/1-1-vocabulario-de-filtros-en-el-dominio-compartido.md#Review Findings] — findings diferidos al applier (in-vacío, contains-vacío) que esta historia cierra.
- [Source: _bmad-output/implementation-artifacts/1-2-errores-de-busqueda-invalida-en-el-pipeline-rfc-9457.md#Dev Agent Record] — named constructors estables, parches de review sin committear, lecciones de gates.
- [Source: api/src/Shared/Infrastructure/Persistence/Doctrine/AbstractDoctrineRepository.php] — fórmula xxh128 (privada — replicar) y patrón `addWhereIn`.
- [Source: api/src/Shared/Domain/ValueObject/NormalizedText.php] — `normalize()` estático que envuelve la 1ª implementación del normalizador.
- [Source: api/tests/Functional/Backoffice/Bank/BankCreateEventIdMatchesPersistedPkTest.php] — convención de test funcional con persist real + rollback + sufijos únicos.
- [Source: docs/project-context.md#Language-Specific Rules] — PHP 8.5, strict_types, readonly, excepciones para error flow, Infrastructure → Domain permitido.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Claude Opus 4.8, 1M context) — Claude Code

### Debug Log References

- RED inicial: 13 tests / 13 errores (clases inexistentes) — fallo confirmado antes de implementar.
- Colisión UNIQUE en `short_name` con varios banks por test: el sufijo único tomaba los PRIMEROS 8 hex del UUID, pero UUID v7 es time-ordered y ese prefijo es timestamp compartido por ids acuñados en el mismo run — fix: tomar los ÚLTIMOS 8 (porción aleatoria). El precedente (`BankCreateEventIdMatchesPersistedPkTest`) no mordía porque crea 1 sola fila.
- PHPMD `TooManyPublicMethods` (13 > 10) en `FilterApplierTest` — suprimido con `@SuppressWarnings("PHPMD.TooManyPublicMethods")`, precedente directo de `ProblemDetailsFactoryTest` (que suprime esa y 4 más).
- **Tug-of-war rector↔psalm descubierto** (primer test del repo que sobreescribe `tearDown`): `NoSetupWithParentCallOverrideRector` (set phpunitCodeQuality) elimina el `#[Override]` de `tearDown()` con llamada a parent, y Psalm `MissingOverrideAttribute` (error level) lo exige de vuelta — `php.quality` no podía pasar nunca. Tres resoluciones iteradas con feedback del usuario: (1) skip de la regla en `rector.php` → descartada (preferencia: rector gana sobre Psalm); (2) `@psalm-suppress MissingOverrideAttribute` a nivel de clase (a nivel de método Psalm lo ignora para este issue) → descartada (preferencia: sin comentarios de supresión en el código); (3) **definitiva**: reestructurar el test sin override de `tearDown` — helper privado `inRolledBackTransaction(callable)` con `try/finally` que envuelve solo los 7 tests que persisten filas. Ninguna regla matchea, ningún override que reclamar, cero supresiones nuevas, cero cambios de config. Verificado idempotente: dos pasadas consecutivas de `php.quality` en verde sin cambios.
- Rector aplicó dos refactors menores en verde: promoción del param `$operators` a constructor property promotion en `FieldMapping` (re-envuelto a multilínea por el límite de 120) y `null === $normalizer` → `!$normalizer instanceof FieldNormalizer` en el applier.
- `api/config/reference.php` se auto-regeneró durante los gates — restaurado, jamás committeado.

### Completion Notes List

- Núcleo de fase 0 completado: `FilterApplier::apply(QueryBuilder, Filters, SearchFieldMap)` con allow-list imposible de omitir por firma (NFR2); `SearchFieldMap::mappingFor()` (accessor único nullable — quien lanza es el applier); `FieldMapping` con path DQL + normalizador opcional + operadores permitidos (default los tres); `FieldNormalizer` interface + `NormalizedTextFieldNormalizer` delegando en `NormalizedText::normalize()`.
- Naming de parámetros: fórmula xxh128 de `AbstractDoctrineRepository::generateUniqueParameterName` replicada como método privado del applier (duplicación consciente de 1 línea — el helper de la base es privado y el applier no es repositorio), con el mismo why-comment de estabilidad de SQL cache.
- D6/D7 cumplidos: normalizador aplicado en TODOS los operadores (eq, in item a item, contains); CONTAINS normaliza → escapa `\`/`%`/`_` (backslash primero) → `LIKE :param` bindeado; campos sin normalizador `LOWER(path) LIKE LOWER(:param)`.
- Review Findings diferidos de la 1.1 cerrados: `in([])` y `contains` que normaliza a `''` lanzan `InvalidArgumentException` con comentario del why (irrepresentables desde el wire tras la 1.4; error de programador → fallo ruidoso, no 400, no silencioso).
- Excepciones de la 1.2 consumidas tal cual vía named constructors (primer call-site de producción, como pineaban sus docblocks).
- Test de integración contra Postgres real (13 tests): eq/in/contains con diacríticos, fallback LOWER, escape de `%` y `_` con filas señuelo, rechazos (campo/operador), no-op de `Filters` vacío, AND mismo campo, no-interpolación (valor ausente del DQL, params `p…` bindeados), guards degenerados.
- Docs del directorio nuevo: filas alineadas en `docs/source-tree-analysis.md` y `docs/claude-code-quickref.md` (alcance mínimo; patrón completo + receta sigue siendo de la 1.6 — conflicto documental señalado y resuelto en Project Structure Notes).
- Gates finales: `php.stan` ✅ · `php.psalm` ✅ · `php.unit` 574/574 (3 skips preexistentes; +13 sobre baseline 561) ✅ · `php.behat` 71 escenarios / 580 steps ✅ · `php.quality` completo exit 0, idempotente en segunda pasada ✅.
- Seguridad (checklist CLAUDE.md): injection es el check central de esta historia — ningún valor/campo del cliente se interpola en DQL (solo `dqlPath` del map de autoría del repo y el nombre de parámetro generado); valores siempre bindeados; comodines escapados; el mensaje de error lleva solo el campo público (sin oráculo de esquema). Sin superficie HTTP nueva (sigue inalcanzable desde el wire), sin authn/authz/mass-assignment/secretos/migraciones — declarar los "no aplica" en el PR de fase 0.
- Para la **story 1.4** (además de lo ya anotado en Project Structure Notes): el wiring del seam puede instanciar `new FilterApplier()` o inyectarlo — sin decisión tomada aquí; `NormalizedTextFieldNormalizer` ya es servicio autodiscovered si se prefiere inyección.
- Commits `b59a28f` (parches review 1.2 pendientes — Task 0) y `753e051` (historia 1.3) en `feat/shared-search-filters-aj0w`. La fase 0 (1.1–1.3) queda completa en esta rama; PR de fase 0 listo para abrirse cuando el usuario lo decida.

### File List

- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FilterApplier.php` (nuevo)
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/SearchFieldMap.php` (nuevo)
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FieldMapping.php` (nuevo)
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FieldNormalizer.php` (nuevo)
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/NormalizedTextFieldNormalizer.php` (nuevo)
- `api/tests/Functional/Shared/Persistence/FilterApplierTest.php` (nuevo)
- `docs/source-tree-analysis.md` (modificado — fila del subdirectorio nuevo)
- `docs/claude-code-quickref.md` (modificado — fila del subdirectorio nuevo)

## Change Log

- 2026-06-07: Task 0 — parches del review de la 1.2 committeados aparte (`b59a28f`).
- 2026-06-07: Story 1.3 implementada y verificada (commit `753e051`, rama `feat/shared-search-filters-aj0w`). 9 ficheros, +577 líneas. 13 tests de integración nuevos contra Postgres real. Todos los gates en verde. Status → review.
- 2026-06-07: Refinamiento por feedback del usuario (commit `2a3ad6c`): revertido el skip de `NoSetupWithParentCallOverrideRector` en rector.php (rector gana sobre Psalm) y eliminado el `@psalm-suppress` intermedio (sin comentarios de supresión) — el test usa el helper `inRolledBackTransaction()` en vez de sobreescribir `tearDown`. `rector.php` queda neto sin cambios en la rama. Gates re-verificados: `php.quality` exit 0 ×2 idempotente · `php.unit` 574/574.
- 2026-06-07: Code review adversarial (Blind Hunter / Edge Case Hunter / Acceptance Auditor). Auditor: ACs 1–5 satisfechos sin desviaciones. 2 hallazgos aplicados como parches (commit `96682ba`): guard de blanco extendido de CONTAINS a EQ + ítems de IN vía helper `normalizedNotBlank`, y test de integración del escape de backslash literal. 12 hallazgos descartados como ruido (colisión de nombres de parámetro imposible por `count` monótono; asimetría LOWER por diseño del AC3; `InvalidArgumentException` prescrita por la Task 1; etc.). Gates: `FilterApplierTest` 16/16 · suite completa 577 OK (3 skips preexistentes) · stan/psalm sin errores · `php.quality` exit 0. Status → done.
