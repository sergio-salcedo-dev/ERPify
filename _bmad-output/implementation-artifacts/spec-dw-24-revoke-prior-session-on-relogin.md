---
title: 'DW-24 — un re-login revoca la sesión que la cookie ya correlacionaba'
type: 'bugfix'
created: '2026-09-29'
status: 'done'
baseline_revision: '586c60fa2e719cc27e9b0272797a2cfececce653'
review_loop_iteration: 0
followup_review_recommended: false
context:
  - '{project-root}/api/CLAUDE.md'
warnings: []
deferred: []
---

<intent-contract>

## Intent

**Problem:** `StartSession::start()` acuña una fila `iam_session` nueva y sobrescribe `iamSessionId` en la sesión nativa sin mirar qué correlacionaba antes. Un re-login desde el mismo navegador (la estrategia `migrate` conserva los atributos del bag) deja la fila previa `ACTIVE` e inalcanzable: un «dispositivo fantasma» en «mis sesiones» durante toda su ventana (hasta ~97 días, hasta que la poda la barre).

**Approach:** Decisión del 2026-09-28: antes de guardar la sesión nueva, `StartSession` lee la correlación actual (`CurrentSessionReference::get()`); si apunta a una sesión todavía admisible (`findActiveById`), la revoca (`Session::revoke()`), la guarda y publica su `SessionRevoked` por el `EventBus` **dentro de la misma transacción** que persiste la sesión nueva. La mitad (a) —fallo de la correlación post-commit— sigue siendo el trade-off documentado y acotado por la poda.

## Boundaries & Constraints

**Always:** una sola transacción para revocación previa + alta nueva (o ambas o ninguna); el evento de la revocación sale por `EventBus::publish(...$previous->pullDomainEvents())`, igual que `RevokeSession`; la correlación nueva se sigue escribiendo DESPUÉS del commit; la revocación previa se aplica sea cual sea el `userId` de la fila previa (la fila sólo era alcanzable por esta cookie, que ahora deja de apuntarla); sin correlación → ningún lookup extra.

**Never:** revocar ninguna otra sesión del usuario (no es un «log out everywhere»); tocar la mitad (a) del ledger; añadir un lock de conjunto (`lockActiveForUser`) — revocar una fila por id no cierra ciclo, como ya documenta `SessionRepository::lockActiveForUser()`; editar `deferred-work.md`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Re-login con sesión viva | bag correlaciona S1 `ACTIVE` no caducada | S1 → `REVOKED` + `SessionRevoked(S1)`; S2 `ACTIVE`; bag = S2 | — |
| Primer login | bag sin `iamSessionId` | sólo S2 acuñada; `findActiveById` no se llama | — |
| Correlación a sesión ya revocada/caducada | `findActiveById(S1)` → `null` | sólo S2; ningún evento de revocación | — |
| Fallo del store al revocar/guardar | excepción dentro de la transacción | rollback: ni S1 revocada ni S2 creada; el listener invalida la cookie y responde 503 (comportamiento existente) | fail-closed existente |

</intent-contract>

## Code Map

- `api/src/Iam/Session/Application/StartSession.php:41-55` -- punto de cambio; ya inyecta `CurrentSessionReference`, `SessionRepository`, `EventBus`, `TransactionManager`. El docblock describe la correlación post-commit: ampliarlo con la revocación de la previa.
- `api/src/Iam/Session/Application/RevokeSession.php:57-71` -- patrón de referencia: `findActiveById` → `revoke()` → `save` + `publish(pullDomainEvents())`.
- `api/src/Iam/Session/Application/CurrentSessionReference.php` -- `get(): ?SessionId` (null si no hay sesión o valor malformado).
- `api/src/Iam/Session/Domain/Entity/Session.php:96-106` -- `revoke()` lanza si no está `ACTIVE`; `findActiveById` garantiza que no.
- `api/src/Iam/Identity/Infrastructure/Security/SessionMintingSuccessListener.php` -- único llamante (prioridad -128, tras el `migrate`); convierte cualquier throw en 503 + invalidate. No cambia.
- Llamantes indirectos vía `ReauthenticateDevice` (change password, complete reset, accept invitation, redeem recovery secret): los tres primeros ya revocan todas las sesiones antes → `findActiveById` devuelve `null`, no-op. Redeem: `compensate()` revoca la sesión NUEVA (lee la correlación nueva); sin regresión.
- `api/tests/Unit/Iam/Session/Application/StartSessionTest.php` + dobles del mismo namespace (`InMemorySessionRepository`, `RecordingEventBus`, `RecordingCurrentSessionReference`, `InlineTransactionManager`) -- extender.
- `api/tests/Functional/Iam/Identity/LoginPasswordRehashFunctionalTest.php` -- patrón de login real (`KernelBrowser`, `disableReboot`, `SeedsAnOrganizationMember`, limpieza de `iam_session` en tearDown).
- Ruta «mis sesiones»: `GET /api/v1/sessions` (`iam_my_sessions`), respuesta `data[]` con `current`.

## Tasks & Acceptance

**Execution:**
- `api/src/Iam/Session/Application/StartSession.php` -- dentro del closure transaccional, revocar la sesión que `currentSession->get()` correlaciona si `findActiveById` la devuelve (save + publish), antes de guardar la nueva; actualizar docblock -- cierra la mitad (b) de DW-24.
- `api/tests/Unit/Iam/Session/Application/StartSessionTest.php` -- casos: previa activa revocada y su `SessionRevoked` publicado junto a `SessionStarted` en la misma transacción; previa inexistente/inactiva → sólo `SessionStarted`; sin correlación → sin lookup -- cubre la matriz.
- `api/tests/Functional/Iam/Session/ReloginRevokesPriorSessionFunctionalTest.php` -- login real dos veces con el mismo cliente → `GET /api/v1/sessions` devuelve 1 elemento `current: true`; la fila previa está `REVOKED`; control negativo: un segundo cliente (otra cookie) NO revoca la sesión del primero -- prueba en la superficie HTTP.

**Acceptance Criteria:**
- Given un usuario con sesión viva en un navegador, when vuelve a hacer login desde ese navegador, then «mis sesiones» lista exactamente una sesión (`current: true`) y la fila previa queda `REVOKED` con un `erpify.iam.session.revoked` en el event store.
- Given un usuario con sesión viva en el navegador A, when hace login desde el navegador B, then ambas sesiones siguen `ACTIVE`.

## Design Notes

Orden dentro de la transacción: revocar previa → guardar nueva → publicar. Se revoca aunque la fila previa sea de otro usuario (login de B sobre la cookie de A): esa fila sólo era alcanzable por esta cookie y la correlación está a punto de sobrescribirse, así que no concede capacidad nueva — quien tiene la cookie ya podía `revoke-current`. Carrera con un revoke masivo concurrente: mismo perfil aceptado que `RevokeSession` (fila única, sin ciclo de locks).

## Verification

**Commands:**
- `make php.unit c='--filter "StartSessionTest|ReloginRevokesPriorSessionFunctionalTest|LoginPasswordRehashFunctionalTest"'` -- expected: verde
- `make php.behat c='features/backoffice/identity'` -- expected: verde (login, change_password, session, recovery)
- `make php.stan` y `make php.quality` -- expected: exit 0

## Auto Run Result

Status: done

**Resumen:** `StartSession::start()` lee la correlación del bag antes de la transacción y, si apunta a una sesión todavía admisible, la revoca, la guarda y publica su `SessionRevoked` en la misma transacción que persiste la sesión nueva (orden: revocar → guardar nueva → publicar). Cierra la mitad (b) de DW-24; la mitad (a) sigue como trade-off documentado y acotado (~97 días).

**Ficheros:**
- `api/src/Iam/Session/Application/StartSession.php` — revocación de la sesión previa correlacionada + docblock (residuales y su cota).
- `api/tests/Unit/Iam/Session/Application/StartSessionRetiresCorrelatedSessionTest.php` — matriz a nivel unitario (re-login, otra identidad, previa inexistente/revocada/caducada, primer login sin lookup).
- `api/tests/Unit/Iam/Session/Application/TransactionSnapshottingManager.php` — doble que cuenta escrituras/eventos dentro de la transacción.
- `api/tests/Unit/Iam/Session/Application/InMemorySessionRepository.php` — espía `findActiveByIdCalls`.
- `api/tests/Functional/Iam/Session/ReloginRevokesPriorSessionFunctionalTest.php` — login real: re-login sin fantasma en `GET /api/v1/sessions`, otro navegador no revoca, otra identidad sobre la misma cookie, rollback con fallo real del store (503, previa intacta).

**Review:** 20 hallazgos — 6 patches aplicados (1 medium, 5 low; agrupados en 4 raíces), 0 diferidos, 14 rechazados con su motivo en el Triage Log. Desviación: la cobertura unitaria vive en una clase hermana nueva y no en `StartSessionTest` (límite de acoplamiento de PHPMD).

**Follow-up review recommended:** false — sólo se parcheó un `medium` (documentación de un residual previo) y ningún `high`.

**Verificación:** `make php.unit` filtrado (StartSessionTest, StartSessionRetiresCorrelatedSessionTest, ReloginRevokesPriorSessionFunctionalTest, LoginPasswordRehashFunctionalTest) → OK 17 tests; `make php.behat c='features/backoffice/identity'` → 120/120; `make php.quality` → exit 0. Falsificación: con la revocación en su propia transacción el test de rollback se pone rojo en «the revocation rolled back with the mint»; restaurado byte a byte.

**Riesgos residuales:** carrera concurrente con revocaciones masivas o erasure (misma ventana que `RevokeSession`); un mint rechazado deja la fila previa `ACTIVE` pero inalcanzable (previo al cambio, acotado por la poda). Capas de code review de CLAUDE.md: aquí corrieron las cuatro capas de bmad-build-auto (Blind Hunter, Edge Case Hunter, Verification Gap, Intent Alignment) sobre el diff en esta sesión.

