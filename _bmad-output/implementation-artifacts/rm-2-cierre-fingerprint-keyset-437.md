---
baseline_commit: edd69e4423c3c688ba8f553b05b92c9f82b30a92
---

# Story RM-2 (PR-2): Cierre del fingerprint keyset (#437) — gate duro de RM-4

Status: done

Epic: `rbac-authorization-model` · Orden de merge: **2º** (tras RM-1; **gate duro de RM-4**) · Slice: `Shared/Search` puro — cierra el bypass de privilege-scope **antes** de que exista el par de rutas con acceso divergente (RM-4). **No gatea ninguna ruta** (eso es RM-4).

## Story

Como responsable de seguridad,
quiero que un cursor keyset acuñado en una ruta sea rechazado en otra ruta con distinto conjunto base (`WHERE`/`JOIN`),
para que, cuando dos rutas sobre la misma raíz de agregado tengan acceso divergente, un cursor no sirva para saltar el alcance de privilegio paginando filas de un recurso al que no se tiene acceso.

## Acceptance Criteria

1. **Discriminante base-query en la cadena canónica.** Al acuñar un cursor, el `QueryExecutionTrace` incorpora — vía un nuevo argumento de constructor sellado + un nuevo segmento canónico en `FingerprintCanonicalizer` (exactamente la evolución que anticipa el docblock **AR24** del trace) — un discriminante que cubre el **`JOIN`** y el **base-predicate `WHERE`** *autorados por el repositorio*, **no** sólo las columnas de orden ni los filtros de `SearchCriteria`. Se deriva de la propia `QueryBuilder` base **antes** de que `FilterApplier` la mute. *(FR9, D9, AR24)*
2. **Rechazo cross-ruta.** Un cursor acuñado en la ruta colección `GET /bank-accounts` (sin base-`WHERE`, con `JOIN Bank`), al presentarse en la ruta anidada `GET /banks/{id}/accounts` (base-`WHERE ba.bankId`, sin `JOIN`), se **rechaza con `422 invalid-cursor`** por el pipeline RFC 9457 — es un `InvalidCursor::fingerprint()` (el fingerprint recomputado diverge). **Sin marker de error nuevo.** *(FR9, R2, NFR6)*
3. **Sin regresión en la propia ruta.** El mismo cursor re-presentado en **su** ruta (mismo conjunto base) pagina sin cambio: `next`/`prev` walk, `synthesizeCursor` (go-to-date) y el round-trip `after`/`before` siguen intactos. *(FR9)*
4. **Gate duro (ejecutable, no nota).** Existe un test de replay cross-ruta (a nivel engine **y** a nivel HTTP) que **falla** si el discriminante se ausenta. Ese test, verde en `main` tras RM-2, es la garantía de la que depende RM-4: **RM-4 no puede mergearse sin RM-2 en `main`** (par nested/colección con acceso divergente = bypass sin el discriminante). *(R2)*
5. **Versión de contrato del cursor — se mantiene `1` (pre-producción).** El fix cambia la cadena canónica, pero la app es greenfield sin cursores liberados (efímeros, nunca persistidos → ninguno v1 «en vuelo»). Se **acota el invariante FR15** a «bump al cambiar un formato *liberado*» (docblock de `Cursor`): pre-release seguimos definiendo v1, así que refinar la cadena antes del primer deploy **no** bumpea — la versión cuenta formatos liberados, no iteraciones dev de v1. Un (inexistente) cursor de formato viejo se rechazaría igual por `InvalidCursor::fingerprint()` → 422, indistinguible en el wire. *(FR15 acotado — decisión de Sergio, greenfield pre-producción)*
6. **Regresión + gates verdes.** `/banks` (raíz `Bank`, ya fingerprint-distinta) sigue su round-trip; el paginador **DBAL separado** de audit (`AuditTimelineKeysetPaginator`, no usa este canonicalizer) queda intacto; `make php.stan` por fichero y `make php.quality` (incl. `php.deptrac`, `php.lint.bounded-context`, `php.lint.error-contract`) verdes; **sin migración** (nada toca esquema). *(NFR5, NFR6)*

## Tasks / Subtasks

- [x] **T1 · Discriminante base-query en el trace + el canonicalizer** (AC: 1, 5)
  - [x] `QueryExecutionTrace`: añadir un argumento de constructor sellado para la identidad estructural de la query base (recomendado: `string $baseQuery`, simétrico con `entity`/`tenant`, que ya son strings — ver Dev Notes §3 para el «VO vs string»). Actualizar el docblock AR24 para documentar la nueva dimensión y su *por qué*.
  - [x] `FingerprintCanonicalizer::canonical()`: añadir el nuevo segmento a la cadena. Colocarlo junto a `entity` (ambos son identidad de forma de query): `tenant|entity|baseQuery|filters|sort|direction|limit`. Mantener la canonicalización **puramente sintáctica** (sin re-validar, sin importar Doctrine).
  - [x] `CursorCodec::CURRENT_VERSION`: **se mantiene en `1`** (ver T3 y AC5 — decisión: greenfield pre-producción, sin cursores liberados; se acota FR15 en el docblock de `Cursor`).
- [x] **T2 · Cablear la extracción en el engine** (AC: 1, 3)
  - [x] `DoctrineSearchEngine::paginate()`: capturar el discriminante base **al inicio, ANTES** de `filterApplier->apply()` (paso 2 muta la QB con los filtros de `SearchCriteria`). Método privado `canonicalBaseQuery(QueryBuilder): string` (única costura Doctrine-aware — es el monopolio del engine). Recomendado: `getDQLPart('join')` + `getDQLPart('where')` estringados de forma determinista (o el `getDQL()` base completo — ver Dev Notes §8). Pasarlo al `new QueryExecutionTrace(...)` (paso 4).
  - [x] `DoctrineSearchEngine::synthesizeCursor()`: mismo discriminante desde la QB base (aquí los filtros corren sobre un **clon**, la QB original está prístina → leerla directamente). **En lockstep**: un cursor sintético debe validar en la ruta `paginate` que lo acompaña, así que ambos sitios de construcción del trace deben derivar el mismo discriminante.
- [x] **T3 · Versión de contrato del cursor — decisión: mantener `1`** (AC: 5)
  - [x] `CursorCodec::CURRENT_VERSION` y `CursorCodecTest` quedan **sin cambios** (== `main`, v1): greenfield pre-producción, no hay cursores liberados que distinguir. El golden vector (`"v":1`) y los tests de versión (`v=2` = desconocida) siguen correctos.
  - [x] `Cursor` docblock: **acotar el invariante FR15** a «bump al cambiar un formato liberado» — pre-release, refinar la cadena canónica no bumpea (la versión cuenta formatos liberados, no iteraciones dev de v1).
- [x] **T4 · Actualizar los tests que fijan los bytes canónicos** (AC: 1)
  - [x] `FingerprintCanonicalizerTest`: el helper `chain()` (hoy `'__erpify_single_tenant__|Bank|' . $filters . '|name|ASC|25'`) debe incorporar el nuevo segmento; actualizar cada `assertSame($this->chain(...), …)`. El cambio de bytes es **legítimo y central** (AR24), no una regresión.
  - [x] `Mother/TraceMother::create()`: añadir el nuevo argumento del constructor (default representativo, p. ej. la query base de `Bank`).
- [x] **T5 · Test del discriminante (prueba primaria del AC)** (AC: 1, 2, 4)
  - [x] Test engine-level (Functional, DIRECTO — sin HTTP, patrón del repo): dos QB base sobre `BankAccount` con distinto conjunto base (una plana `SELECT ba FROM BankAccount ba`, otra `… WHERE ba.bankId = :x`); acuñar un cursor con la 1ª vía `paginate`, presentarlo a `paginate` con la 2ª → `InvalidCursor` (familia 422, causa `fingerprint`). Y el caso positivo: mismo cursor en su propia QB → pagina. Ubicación natural: junto a `KeysetGoToDateSeamTest` / un nuevo `KeysetBaseQueryScopeTest` en `tests/Functional/Shared/Persistence/`.
  - [x] `TraceEquivalenceStabilityTest`: añadir un caso «dos traces que difieren **sólo** en la query base ⇒ distinto canonical/fingerprint» (y su recíproco: misma query base ⇒ idéntico — estabilidad byte-a-byte preservada).
- [x] **T6 · Behat replay cross-ruta (gate HTTP)** (AC: 2, 3, 4)
  - [x] `api/features/backoffice/bank_account/search_collection.feature` y `.../search.feature`: escenario nuevo — acuñar un `pagination.links.next` en una ruta, presentarlo (su `after`) en la **otra** ruta → **422 `invalid-cursor`**; y el control positivo (mismo cursor en su ruta → 200, sin regresión del walk existente). Ver Dev Notes → gotchas Behat (presupuesto de queries, seeds).
- [x] **T7 · Regresión + gates** (AC: 3, 6)
  - [x] Verde: `BankSearchCursorFunctionalTest` (`/banks`), `KeysetSqlSnapshotTest` (el SQL compilado **no** cambia — el discriminante no toca la query ejecutada), `KeysetGoToDateSeamTest`, `KeysetOrderStabilityPropertyTest`, `AuditTimelineSearchCursorFunctionalTest` (paginador DBAL separado, intacto).
  - [x] `make php.stan` en cada fichero PHP tocado; al final `make php.quality`. Correr el árbol keyset completo (`make php.unit c='--filter Keyset'` y afines) + las 2 features de bank_account.

### Review Findings

_Code review (`bmad-code-review`, 2026-07-07): 3 capas adversariales (Blind Hunter · Edge Case Hunter · Acceptance Auditor). **Sin BLOCKER/HIGH**; los 6 probes del Edge Case Hunter verifican OK e AC1–AC6 satisfechos. Revisado por el mismo modelo que implementó — cautela por sesgo de autor. Findings deduplicados:_

- [x] [Review][Decision] **Inyectividad de la cadena canónica — justificación del comentario incorrecta** (Blind M1 + Edge F2, ambos confirman que la inyectividad *se mantiene*, no explotable). El comentario afirma que los segmentos previos «nunca contienen `|`»; **falso** para `filters` (`json_encode` no escapa `|`). → **RESUELTO (opción a, elegida por Sergio):** comentario reescrito a la razón correcta — `filters` es un array JSON auto-delimitado (ningún `json_encode` es prefijo propio de otro) + `sort`/`dir`/`limit` son `|`-free + `baseQuery` es la cola, así que la descomposición es única aun con `|` dentro de `filters`/`baseQuery`. Sin cambio de formato. [`FingerprintCanonicalizer.php`]
- [x] [Review][Patch] **Falta el test de rechazo cross-scope del cursor sintético** (Acceptance Medium) — `synthesizeCursor()` liga el `baseQuery` pero ningún test lo probaba contra scope ajeno. → **RESUELTO:** añadido `aSyntheticCursorMintedUnderOneBaseQueryIsRejectedUnderADivergentOne` (+ helper `synthesize()`) en `KeysetBaseQueryScopeTest` (su hogar cohesivo — ya tiene plain/scoped QB + seed; ambos `#[CoversClass(DoctrineSearchEngine)]`). Prueba el lockstep: token sintético de la QB plana → rechazado por la scoped (causa `Fingerprint`). [`KeysetBaseQueryScopeTest.php`]
- [x] [Review][Patch] **Golden vector con literal explícito de versión** (Blind Low). → **RESUELTO por el revert a v1:** al mantener `CURRENT_VERSION=1`, `CursorCodecTest` vuelve a `main`, cuyo golden vector ya usa el literal `1` explícito. Sin cambio pendiente. [`CursorCodecTest.php`]
- [x] [Review][Defer] **Acoplamiento del fingerprint al formato de `getDQL()`** (Blind M2 + Edge F1) — la determinación byte-idéntica de la base-query entre mint y follow-up es precondición no guardada; un consumidor futuro con auto-params (`?1`, `expr()->in()`) / JOIN condicional, o un upgrade de Doctrine que cambie la generación de DQL, daría 422 espurio. Sin trigger hoy (3 consumidores fixed-shape con placeholders nombrados). → **mitigado con nota de precondición** en el docblock de `baseQueryIdentity()`; el endurecimiento (identidad base-query estable e independiente de Doctrine) queda como candidato a follow-up. [`DoctrineSearchEngine.php`]
- [x] [Review][Dismiss] Same-route/distinto-id acepta el cursor sin test que lo fije (Blind Low) — comportamiento **por diseño** (structural, not value-bound), documentado; un test que asserta «cursor aceptado cross-bank» confundiría al lector futuro.
- [x] [Review][Dismiss] El test engine-level no ejercita la dimensión JOIN vía `getDQL()` real (Acceptance Low) — la cubre end-to-end el escenario Behat colección→anidada (colección lleva `JOIN Bank`); el auditor calificó la elección Bank-plain-vs-`WHERE name` de «defensible — arguably better» (aísla la estructura como única variable).

## Dev Notes

### Contrato de diseño (fuente de verdad)

- **Épica** `_bmad-output/planning-artifacts/epics-rbac-authorization-model.md` — **FR9** (cierre keyset #437, co-requisito) y §"Story RM-2 (PR-2)" con sus 4 AC BDD; **R2** del pre-mortem (bypass keyset = escalada de privilegios; RM-2 = gate duro de RM-4).
- **Addendum** `_bmad-output/planning-artifacts/arch-addendum-rbac-authorization-model.md` — §"Localización de decisiones por PR" (PR-2: «discriminante base-query/route en `QueryExecutionTrace`/`FingerprintCanonicalizer` → un cursor acuñado en una ruta se rechaza `422 invalid-cursor` en otra con distinto `WHERE`/`JOIN`»); DAG (PR-2 cierra la puerta antes del par nested/colección de PR-4).
- **ADR** `docs/adr/rbac-authorization-model.md` — **D9**: «Its fix (a base-query/route discriminant in the fingerprint) ships with this slice, before the divergent-access pair exists.» Es el **co-requisito** de la puerta row-level que el modelo deja abierta sin construir.
- **Issue** `#437` — «keyset cursor fingerprint omits base predicate — cross-route scope-widening becomes a privilege bypass under RBAC». Su cuerpo describe el fix: incluir un discriminante de base-query (el `WHERE`/`JOIN` DQL, o el nombre de ruta) en el `QueryExecutionTrace`/fingerprint. **Cierra #437.**
- **Gobernanza in-code (AR24)** — el docblock de `QueryExecutionTrace` ya predice esta historia: *"the trace must capture the full order-decision space. Adding a future dimension … is a deliberate, central change here — a new constructor argument and a new canonical segment — never an incidental patch scattered across collaborators."* RM-2 es exactamente ese cambio.

### El bug en una línea

`FingerprintCanonicalizer::canonical()` compone `tenant|entity|filters|sort|direction|limit`. La **única** identidad de forma de query en la cadena es el FQCN de la raíz (`entity`); el `WHERE`/`JOIN` base que autora el repositorio **no** está representado. Colección y anidada comparten raíz `BankAccount`, mismo `sortFieldMap` y (en un walk por defecto) mismos filtros/limit → **fingerprint byte-idéntico** → un cursor cruza entre ambas.

### Artefactos a crear/tocar (rutas exactas)

Todo en `api/src/Shared/Search/Infrastructure/Persistence/Doctrine/`:

| Fichero | Qué |
|---|---|
| `Keyset/QueryExecutionTrace.php` | **EDITAR** — nuevo argumento de constructor sellado (identidad de la query base) + docblock AR24. |
| `Keyset/FingerprintCanonicalizer.php` | **EDITAR** — nuevo segmento en `canonical()`. |
| `Keyset/Cursor.php` | **EDITAR** — docblock: acotar el invariante FR15 a formatos liberados (`CURRENT_VERSION` se mantiene en `1`). |
| `DoctrineSearchEngine.php` | **EDITAR** — `canonicalBaseQuery()` privado; capturar en `paginate()` (antes de `filterApplier->apply`) y en `synthesizeCursor()`; pasarlo a los 2 `new QueryExecutionTrace(...)` (L106, L188 aprox.). |

**Estado actual del código (leído — no reinventar):**

- **Cadena canónica** (`FingerprintCanonicalizer::canonical()`, L66-76): `implode('|', [tenant, entity, canonicalFilters, sort->field, sort->direction, limit])`. `fp = hash('xxh128', canonical)` (32 hex). Canonicalización **sintáctica** (nunca re-valida input).
- **Trace** (`QueryExecutionTrace`): `__construct(string $entity, AppliedFilters $filters, AppliedSort $sort, AppliedLimit $limit)` + `const TENANT_SLOT` (placeholder single-tenant, 1er segmento). `entity` = FQCN de `getRootEntities()[0]`.
- **Engine** (`DoctrineSearchEngine::paginate`): pipeline de 8 pasos. Paso 1 `resolveSort` (no toca la QB). **Paso 2 `filterApplier->apply($queryBuilder, …)` MUTA la QB** (añade los `WHERE` de los filtros de `SearchCriteria`). Paso 4 sella el trace + fingerprint. ⇒ El discriminante base debe capturarse **antes del paso 2**, o incluiría los filtros (ya capturados por `AppliedFilters`) y placeholders inestables.
- **`synthesizeCursor`** (L159): construye su propio trace (L188) desde la QB base; los filtros corren sobre `clone $queryBuilder`, así que la QB original está prístina. **Único caller: tests** (`KeysetGoToDateSeamTest`). Debe fijarse en lockstep o su cursor sintético dejaría de validar.
- **Validación → 422**: `CursorCodec::decode($encoded, $expectedFingerprint, $expectedDir)` compara el `fp` del cursor contra el `$expectedFingerprint` que el engine recomputa del trace; mismatch → `InvalidCursor::fingerprint()`. `InvalidCursor` implementa el marker `InvalidSearchCriteria` → **422 `invalid-cursor`** por el pipeline RFC 9457, **sin wiring extra**. La causa (`fingerprint`) viaja sólo interna (log + métrica `invalid_cursor_count{cause}`); la respuesta de wire es **indistinguible** entre causas.
- **Las 3 QB que pasan por el engine** (confirmadas):
  - `DoctrineBankAccountCollectionSearchRepository::search` → `SELECT NEW BankAccountCollectionRow(...) FROM BankAccount ba JOIN Bank b ON ba.bankId = b.id` — **JOIN, sin `WHERE`**. (Ojo: aquí `bankId` **sí** es un filtro allow-listed del `SearchFieldMap` → si el cliente filtra por él, entra en `AppliedFilters`; el `JOIN` base sigue invisible sin este fix.)
  - `DoctrineBankAccountSearchRepository::search` → `SELECT ba FROM BankAccount ba WHERE ba.bankId = :bankId` — **base `WHERE`, sin `JOIN`**. Root `BankAccount` (idéntico a colección).
  - `DoctrineBankRepository::search` → `SELECT b FROM Bank b` — plana. Root `Bank` (ya distinta → no colisiona con las de account).

### Decisiones de diseño y gotchas críticos (previenen retrabajo)

1. **Discriminante estructural derivado de la QB (recomendado), no un token de nombre-de-ruta.** *Principio:* **NFR9** (transport independence) — «recurso ≠ ruta»; la identidad debe venir de la **query** (su `WHERE`/`JOIN`), no del nombre de ruta. *Objetivo:* automático para **todo** endpoint keyset (banks, accounts, audit-si-migrara, y cualquier búsqueda futura) — cero wiring por call-site, cierra la clase de bug globalmente, no sólo para el par de accounts. *Coste / alternativa descartada:* un `string $routeIdentity` que cada controlador pasa al engine sería más explícito pero (a) re-acopla a la ruta (viola NFR9), (b) hay que enhebrarlo por cada call-site y **se olvida en un endpoint nuevo** = regresión silenciosa. La QB **ya** lleva la identidad; extraerla es más barato y más robusto.
2. **Capturar ANTES de `filterApplier->apply` (paso 2).** El discriminante debe cubrir **sólo** el predicado base del repositorio; los filtros de `SearchCriteria` ya se capturan en `AppliedFilters`. Capturar tras el paso 2 doble-contaría filtros y arrastraría placeholders (`:filter_0`) potencialmente inestables. Snapshot al inicio de `paginate()`; en `synthesizeCursor` la QB original está prístina.
3. **`string` plano en el trace, no un VO nuevo (YAGNI).** `entity` y `tenant` ya son `string` en el trace; el discriminante base es una tercera dimensión-string de identidad de query. Un VO que envuelve un solo string no compra nada aquí (Regla de Tres: no hay 3er caller que justifique la abstracción). La extracción Doctrine-aware (`getDQLPart`/`getDQL`) vive en el engine (su monopolio explícito de mecánica Doctrine); el trace sólo guarda el string; el canonicalizer lo anexa. Simétrico con cómo fluye `entity`. Si la extracción creciera (normalización no trivial), promover a colaborador — no ahora.
4. **Versión: se mantiene `1` (greenfield pre-producción).** Añadir un segmento canónico cambia todos los fingerprints, y el docblock de `Cursor` decía *"A change to ANY of the serialization/canonicalization rules bumps it (FR15)"*. **Decisión de Sergio:** acotar ese invariante a «formatos *liberados*» — pre-release seguimos definiendo v1, no hay cursores en el campo, así que refinar la cadena no bumpea. Un (inexistente) cursor viejo se rechaza igual por `InvalidCursor::fingerprint()` → 422 (indistinguible en el wire de un `version`). `CursorCodec`/`CursorCodecTest` quedan idénticos a `main`; sólo se acota el docblock de `Cursor`. El bump legítimo se reserva para el primer cambio de formato **post-release**.
5. **Sin marker de error nuevo, sin cambio del contrato de error.** Se reutiliza `InvalidCursor::fingerprint()` (ya mapeado a 422 vía `InvalidSearchCriteria`). **NFR26 no se dispara** (no se añade ni cambia marker). No tocar `ProblemDetailsFactory`. `php.lint.error-contract` debe quedar verde sin editar `docs/api-error-contract.md`.
6. **El SQL ejecutado no cambia.** El discriminante afecta sólo al **fingerprint** (identidad del cursor), no a la query que se ejecuta. `KeysetSqlSnapshotTest` (snapshot del SQL compilado + params) debe seguir verde; si cambiara, es señal de que se está mutando la QB base por error.
7. **El paginador de audit es DBAL y separado — fuera de alcance.** `Backoffice/Audit/.../AuditTimelineKeysetPaginator` es la contraparte DBAL del `DoctrineSearchEngine` ORM; **no** usa `FingerprintCanonicalizer`/`QueryExecutionTrace`. RM-2 **no lo toca**. Su base QB (`SELECT … FROM audit_log a`) hoy no tiene base-`WHERE` variable, pero podría cargar la misma clase de bug de forma independiente si algún día gana un predicado base scoped → **candidato a issue de follow-up** (anotar en el PR; no abrir superficie aquí).
8. **`getDQL()` vs `getDQLPart('join')+getDQLPart('where')`.** Dos realizaciones válidas del §1: (a) **`getDQL()` base completo** — una sola llamada, determinista, cubre `SELECT+FROM+JOIN+WHERE`; el `SELECT` (p. ej. el `NEW …Row(...)` de la colección) queda incluido (estable por ruta; un cambio de proyección rota el cursor = correcto). (b) **`join`+`where` estringados** — más preciso al AC («`WHERE` + `JOIN`»), evita meter el `SELECT`. Recomendación: empezar por (b) para ceñirse al AC y no acoplar la proyección al cursor; si la determinismo de estringar los `Join`/`Composite` da fricción, (a) es el fallback simple. **Fijar la elección con TDD** (RED: dos QB base distintas → fingerprints distintos; misma → idéntico) — `TraceEquivalenceStabilityTest` es el guardián byte-a-byte.
9. **`bankId` allow-listed en colección (no colisión).** En la colección `bankId` es filtro válido; si un cliente hace `?filters[bankId]=X`, ese valor entra en `AppliedFilters` (segmento `filters`) — pero eso **no** cubre el caso base de la anidada (donde `bankId` es predicado base, no filtro). El discriminante base es ortogonal y necesario: colección-sin-filtro (base = `JOIN Bank`) vs anidada (base = `WHERE ba.bankId`) → distinto por el segmento base.

### Fuera de alcance (NO hacer en RM-2)

- ❌ Gatear rutas de `BankAccount`/`Bank` con `#[IsGranted]` o declarar `BankAccountPermission`/`BankPermission` → **RM-4/RM-3**.
- ❌ Migrar audit a `auditTrail.read` → **RM-5**.
- ❌ Tocar el paginador DBAL de audit (`AuditTimelineKeysetPaginator`) → fuera de alcance (posible follow-up).
- ❌ **Binding por valor** del predicado base (distinguir `bankId=A` de `bankId=B`). El AC pide discriminante **estructural** (`WHERE`/`JOIN`, o nombre de ruta); A-vs-B es la **misma** ruta/permiso (no es el scope-widening de #437). Bindear por valor rompería la estabilidad byte-a-byte (valores = objetos/DateTime/UUID) sin cerrar ninguna puerta que RM-2 deba cerrar. Si se quisiera luego, es otra decisión.
- ❌ Gate OCP ejecutable / row-level / evaluar `subject:` → RM-6 / capacidad futura.

### Testing (obligatorio; convenciones del repo)

Inventario exacto (verificado) — **actualizar** (bytes canónicos/versión cambian legítimamente) y **añadir** (comportamiento nuevo):

**Actualizar (rotura garantizada por el cambio de contrato):**
- `api/tests/Unit/Shared/Search/Infrastructure/Persistence/Doctrine/Keyset/FingerprintCanonicalizerTest.php` — helper `chain()` + cada `assertSame` contra él (el literal `tenant|Bank|<filters>|name|ASC|25` gana el nuevo segmento).
- `api/tests/Unit/Shared/Search/Infrastructure/Persistence/Doctrine/Keyset/Mother/TraceMother.php` — nuevo arg del constructor del trace (default representativo).
- _(`CursorCodecTest`/`CursorMother` NO se tocan — al mantener `CURRENT_VERSION=1` quedan idénticos a `main`: el golden vector `"v":1` y los tests de versión siguen correctos.)_

**Añadir (prueba del AC — la parte de valor real):**
- Test engine-level cross-scope (Functional directo, sin HTTP) — dos QB base con distinto `WHERE`/`JOIN` → cursor de una rechazado por la otra (`InvalidCursor`, causa `fingerprint`); mismo cursor en su QB → pagina. (Prueba primaria del AC2/AC4.)
- `TraceEquivalenceStabilityTest` — caso «distinta query base ⇒ distinto fingerprint» + recíproco de estabilidad.
- `KeysetGoToDateSeamTest` — caso de rechazo cross-scope para el cursor **sintético** (prueba el lockstep de `synthesizeCursor`).
- `api/features/backoffice/bank_account/search_collection.feature` + `.../search.feature` — replay cross-ruta HTTP → 422 (gate); control positivo sin regresión.

**Mantener verde (regresión):** `KeysetSqlSnapshotTest`, `KeysetOrderStabilityPropertyTest`, `BankSearchCursorFunctionalTest`, `AuditTimelineSearchCursorFunctionalTest`, `FilterApplierTest`.

Correr: `make php.unit c='--filter …'` iterando; `make php.behat` para las features; `make php.stan` por fichero; `make php.quality` al cerrar. **Regla del repo:** el engine se prueba por tests **directos**, nunca vía HTTP — el test primario del discriminante es engine-level; el Behat cross-ruta es el gate end-to-end complementario.

### Project Structure Notes

- Todo el cambio vive en `Shared/Search/Infrastructure/Persistence/Doctrine/` — **sin nuevos directorios**, sin cambio de deptrac (los ficheros ya están cubiertos por los collectors `Shared.*`). Namespace: `Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\{,\Keyset}`.
- Sin YAML de rutas, sin serializer groups, sin migración (nada toca esquema ni entidades). El discriminante es puramente en memoria (identidad del cursor).
- El `entity` del trace ya usa el FQCN (globalmente único); el discriminante base **complementa**, no reemplaza (dos rutas con misma raíz y distinto base son el caso a discriminar).

### References

- `_bmad-output/planning-artifacts/epics-rbac-authorization-model.md` — FR9, §"Story RM-2 (PR-2)", R2, §"FR Coverage Map".
- `_bmad-output/planning-artifacts/arch-addendum-rbac-authorization-model.md` — §"Localización de decisiones por PR" (PR-2); DAG.
- `docs/adr/rbac-authorization-model.md` — D9 (co-requisito #437), §"Acceptance criterion (OCP) and the two tripwires".
- `docs/api-error-contract.md` — marker `InvalidSearchCriteria` → 422; banner NFR26 (no se dispara aquí).
- `docs/project-context.md` — reglas PHP/testing/quality (cargar antes de codificar): PHP 8.5 `strict_types`, PHPStan `level: max`, tests AAA con fakes in-tree, keyset sobre OFFSET.
- Código en vigor: `api/src/Shared/Search/Infrastructure/Persistence/Doctrine/DoctrineSearchEngine.php`, `.../Keyset/{QueryExecutionTrace,FingerprintCanonicalizer,CursorCodec,Cursor,AppliedFilters,AppliedSort,AppliedLimit}.php`, `.../FilterApplier.php`; los 3 repos (`DoctrineBankAccountCollectionSearchRepository`, `DoctrineBankAccountSearchRepository`, `DoctrineBankRepository`); controladores `BankAccountSearchCollectionController` (`GET /bank-accounts`), `BankAccountSearchController` (`GET /banks/{id}/accounts`).

### Previous Story Intelligence (RM-1, ya en `main` vía #456)

- RM-1 aterrizó el **núcleo de autorización** (VO `Permission`, puerto `AuthorizationPolicy`, `StaticAuthorizationPolicy`, `PermissionVoter`, `Role` +4 tiers) en `Backoffice/Identity/Infrastructure/Security` — **aditivo, ninguna ruta gateada**. RM-2 es ortogonal (toca `Shared/Search`, no el núcleo RBAC) pero es su **co-requisito**: cierra la puerta keyset **antes** de que RM-4 cree el par de rutas con acceso divergente.
- Convención de tests keyset del repo (heredada): fakes/mothers in-tree (`Mother/*`), tests directos del engine (Functional sin HTTP), un test de estabilidad byte-a-byte (`TraceEquivalenceStabilityTest`) como red de seguridad del contrato del cursor. RM-1 confirmó: `make php.quality` corre `rector`/`cs-fixer`/`phpmd`/`deptrac` — correrlo antes de declarar hecho para diffs limpios.

### Git Intelligence

- Rama: `feat/shared-keyset-route-fingerprint-6rwz` (worktree), base `main` en `edd69e44` (#456, RM-1). Commit reciente relevante: #456 (RM-1) + #454 (ADR RBAC).
- El keyset core (`Shared/Search`) shippeó en la extracción #373/#400 (proyección JOIN, contrato de read-projection); el estilo a seguir es el de sus VOs `final readonly` con docblock que explica el *por qué* y su papel en la cadena canónica (`AppliedSort`/`AppliedLimit`).

### Project Context Reference

`docs/project-context.md` obligatorio antes de codificar: `declare(strict_types=1)`, tipos en todo, `readonly`, enums sobre constantes, excepciones para error-flow, **sin framework en `Domain/`** (el cambio es todo `Infrastructure/`, OK), PHPStan `level: max` como única puerta de tipos, AAA + fakes in-tree, `make` desde la raíz del repo. **No** snapshot de lógica de negocio — el snapshot del SQL (`KeysetSqlSnapshotTest`) y la estabilidad canónica son de **contrato**, la excepción aceptada.

### Questions for Sergio (confirmar durante dev; recomendación por defecto entre corchetes)

1. **Realización del discriminante** — ¿derivarlo estructuralmente de la `QueryBuilder` base **[recomendado: sí, honra NFR9 y es automático]** o pasar un token de ruta explícito por controlador? (Ver §1.)
2. **Bump de versión** — `CURRENT_VERSION 1→2` invalida los cursores en vuelo (efímeros → repaginación transparente). **[Recomendado: sí — lo exige FR15; el coste es nulo en práctica.]** ¿OK?
3. **`getDQL()` vs `join`+`where`** — **[Recomendado: `join`+`where` para ceñirse al AC; `getDQL()` como fallback]**. Se fija con TDD; ¿preferencia?
4. **Follow-up audit DBAL** — ¿abro issue de seguimiento por el mismo patrón latente en `AuditTimelineKeysetPaginator`, o lo dejo anotado sólo en el PR? **[Recomendado: nota en el PR ahora; issue si algún día gana un predicado base scoped.]**

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Claude Opus 4.8, 1M context) — dev-story workflow (create-story + implementación en 1 PR).

### Debug Log References

- `make php.stan` — **OK, 0 errores** (802 ficheros).
- `make php.quality` — **EXIT=0** (rector, cs-fixer, phpmd `0 violations`, phpcs, gherkin, doctrine, error-contract, bounded-context, event-bus, deptrac `0 violations / 0 uncovered / no new layer`). Sin cambio en `docs/api-error-contract.md` (no se añadió/cambió marker → NFR26 no se dispara).
- Unit keyset: **101 tests** verde (incl. los nuevos casos cross-scope de `FingerprintCanonicalizerTest`/`TraceEquivalenceStabilityTest`; `CursorCodecTest` sin cambios, v1).
- Functional keyset: `KeysetBaseQueryScopeTest` (2) + `KeysetGoToDateSeamTest` + `KeysetSqlSnapshotTest` (el SQL **no** cambia) + `KeysetOrderStabilityPropertyTest` = **35 tests** verde. Route-level cursor/search functional (`BankSearchCursorFunctionalTest`, bank-account search) = **65 tests** verde.
- Behat: bank_account (colección + anidada, incl. 2 escenarios cross-route) = **20 escenarios / 223 steps** verde; bank + audit timeline (regresión del cambio canónico) = **78 escenarios / 681 steps** verde.

### Completion Notes List

- **Discriminante = base DQL completo vía `getDQL()`** (la opción §8-fallback, elegida sobre `join`+`where`). Motivo: `getDQLPart()` devuelve `mixed` (fricción con PHPStan `level: max`), mientras `getDQL(): string` es type-clean, determinista por ruta y un **superset** del `WHERE`/`JOIN` que pide el AC (sólo puede ser un binding *más estricto*, nunca más laxo). Documentado en el docblock de `baseQueryIdentity()`. **Estructural, no por valor**: `:bankId = A` y `:bankId = B` comparten identidad de base — el valor es trabajo del filtro, no del scope.
- **Nuevo argumento `string $baseQuery` (último) en `QueryExecutionTrace`**, no un VO (YAGNI: `entity`/`tenant` ya son `string`; un VO de un solo string no compra nada — Regla de Tres). Segmento canónico **al final** de la cadena (`…|limit|baseQuery`): las demás posiciones (FQCN/enum/int/JSON) no contienen `|`, así que un byte de DQL en el último segmento mantiene el mapeo inyectivo.
- **Captura ANTES de `filterApplier->apply`** en `paginate()` (el paso 2 muta la QB con los filtros) y directa en `synthesizeCursor()` (QB prístina, filtros sobre clon) — en lockstep, para que un cursor sintético valide en su `paginate` acompañante.
- **`CURRENT_VERSION` se mantiene en `1`** (decisión de Sergio, greenfield pre-producción). El fix cambia la cadena canónica, pero no hay cursores liberados (efímeros, nunca persistidos → ninguno v1 «en vuelo»; confirmado en review: el único literal de cursor del árbol es el golden vector). El bump no protegería nada operativamente; se **acota el invariante FR15** en el docblock de `Cursor` a «formatos liberados». Un (inexistente) cursor viejo se rechaza igual vía `InvalidCursor::fingerprint()` → 422 (indistinguible en el wire de un `version`). `CursorCodec` + `CursorCodecTest` quedan idénticos a `main`.
- **Sin marker de error nuevo**: se reutiliza `InvalidCursor::fingerprint()` → 422 `invalid-cursor` (marker `InvalidSearchCriteria`). `ProblemDetailsFactory` intacto.
- **Nuevo step Behat** `I follow the :node link ... rebased onto :path` (`HttpRequestContext`): conserva el query string (cursor opaco) y cambia sólo la ruta — modela el replay cross-ruta. **Gotcha resuelto**: los `links.next` generados llevan prefijo `/api/v1`; el step pasa la ruta **relativa** para que `iSendARequestTo` aplique el `baseUrl`, en vez de prefijar `http://localhost` (que saltaba el prefijo → 404 en vez de 422).
- **Fuera de alcance, anotado**: `AuditTimelineKeysetPaginator` (paginador **DBAL** separado) NO usa este canonicalizer y no se toca; podría cargar la misma clase de bug de forma independiente si algún día gana un predicado base scoped → candidato a issue de follow-up (no se abre superficie aquí).
- **Self-review de seguridad**: RM-2 **es** un endurecimiento (cierra un scope-widening). Sin nueva superficie: el discriminante se deriva server-side de la QB (no de input del cliente) y se hashea (xxh128 → HMAC), nunca se devuelve; el `after` sigue validado por el codec antes de usarse; sin SQL nuevo, sin migración, sin secretos, sin cambio de auth (el gateo es RM-4).

### Change Log

- `QueryExecutionTrace`: nuevo argumento sellado `baseQuery` (identidad estructural de la query base) + docblock del *por qué*.
- `FingerprintCanonicalizer::canonical()`: nuevo segmento canónico final `baseQuery`.
- `Cursor` docblock: invariante FR15 acotado a «bump al cambiar un formato liberado» — `CURRENT_VERSION` se mantiene en `1` (greenfield pre-producción, sin cursores liberados).
- `DoctrineSearchEngine`: `baseQueryIdentity()` privado; capturado en `paginate()` (antes de filtros) y `synthesizeCursor()`; pasado a ambos `new QueryExecutionTrace(...)`.
- Tests actualizados (`chain()`, `TraceMother`) + añadidos (`KeysetBaseQueryScopeTest`, casos cross-scope en `FingerprintCanonicalizerTest`/`TraceEquivalenceStabilityTest`) + step Behat `rebased onto` + 2 escenarios de replay cross-ruta.
- **Code review (3 capas adversariales, 2026-07-07):** sin BLOCKER/HIGH. Resueltos: comentario de inyectividad reescrito a la razón correcta, test cross-scope del cursor sintético añadido, nota de precondición `getDQL` en el docblock. `php.quality` EXIT=0.
- **Decisión post-review (Sergio):** mantener `CURRENT_VERSION=1` (greenfield pre-producción, sin cursores liberados) — `CursorCodec`/`CursorCodecTest` revertidos a `main`; el invariante FR15 se acota a «formatos liberados» en el docblock de `Cursor`.

### File List

- `api/src/Shared/Search/Infrastructure/Persistence/Doctrine/Keyset/QueryExecutionTrace.php` (modified — nuevo arg sellado + docblock)
- `api/src/Shared/Search/Infrastructure/Persistence/Doctrine/Keyset/FingerprintCanonicalizer.php` (modified — segmento canónico)
- `api/src/Shared/Search/Infrastructure/Persistence/Doctrine/Keyset/Cursor.php` (modified — docblock: invariante FR15 acotado a formatos liberados)
- `api/src/Shared/Search/Infrastructure/Persistence/Doctrine/DoctrineSearchEngine.php` (modified — `baseQueryIdentity()` + 2 call-sites)
- `api/tests/Unit/Shared/Search/Infrastructure/Persistence/Doctrine/Keyset/FingerprintCanonicalizerTest.php` (modified — `chain()` + 2 tests)
- `api/tests/Unit/Shared/Search/Infrastructure/Persistence/Doctrine/Keyset/TraceEquivalenceStabilityTest.php` (modified — 2 casos cross-scope)
- `api/tests/Unit/Shared/Search/Infrastructure/Persistence/Doctrine/Keyset/Mother/TraceMother.php` (modified — nuevo arg)
- `api/tests/Functional/Shared/Persistence/KeysetBaseQueryScopeTest.php` (new — gate engine-level)
- `api/tests/Behat/Context/HttpRequestContext.php` (modified — step `rebased onto`)
- `api/features/backoffice/bank_account/search_collection.feature` (modified — escenario cross-route)
- `api/features/backoffice/bank_account/search.feature` (modified — escenario cross-route)
- `_bmad-output/implementation-artifacts/rm-2-cierre-fingerprint-keyset-437.md` (new — story)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (modified — RM-2 → done)
- `_bmad-output/implementation-artifacts/deferred-work.md` (modified — follow-up `getDQL` hardening del code review)
