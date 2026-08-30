---
title: 'Barrido de deferred-work.md: 98 balas a 53 en una PR'
type: 'chore'
created: '2026-08-27'
status: 'in-review'
baseline_commit: 'f86b2662'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/deferred-work.md'
  - '{project-root}/docs/api-error-contract.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `deferred-work.md` tiene **98 balas** en `f86b2662` (la fusión de la PR #866, que ya cerró las suyas). Una verificación bala-a-bala **contra el código actual** encuentra que 8 describen defectos que ya no existen —una de ellas, ITEM 40, es directamente **falsa**: afirma que `RequestEvent::setResponse()` no detiene la propagación, y sí la detiene— y 39 son cerrables hoy con alcance acotado. Las 51 restantes no son cerrables sin escribir código especulativo o sin una decisión de producto/arquitectura.

**Approach:** Una rama, una PR. **De las 39 balas hoy cerrables, esta PR cierra 37; las otras 2 (ITEM 41, talla L; ITEM 56, propiedad del registro de #462) permanecen abiertas por decisión explícita del product owner** — no son una categoría aparte, son cerrables que no se cierran aquí. Sumadas las 8 muertas, **salen 45 viñetas** (37 cerradas + 8 borradas por muertas).

**Aritmética del registro: `98 − 45 = ` 53**, y es un solo número, verificable por esta rama contra su propia base. Se descompone en 27 `trigger-gated` + 24 `needs-decision` + 2 aplazadas por el product owner (41, 56).

**Nota de historia, porque explica por qué el gate no protege nada de #866.** Un borrador anterior de este spec partía de `c988198b` y cargaba con una restricción entera —«no tocar las 22 viñetas de #866»— más un desdoble 73/51 entre «lo que esta rama verifica» y «lo que se verá tras el merge». **#866 se mergeó (`f86b2662`) mientras esta rama todavía estaba vacía**, así que se rebasó sobre ella: sus viñetas ya no están en la base y no hay nada que proteger. Aquel 51 proyectado y este 53 medido no coinciden porque #866 no solo borró 24 viñetas, también **editó** 4 — dos de ellas quedaron sin triar en el barrido original y se han triado ahora (ambas `needs-decision`: el contrato de fallo de la rama `security`, hermano del ITEM 38; y el presupuesto de query exacto `27`, que la propia bala llama «patrón aceptado de la casa» y cuyo endurecimiento sería `<= N` repo-wide).

## Boundaries & Constraints

**Always:**
- Cada arreglo llega con su **falsador**: un test o gate que se pone rojo sin el arreglo y verde con él. Un verde que no puede ponerse rojo no cuenta como cierre.
- **Trazabilidad por bala, y compartir fichero no basta.** Cada bala cerrada se registra como una fila `ITEM → condición original → cambio mínimo → falsador → mutación exacta → chequeo de independencia → resultado` en `deferred-work-sweep-closure-evidence.md`. La **mutación debe ser identificable de forma independiente**: revertirla pone rojo **su** falsador y deja **verdes** los de las demás. No hace falta una matriz 37×37; sí hace falta una **partición explícita de mutaciones por cada grupo que comparte fichero**:
  - **82/84/86** (`MessengerConsumerContext.php`) → `82 → aserción de receivers vacíos`, `84 → contador de mensajes manejados`, `86 → cálculo de verbosidad por máximo`. Tres mutaciones distinguibles aunque estén físicamente contiguas. **Rechazado explícitamente:** «se refactorizó el contexto y los tres escenarios pasan».
  - **17/27** (`SessionAdmissionGate.php`) → `17 → publicación del atributo en el Request`, `27 → invalidación de la sesión nativa`. Si el código las vuelve inseparables, **una de las dos no se cierra** y su viñeta se queda.
- **Los duplicados se declaran duplicados, no se separan a la fuerza.** **54 y 67 describen el mismo defecto**: una única mutación de producción y un único contrato de falsador, registrados como `54 → duplicado de 67`, `67 → duplicado de 54`, `mutación compartida M54/67`. Fabricar dos mutaciones artificiales para satisfacer la regla de independencia sería peor que el problema que la regla evita. Consecuencia contable, dicha en voz alta: **dos viñetas salen del registro por una sola corrección semántica**, y eso es correcto.
- Al cerrar una bala se **borra su viñeta** del registro (es pending-only, nunca un changelog).
- Copy del PWA en inglés (`pwa/tests/ui-copy-language.test.ts`).
- Cambiar el mapa de tipos del contrato de error obliga a actualizar `docs/api-error-contract.md` (NFR26).

**Ask First (resueltos por el product owner el 2026-08-27, antes de tocar código):**
- **ITEM 21** — AUTORIZADO. Voltear el default a `['json']`. La verificación de los 11 endpoints **no es prosa libre en la descripción de la PR**: es una tabla en `deferred-work-sweep-closure-evidence.md`, `endpoint | ¿declara acceptFormat? | ¿form/multipart intencionado? | veredicto`, con los once **individualmente**. Nunca en `deferred-work.md` — el registro no gana changelog, pero la evidencia sí tiene que existir en un sitio auditable y reproducible. **Se considera fallo** si aparecen menos de 11 filas, si alguna se clasifica como form/multipart intencionado (→ HALT en vez de restringir), o si el default se cambia antes de completar la inspección.
- **ITEMS 83 y 84** — AUTORIZADOS. Endurecer las dos aserciones vacuas aunque toquen 6 y ~15 escenarios. El procedimiento es exactamente este, y el paso 5 es el que decide:
  1. Endurecer la aserción.
  2. Ejecutar el escenario.
  3. Si falla **únicamente** porque la nueva aserción detecta la ausencia que antes pasaba en silencio (cola vacía, buffer vacío, 0 receivers, N consumidos ≠ N pedidos) → corregir el escenario para que aporte el sujeto que le faltaba.
  4. Si cambia o falla **cualquier otra cosa** — una precondición, un código HTTP, una fila persistida, un evento publicado → **HALT y reportar**. La aserción vacua estaba tapando un defecto real, y ese defecto es el hallazgo, no un obstáculo.
  5. **Nunca modificar código de producción para recuperar el verde**, y nunca relajar la aserción recién endurecida. «Volver a verde» no es parte de la tarea; parte de la tarea es saber por qué se puso rojo.
- **ITEM 41** — FUERA de esta PR (talla L). Su viñeta se queda en el registro; se le añade una línea que registre el hecho medido de que **su disparador ya saltó** (4 consumidores de metadata libre), porque dejar un «Revisit trigger» que ya se cumplió es exactamente la deriva que esta PR existe para corregir.
- **ITEM 56** — FUERA de esta PR. Lo posee el registro de #462 vía `spec-gh-602-desbloqueo-administrativo.md`; cerrarlo aquí crearía dos dueños para una misma carrera. Su viñeta se queda intacta.

**Never:**
- No implementar nada cuyo disparador no haya saltado. Las 27 balas `trigger-gated` se quedan: cerrarlas es exactamente el código especulativo que prohíbe el principio 2 de CLAUDE.md.
- No resolver unilateralmente las 24 balas `needs-decision`.
- **No añadir compose.dev.yaml a `ScheduleConsumption::COMPOSE_FILES`** (ITEM 6). Medido: produce un **rojo falso** — el gate lee cada fichero suelto, sin semántica de merge de Compose.
- No re-proponer filtrar `locked_until` en `DoctrineActiveAdministratorDirectory` (ITEM 69): cerrado y descartado por registro.
- **No se añaden viñetas al registro. La única modificación permitida sobre una viñeta que permanece es la enumerada expresamente para el ITEM 41**; cualquier otro retoque de redacción sobre las 53 que se quedan está prohibido, incluido el «ya que estamos». Las viñetas que permanecen se borran o se dejan intactas — no hay tercera opción salvo esa excepción nombrada.

</frozen-after-approval>

## Code Map

- `_bmad-output/implementation-artifacts/deferred-work.md` — el registro; **45 viñetas salen de él** (37 cerradas + 8 muertas), de 98 a 53.
- `tmp/triage-final.md` — el triaje verificado, bala a bala, con bucket y falsador.
- `api/tests/Behat/Context/{OutboxContext,MessengerConsumerContext,RunOutcomeContext}.php`, `api/tests/Behat/Support/Execution/LastRun.php` — aserciones que hoy pasan vacuamente.
- `api/tests/Doctrine/TestDebugDataHolder.php` — único punto que ve las escrituras raw-DBAL (ITEM 47).
- `api/src/Shared/Audit/**`, `api/tests/Support/AuditResourceTypeRegistry.php`, `api/tests/Unit/Gate/RedactionVocabularyParityTest.php` — patrón de gate de paridad a replicar.
- `api/src/Iam/Session/Infrastructure/{Security/SessionAdmissionGate,Persistence/Doctrine/DoctrineSessionRepository}.php` — doble consulta, 503 y falsador falso.
- `api/src/Iam/Identity/Infrastructure/Persistence/Doctrine/DoctrineActiveAdministratorDirectory.php` — guarda 0-admins (ITEMS 54+67, duplicados).
- `api/src/Shared/ErrorContract/Application/ProblemDetailsFactory.php` — `HTTP_STATUS_TYPE_MAP` (contiene un **byte NUL**: usar `grep -a`).
- `pwa/src/context/backoffice/audit/infrastructure/Api*Repository.ts`, `pwa/src/app/backoffice/_lib/backofficeMenu.ts`.

## Tasks & Acceptance

**Execution — Ola 0: borrar lo que ya está muerto (sin código)**
- [x] `deferred-work.md` — borrar las 8 viñetas ALREADY-RESOLVED (22, 24, 34, 40, 50, 52, 78, 90) — el defecto ya no existe. Cada borrado debe tener **evidencia trazable en el historial de la PR** (`file:line` o commit que lo resolvió): reunida en el cuerpo de la PR o en el artefacto, no necesariamente ocho citas literales dentro de un mensaje de commit.

**Ola A — falsabilidad del arnés Behat y cobertura**
- [x] `OutboxContext.php` (81) — la forma negativa refuta sobre cola vacía — hoy pasa sin aserción alguna.
- [x] `MessengerConsumerContext.php` (82, 84, 86) — 0 receivers ya no es exit 0; `I consume N` cuenta lo consumido; la verbosidad se resuelve por **máximo**, no por última clave.
- [x] `RunOutcomeContext.php` (83) — `output should not contain` no pasa sobre un buffer vacío.
- [x] `LastRun.php` (85) — `record()` refuta si la ejecución anterior nunca se leyó.
- [x] `api/features/backoffice/bank{,_account}/access_control.feature` (51) — escenario positivo `editor → write → 2xx` en ambos.
- [x] `api/tests/Unit/Backoffice/Bank/.../BankRealtimeAuthorizeControllerTest.php` (71) — fija topics firmados y `publish: []`, espejo del gemelo de BankAccount.
- [x] `.github/workflows/ci.yml` (73) — paso `make php.unit` en el job `api-test`: hoy CI solo corre `php.unit.coverage` (1G, warnings relajados), así que el techo estricto de 512M y `failOnWarning` **nunca se ejercen en CI**.
- [x] `PRODUCTION_SECURITY_CHECKLIST.md` §7 (80) — registrar como **coste aceptado** que el borrado del `Referer` es global al sitio para un problema confinado a tres pantallas.

**Ola B — auditoría**
- [x] `api/tests/Unit/Gate/AuditWriteOperationParityTest.php` (2) — el enum PHP y sus tres literales espejo del PWA no pueden derivar.
- [x] `api/tests/Functional/Shared/Audit/AuditLogFieldWidthContractTest.php` (44) — `MAX_FIELD_LENGTH` contra `information_schema`, no contra un comentario.
- [x] `AuditLogWriterIdempotencyTest.php` (45) — resolver `AuditLogWriter` del contenedor; corregir el docblock que aún dice «sin consumidor de producción» (ya hay dos).
- [x] `TestDebugDataHolder.php` (47) — marcar `FixturesChangeTracker` también en escrituras raw-DBAL. **«Estrictamente aditivo» debe ser comprobable en el diff**: la forma es `contabilidad existente + contabilidad raw-DBAL`, nunca `contabilidad existente → contabilidad rediseñada`, porque `DoctrineContext` depende de ella. Falsador en dos direcciones: una escritura raw-DBAL **incrementa** el tracker, y una escritura ORM **conserva su semántica actual sin cambio** (un `SELECT` no marca nada).
- [x] `AuditResourceTypeRegistry.php` (75) — memoizar el corpus. **Antes de aceptar la optimización hay que responder a su pregunta de scope:** ¿es el corpus de `api/src` constante durante toda la ejecución de `php.quality`? Si algún test crea o modifica ficheros bajo `api/src`, una segunda invocación leería estado obsoleto y la caché convertiría un gate en estado compartido incorrecto. Verificarlo primero; si no se puede garantizar, la memoización **no entra** y la bala se queda.
- [x] `api/tests/Unit/Gate/` (91) — paridad del centinela `[REDACTED]` con `RedactedValue.tsx`, cuarto sitio sin guardián.
- [x] `deferred-work.md` (41) — **no se implementa** (fuera por decisión); su viñeta se queda y gana una línea: el disparador ya saltó, hay 4 consumidores de metadata libre.

**Ola C — sesión**
- [x] `SessionAdmissionGate.php` (17, 27) — rechazada → invalidar la sesión nativa → 4xx/503; admitida → adjuntar la `Session` **al atributo de ESE `Request`**, que es lo que la hace propiedad de la petición y no del worker. Prohibido explícitamente: singleton de contenedor, estado global, estado estático — bajo FrankenPHP worker mode cualquiera de los tres es contaminación cross-request. Dos mutaciones separadas (ver la partición 17/27 en *Always*).
- [x] `MySessionsController.php`, `RevokeOtherSessionsController.php` (17) — leer esa `Session` en vez de repetir la consulta.
- [x] `DoctrineSessionRepository.php` (92) — `findByUserId()` convierte `DbalException` en 503, como su hermana.
- [x] `DoctrineSessionRepositoryStoreUnavailableTest.php` (93) — el fallo se dispara desde `createQuery()`, no desde `createQueryBuilder()` (que **no puede lanzar**): hoy el test es verde sobre una mutación que rompe el 503.
- [x] `session.feature` (18, 19) — escenario de sesión caducada fuera de `GET /sessions`; test unitario del camino de **admisión** del gate.

**Ola D — identidad e invitación**
- [x] `DoctrineActiveAdministratorDirectory.php` + doble in-memory (54+67, duplicados) — la guarda responde «¿es el objetivo el último admin?», no «¿queda algún admin?».
- [x] `ClearLockoutOnLoginSuccess.php` (57) — invalidar la sesión en el `catch` 503, espejo del minting (`LoginSuccessEvent` ya expone `getRequest()`).
- [x] (56) — **no se implementa** (fuera por decisión): la carrera la posee #462. Su viñeta se queda intacta.
- [x] Tests (55, 61, 62, 63) — byte-identidad de las tres respuestas pre-identidad; fallo a-mitad de `AcceptInvitation` vía el hook `onSave` existente; sexto caso `REVOKED`; byte-identidad del reset + orden delete-before-save.
- [x] `MigrationColumnDefaults.php` (79) — escribir el argumento de la exención de `failed_attempts`, hoy ausente.
- [x] `deferred-work.md` (53) — solo borrar: el contrato retire-then-act **ya lo cumplen** `AcceptInvitation` y `CompletePasswordReset`.

**Ola E — contrato HTTP**
- [x] `ProblemDetailsFactory.php` + `docs/api-error-contract.md` (20) — 415 gana `type` propio; hoy cae al cubo genérico.
- [x] `StrictRequestPayload.php` (21) — `['json']` como default; **ver Ask First**.
- [x] `EraseBankAccountSubject.php` + repositorio (37) — `findByIdForUpdate`: hoy dos borrados concurrentes fallan ruidosamente en vez de ser no-op. **El falsador debe distinguir idempotencia de concurrencia real**: dos llamadas secuenciales (`delete(); delete();`) no demuestran ninguna carrera y no valen como prueba. Lo que hay que exhibir es `T1 bloquea la fila → T2 intenta la misma → T1 borra y commitea → T2 observa la ausencia → T2 no-op`, con dos conexiones/transacciones (patrón `AdministratorErasureRaceFunctionalTest`). Si solo se consigue probar idempotencia secuencial, se dice así y la bala **no** se declara cerrada por concurrencia.

**Ola F — PWA**
- [x] `AccessWall.tsx` (64) — una frase en `INVALID_RESET_LINK`: la contraseña puede haber cambiado ya.
- [x] `backofficeMenu.ts` + `BackOfficeLayoutClient.tsx` (72) — `permission?` y filtrado en **ambos** puntos de render.
- [x] `ApiAuditTimelineRepository.ts`, `ApiAuditEventDetailRepository.ts` (76) — la tolerancia es ante la **ausencia**, nunca ante la corrupción del contrato. Cuatro estados, y el type-guard los separa: `missing` → válido, por defecto `false`; `false` → válido; `true` → válido; `"false"` / `null` / `42` → **inválido, sigue tumbando el envelope**. Prohibido degradar el campo a `any` o aceptar cualquier valor en silencio. Falsador: un caso por estado, incluidos los tres inválidos.

**Ola G — cierre (el ÚLTIMO commit de la rama, sin excepción)**
- [x] `deferred-work.md` — borrar las 37 viñetas cerradas (las 8 muertas ya salieron en la Ola 0) y aplicar la única edición permitida, la del ITEM 41. El orden es `implementación → falsador → suite verde → independencia de mutación → borrado del registro`, **nunca** `borrado → a ver si pasan los tests`: mientras dura el desarrollo el registro debe seguir describiendo el estado real, y una viñeta borrada sobre una implementación aún no verificada es exactamente la contabilidad falsa que esta PR existe para erradicar.

**Acceptance Criteria:**
- Dado el registro a 98 viñetas en `f86b2662`, cuando la rama termina, entonces tiene **53**: 45 menos, 0 añadidas.
- Dada cada bala cerrada, cuando se revierte **su** mutación, entonces **su** falsador se pone rojo y los de las demás siguen verdes — la independencia se demuestra por mutación, no por lectura, y una bala sin mutación propia no se declara cerrada.
- Dadas las balas 41 y 56, cuando termina la rama, entonces **siguen en el fichero** y ningún fichero de `api/src` las implementa a medias.
- Dadas las 53 viñetas que permanecen, cuando termina la rama, entonces la **única** con texto modificado es la del ITEM 41.
- Dado `make php.quality`, `make pwa.quality`, `make php.unit`, `make php.behat` y `make pwa.test`, cuando corren al final, entonces exit 0 **con el código impreso**.
- Dada una bala `trigger-gated` o `needs-decision`, cuando termina la rama, entonces **sigue en el fichero** y su texto no se ha suavizado.

## Design Notes

El valor de esta PR no es el número. Es que las **53** que quedan queden por una razón **verificada**: 27 tienen un disparador que no ha saltado, **24** exigen una decisión humana y 2 las aplazó el product owner. (Esta frase decía «49 … y 22», los números del triaje original de 96 balas; #866 editó cuatro viñetas y dos de ellas se triaron después del rebase, ambas `needs-decision`. Que la deriva de conteo apareciese dentro de la PR escrita para erradicar la deriva de conteo es exactamente el motivo de que la aritmética la verifique un gate y no una lectura.) Antes de este triaje eso era una afirmación; ahora es una medición bala a bala contra el árbol.

Tres hallazgos que solo aparecen leyendo el código y que cambian qué se hace:
- **ITEM 40 es una bala falsa.** Afirma que `ExceptionResponder` no detiene la propagación; `RequestEvent::setResponse()` llama a `stopPropagation()`. La bala se borra por errónea, no por resuelta.
- **ITEM 6 es una trampa.** El arreglo de una línea que la bala sugiere produce un rojo falso.
- **ITEM 38 ha empeorado.** Su remedio original rompería la atomicidad de la que ahora dependen cinco casos de uso que no existían cuando se escribió. Por eso pasa a `needs-decision` en vez de cerrarse.

## Verification

**Commands:**
- `python3 _bmad-output/implementation-artifacts/deferred-work-sweep-register-gate.py` — exit 0. **Gate mecánico de integridad del registro**, ya escrito y falsificado antes de tocar código. Compara contra `f86b2662` emparejando viñetas por **contenido normalizado**, no por número de ITEM — un chequeo por número pasaría por encima de un superviviente reescrito, y esa es justo la dirección que hay que cerrar. Comprueba las dos capas: **(A) inventario** — base 98, borradas 45, añadidas 0, head 53; y **(B) integridad de los supervivientes** — el ITEM 41 como **único** con texto modificado.
- `make php.quality` — exit 0
- `make pwa.quality` — exit 0
- `make php.unit` — exit 0 (y **debe** correr: es el techo estricto que CI no ejerce hoy)
- `make php.behat` — exit 0
- `make pwa.test` — exit 0

**Estado del gate, medido hoy sobre la rama sin tocar (falsificación previa, no promesa):**

| Escenario | Resultado |
|---|---|
| Rama sin tocar | `FAIL` — 98 viñetas, 0 borradas · exit 1 |
| Añadir una viñeta nueva | `FAIL` mostrando su texto · exit 1 |
| Reescribir un superviviente | `FAIL` mostrando su texto · exit 1 |
| Estado final simulado (45 borradas + edición del 41) | `OK` · **exit 0** |

La última fila es la que importa además de los rojos: prueba que las 45 viñetas existen, casan por contenido y dejan exactamente 53 — la aritmética del spec es una medición, no una afirmación. Esa misma fila destapó un bug del propio gate: la edición del ITEM 41 contaba como una baja más, así que pedía 46 borrados y se habría quedado rojo sobre trabajo correcto. Corregido (`deleted = removed − allowed_edits`); es un bug que leyendo el script no se ve, solo aparece si simulas el final.

## Definition of Done

Checklist recorrible por el revisor sin interpretar el documento:

- [x] 98 → 53 · [x] 45 borradas · [x] 0 añadidas
- [x] ITEM 41 único superviviente modificado
- [x] ITEM 41 permanece · [x] ITEM 56 permanece idéntico
- [x] las 27 `trigger-gated` permanecen · [x] las 24 `needs-decision` permanecen
- [x] existen las 37 filas de cierre (repartidas en **36** filas — `54+67` es una para dos balas)
- [~] **cada fila tiene mutación independiente: 32 de 36.** Cuatro exceptuadas por nombre y con su motivo en la sección B del artefacto de evidencia — 53 (verificación, sin cambio que mutar), 79 y 80 (prosa, sin falsador alguno) y 73 (falsador circular). **No se marca en verde**: la pasada adversarial midió que esta línea no se cumple, y marcarla sería exactamente la contabilidad falsa que esta PR existe para erradicar.
- [x] mutación de 82 / 84 / 86 verificada por separado
- [x] mutación de 17 / 27 verificada por separado
- [x] 54/67 documentados como duplicados con mutación compartida
- [x] los endpoints del ITEM 21 auditados uno a uno — resultaron ser **13**, no 11, y los 13 ya declaraban `acceptFormat`, así que el volteo del default no restringió nada (el «once» de la bala era el conteo correcto de *repetición* el día que se escribió). Tabla completa en la sección C.
- [x] register-gate = 0 · [x] `php.quality` = 0 · [x] `pwa.quality` = 0
- [x] `php.unit` = 0 · [x] `php.behat` = 0 · [x] `pwa.test.unit` = 0
- [~] **`pwa.test.e2e` NO se marca en verde.** Se ejecutó — nunca se había ejecutado en esta rama — y dos pasadas del mismo head dieron 1 fallo y 4 fallos con conjuntos **disjuntos**, así que miden el entorno (Next en modo dev, 11 workers, BD de dev que Behat resetea) y no el código. El único fallo que sí reprodujo se midió **preexistente** contra `f86b2662` por A/B con las fuentes PWA de la base instaladas. Detalle en la sección C-bis del artefacto de evidencia.

## Adversarial pass

**Ejecutada el 2026-08-28, antes de abrir la PR, y cambió el resultado en vez de confirmarlo.**

**Cómo.** Tres lecturas hostiles en paralelo, cada una en contexto fresco, sin el
razonamiento de quien escribió el código y en modo solo-lectura, con lentes
distintas y solapamiento deliberado en los bordes: (1) el TCB de autenticación
(`SessionAdmissionGate`, `ClearLockoutOnLoginSuccess`, la guarda de último admin,
`DoctrineSessionRepository`); (2) auditoría/GDPR, contrato HTTP y PWA; (3)
falsabilidad — atacar la afirmación central de la rama («cada bala cerrada tiene
un falsador y una mutación independiente») contra el propio artefacto de
evidencia. Encima, mediciones vivas propias contra el stack del worktree, que es
lo que decidió el hallazgo más grave. La instrucción a las tres fue romper la
rama, no aprobarla, y lo más valioso que podían devolver era una fila del
artefacto cuya afirmación pudieran demostrar falsa. Devolvieron dos.

### GRAVE

**A1 — El docblock del `SessionAdmissionGate` afirmaba una propiedad de
autenticación que no se cumple.** Decía: *«One refusal now makes the next request
anonymous, refused at `access_control`»*. Es falso. Rechazar **no**
des-autentica: el token sigue en `TokenStorage`, `ContextListener` es un listener
global de `kernel.response`, y la propia llamada a `getToken()` de esta clase es
la que marca el firewall como ejecutado — así que el token se re-serializa en la
sesión que `invalidate()` acaba de regenerar. Medido en vivo contra el stack,
**siguiendo la cookie regenerada**: `GET /api/v1/sessions` → `401
session-expired`, y `GET /api/v1/health` — que es `PUBLIC_ACCESS`, el ejemplo que
el propio docblock elegía como titular — → **`401 session-expired`** también. Mi
primera sonda no lo vio porque reenviaba la cookie rancia en vez de la nueva; esa
es exactamente la diferencia entre medir el precondicionante y medir la
consecuencia.
*Cerrado:* docblock reescrito a lo que la medición dice, más un escenario Behat
que fija la consecuencia en la ruta donde las dos lecturas difieren (`/health` →
401). Un cambio que sí des-autenticase pondría ese escenario rojo, que es el
punto. Lo que la invalidación **sí** compra queda dicho y acotado: borra la
correlación `iamSessionId`, así que el segundo rechazo y los siguientes
cortocircuitan antes de `findActiveById`. Ni ese ahorro ni su precio (destroy +
regenerate + `Set-Cookie` por petición rechazada) están medidos, y ahora se dice
así en vez de afirmarse como una victoria.

**A2 — El artefacto de evidencia afirmaba de sí mismo una completitud que no
tiene, en el párrafo donde se felicitaba por su honestidad.** La versión anterior
señalaba el ITEM 53 como la única fila sin mutación propia y sostenía que la
línea de la DoD *«cada fila tiene mutación independiente»* valía para «las 36
filas que cambiaron código». Son **cuatro** las filas sin mutación independiente,
y la tabla lo decía ella misma: 79 (`falsador: none — docblock`), 80 (`falsador:
None — prose`), 73 (cuyo falsador es «el propio paso», circular) y 53. Dos de
ellas son **más débiles** que la que se señalaba como excepción.
*Cerrado:* la contabilidad real es **32 de 36** filas con mutación propia, con
las cuatro exceptuadas por nombre en una tabla y el motivo de cada una. La
cabecera de la sección B decía «one per closed bullet (37)» sobre 36 filas
(`54+67` es una fila para dos balas); ahora dice qué unidad cuenta.

### SERIOUS

**S1 — El gate de integridad del registro tenía un agujero justo donde afirma su
valor.** `ALLOWED_MUTATED_SURVIVOR = "audit-metadata-keys"` se buscaba **por
subcadena en la mitad AÑADIDA**, y ese token **no aparece en el registro** ni en
la base ni en HEAD. Consecuencia medida: reescribe cualquier otro superviviente
metiéndole ese literal, deja el ITEM 41 intacto, borra las 45 — y la aritmética
cuadra entera (`head`=53, `deleted`=45, `allowed_edits`=1) con **exit 0 sobre un
superviviente reescrito en silencio**, que es la dirección que el docstring del
gate dice existir para cerrar.
*Cerrado:* la excepción se ancla ahora en texto presente en la forma **base** del
ITEM 41 y se exige en **las dos mitades**. Falsificado en tres direcciones, y las
tres se ejecutaron: (A) estado final real → `OK`, exit 0, 53 viñetas / 45
borradas — que es además la prueba de que la aritmética del spec es alcanzable;
(B) el agujero exacto → **exit 1** con los conteos igualmente cuadrados, que es
por lo que el gate viejo lo dejaba pasar; (C) ITEM 41 borrado en vez de editado →
exit 1, invariante nueva que el gate no tenía.

**S2 — Una casilla `[x]` de seguridad quedó falsa por culpa de esta misma rama.**
`PRODUCTION_SECURITY_CHECKLIST.md` afirmaba que «los once sitios
`#[StrictRequestPayload]` declaran `acceptFormat: ['json']`» y prescribía
verificarlo con un `git grep`. Tras voltear el default, **cero** sitios lo
declaran: el criterio de aprobación del propio checklist fallaba contra su propio
árbol. La receta antigua tampoco podía funcionar, porque ese grep casa también
las once menciones en docblocks junto a los trece sitios reales.
*Cerrado:* enunciado reescrito donde la garantía vive de verdad (el default del
tipo), con una receta verificada, y la prosa rancia equivalente corregida en el
docblock del 415 de `ProblemDetailsFactory` y en la línea del endpoint de cambio
de contraseña.

**S3 — El doble in-memory de la guarda de último admin discrepa del adapter en
mayúsculas, en el eje que esta rama declaró importante.** El adapter usa
`strcasecmp` en sus dos mitades; el doble usaba `!==` y una búsqueda de clave
sensible a mayúsculas. `Uuid::ensure()` valida sin normalizar y
`Symfony\Component\Uid\Uuid::isValid` admite ambas grafías, así que un id en
mayúsculas llega a la guarda desde la ruta: el adapter responde `false` (409) y
el doble respondía `true` (permitir). Esta rama añadió el test de mayúsculas *del
adapter* y dejó el doble divergente, con lo que cada test unitario que lo usa era
un verde sobre la respuesta contraria.
*Cerrado:* doble hecho insensible a mayúsculas en ambas mitades, con su falsador
(`ChangeUserStatusTest::testTheLastActiveAdministratorIsProtectedUnderAnyUuidCasing`).
Revertido el doble a sensible: rojo en **exactamente** ese test, 1 de 9.

**S4 — La mitad de controlador del ITEM 17 no estaba fijada por nada.** Las
respuestas son byte-idénticas con y sin la lectura del atributo, así que solo el
**coste** las distingue, y `session.feature` tenía una única aserción de conteo de
queries, sobre `/me`. Revertir ambos controladores a su propia consulta dejaba la
suite entera verde. La fila de evidencia describía una mutación **emparejada**
(gate + controladores a la vez), que por construcción no puede distinguir qué
mitad está fijada.
*Cerrado:* aserción de conteo sobre `GET /sessions`. Medido: 2 queries. Revertido
el controlador: `Failed asserting that 3 matches expected 2`, rojo en exactamente
1 escenario.

**S5 — `ClearLockoutOnLoginSuccess` afirmaba un remedio que su propio test no
mide.** El docblock decía que soltar la sesión hace que el 503 «no deje nada
admitido detrás». No es así, por el mismo mecanismo de A1:
`AuthenticatorManager` pone el token en storage antes de despachar el evento y
`ContextListener` lo re-serializa en la sesión regenerada. El test nuevo mira
`$session->has('_security_main')` justo al volver el listener — aguas arriba del
listener de `kernel.response` que lo vuelve a poner — mientras su mensaje de
aserción enuncia la propiedad de extremo a extremo.
*Cerrado:* docblock reescrito al beneficio que sí existe y es estrecho — en un
re-login estando ya dentro, `SessionStrategyListener` migra la sesión con sus
datos intactos, así que una correlación `iamSessionId` viva sobreviviría a un
fallo que no puede acuñar su reemplazo; `invalidate()` la borra.

**S6 — `docs/api-error-contract.md` (obligatorio por NFR26) era falso en tres
formas.** Nombraba `#[MapRequestPayload]`, que `StrictRequestPayloadGateTest`
prohíbe en todo `api/src`; decía que las rutas declaran `acceptFormat: ['json']`,
que ya no es cierto en ninguna; y afirmaba que «every write endpoint here declares
it», cuando cuatro POST no mapean payload alguno y por tanto no producen 415
(`/login` responde **400** desde `json_login`, y tres rutas no llevan cuerpo).
*Cerrado:* los tres enunciados corregidos, con el 400 de `/login` dicho
explícitamente porque es la lectura que un cliente haría mal.

### Registrado y NO arreglado, con su motivo

- **Los ITEMs 81 y 82 endurecen pasos clasificados `idle`** en
  `api/.behat-step-vocabulary` — ningún escenario los alcanza. Los cierres son
  reales (sus falsadores unitarios existen y se ponen rojos), pero la «condición
  original» de esas dos filas se lee como un falso-verde vivo en la suite de
  aceptación cuando ninguna de las dos formas puede producirla un escenario del
  árbol. Para el 82 es estructural: `iConsume`/`iConsumeWithTimeLimit` reciben un
  `string $transportName`, así que el camino de cero receivers solo es alcanzable
  por el paso CLI crudo, que está ocioso. No se toca porque endurecer un paso
  ocioso es exactamente lo que la regla de la casa pide («la gramática es un
  activo que se gasta»); lo que faltaba era decirlo.
- **Son siete supresiones de PHPMD, no seis, y tres no llevan número medido.**
  Ninguna sobre clase de producción (53 en `api/src` en la base y 53 en HEAD).
  Cuatro llevan medición y alternativa descartada; las tres de
  `BankAccountSubjectErasureRaceFunctionalTest`, `LoginPreIdentityOpacityFunctionalTest`
  y `ResetPasswordDeadTokenOpacityFunctionalTest` no. **La respuesta a la pregunta
  abierta que los commits plantean y no zanjan:** el umbral no se transfiere
  uniformemente y contestarlo en bloque es el error. `TooManyPublicMethods` sí
  deja de aplicar en una clase de test — la métrica aproxima «esta clase tiene
  demasiadas responsabilidades» y ahí cada método público **es** un caso, así que
  fusionar dos para bajar a 10 destruye lo único que la suite te da en una
  regresión: qué caso rompió. `CouplingBetweenObjects` **sí** se transfiere en
  parte: un recuento alto de imports en un test suele ser la señal de que el test
  monta más maquinaria de la que su afirmación necesita. Por eso las dos
  supresiones que aguantan el escrutinio son las que **enseñan** que los imports
  son el ensamblaje del propio sujeto. Regla: `TooManyPublicMethods` en tests, con
  una línea de motivo; `CouplingBetweenObjects` solo con el número y la
  descomposición. Las tres de arriba deberían enunciarlo o mirarse — se registra
  aquí en vez de tocarlas, porque son ficheros que esta rama no abrió por otro
  motivo.
- **El ITEM 47 hace que `FixturesChangeTracker::hasChanged()` sea prácticamente
  constante `true`.** Toda petición autenticada escribe `iam_session`/`audit_log`
  por DBAL crudo, así que casi todo escenario marca ahora y paga restore, y la
  optimización de clonado de plantilla queda en gran parte gastada. Es la
  dirección segura (la BD cambió de verdad), el cambio es estrictamente aditivo
  como se afirmaba, y el coste medido no se materializó — pero es más de lo que
  el docblock sugiere y no estaba dicho.
- **`admittedSession()` y `currentSession->get()` ya no están atados por
  construcción.** Antes salían de una sola lectura con el mismo id; ahora uno
  viene del atributo publicado en `kernel.request` y el otro se re-lee en el
  controlador, y nada los compara. Busqué una divergencia viva y **no la hay**
  (el único escritor de `iamSessionId` a media petición es el listener de acuñado
  vía `Security::login()`, en una ruta que no lee el atributo), pero la invariante
  se sostiene hoy por coincidencia y no por construcción.
- **`AuditWriteOperationParityTest` no fija lo que la bala pedía «por separado».**
  Compara el enum con el espejo del PWA, pero no lee `AuditWriteCaptureListener`,
  así que mutar `$diff['operation'] = $operation->name` lo deja verde. La
  propiedad **sí** está cubierta, por tres aserciones de string preexistentes en
  el test funcional del listener; lo que no está es aislada.
- **El default de `resourceErased` falla hacia abierto.** La API lo declara
  `public bool` no anulable en los dos recursos, así que la forma ausente no tiene
  productor hoy; el `?? false` elegido significa que si algún día falta, un
  recurso borrado se pinta como no-borrado. Los dos docblocks enuncian ese coste,
  y `true` habría sido el default conservador. Se deja como está por decisión
  explícita de la bala (tolerar la **ausencia**, nunca la corrupción) y porque el
  type-guard sí rechaza `null`, `"false"`, `42`, `0` y `{}` — verificado.
- **Los dos gates de paridad nuevos quitan comentarios con un regex ingenuo**
  (`#/\*.*?\*/#s`, `#//[^\n]*#`) sobre fuente TypeScript, así que un `"https://…"`
  dentro de un literal borraría código real. No se dispara hoy en ninguno de los
  tres ficheros que leen.
- **`api-test` sigue declarando `timeout-minutes: 15`** mientras el job corre
  ahora la suite unitaria **dos veces** (sin instrumentar y bajo cobertura
  Xdebug). No se sube a ojo: el número al que subirlo lo da la primera ejecución
  post-merge.
- **El test de carrera del ITEM 37 no conduce la pata que la bala describe.**
  Conduce el lado del poseedor y la lectura de ausencia post-commit sobre la
  conexión rechazada; el «T2 bloquea → despierta → relee bajo su propio lock →
  no-op» no se ejecuta. El docblock ya es honesto en que el contendiente es SQL
  cruda y argumenta por qué; lo que no dice es que esa pata queda sin conducir.
  El cambio de producción **sí** está falsado (revertir `findByIdForUpdate` pone
  rojo `assertTrue($this->contenderBlocked)`).

### Menores cerrados en el paso

Contabilidad del ITEM 86 (`exit 2` era vocabulario de *error*; un `assertSame`
fallido es *failure*, exit 1) · conteos rancios en las Design Notes del spec
(«49 … 22» → 53 / 24, con la ironía anotada) · docblock de
`DoctrineActiveAdministratorDirectory` que aún definía el conjunto por «whose id
differs» · la justificación de `hasSession()` en `refuse()`, que describía como
protección de producción lo que es un contrato de test, y el «response shape is
unchanged», que es cierto del cuerpo y del estado pero no de la respuesta ·
precondición ausente en el docblock del puerto `findByIdForUpdate` (con la
entidad ya gestionada el lock pasa por un refresh que devuelve el snapshot
rancio) · fixture del ancla de verbos de escritura, que no podía ver el ancla que
decía fijar (`INSERT_BANK` falla el `\b` por sí solo; ahora es un token desnudo, y
borrar `^\s*` pone rojo 1 de 10) · predicado `table_schema` ausente en la lectura
de `information_schema` · fichero de sonda `tempnam()` sin borrar · afirmación de
tiempos de Behat, que citaba una muestra única (tres ejecuciones dieron 45s, 40s y
36,22s, dispersión mayor que el efecto afirmado).

### Segunda ronda (Blind Hunter + Edge Case Hunter, paso 4 del workflow)

Dos revisores más, en contexto fresco y solo-lectura, sobre el diff completo
(86 ficheros) y con la primera ronda ya aplicada. Encontraron **ocho** cosas que
tres pases anteriores y el autor no vieron, y la primera es contra el arreglo de
la primera ronda.

**B1 — el escenario que cerraba el GRAVE A1 no falsaba nada, y lo demuestra una
mutación.** El escenario hacía **una** petición: la del rechazo. Sobre ella la
correlación todavía existe, así que el gate refusa igual — en la base y en HEAD.
La afirmación corregida de A1 es sobre la petición **siguiente**, la que lleva la
cookie regenerada. Medido: con `refuse()` limpiando el token (un cambio que sí
des-autentica) el escenario seguía **verde, 19/19**. Escribí un test, lo vi verde
y le atribuí una propiedad que no puede ver — la misma clase de defecto que esta
PR existe para erradicar, cometida dentro de ella.
*Cerrado:* reescrito a **dos** peticiones — `/me` (el rechazo, que invalida y
regenera) y luego `/health` sobre la cookie nueva. Con la mutación de
des-autenticación: `Response status code is 200, expected was 401`, rojo en
exactamente 1 escenario. Ahora sí es falsador.

**B2 — el copy del ITEM 64 es falso en un camino alcanzable.** `ResetPasswordForm`
pinta esa misma pared cuando la URL **no trae token**, y su propio docblock lo dice
(«A missing or dead token collapses to the neutral invalid-link wall»). A alguien
que no envió nada se le decía primero que su contraseña nueva está activa. Es una
regresión que introduce esta rama, y el test de la rama no podía verla porque
renderiza la variante directamente. *Cerrado:* la condición pasa a ser sobre lo
que hizo **la persona** («si ya estableciste una contraseña nueva con este
enlace»), que es evaluable en los dos caminos.

**B3 — `accountMenuItem` esquivaba el filtro de permisos nuevo.** `NavPermission`
está declarado también en `NavSubItem`, y el menú del avatar renderiza desde
`accountMenuItem`, que no pasa por `permittedMenuGroups`: un `permission` en
«Active sessions» compilaba, pasaba lint y tests, y se pintaba para toda sesión.
*Cerrado:* `permittedAccountEntries` + su cableado, más un caso que refusa un
`permission` a nivel del item padre, que ninguna superficie honraría.
**Y el falsador del cableado costó dos intentos**: los dos primeros casos llamaban
al helper directamente y la mutación (volver a `accountMenuItem.subItems` en
crudo) los dejaba verdes — medido. Nada conductual puede verlo tampoco, porque hoy
ninguna entrada declara permiso y las dos listas son iguales. El cableado se lee
del fuente, que es el único instrumento que se pone rojo ahí: 1 de 9 rojo con la
mutación, 9 de 9 restaurado. Un verde ahí prueba que la llamada existe, nunca que
el filtro sea correcto — eso lo prueban los dos casos anteriores.

**B4 — una misma caída del store daba dos respuestas en una petición.**
`convertingStoreFailure` envolvía las dos lecturas y no el `UPDATE` masivo, y
`POST /sessions/revoke-others` alcanza las dos: qué código recibía el llamante
dependía de en qué sentencia moría la conexión — 503 en la lectura, 500 crudo en
la escritura. *Cerrado:* toda sentencia del adaptador pasa por la conversión.

**B5 — el pareo de las dos mitades de la edición permitida era por cardinalidad,
no por identidad.** Es el agujero de S1 un nivel más abajo: borra el ITEM 41 junto
a otras 44 y reescribe un superviviente con el ancla, y todo cuadra. *Cerrado:* la
mitad de cabeza debe **empezar por** la de base, que es la forma exacta del cambio
sancionado (añadir una línea) y refusa cualquier reescritura. Falsificado con el
estado que B5 describe.

**B6 — el doble in-memory tenía un tercer miembro sensible a mayúsculas.** La
primera ronda arregló dos de tres; `holdsAdministratorRoleForUpdate` se quedó.
*Cerrado.*

**B7 — la limpieza del fichero de sonda solo corría en el camino verde**, que es
justo el escenario que se repite ahora que CI ejecuta la suite dos veces.
*Cerrado con `finally`.*

**B8 — dos derivas documentales y un sobre-enunciado.** El spec nombraba un
fichero de test inexistente; el comentario de `bank/access_control.feature` decía
que los 2xx concedidos corren como MANAGER cuando esta rama le añadió los de
EDITOR; y los dos tests de opacidad decían comparar «whole responses» comparando
cuerpo + `Content-Type`. Los tres corregidos — el último enunciando qué queda
fuera y por qué ampliarlo sería una decisión, no una omisión.

### Registrado y no arreglado en esta segunda ronda

- **`permittedItem` tiene dos bordes latentes** (`menuAccess.ts:31,35`): un
  `permission` a nivel de padre se lleva por delante sus hojas no gateadas, y un
  `subItems: []` declarado hace desaparecer la entrada para cualquier sesión. Ni
  una forma ni la otra existen hoy en el modelo, y `permittedMenuGroups` está
  exportado, así que son alcanzables desde fuera. No se tocan: cambiar la
  semántica del filtro sin un consumidor que la pida es el código especulativo que
  esta PR rechaza en 27 balas.
- **El test de opacidad de invitaciones se queda en el estándar débil** (afirma
  `type`/`title`/`status` por caso) mientras sus dos hermanos comparan cuerpo
  entero. La rama lo tocó para bajar una afirmación falsa, no para subirlo. Queda
  registrado como decisión, no como olvido.
- **`MessengerTransports::receiver()` lanza antes** para un nombre de transporte
  desconocido, así que el mensaje de la aserción de cero receivers nombra un caso
  al que no llega — el resultado sigue siendo rojo, pero por otra razón.

### La Ola G ya no es el último commit, y esto es por qué

La regla dice «el ÚLTIMO commit de la rama, sin excepción», y la segunda ronda de
revisión llegó después de aplicarla. Hay tres salidas y solo una es honesta.

Fundir los arreglos dentro del commit de la Ola G haría que un commit titulado
«borrar las viñetas cerradas» llevara cambios en el TCB de autenticación, en el
adaptador de sesiones y en el PWA — ilegible para quien revise. Reordenar la
historia exige reescribir una rama ya pusheada, que es justo el gesto del que
CLAUDE.md advierte cuando otra sesión puede estar apuntando a ella. Queda la
tercera: commitear aparte y decirlo.

**Lo que la regla protege sigue intacto, y es comprobable.** Su propósito es que
el registro no corra por delante del trabajo: ninguna viñeta puede borrarse antes
de que su implementación, su falsador y su independencia estén verificados. Eso se
cumple — los arreglos de la segunda ronda **endurecen** trabajo cuyas viñetas ya
estaban verificadas cuando se borraron, y **ninguno reabre una bala ni añade,
quita o reescribe una viñeta**. El gate del registro se ejecuta después de ellos y
sigue en verde con los mismos números (98 base, 45 borradas, 0 añadidas, 53
supervivientes, 1 edición permitida), que es la evidencia y no la promesa.

Lo que sí se pierde es la propiedad *«el head del registro es lo último que tocó
esta rama»*, que hacía la lectura del historial trivial. Se paga a cambio de un
historial legible y de no reescribir una rama publicada, y se registra aquí en vez
de dejar que quien revise lo descubra.

### Lo que un verde aquí NO prueba

Esta sección registra la forma y el contenido de la lectura hostil, no su
suficiencia. Las tres lentes se solapan pero no agotan la rama: nadie atacó el
PWA más allá del menú, los repositorios de auditoría y `AccessWall`; nadie
ejecutó la suite de mutación completa; y las mediciones vivas se hicieron contra
un stack de desarrollo de un worktree, no contra producción. Dos de los hallazgos
GRAVE fueron afirmaciones **documentales** falsas, no defectos de comportamiento
— lo que dice algo sobre dónde mira esta clase de revisión y dónde no.

### Tercera ronda — revisión de diseño (DDD / SOLID / Tell-Don't-Ask / Demeter / DRY-KISS-YAGNI)

Lectura hostil en tres contextos frescos y de solo lectura, uno por lote
(`api/src`, `pwa/src`, tests+Behat), consolidada y **verificada hallazgo a
hallazgo contra el árbol y contra `vendor/`** antes de tocar nada. Corrió sobre
`5c6a95cf`, que incluye el merge de `main` posterior a la Ola G — la tabla de
verificación de la segunda ronda se midió sobre `9453c9fe` y no cubría ese head.

Cambió el resultado, no lo confirmó.

**GRAVE — el adaptador de sesiones afirmaba un universal que no cumplía.**
`DoctrineSessionRepository` documentaba *«any DBAL failure on any statement»* y
*«EVERY statement in this adapter goes through it»*, y **tres de sus seis
métodos** quedaban fuera de `convertingStoreFailure()`: `save()`,
`deleteAllForUser()` y `deleteRetired()`. La justificación que el propio helper
da para existir se realiza en uno de los tres: el borrado de identidad admite
por `findActiveById` (503) y luego borra por `PurgeUserSessions` →
`deleteAllForUser` (500 crudo), o sea una avería contestada con dos estados en
una petición — exactamente lo que el helper dice impedir. Los seis pasan ahora
por la guarda, y el puerto declara `@throws` en los seis en vez de solo en
`findActiveById`.

**SERIO — `findByIdForUpdate` exportaba hacia arriba una precondición que el
adaptador puede imponer.** El docblock del puerto de dominio cedía al llamante
la obligación de no tener el agregado ya cargado, en vocabulario de `UnitOfWork`
de Doctrine. Verificado en `vendor/doctrine/orm/src/EntityManager.php:327-346`:
con acierto en el mapa de identidad, `find(..., PESSIMISTIC_WRITE)` enruta por
`EntityPersister::refresh()` y **devuelve la instancia gestionada igualmente**,
así que un llamante que ya lo hubiera cargado recibiría una foto viva de una
fila borrada y el borrado informaría de un registro que no borró. Ahora es una
lectura DQL con `setLockMode` + `HINT_REFRESH`: cero filas es `null` sea cual
sea el estado de la unidad de trabajo. El puerto pierde doce líneas de contrato
delegado. Pinchado por `BankAccountLockingReadFunctionalTest`, **medido en ambas
direcciones**: con `find()` el caso enrojece, con la consulta pasa.

**SERIO — el filtro de permisos del menú se aplicaba en 1 de 3 superficies.**
`permittedAccountEntries` alimentaba solo el desplegable de la barra superior;
el pie del sidebar (`withEntryState(accountMenuItem)`) y el cajón móvil
(`accountMenuItem.subItems?.map`) pintaban el modelo crudo. `menuAccess.ts`
afirmaba lo contrario como hecho. El propio diff denuncia esa forma dos veces
—el comentario de `withEntryState` la llama *«the same half-support this diff
removes from the mobile drawer»*— y la reintroducía al lado. Ahora se filtra una
vez a un item y las tres superficies leen de él. El falsador es de contención,
no una enumeración de las tres: `accountMenuItem.subItems` puede leerse
**exactamente una vez**, así que una cuarta superficie tampoco alcanza la lista
sin filtrar. Provocados sus dos brazos por separado.

**SERIO — `resourceErased ?? false` fallaba ABIERTO en el eje GDPR.** La
tolerancia a la ausencia está argumentada y se conserva; lo que estaba mal es la
**dirección**. El argumento escrito («que no se pierda la pantalla entera»)
justifica admitir la ausencia, no resolverla con el valor permisivo: en esa
ventana cada pseudónimo se trataba como identificador vivo y se ofrecía el pivote
`follow-resource` sobre él, que es justo lo que la bandera existe para impedir.
La asimetría lo delata — `actorErased`, la bandera hermana de la misma fila,
rechaza la ausencia de plano. Ahora `?? true`: se pierde función en la ventana,
nunca se divulga. Falsado en los dos ficheros.

**MENORES cerrados** — `'--verbose' => VERY_VERBOSE` en el contexto de Behat,
cuando `Application::configureIO()` lo resuelve a nivel UNO
(`vendor/symfony/console/Application.php:1017`), de modo que el contexto era más
generoso que la CLI con la que declara paridad (corregido y pinchado; falsado:
128 vs 64); el guardián de `AuditWriteOperationParityTest` exigía la anotación de
tipo y habría enrojecido con un mensaje falso al quitarla; la dirección
fail-open del `is_string` en `keepsAnActiveAdminWithout`, colapsando además dos
pasadas en la frase que el comentario ya escribía; dos comentarios relativos al
cambio (`SessionAdmissionGate`, `MessengerConsumerContextTest`) y un «dos/tres»
sin antecedente; `NavPermission` sin exportar, redeclarada estructuralmente en su
único consumidor; dos bloques JSDoc apilados que dejaban `permittedMenuGroups`
sin documentar y su docblock colgando de otra función; orden de argumentos
inconsistente entre las dos exportadas del módulo; y `'BankAccount:' . $id`
reconstruido a mano en vez de pedírselo a `EncryptionScopeId::forBankAccount()`.

**Registrado y NO arreglado, con su motivo**

1. **El estático `SessionAdmissionGate::admittedSession()`.** Dos controllers
   dependen de una clase concreta de `Infrastructure\Security` por llamada
   estática, y «resolver la sesión admitida o 401» se escribe ahora **tres**
   veces. El arreglo correcto es un puerto de Application
   (`CurrentAdmittedSession`) junto al `CurrentSessionReference` que ya existe y
   ya posee exactamente esa clase de seam — pero es puerto nuevo + adaptador +
   cableado en la ruta de autenticación, o sea una refactorización, no la regla
   del boy-scout. Decisión del propietario.
2. **`keepsAnActiveAdminWithout` es un nombre que miente.** Devuelve `true` para
   el conjunto vacío, donde no se «keeps an active admin» en absoluto, y el
   docblock gasta ocho líneas explicando que el nombre no significa lo que dice.
   Renombrar cruza interfaz, dos implementaciones, llamantes y tests.
3. **El motor de lectura de fuentes PWA está en cinco clases de gate.**
   `repoRoot()` ×5, `read()` ×6, `withoutComments()` ×3 + `PhpSource`, cuando la
   regla del repo pone los motores en `api/tests/Support/`. Esta rama añadió las
   copias 4 y 5. **Medido:** el riesgo que hace subtil a `withoutComments`
   —`#//[^\n]*#` se come el resto de cualquier línea con `https://`— es
   **latente, no vivo**: cero ocurrencias de `://` en los tres corpus que leen
   los gates nuevos. Arreglarlo bien exige entrar en tres ficheros que esta rama
   no abre.
4. **`InMemoryPasswordResetTokenRepository` diverge del adaptador tras
   `deleteAllForUser`.** El doble borra de su índice y `findById()` da `null`; el
   `DELETE` DQL real no desaloja el mapa de identidad, así que en la misma unidad
   de trabajo puede devolver la entidad. Benigno hoy (la lectura ocurre en otra
   petición).
5. **`StrictRequestPayload` conserva `null` en la unión** de `acceptFormat`, que
   es la única forma de reabrir con una tecla el agujero que el nuevo default
   cierra. Ningún llamante lo necesita; estrecharlo es una decisión de firma.
6. **Tres sitios afirman «estas N negativas son indistinguibles» con tres
   fuerzas distintas**, y `InvitationAcceptFunctionalTest` es el débil.
7. **`ChangeUserStatusTest` casing**: el caso no puede falsificar lo que su
   nombre afirmaba — `ChangeUserStatus` no compara ids en absoluto, delega. Se
   renombró para decir que su sujeto es el DOBLE (que sí merece el pin), pero la
   forma correcta sería un test de contrato sobre el puerto ejecutado contra el
   doble y contra el adaptador.

**Verificación de esta ronda** (todas sobre `5c6a95cf` + los arreglos, código de
salida impreso, no reutilizado):

| Gate | Exit | Medido |
|---|---:|---|
| `make php.quality.dry-run` | 0 | la variante que corre CI |
| `make pwa.quality.dry-run` | 0 | |
| `make php.unit` | 0 | 3315 tests, 15278 aserciones, 2 skipped |
| `make pwa.test.unit` | 0 | 250 ficheros, 1584 tests |
| `make php.behat` | 0 | 471 escenarios, 4374 pasos |

**Lo que un verde aquí NO prueba.** Nadie ejecutó `pwa.test.e2e` en esta ronda,
que la segunda ya declaraba no-verde por ruido de entorno. Los tres lotes leyeron
el diff, no la rama entera: un defecto en código que la rama no toca es invisible
a esta lectura. Y la ventana de F2 —una API retrocedida entre #375 y #636— no se
verificó como topología de despliegue realmente alcanzable; si no lo es, lo
correcto no es la dirección segura sino borrar la tolerancia entera, que es la
lectura YAGNI y sigue siendo decisión del propietario.
