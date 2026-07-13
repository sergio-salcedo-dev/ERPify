---
baseline_commit: 79b5669a
---
# Story II-4: `Invitation` + aceptación (1ª superficie pública nueva) + las 6 pantallas de acceso

Status: done

<!-- Validación opcional. Ejecuta `bmad-create-story` validate para un chequeo de calidad antes de dev-story. -->

## Story

Como **persona invitada a ERPify**,
quiero **aceptar mi invitación desde el email y definir mi contraseña en una sola pantalla**,
para **caer dentro del ERP ya operativa — sin «crear cuenta» — y sin que nunca se me revele por qué un enlace ya no sirve**.

## Contexto (leer antes de tocar código)

Esta es **II-4 (PR-4)** de la épica `identity-invitation-lifecycle` (orden de merge safe-first
`II-0 → II-1·II-2·II-3·II-7 → II-6 → II-4 → II-5 → II-8`). Es la **1ª superficie pública nueva** y el **primer
consumidor** de tres piezas ya mergeadas — **reutilízalas, no las reinventes**:

- **II-2 (`Shared/Token`, #466)** — `SingleUseToken` (entropía + hash-at-rest + verify constant-time). II-4 es su primer consumidor real.
- **II-3 (`IdentityStatus`/`UserChecker`, #467)** — `User::invite()` + `User::activate()` existen **sin caller**; II-4 es su primer consumidor real. Contrato de error graduado (`invalid-token` **explícitamente diferido a II-4**).
- **II-7 (`Iam/Session`, #469)** — `StartSession` acuña la primera `Session`; el gate fail-closed; `iamSessionId` en el bag.

II-6 (lockout) está en `review` y **no** es dependencia de II-4. Todas las dependencias de II-4 están en `main`.

Fuente de verdad del diseño (**no re-abrir, ya ratificado por Sergio**):
[`docs/adr/identity-invitation-lifecycle.md`](../../docs/adr/identity-invitation-lifecycle.md) **D5, D6, D11, D12** (contexto D3/D4/D8) ·
[`_bmad-output/planning-artifacts/arch-addendum-identity-invitation.md`](../planning-artifacts/arch-addendum-identity-invitation.md) **SI-13, SI-15, NFR3, PR-4** ·
[`_bmad-output/planning-artifacts/epics-identity-invitation-lifecycle.md`](../planning-artifacts/epics-identity-invitation-lifecycle.md) **Story II-4, líneas 745-799** (FR5/FR9) ·
run UX **`ux-ERPify-2026-07-06`** (`EXPERIENCE.md` = espina de comportamiento, `DESIGN.md` = delta visual de los 6 componentes).

**La frase que gobierna II-4:** el POST accept (ruta pública `PUBLIC_ACCESS` **dentro** del firewall `main`, no un firewall
aparte) valida el token y hace `User INVITED→ACTIVE (fija password) → Invitation ACCEPTED` **en una transacción de dominio**,
y **luego** `regenera el id de sesión → acuña la 1ª Session` (vía `Security::login`) — y **solo entonces**. Toda muerte de token (usado/revocado/caducado/aceptado/inexistente) colapsa a
**un único** muro opaco (`invalid-token` + «Este enlace ya no es válido»). El token **nunca** se renderiza.

**⚠️ II-4 es grande** (backend `Iam/Invitation` completo + accept público (dentro de `main`) + **CSRF stateless nativo** +
6 componentes/pantallas PWA + email de invitación + retiro de `/register`). Ver «Nota de tamaño / ejecución» antes de empezar:
tres decisiones de arquitectura de riesgo **medio** piden confirmación al inicio (Decisiones A/B/C).

## Acceptance Criteria

Los AC se redactan como **invariantes verificables** enganchados al ADR (D1–D12), a los System Invariants (SI-10…15) y a
las reglas de proyección (D-a/b/c), de modo que una refactorización futura no pueda romper una garantía sin que un test la
detecte.

1. **(Agregado · FR5/D5)** `Iam/Invitation` modela `Invitation(organizationId, invitedUserId, tokenHash, expiresAt,
   status: CREATED→SENT→ACCEPTED|REVOKED|EXPIRED)` como agregado libre de framework; el **token crudo nunca persiste** —
   solo su `tokenHash` (embebe `SingleUseToken`, II-2). Los **roles NO viven en `Invitation`** (delivery only; los roles
   están en `Membership`, II-1). Un test prueba las transiciones legales y el rechazo de las ilegales.

2. **(SI-10/D-c · admisión antes que sesión, en una transacción · NFR9)** Dado un token de invitación **válido** para
   `Invitation=SENT ∧ User=INVITED`, al aceptar, en **una sola transacción**: `User INVITED→ACTIVE`, password fijada,
   `Invitation ACCEPTED`, sesión **regenerada** y **1ª `Session` acuñada** — y **solo** entonces, **nunca antes** de que los
   flips de estado tengan éxito. El **retire-then-act es atómico**: si la escritura falla tras validar el token, el enlace
   **no** queda replayable a medias (contrato diferido de II-2). Un test verifica que un fallo a mitad no deja `Invitation`
   en `ACCEPTED` sin `User` `ACTIVE` (ni viceversa).

3. **(SI-13 · opacidad total — el invariante que II-4 establece)** Un token **no elegible** en **cualquiera** de los 5
   casos {usado, revocado, caducado, ya-aceptado, inexistente} produce **una sola** respuesta: `invalid-token` (RFC 9457) +
   muro `invalid-link` «Este enlace ya no es válido» — **byte-idéntica** (status + type + shape) en los cinco. Un test
   **compara los cinco** y exige respuesta idéntica; nunca se distingue el motivo; el email invitado **nunca** se muestra.

4. **(NFR3 · CSRF + Origin + regeneración — anti-fixation)** El POST accept, al ser una ruta **pública/pre-identidad**, exige
   **`Origin` same-origin** (patrón `LoginOriginListener`) **y** un **token CSRF válido** (double-submit stateless — II-4 es
   el **primer consumidor** que lo cablea). Sin ambos → rechazo **sin mutar estado** (403). Al pasar `INVITED→ACTIVE` se
   **regenera el id de sesión** (`migrate(true)`) antes de acuñar la `Session`. Un test verifica: (a) accept sin `Origin` →
   403; (b) accept sin CSRF → 403 sin mutación; (c) el id de sesión post-accept ≠ el previo.

5. **(SI-15/D3 · «ningún User ACTIVE sin token válido y sin Membership»)** Un `User` **nunca** llega a `ACTIVE` sin (a)
   consumir un token de invitación válido y (b) tener un `Membership` previo. La ruta de invitación **funnelea por
   `GrantMembership`** al crear la identidad invitada (contrato diferido de II-1). El grant duplicado concurrente **captura
   `UniqueConstraintViolationException` → re-lanza `UserAlreadyMember`** (contrato diferido de II-1). Un test cubre ambos.

6. **(FR9/D12 · `invalid-token` por el contrato de error)** El marker/tipo `invalid-token` fluye por el pipeline **RFC 9457**
   (nunca body manual), como **`DomainException implements <marker existente>` con `type()` override** (patrón II-3,
   **sin** marker interface nuevo → drift gate no dispara). [`docs/api-error-contract.md`](../../docs/api-error-contract.md)
   actualizado (NFR26) y `make php.lint.error-contract` verde. Status y marker concretos = **Decisión B**.

7. **(FR5 · invite / resend / revoke / expire)** Existen las operaciones: **invite** (crea `User::invite()` INVITED +
   `GrantMembership` + `Invitation` CREATED→SENT + envía el email) · **resend** (invalida el token previo → muro opaco al
   reusarlo, emite uno nuevo) · **revoke** (→ `REVOKED`) · lapso TTL (→ `EXPIRED`). La **UI de gestión de miembros está
   fuera de alcance** (contrato J5, slice diferido); la superficie de disparo de invite/resend/revoke = **Decisión D**.

8. **(NFR10/R1 · eventos de dominio)** Cada transición emite su evento (`InvitationCreated`, `InvitationSent`,
   `InvitationResent`, `InvitationRevoked`, `InvitationExpired`, `InvitationAccepted`) por el `EventBus`/outbox **dentro de
   la transacción** (patrón `wrapInTransaction`); `SessionStarted` lo emite `StartSession` (II-7). **Un accept fallido
   (`invalid-token`) emite 0 eventos y no muta estado** (test: vaciar outbox + reset stats antes, assert 0 después).

9. **(UX · las 6 pantallas/componentes)** Se cablean: **`TokenActionScreen`** (accept — campo único de contraseña con toggle
   **revelado por defecto**, token **nunca** renderizado, sin pérdida de datos ante caída de red) · **`AccessWall`** variante
   `invalid-link` (2 acciones: «Iniciar sesión» + «Solicitar nueva invitación», jamás revela motivo/email, **nunca** rojo) ·
   **`ConnectivityButton`** (idle→loading `aria-busy`→disabled-in-flight→retry idempotente, **sin toast de éxito**) ·
   **`OfflineNotice`** (`aria-live="polite"`, `{color.warning}` no rojo, conserva lo tecleado) · **`SecuritySignal`**
   `invitation-accepted` (foco al `<h1>` en la transición SPA) · **`SecurityEmail`** invitación (plantilla bancaria, un
   enlace bulletproof, dark-mode-aware). Todos cumplen el **suelo AA** (UX-DR9): documentos-no-diálogos (sin focus-trap),
   un solo `<h1>`, foco-return al 1.er campo inválido, color nunca canal único, `.max()` en Zod **nunca `maxLength`**.

10. **(UX-DR7 · retiro de `/register`)** La ruta mock `/register` (alta libre) se **retira** (invitation-first): borrar la
    ruta/form/schema, `Routes.REGISTER`, el enlace «Create account» de `LoginForm`, y editar `schemas.test.ts`.

**Invariante rector de no-regresión (transversal):** `make app.test` + `make app.quality` verdes; el login de un `ACTIVE`
sigue devolviendo **204 + cookie** intacto (II-3); el `SessionAdmissionGate` (II-7) sigue admitiendo la nueva `Session`; los
demás muros (`suspended`/`deactivated`/`locked`/`session-expired`) no regresan; **cero credenciales/PII/tokens en migración,
logs o respuestas**.

## Tasks / Subtasks

> **Sugerencia de paralelización (CLAUDE.md):** el backend (T1–T8) y el frontend (T9–T13) apenas comparten estado —
> el contrato es el body/`type` del endpoint accept. Un subagente API + un subagente PWA en paralelo es legítimo **una vez
> fijado ese contrato** (path, 204-vs-body, los `type` de `invalid-token`). No paralelizar antes.

### Backend — `Iam/Invitation` + accept público (dentro del firewall `main`)

- [x] **T1 — Agregado `Invitation` + eventos + repo (AC1, AC8)**
  - [x] `api/src/Iam/Invitation/Domain/Entity/Invitation.php` — `final class Invitation extends AggregateRoot`,
        `#[ORM\Table(name: 'iam_invitation')]`. Props por id: `organizationId`, `invitedUserId`, `tokenHash` (string),
        `expiresAt` (timestamptz), `InvitationStatus $status`. Refs cross-módulo **por id** (`Uuid::ensure()` en el borde),
        **nunca** `#[ORM\ManyToOne]`. Factory `static create(...)` (→ `CREATED`, graba `InvitationCreated`); transiciones
        `markSent()`, `accept()` (`SENT→ACCEPTED`, graba `InvitationAccepted`), `revoke()` (`→REVOKED`), `expire()`; guardas
        de máquina rechazan transiciones ilegales con excepción de dominio (mirror `InvalidIdentityTransition` de II-3,
        `implements Conflict` → 409).
  - [x] `api/src/Iam/Invitation/Domain/Enum/InvitationStatus.php` — backed enum puro `{CREATED, SENT, ACCEPTED, REVOKED, EXPIRED}`.
  - [x] Eventos en `api/src/Iam/Invitation/Domain/Event/` — subclases de `DomainEvent`, `eventName='erpify.iam.invitation.<fact>'`,
        `aggregateType='Iam.Invitation'`, **payload PII-free** (id + status, **nunca** email ni token). Si acaban ≥3 sobres
        con snapshot compartido → trait por-módulo (`CarriesInvitationSnapshot`, Regla-de-Tres **por módulo**, NO unificar con
        Session/Identity — ver memoria `bankaccount-event-envelope-trait-vs-bank`).
  - [x] Puerto `api/src/Iam/Invitation/Domain/Repository/InvitationRepository.php` + adapter Doctrine
        `.../Infrastructure/Persistence/Doctrine/DoctrineInvitationRepository.php` (composición sobre EM, `#[AsAlias]`, mirror
        `DoctrineSessionRepository`). Métodos: `save`, `findById`, y **`findActiveByTokenHash(string $hash): ?Invitation`**
        (o el lookup que use el accept — ver Decisión sobre cómo el token localiza la invitación en T3).
  - [x] `make db.diff` → migración `api/migrations/2026/` (tabla `iam_invitation`, índice sobre `token_hash` y/o
        `invited_user_id`). `down()` reversible. **Cero PII/credenciales/token crudo** en el schema. Editable en esta rama.

- [x] **T2 — Caso de uso invite (AC5, AC7) — funnel por `GrantMembership`, captura de unicidad**
  - [x] `api/src/Iam/Invitation/Application/InviteUser.php` (o `SendInvitation`) en un `TransactionManager::transactional`:
        `User::invite($id, $email, ...$roles)` → **`GrantMembership->grant($userId, ...$roles)`** (funnel obligatorio, AC5) →
        `Invitation::create(...)` con `SingleUseToken::mint($clock->now()->add(TTL))` → `markSent()` → guardar los 3 agregados
        + `eventBus->publish(...pullDomainEvents())` de cada uno → enviar `SecurityEmail` (invitación) con el **plaintext**
        del `GeneratedToken` (`->plaintext()`) en el enlace. El plaintext **no se persiste ni se loggea** (`GeneratedToken`
        lo guarda `#[SensitiveParameter]`).
  - [x] **Fix del contrato diferido de II-1** en `GrantMembership::grant()` (`api/src/Organization/Membership/Application/GrantMembership.php:42`):
        capturar `UniqueConstraintViolationException` → re-lanzar `UserAlreadyMember` (grant concurrente idempotente).
  - [x] TTL de invitación = política de II-4 (p.ej. 72h) — constante nombrada, **no** magic number.

- [x] **T3 — Caso de uso accept (AC2, AC3, AC4) — el corazón de II-4**
  - [x] `api/src/Iam/Invitation/Application/AcceptInvitation.php` — orquesta, en **una** `transactional`:
        1. localizar la `Invitation` por el token presentado (hash del plaintext, o lookup por id + `verify`), validar
           `verify($plaintext, $now)` **y** `status==SENT`;
        2. cargar el `User` (`UserRepository::findById($invitation->invitedUserId())`);
        3. `HashedPassword::fromHash($passwordHasher->hash($plainPassword))` (reusar `PasswordHasher` de II-3);
        4. `user->activate($hashedPassword)` (INVITED→ACTIVE, II-3);
        5. `invitation->accept()` (SENT→ACCEPTED — **retire-then-act atómico**: el estado `ACCEPTED` ES el consumo del
           single-use; contrato diferido de II-2);
        6. `save` User + Invitation, `eventBus->publish(...)` de ambos — **todo dentro del mismo `transactional`**.
    - [x] Cualquier fallo de validación (token no elegible en los 5 casos, status≠SENT, User no INVITED) → lanzar la excepción
          **`invalid-token`** (T5) **antes** de mutar nada. **Opacidad total (AC3):** los 5 caminos lanzan la **misma**
          excepción con el **mismo** mensaje — no ramificar el motivo.
  - [x] **Establecimiento de sesión + anti-fixation (NFR3) = A1 (ratificada)** — tras el **commit** de los flips,
        `Security::login($securityUser, 'main')` dispara `LoginSuccessEvent` → `SessionMintingSuccessListener` acuña la 1.ª
        `Session` + el `migrate(true)` nativo regenera el id. `Security::login` va **fuera** del `wrapInTransaction`, **tras**
        el commit de dominio (los flips + el guard `status==SENT` + retire-then-act van **dentro** y **antes**). Ver «El crux:
        establecer la sesión tras el accept».

- [x] **T4 — Controlador accept + ruta pública + Origin (AC2, AC4)**
  - [x] `api/src/Iam/Invitation/Infrastructure/Http/AcceptInvitationController.php` — `AbstractController` fino, POST, DTO de
        request con `#[MapRequestPayload]` + `#[Assert\…]` (token + password; el password valida longitud/policy en el DTO,
        el dominio revalida). Delega en `AcceptInvitation`. Respuesta de éxito: **decidir 204-vs-body** y fijarlo como el
        contrato que consume el PWA (recomendado 204 + cookie de sesión, espejo del login).
  - [x] Ruta: bloque `resource` nuevo en `api/config/routes.yaml` apuntando a `../src/Iam/Invitation/Infrastructure/Http/`
        con `defaults: { _format: json }` y prefijo adecuado (p.ej. `/api/v1/backoffice`, mirror del login que confina su URL).
  - [x] **`security.yaml`:** añadir una entrada `access_control` `PUBLIC_ACCESS` para la ruta accept **antes** del catch-all
        `^/api → IS_AUTHENTICATED_FULLY`. El accept es pre-identidad pero **dentro** del firewall `main` (ruta pública, **no**
        un firewall `security:false` — `Security::login` de A1 necesita el firewall resuelto).
  - [x] Origin check: listener espejo de `LoginOriginListener` **keyed en el nombre de ruta del accept** (o generalizar el
        existente a un conjunto de rutas). `Origin !== getSchemeAndHttpHost()` → `AccessDeniedHttpException` (403 `forbidden`).

- [x] **T5 — `invalid-token` en el contrato de error (AC3, AC6) = Decisión B**
  - [x] `api/src/Iam/Invitation/Domain/Exception/InvalidToken.php` (o `InvalidInvitationToken`) —
        `final class … extends DomainException implements <marker existente>` con `type()` override → `'invalid-token'`.
        **Sin marker interface nuevo** (vive en `Iam/Invitation`, no en `Shared/ErrorContract` → el `ErrorContractGateTest`
        git-aware no dispara; pero **igual** actualiza el doc). **Decisión B ratificada: 400 `InvalidInput`** (`implements
        InvalidInput`, precedente `InvalidUuidException`; status **uniforme** en los 5 casos = opacidad).
  - [x] Actualizar [`docs/api-error-contract.md`](../../docs/api-error-contract.md): documentar `invalid-token` (el `type` ya
        está **reservado** en el doc por II-3 como «out of scope here» — ahora se realiza), su status, y que es
        **pre-identidad opaco** (los 5 casos colapsan). `make php.lint.error-contract` verde.

- [x] **T6 — CSRF stateless (AC4) — Decisión C = Opción 1 (nativo Symfony 8), ratificada**
  - [x] Registrar el token id del accept en `framework.csrf_protection.stateless_token_ids` (nativo; **nunca** hand-rolled).
        El **check Origin-primary + token stateless = parte load-bearing** (unit/Behat-verificable; honra el «Origin **AND**
        CSRF» de D5). El stateless CSRF de Symfony 8 **no requiere sesión** y es Origin/Referer-primary → encaja en el accept
        pre-identidad; `reference.php` documenta el esquema. **Dev-verify:** ¿el recipe Flex dejó un `config/packages/csrf.yaml`
        parcial (stateless on-by-default) o está ausente?
  - [x] `check_header:true` (cookie+header double-submit, JS-generado al submit — **no** sembrado por un GET) = **defense-in-
        depth OPCIONAL**, la **única** parte solo-navegador → **Playwright**, **no bloqueante** (habilitar o diferir sin
        incumplir D5). El CSRF aquí es **defense-in-depth, NO el control primario** (primarios = Origin + `SingleUseToken`
        opaco) — no debilitar el Origin check. Consolidar `LoginOriginListener` con el check nativo = **follow-up**, no II-4.
        El **login POST** entra en alcance CSRF (epic Additional) — confirmar si se cablea aquí o se deja preparado (no romper
        `login→204`).

- [x] **T7 — `SecurityEmail` invitación (AC9) — plantilla, no componente React**
  - [x] Plantilla del email de invitación (Twig/PHP mailer, `symfony/mailer` — **ya async vía Messenger** por defecto en este
        stack). Contrato UX-DR6: cabecera plana sin hero · una frase de propósito · **un** enlace bulletproof (estilos inline,
        `«Aceptar invitación»`, área táctil grande) · pie legal mínimo · **remitente NO `no-reply`** · pila de sistema ·
        dark-mode-aware con literales `#2f5cd9`/`#6c9bff` · `lang="es"` · fallback de URL en texto plano. El enlace lleva el
        **plaintext** del token (`?token=`); el token **nunca** viaja en logs. **Endurecimiento (async explícito, escape,
        headers, redacción) = II-8** — aquí solo la plantilla + el envío.

- [x] **T8 — Deptrac seams + Behat (AC2–AC8)**
  - [x] `Iam/Invitation` **ya está registrado** en `api/tools/deptrac/deptrac.yaml` (esqueleto de II-0) → **no** hay capa
        nueva. Pero los imports cross-contexto del invite/accept (`Iam/Invitation → Organization\Membership\Application\{GrantMembership,FindUserOrganizationId}`,
        `→ Iam\Identity` repo/hasher, `→ Iam\Session\Application\StartSession`) son violaciones **Nivel-1** salvo allowlist:
        añadir entradas **per-file** en `api/.bounded-context-allowlist` **y** deptrac `skip_violations` (espejo de las de II-7;
        **nunca** forma global `* =>` — `DeptracSeamSyncGateTest` la prohíbe; contexto = módulo de 2 niveles).
  - [x] Behat: nueva feature `api/features/backoffice/identity/invitation.feature` (o `invitation/accept.feature`) — accept
        válido → 204 + cookie + `User` ACTIVE + `Invitation` ACCEPTED; los **5 casos de token muerto** → **idéntico**
        `invalid-token` (comparación cara a cara, AC3); accept sin `Origin` → 403; sin CSRF → 403 sin mutación; **0 eventos**
        en el accept fallido (vaciar outbox + reset stats antes). **Presupuesto de queries +2 por escritura envuelta**
        (BEGIN/COMMIT) — el accept hace varias.

### Frontend — la superficie pública de acceso (6 componentes)

- [x] **T9 — Ruta `accept-invitation` + `TokenActionScreen` (AC9)**
  - [x] `pwa/src/app/(auth)/accept-invitation/page.tsx` — **clon de `reset-password/page.tsx`**: RSC que envuelve el form en
        `<Suspense>` (el form lee `?token=` con `useSearchParams`, obligatorio bajo Suspense en Next 16). Hereda `AuthLayout`
        (card centrada + `noindex`) por vivir en `(auth)/`.
  - [x] `pwa/src/app/(auth)/_components/TokenActionScreen.tsx` (variante accept) — **clon de `ResetPasswordForm.tsx`**:
        `const token = useSearchParams().get("token")`; si `!token` → `<AccessWall variant={INVALID_LINK} />`. **Campo único de
        contraseña con toggle revelado por defecto** (no hay primitivo de reveal → crear con `useState` + `Eye`/`EyeOff` de
        `lucide-react`, toggle ≥44px con `aria-pressed` y nombre accesible estático «Mostrar/Ocultar contraseña»). Submit vía
        `ConnectivityButton`. Éxito → `SecuritySignal` invitation-accepted → navegar al ERP (`safeHref`). **Token nunca
        renderizado.** Anunciar contexto de página antes del autofocus (`aria-describedby` con reglas + propósito).

- [x] **T10 — Caso de uso accept en el cliente (AC9) — puerto + adapter (DIP)**
  - [x] **Clon del patrón login (`context/backoffice/user/`):** puerto `domain/AcceptInvitationRepository.ts` +
        `domain/AcceptInvitationOutcome.ts` (unión discriminada rutada por `problem.type`, **no** por status solo) +
        `infrastructure/ApiAcceptInvitationRepository.ts` (`@injectable`, `@inject("HttpClient")`, mapea 204→aceptado /
        `invalid-token`→muro / re-lanza no-`HttpError`). Registrar 1 binding en `Container.ts` (símbolo string) + 1 entrada en
        `ApiEndpoints.ts` + `Routes.ACCEPT_INVITATION`.
  - [x] **`Origin` gratis:** `FetchHttpClient.post` no setea `Origin` — el navegador lo manda solo en same-origin, y la cookie
        same-origin fluye sola → el requisito Origin se cumple **sin código extra** (mirror del login). El **CSRF double-submit**
        (T6) sí puede necesitar leer una cookie y reenviarla como header — coordinar con backend.
  - [x] Si el accept puede devolver 401/403, **añadir su endpoint a `isAuthHandshakeEndpoint`** en `FetchHttpClient.ts` para
        que un fallo **no** rebote a `/login?reason=session-expired`.
  - [x] Envelope: si el éxito devuelve body (no 204), **guardar el envelope `{data}`** y destructurar (bug #488 — validar la
        forma antes de leer).

- [x] **T11 — `AccessWall` variante `invalid-link` (AC3, AC9)**
  - [x] `pwa/src/context/shared/error/infrastructure/ui/AccessWall.tsx` — **añadir `INVALID_LINK: "invalid-link"`** al objeto
        `AccessWallVariant` (fuerza en compile-time una entrada `COPY` nueva por ser `Record<Variant,…>`). Copy Spanish exacta:
        título **«Este enlace ya no es válido»**, cuerpo **«Solicita una nueva invitación a tu administrador para continuar.»**,
        2 acciones: **«Iniciar sesión»** (`Routes.LOGIN`, primaria) + **«Solicitar nueva invitación»**. Tono neutro
        (`bg-muted`, **nunca** danger); foco al `<h1>` ya implementado. **Nota i18n:** el `AccessWall` actual trae copy en
        **inglés** (`suspended`/`deactivated`/`locked`) — la espina exige **español, ninguna cadena hardcodeada**; reconciliar
        el gap i18n al añadir la variante (o al menos dejar la nueva en español + flag del gap).
  - [x] Añadir el caso `invalid-link` al `it.each` de `tests/context/shared/error/infrastructure/ui/AccessWall.test.tsx`.

- [x] **T12 — `ConnectivityButton` + `OfflineNotice` + hook de conectividad (AC9)**
  - [x] **No existen** (ni `navigator.onLine`/`useOnlineStatus`). Crear:
        - Hook de estado online (nueva capacidad `pwa/src/context/shared/connectivity/infrastructure/…`, hook puro).
        - `ConnectivityButton` (componente que compone el Brand `Button`; máquina `idle→loading[spinner, label «Enviando…»
          persiste, `aria-busy`]→disabled-in-flight[bloquea doble envío]→retry[idempotente]`; conserva foco; **sin toast de
          éxito**). Ubicación = `components/erpify` (primitivo reutilizable) — confirmar frontera `components/{ui,erpify}`.
        - `OfflineNotice` (banda in-form **sobre** el botón, `aria-live="polite"`, `{color.warning}`/`-strong` **no rojo**,
          copy **«Sin conexión. Reintenta cuando recuperes señal.»**, conserva lo tecleado).

- [x] **T13 — `SecuritySignal` + retiro de `/register` (AC9, AC10)**
  - [x] `SecuritySignal` (invitation-accepted) — **no existe** ningún componente de éxito. Card dentro de `AuthLayout`: dot
        `{color.success}` (sin animación), `<h1>`, copy **«Invitación aceptada. Ya puedes empezar a trabajar.»**, acción
        primaria = entrar al ERP. **Mover el foco al `<h1>`** en la transición SPA (no se reubica solo). Ubicación =
        `components/erpify` vs `context/shared/<capability>/…` → confirmar (frontera de componentes no autorizada).
  - [x] **Retirar `/register`:** borrar `app/(auth)/register/page.tsx`, `_components/RegisterForm.tsx`,
        `context/backoffice/user/application/schemas/auth/RegisterSchema.ts`, `Routes.REGISTER`, el `<Link>` «Create account»
        de `LoginForm.tsx` (líneas ~131-133), y **editar** (no borrar) `tests/context/backoffice/user/schemas.test.ts` (quitar
        los casos `RegisterSchema`). **Antes de borrar**, copiar el patrón `.refine` de confirmación de password al schema de
        accept si se usa doble campo — **pero la UX manda campo ÚNICO** con toggle, así que el schema de accept clona
        `ResetPasswordSchema` (min/max de `passwordPolicy.ts`) **sin** el `confirmPassword`.
  - [x] Schema de accept en `context/…/application/schemas/…` — límites en **`.max()`** (nunca `maxLength`); mensajes espejo de
        los 422 del API. Añadir sus casos a `schemas.test.ts`.

- [x] **T14 — Tests (todos los AC)** — ver «Testing».
- [x] **T15 — Gates + verificación fresca** — `make php.behat.install` (worktree fresco) → `make php.stan` (por fichero; exit
      139 → `PHP_SERVICE=messenger_worker`) → `make php.test` → `make php.quality` EXIT 0 → `make php.lint.error-contract` →
      `make php.lint.bounded-context` → `make php.deptrac` → `make php.psalm.taint` → `make pwa.quality` → `make pwa.test`.
      Verificar sobre el **path del worktree**, confiar en el exit code recién impreso.

### Review Findings

Code review (2026-07-13, 3 capas adversariales + triaje leyendo el código). **6 patches aplicados · 6 diferidos · 6
descartados como ruido.** Todos los gates verdes en fresco (`php.stan`, `php.quality` EXIT 0, `php.lint.error-contract`,
`php.test` PHPUnit+Behat 274 esc., `php.psalm.taint`, `pwa.quality`, `pwa.test.unit` 1011). Ningún AC violado en código
(el auditor confirmó AC1–AC10 satisfechos); los hallazgos fueron un race de concurrencia + endurecimiento + cobertura.

Patches aplicados:

- [x] **[Review][Patch] Race de doble-accept: single-use no serializado bajo concurrencia** — `findByIdForUpdate` (lock
      pesimista `SELECT … FOR UPDATE`) sobre la invitación dentro del `transactional`, así dos accepts concurrentes del
      mismo token serializan y el perdedor relee `ACCEPTED` → `InvalidToken`. Cierra el «no dos Session / no doble activate»
      de AC2/NFR9. [`api/src/Iam/Invitation/Application/AcceptInvitation.php`, `…/Domain/Repository/InvitationRepository.php`,
      `…/Infrastructure/Persistence/Doctrine/DoctrineInvitationRepository.php`]
- [x] **[Review][Patch] Opacidad estructural: `activate()` filtraba un 409 distinguible** — guard `IdentityStatus::INVITED
      !== $user->status()` antes de `activate()`, colapsa una identidad des-sincronizada al muro opaco (AC3). Seam
      registrado en `.bounded-context-allowlist` + `deptrac.yaml`. [`api/src/Iam/Invitation/Application/AcceptInvitation.php`]
- [x] **[Review][Patch] Barrido de IDs de story/NFR/AC en comentarios de código** (SI-13, NFR10, AC5/AC7, Decision A1,
      «deferred to II-8»). [8 ficheros `api/src` + 3 tests]
- [x] **[Review][Patch] Docblock «retire-then-act» corregido** para describir el orden real (act-then-retire) y el lock.
      [`api/src/Iam/Invitation/Application/AcceptInvitation.php`]
- [x] **[Review][Patch] `#[SensitiveParameter]` en el entry-path del token** (`accept()` + DTO token/password).
      [`AcceptInvitation.php`, `AcceptInvitationRequest.php`]
- [x] **[Review][Patch] Unit test de `TokenActionScreen`** (sin token→muro, token nunca renderizado, 204→login+éxito,
      token muerto→muro, 422→error de campo, transporte→error neutro). [`pwa/tests/app/(auth)/tokenActionScreen.test.tsx`]
      + unit test del guard de opacidad. [`api/tests/Unit/Iam/Invitation/Application/AcceptInvitationTest.php`]

Diferidos (Low; registrados en `deferred-work.md`): test negativo de CSRF (no escribible con `check_header` off), assert de
regeneración de id de sesión (nativo del framework, cobertura parcial ya existe), KDF antes del check de token (rate-limited),
re-probe `/me` tras 204, test de fallo a-mitad (atomicidad estructural), fixture `REVOKED` distinto.

## Dev Notes

### Mapa de reutilización (NO reinventar — verificado en `main`)

| Necesidad II-4 | Reutiliza (existe) | Ubicación |
|---|---|---|
| Token single-use | `SingleUseToken::mint($expiresAt): GeneratedToken` / `::fromHash($hash,$exp)` / `->verify($plain,$now): bool` / `GeneratedToken->plaintext()` `->token` | `api/src/Shared/Token/Domain/` |
| INVITED→ACTIVE | **`User::invite($id,$email,...Role)`** + **`User::activate(HashedPassword)`** (existen, sin caller — II-4 es el 1.º) | `api/src/Iam/Identity/Domain/Entity/User.php` |
| Hash de password | `PasswordHasher::hash(#[SensitiveParameter] string): string` → `HashedPassword::fromHash(...)` | `api/src/Iam/Identity/Infrastructure/Security/PasswordHasher.php` |
| Cargar user | `UserRepository::findById(string): ?User` | `api/src/Iam/Identity/Domain/Repository/` |
| Membership | `GrantMembership::grant($userId, ...Role): Membership` (funnel AC5) · `FindUserOrganizationId::of($userId): string` | `api/src/Organization/Membership/Application/` |
| Acuñar sesión | `StartSession::start($userId,$organizationId,$device,?$ip): SessionId` (transaccional, escribe `iamSessionId` tras commit) | `api/src/Iam/Session/Application/StartSession.php` |
| Correlación sesión | `CurrentSessionReference` (port) / `SymfonySessionCorrelationStore` (`iamSessionId` bag) | `api/src/Iam/Session/…/Security/` |
| Org tras accept | `FindUserOrganizationId` (mismo seam que `SessionMintingSuccessListener`) | `api/src/Organization/Membership/Application/` |
| Transacción + eventos | `TransactionManager::transactional(cb)` + `EventBus::publish(...pullDomainEvents())` (patrón `ChangeUserStatus`/`StartSession`) | `api/src/Shared/{Persistence,Event}/` |
| Origin check | `LoginOriginListener` (kernel.request prio 9, `Origin===getSchemeAndHttpHost()`) — **espejar keyed en ruta accept** | `api/src/Iam/Identity/Infrastructure/Http/LoginOriginListener.php` |
| Contrato de error | `DomainException implements <marker>` + `type()` override (patrón `AccountSuspended` de II-3) | `api/src/Shared/ErrorContract/` |
| Login mint (referencia) | `SessionMintingSuccessListener` (`LoginSuccessEvent` prio −128 → org+device+`StartSession`, fail-closed) | `api/src/Iam/Identity/…/Security/` |
| **PWA** login (clon) | `LoginRepository`/`ApiLoginRepository` (port+adapter, ruteo por `problem.type`) · `ResetPasswordForm.tsx` (token `?token=`, Suspense) · `AccessWall.tsx` (per-variant action stack) · `useZodForm` · `FormField`/`Input`/`Button`/`Spinner`/`Logo`/`ProblemDisplay`/`MutationError`/`safeHref`/`safeInternalPath` | `pwa/src/context/…`, `pwa/src/components/…` |

**NET-NEW (crear):** `Iam/Invitation` completo · el listener/wiring CSRF stateless · `TokenActionScreen` · `SecuritySignal` ·
`ConnectivityButton` · `OfflineNotice` · hook de conectividad · reveal de password · plantilla `SecurityEmail`. El
`AccessWall` **existe** pero sin variante `invalid-link` (añadir).

### Ficheros a tocar (estado actual verificado)

**API — nuevos bajo `Iam/Invitation/` (hoy solo `.gitkeep`):** `Domain/{Entity/Invitation, Enum/InvitationStatus,
Event/*, Exception/{InvalidToken,InvalidInvitationTransition}, Repository/InvitationRepository}`, `Application/{InviteUser,
AcceptInvitation, RevokeInvitation, ResendInvitation}`, `Infrastructure/{Http/{AcceptInvitationController,
AcceptInvitationOriginListener}, Persistence/Doctrine/DoctrineInvitationRepository, Mail/…}`.

**API — modificar:** `config/packages/security.yaml` (access_control accept `PUBLIC_ACCESS`) · `config/packages/framework.yaml`
(csrf_protection — Decisión C) · `config/routes.yaml` (resource block accept) · `api/tools/deptrac/deptrac.yaml` +
`api/.bounded-context-allowlist` (seams per-file) · `docs/api-error-contract.md` (`invalid-token`) ·
`Organization/Membership/Application/GrantMembership.php` (captura unicidad, AC5) · migración nueva · fixtures (una `Invitation`
`SENT` + su `User` INVITED + `Membership`, para Behat/unit).

**PWA — nuevos:** `app/(auth)/accept-invitation/page.tsx`, `_components/TokenActionScreen.tsx`, el schema de accept, el
port/adapter accept, `SecuritySignal`, `ConnectivityButton`, `OfflineNotice`, hook conectividad, reveal password.
**PWA — modificar:** `AccessWall.tsx` (+`invalid-link`) · `Container.ts` (binding) · `ApiEndpoints.ts` (endpoint) ·
`Routes.ts` (+`ACCEPT_INVITATION`, −`REGISTER`) · `FetchHttpClient.ts` (handshake endpoint) · `LoginForm.tsx` (quitar link
register). **PWA — borrar:** `register/page.tsx`, `RegisterForm.tsx`, `RegisterSchema.ts`.

### El crux: establecer la sesión tras el accept (NFR3 · Decisión A = A1, ratificada)

**Corrección de encuadre:** el accept **NO está fuera del firewall** — es una ruta `PUBLIC_ACCESS` **dentro** del firewall
`main` (catch-all sin `pattern`), igual que `/login`. Por eso `Security::login` resuelve el firewall y dispara
`LoginSuccessEvent`. (Lo que el ADR D5 llama «fuera del firewall» = fuera del flujo `json_login`, no fuera del mapa de firewalls.)

**A1 (ratificada):** tras el commit de los flips de dominio, `Security::login($securityUser, 'main')` (fijar el firewall
explícito evita el `LogicException` si mañana hay >1 authenticator) dispara `LoginSuccessEvent` → **reutiliza**
`SessionMintingSuccessListener` (acuña la `Session` + resuelve org/device, fail-closed) **y** el `migrate(true)` anti-fixation
nativo (prio 0; el minting a −128 corre después y el `iamSessionId` sobrevive). DRY sobre el TCB de sesión; A2 (manual
`migrate`+`StartSession` directo) descartado por duplicar el invariante NFR3 y la orquestación.

**Ordering (el mayor riesgo de implementación, ahora fijado):** el **retire-then-act** (`invitation->accept()` = consumo del
single-use) + los flips de `User`/`Invitation` van **dentro** del `wrapInTransaction` y **antes** del login, con guard
`status==SENT` + captura de unique-constraint (contra doble-accept concurrente → doble sesión); `Security::login` se llama
**tras** el commit de dominio (fuera del `wrapInTransaction`). Son **2 transacciones** (TX1 dominio; TX2 mint de `Session`, en
el `transactional` propio de `StartSession`) — **correcto** por fronteras de agregado (forzar 1 sola TX acoplaría la
persistencia de `Session` a la de `Invitation`), no un defecto. La no-atomicidad residual (TX1 commit + mint 503) deja
«usuario `ACTIVE` sin sesión» = estado **benigno recuperable** (entra por login normal con el password recién fijado);
documentarlo, no ingeniar contra ello.

**Verificar (empírico, no diseño):** que `Security::login` dispara `LoginSuccessEvent` **una sola vez** y el orden
migrate(0)→mint(−128) se respeta como en `json_login`. **La regeneración del id es AC (NFR3), no opcional** — test Behat
compara la cookie de sesión pre/post + asserta **1** fila `Session` + **1** `SessionStarted` (el conteo exacto caza el
doble-minting silencioso).

### El crux: CSRF stateless (Decisión C = Opción 1, ratificada · honra el ADR D5, sin desviación)

Verificado en la doc de Symfony 8 (Context7): el stateless CSRF **no requiere sesión** y su check **same-origin (Origin/Referer)
es el mecanismo PRIMARIO**; el double-submit cookie+header (`check_header`) es **opcional/off-by-default**, con el token
**generado en JS al submit** — **no** sembrado por un GET previo. Por eso funciona en un landing en frío desde el email y el
temor al bootstrap se disuelve. El downgrade-guard («una vez probado, se exige») solo aplica **si ya hay sesión** → no muerde
al accept pre-identidad. `framework.yaml` no tiene `csrf_protection` hoy; `reference.php` documenta el esquema
(`stateless_token_ids`, `check_header`, `cookie_name: "csrf-token"`).

- **Parte load-bearing (unit/Behat-verificable):** registrar el token id del accept en `framework.csrf_protection.stateless_token_ids`
  → check Origin-primary + token stateless. Satisface el «Origin **AND** CSRF token» de D5.
- **Defense-in-depth OPCIONAL (solo-navegador → Playwright, no bloqueante):** `check_header:true` (cookie+header double-submit,
  JS-generado al submit). Puede habilitarse o diferirse sin incumplir D5.
- **Nunca hand-rolled** (C2 descartado): el double-submit completo = generación+almacén+compare constant-time, justo lo que no
  se escribe a mano.

**Framing honesto (ratificado):** el CSRF aquí es **defense-in-depth, NO el control primario** — los primarios son el check
Origin + el `SingleUseToken` opaco (que un atacante CSRF no posee; y la sesión se acuña para la **propia** invitada, así que el
forced-login clásico no aplica). **Documentarlo** para que nadie debilite el Origin check «porque ya hay CSRF». El check
same-origin nativo **subsume** a `LoginOriginListener` → su **consolidación es follow-up**, no II-4 (no romper el login
`ACTIVE→204`). El **login POST** entra en alcance CSRF (epic Additional) — confirmar si se cablea aquí o se deja preparado.

### El crux: opacidad total del token (SI-13 · el invariante que II-4 establece)

Los **5** caminos de muerte del token (usado, revocado, caducado, ya-aceptado, inexistente) deben devolver una respuesta
**byte-idéntica**: mismo status, mismo `type` (`invalid-token`), misma shape. **No ramificar el motivo en ninguna capa** —
ni en el mensaje, ni en el código, ni en logs. El `AcceptInvitation` lanza **una sola** excepción `InvalidToken` para los
cinco; el `AccessWall` `invalid-link` es idéntico; el email invitado **jamás** se muestra. **Test que lo blinda:** compara
las cinco respuestas cara a cara y exige identidad (Behat, patrón del `login.feature` que compara pre-identidad). El terse
«Este enlace ya no es válido» es aceptable **porque** el muro **siempre** ofrece salida («Iniciar sesión» + «Solicitar nueva
invitación», regla D-a). **Higiene de URL (no-referrer, strip `history.replaceState`, redacción en logs) = II-8** — aquí solo:
el token **nunca se renderiza** y **nunca se persiste crudo**.

### El crux: retire-then-act atómico (idempotencia · contrato diferido de II-2)

`SingleUseToken::verify()` **no** fuerza el single-use — devuelve `true` repetidamente hasta el TTL (el single-use es
lifecycle del consumidor = `Invitation.status`). **Contrato:** el accept debe **consumir la invitación (`→ACCEPTED`) en la
MISMA transacción** que los flips de `User`. Si valida el token y luego falla **antes** de marcar `ACCEPTED`, el enlace queda
replayable dentro del TTL. Por eso los pasos 4–6 de T3 van todos dentro de **un** `transactional`. **Idempotencia (NFR9/AC2):**
un reintento tras caída de red → o el token ya se consumió (`ACCEPTED` → muro opaco con «Iniciar sesión», D-a) o sigue `SENT`
(→ reintento válido); **nunca** un efecto duplicado (no dos `Session`, no doble `activate`).

### Persistencia (ya decidida, no re-abrir)

`Invitation` es **state-oriented** (ADR **D5**: `status: CREATED→SENT→ACCEPTED|REVOKED|EXPIRED` — el negocio necesita el
snapshot actual del ciclo de la invitación, no su historia como ledger). **No** es event-sourcing; la *auditabilidad* (NFR10)
es **emisión de eventos** por el outbox, no ES. Decisión ratificada por el ADR aceptado — el dev **no** re-abre la estrategia
de persistencia ni el modelado del agregado.

### Testing (patrones del repo — II-3 es el precedente fresco)

- **Unit domain** (`api/tests/Unit/Iam/Invitation/…`, hoy inexistente): `Invitation` (transiciones legales + ilegales),
  `InvitationStatus`, los eventos, `InvalidToken`. `@internal` + `#[CoversClass]` **estricto por clase** (SonarCloud
  `new_coverage ≥ 80%` es gate real — II-1 falló a 78.8%). Fakes in-memory de puertos (`InMemoryInvitationRepository`,
  `RecordingEventBus`) sobre mocks. Constant-time se prueba en II-2, **no** re-testar timing (flaky, banned). Presupuestos
  PHPMD: `TooManyPublicMethods ≤ 10` (los `DataProvider` estáticos cuentan), `CouplingBetweenObjects ≤ 13` (aplica a tests).
- **Unit application:** `AcceptInvitation` (los 5 caminos de token muerto lanzan `InvalidToken` idéntico **sin** save/evento;
  el camino feliz hace los 3 flips + acuña sesión), `InviteUser` (funnel `GrantMembership` + captura unicidad → `UserAlreadyMember`).
- **Behat** (`api/features/backoffice/identity/invitation.feature`): accept válido → 204 + cookie + estados; **5 tokens
  muertos idénticos** (comparación cara a cara, AC3); accept sin `Origin` → 403; sin CSRF → 403 **sin mutación**; accept
  fallido → **0 eventos** (vaciar outbox + reset stats **antes**, assert 0 **después**); id de sesión regenerado. Ojo:
  el step inline SQL trunca en comillas dobles → seed JSONB quote-free o PyString; **+2 queries por escritura envuelta**.
  Worktree fresco → `make php.behat.install` **antes** de los gates.
- **Functional** (`api/tests/Functional/Iam/Invitation/`): `DoctrineInvitationRepository` sobre Postgres real.
- **PWA** (`vitest` + Playwright): `TokenActionScreen` (clon del test de `LoginForm` — mockea `next/navigation`, `useSession`,
  el container DI); `AccessWall` `invalid-link` (foco al `<h1>`, un `<h1>`, testids); `ApiAcceptInvitationRepository` (clon
  del test de `ApiLoginRepository` — stub `HttpClient`, mapea `HttpError(problem)` → outcome); schema de accept en
  `schemas.test.ts`. **E2E:** el accept necesita un token de invitación vivo — como los muros suspended/deactivated, **es
  probable que no haya tooling live-stack para mintear uno** → cubrir por **Behat API + PWA unit**, no e2e vivo (confirmar en
  dev; si se hace e2e, spec **pública** sin `authenticatedTest`, contexto sin storageState).
- **Worktree e2e:** `PLAYWRIGHT_BASE_URL`/`PLAYWRIGHT_API_BASE_URL` al puerto efímero (`docker compose port php 443`);
  `PLAYWRIGHT_HOST_PLATFORM_OVERRIDE=ubuntu24.04-x64`; `rm -rf pwa/.next-e2e` ante EACCES.

### Gotchas heredados (verificados en ii-0..3/7)

- `make php.behat.install` en worktree fresco antes de gates · `php.stan` exit 139 → `PHP_SERVICE=messenger_worker` ·
  `make php.quality` es el **único** sweep de PHPMD/cs-fixer (puede OOM 137) · Rector gana (nunca `@psalm-suppress`, nunca
  `// NOSONAR`) · migración editable en esta rama, inmutable tras merge · el `/me` devuelve `{data:{...}}` (envelope — bug
  #488) · un `NOT NULL` crudo en migración brickea el boot sobre filas existentes (usa DEFAULT then drop) · barrer del diff
  final los IDs de story/NFR y comentarios change-relative en `api/src`/`pwa/src` (permitidos aquí en el spec, prohibidos en
  código) · verificar **fresco** sobre el path del worktree, confiar en el exit code recién impreso.

### Decisiones ratificadas (A–E) — cerradas por Sergio (2026-07-13; lentes Winston + Amelia + desempate ChatGPT)

Las cinco quedan **cerradas — no re-abrir en dev**. Lo que sigue pendiente es **verificación empírica**, no diseño.

- **Decisión A = A1 (login programático `Security::login`).** Tras el commit de los flips de dominio,
  `Security::login($securityUser, 'main')` dispara `LoginSuccessEvent` → **reutiliza** `SessionMintingSuccessListener` (acuña
  la `Session` + resuelve org/device, fail-closed) **y** el `migrate(true)` anti-fixation nativo (prio 0; minting a −128
  sobrevive). A2 (manual) descartado por duplicar el invariante NFR3. **Corrección de encuadre:** el accept es
  `PUBLIC_ACCESS` **dentro** del firewall `main`, no fuera — por eso `Security::login` resuelve firewall. **Ordering fijado:**
  retire-then-act (`invitation->accept()`) + flips **dentro** del `wrapInTransaction` y **antes** del login, con guard
  `status==SENT`/unique; login **tras** el commit. 2 TX (dominio + mint de `Session`) = correcto por fronteras de agregado;
  «`ACTIVE` sin sesión» ante mint-503 = benigno recuperable. Ver «El crux: establecer la sesión tras el accept».
- **Decisión B = 400 `InvalidInput`.** `final class InvalidToken extends DomainException implements InvalidInput` con
  `type()='invalid-token'` (precedente `InvalidUuidException`; token muerto = target de request inválido, sin filtrar
  «existencia»). **Sin** marker interface nuevo (vive en `Iam/Invitation`, drift gate no dispara; el doc igual se actualiza).
  El ADR D12 lista `invalid-token` como marker **distinto** de `unauthorized`(401) y **no le fija status** → 400 es libre y
  cumple el único requisito duro: **status uniforme** en los 5 casos. 401/404 descartados (401 conflaciona con fallo de
  credenciales; 404 filtra existencia).
- **Decisión C = Opción 1 — CSRF nativo stateless de Symfony 8 (honra D5, sin desviación).** Verificado (Context7): stateless
  CSRF **no requiere sesión**, su check **same-origin es el mecanismo PRIMARIO**, y el double-submit cookie+header
  (`check_header`) es **opcional/off-by-default**, token **JS-generado al submit** (no sembrado por un GET) → el temor al
  cold-landing se disuelve. **Load-bearing = Origin-primary + token stateless** (unit/Behat); `check_header` = **defense-in-
  depth opcional** (única parte solo-navegador → Playwright, **no bloqueante**). El CSRF aquí es **defense-in-depth, NO el
  control primario** (primarios = Origin + `SingleUseToken`) — documentarlo, no debilitar el Origin check. Consolidar
  `LoginOriginListener` con el nativo = **follow-up**, no II-4. **Nunca** hand-rolled (C2 descartado). Ver «El crux: CSRF».
- **Decisión D = D1 (CLI, mirror II-1).** invite/resend/revoke = **casos de uso en `Application`** (comando = adapter fino →
  cuando J5 traiga los endpoints HTTP, envuelven el **mismo** use-case, OCP; cero rework). Comando `iam:invitation:create`
  (o `organization:member:invite`) espejo de `CreateInitialAdministratorCommand`: `mint` + persiste `Invitation` + **imprime
  el token plano una vez** (como el email), nunca lo loggea. Endpoints HTTP de gestión **diferidos** al slice de miembros
  (J5). El único endpoint HTTP de II-4 es el accept público. **Behat conduce el CLI y parsea el token plano** para POST-earlo
  a accept (sembrar por SQL no computa el hash → falsos rojos).
- **Decisión E = per-caso (taxonomía por dependencia).** Hook de conectividad + `OfflineNotice` + `ConnectivityButton` →
  **nueva capability `context/shared/connectivity/`** (`infrastructure/ui/` para los componentes; consumen el hook → **no**
  pueden ser `components/erpify`, que tiene prohibido importar `context/`). **`SecuritySignal`** → según semántica:
  presentacional puro → `components/erpify`; session/security-aware → `context/shared/{access,error}/infrastructure/ui`
  (espejo de `AccessWall`) — **pin con UX/Sally** qué es exactamente (única pieza cuya capa la decide su import real, no la
  regla). Reveal de password → `components/ui` (afordancia de input pura). **No** adelantar la frontera `components/{ui,erpify}`
  no autorizada (ver memoria `project-pwa-component-boundary-model`).

### Fuera de alcance (frontera explícita — no lo hagas en II-4)

- **Endurecimiento transversal (constant-time timing, `Referrer-Policy: no-referrer`, strip de URL `history.replaceState`,
  redacción del token en logs, rate-limit login/forgot/reset/accept, escape del email async, remitente no-`no-reply`
  formalizado)** → **II-8**. II-4 solo: token nunca renderizado + nunca persistido crudo + la plantilla+envío del email.
- **Reset de contraseña (forgot/reset, `TokenActionScreen` variante reset, `SecuritySignal` password-changed, colapso del
  copy «…o ha expirado»)** → **II-5**. II-4 crea `TokenActionScreen` (accept) reutilizable, pero la variante reset es II-5.
- **UI de gestión de miembros** (invitar/reenviar/revocar/activar/desactivar desde el backoffice) → **slice diferido** (J5
  contrato). II-4 hace los casos de uso + agregado, **no** la UI de gestión.
- **`demote`/`remove` last-admin + enforcement ≥1 ADMIN bajo baja de membership** → **#462**.
- **who-am-i / RBAC del cliente / permissions** → plano ortogonal (RBAC ya cortado); II-4 no toca el `subject:` del voter.
- **Multi-sesión detallada / tenancy operativa** → diferidos (II-7 / ADR de tenancy).

### Project Structure Notes

- `Iam/Invitation` ya está **registrado** en `deptrac.yaml` (esqueleto II-0) → **sin** capas deptrac nuevas; II-4 solo puebla
  los namespaces. **Excepción:** los seams cross-contexto del invite/accept (`Iam/Invitation → Organization/Membership`,
  `→ Iam/Identity`, `→ Iam/Session`) son entradas **per-file** en `.bounded-context-allowlist` + deptrac `skip_violations`
  (espejo de las de II-7; nunca forma global — `DeptracSeamSyncGateTest`).
- `routes.yaml` usa resources **estrechos** por módulo → II-4 añade su propio bloque para `Iam/Invitation/Infrastructure/Http/`.
- Migración en `api/migrations/2026/` vía `db.diff` (editable en esta rama; inmutable tras merge).
- PWA: la ruta `accept-invitation` vive en `(auth)/` (hereda `AuthLayout` + `noindex`); los componentes nuevos respetan las
  reglas de capa (`components/ui` no importa `erpify`/`context`; `components/erpify` no importa un bounded context).

### References

- [Source: `docs/adr/identity-invitation-lifecycle.md` D5/D6/D11/D12] — agregado `Invitation`; accept bajo CSRF+regeneración;
  `Shared/Token`; opacidad del token (higiene II-8); contrato de error graduado (`invalid-token` = pre-identidad opaco).
- [Source: `_bmad-output/planning-artifacts/arch-addendum-identity-invitation.md` SI-13/SI-15, NFR3, PR-4].
- [Source: `_bmad-output/planning-artifacts/epics-identity-invitation-lifecycle.md` Story II-4 (líneas 745-799); FR5/FR9;
  DAG líneas 450-459; Additional (CSRF 1.er consumidor, retiro `/register`)].
- [Source: `_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-07-06/{EXPERIENCE.md,DESIGN.md}`] — J1 accept-invitation;
  los 6 componentes; microcopy Spanish; suelo AA (UX-DR1–10).
- [Source: `_bmad-output/implementation-artifacts/deferred-work.md`] — contratos diferidos a II-4: funnel `GrantMembership`
  (línea 77), captura unicidad → `UserAlreadyMember` (línea 78), retire-then-act atómico single-use (línea 92).
- Precedentes de código: `Iam/Session/Application/StartSession.php` (mint transaccional) · `Iam/Identity/…/SessionMintingSuccessListener.php`
  (orquestación login-path) · `Iam/Identity/…/LoginOriginListener.php` (Origin check) · `Iam/Identity/Domain/Exception/AccountSuspended.php`
  (`type()` override) · `Shared/Token/Domain/SingleUseToken.php` · `ii-3-…md` (formato + patrón de decisiones).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Opus 4.8, 1M context).

### Debug Log References

- Verificación empírica A1 (el crux): `InvitationAcceptFunctionalTest` conduce el accept por el kernel real →
  204 + `User` ACTIVE + `Invitation` ACCEPTED + **exactamente 1 fila `Session`** (`assertCount(1, findByUserId)`).
  Confirma que `Security::login($u, firewallName: 'main')` dispara el login-path una sola vez y reutiliza el
  minting nativo sin doble-mint.
- Verificación empírica opacidad (SI-13): los 5 tokens muertos (malformado / inexistente / secreto-erróneo /
  caducado / ya-aceptado) devuelven `400 invalid-token` con `type`/`title`/`status` **idénticos** (comparación
  cara a cara en Functional + Behat).
- CSRF nativo stateless: `SameOriginCsrfTokenManager` exige `_token` de longitud ≥ 24 **y** Origin same-origin;
  el happy-path (Functional/Behat) envía `_token` de 32 chars + Origin y pasa; el PWA envía `uuidV7()` (36).

### Completion Notes List

**Backend `Iam/Invitation` (dominio → aplicación → infraestructura), TDD:**

- Agregado `Invitation` state-oriented (ADR D5): `CREATED→SENT→ACCEPTED|REVOKED|EXPIRED`, refs cross-módulo por
  id + `Uuid::ensure`, guarda de transiciones → `InvalidInvitationTransition` (409). Persiste **solo el digest**
  del token (`token_hash`) + `expires_at`; el token crudo nunca toca la BD ni un evento. 6 eventos PII-free vía
  trait `CarriesInvitationSnapshot` (Regla-de-Tres por módulo). `InvalidToken` (400 `InvalidInput` con `type()`
  override, **sin marcador nuevo** → drift-gate no dispara, Decisión B).
- **Localización del token = selector-verifier `<invitationId>.<secret>`** (`findById` + `Invitation::verify`) —
  corrección propia consistente con II-5: el VO oculta su digest y no hay lookup-por-hash público, así que evita
  editar `Shared/Token` y mantiene la opacidad. Un `Uuid::isValid` en el borde impide que un id malformado fugue
  un `invalid-uuid` distinto y rompa la opacidad.
- `AcceptInvitation` (el corazón): retire-then-act atómico — valida token + status==SENT + carga user **antes**
  de mutar, luego `user.activate($password)` + `invitation.accept()` + save + publish, todo en **un**
  `transactional`. Los 5 caminos muertos lanzan el **mismo** `InvalidToken` sin save/evento. El establecimiento
  de sesión (Decisión A1) vive en el controlador, **tras** el commit: hashea (Infra), llama al use case, y
  `Security::login($userProvider->loadUserByIdentifier($email), firewallName: 'main')` (corrección clave: 3.er
  arg = firewall, no el authenticator).
- `SendInvitation` orquesta invite en **una** transacción: `InviteUser` (Identity, nuevo, hermano credential-less
  de `CreateUser`) → `GrantMembership` (funnel AC5) → `Invitation::create/markSent` → publish → email tras
  commit; devuelve el token compuesto para que el CLI lo imprima. `RevokeInvitation`/`ResendInvitation` +
  3 comandos CLI finos (`iam:invitation:create|revoke|resend`, Decisión D). `expire()` es transición del
  agregado sin caller programado (futuro sweeper; hoy la caducidad la aplica `verify()`).
- **CSRF (Decisión C):** control **primario** = `AcceptInvitationOriginListener` (espejo de `LoginOriginListener`,
  403 same-origin) + el `SingleUseToken` opaco. **Defense-in-depth** = CSRF nativo stateless
  (`config/packages/csrf.yaml` `stateless_token_ids: [invitation_accept]` + `#[IsCsrfTokenValid]`), `check_header`
  OFF/diferido con el follow-up de consolidar `LoginOriginListener`. El `_token` no exige cookie sembrada
  (same-origin + longitud), así que el cold-landing del email funciona.
- **Contrato diferido de II-1 saldado (AC5):** la captura `UniqueConstraintViolationException → UserAlreadyMember`
  vive en `DoctrineMembershipRepository::save` (**Infra**, no `GrantMembership::grant` como decía el spec) —
  deptrac prohíbe DBAL en `Application`; la traducción DBAL→dominio pertenece al adapter (espejo de
  `DoctrineSessionRepository`→`SessionStoreUnavailable`). Test funcional dedicado.
- Email (`SymfonyInvitationEmailSender`): plantilla HTML bulletproof inline + dark-mode + fallback texto plano; el
  enlace = `{DEFAULT_URI}/accept-invitation?token=<compuesto>`. Remitente `MAILER_FROM` (formalización no-reply →
  II-8, per scope-out).

**PWA (subagente paralelo, revisado):** `accept-invitation/page.tsx` (RSC+Suspense) + `TokenActionScreen`
(campo único, reveal por defecto, token **nunca** renderizado/guardado, `SecuritySignal` al éxito) · puerto+adapter
`AcceptInvitationRepository`/`ApiAcceptInvitationRepository` (rutea por `problem.type`, `_token`=`uuidV7()` por la
regla `pwa/CLAUDE.md`) · `AccessWall` variante `invalid-link` (español) · capability nueva `connectivity/`
(`useOnlineStatus`+`ConnectivityButton`+`OfflineNotice`) · `PasswordInput` reveal (`components/ui`) · `SecuritySignal`
(`context/shared/access`) · retirado `/register`. Navegación siempre `safeHref(Routes.*)` (sin open-redirect);
sin XSS sinks; sin secretos en storage.

**Cobertura:** cada clase nueva tiene test `#[CoversClass]` dedicado (dominio + aplicación unit; controller /
listener / adapter / CLI / email vía Functional que alimenta el clover). Uncovered residual = guardas defensivas
inalcanzables (`?? throw RuntimeException` sobre `getId()` nunca-nulo; `!is_string($arg)` en comandos que la
consola siempre da string) — patrón aceptado (mismo que II-1).

**Follow-ups / diferido:** consolidar `LoginOriginListener` + el CSRF nativo (un solo same-origin gate) · habilitar
`check_header` double-submit (solo-navegador, Playwright) · endurecimiento (constant-time, `Referrer-Policy`, strip
de URL, redacción del token en logs, rate-limit accept, remitente no-reply formalizado) → **II-8** · gap i18n del
`AccessWall` (variantes suspended/deactivated/locked siguen en inglés) → iniciativa i18n aparte · UI de gestión de
miembros (invite/resend/revoke desde el backoffice, envuelve los mismos use cases) → slice J5.

**Verificaciones (frescas, sobre el path del worktree):** `php.quality` EXIT 0 (deptrac 0 viol · phpstan max ·
phpmd · phpcs · cs-fixer · gherkinlint) · `php.psalm.taint` No errors · `php.lint.error-contract` + `php.lint.
bounded-context` + `php.deptrac` verdes · `php.unit` **1812** (7940 asserts, 0 fallos) · `php.behat` **274**
escenarios (2507 steps) · `pwa.quality` EXIT 0 · `pwa.test.unit` **1005**. Migración `Version20260713141511`
(`iam_invitation`) aplicada; `db.validate` en sync.

### File List

**API — nuevos (`Iam/Invitation/`):** `Domain/Entity/Invitation.php` · `Domain/Enum/InvitationStatus.php` ·
`Domain/Event/{CarriesInvitationSnapshot,InvitationCreated,InvitationSent,InvitationResent,InvitationRevoked,InvitationExpired,InvitationAccepted}.php`
· `Domain/Exception/{InvalidToken,InvalidInvitationTransition,InvitationNotFound}.php` ·
`Domain/Repository/InvitationRepository.php` ·
`Application/{AcceptInvitation,AcceptedInvitation,SendInvitation,RevokeInvitation,ResendInvitation,InvitationEmailSender}.php`
· `Infrastructure/Http/{AcceptInvitationController,AcceptInvitationRequest,AcceptInvitationOriginListener}.php` ·
`Infrastructure/Persistence/Doctrine/DoctrineInvitationRepository.php` ·
`Infrastructure/Mail/SymfonyInvitationEmailSender.php` ·
`Infrastructure/Cli/{CreateInvitationCommand,RevokeInvitationCommand,ResendInvitationCommand}.php`.

**API — nuevos (otros):** `src/Iam/Identity/Application/InviteUser.php` · `migrations/2026/Version20260713141511.php`
· `config/packages/csrf.yaml`.

**API — modificados:** `config/routes.yaml` · `config/packages/security.yaml` · `tools/deptrac/deptrac.yaml` ·
`.bounded-context-allowlist` · `src/Organization/Membership/Infrastructure/Persistence/Doctrine/DoctrineMembershipRepository.php`
· `docs/api-error-contract.md`.

**API — tests:** `tests/Unit/Iam/Invitation/**` (dominio: `Entity/InvitationTest`, `Event/InvitationEventsTest`,
`Exception/{InvalidToken,InvalidInvitationTransition,InvitationNotFound}Test`; aplicación:
`Application/{AcceptInvitation,SendInvitation,RevokeInvitation,ResendInvitation}Test` + fakes locales
`{InMemoryInvitationRepository,RecordingEventBus,InlineTransactionManager,FixedClock,SpyInvitationEmailSender}`) ·
`tests/Unit/Iam/Identity/Application/InviteUserTest.php` ·
`tests/Functional/Iam/Invitation/{InvitationAcceptFunctionalTest,InvitationCliFunctionalTest}.php` ·
`tests/Functional/Organization/Membership/MembershipUniqueConstraintFunctionalTest.php` ·
`tests/DataFixtures/InvitationFixtureFactory.php` · `tests/DataFixtures/Fixtures/Invitation.yaml` (+ `User.yaml`,
`Membership.yaml` con `iris`) · `features/backoffice/identity/invitation_accept.feature`.

**PWA — nuevos:** `app/(auth)/accept-invitation/page.tsx` · `app/(auth)/_components/TokenActionScreen.tsx` ·
`context/backoffice/user/domain/{AcceptInvitationCommand,AcceptInvitationOutcome,AcceptInvitationRepository}.ts` ·
`context/backoffice/user/infrastructure/ApiAcceptInvitationRepository.ts` ·
`context/backoffice/user/application/schemas/auth/AcceptInvitationSchema.ts` ·
`context/shared/connectivity/infrastructure/{useOnlineStatus,ui/ConnectivityButton,ui/OfflineNotice}` ·
`context/shared/access/infrastructure/ui/SecuritySignal.tsx` · `components/ui/PasswordInput.tsx` · sus tests.

**PWA — modificados:** `context/shared/error/infrastructure/ui/AccessWall.tsx` (+`invalid-link`) ·
`context/shared/access/infrastructure/ui/index.ts` · `context/shared/dependency-injection/infrastructure/Container.ts`
· `context/shared/http-client/infrastructure/{ApiEndpoints,FetchHttpClient}.ts` ·
`context/shared/routing/domain/Routes.ts` · `app/(auth)/_components/LoginForm.tsx` ·
`tests/context/backoffice/user/schemas.test.ts` · `tests/context/shared/error/infrastructure/ui/AccessWall.test.tsx`.
**PWA — borrados:** `app/(auth)/register/page.tsx` · `app/(auth)/_components/RegisterForm.tsx` ·
`context/backoffice/user/application/schemas/auth/RegisterSchema.ts`.

### Change Log

| Fecha       | Cambio |
|-------------|--------|
| 2026-07-13  | Story II-4 creada (ready-for-dev): análisis exhaustivo de 4 artefactos (UX / código API / código PWA / historias previas + ADR). |
| 2026-07-13  | Decisiones A–E **ratificadas** (Sergio; lentes Winston+Amelia + desempate ChatGPT + verificación Context7 de Symfony 8 stateless CSRF): A=A1 (`Security::login`, ordering fijado, accept dentro de `main`), B=400 `InvalidInput`, C=Opción 1 (CSRF nativo stateless, Origin-primary load-bearing + `check_header` defense-in-depth opcional, honra D5), D=D1 (CLI), E=per-caso. |
| 2026-07-13  | II-4 **implementada** (backend `Iam/Invitation` completo + accept público dentro de `main` + CSRF stateless nativo + 6 componentes PWA + email + retiro de `/register`). A1 verificado empíricamente (1 sesión acuñada), opacidad de los 5 tokens muertos verificada, contrato diferido de II-1 saldado (unique→`UserAlreadyMember` en el adapter). Selector-verifier `<id>.<secret>` como localización del token. Gates verdes (php.quality/psalm.taint/php.unit 1812/php.behat 274/pwa 1005). Status → review. |

### Review Findings
