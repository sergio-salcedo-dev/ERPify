---
baseline_commit: 582274b6d82e5597f376908ee7a97ee300ee41d0
---

# Story 1.4 (U-3): Cambio de estado (suspend / deactivate)

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

Como **ADMIN**,
quiero suspender o desactivar a un usuario (p.ej. un empleado despedido) desde la consola,
para bloquear su acceso conservando su historia, sin dejar la organización sin administrador.

## Contexto (leer antes de tocar código)

U-3 de la épica `users-admin` (orden safe-first `U-0 → U-1 → (U-2 · U-3) → U-4 · [U-5a → U-5b]`). U-0 (read-side),
U-1 (`/me` deriva permisos + `<Can>`) y U-2 (invitar) están **done/merged** en `main` (PRs #501/#502/#503/#504). U-3 es
la **segunda superficie de acción** sobre el ciclo de vida ya construido en la Épica II; U-2 y U-3 son un grupo paralelo
del DAG — **U-3 no depende de U-2**, solo comparten churn (detalle/`<Can>`), ya resuelto porque la base incluye U-2.

**Seis hechos verificados en el código — no re-derives:**

1. **El caso de uso ya existe: `ChangeUserStatus`** (`api/src/Iam/Identity/Application/ChangeUserStatus.php`). `final
   readonly class` con **dos métodos-intención** — `suspend(string $userId): User` y `deactivate(string $userId): User`
   (**no** hay `ChangeUserStatusCommand` ni un `change($id, $status)` único). Deps: `UserRepository`,
   `ActiveAdministratorDirectory`, `EventBus`, `TransactionManager` (el seam framework-free
   `Erpify\Shared\Persistence\Application\TransactionManager`, **no** `EntityManagerInterface` — difiere de BankAccount).
   Flujo: `Uuid::ensure` → `findById ?? UserNotFound` (404) → **guard `keepsAnActiveAdminWithout($userId)` ANTES de mutar**
   (409 si rompe) → `$user->suspend()/deactivate()` (el agregado graba el evento) → `transactional(save + publish)`.
2. **`ChangeUserStatus` NUNCA ha tenido entry-point (ni HTTP ni CLI).** Se construyó en la Épica II y solo vive en tests.
   **Corolario duro:** su puerto `ActiveAdministratorDirectory` (`api/src/Iam/Identity/Domain/Repository/
   ActiveAdministratorDirectory.php`, `keepsAnActiveAdminWithout(string): bool`) **no tiene adapter de producción** — el
   único implementor es el doble `tests/Unit/Iam/Identity/Application/InMemoryActiveAdministratorDirectory.php`, y no hay
   binding en `config/`. Sin adapter, el endpoint **no autowirea**. **U-3 estrena el primer consumidor runtime y debe
   construir + bindar ese adapter** (ver Task B y la Decisión D2).
3. **La transición es unidireccional DESDE `ACTIVE`.** El machine vive en el agregado (`api/src/Iam/Identity/Domain/
   Entity/User.php`): `suspend()` y `deactivate()` llaman `guardTransitionTo(target, requiredFrom: ACTIVE)`; si el estado
   actual ≠ `ACTIVE` lanzan `InvalidIdentityTransition` (`extends DomainException implements Conflict` → **409**, `type:
   invalid-identity-transition`). **No hay reinstate** (`SUSPENDED→ACTIVE`) ni `SUSPENDED→DEACTIVATED`: ambos requieren
   `ACTIVE`. **A diferencia de BankAccount, un PATCH de mismo-estado NO es no-op idempotente — es 409** (un `SUSPENDED`
   re-suspendido rompe el guard).
4. **Vocabulario y gateo ya alineados (U-0/U-1) — U-3 los CONSUME, no los toca.** `users.changeStatus → [Role::ADMIN]`
   en `EXPLICIT_GRANTS` (`StaticAuthorizationPolicy.php:63`) + `users` en `TIER_OPT_OUT` (`:72`); `#[IsGranted('users.
   read')]` es el patrón de string literal de los read-controllers. En PWA: `Permission.USERS_CHANGE_STATUS =
   "users.changeStatus"` (byte-idéntico, `Permission.ts`), `UserStatus{INVITED,ACTIVE,SUSPENDED,DEACTIVATED}`
   (`context/shared/access/domain/UserStatus.ts`) y `UserStatusBadge` ya existen.
5. **Contrato de error cerrado sin marcador nuevo.** `LastActiveAdministratorProtected` (`type:
   last-active-administrator-protected`) e `InvalidIdentityTransition` (`type: invalid-identity-transition`) **ya**
   implementan `Conflict` → **409**. `make php.lint.error-contract` queda **verde sin cambios** (contraste con U-2, que
   tuvo que añadir el marcador a `UserAlreadyMember`).
6. **La invalidación de sesiones NO está cableada en `ChangeUserStatus`.** Solo `CompletePasswordReset` usa
   `RevokeSessionsBestEffort` (`api/src/Iam/Identity/Application/RevokeSessionsBestEffort.php`). Un flip puro de estado
   `ACTIVE→SUSPENDED` **no** cambia password ni roles → el listener `RevokeSessionOnTokenDeauthenticated` **no** dispara,
   y `UserChecker::checkPostAuth` solo muralla en el login, no por-request. El AC «invalida sesiones» (epic + J5) **exige
   cablearlo** (ver Decisión D3).

**El precedente exacto a espejar (verificado, ambos lados):**

- **API — `BankAccount PATCH .../status`:** controller `BankAccountPatchStatusController` (`#[Route('/bank-accounts/
  {id}/status', methods: ['PATCH'])]` + `#[IsGranted(...)]` + `#[MapRequestPayload]`), command `ChangeBankAccountStatusCommand`
  (enum backed tipado, sin `#[Assert]`), app service `BankAccountStatusChanger`, `BankAccountResourceMapper` → resource,
  feature `features/backoffice/bank_account/status.feature`. **Divergencia:** BankAccount permite cualquier transición
  (no enforce matriz) — U-3 sí (hecho 3).
- **PWA — `BankAccountStatusControl`:** `src/app/backoffice/banks/[id]/accounts/_components/BankAccountStatusControl.tsx`
  + puerto `BankAccountRepository.changeStatus(id, status): Promise<BankAccount>` + `ChangeBankAccountStatus` (use case) +
  `ApiBankAccountRepository.changeStatus` (PATCH, revalida con `isBankAccountSingleResponse`) + `BANK_ACCOUNT_STATUSES`
  const + bind DI. Espejo directo — U-2 ya dejó el patrón «puerto identity-shaped» (`InviteUser`) para reusar.

> **Entrega: UN PR.** Backend (endpoint + adapter del guard + revoke de sesión) + PWA (puerto + control gateado en el
> detalle) + tests (Behat 403/401/409×2/422/eventos + unit + e2e del ciclo) son una unidad demostrable — el e2e conduce
> `suspend/deactivate → reflejo` sobre un usuario `ACTIVE`.

## Acceptance Criteria

1. **AC1 · Endpoint autenticado + gateado.** `PATCH /api/v1/backoffice/users/{id}/status` con
   `#[IsGranted('users.changeStatus')]` (string literal) y payload `{status}`, ejecutado por un **ADMIN** sobre un usuario
   **`ACTIVE`**, responde **`200 OK`** con `UserDetailResource` (id, email, **status nuevo**, roles, timestamps) vía
   `ChangeUserStatus::suspend`/`deactivate`. El controller vive en `api/src/Iam/Identity/Infrastructure/Controller/`
   (montado por el resource `api_v1_iam_identity`, prefijo `/api/v1`) (FR6, NFR7, SI-18).
2. **AC2 · Gateo por permiso (SI-17).** Un `VIEWER`/`EDITOR`/`MANAGER`/`AUDIT_READER` (no ADMIN) que haga el PATCH recibe
   **`403`**; sin sesión → **`401`**. `users` está en `TIER_OPT_OUT` y `users.changeStatus → [ADMIN]` — ningún tier de
   negocio lo alcanza (mirror de `features/backoffice/users/access_control.feature`).
3. **AC3 · Guard ≥1 ADMIN activo → 409, sin efectos (NFR6).** Suspender/desactivar al **último ADMIN activo** responde
   **`409 last-active-administrator-protected`**; el estado **no** cambia, **0 eventos** en el outbox y **0 sesiones
   revocadas**. El guard corre **antes** de mutar el agregado. Requiere el adapter de producción de
   `ActiveAdministratorDirectory` (Task B).
4. **AC4 · Target restringido a las transiciones legales.** El `{status}` se acota a **`SUSPENDED|DEACTIVATED`** en el
   borde (DTO enum-tipado + `#[Assert\Choice([SUSPENDED, DEACTIVATED])]`): un valor fuera del enum, ausente, `INVITED` o
   `ACTIVE` → **`422 validation-failed`** por `#[MapRequestPayload]`, **antes** de tocar el dominio, sin efectos.
5. **AC5 · Transición ilegal sobre un no-`ACTIVE` → 409 (no idempotente).** Un target legal sobre un usuario cuyo estado
   actual **no es `ACTIVE`** (p.ej. re-suspender un `SUSPENDED`) → **`409 invalid-identity-transition`**, sin efectos
   (contraste explícito con el no-op idempotente de BankAccount). `{id}` malformado → **`400 invalid-uuid`** (`Uuid::ensure`
   dentro de `ChangeUserStatus`, antes del lookup); id ausente → **`404`** (`UserNotFound`).
6. **AC6 · Un solo evento por el outbox, atómico.** Un cambio exitoso deja en el outbox **exactamente**
   `erpify.iam.identity.suspended` **o** `erpify.iam.identity.deactivated` (payload **vacío** — solo `aggregateId`),
   publicado dentro de la **misma** transacción que persiste (`TransactionManager::transactional(save + publish)`). Ningún
   fallo (409/422) emite evento.
7. **AC7 · Invalidación de sesiones (J5) — ver D3.** Un suspend/deactivate exitoso **revoca las sesiones activas** del
   usuario (`RevokeSessionsBestEffort` post-commit, mirror `CompletePasswordReset`), de modo que el **gate fail-closed de
   II-7** bloquea sus requests siguientes → **muro post-identidad** (`SUSPENDED` = «acceso suspendido»; `DEACTIVATED` =
   genérico opaco, `EXPERIENCE.md` J4). La revocación es **best-effort** (traga+loguea; no aborta al caller tras el commit).
8. **AC8 · Atribución conservada (deactivate ≠ erase).** Tras desactivar un usuario, su `actor_id` en `audit_log`
   permanece **intacto** — `deactivate` conserva la historia (el erase GDPR de U-5 es el que des-identifica). El cambio de
   estado lo audita el CDC `onFlush` existente (diff de `status`) — **sin subscriber dedicado**.
9. **AC9 · Puerto identity-shaped (PWA, SI-18).** El cambio va por un caso de uso `ChangeUserStatus` propio + su puerto
   `ChangeUserStatusRepository.changeStatus(id, status): Promise<User>` + adapter `ApiChangeUserStatusRepository` (PATCH),
   espejo del puerto de invite de U-2. `ApiUserRepository.update()` **sigue** como stub `notSupported` — la identidad **no**
   es CRUD genérico.
10. **AC10 · Acciones en el detalle, gateadas.** Un `UserStatusControl` montado en `src/app/backoffice/users/[id]/page.tsx`,
    gateado por `<Can permission={Permission.USERS_CHANGE_STATUS}>`, ofrece **suspend/deactivate** (mirror
    `BankAccountStatusControl`; **no** un form de edición libre de email/roles). Las acciones solo aplican cuando
    `user.status === ACTIVE` (unidireccional, **sin reinstate** — UX-DR4); para un usuario no-`ACTIVE`, el control no ofrece
    transición (FR6, UX-DR2, UX-DR4).
11. **AC11 · Reflejo de estado + errores persistentes.** Un cambio exitoso → toast de éxito y el detalle refleja el nuevo
    `UserStatusBadge` (`reload()` de `useResourceItem`, o estado local). Un fallo → `<MutationError>` **persistente** sobre
    el control; los `409` (last-admin / invalid-transition) se muestran como error de mutación (no field-mapping — no hay
    `violations` salvo el `422` de target inválido) (UX-DR4, UX-DR5).
12. **AC12 · e2e conduce el ciclo (NFR9).** Un spec real-API con sesión **ADMIN** (`authenticatedTest`, per-worker) abre
    el detalle de un usuario **`ACTIVE` no-admin** (semilla dedicada — ver *Testing*, no el admin, para no tropezar AC3),
    lo suspende/desactiva y verifica el `UserStatusBadge` resultante en el detalle — **sin exact-counts** (la DB de dev
    acumula identidades). **No** es un smoke.
13. **AC13 · Vocabulario byte-idéntico + coste de query fijado.** `users.changeStatus` es idéntico byte-a-byte en
    `#[IsGranted('users.changeStatus')]` (API) y `Permission.USERS_CHANGE_STATUS` (PWA) — ya alineado en U-1; U-3 lo
    **consume**. El escenario Behat del éxito fija el presupuesto de query del write envuelto (`+2` BEGIN/COMMIT sobre la
    línea base; ver *Gotchas*), más el coste del guard y del revoke de sesión.

## Tasks / Subtasks

### A — Endpoint `PATCH .../status` · `api/src/Iam/Identity/Infrastructure/` (AC1, AC4, AC5, AC6)

- [x] **Request DTO** `ChangeUserStatusRequest.php` **nuevo** en `Iam/Identity/Infrastructure/Http/` (junto a
      `UserResourceMapper`). Único campo `public IdentityStatus $status` (enum backed → `#[MapRequestPayload]` rechaza
      valor fuera de enum con `422`) **+** `#[Assert\Choice(choices: [IdentityStatus::SUSPENDED, IdentityStatus::DEACTIVATED])]`
      para acotar el target a las transiciones legales (`INVITED`/`ACTIVE` → `422`). Espejo de
      `ChangeBankAccountStatusCommand` + el acote de target.
- [x] **Controller** `UserPatchStatusController.php` **nuevo** en `Iam/Identity/Infrastructure/Controller/` (junto a
      `UserGetController`). `#[Route('/backoffice/users/{id}/status', name: self::ROUTE_NAME, methods: ['PATCH'])]`
      (`const string ROUTE_NAME = 'backoffice_user_change_status'`), `#[IsGranted('users.changeStatus')]` (**string
      literal**, como los read-controllers). `final readonly`, invokable, deps `ChangeUserStatus`, `UserResourceMapper`,
      `ResourceResponder`. Cuerpo:
      `$user = match ($request->status) { SUSPENDED => $changeUserStatus->suspend($id), DEACTIVATED => $changeUserStatus->deactivate($id) };`
      (exhaustivo sobre el set acotado por `Assert\Choice`) → `$responder->respond($mapper->toDetailResource($user))`. El
      `Uuid::ensure`/`UserNotFound` los hace `ChangeUserStatus` — **no** dupliques `UserFinder`.
- [x] **Docblock stale.** Actualiza el comentario de `EXPLICIT_GRANTS` en `StaticAuthorizationPolicy.php` (`:50-54`) que
      afirma «solo `users.read` tiene endpoint hoy» — ahora `changeStatus` también.

### B — Adapter de producción de `ActiveAdministratorDirectory` (AC3) — **bloqueante duro**

- [x] **Adapter Doctrine** que implemente `keepsAnActiveAdminWithout(string $userId): bool` (hoy inexistente en `src/`).
      **Decisión D2:** cuenta admins **desde `Iam/Identity/User.roles`** (fuente operativa hoy, SI-15 — **single-context,
      sin JOIN a `Organization/Membership`**): `¿existe algún `User` con `status = ACTIVE` y `ADMIN` ∈ `roles`, con `id !=
      :excluded`?`. Ubicación: `Iam/Identity/Infrastructure/Persistence/` (o donde vivan los repos Doctrine de Identity).
      Query parametrizada como **`SELECT EXISTS(SELECT 1 FROM identity_user WHERE status = 'ACTIVE' AND roles @> :adminJson AND id <> :excluded)`**
      — **`EXISTS`, no `COUNT(*)`** (solo interesa si **queda** otro admin activo → Postgres corta en la 1ª fila y la
      intención queda explícita; containment JSONB → *Gotchas*), sin `SELECT *`.
- [x] **Binding** del puerto → adapter (autowiring por `_defaults`/`bind:` o entry explícito en `services.yaml` si el
      containment JSONB necesita un `dql:`/función). Verifica que `ChangeUserStatus` autowirea tras el bind (`make sf
      c='debug:container ...'` o el functional wire-gate).
- [x] **Test de integración** del adapter (Postgres real): ≥1 otro admin activo → `true`; único admin activo → `false`;
      admins `SUSPENDED`/`DEACTIVATED` no cuentan; `id` excluido no se auto-cuenta.

### C — Invalidación de sesiones (AC7) — **Decisión D3: revoke síncrono post-commit**

- [x] **Cablear `RevokeSessionsBestEffort`** en `ChangeUserStatus`: añadir la dep e invocar `->revoke($userId)`
      **después** del `transactional(...)` (post-commit best-effort, mirror `CompletePasswordReset` — traga+loguea, no
      aborta al caller). Aplica a `suspend` **y** `deactivate`.
- [x] Extender `ChangeUserStatusTest` para fijar: éxito → revoke invocado 1×; guard-fail (409) → revoke **no** invocado
      (se aborta antes del commit).

### D — Puerto identity-shaped + control en el detalle (PWA) (AC9, AC10, AC11)

- [x] `pwa/src/context/shared/http-client/infrastructure/ApiEndpoints.ts` — `+ USERS.CHANGE_STATUS: (id) =>
      `${userPath(id)}/status`` (espejo del bloque `BANK_ACCOUNTS.CHANGE_STATUS`; `userPath` ya hace `encodeURIComponent`).
- [x] `pwa/src/context/backoffice/user/domain/ChangeUserStatusRepository.ts` **nuevo** — puerto `changeStatus(id: string,
      status: UserStatus): Promise<User>`.
- [x] `pwa/src/context/backoffice/user/infrastructure/ApiChangeUserStatusRepository.ts` **nuevo** — `httpClient.patch(...,
      { status }, isUserSingleResponse)` → `User.fromPrimitives(response.data)` (reusa `isUserSingleResponse` exportado de
      `ApiUserRepository.ts`).
- [x] `pwa/src/context/backoffice/user/application/ChangeUserStatus.ts` **nuevo** — use case `run(id, status)` que delega
      en el puerto (mirror `ChangeBankAccountStatus`).
- [x] `pwa/src/context/shared/dependency-injection/infrastructure/Container.ts` — +2 binds (`BackOfficeChangeUserStatusRepository`
      → `ApiChangeUserStatusRepository` singleton; `BackOfficeChangeUserStatus` → use case).
- [x] `pwa/src/app/backoffice/users/_components/UserStatusControl.tsx` **nuevo** — `"use client"`, mirror
      `BankAccountStatusControl` (select de target {Suspend, Deactivate} + botón save disabled mientras no-dirty/saving) +
      manejo de error de U-2 (`<MutationError testId="user-status__error">` + `toastNotifier.success`). Gateado por
      `<Can permission={Permission.USERS_CHANGE_STATUS}>`. Visible/operable **solo si `user.status === ACTIVE`** (sin
      reinstate). Testids `user-status__select` / `user-status__save` / `user-status__error` (patrón `bank-account-status__*`).
- [x] `pwa/src/app/backoffice/users/[id]/page.tsx` — montar `<UserStatusControl>` en la superficie de detalle; tras éxito,
      `reload()` (de `useResourceItem`) refleja el nuevo `UserStatusBadge`. **No** tocar el read-side (`search`/`find`
      siguen en el toolkit genérico).

### E — Tests (Behat + unit + functional + e2e) (AC1–AC8, AC12, AC13)

- [x] **Behat** `api/features/backoffice/users/status.feature` **nuevo** — espejo de `bank_account/status.feature` +
      escenarios propios: éxito suspend + éxito deactivate (200 + `data.status` + **1 evento** + outbox + budget); **403**
      no-ADMIN + **401** sin sesión (mirror `users/access_control.feature`); **409 last-admin**; **409
      invalid-identity-transition** (re-suspender un `SUSPENDED`); **422** target fuera de `{SUSPENDED,DEACTIVATED}`;
      **400 invalid-uuid** (Scenario Outline); **404**. Assert-0-en-fallo: vaciar outbox + reset stats **antes** de la
      request (`behat-assert-zero-new-events-on-failure`).
- [x] **Unit** — extender `tests/Unit/Iam/Identity/Application/ChangeUserStatusTest.php` (revoke, Task C); `ChangeUserStatusRequest`
      (enum coercion + `Assert\Choice` subset); controller `#[CoversClass(UserPatchStatusController::class)]` (**nunca**
      `CoversNothing` — el wire-gate funcional alimenta cobertura; `sonar-coversnothing-zeroes-thin-controllers`).
- [x] **Unit — guard-fail NO muta el agregado (order-independence).** Test explícito: guard `false` → el `User` (vía
      `UserMother`, estado `ACTIVE`) **nunca** recibe `suspend()`/`deactivate()` — asertar `status()` sigue `ACTIVE`,
      `pullDomainEvents()` **vacío** y `save`/revoke **no** invocados. Hoy se cumple por orden de código; el test lo blinda
      frente a un refactor que reordene guard/mutación.
- [x] **Functional** — wire-gate del controller (golden/response) en `tests/Functional/Iam/Identity/Infrastructure/
      Controller/` (patrón `UserDetailResponseGoldenFunctionalTest`) + autz en `PermissionVoterAccessDecisionTest`.
- [x] **e2e** `pwa/tests/e2e/backoffice/users-change-status-real-api.spec.ts` **nuevo** — `authenticatedTest`, abre el
      detalle de un usuario **ACTIVE no-admin** (semilla), suspende, asserta `UserStatusBadge` = «Suspended»; sin
      exact-counts. Reusa los timeouts/viewport de `users-invite-real-api.spec.ts`.

### Verificaciones (Working principle 4)

- [x] `make php.stan` en cada `.php` tocado (worker: `PHP_SERVICE=messenger_worker` si segfault 139).
- [x] `make php.quality` (stan + psalm-taint + phpmd + cs-fixer + **deptrac** + **bounded-context** + **error-contract**)
      **verde**. El error-contract debe quedar verde **sin** marcador nuevo (hecho 5) — si pide cambio, algo se desvió.
- [x] `make php.behat` (features nuevas + regresión). Re-sembrar el ADMIN e2e **después** de Behat (resetea la DB).
- [x] `make pwa.quality` + `make pwa.test.unit` + e2e (worktree: puerto efímero + overrides Playwright; EACCES → limpiar
      `.next-e2e`).
- [x] Si el adapter del guard necesita índice/containment nuevo → `make db.diff` (migración medida, no asumida).

### Review Findings (code review 2026-07-18)

Revisión adversarial en 3 capas (Blind Hunter · Edge Case Hunter · Acceptance Auditor) sobre el diff completo. Auditoría de aceptación: **13/13 AC cumplidos** (AC8 y AC12 «partial» = divergencias honestas ya documentadas en Completion Notes); D1–D7 todos cumplidos; las 3 divergencias verificadas son ciertas en el código.

**Resuelto (patch aplicado — opción b, Sergio 2026-07-18):**

- [x] [Review][Patch] TOCTOU en el guard del último administrador — **endurecido**. `keepsAnActiveAdminWithout` corría en `findAndGuard()` **antes y fuera** de la transacción, sin lock: con 2 admins activos, dos PATCH concurrentes que suspendan cada uno a uno distinto pasaban ambos el guard (read-committed) → **0 admins activos**, irrecuperable. **Fix:** el guard se movió **dentro** de la transacción de escritura y el adapter toma un `SELECT id … FOR UPDATE` sobre el **conjunto completo** de admins activos (sin excluir el target en SQL → ambas transacciones bloquean las mismas filas en el mismo orden → **sin deadlock**; bajo READ COMMITTED el perdedor re-lee el estado commiteado vía EvalPlanQual → **409 limpio**, no un 500). La exclusión del target se aplica en PHP. + test de integración `testTheGuardLocksTheActiveAdminSetToSerializeConcurrentTransitions` (prueba el `RowShareLock` vía `pg_locks`, determinista, sin carrera temporal). Verde: `php.quality` · unit (5) · integración (5) · functional wire-gate (3) · behat `status.feature` (13/13, canario de queries `22` intacto). [`api/src/Iam/Identity/Application/ChangeUserStatus.php`, `api/src/Iam/Identity/Infrastructure/Persistence/Doctrine/DoctrineActiveAdministratorDirectory.php`]

**Diferidos (checked; reales, no accionables ahora):**

- [x] [Review][Defer] Test funcional borra TODOS los admins sin rollback [`api/tests/Functional/Iam/Identity/Infrastructure/Controller/UserPatchStatusFunctionalTest.php:164-169`] — `deleteAllAdministrators()` commitea un `DELETE … roles @> ADMIN` global en la DB de test (trait base: «manual TRUNCATE, no DAMA rollback»). Impacto real bajo (`functional-admin` se auto-repara; DB de test ≠ DB e2e), pero destruye filas admin no relacionadas dentro del run. Fix ambiguo (el test conduce por HTTP → un tx rolled-back no es visible a la request sin DAMA). Nuevo, no pre-existente; low.
- [x] [Review][Defer] Guard confunde «no queda admin» con «este es el último admin» [`DoctrineActiveAdministratorDirectory.php:35-57`] — en un estado de 0 admins activos, excluir a un no-admin también deja 0 → suspender a un no-admin devuelve `409 last-active-administrator-protected` (error engañoso). Fail-safe (bloquea, no permite); solo alcanzable en estado 0-admins (que el endpoint mismo previene). Low.
- [x] [Review][Defer] Cobertura: `status` ausente/null/empty/lowercase → 422 no aserta­do [`api/features/backoffice/users/status.feature:647-664`] — AC4 lista «ausente» como caso 422; el Scenario Outline solo cubre valores presentes-pero-ilegales (`FROZEN`/`ACTIVE`/`INVITED`). Correcto por construcción (`IdentityStatus $status` no-nullable + `#[MapRequestPayload]`), sin test.
- [x] [Review][Defer] Cobertura: admin `INVITED` no cuenta en el guard · `SUSPENDED→DEACTIVATED` → 409 [`DoctrineActiveAdministratorDirectoryTest.php`, `status.feature`] — ramas del set de estado correctas pero sin aserción explícita (la integración cubre SUSPENDED/DEACTIVATED; la feature cubre re-suspend pero no deactivate-de-suspended).
- [x] [Review][Defer] `AllSessionsRevoked` se emite aunque el objetivo no tenga sesiones, y el éxito lo fija [`RevokeAllSessions.php`, `status.feature`] — smell semántico del módulo Session (pre-existente): el log registra «all-revoked» para identidades con 0 sesiones y la feature lo asegura duro. Si `RevokeAllSessions` se endurece a emitir-solo-si-revocó, rompe este test de status.

**Descartados (ruido / ya trackeado / divergencia honesta):** PWA `UserStatusControl` re-lanza no-`HttpError` en `void onSubmit()` (idéntico al mirror `BankAccountStatusControl`; **ya diferido como sistémico** en la review de U-2, fix central en `FetchHttpClient`) · AC12 e2e reducido a smoke suspend-only (divulgado en Completion Notes; Behat posee la matriz) · AC8 «CDC onFlush audita» inexacto (código correcto: `User` no es `AuditedEntity` a propósito; el registro durable es el evento de dominio) · AC10 gate «no-dirty» omitido (picker target-only sin estado dirty; razonable) · canario de query `22` acopla a internals de revoke (patrón aceptado de la casa) · churn de `api/config/reference.php` (auto-generado; commitear el diff regenerado está permitido).

## Dev Notes

### Crux 1 — el controller traduce `status → método`, el DTO acota el target

`ChangeUserStatus` expone `suspend`/`deactivate`, **no** un `change($id, $status)` — es un contrato de intención, no de
mutación genérica (SI-18). El endpoint recibe `{status}`; el controller hace un `match` exhaustivo sobre el set que el DTO
ya acotó con `#[Assert\Choice([SUSPENDED, DEACTIVATED])]`. **Dos capas de validación complementarias, no redundantes:** el
DTO rechaza en el borde un **target** que el endpoint no ofrece (`INVITED`/`ACTIVE`/desconocido → `422`, AC4); el agregado
rechaza una **transición ilegal desde el estado actual** (no-`ACTIVE` → `409 invalid-identity-transition`, AC5). No metas
la matriz de transición en el controller ni en el DTO — vive en `User::guardTransitionTo`.

### Crux 2 — el guard `ActiveAdministratorDirectory` no existe en producción (bloqueante)

`ChangeUserStatus` se construyó en la Épica II **sin entry-point**, así que su puerto solo tiene un doble de test. U-3 es
el primer runtime → **hay que construir el adapter** (Task B). Sin él, el endpoint lanza un error de autowiring, no un
`409`. Es trabajo de U-3 por construcción (el guard es parte del comportamiento del endpoint), no scope creep. Verifícalo
con un functional wire-gate que ejerza el 409 real (no solo el unit con el doble).

### Crux 3 — «invalida sesiones» es un requisito, y hoy no ocurre (D3)

El AC1 del epic y el contrato J5 (`EXPERIENCE.md:268`: «Suspender / Desactivar ⇒ invalida sus sesiones») exigen que el
cambio de estado corte el acceso vivo. Pero un flip `ACTIVE→SUSPENDED` **no** toca password ni roles, así que ni el
listener de deauth ni el `UserChecker` (login-only) lo cortan; el gate fail-closed de II-7 bloquea **cuando la sesión está
revocada en el registro**. Por eso hay que **revocar** explícitamente. La revocación es la costura que hace visible el muro
post-identidad — sin ella, el AC7 y el e2e J5 son falsos-verdes. Ver D3 para el «cómo».

### Crux 4 — un PATCH de mismo-estado NO es idempotente (diverge de BankAccount)

BankAccount trata `status→mismo status` como no-op idempotente (200, sin evento). User **no**: `guardTransitionTo` exige
`ACTIVE`, así que re-suspender un `SUSPENDED` → `409 invalid-identity-transition`. **No copies** el escenario de
idempotencia de `bank_account/status.feature` — sustitúyelo por el 409. Es una diferencia de dominio deliberada (el ciclo
de identidad es unidireccional; el de una cuenta bancaria no).

### Decisión D2 — el guard lee admins de `User.roles` (single-context), no `Membership` (JOIN)

El docblock del puerto (era Épica II) menciona un `INNER JOIN membership ⋈ identity_user`. **Se descarta para U-3:** SI-15
fija que la **fuente operativa de roles hoy es `Iam/Identity/User.roles`** (columna JSON en el mismo agregado), y que
re-apuntar el auth/read-model a `Membership.roles` es **tenancy — fuera de esta épica**. Un adapter single-context sobre
`identity_user` (`status=ACTIVE ∧ ADMIN ∈ roles ∧ id≠:excluded`) es correcto para single-org, **evita un seam cross-context
`Identity→Organization`** (allowlist + deptrac + riesgo de ciclo) y respeta SI-15/NFR8. **Coste:** cuando tenancy mueva la
fuente autoritativa a `Membership`, este adapter se re-apunta — pero eso es la misma re-apunta que ya deberá hacer el
read-model, no deuda nueva. *(Argumento: DIP intacto — el puerto no cambia; NFR8 deptrac verde; el JOIN cross-context solo
compraría autoridad org-scoped que single-org no necesita.)*

### Decisión D3 — revoke de sesión SÍNCRONO post-commit (mirror `CompletePasswordReset`), no subscriber async

**Decidido (Sergio, 2026-07-18):** invocar `RevokeSessionsBestEffort->revoke($userId)` **dentro de**
`ChangeUserStatus`, **después** del commit (best-effort). *Argumento:* suspender es un control de seguridad — debe cortar
el acceso **inmediatamente**; un subscriber async (`UserSuspended`→outbox→worker) deja una ventana en la que el suspendido
sigue operando hasta que el worker drena. El precedente `CompletePasswordReset` ya hace exactamente esto (mismo seam
`RevokeSessionsBestEffort`, mismo contexto). *Coste:* añade una dep a un Application service de la Épica II (modificación
acotada, cubierta por su unit test). *Alternativa descartada:* reactor sobre `UserSuspended`/`UserDeactivated` — más
desacoplado, pero async ⇒ inseguro para un corte de acceso.

### Decisión D1 — puerto identity-shaped dedicado (no cablear `update()`)

Igual que U-2 con `InviteUser`: el cambio de estado va por un `ChangeUserStatusRepository` propio; `ApiUserRepository.update()`
**sigue** como stub `notSupported`. SI-18: la identidad no es CRUD. U-2 ya pagó el patrón (Regla-de-Tres cumplida: invite +
changeStatus = 2 casos identity-shaped), así que aquí es reuso, no abstracción especulativa.

### Sobre el `#[Assert\Choice]` y el helper que U-2 anticipó — NO se dispara aquí

U-2 anotó que «el disparador de extracción de un `Role::values()` es U-3 si aparece un DTO enum-array». **No aplica:** el
DTO de status usa **otro enum** (`IdentityStatus`, no `Role`), un **valor único** (no array) y un **subconjunto**
(`{SUSPENDED, DEACTIVATED}`, no `::cases()`). No enumera `Role::cases()` → no es el segundo caller de ese patrón. **No
extraigas** un helper compartido de vocabulario: seguiría siendo YAGNI (dos enums distintos, dos formas distintas).

### Ficheros a tocar (verificado)

| Fichero | Acción |
|---|---|
| `api/src/Iam/Identity/Infrastructure/Controller/UserPatchStatusController.php` | **NUEVO** — `PATCH .../status`, `#[IsGranted('users.changeStatus')]` |
| `api/src/Iam/Identity/Infrastructure/Http/ChangeUserStatusRequest.php` | **NUEVO** — DTO `{status}` enum + `Assert\Choice` subset |
| `api/src/Iam/Identity/Infrastructure/Persistence/…ActiveAdministratorDirectory.php` | **NUEVO** — adapter Doctrine (D2) |
| `api/src/Iam/Identity/Application/ChangeUserStatus.php` | +dep `RevokeSessionsBestEffort` + revoke post-commit (D3) |
| `api/src/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicy.php` | docblock stale `:50-54` (boy-scout) |
| `api/config/services.yaml` | bind del adapter (si autowiring no basta por el `dql:` JSONB) |
| `api/features/backoffice/users/status.feature` | **NUEVO** — éxito×2/403/401/409×2/422/400/404 + eventos + budget |
| `api/tests/Unit/Iam/Identity/Application/ChangeUserStatusTest.php` | +casos revoke |
| `api/tests/Unit/Iam/Identity/Infrastructure/Http/ChangeUserStatusRequestTest.php` | **NUEVO** |
| `api/tests/Functional/Iam/Identity/Infrastructure/Controller/UserPatchStatus…Test.php` | **NUEVO** — wire-gate 200/403/409 |
| `api/tests/Integration/…/ActiveAdministratorDirectory…Test.php` | **NUEVO** — adapter (Postgres real) |
| `pwa/src/context/shared/http-client/infrastructure/ApiEndpoints.ts` | +`USERS.CHANGE_STATUS` |
| `pwa/src/context/backoffice/user/domain/ChangeUserStatusRepository.ts` | **NUEVO** — puerto |
| `pwa/src/context/backoffice/user/infrastructure/ApiChangeUserStatusRepository.ts` | **NUEVO** — PATCH adapter |
| `pwa/src/context/backoffice/user/application/ChangeUserStatus.ts` | **NUEVO** — use case |
| `pwa/src/context/shared/dependency-injection/infrastructure/Container.ts` | +2 binds |
| `pwa/src/app/backoffice/users/_components/UserStatusControl.tsx` | **NUEVO** — control gateado (mirror `BankAccountStatusControl`) |
| `pwa/src/app/backoffice/users/[id]/page.tsx` | +montar `<UserStatusControl>` + `reload()` |
| `pwa/tests/…` (adapter, use case, control) + `pwa/tests/e2e/backoffice/users-change-status-real-api.spec.ts` | tests |

### Testing (patrones del repo)

- **Semilla ACTIVE no-admin para el e2e/Behat de éxito.** `suspend`/`deactivate` exigen `ACTIVE` (hecho 3) y el admin
  sembrado es el único ADMIN → suspenderlo dispara AC3 (409). El ciclo del epic «invite → INVITED → changeStatus» **no**
  suspende un `INVITED` directamente (→ 409). Necesitas un usuario **`ACTIVE` no-admin**: verifica si existe un fixture; si
  no, **añádelo** (Hautelook Alice / provisión) — es prerequisito del éxito, no del path de error.
- **Eventos:** `EventStoreContext`/`OutboxContext` — nombres `erpify.iam.identity.suspended` / `...deactivated`. Assert-0
  en fallo (vaciar outbox + reset stats **antes**).
- **Sesión ADMIN en Behat:** `I am logged in as an administrator` (`SecurityContext`, fixture `user_admin`, siembra
  `iamSessionId`).
- **Cobertura de controlador fino:** `#[CoversClass(UserPatchStatusController::class)]`, **nunca** `#[CoversNothing]`.
- **PWA:** mockea `HttpClient` en adapter/use-case; el control se testea con transición feliz (refleja el badge) + un `409`
  que renderiza `<MutationError>`. e2e real-API, sesión por worker, sin exact-counts; scope a testids de entidad (no row
  counts document-wide — el toolbar de Symfony inyecta filas).

### Gotchas heredados (verificados)

- **Containment JSONB para `ADMIN ∈ roles`.** `identity_user.roles` es JSONB (`list<string>`); el grammar `Shared/Search`
  **no** tiene operador de containment (por eso el filtro-por-rol se difirió en U-0). El adapter del guard es DQL/DBAL
  **propio** (no pasa por `SearchFieldMap`): usa el operador nativo de Postgres (`roles @> :adminJson`) vía DBAL
  parametrizado, o `JSON_CONTAINS` si hay `dql:` registrado. **No** reabras el filtro-por-rol del read-side; esto es una
  query interna del guard. El índice probable si la query se vuelve caliente es un **GIN sobre `roles`** — **medir con
  `EXPLAIN ANALYZE` antes de crearlo** (single-org, docenas de filas → seq scan basta hoy; no lo asumas).
- **`users` es PLURAL** (SI-20) — `users.changeStatus`, nunca `user.changeStatus` ni `users.change_status`.
- **Presupuesto de query +2 por write envuelto** (BEGIN/COMMIT); el revoke de sesión añade sus propias queries — mídelas
  en vivo (`I dump the number of executed queries`) y fija el número, no lo adivines (`behat-query-budget-transaction-overhead`).
- **`make php.behat` resetea la DB** → re-siembra el ADMIN e2e **después** (password posicional; `organization:provision`
  primero).
- **Inline SQL step de Behat: sin comillas dobles** embebidas (usa PyString / JSONB `'{}'` sin comillas).
- **`php.stan` puede segfaultear** en el worker web (139) → `PHP_SERVICE=messenger_worker`.
- **Rector:** `/** @phpstan-var T */` (no `@var` sin nombre) sobre `return` en tests; importa el FQCN en closures de
  `array_map` (>120 chars).
- **e2e en worktree:** `PLAYWRIGHT_BASE_URL`/`_API_BASE_URL` al puerto efímero (`docker compose port php 443`); EACCES →
  `rm -rf pwa/.next-e2e && rm -f pwa/next-env.d.ts`; contenedor `pwa` debe estar `Up` (no `Created`).

### Decisiones ya tomadas — no re-abrir

| # | Decisión | Argumento |
|---|---|---|
| D1 | Puerto identity-shaped `ChangeUserStatus` + adapter dedicado; `update()` sigue no-soportado | SI-18; reuso del patrón de U-2 (Regla-de-Tres cumplida) |
| D2 | El guard lee admins de `User.roles` (single-context), **no** JOIN a `Membership` | SI-15 (fuente operativa = `User.roles` hoy); evita seam cross-context; tenancy re-apunta luego |
| D4 | DTO enum-tipado + `Assert\Choice([SUSPENDED,DEACTIVATED])`; controller `match` exhaustivo | Dos capas complementarias (target vs transición); mirror BankAccount + acote |
| D5 | Gateo **de controller** (`#[IsGranted]`), no en `ChangeUserStatus` | Precedente U-0/U-2; meter authz en el use case acoplaría cualquier caller sin sesión |
| D6 | 409 (no no-op) en mismo-estado / no-`ACTIVE` | Dominio unidireccional (`guardTransitionTo` exige `ACTIVE`) — diverge de BankAccount a propósito |
| D7 | Sin realtime (reload basta) | UX-DR5 «realtime opcional; la lista no lo tiene hoy»; el CDC audita el cambio sin subscriber |
| D3 | Revoke de sesión **síncrono post-commit** en `ChangeUserStatus` (no subscriber async) | Suspender es control de acceso → corta ya; async deja ventana hasta que el worker drena. Mirror `CompletePasswordReset` (Sergio, 2026-07-18) |

### Fuera de alcance (frontera explícita)

- **Reinstate** (`SUSPENDED→ACTIVE`) / `SUSPENDED→DEACTIVATED` — no existe método de dominio; UX-DR4 «sin reinstate». Si
  se pide, es historia propia (nuevo caso de uso + AC de guard).
- **Edición de roles** → U-4 (candidato). **Borrado GDPR** → U-5. U-3 no añade `erase` ni `update`.
- **Filtro por rol** en la lista — diferido desde U-0 (JSONB, sin containment en el grammar compartido). El guard usa una
  query propia, **no** reabre ese filtro.
- **Tenancy / `Membership.roles` como fuente autoritativa del guard** — SI-15, diferido (D2).
- **Reactor de auditoría/realtime sobre `UserSuspended`/`UserDeactivated`** — el CDC `onFlush` ya audita; realtime es
  opcional (UX-DR5). No se añade subscriber.
- **Renombrar `ActiveAdministratorDirectory`** — el nombre describe la implementación más que la intención (responde a
  `¿al quitar a este usuario queda algún administrador activo?`); es un puerto **existente** de la Épica II — renombrarlo
  rompería su contrato + el doble de test → **no** se toca aquí. Anotado para una futura ADR.

### Project Structure Notes

- Controller + DTO en `Iam/Identity/Infrastructure/{Controller,Http}/` (misma layer deptrac `Iam.Identity.Infrastructure`
  que los read-controllers) → limpio, **sin allowlist nuevo** (el adapter del guard es single-context por D2). El `#[Route]`
  resuelve `/api/v1/backoffice/users/{id}/status` vía el resource `api_v1_iam_identity` (prefijo `/api/v1`).
- PWA: use case + puerto + adapter en `context/backoffice/user/{application,domain,infrastructure}`; el control en
  `app/backoffice/users/_components/`, montado en `[id]/page.tsx`. Sin migraciones de entidad (el ciclo de estado ya
  existe); posible migración **solo** si el guard necesita un índice para el containment de `roles` (medir primero).

### References

- [Source: `_bmad-output/planning-artifacts/epics-users-admin.md#Story 1.4 (U-3)`] — AC (post-U-1); FR6, NFR6.
- [Source: `_bmad-output/planning-artifacts/arch-addendum-users-admin.md`] — SI-16…SI-20; fila U-3 («precedente BankAccount
  PATCH .../status; validado contra transiciones legales desde ACTIVE»); SI-15 (fuente de roles); DAG.
- [Source: `_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-07-06/EXPERIENCE.md`] — J5 (Acción→Evento→Estado→
  Superficie); machine `INVITED→ACTIVE↔SUSPENDED↔DEACTIVATED`; «suspender/desactivar invalida sesiones» (:268); muros
  post-identidad `SUSPENDED`/`DEACTIVATED` (:143-144).
- [Source: `api/src/Iam/Identity/Application/ChangeUserStatus.php`] — `suspend`/`deactivate`; guard antes de mutar;
  `TransactionManager`.
- [Source: `api/src/Iam/Identity/Domain/Entity/User.php`] — `suspend()/deactivate()/guardTransitionTo(ACTIVE)`.
- [Source: `api/src/Iam/Identity/Domain/Repository/ActiveAdministratorDirectory.php`] — puerto del guard **sin adapter de
  producción**.
- [Source: `api/src/Iam/Identity/Domain/Exception/{LastActiveAdministratorProtected,InvalidIdentityTransition}.php`] —
  ambos `implements Conflict` → 409 (sin marcador nuevo).
- [Source: `api/src/Iam/Identity/Application/RevokeSessionsBestEffort.php` + `CompletePasswordReset.php`] — patrón de revoke
  de sesión (D3).
- [Source: `api/src/Backoffice/BankAccount/Infrastructure/Controller/BankAccountPatchStatusController.php` +
  `Application/Command/ChangeBankAccountStatusCommand.php` + `features/backoffice/bank_account/status.feature`] — precedente
  API a espejar (con las divergencias de dominio anotadas).
- [Source: `pwa/src/app/backoffice/banks/[id]/accounts/_components/BankAccountStatusControl.tsx` +
  `context/backoffice/bankaccount/{application/ChangeBankAccountStatus.ts,domain/BankAccountRepository.ts,infrastructure/ApiBankAccountRepository.ts}`]
  — precedente PWA a espejar.
- [Source: `_bmad-output/implementation-artifacts/u-2-invitar-alta-invitacion.md`] — patrón puerto identity-shaped, gateo de
  controller, testing (NotificationContext/eventos/budget), gotchas de worktree e2e.
- [Source: `docs/adr/identity-invitation-lifecycle.md`] — ciclo de identidad + registro/revocación de sesiones (II-7).

## Dev Agent Record

### Agent Model Used

Opus 4.8 (1M context).

### Debug Log References

- `make php.stan` (per file) · `make php.quality` (stan + psalm-taint + phpmd + cs-fixer + rector + deptrac + bounded-context + error-contract) — green; error-contract green **without** a new marker (hecho 5 confirmado), deptrac green **without** a new allowlist (D2 single-context adapter).
- `make php.behat` — 329 scenarios / 2994 steps green (incl. `features/backoffice/users/status.feature`, 13 scenarios).
- `make php.unit` — 1987 tests / 8702 assertions green.
- `make pwa.quality` (eslint + prettier + tsc) · `make pwa.test.unit` — 1088 tests green.
- e2e `users-change-status-real-api.spec.ts` — green and **repeatable** (the `ON CONFLICT … SET status='ACTIVE'` seed re-activates the target each run).

### Completion Notes List

Delivered as one unit: backend endpoint + guard adapter + session revoke, PWA identity-shaped port + gated control, and tests (Behat/functional/integration/unit/e2e).

- **Three verified divergences from the story's stated assumptions** (surfaced, none reversing a decision):
  1. `identity_user.roles` is a Postgres **`json`** column, **not `jsonb`** (the Gotcha assumed JSONB). `@>` containment needs `jsonb`, so the guard adapter casts `roles::jsonb @> CAST(:adminRole AS jsonb)`. Validated by the real-Postgres adapter integration test.
  2. `UserSuspended` / `UserDeactivated` are **not routed to `async`** (only `PasswordResetCompleted` is, among Identity events), so AC6's "outbox" is the **event_store** log, not the async queue — the success scenarios assert `0 outbox events … on the queue "async"` (aligns with D7: no realtime consumer). The session revoke is provable via the `erpify.iam.session.all-revoked` event (mirror of `password_reset.feature`).
  3. `User` deliberately does **not** implement `AuditedEntity` (credential-leak guard), so AC8's "the CDC onFlush audits the change" is inaccurate — the durable record of the transition is the domain event in the event_store, not an `audit_log` diff. AC8's substantive claim (deactivate ≠ erase: `actor_id` preserved) holds; `User` was **not** made audited (out of scope + a security regression).
- **AC13 query budget**: measured live (deterministic 22 on `default` across runs) and pinned as a canary in the success scenario, with a comment on its composition (admission gate read + guard EXISTS + wrapped write +2 + wrapped revoke +2).
- **Controller `match`**: PHPStan (level max) requires exhaustiveness, so the two-arm `match` carries a throwing `default` guarding the (validation-unreachable) INVITED/ACTIVE — a defensive belt, not a real branch.
- **Boy-scout doc fixes** (named, doc-only, tied to D2): the `ActiveAdministratorDirectory` port docblock and the in-memory test-double docblock described a JOIN adapter that D2 chose not to build; both now describe the shipped single-context `EXISTS`. Plus the stale `EXPLICIT_GRANTS` docblock (`read`/`invite`/`changeStatus` now all back endpoints; only `erase` remains ahead).
- **PWA control**: the save button is disabled only while `saving` (the story's "no-dirty" gate doesn't map to a target-only picker with no current-status option); all other AC10/AC11 behaviour (Suspend/Deactivate offered, `<Can>`-gated, ACTIVE-only, persistent `<MutationError>`, badge reflected via `reload()`) is met.
- **E2E seed** (chosen after consulting the architect / dev / test-architect lenses — unanimous Option 1): a login-less ACTIVE non-admin fixture (`e2e-suspendable@erpify.test`) seeded via raw SQL in `make/pwa.mk` (outside the story's file list — flagged here). `password_hash` is NULL (never authenticates); `ON CONFLICT DO UPDATE SET status='ACTIVE'` re-activates it each run, defeating the unidirectional-lifecycle trap; the e2e is scoped to a single happy-path smoke (Behat owns the 409/422/403/401/404 matrix).
- **Security self-review** (per CLAUDE.md): endpoint gated by `#[IsGranted('users.changeStatus')]`; payload validated by an enum DTO + `#[Assert\Choice]` via `#[MapRequestPayload]` (422); route id guarded by `Uuid::ensure` (400) inside the use case; guard SQL fully parameterised (no interpolation); response is the per-view resource DTO (no credential/audit fields); errors via the RFC 9457 pipeline (no manual bodies); no secrets/`.env` in the diff; the seed row carries no PII/secret. Migrations: none (the lifecycle already existed; the guard runs a seq scan over single-org rows — no index added, measured not assumed).

### File List

**API — new**

- `api/src/Iam/Identity/Infrastructure/Http/ChangeUserStatusRequest.php`
- `api/src/Iam/Identity/Infrastructure/Controller/UserPatchStatusController.php`
- `api/src/Iam/Identity/Infrastructure/Persistence/Doctrine/DoctrineActiveAdministratorDirectory.php`
- `api/features/backoffice/users/status.feature`
- `api/tests/Unit/Iam/Identity/Infrastructure/Http/ChangeUserStatusRequestTest.php`
- `api/tests/Functional/Iam/Identity/Infrastructure/Controller/UserPatchStatusFunctionalTest.php`
- `api/tests/Functional/Iam/Identity/Infrastructure/Persistence/Doctrine/DoctrineActiveAdministratorDirectoryTest.php`

**API — modified**

- `api/src/Iam/Identity/Application/ChangeUserStatus.php` (+ `RevokeSessionsBestEffort` dep + post-commit revoke)
- `api/src/Iam/Identity/Domain/Repository/ActiveAdministratorDirectory.php` (docblock → D2 single-context)
- `api/src/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicy.php` (stale `EXPLICIT_GRANTS` docblock)
- `api/tests/Unit/Iam/Identity/Application/ChangeUserStatusTest.php` (revoke + guard-fail-no-mutation)
- `api/tests/Unit/Iam/Identity/Application/InMemoryActiveAdministratorDirectory.php` (docblock → D2)
- `api/tests/Functional/Iam/Identity/Infrastructure/Security/PermissionVoterAccessDecisionTest.php` (`users.changeStatus` case)

**PWA — new**

- `pwa/src/context/backoffice/user/domain/ChangeUserStatusRepository.ts`
- `pwa/src/context/backoffice/user/infrastructure/ApiChangeUserStatusRepository.ts`
- `pwa/src/context/backoffice/user/application/ChangeUserStatus.ts`
- `pwa/src/app/backoffice/users/_components/UserStatusControl.tsx`
- `pwa/tests/context/backoffice/user/infrastructure/ApiChangeUserStatusRepository.test.ts`
- `pwa/tests/context/backoffice/user/application/ChangeUserStatus.test.ts`
- `pwa/tests/app/backoffice/users/userStatusControl.test.tsx`
- `pwa/tests/e2e/backoffice/users-change-status-real-api.spec.ts`

**PWA — modified**

- `pwa/src/context/shared/http-client/infrastructure/ApiEndpoints.ts` (`USERS.CHANGE_STATUS`)
- `pwa/src/context/shared/dependency-injection/infrastructure/Container.ts` (+2 binds)
- `pwa/src/app/backoffice/users/[id]/page.tsx` (mount `<UserStatusControl>` + `reload()`)

**Infra — modified**

- `make/pwa.mk` (e2e seed: ACTIVE non-admin `e2e-suspendable@erpify.test`)

### Change Log

- 2026-07-18 — U-3 implemented: `PATCH /api/v1/backoffice/users/{id}/status` (suspend/deactivate), last-active-admin guard adapter (single-context, D2), post-commit session revoke (D3), PWA identity-shaped port + gated `<UserStatusControl>`, and full test coverage (Behat/functional/integration/unit/e2e). Status → review.
