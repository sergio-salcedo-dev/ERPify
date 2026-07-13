---
baseline_commit: 79b5669a
---
# Story II-4: `Invitation` + aceptación (1ª superficie pública nueva) + las 6 pantallas de acceso

Status: ready-for-dev

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

**La frase que gobierna II-4:** el POST accept vive **fuera del firewall** y hace, en **una transacción**,
`credenciales-de-token válido → User INVITED→ACTIVE (fija password) → Invitation ACCEPTED → regenera el id de sesión →
acuña la 1ª Session` — y **solo entonces**. Toda muerte de token (usado/revocado/caducado/aceptado/inexistente) colapsa a
**un único** muro opaco (`invalid-token` + «Este enlace ya no es válido»). El token **nunca** se renderiza.

**⚠️ II-4 es grande** (backend `Iam/Invitation` completo + accept fuera del firewall + **primer wiring de CSRF stateless** +
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

4. **(NFR3 · CSRF + Origin + regeneración — anti-fixation)** El POST accept, al estar **fuera del firewall**, exige
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

### Backend — `Iam/Invitation` + accept fuera del firewall

- [ ] **T1 — Agregado `Invitation` + eventos + repo (AC1, AC8)**
  - [ ] `api/src/Iam/Invitation/Domain/Entity/Invitation.php` — `final class Invitation extends AggregateRoot`,
        `#[ORM\Table(name: 'iam_invitation')]`. Props por id: `organizationId`, `invitedUserId`, `tokenHash` (string),
        `expiresAt` (timestamptz), `InvitationStatus $status`. Refs cross-módulo **por id** (`Uuid::ensure()` en el borde),
        **nunca** `#[ORM\ManyToOne]`. Factory `static create(...)` (→ `CREATED`, graba `InvitationCreated`); transiciones
        `markSent()`, `accept()` (`SENT→ACCEPTED`, graba `InvitationAccepted`), `revoke()` (`→REVOKED`), `expire()`; guardas
        de máquina rechazan transiciones ilegales con excepción de dominio (mirror `InvalidIdentityTransition` de II-3,
        `implements Conflict` → 409).
  - [ ] `api/src/Iam/Invitation/Domain/Enum/InvitationStatus.php` — backed enum puro `{CREATED, SENT, ACCEPTED, REVOKED, EXPIRED}`.
  - [ ] Eventos en `api/src/Iam/Invitation/Domain/Event/` — subclases de `DomainEvent`, `eventName='erpify.iam.invitation.<fact>'`,
        `aggregateType='Iam.Invitation'`, **payload PII-free** (id + status, **nunca** email ni token). Si acaban ≥3 sobres
        con snapshot compartido → trait por-módulo (`CarriesInvitationSnapshot`, Regla-de-Tres **por módulo**, NO unificar con
        Session/Identity — ver memoria `bankaccount-event-envelope-trait-vs-bank`).
  - [ ] Puerto `api/src/Iam/Invitation/Domain/Repository/InvitationRepository.php` + adapter Doctrine
        `.../Infrastructure/Persistence/Doctrine/DoctrineInvitationRepository.php` (composición sobre EM, `#[AsAlias]`, mirror
        `DoctrineSessionRepository`). Métodos: `save`, `findById`, y **`findActiveByTokenHash(string $hash): ?Invitation`**
        (o el lookup que use el accept — ver Decisión sobre cómo el token localiza la invitación en T3).
  - [ ] `make db.diff` → migración `api/migrations/2026/` (tabla `iam_invitation`, índice sobre `token_hash` y/o
        `invited_user_id`). `down()` reversible. **Cero PII/credenciales/token crudo** en el schema. Editable en esta rama.

- [ ] **T2 — Caso de uso invite (AC5, AC7) — funnel por `GrantMembership`, captura de unicidad**
  - [ ] `api/src/Iam/Invitation/Application/InviteUser.php` (o `SendInvitation`) en un `TransactionManager::transactional`:
        `User::invite($id, $email, ...$roles)` → **`GrantMembership->grant($userId, ...$roles)`** (funnel obligatorio, AC5) →
        `Invitation::create(...)` con `SingleUseToken::mint($clock->now()->add(TTL))` → `markSent()` → guardar los 3 agregados
        + `eventBus->publish(...pullDomainEvents())` de cada uno → enviar `SecurityEmail` (invitación) con el **plaintext**
        del `GeneratedToken` (`->plaintext()`) en el enlace. El plaintext **no se persiste ni se loggea** (`GeneratedToken`
        lo guarda `#[SensitiveParameter]`).
  - [ ] **Fix del contrato diferido de II-1** en `GrantMembership::grant()` (`api/src/Organization/Membership/Application/GrantMembership.php:42`):
        capturar `UniqueConstraintViolationException` → re-lanzar `UserAlreadyMember` (grant concurrente idempotente).
  - [ ] TTL de invitación = política de II-4 (p.ej. 72h) — constante nombrada, **no** magic number.

- [ ] **T3 — Caso de uso accept (AC2, AC3, AC4) — el corazón de II-4**
  - [ ] `api/src/Iam/Invitation/Application/AcceptInvitation.php` — orquesta, en **una** `transactional`:
        1. localizar la `Invitation` por el token presentado (hash del plaintext, o lookup por id + `verify`), validar
           `verify($plaintext, $now)` **y** `status==SENT`;
        2. cargar el `User` (`UserRepository::findById($invitation->invitedUserId())`);
        3. `HashedPassword::fromHash($passwordHasher->hash($plainPassword))` (reusar `PasswordHasher` de II-3);
        4. `user->activate($hashedPassword)` (INVITED→ACTIVE, II-3);
        5. `invitation->accept()` (SENT→ACCEPTED — **retire-then-act atómico**: el estado `ACCEPTED` ES el consumo del
           single-use; contrato diferido de II-2);
        6. `save` User + Invitation, `eventBus->publish(...)` de ambos — **todo dentro del mismo `transactional`**.
    - [ ] Cualquier fallo de validación (token no elegible en los 5 casos, status≠SENT, User no INVITED) → lanzar la excepción
          **`invalid-token`** (T5) **antes** de mutar nada. **Opacidad total (AC3):** los 5 caminos lanzan la **misma**
          excepción con el **mismo** mensaje — no ramificar el motivo.
  - [ ] **Establecimiento de sesión + anti-fixation (NFR3) = Decisión A** — tras el commit de los flips, establecer la sesión
        autenticada y acuñar la 1.ª `Session` (regenerando el id). Ver «El crux: establecer sesión fuera del firewall» + Decisión A.

- [ ] **T4 — Controlador accept + ruta pública + Origin (AC2, AC4)**
  - [ ] `api/src/Iam/Invitation/Infrastructure/Http/AcceptInvitationController.php` — `AbstractController` fino, POST, DTO de
        request con `#[MapRequestPayload]` + `#[Assert\…]` (token + password; el password valida longitud/policy en el DTO,
        el dominio revalida). Delega en `AcceptInvitation`. Respuesta de éxito: **decidir 204-vs-body** y fijarlo como el
        contrato que consume el PWA (recomendado 204 + cookie de sesión, espejo del login).
  - [ ] Ruta: bloque `resource` nuevo en `api/config/routes.yaml` apuntando a `../src/Iam/Invitation/Infrastructure/Http/`
        con `defaults: { _format: json }` y prefijo adecuado (p.ej. `/api/v1/backoffice`, mirror del login que confina su URL).
  - [ ] **`security.yaml`:** añadir una entrada `access_control` `PUBLIC_ACCESS` para la ruta accept **antes** del catch-all
        `^/api → IS_AUTHENTICATED_FULLY` (el accept es pre-identidad, fuera del firewall).
  - [ ] Origin check: listener espejo de `LoginOriginListener` **keyed en el nombre de ruta del accept** (o generalizar el
        existente a un conjunto de rutas). `Origin !== getSchemeAndHttpHost()` → `AccessDeniedHttpException` (403 `forbidden`).

- [ ] **T5 — `invalid-token` en el contrato de error (AC3, AC6) = Decisión B**
  - [ ] `api/src/Iam/Invitation/Domain/Exception/InvalidToken.php` (o `InvalidInvitationToken`) —
        `final class … extends DomainException implements <marker existente>` con `type()` override → `'invalid-token'`.
        **Sin marker interface nuevo** (vive en `Iam/Invitation`, no en `Shared/ErrorContract` → el `ErrorContractGateTest`
        git-aware no dispara; pero **igual** actualiza el doc). Marker + status concretos = **Decisión B** (recomendado 400
        `InvalidInput`, precedente `InvalidUuidException`).
  - [ ] Actualizar [`docs/api-error-contract.md`](../../docs/api-error-contract.md): documentar `invalid-token` (el `type` ya
        está **reservado** en el doc por II-3 como «out of scope here» — ahora se realiza), su status, y que es
        **pre-identidad opaco** (los 5 casos colapsan). `make php.lint.error-contract` verde.

- [ ] **T6 — CSRF stateless double-submit (AC4) — 1.er consumidor = Decisión C**
  - [ ] **Nada existe hoy** (`framework.yaml` sin `csrf_protection`; el comentario de `LoginOriginListener` lo anticipa como
        `wire-on-consumer`). Cablear el mecanismo (recomendado: `framework.csrf_protection` nativo stateless con
        `stateless_token_ids` + `check_header` + `cookie_name`, esquema ya documentado en `reference.php`) y aplicarlo al POST
        accept. **El login POST entra en el alcance del CSRF** (epic Additional) — confirmar si se cablea aquí o se deja
        preparado. Ver «El crux: CSRF» + Decisión C.
  - [ ] Coordinar con el cliente: el double-submit necesita que el PWA lea/reenvíe el token (cookie→header). Verificar en un
        **navegador real** (el flujo de la cookie CSRF + header no se ve en unit; mirror del hallazgo Base-UI tooltip).

- [ ] **T7 — `SecurityEmail` invitación (AC9) — plantilla, no componente React**
  - [ ] Plantilla del email de invitación (Twig/PHP mailer, `symfony/mailer` — **ya async vía Messenger** por defecto en este
        stack). Contrato UX-DR6: cabecera plana sin hero · una frase de propósito · **un** enlace bulletproof (estilos inline,
        `«Aceptar invitación»`, área táctil grande) · pie legal mínimo · **remitente NO `no-reply`** · pila de sistema ·
        dark-mode-aware con literales `#2f5cd9`/`#6c9bff` · `lang="es"` · fallback de URL en texto plano. El enlace lleva el
        **plaintext** del token (`?token=`); el token **nunca** viaja en logs. **Endurecimiento (async explícito, escape,
        headers, redacción) = II-8** — aquí solo la plantilla + el envío.

- [ ] **T8 — Deptrac seams + Behat (AC2–AC8)**
  - [ ] `Iam/Invitation` **ya está registrado** en `api/tools/deptrac/deptrac.yaml` (esqueleto de II-0) → **no** hay capa
        nueva. Pero los imports cross-contexto del invite/accept (`Iam/Invitation → Organization\Membership\Application\{GrantMembership,FindUserOrganizationId}`,
        `→ Iam\Identity` repo/hasher, `→ Iam\Session\Application\StartSession`) son violaciones **Nivel-1** salvo allowlist:
        añadir entradas **per-file** en `api/.bounded-context-allowlist` **y** deptrac `skip_violations` (espejo de las de II-7;
        **nunca** forma global `* =>` — `DeptracSeamSyncGateTest` la prohíbe; contexto = módulo de 2 niveles).
  - [ ] Behat: nueva feature `api/features/backoffice/identity/invitation.feature` (o `invitation/accept.feature`) — accept
        válido → 204 + cookie + `User` ACTIVE + `Invitation` ACCEPTED; los **5 casos de token muerto** → **idéntico**
        `invalid-token` (comparación cara a cara, AC3); accept sin `Origin` → 403; sin CSRF → 403 sin mutación; **0 eventos**
        en el accept fallido (vaciar outbox + reset stats antes). **Presupuesto de queries +2 por escritura envuelta**
        (BEGIN/COMMIT) — el accept hace varias.

### Frontend — la superficie pública de acceso (6 componentes)

- [ ] **T9 — Ruta `accept-invitation` + `TokenActionScreen` (AC9)**
  - [ ] `pwa/src/app/(auth)/accept-invitation/page.tsx` — **clon de `reset-password/page.tsx`**: RSC que envuelve el form en
        `<Suspense>` (el form lee `?token=` con `useSearchParams`, obligatorio bajo Suspense en Next 16). Hereda `AuthLayout`
        (card centrada + `noindex`) por vivir en `(auth)/`.
  - [ ] `pwa/src/app/(auth)/_components/TokenActionScreen.tsx` (variante accept) — **clon de `ResetPasswordForm.tsx`**:
        `const token = useSearchParams().get("token")`; si `!token` → `<AccessWall variant={INVALID_LINK} />`. **Campo único de
        contraseña con toggle revelado por defecto** (no hay primitivo de reveal → crear con `useState` + `Eye`/`EyeOff` de
        `lucide-react`, toggle ≥44px con `aria-pressed` y nombre accesible estático «Mostrar/Ocultar contraseña»). Submit vía
        `ConnectivityButton`. Éxito → `SecuritySignal` invitation-accepted → navegar al ERP (`safeHref`). **Token nunca
        renderizado.** Anunciar contexto de página antes del autofocus (`aria-describedby` con reglas + propósito).

- [ ] **T10 — Caso de uso accept en el cliente (AC9) — puerto + adapter (DIP)**
  - [ ] **Clon del patrón login (`context/backoffice/user/`):** puerto `domain/AcceptInvitationRepository.ts` +
        `domain/AcceptInvitationOutcome.ts` (unión discriminada rutada por `problem.type`, **no** por status solo) +
        `infrastructure/ApiAcceptInvitationRepository.ts` (`@injectable`, `@inject("HttpClient")`, mapea 204→aceptado /
        `invalid-token`→muro / re-lanza no-`HttpError`). Registrar 1 binding en `Container.ts` (símbolo string) + 1 entrada en
        `ApiEndpoints.ts` + `Routes.ACCEPT_INVITATION`.
  - [ ] **`Origin` gratis:** `FetchHttpClient.post` no setea `Origin` — el navegador lo manda solo en same-origin, y la cookie
        same-origin fluye sola → el requisito Origin se cumple **sin código extra** (mirror del login). El **CSRF double-submit**
        (T6) sí puede necesitar leer una cookie y reenviarla como header — coordinar con backend.
  - [ ] Si el accept puede devolver 401/403, **añadir su endpoint a `isAuthHandshakeEndpoint`** en `FetchHttpClient.ts` para
        que un fallo **no** rebote a `/login?reason=session-expired`.
  - [ ] Envelope: si el éxito devuelve body (no 204), **guardar el envelope `{data}`** y destructurar (bug #488 — validar la
        forma antes de leer).

- [ ] **T11 — `AccessWall` variante `invalid-link` (AC3, AC9)**
  - [ ] `pwa/src/context/shared/error/infrastructure/ui/AccessWall.tsx` — **añadir `INVALID_LINK: "invalid-link"`** al objeto
        `AccessWallVariant` (fuerza en compile-time una entrada `COPY` nueva por ser `Record<Variant,…>`). Copy Spanish exacta:
        título **«Este enlace ya no es válido»**, cuerpo **«Solicita una nueva invitación a tu administrador para continuar.»**,
        2 acciones: **«Iniciar sesión»** (`Routes.LOGIN`, primaria) + **«Solicitar nueva invitación»**. Tono neutro
        (`bg-muted`, **nunca** danger); foco al `<h1>` ya implementado. **Nota i18n:** el `AccessWall` actual trae copy en
        **inglés** (`suspended`/`deactivated`/`locked`) — la espina exige **español, ninguna cadena hardcodeada**; reconciliar
        el gap i18n al añadir la variante (o al menos dejar la nueva en español + flag del gap).
  - [ ] Añadir el caso `invalid-link` al `it.each` de `tests/context/shared/error/infrastructure/ui/AccessWall.test.tsx`.

- [ ] **T12 — `ConnectivityButton` + `OfflineNotice` + hook de conectividad (AC9)**
  - [ ] **No existen** (ni `navigator.onLine`/`useOnlineStatus`). Crear:
        - Hook de estado online (nueva capacidad `pwa/src/context/shared/connectivity/infrastructure/…`, hook puro).
        - `ConnectivityButton` (componente que compone el Brand `Button`; máquina `idle→loading[spinner, label «Enviando…»
          persiste, `aria-busy`]→disabled-in-flight[bloquea doble envío]→retry[idempotente]`; conserva foco; **sin toast de
          éxito**). Ubicación = `components/erpify` (primitivo reutilizable) — confirmar frontera `components/{ui,erpify}`.
        - `OfflineNotice` (banda in-form **sobre** el botón, `aria-live="polite"`, `{color.warning}`/`-strong` **no rojo**,
          copy **«Sin conexión. Reintenta cuando recuperes señal.»**, conserva lo tecleado).

- [ ] **T13 — `SecuritySignal` + retiro de `/register` (AC9, AC10)**
  - [ ] `SecuritySignal` (invitation-accepted) — **no existe** ningún componente de éxito. Card dentro de `AuthLayout`: dot
        `{color.success}` (sin animación), `<h1>`, copy **«Invitación aceptada. Ya puedes empezar a trabajar.»**, acción
        primaria = entrar al ERP. **Mover el foco al `<h1>`** en la transición SPA (no se reubica solo). Ubicación =
        `components/erpify` vs `context/shared/<capability>/…` → confirmar (frontera de componentes no autorizada).
  - [ ] **Retirar `/register`:** borrar `app/(auth)/register/page.tsx`, `_components/RegisterForm.tsx`,
        `context/backoffice/user/application/schemas/auth/RegisterSchema.ts`, `Routes.REGISTER`, el `<Link>` «Create account»
        de `LoginForm.tsx` (líneas ~131-133), y **editar** (no borrar) `tests/context/backoffice/user/schemas.test.ts` (quitar
        los casos `RegisterSchema`). **Antes de borrar**, copiar el patrón `.refine` de confirmación de password al schema de
        accept si se usa doble campo — **pero la UX manda campo ÚNICO** con toggle, así que el schema de accept clona
        `ResetPasswordSchema` (min/max de `passwordPolicy.ts`) **sin** el `confirmPassword`.
  - [ ] Schema de accept en `context/…/application/schemas/…` — límites en **`.max()`** (nunca `maxLength`); mensajes espejo de
        los 422 del API. Añadir sus casos a `schemas.test.ts`.

- [ ] **T14 — Tests (todos los AC)** — ver «Testing».
- [ ] **T15 — Gates + verificación fresca** — `make php.behat.install` (worktree fresco) → `make php.stan` (por fichero; exit
      139 → `PHP_SERVICE=messenger_worker`) → `make php.test` → `make php.quality` EXIT 0 → `make php.lint.error-contract` →
      `make php.lint.bounded-context` → `make php.deptrac` → `make php.psalm.taint` → `make pwa.quality` → `make pwa.test`.
      Verificar sobre el **path del worktree**, confiar en el exit code recién impreso.

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

### El crux: establecer la sesión FUERA del firewall (NFR3 · Decisión A)

El accept es un controlador **custom fuera del firewall** → **no** se dispara `LoginSuccessEvent`, así que **ni** el
`SessionMintingSuccessListener` (que acuña la `Session`) **ni** el `migrate(true)` anti-fixation del firewall corren solos.
**No existe hoy** ningún helper de login programático (`grep` no halló `Security::login`/`authenticateUser`/`->migrate(`).
Dos caminos — **decisión de arquitectura, confirmar al inicio (Decisión A):**

- **(A1) Login programático** — `Security::login($securityUser)` (o `UserAuthenticatorInterface::authenticateUser`) tras los
  flips. Dispara `LoginSuccessEvent` → **reutiliza** `SessionMintingSuccessListener` (acuña la `Session` + resuelve org +
  device) **y** el `migrate` anti-fixation del firewall. **Mínima duplicación.** Riesgo: login programático dentro de un
  controlador público; verificar que el evento dispara una sola vez y que el `iamSessionId` sobrevive a la regeneración
  (el listener corre a −128, después del migrate a 0 — mismo orden que en login).
- **(A2) Manual** — `$request->getSession()->migrate(true)` + fijar el token en `TokenStorage` + llamar `StartSession->start(...)`
  directo (resolviendo org con `FindUserOrganizationId`, device con `UserAgentDeviceLabel`). Explícito, sin evento, pero
  **duplica** la orquestación del `SessionMintingSuccessListener`.

**Recomendación: A1** (reutiliza el listener de minting y el anti-fixation del firewall; DRY sobre el TCB de sesión), con la
verificación empírica de que el evento y el migrate se comportan igual que en el login. Si A1 resulta frágil en la práctica
(doble minting, orden del migrate), caer a A2. **La regeneración del id es AC (NFR3), no opcional** — un test compara el id
pre/post.

### El crux: CSRF stateless double-submit (1.er consumidor · Decisión C)

**Nada existe.** `framework.yaml` no tiene `csrf_protection`; el docblock de `LoginOriginListener` dice que el token CSRF
`wire-on-consumer` **reutilizará** su mismo-origen — **II-4 es ese consumidor**. `reference.php` ya documenta el esquema
nativo (`csrf_protection{ stateless_token_ids, check_header, cookie_name: "csrf-token" }`).

- **(C1) Nativo Symfony 8 stateless** — habilitar `framework.csrf_protection` con `stateless_token_ids` + `check_header:true` +
  `cookie_name`; aplicar al POST accept. Menos criptografía hand-rolled; es lo que el ADR anticipó. **Verificar** que funciona
  para una ruta **fuera del firewall** (el stateless CSRF de Symfony no requiere sesión — encaja).
- **(C2) Hand-rolled** — listener que compara una cookie a un header (double-submit), espejo de `LoginOriginListener`.

**Recomendación: C1** (nativo). El **login POST entra en el alcance del CSRF** (epic Additional) — confirmar si II-4 lo
cablea ya o lo deja preparado (no romper el login `ACTIVE→204` existente es invariante de no-regresión). **Verificar en
navegador real** el ciclo cookie→header (unit no lo ve).

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

### Decisiones a confirmar al inicio del dev (riesgo medio — recomendaciones flagged)

- **Decisión A — establecimiento de sesión + anti-fixation (NFR3).** Recomendado **A1** (login programático
  `Security::login` → reutiliza `SessionMintingSuccessListener` + migrate del firewall); alternativa **A2** (manual `migrate(true)`
  + `StartSession` directo). Ver «El crux: establecer la sesión fuera del firewall». *La regeneración del id es AC, no opcional.*
- **Decisión B — marker + status de `invalid-token`.** Recomendado **400 `InvalidInput`** (`DomainException implements
  InvalidInput`, `type()='invalid-token'`, precedente `InvalidUuidException`; un token muerto es un target de request
  inválido, sin filtrar «existencia»). Alternativas: 404 `NotFound` o 401 `Unauthenticated` (D12 agrupa `invalid-token` en
  pre-identidad junto a `unauthorized`). **Requisito duro:** el status elegido es **uniforme** en los 5 casos (opacidad).
  **Sin** marker interface nuevo (vive en `Iam/Invitation`, drift gate no dispara; doc igual se actualiza).
- **Decisión C — mecanismo CSRF double-submit.** Recomendado **C1** (nativo Symfony 8 stateless, `framework.csrf_protection`);
  alternativa **C2** (listener hand-rolled). Confirmar si el **login POST** se cablea al CSRF ahora o se deja preparado
  (no romper `login→204`). Ver «El crux: CSRF».
- **Decisión D — superficie de invite/resend/revoke (AC7), dado que la UI de miembros está fuera de alcance (J5).**
  Recomendado: **comando CLI** de invite (mirror `ProvisionOrganization`/`CreateInitialAdministrator` de II-1) + los casos de
  uso de aplicación (resend/revoke) exercitables por CLI/Behat; los **endpoints HTTP de gestión** se difieren al slice de
  gestión de miembros. Alternativa: endpoint HTTP mínimo gated ya. **Confirmar** — condiciona cuánto backend de disparo entra
  en II-4 vs cuánto se difiere.
- **Decisión E — placement de los componentes PWA nuevos** (`SecuritySignal`, `ConnectivityButton`, `OfflineNotice`, hook
  conectividad). `components/erpify` (primitivo reutilizable) vs `context/shared/<capability>/infrastructure/ui` (pantalla de
  una capacidad). **No** adelantar la frontera `components/{ui,erpify}` no autorizada (ver memoria
  `project-pwa-component-boundary-model`); confirmar caso a caso en dev.

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

### Completion Notes List

### File List

### Change Log

| Fecha       | Cambio |
|-------------|--------|
| 2026-07-13  | Story II-4 creada (ready-for-dev): análisis exhaustivo de 4 artefactos (UX / código API / código PWA / historias previas + ADR). |

### Review Findings
