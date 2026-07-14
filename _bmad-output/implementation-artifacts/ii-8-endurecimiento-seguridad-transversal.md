---
baseline_commit: 85243d70
---
# Story II-8: Endurecimiento de seguridad transversal (cross-cutting security hardening)

Status: review

<!-- Validación opcional. Ejecuta `bmad-create-story` validate para un chequeo de calidad antes de dev-story. -->

## Story

Como **responsable de seguridad**,
quiero **cerrar las garantías transversales (timing, higiene del token, email, rate-limit, concurrencia, retención) sobre las superficies de acceso ya existentes**,
para que **la indistinguibilidad pre-identidad y la opacidad del token sean honestas multicanal — no solo en el copy — y el flujo de recuperación quede completo, seguro y auditable de punta a punta**.

## Contexto (leer antes de tocar código)

Esta es **II-8 (PR-8)**, la **última** historia de la épica `identity-invitation-lifecycle`
(orden de merge safe-first `II-0 → II-1·II-2·II-3·II-7 → II-6 → II-4 → II-5 → II-8`). No es «solo emails»: es el
**barrido transversal** que endurece las cuatro superficies pre-identidad que las historias previas construyeron —
login (II-3), invitación/accept (II-4) y forgot/reset (II-5 + su cola D1–D3) — más el cierre de los diferidos de
concurrencia y de retención del token de reset.

**Fuente de verdad del diseño (ya ratificada por Sergio — no re-abrir):**
[`docs/adr/identity-invitation-lifecycle.md`](../../docs/adr/identity-invitation-lifecycle.md) **D10, D11** (contexto D9, D12) ·
[`_bmad-output/planning-artifacts/arch-addendum-identity-invitation.md`](../planning-artifacts/arch-addendum-identity-invitation.md) **SI-12, SI-13, PR-8** ·
[`_bmad-output/planning-artifacts/epics-identity-invitation-lifecycle.md`](../planning-artifacts/epics-identity-invitation-lifecycle.md) **Story II-8 (líneas 847-880), FR10, NFR1/NFR2/NFR10** ·
[`_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-07-06/{DESIGN.md,review-security.md}`](../planning-artifacts/ux-designs/ux-ERPify-2026-07-06/DESIGN.md) — **UX-DR6** (`SecurityEmail`) + los tres findings de seguridad (timing / higiene URL / rate-limit).

### Base = `main` @ `85243d70` (POST-#493) — lo que YA existe

II-8 se construye sobre `main` **después** de que #493 (`feat(iam): II-5 password reset D1-D3`, squash `85243d70`)
aterrizara los diferidos D1–D3 de II-5. Verificado en `main`:

- **Login (II-3):** `UserProvider::equaliseTiming()` **ya ecualiza el timing** del camino not-found (hash+verify de un
  dummy en ambas ramas: email malformado e inexistente) — **es la referencia** que el ADR D10 llama «existing
  timing-equalised `UserProvider`, elevated to contract». El `ProblemDetailsAuthenticationFailureHandler` ya colapsa
  todo fallo pre-identidad (password errónea / inexistente / `INVITED` re-envuelto) al **401 uniforme**, y un login
  saturado (`TooManyLoginAttemptsAuthenticationException`) **ya cae al mismo 401 neutral** (no lo mapea a un 429
  distinguible).
- **Invitación (II-4):** superficie completa — accept fuera del firewall (Origin + CSRF stateless + regeneración de
  sesión), email de invitación, 6 pantallas PWA.
- **Reset (II-5 + D1–D3, #493):** forgot/reset uniformes, `PasswordResetToken` (selector-verifier `<id>.<secret>`,
  consumo por `consume():bool` con guard de filas-afectadas), revoca-todas + limpia lock, **session-mint + CSRF
  stateless** (D1/D2, reusa el wiring de II-4), **email de reset** (`SymfonyPasswordResetEmailSender` +
  `SendPasswordResetEmailBestEffort`) y **pantallas PWA reset** (`/forgot-password`, `/reset-password`).
- **Surface de email COMPARTIDA (#493, clave para II-8):** ambos flujos delegan en el bloque compartido
  **`Shared/Mailer/Infrastructure/SecurityLinkMailer`** (scaffold bulletproof único: dark-mode, escape del link,
  CTA + fallback en texto plano) alimentado por el VO **`SecurityLinkEmailContent`** (solo copy + ruta por flujo).
  Los adapters (`SymfonyInvitationEmailSender`, `SymfonyPasswordResetEmailSender`) son de ~30 líneas.

### Lo que II-8 CIERRA (7 workstreams)

| # | Pilar | AC | Lado | Estado en `main` |
|---|-------|----|------|------------------|
| P1 | **Suelo constant-time transversal** (SI-12 · timing) | AC1 | api | Login not-found ✅; **`INVITED` y forgot con FUGA de timing** ❌ |
| P2 | **Higiene del token en URL** (SI-13) | AC2 | pwa + caddy | `no-referrer`, strip `history.replaceState`, redacción en logs = **todo ausente** ❌ |
| P3 | **`SecurityEmail` endurecido + email password-changed** (UX-DR6) | AC3, AC5 | api + config | Envío **síncrono**, remitente `no-reply`, base **http**, fuente **Arial**; email password-changed **inexistente** ❌ |
| P4 | **Rate-limit neutral** (D10) | AC4 | api + config | Login ✅ (cae al 401); **forgot/reset/accept sin límite per-target neutral** ❌ |
| P5 | **Concurrencia — FOR UPDATE** (diferido II-5) | AC6 | api | forgot-supersede + TOCTOU muro no-`ACTIVE` = **abiertos** ❌ |
| P6 | **Retención/GDPR del token de reset** (diferido II-5) | AC7 | api | Sin barrido TTL + sin erase-subject ❌ |
| P7 | **Cola D1–D3 de #493** («Absorb D1–D3») | AC5, AC8 | pwa | Copy muro `INVALID_LINK` de reset + E2E-live ❌ |

> **Decisiones de alcance RATIFICADAS por Sergio (AskUserQuestion, 2026-07-14):**
> **(1) Frontera** = «Absorb D1–D3 + harden» → II-8 endurece las superficies completas **y** cierra la cola de #493.
> **(2) Superficie de email** = «consulta Winston + Amelia» → **hecha** (ver Decisión E: ratifican **C-refinado**, no Twig).
> **(3) Prueba de constant-time** = «test **estructural/conductual**» (NO benchmark wall-clock — flaky/banned).
> **(4) Concurrencia + GDPR** = «**incluir ambos**» → II-8 introduce el **1.er pessimistic-lock del repo** (`FOR UPDATE`)
> **y** el barrido TTL + erase-subject.

## Acceptance Criteria

Redactados como **invariantes verificables** enganchados al ADR (D10/D11), los System Invariants (SI-12/13) y las
decisiones de alcance. Un refactor futuro no puede romper una garantía sin que un test la detecte.

1. **(SI-12 · suelo constant-time, AC1 del épico)** Los tres casos pre-identidad `{inexistente, password errónea,
   INVITED}` y el forgot-password realizan **el mismo trabajo criptográfico observable** — el hash dummy corre
   **siempre**, incluido el camino `INVITED` (sin password) y el forgot (exista o no la cuenta). **Prueba = test
   ESTRUCTURAL/conductual** (Decisión C): un test asserta que el trabajo de hash-ecualización se **invoca en cada
   rama** pre-identidad (verificando el colaborador de hashing, no midiendo latencia). **NO** se añade un benchmark
   wall-clock (flaky/banned; ver `ii-2`/`ii-4`/`ii-5`). El KDF del reset **no** corre antes del check barato del token
   (elimina la amplificación DoS del argon2id sobre tokens muertos).

2. **(SI-13 · higiene del token, AC2 del épico)** Cada pantalla por token (`/accept-invitation`, `/reset-password`):
   (a) se sirve con **`Referrer-Policy: no-referrer`** (override del global `strict-origin-when-cross-origin`); (b) el
   cliente **borra `?token=` de la URL** vía `history.replaceState` tras leerlo (fuera del historial y del `Referer`);
   (c) el token está **redactado** en los logs de acceso (Caddy) — un token nunca viaja por `Referer` a `/api`, a
   `/monitoring` ni al historial. Tests: header presente en la ruta (e2e/functional), el token desaparece de
   `window.location.search` tras el mount (unit PWA), y el filtro de Caddy redacta `token` (assert de config).

3. **(email endurecido, AC3 del épico · NFR2/NFR10)** Todo `SecurityEmail` es: **HTTPS-only** (el link se construye con
   esquema `https` garantizado; una base mal configurada **falla ruidosamente**, no emite un link `http`); **contenido
   dinámico escapado** (el único valor no confiable — el link/token — sigue escapado en el único punto de render);
   **remitente NO `no-reply`**; y **nunca** contiene secretos/PII más allá del enlace de un solo uso.
   **⚠️ «Async» reconciliado con SI-13 (Decisión H — CONFLICTO real):** el AC3 literal del épico pide «async vía
   Messenger», pero el **code-review de #493 (ratificado por Sergio) RECHAZÓ** rutear `SendEmailMessage` al transporte
   Doctrine porque Symfony **serializa el `Email` completo — con el token en claro `<id>.<secret>` en la URL — a la tabla
   del transporte + la cola `failed`**, violando SI-13 («el token nunca toca log ni transporte»). Por eso los emails
   **con token** (invitación, reset) se quedan **síncronos post-commit + best-effort** (`SendPasswordResetEmailBestEffort`
   ya lo hace: traga el fallo → el 202 no se vuelve 500 = **la neutralidad se logra por best-effort, NO por el
   transporte**). Solo el email **password-changed** (que **no lleva token**) puede ir **genuinamente async** (su reactor
   ya consume `PasswordResetCompleted` del transporte async; su idempotencia es un **claim `(eventId, handler)`** en el
   reactor). Ver Decisión H.

4. **(rate-limit neutral, AC4 del épico · D10)** Login/forgot/reset/accept saturados responden con el **mismo
   status/copy** que su fallo pre-identidad — la saturación **no es distinguible** ni **re-enumera**. En concreto: un
   forgot saturado sigue devolviendo el **202 uniforme** (no un 429 que delate un límite per-cuenta); un límite
   **per-cuenta** exhausto **no** cambia la forma de la respuesta (silencia el trabajo, no lo anuncia); el login ya cae
   al 401 neutral (no regresa). Un límite **per-IP** puede seguir devolviendo 429 `rate-limited` (IP-global, no delata
   existencia) **pero** las rutas pre-identidad de recuperación no lo usan como oráculo per-cuenta. Test: forgot
   existente-`ACTIVE` vs inexistente **bajo saturación** → respuesta idéntica.

5. **(`SecurityEmail` template + email password-changed, AC5/UX-DR6)** El scaffold `SecurityLinkMailer` cumple el
   contrato bancario (una frase de propósito · **un** enlace bulletproof, estilos inline, área táctil grande · pie
   legal mínimo · **pila de sistema** — no Arial · **dark-mode-aware** · `lang="es"` · fallback de URL en texto plano).
   Se añade un email **«tu contraseña ha cambiado»** — una **notificación sin enlace de acción** — disparado por un
   **reactor sobre `PasswordResetCompleted`** (NUNCA inline en `CompletePasswordReset`, que está en el techo CBO), con
   idempotencia por `DomainEventHandlerDeduplicator`. La superficie de email queda **C-refinada** (Decisión E): scaffold
   compartido hardened en un solo sitio, sin Twig.

6. **(concurrencia · FOR UPDATE — «incluir ambos»)** Se cierran los dos diferidos de concurrencia de II-5
   **reutilizando el patrón de pessimistic-lock que II-4 ya introdujo** (`DoctrineInvitationRepository::findByIdForUpdate`
   → `EntityManager::find(..., LockMode::PESSIMISTIC_WRITE)`) — **NO es el primer lock del repo** (la premisa «sería el
   1.er pessimistic-lock» del review de II-5 quedó **superada** por ese precedente de II-4): (a) **forgot-supersede** —
   dos `forgot` concurrentes del mismo user `ACTIVE` ya **no** dejan 2 tokens vivos (mutex por lock de la fila de usuario
   en `RequestPasswordReset`); (b) **TOCTOU del muro D-c** — un admin que suspende/desactiva **entre** el load y el commit
   ya **no** deja completar el reset sobre una identidad walled (re-chequeo del `status` **bajo el lock** en
   `CompletePasswordReset`). El double-complete ya lo cerró #491 (`consume():bool` + filas-afectadas) — no se re-toca.
   Relaciona/cierra la familia **#462** (FOR-UPDATE/TOCTOU). **Límite honesto:** el harness **no** ejercita concurrencia
   real (fakes single-thread; ni PHPUnit ni Behat) — el test asserta la **forma del lock** (`PESSIMISTIC_WRITE`) + el
   comportamiento secuencial; la carrera real se documenta como cubierta por diseño, no por un test de concurrencia.

7. **(retención/GDPR del token de reset — «incluir ambos»)** Las filas `identity_password_reset_token`: (a) tienen un
   **barrido TTL** (`expires_at < now`) — un comando/handler programado, sin crecimiento sin cota; (b) se **borran en la
   erasure del sujeto** (erase-subject GDPR del usuario) — sin filas huérfanas ni residuo de linkage `user_id`. Se
   engancha en el flujo de erasure existente (Epic 2). Tests: el barrido elimina expirados y respeta vivos; la erasure
   del sujeto borra sus tokens de reset.

8. **(cola D1–D3 de #493 · no-regresión, AC8)** Se cierra la cola: **copy del muro `INVALID_LINK`** para el reset
   (colapso «…inválido **o ha expirado**» → «Este enlace ya no es válido») reutilizando la variante de II-4; **E2E vivo**
   del reset de punta a punta (Playwright con token vivo, o cobertura Behat-API equivalente si no hay tooling para
   mintear un token vivo en el stack e2e). `make app.test` + `make app.quality` + `make pwa.quality` verdes; ninguna de
   las superficies previas (login 204+cookie, gate II-7, muros suspended/deactivated/locked, accept, reset)
   regresa; **cero credenciales/PII/tokens** en migración, logs o respuestas; deptrac/bounded-context/error-contract/
   psalm-taint verdes.

## Tasks / Subtasks

> **Paralelización (CLAUDE.md):** dos frentes independientes sin estado compartido → **subagente API**
> (P1/P3/P4/P5/P6 + tests backend + config `security.yaml`/`rate_limiter.yaml`/`messenger.yaml`/`Caddyfile`) **||**
> **subagente PWA** (P2 strip + P7 copy/E2E). **Ojo — NO fanoutar dentro del API** lo que toca los mismos ficheros de
> config: `messenger.yaml` (P3 async + P3 reactor route), `security.yaml`/`rate_limiter.yaml` (P4), `Caddyfile` (P2
> redacción — la parte Caddy es API-side aunque el resto de P2 sea PWA). Un solo agente API serializa esos ficheros.
> Verificar cada lado con `make php.stan`/`make php.quality` y `make pwa.quality` antes de commitear.

### P1 — Suelo constant-time transversal (AC1) [API]

- [x] **T1.1 — Cerrar la fuga `INVITED`.** Hoy `UserChecker::checkPreAuth` lanza `InvitedAccountException` **antes** de
      que se verifique la password, y `SecurityUser::getPassword()` devuelve `null` para `INVITED` → **no corre ningún
      verify** → `INVITED` es más rápido que «password errónea». Ecualizar: garantizar que el camino `INVITED` paga el
      **mismo trabajo de hash** que un fallo de credencial (p. ej. ejecutando la ecualización de `UserProvider` también
      en la rama `INVITED`, o reordenando para que el dummy-verify corra antes del throw). **Reutiliza**
      `UserProvider::equaliseTiming()` — no dupliques el dummy-hash; expón/comparte el mecanismo existente. Mantén el
      throw como el resultado (uniforme 401), solo añade el suelo de tiempo.
- [x] **T1.2 — Cerrar la fuga forgot.** `RequestPasswordReset::request()` hace un read barato para inexistente/no-`ACTIVE`
      vs un multi-write TX para `ACTIVE`. Con P3 (email async) el coste SMTP **sale** del request; queda el diferencial
      write-vs-read. Añadir un **suelo constant-time del camino pre-identidad** (el mismo dummy-hash) en el camino forgot
      para que la latencia no correle con la existencia. **Decisión de diseño (crux, ver Dev Notes):** el suelo es
      **transversal** (un colaborador `PreIdentityTimingFloor` reutilizado por login/forgot), **no** per-operación —
      documenta por qué el dominante del oráculo es el KDF (~decenas de ms), no el write (~sub-ms).
- [x] **T1.3 — KDF después del check barato del token (reset/accept).** Reordenar o envolver para que un token muerto de
      reset/accept **no** pague un argon2id completo antes del rechazo (amplificación DoS, `deferred-work.md` l.119/126).
      Si reordenar choca con «hashing en el adapter, no en el use-case» (arquitectura ratificada de II-4/II-5),
      resolverlo **dentro de la envolvente constant-time** (p. ej. el suelo de tiempo cubre el caso muerto sin correr el
      KDF real). Argumenta la elección.
- [x] **T1.4 — Test estructural (Decisión C).** Un test que asserta que el trabajo de hash-ecualización se **invoca** en
      cada rama pre-identidad: (a) `loadUserByIdentifier` inexistente/malformado → `equaliseTiming` corre; (b) camino
      `INVITED` → corre; (c) forgot inexistente/no-`ACTIVE`/`ACTIVE` → corre el suelo. Espía el `PasswordHasherFactory`/
      colaborador de timing (fake que cuenta invocaciones). **NUNCA** `assertLessThan($elapsed)` — el timing wall-clock
      está prohibido (flaky). Documenta el límite honesto: el test prueba *que el trabajo corre*, no *que las latencias
      son iguales* (eso es una propiedad del hasher, no re-testeada aquí).

### P2 — Higiene del token en URL (AC2) [PWA + Caddy]

- [x] **T2.1 — `Referrer-Policy: no-referrer` en las rutas por token [PWA].** El global vive en
      `pwa/next.config.ts#headers()` (`source: "/(.*)"` → `strict-origin-when-cross-origin`). Añadir una **entrada
      per-source** para `/accept-invitation` y `/reset-password` con `Referrer-Policy: no-referrer` (Next es el dueño de
      los headers de estas rutas; Caddy solo pone `?Link`/`?Permissions-Policy` condicionales). **No regreses** el CSP ni
      el resto del bloque de headers. (Alternativa: `pwa/src/proxy.ts` — pero `headers()` per-source es lo idiomático.)
- [x] **T2.2 — Strip del token de la URL [PWA].** En **`TokenActionScreen.tsx`** (tras leer `useSearchParams().get("token")`
      en ~l.36, añadiendo `useEffect` al import de react) **y** en **`ResetPasswordForm.tsx`** (~l.22): un
      `useEffect(() => { window.history.replaceState(null, "", window.location.pathname); }, [])` que corre **una vez** al
      mount, **inmediatamente tras** capturar el token. El `const token` capturado **sobrevive** (replaceState no pasa por
      el router de Next). No uses `router.replace` (re-render/navegación); usa la History API directa. `safeHref` **no**
      aplica (no hay input dinámico — `window.location.pathname`).
- [x] **T2.3 — Redacción del token en el access log de Caddy [API].** `api/frankenphp/Caddyfile` (~l.22-27) hoy redacta
      **solo** `authorization` en el filtro de log. Añadir `replace token REDACTED` en el mismo bloque `format filter →
      request>uri query` para que `?token=` no aterrice en el access log. Verifica que Monolog (`monolog.yaml`) no loggea
      la request-URI con el token por otra vía (no lo hace hoy — sin access-log role; confirmar).
- [x] **T2.4 — Tests [PWA + API].** Unit PWA: tras montar `ResetPasswordForm`/`TokenActionScreen` con `?token=x`,
      `window.location.search` queda vacío y el submit sigue usando el token. Header: assert en e2e/functional de
      `Referrer-Policy: no-referrer` en las 2 rutas. Caddy: test/assert de que el filtro incluye `token` (patrón del
      test de config existente, si lo hay; si no, doc + revisión manual).

### P3 — `SecurityEmail` endurecido + email password-changed (AC3, AC5) [API + config]

> **Superficie de email = C-refinado (Decisión E, ratificada Winston+Amelia).** Endurecer el bloque compartido
> `Shared/Mailer/Infrastructure/SecurityLinkMailer` **en un solo sitio**; el password-changed es una notificación
> **sin enlace**, disparada por un **reactor** (no inline). **NO Twig** (YAGNI para ~1 valor no confiable ya escapado).

- [x] **T3.1 — «Async» que NO fuga el token (Decisión H — leer antes de tocar `messenger.yaml`).** **NO** rutees
      ciegamente `Symfony\Component\Mailer\Messenger\SendEmailMessage: async` para los emails **con token** — el
      code-review de #493 lo **rechazó** (Symfony serializa el `Email` con el token en claro a la tabla del transporte +
      cola `failed` → viola SI-13). El token-bearing (invitación, reset) se queda **síncrono post-commit + best-effort**
      (`SendPasswordResetEmailBestEffort` — la neutralidad ya la da tragar el fallo, no el transporte; añadir el mismo
      best-effort al de invitación si no lo tiene). **Async genuino solo para el password-changed** (sin token): su
      **reactor** ya corre async al consumir `PasswordResetCompleted` del transporte, y su `Email` no lleva secreto → si
      `SendEmailMessage` se rutea async, **solo** ese (sin token) se serializa, y es seguro. **Riesgo:** rutar
      `SendEmailMessage: async` es **global** (afecta a TODOS los `mailer->send()`), así que hacerlo re-fugaría los emails
      con token. **Opciones (confirmar Decisión H):** (a) **NO** rutear `SendEmailMessage`; el password-changed es async
      por vivir en un reactor async, y su `mailer->send()` interno queda síncrono-en-el-worker (aceptable — es un worker,
      no el request); (b) rutear `SendEmailMessage: async` **y** garantizar que los token-bearing **NO** pasan por él
      (difícil — es global). **✅ RATIFICADO por Sergio = (a)** — el reactor da la asincronía real sin tocar el routing
      global de mailer, y los token-bearing nunca tocan el transporte. **NO** se toca `SendEmailMessage` en `messenger.yaml`;
      solo se rutea `PasswordResetCompleted: async` (T3.4). Doc: entrega del reactor = **at-least-once** (dedup por
      `(eventId, handler)`); los token-bearing = **best-effort síncrono** (no «handler idempotente»).
- [x] **T3.2 — Hardening del scaffold (`SecurityLinkMailer`, un solo sitio).**
  - [x] **Pila de sistema (UX-DR6):** `font-family:Arial,sans-serif` (l.73) → `-apple-system, system-ui, "Segoe UI",
        Roboto, sans-serif`.
  - [x] **HTTPS-only:** el link se arma de `%env(DEFAULT_URI)%` (=`http://localhost` en `.env`, `https://localhost` en
        `.env.example`). Añadir un **guard de esquema** en el único punto de armado (`SecurityLinkMailer::send`, l.38):
        fuera de dev, un base no-`https` **falla ruidosamente** (excepción) en vez de emitir un link `http`.
        Defense-in-depth: no confíes solo en la config.
  - [x] **Escape:** ya se escapa el único valor no confiable (el link, `htmlspecialchars`, l.58). El chrome extraído
        (Decisión E) preserva ese escape en un solo sitio; el password-changed es estático (sin interpolación no confiable).
- [x] **T3.3 — Remitente NO `no-reply` = env dedicado `MAILER_SECURITY_FROM` (Decisión F ✅ CERRADA).** Nuevo env var
      `MAILER_SECURITY_FROM` (p. ej. `seguridad@erpify.…`/`soporte@…`, no-`no-reply`) autowired en `SecurityLinkMailer` **y**
      en `SymfonyPasswordChangedEmailSender`; el operacional `PlainTextNotificationMailer` **mantiene** `MAILER_FROM=noreply@`.
      El AC3 fuerza que los dos valores difieran (seguridad replyable · operacional no-reply), así que no es un knob
      especulativo. Actualizar `.env` + `.env.example` + `api/docs/production-ready/secrets.md` (sin secretos; valor de
      ejemplo). **Guard fail-loud:** fuera de dev, un `MAILER_SECURITY_FROM` vacío o `no-reply` → excepción (no degradar en
      silencio; mismo patrón que el guard HTTPS de T3.2). Cero impacto en tests (`SecurityLinkMailerTest` inyecta el `from`
      por constructor, nunca por el nombre del env). **Check ops (Sergio):** confirmar buzón monitorizado para el remitente.
- [x] **T3.4 — Email «password-changed» (notificación sin enlace) — Decisión E ✅ CERRADA: sibling + chrome extraído.**
  - [x] **Extraer el chrome (paso 3 de Decisión E).** Sacar el scaffold compartido (doctype + `@media` dark-mode + body/
        wrapper con **pila de sistema** + el helper `htmlspecialchars`) de `SecurityLinkMailer::htmlBody()` a un colaborador
        `readonly` `Shared/Mailer/Infrastructure/BulletproofEmailChrome` (`render(string $innerHtml): string`).
        `SecurityLinkMailer` **delega** su chrome → el email de invitación/reset queda **byte-idéntico** (contrato congelado;
        `SecurityLinkMailerTest` sigue verde). Esto también evita re-disparar el Sonar dup-gate (#493 lo disparó a 5.1%).
  - [x] **Sender dedicado (NO ramificar el VO).** Nuevo port `Iam/Identity/Application/PasswordChangedEmailSender` +
        adapter `Iam/Identity/Infrastructure/Mail/SymfonyPasswordChangedEmailSender` (`#[AsAlias]`, `final readonly`, espejo
        de `SymfonyPasswordResetEmailSender`) que construye su `Mime\Email` estático (from = `MAILER_SECURITY_FROM`) con el
        body renderizado por `BulletproofEmailChrome`. **NO** se tocan `SecurityLinkMailer`/`SecurityLinkEmailContent`
        (contrato link-centric congelado); **NO** hay rama `if (hasCta)` — el adapter es branch-free (→ 100% coverage fácil).
  - [x] **Trigger = reactor async, NO inline** (`CompletePasswordReset` en techo CBO, #493 lo dejó fuera a propósito). El
        agregado ya graba `PasswordResetCompleted` (**wire-on-consumer, sin consumidor hoy**). II-8 es ese consumidor:
        `api/src/Iam/Identity/Infrastructure/Messenger/SendEmailOnPasswordResetCompleted.php`, `#[AsMessageHandler]` sobre
        `PasswordResetCompleted`, **espejo de** `SendEmailOnBankChanged` (`DomainEventHandlerDeduplicator::claim($eventId,
        self::class)` = guard de idempotencia a nivel-mensaje + release-on-failure). **Route
        `Erpify\Iam\Identity\Domain\Event\PasswordResetCompleted: async`** en `messenger.yaml` (hoy no está ruteado; se
        emite al outbox por `PersistDomainEventMiddleware` pero sin handler → el reactor es el consumidor).
  - [x] **Resolver el email in-módulo** vía `UserRepository::findById($event->aggregateId())?->email()` (`aggregateType =
        'Iam.Identity'`, aggregateId = userId → mismo módulo, sin cruzar frontera; el acoplamiento vive en el reactor, no en
        `CompletePasswordReset`). **Null-guard:** user hard-deleted antes del envío async → `findById` null → skip (el
        reactor solo lee → sin resurrección PII, evita el gap #376).
  - [x] **Contenido estático + PII-free** (Decisión E, unánime): «Tu contraseña de ERPify ha cambiado. Cerramos todas tus
        sesiones por seguridad. Si no fuiste tú, contacta con [remitente de seguridad] de inmediato.» **Sin IP/hora/
        dispositivo** (re-inyectaría PII en el evento + `event_store`). `lang="es"`, dark-mode, pila de sistema (UX-DR6).
- [x] **T3.5 — Tests (deterministas, sin flake).** Mantener el modelo `SecurityLinkMailerTest` (fake `CapturingMailer
      implements MailerInterface`, assert de strings sobre el `Mime\Email` construido: from, subject, `class="erpify-btn"`/
      `href="<escaped>"`, pila de sistema) — debe seguir **verde sin cambios** (contrato congelado tras delegar el chrome).
      Nuevos: `BulletproofEmailChromeTest` (el chrome envuelve un inner-HTML dado, dark-mode/pila-de-sistema presentes);
      `SymfonyPasswordChangedEmailSenderTest` (branch-free → assert del `Mime\Email` estático vía `CapturingMailer`: from =
      `MAILER_SECURITY_FROM`, sin `href`/CTA, pila de sistema); unit del reactor `SendEmailOnPasswordResetCompleted` (fake
      `DomainEventHandlerDeduplicator` + `PasswordChangedEmailSender` grabador → claim-once + send-once + release-on-throw +
      user-null → skip; espejo de los tests del reactor de Bank). Black-box opcional: Behat/Functional con
      `MailerAssertionsTrait` sobre el transporte in-memory de test.

### P4 — Rate-limit neutral (AC4) [API + config]

- [x] **T4.1 — Límite per-target en forgot/reset/accept que NO delata.** Hoy `symfony/rate-limiter` está instalado;
      `login_throttling` (5/IP+email) ya cae al 401 neutral; el global `anonymous_api` (120/min/IP, `RateLimitListener`,
      429 `rate-limited`) cubre todo `/api/*`. **Falta** un límite **per-target** (per-IP + **per-cuenta/email**) en
      forgot/reset/accept cuya respuesta de saturación **se pliegue a la forma pre-identidad uniforme** (forgot → **202**;
      reset/accept → opaco), **no** un 429 distinguible per-cuenta. Definir limitadores nombrados en
      `api/config/packages/rate_limiter.yaml` y cablearlos en los listeners de esas rutas (espejo/extensión de los
      `*OriginListener` existentes, o listeners dedicados keyed por ruta).
- [x] **T4.2 — Neutralidad del límite per-cuenta (crux).** Un limitador **per-cuenta** exhausto **no** puede cambiar la
      forma de la respuesta (revelaría qué cuentas están bajo ataque = oráculo de existencia). Diseño: cuando el bucket
      per-cuenta se agota, **silenciar el trabajo** (no mintear token / no enviar email) y **seguir devolviendo el 202
      uniforme** — mismo patrón que el forgot de cuenta inexistente. El per-IP puede 429 (IP-global no delata). Documenta
      la asimetría IP-vs-cuenta.
- [x] **T4.3 — Contrato de error.** `rate-limited` (429) **ya existe** en el contrato. Si algún mapeo cambia (p. ej. un
      nuevo marker o un cambio de status para estas rutas), actualizar
      [`docs/api-error-contract.md`](../../docs/api-error-contract.md) (NFR26) y `make php.lint.error-contract` verde. Se
      **espera sin marker nuevo** (el forgot saturado reusa el 202 uniforme; el per-IP reusa el `rate-limited` global) —
      confirmar en dev.
- [x] **T4.4 — Test.** Forgot existente-`ACTIVE` vs inexistente **bajo saturación** → respuesta idéntica (status + forma).
      Verifica que el login saturado sigue en 401 neutral (no regresa). Reset/accept saturados → opaco.

### P5 — Concurrencia · FOR UPDATE (AC6) [API]

- [x] **T5.1 — Lock pessimista de la fila de usuario en el forgot (el coste real de la Decisión D).** `UserRepository`
      **NO tiene método de lock hoy** → añadir `findByEmailForUpdate(Email): ?User` en `UserRepository` +
      `DoctrineUserRepository` que hace `EntityManager::find(User::class, $id, LockMode::PESSIMISTIC_WRITE)` — **espejo** de
      `DoctrineInvitationRepository::findByIdForUpdate:41` (patrón ya establecido en `main`, NO nuevo). **Crítico (Amelia,
      verificado):** hoy `RequestPasswordReset:56` hace `findByEmail` **fuera** de la TX (l.72-78) — **un lock tomado fuera
      de la TX no serializa nada**. Hay que **mover el re-fetch con lock DENTRO del `transactional()`** antes del
      `deleteAllForUser`+`save` (mutex de forgots concurrentes → «solo el último vivo»). Si el diseño uniforme (el read
      barato para inexistente/no-`ACTIVE`) exige resolver el user antes, resuélvelo dos veces: el read uniforme fuera (para
      la neutralidad) y el lock dentro de la TX solo en el camino `ACTIVE`.
- [x] **T5.2 — Re-chequeo del `status` bajo lock en el complete (TOCTOU).** En `CompletePasswordReset`, mover el
      muestreo del `status` (muro D-c) a **dentro de la TX y bajo el lock** de la fila de usuario, de modo que una
      suspensión/desactivación admin **entre** load y commit se vea → el reset aborta con el muro post-identidad. **No**
      re-toques el guard `consume():bool` (double-complete ya cerrado en #491).
- [x] **T5.3 — Sin migración (Decisión D ✅ CERRADA: NO unique).** El lock **no** requiere schema nuevo; se mantiene el
      índice NO-único `idx_identity_password_reset_token_user_id`. **NO** se añade un `UNIQUE(user_id)` (unánime 3-lentes):
      con el lock es redundante, sin él convierte el supersede concurrente en `UniqueConstraintViolationException` (500 que
      rompe el 202 uniforme), no expresa el invariante real («≤1 token **vivo**») y su catch es no-testeable → hunde
      `new_coverage`. (El mirror `DoctrineMembershipRepository`→`UserAlreadyMember` **no** transfiere: Membership no tiene
      lock serializador y su colisión SÍ es un outcome surfaced; aquí el lock cierra la carrera y la colisión no tiene
      outcome.) Cero migración en P5.
- [x] **T5.4 — Test (límite honesto del harness).** El harness **no** ejercita concurrencia real. Test Functional que
      asserta la **forma del lock** (la query lleva `FOR UPDATE` / `LockMode::PESSIMISTIC_WRITE`) + el comportamiento
      secuencial (supersede deja 1 token; TOCTOU secuencial → muro). Documenta que la carrera real es cubierta por diseño,
      no por un test de concurrencia (mismo criterio por el que #491 eligió el guard verificable `consume`). Relaciona
      **#462**.

### P6 — Retención / GDPR del token de reset (AC7) [API]

- [x] **T6.1 — Barrido TTL.** Comando `#[AsCommand]` (o handler Messenger programado) que borra
      `identity_password_reset_token` con `expires_at < now`. Ubicación `Iam/Identity/Infrastructure/Cli/` (o el patrón
      de sweeper existente). Sin crecimiento sin cota. Test Functional: elimina expirados, respeta vivos.
- [x] **T6.2 — Erase-subject (GDPR).** Borrar los tokens de reset del usuario en la **erasure del sujeto**. **Precedente
      (Epic 2):** `api/src/Backoffice/BankAccount/Application/EraseBankAccountSubject.php` + su
      `EraseBankAccountSubjectCommand` (CLI) + el orquestador `api/src/Shared/Audit/Application/SubjectErasureReconciler.php`.
      Añadir la erasure análoga para `Iam/Identity` (un `EraseIdentitySubject`/equivalente que incluya
      `PasswordResetTokenRepository::deleteAllForUser`), **o** enganchar en el reconciler si es el punto central de olvido
      por sujeto. **Confirmar** el punto exacto leyendo `SubjectErasureReconciler` + `EraseBankAccountSubject`. Sin filas
      huérfanas ni residuo `user_id` tras el hard-delete GDPR. Test: la erasure del sujeto borra sus tokens de reset.
- [x] **T6.3 — Seam deptrac (si aplica).** Si el enganche erase-subject cruza contexto (`Iam/Identity` ↔ `Shared/Audit`
      o el owner de la erasure), entrada **per-file** en `.bounded-context-allowlist` + deptrac `skip_violations` (nunca
      global). Confirmar el contexto del punto de erasure.

### P7 — Cola D1–D3 de #493 (AC5, AC8) [PWA]

- [x] **T7.1 — Copy del muro `INVALID_LINK` para reset.** Reutilizar la variante `INVALID_LINK` de `AccessWall` (II-4)
      para el reset; colapsar el copy «…inválido **o ha expirado**» → «Este enlace ya no es válido» (opacidad — no revela
      el motivo). El muro **siempre** ofrece salida («Iniciar sesión», D-a). Verifica que un token muerto de reset aterriza
      en el mismo muro opaco que uno de invitación.
- [x] **T7.2 — E2E vivo del reset.** Playwright del reset de punta a punta (forgot → email → link → reset → 204+cookie →
      revoca-todas). **Si no hay tooling para mintear un token vivo** en el stack e2e (como los muros de II-3/II-4), cubrir
      por **Behat-API** equivalente (mirror `password_reset.feature`) + unit PWA, y **documentar** por qué no hay e2e-live
      (mismo criterio que II-5). No inventes un e2e flaky.

### P8 — Gates + verificación fresca (todos los AC)

- [x] **T8.1 —** Worktree fresco → `make php.behat.install` **antes** de los gates. `make php.stan` por fichero (exit 139
      → `PHP_SERVICE=messenger_worker`) → `make php.test` → `make php.quality` **EXIT 0** (único sweep PHPMD/cs-fixer;
      puede OOM 137, re-run) → `make php.lint.error-contract` → `make php.lint.bounded-context` → `make php.deptrac` →
      `make php.psalm.taint`. **PWA:** `make pwa.quality` + `make pwa.test` (unit + e2e). Verifica sobre el **path del
      worktree**, confía en el exit code recién impreso (no logs viejos).
- [x] **T8.2 —** Actualizar `PRODUCTION_SECURITY_CHECKLIST.md` (cambio security-sensitive transversal: headers de token,
      redacción de logs, async email, remitente de seguridad, rate-limit de recuperación, pessimistic-lock del reset,
      retención GDPR) y `docs/rules/security.md` si se introduce un patrón nuevo (el suelo constant-time transversal y la
      neutralidad del rate-limit lo son; el pessimistic-lock **no** — ya existe vía II-4).

## Dev Notes

### Mapa de reutilización (NO reinventar — verificado en `main` @ `85243d70`)

| Necesidad II-8 | Reutiliza (existe en `main`) | Ubicación |
|---|---|---|
| **Suelo constant-time (referencia)** | `UserProvider::equaliseTiming()` (hash+verify de `TIMING_PROBE_INPUT`, lazy `dummyHash`, keyed en `SecurityUser::class`) | `api/src/Iam/Identity/Infrastructure/Security/UserProvider.php:84` |
| **Fallo pre-identidad → 401 uniforme** | `ProblemDetailsAuthenticationFailureHandler` (throttle cae al 401; graded 403 para muros) | `api/src/Iam/Identity/Infrastructure/Security/ProblemDetailsAuthenticationFailureHandler.php` |
| **Admisión INVITED (la fuga)** | `UserChecker::checkPreAuth` (throw `InvitedAccountException` **pre-hash**) · `SecurityUser::getPassword()` (null para INVITED) | `api/src/Iam/Identity/Infrastructure/Security/{UserChecker,SecurityUser}.php` |
| **Scaffold de email compartido (hardening choke point)** | `SecurityLinkMailer` (bulletproof, dark-mode, escape link, `MAILER_FROM`, `DEFAULT_URI`) + `SecurityLinkEmailContent` (VO copy+ruta) | `api/src/Shared/Mailer/Infrastructure/{SecurityLinkMailer,SecurityLinkEmailContent}.php` |
| **Adapters de email por flujo** | `SymfonyInvitationEmailSender` (Iam/Invitation) · `SymfonyPasswordResetEmailSender` (Iam/Identity) — ~30 líneas, delegan | `api/src/Iam/{Invitation,Identity}/Infrastructure/Mail/` |
| **Best-effort del envío (neutralidad)** | `SendPasswordResetEmailBestEffort` (traga `\Throwable`+log → el 202 no delata) | `api/src/Iam/Identity/Application/SendPasswordResetEmailBestEffort.php` |
| **Reactor + idempotencia (patrón para password-changed)** | `SendEmailOnBankChanged` (`#[AsMessageHandler]` + `DomainEventHandlerDeduplicator::claim`) | `api/src/Backoffice/Bank/Infrastructure/Messenger/SendEmailOnBankChanged.php` |
| **Evento trigger (wire-on-consumer)** | `PasswordResetCompleted` (grabado por `User::resetPassword()`, payload PII-free) | `api/src/Iam/Identity/Domain/Event/PasswordResetCompleted.php` |
| **Consumo single-use (double-complete ya cerrado)** | `PasswordResetTokenRepository::consume():bool` (DELETE condicional, filas-afectadas = guard) | `api/src/Iam/Identity/Domain/Repository/PasswordResetTokenRepository.php` |
| **Pessimistic-lock (patrón P5, ya en `main` vía II-4)** | `DoctrineInvitationRepository::findByIdForUpdate` (`EntityManager::find(..., LockMode::PESSIMISTIC_WRITE)`) — espejar para `User` | `api/src/Iam/Invitation/Infrastructure/Persistence/Doctrine/DoctrineInvitationRepository.php:41` |
| **Erase-subject GDPR (precedente P6)** | `EraseBankAccountSubject` + `EraseBankAccountSubjectCommand` (CLI) + `SubjectErasureReconciler` (orquestador) | `api/src/Backoffice/BankAccount/Application/EraseBankAccountSubject.php` · `api/src/Shared/Audit/Application/SubjectErasureReconciler.php` |
| **Fake mailer para tests (deterministas)** | `CapturingMailer implements MailerInterface` + `SecurityLinkMailerTest` (assert de strings sobre `Mime\Email`) | `api/tests/Unit/Shared/Mailer/Infrastructure/{CapturingMailer,SecurityLinkMailerTest}.php` |
| **Rate-limiter instalado** | `symfony/rate-limiter ^8.0.14` · `login_throttling` (security.yaml) · `anonymous_api` + `RateLimitListener` (429) | `api/config/packages/{security,rate_limiter}.yaml` · `api/src/Shared/ErrorContract/Infrastructure/Http/EventListener/RateLimitListener.php` |
| **Origin listeners (rutas pre-identidad)** | `LoginOriginListener` · `PasswordResetOriginListener` · `AcceptInvitationOriginListener` (same-origin 403) | `api/src/Iam/{Identity,Invitation}/Infrastructure/Http/` |
| **Access log filter (redacción)** | Caddy `log { format filter { request>uri query { replace authorization REDACTED } } }` | `api/frankenphp/Caddyfile:20-28` |
| **Headers PWA (dueño de token screens)** | `next.config.ts#headers()` (`source:"/(.*)"`, Referrer-Policy global) | `pwa/next.config.ts:79-117` |
| **Pantallas por token (strip target)** | `TokenActionScreen.tsx` (accept, lee `?token=` l.36) · `ResetPasswordForm.tsx` (reset, l.22) | `pwa/src/app/(auth)/_components/` |
| **Muro opaco `INVALID_LINK`** | `AccessWall` variante `INVALID_LINK` (español) | `pwa/src/context/shared/error/infrastructure/ui/AccessWall.tsx` |
| **Higiene de navegación** | `safeHref`/`safeInternalPath` | `pwa/src/context/shared/navigation/domain/` |

**NET-NEW (crear en II-8):** colaborador de suelo constant-time (P1) · `Shared/Mailer/Infrastructure/BulletproofEmailChrome`
(chrome extraído) + port `PasswordChangedEmailSender` + adapter `SymfonyPasswordChangedEmailSender` + reactor
`SendEmailOnPasswordResetCompleted` (P3) · limitadores per-target neutrales + su cableado (P4) ·
`UserRepository::findByEmailForUpdate` + re-chequeo bajo lock (P5) · comando de barrido TTL + `EraseIdentitySubject`/enganche
erase-subject (P6). **MODIFICAR:** `SecurityLinkMailer` (font/HTTPS/from + delegar chrome, contrato congelado) ·
`messenger.yaml` (`PasswordResetCompleted: async`; **NO** `SendEmailMessage`, Decisión H) · `next.config.ts` (per-route
`no-referrer`) · `TokenActionScreen`/`ResetPasswordForm` (strip) · `Caddyfile` (redact token) · `UserChecker`/`UserProvider`
(fuga INVITED) · `RequestPasswordReset`/`CompletePasswordReset` (FOR UPDATE + re-chequeo, `CompletePasswordReset` sin subir
CBO) · `rate_limiter.yaml`/`security.yaml` · `.env`/`.env.example` (`MAILER_SECURITY_FROM`) · `AccessWall`/copy reset · docs.

### El crux P1 — suelo constant-time transversal, no per-operación

El oráculo de enumeración por timing se cierra con un **suelo del camino pre-identidad**, no parcheando cada operación.
El término **dominante** del oráculo es el **KDF de password** (argon2id, ~decenas de ms), **no** los writes (~sub-ms) —
por eso `deferred-work.md` (l.108/126) dice «II-8 lo cierra holísticamente». El login ya tiene el suelo
(`equaliseTiming`); II-8 lo **eleva a contrato transversal** cubriendo `INVITED` (T1.1) y forgot (T1.2), y quita el KDF
del camino de token muerto (T1.3). **Prueba estructural, no wall-clock** (Decisión C): el timing wall-clock es flaky y
está prohibido en el repo (`ii-2`/`ii-4`/`ii-5`); el test asserta *que el trabajo de hash corre en cada rama*, delegando
la igualdad de coste al hasher (propiedad de `symfony/password-hasher`, no re-testeada). **Límite honesto a documentar:**
tras II-8, si el delta del write aún midiera, la mitigación correcta es igualar el trabajo del store dentro de la
envolvente constant-time — NO async (rompe read-your-writes; la consulta 3-lentes de II-6 ya lo descartó).

### El crux P3 — la superficie de email ya es C (Decisión E), II-8 la termina

**Winston + Amelia convergen (2026-07-14): C-refinado.** #493 ya extrajo `SecurityLinkMailer` + `SecurityLinkEmailContent`
(el mundo «heredoc por adapter» del brief es pre-#493). II-8 **no elige A/B/C desde cero — termina C:** endurece el
scaffold en un solo sitio (font/HTTPS/from), enruta async por config, y añade el password-changed como **reactor
deduplicado** sobre `PasswordResetCompleted` (nunca inline en `CompletePasswordReset`, que está en el techo CBO ~12-13 y
#493 lo dejó fuera a propósito). **Rechazan A (Twig):** autoescape total no compra casi nada cuando el **único** valor no
confiable es el link (ya escapado + unit-testeado), y cuesta habilitar Twig en prod (`twig.yaml` es `when@dev` hoy) +
autoría/migración de plantillas + downgrade del modelo de test directo-sobre-`Mime\Email`. **Rechazan B:** duplicar un
**control de seguridad** (el escape) en 3-4 adapters es el vector de regresión que la extracción mató.

### Decisión E — modelado del email password-changed — ✅ CERRADA (consulta 3-lentes Winston · Amelia · ChatGPT, 2026-07-14)

El password-changed es una **notificación SIN enlace de acción** (sin token, sin CTA) → **no** encaja en
`SecurityLinkEmailContent` (link-centric: `path`, `ctaLabel`). Las tres lentes:
**ChatGPT** → (a) ramificar el único render con CTA opcional; **Winston + Amelia** → sibling dedicado que **duplica** el
skeleton; **unánime** → notificación **estática + PII-free**.

**Decisión final (síntesis — corrige un punto que las 3 lentes omitieron):**

1. **Estática + PII-free (unánime, correcto).** Sin IP/hora/dispositivo. Interpolar contexto del request re-inyectaría PII
   en el evento `PasswordResetCompleted` (PII-free por diseño) **y** en su fila durable de `event_store`, y arrastraría
   contexto HTTP al worker async. El «¿no fuiste tú?» se cubre con copy estático + «contacta con [remitente de seguridad]».
2. **Sibling dedicado, NO ramificar el choke-point (Winston + Amelia, correcto — se rechaza el (a) de ChatGPT).** Nuevo
   port `PasswordChangedEmailSender` (`Iam/Identity/Application`) + adapter `SymfonyPasswordChangedEmailSender`
   (`Iam/Identity/Infrastructure/Mail`), espejo de los senders de invitación/reset. **NO** se hacen nullable `path`/
   `ctaLabel` ni se pasa un `#[SensitiveParameter] $token` vacío al `SecurityLinkMailer` — «un `SecurityLinkMailer` que
   manda correo sin enlace» es el smell de honestidad-de-capacidad y mete riesgo de regresión en los emails de
   invitación/reset (el choke-point que II-8 endurece). El contrato público de `SecurityLinkMailer`/`SecurityLinkEmailContent`
   queda **congelado**.
3. **Reusar el chrome, NO duplicarlo (el punto que las 3 lentes omitieron — restricción del repo).** «Duplicar el skeleton»
   (Winston/Amelia) **choca de frente con la razón por la que existe `SecurityLinkMailer`**: en #493, clonar ese scaffold
   HTML disparó Sonar `new_duplicated_lines_density` a **5.1% > 3%**, lo que **forzó** la extracción — y #493 dejó la
   directiva explícita **«un 3.er email de seguridad DEBE reusar `SecurityLinkMailer`»**. Re-duplicar re-dispararía ese gate.
   → **Extraer el chrome mínimo** (doctype + `@media` dark-mode + body/wrapper con pila-de-sistema + el helper de escape)
   de `SecurityLinkMailer` a un colaborador `readonly` en `Shared/Mailer/Infrastructure/` (p. ej. `BulletproofEmailChrome`
   con `render(string $innerHtml): string`); `SecurityLinkMailer` delega su chrome (el email de invitación/reset queda
   **byte-idéntico**, contrato intacto), y `SymfonyPasswordChangedEmailSender` renderiza sus párrafos estáticos por el mismo
   chrome. Es la **única** opción que satisface las 4 restricciones a la vez: congelar el choke-point · honrar la directiva
   #493 «3.er email reusa» · esquivar el Sonar dup-gate · mantener estático/PII-free. La extracción del chrome está
   justificada por Regla-de-Tres (3.er consumidor + es un **control de seguridad** compartido: el escape) — la misma
   «flexibilidad justificada» que #493 usó al extraer `SecurityLinkMailer`.

**NO reuses el `PlainTextNotificationMailer`** (`<pre>`, audit/bank) — UX-DR6 exige el tratamiento estilado/dark-mode/
pila-de-sistema en la confirmación, y usa `MAILER_FROM` (choca con la Decisión F).

### El crux P4 — neutralidad del rate-limit (per-IP vs per-cuenta)

Un límite **per-IP** que 429ea no delata existencia (es global a la IP). Un límite **per-cuenta** que cambie la respuesta
**sí** delata (revela qué cuentas están bajo ataque). Por eso el ADR D10 exige que la saturación «se pliegue a la forma
pre-identidad uniforme, no un 429 distinguible». Diseño: el per-cuenta exhausto **silencia el trabajo** (no mint / no
email) y **mantiene el 202**; el per-IP puede 429. El login ya lo cumple (el throttle cae al 401, no lo mapea a 429). El
riesgo que P4 cierra: sin per-cuenta, email-bombing de una víctima + supersesión continua de su token legítimo vía
`deleteAllForUser` (`deferred-work.md` l.127).

### Cruxes P5/P6 — pessimistic-lock (patrón ya existente) + higiene de retención

**P5:** Sergio en II-5 **descartó** FOR UPDATE (bajo la premisa de que sería el 1.er lock del repo + no verificable por el
harness) y cerró solo el double-complete con `consume()`+affected-rows. **Para II-8 opta por incluirlo** (Q4=«ambos»):
forgot-supersede (mutex del user row) + TOCTOU (re-chequeo bajo lock). **Corrección de premisa:** el pessimistic-lock **ya
existe en `main`** — II-4 lo introdujo en `DoctrineInvitationRepository::findByIdForUpdate:41`
(`LockMode::PESSIMISTIC_WRITE`), así que P5 **espeja un patrón establecido**, no uno nuevo (la objeción «1.er lock» del
review de II-5 está superada). El harness sigue sin ejercitar concurrencia real → el test asserta la **forma del lock** +
comportamiento secuencial; la carrera real es cubierta-por-diseño. Relaciona **#462**. **P6:** el token de reset no tiene
barrido TTL (crece sin cota) ni erase-subject (huérfanos GDPR) — `deferred-work.md` l.128. El erase-subject **ya tiene
precedente**: `EraseBankAccountSubject` (Application) + `EraseBankAccountSubjectCommand` (CLI) + el orquestador
`Shared/Audit/Application/SubjectErasureReconciler`. P6 añade la erasure análoga para los tokens de reset del sujeto
(mirror `EraseBankAccountSubject`, o engancha en el reconciler si es el punto central).

### Decisiones a confirmar al inicio del dev (recomendaciones flagged)

> **D, E, F, G CERRADAS por consulta 3-lentes (Winston · Amelia · ChatGPT, 2026-07-14).** D/F/G unánimes; E por síntesis.

- **Decisión C — prueba de constant-time = ESTRUCTURAL** (✅ ratificada Sergio). Test que asserta invocación del trabajo de
  hash por rama; **nunca** wall-clock. Ver crux P1.
- **Decisión D — ¿unique en `user_id`? = ✅ CERRADA: NO unique** (unánime Winston · Amelia · ChatGPT). El FOR UPDATE es el
  único serializador; el unique es redundante con el lock, un landmine sin él (supersede concurrente delete-then-insert →
  `UniqueConstraintViolationException` = 500 que rompe el 202 uniforme/SI-12), no puede expresar el invariante real («≤1
  token **vivo**»), y añade un catch no-testeable (sin concurrencia en el harness → hunde `new_coverage`). **Coste real de D
  (Amelia, verificado):** `UserRepository` no tiene método de lock hoy → añadir `findByEmailForUpdate` (espejo
  `findByIdForUpdate`) **y mover el `findByEmail` DENTRO del `transactional()`** (hoy en `RequestPasswordReset:56`, antes de
  la TX — un lock fuera de la TX no serializa). Ver T5.1/T5.3.
- **Decisión E — modelado del password-changed = ✅ CERRADA: sibling dedicado + reusar chrome (extraído), NO duplicar, NO
  ramificar el VO; estático + PII-free.** Ver «Decisión E» arriba (síntesis de las 3 lentes + la restricción Sonar-dup de
  #493 que las 3 omitieron). Ver T3.4.
- **Decisión F — remitente = ✅ CERRADA: (a) `MAILER_SECURITY_FROM` dedicado** (unánime). El AC3 **fuerza** valores
  distintos (seguridad no-`no-reply` · operacional `noreply@` legítimo) → no es YAGNI. Añadir guard fail-loud si el remitente
  de seguridad va vacío/`no-reply` fuera de dev (Winston). **Check ops para Sergio (ChatGPT):** confirmar que existe un
  buzón monitorizado `seguridad@`/`soporte@`. Ver T3.3.
- **Decisión G — testing del reset = ✅ CERRADA: (a) Behat-API + unit PWA, documentado** (unánime). `password_reset.feature`
  ya mintea un token determinista (INSERT hash conocido + POST `<id>.<secret>`) y conduce el flujo servidor de punta a punta;
  el e2e-live vivo duplicaría eso en la flakiness conocida de Mercure/`.next-e2e`. Ver T7.2.
- **Decisión H — «async» del email vs SI-13 (CONFLICTO, el más importante) — ✅ RATIFICADA por Sergio (2026-07-14) =
  opción (a).** El AC3 literal pide «async vía Messenger», pero #493 (review ratificado) **rechazó** rutear
  `SendEmailMessage` porque serializa el **token en claro** al transporte → viola SI-13. **Decisión (a):** **NO** rutear
  `SendEmailMessage`; los emails con token quedan **síncronos post-commit + best-effort** (la neutralidad la da tragar el
  fallo, no el transporte), y el **password-changed** (sin token) es async por vivir en un **reactor async** sobre
  `PasswordResetCompleted` (su `send()` interno queda síncrono-en-el-worker, fuera del request). Es una **reinterpretación
  argumentada** del AC3: la garantía real que AC3 persigue (un fallo del mailer no vuelve el 202 un 500 = neutralidad) ya
  la cumple el best-effort; forzar el transporte re-abriría la fuga SI-13. Ver T3.1 (opción (a)).

### Fuera de alcance (frontera explícita — no lo hagas en II-8)

- **Cambiar contraseña desde «Mi cuenta» (J6)** — confluencia Session+Auth+Identity **con** sesión de confianza («cerrar
  las demás»). II-8 solo el forgot/reset **sin** sesión de confianza. Es un extension point. *(El reactor de
  password-changed de II-8 quedará listo para reusarse cuando J6 aterrice.)*
- **magic-link / MFA / SSO / API keys** — futuros canales pre-identidad; el suelo constant-time de II-8 los cubrirá cuando
  existan, pero no se construyen aquí.
- **Tenancy operativa** (self-signup, tenant-switching, enforcement cross-tenant, `subject:` del voter) — ADR de tenancy.
- **UI de gestión de miembros/sesiones** (invitar/revocar/activar desde backoffice) — slice J5.
- **Nonce-based CSP** (`script-src` sin `'unsafe-inline'`) — iniciativa aparte (`pwa/CLAUDE.md`); II-8 no toca el CSP salvo
  para NO regresarlo.
- **i18n del `AccessWall`** (variantes suspended/deactivated/locked en inglés, gap de II-4) — iniciativa i18n aparte.

### Project Structure Notes

- `Iam/Identity`, `Iam/Invitation`, `Shared/Mailer` **ya registrados** en deptrac → II-8 puebla namespaces existentes.
  Seams cross-contexto nuevos posibles: el reactor `PasswordResetCompleted` (dentro de `Iam/Identity` — sin seam) y el
  enganche erase-subject (P6 — **puede** cruzar a `Shared/Audit`/owner de erasure → entrada per-file si aplica).
- `messenger.yaml`: añadir 2 rutas (`SendEmailMessage`, `PasswordResetCompleted`) al bloque `routing` existente.
- Migración: **solo si** se adopta el unique de Decisión D (recomendado no). El barrido TTL (P6) es un comando, sin schema.
- PWA: los strips viven en los componentes existentes bajo `(auth)/_components/`; el header per-route en `next.config.ts`.
- **Worktree obligatorio (CLAUDE.md):** II-8 es multi-edit grande → arrancar en worktree aislado off `main`, **con la
  topología de rama confirmada por Sergio** (ver «Handoff» al final). Rama sugerida `feat/iam-ii8-<slug>`.

### Testing (patrones del repo — II-3/II-4/II-5/#493 son los precedentes frescos)

- **Unit domain/app:** el colaborador de suelo constant-time (invocación por rama, fake del hasher-factory); el reactor
  password-changed (fake `DomainEventHandlerDeduplicator` + mailer grabador → claim/send/release); el guard de esquema
  HTTPS de `SecurityLinkMailer` (base http fuera de dev → excepción); los limitadores neutrales (per-cuenta exhausto →
  respuesta uniforme). `@internal` + `#[CoversClass]` **estricto** (SonarCloud `new_coverage ≥ 80%` es gate real — II-1
  falló a 78.8%). Presupuestos PHPMD: `TooManyPublicMethods ≤ 10` (DataProviders estáticos cuentan), `CouplingBetweenObjects
  ≤ 13` (aplica a tests → stubs a un trait si dispara; **`CompletePasswordReset` NO debe subir de CBO** — el email va al
  reactor).
- **Functional (Postgres real):** el FOR UPDATE (forma de la query + secuencial); el barrido TTL (borra expirados, respeta
  vivos); el erase-subject (borra tokens del sujeto); `SecurityLinkMailerTest` (assert de strings sobre el `Mime\Email`).
- **Behat** (`api/features/backoffice/identity/`): extender `password_reset.feature`/`login.feature`/`invitation` con la
  neutralidad bajo saturación (forgot ACTIVE vs inexistente idénticos bajo rate-limit) y (opcional) `MailerAssertionsTrait`.
  **+2 queries por escritura envuelta** (BEGIN/COMMIT). Worktree fresco → `make php.behat.install` antes de gates.
- **PWA (Vitest):** el strip (`window.location.search` vacío tras mount, el submit sigue con el token); el muro
  `INVALID_LINK` de reset. **E2E (Playwright):** header `no-referrer` en las 2 rutas; reset end-to-end **si** hay token
  vivo, si no Behat-API (Decisión G). Cuidado con la polución cross-spec de Mercure/real-API (memoria) y EACCES de
  `.next-e2e` en worktree.

### Gotchas heredados (verificados en ii-0..7 + #493)

- `make php.behat.install` en worktree fresco antes de gates · `php.stan` exit 139 → `PHP_SERVICE=messenger_worker` ·
  `make php.quality` es el único sweep PHPMD/cs-fixer (OOM 137 → re-run) · **Rector gana** (nunca `@psalm-suppress`, nunca
  `// NOSONAR`, nunca `/** @var */` sin nombre sobre `return` en tests → `@phpstan-var`) · migración editable en esta rama,
  inmutable tras merge · **barrer del diff final** los IDs de story/AC/SI/NFR/D y comentarios change-relative en
  `api/src`/`pwa/src` (permitidos en el spec, **prohibidos** en código merged) · comentarios solo del *por qué* no obvio ·
  `SendEmailMessage` async = at-least-once (no «idempotente») · el email password-changed **no** debe subir el CBO de
  `CompletePasswordReset` (va al reactor) · verificar **fresco** sobre el path del worktree, confiar en el exit code recién
  impreso · PWA: no `maxLength` en inputs; `safeHref` en navegación dinámica; sin secretos/tokens en storage; no regresar
  el CSP/headers de `next.config.ts`.

### References

- [Source: `docs/adr/identity-invitation-lifecycle.md` D10 (constant-time/status/shape), D11 (higiene URL: no-referrer +
  strip + redacción), D9/D12 (contexto reset/error graduado)].
- [Source: `_bmad-output/planning-artifacts/arch-addendum-identity-invitation.md` SI-12/SI-13, PR-8 (línea 35), «Load-bearing
  implementation challenges» (constant-time across states)].
- [Source: `_bmad-output/planning-artifacts/epics-identity-invitation-lifecycle.md` Story II-8 (líneas 847-880); FR10
  (líneas 150-156); NFR1 (indistinguibilidad = timing+status+shape), NFR2 (opacidad+higiene), NFR10 (observabilidad de
  eventos)].
- [Source: `_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-07-06/DESIGN.md` §`SecurityEmail` (UX-DR6): bancario,
  un enlace bulletproof, dark-mode, pila de sistema, remitente no-`no-reply`; `review-security.md` (3 findings: timing /
  higiene URL / rate-limit)].
- [Source: `_bmad-output/implementation-artifacts/{ii-4,ii-5}-*.md`] — las stories hermanas: los seams diferidos a II-8
  (constant-time, `Referrer-Policy`, strip, redacción, rate-limit, remitente no-reply) y el arco de review de II-5
  (concurrencia → II-8, guard `consume` verificable).
- [Source: `_bmad-output/implementation-artifacts/deferred-work.md`] — l.108/126 (timing pre-identidad → II-8), l.119
  (KDF antes del check de token), l.127 (rate-limit per-target → II-8), l.128 (retención/GDPR del token), l.130
  (forgot-supersede + TOCTOU → II-8, familia #462).
- **Consulta arquitectónica (2026-07-14, Winston + Amelia):** superficie de email = **C-refinado** (endurecer
  `SecurityLinkMailer` compartido, no Twig; password-changed = reactor deduplicado sobre `PasswordResetCompleted` espejo de
  `SendEmailOnBankChanged`; remitente en env dedicado). Ver Decisión E.
- Precedentes de código: `SendEmailOnBankChanged` (reactor+dedup) · `UserProvider::equaliseTiming` (suelo timing) ·
  `SecurityLinkMailer` (scaffold email) · `RateLimitListener` + `login_throttling` (rate-limit) · `PasswordResetOriginListener`
  (Origin) · `SendPasswordResetEmailBestEffort` (best-effort neutral) · `Caddyfile` (redacción de query).

## Dev Agent Record

### Agent Model Used

claude-fable-5 (Fable 5) — implementación; story creada con claude-opus-4-8[1m].

### Debug Log References

- PHPMD round 1 (13 violaciones: CBO en use cases/tests, empty-catch, else, LongVariable) → 0 sin ninguna supresión nueva; ver Completion Notes 9.
- E2E PWA: 149/158 en paralelo; los fallos alternan entre runs (login = submit GET nativo pre-hidratación). A/B con `pwa/` revertido a `main` (stash) contra el MISMO stack: fallan los mismos 2 specs (`logout` mobile, `error-pages` go-back) → flakes de entorno pre-existentes, no regresión. Logs en scratchpad de sesión (`e2e*.log`).

### Completion Notes List

1. **P1 (AC1).** Puerto `PreIdentityTimingFloor` (Application) + adapter `PasswordHashingTimingFloor` (dummy-hash lazy movido desde `UserProvider`, que queda `readonly` delegando). Rama `INVITED` (`UserChecker::checkPreAuth`) y TODAS las ramas del forgot pagan el suelo. **T1.3 — elección argumentada:** el KDF se difiere con una `Closure(): HashedPassword` construida en el controller — el hashing sigue físicamente en Infrastructure y el plaintext nunca cruza a Application, pero solo se invoca con token vivo (en `AcceptInvitation` corre bajo el lock de la fila de invitación: ms extra serializando solo accepts del MISMO token, la carrera que el lock ya serializa). Los casos muertos quedan mutuamente uniformes y baratos. Test estructural: `CountingPreIdentityTimingFloor` + asserts `$kdfRuns === 0` en todo rechazo; cero wall-clock.
2. **P2 (AC2).** PWA (subagente): `Referrer-Policy: no-referrer` per-source tras el global (la última key gana — test de config lo ancla con índices); strip vía `history.replaceState` con captura en `useState` lazy (Next ≥14.1 sincroniza replaceState con el router → una variable de render podía perder el token en re-render; test «keeps the captured token across re-renders» lo bloquea). Caddy: `replace token REDACTED` + gate `CaddyfileAccessLogRedactionGateTest` (Monolog no loggea URIs — confirmado). Sin regresión de CSP.
3. **P3 (AC3/AC5, Decisiones E/F/H).** `BulletproofEmailChrome` extraído (dark-mode + pila de sistema + escape en UN sitio); `SecurityLinkMailer` delega y gana el guard HTTPS fail-loud (exento dev **y test** — Behat corre sobre `http://localhost`); remitente en `SecuritySenderAddress` (guard no-reply/vacío centralizado — control de policy con 2 consumidores, mismo argumento anti-duplicación que mató el heredoc clonado en #493). `MAILER_SECURITY_FROM` en `.env(.example)` + `secrets.md`. Password-changed: port + adapter branch-free + reactor `SendEmailOnPasswordResetCompleted` (espejo Bank: claim/release + user-null → skip) + route `PasswordResetCompleted: async` (único routing añadido; `SendEmailMessage` NO se toca — Decisión H(a)). **T3.1 extra:** el envío de invitación NO era best-effort — `SendInvitationEmailBestEffort` añadido y cableado en `SendInvitation`/`ResendInvitation` (un fault del mailer post-commit abortaba el CLI y perdía el token ya emitido). `SecurityLinkMailerTest`: asserts congelados intactos; solo el constructor del helper crece (los guards exigen entorno/colaboradores — sin defaults que desactiven silenciosamente un control).
4. **P4 (AC4).** Los límites viven en el **edge** (controllers), como `login_throttling`/`anonymous_api` — además los dos use cases están en techo CBO 13 y el puerto los rompía. `password_recovery_per_email` (5/h) y `token_action_per_selector` (10/15min) en `rate_limiter.yaml`, env-driven. Forgot saturado → mismo 202 + trabajo silenciado **+ suelo de timing pagado en el controller** (la denegación tampoco es distinguible por latencia); reset/accept saturados → `InvalidResetToken`/`InvalidToken` (misma pipeline opaca; keyed por selector — un 429 per-selector confirmaría que el selector existe). Behat: steps que agotan el bucket en el mismo container (el cache array se resetea por request) + 4 scenarios (forgot existente vs inexistente idénticos bajo saturación; reset y accept con token VIVO + selector agotado → muro opaco sin consumir). Login saturado → 401 ya clavado por `ProblemDetailsAuthenticationFailureHandlerTest`. Sin marker nuevo (T4.3 confirmado).
5. **P5 (AC6).** `UserRepository::findByIdForUpdate` — **por id, no `findByEmailForUpdate`**: ambos call-sites ya tienen el id y es el espejo exacto del precedente de II-4. **Desviación argumentada:** DQL + `LockMode::PESSIMISTIC_WRITE` + `Query::HINT_REFRESH` en vez de `find(..., PESSIMISTIC_WRITE)` — ambos callers re-leen un aggregate YA cargado en el identity map, y sobre una entidad managed `find()` solo bloquea SIN refrescar → el re-chequeo TOCTOU leería el snapshot stale. Probado contra Postgres real: RowShareLock presente en `pg_locks` tras la llamada, y un UPDATE a espaldas del ORM se ve tras el re-fetch. Forgot: re-fetch bajo lock DENTRO de la TX antes del supersede (user desaparecido → ni token ni email); complete: doble muestreo del muro (fuera = barato sin KDF; bajo lock = autoritativo). `consume():bool` intacto. Sin migración (Decisión D). Límite honesto documentado: el harness prueba la FORMA del lock + comportamiento secuencial; la carrera real queda cubierta por diseño (#462).
6. **P6 (AC7).** `deleteExpired(now)` + `deleteAllForUser` ahora devuelve filas afectadas. Comando `identity:password-reset-tokens:prune` (programarlo en cron de prod — anotado en el checklist). `EraseIdentitySubject` + CLI `identity:gdpr:erase-subject` (espejo del precedente BankAccount, sin crypto — el PII del módulo es la propia fila `identity_user`): TX única {tokens + user hard-delete} + evidencia `GDPR_SUBJECT_ERASED` (metadata solo con el UUID pseudónimo), idempotente. **T6.3: sin seam deptrac** — solo imports `Shared/` (siempre permitidos).
7. **P7 (AC5/AC8).** T7.1 **sin cambio de código**: el flujo reset ya colapsa a `AccessWall INVALID_LINK` con el copy exacto «Este enlace ya no es válido» (no existe «inválido o ha expirado» en el repo) — se añadieron candados de test (heading + salida «Iniciar sesión»). T7.2 por **Decisión G**: `password_reset.feature` ya conduce el flujo servidor de punta a punta (token determinista → 204 + cookie + revoca-todas + login con la nueva password + single-use) + unit PWA del strip/muro; no se añadió e2e-live (la flakiness local de Mercure/`.next-e2e` está documentada y el A/B de esta sesión la re-confirma).
8. **Gates (T8.1, exit codes frescos sobre el worktree):** `php.stan` ✅ · `php.test` ✅ (PHPUnit completo + Behat **286/286**) · `php.quality` **EXIT=0** (cs-fixer/rector/PHPCS/PHPMD 0 violaciones/deptrac) · `php.lint.error-contract` ✅ · `php.lint.bounded-context` ✅ · `php.lint.event-bus` ✅ · `php.psalm.taint` ✅ · `pwa.test.unit` **1047/1047** ✅ · `pwa.quality` ✅ · `pwa.test.e2e` 149/158 (7 pasan en re-run solo; 2 flakes probados pre-existentes en `main` por A/B — ver Debug Log; CI es el gate e2e real).
9. **Mejoras boy-scout nombradas (dentro de ficheros ya tocados, para pasar PHPMD sin suprimir):** `Email::tryFrom()` (gemelo silencioso de `from()`); `User::isSuspended()` (accessor semántico; el muro graduado queda como método privado del use case — moverlo al aggregate disparaba el CBO de `User` a 14); `FixedClock::at()` (named constructor); `RecordingDomainEventHandlerDeduplicator` movido a `Tests\Unit\Shared\Event\Application` (fake de un port Shared con 2 consumidores); `DateInterval`→`modify()` en `UserCheckerTest`; patrón `expectException` + `try/finally` para los asserts post-excepción (sin catches vacíos). Única supresión nueva: `RequestPasswordResetControllerTest` CBO (teje el grafo real del use case `final` — espejo literal de la ya existente en `CompletePasswordResetTest`).
10. **Observaciones para Sergio (no tocadas — fuera de alcance):** (a) la description del `AccessWall INVALID_LINK` dice «Solicita una nueva invitación a tu administrador» — subóptima para reset (la salida natural sería `/forgot-password`), pero forkear la variante rompería la indistinguibilidad del muro: decisión de copy pendiente; (b) `iam_session` conserva `user_id` + device label tras `identity:gdpr:erase-subject` — candidato a follow-up GDPR (la story solo exigía los reset tokens); (c) check ops de la Decisión F: confirmar buzón monitorizado para `MAILER_SECURITY_FROM` antes de prod.

### File List

**API — nuevos:**
`api/src/Iam/Identity/Application/{PreIdentityTimingFloor,PasswordChangedEmailSender,EraseIdentitySubject,IdentityErasureResult}.php` ·
`api/src/Iam/Identity/Infrastructure/Security/{PasswordHashingTimingFloor,PasswordRecoveryThrottle}.php` ·
`api/src/Iam/Identity/Infrastructure/Mail/SymfonyPasswordChangedEmailSender.php` ·
`api/src/Iam/Identity/Infrastructure/Messenger/SendEmailOnPasswordResetCompleted.php` ·
`api/src/Iam/Identity/Infrastructure/Cli/{PruneExpiredPasswordResetTokensCommand,EraseIdentitySubjectCommand}.php` ·
`api/src/Iam/Invitation/Application/SendInvitationEmailBestEffort.php` ·
`api/src/Iam/Invitation/Infrastructure/Security/InvitationAcceptThrottle.php` ·
`api/src/Shared/Mailer/Infrastructure/{BulletproofEmailChrome,SecuritySenderAddress}.php`

**API — modificados:**
`api/src/Iam/Identity/Application/{RequestPasswordReset,CompletePasswordReset}.php` ·
`api/src/Iam/Identity/Domain/{Email.php,Entity/User.php,Entity/PasswordResetToken.php,Repository/UserRepository.php,Repository/PasswordResetTokenRepository.php}` ·
`api/src/Iam/Identity/Infrastructure/Security/{UserProvider,UserChecker}.php` ·
`api/src/Iam/Identity/Infrastructure/Http/{RequestPasswordResetController,CompletePasswordResetController}.php` ·
`api/src/Iam/Identity/Infrastructure/Persistence/Doctrine/{DoctrineUserRepository,DoctrinePasswordResetTokenRepository}.php` ·
`api/src/Iam/Invitation/Application/{AcceptInvitation,SendInvitation,ResendInvitation}.php` ·
`api/src/Iam/Invitation/Infrastructure/Http/AcceptInvitationController.php` ·
`api/src/Shared/Mailer/Infrastructure/SecurityLinkMailer.php` ·
`api/config/packages/{messenger,rate_limiter}.yaml` · `api/config/reference.php` (autogenerado) ·
`api/frankenphp/Caddyfile` · `api/.env` · `api/.env.example` · `api/docs/production-ready/secrets.md`

**API — tests:** nuevos
`PasswordHashingTimingFloorTest` · `CountingPreIdentityTimingFloor` · `PasswordRecoveryThrottleTest` ·
`InvitationAcceptThrottleTest` · `RequestPasswordResetControllerTest` · `SendEmailOnPasswordResetCompletedTest` ·
`RecordingPasswordChangedEmailSender` · `SymfonyPasswordChangedEmailSenderTest` · `BulletproofEmailChromeTest` ·
`SecuritySenderAddressTest` · `SendInvitationEmailBestEffortTest` · `EraseIdentitySubjectTest` ·
`EraseIdentitySubjectCommandTest` · `PruneExpiredPasswordResetTokensCommandTest` ·
`CaddyfileAccessLogRedactionGateTest` (paths en `api/tests/Unit/...` espejo de src); modificados
`UserProviderTest` · `UserCheckerTest` · `RequestPasswordResetTest` · `CompletePasswordResetTest` ·
`AcceptInvitationTest` · `SendInvitationTest` · `ResendInvitationTest` · `SecurityLinkMailerTest` ·
`SendEmailOnBankChangedTest` · `EmailTest` · `UserLifecycleTest` · `FixedClock` · `InMemoryUserRepository` ·
`InMemoryPasswordResetTokenRepository` ·
`api/tests/Unit/Shared/Event/Application/RecordingDomainEventHandlerDeduplicator.php` (movido desde Bank) ·
`api/tests/Functional/Iam/Identity/{DoctrineUserRepositoryTest,DoctrinePasswordResetTokenRepositoryTest}.php` ·
`api/tests/Behat/Context/RateLimitContext.php` ·
`api/features/backoffice/identity/{password_reset,invitation_accept}.feature`

**PWA:** `pwa/next.config.ts` · `pwa/src/app/(auth)/_components/{TokenActionScreen,ResetPasswordForm}.tsx` ·
`pwa/tests/app/(auth)/{tokenActionScreen,resetPasswordForm}.test.tsx` · `pwa/tests/next-config-headers.test.ts` (nuevo)

**Docs:** `PRODUCTION_SECURITY_CHECKLIST.md` · `docs/rules/security.md` · `docs/architecture-api.md` ·
`docs/architecture/event-catalog.md`

### Change Log

| Fecha       | Cambio |
|-------------|--------|
| 2026-07-14  | Story II-8 creada (ready-for-dev): endurecimiento transversal (7 workstreams: constant-time · higiene token · email async+password-changed · rate-limit neutral · concurrencia FOR UPDATE · retención/GDPR · cola D1-D3). Base `main` @ `85243d70` (post-#493). Análisis exhaustivo: 4 subagentes de código (timing backend · email/mailer · headers/rate-limit/logs · pantallas PWA) + 2 de consulta arquitectónica (Winston+Amelia sobre la superficie de email → C-refinado). 4 decisiones de alcance ratificadas por Sergio (AskUserQuestion): Absorb D1-D3+harden · email C-refinado · test estructural · incluir concurrencia+GDPR. #493 mergeado durante el análisis (D1-D3 en `main`). |
| 2026-07-14  | **Decisión H RATIFICADA por Sergio = opción (a)**: NO rutear `SendEmailMessage` (evita fugar el token al transporte, SI-13); token-emails síncronos best-effort, password-changed async vía reactor sobre `PasswordResetCompleted`. Rama de implementación autorizada: `feat/iam-ii8-hardening` (worktree `…-7e7r`). |
| 2026-07-14  | **Decisiones D/E/F/G CERRADAS por consulta 3-lentes (Winston · Amelia · ChatGPT).** D = **NO unique** (unánime; el FOR UPDATE serializa; unique redundante/landmine/no-testeable) + coste real: `findByEmailForUpdate` y mover el fetch DENTRO de la TX. E = **sibling `PasswordChangedEmailSender` + extraer chrome (`BulletproofEmailChrome`), NO duplicar NO ramificar el VO; estático + PII-free** — síntesis que corrige la restricción Sonar-dup de #493 («3.er email DEBE reusar el render») que las 3 lentes omitieron; reactor `SendEmailOnPasswordResetCompleted`, recipient in-módulo, `CompletePasswordReset` intacto. F = **`MAILER_SECURITY_FROM` dedicado** (AC3 fuerza valores distintos) + guard fail-loud. G = **Behat-API + unit PWA** documentado (unánime). |
| 2026-07-14  | **Implementación COMPLETA (dev-story, worktree `iam-ii8-hardening-7e7r`).** 7 workstreams cerrados: suelo constant-time transversal (puerto+adapter, INVITED+forgot, KDF diferido por closure) · higiene del token (no-referrer per-route, strip con captura blindada, redacción Caddy + gate test) · email endurecido (chrome extraído, `SecuritySenderAddress`, guard HTTPS, `MAILER_SECURITY_FROM`, reactor password-changed async, best-effort de invitación) · rate-limit neutral per-target al edge (forgot 202 silenciado + suelo; reset/accept muro opaco per-selector; 4 scenarios Behat con prime-steps) · FOR UPDATE (`findByIdForUpdate` DQL+HINT_REFRESH — el `find()` con lock sirve snapshot stale; probado contra Postgres con pg_locks) · retención/GDPR (prune command + `EraseIdentitySubject`+CLI con evidencia de auditoría) · cola D1-D3 (muro INVALID_LINK ya correcto → candados; Decisión G aplicada). Desviaciones argumentadas y observaciones para Sergio en Dev Agent Record. Gates: stan/test(286 Behat)/quality EXIT=0/error-contract/bounded-context/event-bus/psalm-taint/pwa unit 1047/pwa quality — todos verdes; e2e 149/158 con 2 flakes probados pre-existentes por A/B contra main. Status → review. | |
