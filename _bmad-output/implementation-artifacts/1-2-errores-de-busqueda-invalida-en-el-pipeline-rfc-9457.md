---
baseline_commit: 18e1a2f1b75225731ec4252efd211ace65b12f77
---

# Story 1.2: Errores de búsqueda inválida en el pipeline RFC 9457

Status: done

_Ultimate context engine analysis completed — comprehensive developer guide created (2026-06-07)._

## Story

As a consumidor de la API,
I want que cualquier búsqueda inválida produzca un 400 Problem Details (nunca un 500),
so that pueda corregir mi petición con información precisa y el contrato de errores se mantenga uniforme.

## Acceptance Criteria

1. **Given** `Erpify\Shared\Domain\Exception`
   **When** se crea el marker `InvalidSearchCriteria` (junto a los 7 markers existentes)
   **Then** `ProblemDetailsFactory` lo mapea a status 400 en `MARKER_STATUS_MAP` y a default type `invalid-search-criteria` en `MARKER_DEFAULT_TYPE_MAP`
   **And** el marker queda bajo el guard de `TaxonomyArchitectureTest`.

2. **Given** `Erpify\Shared\Domain\Search\Exception`
   **When** se crean las excepciones concretas `UnknownSearchField` y `UnsupportedSearchOperator` (sin sufijo `Exception` — precedente fijado por la arquitectura)
   **Then** implementan el marker `InvalidSearchCriteria`
   **And** exponen types kebab-case `unknown-search-field` y `unsupported-search-operator`.

3. **Given** el contract test `MarkerStatusMapContractTest`
   **When** se actualiza con el marker nuevo
   **Then** pasa en verde
   **And** `make php.lint.error-contract` pasa en verde.

4. **Given** `docs/api-error-contract.md`
   **When** se añade la fila del marker `InvalidSearchCriteria` (obligación NFR26)
   **Then** documento y código quedan consistentes en el mismo PR.

## Tasks / Subtasks

- [x] Task 0: Continuar en la rama de fase 0 (NO crear worktree nuevo)
  - [x] `cd .claude/worktrees/shared-search-filters-aj0w` — la fase 0 (historias 1.1–1.3) comparte rama/PR; la 1.1 dejó el commit `18e1a2f` en `feat/shared-search-filters-aj0w`. Verificar con `git branch --show-current` y `git status` (working tree limpio)
  - [x] Levantar el stack del worktree si no está arriba: `make docker.up` (o `make app.dev`); fallback ante flake transitorio de health-check: reintentar `make docker.up`
- [x] Task 1: Crear el marker `InvalidSearchCriteria` (AC: 1)
  - [x] `api/src/Shared/Domain/Search/Exception/` aún no existe; el marker va en `api/src/Shared/Domain/Exception/InvalidSearchCriteria.php` — interface VACÍA, espejo exacto de `InvalidInput.php` (namespace `Erpify\Shared\Domain\Exception`, cero imports)
  - [x] Verificar que `TaxonomyArchitectureTest` lo recoge automáticamente (su provider hace glob de `src/Shared/Domain/Exception/*.php` — NO requiere edición) y pasa en verde
- [x] Task 2: Mapear el marker en `ProblemDetailsFactory` (AC: 1)
  - [x] Añadir `InvalidSearchCriteria::class => Response::HTTP_BAD_REQUEST` como ÚLTIMA entrada de `MARKER_STATUS_MAP` (tras `RateLimited`) y `InvalidSearchCriteria::class => 'invalid-search-criteria'` como última de `MARKER_DEFAULT_TYPE_MAP` + el `use` correspondiente
  - [x] NO tocar `HTTP_STATUS_TYPE_MAP` (es el bridge de excepciones Symfony: un 400 del framework sigue siendo `invalid-input` genérico; `testHttpStatusTypeMapHasExactlyTheCanonicalSevenEntries` lo pinea con exactamente 7 entradas y debe seguir en verde sin cambios)
- [x] Task 3: Crear las excepciones concretas (AC: 2)
  - [x] `api/src/Shared/Domain/Search/Exception/UnknownSearchField.php` — `final`, extiende `DomainException`, implementa `InvalidSearchCriteria`; `public const string TYPE = 'unknown-search-field'`; named constructor `named(string $field): self` con title estático `'Unknown search field.'` y context `['field' => $field]`
  - [x] `api/src/Shared/Domain/Search/Exception/UnsupportedSearchOperator.php` — ídem con `TYPE = 'unsupported-search-operator'`; named constructor `forField(string $field, FilterOperator $operator): self` con title `'Unsupported search operator.'` y context `['field' => $field, 'operator' => $operator->value]` (⚠️ `->value`, nunca la instancia del enum — ver Dev Notes)
- [x] Task 4: Tests unit de las excepciones concretas (AC: 2)
  - [x] `api/tests/Unit/Shared/Domain/Search/Exception/UnknownSearchFieldTest.php` y `UnsupportedSearchOperatorTest.php` — espejo de las convenciones de `RateLimitExceededTest` (AAA, `#[CoversClass]`, `/** @internal */`, `final`, sin contenedor/BD)
  - [x] Cobertura mínima: pin literal del type kebab-case, `instanceof InvalidSearchCriteria` + `instanceof DomainException`, title, y context con los valores escalares esperados (los tests son los ÚNICOS call-sites de las named constructors hasta la 1.3 — sin ellos Psalm dispara `PossiblyUnusedMethod`)
- [x] Task 5: Actualizar `MarkerStatusMapContractTest` (AC: 3)
  - [x] `CANONICAL_MARKERS` gana `InvalidSearchCriteria::class`; `assertCount(7, …)` pasa a 8; renombrar `testMarkerStatusMapContainsExactlyTheCanonicalSeven` → `…CanonicalEight`; actualizar los docblocks que dicen "seven canonical markers"
  - [x] Añadir la rama `InvalidSearchCriteria::class` al `match` de `exceptionImplementingMarker` (clase anónima `new class ('', 'x') extends DomainException implements InvalidSearchCriteria {}` — extiende clase no-readonly, así que el gotcha PHPMD/Rector de anónimas readonly no aplica)
  - [x] El data provider es reflection-driven sobre las constantes del factory: la fila nueva (400 + `invalid-search-criteria`) aparece sola al actualizar los maps — verificar en verde
- [x] Task 6: Adaptar el test espejo `testHttpStatusTypeMapValuesMirrorMarkerDefaultTypeMapValues` (AC: 3)
  - [x] ⚠️ TRAMPA CONOCIDA: ese test (en `ProblemDetailsFactoryTest`, ~línea 916) invierte `MARKER_STATUS_MAP` con `$derived[$status] = $markerType[$marker]` — con DOS markers en 400, la última declaración pisa y `derived[400]` pasaría a `invalid-search-criteria` ≠ `HTTP_STATUS_TYPE_MAP[400]` (`invalid-input`) → ROJO
  - [x] Fix pineado: construir `$derived` con first-wins (`if (!\array_key_exists($status, $derived))` o `??=`) + comentario de why ("el bridge usa el type del marker GENÉRICO de cada status; los markers específicos se declaran después en el map") — funciona porque el marker nuevo se declara el ÚLTIMO (Task 2)
  - [x] (Recomendado) Añadir en `ProblemDetailsFactoryTest` un pin de integración de las piezas nuevas: `fromThrowable(UnknownSearchField::named('x'))` → status 400 + type `unknown-search-field` (el `type()` explícito gana sobre el default del marker vía `resolveDomainType`)
- [x] Task 7: Documentación NFR26 (AC: 4)
  - [x] `docs/api-error-contract.md` — fila `InvalidSearchCriteria | 400 | invalid-search-criteria` en la tabla "Marker interface → HTTP status", tras `RateLimited` y antes de la fila "Plain `DomainException`"
  - [x] Refrescar las referencias de líneas del doc a `MARKER_STATUS_MAP`/`MARKER_DEFAULT_TYPE_MAP` (hoy "lines 111–119"/"121–129" — se desplazan al añadir entradas)
  - [x] (Higiene recomendada, mismo doc) La tabla "Listener layout" lista `SearchExceptionListener` (legacy, priority 32): ese listener YA NO EXISTE en el código (verificado 2026-06-07 — solo `ExceptionResponder` y `RateLimitListener`); retirar la fila stale y la frase que lo cita, o señalarlo explícitamente en el PR si se prefiere diferir
- [x] Task 8: Gates de calidad y regresión (AC: 1–4)
  - [x] `make php.stan` y `make php.psalm` sobre cada archivo tocado (AMBOS — discrepancias conocidas)
  - [x] `make php.unit` — suite completa en verde (la 1.1 dejó 549 OK como baseline)
  - [x] `make php.lint.error-contract` en verde (necesita el stack arriba; su check git-aware diffea `origin/main...HEAD` — el marker nuevo exige el cambio del doc en la misma rama, ya cubierto por Task 7)
  - [x] `make php.behat` — 71 escenarios / 29 de `search.feature` en verde (regresión trivial: nada lanza las excepciones nuevas todavía; `make php.behat.install` primero si el vendor de Behat falta — PHPStan también lo necesita como bootstrap)
  - [x] `make php.quality` completo (PHPMD sin baseline; reintentar ante OOM 137 transitorio)
  - [x] Commit convencional en la rama de fase 0, p. ej. `feat(api): map invalid search criteria to 400 in problem details pipeline` (staging explícito de solo los ficheros de la historia; jamás `api/config/reference.php`)

### Review Findings

_Code review adversarial (Blind Hunter / Edge Case Hunter / Acceptance Auditor) del diff `18e1a2f..f53e3b8` — 2026-06-07. ACs 1–4 verificados en PASS por el Acceptance Auditor; alcance y decisiones vinculantes respetados._

- [x] [Review][Patch] Alineación de columnas rota en la fila nueva de la tabla de markers — la columna "Default `type`" pad a 26 caracteres en separador y todas las filas, pero la fila `InvalidSearchCriteria` ocupa 27 (un espacio extra antes del pipe de cierre) [docs/api-error-contract.md:56] — aplicado 2026-06-07 (tabla re-padded a 27)
- [x] [Review][Patch] Referencia de líneas stale en hunk tocado: "(`firstMatchingMarker`, lines 352–364)" — la función vive hoy en `ProblemDetailsFactory.php:444-456` (ya estaba desplazada en el baseline: 442); las refs de los maps sí se refrescaron en este PR, esta quedó atrás [docs/api-error-contract.md:61] — aplicado 2026-06-07 (ref → 444–456)
- [x] [Review][Patch] Falta el pin de integración espejo para `UnsupportedSearchOperator` a través del factory — solo `UnknownSearchField` se verifica end-to-end (`testUnknownSearchFieldMapsTo400WithItsExplicitType`); la clave `operator` del context nunca se comprueba sobreviviendo whitelist/reserved-keys hasta `extensions` [api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php:975] — aplicado 2026-06-07 (`testUnsupportedSearchOperatorMapsTo400WithItsExplicitType`)
- [x] [Review][Defer] Precedencia de default-type sin guard para una hipotética excepción que implemente `InvalidInput` + `InvalidSearchCriteria` a la vez (ambos → 400): resuelve por orden de implements-clause — comportamiento documentado en el doc del contrato y pineado por `testMarkerOrderingFollowsImplementsClause`; ninguna concreta de producción implementa ambos markers hoy [api/src/Shared/Application/Problem/ProblemDetailsFactory.php:444] — deferred, pre-existing

## Dev Notes

### Contexto y alcance (leer antes de tocar nada)

Segunda historia de la **fase 0** del mecanismo compartido de filtros (research opción C). La fase 0 es **estrictamente sin cambio de contrato HTTP**: el marker y las excepciones creados aquí NO son lanzados por nadie todavía — el applier de la 1.3 será su primer (y único) call-site de producción. Esta historia entrega la rama de error completa del mecanismo: marker + excepciones concretas + mapeo en el factory + NFR26 (doc + contract tests + gate).

**Trabajo en curso — misma rama:** la 1.1 está hecha (commit `18e1a2f`) en el worktree `.claude/worktrees/shared-search-filters-aj0w`, rama `feat/shared-search-filters-aj0w`. Las historias 1.1–1.3 comparten ese PR único de fase 0. NO crear worktree ni rama nuevos.

**Fuera del alcance de esta historia (NO hacer):**

- ❌ NO crear `FilterApplier`, `SearchFieldMap`, `FieldMapping`, `FieldNormalizer` (story 1.3).
- ❌ NO lanzar las excepciones nuevas desde ningún sitio (no hay consumidor hasta la 1.3); no tocar repositorios ni `AbstractDoctrineSearchRepository`.
- ❌ NO tocar `SearchQuery`, `BankSearchQuery`, `FilterQuery` ni `Application/Http` (story 1.4, fase 1).
- ❌ NO tocar `HTTP_STATUS_TYPE_MAP` ni el bridge de excepciones Symfony (los 400 del framework siguen siendo `invalid-input`).
- ❌ NO añadir Behat nuevo: la superficie HTTP no cambia; los escenarios 400 de filtros llegan con la 1.4.
- ❌ Cero dependencias nuevas en `api/composer.json`.
- ❌ NO resucitar `SearchExceptionListener` ni añadir listeners: `ExceptionResponder` (priority 16) ya resuelve las excepciones nuevas sin wiring (ese es el punto del patrón marker).

### Estado actual del código que se modifica

**`api/src/Shared/Domain/Exception/`** — 9 ficheros hoy: los 7 markers (interfaces vacías: `NotFound`, `Conflict`, `Forbidden`, `Unauthenticated`, `InvariantViolation`, `InvalidInput`, `RateLimited`), la base `DomainException` (abstracta: constructor `(string $type, string $title, array $context = [], ?Throwable $previous = null)`, accessors `type()`/`title()`/`context()`) y la concreta `RateLimitExceeded` (precedente de concreta en Shared: `final`, consts `TYPE`/`TITLE`, context de escalares).

**`api/src/Shared/Application/Problem/ProblemDetailsFactory.php`** — los dos maps a tocar (líneas 111–129):

```php
private const array MARKER_STATUS_MAP = [
    NotFound::class => Response::HTTP_NOT_FOUND,
    // … Conflict 409, Forbidden 403, Unauthenticated 401, InvariantViolation 422,
    InvalidInput::class => Response::HTTP_BAD_REQUEST,
    RateLimited::class => Response::HTTP_TOO_MANY_REQUESTS,
];   // MARKER_DEFAULT_TYPE_MAP es el gemelo con los literales kebab-case
```

Son constantes **privadas** — los contract tests las leen por reflection, así que la fila de test nueva aparece sola al añadir las entradas. `resolveDomainType()` hace que el `type()` explícito de la excepción concreta gane sobre el default del marker: `UnknownSearchField` saldrá al wire como `unknown-search-field`, no como `invalid-search-criteria` (el default solo aplica si `type()` devuelve `''`). `firstMatchingMarker()` interseca `class_implements` con las claves del map — una concreta con UN marker no tiene ambigüedad de precedencia.

**Tests de contrato afectados:**

- `api/tests/Unit/Shared/Application/Problem/MarkerStatusMapContractTest.php` — const `CANONICAL_MARKERS` (lista estática de los 7), `assertCount(7, …)` en `testMarkerStatusMapContainsExactlyTheCanonicalSeven`, y el `match` de `exceptionImplementingMarker` cuyo default lanza `LogicException` si falta la rama del marker nuevo (diseñado para forzar esta actualización).
- `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` — `testHttpStatusTypeMapHasExactlyTheCanonicalSevenEntries` (pinea `HTTP_STATUS_TYPE_MAP` con exactamente 7 entradas — NO tocar el map y sigue verde) y `testHttpStatusTypeMapValuesMirrorMarkerDefaultTypeMapValues` (~línea 916 — la TRAMPA descrita en Task 6: inversión last-wins por status).
- `api/tests/Unit/Shared/Domain/Exception/TaxonomyArchitectureTest.php` — glob automático de `src/Shared/Domain/Exception/*.php`; prohíbe imports `Symfony\`/`Doctrine\`/`Psr\Http\`/Messenger en el marker. NO cubre `Shared/Domain/Search/Exception/` (las concretas) — no extender el guard (fuera de alcance).
- `api/tests/Unit/Shared/Application/Problem/ErrorContractGateTest.php` (= `make php.lint.error-contract`) — `testNewMarkerExceptionWithoutDocsUpdateIsRejected` diffea `merge-base(origin/main, HEAD)...HEAD`: fichero nuevo bajo `src/Shared/Domain/Exception/` sin cambio en `docs/api-error-contract.md` en la misma rama → ROJO. El cambio del doc de Task 7 lo satisface.
- `testMarkerOrderingFollowsImplementsClause` (en `DomainExceptionTest`) pinea el orden de `class_implements`, no el número de markers — no requiere cambios.

**`docs/api-error-contract.md`** — tabla "Marker interface → HTTP status" (líneas 47–56) termina hoy en `RateLimited | 429 | rate-limited` + la fila "Plain `DomainException` (no marker) | 500 | domain-error". La fila nueva va entre ambas. El doc es "navigation aid": los valores canónicos viven en las constantes (NFR25), pero la fila es obligatoria (NFR26).

**Verificado 2026-06-07 — el legacy `SearchExceptionListener` ya no existe.** `api/src/Shared/Infrastructure/Http/EventListener/` solo contiene `ExceptionResponder.php` (priority 16) y `RateLimitListener.php`. Las menciones en `architecture.md` ("verificar que el legacy priority 32 no intercepta") y en la tabla "Listener layout" del doc del contrato son STALE. Consecuencia para esta historia: ninguna verificación de intercepción necesaria. Consecuencia para la **story 1.4**: su AC de verificación del listener legacy queda trivialmente satisfecha/documentable — anotarlo al crearla.

### Decisiones arquitectónicas vinculantes (no inventar variantes)

| Pieza | FQCN |
|---|---|
| Marker (D3) | `Erpify\Shared\Domain\Exception\InvalidSearchCriteria` |
| Excepción concreta | `Erpify\Shared\Domain\Search\Exception\UnknownSearchField` |
| Excepción concreta | `Erpify\Shared\Domain\Search\Exception\UnsupportedSearchOperator` |

- **D3**: marker nuevo → 400, default type `invalid-search-criteria`. Coste NFR26 asumido conscientemente (se prefirió expresividad del type system a reutilizar `InvalidInput`): entrada en ambos maps + fila en el doc + contract test + gate, todo en el mismo PR.
- Convención de excepciones (precedente fijado): clase = el fallo, SIN sufijo `Exception`; `type` kebab-case del nombre: `unknown-search-field`, `unsupported-search-operator`.
- Capa de validación pineada (contexto, no tarea): estas excepciones cubren la validación SEMÁNTICA (campo fuera de allow-list, operador no permitido para el campo) y las lanzará el applier (1.3). La validación de SHAPE (operador inexistente, value incoherente, caps) irá por `#[MapQueryString]` → 400 `validation-failed` (1.4). Nada de validación en controller ni use case.
- El subdirectorio `Search/Exception/` bajo `Shared/Domain/` es NUEVO — espejo del patrón por-contexto (`Backoffice/Bank/Domain/Exception/`); el árbol delta de fase 0 de la arquitectura lo pinea exactamente ahí.

### Diseño recomendado (guía; las restricciones de arriba mandan)

```php
// InvalidSearchCriteria.php — espejo exacto de InvalidInput.php
namespace Erpify\Shared\Domain\Exception;

interface InvalidSearchCriteria
{
}

// UnknownSearchField.php
namespace Erpify\Shared\Domain\Search\Exception;

use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\InvalidSearchCriteria;

final class UnknownSearchField extends DomainException implements InvalidSearchCriteria
{
    public const string TYPE = 'unknown-search-field';

    public static function named(string $field): self
    {
        return new self(
            type: self::TYPE,
            title: 'Unknown search field.',
            context: ['field' => $field],
        );
    }
}

// UnsupportedSearchOperator.php — forField(string $field, FilterOperator $operator)
// → context: ['field' => $field, 'operator' => $operator->value]
```

- **Las named constructors son el contrato para la 1.3**: el applier las invocará tal cual (`UnknownSearchField::named($filter->field)`, `UnsupportedSearchOperator::forField($filter->field, $filter->operator)`) sin tocar estos ficheros. Firmas estables.
- ⚠️ **Context solo con escalares**: el whitelist del factory (`isWhitelistedValue`) acepta null/escalares/arrays/`JsonSerializable`. Una instancia de `FilterOperator` (enum, NO JsonSerializable) sería sustituida por el sentinel `'[unserializable]'` + un log notice. Pasar SIEMPRE `$operator->value` (string wire `eq`/`in`/`contains`).
- Importar `FilterOperator` (de `Erpify\Shared\Domain\Search`, creado en la 1.1) en `UnsupportedSearchOperator` es dominio-puro — permitido. El marker, en cambio, debe quedarse con CERO imports (guard de Taxonomy).
- Title estático + dato dinámico en context — patrón del walk-through canónico (`BankNotFound::withId`) y de `RateLimitExceeded`. No interpolar el campo/operador en el title (el `field` es input del cliente; en context viaja como extension limpia, con denylist y reserved-keys aplicándose aguas abajo).
- Const `TITLE` opcional (RateLimitExceeded la tiene; BankNotFound no) — si Psalm marca `PossiblyUnusedConstant` por no consumirla un test, omitirla y dejar el literal en la named constructor.

### Testing

- Convención espejo: `api/tests/Unit/Shared/Domain/Search/Exception/` (namespace `Erpify\Tests\Unit\Shared\Domain\Search\Exception`). Referencia directa: `api/tests/Unit/Shared/Domain/Exception/RateLimitExceededTest.php` — `declare(strict_types=1)`, `/** @internal */`, `#[CoversClass(X::class)]`, clase `final`, métodos `testXxx(): void` por comportamiento, AAA, sin contenedor ni BD.
- Cobertura mínima por excepción: pin del literal kebab-case (`assertSame('unknown-search-field', $e->type())` — pin del contrato wire), `instanceof InvalidSearchCriteria` e `instanceof DomainException`, title, context exacto (`['field' => 'name']` / `['field' => 'id', 'operator' => 'contains']`).
- **Los tests son los únicos call-sites de las named constructors hasta la 1.3** — lección de la 1.1: Psalm analiza también tests y resuelve `PossiblyUnusedMethod` orgánicamente cuando un test consume el método; sin test que las invoque, Psalm falla.
- `MarkerStatusMapContractTest`: el provider reflection-driven añade la fila nueva solo; la actualización manual es `CANONICAL_MARKERS`, el count 7→8, el rename del test, los docblocks "seven" y la rama del `match` (su `default` lanza `LogicException` con mensaje explícito si la olvidas — el test te guía).
- Pin de integración recomendado en `ProblemDetailsFactoryTest`: `UnknownSearchField::named('x')` → `status === 400` + `type === 'unknown-search-field'` (demuestra que el `type()` explícito gana sobre el default del marker — comportamiento que la 1.4 expondrá al wire).
- Comandos: `make php.unit c='--filter "UnknownSearchFieldTest|UnsupportedSearchOperatorTest|MarkerStatusMapContractTest|ProblemDetailsFactoryTest|TaxonomyArchitectureTest"'`; gate del contrato: `make php.lint.error-contract` (necesita stack arriba).

### Gotchas del repo que muerden en esta historia

- **`ProblemDetailsFactory.php` contiene un byte NUL literal** en un docblock (zona `sanitiseExceptionClass`): `grep` lo trata como binario y devuelve vacío silenciosamente; `file` dice "data". Usar `grep -a` para buscar en ese fichero. Editarlo con Edit/str-replace funciona con normalidad.
- **Doble gate obligatorio**: PHPStan y Psalm discrepan (precedentes del repo); pasar AMBOS por archivo. No perseguir a uno hasta romper al otro.
- **PHPMD sin baseline**: solo `make php.quality` completo lo ejecuta; clases anónimas en el `match` del contract test son seguras (extienden `DomainException`, no-readonly — Rector no puede readonly-ficarlas), pero JAMÁS crear fakes `new readonly class` nuevos.
- **`php` container restart-loop**: si `docker compose exec php` falla (exit 139), `make php.stan PHP_SERVICE=messenger_worker`.
- **Behat aislado**: `make php.behat.install` antes de `php.stan`/`php.behat` si `api/tools/behat/vendor/` falta (PHPStan lo usa de bootstrap — lección 1.1).
- **El fixer renombra data providers** a `provide<TestName>Cases` — no luchar contra ello (lección 1.1). PHPStan constant-folda literales de enum en asserts (`method.alreadyNarrowedType`) — si muerde, derivar la expectativa de un provider como hizo `FilterOperatorTest`.
- `make php.lint.error-contract` y `php.lint.doctrine` necesitan el stack del worktree arriba (corren PHPUnit en contenedor).
- Línea máx. 120 caracteres; comentarios solo de why no obvio; jamás `--amend`/`--no-verify`; commit NUEVO si un hook falla.
- `api/config/reference.php` se auto-regenera — nunca committearlo.

### Seguridad (checklist CLAUDE.md aplicado a esta historia)

Historia de taxonomy de errores sin superficie HTTP nueva (nada lanza las excepciones todavía): inyección/authn/authz/mass-assignment **no aplican** — declararlo en el PR de fase 0. Puntos con dimensión de seguridad real:

- **Output encoding / no-leak**: titles estáticos; el dato dinámico (`field`, `operator`) viaja en `context` → extensions, pasando por reserved-keys + redaction denylist + whitelist del factory. No incluir paths DQL, SQL ni clases internas en context.
- **RFC 9457 sin bypass**: cero `JsonResponse` manual (el gate lo vigila); el pipeline existente resuelve el marker sin wiring.
- El `type` es un identificador opaco estable — no codificar en él información del esquema interno (los dos literales pineados ya cumplen).

### Project Structure Notes

Delta exacto de esta historia (subconjunto del árbol de fase 0 del Architecture Decision Document):

```text
api/src/Shared/
├── Domain/
│   ├── Exception/
│   │   └── InvalidSearchCriteria.php                 [N] marker → 400 (D3)
│   └── Search/Exception/                             [N] subdirectorio nuevo
│       ├── UnknownSearchField.php                    [N] type unknown-search-field
│       └── UnsupportedSearchOperator.php             [N] type unsupported-search-operator
└── Application/Problem/
    └── ProblemDetailsFactory.php                     [M] +2 entradas en los maps + use

api/tests/Unit/Shared/
├── Domain/Search/Exception/                          [N] directorio nuevo (espejo del src)
│   ├── UnknownSearchFieldTest.php                    [N]
│   └── UnsupportedSearchOperatorTest.php             [N]
└── Application/Problem/
    ├── MarkerStatusMapContractTest.php               [M] canónicos 7→8 + rama match
    └── ProblemDetailsFactoryTest.php                 [M] fix espejo first-wins (+ pin integración)

docs/api-error-contract.md                            [M] fila InvalidSearchCriteria (NFR26)
```

- Autoload PSR-4 ya cubre ambos lados; sin cambios de config, Compose, Make ni `.env`. Sin migraciones (NFR5).
- `Search/Exception/` bajo Domain NO dispara la obligación documental de source-tree (esa es para `Doctrine/Search/`, story 1.3/1.6); la obligación de ESTA historia es exclusivamente `docs/api-error-contract.md`.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 1.2] — historia y ACs canónicos; D3.
- [Source: _bmad-output/planning-artifacts/architecture.md#Naming Patterns] — FQCNs pineados, convención sin sufijo, types kebab-case.
- [Source: _bmad-output/planning-artifacts/architecture.md#API & Communication Patterns] — D3 completo: maps, doc, contract test, gate (coste NFR26 asumido).
- [Source: _bmad-output/planning-artifacts/architecture.md#Format Patterns] — capa de validación pineada (semántica → applier → familia `invalid-search-criteria`).
- [Source: _bmad-output/implementation-artifacts/1-1-vocabulario-de-filtros-en-el-dominio-compartido.md#Dev Agent Record] — rama/worktree de fase 0, lecciones Psalm/fixer/Behat-bootstrap.
- [Source: docs/api-error-contract.md#Marker interface → HTTP status table] — tabla a extender; walk-through canónico de excepción concreta (`BankNotFound`).
- [Source: api/src/Shared/Application/Problem/ProblemDetailsFactory.php] — maps (111–129), `resolveDomainType`, `firstMatchingMarker`, whitelist/sentinel de context.
- [Source: api/tests/Unit/Shared/Application/Problem/MarkerStatusMapContractTest.php] — pins a actualizar (canónicos, count, match).
- [Source: api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php#testHttpStatusTypeMapValuesMirrorMarkerDefaultTypeMapValues] — trampa de inversión last-wins.
- [Source: api/tests/Unit/Shared/Domain/Exception/RateLimitExceededTest.php] — convención de tests de excepciones concretas.
- [Source: docs/project-context.md#Language-Specific Rules] — PHP 8.5, strict_types, excepciones de dominio, Domain puro.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Claude Opus 4.8, 1M context) — Claude Code

### Debug Log References

- RED inicial: 6 errores (clases inexistentes) + 1 failure (`assertCount` 7≠8 markers) — fallo confirmado antes de implementar.
- Dos pins de "Seven" adicionales no anticipados en `ProblemDetailsFactoryTest` (líneas 564/591): `testMarkerStatusMapHasExactlyTheCanonicalSevenEntries` y `testMarkerDefaultTypeMapHasExactlyTheCanonicalSevenEntries` pinean el contenido COMPLETO de ambos maps en orden canónico — actualizados a Eight con las entradas nuevas.
- PHPStan `method.alreadyNarrowedType` sobre los `assertInstanceOf` de los tests de marker (la declaración ya lo prueba estáticamente) — reescritos con pins runtime-derived (`class_implements`/`class_parents` casteados a array), que PHPStan no constant-folda.
- PHPMD `CouplingBetweenObjects` (13) en `MarkerStatusMapContractTest` al añadir el import del marker — resuelto con `@SuppressWarnings("PHPMD.CouplingBetweenObjects")`, precedente ya establecido en `ProblemDetailsFactoryTest` y el propio factory para esta familia de tests de contrato.
- El sub-check git-aware de `ErrorContractGateTest` se marca skipped dentro del contenedor (el `.git` del worktree apunta a una ruta del host inexistente en el contenedor); el invariante real se verificó manualmente en el host: `git diff --diff-filter=A base...HEAD -- src/Shared/Domain/Exception/` devuelve el marker nuevo Y `docs/api-error-contract.md` aparece cambiado en el mismo diff — en CI (checkout completo) el check corre entero.

### Completion Notes List

- Rama de error completa del mecanismo de filtros entregada: marker `InvalidSearchCriteria` (interface vacía, octavo marker canónico) mapeado a 400/`invalid-search-criteria` como última entrada de ambos maps del factory; excepciones concretas `UnknownSearchField` (`unknown-search-field`, context `['field']`) y `UnsupportedSearchOperator` (`unsupported-search-operator`, context `['field', 'operator' => wire token]`) con named constructors estables que la 1.3 consumirá tal cual (`named()`/`forField()`).
- `HTTP_STATUS_TYPE_MAP` intacto (7 entradas): el bridge Symfony mantiene `invalid-input` genérico para 400; el test espejo se adaptó a first-wins por status con comentario de why — los markers específicos que comparten status se declaran DESPUÉS del genérico en `MARKER_STATUS_MAP`.
- Pin de integración nuevo: `testUnknownSearchFieldMapsTo400WithItsExplicitType` demuestra marker→400 + `type()` explícito ganando al default + context como extensions (también protege la relación `implements` por comportamiento: sin marker, el status caería a 500).
- `TaxonomyArchitectureTest` recoge el marker automáticamente vía glob (verificado en verde, cero ediciones).
- NFR26 completo: fila en la tabla de markers de `docs/api-error-contract.md` + párrafo explicando el 400 compartido con `InvalidInput` y la ubicación de las concretas; refs de líneas refrescadas (112–121/123–132). Higiene aplicada: fila stale del `SearchExceptionListener` (ya inexistente) retirada de la tabla "Listener layout" y docblock stale de `findInChain` en el factory actualizado.
- Gates finales: `php.stan` ✅ · `php.psalm` ✅ · `php.unit` 560/560 (3 skips preexistentes; +11 tests nuevos sobre la baseline 549) ✅ · `php.behat` 71 escenarios / 580 steps ✅ · `php.quality` completo exit 0 ✅ · `php.lint.error-contract` ✅ (skip ambiental del sub-check git en contenedor; invariante verificado en host).
- Seguridad: sin superficie HTTP nueva (nada lanza las excepciones hasta la 1.3); titles estáticos, dato dinámico solo en context (pasa por reserved-keys/denylist/whitelist del factory); operator viaja como token wire del enum, nunca la instancia (evita el sentinel `[unserializable]`). Clases de inyección/authn/authz/mass-assignment no aplican — declararlo en el PR de fase 0.
- Para la **story 1.4**: su AC "verificar que el legacy `SearchExceptionListener` (priority 32) no intercepta las excepciones nuevas" quedó obsoleta — el listener ya no existe en el código (este hallazgo se documentó y la fila stale se retiró del doc en esta historia).
- Commit `f53e3b8` en `feat/shared-search-filters-aj0w` (worktree `.claude/worktrees/shared-search-filters-aj0w`). La story 1.3 continúa en esta rama (PR único de fase 0).

### File List

- `api/src/Shared/Domain/Exception/InvalidSearchCriteria.php` (nuevo)
- `api/src/Shared/Domain/Search/Exception/UnknownSearchField.php` (nuevo)
- `api/src/Shared/Domain/Search/Exception/UnsupportedSearchOperator.php` (nuevo)
- `api/src/Shared/Application/Problem/ProblemDetailsFactory.php` (modificado — maps + use + docblock stale de findInChain)
- `api/tests/Unit/Shared/Domain/Search/Exception/UnknownSearchFieldTest.php` (nuevo)
- `api/tests/Unit/Shared/Domain/Search/Exception/UnsupportedSearchOperatorTest.php` (nuevo)
- `api/tests/Unit/Shared/Application/Problem/MarkerStatusMapContractTest.php` (modificado — canónicos 7→8 + rama match + suppress coupling)
- `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` (modificado — pins Eight, provider, espejo first-wins, pin integración)
- `docs/api-error-contract.md` (modificado — fila marker + refs líneas + retirada listener stale)

## Change Log

- 2026-06-07: Story 1.2 implementada y verificada (commit `f53e3b8`, rama `feat/shared-search-filters-aj0w`). 9 ficheros (+191/−32 líneas aprox.). 7 tests unit nuevos + 4 actualizados. Todos los gates en verde. Status → review.
- 2026-06-07: Code review adversarial (3 capas) — ACs 1–4 PASS. 3 patches aplicados en el worktree (tabla de markers re-padded, ref de líneas `firstMatchingMarker` 352–364 → 444–456, pin de integración espejo `testUnsupportedSearchOperatorMapsTo400WithItsExplicitType`); 1 defer (precedencia dual-marker, registrado en deferred-work.md); 8 descartados. Gates post-patch: php.unit 175 OK (filtro afectado) · php.stan ✅ · php.psalm ✅ · php.quality exit 0 ✅ · php.lint.error-contract ✅. Cambios pendientes de commit en la rama. Status → done.
