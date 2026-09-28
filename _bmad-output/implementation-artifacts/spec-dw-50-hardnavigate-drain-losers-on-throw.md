---
title: 'DW-50 — hardNavigate: un callback propio que lanza no deja a los perdedores sin informe'
type: 'bugfix'
created: '2026-09-28'
status: 'done'
baseline_revision: 'a527e49d5f450353a39b660a3c395edf67767275'
review_loop_iteration: 0
followup_review_recommended: false
context: []
warnings: []
deferred: []
---

<intent-contract>

## Intent

**Problem:** En `pwa/src/context/shared/navigation/infrastructure/hardNavigate.ts`, `fire()` llama a `onFailure("not-committed")` del dueño del claim y sólo después drena `ownClaim.superseded`; si ese callback lanza, cada perdedor encolado se queda esperando un informe que nunca llega (latch retenido durante toda la vida del documento). La misma forma se repite dentro del bucle: un perdedor que lanza deja sin informe a los perdedores que vienen detrás.

**Approach:** Entregar cada informe adeudado de forma independiente (el propio primero, luego los perdedores en orden), cada uno aislado del fallo de los demás, y relanzar lo que haya fallado cuando todos hayan sido informados: un único error se relanza tal cual (misma identidad); varios, como `AggregateError` con todos ellos. Nada se traga.

## Boundaries & Constraints

**Always:** el orden de informe se mantiene (propio → perdedores en orden de llegada); cada callback recibe exactamente un informe; `disarm()` sigue ocurriendo antes de cualquier callback; la cola se vacía (`splice(0)`) antes de invocar, para que una re-entrada no vea perdedores ya servidos; el error escapa por el mismo camino que hoy (timer → error no capturado; `abandon()` → sale de `hardNavigate()` del llamante que preempta).

**Never:** tragar errores (ni `catch {}` vacío ni `console.error` en su lugar); inventar un contrato de errores para callbacks más allá de «todos son informados, lo que falló se propaga»; tocar el orden de `held?.abandon()` al final de `hardNavigate()`; cambiar el API público.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Nada lanza | claim + 2 perdedores, vence el presupuesto | propio `not-committed`, cada perdedor `superseded` una vez | sin error |
| Propio lanza (timer) | claim cuyo `onFailure` lanza E + perdedores | todos los perdedores reciben `superseded` | E se propaga desde el callback del timer, idéntico (no envuelto) |
| Propio lanza (preempción) | claim oculto con perdedor, cuyo callback lanza E; llega un tercero que preempta | el nuevo claim queda armado y el perdedor migra a él; E sale de `hardNavigate()` | E relanzado tal cual |
| Perdedor lanza | 1º perdedor lanza E, 2º no | el 2º recibe `superseded` | E se propaga idéntico |
| Varios lanzan | propio lanza E1, perdedor lanza E2 | todos informados | `AggregateError` con `errors` = [E1, E2] en orden |

</intent-contract>

## Code Map

- `pwa/src/context/shared/navigation/infrastructure/hardNavigate.ts:150-155` -- `fire()`: el punto del defecto; `disarm()` → propio → drenaje de `superseded`.
- `hardNavigate.ts:160-166` -- `ownClaim.abandon = () => fire()`: la preempción reutiliza `fire()`, así que el arreglo en `fire()` cubre ambos caminos.
- `hardNavigate.ts:214-224` -- comentario/orden de `held?.abandon()`: ya garantiza que el claim nuevo está armado antes del código ajeno; el comentario menciona que un throw «propaga»; sigue siendo cierto.
- `hardNavigate.ts:52-55` -- docblocks de `superseded`/`abandon` («report to its own caller, then to its losers»): mantener coherentes.
- `pwa/tests/context/shared/navigation/infrastructure/hardNavigate.test.ts` -- suite Vitest con fake timers y módulo fresco por test; patrón de throw en `it("arms the new claim before running the preempted caller's callback…")` (l.365); patrón de perdedores en l.241 y l.332. Nuevos casos van en `describe("single-flight exclusivity")`.
- `pwa/tsconfig.json` -- `lib: esnext` → `AggregateError` disponible sin polyfill.

## Tasks & Acceptance

**Execution:**
- `pwa/src/context/shared/navigation/infrastructure/hardNavigate.ts` -- en `fire()`, tras `disarm()`, construir la lista de informes (propio + `superseded.splice(0)`), invocar cada uno en su propio `try/catch` acumulando errores, y al final relanzar (1 → tal cual; >1 → `AggregateError`). Comentario del porqué (el informe se debe a cada uno; el error no se interpreta, se devuelve). Ajustar el docblock de `abandon` si procede. -- cierra DW-50 y su gemelo en el bucle.
- `pwa/tests/context/shared/navigation/infrastructure/hardNavigate.test.ts` -- añadir casos: propio lanza en el timer (perdedores informados, error idéntico propagado — capturarlo desde `vi.advanceTimersByTime`); propio lanza al ser preemptado (perdedor migrado y luego informado); perdedor lanza (siguiente informado); varios lanzan (`AggregateError` con ambos). -- prueba la garantía en la superficie pública.

**Acceptance Criteria:**
- Given un claim en vuelo con perdedores encolados y un `onFailure` propio que lanza, when vence el presupuesto, then cada perdedor recibe `superseded` exactamente una vez y el error original escapa sin envolver.
- Given el comportamiento sin throws, when se ejecuta la suite existente, then todos los tests previos siguen en verde.

## Spec Change Log

## Review Triage Log

### 2026-09-28 — Review pass
- verdicts: 13 findings — high 0, medium 0, low 5, false 8, maybe-false 0
- findings:
  - `[low]` `[patch]` (Blind) El docblock público de `hardNavigate` no dice que la llamada puede lanzar — añadido un párrafo: lo que lanza un callback se relanza, nunca se traga, tras entregar todos los informes (desde la llamada al preemptar, desde el timer si no).
  - `[false]` `[reject]` (Blind) Alternativa `reportError` no valorada — el camino de preempción ya relanzaba antes de este cambio (fijado por «arms the new claim before…», `toThrow()`); cambiarlo a `reportError` rompería un contrato existente, y relanzar es la lectura directa de «sin tragar el error».
  - `[low]` `[patch]` (Blind) El docblock de `abandon` decía «luego a sus perdedores — todos» cuando en preempción ya migraron — reescrito: sólo se informa al propio llamante y su throw se relanza.
  - `[low]` `[patch]` (Blind) Ningún test prueba que el sumidero queda libre tras un throw — añadido en «still tells every loser…»: nueva llamada, `replace` invocado y su `onFailure` sin llamar.
  - `[false]` `[reject]` (Blind) Re-entrada en el camino del throw sin test — la re-entrada no puede alcanzar `ownClaim.superseded`: `disarm()` limpia `claim` antes de cualquier callback (ver fila VG); no hay estado que probar.
  - `[low]` `[patch]` (Blind) Aserciones incompletas en los casos nuevos — añadidos `toHaveBeenCalledTimes(1)` (primero, perdedor que lanza como `vi.fn`, takeover) y eliminado el `toEqual` redundante.
  - `[false]` `[reject]` (Blind) Números de línea del Code Map rancios — su arreglo edita la spec de este build; rechazado por regla.
  - `[false]` `[reject]` (Blind) La entrada DW-50 del ledger sigue `open` — la intención prohíbe editar el ledger; el orquestador registra la resolución.
  - `[false]` `[reject]` (Blind) El comentario sobre `held?.abandon()` necesita actualización — sigue siendo exacto: el throw propaga desde ahí y el claim queda armado.
  - `[low]` `[patch]` (Verification gap) El comentario de `fire()` justificaba el drenaje previo con una re-entrada imposible — verificado (`disarm()` pone `claim = undefined`); comentario reducido a la razón real.
  - `[false]` `[reject]` (Intent) `AggregateError` como contrato inventado más allá de la intención — «sin tragar el error» con varios fallos exige conservarlos todos; relanzar sólo el primero tragaría los demás. Para el escenario literal (sólo el propio lanza) el error sale idéntico.
  - `[false]` `[reject]` (Intent) El test de preempción no ejercita el defecto corregido — cierto pero no es defecto: fija la propiedad adyacente (error sin envolver desde `hardNavigate()`, perdedor migrado e informado); los tests de timer ejercitan el drenaje.
  - `[false]` `[reject]` (Intent) Timer sólo bajo fake timers — los fake timers invocan el mismo callback de forma síncrona; el throw que capturan es el que un timer real entregaría al manejador global.

## Design Notes

Por qué no un simple `try { own } finally { drain }`: si dentro del `finally` lanza un perdedor, su error reemplaza al original (se traga el primero) y deja sin informe a los perdedores siguientes — el mismo defecto una posición más abajo. Aislar cada informe y relanzar al final es lo mínimo que cumple «todos informados» y «nada tragado» a la vez. Un único error conserva su identidad para no cambiar lo que hoy observa quien lo reciba.

## Verification

**Commands:**
- `make pwa.test.unit` (o `npx vitest run tests/context/shared/navigation/infrastructure/hardNavigate.test.ts` dentro del contenedor pwa) -- expected: verde, nuevos casos incluidos.
- `make pwa.quality` -- expected: exit 0.

## Auto Run Result

Status: done

**Resumen:** `fire()` en `hardNavigate.ts` entrega ahora cada informe adeudado (propio `not-committed`, luego cada perdedor `superseded`) de forma aislada y relanza al final lo que haya fallado: un error tal cual, varios como `AggregateError`. Un callback que lanza ya no deja perdedores sin informe (DW-50), tampoco un perdedor que lanza a los que vienen detrás.

**Ficheros:**
- `pwa/src/context/shared/navigation/infrastructure/hardNavigate.ts` — `fire()` aislado por informe + relanzado; docblocks de `abandon` y de `hardNavigate` precisados.
- `pwa/tests/context/shared/navigation/infrastructure/hardNavigate.test.ts` — 4 casos nuevos (propio lanza en timer, propio lanza en preempción, perdedor lanza, varios lanzan) + sumidero libre tras el throw.

**Revisión:** 13 hallazgos (4 capas; Edge Case Hunter: 0). Parches aplicados: 5 (todos `low`). Diferidos: 0. Rechazados: 8 `false`, cada uno con su refutación en el Review Triage Log.

**Follow-up review:** `false` — 0 `high`, 0 `medium` parcheados (5 `low`).

**Verificación:** `make pwa.test.unit c='tests/context/shared/navigation/infrastructure/hardNavigate.test.ts'` → 30/30, exit 0; `make pwa.test.unit` completo (implementador) → 1910 tests, exit 0; `make pwa.quality` → exit 0 tras los parches. Falsificación: 3 de los 4 casos nuevos fallan con el `hardNavigate.ts` de la baseline.

**Riesgo residual:** quien capture el error de `fire()` puede recibir un `AggregateError` cuando lanzan dos o más callbacks; ningún llamante real lanza hoy.
