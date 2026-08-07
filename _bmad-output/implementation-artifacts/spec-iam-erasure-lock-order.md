---
title: 'Dos ABBA cruzados cuyo único disidente es la cadena de borrado: hacer que el borrado se conforme, y medir la posición'
type: 'bugfix'
created: '2026-08-07'
status: 'in-review'
baseline_commit: '5f7d853f9bb13dc41bc1366fc4ab910b7acee54b'
review_loop_iteration: 0
context:
  - '{project-root}/api/.person-reference-policy'
  - '{project-root}/docs/adr/regulatory-audit-trail.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** El grafo de bloqueos de `Iam` tiene **dos ciclos ABBA cruzados e independientes**, y en los dos el único camino que va a contramano es la cadena de borrado GDPR.

- **ABBA #1** — `AcceptInvitation` (`:64` invitación, `:86` identidad, con el KDF *dentro* del lock de invitación) y `RevokeInvitation` (`:94`/`:62` invitación, `:139` identidad) toman `iam_invitation → identity_user`. `FulfilIdentityErasure` toma `identity_user` (`:143`, vía `EraseIdentitySubject`) **antes** que `iam_invitation` (`:234`, vía `purgeReferences()`).
- **ABBA #2** — `RequestPasswordReset` (`:82` identidad, `:86` token) y `CompletePasswordReset` (`:98` identidad, `:101` token) toman `identity_user → identity_password_reset_token`. `EraseIdentitySubject` toma `identity_password_reset_token` (`:53`) **antes** que `identity_user` (`:59`).
- Además, un ciclo **intra-tabla**: `findSentByInvitedUserForUpdate` (`:56-70`) bloquea un CONJUNTO de filas de `iam_invitation` sin `ORDER BY`, así que dos planes distintos las recorren en órdenes distintos — el mismo defecto que `b57c68fc` cerró en `identity_user`.

Ningún test se pone rojo en **ninguna** de las dos direcciones. `FulfilIdentityErasureTest:186-216` («…y nada es tocado») afirma `removeCalled`, anonimizadores, sesiones y auditoría — y **no** afirma invitaciones ni membresías. Un `php.unit` y un `php.behat` verdes son ausencia de evidencia sobre este cambio, no evidencia.

**Approach:** El borrado **se conforma; nunca define**. La regla no es una estimación de probabilidad: *el orden de un par compartido lo fija el camino con menos grados de libertad*. Accept llega con un token y solo aprende la identidad tras leer la invitación que ya bloqueó — cero libertad; los caminos de reset los conduce la identidad; `FulfilIdentityErasure` tiene el id del sujeto **antes** de abrir la transacción — libertad total. Orden resultante, y ya total sobre estas tres tablas: **`iam_invitation → identity_user → identity_password_reset_token`**. Se enforcea con un test de **POSICIÓN** (unidad, sobre el código real) y con una **sonda funcional** en segunda conexión que mide los adaptadores Doctrine reales.

## Boundaries & Constraints

**Always:**

- **La purga de invitaciones va DESPUÉS del rechazo de administrador (`FulfilIdentityErasure:133-135`) y ANTES de `AuditResource::of(...)` (`:141`).** Nunca en posición 1.
- **El borrado de la identidad NO se mueve.** `$identity` se consume en `:151` (`erasedAnything()` condiciona la entrada de cumplimiento), `:156` (`resetTokensDeleted` en el metadata) y `:163` (`anonymiseBusinessLog`). `purgeReferences()` baja a `array{int,int}` — firma privada, sin superficie pública.
- **Cada regla nueva se provoca ROJA antes de aceptarse, contando los rojos.** Restaurar copiando bytes de un backup en `tmp/` — **nunca** `git checkout --`.
- **Ninguna siembra vacua.** La sonda funcional afirma primero cuántas filas insertó; un `DELETE` que casa cero filas no toma ningún lock y la sonda pasaría sin medir nada (el defecto GRAVE de #618).
- El pase adversarial se ejecuta y **se escribe en este artefacto ANTES de `gh pr create`**. Sin drafts.
- El `transactional()` anidado de `EraseIdentitySubject:52` es un SAVEPOINT real bajo DBAL 4.4.4; `RELEASE SAVEPOINT` no libera locks de fila, así que no cambia nada del orden. No se toca aquí.

**Ask First:**

- Si cerrar ABBA #2 exigiera cambiar la forma pública de `IdentityErasureResult`.
- Si la sonda funcional resultara irrealizable con el aparato existente (`ObservesRowLocksOnASecondConnection`) y pidiera concurrencia real.
- Si apareciera un **tercer** ciclo con `iam_session`, `organization_membership` o `audit_log`.

**Never:**

- **No mover `iam_session` ni `organization_membership`**: no hay ciclo medido en ninguno.
- **No declarar un orden total sobre los siete recursos**: solo dos pares cierran ciclo, y nada puede enforcear un orden total (ni deptrac ni PHPStan ven orden de sentencias).
- Nada de locks consultivos ni orquestación de reintentos.
- **El TOCTOU de ADMIN queda fuera** (`:133` lee vía `holdsAdministratorRole()`, que `DoctrineActiveAdministratorDirectory:84-90` documenta como «sin predicado de estado y sin lock»). Issue aparte, aún por abrir.
- `40P01 → 503 transient-transaction-failure` es defensa en profundidad, **jamás** justificación para conservar una inversión conocida — y ni siquiera es observable: la línea de log por error no recorre la cadena `previous`, así que un operador no distingue `40P01` de `40001`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Borrado de un sujeto con invitaciones y tokens | identidad viva, ≥1 invitación, ≥1 token | Secuencia de locks `iam_invitation`, `identity_user`, `identity_password_reset_token`; contadores del resultado sin cambio | N/A |
| Borrado de un sujeto sin identidad viva | 0 filas en `identity_user`, ≥1 invitación | La purga de invitaciones corre igual; `identityErased=false`, `erasedAnything()=true` | N/A |
| Sujeto administrador | identidad con `ADMIN` | `AdministratorErasureRequiresDemotion` **antes** de tocar invitaciones: `deleteAllForInvitedUser` no se llama | 409 |
| Revocación multi-invitación | ≥2 invitaciones `SENT` del mismo invitado | Las filas se bloquean en orden de `id` ascendente | N/A |
| Carrera erasure ↔ accept | conexión externa reteniendo la invitación del sujeto | El borrado espera; con el orden viejo sería `40P01` | 503 solo si hay fallo transitorio real |

</frozen-after-approval>

## Code Map

- `api/src/Iam/Identity/Application/FulfilIdentityErasure.php` -- orquestador; `:131-185` la transacción, `:168` `purgeReferences()`, `:229-236` su cuerpo.
- `api/src/Iam/Identity/Application/EraseIdentitySubject.php` -- `:52-63`, las dos sentencias a invertir.
- `api/src/Iam/Invitation/Infrastructure/Persistence/Doctrine/DoctrineInvitationRepository.php` -- `:56-70` el `SELECT … FOR UPDATE` sin orden.
- `api/tests/Unit/Iam/Identity/Application/AdministratorSetLockOrderTest.php` -- el patrón de posición a imitar (`:60-62`, `:102-107`).
- `api/tests/Unit/Iam/Identity/Application/InMemoryUserRepository.php`, `.../InMemoryPasswordResetTokenRepository.php`, `api/tests/Unit/Iam/Invitation/Application/InMemoryInvitationRepository.php` -- los tres dobles que deben registrar la posición.
- `api/tests/Functional/Shared/Audit/ObservesRowLocksOnASecondConnection.php` -- aparato de sonda ya existente (`isLocked()` con `FOR UPDATE NOWAIT` → `55P03`, semilla comiteada, limpieza en `tearDown`).
- `api/tests/Unit/Iam/Identity/Application/FulfilIdentityErasureTest.php` -- `:186-216`, el test ciego a ampliar.

## Tasks & Acceptance

**Execution:**

- [x] `api/src/Iam/Invitation/Infrastructure/Persistence/Doctrine/DoctrineInvitationRepository.php` -- añadir `->orderBy('i.id', 'ASC')` a `findSentByInvitedUserForUpdate` -- cierra el ciclo intra-tabla; ninguna otra ruta lo detecta.
- [x] `api/tests/Unit/Shared/Persistence/Double/LockOrderJournal.php` (nuevo) -- diario compartido al que cada doble anota el nombre de la TABLA que bloquea -- el invariante es una secuencia; tres dobles lo necesitan, así que la Regla de Tres está satisfecha.
- [x] `api/tests/Unit/Iam/Identity/Application/InMemoryUserRepository.php` -- anotar al diario en `findByIdForUpdate()` y en `remove()` (ambos toman lock de fila; `findById()` no) -- aditivo, sin romper los tests que ya usan el doble.
- [x] `api/tests/Unit/Iam/Invitation/Application/InMemoryInvitationRepository.php` -- anotar en `deleteAllForInvitedUser()` y registrar sus llamadas -- hoy no registra ninguna de las dos cosas.
- [x] `api/tests/Unit/Iam/Identity/Application/InMemoryPasswordResetTokenRepository.php` -- anotar en `deleteAllForUser()`.
- [x] `api/tests/Unit/Iam/Identity/Application/ErasureLockOrderTest.php` (nuevo) -- **ROJO PRIMERO** contra el orden actual: afirmar la secuencia completa del diario -- sin esto la PR no prueba nada.
- [x] `api/src/Iam/Identity/Application/EraseIdentitySubject.php` -- invertir `:53` y `:59`: leer/borrar la identidad **antes** de `deleteAllForUser` -- cierra ABBA #2.
- [x] `api/src/Iam/Identity/Application/FulfilIdentityErasure.php` -- mover la purga de invitaciones entre `:135` y `:141`; `purgeReferences()` baja a dos elementos -- cierra ABBA #1. Barrer de paso el comentario relativo-al-cambio de `:34-36` («Until now these two had none…»).
- [x] `api/src/Iam/Invitation/Application/RevokeInvitation.php` -- reescribir el párrafo `:127-132`, que hoy argumenta «elige qué par puede deadlockear» -- ese argumento pide un número que nadie ha medido y ya no describe el código.
- [x] `api/tests/Unit/Iam/Identity/Application/FulfilIdentityErasureTest.php` -- añadir invitaciones y membresías al test de rechazo de administrador (`:186-216`) -- hoy es ciego a los dos enlaces que cruzan contexto.
- [x] `api/tests/Unit/Organization/Membership/Application/InMemoryMembershipRepository.php` -- registrar `deleteAllForUser` -- no estaba en el plan: hacía falta para que «nada es tocado» pudiera afirmar también la membresía, no solo las invitaciones.
- [x] `api/tests/Functional/Iam/Identity/ProbingUserRepository.php` (nuevo) -- decorador del adaptador real con un gancho en el instante del lock de `identity_user`.
- [x] `api/tests/Functional/Iam/Identity/ErasureLockOrderFunctionalTest.php` (nuevo) -- sonda en segunda conexión: en el instante del lock de `identity_user`, la fila de `iam_invitation` del sujeto ya debe estar bloqueada; en el instante del `DELETE` de `identity_password_reset_token`, la de `identity_user` también -- es lo único que prueba que los adaptadores REALES toman los locks que los dobles fingen.

**Acceptance Criteria:**

- Dado el orden actual, cuando se ejecuta `ErasureLockOrderTest`, entonces falla **antes** de aplicar los cambios de producción, y el número de rojos se registra.
- Dado un `revokeForInvitedUser` con dos invitaciones `SENT`, cuando se inspecciona el DQL emitido, entonces lleva `ORDER BY … ASC` delante del modo de bloqueo.
- Dado un sujeto administrador, cuando se pide su borrado, entonces `deleteAllForInvitedUser` no se ha llamado y se lanza `AdministratorErasureRequiresDemotion`.
- Dados `make php.unit`, `make php.behat`, `make php.stan` y `make php.quality`, cuando se corren tras el cambio, entonces cada uno sale 0 con su código impreso.

## Design Notes

**Dos correcciones al handoff, ninguna cambia la decisión.**

1. *«Ponerla en posición 1 borraría las invitaciones de un administrador que el borrado luego RECHAZA»* — **falso en producción**: el rechazo se lanza dentro de `transactional()`, y `DoctrineTransactionManager` delega en `wrapInTransaction`, que hace rollback ante cualquier throwable. Los motivos reales para mantenerla tras la guarda son otros dos, y bastan: (a) tomaría locks de escritura sobre `iam_invitation` en una transacción condenada, bloqueando exactamente al par que esta PR existe para proteger; (b) `InlineTransactionManager` **no** hace rollback, así que un test unitario sí observaría el borrado — y el test que dice «nada es tocado» no mira invitaciones.

2. *«Dos tests funcionales de dos conexiones que fuercen el ciclo viejo → `40P01`»* — **irrealizable aquí**: el contenedor no tiene `pcntl` (sin `fork`) ni la extensión `pgsql` procedural (sin `pg_send_query`), así que no hay dos transacciones corriendo a la vez dentro de un proceso PHPUnit. `ObservesRowLocksOnASecondConnection` ya lo dice de su propio invariante: «una que retiene y otra que sondea» no observa un orden de adquisición. Lo que **sí** es determinista y mide el orden real: sondear en el instante del segundo lock. El invariante «A bloquea la invitación antes que la identidad» es exactamente «cuando A toma el lock de identidad, A ya retiene el de invitación», y eso se pregunta desde fuera con `FOR UPDATE NOWAIT` → `55P03`. Decora el puerto (`UserRepository`, `PasswordResetTokenRepository`) para colgar la sonda del instante exacto; el `DELETE` no comiteado de A retiene el lock, así que la respuesta es concluyente en las dos direcciones.

**Por qué dos instrumentos y no uno.** El test de posición es rápido, corre en cada `php.unit` y se pone rojo si alguien reordena el *caso de uso*; es ciego a que un adaptador deje de bloquear. La sonda funcional prueba lo segundo y no lo primero. La misma pareja que el repo ya tiene para el conjunto de administradores (`AdministratorSetLockOrderTest` + `DoctrineActiveAdministratorDirectoryTest`).

**Por qué una posición y no una llamada.** Un lock tomado *después* del otro no ordena nada que no haya ocurrido ya, así que un test que solo comprueba «se llamó a purgar» pasa sobre la única disposición que la operación existe para prohibir.

**Mapeo independiente del grafo (subagente read-only, 72 lecturas).** Confirma los dos ciclos y añade cuatro hechos, tres de ellos verificados de nuevo a mano:

- `ResendInvitation:54` usa `findById` (sin modo de bloqueo) y **nunca** guarda el `User` — no es participante de ningún par. Contar sus símbolos fue el error de una ronda anterior: un grep de nombres no es un grafo de locks.
- `SendInvitation` toca las dos tablas pero **inserta filas nuevas**: un `INSERT` no disputa una fila que otro retenga.
- **No hay tercer ciclo.** `iam_invitation` y `identity_password_reset_token` no se bloquean juntas en ninguna transacción salvo la del borrado, así que no existe una tercera afirmación de orden que pudiera contradecir a esta. `iam_session`, `membership` y `audit_log` no son pata de ningún ciclo entre las tres tablas.
- El subagente afirmó que el `transactional()` anidado **no** emite un `SAVEPOINT` (dedujo `false` de una clave de configuración ausente). Es **falso**: `api/vendor/doctrine/dbal/src/Connection.php:1005-1012` (DBAL 4.4.4) lanza si se intenta desactivar. Anidar con savepoints es obligatorio. Irrelevante para el orden — los locks viven hasta el commit EXTERNO en cualquiera de las dos lecturas — pero la afirmación no debe propagarse.

## Verification

**Commands:**

- `make php.unit c='--filter ErasureLockOrderTest'` -- rojo antes del cambio de producción, verde después; ambos con su exit code impreso.
- `make php.unit c='--filter FulfilIdentityErasure'` -- verde; verificar con `--list-tests` que el filtro casa las cuatro clases y no un subconjunto.
- `make php.unit` -- 2416 tests o más, verde.
- `make php.behat` -- 410 escenarios, verde; leer el **exit code**, no el resumen.
- `make php.stan` -- 0 errores sobre los ficheros tocados.
- `make php.quality` -- verde, sweep completo.

**Manual checks (if no CLI):**

- Falsificación registrada: por cada regla nueva, restaurar el orden viejo copiando bytes desde `tmp/`, correr el gate, anotar **cuántos** tests se ponen rojos, y restaurar. Un solo rojo por regla es sospechoso; cero es la regla que no mide nada.

### Falsificación — re-medida contra el suite COMPLETO

Base intacta (`git stash` sobre `5f7d853f`): **2424** tests, exit 0, con 2 *notices* y 2 *skipped* preexistentes. El «2416» del handoff estaba rancio. Los cambios suman **4** tests.

La primera tabla que escribí infra-contaba, y el pase adversarial lo detectó: la había medido **antes de que existiera la sonda funcional**, y `make php.unit` corre un único testsuite sobre `tests/` — unitarios y funcionales juntos. Re-medida entera con `tmp/falsify.py`, una mutación cada vez, restaurando los bytes desde una copia en memoria (nunca `git checkout --`, que se llevaría por delante el resto del árbol sin commitear):

| Mutación | Rojos | Quién |
|---|---|---|
| ABBA #2 revertido: token borrado antes que la identidad | **2** | `ErasureLockOrderTest` + `ErasureLockOrderFunctionalTest` |
| ABBA #1 revertido: purga de vuelta dentro de `purgeReferences()` | **2** | los mismos dos |
| Purga a posición 1, delante del rechazo de administrador | **2** | `ErasureLockOrderTest::anAdministratorIsRefused…` + `FulfilIdentityErasureTest::…NothingIsTouched` |
| `ORDER BY id` fuera de `findSentByInvitedUserForUpdate` | **1** | `DoctrineInvitationRepositoryTest::theRevocableSetComesBack…` |
| `flush()` fuera de `DoctrineUserRepository::remove()` | **2** | `DoctrineUserRepositoryTest::testRemoveHardDeletes…` (preexistente) + `ErasureLockOrderFunctionalTest` |

Ninguna mutación queda verde, y el script lo afirma en vez de confiarlo: si alguna saliera con exit 0, imprime `THE MUTATION DID NOT GO RED` y termina en 1.

Dos lecturas honestas de esta tabla:

- **Cero tests preexistentes cazan las cuatro primeras mutaciones.** Esa era la afirmación central del handoff y queda medida, no supuesta: el suite era ciego a los dos órdenes en las dos direcciones.
- **La quinta sí tiene un testigo preexistente.** `DoctrineUserRepositoryTest::testRemoveHardDeletesTheAggregate` se pone rojo porque la fila no se borra, no por el orden. Así que el agujero que describió el pase adversarial es **más estrecho** de lo que él afirmaba — esa mutación concreta nunca fue invisible. La tercera pregunta de la sonda sigue ganándose el sitio porque mide la *adquisición*, no el borrado, pero el argumento «los dos tests seguirían verdes» solo vale para un cambio que difiera el DELETE sin romper aquel test.

### Resultados de las puertas (exit code impreso en cada corrida)

| Puerta | Exit | Resultado |
|---|---|---|
| `make php.stan` | 0 | `[OK] No errors` (1256 ficheros) |
| `make php.unit` | 0 | 2428 tests, 10003 aserciones |
| `make php.behat` | 0 | 410 escenarios, 3802 pasos |
| `make php.quality` | 0 | PHPMD 0 violaciones, deptrac 0, ECS 0 ficheros arreglados |
| `make php.quality.dry-run` (paridad CI) | 0 | 0 violaciones |

Dos violaciones de PHPMD aparecieron y se arreglaron **de verdad**, sin supresión: `execute()` llegó a 70 líneas (el comentario largo se movió al docblock de clase, donde ya vivía el argumento) y `useCase()` tenía un argumento booleano de bandera (pasa el mapa de administradores, como su hermano `FulfilIdentityErasureTest`).

### Hechos verificados contra la fuente, no heredados

- Tabla física: **`identity_password_reset_token`** (`pg_tables` en la base viva), no `password_reset_token`.
- El esquema tiene **exactamente dos** claves ajenas (`bank_account.bank_id`, `membership.organization_id`) y **ninguna** hacia `identity_user` — por eso borrar la identidad antes que sus tokens no viola ninguna restricción.
- `Connection::setNestTransactionsWithSavepoints(false)` **lanza** en DBAL 4.4.4: anidar con savepoints es obligatorio.
- El contenedor no tiene `pcntl` ni la extensión `pgsql` procedural (`make php.check.modules`): sin `fork` y sin consulta asíncrona, dos transacciones no pueden correr a la vez dentro de un proceso PHPUnit. De ahí la sonda por instante en vez de por carrera.

---

## Pase adversarial — ejecutado ANTES de `gh pr create`

Dos lecturas hostiles en contexto fresco, en paralelo, sobre el worktree (no sobre el primario) y en modo solo-lectura: **Blind Hunter** (`bmad-review-adversarial-general`, 46 llamadas de herramienta) y **Edge Case Hunter** (`bmad-review-edge-case-hunter`, 35). Convergieron de forma independiente en dos hallazgos, que es la señal fuerte.

Ninguno encontró un GRAVE. Las dos clases de fallo que ya se han escapado en este repo — dejar la organización a cero administradores bajo concurrencia, y PII que sobrevive a su propio borrado — fueron lo primero que atacaron, y las dos salen limpias.

### Aplicado en ESTA PR

| # | Hallazgo | Qué se hizo |
|---|---|---|
| 1 | **La sonda no comprobaba que `identity_user` llegue a bloquearse.** Un `remove()` que perdiera su `flush()` diferiría el DELETE y los dos tests seguirían verdes. Desviación medible de la spec, que pedía dos puntos de observación. | Añadido `ProbingPasswordResetTokenRepository` y la tercera pregunta. Falsificado: la mutación llega **solo** a la aserción nueva de las tres. |
| 2 | **La regla de «menos grados de libertad» no selecciona al conformista en el ciclo #2** — los tres participantes conocen ambos ids antes de abrir transacción. Afirmado en 3 ficheros. | Reescrito el argumento real: a los caminos de reset los fija su **propia corrección** (el lock de usuario *es* el mutex de supersede; la relectura de estado bajo él tapia un reset recién suspendido), no su libertad. |
| 3 | **`RevokeInvitation` afirmaba que sus dos entradas llegan nombrando la invitación.** `revokeForInvitedUser()` llega nombrando a la **persona** — el propio docblock de clase lo dice 95 líneas antes. | Reescrito: quien fija el par es el accept, no el revoke; ambas entradas de revoke conforman. |
| 4 | **`docs/architecture-api.md` describía la cadena vieja.** CLAUDE.md lo hace obligatorio y `b57c68fc` sentó el precedente. | Actualizado el orden y añadido el porqué del invariante. |
| 5 | **El canario de presupuesto de `erase.feature` enumeraba el orden viejo.** El conteo no cambia (15/19), así que el escenario seguía verde y la deriva era silenciosa. | Reescrito en orden de adquisición. |
| 6 | **«Nada más en el suite se pone rojo» era falso** — la sonda funcional también. Es la redacción que hace que borrar el *otro* guardián parezca gratis. | Corregido en los dos sitios. |
| 7 | **`ORDER BY` sobrevendido**: cierra su propia sentencia, no la tabla. | Acotada la afirmación y declarado el residual (abajo). |
| 8 | `tearDown` moría con «typed property not initialised» si `setUp` fallaba, tapando el diagnóstico real. | Guarda `isset`, como el aparato hermano. |
| 9 | `expectException` + `finally` descartaba la excepción y reportaba la causa equivocada. | Cambiado a `try`/`fail`/`catch`, la forma de su hermano. |
| 10 | El doble de tokens comparaba el `userId` sensible a mayúsculas; sus dos hermanos usan `strcasecmp` porque la columna es `uuid`. | Corregido (regla del boy scout: preexistente, pero el fichero ya estaba tocado). |
| 11 | El `User` de la siembra era el único de tres fixtures sin drenar sus eventos de dominio. | Drenado. |

### Residual declarado, no cerrado

`deleteAllForInvitedUser` es un `DELETE` masivo y no admite `ORDER BY`, así que una revocación y un borrado del mismo invitado pueden recorrer filas compartidas de `iam_invitation` en órdenes opuestos. **Alcanzarlo exige dos invitaciones `SENT` vivas para un invitado, estado que ningún camino de escritura produce** (`RevokeInvitation` documenta que su bucle multi-fila es defensivo, no esperado). Es preexistente — antes del cambio las dos sentencias estaban igual de desordenadas — y lo que esta PR introdujo fue la *afirmación* de haberlo cerrado, ya acotada. Cerrarlo costaría un `SELECT … ORDER BY id FOR UPDATE` extra en el camino GDPR y rompería el canario de presupuesto de `erase.feature`: decisión pendiente de Sergio.

### Lo que ninguno de los dos pudo verificar

- **Ninguna carrera real de dos transacciones se ejercita en ningún sitio**, por construcción (sin `pcntl`, sin `pgsql` procedural). Toda afirmación de deadlock aquí — incluidas las que los revisores comparten — descansa en instantes de adquisición, jamás en un `40P01` observado.
- La inestabilidad de plan de `DELETE … WHERE invited_user_id = ?` se argumenta desde la forma del índice, no desde un `EXPLAIN` sobre una tabla poblada.
