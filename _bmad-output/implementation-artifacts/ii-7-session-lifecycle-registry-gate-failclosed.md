# Story II-7: Session Lifecycle + Session Registry + Session Admission Gate fail-closed

Status: ready-for-dev

<!-- Validación opcional. Ejecuta `bmad-create-story` validate para un chequeo de calidad antes de dev-story. -->

## ⚠️ Revisión de diseño (2026-07-10) — leer antes de dev

Revisión adversarial del spec contra el código real (4 verificadores) + consulta al arquitecto + ratificación de Sergio
(2026-07-10). El diseño nuclear es **sólido**; lo de abajo son las **decisiones ya resueltas** (contrato de dev — superan
cualquier fraseo previo del cuerpo) y **correcciones factuales** aplicadas inline (marcadas `⟦rev⟧`).

**Decisiones RESUELTAS por Sergio (2026-07-10):**

- **G1 · `organizationId` desde `Membership` (SI-15), vía read use-case publicado.** El listener de minting resuelve el
  `organizationId` desde el **`Membership` del usuario** (fuente autoritativa SI-15), NO desde una «org de instalación».
  **Vía un caso de uso de LECTURA nuevo y publicado en Organization** (p.ej. `Organization/Membership/Application/FindUserOrganizationId`)
  consumido con un **seam nuevo per-file `Identity→Organization`** (allowlist + `deptrac.yaml skip_violations`). **Prohibido**
  inyectar `MembershipRepository` cross-contexto (regla de aislamiento) y **sin** puerto especulativo en `Iam` (un solo
  consumidor = Regla de Tres; el idiom es «servicio publicado en el contexto dueño»). `Membership` ausente al acuñar →
  falla **fail-closed (503)**, nunca `organizationId` vacío. ⇒ II-7 mantiene DOS seams: `Identity→Session` (B) y
  `Identity→Organization` (I, hacia el read use-case).
- **G2 · Expiración = predicado temporal; `EXPIRED` fuera del modelo → D8 enmendado.** `SessionStatus ∈ {ACTIVE, REVOKED}`
  (solo el ciclo de vida por-actor). La validez temporal es un **predicado `expiresAt <= now`** que aplican **el gate Y la
  proyección «mis sesiones»** — **cero escritura en el read-path**, sin sweeper, **sin evento `SessionExpired`** (no ocurre
  nada en el dominio; una condición deja de cumplirse). `expires_at` **`NOT NULL`** (`start()` lo fija absoluto). Usar el
  `SystemClock` de dominio (UTC), nunca hora de cliente. **ADR D8 ya enmendado** (`docs/adr/identity-invitation-lifecycle.md`):
  `status: Active|Revoked` + `expiresAt`; `Expired`-persistido movido a *Discarded* (mezclaba dos ejes: ciclo-por-actor vs
  validez-temporal). **Reintroducir `EXPIRED`** solo si un día un sweeper materializa la transición de verdad.
- **G3 · `ip`/`device` plaintext operacional; `Session` NO es `AuditedEntity`.** La IP vive solo en `iam_session` (dato
  operacional de vida corta), **sin `#[PersonalData]`** y **sin crypto-shredding** (desproporcionado). `Session` **no
  implementa `AuditedEntity`** → no entra en el trail regulatorio de 5 años (su observabilidad la dan los eventos/outbox),
  así que la IP nunca queda en claro en el audit-log (verificado: el CDC `onFlush` es opt-in vía `AuditedEntity`).
  **`device` normalizado server-side** desde el `User-Agent` — **nunca** un string libre del cliente (PII + inyección
  almacenada al renderizar «mis sesiones»). **Corregir la frase «cero PII»** de T4: hay **PII operacional**, cubierta por
  purga-on-erasure (K) + retención; base de licitud = **interés legítimo** (seguridad de cuenta / gestión de sesiones), no
  consentimiento.

**Riesgos — disposición ratificada:**

- **O1 · GC del registro → follow-up #468 + retención documentada.** `iam_session` NO lo poda el GC nativo (limpia el
  store de ficheros). **Issue [#468](https://github.com/sergio-salcedo-dev/ERPify/issues/468)** para el comando de poda
  (`REVOKED` > 30d; `ACTIVE` con `expiresAt < now - 90d`; borrado inmediato tras erasure). No bloquea II-7, pero la
  **política se documenta ahora** con II-7 (no shippear IP sin política de retención) — ver T12.
- **O2 · Fallo en el acuñado = fail-closed estricto.** Si `StartSession` lanza, el listener hace **`$session->invalidate()`
  + 503** — «si no existe `Session`, no existe sesión». Symfony no revierte la cookie nativa solo → invalidar explícito.
- **O3 · Sentry flood.** El 503 del store-caído llega a Sentry por diseño; **dedup/fingerprint antes de prod**. Test unit:
  `DoctrineSessionRepository` convierte la `ConnectionException` de DBAL → marker `ServiceUnavailable` (si escapa cruda → 500).
- **`organizationId` congelado en la sesión** — deseado; registra el contexto de admisión, no se sincroniza al cambiar el `Membership`.

## Story

Como **subsistema de sesión de ERPify**,
quiero un **agregado `Session` con ciclo de vida**, un **registro server-side** y un **Session Admission Gate per-request fail-closed**,
para que **«autenticado» signifique «tiene una sesión viva y revocable»**: una sesión `Revoked`/`Expired` es **inerte en su siguiente request**, cada sesión es **enumerable y revocable** por su dueño, y el gate **nunca falla abierto** (si no puede decidir, la request no continúa).

## Contexto (leer antes de tocar código)

Esta es **II-7 (PR-7)** de la épica `identity-invitation-lifecycle` (orden safe-first
`II-0 → II-1·II-2·II-3·II-7 → II-6 → II-4 → II-5 → II-8`). **Exige solo II-0** (el esqueleto `Iam/Session` +
capas deptrac ya registradas) y **consume de facto II-3** (la admisión de tres momentos: la `Session` se acuña
**solo tras** `checkPostAuth`, SI-10). Es **prerequisito** de **II-4** (el accept acuña la primera `Session` sobre el
agregado en vez de una sesión nativa desechable) y **II-5** (reset **revoca todas** las sesiones). Es *infraestructura
del modelo, no una feature*: es la **cuarta máquina de estado** del subsistema (Identity · Invitation · Auth · **Session**).

Fuente de verdad del diseño (ya ratificado por Sergio — no re-abrir):
[`docs/adr/identity-invitation-lifecycle.md`](../../docs/adr/identity-invitation-lifecycle.md) **D8** (Session store =
Option-1 endurecida) · [`_bmad-output/planning-artifacts/arch-addendum-identity-invitation.md`](../planning-artifacts/arch-addendum-identity-invitation.md)
**SI-11** (gate fail-closed, TCB) + **NFR11** (sustituibilidad del backend) + **PR-7** ·
[`_bmad-output/planning-artifacts/epics-identity-invitation-lifecycle.md`](../planning-artifacts/epics-identity-invitation-lifecycle.md)
**Story II-7 (líneas ~424-429)**, FR8, NFR3/4/10/11, UX-DR11.

**Estado actual verificado (post II-3, ya en `main`):** la sesión es **nativa de Symfony y efímera**.
`framework.yaml` no fija `handler_id` (native file handler en prod, `mock_file` en test); **no existe** tabla de
sesión, handler PDO, ni clave de correlación (`iamSessionId`/`SessionId` → 0 hits). `json_login` **no tiene
`success_handler`** (solo `failure_handler`); el éxito acuña la cookie nativa y `LoginController` devuelve **204 sin
registro**. `Iam/Session/` es solo `.gitkeep`. **Todo eso lo crea II-7.**

**El invariante rector, en una línea:** `credenciales → identidad → admisión → sesión-registrada → gate-en-cada-request`.
II-3 garantizó los tres primeros pasos; II-7 añade el **registro** (acuñar la `Session`) y el **gate** (validarla en
cada request, fail-closed). D8 mantiene el **storage Symfony nativo** (Option-1: nativo + registro + revocación
**lógica**); el dominio **nunca** conoce el session id del framework — solo `SessionId(UUIDv7)`.

## Acceptance Criteria

AC como **invariantes verificables** (comportamiento / consume / establece / prueba), enganchados a D8, SI-11 y
NFR3/4/10/11 · UX-DR11, de modo que una refactorización futura no pueda romper una garantía sin que un test la detecte.

1. **(Modelo · D8/FR8)** `Iam/Session` es un **agregado libre de framework** (`extends AggregateRoot`) con
   `id` (UUIDv7), `userId`, `organizationId`, `createdAt`, `expiresAt` (**absoluto, NOT NULL**), `lastSeenAt`, `device`,
   `ip`, `status ∈ {ACTIVE, REVOKED}` (**G2:** sin `EXPIRED` — la caducidad es el predicado `expiresAt <= now`, no un
   estado); es la **fuente de verdad** del ciclo `ACTIVE → REVOKED` (única transición, **terminal**, guardada).
   Referencia a `Iam/Identity` por `userId` **sin FK física** cross-sub-módulo (aislamiento por id, patrón
   `membership.user_id`); `organizationId` por id (SI-15). Un test prueba que ninguna clase de `Session/Domain` importa
   Symfony/Doctrine-runtime (deptrac verde) y que una transición ilegal (`REVOKED→ACTIVE`) se rechaza.

2. **(SI-11 · cobertura del gate — RECTOR)** **Toda** request autenticada atraviesa el **Session Admission Gate**, que
   relee la correlación (`iamSessionId` del bag), carga el `Session` del registro y **fuerza logout salvo
   `status=ACTIVE`**. Un test/checklist **falla si una ruta autenticada bypassa el gate** (cobertura = invariante de
   seguridad, no feature — NFR4).

3. **(SI-11/D12 · fail-closed)** Con el store de sesiones **no disponible**, el gate **no falla abierto**: la request
   **no continúa** y responde el tipo **operacional 503-family** (marker nuevo, **NO** `ClientError`), **nunca** un tipo
   de identidad. Las **dos salidas del gate son distinguibles:** `REVOKED` o **expirada por tiempo** (`expiresAt <= now`)
   → **401** «re-login»; *no-puede-decidir* → **503**. Un test simula store caído y asserta 503 (no 401, no 200); otro
   asserta que la revocada da 401 y otro que la expirada-por-tiempo da 401.

4. **(D8 · revocación lógica efectiva)** Una `Session` marcada `REVOKED`, **en su siguiente request**, es **inerte** — el
   gate la rechaza **antes** del controlador; **no** requiere borrado físico del payload (lo recoge el GC nativo).

5. **(NFR11 · sustituibilidad — seam)** El dominio y los casos de uso (acuñar / revocar / mis-sesiones) **nunca**
   referencian el session id del framework: el **único** acoplamiento a Infra es `iamSessionId` en el bag, tras un
   puerto. Cambiar el backend (native → `PdoSessionHandler`/store compartido) **no** toca dominio ni aplicación, solo el
   adapter. Un test verifica que ninguna clase de `Session/{Domain,Application}` conoce la sesión Symfony.

6. **(UX-DR11/J6 · granularidad)** Existen las revocaciones **una / todas-menos-actual / todas**; «cerrar las demás»
   **nunca** autoexpulsa la sesión actual (excluye el `iamSessionId` en curso).

7. **(D8 · camino nativo complementario)** El cambio de credencial de-autentica vía el mecanismo **nativo** del firewall
   (`⟦rev⟧` para ser exactos: `UserProvider::refreshUser` recarga el user, y `ContextListener::hasUserChanged` — código
   **framework**, no de la app — compara el hash porque `SecurityUser` implementa `PasswordAuthenticatedUserInterface` y NO
   `EquatableInterface`; al cambiar, `ContextListener` despacha `TokenDeauthenticatedEvent`). El registro `Session` se
   actualiza **en paso** (reactor sobre ese evento, ver J) para que «mis sesiones»/audit no diverjan del estado real del
   firewall (complementario, no redundante).

8. **(NFR10 · eventos observables)** Cada transición emite su evento de dominio (`SessionStarted` al minting,
   `SessionRevoked` unitaria, `AllSessionsRevoked` bulk) por el `EventBus`/outbox (transporte Doctrine), commit
   **atómico** con la escritura (`TransactionManager`). Como el minting es **post-admisión**, no reabre el canal de
   re-enumeración pre-identidad (SI-12/NFR1 se cumple estructuralmente). **Reactor/consumidor NO** en II-7 (R2
   wire-on-consumer; el `SecurityEmail` async es II-8).

9. **(UX-DR11 · UI mínima)** «Mis sesiones» lista las sesiones activas del usuario con el **«dispositivo actual»
   distinguible**; una sesión expirada/revocada devuelve **401** del gate → el PWA redirige a **B1 conservando `?next=`**
   (vía `safeInternalPath`, sin open-redirect), sin pantalla propia (comportamiento diario). *(La variante `AccessWall
   session-expired` pulida y las 6 pantallas de acceso son II-4 — ver «Decisión F».)*

**Invariante rector de no-regresión (transversal):** `make app.test` + `make app.quality` verdes; el login de un
usuario `ACTIVE` con password correcta **sigue** devolviendo **204 + cookie** (el minting no acopla ni rompe el 204, ni
filtra roles en el body); todo fallo pre-identidad sigue colapsando a **401 neutro**; el `user_checker`/RBAC de II-3
intacto; la postura de cookie (`httpOnly` / `SameSite=lax` / `secure:auto`) **no** se relaja.

## Tasks / Subtasks

- [ ] **T1 — `SessionStatus` enum (AC1)**
  - [ ] `api/src/Iam/Session/Domain/Enum/SessionStatus.php` — backed enum `string` **`ACTIVE/REVOKED`** (G2: `EXPIRED`
        fuera del modelo — la caducidad es un predicado temporal `expiresAt <= now`, no un estado persistido), vocabulario
        puro (sin ranking). Espeja el **patrón** de `IdentityStatus.php` (enum puro), no su lista de valores.
- [ ] **T2 — Agregado `Session` + eventos (AC1, AC8)**
  - [ ] `api/src/Iam/Session/Domain/Entity/Session.php` — `final class Session extends AggregateRoot`, ctor privado que
        canaliza invariantes + factoría estática `start(userId, organizationId, device, ip, expiresAt)` (nace `ACTIVE`,
        fija `expiresAt` **absoluto NOT NULL**, `record(new SessionStarted(...))`); **única transición** `revoke()`
        (`ACTIVE→REVOKED`, `record(SessionRevoked)`) con `guardTransitionTo()` que rechaza ilegales
        (`InvalidSessionTransition`). **G2: NO hay `expire()` ni valor `EXPIRED`** — la caducidad la resuelve el predicado
        `isExpired(now)` / la query del gate, no una transición persistida; **no** hay evento `SessionExpired`. **`lastSeenAt`
        NO es estado del agregado** (Decisión E): el agregado **no** lo mapea ni tiene `touch()` (telemetría, se escribe por
        UPDATE DBAL dirigido y se lee en la proyección). Espeja `User.php` (ctor-funnel + guardas).
  - [ ] Eventos `Domain/Event/{SessionStarted,SessionRevoked,AllSessionsRevoked}.php` — `extends DomainEvent`,
        `aggregateType()='Iam.Session'`, `toPrimitives()` **sin PII** (ids + `occurredAt`; no `ip`/`device` en el payload
        salvo necesidad de consumidor). Espeja `Iam/Identity/Domain/Event/UserSuspended.php`.
- [ ] **T3 — Puerto `SessionRepository` + adapter Doctrine (AC1)**
  - [ ] `api/src/Iam/Session/Domain/Repository/SessionRepository.php` — `save`, `findActiveById(SessionId): ?Session`
        (**G2:** «active» = `status=ACTIVE` **AND** `expires_at > now` — el predicado temporal va en la query, no una
        transición), `findByUserId(userId): list<Session>` (para «mis sesiones»; filtra igual el predicado), y las
        revocaciones (una / todas-menos-actual / todas — ver T8). Interfaz en Domain.
  - [ ] `api/src/Iam/Session/Infrastructure/Persistence/Doctrine/DoctrineSessionRepository.php` — **por composición**
        (`#[AsAlias(SessionRepository::class)]`, `EntityManagerInterface`, **no** `ServiceEntityRepository`). Espeja
        `DoctrineUserRepository`/`DoctrineMembershipRepository`. **O3:** convierte la `ConnectionException` DBAL en el
        marker de dominio `SessionStoreUnavailable` (`ServiceUnavailable`→503); si escapa cruda mapea a 500 (fallo abierto
        en semántica) — test unit que lo prueba.
- [ ] **T4 — Migración: tabla del registro (AC1)**
  - [ ] `make db.diff` → `api/migrations/2026/` creando `iam_session` (`id` GUID PK, `user_id` GUID, `organization_id`
        GUID, `status` `ACTIVE|REVOKED`, `created_at`, **`updated_at`** (lo fuerza `AggregateRoot`/`Timestamped`, ver
        `⟦rev⟧` Decisión G), `last_seen_at`, **`expires_at` NOT NULL** (G2), `revoked_at` nullable, `device`, `ip`).
        `user_id` **sin FK física** cross-sub-módulo (patrón `membership.user_id`; ver «Decisión G»). **`CREATE TABLE IF NOT
        EXISTS` / `DROP TABLE IF EXISTS`** (precedente `Version20260709230444` es un ALTER — reusa el idiom, no la forma),
        transaccional/all-or-nothing, `down()` reversible. Índice en `(user_id, status)` para «mis sesiones». **G3: `ip` y
        `device` son PII operacional** (NO «cero PII»): plaintext, **`Session` NO es `AuditedEntity`** ni lleva
        `#[PersonalData]` → la IP no entra en el audit-log de 5 años; `device` se **normaliza server-side** del `User-Agent`
        (nunca string libre del cliente). El mapping `Iam` de `doctrine.yaml` ya cubre `Iam\Session` (nada que registrar).
- [ ] **T5 — Acuñar la `Session` en el login (AC2 parcial, AC8) — «Decisión B» + G1 + O2**
  - [ ] **Read use-case en Organization (G1):** `api/src/Organization/Membership/Application/FindUserOrganizationId.php` —
        fino, publicado, `(userId): string` (usa `MembershipRepository::findByUserId`; `null` → excepción de dominio).
        Añadir el seam `Identity→Organization` per-file a `.bounded-context-allowlist` + `deptrac.yaml skip_violations`.
  - [ ] Caso de uso `api/src/Iam/Session/Application/StartSession.php` (`(userId, organizationId, device, ip, expiresAt)`:
        mint `SessionId` v7 + `Session::start(...)` + `transactionManager->transactional(save + eventBus->publish(pull...))`).
        Espeja `ChangeUserStatus`/`CreateUser`. `expiresAt` = `SystemClock::now()` + TTL (dominio, UTC, nunca cliente).
  - [ ] Listener sobre `Symfony\...\Security\Http\Event\LoginSuccessEvent` (**recomendado**, dispara **post-admisión**):
        toma `userId` **primitivo** del token, resuelve `organizationId` vía `FindUserOrganizationId` (G1), invoca
        `StartSession`, escribe la correlación en el bag (`$session->set('iamSessionId', $id)`), y **`LoginController` sigue
        devolviendo el 204 intacto**. **O2 — fail-closed estricto:** si `StartSession` (o la resolución de org) lanza,
        **`$session->invalidate()` + 503** (Symfony no revierte la cookie nativa solo) — «si no existe `Session`, no existe
        sesión». El `userId` primitivo evita importar `SecurityUser` en `Session` (ver «Project Structure»). Verificar que
        `iamSessionId` **sobrevive** a la regeneración de id (`migrate` preserva atributos; listener a prioridad negativa,
        tras `SessionStrategyListener@0`) — anti-fijación.
- [ ] **T6 — Session Admission Gate fail-closed (AC2, AC3, AC4) — «Decisión A»**
  - [ ] `api/src/Iam/Session/Infrastructure/Security/SessionAdmissionGate.php` — listener `kernel.request` con
        **`const PRIORITY` < 8** (post-`ContextListener` del firewall, pre-controlador), acotado a `^/api` con token
        autenticado. Lee `iamSessionId` del bag → `SessionRepository::findActiveById` (que ya aplica `status=ACTIVE AND
        expires_at > now`) → si null **lanza fail-closed**: `REVOKED`/expirada-por-tiempo/ausente → el marker de dominio
        `SessionNoLongerActive implements Unauthenticated` (Decisión A; **NO** un `AuthenticationException` de Symfony — ver
        A por el motivo Sentry) → **401 re-login** (NO `AccessDeniedException`: con token full-fledged daría 403 vía
        `UnauthenticatedAccessListener`); store-no-disponible → el **marker operacional 503** (T7). La excepción **no**
        lleva `AccessDeniedException` en su `previous` (el walk cycle-safe lo re-descubriría). Patrón de listener:
        `LoginOriginListener` (prio 9) + `UnauthenticatedAccessListener` (`const PRIORITY=40`, kernel.exception).
- [ ] **T7 — Marker operacional 503-family (AC3) — «Decisión C»**
  - [ ] `api/src/Shared/ErrorContract/Domain/Exception/<Name>.php` — marker interface que mapea a **503** y **NO extiende
        `ClientError`** (el `MarkerStatusMapContractTest::testMarkerIsClientErrorIffStatusIs4xx` fuerza la decisión
        consciente: un 5xx llega a Sentry). Añadir el mapeo en `ProblemDetailsFactory::MARKER_STATUS_MAP` +
        `MARKER_DEFAULT_TYPE_MAP`. Actualizar [`docs/api-error-contract.md`](../../docs/api-error-contract.md) (NFR26,
        `type` neutro tipo `service-unavailable`) → `make php.lint.error-contract` verde.
- [ ] **T8 — Revocación (AC6) + coherencia del camino nativo (AC7)**
  - [ ] Casos de uso `Iam/Session/Application/{RevokeSession, RevokeOtherSessions, RevokeAllSessions}.php` (o uno
        parametrizado por alcance) — una / todas-menos-actual (excluye el `iamSessionId` en curso) / todas. Cada uno
        `transactional(save/updateMany + publish)`; `RevokeAllSessions` emite `AllSessionsRevoked` (lo consumirá el reset
        de II-5). **Sin superficie HTTP de gestión de sesiones de terceros** (backoffice = slice diferido) — se ejercitan
        a nivel aplicación (tests) + la ruta «cerrar sesión / mis sesiones» del propio usuario (T9).
  - [ ] **Camino nativo (AC7) — reactor, NO en `UserProvider` (Decisión J):** reactor sobre el `TokenDeauthenticatedEvent`
        del firewall en `Iam/Session/Infrastructure` que marca la sesión afectada del registro en paso con la de-auth
        nativa (cambio de credencial), leyendo `userId`/`iamSessionId` primitivos — sin acoplar `UserProvider` (Identity)
        al `SessionRepository` (Session). Verificar que **no** se rompe la de-auth nativa existente.
  - [ ] **Erasure/GDPR (Decisión K):** reactor sobre el borrado/erasure de `User` que **purga** sus filas `iam_session`
        (sin FK física no cascadea → filas huérfanas con `userId`; familia del gap #376). Test que lo prueba.
- [ ] **T9 — `GET /api/me` + de-mock del cliente + reflejo gate-401 + «mis sesiones» (AC9 · Decisiones F, Fork-3)**
  - [ ] **Backend `GET /api/me`** (nuevo, mínimo): devuelve identidad + **roles REALES** del `SecurityUser` (id, email,
        roles — **lee lo que ya existe, NO fabricar**). Gateado por el `SessionAdmissionGate` como cualquier `^/api` (sin
        sesión viva → 401). Controlador + Resource DTO por vista; sin filtrar campos de auditoría.
  - [ ] **Matar `SEED_SESSION`** (`AuthProvider.tsx` L22-33; el fallback se usa en L118/121/141) y la fabricación de roles
        tras el 204 (`LoginForm.tsx` L54-64) — **`⟦rev⟧` son TRES puntos, no dos**: falta **`DevSessionSwitcher.tsx`**, que
        lee `session.roles[0]`/permissions y asume una sesión sembrada; la firma `login(user: Identity, …)` del
        `AuthContextValue` es mock-shaped y cambia con la hidratación real. **Innegociable**: con el gate real, un ADMIN
        auto-sembrado sin cookie explota al primer `/api`. Hidratar
        el estado auth desde la **existencia real** de sesión vía `/api/me` (cold-load); `/api/me` KO → `unauthenticated →
        B1` (no spinner infinito). Mantener la forma de `Session.ts` (consumidores sin cambio); **no** guardar
        identidad/roles en `localStorage` como fuente de verdad.
  - [ ] **Reflejo 401 centralizado** en el `FetchHttpClient` con **single-flight** (el 1er 401 del gate limpia la sesión
        de cliente + redirige a **B1 `/login?next=…&reason=session-expired`** vía `safeInternalPath`; los demás no-op →
        sin tormenta de 401). B1 muestra «tu sesión ha expirado» como `<p role="status" aria-live>`, **no** un 2º `<h1>`.
        `SignInRequiredScreen` queda como *fallback* de 401 en frío/SSR, **no** el camino diario (reconcilia AC9 vs T9).
  - [ ] **«Mis sesiones» mínima** en **`context/shared/access`** (autoservicio de seguridad del usuario, **no** backoffice):
        lista de sesiones `ACTIVE` + «This device» **textual** (no solo color) + «cerrar las demás» (la fila actual **sin**
        «Close» genérico; separar «cerrar las demás» de «cerrar esta»). Reusa primitivas `@/components/erpify`, **sin**
        crear ni promover ninguna (Regla de Tres). Copy en inglés (registro de las pantallas enviadas), traducible; tras
        éxito, señal calmada (no toast). `make pwa.quality` + `make pwa.test` verdes.
- [ ] **T10 — Tests (todos los AC)** — ver «Testing».
- [ ] **T11 — Gates + verificación fresca** — `make php.behat.install` (worktree fresco; si el recipe borró el
      condicional FriendsOfBehat de `bundles.php`, restaurarlo, #429) → `make php.stan` (por fichero; si exit 139 →
      `PHP_SERVICE=messenger_worker`) → `make php.test` → `make php.quality` EXIT 0 → `make php.lint.error-contract` →
      `make php.psalm.taint` → `make pwa.quality` → `make pwa.test`. Verificar **fresco** sobre el path del worktree,
      confiar en el exit code recién impreso.
- [ ] **T12 — Docs + follow-ups de las decisiones de revisión (G2/G3/O1)**
  - [ ] **ADR D8 (G2): YA enmendado** en esta rama (`docs/adr/identity-invitation-lifecycle.md`: `status: Active|Revoked` +
        `expiresAt`; `Expired`-persistido a *Discarded*) — verificar que sigue coherente al cerrar la story.
  - [ ] **Retención `iam_session` (O1/G3) — AL IMPLEMENTAR II-7:** como II-7 ya shippea el almacenamiento de `ip` (PII
        operacional), **documentar en `PRODUCTION_SECURITY_CHECKLIST.md`** la política de retención + base de licitud
        (interés legítimo; `REVOKED` > 30d, `ACTIVE` con `expiresAt < now - 90d`, borrado inmediato tras erasure) y
        **enlazar #468 en la PR** (`Relates to #468`). El **comando de poda** en sí va en
        [#468](https://github.com/sergio-salcedo-dev/ERPify/issues/468) (follow-up fuera de alcance de II-7).
  - [ ] **Error contract (T7/O3):** al añadir el marker `ServiceUnavailable`, actualizar `docs/api-error-contract.md`
        (NFR26) → `make php.lint.error-contract` verde.

## Dev Notes

### Ficheros a tocar (estado actual verificado en el worktree)

**API — `Iam/Session` (todo NUEVO; hoy solo `.gitkeep`):** `Domain/{Entity/Session, Enum/SessionStatus,
Repository/SessionRepository, Event/*, Exception/*}`, `Application/{StartSession, Revoke*}`,
`Infrastructure/{Persistence/Doctrine/DoctrineSessionRepository, Security/{SessionAdmissionGate,
SessionMintingSuccessListener}}`.

**API — `Iam/Identity` + config (EXISTENTE que II-7 toca/roza):**

| Fichero | Estado hoy | Qué cambia II-7 |
|---|---|---|
| `config/packages/security.yaml` | firewall `main` `lazy`, `json_login` (solo `failure_handler`), `user_checker: UserChecker` (II-3), `access_control` default-deny `^/api`, `login_throttling` | registrar el gate (servicio/listener) + el minting listener; **preservar** la cadena `access_control` first-match, el `user_checker` de II-3, `login_throttling` |
| `config/packages/framework.yaml` | `session` `cookie_httponly`/`samesite:lax`/`secure:auto`, storage nativo, sin `handler_id` | **preservar** la postura de cookie; test usa `mock_file`. **No** pinnar `PdoSessionHandler` (D8: nativo + registro; el handler compartido es forward-path/ADR nuevo) |
| `src/Iam/Identity/Infrastructure/Http/LoginController.php` | `204` sin body | **intacto** si el minting va en `LoginSuccessEvent` listener (recomendado) |
| `src/Iam/Identity/Infrastructure/Security/UserChecker.php` | admisión II-3 (`checkPostAuth` sin sesión para no-ACTIVE) | **no se toca** — es el punto **tras** el cual se acuña la `Session` |
| `src/Iam/Identity/Infrastructure/Security/UnauthenticatedAccessListener.php` | kernel.exception `PRIORITY=40`, `AccessDeniedException`→401 para anónimos en `^/api`, walk cycle-safe | el gate **coexiste**: una sesión inválida = token full-fledged sin sesión → lanzar `AuthenticationException` (401), **no** `AccessDeniedException` (daría 403). Preservar la distinción y el orden (40 > 32 > 16) |
| `src/Iam/Identity/Infrastructure/Security/{SecurityUser,UserProvider}.php` | `SecurityUser::id():?string`; `UserProvider::refreshUser`/`hasUserChanged` de-auth nativa | el minting/gate leen `userId` (primitivo del token); AC7 actualiza el registro en `refreshUser` en paso |

**API — error contract / registro:** `Shared/ErrorContract/…` (marker 503, T7); `doctrine.yaml` **nada** (`Iam` cubre
`Iam\Session`); `deptrac.yaml` capas `Iam.Session.*` **ya declaradas**; `.bounded-context-allowlist` solo si aparece un
import Session→Identity (ver «Project Structure»); `messenger.yaml` rutear `Iam\Session\...` a `async` solo si hay
consumidor (R2, no ahora).

**PWA — «mis sesiones» + expiración:** `context/shared/access/{domain/Session.ts, application/useSession.ts,
infrastructure/ui/{AuthProvider,RequireAuth}.tsx}` (hoy mock); `app/(auth)/_components/LoginForm.tsx` (mockea identidad
tras 204); `app/(errors)/unauthenticated/page.tsx` (`⟦rev⟧` solo **re-exporta** `SignInRequiredScreen`, cuyo componente
vive en `context/shared/error/infrastructure/ui/`; **NO** es el destino del guard: `RequireAuth` redirige a
**`/login?next=`** (`Routes.LOGIN`), no a `/unauthenticated` — el screen es el *fallback* del boundary `unauthorized()`
SSR, coherente con la Decisión F); `context/backoffice/user/…` (patrón para «mis sesiones» / who-am-i / logout).
`⟦rev⟧` **Ojo `FetchHttpClient`**: hoy no tiene manejo de 401; el punto único para el single-flight es el método privado
`request()` (chokepoint de todos los verbos). Y `context/shared/access` **no tiene infraestructura HTTP** hoy (es estado
de cliente puro) → el cliente de `GET /api/me` y el repo de «mis sesiones» son infra net-new (reusar el puerto
`HttpClient` + añadir entradas a `ApiEndpoints.ts`).

### El crux: dónde se acuña la `Session` y dónde vive el gate (los dos puntos que más importan)

**Hoy:** `json_login` (kernel.request prio 8) autentica → firewall acuña **cookie nativa** → sin `success_handler` cae a
`LoginController`→204. Requests siguientes: `ContextListener` (prio 8) restaura el token; `access_control` `^/api`
admite; anónimo → `AccessDeniedException` → `UnauthenticatedAccessListener` (kernel.exception 40) → 401.

**Minting (T5):** el punto correcto es **tras** `checkPostAuth` (identidad admitida) — un listener sobre
`LoginSuccessEvent` que crea el agregado y escribe `iamSessionId` en el bag, dejando el 204 de `LoginController`
intacto. La regeneración de id la da el `migrate` nativo → verificar que el bag sobrevive (anti-fijación).

**Gate (T6):** listener `kernel.request` con `PRIORITY` **< 8** (post-token, pre-controlador), `^/api` autenticado, que
relee `iamSessionId` y consulta el registro. **La decisión de diseño clave** es la salida del gate: para que una sesión
`REVOKED` o **expirada por tiempo** devuelva **401** (re-login) y no 403, el gate **debe lanzar el marker de dominio
`Unauthenticated`** (Decisión A; NO un `AuthenticationException` de Symfony), **no** `AccessDeniedException` — porque con token full-fledged el
`UnauthenticatedAccessListener` la dejaría en 403. El «no-puede-decidir» (store caído) es un camino **distinto** → el
marker **503** de T7. Confundir ambas salidas **rompe D12/SI-14** (un 5xx operacional ≠ un 4xx de identidad).

### El crux: fail-closed y el marker 503 (por qué no reusar nada existente)

El gate es TCB. `expose_security_errors: None` (default) + el reenvoltorio de `AuthenticatorManager` y el
`UnauthenticatedAccessListener`→401 (documentados por II-3) rigen el mapeo de **cualquier** salida de auth — el gate los
cruza. El «no-puede-decidir» necesita un **marker propio 503** en `Shared/ErrorContract` que **no** extienda
`ClientError` (el contract-test `testMarkerIsClientErrorIffStatusIs4xx` obliga a la decisión consciente: un 5xx debe
alertar en Sentry, no silenciarse como error de cliente). Reusar el bridge de identidad o un 4xx **fallaría abierto en
semántica** (parecería culpa del cliente y no dispararía la alarma operacional). Probar el camino de store no
disponible **explícitamente** (mock del repo lanzando) → assert 503.

### El seam NFR11 (sustituibilidad del backend de sesiones)

El **único** acoplamiento del dominio/aplicación a la sesión Symfony es la correlación `iamSessionId` en el bag. Detrás
de un **puerto** (p.ej. `CurrentSessionReference` / `SessionCorrelationStore` en `Application` o `Domain`), leer/escribir
esa correlación es intercambiable: el adapter nativo es el único hoy; su reemplazo por `PdoSessionHandler`/store
compartido (forward-path multi-node de D8) **no** toca dominio ni casos de uso. Test AC5: ninguna clase de
`Session/{Domain,Application}` importa `SessionInterface`/`RequestStack`/la cookie — solo el adapter Infra. Esto es lo
que hace NFR11 barato **sin** decidir la topología multi-node (eso es un ADR nuevo, fuera de alcance).

### Persistencia (ya decidida por ADR D8, no re-abrir)

`Session` es **state-oriented** (agregado como fuente de verdad + storage Symfony **nativo** + registro + revocación
**lógica**). D8 **descartó** explícitamente: (a) el `SessionHandler` unificado sobre la tabla (SRP), (b) `PdoSessionHandler`/
Redis ahora (YAGNI), (c) el borrado físico como mecanismo de revocación (la lógica basta porque el gate es la frontera:
una sesión revocada es inerte en su siguiente request). La *auditabilidad* de las transiciones (NFR10) es **emisión de
eventos**, no event-sourcing. El forward-path multi-node (store compartido) es un **ADR nuevo**, no una deriva automática.

### Testing (patrones del repo — II-3 es el precedente fresco)

- **Unit domain** (`api/tests/Unit/Iam/Session/…`): `SessionStatus`, las transiciones de `Session` (legal + ilegal), el
  minting, la revocación (una / todas-menos-actual / todas), los eventos. `@internal` + **`#[CoversClass]` estricto por
  clase** — SonarCloud `new_coverage ≥ 80%` es un gate real (II-1 falló a 78.8%) y se computa del clover de **PHPUnit,
  NO Behat**; el agregado, el repo (fake in-memory), el gate y los eventos **necesitan unit PHPUnit** por cada clase
  nueva; verifica clover local (`make php.unit.coverage`). El gate es unit-testeable con un `Session` fake por estado.
- **Behat** (`api/features/…`): el flujo end-to-end sobre kernel real — (a) login `ACTIVE`→204+cookie **y existe fila de
  registro `Session` ACTIVE**; (b) request autenticada con sesión `ACTIVE` → 200; (c) tras revocar la sesión, la
  **siguiente** request → **401** (inerte antes del controlador, AC4); (d) store no disponible → **503** (fail-closed,
  AC3, distinto del 401); (e) revocar «las demás» **no** expulsa la actual (AC6). Presupuesto de queries: +2 (BEGIN/
  COMMIT) por escritura envuelta.
- **NFR11 (AC5):** test estructural — ninguna clase de `Session/{Domain,Application}` referencia la sesión Symfony.
- **PWA** (`vitest` + Playwright): hidratación desde sesión real; `RequireAuth` redirige a B1 con `?next=` ante 401 del
  gate; «mis sesiones» lista + revoca. E2E golpea la stack viva (`https://localhost`); worktree → base URL al puerto
  efímero (`docker compose port php 443`) + `PLAYWRIGHT_HOST_PLATFORM_OVERRIDE=ubuntu24.04-x64`.
- **Presupuestos PHPMD** (II-1/II-2/II-3): `TooManyPublicMethods` ≤ 10 (los `DataProvider` estáticos cuentan → consolida)
  y `CouplingBetweenObjects` ≤ 13 (aplica a clases de test → stubs magros / helpers a traits). Rector impone
  `assertNotInstanceOf`/`assertSame`/`serialize()` — deja que gane, no lo suprimas.

### Gotchas heredados (verificados en ii-0/1/2/3)

- `make php.behat.install` en worktree fresco antes de gates; si el recipe security-bundle borró el condicional
  FriendsOfBehat de `bundles.php`, restaurarlo (#429). · `php.stan` exit 139 → `PHP_SERVICE=messenger_worker`. ·
  **Deptrac seam-sync:** cualquier import cross-sub-módulo va **per-file** en `.bounded-context-allowlist` **y**
  `deptrac.yaml skip_violations` (`DeptracSeamSyncGateTest` prohíbe `* =>`/whole-file). · Error contract: marker/mapping
  nuevo → actualizar `api-error-contract.md` (NFR26) + `php.lint.error-contract`; nunca `JsonResponse` manual de error. ·
  `NumberOfChildren` de `DomainException` suprimido por diseño → un `extends DomainException` nuevo está bien. · Barrer
  del diff final los IDs de story/NFR de **comentarios en `api/src`+tests** (permitidos en este spec, prohibidos en
  `main`).

### Decisiones resueltas (consulta arquitecto Winston + dev Amelia + UX Sally + tie-break ChatGPT · ratificadas por Sergio 2026-07-10)

Consulta 3-lentes + contraste externo, ratificada por Sergio. Ya **no** son «a confirmar»: son el contrato de dev.
Estas resoluciones **superan** cualquier fraseo previo de las tareas/cruxes (p.ej. donde el crux del gate dice
«`AuthenticationException`», léase el marker de dominio `Unauthenticated` — ver A).

- **A — sitio/salida del gate: RESUELTA.** Listener dedicado `SessionAdmissionGate` en `Iam/Session/Infrastructure/Security`,
  `#[AsEventListener(KernelEvents::REQUEST, priority: 7)]` (post-`ContextListener`, token full-fledged), guardas
  `isMainRequest`→matcher `^/api`→`isFullFledged`. `⟦rev⟧` **Las tres guardas son load-bearing**, verificado: el firewall
  entero corre en priority **8** (con el `AccessListener` interno en `-255`, que ya rechaza anónimos en rutas protegidas
  ANTES de que el gate en 7 corra → el gate solo ve requests admitidas por `access_control`), pero el gate en 7 **también
  dispara** en las rutas `PUBLIC_ACCESS` (login/health/`/api/.../dev`) con token anónimo/null Y en **todo** request no-`/api`
  (proxy PWA, `/.well-known/mercure`) — sin `^/api`+`isFullFledged` daría 401 falsos ahí. Nada se interpone entre 8 y 7.
  Lee `iamSessionId` (puerto D) → `findActiveById`. **Dos throws de
  dominio:** `SessionNoLongerActive implements Unauthenticated` (falta/`!ACTIVE`) → **401**; `SessionStoreUnavailable
  implements ServiceUnavailable` (repo lanza) → **503**. **Nunca `AccessDeniedException`** (403 con token full-fledged) y
  **nunca un `AuthenticationException` de Symfony** para el 401: `Unauthenticated extends ClientError` → **suprimido en
  Sentry** (`SentryEventFilter`); un `AuthenticationException` NO es `ClientError` → **inundaría Sentry** con cada request
  de sesión revocada. El 401 lo emite el listener `ExceptionResponder@16` (`⟦rev⟧` que **delega** el mapeo
  marker→status a `ProblemDetailsFactory::fromThrowable` / `buildDomainExceptionResponse` — el `@16` no contiene la rama de
  estado; el `MARKER_STATUS_MAP` y el `type()` explícito viven en el factory), `UnauthenticatedAccessListener@40` no lo toca
  (no hay `AccessDeniedException` en la cadena; **tampoco en `previous`** — el walk lo redescubriría).
- **B — punto de acuñado: RESUELTA.** Listener sobre **`LoginSuccessEvent`** en **`Iam/Identity/Infrastructure/Security`**
  (prioridad baja, tras el `SessionStrategyListener`/`migrate` — verificado que el bag sobrevive a la regeneración de id),
  lee `userId` primitivo de `SecurityUser::id()`, llama a `StartSession` con strings, escribe `iamSessionId` por el puerto
  D. `LoginController` sigue devolviendo 204. **NO es «cero seam»** (la story lo dijo, era falso): la llamada
  Identity/Infra→`Session/Application/StartSession` es una **arista cross-sub-módulo Identity→Session** → entrada
  **per-file** en `.bounded-context-allowlist` + `deptrac.yaml skip_violations`. Si el mint lanza (DB caída) rompe el
  login con el **mismo** marker 503 (fail-closed coherente con el gate).
- **C — marker operacional 503: RESUELTA.** Marker `ServiceUnavailable` en **`Shared/ErrorContract/Domain/Exception/`**
  (obligado: el mapa vive en Shared, que **no puede importar `Iam`** — ciclo/DIP), `type: service-unavailable`, **NO
  extiende `ClientError`**. El **401 del gate reusa el marker `Unauthenticated` existente** con `type()` override a
  `session-expired` (no inventa marker 401 nuevo; la factory: `type()` explícito gana al default). **El
  `MarkerStatusMapContractTest` exige TRES ediciones**, no dos: `CANONICAL_MARKERS` (+1), `assertCount(8→9)`, y la rama
  nueva en el `match` exhaustivo de `exceptionImplementingMarker()`. Actualizar `api-error-contract.md` (NFR26).
- **D — seam NFR11: RESUELTA.** Puerto `CurrentSessionReference { get(): ?SessionId; set(SessionId): void }` en
  **`Application`** (gemelo de `TransactionManager`; el bag no tiene vocabulario de dominio). **Load-bearing hoy** (sin él,
  `StartSession`/gate importarían `SessionInterface`/`RequestStack` → violación deptrac), no YAGNI. Concentra el literal
  `'iamSessionId'` en el único adapter (DRY + test estructural AC5). Rutar también la lectura del gate por este puerto.
- **E — `lastSeenAt`: RESUELTA — FUERA del agregado.** `lastSeenAt` **NO** se mapea como propiedad mutable del agregado
  `Session` (es telemetría de la faceta registro/lectura, no gobierna ninguna invariante — la expiración la fija
  `expiresAt` absoluto de `start()`). Se escribe por **UPDATE dirigido DBAL** (throttle ~ventana idle, columna **sin
  índice** → HOT-update) y se lee en la **proyección «mis sesiones»** (idiom del repo: keyset/enricher separan agregado de
  proyección). **⟦rev⟧ Motivo corregido:** el argumento «lost-update de dos escritores» es **técnicamente falso** en este
  repo — `save()` = `persist()+flush()` y Doctrine emite un UPDATE **parcial por columna** (solo el changeset), así que un
  `revoke()` produce `UPDATE ... SET status=? WHERE id=?` y **no** toca `last_seen_at` aunque estuviera mapeado. La
  conclusión (sacarlo del agregado) **es correcta**, pero por otra razón: **aislamiento de hot-path/churn** — evita hidratar
  y flushear el agregado en cada heartbeat, y mantiene un campo de alta rotación fuera del changeset, del diff de auditoría
  y de los eventos. Medir con Behat query-budget (2 requests en ventana → 0 UPDATEs) + benchmark informativo.
- **F — PWA: RESUELTA (F-reco + correcciones UX de Sally + Fork 3).** Backend 401 + **redirección a B1 con `?next=` Y
  `?reason=session-expired`** (un rebote **mudo** se lee como «perdí trabajo» → B1 muestra una línea `aria-live`, **no** un
  2º `<h1>`) + «mis sesiones» mínima. La variante `AccessWall session-expired` pulida → **II-4**. **Innegociable:** **matar
  `SEED_SESSION`** (el cliente auto-siembra un ADMIN sin login → con el gate real, 401 al primer `/api`); reflejo 401
  **centralizado en el `FetchHttpClient` con single-flight** (1er 401 limpia sesión+redirige, los demás no-op) → evita
  tormenta de 401. **AC9 vs T9:** el camino diario va a **B1 (`/login`)**, NO a `SignInRequiredScreen` (ese queda como
  *fallback* de 401 en frío/SSR). «Mis sesiones» vive en **`context/shared/access`** (autoservicio de seguridad del
  usuario, no backoffice); lista + «This device» **textual** + «cerrar las demás» (la fila actual **no** lleva «Close»
  genérico); reusa primitivas `@/components/erpify`, sin crear ni promover ninguna.
- **G — FK `iam_session.user_id`: RESUELTA — sin FK física.** Aislamiento por id (patrón `membership.user_id`), y sobre
  todo **erasure-first**: una FK `RESTRICT` bloquearía el hard-delete GDPR del usuario; `CASCADE` acoplaría el borrado en
  DB entre módulos. La integridad la da el gate (una sesión de un usuario borrado es inerte). **Ojo `⟦rev⟧`:** `db.diff`
  emitirá **`created_at` Y `updated_at`** (ambos los fuerza, non-null, el trait `Timestamped` de `AggregateRoot`; distintos
  de `last_seen_at` — última transición vs última observación). Si el modelo solo quería `created_at` + `last_seen_at`,
  extender `AggregateRoot` impone igualmente `updated_at`: acéptalo o rediseña la base (no se puede omitir extendiendo).
- **H — eventos: RESUELTA.** `SessionStarted`/`SessionRevoked`/`AllSessionsRevoked` por outbox (R1), **sin reactor** (R2 —
  el `SecurityEmail` async es II-8). Payload **mínimo sin PII** (`aggregateId`, `userId`, `occurredAt`; **ni `ip` ni
  `device`**). 3 clases planas (Regla-de-Tres por módulo). **⟦rev⟧ Dos notas:** (1) el vocabulario de eventos del
  addendum/epics (NFR10) declara **`SessionCreated`**, no `SessionStarted` — reconciliar (rename OK, pero actualiza el
  artefacto de planning para no dejar el nombre huérfano). (2) «espeja `UserSuspended`» **subestima el contrato**: cada
  evento debe implementar `eventName()` (`'erpify.iam.session.<fact>'`) **y** `fromPrimitives()` además de `aggregateType()`
  (`'Iam.Session'`) / `toPrimitives()` — los cuatro son `abstract` en `DomainEvent`, no se heredan.
- **I — `organizationId` al acuñar: RESUELTA por G1 (fuente = `Membership`).** El **listener de minting (Identity/Infra)
  es el orquestador cross-contexto:** resuelve el `organizationId` **desde el `Membership` del usuario** (fuente
  autoritativa SI-15), lo pasa **primitivo** a `StartSession` → **`Session/Application` queda libre de `Organization`**.
  **Vía un caso de uso de LECTURA nuevo publicado en `Organization/Membership/Application`** (p.ej. `FindUserOrganizationId`),
  **no** inyectando `MembershipRepository` (regla de aislamiento) ni con puerto en `Iam` (Regla de Tres). Seam nuevo
  per-file `Identity→Organization` en `.bounded-context-allowlist` + `deptrac.yaml skip_violations` (`⟦rev⟧` NO reutiliza
  el de `GrantMembership`: ningún `Iam/` importa `OrganizationRepository` hoy). `Membership` ausente → **503 fail-closed**,
  nunca `organizationId` vacío. **⇒ II-7 introduce DOS seams per-file: `Identity→Session` (B) y `Identity→Organization` (I).**
- **J — AC7 (coherencia camino nativo): RESUELTA — reubicada.** La actualización del registro «en paso» con la de-auth
  nativa por cambio de credencial **NO** vive en `UserProvider::refreshUser` (rompería SRP + metería seam Identity→Session):
  vive en un **reactor sobre `TokenDeauthenticatedEvent` del firewall en `Iam/Session/Infrastructure`** (Session/Infra →
  Session/Application, sin seam), leyendo solo `userId`/`iamSessionId` primitivos. Corrige la redacción de T8.
- **K — Erasure/GDPR (gap del consult): RESUELTA — reactor de purga.** Sin FK (G), el hard-delete del usuario **no
  cascadea** a `iam_session` → filas huérfanas con `userId` sobrevivirían al borrado (familia del gap de resurrección
  async del audit #376). II-7 añade un **reactor sobre el evento de erasure/borrado de `User`** que purga (o revoca+borra)
  sus filas `iam_session`. Cubrir con test.
- **L — Sentry flood (gap): nota operacional → ver O3.** El 503 llega a Sentry **por diseño** (no-`ClientError`); con el
  store caído = 1 evento/request. Prever dedup/sampling o dejarlo flagueado. **⟦rev⟧** la enumeración de markers que fija
  `ClientError ⇔ 4xx` NO vive en `SentryEventFilterTest` (sus casos son fijos) sino en
  `MarkerStatusMapContractTest::testMarkerIsClientErrorIffStatusIs4xx` — el marker 503 `ServiceUnavailable` **no** debe
  extender `ClientError` o ese test falla; no hace falta tocar `SentryEventFilterTest` para añadirlo.
- **M — Rollout (gap): nota de deploy.** Las sesiones nativas **pre-II-7** no tienen `iamSessionId` → el gate las 401ea →
  **logout global forzado** en el deploy de II-7. Aceptable (una vez), pero **nombrarlo** en la PR.

### Fuera de alcance (frontera explícita — no lo hagas en II-7)

- **Store compartido / multi-node / `PdoSessionHandler`** → **ADR nuevo** (D8 forward-path + NFR11; II-7 solo deja el seam).
- **Las 6 pantallas de acceso + variante `AccessWall session-expired` pulida** → **II-4**.
- **El *consumo* revoca-todas del reset + la regeneración del 2º salto (accept/reset)** → **II-4/II-5** (II-7 solo provee
  la capacidad de revocación).
- **Lockout `LockedUntil`** → **II-6**.
- **Barrido constant-time transversal + `SecurityEmail` async + rate-limit neutral** → **II-8**.
- **UI backoffice de gestión de sesiones de terceros (admin revoca a otro usuario)** → slice diferido (lenguaje visual
  backoffice, posterior a la superficie pública).
- **CSRF double-submit + regeneración del login POST** → **II-4** (el login ya regenera vía `migrate` nativo).

### Project Structure Notes

- Nuevos ficheros bajo `Iam/Session/{Domain,Application,Infrastructure}` — las capas `Iam.Session.*` **ya están en
  `deptrac.yaml`** (esqueleto II-0) y `doctrine.yaml` (`Iam` cubre `Iam\Session`) → **sin** capas ni mapping nuevos.
- **Seam Session↔Identity:** `Iam/Session` e `Iam/Identity` son sub-módulos del **mismo** bounded context `Iam`, pero
  deptrac aísla a **nivel de módulo** → si el gate/minting importa `SecurityUser`/`Role` desde `Session`, es cross-módulo
  y necesita entrada **per-file** en `.bounded-context-allowlist` + `skip_violations` (espejo líneas 66-82). **Recomendado
  evitarlo:** el minting toma el `userId` como **primitivo** del token en la capa Infra de Identity y llama a `StartSession`
  con strings → **cero seam nuevo**. Preferir esta vía.
- PWA: «mis sesiones» como capacidad bajo `context/backoffice/<entity>` o `context/shared/access`; sin adelantar la
  frontera `components/{ui,erpify}` no autorizada.
- Migración en `api/migrations/2026/` vía `db.diff` (editable en esta rama; inmutable tras merge).

### References

- [Source: `docs/adr/identity-invitation-lifecycle.md` D8] — Session store Option-1 endurecida (nativo + registro + gate
  fail-closed + revocación lógica); descartes (unified handler, PdoSessionHandler-ahora, borrado físico).
- [Source: `_bmad-output/planning-artifacts/arch-addendum-identity-invitation.md` SI-11 (gate fail-closed, TCB), NFR11
  (sustituibilidad), PR-7, DAG].
- [Source: `_bmad-output/planning-artifacts/epics-identity-invitation-lifecycle.md` Story II-7 (líneas ~424-429); FR8;
  NFR3/4/10/11; UX-DR11; dependencias líneas ~450-459].
- [Source: `docs/api-error-contract.md`] — marker→status, `testMarkerIsClientErrorIffStatusIs4xx` (503 no-`ClientError`),
  NFR26; el tipo operacional del gate marcado «out of scope» en II-3.
- Precedentes de código: `Iam/Identity/Domain/Entity/User.php` (agregado + ctor-funnel + guardas + `record()`) ·
  `Shared/Kernel/Domain/Aggregate/AggregateRoot.php` · `IdentityStatus.php` (enum) · `UserRepository` +
  `DoctrineUserRepository` (puerto + adapter por composición) · `UserSuspended.php` (evento) · `ChangeUserStatus.php`
  (transacción + outbox; puertos `TransactionManager`/`EventBus`) · `LoginOriginListener.php` +
  `UnauthenticatedAccessListener.php` (listeners + prioridades + 401-vs-403) · `SecurityActorContextFactory.php` (`⟦rev⟧`
  el precedente NO es «sin importar `SecurityUser`» — **sí depende** de `SecurityUser` (`$user instanceof SecurityUser` +
  `$user->id()`, mismo namespace, sin `use`); el patrón real es **co-ubicar el código que toca `SecurityUser` en
  `Iam/Identity/Infrastructure` y entregar el id como primitivo string** aguas abajo — es lo que hace que el minting de la
  `Session` no meta seam nuevo, no que evite tocar `SecurityUser`) · `MembershipOrganizationForeignKeySchemaListener.php` (FK cross-módulo
  schema-aware) · `Version20260709230444.php` (migración idempotente/reversible) · `Shared/Uuid/Domain/Uuid.php` (v7).

## Dev Agent Record

### Agent Model Used

<!-- rellena el dev-agent -->

### Debug Log References

### Completion Notes List

### File List
