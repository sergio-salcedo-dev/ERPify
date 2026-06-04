---
title: 'Contención de texto largo en listas de entidades (Banks) — PR 1 del rediseño UX'
type: 'bugfix'
created: '2026-06-04'
status: 'done'
baseline_commit: '21fa3f6'
context:
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-06-03/DESIGN.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-06-03/implementation-tailwind.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Ningún contenedor de texto del módulo Banks impone presupuesto de espacio: un nombre de 255 chars ensancha la columna Name hasta expulsar el resto del viewport (T1 🔴), infla una tarjeta a ~17 líneas rompiendo el grid (C1 🔴, C2/C3) y desborda el H1 del detalle (D1) y el toast (D2).

**Approach:** PR 1 del plan §10 de `implementation-tailwind.md`: contención pura con diff mínimo — `table-layout: fixed` + `<colgroup>` con presupuestos por columna, clamp 2 líneas con altura reservada en título de tarjeta, clamp 2 en H1 de detalle y en descripción de toast. Todo truncado/clamp es **solo CSS**: el string completo permanece en el DOM.

## Boundaries & Constraints

**Always:**
- Las spines (`DESIGN.md` + `EXPERIENCE.md`) ganan ante cualquier conflicto.
- Clamp/truncate solo CSS — prohibido truncar en JS; valor íntegro en DOM/árbol de accesibilidad.
- Intactos: teclado/ARIA de `DataTable`, todos los `data-testid`, overlay de tarjeta y `z-10` de checkbox/acciones.
- `DataTable` (compartido) retro-compatible: sin las props nuevas, render idéntico.
- BEM + `cn()`; `make pwa.quality` limpio al cierre.

**Ask First:**
- Si Name ≥ 240px exige más que un `min-w` en la tabla (cambiar el modelo de overflow del wrapper).
- Si algún assert e2e existente (más allá de `banksCardsLayout.test.tsx`) exige cambio.

**Never:**
- Lo diferido a PRs 2–6: contraste StatusBadge/A1 y tokens nuevos (PR 2), tooltips (PR 3), densidad/sticky (PR 4), reorden de columnas/Status/Created→xl+ (PR 5), e2e 255-chars (PR 6).
- `maxLength` en inputs; refactors oportunistas.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Tabla, nombre extremo | name 255 chars (con/sin espacios) | Fila mantiene `h-9`; Name trunca a 1 línea; las demás columnas no se mueven | N/A |
| Grid mixto | tarjeta con name 255 chars junto a names cortos | Título clamp 2 con altura reservada; alturas de tarjeta idénticas; footer visible | N/A |
| Código largo en tarjeta | shortName 50 chars sin espacios | 1 línea con ellipsis; cabecera de altura fija | N/A |
| Detalle, nombre extremo | name 255 chars | H1 máx 2 líneas; valor íntegro en la ficha "Name" | N/A |
| Toast largo | `description` = name 255 chars | Descripción clamp 2; el toast no desborda | N/A |

</frozen-after-approval>

## Code Map

- `pwa/src/components/erpify/DataTable.tsx` — `<table>` sin `table-fixed` ni `<colgroup>` (`:406-411`); `DataTableColumn` (`:24-35`).
- `pwa/src/app/backoffice/banks/_components/BanksTable.tsx` — columnas (`:57-104`): shortName `max-w-[8rem]`, name `min-w-0`, actions `w-[1%] whitespace-nowrap`; wrapper `overflow-x-auto` (`:118`).
- `pwa/src/app/backoffice/banks/page.tsx:290` — wrapper de lista `max-w-screen-2xl … 2xl:max-w-[120rem]`.
- `pwa/src/app/backoffice/banks/_components/BanksCards.tsx` — grid sin `auto-rows-fr` (`:36`); título `[overflow-wrap:anywhere]` sin clamp (`:77`); meta `<dl>` sin anclar (`:111`); checkbox/acciones ya `z-10` ✓.
- `pwa/src/app/backoffice/banks/[id]/page.tsx:206-210` — H1 sin clamp; ficha "Name" íntegra en `:249` ✓.
- `pwa/src/context/shared/infrastructure/Notification/Toast/SonnerToaster.tsx` — viewport Sonner sin `toastOptions.classNames`.
- `pwa/tests/app/backoffice/banks/banksCardsLayout.test.tsx` — codifica el contrato viejo («sin truncate») que este PR sustituye.
- `pwa/tests/components/erpify/DataTable.test.tsx` — suite a extender.

## Tasks & Acceptance

**Execution:**
- [x] `pwa/src/components/erpify/DataTable.tsx` — añadir `colClassName?: string` a `DataTableColumn` y prop opt-in `layout?: "fixed"`: aplica `table-fixed` y renderiza `<colgroup>` (col `w-10` de selección cuando exista + un `<col className={colClassName}>` por columna).
- [x] `pwa/src/app/backoffice/banks/_components/BanksTable.tsx` — `layout="fixed"` + presupuestos DESIGN: shortName `w-28`, name flexible (sin `colClassName`), createdAt/updatedAt `w-32` (col oculta en sync con su `hidden md/lg:table-cell`), actions `w-28` (la anatomía actual — 3 botones `size-7` + `gap-0.5` + `px-3` = 112px — no cabe en el token `col-actions` 96px, pensado para la anatomía ⋯-en-reposo de PR 5; PR 5 lo re-estrecha); retirar `max-w-[8rem]` y `w-[1%] whitespace-nowrap`; `min-w` en la tabla para Name ≥ 240px (wrapper ya hace `overflow-x-auto`).
- [x] `pwa/src/app/backoffice/banks/page.tsx` — wrapper de lista a `max-w-[90rem]` (token `list-max-w`).
- [x] `pwa/src/app/backoffice/banks/_components/BanksCards.tsx` — `auto-rows-fr` en `<ul>`, `h-full` en `<li>`, Card columna flex; título `line-clamp-2` + altura reservada de 2 líneas (`min-h-[2.7em]` con `leading-[1.35]`, acoplamiento documentado con un comentario breve de why: 2 líneas × 1.35), conservando `[overflow-wrap:anywhere]` y overlay; shortName `block truncate`; meta `<dl>` anclada (`mt-auto`).
- [x] `pwa/src/app/backoffice/banks/[id]/page.tsx` — H1 + `line-clamp-2` (mantener `break-words`).
- [x] `pwa/src/context/shared/infrastructure/Notification/Toast/SonnerToaster.tsx` — descripción con `line-clamp-2 break-words` vía `toastOptions.classNames`, con **deep-merge** de un `toastOptions` entrante (destructurar `toastOptions` de `props` y componer `classNames` con el del caller encima) — un caller que pase su propio `toastOptions` no debe perder el clamp en silencio; solo un `classNames.description` explícito lo sustituye.
- [x] `pwa/tests/app/backoffice/banks/banksCardsLayout.test.tsx` — contrato nuevo: título `line-clamp-2` y shortName `truncate`, ambos con texto completo en DOM.
- [x] `pwa/tests/components/erpify/DataTable.test.tsx` — casos: `layout="fixed"` ⇒ `table-fixed` + `<colgroup>` alineado (selección incluida); sin `layout` ⇒ ni `<colgroup>` ni `table-fixed`.

**Acceptance Criteria:**
- Given name de 255 chars, when se renderiza la tabla, then `<table>` lleva `table-fixed`, el `<colgroup>` tiene tantos `<col>` como columnas (+selección) y el texto completo sigue en el DOM.
- Given tarjetas con names de 1 y N líneas, when se renderiza el grid, then filas de igual altura y región de título con 2 líneas reservadas siempre.
- Given los e2e actuales (`banks.spec.ts`, `banks-real-api.spec.ts`), when corren contra estos cambios, then pasan sin modificar asserts (el clamp CSS no vacía `getByText`).

## Spec Change Log

- **2026-06-04 · iteración 2 (patches post-review, sin loopback).** Tres auto-fixes del segundo review: (1) H1 del detalle gana `min-w-0` — sin él, un name de 255 chars sin espacios (min-content del flex item) podía empujar el badge "New" antes de que `break-words` + clamp actuaran; (2) JSDoc del toaster precisado — el merge es de un nivel en `classNames`, no "deep", y el orden del spread es load-bearing; (3) nuevo `tests/.../SonnerToaster.test.tsx` fija las tres semánticas del merge (clamp por defecto, siblings del caller preservados, override explícito de `classNames.description`). Defer registrado: comentario lint-narration preexistente en `page.tsx:282` (no de este PR).

- **2026-06-04 · iteración 1 → 2 (bad_spec).** Hallazgo: el spec dictaba actions `colClassName: w-24` (token `col-actions` 96px), pero la anatomía vigente del clúster (3 botones `size-7` + `gap-0.5` + celda `px-3` = 112px) desborda 16px sobre Updated bajo `table-fixed` (antes `w-[1%]` auto-ajustaba). Enmienda: actions pasa a `w-28` con nota de que PR 5 (⋯-en-reposo) re-estrecha al token. Estado evitado: solapamiento visual del botón Copy sobre la columna Updated. Patches absorbidos en tareas (sobreviven loopbacks): deep-merge de `toastOptions.classNames` en `SonnerToaster` (un caller no pierde el clamp en silencio) y comentario del acoplamiento `min-h-[2.7em]` = 2 × `leading-[1.35]`. **KEEP:** todo lo demás de la derivación previa está validado por triple review y suites verdes — `colgroup` gateado en `layout==="fixed"` con `<col>` de selección `w-10`, presupuestos restantes idénticos, cadena de igualación de tarjetas (`auto-rows-fr`/`h-full`/`flex-col`/`mt-auto`), H1 con solo `line-clamp-2`, clamp del toast en el viewport (no en el port), y los dos ficheros de test tal como quedaron; reproducir esa derivación (referencia: diff conocido-bueno de la iteración 1) cambiando únicamente los tres puntos enmendados.

## Design Notes

- Receta exacta por componente: `implementation-tailwind.md` §1 (tabla), §4 (tarjetas), §6 (detalle/toast).
- El clamp del toast va en el viewport (`SonnerToaster`), no en el port `ToastNotifier` ni por llamada: contrato global `toast-desc-lines: 2`, port agnóstico de UI.
- La sustitución de `banksCardsLayout.test.tsx` es intencional (decisión pre-rediseño superada); citarla en el cuerpo del PR.

## Verification

**Commands:**
- `make pwa.test.unit` — expected: verde, incluidas `banksCardsLayout` y `DataTable`.
- `make pwa.test.e2e c='tests/e2e/backoffice/banks.spec.ts'` — expected: verde sin tocar asserts (fallos locales de `banks-realtime.spec.ts` son preexistentes, no investigar).
- `make pwa.quality` — expected: ESLint + Prettier limpios.

**Manual checks (if no CLI):**
- Banco con name de 255 chars (límite Zod) en `https://localhost`: tabla/tarjetas/detalle/toast sin deformarse; valor íntegro en el detalle.

## Suggested Review Order

**Mecanismo de contención de la tabla (núcleo del cambio)**

- Punto de entrada: la prop opt-in que activa `table-fixed` — sin ella, render byte-idéntico
  [`DataTable.tsx:79`](../../.claude/worktrees/pwa-banks-contencion-grjy/pwa/src/components/erpify/DataTable.tsx#L79)
- El `<colgroup>` gateado: col de selección `w-10` + un `<col>` por columna con su presupuesto
  [`DataTable.tsx:424`](../../.claude/worktrees/pwa-banks-contencion-grjy/pwa/src/components/erpify/DataTable.tsx#L424)
- Presupuestos por columna; el oculto del `<col>` va en sync con el `hidden md/lg:table-cell` de las celdas
  [`BanksTable.tsx:89`](../../.claude/worktrees/pwa-banks-contencion-grjy/pwa/src/app/backoffice/banks/_components/BanksTable.tsx#L89)
- Actions `w-28` (no el token 96px): 3 botones `size-7` + `px-3` = 112px; PR 5 lo re-estrecha
  [`BanksTable.tsx:104`](../../.claude/worktrees/pwa-banks-contencion-grjy/pwa/src/app/backoffice/banks/_components/BanksTable.tsx#L104)
- `min-w-[48rem]` garantiza Name ≥ 248px con todas las columnas visibles
  [`BanksTable.tsx:136`](../../.claude/worktrees/pwa-banks-contencion-grjy/pwa/src/app/backoffice/banks/_components/BanksTable.tsx#L136)
- Tope de lista `max-w-[90rem]` = token `list-max-w` (1440px)
  [`page.tsx:290`](../../.claude/worktrees/pwa-banks-contencion-grjy/pwa/src/app/backoffice/banks/page.tsx#L290)

**Tarjetas — igualdad de alturas por construcción**

- La cadena completa: `auto-rows-fr` + `h-full` + columna flex + footer `mt-auto`
  [`BanksCards.tsx:36`](../../.claude/worktrees/pwa-banks-contencion-grjy/pwa/src/app/backoffice/banks/_components/BanksCards.tsx#L36)
- Título clamp 2 con altura reservada; el comentario documenta el acoplamiento 2 × 1.35 = 2.7em
  [`BanksCards.tsx:78`](../../.claude/worktrees/pwa-banks-contencion-grjy/pwa/src/app/backoffice/banks/_components/BanksCards.tsx#L78)
- Código a 1 línea con ellipsis (antes envolvía)
  [`BanksCards.tsx:86`](../../.claude/worktrees/pwa-banks-contencion-grjy/pwa/src/app/backoffice/banks/_components/BanksCards.tsx#L86)

**Detalle y toast**

- H1 clamp 2 + `min-w-0` (sin él, un token sin espacios empuja el badge); valor íntegro en la ficha Name
  [`page.tsx:207`](../../.claude/worktrees/pwa-banks-contencion-grjy/pwa/src/app/backoffice/banks/%5Bid%5D/page.tsx#L207)
- Clamp global de descripción en el viewport, no en el port; merge de un nivel, orden del spread load-bearing
  [`SonnerToaster.tsx:48`](../../.claude/worktrees/pwa-banks-contencion-grjy/pwa/src/context/shared/infrastructure/Notification/Toast/SonnerToaster.tsx#L48)

**Tests (periferia)**

- Colgroup alineado con selección + retro-compatibilidad sin `layout`
  [`DataTable.test.tsx:251`](../../.claude/worktrees/pwa-banks-contencion-grjy/pwa/tests/components/erpify/DataTable.test.tsx#L251)
- Contrato nuevo de tarjetas: clamp CSS con texto completo en DOM (sustituye el «sin truncate» pre-rediseño)
  [`banksCardsLayout.test.tsx:19`](../../.claude/worktrees/pwa-banks-contencion-grjy/pwa/tests/app/backoffice/banks/banksCardsLayout.test.tsx#L19)
- Las tres semánticas del merge del toaster, fijadas
  [`SonnerToaster.test.tsx:24`](../../.claude/worktrees/pwa-banks-contencion-grjy/pwa/tests/context/shared/infrastructure/Notification/Toast/SonnerToaster.test.tsx#L24)
