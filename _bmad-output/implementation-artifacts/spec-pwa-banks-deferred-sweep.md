---
title: 'Barrido banks: anti-resurrección, restos UX, dedup de specs y doc same-origin'
type: 'feature'
created: '2026-06-06'
status: 'done'
context: []
baseline_commit: '70751ad9c74f20422bce77a3eaa755ac3cec82a0'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `deferred-work.md` acumula items de banks ya maduros para cerrarse: dos ventanas de resurrección de filas (restore del bulk delete vs. `onDeleted` de Mercure, y redelivery tardía de `created` tras `delete`), tres restos del contrato UX (e2e del tooltip de tarjeta, RecordSheet peek `o`, selección por rango Shift+↑/↓), el barrido de fixtures/mocks duplicados en los specs de banks, y la viñeta SameSite sin documentar.

**Approach:** Una sola PR que: (1) cierra ambas resurrecciones con un set de tombstones de ids borrados (override consciente del «Never: refs acoplados al handler realtime» del spec previo — autorizado por Sergio 2026-06-06); (2) cablea RecordSheet peek y Shift+rango en las superficies con roving focus; (3) cubre tooltip/no-navegación del shortName en e2e; (4) migra los specs a `_fixtures.ts`/`_mocks.ts`; (5) documenta que el realtime exige PWA y API same-origin (cookie `SameSite=Strict` por diseño); (6) recorta las secciones resueltas de `deferred-work.md`.

## Boundaries & Constraints

**Always:**
- Tombstones en un `ref` (sin re-render), escritos por `onDeleted`, `handleBankDeleted` y los ids con delete exitoso del bulk; consultados por `onCreated` y por el restore del bulk; podados en cada `loadBanks` exitoso (el servidor es autoritativo). UUID v7 ⇒ sin falsos positivos.
- Specs con datos semánticos locales (recency, truncación, layout: `banksListSubtitle`, `banksCardsIdentity`, `banksCardsLayout`, `bankTruncationTooltips`, `banksTableIdentity`, `banksStackedList`) NO se migran.
- Fixtures casi-iguales se derivan por spread (`{ ...ACME, updatedAt: ACME.createdAt }`), nunca duplicando literales.
- El peek muestra los 5 campos ya en memoria (id, name, shortName, createdAt, updatedAt) — sin fetch.
- E2E solo se escribe; corre en CI (sin browsers Playwright en local).

**Ask First:**
- Si migrar un spec a fixtures canónicas cambia aserciones o comportamiento del test.
- Si cablear `o`/Shift+rango exige refactor estructural de `DataTable`/`BanksStackedList` más allá de añadir handlers y props opcionales.

**Never:**
- No tocar las secciones de `deferred-work.md` que se conservan: authorize AuthN/AuthZ, privacy-theater, Sentry/Datadog, PR #120, event-id-generation.
- No cambiar `cookie_samesite` ni código del API — el cierre SameSite es solo documental.
- No añadir keyboard nav a `BanksCards` (sin roving focus en el contrato).
- No sincronizar tombstones con el detalle (`[id]/page.tsx`) — alcance lista.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Mercure `deleted` durante ventana del bulk-restore | re-probe confirmó el id; `bank.deleted` procesado antes del `setBanks` del restore | fila NO restaurada NI re-seleccionada | autocurable; sin toast |
| Redelivery `created` tras `delete` | id en tombstones | no se re-inserta | silencioso |
| `loadBanks` exitoso | tombstones poblados | set vaciado; lista del servidor manda | N/A |
| `o` sobre fila activa (tabla/stacked) | fila con foco roving | RecordSheet (drawer) abre con los 5 campos + enlace al detalle; Esc cierra y el foco vuelve a la fila | N/A |
| Primer Shift+↓/↑ | fila enfocada, sin ancla | ancla=fila actual, baseline=selección actual; selecciona rango [ancla..nueva] ∪ baseline | N/A |
| Shift+↑/↓ continuado | ancla fija | selección = baseline ∪ rango[ancla..foco] (contraer deselecciona solo lo que el rango añadió) | N/A |
| Movimiento sin Shift / cambio externo de selección | ancla activa | ancla y baseline se resetean | N/A |
| e2e: hover shortName de tarjeta | shortName truncado (50 chars) | tooltip `[data-slot="tooltip-content"]` visible con el valor completo | N/A |
| e2e: click en shortName de tarjeta | vista cards | URL no cambia; click en el resto de la tarjeta navega al detalle | N/A |

</frozen-after-approval>

## Code Map

- `pwa/src/app/backoffice/banks/page.tsx` — `runBulkDelete` (restore L456-472), handlers realtime (L491-518), `loadBanks`; aquí viven tombstones, estado del peek y render de `RecordSheet`.
- `pwa/src/components/erpify/DataTable.tsx` — roving focus `handleRowKeyDown` (~L367-401): añadir `o` (prop opcional `onRowPeek`) y Shift+↑/↓ con ancla.
- `pwa/src/app/backoffice/banks/_components/BanksTable.tsx`, `BanksStackedList.tsx` — pass-through de peek/rango (stacked replica el contrato de teclado de la tabla).
- `pwa/src/components/erpify/RecordSheet.tsx` — drawer existente (`open/onOpenChange/title/variant/footer`); no modificar salvo necesidad de `testId`.
- `pwa/src/context/shared/domain/types/keyboard.ts` — añadir `O: "o"`.
- `pwa/tests/app/backoffice/banks/_fixtures.ts`, `_mocks.ts` — helpers canónicos (ACME/BETA; `routerMock/containerMock/toastNotifierMock/bankFormMocks/bankRealtimeMock`).
- `pwa/tests/e2e/backoffice/banks-containment.spec.ts` + `pwa/tests/e2e/fixtures/banks-real-api.ts` — e2e con seeding por API real; toggle cards `banks-list__view-toggle-cards`; shortname testid `banks-cards__shortname-${id}`.
- `docs/integration-architecture.md`, `pwa/docs/production-deployment.md` — destino de la nota same-origin.
- `_bmad-output/implementation-artifacts/deferred-work.md` — recorte final.

## Tasks & Acceptance

**Execution:**
- [x] `pwa/src/context/shared/domain/types/keyboard.ts` — añadir `O` — constante para el peek.
- [x] `pwa/src/app/backoffice/banks/page.tsx` — tombstones (ref + escrituras/consultas/poda según Always) — cierra ambas resurrecciones.
- [x] `pwa/src/components/erpify/DataTable.tsx` — `onRowPeek` (tecla `o`) + Shift+↑/↓ con ancla/baseline — primitivas reutilizables del design system.
- [x] `pwa/src/app/backoffice/banks/_components/BanksTable.tsx` + `BanksStackedList.tsx` + `page.tsx` — cablear peek (estado + `RecordSheet` drawer con 5 campos y enlace al detalle vía `bankRoutes`) y rango en ambas superficies.
- [x] `pwa/tests/app/backoffice/banks/` — tests unit nuevos: tombstone en ventana bulk (vía `bankRealtimeMock` capture), `created` tras `deleted`, peek `o` (abre/cierra/foco), rango Shift (ancla, extensión, contracción, reset) — mirror de patrones existentes. (`banksRealtimeTombstones`, `banksListPeek`, `banksShiftRangeSelection`; + poda de tombstones en reload)
- [x] `pwa/tests/e2e/backoffice/banks-containment.spec.ts` — 2 tests: hover shortName→tooltip; click shortName no navega / resto de tarjeta sí.
- [x] Migración fixtures: `banksListRetry`, `banksListRealtimeRecovery`, `bankDetailDelete`, `bankFormFeedback` (spread para `updatedAt`), `bankFormMutationError`, `bankEditStaleBank` → ACME/BETA de `_fixtures.ts`.
- [x] Migración mocks: `bankListDelete` (router/container/toast → factorías), `banksBulkActions` (container — ya canónico en baseline, no-op verificado), `bankEditStaleBank` (container+toast), `bankDetailDelete` (todos); verificado `banksListSkeleton` (no-op: ya usa `_mocks`).
- [x] `docs/integration-architecture.md` — subsección «Realtime (Mercure): same-origin requerido» (cookie `SameSite=Strict` por diseño; cross-origin rompe el EventSource en silencio y no está soportado); `pwa/docs/production-deployment.md` — cross-ref en la viñeta `NEXT_PUBLIC_API_BASE_URL`.
- [x] `_bmad-output/implementation-artifacts/deferred-work.md` — eliminar: sección bulk-restore (2026-06-06), sección sonar-dup-density (2026-06-06), sección UX restos (2026-06-04), y las viñetas «Cross-event-type redelivery» y «Cookie SameSite=Strict» de la sección code-review Mercure; conservar todo lo demás intacto. (diff solo-eliminaciones; 5 secciones `##` restantes)

**Acceptance Criteria:**
- Given las secciones listadas eliminadas, when se lee `deferred-work.md`, then las secciones conservadas están byte-a-byte intactas.
- Given los specs migrados, when corre la suite, then pasan sin cambiar qué comportamiento asserta cada test.
- Given una fila seleccionada borrada por otro cliente durante el bulk, when el restore se aplica, then la fila no reaparece ni infla el contador de selección.

## Spec Change Log

## Design Notes

- **Tombstones:** el «Never» previo era alcance de aquella PR; handler y bulk ya conviven en `page.tsx`, el acoplamiento real es un ref interno de la página. `onUpdated` ya es seguro (map solo reemplaza existentes). Poda en `loadBanks` mantiene el set acotado sin TTL.
- **Rango Shift:** semántica estándar (Explorer): baseline capturada al fijar ancla; cada pulsación recalcula `baseline ∪ rango[ancla..foco]` — contraer no destruye selección previa ajena al rango. El anuncio aria ya coalescea (`page.tsx` L349-361).
- **Flake conocido:** el test «success toast» de `bankListDelete` flakea ~40% bajo carga de CPU en main limpio — no bisecar el diff por él.
- **Implementación (2026-06-06):** `RecordSheet` ganó el prop opcional `testId` (previsto en el Code Map); `BanksStackedList` ganó `onSelectionChange?: (ids: Set<string>) => void` para aplicar `baseline ∪ rango` (dentro de «handlers y props opcionales» — sin refactor estructural). El retorno de foco a la fila al cerrar con Esc lo da Base UI por defecto (restaura el foco previo a la apertura) — verificado por test, sin código propio. El drawer deriva su banco de `banks`, así que un borrado (local o remoto) durante el peek lo cierra solo.
- **Rebase (hecho, 2026-06-06):** `main` (PR #160) eliminó la sección event-id-generation de `deferred-work.md` después del baseline `70751ad`; este branch la conservó intacta por el Never del spec. El rebase pre-PR produjo un conflicto de eliminaciones adyacentes, resuelto aplicando ambas: post-rebase quedan **4** secciones `##` (las 5 conservadas del spec menos event-id-generation, retirada por `main`). El AC «5 secciones» se validó contra el baseline antes del rebase.

## Review Findings

Step-04 (2026-06-06): tres revisores adversariales (blind / edge-case / acceptance). El auditor confirmó los 3 AC, todos los Always/Never y todas las filas de la matriz. Sin intent_gap ni bad_spec. **Patches aplicados** (con tests de regresión):

1. **Ancla de rango obsoleta** (DataTable + BanksStackedList): el ancla cacheada indexaba `data`/`banks`; una mutación externa (paginación, delete realtime) podía hacer `data[i] === undefined` (crash) o seleccionar filas equivocadas, y un Clear externo no reseteaba la baseline (fila de la matriz «cambio externo de selección»). Fix: efectos que resetean `rangeRef` al cambiar la identidad del slice o al llegar una selección no emitida por `extendRange` (`rangeEmittedRef`). El reset por cambio externo de selección también cubre el checkbox del stacked (asimetría detectada).
2. **Peek de fila borrada en vivo**: el drawer se desmontaba sin cierre controlado, `peekId` quedaba obsoleto (reapertura fantasma si el id reaparecía) y el foco caía a `<body>`. Fix: efecto que limpia `peekId` y enfoca el contenedor de la lista.
3. **Poda de tombstones durante bulk en vuelo**: un reload por reconnect completado dentro de la ventana del re-probe vaciaba el set que el restore estaba a punto de consultar. Fix: la poda se omite mientras `bulkDeleteInFlightRef` está activo.
4. **`o` insensible a mayúsculas** (`toLowerCase`, Shift excluido — reservado para rango) en ambas superficies.
5. **Anchor del cross-ref** en `pwa/docs/production-deployment.md` (deep-link a la subsección).

**Rechazados** (ruido / diseño aceptado por el spec): `onUpdated` sin guard (map solo reemplaza — Design Notes), set sin TTL (poda por load es el diseño), divergencia toggleSelect/setSelectedIds (falsa premisa: ambos acaban en `setSelectedIds`), Shift+Space sin guard, unicidad de `cardShortName` (runPrefix ≈33 chars, `-CARD-` siempre sobrevive), asserts de call-count (convención de la suite). **Defer:** ninguno.

## Suggested Review Order

**Anti-resurrección (tombstones)**

- Punto de entrada: el set de ids borrados, su contrato completo en un comentario
  [`page.tsx:137`](../../pwa/src/app/backoffice/banks/page.tsx#L137)
- `onCreated` consulta el tombstone — la redelivery tardía no re-inserta
  [`page.tsx:539`](../../pwa/src/app/backoffice/banks/page.tsx#L539)
- `onDeleted` escribe el tombstone antes de filtrar la lista
  [`page.tsx:549`](../../pwa/src/app/backoffice/banks/page.tsx#L549)
- El restore del bulk resta los tombstones antes de resucitar/re-seleccionar
  [`page.tsx:506`](../../pwa/src/app/backoffice/banks/page.tsx#L506)
- Poda en load exitoso, bloqueada mientras el bulk está en vuelo (hallazgo del review)
  [`page.tsx:154`](../../pwa/src/app/backoffice/banks/page.tsx#L154)
- Los deletes con éxito del bulk también tombstonean
  [`page.tsx:460`](../../pwa/src/app/backoffice/banks/page.tsx#L460)

**Rango Shift+↑/↓ (Explorer)**

- Ancla + baseline cacheadas; semántica `baseline ∪ rango`
  [`DataTable.tsx:329`](../../pwa/src/components/erpify/DataTable.tsx#L329)
- Los dos resets nuevos: mutación del slice y selección externa (hallazgos del review)
  [`DataTable.tsx:339`](../../pwa/src/components/erpify/DataTable.tsx#L339)
- `extendRange` marca lo emitido para distinguir cambios propios de externos
  [`DataTable.tsx:407`](../../pwa/src/components/erpify/DataTable.tsx#L407)
- Réplica del contrato en la superficie stacked (sin DataTable)
  [`BanksStackedList.tsx:60`](../../pwa/src/app/backoffice/banks/_components/BanksStackedList.tsx#L60)

**Peek `o` (RecordSheet)**

- Estado del peek derivado de `banks` — sin fetch, drawer se autocierra
  [`page.tsx:391`](../../pwa/src/app/backoffice/banks/page.tsx#L391)
- Cleanup si la fila peekeada desaparece: sin reapertura fantasma, foco al contenedor
  [`page.tsx:399`](../../pwa/src/app/backoffice/banks/page.tsx#L399)
- Render del drawer: 5 campos en memoria + enlace al detalle vía `safeHref`
  [`page.tsx:791`](../../pwa/src/app/backoffice/banks/page.tsx#L791)
- Tecla `o` insensible a CapsLock, Shift reservado al rango
  [`DataTable.tsx:460`](../../pwa/src/components/erpify/DataTable.tsx#L460)
- `RecordSheet` solo gana `testId` opcional (previsto por el spec)
  [`RecordSheet.tsx:23`](../../pwa/src/components/erpify/RecordSheet.tsx#L23)

**Documentación same-origin (cierre solo documental)**

- Nueva subsección: por qué realtime exige un único origen público
  [`integration-architecture.md:59`](../../docs/integration-architecture.md#L59)
- Cross-ref con deep-link en la viñeta `NEXT_PUBLIC_API_BASE_URL`
  [`production-deployment.md:18`](../../pwa/docs/production-deployment.md#L18)

**Periféricos: tests, fixtures y recortes**

- La ventana del bulk-restore reproducida con re-probe diferido + delete Mercure
  [`banksRealtimeTombstones.test.tsx:105`](../../pwa/tests/app/backoffice/banks/banksRealtimeTombstones.test.tsx#L105)
- Regresión del review: Clear externo no revive la baseline
  [`banksShiftRangeSelection.test.tsx:127`](../../pwa/tests/app/backoffice/banks/banksShiftRangeSelection.test.tsx#L127)
- e2e (solo CI): tooltip de tarjeta + click contenido en shortName
  [`banks-containment.spec.ts:142`](../../pwa/tests/e2e/backoffice/banks-containment.spec.ts#L142)
- Migraciones a `_fixtures`/`_mocks` — diff de sustitución pura, sin cambiar aserciones
  [`bankListDelete.test.tsx:21`](../../pwa/tests/app/backoffice/banks/bankListDelete.test.tsx#L21)
- Recorte de `deferred-work.md`: solo eliminaciones, 5 secciones conservadas
  [`deferred-work.md:1`](deferred-work.md#L1)
- Constante `O` del peek
  [`keyboard.ts:16`](../../pwa/src/context/shared/domain/types/keyboard.ts#L16)

## Verification

**Commands:**
- `make pwa.test.unit` — expected: verde (módulos banks completos, incl. nuevos tests).
- `make pwa.quality` — expected: ESLint + Prettier limpios.
- `grep -c "^## " _bmad-output/implementation-artifacts/deferred-work.md` — expected: 5 secciones restantes (4 tras el rebase sobre `main`, que retiró event-id-generation vía PR #160 — ver Design Notes).

**Manual checks (if no CLI):**
- E2E nuevos: revisar en CI (sin browsers locales); opcional verificación manual con playwright-cli + Chrome del sistema.
- Peek y rango: smoke manual en `https://localhost/backoffice/banks` del worktree (`↑/↓`, `o`, `Shift+↓`, Esc).
