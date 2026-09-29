---
title: 'DW-39 — el Navbar público consulta la sesión: sin «Sign in» para un usuario autenticado'
type: 'feature'
created: '2026-09-29'
status: 'done'
baseline_revision: '3498e8346ff176a6beb497974893cff33d36ca69'
review_loop_iteration: 0
followup_review_recommended: false
context:
  - '{project-root}/pwa/CLAUDE.md'
  - '{project-root}/docs/rules/frontend.md'
warnings: []
deferred: []
---

<intent-contract>

## Intent

**Problem:** El `<Navbar>` público (`/`, `/status`) renderiza «Sign in» incondicionalmente, así que un usuario ya autenticado ve un CTA que le lleva al formulario de login en vez de al ERP.

**Approach:** El Navbar lee `useSession().status`; «Sign in» (escritorio y móvil) sólo se pinta cuando la sesión está resuelta y no es `authenticated`. La entrada «Backoffice» se mantiene para todos, así que el usuario autenticado conserva su entrada al backoffice y el anónimo conserva «Sign in» (decisión 2026-09-28).

## Boundaries & Constraints

**Always:** copy en inglés; hrefs y `router.push` del Navbar y de sus dos páginas pasan por `safeHref(Routes.…)`; `data-testid` existentes intactos; los tests unitarios cubren los estados autenticado y anónimo en ambos clusters.

**Never:** menú de usuario, logout o nombre del usuario en el Navbar público (fuera de la decisión); tocar `AuthProvider`; ocultar «Backoffice» al anónimo.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Autenticado | `status: authenticated` | Sin «Sign in» (escritorio ni móvil); «Backoffice» visible | — |
| Anónimo | `status: unauthenticated` | «Sign in» → `/login` y «Backoffice» visibles | — |
| Hidratando | `status: hydrating` | Sin «Sign in» (no se afirma anonimato por defecto); «Backoffice» visible | — |
| Sin veredicto (503) | `status: unavailable` | «Sign in» visible (la sesión no está confirmada) | — |

</intent-contract>

## Code Map

- `pwa/src/app/_components/Navbar.tsx` -- clusters de acceso: escritorio líneas 54-69, móvil 109-123; hoy sin sesión.
- `pwa/src/context/shared/access/application/useSession.ts` -- hook; lanza fuera de `<AuthProvider>` (montado en `app/layout.tsx`, cubre `/` y `/status`).
- `pwa/src/context/shared/access/infrastructure/ui/AuthProvider.tsx` -- `AuthStatus` (`hydrating|authenticated|unauthenticated|unavailable`) y `AuthContextValue`.
- `pwa/src/app/page.tsx` -- `goToBackOffice` empuja el literal `"/backoffice"`; pasar a `safeHref(Routes.BACKOFFICE)`.
- `pwa/src/app/status/page.tsx` -- `router.push(Routes.BACKOFFICE)`; envolver en `safeHref`.
- `pwa/src/context/shared/navigation/domain/safeHref.ts` -- saneador; patrón `safeHref(Routes.LOGIN)` ya usado en `ForgotPasswordForm.tsx`.
- `pwa/tests/app/(auth)/loginForm.test.tsx:16` -- patrón de `vi.mock` de `useSession`.
- `pwa/tests/e2e/frontoffice/landing.spec.ts` -- usa `authenticatedTest`: sus dos tests de «Sign in» dejarían de ser ciertos; deben moverse a un contexto anónimo (`@playwright/test`).

## Tasks & Acceptance

**Execution:**
- `pwa/src/app/_components/Navbar.tsx` -- leer `status` con `useSession()`, derivar `showSignIn` (`unauthenticated`/`unavailable`), condicionar ambos «Sign in», envolver hrefs en `safeHref` -- núcleo de DW-39.
- `pwa/src/app/page.tsx`, `pwa/src/app/status/page.tsx` -- `router.push(safeHref(Routes.BACKOFFICE))` -- navegación por `safeHref`.
- `pwa/tests/app/_components/navbar.test.tsx` -- nuevo: los cuatro estados de la matriz, escritorio y móvil, «Backoffice» invoca `goToBackoffice`.
- `pwa/tests/e2e/frontoffice/landing.spec.ts` -- el autenticado afirma que «Sign in» no aparece; los tests de «Sign in» pasan a `landing-anonymous.spec.ts` con `@playwright/test`.

**Acceptance Criteria:**
- Given un usuario autenticado en `/`, when se renderiza el Navbar (escritorio y menú móvil abierto), then no existe `navbar__link-login` ni `navbar__link-login--mobile` y sí `navbar__go-to-backoffice-button`.
- Given un visitante anónimo en `/`, when pulsa «Sign in» (escritorio o móvil), then llega a `/login`.
- Given `make pwa.quality` y `make pwa.test.unit`, when se ejecutan, then salen con exit 0.

## Spec Change Log

## Review Triage Log

### 2026-09-29 — Review pass
- verdicts: 20 findings — high 0, medium 4, low 9, false 7, maybe-false 0
- findings:
  - `[low]` `[reject]` (Blind Hunter) Con `unavailable` se ofrece «Sign in» hacia un login que la misma caída rechaza — real pero raro (503 del almacén de sesiones) y `LoginForm` muestra ya un error neutro reintentable; el arreglo exige cambiar la fila `unavailable` de la matriz del intent-contract, y un hallazgo cuyo arreglo es editar la spec se rechaza. Queda como riesgo residual.
  - `[medium]` `[patch]` (Blind Hunter) El e2e autenticado afirma `toHaveCount(0)` antes de que el provider confirme `authenticated` — parcheado: `data-session-status` en el `<nav>` y el e2e espera `authenticated` antes de afirmar la ausencia; falsificado forzando «Sign in» siempre visible (rojo).
  - `[false]` `[reject]` (Blind Hunter) DW-39 sigue `open` en el ledger — la invocación prohíbe editar el ledger; lo registra el orquestador.
  - `[low]` `[reject]` (Blind Hunter) `/status` sin cobertura propia del Navbar consciente de sesión — es el mismo componente bajo el mismo `AuthProvider` del layout raíz; la regresión descrita es hipotética y el arreglo añade un spec entero.
  - `[low]` `[patch]` (Blind Hunter) Desplazamiento de layout en escritorio al aparecer «Sign in» tras hidratar — parcheado: marcador `span` `aria-hidden` e `invisible` que reserva el hueco sólo en `hydrating`, sin testid ni foco.
  - `[low]` `[patch]` (Blind Hunter) El click de «Backoffice» sólo se probaba en dos estados — parcheado: `it.each` sobre los cuatro `AuthStatus`.
  - `[false]` `[reject]` (Blind Hunter) Tests dependientes de `isDevToolsAvailable()` sin mock — ninguna aserción toca el enlace de dev-tools; no hay resultado incorrecto posible.
  - `[false]` `[reject]` (Blind Hunter) `"/login"` literal en vez de `Routes.LOGIN` — afirmar la URL literal es lo que prueba el href real; no es un defecto.
  - `[low]` `[reject]` (Blind Hunter) El e2e anónimo no prueba «Backoffice» en móvil ni su destino — el diff no cambia ese botón y el test unitario cubre el click en todos los estados.
  - `[low]` `[reject]` (Blind Hunter) `page.reload()` redundante en el e2e autenticado — coste de una navegación en un test; despreciable.
  - `[false]` `[reject]` (Blind Hunter) `safeHref` sobre constantes no protege nada — el intent exige navegación por `safeHref`, patrón ya presente (`ForgotPasswordForm.tsx`, `SecuritySignal.tsx`).
  - `[medium]` `[patch]` (Edge Case Hunter) Carrera `/me` vs commit del provider en el e2e autenticado — mismo arreglo que la fila 2.
  - `[low]` `[patch]` (Edge Case Hunter) El predicado exige `ok()`: un 401/503 cuelga el test hasta timeout — parcheado: casa sólo por ruta `/me` y afirma status 200.
  - `[low]` `[patch]` (Edge Case Hunter) Click de «Backoffice» sin probar en `hydrating`/`unavailable` — mismo arreglo que la fila 6.
  - `[medium]` `[patch]` (Verification Gap) El único test que une el `AuthProvider` real y el Navbar pasa en ventana de hidratación — mismo arreglo que la fila 2.
  - `[false]` `[reject]` (Intent Alignment) «Backoffice» sin cambios para el anónimo (lectura B) — la decisión 2026-09-28 sólo pide ocultar «Sign in» y mostrar una entrada al backoffice al autenticado; ambas se cumplen.
  - `[false]` `[reject]` (Intent Alignment) Los estados `hydrating`/`unavailable` los decide el implementador — la matriz de la spec los fija explícitamente.
  - `[low]` `[reject]` (Intent Alignment) Sin test sobre `/status` — duplicado de la fila 4, misma razón.
  - `[false]` `[reject]` (Intent Alignment) `safeHref` más amplio que los enlaces nuevos — el intent pide la navegación del Navbar por `safeHref`, y los `router.push` de sus dos páginas son esa navegación.
  - `[medium]` `[patch]` (Intent Alignment) El e2e autenticado sólo espera la respuesta de `/me` — mismo arreglo que la fila 2.

## Design Notes

`hydrating` oculta «Sign in» en lugar de mostrarlo por defecto: mostrarlo reintroduce el defecto (un parpadeo de «Sign in» al autenticado en cada carga de `/`), mientras que ocultarlo sólo retrasa unos ms su aparición al anónimo; «Backoffice» ya está siempre, así que el cluster no queda vacío. `unavailable` lo muestra: el servidor no confirmó sesión y el `AuthProvider` re-sondea al navegar.

## Verification

**Commands:**
- `make pwa.test.unit` -- expected: exit 0, incluido `navbar.test.tsx`.
- `make pwa.quality` -- expected: exit 0.

## Auto Run Result

Status: done

**Resumen:** el `<Navbar>` público lee `useSession().status`; «Sign in» (escritorio y móvil) sólo se pinta con `unauthenticated`/`unavailable`, oculto al autenticado y durante la hidratación (con un marcador invisible que reserva su hueco en escritorio). «Backoffice» sigue para todos. Toda la navegación del Navbar y de sus dos páginas pasa por `safeHref(Routes.…)`.

**Ficheros:**
- `pwa/src/app/_components/Navbar.tsx` — cluster de acceso consciente de sesión, `data-session-status`, `safeHref`.
- `pwa/src/app/page.tsx` — `router.push(safeHref(Routes.BACKOFFICE))` en lugar del literal.
- `pwa/src/app/status/page.tsx` — `router.push(safeHref(Routes.BACKOFFICE))`.
- `pwa/tests/app/_components/navbar.test.tsx` — nuevo: 16 tests, los cuatro estados de la matriz en ambos clusters.
- `pwa/tests/e2e/frontoffice/landing.spec.ts` — el autenticado afirma la ausencia de «Sign in» tras `data-session-status="authenticated"`.
- `pwa/tests/e2e/frontoffice/landing-anonymous.spec.ts` — nuevo: los tests de «Sign in» → `/login` en contexto anónimo.

**Revisión:** 20 hallazgos; 5 entradas parcheadas (carrera del e2e autenticado ×4 capas, predicado `ok()`, desplazamiento de layout, click de «Backoffice» en los cuatro estados); 0 diferidas; rechazados con su razón en el triage log (7 `false`, 6 `low`).

**Recomendación de segunda revisión:** `false` — parcheadas 1 entrada `medium` y 3 `low`, ninguna `high`.

**Verificación:** `make pwa.test.unit` exit 0 (266 ficheros, 1980 tests, `navbar.test.tsx` 16/16); `make pwa.quality` exit 0; ambos specs e2e de landing 7/7 contra el stack de este worktree (según el implementador), con falsificación del e2e autenticado en rojo.

**Riesgos residuales:** con `unavailable` (503 de `/me`) se ofrece «Sign in» a un visitante cuya sesión no se pudo decidir, que puede estar autenticado; `LoginForm` responde con error neutro reintentable. La suite e2e completa no se ejecutó.
