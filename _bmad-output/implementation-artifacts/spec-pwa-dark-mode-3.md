---
title: 'Dark mode v3 — navy slate + acento azul mode-aware (sustituye el índigo)'
type: 'feature'
created: '2026-06-04'
status: 'done'
context: ['spec-pwa-dark-mode-2.md']
baseline_commit: '385d032'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** El owner ve el dark v2 (ramp grafito `#16181d–#2b303a`, PR #136/#139) y sigue sin convencerle: (1) el ramp neutro se percibe plano y sin vida — las superficies no se separan con carácter; (2) el acento índigo `#5e6ad2`/`#7170ff` heredado de Linear no encaja con la marca deseada. Exploración visual (Stitch MCP caído — 4 generaciones fallidas; fallback: hoja HTML side-by-side con 4 paletas sobre la superficie banks real) → ganadora **B: navy-tinted, acento azul vivo**. Bonus detectado por cálculo: el índigo v2 como texto-link daba **3.44:1** sobre `bg-elevated` — violación AA latente que esta paleta corrige.

**Approach:** Re-tematizar el bloque `.dark` a un ramp navy `#11151f → #161b29 → #1d2433 → #242e42` con bordes blue-tinted `rgba(165,180,220,…)`, y sustituir la familia índigo por la familia azul (~hue 225°) en **ambos modos**. El acento pasa a ser **mode-aware** (un único valor no puede ser link-AA sobre blanco y sobre navy a la vez — nombres de token intactos, solo divergen los valores como ya hace el resto del ramp). Neutrales y texto del modo claro intactos.

## Boundaries & Constraints

**Always:**
- Solo cambian **valores** de tokens existentes — cero tokens nuevos, cero renombres, cero literales de color en TSX.
- En light solo cambia la familia accent (7 valores: `brand`, `accent`, `accent-hover`, `accent-active`, `focus-ring`, `security`, `chart-3`); neutrales, texto y bordes light intactos.
- AA (≥4.5:1) verificado por cálculo: texto primario y secundario sobre las 4 superficies oscuras; `accent` como texto sobre las superficies de su modo; blanco sobre `brand` en ambos modos.
- Regla v2 `-strong`-como-texto intacta (`danger-strong` 4.92:1 sobre el nuevo elevated ✓).
- Mecanismo next-themes del v1/v2 intacto (provider, storageKey, ciclo del toggle).

**Ask First:**
- Cualquier token nuevo o renombre; cualquier cambio en neutrales/texto del modo claro; cualquier cambio en `next.config.ts#headers()`.

**Never:**
- `dangerouslySetInnerHTML`; config Tailwind v3; tocar el mecanismo de theming.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Dark + backoffice | `.dark` en `<html>` | superficies navy con jerarquía clara (sidebar ≠ canvas ≠ card), links/active en `#6c9bff` | N/A |
| Dark + landing/`/status` | token-driven desde v2 | heredan el ramp navy sin tocar TSX | N/A |
| Light | sin `.dark` | neutrales idénticos a hoy; CTA/links pasan de índigo a azul `#2f5cd9` | N/A |
| Focus visible | Tab por controles | ring `#2f5cd9` (light) / `#6c9bff` (dark) ≥3:1 vs superficie | N/A |
| Toggle | light → dark → system | ciclo y persistencia intactos (sin cambios de mecanismo) | N/A |
| e2e canvas | aserción de color exacta | `#11151f` (antes `#16181d`) — reversión silenciosa falla el spec | N/A |

</frozen-after-approval>

## Code Map

- `pwa/src/app/globals.css` — `:root`: 7 valores de la familia accent; `.dark`: surface ramp, text ramp, borders, line-tint, familia accent, `chart-3`; comentario de cabecera re-benchmarkeado (navy band, no "Linear-similar")
- `pwa/DESIGN.md` — tablas surface/text/borders/brand-accent; la nota "same in both modes" pasa a mode-aware; referencias "Linear Brand Indigo" / "Accent Violet" actualizadas
- `pwa/tests/e2e/frontoffice/theme.spec.ts` — aserción exacta del canvas `#16181d` → `#11151f`
- `pwa/tests/e2e/backoffice/theme.spec.ts` — revisar si asserta colores exactos
- `pwa/CLAUDE.md` — bullet "Theme / dark mode" si referencia valores concretos

## Tasks & Acceptance

**Execution:**
- [x] `globals.css` `.dark` -- surface ramp: bg `#11151f`, muted `#161b29`, subtle `#1d2433`, elevated `#242e42`; texto `#e7eaf3` / `#aeb6cb` / `#8590a8` / faint `#66708a`; borders `rgba(165,180,220,.07/.12/.20)`; line-tint `#141828`; overlay y shadows v2 intactos -- ramp navy con jerarquía
- [x] `globals.css` accent ambos modos -- light: brand/accent `#2f5cd9`, hover `#4a73e8`, active `#2450b8`, focus-ring `#2f5cd9`, security `#7589ad`, chart-3 `#2f5cd9`; dark: brand `#3760e6`, accent `#6c9bff`, hover `#87adff`, active `#5586f2`, focus-ring `#6c9bff`, security `#7589ad`, chart-3 `#6c9bff` -- índigo eliminado del sistema
- [x] `theme.spec.ts` (frontoffice) -- canvas assertion `#11151f` -- candado anti-reversión
- [x] `DESIGN.md` -- tablas + nota mode-aware + purga de referencias índigo/Linear en la sección de color -- docs fieles al sistema
- [x] `pwa/CLAUDE.md` -- revisar bullet theming -- coherencia
- [x] Verificación visual -- stack del worktree + playwright-cli: backoffice banks, landing, `/status` en dark; light sin regresión de neutrales; capturas para el PR -- evidencia

**Acceptance Criteria:**
- Given dark activo, when navego backoffice/landing/`/status`, then todas las superficies son del ramp navy, los links/active usan `#6c9bff` y no queda ningún `#5e6ad2`/`#7170ff` computado.
- Given light activo, when comparo con main, then los neutrales son idénticos y solo la familia accent cambió a azul.
- Given los ratios calculados, then texto ≥11.3:1, texto-muted ≥6.7:1, accent-como-texto ≥5.0:1, blanco-sobre-brand ≥5.2:1 (dark) / ≥5.7:1 (light), danger-strong ≥4.9:1.
- Given `make pwa.quality` + `make pwa.test.unit`, then 100 % verde; e2e theme specs verdes en CI.

## Spec Change Log

- 2026-06-04 — Owner aprueba (rebase sobre el rediseño entity-list de `main`) re-derivar el token nuevo `--erpify-row-selected` desde la marca v3: light `#eef0fb` → `#eef2fc` (brand `#2f5cd9` al ~8% sobre blanco), dark `#16182b` → `#151d33` (brand `#3760e6` al ~10% sobre el canvas navy). Motivo: el valor de `main` derivaba de la marca índigo y del ramp gris v2 retirados; el contrato documentado del token («brand-derived») exige re-derivación. Verificado: dots `#0f7a5a`/`#b45309` ≥3:1 sobre el nuevo tint light (4.74/4.48), semantics dark ≥3:1 sobre el dark (6.59/7.79). Excepción puntual al «Always: en light solo cambia la familia accent (7 valores)» — un octavo valor light, brand-derived, introducido por `main` después del baseline.

## Design Notes

- **Ratios (calculados 2026-06-04, WCAG 2.x):** texto `#e7eaf3` 11.31–15.17:1; muted `#aeb6cb` 6.70–9.00:1; accent dark `#6c9bff` 5.03–6.75:1; blanco sobre `#3760e6` 5.28:1; blanco sobre `#2f5cd9` 5.75:1; accent light `#2f5cd9` 5.40:1 sobre `#f7f8f8`; `danger-strong` 4.92:1 sobre elevated. Subtle `#8590a8` 4.24:1 sobre elevated — mismo caveat documentado del v2 (placeholders/metadata, no body).
- **Acento mode-aware:** v2 mantenía un único valor de accent en ambos modos y eso producía el 3.44:1 latente. La estructura de tokens ya divergía por modo en todo lo demás; el accent se alinea con ese patrón.
- **Botón `#3760e6` vs hoja `#3d6bff`:** el valor aprobado visualmente daba 4.43:1 con texto blanco; se oscurece un paso (visualmente casi idéntico) para cruzar AA.
- **Benchmark:** la banda de luminosidad v2 (validada) se conserva; el undertone navy (B>G>R) da la separación de superficies que el grafito neutro no conseguía. Referentes: GitHub dimmed (undertone azul), Stripe/Vercel (navy band).
- **Artefacto de exploración:** hoja side-by-side `/tmp/erpify-dark-v3-palettes.html` (efímera, no se commitea); Stitch MCP no operativo durante la sesión (project `8080770760216534632` quedó vacío).

## Verification

**Commands:**
- `make pwa.quality` (raíz del worktree) -- expected: sin errores
- `make pwa.test.unit` -- expected: 100 % verde
- Script de contraste (python, en el spec/PR) -- expected: ratios de Design Notes

**Manual checks (if no CLI):**
- playwright-cli contra el stack del worktree (`HTTPS_PORT=8443 make docker.up`): dark en backoffice banks / landing / status, light sin regresión; capturas para el PR.
- e2e en CI (`pwa.test.e2e` local bloqueado: sin browsers Playwright en este host).
