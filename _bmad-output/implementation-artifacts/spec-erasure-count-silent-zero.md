---
title: 'El cero fabricado de los adaptadores de borrado masivo'
branch: 'fix/shared-erasure-count-silent-zero-xrfs'
base: 'c408df9c'
status: 'in-review'
date: '2026-08-30'
---

## Intent

Un defecto, siete sitios, y el número que fabrican es **evidencia de borrado GDPR**. El pase adversarial de
la PR #878 lo registró como «MENOR registrado y NO arreglado», con su motivo; no recibió artefacto de
seguimiento y era lo único abierto de aquella sesión. Esto lo cierra, y añade la puerta que impide el octavo.

## El defecto

Los siete adaptadores Doctrine que ejecutan una sentencia masiva terminan igual:

```php
return \is_int($affected) ? $affected : 0;      // seis sitios
return \is_int($affected) && $affected > 0;     // consume(), séptimo
```

`Doctrine\ORM\AbstractQuery::execute()` está declarado **`mixed`** (`api/vendor/doctrine/orm/src/AbstractQuery.php:881`),
así que todo adaptador cuyo puerto promete un `int` tiene que estrechar. Ese estrechamiento no es defensa:
**inventa** el valor. Y el valor que inventa es `0`, que es exactamente el que sus consumidores leen como
«no había nada que borrar»:

- `EraseIdentitySubject.php:68` lee `$tokensDeleted` → `IdentityErasureResult` → `FulfilIdentityErasureResult`
  → salida de `identity:gdpr:erase-subject`.
- `SessionRepository::deleteAllForUser()` promete en su propio docblock que «una segunda pasada sobre un
  sujeto sin filas borra nada y devuelve 0» — de modo que un cero legítimo y un cero fabricado por el
  fallback de tipos son **indistinguibles para todo llamante**.
- La PR #878 elevó ese conteo a «esto SÍ se promete, y es sobre lo que un llamante puede actuar»
  (`PasswordResetTokenRepository`), que es lo que vuelve el fallback incoherente y no meramente inútil.

Hoy la rama es **inalcanzable**: un `DELETE` DQL devuelve el `int` del driver. Lo que cambia no es el
comportamiento de hoy, es **la dirección en la que falla** el día que esa premisa deje de valer.

## La forma elegida, y las descartadas

**Elegida (c) — guarda compartida.** `Erpify\Shared\Persistence\Infrastructure\AffectedRows::from(mixed): int`
estrecha o **lanza** `UnexpectedValueException`. Siete sitios pasan a `AffectedRows::from($affected)`.

- Regla de Tres: siete copias del mismo estrechamiento, no dos.
- `Shared/Persistence/Infrastructure` es el hogar honesto: existe por el `mixed` de una ORM, y sólo los
  adaptadores lo llaman. `Erpify\Shared\…` es importable desde cualquier contexto.
- `UnexpectedValueException` y no un `DomainException`: nada en la petición del llamante está mal y no hay
  respuesta sobre la que un cliente pueda actuar. Es la misma categoría que
  `DbalAuditTimelineRepository::requiredString()` — «el almacén devolvió una forma que no puede ser
  correcta» — y sale como el 500 que es. Un marker interface lo metería en el contrato de error como si el
  cliente tuviera elección.

**(a) borrar el fallback y dejar que PHPStan pruebe el tipo — descartada, medida.** `execute()` es `mixed`,
así que `return $affected;` enrojece PHPStan level max. La variante `@phpstan-var int` es **la misma mentira
en forma de anotación**, y peor: sin comprobación en tiempo de ejecución.

**(b) `throw` en cada sitio — descartada.** Siete copias de la misma guarda es exactamente lo que la Regla de
Tres prohíbe, y es la forma que produjo las siete copias del defecto.

## `consume()` entra en el cambio, y por qué

Es el guardián de un solo uso del reset de contraseña, y su forma es distinta: un no-int devolvía `false`,
es decir «la fila ya no estaba», y **aborta**. Falla cerrado — pero mintiendo el motivo. A quien sostiene un
token vivo se le dice que está gastado; es un diagnóstico sobre el que no puede actuar y cuyo reintento
falla idéntico. Con la guarda **sigue abortando** (la propiedad «un token, un reset» se conserva entera),
pero como un 500 diagnosticable en vez de un diagnóstico falso. La decisión queda escrita en su propio
sitio, no sólo aquí.

## La puerta, y por qué no es una prohibición de grafía

`BulkStatementCountNarrowingGateTest` (+ motor `BulkStatementMethods`) **deriva el universo**: todo método
de `api/src` que obtiene una `Query` de la ORM (`->getQuery()` / `->createQuery(`) y ejecuta algo
(`->execute(`), y que **devuelve** el resultado, debe alcanzar `AffectedRows::from(`.

- Prohibir `is_int(` prohíbe `is_int(`. `is_numeric()`, un cast `(int)` y un `@phpstan-var int` llegan al
  mismo sitio equivocado y pasarían. Exigir que el resultado **alcance** la guarda es una obligación
  positiva: no se rodea, se cumple.
- Un método `void` queda fuera **por firma, no por exención**: no devuelve conteo, así que no hay ninguno que
  fabricar. Es el caso de `bulkRevokeActive()`. **No hay allowlist, y no debe haberla** — una lista de
  exenciones es cómo se escribe el octavo sitio.
- La atribución es al método **envolvente**: los adaptadores meten la sentencia en una arrow function
  (`convertingStoreFailure(fn (): mixed => …->execute())`), así que la llamada vive en un closure y la
  obligación en el método que devuelve el valor.
- El par `getQuery`+`execute` es necesario porque **`execute()` es la convención de invocación de casos de
  uso de este repositorio**: un detector de `->execute(` pelado devolvió cinco falsos positivos en la primera
  ejecución (`FulfilIdentityErasure::execute()`, `UserEraseController::__invoke()`, los dos `eraseAndReport()`
  y `DoctrineSearchEngine::paginate()`). Medido, no supuesto.

**La rejilla se genera, no se filtra del árbol.** Una puerta cuyo único sujeto es el código que vigila no
distingue «la regla se cumple» de «el escáner no vio nada», y la contención es circular en cuanto los casos
salen de la misma barrida. `testTheScannerClassifiesEachShapeItMustDistinguish` le da formas que el árbol no
contiene y afirma la clasificación de cada una.

## Verificación

| Puerta | Resultado |
|---|---|
| `make php.stan` | `[OK] No errors` — exit 0 |
| `make php.unit` | 3429 tests, 17771 aserciones, 2 skipped — exit 0 |
| `make php.behat` | 472 escenarios, 4378 pasos, todos verdes — exit 0 |
| `make php.quality` | exit 0 (Rector converge: segunda pasada, 0 ficheros) |
| `make php.quality.dry-run` | exit 0 — **la que corre CI**, y se ejecutó aparte por eso |
| `make php.lint.gate-placement` | 10 tests — exit 0, con la línea nueva del registro |

`pwa.quality` / `pwa.test` no se ejecutaron: esta rama no toca `pwa/`.

**Un tira y afloja Rector ↔ PHPStan, resuelto reestructurando y no suprimiendo.** Rector sube `\count()`
fuera de la condición del bucle a un `$counter` (regla de rendimiento), y eso es justo lo que rompe el
estrechamiento de offset de PHPStan level max: verde en `php.stan`, rojo tras `php.quality`. Los cuatro
bucles preguntan ahora `isset($tokens[$i])` — la existencia del offset, que es lo que ambos quieren, sin
`count()` que subir ni cota que probar.

## Matriz de falsificación

Cada aserción provocada roja **por su propia mutación**, restaurada por copia de bytes desde una pristina
(nunca `git checkout --`); `diff -rq` contra las pristinas al final: idénticas.

| # | Mutación | Rojo esperado | Medido |
|---|---|---|---|
| M1 | El guard vuelve a `\is_int($result) ? $result : 0` | los 6 casos que deben lanzar | `Failures: 6`, los seis nombrados |
| M2 | `is_numeric()` en vez de `is_int()` | sólo cadena numérica y float | 2 fallos, exactamente esos dos |
| M3 | El guard rechaza el cero legítimo | `testKeepsTheLegitimateZero…` | 1 fallo, ése |
| M5 | Replantar el fallback original en `DoctrineMembershipRepository` | la puerta, nombrando el sitio | `deleteAllForUser(): int` de Membership |
| M6 | Octavo sitio con **otra grafía**: `(int) $affected` en Session | la puerta igual | los dos métodos de Session |
| M7 | `yieldsItsResult()` deja de discriminar `void` | rejilla + árbol | `discardingTheCount`/`wrappingAClosure` pasan a `governed` |
| M8 | `code()` deja de descartar el CONTENIDO de los literales | rejilla, en las dos direcciones | `quotingTheStatement` aparece **y** `quotingTheGuard` pasa a `guarded` |
| M8b | Deja de quitar comentarios | rejilla | `commentedOnly` aparece |
| M9 | La barrida se vacía (raíz inexistente) | la anti-vacuidad | `testTheSweepStillReaches…` |
| M10 | Detector reducido a la cadena fluida | rejilla | `heldInAVariable` desaparece |
| M11 | Detector ensanchado a `->execute(` pelado | rejilla + árbol | `throughAUseCase` aparece; 5 falsos positivos en el árbol |

**M8 volvió verde en su primera ejecución** — la rejilla no discriminaba el descarte de literales, porque el
caso que tenía no contenía además una fuente de `Query`. Fallo del diseño del test, no del código: se
añadieron `quotingTheStatement` y `quotingTheGuard`, y la segunda es la dirección que importa (prosa
haciéndose pasar por el estrechamiento). Se registra porque una matriz que sólo enumera los rojos que
salieron a la primera no es una medición.

## Lo que un verde NO prueba

- La puerta **nunca juzga si el conteo es correcto**: una sentencia a la que le falta un predicado, una cuya
  transacción hace rollback después, o una que cuenta la tabla equivocada devuelven un `int` perfectamente
  bien tipado y pasan.
- Una `Query` obtenida en un método y ejecutada en **otro** es invisible: el par se lee dentro de un mismo
  cuerpo. Hoy no existe esa forma en el árbol (medido: 20 `->getQuery()`, todos en cadena fluida).
- Un método `void` cuyo closure entregue un conteo fabricado a otro sitio: la firma es el discriminante.
- Alcanzar la guarda es la obligación; **usar su respuesta no lo es**.
- Una grafía futura de la ORM para cualquiera de las dos mitades queda fuera. El resto de puntos ciegos está
  enumerado en la cabecera de `BulkStatementMethods`.

## Adversarial pass

<!-- pendiente: se rellena antes de `gh pr create` -->
