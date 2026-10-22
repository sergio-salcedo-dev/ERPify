---
title: 'Residuos de sesiones e IAM (DW-20, DW-21, DW-23, DW-37, DW-38)'
type: 'bugfix'
created: '2026-10-22'
status: 'done'
baseline_commit: 'b6d9a6705b20e8f4bc550ca40769a4b10caf3423'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/docs/api-error-contract.md'
  - '{project-root}/docs/architecture/event-catalog.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Cinco entradas abiertas de `deferred-work.md` sobre sesiones e IAM: eventos de revocación masiva publicados sobre 0 filas (DW-20); org de admisión resuelta con un `findOneBy` que elige en silencio (DW-21); un usuario sin membership —y cualquier bug del acuñado— reportado como 503 «store-unreachable» (DW-23); y dos tests de invitación aplazados con una premisa falsa (DW-37, DW-38).

**Approach:** Arreglar cada una en su raíz en una sola PR, cerrar las cinco entradas en `deferred-work.md` con la PR como `resolution:` y archivar los cierres pendientes con `bmad-loop sweep --archive`.

**Decisiones (2026-10-22, tras consulta externa):**
- DW-20: el puerto devuelve el rowcount del `UPDATE`; los tres casos de uso publican solo si `> 0`.
- DW-21: `getOneOrNullResult()`; el adaptador traduce `NonUniqueResultException` a una excepción propia de Membership sin marker (500). `FindUserOrganizationId::of()` devuelve `?string` (`null` = sin membership), así `Iam` no importa excepciones de `Organization`.
- DW-23: opción (a). `SessionAdmissionUnresolvable`, `DomainException` sin marker → 500 con `type` propio y sin id de persona. El `catch` del listener se acota: `ServiceUnavailable` se relanza tal cual; un fallo de DBAL de la lectura de membership → `storeUnreachable` (503); `null` → `SessionAdmissionUnresolvable`; cualquier otro → se relanza sin tocar. Siempre se invalida la sesión nativa antes.
- DW-37: ya cubierta por `invitation_accept.feature:182` (#520); se amplía con asserts de no mutación.
- DW-38: trait de test (plantar la cookie, comprobar que el id rota y que el id viejo da 401 en `GET /me`) aplicado a los cuatro flujos de reautenticación; rojo provocado con `session_fixation_strategy: none`.
- Medir (no arreglar a ciegas) la carrera login‖suspensión: `FOR KEY SHARE` no serializa `StartSession` con `RevokeAllSessions`. Si es real, se lleva al usuario como decisión.
- **Carrera login‖suspensión (medida real; el usuario decide arreglarla en esta PR, 2026-10-22):** el acuñado (`StartSession`) bloquea la identidad con `FOR SHARE` y exige `status = 'ACTIVE'`, mediante un método nuevo del puerto `SessionSubjectLock` (p. ej. `holdsAdmissible()`); `RevokeAllSessions` conserva `holdsLive()` (`FOR KEY SHARE`, sin filtro de estado), porque revoca justamente a identidades ya no activas. Una identidad borrada o no ACTIVE en el momento del acuñado se responde con una excepción propia del contexto Session, marker `Unauthenticated` (401), sin datos de la persona, en lugar del 503 actual. Test funcional de concurrencia con dos conexiones que reproduzca la carrera y quede en rojo con `FOR KEY SHARE`; documentar el orden de bloqueos frente al borrado GDPR y al login.
- **PWA, 5xx en los flujos de autenticación (usuario, 2026-10-22, opción a):** cualquier 5xx al iniciar sesión → `REQUEST_FAILED`, nunca «credenciales inválidas». La misma regla en aceptar invitación, reset y canje cuando confundan un 5xx con un error del usuario.
- **Rotación del id (tras la review, BH4):** un listener `LoginSuccessEvent` del firewall rota el id en todo login, el mismo usuario incluido, antes del acuñado; `ReauthenticateDevice` vuelve a su forma original.

## Boundaries & Constraints

**Always:** Fail-closed: un fallo del acuñado nunca deja una sesión nativa ni una fila de `iam_session`. Cada test nuevo se falsifica provocando su rojo. Sin datos de persona en mensajes ni contexto de excepciones.

**Never:** Activar `check_header`; añadir `LIMIT`/orden que oculte la multi-membership; migraciones; un nuevo import cross-context fuera de la seam publicada.

## I/O & Edge-Case Matrix

| Escenario | Estado | Resultado esperado |
|---|---|---|
| Revocar todas, 0 activas | identidad sin sesiones | sin `all-revoked`; la operación sigue siendo un éxito |
| Revocar todas, ≥1 activa | N activas | 1 `all-revoked` |
| Cerrar las demás, solo existe la actual | — | sin `others-revoked` |
| Evict, sin otras activas | superviviente vivo o revocado | sin evento; el retorno `$survived` no cambia |
| Login sin membership | ACTIVE, 0 memberships | 500 `session-admission-unresolvable`, sin sesión ni fila |
| Login con >1 membership | invariante roto | 500, nunca una org arbitraria ni un 503 |
| Login, store caído | DBAL falla | 503 `service-unavailable` |
| Login, bug inesperado | `LogicException` | se relanza (500), sesión invalidada |
| Flujo de reautenticación con cookie plantada | id X | la respuesta lleva un id ≠ X; X da 401 en `/me` |

</frozen-after-approval>

## Code Map

- `api/src/Iam/Session/Domain/Repository/SessionRepository.php`, `Infrastructure/Persistence/Doctrine/DoctrineSessionRepository.php:157-245` (`bulkRevokeActive` ya calcula `AffectedRows::from()`), `api/tests/Unit/Iam/Session/Application/InMemorySessionRepository.php:244-330` -- `void` → `int`.
- `api/src/Iam/Session/Application/{RevokeAllSessions,RevokeOtherSessions,EvictOtherSessions}.php` y sus tests en `api/tests/Unit/Iam/Session/Application/`.
- Features que fijan `1 … all-revoked` sin sesión previa: `users/status.feature:34,55`, `users/roles.feature:39`, `identity/change_password.feature` (ya siembra), `identity/password_reset.feature:128`. Para sembrar, usar el `INSERT` de `change_password.feature:15`. Vigilar el canario `status.feature:41`.
- `api/src/Organization/Membership/Infrastructure/Persistence/Doctrine/DoctrineMembershipRepository.php:66`, `Application/FindUserOrganizationId.php`, `Domain/Exception/` (nueva excepción de cardinalidad). `MembershipNotFound` se queda en el árbol solo si conserva algún usuario.
- `api/src/Iam/Identity/Infrastructure/Security/SessionMintingSuccessListener.php`; la nueva excepción en `Iam/Session/Domain/Exception/`, junto a `SessionStoreUnavailable`.
- `api/tests/Functional/Iam/Invitation/InvitationAcceptFunctionalTest.php` y los funcionales de reset, canje y cambio de contraseña. Si falta alguno, crearlo con el trait.
- `api/features/backoffice/identity/invitation_accept.feature:182`.
- Docs: `docs/architecture/event-catalog.md:102,139-143`, `docs/api-error-contract.md:163` (añadir `session-admission-unresolvable`), docblock de `RecordRecoverySecretAuditBestEffort.php:113`, `deferred-work.md`.

## Tasks & Acceptance

**Execution:**
- [x] Puerto, adaptador y doble de sesión → `int`; los tres casos de uso publican solo si `> 0`, con tests unitarios de la matriz -- DW-20
- [x] Features de Behat: sembrar sesión donde se espera el evento y añadir el caso 0 sesiones → 0 eventos -- DW-20
- [x] Repositorio de Membership, excepción de cardinalidad, `?string` en la seam y test funcional con dos filas (índice borrado en una transacción con rollback) -- DW-21
- [x] Listener acotado, `SessionAdmissionUnresolvable`, test unitario de las cuatro ramas y documentación del contrato de errores -- DW-23
- [x] Trait de rotación aplicado en los cuatro flujos -- DW-38
- [x] Asserts de no mutación en el escenario CSRF -- DW-37
- [x] Medir la carrera login‖suspensión e informar
- [ ] Arreglar la carrera: `holdsAdmissible()` (`FOR SHARE` + `ACTIVE`) en `SessionSubjectLock`/`DbalSessionSubjectLock`, usado por `StartSession`; excepción 401 del contexto Session; test funcional de concurrencia falsificado; docs del contrato de errores y del orden de bloqueos
- [x] Docs, cerrar las cinco entradas DW y `sweep --archive`

**Acceptance Criteria:**
- Given cualquier camino de revocación masiva, when no se afecta ninguna fila, then no se añade evento de sesión al `event_store`.
- Given el árbol, when corren `make php.quality` y las suites de Iam (PHPUnit y Behat), then todo verde, con cada test nuevo falsificado.

## Implementation Notes

- **DW-20 premise corrected.** The identities the features suspend/re-role/reset already hold a fixture `iam_session` (`tests/DataFixtures/Fixtures/Session.yaml`), so no feature needed seeding; the 0-sessions case is `roles.feature` "A suspended member can still be re-roled" (sam has no session). The `session.feature` query canary (10) seeds a second device, since with one session the publish path drops out (measured 4).
- **DW-23 widened by one line.** Any raw DBAL failure during the minting maps to 503, not only the membership read: the subject lock (`DbalSessionSubjectLock`) runs raw DBAL inside `StartSession`, and narrowing would have turned its outage into a 500.
- **DW-38 found a real gap, wider than the four flows.** `SessionStrategyListener` skips `migrate(true)` whenever the previous token names the same user with the same token class — change-password, and also a plain `POST /login` by a device already signed in as that user. First patched in `ReauthenticateDevice`; the review (BH4) showed `POST /login` stayed open, so the rotation moved to the firewall listener `RotateSessionIdOnLogin` (priority -96: after Symfony's listener, before minting at -128), which applies the configured strategy only when the id still equals the request cookie and throws on a login with no session. `ReauthenticateDevice` is back at baseline. `AssertsSessionIdRotation` (planted id seeded in the store; issued id must admit, old id must 401) covers five flows: accept, reset, redeem, change-password and `POST /login`.
- **Login‖suspension race: measured real, fixed (user decision).** Measured with two psql sessions: under `FOR KEY SHARE` a suspension committed during a mint left `SUSPENDED` + an `ACTIVE` session living to its TTL. Minting now holds `identity_user` `FOR SHARE … AND status = 'ACTIVE'` (`SessionSubjectLock::holdsAdmissible()`); a suspension waits for the mint and its revoke-all revokes the minted row, while a mint after a committed suspension finds no admissible row and answers 401 (`SessionSubjectNotAdmissible`). `RevokeAllSessions` keeps `holdsLive()`. Lock order stays identity → session rows, the order erasure and password change already take. Pinned by `LoginRacingASuspensionFunctionalTest` (red under `FOR KEY SHARE`, without the status predicate, and with `holdsLive`).
- **PWA (user decision a).** `ApiLoginRepository` maps every 5xx to `REQUEST_FAILED`; accept/reset/redeem already treated 5xx as a retryable generic error.

## Spec Change Log

- 2026-10-22 · BH4: el parche de rotación en `ReauthenticateDevice` no cubre `POST /login`, porque el salto de Symfony afecta a cualquier login del mismo usuario. Se enmienda: la rotación pasa a un listener `LoginSuccessEvent` del firewall, que corre antes del acuñado, falla en cerrado y no rota dos veces si Symfony ya rotó. `ReauthenticateDevice` vuelve a su forma original. KEEP: el trait `AssertsSessionIdRotation` y los cuatro flujos que lo usan, añadiendo `POST /login` como quinto.

## Review Triage Log

Capas: Blind Hunter (BH), Edge Case Hunter (EC) y Verification Gap (VG), las tres como subagentes en paralelo de esta sesión, 2026-10-22, sobre el diff `b6d9a670..worktree`.

| # | Hallazgo | Veredicto | Evidencia / ruta |
|---|---|---|---|
| VG1+BH3 | La PWA convierte el nuevo 500 de login en «credenciales inválidas» | high | `ApiLoginRepository.ts:11-15` solo trata 502/503/504 como `REQUEST_FAILED`; un usuario con contraseña correcta ve «contraseña incorrecta». intent_gap → pregunta al usuario |
| BH4 | La rotación del id sigue sin ocurrir en `POST /login` del mismo usuario | high | `SessionStrategyListener::onSuccessfulLogin` se salta `migrate` para el mismo usuario y la misma clase de token en CUALQUIER login. Arreglo de raíz: un listener `LoginSuccessEvent` para todo el firewall, que sustituye al parche en `ReauthenticateDevice` (spec change log) |
| EC2+BH9 | `ReauthenticateDevice`: sin token o sin sesión se salta la rotación en silencio, y esas ramas no tienen test | medium | Se resuelve con el rediseño de BH4: el listener falla en cerrado y tiene tests de cada rama |
| EC3 | Si `migrate` lanza después de acuñar, queda una fila ACTIVE | medium | Con el rediseño, la rotación corre ANTES del listener de acuñado (prioridad mayor que -128) |
| EC1 | El trait da por buena una rotación en la que el re-login falló sin avisar | medium | patch: el id emitido debe admitir (`GET /me` 200) |
| BH10 | El trait planta un id desconocido para el store | low | patch: sembrar el id planteado en el storage antes del flujo |
| BH5+EC5 | `InMemoryMembershipRepository::findByUserId` devuelve la primera fila | medium | Confirmado en `InMemoryMembershipRepository.php:75-84`. patch, más un test de que `FindUserOrganizationId` propaga la excepción |
| BH6 | El `catch` de DBAL mapea también errores de SQL a 503 | low | Rechazado: sigue el mismo convenio que `DoctrineSessionRepository::convertingStoreFailure()` (toda `DbalException` → 503); se documenta en el docblock. Un `RetryableException` dentro de `transactional()` ya lo traduce `DoctrineTransactionManager` |
| EC4 | `EntityManagerClosed` → 500 en vez de 503 | low | Rechazado: `DoctrineTransactionManager` resetea el EM cerrado; nunca se observó en el login |
| BH7 | Saturación de Sentry por una identidad sin membership | low | Sentry agrupa por `type`/clase en una sola issue, que es la deduplicación que pedía O3. Se anota en `resolution:` |
| BH1 | La carrera login‖suspensión no queda registrada | false | Decidida por el usuario el 2026-10-22 y en el bloque congelado, con su tarea |
| BH2 | El `resolution:` nombra la rama | low | patch al abrir la PR: nº de PR |
| BH8 | No consta dónde corrió cada capa | low | Esta tabla |
| BH11+EC6 | Docblocks desfasados (`MembershipRepository`, `GrantMembership` sin `@throws`, `RedeemRecoverySecretController:37`) | low | patch |
| BH12 | `membership-not-found` retirado sin nota | low | patch: una línea en `docs/api-error-contract.md`; grep sin consumidores en `pwa/src` |

## Verification

**Commands:**
- `make php.unit c='--filter "Session|Membership|Minting|Invitation|Reset|Recovery|ChangePassword"'` -- verde
- `make php.behat c='features/backoffice/users features/backoffice/identity'` -- verde
- `make php.quality` -- exit 0
