# ADR — Auth/RBAC subsystem: session firewall, pure-domain identity, trail access gate

> **Status:** accepted · design only — **implementation is a separate "auth foundation" epic that unblocks Epic 3 of the regulatory audit trail (trail read routes + #377 production gate); no code ships in this ADR's PR** · **Date:** 2026-07-02 · **Scope:** new `Backoffice/Identity` bounded context (User aggregate + provider), a global Symfony Security firewall (`api/config/packages/security.yaml`), and authorization over the `Backoffice/Audit` read routes. Plugs into the actor seam frozen by [`regulatory-audit-trail.md`](./regulatory-audit-trail.md) (D8/D9) **without revoking it**.
>
> Temporal context: the application is **not in production** and has **no auth infrastructure today** (no `security.yaml`, no `SecurityBundle`, no `User`, no voter, no roles) — the subsystem is greenfield, born without backward-compatibility constraints.

## Context

El trail regulatorio ([`regulatory-audit-trail.md`](./regulatory-audit-trail.md)) quedó *mecánicamente* completo pero *ISO-incompleto*: sin autenticación todo actor se sella como `anonymous`/`system` y las rutas de lectura del trail (incluida la #377) son públicas — un hallazgo ISO en sí (D8 del hermano). Epic 3 (FR13–FR15) exige acceso RBAC-restringido, auto-auditado y atribución real, todo *gated* sobre un subsistema auth/RBAC **inexistente hoy**. Este ADR fija ese subsistema —firewall, modelo de identidad, autorización— diseñado para **enchufar en la costura de actor sin tocar esquema, bus, storage ni read model** (NFR2 / D9 del hermano). No modela facturación, permisos de negocio ni identidad de cliente: sólo la identidad y el control de acceso que E3 necesita.

## Decisions

### D1 — Symfony Security with a stateful httpOnly-session firewall, not JWT

Se instala `symfony/security-bundle` (hoy sólo está `symfony/security-core` como librería, por el tipo `AccessDeniedException`) y se define un firewall **con sesión** (`json_login` para el login de la PWA, cookie de sesión httpOnly). La PWA Next.js y la API comparten origen (FrankenPHP), así que la sesión es el mecanismo natural y encaja con la regla de `pwa/CLAUDE.md` de *no* guardar tokens en `localStorage`. La sesión introduce superficie **CSRF**, mitigada con **`SameSite=Lax`** + verificación de **`Origin`** en métodos no-seguros + un **token CSRF *stateless* double-submit** (Symfony `csrf_protection: stateless`: la PWA lee la cookie del token y la re-emite en cabecera, sin estado de sesión). *Descartado:* el Synchronizer Token clásico (stateful, orientado a formularios Twig → fricción para un API JSON+SPA). Se concreta con el firewall en la Story AF-1.2. El **JWT de Mercure** (`CADDY_MERCURE_JWT_SECRET`, cookie httpOnly emitida por el hub) es **ortogonal** y no se toca.

Discarded: JWT stateless (lexik) — reintroduce el almacenamiento de token en cliente que el front prohíbe y resuelve problemas ausentes (gateways, SPAs cross-domain, consumidores externos) — YAGNI same-origin. Se **conserva** el `ActorType::API_KEY` ya modelado para una futura vía M2M, sin construirla ahora.

### D2 — Domain `User` is framework-free; a `SecurityUser` adapter implements Symfony's contract

Nuevo contexto `Backoffice/Identity` dueño del agregado `User` (`Domain/`): id **UUID v7** (satisface `ActorContext::forUser(uuid)` sin cambiar el VO), identificador (email) y un value object `HashedPassword` (string ya hasheado). El `User` de dominio **no** implementa `Symfony\...\UserInterface`; un adapter `Infrastructure/Security/SecurityUser` sí lo implementa y envuelve al `User`, junto con el `UserProvider`, el authenticator y `security.yaml`. El **hashing vive en Infrastructure** (`PasswordHasherInterface` de Symfony, en el authenticator/registro); el dominio nunca conoce bcrypt/argon2id ni el hasher. Es DIP obligado, no opcional: la regla de dependencias la impone `deptrac` (`api/tools/deptrac/deptrac.yaml`) — importar `UserInterface` en `Domain/` rompería el gate.

Discarded: `User implements UserInterface` directamente — más simple, pero mete framework en `Domain/`, falla deptrac y exigiría baseline-arlo (deuda permanente). Discarded: ubicar `User` en un contexto `Identity`/`IAM` top-level **hoy** — Regla de Tres: el único consumidor es el acceso de backoffice al trail; se promociona a subdominio transversal cuando exista un segundo consumidor real (Frontoffice/cliente/OAuth) **o** emerjan capacidades propias de IAM (MFA, password reset, login attempts, sessions, API keys, OAuth, SSO, impersonation), no antes; este ADR **no** obliga a mantener `Identity` bajo `Backoffice` para siempre. Discarded: `User` en `Shared/` — es un agregado de negocio, no kernel.

### D3 — Roles are a static domain enum, mapped to `ROLE_*` in the adapter

Un enum de dominio `Role` (p. ej. `AUDIT_READER`, `ADMIN`); el `SecurityUser` adapter emite `->value` como `ROLE_*` en `getRoles()`. E3 sólo pide «roles autorizados leen el trail» — un voter, un rol. **Dirección del prefijo:** los `->value` viven **sin** `ROLE_` (`AUDIT_READER`, no `ROLE_AUDIT_READER`) — el dominio es la **fuente de verdad** del vocabulario de roles y el prefijo se añade **sólo** en el borde de Infra (adapter), **nunca al revés**: nada mapea un `ROLE_*` de Symfony de vuelta al enum. **Roles = autorización externa (SI-5 del addendum):** el `User` guarda los roles como *dato* que el adapter emite a Symfony, pero **ninguna lógica de `Application`/`Domain` ramifica por rol** para decidir comportamiento (Security concede/deniega el acceso *antes* de entrar); sin helpers `isAdmin()` en el dominio.

Discarded: una tabla Role/Permission dinámica (RBAC de grano fino) — resuelve problemas inexistentes (editor de permisos, tenancy, jerarquías); se promociona cuando la visibilidad dependa de ≥3 ejes de decisión (propiedad, organización, clasificación).

### D4 — Route authorization via `#[IsGranted]` and the built-in `RoleVoter`, over the existing 403 pipeline

Las dos rutas de lectura del trail —`GET /api/v1/backoffice/audit/timeline` y `GET /api/v1/backoffice/audit/events/{id}`— se protegen con `#[IsGranted('ROLE_AUDIT_READER')]`. El `RoleVoter` interno de Symfony **es** el «voter» que pide FR13; una clase Voter a medida sería `return in_array(...)` — YAGNI mientras no haya autorización por recurso/fila. La denegación lanza `AccessDeniedException`, que el pipeline RFC 9457 **ya** mapea a `403 { "type": "forbidden" }` (marker `Forbidden` + puente en `ProblemDetailsFactory`), y que `AccessDeniedAuditListener` **ya** auto-audita. No se añade marker ni se toca [`../api-error-contract.md`](../api-error-contract.md).

Discarded: clase `Voter` propia ahora — duplica el `RoleVoter` sin lógica contextual. Discarded: `JsonResponse` de error manual en el controlador — prohibido por el pipeline (`php.lint.error-contract`).

### D5 — `actor_id` stays nullable; "real attribution" is a seam invariant, not a schema constraint

FR15 y el paréntesis «(`actor_id` NOT NULL)» de D9 (tier 3) son taquigrafía; el mecanismo autoritativo es D9 **tier 1**: «`actor_id` stays nullable and only `ActorContextFactory` swaps». Se ratifica: la columna **permanece nullable**. «Atribución real» significa que toda request **autenticada** produce un UUID de actor real; las escrituras `system`/fuera de request (CLI, worker, scheduler) siguen `NULL` por diseño —no hay humano— y existen con o sin auth. El invariante vive en la costura, no en el DDL. Esto reconcilia FR15 (reformulado en el epic) con NFR2: no hay migración de esquema.

Discarded: `NOT NULL` físico con UUIDs centinela para `system`/`anonymous` — exige migración (rompe NFR2), toca el VO `ActorContext` (rompe «sólo cambia `ActorContextFactory`») y filtra UUIDs mágicos al read model y al diff. Discarded: un `CHECK (actor_type IN ('system','anonymous') OR actor_id IS NOT NULL)` — traslada a persistencia un invariante que el VO **ya** garantiza en construcción (constructor privado + `withValidatedId`), un segundo lugar donde mantener la misma regla para atrapar un bug prácticamente imposible.

### D6 — `ActorContextFactory` is the single authorized identity-attribution seam

Con auth, `ActorContextFactory` deja de «distinguir HTTP de CLI» y pasa a **traducir el contexto de autenticación al modelo de auditoría**: es un componente de *seguridad*, no sólo de auditoría. La nueva impl (Infrastructure) inyecta `Security`, resuelve el token → UUID del `User` → `ActorContext::forUser($uuid)`, con *fallback* a `anonymous()` (request sin token: rutas públicas legítimas) o `system()` (fuera de request). Regla explícita: **toda atribución de actor entra exclusivamente por `ActorContextFactory`**; ningún `AuditWriter` ni servicio de `Application` obtiene el usuario autenticado directamente de Symfony Security. Esto preserva el «cero retrabajo» de D9: el puerto, la firma y las dependencias aguas abajo no cambian.

Discarded: leer `Security`/token dentro del writer o de un handler de aplicación — acopla persistencia/orquestación a la autenticación y erosiona el aislamiento que hace sustituible la auth.

### D7 — FR14 (authorized-read self-audit) is a separate durable listener, not `#[IsGranted]`

`#[IsGranted]` (D4) cubre la **denegación** (403 + auto-auditoría gratis). La auto-auditoría de la lectura **concedida** (FR14 / Story 3.2) es un mecanismo distinto: un listener sobre la respuesta exitosa de las rutas de audit que emite una fila `security` por la vía **durable write-before-send** del eje (no best-effort), reutilizando la costura del `AccessLogAuditListener` existente. Que no se dé FR14 por cerrada con la protección de rutas.

## Load-bearing implementation challenges

- **Swap de `ActorContextFactory` sin regresión**: la nueva impl reemplaza el `#[AsAlias]` actual; los tests deben cubrir los tres caminos (autenticado → `forUser`, público → `anonymous`, off-request → `system`).
- **Gate de configuración, no de modelo** (matiz de `anonymous`): el riesgo real no es «request autenticada → anonymous» (lo bloquea la factory) sino «endpoint que debería exigir auth queda público». Mitigación: `access_control` **default-deny** + allowlist explícita de rutas públicas + test de integración por endpoint del trail.
- **CSRF en mutaciones** (D1): definir la protección al añadir el firewall, no después.
- **UUID del principal**: el identificador del `User` que alimenta `forUser` debe ser UUID (lo exige `withValidatedId`); el modelo de identidad nace con id UUID v7.

## Decided inputs (previously open)

- **Mecanismo: sesión httpOnly** (no JWT) — same-origin.
- **CSRF: `SameSite=Lax` + `Origin` check + token *stateless* double-submit** (D1) — se implementa con el firewall en AF-1.2.
- **Ubicación de `User`: `Backoffice/Identity`** — promocionable a top-level con un segundo consumidor o capacidades IAM (D2).
- **Lifecycle del `User`:** sin auto-registro público (identidad backoffice-only); alta por admin autenticado (story posterior); **bootstrap del 1er usuario = comando `identity:user:create`** en AF-1.2 (hashea en Infra); nunca sembrar credenciales en migraciones; dev/test = fixture Alice.
- **`actor_id`: permanece nullable** — «atribución real» es invariante de costura.

## Implementation

Épica «auth foundation» aparte, cortada por el PM con `bmad-create-epics-and-stories`, **no** en la PR de este ADR. Orden por dependencia: **(1)** fundación —`SecurityBundle`, `Backoffice/Identity` (User + `HashedPassword` + repositorio + migración), `security.yaml`/firewall/CSRF, `UserProvider` + authenticator + `SecurityUser`, enum `Role`—; **(2)** Story 3.1 —`#[IsGranted]` en las 2 rutas + swap de `ActorContextFactory` a `forUser`—; **(3)** Story 3.2 —listener de auto-auditoría de lectura concedida—; **(4)** Story 3.3 —levantar el gate de prod + cerrar `PRODUCTION_SECURITY_CHECKLIST.md` y [`../rules/security.md`](../rules/security.md)—. El gate de producción de la #377 y del trail se retira sólo al completar (2)+(3) (D8 del hermano).
