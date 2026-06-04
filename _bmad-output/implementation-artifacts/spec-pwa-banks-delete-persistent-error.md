---
title: 'PWA: error de mutación persistente en borrado de bancos (single + masivo)'
type: 'feature'
created: '2026-06-04'
status: 'in-progress'
baseline_commit: '693e63a'
worktree: 'nuevo desde main (petición explícita) — make worktree.create BRANCH=feat/pwa-banks-delete-persistent-error'
context:
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-06-03/EXPERIENCE.md'
  - '{project-root}/_bmad-output/implementation-artifacts/deferred-work.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Al fallar el borrado de un banco (409 `bank-in-use` de PR #144, 404 obsoleto), el error solo vive en un toast transitorio (lista/tarjetas) o embebido en el confirm (detalle): no se puede leer con calma, copiar ni capturar. El contrato UX (EXPERIENCE.md § «Errores de mutación — superficie persistente», 2026-06-04) revoca el patrón D-modal.

**Approach:** Componente `MutationError` (compone el `ProblemDisplay` existente: dismiss ×, botonera de copia, foco) anclado al origen — sobre tabla/grid en lista, bajo el H1 en detalle. El confirm **se cierra solo al fallar**; recuperación tipada por `problem.type` (404 → Refresh; `bank-in-use` → sin acción); toast de error pasa a señal transitoria. El masivo gana pre-check de existencia y rollback parcial que restaura filas **y selección**.

## Boundaries & Constraints

**Always:**
- Las spines (`EXPERIENCE.md` + `DESIGN.md`) ganan ante cualquier conflicto; el bloque Spec B de `deferred-work.md` se lee con su triage SOBREVIVE/REVOCADO/REUBICADO.
- Problem **verbatim** del wire — prohibido sintetizar problems client-side. Sin clamp en la superficie persistente.
- `MutationError` **compone** `ProblemDisplay` (tonos, campos, `debug` env-aware, `role="alert"` ya resueltos) — no lo forkea ni duplica.
- Copia JSON = payload recibido; en build prod omite `debug` (paridad exacta con lo que el render muestra).
- Ciclo de vida: dismiss × · un intento nuevo de la misma mutación lo sustituye · el éxito lo limpia · sobrevive a refetch/Mercure · máx. 1 error por origen.
- `data-testid` existentes intactos; BEM + `cn()`; `make pwa.quality` limpio al cierre.

**Ask First:**
- Si propagar `onError` exigiera cambiar el API de componentes compartidos fuera de `banks/_components` + `components/erpify`.
- Si algún e2e existente (`banks-real-api-flows`, `banks-realtime`) exigiera cambio de contrato (no solo de selector).

**Never:**
- Tocar `api/` (el 409 ya existe). Formularios crear/editar (goal C diferido). UI de cuentas asociadas, Shift-range, `/`, "List updated", peek. `maxLength` en inputs. Refactors oportunistas.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Single 409 (lista/tarjetas/apilada) | confirm → API 409 `bank-in-use` | Dialog se cierra; `MutationError` sobre tabla/grid con problem verbatim (`bankId`, `accountCount`), **sin acción**; foco al error; toast transitorio; fila intacta | N/A |
| Single 404 (lista) | confirm → 404 `bank-not-found` | Ídem con acción **"Refresh list"**; al refrescar: error limpio, fila obsoleta fuera, foco a fila vecina (seam `pendingFocusIdRef`) + anuncio live region | N/A |
| Single desde detalle | confirm → 409/404 | Dialog se cierra; `MutationError` bajo el H1; 404 → acción "Refresh" → `loadBank()` → EmptyState not-found existente; 409 → sin acción | N/A |
| Masivo: pre-check | confirm N ids; sonda `FindBank` allSettled detecta ≥1×404 | **Nada se borra**; dialog se cierra; `MutationError` con el problem de la sonda + "Refresh list"; tras refrescar: selección recalculada, foco al Delete de la barra (o contenedor si queda a 0) + anuncio | Sonda falla con ≠404 → fail-open: se continúa al intento |
| Masivo: fallo parcial post-intento | allSettled con fallos | Rechazo 404 NO resucita la fila; los demás restauran fila **y selección** (hoy `page.tsx` la vacía y no la repone); `MutationError` con el problem del primer fallo + toast transitorio con recuentos | N/A |
| Copia | click en botonera | Mensaje (title+detail) · `type · status` · correlation-id (chip existente) · JSON íntegro recibido | Clipboard falla → estado error de `CopyButton` |
| Éxito tras error | retry OK / refresh resuelve | Error del origen se limpia; toasts de éxito sin cambios | N/A |

</frozen-after-approval>

## Code Map

- `pwa/src/components/erpify/ProblemDisplay.tsx` — base a componer: variants cva (`:64-76`), slot `action` ya existente, `isProductionEnv()` (`:111`, reutilizar/extraer), `role="alert"` (`:207-208`).
- `pwa/src/components/erpify/CopyButton.tsx` + `CorrelationIdChip.tsx` — afford. de copia listas; `pwa/src/components/erpify/index.ts` — barrel.
- `pwa/src/app/backoffice/banks/_components/DeleteBankButton.tsx` — hoy `setProblem` → ProblemDisplay en el dialog (`:56,89,124`); pasa a cerrar dialog + `onError(problem)`.
- `pwa/src/app/backoffice/banks/_components/BankRowActions.tsx` — threading de `onError` (dialog controlado, `:116-122`).
- `pwa/src/app/backoffice/banks/page.tsx` — estado `mutationProblem` + render sobre tabla/grid/apilada; `handleBulkDelete` (`:273-301`) gana pre-check pesimista y restauración de selección (`:279`); seam de foco `pendingFocusIdRef` (`:188-217`); inyectar `FindBank` para sondas.
- `pwa/src/app/backoffice/banks/_components/BanksBulkBar.tsx` — confirm (`:76-141`): cierra al fallar; foco al Delete tras refresh.
- `pwa/src/app/backoffice/banks/[id]/page.tsx` — `MutationError` bajo header (`:199-246`); acción Refresh → `loadBank()` (`:75-98`); NOT_FOUND existente (`:169-189`).
- `pwa/src/context/backoffice/bank/application/FindBank.ts` — sonda del pre-check (token `BackOfficeFindBank`).
- Tests a extender: `pwa/tests/.../bankListDelete.test.tsx`, `bankDetailDelete.test.tsx`, `banksBulkActions.test.tsx`, `deleteBankButtonSpinner.test.tsx`, `bankRowActions.test.tsx` (mocks en `_mocks.ts`); `ProblemDisplay.test.tsx` como referencia de patrón env.
- `pwa/src/app/dev-tools/error-gallery/page.tsx` — añadir caso `MutationError` (galería ya existente).

## Tasks & Acceptance

**Execution:**
- [ ] Worktree: `make worktree.create BRANCH=feat/pwa-banks-delete-persistent-error START=true` — todo lo siguiente dentro del worktree.
- [ ] `pwa/src/components/erpify/MutationError.tsx` — NUEVO: compone `ProblemDisplay` + dismiss × + botonera de copia (mensaje · type+status · JSON; correlation-id ya en chip) + `tabIndex={-1}` con foco al montar + slot de acción de recuperación; BEM `.mutation-error*`.
- [ ] `pwa/tests/components/erpify/MutationError.test.tsx` — NUEVO: matriz de copia (incl. paridad debug/prod), dismiss, foco, acción tipada, casos de la I/O Matrix a nivel componente.
- [ ] `DeleteBankButton.tsx` — quitar ProblemDisplay del dialog; al fallar: cerrar + `onError(problem)`; toast transitorio "Couldn't delete bank — see error details".
- [ ] `BankRowActions.tsx` — propagar `onError`.
- [ ] `banks/page.tsx` — estado de error por origen + `MutationError` sobre la vista activa; recuperación 404→Refresh list (refetch existente); bulk: pre-check `FindBank` (fail-open ≠404), nada-se-borra con algún 404, rollback parcial restaurando fila y selección, focos según matriz.
- [ ] `BanksBulkBar.tsx` — cierre al fallar; foco al Delete tras refresh con selección > 0.
- [ ] `banks/[id]/page.tsx` — `MutationError` bajo H1; Refresh → `loadBank()`.
- [ ] Tests listados en Code Map — actualizar al contrato nuevo (el dialog ya nunca muestra problems).
- [ ] `pwa/e2e/banks-delete-preconditions.spec.ts` — NUEVO (API mockeada): single 404→Refresh→foco vecina; single 409 persiste/copiable/sin acción; bulk pre-check 404→nada borrado→Refresh→recuento; bulk parcial→filas+selección restauradas.
- [ ] `error-gallery/page.tsx` — caso `MutationError`.

**Acceptance Criteria:**
- Given cualquier fallo de borrado, when el dialog estaba abierto, then se cierra solo y ningún problem se renderiza jamás dentro de un dialog.
- Given el error persistente visible, when llega un refetch o update de Mercure, then el error permanece; when el usuario navega a otra página, then no le sigue.
- Given el error montado, when aparece, then recibe foco (`tabIndex={-1}`) y se anuncia una sola vez (el `role="alert"` interno de ProblemDisplay; sin segunda live region duplicada).
- Given build de producción con un problem que trae `debug`, when se copia el JSON, then `debug` no aparece (paridad con el render).
- Given un segundo intento de borrado en el mismo origen, when falla, then el error anterior es sustituido (nunca se apilan).

## Spec Change Log

## Design Notes

`MutationError` es un wrapper, no un fork: `ProblemDisplay` ya resuelve tonos 4xx/5xx, violations, `debug` env-aware y `role="alert"`; el wrapper añade persistencia (dismiss/sustitución), botonera de copia y contrato de foco. La acción tipada entra por el slot `action` ya existente. El mapeo type→acción vive en el origen (página), no en el componente: `bank-not-found` → botón Refresh; resto → sin acción. JSON de copia: `JSON.stringify(problem)` del objeto recibido, con `debug` eliminado si `isProductionEnv()`.

## Verification

**Commands:**
- `make pwa.test.unit` — expected: verde, incl. `MutationError.test.tsx` y suites actualizadas.
- `make pwa.test.e2e c='banks-delete-preconditions'` — expected: verde (recordar `PLAYWRIGHT_HOST_PLATFORM_OVERRIDE=ubuntu24.04-x64` en este host; `banks-realtime` tiene timeout local pre-existente — no es regresión).
- `make pwa.quality` — expected: sin hallazgos nuevos.

**Manual checks (if no CLI):**
- En dev: borrar banco con cuentas desde lista, tarjetas y detalle → el 409 permanece legible, copiable y capturable; × lo cierra.
