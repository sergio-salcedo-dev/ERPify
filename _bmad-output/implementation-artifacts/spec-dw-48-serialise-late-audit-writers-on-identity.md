---
title: 'DW-48 — serializar los escritores tardíos de auditoría sobre identity_user'
type: 'bugfix'
created: '2026-09-29'
status: 'done'
baseline_revision: 'd186780bba77fbc6859079d448049b8a709d4aab'
review_loop_iteration: 0
followup_review_recommended: false
context:
  - '{project-root}/docs/adr/audit-activity-log.md'
warnings: ['oversized']
deferred:
  - summary: >-
      Otros dos escritores post-commit nombran al sujeto en resource_id sin bloquear identity_user: RecordLockoutNoticeAuditBestEffort y RecordRecoverySecretAuditBestEffort.
    evidence: |-
      RecordRecoverySecretAuditBestEffort::record() (L118-127) escribe AuditResource::of(User, $userId) tras el commit de Mint/Redeem/RevokeRecoverySecret sin transacción ni bloqueo; RecordLockoutNoticeAuditBestEffort igual tras NotifyLockedIdentities::save(). Mismo defecto que DW-48 pero fuera de los dos escritores que nombra el intent; el del aviso es riesgo aceptado (@accepted-risk #860, cuyo razonamiento "aggregate-wide concurrency policy" conviene reabrir ahora que el mecanismo existe). Su residuo lo señala identity:gdpr:reconcile-subject-references; el docblock de DbalAuditSubjectRowLock y el ADR ya los nombran como NO serializados.
    location: >-
      api/src/Iam/Identity/Application/RecordRecoverySecretAuditBestEffort.php:118; api/src/Iam/Identity/Application/RecordLockoutNoticeAuditBestEffort.php
    severity: medium
---

<intent-contract>

## Intent

**Problem:** `RecordLockoutAuditBestEffort` (post-commit, id en mano) y `RecordRecoveryThrottleAuditBestEffort` (`findByEmail` sin bloqueo desde `kernel.terminate`) escriben filas `audit_log` que nombran al sujeto sin disputar la fila `identity_user`, así que una `USER_LOCKED` / `PASSWORD_RECOVERY_THROTTLED` puede confirmarse después de la pasada del eje recurso de la erasure y quedar con `resource_id` real, `resource_erased = FALSE` y metadatos de petición.

**Approach:** Cada escritor abre su propia transacción (`TransactionManager`), toma el bloqueo de la fila del sujeto (`UserRepository::findByIdForUpdate` / `findByEmailForUpdate`) y escribe la fila dentro; si la identidad ya no existe, el lockout omite la fila y el throttle la escribe sin recurso. La escritura queda entonces antes de la erasure (y su pasada la redacta) o después (y no encuentra sujeto).

## Boundaries & Constraints

**Always:** orden de bloqueo `identity_user` → `audit_log`, el mismo de `FulfilIdentityErasure` (sin ABBA); nada escapa de ninguna de las dos clases (lookup, bloqueo, transacción, escritura y claim del presupuesto dentro del `try`); informe por `ReportsAuditFailureSafely` al canal `observability` sin id ni dirección; el claim del presupuesto sigue ANTES de la transacción y una vez por dirección y ventana; la transacción del escritor de lockout es distinta de la de `LoginAttemptRegistrar` (post-commit: un INSERT fallido nunca revierte el bloqueo); la dirección nunca aparece en la fila.

**Never:** escribir la auditoría dentro de la transacción de `LoginAttemptRegistrar::commitUnderLock`; bloquear `audit_log` antes que `identity_user`; tocar `deferred-work.md`; cambiar el wiring de canal en `services.yaml`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Lockout, identidad viva | `record($id)`, fila existe | 1 fila `USER_LOCKED` con recurso `User/$id`, escrita con la fila bloqueada | — |
| Lockout, identidad borrada | fila ya no existe | ninguna fila | — |
| Throttle, dirección resoluble | presupuesto concedido, fila existe | 1 fila con recurso `User/$id` | — |
| Throttle, identidad borrada / dirección desconocida / malformada | — | 1 fila sin recurso | — |
| Erasure en curso (fila bloqueada por otra transacción) | ambos escritores | esperan al bloqueo; si expira (`55P03`) no se escribe nada | swallow + `error` en `observability` |
| Fallo de escritura/transacción | cualquier `Throwable` | nada escapa | swallow + `error` sin id/dirección |

</intent-contract>

## Code Map

- `api/src/Iam/Identity/Application/RecordLockoutAuditBestEffort.php` -- escritor post-commit (L77–91); gana `UserRepository` + `TransactionManager`.
- `api/src/Iam/Identity/Application/RecordRecoveryThrottleAuditBestEffort.php` -- `subjectOf()` L115–132 usa `findByEmail` sin bloqueo; gana `TransactionManager`.
- `api/src/Iam/Identity/Application/LoginAttemptRegistrar.php:81-87` -- llama `record()` tras el commit; no cambia.
- `api/src/Iam/Identity/Application/FulfilIdentityErasure.php:164-187` -- orden de la erasure: `holdsAdministratorRoleForUpdate` (fila `identity_user`) → delete → `beginForSubject` (audit_log). Solo lectura: fija el orden a respetar.
- `api/src/Iam/Identity/Infrastructure/Persistence/Doctrine/DoctrineUserRepository.php:56,83` -- `findBy{Id,Email}ForUpdate`: DQL `PESSIMISTIC_WRITE` + refresh; bajo READ COMMITTED una fila borrada por la transacción rival devuelve `null` tras la espera.
- `api/src/Shared/Persistence/Infrastructure/DoctrineTransactionManager.php` -- `wrapInTransaction` + reapertura del EM si queda cerrado; un fallo dentro se relanza (lo atrapa el `catch (Throwable)`).
- `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditSubjectRowLock.php:40-48` -- docblock «What it does NOT cover»: añadir que los escritores tardíos que nombran al sujeto están serializados sobre `identity_user`.
- `api/config/services.yaml:94-104` -- solo `$logger`; el resto autowired (sin cambios).
- Callers de constructores en tests: `tests/Unit/Iam/Identity/Application/{BuildsLockoutRegistrar,RecordLockoutAuditBestEffortTest,RecordRecoveryThrottleAuditBestEffortTest}.php`, `tests/Unit/Iam/Identity/Infrastructure/Security/{BuildsFailureHandler,ClearLockoutOnLoginSuccessTest}.php`, `tests/Unit/Iam/Identity/Infrastructure/Http/{RecoveryThrottleAuditListenerTest,RequestPasswordResetControllerTest}.php`.
- Dobles reutilizables: `InMemoryUserRepository` (`goneUnderLock`, `lockOrderJournal`, `forUpdateCalls`), `InlineTransactionManager` (`inside`), `FixedRecoveryThrottleAuditBudget`, `RecordingLogger`, `UserFixtureFactory`, `ObservesRowLocksOnASecondConnection` (patrón de segunda conexión).

## Tasks & Acceptance

**Execution:**
- `api/src/Iam/Identity/Application/RecordLockoutAuditBestEffort.php` -- transacción + `findByIdForUpdate`; sin identidad → sin fila; docblock explica la serialización.
- `api/src/Iam/Identity/Application/RecordRecoveryThrottleAuditBestEffort.php` -- claim fuera, transacción + `findByEmailForUpdate` + escritura dentro; docblock.
- `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditSubjectRowLock.php` -- actualizar la frontera «What it does NOT cover».
- Tests unitarios existentes -- adaptar constructores; añadir casos: escritura dentro de la transacción y con la fila bloqueada, identidad desaparecida bajo bloqueo (lockout sin fila, throttle sin recurso).
- `api/tests/Functional/Iam/Identity/LateAuditWriterErasureSerialisationFunctionalTest.php` -- Postgres real, segunda conexión: control positivo, contención (fila bloqueada fuera + `lock_timeout` → `55P03`, ninguna fila) y escritura tardía tras borrado confirmado (ninguna fila nombra el id).

**Acceptance Criteria:**
- Given la fila `identity_user` del sujeto bloqueada por otra transacción, when cualquiera de los dos escritores corre, then no se confirma ninguna fila `audit_log` que nombre al sujeto hasta que esa transacción termine (observado vía `lock_timeout`).
- Given el sujeto ya borrado y confirmado, when ambos escritores corren, then ninguna fila de `audit_log` tiene `resource_id` = id borrado.
- Given cualquier fallo en lookup, bloqueo o escritura, when `record()` corre, then nada escapa y se informa un `error` sin id ni dirección.

## Spec Change Log

## Review Triage Log

### 2026-09-29 — Review pass
- verdicts: 23 findings — high 0, medium 7, low 11, false 4, maybe-false 1
- findings:
  - `[medium]` `[defer]` (Blind) Dos escritores post-commit hermanos (aviso de lockout, recovery secret) siguen sin bloqueo — verificado en el árbol; fuera de los dos escritores que nombra el intent → `deferred`.
  - `[low]` `[defer]` (Blind) El razonamiento de @accepted-risk #860 queda debilitado por este mecanismo — es una decisión registrada en la issue; agrupado con el anterior.
  - `[low]` `[patch]` (Blind) El ADR audit-activity-log no menciona la serialización — añadida una frase al párrafo «Quién queda fuera del seam».
  - `[false]` `[reject]` (Blind) Espera de bloqueo sin cota en prod — `LoginAttemptRegistrar::commitUnderLock` ya espera sin `lock_timeout` sobre la MISMA fila en el mismo camino; ningún `FOR UPDATE` del árbol fija cota; no introduce un modo de fallo nuevo.
  - `[false]` `[reject]` (Blind) Efectos de `transactional()` (cierre/reset del EM, «rolls back only this projection» exagerado) — el reset ocurre sin transacción abierta y nada usa el EM después en ninguno de los dos caminos; `LockoutAuditWriteFailureArrivalTest` (fallo real de escritura → 401 intacto) pasa.
  - `[low]` `[reject]` (Blind) El test funcional no ejercita «esperar y re-evaluar» ni el caso de usuario gestionado en el identity map — la re-evaluación EvalPlanQual es semántica de Postgres no observable en un proceso sin pcntl; con el DQL de bloqueo una fila borrada devuelve null aunque la entidad siga gestionada.
  - `[low]` `[patch]` (Blind) El throttle no tenía test de fallo de `transactional()` — añadido `testATransactionThatFailsIsSwallowedAndLoggedAtError`.
  - `[low]` `[patch]` (Blind) El orden de bloqueo no está anclado por test — agrupado con el hallazgo de verificación de «una transacción»: la sonda nueva exige que la fila esté bloqueada en el instante del INSERT.
  - `[low]` `[reject]` (Blind) Test funcional construye los escritores a mano e importa dobles de Unit — el wiring real lo ejercita `LockoutAuditWriteFailureArrivalTest` y `php.lint.prod-container`; acoplamiento entre suites cosmético.
  - `[low]` `[reject]` (Blind) BEGIN/COMMIT también para dirección malformada — coste despreciable en `kernel.terminate`, tras el 202, acotado por el presupuesto.
  - `[medium]` `[patch]` (Edge) El docblock de DbalAuditSubjectRowLock presenta dos escritores como el conjunto completo — reescrito: sólo esos dos están serializados; aviso de lockout (#860) y recovery secret NO, y su residuo lo señala el reconciliador.
  - `[medium]` `[defer]` (Edge) RecordLockoutNoticeAuditBestEffort abre la misma ventana — agrupado en el diferido de escritores hermanos.
  - `[false]` `[reject]` (Edge) El 401 del intento que bloquea espera sin cota — idéntico a la espera ya existente de `commitUnderLock` sobre la misma fila.
  - `[maybe-false]` `[reject]` (Edge) El flush de `wrapInTransaction` en `kernel.terminate` podría confirmar cambios pendientes de la petición — la rama rechazada del forgot-password no gestiona entidades sucias; se zanjaría inspeccionando el UnitOfWork en terminate; aun siendo cierto sería `low`.
  - `[low]` `[reject]` (Edge) El mensaje dice «write failed» cuando falla el bloqueo — sin `lock_timeout` en prod sólo un deadlock (orden consistente) o caída de conexión lo provocan; el texto está anclado por tests de llegada.
  - `[medium]` `[patch]` (VerifGap) Ningún test distingue «bloqueo y escritura en UNA transacción» de dos — añadido `SubjectRowLockProbingAuditLogger` (sonda `FOR UPDATE NOWAIT` en segunda conexión en el instante de `log()`) y el caso `eachWriterWritesWhileItsOwnTransactionStillHoldsTheSubjectRow`; falsificado partiendo el escritor en dos `transactional()` → rojo; restaurado por bytes.
  - `[medium]` `[defer]` (VerifGap) Los escritores hermanos no adoptan el bloqueo — agrupado en el diferido.
  - `[medium]` `[patch]` (VerifGap) El docblock afirma más de lo que garantiza el código — mismo parche del docblock.
  - `[low]` `[reject]` (Intent) El test usa un sustituto de la erasure (segunda conexión + DELETE) en vez de `FulfilIdentityErasure` — el sustituto toma exactamente el bloqueo que la erasure mantiene hasta su commit; la redacción de filas previas ya la prueban los tests de la erasure.
  - `[low]` `[reject]` (Intent) La mitad «escritor primero, erasure después» no se prueba — es el comportamiento existente de la pasada (redacta lo confirmado), cubierto por los tests de la erasure.
  - `[medium]` `[patch]` (Intent) El alcance del docblock excede el cambio — mismo parche del docblock.
  - `[low]` `[reject]` (Intent) Un fallo de bloqueo descarta la fila del throttle — antes un fallo de BD también la descartaba; sin `lock_timeout` en prod no hay timeout que lo dispare.
  - `[false]` `[reject]` (Intent) Esperas sin cota fuera del test — ver el hallazgo equivalente del Blind Hunter.

### 2026-09-29 — Review pass (follow-up)
- verdicts: 23 findings — high 0, medium 0, low 8, false 4, maybe-false 0 (11 carried)
- findings:
  - `[medium]` `[defer]` (Blind) RecordRecoverySecretAuditBestEffort sin bloqueo — carried: ya diferido (DW-69).
  - `[low]` `[patch]` (Blind) ADR y docblock discrepan: el ADR no nombra RecordRecoverySecretAuditBestEffort como no serializado — aplicado: el ADR lista los cuatro `Record*AuditBestEffort` y nombra el aviso de lockout (#860) y el recovery secret como no serializados, con el reconciliador como detector.
  - `[false]` `[reject]` (Blind) Espera de bloqueo sin cota en prod — carried.
  - `[low]` `[reject]` (Blind) Re-evaluación tras la espera no probada — carried.
  - `[false]` `[reject]` (Blind) Efectos de wrapInTransaction sobre el EM — carried.
  - `[low]` `[patch]` (Blind) El test unitario del lockout no comprueba que el bloqueo ocurra dentro de la transacción (capturar `inside` en `onFindByIdForUpdate`, como el del throttle) — aplicado: el test captura `$transactionManager->inside` al bloquear y lo afirma; falsificado sacando el bloqueo fuera de `transactional()` → rojo; restaurado por bytes.
  - `[low]` `[reject]` (Blind) El log dice «write failed» también para fallos de bloqueo — carried.
  - `[low]` `[reject]` (Blind) El test funcional importa dobles de Unit — carried.
  - `[low]` `[reject]` (Blind) BEGIN/COMMIT para dirección malformada — carried.
  - `[low]` `[reject]` (Blind) Tests del lockout no separados como los del throttle (supresión PHPMD) — cosmético, sin daño nombrado.
  - `[false]` `[reject]` (Edge) Sin lock_timeout en prod (lockout) — carried.
  - `[false]` `[reject]` (Edge) Sin lock_timeout en prod (throttle, kernel.terminate) — carried.
  - `[low]` `[reject]` (Edge) Flush del UnitOfWork en la transacción de la proyección — carried (maybe-false previo).
  - `[low]` `[patch]` (Edge) Mutación «bloqueo fuera de transactional()» deja verde el unitario del lockout — agrupado con el patch anterior del test; aplicado (misma aserción, misma falsificación).
  - `[low]` `[reject]` (Edge) Conteos por acción inestables si otro proceso escribe en la BD de test — cada lane tiene su BD y nada concurrente escribe esas acciones.
  - `[low]` `[reject]` (Edge) tearDown con propiedades sin inicializar si setUp falla — sólo enmascara un fallo que ya es rojo.
  - `[low]` `[patch]` (VerifGap) El ADR no nombra RecordRecoverySecretAuditBestEffort y lista tres escritores de cuatro — agrupado con el patch del ADR; aplicado.
  - `[low]` `[patch]` (VerifGap) Frase rota en el docblock de DbalAuditSubjectRowLock («either … » sin «or») — aplicado: «a row from either that commits after an erasure is residue…».
  - `[low]` `[reject]` (Intent) La mitad «erasure espera y redacta» no se prueba de extremo a extremo — carried.
  - `[false]` `[reject]` (Intent) El camino 55P03 sólo existe en el test — carried.
  - `[low]` `[reject]` (Intent) Los unitarios prueban orden, no bloqueo real — descriptivo; el bloqueo real lo cubre la sonda NOWAIT.
  - `[low]` `[reject]` (Intent) Efectos sobre el EM compartido fuera de la superficie probada — carried.
  - `[low]` `[patch]` (Intent) Dos superficies de documentación discrepan — agrupado con el patch del ADR; aplicado.

## Design Notes

Tras la espera, Postgres (READ COMMITTED) re-evalúa la fila bloqueada: si la erasure la borró, `FOR UPDATE` no devuelve nada, así que el escritor ve la ausencia exactamente en el instante que importa. Si el escritor llega primero, la erasure espera en `holdsAdministratorRoleForUpdate` y su `beginForSubject` ve la fila ya confirmada y la redacta. Ningún escritor toca `iam_invitation`, así que tomar sólo `identity_user` → `audit_log` no puede cerrar ciclo con la erasure (`iam_invitation` → `identity_user` → `audit_log`).

## Verification

**Commands:**
- `make php.unit c='--filter "RecordLockoutAuditBestEffort|RecordRecoveryThrottleAuditBestEffort|LoginAttemptRegistrar|RecoveryThrottleAuditListener|RequestPasswordResetController|BuildsFailureHandler|ClearLockoutOnLoginSuccess|ProblemDetailsAuthenticationFailureHandler|LateAuditWriterErasureSerialisation|LockoutAuditWriteFailureArrival"'` -- expected: verde
- `make php.stan` -- expected: sin errores
- `make php.quality` -- expected: exit 0

## Auto Run Result

Status: done

**Resumen:** `RecordLockoutAuditBestEffort` y `RecordRecoveryThrottleAuditBestEffort` escriben su fila en una transacción propia, tras tomar el bloqueo de la fila `identity_user` del sujeto (`findByIdForUpdate` / `findByEmailForUpdate`), en el orden de la erasure (`identity_user` → `audit_log`). Sin identidad: el lockout no escribe fila; el throttle la escribe sin recurso. El claim del presupuesto sigue fuera y antes; nada escapa; el informe sigue en `observability`.

**Ficheros:**
- `api/src/Iam/Identity/Application/RecordLockoutAuditBestEffort.php` — transacción + bloqueo + omisión si la identidad desapareció.
- `api/src/Iam/Identity/Application/RecordRecoveryThrottleAuditBestEffort.php` — lookup bloqueado y escritura en una transacción.
- `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditSubjectRowLock.php` — frontera «What it does NOT cover»: qué escritores están serializados y cuáles no (frase rota corregida en el follow-up).
- `docs/adr/audit-activity-log.md` — la serialización, y los cuatro escritores tardíos con los dos no serializados nombrados.
- `api/tests/Functional/Iam/Identity/LateAuditWriterErasureSerialisationFunctionalTest.php` + `Fixtures/SubjectRowLockProbingAuditLogger.php` — Postgres real: control positivo, bloqueo mantenido durante la escritura, contención (`55P03`, ninguna fila) y escritura tras borrado confirmado.
- `api/tests/Unit/Iam/Identity/Application/RecordRecoveryThrottleAuditBestEffortSerialisationTest.php` (nuevo), `RecordLockoutAuditBestEffortTest.php` (ahora afirma el bloqueo dentro de la transacción) y los tests/builders unitarios adaptados a los constructores.

**Revisión:**
- Primer pase: 23 hallazgos; 4 entradas parcheadas (2 `medium`, 2 `low`); 1 diferida (escritores hermanos, DW-69); el resto rechazado con su motivo en el triage log.
- Pase de follow-up: 23 hallazgos (11 carried); 3 entradas parcheadas, todas `low` (ADR incompleto frente al docblock; unitario del lockout ciego al bloqueo fuera de la transacción; frase rota del docblock); 0 diferidas nuevas; el resto rechazado con su motivo en el triage log.

**Follow-up recomendado: no** — pase de follow-up sin ningún `high` parcheado (parcheadas: high 0, medium 0, low 3); el trabajo ha convergido.

**Verificación (follow-up):** falsificación del unitario del lockout (bloqueo fuera de `transactional()`) → 1 fallo en `testTheRowIsWrittenInsideItsOwnUnitOfWorkAfterTheSubjectRowIsLocked`, fuente restaurada por bytes; filtro del spec → `OK (64 tests, 213 assertions)`, exit 0; `make php.quality` → exit 0.

**Riesgos residuales:** los dos escritores hermanos siguen sin serializar (DW-69); la re-evaluación de Postgres tras la espera se argumenta, no se mide (sin pcntl para dos transacciones concurrentes en un proceso); la espera del bloqueo no tiene cota, igual que el resto de `FOR UPDATE` del camino de login.
