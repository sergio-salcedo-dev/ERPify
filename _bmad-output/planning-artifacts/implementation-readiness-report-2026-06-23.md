---
stepsCompleted: ['step-01-document-discovery', 'step-02-prd-analysis', 'step-03-epic-coverage-validation', 'step-04-ux-alignment', 'step-05-epic-quality-review', 'step-06-final-assessment']
inputDocuments:
  - _bmad-output/planning-artifacts/epics.md
referencedSources:
  - docs/adr/audit-activity-log.md
  - docs/architecture/event-catalog.md
  - docs/architecture-api.md
  - docs/api-error-contract.md
  - docs/adr/domain-event-handler-idempotency.md
  - docs/rules/database.md
  - docs/rules/security.md
  - PRODUCTION_SECURITY_CHECKLIST.md
  - docs/project-context.md
---

# Implementation Readiness Assessment Report

**Date:** 2026-06-23
**Project:** ERPify — Auditoría operativa / de actor (`AuditLogger` → `audit_log`)

## Document Inventory

| Tipo de documento | Estado | Fuente |
|-------------------|--------|--------|
| PRD (dedicado) | ⚠️ No existe como fichero independiente | Requirements Inventory embebido en `epics.md` (FR1–FR20, NFR1–NFR12) |
| Architecture (dedicado) | ⚠️ No existe en `planning-artifacts/` | ADR congelado `docs/adr/audit-activity-log.md` + `docs/architecture-api.md` (brownfield) |
| Epics & Stories | ✅ `epics.md` (37 KB, 2026-06-23) | `_bmad-output/planning-artifacts/epics.md` |
| UX | ⚠️ No existe documento UX dedicado | UX-DR1–UX-DR5 derivados del ADR, embebidos en `epics.md` (requieren pase UX) |

**Sin duplicados** (no hay versiones whole + sharded en conflicto).

**Decisión de enfoque (confirmada por el usuario — opción A):** el ADR `docs/adr/audit-activity-log.md`
(design frozen) actúa como **PRD + Architecture de facto**; los **UX-DR1–5** embebidos en `epics.md`
(derivados del ADR) actúan como el **UX**. La validación se hace contra esas fuentes brownfield.

## PRD Analysis

**Fuente de requisitos:** ADR `audit-activity-log.md` (decisiones D1–D7 + esquema + alcance Fase 1),
materializado en el *Requirements Inventory* embebido de `epics.md`. Verificada la **fidelidad
ADR ↔ Inventory**: cada FR/NFR rastrea a una decisión del ADR, sin requisitos huérfanos ni
contradicciones.

### Functional Requirements (20)

- **FR1** — Eje de auditoría en `Shared` detrás del seam `AuditLogger`, sin tipo público `AuditEvent`; `RecordAuditEntry` no extiende `DomainEvent`, no va por `EventBus`/`event_store`; `StoredDomainEvent` no se renombra. *(ADR D1)*
- **FR2** — Puerto `AuditLogger->log(...)`: único seam de escritura público; persiste según nivel. *(D2/D3/D5)*
- **FR3** — Tabla `audit_log` append-only con esquema del ADR + índices `(actor_id,occurred_on)`/`(correlation_id)`/`(level,occurred_on)`/`(resource_type,resource_id)`; PK `id` UUIDv7 = ancla de idempotencia. *(esquema ADR)*
- **FR4** — `activity` async vía transporte dedicado `audit`, `id` app-minted antes de encolar, `INSERT … ON CONFLICT (id) DO NOTHING` idempotente; `security` síncrono write-before-send. *(D3)*
- **FR5** — Captura híbrida: (a) access-log genérico en `kernel.terminate` acotado a `/api/*`; (b) llamadas explícitas `AuditLogger->log(...)`. *(D2)*
- **FR6** — `AuditPolicy` decide auditabilidad + nivel **antes** de emitir; el hook solo captura contexto. *(D2)*
- **FR7** — Captura `security` de denegaciones vía listener sobre `AccessDeniedException` en el pipeline RFC 9457. *(D2)*
- **FR8** — Dos niveles (`activity`, `security`); el eje de cambios de datos lo cubre `DomainEvent`, no se duplica. *(D4)*
- **FR9** — `audit_log` append-only: sin `UPDATE`/`DELETE` salvo retención. *(D4)*
- **FR10** — Retención diferenciada por nivel vía `AuditLogPruner` (Symfony Scheduler, patrón `HandledDomainEventPruner`). *(D4)*
- **FR11** — Borrado GDPR pseudonimiza `actor_id` (la traza de seguridad sobrevive). *(D4)*
- **FR12** — `metadata` jsonb sin payload sensible (solo IDs/discriminantes, nunca IBAN/PII). *(D4)*
- **FR13** — Ubicación: backbone en `Shared/`, consulta en `Backoffice/Audit/`; Backoffice consume, no escribe. *(D5)*
- **FR14** — Toda entrada lleva `correlation_id` (de `CorrelationIdListener`) + `actor_id` (nullable hasta auth) desde el día 1; `audit_session` diferido. *(D6)*
- **FR15** — `ActorContext` VO de dominio + enum `ActorType` (`anonymous|system|api_key|user`); `actor_type` obligatorio, `actor_id` nullable según tipo. *(D7)*
- **FR16** — `ActorContextFactory` como única pieza que cambia al entrar auth real. *(D7 / secuencia auth)*
- **FR17** — Alcance de captura Fase 1 fijado (`activity`/`security`); nunca assets/health/Mercure/polling; sin `action` `HTTP_REQUEST`. *(Alcance Fase 1)*
- **FR18** — Migrar `BankAccountsViewed` del placeholder log-line al subsistema real; retirar entrada de `.event-dispatch-allowlist` + actualizar event-catalog. *(consumidor #1)*
- **FR19** — Read model de investigación directo sobre `audit_log` (sin proyección en Fase 1). *(YAGNI / trigger de revisita)*
- **FR20** — UI admin PWA: timeline + filtros + detalle. *(read side D5)*

### Non-Functional Requirements (12)

- **NFR1** — Latencia request-path nula para el access-log (`kernel.terminate` post-respuesta). *(D2)*
- **NFR2** — Request path libre de IO de auditoría para `activity`; `security` es la única excepción consciente (síncrona). *(D3)*
- **NFR3** — `audit_log` es PII → GDPR (retención por nivel + pseudonimización), registrado en `rules/security.md` + checklist. *(D4)*
- **NFR4** — Sin compatibilidad hacia atrás (app no en producción). *(cabecera ADR)*
- **NFR5** — Aislamiento de contextos (`php.lint.bounded-context` + `php.deptrac`): captura no entra en `Domain/`; `Backoffice/Audit` no alcanza internals ajenos. *(D5)*
- **NFR6** — Persistencia raw DBAL + `postGenerateSchema` listener (sin entidad ORM), patrón `event_store`/`handled_domain_event`. *(rules/database)*
- **NFR7** — At-least-once resuelto por idempotencia de PK (`ON CONFLICT DO NOTHING`); redelivery = no-op. *(D3)*
- **NFR8** — `actor_type` (quién) y `level` (qué clase) son ortogonales; no se colapsan. *(D7)*
- **NFR9** — Consulta forense soportada por índices de FR3; sin `SELECT *`, keyset si el volumen lo exige. *(esquema ADR)*
- **NFR10** — Secuencia frente a auth: backbone antes de User/RBAC; `actor_id` nullable hasta auth. *(D7 / secuencia)*
- **NFR11** — Sin log-explosion: la política decide antes de persistir. *(D2)*
- **NFR12** — Orden de listeners: el de `security` observa antes de que `ExceptionResponder` fije la respuesta (patrón `SearchObservabilityListener`). *(api-error-contract)*

### UX Design Requirements (5, derivados del ADR — no hay doc UX)

- **UX-DR1** timeline · **UX-DR2** filtros (actor/fecha/recurso/level/action) · **UX-DR3** reconstrucción de jornada (`actor_id`+`correlation_id`+ventana) · **UX-DR4** detalle de entrada · **UX-DR5** presentación PII-aware.

### Additional Requirements / Constraints

Reutilización explícita de piezas existentes: `CorrelationIdListener`, patrón de mantenimiento
`HandledDomainEventMaintenanceSchedule` + transporte `scheduler_maintenance`, `EnumType`/`EnumTypeValidator`,
`Identifiable` (UUIDv7), `postGenerateSchema` (schema-aware), transporte `audit` dedicado en `messenger.yaml`.
Actualización documental obligatoria: `architecture-api.md`, `event-catalog.md` (*Non-domain signals*),
`PRODUCTION_SECURITY_CHECKLIST.md`, `rules/security.md`, nota de excepción de borrado en `rules/database.md`,
y retirada de la entrada de `.event-dispatch-allowlist`.

### PRD Completeness Assessment

**Sólida y completa para implementar.** El ADR es un diseño congelado, internamente consistente, con
alternativas descartadas documentadas inline (rigor por encima de lo habitual). El *Requirements Inventory*
de `epics.md` es trazable 1:1 al ADR. **Única laguna real:** no hay documento UX dedicado — los UX-DR son
suficientes para *planificar* (Epic 4) pero **no** para construir las pantallas finales sin un pase de
diseño UX (riesgo ya explícitamente marcado en Story 4.2). El resto del subsistema (Epics 1–3) no depende
de UX y está listo.

## Epic Coverage Validation

### Coverage Matrix (FR → Epic/Story)

| FR | Épica / Historia | Estado |
|----|------------------|--------|
| FR1 | E1 · 1.2 (seam + internals, sin `AuditEvent`) | ✓ Covered |
| FR2 | E1 · 1.4 (puerto `AuditLogger`) | ✓ Covered |
| FR3 | E1 · 1.3 (tabla `audit_log` + índices) | ✓ Covered |
| FR4 | E1 · 1.3 (writer idempotente) + 1.4 (dispatch async/sync) | ✓ Covered |
| FR5 | E2 · 2.2 (`kernel.terminate`, vía 5a) + E1 · 1.5 (vía explícita 5b) | ✓ Covered |
| FR6 | E2 · 2.1 (`AuditPolicy`) | ✓ Covered |
| FR7 | E2 · 2.3 (`AccessDeniedException`) | ✓ Covered |
| FR8 | E1 · 1.2 (niveles `activity`/`security`) | ✓ Covered |
| FR9 | E1 · 1.3 (invariante append-only) + E3 · 3.1 (única ruta `DELETE`) | ✓ Covered |
| FR10 | E3 · 3.1 (`AuditLogPruner`) | ✓ Covered |
| FR11 | E3 · 3.2 (pseudonimización GDPR) | ✓ Covered |
| FR12 | E1 · 1.2 (`metadata` sin PII) | ✓ Covered |
| FR13 | E1 · 1.1–1.4 (`Shared` backbone) + E4 · 4.1 (`Backoffice` consume) | ✓ Covered |
| FR14 | E1 · 1.2/1.4 (`correlation_id` + `actor_id` día 1) | ✓ Covered |
| FR15 | E1 · 1.1 (`ActorContext` + `ActorType`) | ✓ Covered |
| FR16 | E1 · 1.4 (`ActorContextFactory`) | ✓ Covered |
| FR17 | E2 · 2.1 (alcance captura Fase 1) | ✓ Covered |
| FR18 | E1 · 1.5 (migración `BANK_ACCOUNTS_VIEWED`) | ✓ Covered |
| FR19 | E4 · 4.1 (read model directo) | ✓ Covered |
| FR20 | E4 · 4.2 (UI admin PWA) | ✓ Covered |

### NFR Coverage (asignación por épica)

E1 → NFR2, NFR4, NFR6, NFR7, NFR8, NFR10 · E2 → NFR1, NFR11, NFR12 · E3 → NFR3 · E4 → NFR5, NFR9.
Unión = NFR1–NFR12 (12/12).

### Missing Requirements

**Ninguno.** No hay FR ni NFR sin ruta de implementación trazable. Tampoco hay FRs en las épicas que
no estén en el PRD-de-facto (el inventario embebido es exactamente el del ADR).

### Coverage Statistics

- FRs totales: **20** · cubiertos: **20** → **100 %**
- NFRs totales: **12** · cubiertos: **12** → **100 %**
- UX-DR totales: **5** · cubiertos por historias E4 (4.1/4.2): **5** → **100 %** (sujetos a pase de diseño UX)

### Nit de trazabilidad (no es gap)

La cabecera de Epic 1 (línea de *FRs covered*) **omite FR9**, aunque el *FR Coverage Map* y la AC de
Story 1.3 ("no existe ruta de `UPDATE`/`DELETE` salvo retención") sí lo cubren. Es una inconsistencia
documental menor, no una laguna de cobertura. Recomendación: añadir `FR9` a la lista de Epic 1 para que
la cabecera concuerde con el mapa.

## UX Alignment Assessment

### UX Document Status

**Not Found** (documento dedicado). UX **implícito y confirmado**: Epic 4 / Story 4.2 entrega una UI
admin PWA (timeline + filtros + detalle). El contrato UX vive como **UX-DR1–5 derivados del ADR** (D5
read side + D6 reconstrucción de jornada), embebidos en `epics.md`.

### UX ↔ PRD Alignment

Consistente. Cada UX-DR rastrea a una decisión del ADR y a un FR del read side:
UX-DR1/UX-DR2 → FR19/FR20 (timeline + filtros sobre `audit_log`), UX-DR3 → FR14/D6 (correlación
`actor_id`+`correlation_id`), UX-DR4 → esquema `audit_log` (detalle de entrada), UX-DR5 → FR11/FR12/NFR3
(PII-aware: pseudonimización + sin payload sensible). Sin UX-DR huérfanos.

### UX ↔ Architecture Alignment

Soportado a nivel estructural: el read model directo (Story 4.1) se apoya en los índices de FR3
(`(actor_id,occurred_on)`, `(correlation_id)`, `(level,occurred_on)`, `(resource_type,resource_id)`),
que cubren exactamente los ejes de filtrado/agrupación que pide UX-DR2/UX-DR3 (NFR9: sin full scan).
La paginación keyset (FR19/NFR9) soporta un timeline de gran volumen. La UI (Story 4.2) se ancla a las
reglas PWA del proyecto (`safeHref`, a11y/teclado, sin PII en `localStorage`). No hay componente UX que
la arquitectura no soporte.

### Warnings

- ⚠️ **No hay especificación de diseño UX para las pantallas finales** (layout del timeline, diseño de
  interacción de los controles de filtro, jerarquía visual, vacíos/errores/carga, estados PII-aware).
  Los UX-DR bastan para **planificar** Epic 4, **no** para **construir** las pantallas sin un pase de
  diseño. **Riesgo ya marcado explícitamente** en Story 4.2 (AC final: "dependencia: validación UX
  pendiente") — el plan es honesto sobre su propia laguna, lo cual es lo correcto.
- ✅ **Sin impacto en Epics 1–3** (backbone, captura, retención/GDPR): no tienen superficie UI; su
  readiness es independiente de esta laguna.

### Recomendación

Tratar el **pase de diseño UX como precondición de la Story 4.2** (no del Epic 4 entero: 4.1, el read
model + endpoint, puede construirse ya). Opciones: (a) un mini-spec UX para la pantalla de auditoría
antes de codificar 4.2, o (b) entregar 4.1 + un esqueleto funcional de 4.2 y refinar UX en una iteración
posterior — decisión de alcance del usuario.

## Epic Quality Review

### Compliance Checklist (por épica)

| Criterio | E1 | E2 | E3 | E4 |
|----------|----|----|----|----|
| Entrega valor de usuario (no hito técnico) | ✓¹ | ✓ | ✓ | ✓ |
| Funciona de forma independiente | ✓ | ✓ (solo E1) | ✓ (solo E1) | ✓ (solo E1) |
| Historias bien dimensionadas | ✓² | ✓ | ✓ | ✓ |
| Sin dependencias forward | ✓ | ✓ | ✓ | ✓ |
| Tablas creadas cuando se necesitan | ✓³ | n/a | n/a | n/a |
| ACs claros (Given/When/Then, testables) | ✓ | ✓ | ✓ | ✓⁴ |
| Trazabilidad a FRs mantenida | ✓ | ✓ | ✓ | ✓ |

### Hallazgos por severidad

#### 🔴 Critical Violations — **Ninguna**

No hay épicas técnicas sin valor, ni dependencias forward, ni historias del tamaño de una épica que no
puedan completarse.

#### 🟠 Major Issues — **Ninguna propia de calidad de épicas**

El único bloqueante de readiness real es la **laguna de diseño UX para Story 4.2** (ver *UX Alignment*),
ya marcada explícitamente en la propia historia. No es un defecto estructural del desglose, sino una
precondición externa honestamente declarada.

#### 🟡 Minor Concerns

1. **Cabecera de Epic 1 omite FR9** (ya señalado en *Epic Coverage*) — discrepancia con el FR Coverage
   Map; FR9 sí está cubierto por la AC de Story 1.3.
2. **FR5 se descompone en 5a/5b** (vía genérica vs explícita) en las ACs de Story 2.2 sin declarar esos
   sub-labels en el *Requirements Inventory* (FR5 es una línea única). Trazabilidad correcta pero implícita;
   recomendación: nombrar 5a/5b en el inventario.
3. **Story 1.4 es la historia más pesada** (puerto `AuditLogger` + rama async `activity` + rama síncrona
   `security` + `ActorContextFactory` + aislamiento de fallos). Es cohesiva (el seam y sus dos ramas de
   persistencia pertenecen juntos), pero es candidata a split si durante el desarrollo resulta demasiado
   grande (p. ej. separar la rama `security` write-before-send o `ActorContextFactory`). No es violación.
4. **Story 3.2 deja una AC de gobernanza abierta** sobre el tratamiento GDPR de `ip`/`user_agent`
   ("a concretar en diseño técnico; si excede lo que congela el ADR, actualizar el ADR antes"). Está
   gestionada de forma responsable (gate de gobernanza documental explícito), pero es un punto de diseño
   aún sin cerrar — convendría resolverlo en el diseño técnico de Epic 3 antes de codificar 3.2.

### Fortalezas destacables (calibración honesta — no todo es defecto)

- **Epic 1 es un *walking skeleton* correcto, no infraestructura pura.** El riesgo clásico ("Epic 1 =
  setup técnico sin valor") está **explícitamente neutralizado**: E1 valida end-to-end una acción real
  (`BANK_ACCOUNTS_VIEWED`) con actor + correlación + idempotencia desde el día 1. Es el patrón recomendado.
- **Independencia mejor que la regla lineal:** E2/E3/E4 dependen **solo** de E1 y son mutuamente
  independientes — habilita reordenar o paralelizar el trabajo posterior sin romper nada.
- **Timing de tabla correcto:** `audit_log` nace en Story 1.3 (primer punto que la necesita), no upfront
  en 1.1. Las piezas de dominio puro (1.1 `ActorContext`, 1.2 `AuditLevel`) preceden a la persistencia.
- **ACs de calidad superior:** incluyen casos negativos (rechazo de `actor_type` null, `actor_id` inválido
  por tipo), idempotencia real contra Postgres (`ON CONFLICT → 0 filas`), verificación de gates
  (`php.stan`/`php.quality`/`php.behat`) y de boundaries (`deptrac`/`bounded-context`) **dentro** de las
  propias ACs.
- **Integración brownfield explícita:** Story 1.5 es la historia de migración del placeholder existente
  (lo que un proyecto brownfield debe tener), con limpieza del `.event-dispatch-allowlist` y del event-catalog.

---

¹ Borderline por el término "backbone" en el título, pero la épica se autodefine como capacidad
verificable y lo demuestra con el primer actor auditado real. Recomendación cosmética: un título más
orientado a resultado (p. ej. "Primera acción auditada de extremo a extremo").
² Salvo Story 1.4 (la más pesada — ver Minor #3).
³ `audit_log` se crea en Story 1.3, cuando primero se necesita.
⁴ Story 4.2 con dependencia UX externa declarada (ver *UX Alignment*).

## Summary and Recommendations

### Overall Readiness Status

**READY** — con una única salvedad acotada: la **Story 4.2 (UI admin PWA)** requiere un pase de diseño
UX antes de su construcción final. Todo lo demás (Epics 1–3 + Story 4.1, es decir, el backbone, la
captura, la retención/GDPR y el read model + endpoint) está **listo para implementar**.

### Critical Issues Requiring Immediate Action

**Ninguno.** No hay defectos críticos ni mayores estructurales. Cobertura de requisitos 100 %
(20 FR / 12 NFR / 5 UX-DR), sin dependencias forward, sin épicas técnicas, ACs de calidad superior.

### Recommended Next Steps

1. **Resolver la dependencia UX de Story 4.2** antes de codificar sus pantallas: o bien un mini-spec
   UX de la pantalla de auditoría, o bien construir 4.1 (+ esqueleto funcional de 4.2) y refinar el UX
   en una iteración posterior. *(Único bloqueante real, y solo para 4.2.)*
2. **Cerrar la AC abierta de Story 3.2** (tratamiento GDPR de `ip`/`user_agent`) en el diseño técnico de
   Epic 3 antes de codificar 3.2; si la política excede lo que congela el ADR D4, **actualizar el ADR primero**.
3. **Limpiezas de trazabilidad menores (cosméticas, no bloqueantes):** añadir `FR9` a la cabecera de
   Epic 1; declarar los sub-labels `FR5a`/`FR5b` en el Requirements Inventory.
4. **Vigilar el tamaño de Story 1.4** durante el desarrollo; si crece de más, separar la rama `security`
   write-before-send o `ActorContextFactory` en su propia historia.
5. **Proceder con la implementación** siguiendo la secuencia de commits fijada
   (`1.1 → 1.2 → 1.3 → 1.4 → 1.5 → 2.1 → 2.2 → 2.3 → 3.1 → 3.2 → 4.1 → 4.2`) en el PR único
   `feat/shared-audit-actor-context`.

### Final Note

Este assessment identificó **5 hallazgos** (1 warning de UX + 4 menores) y **0 críticos / 0 mayores
estructurales** en 5 categorías (inventario de documentos, requisitos, cobertura, UX, calidad de épicas).
El plan es de alta calidad y trazable de extremo a extremo. Solo la Story 4.2 tiene una precondición de
diseño UX (ya declarada por el propio plan). Las demás historias pueden proceder a implementación tal cual.

---

**Assessment date:** 2026-06-23 · **Assessor:** PM Implementation-Readiness (BMad) · **Subject:**
`_bmad-output/planning-artifacts/epics.md` validado contra el ADR `docs/adr/audit-activity-log.md` (PRD+Architecture de facto).

## Addendum — decisiones de cierre (post-assessment, 2026-06-23)

- **GDPR `ip`/`user_agent` (Story 3.2 — Minor #4 RESUELTO):** decisión cerrada = **erasure-time,
  irreversible (Modelo 1)**. `ip`/`user_agent` se almacenan completos y solo se redactan (hash/truncado
  irreversible) en el "olvídame", junto a la pseudonimización de `actor_id`; sin minimización en origen.
  → **ADR D4 enmendado** (`docs/adr/audit-activity-log.md`) + **AC de Story 3.2 apretada** (de gobernanza
  abierta a regla concreta). La AC abierta deja de ser un punto de diseño pendiente.
- **UX Story 4.2:** decisión de delivery = **Opción B (diferir)** — construir 4.1 (read model + endpoint)
  ya; 4.2 como shell; refinar UX con datos reales en iteración posterior. Candado: la deuda UX se traquea
  como **issue explícito** vinculado a 4.2.
- **Story 1.4 (Minor #3):** se mantiene sin split; honrar internamente el seam `AuditDispatcher`
  (estrategia sync `security` / async `activity`). Corrección de alcance: la evaluación de `AuditPolicy`
  **no** vive en 1.4 (es Story 2.1) — 1.4 son dos concerns (sellado de contexto + dispatch dual-write),
  no tres.
- **Minor #1/#2 (FR9 header, FR5a/5b labels):** higiene documental diferida a **post primer-PR-merge**.

**Readiness tras el cierre:** sin puntos de diseño abiertos en Epics 1–3 + Story 4.1. Único pendiente
de delivery: pase UX para la construcción final de Story 4.2 (diferido por decisión, traqueado como issue).
