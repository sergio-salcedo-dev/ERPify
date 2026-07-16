---
stepsCompleted: ['step-01-validate-prerequisites']
inputDocuments:
  - _bmad-output/planning-artifacts/arch-addendum-users-admin.md
  - _bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-07-06/EXPERIENCE.md
  - docs/adr/rbac-authorization-model.md
  - docs/adr/auth-rbac-subsystem.md
  - docs/adr/identity-invitation-lifecycle.md
  - docs/project-context.md
  - _bmad-output/planning-artifacts/epics-identity-invitation-lifecycle.md
  - tmp/bmad-md/consult-iam-users-authorization-20260715-130824.md
---

# ERPify — Administración de usuarios (back-office / Iam) — Desglose de épica

## Overview

Desglosa la épica **«Administración de usuarios (Iam)» (U-0…U-4)** definida en el DAG de
[`arch-addendum-users-admin.md`](./arch-addendum-users-admin.md): conectar la consola
`pwa/src/app/backoffice/users` (hoy un mock CRUD in-memory) al backend real `Iam/Identity`
(invitation-based), construyendo el **read-side** de identidades (list + detalle, keyset) y cableando
las **acciones sobre el ciclo de vida ya en vigor** (invitar · cambio de estado · edición de roles) con
su **gateo RBAC**. No construye tenancy operativa ni superficie de cumplimiento.

El hueco que cubre: la consola PWA es un `InMemoryUserRepository` + `userSeed` sobre el puerto genérico
`CrudRepository` (search/find/create/update/delete) **desconectado del backend**, con un `Role` inventado
(`SUPER_ADMIN/EMPLOYEE/CUSTOMER/SUPPLIER`) que **no existe** en el dominio. El backend `Iam/Identity` es
**invitation-based, no CRUD**: no hay endpoints de listado/detalle (construir read-side); «create» real =
`InviteUser` (→ `INVITED`); **no existe** `UpdateUser` (email inmutable, `#[UniqueEntity]`); «delete» real
= `ChangeUserStatus` (suspend/deactivate, invariante ≥1 ADMIN activo) o `EraseIdentitySubject` (GDPR,
hard-delete, CLI). Los permisos por-usuario **no se almacenan** — se derivan de los roles.

> **Derivado de un contrato ya ratificado.** Este inventario **no** es independiente del diseño: las
> FR/NFR/UX-DR destilan decisiones ya fijadas en [`arch-addendum-users-admin.md`](./arch-addendum-users-admin.md)
> (SI-16…SI-19 + localización por-historia U-0…U-4 + DAG) y la decisión de autorización **cerrada** (histórico
> en `tmp/bmad-md/consult-iam-users-authorization-20260715-130824.md`). El comportamiento del backoffice de
> miembros lo fija el **contrato J5** del run UX `ux-ERPify-2026-07-06` (`EXPERIENCE.md` — costura
> `Acción → Evento → Estado → Email → Superficie pública`, sin UI propia: la consola reutiliza los primitivos
> existentes). El objetivo aquí es **trazabilidad e implementabilidad**, no re-abrir el diseño.

## Requirements Inventory

### Functional Requirements

FR1: **Read-side de identidades — list + detalle (keyset)** (SI-16, SI-17 · U-0) — proyección
**single-context** `UserRow` (`SELECT NEW UserRow(u.id, u.email, u.status, u.roles, u.createdAt, u.updatedAt)
FROM Iam/Identity/User u` — **sin JOIN**); `GET /backoffice/users` (keyset sobre `DoctrineSearchEngine`,
**envelope-v2** + cursores opacos, filtros `email`(Contains) + `status`(Eq), sort `email/status/createdAt/
updatedAt` + `id` tie-break) y `GET /backoffice/users/{id}`, ambos `#[IsGranted('users.read')]`; Resource
DTOs por-vista (`UserListResource` / `UserDetailResource`) + `UserResourceMapper` (la entidad **nunca** se
serializa); `roles` es columna de proyección **display-only** (filtro por rol **diferido**, ausente del
`SearchFieldMap`); el detalle **omite** la sección `permissions` (roles **son** el mapa de autorización).

FR2: **Datos de autorización del recurso `users`** (SI-17 · U-0) — `users` → `TIER_OPT_OUT` + `users.read`,
`users.invite`, `users.changeStatus`, `users.erase` → `[ADMIN]` en `EXPLICIT_GRANTS` de
`StaticAuthorizationPolicy` (**edición de datos**, tripwire `token_get_all` verde). Sin el opt-out,
`users.read` se auto-concedería a `VIEWER`. Los strings de permiso son **idénticos byte-a-byte** en API
(`#[IsGranted]`) y PWA (`Permission`), camelCase.

FR3: **Conexión de la consola PWA al backend real** (SI-16, SI-18 · U-0) — `ApiUserRepository` (search+find)
+ `ApiUserSearchNavigator` + adapters `UserCrudRepository`/`UserResourceNavigator` + bloque `USERS` en
`ApiEndpoints` + swap de los dos binds `toConstantValue`(mock) → `.inSingletonScope()`(real), **cero cambios
de consumidor** (páginas/hook dependen solo de las claves DI + puertos genéricos); **alinear el vocabulario de
`Role` al backend** (`VIEWER/EDITOR/MANAGER/ADMIN/AUDIT_READER`; ripple `RolesBadges`/`ROLE_LABEL`/form/
`userSeed`); quitar el select de rol de `UsersFilters`; `create/update/delete` del repo → stub tipado
*no-soportado* hasta su historia (SI-18).

FR4: **Derivación de permisos en `/me` + gateo de cliente** (U-1) — `/me` expande `roles → permisos` vía
`AuthorizationPolicy` (**set derivado, no almacenado**); `<Can>` deja de denegar por `permissions:[]`; los
botones de acción se hacen visibles al ADMIN. **Prerrequisito de toda superficie de acción.**

FR5: **Invitar (alta = invitación)** (SI-18 · U-2) — endpoint de alta `#[IsGranted('users.invite')]` →
`SendInvitation`/`InviteUser` (→ `INVITED`); form de **invitación** (email + roles; **sin** password ni
status) que **reemplaza** el create del mock; **rename** enum PWA `users.write → users.invite`. **J5:**
`InvitationCreated/Sent` → `Invitation CREATED→SENT` → email de invitación → superficie pública B4 accept.

FR6: **Cambio de estado (suspend / deactivate)** (SI-18 · U-3) — `PATCH /backoffice/users/{id}/status`
(precedente `BankAccount PATCH .../status`; body `{status}` validado contra las transiciones legales desde
`ACTIVE`) `#[IsGranted('users.changeStatus')]` → `ChangeUserStatus` (`409 LastActiveAdministratorProtected`
si rompe ≥1 ADMIN activo); acciones de estado en el **detalle** (no un form de edición libre); **rename** enum
PWA `users.delete → users.changeStatus`. **J5:** `UserSuspended` → `Identity ACTIVE→SUSPENDED` (invalida
sesiones) → muro post-identidad «suspendido».

FR7: **Edición de roles** *(candidato — a decidir en el corte · SI-16, SI-18; toca SI-15)* — **nuevo caso de
uso de dominio** `ChangeUserRoles` (no existe hoy: `register`/`invite` fijan roles solo al alta), con guard
≥1 ADMIN en la democión del último admin y la **decisión `User.roles` vs `Organization.Membership.roles`**
(dualidad del seam de tenancy). Se presenta al Paso 2 como historia con diseño propio, no se da por incluida.

FR8: **Borrado GDPR — superficie de cumplimiento en consola** (SI-19 enmendado · U-5) — `users.erase`
**gana** entrada en el enum de la PWA: acción «Borrado GDPR (irreversible)» **separada de deactivate**,
ADMIN-only, `#[IsGranted('users.erase')]`, con **confirmación type-to-confirm** y respetando ≥1 ADMIN
activo. El flujo **encadena** `EraseIdentitySubject` (hard-delete de la identidad + reset tokens + audit
`GDPR_SUBJECT_ERASED`) **+** anonimización del rastro de auditoría (`audit:gdpr:erase` →
`DbalAuditActorAnonymiser`: `actor_id` + `ip`/`user_agent`) — un erase que solo borre la identidad deja el
`actor_id` **huérfano** y es **incompleto**. La CLI (`identity:gdpr:erase-subject`) se mantiene (additiva).
*Fricción = confirmación fuerte, no ausencia de UI: exigir un desarrollador para una obligación legal es un
anti-patrón operativo.*

FR9: **Cerrar #376 — tombstone de `actor_id`s** *(prerrequisito duro de U-5 · `Shared/Audit`)* — un
*tombstone* de `actor_id`s erasados, consultado por el writer DBAL (`DbalAuditLogWriter`) **y** el handler
async (`RecordAuditEntryHandler`), para que un evento `activity` en vuelo **no** re-inserte un `actor_id` ya
anonimizado. Historia previa (cross-cutting en `Shared/Audit`) que **bloquea** U-5, porque exponer el erase a
admins eleva la frecuencia del disparo.

### NonFunctional Requirements

NFR1 (Invariante · SI-16): **Vocabulario de identidad = backend.** El read-model y la PWA reflejan
`Role{VIEWER,EDITOR,MANAGER,ADMIN,AUDIT_READER}` e `IdentityStatus{INVITED,ACTIVE,SUSPENDED,DEACTIVATED}`
tal como los define `Iam/Identity`; en el **wire** viaja el `.value` del enum (mayúsculas), nunca una
etiqueta traducida. La **etiqueta de UI** (título/nav) es capa humana **independiente** del recurso RBAC
(«Usuarios» hoy → puede evolucionar a «Miembros/Equipo» con tenancy **sin tocar `users.*`**).

NFR2 (Invariante · SI-17): **`users` = consola ADMIN-only por opt-out, acciones separadas.** `users` en
`TIER_OPT_OUT`; cada capacidad es un permiso propio gateado con `#[IsGranted('users.<action>')]`. `read` =
**una unidad** (padrón `email`+`status` + roles) con la **redacción field-level de roles vetada hoy**. YAGNI
sobre `USER_ADMIN` (disparador: administrar identidades sin ser ADMIN completo). El `TIER_OPT_OUT` es sano
mientras sea **un puñado (~5)** — hacia **~15** migrar a capacidades puras (nota junto al tripwire).

NFR3 (Invariante · SI-18): **La administración de identidades NO es CRUD.** Las mutaciones son casos de uso
de dominio (invite / changeStatus), **nunca** `create/update/delete` genéricos ni un `PUT/PATCH` genérico de
identidad; el email es **inmutable**.

NFR4 (Invariante · SI-19 enmendado): **Fricción ∝ irreversibilidad = confirmación fuerte, no ausencia de
UI.** `deactivate` es la acción **cotidiana** de consola (despido — conserva la atribución); `erase` es una
superficie de cumplimiento **separada** (ADMIN-only, distinta de deactivate, **type-to-confirm**, respeta ≥1
ADMIN) que des-identifica **identidad + rastro de auditoría** encadenados; `users.erase` **gana** entrada en
el enum PWA. La CLI se mantiene (additiva). *Exigir un desarrollador (CLI) para una obligación legal es un
anti-patrón operativo.*

NFR5 (Read-side / rendimiento): **Keyset, no OFFSET.** El read-side pagina con `DoctrineSearchEngine`
(cursores opacos HMAC, envelope-v2), proyección de columnas explícitas (sin `SELECT *`), **single FROM**
(sin N+1). Los índices que cubran los campos de sort (`email/status/createdAt/updatedAt`) se evalúan en la
historia (añadir vía `make db.diff` si el plan lo pide; medido, no asumido).

NFR6 (Invariante heredado · Épica II): **≥1 ADMIN activo + ciclo unidireccional.** `suspend`/`deactivate`
pasan por `ChangeUserStatus` (guard `ActiveAdministratorDirectory` → `409`; eventos `UserSuspended`/
`UserDeactivated` por el `EventBus` transaccional = outbox). La consola lo **consume**, no lo re-implementa.

NFR7 (Seguridad / contrato de error): **Gateo + RFC 9457.** Cada endpoint declara su `#[IsGranted('users.
<action>')]`; los errores fluyen por el pipeline **RFC 9457** (403 forbidden, 404 not-found, `409`
last-admin, 422 validation) — nunca body manual; route-id vía `Uuid::ensure()` (400 `invalid-uuid`);
validación por `#[MapQueryString]`/`#[MapRequestPayload]`. `php.lint.error-contract` verde (reusar tipos
existentes; documentar si aparece uno nuevo).

NFR8 (Aislamiento / deptrac): **`users` es `Iam/Identity`.** El read-slice + acciones viven en `Iam/Identity`
(no crean módulo nuevo — reusan sus capas); ninguna referencia cruza un contexto salvo seam publicado
(`Organization\Membership` por id para tenancy). `php.deptrac` + `php.lint.bounded-context` verdes.

NFR9 (Test): **Cobertura por suite, no por visitas.** Consola de bajo tráfico verificada con la **suite**;
**Behat: invitar ENCOLA el email** (no basta `201`); **e2e conduce el ciclo** `invite → INVITED →
changeStatus → reflejo` (no smoke); todo sobre primitivos compartidos (`DataTable`/`AsyncBoundary`/
`MutationError`/`<Can>`/`useResourceList`).

NFR10 (Safe-first / secuenciación): **Orden aditivo primero.** `U-0 → U-1 → (U-2 · U-3) → U-4`; U-0 (lectura)
es aditivo (no cambia comportamiento existente); ninguna historia depende de una posterior en su orden de merge.

### Additional Requirements

- **Edición de datos de la policy + tripwire:** añadir `users` a `TIER_OPT_OUT` y los 4 grants a
  `EXPLICIT_GRANTS` en `StaticAuthorizationPolicy` es una edición de **datos** (mantiene verde
  `StaticAuthorizationPolicyIsDataOnlyTest`); actualizar sus fixtures/expectativas si las hubiera.
- **Envelope-v2 + `ResponseGuard` (PWA):** el `ApiUserRepository` reutiliza el envelope de paginación
  `{data[], pagination{hasNext,hasPrev,count,links{next,prev}}}` y el guard de respuesta que ya usa Banks —
  nada de forma legacy (`currentPage/cursor`).
- **Ripple de vocabulario:** alinear `Role` al backend toca `RolesBadges`/`ROLE_LABEL`/checkboxes del form/
  `userSeed` — es parte de U-0, no un barrido aparte.
- **`/me` deriva permisos** (set derivado, no almacenado) — habilitador de `<Can>`; secuenciado en U-1
  (Story 1 lectura **no** lo necesita: enforcement server-side).
- **El toolkit genérico miente para identidad (SI-18):** decidir en el corte si la consola conserva el
  toolkit `CrudRepository`/`useResourceList` para la **lectura** + puertos propios para invite/changeStatus,
  o migra a puertos *identity-shaped*.
- **Endpoint de invite — a decidir en el corte:** `POST /backoffice/users` (alta desde el plano `users`) vs
  reutilizar la superficie de `Iam/Invitation`.
- **U-4 edición de roles — candidato:** nuevo `ChangeUserRoles` + dualidad `User.roles` vs `Membership.roles`;
  puede quedar fuera de esta épica.
- **Atribución al borrar un usuario (verificado):** las filas de negocio (`Bank`, `BankAccount`) **no** guardan
  autor (solo `createdAt`/`updatedAt`) — borrar/erase un usuario **no** cascadea ni toca datos de negocio; la
  atribución vive **solo** en `audit_log` (`actor_id`). `deactivate` la conserva; el erase completo la
  des-identifica (identidad + actor **encadenados**).
- **#376 (resurrección async del `actor_id`) = prerrequisito duro de U-5** — tombstone de `actor_id`s en
  `Shared/Audit` (FR9); historia previa que bloquea el borrado GDPR en consola.
- **Gate de ramas (CLAUDE.md):** cada historia posterior tendrá su rama, reconfirmada una a una; nunca merge a
  `main` sin permiso.

### UX Design Requirements

> El backoffice de miembros es **contrato J5** (`EXPERIENCE.md`), **no diseño visual propio**: la consola
> reutiliza los primitivos existentes (`DataTable`, `AsyncBoundary`, `MutationError`, `UsersPagination`,
> `UserStatusBadge`, `RolesBadges`, `<Can>`). No hay componentes nuevos como en la Épica II; los UX-DR fijan
> **comportamiento y forma de las superficies**, no un sistema visual nuevo.

UX-DR1: **Lista de usuarios (read-only)** — tabla/stacked sobre `DataTable`/`AsyncBoundary` con `email`,
`status` (`UserStatusBadge`), `roles` (`RolesBadges`, **display-only**), `createdAt/updatedAt`; filtros
**email + status** (se **quita** el select de rol); paginación keyset (`UsersPagination`); estados
first-run / filtered-to-zero / permission-denied vía `AsyncBoundary`; error de servidor persistente
(`403 → AsyncBoundary error`).

UX-DR2: **Detalle de usuario** — `email`, `status`, `roles`; **sin** sección `permissions`; el detalle es el
hogar de las **acciones de estado** (no un form de edición libre de email/roles).

UX-DR3: **Form de invitación** — `email` + `roles` (sin password ni status), que **reemplaza** el «New user»/
create del mock; gateado por `<Can permission=users.invite>`.

UX-DR4: **Acciones de cambio de estado** — suspend / deactivate desde el detalle, gateadas por
`<Can permission=users.changeStatus>`, con **confirmación proporcional** (transición unidireccional, sin
reinstate) y feedback por `MutationError` (fallo) / señal de éxito; refleja el nuevo estado tras la acción.

UX-DR5: **Costura J5 visible** — la acción admin dispara `Evento → Estado → Email → Superficie pública`
(invitar **encola** el email → B4; suspender **invalida** sesiones → muro post-identidad). La UI refleja el
estado resultante; realtime es **opcional** (la lista de usuarios no lo tiene hoy — reload basta).

UX-DR6: **Borrado GDPR — superficie separada y guardada** (SI-19 enmendado) — acción «Borrado GDPR
(irreversible)» **claramente distinta** del deactivate/«quitar» cotidiano, ADMIN-only, con **type-to-confirm**
y aviso de que **des-identifica el rastro de auditoría**; la UI nunca permite confundir erase con deactivate.

### FR Coverage Map

{{requirements_coverage_map}}

## Epic List

{{epics_list}}
