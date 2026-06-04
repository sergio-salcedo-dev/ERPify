---
title: 'Dark mode v2 — paleta elevada, frontoffice tematizado, fetch same-origin'
type: 'feature'
created: '2026-06-04'
status: 'done'
context: []
baseline_commit: '4f194d8'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Feedback del owner sobre el PR #136 (renegocia el "Never: adaptar marketing/landing al oscuro" del v1): (1) el dark solo cubre el backoffice — landing y `/status` (paleta raw) quedan claras con scrollbars oscuros (item diferido E9); (2) la paleta near-black `#08090a` se percibe demasiado oscura frente al estándar SaaS; (3) errores de consola: en worktrees con puerto custom, `browserApiBase()` cae a `https://localhost` hardcodeado → fetch cross-origin bloqueado por CSP — banks/health no cargan. El toggle existe y funciona (verificado en vivo en `:8443`).

**Approach:** Re-tematizar el bloque `.dark` a un ramp gris elevado banda `#16`–`#2b` (benchmark GitHub-dimmed/Stripe, AA con el acento `#7170ff`); migrar landing + status a tokens erpify con `ThemeToggle` en el `Navbar`; default same-origin (`""`) en `browserApiBase()` sin tocar el CSP. Incluye los 2 issues MINOR de SonarCloud (S4325).

## Boundaries & Constraints

**Always:**
- Solo cambian los **valores** del bloque `.dark` — tokens light y nombres de token intactos; cero literales de color en TSX (todo vía tokens/clases Tailwind token-driven).
- Texto primario y secundario AA (≥4.5:1) sobre las 4 superficies del ramp oscuro.
- Migración landing/status con clases token-driven (`bg-background`, `text-foreground`, `text-muted-foreground`, semánticos success/warning/danger); BEM existente intacto.
- Toggle en Navbar = la misma primitiva `ThemeToggle` con prop `testId` propio.
- `serverApiBase()` / `SYMFONY_INTERNAL_URL` sin cambios — el fix es solo del lado navegador.

**Ask First:**
- Cualquier cambio en `next.config.ts#headers()` (CSP) — no se espera; el fix de consola alinea el cliente con el CSP vigente.
- Cualquier dependencia nueva o cambio en tokens del modo claro.

**Never:**
- `tailwind.config.js` / config Tailwind v3; `dangerouslySetInnerHTML`.
- Cambiar el mecanismo next-themes del v1 (provider, storageKey, ciclo del toggle).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Dark + landing | `.dark` en `<html>` | landing oscura token-driven, sin parches slate claros | N/A |
| Dark + `/status` | banner OPERATIONAL/DEGRADED/DISRUPTED | semáforo legible AA sobre superficies oscuras | N/A |
| Worktree `:8443`, banks | sin `NEXT_PUBLIC_API_BASE_URL` | fetch relativo same-origin; lista carga; consola limpia | N/A |
| Stack `main` `:443` | sin var | sigue funcionando (relativo ≡ self) | N/A |
| Var definida (cross-origin real) | `https://api.ej.com` | se respeta absoluta; CSP ya emite ese origin en `connect-src` | N/A |
| OS claro + tema `system` | sin `.dark` | landing clara, regresión cero vs hoy | N/A |

</frozen-after-approval>

## Code Map

- `pwa/src/app/globals.css` — bloque `.dark` L221–304: nuevo ramp de superficies/bordes/texto
- `pwa/src/context/shared/infrastructure/HttpClient/HttpClient.ts` — L19–22 `browserApiBase()`: fallback `""`
- `pwa/src/app/_components/Navbar.tsx` — montar `ThemeToggle` + migrar ~10 clases raw a tokens
- `pwa/src/app/page.tsx`, `pwa/src/app/_components/Footer.tsx` — migrar raw → tokens
- `pwa/src/app/status/page.tsx`, `status/_components/StatusBanner.tsx` (`MARKETING_THEME`), `ComponentStatusRow.tsx` — semáforo a tokens semánticos dark-aware
- `pwa/tests/components/erpify/ThemeToggle.test.tsx` — L31/L34: casts S4325
- `pwa/tests/context/shared/infrastructure/HttpClient/FetchHttpClient.test.ts` — cobertura del default relativo
- `pwa/tests/e2e/backoffice/theme.spec.ts` — referencia de estilo e2e del v1

## Tasks & Acceptance

**Execution:**
- [x] `globals.css` -- ramp dark: bg `#16181d`, muted `#1c1f25`, subtle `#22262e`, elevated `#2b303a`; border `rgba(255,255,255,0.09)` / strong `rgba(255,255,255,0.15)`; texto `#edeef0` / `#b4bac4` / `#8b919e` / faint `#646b78`; revisar overlay/line-tint/shadows para coherencia -- banda SaaS "cómoda", AA verificado
- [x] `HttpClient.ts` -- `browserApiBase()`: `trimBase(v || "")` (relativo same-origin); JSDoc del porqué -- elimina el bloqueo CSP en worktrees, igual que ya hace `BrowserMercureSubscriber`
- [x] `FetchHttpClient.test.ts` -- caso: sin env var → URL relativa `/api/...`; con var → absoluta -- fija el contrato
- [x] `Navbar.tsx` -- `ThemeToggle` con `testId="navbar__theme"` + migración a tokens (incl. enlace dev-tools ámbar → token warning) -- toggle descubrible en el frontoffice
- [x] `page.tsx` + `Footer.tsx` -- `bg-slate-50/text-slate-900/text-blue-600/bg-white` → tokens (`bg-background`, `text-foreground`, `text-primary`…) -- landing dark-aware sin literales
- [x] `status/*` -- `MARKETING_THEME` y `DOT/LABEL_CLASSNAME` a tokens semánticos (tintes suaves vía modificador de opacidad, p. ej. `bg-[--erpify-success]/10`) -- semáforo coherente en ambos modos
- [x] `ThemeToggle.test.tsx` -- eliminar los 2 casts redundantes (S4325) tipando el stub de `matchMedia` -- SonarCloud a 0
- [x] `pwa/tests/e2e/` -- e2e: landing con toggle visible y `.dark` aplicada (patrón de `theme.spec.ts`) -- corre en CI
- [x] `pwa/CLAUDE.md` + `pwa/DESIGN.md` -- ramp nuevo documentado + toggle en Navbar -- regla "Keeping docs up to date"
- [x] `_bmad-output/implementation-artifacts/deferred-work.md` -- marcar RESOLVED el item E9 (`color-scheme` leak) -- cerrado por esta migración

**Acceptance Criteria:**
- Given el worktree en `:8443` sin `NEXT_PUBLIC_API_BASE_URL`, when navego `/backoffice/banks` y `/backoffice/health` en oscuro, then los datos cargan y la consola queda sin errores ni warnings de fetch.
- Given oscuro activo, when visito landing y `/status`, then todas las superficies son token-driven oscuras (sin parches claros) y el texto cumple AA.
- Given OS claro y tema `system`, when visito la landing, then se ve idéntica a hoy.
- Given el toggle del Navbar, when se usa con teclado, then es enfocable y accionable con foco visible.
- Given `make pwa.quality` + `make pwa.test.unit`, then 100 % verde; SonarCloud del PR #136 sin issues OPEN.

### Review Findings

_Code review adversarial (Blind Hunter / Edge Case Hunter / Acceptance Auditor) de la PR #136 completa (`main...fdc5b65`), 2026-06-04. AC1/AC2 verificados en vivo en `:8443` (banks 200 same-origin, consola 0 errores; landing y `/status` oscuras token-driven; ciclo y persistencia del toggle OK). Ratios AA verificados por cálculo: primario ≥11.4:1, secundario ≥6.78:1 en las 4 superficies._

- [x] [Review][Decision] Token nuevo `--erpify-danger-strong` también en `:root` (light) sin renegociación registrada — viola formalmente el «Always: solo cambian los valores del bloque `.dark` — tokens light y nombres de token intactos» y cae bajo «Ask First: cambio en tokens del modo claro». Fue un parche consciente de la review previa (necesario para AA en dark: `danger` `#e5484d` da 3.38:1 sobre elevated; `danger-strong` `#f87171` da 4.78:1; en light duplica a `danger`, cero cambio visual), pero el Spec Change Log del v2 está vacío. — **Resuelto 2026-06-04: owner bendice retroactivamente; renegociación registrada en el Spec Change Log. Código intacto.**
- [x] [Review][Patch] Cobertura del encadenado de fallback de `serverApiBase()` (sin `SYMFONY_INTERNAL_URL` ni override → `https://localhost`) — código reestructurado en la PR sin test nuevo (el spec scopeó los tests al lado navegador; comportamiento neto idéntico a `main`) [pwa/src/context/shared/infrastructure/HttpClient/HttpClient.ts:33-43] — **aplicado en `7ceb6b4` (PR #139; 398/398 unit, quality limpio)**
- [x] [Review][Patch] Precisar el comentario del updater funcional — clics dentro del mismo batch de render no encadenan (next-themes invoca el updater con el valor del closure del render, no con una cola); el beneficio real y testeado es la recuperación desde valor almacenado corrupto [pwa/src/components/erpify/ThemeToggle.tsx:87] — **aplicado en `775967d` (PR #139)**
- [x] [Review][Defer] Fallback SSR `https://localhost` sin puerto falla en stacks worktree sin `SYMFONY_INTERNAL_URL` propagada al contenedor pwa [pwa/src/context/shared/infrastructure/HttpClient/HttpClient.ts:41] — deferred, pre-existing

## Spec Change Log

- 2026-06-04 — Owner aprueba (code review full-PR) la renegociación del «Always: tokens light y nombres de token intactos» / «Ask First: cambio en tokens del modo claro»: se admite el token nuevo `--erpify-danger-strong` (dark `#f87171`, texto-AA 4.78:1 sobre elevated; light `#dc2626`, espejo neutro de `danger`, cero cambio visual). Motivo: en dark un solo valor no puede ser texto-AA y fondo-AA a la vez.

## Design Notes

- **Paleta:** GitHub creó *dark dimmed* (`#22272e`) precisamente porque su default resultaba duro; Stripe (`#14171D`) y Notion (`#191919`) confirman la banda `#14`–`#22`. El `#08090a` del v1 es el negro *marketing* de Linear, no su app. Undertone frío (B>R) asienta el índigo.
- **Same-origin por defecto:** FrankenPHP sirve `/api` en el mismo origin; `BrowserMercureSubscriber` y `frankenphp-hot-reload` ya usan `?? ""` — `HttpClient` era el único divergente. El CSP ya emite `apiOrigin` en `connect-src` cuando la var existe.
- **Status tints:** los fondos suaves (`emerald-50`…) pasan a token semántico + modificador de opacidad para que el tinte derive del modo.

## Verification

**Commands:**
- `make pwa.quality` (raíz del worktree) -- expected: sin errores
- `make pwa.test.unit` -- expected: 100 % verde
- `curl -sk "https://sonarcloud.io/api/issues/search?componentKeys=sergio-salcedo-dev_ERPify&pullRequest=136&issueStatuses=OPEN,CONFIRMED" | jq .total` tras push -- expected: 0

**Manual checks (if no CLI):**
- Playwright-cli contra `:8443`: oscuro en backoffice (banks carga, consola limpia), landing y status oscuras coherentes, claro sin regresión; capturas para el PR.
- e2e en CI (`pwa.test.e2e` local bloqueado: sin browsers Playwright en este host).

## Suggested Review Order

**Fix de los errores de consola — fetch same-origin**

- Punto de entrada: el fallback hardcodeado `https://localhost` pasa a `""` (relativo) con el porqué completo
  [`HttpClient.ts:29`](../../pwa/src/context/shared/infrastructure/HttpClient/HttpClient.ts#L29)

- El lado servidor conserva su fallback absoluto — SSR no puede emitir peticiones relativas
  [`HttpClient.ts:41`](../../pwa/src/context/shared/infrastructure/HttpClient/HttpClient.ts#L41)

- Contrato fijado por test: sin var → URL relativa; con var → absoluta
  [`FetchHttpClient.test.ts:150`](../../pwa/tests/context/shared/infrastructure/HttpClient/FetchHttpClient.test.ts#L150)

**Paleta dark elevada (banda GitHub-dimmed/Stripe)**

- Surface ramp nuevo: `#16181d → #1c1f25 → #22262e → #2b303a`, comentarios con el benchmark
  [`globals.css:232`](../../pwa/src/app/globals.css#L232)

- Text ramp AA: `#edeef0 / #b4bac4 / #8b919e / #646b78`
  [`globals.css:238`](../../pwa/src/app/globals.css#L238)

- Bordes white-alpha reforzados (0.09/0.15) para leerse sobre superficies más claras
  [`globals.css:246`](../../pwa/src/app/globals.css#L246)

- Token nuevo `danger-strong` (review): un valor no puede ser texto-AA y fondo-AA a la vez en dark
  [`globals.css:265`](../../pwa/src/app/globals.css#L265)

- En claro `danger-strong` duplica a `danger` — cero cambio visual en light
  [`globals.css:167`](../../pwa/src/app/globals.css#L167)

**Frontoffice tematizado + toggle en Navbar**

- Montaje desktop del toggle (mismo primitive `ThemeToggle`, testId propio)
  [`Navbar.tsx:52`](../../pwa/src/app/_components/Navbar.tsx#L52)

- Montaje móvil con className diferenciada (hallazgo de review: selector 1:1)
  [`Navbar.tsx:66`](../../pwa/src/app/_components/Navbar.tsx#L66)

- Semáforo de `/status` a tokens semánticos; DISRUPTED usa `text-danger-strong`
  [`StatusBanner.tsx:14`](../../pwa/src/app/status/_components/StatusBanner.tsx#L14)

- Labels por estado: success/warning AA en dark; danger vía `-strong`
  [`ComponentStatusRow.tsx:22`](../../pwa/src/app/status/_components/ComponentStatusRow.tsx#L22)

**Periferia: tests, docs**

- e2e landing: aserción exacta del canvas v2 — una reversión silenciosa a `#08090a` falla el spec
  [`theme.spec.ts:9`](../../pwa/tests/e2e/frontoffice/theme.spec.ts#L9)

- Casts S4325 eliminados tipando el stub como `Set<EventListener>`
  [`ThemeToggle.test.tsx:26`](../../pwa/tests/components/erpify/ThemeToggle.test.tsx#L26)

- Contrato de theming actualizado: ramp, bordes, caveat subtle/faint, regla `-strong`
  [`DESIGN.md:498`](../../pwa/DESIGN.md#L498)

- Building block del toggle: montajes y regla de texto semántico en dark
  [`CLAUDE.md:279`](../../pwa/CLAUDE.md#L279)

- E9 resuelto + 2 defers nuevos del review (AA semántico en claro, trim de Mercure)
  [`deferred-work.md:149`](deferred-work.md#L149)
