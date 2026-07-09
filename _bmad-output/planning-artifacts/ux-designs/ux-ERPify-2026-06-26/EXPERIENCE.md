---
name: 'Investigación de Auditoría — experiencia (IA · comportamiento · estados · flujos)'
status: final
updated: 2026-06-26
design_ref: ./DESIGN.md
system_ref: ../../../../pwa/DESIGN.md
sources:
  - ../../epics.md (Story 4.1 contrato read model · Story 4.2 AC · UX-DR1..UX-DR5)
  - ../../../../docs/adr/audit-activity-log.md (D4 PII/GDPR · D5 · D6 jornada · D7 ActorType)
scope: 'Story 4.2 (UI admin de investigación de auditoría, Backoffice PWA) — solo consulta'
note: >
  Espina de comportamiento. Referencia los tokens de DESIGN.md por nombre con sintaxis {token}.
  En conflicto entre cualquier mock y esta espina, gana la espina.
---

# Investigación de Auditoría — EXPERIENCE

> **Qué es:** la UI admin (Backoffice PWA) para **investigar** el `audit_log`: un timeline filtrable,
> la reconstrucción de la jornada de un actor, y el detalle de cada entrada — con tratamiento
> consciente de la PII. **Solo consulta**: Backoffice nunca edita ni borra auditoría (D5).
> Esta espina diseña el **estado objetivo**; la implementación es 4.2b, en su propia PR.

## Foundation

- **Form-factor:** desktop **primario** (herramienta de investigación de alta densidad), tablet
  secundario, móvil terciario (consulta esencial — ver § Responsive).
- **Sistema de UI:** ERPify PWA Design System ([`pwa/DESIGN.md`](../../../../pwa/DESIGN.md)) —
  Shadcn + Tailwind 4 + composites `@/components/erpify`. La identidad visual y los tokens viven ahí;
  el delta de auditoría en [`./DESIGN.md`](./DESIGN.md). Esta espina especifica **solo el delta de
  comportamiento**.
- **Ubicación de código:** ruta `app/backoffice/audit/` (hoy un placeholder
  `<h1>Audit Logs</h1>`); lógica y UI en `src/context/backoffice/audit/{domain,application,infrastructure}`.
- **Backbone existente (no se rediseña):** el read model 4.1 (timeline keyset filtrable) y el read
  model de detalle 4.2a. Esta UI **consume**; no modela datos nuevos (salvo OQ-1).

### Contrato de datos (vivo — no inventar campos)

**Fila del timeline (4.1 + flag de OQ-1):** `id` · `occurredOn` (ISO con microsegundos) · `level`
(`activity`|`security`) · `action` · `actorType` (`anonymous`|`system`|`api_key`|`user`) ·
`actorId` (nullable) · **`actorErased` (boolean)** · `correlationId` · `resourceType` (nullable) ·
`resourceId` (nullable).

**Detalle (4.2a):** los de la fila (incl. **`actorErased`**) + `ip` (nullable) + `userAgent`
(nullable) + `metadata` (objeto JSON). `ip`/`userAgent`/`metadata` son **input no confiable
(*tainted*)** y `metadata` no contiene payload sensible por invariante del ADR (D4).

> **`actorErased`** materializa la anonimización GDPR (resolución de **OQ-1 = Opción A**, ver § PII
> y el log de decisiones). **Dependencia de backend** (fuera del alcance de este doc UX): columna
> `actor_erased boolean NOT NULL DEFAULT false` en `audit_log`, fijada en el **mismo `UPDATE`** del
> erasure (remint de `actor_id` + redacción de `ip`/`user_agent`), expuesta en ambos read models. No
> es PII (un boolean), así que la **fila esbelta** muestra la anonimización sin cargar `ip`/`user_agent`.

## Information Architecture

```
/backoffice/audit                         (única ruta de pantalla)
├── AuditFilterBar      barra: [Todo|Activity|Security]  desde[ ] hasta[ ]  Filtros⃝
│                       panel Filtros: actor(tipo+id) · recurso(tipo+id) · acción
├── modo de vista       toggle [ Timeline ] [ Jornada ]   (Jornada requiere actor fijado)
├── AuditTimelineTable  DataTable denso, divisores por día, orden occurred_on desc
│   └── fila ⟶ AuditEntryDrawer (drawer lateral, no navegación de página)
│       y ⋯ ⟶ pivotes (actor / correlación / recurso) + copia
├── JourneyGroupedTimeline   (cuando modo = Jornada) agrupado por correlation_id
└── AuditPagination     ‹ anterior | siguiente ›  (keyset, siempre renderizado)

Estado en URL params (compartible, sin PII en storage):
  ?level=&from=&to=&actorType=&actorId=&resourceType=&resourceId=&action=&view=timeline|journey
  (opcional, mejora) ?entry=<id>  → abre el drawer en frío / deep-link
  El cursor keyset NO va en la URL (token opaco y prunable); la unidad compartible es la query filtrada.
```

**Una sola pantalla, dos modos de render, un drawer.** No hay página de detalle separada: el detalle
es un drawer sobre el timeline (mantiene contexto y scroll). El «modo Jornada» **no** es otra ruta:
es una reorganización del mismo timeline filtrado a un actor.

**Cierre de superficies:** cada necesidad declarada (UX-DR1..5) aterriza en una superficie y cada
superficie tiene un camino que la alcanza:

| Necesidad                          | Superficie                                            |
| ---------------------------------- | ----------------------------------------------------- |
| UX-DR1 timeline cronológico        | `<AuditTimelineTable>` (orden `occurred_on` desc)     |
| UX-DR2 filtros                     | `<AuditFilterBar>` (barra + panel, estado en URL)     |
| UX-DR3 reconstrucción de jornada   | pivote «seguir actor» → toggle `Jornada` → agrupado   |
| UX-DR4 detalle de entrada          | `<AuditEntryDrawer>` (secciones Qué/Quién/…/Metadata) |
| UX-DR5 presentación PII-aware      | `<ActorChip anonymized>` · `<RedactedValue>` · § PII  |

### Referencias visuales (mockups)

Mocks HTML 1:1 con los tokens reales (modo claro canónico), como referencia de layout/jerarquía —
**no** de tipografía exacta (Geist no carga offline). En conflicto, **gana esta espina**:

- [`mockups/audit-timeline.html`](./mockups/audit-timeline.html) — timeline denso, barra de filtros,
  divisores por día, acento `security`, chips de actor (incl. anonimizado), `⋯` con pivotes, keyset.
- [`mockups/audit-entry-drawer.html`](./mockups/audit-entry-drawer.html) — drawer de detalle con
  secciones Qué/Quién/Sobre qué/Correlación/Metadata + variante de actor anonimizado / `[REDACTED]`.
- [`mockups/audit-journey.html`](./mockups/audit-journey.html) — modo Jornada agrupado por
  correlación (cabeceras de sesión, `rowgroup`, «… continúa»).

## Voice and Tone

Tono **forense y neutral**: el sistema describe hechos, no opina ni tranquiliza. Microcopy en
español; identificadores y tokens **nunca** se traducen.

| Lugar                          | Texto                                                                                                |
| ------------------------------ | ---------------------------------------------------------------------------------------------------- |
| Título de pantalla (H1)        | «Auditoría»                                                                                           |
| Subtítulo                      | «Investiga la actividad y la seguridad registradas.»                                                 |
| Segmentado de nivel            | «Todo» · «Activity» · «Security»                                                                      |
| Botón panel filtros            | «Filtros» (+ badge numérico de filtros activos)                                                       |
| Toggle de modo                 | «Timeline» · «Jornada»                                                                                |
| Toggle Jornada deshabilitado   | tooltip: «Fija un actor para reconstruir su jornada.»                                                 |
| Pivote actor                   | «Seguir a este actor»                                                                                 |
| Pivote correlación             | «Ver esta correlación»                                                                                |
| Pivote recurso                 | «Ver actividad de este recurso»                                                                       |
| Copiar                         | «Copiar id de entrada» · «Copiar correlación» · «Copiar id de actor»                                  |
| Empty first-run                | título «Sin actividad registrada» · cuerpo «Aún no hay entradas en el registro de auditoría.»        |
| Empty filtered-to-zero         | título «Ningún resultado» · cuerpo «Ninguna entrada coincide con estos filtros.» · acción «Limpiar filtros» |
| Empty permission-denied        | título «Acceso restringido» · cuerpo «No tienes permiso para consultar la auditoría.» (honesto, sin culpar) |
| Jornada sin sesiones           | «Este actor no tiene entradas en el rango seleccionado.»                                              |
| Actor anonimizado              | «anonimizado (GDPR)» + «no identificable»                                                             |
| Valor redactado                | `[REDACTED]` (verbatim, es el centinela del dato)                                                     |
| Nulo real (sin dato)           | «—»                                                                                                   |
| Metadata vacío                 | «Sin metadata»                                                                                        |
| Error de carga                 | `<ProblemDisplay>` con `title`/`detail` **verbatim** de la API + `<CorrelationIdChip>`               |

**Reglas de microcopy:** nunca parafrasear `title`/`detail`/`violations[]` de la API (regla RFC
9457 heredada). Nunca «humanizar» un `action` hasta perder el token crudo. `[REDACTED]` y los
`action` se muestran tal cual.

## Component Patterns (comportamiento)

### Timeline (`<AuditTimelineTable>`)

- Orden `occurred_on` **desc** por defecto; cabecera de columna «hora» conmuta asc/desc (único eje
  ordenable — el keyset cabalga `(occurred_on, id)`; otras columnas **no** son ordenables).
- **Divisores por día**: cada cambio de fecha local inserta una cabecera de grupo (`role="rowgroup"`)
  con la fecha larga («12 de junio de 2026»); las filas muestran solo `HH:MM:SS.mmm` local.
- **Acción**: línea primaria = etiqueta humanizada (sans); línea secundaria = token crudo
  (`{font.mono}`, `{color.text-subtle}`). La humanización usa un `Record<AuditAction, string>` curado
  para acciones conocidas + un **humanizador determinista de respaldo** (quita prefijo `ROUTE_`,
  title-case) para `ROUTE_*` y acciones no mapeadas. El token crudo se renderiza **siempre**.
- **Nivel**: `<AuditLevelBadge>` (dot + label) + acento lateral `{color.security}` en filas
  `security`.
- **Actor**: `<ActorChip>` (icono por tipo + id mono copiable cuando existe; anonimizado = chip
  distinto, ver § PII).
- **Recurso**: `resourceType · resourceId⧉` truncado, o «—» si ambos nulos. Id copiable; **sin
  deep-link**.
- **Correlación**: `<CorrelationIdChip>` (copia + tooltip con id completo).
- **Activación de fila**: toda la fila navega al drawer (`onRowActivate`, patrón heredado). `⋯`
  siempre visible (coarse pointers lo ven siempre; en fino, revela en hover/focus-within salvo el
  `⋯` que está siempre).
- **Sin selección/bulk** (read-only): no hay checkboxes, no hay barra de selección, `Space` no
  selecciona.
- **Contención de texto largo**: `ip`, `user_agent`, `action`, ids → budget de columna + truncado
  CSS de una línea (`<TruncatedText>` monta tooltip solo si trunca); el valor completo siempre en el
  DOM y el árbol de accesibilidad. El detalle (drawer) es la casa canónica del valor completo.

### Filtros (`<AuditFilterBar>`)

- **Barra (siempre visible):** segmentado de nivel (`Todo`/`Activity`/`Security`) + `<DateField>`
  desde/hasta (bounds inclusivos vía `parseDdMmYyyyToStart/EndTimestamp`).
- **Panel «Filtros» (popover en ≥ md, sheet en < md):** actor = select de `actor_type` + input de
  `actor_id`; recurso = `resource_type` + `resource_id`; `action` (input o select según el catálogo
  disponible). Badge = nº de filtros del panel activos (no cuenta nivel ni fechas, que ya se ven).
- **Debounce 250 ms** en inputs de texto; cambios reflejan en URL params (sort/filtros persisten en
  URL, heredado). Cambiar cualquier filtro **resetea el cursor** keyset.
- **Limpiar**: acción «Limpiar filtros» en el panel y en el empty `filtered-to-zero`.
- **Sin valor primario de búsqueda libre**: a diferencia de la lista de bancos (búsqueda por nombre),
  aquí no hay un campo «nombre»; los ejes de mayor frecuencia (nivel, fecha) están en la barra y el
  resto en el panel.

### Reconstrucción de jornada (UX-DR3)

Dos piezas, decididas en coaching (pivote para **entrar**, modo agrupado para **leer**):

1. **Pivote (entrar):** desde el `⋯` de una fila o desde el drawer, «Seguir a este actor» fija
   `actorType`+`actorId` en los filtros (URL) y deja la vista lista para Jornada. «Ver esta
   correlación» fija `correlationId`. «Ver actividad de este recurso» fija `resourceType`+`resourceId`.
   Todos **re-filtran el propio `audit_log`** (operación segura de consulta).
2. **Modo Jornada (leer):** toggle explícito `[Timeline] [Jornada]`. «Jornada» se **habilita solo
   con un actor fijado**; si no, va `aria-disabled="true"` (no el `disabled` nativo, que esconde el
   motivo de teclado/SR) con `aria-describedby` a una pista **visible** «Fija un actor para
   reconstruir su jornada» — el porqué llega a teclado y lector de pantalla, no solo en hover.
   Al activarse, el timeline se reorganiza en `<JourneyGroupedTimeline>`: bloques por
   `correlation_id` (cabecera = correlación + ventana `HH:MM`–`HH:MM` + recuento), entradas
   cronológicas dentro de cada bloque. Sin tabla de sesión (D6): la agrupación es client-side sobre
   las filas ya paginadas por `actor_id` + ventana + `correlation_id`.

> La ventana temporal de la jornada = el rango de fechas activo en los filtros (no una ventana
> implícita oculta). Si el actor tiene más entradas que una página, la paginación keyset sigue
> aplicando dentro del modo Jornada (los grupos se construyen sobre la página visible; un grupo puede
> continuar en la página siguiente — se indica con «… continúa»).

### Detalle (`<AuditEntryDrawer>`)

`<RecordSheet>` modo lectura (drawer lateral). **Abrir** = activar una fila (o `?entry=<id>` en frío,
mejora opcional). **Cerrar** = `Esc` / botón / click fuera → vuelve al scroll exacto del timeline.

Estructura (definition-list por secciones):

```
Cabecera   <acción humanizada>        [● Activity|Security]
           BANK_ACCOUNTS_VIEWED  (token crudo, mono)
           [Seguir a este actor] [Ver esta correlación] [Ver actividad de este recurso]
— Qué —      acción   <humanizada> · <token crudo⧉>
             cuándo   12/06/2026 12:04:31.118 (UTC+02:00) · hace 2 h
                      2026-06-12T12:04:31.118402+02:00 ⧉   (ISO completo copiable)
— Quién —    actor    <ActorChip> (o «anonimizado (GDPR)»)
             ip       <ip⧉>  | <RedactedValue> | «—»
             user agent <ua truncado, tooltip completo> | <RedactedValue> | «—»
— Sobre qué — recurso  <resourceType · resourceId⧉> | «—»   (sin deep-link)
— Correlación — <CorrelationIdChip>  [Ver esta correlación]
— Metadata — <MetadataBlock>  (JSON crudo escapado, copiable) | «Sin metadata»
```

Sin acciones de escritura. Las únicas acciones son **pivotes de consulta** y **copia**.

### Paginación (`<AuditPagination>`)

Patrón `BanksPagination` (heredado): par «‹ anterior | siguiente ›» **siempre renderizado**,
`disabled` cuando el link del sobre es null (nunca oculto — gobernado por el ADR de keyset). Cambiar
filtros u orden resetea el cursor.

## State Patterns

Toda la pantalla y el drawer enrutados por `<AsyncBoundary>` (idle/loading/empty/error).

| Estado                | Tratamiento                                                                                                          |
| --------------------- | ------------------------------------------------------------------------------------------------------------------- |
| **Loading**           | skeleton layout-stable de filas (densidad compacta); cabeceras de día y columnas presentes. > 3 s → «aún cargando…» `aria-live=polite`. |
| **Empty first-run**   | `<EmptyState first-run>`: «Sin actividad registrada» (el log está vacío de verdad). Sin ilustración decorativa.       |
| **Empty filtered-to-zero** | `<EmptyState filtered-to-zero>`: «Ningún resultado» + «Limpiar filtros». Distingue de first-run.                |
| **Empty permission-denied** | Inline en la superficie: `<EmptyState permission-denied>` (vía `<AsyncBoundary>`) — «Acceso restringido». `<AccessDeniedScreen>` (403 de ruta completa) **solo** si se gatea la ruta entera. **Especificado pero dormido** hasta RBAC (D1). |
| **Error**             | `<ProblemDisplay panel>`: `title`/`detail` verbatim + `<CorrelationIdChip>` + reintentar. Nunca stack traces en prod. |
| **Jornada sin sesiones** | dentro del modo Jornada, si el actor no tiene entradas en el rango: «Este actor no tiene entradas en el rango seleccionado.» |
| **Drawer cargando**   | si el detalle (4.2a) se pide aparte de la fila: skeleton de secciones dentro del `<RecordSheet>`; error → `<ProblemDisplay inline>` dentro del drawer. |
| **Metadata grande**   | `<MetadataBlock>` trunca + «copiar crudo» (guard de tamaño/profundidad).                                              |

> **Sin estado de mutación.** No hay `<MutationError>` ni confirmaciones destructivas: la auditoría
> no se edita ni se borra desde aquí. El contrato de errores de mutación del sistema **no aplica**.

## Interaction Primitives

- **Teclado (timeline):** `↑`/`↓` mueven fila (roving tabindex, heredado de `<DataTable>`); `Enter`
  abre el drawer; **no** hay `Space`-select (read-only). Dentro de la fila enfocada, `Tab` recorre en
  orden DOM sus controles (`⋯`, chips copiables) y `Shift+Tab` vuelve; el `⋯` abre su menú con
  `Enter`/`Space`. **El drawer es el equivalente de teclado completo** — expone TODOS los pivotes y
  copias —, así que ninguna capacidad queda solo-puntero (resuelve 2.1.1 aunque el usuario nunca entre
  en los chips de la fila). `/` enfoca el primer control de filtro de la barra (no hay búsqueda libre;
  `/` lleva al segmentado de nivel). `Esc` cierra el drawer/popover/sheet con precedencia sobre
  cualquier tooltip.
- **Teclado (drawer):** focus-trap al abrir, focus-restore a la fila de origen al cerrar (heredado de
  `<RecordSheet>`). Tab recorre pivotes → secciones → copias.
- **Teclado (Jornada):** las cabeceras de sesión son hitos navegables; dentro, mismas semánticas de
  fila. Los grupos son `rowgroup` con cabecera asociada (a11y, ver abajo).
- **Pivotes:** un click fija filtros en la URL (estado compartible). Tras un pivote de actor, el
  toggle «Jornada» se habilita.
- **Copia:** todo id/correlación/ip/metadata vía `<CopyButton>` (feedback de 2 s, sr-only fallback,
  nunca confía el valor como HTML). `<CorrelationIdChip>` ya lo encapsula.
- **Densidad:** `<DensityToggle>` (compacto 36 px por defecto / cómodo 44 px); persiste en
  `LIST_DENSITY_STORAGE_KEY` (no-PII).
- **Persistencia de estado:** filtros, orden y modo en **URL params** (compartible, sin PII en
  storage). El **cursor keyset y el tamaño de página son estado de vista transitorio** (ni URL ni
  storage): el cursor es un token opaco y prunable cuyo marcador caducaría, y la unidad realmente
  compartible de una investigación es la query filtrada, ya en la URL; un enlace recargado reabre la
  página 1 de esa misma query. En localStorage solo lo no-PII: densidad y, opcionalmente, el último
  modo (Timeline/Jornada). **Nunca** `actor_id`/`ip`/`metadata` en localStorage/sessionStorage.

## Accessibility Floor

Hereda WCAG 2.2 AA del sistema. Específico de este eje:

- **Estructura semántica:** el timeline es una `<table>` real (`<DataTable>`), no divs; columnas con
  `<th scope="col">`. Los divisores por día y los grupos de Jornada son **`<tbody role="rowgroup">`**
  con su etiqueta en **`<th scope="rowgroup">`** (o `aria-labelledby`), de modo que un lector de
  pantalla anuncia el día / la sesión como contexto del grupo. **En desktop (≥ md) se evita el
  antipatrón de fila-compuesta-focusable fuera de tabla nativa** (precedente `jsx-a11y` S6847): al ser
  una tabla nativa con roving tabindex, no se reintroduce una `<div role="button">` por fila. (El móvil
  `< md` sí apila card-rows fuera de tabla → su tratamiento S6847 se decide en § Responsive.)
- **Color nunca único canal:** `level` siempre lleva label en el badge (no solo el dot ni solo el
  acento lateral); el actor anonimizado lleva **texto** «anonimizado (GDPR)» (no solo un icono);
  `[REDACTED]` es texto.
- **Foco visible** 2 px `{color.focus-ring}` en filas, pivotes, chips copiables y controles de filtro.
- **Nombres accesibles estáticos** en controles de acción (regla heredada): `aria-label` corto y
  estático («Copiar», «Más acciones»); el detalle dinámico (id, nombre de actor) va en `title`, no en
  el `aria-label` dentro de `role=cell`/`role=row` (evita fallos de strict-mode en Playwright).
- **Hit targets** ≥ 24×24 px (44×44 en táctil). El `⋯` y los chips copiables cumplen.
- **`aria-live`:** «aún cargando…» y recuentos en `polite`; errores de carga en `assertive` via
  `<ProblemDisplay>`.
- **Texto *tainted* escapado** (también es una propiedad de seguridad): `ip`/`user_agent`/`metadata`/
  `action` se renderizan como texto; en el árbol de accesibilidad aparecen como su valor literal, sin
  interpretación.

## PII & Forensic Integrity (sección propia — UX-DR5 + D4)

El `audit_log` **es PII** (`actor_id`, `ip`, `user_agent`) y la GDPR exige que el actor anonimizado
sea irreconocible. Reglas de presentación, no negociables:

1. **Actor anonimizado se lee COMO TAL.** Tras el erasure (D4), `actor_id` es un UUID nuevo aleatorio
   y `ip`/`user_agent` son `[REDACTED]`. El chip de actor anonimizado (`<ActorChip>` con `actorErased`)
   sustituye el id por «no identificable» y se ve **distinto** de un actor normal — nunca como «un
   user con una marca». El UUID aleatorio **no se muestra como id**.
   - **Fuente única (OQ-1 resuelta = Opción A):** fila **y** detalle consumen el flag **`actorErased`**
     (materializado en backend). La fila ya **no** depende de ningún *tell*; el flag es la fuente de
     verdad. El detalle sigue mostrando `ip`/`user_agent` = `[REDACTED]` como **evidencia**, no como
     origen del flag.
   - **`actorErased` ≠ `actorType = anonymous`** (ejes ortogonales): `anonymous` = nunca identificado
     (ruta pública); `actorErased` = identificado y **luego borrado por GDPR**. Un `anonymous` jamás
     es `actorErased`. El campo se llama `actorErased` (no `anonymized`) justo para no colisionar con
     `anonymous`.
   - **Etiqueta ≠ campo:** el dato es `actorErased` (la causa/ciclo de vida); la etiqueta visible es
     «anonimizado (GDPR) · no identificable» (el estado legal resultante). La separación es deliberada
     — no «alinear» una con la otra.
2. **Valores redactados ≠ ausentes.** `[REDACTED]` (`<RedactedValue>`: chip mono inerte, texto a
   contraste AA `{color.text-muted}` sobre fondo `{color.bg-subtle}`) significa «vaciado por GDPR»;
   un nulo real es «—». No se colapsan.
3. **`ip`/`user_agent` son evidencia forense:** se muestran **verbatim** (escapados, mono, copiables)
   cuando existen; solo `[REDACTED]` cuando el erasure los vació. La UI no minimiza/normaliza por su
   cuenta (la minimización vive en el disparador GDPR, no en la presentación).
4. **`metadata` sin payload sensible** (invariante D4): se muestra crudo. Si algún día una acción
   guardara PII ahí, la política de redacción crece en backend (D4), no se parchea en la UI.
5. **Sin PII de negocio ni deep-link.** El recurso se muestra como `resource_type` + `resource_id`
   (copiable), **nunca** se navega a su página de negocio: (a) el recurso puede estar borrado;
   (b) `resource_type` es abierto y no siempre mapea a ruta; (c) cruzaría hacia otro contexto y
   podría exponer PII de negocio que la auditoría no debe revelar. La auditoría muestra **identidades
   y discriminantes**, no entidades de negocio.
6. **Sin PII en almacenamiento del cliente:** filtros (que pueden contener `actor_id`) viven en URL
   params, jamás en localStorage/sessionStorage. La copia va por `<CopyButton>`.

## Security review (autorevisión obligatoria — CLAUDE)

| Clase                         | Estado en este diseño                                                                                       |
| ----------------------------- | ---------------------------------------------------------------------------------------------------------- |
| XSS / *tainted* → DOM         | `ip`/`user_agent`/`metadata`/`action` como **texto escapado**; nunca `dangerouslySetInnerHTML`/`innerHTML`. |
| `href`/`src` dinámicos        | **No hay** navegación dinámica a datos: el recurso no es deep-link. Si se añade `?entry=<id>`, el id es UUID y se pasa por `encodeURIComponent` (+ `safeHref`/`safeInternalPath` si fuese URL). |
| Open redirect                 | N/A (sin redirecciones desde datos).                                                                        |
| Storage / clipboard           | Sin PII en local/sessionStorage (filtros en URL); copia vía `<CopyButton>` (nunca trata el valor como HTML). |
| AuthZ                         | Ruta de auditoría destinada a RBAC; estado permission-denied especificado (D1). Gating real de ruta pública/PII = prerrequisito backend antes de prod (follow-up). |
| Output encoding               | JSON-only de la API; errores RFC 9457 verbatim sin stack traces en prod.                                    |
| PII                           | Ver § PII & Forensic Integrity. UX-DR5 cubierto en detalle; en la fila depende de OQ-1.                      |

## Key Flows

**Protagonista: Lucía, auditora interna.** Una alerta de cumplimiento le pide verificar quién
consultó las cuentas bancarias de un cliente la semana pasada y si hubo algún intento sin permiso.

1. Abre `/backoffice/audit`. Ve las entradas más recientes (newest-first), divididas por día. El
   segmentado de nivel está en «Todo».
2. Acota: en la barra pone el rango de fechas de la semana pasada. Abre **Filtros**, escribe el
   `resource_type` `BankAccount` y el `resource_id` del cliente. El badge marca «1». La tabla se
   reduce a las consultas sobre ese recurso.
3. Una fila `●sec ACCESS_DENIED` salta con su **acento lateral de seguridad**. Lucía la activa con
   `Enter`: el **drawer** se abre sin perder la lista detrás. Lee «Acceso denegado», el actor
   `🔑 api_key · 0x77b1…`, la `ip`, el `user_agent`, y en **Metadata** la ruta objetivo de la
   denegación.
4. **Clímax:** quiere ver *todo* lo que ese api_key hizo alrededor de ese instante. Pulsa **«Seguir a
   este actor»**. Los filtros se fijan al actor; el toggle **«Jornada»** se habilita. Lo activa: el
   timeline se reagrupa por correlación. Ve la **sesión** completa — la denegación, seguida de una
   búsqueda, seguida de una exportación exitosa — reconstruida sin ninguna tabla de sesión. El hilo
   que la alerta insinuaba ahora es una secuencia legible.
5. Copia el `correlation_id` de la sesión con un click (para el ticket) y el `id` de la entrada de
   denegación. Cierra el drawer con `Esc`; vuelve exactamente donde estaba.

**Variante PII:** al revisar un actor cuyo titular ejerció el «derecho al olvido», Lucía ve
`👤⃠ anonimizado (GDPR) · no identificable` y, en el detalle, `ip [REDACTED]` / `user agent
[REDACTED]`. La **traza de seguridad sobrevive** (acción, nivel, instante, recurso, correlación) pero
la persona es irreconocible: cumplimiento demostrable sin re-identificar.

## Responsive & Platform

- **≥ md (primario):** tabla densa de 7 columnas, drawer lateral, panel de filtros en popover, modo
  Jornada agrupado, metadata cómodo. Densidad compacta por defecto.
- **< md (terciario, «reconsiderar, no apilar»):** cada entrada es una **card-row** apilada con lo
  esencial (hora · `<AuditLevelBadge>` · acción humanizada · `<ActorChip>`); tap → mismo drawer
  (a pantalla ~completa). Filtros en **sheet**. **Sin scroll horizontal.** El modo Jornada y la
  lectura de metadata grande se marcan **desktop/tablet-first** (en móvil funcionan, pero no se
  optimizan). Cada card-row es una **fila compuesta focusable fuera de tabla nativa**: sigue el
  **precedente del proyecto** (lista apilada de bancos) — un único *tab stop* roving con las
  semánticas de teclado de la tabla y un control nativo para «abrir», **aceptando `jsx-a11y` S6847**
  igual que ese precedente. La garantía «sin `div role=button` por fila» aplica a **≥ md**.
- **prefers-reduced-motion:** respetado en la capa de tokens (heredado); el sticky-header shadow y
  cualquier transición del drawer colapsan.

## Anti-patterns (no hacer)

- ❌ Página de detalle separada (rompe el contexto de investigación — se eligió drawer).
- ❌ Humanizar `action` perdiendo el token crudo (rompe fidelidad forense).
- ❌ `{color.danger}` para `security` (no es severidad).
- ❌ Tinte de identidad para actores; el anonimizado pareciendo un id normal.
- ❌ Deep-link del recurso a su página de negocio.
- ❌ Selección/bulk/edición (read-only).
- ❌ Ventana temporal por defecto oculta (datos que «faltan» sin que el usuario lo pidiera).
- ❌ `OFFSET` o sort multi-columna (rompe el keyset).
- ❌ Filtros (PII) en localStorage; `dangerouslySetInnerHTML` en datos *tainted*.

## Decisiones cerradas (antes Open Questions)

- **OQ-1 — RESUELTA = Opción A (materializar el flag).** La anonimización GDPR se expone como
  **`actorErased: boolean`** en fila **y** detalle. Se materializa en el **mismo `UPDATE`** del erasure
  (remint de `actor_id` + redacción de `ip`/`user_agent`), **no** en el self-audit — así hereda su
  atomicidad (el ADR D4 reconoce que el self-audit `GDPR_ERASURE_EXECUTED` no es transaccional con el
  UPDATE). Razón: hecho *compliance-critical* + asimetría del falso negativo + **eje ortogonal** a
  `actorType`/`level` (no se deriva, no se omite, no se pliega en `actor_type`) + coste de escritura
  cero y **cero derivación en la consulta caliente**. `GDPR_ERASURE_EXECUTED` se mantiene como
  **evidencia forense independiente** (pseudónimo en `metadata.anonymized_actor_id`, **parte del
  contrato de ese evento**; actor = `system`), nunca como fuente de verdad → permite un *cross-check*
  de integridad (todo `actorErased=true` ⟺ existe ese evento). Invariante protegido por **test**
  (post-erasure ⟹ `actorErased` ∧ `actor_id` cambiado ∧ `ip`/`ua` `[REDACTED]`). **Dependencia de
  backend** (columna + read models 4.1/4.2a + ADR D4); fuera del alcance de este doc UX.
- **OQ-2 — DECIDIDA: texto libre ahora.** El filtro de `action` arranca como texto libre (las `ROUTE_*`
  son abiertas; el ADR desaconseja congelar vocabulario). *Fast-follow* posible: autocompletado
  **derivado de datos** (`SELECT DISTINCT action` o el eje `category` que el ADR prevé), nunca un
  catálogo curado congelado — y solo si se acepta su endpoint/coste.
- **OQ-3 — DECIDIDA: deep-link `?entry=<id>` desde el principio.** Valor forense/ticketing alto, coste
  bajo. `id` por `encodeURIComponent`; carga el detalle 4.2a directo si la fila no está en la página.
- **OQ-4 — DECIDIDA: diccionario curado + humanizador de respaldo.** Token crudo siempre visible;
  arrancar con acciones vivas (`BANK_ACCOUNTS_VIEWED`, `ACCESS_DENIED`, `GDPR_ERASURE_EXECUTED`).
- **OQ-5 — DECIDIDA: hora local + offset, ISO completo en detalle, sin toggle UTC** (YAGNI; revisita
  si aparece investigación cross-timezone — no afecta al modelo de datos).
