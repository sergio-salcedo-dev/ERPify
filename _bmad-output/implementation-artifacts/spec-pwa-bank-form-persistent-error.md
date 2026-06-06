---
title: 'PWA: error de mutación persistente en BankForm (crear/editar) — goal C'
type: 'feature'
created: '2026-06-05'
status: 'done'
baseline_commit: '0e1e969'
worktree: '.claude/worktrees/pwa-bank-form-persistent-error-mk40 — rama feat/pwa-bank-form-persistent-error-mk40'
context:
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-06-03/EXPERIENCE.md'
  - '{project-root}/_bmad-output/implementation-artifacts/deferred-work.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Al fallar el guardado en `BankForm` (crear/editar), el problem no-violation se muestra en un `ProblemDisplay` inline sin dismiss, copia ni contrato de foco — el único flujo de mutación de bancos que aún no cumple la superficie persistente (EXPERIENCE.md § «Errores de mutación») que lista/detalle ya adoptaron (PR #150). Goal C de `deferred-work.md`.

**Approach:** Sustituir el inline por `MutationError` (reutilizar su API, no duplicar) sobre el formulario: dismiss ×, sustitución por reintento, limpieza en éxito y en violations-todas-mapeadas. Recuperación tipada solo en edit: PUT 404 `bank-not-found` → "Refresh" re-dispara la carga de la página → `NOT_FOUND` → EmptyState existente.

## Boundaries & Constraints

**Always:**
- Las spines (`EXPERIENCE.md` + `DESIGN.md`) ganan ante cualquier conflicto.
- Problem **verbatim** del wire — prohibido sintetizar problems client-side. Sin clamp.
- `MutationError` se consume tal cual (`problem/onDismiss/action/focusOnMount/testId`); el mapeo type→acción vive en el origen, nunca en el componente.
- Las violations 400 mapeables siguen yendo a errores RHF (`setError`); solo el problem residual (sin violations / parcialmente mapeado) ocupa la superficie.
- `data-testid` existentes intactos; `bank-form__mutation-error` nuevo y único; BEM + `cn()`; `make pwa.quality` limpio al cierre.

**Ask First:**
- Cualquier cambio al API de `MutationError` / `ProblemDisplay` (compartidos fuera de `banks/`).
- Si algún e2e existente (`banks-real-api-flows`) exigiera cambio de contrato (no solo de selector).

**Never:**
- Tocar `api/`. Toast de error en el formulario (la superficie nace enfocada en el viewport del origen; el toast-puntero es para confirms que se cierran). Tocar el `ERROR` de **carga** de la edit page (error de lectura, no de mutación). `maxLength`. Refactors oportunistas.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Fallo no mapeado (500/red/422 sin violations) | submit create o edit | `MutationError` sobre el form, problem verbatim, foco al montar; sin acción | N/A |
| Edit: banco borrado en paralelo | PUT → 404 `bank-not-found` | Acción **"Refresh"** → re-carga → `NOT_FOUND` → EmptyState existente; foco al CTA "Back to banks" (nunca `<body>`) | N/A |
| Create: cualquier type | POST falla | Nunca hay acción de recuperación | N/A |
| 400 violations todas mapeadas | submit | Errores de campo RHF; la superficie se **limpia** (no sobrevive a un outcome ya representado en campos) | N/A |
| 400 violations parcialmente mapeadas | submit | Errores de campo **y** `MutationError` con el problem íntegro | N/A |
| Reintento | error visible → nuevo submit | Permanece durante el vuelo; al fallar se **sustituye** (re-foco vía `[problem]`); al triunfar → toast éxito + navegación | N/A |
| Dismiss | click × | `setProblem(null)`; el form queda como estaba | N/A |

</frozen-after-approval>

## Code Map

- `pwa/src/app/backoffice/banks/_components/BankForm.tsx` — sustituir `ProblemDisplay` (`:148`); lifecycle en `handleHttpError` (`:81-102`: limpiar en mapeo completo) y `onSubmit` (`:105`: quitar el `setProblem(null)` eager — sustitución al outcome, no al despegue); prop `onStaleBank?: () => void`; mapeo `BankProblemType.NOT_FOUND` + edit → botón Refresh (espejo de `DeleteErrorPanel`, `[id]/page.tsx:354-384`).
- `pwa/src/app/backoffice/banks/[id]/edit/page.tsx` — `reloadToken` en deps del effect de carga (`:51-77`); pasar `onStaleBank`; foco al CTA `banks-edit__back-to-list` cuando `NOT_FOUND` llega tras refresh iniciado por el form (`:98-118`).
- `pwa/src/components/erpify/MutationError.tsx` — solo lectura; copia y paridad `debug` ya cubiertas por `MutationError.test.tsx`.
- `pwa/src/context/backoffice/bank/domain/BankProblemType.ts` — `NOT_FOUND` existente; sin constantes nuevas.
- `pwa/tests/app/backoffice/banks/bankFormFeedback.test.tsx` — arnés de referencia para los tests nuevos.
- `pwa/tests/e2e/fixtures/banks-api.ts` — extender si falta: 500 en POST / 404 en PUT por id.

## Tasks & Acceptance

**Execution:**
- [x] Worktree: `make worktree.create BRANCH=feat/pwa-bank-form-persistent-error` — todo lo siguiente dentro.
- [x] `BankForm.tsx` — render + lifecycle + `onStaleBank` + mapeo Refresh según Code Map.
- [x] `[id]/edit/page.tsx` — `reloadToken` + `onStaleBank` + foco post-refresh al CTA not-found.
- [x] `pwa/tests/app/backoffice/banks/bankFormMutationError.test.tsx` — NUEVO: matriz I/O a nivel form (fallo no mapeado→foco, dismiss, sustitución, mapeo completo limpia, parcial muestra ambos, acción solo en edit+404).
- [x] `pwa/tests/app/backoffice/banks/bankEditStaleBank.test.tsx` — NUEVO: PUT 404 → Refresh → `NOT_FOUND` → EmptyState + foco al CTA.
- [x] `pwa/tests/e2e/backoffice/banks-form-errors.spec.ts` — NUEVO (API mockeada): create 500 → error persistente visible/copiable/dismissible; edit 404 → Refresh → not-found; navegación no arrastra el error.

**Acceptance Criteria:**
- Given un fallo de guardado no mapeado, when aparece la superficie, then recibe foco (`tabIndex={-1}`) y se anuncia una sola vez (el `role="alert"` interno; sin live region duplicada).
- Given el error visible, when el usuario navega (Cancel, Back), then el error no le sigue.
- Given un error persistente previo, when un nuevo submit termina en violations todas mapeadas, then la superficie se limpia y solo quedan los errores de campo.
- Given create mode, then nunca se renderiza acción de recuperación, sea cual sea el `problem.type`.

## Spec Change Log

## Design Notes

El error vive dentro de `BankForm` (el form **es** el origen de la mutación, a diferencia del delete): render en la posición actual del inline = "sobre el formulario". El mapeo type→acción en `BankForm` es legítimo: es componente bank-specific (la regla de A solo lo prohíbe en el *genérico*). Refresh en edit: bump de `reloadToken` → effect re-corre → 404 → `NOT_FOUND` desmonta form y error juntos; el foco va al CTA del EmptyState, como en el detalle (`[id]/page.tsx:145-152`, vía `pendingRefreshFocusRef`).

## Verification

**Commands:**
- `make pwa.test.unit` — expected: verde, incl. los dos tests nuevos y `bankFormFeedback` intacto.
- `make pwa.test.e2e c='banks-form-errors'` — expected: verde (recordar `PLAYWRIGHT_HOST_PLATFORM_OVERRIDE=ubuntu24.04-x64` en este host).
- `make pwa.quality` — expected: sin hallazgos nuevos.

**Manual checks (if no CLI):**
- En dev: editar un banco borrado en otra pestaña → el 404 queda legible/copiable sobre el form; Refresh lleva al not-found.

## Suggested Review Order

**La superficie persistente en el formulario (punto de entrada)**

- El swap: el inline sin contrato se sustituye por `MutationError` con dismiss, copia y acción
  [`BankForm.tsx:182`](../../.claude/worktrees/pwa-bank-form-persistent-error-mk40/pwa/src/app/backoffice/banks/_components/BankForm.tsx#L182)

- Mapeo type→acción en el origen: Refresh solo en edit + `bank-not-found`; create nunca recupera
  [`BankForm.tsx:158`](../../.claude/worktrees/pwa-bank-form-persistent-error-mk40/pwa/src/app/backoffice/banks/_components/BankForm.tsx#L158)

- Lifecycle: violations todas-mapeadas limpian la superficie (outcome ya representado en campos)
  [`BankForm.tsx:105`](../../.claude/worktrees/pwa-bank-form-persistent-error-mk40/pwa/src/app/backoffice/banks/_components/BankForm.tsx#L105)

- Sin clear eager al despegue: sustitución al outcome; éxito limpia explícitamente antes de navegar
  [`BankForm.tsx:116`](../../.claude/worktrees/pwa-bank-form-persistent-error-mk40/pwa/src/app/backoffice/banks/_components/BankForm.tsx#L116)

- El contrato del prop `onStaleBank`: hook de recuperación solo-edit, dueño = la página
  [`BankForm.tsx:41`](../../.claude/worktrees/pwa-bank-form-persistent-error-mk40/pwa/src/app/backoffice/banks/_components/BankForm.tsx#L41)

**Recuperación del 404 obsoleto en la edit page**

- `reloadToken` re-dispara la carga; el Refresh del form lo bumpea y arma el foco
  [`page.tsx:46`](../../.claude/worktrees/pwa-bank-form-persistent-error-mk40/pwa/src/app/backoffice/banks/%5Bid%5D/edit/page.tsx#L46)

- Foco post-refresh: CTA del not-found, con fallback al contenedor — nunca `<body>` (espejo del detalle)
  [`page.tsx:91`](../../.claude/worktrees/pwa-bank-form-persistent-error-mk40/pwa/src/app/backoffice/banks/%5Bid%5D/edit/page.tsx#L91)

- El contenedor se vuelve focusable (`tabIndex={-1}`) para sostener ese fallback
  [`page.tsx:102`](../../.claude/worktrees/pwa-bank-form-persistent-error-mk40/pwa/src/app/backoffice/banks/%5Bid%5D/edit/page.tsx#L102)

**Periféricos — fixtures y tests**

- Escenarios nuevos del mock e2e: POST 500 y PUT 404 por id (solo aditivo)
  [`banks-api.ts:69`](../../.claude/worktrees/pwa-bank-form-persistent-error-mk40/pwa/tests/e2e/fixtures/banks-api.ts#L69)

- Matriz I/O a nivel form: foco, dismiss, sustitución en vuelo, mapeo completo/parcial, acción solo edit+404
  [`bankFormMutationError.test.tsx:124`](../../.claude/worktrees/pwa-bank-form-persistent-error-mk40/pwa/tests/app/backoffice/banks/bankFormMutationError.test.tsx#L124)

- Los dos aterrizajes del Refresh: not-found→CTA y error→contenedor
  [`bankEditStaleBank.test.tsx:62`](../../.claude/worktrees/pwa-bank-form-persistent-error-mk40/pwa/tests/app/backoffice/banks/bankEditStaleBank.test.tsx#L62)

- E2E con API mockeada: create 500 copiable/dismissible, edit 404→Refresh→not-found, el error no viaja
  [`banks-form-errors.spec.ts:14`](../../.claude/worktrees/pwa-bank-form-persistent-error-mk40/pwa/tests/e2e/backoffice/banks-form-errors.spec.ts#L14)
