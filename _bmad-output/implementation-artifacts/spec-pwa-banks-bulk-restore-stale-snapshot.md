---
title: 'Bulk delete: validar la restauración del rollback contra un re-probe'
type: 'bugfix'
created: '2026-06-06'
status: 'done'
context: []
baseline_commit: '7b3413571e7c19ef56a6d37688990bb5f9ed204a'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** En `runBulkDelete` (lista de bancos), un DELETE que falla con ≠404 restaura la fila desde un `snapshot` capturado en el closure del render. Si otro cliente borró esa fila (Mercure `onDeleted`) durante la ventana probe/delete, la restauración resucita una fila inexistente en el servidor hasta el siguiente reconcile.

**Approach:** Validar la restauración contra el servidor re-sondeando (`FindBank`) cada id candidato: 404 → no se restaura ni re-selecciona; sondeo exitoso → se restaura desde snapshot; fallo de sondeo ≠404 → fail-open (se restaura), espejo del pre-check existente.

## Boundaries & Constraints

**Always:**
- Superficie de error persistente y toast ANTES del round-trip del re-probe (el re-probe solo retrasa la reaparición de filas, nunca el feedback).
- El re-probe es solo compuerta de existencia: la fila restaurada sale del `snapshot`, nunca del body del probe.
- `mountedRef.current` tras cada `await` antes de cualquier `setState`; restauración con setState funcional y guarda `present`.
- `setSelectedIds` solo re-añade ids cuya restauración fue confirmada.

**Ask First:**
- Cambiar el recuento de errores («N of M could not be deleted») — las filas confirmadas inexistentes siguen contando como fallo, igual que hoy los rechazos 404.
- Tocar el fixture e2e `banks-api.ts`.

**Never:**
- No usar el payload del re-probe como datos frescos: el fixture e2e GET/{id} no es fiel al id (devuelve el banco por defecto) — rompería el e2e «bulk partial failure».
- No añadir tracking de ids borrados por Mercure (refs acoplados al handler realtime) — el re-probe cubre también borrados con el stream caído.
- No tocar el borrado individual ni el pre-check existente.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Todos los DELETE OK | sin rechazos | sin cambios (camino existente) | N/A |
| Fallo ≠404, fila aún existe | re-probe resuelve | fila + selección restauradas (comportamiento actual preservado) | superficie de error + toast |
| Fallo ≠404, fila borrada remotamente | re-probe rechaza 404 | fila NO restaurada, selección NO re-añadida | superficie de error + toast siguen reportando el fallo |
| Fallo ≠404, re-probe falla ≠404 | red/500 en el probe | fail-open: fila + selección restauradas desde snapshot | superficie de error + toast |
| Rechazo DELETE 404 | banco ya borrado | no se restaura y NO se emite re-probe para ese id (camino existente) | superficie de error |
| Unmount durante el re-probe | `mountedRef` false | ningún `setState` | N/A |

</frozen-after-approval>

## Code Map

- `pwa/src/app/backoffice/banks/page.tsx` — `runBulkDelete` (l.389-451): bloque de restauración l.428-439 (el bug), comentario contractual l.368-374 (actualizar). `findBank` ya en scope (l.390); `isNotFoundError` (l.87) sirve para el veredicto del re-probe.
- `pwa/tests/app/backoffice/banks/banksBulkActions.test.tsx` — suite unit del bulk; `findRun` mockea `FindBank` (pre-check y re-probe comparten mock → distinguir por nº de llamada por id).
- `pwa/tests/e2e/backoffice/banks-delete-preconditions.spec.ts` — «bulk partial failure» debe seguir verde: el GET extra del re-probe lo responde el happy-path del fixture.
- `pwa/tests/e2e/fixtures/banks-api.ts` — GET/{id} devuelve `{ data: bank }` (no fiel al id) — motivo del gate-only restore. No se toca.
- `_bmad-output/implementation-artifacts/deferred-work.md` — item «Resurrección por snapshot obsoleto…» a cerrar.

## Tasks & Acceptance

**Execution:**
- [x] `pwa/src/app/backoffice/banks/page.tsx` — en `runBulkDelete`: mover `setDeleteError` + `toastNotifier.error` antes de la restauración; sustituir el bloque de restauración por: ids restaurables → `Promise.allSettled(restorableIds.map((id) => findBank.run(id)))` → confirmados = fulfilled ∪ rechazos ≠404 → restaurar confirmados desde `snapshot` (guarda `present`) y re-añadir solo confirmados a la selección. Actualizar el comentario del contrato (l.368-374) mencionando la restauración validada. — corrige la resurrección sin penalizar el feedback.
- [x] `pwa/tests/app/backoffice/banks/banksBulkActions.test.tsx` — añadir 2 tests: (1) DELETE ≠404 + re-probe 404 → la fila no reaparece, sin bulk bar, superficie de error presente; (2) DELETE ≠404 + re-probe con error ≠404 → fail-open: fila + selección restauradas. Mock `findRun` con contador por id (1ª llamada = pre-check OK, 2ª = veredicto del re-probe). — cubre la matriz de edge cases.
- [x] `_bmad-output/implementation-artifacts/deferred-work.md` — eliminar el item resuelto (dejar intactos los demás bloques). — cierre del trabajo diferido.

**Acceptance Criteria:**
- Given una fila seleccionada borrada por otro cliente en pleno vuelo cuyo DELETE falla con ≠404, when el bulk delete liquida, then la fila no se re-añade, el recuento de selección la excluye y la superficie de error persistente sigue reportando el fallo.
- Given un fallo ≠404 con la fila aún existente en el servidor, when el re-probe resuelve, then fila y selección se restauran — los tests existentes de la suite siguen verdes sin modificarse.
- Given la suite e2e `banks-delete-preconditions.spec.ts`, when corre en CI, then «bulk partial failure» sigue verde sin cambios en el spec ni en el fixture.

## Spec Change Log

## Design Notes

Orden: error surface + toast → re-probe → restauración. En red degradada (justo cuando los DELETE fallan), anteponer el re-probe retrasaría el feedback de error otro round-trip. Restauración tardía es benigna; error tardío no.

Gate-only restore: usar el banco fresco del probe acoplaría el fix a un fixture e2e no fiel al id, y la frescura de datos ya la cubre Mercure/reconcile.

## Verification

**Commands:**
- `make pwa.test.unit c='tests/app/backoffice/banks/banksBulkActions.test.tsx'` — expected: suite verde (existentes + 2 nuevos).
- `make pwa.test.unit` — expected: suite completa verde (incluye guard tests de testid/env).
- `make pwa.quality` — expected: ESLint + Prettier sin hallazgos.

**Manual checks (if no CLI):**
- E2E local no disponible (sin browsers Playwright para este host); `banks-delete-preconditions.spec.ts` se valida en CI del PR.

## Suggested Review Order

**Compuerta de re-probe (el fix)**

- Entrada: el contrato completo del bulk delete validado, en un solo comentario.
  [`page.tsx:368`](../../pwa/src/app/backoffice/banks/page.tsx#L368)

- Re-probe en paralelo de los candidatos; guard de `mountedRef` tras el await.
  [`page.tsx:456`](../../pwa/src/app/backoffice/banks/page.tsx#L456)

- Restauración desde `snapshot` (gate-only, nunca el body del probe) + re-selección solo de confirmados.
  [`page.tsx:465`](../../pwa/src/app/backoffice/banks/page.tsx#L465)

**Orden del feedback**

- Error persistente + toast ANTES del round-trip: red degradada no retrasa el aviso.
  [`page.tsx:433`](../../pwa/src/app/backoffice/banks/page.tsx#L433)

- Los rechazos 404 ni se re-sondean: filtrado previo a la compuerta.
  [`page.tsx:447`](../../pwa/src/app/backoffice/banks/page.tsx#L447)

**Periféricos**

- Test del fix: re-probe 404 → la fila no resucita ni se re-selecciona.
  [`banksBulkActions.test.tsx:145`](../../pwa/tests/app/backoffice/banks/banksBulkActions.test.tsx#L145)

- Test fail-open: re-probe con error ≠404 restaura desde snapshot.
  [`banksBulkActions.test.tsx:182`](../../pwa/tests/app/backoffice/banks/banksBulkActions.test.tsx#L182)

- Cierre del item diferido + nota de la ventana residual (microtarea, autocurable).
  [`deferred-work.md:149`](deferred-work.md#L149)
