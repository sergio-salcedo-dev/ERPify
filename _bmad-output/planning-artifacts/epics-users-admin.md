---
stepsCompleted: ['step-01-validate-prerequisites', 'step-02-design-epics', 'step-03-create-stories']
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

> **Estado: implementado — épica `users-admin` cerrada (2026-07-21).** Documento histórico: el estado vivo son los PRs #500–#529, el código y la retro `epic-users-admin-retro-2026-07-21.md`. No re-abrir el diseño desde aquí.

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
`AuthorizationPolicy` (**set derivado, no almacenado**), enumerando el vocabulario contra un **catálogo** (el puerto
responde sí/no a un permiso, no enumera); `<Can>` deja de denegar por `permissions:[]` y **la consola se gatea con
`users.read`**. U-1 alinea además el **vocabulario completo** de permisos de la PWA al del backend (SI-20), y **retira
la superficie CRUD del mock** (create/edit/delete respaldados por stubs *no-soportado*), que SI-18 declara inexistente
para identidades. **Prerrequisito de toda superficie de acción:** las acciones reales y su visibilidad llegan con
U-2/U-3/U-5, cada una gateada por su permiso. Los permisos **no forman parte del modelo de dominio** — son un
**read-model de autorización derivado**; no existe `User.permissions` persistido.

FR5: **Invitar (alta = invitación)** (SI-18 · U-2) — endpoint de alta `#[IsGranted('users.invite')]` →
`SendInvitation`/`InviteUser` (→ `INVITED`); form de **invitación** (email + roles; **sin** password ni
status) — no «reemplaza» el create del mock: U-1 ya lo retiró, aquí se **construye la superficie real**, visible para
quien posea `users.invite` (`<Can permission=users.invite>`; el string ya existe en el enum desde U-1). **J5:**
`InvitationCreated/Sent` → `Invitation CREATED→SENT` → email de invitación → superficie pública B4 accept.

FR6: **Cambio de estado (suspend / deactivate)** (SI-18 · U-3) — `PATCH /backoffice/users/{id}/status`
(precedente `BankAccount PATCH .../status`; body `{status}` validado contra las transiciones legales desde
`ACTIVE`) `#[IsGranted('users.changeStatus')]` → `ChangeUserStatus` (`409 LastActiveAdministratorProtected`
si rompe ≥1 ADMIN activo); acciones de estado en el **detalle** (no un form de edición libre), visibles para quien posea
`users.changeStatus` (`<Can permission=users.changeStatus>`; el string ya existe en el enum desde U-1). **J5:**
`UserSuspended` → `Identity ACTIVE→SUSPENDED` (invalida sesiones) → muro post-identidad «suspendido».

FR7: **Edición de roles** *(candidato — a decidir en el corte · SI-16, SI-18; toca SI-15)* — **nuevo caso de
uso de dominio** `ChangeUserRoles` (no existe hoy: `register`/`invite` fijan roles solo al alta) que **establece el conjunto completo** de roles (semántica
*set*, no deltas grant/revoke — coherente con el form de checkboxes), con guard
≥1 ADMIN en la democión del último admin y la **decisión `User.roles` vs `Organization.Membership.roles`**
(dualidad del seam de tenancy). Se presenta al Paso 2 como historia con diseño propio, no se da por incluida.

FR8: **Borrado GDPR — superficie de cumplimiento en consola** (SI-19 enmendado · U-5) — U-5 **usa** la entrada
`users.erase` que el enum de la PWA ya declara (alineada en U-1) para una acción «Borrado GDPR (irreversible)»
**separada de deactivate**,
ADMIN-only, `#[IsGranted('users.erase')]`, con **confirmación type-to-confirm** y respetando ≥1 ADMIN
activo. El flujo, **orquestado por un único Application Service** (p.ej. `FulfilIdentityErasure`), **encadena**
`EraseIdentitySubject` (hard-delete de la identidad + reset tokens + audit
`GDPR_SUBJECT_ERASED`) **+** anonimización del rastro de auditoría (`audit:gdpr:erase` →
`DbalAuditActorAnonymiser`: `actor_id` + `ip`/`user_agent`) — un erase que solo borre la identidad deja el
`actor_id` **huérfano** y es **incompleto**. La CLI (`identity:gdpr:erase-subject`) se mantiene (additiva).
*Fricción = confirmación fuerte, no ausencia de UI: exigir un desarrollador para una obligación legal es un
anti-patrón operativo.*

FR9: **Cerrar #376 — eliminar la ventana de resurrección async** *(prerrequisito duro de U-5 · `Shared/Audit`)* —
una entrada `activity` en vuelo **no** puede re-insertar un `actor_id` ya anonimizado. **La solución no es un
tombstone: es eliminar la cola de `activity`** — se escribe síncronamente vía `AuditLogWriter`, igual que
`security` y `change`, conservando verbatim el SLA best-effort (fallo tragado y logueado). Sin cola no hay
consumidor tardío, y desaparece además la segunda copia durable de PII (`messenger_messages`) que ninguna
política de D4 gobernaba. Historia previa (cross-cutting en `Shared/Audit`) que **bloquea** U-5, porque exponer
el erase a admins eleva la frecuencia del disparo.

*El tombstone `(actor_id → pseudónimo)` se descartó: es la tabla de mapeo que D4 prohíbe por escrito, y una
huella del `actor_id` no es de un solo sentido (el espacio de ids es enumerable), así que sería un oráculo de
re-identificación sin llave. Enmienda **D3** (mecanismo de entrega); **D4/D4.1 quedan intactos**. Decisión
cerrada con medición en vivo — detalle en la historia.*

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
identidad; el email es **inmutable hoy** (ancla de identidad; mutabilidad con verificación = evolución
posible, no principio del dominio); los **permisos** son read-model derivado, **no** estado persistido (no
`User.permissions`).

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

NFR10 (Safe-first / secuenciación): **Orden aditivo primero.** `U-0 → U-1 → (U-2 · U-3) → U-4 · [cerrar #376] → U-5`; U-0 (lectura)
es aditivo (no cambia comportamiento existente); ninguna historia depende de una posterior en su orden de merge.

NFR11 (Invariante · SI-20): **Consistencia del contrato de permisos.** Los strings de permiso son **idénticos
byte-a-byte** en API (`#[IsGranted]`) y PWA (`Permission`), en camelCase — sin fuente de verdad compartida el
drift es silencioso (ningún compilador lo atrapa). Separado de la autorización (SI-17) porque evoluciona con
tooling, no con el modelo.

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
- **#376 (resurrección async del `actor_id`) = prerrequisito duro de U-5** — se cierra **retirando la cola de
  `activity`** en `Shared/Audit` (FR9), no con un tombstone; historia previa que bloquea el borrado GDPR en consola.
- **Evolución anotada (no se cambia hoy):** `users.erase` es plano de cumplimiento (SI-19); si el erase
  prolifera a otros sujetos (`customers`/`employees`/…), considerar un recurso dedicado
  (`compliance.eraseSubject` / `gdpr.eraseIdentity`) en vez de un `*.erase` por recurso — punto de evolución.
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

- **FR1 → Epic 1 (U-0)** — read-side de identidades (list + detalle keyset).
- **FR2 → Epic 1 (U-0)** — datos de autorización (`users` → `TIER_OPT_OUT` + grants `[ADMIN]`).
- **FR3 → Epic 1 (U-0)** — conexión PWA ↔ backend real + vocabulario de roles alineado.
- **FR4 → Epic 1 (U-1)** — `/me` deriva permisos + `<Can>` funcional.
- **FR5 → Epic 1 (U-2)** — invitar (alta = invitación).
- **FR6 → Epic 1 (U-3)** — cambio de estado (suspend/deactivate, guard ≥1 ADMIN).
- **FR7 → Epic 1 (U-4)** — edición de roles *(candidato)*.
- **FR8 → Epic 1 (U-5b)** — borrado GDPR en consola (superficie de cumplimiento).
- **FR9 → Epic 1 (U-5a)** — cerrar #376 (retirar la cola de `activity` en `Shared/Audit`, prereq duro de U-5b).

**Cobertura NFR:** NFR1-4 = invariantes SI-16…19 + NFR11 = SI-20, verificados transversalmente en cada
historia; NFR5 (keyset) nace en U-0; NFR6 (≥1 ADMIN) en U-3/U-5b; NFR7 (gateo + RFC 9457) en cada endpoint;
NFR8 (deptrac) gate en todas; NFR9 (test) transversal; NFR10 (safe-first) = el orden de merge. **UX-DR1-6**
en U-0 (lista/detalle) · U-2 (invitación) · U-3 (estado) · U-5b (erase). Sin FR/NFR/UX-DR huérfanos.

## Epic List

**Una sola épica.** El diseño está validado (addendum SI-16…20 + decisión de auth cerrada) y el DAG es un
grafo conexo enraizado en U-0 con **fuerte churn compartido** sobre los mismos ficheros núcleo (la consola
`pwa/.../backoffice/users`, el `Permission` enum, `StaticAuthorizationPolicy`, el `Container` DI,
`Iam/Identity`): no hay frontera de riesgo capaz de cambiar la dirección de una historia posterior, así que
aplica «outcome cierto → menos épicas, más grandes + consolidar lo que toca los mismos ficheros» (precedente:
`epics-identity-invitation-lifecycle.md`). *Alternativa descartada:* aislar U-5 (borrado GDPR + #376) en su
propia épica — legítima (cruza a `Shared/Audit`) pero añade una dependencia cross-épica (U-5 necesita la
consola de U-0) sin comprar feedback temprano (el diseño ya está validado).

### Epic 1: Administración de usuarios (back-office / Iam)

Tras la épica, un **ADMIN** gestiona identidades desde la consola `/backoffice/users` **conectada al backend
real**: ve el padrón (list + detalle keyset), invita altas (alta = invitación), cambia el estado
(suspend/deactivate) con la garantía **≥1 ADMIN activo**, y —con salvaguardas de cumplimiento— ejecuta el
**borrado GDPR**; el gateo RBAC (`users.*`) es ADMIN-only por opt-out y los permisos de cliente vienen de
`/me`. **Fuera de alcance:** tenancy operativa, filtro por rol server-side, superficie de erase para otros
sujetos. **FRs:** FR1-FR9. **NFRs:** NFR1-NFR11. **UX-DR:** UX-DR1-UX-DR6.

**Historias (orden de merge safe-first `U-0 → U-1 → (U-2 · U-3) → U-4 · [U-5a → U-5b]`):**

- **U-0 — read-side + auth-data + conexión PWA** — proyección `UserRow` single-context + `GET
  /backoffice/users` (keyset) + `/{id}`, `#[IsGranted('users.read')]`; `users`→opt-out+grants;
  `ApiUserRepository` + swap del mock + vocabulario de roles alineado. — FR1, FR2, FR3.
- **U-1 — `/me` deriva permisos + `<Can>`** — expansión `roles→permisos` (read-model derivado, no
  persistido) vía catálogo + tripwire; consola gateada por `users.read`; vocabulario alineado (SI-20) y
  superficie CRUD del mock retirada; habilita las acciones de U-2/U-3/U-5. — FR4.
- **U-2 — invitar** — `#[IsGranted('users.invite')]` → `SendInvitation`/`InviteUser` (→ INVITED); form de
  invitación visible con `users.invite`. — FR5.
- **U-3 — cambio de estado** — `PATCH .../status` → `ChangeUserStatus` (`409` si rompe ≥1 ADMIN); acciones en
  el detalle, visibles con `users.changeStatus`. — FR6.
- **U-4 — edición de roles** *(candidato)* — `ChangeUserRoles` (establece el conjunto completo) + dualidad
  `User.roles` vs `Membership.roles`. — FR7.
- **U-5a — cerrar #376** *(prereq duro · `Shared/Audit`)* — `activity` pasa a escritura síncrona; se borran el
  mensaje, su handler y el transporte `audit`. — FR9.
- **U-5b — borrado GDPR en consola** — Application Service `FulfilIdentityErasure` (identity-erase +
  actor-anonymise, una operación); acción separada, ADMIN-only, type-to-confirm; gatea con `users.erase`
  (entrada del enum PWA ya alineada en U-1). — FR8.

Ninguna historia depende de una posterior en su orden de merge.

## Epic 1: Administración de usuarios (back-office / Iam)

Consola `/backoffice/users` conectada al backend real: padrón (list+detalle keyset), invitar, cambio de
estado con garantía ≥1 ADMIN, edición de roles *(candidato)* y borrado GDPR con salvaguardas. Gateo RBAC
`users.*` ADMIN-only por opt-out. **Orden de merge:** `U-0 → U-1 → (U-2 · U-3) → U-4 · [U-5a → U-5b]`.

> **Método de las historias (AC basado en invariantes).** Cada historia declara el comportamiento que
> introduce, los invariantes que consume y los que establece; los AC se redactan como **invariantes
> verificables** enganchados al addendum (SI-16…SI-20) y a las FR, de modo que una refactorización futura no
> pueda romper una garantía sin que un test la detecte.

### Story 1.1 (U-0): Read-side de identidades — lista + detalle conectados al backend real

Como **ADMIN**,
quiero ver el padrón de usuarios (lista paginada + detalle) desde la consola conectada al backend real,
para administrar identidades sobre datos reales en vez del mock in-memory.

**Comportamiento que introduce:** el read-side de identidades, los datos de autorización de `users` y la
conexión de la consola PWA al backend.
**Invariantes que consume:** el plano RBAC (SI-1…9), `Iam/Identity/User` (Épica II).
**Invariantes que establece:** SI-16 (vocabulario = backend), SI-17 (`users`→opt-out, ADMIN-only), SI-20.

**Acceptance Criteria:**

**Given** `StaticAuthorizationPolicy`,
**When** se añade `users`,
**Then** `users` ∈ `TIER_OPT_OUT` y `users.{read,invite,changeStatus,erase}` ∈ `EXPLICIT_GRANTS → [ADMIN]`, y
el tripwire `StaticAuthorizationPolicyIsDataOnlyTest` sigue **verde** (solo datos) (FR2, SI-17).

**Given** un usuario `VIEWER`/`EDITOR`/`MANAGER` (no ADMIN),
**When** hace `GET /backoffice/users`,
**Then** recibe `403` — sin el opt-out, `read` de tier lo concedería; el opt-out lo impide (SI-17).

**Given** un ADMIN,
**When** hace `GET /backoffice/users`,
**Then** recibe un `Page` keyset (envelope-v2 `{data[], pagination{hasNext,hasPrev,count,links{next,prev}}}`,
cursores opacos) de `UserListResource` proyectado por `SELECT NEW UserRow(...) FROM User` **sin JOIN**;
filtros `email`(Contains)+`status`(Eq); `roles` **no** es filtrable (ausente del `SearchFieldMap`) (FR1, NFR5).

**Given** un ADMIN,
**When** hace `GET /backoffice/users/{id}`,
**Then** recibe `UserDetailResource` (email, status, roles, timestamps) **sin** sección `permissions`; `{id}`
malformado → `400 invalid-uuid`; id ausente → `404` (FR1, NFR7).

**Given** la consola PWA,
**When** se conecta,
**Then** `ApiUserRepository`(search+find) + navigator + adapters sustituyen el mock
(`toConstantValue`→`inSingletonScope`) **sin cambios de consumidor**; el `Role` del PWA se alinea al backend
(`VIEWER/EDITOR/MANAGER/ADMIN/AUDIT_READER`), se quita el filtro de rol y `create/update/delete` lanzan
«no soportado» (FR3, SI-16, SI-18).

**Given (SI-20)** los strings de permiso,
**When** se comparan API y PWA,
**Then** `users.read` es idéntico byte-a-byte en `#[IsGranted]` y `Permission.USERS_READ`.

### Story 1.2 (U-1): `/me` deriva permisos + gateo de cliente `<Can>`

Como **ADMIN**,
quiero que la consola conozca mis permisos derivados,
para ver las acciones que puedo ejecutar en vez de tenerlas ocultas.

**Comportamiento que introduce:** la expansión `roles → permisos` en `/me` y el gateo de cliente funcional.
**Invariantes que consume:** el plano RBAC, SI-17.
**Invariantes que establece:** los permisos son un **read-model derivado, no persistido** (SI-18).

**Acceptance Criteria:**

**Given** un ADMIN autenticado,
**When** hace `GET /me`,
**Then** la respuesta incluye el **set de permisos derivado** expandiendo `roles` vía `AuthorizationPolicy`
(no un campo almacenado) (FR4).

**Given (SI-18)** el modelo de `User`,
**When** se inspecciona,
**Then** **no** existe `User.permissions` persistido — los permisos solo se derivan.

**Given** un ADMIN en la consola,
**When** se renderiza,
**Then** `<Can permission=users.invite>` / `<Can permission=users.changeStatus>` **muestran** sus botones
(antes ocultos por `permissions:[]`) (FR4).

**Given** un usuario sin un permiso,
**When** se renderiza,
**Then** el control gateado **no** aparece (deniega por ausencia).

### Story 1.3 (U-2): Invitar (alta = invitación)

Como **ADMIN**,
quiero invitar a una persona nueva por email con sus roles,
para dar de alta identidades sin crear contraseñas ni «cuentas» directamente.

**Comportamiento que introduce:** el alta por invitación desde la consola.
**Invariantes que consume:** SI-17 (`users.invite` ADMIN-only), SI-18 (alta = invitación), SI-20 (el string
`users.invite` ya está en el enum desde U-1).
**Invariantes que establece:** ninguno nuevo — construye la superficie de invitación sobre el vocabulario ya alineado.

**Acceptance Criteria:**

**Given** un ADMIN,
**When** hace el `POST` de alta con `{email, roles}` (`#[IsGranted('users.invite')]`),
**Then** se crea una identidad `INVITED` vía `SendInvitation`/`InviteUser` (sin password ni status en el
payload) (FR5, SI-18).

**Given (test)** una invitación exitosa,
**When** se verifica en Behat,
**Then** el email de invitación queda **encolado** (no basta el `201`) (NFR9).

**Given** el form de la consola,
**When** se invita,
**Then** es un form de **invitación** (email + roles) que reemplaza el «create» del mock, gateado por
`<Can permission=users.invite>` (FR5, UX-DR3).

**Given** un ADMIN en la consola,
**When** posee `users.invite`,
**Then** la acción de invitar es visible y gateada por ese permiso, idéntico al string del `#[IsGranted]` backend (SI-20).

### Story 1.4 (U-3): Cambio de estado (suspend / deactivate)

Como **ADMIN**,
quiero suspender o desactivar a un usuario (p.ej. un empleado despedido),
para bloquear su acceso conservando su historia, sin dejar la organización sin administrador.

**Comportamiento que introduce:** las acciones de estado desde el detalle.
**Invariantes que consume:** el ciclo unidireccional + ≥1 ADMIN activo (Épica II), SI-18, SI-20 (el string
`users.changeStatus` ya está en el enum desde U-1).
**Invariantes que establece:** ninguno nuevo — construye las acciones de estado sobre el vocabulario ya alineado.

**Acceptance Criteria:**

**Given** un ADMIN y un usuario `ACTIVE`,
**When** hace `PATCH /backoffice/users/{id}/status` con `{status: SUSPENDED|DEACTIVATED}`
(`#[IsGranted('users.changeStatus')]`),
**Then** `ChangeUserStatus` transiciona el estado (unidireccional desde `ACTIVE`), invalida sesiones y emite
`UserSuspended`/`UserDeactivated` por el `EventBus` (outbox) (FR6, NFR6).

**Given (≥1 ADMIN)** el último ADMIN activo,
**When** se intenta suspender/desactivar,
**Then** responde `409 LastActiveAdministratorProtected` y el estado **no** cambia (NFR6).

**Given (atribución)** un usuario desactivado,
**When** se consulta el audit trail,
**Then** su atribución (`actor_id`) permanece **intacta** — deactivate conserva la historia.

**Given** la consola,
**When** se cambia el estado,
**Then** las acciones viven en el **detalle** (no un form de edición libre); e2e conduce el ciclo
`invite → INVITED → changeStatus → reflejo` (NFR9, UX-DR4).

**Given** un ADMIN en el detalle de un usuario,
**When** posee `users.changeStatus`,
**Then** las acciones de estado son visibles y gateadas por ese permiso (SI-20).

### Story 1.5 (U-4): Edición de roles *(candidato — a confirmar en el corte)*

Como **ADMIN**,
quiero cambiar el conjunto de roles de un usuario,
para ajustar sus capacidades sin re-invitarlo.

> **Candidato.** Introduce un caso de uso de dominio nuevo y toca el seam de tenancy (SI-15); puede quedar
> fuera de esta épica. Sus decisiones abiertas se resuelven al implementarla.

**Comportamiento que introduce:** `ChangeUserRoles` (nuevo caso de uso).
**Invariantes que consume:** SI-16, SI-15 (dualidad `User.roles`/`Membership.roles`).
**Invariantes que establece:** la semántica *set* de la edición de roles.

**Acceptance Criteria:**

**Given** el caso de uso `ChangeUserRoles`,
**When** se ejecuta,
**Then** **establece el conjunto completo** de roles (semántica *set*, no deltas grant/revoke) y el guard ≥1
ADMIN evalúa el conjunto **resultante** (no puede dejar la org sin ADMIN activo) (FR7).

**Given** la dualidad de fuentes (SI-15),
**When** se persisten los roles,
**Then** se **decide al implementar** si escriben `User.roles` (operativo) y/o `Membership.roles`
(autoritativo) — decisión abierta, no se fija aquí.

**Given** el permiso de la acción,
**When** se define,
**Then** se decide al implementar (probablemente un `users.changeRoles` propio en `EXPLICIT_GRANTS → [ADMIN]`),
idéntico byte-a-byte API↔PWA (SI-20).

### Story 1.6 (U-5a): Cerrar #376 — eliminar la ventana de resurrección async del `actor_id`

Como **plataforma**,
quiero que un `actor_id` anonimizado no pueda ser re-insertado por una escritura de auditoría en vuelo,
para que el borrado GDPR sea completo y no se «resucite» un sujeto ya erasado.

**Comportamiento que introduce:** ninguno — **retira** el camino async de `activity`.
**Invariantes que consume:** el SLA por nivel de D3 (`activity` best-effort, `security` durable).
**Invariantes que establece:** ninguna entrada de auditoría viaja por Messenger, así que no existe consumidor
tardío capaz de re-materializar PII anonimizada.

**Acceptance Criteria:**

**Given** una interacción `/api/*` auditable,
**When** corre la captura en `kernel.terminate`,
**Then** la entrada `activity` se escribe **directamente** vía `AuditLogWriter`, sin despachar mensaje alguno
(FR9).

**Given** un fallo al escribir una entrada `activity`,
**Then** se **traga y se loguea** como `warning` sin filtrar contexto, y un fallo en `security` **sigue
propagándose** — la asimetría de durabilidad por nivel de D3 no cambia.

**Given** el subsistema tras el cambio,
**Then** `RecordAuditEntry`, su handler, el transporte `audit` y su routing **ya no existen**, y ninguna entrada
de auditoría puede aterrizar en el transporte `failed`.

**Given** mensajes `RecordAuditEntry` encolados o en `failed` al desplegar,
**Then** se **descartan** —amparado en el best-effort que D3 declara para `activity`— acotando el borrado **por
tipo de mensaje**, nunca por cola (`failed` es compartido con los eventos de dominio).

**Given** una petición que empieza antes del `UPDATE` de anonimización y termina después,
**Then** puede escribir el `actor_id` original: la ventana pasa de ilimitada a **duración-de-request**, que es
**la misma carrera que `security` y `change` ya tienen** y que D4 tolera. Se documenta; no se declara cero.

**Given** el reconciler existente,
**When** corre tras un erase,
**Then** no encuentra discrepancias de sujeto — **guarda de regresión**: reconcilia DEKs destruidas
(crypto-shredding), un eje distinto del erase de actor (D15), y este cambio no debe perturbarlo.

### Story 1.7 (U-5b): Borrado GDPR desde la consola

Como **ADMIN**,
quiero ejecutar el borrado GDPR de una identidad desde la consola, con confirmación irreversible,
para cumplir una solicitud de derecho al olvido sin depender de que un desarrollador ejecute una CLI.

**Comportamiento que introduce:** la superficie de cumplimiento de erase en la consola.
**Invariantes que consume:** SI-19 (fricción ∝ irreversibilidad), ≥1 ADMIN activo, el cierre de #376 (U-5a).
**Invariantes que establece:** el erase des-identifica identidad + rastro **como una unidad**.

**Acceptance Criteria:**

**Given (prereq duro)** #376,
**When** se planifica el merge,
**Then** U-5b **no** se mergea antes que U-5a (la cola de `activity` ya no existe).

**Given** un ADMIN,
**When** ejecuta el borrado GDPR (`#[IsGranted('users.erase')]`) con **confirmación type-to-confirm**,
**Then** un **único Application Service** (`FulfilIdentityErasure`) encadena `EraseIdentitySubject` (hard-delete
identidad + reset tokens + audit `GDPR_SUBJECT_ERASED`) **+** anonimización del rastro (`actor_id`/`ip`/`ua`)
como **una operación** idempotente (FR8, SI-19).

**Given (atribución)** un usuario erasado,
**When** se consulta el audit trail y los datos de negocio,
**Then** su `actor_id` está anonimizado y su PII redactada, y **ningún** registro de negocio que creó/actualizó/
borró se toca (no hay cascada — las filas de negocio no guardan autor).

**Given (≥1 ADMIN)** el último ADMIN activo,
**When** se intenta erasar,
**Then** se rechaza (no puede dejar la org sin ADMIN).

**Given** la UI,
**When** se muestra el borrado GDPR,
**Then** es una acción «Borrado GDPR (irreversible)» **separada** de deactivate, ADMIN-only, con aviso de
des-identificación; `users.erase` tiene entrada en el enum PWA; la CLI se mantiene (additiva) (UX-DR6, SI-19).
