# Epic 3 Context: Acceso restringido + auto-auditado + atribución real

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

Epic 3 deja la superficie de lectura del trail de auditoría **apta para producción**: control de acceso RBAC sobre las rutas de lectura de `Backoffice/Audit` (incluida la UI de investigación #377), auto-auditoría del propio acceso (el auditor se audita) y **atribución real** de actor por request autenticada. El trail regulatorio ya es *mecánicamente* completo pre-auth, pero solo es *ISO-completo* cuando el «quién» deja de ser `anonymous`/`system` y toda lectura queda registrada y restringida (A.5.18 / A.8.15). Es la única de las tres épicas del trail regulatorio **gated** sobre el subsistema auth/RBAC: se construye aguas abajo de la fundación de autenticación (contexto `Backoffice/Identity`, firewall de sesión, enum `Role`), que es su prerequisito. Enchufa en la costura de actor ya congelada **sin tocar esquema, bus, storage ni read model** del trail.

## Stories

- Story 3.1: Voter RBAC sobre las rutas de lectura + atribución real de actor
- Story 3.2: Auto-auditoría del acceso (auditar-al-auditor) — **próxima a implementar**
- Story 3.3: Levantar el gate de producción

## Requirements & Constraints

- **Lectura restringida por rol (FR13):** solo roles autorizados leen el trail; una request sin el rol requerido se deniega con **403 RFC 9457** (`forbidden`), nunca con `JsonResponse` manual.
- **Auto-auditoría de la lectura concedida (FR14):** cada lectura *autorizada* del trail emite una fila `security` (quién leyó qué). Es un mecanismo **distinto** de la denegación 403 (que ya se auto-audita gratis): un listener sobre la respuesta OK, persistido por la vía **durable write-before-send** del eje hermano (no best-effort).
- **Atribución real (FR15):** con auth, toda request autenticada produce el UUID real del actor; los eventos `system`/off-request (CLI, worker, scheduler) siguen persistiendo `actor_id = NULL` por diseño. La columna **permanece nullable** — «atribución real» es invariante de *costura*, no constraint de esquema → **no hay migración del trail**.
- **Gate de producción (FR15/D8):** la restricción «no llega a producción hasta que exista auth» del trail y de la #377 se retira solo cuando voter + auto-auditoría estén en vigor.
- **Mapeo ISO documentado (FR17):** cerrar `PRODUCTION_SECURITY_CHECKLIST.md` y `docs/rules/security.md` con acceso restringido + auto-auditado (A.5.18 / A.8.15).
- **Cero retrabajo (NFR2/NFR8):** al entrar auth **solo** cambia `ActorContextFactory`; esquema, bus, storage y read model no se tocan. Pre-auth toda lectura/escritura seguía siendo `anonymous`/`system`.

## Technical Decisions

- **Costura única de identidad (SI-1 / D6):** toda atribución de actor entra **exclusivamente** por `ActorContextFactory`. La impl de auth inyecta `Security`, resuelve token → UUID del `User` → `ActorContext::forUser($uuid)`, con *fallback* a `anonymous()` (request sin token, rutas públicas legítimas) o `system()` (off-request). Ningún writer/handler de `Application` lee el token de seguridad directamente. Los tres caminos deben cubrirse en test (autenticado / público / off-request).
- **Autorización de rutas (D4):** `#[IsGranted('ROLE_AUDIT_READER')]` sobre las **dos** rutas de lectura del trail — `GET /api/v1/backoffice/audit/timeline` y `GET /api/v1/backoffice/audit/events/{id}`. Se usa el `RoleVoter` interno de Symfony (es «el voter» que pide FR13); **no** una clase Voter a medida (YAGNI mientras no haya autorización por recurso/fila). La `AccessDeniedException` ya la mapea el pipeline RFC 9457 a `403 forbidden` y `AccessDeniedAuditListener` ya la auto-audita — no se añade marker ni se toca el error-contract.
- **Roles = autorización externa (SI-5 / D3):** enum de dominio `Role` (p. ej. `AUDIT_READER`); el adapter `SecurityUser` emite `->value` como `ROLE_*` en `getRoles()` (prefijo solo en el borde de Infra, nunca de vuelta al dominio). **Ninguna lógica de `Application`/`Domain` ramifica por rol** — Security concede/deniega *antes* de entrar; sin helpers `isAdmin()` en dominio.
- **Framework confinado (SI-2 / D2):** Symfony Security vive solo en `Infrastructure/`; `Backoffice/Identity/Domain` (`User` + VO `HashedPassword`) es libre de framework, lo impone `deptrac`. El hashing vive en Infra (`PasswordHasherInterface`).
- **Self-audit ≠ IsGranted (D7):** la Story 3.2 es un listener sobre la respuesta exitosa de las rutas de audit que reutiliza la costura del `AccessLogAuditListener` existente y emite la fila `security` por write-before-send. La protección de rutas (D4) **no** cierra FR14.
- **Errores por el contrato (SI-4):** auth/authz fallidas fluyen por RFC 9457 (`401 unauthorized` / `403 forbidden`), jamás por `JsonResponse` manual (`php.lint.error-contract`).
- **Gate de producción (SI-3 / D8):** trail regulatorio + #377 no llegan a producción hasta que voter (D4) y auto-auditoría (D7) estén en vigor.

## Cross-Story Dependencies

- **DAG lineal, gated en la fundación auth:** `auth-foundation (AF-1.x: SecurityBundle · Backoffice/Identity · firewall+CSRF · SecurityUser · enum Role)` → **Story 3.1** (`#[IsGranted]` en las 2 rutas + swap de `ActorContextFactory` a `forUser`) → **Story 3.2** (listener de auto-auditoría, durable) → **Story 3.3** (levantar gate de prod + cerrar checklist ISO). Cada story depende de la anterior; sin dependencias hacia adelante.
- **auth-foundation es el prerequisito duro** (contexto `Backoffice/Identity` + firewall de sesión, inexistente al planificar E3). Sin ella las Stories 3.1–3.3 no son *dev-ables*.
- **Story 3.2 (próxima)** reutiliza directamente la costura durable write-before-send del eje operativo hermano (`audit-activity-log`), la misma vía por la que ya se auto-auditan la denegación 403 y las lecturas genéricas.
- **Frontera con E1/E2:** E3 no reabre la captura de escrituras (E1) ni el crypto-shredding PII (E2); solo las vuelve forense-significativas al dar atribución real. El *bootstrap* del primer usuario es el comando `identity:user:create` (de la fundación), nunca semilla de credenciales en migraciones.
