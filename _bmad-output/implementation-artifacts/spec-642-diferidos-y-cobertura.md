---
title: 'Diferidos de #642: una política de contraseña, un re-login que comprueba, un presupuesto que cuenta y una cobertura que mide'
type: 'feature'
created: '2026-08-04'
status: 'in-review'
baseline_commit: 'cc3abb0fca82d7a76d3f2bf438cb6e159d02930c'
review_loop_iteration: 0
context:
  - '{project-root}/docs/api-error-contract.md'
  - '{project-root}/api/.bounded-context-allowlist'
  - '{project-root}/PRODUCTION_SECURITY_CHECKLIST.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** PR #642 mergeó con ocho diferidos abiertos y `new_coverage` a 60,5% contra un umbral de 80. La investigación reencuadra tres de ellos y descubre tres defectos no registrados. (1) La política de contraseña **no existe como objeto**: seis literales dispersos en tres DTOs que ya han derivado — `/reset-password` acepta 8..255 mientras el resto acepta 8..128, en producción, sin test ni doc — y de esa ausencia caen el desacuerdo de unidades cliente/servidor, la contraseña de solo espacios y el bootstrap CLI que acuña al primer administrador con 1 carácter. (2) El re-login programático post-commit **no recomprueba la identidad**, y no es una regresión de #642: `CompletePasswordReset` tiene la ventana exactamente igual de ancha desde #491. (3) `POST /me/password` no tiene presupuesto por identidad ni deja fila contable alguna. (4) El 60,5% es en parte un hueco de herramienta: Behat corre 7 escenarios end-to-end contra el controlador y aporta cero cobertura, porque solo el clover de PHPUnit llega a Sonar.

**Approach:** Una política de contraseña compartida en `Shared/Validation` que los tres DTOs y el comando CLI adoptan, con su espejo en el PWA contando puntos de código; un colaborador `ReauthenticateDevice` que los tres sitios de `Security::login()` usan para releer el agregado y exigir `ACTIVE` antes de acuñar; un limitador por identidad con 429 visible más una fila `SECURITY` sin recurso en el 403; y los tests que faltan — el funcional del controlador, que además cierra la rama de peor desenlace, y el primer test del layout del backoffice.

## Boundaries & Constraints

**Always:**
- `currentPassword` **nunca** adopta la política nueva: es una credencial preexistente, posiblemente acuñada bajo una regla más ancha, y asertarle el mínimo de hoy encierra a su dueño fuera del endpoint que lo arreglaría. Conserva `NotBlank` + techo de 255.
- La regla de no-blanco se escribe con `mb_trim`, **nunca** `\S`: un U+00A0 o un U+3000 sobreviven a `trim()` ASCII. Precedente literal en `FilterQuery.php:86-90`.
- `ReauthenticateDevice` vive en `Infrastructure/Security`, **no** en `Application/`: el paso *es* `SecurityBundle\Security::login()`, código de framework, prohibido hacia dentro.
- **`ReauthenticateDevice` se mantiene diminuto y ciego al flujo que lo llama.** Su firma recibe un id de identidad y nada más; no aprende qué es una invitación, un reset ni un cambio de contraseña, y no acumula ramas por llamante. Tres contextos lo van a consumir: en cuanto conozca a más de uno deja de ser una extracción y pasa a ser el servicio transversal que la costura de `Iam/Invitation` estaría legitimando.
- **La constraint `PasswordPolicy` es única e inmutable: sin parámetros configurables.** Los límites son constantes de la propia clase, no argumentos del atributo. Un `#[PasswordPolicy(max: 255)]` posible es exactamente la deriva que esta PR existe para matar, y llegaría dentro de un año sin que ningún gate lo notara — igual que llegó la de `ResetPasswordRequest`.
- La fila `SECURITY` va **sin recurso** (`resource: null`, forma de `AccessDeniedAuditListener:64`). Nombrar al sujeto como recurso lo mete en el eje `audit_log.resource_id` con `actor_id == resource_id` por construcción — el crosswalk que el ADR describe.
- **La fila detectiva no se mergea sin el limitador.** Una escritura síncrona por intento en un endpoint sin presupuesto es un amplificador de escritura a favor del atacante.
- El 429 visible es legítimo **solo** aquí: post-identidad no hay oráculo de existencia. La neutralidad que `PasswordRecoveryThrottle:13-15` impone sigue vigente en forgot/reset.
- Sin marcador de error nuevo: se reutiliza `RateLimitExceeded`/`RateLimited` (429) y `AccountSuspended`/`AccountDeactivated` (403). El gate `php.lint.error-contract` no debe dispararse.
- Cada test nuevo se falsifica: se provoca el rojo restaurando después los bytes, nunca con `git checkout --`.

**Ask First:**
- Si cerrar #3 con `ReauthenticateDevice` exigiera más de **una** entrada nueva en `.bounded-context-allowlist` para `Iam/Invitation`.
- Si el limitador por identidad resultara imposible de resetear entre escenarios Behat.
- Si mover la guarda de `ACTIVE` dentro de `ChangeMyPassword` volviera inalcanzable algún escenario Behat existente distinto de los nombrados abajo.

**Never:**
- **Trimear** una contraseña en ningún lado: la ruta de verificación es `json_login`, propiedad del framework, que no trimea. Guardar `hash(trim(x))` y verificar `x` es un bloqueo permanente e irreversible.
- Tocar preferencias de usuario (tema/idioma/notificaciones). Fuera de alcance por decisión del usuario; su bala se conserva.
- Cerrar #7 (cross-tab) con código: se acepta y se registra.
- Reglas de clases de caracteres, ni `NotCompromisedPassword` en esta PR.
- Perseguir `backofficeMenu.ts:229` ni `ApiIdentityRepository.ts:36`: no son código nuevo, cero impacto en el gate.
- Fabricar un test para `ChangePasswordForm.tsx:91` (`?? []`): la rama es inalcanzable. Se **borra** el operador.

## I/O & Edge-Case Matrix

| Escenario | Entrada / Estado | Salida esperada | Manejo de error |
|---|---|---|---|
| Identidad no `ACTIVE` al llegar la petición | sesión viva, agregado `SUSPENDED` | **403 `account-suspended`**, 0 KDF pagados, sin efectos | guarda tras el row lock, antes del hash |
| Identidad suspendida **durante** la ventana post-commit | `ACTIVE` al commit, `SUSPENDED` durante el envío SMTP | **204**, credencial cambiada, sesiones revocadas, **ninguna sesión nueva**, línea `critical` | contención existente del controlador |
| Presupuesto por identidad agotado | N intentos en la ventana, misma identidad | **429 `rate-limited`** | sin KDF, sin efectos |
| Contraseña actual incorrecta | credencial válida pero errónea | **403 `invalid-current-password`** + **fila `SECURITY` sin recurso** en `audit_log` | sin efectos |
| Nueva contraseña de solo espacios | `"        "`, u 8× U+00A0, u 8× U+3000 | **422 `validation-failed`**, violación en `newPassword` | idéntico en reset e invitación |
| Nueva contraseña de 5 caracteres astrales | `"😀😀😀😀😀"` | **422 en ambos lados** — el PWA ya no la acepta | conteo por puntos de código |
| Identidad `ACTIVE` + bloqueada que cambia | `lockedUntil` futuro, contraseña actual correcta | **204** y lockout limpiado por decisión **explícita** | `clearLockout()` en el caso de uso |
| Reset con 200 caracteres | `POST /reset-password` | **422** (hoy 204) | techo unificado a 128 |
| Bootstrap CLI con contraseña de 1 carácter | `identity:create-initial-administrator` | **rechazo** con el mensaje de la política | misma constraint |

</frozen-after-approval>

## Code Map

- `api/src/Shared/Validation/Infrastructure/EnumType.php` + `EnumTypeValidator.php` — forma exacta a copiar para la constraint nueva. `Erpify\Shared\…` es siempre importable: cero costuras.
- `api/src/Iam/Identity/Infrastructure/Http/ChangeMyPasswordRequest.php:27-41` — únicas constantes de política del lado API; `:16-20` argumenta por qué `currentPassword` no la lleva.
- `api/src/Iam/Identity/Infrastructure/Http/ResetPasswordRequest.php:12-14,23` — el docblock confiesa la deuda; `:23` es la divergencia viva (255).
- `api/src/Iam/Invitation/Infrastructure/Http/AcceptInvitationRequest.php:24-32` — literales duplicados, mensajes correctos.
- `api/src/Iam/Identity/Infrastructure/Cli/CreateInitialAdministratorCommand.php:79` — solo rechaza cadena vacía.
- `api/src/Iam/Identity/Application/ChangeMyPassword.php:81-105` — orden de operaciones; `:83` row lock (guarda `ACTIVE` va justo después), `:94` `changePassword()`, `:103` revoke, `:104` SMTP bloqueante (la ventana).
- `api/src/Iam/Identity/Infrastructure/Controller/ChangeMyPasswordController.php:72-105` — `#[CurrentUser]` en `:72`, id en `:78`, `Security::login()` en `:91`, contención `critical` en `:95-103`.
- `api/src/Iam/Identity/Infrastructure/Http/CompletePasswordResetController.php:76` y `api/src/Iam/Invitation/Infrastructure/Http/AcceptInvitationController.php:82` — los otros dos sitios de `login()`.
- `api/src/Iam/Identity/Domain/Entity/User.php:165-175` (`ensureActive`), `:324-327` (`isLockedAt`), `:376-384` (`replaceCredential`), `:90-96` (lockout ortogonal al status).
- `api/src/Iam/Identity/Infrastructure/Security/PasswordRecoveryThrottle.php` — patrón del throttle; `api/config/packages/rate_limiter.yaml:53-57` + `api/.env:86-87` — forma de declaración.
- `api/src/Shared/Audit/Infrastructure/Http/EventListener/AccessDeniedAuditListener.php:43,64` — patrón del listener detectivo (prioridad 32, `resource: null`).
- `api/tests/Functional/Iam/Invitation/InvitationAcceptFunctionalTest.php:43-46,69-71,104` — único test del repo que ejercita `Security::login()`; multi-`CoversClass` y `disableReboot()`.
- `api/tests/Functional/Iam/Identity/Infrastructure/Controller/UserPatchStatusFunctionalTest.php:33` — `AuthenticatesFunctionalRequests`.
- `api/tests/Functional/Iam/Session/Fixtures/UnavailableSessionRepository.php` — patrón de fixture que rompe un colaborador a propósito.
- `pwa/src/context/backoffice/user/application/schemas/auth/passwordPolicy.ts` — hoy solo dos constantes; pasa a ser constructor de esquema.
- `pwa/src/app/backoffice/profile/_components/ChangePasswordForm.tsx:27-28,86-101,105-113,130,162-163,183,208` — tipos leídos, mapeo de violaciones, ramas sin cubrir, copy del helper.
- `pwa/src/app/backoffice/BackOfficeLayoutClient.tsx:28-30,40,70-76,98,336,383` — export por defecto, `useSession`, logout, `RequireAuth` (devuelve null si no `authenticated`), `DevSessionSwitcher`, monograma.
- `pwa/tests/app/backoffice/banks/_interactions.ts:7-33` — helper de apertura reintentada de menú; obligatorio para el dropdown de cuenta.
- `pwa/tests/app/backoffice/users/userDetailPage.test.tsx:11-26` — forma de mock (container que lanza ante token desconocido).
- `api/tools/phpunit/phpunit.dist.xml:34-38` — `requireCoverageMetadata` ausente; cualquier `Covers*` filtra el resto.

## Tasks & Acceptance

**Execution — C: una política, un sitio**
- [x] `api/src/Shared/Validation/Infrastructure/PasswordPolicy.php` + `PasswordPolicyValidator.php` — crear constraint reutilizable (min/max en puntos de código + no-blanco vía `mb_trim`), modelada sobre `EnumType`/`EnumTypeValidator` — el duplicado triple ya derivó una vez sin que ningún gate lo notara.
- [x] `api/src/Iam/Identity/Infrastructure/Http/ChangeMyPasswordRequest.php` — `newPassword` adopta la constraint; `currentPassword` **intacto**.
- [x] `api/src/Iam/Identity/Infrastructure/Http/ResetPasswordRequest.php` — adopta la constraint; techo 255→128 — cierra la divergencia viva.
- [x] `api/src/Iam/Invitation/Infrastructure/Http/AcceptInvitationRequest.php` — adopta la constraint.
- [x] `api/src/Iam/Identity/Infrastructure/Cli/CreateInitialAdministratorCommand.php` — valida contra la misma política **antes de cualquier operación** (construcción de entidad, hashing, persistencia): no por rendimiento, sino porque deja dicho que la contraseña es validación de entrada. Es la cuenta más privilegiada del sistema.
- [x] `pwa/.../schemas/auth/passwordPolicy.ts` — exporta un constructor de esquema: límites sobre `[...value].length`, refine de no-blanco, mensajes compartidos, y `EXISTING_PASSWORD_MAX_LENGTH` movido aquí.
- [x] `pwa/.../schemas/auth/{ChangePasswordSchema,ResetPasswordSchema,AcceptInvitationSchema}.ts` — consumen el constructor; mueren los mensajes triplicados.
- [x] `pwa/src/app/backoffice/profile/_components/ChangePasswordForm.tsx:208` — el `helper` deja de mentir sobre los límites.

**Execution — B: el muro de admisión**
- [x] `api/src/Iam/Identity/Infrastructure/Security/ReauthenticateDevice.php` — crear: relee el agregado, `ensureActive()`, `Security::login()`. Una política, no tres.
- [x] `api/src/Iam/Identity/Infrastructure/Controller/ChangeMyPasswordController.php` — usa el colaborador; **conserva** la contención `critical` de `:95-103`.
- [x] `api/src/Iam/Identity/Infrastructure/Http/CompletePasswordResetController.php` + `api/src/Iam/Invitation/Infrastructure/Http/AcceptInvitationController.php` — mismo colaborador: la ventana del reset es igual de ancha y parchear solo #642 bifurcaría la política.
- [x] `api/.bounded-context-allowlist` + `api/tools/deptrac/deptrac.yaml` — una costura para Invitation, misma familia que la entrada `UserProvider` que ya tiene.
- [x] `api/src/Iam/Identity/Application/ChangeMyPassword.php` — `ensureActive()` tras el row lock (`:83`) → 403 en vez de 409; y `clearLockout()` **explícito**, espejo de `CompletePasswordReset:106` — el efecto ya ocurría heredado de un listener a dos capas.
- [x] `pwa/.../ChangePasswordForm.tsx` — añade `account-suspended` / `account-deactivated` a los tipos que sabe leer.

**Execution — D: presupuesto y rastro**
- [x] `api/config/packages/rate_limiter.yaml` + `api/.env` — declarar `password_change_per_identity`, `sliding_window`, límite/intervalo por env.
- [x] `api/src/Iam/Identity/Infrastructure/Security/PasswordChangeThrottle.php` — crear; **lanza** `RateLimitExceeded` (429 visible), a diferencia del throttle de recuperación.
- [x] `api/src/Iam/Identity/Infrastructure/Http/EventListener/InvalidCurrentPasswordAuditListener.php` — crear: fila `SECURITY` sin recurso, metadata solo `{route}`.

**Execution — A: que la cobertura mida**
- [x] `api/tests/Functional/Iam/Identity/.../ChangeMyPasswordFunctionalTest.php` — crear; `#[CoversClass]` sobre controlador + request DTO + `PasswordHasher`; **fuerza el fallo de acuñación** y afirma 204 + `critical` + cero sesiones — la rama de peor desenlace que nada recorre.
- [x] `api/tests/Unit/Iam/Identity/Application/ChangeMyPasswordTest.php` — añadir `CoversClass` de las dos excepciones: sus assertions ya existen, el clover las descarta.
- [x] `api/tests/Unit/Iam/Identity/Domain/Event/PasswordChangedTest.php` — crear; `toPrimitives`/`fromPrimitives`/`aggregateType` no los ejecuta nada hoy.
- [x] `api/tests/Unit/Shared/Validation/PasswordPolicyValidatorTest.php` — crear; astral, U+00A0, U+3000, fronteras.
- [x] `pwa/tests/app/backoffice/backOfficeLayoutClient.test.tsx` — crear; el logout llama a `logout()` y `location.assign("/")`, **no** a `router.push`. Usar el helper de apertura reintentada.
- [x] `pwa/tests/app/backoffice/profile/changePasswordForm.test.tsx` — «cambiar otra vez», descarte del banner, fallback a `title`, y re-lanzado de un error que no es `HttpError`.
- [x] `pwa/tests/app/backoffice/profile/changePasswordFormSsr.test.tsx` — crear; `renderToString` fija que sin hidratar no hay GET nativo con los dos textos planos en la URL.
- [x] `pwa/tests/app/backoffice/profile/page.test.tsx` — sesión con roles y permisos vacíos: el estado vacío no está probado.
- [x] `pwa/src/app/.../ChangePasswordForm.tsx:91` — borrar `?? []`: rama inalcanzable, testearla sería fabricar estado.
- [x] `api/features/backoffice/identity/{change_password,password_reset,invitation_accept}.feature` — escenarios de no-blanco, cota superior, 429, 403 de identidad no activa y lockout; afirmar **el mensaje**, no solo el campo.

**Execution — E, F y registros**
- [x] `PRODUCTION_SECURITY_CHECKLIST.md` — §7: aceptar la ventana cross-tab nombrando **también** `CompletePasswordReset`, o el registro queda incoherente; más el limitador nuevo y la excepción a «solo límites globales por IP pueden 429».
- [x] `docs/api-error-contract.md` — 429 en este endpoint, 403 del muro, y el 422 del reset (que hoy no documenta su cota).
- [x] `docs/rules/security.md` — la política de contraseña, que hoy no existe escrita en ningún sitio.
- [x] `_bmad-output/implementation-artifacts/deferred-work.md` — borrar las 8 balas resueltas; **conservar** la de preferencias, afilada con lo medido (traductor apagado por decisión argumentada, cero canal de notificaciones).
- [x] `_bmad-output/implementation-artifacts/spec-iam-account-profile.md` — borrar: `status: done`.

**Acceptance Criteria:**
- Dado un `make php.quality` y un `make pwa.quality` en frío, cuando terminan, entonces ambos salen 0 y ningún gate nuevo se dispara.
- Dado que la PR se analiza en SonarCloud, cuando el gate evalúa `new_coverage`, entonces supera el 80% — y la PR **desglosa la subida en dos cifras separadas**: cuántos elementos vienen de ejecución nueva y cuántos de corregir la atribución (`#[CoversClass]` sobre assertions que ya existían). Sonar las mezcla en un solo número; si la PR no las separa, nadie podrá volver a distinguirlas.
- Dado que el analizador no ve Behat, cuando se declare la cobertura, entonces la PR dice explícitamente que 7 escenarios end-to-end no cuentan: es un hueco de herramienta, no de tests.
- Dado cualquier test nuevo, cuando se revierte la línea de producción que lo hace pasar, entonces ese test se pone rojo y se restaura copiando bytes, nunca con `git checkout --`.
- Dado el pase adversarial obligatorio (esta PR toca seguridad y auditoría), cuando llegue el momento de **abrir** la PR, entonces el pase ya está hecho y su registro citado en el cuerpo — el gate es la apertura, no el `done`. Si hacen falta ojos antes, se abre en **draft** y el pase es lo que la saca de draft.

## Design Notes

**Por qué la constraint no lleva parámetros.** Un atributo configurable reproduce el defecto que cierra: hoy los números están en tres sitios y uno derivó; con `#[PasswordPolicy(min: …, max: …)]` volverían a estar en tres sitios, solo que con mejor sintaxis. Los límites viven como constantes de la clase y el único punto de configuración legítimo es esa clase. `currentPassword` no es una excepción a esto — sencillamente **no lleva la constraint**.

**Por qué un colaborador y no una guarda en cada sitio.** Los tres controladores repiten hoy el mismo patrón y el hueco es idéntico en los tres. Escribir `ensureActive()` tres veces reproduce exactamente la deriva que la política de contraseña ya sufrió: tres declaraciones, una desalineada, ningún gate. El colaborador es Infrastructure porque `Security::login()` es framework — `Application/` no puede importarlo.

**Por qué el 409 no muere solo con arreglar #3.** Cubren ventanas disjuntas: la guarda post-commit solo alcanza a quien dejó de ser `ACTIVE` *durante* el SMTP; quien ya llegó suspendido lo hace en el row lock. Solo mover la guarda **dentro** de `ChangeMyPassword` tras `:83` degrada el 409 a suelo defensivo — el estado que `resetPassword()` ya tiene.

**El presupuesto no detiene al atacante y hay que decirlo.** Ya tiene la sesión; el límite retrasa la adivinación, no la impide. Vive en caché: un deploy o un segundo worker lo resetea o lo parte, y `lock_factory: null` deja que la cuenta derive bajo concurrencia. Es un atenuador, no el muro que es el lockout persistido en BD. El dueño legítimo también queda bloqueado en esta ruta — pero `/forgot-password` la rodea, que es lo que separa esto de la objeción del lockout.

**Por qué la fila `SECURITY` no cuesta nada de cumplimiento.** `audit_log` no tiene entidad Doctrine: sus columnas las inyecta un listener de `postGenerateSchema` y se escriben por SQL crudo, así que el barrido de `.person-reference-policy` es estructuralmente ciego a ellas. Y `DbalAuditActorAnonymiser` ya reescribe `actor_id` de **todas** las filas del sujeto. Sin recurso nombrado, no entra tipo nuevo en `.audit-resource-types`: cero línea, cero dueño de borrado, cero testigo de aceptación.

## Spec Change Log

- **El listener detectivo vive en `Infrastructure/Http/`, no en `Infrastructure/Http/EventListener/`.** El
  módulo `Iam/Identity` ya tiene ahí sus dos listeners HTTP (`LoginOriginListener`,
  `PasswordResetOriginListener`); la ruta del plan copiaba la de `Shared/Audit`. Estrenar un directorio para
  una clase, además, obliga a actualizar tres documentos por la regla de «nuevos directorios en `src/`».
- **Un dueño único del snapshot de rate limit (decisión del usuario, medida antes de proponerla).** El 429
  por identidad salía **sin `Retry-After`** y con `RateLimit-Remaining` contado del presupuesto por IP, porque
  `RateLimitListener::onResponse` renderiza el snapshot que dejó el listener de `kernel.request`. Contradice el
  contrato que `anonymous_api.feature:28-38` ya afirma para el 429 por IP. Se extrae
  `Shared/ErrorContract/Infrastructure/Http/RateLimitSnapshot`, que ambos estampan y el listener renderiza; el
  limitador por objetivo estampa **solo al rechazar**, para que las cabeceras cambien de sujeto exactamente en
  la respuesta que el otro presupuesto produjo. De paso mueren las ~40 líneas de estrechamiento defensivo de la
  forma del array (`readSnapshot`/`hasExpectedSnapshotShape`): un `instanceof` es el chequeo de forma.
- **Los escenarios Behat del plan seguían abiertos al reanudar.** `git log --stat cc3abb0f..HEAD -- api/features`
  no devolvía nada: ninguno de los tres commits de C, B y A tocó las features. Entraron aquí, con el 429, la
  fila forense, el no-blanco (espacio, U+00A0, U+3000), la cota superior en las tres superficies, el muro de
  identidad suspendida y el lockout que se limpia.
- **Una aserción del test SSR era vacua y lo dijo el falsificado.** `toContain("disabled")` acertaba siempre:
  la `className` de Shadcn lleva `disabled:opacity-50`. Corregida a `/\sdisabled=""/`, que sí se pone roja al
  quitar `|| !hydrated`.

## Verification

**Commands:**
- `make php.stan` — exit 0, en cada fichero PHP tocado.
- `make php.unit c='--filter PasswordPolicy'`, `c='--filter ChangeMyPassword'`, `c='--filter PasswordChanged'` — exit 0. Verificar con `--list-tests` que el filtro casa lo que se cree: el agujero es el filtro que casa un subconjunto.
- `make php.behat c='features/backoffice/identity/change_password.feature'` y las de reset e invitación — leer el **exit code**, no el resumen.
- `make php.quality.dry-run` — exit 0 (paridad CI: deptrac, error-contract, bounded-context, person-reference, persistent-transport).
- `make pwa.test.unit` y `make pwa.quality` — exit 0.
- `make php.unit.coverage` + `make pwa.test.unit.coverage` — comprobar el clover/lcov localmente antes de fiarse de Sonar.

**Manual checks:**
- Que `docs/api-error-contract.md` y `PRODUCTION_SECURITY_CHECKLIST.md` describan el 429 y el 403 que el código emite de verdad, no los que el spec planeó.
- Que `deferred-work.md` quede con la bala de preferencias y ninguna de las ocho resueltas, y que su diff neto no toque entradas ajenas.
