---
name: ERPify Backoffice — listas de entidades
status: final
sources:
  - brief del usuario (2026-06-03, conversación) — auditoría y rediseño Banks
  - revisión de Sergio sobre PR #137 (2026-06-04, conversación)
  - revisión de Sergio post PR #144 (2026-06-04, conversación) — errores de mutación persistentes
  - docs/project-context.md
  - .working/research-banks-ui-inventory.md
design_ref: DESIGN.md
updated: 2026-06-04
---

# ERPify Backoffice — Experience Spine

> Banks es el caso de referencia; este contrato define el **patrón estándar de listas de entidades** (clientes, proveedores, proyectos, bancos…) del backoffice. Pareja de `DESIGN.md` (identidad visual); las spines ganan ante cualquier mock o captura en caso de conflicto.

## Foundation

Web desktop-first responsive. Next.js 16 App Router + React 19; sistema UI **Shadcn** + componentes propios `erpify/` (`DataTable`, `StatusBadge`, `CopyButton`, `EmptyState`, `ProblemDisplay`, `RecordSheet`…). `DESIGN.md` es la referencia visual y nombra los presupuestos de contención; esta spine define solo el delta conductual sobre lo que `DataTable` y las primitivas ya hacen bien (teclado, ARIA, selección) — lo existente que funciona se conserva por contrato, no por inercia.

Usuarios: profesionales que operan cientos/miles de registros al día (contables, back-office, jefes de obra). Stakes: producto B2B comercial — WCAG AA en serio. Dark mode e i18n diferidos: nada aquí los bloquea, nada aquí los diseña.

## Information Architecture

| Superficie | Se llega desde | Propósito |
|---|---|---|
| Lista (vista tabla, **default**) | Sidebar → Banks | Escanear, ordenar, filtrar, seleccionar en masa, navegar al detalle |
| Lista (vista tarjetas) | Toggle de vista | Navegación visual cuando la entidad gana con identidad gráfica (logo); opt-in, nunca default |
| Detalle | Fila/tarjeta (click o Enter) | **Hogar canónico del valor completo** de todo campo — su H1 muestra el nombre íntegro; acciones de la entidad |
| Peek (RecordSheet lateral) | Tecla `o` sobre fila activa `[ASSUMPTION]` — v2 opcional | Inspección rápida sin perder posición ni selección en la lista |
| Crear / Editar | "+ New bank" / acción fila | Formulario con validación Zod (límites visibles, sin truncado silencioso) |

La tabla es la vista por defecto para toda entidad: a igualdad de información gana en densidad y escaneo. Las tarjetas se mantienen como modo opt-in y solo aportan cuando hay señal visual (logos); para entidades sin ella, los módulos nuevos pueden no ofrecerlas.

→ Referencia de composición: [`mockups/key-banks-table.html`](mockups/key-banks-table.html) (tabla compact con selección activa, caso de estrés truncado y tooltip-si-truncado) y [`mockups/key-banks-cards.html`](mockups/key-banks-cards.html) (grid de alturas iguales con clamp 2, seleccionada y hover). Estado previo auditado: PNGs en `imports/`, puente en [`audit-banks.md`](audit-banks.md). Las spines ganan en conflicto.

## Estrategia de texto largo (transversal)

Aplica a nombres de bancos, clientes, proveedores, proyectos, empresas — todo texto de origen externo. Evaluación de patrones y veredicto:

| Patrón | Veredicto | Razón |
|---|---|---|
| Truncado 1 línea (tabla) | ✅ **estándar en celdas** | Altura de fila uniforme = escaneo; el ojo lee columnas, no párrafos |
| Clamp 2 líneas (títulos de tarjeta) | ✅ **estándar en títulos de lista** | Da contexto suficiente sin romper la retícula; altura reservada fija |
| H1 de detalle íntegro (sin clamp) | ✅ **estándar en detalle** | El detalle es el hogar canónico: recortar el nombre justo ahí era contradictorio (decisión 2026-06-04; se acepta el título alto en móvil con 255 chars) |
| Tooltip si-truncado (hover **y focus**) | ✅ **estándar de acceso rápido** | Solo cuando hay truncado real; focusable (corrige `title=`); cumple WCAG 1.4.13 |
| Página de detalle | ✅ **acceso canónico** | El valor íntegro de todo campo siempre vive ahí; el resto son atajos |
| Side drawer / panel (RecordSheet) | 🟡 v2 opcional | Útil para inspección en serie sin perder la lista; el componente ya existe |
| Expand-on-click (fila) | ❌ | Rompe la uniformidad de alturas que hace útil la tabla |
| Expand-on-hover | ❌ | Layout shift bajo el cursor; inutilizable con teclado y touch |
| Row expansion | ❌ | Herramienta para datos hijo (líneas de pedido), no para escalares largos |

**Regla compuesta:** celda = 1 línea truncada → tooltip si-truncado → detalle. Título de tarjeta = clamp 2 → tooltip si-truncado → detalle. H1 de detalle = **íntegro sin clamp** (el campo NAME de la ficha lo repite como dato estructurado). Toast = descripción clamp 2. Los límites reales del dominio (name ≤ 255, shortName ≤ 50, validados en Zod ↔ Assert) hacen el peor caso acotado y testeable: **el e2e de regresión usa un nombre de 255 caracteres**.

**Letra pequeña del tooltip (hallazgos H1/H4 del gate):** el tooltip se dispara con hover de la celda y con **foco de fila** — la fila es el único tab stop; los spans truncados no reciben `tabIndex` (un orden de tab que dependiera del viewport/zoom sería un anti-patrón). En **puntero grueso (touch)** el tooltip no existe por diseño: la ruta declarada al valor completo es el detalle, a un tap de distancia — no es un fallback accidental. Configuración fijada: `TooltipProvider` con `delayDuration` 200ms y `disableHoverableContent={false}`. El truncado/clamp es **solo CSS**: el string completo permanece siempre en el DOM/árbol de accesibilidad (celdas, títulos de tarjeta, H1 de detalle) — prohibido truncar en JS.

## Errores de mutación — superficie persistente (transversal)

Decisión 2026-06-04 (segunda sesión; **sustituye al patrón D-modal y al toast-como-única-superficie**). Todo error de **mutación** (crear, editar, borrar — single o masivo; precondiciones 404/409 incluidas) se presenta en una superficie persistente, legible y copiable. Razón: un toast es transitorio y un dialog no se lee bien — el usuario necesita capturar pantalla, copiar el mensaje y el código, adjuntarlos a un ticket.

- **Anclaje contextual al origen:** el error aparece pegado a donde se intentó la mutación — sobre la tabla/grid en lista y tarjetas; bajo el H1 en la página de detalle; sobre el formulario en crear/editar. Mismo componente en todas las superficies (`ProblemDisplay` en variante persistente con dismiss y acciones de copia `[ASSUMPTION]` — la realización exacta la decide implementación), anclaje por superficie.
- **El confirm destructivo se cierra solo al fallar.** El error nunca vive dentro del dialog: al fallar la mutación o su precondición, el dialog se desmonta y el foco pasa a la superficie persistente (ver Accessibility Floor).
- **Ciclo de vida — dismiss o reintento:** permanece hasta que el usuario lo cierra (×); un nuevo intento de la misma mutación lo sustituye; el éxito posterior la limpia. Sobrevive a refetch y a updates de Mercure; no sobrevive a navegar a otra página. Cada origen de mutación muestra como máximo un error (el último); orígenes distintos pueden coexistir (p. ej. formulario y lista) `[ASSUMPTION]`.
- **Contenido verbatim del problem RFC 9457** — prohibido sintetizar problems client-side (decisión previa, se conserva). Sin clamp: esta superficie existe para leer el error completo. Copiable: mensaje (title + detail), código de error (type + status), correlation id (`CorrelationIdChip`, conservado) y **JSON completo** del payload recibido (extensiones incluidas: `bankId`, `accountCount`…). Los campos visibles y copiables son exactamente los que llegan por wire: el bloque `debug` es env-aware en la API (dev/test lo incluyen; prod lo omite — `docs/api-error-contract.md`) y se renderiza solo si está presente; se copia lo recibido, nunca más.
- **Recuperación tipada por `problem.type`, reubicada aquí:** `bank-not-found` (registro obsoleto) → acción "Refresh list" (en detalle: "Refresh") dentro de la superficie persistente; al resolverse, el error se limpia. `bank-in-use` → sin acción de recuperación (la recuperación vive fuera: las cuentas asociadas). Tipos sin mapeo → sin acción.
- El **toast de error queda como señal transitoria complementaria**: anuncia el fallo (descripción clamp 2) y orienta hacia la superficie persistente; nunca es la única superficie de un error de mutación.

## Jerarquía de información de entidad

Orden en superficies de lista y por qué:

1. **Código (`shortName`)** — primero. Es único, corto, mono y de ancho estable: el ancla de escaneo vertical (patrón terminal financiero/SAP). Quien repite tarea memoriza códigos, no nombres de 255 chars.
2. **Nombre** — la columna flexible, identificador humano y clave de orden por defecto.
3. **Estado** — badge dot-first inmediatamente después del nombre: califica al registro sin competir con él. El badge de recencia ("New") es estado y vive aquí — en la columna Status de la tabla, en la región de estado de la tarjeta y junto al short name bajo el título en el detalle; **nunca inline con el nombre** ni entre el nombre y las acciones (decisión 2026-06-04).
4. **Updated (relativo)** — el timestamp operativo: "¿esto está vivo?". Tooltip con fecha absoluta.
5. **Created** — demovido a `xl+` y al detalle `[ASSUMPTION]`: casi nunca decide una acción en la lista.
6. **Acciones** — extremo derecho, convención universal; ⋯ visible en reposo.
7. **ID (UUID)** — **nunca columna**. Vive en acciones (CopyButton) y detalle. Es para máquinas y soporte, no para escanear.

## Voice and Tone

Microcopy en inglés (UI actual; i18n diferido `[ASSUMPTION]`). Voz: contable competente — cuenta, nombra y calla.

| Do | Don't |
|---|---|
| "3 selected" · "Clear" | "You have selected 3 items!" |
| "Updated 2m ago" (tooltip: fecha absoluta) | "Last modification performed on…" |
| "Delete 3 banks? This cannot be undone." | "Are you absolutely sure you want to…?" |
| "No banks match these filters." + Reset | "Oops! Nothing here 🤔" |
| Toast: "Bank created" + nombre clamp 2 | Toast con el nombre íntegro de 255 chars |
| Toast de error: "Couldn't delete bank — see error details" `[ASSUMPTION]` (el detalle vive en la superficie persistente) | El error completo solo en el toast, o un toast de error sin superficie persistente detrás |

## Component Patterns

Conducta; lo visual en `DESIGN.md.Components`.

| Componente | Uso | Reglas conductuales |
|---|---|---|
| Tabla de entidades | Lista default | `table-layout: fixed` (`{components.table}`); Name única columna flexible. Click en fila → detalle; click en checkbox/acciones no navega. Cabecera sticky; sombra solo con scroll. Orden por una columna, indicador + `aria-sort` (conservado). |
| Celda truncable | Toda celda de texto externo | 1 línea + tooltip-si-truncado (hover de celda y foco de fila — sin tab stops por span); sin truncado real, sin tooltip — cero ruido. En touch, el acceso al valor completo es el detalle. |
| StatusBadge | Tabla, tarjetas, detalle | Presentacional, no interactivo. Mapea estados del dominio; la etiqueta textual siempre acompaña al dot (el color nunca es canal único). Sin tooltip. |
| Fila | Tabla | Estados: hover / selected / focus (ring inset). ↑/↓ mueve, Enter abre, Space selecciona (conservado). Acciones: ⋯ siempre visible; ⧉ ✎ revelan en hover/focus-within; touch todo visible. |
| Tarjeta de entidad | Vista tarjetas | Regiones fijas (zona de controles: checkbox **siempre visible** + código + acciones / título clamp-2 con altura reservada, a todo el ancho / estado / footer meta anclado). Controles y datos nunca comparten fila. Toda la tarjeta clicable (overlay actual); checkbox y acciones por encima del overlay. Tooltip-si-truncado en título. |
| Barra de selección | Lista con selección > 0 | "N selected" + Clear + Delete. Aparece pegada a la tabla (mismo ancho). La región `aria-live="polite"` está **siempre montada** (vacía sin selección) para que el primer anuncio no se pierda; durante selección por rango los anuncios se coalescen (recuento final tras pausa breve). Esc limpia selección (ver precedencia en Interaction Primitives). Header checkbox tri-state que expone `aria-checked="mixed"` cuando 0 < seleccionadas < total. |
| Toggle densidad | Toolbar de lista | compact ↔ comfortable; persiste en localStorage por usuario `[ASSUMPTION]`; aplica a tabla y tarjetas (padding, no tamaño de letra). |
| Toggle de vista | Toolbar | tabla ↔ tarjetas; persiste junto a densidad. |
| Filtros | Toolbar colapsable | Badge con nº de filtros activos en el botón (existente — los e2e dependen de él). Reset visible cuando hay filtros. |
| Toast | Global | Sonner; descripción clamp 2. Éxito: nombra la entidad. Error: **señal transitoria complementaria** que orienta a la superficie de error persistente (ver sección transversal); via pipeline RFC 9457, nunca stack traces. |
| Confirm destructivo | Delete individual/masivo | Frase con recuento y nombre(s) clamp. Si la mutación o su precondición falla (404 obsoleto, 409 en uso…), el dialog **se cierra solo** y el error aparece en la superficie persistente del origen — un confirm nunca muestra el error ni invita a confirmar lo imposible (decisión 2026-06-04; sustituye al patrón D-modal). |
| Error de mutación persistente | Lista, tarjetas, detalle, formularios | `ProblemDisplay` persistente anclado al origen; dismiss ×; sustituido por reintento, limpiado por éxito; copia de mensaje, type+status, correlation id y JSON íntegro; acción de recuperación tipada por `problem.type` (404 → Refresh). Campos según payload recibido (`debug` env-aware). Sin clamp. |

### Wireframe — tabla (desktop, compact)

```
┌─ Banks ───────────────────────────────────────── [▤|⊞] [⚙ Filters ②] [+ New bank] ─┐
│ Manage the banks available in the back office. · Total: 1.204                        │
├──────────────────────────────────────────────────────────────────────────────────────┤
│ ☑ 2 selected · Clear (Esc)                                              [🗑 Delete]   │
├────┬──────────┬───────────────────────────────────────────┬──────────┬─────────┬─────┤
│ ▣  │ CODE ↕   │ NAME ↑                                    │ STATUS   │ UPDATED↕│     │
├────┼──────────┼───────────────────────────────────────────┼──────────┼─────────┼─────┤
│ ☑  │ SSSSSSS… │ ssssssssssssssssssss ssfsfsdfsfdfdf sss…  │ ● New    │ 2m ago  │  ⋯ │
│ ☑  │ HG       │ sssdfs                                    │ ● New    │ 7m ago  │  ⋯ │
│ □  │ BBVA     │ Banco Bilbao Vizcaya Argentaria           │ ● Active │ 3d ago  │⧉✎⋯ │ ← hover
├────┴──────────┴───────────────────────────────────────────┴──────────┴─────────┴─────┤
│ 25 per page ▾                                                       ‹ Page 1 of 49 ›  │
└──────────────────────────────────────────────────────────────────────────[max 1440px]─┘
  40px  112px   flexible (min 240px, truncate+tooltip)        96px      128px    96px
```

### Wireframe — tarjeta de entidad

```
┌────────────────────────────────┐  ┌────────────────────────────────┐
│ □  BBVA                 ⧉ ✎ ⋯ │  │ ☑  SSSSSSSSSSSSSS…      ⧉ ✎ ⋯ │ ← código mono + acciones (h fija)
│ Banco Bilbao Vizcaya           │  │ ssssssssssssssssssss ssfsfsdf… │ ← título clamp 2,
│ Argentaria, S.A.               │  │ sfdfdf ssssssssssssssssssss s… │   altura SIEMPRE 2 líneas
│ ● Active                       │  │ ● New                          │ ← estado (h fija)
│ ──────────────────────────────│  │ ───────────────────────────── │
│ Updated 3d ago                 │  │ Updated 2m ago                 │ ← footer anclado al fondo
└────────────────────────────────┘  └────────────────────────────────┘
        Alturas idénticas por construcción (auto-rows-fr + regiones fijas)
```

## State Patterns

| Estado | Superficie | Tratamiento |
|---|---|---|
| Carga fría | Lista | Skeleton de filas que respeta densidad y anchos de columna reales (sin saltos al resolver). En vista tarjetas, el skeleton respeta `auto-rows-fr` y la altura reservada de 2 líneas del título. Conserva `BanksListSkeleton` adaptado. |
| Vacío absoluto | Lista | `EmptyState` first-run + acción "New bank" (conservado). |
| Vacío filtrado | Lista | "No banks match these filters." + Reset (conserva `BanksEmptyFiltered`). |
| Selección activa | Lista | Barra "N selected", contador `aria-live`; sobrevive a paginación dentro de la misma sesión de filtros `[ASSUMPTION]`; Esc limpia. |
| Borrado optimista | Lista | Fila desaparece al confirmar; si falla, rollback (fila **y selección** restauradas) + error persistente sobre la lista + toast transitorio que orienta a él (decisión 2026-06-04). |
| Precondición de borrado fallida (404/409) | Lista / Detalle | El confirm **se cierra solo**; error persistente en el origen con recuperación tipada: 404 → "Refresh list" (detalle: "Refresh"); 409 `bank-in-use` → sin acción. Sustituye al patrón D-modal de la auditoría (decisión 2026-06-04). |
| Error de carga | Lista | `AsyncBoundary` → `ProblemDisplay` + `CorrelationIdChip` (conservado). |
| Realtime (Mercure) | Lista | Cambios remotos no roban foco ni deshacen selección; si la página visible cambia, indicador discreto "List updated" en vez de re-render disruptivo `[ASSUMPTION]`. |
| Truncado | Celdas/títulos | Tooltip solo si hay truncado real; nunca tooltips en texto que cabe. |
| Permisos por rol | — | Fuera de alcance de este contrato: la autorización por rol se especifica en su propio contrato cuando exista. Silencio consciente, no omisión. |

## Interaction Primitives

Teclado primero — se conserva todo lo existente de `DataTable` y se añade lo mínimo:

- `↑/↓` mueve foco de fila · `Enter` abre detalle · `Space` selecciona (existente)
- `Shift+↑/↓` y `Shift+click` — extensión de selección por rango `[ASSUMPTION]`
- `Esc` — **precedencia:** cierra primero la capa transitoria superior (tooltip/popover/dialog); solo limpia la selección cuando no hay ninguna capa abierta (una selección de 23 filas no se pierde por cerrar un tooltip)
- `/` — foca el filtro de nombre `[ASSUMPTION]`
- `o` — peek lateral (v2, si se adopta RecordSheet)
- Hover revela ⧉ ✎; ⋯ siempre visible y focusable; touch todo visible (existente)
- **Prohibido:** scroll infinito (paginación siempre), drag-to-reorder, affordances solo-hover sin equivalente focus/touch, modales apilados > 1 nivel

## Accessibility Floor

Conductual; contraste y tonos en `DESIGN.md` (regla `meta-text-rule` incluida). WCAG 2.2 AA.

- Se preserva la semántica existente: `aria-sort`, `aria-selected`, `scope="col"`, caption sr-only, focus visible 2px.
- Tooltip-si-truncado: cumple 1.4.13 — hoverable, dismissible (Esc), persistente; se abre también con focus de teclado. Sustituye a `title=` en celdas truncadas.
- Barra de selección: cambios de contador anunciados via `aria-live="polite"`; el botón Delete nombra el alcance ("Delete 3 banks").
- Texto < 18px: mínimo `text-muted` (≈5.9:1). Auditar usos actuales de `text-subtle`/`text-faint` en meta (hallazgo A1).
- StatusBadge: etiqueta neutra ≥4.5:1 y dot ≥3:1 según la cláusula de contraste de `DESIGN.md` (el badge heredado incumplía — C1/C2 del gate).
- Header checkbox tri-state con `aria-checked="mixed"` (vía `.indeterminate` o Radix Checkbox `checked="indeterminate"` — receta en companion).
- **Gestión de foco en mutaciones:** tras borrado optimista de la fila activa, el foco pasa a la fila siguiente (a la anterior si era la última; al contenedor/empty-state si no quedan) y el cambio se anuncia por la región viva. **Cuando una mutación falla y el confirm se cierra, el foco pasa a la superficie de error persistente (`tabIndex={-1}`) y el fallo se anuncia por live region — desmontar/deshabilitar nunca deja el foco en `<body>` (decisión 2026-06-04; sustituye el foco al ProblemDisplay del dialog).** Tras "Refresh list" en el error persistente: si la precondición se resolvió, el error se limpia y el foco va al Delete de la barra de selección si sigue habiendo selección, o al contenedor de lista en su defecto `[ASSUMPTION]`; si el fallo persiste, el foco permanece en el error actualizado. Al cerrar dialog/peek sin fallo, el foco vuelve al invocador (default Radix).
- `prefers-reduced-motion`: sin transiciones de reveal/hover/sheet/dialog; los reveals aparecen sin fade; la sombra scroll-driven de la cabecera se desactiva (gate `no-preference`); el toast no anima entrada (verificar el comportamiento real de la versión de Sonner, no asumirlo).
- Targets táctiles ≥ 40px en modo comfortable; touch siempre ve las acciones (existente).
- Orden de Tab = orden de lectura; el foco nunca queda atrapado en la barra de selección.

## Responsive & Platform

| Breakpoint | Comportamiento |
|---|---|
| `≥ xl` (1280px+) | Tabla completa: Code · Name · Status · Updated · (Created en xl+ `[ASSUMPTION]`) · Actions. Contenido max 1440px. |
| `lg` (1024–1279) | Sin Created. Grid tarjetas 3 col. |
| `md` (768–1023) | Sin Updated (queda en tooltip del nombre o detalle). Grid 2 col. |
| `< md` | La tabla **se convierte en lista de filas-tarjeta apiladas** (código + nombre truncado + badge), sin scroll horizontal — el `overflow-x-auto` actual es escaneo cero en móvil. Grid 1 col. Acciones y checkbox siempre visibles. **Contrato de teclado (fijado 2026-06-04):** cada fila-tarjeta es un único tab stop; orden de foco = orden visual; ↑/↓ mueve entre filas, Enter abre detalle, Space selecciona — las mismas semánticas que la tabla; la barra de selección conserva su live region. |

Desktop es la superficie primaria; móvil es lectura + acción puntual, no operación masiva (la barra de selección masiva puede simplificarse a contador + Delete).

## Inspiration & Anti-patterns

- **De Linear:** densidad 36px, dot-first badges, tonos funcionales sutiles, contenido centrado con max-width, teclado como superficie primaria.
- **De GitHub:** ⋯ como ancla de acciones visible en reposo; tiempos relativos con título absoluto.
- **De Stripe Dashboard:** mono para identificadores; columnas de tabla con presupuesto fijo; copy-as-action en IDs.
- **De Vercel:** la sobriedad monocroma y la familia Geist (ya adoptada en los tokens `--erpify-*`); su patrón de tabla queda subsumido en lo tomado de Linear/GitHub.
- **De Notion (rechazado en parte):** se descarta la edición inline de nombre en lista — en un ERP el nombre es dato maestro con validación; se edita en formulario.
- **Rechazado — scroll infinito:** auditoría y contabilidad necesitan posiciones estables y paginación referenciable.
- **Rechazado — expand-on-hover/click y row expansion** para texto largo (ver Estrategia de texto largo).
- **Rechazado — vista tarjetas como default:** misma información, menos densidad. Solo opt-in.

## Key Flows

### Flujo 1 — Conciliación del lunes (Marta, contable, 8:30, segundo café)

1. Marta abre Banks desde el sidebar. La tabla carga en compact, 25 filas, header sticky.
2. Escribe `/`, teclea "cons" en el filtro de nombre; el badge del botón Filters marca ①.
3. Una fila muestra "Construcciones y Promociones del Mediterráneo Or…" truncado. Baja con `↓` hasta ella; el tooltip de foco le enseña el nombre íntegro sin tocar el ratón.
4. `Enter` — detalle. El H1 muestra el nombre en 2 líneas clamp; el campo NAME de la ficha lo tiene completo; copia el IBAN-código con CopyButton.
5. **Clímax:** vuelve atrás y la lista está exactamente donde la dejó — mismo filtro, misma fila focada, mismo scroll. Marta procesa 14 bancos así en 6 minutos, sin tocar el ratón más que para el café.

Fallo: el detalle devuelve 404 (borrado por otra usuaria) → `EmptyState` not-found con `CorrelationIdChip` y "Back to banks"; la lista al volver refresca y anuncia "List updated".

### Flujo 2 — Limpieza de datos de prueba (Andrés, back-office, viernes 17:40)

1. Andrés filtra por nombre "test". 23 resultados, varios con nombres de 200+ caracteres pegados de un Excel.
2. Marca la primera con `Space`, baja con `Shift+↓` extendiendo la selección por rango. La barra anuncia "23 selected" (`aria-live`).
3. Pulsa Delete en la barra. El confirm dice: "Delete 23 banks? This cannot be undone." — los tres primeros nombres listados con clamp, "+20 more".
4. Uno de los registros ya no existe (otro compañero lo borró). El confirm **se cierra solo**; sobre la tabla aparece el error persistente con el problem del 404 y la acción "Refresh list". Andrés lo lee con calma — podría copiar el código o capturarlo para un ticket; nada desaparece bajo su cursor.
5. **Clímax:** Refresh list → el error se limpia, la selección se recalcula a 22 y el foco va al Delete de la barra `[ASSUMPTION]`; vuelve a confirmar, toast "22 banks deleted". La tabla nunca se deformó, ni con 255 caracteres ni con un borrado concurrente — el viernes acaba a las 17:43.

Fallo: el bulk delete falla a mitad → rollback optimista con fila y selección restauradas, error persistente RFC 9457 sobre la lista (correlation id copiable) + toast transitorio; Andrés reintenta sin re-seleccionar.

### Flujo 3 — Borrado bloqueado por integridad (Lucía, back-office, martes 11:10)

1. Lucía intenta borrar "Banco Industrial de Levante" desde la lista (el flujo es idéntico desde tarjetas o desde el detalle).
2. Confirma el dialog; la API responde 409 `bank-in-use` — el banco tiene 12 cuentas asociadas. El confirm se cierra solo.
3. Sobre la tabla aparece el error persistente: title verbatim del backend con el recuento, código `bank-in-use` · 409, correlation id, extensiones `bankId` y `accountCount` — **sin acción de recuperación**: la recuperación vive fuera (las cuentas asociadas).
4. **Clímax:** Lucía copia el JSON completo con un clic y lo pega en el ticket; hace la captura con el error aún en pantalla — nada parpadeó ni se desvaneció. Cuando termina, lo cierra con ×.

Nota de entorno: en dev/test el mismo error muestra además el bloque `debug` (env-aware, ya en el wire); en prod jamás.

> Crear/editar (delta 2026-06-04): el campo **Name es un textarea auto-grow** — una línea que crece hasta mostrar todo lo escrito, también en móvil; **Enter envía** el formulario (el dominio es single-line) y los saltos de línea pegados colapsan a espacio; contador `n/255` visible desde el 80% del límite. Short name permanece input de 1 línea. Límites Zod visibles, sin `maxLength` silencioso — regla del repo. El lazo de creación termina en el toast clamp-2 ya especificado en Voice and Tone. Los errores de guardado del formulario siguen la superficie persistente transversal (sobre el formulario), no un estado ad-hoc.
