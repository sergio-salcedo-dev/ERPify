---
baseline_commit: 19e07cde4501aebaab494bfab8159dd3156e054b
---

# Story II-6 (PR-6): Lockout `LockedUntil` — estado de autenticación observable, complementario al throttler

Status: review

<!-- Épica identity-invitation-lifecycle · FR7 · D7 · SI-12/SI-14 · UX-DR1(locked). Orden de merge safe-first: va tras II-7 (done). Depende SOLO de II-3 (done). -->

## Story

Como responsable de seguridad,
quiero un bloqueo de autenticación persistido y observable tras N intentos fallidos, limpiable por reset/login/TTL,
para que el abuso de credenciales sobre una identidad resuelta se frene con un muro post-identidad, sin delatar nada a un anónimo.

**Comportamiento que introduce:** `failedAttempts` / `lockedUntil` en la identidad; el muro `locked`; el tipo de error `account-locked`.

**Invariantes que consume:** el `UserChecker.checkPostAuth` (II-3, dejó el punto de extensión exacto); SI-12 (la respuesta pre-identidad ya es neutra); el `login_throttling` efímero (II-3/auth-foundation, sigue en pie).
**Invariantes que establece:** la máquina de autenticación `Unlocked/LockedUntil(T)` es **ortogonal** a la identidad (`ACTIVE+Locked` válido; `SUSPENDED+Locked` **no representable**); el lock es sobre **intentos de contraseña**, no sobre la identidad (habilita D-b en II-5); el `login_throttling` efímero **no delata** (su respuesta se pliega al fallo pre-identidad).

## Acceptance Criteria

1. **(Umbral → lock persistido, muro post-identidad)** — Dada una identidad resuelta con N intentos fallidos consecutivos, cuando se supera el umbral, entonces `lockedUntil` se persiste en `identity_user` y un login posterior con credenciales **correctas** dentro de la ventana es rechazado por `checkPostAuth` como **`account-locked`** (403, RFC 9457, con siguiente paso «Recuperar mi acceso»). (FR7, D7, SI-14)

2. **(Invisibilidad al anónimo)** — Dado un atacante anónimo que provoca fallos sobre una cuenta, entonces **nunca** ve el muro `locked`: solo aparece **tras** presentar credenciales válidas (regla de los tres momentos / SI-12). Un test lo prueba (credenciales incorrectas sobre cuenta bloqueada → 401 uniforme, no 403).

3. **(Ortogonalidad con la identidad)** — Dada una identidad `SUSPENDED` que además superó el umbral, cuando intenta login, entonces ve el muro `suspended`, **no** `locked` — `SUSPENDED+Locked` no es representable; el arm de estado precede al arm de lock en `checkPostAuth`. (máquina de auth ortogonal a `IdentityStatus`)

4. **(Limpieza)** — Dado un login con éxito **o** un `lockedUntil` ya lapsado (TTL vencido), cuando ocurre, entonces `lockedUntil` / `failedAttempts` se **limpian** (login con éxito: limpieza explícita; TTL vencido: `checkPostAuth` trata un `lockedUntil` en el pasado como desbloqueado y la limpieza física ocurre en el siguiente éxito — lazy clear, sin scheduler). (FR7)

5. **(Neutralidad del throttler)** — Dado el `login_throttling` saturado (per-IP+email, efímero), cuando responde, entonces usa el **mismo status/copy** que el fallo pre-identidad (401 uniforme «Correo o contraseña incorrectos»), no un «demasiadas solicitudes» distinguible. **Ya funciona hoy** vía el catch-all del failure handler — verificar que II-6 no lo regresa. (D10, FR7, SI-12)

6. **(Muro `locked` en el PWA)** — Dado el muro `locked`, cuando se renderiza, entonces usa `AccessWall` variante `locked` (paleta **neutra**, nunca `danger`) con **dos** acciones: «Recuperar mi acceso» → `Routes.FORGOT_PASSWORD` (B2) **y** «Iniciar sesión» → `Routes.LOGIN` (D-a). (UX-DR1)

7. **(Observabilidad — NFR10)** — Cuando una identidad se bloquea, entonces se emite un evento de dominio (`AccountLocked` / `UserLocked`, `Iam.Identity`, payload **sin PII**) por el `EventBus`/outbox — **sin** emitir eventos que re-enumeren en fallos pre-identidad (un fallo sobre email inexistente no escribe ni emite nada). (NFR10, consistente con SI-12)

## Tasks / Subtasks

- [x] **Task 1 — Modelo de dominio del lock en `User`** (AC: 1, 3, 4, 7)
  - [x] Añadir a `src/Iam/Identity/Domain/Entity/User.php` dos columnas: `#[ORM\Column(name: 'failed_attempts', type: Types::INTEGER)] private int $failedAttempts` (default 0 en ctor) y `#[ORM\Column(name: 'locked_until', type: Types::DATETIME_IMMUTABLE, nullable: true)] private ?DateTimeImmutable $lockedUntil`. Inicializar ambos en el ctor privado (todas las factories `register`/`invite`/`activate` funnel por él → arrancan `failedAttempts=0`, `lockedUntil=null`).
  - [x] Comportamiento de dominio (Tell-Don't-Ask, política DENTRO del agregado, no en el listener): `recordFailedAttempt(Clock): void` (incrementa; al alcanzar el umbral computa `lockedUntil = clock->now()->modify('+15 minutes')` y `record(new AccountLocked(...))`), `clearLockout(): void` (resetea ambos a 0/null; idempotente), `isLockedAt(DateTimeImmutable $now): bool` (`lockedUntil !== null && lockedUntil > now`). Constantes de dominio nombradas **`MAX_FAILED_ATTEMPTS = 10`** y **`LOCK_DURATION = 'PT15M'`/`'+15 minutes'`** (Decisión A ratificada).
  - [x] **Reloj**: inyectar `Clock` para computar/comparar `lockedUntil` — NO usar `SystemClock::now()` estático (gotcha de `deferred-work.md`: bajo `FixedClock` en test `expiresAt` computado y `createdAt` estático divergen). Mirror del patrón de II-7 (`StartSession` recibe `Clock`).
  - [x] `AggregateRoot::record()` para el evento; `pullDomainEvents()` ya existe.

- [x] **Task 2 — Excepción de dominio `AccountLocked` (RFC 9457, 403)** (AC: 1)
  - [x] `src/Iam/Identity/Domain/Exception/AccountLocked.php`: `final class AccountLocked extends DomainException implements Forbidden` con ctor `parent::__construct(type: 'account-locked', title: '<copy accionable ES: cuenta temporalmente bloqueada, recupera tu acceso>')`. **Mirror EXACTO de `AccountSuspended`** (no `AccountDeactivated`, que pasa `type: ''`). Reutiliza el marcador `Forbidden` existente → 403, **sin tocar** `MARKER_STATUS_MAP` ni `MarkerStatusMapContractTest` (siguen en 9).
  - [x] Test `tests/Unit/Iam/Identity/Domain/Exception/AccountLockedTest.php` (mirror de `AccountSuspendedTest`): `type()==='account-locked'` + `implementsInterface(Forbidden::class)`.

- [x] **Task 3 — Excepción de seguridad `LockedAccountException` + arm en `UserChecker`** (AC: 1, 2, 3)
  - [x] `src/Iam/Identity/Infrastructure/Security/LockedAccountException.php`: `extends CustomUserMessageAccountStatusException` (cuerpo vacío, mirror de `SuspendedAccountException`). **CRÍTICO**: NO `AccountStatusException` plana ni la `LockedException` de Symfony — ambas serían re-envueltas a `BadCredentialsException` → 401 bajo `expose_security_errors: None` (default). Solo `CustomUserMessageAccountStatusException` escapa el re-wrap y llega al 403.
  - [x] `src/Iam/Identity/Infrastructure/Security/UserChecker.php` `checkPostAuth`: sustituir el comentario placeholder (`// A future time-boxed lockout state adds its post-auth arm here...`, última línea) por el arm de lock **DESPUÉS** de los arms SUSPENDED/DEACTIVATED (AC3 — el estado precede al lock): `if ($user->isLockedAt($clock->now())) { throw new LockedAccountException(); }`. `UserChecker` pasa a recibir `Clock` por ctor (hoy es `new UserChecker()` sin deps) → registrar el servicio o dejar autowiring del `Clock` puerto.
  - [x] `SecurityUser`: añadir accessor `isLockedAt(DateTimeImmutable $now): bool` delegando a `$this->user->isLockedAt($now)` (mirror de `status()`), para que el checker consulte el dominio sin conocer el agregado internamente.
  - [x] Ampliar `tests/Unit/Iam/Identity/Infrastructure/Security/UserCheckerTest.php`: ACTIVE+locked (>now) → `LockedAccountException`; ACTIVE+lock lapsado (<now) → admite; SUSPENDED+locked → `SuspendedAccountException` (precedencia).

- [x] **Task 4 — Arm en el failure handler** (AC: 1)
  - [x] `src/Iam/Identity/Infrastructure/Security/ProblemDetailsAuthenticationFailureHandler.php`: añadir `if ($exception instanceof LockedAccountException) { throw new AccountLocked(); }` **encima** del catch-all (junto a los guards SUSPENDED/DEACTIVATED). El catch-all (throttling / bad-cred / INVITED re-envuelto) sigue → 401 uniforme (AC5).
  - [x] Ampliar `tests/Unit/.../ProblemDetailsAuthenticationFailureHandlerTest.php`: `LockedAccountException` → `AccountLocked` (mirror del test de graduación de suspended).

- [x] **Task 5 — Contadores: incremento en fallo, limpieza en éxito** (AC: 1, 4, 7) — ver **Decisión B**
  - [x] Listener nuevo `#[AsEventListener(event: LoginFailureEvent::class)]` en `Iam/Identity/Infrastructure/Security/`: resolver la identidad objetivo desde el passport; **solo si existe** (email resuelto a un `User`) invocar un caso de uso de aplicación transaccional que haga `user->recordFailedAttempt(clock)` + `save` + `publish` de eventos en un `TransactionManager->transactional()` (mirror de `ChangeUserStatus`). Email inexistente → **no-op** (sin fila, sin evento → SI-12/AC7; el throttler cubre el flood anónimo).
  - [x] Limpieza en éxito: listener `#[AsEventListener(event: LoginSuccessEvent::class)]` (prioridad **por encima** de `SessionMintingSuccessListener` @ -128, que fail-closea a 503) que invoca un caso de uso `clearLockout` transaccional. **No** plegar la limpieza dentro de `SessionMintingSuccessListener` (un fallo de limpieza no debe bloquear el login ni comprometer su fail-close). Idempotente.
  - [x] Caso(s) de uso en `src/Iam/Identity/Application/` (`RecordFailedLoginAttempt` / `ClearLoginLockout`, o un `LoginAttemptRegistrar` con dos métodos): `final readonly`, inyectan `UserRepository`+`EventBus`+`TransactionManager`+`Clock`, sin tipos framework (patrón `ChangeUserStatus`). Tests con `InMemoryUserRepository`.

- [x] **Task 6 — Migración** (AC: 1)
  - [x] `make db.diff` (NUNCA a mano) → `failed_attempts INTEGER DEFAULT 0 NOT NULL` + `DROP DEFAULT` (backfilla filas existentes; el agregado siempre lo fija explícito) y `locked_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL` (nullable, sin drop). Mirror de `migrations/2026/Version20260709230444.php`. `down()` reversible. `make db.migrate` y verificar `make db.diff` vuelve vacío.

- [x] **Task 7 — Contrato de error (NFR26)** (AC: 1)
  - [x] `docs/api-error-contract.md`: añadir la fila `account-locked` a la tabla **«Login admission errors (identity lifecycle)»** (post-auth, 403, `AccountLocked` Forbidden con `type()` override, «paso real Recuperar mi acceso»). Actualizar la prosa de cierre («ambos reutilizan Forbidden» → «los tres»). **Sin** cambios en la tabla marcador→status, en la log-line ni en `exception_category` (todo derivado de `status`+`DomainException`). El gate `ErrorContractGateTest` **no** dispara (la excepción vive en `Iam/Identity/Domain/Exception/`, no en `Shared/ErrorContract/Domain/Exception/`; sin marcador nuevo).

- [x] **Task 8 — Cobertura Behat (API, muro sin E2E vivo)** (AC: 1, 2, 3, 5)
  - [x] Escenarios: (a) tras N fallos, login con password correcta → 403 `account-locked` **sin `Set-Cookie`**; (b) N fallos + password incorrecta → 401 uniforme (anónimo nunca ve `locked`); (c) SUSPENDED + N fallos → 403 `account-suspended` (precedencia); (d) login con éxito tras un lock lapsado → 204 + limpieza; (e) 0 eventos nuevos en fallos pre-identidad (email inexistente), 1 evento `AccountLocked` al bloquear (contexts outbox de #277). Ojo presupuesto de queries (+2 por write envuelto). El **muro vivo E2E NO se cubre** (sin tooling para poner un user real en locked, igual que suspended/deactivated en II-3) — cubierto por Behat API + unit PWA.

- [x] **Task 9 — PWA: outcome + repositorio** (AC: 6)
  - [x] `pwa/src/context/backoffice/user/domain/LoginOutcome.ts`: `ACCOUNT_LOCKED: "account-locked"` en `LoginProblemType`; `LOCKED: "locked"` en `LoginOutcomeKind` + su miembro de unión.
  - [x] `pwa/src/context/backoffice/user/infrastructure/ApiLoginRepository.ts` `toOutcome`: dentro del bloque 403, antes del fallthrough: `if (problem.type === LoginProblemType.ACCOUNT_LOCKED) return { kind: LoginOutcomeKind.LOCKED };`.
  - [x] Test `tests/context/backoffice/user/ApiLoginRepository.test.ts`: `maps 403 account-locked to locked` (mirror suspended).
  - [x] `UserStatus.ts` **NO** se toca (el lock no es un estado de identidad — es transitorio de la máquina de auth).

- [x] **Task 10 — PWA: `AccessWall` variante `locked` + pila de dos acciones** (AC: 6) — ver **Decisión D**
  - [x] `pwa/src/context/shared/error/infrastructure/ui/AccessWall.tsx`: añadir `LOCKED: "locked"` a `AccessWallVariant`; entrada `COPY[LOCKED]` (icono neutro p.ej. `LockKeyhole`/`Clock`; paleta neutra automática). **Refactor**: el componente hoy hardcodea UNA acción («Sign in»); extenderlo a una **pila de acciones por variante** (D-a: `locked` lleva primaria «Recuperar mi acceso» → `safeHref(Routes.FORGOT_PASSWORD)` + secundaria «Iniciar sesión» → `safeHref(Routes.LOGIN)`; suspended/deactivated conservan su única acción «Iniciar sesión»). Foco-al-`<h1>` y `safeHref` intactos.
  - [x] `pwa/src/app/(auth)/_components/LoginForm.tsx`: `if (outcome.kind === LoginOutcomeKind.LOCKED) { setWall(AccessWallVariant.LOCKED); return; }`.
  - [x] Tests: `tests/context/shared/error/infrastructure/ui/AccessWall.test.tsx` (caso `locked` + aserción de las **dos** acciones y sus hrefs), `tests/app/(auth)/loginForm.test.tsx` (gemelo `locked`).

- [x] **Task 11 — Gates + seguridad** (todas)
  - [x] `make php.stan` por fichero PHP tocado; `make php.quality` al final; `make pwa.quality`. `make php.test` (unit+Behat) y `make pwa.test.unit`. `make php.psalm.taint`.
  - [x] `PRODUCTION_SECURITY_CHECKLIST.md`: +1 línea (lockout persistido per-identidad, complementario al throttler). Revisar el checklist de seguridad de `CLAUDE.md` (auth = security-sensitive).

## Review Findings

Pasada adversarial de 3 capas (Blind Hunter · Edge Case Hunter · Acceptance Auditor) sobre el diff del worktree (2026-07-11). Auditor: los 7 AC cumplidos. Headline: los hallazgos 1+2 componían un DoS de lockout dirigido sostenible — **ambos parcheados**. Consulta del error-path del clear y del timing a Winston/Amelia/Sally (panel unánime); decisiones de Sergio abajo.

- [x] [Review][Patch] **Throttled attempts incrementaban el contador persistente** → un solo IP alcanzaba el lock (el throttle debía pararlo en 5). **APLICADO:** el handler no cuenta `TooManyLoginAttemptsAuthenticationException` (nunca llegó a verificar credencial). [`ProblemDetailsAuthenticationFailureHandler.php`] + test.
- [x] [Review][Patch] **Re-lock pegajoso:** tras expirar el lock, `failedAttempts` seguía en MAX → el siguiente fallo re-bloqueaba + re-emitía `UserLocked` + crecía sin tope. **APLICADO:** `recordFailedAttempt` resetea el contador al observar un lock lapsado (una tanda fresca completa vuelve a bloquear). [`User.php`] + test.
- [x] [Review][Patch] **`submittedIdentifier` capturaba solo `JsonException`.** **APLICADO:** captura `RequestExceptionInterface` (cubre body no-JSON y `email` no-escalar). [`ProblemDetailsAuthenticationFailureHandler.php`].
- [x] [Review][Decision→Patch] **Error-path del clear** (Sergio delegó al panel; consenso). **APLICADO:** `clearLockout(): bool` → el registrar salta la transacción entera en el camino común (99%: sin write → sin modo de fallo → el login siempre entra + un round-trip menos por login). En el camino raro (lock real + falla el write, que cierra el EM compartido → minting condenado) se re-mapea `DBALException → LockoutStoreUnavailable` (503 retryable) en vez de un 500 filtrado; catch estrecho (un bug real sigue aflorando 500). [`User.php` · `LoginAttemptRegistrar.php` · `ClearLockoutOnLoginSuccess.php` · nueva `LockoutStoreUnavailable`] + tests. *Reframe de Amelia: `wrapInTransaction` cierra el EM ante cualquier throwable, así que «best-effort continuar» era imposible → el fix es no escribir en el común.*
- [x] [Review][Decision] **CTA del muro `locked`** → **Sergio: mantener como diseña UX-DR1** («Recover access» primaria; II-5 cablea el reset real). Sin cambio de código.
- [x] [Review][Decision→Defer] **Oráculo de timing (NFR1)** → **Sergio (siguiendo al panel unánime): mantener el incremento SÍNCRONO, diferir a II-8.** El async rompía read-your-writes (lag de materialización anula el lock bajo ráfaga) y solo cerraba el oráculo con dispatch uniforme = amplificador de spam; el término dominante del oráculo es el verify de password, que cierra II-8 con su suelo constant-time. Diferido a `deferred-work.md` (II-8).
- [x] [Review][Defer] **Lost-update race en `failedAttempts`** [`User.php` / `LoginAttemptRegistrar::commit`] — sin `#[ORM\Version]`/`FOR UPDATE`, incrementos concurrentes se pierden (last-write-wins) y pueden doble-emitir `UserLocked`. Deferred, familia de hardening TOCTOU (#462).

Dismissed (2): `locked_until TIMESTAMP(0)` libera ~1s antes (convención del repo, impacto nulo) · el «`commit()` abre transacción en `clear` limpio» **quedó resuelto** por el bool-skip del error-path.

### Review Findings — bmad-code-review (2026-07-12)

Segunda pasada adversarial de 3 capas (Blind Hunter · Edge Case Hunter · Acceptance Auditor, capacidad Opus) sobre `d9fe5c88`. **Auditor: los 7 AC siguen cumplidos, sin contradicción spec↔código.** Triaje: 1 decisión · 2 patches · 1 diferido nuevo (+1 ya diferido) · 1 descartado. Los tres hallazgos accionables convergen en `recordFailure`: el **gemelo endurecido a medias del `clear`** — el bool-skip y el remap-a-503 se aplicaron solo al camino de éxito.

- [x] [Review][Decision→Patch] **[APLICADO 2026-07-12 · Opción B]** **`recordFailure` sin guarda DBAL → 500 no capturado + oráculo de status-code en el camino de fallo de login.** `onAuthenticationFailure` llama `recordFailure` sin try/catch; `commit()`→`transactional()` puede lanzar `DBALException` (lo prueba el catch gemelo de `ClearLockoutOnLoginSuccess`). Un `DBALException` no es `DomainException` → **500 `unhandled-exception`** en un camino que antes era SIEMPRE 401 uniforme. Peor: el write solo se intenta si el email resuelve a una fila → bajo fallo de escritura, email-existente = 500 vs email-desconocido = 401: **oráculo de enumeración por status-code que rompe SI-12** (distinto del oráculo de *timing* ya diferido a II-8 — un suelo constant-time NO lo cierra). **Recomendación (Opción B): capturar `DBALException` y proseguir al 401 uniforme (best-effort; se pierde un incremento durante un incidente de BD, aceptable — nada aguas abajo necesita el EM aquí, a diferencia del minting). Cierra el 500 Y el oráculo.** Opción A (espejo del clear): remap a `LockoutStoreUnavailable` (503) — mata el 500 pero deja un oráculo 503-vs-401 más débil. [`api/src/Iam/Identity/Infrastructure/Security/ProblemDetailsAuthenticationFailureHandler.php:71-73` · `api/src/Iam/Identity/Application/LoginAttemptRegistrar.php:57,81-87`]
- [x] [Review][Patch] **[APLICADO 2026-07-12]** **`recordFailure` commitea incondicionalmente (BEGIN/COMMIT vacío) en los caminos no-op.** `recordFailedAttempt()` es no-op para identidad no-ACTIVE y para cuenta ya bloqueada, pero `recordFailure` llama `commit()` siempre → transacción vacía por cada intento contra una cuenta **ya bloqueada** (el estado estacionario del ataque distribuido que el lock existe para abaratar) o contra una fila SUSPENDED/DEACTIVATED/INVITED. Asimétrico con `clear()`, que salta la transacción vía `clearLockout(): bool`. Fix: `recordFailedAttempt(): bool` (espejo de `clearLockout`) + `if (!$user->recordFailedAttempt(...)) { return; }`. Reduce además la superficie del hallazgo de decisión. [`api/src/Iam/Identity/Application/LoginAttemptRegistrar.php:55-57` · `api/src/Iam/Identity/Domain/Entity/User.php:182-207`]
- [x] [Review][Patch] **[APLICADO 2026-07-12]** **El PWA mapea el nuevo 503 `LockoutStoreUnavailable` a «Invalid email or password».** `ApiLoginRepository.toOutcome` solo trata el 403; un 503 (nuevo, cuando falla el clear en un login por lo demás exitoso) cae al fallthrough `INVALID_CREDENTIALS` → `LoginForm` muestra el error persistente de credenciales a un usuario que tecleó credenciales **correctas**, en vez del `requestFailed` («Something went wrong. Please try again.»). Fix: rutear `status >= 500` (o `service-unavailable`) a un desenlace reintentar (nuevo `LoginOutcomeKind` o propagar el `HttpError` 5xx para que el `catch` de `LoginForm` fije `requestFailed`). [`pwa/src/context/backoffice/user/infrastructure/ApiLoginRepository.ts:44-56` · `pwa/src/app/(auth)/_components/LoginForm.tsx:69-73`]
- [x] [Review][Defer] **La rama 503 de `ClearLockoutOnLoginSuccess` no invalida la sesión (asimetría con el minting)** [`api/src/Iam/Identity/Infrastructure/Security/ClearLockoutOnLoginSuccess.php:49-53`] — deferred: inerte tras el gate fail-closed de II-7; el fix requiere inyectar la sesión/request en el listener.
- [x] [Review][Defer] **Lost-update race en `failedAttempts`** [`api/src/Iam/Identity/Application/LoginAttemptRegistrar.php`] — deferred, ya registrado (deferred-work.md, familia #462); no se duplica.

Descartado (1): el texto de AC7 dice «por el `EventBus`/outbox» pero `UserLocked` se graba al `event_store` sin rutear a outbox — **intencional** (wire-on-consumer R1/R2, documentado en Completion Notes); no es defecto.

## Dev Notes

### Superficie de código EXACTA (verificada contra el worktree = main; II-3 y II-7 mergeadas)

**Regla de oro de este slice:** dos familias de excepción paralelas ya existen (II-3) y hay que replicar el trío completo:

| Capa | Lanza | Base | Fichero a crear (mirror) |
|------|-------|------|--------------------------|
| Infra (checker) | `UserChecker::checkPostAuth` | `CustomUserMessageAccountStatusException` | `LockedAccountException` ← mirror `SuspendedAccountException` |
| Dominio (RFC 9457) | `ProblemDetailsAuthenticationFailureHandler` | `DomainException implements Forbidden` | `AccountLocked` (`type:'account-locked'`) ← mirror `AccountSuspended` |

Flujo: `checkPostAuth` lanza la Infra → el failure handler la mapea a la Domain → `ProblemDetailsFactory` emite 403 `account-locked`.

**Punto de extensión de II-3 (literal, `UserChecker.php:59`):** `// A future time-boxed lockout state adds its post-auth arm here (a LockedException until an expiry).` — el arm de lock va ahí, **después** de SUSPENDED/DEACTIVATED.

**`User` (`src/Iam/Identity/Domain/Entity/User.php`)**: `#[ORM\Table(name:'identity_user')]`, `extends AggregateRoot`, ctor **privado** (funnel único). Props hoy: `email` (unique), `passwordHash` (`?string`, name `password_hash`, nullable), `roles` (JSON), `status` (`enumType: IdentityStatus`), + `id`/`createdAt`/`updatedAt` de traits. Factories: `register()`→ACTIVE credencial, `invite()`→INVITED sin password, `activate()` (INVITED→ACTIVE, **sin caller aún**), `suspend()`/`deactivate()` (graban `UserSuspended`/`UserDeactivated`). `IdentityStatus` (`Domain/Enum/`): `INVITED|ACTIVE|SUSPENDED|DEACTIVATED`, sin PENDING, sin métodos de ranking. Mapping = atributos en la entidad (no XML/YAML).

**`SecurityUser` (`Infrastructure/Security/SecurityUser.php`)**: `readonly`, envuelve `User`. `status()` es lo que lee el checker → añadir `isLockedAt()` en paralelo. `getPassword():?string` (null para INVITED). **`UserProvider::refreshUser` re-lee el `User` de BD en cada request (firewall lazy)** → `lockedUntil`/`failedAttempts` persistidos SIEMPRE frescos; no hace falta cache ni invalidación.

**`security.yaml` (`config/packages/security.yaml`)**: firewall `main` `lazy`, `user_checker: …\UserChecker`, `json_login.failure_handler: …\ProblemDetailsAuthenticationFailureHandler`, `login_throttling: { max_attempts: 5 }` (rate-limiter **built-in** de Symfony, per-IP+email, efímero en cache — NO usa `rate_limiter.yaml`, que es solo `anonymous_api`). Ruta login: `LoginController` `#[Route('/login', name:'identity_login', methods:['POST'])]` → 204.

**Familia de eventos (`Domain/Event/`)**: `UserSuspended`/`UserDeactivated` `extends DomainEvent`, `aggregateType()='Iam.Identity'`, `toPrimitives()` **sin PII**, nombre `erpify.iam.identity.<x>`. Mirror para `AccountLocked`/`UserLocked`.

**Contadores — dónde enganchan (hoy NO existe listener de fallo):**
- **Éxito**: `SessionMintingSuccessListener` (`#[AsEventListener(LoginSuccessEvent, priority: -128)]`) — corre **tras** la admisión; fail-closea a 503. La limpieza va en un listener **hermano de prioridad mayor**, no plegada aquí.
- **Fallo**: **no hay** `LoginFailureEvent`/`CheckPassportEvent` listener en `Iam/` → crear uno. La única lógica de fallo hoy es el failure handler (no un listener).

**Patrón de caso de uso transaccional (`ChangeUserStatus.php`)**: `final readonly`, `UserRepository`+`ActiveAdministratorDirectory`+`EventBus`+`TransactionManager`; `transactional(fn){ save; publish(...pullDomainEvents) }` → agregado + event-store + outbox atómicos, `TransactionManager` framework-free (mantiene el ORM fuera de Application; el adapter lo documenta como «the deptrac-clean seam»). **Cautela de `deferred-work.md`**: la guarda de `ChangeUserStatus` corre FUERA del `transactional()` (solo envuelve save+publish); para II-6 el incremento/lock **es** la escritura, así que debe ir DENTRO del `transactional()`.

### Inteligencia de la historia previa (II-3, mergeada #467)

- El re-wrap `expose_security_errors: None` es la razón de la doble familia: SUSPENDED/DEACTIVATED extienden `CustomUserMessageAccountStatusException` para escapar; INVITED extiende `DisabledException` plana para *aprovechar* el re-wrap → 401 pre-identidad. **`LockedAccountException` va en el bando «exento» (403).**
- `UnauthenticatedAccessListener` (prio 40 > `ExceptionResponder` 16) reescribe cualquier `AccessDeniedException` en `/api` con token no-full → 401. **NO** reusar `AccessDeniedException` para el muro locked (ni en su cadena `previous`) — el `DomainException implements Forbidden` lo esquiva.
- Muros vivos sin tooling E2E → cubiertos por Behat API + unit PWA (no `login.spec.ts`).

### Persistencia (decidida, NO re-abrir)

Estado-orientado por **D7** (snapshot `failedAttempts`/`lockedUntil`, limpiado en éxito — la **historia** de intentos NO se retiene en el agregado). La observabilidad/historia se sirve por el **evento de dominio → outbox → audit trail** (NFR10), no event-sourceando el contador. Consistente con el default D2 (state-oriented; event-sourcing opt-in por agregado). El muro `locked` es un **timestamp gate ortogonal** a `IdentityStatus`, **no** un case nuevo del enum.

### Project Structure Notes

- Backend en `Iam/Identity` (Domain: entidad+excepción+evento; Infra: security exception+checker arm+listeners; Application: caso(s) de uso). Deptrac: nada nuevo que registrar (todo intra-`Iam/Identity`; sin seams cross-context; `Shared/ErrorContract` ya importable). Sin entradas de `.bounded-context-allowlist`.
- PWA en `context/backoffice/user` (outcome+repo) y `context/shared/error/...ui` (AccessWall). Sin binding Inversify nuevo (`LoginRepository`→`ApiLoginRepository` ya bindeado).
- Migración en `api/migrations/2026/` vía `db.diff`.

### References

- Épica: `_bmad-output/planning-artifacts/epics-identity-invitation-lifecycle.md` §«Story II-6» (líneas 706-743) y FR7 (123-128).
- ADR: `docs/adr/identity-invitation-lifecycle.md` D7 (lockout complementario al throttler), D-b (reset limpia el lock), D10/SI-12 (indistinguibilidad), SI-14 (error graduado).
- Contrato de error: `docs/api-error-contract.md` §«Login admission errors» (fila `account-suspended` como plantilla).
- Contexto de agentes IA: `docs/project-context.md` (stack/reglas). Reglas: `docs/rules/{security,architecture,database,testing}.md`.
- Registro de diferidos: `_bmad-output/implementation-artifacts/deferred-work.md` (cautelas de reloj y frontera transaccional).

## Decisiones abiertas — a ratificar al inicio de dev (con recomendación)

> Ninguna bloquea la creación de la historia; se resuelven al arrancar `bmad-dev-story`. Las de mayor impacto (A, B) se recomiendan con argumento; las flag el dev.

- **Decisión A — Política de lockout: umbral N + duración TTL + interacción con `login_throttling` (max_attempts: 5).** El throttle es **per-IP+email, efímero, primera línea**; un atacante distribuido (credential-stuffing desde muchas IPs sobre una cuenta) lo evade — ahí entra el lock **per-identidad, persistente**. Si N ≈ 5 (= al throttle), el throttle bloquearía antes de que el lock persistente aflore su 403. **RATIFICADA (Sergio, 2026-07-11): N=10, TTL=15 min** — segundo escalón per-identidad sobre el throttle per-IP(5); menos falsos bloqueos. Constantes de dominio nombradas.
- **Decisión B — Mecánica del contador (listener + caso de uso transaccional).** **Recomendación:** `LoginFailureEvent` listener → `RecordFailedLoginAttempt` transaccional (solo si el email resuelve a un `User`); `LoginSuccessEvent` listener hermano (prio > minting) → `ClearLoginLockout`. Alternativa descartada: plegar la limpieza en `SessionMintingSuccessListener` (acopla la limpieza al fail-close 503 del minting; SRP). Dev-shape; confirmar prioridad/orden.
- **Decisión C — Emitir el evento `AccountLocked` ahora (R1/NFR10) vs YAGNI sin reactor.** **Recomendación:** emitir ahora (mirror de la Decisión E de II-3: eventos de identidad se graban aunque el reactor de audit sea el único consumidor). Payload sin PID.
- **Decisión D — Alcance del refactor de `AccessWall` a dos acciones.** El componente hoy hardcodea una acción. **Recomendación:** pila de acciones **por variante** dirigida desde `COPY` (mínima, sin generalizar de más): `locked` = 2 acciones, suspended/deactivated = 1. Argumento: OCP para futuros muros (`invalid-link`/`session-expired` de II-4/II-7) sin re-tocar el render; coste bajo, toca el render compartido → nombrarlo en el PR (boy-scout).
- **Decisión E — i18n (UX-DR8 Spanish-first) vs. patrón inline actual.** El PWA **no tiene i18n**; el copy es inglés inline y las walls de II-3 ya lo son. **Recomendación:** seguir el patrón inline existente por consistencia y tratar i18n como iniciativa separada fuera de alcance — **no** introducir un mecanismo i18n unilateralmente en II-6. Surface a Sergio.
- **Decisión F — Limpieza por reset (D-b) NO es cableable aún.** No existe caso de uso de reset/`activate` con caller (Invitation/reset = II-4/II-5). `User::clearLockout()` queda listo como hook público; **el cableado D-b (reset limpia el lock) es de II-5**, fuera de alcance de II-6. Confirmar que II-6 solo expone el método, no lo cablea.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (orchestrador) + workers de implementación (backend/PWA) en paralelo, TDD.

### Debug Log References

- Gates verificados en fresco (worktree `…-v5wm`): `php.quality`=0 · `php.unit`=0 · `php.behat`=0 (266 escenarios) · `php.psalm.taint`=0 · `db.validate`=0 («mapping correct» + «schema in sync») · `pwa.quality`=0 · `pwa.test.unit`=0 (975). `make db.diff` devuelve «No changes detected» (exit no-cero por convención de doctrine cuando no hay drift = correcto).

### Completion Notes List

- **Lock = timestamp gate en `User`** (`failedAttempts`/`lockedUntil`), ortogonal a `IdentityStatus`; `MAX_FAILED_ATTEMPTS=10`, `LOCK_DURATION='PT15M'`. `recordFailedAttempt(now)`/`clearLockout()`/`isLockedAt(now)`; el dominio toma `DateTimeImmutable` (el `Clock` vive en Application → pureza). `AccountLocked` (Domain, `Forbidden`, `type:'account-locked'`, mapa de marcadores intacto en 9) + `LockedAccountException` (Infra, `CustomUserMessageAccountStatusException`) + arm en `checkPostAuth` tras SUSPENDED/DEACTIVATED. Evento `UserLocked` grabado en `event_store` (no ruteado a outbox: wire-on-consumer, sin reactor aún).
- **DESVIACIÓN (Task 5, load-bearing):** el listener de `LoginFailureEvent` NO es viable — `AuthenticatorManager::handleAuthenticationFailure` invoca `onAuthenticationFailure` (que *lanza* para llegar al pipeline RFC 9457) **antes** de despachar `LoginFailureEvent`, que por eso nunca se dispara en este firewall (verificado). El incremento del contador se plegó **dentro del `ProblemDetailsAuthenticationFailureHandler`** (lee el email del payload, delega en `LoginAttemptRegistrar::recordFailure` antes del mapeo; email desconocido/inválido = no-op → SI-12). La limpieza-en-éxito sí es un listener de `LoginSuccessEvent` (`ClearLockoutOnLoginSuccess`, prio -64 > minting -128).
- **HALLAZGO 1 — RESUELTO (Sergio ratificó, 2026-07-11):** `recordFailedAttempt` ahora **guarda a solo-`ACTIVE`** (`if (IdentityStatus::ACTIVE !== $this->status) return;`) — una identidad no-`ACTIVE` (INVITED sin password / SUSPENDED / DEACTIVATED) no acumula ni emite `UserLocked` espurio. Test `aNonActiveIdentityNeverAccruesALockout` (INVITED + SUSPENDED). Gates re-verdes (php.quality/unit/behat).
- **HALLAZGO 2 (limpieza menor):** dobles de test nuevos (`FixedClock`, `InlineTransactionManager` en `Iam/Identity/Application`) duplican patrones locales (Session tiene los suyos; existe un `Shared/Persistence/Double/ImmediateTransactionManager`). Sigue el precedente reciente de Session; candidato a consolidación, no bloqueante.
- **PWA:** `AccessWall` refactorizado a **pila de acciones por variante** (Decisión D, OCP boy-scout, nombrado): `locked` = «Recover access»→`FORGOT_PASSWORD` + «Sign in»→`LOGIN`; suspended/deactivated sin cambio visible. Sin i18n (Decisión E: patrón inline). Muro sin E2E vivo (cubierto unit PWA + Behat API).
- **Decisiones ratificadas:** A=N10/TTL15m; B=use-case transaccional (con la desviación del hook de fallo); C=evento emitido; D=pila de acciones; E=inline; F=`clearLockout()` expuesto, cableado D-b diferido a II-5.

### Change Log

- II-6 lockout `LockedUntil`: agregado `User` (2 columnas + comportamiento) · `AccountLocked`/`UserLocked`/`LockedAccountException` · arm en `UserChecker` + failure handler · `LoginAttemptRegistrar` + `ClearLockoutOnLoginSuccess` · migración `Version20260711171040` · `api-error-contract.md` +fila `account-locked` · Behat +6 escenarios · PWA muro `locked` + pila de acciones.

### File List

**Backend (api/) — nuevos:** `src/Iam/Identity/Domain/Event/UserLocked.php` · `src/Iam/Identity/Domain/Exception/AccountLocked.php` · `src/Iam/Identity/Domain/Exception/LockoutStoreUnavailable.php` (code-review ⑦) · `src/Iam/Identity/Infrastructure/Security/LockedAccountException.php` · `src/Iam/Identity/Infrastructure/Security/ClearLockoutOnLoginSuccess.php` · `src/Iam/Identity/Application/LoginAttemptRegistrar.php` · `migrations/2026/Version20260711171040.php` · tests: `Unit/Iam/Identity/Domain/Entity/UserLockoutTest.php`, `Unit/Iam/Identity/Domain/Event/UserLockedTest.php`, `Unit/Iam/Identity/Domain/Exception/{AccountLockedTest,LockoutStoreUnavailableTest}.php`, `Unit/Iam/Identity/Application/LoginAttemptRegistrarTest.php`, `Unit/Iam/Identity/Application/{FixedClock,InlineTransactionManager}.php`, `Unit/Iam/Identity/Infrastructure/Security/{BuildsFailureHandler,ClearLockoutOnLoginSuccessTest}.php`.
**Backend — modificados:** `src/Iam/Identity/Domain/Entity/User.php` · `src/Iam/Identity/Infrastructure/Security/{UserChecker,SecurityUser,ProblemDetailsAuthenticationFailureHandler}.php` · `src/Shared/Event/Domain/DomainEvent.php` · `features/backoffice/identity/login.feature` · `tests/DataFixtures/Fixtures/{User,Membership}.yaml` · tests `{UserCheckerTest,SecurityUserTest,ProblemDetailsAuthenticationFailureHandlerTest}.php` · `docs/api-error-contract.md` · `PRODUCTION_SECURITY_CHECKLIST.md`.
**Frontend (pwa/) — modificados:** `src/context/backoffice/user/domain/LoginOutcome.ts` · `src/context/backoffice/user/infrastructure/ApiLoginRepository.ts` · `src/context/shared/error/infrastructure/ui/AccessWall.tsx` · `src/app/(auth)/_components/LoginForm.tsx` · tests `{ApiLoginRepository,AccessWall,loginForm}.test.*`.
