# Auditoría UX — módulo Banks (backoffice ERPify)

Fecha: 2026-06-03 · Evidencia: capturas en `imports/` + inventario de código en `.working/research-banks-ui-inventory.md`. Severidad: 🔴 bloquea el uso · 🟠 degrada el trabajo diario · 🟡 pulido.

## Veredicto general

La base es mejor de lo que sugieren las capturas: `DataTable` ya tiene navegación por teclado, ARIA correcta y soporte de densidad; los tokens `--erpify-*` ya son una ramp estilo Linear; CopyButton, StatusBadge y tooltips via `title` existen. **El fallo es uno y transversal: ningún contenedor de texto impone un presupuesto de espacio.** La tabla no acota el ancho de columna, la tarjeta no acota líneas, el H1 no acota líneas, el toast no acota la descripción. Todo lo demás (jerarquía débil, alturas inconsistentes, pérdida de metadatos) es consecuencia.

## Vista tabla (`Screenshot From 2026-06-03 20-29-31.png`)

| # | Hallazgo | Causa raíz (código) | Severidad |
|---|---|---|---|
| T1 | Un nombre de 255 chars ensancha la columna Name hasta expulsar Status/Created/Updated/Actions del viewport | `table-layout: auto` + celda `min-w-0` **sin max-width**: el `truncate` existente nunca actúa porque la columna crece primero | 🔴 |
| T2 | Short name trunca con "…" sin acceso al valor completo por teclado | `title=` solo funciona con hover de ratón; no hay tooltip focusable | 🟠 |
| T3 | El nombre no comunica que es truncable ni da acceso al valor completo | Sin tooltip en Name (sí lo hay en shortName y fechas) | 🟠 |
| T4 | Jerarquía plana: Created y Updated con el mismo peso visual que Name; ambas con fecha absoluta larga | Columnas sin diferenciación tipográfica; sin tiempos relativos en tabla | 🟠 |
| T5 | Sin indicación de acciones de fila hasta hacer hover; en la captura los iconos compiten con la fila | Acciones reveladas por hover sin ancla visible en reposo (⋯) | 🟡 |
| T6 | Checkbox de selección con poco peso visual; barra "3 selected" desconectada visualmente de la tabla | Estilo por defecto; sin estado tri-state en cabecera | 🟡 |
| T7 | Header no sticky: con 25–100 filas el contexto de columnas se pierde al hacer scroll | Sin `position: sticky` en `thead` | 🟠 |
| T8 | Sensación "CRUD crudo": sin densidad conmutable, sin recuento visible junto a paginación | `density` hardcodeada a `compact` (BanksTable.tsx:124); toggle inexistente | 🟡 |

## Vista tarjetas (`img.png`, `img_1.png`)

| # | Hallazgo | Causa raíz (código) | Severidad |
|---|---|---|---|
| C1 | Una tarjeta crece ~17 líneas de título y triplica la altura de sus hermanas; el grid pierde toda comparabilidad | Título con `[overflow-wrap:anywhere]` **sin line-clamp** (BanksCards.tsx:74-82) | 🔴 |
| C2 | Alturas desiguales incluso con nombres cortos: el contenido fluye sin regiones fijas | Sin `auto-rows-fr`/`h-full` ni footer anclado | 🟠 |
| C3 | Metadatos (Updated/Created) quedan bajo el fold en la tarjeta desbordada | Consecuencia de C1 | 🟠 |
| C4 | Acciones hover (⧉ ✎ ⋯) se superponen al título largo | Overlay clicable `after:inset-0` + acciones absolutas sin región reservada | 🟠 |
| C5 | El nombre completo no es accesible desde la tarjeta (sin tooltip; el `title` del link dice "View bank …") | Sin tooltip de valor completo en título | 🟠 |
| C6 | Valor añadido de la vista tarjetas dudoso para esta entidad: misma información que la tabla con menos densidad y peor escaneo | Decisión de patrón, no bug | 🟡 |

## Página de detalle (`Screenshot From 2026-06-03 20-28-29.png`)

| # | Hallazgo | Causa raíz | Severidad |
|---|---|---|---|
| D1 | H1 de 5 líneas a `text-2xl` empuja todo el contenido; las acciones Edit/Delete quedan flotando junto a un bloque tipográfico enorme | `break-words` sin clamp ([id]/page.tsx:206-211) | 🟠 |
| D2 | El toast de éxito reproduce el nombre completo y desborda | `toastNotifier.success("Bank created", { description: created.name })` sin clamp | 🟠 |

## Accesibilidad (transversal)

| # | Hallazgo | Severidad |
|---|---|---|
| A1 | `--erpify-text-subtle` (#8a8f98) sobre blanco ≈ 3.4:1 — **falla AA (4.5:1) en texto < 18px**; `text-faint` aún peor. Revisar dónde se usan en meta/labels pequeños | 🟠 |
| A2 | Acceso al valor completo depende de `title` (no anunciado por lectores de pantalla de forma fiable, no focusable, no cumple WCAG 1.4.13: hoverable/dismissible/persistent) | 🟠 |
| A3 | Lo que ya está bien y debe preservarse: `aria-sort`, `aria-selected`, `scope="col"`, caption sr-only, focus ring, ↑/↓/Enter/Space, reveal por `focus-within`, touch siempre visible | ✅ |
| A4 | StatusBadge heredado: etiqueta coloreada sobre tinte del mismo matiz = 2.19:1 (success) a 3.90:1 (info) — falla 1.4.3 (4.5:1); dot success #10b981 = 2.54:1 — falla 1.4.11 (3:1). Detectado por la lente de accesibilidad del Reviewer Gate (ratios calculados, no estimados) | 🔴 |

## Lo que NO hay que rediseñar

- La navegación por teclado y la semántica ARIA de `DataTable` — son correctas; el rediseño construye encima.
- El pipeline de errores RFC 9457 con `ProblemDisplay` + `CorrelationIdChip` — patrón ejemplar.
- Los tokens `--erpify-*` — la ramp tipográfica/colores ya apunta a Linear; faltan tokens de *contención* (anchos de columna, alturas de fila, clamps), no de estética.
- El modal de borrado con 404 embebido (`Screenshot From 2026-05-31 13-43-34.png`) es un bug de carrera ya rastreado aparte (`fix/pwa-bank-delete-flash`), no un problema de diseño — aunque el rediseño especifica que un confirm con error de precondición debe deshabilitar la acción primaria (ver EXPERIENCE.md, State Patterns).

## Respuesta del rediseño

Cada hallazgo queda cubierto por las spines: T1→tabla `table-fixed` con presupuesto por columna; T2/T3/A2→tooltip-si-truncado focusable; C1/C2→clamp 2 líneas + regiones fijas + `auto-rows-fr`; D1→clamp en H1 con valor completo en la ficha; D2→clamp en toast; T7→header sticky; T8→toggle de densidad; A1→regla de contraste para texto pequeño; A4→cláusula de contraste del StatusBadge (etiqueta neutra + dots oscurecidos). Detalle: `DESIGN.md` (visual) y `EXPERIENCE.md` (conducta).
