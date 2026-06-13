---
name: ERPify Backoffice
description: Sistema visual del backoffice ERPify — delta sobre Shadcn + tokens --erpify-* existentes, centrado en superficies de datos densas (listas, tarjetas, detalle). Banks es el caso de referencia; aplica a toda lista de entidades.
status: final
updated: 2026-06-04
colors:
  # Heredados de pwa/src/app/globals.css (@theme) — NO se redefinen aquí, se referencian.
  # bg #f7f8f8 · bg-muted #f3f4f5 · bg-elevated #ffffff · text #08090a · text-muted #62666d
  # text-subtle #8a8f98 · border #dcdfe3 · border-subtle #eef0f2 · brand #5e6ad2
  # accent #7170ff · focus-ring #7170ff · success #10b981 · warning #d97706 · danger #dc2626
  # Dark mode ya tokenizado en globals.css; este DESIGN.md no lo diseña (diferido), no lo bloquea.
  surface-row-hover: '#f3f4f5'        # = --erpify-bg-muted al 100% (sustituye bg-muted/30 actual)
  surface-row-selected: '#eef0fb'     # tinte de brand al ~8% sobre blanco
  surface-header: '#f7f8f8'           # = --erpify-bg; cabecera sticky de tabla
  status-dot-success: '#0f7a5a'       # relleno del dot de estado; ≥3:1 sobre blanco y surface-row-selected (el #10b981 heredado da 2.54:1 y falla 1.4.11)
  status-dot-warning: '#b45309'       # ídem (el #d97706 heredado da 3.19:1, marginal)
  # dots danger #dc2626 (4.83:1) e info/brand #5e6ad2 (4.70:1) heredados ya cumplen 3:1
  meta-text-rule: 'Texto < 18px usa como mínimo --erpify-text-muted (#62666d, ~5.9:1). --erpify-text-subtle y text-faint quedan reservados a >= 18px o elementos decorativos.'
typography:
  # Ramp heredada de globals.css. Roles de superficie de datos:
  table-cell:
    fontSize: 13px            # --text-sm
    lineHeight: '1.4'
  table-header:
    fontSize: 12px            # --text-xs
    fontWeight: '500'
    letterSpacing: 0.02em
  entity-code:
    fontFamily: 'Geist Mono'
    fontSize: 12px
    fontWeight: '500'
    note: 'uppercase; shortName/códigos únicos. Nunca proporcional.'
  card-title:
    fontSize: 14px            # --text-base
    fontWeight: '600'
    lineHeight: '1.35'
  meta:
    fontSize: 12px
    note: 'aplica {colors.meta-text-rule} — mínimo text-muted'
  detail-h1:
    fontSize: 20px            # --text-xl; sm+: 24px (--text-2xl)
    fontWeight: '600'
    letterSpacing: -0.01em
    note: 'sin clamp — el detalle es el hogar canónico y el título envuelve el nombre íntegro (2026-06-04)'
rounded:
  # Heredados: micro 2px · sm 4px · md 6px (controles) · lg 8px · xl 12px
  row: 6px        # filas focusables y tarjeta-fila
  card: 8px       # tarjetas de entidad
spacing:
  # Escala Tailwind heredada. Presupuestos de contención (nuevos — el corazón del rediseño):
  row-h-compact: 36px
  row-h-comfortable: 44px
  table-header-h: 36px
  col-select: 40px
  col-code: 112px
  col-status: 96px
  col-updated: 128px
  col-actions: 96px
  col-name-min: 240px
  list-max-w: 90rem          # 1440px (confirmado) — sustituye 2xl:max-w-[120rem]
  card-min-w: 280px
  card-title-lines: '2'
  toast-desc-lines: '2'
components:
  table:
    layout: 'fixed'
    maxWidth: '{spacing.list-max-w}'
    headerHeight: '{spacing.table-header-h}'
    headerBackground: '{colors.surface-header}'
    headerSticky: 'true'
  table-row:
    heightCompact: '{spacing.row-h-compact}'
    heightComfortable: '{spacing.row-h-comfortable}'
    hover: '{colors.surface-row-hover}'
    selected: '{colors.surface-row-selected}'
    focusRing: '2px --erpify-focus-ring, inset'   # CSS literal intencional (herencia nombrada); ≥3:1 validado sobre las 4 superficies de lista — cualquier cambio de token re-valida
  entity-card:
    minWidth: '{spacing.card-min-w}'
    radius: '{rounded.card}'
    titleLines: '{spacing.card-title-lines}'
    titleType: '{typography.card-title}'
    equalHeights: 'true'
  status-badge:
    height: 20px
    fontSize: 11px            # --text-2xs
    dotSize: 6px
    radius: 9999px
    labelColor: '--erpify-text-muted'   # SIEMPRE neutra (≥4.5:1); el matiz vive solo en el dot
    dotSuccess: '{colors.status-dot-success}'
    dotWarning: '{colors.status-dot-warning}'
  bulk-bar:
    background: '--erpify-bg-elevated'
    border: '1px --erpify-border'
    radius: '{rounded.lg}'
    note: 'mismo ancho que la tabla, pegada a su borde superior; recuento 13px medium'
  row-action-button:
    sizeCompact: 28px
    sizeComfortable: 32px
    iconSize: 16px
  tooltip-full-value:
    maxWidth: 360px
    fontSize: 12px
    note: 'multilínea, hasta ~6 líneas con scroll interno; solo si hay truncado real; delayDuration 200ms'
  form-name-field:
    autoGrow: 'true'          # textarea de 1 línea que crece con el contenido
    minRows: 1
    counter: 'n/255 — visible desde el 80% del límite'
---

## Brand & Style

ERPify Backoffice es una herramienta de trabajo diario para gente que administra cientos de registros entre llamada y llamada: contables, back-office, jefes de obra. La postura estética es **herramienta, no escaparate** — en la familia de Linear y Stripe Dashboard: tipografía pequeña y nítida, cromatismo contenido, jerarquía por peso y tono antes que por color, y cero decoración que no pague su alquiler en escaneabilidad.

Este DESIGN.md es un **delta sobre dos capas heredadas**: las primitivas Shadcn del repo (`pwa/src/components/ui/`) y los tokens `--erpify-*` ya definidos en `globals.css`, que ya implementan la ramp tipo Linear (Geist, body 14px, radios 6px). No se redefine nada que ya exista; lo nuevo de esta iteración es la capa de **contención**: todo texto de origen externo (nombres de bancos, clientes, proveedores, proyectos) vive dentro de un presupuesto de espacio explícito — ancho de columna, líneas de clamp, altura de fila — y el layout jamás se deforma para acomodarlo. La regla de marca es: *el layout manda, el contenido se adapta, el valor completo siempre queda a un clic o un focus de distancia*.

## Colors

Paleta heredada de `globals.css` sin cambios de identidad. Lo que esta iteración añade son tres tonos funcionales de superficie y una regla de uso:

- **`{colors.surface-row-hover}`** — hover de fila/tarjeta. Sólido (no alpha) para que el `truncate` con degradado opcional y los reveals no produzcan moiré sobre contenido.
- **`{colors.surface-row-selected}`** — fila/tarjeta seleccionada: tinte frío de brand. Su delta de luminancia con el hover es mínimo (≈1.03:1) — distinción por matiz, no por brillo —, así que la señal fiable de selección son siempre los canales no-cromáticos (checkbox marcado / ring); el tinte es refuerzo, nunca el único canal.
- **`{colors.status-dot-success}` / `{colors.status-dot-warning}`** — rellenos del dot de estado, oscurecidos respecto a los semánticos heredados para superar 3:1 (WCAG 1.4.11) sobre blanco y sobre `{colors.surface-row-selected}`. Los semánticos heredados (`--erpify-success/warning`) siguen siendo los tonos de texto grande, iconos grandes y acciones.
- **`{colors.surface-header}`** — fondo de cabecera sticky de tabla; idéntico al fondo de página para que "flote" solo por el borde inferior y una sombra sutil al hacer scroll.
- **Regla de contraste para texto pequeño** (`{colors.meta-text-rule}`): por debajo de 18px el tono mínimo es `--erpify-text-muted` (#62666d, ~5.9:1 AA ✓). `--erpify-text-subtle` (#8a8f98, ~3.4:1) y `text-faint` quedan **prohibidos en texto funcional pequeño** — solo ≥18px o elementos no textuales. Esto corrige el hallazgo A1 de la auditoría.

Evitar: codificar entidades por color, gradientes, más acentos. `--erpify-danger` solo para acciones destructivas; `--erpify-success` solo en StatusBadge y confirmaciones.

## Typography

Geist + Geist Mono, ramp heredada. Roles de superficie de datos:

- **`table-cell` (13px)** es el cuerpo de las listas en ambas densidades — la densidad cambia el padding, nunca el tamaño de letra (cambiarlo rompería la memoria espacial del usuario).
- **`entity-code` (Geist Mono 12px, uppercase, medium)** para `shortName` y cualquier código único. El mono da ancho predecible y distingue al instante "código" de "nombre" — es el ancla de escaneo de la columna izquierda.
- **`card-title` (14px semibold, clamp 2 líneas)** — el único texto que puede ocupar dos líneas en superficies de lista, y siempre con altura reservada para dos.
- **`detail-h1` (20/24px semibold)** sin clamp: el detalle es el hogar canónico del valor completo y su título envuelve el nombre íntegro, las líneas que haga falta (decisión 2026-06-04 — revierte el clamp-2 inicial). El campo Name de la ficha lo repite como dato estructurado.
- **`meta` (12px)** para timestamps relativos y conteos, siempre en `text-muted` como mínimo (ver regla de contraste).

Jamás: texto multilínea en celdas de tabla; uppercase en texto proporcional largo; tamaños por debajo de 11px.

## Layout & Spacing

Escala Tailwind heredada. Lo nuevo son los **presupuestos de contención** del frontmatter, que son contrato, no sugerencia:

- Lista (tabla o grid) limitada a `{spacing.list-max-w}` (1440px) y centrada — por encima de eso el viaje ocular entre Name y Updated destruye el escaneo. Sustituye al `2xl:max-w-[120rem]` actual.
- Tabla con `table-layout: fixed`; anchos por columna en `{spacing.col-*}`. **Name es la única columna flexible**: recibe el espacio restante, nunca menos de `{spacing.col-name-min}`, y trunca a una línea. Así un nombre de 255 caracteres es incapaz de mover un solo pixel del resto (corrige T1).
- Grid de tarjetas: `repeat(auto-fill, minmax({spacing.card-min-w}, 1fr))` con filas de altura igual (`auto-rows-fr`). 1→2→3→4 columnas según breakpoint (se mantiene el mapping actual).
- Densidad: dos modos, compact (`{spacing.row-h-compact}`) y comfortable (`{spacing.row-h-comfortable}`), conmutables y persistidos. Compact es el default `[ASSUMPTION]`.

## Elevation & Depth

Sombras `--erpify-shadow-*` heredadas, con uso mínimo: shadow-1 en hover de tarjeta, shadow-2 bajo la cabecera sticky **solo cuando hay scroll** (señal de "hay contenido arriba"), shadow-3+ reservadas a popovers/dialogs de Shadcn. La jerarquía en superficies de datos se construye con tono y borde, no con elevación.

## Shapes

Radios heredados: `{rounded.row}` (6px) en filas focusables y controles, `{rounded.card}` (8px) en tarjetas de entidad, pill solo en `status-badge`. Sin cambios de lenguaje de forma — la nitidez actual ya lee "herramienta".

## Components

Primitivas Shadcn y componentes `erpify/` existentes son la base; aquí solo el delta visual. Ilustración 1:1 de los presupuestos aplicados: [`mockups/key-banks-table.html`](mockups/key-banks-table.html) y [`mockups/key-banks-cards.html`](mockups/key-banks-cards.html) — este documento gana en conflicto.

- **Tabla de entidades** (`{components.table}`) — cabecera sticky de `{spacing.table-header-h}` en `table-header` 12px medium `text-muted`, fondo `{colors.surface-header}`; indicador de orden (▲/▼ 12px) junto al label; `table-layout: fixed` y anchos de `{spacing.col-*}`.
- **Fila** (`{components.table-row}`) — 36/44px según densidad; hover `{colors.surface-row-hover}`; seleccionada `{colors.surface-row-selected}` + checkbox marcado; focus ring inset 2px `--erpify-focus-ring`. Celdas con `truncate` y tooltip-si-truncado.
- **Tarjeta de entidad** (`{components.entity-card}`) — regiones fijas de arriba a abajo: (1) **zona de controles** — checkbox (siempre visible) + código `entity-code` + acciones; (2) título `card-title` con clamp 2 y **altura reservada de 2 líneas aunque ocupe 1**, a todo el ancho; (3) StatusBadge (aquí vive también el badge de recencia "New"); (4) footer de meta anclado al fondo. Controles y datos nunca comparten fila; alturas iguales por construcción, no por suerte.
- **StatusBadge** (`{components.status-badge}`) — 20px de alto, dot 6px + label 11px. Estilo dot-first (Linear): el punto da el color, el texto da el significado. **Cláusula de contraste:** la etiqueta es siempre neutra (`{components.status-badge.labelColor}`, ≥4.5:1 sobre blanco y sobre `{colors.surface-row-selected}`); el matiz vive únicamente en el dot, con rellenos `{colors.status-dot-success}`/`{colors.status-dot-warning}` validados ≥3:1. El StatusBadge heredado (texto coloreado sobre tinte del mismo matiz, 2.19–3.90:1) **incumple AA y no se conserva tal cual** — hallazgo C1/C2 del gate de accesibilidad.
- **Acciones de fila/tarjeta** (`{components.row-action-button}`) — botones icono 28/32px, iconos lucide 16px. En reposo solo es visible **⋯**; copiar/editar se revelan en hover/focus-within (touch: todo visible, comportamiento actual conservado).
- **Tooltip de valor completo** (`{components.tooltip-full-value}`) — máx. 360px de ancho, multilínea, 12px; aparece **solo cuando hay truncado real** y también con focus de teclado. Sustituye los `title=` nativos en celdas truncadas.
- **Toast** — descripción limitada a `{spacing.toast-desc-lines}` líneas con clamp; los nombres largos truncan en el toast porque el lugar del valor completo es el detalle. En errores de mutación es señal transitoria: el lugar de lectura es el error persistente.
- **Error persistente de mutación** (`mutation-error`) — hereda el lenguaje visual del `ProblemDisplay` existente (tono danger) sin delta de identidad; ocupa el ancho del contenedor del origen (tabla/grid, formulario, cabecera de detalle) y se pega sobre él, como `{components.bulk-bar}` respecto a la tabla `[ASSUMPTION]`. Añade: dismiss × (`{components.row-action-button}`) y botonera de copia (mensaje · type+status · correlation id · JSON íntegro) como Buttons ghost de Shadcn con icono copy 16px `[ASSUMPTION]`; el bloque `debug` conserva su render env-aware actual. El title/detail del problem **no clampa** — esta superficie existe para leer el error completo; quien clampa es el toast.
- **Celda truncable** (`truncated-cell`) — par visual de `{components.table-row}` + `{components.tooltip-full-value}`: una línea, ellipsis nativa, sin máscara de degradado. (Nombre compartido con EXPERIENCE.md.Component Patterns.)
- **Barra de selección** (`{components.bulk-bar}`) — banda elevada del ancho exacto de la tabla, pegada a su borde superior; recuento en 13px medium `--erpify-text`; Clear como Button ghost de Shadcn; Delete como Button destructive (`--erpify-danger`).
- **Toggles de densidad y de vista** — segmented de botones icono 32px; heredan Shadcn `Button` ghost sin delta; el segmento activo usa `{colors.surface-row-selected}` con icono `--erpify-brand`.
- **Filtros (toolbar)** — heredan Shadcn `Button` outline + el panel colapsable existente sin delta visual; el badge contador de filtros activos reutiliza `{components.status-badge}` en variante neutra.
- **Confirm destructivo** — hereda Shadcn `Dialog` sin delta visual; botón primario en `--erpify-danger`. Ya no aloja errores: al fallar la mutación o su precondición, el dialog se cierra y el error vive en `mutation-error` (conducta en EXPERIENCE.md, decisión 2026-06-04).
- **Campo Name del formulario** (`{components.form-name-field}`) — textarea auto-grow con aspecto de input: una línea que crece con el contenido hasta mostrar todo lo escrito (móvil incluido); contador `n/255` en `meta` 12px `text-muted` alineado bajo el campo, visible desde el 80% del límite. Short name permanece input de 1 línea.

## Do's and Don'ts

| Do | Don't |
|---|---|
| Todo texto externo vive en un presupuesto: columna fija, clamp, o altura reservada | Dejar que un dato decida el tamaño de su contenedor (`table-layout: auto`, wrap sin clamp) |
| `truncate` a 1 línea en celdas; clamp 2 en títulos de tarjeta; valor completo via tooltip focusable + detalle | Multilínea en filas de tabla, tooltips solo-hover via `title`, "…" sin acceso al valor |
| Mono uppercase para códigos; 13px en celdas en ambas densidades | Cambiar tamaño de letra entre densidades; uppercase en nombres largos |
| `text-muted` como suelo de contraste bajo 18px | `text-subtle`/`text-faint` en meta de 12px (falla AA) |
| Hover y selección con tonos distintos + señal no-cromática | Selección solo por color; hover con alphas que ensucian el reveal |
| Heredar Shadcn y `--erpify-*`; este doc solo añade contención | Crear un segundo sistema paralelo de tokens o forks de primitivas |
| Errores de mutación legibles y copiables en superficie persistente, sin clamp | Errores solo en toast o embebidos en un dialog |
