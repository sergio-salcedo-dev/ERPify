# Validation Report — ERPify (Backoffice — listas de entidades)

- **DESIGN.md:** `_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-06-03/DESIGN.md`
- **EXPERIENCE.md:** `_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-06-03/EXPERIENCE.md`
- **Run at:** 2026-06-03T21:34:24+02:00

## Overall verdict

El par de spines es un contrato sólido y extraíble: cada hallazgo del audit tiene respuesta nombrada, los presupuestos de contención son números (no prosa), los flujos tienen protagonista/clímax/fallo, y la disciplina de herencia (Shadcn + `--erpify-*`) está bien declarada. Lo que impedía el "strong" limpio era un puñado de roturas mecánicas de referencia y un hueco de cobertura: cinco componentes con reglas conductuales sin spec visual.

La lente de accesibilidad desplazó el cuadro de forma material: el contrato de contención es sano y la mayoría de afirmaciones AA verifican por cálculo, pero las spines heredaban en silencio **dos defectos de contraste bloqueantes** del StatusBadge "conservado" (etiqueta 2.19–3.90:1 vs 4.5:1; dot success 2.54:1 vs 3:1), más cuatro huecos de especificación de severidad alta (tooltip sin ruta touch, tri-state sin camino de implementación, foco tras mutaciones sin destino, tabIndex condicional anti-patrón).

**Resolución:** los 2 critical, los 7 high y los medium/low de arreglo directo fueron resueltos en las spines y companions en este mismo Finalize (2026-06-03) — cláusula de contraste del badge con tokens `status-dot-*`, tooltip disparado por foco de fila con ruta touch declarada, recetas de tri-state y gestión de foco, `meta-text-rule` bajo `colors`, sintaxis de refs normalizada y filas visuales para los cinco componentes. Quedan como seguimiento los low aceptados (contrato de teclado de la vista apilada `<md`, verificación de Sonner).

## Category verdicts

- Flow coverage — **strong**
- Token completeness — **adequate** → resuelto a strong tras fixes
- Component coverage — **thin** → resuelto a adequate/strong tras fixes
- State coverage — **strong**
- Visual reference coverage — **strong**
- Bloat & overspecification — **strong**
- Inheritance discipline — **adequate** → resuelto tras fixes
- Shape fit — **strong**

## Findings by severity

### Critical (2) — ambos resueltos

**[Accessibility] C1 — StatusBadge heredado: etiqueta falla 1.4.3** (DESIGN.md `status-badge` + EXPERIENCE.md "conservado")
Etiqueta coloreada sobre tinte del mismo matiz: success 2.19:1 · warning 2.72:1 · danger 3.81:1 · info 3.90:1 (peor sobre `#eef0fb`). El suelo para texto de 11px es 4.5:1.
Fix: etiqueta siempre neutra (`labelColor: --erpify-text-muted`, 5.77:1) y matiz solo en el dot. **RESUELTO** — cláusula de contraste añadida a `DESIGN.md.status-badge` + receta §7b.

**[Accessibility] C2 — Dot de estado falla 1.4.11** (DESIGN.md `status-badge.dotSize`)
success #10b981 = 2.54:1 y warning #d97706 = 3.19:1 contra blanco (peor sobre tinte de selección); suelo 3:1 para objetos gráficos.
Fix: rellenos oscurecidos `status-dot-success #0f7a5a` / `status-dot-warning #b45309` validados ≥3:1. **RESUELTO** — tokens añadidos; mocks parcheados.

### High (7) — todos resueltos

**[Accessibility] H1 — Tooltip-si-truncado: sin ruta en touch y config sin fijar (1.4.13)** — Radix Tooltip no se abre con tap y su delay default es 700ms; además la primitiva ni siquiera está instalada. **RESUELTO**: ruta touch declarada (detalle a un tap), `delayDuration 200ms` + `disableHoverableContent={false}` fijados, prerequisito documentado.

**[Accessibility] H2 — Tri-state `aria-checked="mixed"` sin camino de implementación (4.1.2)** — el checkbox nativo no deriva `mixed` de `checked`. **RESUELTO**: receta `.indeterminate` / Radix Checkbox + criterio de aceptación (§7c).

**[Accessibility] H3 — Destino del foco tras borrado optimista y "Refresh list" sin especificar (2.4.3)** — **RESUELTO**: regla añadida al Accessibility Floor (fila siguiente/anterior/contenedor; dialog abierto con foco al Delete rehabilitado) + receta §7d.

**[Accessibility] H4 — `tabIndex` condicional en spans truncados (2.4.3/4.1.2)** — orden de tab dependiente de viewport/zoom + focusables sin rol. **RESUELTO**: el tooltip se dispara por foco de FILA; los spans no reciben tabIndex (spine + receta §5 reescritas).

**[Rubric §2] `colors.meta-text-rule` no resolvía** — clave raíz citada como `colors.*`. **RESUELTO**: movida bajo `colors:`, tres menciones unificadas.

**[Rubric §3] Cinco componentes sin spec visual** (Barra de selección, Toggle densidad, Toggle de vista, Filtros, Confirm destructivo). **RESUELTO**: filas añadidas a DESIGN.md.Components (+ token `bulk-bar`), declarando herencia Shadcn donde no hay delta.

**[Rubric §7] `{design: components.table}` sintaxis no estándar** — **RESUELTO**: normalizada a `{components.table}`.

### Medium (8) — resueltos salvo los marcados

**[Accessibility] M1 — Focus ring margen justo sobre tinte de selección (3.38:1, pasa 1.4.11)** — RESUELTO como guardia: comentario de re-validación en el token. Nota: el revisor corrige la premisa — 2.4.13 Focus Appearance es AAA, no AA.
**[Accessibility] M2 — Reduced-motion no cubría la sombra scroll-driven ni sheet/dialog** — RESUELTO: bloque §8 reescrito con gate `no-preference`; verificación de Sonner pendiente (follow-up).
**[Accessibility] M3 — Guardrail del H1: nombre accesible íntegro** — RESUELTO: "prohibido truncar en JS" en la spine.
**[Accessibility] M4 — Live region: montaje permanente + coalescencia de anuncios** — RESUELTO en Component Patterns.
**[Rubric §2] focusRing como CSS literal** — RESUELTO: documentado como herencia nombrada intencional.
**[Rubric §3] "Celda truncable" sin nombre homónimo en DESIGN** — RESUELTO: fila `truncated-cell` añadida.
**[Rubric §3] StatusBadge sin fila conductual en EXPERIENCE** — RESUELTO: fila añadida (presentacional).
**[Rubric §7] Nomenclatura no idéntica entre spines** — RESUELTO vía las dos filas anteriores.

### Low (15) — resueltos los de arreglo directo; aceptados los señalados

Resueltos: artefacto `�` en Voice and Tone; Vercel ubicado en Inspiration; nota crear/editar al cierre de Key Flows; skeleton de tarjetas; permisos-por-rol como silencio consciente; claim "claramente distinguible" suavizado (L1, delta de luminancia 1.03:1); precedencia de Esc (L3).
Aceptados como follow-up: contrato de teclado de la vista apilada `<md` (L2, marcado en spine); decisión ASCII-vs-mocks cuando maduren los mockups; `name` no idéntico entre spines (intencional: EXPERIENCE acota el alcance); RecordSheet sin fila de componente (v2).

## Reviewer files

- `review-rubric.md` — rubric walker (8 categorías)
- `review-accessibility.md` — lente adversarial WCAG 2.2 AA (ratios calculados; incluye "Verified claims" y dos correcciones de premisa)
