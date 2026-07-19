---
baseline_commit: a8a88bf31c0867754feec7d2ba2be0017975d56a
---

# Story 1.5 (U-4): Edición de roles

Status: ready-for-dev

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

Como **ADMIN**,
quiero cambiar el conjunto de roles de un usuario desde la consola,
para ajustar sus capacidades sin re-invitarlo, sin poder dejar la organización sin un administrador activo.

## Contexto (leer antes de tocar código)

U-4 de la épica `users-admin` (orden safe-first `U-0 → U-1 → (U-2 · U-3) → U-4 · [U-5a → U-5b]`). U-0 (read-side),
U-1 (`/me` deriva permisos + `<Can>`), U-2 (invitar) y **U-3 (cambio de estado)** están **done/merged** en `main`
(PRs #501/#502/#503/#504/#506). U-4 es la **tercera superficie de acción** sobre el ciclo de vida de identidad y
**completa la Regla-de-Tres** de puertos identity-shaped (invite + changeStatus + changeRoles) → a partir de aquí es
reuso de patrón, no abstracción especulativa.

**El épico marcaba U-4 «candidato / a decidir en el corte».** Sus dos decisiones abiertas quedan cerradas por Sergio
antes de dev y localizadas abajo (Decisiones D2 y D6): **la fuente de verdad de los roles es `User.roles` solo**
(single-context, se difiere `Membership.roles` a tenancy — espeja la D2 de U-3) y **la edición se permite en cualquier
estado no-erased** (los roles son ortogonales al ciclo de vida). El resto del diseño se destila del contrato FR7/SI-15
del addendum y de los AC de la Story 1.5 del épico.

**U-4 NO es un mirror limpio de U-3 — es full-stack con cinco divergencias verificadas** (contra el supuesto «espeja
U-3 y ya»). Las tres primeras condicionan el diseño; se detallan en los *Crux* de Dev Notes:

1. **El guard ≥1 ADMIN se invoca CONDICIONALMENTE** (U-3 lo invocaba siempre). Un cambio de roles solo pone en riesgo
   el invariante cuando **demueve a un admin ACTIVE** (le quita `ADMIN`); un `add`/`same-set`/edición de no-admin NO
   debe dar 409.
2. **La invalidación de sesión al cambiar roles YA ocurre de forma nativa** (a diferencia del status flip de U-3): el
   cambio de roles dispara la des-autenticación de Symfony por sí solo. `RevokeSessionsBestEffort` se cablea igual que
   U-3 pero como **defensa-en-profundidad**, no como única barrera.
3. **El payload es un ARRAY de roles** (semántica *set*), no un enum escalar como el status → el DTO espeja
   `InviteUserRequest`, y el editor PWA reutiliza el fieldset de checkboxes del form de invitación.
4. **Hay que crear un mutador de dominio `User::changeRoles(Role ...)`** (hoy no existe: `register`/`invite` fijan los
   roles solo al alta) **y un evento nuevo `UserRolesChanged`**.
5. **`users.changeRoles` no existe** en el vocabulario RBAC — hay que añadirlo a catálogo + policy + enum PWA + tests
   de vocabulario (U-3 consumía un `users.changeStatus` ya alineado desde U-1; este permiso es nuevo).

**Nueve hechos verificados en el código (mapeo de dos exploradores paralelos) — no re-derives:**

1. **`User` (`api/src/Iam/Identity/Domain/Entity/User.php`)** `extends AggregateRoot` (traits `Identifiable` +
   `Timestamped`). `roles` es `#[ORM\Column(type: Types::JSON)] private array $roles` como **`list<string>`** (los
   `Role->value`, no el enum). **La columna Postgres es `json`, NO `jsonb`** (por eso el guard de U-3 castea
   `roles::jsonb`). Accesor `roles(): list<Role>` (mapea con `Role::from`). El constructor dedupe con
   `distinctRoleValues()` (preserva orden). **No existe ningún mutador de roles** — U-4 añade
   `User::changeRoles(Role ...$roles)` (reemplaza el set completo, dedup con el helper existente, bumpea `updatedAt`
   vía `SystemClock::now()`, `record(UserRolesChanged)`). El docblock de `guardTransitionTo()` avisa: *los invariantes
   cross-agregado (≥1 ADMIN) viven en el use case, no en la entidad* — igual que U-3.
2. **`Role` (`api/src/Shared/Access/Domain/Role.php`, `Erpify\Shared\Access\Domain\Role`)** — cases
   `VIEWER/EDITOR/MANAGER/ADMIN/AUDIT_READER`, vocabulario compartido (movido a `Shared` en U-2). **No tiene helper
   `values()`**: el patrón `Role::cases() → ->value` vive en su único caller `InviteUserRequest::roleValues()` (reusar,
   ver hecho 6).
3. **`ChangeUserStatus` (`api/src/Iam/Identity/Application/ChangeUserStatus.php`, `final readonly class`)** — precedente
   directo del app service. Deps: `UserRepository`, `ActiveAdministratorDirectory`, `RevokeSessionsBestEffort`,
   `EventBus`, `TransactionManager`. Flujo (tras el fix TOCTOU de U-3): `Uuid::ensure` → abre `transactional(...)` →
   **dentro de la tx** `findById ?? UserNotFound` → **guard dentro de la tx** (con el `FOR UPDATE` sostenido) → mutar →
   `save` + `publish(...pullDomainEvents())` en la misma tx → **post-commit** `revokeSessions->revoke($userId)`
   (best-effort). U-4 replica esta forma exacta, con la invocación condicional del guard (Crux 2).
4. **Guard reusable TAL CUAL** — puerto `ActiveAdministratorDirectory::keepsAnActiveAdminWithout(string $userId): bool`
   (`api/src/Iam/Identity/Domain/Repository/`) + adapter `DoctrineActiveAdministratorDirectory`
   (`api/src/Iam/Identity/Infrastructure/Persistence/Doctrine/`, `SELECT id FROM identity_user WHERE status=:active AND
   roles::jsonb @> CAST(:adminRole AS jsonb) FOR UPDATE`, lockea el set completo, excluye `$userId` en PHP). **U-4 NO
   crea guard nuevo** — reusa este; solo cambia la *política de invocación* (condicional, Crux 2). Excepción
   `LastActiveAdministratorProtected` (`implements Conflict` → **409**, `type: last-active-administrator-protected`) ya
   existe.
5. **La des-autenticación nativa YA dispara con un cambio de roles.** `SecurityUser`
   (`api/src/Iam/Identity/Infrastructure/Security/SecurityUser.php`) **no** implementa `EquatableInterface`, así que
   `AbstractToken::hasUserChanged()` compara **roles** (además de password e identificador); `UserProvider::refreshUser`
   recarga el user cada request; un set de roles distinto → `TokenDeauthenticatedEvent` →
   `RevokeSessionOnTokenDeauthenticated` (`api/src/Iam/Session/Infrastructure/Security/`) revoca la sesión **actual**.
   Límite: es *lazy* (solo en el siguiente request del afectado) y solo la sesión **actual**. Por eso U-4 **igual cablea
   `RevokeSessionsBestEffort`** post-commit — para un corte **eager + todos los dispositivos** — pero como
   **defensa-en-profundidad** (mismo racional que `CompletePasswordReset`; su docblock lo dice literal), no como única
   barrera (contraste con U-3, donde el status flip no toca roles/credencial → la única barrera era el revoke).
6. **DTO array-of-Role — el template correcto es `InviteUserRequest`, NO el status.** `ChangeUserStatusRequest` valida
   un enum **escalar** (`#[Assert\Choice]`) — no aplica a un array. `InviteUserRequest`
   (`api/src/Iam/Invitation/Infrastructure/Http/`) ya valida un array de roles como **strings**:
   `#[Assert\Count(min: 1, minMessage: ...)]` + `#[Assert\Choice(callback: [self::class, 'roleValues'], multiple: true,
   multipleMessage: ...)]`, con `roleValues()` estático = `array_map(fn(Role $r) => $r->value, Role::cases())`. El
   controller mapea strings→enum con `Role::from(...)` (patrón `CreateInvitationController::rolesFrom()`). U-4 clona esa
   forma en `ChangeUserRolesRequest`.
7. **Recurso de detalle ya expone `roles`.** `UserResourceMapper::toDetailResource()` produce `UserDetailResource`
   (`id, email, status, list<string> roles, createdAt, updatedAt`) — el PATCH de roles devuelve **el mismo DTO** que el
   de status; **sin cambios en mapper/DTO**. Orden de claves fijado por el golden:
   `['id','email','status','roles','createdAt','updatedAt']`.
8. **`Organization/Membership.roles` existe pero está dormante para mutación** (top-level `api/src/Organization/`, NO
   bajo `Iam/`): `list<string>` `json`, escrito **solo** en `GrantMembership::grant()` (onboarding: `SendInvitation` +
   `CreateInitialAdministratorCommand`), **nunca actualizado**; nadie lo lee operativamente hoy (guard/read-model/auth
   leen `User.roles`). **Decisión D2 (Sergio): U-4 escribe solo `User.roles`** — el drift de `Membership.roles` es
   latente hasta que tenancy re-apunte la fuente (misma re-apunta que ya deberá hacer el read-model). `FindUserOrganizationId`
   + `MembershipRepository::findByUserId` existen si algún día se reconcilia — **fuera de alcance aquí**.
9. **Vocabulario RBAC — `users.changeRoles` es NUEVO.** Hoy `users.{read,invite,changeStatus,erase}` → `[ADMIN]` en
   `EXPLICIT_GRANTS` de `StaticAuthorizationPolicy` (+`users` en `TIER_OPT_OUT`). U-4 añade `users.changeRoles →
   [Role::ADMIN->value]` en la policy **y** al `PermissionCatalog` (`PERMISSIONS` const) — este último es **obligatorio**
   (el test `PermissionCatalogCoversEveryGatedRouteTest` barre todo `#[IsGranted]` y falla el build si el string no está
   en el catálogo). Los tripwires data-only (`StaticAuthorizationPolicyIsDataOnlyTest` / `PermissionCatalogIsDataOnlyTest`)
   tokenizan por **nombre de const** → una entrada literal pasa. En PWA: `Permission.USERS_CHANGE_ROLES =
   "users.changeRoles"` byte-idéntico (SI-20).

**El precedente exacto a espejar (verificado, ambos lados):**

- **API — el trío de U-3:** controller `UserPatchStatusController` (`#[Route('/backoffice/users/{id}/status',
  methods:['PATCH'])]` + `#[IsGranted('users.changeStatus')]` + `#[MapRequestPayload]`), app service `ChangeUserStatus`,
  DTO `ChangeUserStatusRequest`, `UserResourceMapper`, `ResourceResponder`, feature `features/backoffice/users/status.feature`.
  U-4 clona la forma; el cuerpo del controller mapea `{roles: string[]}` → `Role::from(...)` → `ChangeUserRoles::run($id, $roles)`.
- **API — validación array-of-Role:** `InviteUserRequest` / `CreateInvitationController::rolesFrom()` (hecho 6).
- **PWA — el trío de U-3:** `UserStatusControl.tsx` (`app/backoffice/users/_components/`) + puerto
  `ChangeUserStatusRepository` + adapter `ApiChangeUserStatusRepository` (PATCH, revalida `isUserSingleResponse`) + use
  case `ChangeUserStatus` + 2 binds DI + `ApiEndpoints.USERS.CHANGE_STATUS` + montaje en `[id]/page.tsx` con `reload()`.
- **PWA — el editor de conjunto (checkbox multi-rol):** el fieldset del form de invitación de U-2
  (`InviteUserForm.tsx`: `<fieldset>` + `ALL_ROLES.map(role => <input type="checkbox" value={role}
  {...register("roles")} />)` + `<legend>` + slot de error) y su schema `InviteUserSchema`
  (`z.array(z.enum(Role)).min(1, ...)`). **Reusar este patrón** (pre-marcado desde `user.roles`) — es el único punto
  donde U-4 es más complejo que U-3, y la solución ya existe.

> **Entrega: UN PR.** Backend (mutador + evento + endpoint + app service + guard condicional + revoke + datos RBAC) +
> PWA (puerto + control gateado en el detalle + vocabulario) + tests (Behat 403/401/409/422/200 set/unchanged-no-op +
> unit + e2e del ciclo) son una unidad demostrable — el e2e edita el set de roles de un usuario no-admin y verifica los
> `RolesBadges` resultantes.

## Acceptance Criteria

1. **AC1 · Endpoint autenticado + gateado + set semantics.** `PATCH /api/v1/backoffice/users/{id}/roles` con
   `#[IsGranted('users.changeRoles')]` (string literal) y payload `{roles: string[]}`, ejecutado por un **ADMIN** sobre
   un usuario en **cualquier estado no-erased** (INVITED/ACTIVE/SUSPENDED/DEACTIVATED — D6), responde **`200 OK`** con
   `UserDetailResource` (id, email, status, **roles nuevos**, timestamps) vía `ChangeUserRoles::run($id, $roles)`, que
   **establece el conjunto completo** (semántica *set*, no deltas). El controller vive en
   `api/src/Iam/Identity/Infrastructure/Controller/` (montado por `api_v1_iam_identity`, prefijo `/api/v1`) (FR7, NFR7,
   SI-18).
2. **AC2 · Gateo por permiso (SI-17).** Un `VIEWER`/`EDITOR`/`MANAGER`/`AUDIT_READER` (no ADMIN) que haga el PATCH
   recibe **`403`**; sin sesión → **`401`**. `users` está en `TIER_OPT_OUT` y `users.changeRoles → [ADMIN]` — ningún tier
   de negocio lo alcanza (mirror de `features/backoffice/users/access_control.feature`).
3. **AC3 · Guard ≥1 ADMIN CONDICIONAL → 409, sin efectos (NFR6).** El guard solo se evalúa cuando el cambio **demueve a
   un admin ACTIVE** (el usuario objetivo está `ACTIVE`, sus roles actuales contienen `ADMIN`, y el nuevo set **no**
   contiene `ADMIN`). En ese caso, si es el **último ADMIN activo** → **`409 last-active-administrator-protected`**, el
   set **no** cambia, **0 eventos**, **0 sesiones revocadas**; el guard corre **dentro** de la tx (con `FOR UPDATE`,
   TOCTOU-safe). **Un cambio que NO demueve** (añade un rol, conserva `ADMIN`, o edita a un no-admin) **no dispara el
   guard** aunque no exista otro admin — un `[ADMIN]→[ADMIN,EDITOR]` del único admin es **`200`**, no `409` (contraste
   con U-3, que invoca el guard incondicionalmente).
4. **AC4 · Payload validado en el borde (422).** El `{roles}` se valida por `#[MapRequestPayload]` **antes** del
   dominio: **no vacío** (`#[Assert\Count(min: 1)]`) y **cada valor ∈ vocabulario `Role`**
   (`#[Assert\Choice(callback: roleValues, multiple: true)]`). Un array vacío, ausente, o con un valor fuera de
   `{VIEWER,EDITOR,MANAGER,ADMIN,AUDIT_READER}` → **`422 validation-failed`**, sin efectos. Los **duplicados** en el
   payload no son error: el agregado dedupe (`distinctRoleValues`) → `["ADMIN","ADMIN"]` persiste `["ADMIN"]`.
5. **AC5 · Id malformado / ausente.** `{id}` que no es UUID → **`400 invalid-uuid`** (`Uuid::ensure` dentro de
   `ChangeUserRoles`, antes del lookup); id UUID válido pero inexistente → **`404`** (`UserNotFound`), sin efectos.
6. **AC6 · Un solo evento por el outbox, atómico.** Un cambio exitoso que **modifica** el set deja **exactamente** un
   `UserRolesChanged` (`erpify.iam.identity.roles-changed`) en el event_store, publicado dentro de la **misma**
   transacción que persiste (`TransactionManager::transactional(save + publish)`). El payload del evento **lleva el
   nuevo conjunto de roles** (D9, a diferencia de los eventos vacíos de status). Ningún fallo (409/422/404/400) emite
   evento.
7. **AC7 · Invalidación de sesiones — defensa en profundidad (D3).** Un cambio de roles exitoso **revoca las sesiones
   activas** del usuario vía `RevokeSessionsBestEffort` post-commit (eager, todos los dispositivos; emite
   `erpify.iam.session.all-revoked`). Es **defensa-en-profundidad**: el cambio de roles **ya** dispara la
   des-autenticación nativa de Symfony (`hasUserChanged` compara roles; `SecurityUser` no es `EquatableInterface`) en el
   siguiente request del afectado — pero esa vía es *lazy* y solo la sesión actual, así que el revoke explícito corta
   ya y en todos los dispositivos. La revocación es **best-effort** (traga+loguea; no aborta al caller tras el commit).
8. **AC8 · Set sin cambios = no-op idempotente.** Un PATCH cuyo `{roles}` (como conjunto, ignorando orden/duplicados)
   **iguala** el set actual del usuario responde **`200`** con el detalle, pero **no** persiste, **no** emite
   `UserRolesChanged` y **no** revoca sesiones (evita nukear sesiones por un guardado redundante). *(A diferencia del
   status de U-3, donde el mismo-estado es `409`; aquí el dominio de roles no es unidireccional, un set idéntico es
   legítimo y no cambia nada.)*
9. **AC9 · Puerto identity-shaped (PWA, SI-18).** El cambio va por un caso de uso `ChangeUserRoles` propio + su puerto
   `ChangeUserRolesRepository.changeRoles(id, roles): Promise<User>` + adapter `ApiChangeUserRolesRepository` (PATCH),
   espejo del puerto de status de U-3 (Regla-de-Tres: invite + changeStatus + changeRoles). `ApiUserRepository.update()`
   **sigue** como stub `notSupported` — la identidad **no** es CRUD genérico.
10. **AC10 · Editor de roles en el detalle, gateado, para cualquier estado.** Un `UserRolesControl` montado en
    `src/app/backoffice/users/[id]/page.tsx`, gateado por `<Can permission={Permission.USERS_CHANGE_ROLES}>`, presenta un
    **grupo de checkboxes** de `ALL_ROLES` (reusando el fieldset del form de invitación) **pre-marcado con
    `user.roles`** + botón save, y envía el **conjunto completo**. Es visible/operable en **cualquier estado no-erased**
    (a diferencia del `UserStatusControl`, ACTIVE-only — **no** copies ese early-return). Exige **≥1 rol** seleccionado
    (`z.array(z.enum(Role)).min(1)`).
11. **AC11 · Reflejo + errores persistentes.** Un cambio exitoso → toast de éxito y el detalle refleja los nuevos
    `RolesBadges` (`reload()` de `useResourceItem`). Un fallo → `<MutationError>` **persistente** sobre el control; el
    `409 last-active-administrator-protected` se muestra como error de mutación; el `422` mapea sus `violations` al campo
    `roles` (patrón `InviteUserForm`, que gatea en `UNPROCESSABLE_ENTITY`).
12. **AC12 · e2e conduce el ciclo (NFR9).** Un spec real-API con sesión **ADMIN** (`authenticatedTest`, per-worker) abre
    el detalle de un usuario **no-admin** (semilla — ver *Testing*), le cambia el set de roles y verifica los
    `RolesBadges` resultantes en el detalle — **sin exact-counts** (la DB de dev acumula identidades). El objetivo es
    no-admin para no rozar el guard AC3. **No** es un smoke.
13. **AC13 · Vocabulario byte-idéntico + coste de query fijado.** `users.changeRoles` es idéntico byte-a-byte en
    `#[IsGranted('users.changeRoles')]` (API) y `Permission.USERS_CHANGE_ROLES` (PWA) (SI-20). El escenario Behat del
    éxito fija el presupuesto de query del write envuelto (`+2` BEGIN/COMMIT sobre la línea base) más el coste del revoke
    de sesión — **medido en vivo, no adivinado**.

## Tasks / Subtasks

### A — Dominio: mutador de roles + evento · `api/src/Iam/Identity/Domain/` (AC1, AC4, AC6, AC8)

- [ ] **Mutador** `User::changeRoles(Role ...$roles): void` **nuevo** (`Domain/Entity/User.php`). Reemplaza el set
      completo: `$this->roles = $this->distinctRoleValues(...$roles)` (reusa el helper existente → dedup + orden),
      bumpea `updatedAt` (`SystemClock::now()`), y **`record(UserRolesChanged::...)`** con el nuevo set. **No** aplica
      guard de ciclo de vida (roles ⟂ estado, D6) — cualquier estado no-erased es válido. **No** metas el invariante
      ≥1 ADMIN aquí (vive en el use case, hecho 1). *Nota: el no-op de set-sin-cambios (AC8) lo decide el use case,
      no el agregado — ver Task C.*
- [ ] **Evento** `UserRolesChanged` **nuevo** en `api/src/Iam/Identity/Domain/Event/` (espejo de `UserSuspended`:
      `extends DomainEvent`, `eventName(): 'erpify.iam.identity.roles-changed'`, `aggregateType(): 'Iam.Identity'`,
      `toPrimitives()/fromPrimitives()`). **Payload lleva `roles: list<string>`** (el nuevo set — D9; no vacío como los
      de status). Auto-descubierto por `RegisterDomainEventsPass` + `ReflectionDomainEventMapper` — **sin** editar
      registro/config.

### B — Endpoint `PATCH .../roles` + DTO array-of-Role · `api/src/Iam/Identity/Infrastructure/` (AC1, AC4, AC5)

- [ ] **Request DTO** `ChangeUserRolesRequest.php` **nuevo** en `Iam/Identity/Infrastructure/Http/` (junto a
      `UserResourceMapper`). Campo `public array $roles = []` (`list<string>`) con `#[Assert\Count(min: 1, minMessage:
      'Select at least one role.')]` + `#[Assert\Choice(callback: [self::class, 'roleValues'], multiple: true,
      multipleMessage: 'Select a valid role.')]` + `roleValues()` estático (clon de `InviteUserRequest`, **NO** el
      enum-escalar de `ChangeUserStatusRequest`).
- [ ] **Controller** `UserPatchRolesController.php` **nuevo** en `Iam/Identity/Infrastructure/Controller/` (junto a
      `UserPatchStatusController`). `#[Route('/backoffice/users/{id}/roles', name: self::ROUTE_NAME, methods:
      ['PATCH'])]` (`const string ROUTE_NAME = 'backoffice_user_change_roles'`), `#[IsGranted('users.changeRoles')]`
      (**string literal**). `final readonly`, invokable, deps `ChangeUserRoles`, `UserResourceMapper`,
      `ResourceResponder`. Cuerpo: mapea `$request->roles` (strings) → `list<Role>` con `Role::from(...)` (patrón
      `CreateInvitationController::rolesFrom()`) → `$user = $changeUserRoles->run($id, $roles)` →
      `$responder->respond($mapper->toDetailResource($user))`. El `Uuid::ensure`/`UserNotFound` los hace
      `ChangeUserRoles` — **no** dupliques `UserFinder`.

### C — Application service `ChangeUserRoles` + guard condicional + revoke · `api/src/Iam/Identity/Application/` (AC3, AC6, AC7, AC8)

- [ ] **App service** `ChangeUserRoles.php` **nuevo** (`final readonly class`, espejo de `ChangeUserStatus`). Deps:
      `UserRepository`, `ActiveAdministratorDirectory`, `RevokeSessionsBestEffort`, `EventBus`, `TransactionManager`.
      Método `run(string $userId, Role ...$roles): User` (o `array $roles` — sé consistente con el mapeo del controller).
      Flujo:
  - [ ] `Uuid::ensure($userId)` antes de todo.
  - [ ] Abre `transactionManager->transactional(...)`; **dentro**: `findById ?? UserNotFound`.
  - [ ] **No-op (AC8):** calcula el set resultante deduplicado; si **iguala** el set actual (comparación de conjuntos,
        ignora orden) → devuelve el `User` **sin** mutar, **sin** publicar, **sin** marcar para revoke. (Determina el
        flag de revoke/publish antes de salir de la tx.)
  - [ ] **Guard condicional (AC3, Crux 2):** invoca `keepsAnActiveAdminWithout($userId)` **solo si**
        `$user->isActive()` **y** el set actual contiene `ADMIN` **y** el nuevo set **no** contiene `ADMIN`; si el guard
        devuelve `false` → `throw LastActiveAdministratorProtected::forUser($userId)`. En cualquier otro caso, **no**
        llames al guard.
  - [ ] `$user->changeRoles(...$roles)` → `save` → `publish(...pullDomainEvents())` en la misma tx.
  - [ ] **Post-commit** (fuera de la closure, solo si hubo cambio): `revokeSessions->revoke($userId)` (best-effort,
        defensa-en-profundidad — D3).

### D — Datos RBAC: `users.changeRoles` · `api/src/Iam/Identity/Infrastructure/Security/` (AC2, AC13)

- [ ] **Catálogo** `PermissionCatalog.php` — añadir `'users.changeRoles'` a `PERMISSIONS` (**obligatorio**:
      `PermissionCatalogCoversEveryGatedRouteTest` falla el build si un `#[IsGranted]` no está en el catálogo).
- [ ] **Policy** `StaticAuthorizationPolicy.php` — añadir `'users.changeRoles' => [Role::ADMIN->value]` a
      `EXPLICIT_GRANTS` (data-only, tripwire verde). **Boy-scout:** actualizar el docblock (`~:51-54`) que dice «cuatro
      grants `users.*`» → cinco (y que `read/invite/changeStatus/changeRoles` respaldan endpoints; `erase` sigue por
      delante).
- [ ] **Test voter** — añadir el caso `users.changeRoles` (ADMIN concede / tier de negocio deniega) a
      `PermissionVoterAccessDecisionTest` (espejo del caso `users.changeStatus`).

### E — PWA: puerto identity-shaped + editor de conjunto en el detalle (AC9, AC10, AC11, AC13)

- [ ] `context/shared/http-client/infrastructure/ApiEndpoints.ts` — `+ USERS.CHANGE_ROLES: (id) =>
      `${userPath(id)}/roles`` (espejo de `USERS.CHANGE_STATUS`).
- [ ] `context/shared/access/domain/Permission.ts` — `+ USERS_CHANGE_ROLES: "users.changeRoles"` (byte-idéntico, SI-20).
- [ ] **Actualizar los 2 tests que fijan el vocabulario de permisos** (o el build/asserts fallan):
      `tests/context/shared/access/ApiIdentityRepository.test.ts` (el mock de `/me` hardcodea la lista de strings) y
      `tests/context/shared/access/domain/authorize.test.ts`.
- [ ] `context/backoffice/user/domain/ChangeUserRolesRepository.ts` **nuevo** — puerto `changeRoles(id: string, roles:
      Role[]): Promise<User>`.
- [ ] `context/backoffice/user/infrastructure/ApiChangeUserRolesRepository.ts` **nuevo** — `httpClient.patch(
      USERS.CHANGE_ROLES(id), { roles }, isUserSingleResponse)` → `User.fromPrimitives(response.data)` (reusa
      `isUserSingleResponse` exportado de `ApiUserRepository.ts`).
- [ ] `context/backoffice/user/application/ChangeUserRoles.ts` **nuevo** — use case `run(id, roles)` delega en el puerto.
- [ ] `context/backoffice/user/application/schemas/ChangeUserRolesSchema.ts` **nuevo** — `z.object({ roles:
      z.array(z.enum(Role)).min(1, "Select at least one role.") })` (patrón `InviteUserSchema`).
- [ ] `context/shared/dependency-injection/infrastructure/Container.ts` — +2 binds
      (`BackOfficeChangeUserRolesRepository` → `ApiChangeUserRolesRepository` singleton; `BackOfficeChangeUserRoles` →
      use case) + 3 imports (espejo de los de status, `~L50-52`/`~L215-221`).
- [ ] `app/backoffice/users/_components/UserRolesControl.tsx` **nuevo** — `"use client"`, mirror de `UserStatusControl`
      **pero con el fieldset de checkboxes del `InviteUserForm`** (`ALL_ROLES.map`, pre-marcado desde `user.roles`,
      `react-hook-form` + `ChangeUserRolesSchema`), botón save, `<MutationError testId="user-roles__error">` +
      `toastNotifier.success`. Gateado por `<Can permission={Permission.USERS_CHANGE_ROLES}>`. **Sin** early-return por
      estado (D6 — cualquier estado no-erased). Testids `user-roles`, `user-roles__role-{ROLE}`, `user-roles__save`,
      `user-roles__error` (patrón de U-2/U-3). Props `{ user: User; onChanged: (user: User) => void }`.
- [ ] `app/backoffice/users/[id]/page.tsx` — montar `<UserRolesControl user={user} onChanged={() => void reload()} />`
      junto a `<UserStatusControl>` (`~L167`); `reload()` refleja los nuevos `RolesBadges`. **No** tocar el read-side.

### F — Tests (Behat + unit + functional + e2e) (AC1–AC13)

- [ ] **Behat** `api/features/backoffice/users/roles.feature` **nuevo** — espejo de `status.feature`: éxito que
      **cambia** el set (200 + `data.roles` + **1 evento** `roles-changed` + `all-revoked` + budget); **no-op**
      set-sin-cambios (200, **0 eventos**, **0 all-revoked** — AC8); **403** no-ADMIN + **401** sin sesión; **409
      last-admin** en democión del último admin ACTIVE; **200** en `[ADMIN]→[ADMIN,EDITOR]` del único admin (guard NO
      dispara — AC3); **422** roles vacío / valor fuera de vocabulario; **400 invalid-uuid** (Scenario Outline); **404**.
      Assert-0-en-fallo: vaciar outbox + reset stats **antes** de la request.
- [ ] **Unit** — `ChangeUserRolesTest` (`tests/Unit/Iam/Identity/Application/`, reusa `InMemoryActiveAdministratorDirectory`):
      cambio con revoke; **guard NO invocado** cuando no hay democión (add/same-set/no-admin) — asertar que el doble del
      directory **no** recibe la llamada; guard invocado y 409 en democión del último admin; **no-op** set-igual (sin
      save/publish/revoke); guard-fail NO muta el agregado (order-independence). `ChangeUserRolesRequestTest`
      (`tests/Unit/.../Http/`, valida el array: vacío→inválido, valor ilegal→inválido, dedup ok). Aggregate test
      `User::changeRoles` (set semantics + dedup + evento). Controller `#[CoversClass(UserPatchRolesController::class)]`
      (**nunca** `CoversNothing`).
- [ ] **Functional** — wire-gate del controller en `tests/Functional/Iam/Identity/Infrastructure/Controller/`
      (`UserPatchRolesFunctionalTest`, patrón `UserPatchStatusFunctionalTest`): 200 admin (orden de claves del recurso),
      403 no-admin, y el **409 real** (adapter de producción) en democión del último admin. El adapter del guard se
      reusa → **no** hace falta test de integración nuevo.
- [ ] **e2e** `pwa/tests/e2e/backoffice/users-change-roles-real-api.spec.ts` **nuevo** — `authenticatedTest`, abre el
      detalle de un usuario **no-admin** (semilla), cambia su set de roles, asserta los `RolesBadges` resultantes; sin
      exact-counts. **Semilla:** reusar el fixture no-admin `e2e-suspendable@erpify.test` de `make/pwa.mk` **pero
      añadir el reset de `roles` al `ON CONFLICT DO UPDATE SET`** (hoy solo resetea `status`) para que el spec sea
      repetible, o sembrar un `e2e-role-editable@erpify.test` dedicado. Mantenerlo no-admin (nunca roza el guard AC3).
- [ ] **PWA unit** — `ApiChangeUserRolesRepository` + `ChangeUserRoles` (use case) + `userRolesControl.test.tsx`
      (mockea el container por token; render con checkboxes pre-marcados; save/error/hidden-when-not-permitted;
      **renderiza en cualquier estado**, sin el test ACTIVE-only de U-3).

### Verificaciones (Working principle 4)

- [ ] `make php.stan` en cada `.php` tocado (worker: `PHP_SERVICE=messenger_worker` si segfault 139).
- [ ] `make php.quality` completo (stan + psalm-taint + phpmd + cs-fixer + rector + **deptrac** + **bounded-context** +
      **error-contract**) **verde**. `error-contract` debe quedar verde **sin marcador nuevo** (`LastActiveAdministratorProtected`
      ya es `Conflict`); `deptrac`/`bounded-context` verdes **sin allowlist nuevo** (D2 single-context, no toca
      `Organization`). Si alguno pide cambio, algo se desvió.
- [ ] `make php.behat` (features nuevas + regresión). Re-sembrar el ADMIN e2e **después** (Behat resetea la DB).
- [ ] `make pwa.quality` + `make pwa.test.unit` + e2e (worktree: puerto efímero + overrides Playwright; EACCES →
      limpiar `.next-e2e`; contenedor `pwa` debe estar `Up`).
- [ ] **Barrido final:** eliminar del diff (código `api/src`, `pwa/src`, tests) los comentarios con IDs de story/NFR/AC
      y los change-relative — la trazabilidad vive aquí, en el PR y en el commit (CLAUDE.md).

## Dev Notes

### Crux 1 — el controller mapea `{roles: string[]}` → `Role[]`, el DTO valida el array, el agregado dedupe

El payload es un **conjunto** de strings, no un enum escalar. Tres capas complementarias: el DTO rechaza en el borde un
array vacío o con un valor fuera del vocabulario (`422`, AC4) reusando `InviteUserRequest` (**no** el `Assert\Choice`
escalar del status); el controller mapea strings→`Role` con `Role::from(...)`; el agregado dedupe con
`distinctRoleValues` (un `["ADMIN","ADMIN"]` → `["ADMIN"]`). **No** metas la validación de vocabulario en el dominio ni
el dedup en el controller — cada capa tiene su responsabilidad.

### Crux 2 — el guard ≥1 ADMIN se invoca CONDICIONALMENTE (la divergencia central con U-3)

U-3 invoca `keepsAnActiveAdminWithout($userId)` **siempre**, porque suspend/deactivate **siempre** saca al usuario del
pool de admins activos. Un cambio de roles **solo** amenaza el invariante cuando **demueve a un admin ACTIVE** (le quita
`ADMIN`). Por eso U-4 evalúa el guard **solo si** las tres condiciones se cumplen: `$user->isActive()` ∧ `ADMIN ∈
roles_actuales` ∧ `ADMIN ∉ roles_nuevos`. Si se invocara incondicionalmente, un `[ADMIN]→[ADMIN,EDITOR]` del único
admin daría un `409` falso (no hay *otro* admin, pero el cambio no quita a nadie). El guard reusado (`FOR UPDATE`
TOCTOU-safe de U-3) sigue serializando demociones concurrentes correctamente cuando **sí** se invoca. **No** crees un
guard nuevo ni cambies su firma — solo la política de invocación.

### Crux 3 — el revoke de sesión es defensa-en-profundidad, no la única barrera (contraste con U-3)

A diferencia del status flip de U-3, **cambiar roles ya des-autentica de forma nativa**: `SecurityUser` no es
`EquatableInterface`, así que `AbstractToken::hasUserChanged()` compara roles y, en el siguiente request del afectado,
`refreshUser` + `TokenDeauthenticatedEvent` → `RevokeSessionOnTokenDeauthenticated` revoca su sesión actual. Esa vía es
*lazy* (solo al próximo request) y solo la sesión **actual**. Por eso U-4 **igual** cablea `RevokeSessionsBestEffort`
post-commit (corte **eager**, **todos** los dispositivos) — mismo racional que `CompletePasswordReset` (su docblock lo
dice), **no** el de U-3. Documenta esta diferencia en el docstring de `ChangeUserRoles` y en el unit test (el revoke se
prueba vía el evento `erpify.iam.session.all-revoked`, como en `status.feature`).

### Crux 4 — set-sin-cambios es no-op (diverge de U-3, donde mismo-estado es 409)

El dominio de roles **no** es unidireccional. Re-enviar el mismo set (ignorando orden/duplicados) es legítimo → `200`,
pero **no** debe persistir, publicar ni **revocar sesiones** (nukear las sesiones por un guardado redundante sería un
efecto colateral sorpresa). El use case compara el set resultante con el actual **antes** de mutar y hace short-circuit.
**No** copies el escenario `409 invalid-identity-transition` de `status.feature` — aquí el mismo-set es `200` no-op.

### Decisión D2 — fuente de verdad = `User.roles` solo (single-context), NO `Membership.roles` (Sergio, cierre del candidato)

`Organization/Membership.roles` es «autoritativo por diseño» pero hoy **se escribe solo en `grant()` (onboarding) y nadie
lo lee operativamente** (guard, read-model y auth leen `User.roles`). U-4 escribe **solo `User.roles`** — espeja la D2
de U-3, respeta SI-15 (fuente operativa = `User.roles` hoy), y **evita un seam cross-context `Iam/Identity →
Organization`** (allowlist + deptrac + un `GrantMembership`-style reassign). *Coste nombrado:* `Membership.roles` queda
divergente (latente); cuando tenancy re-apunte la fuente autoritativa a `Membership`, esa re-apunta reconcilia — es la
**misma** re-apunta que ya deberá hacer el read-model, no deuda nueva. *Alternativa descartada:* escribir ambas fuentes
compraría hoy una consistencia que nada lee, al precio de la orquestación cross-context. **Fuera de alcance:**
reconciliar `Membership.roles`.

### Decisión D6 — edición permitida en cualquier estado no-erased (Sergio)

Los roles son **ortogonales** al ciclo de vida de identidad: no hay razón de dominio para bloquear la edición por estado
(a diferencia de suspend/deactivate, que exigen `ACTIVE`). El mutador `changeRoles` no lleva `guardTransitionTo`, y el
`UserRolesControl` **no** copia el early-return ACTIVE-only del `UserStatusControl`. En estados terminales
(SUSPENDED/DEACTIVATED) los roles son inertes (el usuario no puede loguear) pero editarlos es inofensivo — mínimo código,
sin matriz de estado.

### Decisiones ya tomadas — no re-abrir

| # | Decisión | Argumento |
|---|---|---|
| D1 | Puerto identity-shaped `ChangeUserRoles` + adapter dedicado; `update()` sigue no-soportado | SI-18; Regla-de-Tres cumplida (invite + changeStatus + changeRoles) |
| D2 | Fuente = `User.roles` solo (single-context), **no** `Membership.roles` | Sergio; SI-15 (fuente operativa hoy); evita seam cross-context; tenancy re-apunta luego |
| D3 | Revoke de sesión post-commit como **defensa-en-profundidad** (no única barrera) | El cambio de roles ya des-autentica nativamente; el revoke corta eager + todos los dispositivos. Mirror `CompletePasswordReset` |
| D4 | Guard ≥1 ADMIN reusado **tal cual**, invocado **condicionalmente** (solo democión de admin ACTIVE) | Un cambio que no quita `ADMIN` no amenaza el invariante; invocación incondicional daría 409 falsos |
| D5 | Permiso `users.changeRoles` **nuevo** (catálogo + grant `[ADMIN]` + enum PWA) | SI-17 (cada capacidad su permiso); byte-idéntico API↔PWA (SI-20) |
| D6 | Edición en **cualquier estado no-erased** | Sergio; roles ⟂ ciclo de vida; sin matriz de estado |
| D7 | DTO array-of-string + `Assert\Count(min:1)` + `Assert\Choice(multiple:true, callback: roleValues)` | Template `InviteUserRequest`, **no** el enum-escalar del status |
| D8 | Semántica *set* (conjunto completo); set-sin-cambios = **no-op** (sin evento/revoke); dedup en el agregado | El form de checkboxes envía el set; el mismo-set no cambia nada → no dispares efectos |
| D9 | `UserRolesChanged` **lleva el nuevo set** en el payload (no vacío como los de status) | Es el cambio sustantivo; roles no son PII; habilita audit/consumidor futuro (emit-always; wire consumer luego) |
| D10 | Gateo **de controller** (`#[IsGranted]`), no en `ChangeUserRoles` | Precedente U-2/U-3; authz en el use case acoplaría cualquier caller sin sesión |

### Ficheros a tocar (verificado)

| Fichero | Acción |
|---|---|
| `api/src/Iam/Identity/Domain/Entity/User.php` | +`changeRoles(Role ...)` + `record(UserRolesChanged)` |
| `api/src/Iam/Identity/Domain/Event/UserRolesChanged.php` | **NUEVO** — evento (payload lleva el set) |
| `api/src/Iam/Identity/Application/ChangeUserRoles.php` | **NUEVO** — app service (guard condicional + revoke + no-op) |
| `api/src/Iam/Identity/Infrastructure/Http/ChangeUserRolesRequest.php` | **NUEVO** — DTO array-of-Role (template `InviteUserRequest`) |
| `api/src/Iam/Identity/Infrastructure/Controller/UserPatchRolesController.php` | **NUEVO** — `PATCH .../roles`, `#[IsGranted('users.changeRoles')]` |
| `api/src/Iam/Identity/Infrastructure/Security/PermissionCatalog.php` | +`'users.changeRoles'` (obligatorio) |
| `api/src/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicy.php` | +grant `[ADMIN]` + docblock (cuatro→cinco) |
| `api/features/backoffice/users/roles.feature` | **NUEVO** — 200 set/no-op/403/401/409/422/400/404 + eventos + budget |
| `api/tests/Unit/Iam/Identity/Application/ChangeUserRolesTest.php` | **NUEVO** |
| `api/tests/Unit/Iam/Identity/Infrastructure/Http/ChangeUserRolesRequestTest.php` | **NUEVO** |
| `api/tests/Unit/Iam/Identity/Domain/Entity/…UserTest` (o donde viva) | +`changeRoles` (set/dedup/evento) |
| `api/tests/Functional/Iam/Identity/Infrastructure/Controller/UserPatchRolesFunctionalTest.php` | **NUEVO** — wire-gate 200/403/409 |
| `api/tests/Functional/Iam/Identity/Infrastructure/Security/PermissionVoterAccessDecisionTest.php` | +caso `users.changeRoles` |
| `pwa/src/context/shared/http-client/infrastructure/ApiEndpoints.ts` | +`USERS.CHANGE_ROLES` |
| `pwa/src/context/shared/access/domain/Permission.ts` | +`USERS_CHANGE_ROLES` |
| `pwa/tests/context/shared/access/ApiIdentityRepository.test.ts` | +`users.changeRoles` en el mock de `/me` |
| `pwa/tests/context/shared/access/domain/authorize.test.ts` | +cobertura del nuevo permiso |
| `pwa/src/context/backoffice/user/domain/ChangeUserRolesRepository.ts` | **NUEVO** — puerto |
| `pwa/src/context/backoffice/user/infrastructure/ApiChangeUserRolesRepository.ts` | **NUEVO** — PATCH adapter |
| `pwa/src/context/backoffice/user/application/ChangeUserRoles.ts` | **NUEVO** — use case |
| `pwa/src/context/backoffice/user/application/schemas/ChangeUserRolesSchema.ts` | **NUEVO** — zod (`z.array(z.enum(Role)).min(1)`) |
| `pwa/src/context/shared/dependency-injection/infrastructure/Container.ts` | +2 binds + 3 imports |
| `pwa/src/app/backoffice/users/_components/UserRolesControl.tsx` | **NUEVO** — editor checkbox-set gateado |
| `pwa/src/app/backoffice/users/[id]/page.tsx` | +montar `<UserRolesControl>` + `reload()` |
| `pwa/tests/…` (adapter, use case, control) + `pwa/tests/e2e/backoffice/users-change-roles-real-api.spec.ts` | tests |
| `make/pwa.mk` | e2e seed: añadir reset de `roles` al `ON CONFLICT` del fixture no-admin (o fixture dedicado) |

### Testing (patrones del repo)

- **Semilla no-admin para el e2e de éxito.** Reusar `e2e-suspendable@erpify.test` (VIEWER, login-less) **añadiendo
  `roles = json_build_array('VIEWER')` al `ON CONFLICT DO UPDATE SET`** de `make/pwa.mk` (hoy solo resetea `status`) →
  spec repetible; o sembrar `e2e-role-editable@erpify.test`. **No-admin** para no rozar el guard AC3.
- **Eventos:** `EventStoreContext`/`OutboxContext` — `erpify.iam.identity.roles-changed`. Assert-0 en fallo/no-op
  (vaciar outbox + reset stats **antes**).
- **Sesión ADMIN en Behat:** `I am logged in as an administrator` (`SecurityContext`, fixture `user_admin`).
- **Cobertura de controlador fino:** `#[CoversClass(UserPatchRolesController::class)]`, **nunca** `#[CoversNothing]`
  (los wire-gates funcionales alimentan cobertura; Behat no).
- **PWA:** mockea `HttpClient` en adapter/use-case; el control se testea con checkboxes pre-marcados desde `user.roles`,
  cambio feliz (refleja `RolesBadges`) + un `409`/`422` que renderiza `<MutationError>`. e2e real-API, sesión por
  worker, sin exact-counts; scope a testids de entidad.

### Gotchas heredados (verificados)

- **`identity_user.roles` es `json`, NO `jsonb`** — el guard reusado castea `roles::jsonb @> CAST(:adminRole AS jsonb)`;
  no lo toques (se reusa el adapter de U-3).
- **`users` es PLURAL** (SI-20) — `users.changeRoles`, nunca `user.changeRoles` ni `users.change_roles`.
- **`PermissionCatalogCoversEveryGatedRouteTest`** falla el build si `users.changeRoles` no está en el catálogo — no es
  opcional.
- **Presupuesto de query +2 por write envuelto** (BEGIN/COMMIT); el revoke añade sus propias queries — mídelas en vivo
  (`I dump the number of executed queries`) y fija el número, no lo adivines. El **no-op (AC8)** tiene su propio
  presupuesto (sin write/revoke) — fíjalo aparte.
- **`make php.behat` resetea la DB** → re-siembra el ADMIN e2e **después**.
- **Inline SQL step de Behat: sin comillas dobles** embebidas; JSONB via `json_build_array('VIEWER')` o PyString.
- **`php.stan` puede segfaultear** en el worker web (139) → `PHP_SERVICE=messenger_worker`.
- **Rector:** `/** @phpstan-var T */` (no `@var` sin nombre) sobre `return` en tests; importa el FQCN en closures de
  `array_map` (>120 chars). `fetchFirstColumn` da `list<mixed>` → guarda con `\is_string()`, no cast.
- **e2e en worktree:** `PLAYWRIGHT_BASE_URL`/`_API_BASE_URL` al puerto efímero (`docker compose port php 443`); EACCES →
  `rm -rf pwa/.next-e2e && rm -f pwa/next-env.d.ts`; contenedor `pwa` debe estar `Up` (no `Created`).

### Fuera de alcance (frontera explícita)

- **Reconciliar `Organization/Membership.roles`** — D2: U-4 escribe solo `User.roles`; la re-apunta a `Membership` es
  tenancy (SI-15).
- **Filtro por rol** en la lista — diferido desde U-0 (JSONB sin containment en el grammar compartido).
- **Redacción field-level de roles** (grant/revoke por delta) — SI-17: `read` es una unidad; U-4 es semántica *set*.
- **`USER_ADMIN`** (administrar identidades sin ser ADMIN completo) — YAGNI, disparador documentado.
- **Reactor de auditoría/realtime sobre `UserRolesChanged`** — se emite siempre (R1); consumidor/Mercure solo con
  consumidor real (R2). No se añade subscriber.
- **Índice GIN sobre `roles`** — el guard reusado corre seq scan sobre docenas de filas single-org; **medir con
  `EXPLAIN ANALYZE` antes** de crear índice.
- **`erase` / `update` genérico** — U-5 y SI-18 respectivamente.

### Project Structure Notes

- Controller + DTO + app service + evento + mutador en `Iam/Identity/{Infrastructure,Application,Domain}/` (mismas layers
  deptrac que U-3) → limpio, **sin allowlist nuevo** (D2 single-context, no toca `Organization`). El `#[Route]` resuelve
  `/api/v1/backoffice/users/{id}/roles` vía `api_v1_iam_identity`.
- PWA: puerto + adapter + use case + schema en `context/backoffice/user/{domain,infrastructure,application}`; el control
  en `app/backoffice/users/_components/`, montado en `[id]/page.tsx`. **Sin migraciones** (columna `roles` existe; guard
  reusado; sin índice — medir primero).

### References

- [Source: `_bmad-output/planning-artifacts/epics-users-admin.md#Story 1.5 (U-4)`] — AC (invariantes); FR7.
- [Source: `_bmad-output/planning-artifacts/arch-addendum-users-admin.md`] — SI-15 (dualidad de fuentes de roles),
  SI-16/17/18/20; fila U-4 («nuevo `ChangeUserRoles`, semántica set, guard evalúa el conjunto resultante»); DAG.
- [Source: `_bmad-output/implementation-artifacts/u-3-cambio-de-estado-suspend-deactivate.md`] — precedente completo:
  app service, guard endurecido (TOCTOU/`FOR UPDATE`), puerto identity-shaped PWA, testing, gotchas.
- [Source: `api/src/Iam/Identity/Application/ChangeUserStatus.php`] — forma del app service (Uuid::ensure → tx →
  findById → guard-en-tx → save+publish → post-commit revoke).
- [Source: `api/src/Iam/Identity/Domain/Entity/User.php`] — `roles` (`json`), `distinctRoleValues`, `roles()`, base
  `AggregateRoot`/`Timestamped`; docblock «invariante cross-agregado vive en el use case».
- [Source: `api/src/Iam/Identity/Domain/Repository/ActiveAdministratorDirectory.php` +
  `Infrastructure/Persistence/Doctrine/DoctrineActiveAdministratorDirectory.php`] — guard reusado tal cual.
- [Source: `api/src/Iam/Identity/Infrastructure/Security/{SecurityUser,UserProvider}.php` +
  `api/src/Iam/Session/Infrastructure/Security/RevokeSessionOnTokenDeauthenticated.php`] — des-autenticación nativa al
  cambiar roles (D3).
- [Source: `api/src/Iam/Identity/Application/RevokeSessionsBestEffort.php` + `CompletePasswordReset.php`] — revoke
  best-effort como defensa-en-profundidad.
- [Source: `api/src/Iam/Invitation/Infrastructure/Http/InviteUserRequest.php` + `CreateInvitationController.php`] —
  DTO array-of-Role + mapeo string→enum (D7).
- [Source: `api/src/Iam/Identity/Infrastructure/Security/{PermissionCatalog,StaticAuthorizationPolicy}.php`] — dónde
  añadir `users.changeRoles` (D5).
- [Source: `api/src/Organization/Membership/Domain/Entity/Membership.php` + `Application/{GrantMembership,FindUserOrganizationId}.php`]
  — `Membership.roles` dormante para mutación (D2).
- [Source: `pwa/src/app/backoffice/users/_components/{UserStatusControl,InviteUserForm,RolesBadges}.tsx` +
  `context/backoffice/user/{domain/ChangeUserStatusRepository.ts,infrastructure/ApiChangeUserStatusRepository.ts,application/ChangeUserStatus.ts}`
  + `context/shared/access/domain/{Role,Permission}.ts` + `context/backoffice/user/application/schemas/InviteUserSchema.ts`]
  — precedente PWA (control + editor checkbox-set + puerto + vocabulario).
- [Source: `docs/adr/rbac-authorization-model.md`, `docs/adr/identity-invitation-lifecycle.md`] — plano RBAC + ciclo de
  identidad/sesiones.

## Dev Agent Record

### Agent Model Used

Opus 4.8 (1M context).

### Debug Log References

### Completion Notes List

### File List
