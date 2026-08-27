---
title: 'Barrido de deferred-work.md: 98 balas a 53 en una PR'
type: 'chore'
created: '2026-08-27'
status: 'draft'
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
- [ ] `deferred-work.md` — borrar las 8 viñetas ALREADY-RESOLVED (22, 24, 34, 40, 50, 52, 78, 90) — el defecto ya no existe. Cada borrado debe tener **evidencia trazable en el historial de la PR** (`file:line` o commit que lo resolvió): reunida en el cuerpo de la PR o en el artefacto, no necesariamente ocho citas literales dentro de un mensaje de commit.

**Ola A — falsabilidad del arnés Behat y cobertura**
- [ ] `OutboxContext.php` (81) — la forma negativa refuta sobre cola vacía — hoy pasa sin aserción alguna.
- [ ] `MessengerConsumerContext.php` (82, 84, 86) — 0 receivers ya no es exit 0; `I consume N` cuenta lo consumido; la verbosidad se resuelve por **máximo**, no por última clave.
- [ ] `RunOutcomeContext.php` (83) — `output should not contain` no pasa sobre un buffer vacío.
- [ ] `LastRun.php` (85) — `record()` refuta si la ejecución anterior nunca se leyó.
- [ ] `api/features/backoffice/bank{,_account}/access_control.feature` (51) — escenario positivo `editor → write → 2xx` en ambos.
- [ ] `api/tests/Unit/Backoffice/Bank/.../BankRealtimeAuthorizeControllerTest.php` (71) — fija topics firmados y `publish: []`, espejo del gemelo de BankAccount.
- [ ] `.github/workflows/ci.yml` (73) — paso `make php.unit` en el job `api-test`: hoy CI solo corre `php.unit.coverage` (1G, warnings relajados), así que el techo estricto de 512M y `failOnWarning` **nunca se ejercen en CI**.
- [ ] `PRODUCTION_SECURITY_CHECKLIST.md` §7 (80) — registrar como **coste aceptado** que el borrado del `Referer` es global al sitio para un problema confinado a tres pantallas.

**Ola B — auditoría**
- [ ] `api/tests/Unit/Gate/AuditWriteOperationParityTest.php` (2) — el enum PHP y sus tres literales espejo del PWA no pueden derivar.
- [ ] `api/tests/Functional/.../AuditLogEntryFieldLengthContractTest.php` (44) — `MAX_FIELD_LENGTH` contra `information_schema`, no contra un comentario.
- [ ] `AuditLogWriterIdempotencyTest.php` (45) — resolver `AuditLogWriter` del contenedor; corregir el docblock que aún dice «sin consumidor de producción» (ya hay dos).
- [ ] `TestDebugDataHolder.php` (47) — marcar `FixturesChangeTracker` también en escrituras raw-DBAL. **«Estrictamente aditivo» debe ser comprobable en el diff**: la forma es `contabilidad existente + contabilidad raw-DBAL`, nunca `contabilidad existente → contabilidad rediseñada`, porque `DoctrineContext` depende de ella. Falsador en dos direcciones: una escritura raw-DBAL **incrementa** el tracker, y una escritura ORM **conserva su semántica actual sin cambio** (un `SELECT` no marca nada).
- [ ] `AuditResourceTypeRegistry.php` (75) — memoizar el corpus. **Antes de aceptar la optimización hay que responder a su pregunta de scope:** ¿es el corpus de `api/src` constante durante toda la ejecución de `php.quality`? Si algún test crea o modifica ficheros bajo `api/src`, una segunda invocación leería estado obsoleto y la caché convertiría un gate en estado compartido incorrecto. Verificarlo primero; si no se puede garantizar, la memoización **no entra** y la bala se queda.
- [ ] `api/tests/Unit/Gate/` (91) — paridad del centinela `[REDACTED]` con `RedactedValue.tsx`, cuarto sitio sin guardián.
- [ ] `deferred-work.md` (41) — **no se implementa** (fuera por decisión); su viñeta se queda y gana una línea: el disparador ya saltó, hay 4 consumidores de metadata libre.

**Ola C — sesión**
- [ ] `SessionAdmissionGate.php` (17, 27) — rechazada → invalidar la sesión nativa → 4xx/503; admitida → adjuntar la `Session` **al atributo de ESE `Request`**, que es lo que la hace propiedad de la petición y no del worker. Prohibido explícitamente: singleton de contenedor, estado global, estado estático — bajo FrankenPHP worker mode cualquiera de los tres es contaminación cross-request. Dos mutaciones separadas (ver la partición 17/27 en *Always*).
- [ ] `MySessionsController.php`, `RevokeOtherSessionsController.php` (17) — leer esa `Session` en vez de repetir la consulta.
- [ ] `DoctrineSessionRepository.php` (92) — `findByUserId()` convierte `DbalException` en 503, como su hermana.
- [ ] `DoctrineSessionRepositoryStoreUnavailableTest.php` (93) — el fallo se dispara desde `createQuery()`, no desde `createQueryBuilder()` (que **no puede lanzar**): hoy el test es verde sobre una mutación que rompe el 503.
- [ ] `session.feature` (18, 19) — escenario de sesión caducada fuera de `GET /sessions`; test unitario del camino de **admisión** del gate.

**Ola D — identidad e invitación**
- [ ] `DoctrineActiveAdministratorDirectory.php` + doble in-memory (54+67, duplicados) — la guarda responde «¿es el objetivo el último admin?», no «¿queda algún admin?».
- [ ] `ClearLockoutOnLoginSuccess.php` (57) — invalidar la sesión en el `catch` 503, espejo del minting (`LoginSuccessEvent` ya expone `getRequest()`).
- [ ] (56) — **no se implementa** (fuera por decisión): la carrera la posee #462. Su viñeta se queda intacta.
- [ ] Tests (55, 61, 62, 63) — byte-identidad de las tres respuestas pre-identidad; fallo a-mitad de `AcceptInvitation` vía el hook `onSave` existente; sexto caso `REVOKED`; byte-identidad del reset + orden delete-before-save.
- [ ] `MigrationColumnDefaults.php` (79) — escribir el argumento de la exención de `failed_attempts`, hoy ausente.
- [ ] `deferred-work.md` (53) — solo borrar: el contrato retire-then-act **ya lo cumplen** `AcceptInvitation` y `CompletePasswordReset`.

**Ola E — contrato HTTP**
- [ ] `ProblemDetailsFactory.php` + `docs/api-error-contract.md` (20) — 415 gana `type` propio; hoy cae al cubo genérico.
- [ ] `StrictRequestPayload.php` (21) — `['json']` como default; **ver Ask First**.
- [ ] `EraseBankAccountSubject.php` + repositorio (37) — `findByIdForUpdate`: hoy dos borrados concurrentes fallan ruidosamente en vez de ser no-op. **El falsador debe distinguir idempotencia de concurrencia real**: dos llamadas secuenciales (`delete(); delete();`) no demuestran ninguna carrera y no valen como prueba. Lo que hay que exhibir es `T1 bloquea la fila → T2 intenta la misma → T1 borra y commitea → T2 observa la ausencia → T2 no-op`, con dos conexiones/transacciones (patrón `AdministratorErasureRaceFunctionalTest`). Si solo se consigue probar idempotencia secuencial, se dice así y la bala **no** se declara cerrada por concurrencia.

**Ola F — PWA**
- [ ] `AccessWall.tsx` (64) — una frase en `INVALID_RESET_LINK`: la contraseña puede haber cambiado ya.
- [ ] `backofficeMenu.ts` + `BackOfficeLayoutClient.tsx` (72) — `permission?` y filtrado en **ambos** puntos de render.
- [ ] `ApiAuditTimelineRepository.ts`, `ApiAuditEventDetailRepository.ts` (76) — la tolerancia es ante la **ausencia**, nunca ante la corrupción del contrato. Cuatro estados, y el type-guard los separa: `missing` → válido, por defecto `false`; `false` → válido; `true` → válido; `"false"` / `null` / `42` → **inválido, sigue tumbando el envelope**. Prohibido degradar el campo a `any` o aceptar cualquier valor en silencio. Falsador: un caso por estado, incluidos los tres inválidos.

**Ola G — cierre (el ÚLTIMO commit de la rama, sin excepción)**
- [ ] `deferred-work.md` — borrar las 37 viñetas cerradas (las 8 muertas ya salieron en la Ola 0) y aplicar la única edición permitida, la del ITEM 41. El orden es `implementación → falsador → suite verde → independencia de mutación → borrado del registro`, **nunca** `borrado → a ver si pasan los tests`: mientras dura el desarrollo el registro debe seguir describiendo el estado real, y una viñeta borrada sobre una implementación aún no verificada es exactamente la contabilidad falsa que esta PR existe para erradicar.

**Acceptance Criteria:**
- Dado el registro a 98 viñetas en `f86b2662`, cuando la rama termina, entonces tiene **53**: 45 menos, 0 añadidas.
- Dada cada bala cerrada, cuando se revierte **su** mutación, entonces **su** falsador se pone rojo y los de las demás siguen verdes — la independencia se demuestra por mutación, no por lectura, y una bala sin mutación propia no se declara cerrada.
- Dadas las balas 41 y 56, cuando termina la rama, entonces **siguen en el fichero** y ningún fichero de `api/src` las implementa a medias.
- Dadas las 53 viñetas que permanecen, cuando termina la rama, entonces la **única** con texto modificado es la del ITEM 41.
- Dado `make php.quality`, `make pwa.quality`, `make php.unit`, `make php.behat` y `make pwa.test`, cuando corren al final, entonces exit 0 **con el código impreso**.
- Dada una bala `trigger-gated` o `needs-decision`, cuando termina la rama, entonces **sigue en el fichero** y su texto no se ha suavizado.

## Design Notes

El valor de esta PR no es el número. Es que las 49 que quedan queden por una razón **verificada**: 27 tienen un disparador que no ha saltado y 22 exigen una decisión humana. Antes de este triaje eso era una afirmación; ahora es una medición bala a bala contra el árbol.

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

- [ ] 98 → 53 · [ ] 45 borradas · [ ] 0 añadidas
- [ ] ITEM 41 único superviviente modificado
- [ ] ITEM 41 permanece · [ ] ITEM 56 permanece idéntico
- [ ] las 27 `trigger-gated` permanecen · [ ] las 24 `needs-decision` permanecen
- [ ] existen las 37 filas de cierre · [ ] cada fila tiene mutación independiente
- [ ] mutación de 82 / 84 / 86 verificada por separado
- [ ] mutación de 17 / 27 verificada por separado
- [ ] 54/67 documentados como duplicados con mutación compartida
- [ ] los 11 endpoints del ITEM 21 auditados uno a uno
- [ ] register-gate = 0 · [ ] `php.quality` = 0 · [ ] `pwa.quality` = 0
- [ ] `php.unit` = 0 · [ ] `php.behat` = 0 · [ ] `pwa.test` = 0
