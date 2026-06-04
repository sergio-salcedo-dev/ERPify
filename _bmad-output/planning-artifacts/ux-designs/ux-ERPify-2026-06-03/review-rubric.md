# Spine Pair Review — ERPify

## Overall verdict

El par de spines es un contrato sólido y extraíble: cada hallazgo del audit tiene respuesta nombrada, los presupuestos de contención son números (no prosa), los flujos tienen protagonista/clímax/fallo, y la disciplina de herencia (Shadcn + `--erpify-*`) está bien declarada. Lo que impide el "strong" limpio es un puñado de roturas mecánicas de referencia (`colors.meta-text-rule` no resuelve; `{design: components.table}` usa una sintaxis no estándar) y un hueco real de cobertura de componentes: cinco componentes con reglas conductuales en EXPERIENCE.md (Barra de selección, Toggle densidad, Toggle de vista, Filtros, Confirm destructivo) no tienen fila de spec visual en DESIGN.md.Components. Un consumidor downstream puede arrancar, pero tropezaría con esas referencias rotas y tendría que inventar el visual de esos cinco componentes.

## 1. Flow coverage — strong

Se extrajo del Intake del decision log cada entregable pedido (auditoría, rediseño tabla, rediseño tarjetas, estrategia de texto largo, jerarquía de entidad, diseño de interacción, design system, a11y/responsive, recetas Tailwind) y se verificó contra EXPERIENCE.md. Los dos Key Flows tienen protagonista nombrado (Marta, contable; Andrés, back-office), pasos numerados, beat de clímax explícito ("**Clímax:**") y ruta de fallo ("Fallo:"). Cada entregable del intake aparece como sección dedicada o tabla.

### Findings
- **low** El intake pide explícitamente flujos de *operaciones masivas* y de *navegación por teclado* como concerns de primer orden; ambos están cubiertos por los dos flujos existentes (Flujo 1 = teclado puro; Flujo 2 = selección masiva + borrado). No falta nada, pero no hay flujo de *crear/editar* pese a que la IA lista "Crear / Editar" como superficie y la jerarquía insiste en "validación Zod, sin truncado silencioso" (EXPERIENCE.md IA + Component Patterns "Confirm destructivo"). *Fix:* opcional — un mini-flujo de creación que cierre el lazo con el toast clamp-2 ya especificado, o una nota de que crear/editar hereda el contrato de formulario existente sin delta conductual.

## 2. Token completeness — adequate

Se extrajo cada token del frontmatter YAML de DESIGN.md y cada referencia `{path.to.token}` de la prosa. Los colores funcionales nuevos (`surface-row-hover/-selected/-header`) llevan hex; los heredados `--erpify-*` se declaran como herencia nombrada (válido) en el bloque de comentarios. El diferido de dark mode está declarado explícitamente en el frontmatter (líneas 11) y en EXPERIENCE.md Foundation — el frontmatter lo deja claro, así que la ausencia de pares `-dark` **no** es critical aquí. Los contrastes estructurales declarados (`#62666d ≈5.9:1 AA`, `#8a8f98 ≈3.4:1`) están en `meta-text-rule` y en Colors.

### Findings
- **high** `meta-text-rule` es una clave **raíz** del frontmatter (DESIGN.md:15), pero se referencia como `colors.meta-text-rule` en dos sitios (typography.meta `note`, DESIGN.md:36; y el cuerpo de Colors la cita como `meta-text-rule` a secas en :108). La ruta `colors.meta-text-rule` **no resuelve** — el token vive en raíz, no bajo `colors`. *Fix:* mover `meta-text-rule` bajo `colors:` (y entonces `colors.meta-text-rule` resuelve), o dejarlo en raíz y citarlo como `{meta-text-rule}` consistentemente. Elegir una y unificar las tres menciones.
- **medium** Varios valores del frontmatter referencian tokens `--erpify-*` como literal-string en vez de como `{ref}`: `table-row.focusRing: '2px --erpify-focus-ring, inset'` (DESIGN.md:73) y `table-row.hover/selected` sí usan `{colors.*}`. Es herencia nombrada (válida por la regla del spec), pero el valor de `focusRing` mezcla CSS crudo con un nombre de token sin sintaxis de referencia — un resolver mecánico no lo flattenará. *Fix:* aceptable como herencia nombrada; si se quiere resoluble, exponer `--erpify-focus-ring` como token referenciable o documentar que estos strings son CSS literal intencional.
- **low** `meta-text-rule` y los presupuestos `*-lines` (`card-title-lines: '2'`, `detail-h1-lines: '2'`, `toast-desc-lines: '2'`) son tokens de comportamiento/regla colgados del bloque `colors`/`spacing`. Resuelven y se referencian bien (`{spacing.detail-h1-lines}` en :119), pero estiran la semántica de `spacing`. *Fix:* ninguno necesario; nota de forma, no de corrección.

## 3. Component coverage — thin

Se extrajo cada nombre de componente usado en cualquier sección de ambas spines y se cruzó: DESIGN.md.Components (spec visual) ↔ EXPERIENCE.md.Component Patterns (spec conductual). DESIGN.md cubre: table, table-row, entity-card, status-badge, row-action-button, tooltip-full-value, Toast — todos con reglas reales. El hueco está en sentido inverso.

### Findings
- **high** Cinco componentes con fila conductual en EXPERIENCE.md.Component Patterns **no tienen fila de spec visual** en DESIGN.md.Components: **Barra de selección** (EXPERIENCE.md:85), **Toggle densidad** (:86), **Toggle de vista** (:87), **Filtros** (:88) y **Confirm destructivo** (:90). Un consumidor de UI no sabe su anatomía, color, tamaño ni apariencia de estado desde DESIGN.md. *Fix:* añadir filas mínimas en DESIGN.md.Components para los cinco (al menos: alto, tipografía, color de superficie/borde, y para la barra de selección su anclaje al ancho de tabla), o declarar explícitamente "hereda Shadcn sin delta" como hace el ejemplo Drift con Button/Dialog.
- **medium** **Celda truncable** existe como componente conductual propio en EXPERIENCE.md (:82) pero en DESIGN.md su comportamiento visual se reparte entre `table-row` ("Celdas con truncate y tooltip-si-truncado") y `tooltip-full-value`. Resoluble, pero el nombre "Celda truncable" no aparece verbatim en DESIGN.md. *Fix:* o renombrar la fila de EXPERIENCE.md a la pareja `table-row`/`tooltip-full-value`, o añadir una fila `truncated-cell` en DESIGN.md para nombre-idéntico.
- **medium** **StatusBadge** tiene spec visual en DESIGN.md (`status-badge`, :84-88) pero **no tiene fila propia** en EXPERIENCE.md.Component Patterns; su conducta solo se infiere de "badge dot-first" en la Jerarquía (:57) y del wireframe. *Fix:* añadir fila conductual de StatusBadge (qué estados mapea, si es interactivo, cómo se anuncia) o declarar "sin conducta — puramente presentacional".
- **low** **Peek / RecordSheet** aparece en IA y en Interaction Primitives (`o`) como v2 opcional, sin fila de componente en ninguna spine. Defendible por estar marcado v2/`[ASSUMPTION]`. *Fix:* ninguno mientras siga v2.

## 4. State coverage — strong

Se recorrió cada superficie de la IA (Lista-tabla, Lista-tarjetas, Detalle, Peek, Crear/Editar) y se cruzó con EXPERIENCE.md.State Patterns. Cubre carga fría (skeleton respetando densidad/anchos), vacío absoluto, vacío filtrado, selección activa, borrado optimista + rollback, registro obsoleto en confirm, error de carga, realtime/Mercure y truncado. Foco está en Interaction Primitives + Accessibility Floor (ring inset 2px). Offline no se trata, pero es defendible: es una app B2B desktop de backoffice contra API, no offline-first; ningún source lo pide.

### Findings
- **low** No hay estado explícito de *permiso denegado* pese a ser producto B2B con roles plausibles (contable vs back-office vs jefe de obra). Ningún source lo exige y el alcance es "patrón de listas", no autorización. *Fix:* opcional — una línea "fuera de alcance: autorización por rol se trata en su propio contrato" para que el silencio sea consciente, no omisión.
- **low** El estado "Carga fría" en la vista *tarjetas* no se nombra por separado (el skeleton descrito es "filas"); EXPERIENCE.md asume que tarjetas reusan el mismo patrón. *Fix:* una nota de que el skeleton de tarjetas respeta `auto-rows-fr` + altura reservada de 2 líneas, paralelo al de filas.

## 5. Visual reference coverage — strong

Se listó cada archivo de `.working/` (`research-banks-ui-inventory.md`) e `imports/` (5 PNG del estado actual). Ambas spines enlazan los imports vía el decision log y el audit (que cita cada captura por nombre y la mapea a hallazgos). La regla **spines-ganan-en-conflicto** se declara una vez por documento: EXPERIENCE.md:14 ("las spines ganan ante cualquier mock o captura") y DESIGN.md la implica vía el companion `implementation-tailwind.md:3` ("las spines ganan en conflicto"). `mockups/`/`wireframes/` aún no existen (generación en paralelo) — no contado como omisión.

### Findings
- **low** Los imports se enlazan desde `.decision-log.md` y `audit-banks.md`, no directamente desde las spines. Las spines son el contrato extraíble; un consumidor que solo lea DESIGN.md+EXPERIENCE.md no ve la ruta a `imports/`. Defendible (los imports son estado-previo, no composición-objetivo), pero el cruce import→spine vive en docs satélite. *Fix:* opcional — una línea en EXPERIENCE.md Foundation apuntando a `imports/` como "estado previo auditado" y a `audit-banks.md` como puente.
- **low** EXPERIENCE.md declara la regla spines-win contra "mock o captura"; DESIGN.md no la repite (la hereda vía el companion). Consistente, pero asimétrico entre las dos spines. *Fix:* ninguno necesario; el par lo declara una vez en conjunto.

## 6. Bloat & sobreespecificación — strong

DESIGN.md lleva voz editorial defendible (Brand & Style: "herramienta, no escaparate"; "el layout manda, el contenido se adapta") — apropiado para esa spine. EXPERIENCE.md se mantiene conductual y tabular, sin narrativa decorativa. Los presupuestos de píxel (anchos de columna, alturas de fila) **no** son sobreespecificación: son el corazón del rediseño (corrigen T1/C1) y son contrato testeable. No hay restatement de fuentes ni prosa donde una tabla bastaría.

### Findings
- **low** Las dos secciones inventadas de EXPERIENCE.md —**Estrategia de texto largo** y **Jerarquía de información de entidad**— se ganan el sitio: son entregables explícitos del intake ("estrategia de texto largo: evaluar truncado/clamp/tooltips/…"; "jerarquía de información de la entidad con justificación"). No son bloat. *Fix:* ninguno.
- **low** Los dos wireframes ASCII en EXPERIENCE.md (tabla y tarjeta) son entregables pedidos ("wireframe ASCII") y aportan la composición que las tablas no dan. Rozan el límite de "composición = mock", pero al no existir mocks aún, cubren un hueco real. *Fix:* cuando existan `mockups/`, decidir si los ASCII se mantienen o se degradan a referencia.

## 7. Disciplina de herencia — adequate

`design_ref: DESIGN.md` resuelve; `sources:` (brief del usuario, `docs/project-context.md`, `.working/research-banks-ui-inventory.md`) son resolubles y verbatim respecto al decision log. El glosario de presupuestos (row-h, col-*, list-max-w, clamps) es idéntico entre ambas spines. Las referencias de token de EXPERIENCE.md a DESIGN.md mayormente resuelven por nombre.

### Findings
- **high** EXPERIENCE.md:81 usa la sintaxis **`{design: components.table}`**, que no es la sintaxis de referencia del spec (`{path.to.token}`, p. ej. `{components.table}`). Es la única cross-ref tipada-con-prefijo del par y un resolver mecánico la trataría distinto del resto. *Fix:* normalizar a `{components.table}` (o a la convención de prefijo que se adopte, pero entonces aplicarla en *todas* las refs de EXPERIENCE.md, no en una sola).
- **medium** Los nombres de componente no son idénticos entre spines en dos casos (ver §3): "Celda truncable" (EXPERIENCE) vs `tooltip-full-value`+`table-row` (DESIGN); "StatusBadge" (DESIGN `status-badge`) sin fila homónima en EXPERIENCE. La disciplina pide nombre idéntico en todas las secciones de ambos archivos. *Fix:* unificar nomenclatura (kebab-case del componente) en ambas spines.
- **low** EXPERIENCE.md `name: ERPify Backoffice — listas de entidades` vs DESIGN.md `name: ERPify Backoffice`. Variación deliberada (la spine acota a "listas de entidades"), pero el `name` del par no es idéntico. *Fix:* ninguno si es intencional; si se quiere par estricto, alinear.

## 8. Ajuste de forma — strong

DESIGN.md sigue el orden canónico exacto: Brand & Style → Colors → Typography → Layout & Spacing → Elevation & Depth → Shapes → Components → Do's and Don'ts. EXPERIENCE.md tiene todos los defaults requeridos (Foundation, IA, Voice and Tone, Component Patterns, State Patterns, Interaction Primitives, Accessibility Floor, Key Flows) y las required-when-applicable disparadas correctamente: **Inspiration & Anti-patterns** (el intake declara referencias Linear/GitHub/Stripe/Vercel/Notion → se dispara) y **Responsive & Platform** (desktop-first responsive con breakpoints → se dispara). Las secciones inventadas (Estrategia de texto largo, Jerarquía de información) se ganan el sitio (§6).

### Findings
- **low** El intake declara cinco referencias (Linear, GitHub, Stripe, **Vercel**, Notion); Inspiration & Anti-patterns cita Linear, GitHub, Stripe y Notion pero **omite Vercel**. No es rotura de forma, pero una referencia declarada por el usuario queda sin "lifted from / rejected". *Fix:* una línea que ubique Vercel (probablemente subsumido en la estética Linear/Geist) o lo descarte explícitamente.
- **low** Las dos secciones inventadas se insertan entre IA y Voice and Tone, rompiendo la adyacencia IA→Voice del orden de defaults. Defendible (son transversales y se leen mejor pegadas a la IA), pero un consumidor que espere el orden canónico de EXPERIENCE.md las encuentra fuera de sitio. *Fix:* ninguno necesario; nota de forma.

## Mechanical notes

- **Cross-ref rota (alta):** `colors.meta-text-rule` (DESIGN.md:36) no resuelve — `meta-text-rule` es clave raíz (DESIGN.md:15). Tres menciones inconsistentes (raíz vs `colors.`). Ver §2.
- **Sintaxis no estándar (alta):** `{design: components.table}` (EXPERIENCE.md:81) es la única ref con prefijo tipado; el resto del par usa `{path.to.token}`. Ver §7.
- **Cobertura de componentes (alta):** Barra de selección, Toggle densidad, Toggle de vista, Filtros, Confirm destructivo faltan en DESIGN.md.Components. Ver §3.
- **Nombres no idénticos entre spines:** "Celda truncable" vs `tooltip-full-value`/`table-row`; "StatusBadge"/`status-badge` sin fila conductual homónima. Ver §3/§7.
- **Completitud de frontmatter:** ambos frontmatters están completos para sus roles; DESIGN.md `status: draft` y EXPERIENCE.md `status: draft` (coherente con el estado pre-Finalize del decision log). Dark mode diferido declarado en frontmatter — no es omisión.
- **Artefacto de codificación (cosmético):** carácter de reemplazo `�` en el ejemplo Don't de Voice and Tone (EXPERIENCE.md:72, "Nothing here �puzzled"). Solo afecta a un ejemplo de microcopy-a-evitar; sustituir por el emoji/texto previsto o quitarlo.
- **Referencia Vercel sin ubicar:** declarada en el intake, ausente de Inspiration & Anti-patterns. Ver §8.
