---
title: 'BR-5: ciclo de vida de `iam_session` — predicado compartido y suelo de retención'
type: 'feature'
created: '2026-08-14'
status: 'done'
review_loop_iteration: 1
baseline_commit: d07ba35f92a3ef550462b854ec31892d14c9ef04
context:
  - '{project-root}/docs/adr/identity-invitation-lifecycle.md'
  - '{project-root}/api/.bounded-context-allowlist'
---

# Story BR-5: ciclo de vida de `iam_session` (#474 + #468)

> Épica: [`epics-backlog-resolution.md`](../planning-artifacts/epics-backlog-resolution.md) · Lote BR-5 · Issues #474 #468
> Rama: `feat/iam-session-retention-5zzw` · Worktree: `.claude/worktrees/iam-session-retention-5zzw` · Base: `main` @ `d07ba35f`
> Una rama, una PR — decisión de Sergio, no separar los dos issues.

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Nada poda `iam_session`. Cada fila guarda `ip` y `device`, que el propio docblock de la entidad
llama *"short-lived operational PII"* — una vida corta que **ningún código impone** (#468). Y la regla de
admisibilidad `status = ACTIVE AND expiresAt > :now` está copiada literal en tres sitios del repositorio, con
sus dos bindings, de modo que una futura ventana de gracia obliga a editar tres sitios en lockstep (#474).

**Approach:** Primero el refactor conservador de conducta de `DoctrineSessionRepository`, para que la poda
aterrice sobre un repositorio limpio; después un caso de uso publicado `PruneRetiredSessions` consumido por
dos entradas — un comando `iam:session:prune` para el operador y un cuarto `RecurringMessage` en el
`IdentityMaintenanceSchedule` existente — y la política de retención escrita con su base legal.

## Boundaries & Constraints

**Always:**

- **Conducta idéntica en #474.** Mismas filas, mismo orden, misma semántica de revocación. La extracción deja
  **una sola** renderización en código de la regla: el docblock de `InMemorySessionRepository` *cuenta* las
  renderizaciones ("deja dos … en vez de tres") y esa cuenta debe seguir siendo cierta.
- **`findActiveById` sigue llamando a `createQueryBuilder()` dentro de su propio `try`.** El test de
  indisponibilidad mockea ese método para lanzar, así que sacar el QB del `try` lo pone rojo.
  ~~y convierte un 503 en un 500~~ — **falso, corregido tras el pase adversarial (2026-08-14):**
  `EntityManager::createQueryBuilder()` es `return new QueryBuilder($this)`, no abre conexión y no puede
  lanzar `DbalException`. Lo que en producción debe quedar dentro del `try` es la **ejecución**
  (`getOneOrNullResult()`), no la construcción. La restricción sobre el código sigue en pie porque el test
  la impone; lo que era falso es la razón. Se conserva tachado en vez de borrado: el error es reproducible
  —deducir un requisito de producción a partir de la forma de un mock— y es el que produjo el único GRAVE.
- **`deleteAllForUser` sigue siendo una sola sentencia.** `erase.feature:92-97` fija 16 queries para toda la
  cadena de borrado.
- La poda clavea en `expiresAt` y en `revokedAt`, **nunca** en un status nuevo.
- Cada garantía nueva se falsifica por separado, rompiéndola a propósito y contando los rojos, y se restaura
  **copiando bytes** — nunca `git checkout --`.

**Ask First:**

- Cambiar las ventanas 30/90 días, o medirlas desde otra columna.
- Introducir un status `EXPIRED`, tocar el TTL `P7D`, o poner un limitador en `revoke-others`.
- Repuntar el dueño de borrado de `Session::$userId` fuera de `PurgeUserSessions.php`.
- Escribir una fila de auditoría desde la poda.

**Never:**

- Separar #474 y #468 en dos ramas o dos PRs.
- Acuñar un `SessionMaintenanceSchedule` propio.
- Tocar `revoke-others`, el TTL de sesión, o la ruta de borrado GDPR (`PurgeUserSessions` sigue siendo el
  dueño; esto es el suelo de retención rutinario, **no** la ruta de erasure).
- Añadir un segundo predicado temporal a `DbalSessionPersonReferences`: su ceguera al predicado es deliberada.

## I/O & Edge-Case Matrix

| Escenario | Entrada / Estado | Salida / Conducta esperada | Manejo de error |
|---|---|---|---|
| Poda de revocada vieja | fila `REVOKED`, `revokedAt` = ahora − 31 d | borrada; el contador la cuenta | N/A |
| Revocada en el borde | fila `REVOKED`, `revokedAt` = ahora − 30 d exactos | **sobrevive** (`<`, no `<=`) | N/A |
| Poda de activa caducada | fila `ACTIVE`, `expiresAt` = ahora − 91 d | borrada | N/A |
| Activa caducada reciente | fila `ACTIVE`, `expiresAt` = ahora − 89 d | **sobrevive** | N/A |
| Activa viva | fila `ACTIVE`, `expiresAt` = ahora + 1 h | sobrevive | N/A |
| Revocada reciente y caducada hace mucho | `REVOKED`, `revokedAt` = ahora − 1 d, `expiresAt` = ahora − 200 d | **borrada**: gana la ventana que venza **primero**. Sergio, 2026-08-14, tras el pase adversarial | N/A |
| Segunda pasada | inmediatamente tras la primera | borra 0, devuelve 0 | idempotente |
| Tabla vacía | sin filas | devuelve 0, el comando sale `SUCCESS` | N/A |
| Orden de `findByUserId` | 3 sesiones vivas del usuario, creadas en instantes distintos | `createdAt` **DESC** | N/A |

</frozen-after-approval>

## Code Map

- `api/src/Iam/Session/Infrastructure/Persistence/Doctrine/DoctrineSessionRepository.php` — sujeto de #474.
  `USER_ID_FILTER` :35; el predicado literal en :58-59, :81-82, :132.
- `api/src/Iam/Session/Domain/Repository/SessionRepository.php` — el puerto; publica el predicado en prosa.
- `api/src/Iam/Session/Application/PurgeUserSessions.php` — el hermano de borrado GDPR; la forma a imitar.
- `api/src/Iam/Session/Application/StartSession.php:30` — `TTL_SPEC = 'P7D'`, el techo de vida de una sesión.
- `api/src/Iam/Session/Domain/Enum/SessionStatus.php:12-15` — *"the day a sweeper genuinely exists, `EXPIRED`
  can be reintroduced"*. Este PR trae un sweeper que **borra**, no uno que escribe la transición.
- `api/src/Iam/Session/Infrastructure/Persistence/Doctrine/DbalSessionPersonReferences.php:18-29` — afirma
  *"nothing reaps an expired row"*. **Este PR lo vuelve falso.**
- `api/src/Iam/Identity/Infrastructure/Messenger/Maintenance/IdentityMaintenanceSchedule.php` — `->stateful()`
  :72, los tres `RecurringMessage` :73-75. Su docblock argumenta *qué* puede colgarse de aquí.
- `api/src/Iam/Identity/Infrastructure/Cli/PruneExpiredPasswordResetTokensCommand.php` — patrón de comando.
- `api/src/Shared/Audit/Infrastructure/Messenger/Maintenance/PruneAuditLogHandler.php` — el único precedente
  de una poda *agendada*: el handler llama a un puerto de Application con un `Clock`.
- `api/tests/Unit/Iam/Identity/Infrastructure/Messenger/Maintenance/IdentityMaintenanceScheduleTest.php` — su
  `assertSame` ordenado es **el único rojo** que puede tener el cableado del tick.
- `api/tests/Functional/Iam/Session/DoctrineSessionRepositoryTest.php` — Postgres real, transacción revertida.
- `api/tests/Unit/Iam/Session/Application/InMemorySessionRepository.php` (+ su `…ContractTest`) — el doble.
- `api/tests/Functional/Iam/Session/Fixtures/UnavailableSessionRepository.php` — implementa la interfaz
  entera; un método nuevo en el puerto obliga a ampliarlo.
- `api/.bounded-context-allowlist` :117 y :124 — las seams `Identity → Session` vivas (por **ruta**).
- `api/tools/deptrac/deptrac.yaml` `skip_violations` — las mismas seams por **FQCN**. Nada sincroniza las dos.
- `PRODUCTION_SECURITY_CHECKLIST.md:583-602` — el párrafo de `iam_session`, que **ya** promete 30/90 días
  como follow-up de #468.

## Tasks & Acceptance

**Execution:**

*#474 — refactor conservador de conducta (va primero)*

- [x] `api/tests/Functional/Iam/Session/DoctrineSessionRepositoryTest.php` — añadir el test que fija
  `createdAt DESC` en `findByUserId` **antes** de tocar producción — hoy ese orden no lo observa nada (ni
  unitario, ni funcional, ni Behat, ni un doc) y el doble en memoria ya diverge sin que nadie lo detecte, así
  que es la única conducta que el refactor puede romper en silencio.
- [x] `api/src/Iam/Session/Infrastructure/Persistence/Doctrine/DoctrineSessionRepository.php` — extraer
  `ACTIVE_STATUS_FILTER` / `UNEXPIRED_FILTER` y un privado `whereAdmissibleNow(QueryBuilder): QueryBuilder`
  que aplique ambos más sus dos bindings; consumirlo desde los dos SELECT. `bulkRevokeActive` consume **solo**
  `ACTIVE_STATUS_FILTER` — es un UPDATE y no lleva la mitad temporal.
- [x] `api/src/Iam/Session/Domain/Repository/SessionRepository.php` — revisar que la prosa del puerto sigue
  siendo cierta tras la extracción (no debe crecer: el código pasa a tener una renderización, no dos).

*#468 — suelo de retención*

- [x] `api/src/Iam/Session/Domain/Repository/SessionRepository.php` — añadir
  `deleteRetired(DateTimeImmutable $revokedBefore, DateTimeImmutable $expiredBefore): int` con su docblock,
  diciendo por qué son dos umbrales y no uno.
- [x] `api/src/Iam/Session/Infrastructure/Persistence/Doctrine/DoctrineSessionRepository.php` — implementarlo
  como **una** sentencia DQL `DELETE` con las dos ramas en `OR`, idiom `\is_int($affected) ? $affected : 0`.
- [x] `api/tests/Unit/Iam/Session/Application/InMemorySessionRepository.php` — implementar `deleteRetired` en
  el doble; `api/tests/Functional/Iam/Session/Fixtures/UnavailableSessionRepository.php` — ampliarlo para que
  siga implementando la interfaz entera.
- [x] `api/src/Iam/Session/Application/PruneRetiredSessions.php` — caso de uso publicado: dueño de las dos
  ventanas (`P30D` sobre `revokedAt`, `P90D` sobre `expiresAt`), calcula los umbrales desde el `Clock` y
  devuelve el conteo. Una sola renderización de la política.
- [x] `api/src/Iam/Session/Infrastructure/Cli/PruneRetiredSessionsCommand.php` — `iam:session:prune`,
  copiando `PruneExpiredPasswordResetTokensCommand` (`SymfonyStyle::success`, `Command::SUCCESS`). Directorio
  nuevo; deptrac ya lo colecciona por directorio.
- [x] `api/src/Iam/Identity/Infrastructure/Messenger/Maintenance/PruneRetiredSessionsMessage.php` — mensaje sin
  payload, con el docblock que argumenta por qué no lleva ninguno.
- [x] `api/src/Iam/Identity/Infrastructure/Messenger/Maintenance/PruneRetiredSessionsHandler.php` —
  `#[AsMessageHandler]`, `unset($message);` como los tres hermanos, delega en `PruneRetiredSessions`.
- [x] `api/src/Iam/Identity/Infrastructure/Messenger/Maintenance/IdentityMaintenanceSchedule.php` — cuarto
  `RecurringMessage::every('1 day', …)`, y **ampliar su docblock**: hoy argumenta que los tres son controles
  que este contexto posee, y este cuarto no lo es. El argumento honesto es el coste evitado (un transporte,
  dos ediciones de compose, un emparejamiento para el gate y una forma de enviarlo muerto, por un tick).
- [x] `api/.bounded-context-allowlist` — una línea nueva `…/PruneRetiredSessionsHandler.php =>
  Erpify\Iam\Session\Application\PruneRetiredSessions`, con su párrafo de justificación.
- [x] `api/tools/deptrac/deptrac.yaml` — la misma seam por FQCN en `skip_violations`.

*Tests de #468*

- [x] `api/tests/Unit/Iam/Session/Application/PruneRetiredSessionsTest.php` — la matriz de bordes sobre el
  doble, incluida la fila revocada-reciente-caducada-hace-mucho.
- [x] `api/tests/Unit/Iam/Session/Infrastructure/Cli/PruneRetiredSessionsCommandTest.php` — `CommandTester`,
  exit code y el texto del `success`.
- [x] `api/tests/Unit/Iam/Identity/Infrastructure/Messenger/Maintenance/PruneRetiredSessionsHandlerTest.php` —
  el handler delega y no hace nada más.
- [x] `api/tests/Unit/Iam/Identity/Infrastructure/Messenger/Maintenance/IdentityMaintenanceScheduleTest.php` —
  cuarta entrada en el `assertSame` **ordenado alfabéticamente**. Es el único rojo del cableado.
- [x] `api/tests/Functional/Iam/Session/DoctrineSessionRepositoryTest.php` — la matriz contra Postgres real:
  los dos bordes (`<` y no `<=`), las dos ramas y la idempotencia de la segunda pasada.

*Documentación y verdad*

- [x] `api/src/Iam/Session/Infrastructure/Persistence/Doctrine/DbalSessionPersonReferences.php` — corregir
  *"nothing reaps an expired row"*: ahora hay una poda, y la razón de que esta fuente siga sin predicado pasa
  a ser otra (una fila dentro de su ventana de retención sigue guardando el id, y es precisamente la que el
  reconciliador debe ver).
- [x] `api/src/Iam/Session/Domain/Enum/SessionStatus.php` — precisar que el sweeper que ahora existe **borra**
  filas y no escribe transición, así que la condición para reintroducir `EXPIRED` sigue sin cumplirse.
- [x] `PRODUCTION_SECURITY_CHECKLIST.md:595-597` — reescribir el follow-up como capacidad enviada: el comando,
  el tick diario, las dos ventanas y la base legal (**interés legítimo**: seguridad de la cuenta / gestión de
  sesiones), y qué **no** cubre.
- [x] `docs/adr/identity-invitation-lifecycle.md` D8 — una cláusula: la GC del handler nativo cubre el *payload*
  de la sesión de framework, no la tabla del registro, cuyo suelo de retención es este trabajo.
- [x] `docs/architecture-api.md` — registrar la poda junto a las otras dos del árbol.
- [x] Boy scout, ambos nombrados en la PR: `api/CLAUDE.md` llama a `Invitation/` y `Session/` *"reserved
  skeletons"*, y `api/tools/deptrac/deptrac.yaml` repite la misma prosa rancia sobre los dos módulos.

*Divergencias respecto al plan, decididas durante la ejecución*

- [x] **La garantía de orden se cerró en tres capas, no en una.** El plan sólo pedía el test del adaptador,
  pero al volverse observable el orden, el puerto tenía que publicarlo y el doble tenía que honrarlo — hoy
  conserva orden de inserción, así que un test de caso de uso podía afirmar una secuencia que producción no
  produce. Añadidos: la promesa en el docblock del puerto, un `usort` en `InMemorySessionRepository`, y un
  caso en su contract test (cuyo docblock pasa de contar "dos formas de divergir" a tres).
- [x] **Los tests funcionales de retención viven en su propia clase**, `DoctrineSessionRetentionTest`.
  No fue estética: con los tres dentro, `DoctrineSessionRepositoryTest` llegaba a 12 métodos públicos y PHPMD
  corta en 10. La partición resultó mejor que la causa — la sentencia que **borra** merece una clase cuyo
  docblock hable de por qué se prueba aparte.
- [x] `docs/architecture-api.md` y la cláusula de D8 no estaban en el plan como tareas propias; entraron al
  medir que las dos son prosa que este cambio vuelve incompleta o citable en contra.

**Acceptance Criteria:**

- Dado el suite completo en verde antes del refactor, cuando se aplica #474, entonces `make php.test` sigue
  verde sin haber editado ninguna aserción existente salvo la del schedule.
- Dada una extracción que mueva `createQueryBuilder()` fuera del `try` de `findActiveById`, cuando corre
  `DoctrineSessionRepositoryStoreUnavailableTest`, entonces sale rojo — verificado a propósito y restaurado
  copiando bytes.
- Dado que se borra el cuarto `RecurringMessage` del schedule, cuando corre el suite, entonces
  `IdentityMaintenanceScheduleTest` es el único rojo — contado, no supuesto.
- Dada una tabla sembrada con las nueve filas de la matriz, cuando corre `iam:session:prune`, entonces
  sobreviven exactamente las que la matriz marca como supervivientes, y una segunda ejecución borra 0.
- Dado el árbol final, cuando corren `make php.lint.bounded-context`, `make php.deptrac`,
  `make php.lint.person-reference`, `make php.lint.schedule-consumption` y `make php.lint.step-vocabulary`,
  entonces todos salen 0 — cada uno desde una ejecución fresca con su código de salida impreso.
- Dado el diff completo, cuando se busca la cadena `nothing reaps an expired row`, entonces no aparece.

## Pase adversarial (registro obligatorio, previo a abrir la PR)

**Ejecutado el 2026-08-14, por dos contextos distintos del autor**, ambos read-only y sin contexto previo de
esta sesión: `bmad-review-adversarial-general` (Blind Hunter) y `bmad-review-edge-case-hunter`, cada uno sobre
el diff completo de 26 ficheros. Devolvieron 1 GRAVE, 10 moderados y varios menores; el adversarial además
descartó explícitamente ocho hipótesis por no sostenerse contra el código (entre ellas que la parentización
del `OR` estuviera mal, que el barrido pudiera borrar una sesión viva, y que hubiera desfase de reloj entre
`SystemClock` y el `Clock` inyectado).

**GRAVE — corregido.** El docblock de `andWhereAdmissibleNow()` afirmaba que un builder creado fuera del `try`
expondría el fallo de store como un 500. Falso: `EntityManager::createQueryBuilder()` es
`return new QueryBuilder($this)` — no abre conexión y no puede lanzar `DbalException`. El fallo real ocurre en
`getOneOrNullResult()`. La afirmación se había deducido de un test que mockea `createQueryBuilder()` para
lanzar, es decir, de una forma que producción no puede producir. Reescrito con la razón verdadera, y anotado
allí mismo que ese test es un pin más débil de lo que aparenta; el arreglo del test queda en `deferred-work.md`
por ser preexistente y tocar el TCB de autenticación.

**Cambio de conducta que salió del pase** — la regla «gana la ventana que venza primero», decidida por Sergio.
Ver el change log de abajo.

**Correcciones de verdad documental aplicadas:** el ordinal «tercera tabla con suelo de retención» (es la
cuarta: `identity_password_reset_token` ya tenía la suya); el titular «enforced, not merely declared» del
checklist, que se contradecía once líneas más abajo con «nada observa que se haya ejecutado»; la promesa de
orden del puerto, que era más fuerte de lo que el esquema puede sostener; y la interacción con el control
detective de GDPR, cuya **ventana de detección** queda acotada por el propio barrido — dicho ahora
explícitamente en `DbalSessionPersonReferences`, porque es el trade opuesto al de `audit_log`.

**Cuatro comentarios relativos al cambio** (prohibidos por CLAUDE.md) eliminados de `api/src`.

**Lo que el pase NO habría encontrado y encontró la falsificación:** el test funcional del desempate de orden
era **vacuo** — sembraba las filas en el mismo orden que esperaba, así que pasaba igual sin `addOrderBy`.
Se detectó al quitar el desempate a propósito y ver que no se ponía rojo; corregido invirtiendo la siembra, y
re-medido: ahora sí falla.

## Spec Change Log

### Iteración 1 — 2026-08-14, tras el pase adversarial

**Hallazgo que lo dispara (Edge Case Hunter, confirmado leyendo `bulkRevokeActive`):** el UPDATE de revocación
masiva filtra **sólo** por `status = ACTIVE`, así que sella `revokedAt = now` sobre filas que llevaban meses
caducadas. Con la regla aprobada —«una fila revocada se juzga por `revokedAt` y por nada más»— esa fila salta
del reloj de 90 días al de 30 **contado desde hoy**, de modo que un cambio de contraseña ordinario alargaba la
vida de PII muerta hasta ~119 días. Alcanzable en el flujo más común que hay: el usuario entra, cambia la
contraseña, y `RevokeSessionsBestEffort` marca todas sus filas.

**Enmienda (decisión de Sergio, 2026-08-14):** gana la ventana que **venza primero**. La segunda rama del
DELETE deja de nombrar un status — `(status = REVOKED AND revokedAt < :revokedBefore) OR (expiresAt <
:expiredBefore)`. La fila de la matriz congelada queda invertida arriba.

**Estado malo que evita:** una revocación *alargando* la retención de datos personales, en un control que
existe para acotarla, con la documentación diciendo 90 días y el código dando 119.

**Efecto lateral que no se buscaba y conviene conservar:** al no nombrar status la rama de caducidad, un tercer
valor del enum (`EXPIRED`, que el ADR D8 deja la puerta abierta a reintroducir) ya no puede caer entre las dos
ramas y guardar `ip`/`device` para siempre. El hallazgo 5 del pase adversarial se cierra con la misma línea.

**KEEP — lo que funcionó y debe sobrevivir a cualquier re-derivación:**

- Escribir el test del orden **antes** de tocar producción, y falsificarlo contra el suite entero. Es lo que
  demostró que nada observaba `createdAt DESC` (1 rojo de 2795 en PHPUnit, 0 en Behat).
- Un caso por garantía, con los bordes estrictos (`<`) probados a ambos lados. La regla nueva tiene su propio
  caso porque restringir la rama de caducidad a `ACTIVE` pasa todos los demás.
- Falsificar el cuarto tick del schedule **y** comprobar que los gates siguen verdes sin él: eso convierte la
  afirmación del docblock en medida.
- Restaurar siempre copiando bytes y verificando md5, nunca `git checkout --`.

## Design Notes

**Por qué el applier no hilvana el alias, en contra de lo que sugiere #474.** La clase tiene exactamente un
alias (`'s'`) en sus cuatro métodos, y `USER_ID_FILTER` ya sienta el precedente de una constante con el alias
incrustado. Un parámetro `string $alias` que todos los call sites pasan con el mismo literal es abstracción
para un futuro hipotético — justo lo que la regla de las Tres Veces / YAGNI de `CLAUDE.md` prohíbe. El único
precedente del repo que sí lo hilvana (`KeysetPredicateBuilder`) es un colaborador inyectado en `Shared/`,
que sirve a un motor genérico de búsqueda: no es esta forma.

**Por qué el handler vive en `Iam/Identity` y no en `Iam/Session`.** Poner mensaje y handler en `Iam/Session`
haría que el schedule importara `Iam\Session\Infrastructure\…`; las cuatro seams vivas entre estos dos módulos
importan `Application` o `Domain`, y el header del allowlist define una seam legítima como *"published
Application service interface / integration event"*. Cruzar hacia `Infrastructure` sería una seam peor, no
mejor. Con el handler en `Identity` consumiendo `PruneRetiredSessions`, esto es la misma familia que
`FulfilIdentityErasure => PurgeUserSessions`.

**Dos entradas, una política.** El comando sirve al operador (y es lo que pide #468); el tick es lo que hace
real la "vida corta". Ambos delegan en el mismo caso de uso, así que las ventanas se escriben una sola vez.
El precedente del árbol está partido —la poda de auditoría es solo agendada, la de tokens solo CLI y de hecho
**nada la agenda**— y esa asimetría es precisamente lo que produce un control que nadie dispara.

**Los números ya están comprometidos.** `PRODUCTION_SECURITY_CHECKLIST.md:596` promete `REVOKED > 30 d` y
`ACTIVE` con `expiresAt > 90 d`. Ambas cifras tienen precedente en el árbol (90 d = techo de `activity` en
auditoría; 30 d = retención de `handled_domain_event`). Con `TTL_SPEC = 'P7D'`, la ventana de 90 días se mide
desde `expiresAt`, no desde el alta: ~97 días desde que se acuñó la sesión.

## Verification

**Commands:**

- `make php.stan` — sobre cada fichero PHP tocado; esperado: exit 0.
- `make php.unit c='--filter DoctrineSessionRepository'` y `--filter PruneRetiredSessions` — verde.
- `make php.unit c='--filter IdentityMaintenanceScheduleTest'` — verde con la cuarta entrada.
- `make php.quality` — exit 0, de una ejecución fresca, redirigiendo a fichero y leyendo `$?` (en zsh
  `${PIPESTATUS[0]}` sale vacío).
- `make php.test` — exit 0.

**Manual checks:**

- Falsificar cada garantía nueva por separado (el `try`, el cuarto tick, cada rama del `DELETE`, el orden de
  `findByUserId`), contar los rojos de cada una y restaurar **copiando los bytes**.
- Pase adversarial registrado, por un contexto distinto del autor, con sus hallazgos escritos en este
  artefacto **antes** de `gh pr create`.

## Suggested Review Order

**La política de retención — empieza aquí**

- Dueño único de las dos ventanas; ambas entradas delegan aquí, así que no pueden divergir.
  [`PruneRetiredSessions.php:35`](../../api/src/Iam/Session/Application/PruneRetiredSessions.php#L35)

- El contrato: gana la ventana que venza primero, y por qué sólo una rama nombra un status.
  [`SessionRepository.php:85`](../../api/src/Iam/Session/Domain/Repository/SessionRepository.php#L85)

- La sentencia. Ramas parentizadas, semántica de `NULL`, y el análisis de concurrencia aceptado.
  [`DoctrineSessionRepository.php:166`](../../api/src/Iam/Session/Infrastructure/Persistence/Doctrine/DoctrineSessionRepository.php#L166)

**El refactor de #474 — conducta preservada**

- El aplicador compartido. Su docblock dice dónde ocurre de verdad el fallo de store.
  [`DoctrineSessionRepository.php:230`](../../api/src/Iam/Session/Infrastructure/Persistence/Doctrine/DoctrineSessionRepository.php#L230)

- El desempate: `created_at` es TIMESTAMP(0), así que sin el id el orden no es total.
  [`DoctrineSessionRepository.php:102`](../../api/src/Iam/Session/Infrastructure/Persistence/Doctrine/DoctrineSessionRepository.php#L102)

**El cableado y su frontera**

- El cuarto tick. Ningún gate lo ve: el argumento de coste está en el docblock.
  [`IdentityMaintenanceSchedule.php:91`](../../api/src/Iam/Identity/Infrastructure/Messenger/Maintenance/IdentityMaintenanceSchedule.php#L91)

- El handler cruza a `Iam/Session` por un caso de uso publicado, como las otras tres seams.
  [`PruneRetiredSessionsHandler.php:35`](../../api/src/Iam/Identity/Infrastructure/Messenger/Maintenance/PruneRetiredSessionsHandler.php#L35)

- La seam declarada por ruta; su gemela por FQCN vive en `deptrac.yaml` y nada las sincroniza.
  [`.bounded-context-allowlist:144`](../../api/.bounded-context-allowlist#L144)

- El brazo del operador, delgado a propósito: no decide nada sobre las ventanas.
  [`PruneRetiredSessionsCommand.php:9`](../../api/src/Iam/Session/Infrastructure/Cli/PruneRetiredSessionsCommand.php#L9)

**La consecuencia sobre el control detective de GDPR**

- El barrido acota la ventana de DETECCIÓN: trade opuesto al de `audit_log`, dicho explícitamente.
  [`DbalSessionPersonReferences.php:30`](../../api/src/Iam/Session/Infrastructure/Persistence/Doctrine/DbalSessionPersonReferences.php#L30)

**Los tests que sostienen las afirmaciones**

- La garantía que salió del pase adversarial: una revocación no alarga una fila ya caducada.
  [`PruneRetiredSessionsTest.php:86`](../../api/tests/Unit/Iam/Session/Application/PruneRetiredSessionsTest.php#L86)

- Siembra invertida a propósito: en el orden esperado, esta aserción pasaba sin el desempate.
  [`DoctrineSessionRepositoryTest.php:141`](../../api/tests/Functional/Iam/Session/DoctrineSessionRepositoryTest.php#L141)

- La única aserción del árbol que se pone roja si el tick desaparece.
  [`IdentityMaintenanceScheduleTest.php:47`](../../api/tests/Unit/Iam/Identity/Infrastructure/Messenger/Maintenance/IdentityMaintenanceScheduleTest.php#L47)
