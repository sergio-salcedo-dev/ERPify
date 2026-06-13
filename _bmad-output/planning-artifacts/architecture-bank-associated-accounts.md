---
stepsCompleted: [1, 2, 3]
methodology: 'contract-first incremental addendum on existing system — classic steps 4–8 absorbed into the System Invariants kernel (decision kernel) + Implementation Decomposition (decision localization at PR-spec level)'
status: 'frozen-ready'
irReviewed: '2026-06-11'   # G-1 (envelope) + G-2 (repo compartido) cerrados — ver Cross-Epic Dependencies CE-1…CE-4
crossEpicDependsOn:
  - 'Epic 1 (keyset) Story 1.2/1.3 — repo BankAccount por composición + flip de envelope wire'
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
- **Paginación keyset cursor-only.** Se alinea al **envelope final del Epic 1** —
  `{hasNext, hasPrev, count?, links}` con params `after`/`before` (K6/FR6) — **no** al
  legacy `{cursor, hasMorePages}`. Un único contrato wire vivo: ver *Cross-Epic
  Dependencies* CE-1. Hereda el kernel keyset (PR1 `8bfb8b7` + engine PR2 `8b1d728`).
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
  **Pero `DoctrineBankAccountRepository` es superficie compartida con el Epic 1**, que lo
  está reescribiendo (herencia → composición, puerto `save()`, delegación al engine):
  esta feature construye **sobre** ese repositorio reestructurado, no sobre el heredado.
  Ver *Cross-Epic Dependencies* CE-2.
- **Stack.** Symfony 8 / Doctrine ORM 3 / DBAL 4 / PostgreSQL; Next 16 / React 19 /
  Inversify 8. Doctrine: sin `flush($entity)`, sin `fetchAll()`; usar
  `executeQuery()` / `fetchAllAssociative()`.
- **Hereda (no reinventa).** Envelope **final** del Epic 1 (`{hasNext, hasPrev, count?,
  links}` + `after`/`before`, CE-1) + kernel keyset (PR1 + engine PR2 mergeados);
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
  PWA, sin reach-ins desde/hacia `Bank`: el contador se sirve por el puerto de lectura
  `AccountCountsByBank` (CE-3), no por SQL desde `Bank`; puertos de lectura y escritura
  segregados (CE-4).

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

## Cross-Epic Dependencies (Epic 1 — keyset) y Boundary Rules

> **Capa de gobernanza inter-épica.** Estas reglas existen porque esta feature **no opera
> en aislamiento**: el Epic 1 (rediseño de paginación a keyset cursor-only) está **en
> vuelo sobre el mismo contrato wire y el mismo agregado `BankAccount`** que esta feature
> toca. A 2026-06-11 el Epic 1 tiene mergeados PR1 (kernel puro, `8bfb8b7`) y PR2 (engine
> off-wire, `8b1d728`); **pendiente el flip de contrato (Story 1.3/1.4)** que reemplaza el
> envelope global. Dejar esta intersección implícita convierte el addendum en deuda
> estructural que estalla en la primera integración real. CE-1…CE-4 son **vinculantes**
> para todos los PR-specs de abajo, a la par del kernel de invariantes.

### CE-1 — Un único contrato wire de paginación vivo (enforcement de AR11)

- El endpoint `GET /backoffice/banks/{id}/accounts` (PR2) se alinea al **envelope final
  del Epic 1** — `{hasNext, hasPrev, count?, links: {next, prev}}` con params
  `after`/`before` (keyset K6/FR6) — **no** al legacy `{cursor, hasMorePages}`.
- **Prohibida la coexistencia de dos contratos wire de paginación en runtime** —
  enforcement explícito de AR11 del Epic 1 (*"prohibida una segunda implementación de
  paginación"*). Esta feature **no** estrena un segundo contrato público de paginación.
- **Dependencia de secuencia:** PR2 **depende de** Epic 1 Story 1.3 (el flip que publica
  `PaginationMeta` v2 y pone `DoctrineSearchEngine` en el read-path). PR2 se rebasa sobre
  ese contrato; **no** lo anticipa con un envelope propio.
- **Escape hatch (solo si el flip del Epic 1 se retrasa y bloquea la feature):** un
  adapter temporal **único** que sirva el envelope final por delante del read-path
  todavía-legacy, **encapsulado** y **nunca expuesto como contrato público** — el cliente
  ve exclusivamente `{hasNext, hasPrev, count?, links}`. El adapter muere al aterrizar
  Story 1.3. Bajo ninguna circunstancia se expone `{cursor, hasMorePages}` en el endpoint
  de cuentas. El objetivo no es la forma, sino **eliminar el estado "doble contrato vivo"**.

### CE-2 — `DoctrineBankAccountRepository` es superficie compartida con el Epic 1

- El Epic 1 (Story 1.2/1.3) **ya reescribe** `DoctrineBankAccountRepository`: herencia →
  composición, puerto de dominio con `EntityManagerInterface` inyectado, `save()` sin
  flush implícito (FR12), delegación del read-path en `DoctrineSearchEngine`. Esta feature
  **no parte de cero** sobre `BankAccount`.
- El count read-model (PR1) y el endpoint de cuentas (PR2) se construyen **sobre** ese
  repositorio reestructurado, **no** sobre el `ServiceEntityRepository` heredado que el
  Epic 1 retira. Coordinar en secuencia (PR1/PR2 tras Story 1.2/1.3) o en worktree
  compartido para evitar colisión de merge.
- Dependencia **bidireccional**: registrar en `epics.md` (Story 1.3/1.4 del Epic 1) que el
  endpoint de cuentas debe entrar en el barrido del flip — hoy el Epic 1 no sabe que esta
  feature existe.

### CE-3 — El read-model del contador sale del contexto `Bank`

- El contador **no** se resuelve con SQL crudo desde la infraestructura de `Bank`. Se
  introduce un servicio de lectura del contexto `BankAccount` — **`AccountCountsByBank`**
  (puerto de dominio query-side; impl infra `DoctrineAccountCountsByBank`) — que ejecuta
  la query agregada batched y devuelve `Map<bankId, int>`.
- La ensambladura de la lista de `Bank` **consume ese puerto de lectura**; `Bank`
  **nunca** ejecuta SQL sobre la tabla `bank_account`. Elimina el reach-in cross-context
  implícito sin tocar el dominio de escritura (regla del repo: cross-context solo por
  servicios de Application publicados / eventos, jamás reach-in a otro contexto).

### CE-4 — Segregación read/write de puertos (API y PWA)

- El read context de cuentas (API y PWA) **inyecta exclusivamente puertos de lectura**:
  `AccountCountsByBank` (contador) y `BankAccountsByBankFinder` (listado). **Ninguna
  capacidad de escritura** (`save()` u otra mutación del agregado `BankAccount`) entra en
  el read context — aunque el Epic 1 abra `save()` en el repositorio, ese puerto **no** se
  cablea en las superficies de lectura de esta feature.
- Resultado: el read-model de cuentas queda **formalmente separado** del agregado de
  escritura; el `save()` del Epic 1 y las lecturas de esta feature no comparten punto de
  inyección.

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
la superficie de cuentas como salida). **Más la arista cross-épica `keyset.1.3 → PR1` y
`keyset.1.3 → PR2`** (CE-1/CE-2: el read-model del contador y el endpoint montan sobre el
repo `BankAccount` reestructurado y el envelope final del Epic 1).

### Safe-first merge order

`PR1` · `PR2`  →  `PR3`  →  `PR4`  →  `PR5`

- **Precondición cross-épica (CE-1/CE-2):** PR1 y PR2 aterrizan **sobre** el repo
  `BankAccount` por composición y el envelope final del Epic 1. Si Story 1.3 aún no está,
  aplica el escape hatch encapsulado de CE-1 — **nunca** un segundo contrato wire público.
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

- **Dueño del read-model: el contexto `BankAccount`, no `Bank` (CE-3).** La query vive en
  un servicio de lectura `AccountCountsByBank` (puerto de dominio query-side; impl
  `DoctrineAccountCountsByBank`). La ensambladura de la lista de `Bank` **consume ese
  puerto** — `Bank` **nunca** ejecuta SQL sobre `bank_account`.
- **Construye sobre el repo `BankAccount` reestructurado del Epic 1 (CE-2)**, no sobre el
  `ServiceEntityRepository` heredado.
- Resultado → `Map<bankId, int>`; bancos sin filas → `0` en el ensamblado del read-model.
- `executeQuery()` + `fetchAllAssociative()` (DBAL 4). Índice `IDX_53A23E0A11C8FB41` reutilizado.
- **Prohibido** `countByBankId()` en bucle por fila.
- **DTO:** el item de lista gana `accountCount: int` (serializer group del listado); el
  ensamblado inyecta **solo** el puerto de lectura `AccountCountsByBank` (CE-4).

**Aserción de rendimiento (cierra la puerta al N+1):** test de integración/Behat — una página
de N bancos emite **exactamente 1** query agregada adicional (assert de query-count), **no** N.

### PR2 — API · Endpoint de cuentas por banco (additive, riesgo medio: PII + auth)

**Objetivo:** `GET /backoffice/banks/{id}/accounts`. Invariantes #2 (RFC 9457), #3 (PII IBAN).

**Endpoint shape (envelope FINAL del Epic 1 — CE-1; jamás el legacy `{cursor, hasMorePages}`):**

```
GET /backoffice/banks/{id}/accounts?after=<opaque>|before=<opaque>&limit=<n>
```
```json
{
  "items": [
    { "id": "uuid", "holder": "string", "iban": "ES9121000418450200051332",
      "alias": "string|null", "currency": "EUR", "status": "active" }
  ],
  "pagination": {
    "hasNext": true, "hasPrev": false, "count": null,
    "links": { "next": "…?after=…", "prev": null }
  }
}
```

- **Depende de Epic 1 Story 1.3 (CE-1/CE-2):** servido por `DoctrineSearchEngine` +
  `PaginationMeta` v2; `after`/`before` mutuamente excluyentes (ambos → 422
  `validation-failed`); `links.next`/`links.prev` siempre presentes, `null` cuando no
  aplican; prohibido `skip_null_values`.

- **DTO read-model** `BankAccountListItem` (id, holder, iban, alias, currency, status).
  Sin mapping de escritura; servido por el puerto de lectura `BankAccountsByBankFinder` —
  el read context **no** cablea ninguna capacidad de escritura (`save()`) aunque el Epic 1
  la abra en el repositorio (CE-4).
- **IBAN íntegro** bajo un serializer group dedicado (p. ej. `bank_account:read`); el masking
  es del cliente, **nunca** aquí (invariante #3).
- **Autorización** en capa Application (voter / `IsGranted`) — ruta **no** pública; declararlo en el PR.
- **Errores:** id mal formado → `400 invalid-uuid` vía `Uuid::ensure`; banco ausente → `404`.
  Ambos por el pipeline RFC 9457.
- **Auditoría:** emitir un **evento de acceso** (quién/cuándo/bankId) al leer cuentas con IBAN —
  **no** el valor del IBAN; handler idempotente (patrón audit/Messenger). **Clasificado como
  evento de auditoría/observabilidad, no de negocio** — único efecto admitido en el read-path,
  sin sentar precedente de read-models que emitan eventos de dominio.
- **Sin Mercure** (v1 estático, invariante #4).
- **Doc obligatoria:** actualizar `PRODUCTION_SECURITY_CHECKLIST.md` (exposición IBAN) y `api/docs/` + `docs/architecture-api.md` (endpoint nuevo).
- **Behat:** 200 con cuentas · `400 invalid-uuid` · `404` · paginación keyset · auth denegada.

### PR3 — PWA · Read context BankAccount + superficie de cuentas (riesgo medio)

**Objetivo:** nuevo contexto de lectura + ruta `/backoffice/banks/{id}/accounts`. Invariante #3.

- **Read context** `src/context/backoffice/bank-account/`: interfaz de dominio
  (`BankAccountsByBankFinder`), adaptador infra que consume el endpoint de PR2, binding
  Inversify en el container del bc, compuesto en el root. Inyectar **interfaz**, no concreto.
  **Solo puertos de lectura** en el container del read context — cero capacidad de
  escritura sobre `BankAccount` (CE-4).
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

Doc **frozen-ready** (IR 2026-06-11 superado: G-1 envelope y G-2 repositorio compartido
cerrados vía *Cross-Epic Dependencies* CE-1…CE-4; el reach-in del contador queda extraído a
`AccountCountsByBank`). Siguiente paso: `bmad-create-epics-and-stories` para convertir estos
5 PR-specs en stories ejecutables. Las stories heredan, además del kernel de invariantes,
las reglas CE-1…CE-4 como aceptación transversal — en particular la **precondición de
secuencia con Epic 1 Story 1.2/1.3** y el **único contrato wire vivo** (sin un segundo
contrato de paginación público).
