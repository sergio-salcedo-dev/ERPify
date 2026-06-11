---
stepsCompleted: [1, 2, 3]
methodology: 'contract-first incremental addendum on existing system — classic steps 4–8 absorbed into the System Invariants kernel (decision kernel) + Implementation Decomposition (decision localization at PR-spec level)'
status: 'frozen-ready'
inputDocuments:
  - '_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-06-10/EXPERIENCE.md'
  - '_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-06-10/DESIGN.md'
  - '_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-06-10/.decision-log.md'
  - 'docs/project-context.md'
  - 'docs/api-error-contract.md'
  - 'docs/architecture-api.md'
  - 'PRODUCTION_SECURITY_CHECKLIST.md'
inheritsFrom:
  - '_bmad-output/planning-artifacts/architecture.md'
  - '_bmad-output/planning-artifacts/architecture-keyset-pagination.md'
workflowType: 'architecture'
documentKind: 'architecture-addendum'
feature: 'Cuentas asociadas (Bank ↔ BankAccount)'
relatedPR: 213
branch: 'feat/backoffice-bank-associated-accounts-dr9j'
project_name: 'ERPify'
user_name: 'Sergio'
date: '2026-06-11'
---

# Architecture Addendum — Cuentas asociadas (Bank ↔ BankAccount)

> **Addendum acotado**, no arquitectura de sistema. **Hereda** de `architecture.md`
> (track search/filters) y `architecture-keyset-pagination.md` (kernel keyset, PR1
> ya mergeado) — no los reescribe. Distila a contrato técnico las cuatro decisiones
> que las UX spines del PR #213 dejaron explícitamente en handoff a arquitectura.
>
> **Alcance fijado (solo estos cuatro contratos):**
> 1. Política dual-truth del delete-guard (UI optimista vs 409 autoritativo + Sentry).
> 2. Read-model del contador de cuentas en la lista (batched, anti-N+1).
> 3. Contrato del endpoint `GET /backoffice/banks/{id}/accounts`.
> 4. Decisión PII del IBAN (íntegro en payload).
>
> **Fuera de alcance (se quedan en la UX spine):** reveal single/multi-IBAN (3.5),
> popover vs dialog del guard (3.6) — son estado React / interacción visual, sin
> impacto en bounded contexts, dominio, API ni persistencia.

_This document builds collaboratively through step-by-step discovery. Sections are appended as we work through each architectural decision together._

## Project Context Analysis

### Requirements Overview

**Functional Requirements (derivados de las UX spines + `.decision-log` Entry 1–3 — sin PRD formal, precedente del repo):**

- **FR1 — Señal de contador en lista.** La **API devuelve `accountCount` por banco**;
  la **UI decide si es clickable** y a dónde navega. El contador es una *affordance*
  de UI, **no** un contrato de navegación de la API. **La API NO debe codificar
  semántica de navegación** (_API MUST NOT encode navigation semantics_) — `accountCount`
  es un dato escalar, no un enlace. `0` se resuelve en presentación (atenuado, sin enlace).
- **FR2 — Campo en detalle.** La API entrega `accountCount`; la UI compone
  "Associated accounts: N · View accounts" / "None". La navegación vive en la UI,
  nunca en el payload.
- **FR3 — Superficie de cuentas por banco (NUEVA).** Ruta `/backoffice/banks/{id}/accounts`;
  tabla read-only: holder, IBAN, alias, currency, status. Filas **no** navegan en v1.
- **FR4 — Delete-guard optimista.** Si `accountCount > 0`, la UI **no bloquea
  preventivamente** `DELETE /banks/{id}`: solo lo **evita en el flujo normal** y ofrece
  recuperación ("View accounts"). `DELETE` **no** está "condicionalmente prohibido en
  frontend": sigue siendo posible y válido desde cualquier estado, y la **UI nunca asume
  el éxito del guard** (ver *Consistency Window*).
- **FR5 — IBAN: dos planos.** **Payload de API = IBAN íntegro; render de cliente =
  enmascarado por defecto.** El enmascarado es presentacional y **nunca ocurre en
  backend** (evita doble-masking y leaks parciales). Reveal/copy son UX (fuera de este
  addendum).
- **FR6 — Recuperación del `bank-in-use` bajo carrera.** Si una alta entre lectura
  y borrado dispara el 409, el `mutation-error` persistente del contrato base **ahora**
  ofrece "View accounts" (antes: sin salida).

**Non-Functional Requirements (dan forma a las decisiones):**

- **Pureza DDD/Hexagonal — read-side.** `BankAccount` se incorpora como **read
  bounded context (query-side), no como agregado de escritura nuevo.** Esto es
  **read-model expansion**, no un BC completo — evita over-architecture. Las cuentas
  son dueñas de su superficie; no se embeben en `Bank` ni en su detalle.
- **Rendimiento / anti-N+1.** El contador se resuelve con **una** query agregada
  batched por página (`GROUP BY bank_id`), no `countByBankId()` en bucle. Índice
  `IDX_53A23E0A11C8FB41` ya existe. p95 del listado sin regresión.
- **Consistency timing (NFR "invisible").** Entre la lectura que alimenta guard/contador
  y el `DELETE` hay una **ventana de staleness**. El sistema **acepta lecturas stale**;
  el backend es la fuente de verdad; el guard **no garantiza invariantes**. No se
  introduce consistencia fuerte ni mecanismo de sincronización. Ver bloque
  *Consistency Window*.
- **Contrato de errores RFC 9457 (NFR26).** El endpoint de cuentas mapea
  `400 invalid-uuid` (vía `Uuid::ensure`) y `404` (banco ausente) por el pipeline
  existente; sin `JsonResponse` de error manual.
- **Paginación keyset cursor-only.** Hereda el envelope `items + pagination.cursor/
  hasMorePages` y el kernel keyset (PR1, `8bfb8b7`).
- **Seguridad / PII (IBAN).** Campo clasificado — ver bloque *Data Exposure
  Classification*. Actualizar `PRODUCTION_SECURITY_CHECKLIST.md`.
- **Coherencia dual-truth + Sentry.** El backend `409 bank-in-use` es la única fuente
  de verdad; el guard UI es fast-path optimista; el 409 es 4xx esperado y se mantiene
  en el drop de `before_send` (`f7b0d5e`, issue `ERPIFY-API-DEV-6`).
- **Realtime diferido — baseline aceptado, no introducido.** v1 estático (sin Mercure
  para cuentas/contador). No se introduce eventual consistency como capacidad nueva:
  **se acepta como baseline**, coherente con el modelo keyset. La **ausencia de
  infraestructura de invalidación es una restricción de diseño, no un gap/deuda
  técnica**. v2 reconsidera.

**Scale & Complexity:**

- Primary domain: full-stack (API read endpoint + read-model + PWA read surface).
- Complexity level: media — acotada a una feature; abre un **read context** `BankAccount`
  (API + PWA) y formaliza una política transversal (dual-truth + consistency window).
- Estimated architectural components: ~4–5 (endpoint + read-model de cuentas ·
  read-model batched del contador · read context `BankAccount` en PWA + wiring Inversify ·
  guard wiring · reuso del mapeo RFC 9457).

### Technical Constraints & Dependencies

- **Estado actual de `BankAccount`.** La API hoy expone solo `countByBankId()`; **no**
  hay endpoint de lectura de cuentas, **ni** read context `BankAccount` en la PWA.
- **Stack.** Symfony 8 / Doctrine ORM 3 / DBAL 4 / PostgreSQL; Next 16 / React 19 /
  Inversify 8. Doctrine: sin `flush($entity)`, sin `fetchAll()`; usar
  `executeQuery()` / `fetchAllAssociative()`.
- **Hereda (no reinventa).** Envelope cursor-only + kernel keyset (PR1 mergeado);
  `SearchCriteria` / `Filters`; pipeline RFC 9457; primitivo `Uuid::ensure`;
  `Paginator` LIGHT/DETAILED.
- **Cero esquema.** Sin migraciones para v1 (índice ya existe). Denormalización
  `bank.account_count` = escape hatch documentada, **no** punto de partida.

### Cross-Cutting Concerns Identified

- **Dual-truth + Consistency Window** (UI guard ↔ invariante de backend) + coherencia
  con el drop de 4xx esperados en Sentry.
- **PII (IBAN) como asset clasificado** — serialización, autorización, logging, auditoría,
  observabilidad.
- **Read-model performance (anti-N+1)** — patrón batched por página.
- **Fronteras de bounded context (read-side)** — read context `BankAccount` en API y
  PWA, sin reach-ins desde/hacia `Bank`.

### Consistency Window (formalización)

> **UI Guard ≠ authoritative state.**

- El sistema **acepta stale reads**: `accountCount` y el guard reflejan el último fetch,
  no el estado en el instante del click.
- El **backend es la única fuente de verdad**: `DELETE /banks/{id}` puede devolver
  `409 bank-in-use` desde cualquier estado, incluso con el guard "abierto".
- El guard **no garantiza invariantes** — reduce la frecuencia del 409, no su posibilidad.
- **No se introduce ningún mecanismo de sincronización** para cerrar la ventana: sin
  polling, sin estrategia de invalidación, sin eventing. La ventana es una **propiedad
  aceptada del sistema**, no un problema a resolver en v1.
- Implicación QA/frontend: probar explícitamente la carrera (alta de cuenta entre
  lectura y borrado) y la recuperación "View accounts" sobre el `mutation-error`.

### Data Exposure Classification — IBAN (formalización)

| Eje | Decisión |
|---|---|
| Clasificación | Campo sensible (PII financiera) |
| Exposición API | **Permitida** — IBAN íntegro en el payload del endpoint de cuentas, bajo autorización |
| Render UI | **Masking-only** — enmascarado presentacional por defecto; el masking nunca ocurre en backend |
| Logging | **Prohibido** — el valor del IBAN nunca se escribe en logs |
| Auditoría | **Requerida para eventos de acceso** (quién/cuándo) — **no** logging a nivel de payload (evita sobreinstrumentación) |

## Starter Template Evaluation

### Primary Technology Domain

Full-stack sobre la **fundación existente de ERPify** (no starter) — addendum, no proyecto nuevo.

### Selected Foundation: fundación existente de ERPify + contratos heredados

**Rationale:** No procede evaluar starters. La feature se construye sobre el código y las
convenciones ya establecidas, **heredando** sin reinventar:

- **API:** Symfony 8 / Doctrine ORM 3 / DBAL 4; pipeline RFC 9457 (NFR26); primitivo
  `Uuid::ensure`; envelope cursor-only + kernel keyset (PR1, `8bfb8b7`); `SearchCriteria`/
  `Filters`; `Paginator` LIGHT/DETAILED.
- **PWA:** Next 16 / React 19 / Inversify 8; read context por bounded context;
  `AsyncBoundary` / `ProblemDisplay` / `CorrelationIdChip`; `CopyButton`; `safeHref`.

**Note:** No hay *initialization story* — el primer trabajo es código de feature, no scaffolding.

## System Invariants (Decision Kernel)

> **Kernel global, no PR-level.** Cuatro invariantes transversales que **todos** los PRs de
> esta feature deben honrar. No alternativas, no debate — solo invariantes. Su función es
> preservar la trazabilidad de las decisiones transversales que, de otro modo, se
> fragmentarían entre PRs (*decision localization* con kernel central).

1. **Dual-truth.** El backend `409 bank-in-use` es la **única autoridad** sobre la
   borrabilidad de un banco. Cualquier guard de UI es un *fast-path* **optimista** y
   **NO** debe tratarse como autoritativo: `DELETE /banks/{id}` sigue siendo válido y
   posible desde cualquier estado.
2. **Error handling.** Todo error de entrada/precondición fluye por el pipeline **RFC 9457**
   (NFR26) — nunca JSON de error manual. `409 bank-in-use` y `400 invalid-uuid` son errores
   de cliente **esperados** y se mantienen en el drop de Sentry `before_send` (`f7b0d5e`).
3. **PII classification — IBAN.** El IBAN es PII financiera sensible: se expone **íntegro
   solo** vía payload de API **autorizado**; el enmascarado es **presentacional** (nunca
   backend); **nunca** se registra en logs; los **eventos de acceso** se auditan (no los payloads).
4. **Consistency model.** Las señales derivadas de lectura (`accountCount`, estado del guard)
   son **tolerantes a staleness**. **No** se introduce mecanismo de sincronización
   (polling / invalidación / eventing); la ventana de staleness es una **propiedad aceptada**
   del sistema, con el backend como punto de reconciliación.

## Implementation Decomposition

> Flujo **contract-first incremental sobre sistema existente** (no PRD → Arch → Decisions →
> Impl). Cada PR es autocontenido y trae sus formas concretas (SQL / DTO / serializer /
> endpoint shape). Orden **safe-first**: backend additive primero, el cambio de
> comportamiento (guard) al final, detrás de los datos de los que depende.

### Dependency DAG

```
PR1 (API · count read-model) ───────────────┐
                                             ├─→ PR4 (PWA · señales lista+detalle) ─→ PR5 (PWA · guard + recovery)
PR2 (API · accounts endpoint) ─→ PR3 (PWA · accounts surface) ─┘
```

Aristas: `PR1→PR4` (dato `accountCount`) · `PR2→PR3` (endpoint) · `PR3→PR4` (el enlace del
contador necesita destino) · `PR3→PR5` y `PR4→PR5` (el guard necesita el contador fiable y
la superficie de cuentas como salida).

### Safe-first merge order

`PR1` · `PR2`  →  `PR3`  →  `PR4`  →  `PR5`

- **PR1 y PR2** son backend, additive y **paralelizables** entre sí (subagente API).
- **PWA puede arrancar shells en paralelo** contra el contrato **ya congelado** de PR2 (stub),
  e integrar al mergear PR2 — distinto deployable, sin estado compartido (CLAUDE.md §subagentes).
- **PR5 va último**: es el único cambio de comportamiento (flip del flujo de borrado / dual-truth).

### PR1 — API · Read-model batched del contador (additive, riesgo bajo)

**Objetivo:** añadir `accountCount` a cada item de la lista de bancos con **una** query
agregada por página. Invariante #4 (stale-tolerant), #2 (sin error manual).

**Forma concreta (DBAL, una query por página tras obtener la página de bancos):**

```sql
SELECT ba.bank_id AS bank_id, COUNT(ba.id) AS cnt
FROM bank_account ba
WHERE ba.bank_id IN (:bankIds)   -- ids de banco de la página actual
GROUP BY ba.bank_id
```

- Resultado → `Map<bankId, int>`; bancos sin filas → `0` en el ensamblado del read-model.
- `executeQuery()` + `fetchAllAssociative()` (DBAL 4). Índice `IDX_53A23E0A11C8FB41` reutilizado.
- **Prohibido** `countByBankId()` en bucle por fila.
- **DTO:** el item de lista gana `accountCount: int` (serializer group del listado).

**Aserción de rendimiento (cierra la puerta al N+1):** test de integración/Behat — una página
de N bancos emite **exactamente 1** query agregada adicional (assert de query-count), **no** N.

### PR2 — API · Endpoint de cuentas por banco (additive, riesgo medio: PII + auth)

**Objetivo:** `GET /backoffice/banks/{id}/accounts`. Invariantes #2 (RFC 9457), #3 (PII IBAN).

**Endpoint shape (envelope cursor-only heredado):**

```
GET /backoffice/banks/{id}/accounts?cursor=<opaque>&limit=<n>
```
```json
{
  "items": [
    { "id": "uuid", "holder": "string", "iban": "ES9121000418450200051332",
      "alias": "string|null", "currency": "EUR", "status": "active" }
  ],
  "pagination": { "cursor": "<opaque|null>", "hasMorePages": true }
}
```

- **DTO read-model** `BankAccountListItem` (id, holder, iban, alias, currency, status). Sin mapping de escritura.
- **IBAN íntegro** bajo un serializer group dedicado (p. ej. `bank_account:read`); el masking
  es del cliente, **nunca** aquí (invariante #3).
- **Autorización** en capa Application (voter / `IsGranted`) — ruta **no** pública; declararlo en el PR.
- **Errores:** id mal formado → `400 invalid-uuid` vía `Uuid::ensure`; banco ausente → `404`.
  Ambos por el pipeline RFC 9457.
- **Auditoría:** emitir **evento de acceso** (quién/cuándo/bankId) al leer cuentas con IBAN —
  **no** el valor del IBAN; handler idempotente (patrón audit/Messenger).
- **Sin Mercure** (v1 estático, invariante #4).
- **Doc obligatoria:** actualizar `PRODUCTION_SECURITY_CHECKLIST.md` (exposición IBAN) y `api/docs/` + `docs/architecture-api.md` (endpoint nuevo).
- **Behat:** 200 con cuentas · `400 invalid-uuid` · `404` · paginación keyset · auth denegada.

### PR3 — PWA · Read context BankAccount + superficie de cuentas (riesgo medio)

**Objetivo:** nuevo contexto de lectura + ruta `/backoffice/banks/{id}/accounts`. Invariante #3.

- **Read context** `src/context/backoffice/bank-account/`: interfaz de dominio
  (`BankAccountsByBankFinder`), adaptador infra que consume el endpoint de PR2, binding
  Inversify en el container del bc, compuesto en el root. Inyectar **interfaz**, no concreto.
- **Ruta** `app/backoffice/banks/[id]/accounts/page.tsx` (Server Component, fetch vía DI);
  `AsyncBoundary` → `ProblemDisplay` + `CorrelationIdChip` en error.
- **Tabla de cuentas** (reusa table / `StatusBadge` / `truncated-cell` base): Holder
  (truncate+tooltip), **IBAN** (enmascarado por defecto + reveal toggle + `CopyButton` que
  copia íntegro), Alias, Currency, Status. Filas **no** navegan (v1).
- `safeHref` + `encodeURIComponent(id)` en cualquier navegación.
- **Tests:** Vitest (tabla, máscara/reveal/copy) · Playwright (carga, error boundary, copy).
- _(3.5 reveal single/multi y 3.6 popover/dialog NO se deciden aquí — viven en la UX spine.)_

### PR4 — PWA · Señales lista + detalle (additive, riesgo bajo-medio)

**Objetivo:** las dos proyecciones del contador. Invariante #1 (señal ≠ autoridad), #4.

- **Lista:** columna `accountCount` (~72px, derecha, header "ACCOUNTS", tras Status, oculta
  `<lg`, **no** ordenable). Clickable cuando `>0` → superficie de cuentas (`safeHref` +
  `encodeURIComponent`); `0` atenuado, **no** enlaza. La navegación es **affordance de UI**,
  no contrato de API.
- **Detalle:** campo "Associated accounts: N · View accounts"; `0` → "None".
- **Depende de** PR1 (payload con `accountCount`) + PR3 (destino de los enlaces).
- **Tests:** Vitest (render `0` vs `N`, clickability) · Playwright (navegación lista→cuentas).

### PR5 — PWA · Delete-guard + recovery de `bank-in-use` (riesgo alto — flip de comportamiento, va último)

**Objetivo:** el guard optimista y la salida de la carrera. Invariante #1 (dual-truth) es **load-bearing** aquí.

- **Guard:** Delete en lista (⋯) y detalle con `accountCount > 0` → **no** abre confirm ni
  dispara `DELETE`; abre popover neutro "Can't delete — N associated accounts" + "View accounts".
  `accountCount === 0` → confirm → `DELETE` (como hoy).
- **El guard NO bloquea preventivamente `DELETE`**: solo lo evita en el flujo normal. Si bajo
  carrera el `DELETE` sí sale y vuelve `409`, cae en el `mutation-error` persistente.
- **Recovery:** el `mutation-error` persistente del contrato base gana la acción "View accounts".
- **Tests:** Vitest (guard abre popover, no dispara DELETE; `0` → confirm normal) · Playwright
  (flujo bloqueado→"View accounts"; **carrera**: 409 → mutation-error con "View accounts").
- **Actualizar el contrato base** `ux-ERPify-2026-06-03` al promocionar (recovery de `bank-in-use`).

### Handoff

Doc **frozen-ready**. Siguiente paso natural: `bmad-create-epics-and-stories` para convertir
estos 5 PR-specs en stories ejecutables, o ir directo a implementación (PR1/PR2 en paralelo).
Los invariantes del kernel son la referencia de aceptación transversal de cada story.
