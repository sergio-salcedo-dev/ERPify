---
baseline_commit: f1ed8545770b98f0bb6f27e8866f42faa2c952e3
---

# Story 1.5: Camino único `filters[]` en Bank — retirada del wire legacy (D8 + fase contract adelantada)

Status: done

_Ultimate context engine analysis completed — comprehensive developer guide created (2026-06-07)._

> **Pivote de alcance (2026-06-07, decisión de usuario):** la historia arrancó con el alcance D8 original
> (mapear `names[]`/`ids[]` internamente a `Filters` manteniendo el wire — Parallel Change expand). A mitad de
> implementación, Sergio decidió: _"I don't want to keep legacy filters — the code is not deployed yet in
> production environment"_. Sin despliegue y sin consumidores reales (la PWA filtra client-side), la fase
> *contract* se adelantó a esta historia: los params legacy se **retiran del wire** en lugar de mapearse.
> Decisiones adicionales fijadas por el usuario: `ids[]` se elimina también del contrato base (vocabulario
> único `filters[]`) y los ACs de esta historia se reescriben al nuevo alcance (FR4/NFR5 de la épica quedan
> obsoletos para el wire legacy — nota registrada en `epics.md`).

## Story

As a mantenedor del contexto Bank,
I want que el contrato genérico `filters[]` sea el ÚNICO vocabulario de filtrado (retirando `names[]`/`ids[]`
del wire antes de cualquier despliegue),
so that exista un solo camino de filtrado sin coste de mantenimiento duplicado ni contrato legacy que arrastrar.

## Acceptance Criteria

1. **Given** el endpoint `GET /api/v1/backoffice/banks`
   **When** se retiran los params legacy `names[]`/`ids[]` del wire (fase *contract* adelantada — código sin
   desplegar, cero consumidores)
   **Then** el único vocabulario de filtrado es `filters[N][field|operator|value]`
   **And** los params retirados se comportan como cualquier query param desconocido (ignorados, nunca error) —
   pineado con escenario Behat lápida.

2. **Given** las firmas del camino de búsqueda
   **When** `BankSearchQuery` y `BankSearchCriteria` se eliminan (el directorio `Domain/Search/` desaparece;
   en `Application/Http/` permanecen los payloads de escritura)
   **Then** `BankSearchController` mapea `SearchQuery` base directamente y llama `$query->toCriteria()`;
   `BankSearcher::search(SearchCriteria)`; `DoctrineBankRepository` sin filtrado ad hoc (`instanceof` +
   `addWhereIdsIn` + `addWhereIn` + normalización in-memory eliminados — el filtrado entra SOLO por el
   auto-apply del seam contra `searchFieldMap()`)
   **And** `SearchQuery` y `SearchCriteria` pierden `ids` y pasan a `final` (cero subclases — la especialización
   per-entity va por filters + field map, nunca por herencia).

3. **Given** la suite Behat
   **When** corre
   **Then** los escenarios legacy quedan sustituidos por su forma `filters[]` con pins de id exacto (resultado
   idéntico, no solo conteos), composición AND multi-filtro (intersección y disjunta) y el escenario lápida
   **And** `delete.feature` y `query_stats.feature` migran su gramática con los conteos de queries intactos
   **And** la suite completa queda en verde: 88 escenarios / 651 steps (baseline 1.4: 85/640).

4. **Given** los gates de cierre
   **When** se completa la historia
   **Then** `make php.stan` + `make php.psalm` + `make php.unit` + `make php.behat` + `make php.quality`
   (×2 idempotente) + `make php.lint.error-contract` en verde
   **And** NFR4 verificado con `EXPLAIN ANALYZE` (in/eq sobre `name_normalized` → Index Scan del índice UNIQUE;
   `id` → Index Scan del PK; `contains` → Seq Scan asumido conscientemente; plan idéntico al del camino legacy
   retirado → p95 sin regresión)
   **And** los 2 deferred del review de la 1.4 quedan resueltos (índice posicional en `InvalidSearchValue`;
   decisión de equivalencia de types moot por retirada del legacy).

## Tasks / Subtasks

- [x] Task 0: Prep — rama de la épica + cierre del pendiente de la 1.4 (AC: —)
  - [x] Worktree `shared-search-filters-aj0w`, rama `feat/shared-search-filters-aj0w` verificada; stack arriba
  - [x] Los 2 patches del review 1.4 estaban SIN committear en el working tree — committeados como `1c108d6`
        (`fix(api): reject contains on uuid field mappings and clarify behat in-filter step`, 5 ficheros)
- [x] Task 1: Behat pre-refactor — arnés de equivalencia y caracterización (alcance original; AC: 3)
  - [x] 12 escenarios añadidos (equivalencia legacy≡genérico con ids explícitos, combinación AND, edges blank)
        — 46/46 en verde al primer intento sobre el código 1.4, conteos de queries clavados
  - [x] Tras el pivote, las mitades legacy/edges se sustituyeron por los pins genéricos + lápida (Task 4)
- [x] Task 2: RED — `BankSearchQueryTest` del mapping legacy→Filters (alcance original; AC: —)
  - [x] 8 tests escritos; RED confirmado (5 fallos esperados). Tras el pivote el fichero se eliminó con la clase
- [x] Task 3: GREEN — refactor D8 (alcance original, subsumido por el pivote; AC: 2)
  - [x] Mapping `legacyFilters()` en `BankSearchQuery` implementado y verde (8/8) — luego eliminado en el pivote
  - [x] `BankSearcher::search(SearchCriteria)` + controller `toCriteria()` + `BankSearchCriteria` eliminada +
        `DoctrineBankRepository` sin `instanceof`/`addWhere*` ad hoc — TODO esto sobrevive al pivote
  - [x] `SearchCriteria` → `final` (Psalm `ClassMustBeFinal` al caer la última subclase; reestructurar > suprimir)
- [x] Task 4: PIVOTE — retirar `names[]`/`ids[]` del wire (decisión de usuario; AC: 1, 2, 3)
  - [x] `BankSearchQuery` [D] + `BankSearchQueryTest` [D] (el dir de tests `Application/Http/` desaparece; el de
        `src/` permanece con los payloads de escritura); controller mapea `SearchQuery` base
  - [x] `SearchQuery` [M]: sin `ids`, `final readonly`, `domainFilters()` privado, docblock del contrato único
  - [x] `SearchCriteria` [M]: sin `ids`, docblock del contrato único
  - [x] `SearchQueryTest` [M]: casos `ids` retirados (Uuid provider + asserts de transporte)
  - [x] `search.feature`: 3 escenarios legacy sustituidos (ids-miss → forma `filters[]`; names-array y
        names-diacritics → cubiertos por los pins genéricos; ids-invalid → cubierto por el escenario
        `invalid-search-value`); bloque de pins (5) + escenario lápida; comentarios actualizados
  - [x] `delete.feature` + `query_stats.feature`: gramática migrada a `filters[]`, conteos intactos
  - [x] `adding-endpoints.md`: skeleton sin DTO/criteria per-entity (4 pasos), controller con `SearchQuery` base,
        convención "final a propósito — campos nuevos via field map, jamás wire params/subclases"
  - [x] Postman: params `ids[]`/`names[]` retirados del request "Search banks" (JSON validado)
  - [x] Limpieza de referencias legacy en comentarios/docblocks (`FilterQuery` cap 255, `FieldNormalizer`)
- [x] Task 5: Deferred del review 1.4 (AC: 4)
  - [x] `InvalidSearchValue::notAUuid(string $field, int $position)` — context `{field, position}` 0-based, sin
        echo del valor; `FilterApplier::ensureUuidValues()` propaga el índice; `FilterApplierTest` assevera
        field+position (eq → 0, in → 1); escenario Behat amplía asserts (`field`/`position` en el body)
  - [x] Decisión de equivalencia de types legacy↔genérico: MOOT — el camino legacy ya no existe en el wire;
        registrado en `deferred-work.md` (ambos items cerrados)
- [x] Task 6: Gate NFR4 — índices y p95 (AC: 4)
  - [x] `EXPLAIN ANALYZE` contra `erpify_db_test` (31 banks fixtures): (a) `name_normalized IN` → Index Scan
        `uniq_d860bf7ae1b35095` 0.058ms; (b) `=` → mismo Index Scan 0.060ms; (c) `LIKE '%banc%'` → Seq Scan
        0.055ms (asumido: comodín inicial no indexable por btree, caps acotan; deferred 1.4 cerrado);
        (d) `id IN` → Index Scan `bank_pkey` 0.052ms
  - [x] Igualdad de plan legacy↔único: el camino retirado emitía el mismo shape (`IN` bindeado sobre las mismas
        columnas indexadas) → p95 sin regresión por construcción; conteos de queries Behat como segunda evidencia
- [x] Task 7: Gates finales + housekeeping (AC: 4)
  - [x] stan ✅ · psalm ✅ (sin baseline nueva — `addWhereIn`/`addWhereIdsIn` no flaggeados) · unit 611/611 ✅
        (3 skips preexistentes) · behat 88/651 ✅ · php.quality exit 0 ×2 idempotente ✅ · error-contract ✅
  - [x] `reference.php` auto-regenerado restaurado (no committeado); fixer re-formateó tests (2ª pasada limpia)
  - [x] `deferred-work.md` + `epics.md` (nota de decisión) + sprint-status actualizados

### Review Findings

- [x] [Review][Decision] Los artefactos `_bmad-output` del File List no viajan en la rama — existían solo sin commitear/untracked en el checkout principal mientras la rama (commits `1c108d6` + `31d508c`) solo tocaba `api/`. **Resuelto (decisión de usuario): commitearlos a la rama del PR** — story, `deferred-work.md`, `epics.md` y `sprint-status.yaml` añadidos a `feat/shared-search-filters-aj0w`.
- [x] [Review][Patch] Comentario obsoleto post-pivote: "all filters — legacy params included — arrive as criteria->filters" + nomenclatura "(D8)" contradecían la retirada del wire legacy [api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/DoctrineBankRepository.php:74] — corregido en commit `6c9aaab` (stan + php.quality verdes)
- [x] [Review][Patch] File List/AC2 imprecisos: el dir `Bank/Application/Http/` NO desaparece (siguen `BankPostPayload.php`/`BankPutPayload.php`); solo se borró `BankSearchQuery.php`. `Domain/Search/` sí desapareció — redacción corregida en AC2, Task 4, Completion Notes, File List y Project Structure Notes

## Dev Notes

### Decisiones del pivote (fijadas por el usuario, 2026-06-07)

1. **Retirada total del wire legacy**: `names[]`/`ids[]` desaparecen del contrato (no se mapean, no se
   deprecan). Justificación: código sin desplegar en producción y cero consumidores (la PWA filtra client-side
   hasta la fase 2). La fase *contract* del Parallel Change se adelanta de "post fase 2" a esta historia.
2. **`ids[]` también fuera del contrato base**: era param genérico de `SearchQuery`/`SearchCriteria`, pero
   redundante con `filters[id][in]` y sin consumidor. Vocabulario único.
3. **`SearchQuery` y `SearchCriteria` final**: sin subclases (la última, Bank, cayó aquí), Psalm exige final y
   la arquitectura lo confirma — la especialización per-entity se expresa via `filters[]` + `searchFieldMap()`,
   nunca por herencia ni wire params nuevos. Documentado como convención en `adding-endpoints.md`.
4. **Params retirados = params desconocidos**: `#[MapQueryString]` ignora query params no mapeados (comportamiento
   estándar) — `?names[]=X` devuelve 200 sin filtrar, como `?foo=bar`. Pineado con escenario lápida.
5. **Helpers `addWhereIn`/`addWhereIdsIn`/`addWhereInCaseInsensitive` del base NO se borran**: toolkit deliberado
   de `AbstractDoctrineRepository`; Psalm no los flaggeó tras el cambio (sin churn de baseline).

### Trabajo del alcance original que sobrevive al pivote

`BankSearcher::search(SearchCriteria)` (flujo canónico: el controller llama `toCriteria()`), eliminación de
`BankSearchCriteria` + dir `Domain/Search/`, `DoctrineBankRepository` sin `instanceof` ni filtrado ad hoc
(QB = `createQueryBuilder('b')` + orden + limit; filtros solo por el seam), `SearchCriteria` final, y el arnés
Behat de equivalencia (demostró la equivalencia legacy≡genérico ANTES de cualquier borrado — 46/46 — y después
mutó a los pins genéricos del contrato único).

### Riesgos vigilados durante la ejecución

- El refactor de firmas no admite estados intermedios (el `instanceof` lanzaba con criteria base) — se aplicó
  atómico y verificado por unit+Behat antes de seguir.
- Doble gate stan+psalm por fichero: el único conflicto (`ClassMustBeFinal` en `SearchCriteria`) se resolvió
  reestructurando (final + docblock), jamás con suppression — regla del repo.
- `docker compose` a pelo no resuelve el proyecto del worktree (la capa Make fija `COMPOSE_PROJECT_NAME`) —
  para psql se usó `docker exec` con el nombre de contenedor completo.
- `api/config/reference.php` auto-regenerado por los gates — restaurado, jamás committeado.

### Project Structure Notes

Delta final (ver File List). Cero migraciones, cero `services.yaml`, cero cambios Compose/Make/CI/`.env`.
Para la **story 1.6** (anotar al crearla): receta "añadir una lista filtrable" en `docs/architecture-api.md`
debe reflejar el contrato único post-pivote (sin subclases de `SearchQuery`/`SearchCriteria`, sin params
tipados per-entity); `docs/source-tree-analysis.md` + `docs/claude-code-quickref.md` (alta de
`Doctrine/Search/`, baja de `Bank/Domain/Search/` — `Bank/Application/Http/` permanece con los payloads de
escritura); retirar `php-criteria-main/`
del working tree; revisar `docs/deep-dive-api-shared-foundation.md` (describe el flujo pre-pivote:
`BankSearchQuery`, criteria por herencia, `addWhereIn` como camino de filtrado) y el ADR
`adr-2026-04-29-search-controller-boundary.md` (sus subclases per-entity quedaron retiradas — la nota ya
está en `adding-endpoints.md`).

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 1.5] — ACs originales (D8 expand) + nota de decisión
  del pivote añadida en este PR.
- [Source: _bmad-output/planning-artifacts/architecture.md#Decision Impact Analysis] — la fase *contract*
  ("retirar names[]/ids[] del wire") estaba diferida a post-fase-2; adelantada aquí por decisión de usuario.
- [Source: _bmad-output/implementation-artifacts/deferred-work.md] — los 2 deferred de la 1.4 cerrados aquí.
- [Source: api/docs/adding-endpoints.md#Search endpoints] — skeleton y convenciones actualizados al contrato único.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Claude Opus 4.8, 1M context) — Claude Code

### Debug Log References

- Task 0: los 2 patches del review 1.4 estaban aplicados pero sin committear en el worktree → commit `1c108d6`
  antes de empezar (verificado contra el registro de la 1.4: gates post-patch ya estaban en verde).
- Task 1 (alcance original): 12 escenarios de equivalencia/caracterización en verde al primer intento sobre el
  código 1.4 — incluida la predicción de conteos de queries (hits 2, AND-disjunto 1, edges 2).
- Task 2/3 (alcance original): RED 5/8 → GREEN 8/8 del mapping `legacyFilters()`; el hallazgo central fue la
  asimetría blank: el legacy descartaba silenciosamente (`sanitizeArray`) lo que el applier rechaza loudly —
  el mapping reproducía el sanitize. Todo ello quedó superado por el pivote (clase eliminada).
- PIVOTE a mitad de Task 3: mensaje del usuario "I don't want to keep legacy filters…". Confirmadas 2 decisiones
  via AskUserQuestion (ids[] fuera del base; reescritura de ACs). El único error stan pendiente en ese momento
  (`method.alreadyNarrowedType` en un assert del test del mapping) murió con el fichero.
- Psalm `ClassMustBeFinal` sobre `SearchCriteria` al caer la última subclase → final + docblock (también
  `SearchQuery`); cero suppressions, cero entradas nuevas de baseline (Psalm no flaggeó `addWhereIn*`).
- Postman: edición quirúrgica de 2 líneas (lección de la 1.4: jamás re-serializar el JSON entero); validado.
- NFR4: `docker compose exec` a pelo no resuelve el proyecto del worktree → `docker exec` directo al contenedor
  `erpify-shared-search-filters-aj0w-database-1` (usuario `erpify_user`, BD `erpify_db_test` con las fixtures).
- `php.quality` pasada 1 re-formateó `FilterApplierTest`/`SearchQueryTest` (fixer); pasada 2 exit 0 idempotente;
  `reference.php` restaurado.

### Completion Notes List

- **Contrato único `filters[]` consumado**: `GET /api/v1/backoffice/banks` ya no acepta `names[]`/`ids[]` — son
  query params desconocidos (ignorados, jamás error, jamás filtro; escenario lápida los pinea). La fase
  *contract* del Parallel Change se adelantó por decisión explícita del usuario (código sin desplegar, cero
  consumidores). FR4/NFR5 de la épica quedan obsoletos para el wire legacy — nota en `epics.md`.
- **Estructura final del camino de búsqueda**: HTTP → `#[MapQueryString] SearchQuery` (base, final) →
  `toCriteria()` en el controller → `BankSearcher::search(SearchCriteria)` → repo → seam auto-apply contra
  `searchFieldMap()`. Bank ya no aporta DTO, criteria ni filtrado propio: `Bank/Domain/Search/` desapareció
  y en `Bank/Application/Http/` solo permanecen los payloads de escritura. `SearchQuery`/`SearchCriteria` son `final`
  y sin `ids` — la especialización per-entity va por filters + field map (convención documentada).
- **Behat como red de seguridad del pivote**: el arnés de equivalencia (alcance original) demostró
  legacy≡genérico ANTES de cualquier borrado; tras el pivote mutó a pins de id exacto, composición AND
  multi-filtro (intersección 1 item / disjunta 0 items) y lápida. `delete.feature` y `query_stats.feature`
  migraron su gramática con conteos de queries idénticos — evidencia de que el camino único emite el mismo SQL.
- **Deferred 1.4 cerrados**: `InvalidSearchValue` lleva `{field, position}` (0-based, sin echo del valor,
  visible en el body Problem Details y asseverado e2e); la decisión de equivalencia de types quedó moot.
- **NFR4**: in/eq sobre `name_normalized` → Index Scan (UNIQUE); `id` → Index Scan (PK); `contains` → Seq Scan
  asumido (no indexable con comodín inicial; caps acotan; cerrado el deferred de coste de query). Igualdad de
  plan con el camino retirado → p95 sin regresión por construcción.
- **Seguridad (checklist CLAUDE.md)**: superficie REDUCIDA (2 wire params menos, validación concentrada en una
  sola capa); cero interpolación nueva (desapareció el último filtrado fuera del applier); errores sin oráculo
  (field+position, nunca el valor); sin cambios de authn/authz/secretos/migraciones/CORS/Mercure (no aplican —
  declarar en el PR; endpoint sigue siendo público consciente).
- **Gates finales**: stan ✅ · psalm ✅ · unit 611/611 (3 skips preexistentes) ✅ · behat 88 escenarios /
  651 steps ✅ · php.quality exit 0 ×2 ✅ · error-contract ✅ · Postman JSON válido ✅.
- **Pendiente de decisión del usuario**: PR única (fases 0+1 completas en la rama) o troceada; el merge a main
  es del usuario. La 1.6 cierra docs (ver Project Structure Notes — lista ampliada post-pivote).

### File List

- `api/src/Backoffice/Bank/Application/Http/BankSearchQuery.php` (eliminado — el dir permanece con `BankPostPayload`/`BankPutPayload`)
- `api/src/Backoffice/Bank/Domain/Search/BankSearchCriteria.php` (eliminado — dir `Domain/Search/` desaparece)
- `api/src/Backoffice/Bank/Application/BankSearcher.php` (modificado — firma `search(SearchCriteria)`)
- `api/src/Backoffice/Bank/Infrastructure/Controller/BankSearchController.php` (modificado — `SearchQuery` base + `toCriteria()`)
- `api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/DoctrineBankRepository.php` (modificado — QB sin `instanceof` ni `addWhere*` ad hoc)
- `api/src/Shared/Application/Http/Search/SearchQuery.php` (modificado — sin `ids`, `final`, `domainFilters()` privado)
- `api/src/Shared/Application/Http/Search/FilterQuery.php` (modificado — comentario cap 255 sin referencia legacy)
- `api/src/Shared/Domain/Search/SearchCriteria.php` (modificado — sin `ids`, `final`)
- `api/src/Shared/Domain/Search/Exception/InvalidSearchValue.php` (modificado — `notAUuid(field, position)`)
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FilterApplier.php` (modificado — propaga la posición)
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FieldNormalizer.php` (modificado — docblock sin referencia legacy)
- `api/tests/Unit/Backoffice/Bank/Application/Http/BankSearchQueryTest.php` (eliminado)
- `api/tests/Unit/Shared/Application/Http/Search/SearchQueryTest.php` (modificado — sin casos `ids`)
- `api/tests/Functional/Shared/Persistence/FilterApplierTest.php` (modificado — asserts field+position)
- `api/features/backoffice/bank/search.feature` (modificado — legacy → `filters[]`, pins, lápida, comentarios)
- `api/features/backoffice/bank/delete.feature` (modificado — gramática `filters[]`)
- `api/features/backoffice/bank/query_stats.feature` (modificado — gramática `filters[]`)
- `api/docs/adding-endpoints.md` (modificado — skeleton del contrato único)
- `api/docs/postman/erpify-api.postman_collection.json` (modificado — params legacy retirados)
- `_bmad-output/implementation-artifacts/deferred-work.md` (modificado — 2 deferred 1.4 resueltos)
- `_bmad-output/planning-artifacts/epics.md` (modificado — nota de decisión del pivote)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (modificado — estados 1.5)
- Task 0 (commit `1c108d6`, previo): `FieldMapping.php`, `FieldMappingTest.php`, `HttpRequestContext.php`,
  `search.feature`, `adding-endpoints.md` (patches del review 1.4)

## Change Log

- 2026-06-07: Story 1.5 creada (alcance original D8: mapear legacy→Filters manteniendo el wire).
- 2026-06-07: Task 0–3 del alcance original ejecutadas (arnés Behat de equivalencia 46/46; mapping
  `legacyFilters()` RED→GREEN; firmas a `SearchCriteria`; `BankSearchCriteria` eliminada; repo sin ad hoc).
- 2026-06-07: **PIVOTE por decisión de usuario** ("no mantener filtros legacy — sin desplegar en producción"):
  fase *contract* adelantada. `names[]`/`ids[]` retirados del wire; `ids[]` eliminado también del contrato base;
  `BankSearchQuery`+test eliminados; `SearchQuery`/`SearchCriteria` final; Behat/docs/Postman migrados; ACs de
  la historia reescritos (autorizado) y nota en `epics.md`. Deferred 1.4 cerrados (`InvalidSearchValue`
  field+position; equivalencia de types moot). NFR4 verificado con `EXPLAIN ANALYZE` (igualdad de plan).
  Gates: stan/psalm/unit 611/behat 88×651/quality ×2/error-contract — todo verde. Status → review.
- 2026-06-07: Code review adversarial (Blind Hunter / Edge Case Hunter / Acceptance Auditor): 14 hallazgos
  brutos → 11 descartados (falsos positivos de la capa ciega, verificados contra el código), 1 decisión +
  2 patches resueltos. Comentario post-pivote corregido (`6c9aaab`), File List/AC2 precisados
  (`Application/Http/` no desaparece), artefactos BMAD commiteados a la rama por decisión de usuario.
  Status → done.
