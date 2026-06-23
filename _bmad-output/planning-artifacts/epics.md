---
stepsCompleted: ['step-01-validate-prerequisites', 'step-02-design-epics', 'step-03-create-stories']
inputDocuments:
  - docs/adr/audit-activity-log.md
  - docs/architecture/event-catalog.md
  - docs/architecture-api.md
  - docs/api-error-contract.md
  - docs/adr/domain-event-handler-idempotency.md
  - docs/rules/database.md
  - docs/rules/security.md
  - PRODUCTION_SECURITY_CHECKLIST.md
  - api/src/Backoffice/BankAccount/Application/Audit/BankAccountsViewedAuditEvent.php
  - api/src/Backoffice/BankAccount/Infrastructure/Audit/RecordAuditLogOnBankAccountsViewed.php
  - api/src/Backoffice/BankAccount/Application/BankAccountSearcher.php
  - api/.event-dispatch-allowlist
scope: 'Subsistema completo de auditoría operativa/de actor en un único PR (feat/shared-audit-actor-context): backbone Shared + captura + retención/GDPR + Backoffice/Audit read model + UI. Persistencia state-oriented append-only (no event sourcing), ya decidida en el ADR (D1/D4).'
---

# ERPify — Auditoría operativa / de actor (`AuditLogger` → `audit_log`) — Desglose de épicas

## Overview

Este documento desglosa en épicas e historias implementables el subsistema de **auditoría
operativa / de actor** definido en [`docs/adr/audit-activity-log.md`](../../docs/adr/audit-activity-log.md)
(diseño congelado, implementación pendiente). El eje es la **observabilidad del actor** (*¿qué hizo la
persona?*), independiente del stream de dominio (`DomainEvent` → `event_store`, que audita *qué le pasó
al agregado*) y de los logs/métricas de sistema.

Estado del baseline (brownfield): **no existe** backbone `Shared/Audit` (ni `AuditLogger`, ni
`RecordAuditEntry`/`AuditLogEntry`, ni `AuditPolicy`, ni `ActorContext`, ni tabla `audit_log`). El "primer consumidor"
`BankAccountsViewed` existe como **placeholder provisional** que hoy solo escribe una línea de log
(`RecordAuditLogOnBankAccountsViewed` → `logger->info('bank_accounts.viewed', …)`) y se despacha
best-effort vía `MessageBusInterface` directo (excepción registrada en `api/.event-dispatch-allowlist`).
Migrarlo al subsistema real es parte del alcance.

Alcance confirmado por el usuario: **subsistema completo en un único PR**, incluido el read model y la
UI de investigación de `Backoffice/Audit`.

**Nota de entrega (PR único):** Epic 1 es grande, Epic 3 pequeña; al ir todo en un PR, los commits
**siguen exactamente la secuencia de historias** (`1.1 → 1.2 → 1.3 → 1.4 → 1.5 → 2.1 → 2.2 → 2.3 →
3.1 → 3.2 → 4.1 → 4.2`) para facilitar revisión y rollback. El seam público es el puerto
`AuditLogger`; detrás, el mensaje interno `RecordAuditEntry` y la fila persistida `AuditLogEntry`
dejan abierta la puerta a múltiples adaptadores de almacenamiento sin tocar el seam, y **sin** exponer
un tipo público `AuditEvent`.

## Requirements Inventory

### Functional Requirements

FR1: Introducir el eje de auditoría en `Shared` detrás del seam `AuditLogger`, **independiente** del `DomainEvent` y **sin un tipo público `AuditEvent`**: el mensaje interno `RecordAuditEntry` no extiende `DomainEvent`, no es evento de integración, no viaja por el `EventBus` transaccional ni entra en `event_store`, y no se publica fuera de `Shared/Audit`; `StoredDomainEvent` NO se renombra (D1).
FR2: Definir el puerto `AuditLogger` (`->log(...)`) que acepta la acción + contexto y la persiste según el nivel (`activity` async, `security` write-before-send síncrona); es el **único** seam de escritura público que toda Application usa (D2/D3/D5).
FR3: Crear el almacén `audit_log` **append-only** con el esquema del ADR (`id`, `level`, `action`, `actor_type`, `actor_id`, `correlation_id`, `resource_type`, `resource_id`, `metadata` jsonb, `ip`, `user_agent`, `occurred_on`) y sus índices `(actor_id, occurred_on)`, `(correlation_id)`, `(level, occurred_on)`, `(resource_type, resource_id)`. `id` (UUIDv7) es la **PK** y el ancla de idempotencia de la inserción (FR4).
FR4: Persistencia de `activity` **asíncrona** vía Messenger en un transporte dedicado `audit` (consumido por `messenger_worker` en dev): el `RecordAuditEntry` lleva su propio `id` (UUIDv7) **generado antes de encolar**, se despacha al transporte `audit` y un `RecordAuditEntryHandler` hace el `INSERT … ON CONFLICT (id) DO NOTHING` en `audit_log`. La PK por `id` hace la inserción **idempotente**: un redelivery del transporte es un no-op (sin duplicados, sin ruido forense, sin deduplicación en consultas, append-only preservado). Modelo **best-effort para `activity`** (acepta perder un registro si el proceso muere antes de encolar). El nivel `security` usa, en cambio, la inserción síncrona write-before-send (durable) descrita en D3 (D3).
FR5: Captura **híbrida**: (a) access-log genérico vía `EventSubscriber` sobre `kernel.terminate`, acotado a `/api/*`; (b) llamadas explícitas `AuditLogger->log(...)` en Application para acciones de fuerte semántica (exportaciones, vistas de datos sensibles, denegaciones) que el hook no conoce (D2).
FR6: `AuditPolicy` genérica que decide **antes de emitir** si la interacción es auditable y a qué nivel; el hook de kernel **solo captura contexto**, la decisión de "qué se guarda" no vive en el hook (D2).
FR7: Captura de nivel `security` para denegaciones de permiso mediante un listener sobre `AccessDeniedException`, enganchado al pipeline RFC 9457 existente (`ExceptionResponder` / `kernel.exception`), no esparcido por los handlers (D2).
FR8: Dos niveles (`level` enum: `activity`, `security`); el tercer eje (cambios de datos) lo cubre `DomainEvent` y **no se duplica** (D4).
FR9: `audit_log` es **append-only**: sin `UPDATE` ni `DELETE` desde la app salvo el proceso de retención (D4).
FR10: Retención **diferenciada por nivel** mediante un `AuditLogPruner` planificado con Symfony Scheduler, reutilizando el patrón `HandledDomainEventPruner` + `…MaintenanceSchedule` sobre el transporte `scheduler_maintenance`: `security` se conserva más, `activity` rota agresivo (D4).
FR11: Borrado GDPR ("olvídame") que **pseudonimiza** `actor_id` (la traza de seguridad sobrevive) en lugar de borrar filas (D4).
FR12: `metadata` (jsonb) sin payload sensible — solo IDs y discriminantes, nunca cuerpos de entidad ni la PII de negocio (p. ej. nunca el IBAN) (D4).
FR13: Ubicación: backbone (`AuditLogger`, `RecordAuditEntry`/`AuditLogEntry`, `ActorContext`, `AuditPolicy` genérica, subscriber de captura, adaptador de storage, transporte `audit`) en `Shared/`; lado de consulta (timeline, filtros, proyecciones, UI admin) en `Backoffice/Audit/`. **Backoffice consume, no escribe** auditoría (D5).
FR14: Toda entrada de auditoría (`AuditLogEntry`) lleva, sin excepción, `correlation_id` (id de request estable, reutilizando `CorrelationIdListener`) y `actor_id` (nullable hasta que exista auth) desde el día 1; esto habilita la "reconstrucción de jornada" sin tabla de sesión (D6). `audit_session` queda **diferido**.
FR15: `ActorContext` como value object de dominio (`Shared/…/Audit/Domain`, sin dependencias de framework) con enum `ActorType` (`anonymous|system|api_key|user`): `actor_type` **obligatorio** (nunca null); `actor_id` nullable según el tipo (`anonymous`/`system` → null; `api_key`/`user` → uuid) (D7).
FR16: Proveedor `ActorContextFactory` que produce el `ActorContext` actual; es **la única pieza que cambia** cuando entre auth real — schema, bus, storage, retención y read model no se tocan (D7 / secuencia frente a auth).
FR17: Fijar el **alcance de captura Fase 1**: `activity` (navegación de backoffice, listados, detalle, búsquedas, filtros, exportaciones — hoy solo `BANK_ACCOUNTS_VIEWED` cableado) y `security` (`AccessDenied`, accesos fuera de alcance, uso de API keys, elevación de permisos, operaciones admin sensibles). **Nunca**: assets, health checks, Mercure, polling, requests técnicos. No existe un `action` `HTTP_REQUEST` (sería el log-explosion que D2 rechaza).
FR18: **Migrar el primer consumidor** `BankAccountsViewed` del placeholder de log-line al subsistema real: registrar la acción vía `AuditLogger->log(...)` (`action` `BANK_ACCOUNTS_VIEWED`, `level` `activity`) persistida en `audit_log`, eliminar la entrada de `api/.event-dispatch-allowlist` y actualizar `docs/architecture/event-catalog.md` (sección *Non-domain signals*).
FR19: Lado de consulta (`Backoffice/Audit`): read model de investigación construido **directamente sobre `audit_log`** (consulta directa, sin tabla de proyección adicional en Fase 1) — timeline por actor / fecha / recurso con filtros, soportado por los índices de FR3. Una proyección materializada (`audit_timeline`) queda como **trigger de revisita** cuando exista un requisito de escala real, no antes (YAGNI).
FR20: UI admin de investigación (PWA) sobre el read model: timeline navegable, filtros (actor / rango de fechas / recurso / nivel / acción) y vista de detalle de una entrada.

### NonFunctional Requirements

NFR1: Latencia de request-path **nula** para el access-log genérico — `kernel.terminate` se ejecuta *tras* enviar la respuesta (D2).
NFR2: Camino de request libre de IO de auditoría para `activity` (persistencia asíncrona). El nivel `security` es la **única excepción consciente**: inserción síncrona write-before-send, rara y fuera del path caliente (D3).
NFR3: `audit_log` **es PII** (`actor_id`, `ip`, `user_agent`) → debe satisfacer GDPR (retención por nivel + pseudonimización) y quedar registrado en `docs/rules/security.md` y `PRODUCTION_SECURITY_CHECKLIST.md` (D4, `rules/database.md`).
NFR4: **Sin compatibilidad hacia atrás**: la app no está en producción; tablas y políticas de retención nacen limpias (cabecera del ADR).
NFR5: Aislamiento de contextos (`make php.lint.bounded-context` + `make php.deptrac`): `Erpify\Shared\…` siempre importable; la captura no entra en el `Domain/` de ningún contexto; `Backoffice/Audit` no alcanza internals de otros contextos (D5).
NFR6: La persistencia append-only usa **raw DBAL + schema listener `postGenerateSchema`** (sin entidad ORM para el log inmutable), patrón establecido `event_store`/`bank_count`/`handled_domain_event` (`rules/database.md`, `architecture-api.md`). El `INSERT … ON CONFLICT (id) DO NOTHING` de FR4 es propio de DBAL crudo; el `id` es el UUIDv7 app-minted que viaja en el `RecordAuditEntry` y sella el `AuditLogEntry`, no una identidad generada por la BD.
NFR7: Tolerancia a entrega **at-least-once** del `messenger_worker` resuelta por diseño: la inserción es **idempotente por PK** (`ON CONFLICT (id) DO NOTHING`, FR4), de modo que un redelivery es un no-op. No se difiere ninguna decisión de deduplicación a las consultas ni se aceptan duplicados (D3, `architecture-api.md`).
NFR8: `actor_type` (quién) y `level` (qué clase de auditoría) son ejes **ortogonales**; no se colapsan (D7).
NFR9: Rendimiento de consulta forense soportado por los índices de FR3; sin `SELECT *`, columnas explícitas, paginación keyset si el volumen lo exige (triggers de revisita del ADR).
NFR10: **Secuencia frente a auth**: el backbone se implementa **antes** de User/RBAC; `actor_id` permanece nullable (`actor_type` ∈ {`anonymous`, `system`, `api_key`}) hasta que exista auth; el día que entre User solo cambia `ActorContextFactory`.
NFR11: **Sin log-explosion**: la política decide antes de persistir; nunca "capturar todo y decidir tarde" (D2).
NFR12: Idempotencia / orden de listeners: el listener de `security` sobre `AccessDeniedException` debe ordenarse correctamente frente a `ExceptionResponder` (que fija la respuesta y detiene la propagación), patrón análogo a `SearchObservabilityListener` en `kernel.exception` (`api-error-contract.md`, `architecture-api.md`).

### Additional Requirements

- Reutilizar `Shared/Http/Infrastructure/CorrelationIdListener` como fuente de `correlation_id` (ya emitido en cada línea PSR-3 y en `X-Correlation-Id`).
- Reutilizar el patrón de mantenimiento del event-backbone para la poda: `HandledDomainEventMaintenanceSchedule` + `PruneHandledDomainEventsHandler` + transporte `scheduler_maintenance` (consumido por `scheduler_worker` dedicado en prod, plegado en `messenger_worker` en dev).
- Reutilizar `Shared/Validation/Infrastructure/EnumType` + `EnumTypeValidator` para los enums Postgres/Doctrine `level` y `actor_type`.
- Reutilizar `Shared/Kernel/Domain/Entity/Identifiable` (uuid v7) para la identidad de la fila.
- Mantener `audit_log` **schema-aware** vía `postGenerateSchema` listener para que `make db.diff` no proponga `DROP TABLE audit_log` (mismo patrón que `EventStoreSchemaListener` / `HandledDomainEventSchemaListener`).
- Cablear en `config/packages/messenger.yaml` un transporte **dedicado** `audit` (no el `async` de los `DomainEvent`) + el routing de `RecordAuditEntry` a ese transporte + su handler; en dev lo consume `messenger_worker`. Registrar además la `Scheduler` de poda.
- Actualizar documentación de arquitectura: `docs/architecture-api.md` (*Async & messaging* / nuevo eje de auditoría), `docs/architecture/event-catalog.md` (*Non-domain signals* — pasa de log-line a `audit_log`), y cualquier nuevo seam publicado en `api/.bounded-context-allowlist` que requiera `Backoffice/Audit`.
- Actualizar `PRODUCTION_SECURITY_CHECKLIST.md`, `docs/rules/security.md` y la nota de excepción de borrado de `docs/rules/database.md` con la política de retención + pseudonimización GDPR de `audit_log`.
- Eliminar la entrada de `BankAccountSearcher.php` en `api/.event-dispatch-allowlist` al completar la migración (FR18).

### UX Design Requirements

> No existe un documento UX dedicado; estos UX-DR se derivan de la descripción del lado de consulta
> del ADR (D5) y del caso de uso "reconstrucción de jornada" (D6). Las historias de UI necesitarán un
> pase de diseño UX antes de implementarse (ver Riesgos/Dependencias en step-02).

UX-DR1: Vista **timeline** de auditoría (admin Backoffice) — lista cronológica de entradas de `audit_log`, ordenada por `occurred_on`.
UX-DR2: **Filtros** del timeline — por actor (`actor_type`/`actor_id`), por rango de fechas, por recurso (`resource_type` + `resource_id`), por `level` (`activity`/`security`) y por `action`.
UX-DR3: **Reconstrucción de jornada** — agrupar/correlar entradas por `actor_id` + `correlation_id` + ventana temporal (el caso de uso central de D6, sin tabla de sesión).
UX-DR4: **Detalle de entrada** — `action`, actor (`actor_type` + `actor_id`), `correlation_id`, recurso, `ip`, `user_agent`, `occurred_on` y `metadata`.
UX-DR5: Presentación **consciente de PII** — los actores pseudonimizados se muestran como tales; nunca se expone payload sensible ni PII de negocio.

### FR Coverage Map

FR1: Epic 1 — seam `AuditLogger` + internos `RecordAuditEntry`/`AuditLogEntry` (eje separado de `DomainEvent`, sin tipo público `AuditEvent`)
FR2: Epic 1 — puerto `AuditLogger`
FR3: Epic 1 — tabla `audit_log` append-only + índices (PK `id` UUIDv7)
FR4: Epic 1 — persistencia `activity` async idempotente (`INSERT … ON CONFLICT (id) DO NOTHING`) + `security` write-before-send
FR5: Epic 2 — captura híbrida, vía genérica `kernel.terminate` (la vía explícita `AuditLogger->log` se estrena en Epic 1 con FR18)
FR6: Epic 2 — `AuditPolicy` decide antes de persistir
FR7: Epic 2 — listener sobre `AccessDeniedException` → nivel `security`
FR8: Epic 1 — niveles `activity`/`security` (el eje de datos no se duplica)
FR9: Epic 1 — append-only (invariante de la tabla); reforzado en Epic 3 (sin `DELETE` salvo retención)
FR10: Epic 3 — `AuditLogPruner` (Scheduler, retención por nivel)
FR11: Epic 3 — pseudonimización GDPR de `actor_id`
FR12: Epic 1 — `metadata` sin payload sensible
FR13: Epic 1 (`Shared` backbone) · Epic 4 (`Backoffice/Audit` consulta)
FR14: Epic 1 — `correlation_id` + `actor_id` desde el día 1
FR15: Epic 1 — `ActorContext` + `ActorType`
FR16: Epic 1 — `ActorContextFactory` (única pieza que cambia con auth)
FR17: Epic 2 — alcance de captura Fase 1
FR18: Epic 1 — migración de `BankAccountsViewed` al subsistema real
FR19: Epic 4 — read model directo sobre `audit_log` (sin proyección en Fase 1)
FR20: Epic 4 — UI admin de investigación (PWA)

## Epic List

### Epic 1: Registro de auditoría end-to-end (backbone + primer actor auditado)
Una acción real (`BANK_ACCOUNTS_VIEWED`) queda registrada de forma durable en `audit_log` con actor + correlación, end-to-end (`AuditLogger` → Messenger → `audit_log`). E1 es una **capacidad verificable**, no infraestructura pura: valida desde el día 1 `actor_type`, `correlation_id`, persistencia async, append-only e idempotencia (`ON CONFLICT (id) DO NOTHING`) sin esperar a E2/E3/E4.
**FRs covered:** FR1, FR2, FR3, FR4, FR8, FR12, FR13 (mitad `Shared`), FR14, FR15, FR16, FR18
**NFRs:** NFR2, NFR4, NFR6, NFR7, NFR8, NFR10

### Epic 2: Captura híbrida — cobertura activity + security
El sistema captura **automáticamente** toda la superficie de Fase 1 (no solo llamadas explícitas): navegación, listados, búsquedas y exportaciones (`activity`) y denegaciones de permiso (`security`).
**FRs covered:** FR5, FR6, FR7, FR17
**NFRs:** NFR1, NFR11, NFR12

### Epic 3: Retención diferenciada y borrado GDPR
La obligación legal/PII queda satisfecha y `audit_log` se mantiene acotado — un límite de riesgo de compliance propio: retención por nivel + pseudonimización GDPR + actualización de la documentación de seguridad.
**FRs covered:** FR9, FR10, FR11
**NFRs:** NFR3

### Epic 4: Investigación de auditoría — read model + UI admin
Una persona puede investigar (reconstruir la jornada de un actor): read model **directo sobre `audit_log`** + UI admin PWA (timeline, filtros, detalle, PII-aware).
**Restricción explícita (Fase 1):** sin tabla `audit_timeline`, sin tabla de proyección, sin `Projector` — `Backoffice/Audit` consulta `audit_log` directamente, optimizado por los índices de FR3. Revisita solo ante escala real (decenas de millones de filas, agregaciones complejas o SLA de búsqueda exigente).
**Dependencia (riesgo):** UX derivada del ADR — los UX-DR sirven para planificar, **no** para construir pantallas finales; requieren validación UX específica antes de implementar.
**FRs covered:** FR13 (mitad `Backoffice`), FR19, FR20
**UX-DRs:** UX-DR1, UX-DR2, UX-DR3, UX-DR4, UX-DR5
**NFRs:** NFR5, NFR9

## Epic 1: Registro de auditoría end-to-end (backbone + primer actor auditado)

Una acción real (`BANK_ACCOUNTS_VIEWED`) queda registrada de forma durable en `audit_log` con actor + correlación, end-to-end (`AuditLogger` → Messenger → `audit_log`). Valida desde el día 1 `actor_type`, `correlation_id`, persistencia async, append-only e idempotencia (`ON CONFLICT (id) DO NOTHING`) sin esperar a E2/E3/E4.

### Story 1.1: `ActorContext` con `ActorType` tipado

Como plataforma de ERPify,
quiero un value object `ActorContext` con `ActorType` tipado,
para que toda entrada de auditoría identifique sin ambigüedad quién actuó y la consulta forense no dependa de heurísticas frágiles.

**Acceptance Criteria:**

**Given** el enum `ActorType` (`anonymous|system|api_key|user`),
**When** se construye un `ActorContext`,
**Then** `actor_type` es obligatorio y nunca null (FR15, NFR8).

**Given** `actor_type` ∈ {`anonymous`,`system`},
**When** se construye con `actor_id` no nulo,
**Then** se rechaza con excepción de dominio;
**And** con `actor_id=null` se acepta.

**Given** `actor_type` ∈ {`api_key`,`user`},
**When** `actor_id` no representa un UUID válido,
**Then** se rechaza con excepción de dominio;
**And** con un valor que sí representa un UUID válido se acepta (el test usa la utilidad estándar del proyecto, `Uuid::ensure()`).

**Given** el VO,
**When** se ubica,
**Then** vive en `Shared/…/Audit/Domain` sin imports de framework/ORM/HTTP (verificado por `php.deptrac` / `php.lint.bounded-context`).

**Given** un test unitario de los cuatro tipos,
**When** se ejecuta,
**Then** pasa sin contenedor ni BD (dominio puro).

### Story 1.2: `AuditLevel` + mensaje interno `RecordAuditEntry` + modelo `AuditLogEntry`, separados del `DomainEvent`

Como desarrollador de un módulo,
quiero el enum `AuditLevel`, un mensaje interno `RecordAuditEntry` y un modelo persistible `AuditLogEntry` (no un tipo público `AuditEvent`),
para describir "qué hizo el actor" sin contaminar el stream de dominio ni el `event_store`, y sin exponer un tipo que invite a tratarlo como evento.

**Acceptance Criteria:**

**Given** la superficie pública del subsistema,
**When** se examina,
**Then** **no existe un tipo público `AuditEvent`**: el único seam que tocan los módulos es `AuditLogger->log(...)` (Story 1.4); `RecordAuditEntry` y `AuditLogEntry` son internos de `Shared/Audit` (FR1, D1).

**Given** `RecordAuditEntry`,
**When** se examina su tipo,
**Then** NO extiende `Shared\Event\Domain\DomainEvent`, no es evento de integración, no expone `aggregateId`, `RegisterDomainEventsPass` no lo descubre ni lo enruta por `EventBus`/`event_store`, y no se publica fuera de `Shared/Audit` (es solo el mensaje de transporte interno hacia el worker de auditoría) (FR1, D1).

**Given** `RecordAuditEntry` / `AuditLogEntry`,
**When** se examina su superficie,
**Then** llevan `id` (UUIDv7), `level` (`AuditLevel` ∈ {activity, security}), `action` (no vacío), `ActorContext`, `correlationId` (UUID), `occurredOn` y contexto opcional de recurso (`resourceType`/`resourceId`), más `metadata`/`ip`/`userAgent` opcionales (FR8, FR14);
**And** el `id` (UUIDv7) se genera al construir el mensaje/entrada, antes de encolar (ancla de idempotencia de FR4).

**Given** dos entradas con el mismo `id`,
**When** se comparan,
**Then** comparten identidad (ancla de idempotencia de FR4).

**Given** `metadata`,
**When** se tipa,
**Then** admite estructuras JSON simples (escalares, arrays y objetos de **discriminantes** — p. ej. `{"filters":{"status":"active"}}`, `{"export_format":"xlsx"}`);
**And** nunca contiene payloads de negocio ni PII sensible — la prohibición es el **contenido**, no la forma (FR12).

**Given** las piezas de dominio (`AuditLevel`, `ActorContext`),
**When** se ubican,
**Then** no tienen dependencias de framework (`Shared/…/Audit/{Domain|Application}`).

### Story 1.3: Tabla `audit_log` append-only + escritor idempotente (raw DBAL + schema listener)

Como plataforma de ERPify,
quiero la tabla `audit_log` append-only con un escritor idempotente,
para registrar acciones de forma duradera, inmutable y sin duplicados ante reentrega.

**Acceptance Criteria:**

**Given** una migración generada con `make db.diff`,
**When** se aplica,
**Then** existe `audit_log` con el esquema del ADR (PK `id` UUIDv7; `level`, `action`, `actor_type`, `actor_id` null, `correlation_id`, `resource_type` null, `resource_id` null, `metadata` jsonb, `ip` inet null, `user_agent` null, `occurred_on` timestamptz) e índices `(actor_id, occurred_on)`, `(correlation_id)`, `(level, occurred_on)`, `(resource_type, resource_id)` (FR3, NFR9).

**Given** el `postGenerateSchema` listener,
**When** se re-ejecuta `make db.diff` / `doctrine:schema:validate`,
**Then** NO se propone `DROP TABLE audit_log` y el esquema queda in-sync, sin entidad ORM (NFR6).

**Given** un `RecordAuditEntry` a persistir,
**When** se inserta en `audit_log`,
**Then** el `id` utilizado es el generado por el `RecordAuditEntry` (sella el `AuditLogEntry`), no uno generado por PostgreSQL (la idempotencia depende de ello) (FR4).

**Given** el escritor DBAL,
**When** inserta un `AuditLogEntry` y luego reinserta el mismo `id`,
**Then** la segunda inserción afecta 0 filas (`ON CONFLICT (id) DO NOTHING`), sin lanzar (FR4, NFR7) — integración contra Postgres real.

**Given** el alcance de la idempotencia,
**When** se razona sobre ella,
**Then** se basa **exclusivamente** en la identidad del `RecordAuditEntry` (mismo `id`): tolera el redelivery del **mismo** mensaje, pero un mensaje **regenerado** con un `id` nuevo produce una fila nueva — no hay deduplicación semántica (FR4).

**Given** la app,
**When** se busca una ruta de `UPDATE`/`DELETE` sobre `audit_log`,
**Then** no existe salvo el proceso de retención (Epic 3): el escritor solo inserta (FR9).

**Given** los enums `level`/`actor_type`,
**When** se mapean a Postgres/Doctrine,
**Then** reutilizan `EnumType`/`EnumTypeValidator`.

### Story 1.4: Puerto `AuditLogger` + persistencia por nivel (activity async / security write-before-send) + `ActorContextFactory`

Como desarrollador de un módulo,
quiero el puerto `AuditLogger` que sella actor + correlación y persiste según el nivel,
para registrar una acción con una sola llamada, sin IO en el camino de request para `activity` y con durabilidad write-before-send para `security`.

**Acceptance Criteria:**

**Given** `AuditLogger` (`Shared/…/Audit/Application`),
**When** un caso de uso llama `log(action, level, resource?, metadata?)`,
**Then** construye la entrada (con su `id` UUIDv7) estampando `correlationId` (de `CorrelationIdListener`) y `ActorContext` (de `ActorContextFactory`); para `level=activity` despacha un `RecordAuditEntry` al transporte `audit`, para `level=security` ejecuta la inserción síncrona write-before-send (FR2, FR14, FR16, D3).

**Given** `ActorContextFactory` antes de que exista auth,
**When** resuelve una request `/api/*` sin autenticación,
**Then** devuelve `actor_type=anonymous`;
**And** en CLI/scheduler devuelve `actor_type=system` (FR16, NFR10).

**Given** el `RecordAuditEntryHandler` sobre el transporte `audit` (consumido por `messenger_worker` en dev),
**When** consume el `RecordAuditEntry`,
**Then** invoca el escritor DBAL de 1.3 (insert idempotente) (FR4).

**Given** un fallo del despacho de auditoría (incluidos errores de programación: configuración rota, serialización, servicio inexistente),
**When** ocurre,
**Then** **nunca** impide completar el caso de uso principal;
**And** el fallo queda registrado mediante observabilidad técnica — la pérdida pre-encolado es aceptable, pero nunca silenciosa; no se manda tragarse toda excepción a ciegas, queda libertad de implementación (FR4, NFR2).

**Given** el camino de request,
**When** se observa `AuditLogger->log` con `level=activity`,
**Then** no hace IO de persistencia síncrono (solo encola en el transporte `audit`);
**And** con `level=security` ejecuta una inserción síncrona write-before-send (rara, fuera del path caliente) — única excepción consciente a NFR2 (NFR2, D3).

### Story 1.5: Migrar `BANK_ACCOUNTS_VIEWED` al subsistema real (primer actor auditado)

Como investigador de seguridad,
quiero que "ver las cuentas de un banco" quede registrado en `audit_log` (sustituyendo el placeholder de log-line),
para tener la primera traza forense real end-to-end y validar todo el backbone.

**Acceptance Criteria:**

**Given** `BankAccountSearcher` tras una lectura con éxito,
**When** se ejecuta,
**Then** llama a `AuditLogger->log('BANK_ACCOUNTS_VIEWED', activity, resource=Bank:bankId)` en vez de despachar `BankAccountsViewedAuditEvent` (FR18).

**Given** la migración,
**When** se completa,
**Then** se eliminan `BankAccountsViewedAuditEvent` y `RecordAuditLogOnBankAccountsViewed`, y la entrada de `BankAccountSearcher.php` desaparece de `api/.event-dispatch-allowlist` (FR18).

**Given** una request real que lista cuentas,
**When** se procesa,
**Then** aparece exactamente una fila en `audit_log` con `action=BANK_ACCOUNTS_VIEWED`, `level=activity`, `actor_type=anonymous`, `correlation_id` = el de la request, sin IBAN/PII de cuenta en `metadata` (FR12, FR14);
**And** `resource_type`/`resource_id` identifican el banco consultado (validación real del caso forense) — Behat.

**Given** reentrega at-least-once,
**When** el worker reprocesa,
**Then** sigue habiendo una sola fila (idempotencia por `id`) (FR4, NFR7).

**Given** `docs/architecture/event-catalog.md`,
**When** se actualiza,
**Then** *Non-domain signals* refleja que `BANK_ACCOUNTS_VIEWED` persiste en `audit_log` (no log-line) y se retira la nota del allowlist.

**Given** los gates,
**When** se ejecutan,
**Then** `make php.stan`, `make php.quality` y `make php.behat` pasan.

## Epic 2: Captura híbrida — cobertura activity + security

El sistema captura automáticamente toda la superficie de Fase 1 (no solo llamadas explícitas): navegación, listados, búsquedas y exportaciones (`activity`) y denegaciones de permiso (`security`). Construye sobre E1; independiente de E3/E4.

### Story 2.1: `AuditPolicy` — qué es auditable y a qué nivel (alcance Fase 1)

Como plataforma de ERPify,
quiero una `AuditPolicy` que decida antes de emitir si una interacción es auditable y a qué nivel,
para evitar el log-explosion y que el criterio no quede en manos de cada desarrollador.

**Acceptance Criteria:**

**Given** una ruta de asset / health check / Mercure / polling / request técnico,
**When** la `AuditPolicy` la clasifica,
**Then** la marca no auditable y no se emite ninguna entrada de auditoría (FR17, NFR11).

**Given** una navegación/listado/detalle/búsqueda de backoffice,
**When** la clasifica,
**Then** es auditable con `level=activity` (FR6, FR17).

**Given** la política,
**When** se evalúa,
**Then** no existe una `action` genérica `HTTP_REQUEST` (capturar-todo-decidir-tarde) (NFR11).

**Given** la política,
**When** se prueba,
**Then** se cubre con tests unitarios sin contenedor ni BD (decisión pura).

### Story 2.2: Subscriber de access-log sobre `kernel.terminate`

Como plataforma de ERPify,
quiero un `EventSubscriber` que capture contexto en `kernel.terminate` y delegue en la `AuditPolicy`,
para auditar la actividad de `/api/*` con coste de latencia nulo.

**Acceptance Criteria:**

**Given** el subscriber,
**When** se registra,
**Then** escucha `kernel.terminate` (tras enviar la respuesta), está acotado a `/api/*` y solo actúa sobre la request principal (`isMainRequest()`), nunca subrequests (FR5a, NFR1).

**Given** una request,
**When** termina,
**Then** el subscriber **solo captura contexto** (`correlation_id`, `ip`, `user_agent`, método/ruta/estado) y **delega toda decisión de auditabilidad y clasificación (`level`/`action`) a la `AuditPolicy`** — el subscriber no construye acciones ni decide qué se guarda (D2, FR5a, FR6).

**Given** la `AuditPolicy` marca la request como auditable,
**When** termina,
**Then** se emite vía `AuditLogger->log(...)` con el `level`/`action` que dicta la política; si la marca no auditable, no se emite nada.

**Given** el subscriber,
**When** se mide su efecto,
**Then** no añade latencia al camino de request (post-respuesta) y nunca altera la respuesta (NFR1).

### Story 2.3: Captura `security` sobre `AccessDeniedException`

Como investigador de seguridad,
quiero que las denegaciones de permiso queden auditadas a nivel `security`,
para detectar accesos indebidos sin esparcir lógica de auditoría por los handlers.

**Acceptance Criteria:**

**Given** una `AccessDeniedException` en el pipeline RFC 9457,
**When** se produce,
**Then** se registra una fila en `audit_log` con `level=security`, `action=ACCESS_DENIED`, `correlation_id` de la request y el recurso si está disponible (FR7);
**And** al ser `level=security`, la escritura es la inserción síncrona write-before-send de 1.4 (la denegación no se pierde aunque el proceso muera tras la respuesta) (D3).

**Given** el orden de listeners,
**When** se dispara la excepción,
**Then** el listener de auditoría la observa antes de que `ExceptionResponder` fije la respuesta y detenga la propagación (prioridad correcta, fijada por test de regresión — patrón `SearchObservabilityListener`) (NFR12).

**Given** el listener,
**When** actúa,
**Then** es puramente aditivo: nunca fija la respuesta ni cambia el cuerpo RFC 9457 del 403.

## Epic 3: Retención diferenciada y borrado GDPR

La obligación legal/PII queda satisfecha y `audit_log` se mantiene acotado — un límite de riesgo de compliance propio. Construye sobre E1; independiente de E2/E4.

### Story 3.1: `AuditLogPruner` — retención por nivel (Symfony Scheduler)

Como responsable de cumplimiento,
quiero una poda programada con retención diferenciada por nivel,
para mantener `audit_log` acotado respetando la separación legal entre seguridad y actividad.

**Acceptance Criteria:**

**Given** el patrón `…MaintenanceSchedule` + `Pruner` sobre `scheduler_maintenance` (consumido por `scheduler_worker` en prod, plegado en `messenger_worker` en dev),
**When** corre la tarea programada,
**Then** elimina las filas `activity` más antiguas que su ventana y las `security` más antiguas que su ventana (más larga) (FR10).

**Given** las ventanas de retención,
**When** se configuran,
**Then** son parametrizables y `security` > `activity`.

**Given** `audit_log`,
**When** se audita el código,
**Then** la poda es la única ruta de `DELETE` (FR9) — el resto es append-only.

**Given** filas sembradas con distintas edades/niveles,
**When** corre la poda,
**Then** se borran exactamente las fuera de ventana (integración contra Postgres real).

**Given** `rules/database.md`,
**When** se actualiza,
**Then** documenta `audit_log` como excepción de retención justificada.

### Story 3.2: Borrado GDPR — pseudonimización de `actor_id`

Como responsable de cumplimiento,
quiero que el "olvídame" pseudonimice al actor en lugar de borrar filas,
para satisfacer el derecho de supresión preservando la traza de seguridad.

**Acceptance Criteria:**

**Given** una solicitud de supresión para un `actor_id`,
**When** se ejecuta,
**Then** todas sus filas en `audit_log` quedan con `actor_id` **pseudonimizado** (no borradas); la traza de seguridad (`action`, `level`, `occurred_on`, recurso) sobrevive (FR11).

**Given** la misma solicitud de supresión,
**When** se ejecuta,
**Then** `ip` y `user_agent` de esas filas se redactan junto al `actor_id` mediante hash/truncado **irreversible** (Modelo 1): se **almacenan completos** en la inserción y **solo** se redactan en la supresión (minimización en el disparador GDPR, no en origen), preservando el valor forense hasta el "olvídame" (FR11, NFR3, D4 enmendado).

**Given** la operación de supresión,
**When** se repite,
**Then** es idempotente.

**Given** `PRODUCTION_SECURITY_CHECKLIST.md` y `rules/security.md`,
**When** se actualizan,
**Then** reflejan `audit_log` como PII con política de retención + pseudonimización (NFR3).

## Epic 4: Investigación de auditoría — read model + UI admin

Una persona puede investigar (reconstruir la jornada de un actor): read model directo sobre `audit_log` + UI admin PWA. Construye sobre E1; independiente de E2/E3.

### Story 4.1: Read model de investigación directo sobre `audit_log` (`Backoffice/Audit`)

Como investigador,
quiero consultar el timeline de auditoría con filtros,
para reconstruir la jornada de un actor a partir de `audit_log`.

**Acceptance Criteria:**

**Given** `Backoffice/Audit`,
**When** se implementa el read model,
**Then** consulta `audit_log` directamente — sin tabla `audit_timeline`, sin tabla de proyección, sin `Projector` (Fase 1) (FR19).

**Given** que `audit_log` contiene registros `activity` y `security`,
**When** se consulta el timeline,
**Then** los resultados se obtienen directamente desde `audit_log` **sin replicación de datos** (ninguna tabla intermedia "por comodidad") (FR19).

**Given** un endpoint de consulta,
**When** se invoca,
**Then** devuelve un timeline paginado (keyset) filtrable por actor (`actor_type`/`actor_id`), rango de fechas, recurso (`resource_type`/`resource_id`), `level` y `action` (UX-DR1, UX-DR2).

**Given** los filtros,
**When** se ejecutan,
**Then** se apoyan en los índices de FR3 (sin full scan; respaldado por `EXPLAIN`) (NFR9).

**Given** la reconstrucción de jornada,
**When** se consulta por `actor_id` + ventana,
**Then** las entradas se pueden correlar por `correlation_id` (UX-DR3).

**Given** el boundary de auditoría (D5),
**When** se revisa `Backoffice/Audit`,
**Then** **no contiene `AuditLogger` ni casos de uso de escritura sobre `audit_log`** — Backoffice consume, no escribe auditoría (FR13);
**And** no alcanza el `Domain/`/`Infrastructure/` de otros contextos (`php.lint.bounded-context` / `php.deptrac`) (NFR5).

### Story 4.2: UI admin de investigación (PWA)

Como administrador,
quiero una pantalla para navegar y filtrar el timeline de auditoría,
para investigar visualmente la actividad y la seguridad.

**Acceptance Criteria:**

**Given** la ruta admin PWA de `Backoffice/Audit`,
**When** se carga,
**Then** renderiza el timeline desde el endpoint de 4.1 con controles de filtro (actor / rango de fechas / recurso / nivel / acción) (UX-DR1, UX-DR2).

**Given** una entrada,
**When** se abre el detalle,
**Then** muestra `action`, actor (`actor_type`+`actor_id`), `correlation_id`, recurso, `ip`, `user_agent`, `occurred_on` y `metadata` (UX-DR4).

**Given** un actor pseudonimizado,
**When** se muestra,
**Then** se presenta como tal y nunca se expone payload sensible/PII de negocio (UX-DR5).

**Given** las reglas PWA,
**When** se implementa,
**Then** cumple accesibilidad (navegación por teclado, HTML semántico), `safeHref` en URLs dinámicas y sin PII en `localStorage`/`sessionStorage`.

**Given** que no hay documento UX dedicado,
**When** se planifica esta historia,
**Then** se marca **dependencia: validación UX pendiente** — los UX-DR derivados del ADR bastan para planificar, no para construir las pantallas finales sin un pase de diseño UX.

