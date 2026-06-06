---
title: 'Deferred dark-mode-2: AA light text tokens, SSR base fail-fast, mercure trim'
type: 'bugfix'
created: '2026-06-06'
status: 'done'
baseline_commit: '7b34135'
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Tres findings diferidos de las reviews adversariales del PR #136 (dark-mode v2) siguen abiertos: (1) `text-success`/`text-warning` como texto visible fallan WCAG AA (~2.5:1 / ~3.2:1) en superficies claras, design-system-wide; (2) `serverApiBase()` cae en silencio al literal `https://localhost`, apuntando fetches SSR a `:443` en stacks sin env completa; (3) `mercureUrl()` no hace `.trim()` de `NEXT_PUBLIC_API_BASE_URL` mientras `browserApiBase()` sí — fetch y EventSource pueden resolver orígenes distintos.

**Approach:** Introducir grado-texto AA en light reutilizando la paleta status-dot ya aprobada y migrar los call sites de texto a `-strong`, replicando la convención dark de `text-danger-strong`; los valores dark se fijan para que su render quede píxel-idéntico tras la migración. Sustituir el literal SSR por un fail-fast lazy y descriptivo (derive-from-request descartado — ver Design Notes). Alinear `.trim()` en ambos builders de URL Mercure.

## Boundaries & Constraints

**Always:**
- Valores hex aprobados por owner (2026-06-06): light `--erpify-success-strong: #0f7a5a`, light `--erpify-warning-strong: #b45309`; dark `--erpify-success-strong: #10b981`, dark `--erpify-warning-strong: #f59e0b`.
- Solo migra **texto visible** (e iconos informativos); fills/dots/borders (`bg-success/10`, `border-warning/30`, `bg-status-dot-*`, …) conservan tokens base.
- Resolución de base URL lazy: jamás throw en module-init/construcción; el error solo salta en un fetch server-side real sin `SYMFONY_INTERNAL_URL` ni `NEXT_PUBLIC_API_BASE_URL`, y su mensaje nombra ambas vars. Path browser intacto (`""` same-origin).
- Actualizar `pwa/DESIGN.md` (señales semánticas + convención grado-texto) y borrar las dos secciones dark-mode-2 de `deferred-work.md` al cerrar.
- Trabajo en worktree (`make worktree.create`), nunca en el checkout primario.

**Ask First:** Cambiar cualquier otro valor del bloque light; tocar los tokens `--erpify-status-dot-*` o la anatomía de `StatusBadge`; reintroducir lógica derive-from-Host.

**Never:** No `tailwind.config.js` (Tailwind 4 CSS-first); no tocar la paleta raw del landing/marketing; no ampliar CSP; no cambiar firmas del port `HttpClient`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| SSR fetch, internal set | `SYMFONY_INTERNAL_URL=" http://php:80 "` | base = `http://php:80` (trim + sin slash final) | N/A |
| SSR fetch, solo pública | `NEXT_PUBLIC_API_BASE_URL=https://x` | base = `https://x` | N/A |
| SSR fetch, ambas unset | env vacía + fetch server-side | — | `Error` descriptivo nombrando ambas vars (no más `https://localhost` silencioso) |
| Construcción SSR sin env, sin fetch server | singleton DI creado en module-init | sin throw (resolución lazy) | N/A |
| Browser fetch, ambas unset | `window` definido | base `""` relativa same-origin (sin cambio) | N/A |
| mercureUrl con padding | `NEXT_PUBLIC_API_BASE_URL=" https://x "` | mismo origen trimmed que fetch (EventSource ≡ fetch) | N/A |
| mercureUrl sin env | unset/vacía | `/.well-known/mercure` relativo al origin de window (sin cambio) | N/A |

</frozen-after-approval>

## Code Map

- `pwa/src/app/globals.css` — `@theme` alias (línea ~54: falta `--color-warning-strong`); bloque light ~169–176; bloque dark ~276–282.
- `pwa/src/context/shared/infrastructure/HttpClient/HttpClient.ts` — `serverApiBase()` (:32–42, literal en :41); `FetchHttpClient` hornea `baseUrl` en constructor (:89–93) → mover a lazy.
- `pwa/src/context/shared/infrastructure/RealTime/BrowserMercureSubscriber.ts:17` y `useMercureRealtime.ts:33` — `?? ""` sin `.trim()`.
- Call sites texto (9): `app/status/_components/ComponentStatusRow.tsx:19-20`, `app/status/_components/StatusBanner.tsx:19-20`, `app/backoffice/health/_components/SystemStatusBanner.tsx:16-17`, `context/shared/error/infrastructure/ui/ErrorScreen.tsx:10` (icono warning), `app/_components/Navbar.tsx:43,92`, `context/shared/dev-tools/infrastructure/ui/DevToolsMenu.tsx:24-25`, `context/shared/error/infrastructure/ui/SegmentErrorBoundary.tsx:95`, `app/dev-tools/error-gallery/page.tsx:227`, `components/erpify/ProblemDisplay.tsx:44` (solo el `text-warning` del badge; `bg-warning/10` queda).
- `pwa/tests/context/shared/infrastructure/HttpClient/FetchHttpClient.test.ts:221-231` — test del fallback literal a reescribir.
- `pwa/tests/context/shared/infrastructure/RealTime/BrowserMercureSubscriber.test.ts` — sin stub de env hoy; añadir caso padded.
- `pwa/DESIGN.md` §señales semánticas (~154–162) + nota convención grado-texto + §StatusBadge (~349).
- `_bmad-output/implementation-artifacts/deferred-work.md:101-135` — dos secciones a eliminar al cerrar.

## Tasks & Acceptance

**Execution:**
- [x] `pwa/src/app/globals.css` — añadir alias `--color-warning-strong: var(--erpify-warning-strong)` en `@theme`; light: `success-strong` → `#0f7a5a`, nuevo `warning-strong: #b45309`; dark: `success-strong` → `#10b981`, nuevo `warning-strong: #f59e0b`; comentario breve estilo danger-strong ("text-grade") — habilita utilidades `text-*-strong` AA.
- [x] Migrar los 9 call sites del Code Map: `text-success`→`text-success-strong`, `text-warning`→`text-warning-strong` (solo texto/icono informativo) — cierre del finding AA.
- [x] `pwa/src/context/shared/infrastructure/HttpClient/HttpClient.ts` — resolver base lazy por llamada (browser/server decidido en uso, no en constructor); `serverApiBase()` lanza `Error` descriptivo cuando ambas env faltan — guard fail-fast sin romper construcción.
- [x] `pwa/tests/.../HttpClient/FetchHttpClient.test.ts` — sustituir aserción del literal por: (a) throw descriptivo en fetch SSR sin env, (b) construcción SSR sin env no lanza — cubre la matriz I/O.
- [x] `BrowserMercureSubscriber.ts` + `useMercureRealtime.ts` — `.trim()` alineado con `browserApiBase()`; test unitario con env padded verificando origen idéntico — cierre divergencia fetch/EventSource.
- [x] `pwa/DESIGN.md` — documentar nuevos valores/token y la convención «semántico como texto ⇒ `-strong`» ahora simétrica en light y dark.
- [x] `_bmad-output/implementation-artifacts/deferred-work.md` — eliminar las dos secciones dark-mode-2 (101–135), dejando el resto intacto.

**Acceptance Criteria:**
- Given modo light, when renderizan `/status`, el banner de health del backoffice o el badge warning de `ProblemDisplay`, then el texto success/warning computa `#0f7a5a`/`#b45309` (≥4.5:1 sobre `#fff`, `#f7f8f8`, `#f3f4f5`, `#e9eaec`, `#eef2fc`).
- Given modo dark, when renderizan esas mismas superficies, then el color computado es idéntico al de antes de la migración (`#10b981`/`#f59e0b`).
- Given un stack worktree con la env del pwa incompleta, when una page/route hace fetch SSR, then el fallo es un error explícito que nombra `SYMFONY_INTERNAL_URL` y `NEXT_PUBLIC_API_BASE_URL` — nunca un fetch silencioso a `:443`.
- Given `NEXT_PUBLIC_API_BASE_URL` con espacios, when se abren fetch y EventSource, then ambos resuelven el mismo origen.

## Spec Change Log

## Design Notes

- **Fail-fast lazy vs derive-from-request:** `FetchHttpClient` es singleton de module-init (sin request scope → `headers()` lanza); `HttpClient.ts` entra en el bundle cliente (import estático de `next/headers` rompe el build); y derivar el target del `Host` entrante es input no confiable dirigiendo un fetch server-side (checklist de seguridad). Hoy ninguna página fetchea server-side → guard puro sin regresión.
- **Dark `-strong` = grado-texto** («el valor apto para texto en ese modo»): dark success-strong toma `#10b981` — lo que `text-success` ya renderizaba — y no `#27a644` (≈4.7:1, peor). Nadie usa `*-success-strong` hoy → cero blast radius.
- **Status-dots independientes:** comparten hex con los nuevos valores light deliberadamente, pero son tokens separados (grado-gráfico 3:1 vs grado-texto 4.5:1); no aliasar.

## Verification

**Commands:**
- `make pwa.test.unit c='tests/context/shared/infrastructure/HttpClient/FetchHttpClient.test.ts'` -- expected: verde con los nuevos casos throw/lazy.
- `make pwa.test.unit c='tests/context/shared/infrastructure/RealTime'` -- expected: verde incluyendo caso padded-env.
- `make pwa.test.unit` -- expected: verde (guards de testid/env-allowlist incluidos).
- `make pwa.quality` -- expected: ESLint + Prettier limpios.

**Manual checks (if no CLI):**
- Stack del worktree (`HTTPS_PORT=8443 make docker.up`): `/status` y backoffice health en light → texto success/warning visiblemente más oscuro; ciclo de tema → dark sin cambio visual. (e2e completo corre en CI; host sin browsers Playwright.)

## Suggested Review Order

**Tokens grado-texto AA (decisión de diseño)**

- Punto de entrada: valores light owner-approved y comentario que fija la convención grado-texto vs grado-gráfico.
  [`globals.css:176`](../../pwa/src/app/globals.css#L176)

- Dark `-strong` = base: la migración de call sites es píxel-idéntica en dark.
  [`globals.css:287`](../../pwa/src/app/globals.css#L287)

- Alias `@theme` que habilita la utilidad `text-warning-strong` (success ya existía).
  [`globals.css:55`](../../pwa/src/app/globals.css#L55)

**Migración de call sites (texto/icono informativo → `-strong`; fills/dots/borders intactos)**

- Mapeo más claro: label de estado por `SystemStatus`; los dots de al lado conservan su token.
  [`ComponentStatusRow.tsx:19`](../../pwa/src/app/status/_components/ComponentStatusRow.tsx#L19)

- Tema marketing del banner `/status`: solo la primera entrada (texto) de cada tupla migra.
  [`StatusBanner.tsx:19`](../../pwa/src/app/status/_components/StatusBanner.tsx#L19)

- Tema gemelo del backoffice, mismo patrón.
  [`SystemStatusBanner.tsx:16`](../../pwa/src/app/backoffice/health/_components/SystemStatusBanner.tsx#L16)

- Caso matizado: solo el `text-warning` del badge migra; `icon:` y `bg-warning/10` quedan (grado-gráfico).
  [`ProblemDisplay.tsx:44`](../../pwa/src/components/erpify/ProblemDisplay.tsx#L44)

- Icono warning informativo del tono de error.
  [`ErrorScreen.tsx:10`](../../pwa/src/context/shared/error/infrastructure/ui/ErrorScreen.tsx#L10)

- Link dev-tools, variantes desktop y móvil (incluye el hover).
  [`Navbar.tsx:43`](../../pwa/src/app/_components/Navbar.tsx#L43)

- Cabecera dev-tools: icono `Wrench` + texto uppercase.
  [`DevToolsMenu.tsx:24`](../../pwa/src/context/shared/dev-tools/infrastructure/ui/DevToolsMenu.tsx#L24)

- Cue de copiado del digest (variant selector Tailwind anidado).
  [`SegmentErrorBoundary.tsx:95`](../../pwa/src/context/shared/error/infrastructure/ui/SegmentErrorBoundary.tsx#L95)

- Banner dev-only de la galería de errores.
  [`page.tsx:227`](../../pwa/src/app/dev-tools/error-gallery/page.tsx#L227)

**SSR base fail-fast (sustituye el literal silencioso `https://localhost`)**

- El throw descriptivo nombra ambas env vars; solo salta en fetch server-side real.
  [`HttpClient.ts:47`](../../pwa/src/context/shared/infrastructure/HttpClient/HttpClient.ts#L47)

- Resolución lazy por llamada: el constructor desaparece, el singleton DI sigue construyéndose sin env.
  [`HttpClient.ts:162`](../../pwa/src/context/shared/infrastructure/HttpClient/HttpClient.ts#L162)

**Alineamiento `.trim()` Mercure (EventSource ≡ fetch)**

- `mercureUrl()` ahora trimea igual que `browserApiBase()`.
  [`BrowserMercureSubscriber.ts:17`](../../pwa/src/context/shared/infrastructure/RealTime/BrowserMercureSubscriber.ts#L17)

- Mismo one-liner en el builder del authorize fetch.
  [`useMercureRealtime.ts:33`](../../pwa/src/context/shared/infrastructure/RealTime/useMercureRealtime.ts#L33)

**Documentación y ledger**

- Tabla de señales semánticas + convención «semántico como texto ⇒ `-strong`» simétrica light/dark.
  [`DESIGN.md:159`](../../pwa/DESIGN.md#L159)

- Las dos secciones dark-mode-2 cerradas se eliminan; nueva entrada deferred (divergencia DISRUPTED entre banners) de la review de esta spec.
  [`deferred-work.md:119`](deferred-work.md#L119)

**Tests (periferia)**

- Sustituye la aserción del literal: throw descriptivo + construcción lazy sin throw + trim de `SYMFONY_INTERNAL_URL`.
  [`FetchHttpClient.test.ts:233`](../../pwa/tests/context/shared/infrastructure/HttpClient/FetchHttpClient.test.ts#L233)

- Env padded: EventSource y authorize fetch resuelven origen idéntico.
  [`BrowserMercureSubscriber.test.ts:146`](../../pwa/tests/context/shared/infrastructure/RealTime/BrowserMercureSubscriber.test.ts#L146)

- Comentario e2e actualizado al nuevo nombre de clase (solo doc).
  [`error-pages.spec.ts:233`](../../pwa/tests/e2e/error-pages.spec.ts#L233)
