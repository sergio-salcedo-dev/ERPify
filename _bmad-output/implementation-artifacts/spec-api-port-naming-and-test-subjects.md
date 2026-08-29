---
title: 'Nombres de puerto honestos y tests colocados en su sujeto (residuos 2, 5, 6 y 7 de la ronda 3 de #871)'
type: 'chore'
created: '2026-08-29'
status: 'in-review'
baseline_commit: '82db9406'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/spec-deferred-work-sweep.md'
  - '{project-root}/docs/rules/testing.md'
  - '{project-root}/docs/rules/clean-code.md'
---

## Intent

Cuatro residuos de la tercera ronda adversarial de #871. El hilo que los une no es el área del código sino
la **forma del defecto**: en los cuatro, algo *afirma* más de lo que hace. Un nombre que dice lo contrario de
su predicado, un tipo que admite la configuración que su clase existe para prohibir, un test que dice comparar
seis respuestas y compara tres campos, y un test cuyo sujeto declarado no es el que puede ponerlo rojo.

Decidido con consulta externa (ChatGPT) y debate contra medición. **Tres de las siete premisas que la consulta
dio por buenas resultaron falsas contra el árbol**, y están corregidas abajo. El alcance (2+5+6+7) es decisión
del usuario; (3) y (4) quedan decididos pero fuera de esta rama.

## Lo implementado, y lo que la medición cambió

### (2) `keepsAnActiveAdminWithout` → `survivesRemovalOf`

20 ocurrencias en 7 ficheros, más una línea de `docs/adr/administrative-recovery-channel.md:163` (cuyos dos
números de línea citados estaban rancios y se corrigen de paso).

**Lo que la medición añadió sobre la consulta:** el vocabulario «survive» ya estaba en el árbol por los dos
extremos — el docblock de la propia interfaz dice *"does the active-`ADMIN` set **survive** this identity
leaving it"* y el consumidor envuelve la llamada en `ChangeUserRoles::guardActiveAdministratorsSurvive()`. El
rename no introduce lenguaje: **cierra una divergencia entre el nombre y la prosa que ya lo rodeaba**.

**Corrección al handoff:** decía que «el docblock gasta ocho líneas diciendo que el nombre miente». Medido, el
docblock tiene doce líneas y sólo el primer párrafo (seis) contenía el desmentido; el segundo (autoridad
`existe AND ACTIVE`, el `FOR UPDATE`, *debe leerse dentro de la transacción del caller*, *el caller debe ya
tener la fila del sujeto*) no es deducible de ningún nombre y se conserva íntegro. El docblock encoge de 12 a
11 líneas. La frase del conjunto vacío **no** se va: es exactamente la mala lectura que el nombre nuevo
todavía admite, así que pasa de aclaración a requisito.

Los artefactos históricos de `_bmad-output/` que citan el nombre viejo se dejan intactos: son el registro de
lo que se midió entonces, y reescribirlos falsifica ese registro.

### (5) `StrictRequestPayload::$acceptFormat`

**La premisa de la consulta era correcta y ahora está medida**, en
`vendor/symfony/http-kernel/Controller/ArgumentResolver/RequestPayloadValueResolver.php:242`:

```php
if ($attribute->acceptFormat && !\in_array($format, (array) $attribute->acceptFormat, true)) {
```

`null` no *afloja* la comprobación de formato: es falsy, así que **la salta entera** y el endpoint acepta
form-encoded y multipart. El docblock de la clase ya lo sabía (*"the resolver only runs its format check when
the list is non-empty"*) y el tipo lo permitía igualmente.

**Lo que ni la consulta ni el handoff tenían:** `null` no es la única grafía falsy, y estrechar el tipo sólo
cierra esa. El constructor rechaza el resto — pero **la primera versión de esa guarda las enumeró (`[]`, `''`)
y el pase adversarial la rompió en un carácter**: `'0'` es un string falsy, sobrevivía al tipo y a la guarda, y
fue medido aceptando un cuerpo form-encoded a través del resolver real. La guarda es ahora `if (!$acceptFormat)`
— **espeja el predicado del resolver en vez de enumerar los valores que lo satisfacen**, que es más corto que
lo que se escribió y es la forma que este repo ya tiene documentada como fallida dos veces. Una prueba de
veracidad no puede quedarse corta de un miembro; una lista sí.

Declarar `non-empty-array` en el `@param` en vez de la guarda se descartó: haría la guarda «siempre falsa» para
PHPStan y el test que la provoca sería inescribible.

**Corrección al handoff:** decía 11 sitios de producción. Son **13** (contados uno a uno; las otras 11 de las
24 apariciones son menciones en docblock). Ninguno pasa argumento; el único que pasa algo es
`StrictRequestPayloadTest`, y pasa `'json'`.

PHPStan no objeta la varianza del constructor — medido, no supuesto.

### (6) La comparación de opacidad

Trait `api/tests/Functional/ComparesOpaqueRefusals.php`, junto a los otros tres helpers funcionales del
árbol. **Trait y no clase estática**, divergiendo de la consulta: el helper necesita `self::assert*`; una
clase estática tendría que recibir el `TestCase` o cambiar aserciones por excepciones, peor diagnóstico a
cambio de una pureza que aquí no compra nada. Y `tests/Support/` es el hogar de *motores* sin estado (2 de sus
~41 ficheros tocan PHPUnit), mientras los tres helpers funcionales existentes son traits en
`api/tests/Functional/`. Lleva `@phpstan-require-extends`, que los tres existentes no llevan.

El tercer test pasa de comparar `{type,title,status}` a comparar cuerpo completo + `Content-Type`.

**La duda con la que la consulta cerraba —«puede ir rojo y descubrir un defecto real»— se resolvió midiendo
antes de escribir.** Un lift ingenuo iría rojo por tres miembros, y ninguno es una fuga:

| Miembro | Por qué diverge | Fuente |
|---|---|---|
| `instance` | UUIDv7 acuñado **por ocurrencia de error**, no derivado de la petición | `ExceptionResponder.php:200` |
| `correlation-id` | el test **no enviaba** `X-Correlation-Id`; el listener acuña uno por petición | `CorrelationIdListener:13-26` |
| `debug.line` | `APP_ENV=test` ⇒ `DEBUG_MODE_FULL`, y las seis se lanzan desde ≥4 líneas | `ProblemDetailsFactory.php:604-611` |

Y la objeción concreta de la consulta —«¿y si `detail` puede variar legítimamente?»— es **estructuralmente
imposible**: las tres ramas de `ProblemDetailsFactory::fromThrowable` pasan `detail: null` (`:279`, `:335`,
`:363`) y `ProblemDetails::toArray():42-45` omite la clave. Elevado a la manera de los otros dos, **queda
verde**: no había fuga preexistente.

Hallazgo lateral que el handoff no tenía: el tercer test **no fijaba la correlación**, así que sus seis
respuestas ya llevaban seis ids distintos y nadie lo veía. Ahora la fija.

### (7) El caso mal colocado

`ChangeUserStatusTest::testTheInMemoryDirectoryMatchesTheAdaptersCaseInsensitiveMembership` no protegía
`ChangeUserStatus` — el caso de uso no compara ids, delega. Movido a
`InMemoryActiveAdministratorDirectoryContractTest`.

**La consulta afirmaba que falta la evidencia Regla-de-Tres para un test cuyo sujeto es un double. No falta:
ya había dos** — `InMemorySessionRepositoryContractTest` y `InMemorySessionRepositoryBulkRevocationContractTest`,
ambos `#[CoversClass(InMemorySessionRepository::class)]`. Su recomendación no es una alternativa minimalista,
es **el patrón establecido del repo**, y éste es el tercero.

Cubre **las dos mitades** del `strcasecmp` del double, no sólo la que se movió: el docblock del double dice
«in both of its halves», y pinchar una dejaría la otra leyéndose como cubierta — el defecto exacto que (8)
era. Un cuarto caso fija la frontera, para que un double que no reconociera **nada** fallara los tres de
casing en vez de pasar éste por accidente.

## Matriz de falsificación

Cada guarda provocada roja por **su propia mutación**, restaurada por copia de bytes desde una copia pristina
(nunca `git checkout --`). Ninguna mutación es un «class not found».

| # | Mutación | Esperado | Medido |
|---|---|---|---|
| M1 | `acceptFormat` re-ensanchado a `array\|string\|null` | rojo sólo en el caso del tipo | `Failures: 1` — `itsAcceptFormatCannotBeSpelledAsNull` |
| M2 | guarda de vacío eliminada | rojo sólo en los dos casos de vacío | `Failures: 2` — `…EmptyAcceptFormatList`, `…EmptyAcceptFormatString` |
| M3 | `activeEntryFor()` case-SENSITIVE | rojo en los 3 casos de casing, verde el de frontera | `Failures: 3` |
| M4 | el scan de `survivesRemovalOf` case-SENSITIVE | rojo sólo en los 2 de `survivesRemovalOf` | `Failures: 2` |
| M5 | `InvalidToken` con contexto en **uno** de los seis sitios | rojo en el test elevado, por una **extensión** | `Failures: 1`, diff `+ 'hint' => 'no-separator'` |
| M6 | quitada la cabecera `X-Correlation-Id` | rojo por `correlation-id` | `Failures: 1`, dos UUIDv7 distintos |
| M7 | método del puerto renombrado sólo en la interfaz | fatal de tipo | `Fatal error: … must implement … survivesRemovalOfRenamedAgain` |
| M8 | guarda de vacío **enumerada** en vez de espejada | rojo sólo en el caso `'0'` | `Failures: 1` — `itRefusesAFalsyAcceptFormat@the string '0'` |
| M9 | borrado el disyuntivo de pertenencia del double | rojo en los dos casos de conjunto | `Failures: 2` — conjunto vacío y conjunto fantasma |

**M7 es deliberadamente el rojo más débil y se registra como tal.** (2) es un rename: no gana una guarda
nueva, su protección es el sistema de tipos. La fila existe para decir eso, no para aparentar un falsificador.

**M6 y M8 hubo que repetirlos.** Sus primeras ejecuciones devolvieron `Error 137` (OOM, con cuatro stacks
Docker levantados a la vez) y se habrían registrado como rojos que nunca corrieron. **Un exit distinto de cero
no es una falsificación hasta que se lee de qué es.** M8 necesitó además bajar un stack para tener memoria.

## Gates

Ejecuciones frescas, con su código de salida impreso:

| Gate | Exit | Resultado |
|---|---|---|
| `make php.quality` | 0 | — |
| `make php.quality.dry-run` | 0 | la variante que corre CI |
| `make php.unit` | 0 | 3323 tests / 15296 aserciones |
| `make php.behat` | 0 | 471 escenarios / 4374 pasos |

`pwa.*` no ejecutado y **no reclamado**: el cambio no toca `pwa/`.

## Registrado y NO arreglado, con su motivo

- **`service()` está duplicado ×4** (los tres tests de opacidad más otro). Es un helper de contenedor,
  ortogonal a la opacidad; meterlo en un trait llamado `ComparesOpaqueRefusals` sería incoherente, y sacarlo a
  un trait propio es un diff que nadie ha pedido. Nombrado en vez de metido de matute.
- **(3) `PwaSource` y (4) el contrato de `deleteAllForUser`** están decididos (dos clases; coherencia débil)
  pero fuera de esta rama por decisión de alcance del usuario.

## Adversarial pass

Lectura hostil en contexto fresco e independiente sobre el diff completo, **ejecutada antes del commit y antes
de abrir la PR**. **Cambió el resultado, no lo confirmó:** un GRAVE, tres SERIO y tres MENOR, todos aplicados.
El GRAVE estaba en la parte que esta PR *añadía* por iniciativa propia, que es exactamente donde una revisión
del autor no mira.

### GRAVE

**G1 — la guarda de `acceptFormat` enumeraba donde debía espejar; `'0'` la atravesaba.**
`StrictRequestPayload.php`. PHP tiene **cuatro** grafías falsy alcanzables por la firma del padre, no tres:
`'0'` es un string falsy. El tipo estrechado cerraba `null`, la guarda cerraba `[]` y `''`, y `'0'` quedaba
admitido, almacenado y leído por `RequestPayloadValueResolver:242` como false. Medido contra la clase real, el
resolver real y un DTO de producción real: con el default `['json']` un cuerpo form-encoded da
`UnsupportedMediaTypeHttpException`; con `'0'` **es aceptado y deserializado**. Peor que el hueco: los dos
docblocks que la PR añadía afirmaban *«tres grafías … ninguna sobrevive»*, y eso era falso — una afirmación de
cobertura inexistente, el defecto que esta misma PR corrige en otros sitios. `phpstan/phpstan-strict-rules` no
está instalado, así que nada forzaba la enumeración.

**Aplicado:** `if (!$acceptFormat)`, que espeja el predicado del resolver y es más corto que lo enumerado; los
dos docblocks reescritos para decir *por qué* es una prueba de veracidad y no una lista; y el caso `'0'`
añadido como data provider. Falsificado en **M8**: reponiendo la guarda enumerada falla exactamente ese caso.

### SERIO

**S2 — el cuarto caso del contract test era verde sobre la mutación que borra la semántica que su nombre
reclamaba.** Con el fixture `[DEFAULT_ID => true]` y preguntando por otro id, el primer disyuntivo
(`$anotherActiveAdminRemains`) es true y el `||` cortocircuita: `activeEntryFor()` nunca se alcanzaba. El pase
simuló la mutación y los tres casos seguían pasando mientras la propiedad que el puerto llama su contrato —
*el conjunto vacío sobrevive* — desaparecía. **Aplicado:** el caso pasa a data provider con los dos fixtures
que sí alcanzan la mitad de pertenencia (conjunto vacío y conjunto con sólo un fantasma), y se renombra a lo
que pincha. Falsificado en **M9**.

**S3 — la extracción separó una normalización de su precondición.** El trait sustituía `instance` y confiaba
en prosa para que el llamador afirmara que los originales son distintos. Cierto sólo mientras los tres
llamadores actuales comparan el cuerpo entero; un llamador futuro que compare campos sueltos se lleva la
normalización sin su guarda. **Aplicado:** `assertRefusalsAreIndistinguishable($answers, $instances)` hace las
dos mitades en un método — la comparación de identidad, el censo mínimo de dos respuestas y la unicidad de los
`instance` — así que la obligación viaja con la normalización en vez de repetirse en tres ficheros.

**S4 — `PRODUCTION_SECURITY_CHECKLIST.md:432-446` describe este control exacto y no se había tocado.** Su
verificación declarada (un grep manual de `acceptFormat` sobre `api/src`) es ciega precisamente al vector de
G1, porque un sitio que lo ensanchara sería un argumento de atributo, no la cadena `acceptFormat`. **Aplicado:**
el ítem recoge la guarda nueva y **dos residuos declarados**: que el refuse aflora como 500 en tiempo de
petición (Symfony construye el atributo por petición en `ArgumentMetadataFactory`) y que el grep no cubre esa
dirección.

### MENOR

**M-a — el encogido del docblock introdujo un error temporal.** *"A subject the set **never held**"* donde el
original decía *"does not hold"*. El método responde sobre el conjunto **actual**: un administrador antes
`ACTIVE` y ahora `SUSPENDED` sí fue miembro, no lo es, y recibe `true`. Restaurado el tiempo verbal correcto.

**M-b — el test elevado no llegó a la forma que estaba copiando.** Usaba una lista posicional y `assertCount(6)`.
Si dos de las seis formas llegaran a coincidir, el conteo sigue siendo 6, las seis respuestas siguen siendo
idénticas y el test pasa ejercitando cinco causas — y `array_unique($instances)` tampoco lo ve, porque
`instance` se acuña por **ocurrencia**, no por causa. **Aplicado:** `deadTokens()` se keyea por causa (un fallo
nombra cuál divergió) y se afirma que los seis **tokens** son distintos, que es lo que realmente cierra el
hueco — keyear por sí solo no lo cerraba, porque dos tokens idénticos bajo claves distintas siguen dando seis
entradas.

**M-c — dónde aflora el `InvalidArgumentException` no estaba dicho, y es más tarde de lo que un lector supone.**
Documentado en el `@throws`: petición, no compilación del contenedor ni CI.

### Lo que el pase buscó y NO encontró

Completitud del rename (27 ocurrencias en 10 ficheros; el nombre viejo sólo sobrevive en artefactos históricos
de `_bmad-output/`, donde es correcto); que la edición del ADR corrige referencias rancias en vez de
introducirlas (`origin/main` apuntaba a un `}` y a un comentario); que los dos tests renombrados los sigue
seleccionando el barrido por directorio; pérdida de cobertura al mover el caso de D7 (ninguna material);
colisión del UUID de frontera; legitimidad de `#[CoversClass]` sobre un double (precedente idéntico e
irrelevante para la atribución, que está limitada a `src`); necesidad de línea en `.artifact-gate-placement`
(ninguno de los dos ficheros nuevos encaja en el barrido — `make php.lint.gate-placement` exit 0); que el trait
no se recoja como test; interferencia del limitador por selector al re-POSTear el token aceptado; si la
cabecera de correlación enmascara algo en los otros tests de esa clase; si el trait debilita los dos tests
fuertes respecto de lo que afirmaban en `origin/main` (byte a byte las mismas operaciones); e higiene de
comentarios en cada línea añadida.

### Lo que el pase NO pudo verificar, y quién lo cubrió

No ejecutó los tres tests funcionales: midió que en este worktree `APP_ENV=test` resuelve a `erpify_db` —la
base de **dev**— pese a `api/.env.test`, y `InvitationAcceptFunctionalTest` abre con un `TRUNCATE … CASCADE`.
La decisión de no ejecutarlos fue correcta. Esa mitad la cubren las ejecuciones de esta sesión a través de
`make php.unit`, que usa `api/tools/phpunit/phpunit.dist.xml` y no esa resolución: los tres tests corren verdes
y M5/M6 los ponen rojos dirigidamente.
