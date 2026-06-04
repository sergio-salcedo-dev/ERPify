# Implementación Tailwind 4 — recetas por componente

Companion técnico de `DESIGN.md` + `EXPERIENCE.md` (las spines ganan en conflicto). Contexto: Tailwind 4 CSS-first (`@theme` en `globals.css`, sin `tailwind.config.js`), Shadcn, `cn()`, BEM en clases custom, Next 16 App Router.

## 1. Tabla — contención de columnas (corrige T1)

`table-layout: fixed` + anchos explícitos. Con `w-full table-fixed`, los `w-*`/`max-w-*` de `<th>`/`<col>` se vuelven deterministas y el `truncate` de las celdas por fin actúa.

```tsx
<div className="banks-table relative max-w-[90rem]">       {/* list-max-w */}
  <table className="w-full table-fixed">
    <colgroup>
      <col className="w-10" />          {/* select 40px */}
      <col className="w-28" />          {/* code 112px */}
      <col />                            {/* name: flexible, el resto */}
      <col className="w-24" />          {/* status 96px */}
      <col className="w-32 max-md:hidden" />  {/* updated 128px */}
      <col className="w-24" />          {/* actions 96px */}
    </colgroup>
    …
```

- Celda nombre: `<td className="min-w-0"><span className="block truncate">{name}</span></td>` — con `table-fixed` el `min-w-[240px]` de name se garantiza vía el ancho mínimo del contenedor, no de la col.
- **Nunca** `whitespace-nowrap` sin `truncate` en celdas de texto externo.

## 2. Cabecera sticky con sombra-al-scroll (corrige T7)

```tsx
<thead className="banks-table__head sticky top-0 z-10 bg-background">
```

Sombra solo con contenido desplazado — sin JS, con scroll-driven animation (progressive enhancement; los navegadores sin soporte simplemente no muestran la sombra):

```css
/* globals.css */
@supports (animation-timeline: scroll()) {
  .banks-table__head {
    animation: table-head-shadow linear both;
    animation-timeline: scroll();
    animation-range: 0 24px;
  }
  @keyframes table-head-shadow {
    to { box-shadow: var(--erpify-shadow-2); }
  }
}
```

## 3. Filas y densidad (toggle compact/comfortable)

`DataTable` ya acepta `density`; se deja de hardcodear y se conecta a un store persistido:

```tsx
// h-9 = 36px compact · h-11 = 44px comfortable; la letra NO cambia (text-sm en ambas)
<tr className={cn(
  'banks-table__row cursor-pointer text-sm',
  density === 'compact' ? 'h-9' : 'h-11',
  'hover:bg-[--erpify-bg-muted]',
  'aria-selected:bg-[--erpify-row-selected]',          // definir token en @theme
  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring',
)}>
```

Persistencia: `localStorage` con clave compartida (`erpify.list.density`) leída en un hook `useListPreferences()` — misma clave para vista tabla/tarjetas; SSR-safe (default compact hasta hidratar, sin layout shift porque solo cambia padding).

## 4. Tarjetas — alturas iguales y clamp (corrige C1/C2/C3)

```tsx
<ul className="grid auto-rows-fr grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
  <li className="h-full">
    <Card className="entity-card flex h-full flex-col">
      <CardHeader className="entity-card__header flex-row items-center gap-2">
        <Checkbox … />
        <span className="entity-card__code truncate font-mono text-xs font-medium uppercase">{shortName}</span>
        <div className="entity-card__actions ml-auto …">⧉ ✎ ⋯</div>
      </CardHeader>
      {/* título: clamp 2 + ALTURA RESERVADA de 2 líneas aunque ocupe 1 */}
      <CardTitle className="entity-card__title line-clamp-2 min-h-[2.7em] text-sm leading-[1.35] font-semibold">
        {name}
      </CardTitle>
      <StatusBadge … />
      <CardFooter className="entity-card__footer mt-auto border-t pt-2 text-xs text-muted-foreground">
        Updated {relative}
      </CardFooter>
    </Card>
  </li>
</ul>
```

Claves: `auto-rows-fr` + `h-full flex-col` + `mt-auto` en el footer = igualdad por construcción. `min-h-[2.7em]` (2 líneas × 1.35) evita que tarjetas de 1 línea encojan la región del título.

Nota overlay: el link-overlay actual (`after:absolute after:inset-0`) se conserva, pero checkbox y acciones necesitan `relative z-10` para quedar por encima.

## 5. Tooltip-si-truncado (corrige T2/T3/C5/A2)

**Prerequisito:** no existe `components/ui/tooltip.tsx` — instalar la primitiva Shadcn Tooltip y montar `TooltipProvider` con `delayDuration={200}` y `disableHoverableContent={false}` (config fijada por la spine; el default de Radix son 700ms).

Hook + Tooltip de Shadcn (Radix). Solo monta el tooltip cuando hay truncado real. **Disparadores: hover de la celda y foco de FILA** — los spans truncados NO reciben `tabIndex` (hallazgo H4 del gate: un tab order dependiente del viewport/zoom y spans focusables sin rol son anti-patrones). En touch, Radix Tooltip no se abre: la ruta declarada al valor completo es el detalle (un tap en la fila). Cumple WCAG 1.4.13 (hoverable, Esc cierra, persistente).

```tsx
function useIsTruncated<T extends HTMLElement>() {
  const ref = useRef<T>(null);
  const [truncated, setTruncated] = useState(false);
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    const check = () => setTruncated(el.scrollWidth > el.clientWidth || el.scrollHeight > el.clientHeight);
    check();
    const ro = new ResizeObserver(check);
    ro.observe(el);
    return () => ro.disconnect();
  }, []);
  return { ref, truncated };
}
```

```tsx
const CLAMP_CLASS = { 1: 'truncate', 2: 'line-clamp-2' } as const; // clases estáticas: Tailwind no genera template literals

export function TruncatedText({ value, lines = 1, open }: TruncatedTextProps) {
  // `open` lo controla la fila: hover de celda o foco de fila (focus-within del <tr>).
  // El span NUNCA recibe tabIndex — la fila es el único tab stop (gate H4).
  const { ref, truncated } = useIsTruncated<HTMLSpanElement>();
  const text = (
    <span ref={ref} className={cn('block', CLAMP_CLASS[lines])}>{value}</span>
  );
  if (!truncated) return text;
  return (
    <Tooltip open={open === undefined ? undefined : open}>
      <TooltipTrigger asChild>{text}</TooltipTrigger>
      <TooltipContent className="max-w-[360px] whitespace-pre-wrap break-words text-xs">{value}</TooltipContent>
    </Tooltip>
  );
}
```

En la tabla, el `<tr>` (ya focusable por DataTable) pasa `open={rowFocused && truncated ? true : undefined}` para abrir el tooltip del nombre al focar la fila con teclado; con ratón basta el trigger por hover. Sustituye los `title=` de celdas truncadas (los `title` de timestamps pueden quedarse: no truncan).

## 6. Detalle y toast (corrige D1/D2)

```tsx
<h1 className="banks-detail__name line-clamp-2 text-xl font-semibold tracking-tight break-words sm:text-2xl"
    title={undefined /* el valor completo está en la ficha NAME debajo */}>
  {bank.name}
</h1>
```

```tsx
// adapter del toastNotifier (Sonner): clamp en la descripción
toast.success(label, { description, classNames: { description: 'line-clamp-2 break-words' } });
```

## 7. Tokens nuevos en `@theme` (globals.css)

```css
@theme inline {
  --erpify-row-selected: #eef0fb;       /* DESIGN.md colors.surface-row-selected */
  --erpify-status-dot-success: #0f7a5a; /* dot ≥3:1 sobre blanco y row-selected (el semántico #10b981 da 2.54:1) */
  --erpify-status-dot-warning: #b45309; /* ídem (#d97706 da 3.19:1, marginal) */
}
```

`surface-row-hover` y `surface-header` ya existen como `--erpify-bg-muted` / `--erpify-bg` — referenciar, no duplicar.

## 7b. StatusBadge — cláusula de contraste (corrige C1/C2 del gate)

El `StatusBadge.tsx` actual (`text-success` sobre `bg-success/15`) mide 2.19:1 — falla 1.4.3. Nueva anatomía:

```tsx
<span className="status-badge inline-flex h-5 items-center gap-1.5 rounded-full bg-muted px-2 text-[11px] font-medium text-muted-foreground">
  <span aria-hidden="true" className="size-1.5 rounded-full bg-[--erpify-status-dot-success]" />
  New
</span>
```

Etiqueta SIEMPRE neutra (`text-muted-foreground`, 5.77:1 ✓); el matiz vive solo en el dot con los tokens `--erpify-status-dot-*` (≥3:1 ✓). Danger/info pueden usar sus semánticos como dot (4.83/4.70:1 ✓).

## 7c. Header checkbox tri-state (corrige H2 del gate)

El checkbox nativo no deriva `aria-checked="mixed"` de `checked` — hay que fijar la propiedad DOM:

```tsx
const headerRef = useRef<HTMLInputElement>(null);
useEffect(() => {
  if (headerRef.current) headerRef.current.indeterminate = someSelected && !allSelected;
}, [someSelected, allSelected]);
```

(Alternativa: instalar Shadcn `Checkbox` y pasar `checked="indeterminate"`.) Criterio de aceptación: con 0 < seleccionadas < total, el header expone `aria-checked="mixed"`.

## 7d. Gestión de foco en mutaciones (corrige H3 del gate)

- Borrado optimista de la fila activa: antes de retirarla del DOM, mover foco a la fila siguiente (anterior si era la última; contenedor/empty-state si no quedan) y dejar que la región viva anuncie el cambio.
- "Refresh list" dentro del confirm: el dialog no se cierra; tras recalcular, foco al botón Delete rehabilitado (o al ProblemDisplay si persiste el fallo).

## 8. Reduced motion

Cobertura completa (el gate M2 cazó que la sombra scroll-driven y sheet/dialog quedaban fuera). La forma robusta: la animación de la cabecera se declara solo bajo `no-preference`, y el resto se neutraliza bajo `reduce`:

```css
@media (prefers-reduced-motion: no-preference) {
  @supports (animation-timeline: scroll()) {
    .banks-table__head { animation: table-head-shadow linear both; animation-timeline: scroll(); animation-range: 0 24px; }
  }
}
@media (prefers-reduced-motion: reduce) {
  .entity-card__actions, .banks-table__row-actions { transition: none; }
  [data-slot='sheet-content'], [data-slot='dialog-content'] { animation: none; transition: none; }
}
```

(El bloque de §2 queda envuelto por el gate `no-preference`.) Sonner: **verificar** el manejo real de `prefers-reduced-motion` en la versión instalada — no asumirlo.

## 9. Contraste de meta (corrige A1)

Buscar usos de `text-[--erpify-text-subtle]`/`text-faint` (o sus utilidades mapeadas) en texto < 18px y sustituir por `text-muted-foreground` (#62666d). Regla candidata a ESLint `no-restricted-syntax` si reincide.

## 10. Plan de PRs sugerido (no vinculante)

1. `fix(pwa)`: contención — table-fixed + colgroup, clamp tarjeta/H1/toast, min-h título. *(corrige lo 🔴 con diff mínimo)*
2. `fix(pwa)`: contraste — StatusBadge etiqueta neutra + dots oscurecidos (C1/C2) y meta `text-subtle`→`text-muted` (A1).
3. `feat(pwa)`: Tooltip primitivo + `TruncatedText` + `useIsTruncated` (disparo por fila, sin tabIndex en spans) + sustitución de `title=` en celdas.
4. `feat(pwa)`: densidad conmutable + persistencia + sticky header (con gate reduced-motion).
5. `feat(pwa)`: jerarquía de columnas (Code primero, Updated relativo, Created→xl+, ⋯ en reposo) + barra selección (live region siempre montada, anuncios coalescidos) + tri-state + gestión de foco en mutaciones.
6. E2E de regresión: nombre de 255 chars en tabla/tarjeta/detalle/toast — asserts de altura de fila constante, igualdad de alturas de tarjeta, y `aria-checked="mixed"` en el header con selección parcial.
