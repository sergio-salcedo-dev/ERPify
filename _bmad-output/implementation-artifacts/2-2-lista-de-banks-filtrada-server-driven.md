---
baseline_commit: 0ffeea8b32864e6cf4cb04a6a40fd31141196bb7
---

# Story 2.2: Lista de banks filtrada server-driven

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a usuario del backoffice,
I want que el filtrado, la ordenación y la paginación de la lista de banks se resuelvan en el servidor,
so that los resultados sean consistentes y escalen con el volumen de datos en lugar de limitarse a la primera página cargada en memoria.

## Contexto de ejecución (leer antes de empezar)

- **Dónde se trabaja:** worktree `.claude/worktrees/shared-search-filters-aj0w/`, rama
  `feat/shared-search-filters-aj0w`, **PR #180**. Gates desde **dentro del worktree**.
- **Depende de:** Story 2.1 (vocabulario `Filter` + `buildSearchParams`, ya en `review`/commit `0ffeea8`) y de
  la Épica 1 completa (1.7 rango `createdAt` + `shortName`; 1.8 orden server-side) — todo `done` en esta rama.
- **DECISIÓN DE ALCANCE (Sergio, 2026-06-08): server-driven COMPLETO con cursor.** No solo filtrado y orden:
  también la **paginación** pasa a cursor keyset del servidor. La lista deja de cargar ≤1000 filas y filtrar/
  ordenar/paginar en memoria; cada página se pide al servidor.
- **Riesgo alto, lista estrella.** `page.tsx` (847 líneas) acopla realtime (Mercure), borrado optimista,
  bulk-delete con re-probe/restore, tombstones, selección y foco — todo asume "el cliente tiene la lista
  completa". El recableado a "el cliente tiene SOLO la página actual" es el grueso del riesgo. Ver
  «Recableado de realtime» y «Anti-regresiones».
- **Viable sin cambio visual:** la UI de paginación ya es prev/next + "Page N" + pageSize (no salto a página
  arbitraria), que mapea a `page`+`cursor`+`limit`; el API soporta navegación bidireccional con `page`+`cursor`.

## Acceptance Criteria

1. **Búsqueda server-driven en el repositorio** — `BankRepository.search(criteria)` acepta filtros, orden,
   `page`, `cursor` y `limit`. `ApiBankRepository` serializa los filtros con `buildSearchParams` (Story 2.1)
   y añade `sort`/`direction`, `page`, `cursor`, `limit` y `paginationMode` hacia `GET /api/v1/backoffice/banks`.
   El filtrado/orden/paginación **client-side se elimina por completo** (`applyFilters`/`applySort` de
   `banksFilterSort.ts` y `paginate.ts` desaparecen, salvo lo que siga teniendo uso server-driven —
   ver Dev Notes).

2. **Cursor opaco, descartado al cambiar la query** — un cambio en cualquier filtro activo, en el orden o en
   el `pageSize` reconstruye la búsqueda desde la página 1 y **descarta el cursor** (regla aprendida del race
   debounce+paginación). El cursor nunca se interpreta ni se fabrica client-side: se reenvía verbatim.

3. **Paridad de capacidad (sin pérdida)** — se preservan TODAS las capacidades actuales, ahora server-side:
   búsqueda por `name` (contains), filtro `shortName` (contains), rango `createdFrom`/`createdTo`
   (`gte`/`lte` sobre `createdAt`) y orden por `shortName`/`name`/`createdAt`/`updatedAt` (asc/desc).
   `direction` viaja en mayúsculas (`ASC`/`DESC`) — el enum PWA es minúsculas, hay que mapear.

4. **Sin cambios visuales** — misma UI (toolbar de búsqueda, panel de filtros, selects de orden, prev/next +
   "Page N" + items-per-page, contador "Total banks"). Solo cambia el origen del filtrado/orden/paginación.
   El total ("Total banks: N") se preserva vía `paginationMode=detailed`; el contador "added this week" se
   **retira** (decisión Sergio 2026-06-08), quedando solo "Total banks: N".

5. **Realtime sin regresiones** — los eventos Mercure (created/updated/deleted) reconcilian la **página
   actual** contra el servidor (refetch silencioso coalescido), nunca insertando filas que el filtro/orden
   activos no devolverían. El borrado optimista propio, el bulk-delete con re-probe/restore y la gestión de
   foco/selección siguen funcionando. Los tests unit y e2e existentes se adaptan y pasan (mockear el hook
   realtime donde aplique el flake conocido; esperar el badge de filtro activo antes de paginar en e2e).

6. **Documentación fase 2** — `pwa/docs/` y `docs/architecture-pwa.md` documentan el builder (2.1) y el patrón
   server-driven (criteria → `buildSearchParams` → endpoint → cursor). `make pwa.quality` + Vitest + `tsc`
   en verde; e2e de banks en verde.

## Tasks / Subtasks

- [x] **Task 1 — Contrato del repositorio + criteria** (AC: #1, #3)
  - [x] Definir `BankSearchCriteria` (en `bank/domain/BankRepository.ts`): `{ filters: Filter[]; sort: BankSort | null; page: number; cursor?: string; limit: number }`. `BankSort = { field: BanksSortableColumn; direction: SortDirection }` (reusar columnas/SortDirection existentes).
  - [x] Ampliar `BankSearchPage`: `{ banks: Bank[]; cursor: string; currentPage: number; hasMorePages: boolean; totalCount: number | null }`.
  - [x] Cambiar la firma `BankRepository.search(criteria: BankSearchCriteria): Promise<BankSearchPage>`.
  - [x] `SearchBanks.run(criteria)` pasa a delegar con criteria.

- [x] **Task 2 — `ApiBankRepository.search` server-driven** (AC: #1, #2, #3)
  - [x] Construir params: `buildSearchParams(criteria.filters)`; añadir `sort`=`field` + `direction`=`direction.toUpperCase()` solo si hay sort; `page`; `cursor` (solo si definido); `limit`; `paginationMode`=`detailed`.
  - [x] `GET ${BANKS.LIST}?${params}`. Actualizar el type guard `isBankSearchResponse` al envelope real `{ data, pagination: { currentPage, pageCount, count, hasMorePages, cursor } }`.
  - [x] Devolver `{ banks, cursor, currentPage, hasMorePages, totalCount: count }`. El cursor siempre presente (opaco).
  - [x] Tests unit del repo (`tests/context/backoffice/bank/infrastructure/ApiBankRepository.test.ts`) cubriendo: serialización de filtros+sort+page+cursor+limit, mapeo `direction` a mayúsculas, parseo del envelope nuevo.

- [x] **Task 3 — Mapeo `BanksFilter`/`BanksSort` → criteria** (AC: #3)
  - [x] Helper en `_lib` (p.ej. `banksSearchCriteria.ts`) que convierta `BanksFilter` → `Filter[]`: `name`→`contains` (si trim ≠ ""), `shortName`→`contains`, `createdFrom`→`gte` sobre `createdAt` (ISO inicio-de-día), `createdTo`→`lte` sobre `createdAt` (ISO fin-de-día). Campos vacíos/whitespace/fecha no parseable → omitidos (no generan filtro).
  - [x] Conversión de fecha: `formatToISO(startOfDay(parseISO(value)))` / `formatToISO(endOfDay(parseISO(value)))` vía `dateTimeProvider`; `parseISO` null → sin bound (replica la semántica actual de `applyFilters`).
  - [x] `BanksSort` → `{ field: columnId, direction }`; `null` → sin sort.
  - [x] Tests unit del helper (escalares, rango, vacíos, fecha mid-edit no parseable).

- [x] **Task 4 — Recableado de `page.tsx` a server-driven** (AC: #1, #2, #4, #5)
  - [x] Eliminar `visibleBanks`/`applyFilters`/`applySort`/`paged`/`paginate`. `banks` = página actual del servidor.
  - [x] Estado: `filter`, `sort`, `pageSize`, `page`, y `cursor` (último cursor de respuesta, en ref). `loadBanks` construye criteria (filtros mapeados + sort + page + cursor + limit=pageSize) y setea `banks`/`cursor`/`currentPage`/`hasMorePages`/`totalCount`.
  - [x] Disparo de carga: efecto sobre `[filter, sort, page, pageSize]`. Al cambiar `filter`/`sort`/`pageSize` → `page=1` + cursor descartado ANTES del fetch (AC #2). Next → `page+1` con el último cursor; Prev → `page-1` con el último cursor. `hasNext` = `hasMorePages`; `hasPrev` = `currentPage > 1`.
  - [x] Mantener el debounce de filtros (300ms, ya en `BanksFilters`) — cada cambio debounced → nueva query. Respetar el guard del race debounce+page-reset.
  - [x] Cabecera "Total banks" usa `totalCount` (DETAILED). **Retirar** "added this week" + `countRecentlyCreated`/`bankRecency.ts` si quedan sin uso (decisión 2026-06-08).

- [x] **Task 5 — Recableado de realtime / borrado / bulk** (AC: #5)
  - [x] created/updated/deleted → refetch silencioso coalescido de la página actual (no insertar/mover filas en memoria según filtro/orden, que ya no se evalúan en cliente). `onReconnect` y `reconcileAfterErroredLoad` ya refetchan: unificar.
  - [x] Conservar el borrado optimista propio (quita la fila al instante) + toast; reconciliar con refetch tras la mutación.
  - [x] Conservar bulk-delete con re-probe/restore (opera sobre el snapshot de la página actual) + foco/selección. Revisar tombstones: con refetch autoritativo se simplifican; mantener solo lo necesario para la ventana optimista. NO regresar el comportamiento de foco/anuncios/selección.

- [x] **Task 6 — Limpieza client-side** (AC: #1)
  - [x] Eliminar `applyFilters`/`applySort` de `banksFilterSort.ts` (conservar `BanksFilter`, `BanksSort`, `EMPTY_FILTER`, `DEFAULT_SORT`, `BANKS_SORTABLE_COLUMNS`, `countPanelFilters`, `hasActiveFilter`, `isDefaultSort` — siguen usándose por la UI). Eliminar `paginate.ts` (`paginate`) si nada más lo usa; conservar `BANKS_PAGE_SIZE_OPTIONS`/`BANKS_PAGE_SIZE_DEFAULT`/`BanksPageSize` (los usa la UI).
  - [x] Borrar/migrar `banksFilterSort.test.ts` y `paginate.test.ts` acorde a lo que se elimine.

- [x] **Task 7 — Adaptar tests unit de la lista** (AC: #5)
  - [x] Actualizar el mock de `BackOfficeSearchBanks.run` (en `_mocks.ts` / specs) al nuevo criteria→page shape `{ banks, cursor, currentPage, hasMorePages, totalCount }`.
  - [x] Reescribir/ajustar: `banksFiltersDebounce`, `banksFiltersToolbarSearch`, `banksEmptyFiltered` (ahora asertan que el cambio de filtro llama a `searchRun` con la criteria correcta, no que filtra en memoria); `banksListSubtitle`/total (usa `totalCount`); paginación (next/prev disparan `searchRun` con page+cursor); realtime (`banksRealtimeTombstones`, `banksListRealtimeRecovery`) → refetch; `banksListPeek`, `banksShiftRangeSelection`, `bankListDelete`, `banksListRetry` (mockear realtime, de-flake conocido).

- [x] **Task 8 — Adaptar e2e** (AC: #5)
  - [x] `tests/e2e/fixtures/banks-api.ts`: el mock de `GET …/banks` debe honrar query params (filtros/sort/page/cursor/limit) y devolver el envelope `pagination` real, o re-encarar los specs para asertar los params de la request. `banks.spec.ts` (mock), `banks-realtime.spec.ts`, y los `banks-real-api*.spec.ts` (API real) se adaptan: esperar el badge de filtro activo antes de paginar (race conocido).

- [x] **Task 9 — Docs + gates** (AC: #6)
  - [x] `docs/architecture-pwa.md` + `pwa/docs/`: builder `buildSearchParams`, patrón criteria→endpoint→cursor, regla "descartar cursor al cambiar query", `paginationMode=detailed`.
  - [x] `make pwa.quality` + Vitest (suite banks) + `tsc --noEmit` + e2e de banks en verde.

## Dev Notes

### Contrato API exacto (verificado en esta rama)

- **Endpoint:** `GET /api/v1/backoffice/banks` (`BankSearchController`).
- **Filtros (banks `searchFieldMap`):** `name`→`eq|in|contains` (normalizado acentos+lower), `shortName`→`eq|in|contains` (ASCII upper), `id`→`eq|in`, `createdAt`/`updatedAt`→`gt|gte|lt|lte` (ISO-8601 ATOM). Banks UI usa: `name` contains, `shortName` contains, `createdAt` gte/lte.
- **Orden:** `sort=<campo>` + `direction=ASC|DESC` (**mayúsculas** — `SortDirection.php` backing). Campos ordenables: `name`/`shortName`/`createdAt`/`updatedAt` (NO `id`). `sort` vacío → sin orden (no 400); campo fuera de allow-list → 400 `unknown-sort-field`.
- **Paginación:** keyset/cursor HMAC opaco. `page` (target) + `cursor` (de la respuesta previa) + `limit`. El `page` calcula el delta respecto al `currentPage` del cursor → soporta next/prev. `paginationMode`=`light` (default, sin count) | `detailed` (con `count`/`pageCount`).
- **Envelope respuesta:** `{ data: BankPrimitives[], pagination: { currentPage: number, pageCount: number|null, count: number|null, hasMorePages: boolean, cursor: string } }`. En DETAILED, `count`=total que matchea los filtros.
- **`direction` mapping:** la PWA tiene `SortDirection = { ASC:"asc", DESC:"desc" }`. El wire exige `ASC`/`DESC`. Serializar con `.toUpperCase()` (o un mapa explícito) en `ApiBankRepository`. NO cambiar el enum PWA (lo usan los selects y el sort de la tabla).

### Recableado de page.tsx (recipe)

- `banks` pasa a ser la PÁGINA actual (servidor ya filtró/ordenó/paginó). Sin `visibleBanks`/`paged`.
- Mantener `cursor` en un **ref** (no dispara refetch por sí solo); actualizarlo desde cada respuesta. El efecto de carga depende de `[filter, sort, page, pageSize]`.
- **Reset/descartar cursor (AC #2):** al cambiar `filter`/`sort`/`pageSize`, en el mismo idioma "ajustar estado durante el render" ya presente, poner `page=1` y limpiar el ref del cursor ANTES de que el efecto dispare el fetch. Next/Prev solo cambian `page` y conservan el cursor.
- **Debounce + race:** el debounce de 300ms vive en `BanksFilters` (ya implementado). El guard del race "debounce + reset de página" debe mantenerse: una página en vuelo obsoleta no debe pisar el resultado de la nueva query (usar un token/seq de petición o abortar la anterior). Ver memoria del proyecto sobre este race.
- `hasNext = hasMorePages`; `hasPrev = currentPage > 1`. La UI de `BanksPagination` no cambia.

### Cabecera "Total banks" / "added this week"

- **Total banks:** usar `paginationMode=detailed` → `pagination.count` = total que matchea los filtros activos. Mostrar `totalCount` (mejor que el `banks.length` actual: refleja el total real, no solo lo cargado). Si `null` (no debería con DETAILED), ocultar el contador.
- **"added this week" (recentCount):** **DECISIÓN (Sergio, 2026-06-08): retirar.** Se elimina el fragmento de la cabecera (queda solo "Total banks: N" del `count` DETAILED). Eliminar también `countRecentlyCreated` y `_lib/bankRecency.ts` (+ su test) si no quedan otros consumidores; verificar con grep antes de borrar.

### Recableado de realtime (lo más delicado)

- Bajo server-driven, el cliente no puede decidir si un `created`/`updated` entra en la página actual (filtro/orden viven en el servidor). Estrategia: **cualquier evento Mercure (created/updated/deleted) → refetch silencioso coalescido de la página actual** (re-ejecuta la query vigente). Esto mantiene la página consistente sin lógica de filtro/orden en cliente.
- Conservar: borrado optimista propio (quita la fila + toast al instante; el refetch reconcilia); bulk-delete con re-probe/restore (sobre el snapshot de la página) + foco/selección/anuncios.
- Tombstones: con refetch autoritativo del servidor pierden parte de su razón de ser; mantener el mínimo para la ventana entre el borrado optimista y el refetch. NO eliminar la protección anti-resurrección sin sustituirla por el refetch.
- Coalescer: una ráfaga de eventos = un solo refetch (ya hay precedente con `reloadingRef`).

### Limpieza / qué se conserva de `_lib`

- `banksFilterSort.ts`: ELIMINAR `applyFilters`, `applySort`, `containsCi`, comparadores y colador. CONSERVAR `BanksFilter`, `BanksSort`, `BanksSortableColumn`, `BANKS_SORTABLE_COLUMNS`, `EMPTY_FILTER`, `DEFAULT_SORT`, `countPanelFilters`, `hasActivePanelFilter`, `hasActiveFilter`, `isDefaultSort` (los usa `BanksFilters`/`page.tsx`).
- `paginate.ts`: ELIMINAR `paginate`/`PaginatedSlice`. CONSERVAR `BANKS_PAGE_SIZE_OPTIONS`, `BANKS_PAGE_SIZE_DEFAULT`, `BanksPageSize` (los usa `BanksPagination`/`page.tsx`).

### Anti-regresiones (no romper)

- Borrado optimista + toast "Bank deleted"; bulk-delete (pre-check 404, optimista, re-probe/restore, foco a vecino/contenedor, anuncios `aria-live`); peek (`o`), selección rango (shift), Esc limpia selección, densidad/vista persistidas, skeleton/empty/error boundary, MutationError persistente con recovery `Refresh list`.
- "No visual change": data-testids y estructura DOM se mantienen; la paginación sigue prev/next + "Page N".

### Testing

- **Unit:** mock `searchRun` devuelve el nuevo page shape; las specs de filtro/orden asertan la criteria enviada (no el filtrado en memoria). Adaptar la lista completa de specs de `tests/app/backoffice/banks/` que dependan de filtrado/paginación client-side o del shape antiguo de `search()`.
- **Repo:** nuevo `ApiBankRepository.test.ts` (serialización + parseo del envelope).
- **e2e:** el fixture `banks-api.ts` debe honrar query params o los specs cambian de estrategia; esperar el badge de filtro activo antes de paginar.
- Reglas de test del proyecto: query by role/label; `findBy*`/`waitFor` (sin sleeps); mockear `useBankRealtime` donde el flake realtime aplique.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 2.2] — ACs (incl. paridad 1.7/1.8).
- [Source: api/src/Shared/Application/Http/Search/SearchQuery.php] — params: cursor/page/limit/paginationMode/filters/sort/direction.
- [Source: api/src/Shared/Domain/Search/SortDirection.php] — wire `ASC`/`DESC` (mayúsculas).
- [Source: api/src/Shared/Infrastructure/Http/Controller/AbstractSearchController.php] — envelope `pagination` (currentPage/pageCount/count/hasMorePages/cursor).
- [Source: api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/DoctrineBankRepository.php] — field map (filtros + sort por campo).
- [Source: pwa/src/context/shared/infrastructure/Search/buildSearchParams.ts] — builder (Story 2.1).
- [Source: pwa/src/app/backoffice/banks/page.tsx] — orquestador actual (realtime/bulk/optimista a recablear).
- [Source: pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts] — `applyFilters`/`applySort` a eliminar; resto a conservar.
- [Source: pwa/src/app/backoffice/banks/_lib/paginate.ts] — `paginate` a eliminar; constantes a conservar.
- [Source: pwa/src/app/backoffice/banks/_components/BanksPagination.tsx] — UI prev/next + Page N (sin cambios).
- [Source: pwa/src/context/backoffice/bank/infrastructure/ApiBankRepository.ts] — `search()` a reescribir.
- [Source: pwa/tests/app/backoffice/banks/_mocks.ts] — mock `BackOfficeSearchBanks` (shape a actualizar).
- [Source: docs/project-context.md / pwa/CLAUDE.md] — reglas PWA, realtime, testing.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Claude Opus 4.8, 1M context)

### Debug Log References

- `make pwa.test.unit` (suite completa) → 461/461 en verde (incluye banks: 136).
- `tsc --noEmit` (TS 6 strict, contenedor pwa del worktree) → EXIT 0.
- `make pwa.quality` (ESLint + Prettier) → EXIT 0.
- **E2E:** los specs (`banks.spec.ts` + fixture `banks-api.ts`) se actualizaron al modelo server-driven y
  pasan tsc + ESLint; NO se ejecutó el runner Playwright en local (browsers no instalados en el contenedor
  + flakes locales documentados: timeout Mercure, polución de DB, workaround Ubuntu 26.04). Verificación real
  delegada a CI.

### Completion Notes List

- **Contrato/repositorio:** `BankRepository.search(criteria)` con `BankSearchCriteria`
  (`filters/sort/page/cursor/limit`) → `BankSearchPage` (`banks/cursor/currentPage/hasMorePages/totalCount`).
  `ApiBankRepository` serializa con `buildSearchParams` (2.1) + `sort`/`direction` (mapeado a mayúsculas
  `ASC`/`DESC`), `page`, `cursor` (solo page>1), `limit`, `paginationMode=detailed`. `SearchBanks.run(criteria)`.
- **Mapeo:** `_lib/banksSearchCriteria.ts` (`toBankFilters`/`toBankSort`): name/shortName → `contains`,
  createdFrom/To → `gte`/`lte` sobre `createdAt` con límites ISO inclusivos (gate `yyyy-mm-dd` completo).
- **page.tsx server-driven:** `banks` = página actual; cursor en ref (solo se envía con page>1, descartado al
  cambiar query); guard de carrera por token de secuencia; realtime created/updated/deleted/reconnect →
  refetch silencioso coalescido que cede ante un bulk-delete en vuelo; cabecera "Total banks" desde
  `totalCount` (DETAILED). Eliminados `applyFilters`/`applySort`/`paginate`/`countRecentlyCreated` y el
  contador "added this week" (decisión 2026-06-08). Sin cambios visuales; UI de paginación intacta.
- **Anti-regresiones preservadas:** borrado optimista propio + toast, bulk-delete con re-probe/restore +
  tombstone (ventana de bulk), foco/selección/anuncios, peek, skeleton/empty/error, MutationError persistente.
- **Tests:** nuevos `banksSearchCriteria.test.ts` + `ApiBankRepository.test.ts` reescrito; specs de la lista
  adaptados al nuevo shape/realtime (mock refleja el estado servidor tras el evento); `banksFilterSort.test.ts`
  y `bankRecency.test.ts` podados; `paginate.test.ts` eliminado; fixture e2e emula filtros/orden/paginación.
- **Docs:** `docs/architecture-pwa.md` (patrón server-driven) + `pwa/docs/server-driven-search.md` (receta).

### File List

- `pwa/src/context/backoffice/bank/domain/BankRepository.ts` (criteria/page + search signature)
- `pwa/src/context/backoffice/bank/application/SearchBanks.ts` (run(criteria))
- `pwa/src/context/backoffice/bank/infrastructure/ApiBankRepository.ts` (search server-driven + envelope guard)
- `pwa/src/app/backoffice/banks/_lib/banksSearchCriteria.ts` (nuevo: mapeo UI → criteria)
- `pwa/src/app/backoffice/banks/page.tsx` (recableado server-driven + realtime refetch)
- `pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts` (eliminados applyFilters/applySort)
- `pwa/src/app/backoffice/banks/_lib/paginate.ts` (eliminado paginate; constantes conservadas)
- `pwa/src/app/backoffice/banks/_lib/bankRecency.ts` (eliminado countRecentlyCreated; isRecentlyCreated queda)
- `pwa/tests/app/backoffice/banks/banksSearchCriteria.test.ts` (nuevo)
- `pwa/tests/context/backoffice/bank/infrastructure/ApiBankRepository.test.ts` (reescrito)
- `pwa/tests/app/backoffice/banks/banksFilterSort.test.ts` (podado)
- `pwa/tests/app/backoffice/banks/bankRecency.test.ts` (podado)
- `pwa/tests/app/backoffice/banks/banksListSubtitle.test.tsx` (total desde totalCount)
- `pwa/tests/app/backoffice/banks/banksRealtimeTombstones.test.tsx` (contrato refetch + bulk window)
- `pwa/tests/app/backoffice/banks/banksListPeek.test.tsx` (mock refleja delete remoto)
- `pwa/tests/app/backoffice/banks/banksShiftRangeSelection.test.tsx` (mock refleja delete remoto)
- `pwa/tests/app/backoffice/banks/bankListDelete.test.tsx` (mock refleja delete remoto)
- `pwa/tests/app/backoffice/banks/paginate.test.ts` (ELIMINADO)
- `pwa/tests/e2e/fixtures/banks-api.ts` (emula server-driven: filtros/orden/paginación)
- `pwa/tests/e2e/backoffice/banks.spec.ts` (retirado el test del aviso "More banks available")
- `docs/architecture-pwa.md` (sección server-driven)
- `pwa/docs/server-driven-search.md` (nuevo: receta)

## Change Log

| Fecha      | Versión | Descripción                                                      | Autor       |
|------------|---------|-----------------------------------------------------------------|-------------|
| 2026-06-08 | 0.1     | Historia creada (create-story)                                  | Sergio      |
| 2026-06-08 | 1.0     | Implementación server-driven completa (cursor); lista para review | Amelia (dev) |
