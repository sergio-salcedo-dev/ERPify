---
baseline_commit: c1f8872c22706c7f4e67ce0103aae948bbe38f39
---
# Story II-3: `IdentityStatus` + `UserChecker` (admisión de tres momentos) + tipos de error post-identidad

Status: review

<!-- Validación opcional. Ejecuta `bmad-create-story` validate para un chequeo de calidad antes de dev-story. -->

## Story

Como **sistema de admisión de ERPify**,
quiero un **estado de identidad (`IdentityStatus`)** y un **`UserChecker`** que rechace las identidades no elegibles **entre demostrar credenciales y acuñar sesión**,
para que **«autenticado» nunca implique «admitido»**: los muros post-identidad no acuñan ninguna sesión y no filtran estado a un anónimo, y el contrato de error se gradúa por el nivel de confianza alcanzado.

## Contexto (leer antes de tocar código)

Esta es **II-3 (PR-3)** de la épica `identity-invitation-lifecycle` (orden de merge safe-first
`II-0 → II-1·II-2·II-3·II-7 → II-6 → II-4 → II-5 → II-8`). Depende **solo de II-0** (el `User` ya vive en
`Iam/Identity`, firewall de sesión SI-1) — **ninguna** dependencia de una historia posterior. Es un **hub**: su
`IdentityStatus` / `UserChecker` / tipos de error los reutilizan cuatro historias downstream (II-6 lockout añade el
brazo `LockedUntil` a `checkPostAuth`; II-4 accept-invitation consume «solo `ACTIVE` recibe sesión»; II-5 reset
consume `IdentityStatus`+muro no-`ACTIVE`; II-7 consume «sesión solo tras admisión») → **constrúyelo extensible**.

Fuente de verdad del diseño (no re-abrir, ya ratificado por Sergio):
[`docs/adr/identity-invitation-lifecycle.md`](../../docs/adr/identity-invitation-lifecycle.md) **D3, D4, D12** (contexto D6/D8) ·
[`_bmad-output/planning-artifacts/arch-addendum-identity-invitation.md`](../planning-artifacts/arch-addendum-identity-invitation.md) **SI-10…15, PR-3** ·
[`_bmad-output/planning-artifacts/epics-identity-invitation-lifecycle.md`](../planning-artifacts/epics-identity-invitation-lifecycle.md) **Story II-3, líneas 604-649**.

**La regla de los tres momentos en una línea:** `credenciales → identidad → admisión → sesión`, **nunca**
`credenciales → sesión`. El `UserChecker` de II-3 **es** el paso «admisión». El invariante que debe hacer
imposible de puentear: un login `INVITED` / `SUSPENDED` / `DEACTIVATED` deja **cero** artefactos de sesión.

## Acceptance Criteria

Los AC se redactan como **invariantes verificables** enganchados al ADR (D1–D12), a los System Invariants (SI-10…15)
y a las reglas de proyección (D-a/b/c), de modo que una refactorización futura no pueda romper una garantía sin que
un test la detecte.

1. **(Modelo · FR3/D3)** `User` porta `IdentityStatus ∈ {INVITED, ACTIVE, SUSPENDED, DEACTIVATED}` (**sin `PENDING`**)
   y `HashedPassword` es **nullable hasta `ACTIVE`** (`INVITED` = identidad + membership provisionados, credencial aún
   sin fijar). Un test prueba que una identidad `INVITED` puede existir **sin** password y que el enum no admite otro
   valor.

2. **(SI-10 · tres momentos)** Dadas credenciales válidas para una identidad, **ninguna sesión se acuña antes** de que
   `checkPostAuth` evalúe la admisión (`credenciales → identidad → admisión → sesión`). Un test **falla** si se observa
   una `Session`/cookie de sesión **antes** de la admisión.

3. **(D-c · muro sin sesión)** Una identidad `SUSPENDED` o `DEACTIVATED` con credenciales **correctas** que intenta
   login → **no existe** ninguna sesión, cookie de sesión ni token reanudable tras la respuesta; el muro se renderiza
   desde el **body del POST de login** (stateless). Un test verifica ausencia de `Set-Cookie` de sesión y de registro
   de sesión.

4. **(SI-12 · indistinguibilidad pre-identidad)** Una identidad `INVITED` (sin password) que intenta login produce una
   respuesta **indistinguible** de «email inexistente» y «password errónea** en **código de estado y forma/tamaño de
   respuesta** (`checkPreAuth` trata `INVITED` como pre-identidad). Un test compara las **tres** respuestas y exige
   status+shape idénticos. **El timing se cierra en II-8** — aquí **no** se ecualiza latencia (ver «Fuera de alcance»).

5. **(SI-14/D12 · error graduado)** `account-suspended` (**403, específico** — carga un siguiente paso real) y
   `DEACTIVATED` (**genérico** — sin paso accionable) fluyen por el pipeline **RFC 9457** **sin body manual**;
   [`docs/api-error-contract.md`](../../docs/api-error-contract.md) actualizado (NFR26) y `make php.lint.error-contract`
   verde. El `invalid-token` y el tipo operacional del gate **NO** son de esta historia (ver «Fuera de alcance»).

6. **(Cliente B1 · UX-DR1/7/9)** Un login que devuelve identidad **no-`ACTIVE`** proyecta el `AccessWall`
   correspondiente (`suspended` / `deactivated`, ruteado por `body.type`) con **foco al `<h1>`**, exactamente un `<h1>`
   por superficie, el color **nunca** como canal único, copy neutro traducible. El muro `locked` se cablea en **II-6**;
   `invalid-link` en **II-4**; `session-expired` en **II-7**.

7. **(Invariante ≥1 ADMIN · caminos en alcance)** Una transición `ACTIVE→SUSPENDED` o `ACTIVE→DEACTIVATED` se
   **rechaza** si dejaría a la organización con **0 `ADMIN` activos** — el **último administrador activo no puede ser
   suspendido ni desactivado** (invariante de dominio establecido en II-1, cuya preservación bajo suspend/deactivate se
   difirió explícitamente a esta historia). El enforcement de `demote`/`remove` viaja con el **slice diferido de gestión
   de miembros** (issue #462), **no** con II-3. El conteo de admins activos **no** debe dejarse engañar por una
   `Membership` huérfana de un `User` hard-borrado (#462): cuenta solo admins cuyo `User` existe y está `ACTIVE`.

**Invariante rector de no-regresión (transversal a todos los AC):** `make app.test` + `make app.quality` verdes; el
login de un usuario `ACTIVE` con password correcta sigue devolviendo **204 + cookie de sesión** (LoginController
intacto); todo fallo pre-identidad sigue colapsando a **un único 401 neutro**; el `PermissionVoter`/RBAC sigue leyendo
`User.roles` sin cambio de decisión (ver «Decisión C»).

## Tasks / Subtasks

- [x] **T1 — `IdentityStatus` enum (AC1)**
  - [x] Crear `api/src/Iam/Identity/Domain/Enum/IdentityStatus.php` — backed enum `string`, casos
        `INVITED/ACTIVE/SUSPENDED/DEACTIVATED`, **pura vocabulary** (sin lógica/ranking), espejo exacto de la forma de
        `Domain/Enum/Role.php` (docblock del *porqué*, sin método que ramifique). **No** `PENDING`.
- [x] **T2 — `User` con estado + password nullable (AC1)**
  - [x] Añadir `#[ORM\Column(enumType: IdentityStatus::class)] private IdentityStatus $status;` a `User`.
  - [x] Hacer `password_hash` **nullable**: `#[ORM\Column(name: 'password_hash', nullable: true)] private ?string $passwordHash;`
        y `passwordHash(): ?HashedPassword` (devuelve `null` cuando no hay hash). Ajustar el ctor privado — firma pasa a
        `(string $id, string $email, ?HashedPassword $password, IdentityStatus $status, Role ...$roles)` con
        `$this->passwordHash = $password?->toString();` — **todas** las construcciones siguen funnelando por él (invariante
        canónico de email/roles se conserva).
  - [x] **`SecurityUser::getPassword()` pasa a `?string`** (`return $this->user->passwordHash()?->toString();`): con el
        password nullable, la firma `: string` de hoy da `TypeError` en runtime y viola PHPStan-max;
        `PasswordAuthenticatedUserInterface::getPassword()` ya es `?string` y `CheckCredentialsListener` maneja el `null`.
  - [x] Factories del ciclo de vida: `invite()` (nace `INVITED`, **sin** password) y la vía `ACTIVE` (renombrar/conservar
        `register()` para el camino con credencial ya fijada — ver «Decisión A»). Transiciones de dominio `activate()`
        (fija password + `→ACTIVE`), `suspend()` (`ACTIVE→SUSPENDED`), `deactivate()` (`ACTIVE→DEACTIVATED`) que **guardan
        la máquina** (rechazan transiciones ilegales con una excepción de dominio, p.ej. `InvalidIdentityTransition`).
  - [x] `status(): IdentityStatus` accessor.
- [x] **T3 — Migración (AC1)**
  - [x] `make db.diff` → migración en `api/migrations/2026/` + `password_hash` a `NULL`-able. `down()` reversible. **Cero
        credenciales/PII** en el schema. Verificar que el `postGenerateSchema` de FKs no se altera y confirmar el tipo de
        columna que `enumType` genera (primer `enumType` del repo — `Role` se persiste como JSON, no es precedente).
  - [x] **`status` NOT NULL brickearía el boot: el `db.diff` crudo emite `ADD status ... NOT NULL` que falla sobre la fila
        del admin-bootstrap (II-1) y cualquier dato dev/test — y las migraciones son all-or-nothing en boot (brickea la
        stack).** Editar la migración generada (permitido en esta rama): `ADD status ... DEFAULT 'ACTIVE' NOT NULL` (y
        opcionalmente soltar el default después), **o** add-nullable → `UPDATE identity_user SET status='ACTIVE'` → `SET
        NOT NULL`. Idempotente (`IF [NOT] EXISTS`).
  - [x] Fixtures: variantes `INVITED`/`SUSPENDED`/`DEACTIVATED` en `User.yaml` (+ `Membership` companion cada una, per
        II-1: **ningún `User` sin `Membership`**), para los tests de muro/pre-identidad. Nunca en migración.
- [x] **T4 — `UserChecker` (AC2, AC3, AC4)**
  - [x] `api/src/Iam/Identity/Infrastructure/Security/UserChecker.php` implements `Symfony\...\User\UserCheckerInterface`.
        Exponer el estado a través de `SecurityUser` (añadir `SecurityUser::status(): IdentityStatus`, leído del `User`).
  - [x] `checkPreAuth`: `INVITED` → lanzar una **`AccountStatusException` «plana»** (p.ej. extender `DisabledException`,
        **NO** `CustomUserMessageAccountStatusException`). Con `expose_security_errors: None` (default de este stack),
        `AuthenticatorManager::handleAuthenticationFailure` la **reenvuelve en `BadCredentialsException`** antes de llamar
        al failure handler → cae al **401 uniforme** — que es exactamente el colapso pre-identidad deseado (SI-12
        status+shape gratis). Corre **antes** de verificar password (prio 256 > 0) → el password nunca se verifica para
        `INVITED`.
  - [x] `checkPostAuth`: `SUSPENDED` → `SuspendedAccountException`; `DEACTIVATED` → `DeactivatedAccountException`. **Ambas
        DEBEN extender `CustomUserMessageAccountStatusException`** (sigue siendo `AccountStatusException` → el firewall
        aborta sin sesión, pero **exenta del reenvoltorio** `isSensitiveException` que colapsaría a 401 — ver
        `AuthenticatorManager.php:247,268`). **Dejar el brazo `LockedUntil` preparado como punto de extensión para II-6**
        (comentario/estructura, sin implementarlo).
  - [x] `security.yaml`: añadir `user_checker: Erpify\Iam\Identity\Infrastructure\Security\UserChecker` al firewall `main`.
- [x] **T5 — Contrato de error graduado (AC5)**
  - [x] Excepciones de dominio en `api/src/Iam/Identity/Domain/Exception/`, **ambas `DomainException implements
        Forbidden`** (403): `AccountSuspended` con `type()='account-suspended'` + `title()` específico con siguiente paso;
        `AccountDeactivated` con `type()` **genérico** (vacío → default `forbidden`, o `'forbidden'` explícito) y `title()`
        neutro sin paso accionable. Literal: `final class AccountSuspended extends DomainException implements Forbidden {
        public function __construct() { parent::__construct(type: 'account-suspended', title: '…'); } }` (mecanismo
        `type()`-override documentado en `api-error-contract.md:61`; **no** hay precedente concreto aún — es el primero).
        **NO** usar el bridge `AccessDeniedException` para el genérico: `UnauthenticatedAccessListener` (prio 40) reescribe
        cualquier `AccessDeniedException` en `/api` con token no-full a `InsufficientAuthenticationException` → **401**, no
        403 (`UnauthenticatedAccessListener.php:69-73`).
  - [x] Evolucionar `ProblemDetailsAuthenticationFailureHandler` para **ramificar sobre el tipo de excepción**:
        `SuspendedAccountException` → lanzar `AccountSuspended` (403 `account-suspended`); `DeactivatedAccountException` →
        lanzar `AccountDeactivated` (403 `forbidden` genérico); **cualquier otra** `AuthenticationException` (bad-cred,
        `UserNotFoundException`, el `INVITED` ya reenvuelto en `BadCredentialsException`) → el **401 uniforme** de hoy (sin
        cambio). Ambos `DomainException` lanzados **no** llevan `AccessDeniedException` en su cadena, así que
        `UnauthenticatedAccessListener` los ignora y `ProblemDetailsFactory` (rama DomainException) emite el 403.
  - [x] Actualizar [`docs/api-error-contract.md`](../../docs/api-error-contract.md): documentar `account-suspended` (403,
        marker `Forbidden`, `type()` override — patrón de la familia `InvalidSearchCriteria`) y el mapeo pre/post-identidad
        de FR9 en el alcance de II-3. `make php.lint.error-contract` verde.
- [x] **T6 — Invariante ≥1 ADMIN en suspend/deactivate (AC7) — Decisión D = B (puerto+fake ahora, adapter diferido)**
  - [x] **Puerto mínimo** en `Iam/Identity/Domain/Repository/ActiveAdministratorDirectory.php`:
        `keepsAnActiveAdminWithout(string $userId): bool` — devuelve un `bool` (nada de `Membership`/`User`/`Role[]` cruza
        la frontera). Docblock fija el contrato: «cuenta solo memberships cuyo user ADMIN existe y está `ACTIVE`». **NO
        pre-diseñar** para `demote`/`remove` (eso lo reshapea #462, Rule-of-Three con 4 consumidores reales).
  - [x] Caso de uso en `Iam/Identity/Application` (`SuspendUser`/`DeactivateUser`, o `ChangeUserStatus` parametrizado) —
        **único punto de enforcement**, en un `wrapInTransaction`: `if (!$directory->keepsAnActiveAdminWithout($userId))
        throw new LastActiveAdministratorProtected();` → `user->suspend()`/`deactivate()` → save+publish. La guarda va **en
        aplicación, NO dentro de `User.suspend()`** (invariante cross-aggregate; `User` no puede conocerlo; #462
        `demote`/`remove` reutiliza este punto). Espejo de `BankAccountStatusChanger`/`BankDeleter`.
  - [x] Excepción `Iam/Identity/Domain/Exception/LastActiveAdministratorProtected` **`extends DomainException implements
        Conflict`** → **409** (petición bien formada y autorizada que choca con el estado; precedentes `BankInUseException`,
        `BankAccountNotClosedException`). **Distinta** de `AccountSuspended` (403, muro de login, T5). Sin superficie HTTP
        en II-3 → **no** toca `api-error-contract.md` todavía (se testea a nivel aplicación con `expectException`).
  - [x] **DIFERIR a #462** (el slice que añade el disparador HTTP/CLI de gestión de miembros + `demote`/`remove`): el
        adapter Doctrine real (`DoctrineActiveAdministratorRegistry` en `Organization/Membership/Infrastructure`, INNER JOIN
        `membership ⋈ identity_user` filtrando `roles=ADMIN AND status=ACTIVE` → el INNER JOIN excluye al fantasma #462),
        las entradas per-file de seam (`.bounded-context-allowlist` + deptrac `skip_violations`), el wiring `#[AsAlias]` y el
        `SELECT … FOR UPDATE` anti-TOCTOU (solo relevante con caller concurrente real). Registrar la receta en
        `deferred-work.md`.
  - [x] **Honestidad del contenedor (verificar en dev, NO asumir):** el caso de uso queda **caller-less** en II-3 (sin
        HTTP/CLI/Messenger/subscriber) → Symfony debería descartarlo (`RemoveUnusedDefinitionsPass`) y el stack arranca
        pese al puerto sin bindear. **Arrancar el stack / `make php.behat` para confirmarlo.** Si rompe → `services.yaml`
        `exclude` sobre las clases de Application con comentario `# adapter bound in #462`; **NUNCA** bindear un stub (un
        stub dejaría suspender al último admin en silencio = fallo de seguridad).
  - [x] Tests (unit, sin DB): `InMemoryActiveAdministratorDirectory` + casos AC7 — (a) único admin activo excluido →
        `expectException(LastActiveAdministratorProtected)`, sin save ni evento; (b) ≥2 admins activos → transición aplica;
        (c) **fantasma #462** → el fake modela «membership ADMIN cuyo user no existe/no está ACTIVE» → no cuenta → excluir
        al admin real sigue rechazando. El fake **es** la spec ejecutable del INNER JOIN de #462.
- [x] **T7 — Frontend: desmockear el login + `AccessWall` (AC6 · Decisión F = F-a, ratificada)** — el login del PWA es
      hoy un **MOCK puro** (`LoginForm.tsx` llama `useSession().login({…identidad hardcodeada…})`, **sin** API ni Problem
      Details). II-3 lo **desmockea end-to-end**.
  - [x] Mutación real → `POST /api/v1/backoffice/login` (nombre `identity_login`), con `Origin` correcto (el login lleva
        `LoginOriginListener`). Manejar: **204** (éxito, cookie de sesión httpOnly establecida) → navegar al ERP;
        **401** (`type: unauthenticated`) → mensaje **único neutro** de credenciales (UX-DR8, sin delatar); **403** →
        **rutear por `body.type`** (`account-suspended` → `AccessWall` suspended; `forbidden` → `AccessWall` deactivated)
        — patrón FR44 de `api-error-contract.md` (route on `type`, no status). Reemplaza la llamada mock `useSession().login(...)`.
  - [x] **Ramificación del de-mock (resolver en dev):** la sesión del PWA está mockeada más allá del login
        (`useSession.ts` = «mocked session»; identidad/roles/permissions hardcodeados). Desmockear **solo** el login deja
        incoherente cómo se establece la identidad post-204. Decidir: **who-am-i real** (endpoint de sesión) vs mantener
        el mock de identidad/roles tras un login real (mínimo viable para II-3, con TODO explícito). No expandir a
        RBAC/permissions del cliente (fuera de alcance — plano ortogonal). **Si resulta más grande de lo previsto,
        parar y avisar a Sergio** antes de arrastrar el de-mock de toda la sesión.
  - [x] Componente `AccessWall` (variantes `suspended` / `deactivated`; muro dentro de `AuthLayout`, tarjeta centrada
        **sin formulario**, **nunca** `{color.danger}` — no es un error). Foco al `<h1>` en la transición; exactamente un
        `<h1>`; nombres accesibles estáticos; copy traducible; **siempre** ofrece «Iniciar sesión» (D-a) vía `safeHref`.
        **NO reinventar el shell:** `pwa/src/context/shared/error/infrastructure/ui/` ya trae `ErrorScreen` +
        `AccessDeniedScreen` (403) + `SignInRequiredScreen` (401) con el patrón foco-al-`<h1>` / un-`<h1>` / 2-botones —
        usarlo como base de `AccessWall` (o argumentar por qué uno nuevo).
  - [x] **Reconciliar `UserStatus`:** `pwa/src/context/shared/access/domain/UserStatus.ts` YA existe con
        `{ACTIVE, BLOCKED, PENDING}` — **divergente** del API `{INVITED, ACTIVE, SUSPENDED, DEACTIVATED}` (BLOCKED vs
        SUSPENDED/DEACTIVATED, PENDING vs INVITED = bug latente). Con el login real, alinear el enum del cliente al del API.
  - [x] Tests: unit del `AccessWall` (cada variante, foco, AA) + del ruteo `body.type`→muro en la mutación; E2E del flujo
        login→muro sobre la stack viva (`https://localhost`). `make pwa.quality` + `make pwa.test` verdes.
- [x] **T8 — Tests (todos los AC)** — ver «Testing».
- [x] **T9 — Gates + verificación fresca** — `make php.behat.install` (worktree fresco) → `make php.stan` (por fichero;
      si exit 139 → `PHP_SERVICE=messenger_worker`) → `make php.test` → `make php.quality` EXIT 0 →
      `make php.lint.error-contract` → `make php.psalm.taint` → `make pwa.quality` → `make pwa.test`. Verificar sobre el
      **path del worktree**, confiar en el exit code recién impreso.

## Dev Notes

### Ficheros a tocar (estado actual verificado en el worktree)

**API — `Iam/Identity`:**

| Fichero | Estado hoy | Qué cambia II-3 |
|---|---|---|
| `Domain/Entity/User.php` | Aggregate: `email`, `passwordHash` (**no-null**), `roles` (JSON). Ctor privado único; factory `register()`. `passwordHash(): HashedPassword`. **Sin estado.** | +`status` (`enumType`), `passwordHash` nullable + `?HashedPassword`, factories `invite()`/transiciones, guardas de máquina. |
| `Domain/HashedPassword.php` | VO `final readonly`, `fromHash(#[SensitiveParameter])` rechaza `''`. | Sin cambio (el `null` se maneja en `User`, no en el VO — el VO nunca es «vacío»). |
| `Domain/Enum/Role.php` | Backed enum puro (plantilla exacta para `IdentityStatus`). | — (referencia de forma). |
| `Domain/Exception/{InvalidEmail,InvalidHashedPassword}.php` | Excepciones de dominio existentes. | +`AccountSuspended`, +transición inválida, +`LastActiveAdministratorProtected` (nombres a confirmar). |
| `Infrastructure/Security/SecurityUser.php` | Adapter Symfony; `getPassword(): string`, `getRoles()` con `ROLE_`. | +`status(): IdentityStatus`; `getPassword(): ?string` (`passwordHash()?->toString()`). INVITED nunca llega a verificación de password (checkPreAuth prio 256 > CheckCredentialsListener 0 — verificado en vendor). |
| `Infrastructure/Security/UserProvider.php` | Ya ecualiza timing en not-found (dummy hash) — **mecanismo SI-12 status+shape ya parcialmente presente**. | Sin cambio funcional (el timing global de `INVITED` es II-8). |
| `Infrastructure/Security/ProblemDetailsAuthenticationFailureHandler.php` | Colapsa **toda** `AuthenticationException` → `'Invalid credentials.'` 401 uniforme. | **Ramificar** post-identidad (ver «Decisión B»). |
| `Infrastructure/Http/LoginController.php` | `json_login` check path; éxito → 204 sin body; fallo nunca lo alcanza. | Sin cambio (no crear controlador nuevo; el muro sale del fallo). |
| `Application/CreateUser.php` | Caso de uso existente. | Posible ajuste al camino `INVITED`/`ACTIVE` (ver «Decisión A»). |

**API — error contract (`Shared/ErrorContract`, siempre importable):**
- Markers = **interfaces** en `Domain/Exception/` (`Forbidden extends ClientError`, `Unauthenticated`, …).
  `Application/ProblemDetailsFactory::MARKER_STATUS_MAP` mapea marker→status (`Forbidden`→403);
  `MARKER_DEFAULT_TYPE_MAP` el `type` por defecto (`forbidden`). Un `DomainException` puede `implements Forbidden` y
  **override `type()`** → tipo específico `account-suspended` conservando 403 (patrón de la familia
  `InvalidSearchCriteria` documentada en `api-error-contract.md`). El bridge `AccessDeniedException` → 403 genérico
  `forbidden`. **No** hace falta un marker interface nuevo → el drift gate `ErrorContractGateTest` (que vigila markers
  nuevos) no se dispara, pero **igual actualiza `api-error-contract.md`** (NFR26 + AC5).
- `DomainException` base: ctor `(string $type, string $title, array $context = [], ?Throwable $previous)`.

**API — Organization (para AC7):**
- `Organization/Membership/Domain/Entity/Membership.php`: `hasRole(Role)`, refs `userId`/`organizationId` por id.
- `Organization/Membership/Domain/Repository/MembershipRepository.php`: `findByUserId`, `findByOrganizationId(): list<Membership>`
  (docblock dice explícitamente que respalda «la org mantiene ≥1 ADMIN activo … later stories verify»).
- **FK física solo `membership.organization_id`**; `user_id` id-only sin FK cross-contexto → de ahí el hueco #462.

**PWA — superficie de acceso (`pwa/`):**
- `pwa/src/app/(auth)/_components/LoginForm.tsx` — form de login (punto de ruteo del body de error).
- `pwa/src/context/backoffice/user/application/schemas/auth/LoginSchema.ts` — Zod schema del login.
- `pwa/src/context/shared/access/{domain/authorize.ts, infrastructure/ui/{RequireAuth,AuthProvider}.tsx}` — contexto de acceso.
- `pwa/src/app/unauthorized.tsx`, `pwa/src/app/(errors)/`, `pwa/src/app/status/` — superficies de error/estado existentes.
- **`AccessWall` NO existe** → II-3 lo crea (variantes suspended/deactivated). Sigue el contrato UX-DR1 del run
  `ux-ERPify-2026-07-06` (`EXPERIENCE.md`/`DESIGN.md` en `_bmad-output/planning-artifacts/ux-designs/`).

### El crux: admisión de tres momentos y por qué el orden importa

Symfony corre el `UserChecker` en dos puntos del `AuthenticatorManager`:
- **`checkPreAuth`** — **antes** de verificar la password. Rechazar aquí a `INVITED` significa que la password **nunca**
  se verifica y que el fallo entra por el brazo **uniforme 401** (pre-identidad, indistinguible de inexistente/errónea
  en status+shape). El *gap* de timing (INVITED no gasta una verificación de password) es exactamente lo que **II-8**
  cierra con hash-dummy siempre; **aquí no**.
- **`checkPostAuth`** — **después** de verificar la password (⇒ identidad demostrada). Rechazar aquí a
  `SUSPENDED`/`DEACTIVATED` produce el **muro post-identidad**. Como la autenticación **falló**, el firewall **no acuña
  token ni sesión** — la garantía D-c «cero artefactos de sesión» es estructural, no algo que haya que limpiar a mano.

Esto es lo que hace `checkPostAuth` el punto de admisión **entre** identidad demostrada y sesión (SI-10). Cualquier
diseño que acuñe sesión y luego compruebe estado **viola** los tres momentos (alternativa descartada en D4).

### El crux: contrato de error graduado (cómo se rutea sin romper la neutralidad)

Tensión real: el `ProblemDetailsAuthenticationFailureHandler` **hoy colapsa toda** `AuthenticationException` a un 401
uniforme (eso es lo que da SI-12 status+shape para pre-identidad, y **debe conservarse**). Pero `checkPostAuth` necesita
**salir** de ese uniforme hacia 403 graduado. **Dos mecanismos stack-específicos gobiernan esto — ignorarlos hace que la
implementación devuelva silenciosamente 401 para suspended y deactivated (comportamiento plausible, contrato roto, solo
lo caza un test que asserte 403):**

- **Reenvoltorio `expose_security_errors`** (`AuthenticatorManager::handleAuthenticationFailure`, `.php:247,268`): con
  `ExposeSecurityLevel::None` (default, **no** overridden en `security.yaml`), una `AccountStatusException` que **no** sea
  `CustomUserMessageAccountStatusException` se **reenvuelve en `BadCredentialsException`** ANTES de invocar el failure
  handler. `UserNotFoundException` idem (salvo `ExposeSecurityLevel::All`).
- **`UnauthenticatedAccessListener`** (prio 40 > `ExceptionResponder` 16, `.php:69-73`): reescribe cualquier
  `AccessDeniedException` en `/api` con token **no full-fledged** (siempre cierto en un login fallido, sin sesión) a
  `InsufficientAuthenticationException` → **401**. Descarta reusar el bridge `AccessDeniedException` para el 403 genérico.

Flujo correcto sobre este stack:

1. `checkPreAuth` (INVITED) lanza una `AccountStatusException` **plana** (p.ej. `DisabledException`) → el reenvoltorio la
   colapsa a `BadCredentialsException` → **401 uniforme** (idéntico a bad-cred / inexistente). *Aprovechamos* el
   reenvoltorio en vez de luchar contra él.
2. `checkPostAuth` lanza `SuspendedAccountException` / `DeactivatedAccountException`, **ambas
   `CustomUserMessageAccountStatusException`** → **exentas** del reenvoltorio → llegan intactas al handler.
3. El handler ramifica: suspended → lanza `AccountSuspended` (`DomainException implements Forbidden`,
   `type()='account-suspended'` → 403 específico vía la rama DomainException de `ProblemDetailsFactory`); deactivated →
   lanza `AccountDeactivated` (`DomainException implements Forbidden`, `type()` genérico → 403 `forbidden`); **default** →
   el 401 uniforme de hoy. Ninguno lleva `AccessDeniedException` en cadena → `UnauthenticatedAccessListener` no los toca.

Por qué así: mantiene **un solo sitio de mapeo** (`ProblemDetailsFactory`), reutiliza el mecanismo de marker + `type()`
override, y hace la neutralidad pre-identidad el **comportamiento por defecto** (todo lo no-reconocido colapsa a 401).
`account-suspended` es específico (carga un siguiente paso: contactar al admin); `DEACTIVATED` es genérico **por diseño
semántico** (sin paso accionable), no solo anti-enumeración — la frontera de especificidad es la **demostración de
identidad** (SI-14), no el tipo de error. **Test que lo blinda: Behat asserta `SUSPENDED → 403 account-suspended` y
`DEACTIVATED → 403 forbidden` — sin él, el reenvoltorio pasa desapercibido.**

### El invariante ≥1 ADMIN (AC7) — el punto que más decisión pide

II-1 estableció «la org mantiene siempre ≥1 `ADMIN` activo» y **difirió explícitamente** su preservación bajo
suspend/deactivate a **esta** historia (epic II-1 AC6). El conteo «admins activos» necesita cruzar dos contextos:
`Membership.roles` (contexto `Organization`) **y** `User.status=ACTIVE` (contexto `Iam`). Un `Iam` que injecta el repo
de `Organization` a pelo es una violación de aislamiento **Nivel-1** (`php.lint.bounded-context` + deptrac). → **seam
publicado** (per-file en `.bounded-context-allowlist` + deptrac `skip_violations`, patrón `Role` de II-1; **nunca**
forma global `* =>` — `DeptracSeamSyncGateTest` la prohíbe). Además, `membership.user_id` **no tiene FK** (#462): una
`Membership` huérfana de un `User` hard-borrado haría `hasRole(ADMIN)` `true` contra un fantasma → el conteo **debe**
resolver el `User` y exigir `status=ACTIVE` real. **Esto es una decisión de topología/seam → ver «Decisión D»
(confirmar con Sergio antes de dev).**

### Persistencia (ya decidida, no re-abrir)

`User` es **state-oriented** (ADR D2/D3; no event-sourcing) — el negocio necesita el snapshot actual del estado de
identidad, no su historia como ledger. Extender `User` con una máquina de estado `INVITED→ACTIVE↔SUSPENDED↔DEACTIVATED`
**no** cambia esa decisión. La *auditabilidad* de las transiciones (NFR10) es **emisión de eventos**, no event-sourcing
— y su wiring de consumidor (audit) se asigna a II-4/5/6/7 en el mapa de cobertura, no a II-3 (ver «Decisión E»).

### Testing (patrones del repo — II-2 es el precedente fresco)

- **Unit domain** (`api/tests/Unit/Iam/Identity/…`): `IdentityStatus`, las transiciones de `User` (legal + ilegal), la
  nullabilidad del password. `@internal` + `#[CoversClass(...)]` **estricto por clase** (SonarCloud `new_coverage ≥ 80%`
  es un gate real — II-1 falló a 78.8%; pon un `#[CoversClass]` por cada clase nueva y cubre todas las líneas nuevas;
  verifica clover local con `make php.unit.coverage`). AAA, un comportamiento por test, fakes in-memory de puertos
  (mirror `InMemoryUserRepository`/`InMemoryMembershipRepository` de II-1) sobre mocks.
- **Behat** (`api/features/…`): el flujo de admisión end-to-end sobre kernel real — (a) `ACTIVE`+password ok → 204
  + cookie; (b) `SUSPENDED`+password ok → 403 `account-suspended`, **sin** `Set-Cookie` de sesión; (c) `DEACTIVATED`
  → 403 genérico, sin sesión; (d) `INVITED` → 401 **byte-idéntico en status+shape** a bad-cred y a inexistente (el test
  compara las tres); (e) invariante SI-10: ninguna sesión antes de la admisión. **Worktree fresco → `make
  php.behat.install` antes de `php.stan`/`php.quality`** (tooling aislado en `api/tools/behat/vendor`).
- **≥1 ADMIN**: test de aplicación — suspender/desactivar al único admin activo → rechazo; con ≥2 admins → ok; conteo
  robusto ante membership huérfana.
- **PWA** (`vitest` + Playwright): `AccessWall` renderiza cada variante; foco al `<h1>`; AA (un `<h1>`, color no-único,
  copy traducible). El test de **ruteo login→muro por `body.type`** solo aplica si Decisión F = F-a (login real); con F-b
  se limita al render/unit del componente. Ojo E2E: la suite golpea la stack viva (`https://localhost`); no host-spawn.
- **Presupuestos PHPMD que muerden a los tests** (II-1/II-2): `TooManyPublicMethods` ≤ 10 (los `DataProvider` estáticos
  cuentan → consolida tests de frontera); `CouplingBetweenObjects` ≤ 13 (aplica a clases de test → mantén los tests de
  contrato/CLI magros, sin stubs de más). Rector impone `assertNotInstanceOf`/`assertSame`/`serialize()` en varios
  casos — deja que Rector gane, no lo suprimas.

### Gotchas heredados (verificados en ii-0/1/2)

- `make php.behat.install` en worktree fresco antes de gates. · `php.stan` exit 139 (segfault web-worker) →
  `PHP_SERVICE=messenger_worker`. · `routes.yaml` usa resource **estrecho** `../src/Iam/Identity/Infrastructure/Http/`
  — II-3 **no** añade controlador (el muro sale del body del fallo), así que no se toca. · `doctrine.yaml`
  `auto_mapping:false` con `Iam` ya mapeado por atributo sobre `src/Iam` → la columna nueva la coge `db.diff`. ·
  Verificar **fresco** sobre el path del worktree, confiar en el exit code recién impreso (no logs viejos). · Barrer del
  diff final los IDs de story/NFR en **comentarios de código** (permitidos en este spec, prohibidos en `api/src`).

### Decisiones a confirmar al inicio del dev (bajo/medio riesgo — recomendaciones flagged, mirror ii-1/ii-2)

- **Decisión A — camino de construcción `INVITED` vs `ACTIVE`.** Recomendado: `invite()` (nace `INVITED`, sin password)
  + `activate(HashedPassword)` (`→ACTIVE`), y conservar `register()` para el bootstrap/fixtures `ACTIVE` (el
  `CreateInitialAdministrator` de II-1 crea un admin ya activo). Confirmar si el bootstrap/`CreateUser` migra a
  `invite()+activate()` o sigue creando `ACTIVE` directo (el accept-invitation real que consume `invite()` es **II-4**).
- **Decisión B — dónde vive el mapeo del error graduado.** Recomendado: **evolucionar** el
  `ProblemDetailsAuthenticationFailureHandler` con la rama post-identidad (un solo sitio, ya es el punto de traducción
  auth→RFC 9457). Alternativa: un listener/traductor dedicado `AccountStatusException→DomainException`. *(Ya resuelto por
  el stack: tanto suspended como deactivated van por `DomainException implements Forbidden` — el bridge
  `AccessDeniedException` NO sirve para el genérico porque `UnauthenticatedAccessListener` lo convierte en 401.)*
- **Decisión C — retiro de `User.roles` (candidato histórico de II-3).** II-1 dejó `SecurityUser::getRoles()` /
  `PermissionVoter` leyendo `User.roles` (duplicado transicional con `Membership.roles`), y marcó su retiro como
  *candidato* a II-3 «donde ya se toca el auth path». **Recomendación: NO plegarlo aquí.** No es un AC de II-3, agranda
  el blast-radius sobre el TCB de auth (resolver roles desde `Membership` = nuevo seam cross-contexto en el hot-path de
  cada request) y compite con la Decisión D. Propuesta *propose-and-stop*: dejarlo como follow-up del ADR RBAC.
  **RATIFICADO por Sergio (2026-07): NO se retira `User.roles` en II-3** — el auth path/voter sigue leyendo `User.roles`
  sin tocar; retiro = follow-up del ADR RBAC.
- **Decisión D — seam del conteo de admins activos (AC7). RATIFICADA (Winston+Amelia → consenso de forma; ChatGPT externo
  → timing; ver detalle en T6).** **Forma:** puerto **consumer-owned** en `Iam/Identity/Domain`
  (`ActiveAdministratorDirectory.keepsAnActiveAdminWithout(userId): bool`, org implícita single-tenant); guarda en el caso
  de uso de **aplicación** (no en `User.suspend()` — invariante cross-aggregate, y #462 `demote`/`remove` reutiliza el
  punto); **síncrona** (precondición, no reacción — un evento post-hoc detecta pero no rechaza); excepción
  `LastActiveAdministratorProtected implements Conflict` → **409**; adapter futuro = INNER JOIN `membership ⋈ identity_user`
  (`roles=ADMIN AND status=ACTIVE`) → el INNER JOIN excluye al fantasma #462. **Timing = B (diferir el adapter):** en II-3
  solo puerto + máquina de estados + caso de uso + tests con fake in-memory; **el adapter Doctrine real, las entradas de
  seam y el wiring van a #462** (el slice con el primer caller real, que además necesita la misma política para
  `demote`/`remove` → reshapea el puerto para los 4 ops). **Por qué B:** YAGNI/Rule-of-Three (adapter sin caller =
  infra especulativa; construirlo fija la firma del puerto antes de su consumidor); **refuerzo del repo** (diferir = 0
  entradas de seam → `DeptracSeamSyncGateTest` verde, y evita un test de integración con fixtures DB que hundiría el gate
  de cobertura por líneas nuevas). **Guardarraíl:** cuando #462 añada un caller, el servicio deja de ser unused → el
  puerto sin bindear **falla el contenedor en compile-time** → obliga a bindear el adapter real; caller-less → dropped →
  arranca (verificar empíricamente en dev; si no dropea → `services.yaml exclude` + comentario, **nunca** un stub — un
  stub = suspender al último admin en silencio). Alternativa descartada: adapter+seam ahora (postura Winston) — su
  blueprint (adapter en `Organization/Membership/Infrastructure`, JOIN, entradas exactas, `FOR UPDATE` anti-TOCTOU) se
  **difiere a #462**, registrado en `deferred-work.md`, no se pierde.
- **Decisión F — alcance del PWA (AC6), porque el login es un MOCK.** `LoginForm.tsx` no llama a la API (identidad
  hardcodeada vía `useSession().login`). Dos caminos: **(F-a) desmockear el login en II-3** — mutación real `POST
  identity_login` + parseo del body RFC 9457 + ruteo por `type` + `AccessWall`; coherente con «II-3 dueña de los estados
  de B1» pero es un **bump de alcance real** (introduce la integración de auth del cliente). **(F-b) descope PWA a solo el
  componente `AccessWall` + sus tests unitarios** (sobre `ErrorScreen`), difiriendo el ruteo login→muro end-to-end a la
  historia que desmockee auth (candidata II-4, «1ª superficie pública nueva»). **RATIFICADO por Sergio: F-a** — II-3
  desmockea el login (ver T7, incl. la ramificación «cómo se establece la identidad post-204»: who-am-i real vs mock
  mínimo de identidad/roles, sin expandir a RBAC del cliente; si crece de más → parar y avisar a Sergio).
- **Decisión E — emisión de eventos `UserSuspended`/`UserDeactivated` (NFR10 vs R1).** El mapa de cobertura asigna NFR10
  a II-4/5/6/7, pero la regla R1 del proyecto dice «los eventos de dominio se emiten **siempre**». Como II-3 implementa
  las transiciones, emitir esos eventos ahora es barato y coherente; el **consumidor** de audit se cablea después.
  Recomendación: emitir los eventos ahora (R1) sin reactor/Mercure (R2). Confirmar, dado que las transiciones aún no
  tienen superficie de disparo pública (podría ser YAGNI hasta que exista el caller).

### Fuera de alcance (frontera explícita — no lo hagas en II-3)

- **Ecualización de timing** de las tres respuestas pre-identidad (SI-12 aquí es **status+shape**, no latencia) →
  **II-8** (hash-dummy siempre, incl. `INVITED`/forgot).
- **Marker `invalid-token`** → **II-4** (split de FR9: II-3 = tipos de estado, II-4 = `invalid-token`).
- **Tipo operacional 503-family del gate** → **II-7** (nace con el Session Admission Gate).
- **`account-locked` / lockout `LockedUntil`** → **II-6** (deja `checkPostAuth` extensible; el muro `locked` es II-6).
- **Agregado `Session`, registro, gate, regeneración de sesión** → **II-7** (II-3 solo garantiza que la admisión precede
  a la sesión; **no** crea `Session`).
- **`demote`/`remove` last-admin + UI/CLI de gestión de miembros** → slice diferido / **#462**.
- **Invitación / accept / reset** → **II-4/II-5**. `SingleUseToken` (II-2, ya en `main`) **no** se toca aquí.

### Project Structure Notes

- Nuevos ficheros bajo `Iam/Identity/{Domain/Enum, Domain/Exception, Infrastructure/Security}` y
  `Iam/Identity/Application` — el contexto `Iam` ya está registrado en `deptrac.yaml` (II-0), mapeado en `doctrine.yaml`,
  y `Shared/ErrorContract` es siempre importable → **sin** capas deptrac nuevas. **Excepción:** si la Decisión D crea el
  seam del conteo de admins, ese sí es un par per-file en `.bounded-context-allowlist` + `skip_violations`.
- PWA: `AccessWall` como componente de acceso; ubicación coherente con los componentes `(auth)`/`access` existentes
  (confirmar `components/erpify` vs `context/shared/access/infrastructure/ui` durante el dev — sin adelantar la frontera
  de `components/{ui,erpify}` no autorizada).
- Migración en `api/migrations/2026/` vía `db.diff` (editable en esta rama; inmutable tras merge).

### References

- [Source: `docs/adr/identity-invitation-lifecycle.md` D3/D4/D12] — modelo `INVITED`, admisión `UserChecker` tres
  momentos, contrato de error graduado (alternativa «sesión-luego-check» descartada).
- [Source: `_bmad-output/planning-artifacts/arch-addendum-identity-invitation.md` SI-10/SI-12/SI-14, PR-3].
- [Source: `_bmad-output/planning-artifacts/epics-identity-invitation-lifecycle.md` Story II-3 (líneas 604-649); FR3/FR9;
  DAG líneas 450-459].
- [Source: `api/src/Shared/ErrorContract/Application/ProblemDetailsFactory.php` líneas 112-132, 258-285] —
  `MARKER_STATUS_MAP`, bridges `AccessDenied`/`Authentication`, `resolveDomainType`.
- [Source: `docs/api-error-contract.md` líneas 45-63, 341-349] — marker→status, `type()` override, NFR26 «actualiza esta
  página».
- [Source: `_bmad-output/planning-artifacts/epics-identity-invitation-lifecycle.md` Story II-1 AC6] — origen del
  invariante ≥1 ADMIN y su deferral explícito a II-3.
- [Source: `_bmad-output/implementation-artifacts/deferred-work.md`, issue #462] — membership huérfana / conteo de admin
  robusto.
- Precedentes de código: `Domain/Enum/Role.php` (forma del enum) · `Domain/HashedPassword.php` (VO opaco) ·
  `Organization/Membership` (patrón cross-context por id + seam `Role`).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Opus 4.8, 1M context).

### Debug Log References

Verificación fresca sobre el path del worktree (exit codes recién impresos):

- `make php.stan` → OK, No errors (859 files).
- `make php.quality` → deptrac **0 violations**; cs-fixer/rector/phpmd limpios (tras envolver 3 líneas >120 y aceptar mutaciones equivalentes de rector: `assertNotInstanceOf`, `array_any`, promoción de `$status`).
- `make php.lint.error-contract` → OK · `make php.psalm.taint` → No errors found (99.79% inferido).
- `make php.unit` → **1660 tests / 7396 assertions** OK · `make php.behat` → **253 escenarios / 2333 steps** OK (incl. login ACTIVE→204+cookie, SUSPENDED→403 `account-suspended` sin `Set-Cookie`, DEACTIVATED→403 `forbidden` sin cookie, INVITED→401 indistinguible).
- `make sf lint:container` → OK; `debug:container ChangeUserStatus` → *"service has been removed or inlined when the container was compiled"* (caller-less → descartado; puerto sin bindear no rompe el arranque — Decisión D verificada, sin stub).
- `make pwa.quality` → EXIT 0 · `make pwa.test.unit` → **187 files / 959 tests** OK.
- Login E2E vivo (worktree HTTPS `:32939`, `PLAYWRIGHT_BASE_URL`/`PLAYWRIGHT_API_BASE_URL` al puerto efímero) → **3 passed** (setup + valid-creds→backoffice + wrong-creds→error neutro).
- Smoke API vivo (curl): `e2e@erpify.test` (ACTIVE, creado por el CLI de bootstrap → confirma Decisión A `register()`→ACTIVE con la columna `status`) → 204 + `Set-Cookie httponly samesite=lax`; password erróneo → 401.

### Completion Notes List

- **Decisiones abiertas confirmadas al inicio** (A y E; C/F/D ya cerradas en el spec): **A** = bootstrap mantiene `register()`→ACTIVE (invite()/activate() para el ciclo; consumidor real de invitación = II-4). **E** = emitir `UserSuspended`/`UserDeactivated` ahora (R1) — grabados en el agregado y publicados por el caso de uso caller-less (adapter/caller a #462).
- **Admisión de tres momentos (SI-10/D-c)**: `UserChecker.checkPreAuth` rechaza `INVITED` con `AccountStatusException` plana (→ re-envuelta a `BadCredentialsException` con `expose_security_errors: None` → 401 uniforme, password nunca verificado); `checkPostAuth` rechaza `SUSPENDED`/`DEACTIVATED` con `CustomUserMessageAccountStatusException` (exenta del re-envoltorio) → el handler ramifica a 403. **Cero artefactos de sesión** en los muros (Behat asserta ausencia de `Set-Cookie`).
- **Error graduado (SI-14/D12)**: `AccountSuspended` (403 `account-suspended`, marker `Forbidden` + `type()` override) vs `AccountDeactivated` (403 `forbidden` genérico, `type()` vacío). Sin marker nuevo → drift gate no dispara; `api-error-contract.md` actualizado (NFR26) con la tabla de admisión.
- **Invariante ≥1 ADMIN (AC7)**: enforcement en `ChangeUserStatus` (único punto, `transactional`); puerto consumer-owned `ActiveAdministratorDirectory` (bool, nada cruza la frontera); `LastActiveAdministratorProtected` (409); fantasma #462 modelado por el fake in-memory (spec ejecutable del INNER JOIN). Adapter Doctrine + seam + `FOR UPDATE` **diferidos a #462** (`deferred-work.md`).
- **PWA (F-a)**: login desmockeado end-to-end vía puerto `LoginRepository` (DIP) → `ApiLoginRepository` mapea 204/401/403 a un `LoginOutcome` tipado (ruteo por `body.type`); `AccessWall` card-content (dentro de `AuthLayout`, tono neutro no-danger, foco-al-`<h1>`, un `<h1>`, `safeHref`); enum `UserStatus` reconciliado `{INVITED,ACTIVE,SUSPENDED,DEACTIVATED}` con todos sus consumidores. Identidad/roles post-204 siguen **mockeados mínimamente con TODO** (who-am-i diferido; solo el login es real).
- **Mejora argumentada (boy-scout)**: `ChangeUserStatus` depende del puerto framework-free `TransactionManager` en vez de `EntityManagerInterface` directo — DIP correcto y **deptrac-clean sin crecer el baseline** (los usos legacy de Bank están grandfathered; el propio adapter documenta este puerto como "the deptrac-clean seam").
- **Higiene**: barridos los refs de ticket `#462` y IDs de story/NFR de comentarios en `api/src`/tests; comentarios `// Mocked:` del login eliminados.
- **Fuera de alcance respetado**: timing-equalisation (II-8), `invalid-token` (II-4), gate 503 (II-7), lockout `LockedUntil` (II-6, punto de extensión dejado en `checkPostAuth`), `Session`/registro (II-7), `demote`/`remove` + adapter/CLI de miembros (#462).
- **Follow-up (#462)**: cuando aterrice el caller real de suspend/deactivate + `demote`/`remove`, bindear `DoctrineActiveAdministratorRegistry` (el puerto sin bindear falla el contenedor en compile-time y lo obliga; **nunca** un stub) y añadir `#[IsGranted]` a su superficie HTTP. El E2E del *muro* en vivo también se habilita ahí (hoy no hay tooling para poner un usuario en SUSPENDED/DEACTIVATED en la stack viva → muro cubierto por Behat API + unit PWA).

### File List

**API — `src/Iam/Identity/`:**

- `Domain/Enum/IdentityStatus.php` (nuevo)
- `Domain/Entity/User.php` (estado + password nullable + máquina de estados + eventos)
- `Domain/Event/UserSuspended.php`, `Domain/Event/UserDeactivated.php` (nuevos)
- `Domain/Exception/{InvalidIdentityTransition,AccountSuspended,AccountDeactivated,LastActiveAdministratorProtected,UserNotFound}.php` (nuevos)
- `Domain/Repository/ActiveAdministratorDirectory.php` (nuevo puerto)
- `Application/ChangeUserStatus.php` (nuevo)
- `Infrastructure/Security/UserChecker.php`, `InvitedAccountException.php`, `SuspendedAccountException.php`, `DeactivatedAccountException.php` (nuevos)
- `Infrastructure/Security/SecurityUser.php` (`getPassword(): ?string`, `status()`), `ProblemDetailsAuthenticationFailureHandler.php` (rama post-identidad)

**API — config / migración / fixtures / docs:**

- `config/packages/security.yaml` (`user_checker`), `config/reference.php` (regenerado)
- `migrations/2026/Version20260709230444.php` (nuevo)
- `tests/DataFixtures/UserFixtureFactory.php`, `Fixtures/User.yaml`, `Fixtures/Membership.yaml` (variantes INVITED/SUSPENDED/DEACTIVATED + companions)
- `features/backoffice/identity/login.feature` (escenarios de muro)
- `docs/api-error-contract.md` (sección de errores de admisión)

**API — tests:** `tests/Unit/Iam/Identity/**` (IdentityStatus, UserLifecycle, User, SecurityUser, UserChecker, eventos, excepciones, ChangeUserStatus + fakes `InMemoryActiveAdministratorDirectory`/`RecordingEventBus`, ProblemDetailsAuthenticationFailureHandler) · `tests/Functional/Iam/Identity/DoctrineUserRepositoryTest.php` · `tests/Unit/Iam/Identity/Domain/Entity/Mother/UserMother.php`

**PWA — `pwa/src/`:**

- `context/backoffice/user/domain/{LoginCredentials,LoginOutcome,LoginRepository}.ts`, `context/backoffice/user/infrastructure/ApiLoginRepository.ts` (nuevos)
- `context/shared/error/infrastructure/ui/AccessWall.tsx` (nuevo) + `error/infrastructure/ui/index.ts` (export)
- `app/(auth)/_components/LoginForm.tsx` (desmock), `context/shared/http-client/infrastructure/ApiEndpoints.ts` (LOGIN), `context/shared/dependency-injection/infrastructure/Container.ts` (binding)
- `context/shared/access/domain/UserStatus.ts` (reconciliación) + consumidores `app/backoffice/users/_components/{UserForm,UserStatusBadge}.tsx`, `_lib/userLabels.ts`, `context/backoffice/user/infrastructure/userSeed.ts`, `context/shared/access/infrastructure/ui/AuthProvider.tsx`

**PWA — tests:** `tests/context/backoffice/user/ApiLoginRepository.test.ts`, `tests/context/shared/error/infrastructure/ui/AccessWall.test.tsx` (nuevos) · `tests/app/(auth)/loginForm.test.tsx`, `tests/context/shared/access/{AuthProvider.test.tsx,domain/authorize.test.ts}`, `tests/context/backoffice/user/schemas.test.ts` (enum) · `tests/e2e/backoffice/login.spec.ts` (nuevo, E2E vivo)

**bmad:** `_bmad-output/implementation-artifacts/{sprint-status.yaml,deferred-work.md}` (ii-3 → review; nota #462 afinada)

### Change Log

| Fecha       | Cambio                                                                                                   |
|-------------|----------------------------------------------------------------------------------------------------------|
| 2026-07-10  | II-3 implementada: `IdentityStatus` + máquina de estados en `User`, `UserChecker` (admisión tres momentos), error graduado 403, invariante ≥1 ADMIN (puerto + fake, adapter a #462), PWA login desmockeado + `AccessWall` + reconciliación `UserStatus`. Todos los gates (API + PWA) verdes; login E2E vivo verde. Status → review. |

### Review Findings

_Code review adversarial (Blind Hunter · Edge Case Hunter · Acceptance Auditor), 2026-07-10. Veredicto: implementación fiel — 7/7 AC satisfechos, decisiones A/C/D/E/F honradas, fuera-de-alcance respetado. **Sin hallazgos alto/crítico.** 1 decisión, 3 patches, 5 diferidos, 4 descartados por diseño._

#### Decision needed

- [x] [Review][Decision→Patch] Transición de estado ilegal mapea a HTTP 500 — **RESUELTO (opción 1, 2026-07-10):** dar a `InvalidIdentityTransition` un marker `Conflict` (409), alineado con `LastActiveAdministratorProtected`. Movido a Patch abajo.

#### Patch

- [x] [Review][Patch] Transición ilegal → 409, no 500: `InvalidIdentityTransition implements Conflict` [`api/src/Iam/Identity/Domain/Exception/InvalidIdentityTransition.php`] — marker-less hoy → `ProblemDetailsFactory` `default` terminal → 500 `unhandled-exception`; añadir `implements Conflict` (mapea 409, patrón `LastActiveAdministratorProtected`) para que el contrato sea correcto cuando #462 cablee el caller. **Actualizar también** `tests/Unit/.../InvalidIdentityTransitionTest` (hoy asierta que NO lleva marker `ClientError`). No toca `api-error-contract.md` (marker `Conflict` ya existe/documentado — no es marker nuevo).
- [x] [Review][Patch] Fallo de red/transporte en el login no da feedback al usuario [`pwa/src/app/(auth)/_components/LoginForm.tsx:42`] — `onSubmit` no envuelve `repo.login()` en try/catch; `ApiLoginRepository.login` re-lanza todo fallo no-`HttpError` (red/DNS/transporte), que se propaga fuera del handler de RHF: `isSubmitting` se resetea y el usuario no ve nada (ni error, ni toast, ni muro). Es el path de auth REAL (login desmockeado). Fix: capturar el fallo no-`HttpError` y mostrar un error neutro reintenable.
- [x] [Review][Patch] Docblock de `AccountDeactivated` sobre-afirma indistinguibilidad [`api/src/Iam/Identity/Domain/Exception/AccountDeactivated.php:15`] — dice «indistinguishable on the wire from any other generic denial», pero solo el `type` colapsa a `forbidden`; el `title` («Your account is not active.») difiere del genérico («Access denied.»), así que SÍ es distinguible por título. Reformular a «indistinguible en `type`».
- [x] [Review][Patch] Comentario change-relative «(de-mocked)» en `Container.ts` [`pwa/src/context/shared/dependency-injection/infrastructure/Container.ts:187`] — CLAUDE.md prohíbe comentarios que describan el cambio en `pwa/src`; quitar «(de-mocked)» del binding `BackOfficeLoginRepository`.

#### Deferred

- [x] [Review][Defer] Guarda ≥1-ADMIN corre FUERA de la transacción de escritura [`api/src/Iam/Identity/Application/ChangeUserStatus.php:65`] — diferido a #462: `keepsAnActiveAdminWithout()` se lee antes de `transactional()` (:74); el `SELECT … FOR UPDATE` planeado para #462 sería inútil fuera del write-txn → #462 debe mover la guarda dentro de `transactional()`. TOCTOU latente (caller-less hoy).
- [x] [Review][Defer] Estado 0-admins rechaza suspender a un no-admin ajeno con 409 engañoso [`api/src/Iam/Identity/Application/ChangeUserStatus.php:65`] — diferido a #462: la guarda devuelve `false` cuando NO hay admin activo alguno, sea quien sea el target; el adapter real (INNER JOIN) debe acotarla a «el target es el admin cuya baja deja <1». Solo alcanzable en un estado que el invariante prohíbe.
- [x] [Review][Defer] Login real concede ADMIN+wildcard client-side a toda identidad [`pwa/src/app/(auth)/_components/LoginForm.tsx:48`] — diferido a who-am-i: post-204 identidad/roles siguen mockeados con TODO (Decisión F-a); solo display (la API enforcea RBAC). Recomendación: mock por defecto least-privilege (VIEWER) para fallar-cerrado en gates de cliente.
- [x] [Review][Defer] Cliente mapea cualquier `403 forbidden` al muro DEACTIVATED [`pwa/src/context/backoffice/user/infrastructure/ApiLoginRepository.ts:48`] — diferido/by-design (D12): un `forbidden` genérico (cross-origin de `LoginOriginListener`, Behat `login.feature:62-73`) impersonaría el muro «desactivado». Inalcanzable same-origin. Al entrar who-am-i / nuevas fuentes de 403, rutear sobre señal positiva de desactivación.
- [x] [Review][Defer] AC4 sin test único que compare las tres respuestas pre-identidad [`api/features/backoffice/identity/login.feature:110`] — se asertan idénticas en 3 escenarios separados (status+type+title) pero sin comparación cara a cara ni aserción de forma/tamaño. Garantía estructural (mismo code path). Test de endurecimiento opcional.
