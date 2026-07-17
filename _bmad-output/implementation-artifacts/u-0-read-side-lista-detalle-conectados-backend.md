---
baseline_commit: 3bb35964
---
# Story 1.1 (U-0): Read-side de identidades — lista + detalle conectados al backend real

Status: done

<!-- Validación opcional. Ejecuta `bmad-create-story` validate para un chequeo de calidad antes de dev-story. -->

## Story

Como **ADMIN**,
quiero **ver el padrón de usuarios (lista paginada + detalle) desde la consola `/backoffice/users` conectada al backend real**,
para **administrar identidades sobre datos reales en vez del mock in-memory**.

## Contexto (leer antes de tocar código)

Esta es **U-0 (PR-1)** de la épica `users-admin` (orden de merge safe-first
`U-0 → U-1 → (U-2 · U-3) → U-4 · [U-5a → U-5b]`). Es la **primera** historia. Es **aditiva**: read-only, no cambia
ningún comportamiento existente, ninguna historia posterior depende de ella en su orden de merge invertido.

El hueco que cubre: la consola PWA `pwa/src/app/backoffice/users` es hoy un `InMemoryUserRepository` + `userSeed`
sobre el puerto genérico `CrudRepository` — **desconectada del backend**, con un `Role` inventado
(`SUPER_ADMIN/EMPLOYEE/CUSTOMER/SUPPLIER`) que **no existe** en el dominio. El backend `Iam/Identity` es
**invitation-based, no CRUD** y **no tiene endpoints de lista/detalle** (hay que construir el read-side). U-0 tiene
tres costuras:

- **A · Auth-data (RBAC):** `users` → `TIER_OPT_OUT` + `users.{read,invite,changeStatus,erase}` → `[ADMIN]` en
  `StaticAuthorizationPolicy` (edición de **datos**, el tripwire `token_get_all` sigue verde).
- **B · Read-side de identidades (API):** proyección `UserRow`, `GET /backoffice/users` (keyset) +
  `GET /backoffice/users/{id}`, ambos `#[IsGranted('users.read')]`, con Resource DTOs por-vista y el envelope-v2 que ya
  usa Banks.
- **C · Conexión PWA ↔ backend (front):** `ApiUserRepository`(search+find) + navigator, swap de los 2 binds mock→real
  **sin cambios de consumidor**, `Role` alineado al backend, filtro de rol eliminado, `create/update/delete` → stub
  tipado *no-soportado*.

> **A + B son backend; C es frontend.** Se entrega en **dos PRs** (ver *Estrategia de entrega*): PR-A backend (aditivo,
> mergeable solo) y PR-B frontend (depende de PR-A vivo para el e2e). A y C comparten **solo el contrato de wire**
> (`{data[], pagination{…}}` + shape de fila) → son **paralelizables** en dev con ese contrato como interfaz.

**Reutiliza, no reinventes** — todo el andamiaje ya existe y está verificado en `main @ 3bb35964`. Contratos y
precedentes a **consumir** (no recrear):

- **Read-side backend:** `DoctrineSearchEngine`, `SearchFieldMap`/`SortFieldMap`/`FieldMapping`, `SearchQuery`
  (`#[MapQueryString]`), `SearchResponder` (compone envelope + `links`), `ResourceResponder` (detalle), `PaginationMeta`,
  `CursorCodec` (cursores opacos HMAC), `Uuid::ensure()` — todo `Shared/Search` / `Shared/Http` / `Shared/Uuid`. El
  patrón de proyección con `SELECT NEW` es **`BankAccountCollectionRow`**; el de lista/detalle es **`Bank`** (ver
  *References*).
- **RBAC:** `StaticAuthorizationPolicy` es data-only con tripwire; el precedente exacto de recurso opted-out es
  **`auditTrail`**.
- **Conexión PWA:** **`ApiBankRepository`/`ApiBankSearchNavigator`/`ApiEndpoints.BANKS`/`ResponseGuard`** son la
  plantilla. **El toolkit genérico (`useResourceList`/`useResourceItem`/`DataTable`/`AsyncBoundary`/`MutationError`/`<Can>`)
  no se toca** — los consumidores dependen solo de las claves DI + puertos genéricos.

Fuente de verdad del diseño (**no re-abrir, ya ratificado**):
[`_bmad-output/planning-artifacts/epics-users-admin.md`](../planning-artifacts/epics-users-admin.md) — **Story 1.1, FR1/FR2/FR3, NFR1-11, UX-DR1/UX-DR2, SI-16/SI-17/SI-20** ·
[`_bmad-output/planning-artifacts/arch-addendum-users-admin.md`](../planning-artifacts/arch-addendum-users-admin.md) — **SI-16…SI-20 + fila U-0 de la tabla de localización + Riesgos** ·
[`docs/adr/rbac-authorization-model.md`](../../docs/adr/rbac-authorization-model.md) — **D5 (constantes de permiso por módulo), D9 (sin ABAC)** ·
[`docs/adr/identity-invitation-lifecycle.md`](../../docs/adr/identity-invitation-lifecycle.md) — el `User` aggregate + `IdentityStatus`/`Role`.

**La frase que gobierna U-0:** el read-side de identidades es una **lectura state-oriented sobre `identity_user`** —
proyección de columnas explícitas (nunca la entidad), keyset (nunca OFFSET), gateada `users.read` ADMIN-only por
**opt-out** — y la consola PWA **conmuta el mock por HTTP real vía DI sin que ningún consumidor cambie**. El gateo de
**cliente** (`<Can>`) sigue **muerto hasta U-1** (`/me` devuelve `permissions:[]`), así que U-0 se sostiene **solo con
enforcement server-side** (`#[IsGranted]` → `403` → `AsyncBoundary`), correcto para una consola ADMIN-only.

### Estrategia de entrega — una historia, dos PRs

Una sola historia (esta), entregada en **dos PRs** para mantenerlos pequeños:

- **PR-A · backend (costuras A + B):** RBAC auth-data + read-side API. **Aditivo, mergeable solo** (no cambia
  comportamiento existente). Gates: `php.stan`/`php.quality`/`php.deptrac`/`php.lint.*` + Behat + PHPUnit.
- **PR-B · frontend (costura C):** conexión PWA + revocab `Role` + filtro fuera. **Depende de PR-A en `main`/vivo**
  (necesita el endpoint real para el e2e). Gate: `pwa.quality` + vitest + e2e.

El contrato de wire (`{data[], pagination{…}}` + shape de fila) es la **interfaz** entre ambos. La topología concreta de
ramas se **reconfirma al arrancar el dev** (gate de ramas CLAUDE.md).

---

## Acceptance Criteria

Los AC se redactan como **invariantes verificables** (comportamiento observable enganchado al addendum SI-16…SI-20 + las
FR), de modo que una refactorización futura no pueda romper una garantía sin que un test la detecte. La *mecánica* que los
cumple (proyección `SELECT NEW`, single-FROM, sin-JOIN) es implementación → *Dev Notes*, no AC. AC1-2 = costura A; AC3-4 =
costura B; AC5 = costura C; AC6-7 = transversales.

1. **(A · FR2 · SI-17 — opt-out + grants, data-only)** En `StaticAuthorizationPolicy`: `users` ∈ `TIER_OPT_OUT` y
   `users.read`, `users.invite`, `users.changeStatus`, `users.erase` ∈ `EXPLICIT_GRANTS → [Role::ADMIN->value]`. El
   tripwire `StaticAuthorizationPolicyIsDataOnlyTest` **sigue verde** (solo datos, ningún token ejecutable). **El opt-out
   es lo que hace el trabajo** — sin él, `users.read` se auto-concedería a `VIEWER` (`read` es verbo de tier). Los 3 grants
   sin endpoint todavía (`invite`/`changeStatus`/`erase`) se registran ya como el **seam de autorización documentado**
   (SI-17) — es **deuda deliberada, NO «unused»** (ver crux). Un test prueba: ADMIN concede las 4; `VIEWER`/`EDITOR`/
   `MANAGER`/`[]` **niegan** `users.read` (contraste opt-out); regresión: `bank.read` **sigue** tiereando a `VIEWER`.

2. **(A · SI-17 — 403/401)** Dado un usuario `VIEWER`/`EDITOR`/`MANAGER` (no ADMIN), `GET /backoffice/users` → **403**
   (`forbidden`, RFC 9457); anónimo → **401**. Un test cubre ambos por ruta (lista y detalle).

3. **(B · FR1 · NFR5 — lista keyset)** Dado un ADMIN, `GET /backoffice/users` → un `Page` keyset con **envelope-v2**
   (`{data[], pagination{hasNext,hasPrev,count,links{next,prev}}}`, cursores **opacos** dentro de `links`, nunca escalar
   top-level) de `UserListResource`; filtros **`email`(Contains) + `status`(Eq)**; **`roles` NO es filtrable** (ausente del
   `SearchFieldMap`); sort **`email`/`status`/`createdAt`/`updatedAt`** con tie-break **`id`**; **presupuesto de queries: 1
   consulta, sin N+1** (Behat `N requests got executed`); el **`.value` del enum viaja en el wire** (`ACTIVE`,
   `["ADMIN","VIEWER"]`), nunca una etiqueta traducida (SI-16).

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

7. **(Transversal · seguridad — la entidad nunca se serializa)** El `User` (entity) **nunca** llega al serializer. **Dos
   guardias, no solo el golden:**
   - **(a) Estructural** — un test fija por **tipo/reflexión** que `UserResourceMapper` retorna Resource DTOs y que los
     controladores pasan **DTOs (no la entidad)** al `Responder`. Resiste cambios de formato (no depende del snapshot).
   - **(b) Golden del detalle** — el key-set de la respuesta es **exactamente** `{id,email,status,roles,createdAt,updatedAt}`,
     sin `password_hash`/`failedAttempts`/`lockedUntil`/`permissions`.

   La proyección `UserRow` además **excluye los campos sensibles por construcción** (no los selecciona).

---

## Tasks / Subtasks

> Contratos + restricciones + punteros a la referencia (no un diff línea a línea). **A antes que B** (B testea el 403 que A
> habilita). **PR-A (A+B) antes que PR-B (C)**.

### A — Auth-data RBAC · `api/src/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicy.php` (AC1, AC2)

- [x] Añadir `'users'` a `TIER_OPT_OUT` (hoy `['auditTrail']`).
- [x] Añadir las 4 filas `'users.<action>' => [Role::ADMIN->value]` a `EXPLICIT_GRANTS` — **solo `Role::ADMIN->value` +
      strings literales** (el tripwire prohíbe `match`/`fn`/`new`/`(`/`?`). Un **docblock breve** en el sitio explica que los
      3 sin endpoint son el seam documentado (SI-17), no código muerto → que un reviewer posterior no los cull.
- [x] Ampliar `StaticAuthorizationPolicyTest` (espeja el caso `auditTrail`): ADMIN concede las 4; VIEWER/EDITOR/MANAGER/`[]`
      niegan `users.read`; regresión `bank.read` sigue tiereando a VIEWER. Y `PermissionVoterAccessDecisionTest` (functional):
      `users.read` ADMIN vs tier. (Los casos de política de `users` viven en la clase dedicada
      `StaticAuthorizationPolicyUsersResourceTest` porque la existente estaba en el tope de métodos públicos de PHPMD.)
- [x] **NO** tocar el tripwire `StaticAuthorizationPolicyIsDataOnlyTest` (queda verde solo). **NO** nombrar nada `invoice`
      (centinela OCP de `AuthorizationCoreIsClosedForModificationTest`).

### B — API read-side · `api/src/Iam/Identity/` (AC3, AC4, AC6, AC7)

- [x] **Gateo** — `#[IsGranted('users.read')]` (**literal**) en ambos controladores. **No** crear una clase `UsersPermission`
      para una sola acción (mismo patrón que `auditTrail.read`, que usa el literal); la clase de constantes aterriza cuando
      U-2/U-3 añaden la 2ª acción. ⚠️ Recurso **`users` PLURAL** — no `user.read` singular (SI-20).
- [x] **Proyección** — `Domain/Projection/UserRow.php` (readonly, ctor promocionado: `id`, `email`, `roles` `list<string>`,
      `status` `IdentityStatus`, `createdAt`, `updatedAt`). `createdAt`/`updatedAt` **públicos** (el `CursorPositionExtractor`
      los lee por property-path). Ver `BankAccountCollectionRow` como patrón exacto (docblock incluido).
- [x] **Puerto + adaptador de búsqueda** — `Domain/Repository/UserSearchRepository.php` (`search(SearchCriteria): Page<UserRow>`)
      + su adaptador Doctrine (sibling nuevo, o sobre `DoctrineUserRepository` con `#[AsAlias]`): DQL `SELECT NEW UserRow(...)`
      **single FROM `User`, sin JOIN**, delegando en `DoctrineSearchEngine::paginate(...)`. **Field maps:** filtrable =
      `email`(Contains) + `status`(Eq); **`roles` fuera del map** (no filtrable). Sortable = `email`/`status`/`createdAt`/
      `updatedAt` (el `id` tie-break lo añade el engine). Espeja `DoctrineBankAccountCollectionSearchRepository`.
- [x] **Handler + finder** — `Application/UserSearcher.php` + `Application/Query/SearchUsersQuery.php` (espeja `BankSearcher`);
      `Application/UserFinder.php` para el detalle: `Uuid::ensure($id)` → **reusa** `UserRepository::findById` y la excepción
      `UserNotFound` ya existentes (no crear repos nuevos para el detalle).
- [x] **Resource DTOs por-vista + mapper** — `UserListResource` + `UserDetailResource` (`status`/`roles` = `.value`,
      timestamps ATOM; **detalle SIN `permissions`**) + `UserResourceMapper` (`toListPage` + `toDetailResource`). **Hoy los
      dos DTOs coinciden; se mantienen separados por estabilidad de contrato, NO fusionar** (un docblock en cada uno lo dice;
      divergen cuando el detalle hospede metadata de acciones en U-3). **La entidad `User` NUNCA se serializa** (AC7).
- [x] **Controladores** — `Infrastructure/Controller/UserSearchController.php` (`GET`, `#[MapQueryString] SearchQuery`,
      `SearchResponder`) + `UserGetController.php` (`GET /{id}`, `ResourceResponder`), ambos gateados. ⚠️ **Verificar** que la
      ruta resuelve a **`/api/v1/backoffice/users`** (`debug:router`) antes de escribir el `Given` del Behat — ver *Verificaciones*.
- [x] **Behat + PHPUnit** — Behat `users/search.feature` + `get.feature` + `access_control.feature` (espeja bank + la
      plantilla opt-out de audit). PHPUnit Unit: `UserSearcherTest`, `UserFinderTest`, `UserRowContractTest`,
      `UserResourceMapperTest`. Functional (Postgres real): `UserSearchCursorFunctionalTest`, `UserDetailResponseGoldenFunctionalTest`
      (AC7-b), un **test estructural** de AC7-a, y `DoctrineUserSearchRepositoryTest` (fija la hidratación de `roles` como
      regresión — ver crux).
- [x] Gates: `make php.stan` por fichero → `make php.quality` → `make php.deptrac` → `make php.lint.bounded-context`
      → `make php.lint.error-contract`.

### C — Conexión PWA · `pwa/src/` (AC5, AC6)

- [x] **Endpoints** — bloque `USERS` en `ApiEndpoints` (helper `userPath(id)` con `encodeURIComponent`, bajo `BACKOFFICE_PREFIX`
      → `/api/v1/backoffice/users`).
- [x] **Repo + navigator reales** — `ApiUserRepository implements CrudRepository<User, UserInput>` (**SIN capa de adaptador**
      — el puerto ya es genérico; ver crux) con `search`/`find` vía `HttpClient` + `ResponseGuard` (guards + `toUserSearchPage`
      devolviendo `{items}`, espejando `ApiBankRepository`) y `create/update/delete` → `throw` tipado «no soportado»;
      `ApiUserSearchNavigator implements ResourceSearchNavigator<User>` (`follow` con guard same-origin `safeHref`).
- [x] **DI swap** — en `Container.ts`, los 2 binds `.toConstantValue(mock)` → `.to(Api...).inSingletonScope()`; borrar la
      construcción del mock + imports. Páginas/hooks intactos (0 cambios de consumidor).
- [x] **Revocabulario `Role`** — `Role.ts` → `VIEWER/EDITOR/MANAGER/ADMIN/AUDIT_READER`. Ripple: `ROLE_LABEL` (`userLabels.ts`,
      `Record<Role,string>` exhaustivo = guardrail de compilación), `UserForm` (checkboxes), `RolesBadges`, `DevSessionSwitcher`.
      Borrar `userSeed.ts` (mock retirado).
- [x] **Quitar filtro de rol** — el `<select users-filters__role>` en `UsersFilters` + el campo `role` en `usersFilterSort`/
      `usersSearchCriteria`. Quedan email + status.
- [x] **Detalle sin `permissions`** — quitar `permissions` de `User.ts` (`UserPrimitives`) y de los chips en `[id]/page.tsx`
      (UX-DR2 — el real no lo devuelve). El form/`UserInput.permissions` son **transitorios** (→ invite/changeStatus en
      U-2/U-3, ocultos por `<Can>`): **mínima cirugía para que compile**, no invertir.
- [x] **NO tocar** el enum `Permission` (`users.write`/`users.delete` se renombran en U-2/U-3; `users.read` ya coincide),
      `UserStatus` (ya coincide), ni páginas/hooks.
- [x] **Tests** — retirar/reescribir `InMemoryUserRepository.test.ts` (mock muerto); actualizar `schemas.test.ts` a los nuevos
      `Role`; nuevos `ApiUserRepository.test.ts` + `ApiUserSearchNavigator.test.ts` (espejan Banks: filtros/sort/limit,
      envelope-v2 aceptado / legacy rechazado, guard open-redirect). e2e `users-real-api.spec.ts` **solo lectura** (list+detail,
      login ADMIN con el seed dev/test; **NO** conteos exactos — DB dev compartida).
- [x] Gate: `make pwa.quality` + `make pwa.test.unit`.

### Verificaciones (Working principle 4)

- [x] `make php.stan` (cada fichero), `make php.quality`, `make php.deptrac`, `make php.lint.bounded-context`,
      `make php.lint.error-contract`, `make pwa.quality`, `make php.behat`, `make pwa.test`.
- [x] `make sf c='debug:router'` → confirmar `/api/v1/backoffice/users` (los controladores en `Iam/Identity/Infrastructure/
      Controller/` reciben el prefijo `/api/v1` del recurso `api_v1_iam_identity`). ✓ resuelve a `backoffice_user_search` /
      `backoffice_user_get`.
- [x] Check en vivo (backend): `curl -k` autenticado como ADMIN → lista 200 + envelope-v2 (roles JSON→array, status enum,
      timestamps ATOM, cursor opaco en `links.next`, sin credenciales), detalle 200, id malformado 400 `invalid-uuid`,
      anónimo 401. El **check visual del navegador** en `/backoffice/users` es PR-B (la consola PWA aún usa el mock hasta la
      costura C).

---

## Dev Notes

### Ficheros a tocar (estado verificado en `main @ 3bb35964`)

**A — RBAC (`api/`):** `Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicy.php` (**UPDATE** — `TIER_OPT_OUT`
línea ~60 + `EXPLICIT_GRANTS` líneas ~50-53) · `tests/Unit/…/Security/StaticAuthorizationPolicyTest.php` (**UPDATE**) ·
`tests/Functional/…/Security/PermissionVoterAccessDecisionTest.php` (**UPDATE**).

**B — API read-side (`api/src/Iam/Identity/`, NEW salvo aviso):** `Domain/Projection/UserRow.php` ·
`Domain/Repository/UserSearchRepository.php` · `Infrastructure/Persistence/Doctrine/DoctrineUserSearchRepository.php`
(o **UPDATE** `DoctrineUserRepository.php` + `#[AsAlias]`) · `Application/UserSearcher.php` ·
`Application/Query/SearchUsersQuery.php` · `Application/UserFinder.php` · `Application/Resource/UserListResource.php` +
`UserDetailResource.php` · `Infrastructure/Http/UserResourceMapper.php` · `Infrastructure/Controller/UserSearchController.php`
+ `UserGetController.php`. **Reutiliza (no crear):** `Domain/Repository/UserRepository.php` (`findById`),
`Domain/Exception/UserNotFound.php`, `Domain/Entity/User.php`, `Domain/Enum/{Role,IdentityStatus}.php`, y todo
`Shared/Search`/`Shared/Http`/`Shared/Uuid`.

**C — PWA (`pwa/src/`):** `context/shared/http-client/infrastructure/ApiEndpoints.ts` (**UPDATE** — bloque `USERS`) ·
`context/backoffice/user/infrastructure/ApiUserRepository.ts` + `ApiUserSearchNavigator.ts` (**NEW**) ·
`context/shared/dependency-injection/infrastructure/Container.ts` (**UPDATE** — 2 binds) ·
`context/shared/access/domain/Role.ts` + `app/backoffice/users/_lib/userLabels.ts` +
`app/backoffice/users/_components/{UsersFilters,UserForm,RolesBadges}.tsx` +
`app/backoffice/users/_lib/{usersFilterSort,usersSearchCriteria}.ts` + `app/backoffice/users/[id]/page.tsx` +
`context/backoffice/user/domain/User.ts` + `context/shared/access/infrastructure/ui/DevSessionSwitcher.tsx` (**UPDATE**) ·
`context/backoffice/user/infrastructure/{InMemoryUserRepository.ts,userSeed.ts}` (**DELETE**).

### El crux: proyección `UserRow` — Shape B (hidratación JSON verificada)

El read-side usa **Shape B**: `SELECT NEW UserRow(u.id, u.email, u.roles, u.status, u.createdAt, u.updatedAt) FROM User u`,
single FROM sin JOIN. Es la opción correcta por **seguridad-por-construcción**: una proyección de columnas explícitas **no
puede** seleccionar `password_hash`/`failedAttempts`/`lockedUntil` (AC7). **Verificado por spike (`main @ 3bb35964`,
Doctrine ORM 3.6.7 / DBAL 4.4.3, Postgres 18):** la columna JSON `roles` (`#[ORM\Column(type: Types::JSON)]`) **hidrata
dentro de `SELECT NEW` como PHP `array`** (`["AUDIT_READER","MANAGER"]`); `status` (enumType) y `createdAt`
(`DateTimeImmutable`) también, sobre un `SELECT … FROM identity_user` de un solo FROM. **No hay fallback: Shape B es la
única implementación.** El `DoctrineUserSearchRepositoryTest` (contra Postgres real) lo fija como regresión.

### El crux: `UserRow` vive en `Domain/Projection/` (dirección de dependencia · deptrac)

El puerto de lectura `UserSearchRepository` vive en `Domain/Repository/` y su firma es `Page<UserRow>` → el puerto
**importa** `UserRow` (arista AST que `deptrac` ve). El ruleset `*.domain` permite `Domain→Domain` pero **prohíbe
`Domain→Application`**. Poner `UserRow` en `Application/` ⇒ el puerto Domain importa Application ⇒ **`make php.deptrac`
FALLA** (salvo que muevas también el puerto → split-brain read/write ports). Y es la convención: **tres** proyecciones DQL
ya viven en `Domain/Projection` (`BankAccountCollectionRow`, `AuditTimelineEntry`, `AuditEventDetail`), cada una devuelta
por un puerto Domain. *(La "convención de dos hogares" — proyección síncrona `SELECT NEW` → `Domain/Projection`;
read-model materializado event-fed como `BankCountReadModel` → `Application/Projection` — podría codificarse en
[`docs/rules/read-side-projections.md`](../../docs/rules/read-side-projections.md); es un follow-up **opcional fuera de U-0**,
no parte de esta PR.)*

### El crux: el opt-out hace el trabajo; los 3 grants sin endpoint son deuda deliberada (SI-17)

`StaticAuthorizationPolicy::permits()` concede si: (1) el subject es ADMIN (wildcard `*`, **corre primero**), o (2) tier no
opted-out + acción en un verbo de tier, o (3) grant explícito. Como ADMIN ya pasa por (1), **listar `users.* → [ADMIN]` es
funcionalmente redundante** — pero SI-17 lo exige **a propósito**: los 3 grants sin endpoint todavía (`invite`/`changeStatus`/
`erase`) **documentan qué acciones existen** y localizan el seam en **una línea de datos**, no un refactor. **Deuda
deliberada — NO borrar como «unused»** (un docblock en el sitio lo dice). Lo que de verdad enforce ADMIN-only es el
**opt-out** (`users` en `TIER_OPT_OUT`): sin él, `users.read` se auto-concede a `VIEWER`. Precedente: `auditTrail`.

### El crux: recurso `users` PLURAL, byte-idéntico API↔PWA (SI-20)

El recurso es **`users`** (el plano/consola de identidades), **no `user`**. Strings camelCase:
`users.read`/`users.invite`/`users.changeStatus`/`users.erase`. El PWA ya tiene `Permission.USERS_READ = "users.read"`.
**Error a prevenir:** escribir `user.read` singular por analogía con `bank.read` — rompe SI-20 en silencio (`Permission::isWellFormed`
acepta ambos → el test de byte-identidad es la red). En U-0 solo `users.read` se compara.

### El crux: sin capa de adaptador en el PWA (D2 — desviación argumentada del inventario del epic)

El epic FR3 lista «adapters `UserCrudRepository`/`UserResourceNavigator`» por analogía con Banks. **Es ceremonia aquí:**
Banks los necesita porque su puerto devuelve una forma bespoke `{banks}` que hay que remapear a `{items}`. El puerto de
users **ya es genérico** (`type UserRepository = CrudRepository<User, UserInput>`) y las páginas consumen directamente
`"BackOfficeUserRepository"` / `"BackOfficeUserSearchNavigator"`. Así que `ApiUserRepository implements CrudRepository<...>`
se **bindea directo** (devuelve `{items}` sin remap). **Principio:** SRP/YAGNI — un adaptador que solo re-expone la misma
forma no compra nada. **Objetivo:** menos superficie, mismo «cero cambios de consumidor». (El AC5 habla de comportamiento,
no de nº de clases.)

### El crux: `<Can>` deniega todo hasta U-1 → U-0 se sostiene server-side

`/me` devuelve `permissions:[]` hoy → `<Can>` **oculta todos los botones de acción** en la app real (solo aparecen en
tests/dev con permisos mockeados). Consecuencia doble: (1) la **lectura** funciona por enforcement server-side
(`#[IsGranted]` → 403 → `AsyncBoundary`), no depende de `<Can>`; (2) los stubs `create/update/delete` **«no soportado»** son
**inalcanzables por UI** (los botones están ocultos) — puro type-safety del puerto `CrudRepository`. El gateo de cliente lo
enciende U-1.

### Persistencia (state-oriented — decidido, no re-abrir)

Lectura **state-oriented sobre `identity_user`** (snapshot del padrón), consistente con Bank. **No es event-sourcing.** No
se toca la escritura ni el aggregate `User` (que **no** es `AuditedEntity` — mantiene `password_hash` fuera del audit trail;
respétalo). El `ActiveAdministratorDirectory` (puerto sin adaptador Doctrine todavía) es de **U-3**, no de U-0.

### Testing (patrones del repo — Bank/BankAccount + Banks-PWA son los precedentes frescos)

- **Behat:** `api/features/backoffice/bank/search.feature` (envelope, límite 25/techo 100, next/prev, `invalid-cursor` 422,
  filtros eq/in/contains, sort allow-list, `unknown-search-field` 422, `N requests got executed` = anti-N+1) + `get.feature`
  (200/400 invalid-uuid/404) + `features/backoffice/audit/access_control.feature` (plantilla **opt-out**: 401/403/200).
  Convención `features/<contexto>/<módulo>/<verbo>.feature`.
- **PHPUnit:** Unit espeja el path del SUT, `#[CoversClass]`. Contrato de proyección: `BankAccountCollectionRowContractTest`.
  Functional (`WebTestCase` + `AuthenticatesFunctionalRequests`, Postgres real, `#[CoversNothing]`): cursor
  `BankSearchCursorFunctionalTest`, golden `BankDetailResponseGoldenFunctionalTest`, repo-proyección
  `DoctrineBankAccountCollectionSearchRepositoryTest`.
- **PWA:** `tests/context/backoffice/bank/infrastructure/ApiBankRepository.test.ts` + `ApiBankSearchNavigator.test.ts`. e2e
  real: `tests/e2e/backoffice/banks-real-api.spec.ts` + `tests/e2e/fixtures/banks-real-api.ts`.

### Gotchas heredados (verificados en épicas previas)

- **Keyset + proyección:** usa el `SELECT NEW UserRow(...)` **completo**; un `SELECT u, b.col AS x` mixto **rompe el
  cursor** (memoria `keyset-engine-join-projection`). Single FROM sin JOIN → deptrac/bounded-context verdes sin allowlist.
- **DB dev compartida:** e2e/functional locales **no** assertean conteos exactos (la DB dev acumula filas; CI es fresca).
- **Behat presupuesto:** un write envuelto suma +2 (BEGIN/COMMIT); U-0 es read-only → el `N requests` es de la lectura.
- **PHPMD:** `CouplingBetweenObjects` (≤13) aplica a clases de TEST; tests de contrato magros. `php.quality` corre
  PHPMD/cs-fixer (puede OOM 137).
- **PHPStan/Psalm/Rector:** correr **ambos** stan+psalm; no `@psalm-suppress` (memoria `rector-over-psalm-no-suppress`).

### Decisiones ya tomadas (con su argumento) + verificaciones al arrancar

**Tomadas — no re-abrir:**

- **Shape B (proyección)** — hidratación JSON verificada por spike; única implementación (sin fallback).
- **`UserRow` en `Domain/Projection/`** — dirección de dependencia (deptrac prohíbe `Domain→Application`); consistente con
  las 3 proyecciones existentes. *(Consultado repo-wide con arquitectura/dev + IA externa; Opción 1.)*
- **Sin capa de adaptador PWA** — puerto ya genérico; YAGNI.
- **Una historia, 2 PRs** — backend (A+B) primero, frontend (C) después.
- **Gateo con literal `#[IsGranted('users.read')]`** — sin clase de constantes hasta la 2ª acción (patrón `auditTrail`).

**A verificar al arrancar (bajo riesgo):**

- Ruta resuelve a `/api/v1/backoffice/users` — `debug:router` antes del Behat.
- Alcance e2e = **solo lectura** (list+detail); el ciclo `invite→changeStatus→reflejo` es U-3.

### Fuera de alcance (frontera explícita — no lo hagas en U-0)

`/me` deriva permisos + `<Can>` funcional (**U-1**) · invitar / rename `users.write→users.invite` (**U-2**) ·
`PATCH .../status` + `ChangeUserStatus` + adaptador Doctrine de `ActiveAdministratorDirectory` + rename
`users.delete→users.changeStatus` (**U-3**) · `ChangeUserRoles` (**U-4**) · borrado GDPR + #376 (**U-5**) · **re-add del
filtro por rol** (diferido: `Shared/Search` sin operador JSONB containment) · tenancy / `Membership.roles` como fuente de
roles · **índices btree** sobre columnas de sort (`status`/`createdAt`/`updatedAt`): tabla diminuta hoy (docenas, single-org)
→ **no es tarea de U-0**; si crece, medir por `EXPLAIN` y añadir migración (`identity_user(email)` ya es `UNIQUE`) · codificar
la convención de proyecciones en `docs/rules/read-side-projections.md` (follow-up opcional).

### Project Structure Notes

- El read-slice **vive en `Iam/Identity`** (NFR8 — no crea módulo nuevo). Ninguna referencia cruza contexto (single FROM
  `User`) → sin entrada en `api/.bounded-context-allowlist` ni `skip_violations` (contraste con el JOIN a `Bank` de la
  proyección de BankAccount).
- Controladores → `Infrastructure/Controller/`; glue HTTP → `Infrastructure/Http/`; proyección → `Domain/Projection/`;
  puerto de búsqueda → `Domain/Repository/`; DTOs de vista → `Application/Resource/`. Autowiring PSR-4 cubre todo salvo el
  `#[AsAlias(UserSearchRepository::class)]` en el adaptador Doctrine.
- PWA: repo/navigator reales → `context/backoffice/user/infrastructure/`; el puerto genérico y el toolkit viven en
  `context/shared/*` y **no se tocan**.

### References

- [`_bmad-output/planning-artifacts/epics-users-admin.md`](../planning-artifacts/epics-users-admin.md) — Story 1.1, FR1/FR2/FR3, NFR1/NFR5/NFR7/NFR8/NFR9/NFR11, UX-DR1/UX-DR2, SI-16/SI-17/SI-20.
- [`_bmad-output/planning-artifacts/arch-addendum-users-admin.md`](../planning-artifacts/arch-addendum-users-admin.md) — SI-16…SI-20, fila U-0 de localización, Riesgos.
- [`docs/adr/rbac-authorization-model.md`](../../docs/adr/rbac-authorization-model.md) — D5, D9.
- [`docs/adr/identity-invitation-lifecycle.md`](../../docs/adr/identity-invitation-lifecycle.md) — `User`, `IdentityStatus`, `Role`.
- [`docs/rules/read-side-projections.md`](../../docs/rules/read-side-projections.md) — proyecciones de lectura (Domain/Projection vs read-model materializado).
- Referencia **API** — `api/src/Backoffice/Bank/Infrastructure/Controller/BankSearchController.php` + `BankGetController.php`, `api/src/Backoffice/BankAccount/Domain/Projection/BankAccountCollectionRow.php`, `api/src/Backoffice/BankAccount/Infrastructure/Persistence/Doctrine/DoctrineBankAccountCollectionSearchRepository.php`, `api/src/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicy.php`, `api/src/Iam/Identity/Domain/Entity/User.php`.
- Referencia **PWA** — `pwa/src/context/backoffice/bank/infrastructure/ApiBankRepository.ts` + `ApiBankSearchNavigator.ts`, `pwa/src/context/shared/http-client/infrastructure/ApiEndpoints.ts`, `pwa/src/context/shared/dependency-injection/infrastructure/Container.ts`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context).

### Debug Log References

- `make sf c='debug:router'` → `backoffice_user_search` = `GET /api/v1/backoffice/users`, `backoffice_user_get` =
  `GET /api/v1/backoffice/users/{id}`.
- `make php.behat` (full) → 308 scenarios / 2803 steps green (verificado tras tocar `TestDebugDataHolder`).
- `make php.unit` (full) → 1951 tests / 8557 assertions green (2 notices pre-existentes en `main`, no de esta historia).
- `make php.quality`, `make php.lint.bounded-context`, `make php.lint.error-contract` → exit 0.
- Live: admin login 204 → `GET /api/v1/backoffice/users` 200 (envelope-v2 correcto), detalle 200, `not-a-uuid` 400,
  anónimo 401.

### Completion Notes List

**PR-A backend (costuras A + B)** mergeado en `main` (#501, `086624e9`). **PR-B frontend (costura C)** entregado abajo (#502)
→ la historia pasa a `review`.

**C · Conexión PWA ↔ backend (PR-B, #502).** La consola `/backoffice/users` conmuta el mock in-memory por HTTP real vía DI,
sin un solo cambio de consumidor (páginas/hooks/toolkit intactos):

- **`ApiUserRepository implements CrudRepository<User, UserInput>`** (sin capa de adaptador — el puerto ya es genérico):
  `search` serializa `filters + sort(dir uppercased) + limit(clamp 100)` y `find` pega a `/backoffice/users/{id}`, ambos con
  `ResponseGuard` que acepta el envelope-v2 y rechaza la forma legacy. `create/update/delete` lanzan un `HttpError` tipado
  **status 501** (`UserProblemType.NOT_SUPPORTED` + nuevo `HttpStatus.NOT_IMPLEMENTED`) — `HttpError`, no `Error` plano, para
  que `DeleteUserButton` (que re-lanza los no-`HttpError`) lo degrade a `MutationError` en vez de crashear; ruta hoy
  inalcanzable por `<Can>` (SI-18). **`ApiUserSearchNavigator implements ResourceSearchNavigator<User>`** (follow verbatim con
  guard same-origin `safeHref`). Bindeados directo en `Container.ts` bajo `"BackOfficeUserRepository"` /
  `"BackOfficeUserSearchNavigator"`; mock (`InMemoryUserRepository`) + `userSeed` + su test borrados.
- **`Role` alineado al backend** (`VIEWER/EDITOR/MANAGER/ADMIN/AUDIT_READER`, byte-idéntico a `Iam\Identity\Domain\Enum\Role`);
  `ROLE_LABEL` reescrito (guardrail `Record<Role,string>` exhaustivo). Ripple automático en `RolesBadges`/`UserForm`/
  `DevSessionSwitcher` (consumen `ALL_ROLES`/`ROLE_LABEL`). `schemas.test.ts` fija el vocabulario nuevo (acepta los 5, rechaza
  uno stale).
- **Filtro de rol quitado** (`roles` no es filtrable — fuera del `SearchFieldMap`): fuera el `<select>` de `UsersFilters` y el
  campo `role` de `UsersFilter`/`EMPTY_FILTER`/`hasActiveFilter`/`toUserFilters`; quedan email(contains) + status(eq).
- **Detalle sin `permissions`** (UX-DR2 — el read-model real no lo devuelve): `permissions` fuera de `UserPrimitives`/`User` y
  de los chips de `[id]/page.tsx`; el campo del form es transitorio (`UserFormInitial.permissions` → opcional, seed `[]`),
  cirugía mínima para compilar. Enum `Permission`, `UserStatus`, páginas y hooks intactos.
- **Verificado (fresco, worktree #502):** `tsc --noEmit` limpio · `make pwa.quality` exit 0 · `make pwa.test.unit` 1061/1061 ·
  e2e **read-only** `users-real-api.spec.ts` verde contra el stack vivo (list + filtro por email + detalle sin `permissions`;
  el `beforeAll` afirma envelope-v2 y que la fila no trae `password_hash`/`permissions`; sin conteos exactos).
- **Seguridad (checklist frontend):** open-redirect cubierto por `assertSameOriginRelative` (test dedicado: absoluta/
  protocol-relative/scheme peligroso rechazadas, sin red); `ResponseGuard` convierte drift/legacy en fallo tipado; ninguna
  credencial/PII en el wire (el guard tolera pero `User` descarta un campo extra; la proyección server-side es la defensa
  real); sin `dangerouslySetInnerHTML`, sin deps nuevas, headers/CSP intactos.

- **A · RBAC (data-only):** `users` → `TIER_OPT_OUT` + 4 grants `users.{read,invite,changeStatus,erase}` → `[ADMIN]` en
  `StaticAuthorizationPolicy`. El opt-out es lo que confina la consola a ADMIN (sin él, `read` se auto-concedería a VIEWER);
  los 3 grants sin endpoint son deuda deliberada documentada en el docblock. El tripwire data-only y el centinela OCP
  (`invoice`) siguen verdes intactos.
- **B · read-side:** proyección `UserRow` (`Domain/Projection`) + puerto `UserSearchRepository` + adaptador Doctrine
  `SELECT NEW UserRow(...)` single-FROM sin JOIN sobre el keyset engine; `UserSearcher`/`SearchUsersQuery`/`UserFinder`
  (reusa `UserRepository::findById` + `UserNotFound`); Resource DTOs por-vista (list/detail, hoy idénticos, separados por
  contrato) + `UserResourceMapper`; controladores `GET /backoffice/users` y `/{id}` gateados con el literal
  `#[IsGranted('users.read')]`. Filtros `email`(contains) + `status`(eq/in); `roles` **fuera** del map (no filtrable).
- **Shape B verificado en real-DB** (`DoctrineUserSearchRepositoryTest`): la columna JSON `roles` hidrata dentro de
  `SELECT NEW` como `list<string>`, `status` como enum `IdentityStatus`, timestamps como `DateTimeImmutable`; y el filtro
  `status` (string atado contra la columna enum) selecciona sólo ese estado. Sin fallback Shape A.
- **AC7 (la entidad nunca se serializa):** guardia estructural por reflexión
  (`UserEntityNeverReachesTheSerializerStructuralTest`) + golden HTTP del key-set
  (`UserDetailResponseGoldenFunctionalTest`) + la proyección excluye por construcción `password_hash`/`failedAttempts`/
  `lockedUntil`.
- **Cambio en infra de test compartida (necesario, anticipado por su propio docblock):** `TestDebugDataHolder`
  descartaba **por nombre de tabla** (`identity_user`) toda query como "auth plumbing"; U-0 es el primer read-side de
  negocio sobre esa tabla, así que ahora descarta por **call-site** (`UserProvider`), como ya hacía con el
  `SessionAdmissionGate`. Sin esto, el presupuesto de queries del Behat contaba 0 en la lista. Full Behat (308) revalidado
  sin regresiones. También `AuthenticatesFunctionalRequests` gana `authenticateAdminClient()` (el registro es ADMIN-only).
- **Boy-scout / decisión menor a revisar:** los casos de política de `users` se alojaron en la clase nueva
  `StaticAuthorizationPolicyUsersResourceTest` en vez de la existente `StaticAuthorizationPolicyTest`, que ya estaba en el
  tope de 10 métodos públicos de PHPMD; se cubre el mismo SUT desde una clase cohesiva dedicada.
- **Nota de auditoría (no en scope, informativa):** como cualquier GET de negocio, las dos rutas del registro reciben el
  access-log genérico en `kernel.terminate` (`ROUTE_BACKOFFICE_USER_*`), consistente con Bank; no cuenta contra el
  presupuesto de queries (post-terminate) y no requiere código.

### File List

**A — RBAC (`api/`):**
- `src/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicy.php` (UPDATE)
- `tests/Unit/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicyUsersResourceTest.php` (NEW)
- `tests/Functional/Iam/Identity/Infrastructure/Security/PermissionVoterAccessDecisionTest.php` (UPDATE)

**B — read-side API (`api/src/Iam/Identity/`, NEW salvo aviso):**
- `Domain/Projection/UserRow.php` · `Domain/Repository/UserSearchRepository.php`
- `Infrastructure/Persistence/Doctrine/DoctrineUserSearchRepository.php`
- `Application/UserSearcher.php` · `Application/Query/SearchUsersQuery.php` · `Application/UserFinder.php`
- `Application/Resource/UserListResource.php` · `Application/Resource/UserDetailResource.php`
- `Infrastructure/Http/UserResourceMapper.php`
- `Infrastructure/Controller/UserSearchController.php` · `Infrastructure/Controller/UserGetController.php`

**B — tests (`api/tests/`, NEW):**
- `Unit/Iam/Identity/Application/{UserSearcherTest,UserFinderTest,InMemoryUserSearchRepository}.php`
- `Unit/Iam/Identity/Infrastructure/UserRowContractTest.php`
- `Unit/Iam/Identity/Infrastructure/Http/UserResourceMapperTest.php`
- `Unit/Iam/Identity/Infrastructure/UserEntityNeverReachesTheSerializerStructuralTest.php`
- `Functional/Iam/Identity/Infrastructure/Persistence/Doctrine/DoctrineUserSearchRepositoryTest.php`
- `Functional/Iam/Identity/Infrastructure/Controller/{UserSearchCursorFunctionalTest,UserDetailResponseGoldenFunctionalTest}.php`
- `features/backoffice/users/{search,get,access_control}.feature`

**Infra de test compartida (UPDATE):**
- `tests/Doctrine/TestDebugDataHolder.php` (auth-lookup por call-site)
- `tests/Functional/AuthenticatesFunctionalRequests.php` (`authenticateAdminClient`)
- `tests/Behat/Context/SecurityContext.php` (paso "logged in as an administrator")
- `tests/DataFixtures/Fixtures/Session.yaml` (`session_admin`)

**C — PWA (`pwa/`, PR-B / #502):**
- `src/context/backoffice/user/infrastructure/ApiUserRepository.ts` · `ApiUserSearchNavigator.ts` (NEW)
- `src/context/backoffice/user/domain/User.ts` (UPDATE — sin `permissions`)
- `src/context/backoffice/user/domain/UserProblemType.ts` (UPDATE — `NOT_SUPPORTED`)
- `src/context/shared/http-client/domain/HttpStatus.ts` (UPDATE — `NOT_IMPLEMENTED` 501)
- `src/context/shared/access/domain/Role.ts` (UPDATE — vocab backend)
- `src/context/shared/http-client/infrastructure/ApiEndpoints.ts` (UPDATE — bloque `USERS`)
- `src/context/shared/dependency-injection/infrastructure/Container.ts` (UPDATE — swap 2 binds mock→real)
- `src/app/backoffice/users/_lib/userLabels.ts` · `_lib/usersFilterSort.ts` · `_lib/usersSearchCriteria.ts` (UPDATE)
- `src/app/backoffice/users/_components/UsersFilters.tsx` · `_components/UserForm.tsx` (UPDATE)
- `src/app/backoffice/users/[id]/page.tsx` · `[id]/edit/page.tsx` (UPDATE)
- `src/context/backoffice/user/infrastructure/InMemoryUserRepository.ts` · `infrastructure/userSeed.ts` (DELETE)

**C — tests (`pwa/tests/`, PR-B / #502):**
- `context/backoffice/user/infrastructure/ApiUserRepository.test.ts` · `ApiUserSearchNavigator.test.ts` (NEW)
- `e2e/backoffice/users-real-api.spec.ts` (NEW)
- `context/backoffice/user/schemas.test.ts` (UPDATE — vocabulario `Role`)
- `context/backoffice/user/InMemoryUserRepository.test.ts` (DELETE)

### Change Log

- 2026-07-16 — PR-A (costuras A+B): RBAC `users` opt-out + 4 grants ADMIN; read-side `GET /backoffice/users` (+`/{id}`)
  con proyección `UserRow`, keyset y Resource DTOs por-vista, gateados `users.read`. Tests unit/functional/Behat +
  ajuste de `TestDebugDataHolder` (auth-lookup por call-site). Costura C (PWA) pendiente en PR-B.
- 2026-07-17 — PR-B (costura C, #502): consola `/backoffice/users` conectada al backend real vía DI (`ApiUserRepository`/
  `ApiUserSearchNavigator`, swap mock→real sin cambios de consumidor); `Role` alineado al backend; filtro de rol quitado;
  detalle sin `permissions`; writes → `HttpError` 501 (`user-operation-not-supported`). Tests unit + e2e read-only. Historia
  → `review`.

### Review Findings
