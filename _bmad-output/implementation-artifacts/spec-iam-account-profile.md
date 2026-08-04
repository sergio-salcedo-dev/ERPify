---
title: 'Mi cuenta: vista de perfil dedicada y cambio de contraseña autenticado'
type: 'feature'
created: '2026-08-04'
status: 'done'
baseline_commit: 'ff8985348f1a472bd956bbdb4a9622a8f11c0d95'
review_loop_iteration: 0
context:
  - '{project-root}/docs/api-error-contract.md'
  - '{project-root}/api/.persistent-transport-policy'
  - '{project-root}/api/.bounded-context-allowlist'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** «User Profile» es hoy un callejón sin salida: en el sidebar expandido el clic solo despliega el submenú, y en modo compacto / móvil navega a `/backoffice/profile`, que **no tiene `page.tsx`** → 404. El botón de cuenta de la barra superior (`bo-layout__topbar-account`) es un placeholder sin `onClick`. Y un usuario autenticado **no puede cambiar su contraseña**: la única vía es cerrar sesión y pedir un enlace de recuperación por email.

**Approach:** Aterrizar `/backoffice/profile` como la vista «Mi cuenta»: identidad en lectura (lo que `GET /me` realmente devuelve) más un formulario de cambio de contraseña contra un endpoint nuevo `POST /api/v1/me/password`, que verifica la contraseña actual, la sustituye y **revoca las sesiones abiertas dejando la del dispositivo actual viva** (FR9). Se le dan dos entradas reales: un sub-ítem «My profile» en el grupo Account del sidebar y un menú de cuenta en la barra superior sobre el botón que hoy no hace nada.

## Boundaries & Constraints

**Always:**
- El DTO de escritura usa `#[StrictRequestPayload(acceptFormat: ['json'])]`, nunca `#[MapRequestPayload]` a secas.
- El hashing y la verificación de la contraseña viven en `Infrastructure` (`PasswordHasher`); el caso de uso los recibe como *closures diferidas*, igual que `CompletePasswordReset::complete()`.
- El evento nuevo `PasswordChanged` tiene `aggregateType() = 'Iam.Identity'`, que el registro clasifica como `person` → **queda fuera de `framework.messenger.routing` y sin `#[AsMessage]` no-`sync`**; se maneja en proceso.
- Errores por el pipeline RFC 9457: marcador existente + `type()` sobrescrito, sin marcador nuevo (patrón `AccountLocked`).
- Contraseña nueva: `min: 8, max: 128`, **distinta de la actual**, `#[SensitiveParameter]` en el DTO y en las firmas que la transporten; el mismo par de límites que ya declara `passwordPolicy.ts` en el PWA.
- Cada control interactivo nuevo lleva `data-testid` estable, `title`, `aria-label` corto y estático, y los iconos decorativos `aria-hidden`.

**Ask First:**
- Si `Security::login()` sobre una petición **ya autenticada** no acuña una fila de sesión nueva en el registro (dejando al usuario fuera tras revocar), **PARAR**: la alternativa es consumir `Iam\Session\Application\RevokeOtherSessions` desde Identity, y eso exige dos entradas de costura nuevas en `api/.bounded-context-allowlist` **y** en `skip_violations` de `api/tools/deptrac/deptrac.yaml` — ampliar una frontera de contexto no se decide sobre la marcha.
- Cualquier campo del perfil que no venga hoy en `GET /me` (nombre, estado, último acceso…) exige ampliar `MeResource` — no lo hagas sin preguntar.

**Never:**
- No escribir fila de `audit_log` por este cambio: `User` **no** es `AuditedEntity` a propósito (mantiene `password_hash` fuera del trail), y auditar un recurso-persona entra en el eje `audit_log.resource_id` que la épica GDPR sigue cerrando. El registro durable es la fila de `event_store` de `PasswordChanged`.
- No tocar `SidebarItem.handleItemClick`: cambiar el «expandir vs navegar» afectaría a los 9 grupos del menú.
- No añadir preferencias (tema/idioma/notificaciones) ni campos nuevos al agregado `User` — diferido en `deferred-work.md`.
- No reutilizar `User::resetPassword()` (registraría `PasswordResetCompleted`, semánticamente falso) ni marcar intentos fallidos / lockout desde este endpoint.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Cambio correcto | Sesión viva; `{currentPassword: <válida>, newPassword: <8..128 chars>}` | `204`; hash sustituido; `PasswordChanged` en `event_store`; email best-effort «password changed»; resto de sesiones revocadas y la del dispositivo actual sigue navegando | N/A |
| Contraseña actual incorrecta | `currentPassword` no casa con el hash | `403` + `application/problem+json`, `type: invalid-current-password` | El hash **no** cambia, no se revoca ninguna sesión, no se publica evento |
| Contraseña nueva inválida | `newPassword` ausente / <8 / >128 | `422` `validation-failed` con `violations[]` apuntando a `newPassword` | Falla en el mapeo, antes de tocar el agregado |
| Contraseña nueva igual a la actual | `currentPassword` correcta y `newPassword` idéntica a ella | `422`, `type: new-password-must-differ` | Nada se escribe: ni hash, ni evento, ni revocación, ni email |
| Miembro extra en el body | `{currentPassword, newPassword, roles: [...]}` | `422` (`StrictRequestPayload` prohíbe extras) | Nada se ejecuta |
| Identidad no `ACTIVE` | Sesión viva pero agregado suspendido/desactivado | La excepción de transición del agregado sale por el pipeline (`invalid-identity-transition`) | Transacción revertida |
| Perfil sin permisos especiales | Cualquier identidad autenticada abre `/backoffice/profile` | Renderiza su propia identidad; sin `#[IsGranted]` ni `<Can>` — toda identidad lee y cambia lo suyo | 401 → `RequireAuth` redirige a login con `?next=` |

</frozen-after-approval>

## Code Map

- `api/src/Iam/Identity/Application/CompletePasswordReset.php` -- plantilla del caso de uso: `transactional()`, `findByIdForUpdate`, closure diferida de hash, efectos post-commit ordenados.
- `api/src/Iam/Identity/Application/RevokeSessionsBestEffort.php` -- costura Identity→Session **ya permitida** (`.bounded-context-allowlist:110`); su docblock nombra este flujo como su segundo consumidor.
- `api/src/Iam/Identity/Application/SendPasswordChangedEmailBestEffort.php` -- aviso al usuario, reutilizable tal cual.
- `api/src/Iam/Identity/Domain/Entity/User.php:186` -- `resetPassword()`: guarda `ACTIVE` + `record(...)` a copiar.
- `api/src/Iam/Identity/Domain/Event/PasswordResetCompleted.php` -- forma del evento y docblock de por qué no se enruta.
- `api/src/Iam/Identity/Domain/Exception/AccountLocked.php` -- patrón marcador `Forbidden` + `type()` sobrescrito.
- `api/src/Iam/Identity/Infrastructure/Security/PasswordHasher.php` -- solo tiene `hash()`; **no existe ninguna verificación de plaintext fuera del firewall**.
- `api/src/Iam/Identity/Infrastructure/Controller/UserPatchRolesController.php` -- controlador autenticado de escritura: sin `#[IsCsrfTokenValid]`, DTO en `Infrastructure/Http/`.
- `api/config/routes.yaml:33-40` -- `Infrastructure/Controller/` monta en `/api/v1`; `security.yaml:56` ya exige `IS_AUTHENTICATED_FULLY` bajo `^/api`.
- `pwa/src/app/backoffice/BackOfficeLayoutClient.tsx:348-359` -- botón de cuenta inerte (sin `onClick`) en `.bo-layout__topbar-actions`; `handleNavigation` en `:51-68`.
- `pwa/src/context/shared/access/infrastructure/ui/DevSessionSwitcher.tsx:41-79` -- `DropdownMenu` ya montado en esa misma fila de la barra superior: modelo del menú de cuenta.
- `pwa/src/app/backoffice/users/_components/InviteUserForm.tsx:65-88,112-118` -- mapeo de 422 → `setError` y colocación de `<MutationError>`.
- `pwa/src/app/backoffice/users/_components/UserDetailView.tsx:140-241` -- patrón `<dl>` + helper `Field` para identidad en lectura.
- `pwa/src/context/shared/access/infrastructure/ApiIdentityRepository.ts:75` -- adaptador de `/me`; `Identity` no lleva `name` y su `status` es sintetizado en cliente.

## Tasks & Acceptance

**Execution:**
- [x] `api/src/Iam/Identity/Domain/Event/PasswordChanged.php` -- crear el evento (`erpify.iam.identity.password-changed`, `aggregateType() = 'Iam.Identity'`, payload vacío) -- el registro reproducible del cambio, y el docblock deja escrito por qué no se enruta.
- [x] `api/src/Iam/Identity/Domain/Exception/InvalidCurrentPassword.php` -- excepción de dominio con marcador `Forbidden` y `type()` = `invalid-current-password` -- 403 mantiene la sesión viva; un 401 haría que el PWA expulsara al usuario al login.
- [x] `api/src/Iam/Identity/Domain/Exception/NewPasswordMustDiffer.php` -- excepción de dominio con marcador `InvariantViolation` y `type()` = `new-password-must-differ` -- entrada bien formada pero semánticamente inválida; 422 sin marcador nuevo, y el PWA la cuelga del campo `newPassword`.
- [x] `api/src/Iam/Identity/Domain/Entity/User.php` -- añadir `changePassword(HashedPassword $password): void` (exige `ACTIVE`, registra `PasswordChanged`) -- el cambio autoservicio no es un reset.
- [x] `api/src/Iam/Identity/Infrastructure/Security/PasswordHasher.php` -- añadir `verify(#[SensitiveParameter] string $plainPassword, string $hash): bool` -- la comparación es responsabilidad del adaptador, no del dominio.
- [x] `api/src/Iam/Identity/Application/ChangeMyPassword.php` -- caso de uso `change(string $userId, Closure $verifyCurrent, Closure $isSameAsCurrent, Closure $hashNew): void`: `transactional()` → `findByIdForUpdate` → verificar la actual → rechazar si la nueva coincide → `changePassword()` → `save()` → publicar eventos; post-commit `RevokeSessionsBestEffort` y `SendPasswordChangedEmailBestEffort` -- las tres closures reciben la `HashedPassword` almacenada y devuelven `bool`, así que **ninguno de los dos textos en claro sale de `Infrastructure`**; reutiliza la costura ya permitida en vez de abrir una nueva.
- [x] `api/src/Iam/Identity/Infrastructure/Http/ChangeMyPasswordRequest.php` -- DTO con `currentPassword` y `newPassword` (`NotBlank`, `Length(min: 8, max: 128)`, `#[SensitiveParameter]`) -- las violaciones se responden 422 en el mapeo.
- [x] `api/src/Iam/Identity/Infrastructure/Controller/ChangeMyPasswordController.php` -- `POST /me/password` con `#[CurrentUser] SecurityUser $user` + `#[StrictRequestPayload]`; pasa las closures y responde 204. Tras `RevokeSessionsBestEffort`, invoca `Security::login()` **con el único fin de preservar la sesión del dispositivo actual, que la revocación acaba de tumbar** — es una hipótesis a validar, no una garantía: **si al implementarlo se comprueba que no acuña una fila de sesión nueva en el registro, DETENER la historia y aplicar el bloque *Ask First***. Sin `#[IsGranted]` (cada identidad actúa sobre la suya) y sin CSRF, como las otras cuatro escrituras autenticadas: exigir la contraseña actual ya hace el endpoint resistente a CSRF.
- [x] `api/tests/Unit/Iam/Identity/Domain/Entity/UserChangePasswordTest.php` -- cubrir el registro de `PasswordChanged` y el rechazo si no está `ACTIVE`.
- [x] `api/tests/Unit/Iam/Identity/Application/ChangeMyPasswordTest.php` -- cubrir la matriz: éxito (hash sustituido, 1 evento, revocación llamada, email enviado), contraseña actual incorrecta y contraseña nueva idéntica (ambas sin escritura, sin evento, sin revocación) -- reutiliza los dobles de `CompletePasswordResetTest`.
- [x] `api/features/backoffice/identity/change_password.feature` -- escenarios 204 / 403 `invalid-current-password` / 422 `validation-failed` / 422 `new-password-must-differ`; **busca antes en el vocabulario** (`make php.behat c="-d 'password'"`, `c="-d 'me'"`) y reutiliza pasos existentes en vez de crear frases casi duplicadas.
- [x] `pwa/src/context/shared/routing/domain/Routes.ts` -- añadir `PROFILE: "/backoffice/profile"` -- lo consumen página, menú y barra superior; `DEV_TOOLS` es el precedente.
- [x] `pwa/src/context/shared/http-client/infrastructure/ApiEndpoints.ts` -- añadir `IDENTITY.CHANGE_PASSWORD` -- ninguna ruta se escribe a mano.
- [x] `pwa/src/context/shared/access/domain/IdentityRepository.ts` + `infrastructure/ApiIdentityRepository.ts` -- añadir `changePassword(command): Promise<void>` -- mismo puerto que `me()`: es el agregado «mi identidad».
- [x] `pwa/src/context/backoffice/user/application/schemas/auth/ChangePasswordSchema.ts` -- esquema Zod + tipo inferido reutilizando `passwordPolicy.ts` -- los límites no se reescriben.
- [x] `pwa/src/app/backoffice/profile/_components/ChangePasswordForm.tsx` -- formulario `useZodForm` con `<FormField>` + `<PasswordInput autoComplete="current-password" | "new-password">`, `<MutationError>` como primer hijo del `<form>`, `invalid-current-password` mapeado a `setError("currentPassword")` y `new-password-must-differ` a `setError("newPassword")`; éxito → `<SecuritySignal>` -- sigue `InviteUserForm`, no inventa patrón.
- [x] `pwa/src/app/backoffice/profile/_components/ProfileView.tsx` -- cliente: `useSession()` → `<dl>` con email, id (+`<CopyButton>`) y roles/permisos como `<StatusBadge>`, más el formulario bajo un encabezado «Security» -- prefijo de testid `account-profile__`.
- [x] `pwa/src/app/backoffice/profile/page.tsx` -- página servidor delgada (`metadata` con `robots: {index:false}`) que monta `<ProfileView />` -- cierra el 404.
- [x] `pwa/src/app/backoffice/_lib/backofficeMenu.ts` -- insertar `{ name: "My profile", path: Routes.PROFILE, icon: User }` como primer `subItem` de `accountMenuItem` -- hace la vista alcanzable desde el sidebar expandido sin tocar `SidebarItem`.
- [x] `pwa/src/app/backoffice/BackOfficeLayoutClient.tsx` -- sustituir el botón inerte `bo-layout__topbar-account` por un `DropdownMenu` (trigger conserva el testid, `aria-label="Account menu"`, `<MonogramAvatar>` alimentado del email) con My profile / Active sessions / Settings / separador / Logout (`variant="destructive"`), todos vía `handleNavigation` -- la barra superior deja de mentir.
- [x] `pwa/tests/app/backoffice/profile/page.test.tsx` y `pwa/tests/app/backoffice/profile/changePasswordForm.test.tsx` -- render de la identidad y la matriz del formulario (éxito, 403 al campo, 422 a violaciones) -- mockean el contenedor, como `MySessions.test.tsx`.
- [x] `pwa/tests/context/shared/access/ApiIdentityRepository.test.ts` -- extender con el POST de cambio de contraseña.
- [x] `pwa/tests/e2e/backoffice/app-shell.spec.ts` -- actualizar el caso que hoy fija `bo-layout__topbar-account` como control habilitado inerte: ahora abre el menú y navega a `/backoffice/profile`.
- [x] `pwa/tests/e2e/backoffice/sidebar.spec.ts` -- añadir «My profile» a las aserciones del grupo Account.
- [x] `docs/api-error-contract.md` -- documentar `invalid-current-password` (403, `Forbidden` + `type()`) y `new-password-must-differ` (422, `InvariantViolation` + `type()`), ambos sin marcador nuevo, en la sección de recuperación de contraseña -- es el registro manual que NFR26 exige.
- [x] `docs/architecture-api.md` -- registrar el evento de dominio `PasswordChanged` y el endpoint nuevo.
- [x] `PRODUCTION_SECURITY_CHECKLIST.md` -- anotar la superficie de credenciales nueva (verificación de contraseña actual, revocación de sesiones, sin CSRF por convención de escrituras autenticadas).

**Acceptance Criteria:**
- Dado un usuario autenticado en escritorio con el sidebar expandido, cuando pulsa «User Profile» → «My profile», entonces aterriza en `/backoffice/profile` y ve su email, su id y sus roles — sin 404 y sin pantalla vacía.
- Dado ese mismo usuario, cuando abre el menú de la barra superior, entonces encuentra las mismas entradas de cuenta y «Logout» sigue cerrando sesión por la vía actual (revoke-current + navegación dura a `/`).
- Dado un cambio de contraseña correcto con dos sesiones abiertas, cuando se completa, entonces la pestaña que lo ejecutó sigue navegando sin re-login y la otra sesión queda revocada **en su siguiente petición autenticada** — la revocación es lógica en el registro, no mata el navegador abierto en el instante.
- Dado el endpoint nuevo, cuando se declara, entonces `make php.lint.persistent-transport` sigue verde sin línea nueva en `api/.persistent-transport-policy` y `make php.deptrac` sin entrada nueva en `skip_violations`.
- Dado un pase adversarial sobre el diff (contexto distinto del autor), cuando se completa, entonces queda registrado en la descripción del PR — requisito de CLAUDE.md para trabajo de seguridad, no autocertificable.

## Spec Change Log

## Design Notes

**De quién es el caso de uso.** El cambio de contraseña pertenece al agregado `User`, así que `ChangeMyPassword` vive en `Iam/Identity/Application`: `Iam/Session` solo ejecuta el efecto colateral —revocar sesiones— *después* de que el cambio esté confirmado, y por eso se invoca post-commit y en modo best-effort, nunca dentro de la transacción del agregado.

**Por qué revocar *todas* las sesiones y re-loguear, en vez de `revoke-others`.** El efecto visible es el de FR9 (las demás sesiones caen, la actual sigue), pero se consigue con la costura que **ya está permitida**: `RevokeSessionsBestEffort` → `RevokeAllSessions`, declarada en `api/.bounded-context-allowlist:110` y reflejada en deptrac. Consumir `RevokeOtherSessions` desde Identity exigiría importar además `SessionId` y `CurrentSessionReference`, o sea **tres costuras nuevas duplicadas en dos ficheros**, para el mismo resultado observable. Precedente exacto: `CompletePasswordResetController:76` revoca todo y luego `Security::login()`. Coste asumido: la fila de sesión del dispositivo actual se sustituye, así que su id cambia en «Active sessions».

**Por qué 403 y no 401 para la contraseña actual incorrecta.** `FetchHttpClient` desvía cualquier 401 (salvo endpoints de *handshake*) a `/login?reason=session-expired`: responder 401 expulsaría de la aplicación a quien solo se ha equivocado tecleando. 403 con `type()` sobrescrito es el patrón que ya usan los tres muros de `AccountLocked`/`AccountSuspended`/`AccountDeactivated`, y no introduce marcador nuevo, así que la puerta de deriva del contrato de error no se dispara.

**La vista muestra lo que `/me` devuelve, y nada más.** `MeResource` lleva `id`, `email`, `roles`, `permissions`; el `status` que hoy pinta `ApiIdentityRepository` es un `ACTIVE` sintetizado en cliente. Renderizarlo como si viniera del servidor sería afirmar algo que la respuesta no dice: la vista lo omite hasta que `MeResource` lo transporte de verdad.

## Verification

**Commands:**
- `make php.behat c="-d 'password'"` y `c="-d 'session'"` -- **antes** de escribir la feature: lista el vocabulario existente y su `Context::method()`; toda frase nueva tiene que justificar por qué ninguna de las listadas sirve.
- `make php.stan` -- exit 0 sobre cada fichero PHP tocado.
- `make php.unit c='--filter ChangeMyPassword'` y `c='--filter UserChangePassword'` -- verde; confirma con `--list-tests` que el filtro casa las clases nuevas.
- `make php.behat c='api/features/backoffice/identity/change_password.feature'` -- todos los escenarios verdes (lee el exit code, no el resumen).
- `make php.quality` -- exit 0; incluye `php.deptrac`, `php.lint.persistent-transport`, `php.lint.person-reference` y `php.lint.error-contract`.
- `make pwa.test.unit c='tests/app/backoffice/profile'` -- verde.
- `make pwa.quality` -- exit 0.
- `make pwa.test.e2e c='tests/e2e/backoffice/app-shell.spec.ts tests/e2e/backoffice/sidebar.spec.ts'` -- verde contra el stack vivo del worktree.

**Manual checks (if no CLI):**
- Con dos navegadores autenticados, cambiar la contraseña en uno: el que la cambió sigue navegando sin volver al login; el otro cae al login en su siguiente petición.
- `/backoffice/profile` en escritorio expandido, escritorio compacto y móvil: los tres llegan a la vista, ninguno a 404.
- Barra superior: el menú de cuenta abre con teclado, el trigger tiene nombre accesible propio (el `MonogramAvatar` es `aria-hidden`) y Esc lo cierra.

## Suggested Review Order

**El endpoint de credencial (empezar aquí)**

- La firma que mantiene ambos textos en claro fuera de Application y Domain.
  [`ChangeMyPassword.php:75`](../../api/src/Iam/Identity/Application/ChangeMyPassword.php#L75)

- Construye las tres closures; el `try` contiene el único paso que no puede volverse un error honesto.
  [`ChangeMyPasswordController.php:90`](../../api/src/Iam/Identity/Infrastructure/Controller/ChangeMyPasswordController.php#L90)

- La verificación de plaintext que no existía fuera del firewall; ojo al orden de los argumentos.
  [`PasswordHasher.php:36`](../../api/src/Iam/Identity/Infrastructure/Security/PasswordHasher.php#L36)

- El mutador nuevo y el guard que ahora comparte con `resetPassword()`: solo difiere el hecho registrado.
  [`User.php:204`](../../api/src/Iam/Identity/Domain/Entity/User.php#L204)

**Contrato de error y evento**

- Evento de agregado-persona: por qué nunca se enruta a un transporte persistido.
  [`PasswordChanged.php:37`](../../api/src/Iam/Identity/Domain/Event/PasswordChanged.php#L37)

- 403 y no 401: un 401 expulsaría al login a quien solo tecleó mal.
  [`InvalidCurrentPassword.php`](../../api/src/Iam/Identity/Domain/Exception/InvalidCurrentPassword.php)

- 422 sin marcador nuevo: entrada bien formada, inválida solo contra el hash almacenado.
  [`NewPasswordMustDiffer.php`](../../api/src/Iam/Identity/Domain/Exception/NewPasswordMustDiffer.php)

- El registro manual que NFR26 exige, con el 409 de la transición corregido.
  [`api-error-contract.md`](../../docs/api-error-contract.md)

**La superficie «Mi cuenta»**

- Cierra el 404: página servidor delgada sobre el cliente.
  [`page.tsx`](../../pwa/src/app/backoffice/profile/page.tsx)

- Muestra solo lo que `/me` transporta; sin `status`, que hoy se sintetiza en cliente.
  [`ProfileView.tsx:43`](../../pwa/src/app/backoffice/profile/_components/ProfileView.tsx#L43)

- Mapea los dos `type` al campo donde el usuario escribió; el resto al banner persistente.
  [`ChangePasswordForm.tsx:103`](../../pwa/src/app/backoffice/profile/_components/ChangePasswordForm.tsx#L103)

- El éxito afirma lo que el 204 prueba y apunta a *Active sessions* para lo que no.
  [`ChangePasswordForm.tsx:138`](../../pwa/src/app/backoffice/profile/_components/ChangePasswordForm.tsx#L138)

**Puntos de entrada**

- El botón inerte de la barra superior pasa a menú; Logout conserva la navegación dura.
  [`BackOfficeLayoutClient.tsx:366`](../../pwa/src/app/backoffice/BackOfficeLayoutClient.tsx#L366)

- Sub-ítem que hace alcanzable la vista sin tocar `SidebarItem`.
  [`backofficeMenu.ts:261`](../../pwa/src/app/backoffice/_lib/backofficeMenu.ts#L261)

**Pruebas y soporte**

- El orden post-commit se muestrea DENTRO de cada efecto: mover el revoke a la transacción lo pone rojo.
  [`ChangeMyPasswordTest.php:168`](../../api/tests/Unit/Iam/Identity/Application/ChangeMyPasswordTest.php#L168)

- Gancho nuevo en el doble compartido, por defecto nulo, que hace observable ese instante.
  [`InMemorySessionRepository.php:100`](../../api/tests/Unit/Iam/Session/Application/InMemorySessionRepository.php#L100)

- Los siete escenarios de extremo a extremo, incluidos los dos rechazos.
  [`change_password.feature`](../../api/features/backoffice/identity/change_password.feature)

- Adaptador y contrato de puerto para el POST.
  [`ApiIdentityRepository.ts:100`](../../pwa/src/context/shared/access/infrastructure/ApiIdentityRepository.ts#L100)
