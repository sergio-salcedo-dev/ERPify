---
baseline_commit: ed045daa019438581222598e61ac79abedef0827
---

# Story 2.5: PWA · Delete-guard + recovery de bank-in-use (PR5)

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a usuario del backoffice,
I want que el sistema me advierta amigablemente si intento borrar un banco con cuentas asociadas, en lugar de fallar abruptamente o bloquearme,
so that la UX fluya hacia la acción correctiva ("View accounts") reconociendo al backend como la única fuente de verdad.

## Acceptance Criteria

1. **Guard optimista pre-DELETE (`accountCount > 0`).** Al disparar el borrado de un banco cuyo `accountCount > 0` (señal ya presente en `Bank.accountCount`), la UI **no abre el diálogo destructivo y no emite la llamada `DELETE`**. En su lugar muestra una superficie **neutra** (tono no destructivo) anclada al disparador con el mensaje **"Can't delete — N associated accounts"** (N = `accountCount`, con concordancia singular/plural: `1 associated account` / `N associated accounts`) y un botón **"View accounts"** que navega a `/backoffice/banks/{id}/accounts`. Disponible en los tres orígenes de borrado: fila de la **tabla**, fila de **cards** y página de **detalle**.
2. **Recovery ante carrera (`409 bank-in-use`).** Cuando `accountCount` parecía `0` (cache stale / carrera TOCTOU) pero el backend rechaza el `DELETE` con `409` `type: "bank-in-use"`, el `<MutationError>` persistente ya existente **incorpora un botón "View accounts"** de recuperación (mismo destino que AC#1). Esto aplica tanto en la **lista** (`banks-list__delete-error`) como en el **detalle** (`banks-detail__delete-error`). El `type` `bank-not-found` conserva su acción "Refresh"/"Refresh list" actual sin cambios.
3. **Backend = guard autoritativo.** El frontend **no** asume autoridad: el guard optimista (AC#1) es una mejora de UX, no un sustituto. Si por cualquier estado del frontend se fuerza un `DELETE /banks/{id}` de un banco en uso, el backend sigue abortándolo con `BankInUseException` (409). **No se modifica el contrato ni el código del API en esta historia** — sólo se consume el `409` ya emitido (PR #248/#249).

## Tasks / Subtasks

- [x] **Task 1 — Recovery "View accounts" en el `<MutationError>` para `bank-in-use` (AC: #2, #3)**
  - [x] Red: test de la lista (`pwa/tests/app/backoffice/banks/…` o el spec que cubra el render de `deleteError`) que, dado un `deleteError` con `problem.type === "bank-in-use"`, espera un control "View accounts" con `href` = `bankRoutes.accounts(bankId)` dentro de `banks-list__delete-error`. Debe **fallar** primero (hoy la acción es `undefined` para 409).
  - [x] Verde (lista): en `pwa/src/app/backoffice/banks/page.tsx`, extender `deleteRecoveryAction` (≈línea 614) para cubrir `BankProblemType.IN_USE`. Para `IN_USE` renderizar un `<Link>` estilizado como botón (`buttonVariants({ variant: "outline", size: "sm" })`, patrón de `bankRoutes.new` en esta misma página) con `href={safeHref(bankRoutes.accounts(deleteError.bankId))}`, `aria-label="View associated accounts"`, `data-testid="banks-list__delete-error-view-accounts"`, texto "View accounts". El `deleteError` ya guarda `bankId` (`DeleteErrorState`, ≈línea 85). Mantener el caso `NOT_FOUND` → "Refresh list" intacto.
  - [x] Verde (detalle): en `pwa/src/app/backoffice/banks/[id]/page.tsx`, `DeleteErrorPanel` (≈línea 375) ya recibe `problem`; extender su `action` para que, cuando `problem.type === BankProblemType.IN_USE`, renderice el `<Link>` "View accounts" → `safeHref(bankRoutes.accounts(bank.id))` (el `id` está disponible vía `params`/`bank`), `data-testid="banks-detail__delete-error-view-accounts"`. Conservar el caso `NOT_FOUND` → "Refresh".
  - [x] Verde: ambos call-sites reutilizan `bankRoutes.accounts(id)` (ya hace `encodeURIComponent`) envuelto en `safeHref(...)` — no construir rutas a mano.

- [x] **Task 2 — Guard optimista pre-DELETE en el disparador de borrado (AC: #1, #3)**
  - [x] Red: tests del disparador (nuevo `pwa/tests/app/backoffice/banks/bankDeleteGuard.test.tsx` o equivalente) que verifiquen: (a) con `accountCount > 0` un click en "Delete" **no** invoca el use case `DeleteBank` (ni abre el `banks-detail__delete-dialog`), y muestra la superficie neutra con texto "Can't delete — N associated accounts" + control "View accounts"; (b) con `accountCount === 0` el flujo destructivo actual (diálogo de confirmación) se conserva intacto.
  - [x] Decisión de primitiva (documentar en Dev Agent Record): la superficie neutra de AC#1 es **no destructiva** y de baja fricción. Preferir un **Popover** anclado al disparador usando `@base-ui/react/popover` (v1.5.0 ya instalada) envuelto en un wrapper `pwa/src/components/ui/popover.tsx` siguiendo el patrón de `dialog.tsx`/`dropdown-menu.tsx`/`tooltip.tsx` (mismos `data-slot`, estilos Tailwind). **Verificar el export** (`import { Popover } from "@base-ui/react/popover"`) antes de implementar. **Fallback** si el subpath no exporta `Popover` en 1.5.0: reutilizar el `Dialog` existente con una variante neutra (sin botón destructivo, sólo "View accounts" + "Close") — **no** introducir otra librería.
  - [x] Verde — `DeleteBankButton` (`pwa/src/app/backoffice/banks/_components/DeleteBankButton.tsx`): añadir prop `accountCount: number`. Cuando `accountCount > 0`, el disparador NO abre el `Dialog` destructivo; en su lugar abre la superficie neutra (popover/diálogo neutro) con el mensaje y "View accounts" → `safeHref(bankRoutes.accounts(id))`. Cuando `accountCount === 0`, comportamiento actual sin cambios. El `handleConfirm`/`DeleteBank.run` **sólo** es alcanzable en la rama `accountCount === 0`.
  - [x] Verde — propagar `accountCount` por los call-sites:
    - `BankRowActions` (`_components/BankRowActions.tsx`): añadir prop `accountCount` y pasarla a `DeleteBankButton`. El menú `⋯ → Delete` con `accountCount > 0` abre la superficie neutra, no el diálogo.
    - Tabla (`_components/BanksTable.tsx`) y cards: pasar `row.accountCount` a `BankRowActions`.
    - Detalle (`[id]/page.tsx`): pasar `bank.accountCount` al `DeleteBankButton` del header.
  - [x] Verde: concordancia singular/plural del texto N (`1 associated account` vs `N associated accounts`) — espejo del patrón del API en `BankInUseException::withAccountCount`.

- [x] **Task 3 — Tests E2E (mocked) del guard + recovery (AC: #1, #2)**
  - [x] Extender `pwa/tests/e2e/backoffice/banks-delete-preconditions.spec.ts`:
    - **Actualizar** el caso `409 bank-in-use` existente (≈líneas 23-51): hoy asevera "no recovery action button"; ahora debe asever**ar** la presencia de "View accounts" (`banks-list__delete-error-view-accounts`) y que su `href` apunta a `…/banks/{id}/accounts`. Conservar el resto (surface persistente, copy toolbar, fila no removida, dismiss).
    - **Nuevo** caso guard optimista: en una fila/detalle con `accountCount > 0`, click en Delete → **no** se emite `DELETE` (no hay request al endpoint; usar `page.route`/contador), aparece la superficie neutra "Can't delete — N associated accounts" + "View accounts", y al pulsar "View accounts" navega a la ruta de cuentas.
  - [x] Nota Testing: los browsers de Playwright están bloqueados en esta distro para correr en local (ver memoria `pwa-e2e-local-ownership-blocker`). Estrategia: **unit (Vitest) + `make pwa.quality` en local; los e2e corren en CI**. Documentar en Completion Notes.

- [x] **Task 4 — Gate de calidad y verificación de ACs (AC: #1, #2, #3)**
  - [x] `make pwa.test.unit` verde (incluyendo los nuevos tests).
  - [x] `make pwa.quality` EXIT 0 (ESLint + Prettier + `tsc --noEmit`).
  - [x] Auto-review de seguridad PWA del diff (XSS / open-redirect): todo `href`/navegación con `id` pasa por `safeHref` + `encodeURIComponent`; sin `dangerouslySetInnerHTML`/`innerHTML`/`eval`; `aria-label`/`title` estáticos; ningún valor de cuenta o IBAN tocado aquí (el guard sólo usa `accountCount`, un entero). Sin nuevas dependencias; `next.config.ts` (CSP/headers) intacto.
  - [x] Verificar las 3 ACs explícitamente contra el código.

## Dev Notes

### Contexto del Epic y de las PRs previas

- **Stack de PRs (ninguna en `main` aún):** #248 (Stories 2.1+2.4: `accountCount` en lista/detalle/realtime) → #249 (Stories 2.2+2.3: endpoint de cuentas + superficie `/backoffice/banks/{id}/accounts`). Esta historia (PR5) **se apila sobre #249** (rama base `feat/backoffice-bank-accounts-endpoint`). El worktree ya contiene el código de ambas.
- **Invariante #1 del Epic 2:** un `accountCount === 0` se renderiza atenuado y **no** enlaza; sólo `> 0` enlaza a la superficie de cuentas. El guard de borrado respeta esta misma señal.
- Esta historia es **PWA-only**: el `409 bank-in-use` y el guard FK/TOCTOU del API ya existen (`BankInUseException`, `BankDeleter`), no se tocan.

### Estado actual de los ficheros a MODIFICAR (leer antes de editar)

- **`pwa/src/app/backoffice/banks/_components/DeleteBankButton.tsx`** — disparador de borrado (controlado/uncontrolado). Hoy: cualquier click abre el `Dialog` destructivo; `handleConfirm` → `container.get<DeleteBank>("BackOfficeDeleteBank").run(id)`; en `HttpError` cierra el diálogo, hace `onError(err.problem)` + toast transitorio. **Cambio:** añadir `accountCount`; con `> 0` cortocircuitar a la superficie neutra antes de cualquier `DELETE`. **Preservar:** modos controlado (menú `⋯`) y uncontrolado (detalle), `onDeleted`/`onError`, el toast y el contrato "el error nunca vive en el diálogo".
- **`pwa/src/app/backoffice/banks/_components/BankRowActions.tsx`** — cluster por fila (Copy/Edit directos; Delete demotado al `⋯`). **Cambio:** prop `accountCount` → `DeleteBankButton`. **Preservar:** el patrón controlado del diálogo (un menú-item no puede ser trigger de diálogo sin conflicto de foco) y los testids `banks-{surface}__…`.
- **`pwa/src/app/backoffice/banks/_components/BanksTable.tsx`** — ya tiene `row.accountCount` (celda "ACCOUNTS" con `bankRoutes.accounts`). **Cambio:** pasar `row.accountCount` a `BankRowActions`. (Aplicar lo mismo al surface de cards.)
- **`pwa/src/app/backoffice/banks/page.tsx`** — `deleteError: DeleteErrorState | null` (`{ problem, bankId, scope }`, ≈línea 85-104). `deleteRecoveryAction` (≈línea 614) hoy: `NOT_FOUND` → "Refresh list", resto → `undefined`. `<MutationError … action={deleteRecoveryAction} />` (≈línea 717). **Cambio:** rama `IN_USE` → `<Link>`-botón "View accounts".
- **`pwa/src/app/backoffice/banks/[id]/page.tsx`** — `deleteProblem` + `DeleteErrorPanel` (≈línea 375) con `action` `NOT_FOUND` → "Refresh"; header con `DeleteBankButton` y `bank.accountCount` (campo "Associated accounts", ≈línea 329). **Cambio:** `IN_USE` → "View accounts" en `DeleteErrorPanel`; pasar `bank.accountCount` al `DeleteBankButton`.

### Componentes/primitivas a REUTILIZAR (no reinventar)

- **`MutationError`** (`pwa/src/components/erpify/MutationError.tsx`) — superficie persistente con `action?: ReactNode`. **Ya soporta** la acción de recovery; AC#2 sólo añade un nuevo `action` para `IN_USE`. No modificar el componente.
- **`BankProblemType`** (`pwa/src/context/backoffice/bank/domain/BankProblemType.ts`) — ya define `IN_USE: "bank-in-use"` y `NOT_FOUND`. Usar estas constantes, nunca strings sueltos.
- **`bankRoutes.accounts(id)`** (`pwa/src/app/backoffice/banks/_lib/bankRoutes.ts`) — builder con `encodeURIComponent`. Reutilizar para todo "View accounts".
- **`safeHref`** (`@/lib/safeHref`), **`buttonVariants`** (`@/components/ui/button-variants`) para `<Link>`-como-botón, **`Button`** (`@/components/ui/button`).
- **Popover (AC#1):** preferir `@base-ui/react/popover` (v1.5.0) con wrapper nuevo `pwa/src/components/ui/popover.tsx` (espejo de `dialog.tsx`). Verificar el export antes; fallback a `Dialog` neutro.

### Decisiones de diseño

- **Guard optimista, no autoritativo (AC#3):** el frontend usa `accountCount` para evitar el viaje y dar feedback inmediato; el backend permanece como verdad. La rama de carrera (AC#2) existe precisamente porque la señal del cliente puede estar stale.
- **Dos puntos de defensa, un destino:** AC#1 (pre-DELETE) y AC#2 (post-409) llevan ambos a `/backoffice/banks/{id}/accounts` vía el mismo helper.
- **Texto consistente:** singular/plural de "associated account(s)" coincide con el del API.

### Testing standards

- **Vitest** (`pwa/tests/app/backoffice/banks/**`): render-level (Testing Library), assert por `data-testid` y por nombre accesible. Tests primero (red) en cada task.
- **Playwright mocked** (`pwa/tests/e2e/backoffice/banks-delete-preconditions.spec.ts` + fixtures `pwa/tests/e2e/fixtures/banks-api.ts`): escenarios de delete. **e2e local bloqueado** (ver memoria); verde local = unit + `make pwa.quality`, e2e en CI.
- Memoria relevante: `bankListDelete success-toast test` flakea ~40% bajo carga (portal dropdown vs `findBy` 1s) — no perseguir ese flake si aparece; no introducir dependencias de timing nuevas.

### Project Structure Notes

- Sin nuevos directorios de módulo. Posible **único** fichero nuevo: `pwa/src/components/ui/popover.tsx` (wrapper de primitiva, si se toma la vía Popover). Todo lo demás son ediciones in-place en el módulo `backoffice/banks`.
- Sin cambios en `api/`. Sin migraciones. Sin nuevas dependencias npm.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 2.5] — AC originales (PR5, delete-guard + recovery).
- [Source: pwa/src/app/backoffice/banks/page.tsx] — `deleteRecoveryAction` / `MutationError` (patrón a extender para `IN_USE`).
- [Source: pwa/src/app/backoffice/banks/[id]/page.tsx#DeleteErrorPanel] — acción de recovery en detalle.
- [Source: pwa/src/app/backoffice/banks/_components/DeleteBankButton.tsx] — disparador de borrado (guard pre-DELETE).
- [Source: pwa/src/context/backoffice/bank/domain/BankProblemType.ts] — `IN_USE` / `NOT_FOUND`.
- [Source: pwa/src/components/erpify/MutationError.tsx] — `action` prop (ya soporta recovery).
- [Source: pwa/src/app/backoffice/banks/_lib/bankRoutes.ts#accounts] — builder de ruta a cuentas.
- [Source: api/src/Backoffice/Bank/Domain/Exception/BankInUseException.php] — contrato `409 bank-in-use` (no se modifica).
- [Source: pwa/tests/e2e/backoffice/banks-delete-preconditions.spec.ts] — caso 409 a actualizar (hoy "no action").

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context) — BMAD dev-story workflow.

### Debug Log References

- `make pwa.test.unit` (full): **619/619** pass (110 files), incl. the new `bankDeleteGuard.test.tsx` and the updated banks delete specs.
- `make pwa.quality`: **EXIT 0** (ESLint + Prettier + `tsc --noEmit`).
- e2e (`banks-delete-preconditions.spec.ts`): authored (guard + 409-recovery cases). Not run locally — Playwright browsers are blocked on this distro (memory `pwa-e2e-local-ownership-blocker`); runs in CI.

### Completion Notes List

- **AC#1 (optimistic guard).** `DeleteBankButton` gained a required `accountCount`. With `> 0` the delete trigger opens a **neutral guard** ("«Bank» can't be deleted — N associated account(s)…" + a "View accounts" link to `/backoffice/banks/{id}/accounts`) and `DeleteBank.run` is unreachable — no `DELETE` is issued. With `0`, the existing destructive confirmation is unchanged. Propagated `accountCount` through `BankRowActions` → table/cards/stacked surfaces and the detail header.
- **Primitive decision (deviation from the spec's stated preference).** The spec leaned to a `@base-ui/react` Popover (the export *does* exist in v1.5.0). I implemented the guard with the **existing `Dialog` primitive** instead: the delete trigger spans a controlled (row `⋯` menu) and an uncontrolled (detail) mode, and a Popover would need its anchor threaded across that controlled handoff (fragile focus management). Reusing `Dialog` keeps the whole `accountCount` branch in one component, works identically in both modes, adds no new `ui/` file, and is visually consistent with the destructive confirm it replaces. The "neutral" requirement (non-destructive tone, no destructive button, View-accounts-forward) is honored.
- **AC#2 (raced 409 recovery).** The persistent `<MutationError>` `action` slot now renders a "View accounts" link for `type === bank-in-use` on both the list (`banks-list__delete-error-view-accounts`) and detail (`banks-detail__delete-error-view-accounts`) surfaces; `bank-not-found` keeps its Refresh action. This **changes the documented contract** `bank-in-use → none` → `bank-in-use → View accounts` — updated in `pwa/CLAUDE.md`.
- **AC#3 (backend authoritative).** No `api/` changes; the `409 BankInUseException` path is consumed, never replaced. The guard is purely an optimistic UX short-circuit.
- **e2e fixture reconciliation (pre-existing gap, fixed here).** PR #248 made the PWA list/single response guards require `accountCount: number`, but the e2e `BankFixture`/mock responses never carried it — so the banks-list e2e specs (delete-preconditions, banks, form-errors) could not satisfy the list-load guard on this stack. Added `accountCount` to `BankFixture` (+ all samples + `makeBanks`, default `0`) so the mock mirrors the real read contract; this unblocks the whole banks e2e suite and enables the AC#1 guard scenario (a bank with `accountCount: 3`).
- **Security (PWA review).** Every "View accounts" navigation goes through `safeHref(bankRoutes.accounts(id))` (`bankRoutes.accounts` already `encodeURIComponent`s the id). No `dangerouslySetInnerHTML`/`innerHTML`/`eval`; `aria-label`s static; only `accountCount` (an integer) crosses the new boundary — no IBAN/PII. No new dependencies; `next.config.ts` untouched.
- **Test-IDs.** New literals (`banks-detail__delete-guard-message`, `banks-detail__delete-guard-view-accounts`, `banks-list__delete-error-view-accounts`, `banks-detail__delete-error-view-accounts`) are each unique across `src/` (uniqueness guard green).

### File List

**Production (`pwa/src/`)**

- `app/backoffice/banks/_components/DeleteBankButton.tsx` — `accountCount` prop; neutral in-use guard branch.
- `app/backoffice/banks/_components/BankRowActions.tsx` — `accountCount` prop → `DeleteBankButton`.
- `app/backoffice/banks/_components/BanksTable.tsx` — pass `row.accountCount`.
- `app/backoffice/banks/_components/BanksCards.tsx` — pass `bank.accountCount`.
- `app/backoffice/banks/_components/BanksStackedList.tsx` — pass `bank.accountCount`.
- `app/backoffice/banks/page.tsx` — `bank-in-use` → "View accounts" recovery in the list mutation-error.
- `app/backoffice/banks/[id]/page.tsx` — `bank-in-use` → "View accounts" recovery in `DeleteErrorPanel`; pass `bank.accountCount` to the header `DeleteBankButton`.

**Tests (`pwa/tests/`)**

- `app/backoffice/banks/bankDeleteGuard.test.tsx` — NEW: AC#1 guard (intercept, no DELETE, View accounts, singular/plural, 0-account passthrough).
- `app/backoffice/banks/bankRowActions.test.tsx` — `accountCount` prop + controlled-path guard case.
- `app/backoffice/banks/deleteBankButtonSpinner.test.tsx` — `accountCount={0}` prop.
- `app/backoffice/banks/bankListDelete.test.tsx` — 409 now asserts the View-accounts recovery.
- `app/backoffice/banks/bankDetailDelete.test.tsx` — 409 now asserts the View-accounts recovery.
- `tests/e2e/backoffice/banks-delete-preconditions.spec.ts` — NEW guard scenario + updated 409 recovery assertion.
- `tests/e2e/fixtures/banks-api.ts` — `accountCount` on `BankFixture`/samples/`makeBanks`.

**Docs**

- `pwa/CLAUDE.md` — mutation-error `action`-slot contract: `bank-in-use` → "View accounts".

## Change Log

| Date       | Change                                                                                  |
|------------|-----------------------------------------------------------------------------------------|
| 2026-06-13 | Story 2.5 implemented: optimistic delete-guard (`accountCount > 0`) + `bank-in-use` 409 "View accounts" recovery (list + detail). e2e fixtures reconciled to the `accountCount` read contract. Gates green (unit 619/619, quality EXIT 0). Status → review. |

## Review Findings

_Code review 2026-06-13 (adversarial: Blind Hunter + Edge Case Hunter + Acceptance Auditor). All 3 ACs PASS. 1 decision-needed, 1 patch, 11 dismissed as noise/false-positive/by-design._

- [x] [Review][Decision→Patch] Bulk-delete `bank-in-use` recovery "View accounts" only addressed the first failed bank — In `page.tsx` `runBulkDelete`, a bulk delete with ≥2 `bank-in-use` rejections sets `deleteError.bankId = rejections[0].id` (`scope: "bulk"`), so the IN_USE "View accounts" link routed only to the first failed bank's accounts; the others had no affordance even though the copy says "N of M could not be deleted". **Resolved (Sally, UX — option a refined):** `deleteRecoveryAction` now branches on `scope` — `single` keeps the per-bank "View accounts" deep-link; `bulk` renders a non-navigating orienting hint ("Open each flagged bank below to view its accounts."), since the in-use rows are restored to the list and each carries its own optimistic guard (the precise per-bank recovery). No client-synthesized ProblemDetails (contract-respecting). New test `banksBulkActions.test.tsx` "a bulk 409 bank-in-use orients to the per-row guard instead of deep-linking one bank". Gate: pwa.quality EXIT 0; affected unit suites 18/18; testid-uniqueness green. [pwa/src/app/backoffice/banks/page.tsx:637]

- [x] [Review][Patch] Stale `BankProblemType.IN_USE` doc comment now contradicts the behavior this story adds — The constant was annotated `recovery lives outside the list`, but AC#2 makes the `bank-in-use` recovery an in-surface "View accounts" action. **Applied:** comment updated to describe the in-surface recovery (single deep-links; bulk orients to the per-row guard). [pwa/src/context/backoffice/bank/domain/BankProblemType.ts:9]
