---
baseline_commit: 3ec79a0372d725a7e46b8a7e77f88ee01a750d1d
---

# Story 1.7 (U-5b): Borrado GDPR desde la consola

Status: done

<!-- Validación opcional: correr `validate-create-story` antes de `dev-story` para un check de calidad. -->

> **Casi todo el sustrato ya existe y está testeado en producción.** Lo único *net-new* en el backend es el
> Application Service orquestador **`FulfilIdentityErasure`**; en la PWA, el componente `UserEraseControl` + el
> mecanismo **type-to-confirm** (sin precedente en el repo). El resto se **reutiliza / espeja** — lee el *Reuse
> map* (Dev Notes) antes de escribir una sola línea, es lo que impide reinventar `EraseIdentitySubject`, el
> anonimizador del rastro, o el guard ≥1-ADMIN.

## Story

Como **ADMIN**,
quiero ejecutar el borrado GDPR de una identidad desde la consola, con confirmación irreversible,
para cumplir una solicitud de derecho al olvido sin depender de que un desarrollador ejecute una CLI.

**Comportamiento que introduce:** la superficie de cumplimiento del erase en la consola.
**Invariantes que consume:** SI-19 (fricción ∝ irreversibilidad), ≥1 ADMIN activo (NFR6), el cierre de #376 (U-5a).
**Invariantes que establece:** el erase des-identifica **identidad + rastro de auditoría como una unidad**.

## Contexto (leer antes de tocar código)

U-5b de la épica `users-admin` (orden safe-first `U-0 → U-1 → (U-2 · U-3) → U-4 · [U-5a → U-5b]`). U-0…U-5a están
**done/merged**. U-5a (cerrar #376 — retirar la cola `activity` de `Shared/Audit`) es **prerrequisito duro** y ya
está cerrado, así que **no existe** consumidor async capaz de re-insertar un `actor_id` ya anonimizado: puedes
exponer el erase en UI sin reabrir esa ventana.

**Qué ya existe y se reutiliza (no reinventar):**

- `EraseIdentitySubject::execute(userId)` — hard-delete de la identidad + borrado de reset tokens + audit
  `GDPR_SUBJECT_ERASED`, en **una** transacción, idempotente. Se usa **tal cual** como el eslabón de identidad.
- `DbalAuditActorAnonymiser::anonymise(actorId)` — `UPDATE audit_log` que anonimiza `actor_id` (pseudónimo UUIDv7
  único por sujeto), `ip`, `user_agent`, y marca `actor_erased = TRUE`. Idempotente. Es el eslabón de rastro.
- `ActiveAdministratorDirectory::keepsAnActiveAdminWithout(userId)` (+ `LastActiveAdministratorProtected` → 409) —
  el guard ≥1-ADMIN, con lock `FOR UPDATE`. Ya lo usan `ChangeUserStatus` (U-3) y `ChangeUserRoles` (U-4).
- El permiso **`users.erase` ya está registrado y test-pinned** (policy + catálogo) — solo se referencia, no se añade.
- `TransactionManager::transactional` = `EntityManagerInterface::wrapInTransaction`, que **anida**: envolver
  `EraseIdentitySubject::execute()` dentro de una transacción externa de `FulfilIdentityErasure` hace que la
  interna se una y todo comitee/rollee **atómicamente** sobre la misma `Connection`.

**Qué es net-new:**

1. **API:** `FulfilIdentityErasure` (orquestador) + controlador HTTP `#[IsGranted('users.erase')]` + tests.
2. **PWA:** `UserEraseControl` (detalle) + type-to-confirm + use case/puerto/adapter/DI + tests.

## Acceptance Criteria

**AC1 — Prerrequisito duro (#376) satisfecho.**
**Given** que U-5a cerró la cola `activity`,
**Then** ninguna escritura de auditoría en vuelo puede re-insertar un `actor_id` ya anonimizado; U-5b **no**
re-introduce ese camino async. *(Consumido, no re-verificado aquí más allá de no reabrirlo.)*

**AC2 — El erase es un único caso de uso encadenado y atómico.**
**Given** un ADMIN autenticado,
**When** ejecuta el borrado GDPR con confirmación type-to-confirm,
**Then** un **único Application Service `FulfilIdentityErasure`** encadena, en **una transacción atómica**,
`EraseIdentitySubject` (hard-delete identidad + reset tokens + audit `GDPR_SUBJECT_ERASED`) **+** anonimización
del rastro (`AuditActorAnonymiser::anonymise` sobre `actor_id`/`ip`/`user_agent`); un fallo en cualquier eslabón
**rollea todo** (no queda identidad medio-borrada ni rastro medio-anonimizado). Re-ejecutar es seguro (idempotente).

**AC3 — Gateo ADMIN-only + RFC 9457.**
**Given** el endpoint de erase,
**When** lo invoca quien **no** tiene `users.erase`,
**Then** responde **403 `forbidden`** (`#[IsGranted('users.erase')]`); un id malformado → **400 `invalid-uuid`**
(`Uuid::ensure`); un id desconocido → **404 `user-not-found`**; todos por el pipeline RFC 9457, nunca body manual.
`make php.lint.error-contract` verde (se reutilizan marcadores existentes; ningún marcador nuevo).

**AC4 — Guard ≥1 ADMIN activo (NFR6).**
**Given** el último ADMIN activo,
**When** se intenta erasar,
**Then** se rechaza con **409 `last-active-administrator-protected`**; el guard corre **dentro** de la transacción
del erase (lock `FOR UPDATE`, antes del delete) para ser race-safe. No puede dejar la org sin ADMIN.
> **Sobre HTTP este 409 es inalcanzable directamente** (consecuencia de D7, ver Completion Notes): la única forma de
> apuntar al último ADMIN es que sea uno mismo, y el auto-borrado se rechaza antes con 409 `self-erasure-forbidden`.
> El guard ≥1-ADMIN queda como defensa off-request (CLI, actor `system`), **cubierto a nivel unit + CLI**; HTTP/Behat
> ejercitan el rechazo de auto-borrado (incluido el id propio en distinto case, que también debe rechazarse).

**AC5 — Atribución: negocio intacto, rastro des-identificado.**
**Given** un usuario erasado,
**When** se consulta el audit trail y los datos de negocio,
**Then** su `actor_id` está anonimizado (pseudónimo único, `actor_erased = TRUE`) y su PII redactada, y **ningún**
registro de negocio que creó/actualizó/borró se toca (no hay cascada — las filas de negocio no guardan autor).

**AC6 — Superficie PWA separada, guardada e inconfundible (UX-DR6, SI-19).**
**Given** el detalle de un usuario,
**When** se muestra el borrado GDPR,
**Then** es una acción «Borrado GDPR (irreversible)» **separada** de deactivate, con **lenguaje visual destructivo
distinto** (nunca confundible con «quitar»/deactivate), **ADMIN-only** (`<Can permission={USERS_ERASE}>`), con
**type-to-confirm** (el botón de confirmar no se habilita hasta que el texto tecleado coincide con la frase
requerida) y **aviso explícito de que des-identifica el rastro de auditoría**. En éxito, redirige a la lista
(el detalle ya no existe). La CLI se mantiene (additiva).

**AC7 — Contrato de permiso idéntico byte-a-byte (SI-20).**
**Given** el string `users.erase`,
**Then** es idéntico en API (`#[IsGranted('users.erase')]`) y PWA (`Permission.USERS_ERASE = "users.erase"`).
Ambos ya existen; se **confirma**, no se re-declara.

**AC8 — Las sesiones del sujeto se borran como parte de la unidad atómica.**
**Given** un usuario con sesiones vivas,
**When** se erasa,
**Then** sus filas `iam_session` se **hard-deletean dentro de la misma transacción** que el erase (no soft-revoke,
no post-commit best-effort), de modo que no quede **PII residual** (`ip`/`device`/`user_id`) de un sujeto ya
inexistente; un fallo del borrado de sesión **rollea todo** (falla ruidoso → reintento idempotente), nunca deja
residuo en silencio. *(Decisión D4 — endurecida sobre el consenso interno; ver Decisiones.)*

**AC9 — Sin regresión + gates verdes.**
`make php.quality` (incl. deptrac + error-contract), `make php.unit`, `make php.behat`, `make pwa.quality`,
`make pwa.test`. La suite existente de identidad/audit sigue verde.

## Tasks / Subtasks

> Antes de codificar: resolver las **Decisiones D1–D6** (Dev Notes). Las marcadas *(recomendada)* traen una opción
> por defecto; confirma con Sergio las que cambian comportamiento ya mergeado (D3 CLI, D4 sesiones).

### A — API: `FulfilIdentityErasure` (AC2, AC4, AC5) · el orquestador net-new

- [x] A1. Crear `api/src/Iam/Identity/Application/FulfilIdentityErasure.php` — `final readonly`, inyecta
      `EraseIdentitySubject`, `AuditActorAnonymiser` (`Shared/Audit/Application` — importable), `ActiveAdministratorDirectory`,
      `TransactionManager` (y `AuditLogger` si emite self-audit combinado, D5). Método `execute(string $userId): <Result>`.
- [x] A2. `Uuid::ensure($userId)` en el borde (antes de la transacción → 400 `invalid-uuid`).
- [x] A3. Envolver en `transactionManager->transactional(fn () => …)` **una sola** unidad de trabajo, en este orden:
      (1) guard `if (!keepsAnActiveAdminWithout($userId)) throw LastActiveAdministratorProtected::forUser($userId)`
      — race-safe por el `FOR UPDATE`; (2) `$result = $eraseIdentitySubject->execute($userId)` (su transacción
      **anida** y se une); (3) si `!$result->identityErased` → `throw UserNotFound::withId($userId)` (D2, 404);
      (4) `$anon = $auditActorAnonymiser->anonymise($userId)`; (5) self-audit combinado (D5, si se decide);
      (6) hard-delete de las sesiones del sujeto vía el seam publicado de `Iam/Session` (**dentro** de la misma tx —
      participa en la Connection ambiente; ver A7). El orden de (4)-(6) es libre (sin FK entre las tablas).
- [x] A4. Verificar que `DbalAuditActorAnonymiser` inyecta la **`Connection` por defecto** (la que envuelve el EM) —
      lo hace hoy; es la premisa de la atomicidad cross-módulo. Si algún día usara otra conexión, la garantía cae.
- [x] A5. Test unit `FulfilIdentityErasureTest` con dobles in-memory (mirror `EraseIdentitySubjectTest` +
      `RecordingAuditLogger` + doble de `ActiveAdministratorDirectory`): éxito encadena ambos eslabones; último-ADMIN
      lanza 409 y **no** erasa nada (rollback); id desconocido → `UserNotFound`; re-ejecución idempotente.
- [x] A6. **Título 409 correcto para erase.** `LastActiveAdministratorProtected::forUser` hard-codea el title «Cannot
      suspend or deactivate the last active administrator…» (`api/src/Iam/Identity/Domain/Exception/LastActiveAdministratorProtected.php:24`),
      engañoso en un erase. Añadir un factory `::forErasure($userId)` con title propio (o generalizar a «…cannot be
      removed») y apuntar A3 a él. El `type`/estado (`last-active-administrator-protected`, 409) **no** cambian.
- [x] A7. **Borrado de sesiones dentro de la tx (D4 endurecida).** Añadir un seam publicado en `Iam/Session`
      (Application service / puerto `deleteAllForUser(string $userId): int` → `DELETE FROM iam_session WHERE user_id=:id`,
      DQL/DBAL param.), invocado por `FulfilIdentityErasure` **dentro** de `transactional{}` (misma Connection → atómico;
      **no** reutilizar `RevokeSessionsBestEffort`, que es soft + best-effort). Omitir el evento `AllSessionsRevoked`
      (un sujeto borrado no necesita señal «revocada»; la observabilidad de sesión vive en eventos PII-free → la fila es
      descartable). Cruce `Iam/Identity`→`Iam/Session` por el seam Application publicado (como hoy `RevokeSessionsBestEffort`),
      nunca reach-in al repo. Test: unit — un fallo del borrado de sesión **rollea** el erase (nada erasado);
      functional/Behat — sembrar ≥1 `iam_session` (`user_id=subject`, `ip`/`device` no nulos) y asertar
      `COUNT(*) FROM iam_session WHERE user_id = subject == 0` tras el 204.

### B — API: superficie HTTP (AC3, AC4) · espejo de `UserPatchStatusController`

- [x] B1. Crear `api/src/Iam/Identity/Infrastructure/Controller/UserEraseController.php` — `final`, un solo
      `__invoke(string $id): Response`, `#[Route('/backoffice/users/{id}', name: 'backoffice_user_erase', methods:
      ['DELETE'])]` (hereda el prefijo `/api/v1` de `api/config/routes.yaml` → `DELETE /api/v1/backoffice/users/{id}`),
      `#[IsGranted('users.erase')]`. Delega en `FulfilIdentityErasure::execute($id)`.
- [x] B2. Respuesta: **`204 No Content`** (D6 recomendada — hard-delete, nada que serializar; precedente: el invite
      devuelve body vacío). No usar `UserDetailResource` (la fila ya no existe). Errores por el pipeline RFC 9457
      (no `JsonResponse` manual).
- [x] B3. Al aterrizar el endpoint, **actualizar los docblocks** que declaran «sin endpoint aún»: `PermissionCatalog.php:29`
      (frase literal «no endpoint yet») y `StaticAuthorizationPolicy.php:49-54` («`erase` is listed ahead of the GDPR use
      case that reaches it» / «functionally redundant for ADMIN») — ambos dejan de ser ciertos al cablear la ruta.
- [x] B4. `PermissionCatalogCoversEveryGatedRouteTest` debe seguir verde (el catálogo es superset de rutas gateadas;
      cablear la ruta **estrecha**, no rompe).
- [x] B5. Test funcional `UserEraseFunctionalTest` (mirror `UserPatchStatusFunctionalTest`, trait
      `AuthenticatesFunctionalRequests`): ADMIN → 204; MANAGER/AUDIT_READER → 403; último-ADMIN → 409
      `application/problem+json`; id malformado → 400; id desconocido → 404. Asserta que se escribió la fila
      `GDPR_SUBJECT_ERASED` (firmada por el ADMIN **actuante**, no el sujeto) y que las filas de auditoría **del sujeto**
      quedaron `actor_erased = TRUE`. **Seed obligatorio:** el sujeto debe tener filas con `actor_id = subjectId`
      **antes** del erase (que ejecute una acción auditable, o siémbralas) — si no, la aserción de anonimización corre
      sobre un conjunto vacío y no prueba nada. **Sesiones (D4/A7):** sembrar ≥1 `iam_session` (`user_id=subject`,
      `ip`/`device` no nulos) y asertar `SELECT COUNT(*) FROM iam_session WHERE user_id = subject == 0` tras el 204.
- [x] B6. **Behat `api/features/backoffice/users/erase.feature`** (matriz de `status.feature`): 204 éxito, 401
      `unauthenticated`, 403 `forbidden` (viewer + audit-reader), 400 `invalid-uuid`, 404 `user-not-found`, 409
      `self-erasure-forbidden` (incluido el id propio en distinto case; el 409 `last-active-administrator-protected`
      es inalcanzable por HTTP → cubierto en unit + CLI, ver AC4); assert del row `GDPR_SUBJECT_ERASED` y de la
      anonimización (con el mismo seed de B5). **Sin esta feature `make php.behat` pasa en vacío y AC9 no ejercita el erase.** Presupuesto de queries:
      `assertEquals` exacto (mídelo, no lo asumas).

### C — API: CLI y self-audit (AC2, AC5) · Decisiones D3, D5

- [x] C1. **(D3 — RATIFICADA por Sergio: re-apuntar)** Re-apuntar `EraseIdentitySubjectCommand`
      (`identity:gdpr:erase-subject`) para invocar `FulfilIdentityErasure` en vez de `EraseIdentitySubject` directo —
      así CLI y consola comparten la **misma operación encadenada** (hoy la CLI erasa identidad pero **no** anonimiza
      el rastro → deja `actor_id` huérfano, incompleto por SI-19). La CLI pasa a aplicar también el guard ≥1-ADMIN (sin
      `--force` break-glass — rechazado). El comando `audit:gdpr:erase` **se mantiene** (anonimizar-solo, sin borrar
      identidad). **Documentar el cambio de semántica en el PR / release notes.** Rewire de `EraseIdentitySubjectCommandTest`
      (4 métodos: el `tester()` construye `FulfilIdentityErasure`, dobles de `ActiveAdministratorDirectory`/`AuditActorAnonymiser`/`ActorContextFactory`)
      + nuevo caso «último-ADMIN → 409/FAILURE». La idempotencia «nada que borrar» se conserva porque el 404 vive en el
      controlador HTTP, no en el service (D2).
- [x] C2. **(D5)** Decidir el self-audit del op combinado: hoy `EraseIdentitySubject` emite `GDPR_SUBJECT_ERASED`
      (identidad) y el comando `audit:gdpr:erase` emite `GDPR_ERASURE_EXECUTED` (actor). Al encadenar, `FulfilIdentityErasure`
      debe emitir el `GDPR_ERASURE_EXECUTED` (o una acción combinada) para que la evidencia de cumplimiento sea
      inequívoca. **No romper** el reconciler `DbalSubjectErasureReconciler` (indexa por `GDPR_SUBJECT_ERASED`, eje
      crypto-shredding distinto — D15). Acciones son `const` string por emisor (no hay enum central): mantener la convención.

### D — PWA: capa de datos (AC6, AC7) · clonar el trío de change-status

- [x] D1. `pwa/src/context/shared/http-client/infrastructure/ApiEndpoints.ts` — añadir `ERASE: (id) => userPath(id)`
      al bloque `USERS` (un `DELETE`; no devuelve `User`).
- [x] D2. `pwa/src/context/backoffice/user/` — clonar el trío: use case `FulfilIdentityErasure.ts`
      (`erase(id): Promise<void>`), puerto `domain/EraseIdentityRepository.ts`, adapter
      `infrastructure/ApiEraseIdentityRepository.ts` (DELETE a `API_ENDPOINTS.BACKOFFICE.USERS.ERASE(id)`; 204 → void).
- [x] D3. `pwa/src/context/shared/dependency-injection/infrastructure/Container.ts` — bind del use case + repo
      (tokens `BackOfficeEraseIdentity` / `BackOfficeEraseIdentityRepository`), espejo del bloque change-status (~:218-224).
- [x] D4. Tests: `ChangeUserStatus.test.ts` → `FulfilIdentityErasure.test.ts`; `ApiChangeUserStatusRepository.test.ts`
      → `ApiEraseIdentityRepository.test.ts` (mock `HttpClient`, asserta endpoint DELETE + 204→void).

### E — PWA: `UserEraseControl` + type-to-confirm (AC6) · net-new UI

- [x] E1. Crear `pwa/src/app/backoffice/users/_components/UserEraseControl.tsx` — `UserEraseControl({user, onErased})`,
      envuelto en `<Can permission={Permission.USERS_ERASE}>`; resuelve el use case vía `container.get`; en `HttpError`
      → `ProblemDetails` en `<MutationError>`; en éxito → toast + redirect a la lista vía `userRoutes.list`
      (`../_lib/userRoutes`, el mismo helper que usa el «Back to users» del detalle), envuelto en `safeHref`.
- [x] E2. Type-to-confirm (net-new): dentro de un `Dialog` (primitiva `@/components/ui/dialog`), input controlado cuyo
      valor debe **igualar la frase requerida** (recomendado: el `email` del usuario; alternativa: literal `ERASE`)
      para habilitar el botón de confirmar. Contrato de acción destructiva de `pwa/CLAUDE.md`: en `HttpError` el
      diálogo **se cierra** y el problema va a un `<MutationError>` persistente (nunca inline).
- [x] E3. Lenguaje visual **destructivo y distinto** de `UserStatusControl` (`variant="destructive"`, icono de aviso,
      copy que nombra la irreversibilidad y la des-identificación del rastro). UX-DR6: nunca confundible con deactivate.
- [x] E4. Montar `<UserEraseControl user onErased={…} />` en `pwa/src/app/backoffice/users/[id]/page.tsx` **después** de
      `UserStatusControl` (:206; ya hay un `UserRolesControl` en :204 de U-4). **No** en `UserRowActions` (el menú de fila
      es solo Copy-ID por diseño). En éxito redirige a la lista (el detalle será 404), no `reload()`.
- [x] E5. Test-ids (extienden el patrón BEM existente, únicos tree-wide — el guard `data-testid-uniqueness.test.ts`
      falla si no): `user-erase`, `user-erase__trigger`, `user-erase__dialog`, `user-erase__confirm-input`,
      `user-erase__confirm`, `user-erase__cancel`, `user-erase__error`.

### F — PWA: tests de UI (AC6, AC9)

- [x] F1. Unit `pwa/tests/app/backoffice/users/userEraseControl.test.tsx` (mirror `userStatusControl.test.tsx`):
      ADMIN con `USERS_ERASE` ve el control; sin el permiso → ausente (`queryByTestId("user-erase")` null); el confirmar
      está **deshabilitado** hasta que el input coincide con la frase; `HttpError` rechazado → `user-erase__error`.
- [x] F2. E2E real-api `pwa/tests/e2e/backoffice/users-erase-real-api.spec.ts` (mirror
      `users-change-status-real-api.spec.ts`, fixture `authenticatedTest`): sembrar una **identidad desechable por-run**
      (no reutilizar un usuario compartido — el erase es destructivo), navegar a su detalle, teclear la frase, confirmar,
      asertar el redirect a la lista y que el detalle da 404. Documentar la semilla como en el precedente suspendable.

### G — Docs + seguridad (AC5, AC9)

- [x] G1. `docs/api-error-contract.md` — **solo si** se introdujera un marcador/estado nuevo (no debería: se reutilizan
      `Forbidden`/`NotFound`/`Conflict`/`InvalidInput`). Si el mapping no cambia, **no** tocar (NFR26).
- [x] G2. `docs/architecture-api.md` — documentar el nuevo endpoint `DELETE /backoffice/users/{id}` y `FulfilIdentityErasure`
      (encadenamiento erase+anonimize atómico). `api/docs/` si aplica a la superficie de endpoints.
- [x] G3. `PRODUCTION_SECURITY_CHECKLIST.md` — el borrado GDPR es ahora accesible por HTTP (ADMIN-only, type-to-confirm);
      reflejar la superficie y que sigue sin dejar PII/secretos.
- [x] G4. Revisión de seguridad por fichero (checklist raíz): gateo `#[IsGranted]`; `Uuid::ensure`; sin body manual;
      PWA `safeHref` en el redirect, sin `dangerouslySetInnerHTML`, sin secretos en storage.

### Verificaciones (Working principle 4)

- [x] `make php.stan` en cada `.php` tocado (`PHP_SERVICE=messenger_worker` si segfaultea con 139).
- [x] `make php.quality` completo al final (rector/cs-fixer/phpmd/deptrac/error-contract **solo** salen aquí; CI corre
      `php.quality.dry-run` que no arregla — repasa `git diff` tras el sweep por si los fixers reescribieron algo).
- [x] `make php.unit`, `make php.behat`.
- [x] `make pwa.quality`, `make pwa.test` (unit + e2e).
- [x] **Barrer story/AC/SI/NFR IDs de los comentarios de código** antes del commit final (la trazabilidad vive en el
      PR y en este spec, no en `src/`). Los comentarios explican el *por qué* del código actual, no el cambio.

## Dev Notes

### Reuse map — API (rutas verificadas contra el worktree)

| Pieza | Path | Cómo se usa en U-5b |
|---|---|---|
| **`EraseIdentitySubject`** | `api/src/Iam/Identity/Application/EraseIdentitySubject.php:38` | `execute(userId)`: hard-delete + tokens + `GDPR_SUBJECT_ERASED` en su propia `transactional{}` (anida). **Reutilizar tal cual.** |
| `IdentityErasureResult` | `api/src/Iam/Identity/Application/IdentityErasureResult.php` | `{userId, identityErased, resetTokensDeleted}` + `erasedAnything()`. Base del `<Result>` de `FulfilIdentityErasure`. |
| **`AuditActorAnonymiser`** (puerto) | `api/src/Shared/Audit/Application/AuditActorAnonymiser.php:28` | `anonymise(actorId): ActorAnonymisationResult`. El `userId` erasado **es** el `actor_id`. |
| `DbalAuditActorAnonymiser` (adapter) | `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditActorAnonymiser.php:60` | `UPDATE audit_log` param.: pseudónimo UUIDv7 único, `ip`/`ua` = `[REDACTED]`, `actor_erased = TRUE`. Idempotente. |
| **`ActiveAdministratorDirectory`** (puerto) | `api/src/Iam/Identity/Domain/Repository/ActiveAdministratorDirectory.php:23` | `keepsAnActiveAdminWithout(userId): bool`. Guard ≥1-ADMIN. |
| Adapter guard | `api/src/Iam/Identity/Infrastructure/Persistence/Doctrine/DoctrineActiveAdministratorDirectory.php:36` | `SELECT … WHERE status=ACTIVE AND roles @> ADMIN … FOR UPDATE`. Lock a nivel de conjunto. |
| `LastActiveAdministratorProtected` | `api/src/Iam/Identity/Domain/Exception/LastActiveAdministratorProtected.php:18` | `implements Conflict` → **409**, `type: last-active-administrator-protected`. |
| `UserNotFound` | `api/src/Iam/Identity/Domain/Exception/UserNotFound.php:15` | `implements NotFound` → **404 `user-not-found`** (D2). |
| `TransactionManager` | `api/src/Shared/Persistence/Application/TransactionManager.php:22` → `DoctrineTransactionManager` (`wrapInTransaction`) | **Anida** → un solo commit atómico cross-módulo. |
| Patrón controlador | `api/src/Iam/Identity/Infrastructure/Controller/UserPatchStatusController.php:27` | Espejo estructural: `final`, un `__invoke`, `#[Route]` + `#[IsGranted]`, delega en el Application Service. |
| Prefijo de ruta | `api/config/routes.yaml` (`api_v1_iam_identity`, prefix `/api/v1`) | El nuevo controlador bajo `Iam/Identity/Infrastructure/Controller/` hereda `/api/v1`. |
| Policy (grant) | `api/src/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicy.php:65` (`users.erase → [ADMIN]`), `:73` (`users` ∈ `TIER_OPT_OUT`) | **Ya existe.** Solo referenciar. |
| Catálogo | `api/src/Iam/Identity/Infrastructure/Security/PermissionCatalog.php:48` | **Ya existe** (docblock «no endpoint yet» → actualizar en B3). |
| `AuditLogger` | `api/src/Shared/Audit/Application/AuditLogger.php:24` | `log(action, AuditLevel::SECURITY, resource?, metadata)`. SECURITY = síncrono, fallo propaga. |
| Sesiones (**NO** reusar `RevokeSessionsBestEffort`) | `api/src/Iam/Identity/Application/RevokeSessionsBestEffort.php:27` (soft + best-effort — lo usa `ChangeUserStatus`) | Para erase no vale: soft-revoke retiene la fila con `ip`/`device`. U-5b usa un **nuevo** `deleteAllForUser` de `Iam/Session` **dentro** de la tx (A7, D4). |

**Marcador → estado (RFC 9457):** `ProblemDetailsFactory::MARKER_STATUS_MAP` (`api/src/Shared/ErrorContract/…`);
tabla en `docs/api-error-contract.md`: NotFound=404, Conflict=409, Forbidden=403, InvalidInput=400. `#[IsGranted]`
denegado → `AccessDeniedException` → 403. **Se reutilizan todos; ningún marcador nuevo.**

### Reuse map — PWA (rutas verificadas)

| Pieza | Path | Cómo se usa en U-5b |
|---|---|---|
| Permiso (const object, no enum) | `pwa/src/context/shared/access/domain/Permission.ts:20` — `USERS_ERASE = "users.erase"` | **Ya existe.** Gatea con `<Can permission={Permission.USERS_ERASE}>`. |
| `<Can>` | `pwa/src/context/shared/access/infrastructure/ui/Can.tsx` | Oculta children si falta el permiso (nunca error). |
| `useCan` / `useSession` | `pwa/src/context/shared/access/application/useCan.ts` | Sesión hidratada de `/me` vía `AuthProvider`. |
| Control a espejar | `pwa/src/app/backoffice/users/_components/UserStatusControl.tsx` | Patrón `<Can>` + `container.get` + `HttpError→MutationError` + toast + `onChanged`. |
| Montaje | `pwa/src/app/backoffice/users/[id]/page.tsx:204` | Hermano bajo `UserStatusControl`. |
| Menú de fila (NO tocar) | `pwa/src/app/backoffice/users/_components/UserRowActions.tsx:31` | Solo Copy-ID por diseño; el erase vive en el detalle. |
| Trío de datos a clonar | `pwa/src/context/backoffice/user/{application,domain,infrastructure}/ChangeUserStatus*` | use case + puerto + adapter. |
| Endpoints | `pwa/src/context/shared/http-client/infrastructure/ApiEndpoints.ts:82` (bloque `USERS`, sin `ERASE`) | Añadir `ERASE`. |
| DI | `pwa/src/context/shared/dependency-injection/infrastructure/Container.ts:220` (bloque change-status) | Binds del trío. |
| Diálogo base | `pwa/src/components/erpify/DeleteResourceButton.tsx` + `@/components/ui/dialog` | Base para el `Dialog`; **type-to-confirm no existe** — es net-new sobre esta primitiva. |
| Acción destructiva (contrato) | `pwa/CLAUDE.md` §Test-ID + acción destructiva | Diálogo se cierra en error → `<MutationError>` persistente. |
| Helpers seguridad | `safeHref` (`pwa/src/context/shared/navigation/domain/safeHref.ts`), `<CopyButton>` | Envolver el redirect post-erase. |

### Decisiones (resolver antes/durante dev)

**D1 — Atomicidad cross-módulo: SÍ es alcanzable (mejora sobre el hedge del planning).** *(recomendada)*
El planning admitía «si no es atómica cross-módulo, con orden + reconciliación». **No hace falta el fallback:**
`TransactionManager` delega en `wrapInTransaction`, que **anida**; `EraseIdentitySubject` y `DbalAuditActorAnonymiser`
usan la **misma `Connection`** (verificar en A4). Por tanto `FulfilIdentityErasure` corre **una** `transactional{}`
que engloba guard + erase (interna se une) + anonimize + self-audit → commit/rollback único.
*Principio:* atomicidad real elimina un estado intermedio inconsistente (identidad borrada, rastro no). *Objetivo:*
correctness (sin reconciliación) + menos código. *Coste/alternativa descartada:* orden+reconciliación es más código
y ventana de inconsistencia; solo se justificaría si las conexiones divergieran (no es el caso).

**D2 — Id desconocido: 404, no 204 idempotente.** *(recomendada)*
`EraseIdentitySubject::execute` es idempotente (no lanza en ausente). Para la superficie HTTP, `FulfilIdentityErasure`
lanza `UserNotFound` si `!identityErased` (consistente con change-status; la consola siempre opera sobre una fila
listada). El *DELETE* sigue siendo idempotente en el sentido HTTP (estado del servidor sin cambio en el reintento).

**D3 — La CLI se re-apunta a `FulfilIdentityErasure`.** *(RATIFICADA por Sergio — cambia comportamiento mergeado, aprobado)*
Hoy `identity:gdpr:erase-subject` erasa identidad pero **no** anonimiza el rastro (verificado:
`EraseIdentitySubjectCommand.php:104` llama solo a `EraseIdentitySubject`). Para que la CLI no quede incompleta (SI-19:
erase que deja `actor_id` huérfano es incompleto), re-apuntarla a `FulfilIdentityErasure`.
**Tensión con el épico:** FR8/NFR4 dicen «la CLI se mantiene (additiva)» — re-apuntarla es un **cambio** de comportamiento,
no additivo; nombrar la contradicción en el PR. Efectos laterales: (a) la CLI pasaría a aplicar el guard ≥1-ADMIN
(¿break-glass deseado?); (b) el comando `audit:gdpr:erase` (`EraseActorAuditTrailCommand`) queda **redundante/solapado**
— decidir si se deprecia o se conserva para anonimizar actores *sin* borrar identidad. Alternativa: dejar la CLI como está
y documentar la incompletitud. **Decisión de Sergio.**
> **Consultado (Winston + Amelia + ChatGPT): los tres recomiendan RE-APUNTAR (a).** ChatGPT lo afina: la CLI actual
> **ya incumple** un invariante del dominio (SI-19), así que el fallback (b) no es «no tocar código mergeado» sino
> «documentar una operación conocida-incorrecta» — más grave. Refinamiento aceptado: `FulfilIdentityErasure` **devuelve**
> `Result` y el **404** vive en el controlador → la CLI conserva su idempotencia («nada que borrar»). **Documentar el
> cambio de semántica en el PR / release notes** (la CLI pasa a guardar ≥1-ADMIN y a anonimizar). Sin fase de deprecación
> formal (YAGNI: mismo nombre/UX, ahora completa). **RATIFICADA por Sergio** (2026-07-21): re-apuntar, documentando el
> cambio de semántica en el PR / release notes.

**D4 — Sesiones del sujeto: HARD-DELETE dentro de la transacción atómica.** *(RATIFICADA por Sergio — endurecida sobre el consenso interno, aprobado)*
Winston + Amelia convergieron en **hard-delete** (hallazgo: `RevokeSessionsBestEffort`→`revokeAllForUser` es *soft* y
**retiene** la fila con `ip`/`device` — PII que nadie purga; los eventos de sesión son PII-free → la fila es descartable).
Su propuesta lo ponía **post-commit best-effort** (espejo de `ChangeUserStatus`). **ChatGPT disintió aquí, y tiene razón:**
si el `DELETE` post-commit falla y se traga, la identidad ya está borrada pero la PII de sesión sobrevive → **vuelve a
violar SI-19 en silencio**, la misma clase de bug que el `actor_id` huérfano. *Principio:* completitud de SI-19 («como
una unidad») + fallo honesto > residuo silencioso. *Objetivo:* cero PII residual, garantizado. *Alternativas descartadas:*
(i) best-effort-swallow → hueco SI-19 silencioso; (ii) post-commit con retry fiable (outbox/scheduler) → YAGNI y
**reintroduce el async que U-5a acaba de retirar** para cerrar justo una ventana de resurrección GDPR. Por eso va
**dentro** de `transactional{}` (A7): misma Connection → atómico (igual que el anonimize del rastro); un fallo rollea
todo y el admin reintenta (idempotente). *Verificar en dev:* que `iam_session` no tenga obligación de retención propia
(no la tiene — sin suelo regulatorio como `audit_log`) y que ningún proyector dependa de la fila (no: leen eventos
PII-free). **Diverjo del placement de Winston/Amelia a propósito — si prefieres best-effort, dímelo.**

**D5 — Self-audit del op combinado.** *(recomendada)*
Emitir `GDPR_ERASURE_EXECUTED` (como el comando de actor) desde `FulfilIdentityErasure`, además del `GDPR_SUBJECT_ERASED`
que emite `EraseIdentitySubject`. No introducir enum central (la convención es `const` por emisor). No perturbar
`DbalSubjectErasureReconciler` (indexa `GDPR_SUBJECT_ERASED`, eje crypto-shredding — D15, distinto).
**Cambio de semántica a declarar:** el `audit:gdpr:erase` actual emite `GDPR_ERASURE_EXECUTED` **fuera** de transacción y
**tolera** el fallo del self-audit (`EraseActorAuditTrailCommand.php:108-130`); dentro de la transacción atómica, un fallo
del self-audit SECURITY **rollea el erase entero** (SECURITY propaga por diseño). Es más correcto (todo-o-nada), pero es
un cambio — decláralo. **Fijar las metadata keys** para evidencia consistente: el precedente CLI usa
`{anonymized_actor_id, affected_rows}`; `GDPR_SUBJECT_ERASED` usa `{subject_user_id, reset_tokens_deleted}`.

**D6 — Respuesta HTTP: `204 No Content`.** *(recomendada — B2)*
Hard-delete: no hay recurso que devolver (precedente: invite → body vacío). Alternativa: `200` con un
`IdentityErasureResource` (counts) si la consola quisiera mostrar «N tokens / M filas anonimizadas» — no lo necesita
(redirige a la lista). Recomendado 204.

**D7 — Auto-erase de un ADMIN (subject == actor).** *(confirmar — edge case destapado en validación)*
El guard ≥1-ADMIN permite que un ADMIN erase su **propia** identidad si existe un segundo ADMIN. En ese camino: (a) el
`UPDATE … WHERE actor_id = subjectId` **sí** reescribe el `actor_id` de la fila `GDPR_SUBJECT_ERASED`/`GDPR_ERASURE_EXECUTED`
que él mismo acaba de firmar (su atribución se pseudonimiza y queda `actor_erased = TRUE`), y (b) `RevokeSessionsBestEffort`
revoca su propia sesión viva a mitad de request. Decidir: permitir el auto-erase (documentando esa consecuencia de
atribución + self-revoke) o rechazarlo (p.ej. si `subjectId === actingAdminId`). Interactúa con el orden de D5.
> **DECIDIDA (Winston + Amelia + ChatGPT convergen): RECHAZAR con 409.** Guard de 2 líneas: comparar el `actor` (del
> `ActorContext` de confianza, **nunca** del body) contra `subjectId` **antes** de abrir la tx; lanzar
> `SelfErasureForbidden implements Conflict` (reusa el marcador `Conflict` → 409, `type: self-erasure-forbidden`; **sin
> marcador nuevo → sin cambio de `api-error-contract.md`**). CLI-safe: el actor `system` (actorId=null) nunca lo dispara,
> así que coexiste con D3. *El porqué de fondo (ChatGPT, más afilado que «clobbering»):* con actor==subject hay
> **responsabilidades incompatibles** — el sujeto deja de existir mientras aún debe existir como *actor* para atribuir su
> propia evidencia jurídica; ninguna ordenación de escritura reconcilia «anonimizar toda fila del sujeto» con «conservar
> evidencia nominal de quién borró». *Alternativa descartada (además de permitir+documentar):* un **actor técnico delegado**
> («system on behalf of…») — cambiaría el modelo de auditoría entero por un caso límite; complejidad desproporcionada (YAGNI).

### Fuera de alcance (frontera explícita)

- **Crypto-shredding / DEK.** La PII de identidad (email + hash de credencial) está protegida por **hard-delete**, no
  por envelope encryption; **no existe** `EncryptionScopeId::forUser` y `EraseIdentitySubject` no tiene encryptor.
  `FulfilIdentityErasure` **no** debe tocar `Shared/Crypto` (el crypto-shred es bank-account-scoped, ADR D15). No
  inventar un scope `forUser`.
- **Recurso de cumplimiento dedicado** (`compliance.eraseSubject`): `users.erase` bajo `users` por localidad; migrar a
  un recurso propio si el erase prolifera a otros sujetos es punto de evolución, **no** deuda de esta historia.
- **Filtro por rol server-side, tenancy operativa, realtime de la lista** — fuera de la épica.
- **`failed` sin gobierno para otros tipos de mensaje** — gap vivo independiente (issue propio, heredado de U-5a).

### Project Structure Notes

- API: `FulfilIdentityErasure` en `Iam/Identity/Application/` (orquesta Application services; importa el puerto
  `Shared/Audit/Application/AuditActorAnonymiser` — `Shared/` es siempre importable, sin allowlist de bounded-context).
  Controlador en `Iam/Identity/Infrastructure/Controller/`. **Deptrac** no debería moverse (todo cae en capas ya
  registradas; nada nuevo hacia framework en Domain/Application).
- PWA: componente en `app/backoffice/users/_components/`; datos en `context/backoffice/user/{domain,application,infrastructure}`;
  DI en el `Container.ts` raíz. Sin `src/lib/`, sin default exports bajo `context/**`.
- **Sin migraciones** (no hay esquema nuevo — se reusan `identity_user`, `audit_log`, `password_reset_token`, `session`).

### Testing (patrones del repo)

- **API unit:** dobles in-memory de puertos (mirror `EraseIdentitySubjectTest`, `RecordingAuditLogger`). `#[CoversClass(...)]`,
  **nunca** `#[CoversNothing]` (Behat no alimenta clover; los functional sí — el controlador thin cobra cobertura del
  functional). Cuidado con PHPMD `CouplingBetweenObjects` (≤13) **también en tests**.
- **API functional:** `WebTestCase` + `AuthenticatesFunctionalRequests` (`authenticateAdminClient` / `authenticateClient`),
  espejo `UserPatchStatusFunctionalTest`. 403/409 asertan `Content-Type: application/problem+json`.
- **API Behat:** `api/features/backoffice/users/erase.feature`, matriz de `status.feature` (200/204, 401 `unauthenticated`,
  403 `forbidden` viewer+audit-reader, 400 `invalid-uuid`, 404, 409 last-admin) + assert del row `GDPR_SUBJECT_ERASED`.
  Presupuesto de queries: `assertEquals` exacto — mídelo, no lo asumas (`I dump the number of executed queries`); recuerda
  el +2 BEGIN/COMMIT por escritura envuelta. `make php.behat` **resetea la DB** → re-siembra el ADMIN e2e **después**.
- **PWA unit:** Vitest, mock del Container + Toast, render dentro de `<AuthProvider>` para que `<Can>` vea sesión real
  (mirror `userStatusControl.test.tsx`). Query por rol/label/testid.
- **PWA e2e:** Playwright real-api, fixture `authenticatedTest`, **identidad desechable por-run** (el erase es destructivo;
  no compartir usuario). `baseURL` :3000. `rm -rf pwa/.next-e2e` si EACCES root-owned en worktree.

### Gotchas heredados (verificados)

- **php.stan segfault** en worker web (139) → `PHP_SERVICE=messenger_worker`.
- **Rector:** `/** @phpstan-var T */` (no `@var` sin nombre) sobre `return` en tests; importa FQCN en closures de
  `array_map` (>120 chars rompe PHPCS); `CatchExceptionNameMatchingType` renombra `catch ($x)` → dispara `LongVariable`
  de PHPMD (usa `expectException`).
- **Cluster de flakes conocido** en tests banks/realtime de la PWA: si algo rojo aparece ahí, **no** es de este diff.
  Nunca culpes a tu diff con una sola muestra — re-corre.
- **Base UI `DropdownMenuLabel`** lanza fuera de un `DropdownMenuGroup` (relevante si el erase usara un menú — mejor
  control dedicado en el detalle, como aquí).
- **`api/config/reference.php`** se regenera al correr comandos de consola; auto-generado, se commitea tal cual.
- **CLI + guard ≥1-ADMIN:** si D3 re-apunta la CLI, un test de la CLI que borre «el último admin» empezará a dar 409 —
  ajusta el fixture o el escenario.

### References

- [Source: `_bmad-output/planning-artifacts/epics-users-admin.md#Story 1.7 (U-5b)`] — AC del épico; FR8; NFR4/6/7; UX-DR6.
- [Source: `_bmad-output/planning-artifacts/arch-addendum-users-admin.md`] — SI-16…20 (SI-19 erase = identidad+rastro
  como unidad; SI-17 `users.erase` grant; SI-20 contrato byte-a-byte); fila U-5; DAG safe-first; nota «erase = hard-delete,
  no crypto-shred».
- [Source: `_bmad-output/implementation-artifacts/u-5a-cerrar-376-tombstone-actor-ids.md`] — prerrequisito cerrado;
  «encadenar los dos comandos es el `FulfilIdentityErasure` de U-5b»; D15 (erase-actor ≠ erase-subject).
- [Source: `api/src/Iam/Identity/Application/EraseIdentitySubject.php`] — eslabón identidad (reutilizar).
- [Source: `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditActorAnonymiser.php`] — eslabón rastro.
- [Source: `api/src/Iam/Identity/Application/ChangeUserStatus.php`] — patrón guard+transacción+revoke-sessions a espejar.
- [Source: `api/src/Iam/Identity/Infrastructure/Controller/UserPatchStatusController.php`] — patrón controlador.
- [Source: `api/src/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicy.php`, `PermissionCatalog.php`] — `users.erase` ya seedeado.
- [Source: `docs/api-error-contract.md`] — marcador→estado (reutilizados, sin marcador nuevo).
- [Source: `pwa/src/app/backoffice/users/_components/UserStatusControl.tsx`, `.../[id]/page.tsx`] — patrón de control + montaje.
- [Source: `pwa/src/components/erpify/DeleteResourceButton.tsx`, `pwa/CLAUDE.md`] — contrato de acción destructiva; base del diálogo.

## Dev Agent Record

### Agent Model Used

Claude Opus 4.8 (1M context) — `/bmad-dev-story`.

### Debug Log References

- Full-suite `make php.unit` intermittently aborts with "Premature end of PHP process" at a *different*
  unrelated Shared/* test each run (advisory-lock, error-contract) — the pre-existing non-deterministic
  worker segfault. The touched namespaces (`tests/Unit/Iam`, `Shared/Audit`, `Shared/Persistence`,
  `Functional/Iam`, 635 tests) pass in a smaller batch, and a re-run of the whole suite is green (2058 tests).
- PWA e2e in the worktree needs the full stack up (`pwa` + `messenger_worker` were down after `make app.dev`,
  yielding 502 on HTML). After `make docker.up` restored them, the erase spec passes against the worktree's
  ephemeral HTTPS port (`PLAYWRIGHT_BASE_URL`/`_API_BASE_URL` = `https://localhost:<php-443-port>`,
  `PLAYWRIGHT_SKIP_WEBSERVER=1`).

### Completion Notes List

- **AC1–AC8 met.** `FulfilIdentityErasure` chains, in one `transactional{}`, the ≥1-admin guard, the identity
  erase (its own tx nests), the trail anonymisation (same `Connection`), the session hard-delete, and the
  combined `GDPR_ERASURE_EXECUTED` self-audit — atomic, idempotent. D1 (real atomicity, no reconciliation),
  D2/D3 (service returns a Result; the 404 lives in the controller, CLI keeps idempotency), D4 (sessions
  hard-deleted inside the tx via the new `PurgeUserSessions` seam), D5 (combined self-audit), D6 (204), D7
  (self-erasure refused, 409 `self-erasure-forbidden`) all implemented as ratified.
- **Design consequence of D7 (surface for review):** with the self-erasure guard checked before the tx, the
  `last-active-administrator-protected` 409 is **unreachable over HTTP** — an ADMIN can only reach the ≥1-admin
  guard by targeting the sole active admin, which is necessarily themselves (any second active admin means the
  target is not the last), and self-targeting is refused first. So over HTTP the ≥1-admin invariant is enforced
  *by* the self-erasure refusal; the ≥1-admin guard binds only off-request (the CLI's `system` actor). Both are
  covered — the guard at the unit + CLI level, the self-erasure at the functional + Behat level. The functional
  test substitutes a self-erasure 409 scenario for the (unreachable) HTTP last-admin one and documents why.
- **CLI semantics changed (D3, ratified):** `identity:gdpr:erase-subject` now delegates to `FulfilIdentityErasure`,
  so it also anonymises the trail, purges sessions, and enforces the ≥1-admin guard (erasing the last admin now
  fails). `audit:gdpr:erase` (actor-only anonymise) is unchanged. Flag in release notes.
- **No new error-contract marker/status** — `SelfErasureForbidden` reuses `Conflict`→409; `make php.lint.error-contract`
  green, `docs/api-error-contract.md` untouched (NFR-correct). No migration (reuses `identity_user`, `audit_log`,
  `password_reset_token`, `iam_session`).
- **New cross-context seam registered** (`FulfilIdentityErasure` → `PurgeUserSessions`, Identity→Session) in
  `api/.bounded-context-allowlist` and deptrac `skip_violations`; `make php.deptrac` + bounded-context gate green.
- **Behat budget canary:** the 204 erase costs **14** queries on the default connection (measured, pinned).
- **Security review (per-file, root checklist):** `#[IsGranted('users.erase')]` on the controller; route id
  `Uuid::ensure`'d (400 before any work); no manual error body (RFC 9457, 204 no body); parameterised DQL/DBAL
  throughout; no secrets logged/returned; PWA redirect wrapped in `safeHref`; no `dangerouslySetInnerHTML`; no
  PII/JWT to client storage; no new deps; no migration. All applicable classes pass.
- **Gates:** `make php.quality` (incl. deptrac + error-contract), `make php.unit` (2058), `make php.behat`
  (362 scenarios), `make pwa.quality`, `make pwa.test.unit` (1111) — all green. The erase e2e passes against the
  worktree stack; the full e2e suite runs in CI.

### File List

**API — new**
- `api/src/Iam/Identity/Application/FulfilIdentityErasure.php`
- `api/src/Iam/Identity/Application/FulfilIdentityErasureResult.php`
- `api/src/Iam/Identity/Domain/Exception/SelfErasureForbidden.php`
- `api/src/Iam/Identity/Infrastructure/Controller/UserEraseController.php`
- `api/src/Iam/Session/Application/PurgeUserSessions.php`
- `api/tests/Unit/Iam/Identity/Application/FulfilIdentityErasureTest.php`
- `api/tests/Functional/Iam/Identity/Infrastructure/Controller/UserEraseFunctionalTest.php`
- `api/features/backoffice/users/erase.feature`

**API — modified**
- `api/src/Iam/Identity/Domain/Exception/LastActiveAdministratorProtected.php` (`::forErasure`)
- `api/src/Iam/Identity/Infrastructure/Cli/EraseIdentitySubjectCommand.php` (rewire → `FulfilIdentityErasure`)
- `api/src/Iam/Identity/Infrastructure/Security/PermissionCatalog.php` (docblock)
- `api/src/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicy.php` (docblock)
- `api/src/Iam/Session/Domain/Repository/SessionRepository.php` (`deleteAllForUser`)
- `api/src/Iam/Session/Infrastructure/Persistence/Doctrine/DoctrineSessionRepository.php` (`deleteAllForUser`)
- `api/tests/Unit/Iam/Identity/Infrastructure/Cli/EraseIdentitySubjectCommandTest.php`
- `api/tests/Unit/Iam/Session/Application/InMemorySessionRepository.php` (double: `deleteAllForUser` + `failOnDelete`)
- `api/tests/Functional/Iam/Session/Fixtures/UnavailableSessionRepository.php` (`deleteAllForUser`)
- `api/.bounded-context-allowlist` · `api/tools/deptrac/deptrac.yaml` (Identity→Session seam)

**PWA — new**
- `pwa/src/context/backoffice/user/domain/EraseIdentityRepository.ts`
- `pwa/src/context/backoffice/user/application/FulfilIdentityErasure.ts`
- `pwa/src/context/backoffice/user/infrastructure/ApiEraseIdentityRepository.ts`
- `pwa/src/app/backoffice/users/_components/UserEraseControl.tsx`
- `pwa/tests/context/backoffice/user/application/FulfilIdentityErasure.test.ts`
- `pwa/tests/context/backoffice/user/infrastructure/ApiEraseIdentityRepository.test.ts`
- `pwa/tests/app/backoffice/users/userEraseControl.test.tsx`
- `pwa/tests/e2e/backoffice/users-erase-real-api.spec.ts`

**PWA — modified**
- `pwa/src/context/shared/http-client/infrastructure/ApiEndpoints.ts` (`USERS.ERASE`)
- `pwa/src/context/shared/dependency-injection/infrastructure/Container.ts` (erase binds)
- `pwa/src/app/backoffice/users/[id]/page.tsx` (mount `<UserEraseControl>`)

**Docs**
- `docs/architecture-api.md` · `PRODUCTION_SECURITY_CHECKLIST.md`

### Change Log

- 2026-07-21 — Implemented U-5b (GDPR erasure from the console): net-new `FulfilIdentityErasure` orchestrator +
  `DELETE /backoffice/users/{id}` (ADMIN-only), CLI rewired to the chained op, PWA `UserEraseControl` with
  type-to-confirm. All decisions D1–D7 as ratified. Status → review.
