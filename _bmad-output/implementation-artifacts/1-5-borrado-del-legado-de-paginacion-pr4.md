---
baseline_commit: fcce3629e66e15bc9f7592f1b9a6a26af2fc04f8
---

# Story 1.5: Borrado del legado de paginación (PR4)

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a desarrollador de ERPify,
I want eliminar todo el aparato legacy de paginación (Paginator, cursores mutables, bases abstractas, válvula de transición y el repositorio-shim que la sostiene) sin ningún cambio de comportamiento observable,
so that quede un único kernel keyset, muera Sonar `php:S1448` estructuralmente, desaparezca el modelo de páginas numeradas también del código, y PR3 quede demostrado revertible sin tocar este PR.

## Acceptance Criteria

**AC1 — Borrado del aparato legacy (FR10/FR11 total, AR8)**

**Given** el árbol `api/src/`
**When** la historia se completa
**Then** se eliminan los 12 ficheros del cluster legacy: `Paginator.php`, `PaginatorCursor.php`, `PaginatorCursorInterface.php`, `PaginatorCursorFactory.php`, `QueryBuilderWithOptions.php`, `PaginatorOption.php`, `PaginatedResult.php`, `SearchCursor.php` (el VO viejo de `Domain/Search`, no la familia `Keyset/Cursor`), `AbstractDoctrineSearchRepository.php`, `AbstractDoctrineRepository.php`, la válvula de transición (`PaginationModeBankSearchValve.php` — el fichero que el ADR/epics nombra como `LegacyPaginationValve`) y su único subtipo vivo `LegacyBankSearchRepository.php`
**And** no queda ninguna referencia a los símbolos borrados (grep limpio en `api/src`, `api/tests`, `api/config`, `api/tools/behat` y `docs/` + compilación verde)
**And** el `why` del naming estable de parámetros (caché SQL de Doctrine, FR11) sigue documentado y vivo en `Search/FilterApplier.php` tras borrar `AbstractDoctrineRepository`.

**AC2 — Quality gate sin supresiones ni baselines (NFR4)**

**Given** el quality gate
**When** corre el análisis
**Then** Sonar `php:S1448` queda resuelto estructuralmente (no suprimido) — muere con `Paginator.php` — y las supresiones PHPMD asociadas al cluster legacy se eliminan junto a sus ficheros (no quedan `@SuppressWarnings` huérfanas ni entradas de baseline nuevas)
**And** `make php.stan` + `make php.quality` quedan verdes sin baselines nuevas.

**AC3 — Suite verde, PR puramente sustractivo (AR16) + docs (AR18)**

**Given** la suite completa
**When** corre CI
**Then** los escenarios Behat de `search.feature` (ya migrados a cursor-only en Story 1.4) y los tests funcionales/unitarios del kernel keyset pasan **sin modificación** — el diff de PR4 contiene sólo eliminaciones de código legacy + actualizaciones de documentación, y PR3 seguiría siendo revertible sin tocar este PR
**And** `docs/source-tree-analysis.md`, `docs/claude-code-quickref.md`, `docs/architecture-api.md`, `docs/architecture-pwa.md`, `api/docs/adding-endpoints.md` y `pwa/docs/` reflejan el directorio `Keyset/` como único sistema de paginación, sin menciones a las bases eliminadas ni a la válvula/`pagination_mode`.

## Tasks / Subtasks

- [x] **Task 0 — Preparar worktree y baseline verde** (AC: #3)
  - [x] Regla dura del repo: ejecutar este PR en un worktree aislado, nunca en `main`. Crear con `make worktree.create BRANCH=feat/shared-borrado-legado-paginacion` (base `main`), y arrancar el stack ahí (`make app.dev` o `START=true`).
  - [x] Confirmar baseline pre-borrado verde dentro del worktree: `make php.stan`, `make php.quality`, `make php.behat`. Si algo está rojo en `main` antes de tocar nada, HALT y reportar (no es regresión de PR4).

- [x] **Task 1 — Borrar la válvula y el repositorio-shim de Bank** (AC: #1)
  - [x] Borrar `api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/PaginationModeBankSearchValve.php` (decorador `#[AsDecorator(decorates: BankSearchRepository::class)]`). Al retirarlo, `BankSearchRepository` debe resolver directamente a `DoctrineBankRepository` (la implementación por composición de PR3).
  - [x] **Verificar el binding antes de seguir:** confirmar que `DoctrineBankRepository` es la única implementación autowired de `BankSearchRepository` una vez sin decorador (revisar `api/config/services*.yaml` y atributos). Probar con `make sf c='debug:container BankSearchRepository'` o equivalente. Si hubiera ambigüedad, resolver el wiring en este task.
  - [x] Borrar `api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/LegacyBankSearchRepository.php` (único subtipo vivo de `AbstractDoctrineSearchRepository`; consume `PaginatorCursorFactory` e instancia indirectamente `Paginator`).
  - [x] `make php.stan` tras este task (verde antes de continuar).

- [x] **Task 2 — Borrar las bases abstractas de repositorio** (AC: #1)
  - [x] Borrar `api/src/Shared/Infrastructure/Persistence/Doctrine/AbstractDoctrineSearchRepository.php` (ya sin subtipos tras Task 1).
  - [x] Borrar `api/src/Shared/Infrastructure/Persistence/Doctrine/AbstractDoctrineRepository.php`.
  - [x] **Antes de borrar `AbstractDoctrineRepository`:** confirmar que el `why` del naming estable de parámetros sigue presente en `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FilterApplier.php` (~líneas 314–321). Ese comentario es la copia viva de producción (FR11); no se pierde conocimiento institucional al borrar la clase. NO tocar `FilterApplier.php`.
  - [x] `make php.stan` tras este task.

- [x] **Task 3 — Borrar el resto del cluster legacy (Shared)** (AC: #1)
  - [x] Borrar `api/src/Shared/Infrastructure/Persistence/Doctrine/Paginator.php` (aquí muere Sonar `php:S1448` y sus 3 `@SuppressWarnings` PHPMD de clase).
  - [x] Borrar `api/src/Shared/Infrastructure/Persistence/Doctrine/QueryBuilderWithOptions.php` y `api/src/Shared/Infrastructure/Persistence/Doctrine/PaginatorOption.php`.
  - [x] Borrar `api/src/Shared/Infrastructure/Persistence/PaginatorCursor.php`, `api/src/Shared/Infrastructure/Persistence/PaginatorCursorInterface.php`, `api/src/Shared/Infrastructure/Persistence/PaginatorCursorFactory.php`.
  - [x] Borrar `api/src/Shared/Domain/Search/PaginatedResult.php` y `api/src/Shared/Domain/Search/SearchCursor.php` (VO viejo; el keyset usa `…/Doctrine/Search/Keyset/Cursor.php`, que NO se toca).
  - [x] `make php.stan` tras este task.

- [x] **Task 4 — Grep de limpieza + supresiones huérfanas** (AC: #1, #2)
  - [x] `grep -rn` de cada símbolo borrado en `api/src api/tests api/config api/tools/behat` → cero resultados (salvo, transitoriamente, los docs que se actualizan en Task 6).
  - [x] Buscar supresiones huérfanas tras el borrado: `grep -rn "PHPMD" api/tools/phpmd*` / baseline PHPMD y cualquier entrada de Sonar (`NOSONAR`, baseline) que apuntara a las clases borradas; eliminarlas. No introducir baseline nueva.
  - [x] Confirmar que NO se ha tocado `PaginatorConfig.php` (reemplazo readonly nuevo de PR2/PR3, se conserva con su `@SuppressWarnings("PHPMD.BooleanArgumentFlag")` propia).

- [x] **Task 5 — Quality gates verdes** (AC: #2, #3)
  - [x] `make php.stan` — verde, sin nuevas violaciones.
  - [x] `make php.quality` — verde, **sin baselines nuevas** (PHPMD incluido).
  - [x] `make php.behat` — los escenarios de `search.feature` (cursor-only, migrados en 1.4) pasan **sin modificación**. Este es el verificador de que PR4 es puramente sustractivo (AR16). Si un escenario Behat hay que tocarlo, HALT: significa que PR4 no es sustractivo y algo se rompió.
  - [x] `make php.unit` — suite unitaria/funcional verde (kernel keyset intacto).

- [x] **Task 6 — Documentación (AR18)** (AC: #3)
  - [x] `docs/source-tree-analysis.md`: actualizar la línea "Search" del quick-reference — `…/Doctrine/Search/Keyset/` es el único kernel de paginación; eliminar referencias a `AbstractDoctrineSearchRepository`/`AbstractDoctrineRepository`/`Paginator`.
  - [x] `docs/architecture-api.md`: remover la "válvula reversible"/`pagination_mode=legacy|cursor_v2` como componente; la arquitectura es keyset-only.
  - [x] `api/docs/adding-endpoints.md`: el esqueleto de endpoint es composición + `SearchResponder` puro keyset; remover cualquier fallback page-based/válvula.
  - [x] `docs/claude-code-quickref.md` y `docs/architecture-pwa.md` + `pwa/docs/server-driven-search.md`: verificar y eliminar cualquier mención residual a modo legacy/page-based (grep muestra que quickref/architecture-pwa pueden no necesitar cambios — solo verificar).
  - [x] Boy-scout (regla CLAUDE.md): `docs/runbooks/cursor-pagination.md` actualizado (sección "legacy fallback" eliminada, rollback reescrito al estado cursor-only). `docs/deep-dive-api-shared-foundation.md` **diferido a follow-up**: contiene secciones enteras (pipeline Paginator, deep-dives por fichero, tablas) describiendo el código borrado — su reescritura es un *mass cleanup* de un fichero fuera de la lista AR18, lo que la regla "No mass cleanup" desaconseja en este PR. Pendiente de issue.
  - [x] Barrer comentarios change-relative / story-IDs introducidos durante el desarrollo antes del commit final (regla de comentarios CLAUDE.md).

## Dev Notes

### Naturaleza del PR (leer primero)

PR4 es **puramente sustractivo y un punto de cierre, no de decisión**. Toda la arquitectura keyset está congelada desde PR2 (engine) y el contrato wire desde PR3 (envelope, validación de cursor, observabilidad). PR4 sólo borra la basura que la válvula retenía por necesidad de rollback. El diff debe contener **únicamente eliminaciones de código legacy + actualizaciones de docs**. Cualquier cambio de lógica activa es señal de que algo se ha desviado → HALT.

> Invariante de verificación (AR16): *"si Behat pasa en 1.4, PR4 es puramente sustractivo"*. Si tienes que modificar un escenario Behat para que pase, no es sustractivo — detente y reporta.

### Reconciliación de nombres (epics ↔ código real)

El ADR/epics nombra la válvula como `Infrastructure/Http/LegacyPaginationValve.php`. **Ese fichero no existe con ese nombre.** La implementación real de PR3 es:

- **Válvula:** `api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/PaginationModeBankSearchValve.php` — decorador `#[AsDecorator(decorates: BankSearchRepository::class)]`, `const PARAM = 'pagination_mode'`, `LEGACY_MODE = 'legacy'`, `LEGACY_ENABLED_ENVS = ['dev','staging']` (fail-closed en prod por construcción, AR8).
- **Shim de alcanzabilidad (no listado explícito en epics pero es BLOQUEANTE):** `api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/LegacyBankSearchRepository.php` — único subtipo vivo de `AbstractDoctrineSearchRepository`. Mientras exista, las bases abstractas y `Paginator` no se pueden borrar. Por eso Task 1 lo elimina antes que las bases.

Ninguno de los dos tiene wiring en `services.yaml` (van por atributos), así que el borrado es limpio una vez retirado el decorador.

### Lista canónica de ficheros a BORRAR (12) y orden

Borrar en este orden mantiene la compilación verde en cada paso:

1. `api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/PaginationModeBankSearchValve.php`
2. `api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/LegacyBankSearchRepository.php`
3. `api/src/Shared/Infrastructure/Persistence/Doctrine/AbstractDoctrineSearchRepository.php`
4. `api/src/Shared/Infrastructure/Persistence/Doctrine/AbstractDoctrineRepository.php`
5. `api/src/Shared/Infrastructure/Persistence/Doctrine/Paginator.php`
6. `api/src/Shared/Infrastructure/Persistence/Doctrine/QueryBuilderWithOptions.php`
7. `api/src/Shared/Infrastructure/Persistence/Doctrine/PaginatorOption.php`
8. `api/src/Shared/Infrastructure/Persistence/PaginatorCursor.php`
9. `api/src/Shared/Infrastructure/Persistence/PaginatorCursorInterface.php`
10. `api/src/Shared/Infrastructure/Persistence/PaginatorCursorFactory.php`
11. `api/src/Shared/Domain/Search/PaginatedResult.php`
12. `api/src/Shared/Domain/Search/SearchCursor.php`

Grafo de dependencias interno (todo es un closure conectado salvo nada externo): `LegacyBankSearchRepository` → (extends) `AbstractDoctrineSearchRepository` → (extends) `AbstractDoctrineRepository`; `AbstractDoctrineSearchRepository` usa `PaginatorCursorFactory`/`PaginatorCursorInterface`/`PaginatorOption`/`QueryBuilderWithOptions` e instancia `Paginator`; `Paginator implements PaginatedResult`, usa `PaginatorCursorInterface`+`PaginatorOption`; `PaginatorCursor implements PaginatorCursorInterface extends SearchCursor`; `PaginatorCursorFactory` crea `PaginatorCursor`. **El viejo `SearchCursor` sólo lo referencian `PaginatorCursorInterface` y `PaginatorCursorFactory`** (ambos legacy) → es borrable; verificado por grep.

### ❌ NO BORRAR (kernel keyset y contrato wire — todo esto es lo que SUSTITUYÓ al legado)

- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/DoctrineSearchEngine.php` — el orquestador del read-path en prod desde PR3.
- `…/Doctrine/Search/Keyset/` completo: `Cursor.php`, `CursorCodec.php`, `FingerprintCanonicalizer.php`, `KeysetPredicateBuilder.php`, `OrderByColumns.php`, `CursorPositionExtractor.php`, `QueryExecutionTrace.php`, `AppliedFilters/AppliedSort/AppliedLimit.php`, `WirePaginationPolicy.php`.
- `…/Doctrine/Search/FilterApplier.php` — colaborador keyset **y** sede viva del `why` de naming estable (FR11). No tocar.
- `PaginatorConfig.php` (reemplazo readonly nuevo, NO es `PaginatorOption`).
- `api/src/Shared/Domain/Search/Page.php` — el puerto que reemplazó a `PaginatedResult`.
- `SearchResponder` + `PaginationMeta` (envelope v2 wire), `InvalidCursor` (+ marker 422), `SearchObservabilityListener` (métricas), `DoctrineBankRepository` (repo por composición de PR3).
- Suites keyset (`…/Search/Keyset/*Test`, `TraceEquivalenceStabilityTest`, `KeysetSqlSnapshotTest`), tests funcionales del flip, y la red Behat de `search.feature` migrada en 1.4.

### Tests legacy

Verificado: **no existen tests unitarios dedicados** a los 12 símbolos (`grep` en `api/tests`/`api/tools/behat` = 0). La cobertura legacy era indirecta (Behat/funcional, ya migrada a cursor-only en 1.4). Por tanto no hay tests que borrar — y ningún test debería romperse (AR16). Si alguno rompe, es un acoplamiento oculto a investigar, no a parchear silenciosamente.

### NFR4 — Sonar `php:S1448` y supresiones

`php:S1448` ("demasiados métodos") vivía en `Paginator.php` (21 métodos). Muere **estructuralmente** al borrar el fichero (no por supresión). Las 3 `@SuppressWarnings` de clase en `Paginator` (`ExcessiveClassComplexity`, `NPathComplexity`, `CouplingBetweenObjects`) + las de método y las de `PaginatorCursorFactory`/`AbstractDoctrineSearchRepository` se van con sus ficheros. Task 4 verifica que no quede ninguna supresión/baseline huérfana apuntando a símbolos inexistentes. Gate: `make php.quality` verde sin baseline nueva.

### Fuera de alcance de PR4 (deferidos pre-existentes — NO tocar aquí)

`deferred-work.md` tiene ítems del *engine keyset* (no del borrado): codec/aridad→422 (línea 46), leak de conexión en `KeysetSqlSnapshotTest` (82), `resolveLimit`/`defaultLimit` inerte (83), `qualify()` por regex (90). Son deuda de PR2/PR3, **ajenos al alcance sustractivo de PR4**. No abrirlos aquí. Las menciones "(story 1.5)" de líneas 29–37 son ítems ya resueltos el 2026-06-07 y mal etiquetados; ignorar.

### Project Structure Notes

- Capas: `Domain → Application → Infrastructure` (dependencias hacia dentro). El borrado no altera la dirección: elimina infra legacy y un VO de dominio (`PaginatedResult`/`SearchCursor`) ya sin consumidores no-legacy.
- Bounded context: la válvula y el shim viven en `Backoffice/Bank/Infrastructure`; el resto en `Shared/`. No hay reach-ins cruzados que romper.
- Comandos siempre desde la raíz vía `make`; ejecutar dentro del worktree (su stack es aislado por `COMPOSE_PROJECT_NAME`).

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 1.5: Borrado del legado de paginación (PR4)] — Story statement + 3 ACs (GWT).
- [Source: _bmad-output/planning-artifacts/epics.md#AR8] — válvula env-gated dev/staging, vida útil PR3→PR4, cero tests propios.
- [Source: _bmad-output/planning-artifacts/epics.md#AR16] — secuencia PR1→PR4; PR4 puramente sustractivo; PR3 revertible sin tocar PR4.
- [Source: _bmad-output/planning-artifacts/epics.md#AR18] — docs obligatorios por PR (PR4: source-tree-analysis, claude-code-quickref, architecture-api/pwa, pwa/docs).
- [Source: _bmad-output/planning-artifacts/epics.md#FR10/FR11] — muerte del Paginator viejo; preservar el `why` del naming estable.
- [Source: _bmad-output/planning-artifacts/architecture-keyset-pagination.md#Estructura de archivos PR4] — listado [D]/[M] del borrado.
- [Source: _bmad-output/implementation-artifacts/1-3-...-pr3-lado-api.md#Cleanup legacy (FR11 + cascada)] — qué ya se limpió en PR3 y qué quedó "VIVO, borrado=PR4".
- [Source: _bmad-output/implementation-artifacts/1-4-...-pr3-lado-consumidor.md] — red Behat migrada a cursor-only (gate de PR4).
- [Source: api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FilterApplier.php] — sede viva del `why` de naming estable (FR11), a preservar.
- [Source: CLAUDE.md] — regla worktree, comentarios sin story-IDs, checks PHP obligatorios, secuencia de docs.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m]

### Debug Log References

- Baseline (pre-borrado, dentro del worktree): `make php.stan` verde (355 archivos). Behat tools instalados (`php.behat.install`) porque PHPStan bootstrapea el árbol Behat.
- PHPStan tras cada task: 353 (Task 1) → 351 (Task 2) → 343 (Task 3, tras borrar los 12 ficheros) — siempre verde.
- DI verificada tras quitar el decorador: `debug:container BankSearchRepository` → resuelve a `DoctrineBankRepository` (args: EntityManager + DoctrineSearchEngine + normalizers). Contenedor compila limpio.

### Completion Notes List

- **Borrado de los 12 ficheros del cluster legacy** en orden topológico (válvula → shim → bases → resto) manteniendo compilación verde por paso. Net: ~1306 líneas eliminadas, 0 lógica nueva.
- **Reconciliación de nombres confirmada en código:** la "válvula" del epics (`LegacyPaginationValve`) es en realidad `PaginationModeBankSearchValve` (decorador `#[AsDecorator]`); el bloqueante no listado `LegacyBankSearchRepository` (único subtipo vivo de `AbstractDoctrineSearchRepository`) también se borró. Tras retirar ambos, `DoctrineBankRepository` queda como único implementador de `BankSearchRepository` → auto-alias limpio (verificado por `debug:container`).
- **FR11 preservado:** el `why` del naming estable de parámetros (caché SQL de Doctrine) vive en `FilterApplier::uniqueParameterName()`. Al borrar `AbstractDoctrineRepository`, el comentario de `FilterApplier` referenciaba la clase borrada → lo reescribí auto-contenido (mantiene el *why*, sin referencia colgante).
- **NFR4 resuelto estructuralmente:** Sonar `php:S1448` muere con `Paginator.php`; las `@SuppressWarnings` PHPMD del cluster se van con sus ficheros. `make php.quality` (PHPMD incl.) → **0 violaciones, sin baselines nuevas**. `PaginatorConfig.php` (reemplazo readonly) NO tocado.
- **AR16 verificado — PR4 puramente sustractivo:** `make php.behat` → **117/117 escenarios, 819/819 steps sin modificar**; `make php.unit` → **876 tests, 4446 assertions, 0 fallos**; `make php.stan` → 343 archivos verde.
- **Limpieza de docblocks colgantes (boy-scout):** 6 ficheros vivos (`DoctrineBankRepository`, `SortFieldMap`, `FilterApplier`, `CursorPositionExtractor`, `CursorCodec`, `DoctrineSearchEngine`) tenían comentarios `{@see}`/change-relative apuntando a clases borradas o afirmaciones ya falsas ("la legacy aún sirve el wire") → reescritos a la verdad actual, sin marcas change-relative ni story-IDs.
- **Docs AR18:** `source-tree-analysis.md`, `architecture-api.md` (párrafo de la válvula + diagrama + receta), `adding-endpoints.md`, `runbooks/cursor-pagination.md` (sección "legacy fallback" eliminada, rollback reescrito, secciones renumeradas 1–6) actualizados a keyset-only. `claude-code-quickref.md`, `architecture-pwa.md`, `pwa/docs/` verificados — sin menciones legacy (la PWA quedó cursor-only en 1.4), sin cambios.
- **Diferido a follow-up (consciente):** `docs/deep-dive-api-shared-foundation.md` contiene secciones enteras describiendo el código borrado (pipeline Paginator, deep-dives por fichero, tablas). Su reescritura es un *mass cleanup* de un fichero fuera de la lista AR18 → diferido (regla CLAUDE.md "No mass cleanup"). Recomendado abrir issue.
- `api/config/reference.php` (auto-generado, reescrito por el boot del stack) restaurado a HEAD — fuera del diff.

### File List

**Borrados (12):**
- `api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/PaginationModeBankSearchValve.php`
- `api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/LegacyBankSearchRepository.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/AbstractDoctrineSearchRepository.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/AbstractDoctrineRepository.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Paginator.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/QueryBuilderWithOptions.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/PaginatorOption.php`
- `api/src/Shared/Infrastructure/Persistence/PaginatorCursor.php`
- `api/src/Shared/Infrastructure/Persistence/PaginatorCursorInterface.php`
- `api/src/Shared/Infrastructure/Persistence/PaginatorCursorFactory.php`
- `api/src/Shared/Domain/Search/PaginatedResult.php`
- `api/src/Shared/Domain/Search/SearchCursor.php`

**Modificados (código — limpieza de docblocks colgantes):**
- `api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/DoctrineBankRepository.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FilterApplier.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/SortFieldMap.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/DoctrineSearchEngine.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/CursorCodec.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/CursorPositionExtractor.php`

**Modificados (docs):**
- `docs/source-tree-analysis.md`
- `docs/architecture-api.md`
- `api/docs/adding-endpoints.md`
- `docs/runbooks/cursor-pagination.md`

**Tracking:**
- `_bmad-output/implementation-artifacts/1-5-borrado-del-legado-de-paginacion-pr4.md` (esta historia)
- `_bmad-output/implementation-artifacts/sprint-status.yaml`

## Change Log

| Fecha | Cambio |
|-------|--------|
| 2026-06-12 | PR4: borrado del aparato legacy de paginación (12 ficheros), limpieza de docblocks colgantes en 6 ficheros vivos, docs AR18 a keyset-only. Gates verdes: PHPStan 343, PHPMD 0/0 (NFR4), Behat 117/819 sin modificar (AR16), PHPUnit 876. Deep-dive doc diferido a follow-up. |
