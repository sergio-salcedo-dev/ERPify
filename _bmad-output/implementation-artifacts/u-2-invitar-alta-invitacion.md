---
baseline_commit: b8b13b61
---
# Story 1.3 (U-2): Invitar (alta = invitación)

Status: ready-for-dev

<!-- Validación opcional. Ejecuta `bmad-create-story` validate para un chequeo de calidad antes de dev-story. -->

## Story

Como **ADMIN**,
quiero **invitar a una persona nueva por email con sus roles desde la consola**,
para **dar de alta identidades sin crear contraseñas ni «cuentas» directamente — el alta ES una invitación**.

## Contexto (leer antes de tocar código)

U-2 de la épica `users-admin` (orden safe-first `U-0 → U-1 → (U-2 · U-3) → U-4 · [U-5a → U-5b]`). U-0 (read-side) y U-1
(`/me` deriva permisos + `<Can>`) están **done** en `main @ b8b13b61` (PRs #501/#502/#503). U-2 es la **primera
superficie de acción real** sobre el ciclo de vida ya construido en la Épica II.

**Cinco hechos verificados en `main @ b8b13b61` — no re-derives:**

1. **El caso de uso ya existe: `SendInvitation::invite(string $email, Role ...$roles)`** (`api/src/Iam/Invitation/Application/SendInvitation.php:60`). Orquesta, en **una** transacción: `InviteUser::invite` (identidad `INVITED`) → `GrantMembership::grant` (membership + roles) → `Invitation::create` + `markSent()` (CREATED→SENT) → `eventBus->publish(...)`; **tras el commit** manda el email best-effort. Su docblock (`:29-30`) anticipa **exactamente** U-2: *«the single reusable use case behind both the CLI now and the management HTTP surface later»*. **No cambies su firma** — la comparte la CLI (OCP).
2. **La creación de invitación es CLI-only hoy** (`iam:invitation:create` → `CreateInvitationCommand`). El único `#[Route]` bajo `Iam/Invitation/**` es *accept* (`AcceptInvitationController.php:37`). **U-2 introduce el primer endpoint HTTP de creación de invitación.**
3. **El rename `users.write → users.invite` ya ocurrió en U-1.** El enum PWA declara `Permission.USERS_INVITE = "users.invite"` (`Permission.ts:17`); la auth-data (`users.invite → [ADMIN]` en `EXPLICIT_GRANTS`, `users` en `TIER_OPT_OUT`, `users.invite` en `PermissionCatalog`) se cableó en U-0/U-1. **U-2 no renombra ni añade vocabulario — lo consume.**
4. **U-1 ya retiró el `UserForm` del mock** (`UserForm.tsx`, `users/new/page.tsx`, `UserFormSchema.ts` — borrados). U-2 **no «reemplaza» nada**: construye la superficie de invitación **desde cero** (email + roles; sin password ni status).
5. **El email de invitación es SÍNCRONO best-effort post-commit — nunca un transport.** `SecurityLinkMailer.php:18-23` lo declara: un email con token en claro **no puede** viajar en cola (se serializaría a la tabla del transport y a `failed`). NFR9 («invitar ENCOLA el email») significa aquí: *el email se manda síncrono best-effort tras el commit y el harness de Behat lo captura* — **no** una fila de Messenger.

Las **dos decisiones abiertas del addendum** («a decidir en el corte») quedan resueltas (ver *Decisiones ya tomadas*):

- **A · Endpoint = `POST /api/v1/backoffice/invitations`** en `Iam/Invitation/Infrastructure/Http/` (junto a `AcceptInvitationController`), envolviendo `SendInvitation` **sin seam cross-context nuevo**.
- **B · PWA = puerto identity-shaped dedicado.** Un caso de uso `InviteUser` propio hace el POST; `ApiUserRepository.create()` **sigue** como stub *no-soportado* (SI-18: la identidad NO es CRUD). Sienta el patrón para U-3.

> **Entrega: UN PR.** Backend (endpoint + markers) + PWA (puerto + form + gate) + tests (Behat email/eventos + e2e del alta) son una unidad demostrable — el e2e conduce `invite → INVITED`.

## Acceptance Criteria

1. **AC1 · Endpoint de alta autenticado.** `POST /api/v1/backoffice/invitations` con `#[IsGranted('users.invite')]` y
   payload `{email, roles}` (ADMIN) responde **`201 Created`** y crea una identidad `INVITED` vía
   `SendInvitation::invite($email, ...$roles)`. El body **no** incluye el accept-token ni una identidad (ver AC7);
   `SendInvitation` **no se modifica** (FR5, SI-18).
2. **AC2 · Gateo por permiso (SI-17).** Un usuario `VIEWER`/`EDITOR`/`MANAGER`/`AUDIT_READER` (no ADMIN) que haga el
   POST recibe **`403`** — `users` está en `TIER_OPT_OUT` y `users.invite → [ADMIN]`; ningún tier de negocio lo alcanza.
3. **AC3 · Eventos por el outbox, atómicos.** Una invitación exitosa deja en el outbox `erpify.iam.invitation.created`
   **+** `erpify.iam.invitation.sent` (más los eventos de Identity/Membership), publicados dentro de la misma
   transacción que persiste el agregado (`wrapInTransaction(save + publish)`). El token en claro **no** aparece en
   ningún evento (los eventos de Invitation cargan solo `invitedUserId`).
4. **AC4 · El email queda encolado (NFR9).** Tras el `201`, **exactamente 1** notification email fue capturado, con
   subject **`"Your ERPify invitation"`** y recipient = el email invitado. Verificado en Behat vía `NotificationContext`
   (`RecordingMailerSubscriber` captura el `Email` no-encolado que emite `SecurityLinkMailer`). **No basta el `201`.**
5. **AC5 · Validación en el borde → 422, sin efectos.** Payload inválido — email mal formado, `roles` vacío, o un rol
   fuera del enum — responde **`422 validation-failed`** por `#[MapRequestPayload]` + `#[Assert]`, **antes** de tocar el
   dominio; **0 identidades creadas, 0 eventos, 0 emails** (outbox vaciado y stats reseteadas *antes* de la request).
6. **AC6 · Re-invitación de un email existente → 422, sin escritura parcial.** Invitar un email ya en uso responde
   **`422`** con el mensaje `"This email is already in use."` (`#[UniqueEntity(email)]` vía `Validator::ensure` en
   `InviteUser`), **sin** crear identidad ni membership ni emitir eventos/emails (la validación corre dentro de la
   transacción y aborta el commit).
7. **AC7 · Contrato de error cerrado (NFR7, NFR26).** `UserAlreadyMember` — hoy `extends DomainException` **sin
   marcador** → mapea a `500` — recibe el marcador `Conflict` (**`409`**). El body de error fluye por el pipeline
   **RFC 9457** (nunca `JsonResponse` manual); `make php.lint.error-contract` verde. (`OrganizationNotProvisioned`
   es prácticamente inalcanzable vía HTTP en single-org — decisión + nota en Completion Notes.)
8. **AC8 · Puerto identity-shaped (PWA, SI-18).** La invitación va por un caso de uso `InviteUser` propio + su puerto,
   que hace `POST` al endpoint de invitación. `ApiUserRepository.create()` **sigue** rechazando con
   `UserProblemType.NOT_SUPPORTED` — la administración de identidades **no** es CRUD genérico.
9. **AC9 · Superficie de invitación gateada.** Una ruta dedicada `/backoffice/users/invite` monta un form de
   **invitación** (email + selector multi-rol; **sin** password ni status) gateado por
   `<Can permission={Permission.USERS_INVITE}>`; el disparador «Invite user» en el header de la lista
   (`users/page.tsx`) también va envuelto en `<Can permission={Permission.USERS_INVITE}>` (FR5, UX-DR3).
10. **AC10 · Reflejo de estado + errores persistentes.** Un alta exitosa → toast de éxito, navegación a la lista y la
    nueva fila `INVITED` aparece (refetch; realtime no requerido — UX-DR5). Un fallo → `<MutationError>` **persistente**
    sobre el form; las violaciones `422` se mapean a los campos del form (email / roles) vía `setError`.
11. **AC11 · e2e conduce el alta (NFR9).** Un spec real-API con sesión **ADMIN** navega al form, rellena un email
    **único** + un rol, envía, y verifica la nueva fila `INVITED` (filtrando por ese email) + la señal de éxito —
    **sin exact-counts** (la DB de dev acumula identidades).
12. **AC12 · Vocabulario byte-idéntico (SI-20).** `users.invite` es idéntico byte-a-byte en `#[IsGranted('users.invite')]`
    (API) y `Permission.USERS_INVITE` (PWA) — ya alineado en U-1; U-2 lo **consume** sin re-tocar el enum.
13. **AC13 · Coste de query fijado.** El escenario Behat del alta fija el presupuesto de query del write envuelto
    (`+2` BEGIN/COMMIT sobre la línea base; ver *Gotchas*).

## Tasks / Subtasks

### A — Endpoint de invitación · `api/src/Iam/Invitation/` (AC1–AC4, AC13)

- [ ] **Request DTO** `InviteUserRequest.php` **nuevo** (junto a `AcceptInvitationRequest.php` en `Infrastructure/Http/`,
      o `Application/` según encaje deptrac). Campos: `string $email` (`#[Assert\NotBlank]`,
      `#[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT)]`) + `array $roles`
      (`#[Assert\Count(min: 1)]` + `#[Assert\All([new Assert\Choice(choices: [...Role values]))])]`, o
      `#[Assert\Choice(callback: [Role::class, 'cases'])]` por elemento). Sin `password`, sin `status` (SI-18).
- [ ] **Controller** `CreateInvitationController.php` (o `InviteUserController.php`) **nuevo** en
      `Iam/Invitation/Infrastructure/Http/`. `#[Route('/invitations', name: 'backoffice_invitation_create', methods: ['POST'])]`
      (el resource `api_v1_iam_invitation` prefija `/api/v1/backoffice` → resuelve a `/api/v1/backoffice/invitations`);
      `#[IsGranted('users.invite')]` (**string literal**, como `UserSearchController` con `'users.read'`);
      `#[MapRequestPayload] InviteUserRequest`. Mapea los strings de `roles` → `Role::from(...)` en el borde (patrón
      `CreateInvitationCommand.php:87`) y llama `SendInvitation::invite($email, ...$roles)`.
- [ ] **Respuesta = `201 Created` sin cuerpo de identidad.** Devolver `new Response(status: Response::HTTP_CREATED)`
      (o `{data: null}` por el responder JSON). **NO** devolver el accept-token (secreto) ni un `UserListResource`
      (reintroduciría el seam `Invitation → Identity` — ver *Crux 1*). La consola refresca la lista (AC10).
- [ ] **Seguridad de ruta:** endpoint **autenticado same-origin** (cookie de sesión + `IsGranted`) → **sin** el
      `#[IsCsrfTokenValid]`/`OriginListener` del flujo *público* de accept; sigue el precedente de los writes de
      backoffice (`BankPostController`/`BankAccountPostController`).

### B — Cerrar el contrato de error (API) (AC7)

- [ ] `UserAlreadyMember` (`api/src/Organization/Membership/...`) — hoy `extends DomainException` sin marcador → aplica
      el marcador existente `Conflict` (mapea a `409` en `ProblemDetailsFactory.php:114-122`). **No** añade una entrada
      nueva al mapa marker→status (Conflict→409 ya está) → probablemente **sin** cambio en `docs/api-error-contract.md`;
      **verifícalo** con `make php.lint.error-contract` y actualiza el doc solo si el gate lo pide (NFR26).
- [ ] `OrganizationNotProvisioned` — evaluar (single-org bootstrap lo hace casi inalcanzable vía HTTP). Marcarlo
      `Conflict`/dejar `500` con nota; **decide y justifica en Completion Notes**.
- [ ] Test unitario que fije el mapeo (p.ej. `UserAlreadyMemberIsConflictTest` o extender el contrato de markers).

### C — Puerto identity-shaped + superficie de invitación (PWA) (AC8–AC10, AC12)

- [ ] **Endpoint** en `ApiEndpoints.ts` — añadir el bloque/entrada `INVITATIONS.CREATE = `${BACKOFFICE_PREFIX}/invitations``.
- [ ] **Puerto + adapter** `InviteUserRepository.ts` (domain) + `ApiInviteUserRepository.ts` (infrastructure) **nuevos**
      en `context/backoffice/user/`. Método `invite(input: { email: string; roles: Role[] }): Promise<void>` →
      `httpClient.post(INVITATIONS.CREATE, input)` (sin body de respuesta a validar; un `201` vacío es éxito).
- [ ] **Caso de uso** `InviteUser.ts` (application) **nuevo** — `run(input): Promise<void> { return this.repository.invite(input); }`
      (patrón `CreateBank.ts`). Bind en `Container.ts`: `"BackOfficeInviteUser"` → `InviteUser`,
      `"BackOfficeInviteUserRepository"` → `ApiInviteUserRepository`.
- [ ] **Schema** `InviteUserSchema.ts` **nuevo** — `email` (`.trim().min(1).max(255).email(...)`) +
      `roles: z.array(z.enum(Role)).min(1, "Select at least one role.")`. **Sin** `status`. **Verifica primero** si el
      leftover `UserCreateSchema.ts` (PR #269) sigue en uso (`git grep -l UserCreateSchema`): U-1 lo dio por vivo (usado
      por `LoginSchema`/`ForgotPasswordSchema`), un análisis posterior lo vio huérfano — **compruébalo**; si está
      muerto, retíralo (boy-scout), si no, **no** lo repurposees: crea `InviteUserSchema` aparte.
- [ ] **Ruta + página** `app/backoffice/users/invite/page.tsx` **nueva** (server page fina: back-link + `<h1>` +
      `<InviteUserForm>`), patrón `banks/new/page.tsx`. Añade `usersRoutes.invite = "/backoffice/users/invite"` a
      `_lib/userRoutes.ts`.
- [ ] **Form** `_components/InviteUserForm.tsx` **nuevo** (`"use client"`), patrón `BankForm.tsx`:
      `useZodForm(InviteUserSchema)`; marcador de hidratación (`data-hydrated`); `<MutationError>` **sobre** el form
      (nunca dentro de un dialog); submit → `container.get<InviteUser>("BackOfficeInviteUser").run(values)` →
      `toastNotifier.success("Invitation sent", …)` → `router.push(safeHref(usersRoutes.list))` + `router.refresh()`;
      `handleHttpError` mapea `422` → `setError(field)`, resto → `setProblem`. Botón con `<Spinner>` mientras `isSubmitting`.
- [ ] **Selector multi-rol** (net-new — **no** existe primitivo de grupo, verificado). Un `<fieldset>`/`<legend>` con un
      checkbox por `ALL_ROLES` (label vía `ROLE_LABEL`), cableado a `roles` con `useZodForm` (`setValue`/`watch` o
      `register`), a11y por `useFormField()`. Alternativa: añadir un primitivo `checkbox` a `components/ui/` — decide y
      justifica (KISS: fieldset directo para 5 valores fijos).
- [ ] **Disparador en la lista** `users/page.tsx` — botón/Link «Invite user» en el slot de acción vacío del header,
      envuelto en `<Can permission={Permission.USERS_INVITE}>` → `<Link href={usersRoutes.invite}>` (patrón del
      `banks-list__new-button`, pero **gateado**).
- [ ] `ApiUserRepository.create()` **NO se toca** — sigue como stub `notSupported("creating a user")` (AC8).
- [ ] Comentarios/JSDoc: sin IDs de historia ni comentarios change-relative en el diff final (regla de comentarios).

### D — Tests (Behat + unit + e2e) (AC3–AC6, AC11, AC13)

- [ ] **Behat** `api/features/backoffice/identity/invitation_create.feature` **nuevo** (o `.../invitation/`). Escenarios:
      - **Éxito (ADMIN):** `I am logged in as an administrator` → POST `{email, roles}` → `201`; identidad `INVITED`
        creada (SQL); `there should be 1 event stored named "erpify.iam.invitation.created"` **+** `...sent`;
        `1 notification email was sent`, subject `"Your ERPify invitation"`, recipient = invitee (AC4). Incluye el step
        de presupuesto de query (AC13).
      - **403 (no ADMIN):** un VIEWER/EDITOR → `403`, 0 eventos, 0 emails.
      - **422 (payload inválido):** email malo / roles vacío / rol desconocido → `422`; vaciar outbox + reset stats
        **antes**, assert **0** eventos y **0** emails **después** (patrón `behat-assert-zero-new-events-on-failure`).
      - **422 (email duplicado):** re-invitar `admin@erpify.test` (fixture) → `422` "This email is already in use.",
        0 eventos/emails.
      - Cada escenario mutating necesita email fresco o `I reload the fixtures` (la DB se resetea; `#[UniqueEntity]`).
- [ ] **Unit:** `InviteUserRequest` (constraints), el mapeo rol-string→enum, el controller (thin, `#[CoversClass]` — nunca
      `#[CoversNothing]`), y el marker de AC7. PWA: `InviteUser`/`ApiInviteUserRepository` (mockea `HttpClient`),
      `InviteUserSchema` (email/roles), y el form (submit feliz + error → `MutationError`).
- [ ] **e2e (AC11):** extiende `pwa/tests/e2e/backoffice/users-real-api.spec.ts` (o spec nuevo real-API) — usa
      `authenticatedTest` + `workerStorageState` (sesión ADMIN por worker). Navega a `/backoffice/users/invite`, email
      único (p.ej. sufijo por worker), selecciona un rol, envía; assert toast de éxito + fila `INVITED` filtrando por el
      email (`users-filters__email` → `users-table__row-…` + `UserStatusBadge` INVITED). **Nunca exact-counts.**

### Verificaciones (Working principle 4)

- [ ] `make php.stan` por fichero PHP tocado (`PHP_SERVICE=messenger_worker` si segfaultea) · `make php.quality` al final
      (deptrac + PHPMD + cs-fixer + PHPCS).
- [ ] `make php.deptrac` · `make php.lint.bounded-context` — **verdes sin tocar el allowlist** (Crux 1: el endpoint vive
      en el contexto Invitation → cero seam nuevo).
- [ ] `make php.lint.error-contract` — verde (AC7).
- [ ] `make php.unit` · `make php.behat` — verdes completos.
- [ ] `make pwa.quality` · `make pwa.test.unit`.
- [ ] e2e contra el stack del worktree: puerto efímero (`docker compose port php 443`) +
      `PLAYWRIGHT_HOST_PLATFORM_OVERRIDE=ubuntu24.04-x64`; ADMIN sembrado **después** de Behat
      (`organization:provision` + `organization:administrator:create e2e@erpify.test e2ePassword123`).
- [ ] `curl -k` en vivo: POST invitación con sesión ADMIN → `201` + (revisar el mailer del stack / log) el email; con
      VIEWER → `403`.

## Dev Notes

### Crux 1 — el endpoint vive en `Iam/Invitation`, y por qué la respuesta NO lleva identidad

`SendInvitation` está en `Iam\Invitation\Application`. **No existe** ningún seam `Identity → Invitation` en el repo; todos
los seams registrados van `Invitation → Identity/Organization` (allowlist + `deptrac.yaml` `skip_violations`
`:490-499`). Colocar el controller en `Iam/Identity/` para servir `/backoffice/users` **crearía un import cruzado
`Identity → Invitation` nuevo** → 2 entradas de gobernanza + inversión del grafo de dependencia (riesgo de ciclo a nivel
de contexto). Colocarlo en `Iam/Invitation/Infrastructure/Http/` (junto a `AcceptInvitationController`) envuelve
`SendInvitation` **en su propio contexto, cero seam**.

**Corolario:** la respuesta `201` **no** puede devolver un `UserListResource` (DTO de `Identity`) — eso reintroduciría
el mismo seam por la puerta de atrás. Y `SendInvitation::invite` devuelve el **accept-token** (secreto), que **jamás**
va en el body. Por eso el `201` es **vacío** y la consola **refresca** la lista (UX-DR5: «reload basta»). Cambiar
`SendInvitation` para que devuelva la `Invitation` rompería el contrato OCP que comparte con la CLI — descartado.

### Crux 2 — «email encolado» (NFR9) = síncrono best-effort, capturado por el subscriber

El email de invitación **no** viaja en Messenger — `SecurityLinkMailer.php:18-23` lo prohíbe (el token en claro se
serializaría al transport + `failed`). El flujo: `SendInvitation` commitea la escritura + publica eventos en el outbox
**dentro** de la transacción; **después** del commit llama `SendInvitationEmailBestEffort->send(...)` (traga y loguea
cualquier `Throwable` para no abortar al caller tras el flip). Pese a ser síncrono, el email pasa por `MailerInterface`
→ dispara `MessageEvent` con `isQueued() === false` → `RecordingMailerSubscriber` (`tests/Behat/Support/Notification/`)
lo graba. Así los steps de `NotificationContext` (`:39`, `:58`, `:73`, `:79`) — hoy ejercitados por
`bank/create.feature:52-55` — valen tal cual para el email de invitación (subject `"Your ERPify invitation"`).
**No hay reactor** para este email (contraste: `PasswordResetCompleted` **sí** es async por reactor, porque su payload
es solo el id — sin token).

### Crux 3 — el contrato de error que la CLI escondía

Mientras la creación era CLI-only, `UserAlreadyMember`/`OrganizationNotProvisioned`/`InvitedIdentityUnavailable`
(`extends DomainException`, **sin marcador**) se **capturaban e imprimían** en el comando (`CreateInvitationCommand.php:61-67`)
→ nadie notaba que sin marcador mapean a `500`. Exponerlas por HTTP **obliga** a marcarlas (AC7). El caso común de
re-invitación (email repetido) lo atrapa **antes** `#[UniqueEntity(email)]` en `InviteUser::invite` → `422` (`Validator::ensure`),
así que `UserAlreadyMember` queda mayormente **latente** para el path HTTP; aun así se marca `Conflict` (409) por
corrección defensiva. Duplicado de email = `422 validation-failed` (mecanismo existente, no lo cambies a 409).

### Crux 4 — puerto identity-shaped, no `create()` genérico (SI-18)

SI-18 fija que *«los `create/update/delete` que hoy hereda del toolkit genérico se sustituyen por invite/changeStatus,
nunca por un `PUT/PATCH` genérico de identidad»*. Por eso la invitación va por un **caso de uso propio** `InviteUser`
(+ su puerto), **no** por `ApiUserRepository.create()` (que sigue como stub *no-soportado*). Beneficio concreto: la
consola deja de fingir que una identidad es un recurso CRUD, y el patrón queda **listo para U-3** (`changeStatus`
tendrá su propio puerto igual). Coste: una clase de caso de uso + un puerto más que la variante «cablear el stub» — se
acepta porque el stub perpetúa la mentira que SI-18 nombra, y U-3 lo reutiliza (no es abstracción para un solo caller).

### Roles al invitar (verificado)

`SendInvitation::invite(string $email, Role ...$roles)` acepta variádico `Role` hasta el fondo
(`InviteUser`/`GrantMembership`). **No hay guard sobre qué roles puede asignar un inviter** — cualquier `Role` (incl.
`ADMIN`) es asignable; es aceptable (la acción entera es ADMIN-only). El form exige **≥1 rol** (`InviteUserSchema`,
`.min(1)`); la CLI por defecto usa `[VIEWER]`, pero en UI el ADMIN elige explícitamente. El DTO valida los strings
contra el enum (`#[Assert\Choice]`) → rol desconocido = `422` en el pipeline estándar.

### Auth en la capa de Application (nota de decisión)

`project-context.md:356` recomienda no depender **solo** de `#[IsGranted]` de controller. Aquí se sigue el precedente
de U-0 (`UserSearchController` gatea **solo** a nivel de controller con `#[IsGranted('users.read')]`): añadir un check
de autorización dentro de `SendInvitation` **acoplaría la CLI** (que lo llama sin sesión). Decisión: gateo a nivel de
controller, coherente con el read-side; el enforcement real es el `#[IsGranted]` + el `PermissionVoter`. Documentarlo en
Completion Notes si un revisor lo cuestiona.

### Ficheros a tocar (verificado en `main @ b8b13b61`)

| Fichero | Acción |
|---|---|
| `api/src/Iam/Invitation/Infrastructure/Http/CreateInvitationController.php` | **NUEVO** — `POST /invitations`, `#[IsGranted('users.invite')]`, `#[MapRequestPayload]` |
| `api/src/Iam/Invitation/Infrastructure/Http/InviteUserRequest.php` | **NUEVO** — DTO `{email, roles}` con `#[Assert]` |
| `api/src/Organization/Membership/…/UserAlreadyMember.php` | +marcador `Conflict` (409) |
| `api/features/backoffice/identity/invitation_create.feature` | **NUEVO** — éxito/403/422×2 + email + eventos + budget |
| `api/tests/Unit/Iam/Invitation/…` (controller, request, marker) | **NUEVOS** |
| `pwa/src/context/shared/http-client/infrastructure/ApiEndpoints.ts` | +`INVITATIONS.CREATE` |
| `pwa/src/context/backoffice/user/domain/InviteUserRepository.ts` | **NUEVO** — puerto `invite()` |
| `pwa/src/context/backoffice/user/infrastructure/ApiInviteUserRepository.ts` | **NUEVO** — POST adapter |
| `pwa/src/context/backoffice/user/application/InviteUser.ts` | **NUEVO** — caso de uso |
| `pwa/src/context/backoffice/user/application/schemas/InviteUserSchema.ts` | **NUEVO** — `email + roles`, sin `status` |
| `pwa/src/context/shared/dependency-injection/infrastructure/Container.ts` | +2 binds (`BackOfficeInviteUser*`) |
| `pwa/src/app/backoffice/users/invite/page.tsx` | **NUEVO** — server page fina |
| `pwa/src/app/backoffice/users/_components/InviteUserForm.tsx` | **NUEVO** — form (patrón `BankForm`) |
| `pwa/src/app/backoffice/users/_lib/userRoutes.ts` | +`invite` |
| `pwa/src/app/backoffice/users/page.tsx` | +botón «Invite user» gateado por `<Can users.invite>` |
| `pwa/src/context/backoffice/user/application/schemas/UserCreateSchema.ts` | **VERIFICAR** vivo/huérfano; boy-scout si muerto |
| `pwa/tests/…` (InviteUser, schema, form) + `pwa/tests/e2e/backoffice/users-real-api.spec.ts` | tests |

### Testing (patrones del repo)

- **`NotificationContext` + `RecordingMailerSubscriber`** = el harness de email (captura cualquier `Email` por
  `MailerInterface`); `MAILER_DSN=null://null` en `.env.test`. Precedente de uso: `bank/create.feature:52-55`.
- **Eventos:** `EventStoreContext`/`OutboxContext` — `there should be N event(s) stored named "<name>"`; nombres
  `erpify.iam.invitation.created` / `...sent`.
- **Assert 0 en fallo:** vaciar outbox + reset stats **antes** de la request, assert 0 **después**
  (`behat-assert-zero-new-events-on-failure`).
- **Sesión ADMIN en Behat:** `I am logged in as an administrator` (`SecurityContext`) usa el fixture `user_admin`
  (`admin@erpify.test`) y siembra el `iamSessionId` en la sesión para pasar el admission gate.
- **Cobertura de controlador fino:** `#[CoversClass(TheController)]`, **nunca** `#[CoversNothing]` (los wire-gates
  funcionales alimentan la cobertura; Behat no da clover — ver `sonar-coversnothing-zeroes-thin-controllers`).
- **PWA:** mockea `HttpClient` en los tests del adapter/caso de uso; el form se testea con submit feliz + un error que
  renderiza `<MutationError>`. e2e = real-API, sesión por worker (`authenticatedTest`), sin exact-counts.

### Gotchas heredados (verificados)

- **`make php.behat` resetea la DB** → siembra el ADMIN del e2e **después** de Behat (password posicional;
  `organization:provision` primero).
- **Presupuesto de query +2 por write envuelto** (BEGIN/COMMIT) — un create sube +2 sobre la línea base
  (`behat-query-budget-transaction-overhead`).
- **Inline SQL step de Behat: sin comillas dobles** embebidas (el matcher trunca) — usa PyString o JSONB `'{}'` sin
  comillas (`behat-inline-sql-step-no-double-quotes`).
- **`php.stan` puede segfaultear** en el worker web (exit 139) → `PHP_SERVICE=messenger_worker`.
- **Rector** borra `/** @var T */` sin nombre sobre `return` en tests → `/** @phpstan-var T */`; e inlinea el FQCN en
  closures de `array_map` (revienta 120 chars) → importa el tipo.
- **`users` es PLURAL** (SI-20) — `users.invite`, nunca `user.invite`.
- **e2e en worktree:** `PLAYWRIGHT_BASE_URL`/`_API_BASE_URL` al puerto efímero; EACCES → `rm -rf pwa/.next-e2e && rm -f
  pwa/next-env.d.ts`.

### Decisiones ya tomadas — no re-abrir

| # | Decisión | Argumento |
|---|---|---|
| D1 | Endpoint `POST /api/v1/backoffice/invitations` en `Iam/Invitation/Infrastructure/Http/` | Envuelve `SendInvitation` **en su contexto** → cero seam nuevo; hermano de `/invitations/accept`; REST honesto (crea una Invitation, SI-18). Poner en `Iam/Identity/` para URL `/users` fuerza un import cruzado `Identity→Invitation` + allowlist+deptrac + inversión del grafo (Sergio: `/backoffice/invitations`) |
| D2 | Respuesta `201` **vacía**; la consola refresca | Devolver un `UserListResource` reintroduce el seam; el accept-token es secreto; cambiar `SendInvitation` rompe el OCP que comparte la CLI |
| D3 | PWA: caso de uso `InviteUser` + puerto **dedicado**; `create()` sigue no-soportado | SI-18 (identidad no es CRUD); sienta el patrón para U-3; no es abstracción para un solo caller (Sergio: identity-shaped) |
| D4 | Duplicado de email = `422` (`#[UniqueEntity]`), no `409` | Mecanismo existente; cambiarlo a 409 es scope creep. `UserAlreadyMember` (membership) sí → `Conflict` 409 |
| D5 | Gateo **de controller** (`#[IsGranted]`), no en `SendInvitation` | Añadir authz en la App acopla la CLI (la llama sin sesión); precedente de U-0 read-side |
| D6 | Ruta dedicada `/backoffice/users/invite`, no modal | Precedente **uniforme** de la consola (Banks/BankAccounts = ruta `new`); cero create-via-modal; el header de la lista ya tiene el slot |
| D7 | Selector multi-rol = fieldset de checkboxes | No existe primitivo de grupo; 5 valores fijos → KISS sobre añadir un primitivo `ui/checkbox` |

### Fuera de alcance (frontera explícita)

- **Cambio de estado (suspend/deactivate)** → U-3. U-2 no añade `PATCH .../status`.
- **Reenviar / revocar invitación** (existen como CLI: `iam:invitation:resend`/`revoke`) — sus superficies HTTP no son
  U-2. Solo *crear*.
- **Guardar qué roles puede asignar un inviter** (hoy: cualquiera). Si aparece la necesidad (no delegar `ADMIN`), es
  una historia propia.
- **Filtro por rol** en la lista — diferido desde U-0 (JSONB, sin operador de containment).
- **Tenancy / `Membership.roles` como fuente autoritativa** — SI-15, diferido.
- **Migrar el read-side (`search`/`find`) a puertos identity-shaped** — se queda en `CrudRepository` (la lectura sí
  encaja en `useResourceList`); solo las **mutaciones** salen del toolkit genérico.

### Project Structure Notes

- El controller + DTO en `Iam/Invitation/Infrastructure/Http/` (misma layer deptrac `Iam.Invitation.Infrastructure`
  que `AcceptInvitationController`) → limpio, sin allowlist. El `#[Route]` resuelve `/api/v1/backoffice/invitations`
  vía el prefijo del resource `api_v1_iam_invitation` (`routes.yaml:26`).
- PWA: caso de uso + puerto + adapter en `context/backoffice/user/{application,domain,infrastructure}`; la ruta/página
  y el form en `app/backoffice/users/`. Sin migraciones, sin cambios de entidad (la identidad `INVITED` y la
  `Invitation` ya existen).

### References

- [Source: `_bmad-output/planning-artifacts/epics-users-admin.md#Story 1.3 (U-2)`] — AC (post-U-1); FR5 enmendado
  («no reemplaza; construye la superficie real»).
- [Source: `_bmad-output/planning-artifacts/arch-addendum-users-admin.md`] — SI-16…SI-20; fila U-2 (placement «a
  decidir en el corte», resuelto en D1/D3); DAG.
- [Source: `_bmad-output/implementation-artifacts/u-1-me-deriva-permisos-gateo-can.md`] — U-1 alineó el vocabulario y
  retiró el `UserForm` del mock; `Permission.USERS_INVITE` + auth-data ya cableadas.
- [Source: `api/src/Iam/Invitation/Application/SendInvitation.php:60`] — el caso de uso a envolver; docblock `:29-30`
  anticipa la superficie HTTP.
- [Source: `api/src/Shared/Mailer/Infrastructure/SecurityLinkMailer.php:18-23`] — el email de token es síncrono a
  propósito (nunca transport).
- [Source: `api/src/Iam/Invitation/Infrastructure/Cli/CreateInvitationCommand.php:18-23`] — la CLI que U-2 «asciende» a
  HTTP.
- [Source: `docs/adr/identity-invitation-lifecycle.md`] — D3/D5 (roles en `Membership`, Invitation = delivery), D12
  (contrato de error graduado).
- [Source: `docs/project-context.md:356`] — auth en la capa de Application (motivo de la nota D5).

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

### Change Log

| Fecha | Cambio |
|---|---|
| 2026-07-17 | Historia creada `ready-for-dev` — endpoint en contexto Invitation (D1) + puerto identity-shaped PWA (D3) |
