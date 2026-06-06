---
title: 'Cadena de resolvers para type-hints del query-string Behat'
type: 'refactor'
created: '2026-06-06'
status: 'done'
context: []
baseline_commit: '15091d925a746d5478d14f0544dd384de8aefef5'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `EntityManagerToolTrait::handleQueryStringTypeHinting` concentra cuatro reglas de resolución heterogéneas (null, enum, value-object, date) con complejidad ciclomática suprimida (`@SuppressWarnings PHPMD.CyclomaticComplexity`) y un parche previo (`resolveEnumValue`, extraído solo para cumplir el presupuesto de returns de Sonar S1142). Cada regla nueva agranda el método en lugar de añadir una pieza.

**Approach:** Extraer la resolución a una cadena de responsabilidad: orquestador `TypeHintValueResolver` + resolvers especializados (`NullValueResolver`, `EnumValueResolver`, `ValueObjectResolver`, `DateValueResolver`) bajo `api/tests/Behat/Support/Tool/TypeHint/`, eliminando del trait ambos métodos. Semántica de resolución idéntica, verificada por test unitario nuevo.

## Boundaries & Constraints

**Always:**
- Semántica idéntica a la actual — la matriz I/O es el contrato.
- Orden de la cadena: Null → Enum → ValueObject → Date. Los enums pasan `class_exists()`, por lo que Enum DEBE preceder a ValueObject.
- Primer resolver cuyo `supports()` devuelva `true` gana; si ninguno soporta, se devuelve el valor crudo.
- Clases `final`, `declare(strict_types=1)`, interfaces con sufijo `Interface` (convención `HumanReadableIntEnumInterface`).
- Namespace `Erpify\Tests\Behat\Support\Tool\TypeHint`.

**Ask First:**
- Si aparece algún caller externo de `handleQueryStringTypeHinting` no detectado en la investigación (hoy: solo `parseFindByQueryString:93`).
- Si la baseline de Psalm exige regenerarse con cambios que toquen ficheros fuera del alcance.

**Never:**
- No tocar `parseFindByQueryStringEntity`, `autoDetectType` ni el resto del trait más allá de la delegación, imports y supresiones que queden obsoletas.
- No introducir DI/contenedor ni registrar servicios — es tooling de tests, instanciación directa.
- No cambiar el formato del query-string (`campo:tipo`) ni la inferencia de tipo por metadata de Doctrine (se queda en `parseFindByQueryString`).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Null literal | `value='null'`, cualquier `type` | `null` | N/A |
| Enum por label | `type` FQCN de `HumanReadableIntEnumInterface`, `value` label string | caso enum (`fromLabel`) | label sin match → devuelve el label crudo |
| Enum, array de labels | mismo `type`, `value` array de labels | array resuelto ítem a ítem (índices preservados) | ítem sin match → label crudo |
| Date hint | `type='date'`, `value` escalar | `new DateTime((string) $value)` | `assert(is_scalar)` como hoy |
| Value object | `type` FQCN de clase existente, constructor acepta `value` | instancia `new $type($value)` | N/A |
| Value object falla | mismo `type`, constructor lanza `Throwable` | valor crudo sin modificar | catch silencioso (comportamiento actual) |
| Sin tipo / builtin | `type=null` o `'string'`/`'int'`… | valor crudo sin modificar | N/A |

</frozen-after-approval>

## Code Map

- `api/tests/Behat/Support/Tool/EntityManagerToolTrait.php` — trait objetivo: eliminar `handleQueryStringTypeHinting` (l.103-128) y `resolveEnumValue` (l.220-242); único caller en `parseFindByQueryString:93`; limpiar imports (`HumanReadableIntEnumInterface`, posiblemente `DateTime` no — se usa en `getLastEntity`).
- `api/tests/Behat/Support/Tool/RelationQueryHelper.php` — convención sibling: `final class` en el mismo namespace.
- `api/src/Shared/Domain/Enum/Abstraction/HumanReadableIntEnumInterface.php` — `fromLabel(string): ?self` (estático, nullable).
- `api/tests/Unit/Shared/Domain/Enum/Abstraction/Fixtures/FullyLabeledIntEnum.php` — enum fixture reutilizable para el test.
- `api/tests/Behat/Context/EntityManagerContext.php` — único consumidor del trait; no llama a los métodos eliminados.
- `api/tools/psalm/psalm-baseline.xml` — tiene 11 entradas para el trait; pueden quedar obsoletas tras el refactor.

## Tasks & Acceptance

**Execution:**
- [x] `api/tests/Behat/Support/Tool/TypeHint/ValueResolverInterface.php` — crear interface con `supports(mixed $value, ?string $type): bool` y `resolve(mixed $value, ?string $type): mixed` — contrato de la cadena.
- [x] `api/tests/Behat/Support/Tool/TypeHint/NullValueResolver.php` — crear: soporta `'null' === $value`; resuelve a `null`.
- [x] `api/tests/Behat/Support/Tool/TypeHint/EnumValueResolver.php` — crear: soporta `$type` is_a `HumanReadableIntEnumInterface`; resuelve label o array de labels (lógica actual de `resolveEnumValue`).
- [x] `api/tests/Behat/Support/Tool/TypeHint/ValueObjectResolver.php` — crear: soporta `is_string($type) && class_exists($type)`; intenta `new $type($value)`, en `Throwable` devuelve el valor crudo.
- [x] `api/tests/Behat/Support/Tool/TypeHint/DateValueResolver.php` — crear: soporta `'date' === $type`; resuelve `new DateTime((string) $value)` con assert escalar.
- [x] `api/tests/Behat/Support/Tool/TypeHint/TypeHintValueResolver.php` — crear orquestador `final` con cadena por defecto en orden Null → Enum → ValueObject → Date (param default con `new` in initializers, PHP 8.1+); método `resolve(mixed $value, ?string $type): mixed`.
- [x] `api/tests/Behat/Support/Tool/EntityManagerToolTrait.php` — sustituir la llamada de `parseFindByQueryString:93` por el orquestador (instancia local antes del bucle); eliminar los dos métodos; limpiar imports y `@SuppressWarnings` que queden sin causa.
- [x] `api/tests/Unit/Behat/Support/Tool/TypeHint/TypeHintValueResolverTest.php` — crear test PHPUnit 13 (atributos `#[Test]`/`#[DataProvider]`) cubriendo TODA la matriz I/O, usando `FullyLabeledIntEnum` como fixture de enum.

**Acceptance Criteria:**
- Given la matriz I/O, when se ejecuta el test unitario nuevo, then cada escenario produce exactamente la salida de la implementación previa.
- Given la suite Behat existente, when se ejecuta `make php.behat`, then pasa igual que en `main` (sin escenarios nuevos rotos).
- Given el trait refactorizado, when se ejecuta `make php.stan` y `make php.quality`, then no aparecen errores nuevos (baseline de Psalm ajustada solo si hay entradas obsoletas del trait).

## Spec Change Log

## Design Notes

Forma del orquestador (golden example):

```php
final readonly class TypeHintValueResolver
{
    /** @param list<ValueResolverInterface> $resolvers */
    public function __construct(
        private array $resolvers = [
            new NullValueResolver(),
            new EnumValueResolver(),
            new ValueObjectResolver(),
            new DateValueResolver(),
        ],
    ) {}

    public function resolve(mixed $value, ?string $type): mixed
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver->supports($value, $type)) {
                return $resolver->resolve($value, $type);
            }
        }

        return $value;
    }
}
```

Racional: el fallthrough actual «constructor lanza → sigue al check `'date'`» es equivalente a terminar la cadena en `ValueObjectResolver`, porque `'date'` no es una clase (`class_exists('date') === false`) — ambas ramas son mutuamente excluyentes.

## Verification

**Commands:**
- `make php.unit c='--filter TypeHintValueResolverTest'` — expected: verde, toda la matriz cubierta.
- `make php.stan` — expected: sin errores en los ficheros nuevos ni en el trait.
- `make php.behat` — expected: suite igual de verde que en `main`.
- `make php.quality` — expected: sin hallazgos nuevos (cs-fixer/psalm/phpcs limpios).

## Suggested Review Order

**La cadena (intención de diseño)**

- Punto de entrada: orquestador first-match-wins; el orden Null → Enum → ValueObject → Date es semántico
  [`TypeHintValueResolver.php:14`](../../api/tests/Behat/Support/Tool/TypeHint/TypeHintValueResolver.php#L14)

- Contrato `supports()`/`resolve()` que cada eslabón implementa
  [`ValueResolverInterface.php:11`](../../api/tests/Behat/Support/Tool/TypeHint/ValueResolverInterface.php#L11)

**Los resolvers**

- El más complejo: labels de enum escalares y arrays (índices preservados), fallback al label crudo
  [`EnumValueResolver.php:23`](../../api/tests/Behat/Support/Tool/TypeHint/EnumValueResolver.php#L23)

- Catch `Throwable` → valor crudo; docblock justifica por qué terminar la cadena aquí es equivalente
  [`ValueObjectResolver.php:14`](../../api/tests/Behat/Support/Tool/TypeHint/ValueObjectResolver.php#L14)

- Cortocircuito del literal `'null'`, cabeza de la cadena como en el original
  [`NullValueResolver.php:12`](../../api/tests/Behat/Support/Tool/TypeHint/NullValueResolver.php#L12)

- Hint `date` → `DateTime` mutable (comportamiento pre-extracción)
  [`DateValueResolver.php:13`](../../api/tests/Behat/Support/Tool/TypeHint/DateValueResolver.php#L13)

**Integración en el trait**

- Una instancia antes del bucle; delegación en la línea 90; los dos métodos y 5 `@SuppressWarnings` eliminados
  [`EntityManagerToolTrait.php:65`](../../api/tests/Behat/Support/Tool/EntityManagerToolTrait.php#L65)

**Periféricos**

- Matriz I/O completa como data provider (7 escenarios del spec → 9 tests)
  [`TypeHintValueResolverTest.php:38`](../../api/tests/Unit/Behat/Support/Tool/TypeHint/TypeHintValueResolverTest.php#L38)

- Solo las 2 entradas huérfanas (`$type`/`$value`) eliminadas; el resto del bloque intacto
  [`psalm-baseline.xml`](../../api/tools/psalm/psalm-baseline.xml)

- Fixtures mínimos para las filas value-object de la matriz
  [`StringValueObject.php:1`](../../api/tests/Unit/Behat/Support/Tool/TypeHint/Fixtures/StringValueObject.php#L1)
