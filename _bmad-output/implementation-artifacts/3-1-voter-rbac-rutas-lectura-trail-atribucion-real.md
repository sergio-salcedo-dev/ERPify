---
baseline_commit: 015609fc3698e022df303d2c0300d159c6d6fccb
---

# Story 3.1: Voter RBAC sobre las rutas de lectura del trail + atribución real de actor

Status: review

<!-- Validación opcional: correr validate-create-story antes de dev-story. -->

## Story

Como **responsable de seguridad**,
quiero que **solo roles autorizados lean el trail de auditoría y que la atribución del actor sea real**,
para **satisfacer ISO 27001 A.5.18 (acceso restringido) y que el «quién» de cada entrada deje de ser `anonymous`**.

Epic 3 (trail regulatorio ISO 27001). **Gate ya levantado:** el subsistema auth/RBAC (auth-foundation AF-1.1/1.2/1.3/1.4) está mergeado en `origin/main` (`015609fc`). Esta story es la primera consumidora real de ese subsistema.

## Acceptance Criteria

**AC1 — Denegación por rol (FR13, D4).**
**Given** las dos rutas de lectura de `Backoffice/Audit` (`GET /api/v1/backoffice/audit/timeline` y `GET /api/v1/backoffice/audit/events/{id}`, incluida la superficie #377),
**When** una request **autenticada pero sin `ROLE_AUDIT_READER`** las invoca,
**Then** se deniega con **403 RFC 9457 `{ "type": "forbidden" }`** por el pipeline existente (sin marker nuevo, sin `JsonResponse` manual).

**AC2 — Concesión (regresión preservada).**
**Given** un usuario autenticado **con `ROLE_AUDIT_READER`**,
**When** invoca cualquiera de las dos rutas,
**Then** responde **200** con el mismo contrato de recurso que hoy (comportamiento actual intacto).

**AC3 — Anónimo → 401 (no regresión de AF-1.3).**
**Given** una request **sin sesión** a cualquiera de las dos rutas,
**When** se procesa,
**Then** responde **401 `{ "type": "unauthenticated" }`** (garantizado por `UnauthenticatedAccessListener.isFullFledged`; el 403 es exclusivo del autenticado-sin-rol).

**AC4 — Auto-auditoría de la denegación (gratis, D4).**
**Given** una denegación 403 de AC1,
**When** ocurre,
**Then** se emite **una fila `security` `ACCESS_DENIED` síncrona** con `metadata.route` = el nombre de la ruta de audit denegada (comportamiento existente de `AccessDeniedAuditListener`; **no se construye nada nuevo**, solo se asevera).

**AC5 — Atribución real en la escritura (FR15 mitad-atribución, D5/D6).**
**Given** auth real en vigor,
**When** se sella una entrada de auditoría dentro de una **request autenticada** (captura `onFlush` de E1),
**Then** `actor_id` lleva el **UUID real del actor** y `actor_type = user`; el read model (timeline/detalle) expone ese UUID en `actorId`.

**AC6 — Nullable preservado, cero migración (D5, NFR2).**
**Given** una escritura `system`/fuera de request (CLI, worker, scheduler),
**When** se sella su entrada,
**Then** `actor_id` sigue **`NULL`** por diseño; la columna permanece nullable y **`make db.diff` no genera migración** (esquema intacto).

**AC7 — Las tres rutas de atribución testeadas (D6).**
El nuevo impl de `ActorContextFactory` cubre en unit test: **autenticado → `forUser($uuid)`**, **request en vuelo sin token → `anonymous()`**, **fuera de request → `system()`**.

**AC8 — Sin scope-creep ni deriva de contrato.**
No se añade Voter a medida, ni marker de error, ni se edita `docs/api-error-contract.md`, ni `access_control` en `security.yaml`, ni ramifica lógica de `Domain`/`Application` por rol. **No se levanta** el gate de producción de #377/trail (eso es 3.3, SI-3) ni se construye la auto-auditoría de la lectura **concedida** (eso es 3.2, D7).

## Tasks / Subtasks

- [x] **T1 — RBAC declarativo en los dos controllers de lectura (AC1, AC2, AC3, AC4, AC8)**
  - [x] Añadir `#[IsGranted('ROLE_AUDIT_READER')]` a `AuditTimelineSearchController` (`src/Backoffice/Audit/Infrastructure/Controller/AuditTimelineSearchController.php`).
  - [x] Añadir `#[IsGranted('ROLE_AUDIT_READER')]` a `AuditEventDetailController` (`src/Backoffice/Audit/Infrastructure/Controller/AuditEventDetailController.php`).
  - [x] **No** crear clase Voter: el `RoleVoter` built-in de Symfony es el voter que pide FR13 (D4, YAGNI — sin autorización por recurso/fila aún). **No** añadir marker ni tocar el error-contract: `AccessDeniedException` ya mapea a 403 `forbidden` en `ProblemDetailsFactory`.
  - [x] **Boy-scout (barrer comentarios ya stale que estos ficheros contienen):** eliminar los docblocks «Conscious public route / public pre-auth» de `AuditTimelineSearchController` (líneas ~22-24), `AuditEventDetailController` (~21-23) y `AuditEventDetailResource` (~13, «the route is public pre-auth»). Son change-relative/obsoletos: la ruta deja de ser pública en esta story.
- [x] **T2 — Atribución real de actor: swap del impl de `ActorContextFactory` (AC5, AC6, AC7)**
  - [x] Exponer el UUID del usuario autenticado: añadir `SecurityUser::id(): string` (`src/Backoffice/Identity/Infrastructure/Security/SecurityUser.php`) devolviendo `User::getId()` (público vía trait `Identifiable`), **null-guardado** (nunca lanzar en el hot-path — ver nota #435). Hoy `SecurityUser` solo expone email vía `getUserIdentifier()`.
  - [x] Nuevo impl del puerto `ActorContextFactory` en **`Backoffice/Identity/Infrastructure/Security`** (p. ej. `SecurityActorContextFactory`) con `#[AsAlias(ActorContextFactory::class)]`, inyectando Symfony `Security` (o `TokenStorageInterface`) + `RequestStack`: si hay un `SecurityUser` full-fledged en el token → `ActorContext::forUser($securityUser->id())`; si hay request en vuelo sin token → `ActorContext::anonymous()`; fuera de request → `ActorContext::system()`.
  - [x] Retirar `RequestStackActorContextFactory` (`src/Shared/Audit/Infrastructure/`) y mover/extender su test. **Razón de reubicación (decisión de diseño, ver Dev Notes):** `Shared/Audit` no puede importar `Backoffice/Identity` (`SecurityUser`) sin violar `php.lint.bounded-context`/deptrac; `Backoffice/Identity/Infrastructure` sí puede importar `SecurityUser` + el puerto Shared (Shared siempre es importable). El puerto, su firma y todo aguas abajo quedan intactos (NFR2).
  - [x] Verificar que ningún otro componente cambia: `SealedAuditEntryFactory` (único consumidor del puerto, sigue llamando `current()`), `AuditWriteCaptureListener.onFlush`, `DbalAuditLogWriter` (bindea `NULL` sin cambios), read model (`DbalAuditTimelineRepository`) — todos ya nullable-clean.
- [x] **T3 — Tests (AC1–AC7)**
  - [x] **Unit** del nuevo `SecurityActorContextFactory`: las 3 rutas de AC7 + el caso token-presente-pero-no-`SecurityUser` → fallback anónimo (no 500). Unit de `SecurityUser::id()`.
  - [x] **Functional (rompen hoy → arreglar):** `AuthenticatesFunctionalRequests` crea `functional@erpify.test` con roles `[]` → tras `#[IsGranted]` los dos functional tests de audit (`AuditTimelineSearchCursorFunctionalTest`, `AuditEventDetailFunctionalTest`) darían 403. Conceder `AUDIT_READER` a ese usuario. **Gotcha:** el find-or-create del trait es idempotente por email — si ya persiste una fila sin rol, o se actualiza el rol en la fila existente o se usa un email fresco; que un row role-less viejo no cause 403 fantasma.
  - [x] **Behat negativo (403):** `SecurityContext` auto-loguea a Alice (que **ya tiene `AUDIT_READER`** → `timeline.feature` sigue verde sin tocar). Para el 403 hace falta un usuario **autenticado sin el rol**: añadir fixture role-less (p. ej. `mallory@erpify.test` roles `[]`) + un step en `SecurityContext` tipo «logged in as a user without `AUDIT_READER`»; escenario asevera **403** + (AC4) la fila `security` `ACCESS_DENIED` con `metadata.route` correcto (usar los contexts de event/log observability de #277).
  - [x] **Behat anónimo (401):** escenario `@anonymous` sobre una ruta de audit → 401.
  - [x] **Behat atribución (AC5):** Alice (autenticada) ejecuta una escritura auditada (p. ej. crear/editar un `Bank`) → asertar que la fila de auditoría lleva `actor_id` = UUID de Alice y `actor_type = user` (no `NULL`/`anonymous`), vía el read model o los contexts de observabilidad.
  - [x] **Cobertura de la ruta B (detalle):** hoy solo existe `features/backoffice/audit/timeline.feature`; añadir cobertura de `/audit/events/{id}` (403/200) — o Behat nuevo o apoyarse en el functional existente.
- [x] **T4 — Gates y verificación (AC6, AC8)**
  - [x] `make db.diff` → **vacío** (probar que no hay cambio de esquema). `make php.stan` en cada fichero tocado. `make php.quality` (deptrac + bounded-context + error-contract EXIT 0). `make php.psalm.taint`. `make php.unit` (suite completa). `make php.behat` (suite completa).
  - [x] **Live check** sobre el stack HTTPS: `curl -k` a las dos rutas → 401 sin sesión, 403 autenticado-sin-rol, 200 con `AUDIT_READER`.
- [x] **T5 — Docs**
  - [x] Actualizar `docs/architecture-api.md` si el impl de atribución se reubica (nueva clase en `Backoffice/Identity/Infrastructure/Security`, `RequestStackActorContextFactory` retirado). **No** tocar `docs/api-error-contract.md` (sin cambios de contrato). **No** cerrar `PRODUCTION_SECURITY_CHECKLIST.md`/`docs/rules/security.md` (eso es 3.3).
  - [x] Barrer del propio diff cualquier ID de story/FR/NFR/AC en comentarios de código antes del commit final (viven en spec/PR, no en el código).

## Dev Notes

### Alcance exacto — qué es y qué NO es 3.1 (anti-scope-creep)

3.1 = **D4 + D5 + D6**. Dos mitades: (1) `#[IsGranted]` en las 2 rutas de lectura; (2) swap de `ActorContextFactory` para atribución real. **FR15 es compuesto** — su texto mezcla «atribución real **+ puesta en producción**»; 3.1 implementa **solo la atribución**. Trampas verificadas a evitar:

| Trampa | Realidad | Dueño |
|---|---|---|
| «incluida la #377» ⇒ productizar #377 | Añadir el voter a la ruta #377 ≠ levantar su gate de prod (SI-3 la mantiene fuera de prod hasta 3.1 **y** 3.2) | 3.3 |
| Auto-auditar la lectura **concedida** (FR14) | D7 es explícito: `#[IsGranted]` cubre la **denegación** (403 + audit gratis); la auto-auditoría de la lectura concedida es un **listener durable aparte** sobre la respuesta exitosa | 3.2 |
| `actor_id NOT NULL` / migración | D5 ratifica columna **nullable**; «atribución real» = invariante de costura, no DDL | — (nunca) |
| Voter propio / marker / editar error-contract | Built-in `RoleVoter`; 403 por `Forbidden`+`ProblemDetailsFactory` ya existente | — (nunca) |
| Ramificar por rol en Domain/Application | SI-5/D3: roles = autorización externa; sin `isAdmin()`, sin branch por rol; única autz = `#[IsGranted]` en el borde Infra | — (nunca) |

### Parte 1 — enforcement surface (verificado contra código del worktree)

- **Rutas (exactamente 2), hoy sin atributo de seguridad:** `AuditTimelineSearchController` → `#[Route('/audit/timeline', name: self::ROUTE_NAME, methods: ['GET'])]` (:26, read-model keyset #374); `AuditEventDetailController` → `#[Route('/audit/events/{id}', name: 'backoffice_audit_event_detail', methods: ['GET'])]` (:25, detalle/#377, guarda `{id}` con `Uuid::ensure`). Prefijo `/api/v1/backoffice` (routes.yaml).
- **Posture actual (post-#434):** ambas caen **solo** en el catch-all `access_control` `^/api → IS_AUTHENTICATED_FULLY`. Hoy cualquier autenticado lee. 3.1 **añade una capa de rol encima** vía `#[IsGranted]` — **no tocar `access_control`** (el comentario de `security.yaml` confirma: «una ruta protegida no necesita config»).
- **Mapeo de rol:** `SecurityUser::getRoles()` (:41-48) emite `'ROLE_'.$role->value` → `Role::AUDIT_READER` (value `'AUDIT_READER'`, único case en `src/Backoffice/Identity/Domain/Enum/Role.php`) surfacea como `ROLE_AUDIT_READER`, exactamente el string del atributo. Dirección **unidireccional** dominio→Infra→Symfony; el dominio nunca ve el prefijo `ROLE_`.
- **403 ya conforme:** `ProblemDetailsFactory::fromThrowable` tiene arm dedicado `AccessDeniedException → forbidden / 403` (:258-271). Sin marker nuevo, sin cambio de contrato.
- **Denegación auto-auditada gratis:** `AccessDeniedAuditListener` (`kernel.exception` prio 32) emite `security` `ACCESS_DENIED` síncrono con `metadata.route` para cualquier `AccessDeniedException` en `/api`. Cardinalidad-1 de acción; la ruta va en metadata.
- **401 vs 403 ya correcto:** `UnauthenticatedAccessListener` (prio 40, #434) reescribe la `AccessDeniedException` **anónima** → `InsufficientAuthenticationException` → 401; el guard `isFullFledged` retorna temprano para el autenticado → su `AccessDeniedException` sobrevive → 403. Pinneado por `UnauthenticatedAccessListenerTest::testLeavesAnAuthenticatedDenialUntouchedSoItStaysA403()`. **3.1 no toca este listener**, solo lo asevera end-to-end sobre las rutas de audit.

### Parte 2 — costura de atribución (verificado)

- **VO `ActorContext`** (`src/Shared/Audit/Domain/ActorContext.php`): `final readonly`, ctor privado, factories cerradas — `anonymous()`/`system()` (id `null`), `forUser($id)`/`forApiKey($id)` (validan UUID vía `withValidatedId`, lanzan `InvalidActorContext` si no). `actorId` es `?string` público. **`forUser` ya existe y valida → el VO no cambia** (D5 descartó UUIDs centinela justo para no tocarlo).
- **Puerto** `ActorContextFactory` (`src/Shared/Audit/Application/`): `current(): ActorContext`. **Impl actual** `RequestStackActorContextFactory` (`src/Shared/Audit/Infrastructure/`, `#[AsAlias]`): retorna `anonymous()` si hay `Request` en vuelo, `system()` si no. Es la **única** clase a reemplazar.
- **Único consumidor del puerto:** `SealedAuditEntryFactory` (:60 `current()`), que sella actor+correlation+instante+id dentro del ciclo de request y alimenta `onFlush` (write), `AccessLogAuditListener` y `AccessDeniedAuditListener` (security). Al swappear el proveedor de actor, **las tres vías mejoran a la vez** sin tocarlas (NFR2 «cero retrabajo»).
- **Obtener el UUID:** `SecurityUser` **solo** expone email (`getUserIdentifier()`); no hay accessor de id. El `User` envuelto sí: `Identifiable::getId(): ?string` público (UUID v7). `AggregateRoot::id()` es `protected` → inalcanzable. **Sub-task obligatoria:** `SecurityUser::id(): string` null-guardado.
- **Invariante nullable (D5/D9 tier-1):** `system`/off-request → `actor_id = NULL` por diseño (no hay humano). `forUser` **solo** cuando hay usuario autenticado full-fledged en el token; si no, cae a la lógica request-presence actual (`anonymous`/`system`). Confirmado `actor_id UUID DEFAULT NULL` en `migrations/2026/Version20260623164321.php:19` — **no migrar**.

### Decisión de diseño load-bearing — dónde vive el nuevo impl (recomendación, confirmar)

`RequestStackActorContextFactory` está en `Shared/Audit/Infrastructure`, pero necesita `SecurityUser` (`Backoffice/Identity`). **Shared no puede importar un contexto de negocio** (`make php.lint.bounded-context` / deptrac).

- **Opción A (recomendada) — reubicar** el impl a `Backoffice/Identity/Infrastructure/Security` (`SecurityActorContextFactory implements Shared\...\ActorContextFactory`, `#[AsAlias]`). Ahí importa legalmente `SecurityUser` + Symfony Security + el puerto Shared (Shared siempre importable). Retira `RequestStackActorContextFactory`. **Principio:** DIP limpio + `Backoffice/Identity` es el hogar natural de la identidad (ADR D6: «es un componente de *seguridad*, no sólo de auditoría»). **Objetivo:** deptrac verde sin excepciones; puerto/firma/aguas-abajo intactos (NFR2). **Coste/alternativa descartada:** Opción B — mantenerlo en Shared + un puerto Shared `AuditableActor` que `SecurityUser` implemente y el factory lea por él (evita el import cruzado). Más piezas y una interfaz por un solo consumidor (YAGNI) → pierde. Precedente de acceso al token en esta rama: `UnauthenticatedAccessListener` inyecta `TokenStorageInterface`.

### Regresiones y hot-path (#435)

`refreshUser` corre **cada request** (firewall stateful sesión) → `User::roles()`/`passwordHash()` ya están en el hot-path y pueden lanzar ante datos corruptos (issue #435, abierto, fuera de scope). La nueva lectura del UUID **no** ensancha ese surface (`getId()` es `?string` plano, sin re-hidratar enum/VO) **siempre que** `SecurityUser::id()` se null-guarde y nunca lance (fallback anónimo, jamás 500). No resolver #435 aquí; solo no empeorarlo.

### Follow-up relacionado fuera de scope

Issue **#437** (cursor keyset cross-route = posible privilege-bypass bajo RBAC): **no** se aborda en 3.1 — ambas rutas de audit comparten `ROLE_AUDIT_READER`, así que no hay escalada intra-audit; #437 es una preocupación genérica del motor keyset entre rutas con autz distinta. Dejar nota, no ampliar el diff.

### Estándares de test (patrones del repo)

- **Unit** de listeners/factories: `tests/Unit/<mismo-namespace>/`, `final class …Test extends TestCase`, `#[CoversClass(...)]`, dobles hechos a mano (ver `UnauthenticatedAccessListenerTest`, `RequestStackActorContextFactoryTest` existentes). Ojo PHPMD `CouplingBetweenObjects` (≤13) también en tests → mantener lean.
- **Behat** `SecurityContext` (`tests/Behat/Context/SecurityContext.php`): `#[BeforeScenario]` auto-loguea Alice (`alice@erpify.test`, ya con `AUDIT_READER`) salvo tag `@anonymous`; usa `client->loginUser(new SecurityUser($user),'main')` (sin round-trip HTTP, sin query contada). Fixtures en `tests/DataFixtures/Fixtures/User.yaml`; `UserFixtureFactory::create(id,email,plainPassword,array $roleValues=[])`.
- **Functional** (`tests/Functional/...`): trait `AuthenticatesFunctionalRequests` (**no** usa DAMA rollback → create idempotente); los 2 tests de audit lo consumen.
- Budgets de query Behat: `loginUser` no añade query contada, pero `refreshUser` (`findByEmail` sobre `identity_user`) ya se **excluye** en `TestDebugDataHolder.addQuery` por tabla (AF-1.3). No re-tocar eso.

### Project Structure Notes

- Nuevo: `src/Backoffice/Identity/Infrastructure/Security/SecurityActorContextFactory.php` (Opción A) + `SecurityUser::id()`. Registro deptrac: `Backoffice/Identity/Infrastructure` ya declarado (AF-1.1); reusa layers. Auto-wiring por `services.yaml` `Erpify\: '../src/'` + `#[AsAlias]` → cero edición de `services.yaml`.
- Retirado: `src/Shared/Audit/Infrastructure/RequestStackActorContextFactory.php` (+ su test movido/extendido).
- Modificados: los 2 controllers de audit (atributo + barrido de docblock), `AuditEventDetailResource` (barrido docblock).
- **Sin** cambios en: `security.yaml` (`access_control`), migraciones, `api-error-contract.md`, VO `ActorContext`, puerto `ActorContextFactory`, writer/entry/read-model.

### References

- [Source: `_bmad-output/planning-artifacts/epics-regulatory-audit-trail.md#story-31`] — user story + ACs (FR13/FR14/FR15/NFR2).
- [Source: `_bmad-output/planning-artifacts/arch-addendum-auth-rbac.md#system-invariants`] — SI-1..5 (costura única de identidad, framework confinado, gate de prod, errores por contrato, roles=autz externa).
- [Source: `_bmad-output/planning-artifacts/arch-addendum-auth-rbac.md#localización-de-decisiones-por-pr`] — Story 3.1 = D4+D5+D6; 3.2 = D7; 3.3 = gate.
- [Source: `docs/adr/auth-rbac-subsystem.md#d4`] — `#[IsGranted('ROLE_AUDIT_READER')]` + built-in RoleVoter; 403 por pipeline existente; sin marker; Voter propio descartado (YAGNI).
- [Source: `docs/adr/auth-rbac-subsystem.md#d5`] — `actor_id` nullable; atribución real = invariante de costura, no DDL; NOT NULL + centinelas y CHECK descartados.
- [Source: `docs/adr/auth-rbac-subsystem.md#d6`] — `ActorContextFactory` = única costura autorizada; nueva impl (Infra) inyecta Security → token → UUID → `forUser`; nadie más lee el token.
- [Source: `docs/adr/auth-rbac-subsystem.md#d7`] — FR14 (auto-auditoría de lectura **concedida**) = listener durable aparte = **Story 3.2**, no 3.1.
- [Source: `docs/adr/audit-activity-log.md#d3`] — durabilidad write-before-send de `security` (el «D3 del hermano» que hereda 3.2).
- [Source: `docs/adr/regulatory-audit-trail.md#d5`] — mapeo ISO A.5.18/A.8.15/A.8.17/A.5.12; #d8 gate de prod; sellado de actor en `onFlush`.
- Código verificado: `src/Backoffice/Audit/Infrastructure/Controller/{AuditTimelineSearchController,AuditEventDetailController}.php`; `src/Backoffice/Identity/Infrastructure/Security/{SecurityUser,UnauthenticatedAccessListener,UserProvider}.php`; `src/Shared/Audit/{Domain/ActorContext,Application/ActorContextFactory,Infrastructure/RequestStackActorContextFactory,Infrastructure/SealedAuditEntryFactory,Infrastructure/Persistence/DbalAuditLogWriter}.php`; `migrations/2026/Version20260623164321.php`; `config/packages/security.yaml`; `tests/Behat/Context/SecurityContext.php`; `tests/Functional/AuthenticatesFunctionalRequests.php`; `tests/DataFixtures/Fixtures/User.yaml`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Claude Code, dev-story workflow).

### Debug Log References

- `make db.diff` → «No changes detected» (AC6: esquema intacto, cero migración).
- `make php.stan` (`PHP_SERVICE=messenger_worker`) → No errors.
- `make php.quality` → EXIT 0 (deptrac Violations 0 → Opción A pasa la isolación sin skip nuevo; bounded-context + error-contract + cs-fixer + rector + phpmd + phpcs + gherkinlint sin churn).
- `make php.psalm.taint` → No errors found.
- `make php.unit` → 1500 tests OK (3 skipped preexistentes); nuevos: `SecurityActorContextFactoryTest` (4) + `SecurityUserTest::id()` (1); los 2 functional de audit pasan con el rol concedido (17 tests, 547 asserts).
- `make php.behat` → 204 escenarios OK (2054 steps); +5 escenarios en `access_control.feature`.
- Live `curl -k` (HTTPS worktree): sin sesión → 401 `unauthenticated`; autenticado sin rol → 403 `forbidden` (timeline y detalle); `AUDIT_READER` → 200 (25 filas).

### Completion Notes List

- **T2 (Opción A).** Nuevo `SecurityActorContextFactory` en `Backoffice/Identity/Infrastructure/Security` (`#[AsAlias(ActorContextFactory::class)]`, inyecta `TokenStorageInterface` + `RequestStack`): `SecurityUser` en el token → `forUser($uuid)`; request en vuelo sin `SecurityUser` → `anonymous()`; fuera de request → `system()`. `SecurityUser::id(): ?string` null-guardado (nunca lanza en el hot-path). Retirado `RequestStackActorContextFactory` (+ su test). Puerto/firma/único consumidor (`SealedAuditEntryFactory`) intactos (NFR2).
- **T1.** `#[IsGranted('ROLE_AUDIT_READER')]` en los 2 controllers de lectura (built-in `RoleVoter`, sin Voter propio, sin marker, sin tocar `access_control`/error-contract). Boy-scout: barridos los docblocks «conscious public route / pre-auth» en ambos controllers y en `AuditEventDetailResource`.
- **T3.** `AuthenticatesFunctionalRequests` concede `AUDIT_READER` al usuario funcional (arregla los 2 functional de audit). Fixture role-less `mallory` + step `SecurityContext` «logged in as a user without the audit-reader role». Nueva `features/backoffice/audit/access_control.feature` (401 anónimo, 403 sin rol en timeline y detalle, `ACCESS_DENIED` con `metadata.route` filtrado por correlation-id, 200 detalle con rol). Atribución real (AC5) probada al reflejar la regresión en las 3 vías (`write_capture` change, `access_log` activity, `security_denial` security) + `bank_account/audit`: `actor_type` `anonymous` → `user`, `actor_id` = UUID de Alice.
- **T5.** `docs/architecture-api.md` actualizado: rutas de audit ahora requieren `ROLE_AUDIT_READER`; atribución resuelta por `SecurityActorContextFactory` (Identity), no ya «consciously public / pre-auth». ADRs no tocados (decision records; D6 ya enmarcaba la costura como componente de seguridad).
- **Fuera de scope confirmado:** #435 (datos corruptos en el hot-path) no empeorado (`id()` lee `?string` plano); #437 (cursor keyset cross-route) sin abordar (ambas rutas comparten el mismo rol). Sin gate de prod de #377/trail (eso es 3.3).

### File List

- **Añadidos**
  - `api/src/Backoffice/Identity/Infrastructure/Security/SecurityActorContextFactory.php`
  - `api/tests/Unit/Backoffice/Identity/Infrastructure/Security/SecurityActorContextFactoryTest.php`
  - `api/features/backoffice/audit/access_control.feature`
- **Modificados**
  - `api/src/Backoffice/Audit/Infrastructure/Controller/AuditTimelineSearchController.php`
  - `api/src/Backoffice/Audit/Infrastructure/Controller/AuditEventDetailController.php`
  - `api/src/Backoffice/Audit/Application/Resource/AuditEventDetailResource.php`
  - `api/src/Backoffice/Identity/Infrastructure/Security/SecurityUser.php`
  - `api/tests/Functional/AuthenticatesFunctionalRequests.php`
  - `api/tests/Behat/Context/SecurityContext.php`
  - `api/tests/DataFixtures/Fixtures/User.yaml`
  - `api/tests/Unit/Backoffice/Identity/Infrastructure/Security/SecurityUserTest.php`
  - `api/features/shared/audit/write_capture.feature`
  - `api/features/shared/audit/access_log.feature`
  - `api/features/shared/audit/security_denial.feature`
  - `api/features/backoffice/bank_account/audit.feature`
  - `docs/architecture-api.md`
- **Eliminados**
  - `api/src/Shared/Audit/Infrastructure/RequestStackActorContextFactory.php`
  - `api/tests/Unit/Shared/Audit/Infrastructure/RequestStackActorContextFactoryTest.php`

## Change Log

| Fecha | Cambio |
|---|---|
| 2026-07-03 | Story 3.1 implementada: `#[IsGranted('ROLE_AUDIT_READER')]` en las 2 rutas de lectura del trail + atribución real de actor vía `SecurityActorContextFactory` (Opción A: reubicado a `Backoffice/Identity`, `RequestStackActorContextFactory` retirado). Puerto/esquema intactos (NFR2/AC6). Status → review. |

## Review Findings

Code review adversarial (Blind Hunter · Edge Case Hunter · Acceptance Auditor) — 2026-07-04. Veredicto del Acceptance Auditor: implementación fiel y completa (AC1–AC8 satisfechas, sin scope-creep). Los hallazgos son robustez de tests y una divergencia de invariante no alcanzable hoy.

### Decision-needed (resuelto)

- [x] [Review][Decision] Atribución off-request con token contradice el invariante «off-request → system» (AC6/AC7). **RESUELTO 2026-07-04 → reforzar el código.** Ver patch P5.

### Patch

Todos aplicados y verificados el 2026-07-04 (`php.stan` · `php.unit` 1501 OK · `php.behat` 205 OK · `php.quality` EXIT 0).

- [x] [Review][Patch] P5: Reforzar `current()` para honrar «off-request → system» siempre [api/src/Backoffice/Identity/Infrastructure/Security/SecurityActorContextFactory.php] — **APLICADO**: se comprueba la presencia de request primero (`system()` incondicional fuera de request), y sólo dentro de una request en vuelo se atribuye `forUser`/`anonymous`. Nuevo unit test `testAnAuthenticatedTokenOffRequestStillResolvesToSystem`. Cierra el matiz «full-fledged token» (Blind #4). Docblock reescrito y suavizado (retira el «cannot turn into a 5xx» absoluto — parte opcional del defer F6).
- [x] [Review][Patch] El trait funcional concede `AUDIT_READER` sólo en la rama de creación → 403 fantasma en BD tibia [api/tests/Functional/AuthenticatesFunctionalRequests.php] — **APLICADO**: una fila almacenada sin `AUDIT_READER` se elimina y recrea con el rol (el agregado `User` no tiene mutador de roles por diseño). Idempotente en BD fresca; robusto en BD persistente.
- [x] [Review][Patch] `security_denial.feature` cuenta `ACCESS_DENIED` sin filtrar → colisión con la nueva `access_control.feature` [api/features/shared/audit/security_denial.feature:14] — **APLICADO**: el `SELECT` se acotó con `AND correlation_id = '0190dead-beef-7abc-8def-001122334455'`. Verificado corriendo `backoffice/audit` + `shared/audit` juntas (la ordenación que dispara la colisión) → 23 escenarios verdes.
- [x] [Review][Patch] La rama de fallback por id nulo no tiene cobertura unit [api/tests/Unit/Backoffice/Identity/Infrastructure/Security/SecurityActorContextFactoryTest.php] — **RESUELTO POR ANÁLISIS, sin test**: la rama es inalcanzable en runtime — `User::__construct` asigna siempre `$this->id` desde un `string` no-nulo, así que `SecurityUser::id()` nunca devuelve null para un `SecurityUser` real; el guard `?string` es sólo del tipo (PHPStan max), no un camino ejecutable. `SecurityUser` es `final readonly` → un test exigiría forjar un `User` inválido por reflexión (test frágil sobre código muerto). El guard defensivo se mantiene (lo pide el tipo); no se fuerza un test contrived. La línea de retorno `anonymous()` sí queda cubierta por otras rutas (no hay hueco de `new_coverage`).
- [x] [Review][Patch] Huecos de aserción en la ruta de detalle (AC3/AC4/AC5 fijados sólo para timeline) [api/features/backoffice/audit/access_control.feature] — **APLICADO**: (a) escenario anónimo-401 para `/audit/events/{id}`; (b) el 403 de detalle ahora asevera la fila `ACCESS_DENIED` con `metadata.route = backoffice_audit_event_detail`; (c) el escenario 200 asevera `data.actorId`. Fold Blind #8: la rama not-`SecurityUser` del unit test asevera también `actorId` null.

### Defer

- [x] [Review][Defer] Un id no-nulo no-UUID haría lanzar a `ActorContext::forUser()` → 5xx en las vías síncronas `security`/`change`; el docblock sobre-afirma «cannot turn into a 5xx» [api/src/Backoffice/Identity/Infrastructure/Security/SecurityActorContextFactory.php:498] — defence-in-depth: no alcanzable en producción (`Identifiable::$id` es UUID v7 o null bajo `#[Assert\Uuid(strict:true)]`); misma clase de dato-corrupto-en-hot-path que rastrea el issue abierto **#435** (fuera de scope declarado). Deferido a **#435**. Opcional: suavizar el docblock para no garantizar lo que el código no cubre.
