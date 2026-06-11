---
baseline_commit: 8b1d72899ee4484b9b75273ff49ce60ca46d3f1c
---

# Story 1.4: Migración PWA, red Behat y observabilidad del switch (PR3, lado consumidor)

Status: in-progress

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **Emparejamiento de PR (AR16, nota de `sprint-status.yaml`):** Story 1.4 (lado consumidor: PWA + Behat + observabilidad) y **Story 1.3 (lado API: flip del wire + repos por composición)** se entregan en **el mismo worktree/PR (PR3)** — breaking change sincronizado API↔PWA del mismo ciclo. **Consecuencia operativa:** el flip del envelope en 1.3 deja en rojo los escenarios Behat page-based existentes; su migración al envelope nuevo es **el corazón de esta historia (AC #3)**. El gate de cierre de 1.3 es estático (`stan`/`psalm`/`quality`); **Behat verde + `pwa.quality`/Vitest verde es el gate del PR combinado 1.3+1.4.** No abrir un PR de 1.3 sin 1.4, ni al revés.
>
> **Orden de trabajo dentro del worktree:** 1.3 hace el flip del API primero (envelope v2 servido); 1.4 migra el consumidor (PWA), reescribe la red Behat al envelope nuevo y añade la observabilidad. La PWA no compila contra el envelope nuevo hasta que 1.3 lo emita — coordinar el orden de los commits dentro del PR (API antes que el smoke e2e del consumidor).

## Story

As a usuaria del backoffice de ERPify,
I want que las listas (bancos) naveguen con los enlaces `next`/`prev` del envelope nuevo, con cobertura e2e/Behat de simetría y métricas de cursor observables en producción,
so that el breaking change llegue sincronizado, verificable y observable en el mismo ciclo — sin capas de mapeo legacy ocultas.

## Acceptance Criteria

1. **Tipos compartidos + hard removal del modelo page-based en la PWA (AR15, FR1, FR6):** Given `pwa/src/context/shared/domain/Search/` (hoy solo aloja tipos de `Filter`, **no** existen tipos de paginación), When la historia se completa, Then existen `PageEnvelope` y `PaginationLinks` con `links: { next: string | null; prev: string | null }` — `string | null` **no opcional**, named exports (regla "no default exports bajo `src/context/**`"). And `currentPage`/`cursor`/`hasMorePages`/`pageCount` sufren **hard removal** de `BankRepository.ts` (puerto de dominio), `ApiBankRepository.ts` (adaptador) y de cualquier adapter/hook/boundary function que los referencie — se eliminan, no se adaptan (barrido explícito; ver Dev Notes "Barrido de símbolos page-based"). And no existe ningún helper tipo `getPageNumber(envelope)` (anti-example del ADR); **cero librerías nuevas**. And el control de paginación de las listas sustituye al paginador numerado por navegación `next`/`prev` reutilizando los patrones visuales existentes del toolbar de listas — **un enlace `null` se renderiza como control deshabilitado, nunca oculto** (ver Dev Notes "⚠️ Conflicto con `pwa/CLAUDE.md`"); cualquier rediseño visual mayor del control se difiere a la fase C del overhaul UI/UX del backoffice (sin spec UX en este alcance). And la compilación TS estricta y `make pwa.quality` + Vitest quedan verdes.

2. **Descartar cursores al cambiar la query + el cliente nunca decodifica ni reconstruye (AR15, AR20; W11 del execution contract):** Given un cambio de `sort`, `direction`, `filters` o `limit` en una lista, When el cliente reconstruye la query, Then descarta **ambos** cursores (`after` y `before`) — defensa en profundidad sobre el fingerprint; la UX no depende del 422. And el cliente usa `links` **tal cual** (los reenvía verbatim como navegación) y **jamás decodifica ni fabrica cursores**. And `buildSearchParams.ts` soporta `after`/`before` **mutuamente excluyentes** (nunca ambos en la misma request). **And — invariante W11 (single-composer binding del consumidor, sellado en `pr3-execution-contract.md` §3):** en **flujo de navegación normal** (next/prev) el cliente navega **exclusivamente** con los `links.next`/`links.prev` del envelope, verbatim; **NO reconstruye, deriva ni recompone** la URL/los params de navegación. `buildSearchParams` con `after`/`before` queda confinado a **fallback de primera-página / seam** (sin cursor en flujo normal), **nunca** al camino next/prev. Cualquier reconstrucción activa en flujo normal = violación de W9/W2 (segundo compositor silencioso), **aunque sea "defensiva"**. _Enforcement explícito (no solo prosa): Behat asserta el round-trip verbatim de `links.*` (Task 9); Vitest asserta que la navegación next/prev usa el link del envelope y que el path `after`/`before` de `buildSearchParams` solo se ejercita en su rol de primera-página/seam (Task 7)._

3. **Red Behat migrada al envelope nuevo + escenarios de simetría/422/vacío (AR13, FR14):** Given un dataset con empates masivos en la clave de ordenación, When corre el escenario Behat de simetría (extendiendo `api/features/backoffice/bank/search.feature`, **no** una feature paralela), Then `next`×3 seguido de `prev`×3 devuelve ids idénticos en orden inverso exacto. And los escenarios existentes (**52 bloques a 2026-06-10**: 47 Scenario + 5 Scenario Outline — la cifra "29" del ADR quedó desactualizada; el esfuerzo real es mayor) quedan actualizados al envelope nuevo y verdes dentro de este PR — **si Behat pasa aquí, PR4 es puramente sustractivo**. And hay escenario de **422 `invalid-cursor`** y de **página vacía → 200** (incluida `before` vacía con `hasPrev=true`, espejo del fix #3 de Story 1.3).

4. **Observabilidad del switch — métricas, dashboards, runbook (AR14, AR18):** Given el deploy del switch, When se publican las métricas, Then existen `invalid_cursor_count{cause}` con `cause ∈ {signature, version, payload, fingerprint}`, `cursor_version_distribution`, `next_navigation_count` y `prev_navigation_count`. And los dashboards se actualizan en el mismo PR y el runbook documenta: **pico de `invalid_cursor_count{cause=version|fingerprint}` post-deploy = bug de encoding o bump esperado — verificar el bump, no rotar secretos**. And los docs PWA obligatorios (`docs/architecture-pwa.md`, `pwa/docs/`) se actualizan. _(Realidad del stack — ver Dev Notes "⚠️ El stack de métricas no existe": no hay Prometheus/StatsD/OTel; las métricas se materializan como **campos de log estructurado** sobre Monolog + Sentry, y "dashboard"/"runbook" se materializan como doc nuevo. Decisión de estrategia pendiente de confirmación — ver Preguntas.)_

## Tasks / Subtasks

- [ ] **Task 0: Worktree compartido con Story 1.3 + baseline + smoke previo (AC: todas)**
  - [ ] **Trabajar en el worktree de PR3 compartido con 1.3** — el mismo `feat/api-keyset-pagination-<suffix>`. Si 1.3 ya lo creó, `cd` a él; si no, `make worktree.create BRANCH=feat/api-keyset-pagination` y `make app.dev` (regla dura: jamás trabajar en `main`, que es superficie viva compartida). **No crear un segundo worktree** — PR3 es un único PR API+PWA.
  - [ ] Confirmar la base PR2 (`8b1d728`): engine + kernel `…/Search/Keyset/` presentes y verdes (`make php.unit c='--filter Keyset'`).
  - [ ] `make php.behat` ANTES de tocar Behat (baseline page-based verde) — capturar el set de 52 bloques verdes como referencia. Tras el flip de 1.3, los escenarios page-based caen; su migración es esta historia.
  - [ ] Releer, sin implementar de memoria (tabla "Código existente que DEBES leer"): los 4 ficheros PWA del flip (`BankRepository.ts`, `ApiBankRepository.ts`, `page.tsx`, `BanksPagination.tsx`), `buildSearchParams.ts`, `search.feature`, los contexts Behat, `ExceptionResponder.php`, `InvalidCursorCause.php` y el `Telemetry` port de la PWA.

- [x] **Task 1: Tipos compartidos `PageEnvelope` + `PaginationLinks` (AC: #1)**
  - [x] Crear `pwa/src/context/shared/domain/Search/PaginationLinks.ts`: `export interface PaginationLinks { next: string | null; prev: string | null }` (`string | null` **no opcional** — shape constante espejo de FR6 del API; docblock fija la regla W9/W11 "navegar verbatim, nunca reconstruir").
  - [x] Crear `pwa/src/context/shared/domain/Search/PageEnvelope.ts`: `export interface PageEnvelope { hasNext: boolean; hasPrev: boolean; count: number | null; links: PaginationLinks }`. Named exports; sin import de framework. Re-exportados desde `…/Search/index.ts`.
  - [x] **OQ-3 resuelto: item-agnóstico, sin genérico** — los items viajan por separado en el page type de cada contexto (`BankSearchPage = { banks } & PageEnvelope`); no `PageEnvelope<T>` especulativo (YAGNI). `make pwa.quality` EXIT=0.

- [x] **Task 2: Puerto de dominio `BankRepository.ts` — Modelo A (W11-PORT): criteria sin cursor + `searchFromLink` (AC: #1, #2)**
  - [ ] `BankSearchCriteria`: **eliminar** `page: number` y `cursor?: string`. **NO añadir** `after`/`before` (Modelo A, W11-PORT — el seam "ir a fecha" está diferido → YAGNI; añadirlos recrea la superficie de "segundo compositor silencioso"). Conservar `filters`, `sort`, `limit`. Queda solo como criteria de **primera página / cambio de query**.
  - [ ] **Añadir al puerto `searchFromLink(link: string): Promise<BankSearchPage>`** — continúa una búsqueda vía un **token de continuación opaco** del servidor (`PageEnvelope.links.next/.prev`). El dominio trata `link` como opaco (no sabe que es URL — espejo del cursor-opaco del `Page` del API). Direction-agnostic (el link codifica `after`/`before`). El nombre es `searchFromLink`, **no** `navigate` (verbo de UI; vive en el componente). Docblock: "continuación verbatim; el cliente nunca decodifica/reconstruye (W9/W11)".
  - [ ] `BankSearchPage`: **eliminar** `cursor: string`, `currentPage: number`, `hasMorePages: boolean`. Componer con el envelope nuevo: `{ banks: Bank[] } & PageEnvelope` (OQ-3 resuelto: item-agnóstico). Actualizar el docblock (hoy describe `cursor`/`hasMorePages`).

- [x] **Task 3: Adaptador `ApiBankRepository.ts` — `search()` sin cursor + `searchFromLink()` verbatim + envelope v2 (AC: #1, #2)**
  - [ ] `BankSearchResponse` (interface interna, L13–22): reescribir `pagination` a `{ hasNext: boolean; hasPrev: boolean; count: number | null; links: { next: string | null; prev: string | null } }`. Eliminar `currentPage`/`pageCount`/`hasMorePages`/`cursor`. Reutilizar los tipos `PageEnvelope`/`PaginationLinks` del shared domain.
  - [ ] `isBankSearchResponse` (guard, L47–61): reescribir las comprobaciones de shape a las claves nuevas (`hasNext`/`hasPrev` boolean, `count` number|null, `links` objeto con `next`/`prev` string|null). El guard es la frontera de confianza del adaptador — debe **rechazar el envelope viejo**. Ambos paths (`search`/`searchFromLink`) usan el MISMO guard.
  - [ ] `search()` (L71–98): construir params **sin** `page`/`cursor`/`after`/`before` — solo `filters[]` (`buildSearchParams`) + `sort`/`direction`/`limit`. **Eliminar** `params.append("page", …)` (L78) y `params.append("cursor", …)` (L80). Mapear la respuesta al `BankSearchPage` nuevo (`{ banks } & PageEnvelope`).
  - [ ] **Añadir `searchFromLink(link: string): Promise<BankSearchPage>`** — el ÚNICO sitio que sabe que `link` es una URL relativa same-origin: validar con `safeHref` + chequeo relativo/mismo-origen (rechazar absoluto/host externo/esquemas peligrosos), luego `httpClient.get(link, isBankSearchResponse)` **verbatim** y mapear al `BankSearchPage`. **Cero** construcción de params, **cero** parsing de `after`/`before`, **cero** reapendizado de `limit`/`sort`/`filters[]` (W9/W11/W2: el link ya los lleva, compuestos por `SearchResponder`). Es la realización estructural de W11 — un cursor solo entra a una request por aquí, vía el link del servidor.

- [x] **Task 4: `buildSearchParams.ts` filters-only (sin cambio de contrato) + invariante `MAX_LIMIT` (AC: #2; D-Cap)**
  - [ ] **Modelo A: `buildSearchParams` queda FILTERS-ONLY — NO se extiende con `after`/`before`.** En flujo normal el cursor nunca se serializa en el cliente (entra solo vía `searchFromLink`, Task 3). Esto es la consecuencia directa de W11-PORT: no hay reconstrucción de params de navegación en el cliente. (Si el seam "ir a fecha" se reactiva en el futuro, será el **servidor** quien sintetice el cursor y devuelva el `link`; el cliente seguiría navegando verbatim — `buildSearchParams` no necesita `after`/`before` ni entonces.)
  - [ ] **Invariante de `MAX_LIMIT` (D-Cap):** crear una **única fuente de verdad** en la PWA (constante compartida espejo de `WirePaginationPolicy.MAX_LIMIT = 100`, p.ej. en `shared/domain/Search/` o `_lib/paginate.ts`). La construcción del `limit` (en `ApiBankRepository.search`) **clampa o asserta** contra esa constante — la UI no puede fabricar `limit > 100` ni por el selector ni por un override. Enforcement hard de cliente, complementario al 422 del backend. El selector (Task 6) deriva sus opciones de la misma constante.
  - [ ] `limit`/`sort`/`direction` siguen apendizados por `ApiBankRepository.search` (patrón actual; KISS) — no se centralizan en `buildSearchParams`.

- [x] **Task 5: Estado de la lista `page.tsx` — navegación direccional + descarte de cursores (AC: #1, #2)**
  - [ ] `pwa/src/app/backoffice/banks/page.tsx`: **eliminar** los estados numerados `currentPage` (L96), `page` (L101), `hasMorePages` (L97) y el `cursorRef` numerado (L130). Sustituir por estado direccional: el envelope de la última respuesta (`hasNext`/`hasPrev`/`links`) + el cursor activo (`after`/`before` o `null` para la primera página).
  - [ ] Reescribir `loadBanks` (L150–201) en dos caminos (Modelo A): **primera página / cambio de query** → `repo.search(criteria)` con `BankSearchCriteria` **sin cursor** (solo filters/sort/limit); **navegación next/prev** → `repo.searchFromLink(envelope.links.next!|prev!)`. Capturar el envelope (`hasNext`/`hasPrev`/`links`/`count`) en estado, no `currentPage`/`hasMorePages`. El estado guarda el **último envelope** (de donde salen los `links`), nunca un cursor crudo ni un número de página.
  - [ ] **Descarte de ambos cursores al cambiar `sort`/`direction`/`filters`/`limit`** (AC #2): la lógica de reset actual (L221–233 — hoy `setPage(1)`) pasa a limpiar `after`/`before` a `null` (vuelta a la primera página sin cursor). Defensa en profundidad sobre el fingerprint: aunque el API devolvería 422 con un cursor obsoleto, el cliente no debe llegar a enviarlo.
  - [ ] **Eliminar el efecto de fallback numerado** (L241–246, `setPage(current - 1)` cuando la página queda vacía): ya no hay número de página al que retroceder. El caso "página vacía" lo gobierna ahora el envelope (`hasPrev`/`hasNext` + `items: []` → control habilitado/deshabilitado coherente). Verificar que navegar a una página vacía deja `prev` accionable (espejo del fix #3 de 1.3: `before` vacía → `hasPrev=true`).
  - [ ] Las acciones `onNext`/`onPrev` llaman `repo.searchFromLink(envelope.links.next!)` / `searchFromLink(envelope.links.prev!)` respectivamente; un `link === null` deshabilita el control (no navega). El verbo de UI (`onNext`/`onPrev`) vive aquí; el puerto solo conoce `searchFromLink` (W11-PORT).

- [x] **Task 6: Control `BanksPagination.tsx` — numerado → direccional, link `null` = deshabilitado (AC: #1)**
  - [ ] `pwa/src/app/backoffice/banks/_components/BanksPagination.tsx`: props nuevas — `hasPrev`/`hasNext` derivados del envelope (no `page > 1`/`hasMorePages`), `onPrev`/`onNext` (en vez de `onPageChange(n)`). **Eliminar** el indicador numerado `Page {page}` (L65–71) — ya no hay número de página. Conservar el selector de page-size (`banks-pagination__page-size`).
  - [ ] **Recortar el selector a `≤ MAX_LIMIT` (D-Cap):** `pwa/src/app/backoffice/banks/_lib/paginate.ts` hoy ofrece `BANKS_PAGE_SIZE_OPTIONS=[25,50,100,500,1000]`. Recortar a `[25, 50, 100]` (500/1000 darían 422 con el techo nuevo) y **derivar/validar las opciones contra la constante única de `MAX_LIMIT`** (Task 4) — no hardcodear un techo divergente. Default 25 ya coincide con el wire.
  - [ ] **Link `null` → control deshabilitado, NUNCA oculto** (AR15): cambiar el render condicional actual (`{hasPrev ? <Button> : null}`) por `<Button disabled={!hasPrev} …>` / `<Button disabled={!hasNext} …>`. Reutilizar el styling del toolbar: `buttonVariants({ variant: "outline", size: "sm" })` + el patrón `disabled:pointer-events-none disabled:opacity-50` ya presente en `button-variants.ts`. Conservar los `data-testid` BEM (`banks-pagination__prev`/`__next`).
  - [ ] **A11y del control deshabilitado** (mitiga el conflicto con `pwa/CLAUDE.md`, ver Dev Notes): el botón deshabilitado lleva `aria-label`/`title` estáticos y `aria-disabled` coherente; los iconos decorativos `aria-hidden="true"`. Un par prev/next persistente y deshabilitado es un patrón discoverable y predecible — documentarlo como la excepción explícita a la regla a11y general de `pwa/CLAUDE.md`.

- [x] **Task 7: Tests Vitest de la PWA (AC: #1, #2)**
  - [ ] `pwa/tests/context/backoffice/bank/infrastructure/ApiBankRepository.test.ts`: reescribir el mock del envelope (hoy L17–23 `currentPage`/`pageCount`/`hasMorePages`/`cursor`) al envelope v2; los tests de serialización (`page`/`cursor`/`limit` → `after`/`before`/`limit`) y de mapeo (`currentPage`/`hasMorePages` → `hasNext`/`hasPrev`/`links`); el test del guard (`isBankSearchResponse`) al shape nuevo + un caso que **rechace** el envelope viejo.
  - [ ] `pwa/tests/app/backoffice/banks/_fixtures.ts`: `searchPage()` factory (L9–17) al envelope nuevo.
  - [ ] Test del control `BanksPagination` (si existe, o añadir): aserta que con `link === null` el botón está **deshabilitado** (no ausente) — `getByRole('button', { name: … })` + `toBeDisabled()`. Es el test que sella el AC #1 disabled-not-hidden.
  - [ ] Test de la lista: cambiar `sort`/`filters`/`limit` descarta ambos cursores (la siguiente request sale sin `after`/`before`).

- [x] **Task 8: e2e Playwright de la PWA (AC: #1) — autoría completa; ejecución CI DIFERIDA (Sergio "Después")**
  - [x] `pwa/tests/e2e/fixtures/banks-api.ts`: mock reescrito a cursor-only — cursor opaco `after`/`before` (offset base64url, decodificado solo por el mock como `CursorCodec`) → slice; envelope `{hasNext, hasPrev, count: null (LIGHT), links: {next, prev}}`; `links` relativos mismo-endpoint que preservan `limit`/`sort`/`direction`/`filters[]` (los reenvía el navigator verbatim, W9/W11); `list_next_cursor`/`currentPage`/`pageCount`/`hasMorePages`/`cursor` legacy eliminados. Determinismo conservado.
  - [x] `pwa/tests/e2e/backoffice/banks.spec.ts` (bloque `describe("pagination")`, 9 casos): "Page {N}"/`toBeHidden()` → `toBeDisabled()`/`toBeEnabled()` sobre prev/next persistentes (D-A11y); navegación `next`/`prev`; reset de cursores al cambiar filtro/sort/size; tests de page-size conservados. **Además (scope ampliado, decisión de Sergio "migrate all 3 specs"):** `banks-real-api.spec.ts` (SEED_COUNT 26) y `banks-real-api-flows.spec.ts` (SEED_COUNT 30) migrados igual — referenciaban el `__indicator` eliminado + `toBeHidden` y habrían roto la CE diferida.
  - [ ] **Ejecución CI — DIFERIDA (Sergio):** la suite e2e **no corre en local** (sin browsers Playwright para ubuntu26.04 — `pwa-e2e-local-ownership-blocker`). Verificada estáticamente: `make pwa.quality` (ESLint+Prettier+`tsc --noEmit`) **EXIT=0**. La ejecución en CI se difiere a cuando el sistema esté estable en staging (evitar CI flaky sobre performance no validada). **No declarado "verde" sin CI.**

- [x] **Task 9: Red Behat — migrar los 52 bloques al envelope nuevo (AC: #3)** — `make php.behat` verde: 117 escenarios / 819 steps. **Gate R3 del PR combinado 1.3+1.4 satisfecho.**
  - [x] `api/features/backoffice/bank/search.feature`: migrado al envelope `{hasNext,hasPrev,count,links:{next,prev}}` (4 claves). **Tres clases de fallo del flip resueltas:** (1) drift de query-count del engine keyset (`2→1` query en páginas no vacías; vacías siguen en 1) — actualizado en search.feature **y** en `query_stats.feature` (2 escenarios) y `delete.feature` (`4→3`); (2) default limit `ilimitado→25` → los escenarios que afirmaban "31 elements" sin `limit` pasan a `limit=100`; (3) `page=N` ya **no** es 422 (param retirado, ignorado como `names[]`/`ids[]`) → outline "Invalid page" eliminado y `page=2` plegado en el escenario de params ignorados; `cursor=` retirado → escenario "cursor silently empty" eliminado (su reemplazo es el 422 `invalid-cursor`). Modo `light`/`detailed` reescritos a navegación por links (`count` poblado solo en detailed). `limit=1001→101` (pin exacto de `MAX_LIMIT=100`).
  - [x] **Escenario de simetría** (AC #3, AR13): usa el dataset de fixtures tal cual — los 31 bancos comparten `createdAt` (instante de carga) → orden por defecto = `createdAt` con **empates masivos** resueltos por el tiebreak `id` ASC (ids 01..31 contiguos). `next`×3 → `prev`×3 retraza la ruta exacta (data[0].id 001→006→011→016→011→006→001). **No** se reutilizó el step `{value}` (el cursor ya no se expone como nodo escalar; vive dentro de `links.next`); ver step nuevo abajo.
  - [x] **Escenario 422 `invalid-cursor`** (AC #3): `?after=not-a-real-cursor` → 422 `type: invalid-cursor`, `0` queries (espejo de `BankSearchCursorFunctionalTest::testGarbageCursorIsRejectedAsInvalidCursor`). Añadido también W6: `after`+`before` ambos → 422 `validation-failed`.
  - [x] **Escenario página vacía → 200** (AC #3): hueco lógico vía DELETE de los bancos 01–10 bajo el cursor `before` de la página 2 → `200`, `data: []`. **CORRECCIÓN W7:** la prosa de AC#3/execution-contract "`before` vacía → `hasPrev=true`" es el **mislabel** que 1.3 ya documentó (story 1.3 líneas 77/289/291 + `docs`): el comportamiento sellado (Opción A, forward-recoverable only) es **`hasNext=true, hasPrev=false`**, `links.next` presente (recovery cursor, W10), `links.prev=null`. Behat fijado a ese contrato real (espejo de `testBeforeIntoADeletedGapIsForwardRecoverable`). El escenario destructivo se auto-restaura con `Given I reload the fixtures` al final (raw-SQL no dispara el dirty-tracker onFlush y las fixtures sólo recargan entre features).
  - [x] **Step Behat nuevo** (la nota "no hacen falta steps nuevos" del plan era incorrecta): `I follow the :node link from the previous response` en `HttpRequestContext` — navegación **verbatim** del `links.next`/`links.prev` (W11), sin reaplicar `baseUrl` ni reconstruir el cursor. Traversal de nodo anidado extraído a un helper privado compartido con el step `{value}` existente. Los asserts de envelope usan los steps genéricos de `JsonNodeContext` (`should be null`/`should not be null`/`should be true/false`/nodos anidados `pagination.links.next`).
  - [x] Fixtures `api/tests/DataFixtures/Fixtures/Bank.yaml` (31 bancos): sin cambios — el perfil de empates necesario (todos los bancos comparten `createdAt`/`updatedAt` por carga en el mismo instante) ya existe. La simetría y la página vacía corren contra Postgres real (round-trip verificado: filas frontera ni saltadas ni duplicadas).

- [x] **Task 10: Observabilidad — métricas como campos de log + cursor cause (AC: #4)** — `SearchObservabilityListener` (canal Monolog dedicado `observability`, always-on, JSON), 12 unit verdes + verificado e2e en Behat (54 `keyset_search` + `invalid_cursor cause=payload`). **DOS DESVIACIONES del plan sellado (decisiones de diseño, confirmadas con la guía de Sergio "no tocar engine/responder, añadir capacidad operativa"):** (1) **NO se extendió `ExceptionResponder::buildLogContext`** (la prescripción del plan); en su lugar un listener dedicado decoupled emite todos los eventos en un **canal always-on** — evita tocar el pipeline de error sensible + churn NFR26, y **sobrevive al `fingers_crossed` de prod** (que descarta las líneas no-error en `app`). El RFC9457 per-error line queda intacto → sin cambio de api-error-contract. (2) **El evento `cursor_version` (distribución del `v` decodificado por request) se OMITE** — exponer el `v` exigiría tocar el engine congelado y la lista de campos refinada por Sergio no lo pide; el diagnóstico de versión se hace vía `invalid_cursor{cursor_cause=version}` (la señal accionable) + FR15, documentado en el runbook.
  - [ ] **`invalid_cursor_count{cause}`:** extender `ExceptionResponder::buildLogContext` (`api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php`, L312–329) para añadir el campo `cursor_cause` cuando `$throwable instanceof InvalidCursor`, leyendo su propiedad pública `cause` (`InvalidCursorCause`: `signature|version|payload|fingerprint`). **Coordinación con 1.3:** 1.3 ya añade la fila `invalid-cursor` a `docs/api-error-contract.md` (su AC #4); **cambiar el per-error log line es mandatorio documentarlo ahí también (NFR26)** — un solo cambio coordinado en el mismo PR, sin doble edición conflictiva del fichero.
  - [ ] **`cursor_version_distribution`:** emitir el `v` del cursor decodificado en cada request portadora de cursor (campo de log estructurado en el flujo de búsqueda — no en `ExceptionResponder`, que solo ve errores). Decidir el punto de emisión (OQ-5: responder/handler/un listener de éxito).
  - [ ] **`next_navigation_count` / `prev_navigation_count`:** API-side (patrón del repo: observabilidad por log estructurado, no telemetría client happy-path). Emitir una línea de log con la dirección cuando la request llega con `after`/`before`. **Alternativa**: instrumentar el `Telemetry` port de la PWA (`apiScope('banks-pagination-next'/'-prev')`) — más ruidoso, redundante. **Recomendado: API-side**, confirmar (OQ-6).
  - [ ] **Sin stack de métricas nativo:** materializar las 4 métricas como **campos/líneas de log estructurado** queryables (Monolog JSON en prod) — ver Dev Notes "⚠️ El stack de métricas no existe". No introducir Prometheus/StatsD/OTel en este PR (sería una integración aparte, fuera de alcance).
  - [ ] **Schema de eventos de observabilidad (D-Obs — vinculante, NO logs libres):** cada métrica se emite como un evento de log con **shape fijo**, no como texto libre. Contrato mínimo:
    - `invalid_cursor`: `{ event: "invalid_cursor", cause: "signature|version|payload|fingerprint", route, cursor_v?: int }` — `cause` desde `InvalidCursor::$cause`; **jamás el cursor crudo** (NFR1). Emitido vía el `cursor_cause` añadido a `ExceptionResponder::buildLogContext`.
    - `cursor_version`: `{ event: "cursor_version", v: int, route }` — distribución de `v` por request portadora de cursor.
    - `keyset_navigation`: `{ event: "keyset_navigation", direction: "next|prev", route }` — alimenta `next/prev_navigation_count`.
    Usar una clave `event` estable como discriminador (habilita agregación por parsing sin re-instrumentar) y un canal Monolog dedicado o `app` con `event` presente. Documentar el schema en el runbook (Task 11) — es el contrato que los dashboards futuros parsean.

- [x] **Task 11: Dashboard + runbook (AC: #4, AR18)** — runbook nuevo `docs/runbooks/cursor-pagination.md` (ubicación confirmada por Sergio) cubriendo: envelope cursor-only + invariantes **W2/W9/W10/W11**, página vacía W7 (`before` vacía → `hasNext=true,hasPrev=false`), `limit` 25/100, diagnóstico de las 4 causas de `invalid_cursor` + **pico post-deploy `cause=version`/`fingerprint` = bump FR15 esperado → verificar el bump, NO rotar secretos**, identificación de la válvula legacy (`?pagination_mode=legacy`, fail-closed a cursor en prod), rollback PR3→PR2 (revert del merge, sin migraciones), y garantías/no-garantías FR14. "Dashboards" = queries `jq` documentadas (sin Grafana). `pwa/CLAUDE.md` excepción a11y ya estaba (Task 6). **Docs actualizados:** `docs/architecture-pwa.md` y `pwa/docs/server-driven-search.md` reescritos del modelo page-based STALE al consumidor cursor-only (puerto link-free + `BankSearchNavigator.follow` verbatim, descarte W8, `WIRE_MAX_LIMIT`); `docs/architecture-api.md` + sección de observabilidad (canal dedicado, listener, prioridad > ExceptionResponder).
  - [ ] **Runbook nuevo** (no existe convención — esta historia la establece): crear `docs/runbooks/cursor-pagination.md` (o ubicación que Sergio confirme) documentando: las 4 métricas y cómo consultarlas (queries de log / vistas Sentry); la interpretación del **pico post-deploy** de `invalid_cursor_count{cause=version|fingerprint}` = bug de encoding o **bump esperado de `v`** → **verificar el bump, no rotar secretos**; FR15 (bump explícito por release) como causa legítima del pico.
  - [ ] **"Dashboards":** como no hay Grafana/Datadog, materializar como las queries/vistas documentadas en el runbook (+ Sentry). Si Sergio quiere una superficie de dashboard real, es integración aparte. Documentar la decisión.
  - [ ] Actualizar `docs/architecture-pwa.md` (envelope cursor-only en el consumidor, navegación direccional, descarte de cursores) y `pwa/docs/` (contrato del envelope que consume `ApiBankRepository`, regla "el cliente nunca decodifica cursores"). Añadir a `pwa/CLAUDE.md` la **excepción explícita** a la regla a11y hide-vs-disabled (ver Dev Notes).

- [x] **Task 12: Gates, revertibilidad y cierre (AC: todas) — VERDE (e2e CI diferida)**
  - [x] `make pwa.quality` (ESLint + Prettier + `tsc --noEmit`) **EXIT=0** + `make pwa.test.unit` (Vitest) **541/541 verdes**.
  - [x] `make php.behat` **117 esc./819 steps verdes** con la red migrada — **gate del PR combinado 1.3+1.4 satisfecho**. (e2e Playwright en CI — diferida, Task 8.)
  - [x] **Revertibilidad (AR16):** confirmado — los cambios de 1.4 son PWA (consumidor) + red Behat + listener de observabilidad (`SearchObservabilityListener` desacoplado) + monolog `observability`; ninguno reescribe el kernel/engine de PR1/PR2 (`…/Search/Keyset/`, `DoctrineSearchEngine`). Revertir el merge de PR3 restaura el consumidor page-based.
  - [x] **Self-review de seguridad (checklist frontend) — PASS:** sin `dangerouslySetInnerHTML`/`innerHTML`/`eval` en `pwa/src`; cero `localStorage`/`sessionStorage` para cursores; `ApiBankSearchNavigator.follow()` reenvía el `link` verbatim pero tras guarda `safeHref` + leading-`/` + rechazo de `//` + check de origen contra sentinel (open-redirect cerrado); `page.tsx` usa `safeHref` en hrefs dinámicos; `next.config.ts#headers()`/CSP intactos (fuera del diff).

## Dev Notes

### Contexto arquitectónico imprescindible

- **Fuente única de requisitos:** ADR `_bmad-output/planning-artifacts/architecture-keyset-pagination.md` (status: IMPLEMENTATION LOCKED) + `epics.md` (Story 1.4) + `1-3-…-pr3-lado-api.md` (la otra mitad del PR). Ante duda, el ADR manda; ante conflicto con CLAUDE.md/docs/rules, **señalar el conflicto, no elegir** (ver el conflicto a11y abajo).
- **Secuencia vinculante AR16:** PR1 (kernel puro, en `main`) → PR2 (engine off-wire, en `main` `8b1d728`) → **PR3 (Story 1.3 API + esta historia: el ÚNICO flip observable)** → PR4 (borrado del legado). 1.3 y 1.4 son **el mismo PR**.
- **Qué entrega 1.4 (lado consumidor):** la PWA deja de hablar page-based y pasa a navegación `next`/`prev` con cursores opacos que **reenvía verbatim**; la red Behat se reescribe al envelope nuevo (si pasa, PR4 es sustractivo); y el switch se hace **observable** (métricas de cursor + runbook). Es el espejo consumidor del flip de API de 1.3.

### ⚠️ Conflicto con `pwa/CLAUDE.md` (a11y: hide vs disabled) — DECISIÓN A CONFIRMAR

**Conflicto directo detectado.** `pwa/CLAUDE.md:411–413` dice textualmente:

> "For pagination/navigation controls that have no valid target (no previous or no next page), **hide** the control instead of rendering it disabled — a disabled control is still discovered by assistive tech and adds noise."

Pero el ADR/epics (AR15, AC #1 de esta historia) dice:

> "un enlace `null` se renderiza como **control deshabilitado, nunca oculto**".

**Y el código actual** (`BanksPagination.tsx`) hoy **oculta** (render condicional `{hasPrev ? <Button> : null}`), alineado con `pwa/CLAUDE.md`, no con el ADR.

**Resolución propuesta (default, baked-in en las tasks):** seguir el **ADR**, porque es la fuente IMPLEMENTATION LOCKED más específica y reciente y gobierna este alcance; mitigar la preocupación a11y de `pwa/CLAUDE.md` con un control deshabilitado **bien etiquetado** (`aria-label`/`title` estáticos, `aria-disabled`, iconos `aria-hidden`) — un par prev/next persistente y predecible es discoverable, no ruido. Como parte de Task 11, **añadir la excepción explícita a `pwa/CLAUDE.md`** para que la regla general y este alcance dejen de contradecirse. **Confirmar con Sergio** (ver Preguntas) por si prefiere mantener hide y pedir una corrección del AR15 — no re-litigar el ADR sin su decisión.

### ⚠️ El stack de métricas no existe — las "métricas/dashboards" se materializan sobre logging + Sentry

Verificado en el repo: **no hay Prometheus, StatsD ni OpenTelemetry.** La observabilidad es:

- **API:** Monolog PSR-3 (JSON a stderr en prod), canales `app|deprecation|messenger|mercure|audit|media`; per-error log line de 9 campos canónicos emitido por `ExceptionResponder::buildLogContext` (`instance, correlation_id, type, status, exception_class, exception_category, exception_message, request_uri, request_method`). + **Sentry** (`config/packages/sentry.yaml`, `before_send` descarta 4xx vía marker `ClientError` — `InvalidSearchCriteria` entre ellos, así que **`InvalidCursor` NO llega a Sentry**: su observabilidad es por log).
- **PWA:** un `Telemetry` port (`pwa/src/context/shared/domain/Observability/Telemetry.ts`) con adapters Console/Sentry/Throttled — scope para errores/diagnóstico, **no** métricas happy-path.
- **No existen** dashboards (Grafana/Datadog) ni runbooks en `docs/`.

**Consecuencia para AC #4:** las 4 métricas de AR14 (`invalid_cursor_count{cause}`, `cursor_version_distribution`, `next/prev_navigation_count`) se materializan como **campos/líneas de log estructurado** queryables, no como métricas de un sistema de métricas. `invalid_cursor_count{cause}` engancha en `ExceptionResponder::buildLogContext` (añadir `cursor_cause` desde `InvalidCursor::$cause`). "Dashboard"/"runbook" → **doc nuevo** (`docs/runbooks/cursor-pagination.md`) con las queries + la interpretación del pico post-deploy. Esto **establece una convención nueva**, no extiende una existente. La estrategia exacta (solo-log vs Sentry custom measurements vs preparar una capa Prometheus futura) está pendiente de confirmación — ver Preguntas.

### Estado actual de la paginación en la PWA (verificado — leer antes de codear)

**Hallazgo clave:** los tipos de paginación **NO viven** en `pwa/src/context/shared/domain/Search/` (ahí solo hay tipos de `Filter`). Viven en el bounded context de Bank. Esta historia **crea** los tipos compartidos y migra el contexto de Bank a usarlos.

| Capa | Fichero | Símbolos hoy (page-based) | Acción |
|---|---|---|---|
| Dominio (shared) | `pwa/src/context/shared/domain/Search/{index,Filter}.ts` | solo `Filter*` — **no hay tipos de paginación** | **crear** `PageEnvelope.ts` + `PaginationLinks.ts` |
| Dominio (puerto Bank) | `pwa/src/context/backoffice/bank/domain/BankRepository.ts` | `BankSearchCriteria{page,cursor,limit}` (L25–30); `BankSearchPage{cursor,currentPage,hasMorePages}` (L38–43) | migrar a `after`/`before` + envelope |
| Infra (adaptador) | `pwa/src/context/backoffice/bank/infrastructure/ApiBankRepository.ts` | `BankSearchResponse.pagination{currentPage,pageCount,count,hasMorePages,cursor}` (L13–22); guard `isBankSearchResponse` (L47–61); `search()` apend `page`/`cursor`/`limit` (L78–82) + mapeo (L92–97) | reescribir shape, guard, params, mapeo |
| Infra (params) | `pwa/src/context/shared/infrastructure/Search/buildSearchParams.ts` | solo `filters[]` (L14–30); paginación la apend el caller | añadir `after`/`before` excluyentes |
| UI (estado) | `pwa/src/app/backoffice/banks/page.tsx` | `currentPage`(L96), `hasMorePages`(L97), `page`(L101), `cursorRef`(L130), criteria L166–168, captura L176–179, reset L221–233, fallback L241–246 | navegación direccional + descarte de cursores |
| UI (control) | `pwa/src/app/backoffice/banks/_components/BanksPagination.tsx` | props `page`/`hasPrev`/`hasNext`/`onPageChange` (L7–14); `Page {page}` (L65–71); render condicional prev/next (L49–87) | direccional; link `null` → `disabled`, no oculto |
| UI (page-size) | `pwa/src/app/backoffice/banks/_lib/paginate.ts` | `BANKS_PAGE_SIZE_OPTIONS=[25,50,100,500,1000]`, default 25 | **revisar:** el techo wire es 100 → opciones 500/1000 exceden el cap del API (ver OQ-7) |

**OQ-7 (page-size 500/1000 vs techo 100):** el API nuevo capa `limit` a **100** (422 si excede). Las opciones `500`/`1000` del selector de la PWA pasarían a producir 422. Hay que **recortar `BANKS_PAGE_SIZE_OPTIONS` a ≤ 100** (p.ej. `[25, 50, 100]`) o el selector romperá. Confirmar la lista final con Sergio (afecta UX). El default 25 ya coincide con el API.

### Barrido de símbolos page-based (grep — hard removal, AC #1)

Eliminar/migrar **toda** referencia (no adaptar). Loci confirmados por grep:

- `currentPage`: `BankRepository.ts:41`; `ApiBankRepository.ts:16,55,95`; `page.tsx:96,178,767,769`; tests `ApiBankRepository.test.ts:18,92`, `_fixtures.ts:13`, `banks-api.ts:270,277`.
- `pageCount`: `ApiBankRepository.ts:17,56`; tests `ApiBankRepository.test.ts:19`, `banks-api.ts:278`.
- `hasMorePages`: `BankRepository.ts:35,42`; `ApiBankRepository.ts:19,58,96`; `page.tsx:97,179,770`; tests `ApiBankRepository.test.ts:21,93,109`, `_fixtures.ts:14`, `banks-api.ts:280`.
- `cursor` (paginación): `BankRepository.ts:22,29,34,40`; `ApiBankRepository.ts:20,59,79-80,94`; `page.tsx:127-130,167,176`; tests varios.
- `page`(número): `BankRepository.ts:28`; `ApiBankRepository.ts:78`; `page.tsx:96,101,166-167`; tests.

**Cero `getPageNumber(envelope)` o equivalente** (anti-example del ADR). Grep limpio post-migración.

### Mapa Behat — escenarios a migrar (AC #3)

`api/features/backoffice/bank/search.feature` — **52 bloques** (47 Scenario + 5 Outline). **8 page-based a reescribir:**

| Líneas | Escenario | Cambio |
|---|---|---|
| 6–21 | List all banks | `currentPage`/`pageCount`/`hasMorePages` → `hasNext`/`hasPrev`/`count`/`links` |
| 40–48 | Invalid page returns 422 (Outline) | el param `page` muere → reconvertir a invalidez de cursor (`after`/`before`) o eliminar |
| 50–59 | Invalid limit returns 422 (Outline) | `limit=1001` → `limit=101` (cap 100 nuevo); `0`/`-1`/`abc` se conservan |
| 70–78 | Light: emits a cursor, skips pageCount | `currentPage=1,pageCount=null,hasMorePages=true` → `hasNext=true,hasPrev=false,links` |
| 80–89 | Light: follows the cursor | `page=2&cursor={value}` → `after={value}` |
| 91–100 | Detailed: follows the cursor | `page=2&cursor={value}` → `after={value}`; `currentPage`/`pageCount` → envelope+`count` |
| 102–109 | Detailed: total counts on full first page | `limit=1000` → `limit` ≤ 100 (o `count` con dataset menor); quitar `pageCount` |
| 111–118 | Detailed: COUNT when page doesn't fit | `currentPage`/`pageCount`/`hasMorePages` → envelope+`count` |

**Steps:** `api/tests/Behat/Context/HttpRequestContext.php` (envía params, substituye `{value}` desde la respuesta previa — L338–361) y `api/tests/Behat/Context/Json/JsonNodeContext.php` (asserts JSON node genéricos). **No** hacen falta steps nuevos; los genéricos cubren `pagination.links.next` (nodo anidado) y `null`. **Escenarios nuevos a autorar:** simetría next×3/prev×3 (empates masivos), 422 `invalid-cursor`, página vacía → 200 (incl. `before` vacía `hasPrev=true`) — no existen hoy.

**Fixtures:** `api/tests/DataFixtures/Fixtures/Bank.yaml` (31 bancos). El escenario de simetría necesita empates masivos en `createdAt`/`updatedAt` a precisión de segundo — **coordinar con 1.3** (comparte el perfil adversario del property test de PR2). **Watch micro-segundos:** `CursorPositionExtractor` hace floor a segundos; Postgres `TIMESTAMP(0)` redondea — el round-trip real contra Postgres en este Behat verifica que las filas frontera no se saltan/duplican en empates sub-segundo (deferred-work registrado).

### Observabilidad — puntos de enganche (verificado)

- **`invalid_cursor_count{cause}`:** `ExceptionResponder::buildLogContext` (`…/Http/EventListener/ExceptionResponder.php` L312–329). Añadir `cursor_cause` si `$throwable instanceof InvalidCursor` leyendo `InvalidCursorCause` (`…/Domain/Search/Exception/InvalidCursorCause.php`: `signature|version|payload|fingerprint`, lanzados en `CursorCodec.php`). Nivel: `WARNING` (4xx). **Nunca el cursor crudo en el log** (NFR1). **Coordinar con 1.3** (que documenta la fila `invalid-cursor` en `docs/api-error-contract.md`): cambiar el per-error log line es mandatorio documentarlo ahí (NFR26) — edición única del fichero en el PR.
- **`cursor_version_distribution`, `next/prev_navigation_count`:** son happy-path → NO pasan por `ExceptionResponder`. Necesitan un punto de emisión nuevo en el flujo de búsqueda (responder/handler/listener de éxito). Decidir el locus (OQ-5/OQ-6). Patrón del repo: log estructurado API-side, no telemetría client.
- **Sentry NO ve `InvalidCursor`** (lo descarta `before_send` por ser `ClientError`). Correcto: su observabilidad es por métrica/log, no por captura de excepción.

### Anti-patterns prohibidos (del ADR — el review los caza)

- ❌ El cliente decodifica/fabrica cursores (AR15/AR20) — solo reenvía `links` verbatim.
- ❌ `getPageNumber(envelope)` o cualquier helper que reintroduzca el número de página.
- ❌ Ocultar el control prev/next sin target — debe ir **deshabilitado** (AR15). _(Flagueado contra `pwa/CLAUDE.md` — ver arriba.)_
- ❌ Enviar `after` y `before` a la vez (AC #2 — excluyentes; 422 si ambos).
- ❌ Librerías nuevas (cero deps npm; AR15/NFR6).
- ❌ Default exports bajo `src/context/**` (named exports; `page.tsx` es la excepción Next).
- ❌ `skip_null_values` / opcionalidad en `links` — `next`/`prev` son `string | null` **no opcionales** (shape constante, espejo de FR6).
- ❌ Crear una feature Behat paralela — extender `search.feature` (AR13).
- ❌ Introducir Prometheus/StatsD/OTel en este PR (las métricas son log estructurado; el stack real es integración aparte).
- ❌ Meter cursores en `localStorage`/`sessionStorage`; `links` absolutos a host externo (open-redirect) — relativos mismo-origen, `safeHref` + `encodeURIComponent`.
- ❌ Romper la encapsulación de la revertibilidad de PR3 (espejo del AC #8 de 1.3).

### Gotchas operativos del repo (de sesiones previas — evitan ciclos de review)

- **e2e Playwright NO corre en local** (sin browsers para ubuntu26.04 — memoria `pwa-e2e-local-ownership-blocker`): unit + quality en local, e2e en CI; verificación manual con `playwright-cli` + canal Chrome del sistema si hace falta ver el control.
- **Turbopack sirve CSS stale en worktrees** — si los estilos del control nuevo no aparecen: limpiar `.next/*` + reiniciar `pwa` (memoria `worktree-stack-browsing-gotchas`).
- **`make app.dev` en el worktree reescribe `api/config/reference.php`** — nunca `git add -A` sin revisar; auto-generado (memoria `reference-php-regen-gotcha`).
- **FrankenPHP vuelca `core.N` (~1GB) en `api/`** durante test runs en contenedor — borrar, jamás commitear.
- **Lint/format siempre vía `make` desde la raíz** (contenedor dev) — nunca `npm`/`eslint` directos desde `pwa/`.
- **Commits:** Conventional Commits (`feat(pwa): …` / `feat(api): …` / `test(api): …` para Behat / `docs: …` para runbook); pre-commit activos; nunca `--no-verify`, nunca amend tras fallo de hook.
- **Protección de `main`:** nunca force-push ni merge sin permiso explícito por-merge de Sergio. PR3 (1.3+1.4) se prepara y se detiene; el merge lo decide Sergio.
- **`bankListDelete` test flakea ~40% bajo carga** (portal dropdown vs `findBy` 1s) — no bisectar el diff de PR3 por ese flake (memoria `bank-list-delete-test-load-flake`).

### Testing

- **Vitest (PWA):** `pwa/tests/context/backoffice/bank/infrastructure/ApiBankRepository.test.ts`, `pwa/tests/app/backoffice/banks/_fixtures.ts`, test del control (`disabled` no ausente), test de descarte de cursores. `make pwa.test.unit c='…'` durante desarrollo. Query por rol/label (no por CSS/testid cuando haya query accesible). Render real, sin shallow.
- **e2e (Playwright):** `pwa/tests/e2e/fixtures/banks-api.ts` (mock cursor-only), `pwa/tests/e2e/backoffice/banks.spec.ts` (`describe("pagination")`, 9 casos: `toBeHidden`→`toBeDisabled`). Ejecución en CI.
- **Behat (API):** `api/features/backoffice/bank/search.feature` — 8 page-based migrados + 3 nuevos (simetría/422/vacío). `make php.behat` es el **gate del PR combinado**.
- **Gates de cierre:** `make pwa.quality` + `make pwa.test.unit` + `make php.behat` verdes; e2e verde en CI. Si se tocó PHP (`ExceptionResponder`), `make php.stan` por archivo + `make php.quality` al cierre (incluye `php.lint.error-contract` — coordinar la fila `invalid-cursor`/log line con 1.3).

### Stack pineado (no renegociable; leer código existente antes que memoria)

Next.js 16.2 (App Router, Turbopack) · React 19.2 · TypeScript 6 (`strict: true`) · Tailwind 4.2 (CSS-first, sin `tailwind.config.js`) · Shadcn/Base UI · Inversify 8 · Vitest 4 · Playwright 1.59 · **API:** PHP 8.5 · Symfony 8.0 · PHPUnit 13 · Behat 3 (árbol aislado `api/tools/behat/`, jamás `composer require behat/*` en `api/composer.json`) · Monolog · Sentry. **Cero dependencias npm/Composer nuevas; cero migraciones nuevas.**

### Project Structure Notes

- Tipos compartidos de paginación → `pwa/src/context/shared/domain/Search/` (named exports, sin framework). El contexto de Bank los consume; no duplicar tipos en el bounded context.
- FQCNs/paths pineados (no inventar variantes): PWA `PageEnvelope`/`PaginationLinks` en `shared/domain/Search/`; API envelope v2 `…\Infrastructure\Http\Responder\PaginationMeta` (lo entrega 1.3), `InvalidCursorCause` en `…\Domain\Search\Exception\`.
- Behat extiende `api/features/backoffice/bank/search.feature` — no feature paralela (AR13).
- Runbook nuevo en `docs/runbooks/` (convención a establecer — confirmar ubicación).
- **Conflicto detectado y flagueado:** `pwa/CLAUDE.md:411–413` (hide) vs AR15 (disabled). Resolución propuesta: seguir el ADR + añadir excepción a `pwa/CLAUDE.md`. Pendiente de confirmación de Sergio.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 1.4] — acceptance criteria de origen; FR1/FR6/FR14, AR13/AR14/AR15/AR18
- [Source: _bmad-output/planning-artifacts/epics.md#FR Coverage Map] — FR6 (Stories 1.3–1.4), FR14 (docs+Behat 1.3–1.4)
- [Source: _bmad-output/planning-artifacts/architecture-keyset-pagination.md] — ADR IMPLEMENTATION LOCKED (AR13 tests pineados, AR14 observabilidad PR3, AR15 PWA PR3)
- [Source: _bmad-output/implementation-artifacts/1-3-…-pr3-lado-api.md] — la otra mitad del PR3 (envelope v2 servido, fix #3 `before` vacía, fila `invalid-cursor` en error-contract); emparejamiento 1.3+1.4
- [Source: pwa/src/context/backoffice/bank/domain/BankRepository.ts] — `BankSearchCriteria`/`BankSearchPage` page-based a migrar
- [Source: pwa/src/context/backoffice/bank/infrastructure/ApiBankRepository.ts] — adaptador: shape/guard/params/mapeo a reescribir
- [Source: pwa/src/context/shared/infrastructure/Search/buildSearchParams.ts] — params builder (filters-only hoy)
- [Source: pwa/src/app/backoffice/banks/page.tsx + _components/BanksPagination.tsx] — estado + control numerado a direccional
- [Source: pwa/src/context/shared/domain/Search/] — destino de `PageEnvelope`/`PaginationLinks` (hoy solo `Filter`)
- [Source: api/features/backoffice/bank/search.feature] — 52 bloques; 8 page-based; steps en `api/tests/Behat/Context/`
- [Source: api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php:312-329] — per-error log line; enganche de `cursor_cause`
- [Source: api/src/Shared/Domain/Search/Exception/InvalidCursorCause.php] — `signature|version|payload|fingerprint`
- [Source: api/config/packages/sentry.yaml + …/Sentry/SentryEventFilter.php] — `before_send` descarta `ClientError` (InvalidCursor no llega a Sentry)
- [Source: pwa/src/context/shared/domain/Observability/Telemetry.ts] — `Telemetry` port (si se opta por instrumentar nav counts client-side)
- [Source: pwa/CLAUDE.md:411-413] — regla a11y hide-vs-disabled (CONFLICTO con AR15)
- [Source: docs/project-context.md] — reglas PWA/TS/testing/seguridad/workflow del repo

## Decisiones confirmadas (Sergio, 2026-06-11) — selladas, no re-litigar

- **D-A11y (resuelve el conflicto `pwa/CLAUDE.md` ↔ AR15):** gana el **ADR** → el control prev/next se renderiza **deshabilitado + visible + `aria-disabled`**, nunca oculto. Razón de arquitectura: *"hide" elimina estado del sistema (malo para consistencia de UI y para el determinismo de Behat, que depende de la existencia DOM del control); "disable" preserva el contrato de estado.* `pwa/CLAUDE.md` es guía de implementación local; el ADR es intención de sistema. **Acción obligatoria de cierre:** patch explícito a `pwa/CLAUDE.md:411–413` registrando esta excepción (Task 6 + Task 11). Sin el patch, la regla general y este alcance quedan en contradicción permanente.
- **D-Obs (resuelve la estrategia de métricas):** la Opción A (**log estructurado JSON sobre Monolog**, cero infra nueva) es la **única coherente** con el estado del repo — OTel/Prometheus queda fuera del scope de PR3. **Condición vinculante:** no son logs libres, es un **contrato de eventos de log con schema fijo** (ver Task 10 "Schema de eventos de observabilidad"). Sin schema, "logs = basura no agregable". El schema es el que habilita dashboards futuros por parsing sin re-instrumentar.
- **W11-PORT (sellado 2026-06-11, Sergio — Modelo A2): navigator en app-layer, puerto de dominio link-free.** El puerto `BankRepository` deja de ser "pagination-aware input API" y pasa a "query executor"; la navegación verbatim vive **fuera del puerto de dominio**, en una capa application. **Clave (corrección sobre A):** el cliente **nunca parsea el link — ni el dominio ni el adaptador** (parsearlo para extraer cursor/filtros y reconstruir un command sería reconstrucción W2/W11; ese patrón es del **servidor**, no del cliente sin engine). Dos seams: **(1)** `BankRepository.search(criteria)` (dominio) **link-free** — `BankSearchCriteria` = filters/sort/limit, sin cursor — para primera página / cambio de query; **(2)** `BankSearchNavigator.follow(link)` (puerto en `application/`, impl `ApiBankSearchNavigator` en `infrastructure/`) — same-origin/relativo + `safeHref` + `httpClient.get(link)` **verbatim**, sin abrir el link. El `string` de transporte **jamás toca el puerto de dominio**. UI: primera página → `SearchBanks.run(criteria)`; next/prev → `BankSearchNavigator.follow(envelope.links.next!)`. `buildSearchParams` queda **filters-only**. **Razón:** dominio PWA 100% puro (sin transporte); cero parsing en cliente; W2/W9 intactos; W11 = garantía **estructural**. Único modelo consistente con envelope-freeze (links-only), W11 verbatim, cursor opaco, single composer y revertibilidad (AC#8). Sellado en `pr3-execution-contract.md` §3. Refina los Tasks 2/3/4/5 (que asumían `after`/`before` en criteria / `searchFromLink` en el puerto de dominio).
- **D-Cap (eleva OQ-7 de guard de UI a invariante de contrato):** **la UI jamás puede emitir un `limit` fuera de `WirePaginationPolicy.MAX_LIMIT` (100).** Enforcement **hard en ambos lados**, no solo guard de UI: (a) backend ya lo impone con el `#[Assert\LessThanOrEqual(100)]` del DTO → 422 `validation-failed` (Story 1.3); (b) UI recorta `BANKS_PAGE_SIZE_OPTIONS` a `[25, 50, 100]` **y** la construcción del `limit` se ancla a una **única fuente de verdad** del techo (constante compartida espejo de `MAX_LIMIT`), de modo que ni el selector ni un override de UI puedan fabricar `limit > 100`. Riesgo que cierra: desalineación entre wire policy (100), controles PWA y `WirePaginationPolicy` → bifurcación silenciosa del contrato. Ver Task 4 (invariante) y Task 6 (selector).

## Preguntas abiertas (no bloquean dev-story; resolver en el PR3 execution contract)

- **OQ-4 — Contrato link↔param → RESUELTO (Modelo C, W9 del `pr3-execution-contract.md`).** Es decisión de **ownership**, no de formato: el engine/`Page` son link-agnósticos (cursores opacos), `SearchResponder` es el **único compositor** de los `links` (URL relativa completa), el cliente solo los **consume verbatim** (no decodifica/reconstruye). Coordinación con 1.3 (Task 3, invariante de ownership añadido). Ya no es pregunta abierta.
- **OQ-5/OQ-6 — Locus de emisión** de `cursor_version_distribution` y `next/prev_navigation_count` (happy-path, no pasan por `ExceptionResponder`): responder/handler/listener de éxito, API-side. Default: API-side, log estructurado con el schema de D-Obs.
- **Runbook (ya NO opcional — es requisito de operabilidad de PR3):** ubicación propuesta `docs/runbooks/cursor-pagination.md` (convención nueva). Debe cubrir, como mínimo: cómo detectar `invalid_cursor`, cómo interpretar `invalid_cursor_count{cause}`, y cómo diferenciar **legacy fallback activo vs keyset path activo** post-deploy. Confirmar solo la ubicación.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (dev-story)

### Debug Log References

- **Task 0 — grounding (2026-06-11):** worktree compartido `api-keyset-pagination-8rho` (branch `feat/api-keyset-pagination-8rho`) ya creado por 1.3; stack up y healthy (5 contenedores `erpify-api-keyset-pagination-8rho-*`). Baseline Behat page-based ya capturada por 1.3 (Task 0: 116 esc./773 steps verdes; re-verificada en el revert empírico de 1.3 Task 13). Relectura load-bearing de los ficheros PWA del flip: `BankRepository.ts` (puerto page-based `BankSearchCriteria{page,cursor,limit}` / `BankSearchPage{cursor,currentPage,hasMorePages}`), `ApiBankRepository.ts` (shape `pagination{currentPage,pageCount,count,hasMorePages,cursor}`, guard `isBankSearchResponse`, `search()` apend `page`/`cursor`), `buildSearchParams.ts` (filters-only), `shared/domain/Search/index.ts` (solo `Filter`). Confirmado: los tipos de paginación NO existían en `shared/domain/Search/`.
- **W11 sellado (Sergio, 2026-06-11) ANTES de tocar PWA:** invariante consumer-side "no-reconstruction client-side" añadido a `pr3-execution-contract.md` §3 (W11) + tejido en AC#2 / Task 4 / Task 7 / Task 9 de esta story. W10 (Linkability) y la corrección de W7 (`before` vacía ⇒ `hasNext=true`) también trasladados a §3.

### Completion Notes List

- ✅ **Task 1 completado.** `PaginationLinks` + `PageEnvelope` creados en `shared/domain/Search/` (named exports, sin framework, `string | null` no opcional), re-exportados desde el barrel. Docblocks fijan W9/W11 (navegar `links` verbatim, nunca decodificar/reconstruir). OQ-3 resuelto item-agnóstico (sin genérico). `make pwa.quality` **EXIT=0** (eslint --fix + prettier, sin cambios). Tarea aditiva — no rompe compilación del resto page-based.
- ✅ **Tasks 2-7 completados (migración PWA consumidor — Modelo A2) — VERDE.** `tsc --noEmit` EXIT=0 (source + tests), `make pwa.quality` EXIT=0, **Vitest 541 verdes** (536 previos + 5 nuevos; 94 ficheros). **Task 2:** `BankRepository.search(criteria)` link-free (criteria sin page/cursor); `BankSearchPage = { banks } & PageEnvelope`; nuevo puerto **application** `BankSearchNavigator.follow(link)` (transporte fuera del domain). **Task 3:** `ApiBankRepository` envelope v2, `isBankSearchResponse` rechaza el page-based, `toBankSearchPage()` compartido, `search()` sin page/cursor/after/before + clamp `WIRE_MAX_LIMIT`; nuevo `ApiBankSearchNavigator.follow()` = same-origin/relativo + `safeHref` + `httpClient.get(link)` **verbatim** (cero parsing); `safeHref` no cubre origen externo → check explícito. **Task 4:** `WIRE_MAX_LIMIT=100` fuente única; `buildSearchParams` **filters-only** (W11); selector `[25,50,100]`. **Task 5 (`page.tsx`):** `currentPage`/`hasMorePages`/`page`/`cursorRef` → `pagination: PageEnvelope|null` + `activeLink: string|null` (state); `loadBanks` 2 caminos (query vs `follow`); navegación = `setActiveLink`; reset query = `setActiveLink(null)` (W8); fallback numerado eliminado; `boundaryState` mantiene READY en página vacía navegable. **Task 6 (`BanksPagination`):** direccional, `Page {n}` fuera, **link null = `disabled` no oculto** (D-A11y) + patch `pwa/CLAUDE.md:411-413`. **Task 7:** `ApiBankRepository.test.ts` reescrito (W11: sin cursor/page; D-Cap: 1000→100; guard rechaza page-based); `_fixtures.ts` envelope v2; **nuevos** `ApiBankSearchNavigator.test.ts` (verbatim + open-redirect guard) y `banksPagination.test.tsx` (disabled-not-hidden). DI: navigator bindeado.
  - **Hallazgo (regla React del repo):** escribir un ref en fase de render está prohibido por eslint → `activeLink` es **state**, no ref (igual que el `setPage(1)` del modelo previo). Unifica navegación a través del efecto de carga.
  - **Hallazgo (worktree gotcha):** `docker compose exec` desnudo apunta al proyecto `erpify` equivocado ("service pwa is not running"); usar el nombre real `erpify-api-keyset-pagination-8rho-pwa-1` o `make`.
- ✅ **Task 12 + Task 8 (cierre del freeze, orden de Sergio Task 12 → Task 8) — VERDE, e2e CI diferida.** Gates: `pwa.quality` EXIT=0, Vitest 541/541, Behat 117/819 (gate combinado 1.3+1.4). Revertibilidad y self-review de seguridad confirmados (ver Task 12). **Task 8:** mock cursor-only + 9 casos de `banks.spec.ts` + 2 specs real-api migrados al contrato cursor-only (decisión de Sergio "migrate all 3 specs"). e2e **no ejecutable en local** (sin browsers Playwright); ejecución en CI **diferida** explícitamente (cuando staging esté estable). Diseño congelado: cero cambios estructurales en esta tanda, solo autoría de tests + verificación.

### File List

_Tipos compartidos (Task 1):_
- `pwa/src/context/shared/domain/Search/PaginationLinks.ts` **(nuevo)**
- `pwa/src/context/shared/domain/Search/PageEnvelope.ts` **(nuevo)**
- `pwa/src/context/shared/domain/Search/index.ts` — re-export de ambos

_PWA consumidor — Tasks 2-7 (Modelo A2):_
- `pwa/src/context/shared/domain/Search/paginationLimits.ts` **(nuevo)** — `WIRE_MAX_LIMIT` (D-Cap)
- `pwa/src/context/backoffice/bank/domain/BankRepository.ts` — criteria link-free + `BankSearchPage` envelope
- `pwa/src/context/backoffice/bank/application/BankSearchNavigator.ts` **(nuevo)** — puerto navegación verbatim
- `pwa/src/context/backoffice/bank/infrastructure/ApiBankRepository.ts` — envelope v2, guard, `toBankSearchPage`, search sin cursor + clamp
- `pwa/src/context/backoffice/bank/infrastructure/ApiBankSearchNavigator.ts` **(nuevo)** — `follow()` verbatim + same-origin guard
- `pwa/src/context/shared/infrastructure/DependencyInjection/Container.ts` — bind `BackOfficeBankSearchNavigator`
- `pwa/src/app/backoffice/banks/page.tsx` — estado direccional + 2 caminos de carga
- `pwa/src/app/backoffice/banks/_components/BanksPagination.tsx` — direccional, disabled-not-hidden
- `pwa/src/app/backoffice/banks/_lib/paginate.ts` — opciones `[25,50,100]`
- `pwa/CLAUDE.md` — excepción D-A11y (disabled-not-hidden) a la regla hide
- `pwa/tests/context/backoffice/bank/infrastructure/ApiBankRepository.test.ts` — reescrito a envelope v2 + W11/D-Cap
- `pwa/tests/context/backoffice/bank/infrastructure/ApiBankSearchNavigator.test.ts` **(nuevo)**
- `pwa/tests/app/backoffice/banks/banksPagination.test.tsx` **(nuevo)**
- `pwa/tests/app/backoffice/banks/_fixtures.ts` — `searchPage()` envelope v2

_Red Behat — Task 9:_
- `api/features/backoffice/bank/search.feature` — migrado al envelope cursor-only + 4 escenarios nuevos (simetría, after+before 422, invalid-cursor 422, página vacía forward-recoverable); query-counts `2→1`; `limit=100` en los "all 31"; `page`/`cursor` legacy retirados; `limit=1001→101`
- `api/features/backoffice/bank/query_stats.feature` — query-count `2→1` (listing + name-filter)
- `api/features/backoffice/bank/delete.feature` — query-count `4→3` (bank-in-use, por el drift del engine)
- `api/tests/Behat/Context/HttpRequestContext.php` — step nuevo `I follow the :node link from the previous response` (navegación verbatim W11) + helper privado `jsonNodeFromPreviousResponse()` compartido
- `api/tools/psalm/psalm-baseline.xml` — entrada `$node` (PossiblyUnusedParam, FP de método invocado por reflexión Behat) regenerada con cache limpia

_Observabilidad — Task 10:_
- `api/src/Shared/Infrastructure/Http/EventListener/SearchObservabilityListener.php` **(nuevo)** — emite `keyset_search` (kernel.response) + `invalid_cursor` (kernel.exception, prioridad 32 > ExceptionResponder) en el canal `observability`; never-throw, nunca loguea el cursor
- `api/tests/Unit/Shared/Infrastructure/Http/EventListener/SearchObservabilityListenerTest.php` **(nuevo)** — 12 tests (campos, direction next/prev/first, scoping, unwrap del chain, guard de prioridad anti-stopPropagation)
- `api/config/packages/monolog.yaml` — canal `observability` + handler always-on (dev/test/prod), excluido de los handlers `fingers_crossed`
- `api/tools/psalm/psalm-baseline.xml` — `JsonDecoder` `UnusedClass`→`decodeResponse` PossiblyUnusedMethod (ahora `decodeArray` se usa desde src)

_e2e Playwright (autoría) — Task 8:_
- `pwa/tests/e2e/fixtures/banks-api.ts` — mock list reescrito a cursor-only (helpers `encodeCursorOffset`/`decodeCursorOffset`/`buildCursorLink`; envelope v2; `list_next_cursor` eliminado)
- `pwa/tests/e2e/backoffice/banks.spec.ts` — bloque `describe("pagination")` (9 casos) migrado a `toBeDisabled()`/`toBeEnabled()` + navegación direccional, sin `__indicator`
- `pwa/tests/e2e/backoffice/banks-real-api.spec.ts` — aserciones de paginación (real backend, SEED 26) migradas a cursor-only (scope ampliado)
- `pwa/tests/e2e/backoffice/banks-real-api-flows.spec.ts` — aserciones de paginación (real backend, SEED 30) migradas a cursor-only (scope ampliado)

_Runbook + docs — Task 11:_
- `docs/runbooks/cursor-pagination.md` **(nuevo)** — runbook operativo (envelope, W2/W9/W10/W11, W7, invalid_cursor, válvula legacy, rollback, FR14, queries `jq`)
- `docs/architecture-pwa.md` — consumidor cursor-only (reescrito desde page-based stale)
- `pwa/docs/server-driven-search.md` — receta cursor-only (reescrita desde page-based stale)
- `docs/architecture-api.md` — sección de observabilidad de cursor (canal + listener + prioridad)

## Change Log

| Fecha       | Versión | Descripción                                                                                                                                  | Autor        |
|-------------|---------|----------------------------------------------------------------------------------------------------------------------------------------------|--------------|
| 2026-06-11  | 0.1     | Creación del contexto de la Story 1.4 (PR3 lado consumidor: PWA cursor-only + red Behat migrada + observabilidad). Flaguea el conflicto a11y `pwa/CLAUDE.md`↔AR15 y la ausencia de stack de métricas. | create-story |
| 2026-06-11  | 0.2     | **Arranque dev-story 1.4.** W11 (no-reconstruction client-side) sellado en execution contract §3 + tejido en AC#2/Task 4/7/9. Task 0 grounding (stack up, ficheros PWA releídos). **Task 1** tipos compartidos `PageEnvelope`/`PaginationLinks` (pwa.quality EXIT=0). Sprint-status 1-4 → in-progress. Pendiente: fork de diseño del puerto W11-compliant (search-by-criteria vs navigate-by-link) antes de Tasks 2-5. | dev-story |
| 2026-06-11  | 0.3     | **W11-PORT = Modelo A2** (Sergio): navigator en app-layer (`BankSearchNavigator.follow(link)`), puerto de dominio link-free, el cliente NUNCA parsea el link (ni domain ni adapter) — reenvío verbatim. Sellado en execution contract §3 (W11) + §7; W10/corrección-W7 trasladados a §3; gate §5/§6 → W1–W11. | dev-story |
| 2026-06-11  | 0.4     | **Tasks 2-7 (migración PWA consumidor) COMPLETOS y VERDES.** Puerto link-free + `BankSearchNavigator`; adaptadores (envelope v2, guard anti-drift, `follow()` verbatim + open-redirect guard); `WIRE_MAX_LIMIT`; `page.tsx` estado direccional (2 caminos); `BanksPagination` disabled-not-hidden + patch `pwa/CLAUDE.md`; tests reescritos + 2 nuevos (W11/D-A11y). `tsc` EXIT=0, `pwa.quality` EXIT=0, **Vitest 541 verdes**. Pendiente: Task 8 (e2e, CI), Task 9 (Behat — verificador de invariantes), Task 10/11 (observabilidad + runbook), Task 12 (gates cierre). | dev-story |
| 2026-06-11  | 0.6     | **Tasks 10 + 11 (observabilidad + runbook) COMPLETAS y VERDES.** `SearchObservabilityListener` (canal Monolog dedicado `observability` always-on — sobrevive `fingers_crossed`) emite `keyset_search` (route/limit/direction/pagination_mode/count_mode/has_next/has_prev) + `invalid_cursor` (cursor_cause); 12 unit + verificado e2e en Behat (54+1 eventos). **2 desviaciones de diseño:** (a) listener dedicado en vez de extender `ExceptionResponder` (no toca el pipeline de error/NFR26; sobrevive prod fingers_crossed); (b) `cursor_version` `v` por-request omitido (exigiría tocar el engine congelado; diagnóstico de versión vía `invalid_cursor{cause=version}`). Runbook `docs/runbooks/cursor-pagination.md` + docs (`architecture-pwa.md`/`server-driven-search.md` reescritos a cursor-only, `architecture-api.md` observabilidad). `make php.behat` 117/117, `make php.quality` EXIT=0. Pendiente: Task 8 (e2e CI), Task 12 (gates cierre + self-review). | dev-story |
| 2026-06-11  | 0.7     | **Tasks 12 + 8 COMPLETAS (orden de Sergio Task 12 → Task 8 → freeze) — VERDE, e2e CI diferida.** Task 12: `pwa.quality` EXIT=0, Vitest 541/541, Behat 117/819 (gate combinado); revertibilidad (AR16) y self-review de seguridad frontend confirmados. Task 8: mock e2e cursor-only + 9 casos `banks.spec.ts` migrados a disabled-not-hidden/navegación direccional; **scope ampliado (Sergio "migrate all 3 specs")** a `banks-real-api.spec.ts` y `banks-real-api-flows.spec.ts` (referenciaban el `__indicator` eliminado). e2e no ejecutable en local (sin browsers); **ejecución CI diferida** (staging estable primero). Diseño congelado. | dev-story |
| 2026-06-11  | 0.5     | **Task 9 (red Behat) COMPLETA y VERDE — gate R3 del PR combinado satisfecho.** `make php.behat` 117 esc./819 steps; `make php.stan` OK; `make php.quality` EXIT=0. `search.feature` migrada al envelope cursor-only + 4 escenarios nuevos (simetría bajo empates masivos, after+before→422, invalid-cursor→422, página vacía forward-recoverable). Step Behat nuevo `I follow the :node link …` (navegación verbatim W11 — la nota "no new steps" del plan era incorrecta). Fix cross-file: query-counts `2→1` (`query_stats`) y `4→3` (`delete`) por el drift del engine keyset. **Corrección W7 confirmada contra el comportamiento sellado de 1.3: `before` vacía ⇒ `hasNext=true, hasPrev=false`** (la prosa AC#3/contract "hasPrev=true" era mislabel). psalm-baseline `+$node` (regen con cache limpia). Pendiente: Task 8 (e2e CI), Task 10/11 (observabilidad + runbook), Task 12 (gates cierre). | dev-story |
