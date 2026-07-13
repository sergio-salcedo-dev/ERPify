---
baseline_commit: 79b5669a
---
# Story II-5: Reset de contraseña uniforme — slice backend (revoca todas las sesiones, limpia el lock)

Status: done

<!-- Validación opcional. Ejecuta `bmad-create-story` validate para un chequeo de calidad antes de dev-story. -->

## Story

Como **usuario que perdió el acceso**,
quiero **solicitar y usar un enlace de restablecimiento con una respuesta idéntica exista o no mi cuenta**,
para **recuperar el acceso sin que la respuesta permita enumerar cuentas y con todas mis sesiones previas cerradas por seguridad**.

## Contexto (leer antes de tocar código)

Esta es **II-5 (PR-5)** de la épica `identity-invitation-lifecycle` (orden de merge safe-first
`II-0 → II-1·II-2·II-3·II-7 → II-6 → II-4 → II-5 → II-8`). Es el **2.º salto de privilegio** (junto a accept-invitation
de II-4) y el **2.º consumidor real** de `Shared/Token`.

> **⚠️ ALCANCE RATIFICADO POR SERGIO — esta story es SOLO el SLICE BACKEND PARALELO.** Se construye **sobre `main`**
> (que ya tiene II-2/II-3/II-6/II-7), **en paralelo a II-4** (que aún NO está en `main` — es la PR #490 draft solo-spec).
> Todo lo que **reutiliza superficie de II-4** (pantallas PWA, plantilla de email, el wiring «reset acuña sesión fuera del
> firewall» + CSRF stateless) se **DIFIERE**: aterriza **tras** mergear II-4, rebasando esta rama sobre `main` y montando
> encima. Ver **«Frontera del slice: DENTRO vs DIFERIDO»** más abajo — es la sección que gobierna qué se hace ahora.

**Reutiliza, no reinventes** — tres piezas ya en `main` (verificadas):

- **II-2 (`Shared/Token`, #466)** — `SingleUseToken` (entropía + hash-at-rest + verify constant-time). II-5 es su **2.º**
  consumidor (el 1.º es II-4 accept, en paralelo). El VO **no** fuerza el single-use: el retire-then-act es del consumidor.
- **II-3 (`IdentityStatus`/`UserChecker`, #467)** — `User` con `IdentityStatus` + `HashedPassword` nullable; los muros
  post-identidad `AccountSuspended`/`AccountDeactivated` (403). **`AccountLocked` ya apunta a este flujo** («Reset your
  password to regain access.»).
- **II-6 (lockout, #475)** — `User::clearLockout()` + `failedAttempts`/`lockedUntil` en la identidad. II-5 lo limpia (D-b).
- **II-7 (`Iam/Session`, #469)** — **`RevokeAllSessions::revoke($userId)`** revoca **todas incl. la actual**; su evento
  `AllSessionsRevoked` **ya dice en su docblock «Consumed by the password-reset flow»**.

Fuente de verdad del diseño (**no re-abrir, ya ratificado por Sergio**):
[`docs/adr/identity-invitation-lifecycle.md`](../../docs/adr/identity-invitation-lifecycle.md) **D9, D10, D11, D12** (contexto D3/D4/D6/D8) ·
[`_bmad-output/planning-artifacts/arch-addendum-identity-invitation.md`](../planning-artifacts/arch-addendum-identity-invitation.md) **SI-12, SI-13, SI-14, NFR3, PR-5** ·
[`_bmad-output/planning-artifacts/epics-identity-invitation-lifecycle.md`](../planning-artifacts/epics-identity-invitation-lifecycle.md) **Story II-5, líneas 801-845** (FR6) ·
[`_bmad-output/implementation-artifacts/ii-4-invitation-accept-pantallas-acceso.md`](ii-4-invitation-accept-pantallas-acceso.md) — **la story hermana**, el patrón que II-5 espeja (retire-then-act, session-mint, CSRF, opacidad).

**La frase que gobierna II-5:** el reset **verifica y consume** el token de un solo uso (retire-then-act **atómico** en una
transacción de dominio), fija una **nueva `HashedPassword`** en un `User` **`ACTIVE`**, **revoca TODAS sus sesiones**
(incl. la actual — quien resetea arranca sin sesión de confianza), **limpia el lockout** (`LockedUntil`), y si la identidad
es **no-`ACTIVE`** aterriza en el muro post-identidad **sin conceder nada**. El forgot responde **uniforme** (mismo status +
forma) exista o no la cuenta. Toda **muerte del token** (usado / caducado / inexistente) colapsa a **un único** `invalid-token`
opaco. El token **nunca** se renderiza ni se persiste crudo.

---

### Frontera del slice: DENTRO (ahora, sobre `main`) vs DIFERIDO (tras rebasar sobre II-4)

**✅ DENTRO del slice paralelo — solo consume II-2/II-3/II-6/II-7 (ya en `main`):**

- **`RequestPasswordReset` (forgot)** → acuña un `SingleUseToken`, respuesta **uniforme (SI-12)** exista o no la cuenta,
  emite `PasswordResetRequested` (solo para `ACTIVE`; nadie más), **persiste el hash** del token de reset. El **envío del
  email** con el enlace es un **SEAM diferido** (reutiliza `SecurityEmail` de II-4) — el caso de uso deja el punto marcado.
- **`CompletePasswordReset` (reset)** → **consume** el token (**retire-then-act atómico**, single-use **opaco**), fija la
  nueva `HashedPassword` en el `User`, **revoca-todas** (II-7), **limpia `LockedUntil`** (II-6), y **no-`ACTIVE` → muro
  post-identidad sin sesión** (D-c). El **mint de la sesión post-reset** (auto-login) es un **SEAM diferido** (NFR3).
- **Endpoints HTTP** forgot + reset (rutas públicas dentro del firewall `main`) con **Origin check** (espejo de
  `LoginOriginListener`) como control **primario** — el **CSRF stateless** es SEAM diferido (reutiliza el de II-4).
- **Tests Behat-API + unit** de los invariantes: uniforme · revoca-todas · clear-lock · single-use opaco · muro no-`ACTIVE`.

**⏸️ DIFERIDO hasta que II-4 mergee (luego rebase sobre `main` y montar encima):**

- **PWA:** `TokenActionScreen` variante **reset** · `SecuritySignal` **password-changed** · **colapso del copy**
  («…inválido **o ha expirado**» → «Este enlace ya no es válido») · el port/adapter de reset del cliente. **Reutiliza los
  componentes que crea II-4** (no los dupliques).
- **`SecurityEmail` reset / password-changed** → reutiliza la **plantilla** que crea II-4 (solo cambia el copy/variante).
- **El wiring «reset acuña sesión fuera del firewall» + CSRF stateless** → reutiliza el de II-4 (`Security::login('main')`
  → `SessionMintingSuccessListener` + `migrate(true)` nativo; `framework.csrf_protection.stateless_token_ids`). **No lo
  construyas en paralelo.** Deja el **seam marcado**; el único punto propio es la **regeneración de id (NFR3)** al re-usar
  ese wiring. El transversal de endurecimiento (constant-time timing, `Referrer-Policy`, strip URL, log-redaction,
  rate-limit) es **II-8**, no II-5.

**🔭 VIGILAR EN EL REBASE (el único roce previsible):** `api/src/Iam/Identity/Domain/Entity/User.php`. **II-4 lo toca**
(llama `activate()` en el accept); **II-5 le añade `resetPassword()`**. Es el **único** solape esperado. Mantén ese cambio
**mínimo y localizado** (una sola función pública nueva + su evento) para que el rebase sea trivial. Secundario (aditivo,
bajo riesgo): `config/packages/security.yaml` (nueva entrada `access_control` para las rutas de reset — bloque distinto al
de II-4) y `config/routes.yaml` (bloque `resource` propio).

## Acceptance Criteria

Los AC se redactan como **invariantes verificables** enganchados al ADR (D9–D12), a los System Invariants (SI-12/13/14) y a
las reglas de proyección (D-b/D-c), de modo que una refactorización futura no pueda romper una garantía sin que un test la
detecte. Los AC 1–10 son el **slice backend (DENTRO)**; los AC D1–D3 son **contratos DIFERIDOS** (se realizan tras rebasar
sobre II-4) — su test se enuncia aquí pero **no se implementa en este PR**.

1. **(Persistencia · Decisión A · SI-13)** El token de reset se modela como un agregado/registro **state-oriented** cuyo
   **crudo nunca persiste** — solo su **hash** (embebe `SingleUseToken` de II-2, `fromHash`+`verify`). Se recomienda un
   agregado propio **`PasswordResetToken`** en `Iam/Identity` (ver Decisión A); el retire-then-act consume el registro en la
   MISMA transacción que fija la password. Un test prueba que se persiste el hash y **nunca** el plaintext.

2. **(SI-12 · forgot uniforme)** Dado un forgot con un email cualquiera, la respuesta es **idéntica** (status **y** forma/
   tamaño) exista o no la cuenta y **sea cual sea su estado** (`INVITED`/`ACTIVE`/`SUSPENDED`/`DEACTIVATED`/inexistente). Un
   test **compara cara a cara** existente-`ACTIVE` vs inexistente y exige respuesta idéntica. **Solo `ACTIVE`** acuña token +
   emite `PasswordResetRequested` + dispara el email (seam); el resto **no muta nada** — pero **la respuesta no lo delata**
   (el trabajo interno no es observable por el peticionario anónimo). *(La indistinguibilidad en **timing** se cierra en
   II-8 con un suelo constant-time transversal; II-5 garantiza status + forma.)*

3. **(Revoca-todas · consume II-7)** Un reset con éxito **no deja ninguna `Session` `ACTIVE` previa** de ese usuario —
   **todas** revocadas vía `RevokeAllSessions::revoke($userId)` (**incl. la actual**, no «todas menos la actual»: quien
   resetea no tiene sesión de confianza). Un test asserta 0 sesiones activas del usuario tras el reset y **1**
   `AllSessionsRevoked` en el outbox.

4. **(D-b · limpia el lock · consume II-6)** Dada una identidad con `lockedUntil > now`, tras completar el reset
   `lockedUntil`/`failedAttempts` quedan **limpios** (`User::clearLockout()`) — puede entrar **sin esperar al TTL**. Un test
   parte de un `User` bloqueado y verifica el desbloqueo.

5. **(D-c · no-`ACTIVE` → muro sin sesión)** Un token de reset **válido** cuya identidad es **no-`ACTIVE`** en el momento de
   completar aterriza en el **muro post-identidad** y **no** obtiene sesión ni cambia la password: `SUSPENDED` →
   `account-suspended` (403, específico, reusa `AccountSuspended` de II-3); `DEACTIVATED` → genérico `forbidden` (403, reusa
   `AccountDeactivated`). Un test cubre ambos estados (identidad suspendida/desactivada **entre** el request y el complete).
   *(Un token válido prueba control del email → post-identidad → especificidad graduada, SI-14 — coherente con II-3.)*

6. **(Single-use opaco · SI-13 · retire-then-act atómico)** El token de reset es **single-use**: consumirlo lo **retira en
   la misma transacción** que el cambio de password (contrato diferido de II-2). Reusarlo → `invalid-token`. **Los tres casos
   de muerte del token** {usado, caducado, inexistente} producen **una sola** respuesta **byte-idéntica** — `invalid-token`
   (RFC 9457) — **nunca** el motivo. Un test **compara los tres** y exige identidad; el mismo `type` que II-4 (opacidad
   **cross-superficie**: un enlace de reset muerto es indistinguible de uno de invitación muerto).

7. **(`User::resetPassword()` — el método nuevo · roce con II-4)** `User` gana **una** función pública nueva
   `resetPassword(HashedPassword): void` que exige la identidad **`ACTIVE`** (guard defensivo → excepción de dominio si se
   la invoca sobre otro estado), fija el nuevo hash, bumpea `updatedAt` y **graba un evento** de credencial cambiada. Es el
   **único** cambio en `User.php` (roce previsible con `activate()` de II-4 — mantenerlo mínimo). Un test unitario cubre el
   camino feliz y el guard. **`activate()` NO se reutiliza** (está guardado a `INVITED→ACTIVE`).

8. **(Eventos · NFR10, coherente con NFR1)** Se emiten por el `EventBus`/outbox **dentro de la transacción**
   (`TransactionManager::transactional` + `EventBus::publish(...pullDomainEvents())`): `PasswordResetRequested` (forgot de un
   `ACTIVE`) y `PasswordResetCompleted` (reset con éxito), **payload PII-free** (solo `userId`, **nunca** email ni token). Un
   **reset fallido** (`invalid-token` **o** muro no-`ACTIVE`) emite **0 eventos** y **no muta estado** (vaciar outbox + reset
   stats **antes**, assert 0 **después**). Un **forgot de cuenta inexistente/no-`ACTIVE`** emite **0 eventos que
   re-enumeren** (no hay evento cuya presencia delate la cuenta).

9. **(NFR3 · regeneración — SEAM DIFERIDO)** Un reset con éxito sobre una identidad `ACTIVE` **puede** acuñar sesión
   (auto-login) y, si lo hace, **regenera el id de sesión** (2.º salto de privilegio, no pasa por `json_login`). **En este
   slice NO se construye el mint** (reutiliza el wiring de II-4 tras el rebase): el backend **revoca-todas** y responde
   **204 sin cookie** — el usuario entra por login normal con la password recién fijada (estado **benigno recuperable**,
   espejo de II-4). El seam queda **marcado en el controlador**; el test de regeneración es **AC diferido (D1)**.

10. **(No-regresión · transversal)** `make app.test` + `make app.quality` verdes; el login de un `ACTIVE` sigue devolviendo
    **204 + cookie** intacto (II-3); el `SessionAdmissionGate` (II-7) no regresa; los muros `suspended`/`deactivated`/`locked`
    no regresan; **cero credenciales/PII/tokens en migración, logs o respuestas**; deptrac/bounded-context/error-contract
    verdes.

**Contratos DIFERIDOS (se enuncian; NO se implementan en este PR — aterrizan al rebasar sobre II-4):**

- **D1 (NFR3 · session-mint + regeneración).** Al re-usar el wiring de II-4 (`Security::login('main')` → mint +
  `migrate(true)`), un reset `ACTIVE` acuña la sesión y **regenera el id**; test Behat compara la cookie pre/post + asserta
  **1** `Session` nueva + **1** `SessionStarted`. **Marcar el seam** en el controlador de reset ahora.
- **D2 (CSRF stateless).** Registrar el token id del reset en `framework.csrf_protection.stateless_token_ids` (nativo,
  reutiliza la config que II-4 introduce). En este slice el control **primario** es el **Origin check**; el CSRF es
  **defense-in-depth** (honra D5/D11, no primario).
- **D3 (PWA + `SecurityEmail`).** `TokenActionScreen` variante reset · `SecuritySignal` password-changed («…hemos cerrado las
  demás sesiones abiertas.») con foco al `<h1>` · colapso del copy · `SecurityEmail` reset/changed (reutiliza plantilla II-4)
  · port/adapter cliente. **Todo reutiliza componentes de II-4** — no se duplica.

## Tasks / Subtasks

> **Paralelización (CLAUDE.md):** este slice es **solo backend** — no hay subagente PWA (el frontend es DIFERIDO). Dentro del
> backend, T1/T2 (dominio) y T5 (excepción) son fanoutables, pero T3/T4 (casos de uso) dependen de ellos. Recomendado:
> secuencial dominio → app → http → tests, o un subagente por agregado si se separa `PasswordResetToken` de `User`.

### Backend — `Iam/Identity` reset (DENTRO del slice)

- [x] **T1 — Agregado `PasswordResetToken` + repo + migración (AC1, AC6) = Decisión A**
  - [x] `api/src/Iam/Identity/Domain/Entity/PasswordResetToken.php` — `final class PasswordResetToken extends AggregateRoot`,
        `#[ORM\Table(name: 'identity_password_reset_token')]`. Props por id (UUIDv7): `userId` (string, `Uuid::ensure()` en el
        borde), `tokenHash` (string), `expiresAt` (timestamptz). **State-oriented**, **sin PII, sin token crudo** (embebe el
        hash; rehidrata `SingleUseToken::fromHash($tokenHash, $expiresAt)` para `verify`). Factory `static issue(string $id,
        string $userId, SingleUseToken $token): self`. **Consumo = retire (borrado físico) en la misma TX que el reset**
        (ver Decisión A: sin enum de estado — `verify()` cubre la caducidad; el «superseder al re-pedir» = borrar el previo).
  - [x] **Localización del token = selector-verifier (usa el VO tal cual, SIN editar `Shared/Token`).** El VO `SingleUseToken`
        oculta su `digest` (privado) y solo expone `verify($plain,$now)` sobre un token **ya cargado** — no hay lookup-por-hash
        público, y **no se toca `Shared/Token`** (respeta «`User.php` es el único roce»). Por eso el enlace lleva
        **`?token=<resetTokenId>.<secret>`**: el `id` (PK, no secreto) **selecciona** la fila, el `secret` (plaintext 256-bit)
        se **verifica** constant-time contra `tokenHash`. Un `id` inexistente/caducado/ya-consumido → `findById` null / `verify`
        false → **el mismo** `invalid-token` (opacidad intacta: conocer un `id` no compra nada sin el secreto). Ver Decisión A.
  - [x] Puerto `api/src/Iam/Identity/Domain/Repository/PasswordResetTokenRepository.php` (`save`, `remove`,
        **`findById(string $id): ?PasswordResetToken`**, `deleteAllForUser(string $userId): void` para el supersede) +
        adapter Doctrine `.../Infrastructure/Persistence/Doctrine/DoctrinePasswordResetTokenRepository.php` (`#[AsAlias]`,
        composición sobre EM, mirror `DoctrineUserRepository`/`DoctrineSessionRepository`).
  - [x] `make db.diff` → migración `api/migrations/2026/` (tabla `identity_password_reset_token`, PK `id`, índice sobre
        `user_id` para `deleteAllForUser`). `down()` reversible. **Cero PII/credenciales/token crudo** en el schema.
        Editable en esta rama, inmutable tras merge. **No** `NOT NULL` crudo sobre filas existentes (tabla nueva, sin filas → ok).

- [x] **T2 — `User::resetPassword()` + evento (AC7) — el roce con II-4, mínimo y localizado**
  - [x] `api/src/Iam/Identity/Domain/Entity/User.php` — añadir **una** función pública:
        `resetPassword(HashedPassword $password): void`. Guarda que `status === IdentityStatus::ACTIVE` (si no, excepción de
        dominio — es un bug de orquestación, el muro D-c lo intercepta **antes** en el caso de uso); fija
        `passwordHash = $password->toString()`, `updatedAt = SystemClock::now()`, y **graba** un evento
        `PasswordWasReset($this->id, ...)`. **No** reutilizar `activate()` (guardado a `INVITED→ACTIVE`). Mantén el diff a
        esta única función + el import del evento (rebase-friendly con II-4).
  - [x] Evento `api/src/Iam/Identity/Domain/Event/PasswordWasReset.php` — subclase de `DomainEvent`,
        `eventName='erpify.iam.identity.password_was_reset'`, `aggregateType='Iam.Identity'`, **payload PII-free** (solo
        `userId`). **Regla-de-Tres POR MÓDULO:** son 2 eventos nuevos (`PasswordResetRequested` + `PasswordWasReset`/
        `PasswordResetCompleted`) → **sin trait de snapshot** (mirror memoria `bankaccount-event-envelope-trait-vs-bank`;
        NO unificar con Session/Invitation). *(Nombre del evento: ver nota de nomenclatura en Dev Notes — `PasswordWasReset`
        de dominio vs `PasswordResetCompleted` de aplicación; elegir uno, no duplicar.)*
  - [x] **¿`resetPassword` limpia el lock por dentro (D-b) o lo orquesta el caso de uso?** Recomendado: el **caso de uso**
        llama `user.resetPassword(...)` **y** `user.clearLockout()` explícitos (métodos single-purpose, composables). Ver
        Decisión B.

- [x] **T3 — Caso de uso `RequestPasswordReset` (forgot) (AC2, AC8)**
  - [x] `api/src/Iam/Identity/Application/RequestPasswordReset.php` — `request(string $email): void`. Flujo **uniforme**:
        1. resolver el `User` por email (`UserRepository::findByEmail(new Email($email))` — usar la VO `Email` de dominio;
           un email malformado ya lo caza el `#[Assert\Email]` del DTO → 422, ortogonal a la enumeración);
        2. **si NO existe o NO es `ACTIVE`** → **return** silencioso (sin token, sin evento, sin email) — la respuesta HTTP
           es idéntica igual;
        3. **si es `ACTIVE`** → en un `transactional`: `$tokens->deleteAllForUser($userId)` (supersede el previo),
           `SingleUseToken::mint($clock->now()->add(TTL))` → `PasswordResetToken::issue(...)` → `save` + `eventBus->publish(
           new PasswordResetRequested($userId, ...))`; **fuera/tras** el commit, **[SEAM diferido D3]** enviar el
           `SecurityEmail` (reset) con `->plaintext()` en el enlace (`?token=`). Marca el seam con un `// TODO`-de-story
           (barrer del diff final) — en el slice, deja el punto de envío **preparado** (interfaz `SecurityMailer`/no-op) sin
           construir la plantilla.
  - [x] TTL de reset = política de II-5 (p.ej. **1h**, más corto que la invitación 72h — un reset es más sensible) —
        **constante nombrada**, no magic number.
  - [x] El plaintext **no se persiste ni se loggea** (`GeneratedToken` lo guarda `#[SensitiveParameter]`; solo el hash a DB).

- [x] **T4 — Caso de uso `CompletePasswordReset` (reset) (AC3, AC4, AC5, AC6) — el corazón del slice**
  - [x] `api/src/Iam/Identity/Application/CompletePasswordReset.php` — `complete(string $token, string $newPassword): void`
        (donde `$token = "<resetTokenId>.<secret>"`, ver T1):
        1. **split** `$token` en `id` + `secret` (formato inválido → `InvalidResetToken`); `findById($id)` → si null →
           `InvalidResetToken`; `$record->verify($secret, $now)` (constant-time sobre el VO cargado) → false →
           `InvalidResetToken`. **Cualquier fallo** (formato / no encontrado / caducado / secret no casa) → lanzar
           `InvalidResetToken` (T5) **antes de mutar nada**, **misma excepción y mensaje** para todos los casos (opacidad, AC6);
        2. cargar el `User` (`UserRepository::findById($token->userId())`);
        3. **guard D-c:** si `user.status() !== ACTIVE` → lanzar el muro post-identidad (`SUSPENDED` → `AccountSuspended`;
           `DEACTIVATED` → `AccountDeactivated`; reusar II-3) — **sin** consumir el token ni mutar (el token sigue vivo para
           un futuro intento si la cuenta se re-activa; alternativa: consumirlo igual — ver Decisión B);
        4. en **un** `transactional`: `HashedPassword::fromHash($passwordHasher->hash($newPassword))` (reusar `PasswordHasher`
           de II-3) → `user.resetPassword($hashed)` → `user.clearLockout()` (D-b) → `$tokens->remove($token)` (**retire-then-act:
           el borrado del registro ES el consumo del single-use, en la MISMA TX que el reset**) → `save` user +
           `eventBus->publish(...pullDomainEvents())` (incluye `PasswordWasReset`) + `eventBus->publish(new
           PasswordResetCompleted($userId, ...))`;
        5. **fuera** de esa TX (y **tras** el commit del cambio de password): `RevokeAllSessions::revoke($userId)` (AC3 — su
           propio `transactional`, frontera de agregado `Session` distinta; **2 TX es correcto**, mirror II-4 accept, no un
           defecto). **Por qué 2 TX es seguro:** el cambio de password de-autentica las sesiones viejas por el camino
           **nativo** `refreshUser`/`hasUserChanged` de II-7 (el hash en el token de sesión deja de casar) → aunque
           `RevokeAllSessions` fallara (503 store down), ninguna sesión vieja sobrevive a la siguiente request; el revoke
           explícito es **eager/defensa-en-profundidad**, no la única barrera (ver `RevokeSessionOnTokenDeauthenticated`);
        6. **[SEAM diferido D1 + D3]** `Security::login` (mint + regeneración NFR3) y el email «tu contraseña ha cambiado» —
           **no en este slice**. El controlador responde **204 sin cookie** (ver T6). Marcar el seam.
  - [x] **Ordering (riesgo de implementación):** el retire-then-act (borrar el `PasswordResetToken`) + `resetPassword` +
        `clearLockout` van **dentro** del `transactional` y **antes** de revocar sesiones / login. Si valida el token y falla
        antes de borrarlo, quedaría replayable dentro del TTL → por eso pasos 4 van todos en **una** TX. **Idempotencia
        (NFR9):** un reintento tras caída de red → o el token ya se consumió (borrado → `invalid-token` → muro con «Iniciar
        sesión», D-a) o sigue vivo (→ reintento válido); **nunca** doble efecto (no doble revoke-mint).

- [x] **T5 — `invalid-token` en el contrato de error (AC6) = Decisión C**
  - [x] `api/src/Iam/Identity/Domain/Exception/InvalidResetToken.php` — `final class InvalidResetToken extends DomainException
        implements InvalidInput` con `type()` override → `'invalid-token'` (precedente `InvalidUuidException`;
        **`InvalidInput` YA existe en `main`** → self-contained, NO depende de II-4). **Sin marker interface nuevo** (vive en
        `Iam/Identity`, no en `Shared/ErrorContract` → `ErrorContractGateTest` git-aware no dispara). **Status = 400**
        (uniforme en los 3 casos = opacidad; **alinea con la Decisión B ratificada de II-4** — 400 `InvalidInput`).
  - [x] Actualizar [`docs/api-error-contract.md`](../../docs/api-error-contract.md): documentar que `invalid-token` (hoy
        reservado «out of scope here» en la línea ~95) lo **realiza también el reset** (pre-identidad opaco, 3 casos
        colapsan). `make php.lint.error-contract` verde. **En el rebase sobre II-4:** reconciliar — ambas superficies
        (invitación + reset) producen el **mismo** `type='invalid-token'` con **su propia** clase excepción por aislamiento
        de contexto (2 clases, 1 wire type; refuerza la opacidad cross-superficie, no la rompe).

- [x] **T6 — Controladores + rutas públicas + Origin (AC2, AC5, AC6, AC9-seam)**
  - [x] `api/src/Iam/Identity/Infrastructure/Http/RequestPasswordResetController.php` — `AbstractController` fino, POST, DTO
        con `#[MapRequestPayload]` + `#[Assert\Email]` sobre `email`. Delega en `RequestPasswordReset`. **Respuesta uniforme
        202** (Accepted — «si esa dirección corresponde a una cuenta, te enviaremos un enlace») **sin body variable**
        (ver Decisión D). **Nunca** ramifica por existencia.
  - [x] `api/src/Iam/Identity/Infrastructure/Http/CompletePasswordResetController.php` — POST, DTO con `#[MapRequestPayload]`
        + `#[Assert]` (`token` non-empty `#[Assert\Length(max: 128)]` = `SingleUseToken::MAX_PLAINTEXT_LENGTH`; `password`
        con la **misma policy** que el accept/register — reusar el constraint existente). Delega en `CompletePasswordReset`.
        Éxito → **204 sin cookie** (el mint de sesión es seam D1); `invalid-token` → 400; muro D-c → 403.
  - [x] Ruta: bloque `resource` nuevo en `api/config/routes.yaml` apuntando a `../src/Iam/Identity/Infrastructure/Http/`
        con `defaults: { _format: json }` y prefijo mirror del login (p.ej. `/backoffice`, → `/backoffice/forgot-password`
        + `/backoffice/reset-password`; confirmar los paths en Decisión D). **Ojo:** si `Iam/Identity/Infrastructure/Http/`
        ya tiene un bloque `resource` (login), **añadir las acciones al existente**, no duplicar el bloque.
  - [x] **`security.yaml`:** añadir `access_control` `PUBLIC_ACCESS` para las 2 rutas de reset **antes** del catch-all
        `^/api → IS_AUTHENTICATED_FULLY` (mirror del `/backoffice/login`). Rutas **públicas/pre-identidad** pero **dentro**
        del firewall `main` (para que el seam D1 `Security::login` resuelva el firewall al aterrizar).
  - [x] **Origin check (control primario):** listener espejo de `LoginOriginListener` **keyed en los nombres de ruta de
        reset** (o generalizar el existente a un conjunto de rutas). `Origin !== getSchemeAndHttpHost()` → 403 `forbidden`
        **sin mutar estado**. **[SEAM diferido D2]** el CSRF stateless (`framework.csrf_protection`) reutiliza el de II-4 —
        **no** introducir `framework.csrf_protection` aquí (lo trae II-4); marcar el punto de registro del token id.

- [x] **T7 — Deptrac seams + Behat (AC3, AC8)**
  - [x] **Seam cross-contexto `Iam/Identity → Iam/Session`:** el `CompletePasswordReset` importa
        `Erpify\Iam\Session\Application\RevokeAllSessions` → violación **Nivel-1** salvo allowlist. Añadir entrada
        **per-file** en `api/.bounded-context-allowlist` **y** deptrac `skip_violations` (`CompletePasswordReset.php =>
        RevokeAllSessions`; **nunca** forma global `* =>` — `DeptracSeamSyncGateTest` la prohíbe; contexto = módulo de 2
        niveles). *(Ya existe un seam `Iam/Identity → Iam/Session` para `SessionMintingSuccessListener → StartSession`; este
        es un fichero + target **nuevos**, entrada aparte.)* `Shared/Token` **no** necesita seam (shared kernel).
  - [x] Behat: nueva feature `api/features/backoffice/identity/password_reset.feature` (mirror `login.feature` +
        `session.feature`). Escenarios `@anonymous` (forgot/reset son públicos, sin `authenticateDefaultUser`):
        forgot existente-`ACTIVE` **vs** inexistente → **respuesta idéntica** (comparación cara a cara, AC2); reset válido →
        204 + password cambiada + **0 sesiones activas** del user + `LockedUntil` limpio; **3 tokens muertos idénticos**
        (usado/caducado/inexistente → mismo `invalid-token`, AC6); reset con token válido + user `SUSPENDED` →
        `account-suspended` (AC5); reset fallido → **0 eventos** (vaciar outbox + reset stats antes, assert 0 después, AC8);
        **1** `AllSessionsRevoked` + **1** `PasswordResetCompleted` en el reset con éxito. Seed del `PasswordResetToken` con
        `id` conocido + `token_hash = sha256(secret)` de un `secret` conocido (Alice o SQL inline **quote-free** — el step
        trunca en comillas dobles → valores sin `"`; o PyString) y el POST usa `token="<id>.<secret>"`. **+2 queries por
        escritura envuelta** (BEGIN/COMMIT); el reset hace varias.
        Worktree fresco → `make php.behat.install` **antes** de los gates.

- [x] **T8 — Tests unit + functional (todos los AC del slice)** — ver «Testing».

- [x] **T9 — Gates + verificación fresca** — `make php.behat.install` (worktree fresco) → `make php.stan` (por fichero;
      exit 139 → `PHP_SERVICE=messenger_worker`) → `make php.test` → `make php.quality` EXIT 0 (único sweep PHPMD/cs-fixer;
      puede OOM 137) → `make php.lint.error-contract` → `make php.lint.bounded-context` → `make php.deptrac` →
      `make php.psalm.taint`. **PWA no aplica en este slice** (frontend diferido). Verificar sobre el **path del worktree**
      `iam-ii5-reset-password-pfs0`, confiar en el exit code recién impreso (no logs viejos).

### DIFERIDO (NO en este PR — al rebasar sobre II-4)

- [ ] **D1 — Session-mint + regeneración (NFR3)** — reutilizar `Security::login('main')` → `SessionMintingSuccessListener`
      + `migrate(true)` (patrón A1 ratificado de II-4). El reset `ACTIVE` con éxito auto-loguea y regenera el id; el
      controlador pasa de 204-sin-cookie → 204-con-cookie. Test Behat de regeneración (AC D1).
- [ ] **D2 — CSRF stateless** — registrar el token id de reset en `framework.csrf_protection.stateless_token_ids`
      (config que II-4 introduce). Defense-in-depth; el Origin check ya cubre el primario.
- [ ] **D3 — PWA + `SecurityEmail`** — `TokenActionScreen` variante reset · `SecuritySignal` password-changed · colapso del
      copy · port/adapter cliente · plantilla `SecurityEmail` reset/changed. **Reutiliza los componentes de II-4.**

## Dev Notes

### Mapa de reutilización (NO reinventar — verificado en `main` @ `79b5669a`)

| Necesidad II-5 | Reutiliza (existe en `main`) | Ubicación |
|---|---|---|
| Token single-use | `SingleUseToken::mint($expiresAt): GeneratedToken` · `::fromHash($hash,$exp): self` · `->verify(#[SensitiveParameter] $plain,$now): bool` (constant-time, opaco) · `->toHash()` · `GeneratedToken->plaintext()` | `api/src/Shared/Token/Domain/{SingleUseToken,GeneratedToken}.php` |
| **Fijar password en ACTIVE** | **NO existe** — `activate(HashedPassword)` está guardado a `INVITED→ACTIVE`. **II-5 AÑADE `User::resetPassword(HashedPassword)`** (T2) | `api/src/Iam/Identity/Domain/Entity/User.php` |
| Limpiar lock (D-b) | `User::clearLockout(): bool` (idempotente, zeroes `failedAttempts` + null `lockedUntil`) | `api/src/Iam/Identity/Domain/Entity/User.php:219` |
| Hash de password | `PasswordHasher::hash(#[SensitiveParameter] string): string` → `HashedPassword::fromHash(...)` | `api/src/Iam/Identity/Infrastructure/Security/PasswordHasher.php` · `.../Domain/HashedPassword.php` |
| Cargar user | `UserRepository::findById(string): ?User` · `findByEmail(Email): ?User` (VO `Email` de dominio) | `api/src/Iam/Identity/Domain/Repository/UserRepository.php` |
| **Revocar TODAS las sesiones** | **`RevokeAllSessions::revoke(string $userId): void`** (incl. la actual; su docblock ya nombra el reset) — **NO** `RevokeOtherSessions` | `api/src/Iam/Session/Application/RevokeAllSessions.php:29` |
| Muros post-identidad (D-c) | `AccountSuspended` (403 `account-suspended`) · `AccountDeactivated` (403 genérico) — `extends DomainException implements Forbidden`, `type()` override | `api/src/Iam/Identity/Domain/Exception/` |
| Marker `invalid-token` | `DomainException implements InvalidInput` + `type()='invalid-token'` (precedente `InvalidUuidException`, 400) | `api/src/Shared/ErrorContract/Domain/Exception/InvalidInput.php` |
| Transacción + eventos | `TransactionManager::transactional(callable): mixed` + `EventBus::publish(DomainEvent ...$e)` (variadic) — patrón `RevokeAllSessions`/`StartSession`/`ChangeUserStatus`/`LoginAttemptRegistrar` | `api/src/Shared/{Persistence/Application,Event/Domain}/` |
| Origin check | `LoginOriginListener` (`Origin===getSchemeAndHttpHost()`) — **espejar keyed en rutas de reset** | `api/src/Iam/Identity/Infrastructure/Http/LoginOriginListener.php` |
| Behat: eventos/outbox | `EventStoreContext` (`there should be :count event(s) stored named :eventName`) · `OutboxContext` (`I reset the outbox context`, `:number outbox event(s) was/were created`, `there should not have been an outbox event created containing:`) | `api/tests/Behat/Context/{EventStore,Outbox}Context.php` |
| Behat: HTTP + seed | `HttpRequestContext` (`I send a :method request to :url with body:`, `I add :name header equal to :value`) · `FixturesContext` (Alice) · `SqlQueryContext` (SQL inline) · `SecurityContext` (`@anonymous` desactiva el auto-login) | `api/tests/Behat/Context/*.php` · fixtures `api/tests/DataFixtures/Fixtures/{User,Session}.yaml` |
| **[SEAM D1]** mint fuera del firewall | `StartSession::start($userId,$orgId,$device,?$ip): SessionId` + `SessionMintingSuccessListener` (`LoginSuccessEvent` −128, tras `migrate(0)`) — **reutiliza el wiring de II-4** | `api/src/Iam/Session/Application/StartSession.php` · `api/src/Iam/Identity/…/SessionMintingSuccessListener.php` |

**NET-NEW (crear en este slice):** `Iam/Identity/Domain/Entity/PasswordResetToken` + su repo/adapter/migración · `User::resetPassword()`
+ 2 eventos (`PasswordResetRequested`, `PasswordWasReset`/`PasswordResetCompleted`) · `RequestPasswordReset` +
`CompletePasswordReset` (Application) · `InvalidResetToken` (Exception) · 2 controladores + Origin listener + rutas +
`security.yaml` · seam deptrac `Identity→Session` · feature Behat.
**DIFERIDO (crear al rebasar sobre II-4):** session-mint wiring · CSRF stateless · `SecurityEmail` reset/changed · las
pantallas PWA. **`User.php`** = **una** función nueva (`resetPassword`) — el único roce.

### El crux: forgot uniforme sin filtrar existencia (SI-12 · NFR1 parcial)

La respuesta de forgot debe ser **idéntica** (status + forma) para {inexistente, `INVITED`, `ACTIVE`, `SUSPENDED`,
`DEACTIVATED`}. **Solo `ACTIVE`** hace trabajo real (mint + persist + evento + email-seam); el resto hace **return silencioso**.
La clave de por qué esto **no** delata: el trabajo interno (fila de token, evento en outbox, email) **no es observable** por
el peticionario anónimo — solo ve el 202 uniforme. El evento `PasswordResetRequested` **solo** existe para `ACTIVE`, pero su
presencia/ausencia no viaja en la respuesta → **no re-enumera** (NFR10 coherente con NFR1). **Límite honesto (documentar, no
ingeniar contra ello aquí):** `ACTIVE` hace writes (INSERT token + outbox), inexistente hace un read → **diferencial de
timing** correlado con la existencia. **II-8 lo cierra** con un suelo constant-time transversal (login/forgot/reset/INVITED);
II-5 garantiza **status + forma**, **no timing** (ver `deferred-work.md` línea ~108, mismo patrón que el lockout). No mitigar
con async (rompe read-your-writes; la consulta 3-lentes de II-6 ya lo descartó).

### El crux: single-use opaco + retire-then-act (SI-13 · contrato diferido de II-2)

`SingleUseToken::verify()` **no** fuerza el single-use — devuelve `true` repetidamente hasta el TTL (el single-use es
**lifecycle del consumidor**). **Contrato de II-5:** consumir = **`$tokens->remove($token)` (borrado físico) en la MISMA
transacción** que `resetPassword` + `clearLockout`. Los **3** caminos de muerte {usado (fila borrada), caducado
(`verify`→false), inexistente (lookup→null)} lanzan **la misma** `InvalidResetToken` con el **mismo** mensaje — no ramificar
el motivo en ninguna capa (mensaje, código, log). El terse «Este enlace ya no es válido» es aceptable **porque** el muro
`invalid-link` **siempre** ofrece salida («Iniciar sesión», D-a, superficie de II-4). **Higiene de URL (no-referrer, strip
`history.replaceState`, redacción en logs) = II-8**; aquí solo: token **nunca renderizado** (PWA diferido) y **nunca
persistido crudo**.

### El crux: no-`ACTIVE` con token válido = post-identidad (D-c · SI-14)

Un token de reset **válido** prueba control del email → **graduación de confianza a post-identidad** (SI-14). Por eso, a
diferencia de un token **muerto** (`invalid-token` opaco, pre-identidad), un token **vivo** sobre una identidad `SUSPENDED`
devuelve `account-suspended` **específico** (403) y `DEACTIVATED` genérico — **observable por diseño**, coherente con los
muros de login de II-3. Esto **no** rompe la opacidad: la opacidad protege la muerte del token (indistinguible), no el estado
de una identidad ya probada. El escenario es real: la cuenta se suspende/desactiva **entre** el request y el complete
(acción admin). Ninguna sesión ni cambio de password ocurre en ese camino.

### El crux: session-mint post-reset (NFR3 · SEAM DIFERIDO a II-4)

El único punto propio de II-5 sobre el mint es la **regeneración de id (NFR3)** — y **se difiere**: II-4 construye el patrón
`Security::login('main')` → `LoginSuccessEvent` → `SessionMintingSuccessListener` (mint) + `migrate(true)` nativo
(anti-fixation). II-5 **reutiliza ese wiring** tras el rebase, **no** lo construye en paralelo (evita duplicar el TCB de
sesión y colisionar en `security.yaml`/`framework.yaml` con II-4). **En el slice:** el reset **revoca-todas** y responde
**204 sin cookie** — el usuario entra por login normal con la password recién fijada. La no-atomicidad residual (reset commit
+ sin auto-login) es un estado **benigno recuperable** (idéntico al «usuario `ACTIVE` sin sesión» de II-4), **no** un defecto.
Marcar el seam en `CompletePasswordResetController`.

### Persistencia (Decisión A — presentar a Sergio, regla dura de CLAUDE.md)

**Regla dura (CLAUDE.md · per-aggregate persistence):** antes de modelar un agregado nuevo se **presenta la decisión de
estrategia de persistencia**. Para el token de reset:

- **¿State-oriented o event-sourcing?** → **State-oriented, sin duda.** El token de reset es **dato de referencia efímero**
  (existe entre request y complete/TTL), no un ledger cuya historia sea el negocio. La *auditabilidad* (NFR10) la dan los
  **eventos** (`PasswordResetRequested`/`Completed`) por el outbox, **no** event-sourcing del token. Espeja la decisión
  ratificada de `Invitation` (ADR D5, state-oriented). **No re-abrir.**
- **¿Agregado propio `PasswordResetToken` o columnas en `User`?** = **la decisión de modelado abierta (Decisión A).**
  **Recomendado: agregado propio `PasswordResetToken`** en `Iam/Identity`. **Principio:** SRP — la identidad se queda sobre
  identidad + credenciales + lockout, no sobre bookkeeping de tokens de recuperación; espeja `Invitation` (simetría de los
  dos flujos por token). **Objetivo que compra:** minimiza el diff de `User.php` a **una sola** función (`resetPassword`) →
  **rebase trivial con II-4** (columnas nuevas en `identity_user` ampliarían el roce a la tabla + entidad que II-4 toca);
  tabla nueva `identity_password_reset_token` = cero colisión de migración con II-4. **Coste / alternativa descartada:**
  columnas `password_reset_token_hash`/`_expires_at` en `User` darían atomicidad de **un solo agregado** (retire-then-act
  trivial), pero (a) cargan estado transitorio en cada fila de identidad (tensión SRP), (b) el forgot mutaría `User` incluso
  en el camino uniforme, y (c) **amplían el roce con II-4** sobre `User`/`identity_user`. El agregado propio gana por
  rebase-safety + SRP; el coste (una tabla + un repo + 2 TX por frontera de agregado) es el patrón que II-4 ya establece.
- **Consumo = borrado físico (retire), sin enum de estado.** `verify()` cubre la caducidad; el «superseder al re-pedir» =
  `deleteAllForUser` antes de emitir el nuevo; reusar un token consumido → fila ausente → `invalid-token`. **Hard delete es
  el default** (CLAUDE.md). *(Alternativa: espejar el status-machine de `Invitation` `PENDING→CONSUMED|EXPIRED` para
  simetría/auditabilidad — descartada por YAGNI: el token de reset no tiene consumidores downstream de su lifecycle, el
  evento ya audita.)*

### Nomenclatura de eventos (evitar duplicación)

Dos hechos a modelar: **(a)** «se solicitó un reset» → `PasswordResetRequested` (emitido por `RequestPasswordReset`);
**(b)** «la password se cambió» → **elegir UN nombre**: `PasswordWasReset` (grabado por el agregado `User` en `resetPassword`,
estilo `UserSuspended`/`UserLocked` de II-3/II-6) **o** `PasswordResetCompleted` (emitido por el caso de uso, estilo de la
lista NFR10 del épico). **Recomendado:** el evento del hecho de dominio lo graba el **agregado** (`PasswordWasReset`, mirror
`User` de II-3), y **no** duplicar con un `PasswordResetCompleted` de aplicación — usar el nombre del épico
(`PasswordResetCompleted`) como el evento del agregado si se prefiere alinear con NFR10. **No emitir ambos.** Confirmar en
Decisión B. Payload PII-free (`userId`). **2 eventos → sin trait** (Regla-de-Tres por módulo).

### Ficheros a tocar (estado actual verificado)

**API — nuevos bajo `Iam/Identity/`:** `Domain/{Entity/PasswordResetToken, Event/{PasswordResetRequested,
PasswordWasReset|PasswordResetCompleted}, Exception/InvalidResetToken, Repository/PasswordResetTokenRepository}`,
`Application/{RequestPasswordReset, CompletePasswordReset}`, `Infrastructure/{Http/{RequestPasswordResetController,
CompletePasswordResetController, PasswordResetOriginListener}, Persistence/Doctrine/DoctrinePasswordResetTokenRepository}`.

**API — modificar:** `Domain/Entity/User.php` (**+`resetPassword()`, único roce con II-4**) · `config/packages/security.yaml`
(access_control reset `PUBLIC_ACCESS`) · `config/routes.yaml` (resource block reset) · `api/tools/deptrac/deptrac.yaml` +
`api/.bounded-context-allowlist` (seam per-file `Identity→Session` `RevokeAllSessions`) · `docs/api-error-contract.md`
(`invalid-token` lo realiza también el reset) · migración nueva · fixtures (un `User` `ACTIVE` + un `PasswordResetToken`
`SENT` con token conocido, para Behat/unit) · `PRODUCTION_SECURITY_CHECKLIST.md` (cambio security-sensitive: nuevo flujo
de recuperación de credenciales).

**DIFERIDO — modificar al rebasar sobre II-4:** `config/packages/framework.yaml` (csrf reset token id) · el controlador de
reset (204 → mint sesión) · plantilla `SecurityEmail` · PWA (`TokenActionScreen`/`SecuritySignal`/copy/port/adapter).

### Testing (patrones del repo — II-3/II-6/II-4 son los precedentes frescos)

- **Unit domain** (`api/tests/Unit/Iam/Identity/…`): `PasswordResetToken` (issue + rehidrata `SingleUseToken` + expiry);
  `User::resetPassword` (camino feliz sobre `ACTIVE`; guard sobre no-`ACTIVE`; graba el evento; **no** confundir con
  `activate()`); `InvalidResetToken` (`type()='invalid-token'`, 400, `implements InvalidInput`). `@internal` +
  `#[CoversClass]` **estricto por clase** (SonarCloud `new_coverage ≥ 80%` es gate real — II-1 falló a 78.8%). Reusar
  `UserMother` (`create()` = ACTIVE, hay que añadir un helper para `locked`/`suspended` si no existe). Constant-time se
  prueba en II-2, **no** re-testar timing (flaky, banned). Presupuestos PHPMD: `TooManyPublicMethods ≤ 10` (los
  `DataProvider` estáticos cuentan), `CouplingBetweenObjects ≤ 13` (aplica a tests → stubs a un trait si se dispara).
- **Unit application:** `RequestPasswordReset` (uniforme: `ACTIVE` → mint+persist+evento; inexistente/`INVITED`/`SUSPENDED`/
  `DEACTIVATED` → **sin** token/evento; supersede borra el previo); `CompletePasswordReset` (los 3 tokens muertos → misma
  `InvalidResetToken` **sin** mutar; camino feliz → `resetPassword`+`clearLockout`+remove(token)+`RevokeAllSessions`+eventos;
  D-c → muro sin mutar). Fakes in-memory de puertos (`InMemoryUserRepository` existe; **crear** `InMemoryPasswordResetTokenRepository`,
  `RecordingEventBus` existe, `InlineTransactionManager`/`FixedClock` existen; **fake/spy de `RevokeAllSessions`** —
  inyectar la clase de aplicación, verificar que se llamó con el `userId`).
- **Behat** (`api/features/backoffice/identity/password_reset.feature`, escenarios `@anonymous`): forgot existente vs
  inexistente **idénticos**; reset válido → 204 + password cambiada + 0 sesiones activas + lock limpio + **1**
  `AllSessionsRevoked` + **1** `PasswordResetCompleted`; **3 tokens muertos idénticos** (AC6); token válido + `SUSPENDED` →
  `account-suspended` (AC5); reset fallido → **0 eventos** (vaciar outbox + reset stats **antes**). Seed del
  `PasswordResetToken` con hash de plaintext conocido (Alice o SQL inline **quote-free**; el matcher trunca en `"`).
  **+2 queries por escritura envuelta.** Worktree fresco → `make php.behat.install` antes de los gates.
- **Functional** (`api/tests/Functional/Iam/Identity/`): `DoctrinePasswordResetTokenRepository` sobre Postgres real
  (`findById`, `save`, `remove`, `deleteAllForUser`).
- **E2E vivo:** el reset necesita un token vivo (como los muros de II-3/II-4, **probablemente sin tooling live-stack para
  mintearlo**) → cubrir por **Behat API + unit**, no e2e vivo. La superficie PWA es **diferida** de todas formas.

### Gotchas heredados (verificados en ii-0..7 + spec II-4)

- `make php.behat.install` en worktree fresco antes de gates · `php.stan` exit 139 → `PHP_SERVICE=messenger_worker` ·
  `make php.quality` es el **único** sweep de PHPMD/cs-fixer (puede OOM 137) · **Rector gana** (nunca `@psalm-suppress`,
  nunca `// NOSONAR`; nunca `/** @var */` sin nombre sobre `return` en tests → `@phpstan-var`) · migración editable en esta
  rama, inmutable tras merge · un `NOT NULL` crudo en migración sobre filas existentes brickea el boot (tabla nueva → n/a) ·
  `AllSessionsRevoked` se emite **aunque afecte 0 filas** (`deferred-work.md` línea 5 — inerte hoy; **el reset no cabla un
  reactor** de ese evento, así que sigue inerte; el fix rowcount>0 es del cableado del consumidor, **no** de este slice) ·
  el `Email` de `findByEmail` es la **VO de dominio** `Erpify\Iam\Identity\Domain\Email`, no un string · barrer del diff
  final los IDs de story/NFR/AC y comentarios change-relative en `api/src` (permitidos aquí en el spec, **prohibidos** en
  código merged) · verificar **fresco** sobre el path del worktree, confiar en el exit code recién impreso.

### Decisiones a confirmar al inicio del dev (riesgo medio — recomendaciones flagged)

- **Decisión A — modelado + persistencia + localización del token de reset (regla dura CLAUDE.md).** Recomendado: agregado
  propio **`PasswordResetToken`** en `Iam/Identity`, **state-oriented**, consumo por **borrado** (retire-then-act, sin enum de
  estado), y **localización selector-verifier** (`?token=<id>.<secret>`; `findById` + `verify`, **sin editar `Shared/Token`**).
  Alternativas: (a2) columnas `password_reset_*` en `User` (atomicidad 1-agregado pero **amplía el roce con II-4** + tensión
  SRP); (a3) mirror del status-machine de `Invitation` (más código, YAGNI); (a4) lookup-por-hash → exigiría exponer un
  `digest` público en `SingleUseToken` = **editar `Shared/Token`** en paralelo a II-4 (descartado: rompe «`User.php` es el
  único roce»). Ver «Persistencia (Decisión A)» y T1.
- **Decisión B — guard de `resetPassword` + orquestación D-b + nombre del evento.** Recomendado: el **caso de uso** intercepta
  no-`ACTIVE` → muro (D-c) **antes** de tocar el token; `User::resetPassword` guarda `ACTIVE` defensivamente; el caso de uso
  llama `resetPassword()` **y** `clearLockout()` explícitos; **un solo** evento de cambio de credencial
  (`PasswordWasReset` de agregado **o** `PasswordResetCompleted` del épico — no ambos). Sub-punto: en el camino D-c
  (token válido + no-`ACTIVE`), **¿consumir el token o dejarlo vivo?** Recomendado dejarlo vivo (no se concedió nada; podría
  servir si la cuenta se re-activa dentro del TTL) — confirmar.
- **Decisión C — status de `invalid-token`.** Recomendado **400 `InvalidInput`** (`type()='invalid-token'`, precedente
  `InvalidUuidException`; **alinea con la Decisión B ratificada de II-4**). **Requisito duro:** status **uniforme** en los 3
  casos (opacidad). **Sin** marker interface nuevo. En el rebase, **reconciliar el doc** con la realización de II-4 (mismo
  wire type, 2 clases por aislamiento de contexto).
- **Decisión D — paths + códigos de respuesta.** Recomendado `POST /backoffice/forgot-password` (**202** uniforme) +
  `POST /backoffice/reset-password` (**204** sin cookie en el slice / 400 `invalid-token` / 403 muro / 422 validación).
  Confirmar 202-vs-204 para forgot y los paths exactos (mirror del prefijo del login).
- **Decisión E — límite del slice (RATIFICADO por Sergio, no re-abrir sin motivo).** Backend = dominio + app + persistencia +
  HTTP(Origin-only) + tests; **DIFERIDO** = session-mint+NFR3 (D1, reusa II-4 `Security::login`), CSRF stateless (D2, reusa
  II-4 `framework.csrf_protection`), `SecurityEmail` (D3, reusa plantilla II-4), PWA (D3). El backend **revoca-todas** y
  responde **204 sin cookie**; el auto-login se injerta al rebasar. Esta es la costura que evita construir en paralelo lo que
  II-4 ya trae.

### Fuera de alcance (frontera explícita — no lo hagas en II-5)

- **Todo lo DIFERIDO (D1–D3)** hasta rebasar sobre II-4 (session-mint, CSRF stateless, `SecurityEmail`, PWA). Es reutilización
  de superficie de II-4, no construcción paralela.
- **Endurecimiento transversal** (constant-time timing en forgot/reset, `Referrer-Policy: no-referrer`, strip de URL
  `history.replaceState`, redacción del token en logs, rate-limit login/forgot/reset, escape del email async, remitente
  no-`no-reply` formalizado) → **II-8**. II-5 garantiza status+forma uniformes, **no** timing.
- **Cambiar contraseña desde «Mi cuenta»** (J6, confluencia Session+Authentication+Identity, con sesión de confianza y
  «cerrar las demás») → **extension point**, no II-5 (II-5 es forgot/reset **sin** sesión de confianza → revoca **todas**).
- **Fix del `AllSessionsRevoked` sobre 0 filas** (`deferred-work.md` línea 5) → al cablear un consumidor real del evento
  (II-5 no lo cabla).
- **UI de gestión de sesiones/miembros · magic-link/MFA/SSO · tenancy operativa** → diferidos (otro slice / ADR de tenancy).

### Project Structure Notes

- `Iam/Identity` ya está **registrado** en `deptrac.yaml` → **sin** capas nuevas; II-5 solo puebla namespaces existentes.
  **Excepción:** el seam `Iam/Identity → Iam/Session\Application\RevokeAllSessions` es entrada **per-file** en
  `.bounded-context-allowlist` + deptrac `skip_violations` (nunca forma global — `DeptracSeamSyncGateTest`). `Shared/Token`
  **no** necesita seam (shared kernel, siempre importable).
- `routes.yaml` usa resources **estrechos** por módulo; `Iam/Identity/Infrastructure/Http/` ya tiene el bloque del login →
  **añadir las acciones de reset al bloque existente** o un bloque hermano, no duplicar.
- Migración en `api/migrations/2026/` vía `db.diff` (editable en esta rama; inmutable tras merge).
- **No PWA en este slice** (la superficie de reset es diferida a post-II-4).

### References

- [Source: `docs/adr/identity-invitation-lifecycle.md` D9/D10/D11/D12] — reset uniforme (revoca-todas, limpia lock);
  indistinguibilidad pre-identidad (timing = II-8); opacidad del token; contrato de error graduado por confianza.
- [Source: `_bmad-output/planning-artifacts/arch-addendum-identity-invitation.md` SI-12/13/14, NFR3, PR-5 (líneas 32/46)].
- [Source: `_bmad-output/planning-artifacts/epics-identity-invitation-lifecycle.md` Story II-5 (líneas 801-845); FR6/FR7
  (D-b); D9/D10; DAG (línea 46-47, PR-5 depende de PR-2/PR-3/PR-7); NFR10 (lista de eventos, `PasswordResetRequested`/
  `PasswordResetCompleted`); NFR9 (idempotencia)].
- [Source: `_bmad-output/implementation-artifacts/ii-4-invitation-accept-pantallas-acceso.md`] — story hermana: retire-then-act
  atómico, session-mint A1 (`Security::login`), CSRF stateless, opacidad `invalid-token`, los 6 componentes (reutilizables).
- [Source: `_bmad-output/implementation-artifacts/deferred-work.md`] — línea 5 (`AllSessionsRevoked` 0-filas, nombra II-5) ·
  línea 92 (retire-then-act single-use, contrato II-4/II-5) · línea 108 (timing pre-identidad del write → II-8).
- Precedentes de código: `Iam/Session/Application/RevokeAllSessions.php` (revoca-todas + evento en TX) ·
  `Iam/Identity/Application/{LoginAttemptRegistrar,ChangeUserStatus}.php` (transactional + EventBus + skip-si-no-cambia) ·
  `Iam/Identity/Domain/Entity/User.php` (`activate`/`clearLockout`/`suspend` — patrón de mutador + evento + guard) ·
  `Iam/Identity/Domain/Exception/AccountSuspended.php` (`type()` override) · `Shared/Token/Domain/SingleUseToken.php` ·
  `Shared/Uuid/Domain/InvalidUuidException.php` (`InvalidInput` + `type()`, 400) · `ii-4-…md` (formato + patrón de decisiones).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Opus 4.8, 1M context).

### Debug Log References

- Gates verdes sobre el worktree `iam-ii5-reset-password-pfs0`: `php.stan` OK · `php.unit` **1811** (3 skip) EXIT 0 · `php.behat` **274** esc./**2529** pasos (feature nueva `password_reset.feature` = 8 esc.) · `php.quality` **EXIT 0** (deptrac 0 viol/81 skip, PHPMD 0, PHPCS 0, gherkin 0, cs-fixer/rector idempotentes) · `php.lint.error-contract` 0 · `php.lint.bounded-context` 0 · `php.psalm.taint` No errors.
- OOM intermitente de `BankStoredObjectMultipartFunctionalTest` (FrankenPHP web-worker 128M) = flake ajeno; re-run limpio.
- `reference.php` revertido a `origin/main` (drift de translator/`_instanceof` ajeno).

### Completion Notes List

- **Decisiones A–E ratificadas por Sergio (AskUserQuestion) e implementadas tal cual:** A = agregado propio **`PasswordResetToken`** state-oriented, consumo por borrado (retire-then-act), localización **selector-verifier `<id>.<secret>`** (`findById`+`verify`, sin tocar `Shared/Token`); B = evento único **`PasswordResetCompleted`** grabado por el agregado `User`; C = **400 `InvalidResetToken implements InvalidInput`** (`type()='invalid-token'`); D = `POST /api/v1/backoffice/forgot-password` **202** + `POST /api/v1/backoffice/reset-password` **204** (mirror del prefijo del login, sin bloque `routes.yaml` nuevo — el `resource` estrecho de Http ya los escanea); E = límite del slice (backend Origin-only; PWA/email/session-mint/CSRF DIFERIDOS a post-II-4).
- **Slice completo (AC1–AC10):** `RequestPasswordReset` (forgot uniforme SI-12: solo `ACTIVE` acuña token + `PasswordResetRequested` + supersede; resto return silencioso); `CompletePasswordReset` (retire-then-act atómico en 1 TX: `resetPassword`+`clearLockout`+`remove(token)`+publish; `RevokeAllSessions` tras el commit; muro D-c sin consumir el token). `User::resetPassword()` = único roce con II-4 (una función + su evento). Migración `Version20260713140053` (`identity_password_reset_token`, sin FK, índice `user_id`, sin PII).
- **Desviaciones argumentadas del literal de la story (folded, más limpias):** (1) **hashing en el controlador** (Infra), el caso de uso recibe `HashedPassword` ya opaco — mirror de `CreateUser`; mantiene Application libre del `PasswordHasher` de Infra (DIP) y da coste de hash uniforme valid/dead-token. (2) **`findById` malformado → null en el adapter Doctrine** (`Uuid::isValid` guard): un selector no-UUID es «fila ausente» (concern del repositorio), evita un 500 por cast uuid y baja el acople del caso de uso; documentado en el puerto. (3) **`User::isActive()`** nuevo predicado de dominio (usado por ambos casos de uso; más expresivo que `IdentityStatus::ACTIVE === status()`). Las tres bajan el acople PHPMD de los casos de uso a ≤12 sin `@SuppressWarnings`.
- **Seam cross-contexto** `Iam/Identity → Iam/Session\Application\RevokeAllSessions`: entrada **per-file** en `.bounded-context-allowlist` + deptrac `skip_violations` (nunca forma global). `Shared/Token` no necesita seam.
- **Eventos wire-on-consumer:** `PasswordResetRequested`/`PasswordResetCompleted` (hyphen-style `all-revoked`, aggregateType `Iam.Identity`, payload PII-free) van al `event_store` vía `EventBus`, sin routing a transporte (sin reactor). Auto-descubiertos por el mapper (sin registro manual).
- **Cobertura:** tests unit por-evento (`fromPrimitives`), `PasswordResetOriginListener` unit (mirror `LoginOriginListener`), adapter functional (incl. selector malformado). Controladores finos sin unit test (precedente `LoginController`).
- **DIFERIDO (contratos AC D1–D3, tras rebasar sobre II-4):** session-mint + regeneración NFR3 (204-sin-cookie hoy), CSRF stateless (Origin es el control primario), PWA + `SecurityEmail`. Seams marcados en `RequestPasswordReset` (envío de email) y `CompletePasswordResetController` (mint). **VIGILAR EN REBASE:** `User.php` (II-4 llama `activate()`, II-5 añade `resetPassword()`+`isActive()`).
- **Política de password del reset DTO** = `min:8/max:255` inline (no había policy compartida en `main`); reconciliar con la del accept de II-4 al rebasar.

### File List

**API — nuevos (`Iam/Identity/`):** `Domain/Entity/PasswordResetToken.php` · `Domain/Event/{PasswordResetRequested,PasswordResetCompleted}.php` · `Domain/Exception/InvalidResetToken.php` · `Domain/Repository/PasswordResetTokenRepository.php` · `Application/{RequestPasswordReset,CompletePasswordReset}.php` · `Infrastructure/Http/{RequestPasswordResetController,CompletePasswordResetController,ForgotPasswordRequest,ResetPasswordRequest,PasswordResetOriginListener}.php` · `Infrastructure/Persistence/Doctrine/DoctrinePasswordResetTokenRepository.php`.

**API — nuevos (otros):** `api/migrations/2026/Version20260713140053.php` · `api/features/backoffice/identity/password_reset.feature`.

**API — modificados:** `src/Iam/Identity/Domain/Entity/User.php` (+`resetPassword()` +`isActive()`) · `config/packages/security.yaml` (2 rutas `PUBLIC_ACCESS`) · `config/routes.yaml` (comentario del resource) · `tools/deptrac/deptrac.yaml` + `.bounded-context-allowlist` (seam `Identity→Session`) · `docs/api-error-contract.md` (`invalid-token` realizado por el reset).

**Tests — nuevos:** `tests/Unit/Iam/Identity/Application/{RequestPasswordResetTest,CompletePasswordResetTest,InMemoryPasswordResetTokenRepository}.php` · `tests/Unit/Iam/Identity/Domain/Entity/{PasswordResetTokenTest,UserResetPasswordTest}.php` · `tests/Unit/Iam/Identity/Domain/Event/{PasswordResetRequestedTest,PasswordResetCompletedTest}.php` · `tests/Unit/Iam/Identity/Domain/Exception/InvalidResetTokenTest.php` · `tests/Unit/Iam/Identity/Infrastructure/Http/PasswordResetOriginListenerTest.php` · `tests/Functional/Iam/Identity/DoctrinePasswordResetTokenRepositoryTest.php`.

### Change Log

| Fecha       | Cambio |
|-------------|--------|
| 2026-07-13  | Story II-5 creada (ready-for-dev): slice **backend paralelo** acotado (DENTRO vs DIFERIDO) para no pisar a II-4. Análisis exhaustivo de 4 artefactos (épico/addendum/ADR · story hermana II-4 · código API `main` vía 3 subagentes: `Shared/Token`, `Iam/Identity` status+lockout+password, `Iam/Session` revoke-all + EventBus + Behat). |
| 2026-07-13  | Slice backend implementado (Status → review). Decisiones A–E ratificadas. `PasswordResetToken` + repo/adapter/migración · `User::resetPassword()`/`isActive()` + 2 eventos · `RequestPasswordReset`/`CompletePasswordReset` · `InvalidResetToken` · 2 controladores + DTOs + `PasswordResetOriginListener` · seam deptrac `Identity→Session` · `api-error-contract.md`. Feature Behat `password_reset.feature` (8 esc.) + unit/functional. Desviaciones argumentadas: hash en controlador (DIP), `findById` malformado→null en el adapter, `User::isActive()`. Todos los gates PHP verdes; PWA/email/session-mint/CSRF diferidos a post-II-4. |

### Review Findings

_Code review 2026-07-13 (Blind Hunter · Edge Case Hunter · Acceptance Auditor, Opus 4.8). 2 decision-needed, 2 patch, 4 defer, 5 dismissed as by-design/noise._

**Resolución (2026-07-13, todos los gates verdes: php.stan · php.unit 1813 · php.quality EXIT 0 · error-contract · bounded-context · deptrac seam-sync · Behat 8/8 · psalm.taint):**
- **D1 APLICADO** (guard mínimo verificable): `PasswordResetTokenRepository::consume(token): bool` (DELETE condicional; el conteo de filas afectadas ES el guard single-use). `CompletePasswordReset` aborta con `InvalidResetToken` si `consume` borra 0 filas → dos `complete` concurrentes ya no doblan el efecto (cierra SI-13/AC6). Verificado en el functional (2.º consume → false) y en Behat (reuso del token → 400).
- **D2 APLICADO** (tragar+loggear, 204): extraído `RevokeSessionsBestEffort` (Iam/Identity Application) que envuelve `RevokeAllSessions`, traga cualquier `\Throwable` de la revocación post-commit y loggea `warning`. `CompletePasswordReset` delega en él → un store-outage ya no falla un reset commiteado. **Desviación argumentada:** se extrajo un colaborador en vez de un try/catch inline porque el caso de uso ya estaba en el techo de acoplamiento PHPMD (CBO 12) y el logger+catch lo rompían a 14; el colaborador lo devuelve a 12 sin suprimir, mantiene la política en Application, y DRYa hacia el futuro flujo J6 (cambiar password desde «Mi cuenta»). El seam deptrac/allowlist `Identity→Session` se movió a `RevokeSessionsBestEffort`.
- **P (patches) APLICADOS:** checklist de seguridad corregido; `PasswordResetToken::issue()` con `Uuid::ensure($id)` simétrico.
- **Diferidos a II-8/follow-up:** forgot-supersede + TOCTOU (concurrencia; ver defer abajo), timing/enumeración, rate-limit per-target, higiene TTL/GDPR del token, endurecer asserts AC6.

**Decision-needed:**

- [ ] [Review][Decision] Concurrencia & carreras mid-flight en el flujo de reset — el `resolve()` (findById+verify) y el guard de estado corren FUERA de la transacción, y el consumo del token es un `remove()`+`flush()` sin guard de filas afectadas (sin `#[ORM\Version]`, sin `SELECT ... FOR UPDATE`, sin unique en `user_id`). Bajo READ COMMITTED: (a) dos `complete` concurrentes del MISMO token válido tienen éxito ambos → single-use roto, `PasswordResetCompleted`+`AllSessionsRevoked` duplicados [`api/src/Iam/Identity/Application/CompletePasswordReset.php:60`, adapter `.../DoctrinePasswordResetTokenRepository.php:34`]; (b) dos `forgot` concurrentes dejan 2 tokens vivos (supersede no atómico) [`api/src/Iam/Identity/Application/RequestPasswordReset.php:71`]; (c) TOCTOU: un admin que suspende ENTRE el load y el commit deja completar el reset sobre una identidad ya no-`ACTIVE` [`CompletePasswordReset.php:63`]. Impacto acotado (el portador ya es legítimo; una cuenta walled no puede usar la credencial nueva; eventos de auditoría duplicados), pero rompe el invariante SI-13 (single-use atómico) que el propio docblock afirma. **Severidad: media.** Opciones: (1) endurecer ahora — lock de fila (`FOR UPDATE`) del token+user dentro de la TX y/o assert de 1 fila borrada + unique en `user_id`; (2) aceptar el impacto acotado y diferir a II-8; (3) solo el guard mínimo del delete del token (assert affected-rows) sin tocar la carrera de forgot. **→ RESUELTO (Sergio, 2026-07-13): tras descubrir que FOR UPDATE sería el PRIMER pessimistic-lock del repo (~13 ficheros) y NO verificable por el harness (fakes single-threaded; ni PHPUnit ni Behat ejercitan concurrencia real), se optó por el guard mínimo verificable — `PasswordResetTokenRepository::consume(token): bool` = DELETE condicional cuyo conteo de filas afectadas ES el guard single-use (dos `complete` concurrentes serializan en el row-lock del DELETE; el loser borra 0 filas → `InvalidResetToken`, aborta antes de mutar). Cierra el double-complete (SI-13/AC6). APLICADO. Forgot-race + TOCTOU → DIFERIDOS a II-8 (ver defer abajo).**
- [ ] [Review][Decision] Un fallo de `revokeAllSessions` post-commit hace fallar un reset ya exitoso y consume el token — `revokeAllSessions->revoke()` está fuera de la TX y sin `try/catch` [`api/src/Iam/Identity/Application/CompletePasswordReset.php:76`]; abre su propia transacción [`api/src/Iam/Session/Application/RevokeAllSessions.php:33`]. Si el store de sesiones cae, lanza → el controlador devuelve 5xx AUNQUE la password ya cambió y el token ya se borró, contradiciendo el propio docblock ("eager defence-in-depth, never the only barrier — a store outage cannot leave a stale session live": el cambio de credencial ya de-autentica vía `refreshUser` nativo). El usuario recupera entrando por login normal, pero ve un 5xx falso sobre una mutación exitosa. **Severidad: media.** Opciones: (1) tragar+loggear el fallo del revoke y devolver 204 igual (honra el contrato documentado) — recomendado; (2) dejarlo tal cual (fail-loud); (3) diferir a II-8. **→ RESUELTO (Sergio, 2026-07-13): Opción 1 — try/catch alrededor del revoke post-commit, loggear el fallo, devolver 204. Reclasificado a patch.**

**Patch:**

- [x] [Review][Patch] APLICADO — `PRODUCTION_SECURITY_CHECKLIST.md` sin actualizar y ahora factualmente falso [PRODUCTION_SECURITY_CHECKLIST.md:214] — el fichero no se tocó (git status vacío); la línea 214 sigue diciendo "No HTTP surface yet (consumed by the invitation/reset flows)", que II-5 vuelve falso al añadir `/api/v1/backoffice/{forgot,reset}-password`; el "Access-control baseline" (l.226) no lista las 2 rutas `PUBLIC_ACCESS` nuevas ni el `PasswordResetOriginListener`. El spec lo lista en "Ficheros a tocar" y CLAUDE.md lo exige para cambios security-sensitive. **Severidad: media.**
- [x] [Review][Patch] APLICADO — `PasswordResetToken::issue()` valida `userId` pero no su propio `id` [api/src/Iam/Identity/Domain/Entity/PasswordResetToken.php:53] — el constructor hace `Uuid::ensure($userId)` pero asigna `$this->id = $id` sin `Uuid::ensure($id)`. Hoy seguro (único caller pasa `Uuid::generate()`), pero es una factory pública con invariante asimétrico; un `id` malformado solo fallaría tarde en el cast uuid de la DB. Simetría barata, fail-fast. **Severidad: baja.**

**Defer (diferidos):**

- [x] [Review][Defer] Side-channel de timing en forgot → II-8 [api/src/Iam/Identity/Application/RequestPasswordReset.php:56] — el 202 es uniforme en status+forma, pero el camino `ACTIVE` (mint+2 writes+outbox, y el KDF completo en el controlador del reset para un token muerto) es medibledemente más lento que el read silencioso → oráculo de enumeración por latencia. Accepted-risk documentado; el spec asigna el suelo constant-time y el rate-limit a **II-8**. Diferido, owned by II-8.
- [x] [Review][Defer] Higiene de ciclo de vida del token de reset [api/migrations/2026/Version20260713140053.php] — sin barrido TTL (filas expiradas-no-usadas crecen sin cota) y sin hook de erase-subject (sin FK cascade → filas huérfanas tras hard-delete GDPR del user; solo `deleteAllForUser` limpia, y solo si el user vuelve a pedir). Follow-up: sweep programado de `expires_at < now` + borrar tokens de reset en la erasure del sujeto. Diferido.
- [x] [Review][Defer] Rate-limiting de recuperación (per-target) → II-8 [api/config/packages/security.yaml:43] — solo el limitador global 120/min-por-IP `anonymous_api`; sin throttle per-email. Una vez cableado el email: email-bombing + supersesión del token legítimo de la víctima vía `deleteAllForUser`. El spec asigna rate-limit a **II-8**. Diferido, owned by II-8.
- [x] [Review][Defer] Endurecer tests: byte-identidad AC6 + efecto del supersede [api/features/backoffice/identity/password_reset.feature:76] — el escenario "4 tokens muertos = un invalid-token" solo asserta `status 400 + node type=invalid-token`, no compara los bodies cara a cara (el spec exige "byte-idéntica"); el unit de supersede prueba que se LLAMA `deleteAllForUser`, no que borra un token pre-existente ni el orden delete-before-save. El código es correcto hoy; los asserts no cierran el invariante. Diferido.
- [x] [Review][Defer] Concurrencia: forgot-supersede no atómico + TOCTOU del muro no-`ACTIVE` [api/src/Iam/Identity/Application/RequestPasswordReset.php:71, CompletePasswordReset.php:61] — de la Decisión 1; el double-complete se cerró con el guard `consume`, estas 2 (ambas benignas: 2 tokens del propio user / password sobre cuenta ya walled) se difieren al hardening de concurrencia de II-8 (evita el primer pessimistic-lock del repo con un mecanismo no verificable por el harness). Diferido, owned by II-8.
