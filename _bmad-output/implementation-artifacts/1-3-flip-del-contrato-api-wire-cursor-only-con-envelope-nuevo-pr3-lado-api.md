---
baseline_commit: 8b1d72899ee4484b9b75273ff49ce60ca46d3f1c
---

# Story 1.3: Flip del contrato API — wire cursor-only con envelope nuevo + repos por composición (PR3, lado API)

Status: in-progress

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

> **D-1 (2026-06-11):** la reestructuración de repositorios de herencia a composición (FR9, FR11 parcial, FR12) se trasladó aquí desde PR2 — es la pieza que conecta el `DoctrineSearchEngine` (ya entregado off-wire en PR2, commit `8b1d728`) al runtime path, así que solo cobra sentido en el flip observable de PR3.
>
> **Emparejamiento de PR (AR16, nota de `sprint-status.yaml`):** Story 1.3 (lado API) y **Story 1.4 (lado consumidor: PWA + Behat + observabilidad)** se entregan en **el mismo worktree/PR (PR3)** — breaking change sincronizado API↔PWA del mismo ciclo. **Consecuencia operativa:** el flip del envelope en 1.3 deja en rojo los escenarios Behat page-based existentes; su migración al envelope nuevo es **alcance de la Story 1.4 dentro del mismo PR**. El gate de cierre de 1.3 es estático (`stan`/`psalm`/`quality`); Behat verde es el gate del PR combinado 1.3+1.4. No abrir un PR de 1.3 sin 1.4.

## Story

As a consumidor del API de listados (la PWA),
I want navegar `GET /api/v1/backoffice/banks` exclusivamente con `limit` + `after`/`before` opacos y recibir el envelope `{hasNext, hasPrev, count?, links}`,
so that la navegación sea O(1) a cualquier profundidad, los enlaces sean autocontenidos y toda invalidez de cursor sea observable — sin rastro de páginas numeradas.

## Acceptance Criteria

1. **Repos por composición delegando en el engine (FR9/K9, FR12, FR11 parcial):** Given `DoctrineBankRepository` y `DoctrineBankAccountRepository` (hoy por herencia + `Paginator` legacy), When se completa el flip de PR3, Then implementan solo sus puertos de dominio con `EntityManagerInterface` inyectado — sin `ServiceEntityRepository`/`ManagerRegistry`, sin `getEntityClassName()`, sin `QueryBuilderWithOptions`, sin `PaginatorOption` — y el read-path paginado de Bank delega en el `DoctrineSearchEngine` de PR2. And el contrato del puerto expone `save()` sin flush implícito obligatorio (FR12 — puerta abierta), conservando la semántica observable actual (D-3); los helpers muertos accesibles se eliminan de `AbstractDoctrineRepository` preservando el comentario `// why:` del naming estable de parámetros (FR11 parcial). And el `Paginator` legacy deja de servir el read-path de Bank/BankAccount (su borrado total es PR4/Story 1.5) — este es el único punto donde el engine nuevo pasa a gobernar la ejecución real.

2. **Envelope nuevo cursor-only (FR6/K6, FR1, AR7/K11, AR20):** Given una petición con `filters[]`/`sort`/`direction` válidos y sin cursor, When se ejecuta, Then responde 200 con `pagination: {hasNext, hasPrev, count, links: {next, prev}}` de shape constante — `links.next`/`links.prev` siempre presentes, `null` cuando no aplican, **prohibido `skip_null_values`**. And los `links` son URLs relativas al mismo endpoint preservando los query params vigentes y sustituyendo solo `after`/`before`. And `page`, `currentPage`, `pageCount` y `MAX_PAGE` desaparecen de `SearchQuery`/`SearchCriteria`/`PaginationMeta`. And `limit` tiene default 25 y techo 100; `limit` ∉ [1,100] → 422 `validation-failed`; fixtures/Behat que asumían 1000 se actualizan.

3. **`after`/`before` mutuamente excluyentes + autoridad semántica del wire (AR1/K2, AR10, AR21):** Given una petición con `after` y `before` simultáneos, When se mapea el DTO, Then responde 422 `validation-failed` en mapping — capa 1 del DAG. And la dirección de navegación la decide **exclusivamente** el parámetro wire; un `dir` discrepante en el payload del cursor → 422 `invalid-cursor` (integrity binding, jamás fallback de navegación).

4. **Cursor inválido → 422 `invalid-cursor` por el pipeline RFC 9457 (NFR2, NFR26):** Given un cursor inválido por cualquiera de las cuatro causas (firma/versión/payload/fingerprint), When se valida, Then responde 422 `invalid-cursor` (familia `invalid-search-criteria`) indistinguible para el cliente, vía pipeline RFC 9457 — **cero `JsonResponse` manual**. And existe la fila en `docs/api-error-contract.md`, `MarkerStatusMapContractTest` actualizado y `make php.lint.error-contract` verde. And se confirma que `InvalidCursor` fluye por `ExceptionResponder` (`PRIORITY = 16`) — el `SearchExceptionListener` legacy ya fue retirado (AR17 resuelto). And `InvalidCursor` se loguea con `cause` en contexto, nunca el cursor crudo (NFR1).

5. **Hueco lógico / fin de dataset → 200 `items: []` (AR6/K10, FR3):** Given navegación `after`/`before` hacia un hueco lógico (filas borradas) o fin del dataset, When se ejecuta, Then responde 200 con `items: []`, flags de affordance coherentes — nunca error. And `hasNext`/`hasPrev` se computan con trick +1 en la dirección navegada y derivación en la contraria, sin queries extra (**incluida la página `before` vacía: `hasPrev=true`, la página de la que vienes es accionable** — ver Dev Notes "Diferidos de PR2 que PR3 resuelve" #3). And en modo DETAILED `count` llega poblado; en LIGHT, `count: null`.

6. **Seam "ir a fecha" (FR5/K14, AR9):** Given el seam de "ir a fecha", When el servidor sintetiza una posición de cursor desde un valor de clave de ordenación, Then usa la misma maquinaria K3/K4 sin endpoint nuevo, con `hasPrev: true` conservador. (UI diferida — sin spec UX en este alcance; solo se entrega/verifica el seam server-side.)

7. **Válvula de transición + gates + docs (AR8/K13, AR18, FR14):** Given el entorno `dev` o `staging`, When se activa la válvula `pagination_mode=legacy|cursor_v2`, Then permite emitir el envelope viejo — `#[When(env)]`, inalcanzable en prod por construcción, **sin tests propios**. And `make php.stan` + `make php.psalm` + `make php.quality` verdes sin baselines nuevas; docs API obligatorios actualizados (AR18), **documentando explícitamente la no-garantía de instantánea entre páginas y la garantía que sí se da** — sin duplicados ni saltos causados por la propia paginación, unicidad de ids intra-página (FR14: el "documentado" es parte del requisito).

8. **Revertibilidad encapsulada de PR3 (AR16):** Given la rama con PR1 y PR2 ya integrados en `main` (`8b1d728`), When se revierte el merge de PR3 (junto con Story 1.4, mismo PR), Then el comportamiento wire previo (envelope legacy, params de página) queda completamente restaurado **sin requerir revertir PR1 ni PR2** — la activación del contrato cursor-only está íntegramente encapsulada en los cambios de PR3. And ningún cambio de PR3 modifica piezas de PR1/PR2 de forma que el revert las rompa (el kernel y el engine siguen compilando y pasando sus suites con PR3 revertido). And esta propiedad es un **criterio de review del PR**: cualquier cambio que la rompa invalida la estrategia de rollout y debe rediseñarse antes del merge.

## Tasks / Subtasks

- [x] Task 0: Entorno aislado y baseline compartido con Story 1.4 (AC: #8)
  - [x] `make worktree.create BRANCH=feat/api-keyset-pagination` (regla dura: jamás trabajar en `main`); `cd` al worktree y `make app.dev`. **Este worktree es compartido por 1.3 (API) y 1.4 (PWA+Behat+métricas)** — mismo PR3. → worktree `api-keyset-pagination-8rho` (branch `feat/api-keyset-pagination-8rho`, off main `64e8145`); stack up (todos los contenedores healthy).
  - [x] Confirmar que PR2 (`8b1d728`) está en la base: `DoctrineSearchEngine`, `RowUniquenessGuard`, `WirePaginationPolicy`, kernel `…/Search/Keyset/`, migración `Version20260610195734` y suites directas presentes y verdes (`make php.unit c='--filter Keyset'`). → 8b1d728 confirmado ancestro de main; piezas presentes; migración en `api/migrations/2026/Version20260610195734.php`; **76 tests / 180 asserts verdes**.
  - [x] Smoke `make php.behat` ANTES de tocar nada (baseline page-based verde). A partir del flip, los escenarios page-based caen — su migración es Story 1.4 en este mismo worktree. → tras `make php.behat.install` (vendor de la tool aislada ausente en worktree fresco): **116 escenarios / 773 steps verdes**, sin tocar código.
  - [x] Releer el engine (firma `paginate(...)`), `SearchResponder`/`PaginationMeta`, `SearchQuery`/`SearchCriteria`, `DoctrineBankRepository`, `AbstractDoctrineSearchRepository` y `docs/api-error-contract.md` — no implementar de memoria (tabla "Código existente que DEBES leer"). → relectura completa; firma `paginate(...)` (L79) y diferidos #2 (`resolveLimit` L145) / #3 (`buildPage` empty L278) verificados contra el blob del worktree; `WirePaginationPolicy::wire()` = 25/100; `InvalidCursor` ya implementa el marker.

- [x] Task 1: `DoctrineBankRepository` a composición + delegar `search()` en el engine (AC: #1)
  - [ ] Dejar de extender `AbstractDoctrineSearchRepository`. Implementar solo `BankRepository` + `BankSearchRepository` + `BankStoredObjectQueries`. Conservar los tres `#[AsAlias]`.
  - [ ] Constructor por composición: `EntityManagerInterface $em` + `DoctrineSearchEngine $engine` + `NormalizedTextFieldNormalizer` + `AsciiUpperTextFieldNormalizer`. **Eliminar** `ManagerRegistry`, `PaginatorCursorFactory`, `FilterApplier` del constructor del repo (el engine ya orquesta `FilterApplier` internamente).
  - [ ] `search(SearchCriteria): PaginatedResult` → construir el QB base (`$this->em->createQueryBuilder()->select('b')->from(Bank::class, 'b')`; sin joins en Bank), pasar `searchFieldMap()`/`sortFieldMap()` (preservar las 4 entradas sortables: `name`→`b.nameNormalized`, `shortName`→`b.shortName`, `createdAt`→`b.createdAt`, `updatedAt`→`b.updatedAt`) y delegar en `$engine->paginate(...)`. **Resolver el `routingDirection`** desde el intent del wire (`after`→`Cursor::DIRECTION_AFTER`, `before`→`DIRECTION_BEFORE`; ver Task 4 sobre cómo viaja en `SearchCriteria`).
  - [ ] Adaptar el retorno `Page` → el tipo que consume `SearchResponder` (ver Task 3 — decisión de `PaginatedResult`→`Page` en el puerto).
  - [ ] Reescribir las queries no-search con `EntityManagerInterface` directo (sin `find()`/`createQueryBuilder` heredados): `findById`, `save`/`remove` (vía `em->persist/flush/remove`), `countBanksWithStoredObjectContentHash`, `findStoredObjectMimeTypeByContentHash`. Eliminar `getEntityClassName()`.

- [x] Task 2: `DoctrineBankAccountRepository` a composición (sin engine — no tiene read-path paginado) (AC: #1)
  - [ ] Dejar de extender `AbstractDoctrineRepository`; implementar solo `BankAccountRepository` con `EntityManagerInterface`. `countByBankId()` reescrito con `em->createQueryBuilder()`. Conservar `#[AsAlias(BankAccountRepository::class)]`. Eliminar `getEntityClassName()`. (No usa `DoctrineSearchEngine`: `BankAccount` no expone búsqueda paginada.)

- [x] Task 3: Conectar el engine al wire — adaptador `Page` → responder (AC: #1, #2, #5)
  - [ ] Decidir y documentar el contrato del puerto `BankSearchRepository::search()`: hoy devuelve `PaginatedResult`. PR3 hace que el engine devuelva `Page<Bank>` (dominio). **Opción recomendada:** evolucionar el puerto a `Page` y que el `SearchResponder` lea del `Page` directamente (alinea con FR6/FR10 y NFR5: los arrays `firstItem`/`lastItem` desaparecen del puerto). Confirmar con OQ-1.
  - [ ] El `SearchResponder` ya no usa `PaginatorCursorFactory` para Bank: los cursores (`nextCursor`/`prevCursor`) los trae el `Page` ya codificados por el engine (`CursorCodec`, base64url + HMAC-32 + fingerprint). `PaginatorCursorFactory` queda huérfano para Bank (su borrado es PR4).
  - [ ] **Construir los `links` relativos**: `links.next = ?{query vigente con after=<nextCursor>, sin before}`, `links.prev = ?{query vigente con before=<prevCursor>, sin after}`; `null` cuando el cursor correspondiente es `null`. Preservar `limit`/`sort`/`direction`/`filters[]`/`paginationMode` en ambos. **Sanitizar/encodear** cada segmento (alineado con la regla XSS/open-redirect del repo: URLs relativas mismo-endpoint, nunca absolutas con host externo).
  - [ ] **Invariante de ownership (W9 / OQ-4 del `pr3-execution-contract.md`) — criterio de review:** `SearchResponder` es el **único compositor** de `links`. El **engine y el `Page` son link-agnósticos**: el `Page` transporta cursores **opacos** (`nextCursor`/`prevCursor`), nunca URLs/rutas/query strings. La materialización de URL ocurre **solo aquí**, en la frontera HTTP — jamás en el engine ni en el dominio. Cero símbolos de URL/ruta en `DoctrineSearchEngine` ni en `Page`. (El cliente de 1.4 reenvía `links` verbatim; no reconstruye.)

- [x] Task 4: DTO `SearchQuery` + `SearchCriteria` — `after`/`before`, limit 25/100, muerte de `page` (AC: #2, #3)
  - [x] `SearchQuery`: **añadir** `?string $after`, `?string $before` (`#[Assert\Length(max: 8192)]` como el `cursor` legacy). **Eliminar** `?int $page` (y el `cursor` legacy). Cambiar `limit` default a `SearchCriteria::DEFAULT_LIMIT` (**25**) y el `#[Assert\LessThanOrEqual]` a `SearchCriteria::MAX_LIMIT` (**100**); `limit` ∉ [1,100] → 422 `validation-failed`. Docstring corregido 400→**422**.
  - [x] **Validación de exclusión mutua** `after` XOR `before` (capa 1 del DAG, AR1/K2): `#[Assert\Callback] validateCursorsAreMutuallyExclusive` → violación en `after` → 422 `validation-failed` en mapping (no en el handler).
  - [x] `toCriteria()`: mapea el cursor presente + dirección. **Dirección como VO de dominio nuevo `NavigationDirection` {After, Before}** (`Shared/Domain/Search/`, sin importar `Cursor` de Infra → AR21 limpio); `SearchCriteria.routingDirection: NavigationDirection` deriva 1:1 del param (`before`→Before, si no After). Una sola fuente de verdad.
  - [x] `SearchCriteria`: **eliminado** `page` y `MAX_PAGE`; nuevas consts `DEFAULT_LIMIT=25`/`MAX_LIMIT=100` (canónicas, Domain — el DTO las lee; layering-correct). `limit` default 25, valida [1,100].
  - [x] Barrido `page`/`MAX_PAGE`: único reader src roto = `AbstractDoctrineSearchRepository::getPaginatedResults` (`$criteria->page`) → **desacoplado** (param `int $page` explícito; legacy retenido para la válvula, borrado PR4). `InvalidPagination::pageOutOfRange` huérfano → eliminado. `make php.stan` verde; `SearchCriteria|SearchQuery` unit verde (37 tests).

- [x] Task 5: Envelope `PaginationMeta` v2 + `SearchResponder` (AC: #2, #5)
  - [ ] Reescribir `PaginationMeta` a las claves nuevas: `{hasNext: bool, hasPrev: bool, count: ?int, links: {next: ?string, prev: ?string}}`. Eliminar `currentPage`/`pageCount`/`hasMorePages`/`cursor`. `toArray()` emite las 4 claves con `links` anidado; **`links.next`/`links.prev` siempre presentes, `null` cuando no aplican** (FR6: shape constante).
  - [ ] `SearchResponder::respond(...)` construye `PaginationMeta` desde el `Page` (no desde `PaginatedResult` + cursor legacy). **Prohibido `skip_null_values`** en el responder (AR20): un `count: null` (LIGHT) o `links.next: null` debe emitirse como `null` explícito, no omitirse.
  - [ ] FQCN pineado del envelope v2: `…\Infrastructure\Http\Responder\PaginationMeta` (AR12 — no inventar variante).

- [ ] Task 6: Resolver los diferidos de PR2 que PR3 DEBE cerrar — `resolveLimit` default 25 + `before` vacía (AC: #2, #5)
  - [x] **Diferido #2 (`resolveLimit` default inerte):** **RESUELTO por OQ-2 = (a) en el DTO.** `SearchQuery.limit` default `SearchCriteria::DEFAULT_LIMIT` (25) → `SearchCriteria` siempre llega con un `limit` concreto, así que el `defaultLimit` inerte del engine deja de importar. `resolveLimit()` se mantiene como **clamp puro** (`max(1, min($criteria->limit, $policy->maxLimit))`) — **sin cambio en el engine** (mecánica pura). `WirePaginationPolicy.defaultLimit` queda como fuente declarativa; las consts canónicas viven en `SearchCriteria` (Domain, layering-correct). Documentar en Task 14.
  - [x] **Diferido #3 (`before` vacía):** **RESUELTO (decisión sellada Sergio: Opción A).** `buildPage()` empty branch ya no cortocircuita a `(false,false)`: aplica la **misma fórmula direccional con `hasExtra=false`** → `new Page([], $isBefore && $hadCursor, !$isBefore && $hadCursor, $count)`. Invariante: **empty-before ⇒ hasNext=true, hasPrev=false** (forward-recoverable only); empty-after ⇒ hasNext=false, hasPrev=true. NO es estado especial bidireccional. La prosa de AC#5/W7 ("hasPrev=true") es mislabel → **corregir en docs (Task 14)** y fijar el assert de Behat de 1.4 a `hasNext=true`. Keyset suite verde (76). _Pendiente: test directo focalizado del caso vacío (lo añado con los funcionales del flip, Task 15) + escenario Behat (1.4)._
  - [ ] **Links↔flags en página vacía (abierto, a resolver en Task 3/responder):** un empty-before con `hasNext=true` pero `nextCursor=null` (no hay fila para encodear) deja `links.next=null` con `hasNext=true` → tensión con W1. Decidir en el responder cómo se materializa `links.next` (¿re-encode del cursor inbound con `dir=after`? choca con el integrity binding AR21). Marcado para Task 3.

- [x] Task 7: Contrato de error `invalid-cursor` (AC: #4)
  - [x] `InvalidCursor` implementa `InvalidSearchCriteria` (→ 422). Verificado: fluye por `ExceptionResponder` (`PRIORITY = 16`) + `ProblemDetailsFactory` sin listener nuevo; **cero `JsonResponse` manual** (NFR26).
  - [x] Fila `invalid-cursor` añadida a `docs/api-error-contract.md` (lista de excepciones concretas de `InvalidSearchCriteria` + descripción de las 4 causas indistinguibles + binding `dir`). **`MarkerStatusMapContractTest` NO cambia** (pinea los 8 markers; `invalid-cursor` es un `type` concreto bajo el marker existente). `make php.lint.error-contract` verde. _Bonus: corregido texto stale de `invalid-pagination` (mencionaba `page`/`MAX_PAGE` ya eliminados)._
  - [x] Logging: `InvalidCursor.context` vacío (causa solo interna), nunca el cursor crudo (NFR1) — ya garantizado por el named-constructor. _El per-error log line con `cursor_cause` en `ExceptionResponder::buildLogContext` es observabilidad de **Story 1.4** (pr3-execution-contract §4)._

- [x] Task 8: Activar la validación de cursor en el wire — 422 por causa + `dir` discrepante (AC: #3, #4)
  - [x] Verificado: el engine valida el cursor (firma→versión→payload→fingerprint) y lanza `InvalidCursor` por causa; la aridad incorrecta → `InvalidCursor::payload()` antes del `KeysetPredicateBuilder` (`decodeCursor`). En PR3 ese `InvalidCursor` aflora como 422 vía pipeline RFC 9457. `dir` discrepante → 422 `invalid-cursor` (integrity binding en `CursorCodec`, AR21). Cubierto a nivel unidad (`CursorCodecTest`); **prueba HTTP 422 en vivo = Task 15 (funcionales)**.

- [ ] Task 8: Activar la validación de cursor en el wire — 422 por causa + `dir` discrepante (AC: #3, #4)
  - [ ] El engine ya valida el cursor en el paso 5 (firma→versión→payload→fingerprint) y lanza `InvalidCursor` con la causa. En PR3 ese `InvalidCursor` **deja de ser interno** y aflora al cliente como 422 (en PR2 era off-wire). Confirmar que la aridad incorrecta se mapea a `InvalidCursor::payload()` antes de invocar `KeysetPredicateBuilder` (resuelto en PR2; verificar que sigue así en el runtime path).
  - [ ] `dir` del payload discrepante con el param wire → 422 `invalid-cursor` (integrity binding, AR21). El `routingDirection` del param manda la navegación; el `dir` solo se compara.

- [x] Task 9: Affordance hacia hueco/fin + modos LIGHT/DETAILED (AC: #5)
  - [x] Navegación a hueco lógico (filas borradas) → 200 `items: []`, flags coherentes, nunca error (AR6). Verificado en `testBeforeIntoADeletedGapIsForwardRecoverable` (borra filas y navega `links.prev`).
  - [x] `count`: DETAILED poblado / LIGHT `null` (FR3) — verificado en `testDetailedModePopulatesCountWhileLightLeavesItNull`. **(Cazó el bug del engine `getClassMetadata`.)**

- [~] Task 10: Seam "ir a fecha" server-side (AC: #6) — **DIFERIDO de PR3** (freeze, Sergio 2026-06-11)
  - [ ] Verificar/entregar el seam que sintetiza una posición de cursor desde un valor de clave de ordenación (misma maquinaria K3/K4 — `CursorPositionExtractor`/`CursorCodec`), `hasPrev: true` conservador, **sin endpoint nuevo**. UI diferida. Si el seam no existe aún, dejarlo como punto de extensión testeado a nivel unidad, no como endpoint.
  - **Diferido fuera del flip:** único cambio de comportamiento real pendiente; toca navegación temporal del cursor → riesgo de reabrir W9/W10. Sin spec UX en alcance. Movido a `deferred-work.md#Deferred from: PR3 API contract freeze`; se retoma como trabajo aislado post-freeze, no dentro de PR3. Ver `pr3-execution-contract.md` §7.4.

- [x] Task 11: Válvula de transición `pagination_mode=legacy` (AC: #7)
  - [x] Válvula `pagination_mode=legacy` (`PaginationModeBankSearchValve` `#[AsDecorator]` + `LegacyBankSearchRepository`). **Desviación sellada (Sergio, Opción 2):** NO `#[When]` sino **registrada en todos los envs + guard runtime fail-closed** (legacy solo en dev/staging + param explícito; resto → keyset) — porque Psalm sin container-graph no ve servicios `#[When]` como usados y env-gating forzaría baseline nueva (AC#7). "Inalcanzable en prod por guard de runtime", no por construcción. Sin tests propios (AR8). **Borrado entero en PR4 (tarea explícita).** Devuelve el mismo `Page`; W9 intacto (compositor único = `SearchResponder`).

- [ ] Task 12: Perf gate de staging (NFR3) + diferido de dirección de índice (AC: #2 perf, Dev Notes #5)
  - [ ] Ejecutar el perf gate de staging con doble perfil (uniforme ~100k + sesgado skew 80/10): p95 del listado sin regresión vs legacy.
  - [ ] **Diferido #5 (dirección del índice):** evaluar aquí si el walk `(col DESC, id ASC)` sobre el índice compuesto `(col, id)` ASC degrada el plan (orden mixto). Es **gap de perf, no de corrección** (AR4 correct-by-result). Si el perf gate lo evidencia, decidir índice direccional adicional; si no, registrar la postura. **No** perseguir estabilidad del plan como proxy de corrección (AR20).

- [x] Task 13: Verificación de revertibilidad (AC: #8) — **PROBADO estructural + empíricamente**
  - [x] **Modelo de revert por capas (auditoría):** superficie inverse-diff = 20 ficheros tracked + 4 nuevos, autocontenida. Territorio PR1/PR2 tocado SOLO en `Page.php` (1 línea, covarianza) + `DoctrineSearchEngine.php` (63 líneas localizadas: W10 empty + reencodeCursor + rootEntity + fix countIfDetailed); **kernel `…/Keyset/*` intacto**. **Cero contaminación:** cada símbolo PR3-nuevo (`NavigationDirection`, valve, legacy repo, `::fromPage`) referenciado SOLO dentro del diff; `SearchResponder` consumido SOLO por el controller migrado; cluster legacy de lectura (6 clases) **íntegro** → el revert restaura el page-based. Baseline +2/−stale vive en el diff → revierte atómicamente.
  - [x] **Simulación empírica del revert:** `git stash` de todo PR3 → worktree = main (wire legacy). En ese estado: **PR2 keyset suite 76 verdes** (engine revierte a su forma PR2 sin romper) + **Behat page-based 116 esc. / 773 steps verdes** (= comportamiento wire previo —envelope legacy, params de página— COMPLETAMENTE restaurado). PR3 restaurado y re-verificado verde (`php.quality` EXIT=0). _Nota operativa: el pop de un stash `-u` no re-aplica los cambios tracked si los untracked colisionan; recuperado con `git restore --source=stash` — gotcha registrada._

- [x] Task 14: Docs obligatorios (AR18) + FR14 documentado (AC: #7)
  - [x] `docs/architecture-api.md` (flip del wire, engine en runtime, repos por composición), `api/docs/adding-endpoints.md` (cómo un endpoint de búsqueda emite el envelope cursor-only), `docs/api-error-contract.md` (`invalid-cursor` — ya añadido en Task 7), `docs/source-tree-analysis.md` (línea Search del quick-reference). **Documentado como el sistema se ejecuta HOY**, no como diseño aspiracional: (1) frontera dura engine/`SearchResponder` (W9 — engine+`Page` link-agnósticos, responder único compositor); (2) cursor-only como contrato físico (cero números de página en el wire); (3) válvula = compat-layer reversible fail-closed, NO "modo alternativo" (mismo `Page`/responder); (4) `Page` como unidad semántica (VO de dominio, cursores opacos, no transporte de links); (5) invariantes W2 (una sola serialización de params)/W9 (ownership del envelope)/W10 (Linkability `hasNext⇒nextCursor!=null`) como restricciones reales del wire; (6) **corrección explícita de AC#5/W7**: `before` vacía → `hasNext=true, hasPrev=false` (la prosa original "hasPrev=true" era mislabel; comportamiento real pineado por `BankSearchCursorFunctionalTest`). Anti-patterns cursor-only añadidos a `architecture-api.md`; skeleton de `adding-endpoints.md` reescrito al patrón Bank real (`final readonly` controller + `SearchResponder`, repo por composición, sin `AbstractSearchController`/`PaginatorCursorFactory`/`MAX_PAGE`).
  - [x] **FR14 (documentar es parte del requisito):** no-garantía de instantánea entre páginas + la garantía que sí se da (sin duplicados/saltos por la propia paginación; unicidad de ids intra-página por el tie-break `id`) documentada en `docs/architecture-api.md` (subsección "Cursor-only wire envelope") **y** `api/docs/adding-endpoints.md` (subsección "Cursor-only navigation wire").

- [ ] Task 15: Gates y cierre (AC: #7, #8)
  - [ ] `make php.stan` por archivo cambiado durante el desarrollo.
  - [ ] `make php.unit` verde (suites directas del engine + nuevos tests del responder/DTO/repo). **Tests funcionales nuevos** del flip (envelope, 422 cursor, after/before, página vacía, links relativos) en `api/tests/Functional/…`.
  - [ ] `make php.quality` al cierre (stan + psalm + error-contract + phpmd) — **verde sin baselines nuevas**. Vigilar Psalm `findUnusedCode` (borrar callers legacy puede dejar métodos huérfanos: si es código que muere en PR4, déjalo; si es de PR3, límpialo). Jamás regenerar baseline sin `var/cache/psalm` limpio.
  - [ ] **Behat:** la migración de los escenarios al envelope nuevo (after/before, simetría next×3/prev×3, 422 `invalid-cursor`, página vacía) es **Story 1.4** en este mismo PR. 1.3 actualiza únicamente fixtures/Behat que asumían `limit=1000` (AC #2). No declarar 1.3 "done" hasta que 1.4 deje Behat verde en el PR.
  - [ ] Self-review de seguridad del repo (checklist backend de CLAUDE.md): injection (binds parametrizados — el engine ya lo garantiza), open-redirect en los `links` relativos, sin secretos en logs (cursor crudo nunca), RFC 9457 sin leaks.

- [ ] Task 16 (hardening — watch, no bloqueante): el engine entra al runtime (Dev Notes #4, #9)
  - [ ] **Diferido #4 (`RowUniquenessGuard` falla-abierto):** con el engine gobernando el read-path real, el guard ahora protege producción. Hoy NO caza (a) cartesiano multi-root `from(A)->from(B)`, (b) joins to-many no seleccionados que multiplican filas bajo `LIMIT`. Bank no los usa (QB de un solo root, sin joins), así que **no bloquea PR3**; registrar como riesgo si un repo de búsqueda futuro los introduce. Decisión: endurecer hacia fail-closed queda fuera del scope de 1.3 salvo que aparezca un caller real.
  - [ ] **Diferido #9 (`qualify()` por regex):** `qualify()` reescribe el DQL del predicado por regex y está acoplado a que el builder emita `id` bare (seguro hoy: Bank pre-cualifica con `b.`). Latente para paths de sort bare en reuso genérico. Con la composición habilitando reuso del engine por más repos, **preferible pasar el alias al `KeysetPredicateBuilder`** en vez de reescribir por regex — evaluarlo si entra un segundo repo de búsqueda; no bloquea Bank.

## Dev Notes

### Contexto arquitectónico imprescindible

- **Fuente única de requisitos:** ADR `_bmad-output/planning-artifacts/architecture-keyset-pagination.md` (status: IMPLEMENTATION LOCKED) + `epics.md` (Story 1.3) + `implementation-readiness-report-2026-06-10.md`. Ante duda, el ADR manda; ante conflicto con CLAUDE.md/docs/rules, señalar el conflicto, no elegir.
- **Secuencia vinculante AR16:** PR1 (kernel puro, en `main`) → PR2 (engine off-wire, en `main` `8b1d728`) → **PR3 (esta historia + Story 1.4: el ÚNICO flip observable)** → PR4 (borrado del legado). Cualquier desviación invalida el modelo de validación.
- **Qué entrega PR3 (lado API):** el `DoctrineSearchEngine` pasa de *specification engine* off-wire a **gobernar el read-path HTTP real** de Bank. Los repos pasan a composición y delegan en él. El envelope wire cambia a cursor-only. Es el inverso exacto de PR2 (que era "wire intacto, engine off-wire").

### ⚠️ DECISIÓN LOAD-BEARING: PR3 es el flip observable (leer antes de codear)

PR2 fue *replatforming interno sin impacto observable*. **PR3 es lo contrario: el cambio observable está concentrado y es el objetivo.** La consigna de review se invierte: en PR2 era "¿esto es observable en el wire? entonces no pertenece a PR2"; en PR3 es **"¿este cambio observable está encapsulado de forma que el revert restaure el wire legacy sin tocar PR1/PR2?"** (AC #8, AR16). El riesgo nº1 de PR3 es romper la encapsulación de la revertibilidad.

| Dimensión wire | Legacy (PR2 end-state) | PR3 (esta historia) |
|---|---|---|
| Params | `page` + `cursor` (legacy zlib) | **`after`/`before` opacos** (base64url+HMAC-32+fingerprint), mutuamente excluyentes; `page` muere |
| Envelope | `{currentPage, pageCount, count, hasMorePages, cursor}` (5 claves) | **`{hasNext, hasPrev, count?, links:{next, prev}}`** (shape constante, links siempre presentes) |
| Cursor inválido | Degradación silenciosa → 200 página 1 | **422 `invalid-cursor`** (4 causas indistinguibles) |
| `limit` | default 1000, techo 1000 | **default 25, techo 100** |
| Navegación | física keyset, reporta nº de página | física keyset, **navegación direccional pura** (sin nº de página) |

### Firma EXACTA del engine que los repos cablean (verificada en `8b1d728`)

```php
// api/src/Shared/Infrastructure/Persistence/Doctrine/Search/DoctrineSearchEngine.php:79
public function paginate(
    QueryBuilder $queryBuilder,      // QB base del repo (select+from+joins); el repo NO toca applier/codec/predicado
    SearchCriteria $criteria,        // filtros/sort/direction/limit/cursor del dominio
    SearchFieldMap $searchFieldMap,  // allow-list de filtros del repo
    SortFieldMap $sortFieldMap,      // allow-list de sort del repo (el engine resuelve OrderByColumns internamente)
    PaginatorConfig $config,         // PaginationMode LIGHT/DETAILED + fetchJoinCollection
    WirePaginationPolicy $policy,    // WirePaginationPolicy::wire() — DEFAULT_LIMIT=25, MAX_LIMIT=100, exclusiveBoundary
    string $routingDirection = Cursor::DIRECTION_AFTER, // 'after'|'before' — autoridad semántica del wire (AR21)
): Page                              // dominio: items, hasNext, hasPrev, ?count, ?nextCursor, ?prevCursor
```

El engine resuelve `OrderByColumns` desde `SortFieldMap` + tie-break `id` por dentro (paso 1). El repo aporta **solo** el QB base + los field maps; no construye `OrderByColumns` ni toca el `CursorCodec`. (Confirmar la firma releyendo el fichero: es la fuente de verdad.)

### Diferidos del code review de Story 1.2 — clasificación para PR3

> El review de PR2 dejó **11 diferidos legítimos** registrados en `_bmad-output/implementation-artifacts/deferred-work.md#Deferred from: code review of story-1.2 (2026-06-11)` (también listados en la sección *Review Findings* del spec de PR2). `deferred-work.md` es el **registro vivo transversal** y la red de seguridad; se conserva. Esta historia **absorbe los PR3-bound** abajo; los demás permanecen en el registro hasta que se resuelvan. Convención del repo: cuando PR3 resuelva uno, **se borra su bullet** de `deferred-work.md` (no se anota "resuelto").

**A. PR3 DEBE resolver (convertidos en tareas de esta historia):**

1. **#2 — `resolveLimit` nunca aplica `defaultLimit` (25).** Loci confirmado: `DoctrineSearchEngine::resolveLimit()` línea ~145 = `max(1, min($criteria->limit, $policy->maxLimit))`. `limit` ausente (`SearchCriteria` default hoy 1000) → `min(1000, 100)=100`, no 25; `WirePaginationPolicy::defaultLimit` queda inerte. → **Task 6 / Task 4** (decidir dónde vive el 25; recomendado: en el DTO/adaptador). AC #2 ("limit default 25").
2. **#3 — Página `before` vacía devuelve `hasNext=false` (debería `true`).** Loci confirmado: `buildPage()` línea ~278, rama `[] === $items` → `new Page([], false, false, $count)` puentea la lógica `isBefore ? hadCursor`. → **Task 6**. AC #5.
3. **#5 — Índice `(col, id)` ASC no da scan limpio para `(col DESC, id ASC)`.** Gap de **perf, no de corrección** (AR4). El contract test asserta existencia del índice, no dirección. → **Task 12** (evaluar en el perf gate de staging de PR3). AC #2 (perf).

**B. Watch / hardening cuando el engine entra al runtime (no bloqueante para Bank):**

4. **#4 — `RowUniquenessGuard` falla-abierto** fuera del caso addSelect-alias-líder (cartesiano multi-root; joins to-many no seleccionados). Con el engine en producción importa más, pero Bank no los usa (QB de un solo root sin joins). → **Task 16**, registrar riesgo; endurecer si entra un caller real.
9. **#9 — `qualify()` reescribe el DQL por regex**, acoplado al `id` bare (seguro hoy: Bank pre-cualifica con `b.`). La composición habilita reuso del engine por más repos → preferible pasar el alias al `KeysetPredicateBuilder`. → **Task 16**, evaluar al segundo repo de búsqueda.

**C. NO entran en 1.3 — permanecen en `deferred-work.md` (red de seguridad):**

- **#1** `KeysetSqlSnapshotTest` no cierra la conexión DBAL paralela de `setUp()` (test hygiene PR2; el `tearDown()` idiomático dispara el conflicto rector↔psalm) — standalone.
- **#6** El engine no impone `nullable: false` en columnas sortables (solo lo verifica el contract test hardcodeado a Bank) — hardening standalone.
- **#7** `SortFieldMapIndexContractTest` refleja los campos de Bank a mano en vez de derivarlos del `SortFieldMap` — mejora del test de PR2, standalone.
- **#8** AC5 invariante (3) "frontera intra-empate" asserted solo transitivamente — mejora del test de PR2, standalone.
- **#10** `entityName()` colapsa al nombre corto de clase (colisión de fingerprint entre homónimos de distintos contextos) — single-tenant/Bank hoy; forward-looking a Fase H (multi-tenant). Verificado correcto por diseño para el alcance actual.
- **#11** Migración `down()` revierte collation a `pg_catalog."default"` en vez de la heredada — low; `down()` rara vez corre en prod.

### Repos por composición — mapa de migración (verificado en `8b1d728`)

| Hoy (herencia) | PR3 (composición) |
|---|---|
| `DoctrineBankRepository extends AbstractDoctrineSearchRepository` | implementa solo `BankRepository`+`BankSearchRepository`+`BankStoredObjectQueries`; `EntityManagerInterface`+`DoctrineSearchEngine` inyectados |
| Constructor: `ManagerRegistry`, `PaginatorCursorFactory`, `FilterApplier`, 2 normalizers | Constructor: `EntityManagerInterface`, `DoctrineSearchEngine`, 2 normalizers (fuera `ManagerRegistry`/`PaginatorCursorFactory`/`FilterApplier`) |
| `search()` → `getPaginatedResults()` (heredado, `Paginator` legacy) | `search()` → construye QB base + delega en `$engine->paginate(...)` |
| `findById` → `find()` heredado; `save`/`remove` → `persistAndFlush`/`removeAndFlush` heredados | `em->find()` / `em->persist`+`flush` / `em->remove`+`flush` directos |
| `getEntityClassName(): Bank::class` | eliminado |
| `countBanksWithStoredObjectContentHash`, `findStoredObjectMimeTypeByContentHash` | reescritas con `em->createQueryBuilder()` |
| `DoctrineBankAccountRepository extends AbstractDoctrineRepository` | implementa solo `BankAccountRepository`; `EntityManagerInterface`; `countByBankId()` reescrito; **sin engine** (no hay búsqueda paginada) |

- `searchFieldMap()` (5 filtros: name, shortName, id, createdAt, updatedAt) y `sortFieldMap()` (4 sortables) de Bank **se preservan tal cual** — son la allow-list que el engine consume.
- `AbstractDoctrineSearchRepository`/`AbstractDoctrineRepository`/`Paginator`/`PaginatorOption`/`QueryBuilderWithOptions` **NO se borran en PR3** (eso es PR4/Story 1.5) — solo se *desacoplan*; quedan huérfanos pero presentes. FR11 parcial: eliminar de `AbstractDoctrineRepository` los helpers muertos **sin caller** (`addWhereInCaseInsensitive`, `addWhereBetweenDates`, `addWhereBetweenValues`, `sanitizeArray`) preservando el `// why:` de `generateUniqueParameterName`.
- **D-3 (FR12 — puerta abierta, NO bloqueante):** el contrato del puerto expone `save()` **sin** obligar al flush implícito, pero **se mantiene la semántica observable actual** (`save()` sigue haciendo persist+flush para no romper Behat de POST/PUT/DELETE). La frontera transaccional real es decisión separada fuera de alcance.

### Wire — envelope antes/después (verificado en `8b1d728`)

```text
HOY  PaginationMeta::toArray() → {currentPage, pageCount, count, hasMorePages, cursor}   (SearchResponder + PaginatorCursorFactory)
PR3  PaginationMeta::toArray() → {hasNext, hasPrev, count, links:{next, prev}}            (SearchResponder lee del Page; cursores ya codificados por el engine)
```

- `SearchResponder` (`…/Http/Responder/SearchResponder.php`) y `PaginationMeta` (`…/Http/Responder/PaginationMeta.php`) son los dos ficheros del flip del envelope. `PaginatorCursorFactory` queda **sin tocar** (legacy, muere en PR4) pero deja de usarse para Bank.
- `BankSearchController` (`…/Bank/Infrastructure/Controller/BankSearchController.php`, `#[MapQueryString] SearchQuery`) → `SearchQuery::toCriteria()` → `bankSearcher.search(SearchBanksQuery)` → `searchResponder.respond()`. El flip del DTO (Task 4) y del envelope (Task 5) atraviesan esta cadena.
- **`links` relativos:** mismo endpoint, sustituir solo `after`/`before`, preservar `limit`/`sort`/`direction`/`filters[]`/`paginationMode`. **Open-redirect/XSS:** URLs relativas mismo-origen, jamás absolutas; encodear cada segmento (regla de seguridad del repo).

### Contrato de error `invalid-cursor` (verificado)

- `InvalidCursor` (`…/Domain/Search/Exception/InvalidCursor.php`) ya implementa el marker `InvalidSearchCriteria` (→ 422, familia `invalid-search-criteria`) con causas `signature`/`version`/`payload`/`fingerprint` (named constructors). Las 4 comparten el `type` `invalid-cursor`, indistinguibles para el cliente (NFR2).
- `ExceptionResponder` (`…/Http/EventListener/ExceptionResponder.php`, **`PRIORITY = 16`**, path-scoped `/api/*`) + `ProblemDetailsFactory` ya mapean `InvalidSearchCriteria`→422. **No hace falta listener nuevo.** El `SearchExceptionListener` legacy ya fue retirado (AR17 resuelto).
- **Pendiente de PR3:** `invalid-cursor` aún **no figura** en `docs/api-error-contract.md` como `type` propio (solo está la familia `invalid-search-criteria`). Añadir la fila + actualizar `MarkerStatusMapContractTest`; `make php.lint.error-contract` verde (NFR26).

### Anti-patterns prohibidos (del ADR — el review los caza)

- ❌ Cualquier `JsonResponse` manual de error que puentee el pipeline RFC 9457 (NFR26).
- ❌ `skip_null_values` en el responder (un `links.next: null`/`count: null` debe emitirse explícito) (AR20).
- ❌ Usar el `dir` del payload del cursor para decidir navegación o como fallback — es solo integrity binding; la dirección la manda el param wire (AR21).
- ❌ Reintroducir una segunda fuente de verdad de dirección en el `SearchCriteria`/DTO.
- ❌ `OFFSET`/`setFirstResult` en el read-path keyset (muere con el Paginator en PR4).
- ❌ Catch de `InvalidCursor` fuera del pipeline RFC 9457; degradación silenciosa del cursor en el wire (eso era legacy; en PR3 toda invalidez es 422 observable).
- ❌ Links absolutos con host externo en `links.next`/`links.prev` (open-redirect); valores sin encodear.
- ❌ Borrar `Paginator`/bases abstractas en PR3 (eso es PR4) — solo desacoplar.
- ❌ Una segunda implementación de paginación (kernel único, AR11).
- ❌ Romper la encapsulación de la revertibilidad de PR3 (AC #8) — cualquier cambio que toque PR1/PR2 de forma que el revert los rompa.

### Gotchas operativos del repo (de sesiones previas — evitan ciclos de review)

- **`make app.dev` en el worktree reescribe `api/config/reference.php`** — nunca `git add -A` sin revisar; auto-generado.
- **El primary `main` es superficie compartida viva:** al crear esta historia se observó el árbol de `main` en estado parcial por ops concurrentes del usuario. **Trabaja siempre en el worktree de PR3**, nunca en `main`. Verifica HEAD antes de commitear.
- **FrankenPHP vuelca `core.N` (~1GB) en `api/` durante test runs en contenedor** — borrar, jamás commitear.
- **Lint siempre vía `make` desde la raíz** (contenedor dev; un entorno sin ext-bcmath voltea cs-fixer).
- **Psalm `findUnusedCode`:** desacoplar repos puede dejar métodos huérfanos en las bases abstractas. Si mueren en PR4, déjalos; si son de PR3, límpialos. Jamás regenerar baseline sin `var/cache/psalm` limpio.
- **`make php.quality` incluye `php.lint.error-contract`:** PR3 SÍ añade el marker `invalid-cursor` al wire → actualizar `docs/api-error-contract.md` es obligatorio (a diferencia de PR2).
- **Commits:** Conventional Commits (`feat(api): …` / `feat(shared): …`), pre-commit hooks activos; nunca `--no-verify`, nunca amend tras fallo de hook.
- **Migración editable solo en la rama de feature**; una vez mergeada es inmutable. PR3 **no añade migración** (la de índices/collation ya está en PR2, `Version20260610195734`).
- **Protección de `main`:** nunca force-push ni merge sin permiso explícito por-merge del usuario. PR3 se prepara y se detiene; el merge lo decide Sergio.

### Testing

- Tests funcionales nuevos del flip en `api/tests/Functional/…` (Postgres real): envelope cursor-only, 422 `invalid-cursor` por las 4 causas, `after`/`before` mutuamente excluyentes, `dir` discrepante, página vacía (`before` vacía → `hasPrev=true`, fix #3), `links` relativos correctos, `limit` default 25/techo 100.
- PHPUnit 13, atributos `#[Test]`/`#[CoversClass]`/`#[DataProvider]`; `declare(strict_types=1);`; AAA; nombres por comportamiento. Tests del engine **sin** aserciones sobre objetos Doctrine (solo SQL/DQL strings + binds, AR20).
- **Behat:** la migración de `search.feature` al envelope nuevo (simetría next×3/prev×3 con empates masivos, 422 `invalid-cursor`, página vacía → 200) es **Story 1.4** en el mismo PR (extender `search.feature`, no crear feature paralela, AR13). 1.3 solo actualiza fixtures que asumían `limit=1000`.
- Comandos: `make php.unit c='--filter Bank'` / `c='--filter DoctrineSearchEngine'` durante desarrollo; `make php.unit` + `make php.quality` al cierre. Behat verde es gate del PR combinado.

### Stack pineado (no renegociable; leer código existente antes que memoria)

PHP 8.5 · Symfony 8.0 (componentes individuales; `#[When(env)]` para la válvula; `#[MapQueryString]`+`#[Assert]` para el DTO) · Doctrine ORM 3.6 / DBAL 4.4 (`EntityManagerInterface` directo en los repos por composición) · PHPUnit 13 · PostgreSQL 18. **Cero dependencias Composer nuevas; cero migraciones nuevas** (la de PR2 ya cubre índices+collation).

### Project Structure Notes

- Repos por composición: cada repo concreto en su bounded context (`…/Backoffice/Bank/Infrastructure/Persistence/Doctrine/`), implementando solo puertos de dominio, con `EntityManagerInterface` (+ `DoctrineSearchEngine` para los de búsqueda) inyectados.
- FQCNs pineados (AR12, no inventar variantes): `…\Infrastructure\Http\Responder\PaginationMeta` (envelope v2), `Erpify\Shared\Domain\Search\Page`, `…\Domain\Search\Exception\InvalidCursor`, `…\Infrastructure\Persistence\Doctrine\Search\DoctrineSearchEngine`.
- Tests funcionales del flip en `api/tests/Functional/…` (espejo del seam afectado).
- Sin conflictos detectados entre `epics.md`, el ADR, la readiness report y la estructura del repo. La firma del engine se verificó contra el blob de `8b1d728` (no de memoria).

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 1.3] — acceptance criteria de origen; FR1/FR5/FR6/FR9/FR11/FR12/FR13/FR14, AR1/AR6/AR7/AR8/AR9/AR10/AR11/AR12/AR16/AR17/AR20/AR21, NFR2/NFR3/NFR5/NFR26
- [Source: _bmad-output/planning-artifacts/epics.md#FR Coverage Map] — FR9/FR11/FR12 trasladados a PR3 (Story 1.3) por D-1
- [Source: _bmad-output/planning-artifacts/architecture-keyset-pagination.md] — ADR IMPLEMENTATION LOCKED (K2/K6/K9/K10/K11/K13/K14, pipeline, envelope, válvula)
- [Source: _bmad-output/implementation-artifacts/1-2-…-pr2.md] — Story 1.2 (engine off-wire, in-progress→merged en `8b1d728`); firmas del engine; D-1/D-3; Review Findings (los 11 diferidos)
- [Source: _bmad-output/implementation-artifacts/deferred-work.md#Deferred from: code review of story-1.2] — los 11 diferidos; PR3 absorbe #2/#3/#5 (y vigila #4/#9)
- [Source: api/src/Shared/Infrastructure/Persistence/Doctrine/Search/DoctrineSearchEngine.php@8b1d728] — `paginate(...)` (L79), `resolveLimit` (L145, diferido #2), `buildPage` empty branch (L278, diferido #3), `qualify` (L344, #9), `entityName` (L378, #10)
- [Source: api/src/Shared/Infrastructure/Http/Responder/SearchResponder.php + PaginationMeta.php] — envelope legacy a flipear
- [Source: api/src/Shared/Application/Http/Search/SearchQuery.php + api/src/Shared/Domain/Search/SearchCriteria.php] — DTO + criteria (page/limit/cursor → after/before/limit 25/100)
- [Source: api/src/Shared/Domain/Search/Exception/InvalidCursor.php + docs/api-error-contract.md] — marker `InvalidSearchCriteria`→422; falta la fila `invalid-cursor`
- [Source: api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/DoctrineBankRepository.php] — repo a composición; `searchFieldMap`/`sortFieldMap` a preservar
- [Source: docs/project-context.md] — reglas PHP/Doctrine/testing/seguridad/workflow del repo

## Decisiones confirmadas (Sergio) — selladas, no re-litigar

- **D-1 (2026-06-11):** FR9/FR11(parcial)/FR12 (repos por composición) se trasladaron de PR2 a **PR3 (esta historia)** — la composición solo cobra sentido cuando el engine entra al runtime path. Reasignado en `epics.md`.
- **D-3 (2026-06-10):** `save()` mantiene la semántica observable actual (persist+flush) para no romper Behat; el **contrato del puerto** se relaja para no obligar al flush implícito (FR12 puerta abierta). La frontera transaccional real es decisión separada no bloqueante.
- **Emparejamiento 1.3+1.4 (AR16):** mismo worktree/PR3. La migración de Behat al envelope nuevo es Story 1.4. El gate de cierre de 1.3 es estático; Behat verde es del PR combinado.
- **Diferidos de PR2 (decisión de este story-create, 2026-06-11):** `deferred-work.md` se conserva como red de seguridad transversal. PR3 absorbe #2 (`resolveLimit` default 25), #3 (`before` vacía `hasNext`), #5 (dirección de índice → perf gate) y vigila #4/#9. Los standalone (#1/#6/#7/#8/#10/#11) permanecen en el registro. Cuando PR3 resuelva uno, se **borra su bullet** del registro (convención del repo).

### Decisiones de ejecución del flip (Sergio, 2026-06-11 — selladas en dev-story, no re-litigar)

- **OQ-1 — contrato del puerto = `Page`.** `BankSearchRepository::search()` evoluciona de `PaginatedResult` a `Page<Bank>`; `SearchResponder` lee del `Page` directamente (firstItem/lastItem fuera del puerto, alinea FR6/FR10/NFR5). El engine produce `Page` puro; el puerto lo expone sin envelope.
- **OQ-2 — el default 25 vive en el DTO/adaptador (opción a).** `SearchQuery.limit` default = `SearchCriteria::DEFAULT_LIMIT` (25); el engine `resolveLimit()` queda como clamp puro sin tocar. Consts canónicas (25/100) en `SearchCriteria` (Domain), que el DTO lee (layering-correct); `WirePaginationPolicy` (Infra) las espeja.
- **Legacy huérfano = Opción A (válvula legacy env-gated como shim de reachability estática).** `findUnusedCode=true` + `findUnusedBaselineEntry=true` hacen incompatible "desacoplar Bank + conservar legacy + sin baseline nueva". La válvula `pagination_mode=legacy` (`#[When(dev/staging)]`) mantiene el cluster legacy (`AbstractDoctrineSearchRepository`/`Paginator`/`PaginatorCursorFactory`/`QueryBuilderWithOptions`/`PaginatorOption`) **referenciado estáticamente** → Psalm verde sin baseline ni borrado; runtime de negocio (prod) intacto; revert de PR3 borra los ficheros nuevos y restaura legacy (AC#8). La válvula entra en el **mismo gate** del flip (no es paso posterior). Regla de facto: "un subgrafo legacy desreferenciado por refactor pero retenido por contrato de revert se preserva en el adapter layer, no en el baseline".
- **`before` vacía = Opción A: `hasNext=true, hasPrev=false`** (derivado de la fórmula direccional, no caso especial). Invariante para 1.4: empty directional page es forward-recoverable only. La prosa de AC#5/W7 ("hasPrev=true") es mislabel → se corrige en docs.
- **Fork 1 — links desde el DTO validado.** `SearchResponder::respond(Page, SearchQuery, routeName, groups)`; compone los links desde el `SearchQuery` validado (no `RequestStack`), sustituyendo solo el cursor, vía `UrlGeneratorInterface` (encode + relativo). Ownership limpio (engine→cursores, Application→estado validado, responder→composición HTTP), tests deterministas.
- **Fork 2 + W10 (NUEVO invariante sellado, añadir al execution contract).** El engine acuña un recovery cursor para el caso vacío. **W10 — Linkability: `hasNext ⇒ nextCursor != null`; `hasPrev ⇒ prevCursor != null`** (no el converso). Elimina por construcción `{hasNext:true, links.next:null}`. El responder gatea links por flag; W10 garantiza cursor presente cuando el flag es true. El cliente consume links verbatim, nunca reconstruye. W1/W2/W9 intactos.
- **API CONTRACT FREEZE de PR3 (Sergio, 2026-06-11) — STOP feature development en 1.3.** El core del wire (engine → `Page` → `SearchResponder` → `links` → tests → revertibilidad) está cerrado, verificado contra Postgres y revert-probado. Superficie congelada (inmutable sin reabrir el ADR): `Page`, `DoctrineSearchEngine`, `SearchResponder`, `PaginationMeta`, semántica de cursor, DTO after/before/limit, y las decisiones OQ-1/OQ-4(W9)/W2/W10/AC#5-W7. Solo dos direcciones permitidas a partir de aquí: **A** observability/tooling (Task 12) y **B** consumer adaptation (Story 1.4). Task 10 (seam) diferido fuera de PR3. Contrato formal sellado en `pr3-execution-contract.md` §7 (frozen surface + DoD + change-control).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (dev-story)

### Debug Log References

- **Task 0 — entorno y baseline (2026-06-11):**
  - Worktree compartido PR3: `make worktree.create BRANCH=feat/api-keyset-pagination` → `.claude/worktrees/api-keyset-pagination-8rho` (branch `feat/api-keyset-pagination-8rho`, base main `64e8145`). `make app.dev` → todos los contenedores `erpify-api-keyset-pagination-8rho-*` healthy.
  - Artefactos bmad de PR3 (story 1.3, story 1.4, `pr3-execution-contract.md`, `sprint-status.yaml`) trasladados al worktree (estaban untracked en el primary `main`; un worktree off-main no los hereda). `sprint-status.yaml`: `1-3 → in-progress`.
  - Base PR2 (`8b1d728`, ancestro de main): `DoctrineSearchEngine`, `RowUniquenessGuard`, `WirePaginationPolicy` presentes; migración `api/migrations/2026/Version20260610195734.php`; `make php.unit c='--filter Keyset'` → **76 tests / 180 asserts OK**.
  - Baseline Behat page-based: requirió `make php.behat.install` (vendor de `api/tools/behat` ausente en worktree fresco) → `make php.behat` **116 escenarios / 773 steps OK** sin tocar código.
  - **Checkpoint de control (solicitado por Sergio): el engine es probable sin instanciar `SearchResponder`.** Verificado estructuralmente: `grep` sobre `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/` → cero referencias a `SearchResponder`/`Http`/`Responder`/`JsonResponse`; ningún test de `…/Search/Keyset/` instancia `SearchResponder` (la única referencia a `SearchResponder` en tests vive en `NativeJsonEncodeContractTest`, ajeno al engine); las 76 pruebas keyset corren sin tocar la capa HTTP. El engine compone solo colaboradores kernel y devuelve `Page` (cursores opacos), conforme al modelo de ownership OQ-4/W9.

### Completion Notes List

- ✅ **Task 0 completado.** Entorno aislado listo (worktree compartido 1.3+1.4), base PR2 verificada verde, baseline page-based Behat verde, relectura del código load-bearing completada (no implementar de memoria). Modelo C / OQ-4 confirmado en código: `Page` link-agnóstico (cursores opacos `nextCursor`/`prevCursor`), engine sin acoplamiento HTTP. Diferidos #2 (`resolveLimit` L145) y #3 (`buildPage` empty L278) localizados para Task 6. Guardas de no-drift de PR3 (sin helpers URL / DTOs links-builder / route injection en engine / cursor decoding en cliente / convenience mapping en repos) registradas como criterio de review.
- ✅ **Task 4 completado** (Domain/Application, sin huérfanos — el cluster legacy sigue alcanzable hasta el flip del repo). Nuevo VO `NavigationDirection` (Domain). `SearchCriteria`: fuera `page`/`MAX_PAGE`, dentro `routingDirection` + consts `DEFAULT_LIMIT=25`/`MAX_LIMIT=100`. `SearchQuery`: `after`/`before` excl. (Callback), `limit` 25/100, fuera `page`/`cursor`. `InvalidPagination::pageOutOfRange` eliminado (huérfano). `AbstractDoctrineSearchRepository::getPaginatedResults` desacoplado de `$criteria->page` (param explícito). **`make php.stan` verde; unit `SearchCriteria|SearchQuery` 37 verdes.**
- ✅ **Task 6 #2/#3 cerrados** (engine). #2 resuelto por OQ-2(a) (sin tocar el engine). #3: `buildPage` empty branch corregido a la fórmula direccional. **Keyset suite 76 verdes, stan verde.** _Pendiente test directo focalizado del vacío (con los funcionales del flip)._
- ✅ **Tasks 1/2/3/5 completados** (capa de repos + puerto + handler + responder + envelope). `DoctrineBankRepository` y `DoctrineBankAccountRepository` por composición (`EntityManagerInterface`; Bank delega en `DoctrineSearchEngine`, devuelve `Page<Bank>`). Puerto `BankSearchRepository::search(): Page` + `BankSearcher`. `Page<T>` → `@template-covariant`. `PaginationMeta` v2 `{hasNext,hasPrev,count,links:{next,prev}}` (sin skip_null_values). `SearchResponder` v2 = **único compositor**: lee del `Page`, compone `links` relativos vía `UrlGeneratorInterface` desde el `SearchQuery` validado (Fork 1), gateados por flag; **`PaginatorCursorFactory` fuera del dataflow del default path**. Controller pasa `$query` + `ROUTE_NAME`. **PHPStan verde · 796 unit verdes.**
- ✅ **Fork 2 / W10 (engine):** recovery cursor en el caso vacío (`reencodeCursor` re-firma los valores del cursor inbound con dirección opuesta). Invariante **W10 — Linkability: `hasNext ⇒ nextCursor != null`, `hasPrev ⇒ prevCursor != null`** (no el converso). Coste aceptado: boundary exclusivo → recovery aterriza justo pasada la fila frontera (documentar). Keyset 76 verdes.
- ✅ **GATE ESTÁTICO DE 1.3 CERRADO** (Sergio lo declara cerrado). `make php.quality` **EXIT=0** (stan + psalm + error-contract lint + phpcs + phpmd + cs-fixer + behat feature-lint + mapping) · **796 unit verdes** (3748 asserts). Shim de reachability resuelto vía válvula (A-lean → Opción 2 runtime-guarded). Baseline +2 entradas (FP estructural de DI, regla de repo). `validateCursorsAreMutuallyExclusive` fusionado en `validateFilterIndexes` (sin entrada de baseline nueva).
  - **Cleanup legacy (FR11 + cascada, Opción 1):** borrados de `AbstractDoctrineRepository` (sin caller post-flip): `persist`/`flush`/`persistAndFlush`/`removeAndFlush`/`addLimit`/`addWhere*`×3/`sanitizeArray`/`generateUniqueParameter`/`generateUniqueParameterName`; `// why:` (param-names estables → disco SQL cache) reubicado al docblock de la clase. `InvalidPagination::pageOutOfRange` borrado.
  - **Cluster legacy de lectura VIVO (sin baseline, borrado=PR4):** `AbstractDoctrineSearchRepository`/`Paginator`/`PaginatorCursor`/`PaginatorCursorFactory`/`PaginatedResult` (los 5 accessors, `getCurrentPage`/`getPageCount` vía log de fallback) referenciados por `LegacyBankSearchRepository`.
  - **Válvula = `PaginationModeBankSearchValve`** `#[AsDecorator(BankSearchRepository)]`, registrada en todos los envs (Psalm no ve `#[When]`), **guard runtime fail-closed** (legacy solo en dev/staging + `pagination_mode=legacy`; resto → keyset). Único compositor de envelope sigue siendo `SearchResponder` (W9 intacto). **Borrar entera en PR4 (tarea explícita).**
  - **Hallazgo de tooling sellado como regla de repo:** Psalm sin `<containerXml>` ⇒ toda clase de servicio DI es `UnusedClass`/`__construct`-FP (ya baselined: DoctrineBankRepository, DoctrineBankAccountRepository, PaginatorCursorFactory::__construct, DoctrineConnectionResetListener). El +2 es el MISMO FP, no deuda del flip.
- ✅ **Task 15 (funcionales del flip) + Task 9 (modos) — PROOF-OF-WIRE verde contra Postgres real.** `BankSearchCursorFunctionalTest` (8 tests / 453 asserts): envelope cursor-only sin page-numbers, navegación forward/backward por `links.*` **verbatim** (round-trip), `after`×`before` → 422 `validation-failed`, cursor basura → 422 `invalid-cursor`, `dir` discrepante → 422 `invalid-cursor`, limit default 25 / techo 100 / <1 → 422, DETAILED `count` poblado vs LIGHT `null`, **empty-before en hueco borrado → 200 `items:[]`, `hasNext=true`/`hasPrev=false`, `links.next` presente (W10), `links.prev` null** (W7). Navegación solo por `links` + cursor opaco (W2/W9 honrado en los tests).
  - 🐞 **Bug real cazado por el proof-of-wire (engine, PR2 latente):** `countIfDetailed` pasaba el nombre CORTO (`entityName`) a `getClassMetadata` → MappingException → 500 en modo DETAILED. DETAILED nunca se ejercía contra un EM real off-wire. **Fix:** nuevo `rootEntity()` (FQCN) para el lookup; `entityName()` (corto) sigue para la cadena canónica del trace. Keyset suite 76 verdes (sin regresión).
  - `make php.quality` EXIT=0 · full unit **804 verdes** (4201 asserts).
- ✅ **Task 14 (docs obligatorios AR18 + FR14) completado.** 4 docs durables actualizados al sistema **en ejecución hoy** (no diseño aspiracional): `docs/architecture-api.md` (read-path flow flipeado a la cadena Bank real; subsección engine retitulada "on the HTTP wire (PR3)"; nueva subsección "Cursor-only wire envelope (PR3) — engine/responder boundary" con frontera W9, contrato físico cursor-only, `Page` como unidad semántica, W10 Linkability, corrección AC#5/W7 `before`-vacía=`hasNext=true`, 422 `invalid-cursor`, válvula como compat-layer reversible, FR14; 7 anti-patterns cursor-only); `api/docs/adding-endpoints.md` (skeleton reescrito al patrón composición+`SearchResponder`, bullets de ordering/keyset flipeados, nueva subsección "Cursor-only navigation wire" + FR14, `MAX_PAGE`→`DEFAULT_LIMIT`/`MAX_LIMIT`); `docs/api-error-contract.md` (`invalid-cursor` ya cubierto en Task 7 — verificado, `make php.lint.error-contract` verde); `docs/source-tree-analysis.md` (línea Search del quick-reference). FR14 (no-snapshot + garantías reales) documentado en arquitectura **y** endpoint contract. Runbook `docs/runbooks/cursor-pagination.md` es alcance de Story 1.4 (cuelga de la observabilidad), no de Task 14. **Sin cambios de código — solo docs.**

### File List

_Bookkeeping (Task 0):_
- `_bmad-output/implementation-artifacts/1-3-…pr3-lado-api.md` (trasladado + progreso)
- `_bmad-output/implementation-artifacts/1-4-…pr3-lado-consumidor.md` (trasladado)
- `_bmad-output/implementation-artifacts/pr3-execution-contract.md` (trasladado)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (trasladado + `1-3 → in-progress`)

_Código — Task 4 + Task 6 (Domain/Application/engine):_
- `api/src/Shared/Domain/Search/NavigationDirection.php` **(nuevo)** — VO dirección de navegación
- `api/src/Shared/Domain/Search/SearchCriteria.php` — fuera page/MAX_PAGE; routingDirection + DEFAULT_LIMIT/MAX_LIMIT
- `api/src/Shared/Domain/Search/Exception/InvalidPagination.php` — eliminado `pageOutOfRange`
- `api/src/Shared/Application/Http/Search/SearchQuery.php` — after/before excl. + limit 25/100; fuera page/cursor
- `api/src/Shared/Infrastructure/Persistence/Doctrine/AbstractDoctrineSearchRepository.php` — `getPaginatedResults` desacoplado de `$criteria->page` (param explícito)
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/DoctrineSearchEngine.php` — `buildPage` empty branch (fix #3)
- `api/tests/Unit/Shared/Domain/Search/SearchCriteriaTest.php` — routingDirection + default-limit; fuera page
- `api/tests/Unit/Shared/Application/Http/Search/SearchQueryTest.php` — after/before/XOR/direction; fuera page

_Código — Tasks 1/2/3/5 (flip de repos + responder):_
- `api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/DoctrineBankRepository.php` — composición + delega en engine, `search(): Page<Bank>`
- `api/src/Backoffice/BankAccount/Infrastructure/Persistence/Doctrine/DoctrineBankAccountRepository.php` — composición
- `api/src/Backoffice/Bank/Domain/Repository/BankSearchRepository.php` — puerto → `Page<Bank>`
- `api/src/Backoffice/Bank/Application/BankSearcher.php` — → `Page<Bank>`
- `api/src/Backoffice/Bank/Infrastructure/Controller/BankSearchController.php` — pasa `$query` + `ROUTE_NAME`
- `api/src/Shared/Domain/Search/Page.php` — `@template-covariant`
- `api/src/Shared/Infrastructure/Http/Responder/PaginationMeta.php` — envelope v2
- `api/src/Shared/Infrastructure/Http/Responder/SearchResponder.php` — único compositor (Page→envelope+links), sin `PaginatorCursorFactory`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/DoctrineSearchEngine.php` — recovery cursor empty (W10) + fix #3
- `api/tests/Unit/Shared/Infrastructure/Http/Responder/PaginationMetaTest.php` — envelope v2

_Código — válvula/shim + cleanup legacy + baseline (gate estático):_
- `api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/LegacyBankSearchRepository.php` **(nuevo)** — shim de reachability + fallback legacy (devuelve `Page`, log de page-meta)
- `api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/PaginationModeBankSearchValve.php` **(nuevo)** — decorator `#[AsAlias→AsDecorator]` con guard runtime fail-closed
- `api/src/Shared/Infrastructure/Persistence/Doctrine/AbstractDoctrineRepository.php` — stripped (FR11 + cascada); `// why:` reubicado
- `api/tools/psalm/psalm-baseline.xml` — **+2** entradas (FP DI: valve UnusedClass, legacy `__construct`); −bloque stale de `AbstractDoctrineRepository`

_Código — Task 15 (proof-of-wire) + fix de bug:_
- `api/tests/Functional/Backoffice/Bank/Infrastructure/Controller/BankSearchCursorFunctionalTest.php` **(nuevo)** — 8 tests funcionales del flip contra Postgres real
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/DoctrineSearchEngine.php` — fix DETAILED `countIfDetailed` (FQCN vía nuevo `rootEntity()`)

_Docs — Task 14 (solo documentación, sin código):_
- `docs/architecture-api.md` — read-path flow flipeado; subsección engine "on the HTTP wire (PR3)"; nueva subsección "Cursor-only wire envelope (PR3)" (frontera W9, contrato físico, `Page` semántico, W10, AC#5/W7 corregido, válvula, FR14); anti-patterns cursor-only; línea de plumbing de búsqueda → `SearchResponder`
- `api/docs/adding-endpoints.md` — skeleton reescrito (composición + `SearchResponder`, sin `AbstractSearchController`); ordering/keyset bullets flipeados; nueva subsección "Cursor-only navigation wire" + FR14; `MAX_PAGE`→`DEFAULT_LIMIT`/`MAX_LIMIT`
- `docs/source-tree-analysis.md` — línea `…/Doctrine/Search/` del quick-reference (engine en runtime, `Page` link-agnóstico, `SearchResponder` compositor, legacy tras válvula)
- `docs/api-error-contract.md` — `invalid-cursor` (añadido en Task 7; verificado verde, sin cambios en Task 14)

## Change Log

| Fecha       | Versión | Descripción                                                                                          | Autor          |
|-------------|---------|------------------------------------------------------------------------------------------------------|----------------|
| 2026-06-11  | 0.1     | Creación del contexto de la Story 1.3 (PR3 lado API: flip del wire cursor-only + repos por composición). Absorbe los diferidos PR3-bound del review de PR2 (#2/#3/#5; vigila #4/#9). | create-story   |
| 2026-06-11  | 0.2     | Task 0: worktree compartido PR3 `api-keyset-pagination-8rho` + stack up; base PR2 verde (76 keyset tests); baseline Behat verde (116 esc.); relectura load-bearing; checkpoint engine-sin-SearchResponder verificado. | dev-story      |
| 2026-06-11  | 0.3     | Decisiones selladas (OQ-1 puerto→Page; OQ-2 default 25 en DTO; Opción A válvula=shim reachability; before-vacía hasNext=true/hasPrev=false). Task 4 (DTO/criteria/NavigationDirection) + Task 6 #2/#3 (engine) implementados — stan verde, unit verde (37 + 76 keyset). | dev-story      |
| 2026-06-11  | 0.4     | Tasks 1/2/3/5: flip de repos a composición (Page), puerto/handler→Page, `SearchResponder` v2 único compositor + `PaginationMeta` v2, controller, `Page` covariante, recovery cursor empty (Fork 2 + W10). PHPStan verde, 796 unit verdes. Psalm rojo (cluster legacy huérfano) → pendiente shim de reachability (válvula). | dev-story      |
| 2026-06-11  | 0.5     | **GATE ESTÁTICO 1.3 CERRADO.** Task 11 válvula (Opción 2 runtime-guarded, fail-closed) + `LegacyBankSearchRepository`; cleanup legacy FR11+cascada en `AbstractDoctrineRepository`; baseline +2 FP-DI (regla de repo). `make php.quality` EXIT=0, 796 unit verdes. Pendiente: Task 7/8 (invalid-cursor contract), 9/10/12/13/14/15. | dev-story      |
| 2026-06-11  | 0.6     | Task 7/8 (contrato `invalid-cursor` doc + pipeline) + Task 9 (modos) + **Task 15 PROOF-OF-WIRE** (`BankSearchCursorFunctionalTest`, 8 tests contra Postgres real: envelope, 422 cursor/excl/dir, limit 25/100, DETAILED/LIGHT, empty-before-gap). 🐞 cazó+arregló bug del engine DETAILED `getClassMetadata` (short-name→500). `php.quality` EXIT=0, **804 unit verdes**. Pendiente: 10 (seam), 12 (perf), 13 (revert), 14 (docs), Behat=1.4. | dev-story      |
| 2026-06-11  | 0.7     | **Task 13 revertibilidad (AC#8) PROBADA** estructural (auditoría inverse-diff: encapsulación, cero contaminación, cluster legacy íntegro, PR1/PR2 tocado solo en Page+engine localizado) + empírica (revert simulado vía stash → Behat page-based 116/773 verde + keyset 76 verde en estado revertido; PR3 restaurado verde). Pendiente: 10 (seam), 12 (perf staging), 14 (docs), Behat=1.4. | dev-story      |
| 2026-06-11  | 0.8     | **Task 14 docs (AR18 + FR14)** — 4 docs durables flipeados al sistema en ejecución hoy: `architecture-api.md` (read-path Bank, engine on-wire, subsección "Cursor-only wire envelope": frontera W9, contrato físico, `Page` semántico, W10, AC#5/W7 corregido, válvula reversible, FR14, anti-patterns), `adding-endpoints.md` (skeleton composición+`SearchResponder`, "Cursor-only navigation wire", FR14, sin `MAX_PAGE`), `source-tree-analysis.md`, `api-error-contract.md` (invalid-cursor verificado verde). Solo docs. Pendiente: 10 (seam), 12 (perf staging), 15 (gate cierre), Behat=1.4. | dev-story      |
| 2026-06-11  | 0.9     | **API CONTRACT FREEZE (Sergio) — STOP feature dev en 1.3.** Contrato wire lado API funcionalmente completo y congelado; freeze spec formal sellado en `pr3-execution-contract.md` §7 (superficie congelada + DoD + change-control). **Task 10 (seam "ir a fecha") DIFERIDO** fuera de PR3 (riesgo W9/W10) → `deferred-work.md`. Solo dos direcciones permitidas: A) observability/tooling (Task 12), B) consumer adaptation (Story 1.4). | dev-story      |
