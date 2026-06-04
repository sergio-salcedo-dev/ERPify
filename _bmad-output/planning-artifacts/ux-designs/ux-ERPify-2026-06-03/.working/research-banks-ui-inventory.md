# Inventario factual — implementación actual del módulo Banks (subagente, 2026-06-03)

Datos extraídos del código real (`pwa/`, `api/`). Sin opiniones de diseño. Fundamenta las spines: DESIGN.md referencia los tokens reales; EXPERIENCE.md especifica el delta conductual sobre lo que ya existe.

## 1. Rutas y componentes

- `/backoffice/banks` (lista tabla/tarjetas) · `/backoffice/banks/new` · `/backoffice/banks/[id]` (detalle) · `/backoffice/banks/[id]/edit`
- Página de lista: `pwa/src/app/backoffice/banks/page.tsx:62` — estado, filtros, paginación, realtime Mercure.
- Tabla: `_components/BanksTable.tsx:106` (sobre `DataTable` genérico) · Tarjetas: `_components/BanksCards.tsx:28` · Filtros: `_components/BanksFilters.tsx:45` · Barra masiva: `_components/BanksBulkBar.tsx:30` ("N selected" + Clear + Delete) · Paginación: `_components/BanksPagination.tsx:16` · Toggle vistas: `_components/BanksViewToggle.tsx:25` · Formulario: `_components/BankForm.tsx:48` · Acciones fila: `_components/BankRowActions.tsx:39` · Vacío filtrado: `_components/BanksEmptyFiltered.tsx:16` · Detalle: `[id]/page.tsx:45` · Skeleton: `_components/BanksListSkeleton.tsx`.

## 2. Tokens actuales (`pwa/src/app/globals.css`, @theme inline)

- Colores semánticos `--erpify-*`: bg/bg-muted/bg-subtle/bg-elevated; text/text-muted/text-subtle/text-faint/text-on-accent; border-subtle/border/border-strong/line-tint; brand `#5e6ad2`, accent `#7170ff` (+hover/active), focus-ring `#7170ff`; success `#10b981`, warning `#d97706`, danger `#dc2626`; overlay rgba(8,9,10,.45).
- Sombras `--erpify-shadow-1..5` + inset.
- Tipografía (ramp estilo Linear): 2xs 11px · xs 12px · sm 13px · base 14px (body) · md 16px · lg 18px · xl 20px · 2xl 24px · 3xl 30px · 4xl–6xl display.
- Radios: micro 2px · sm 4px · md 6px (control default) · lg 8px · xl 12px · 2xl 22px · 3xl 24px.
- Fuentes: Geist + Geist Mono. Dark mode ya definido en tokens (valores inversos).

## 3. Primitivas disponibles

- Shadcn `components/ui/`: button, card, dialog, dropdown-menu, input, label, sheet, tabs.
- ERPify `components/erpify/`: **DataTable** (sort, selección, navegación teclado, prop `density="compact"|"comfortable"`), StatusBadge, **CopyButton**, EmptyState, ProblemDisplay, CorrelationIdChip, FormField, DateField/DatePickerField, Spinner, AsyncBoundary, AppShell, RecordSheet, StatCard…
- Banks usa: DataTable, Card*, StatusBadge, Button, Input, Dialog*, DropdownMenu*, CopyButton, DatePickerField, FormField, EmptyState, ProblemDisplay, AsyncBoundary, CorrelationIdChip, Spinner.

## 4. Renderizado actual del texto largo (clases literales)

- **Name en tabla** (`BanksTable.tsx:28-39`): `flex min-w-0 items-center gap-2.5` + `<span class="min-w-0 truncate">` — truncado de 1 línea SÍ existe; celda `min-w-0` **sin max-width** → con `table-layout: auto` la columna sigue creciendo (causa del desborde de la captura).
- **ShortName en tabla** (`:22-25`): `block truncate font-mono text-xs uppercase` + `title={row.shortName}`; celda `max-w-[8rem] truncate`.
- **Título de tarjeta** (`BanksCards.tsx:74-82`): Link con `[overflow-wrap:anywhere] hover:underline … after:absolute after:inset-0` (overlay clicable a toda la tarjeta) — **sin clamp ni truncado** → tarjetas de altura libre (causa del colapso).
- **H1 detalle** (`[id]/page.tsx:206-211`): `text-xl font-semibold tracking-tight break-words sm:text-2xl` — sin clamp.
- **Toast éxito** (`BankForm.tsx:95,107`): `toastNotifier.success("Bank created", { description: created.name })` — Sonner; descripción sin límite.

## 5. Estados existentes

- Tabla: hover `hover:bg-muted/30 cursor-pointer`; seleccionada `bg-accent/40`; focus `focus-visible:ring-2 focus-visible:outline-none`; teclado ↑/↓ navega, Enter activa, Space selecciona (DataTable.tsx:355-381); ARIA: `aria-sort`, `aria-selected`, `scope="col"`, caption sr-only, `aria-label="Select row {id}"`.
- Tarjetas: hover `hover:shadow-elevation-1 hover:ring-foreground/20`; seleccionada `ring-2 ring-primary`; checkbox y acciones reveladas en hover/focus (`opacity-0 group-hover/card:opacity-100 group-focus-within/card:opacity-100 [@media(hover:none)]:opacity-100`).

## 6. Patrones ya presentes

- Truncado + `title` tooltip: shortName y timestamps (name de tarjeta NO tiene tooltip).
- Densidad: `DataTable` ya acepta `density`, pero Banks la hardcodea a `"compact"` (BanksTable.tsx:124). No hay toggle de usuario.
- CopyButton y safeHref: existen y se usan en todas las rutas dinámicas de Banks.

## 7. Límites de longitud (validación real)

- `name`: max **255** (Zod `.max(255)` en `BankSchema.ts` ↔ `Assert\Length(max: 255)` + VARCHAR(255) en `Bank.php:35-55`).
- `shortName`: max **50**, único, normalizado a ASCII UPPER (`NormalizedText::toAsciiUpper()`).

## 8. Responsive actual

- Tabla: `overflow-x-auto` en móvil; Created oculta hasta `md:`, Updated hasta `lg:`; ancho máximo lista `2xl:max-w-[120rem]`.
- Grid tarjetas: 1 → `sm:2` → `lg:3` → `2xl:4` columnas.
- Acciones de tarjeta siempre visibles en touch (`[@media(hover:none)]`).

## Síntesis para el rediseño

1. El problema de la tabla NO es ausencia de `truncate` — es ausencia de **restricción de ancho de columna** (`table-layout: auto` + celda sin `max-w`).
2. La tarjeta no tiene clamp: `overflow-wrap:anywhere` envuelve ilimitadamente.
3. Infraestructura aprovechable: DataTable con densidad/teclado/ARIA, CopyButton, StatusBadge, tooltips via `title`, tokens `--erpify-*` ya estilo Linear, dark mode ya tokenizado.
4. Límites de datos conocidos (255/50) → el "peor caso" de diseño es acotable y testeable.
