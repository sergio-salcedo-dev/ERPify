---
baseline_commit: 3bb35964
---
# Story 1.1 (U-0): Read-side de identidades — lista + detalle conectados al backend real

Status: ready-for-dev

<!-- Validación opcional. Ejecuta `bmad-create-story` validate para un chequeo de calidad antes de dev-story. -->

## Story

Como **ADMIN**,
quiero **ver el padrón de usuarios (lista paginada + detalle) desde la consola `/backoffice/users` conectada al backend real**,
para **administrar identidades sobre datos reales en vez del mock in-memory**.

## Contexto (leer antes de tocar código)

Esta es **U-0 (PR-1)** de la épica `users-admin` (orden de merge safe-first
`U-0 → U-1 → (U-2 · U-3) → U-4 · [U-5a → U-5b]`). Es la **primera** historia y la **más pesada** — el epic la marca
«partible al crear historia» (ver **Decisión D3**). Es **aditiva**: read-only, no cambia ningún comportamiento
existente, ninguna historia posterior depende de ella en su orden de merge invertido.

El hueco que cubre: la consola PWA `pwa/src/app/backoffice/users` es hoy un `InMemoryUserRepository` + `userSeed`
sobre el puerto genérico `CrudRepository` — **desconectada del backend**, con un `Role` inventado
(`SUPER_ADMIN/EMPLOYEE/CUSTOMER/SUPPLIER`) que **no existe** en el dominio. El backend `Iam/Identity` es
**invitation-based, no CRUD** y **no tiene endpoints de lista/detalle** (hay que construir el read-side). U-0 hace
tres cosas en tres costuras:

- **A · Auth-data (RBAC):** `users` → `TIER_OPT_OUT` + `users.{read,invite,changeStatus,erase}` → `[ADMIN]` en
  `StaticAuthorizationPolicy` (edición de **datos**, el tripwire `token_get_all` sigue verde).
- **B · Read-side de identidades (API):** proyección `UserRow` single-context (`SELECT NEW … FROM User`, **sin JOIN**),
  `GET /backoffice/users` (keyset) + `GET /backoffice/users/{id}`, ambos `#[IsGranted('users.read')]`, con Resource
  DTOs por-vista y el envelope-v2 que ya usa Banks.
- **C · Conexión PWA ↔ backend (front):** `ApiUserRepository`(search+find) + navigator, swap de los 2 binds mock→real
  **sin cambios de consumidor**, `Role` alineado al backend, filtro de rol eliminado, `create/update/delete` → stub
  tipado *no-soportado*.

> **A + B son backend; C es frontend.** El **e2e de C** necesita A + B vivos (el endpoint real). A y C comparten **solo
> el contrato de wire** (`{data[], pagination{…}}` + shape de fila) → son **paralelizables** en dev con ese contrato
> como interfaz (patrón CLAUDE.md: subagente API + subagente PWA en paralelo → verificar cada uno → commit).

**Reutiliza, no reinventes** — todo el andamiaje ya existe y está verificado en `main @ 3bb35964`:

- **Read-side backend = espeja Bank / BankAccount.** `DoctrineSearchEngine::paginate(...)`, `SearchFieldMap`/`SortFieldMap`/
  `FieldMapping`, `SearchQuery` (`#[MapQueryString]`), `SearchResponder` (compone el envelope + `links`), `ResourceResponder`
  (detalle), `PaginationMeta`, `CursorCodec` (cursores opacos HMAC), `Uuid::ensure()` — **todo es `Shared/Search` /
  `Shared/Http` / `Shared/Uuid`, se consume, no se recrea.** El patrón de proyección con `SELECT NEW` es
  `BankAccountCollectionRow`; el patrón entidad→resource es `Bank`.
- **RBAC = añade 2 líneas de datos.** `StaticAuthorizationPolicy` es data-only con tripwire; el precedente exacto de
  recurso opted-out es `auditTrail`.
- **Conexión PWA = espeja Banks.** `ApiBankRepository`/`ApiBankSearchNavigator`/`ApiEndpoints.BANKS`/`ResponseGuard` son la
  plantilla. **El toolkit genérico (`useResourceList`/`useResourceItem`/`DataTable`/`AsyncBoundary`/`MutationError`/`<Can>`)
  no se toca** — los consumidores dependen solo de las claves DI + puertos genéricos.

Fuente de verdad del diseño (**no re-abrir, ya ratificado**):
[`_bmad-output/planning-artifacts/epics-users-admin.md`](../planning-artifacts/epics-users-admin.md) — **Story 1.1, FR1/FR2/FR3, NFR1-11, UX-DR1/UX-DR2, SI-16/SI-17/SI-20** ·
[`_bmad-output/planning-artifacts/arch-addendum-users-admin.md`](../planning-artifacts/arch-addendum-users-admin.md) — **SI-16…SI-20 + fila U-0 de la tabla de localización + Riesgos** ·
[`docs/adr/rbac-authorization-model.md`](../../docs/adr/rbac-authorization-model.md) — **D5 (constantes de permiso por módulo), D9 (sin ABAC)** ·
[`docs/adr/identity-invitation-lifecycle.md`](../../docs/adr/identity-invitation-lifecycle.md) — el `User` aggregate + `IdentityStatus`/`Role`.

**La frase que gobierna U-0:** el read-side de identidades es una **lectura state-oriented sobre `identity_user`** —
proyección de columnas explícitas (nunca la entidad), keyset (nunca OFFSET), single FROM (sin N+1), gateada
`users.read` ADMIN-only por **opt-out** — y la consola PWA **conmuta el mock por HTTP real vía DI sin que ningún consumidor
cambie**. El gateo de **cliente** (`<Can>`) sigue **muerto hasta U-1** (`/me` devuelve `permissions:[]`), así que U-0 se
sostiene **solo con enforcement server-side** (`#[IsGranted]` → `403` → `AsyncBoundary`), correcto para una consola ADMIN-only.

---

## Acceptance Criteria

Los AC se redactan como **invariantes verificables** enganchados al addendum (SI-16…SI-20) y a las FR, de modo que una
refactorización futura no pueda romper una garantía sin que un test la detecte. AC1-2 = costura A (auth-data); AC3-4 =
costura B (API); AC5 = costura C (PWA); AC6-7 = invariantes transversales.

1. **(A · FR2 · SI-17 — opt-out + grants, data-only)** En `StaticAuthorizationPolicy`: `users` ∈ `TIER_OPT_OUT` y
   `users.read`, `users.invite`, `users.changeStatus`, `users.erase` ∈ `EXPLICIT_GRANTS → [Role::ADMIN->value]`. El
   tripwire `StaticAuthorizationPolicyIsDataOnlyTest` **sigue verde** (solo datos, ningún token ejecutable: sin `match`/`fn`/
   `new`/`?:`/llamadas). **El opt-out es lo que hace el trabajo** — sin él, `users.read` se auto-concedería a `VIEWER`
   (`read` es verbo de tier). Los 4 grants **se registran ya** aunque U-0 solo cablee `users.read` a endpoints: documentan
   qué acciones existen y localizan el seam (SI-17). Un test prueba: ADMIN concede las 4; `VIEWER`/`EDITOR`/`MANAGER`/`[]`
   **niegan** `users.read` (contraste opt-out); regresión: `bank.read` **sigue** tiereando a `VIEWER`.

2. **(A · SI-17 — 403/401)** Dado un usuario `VIEWER`/`EDITOR`/`MANAGER` (no ADMIN), `GET /backoffice/users` → **403**
   (`forbidden`, RFC 9457); anónimo → **401**. Un test cubre ambos por ruta (lista y detalle).

3. **(B · FR1 · NFR5 — lista keyset)** Dado un ADMIN, `GET /backoffice/users` → un `Page` keyset con **envelope-v2**
   (`{data[], pagination{hasNext,hasPrev,count,links{next,prev}}}`, cursores **opacos** en `links`, nunca escalar
   top-level) de `UserListResource`, proyectado por `SELECT NEW UserRow(...) FROM User` **single FROM, sin JOIN**; filtros
   `email`(Contains) + `status`(Eq); `roles` **no** es filtrable (ausente del `SearchFieldMap`); sort
   `email`/`status`/`createdAt`/`updatedAt`, tie-break `id` (lo añade el engine). Presupuesto de queries: **1 FROM, sin N+1**
   (Behat `N requests got executed`). El `.value` del enum viaja en el wire (`ACTIVE`, `["ADMIN","VIEWER"]`), nunca una
   etiqueta traducida (SI-16).

4. **(B · FR1 · NFR7 — detalle)** Dado un ADMIN, `GET /backoffice/users/{id}` → `UserDetailResource`
   (`email`, `status`, `roles`, `createdAt`, `updatedAt`) **sin** sección `permissions` (los roles **son** el mapa de
   autorización — no existe `User.permissions`); `{id}` malformado → **400 `invalid-uuid`** (`Uuid::ensure()` **antes** de
   cualquier lookup); id ausente pero UUID válido → **404** (`UserNotFound`). Los errores fluyen por el pipeline RFC 9457 —
   nunca body manual.

5. **(C · FR3 · SI-16 · SI-18 — swap PWA)** `ApiUserRepository`(search+find) + `ApiUserSearchNavigator` **sustituyen** el
   mock (`toConstantValue` → `.to(...).inSingletonScope()`) **sin cambios de consumidor** (páginas/hooks dependen solo de las
   claves DI `"BackOfficeUserRepository"` / `"BackOfficeUserSearchNavigator"` + puertos genéricos); el `Role` del PWA se
   alinea al backend (`VIEWER/EDITOR/MANAGER/ADMIN/AUDIT_READER`); el **filtro de rol** se quita de `UsersFilters` (quedan
   email + status); `create/update/delete` del repo lanzan un error tipado **«no soportado»** (SI-18 — sus casos de uso son
   invite/changeStatus/erase en historias posteriores). El repo consume el envelope-v2 con `ResponseGuard` (nada de forma
   legacy `currentPage/cursor`).

6. **(Transversal · SI-20 — contrato de permisos)** `users.read` es **byte-idéntico** en `#[IsGranted('users.read')]`
   (API) y `Permission.USERS_READ = "users.read"` (PWA), camelCase, recurso **`users` (plural)**. *(Los renames
   `users.write→users.invite` y `users.delete→users.changeStatus` son U-2/U-3, no U-0 — `users.read` ya coincide hoy.)*

7. **(Transversal · seguridad — la entidad nunca se serializa)** El `User` (entity) **nunca** llega al serializer: la
   proyección `UserRow` + los Resource DTOs **excluyen por construcción** `password_hash`, `failedAttempts`, `lockedUntil` —
   ningún campo sensible ni de auditoría puede filtrarse al wire. Un golden test del detalle lo fija.

---

## Tasks / Subtasks

> Grupos A (RBAC) · B (API read-side) · C (PWA). **A antes que B** (B testea el 403 que A habilita). **B antes que el e2e de
> C**. A+B (backend) y C (PWA) paralelizables con el contrato de wire como interfaz.

### A — Auth-data RBAC (`api/src/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicy.php`) (AC1, AC2)

- [ ] En `TIER_OPT_OUT` (hoy `['auditTrail']`) → añadir `'users'`.
- [ ] En `EXPLICIT_GRANTS` (hoy `auditTrail.read`, `bankAccount.changeStatus`) → añadir las 4 filas
      `'users.read' | 'users.invite' | 'users.changeStatus' | 'users.erase' => [Role::ADMIN->value]`. **Solo `Role::ADMIN->value`
      y strings literales** (el tripwire prohíbe `match`/`fn`/`new`/`(`/`?`).
- [ ] Ampliar `api/tests/Unit/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicyTest.php` — espejar
      `testAuditTrailReadIsGrantedOnlyToAuditReaderAndAdmin`: ADMIN concede las 4 `users.*`; VIEWER/EDITOR/MANAGER/`[]` niegan
      `users.read`; regresión `bank.read` sigue tiereando a VIEWER.
- [ ] Ampliar `api/tests/Functional/…/Security/PermissionVoterAccessDecisionTest.php` — `users.read` concedido a ADMIN,
      denegado a tier genérico (espejar el caso `auditTrail`).
- [ ] **NO** tocar el tripwire `StaticAuthorizationPolicyIsDataOnlyTest` (queda verde solo). **NO** nombrar nada `invoice`
      (centinela OCP de `AuthorizationCoreIsClosedForModificationTest`). `users` ≠ `invoice` → no rompe el OCP test.

### B — API read-side en `api/src/Iam/Identity/` (AC3, AC4, AC6, AC7)

- [ ] **Constante de permiso** — `Infrastructure/Security/UsersPermission.php` (espeja `BankAccountPermission`): al menos
      `public const string READ = 'users.read';` (INVITE/CHANGE_STATUS/ERASE aterrizan **con su controlador** en U-2/U-3/U-5 —
      YAGNI). ⚠️ **Recurso `users` PLURAL** — no `user.read` singular; debe ser byte-idéntico a `Permission.USERS_READ` (SI-20).
- [ ] **Proyección** — `Domain/Projection/UserRow.php` (`final readonly class`, ctor promocionado:
      `string $id`, `string $email`, `list<string> $roles`, `IdentityStatus $status`, `DateTimeImmutable $createdAt`,
      `DateTimeImmutable $updatedAt`). `createdAt`/`updatedAt` **públicos** (el `CursorPositionExtractor` los lee por
      property-path). `@SuppressWarnings("PHPMD.ExcessiveParameterList")`.
- [ ] **Puerto de búsqueda** — `Domain/Repository/UserSearchRepository.php` → `search(SearchCriteria): Page<UserRow>`.
- [ ] **Adaptador Doctrine** — `Infrastructure/Persistence/Doctrine/DoctrineUserSearchRepository.php` (o `implements
      UserSearchRepository` sobre `DoctrineUserRepository` con `#[AsAlias(UserSearchRepository::class)]`):
      `SELECT NEW UserRow(u.id, u.email, u.roles, u.status, u.createdAt, u.updatedAt) FROM User u` → `DoctrineSearchEngine::paginate($qb, $criteria, $searchFieldMap(), $sortFieldMap(), new PaginatorConfig($criteria->paginationMode, fetchJoinCollection: false), WirePaginationPolicy::wire(), $routingDirection)`.
      - `searchFieldMap()` = `['email' => new FieldMapping('u.email', operators:[Eq,In,Contains]), 'status' => new FieldMapping('u.status', operators:[Eq,In])]`. `roles` **ausente** (no filtrable — Riesgos).
      - `sortFieldMap()` = `['email'=>'u.email','status'=>'u.status','createdAt'=>'u.createdAt','updatedAt'=>'u.updatedAt']` (`id` lo añade el engine).
      - ⚠️ **VERIFICAR (crux D1):** que Doctrine hidrata la columna JSON `u.roles` vía `SELECT NEW` como `list<string>`.
        **Pruébalo con el test funcional de repo contra Postgres real** (`DoctrineUserSearchRepositoryTest`). Si NO hidrata →
        **fallback Shape A** (paginar `SELECT u FROM User u` → `Page<User>`, mapear `User::roles()` en el mapper). Ver Dev Notes.
- [ ] **Handler + query** — `Application/UserSearcher.php` + `Application/Query/SearchUsersQuery.php` (espeja `BankSearcher` +
      `SearchBanksQuery`).
- [ ] **Finder de detalle** — `Application/UserFinder.php`: `Uuid::ensure($id)` → `UserRepository::findById($id)` → si null
      `throw UserNotFound::…($id)` (**reusa** el puerto `UserRepository` y la excepción `UserNotFound` ya existentes —
      no crear repos nuevos para el detalle).
- [ ] **Resource DTOs por-vista** — `Application/Resource/UserListResource.php` y `UserDetailResource.php`
      (`id`, `email`, `status` = `$status->value`, `roles` = `list<string>` de `.value`, `createdAt`/`updatedAt` = ATOM).
      **Detalle SIN `permissions`.** (Hoy list y detail coinciden; siguen siendo DTOs separados — patrón del repo, divergen
      cuando el detalle hospede metadata de acciones en U-3.)
- [ ] **Mapper** — `Infrastructure/Http/UserResourceMapper.php`: `toListPage(Page): Page` (`array_map` preservando
      `hasNext/hasPrev/count/nextCursor/prevCursor`) + `toDetailResource(...)`; enum `->value`; timestamps `DateTimeInterface::ATOM`.
      **La entidad `User` NUNCA se serializa** (AC7).
- [ ] **Controladores** — `Infrastructure/Controller/UserSearchController.php` (`#[Route('/backoffice/users', methods:['GET'])]`,
      `#[IsGranted(UsersPermission::READ)]`, `#[MapQueryString] SearchQuery $query = new SearchQuery()`, delega en
      `SearchResponder->respond(...)`) + `UserGetController.php` (`#[Route('/backoffice/users/{id}', methods:['GET'])]`,
      `#[IsGranted(UsersPermission::READ)]`, `ResourceResponder->respond(...)`).
      - ⚠️ **VERIFICAR (D6):** que la ruta resuelve a **`/api/v1/backoffice/users`**. Los controladores en
        `Iam/Identity/Infrastructure/Controller/` reciben el prefijo `/api/v1` del recurso `api_v1_iam_identity`
        (`config/routes.yaml`) → `#[Route('/backoffice/users')]` da `/api/v1/backoffice/users`. Confírmalo con
        `make sf c='debug:router | grep users'` antes de escribir el test.
- [ ] **Índices** — evaluar btree en las columnas de sort (`status`, `createdAt`, `updatedAt`) vía `EXPLAIN` (NFR5 — medido,
      no asumido). `identity_user(email)` ya es `UNIQUE`. La tabla es diminuta hoy → probablemente **diferir**; si el plan lo
      pide, `make db.diff` → `make db.migrate` (migración nueva).
- [ ] **Behat** — `api/features/…/users/search.feature` + `get.feature` + `access_control.feature` (espeja
      `features/backoffice/bank/search.feature`+`get.feature` y `features/backoffice/audit/access_control.feature` —
      plantilla opt-out: 401 anón, 403 tier genérico, 200 ADMIN). Verifica la ruta/prefijo real antes.
- [ ] **PHPUnit** — Unit: `UserSearcherTest`, `UserFinderTest`, `UserRowContractTest` (contrato de proyección, espeja
      `BankAccountCollectionRowContractTest`), `UserResourceMapperTest`. Functional: `UserSearchCursorFunctionalTest`
      (keyset sobre `links` reales), `UserDetailResponseGoldenFunctionalTest` (golden — fija que no hay `permissions`/
      `password_hash`), `DoctrineUserSearchRepositoryTest` (**prueba la hidratación JSON de `roles`**).
- [ ] Gates: `make php.stan` por fichero → `make php.quality` → `make php.deptrac` → `make php.lint.bounded-context`
      → `make php.lint.error-contract` (todos verdes).

### C — Conexión PWA en `pwa/src/` (AC5, AC6)

- [ ] **Endpoints** — bloque `USERS` en `context/shared/http-client/infrastructure/ApiEndpoints.ts` con helper
      `userPath(id)` (`encodeURIComponent`) bajo `BACKOFFICE_PREFIX`: `LIST`, `DETAILS`, `CREATE`, `UPDATE`, `DELETE`
      (→ `/api/v1/backoffice/users`).
- [ ] **Repo real** — `context/backoffice/user/infrastructure/ApiUserRepository.ts`:
      `@injectable() class ApiUserRepository implements CrudRepository<User, UserInput>` (**SIN capa de adaptador** — el puerto
      `UserRepository` ya ES `CrudRepository<User,UserInput>`; ver crux D2). `search`/`find` vía `HttpClient` + `ResponseGuard`
      (guards `isUserPrimitives`/`isUserSearchResponse`/`isUserSingleResponse` + `toUserSearchPage` que devuelve `{items}`,
      espejando `ApiBankRepository`; `User.fromPrimitives`). `create`/`update`/`delete` → `throw` tipado **«no soportado»**.
- [ ] **Navigator real** — `context/backoffice/user/infrastructure/ApiUserSearchNavigator.ts`:
      `implements ResourceSearchNavigator<User>`; `follow(link)` con guard same-origin/relativo (`safeHref`), reusa los guards
      de `ApiUserRepository` (espeja `ApiBankSearchNavigator`).
- [ ] **DI swap** — `context/shared/dependency-injection/infrastructure/Container.ts` (líneas ~189-195): las 2 líneas
      `.toConstantValue(mock)` → `.bind("BackOfficeUserRepository").to(ApiUserRepository).inSingletonScope()` y
      `.bind("BackOfficeUserSearchNavigator").to(ApiUserSearchNavigator).inSingletonScope()`. Borrar la construcción
      `new InMemoryUserRepository()` + `new InMemoryResourceNavigator(...)` y sus imports.
- [ ] **Revocabulario de `Role`** — `context/shared/access/domain/Role.ts` → valores `VIEWER/EDITOR/MANAGER/ADMIN/AUDIT_READER`
      (solo `ADMIN` sobrevive). Ripple: `app/backoffice/users/_lib/userLabels.ts` (`ROLE_LABEL` — `Record<Role,string>`
      exhaustivo, **rompe la compilación hasta actualizar** = guardrail), `_components/UserForm.tsx` (checkboxes de rol),
      `_components/RolesBadges.tsx`, `context/shared/access/infrastructure/ui/DevSessionSwitcher.tsx`. Borrar
      `context/backoffice/user/infrastructure/userSeed.ts` (mock retirado).
- [ ] **Quitar filtro de rol** — `_components/UsersFilters.tsx`: borrar el `<select data-testid="users-filters__role">` +
      `handleRoleChange` + el conteo `role`. Limpiar el campo `role` en `_lib/usersFilterSort.ts` (`UsersFilter.role`,
      `EMPTY_FILTER`, `hasActiveFilter`), `_lib/usersSearchCriteria.ts` (rama `if (filter.role)`). Quedan email + status.
- [ ] **Detalle sin `permissions`** — borrar `permissions` de `context/backoffice/user/domain/User.ts`
      (`UserPrimitives`) y la sección de chips `user.permissions` en `app/backoffice/users/[id]/page.tsx` (UX-DR2 — el real no
      lo devuelve). El form/`UserInput.permissions`/`UserFormSchema` son **transitorios** (create/edit → invite/changeStatus
      en U-2/U-3, ocultos por `<Can>` hasta U-1): **mínima cirugía para que compile**, no invertir.
- [ ] **NO tocar** el enum `Permission` (`users.write`/`users.delete` se renombran en U-2/U-3; `users.read` ya coincide).
      **NO tocar** `UserStatus` (ya coincide con el backend). **NO tocar** páginas/hooks (swap = 0 cambios de consumidor).
- [ ] **Tests** — retirar/reescribir `tests/context/backoffice/user/InMemoryUserRepository.test.ts` (mock muerto); actualizar
      `tests/context/backoffice/user/schemas.test.ts` a los nuevos `Role`. Nuevos (espejando Banks):
      `tests/context/backoffice/user/infrastructure/ApiUserRepository.test.ts` + `ApiUserSearchNavigator.test.ts`
      (serialización de filtros/sort/limit, envelope-v2 aceptado / legacy rechazado, guard open-redirect).
- [ ] **e2e (candidato — ver D4)** — `tests/e2e/backoffice/users-real-api.spec.ts` list+detail read (espeja la porción
      list/detail de `banks-real-api.spec.ts` + fixture). Login como ADMIN (el seed dev/test existe). **NO** assertear
      conteos exactos (contaminación de DB dev compartida). Alcance U-0 = **solo lectura** (el ciclo `invite→changeStatus→
      reflejo` es U-3).
- [ ] Gate: `make pwa.quality` (ESLint + Prettier) + `make pwa.test.unit`.

### Verificación de cierre (Working principle 4)

- [ ] `make php.stan` (cada fichero PHP), `make php.quality`, `make php.deptrac`, `make php.lint.bounded-context`,
      `make php.lint.error-contract`, `make pwa.quality`, `make php.behat`, `make pwa.test`.
- [ ] Check en vivo: `curl -k` autenticado como ADMIN a `/api/v1/backoffice/users` (204/200 + envelope) y check visual del
      navegador en `/backoffice/users` (lista + detalle) — no downgradear a curl-only si salta `ERR_CERT_AUTHORITY_INVALID`
      (aceptar el cert manual y reintentar).

---

## Dev Notes

### Ficheros a tocar (estado actual verificado en `main @ 3bb35964`)

**A — RBAC (`api/`):**

| Fichero | Acción |
|---|---|
| `api/src/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicy.php` | **UPDATE** — `TIER_OPT_OUT` (línea ~60) + `EXPLICIT_GRANTS` (líneas ~50-53) |
| `api/tests/Unit/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicyTest.php` | **UPDATE** — casos `users.*` |
| `api/tests/Functional/…/Security/PermissionVoterAccessDecisionTest.php` | **UPDATE** — `users.read` ADMIN vs tier |

**B — API read-side (`api/src/Iam/Identity/`, todo NEW salvo aviso):**
`Infrastructure/Security/UsersPermission.php` · `Domain/Projection/UserRow.php` · `Domain/Repository/UserSearchRepository.php` ·
`Infrastructure/Persistence/Doctrine/DoctrineUserSearchRepository.php` (o **UPDATE** `DoctrineUserRepository.php` +
`#[AsAlias]`) · `Application/UserSearcher.php` · `Application/Query/SearchUsersQuery.php` · `Application/UserFinder.php` ·
`Application/Resource/UserListResource.php` + `UserDetailResource.php` · `Infrastructure/Http/UserResourceMapper.php` ·
`Infrastructure/Controller/UserSearchController.php` + `UserGetController.php`. **Reutiliza (no crear):** `Domain/Repository/
UserRepository.php` (`findById`), `Domain/Exception/UserNotFound.php`, `Domain/Entity/User.php`, `Domain/Enum/{Role,
IdentityStatus}.php`, y todo `Shared/Search`/`Shared/Http`/`Shared/Uuid`.

**C — PWA (`pwa/src/`):**

| Fichero | Acción |
|---|---|
| `context/shared/http-client/infrastructure/ApiEndpoints.ts` | **UPDATE** — bloque `USERS` |
| `context/backoffice/user/infrastructure/ApiUserRepository.ts` | **NEW** |
| `context/backoffice/user/infrastructure/ApiUserSearchNavigator.ts` | **NEW** |
| `context/shared/dependency-injection/infrastructure/Container.ts` | **UPDATE** — 2 binds mock→real |
| `context/shared/access/domain/Role.ts` | **UPDATE** — valores backend |
| `app/backoffice/users/_lib/userLabels.ts` | **UPDATE** — `ROLE_LABEL` |
| `app/backoffice/users/_components/{UsersFilters,UserForm,RolesBadges}.tsx` | **UPDATE** — filtro fuera + rol revocab |
| `app/backoffice/users/_lib/{usersFilterSort,usersSearchCriteria}.ts` | **UPDATE** — quitar `role` |
| `app/backoffice/users/[id]/page.tsx` | **UPDATE** — quitar chips `permissions` |
| `context/backoffice/user/domain/User.ts` | **UPDATE** — quitar `permissions` |
| `context/shared/access/infrastructure/ui/DevSessionSwitcher.tsx` | **UPDATE** — rol revocab |
| `context/backoffice/user/infrastructure/{InMemoryUserRepository.ts,userSeed.ts}` | **DELETE** |

### El crux: Shape B (proyección) vs Shape A (entidad) — la hidratación JSON de `roles` (D1)

El epic manda **Shape B**: `SELECT NEW UserRow(u.id, u.email, u.status, u.roles, u.createdAt, u.updatedAt) FROM User u`,
single FROM sin JOIN. **Es la opción correcta por seguridad-por-construcción:** una proyección de columnas explícitas
**no puede** seleccionar `password_hash`/`failedAttempts`/`lockedUntil` (AC7) — el read-path es inmune a fugas por
accidente, mientras que paginar la entidad (Shape A) delega esa garantía al mapper. **El riesgo #1 es técnico:**
`identity_user.roles` es `#[ORM\Column(type: Types::JSON)]` (columna Postgres `json`, `list<string>`); hay que **verificar
que Doctrine hidrata esa columna JSON dentro de un `SELECT NEW`** como `array`. Pruébalo en `DoctrineUserSearchRepositoryTest`
contra Postgres real (precedente: `api/tests/Functional/Backoffice/BankAccount/DoctrineBankAccountCollectionSearchRepositoryTest.php`).
**Si no hidrata**, el fallback es **Shape A**: `->select('u')->from(User::class,'u')` → `Page<User>` y el `UserResourceMapper`
mapea `User::roles()` (que ya devuelve `list<Role>` limpio) → `.value`. Shape A pierde la garantía-por-construcción de AC7,
así que **compénsala con el golden test del detalle** que asserta que el wire no tiene `password_hash`. **Recomendación:**
intentar Shape B primero (contrato + seguridad); caer a Shape A solo si la hidratación falla. **Presenta la decisión final al
usuario si caes a Shape A** (es una desviación del contrato del epic).

### El crux: el opt-out hace el trabajo; los grants `[ADMIN]` son redundantes-pero-documentados (SI-17)

`StaticAuthorizationPolicy::permits()` concede si: (1) el subject es ADMIN (wildcard `*`, **corre primero**), o (2) tier no
opted-out + acción en un verbo de tier, o (3) grant explícito. Como ADMIN ya pasa por (1), **listar `users.* → [ADMIN]` es
funcionalmente redundante** — pero el addendum SI-17 lo exige **a propósito**: documenta qué acciones existen y localiza el
seam a un futuro lector no-ADMIN en **una línea de datos**, no un refactor. **No lo "optimices" borrándolo.** Lo que de
verdad enforce ADMIN-only es el **opt-out** (`users` en `TIER_OPT_OUT`): sin él, `users.read` se auto-concede a `VIEWER`
porque `read` es verbo de tier. Precedente exacto: `auditTrail` (opted-out, grant `auditTrail.read → AUDIT_READER`).

### El crux: recurso `users` PLURAL, byte-idéntico API↔PWA (SI-20)

El recurso es **`users`** (el plano/consola de identidades), **no `user`**. Strings canónicas camelCase:
`users.read` · `users.invite` · `users.changeStatus` · `users.erase`. El PWA ya tiene `Permission.USERS_READ = "users.read"`.
**Error a prevenir:** escribir `user.read` (singular) en el backend por analogía con `bank.read` — rompería SI-20 en silencio
(ningún compilador lo atrapa). El validador `Permission::isWellFormed` acepta ambos, así que el test de byte-identidad es la
red. En U-0 solo `users.read` se compara (los otros tres son data de policy sin consumidor de código todavía).

### El crux: sin capa de adaptador en el PWA (D2 — desviación argumentada del inventario del epic)

El epic FR3 lista «adapters `UserCrudRepository`/`UserResourceNavigator`» por analogía con Banks. **Pero eso es ceremonia aquí:**
Banks necesita adaptadores porque su puerto `BankRepository` devuelve una forma bespoke `{banks}` que hay que remapear a
`{items}`. El puerto de users **ya es genérico**: `type UserRepository = CrudRepository<User, UserInput>` y las páginas
consumen directamente `"BackOfficeUserRepository"` / `"BackOfficeUserSearchNavigator"`. Así que `ApiUserRepository implements
CrudRepository<User,UserInput>` se **bindea directo** a la clave (devuelve `{items}` sin remap), **sin** `UserCrudRepository`/
`UserResourceNavigator`/`userResourcePage.ts`. **Principio:** SRP/YAGNI (Regla de Tres) — un adaptador que solo re-expone la
misma forma no compra nada. **Objetivo:** menos superficie, mismo «cero cambios de consumidor». **Coste/alternativa descartada:**
crear los adaptadores «para igualar Banks» = 3 clases de puro passthrough. *(Confirmar con el usuario que la desviación del
inventario literal del epic es aceptada — el AC5 habla de comportamiento, no de nº de clases.)*

### El crux: `<Can>` deniega todo hasta U-1 → U-0 se sostiene server-side

`/me` devuelve `permissions:[]` hoy → `<Can>` **oculta todos los botones de acción** (New/Edit/Delete) en la app real (solo
aparecen en tests/dev con permisos mockeados). Consecuencia doble: (1) la **lectura** funciona porque el enforcement es
server-side (`#[IsGranted('users.read')]` → 403 → `AsyncBoundary`), no depende de `<Can>`; (2) los stubs
`create/update/delete` **«no soportado»** son **inalcanzables por UI** en U-0 (los botones están ocultos) — son puro
type-safety del puerto `CrudRepository`, que exige esos métodos. El gateo de cliente lo enciende U-1.

### Persistencia (state-oriented — decidido, no re-abrir)

El read-side es una **lectura state-oriented sobre `identity_user`** (snapshot actual del padrón), consistente con Bank. **No
es event-sourcing** (no hay proyector, no es un ledger). No se toca la escritura ni el aggregate `User` (que **no** es
`AuditedEntity` — mantiene `password_hash` fuera del audit trail; respétalo). El `ActiveAdministratorDirectory` (puerto sin
adaptador Doctrine todavía) es de **U-3** (guard ≥1 ADMIN en changeStatus), **no** de U-0 — su adaptador hace un
`JOIN membership ⋈ identity_user` (cross-context) que aquí no toca.

### Testing (patrones del repo — Bank/BankAccount + Banks-PWA son los precedentes frescos)

- **Behat backend:** `api/features/backoffice/bank/search.feature` (contrato exhaustivo de lista: envelope, límite default
  25 / techo 100, next/prev, `invalid-cursor` 422, filtros eq/in/contains, sort allow-list, `unknown-search-field` 422,
  presupuesto `N requests got executed` = anti-N+1) + `get.feature` (200/400 invalid-uuid/404) +
  `features/backoffice/audit/access_control.feature` (plantilla **opt-out**: 401 anón, 403 tier, 200 grant). Convención:
  `features/<contexto>/<módulo>/<verbo>.feature`. **Verifica bajo qué prefijo cae `/api/v1/backoffice/users` antes de escribir
  el `Given` de la URL.**
- **PHPUnit:** Unit espeja el path del SUT, `#[CoversClass]`, `final class …Test`. Contrato de proyección:
  `BankAccountCollectionRowContractTest`. Functional (`WebTestCase` + `AuthenticatesFunctionalRequests`, Postgres real,
  `#[CoversNothing]`): cursor `BankSearchCursorFunctionalTest`, golden `BankDetailResponseGoldenFunctionalTest`, repo-proyección
  `DoctrineBankAccountCollectionSearchRepositoryTest`.
- **PWA:** `tests/context/backoffice/bank/infrastructure/ApiBankRepository.test.ts` (fake `httpClientReturning`, envelope-v2
  aceptado / legacy rechazado, límite clamp 100) + `ApiBankSearchNavigator.test.ts` (link relativo verbatim + guard
  open-redirect). e2e real: `tests/e2e/backoffice/banks-real-api.spec.ts` + `tests/e2e/fixtures/banks-real-api.ts`
  (`authenticatedTest`, `uniqueRunPrefix`, seed/cleanup).

### Gotchas heredados (verificados en épicas previas — evita re-tropezar)

- **Keyset + proyección:** usa el `SELECT NEW UserRow(...)` **completo**; un `SELECT u, b.col AS x` mixto **rompe el
  cursor** (memoria `keyset-engine-join-projection`). Single FROM sin JOIN → deptrac/bounded-context verdes sin allowlist.
- **DB dev compartida:** los e2e/functional locales **no** deben assertear conteos exactos — la DB dev acumula filas
  (memoria `local-dev-db-pollution-real-api-e2e`); en CI la DB es fresca. Assertea presencia/forma, no `count == N`.
- **Behat presupuesto de queries:** un write envuelto suma +2 (BEGIN/COMMIT); U-0 es read-only, así que el `N requests`
  aplica a la lectura — vigila el anti-N+1 (single FROM lo garantiza).
- **PHPMD:** `CouplingBetweenObjects` (≤13) aplica también a clases de TEST; mantén los tests de contrato magros (stubs a un
  trait si hace falta). `php.quality` es lo único que corre PHPMD/cs-fixer (puede OOM 137).
- **Worktree e2e:** si corres e2e en un worktree, `PLAYWRIGHT_BASE_URL`/`_API_BASE_URL` al `docker compose port php 443` del
  worktree; EACCES en `.next-e2e` → `rm -rf pwa/.next-e2e`.
- **PHPStan/Psalm/Rector:** correr **ambos** stan+psalm; Rector puede imponer `assertNotInstanceOf`/renombres — no
  `@psalm-suppress` (memoria `rector-over-psalm-no-suppress`).

### Decisiones a confirmar al inicio del dev (flagged — recomendaciones)

- **D1 · Shape B vs Shape A** (medio) — recomiendo **Shape B** (proyección, seguridad-por-construcción + contrato); fallback
  **Shape A** solo si la hidratación JSON de `roles` en `SELECT NEW` falla. Si caes a A, **preséntalo al usuario**.
- **D2 · Capa de adaptador PWA** (bajo) — recomiendo **ninguna** (puerto ya genérico; YAGNI). Desvía del inventario literal
  del epic; el AC5 es de comportamiento.
- **D3 · Partir la historia** (planificación — es del usuario) — el epic marca U-0 «partible». Recomiendo **mantenerla como
  una historia** con grupos A/B/C (las costuras ya son limpiamente paralelizables en dev). Alternativa: `1.1a` backend +
  `1.1b` PWA (dos claves en `sprint-status.yaml`). **No lo decidas tú.**
- **D4 · Alcance e2e** (bajo) — recomiendo un e2e de **solo lectura** (list+detail) en U-0; el ciclo completo
  `invite→changeStatus→reflejo` es el e2e de U-3.
- **D5 · Índices btree** (bajo) — recomiendo **diferir** (tabla diminuta) y decidir por `EXPLAIN`; si el plan lo pide,
  migración nueva vía `make db.diff`.
- **D6 · Ruta/prefijo** (bajo) — recomiendo `#[Route('/backoffice/users')]` en `Iam/Identity/Infrastructure/Controller/`
  (código en Iam por NFR8, wire-path en `/backoffice` por la consola) → `/api/v1/backoffice/users`. **Verifícalo con
  `debug:router` antes del test.**

### Fuera de alcance (frontera explícita — no lo hagas en U-0)

`/me` deriva permisos + `<Can>` funcional (**U-1**) · invitar / form de invitación / rename `users.write→users.invite`
(**U-2**) · `PATCH /backoffice/users/{id}/status` + `ChangeUserStatus` + adaptador Doctrine de `ActiveAdministratorDirectory`
+ rename `users.delete→users.changeStatus` (**U-3**) · edición de roles `ChangeUserRoles` (**U-4**) · borrado GDPR + #376
(**U-5**) · **re-add del filtro por rol** (diferido: `Shared/Search` no tiene operador JSONB containment; flip = necesidad
real de segmentar cientos por rol) · tenancy / `Membership.roles` como fuente de roles (diferido a su ADR).

### Project Structure Notes

- El read-slice **vive en `Iam/Identity`** (NFR8 — no crea módulo nuevo, reusa sus capas Domain/Application/Infrastructure).
  Ninguna referencia cruza contexto (single FROM `User`) → sin entrada en `api/.bounded-context-allowlist` ni
  `skip_violations` (contraste con el JOIN a `Bank` de la proyección de BankAccount).
- Controladores → `Infrastructure/Controller/`; mappers/glue HTTP → `Infrastructure/Http/`; proyección → `Domain/Projection/`;
  puerto de búsqueda → `Domain/Repository/`; DTOs de vista → `Application/Resource/`. Autowiring PSR-4 de `services.yaml` cubre
  todo salvo el `#[AsAlias(UserSearchRepository::class)]` en el adaptador Doctrine.
- PWA: repo/navigator reales → `context/backoffice/user/infrastructure/`; el puerto genérico y el toolkit
  (`useResourceList`/`DataTable`/`AsyncBoundary`/`<Can>`) viven en `context/shared/*` y **no se tocan**.

### References

- [`_bmad-output/planning-artifacts/epics-users-admin.md`](../planning-artifacts/epics-users-admin.md) — Story 1.1 (líneas ~299-341), FR1/FR2/FR3, NFR1/NFR5/NFR7/NFR8/NFR9/NFR11, UX-DR1/UX-DR2, SI-16/SI-17/SI-20.
- [`_bmad-output/planning-artifacts/arch-addendum-users-admin.md`](../planning-artifacts/arch-addendum-users-admin.md) — SI-16…SI-20, fila U-0 de la tabla de localización, sección Riesgos (filtro-rol diferido, `/me` sin permisos, toolkit genérico).
- [`docs/adr/rbac-authorization-model.md`](../../docs/adr/rbac-authorization-model.md) — D5 (constantes de permiso por módulo), D9 (sin ABAC / subject no evaluado).
- [`docs/adr/identity-invitation-lifecycle.md`](../../docs/adr/identity-invitation-lifecycle.md) — `User` aggregate, `IdentityStatus`, `Role`.
- Referencia de implementación **API** — `api/src/Backoffice/Bank/Infrastructure/Controller/BankSearchController.php` + `BankGetController.php`, `api/src/Backoffice/BankAccount/Domain/Projection/BankAccountCollectionRow.php`, `api/src/Backoffice/BankAccount/Infrastructure/Persistence/Doctrine/DoctrineBankAccountCollectionSearchRepository.php`, `api/src/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicy.php`, `api/src/Iam/Identity/Domain/Entity/User.php`.
- Referencia de implementación **PWA** — `pwa/src/context/backoffice/bank/infrastructure/ApiBankRepository.ts` + `ApiBankSearchNavigator.ts`, `pwa/src/context/shared/http-client/infrastructure/ApiEndpoints.ts`, `pwa/src/context/shared/dependency-injection/infrastructure/Container.ts`.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

### Change Log

### Review Findings
