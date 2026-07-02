---
stepsCompleted: ['step-01-validate-prerequisites', 'step-02-design-epics']
inputDocuments:
  - docs/adr/auth-rbac-subsystem.md
  - _bmad-output/planning-artifacts/arch-addendum-auth-rbac.md
  - _bmad-output/planning-artifacts/epics-regulatory-audit-trail.md
  - docs/adr/regulatory-audit-trail.md
  - docs/rules/security.md
  - docs/api-error-contract.md
  - PRODUCTION_SECURITY_CHECKLIST.md
  - api/src/Shared/Audit/Domain/ActorContext.php
  - api/src/Shared/Audit/Infrastructure/RequestStackActorContextFactory.php
  - api/src/Backoffice/Audit/Infrastructure/Controller/AuditTimelineSearchController.php
  - api/src/Shared/ErrorContract/Domain/Exception/Forbidden.php
scope: >-
  Fundación auth/RBAC (PR-0 del DAG del addendum), el subsistema greenfield que HOY NO EXISTE (sin
  security.yaml, sin SecurityBundle, sin User, sin voter). Prerequisito de Epic 3 (Stories 3.1–3.3) del
  trail regulatorio (epics-regulatory-audit-trail.md), que asume auth en vigor. Alcance = decisiones ADR
  D1/D2/D3: firewall de sesión + modelo de identidad + roles + baseline de control de acceso. NO incluye el
  swap de `ActorContextFactory` ni el `#[IsGranted]` sobre las rutas de audit (eso es E3 Story 3.1 — D4/D5/D6).
---

# ERPify — Fundación auth/RBAC — Desglose de épica

## Overview

Desglosa la épica **«auth foundation» (PR-0)** definida en el DAG de
[`arch-addendum-auth-rbac.md`](./arch-addendum-auth-rbac.md), cuyas decisiones fija
[`docs/adr/auth-rbac-subsystem.md`](../../docs/adr/auth-rbac-subsystem.md) (D1–D7).

El hueco que cubre: ERPify **no tiene ninguna infraestructura de autenticación** — todo `/api/*` es público,
y el actor de auditoría se sella siempre como `anonymous`/`system`. Esta épica construye el subsistema de
identidad y control de acceso desde cero para que **Epic 3** del trail regulatorio (voter + auto-auditoría +
atribución real, Stories 3.1–3.3) sea *dev-able* y se pueda levantar el gate de producción de la ruta #377.

**Frontera explícita (SI-1, NFR2 del hermano):** esta épica **NO toca `ActorContextFactory`**. La costura de
actor permanece intacta (`anonymous`/`system`) hasta que E3 Story 3.1 la reemplace por `forUser`. Aquí se
construye *quién puede autenticarse y qué rol lleva*; E3 conecta esa identidad al sellado del trail.

## Requirements Inventory

### Functional Requirements

FR1: **Firewall de sesión httpOnly** — instalar `symfony/security-bundle` y definir un firewall con
`json_login` + sesión (cookie httpOnly); login por credenciales establece sesión; **sin JWT en cliente**
(D1).
FR2: **Protección CSRF** para rutas mutantes bajo sesión — cookies `SameSite=Lax/Strict` + same-origin y,
para no-GET, token CSRF o comprobación de cabecera (D1).
FR3: **Agregado `User` en `Backoffice/Identity`** — id **UUID v7**, email (identificador), `HashedPassword`
value object y roles de dominio; el agregado de dominio es **libre de framework** (D2).
FR4: **Adapter de seguridad** — `Infrastructure/Security/SecurityUser` implementa la `UserInterface` de
Symfony y envuelve al `User`; `UserProvider` carga por repositorio; authenticator + **hashing en
Infrastructure** (`PasswordHasherInterface`) (D2).
FR5: **Modelo de roles** — enum de dominio `Role`; el adapter emite `->value` como `ROLE_*` en
`getRoles()` (D3). Los `->value` viven **sin** prefijo `ROLE_` (dominio = fuente de verdad; el prefijo sólo
en el borde de Infra, unidireccional) y los roles son **autorización externa** — ninguna lógica de
`Application`/`Domain` ramifica por rol (SI-5).
FR6: **Baseline de control de acceso** — `access_control` **default-deny** + allowlist explícita de las
rutas hoy públicas; una request no autenticada a una ruta protegida → **401 RFC 9457** por el pipeline
(marker `Unauthenticated`), nunca `JsonResponse` manual (D1, SI-3/SI-4).

### NonFunctional Requirements

NFR1: **Aislamiento de capas/contextos** — Symfony Security confinado a `Infrastructure/`;
`Backoffice/Identity/Domain` no alcanza framework; `php.deptrac` + `php.lint.bounded-context` verdes
(D2, SI-2).
NFR2: **Errores por el contrato** — auth/authz fallidas fluyen por el pipeline RFC 9457 (401/403), jamás
por `JsonResponse` manual; `php.lint.error-contract` verde (SI-4).
NFR3: **Sin ensanchar la superficie** — no se amplían CORS/CSRF ni la política de Mercure; el JWT-cookie de
Mercure queda intacto y ortogonal (D1, `PRODUCTION_SECURITY_CHECKLIST.md`).
NFR4: **Migración segura** — reversible (`down()`), sin sembrar PII/secretos; el hard-delete de `User`
mantiene satisfacible el borrado GDPR (`docs/rules/database.md`).
NFR5: **Cero retrabajo del eje de auditoría** — esta épica **no modifica `ActorContextFactory`** ni el
esquema/bus/storage del trail; la costura de actor permanece como está hasta E3 (NFR2 del hermano, SI-1).

## Requirements Coverage Map

FR3, FR5: Story AF-1.1 — agregado `User` + roles + persistencia.
FR1, FR2, FR4: Story AF-1.2 — firewall de sesión + adapter/provider/authenticator + CSRF.
FR6: Story AF-1.3 — baseline de control de acceso (default-deny + 401 por el pipeline).
NFR1–NFR5: transversales, verificadas en cada story (gates + AC de frontera).

## Epic List

### Epic auth-foundation: Fundación auth/RBAC (prerequisite de E3)

Subsistema de identidad + control de acceso desde cero (D1/D2/D3): firewall de sesión, agregado `User` en
`Backoffice/Identity`, roles y baseline default-deny. **Desbloquea** Epic 3 (Stories 3.1–3.3) del trail
regulatorio. **No** toca la costura de actor. **FRs:** FR1–FR6. **NFRs:** NFR1–NFR5.

---

## Epic auth-foundation: Fundación auth/RBAC

Construye el subsistema auth/RBAC que hoy no existe, dejando a ERPify con autenticación por sesión, un
modelo de identidad de dominio y un baseline de control de acceso — sin tocar el eje de auditoría.

### Story AF-1.1: Agregado `User` en `Backoffice/Identity` + persistencia

Como plataforma de ERPify,
quiero un agregado `User` de dominio con identidad, credencial y roles,
para tener un modelo de identidad propio y libre de framework sobre el que montar la autenticación.

**Acceptance Criteria:**

**Given** el nuevo contexto `Backoffice/Identity`,
**When** se modela el agregado,
**Then** `Domain/User` lleva id **UUID v7**, email como identificador, un `HashedPassword` value object y
roles como enum de dominio `Role`; **no importa** ninguna clase de framework (FR3, FR5).

**Given** el agregado `User`,
**When** se persiste,
**Then** hay un puerto de repositorio en `Domain`/`Application` y un adapter Doctrine en `Infrastructure`,
con una migración generada por `make db.diff`, reversible y sin sembrar PII/secretos (FR3, NFR4).

**Given** el aislamiento de capas,
**When** se corre `php.deptrac` + `php.lint.bounded-context`,
**Then** pasan: `Backoffice/Identity/Domain` no alcanza framework y no importa el `Domain` de otro contexto
de negocio (NFR1).

**Given** el `HashedPassword`,
**When** se construye,
**Then** representa un hash ya calculado (el dominio **no** conoce bcrypt/argon2id ni el hasher); el hashing
ocurre en Infrastructure en la Story AF-1.2 (D2, NFR5).

### Story AF-1.2: Firewall de sesión + `SecurityUser`/provider/authenticator + CSRF

Como responsable de seguridad,
quiero autenticación por sesión httpOnly con Symfony Security,
para que un usuario se autentique same-origin desde la PWA sin exponer tokens en el cliente.

**Acceptance Criteria:**

**Given** `symfony/security-bundle` instalado y `api/config/packages/security.yaml`,
**When** se define el firewall,
**Then** usa `json_login` + sesión (cookie httpOnly); un login con credenciales válidas establece sesión y
uno inválido devuelve **401 RFC 9457** por el pipeline, sin `JsonResponse` manual (FR1, NFR2).

**Given** el agregado `User` de la Story AF-1.1,
**When** Symfony resuelve la identidad,
**Then** un `Infrastructure/Security/SecurityUser` implementa `UserInterface` y envuelve al `User`; un
`UserProvider` lo carga por repositorio; el hashing/verificación de password usa `PasswordHasherInterface`
en Infrastructure (FR4, D2).

**Given** los roles del `User`,
**When** el adapter expone `getRoles()`,
**Then** emite los `->value` del enum `Role` como `ROLE_*` (FR5).

**Given** rutas mutantes bajo sesión,
**When** se diseña la protección CSRF,
**Then** la historia **cierra explícitamente** el mecanismo (SameSite + same-origin, y token/cabecera para
no-GET); no se ensancha CORS/CSRF ni la política de Mercure (FR2, NFR3).

### Story AF-1.3: Baseline de control de acceso — default-deny + 401 por el pipeline

Como responsable de seguridad,
quiero que el firewall deniegue por defecto y que las rutas públicas sean una allowlist explícita,
para que ninguna ruta que deba exigir auth quede expuesta por descuido (el riesgo real no es de modelo,
sino de configuración).

**Acceptance Criteria:**

**Given** el firewall de la Story AF-1.2,
**When** se define `access_control`,
**Then** es **default-deny** con una allowlist explícita de las rutas hoy públicas (p. ej. login, health,
rutas Frontoffice públicas); añadir una ruta protegida no exige tocar el modelo, solo la config (FR6).

**Given** una request **no autenticada** a una ruta protegida,
**When** el firewall la rechaza,
**Then** responde **401 RFC 9457** (`type: unauthorized`) por el `ExceptionResponder`/`ProblemDetailsFactory`
—usando el marker `Unauthenticated` ya existente— sin `JsonResponse` manual; si falta el puente de
`AuthenticationException`→401 se añade con su fila en `docs/api-error-contract.md` (FR6, NFR2).

**Given** las dos rutas de lectura de `Backoffice/Audit`,
**When** se revisa este baseline,
**Then** quedan **listas para** que E3 Story 3.1 les añada `#[IsGranted('ROLE_AUDIT_READER')]`; esta épica
**no** añade el voter de rol ni toca `ActorContextFactory` (frontera con E3, SI-1).

## Riesgos / decisiones abiertas

- **Puente 401 en el pipeline:** existe el marker `Unauthenticated` (401), pero hay que confirmar/añadir el
  puente de la `AuthenticationException` de Symfony → 401 (análogo al de `AccessDeniedException` → 403). Si
  se añade, actualizar `docs/api-error-contract.md` en el mismo PR (NFR26). **A cerrar en Story AF-1.3.**
- **CSRF (CONGELADO):** `SameSite=Lax` + verificación de `Origin` en no-seguros + **token CSRF *stateless*
  double-submit** (Symfony `csrf_protection: stateless`); descartado el Synchronizer Token (stateful,
  form-oriented). Se **implementa** con el firewall en Story AF-1.2 (ADR D1).
- **Nombre/ubicación del contexto:** `Backoffice/Identity` por Regla de Tres (único consumidor hoy);
  promocionable a `Identity`/`IAM` top-level con un segundo consumidor real **o capacidades propias de IAM**
  (MFA, reset, sessions, API keys, OAuth, SSO, impersonation) (ADR D2) — el ADR no lo ata a `Backoffice` para
  siempre. No antes.
- **Lifecycle / bootstrap (CONGELADO):** sin auto-registro público (identidad backoffice-only); alta de
  usuarios = admin autenticado vía story posterior; **bootstrap del 1er usuario = comando
  `identity:user:create`** en Story AF-1.2 (hashea en Infra); **nunca** sembrar credenciales en migraciones
  (NFR4); dev/test = fixture Alice con hash precomputado (ADR «Decided inputs»).
- **Relación con E3:** al completar esta épica, `sprint-status.yaml` deja `epic-3` listo para arrancar
  (Story 3.1 = `#[IsGranted]` + swap de `ActorContextFactory`). DAG: **auth-foundation → 3.1 → 3.2 → 3.3**.
