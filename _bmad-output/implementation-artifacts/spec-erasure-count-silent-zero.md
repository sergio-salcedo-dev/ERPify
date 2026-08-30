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

Los siete adaptadores Doctrine que ejecutan una sentencia masiva terminaban igual:

```php
return \is_int($affected) ? $affected : 0;      // seis sitios
return \is_int($affected) && $affected > 0;     // consume(), séptimo
```

`Doctrine\ORM\AbstractQuery::execute()` está declarado **`mixed`** (`api/vendor/doctrine/orm/src/AbstractQuery.php:881`),
así que todo adaptador cuyo puerto promete un `int` tiene que estrechar. Ese estrechamiento no es defensa:
**inventa** el valor. Y el valor que inventa es `0`, exactamente el que sus consumidores leen como «no había
nada que borrar»:

- `EraseIdentitySubject.php:68` lee `$tokensDeleted` → `IdentityErasureResult` → `FulfilIdentityErasureResult`
  → salida de `identity:gdpr:erase-subject`.
- `SessionRepository::deleteAllForUser()` promete en su docblock que «una segunda pasada sobre un sujeto sin
  filas borra nada y devuelve 0» — de modo que un cero legítimo y un cero fabricado por el fallback de tipos
  son **indistinguibles para todo llamante**.
- La PR #878 elevó ese conteo a «esto SÍ se promete, y es sobre lo que un llamante puede actuar»
  (`PasswordResetTokenRepository`), que es lo que vuelve el fallback incoherente y no meramente inútil.

Hoy la rama es **inalcanzable**: un `DELETE` DQL devuelve el `int` del driver. Lo que cambia no es el
comportamiento de hoy; es **la dirección en la que falla** el día que esa premisa deje de valer.

## La forma elegida, y las descartadas

**Elegida (c) — guarda compartida.** `Erpify\Shared\Persistence\Infrastructure\AffectedRows::from(mixed): int`
estrecha o **lanza** `UnexpectedValueException`. Los ocho sitios (siete, más el `UPDATE` masivo cuyo conteo se
descarta) pasan a `AffectedRows::from($affected)`.

- Regla de Tres: siete copias del mismo estrechamiento, no dos.
- `Shared/Persistence/Infrastructure` es el hogar honesto: existe por el `mixed` de una ORM, y sólo los
  adaptadores lo llaman. `Erpify\Shared\…` es importable desde cualquier contexto (deptrac lo confirma:
  `Violations 0`).
- `UnexpectedValueException` y no un `DomainException`: nada en la petición del llamante está mal y no hay
  respuesta sobre la que un cliente pueda actuar. Es la categoría de
  `DbalAuditTimelineRepository::requiredString()` — «el almacén devolvió una forma que no puede ser correcta»
  — y sale como el 500 que es. Un marker interface lo metería en el contrato de error como si el cliente
  tuviera elección.
- Rechaza también un **negativo**. Es tan imposible como una cadena y llega a los mismos consumidores; la
  clase que decide qué puede contar como conteo decide las dos mitades, no sólo la que el sistema de tipos no
  cubre.

**(a) borrar el fallback y dejar que PHPStan pruebe el tipo — descartada, medida.** `execute()` es `mixed`,
así que `return $affected;` enrojece PHPStan level max. La variante `@phpstan-var int` es **la misma mentira
en forma de anotación**, y peor: sin comprobación en tiempo de ejecución.

**(b) `throw` en cada sitio — descartada.** Siete copias de la misma guarda es exactamente lo que la Regla de
Tres prohíbe, y es la forma que produjo las siete copias del defecto.

## `consume()` entra en el cambio, y por qué

Es el guardián de un solo uso del reset de contraseña, y su forma era distinta: un no-int devolvía `false`,
es decir «la fila ya no estaba», y **aborta**. Falla cerrado — pero mintiendo el motivo. A quien sostiene un
token vivo se le dice que está gastado; es un diagnóstico sobre el que no puede actuar y cuyo reintento falla
idéntico. Con la guarda **sigue abortando** (la propiedad «un token, un reset» se conserva entera), pero como
un 500 diagnosticable en vez de un diagnóstico falso. Declarado en el puerto, no sólo aquí.

## La puerta: por qué cuenta en vez de parsear

`BulkStatementNarrowingGateTest` (+ motor `BulkStatementNarrowing`) afirma, **por fichero**, que el número de
sentencias masivas DQL construidas es igual al número de estrechamientos que pasan por la guarda.

- **Prohibir `is_int(` prohíbe `is_int(`.** `is_numeric()`, un cast `(int)` y un `@phpstan-var int` llegan al
  mismo sitio equivocado. Exigir que cada sentencia esté **contabilizada** por un estrechamiento es una
  obligación positiva: no se rodea, se cumple.
- **Igualdad, no presencia.** Un método que estrecha una sentencia y fabrica el cero de una segunda pasaba un
  `str_contains` — medido. Archivar-y-borrar y bloquear-y-borrar son las formas que llegan después.
- **El universo es el DML, no la ejecución.** Nombrar `->execute(` estaba mal dos veces:
  `AbstractQuery::getResult()` es literalmente `return $this->execute(null, $hydrationMode);` y ya es la
  grafía de lectura de este repositorio, así que un octavo adaptador escrito así era invisible; y un SELECT
  ejecutado con `->execute()` quedaba obligado a alcanzar una guarda cuyo único efecto sobre un array es un
  500. El DML se reconoce por la forma que sólo el query builder tiene: `->delete(Entidad::class, …)`.
- **Nada está exento, y eso es lo que quitó el parser.** La primera versión atribuía cada sentencia a su
  método envolvente para poder exonerar a uno `void`. El `UPDATE` masivo de sesiones descarta su conteo y aun
  así lo estrecha: afirmar que una sentencia masiva devolvió un conteo es significativo aunque nadie lea el
  número, de modo que la exención no compraba nada y costaba un parser.
- **La rejilla se genera, no se filtra del árbol.** Quince formas que el árbol no contiene, con su
  clasificación afirmada una a una.

## Verificación

| Puerta | Resultado |
|---|---|
| `make php.stan` | `[OK] No errors` — exit 0 |
| `make php.unit` | 3444 tests, 17790 aserciones, 2 skipped — exit 0 |
| `make php.behat` | 472 escenarios, 4378 pasos — exit 0 |
| `make php.quality` | exit 0 (Rector converge: «Rector is done!», 0 ficheros) |
| `make php.quality.dry-run` | exit 0 — **la que corre CI**, ejecutada aparte por eso |
| `make php.lint.gate-placement` | 27 tests en tres suites (4 + 13 + 10) — exit 0 |

`pwa.quality` / `pwa.test` no se ejecutaron: esta rama no toca `pwa/`.

**Dos tiras y aflojas de herramientas, resueltos reestructurando y no suprimiendo.** Rector sube `\count()`
fuera de la condición del bucle a un `$counter`, lo que rompía el estrechamiento de offset de PHPStan level
max — verde en `php.stan`, rojo tras `php.quality`; irrelevante ahora que no hay bucles, pero medido. Y
`phpdoc_align` rellena la columna de descripción de `@throws` hasta la del vecino más largo, así que una
descripción que cabía por sí sola pasaba de 120 caracteres una vez alineada bajo un FQCN de excepción.

## Matriz de falsificación

Dieciséis mutaciones, cada aserción provocada roja **por su propia mutación**, restaurada por copia de bytes
desde una pristina (nunca `git checkout --`); `diff -rq` final contra las pristinas: idénticas.

| # | Mutación | Rojo medido |
|---|---|---|
| N1 | El guard vuelve a `\is_int($result) ? $result : 0` | 7 fallos: los 6 no-int y el negativo |
| N2 | `is_numeric()` en vez de `is_int()` | 2: cadena numérica y float |
| N3 | El guard rechaza el cero legítimo | `testKeepsTheLegitimateZero…` |
| N4 | El guard devuelve `$result + 1` | `testPassesACountThrough` + el cero |
| N5 | El guard deja de rechazar negativos | `testRaisesOnANegativeCount` |
| N6 | Replantar el fallback original en `DoctrineMembershipRepository` | la puerta: «builds 1 … narrows 0» |
| N7 | Otra grafía: cast `(int)` en Session | «builds 3 … narrows 2» |
| N8 | La grafía `->getResult()` en Membership | «builds 1 … narrows 0» |
| N9 | Presencia (`str_contains`) en vez de igualdad | `two statements, one narrowed` |
| N10 | Deja de descartar el CONTENIDO de los literales | `the guard only quoted` |
| N11 | Deja de quitar comentarios | `the guard only in a comment` |
| N12 | El DML deja de exigir `::class` | `a collaborator delete` |
| N13 | Deja de exigir que el fichero construya una `Query` | `a collaborator taking a class name` |
| N14 | La barrida se vacía (raíz inexistente) | `testTheSweepStillReaches…` |
| N15 | La barrida **ENCOGE** a un fichero | `testTheSweepStillReaches…` (lo que `assertNotEmpty` no vería) |
| N16 | El patrón DML no compila | 15 errores: **lanza**, no se vacía en silencio |

**N13 salió verde en su primera ejecución.** La comprobación de «este fichero construye una `Query`» no era
falsable: el caso que tenía (`$this->storage->delete($id)`) ya quedaba fuera por no llevar `::class`. Se
añadió `a collaborator taking a class name` — un colaborador cuyo argumento **sí** es un nombre de clase, en
un fichero sin query builder — y entonces discrimina. Se registra porque es la segunda vez en esta rama que
una guarda mía resultó decorativa hasta que su mutación lo demostró, y una matriz que sólo enumera los rojos
que salieron a la primera no es una medición.

## Lo que un verde NO prueba

- Una llamada a la guarda **muerta** — un closure sin usar, una rama inalcanzable — cuadra la aritmética sin
  estrechar nada. Ningún conteo ni barrido de texto distingue una llamada alcanzada de una escrita; la
  revisión es el único control en esa dirección.
- **Nunca juzga si el conteo es correcto**: una sentencia a la que le falta un predicado, una cuya
  transacción hace rollback después, o una que cuenta la tabla equivocada devuelven un conteo perfectamente
  bien tipado y pasan.
- Una sentencia cuyo DQL sea una **cadena** (`createQuery('DELETE FROM …')`, o `delete('Foo\Bar', 'f')` sin
  `::class`) es invisible: el contenido de los literales se descarta a propósito, y ese corte vale en las dos
  direcciones. Cero sitios así hoy.
- Un fichero que construye la sentencia y la estrecha en **otro**: los conteos son por fichero.
- `AffectedRows` importado con **alias** es un falso ROJO. Esta regla falla hacia el ruido donde su
  predecesora fallaba hacia el silencio.
- La familia **DBAL** queda fuera: `Connection::executeStatement()` devuelve `int|numeric-string` y diez
  sitios lo estrechan a mano con un cast `(int)`, `DbalKeystore::destroy()` — la lápida del crypto-shredding
  — entre ellos. El cast **convierte** en vez de fabricar, que es por qué es residual y no el mismo defecto;
  pero nada lo sujeta ahí. Registrado en `PRODUCTION_SECURITY_CHECKLIST.md`.

## Adversarial pass

Tres capas hostiles en paralelo, en contextos frescos e independientes, **antes de abrir la PR**, sobre el
commit `84e270b9`: Blind Hunter (sin spec), Edge Case Hunter (toda rama y frontera) y Acceptance Auditor (el
registro contra el árbol). Read-only explícito en los tres prompts. **Cambió el resultado, no lo confirmó:**
1 GRAVE, 8 SERIO y 8 MENOR tras deduplicar, y **el arreglo del gate se rehízo entero**.

**GRAVE · el gate estaba verde sobre el defecto exacto que existía para refutar.** El estrechamiento se
comprobaba con `str_contains`, así que un método que estrecha una sentencia y fabrica el cero de una segunda
pasaba. Medido con el escáner en el contenedor: `purge()` archivando con la guarda y borrando con
`\is_int($deleted) ? $deleted : 0` → `guarded=Y`. Es la forma más probable que llegue después
(archivar-y-borrar, y el bloquear-y-borrar que `DoctrineInvitationRepository` ya escribe). **Cerrado** por la
igualdad de conteos, y pinchado en N9.

**SERIO · `getResult()` hacía falsa la afirmación central.** `AbstractQuery::getResult()` es
`return $this->execute(null, $hydrationMode);` y **ya se usa en este repositorio** (`DoctrineSessionRepository:116`,
`DoctrineSearchEngine:338`). Un octavo adaptador escrito así era invisible al detector, que nombraba la
ejecución. No era «una grafía futura de la ORM», como decía mi docblock. **Cerrado** moviendo el universo al
DML; pinchado en N8.

**SERIO · seis defectos en mi parser escrito a mano, y el patrón es el diagnóstico.** `use function is_int;`
colapsaba una clase entera en un registro fantasma y escondía todos sus sitios reales (medido sobre el
`DoctrineSessionRepository` real, con un octavo sitio sin guardar: **gate verde**); un método llamado `list` o
`match` lexa a su propio token de palabra clave y desaparecía; `function &f()` desaparecía, y los **dos**
intentos de tratar el `&` eran código muerto (el token entre `function` y el nombre es
`T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG`, y desde 8.1 el tokenizador no emite un `'&'` suelto); un literal
`"$a}"` descuadraba las llaves; una clase anónima anidada se atribuía a su método envolvente y una función
nombrada anidada al método `void` que la rodeaba. Ninguno era una propiedad de la regla. **Cerrados por
construcción**: contar por fichero no necesita frontera de método, así que ninguna de esas formas existe para
equivocarse. El motor pasó de 278 líneas a 146 y el VO acompañante desapareció.

**SERIO · un SELECT quedaba obligado a alcanzar una guarda cuyo único efecto ahí es un 500**, y un método que
lee y llama a un `execute()` de caso de uso era falso rojo sin salida. Ambos desaparecen con el universo DML.
Un tercero — un colaborador cuyo `delete()` toma un valor — se cerró exigiendo que el DML nombre su entidad
con `::class`, que es la forma que sólo el query builder tiene (N12).

**SERIO · la anti-vacuidad era la forma débil.** `assertNotEmpty` sólo rechaza una barrida totalmente vacía;
una regresión que la encogiera de 4 ficheros a 1 dejaba verdes las dos aserciones sobre siete sentencias sin
guardar. El gate hermano nacido del mismo defecto (`ConfirmationGuardAdjacencyGateTest`, #866) usa un suelo.
**Cerrado** con suelos de 4 ficheros y 8 sentencias, y N15 lo demuestra vivo.

**SERIO · `PRODUCTION_SECURITY_CHECKLIST.md` sin tocar**, contra un precedente de tres días: #866 añadió 15
líneas por la versión de este mismo defecto en la capa de comandos. **Cerrado** con su entrada y sus
residuales.

**SERIO · la exclusión del DBAL se apoyaba en una premisa falsa.** Mi docblock decía que
`executeStatement()` «ya devuelve `int`»; devuelve `int|numeric-string`
(`api/vendor/doctrine/dbal/src/Connection.php:891`), que es justamente por qué diez sitios lo castean a mano
— `DbalKeystore::destroy()`, en la ruta de evidencia del crypto-shredding, incluido. Verificado por mí contra
el vendor. **Corregido**: la exclusión se reargumenta sobre su motivo verdadero (el cast convierte, no
fabrica) y la familia se nombra en vez de declararse inexistente.

**SERIO · cuatro puertos ganaron una excepción que ninguno declaraba.** `SessionRepository` es el caso agudo:
su docblock afirma que **toda** su superficie convierte un fallo de almacén en `SessionStoreUnavailable`.
**Corregido** en los cinco puertos, y el de `consume()` dice ahora qué significa `false` y qué no.

**MENORES atendidos**: `AffectedRows::from(-1)` devolvía `-1` como conteo de filas — ahora lanza, con su
propio caso y su mutación (N5); `testPassesACountThrough` no lo cubría ninguna mutación — ahora N4;
`assertNotEmpty` sobre un fichero ilegible pasaba a un salto silencioso — ahora lanza; un patrón que no
compila vaciaba la barrida en silencio — ahora lanza (N16), y me pasó de verdad: un `\\` colapsado por las
comillas simples dejó la clase de caracteres sin cerrar y todo el árbol salió del universo. Correcciones de
registro: «20 `->getQuery()`, todos en cadena fluida» era falso (dos aparcan la `Query` en una variable local,
aunque la conclusión — ninguna `Query` cruza una frontera de método — se sostiene, y esa frase ya no existe);
`php.lint.gate-placement` corre 27 tests en tres suites, no 10; la matriz saltaba de M3 a M5 sin decirlo, y
está renumerada N1–N16.

**Veredicto de higiene de comentarios (regla de `CLAUDE.md`)**: el Auditor argumentó los dos casos límite en
vez de afirmarlos. El comentario de `consume()` («…en vez de plegarlo en la comparación») nombra una
*alternativa de diseño* descartada con su coste, no el código anterior — legítimo. El de `AffectedRows` estaba
más cerca de la línea (pretérito sobre una grafía que ya no existe en `src`), y se ha reescrito en presente
para que sea verificable desde el árbol para siempre. Cero IDs de story o de requisito en todo el diff.

**Lo que el pase NO pudo verificar**: ninguna capa ejecutó `php.behat` (resetea la BD) ni `php.quality`
(aplica fixers), así que esas dos filas de la tabla son de esta sesión; ninguna reprodujo la matriz de
mutación (habría exigido editar ficheros, prohibido por su restricción read-only); y ninguna puede decir si
las evasiones que encontró son realistas en manos de este equipo — el propio Blind Hunter califica la de
`getResult()` como alta y las otras dos como mecánicamente reales pero sin forma motivadora en el árbol. El
Auditor dejó `api/config/reference.php` sucio al warmear la caché de prod; restaurado antes de commitear.
