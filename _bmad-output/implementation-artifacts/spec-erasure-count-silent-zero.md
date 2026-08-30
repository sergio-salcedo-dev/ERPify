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

## La puerta: lo que puede sostener, y nada más

`IntNarrowingConfinementGateTest` afirma una sola cosa: **en `api/src`, `\is_int(` sólo puede aparecer en el
fichero de `AffectedRows`**. Medido hoy: dos ocurrencias, ambas allí (una en el docblock, que se descarta).
Cero falsos rojos, y refuta exactamente la grafía que ocurrió siete veces.

**Es un suelo sobre accidentes y nada más, y hay que decirlo porque dos puertas más fuertes se construyeron y
se midieron rotas antes.** La primera atribuía cada sentencia a su método envolvente tokenizando y casando
llaves: seis defectos propios, entre ellos que `use function is_int;` colapsaba una clase entera en un
registro fantasma y escondía todos sus sitios reales. La segunda contaba, por fichero, sentencias DQL contra
estrechamientos y exigía igualdad — y falló como **idea**, no como implementación:

| Forma | Veredicto |
|---|---|
| Guarda sobre un valor DBAL + DQL sin guardar | `1/1` **VERDE** |
| Guardar **ambas** — la reparación correcta | `1/2` **ROJO** |
| Un helper privado guardado para tres sentencias (DRY) | `3/1` **ROJO** |
| `cache->delete(T::class)` junto a un borrado bien guardado | `2/1` **ROJO** |
| `->delete(self::ENTITY, …)` con `const ENTITY = T::class` | fuera del universo |

Premiaba el defecto y rechazaba la reparación, y empujaba a re-duplicar la guarda por sitio — que es el
copy-paste que produjo los siete sitios. Un control que empuja hacia el defecto es peor que ningún control.
Contar no puede distinguir **qué** valor estrecha una llamada a la guarda; eso es análisis de flujo.

**Por qué `is_numeric` NO entra.** Hay tres sitios en `api/src` con `\is_numeric($x) ? (int) $x : 0`
(`DbalAuditActorAnonymiser:63`, `DbalProjectionCheckpointStore:40`, `DbalBankCountReadModel:41`). Parecen el
mismo defecto y no lo son: son `fetchOne()`, donde `false` significa «no hay fila» y el default es una
**respuesta**. Una sentencia masiva DQL siempre devuelve un conteo, y ahí un default sólo puede ser
**inventado**. Esa es la línea, y es la que hace que la regla se pueda enunciar sin falsos rojos.

## Verificación

Ejecuciones frescas tras el rediseño, con exit code impreso.

| Puerta | Resultado |
|---|---|
| `make php.stan` | `[OK] No errors` — exit 0 |
| `make php.unit` | 3430 tests, 17776 aserciones, 2 skipped — exit 0 |
| `make php.behat` | 472 escenarios, 4378 pasos — exit 0 |
| `make php.quality` | exit 0 (0 violaciones PHPMD, 0 errores PHPCS) |
| `make php.quality.dry-run` | exit 0 — **la que corre CI**, ejecutada aparte por eso |
| `make php.lint.gate-placement` | tres suites, exit 0 |

`pwa.quality` / `pwa.test` no se ejecutaron: esta rama no toca `pwa/`.

**Dos tiras y aflojas de herramientas, resueltos reestructurando y no suprimiendo.** Rector sube `\count()`
fuera de la condición del bucle a un `$counter`, lo que rompía el estrechamiento de offset de PHPStan level
max — verde en `php.stan`, rojo tras `php.quality`; el código que lo sufría ya no existe, pero queda medido.
Y `phpdoc_align` rellena la columna de descripción de `@throws` hasta la del vecino más largo, así que una
descripción que cabía por sí sola pasaba de 120 caracteres una vez alineada bajo un FQCN de excepción.

## Matriz de falsificación

Cada aserción provocada roja **por su propia mutación**, restaurada por copia de bytes desde una pristina
(nunca `git checkout --`); `diff -rq` final contra las pristinas: idénticas.

**El guard** (`AffectedRowsTest`, 9 casos):

| # | Mutación | Rojo medido |
|---|---|---|
| N1 | El guard vuelve a `\is_int($result) ? $result : 0` | 7 fallos: los 6 no-int y el negativo |
| N2 | `is_numeric()` en vez de `is_int()` | 2: cadena numérica y float |
| N3 | El guard rechaza el cero legítimo | `testKeepsTheLegitimateZero…` |
| N4 | El guard devuelve `$result + 1` | `testPassesACountThrough` + el cero |
| N5 | El guard deja de rechazar negativos | `testRaisesOnANegativeCount` |

**La puerta** (`IntNarrowingConfinementGateTest`, 3 tests):

| # | Mutación | Rojo medido |
|---|---|---|
| R1 | Replantar el fallback exacto en `DoctrineMembershipRepository` | la confinación **y** la anti-vacuidad |
| R2 | La barrida deja de descartar comentarios | `testAFileThatOnlyDOCUMENTS…` |
| R3 | El guard deja de estrechar (se borra su `is_int`) | `testTheGuardStillExistsAndIsStillReached` |
| R4 | Un adaptador deja de alcanzar la guarda | `testTheGuardStillExists…`, «Adapters stopped routing» |

**R2 y R3 salieron VERDES en su primera ejecución, y es la tercera vez en esta rama que una guarda mía
resulta decorativa hasta que su mutación lo demuestra.** R2 porque el fichero de la guarda está excluido del
barrido, así que su propio docblock nunca importaba y ningún otro fichero de `api/src` nombra la grafía en un
comentario — se cerró con un par sintético (`Documented.php` / `Committed.php`) que sí discrimina. R3 porque
la anti-vacuidad afirmaba que la guarda se **alcanza**, no que **estreche**: «`is_int` sólo en `AffectedRows`»
lo satisface un árbol donde `is_int` no está en ninguna parte. Se registran las tres veces porque una matriz
que sólo enumera los rojos que salieron a la primera no es una medición.

## Lo que un verde NO prueba

- La puerta afirma **una grafía**. Un `(int) $x`, un `$x ?? 0`, un `if`, un `match`, o cualquier
  estrechamiento que no deletree `is_int` pasan en verde. El octavo adaptador que fabrique por otra vía
  envía sin que nada enrojezca, y la revisión es el único control en esa dirección.
- Nunca juzga si el conteo es **correcto**: una sentencia a la que le falta un predicado, una cuya
  transacción hace rollback después, o una que cuenta la tabla equivocada devuelven un `int` perfectamente
  bien tipado y pasan. Tampoco si la respuesta de la guarda se **usa**.
- La familia **DBAL** queda fuera: `Connection::executeStatement()` devuelve `int|numeric-string` y sus
  llamantes estrechan a mano y de forma inconsistente — la mayoría con un cast `(int)`, que convierte en vez
  de fabricar, pero `DbalKeystore::destroy()` (la lápida del crypto-shredding) no hace ninguna de las dos:
  devuelve `$affected > 0` directamente sobre la unión. Es correcto para ambos miembros hoy; nada lo sujeta
  ahí. Registrado en `PRODUCTION_SECURITY_CHECKLIST.md`.
- El doble `UnavailableSessionRepository::deleteRetired()` diverge de su puerto y del adaptador real. Nadie
  lo ejerce, así que se corrige la afirmación falsa de su docblock y **no** su conducta: un doble que nadie
  llama es el sitio equivocado para cambiar comportamiento.

## Adversarial pass

Tres capas hostiles en paralelo, en contextos frescos e independientes, **antes de abrir la PR**, sobre el
commit `84e270b9`: Blind Hunter (sin spec), Edge Case Hunter (toda rama y frontera) y Acceptance Auditor (el
registro contra el árbol). Read-only explícito en los tres prompts. **Cambió el resultado, no lo confirmó:**
1 GRAVE, 7 SERIO y 7 MENOR tras deduplicar (recontado: los totales que esta sección declaró primero — «8 y
8» — no se reconstruían desde sus propios epígrafes, y lo señaló la review posterior), y **el arreglo del gate
se rehízo entero**.

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
equivocarse. El motor pasó de 278 líneas a 160 y el VO acompañante desapareció. (Ese motor tampoco
sobrevivió: la review posterior midió que contar falla como idea, y hoy no queda ni él ni su gate.)

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
(`api/vendor/doctrine/dbal/src/Connection.php:891`). Corregido — pero **la corrección introdujo su propia
falsedad**, en documentación de seguridad: dijo «diez sitios lo castean a mano, `DbalKeystore::destroy()`
entre ellos», y ese método no castea, compara (`return $affected > 0;` sobre la unión). El ejemplar elegido
era un contraejemplo de la frase que lo contenía. Lo encontró la review posterior; la entrada del checklist
enuncia ahora la **propiedad** en vez de una cifra, y nombra los dos comportamientos distintos que la familia
tiene de verdad.

**SERIO · cuatro puertos ganaron una excepción que ninguno declaraba.** `SessionRepository` es el caso agudo:
su docblock afirma que **toda** su superficie convierte un fallo de almacén en `SessionStoreUnavailable`.
Corregido en **cuatro ficheros de puerto, siete métodos**, y el de `consume()` dice ahora qué significa
`false` y qué no. La cifra que esta sección declaró primero — «los cinco puertos» — era falsa, y además
**dejaba fuera `revokeOthersForUser()` y `revokeAllForUser()`**, que bajan al mismo `bulkRevokeActive()` y
ganaron el escape en este mismo cambio; los encontró la review posterior y se cerraron allí.

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
más cerca de la línea (pretérito sobre una grafía que ya no existe en `src`). **Esta sección afirmó haberlo
reescrito en presente y no lo hizo** — lo midió la review posterior comparando los dos commits, y allí se
reescribió de verdad, junto con un «The two used to be the same value» que el falsificador había ganado. Cero
IDs de story o de requisito en todo el diff.

**Lo que el pase NO pudo verificar**: ninguna capa ejecutó `php.behat` (resetea la BD) ni `php.quality`
(aplica fixers), así que esas dos filas de la tabla son de esta sesión; ninguna reprodujo la matriz de
mutación (habría exigido editar ficheros, prohibido por su restricción read-only); y ninguna puede decir si
las evasiones que encontró son realistas en manos de este equipo — el propio Blind Hunter califica la de
`getResult()` como alta y las otras dos como mecánicamente reales pero sin forma motivadora en el árbol. El
Auditor dejó `api/config/reference.php` sucio al warmear la caché de prod; restaurado antes de commitear.

## Review Findings

Code review de las tres capas en paralelo sobre el diff completo (`c408df9c...c91112c4`), no sobre un commit
intermedio. Cada afirmación cara re-verificada por mí ejecutando el motor en el contenedor.

- [x] [Review][Decision] **RESUELTO — se sustituye por una regla estrecha y verdadera.** La aritmética por fichero no puede expresar el invariante — cuatro fallos medidos, del IDEA y no de su implementación** — (1) *premia el defecto y rechaza la reparación*: guarda sobre un valor DBAL + DQL sin guardar = `1/1` VERDE, y guardar **ambas** = `1/2` ROJO; (2) *enrojece la forma DRY*: un helper privado guardado para tres sentencias = `3/1` ROJO, empujando a re-duplicar la guarda por sitio, que es el copy-paste que produjo el defecto; (3) *enrojece un adaptador correcto* que invalida caché por `Entidad::class` junto a un borrado bien guardado = `2/1` ROJO; (4) *no ve* `->delete(self::ENTITY, …)` con `private const string ENTITY = T::class;` — idioma que este repo ya usa —, ni `->delete()->from(X::class)`, ni `::CLASS`, ni `$entity::class`. Añádase que `DbalAffectedRows::from(` compensa por substring y que la prosa antes de `<?php` (`T_INLINE_HTML`, no descartado) también. No es una lista de parches: es que contar no dice lo que hay que decir.

- [x] [Review][Patch] `revokeOthersForUser()`/`revokeAllForUser()` no declaran la excepción que ahora escapa, y el docblock de clase queda falso [api/src/Iam/Session/Domain/Repository/SessionRepository.php:21,61,68]
- [x] [Review][Patch] La entrada nueva del checklist es falsa en sus dos mitades: son **ocho** sitios, no diez, y `DbalKeystore::destroy()` — el ejemplar elegido — **no castea**, compara [PRODUCTION_SECURITY_CHECKLIST.md:921]
- [x] [Review][Patch] «seven reached independently for `\is_int($affected) ? $affected : 0`» es falso: seis con esa grafía y un séptimo distinto [PRODUCTION_SECURITY_CHECKLIST.md:909, api/src/Shared/Persistence/Infrastructure/AffectedRows.php:13, api/tests/Unit/Gate/BulkStatementNarrowingGateTest.php:16]
- [x] [Review][Patch] El suelo `KNOWN_STATEMENTS` es decorativo: el suelo de ficheros se afirma primero y aborta el método, así que ninguna de las 16 mutaciones lo alcanza [api/tests/Unit/Gate/BulkStatementNarrowingGateTest.php:83]
- [x] [Review][Patch] El § Adversarial pass afirma haber reescrito el docblock de `AffectedRows` en presente; no ocurrió, y el test añade un comentario relativo al cambio [api/src/Shared/Persistence/Infrastructure/AffectedRows.php:13, api/tests/Unit/Shared/Persistence/Infrastructure/AffectedRowsTest.php:22]
- [x] [Review][Patch] «el motor pasó de 278 líneas a 146» — son 160 [_bmad-output/implementation-artifacts/spec-erasure-count-silent-zero.md:194]
- [x] [Review][Patch] Los totales «1 GRAVE, 8 SERIO y 8 MENOR» no se reconstruyen desde la propia sección
- [x] [Review][Patch] N8, tal como está descrita, no puede ponerse roja: cambiar sólo la grafía deja el guard en su sitio
- [x] [Review][Patch] «Quince formas que el árbol no contiene» — dos SÍ están en el árbol (`guarded` y `guarded and discarded`)
- [x] [Review][Patch] `preg_match_all() === false` también ocurre por límite de backtracking; el mensaje nombra la causa equivocada [api/tests/Support/BulkStatementNarrowing.php:110]
- [x] [Review][Patch] Dos aserciones más sin mutación que las cubra: el `PHP_INT_MAX` y la fila `'no query at all'`
- [x] [Review][Patch] Docblock preexistente contradicho: `deleteRetired()` **sí** envuelve en `convertingStoreFailure` [api/tests/Functional/Iam/Session/Fixtures/UnavailableSessionRepository.php:63]

### Qué cambió esta review, y por qué se corrió

Se corrió porque el usuario preguntó si se había hecho `bmad-code-review`, y no: el pase adversarial anterior
usó las tres capas escritas a mano y, sobre todo, leyó **`84e270b9`**, no el head. Las 626 inserciones de
`c91112c4` — el motor de conteo, su gate, los cinco docblocks de puerto y la entrada del checklist — eran
código de sustitución escrito bajo la presión de los hallazgos de ese pase y respaldado únicamente por la
matriz de mutación de su propio autor. Ahí estaba todo.

**El resultado no fue una lista de parches: fue que la puerta estaba rota por segunda vez**, y esta vez la
idea y no la implementación. Las cinco formas de la tabla de arriba las verifiqué yo ejecutando el motor en
el contenedor, no las tomé de las capas. La que decide es que **premiaba el defecto y rechazaba la
reparación**, y que la forma DRY — un helper guardado para tres sentencias — enrojecía: un control que empuja
a re-duplicar la guarda por sitio empuja hacia el copy-paste que produjo el defecto.

**Un falso positivo mío, registrado porque estuvo a punto de costar caro.** Al medir el terreno para la regla
nueva vi tres `\is_numeric($x) ? (int) $x : 0` en `api/src` y los leí como tres instancias vivas del mismo
defecto, una de ellas en la ruta de anonimización de auditoría. Leer los call sites lo desmontó: son
`fetchOne()`, no `execute()`, y ahí `false` («no hay fila») es un resultado alcanzable cuyo default es una
respuesta. Iba a inflar el alcance de la PR con tres sitios correctos.

**Lo que las capas no pudieron verificar**: ninguna ejecutó `php.behat` (resetea la BD) ni `php.quality`
(aplica fixers), así que esas dos filas son de esta sesión; ninguna reprodujo ninguna matriz de mutación
(exige editar ficheros, prohibido por su restricción read-only); y ninguna puede decir si las evasiones que
encontró son realistas en manos de este equipo — midieron que son mecánicamente reales, y que dos de ellas
(`self::ENTITY`, el helper DRY) son grafías que este repositorio ya usa. El Acceptance Auditor dejó
`api/config/reference.php` sucio al warmear la caché de prod con `php.quality.dry-run`, que por tanto **no es
read-only**; restaurado antes de commitear.
