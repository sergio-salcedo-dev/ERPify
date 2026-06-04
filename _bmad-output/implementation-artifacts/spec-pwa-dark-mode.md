---
title: 'Tema oscuro PWA — toggle + persistencia + prefers-color-scheme'
type: 'feature'
created: '2026-06-03'
status: 'done'
context: []
baseline_commit: '1d218ce6c620ae9d17541124186544e4ca9eca12'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** La PWA ya define los tokens oscuros (`globals.css`: `.dark` L219, `@custom-variant dark` L5) pero nada los activa: sin toggle, sin persistencia, sin `prefers-color-scheme`.

**Approach:** Integrar `next-themes` (clase en `<html>`, enfoque oficial Shadcn) con `defaultTheme="system"` y storageKey `erpify:theme`; primitiva `<ThemeToggle>` (ciclo claro → oscuro → sistema) montada en el top bar desktop y el header móvil del backoffice; `color-scheme` en `:root`/`.dark`.

## Boundaries & Constraints

**Always:**
- Clase `.dark` en `<html>` consumiendo tokens existentes — cero tokens nuevos o modificados.
- `suppressHydrationWarning` en `<html>` (next-themes muta la clase pre-hidratación).
- `ThemeToggle` en `components/erpify/` + barrel, prop `testId` (sin testid hardcodeado), a11y completo: `title` + `aria-label` + `sr-only` + `aria-hidden` en iconos.
- Storage key `erpify:theme` (convención `erpify:sidebar-open`). Iconos lucide: `Sun`/`Moon`/`Monitor`.
- Avoid using string literals as "dark".

**Ask First:**
- Cualquier cambio en `next.config.ts#headers()` (CSP) — no se espera; si pareciera necesario, HALT.
- Cualquier dependencia más allá de `next-themes`.

**Never:**
- `dangerouslySetInnerHTML` ni scripts inline propios — el anti-FOUC lo inyecta `next-themes` (cubierto por `script-src 'unsafe-inline'` vigente; acepta `nonce` para el CSP futuro).
- Adaptar la superficie marketing/landing (paleta raw) al oscuro.
- `tailwind.config.js` / configuración Tailwind v3.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Primera visita, OS oscuro | sin `erpify:theme`; OS dark | `.dark` en `<html>` sin flash | N/A |
| Primera visita, OS claro | sin clave; OS light | sin `.dark` | N/A |
| Click toggle en claro | tema `light` | `dark`: clase + `erpify:theme="dark"` | N/A |
| Recarga con elección explícita | `="dark"`, OS claro | oscuro persiste; ignora OS | N/A |
| Sistema + cambio de OS en caliente | `system`; OS pasa a dark | la UI sigue sin recargar | N/A |
| localStorage bloqueado | excepción al leer/escribir | cae a `system`; sin crash ni ruido en consola | lo captura next-themes |

</frozen-after-approval>

## Code Map

- `pwa/package.json` — añadir `next-themes` (no hay passthrough npm en make: `docker compose exec pwa npm install`)
- `pwa/src/app/layout.tsx` — `<html>` L34: `suppressHydrationWarning` + envolver con `ThemeProvider`
- `pwa/src/app/globals.css` — `:root` L128 y `.dark` L219: añadir `color-scheme`
- `pwa/src/components/erpify/ThemeToggle.tsx` — nueva primitiva; `index.ts` — barrel
- `pwa/src/app/backoffice/BackOfficeLayoutClient.tsx` — montajes: `bo-layout__topbar-actions` L376 (junto a Search/Bell) y header móvil L227
- `pwa/tests/components/erpify/` — unit espejo (jsdom sin `matchMedia`: stub necesario)
- `pwa/tests/e2e/backoffice/app-shell.spec.ts` — referencia de estilo e2e

## Tasks & Acceptance

**Execution:**
- [x] `pwa/package.json` -- añadir `next-themes` (lockfile actualizado) -- resuelve FOUC+sistema+persistencia sin inline scripts propios
- [x] `pwa/src/app/layout.tsx` -- `suppressHydrationWarning` en `<html>`; `<ThemeProvider attribute="class" defaultTheme="system" enableSystem storageKey="erpify:theme" disableTransitionOnChange>` -- activa la estrategia de clase
- [x] `pwa/src/app/globals.css` -- `color-scheme: light` en `:root`, `dark` en `.dark` -- controles nativos coherentes
- [x] `pwa/src/components/erpify/ThemeToggle.tsx` + `index.ts` -- primitiva client: Button `ghost`/`icon-sm`, ciclo light→dark→system, icono según tema con mounted-guard, `title`/`aria-label` con la acción siguiente, prop `testId` -- patrón de los botones del top bar
- [x] `BackOfficeLayoutClient.tsx` -- montar con `testId="bo-layout__topbar-theme"` (topbar) y `"bo-layout__header-mobile-theme"` (móvil) -- cobertura desktop + móvil
- [x] `pwa/tests/components/erpify/ThemeToggle.test.tsx` -- unit: ciclo, clase en `documentElement`, persistencia, casos de la matriz (stub `matchMedia`)
- [x] `pwa/tests/e2e/backoffice/theme.spec.ts` -- e2e: `.dark` aplicada y persistente tras reload -- corre solo en CI
- [x] `pwa/CLAUDE.md` + `pwa/DESIGN.md` -- registrar `ThemeToggle` y el mecanismo de tema -- regla "Keeping docs up to date"

**Acceptance Criteria:**
- Given el stack del worktree levantado, when se alterna a oscuro, then todas las superficies token-driven del backoffice cambian sin recarga.
- Given oscuro activo, when se navega entre rutas backoffice, then no hay flash claro.
- Given el toggle, when se usa teclado, then es enfocable y accionable (Enter/Espacio) con foco visible.
- Given `make pwa.quality` + `make pwa.test.unit`, then 100 % verde (guards de testid y NEXT_PUBLIC incluidos).

## Spec Change Log

## Design Notes

- **Worktree:** `.claude/worktrees/pwa-dark-mode-vcmp` (rama `feat/pwa-dark-mode-vcmp`, base `main`); los `make` se ejecutan desde su raíz.
- **next-themes vs hand-rolled:** el anti-FOUC fiable exige script inline síncrono; a mano chocaría con la prohibición de `dangerouslySetInnerHTML` (pwa/CLAUDE.md). `next-themes` (0 deps, Vercel, recomendación Shadcn) lo encapsula + modo sistema + sync entre pestañas. **Conflicto señalado:** el inline lo emite la lib, cubierto por el `'unsafe-inline'` ya presente — `next.config.ts` no se toca.
- **Mounted-guard:** `useTheme()` no conoce el tema en SSR; icono neutro hasta el primer efecto para evitar hydration mismatch.

## Verification

**Commands:**
- `make pwa.quality` (raíz del worktree) -- expected: sin errores
- `make pwa.test.unit` -- expected: 100 % verde, guards incluidos
- `npm audit` (contenedor pwa) -- expected: sin vulnerabilidades nuevas

**Manual checks (if no CLI):**
- e2e en CI (`pwa.test.e2e` local bloqueado: Playwright sin browsers en este host).
- `HTTPS_PORT=8443 make docker.up` en el worktree: alternar, recargar, cambiar esquema del OS en modo sistema.

## Suggested Review Order

**Núcleo del mecanismo de tema**

- Punto de entrada: la primitiva completa — ciclo, a11y, mounted-guard, prop `testId`
  [`ThemeToggle.tsx:67`](../../pwa/src/components/erpify/ThemeToggle.tsx#L67)

- Updater funcional: clicks rápidos/pre-hidratación avanzan desde el tema real; repara valores corruptos
  [`ThemeToggle.tsx:87`](../../pwa/src/components/erpify/ThemeToggle.tsx#L87)

- `useHydrated` vía `useSyncExternalStore` — mounted-guard sin `setState` en efecto (regla lint)
  [`ThemeToggle.tsx:59`](../../pwa/src/components/erpify/ThemeToggle.tsx#L59)

- Constantes `Theme` + `THEME_STORAGE_KEY`: cero literales de tema en TS/TSX
  [`theme.ts:17`](../../pwa/src/context/shared/domain/types/theme.ts#L17)

- Wiring del provider: `system` por defecto, clase en `<html>`, clave `erpify:theme`
  [`layout.tsx:55`](../../pwa/src/app/layout.tsx#L55)

- `suppressHydrationWarning` en `<html>` — next-themes muta la clase pre-hidratación
  [`layout.tsx:36`](../../pwa/src/app/layout.tsx#L36)

- Glue client mínimo para montar el provider desde el layout server
  [`ThemeProvider.tsx:13`](../../pwa/src/lib/ThemeProvider.tsx#L13)

**Activación CSS**

- `color-scheme: light` en `:root` — widgets nativos coherentes en claro
  [`globals.css:129`](../../pwa/src/app/globals.css#L129)

- `color-scheme: dark` en `.dark` — único cambio al bloque de tokens oscuros
  [`globals.css:222`](../../pwa/src/app/globals.css#L222)

**Montajes en el app-shell**

- Toggle en el top bar desktop, primero del grupo de acciones
  [`BackOfficeLayoutClient.tsx:382`](../../pwa/src/app/backoffice/BackOfficeLayoutClient.tsx#L382)

- Grupo de acciones nuevo en el header móvil con el segundo montaje
  [`BackOfficeLayoutClient.tsx:236`](../../pwa/src/app/backoffice/BackOfficeLayoutClient.tsx#L236)

**Periferia: tests, docs, dependencia**

- Stub controlable de `matchMedia` (jsdom no lo trae) con `setOsDark`
  [`ThemeToggle.test.tsx:20`](../../pwa/tests/components/erpify/ThemeToggle.test.tsx#L20)

- Filas de la matriz I/O: cambio de OS en caliente, valor corrupto, storage bloqueado
  [`ThemeToggle.test.tsx:140`](../../pwa/tests/components/erpify/ThemeToggle.test.tsx#L140)

- e2e móvil + `colorScheme` fijado para que `system` resuelva igual en cualquier runner
  [`theme.spec.ts:43`](../../pwa/tests/e2e/backoffice/theme.spec.ts#L43)

- Entrada en building blocks: API del toggle y reglas de uso
  [`CLAUDE.md:279`](../../pwa/CLAUDE.md#L279)

- Contrato de theming en el design system
  [`DESIGN.md:508`](../../pwa/DESIGN.md#L508)

- Única dependencia nueva: `next-themes@0.4.6` (0 deps transitivas, audit limpio)
  [`package.json:35`](../../pwa/package.json#L35)
