---
stepsCompleted: ['step-01-validate-prerequisites', 'step-02-design-epics', 'step-03-create-stories']
inputDocuments:
  - docs/adr/identity-invitation-lifecycle.md
  - _bmad-output/planning-artifacts/arch-addendum-identity-invitation.md
  - _bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-07-06/EXPERIENCE.md
  - _bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-07-06/DESIGN.md
  - _bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-07-06/validation-report.md
  - docs/adr/auth-rbac-subsystem.md
  - _bmad-output/planning-artifacts/arch-addendum-auth-rbac.md
  - docs/adr/rbac-authorization-model.md
  - _bmad-output/planning-artifacts/arch-addendum-rbac-authorization-model.md
  - _bmad-output/planning-artifacts/epics-rbac-authorization-model.md
  - _bmad-output/planning-artifacts/epics-auth-foundation.md
  - docs/api-error-contract.md
  - docs/rules/security.md
  - docs/rules/architecture.md
  - docs/rules/database.md
scope: >-
  Ciclo de vida de identidad / invitación (PR-0…PR-8 del DAG del addendum): promoción del subsistema de
  identidad a contextos top-level `Iam/{Identity,Invitation,Session}` + `Organization/{Organization,Membership}`,
  y realización backend de las **cuatro máquinas de estado** (identidad · invitación · autenticación/lockout ·
  sesión) tras los **dos invariantes transversales** (indistinguibilidad pre-identidad · opacidad del token) y la
  **regla de los tres momentos** (`credenciales → identidad → admisión → sesión`). Incluye el delta de UI de las
  **superficies públicas de acceso** (login, forgot/reset, accept-invitation, muros de estado) y el contrato de
  emails de seguridad. EXTIENDE el subsistema auth/RBAC en vigor (SI-1…SI-9) sin revocarlo; es ORTOGONAL al plano
  de autorización RBAC (ya cortado). NO opera tenancy (self-signup, tenant-switching, enforcement cross-tenant) —
  el seam multi-tenant-ready se modela, la operación se difiere a su ADR.
---

# ERPify — Ciclo de vida de Identity & Invitation — Desglose de épica

## Overview

Desglosa la épica **«Identity & Invitation lifecycle» (PR-0…PR-8)** definida en el DAG de
[`arch-addendum-identity-invitation.md`](./arch-addendum-identity-invitation.md), cuyas decisiones fija
[`docs/adr/identity-invitation-lifecycle.md`](../../docs/adr/identity-invitation-lifecycle.md) (D1–D12), y que
**proyecta** el run UX `ux-ERPify-2026-07-06` (`EXPERIENCE.md` = espina de comportamiento; `DESIGN.md` = delta
visual) sobre las superficies públicas de acceso.

El hueco que cubre: hoy la fundación auth shippeó un **firewall de sesión stateful**, un `User` libre de framework
en `Backoffice/Identity` y creación de usuarios **solo por CLI** (`identity:user:create`). **No hay** onboarding
público, invitación, verificación de email, recuperación de contraseña, ciclo de vida de cuenta, estado de bloqueo
ni registro server-side de sesiones — un usuario está creado por CLI o ausente, y una sesión es una sesión Symfony
desnuda **sin superficie de revocación**. Esta épica introduce todo ese subsistema como **cuatro máquinas de estado
ortogonales** (identidad `INVITED→ACTIVE↔SUSPENDED↔DEACTIVATED`; invitación `CREATED→SENT→ACCEPTED|REVOKED|EXPIRED`;
autenticación `Unlocked/LockedUntil(T)`; sesión `Active→Revoked|Expired`) tras **dos invariantes de seguridad**
transversales, con onboarding **invitation-first** (no hay «crear cuenta») y un dominio **multi-tenant-ready** cuya
operación de tenancy se difiere.

**Continuidad con los hermanos (extiende, no revoca):** respeta SI-1…SI-9 de
[`arch-addendum-auth-rbac.md`](./arch-addendum-auth-rbac.md) /
[`docs/adr/auth-rbac-subsystem.md`](../../docs/adr/auth-rbac-subsystem.md) (D2 fijó el **trigger de promoción**:
mover `User` fuera de `Backoffice/Identity` «cuando emerjan capacidades IAM» — password reset, login attempts,
sessions — que ahora emergen a la vez, así que dispara). Es **ortogonal** al plano de autorización RBAC
(`docs/adr/rbac-authorization-model.md`, épica ya cortada): la gramática `Role`/permiso es el plano de autorización
y permanece separada de este plano de identidad. El precedente de corte por addendum + DAG es
[`epics-auth-foundation.md`](./epics-auth-foundation.md) y `epics-rbac-authorization-model.md`.

**Frontera explícita (identidad, no tenancy ni autorización):** esta épica diseña **quién eres y si eres admitido**
(admisión = consultar Identity Lifecycle **entre** demostrar identidad y conceder sesión) y deja fuera, por diseño,
(a) el **plano de autorización** *qué puedes hacer* (RBAC — el `subject:` del voter sigue sin evaluar) y (b) la
**tenancy operativa** *sobre qué organización* (self-signup, tenant-switching, enforcement de scope cross-tenant).
El seam multi-tenant-ready (`organizationId` en todo agregado, `Membership` autoritativo) se **modela** ahora; la
operación se difiere a un ADR de tenancy.

## Requirements Inventory

> **Derivado de un ADR ya aceptado.** Este inventario **no** es independiente del diseño: las FR/NFR destilan
> decisiones ya ratificadas en
> [`docs/adr/identity-invitation-lifecycle.md`](../../docs/adr/identity-invitation-lifecycle.md) (D1–D12) y los seis
> invariantes globales del addendum (SI-10…SI-15) — por eso varias nombran artefactos concretos (`UserChecker`,
> `Iam/Invitation`, `Shared/Token`, `Session Admission Gate`). El objetivo aquí es **trazabilidad e
> implementabilidad**, no re-abrir el diseño. La cobertura decisión→requisito se verifica en «ADR Decision
> Coverage»; el requisito→historia (`FR Coverage Map`) se rellena en el Paso 2.

### Functional Requirements

FR1: **Promoción de contexto `Iam/` + `Organization/`** (D1 · PR-0) — mover `Backoffice/Identity` → **`Iam/Identity`**
(`User`, `SecurityUser`, `UserProvider`, authenticator, `SecurityActorContextFactory`); crear módulos hermanos
**`Iam/Invitation`** e **`Iam/Session`**; nuevo contexto **`Organization/`** con `Organization` + `Membership`.
**Move/rename sin cambio de comportamiento**: actualizar `security.yaml` y registrar `Iam/*` + `Organization/*` en
`deptrac.yaml` (mirror del bloque Identity). Referencia cross-module **por id** (`private string $userId`,
`organizationId`) — nunca `#[ORM\ManyToOne]` a la entidad de otro módulo.

FR2: **`Organization` + `Membership` + bootstrap** (D2 · PR-1) — agregados `Organization/Organization` y
`Organization/Membership(userId, organizationId, roles)` como **enlace autoritativo** user↔org (un user nunca es
«global»; los roles son org-scoped y **se asignan antes de aceptar**). **Una org por instalación** hoy, bootstrapeada
por CLI (`ProvisionOrganization` + `CreateInitialAdministrator`); **nunca credenciales en migración** (dev/test vía
Alice fixtures).

FR3: **`IdentityStatus` + admisión vía `UserChecker`** (D3, D4, D12 · PR-3) — enum `IdentityStatus{INVITED, ACTIVE,
SUSPENDED, DEACTIVATED}` en `User` (**sin `PENDING`**); `HashedPassword` **nullable hasta `ACTIVE`** (`INVITED` =
identidad + membership provisionados, credencial aún sin fijar). `UserChecker` que hace **mecánica la regla de los
tres momentos**: `checkPreAuth` rechaza `INVITED` **uniformemente como pre-identidad** (sin password, indistinguible
de «no existe»); `checkPostAuth` (alcanzado solo con credenciales válidas) rechaza `SUSPENDED`/`DEACTIVATED`/
`LockedUntil>now` con un **muro post-identidad que no acuña sesión** — stateless, renderizado desde el body del POST
de login, sin cookie ni token reanudable.

FR4: **`Shared/Token` — `SingleUseToken`** (D6 · PR-2, prereq de PR-4/PR-5) — capacidad `Shared/Token` con
`Domain/SingleUseToken` (VO: alta entropía · single-use · TTL · **hasheado en reposo**) + `Infrastructure/`
(generador CSPRNG + hasher + **verificación constant-time**). **Un único mecanismo** compartido por invitación y
reset (no dos verificadores hand-rolled): en código de seguridad la divergencia sutil es una vulnerabilidad, y ese
«no debe divergir» anula la Regla-de-Tres. Placement `Shared/` porque dos módulos lo consumen (mirror de
`Shared/Uuid`, `Shared/Clock`).

FR5: **`Invitation` + aceptación** (D5, D11 · PR-4) — agregado `Iam/Invitation(organizationId, invitedUserId,
tokenHash, expiresAt, status: CREATED→SENT→ACCEPTED|REVOKED|EXPIRED)`; el **token crudo nunca persiste** (solo su
hash, FR4). POST **accept fuera del firewall** que valida el token, voltea `User INVITED→ACTIVE`, fija la password,
marca `Invitation ACCEPTED`, **regenera el id de sesión** (anti-fixation en el salto de privilegio) y acuña la
primera `Session`. Como POST no autenticado que muta estado y acuña sesión, lleva **`Origin` check** (patrón
`LoginOriginListener`) **+ token CSRF** — **primer consumidor** que cablea el double-submit stateless que el hermano
auth-rbac dejó `wire-on-consumer`. `resend` invalida el token previo y emite uno nuevo; `revoke → REVOKED`; lapso
TTL → `EXPIRED`. Los roles **nunca** viven en `Invitation` (delivery only; roles en `Membership`, FR2).

FR6: **Reset de contraseña uniforme** (D9, D10 · PR-5; depende de PR-2, PR-3, PR-7) — forgot/reset **uniformes para
todo estado de identidad** (mismo status, misma forma, mismo trabajo observable, exista o no la cuenta). Un reset con
éxito consume un `SingleUseToken`, fija la nueva password, **revoca TODAS las sesiones** (no «las demás» — quien
resetea arranca sin sesión de confianza, así que ninguna del atacante debe sobrevivir), **limpia `LockedUntil`**
(D-b: la recuperación puentea el lock) y, si la identidad es **no-`ACTIVE`**, aterriza en el muro post-identidad
**sin conceder nada** (D-c).

FR7: **Lockout `LockedUntil`** (D7 · PR-6; depende de PR-3) — `failedAttempts` / `lockedUntil` **persistido en la
identidad** (observable), puesto tras N intentos fallidos para una identidad resuelta, enforced en `checkPostAuth`
como **muro post-identidad «temporalmente bloqueada»**, **limpiado por reset (D-b) / login con éxito / TTL**.
**Complementario** —no sustituto— del `login_throttling` per-IP+email (rate-limiter efímero, que se **queda**): el
throttle no es persistible, observable, post-identidad ni reset-clearable, así que no expresa la máquina ratificada.
La respuesta throttled se **pliega a la forma de fallo pre-identidad** (no delata «demasiados intentos»).

FR8: **Session Lifecycle + Session Registry + Session Admission Gate fail-closed** (D8 · PR-7) — la máquina de sesión
`Active → Revoked | Expired` (**modelo de dominio con ciclo de vida, no una tabla técnica de sesiones**: el registro
existe para materializar esa máquina), encarnada por el agregado `Iam/Session(id UUIDv7, userId, organizationId,
createdAt, lastSeenAt, device, ip, status: Active|Revoked|Expired)` como **source of truth** del ciclo de sesión.
Symfony conserva su **almacenamiento nativo**; el dominio nunca aprende el session id del framework — Infra guarda
el `SessionId` de dominio en el bag Symfony (`iamSessionId`, detalle de adapter, nunca parte del contrato de
dominio) y el **Session Admission Gate** per-request lo relee, carga el registro y **fuerza logout salvo
`status=Active`**. El gate es **fail-closed** (si no puede decidir → la request no continúa; parte del TCB de auth).
Revocación **lógica** (marcar `Revoked`); el borrado físico al GC del handler. **Dos caminos de enforcement**:
de-auth por cambio de credencial vía `refreshUser`/`hasUserChanged` nativo (con el registro actualizado en paso) +
revocación administrativa vía registry+gate. Revoca **una / todas-menos-actual / todas**; superficie «mis sesiones».

FR9: **Contrato de error graduado por confianza** (D12 · PR-3/PR-4) — todo por el pipeline **RFC 9457** (nunca body
manual). **Pre-identidad:** `unauthorized` (401, uniforme para inexistente / password errónea / `INVITED`) +
`invalid-token` (**marker único opaco** para toda muerte de token). **Post-identidad:** `account-locked` +
`account-suspended` (403, **específicos** — cargan un siguiente paso real); `DEACTIVATED` **genérico** (sin paso
accionable). **Infra:** tipo **operacional** (503-family) cuando el gate no puede decidir — **nunca** reusa un tipo
de identidad. Añadir `invalid-token` + los tipos de estado actualiza
[`docs/api-error-contract.md`](../../docs/api-error-contract.md) (NFR26).

FR10: **Hardening anti-enum + email de seguridad** (D10, D11 · PR-8; sobre superficies PR-4/PR-5) — barrido
**constant-time** transversal (hashear **siempre** una password, incl. el camino `INVITED` sin password y
forgot-password) para que el timing no re-enumere lo que el copy oculta; `Referrer-Policy: no-referrer` en pantallas
por token + strip del token de la URL (`history.replaceState`) + **redacción del token en logs** de acceso;
`SecurityEmail` **async** (Messenger, HTTPS-only, escape de contenido dinámico, remitente **no-`no-reply`**);
**rate-limit** en login/forgot/reset/accept (por IP y por cuenta) **sin romper la neutralidad** (mismo status/copy al
saturar).

### NonFunctional Requirements

NFR1 (Security invariant · **rector**): **Indistinguibilidad pre-identidad = timing + status + shape** (SI-12, D10) —
antes de demostrar identidad, `{inexistente, password errónea, INVITED}` son indistinguibles en **latencia, código de
estado y forma/tamaño de respuesta**, no solo en copy: hash dummy **siempre**, forgot uniforme, throttle plegado al
fallo. Transversal a login, forgot, reset e invitaciones (y futuros magic-link/MFA/API).

NFR2 (Security invariant): **Opacidad + higiene del token** (SI-13, D11) — token opaco, single-use, TTL, hasheado en
reposo; **una muerte de token = un único mensaje** (usado/revocado/caducado/aceptado/inexistente colapsan al mismo),
nunca el motivo; `no-referrer` + strip de URL + redacción en logs; el token **nunca** se renderiza en pantalla.

NFR3 (Architectural boundary): **Regla de los tres momentos + regeneración de sesión** (SI-10, D4/D8) — el `SessionId`
se acuña **solo** tras superar la admisión (`credenciales → identidad → admisión → sesión`); **nunca**
`credenciales → sesión`. Los **dos saltos de privilegio** (accept-invitation, reset) acuñan sesión y **regeneran el
id**; ninguno pasa por `json_login`.

NFR4 (Security boundary · TCB): **Gate de sesión fail-closed** (SI-11, D8) — toda request autenticada atraviesa el
Session Admission Gate; si no puede decidir (store caído), **no continúa**. La **cobertura del gate es un invariante
de seguridad, no una feature**: una ruta que autentica pero lo bypassa reabre sesiones revocadas. La revocación
lógica basta **porque** el gate es la frontera de confianza.

NFR5 (Reliability / error contract): **Especificidad graduada por confianza** (SI-14, D12) — la especificidad de un
error depende del **nivel de confianza alcanzado**, no del tipo de error: pre-identidad indistinguible; post-identidad
**observable por diseño** (no «enumerable» — el modelo protege contra enumeración anónima, no contra quien ya controla
credenciales válidas). La frontera es la **demostración de identidad**; 401/403/503 fluyen por RFC 9457, sin marker
manual; `php.lint.error-contract` verde.

NFR6 (Evolvability / tenancy seam): **Seam multi-tenant-ready, tenancy operativa diferida** (SI-15, D2) — todo agregado
carga `organizationId` (id, **nunca** relación tipada); `Membership` autoritativo. Self-signup, tenant-switching y
**enforcement** de scope cross-tenant quedan diferidos a su ADR (el `subject:` del voter RBAC sigue sin evaluar). El
seam id-level ahora es casi gratis y evita la migración single→multi (ownership/URLs/invitaciones/audit/RBAC/APIs).

NFR7 (Maintainability / isolation): **Aislamiento de contexto + placement deptrac-legal** (D1) — `Iam/{Identity,
Invitation,Session}` + `Organization/{Organization,Membership}` registrados en `api/tools/deptrac/deptrac.yaml`
(mirror del bloque Identity); per-aggregate isolation (ningún object graph cruza un módulo; referencia por id).
`php.deptrac` + `php.lint.bounded-context` verdes.

NFR8 (Safety): **Orden safe-first — PR-0 sin cambio de comportamiento** (DAG del addendum) — PR-0 es move/rename puro
(comportamiento idéntico); la fundación aditiva (PR-1/PR-2/PR-3/PR-7) no cambia la superficie pública; el lockout
(PR-6), la 1ª superficie pública nueva (PR-4 invitación), el reset (PR-5) y el hardening transversal (PR-8) van al
final. Ninguna historia depende de una posterior en su orden de merge.

NFR9 (Resiliencia de conectividad · backend): **Mutaciones idempotentes y re-entrables** (UX · resiliencia) — las
operaciones que mutan y acuñan sesión (accept-invitation, reset) toleran **reintento idempotente** tras pérdida de
cobertura (un reintento no crea efectos duplicados: token single-use ya consumido → muro opaco con «Iniciar sesión»,
D-a); el email de seguridad es un handler **idempotente** tolerante a re-entrega. Soporta la postura UX «UX resiliente,
no offline-first».

NFR10 (Observabilidad / auditoría de eventos de seguridad): los eventos de seguridad relevantes —
`InvitationCreated/Sent`, `InvitationResent`, `InvitationRevoked`, `InvitationExpired`, `InvitationAccepted`,
`PasswordResetRequested`, `PasswordResetCompleted`, `SessionCreated`, `SessionRevoked`, `AllSessionsRevoked`,
`UserSuspended`, `UserDeactivated`, `AccountLocked`— deben ser **observables y auditables** (**no meros logs**):
emitidos como **eventos de dominio** por el `EventBus` transaccional (Doctrine transport = outbox) y consumibles por el
**audit trail** regulatorio y por reactores (el `SecurityEmail` async ya es uno). Encaja con el diseño basado en
eventos ya en vigor. **Restricción de coherencia con NFR1:** la emisión de eventos **no rompe la indistinguibilidad
pre-identidad** — un fallo de login pre-identidad (inexistente / password errónea / `INVITED`) no emite un evento que
re-enumere la cuenta; los eventos de identidad/sesión se emiten **post-admisión** o sobre acciones administrativas.

NFR11 (Sustituibilidad del backend de sesiones — seam arquitectónico): el subsistema de sesiones debe poder
**sustituir su backend de almacenamiento** (native storage → `PdoSessionHandler` / store compartido) **sin modificar el
modelo de dominio (`Session`, `SessionId`, ciclo de vida) ni los casos de uso** (admisión, revocación, «mis sesiones»).
El **único acoplamiento a Infra** es el `iamSessionId` en el bag Symfony; el dominio **nunca** aprende el session id del
framework. Este seam es lo que hace del multi-node un **ADR nuevo** (D8 forward-path), no una reescritura del modelo.

### Additional Requirements

- **Registro deptrac de los nuevos módulos:** `Iam/*` + `Organization/*` necesitan su entrada en
  `api/tools/deptrac/deptrac.yaml` como cualquier módulo (mirror del bloque Identity ya existente).
- **Secuenciación con RBAC (opción (b) — *parking explícito*, no topología definitiva):** PR-0 co-mueve el core RBAC
  (`PermissionVoter` + `AuthorizationPolicy` + `StaticAuthorizationPolicy`, **mergeado a `main` en #456**, hoy en
  `Backoffice/Identity/Infrastructure/Security`) a `Iam/Identity/Infrastructure/Security` **como consecuencia física del
  rename** —esos ficheros viven dentro del árbol que PR-0 mueve, así que se mueven igual— **no** porque el modelo diga
  que la autorización pertenezca a Identity: es su **plano ortogonal** (ADR: identidad ≠ autorización ≠ tenancy). Se
  aloja ahí **temporalmente** para mantener PR-0 *move-only* y de mínimo blast radius sobre el TCB de auth; **mover ≠
  terminar** (el comportamiento RBAC aterriza en PRs posteriores). **Follow-up (propiedad del ADR RBAC):** extraer el
  plano de autorización a su **hogar definitivo** (`Access/`, `Iam/Authorization/` o una capacidad `Kernel/Authorization`)
  cuando ese subsistema se retome y pueda decidirse con calma — **no se decide aquí** ni se hipoteca dentro de PR-0. Se
  acepta el coste de mover esos pocos ficheros **dos veces** frente a inflar la PR estructural más sensible de la
  promoción. *(El coste de coordinación que antes lo complicaba desapareció: RM-1 ya está en `main`, #456.)*
  *Precisión de contexto (ADR D1): `Membership` **no «se mueve»**; nace nuevo en el contexto hermano `Organization/`
  (PR-1) — D1 descartó a propósito el paraguas `Iam/` que absorbía `Organization`+`Membership`, para no acoplar tenancy
  a identidad. La co-localización **temporal** de PR-0 es **Identity + core RBAC (parking)**, no Membership.*
- **Actualización del error contract** (`docs/api-error-contract.md`, NFR26): nuevo marker `invalid-token` + tipos
  `account-locked` / `account-suspended` + el tipo **operacional** del gate; y el mapeo pre/post-identidad de FR9.
- **CSRF double-submit stateless:** PR-4 es el **primer consumidor** que cablea el token CSRF que el hermano auth-rbac
  dejó `wire-on-consumer`; el login POST también entra en su alcance (CSRF + regeneración de sesión).
- **Forward-path de sesión (D8 → NFR11):** el modelo es estable para **single-node**; si evoluciona a store compartido
  (multi-node / blue-green / autoscaling) se reabre un **ADR nuevo** (`PdoSessionHandler`-plus-registry vs
  unified-handler) — no una deriva automática. El seam que lo hace barato es **NFR11**.
- **Retiro de deuda de wiring (al cablear, no solo deprecar):** la ruta mock `/register` (alta libre, siembra identidad
  sin invitación) se **retira** (invitation-first) y el copy del `ResetPasswordForm` («…inválido **o ha expirado**») se
  **colapsa** a «Este enlace ya no es válido» (opacidad total del token).
- **Frontera del run UX:** el **backoffice de gestión de miembros** (invitar/reenviar/revocar/activar/desactivar) es
  **contrato** (J5), **no UI en esta épica** — vive en su propio slice (lenguaje visual backoffice), posterior a la
  superficie pública. Magic-link / passwordless / MFA / SSO y org self-signup son **solo costura** (el invariante y los
  flujos las contemplan; dependen de backend inexistente).

### UX Design Requirements

> El run UX `ux-ERPify-2026-07-06` es **input de primera clase**. `EXPERIENCE.md` fija comportamiento/IA/estados/
> journeys; `DESIGN.md` el delta visual (6 componentes nuevos sobre `pwa/DESIGN.md`). Aterrizan en las historias de
> **superficie pública** (PR-4 invitación, PR-5 reset) y su hardening (PR-8); la UI de gestión de sesiones/miembros es
> costura/otro slice. Los seis componentes se listan explícitamente (no se reducen a «crear componentes de acceso»).

UX-DR1: **`AccessWall`** — muro de estado dentro de `AuthLayout` (tarjeta centrada, sin formulario). Variantes
`invalid-link` / `suspended` / `locked` / `session-expired` / `deactivated` que cambian **solo el copy, nunca la
paleta** (jamás `{color.danger}` — no son errores). **Siempre** ofrece «Iniciar sesión» (D-a) además de la acción
específica genérica; **opacidad total** en muros de token; los muros específicos (`suspended`/`locked`) solo
**post-identidad**; se alcanza en frío (token inválido) o como estado (login→muro, sesión expirada→puente re-login).

UX-DR2: **`TokenActionScreen`** — shell de B3 reset + B4 accept-invitation. **Un solo campo de contraseña con toggle**
(no doble campo de confirmación) que arranca **REVELADO** en pantallas de token (se *crea* la contraseña); el teclado
no tapa el botón; **anunciar contexto de página antes del autofocus** (lector de pantalla que llega del email);
reglas de contraseña en el **Zod schema** (`.max()`, **nunca `maxLength`**); no pierde datos ante caída de red;
envío vía `ConnectivityButton`; **el token nunca se renderiza**; al éxito → `SecuritySignal` + navega (ERP para B4,
B1 para B3). Salvavidas: tras aceptar la identidad ya es `ACTIVE`, así que un typo se recupera por forgot (B2), no por
re-invitación.

UX-DR3: **`ConnectivityButton`** — botón resiliente (máquina de estados de red, no aspecto): idle → loading (spinner,
etiqueta permanece) → **disabled en vuelo** (`aria-busy`, bloquea doble-envío) → **retry** idempotente. Conserva el
foco; **no** lanza toast de éxito (la confirmación es `SecuritySignal`).

UX-DR4: **`OfflineNotice`** — aviso in-form de red caída (`aria-live="polite"`, `{color.warning}`/`-strong`, **no
rojo**) **sobre** el botón, que **conserva íntegro** lo tecleado; emparejado con el estado `retry`. Advertencia, no
error; nunca destructivo.

UX-DR5: **`SecuritySignal`** — confirmación calmada post-acción de seguridad, **siempre con el siguiente paso**
(dot `{color.success}`, sin animación/celebración). Variantes: `password-changed` con **dos copys** (reset J2 vs
cambio desde Mi-cuenta J6), `invitation-accepted`, `sessions-closed`. Al navegar a él, **mover el foco al `<h1>`** y
anunciarlo (el foco no se reubica solo).

UX-DR6: **`SecurityEmail`** — **contrato de plantilla** (no componente React) estilo bancario mínimo: cabecera plana
sin hero, una frase de propósito, **un** enlace/botón prominente bulletproof (estilos inline, área de toque grande en
móvil), pie legal mínimo. Remitente **nunca `no-reply`**; **pila de sistema** (webfont no fiable en correo);
**dark-mode-aware** con literales de rama (`#2f5cd9` claro / `#6c9bff` oscuro — AA en ambas). Variantes: invitación ·
restablecer contraseña · aviso «tu contraseña ha cambiado» · (opcional) invitación revocada.

UX-DR7: **Superficies + IA de acceso** — A1 entrada landing (**CTA único** «Iniciar sesión / Acceder al ERP», **no
hay «crear cuenta»**, lenguaje marketing); B1 login (`noindex`); B2 forgot; B3 reset (`?token=`); B4 accept-invitation
(`?token=`); C1 access walls; **todas `noindex`**. Estados de B1: idle · enviando · **credenciales inválidas =
mensaje neutro único** · `LockedUntil`→muro post-identidad · offline · error técnico. **Retiro de `/register`**
(invitation-first) y **sin superficie de tenant** (forward-compat: cuando llegue tenancy solo se **añaden** caminos).

UX-DR8: **Microcopy pre-identidad neutro (invariante · i18n)** — login «Correo o contraseña incorrectos.» (**un solo
mensaje** para inexistente/password/no-elegible); forgot «Si esa dirección corresponde a una cuenta, te enviaremos un
enlace…» (**idéntico** exista o no); reset/token no elegible «Este enlace ya no es válido» (**nunca** «caducado /
usado / revocado»). Español primero; **ninguna cadena hardcodeada** (contenido traducible).

UX-DR9: **Suelo de accesibilidad WCAG 2.2 AA** — gestión de foco en flujos por token (autofocus inicial, **focus-return
al primer campo inválido**, foco visible 2 px); `{TokenActionScreen}`/`{AccessWall}` son **documentos, no diálogos**
→ **orden de foco natural** (sin focus-trap; `{Logo}`/`{ThemeToggle}` alcanzables — evita keyboard-trap); **mover el
foco al `<h1>`** en transiciones SPA (éxito de token / login→muro / sesión-expirada→re-login); exactamente **un
`<h1>`** por superficie; **el color nunca es canal único**; nombres accesibles **estáticos**; `aria-live`
(**assertive** para fallo real 5xx/red vía `ProblemDisplay`/`MutationError`; **polite** para `OfflineNotice`; ninguno
mueve el foco); `prefers-reduced-motion`; dark mode; ergonomía de una mano (target ≥ 44 px).

UX-DR10: **Resiliencia de conectividad (postura fundacional)** — cinco principios de primera clase: estado de carga
claro · anti doble-envío · formularios que no pierden datos · reintentos idempotentes · errores recuperables (nunca
callejón sin salida). Deep-link `?next=` guardado por `RequireAuth` (`encodeURIComponent`) y **validado** en B1 con
`safeInternalPath(next, Routes.BACKOFFICE)` + `safeHref` (rechaza off-origin / `//host` / esquemas peligrosos); una
sesión expirada **preserva** `?next=`.

UX-DR11: **Modelo de sesión (UI mínima, modelo mental completo)** — «**dispositivo actual**» distinguible del resto;
«cerrar las demás sesiones» **nunca autoexpulsa**; la expiración es comportamiento diario **sin pantalla propia**
(vuelta a B1 conservando `?next=` + mensaje breve). Multi-sesión detallada / granularidad por-dispositivo = **costura**;
J6 (cambiar contraseña desde *Mi cuenta*, confluencia Session+Authentication+Identity) = **extension point** con el
modelo mental presente ahora.

### ADR Decision Coverage

Verificación de que ninguna decisión / invariante queda huérfano. Tabla **decisión→requisito**; el mapa
**requisito→historia** (`FR Coverage Map`) se rellena en el Paso 2.

| Decisión ADR | Requisito(s) |
|--------------|--------------|
| D1 — promoción a `Iam/` + `Organization/` sibling | FR1; NFR7, NFR8 |
| D2 — dominio multi-tenant-ready, tenancy diferida | FR2; NFR6 |
| D3 — dos máquinas paralelas → `User` nace `INVITED` | FR3 |
| D4 — admisión = `UserChecker` (tres momentos) | FR3; NFR3; FR9 |
| D5 — agregado `Invitation`; accept bajo CSRF + regeneración | FR5 |
| D6 — building block `Shared/Token` (`SingleUseToken`) | FR4 |
| D7 — lockout `LockedUntil` persistido, complementario al throttler | FR7 |
| D8 — Session Lifecycle + registry + gate fail-closed; storage nativo, revocación lógica | FR8; NFR3, NFR4, NFR11 |
| D9 — reset uniforme, revoca todas las sesiones, limpia el lock | FR6 |
| D10 — Invariante 1 (indistinguibilidad = timing+status+shape) | NFR1; FR10 (constant-time); UX-DR8 |
| D11 — Invariante 2 (opacidad + higiene del token) | NFR2; FR5/FR10 (higiene); UX-DR1/UX-DR2 |
| D12 — contrato de error graduado por confianza | FR9; NFR5 |

Invariantes globales: **SI-10**→NFR3 · **SI-11**→NFR4 · **SI-12**→NFR1 · **SI-13**→NFR2 · **SI-14**→NFR5 ·
**SI-15**→NFR6. Reglas de proyección UX **D-a**→UX-DR1 · **D-b**→FR6/FR7 · **D-c**→FR3/FR6. Requisitos transversales
derivados (no atados a una sola decisión): **NFR10** (observabilidad de eventos de seguridad) ← J5 (costura
`Acción→Evento→Estado→Email→Superficie`) + arquitectura event-bus/outbox + audit trail; **NFR11** (sustituibilidad del
backend de sesiones) ← D8 forward-path. Sin decisiones ni invariantes huérfanos.

### FR Coverage Map

Todas las FR/NFR/UX-DR pertenecen a la única épica `identity-invitation-lifecycle`; el mapa desglosa a nivel de
historia (II-N ⟷ PR-N del DAG):

- **FR1 → II-0** — promoción `Iam/` + `Organization/` (+ co-move del core RBAC, opción b).
- **FR2 → II-1** — `Organization` + `Membership` + bootstrap CLI.
- **FR4 → II-2** — `Shared/Token` (`SingleUseToken`).
- **FR3 → II-3** — `IdentityStatus` + `UserChecker` (admisión, muros sin sesión).
- **FR8 → II-7** — Session Lifecycle + registry + gate fail-closed.
- **FR7 → II-6** — lockout `LockedUntil`.
- **FR5 → II-4** — `Invitation` + accept (CSRF + Origin + regeneración).
- **FR6 → II-5** — reset uniforme (revoca-todas, limpia lock).
- **FR9 → II-3 (tipos de estado) · II-4 (`invalid-token`)** — contrato de error graduado.
- **FR10 → II-8** — hardening constant-time + `SecurityEmail` async.

**Cobertura NFR:** **NFR1/NFR2/NFR5** (invariantes de seguridad) nacen en II-3/II-4 y se **verifican transversalmente**
en cada historia de superficie, endurecidas en II-8; **NFR3** (tres momentos + regeneración) en II-3/II-4/II-7; **NFR4**
(gate TCB) + **NFR11** (sustituibilidad) en II-7; **NFR6** (seam tenant) en II-1; **NFR7/NFR8** (deptrac / safe-first) en
II-0 y como gate en cada historia; **NFR9** (idempotencia) en II-4/II-5; **NFR10** (observabilidad de eventos) en
II-4/II-5/II-6/II-7 y consolidada como criterio en II-8.

**Cobertura UX-DR:** **UX-DR1** (`AccessWall`) en II-3 (suspended/deactivated) · II-6 (locked) · II-7 (session-expired) ·
II-4 (invalid-link); **UX-DR2** (`TokenActionScreen`) en II-4 (accept) · II-5 (reset); **UX-DR3/4/5**
(`ConnectivityButton` / `OfflineNotice` / `SecuritySignal`) en II-4 y reutilizados en II-5; **UX-DR6** (`SecurityEmail`)
en II-4 (invitación) · II-5 (reset/changed) · II-8 (async + headers); **UX-DR7/8** (superficies + microcopy) en II-3
(estados de B1) · II-4 (retiro `/register`) · II-5 (copy de reset); **UX-DR9** (AA) transversal a toda historia de UI;
**UX-DR10** (resiliencia) en II-4/II-5; **UX-DR11** (modelo de sesión) en II-7. Sin FR/NFR/UX-DR huérfanos.

## Epic List

**Una sola épica.** El diseño está congelado (ADR D1–D12 **aceptado**, addendum **`frozen-ready`**, run UX **`final`**
validado por 4 lentes) y el DAG es un grafo conexo enraizado en PR-0 con **fuerte churn compartido** sobre `Iam/Identity`
(`User`, `UserChecker`, `security.yaml`) y sobre los 6 componentes de UI de acceso: no hay frontera de riesgo capaz de
cambiar la dirección de un bloque posterior, así que la guía «outcome cierto → menos épicas, más grandes» + «consolidar
lo que toca los mismos ficheros» aplica de lleno (precedente: `epics-rbac-authorization-model.md`). *Alternativa
descartada:* partir en «fundación + admisión» vs «recuperación + endurecimiento» — legítima (la 2ª construiría sobre la
1ª) pero fragmenta un subsistema de **un solo lenguaje ubicuo**, crea dependencias cross-épica (el reset depende del
registro de sesión) y **no compra feedback temprano** porque el modelo ya está validado por los journeys J1–J6+V1 «sin
ningún estado nuevo».

### Epic identity-invitation-lifecycle: Onboarding invitation-first, admisión y ciclo de vida de sesiones

Tras la épica, una persona **invitada** acepta su invitación desde el email y **cae dentro del ERP ya operativa** (sin
«crear cuenta»); un usuario **recupera su acceso** por forgot/reset; los estados de cuenta (`SUSPENDED` / `DEACTIVATED` /
`LockedUntil` / sesión expirada) se proyectan en **muros post-identidad** que no filtran nada a un atacante anónimo; y
cada sesión es **revocable** desde un registro server-side con un gate fail-closed. El subsistema de identidad vive en
contextos top-level `Iam/{Identity,Invitation,Session}` + `Organization/{Organization,Membership}`, **multi-tenant-ready**,
tras los dos invariantes (indistinguibilidad pre-identidad · opacidad del token) y la regla de los tres momentos.
**Fuera de alcance (costura):** backoffice de gestión de miembros (contrato J5), gestión multi-sesión detallada,
magic-link / MFA / SSO, org self-signup (diferido al ADR de tenancy). **FRs:** FR1–FR10. **NFRs:** NFR1–NFR11.
**UX-DR:** UX-DR1–UX-DR11.

**Historias (II-N ⟷ PR-N del DAG; detalle en el Paso 3):**

- **II-0 (PR-0) — promoción de contexto (estructural, sin comportamiento):** mover `Backoffice/Identity` → `Iam/Identity`
  (`User`, `SecurityUser`, provider, authenticator, `SecurityActorContextFactory`) + crear `Iam/Invitation` / `Iam/Session`
  + `Organization/`; **co-mover el core RBAC** (`PermissionVoter` + `AuthorizationPolicy`, ya en `main` vía #456) a
  `Iam/Identity/Infrastructure/Security` como **parking temporal** (consecuencia física del rename, no topología
  definitiva; **follow-up:** extraer el plano de autorización a su hogar propio); actualizar `security.yaml` + registrar
  `Iam/*` y `Organization/*` en `deptrac.yaml`. Move/rename **sin cambio de comportamiento**. — FR1; NFR7, NFR8.
- **II-1 (PR-1) — `Organization` + `Membership` + bootstrap CLI:** agregados `Organization/Organization` y
  `Organization/Membership(userId, organizationId, roles)` (enlace autoritativo, roles asignados **antes** de aceptar);
  CLI `ProvisionOrganization` + `CreateInitialAdministrator` (una org/instalación); nunca credenciales en migración;
  **seam `organizationId`** en los agregados. — FR2; NFR6.
- **II-2 (PR-2) — `Shared/Token` (`SingleUseToken`):** VO `SingleUseToken` (alta entropía · single-use · TTL · hash-at-
  rest) + generador CSPRNG + hasher + **verificación constant-time**. Prereq de II-4/II-5. — FR4.
- **II-3 (PR-3) — `IdentityStatus` + `UserChecker` + muros post-identidad + tipos de error:** enum `IdentityStatus`
  (sin `PENDING`) + `HashedPassword` nullable-hasta-`ACTIVE`; `UserChecker` tres-momentos (`checkPreAuth` INVITED
  uniforme; `checkPostAuth` muros `SUSPENDED`/`DEACTIVATED`/`LockedUntil` **sin sesión**); tipos `account-locked` /
  `account-suspended` / `DEACTIVATED`-genérico + operacional del gate → `api-error-contract.md`; wiring frontend de los
  muros post-login (`AccessWall`) + estados de B1. — FR3, FR9 (tipos de estado); NFR3, NFR5; UX-DR1/7/8/9.
- **II-7 (PR-7) — Session Lifecycle + registry + gate fail-closed** — *habilitador transversal / **infraestructura del
  modelo**, no una feature funcional: condiciona `revoke-all`, `revoke-current`, reset, suspend y deactivate; más que
  una historia de usuario, es la costura sobre la que descansan las demás:* agregado `Iam/Session`
  (`Active→Revoked|Expired`) + **Session Admission Gate** per-request fail-closed (correlación `iamSessionId` en el bag,
  infra-only); revocación una / todas-menos-actual / todas; dos caminos (`refreshUser` nativo + registry); **eventos de
  sesión** (NFR10); UI mínima «mis sesiones» + muro sesión-expirada. — FR8; NFR3, NFR4, NFR10, NFR11; UX-DR11.
- **II-6 (PR-6) — lockout `LockedUntil`:** `failedAttempts` / `lockedUntil` persistido en la identidad, enforced en
  `checkPostAuth`, limpiado por reset (D-b) / login / TTL; neutralidad del `login_throttling`; contenido del muro
  `locked`. — FR7; NFR5; UX-DR1 (locked).
- **II-4 (PR-4) — `Invitation` + accept (1ª superficie pública nueva):** agregado `Iam/Invitation`
  (`CREATED→SENT→ACCEPTED|REVOKED|EXPIRED`); POST accept **fuera del firewall** (`Origin` + **CSRF** + **regeneración de
  sesión**) `INVITED→ACTIVE` + acuña `Session`; invite / resend / revoke; marker `invalid-token`; higiene token-en-URL;
  **wiring de las 6 pantallas/componentes** (`TokenActionScreen` accept · `AccessWall` invalid-link · `ConnectivityButton`
  · `OfflineNotice` · `SecuritySignal` invitation-accepted · `SecurityEmail` invitación); **retiro de `/register`**. —
  FR5, FR9 (`invalid-token`); NFR2, NFR3, NFR9, NFR10; UX-DR1–6 / 7 / 8 / 9 / 10.
- **II-5 (PR-5) — reset de contraseña uniforme:** forgot/reset **uniformes** (status + shape invariables); reset consume
  token, **revoca TODAS** las sesiones, **limpia `LockedUntil`**, no-`ACTIVE`→muro; `TokenActionScreen` reset +
  `SecuritySignal` password-changed + `SecurityEmail` reset/changed; **colapso del copy** «…o ha expirado». Depende de
  II-2 + II-3 + **II-7**. — FR6; NFR1, NFR9, NFR10; UX-DR2 / 5 / 6 / 8 / 9 / 10.
- **II-8 (PR-8) — endurecimiento de seguridad transversal (*cross-cutting security hardening*):** **no es «solo
  emails»** — cierra el conjunto transversal de garantías sobre las superficies ya existentes: barrido **constant-time**
  (hash dummy en todos los caminos, incl. `INVITED` / forgot) · **higiene del token** (`Referrer-Policy: no-referrer` +
  strip de URL + **redacción en logs**) · `SecurityEmail` **async** (Messenger, HTTPS-only, escape de contenido dinámico,
  remitente no-`no-reply`) · **rate-limit** login/forgot/reset/accept **sin romper la neutralidad** (mismo status/copy al
  saturar). Depende de las superficies II-4/II-5. — FR10; NFR1, NFR2, NFR10.

**Orden de ejecución/merge (safe-first — aditivo/estructural primero, comportamiento y superficie pública al final):**
`II-0 → (II-1 · II-2 · II-3 · II-7) → II-6 → II-4 → II-5 → II-8`. La numeración II-N sigue el PR-N del DAG; el **orden de
merge** respeta las dependencias, no el número.

**Dependencias:** II-0 desbloquea todo (y **co-mueve el core RBAC**, opción b). II-4 exige II-2 (token) + II-3 (status) +
**II-7** (registro de sesión, para acuñar la primera `Session` sobre el agregado en vez de una sesión nativa desechable
— *tightening* sobre el DAG mínimo del addendum, ya garantizado por el orden safe-first que sitúa II-7 antes).
II-5 exige II-2 + II-3 + **II-7** (revoca-todas). II-6 exige II-3. II-7 exige II-0. II-8 exige las superficies II-4/II-5.
El contrato de error (FR9) se estrena en II-3 (tipos de estado) y se extiende en II-4 (`invalid-token`). Cada historia
cabe en el contexto de un único dev agent; ninguna depende de una historia **posterior** en su orden de merge.

---

## Epic identity-invitation-lifecycle: Onboarding invitation-first, admisión y ciclo de vida de sesiones

Introduce el subsistema de identidad/invitación completo: onboarding **invitation-first**, admisión de **tres
momentos**, recuperación de contraseña, lockout y un **registro de sesiones** con gate fail-closed, todo en contextos
top-level `Iam/{Identity,Invitation,Session}` + `Organization/{Organization,Membership}` multi-tenant-ready, tras los
dos invariantes de seguridad. **Orden de merge safe-first** (`II-0 → II-1·II-2·II-3·II-7 → II-6 → II-4 → II-5 → II-8`);
la numeración II-N sigue el PR-N del DAG. Ninguna historia depende de una posterior en su orden de merge.

> **Método de las historias (criterio de AC basado en invariantes).** Cada historia declara — además del AC funcional —
> el **comportamiento que introduce**, los **invariantes que consume** y los **invariantes que establece**; los AC se
> redactan como **invariantes verificables** enganchados al ADR (D1–D12), a los System Invariants (SI-10…SI-15) y a las
> reglas de proyección (D-a/D-b/D-c), de modo que una refactorización futura no pueda romper una garantía sin que un
> test la detecte. Los invariantes de seguridad transversales (SI-12 timing, SI-13 higiene) nacen aquí y se **cierran
> multicanal** en II-8.

### Story II-0 (PR-0): Promoción de contexto `Iam/` + `Organization/` con parking del core RBAC (estructural, sin comportamiento)

Como plataforma de ERPify,
quiero promover el subsistema de identidad a contextos top-level `Iam/` + `Organization/` moviendo con él el core RBAC,
para que las capacidades IAM emergentes (invitación, reset, lockout, sesiones) tengan su hogar de dominio sin acoplarse
a un área de negocio — **sin cambiar todavía ningún comportamiento**.

**Comportamiento que introduce:** ninguno observable (move/rename puro; *mover ≠ terminar*).

**Invariantes que consume:** SI-1…SI-9 (firewall de sesión, `User` libre de framework, enum `Role`) — se preservan intactos.
**Invariantes que establece:** la ubicación canónica del subsistema es `Iam/{Identity,Invitation,Session}` +
`Organization/{Organization,Membership}`; el core RBAC queda **parqueado** en `Iam/Identity/Infrastructure/Security`
(ubicación **temporal**, no canónica — su plano propio se extrae en un follow-up del ADR RBAC); `Iam/*` y
`Organization/*` son módulos deptrac-registrados; **el comportamiento observable es idéntico al previo** (no-regresión).

**Acceptance Criteria:**

**Given** `Backoffice/Identity`,
**When** se promueve,
**Then** `User` / `SecurityUser` / `UserProvider` / authenticator / `SecurityActorContextFactory` viven bajo
`Iam/Identity`, `security.yaml` referencia las clases nuevas y `Backoffice/Identity` deja de existir (FR1, D1).

**Given** el core RBAC (`PermissionVoter` + `AuthorizationPolicy` + `StaticAuthorizationPolicy`, ya en `main` vía #456),
**When** se co-mueve (**parking temporal** — consecuencia física del rename, no topología definitiva),
**Then** vive en `Iam/Identity/Infrastructure/Security` con **su comportamiento intacto** — *mover ≠ terminar*: ninguna
ruta cambia su decisión de autorización respecto de RM-1; queda **registrado el follow-up** de extraer el plano de
autorización a su hogar propio (Additional: secuenciación con RBAC).

**Given** los módulos nuevos,
**When** se corren los gates,
**Then** `Iam/*` y `Organization/*` están registrados en `api/tools/deptrac/deptrac.yaml` (mirror del bloque Identity) y
`php.deptrac` + `php.lint.bounded-context` verdes (NFR7).

**Given** una referencia cross-module,
**When** exista,
**Then** es por id (`private string $userId`), nunca `#[ORM\ManyToOne]` a la entidad de otro módulo (D1, per-aggregate isolation).

**Given (invariante de no-regresión)** la suite `make app.test` + `make app.quality`,
**When** corren pre y post promoción,
**Then** pasan **idénticas** — cero cambio de comportamiento observable (NFR8).

**Given** los módulos hermanos,
**When** se crean,
**Then** `Iam/Invitation` e `Iam/Session` existen como esqueletos deptrac-legales sin lógica (habilitan II-4/II-7 sin adelantarlos).

### Story II-1 (PR-1): `Organization` + `Membership` + bootstrap CLI de la primera organización

Como administrador de la instalación,
quiero provisionar la organización y el primer administrador por CLI, con la pertenencia usuario↔organización como enlace autoritativo,
para que exista una organización propietaria de identidades y roles desde el arranque, sin alta pública ni credenciales en migración.

**Comportamiento que introduce:** comandos CLI de bootstrap; el modelo `organizationId` + `Membership`.

**Invariantes que consume:** la ubicación `Organization/` (II-0).
**Invariantes que establece:** SI-15 — todo agregado de identidad carga `organizationId` (id, nunca relación tipada);
`Membership(userId, organizationId, roles)` es el **único** enlace autoritativo user↔org (ningún user es «global»); los
roles son org-scoped y existen **antes** de que el user sea `ACTIVE`; **una** organización por instalación; **ninguna
credencial en migración**. **Invariante de titularidad:** la organización mantiene **siempre ≥1 `ADMIN` activo** — regla
de **dominio**, no un tier `OWNER` nuevo (la titularidad billing/legal/transferencia queda como concepto futuro de
`Organization`/tenancy, SRP).

**Acceptance Criteria:**

**Given** `Organization/`,
**When** se modela,
**Then** existen `Organization` y `Membership(userId, organizationId, roles)` como agregados que referencian `User` **por id** (SI-15, D2).

**Given** el CLI `ProvisionOrganization` + `CreateInitialAdministrator`,
**When** se ejecutan,
**Then** crean una org y un `User` administrador cuyo `Membership` lleva el rol **`ADMIN`** (tier máximo; **sin**
`OWNER`/`SUPER_ADMIN`/`ROOT` — un rol superior sería una evolución explícita del modelo, YAGNI hoy); un segundo
`ProvisionOrganization` se rechaza o es idempotente (una org/instalación) (FR2, R5).

**Given (invariante de datos)** cualquier migración generada,
**When** se inspecciona,
**Then** **no** contiene credenciales ni PII; el password del admin se fija por CLI, nunca en el schema (FR2, security).

**Given (invariante SI-15)** un `User` en un flujo válido,
**When** se consulta su pertenencia,
**Then** tiene exactamente un `Membership` con su `organizationId` y sus roles; **no existe** un `User` sin `Membership`.

**Given** dev/test,
**When** se siembran datos,
**Then** vía Alice fixtures (nunca en migración).

**Given (invariante ≥1 ADMIN activo)** una organización en cualquier estado válido,
**When** se consulta,
**Then** tiene **al menos un `Membership` con rol `ADMIN` cuyo `User` está `ACTIVE`** — es un **invariante de dominio**,
no un tier RBAC nuevo. El bootstrap lo satisface con el primer admin; su **preservación** bajo `suspend`/`deactivate` se
verifica en II-3, y bajo `demote`/`remove` (cambio de rol / baja de `Membership`) en el **slice diferido de gestión de
miembros** — no en esta épica. Protege contra el lockout de titularidad sin introducir `OWNER`/`SUPER_ADMIN` (R5, YAGNI).

### Story II-2 (PR-2): `Shared/Token` — `SingleUseToken` constant-time (prerrequisito de invitación y reset)

Como responsable de seguridad,
quiero un único building-block de token de un solo uso, TTL, hasheado en reposo y verificado en tiempo constante,
para que invitación y reset compartan exactamente el mismo mecanismo y no puedan divergir en su seguridad.

**Comportamiento que introduce:** la capacidad `Shared/Token` (aún sin consumidores).

**Invariantes que consume:** — (aditivo puro).
**Invariantes que establece (base de SI-13):** el token es alta-entropía, single-use, TTL-bound, **hasheado en reposo**
(el crudo nunca persiste) y su verificación es **constant-time**; **un único** mecanismo compartido (no dos verificadores).

**Acceptance Criteria:**

**Given** `Shared/Token`,
**When** se modela,
**Then** `Domain/SingleUseToken` es un VO (entropía ≥ umbral, TTL, sin framework) y el crudo **nunca** se persiste — solo su hash (D6, SI-13).

**Given (invariante constant-time)** un token presentado,
**When** se compara con el hash almacenado,
**Then** la comparación es **constant-time** (sin short-circuit) — un test lo prueba (D6).

**Given (invariante de opacidad de causa)** un token consumido o con TTL lapsado,
**When** se re-verifica,
**Then** falla **sin distinguir** single-use de expiración (alimenta SI-13 en los consumidores).

**Given** los dos consumidores futuros (invitación, reset),
**When** ambos usan `Shared/Token`,
**Then** comparten el **mismo** generador/hasher/verificador — no hay un segundo verificador hand-rolled (D6).

**Given** el placement,
**When** se corre deptrac,
**Then** `Shared/Token` es importable por `Iam/*` (cross-module en `Shared/`, mirror de `Shared/Uuid`) y `php.deptrac` verde (NFR7).

### Story II-3 (PR-3): `IdentityStatus` + `UserChecker` (admisión de tres momentos) + tipos de error post-identidad

Como sistema de admisión,
quiero un estado de identidad y un `UserChecker` que rechace las identidades no elegibles entre demostrar credenciales y acuñar sesión,
para que «autenticado» nunca implique «admitido» y los muros post-identidad no acuñen ninguna sesión ni filtren estado a un anónimo.

**Comportamiento que introduce:** el enum `IdentityStatus`, la nullabilidad del password, los muros de admisión en el login existente y los tipos de error de estado.

**Invariantes que consume:** el `User` en `Iam/Identity` (II-0); el firewall de sesión (SI-1).
**Invariantes que establece:** SI-10 (`checkPostAuth` es el punto de admisión, **entre** identidad demostrada y sesión);
**D-c parcial** (`SUSPENDED`/`DEACTIVATED` prueban identidad pero **no reciben sesión** — muro stateless desde el body del
POST); **SI-12 parcial** (`checkPreAuth` trata `INVITED` como **pre-identidad**, indistinguible de inexistente); **SI-14/D12**
(post-identidad `account-suspended` específico, `DEACTIVATED` genérico, todo por RFC 9457).

**Acceptance Criteria:**

**Given** `User`,
**When** se modela el estado,
**Then** `IdentityStatus ∈ {INVITED, ACTIVE, SUSPENDED, DEACTIVATED}` (**sin `PENDING`**) y `HashedPassword` es **nullable hasta `ACTIVE`** (FR3, D3).

**Given (invariante SI-10 · tres momentos)** credenciales válidas para una identidad,
**When** el firewall autentica,
**Then** **ninguna sesión se acuña antes** de que `checkPostAuth` evalúe la admisión (`credenciales → identidad → admisión → sesión`); un test falla si se observa `Session`/cookie antes de la admisión.

**Given (invariante D-c)** una identidad `SUSPENDED` o `DEACTIVATED` con credenciales **correctas**,
**When** intenta login,
**Then** **no existe** ninguna sesión, cookie ni token reanudable tras la respuesta; el muro se renderiza desde el body del POST (FR3, D-c).

**Given (invariante SI-12)** una identidad `INVITED` (sin password),
**When** intenta login,
**Then** la respuesta es **indistinguible** de «email inexistente» y «password errónea» en **status y forma** (`checkPreAuth` como pre-identidad); un test compara las tres respuestas (SI-12 — el timing se cierra en II-8).

**Given (invariante SI-14/D12)** los muros post-identidad,
**When** se mapean errores,
**Then** `account-suspended` (403, específico) y `DEACTIVATED` (genérico) fluyen por **RFC 9457** sin body manual;
`docs/api-error-contract.md` actualizado; `php.lint.error-contract` verde (FR9).

**Given** la superficie B1,
**When** un login devuelve identidad no-`ACTIVE`,
**Then** el cliente proyecta el `AccessWall` correspondiente (suspended/deactivated) con foco al `<h1>` (UX-DR1/7/9); el muro `locked` se cablea en II-6.

**Given (invariante ≥1 ADMIN · caminos en alcance de esta épica)** una transición `ACTIVE→SUSPENDED` o `ACTIVE→DEACTIVATED`,
**When** se aplica sobre una identidad,
**Then** se **rechaza** si dejaría a la organización con **0 `ADMIN` activos** — el **último administrador no puede ser
suspendido ni desactivado** (invariante de II-1). El enforcement de `demote`/`remove` viaja con el **slice diferido de
gestión de miembros**, no con esta historia.

### Story II-7 (PR-7): Session Lifecycle + Session Registry + Session Admission Gate fail-closed (habilitador transversal)

Como responsable de seguridad,
quiero un registro server-side de sesiones con un gate fail-closed en cada request autenticada,
para que toda sesión sea enumerable y revocable, y **ninguna sesión revocada alcance jamás un controlador**.

**Comportamiento que introduce:** el agregado `Session`, el gate per-request, la revocación (una / todas-menos-actual /
todas), la UI mínima «mis sesiones» + muro sesión-expirada. *Es infraestructura del modelo, no una feature: condiciona
revoke-all, revoke-current, reset, suspend y deactivate.*

**Invariantes que consume:** la admisión completada (II-3) — una sesión solo se acuña tras admisión (SI-10).
**Invariantes que establece:**
- **SI-11:** toda request autenticada atraviesa el gate; si no puede decidir (store caído), **no continúa** (fail-closed, TCB).
- Ninguna `Session` con estado ≠ `Active` alcanza un controlador (la revocación **lógica** basta porque el gate es la frontera).
- El dominio **nunca** aprende el session id del framework (**NFR11** — `iamSessionId` en el bag es el único acoplamiento a Infra).
- Los eventos de sesión (`SessionCreated`/`SessionRevoked`/`AllSessionsRevoked`) son observables (NFR10).

**Acceptance Criteria:**

**Given** `Iam/Session`,
**When** se modela,
**Then** es un agregado libre de framework (`id UUIDv7, userId, organizationId, createdAt, lastSeenAt, device, ip, status: Active|Revoked|Expired`), source of truth del ciclo `Active→Revoked|Expired` (FR8, D8).

**Given (invariante SI-11 · cobertura)** **cualquier** request autenticada,
**When** llega al firewall,
**Then** atraviesa el Session Admission Gate, que carga el `Session` por `iamSessionId` y **fuerza logout salvo `status=Active`**; un test/checklist falla si una ruta autenticada **bypassa** el gate.

**Given (invariante fail-closed)** el store de sesiones no disponible,
**When** el gate intenta decidir,
**Then** la request **no continúa** (no fail-open) y responde el tipo **operacional** (503-family), nunca un tipo de identidad (SI-11, D12).

**Given (invariante de revocación lógica)** una `Session` marcada `Revoked`,
**When** llega su siguiente request,
**Then** es inerte — el gate la rechaza **antes** del controlador; no se requiere borrado físico (D8).

**Given (invariante NFR11 · sustituibilidad)** el modelo de dominio (`Session`, `SessionId`, casos de uso admisión/revocación),
**When** se cambia el backend de almacenamiento (native → `PdoSessionHandler`/store compartido),
**Then** **no** se modifica el dominio ni los casos de uso — solo el adapter; un test verifica que el dominio no referencia el session id del framework (NFR11).

**Given** las operaciones de revocación,
**When** se ejecutan,
**Then** existen **una / todas-menos-actual / todas**; «cerrar las demás» **nunca** autoexpulsa la sesión actual (UX-DR11, J6).

**Given** el camino de cambio de credencial,
**When** cambia el password,
**Then** el `refreshUser`/`hasUserChanged` nativo de-autentica y el registro `Session` se actualiza en paso (complementario, no redundante) (D8).

**Given (invariante NFR10 · coherente con SI-12)** cada transición de sesión,
**When** ocurre,
**Then** emite su evento de dominio por el `EventBus`/outbox, **sin** emitir eventos que re-enumeren en fallos pre-identidad.

**Given** la UI mínima,
**When** el usuario abre «mis sesiones»,
**Then** ve la lista con el «dispositivo actual» distinguible; una sesión expirada vuelve a B1 conservando `?next=` (UX-DR11).

### Story II-6 (PR-6): Lockout `LockedUntil` (estado de autenticación observable, complementario al throttler)

Como responsable de seguridad,
quiero un bloqueo de autenticación persistido y observable tras N intentos fallidos, limpiable por reset/login/TTL,
para que el abuso de credenciales sobre una identidad resuelta se frene con un muro post-identidad, sin delatar nada a un anónimo.

**Comportamiento que introduce:** `failedAttempts` / `lockedUntil` en la identidad; el muro `locked`; el tipo `account-locked`.

**Invariantes que consume:** el `UserChecker.checkPostAuth` (II-3, extensible); SI-12 (la respuesta pre-identidad ya es neutra).
**Invariantes que establece:** la máquina de autenticación `Unlocked/LockedUntil(T)` es **ortogonal** a la identidad
(`ACTIVE+Locked` válido; `SUSPENDED+Locked` **imposible**); el lock es sobre **intentos de contraseña**, no sobre la
identidad (habilita D-b en II-5); el `login_throttling` efímero **no delata** (su respuesta se pliega al fallo pre-identidad).

**Acceptance Criteria:**

**Given** una identidad resuelta con N intentos fallidos,
**When** se supera el umbral,
**Then** `lockedUntil` se persiste y `checkPostAuth` la rechaza como **`account-locked`** (403, post-identidad, con siguiente paso «Recuperar mi acceso») (FR7, D7, SI-14).

**Given (invariante post-identidad)** un atacante anónimo,
**When** provoca fallos sobre una cuenta,
**Then** **nunca** ve el muro `locked` — solo aparece **tras** credenciales válidas (SI-12 / tres momentos); un test lo prueba.

**Given (invariante de ortogonalidad)** una identidad `SUSPENDED`,
**When** intenta login,
**Then** ve el muro `suspended`, no `locked` — `SUSPENDED+Locked` no es representable (máquina de auth ortogonal a la identidad).

**Given** un login con éxito o un TTL lapsado,
**When** ocurre,
**Then** `lockedUntil` / `failedAttempts` se **limpian** (FR7).

**Given (invariante SI-12 · neutralidad)** el `login_throttling` saturado,
**When** responde,
**Then** usa el **mismo status/copy** que el fallo pre-identidad — no un «demasiadas solicitudes» distinguible (D10, FR7).

**Given** el muro `locked`,
**When** se renderiza,
**Then** usa `AccessWall` variante `locked` (neutro, nunca rojo) con «Recuperar mi acceso» → B2 **y** «Iniciar sesión» (D-a) (UX-DR1).

### Story II-4 (PR-4): `Invitation` + aceptación (primera superficie pública) + las 6 pantallas de acceso

Como persona invitada,
quiero aceptar mi invitación desde el email y definir mi contraseña en una sola pantalla,
para caer dentro del ERP ya operativa, sin «crear cuenta» y sin que nunca se revele por qué un enlace ya no sirve.

**Comportamiento que introduce:** el agregado `Invitation`, el POST accept fuera del firewall, invite/resend/revoke, el
marker `invalid-token` y **las 6 pantallas/componentes** de acceso.

**Invariantes que consume:** SI-10 / D-c (admisión, II-3 — solo `ACTIVE` recibe sesión); `Shared/Token` (II-2); el CSRF
double-submit `wire-on-consumer` del hermano auth-rbac; la sesión acuñada por II-7.
**Invariantes que establece:**
- **SI-13:** toda muerte de token (usado/revocado/caducado/aceptado/inexistente) colapsa a **un único** muro opaco + marker `invalid-token`; el token nunca se renderiza.
- **NFR3:** el POST accept **regenera el id de sesión** al pasar `INVITED→ACTIVE` (anti-fixation); exige `Origin` + **CSRF**.
- Un `User` **nunca** llega a `ACTIVE` sin consumir un token de invitación válido y sin `Membership` previo (SI-15/D3).

**Acceptance Criteria:**

**Given** `Iam/Invitation`,
**When** se modela,
**Then** es `(organizationId, invitedUserId, tokenHash, expiresAt, status: CREATED→SENT→ACCEPTED|REVOKED|EXPIRED)`; el token crudo **nunca** persiste (FR5, D5).

**Given (invariante SI-10/D-c)** un token de invitación válido,
**When** se acepta,
**Then** en **una transacción**: `User INVITED→ACTIVE`, password fijado, `Invitation ACCEPTED`, **sesión regenerada** y **primera `Session` acuñada** — y **solo** entonces, nunca antes de la admisión (FR5, NFR3).

**Given (invariante CSRF/Origin)** el POST accept **fuera del firewall**,
**When** se procesa,
**Then** exige `Origin` same-origin **y** token CSRF válido; sin ambos se rechaza **sin mutar estado** (FR5, D5) — primer consumidor del double-submit stateless.

**Given (invariante SI-13 · opacidad)** un token no elegible (usado / revocado / caducado / aceptado / inexistente),
**When** se presenta,
**Then** la respuesta es **una sola** — `invalid-token` (RFC 9457) + muro `invalid-link` «Este enlace ya no es válido» — **idéntica** en los 5 casos; un test compara las cinco (SI-13, D11).

**Given (invariante D-a)** el `AccessWall` invalid-link,
**When** se muestra,
**Then** **siempre** ofrece «Iniciar sesión» + «Solicitar nueva invitación», sin revelar el motivo; nunca muestra el email invitado (UX-DR1).

**Given** resend / revoke / TTL,
**When** ocurren,
**Then** resend invalida el token previo (→ muro opaco) y emite uno nuevo; revoke → `REVOKED`; TTL → `EXPIRED` (FR5).

**Given** las 6 pantallas/componentes,
**When** se cablean,
**Then** existen `TokenActionScreen` (accept, campo único revelado, token **nunca** renderizado), `ConnectivityButton`
(anti doble-envío + reintento idempotente), `OfflineNotice` (no pierde datos), `SecuritySignal` (invitation-accepted,
foco al `<h1>`), `SecurityEmail` (invitación) y `AccessWall` (invalid-link) (UX-DR1–6, 9, 10).

**Given (invariante NFR9 · idempotencia)** una pérdida de cobertura tras el submit de accept,
**When** se reintenta,
**Then** no hay efecto duplicado — o el token ya se consumió (→ muro con «Iniciar sesión», D-a) o sigue `SENT` (→ reintento válido) (NFR9, J1).

**Given** el mock `/register`,
**When** se cablea el backend,
**Then** la ruta de alta libre se **retira** (invitation-first) (UX-DR7, Additional).

### Story II-5 (PR-5): Reset de contraseña uniforme (revoca todas las sesiones, limpia el lock)

Como usuario que perdió el acceso,
quiero solicitar y usar un enlace de restablecimiento con una respuesta idéntica exista o no mi cuenta,
para recuperar el acceso sin que la respuesta permita enumerar cuentas y con mis sesiones previas cerradas por seguridad.

**Comportamiento que introduce:** forgot/reset, el `TokenActionScreen` reset, el `SecuritySignal` password-changed, el colapso del copy.

**Invariantes que consume:** `Shared/Token` (II-2), `IdentityStatus` (II-3), la revocación de **todas** las sesiones (II-7).
**Invariantes que establece:**
- **SI-12:** forgot responde **uniforme** exista o no la cuenta (status + shape + trabajo observable).
- **Tras un reset con éxito no queda ninguna `Session` `Active` previa** de ese usuario (revoca **todas**, no «las demás» — quien resetea arranca sin sesión de confianza).
- **D-b:** un reset con éxito **limpia `LockedUntil`**.
- **D-c:** si la identidad es no-`ACTIVE`, el reset **no concede nada** (muro post-identidad).

**Acceptance Criteria:**

**Given (invariante SI-12)** forgot-password,
**When** se envía un email cualquiera,
**Then** la respuesta es **idéntica** (status, shape, trabajo observable) exista o no la cuenta y sea cual sea su estado; un test compara existente vs inexistente (FR6, D10).

**Given (invariante revoca-todas)** un reset con éxito,
**When** se completa,
**Then** **no queda ninguna `Session` `Active` previa** de ese usuario — todas revocadas; a lo sumo sobrevive la sesión que el propio reset acuñe (no «todas menos la actual», porque no había sesión de confianza) (FR6, D9, consume II-7).

**Given (invariante D-b)** una identidad con `LockedUntil>now`,
**When** completa un reset,
**Then** `LockedUntil` queda **limpio** — puede entrar sin esperar al TTL (FR6/FR7, D-b).

**Given (invariante D-c)** una identidad no-`ACTIVE`,
**When** completa un reset,
**Then** aterriza en el muro post-identidad y **no** obtiene sesión (FR6, D-c).

**Given (invariante NFR3 · regeneración)** un reset que acuña sesión (identidad `ACTIVE`),
**When** la acuña,
**Then** **regenera el id de sesión** (2º salto de privilegio, no pasa por `json_login`) (NFR3).

**Given** el token de reset,
**When** se consume,
**Then** es single-use (reusarlo → muro opaco, SI-13) y su muerte es **opaca** (mismo mensaje) (D11).

**Given** la UI,
**When** se restablece,
**Then** `TokenActionScreen` (reset) + `SecuritySignal` password-changed («…hemos cerrado las demás sesiones abiertas.»)
con foco al `<h1>`; el copy «…o ha expirado» se **colapsa** a «Este enlace ya no es válido» (UX-DR2/5/8).

### Story II-8 (PR-8): Endurecimiento de seguridad transversal (*cross-cutting security hardening*)

Como responsable de seguridad,
quiero cerrar las garantías transversales (timing, higiene del token, email, rate-limit) sobre las superficies ya existentes,
para que la indistinguibilidad y la opacidad sean honestas **multicanal**, no solo en el copy.

**Comportamiento que introduce:** el barrido constant-time transversal, los headers/strip/redaction del token, el email async endurecido y el rate-limit neutral. **No es «solo emails».**

**Invariantes que consume:** las superficies II-4 (accept), II-5 (reset) y II-3 (login).
**Invariantes que establece (cierre multicanal):** **SI-12 completo** (indistinguibilidad también en **timing**);
**SI-13 completo** (el token no viaja por `Referer` ni aparece en logs); email async HTTPS-only con contenido escapado y
remitente no-`no-reply`; rate-limit que **no delata**.

**Acceptance Criteria:**

**Given (invariante SI-12 · timing)** los tres casos pre-identidad {inexistente, password errónea, `INVITED`},
**When** se mide la **latencia**,
**Then** son estadísticamente indistinguibles — el hash dummy corre **siempre**, incl. el camino `INVITED` (sin password) y forgot-password; un test/benchmark lo prueba (FR10, D10, NFR1).

**Given (invariante SI-13 · higiene)** una pantalla por token,
**When** se sirve,
**Then** lleva `Referrer-Policy: no-referrer`, el cliente hace **strip** del `?token=` (`history.replaceState`) tras leerlo y el token está **redactado** en los logs de acceso — no viaja por `Referer` a `/api`, `/monitoring` ni al historial (FR10, D11, NFR2).

**Given (invariante de email)** un email de seguridad,
**When** se envía,
**Then** es **async** (Messenger, handler idempotente), HTTPS-only, con contenido dinámico **escapado**, remitente **no-`no-reply`**, y **nunca** contiene secretos/PII más allá del enlace de un solo uso (FR10, NFR2, NFR10).

**Given (invariante de rate-limit neutral)** login/forgot/reset/accept saturados,
**When** responden bajo rate-limit,
**Then** usan el **mismo status/copy** que su fallo pre-identidad — la saturación no es distinguible (FR10, D10, NFR1).

**Given** `SecurityEmail`,
**When** se renderiza,
**Then** cumple el contrato de plantilla (bancario, un enlace bulletproof, dark-mode-aware, pila de sistema) (UX-DR6).

## Riesgos / notas de ejecución

Extraídos de los *load-bearing implementation challenges* del ADR + la secuenciación; cada uno queda anclado en un AC.

- **R1 · Cobertura del gate (SI-11).** Una ruta que autentica pero **bypassa** el gate reabre sesiones revocadas.
  *Mitigación:* AC de cobertura en II-7 (checklist/test de que toda ruta autenticada lo atraviesa; fail-closed probado).
- **R2 · Constant-time across states.** El camino dummy-hash debe cubrir `INVITED` (sin password) y forgot, o el timing
  re-enumera lo que el copy oculta. *Mitigación:* II-8 (benchmark de las 3 respuestas pre-identidad); II-3 ya iguala status+shape.
- **R3 · Regeneración de sesión en los dos saltos.** accept (II-4) y reset (II-5) acuñan sesión **fuera** de `json_login`
  y deben regenerar el id. *Mitigación:* invariante NFR3 como AC en ambas.
- **R4 · Promotion churn (coordinación RBAC ya resuelta).** II-0 mueve Identity **+ core RBAC** y toca
  `security.yaml`/deptrac. **RM-1 ya está mergeada a `main` (#456)** — el coste de coordinar/rebasar un PR abierto
  **desapareció**; II-0 solo reubica código ya en `main`. *Mitigación:* AC de no-regresión (`app.test`/`app.quality`
  idénticas pre/post). Opción (b) adoptada como **parking explícito** (no topología definitiva); **follow-up** de
  extracción del plano de autorización registrado en Additional (propiedad del ADR RBAC).
- **R5 · Rol por defecto del bootstrap (CERRADA → `ADMIN`).** El 1er admin recibe el tier **`ADMIN`**: en un sistema
  invitation-first el primer usuario necesita **invitar, administrar miembros y gestionar la organización**, exactamente
  lo que `ADMIN` concede. **No** se introduce `OWNER`/`SUPER_ADMIN`/`ROOT` hasta una necesidad real (propiedad, licencias,
  transferencia) — sería una evolución explícita del modelo. El riesgo de **lockout del último admin** (degradación mutua)
  **no** se cubre con un tier nuevo sino con el **invariante de dominio ≥1 `ADMIN` activo** (AC en II-1 + II-3
  suspend/deactivate; enforcement de demote/remove en el slice de miembros). Anclado en el AC de bootstrap de II-1.
- **R6 · Proveniencia del run UX (RESUELTA).** El run `ux-ERPify-2026-07-06` produjo decisiones arquitectónicas, así que
  **viaja con el PR** de esta épica (gobernanza: el contexto de las decisiones entra en la revisión, aunque el run viva
  fuera de `docs/`). Se incluye en el árbol del PR #455 al commitear.
