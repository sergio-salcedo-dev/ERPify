---
reportKind: 'implementation-readiness'
project_name: 'ERPify'
user_name: 'Sergio'
date: '2026-06-11'
reviewer: 'Winston (System Architect)'
scope: 'Feature + baseline alignment'
feature: 'Cuentas asociadas (Bank ↔ BankAccount)'
relatedPR: 213
branch: 'feat/backoffice-bank-associated-accounts-dr9j'
focusAxes:
  - 'Consistencia de invariantes (dual-truth + 409 + read-model)'
  - 'Acotamiento del read-model de BankAccount (¿filtra dominio de escritura?)'
inputDocuments:
  - '_bmad-output/planning-artifacts/architecture-bank-associated-accounts.md (PR #213)'
  - '_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-06-10/EXPERIENCE.md (PR #213)'
  - '_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-06-10/DESIGN.md (PR #213)'
  - '_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-06-10/.decision-log.md (PR #213)'
  - '_bmad-output/planning-artifacts/architecture.md (baseline — track filtros)'
  - '_bmad-output/planning-artifacts/architecture-keyset-pagination.md (baseline — ADR keyset)'
  - '_bmad-output/planning-artifacts/epics.md (baseline — Epic 1 keyset)'
verdict: 'GO — los 2 bloqueantes (G-1 envelope, G-2 repo compartido) cerrados en el addendum vía R-1+R-2 (CE-1…CE-4) el 2026-06-11; listo para bmad-create-epics-and-stories'
verdictHistory:
  - '2026-06-11 — CONDITIONAL GO (hallazgo inicial: G-1 + G-2 abiertos)'
  - '2026-06-11 — GO (R-1+R-2 aplicados en el worktree: CE-1…CE-4, contador extraído a AccountCountsByBank)'
stepsCompleted: [1, 2, 3, 4, 5, 6]
---

# Implementation Readiness Assessment Report

**Date:** 2026-06-11
**Project:** ERPify
**Feature:** Cuentas asociadas (Bank ↔ BankAccount) — PR [#213](https://github.com/sergio-salcedo-dev/ERPify/pull/213)
**Reviewer:** Winston (System Architect)
**Scope:** Feature + baseline alignment
**Focus (dirigido por Sergio):** (A) consistencia de invariantes dual-truth + 409 + read-model · (B) acotamiento del read-model de `BankAccount`

---

> **Resolución (2026-06-11, post R-1+R-2).** Tras el hallazgo inicial, Sergio aprobó
> aplicar R-1+R-2 en el worktree. El addendum `architecture-bank-associated-accounts.md`
> **ya cierra los dos bloqueantes**: nueva sección *Cross-Epic Dependencies* **CE-1…CE-4**
> (un único contrato wire vivo + dependencia de secuencia con Epic 1 Story 1.2/1.3 +
> segregación R/W de puertos), envelope del endpoint de cuentas **alineado al final del
> Epic 1** (`{hasNext, hasPrev, count?, links}` + `after`/`before`), y el contador
> **extraído** del contexto `Bank` a un servicio de lectura `AccountCountsByBank` (CE-3).
> **Veredicto efectivo: GO.** Las secciones §5/§7 de abajo se conservan como *registro del
> hallazgo*; su estado actual está marcado **[RESUELTO]** donde aplica. Pendientes
> no-bloqueantes que se llevan a las stories: A-1 (métrica del 409 bajo guard), C-1 (fuente
> del count del guard), U-1 (cierre del open item de paginación en la UX spine).

## 0. Veredicto — CONDITIONAL GO → **GO** (post R-1+R-2)

Los artefactos de PR #213 (UX spines + addendum de arquitectura) son **densos, internamente coherentes y trazables**. La feature está **lista para `bmad-create-epics-and-stories`** salvo **dos condiciones bloqueantes de alineación cross-épica** con el Epic 1 (keyset) que está **en vuelo sobre el mismo agregado y el mismo contrato wire**. No son defectos del diseño de la feature en sí: son **costuras entre dos épicas concurrentes** que el addendum no reconoce porque se escribió como si el keyset fuese solo "PR1 mergeado".

| | |
|---|---|
| **Calidad interna del addendum + UX** | 🟢 Alta — kernel de invariantes explícito, DAG de PRs, decomposición safe-first, trazabilidad UX→FR→PR-spec |
| **Consistencia de invariantes (foco A)** | 🟢 Sólida — dual-truth/409/consistency-window coherentes punta a punta · 1 hueco de observabilidad |
| **Acotamiento read-model (foco B)** | 🟡 Mayormente limpio — 2 puntos de erosión a explicitar (reach-in SQL del contador · evento en read-path) |
| **Alineación con baseline (keyset)** | 🔴 2 bloqueantes — colisión de envelope + colisión sobre `DoctrineBankAccountRepository` |
| **Cobertura de requisitos** | 🟢 FR1–FR6 + NFRs → 5 PR-specs sin huérfanos |
| **Epics/Stories** | ⚪ N/A por diseño — diferidos (handoff explícito); este IR es la puerta previa |

**Condición de salida:** resolver **G-1** (envelope) y **G-2** (repositorio compartido) antes de redactar stories. Lo demás es refinamiento no bloqueante.

---

## 1. Inventario de documentos (Step 1)

| Tipo | Documento | Ubicación | Rol en el IR |
|---|---|---|---|
| Arquitectura (addendum) | `architecture-bank-associated-accounts.md` | PR #213 (rama) | **Bajo revisión** |
| UX — Experience | `ux-designs/ux-ERPify-2026-06-10/EXPERIENCE.md` | PR #213 (rama) | **Bajo revisión** (fuente de requisitos, sin PRD) |
| UX — Design | `ux-designs/ux-ERPify-2026-06-10/DESIGN.md` | PR #213 (rama) | **Bajo revisión** |
| UX — Decisiones | `ux-designs/ux-ERPify-2026-06-10/.decision-log.md` | PR #213 (rama) | **Bajo revisión** (Entry 1–3) |
| UX — Base import | `imports/ux-ERPify-2026-06-03-{DESIGN,EXPERIENCE}.md` | PR #213 (rama) | Contrato base heredado |
| Arquitectura (baseline) | `architecture.md` | `main` | Alineación |
| Arquitectura (baseline) | `architecture-keyset-pagination.md` | `main` | **Alineación crítica** |
| Épicas (baseline) | `epics.md` | `main` | Alineación |

**Issues de inventario (resueltos):**
- ⚠️ **No hay PRD** — y el repo no tiene convención de PRD por feature (precedente del ADR de filtros). La `EXPERIENCE.md` actúa de contrato de requisitos; el addendum ya destiló FR1–FR6 de ella. *Adaptación aceptada del workflow PRD-first.*
- ✅ `architecture.md` + `architecture-*.md` **no son duplicados** — patrón de addendum acotado (kernel + localización por PR). Componen.
- ✅ `ux-designs/` vacío en `main`, poblado en la rama — esperado (la base 06-03 se podó y se re-importa aquí).
- ⚪ **Sin epics/stories de la feature** — **esperado, no defecto**: el addendum hace handoff explícito a `bmad-create-epics-and-stories` (línea 327).

---

## 2. Requisitos y trazabilidad (Steps 2–3)

Sin PRD; la fuente es `EXPERIENCE.md` + `.decision-log` Entry 1–3, ya destilada por el addendum en FR1–FR6 y NFRs. La decomposición de implementación (PR1–PR5) es el sustituto de "epics/stories" que este IR valida por cobertura y calidad.

### 2.1 Mapa de cobertura FR → UX → PR-spec

| FR (addendum) | Origen UX | PR-spec | Invariante(s) | Cobertura |
|---|---|---|---|---|
| FR1 — señal contador en lista | EXPERIENCE IA + Entry 3.3 | PR1 (dato) + PR4 (proyección lista) | #1 señal≠autoridad, #4 | ✅ |
| FR2 — campo en detalle | EXPERIENCE Component Patterns | PR4 (proyección detalle) | #1, #4 | ✅ |
| FR3 — superficie cuentas (NUEVA) | Entry 2 Q3 + Entry 3.1 | PR2 (endpoint) + PR3 (superficie PWA) | #2, #3 | ✅ |
| FR4 — delete-guard optimista | EXPERIENCE Guard + Entry 3.5 | PR5 | **#1 load-bearing** | ✅ |
| FR5 — IBAN dos planos | Entry 2 Q2 + Entry 3.2 | PR2 (payload íntegro) + PR3 (máscara) | #3 | ✅ |
| FR6 — recovery `bank-in-use` bajo carrera | EXPERIENCE Flujo 3′ + Actualización base | PR5 (mutation-error + "View accounts") | #1, #4 | ✅ |

**Sin FRs huérfanos; sin PR-specs sin FR.** Los NFRs (DDD read-side, anti-N+1, consistency-window, RFC 9457, keyset, PII, dual-truth+Sentry, realtime diferido) están todos anclados a un PR-spec o al kernel. Trazabilidad **fuerte**.

### 2.2 Calidad de los PR-specs (Step 5, adaptado)

Cada PR-spec trae forma concreta (SQL / DTO / shape de endpoint / componentes), criterios de test (incluida la **aserción de query-count anti-N+1** en PR1) y riesgo etiquetado. El **orden safe-first** (backend additive → superficie → señales → flip de comportamiento) es correcto: el único cambio de comportamiento (guard, PR5) va último, detrás de los datos de los que depende. **Calidad de descomposición: alta.** Lo que falta no está *dentro* de los PR-specs sino en sus *aristas con el Epic 1* (§5).

---

## 3. FOCO A — Consistencia de invariantes (dual-truth + 409 + read-model)

> Veredicto del eje: 🟢 **Sólida y bien trazada.** El nudo difícil (la carrera lectura→borrado) está formalizado, no escondido. Un único hueco: observabilidad de la tasa de 409 bajo guard.

### 3.1 Dual-truth (#1) — coherente punta a punta

La cadena está bien cerrada y es consistente entre PR-specs:

- **Backend = única autoridad.** `DELETE /banks/{id}` puede devolver `409 bank-in-use` desde cualquier estado (kernel #1, líneas 187–190). ✅
- **Guard = fast-path optimista, no autoritativo.** PR5 (líneas 311–319): el guard **no bloquea preventivamente** el `DELETE`; solo lo evita en el flujo normal. `accountCount === 0` → confirm normal. ✅ Coherente con FR4 (líneas 62–65) que insiste en que el `DELETE` "sigue siendo posible y válido desde cualquier estado".
- **Carrera → recovery.** Si el `DELETE` sale bajo carrera y vuelve 409, cae en el `mutation-error` persistente que **ahora ofrece "View accounts"** (FR6 + PR5 recovery + EXPERIENCE Flujo 3′ línea 92). ✅ El callejón sin salida del contrato base queda resuelto sin introducir consistencia fuerte.

**No hay contradicción entre el guard (UI) y la autoridad (backend).** El *Consistency Window* (líneas 136–149) declara explícitamente que el guard *reduce la frecuencia del 409, no su posibilidad* — exactamente la semántica correcta para un guard optimista. Esto es una **fortaleza**: la mayoría de los diseños de "guard de borrado" caen en la trampa de tratar la lectura como invariante; éste no.

### 3.2 409 + Sentry (#2) — coherente, con un hueco de observabilidad

- El `409 bank-in-use` es 4xx **esperado** y se mantiene en el drop de `before_send` (`f7b0d5e`, `ERPIFY-API-DEV-6`) — kernel #2 (líneas 191–193). ✅ Consistente con el origen del feature (el propio Sentry que lo disparó).
- El audit "access event" de PR2 (líneas 277–278) es ortogonal al tracking de error: se emite en la **lectura** de cuentas, no en el 409. Sin colisión de canales. ✅

🟡 **Hueco A-1 (no bloqueante) — ceguera sobre la tasa de 409 bajo guard.** El kernel #4 declina todo mecanismo de sincronización; el guard es la *única* mitigación de la carrera; y el 409 se **descarta de Sentry**. Resultado: si el guard falla más de lo previsto (más altas concurrentes de las esperadas), **el sistema no tiene forma de verlo** — ni Sentry (drop) ni métrica. El Epic 1 trata la observabilidad como *load-bearing* (métricas `invalid_cursor_count{cause}`, runbook del pico post-deploy — `epics.md:315–316`). El addendum **no hereda esa disciplina** para el único camino genuinamente racy de la feature. → Recomendación R-3.

### 3.3 Read-model (#4 staleness) — consistente, con una decisión implícita

El modelo de consistencia es uniforme: `accountCount` y estado del guard son *tolerantes a staleness*, sin polling/invalidación/eventing, backend como punto de reconciliación. PR1 (count batched), PR4 (señales) y PR5 (guard) honran #4 sin fisuras.

🟡 **Decisión implícita C-1 (refinamiento) — ¿qué contador alimenta el guard?** `.decision-log` Entry 2 (línea 48) dice que el guard "reads only the count (cheap, already `countByBankId()`)", mientras que la señal de lista usa el **GROUP BY batched** de PR1. PR5 solo dice "el guard lee `accountCount`" sin fijar la fuente. Bajo #4 ambas opciones (confiar en el count stale del payload de lista/detalle vs un `countByBankId()` fresco) son **válidas y consistentes** — pero la elección cambia la frecuencia del 409 y debe ser explícita en la story de PR5 (no re-fetch ⇒ más staleness ⇒ más 409; re-fetch ⇒ ventana más corta pero coste por click). No es contradicción; es una palanca de diseño sin fijar.

---

## 4. FOCO B — ¿El read-model de `BankAccount` está bien acotado, o filtra dominio de escritura?

> Veredicto del eje: 🟡 **Mayormente limpio y conscientemente read-side**, con **dos puntos de erosión** que hoy son implícitos y conviene nombrar/poseer antes de implementar.

### 4.1 Lo que el addendum hace bien (acotamiento correcto)

- **Framing explícito query-side:** "`BankAccount` se incorpora como **read bounded context (query-side), no como agregado de escritura nuevo** … read-model expansion, no un BC completo" (líneas 76–79). Esto es exactamente la disciplina correcta para evitar over-architecture.
- **DTO read-model sin mapping de escritura:** `BankAccountListItem` (PR2, línea 271) — "Sin mapping de escritura". ✅
- **Finder en PWA, no repositorio de escritura:** `BankAccountsByBankFinder` (PR3, línea 288) — interfaz de dominio query-side, inyectada, no concreta. ✅
- **Las cuentas son dueñas de su superficie:** no se embeben filas en `Bank` ni en su detalle (línea 79). ✅

### 4.2 Punto de erosión B-1 (a explicitar) — el contador es un reach-in SQL de Bank dentro de `bank_account`

PR1 (líneas 237–247) ejecuta `SELECT ba.bank_id, COUNT(ba.id) FROM bank_account ba WHERE ba.bank_id IN (:bankIds) GROUP BY ba.bank_id` y **adjunta `accountCount` al item de lista de Bank**. Es decir: **el read-model de Bank lee directamente la tabla `bank_account`** por SQL crudo. Tensión con dos reglas del repo:

- `project-context.md`: *"cross-context calls go through published Application services or domain events — never reach into another context's Domain/Infrastructure"* y *"Bounded contexts are real boundaries"*.
- El propio addendum (línea 79): "las cuentas son dueñas de su superficie".

Hay una contradicción suave: el **scalar count** sí se embebe en `Bank` (línea 247: "el item de lista gana `accountCount: int`"), y el join lo arma la infraestructura de Bank. Pragmáticamente un *count batched* es un join de read-model común y aceptable (no es over-engineering exigir un BC completo). **Pero la costura debe ser explícita y poseída:** un servicio de lectura nombrado del contexto `BankAccount` (p. ej. `AccountCountsByBank::for(bankIds): Map<bankId,int>`) que la ensambladura de la lista de Bank *invoca*, en vez de que la infraestructura de Bank consulte `bank_account` ad-hoc. Así el acoplamiento de lectura cross-context queda **visible y de un solo dueño**, y el esquema de `bank_account` no se consulta desde la infra de Bank. → Recomendación R-2. (Rule of Three: no inventes un BC; **sí** nombra el seam.)

### 4.3 Punto de erosión B-2 (aceptar como excepción, no como precedente) — evento de dominio en read-path

PR2 (líneas 277–278) emite un **"access event" (quién/cuándo/bankId)** al leer cuentas con IBAN. Emitir un *evento de dominio* desde un camino de **lectura** es un leve olor CQRS (las lecturas, idealmente, no producen efectos de escritura). Aquí está **justificado** — auditoría de acceso a PII es un caso de uso reconocido y el kernel #3 lo exige — pero conviene clasificarlo explícitamente como **evento de auditoría/observabilidad, no de negocio**, idempotente (patrón audit/Messenger), para que **no siente precedente** de que los read-models de esta feature pueden emitir eventos de dominio. No es leak de escritura del agregado `BankAccount` (no muta estado), pero está en la frontera y merece la etiqueta. → nota en la story de PR2.

### 4.4 No-leaks confirmados

- `status: active` expuesto en el endpoint es una **proyección de lectura**, no propiedad de escritura del agregado mutada desde el read context. ✅
- Ningún PR-spec añade setters, mapping de escritura ni mutación de `BankAccount`. ✅

**Conclusión del eje B:** el read-model **no filtra dominio de escritura de forma sustantiva**. Lo que filtra es **acoplamiento de lectura cross-context implícito** (B-1) y un **efecto de read-path sin etiquetar** (B-2). Ambos se cierran nombrando seams, no rediseñando.

---

## 5. Alineación con baseline — Epic 1 (keyset) — 🔴 2 BLOQUEANTES

> El addendum trata el keyset como "PR1 mergeado" (líneas 92, 120) y hereda su estado **pre-flip**. Pero el Epic 1 está **en vuelo** (mergeado: PR1 kernel `8bfb8b7`, PR2 engine `8b1d728`; **pendiente: el flip de contrato PR3 = Stories 1.3–1.4**) y reescribe **exactamente** el mismo envelope y el mismo repositorio que esta feature toca. `epics.md` no menciona "cuentas asociadas" en absoluto, y el addendum no menciona el Epic 1 en su DAG. Las dos costuras:

### 5.1 🔴 G-1 (BLOQUEANTE) — colisión de envelope de paginación

| Fuente | Envelope | Estado |
|---|---|---|
| `architecture.md:50` (filtros) | `{items, pagination:{cursor, hasMorePages}}` — "no cambia" | **pre-flip (vivo hoy)** |
| `architecture-keyset-pagination.md` FR6/K6 (`:77–80, :442`) | `{hasNext, hasPrev, count?, links:{next, prev}}` + `after`/`before` | **post-flip (Story 1.3, pendiente)** |
| **PR #213 addendum** PR2 (`:91, :120, :256–269`) | `{items, pagination:{cursor, hasMorePages}}` y lo llama *"keyset cursor-only"* | hereda el **pre-flip** |

El addendum **hereda el envelope legacy y lo etiqueta con el vocabulario del nuevo**. Dos consecuencias:

1. Si el endpoint de cuentas (PR2) emite `{cursor, hasMorePages}` y luego el flip del Epic 1 (Story 1.3) cambia el contrato global a `{hasNext, hasPrev, count?, links}`, el endpoint nuevo o bien **se queda fuera del flip** (dos envelopes de paginación coexistiendo — viola `epics.md` AR11/K6: *"prohibida una segunda implementación de paginación"* + principio de kernel único) o bien **necesita una segunda migración inmediata** apenas nacido.
2. La UX (`EXPERIENCE.md:40, :108`) dejó la paginación de la tabla de cuentas como `[ASSUMPTION]` "límite simple ahora, keyset después" — el addendum saltó a envelope cursor, pero al **cuál** de los dos cursores (legacy `cursor` vs keyset `after`/`before`) no lo reconcilia.

**Condición de salida:** decidir explícitamente, *antes* de redactar stories, sobre qué contrato nace el endpoint de cuentas:
- **(a) Nace post-flip** (`{hasNext, hasPrev, count?, links}`, `after`/`before`) ⇒ PR2 **depende de** Story 1.3 del Epic 1; añadir esa arista al DAG. *Recomendado* — un solo contrato wire en todo ERPify.
- **(b) Nace pre-flip** (`{cursor, hasMorePages}`) ⇒ aceptar conscientemente que el flip del Epic 1 deberá **incluir el endpoint de cuentas** en su barrido, y anotarlo como dependencia inversa en `epics.md` Story 1.3/1.4.

Cualquiera vale; **lo no aceptable es dejarlo implícito** — es la receta para dos contratos de paginación en producción.

### 5.2 🔴 G-2 (BLOQUEANTE) — `DoctrineBankAccountRepository` lo reescriben las dos épicas a la vez

El addendum afirma "la API hoy expone solo `countByBankId()`" (línea 116) — cierto **hoy**, pero el Epic 1 ya tiene en su alcance la reescritura de ese mismo repositorio:

- `epics.md:195–199` (Story 1.2) y `:239–243` (Story 1.3): `DoctrineBankAccountRepository` pasa de herencia a **composición**, implementa solo su puerto de dominio con `EntityManagerInterface` inyectado, **expone `save()` sin flush implícito** (FR12) y **delega el read-path en `DoctrineSearchEngine`**.

Es decir, el `BankAccount` ya gana un **puerto de escritura (`save()`)** y un motor de lectura keyset por la vía del Epic 1, *mientras* esta feature añade el count read-model (PR1) y el endpoint de lectura (PR2) sobre el mismo agregado. Riesgos:

1. **Colisión de merge** sobre los mismos ficheros de repositorio/persistencia de `BankAccount`.
2. **Dependencia oculta:** si el endpoint de cuentas (PR2) se construye sobre `DoctrineSearchEngine` (lo cual es lo coherente con G-1 opción (a)), entonces PR2 **depende de** que Story 1.2/1.3 aterricen primero. El DAG del addendum (líneas 211–223) **no tiene esa arista**.
3. **Pregunta de frontera (cruza con B-1):** el `save()` que el Epic 1 abre en el puerto de `BankAccount` es write-side; el read context de esta feature debe **inyectar solo el puerto de lectura** (`BankAccountsByBankFinder`), nunca el puerto con `save()`. Conviene declararlo explícitamente para que el read context no termine con acceso de escritura "porque estaba en el mismo repositorio".

**Condición de salida:** secuenciar contra el Epic 1 (PR2 de la feature *después* de Story 1.2/1.3, o coordinar en worktree) y declarar la segregación de puertos lectura/escritura de `BankAccount`.

### 5.3 Reclamos de herencia verificados (sanos)

Para ser justos con el addendum, sus demás afirmaciones de herencia **se verifican**: `Uuid::ensure` / RFC 9457 NFR26 (`architecture.md:52,66`), `PaginationMode` LIGHT/DETAILED conservado (`keyset:67–68,241`), pipeline de errores, kernel keyset PR1. La inconsistencia está **localizada en el envelope y en el repositorio compartido** — no es una herencia fantasma generalizada.

---

## 6. Alineación UX ↔ Arquitectura (Step 4)

🟢 **Fuerte.** El addendum trazó 1:1 las decisiones del `.decision-log` Entry 1–3 a FR1–FR6. Las 6 confirmaciones de Sergio (Entry 3) están reflejadas. Micro-divergencias no bloqueantes:

- **U-1 — paginación de la tabla de cuentas:** UX la dejó abierta ("límite simple ahora", `EXPERIENCE.md:40,108`); el addendum especificó envelope cursor (PR2). El addendum **resolvió** un open item de UX — bien — pero la resolución queda atrapada en G-1. Cerrar el loop: actualizar el open item de UX una vez se decida G-1.
- **U-2 — alcance fuera-de-addendum bien marcado:** reveal single/multi-IBAN (3.5) y popover-vs-dialog (3.6) se quedan en la UX spine (addendum líneas 39–41, 297). Frontera correcta: son estado React, sin impacto en BC/dominio/API. ✅
- **U-3 — `[ASSUMPTION]` residuales** (color del `0`, wording del popover, un-solo-IBAN-revelado, i18n): micro-detalles, no bloquean handoff. Que las stories los hereden como decisiones de implementación.

---

## 7. Registro de gaps y riesgos

| ID | Severidad | Eje | Hallazgo | Acción |
|---|---|---|---|---|
| **G-1** | ✅ **[RESUELTO]** | Baseline | Envelope de cuentas (`{cursor,hasMorePages}`) colisiona con el flip keyset (`{hasNext,hasPrev,count?,links}`) en vuelo | **Cerrado:** CE-1 — endpoint alineado al envelope final + escape hatch encapsulado; arista `keyset.1.3→PR2` en el DAG |
| **G-2** | ✅ **[RESUELTO]** | Baseline + B | `DoctrineBankAccountRepository` reescrito por Epic 1 (Story 1.2/1.3) y por esta feature a la vez | **Cerrado:** CE-2 — PR1/PR2 sobre el repo reestructurado, dependencia bidireccional registrada; CE-4 segrega puertos R/W |
| **B-1** | ✅ **[RESUELTO]** | Read-model | Contador = reach-in SQL de Bank dentro de `bank_account` (acoplamiento cross-context implícito) | **Cerrado:** CE-3 — contador extraído a `AccountCountsByBank`; `Bank` no consulta `bank_account` |
| **A-1** | 🟡 Menor | Invariantes | 409 bajo guard: descartado de Sentry y sin métrica ⇒ ceguera de la tasa de fallo del guard | Métrica `bank_delete_conflict_count` + heredar disciplina de observabilidad del Epic 1 (§3.2) |
| **C-1** | 🟡 Menor | Invariantes | Fuente del count del guard (stale del payload vs `countByBankId()` fresco) sin fijar | Fijar en la story de PR5 (§3.3) |
| **B-2** | ✅ **[RESUELTO]** | Read-model | Evento en read-path (audit access) — olor CQRS aceptable | **Cerrado:** PR2 lo etiqueta "evento de auditoría/observabilidad, no de negocio", idempotente — sin precedente |
| **U-1** | 🟢 Nota | UX↔Arch | Open item de paginación de cuentas resuelto por arch pero atrapado en G-1 | Cerrar loop tras G-1 (§6) |

---

## 8. Recomendaciones (priorizadas)

1. **R-1 (cierra G-1+G-2): añadir una sección "Dependencias cross-épica (Epic 1 keyset)" al addendum** antes de generar stories. Debe fijar: (a) el endpoint de cuentas nace **post-flip** sobre `{hasNext,hasPrev,count?,links}` + `after`/`before` (recomendado), (b) PR2 depende de Story 1.2/1.3, (c) el read context inyecta solo el puerto de lectura de `BankAccount`. Actualizar el DAG (líneas 211–223) con las aristas `keyset.1.3 → feature.PR2`.
2. **R-2 (cierra B-1): nombrar el seam de lectura del contador** — `AccountCountsByBank` como servicio de lectura del contexto `BankAccount` que la ensambladura de la lista de Bank invoca; prohibir el `SELECT … FROM bank_account` desde la infra de Bank. Mantener el batched GROUP BY *dentro* de ese servicio.
3. **R-3 (cierra A-1): heredar la disciplina de observabilidad del Epic 1** — una métrica de tasa de `409 bank-in-use` (la feature *baja* su frecuencia con el guard; sin métrica no se sabe si el guard cumple). Runbook de una línea.
4. **R-4 (refinamientos): fijar C-1 en la story de PR5, etiquetar B-2 en la story de PR2, cerrar U-1 en la UX spine.** No bloquean.
5. **R-5 (proceso): registrar la decisión de G-1/G-2 también en `epics.md`** (Story 1.3/1.4 del Epic 1) — la dependencia es bidireccional y hoy el Epic 1 no sabe que existe esta feature.

---

## 9. Conclusión

PR #213 es un paquete de planificación **de alta calidad**: invariantes explícitos, decomposición safe-first verificable, trazabilidad UX→FR→PR-spec sin huérfanos, y un manejo del nudo dual-truth/carrera/409 que es **mejor que el típico** (acepta la staleness en vez de fingir invariantes en la UI). En tus dos ejes de foco: **(A) los invariantes son consistentes** punta a punta (un solo hueco de observabilidad), y **(B) el read-model está conscientemente acotado al query-side** sin leak sustantivo de escritura — con dos costuras a *nombrar* (reach-in del contador, evento de read-path), no a rediseñar.

El bloqueo no está *dentro* de la feature: está en sus **dos aristas con el Epic 1 keyset en vuelo** (envelope + repositorio compartido), que el addendum no ve porque se escribió como si el keyset ya estuviera cerrado. Cierra **G-1 y G-2** — una sección de dependencias cross-épica y dos aristas en el DAG — y la feature está **lista para `bmad-create-epics-and-stories`**.

**Veredicto: CONDITIONAL GO.**

---

_Generado por el workflow `bmad-check-implementation-readiness` (Winston / System Architect). Scope: Feature + baseline alignment. Foco dirigido: invariantes dual-truth+409+read-model · acotamiento read-model BankAccount._
