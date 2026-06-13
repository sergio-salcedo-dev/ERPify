---
name: ERPify Backoffice — Cuentas asociadas (Bank ↔ BankAccount)
status: draft
sources:
  - .decision-log.md (Entry 1–3) — triage Sentry ERPIFY-API-DEV-6 + decisiones de Sergio (2026-06-10)
  - imports/ux-ERPify-2026-06-03-EXPERIENCE.md — contrato base de listas de entidades (se hereda)
  - docs/project-context.md
design_ref: DESIGN.md
updated: 2026-06-10
---

# Cuentas asociadas — Experience Spine (delta)

> **Hereda** el contrato base de listas de entidades [`ux-ERPify-2026-06-03`](imports/ux-ERPify-2026-06-03-EXPERIENCE.md). Esta spine especifica **solo el delta conductual** de "cuentas asociadas" sobre la lista/detalle de bancos, más una superficie nueva (cuentas de un banco). Las spines ganan en conflicto con cualquier mock. Las 6 asunciones principales fueron **confirmadas por Sergio (2026-06-10)** — ver `.decision-log` Entry 3; quedan solo micro-detalles `[ASSUMPTION]`.

## Por qué existe

`DELETE /banks/{id}` sobre un banco con cuentas devuelve 409 `bank-in-use` (Sentry `ERPIFY-API-DEV-6`). El contrato base ya diseñó ese rechazo (Flujo 3, Lucía) pero lo dejó **sin acción de recuperación**: *"la recuperación vive fuera (las cuentas asociadas)"*. Esta feature **construye esa recuperación** — ver las cuentas que bloquean — y la adelanta a un **guard** que evita disparar el 409.

## Foundation

Sin cambios sobre el base: web desktop-first responsive, Next 16 + React 19, sistema UI Shadcn + `erpify/` (`DataTable`, `StatusBadge`, `CopyButton`, `EmptyState`, `ProblemDisplay`). `DESIGN.md` es la referencia visual. **Restricción de arquitectura:** `BankAccount` es un contexto/agregado separado; la API hoy solo expone `countByBankId()` — el contador y la nueva lista de cuentas requieren read-models nuevos (handoff a Winston / `bmad-create-architecture`).

## Information Architecture (delta)

| Superficie | Se llega desde | Propósito | Estado |
|---|---|---|---|
| **Lista de bancos** | (existente) | + señal de **contador de cuentas** por fila (escaneo + antesala del guard) | delta |
| **Detalle de banco** | (existente) | + campo **"Associated accounts: N · View accounts"**; el Delete pasa por el guard | delta |
| **Cuentas de un banco** | Contador (lista) · "View accounts" (detalle) · guard de borrado | **NUEVA** — hogar de las cuentas: holder, IBAN (enmascarado + revelar + copiar), alias, divisa, estado | nueva — ruta `/backoffice/banks/{id}/accounts` (decidida) |

**Cierre de IA:** el contador (lista) y el campo (detalle) son señales; ambos **enlazan** a la superficie de cuentas, que es el hogar canónico de las cuentas de ese banco. Q3 = *solo contador + enlace*: ni la lista ni el detalle embeben filas de cuentas — eso evita el coste/duplicación y mantiene a `BankAccount` dueño de su propia superficie (DDD). Una página de **detalle de cuenta** individual queda **fuera de alcance** v1 (las filas de cuenta no navegan a ningún sitio en v1).

## Component Patterns (delta — conducta; lo visual en `DESIGN.md`)

| Componente | Uso | Reglas conductuales |
|---|---|---|
| **Contador de cuentas** (lista) | Columna/celda por fila de banco | Numérico, derecha. **Click → superficie de cuentas** de ese banco (`safeHref` + `encodeURIComponent` del id). `0` se muestra atenuado y **no** enlaza. **No ordenable en v1** (ordenar por agregado se difiere). No roba el papel de la columna Name. |
| **Campo "Associated accounts"** (detalle) | Región meta (dl/dd) | `N` + enlace "View accounts"; si `0` → "None" sin enlace. Vive en la jerarquía de info del detalle, no inline con el H1. |
| **Tabla de cuentas** (superficie nueva) | Lista de cuentas de un banco | Reusa `{components.table}` / `table-row` / `StatusBadge` / `truncated-cell` del base. Columnas: Holder (flexible, truncate+tooltip), **IBAN** (campo enmascarado), Alias, Currency, Status. Filas **no** navegan en v1. Paginación keyset diferida `[ASSUMPTION]` (a fijar con Winston; límite simple ahora). |
| **Campo IBAN** (revelar como contraseña) | Celda IBAN en la tabla de cuentas | **Enmascarado por defecto** (país + últimos 4: `ES•• ···· 1234`). **Toggle ojo** revela el valor íntegro **momentáneamente** y se re-oculta solo al cabo de **~10s o al perder el foco/hover**; `aria-pressed`, nombre accesible "Show/Hide IBAN". **CopyButton** copia el valor íntegro **siempre** (independiente del estado de revelado). El enmascarado es **solo presentacional** (CSS) — el valor completo ya viaja en el payload de la cuenta; reduce shoulder-surfing/capturas, no es un secreto criptográfico. |
| **Guard de borrado** | Acción Delete en lista (⋯) y en detalle | Si `accountCount > 0`, la acción Delete **no abre el confirm destructivo ni dispara `DELETE`**: abre una explicación **no destructiva** (popover anclado al control) — *"Can't delete — N associated accounts"* + acción **"View accounts"**. Coherente con el contrato base: *"un confirm nunca invita a confirmar lo imposible"*. Si `accountCount === 0`, Delete se comporta como hoy (confirm → `DELETE`). |

## State Patterns (delta)

| Estado | Superficie | Tratamiento |
|---|---|---|
| Banco con 0 cuentas | Lista / Detalle | Contador `0` atenuado sin enlace; campo "None"; **Delete habilitado normal**. |
| Banco con N>0 cuentas | Lista / Detalle | Contador enlazado; campo "N · View accounts"; **Delete pasa al guard**. |
| Carga del contador | Lista | El contador llega con la fila (read-model de búsqueda, una query agregada por página — ver `.decision-log` Entry 2); **sin** request por fila. Skeleton respeta el ancho de la celda. |
| Cuentas — carga fría | Superficie cuentas | Skeleton de filas (densidad del base). |
| Cuentas — vacío | Superficie cuentas | `EmptyState`: "This bank has no associated accounts." (no debería coexistir con un guard, que exige N>0). |
| Cuentas — error de carga | Superficie cuentas | `AsyncBoundary` → `ProblemDisplay` + `CorrelationIdChip` (patrón base). |
| IBAN revelado | Celda IBAN | Visible hasta auto-ocultar (~10s / blur); cambiar de fila/paginar re-enmascara. Un solo IBAN revelado a la vez `[ASSUMPTION]`. |
| **`bank-in-use` (carrera)** | Lista / Detalle | Aunque el guard evita el caso normal, una alta de cuenta entre lectura y borrado puede disparar el 409. Entonces el `mutation-error` persistente del base **ahora SÍ ofrece recuperación**: acción **"View accounts"** (antes: sin acción). Ver *Actualización al contrato base*. |
| Realtime del contador/cuentas | Lista / Cuentas | v1 **estático** (sin Mercure para cuentas); el contador refleja el último fetch. Diferido a v2. |

## Interaction Primitives (delta)

- **Toggle de revelado IBAN** — Enter/Space alterna; `aria-pressed`; foco visible; auto-oculta por timeout (~10s) o blur. Sin fade bajo `prefers-reduced-motion` (regla base).
- **Guard** — activar Delete con N>0 abre la explicación no destructiva (no el confirm); el foco entra en el popover; "View accounts" navega; Esc cierra y devuelve foco al invocador (precedencia de Esc del base).
- **Contador / enlaces** — un tab stop; activan navegación a la superficie de cuentas.
- **Prohibido (hereda):** revelar/copiar solo-hover sin equivalente foco/touch; el ojo y el CopyButton son focusables y visibles en touch.

## Accessibility Floor (delta — WCAG 2.2 AA; lo visual en `DESIGN.md`)

- **Color nunca es canal único**: el contador y su estado (0 vs N) se distinguen por número + atenuación, no por color.
- **IBAN reveal**: toggle con nombre accesible y `aria-pressed`; el valor enmascarado y el íntegro **ambos** legibles (contraste `text` AA); el CopyButton anuncia "IBAN copied" (patrón `CopyButton`). El revelado no depende de hover (también teclado).
- **Guard**: la explicación es alcanzable por teclado y se **anuncia** (no un botón `disabled` mudo — un control deshabilitado no es focusable ni se explica). El enlace "View accounts" nombra el destino.
- **Superficie de cuentas**: hereda semántica de tabla del base (`aria-sort` si hay orden, `scope="col"`, foco de fila); IBAN enmascarado no rompe el árbol de accesibilidad (el valor mostrado es el enmascarado; el íntegro se expone al revelar/copiar).
- Targets táctiles ≥40px (ojo y copy incluidos).

## Voice and Tone (delta — microcopy inglés, i18n diferido `[ASSUMPTION]`)

| Do | Don't |
|---|---|
| Columna lista: "ACCOUNTS" · valor `12` / `0` | "Number of associated bank accounts" |
| Detalle: "Associated accounts: 12 · View accounts" / "None" | "This bank has 12 account(s) linked to it" |
| Guard: "Can't delete — 12 associated accounts" + "View accounts" | "Error: deletion not allowed" / un confirm que invita a borrar lo imposible |
| Cuentas, vacío: "This bank has no associated accounts." | "Oops, no accounts 🤷" |
| IBAN toggle: "Show IBAN" / "Hide IBAN"; copy: "Copy IBAN" → "IBAN copied" | "Reveal sensitive data" |

## Key Flows

### Flujo 3′ — Borrado bloqueado, ahora con salida (Lucía, back-office, martes 11:10)

1. Lucía intenta borrar "Banco Industrial de Levante" desde la lista. La fila muestra el contador **`12`**.
2. Activa Delete (⋯). Como `accountCount = 12 > 0`, **no** se abre el confirm: aparece *"Can't delete — 12 associated accounts"* con **"View accounts"**. Nada destructivo, ningún `DELETE` disparado, ningún 409.
3. Pulsa "View accounts" → superficie de cuentas del banco: 12 filas (Operativa, Nóminas, Tesorería…). Para cotejar una, pulsa el **ojo** del IBAN → se revela `ES91 2100 0418 4502 0005 1332` unos segundos y se vuelve a enmascarar; de otra **copia** el IBAN con un clic para el ticket de reasignación.
4. **Clímax:** Lucía entiende en 10s *por qué* no puede borrar y *qué* hay debajo — sin callejón sin salida, sin leer un 409 en crudo. Reasignará/cerrará esas cuentas primero (flujo de cuentas, fuera de v1).

*Carrera:* si entre su lectura y un borrado alguien añade una cuenta y el `DELETE` sí sale, el 409 `bank-in-use` cae en el `mutation-error` persistente — que **ahora** ofrece "View accounts" en vez de dejarla sin salida.

### Flujo 4 — Auditoría rápida de cuentas (Marta, contable)

1. En la lista, Marta ve que "BBVA" tiene **`3`** y "CaixaBank" **`12`**. Click en el `12`.
2. Superficie de cuentas de CaixaBank; escanea holders y estados; revela un IBAN para verificar dígitos de control; copia otro. Vuelve con back al detalle/lista, posición intacta (patrón base).

## Actualización al contrato base (`ux-ERPify-2026-06-03`)

Esta feature **modifica** dos puntos del base; cuando se promocione, reflejarlo allí:

1. **Recuperación de `bank-in-use`** (base §"Errores de mutación" y Flujo 3): pasa de **"sin acción de recuperación"** a **acción "View accounts"** en el `mutation-error` persistente. La recuperación ya no "vive fuera" sin enlace: tiene superficie.
2. **Jerarquía de info de lista**: se añade **contador de cuentas** como señal de entidad (posición/columna en `DESIGN.md`); no desplaza Code/Name/Status.

## Open items restantes (`[ASSUMPTION]` — micro-detalles, no bloquean handoff)

- **Paginación** de la tabla de cuentas: cuándo se introduce keyset (v1 con límite simple vs paginada de inicio) — a fijar con Winston según volumen real.
- **i18n** del microcopy (heredado del base, diferido).
- Nit visual: color del `0` del contador (`text-subtle` vs `text-muted`) — ver `DESIGN.md`.
- **Un solo IBAN revelado a la vez** vs varios — confirmar interacción.
- **Wording final** del popover del guard.
