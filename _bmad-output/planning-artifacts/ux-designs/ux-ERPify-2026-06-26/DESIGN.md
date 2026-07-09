---
name: 'Investigación de Auditoría — delta visual'
status: final
updated: 2026-06-26
inherits: ../../../../pwa/DESIGN.md
scope: 'Story 4.2 (UI admin de investigación de auditoría, Backoffice PWA)'
note: >
  Delta sobre el design system vigente (pwa/DESIGN.md = autoridad visual). Solo define
  los tokens, variantes y componentes NUEVOS del eje de auditoría; todo lo demás se hereda.
  Donde un componente del sistema ya sirve, se reutiliza tal cual y no se redefine aquí.
---

# Investigación de Auditoría — DESIGN (delta)

> **Hereda** todo de [`pwa/DESIGN.md`](../../../../pwa/DESIGN.md): ramas de color, tipografía (Geist /
> Geist Mono), espaciado, radios, densidad, elevación, motion, accesibilidad AA, y los composites
> `<DataTable>`, `<AsyncBoundary>`, `<EmptyState>`, `<ProblemDisplay>`, `<StatusBadge>`,
> `<CorrelationIdChip>`, `<RecordSheet>`, `<TruncatedText>`, `<CopyButton>`, `<DateField>`,
> `<DensityToggle>`, `<AppShell>`. Este documento añade **solo** lo específico de auditoría.
> En conflicto entre cualquier mock y estas espinas, ganan las espinas.

## Brand & Style (delta)

La auditoría es una **superficie de investigación, no de operación**: sobria, forense, sin adorno.
Nada de color celebratorio ni de marca decorativa. El único matiz cromático propio es el ya
reservado **`{color.security}`** (`--color-security`, `#7589ad`, idéntico en claro y oscuro), usado
con parsimonia para señalar el eje `security`. Densidad y fidelidad al dato priman sobre estética.

## Colors (delta)

No se introduce **ninguna hue nueva**. El eje reutiliza la rama existente y el token de seguridad.

| Uso nuevo                       | Token (heredado)                          | Aplicación                                                                                              |
| ------------------------------- | ----------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| Punto de nivel `activity`       | `{color.text-subtle}` (dot decorativo)    | Dot del `<StatusBadge>` variante `audit-activity` (baseline de alto volumen, lectura tranquila).        |
| Punto de nivel `security`       | `{color.security}`                        | Dot del `<StatusBadge>` variante `audit-security`.                                                       |
| Acento lateral de fila security | `{color.security}`                        | Borde izquierdo de 2 px en filas `security` del timeline (refuerzo, no único canal — el badge rotula).  |
| Chip de actor anonimizado       | fondo `{color.bg-subtle}` · texto `{color.text-muted}` · icono `{color.text-subtle}` | Chip «anonimizado (GDPR)». El **texto va a contraste AA** (`text-muted` ≈ 5.7:1 en claro); el gris-claro queda solo en el fondo y el icono. **Nunca** el tinte azul-marca de identidad (`<MonogramAvatar>`). |
| Valor redactado `[REDACTED]`    | fondo `{color.bg-subtle}` · texto `{color.text-muted}`  | Chip mono inerte para `ip`/`user_agent` redactados. El **texto va a AA** (`text-muted`); la inercia la dan el fondo y el tratamiento mono, no un gris sub-AA. |

> **Contraste (WCAG 1.4.3).** `{color.text-subtle}` y `{color.text-faint}` están marcados en
> `pwa/DESIGN.md` como **no aptos para body-copy** (sub-AA sobre varias superficies). Por eso el
> **texto legible** del chip anonimizado y de `[REDACTED]` usa `{color.text-muted}` (AA); el gris más
> claro solo colorea fondos, dots e iconos decorativos.

> **Por qué `security` no es rojo.** `security` aquí es **clase de auditoría**, no severidad. Una
> denegación esperada no es un error; teñirla de `{color.danger}` mentiría sobre su naturaleza. El
> gris-azulado `{color.security}` la distingue sin alarmar. El rojo `{color.danger}` queda para
> errores reales de la propia UI (vía `<ProblemDisplay>`).

## Typography (delta)

Hereda la escala. Reglas propias de este eje:

- **Geist Mono (`{font.mono}`)** para todo identificador y dato técnico: `occurred_on`, `actor_id`,
  `resource_id`, `correlation_id`, `ip`, el **token crudo de `action`**, y el bloque `metadata`.
- **Sans** para la **etiqueta humanizada** de `action` (línea primaria) y para `actor_type`,
  `resource_type`. El token crudo va debajo en `{font.mono}` tamaño Caption, color `{color.text-subtle}`.
- Numéricos de tiempo con `tabular-nums` (heredado).

## Components (nuevos — viven en el contexto `backoffice/audit`)

Salvo indicación, son **componentes del contexto de auditoría**
(`src/context/backoffice/audit/infrastructure/ui/`), no primitivos genéricos de `@/components/erpify`
(consumen `ActorType`/`level`/`action`, propios del dominio de auditoría). Se anota cuáles podrían
**graduar** a `erpify` si un segundo consumidor aparece (Regla de Tres).

### `<AuditLevelBadge level>`

`<StatusBadge>` con dos variantes CVA cerradas: `audit-activity` (dot `{color.text-subtle}`, label
«Activity») y `audit-security` (dot `{color.security}`, label «Security»). Dot-first; el label
siempre presente (color nunca único canal). 20 px de alto (pill heredado).

### `<ActorChip actorType actorId actorErased?>`

Anatomía: icono por `ActorType` (`User` / `Cog` / `KeyRound` / `Eye` de lucide, `aria-hidden`) +
`actor_type` (sans) + `actor_id` truncado-medio en `{font.mono}` con `<CopyButton>` cuando hay id.
`system` y `anonymous` no muestran id (no lo tienen).

- **Variante anonimizada** (`actorErased` true — borrado GDPR, flag materializado en backend; OQ-1
  resuelta): icono enmascarado (`UserX`/`EyeOff`, `aria-hidden`, color `{color.text-subtle}`) + label
  «anonimizado (GDPR)» en `{color.text-muted}` (AA) sobre fondo `{color.bg-subtle}`; **el UUID no se
  renderiza como id** (se sustituye por «no identificable»). Es visualmente **otra cosa** que un actor
  normal. `actorErased` es **ortogonal** a `actorType`: un `anonymous` (nunca identificado) **no** es
  `actorErased`. El campo se llama `actorErased` (no `anonymized`) para no colisionar con `anonymous`;
  la *etiqueta* visible sí dice «anonimizado (GDPR)» (estado legal resultante). Candidato a
  sub-componente `<AnonymizedActorChip>`.

### `<RedactedValue>`

Chip mono inerte que renderiza el centinela `[REDACTED]` (de `ip`/`user_agent` tras erasure GDPR):
fondo `{color.bg-subtle}`, **texto `{color.text-muted}` (AA)**, `{radius.micro}`, sin copia (no hay
nada que copiar). La inercia la dan el fondo y el tratamiento mono, no un gris sub-AA. Comunica
«vaciado a propósito», no «sin dato» (un nulo real se muestra como «—»).

### `<MetadataBlock value>`

Bloque `metadata` (JSON): `<pre>` mono escapado, indentado, `{color.bg-subtle}` fondo (estilo
code-block heredado, elevación-2-inset en oscuro), con `<CopyButton>` del JSON crudo en la cabecera
de la sección. **Cada clave y valor se renderiza como texto** (React-escapado) — jamás
`dangerouslySetInnerHTML`/`innerHTML`. Guard: si el JSON serializado supera un umbral
(p. ej. > 4 KB) o una profundidad razonable, se trunca con affordance «copiar crudo» y un aviso
(`<TruncatedText>`-style) — un objeto patológico no puede romper el layout del drawer. `null`/objeto
vacío → estado «sin metadata» (texto `{color.text-subtle}`), no un bloque vacío.

### `<AuditTimelineTable>`

Composición sobre `<DataTable>` (heredado: `layout="fixed"`, budgets por columna, sticky header,
mono para ids, `tabular-nums`, keyset prev/next, density compact 36 px / comfortable 44 px). Aporta:

- **Divisores por día**: cada día es un `<tbody role="rowgroup">` que envuelve sus filas; la fecha
  local larga vive en una fila-cabecera con `<th scope="rowgroup">` (o el `<tbody>` lleva
  `aria-labelledby` al id de esa cabecera), de modo que el lector de pantalla anuncia el día como
  contexto del grupo. Las horas dentro muestran solo `HH:MM:SS.mmm`.
- **Acento lateral security**: borde izquierdo 2 px `{color.security}` en filas `security`.
- **Sin checkbox de selección** (read-only): la fila solo activa el drawer; `⋯` siempre visible con
  pivotes + copia.
- Columnas (orden): hora · nivel (`<AuditLevelBadge>`) · acción (humanizada + token mono) · actor
  (`<ActorChip>`) · recurso (`type · id⧉` o «—») · correlación (`<CorrelationIdChip>`) · `⋯`.

### `<JourneyGroupedTimeline>` (modo Jornada)

Variante de render del timeline cuando hay **un actor fijado** y el toggle está en «Jornada»:
agrupa por `correlation_id` en bloques `<tbody role="rowgroup">`, cada uno con una cabecera de sesión
en `<th scope="rowgroup">` (correlación truncada + ventana temporal `HH:MM`–`HH:MM` + recuento).
Dentro de cada bloque, entradas en orden cronológico (asc dentro de la sesión, sesiones desc).
Mantiene la semántica de tabla nativa y el modelo de teclado. Si un grupo continúa en la página
keyset siguiente, su cabecera lo indica («… continúa»).

### `<AuditFilterBar>`

Barra: segmentado de nivel (`Todo` / `Activity` / `Security`) + rango de fechas (`<DateField>`
desde/hasta, heredado, con bounds inclusivos) + botón «Filtros» con badge (cuenta actor + recurso +
acción activos). El panel «Filtros» (popover/sheet en `<md`) contiene: actor (`actor_type` select +
`actor_id` input), recurso (`resource_type` + `resource_id`), `action`. Estado en **URL params**,
nunca localStorage (un `actor_id` es PII).

### `<AuditPagination>`

**Sin delta visual propio**: consume el primitivo compartido `KeysetPagination` (`@/components/erpify`),
igual que `BanksPagination` y `BankAccountsPagination` — par «‹ anterior | siguiente ›» siempre
renderizado, `disabled` cuando el link del sobre es null, selector de tamaño de página, iconos
`aria-hidden`, `aria-label`/`title` estáticos. La única divergencia admitida es el idioma de las
etiquetas (ES); el `testId` y las opciones de tamaño se inyectan por prop. No se forka el markup.

### `<AuditEntryDrawer entry>`

`<RecordSheet>` en **modo lectura** (drawer). Cabecera: acción humanizada + token crudo +
`<AuditLevelBadge>` + acciones de pivote («Seguir a este actor», «Ver esta correlación», «Ver
actividad de este recurso»). Cuerpo = definition-list en secciones **Qué / Quién / Sobre qué /
Correlación / Metadata**. Sin acciones de escritura (Backoffice solo consulta). Focus-trap y
focus-restore heredados de `<RecordSheet>`.

## Do's and Don'ts (audit-specific)

**Do**

- Reutiliza `<CorrelationIdChip>` para toda correlación — es «first-class to every audit surface».
- Renderiza `ip`/`user_agent`/`metadata`/`action` como **texto escapado**; copia vía `<CopyButton>`.
- Conserva **siempre** el token crudo de `action` junto a la etiqueta humanizada (fidelidad forense).
- Señala `security` con badge **y** acento lateral (doble canal).
- Trata el actor anonimizado como **categoría distinta**, no como id con marca.

**Don't**

- ❌ Nueva hue para el eje: usa `{color.security}` y la rama neutra.
- ❌ `{color.danger}` para `security` (no es severidad; es clase de auditoría).
- ❌ Tinte azul-marca / `<MonogramAvatar>` para actores (no es identidad de negocio; el anonimizado
  jamás debe parecer un id normal).
- ❌ `dangerouslySetInnerHTML`/`innerHTML` en `metadata`/`ip`/`user_agent` (datos *tainted*).
- ❌ Deep-link del recurso a su página de negocio (frágil; ver EXPERIENCE § Integridad forense).
- ❌ Persistir filtros (PII) en localStorage; van en URL params.
