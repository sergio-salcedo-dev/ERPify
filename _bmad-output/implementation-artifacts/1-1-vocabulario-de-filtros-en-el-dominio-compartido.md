---
baseline_commit: e2a5e6fe55601aa339f13023620ea225dbd50535
---

# Story 1.1: Vocabulario de filtros en el dominio compartido

Status: done

_Ultimate context engine analysis completed — comprehensive developer guide created (2026-06-07)._

## Story

As a desarrollador del monorepo,
I want un vocabulario tipado e inmutable de filtros (`Filter`, `FilterOperator`, `Filters`) transportado por `SearchCriteria`,
so that cualquier búsqueda exprese filtrado con tipado estático extremo a extremo sin acoplarse a ningún framework.

## Acceptance Criteria

1. **Given** el namespace `Erpify\Shared\Domain\Search`
   **When** se implementan `Filter` (VO `final readonly`), `Filters` (colección inmutable) y `FilterOperator` (enum backed: `Eq = 'eq'`, `In = 'in'`, `Contains = 'contains'`)
   **Then** ninguna de las tres clases tiene dependencias externas (ni framework, ni ORM, ni `lambdish/phunctional` — NFR1)
   **And** se construyen vía named constructors y `Filter` transporta el nombre PÚBLICO del campo (nunca paths DQL).

2. **Given** la clase `SearchCriteria` existente
   **When** gana el parámetro `filters` como named arg con default `Filters` vacío
   **Then** todos los call-sites existentes compilan sin cambios (retrocompatibilidad total)
   **And** ningún comportamiento existente cambia (Bank intacto, Behat existente en verde).

3. **Given** las clases nuevas
   **When** se ejecutan los tests unitarios puros (`FilterTest`, `FiltersTest`, `FilterOperatorTest`) sin contenedor ni BD
   **Then** cubren construcción, inmutabilidad, colección vacía y los valores wire del enum
   **And** existen `FilterMother` y `FiltersMother` como clases nombradas bajo el subnamespace `Mother/` (primer precedente del repo — jamás clases anónimas readonly, gotcha PHPMD).

4. **Given** los gates de calidad
   **When** se cierra la historia
   **Then** `make php.stan` y `make php.psalm` pasan sobre cada archivo tocado.

## Tasks / Subtasks

- [x] Task 0: Preparar la rama de fase 0 (si no existe ya)
  - [x] `make worktree.create BRANCH=feat/shared-search-filters` desde `main` actualizado (las historias 1.1–1.3 — fase 0 — comparten esta rama/PR; si la rama ya existe de una historia anterior, reutilizarla)
  - [x] `make app.dev` dentro del worktree para levantar su stack aislado
- [x] Task 1: Crear `FilterOperator` enum (AC: 1)
  - [x] `api/src/Shared/Domain/Search/FilterOperator.php` — enum backed string con casos exactos `Eq = 'eq'`, `In = 'in'`, `Contains = 'contains'` (D2: el backing string ES el contrato wire)
  - [x] `api/tests/Unit/Shared/Domain/Search/FilterOperatorTest.php` — pin de los 3 valores wire exactos (un cambio de backing string rompe el contrato HTTP futuro)
- [x] Task 2: Crear `Filter` VO (AC: 1, 3)
  - [x] `api/src/Shared/Domain/Search/Filter.php` — `final readonly`, constructor privado + named constructors por operador (`eq()`, `in()`, `contains()`), propiedades `field` (nombre público), `operator`, `value` (`string|list<string>`)
  - [x] `api/tests/Unit/Shared/Domain/Search/FilterTest.php` — construcción por cada named constructor, inmutabilidad (readonly), shape del value por operador
  - [x] `api/tests/Unit/Shared/Domain/Search/Mother/FilterMother.php` — clase nombrada (namespace `Erpify\Tests\Unit\Shared\Domain\Search\Mother`)
- [x] Task 3: Crear `Filters` colección inmutable (AC: 1, 3)
  - [x] `api/src/Shared/Domain/Search/Filters.php` — `final readonly`, iterable, con caso vacío de primera clase (`isEmpty()`); solo interfaces SPL nativas (`IteratorAggregate`, `Countable`) — cero deps externas
  - [x] `api/tests/Unit/Shared/Domain/Search/FiltersTest.php` — construcción, colección vacía, iteración, count
  - [x] `api/tests/Unit/Shared/Domain/Search/Mother/FiltersMother.php`
- [x] Task 4: `SearchCriteria` transporta `filters` (AC: 2)
  - [x] Añadir `public Filters $filters = new Filters()` como ÚLTIMO parámetro promovido del constructor (named arg, default vacío)
  - [x] Verificar que los 2 call-sites existentes (`SearchQuery::toCriteria()`, `BankSearchQuery::toCriteria()` → `BankSearchCriteria::__construct` → `parent::__construct` posicional) compilan sin tocarlos
- [x] Task 5: Gates de calidad y regresión (AC: 2, 4)
  - [x] `make php.stan` y `make php.psalm` sobre cada archivo tocado (AMBOS — discrepancias conocidas entre ellos)
  - [x] `make php.unit c='--filter "FilterTest|FiltersTest|FilterOperatorTest"'` en verde, y suite unit completa en verde
  - [x] `make php.behat.install` (si no está instalado) + `make php.behat` — los 29 escenarios existentes de `search.feature` siguen en verde (Bank intacto)
  - [x] `make php.quality` completo al final (PHPMD sin baseline)
  - [x] Commit convencional, p. ej. `feat(api): add shared search filter vocabulary to domain` (verificar rama con `git branch --show-current` ANTES de committear; staging explícito de solo los ficheros de la historia)

### Review Findings

- [x] [Review][Defer] Valores degenerados aceptados por los named constructors — `Filter::in('campo', [])` y `Filter::contains('campo', '')` construyen sin guarda; consecuencia real en el applier de la story 1.3 (`IN ()` inválido/resultset vacío silencioso; `LIKE '%%'` no-op). Decisión (review 2026-06-07): los VOs quedan intactos, fiel al diseño pineado «sin validación en VOs»; el hueco se cierra con pin explícito en las stories consumidoras — el applier de 1.3 trata/rechaza in-vacío y la validación de shape de 1.4 rechaza listas vacías y strings vacíos (`#[Assert\Count(min:1)]`, `NotBlank`). [api/src/Shared/Domain/Search/Filter.php:34-43] — deferred, diseño pineado: validación fuera de los VOs (shape → 1.4, semántica → applier 1.3)
- [x] [Review][Defer] `Filters::fromList()` puede lanzar `Error` en runtime con arrays de claves string (el spread las convierte en named args desconocidos) — endurecerlo con `array_values()` chocaría con Psalm `RedundantFunctionCall` mientras el contrato sea `list<Filter>`; lo natural es que la story 1.4 garantice list-ness en el mapping antes de llamar a `fromList()`. [api/src/Shared/Domain/Search/Filters.php:36-41] — deferred, anotar en story 1.4
- [x] [Review][Defer] El no-dedupe por campo (diseño pineado) tiene una consecuencia no obvia en el merge legacy+genéricos de la story 1.5: `names` legacy + `Filter::in('name', …)` genérico componen dos predicados AND sobre el mismo campo y pueden vaciar resultados de forma inesperada — la capa de merge debe componer conscientemente, no concatenar a ciegas. [api/src/Shared/Domain/Search/Filters.php:13-17] — deferred, anotar en story 1.5

## Dev Notes

### Contexto y alcance (leer antes de tocar nada)

Primera historia de la **fase 0** del mecanismo compartido de filtros (research opción C: convergencia por reimplementación propia inspirada en php-criteria, sin requerir ningún paquete `codelytv/*`). La fase 0 es **estrictamente sin cambio de contrato HTTP** — nada de lo creado aquí es alcanzable desde HTTP todavía. Esta historia entrega SOLO el vocabulario de dominio; el resto de la fase 0 llega en 1.2 (marker + excepciones) y 1.3 (applier + field map).

**Fuera del alcance de esta historia (NO hacer):**

- ❌ NO crear `InvalidSearchCriteria`, `UnknownSearchField` ni `UnsupportedSearchOperator` (story 1.2).
- ❌ NO crear `FilterApplier`, `SearchFieldMap`, `FieldMapping`, `FieldNormalizer` (story 1.3).
- ❌ NO tocar `SearchQuery`, `BankSearchQuery`, `FilterQuery` ni nada de `Application/Http` (story 1.4, fase 1).
- ❌ NO tocar `BankSearchCriteria`, `AbstractDoctrineSearchRepository` ni `DoctrineBankRepository` (fase 1).
- ❌ NO añadir validación de negocio a los VOs: las capas de validación están pineadas (shape → mapping `#[MapQueryString]`; semántica → applier). Los VOs son portadores tipados; las named constructors por operador ya hacen estructuralmente imposible el shape incorrecto.
- ❌ NO añadir operadores más allá de `eq`/`in`/`contains` (YAGNI; el enum es el punto de extensión documentado, se amplía solo con consumidor real).
- ❌ NO borrar ni committear `php-criteria-main/` (está gitignored, línea 93; es material de estudio y su retirada es la story 1.6). PROHIBIDO copiar código literal de ahí (sin licencia declarada = todos los derechos reservados); solo ideas.
- ❌ Cero dependencias nuevas en `api/composer.json` (la promoción phpdoc-parser/type-resolver es de la story 1.4).
- ❌ NO transportar `Filters`/`SearchCriteria` en payloads de Messenger ni topics de Mercure (read-path síncrono).

### Estado actual del código que se modifica

`api/src/Shared/Domain/Search/SearchCriteria.php` (única modificación de esta historia) — hoy:

```php
readonly class SearchCriteria
{
    final public const int MAX_LIMIT = 1_000;

    /** @param list<string>|null $ids */
    public function __construct(
        public ?string $cursor = null,
        public int $page = 1,
        public int $limit = self::MAX_LIMIT,
        public PaginationMode $paginationMode = PaginationMode::LIGHT,
        public ?array $ids = null,
    ) {
    }
}
```

**Call-sites de construcción verificados (los únicos 2 en src/tests):**

- `api/src/Shared/Application/Http/Search/SearchQuery.php:49` — `new SearchCriteria(...)` con named args y SIN `filters` → compila con el default.
- `api/src/Backoffice/Bank/Application/Http/BankSearchQuery.php:37` — `new BankSearchCriteria(...)`; `BankSearchCriteria::__construct` llama `parent::__construct($cursor, $page, $limit, $paginationMode, $ids)` posicional con 5 args → el 6º parámetro nuevo toma su default. **Añadir `filters` al FINAL del constructor garantiza retrocompatibilidad total**; ninguno de los dos ficheros se toca.

El resto del namespace `Shared/Domain/Search/` (no se toca, pero conviene conocerlo): `PaginationMode` (enum), `SearchCursor`, `PaginatedResult<T>` (interface `IteratorAggregate` — precedente de SPL nativo en Domain).

### Decisiones arquitectónicas vinculantes (no inventar variantes)

FQCNs exactos pineados por el Architecture Decision Document:

| Pieza | FQCN |
|---|---|
| VO filtro | `Erpify\Shared\Domain\Search\Filter` |
| Colección | `Erpify\Shared\Domain\Search\Filters` |
| Enum operador | `Erpify\Shared\Domain\Search\FilterOperator` |

- **D2**: casos del enum EXACTOS `Eq = 'eq'`, `In = 'in'`, `Contains = 'contains'` (PascalCase, backing minúsculo). ⚠️ Varianza consciente respecto a `PaginationMode` (que usa `DETAILED`/`LIGHT` en mayúsculas): la arquitectura pinea PascalCase para `FilterOperator` — seguirla, NO "corregir" a mayúsculas ni abrir debate.
- `Filter` transporta el **nombre PÚBLICO del campo** (camelCase de la propiedad serializada del recurso, p. ej. `name`, `createdAt`) — NUNCA paths DQL (`b.nameNormalized`). La traducción a paths es monopolio de Infrastructure (story 1.3).
- `value` polimórfico `string|list<string>` (D5): `eq`/`contains` → `string`; `in` → `list<string>`.
- VOs `final readonly`, named constructors, **cero imports** (NFR1: ni framework, ni ORM, ni `lambdish/phunctional`; las interfaces SPL nativas como `IteratorAggregate`/`Countable` NO son dependencias externas — precedente: `PaginatedResult`).
- Varios filtros sobre el mismo campo componen con **AND** — la colección los conserva todos, sin dedupe por campo.

### Diseño recomendado (guía, las restricciones de arriba mandan)

```php
// FilterOperator.php
enum FilterOperator: string
{
    case Eq = 'eq';
    case In = 'in';
    case Contains = 'contains';
}

// Filter.php — constructor privado; el shape del value lo impone la named constructor
final readonly class Filter
{
    /** @param string|list<string> $value */
    private function __construct(
        public string $field,
        public FilterOperator $operator,
        public string|array $value,
    ) {
    }

    public static function eq(string $field, string $value): self { /* … */ }

    /** @param list<string> $values */
    public static function in(string $field, array $values): self { /* … */ }

    public static function contains(string $field, string $value): self { /* … */ }
}

// Filters.php — constructor variádico público permite `new Filters()` como default
/** @implements IteratorAggregate<int, Filter> */
final readonly class Filters implements IteratorAggregate, Countable
{
    /** @var list<Filter> */
    private array $items;

    public function __construct(Filter ...$filters) { $this->items = $filters; } // variádico ya es list — no envolver en array_values (Psalm: RedundantFunctionCall)

    public static function none(): self { /* new self() */ }

    /** @param list<Filter> $filters */
    public static function fromList(array $filters): self { /* new self(...$filters) */ }

    public function isEmpty(): bool { /* … */ }

    /** @return list<Filter> */
    public function all(): array { /* … */ }
    // getIterator(): Traversable + count(): int
}
```

- **Default vacío en `SearchCriteria`**: `public Filters $filters = new Filters()` como último parámetro promovido — `new` en defaults de parámetros promovidos es el patrón canónico del RFC new-in-initializers (PHP 8.1+), soportado por PHPStan/Psalm. Requiere que `Filters` tenga constructor público invocable sin args (de ahí el variádico); las named constructors (`none()`, `fromList()`) conviven con él.
- Consumidores futuros de esta API (para no quedarse corto ni sobre-diseñar): story 1.3 itera `Filters` y necesita `isEmpty()` (Filters vacío = no-op silencioso del applier); story 1.4 construye `Filters` desde `list<FilterQuery>` (de ahí `fromList()`); story 1.5 compone legacy + genéricos con AND (merge a nivel de array antes de construir — no anticipar un método `merge()`).
- PHP 8.5 es bleeding-edge: usar idioms 8.3-forward (readonly, enums, promoted params, named args, first-class callables); **no inventar sintaxis 8.5 de memoria**.

### Testing

- Convenciones verificadas en el repo (espejo: `api/tests/Unit/Shared/Domain/ValueObject/NormalizedTextTest.php`):
  - `declare(strict_types=1);` + namespace espejo `Erpify\Tests\Unit\Shared\Domain\Search`.
  - Docblock `/** @internal */`, `#[CoversClass(X::class)]`, clase `final`, métodos `testXxx(): void` con nombre por comportamiento.
  - `#[DataProvider('provideXxxCases')]` con providers `public static function … : iterable` y claves `yield 'caso' => […]`.
  - AAA, sin contenedor ni BD (unit puro de Domain).
- Cobertura mínima exigida por AC 3: construcción (cada named constructor), inmutabilidad, colección vacía (`none()`/`isEmpty()`), valores wire del enum (`'eq'`, `'in'`, `'contains'` literales — pin del contrato).
- **Mothers**: `FilterMother` y `FiltersMother` como clases NOMBRADAS bajo `api/tests/Unit/Shared/Domain/Search/Mother/` — primer precedente del repo (idea absorbida de `criteria-test-mother`, reimplementada). Precedente de clases de soporte en tests: `tests/Unit/Shared/Domain/Enum/Abstraction/Fixtures/`. JAMÁS clases anónimas readonly: PDepend no parsea `new readonly class` y aborta `php.md` (Error 3), y Rector readonly-fica fakes anónimos.
- Comandos: `make php.unit c='--filter FilterTest'` (desde repo root, nunca desde `api/`).

### Gotchas del repo que muerden en esta historia

- **Doble gate obligatorio**: PHPStan y Psalm discrepan en uniones tipo `string|list<string>` (precedente documentado del repo con `assertCount`). Pasar AMBOS `make php.stan` y `make php.psalm` por archivo; no perseguir a uno hasta romper al otro.
- **PHPMD sin baseline**: `php.stan`+`php.psalm` en verde NO bastan — solo `make php.quality` completo detecta PHPMD/cs-fixer (BooleanArgumentFlag y TooManyPublicMethods son los que más saltan). El paso phpmd puede fallar transitoriamente con OOM (Error 137) — reintentar antes de investigar.
- **`php` container restart-loop**: si `docker compose exec php` falla (zend_mm_heap corrupted / exit 139), ejecutar los checks contra el worker sano: `make php.stan PHP_SERVICE=messenger_worker`. No es un problema del código.
- **Behat aislado**: vive en `api/tools/behat/` con vendor propio; `make app.dev` NO lo instala — `make php.behat.install` primero. Jamás `composer require behat/*` en `api/composer.json`.
- **`api/config/reference.php`** se auto-regenera — nunca committearlo si aparece en el diff.
- Línea máx. 120 caracteres (`api/CLAUDE.md`); comentarios solo de "why" no obvio — por defecto, ninguno.
- Pre-commit valida Conventional Commits; si un hook falla: arreglar, re-stagear y crear commit NUEVO (nunca `--amend`, nunca `--no-verify`).

### Seguridad (checklist CLAUDE.md aplicado a esta historia)

Historia de VOs puros de dominio sin superficie HTTP, SQL, serialización ni secretos: las clases de inyección/authz/mass-assignment **no aplican**. Único punto con dimensión de seguridad: `Filter` transporta el nombre público del campo sin validarlo — es el diseño pineado (la allow-list `SearchFieldMap` de la story 1.3 es quien rechaza campos no autorizados; la validación de shape es de la 1.4). Declarar este "no aplica" razonado en la descripción del PR de fase 0.

### Project Structure Notes

Delta exacto de esta historia (subconjunto del árbol de fase 0 del Architecture Decision Document):

```text
api/src/Shared/Domain/Search/
├── Filter.php                                  [N]
├── Filters.php                                 [N]
├── FilterOperator.php                          [N]
└── SearchCriteria.php                          [M] +filters (último param, default vacío)

api/tests/Unit/Shared/Domain/Search/            [N] directorio nuevo (espejo del src)
├── FilterTest.php                              [N]
├── FiltersTest.php                             [N]
├── FilterOperatorTest.php                      [N]
└── Mother/
    ├── FilterMother.php                        [N]
    └── FiltersMother.php                       [N]
```

- Autoload PSR-4 ya cubre ambos lados (`Erpify\` → `api/src/`, `Erpify\Tests\` → `api/tests/`); sin cambios de config, Compose, Make ni `.env`.
- Sin migraciones de BD, sin cambios de esquema (NFR5).
- Las obligaciones documentales de la fase (api-error-contract, architecture-api, source-tree) pertenecen a las stories 1.2/1.4/1.6 — esta historia no toca `docs/` (el directorio nuevo de src que dispara doc es `Doctrine/Search/`, story 1.3).

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 1.1] — historia y ACs canónicos.
- [Source: _bmad-output/planning-artifacts/architecture.md#Naming Patterns] — FQCNs pineados, D2, convención de campos públicos.
- [Source: _bmad-output/planning-artifacts/architecture.md#Process Patterns] — inmutabilidad, named constructors, cero deps.
- [Source: _bmad-output/planning-artifacts/architecture.md#Complete Project Directory Structure] — árbol delta fase 0.
- [Source: _bmad-output/planning-artifacts/research/technical-php-criteria-vs-searchcriteria-erpify-research-2026-06-06.md#Architectural Patterns and Design] — opción C, ideas absorbidas de php-criteria (VOs inmutables, enum, mothers), prohibición de copiar código.
- [Source: docs/project-context.md#Language-Specific Rules] — PHP 8.5, strict_types, readonly, enums sobre constantes, Domain puro.
- [Source: api/src/Shared/Domain/Search/SearchCriteria.php] — estado actual del fichero modificado.
- [Source: api/tests/Unit/Shared/Domain/ValueObject/NormalizedTextTest.php] — convención de tests unit de Domain.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Claude Opus 4.8, 1M context) — Claude Code

### Debug Log References

- RED inicial: 13 tests / 13 errores (clases inexistentes) — fallo confirmado antes de implementar.
- `make app.dev` del worktree falló transitoriamente en `up --wait` (health-check del contenedor `php` durante el arranque); el contenedor se recuperó solo y `make docker.up` completó el stack. No era el segfault conocido — flake de arranque puntual.
- PHPStan exigía el vendor de Behat como bootstrap (`tools/behat/vendor/autoload.php`) → `make php.behat.install` antes de `php.stan`.

### Completion Notes List

- Vocabulario completo entregado: `FilterOperator` (enum `Eq='eq'`, `In='in'`, `Contains='contains'`), `Filter` (named constructors `eq()`/`in()`/`contains()`, constructor privado, value `string|list<string>`), `Filters` (inmutable, `Countable` + `IteratorAggregate`, `none()`/`fromList()`/`isEmpty()`/`all()`). Cero dependencias externas (solo SPL nativo, precedente `PaginatedResult`).
- `SearchCriteria` gana `public Filters $filters = new Filters()` como último parámetro promovido — los 2 call-sites existentes compilan sin tocarse (verificado: suite unit 549 OK + Behat 71/71, incluidos los 29 de `search.feature`).
- Desviaciones menores respecto al plan, todas forzadas por los gates:
  - `Filters::__construct` envuelve el variádico en `array_values()` — Psalm tipa variádicos como `array<array-key, T>` (no `list<T>`); PHPStan no lo objeta (discrepancia dual anticipada en Dev Notes).
  - `#[Override]` en `count()`/`getIterator()` (Psalm `MissingOverrideAttribute`; convención ya usada en src).
  - `FiltersMother::empty()` eliminado (Psalm `PossiblyUnusedMethod`; YAGNI — se reañade cuando un test lo consuma).
  - Se añadió `SearchCriteriaTest` (2 tests: default vacío + named arg) — pin directo del AC2 y resolución orgánica del `PossiblyUnusedProperty` de Psalm sin suppression.
  - `FilterOperatorTest` reescrito con DataProvider: PHPStan constant-folda literales de enum (incluso vía `array_column` y `foreach`) y marcaba `method.alreadyNarrowedType`; el pin de exhaustividad deriva la expectativa del propio provider.
  - El fixer del repo renombró el provider a `provideBackingValuesAreTheLowercaseWireTokensCases` (convención `provide<TestName>Cases`).
- Gates finales: `php.stan` ✅ · `php.psalm` ✅ · `php.unit` 549/549 (3 skips preexistentes) ✅ · `php.behat` 71 escenarios / 580 steps ✅ · `php.quality` completo exit 0 ✅.
- Seguridad: historia de VOs puros sin superficie HTTP/SQL/serialización — clases del checklist no aplican (declarar en el PR de fase 0); el campo viaja sin validar por diseño pineado (allow-list en story 1.3).
- Commit `18e1a2f` en `feat/shared-search-filters-aj0w` (worktree `.claude/worktrees/shared-search-filters-aj0w`). Las stories 1.2 y 1.3 continúan en esta rama (PR único de fase 0).

### File List

- `api/src/Shared/Domain/Search/FilterOperator.php` (nuevo)
- `api/src/Shared/Domain/Search/Filter.php` (nuevo)
- `api/src/Shared/Domain/Search/Filters.php` (nuevo)
- `api/src/Shared/Domain/Search/SearchCriteria.php` (modificado — +1 línea)
- `api/tests/Unit/Shared/Domain/Search/FilterOperatorTest.php` (nuevo)
- `api/tests/Unit/Shared/Domain/Search/FilterTest.php` (nuevo)
- `api/tests/Unit/Shared/Domain/Search/FiltersTest.php` (nuevo)
- `api/tests/Unit/Shared/Domain/Search/SearchCriteriaTest.php` (nuevo)
- `api/tests/Unit/Shared/Domain/Search/Mother/FilterMother.php` (nuevo)
- `api/tests/Unit/Shared/Domain/Search/Mother/FiltersMother.php` (nuevo)

## Change Log

- 2026-06-07: Story 1.1 implementada y verificada (commit `18e1a2f`, rama `feat/shared-search-filters-aj0w`). 10 ficheros, +385 líneas. 17 tests unit nuevos. Todos los gates en verde. Status → review.
