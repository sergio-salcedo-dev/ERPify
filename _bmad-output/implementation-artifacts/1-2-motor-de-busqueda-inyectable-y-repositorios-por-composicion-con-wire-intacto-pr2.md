---
baseline_commit: 8bfb8b7835293d5c5bb50eb7117a3f717e11bda8
---

# Story 1.2: Motor de búsqueda keyset inyectable OFF-WIRE — engine + Row Uniqueness Guard + migración de infraestructura + suites de verificación directa (PR2)

> **Reconciliación de alcance (D-1, 2026-06-11).** El título y los AC originales prometían "repositorios por composición" y "el envelope viejo emitido desde el motor nuevo". Por las decisiones arquitectónicas vinculantes **D-1** (PR2 = engine off-wire; PR3 = único flip observable), eso NO es lo que PR2 entrega. Esta historia se ha reescrito para que el contrato coincida con lo realmente aprobado y entregado: un engine keyset de especificación, verificado por tests directos y NO conectado al wire. El trabajo de composición de repositorios se **trasladó a la Story 1.3 (PR3)** (decisión D-1, 2026-06-11) — ver AC3 y `epics.md` (FR Coverage Map FR9/FR11/FR12 + AC de la Story 1.3).

Status: in-progress

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a desarrollador de ERPify,
I want que `DoctrineSearchEngine` exista como engine keyset de especificación — único query-shaper del read-path, gobernado por el trace sellado y el Row Uniqueness Contract, verificado por tests directos (property/snapshot/contract) y **NO conectado al wire** (el `Paginator` legacy sigue sirviendo el HTTP),
so that la corrección de la ejecución keyset quede sellada y demostrada antes del flip observable de PR3, sin exponer ningún cambio al consumidor en esta historia.

## Acceptance Criteria

1. **Pipeline fijo de 8 pasos + trace como fuente única (FR8, AR3, AR10, AR22):** Given una búsqueda paginada de Bank, When el engine la ejecuta, Then sigue el pipeline fijo de 8 pasos (1: sort→`AppliedSort`; 2: filtros→`AppliedFilters`; 3: limit→`AppliedLimit`; 4: sellado del trace + fingerprint; 5: validación de cursor; 6: predicado keyset; 7: exec con trick +1; 8: `Page` inmutable + cursores) con invariantes impuestos hasta el paso 6. And `FilterApplier.apply()` devuelve `AppliedFilters` y el fingerprint deriva exclusivamente del trace sellado — ningún output de la capa de validación muta post-fingerprint. And el fetch de `before` invierte el ORDER BY en SQL y re-invierte en memoria, contenido en el ejecutor (FR13/K15).

2. **Guard del Row Uniqueness Contract (AR5):** Given un query builder base cuyo join con `addSelect` apunta a una asociación to-many — definida como asociación con `ClassMetadata::isCollectionValuedAssociation()` true (OneToMany / ManyToMany según el mapping de Doctrine), nunca por heurística de nombres, When el engine inspecciona el QueryBuilder **en el momento de sellar el trace** (paso 4 — antes de validar el cursor y antes de cualquier compilación), Then lanza `LogicException` clasificada como programmer error (→ `exception_category` critical, despierta on-call). And el fallo NUNCA es `InvalidCursor` ni ningún 422: es un bug del repositorio, no un error del cliente — prohibido que el pipeline RFC 9457 lo degrade a error de petición. And los joins to-one con `addSelect` están permitidos; las colecciones to-many se cargan por segunda query batch fuera del read-path paginado. And el engine jamás añade DISTINCT. And controllers y use cases nunca tocan QueryBuilder/applier/codec: los repositorios solo aportan su query builder base con joins.

3. **Repositorios por composición — FUERA DEL ALCANCE DE ESTA HISTORIA, diferido (FR9/K9, FR12, FR11 parcial):** Por la decisión D-1 (PR2 = engine off-wire; PR3 = único flip observable), migrar `DoctrineBankRepository`/`DoctrineBankAccountRepository` de herencia a composición — puertos de dominio con `EntityManagerInterface` inyectado, sin `ServiceEntityRepository`/`getEntityClassName()`/`QueryBuilderWithOptions`/`PaginatorOption` —, relajar el `save()` sin flush implícito (FR12) y eliminar los helpers muertos de `AbstractDoctrineRepository` (FR11 parcial) **no forma parte del entregable de PR2**: ese trabajo solo tiene sentido cuando el engine entra al runtime path. En PR2 los repos conservan su forma actual (herencia + `Paginator` legacy) **intacta**. **Decisión D-1 (2026-06-11): el bloque se trasladó íntegro a la Story 1.3 (PR3)** — reasignado en `epics.md` (FR9/FR11/FR12 + AC de la Story 1.3). _Criterio de aceptación en PR2: ningún cambio en los repositorios ni en `AbstractDoctrineRepository`._

4. **`SortFieldMapIndexContractTest` + migración de infraestructura (NFR3 refinado, AR13, AR23, AR17):** Given cada entrada de `sortFieldMap()` de un repositorio de búsqueda, When corre `SortFieldMapIndexContractTest` en CI, Then asserta vía ClassMetadata la **propiedad de estabilidad de orden bajo igualdad del sort key, no la forma física del índice**: columna UNIQUE → su índice único de una columna satisface la regla; columna sortable no única → exige índice compuesto `(columna, id)`; en ambos casos `nullable: false`; y toda columna de texto sortable declara `COLLATE "C"`. And se añaden `(created_at, id)` y `(updated_at, id)` en Bank como índices secundarios — los simples existentes (`idx_bank_created_at`/`idx_bank_updated_at`) se conservan — y se aplica `COLLATE "C"` a `name_normalized` y `short_name`, todo en una única migración de infraestructura. And esa migración es evolución de infraestructura (índices + determinismo de ordenación), no contractual: no cambia esquema lógico, entidad ni semántica de dominio, por lo que **no reabre el pin "cero migraciones" de NFR6**.

5. **`KeysetOrderStabilityPropertyTest` — gate normativo de la propiedad (AR13, AR4, AR23):** Given un dataset Bank sembrado para máxima adversidad de empates (~50 filas en orden físico aleatorio respecto a sus ids; perfil sesgado con ~80% de filas en un único grupo de empate de `created_at`/`updated_at` a precisión de segundo, generadas desde datetimes PHP que difieren solo en microsegundos; texto de alfabeto seguro `[a-z0-9]`), When corre `KeysetOrderStabilityPropertyTest` (funcional, Postgres real, en `api/tests/Functional/Shared/Persistence/`) contra `DoctrineSearchEngine` directamente — sin HTTP — por cada entrada de `sortFieldMap()` × ASC/DESC, con `limit` menor que el grupo de empate dominante, Then valida la propiedad **solo con dataset + asserts, sin consultar índices, ClassMetadata ni planes de ejecución**: (1) oráculo determinista por full-scan `ORDER BY (col, id)`; (2) partición exacta de la caminata `after` (sin duplicados, sin huecos, longitud N); (3) frontera intra-empate (cursor de una fila frontera reanuda en la siguiente del oráculo); (4) simetría `before` (oráculo invertido exacto); (5) precisión a segundos (microsegundos colapsan a un grupo; UNIQUE degeneran a grupos de tamaño 1). And el test pasa idéntico con o sin los índices compuestos presentes (la propiedad es de corrección, no depende de la forma física — gate normativo; `SortFieldMapIndexContractTest` y el perf gate quedan subordinados). And las infra-asunciones del oráculo quedan selladas por contrato (`id` PK UUID byte-wise + `COLLATE "C"` en columnas de texto sortables). And la validez es correct-by-result (AR4): un cambio de plan físico que preserve la propiedad no es regresión.

6. **`KeysetSqlSnapshotTest` derivado no normativo (AR4, AR20, AR22):** Given el SQL compilado del read-path, When corre `KeysetSqlSnapshotTest`, Then compara únicamente SQL string + parámetros bindeados + ordering — nunca objetos Doctrine. And el snapshot es derivado NO normativo: detector de regresiones, jamás contrato de compatibilidad runtime.

7. **Wire intacto + gates verdes + docs (AR16, AR18, FR3):** Given la suite Behat completa, When corre CI, Then los 52 bloques existentes de `search.feature` pasan **sin modificación** — el wire queda intacto **porque el engine es OFF-wire**: el `Paginator` legacy sigue siendo el único que sirve el read-path HTTP (page-based navigation, `currentPage`/`pageCount`, degradación silenciosa de cursor inválido, modos LIGHT/DETAILED y los conteos de query por escenario, todos preservados). El motor nuevo **NO emite el envelope en PR2**; su conexión al wire (nuevo codec + 422 + envelope `after`/`before`) es PR3. And `make php.stan` + `make php.psalm` + `make php.quality` verdes sin baselines nuevas. And los docs obligatorios del PR se actualizan (AR18): `docs/architecture-api.md`, `api/docs/adding-endpoints.md`, `docs/source-tree-analysis.md`.

## Tasks / Subtasks

- [x] Task 0: Entorno aislado y baseline (AC: #7)
  - [x] `make worktree.create BRANCH=feat/api-keyset-pagination` (regla dura: jamás trabajar en `main`); `cd` al worktree y `make app.dev`
  - [x] Smoke: `make php.behat` con `search.feature` verde **antes** de tocar nada (línea base — los 52 bloques deben pasar al empezar y al terminar)
  - [x] Releer el kernel de PR1 (`…/Doctrine/Search/Keyset/`) y los ficheros de la tabla "Código existente que DEBES leer" abajo — no implementar de memoria

- [x] Task 1: `DoctrineSearchEngine` — esqueleto y pipeline de 8 pasos (AC: #1)
  - [x] `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/DoctrineSearchEngine.php` — `final readonly`; deps por constructor: `FilterApplier`, `CursorCodec`, `FingerprintCanonicalizer`, `KeysetPredicateBuilder`, `CursorPositionExtractor`, `PropertyAccessorInterface` (para el extractor — ya inyectable). **Único punto que toca Doctrine** (NFR4)
  - [x] Firma pública del paso de ejecución: recibe el query builder base del repo + `SearchCriteria` + `OrderByColumns` + `PaginatorConfig` + `WirePaginationPolicy`; devuelve `Page<T>` (dominio). El repo NO toca applier/codec/predicado (AC #2 última cláusula)
  - [x] Implementar los 8 pasos en orden fijo: (1) resolver sort vía `SortFieldMap` + tie-break `id` → `AppliedSort`; (2) `FilterApplier.apply()` → `AppliedFilters` (ver Task 4); (3) resolver limit clampeado por policy → `AppliedLimit`; (4) **sellar `QueryExecutionTrace` + `fp = xxh128(canonical)`** vía `FingerprintCanonicalizer` — y aquí va el guard del Row Uniqueness Contract (Task 2); (5) validar cursor con `CursorCodec::decode(encoded, fp, dir)`; (6) construir predicado keyset (`KeysetPredicateBuilder::build`); (7) ejecutar con trick +1 (`before`: invertir + re-reverse, Task 3); (8) construir `Page` inmutable + extraer/codificar ambos cursores
  - [x] Invariantes impuestos hasta el paso 6; nada posterior forma parte de las garantías. El fingerprint deriva EXCLUSIVAMENTE del trace sellado — ningún output de validación muta post-fingerprint (AC #1, AR22)

- [x] Task 2: Guard del Row Uniqueness Contract (AC: #2)
  - [x] En el paso 4 (antes de validar cursor / compilar): inspeccionar el QueryBuilder; si un `addSelect` apunta a una asociación to-many → `LogicException`. Detección **solo** por `ClassMetadata::isCollectionValuedAssociation()` (OneToMany/ManyToMany), JAMÁS por heurística de nombres
  - [x] El `LogicException` debe clasificar como programmer error → `exception_category` critical. Verificar en `docs/api-error-contract.md` cómo se mapea `LogicException`/programmer_error al category critical; NO convertirlo en `InvalidCursor` ni 422
  - [x] Permitir joins to-one con `addSelect`; el engine jamás añade DISTINCT
  - [x] Test unitario del guard: QB con join to-many+addSelect → `LogicException`; QB con join to-one+addSelect → OK

- [x] Task 3: Ejecutor — trick +1 y `before` interno (AC: #1)
  - [x] `after`: ORDER BY normal, `setMaxResults(limit + 1)`; si vuelven `limit+1` filas → `hasNext = true`, se descarta la extra
  - [x] `before`: invertir cada dirección del ORDER BY en SQL, ejecutar con trick +1, re-invertir la lista en memoria antes de construir `Page`. Contenido íntegramente en el ejecutor — invisible al contrato, testeable como función pura (FR13/K15)
  - [x] Extraer `nextCursor`/`prevCursor` con `CursorPositionExtractor` desde la última/primera fila respectivamente; codificar con `CursorCodec::encode`
  - [x] **Guardar aridad ANTES de invocar `KeysetPredicateBuilder`** (item diferido de review 1.1): el codec no valida aridad; si `values` tiene columnas faltantes/sobrantes respecto a `OrderByColumns`, el builder lanzaría `InvalidArgumentException` (500). El engine debe garantizar la aridad o mapear ese fallo a `InvalidCursor` (422) para preservar el contrato — decidir y documentar cuál (ver Open Question OQ-2)

- [x] Task 4: `FilterApplier.apply()` devuelve `AppliedFilters` (AC: #1, #3)
  - [x] Cambiar la firma de `FilterApplier::apply()` de `void` a `AppliedFilters` — devuelve el recibo de lo realmente aplicado (los `Filter` que pasaron el allow-list y se tradujeron a condiciones). Mantener intacta la traducción a `andWhere` + binds y los escapes LIKE
  - [x] El recibo alimenta el paso 4 del pipeline (trace); el fingerprint sale del recibo, no del input crudo (AR22)
  - [x] Actualizar el único caller actual (`AbstractDoctrineSearchRepository::getPaginatedResults`) y cualquier test de `FilterApplier` que asuma `void`

- [ ] Task 5: Repositorios por composición (AC: #3) — **DIFERIDA A PR3 (decisión sellada D-1, Sergio 2026-06-10).** Mover los repos a composición exige que `search()` delegue en el engine, lo que mete el engine en el HTTP wire-path — prohibido en PR2 (el engine es *specification engine* off-wire). Los repos siguen usando el `Paginator` legacy en PR2; el flip (engine→runtime + repos por composición + borrado FR11 acoplado) es PR3. No es trabajo incompleto: es alcance reasignado por el usuario.
  - [ ] `DoctrineBankRepository` — dejar de extender `AbstractDoctrineSearchRepository`; implementar solo `BankRepository`/`BankSearchRepository`/`BankStoredObjectQueries`. Inyectar `EntityManagerInterface` (+ `DoctrineSearchEngine`, `NormalizedTextFieldNormalizer`, `AsciiUpperTextFieldNormalizer`). Conservar `#[AsAlias]` de los tres puertos
  - [ ] `search()`: construir el query builder base (`$this->em->createQueryBuilder()->select('b')->from(Bank::class, 'b')` + joins si los hubiera; aquí no hay), declarar `searchFieldMap()`/`sortFieldMap()` igual que hoy, y delegar en `DoctrineSearchEngine`. Las consultas no-search (`countBanksWithStoredObjectContentHash`, `findStoredObjectMimeTypeByContentHash`, `findById`) se reescriben con `EntityManagerInterface` directo (sin `createQueryBuilder` heredado, sin `find()` heredado)
  - [ ] `save()` sin flush implícito obligatorio en el contrato del puerto (FR12). **Atención al wire intacto**: si hoy `save()` hace `persistAndFlush`, mantener el comportamiento observable para no romper Behat de POST/PUT/DELETE — el requisito es que el *puerto* no obligue al flush implícito, no cambiar la semántica actual en esta historia (ver Open Question OQ-3)
  - [ ] `DoctrineBankAccountRepository` — dejar de extender `AbstractDoctrineRepository`; implementar solo `BankAccountRepository` con `EntityManagerInterface`. `countByBankId()` reescrito con `em->createQueryBuilder()`
  - [ ] **No tocar `AbstractDoctrineSearchRepository`/`AbstractDoctrineRepository`/`Paginator`/`PaginatorOption`/`QueryBuilderWithOptions` como borrado** — su muerte es PR4 (Story 1.5). En PR2 solo se *desacoplan* los repos de ellos; quedan huérfanos pero presentes
  - [ ] FR11 parcial: eliminar de `AbstractDoctrineRepository` los helpers muertos *accesibles* que ya no tiene caller tras el desacople (`addWhereInCaseInsensitive`, `addWhereBetweenDates`, `addWhereBetweenValues`, `sanitizeArray`, la doble llamada de `generateUniqueParameterName`) **solo si quedan sin caller** — preservar el comentario `// why:` del naming estable de parámetros. Si algún helper sigue con caller en PR2, diferir su borrado total a PR4

- [x] Task 6: Migración de infraestructura (AC: #4)
  - [x] `make db.diff` NO bastará (los índices compuestos y la collation no salen de un diff de entidad simple); generar la migración y editar su `up()`/`down()` a mano. Editar migraciones de la rama de feature está permitido (regla del repo); una vez mergeada es inmutable
  - [x] `up()`: `CREATE INDEX idx_bank_created_at_id ON bank (created_at, id)`; `CREATE INDEX idx_bank_updated_at_id ON bank (updated_at, id)`; aplicar `COLLATE "C"` a `name_normalized` y `short_name` (`ALTER TABLE bank ALTER COLUMN name_normalized TYPE VARCHAR(255) COLLATE "C"`; idem `short_name` VARCHAR(50)). Conservar `idx_bank_created_at`/`idx_bank_updated_at` (NO borrarlos)
  - [x] `down()` reversible: `DROP INDEX` de los dos compuestos + revertir la collation a default. Patrón `DROP INDEX IF EXISTS` (consistente con issue #207)
  - [x] Declarar los índices compuestos en el entity `#[ORM\Index]` para que `make db.validate` quede limpio (schema-in-sync). La collation por columna puede no reflejarse en el diff de Doctrine — verificar `make db.validate` y, si reporta drift de collation, documentar el porqué (Doctrine no modela `COLLATE` por columna de forma nativa; ver Open Question OQ-4)
  - [x] `make db.migrate` en el worktree; confirmar el DDL con `make db.shell` (`\d+ bank`)

- [x] Task 7: `SortFieldMapIndexContractTest` (AC: #4)
  - [x] `api/tests/Functional/Shared/Persistence/SortFieldMapIndexContractTest.php` — por cada entrada de cada `sortFieldMap()` de repo de búsqueda (hoy solo Bank): vía `ClassMetadata` assertar la **propiedad**, no la forma física. UNIQUE de una columna satisface; sortable no-única exige índice `(columna, id)`; en ambos casos `nullable: false`; columnas de texto sortables declaran `COLLATE "C"`
  - [x] Es gate de **forma**, subordinado a la propiedad (AR13: propiedad > forma > snapshot). Lee metadata/esquema, no ejecuta búsquedas

- [x] Task 8: `KeysetOrderStabilityPropertyTest` — gate normativo (AC: #5)
  - [x] `api/tests/Functional/Shared/Persistence/KeysetOrderStabilityPropertyTest.php` — Postgres real, contra `DoctrineSearchEngine` directamente (sin HTTP). Object mothers reutilizables de PR1 (`TraceMother`/`CursorMother`) donde apliquen
  - [x] Sembrar el dataset adverso (ver AC #5 y Dev Notes → "Dataset del property test"): ~50 Bank en orden físico aleatorio vs ids; ~80% en un grupo de empate `created_at`/`updated_at` a segundo (datetimes que difieren solo en microsegundos); texto `[a-z0-9]`
  - [x] Por cada entrada de `sortFieldMap()` × {ASC, DESC}, con `limit` < grupo de empate dominante, validar los 5 invariantes (oráculo, partición exacta `after`, frontera intra-empate, simetría `before`, precisión a segundos)
  - [x] El test pasa idéntico con o sin los índices compuestos (ejecutarlo antes y después de la migración de Task 6 para probarlo)

- [x] Task 9: `KeysetSqlSnapshotTest` (AC: #6)
  - [x] `api/tests/Functional/Shared/Persistence/KeysetSqlSnapshotTest.php` — compara SOLO SQL string + binds + ordering del read-path compilado; nunca objetos Doctrine (AR20). Snapshot derivado no normativo (detector de regresión)
  - [x] No usar snapshots de PHPUnit "mágicos"; assertar strings explícitos (KISS; el repo prohíbe snapshot tests de lógica de negocio — esto es shape de SQL, aceptable como string assert explícito)

- [x] Task 10: Wire intacto — verificación y resolución de `OrderByColumns::fromSorts` (AC: #7)
  - [x] Resolver el item diferido de review 1.1: `OrderByColumns::fromSorts` solo deduplica `id` cuando es la última clave. PR2 cablea sorts reales — añadir guard/dedup para `id` en posición no-última antes de cablear (evita `id` duplicado en ORDER BY/predicado)
  - [x] Resolver el item diferido de precisión: round-trip real contra Postgres (cubierto por el property test, AC #5 invariante 5) de que las filas frontera no se saltan/duplican en empates sub-segundo
  - [x] `make php.behat` — los 52 bloques pasan SIN tocar `search.feature` ni fixtures. Si un escenario falla, ES un bug de esta historia (algo cambió el wire). Verificar especialmente: degradación silenciosa de cursor inválido (200, no 422), `?page=2&cursor=` navegación, `currentPage`/`pageCount`, conteos de query por escenario (2/3/4/5)

- [x] Task 11: Gates, docs y cierre (AC: #7)
  - [x] `make php.stan` por archivo cambiado durante el desarrollo
  - [x] `make php.unit` + `make php.behat` verdes (suites nuevas + existentes)
  - [x] `make php.quality` al cierre (stan + psalm + error-contract + phpmd) — verde sin baselines nuevas. Vigilar Psalm `findUnusedCode`: el engine consume el API público de PR1 (debería resolver `PossiblyUnusedMethod` pendientes); si algo legítimo dispara, suppression puntual con `// why:`, jamás regenerar baseline sin limpiar `var/cache/psalm`
  - [x] Docs obligatorios (AR18): `docs/architecture-api.md` (engine + repos por composición), `api/docs/adding-endpoints.md` (cómo un repo de búsqueda nuevo aporta su QB base y delega en el engine), `docs/source-tree-analysis.md` (nuevo `DoctrineSearchEngine`)
  - [x] Self-review de seguridad del repo (checklist backend de CLAUDE.md) antes del PR

## Dev Notes

### Contexto arquitectónico imprescindible

- **Fuente única de requisitos:** ADR `_bmad-output/planning-artifacts/architecture-keyset-pagination.md` (status: IMPLEMENTATION LOCKED) refinado por `implementation-readiness-report-2026-06-10.md`. Ante duda, el ADR manda; ante conflicto con CLAUDE.md/docs/rules, señalar el conflicto en vez de elegir.
- **Secuencia vinculante AR16:** PR1 (kernel puro, hecho y mergeado en `main` — commit `8bfb8b7`) → **PR2 (esta historia: engine + repos, wire intacto)** → PR3 (el flip: envelope nuevo + PWA + Behat + métricas + válvula — único cambio observable) → PR4 (borrado del legado). Cualquier desviación del orden invalida el modelo de validación.
- **Qué entrega PR2:** el `DoctrineSearchEngine` se convierte en el único query-shaper del read-path (reemplaza la mecánica física del `Paginator` legacy: predicado keyset, tie-break, trick +1, before/after). Los repos de Bank/BankAccount pasan a composición. **Pero el contrato observable en el wire NO cambia** (eso es PR3).

### ⚠️ DECISIÓN LOAD-BEARING: qué significa "wire intacto" en PR2 (leer antes de codear)

Es la decisión más importante de la historia. La red Behat (52 bloques) fija semántica **legacy** que es incompatible con el contrato keyset nuevo. Reconciliación pineada:

| Dimensión wire | Comportamiento legacy que Behat fija | En PR2 |
|---|---|---|
| Navegación | `?page=2&cursor={value}` (page-based); `currentPage` se devuelve tal cual lo pidió el cliente | **Se preserva**: el engine echo el `page` recibido; navega físicamente por keyset pero reporta el número de página |
| Envelope | `{currentPage, pageCount, count, hasMorePages, cursor}` (5 claves) | **Se preserva byte-a-byte** vía el adaptador legacy (`PaginationMeta`/`SearchResponder` intactos) |
| Cursor en el wire | `PaginatorCursorFactory` (zlib + `{currentPage,count,firstItem,lastItem}` + HMAC hex completo) | **Se preserva**: el cursor wire sigue siendo el legacy. El `CursorCodec` nuevo (base64url + HMAC-32 + fingerprint + 422) NO va al wire en PR2 |
| Cursor inválido | Degradación silenciosa → cursor vacío → 200 página 1 (escenario "Cursor without HMAC signature is silently treated as empty") | **Se preserva**: en el wire, cursor inválido sigue degradando a 200. El 422 `invalid-cursor` del codec nuevo solo se activa en PR3 |
| Modos | LIGHT (sin COUNT, `pageCount: null`) / DETAILED (COUNT, `pageCount` numérico) | **Se preserva** tal cual (FR3) |
| Conteos de query | `2`/`3`/`4`/`5` "requests for doctrine connection default" por escenario | **Se preserva**: el engine debe reproducir el número de queries por request (trick +1 = 1 query principal; COUNT solo en DETAILED) |

**Implicación de diseño (recomendada, confirmar OQ-1):** en PR2 el `DoctrineSearchEngine` corre la **maquinaria keyset interna** (predicado, OrderByColumns con tie-break, trick +1, before/after, trace+fingerprint) y se verifica con tests **directos** (`KeysetOrderStabilityPropertyTest`, `KeysetSqlSnapshotTest`, contract test) — "sin HTTP". El **wire-facing** (encoding del cursor, page semantics, degradación silenciosa, envelope) permanece legacy: `SearchResponder`, `PaginationMeta` y `PaginatorCursorFactory` NO se tocan. El engine emite hacia el responder la misma forma `PaginatedResult` que hoy (o un adaptador delgado `Page`→`PaginatedResult`). El flip a cursor-only (codec nuevo en el wire, 422, after/before, envelope nuevo, válvula) es **íntegramente PR3** — y debe ser revertible sin tocar PR2 (AR16).

**El riesgo nº1 de esta historia es romper el wire.** Cualquier 422 nuevo, cambio de envelope, o cambio de conteo de query observable en Behat = bug de PR2. La consigna de review: *¿este cambio es observable en el wire? Si sí, no pertenece a PR2.*

### Pipeline fijo de 8 pasos del engine (ADR Process Patterns — "Orden del pipeline del motor")

```text
1. Resolver sort vía SortFieldMap + tie-break id   → recibo AppliedSort
2. Aplicar FilterApplier                           → recibo AppliedFilters
3. Aplicar limit (clampeado por policy)            → recibo AppliedLimit
4. SELLAR QueryExecutionTrace + computar fingerprint   ← aquí el guard Row Uniqueness (paso 4)
5. Validar cursor (intrínseco → extrínseco: firma→versión→payload→fingerprint)
6. Construir predicado keyset (pre-compilación)
7. Ejecutar con trick +1 (before: invertir + re-reverse contenido en el ejecutor)
8. Construir Page inmutable + codificar ambos cursores
```

Invariantes impuestos **hasta el paso 6**; nada posterior forma parte de las garantías (AR10). El trace se sella en el paso 4 y es la **fuente semántica única** (AR22): el fingerprint sale del trace, jamás del input crudo; ningún output de validación muta post-fingerprint.

### Firmas EXACTAS del kernel de PR1 que el engine consume (no reinventar)

| Pieza | Firma a consumir |
|---|---|
| `FilterApplier::apply()` | **HOY** `apply(QueryBuilder, Filters, SearchFieldMap): void` → **CAMBIAR a** `: AppliedFilters` (Task 4) |
| `CursorCodec::decode()` | `decode(string $encoded, string $expectedFingerprint, string $expectedDir): Cursor` — lanza `InvalidCursor`. El engine cablea `fp` esperado desde el trace y `dir` desde el routing intent |
| `CursorCodec::encode()` | `encode(Cursor): string` |
| `Cursor` | `new Cursor(int $version, string $direction, array $values, string $fingerprint)`; `Cursor::DIRECTION_AFTER='after'`, `DIRECTION_BEFORE='before'` |
| `FingerprintCanonicalizer` | produce la cadena canónica `tenant\|entity\|filters\|sort\|direction\|limit` desde el trace y `fp = xxh128(canonical)` (verificar firma pública en el fichero) |
| `QueryExecutionTrace` | `new QueryExecutionTrace(string $entity, AppliedFilters, AppliedSort, AppliedLimit)`; `TENANT_SLOT='__erpify_single_tenant__'` |
| `AppliedSort` | `new AppliedSort(string $field, SortDirection)` — el sort *semántico* (campo público), distinto de `OrderByColumns` (físico) |
| `AppliedLimit` | `new AppliedLimit(int $value)` (≥1) |
| `AppliedFilters` | `new AppliedFilters(Filter ...$filters)`; `AppliedFilters::none()`; `->all()` |
| `OrderByColumns` | `fromPrimarySort(string $column, SortDirection)`; `fromSorts(array)`; `tieBreakOnly()`; `TIE_BREAK_COLUMN='id'`; `->all()`, `->columnNames()`, `->tieBreakColumn()` |
| `KeysetPredicateBuilder::build()` | `build(OrderByColumns, array $values, WirePaginationPolicy): array{dql: string, parameters: array}` — lanza `InvalidArgumentException` si falta una boundary value (mapear/guardar, OQ-2) |
| `CursorPositionExtractor::extract()` | `extract(OrderByColumns, array\|object $row): array` — valores a precisión de columna (`TIMESTAMP(0)`→segundos `Y-m-d\TH:i:sP`); requiere `PropertyAccessorInterface` por constructor |
| `WirePaginationPolicy` | `WirePaginationPolicy::wire()`; `DEFAULT_LIMIT=25`, `MAX_LIMIT=100`, `exclusiveBoundary=true` (strict `>`/`<`) |
| `PaginatorConfig` | `new PaginatorConfig(PaginationMode = LIGHT, bool $fetchJoinCollection = true)` |
| `Page` (dominio) | `new Page(array $items, bool $hasNext, bool $hasPrev, ?int $count, ?string $nextCursor, ?string $prevCursor)`; `Page::empty()` |

### Código existente que DEBES leer antes de implementar

| Fichero | Por qué |
|---|---|
| `api/src/Shared/Infrastructure/Persistence/Doctrine/Paginator.php` | La mecánica física que el engine reemplaza: `buildCursorWhere()` (predicado keyset legacy), trick +1 (`getQuery` hace `+1`), `setCursorCount` (COUNT en DETAILED, short-circuit single-first-page), `extractFields` (bug de microsegundos que `CursorPositionExtractor` corrige). El engine debe reproducir su comportamiento observable (conteos de query, hasMorePages, count) |
| `api/src/Shared/Infrastructure/Persistence/Doctrine/AbstractDoctrineSearchRepository.php` | `getPaginatedResults()` (flujo actual), `addOrderByFromQueryParams()` (resuelve sort vía `sortFieldMap()->pathFor()` o `UnknownSortField` 400 — el engine hereda esta lógica), el manejo de PK compuesta |
| `api/src/Shared/Infrastructure/Persistence/Doctrine/AbstractDoctrineRepository.php` | Helpers a borrar parcialmente (FR11); el `// why:` del naming estable de parámetros (`generateUniqueParameterName`) a PRESERVAR |
| `api/src/Shared/Infrastructure/Http/Responder/SearchResponder.php` + `PaginationMeta.php` | El adaptador wire que NO se toca en PR2: emite `{currentPage,pageCount,count,hasMorePages,cursor}` desde `PaginatedResult` + `PaginatorCursorFactory` |
| `api/src/Shared/Infrastructure/Persistence/PaginatorCursorFactory.php` | El cursor wire legacy (zlib + degradación silenciosa de cursor inválido → vacío). NO se toca en PR2 |
| `api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/DoctrineBankRepository.php` | El repo a migrar a composición; sus `searchFieldMap()`/`sortFieldMap()` (4 sortables: name→`b.nameNormalized`, shortName→`b.shortName`, createdAt→`b.createdAt`, updatedAt→`b.updatedAt`) se preservan |
| `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FilterApplier.php` | `apply()` pasa de `void` a `AppliedFilters` (Task 4) |
| `api/features/backoffice/bank/search.feature` | La red de seguridad: 52 bloques (47 Scenario + 5 Outline). Escenarios de paginación en líneas ~60-130 |

### Migración — datos exactos del esquema actual (verificado 2026-06-10)

- Tabla `bank`. Columnas relevantes:
  - `id` — `UUID NOT NULL`, PK, `#[ORM\Column(type: Types::GUID, unique: true)]` (UUID v7 asignada en app, sin `GeneratedValue`)
  - `name_normalized` (prop `nameNormalized`) — `VARCHAR(255) NOT NULL`, UNIQUE `UNIQ_D860BF7AE1B35095`, `#[ORM\Column(unique: true)]`. **Sin COLLATE hoy** → añadir `COLLATE "C"`
  - `short_name` (prop `shortName`) — `VARCHAR(50) NOT NULL`, UNIQUE `UNIQ_D860BF7A3EE4B093`, `#[ORM\Column(length: 50, unique: true)]`. **Sin COLLATE hoy** → añadir `COLLATE "C"`
  - `created_at`/`updated_at` — `TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL` (segundos, confirmado), `Types::DATETIME_IMMUTABLE`
- Índices existentes en `bank`: UNIQUE `UNIQ_D860BF7AE1B35095` (name_normalized), UNIQUE `UNIQ_D860BF7A3EE4B093` (short_name), `idx_bank_created_at` (created_at), `idx_bank_updated_at` (updated_at). **NO existen** `(created_at, id)` ni `(updated_at, id)` → añadirlos.
- Migración más reciente sobre `bank`: `api/migrations/2026/Version20260608165844.php` (creó los simples created_at/updated_at). Patrón de la casa: clase `Version{YYYYMMDDHHmmss}`, namespace `DoctrineMigrations`, `getDescription()` imperativo + "story X.Y", `up()`/`down()` con `addSql`, índices descriptivos en snake_case (`idx_bank_*`).
- Por qué la propiedad NFR3 ya se satisface en las UNIQUE: una columna UNIQUE NOT NULL no admite empates, así que su índice de una columna ya da orden total con el tie-break implícito; los compuestos `(col,id)` solo hacen falta donde el sort key NO es único (created_at/updated_at).

### Dataset del property test (AC #5) — por qué cada elección

- **Orden físico aleatorio vs ids** (heap order ≠ orden lógico): elimina el falso verde por correlación inserción↔orden.
- **~80% en un grupo de empate** `created_at`/`updated_at` a segundo (datetimes PHP que difieren solo en microsegundos): fuerza que cada frontera de página caiga *dentro* de un empate, donde rompen conductualmente la multiplicidad por fetch-join, la omisión por NULL y la pérdida del tie-break.
- **Texto `[a-z0-9]`**: el orden binario en memoria coincide con `COLLATE "C"` — con el pin de columna esto pasa de necesidad a redundancia defensiva.
- **`limit` < grupo dominante**: garantiza fronteras intra-empate.
- 5 invariantes: oráculo determinista (full-scan `ORDER BY (col,id)`), partición exacta `after` (sin dup/huecos, longitud N), frontera intra-empate (literal de la propiedad), simetría `before` (oráculo invertido), precisión a segundos (micros colapsan; UNIQUE degeneran a grupo 1).

### Items diferidos del review de Story 1.1 que PR2 DEBE resolver (de deferred-work.md)

1. **Aridad del cursor:** el codec acepta `values` con aridad incorrecta / columna ausente / no-escalar → `KeysetPredicateBuilder` lanzaría `InvalidArgumentException` (500). PR2 debe garantizar la aridad antes de invocar al builder, o mapear ese `InvalidArgumentException` a `InvalidCursor` (422). **OJO wire intacto**: en PR2 esto vive en la maquinaria interna/tests directos; el 422 al cliente es PR3 (OQ-2).
2. **`OrderByColumns::fromSorts` dedup de `id`:** solo deduplica cuando `id` es la última clave; un sort multi-clave con `id` no-último duplicaría el tie-break. PR2 cablea sorts reales → guardar antes (Task 10).
3. **Floor de microsegundos vs `TIMESTAMP(0)`:** `CursorPositionExtractor` trunca a segundos, Postgres redondea. Verificar con round-trip real (el property test, invariante 5) que las filas frontera no se saltan/duplican en empates sub-segundo.

### Anti-patterns prohibidos (del ADR — el review los caza)

- ❌ Cualquier cambio observable en el wire en PR2 (envelope, cursor format, 422 nuevo, conteo de query, page semantics). Si es observable, es PR3.
- ❌ Computar el fingerprint desde el input (criteria/query string) en vez del trace sellado.
- ❌ Mutar inputs del trace después del sellado (paso 4).
- ❌ Detectar to-many por heurística de nombres en vez de `ClassMetadata::isCollectionValuedAssociation()`.
- ❌ Degradar el `LogicException` del Row Uniqueness Contract a `InvalidCursor`/422 — es programmer error critical.
- ❌ Añadir DISTINCT en el engine.
- ❌ `OFFSET`/`setFirstResult` en el read-path keyset; `setFirstResult` legacy muere con el Paginator (PR4).
- ❌ Aserciones sobre objetos `QueryBuilder`/`Query` en tests del engine — solo strings DQL/SQL + binds.
- ❌ Compartir `KeysetPredicateBuilder` entre policies sin configuración explícita (cada caller pasa su `WirePaginationPolicy`).
- ❌ Una segunda implementación de paginación (kernel único).
- ❌ Borrar `Paginator`/bases abstractas en PR2 (eso es PR4) — solo desacoplar.

### Gotchas operativos del repo (de sesiones previas — evitan ciclos de review)

- **`make app.dev` en el worktree reescribe `api/config/reference.php`** — nunca `git add -A` sin revisar; auto-generado.
- **FrankenPHP vuelca `core.N` (~1GB) en `api/` durante test runs en contenedor** — borrar, jamás commitear.
- **Lint siempre vía `make` desde la raíz** (contenedor dev; un entorno sin ext-bcmath voltea cs-fixer).
- **Psalm `findUnusedCode`:** PR2 da consumidor de producción al API público de PR1 (debería resolver `PossiblyUnusedMethod` pendientes). Si algo legítimo dispara, suppression puntual con `// why:`; **jamás** regenerar baseline sin `var/cache/psalm` limpio.
- **`SearchExceptionListener` ya retirado** (lo absorbió `ProblemDetailsFactory`): AR17 resuelto, PR2 no lo toca.
- **`make php.quality` incluye `php.lint.error-contract`:** PR2 no añade marker nuevo en el wire (el 422 `invalid-cursor` es PR3). El `LogicException` del guard es programmer_error, no marker 422 — verificar que el gate queda verde sin tocar `docs/api-error-contract.md` (si exigiera algo, añadirlo, no suprimir).
- **Commits:** Conventional Commits (`feat(api): …` / `feat(shared): …`), pre-commit hooks activos; nunca `--no-verify`, nunca amend tras fallo de hook.
- **Migración editable solo en la rama de feature**; una vez mergeada es inmutable.

### Testing

- Ubicación de los nuevos tests funcionales: `api/tests/Functional/Shared/Persistence/` (Postgres real, transacción/fixtures por test — regla del repo: integración Doctrine usa Postgres de test, no SQLite). Tests del engine sin aserciones sobre objetos Doctrine (solo SQL/DQL strings + binds).
- PHPUnit 13, atributos `#[Test]`/`#[CoversClass]`/`#[DataProvider]`; `declare(strict_types=1);`; AAA; nombres por comportamiento.
- `KeysetOrderStabilityPropertyTest` es el **gate normativo** (propiedad); `SortFieldMapIndexContractTest` (forma) y `KeysetSqlSnapshotTest` (snapshot) le quedan subordinados (AR13: propiedad > forma > snapshot).
- Comandos: `make php.unit c='--filter Keyset'` o `c='--filter DoctrineSearchEngine'` durante desarrollo; `make php.unit` + `make php.behat` + `make php.quality` al cierre.
- **No tocar `search.feature` ni fixtures**: si un escenario Behat falla, ES un bug de PR2 (algo cambió el wire).

### Stack pineado (no renegociable; leer código existente antes que memoria)

PHP 8.5 (idioms 8.3 forward-compatible) · Symfony 8.0 componentes individuales · Doctrine ORM 3.6 / DBAL 4.4 (`EntityManager::flush()` sin args; `toIterable()`; `fetchAllAssociative()`; `executeQuery()`) · PHPUnit 13 · PostgreSQL 18 (`TIMESTAMP(0)`, `COLLATE "C"`). **Cero dependencias Composer nuevas; cero migraciones funcionales de dominio** (la de índices+collation es de infraestructura, AC #4).

### Project Structure Notes

- `DoctrineSearchEngine` vive en `…\Infrastructure\Persistence\Doctrine\Search\` (raíz del seam, junto a `FilterApplier`/`PaginatorConfig`), NO dentro de `Keyset/` (los colaboradores puros viven en `Keyset/`; el engine es el orquestador con Doctrine).
- Repos por composición: cada repo concreto en su bounded context, implementando solo puertos de dominio, con `EntityManagerInterface` + `DoctrineSearchEngine` inyectados.
- Tests funcionales en `api/tests/Functional/Shared/Persistence/` (espejo del seam compartido).
- Sin conflictos detectados entre epics.md, el ADR, la readiness report y la estructura del repo (verificación 2026-06-10).

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 1.2] — acceptance criteria de origen; AR3, AR4, AR5, AR10, AR11, AR12, AR13, AR16, AR17, AR22, AR23, AR24
- [Source: _bmad-output/planning-artifacts/architecture-keyset-pagination.md] — Core Architectural Decisions (K8/K9/K15), Process Patterns (pipeline 8 pasos), Architectural Boundaries (Row Uniqueness Contract), Data Architecture (índices), Project Structure (delta PR2)
- [Source: _bmad-output/planning-artifacts/implementation-readiness-report-2026-06-10.md] — verificación de índices compuestos faltantes, collation column-level (AR23), TIMESTAMP(0) confirmado, 52 bloques Behat, SearchExceptionListener retirado
- [Source: _bmad-output/implementation-artifacts/1-1-…-pr1.md] — Story 1.1 (kernel, done); Review Findings (items diferidos a PR2); firmas exactas del kernel
- [Source: _bmad-output/implementation-artifacts/deferred-work.md#Deferred from code review of story-1.1] — los 3 items que PR2 resuelve
- [Source: docs/project-context.md] — reglas PHP/Doctrine/testing/workflow del repo
- [Source: api/src/Shared/Infrastructure/Persistence/Doctrine/Paginator.php] — mecánica física a reemplazar (conteos de query, trick +1, COUNT)
- [Source: api/features/backoffice/bank/search.feature] — la red de 52 bloques que define el wire intacto

## Decisiones confirmadas (Sergio, 2026-06-10) — selladas, no re-litigar

> **PR2 = replatforming interno sin impacto observable, NO una migración de contrato.** Engine-first, wire-stable, zero observable change. El "flip observable" queda estrictamente reservado para PR3 (AR16 al pie de la letra).

- **D-1 (load-bearing, CONFIRMADA):** El `DoctrineSearchEngine` es el motor real de paginación keyset **internamente** (predicado, sort, tie-break, trick +1, before/after, trace sellado, fingerprint), verificado con tests **directos (sin HTTP)**. El wire-facing permanece **100% legacy e inmutable**: envelope de 5 claves intacto; cursor legacy intacto (incluida la degradación silenciosa a 200); page-based navigation (`?page=`); conteos de query y LIGHT/DETAILED exactamente igual. El codec nuevo + 422 + `after`/`before` + envelope nuevo **NO aparecen en el wire en PR2**.
- **D-2 (CONFIRMADA):** El enforcement de aridad / shape del cursor en PR2 se queda en la **capa interna** del engine y los tests directos. Cualquier mapping a `InvalidCursor` como **semántica de cliente** (422) pertenece a PR3 — no se expone al wire en PR2.
- **D-3 (CONFIRMADA):** Mantener la semántica actual de flush (`save()` sigue haciendo `persistAndFlush` observable, no se rompe Behat de POST/PUT/DELETE), pero **relajar el contrato del puerto** para que no obligue al flush implícito (FR12 — puerta abierta; la frontera transaccional real es decisión separada no bloqueante).
- **D-4 (CONFIRMADA):** Aceptar `COLLATE "C"` como **verdad de la migración** aunque Doctrine no lo modele limpiamente por columna (AR23: la fuente de verdad es el esquema). Si `make db.validate` reporta drift de collation, **documentar el porqué y aceptarlo**; no forzarlo al ORM con `columnDefinition`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (dev-story workflow).

### Debug Log References

- **Decisión sellada D-1 (Sergio, 2026-06-10) que re-scopea PR2:** el `DoctrineSearchEngine` NO entra al HTTP wire-path en PR2. Se construye como *specification engine* (query-shaper keyset standalone, `→ Page<object>`) y se valida **solo con tests directos** (property + snapshot + contract, sin HTTP). Los repos siguen usando el `Paginator` legacy; no delegan en el engine todavía. El flip (engine→runtime HTTP, codec nuevo + 422 + after/before) es íntegramente PR3. Consecuencia: **Task 5 (repos por composición) se difiere a PR3** — desacoplar los repos exigiría meter el engine en el runtime, prohibido en PR2. Wire literalmente intacto (Paginator sin tocar) → 65 escenarios Behat verdes gratis.
- **OQ-1 resuelta por D-1:** la frontera de compatibilidad wire↔motor no se cruza en PR2 (no se inventan adaptadores `Page→cursor legacy`).
- **OQ-2 resuelta:** una aridad de cursor incorrecta → `InvalidCursor::payload()` (familia 422) en `decodeCursor`, ANTES de invocar `KeysetPredicateBuilder` (que lanzaría `InvalidArgumentException`/500). El codec valida integridad, no aridad.
- **Convención de columnas (verificada empíricamente):** DQL rechaza el campo bare `id` (`'id' is not defined`); `b.id` es válido. El engine califica las columnas bare de `OrderByColumns` con el alias raíz al construir ORDER BY + predicado; el tie-break bare `id` → `b.id`.
- **Misterio "2 queries" del legacy (mapeado, NO reproducido — engine off-wire):** el `Paginator` legacy envuelve la query en el `DoctrinePaginator` de Doctrine (`fetchJoinCollection`) → doble query (id-walk + fetch), y cachea `count` en el cursor legacy (por eso DETAILED p2 = 5, no 6). PR2 no toca esto.
- **Refactor de calidad:** el guard del Row Uniqueness Contract se extrajo a `RowUniquenessGuard` (SRP + bajar la complejidad PHPMD del engine de 62→<50) y se testea aislado con stubs del boundary Doctrine.

### Completion Notes List

- **Task 4 — `FilterApplier::apply()` ahora devuelve `AppliedFilters`** (recibo post-allow-list que alimenta el trace/fingerprint, AR22). El único caller del wire (`AbstractDoctrineSearchRepository`) ignora el retorno → retro-compatible. +2 tests del recibo.
- **Tasks 1/2/3 — `DoctrineSearchEngine`** (`final readonly`, único punto que toca Doctrine, NFR4): pipeline fijo de 8 pasos, fingerprint desde el trace sellado, guard Row Uniqueness en paso 4, validación de cursor + guard de aridad en paso 5, ejecutor trick +1 con `before` (invertir ORDER BY + re-reverse en memoria) contenido en el ejecutor.
- **Task 6 — migración de infraestructura** `Version20260610195734`: índices compuestos `(created_at, id)` / `(updated_at, id)` (declarados en `#[ORM\Index]` → `db.validate` limpio) + `COLLATE "C"` en `name_normalized`/`short_name` (editado a mano; Doctrine no modela collation por columna — D-4). DDL verificado en Postgres.
- **Task 8 — `KeysetOrderStabilityPropertyTest` (GATE NORMATIVO, AR13):** 17 tests / Postgres real / sin HTTP / contra el engine directo. Dataset adverso (~50 banks, ~80% en un empate de segundo desde micros, orden físico ≠ id). Por cada sort key × ASC/DESC: oráculo full-scan `ORDER BY (col, id)` vs walk del engine — partición exacta, frontera intra-empate, simetría `before`, precisión a segundos. **Correct-by-result (AR4): pasa idéntico antes y después de la migración** (probado).
- **Task 7 — `SortFieldMapIndexContractTest`** (forma, subordinado): 10 tests; vía ClassMetadata + esquema, por cada entrada de `sortFieldMap()`: UNIQUE satisface; no-única exige `(col, id)`; `nullable: false`; texto sortable `COLLATE "C"`.
- **Task 9 — `KeysetSqlSnapshotTest`** (derivado no normativo): captura el SQL compilado vía middleware de logging en un EM paralelo; fija el predicado keyset + ORDER BY + LIMIT (trick +1) y asserta que NO hay DISTINCT y que todo bind viaja como parámetro.
- **Task 2 (test) — `RowUniquenessGuardTest`** (unit): to-many fetch-join → `LogicException` (programmer error, jamás 422); to-one / sin join → permitido. Stubs del boundary Doctrine (sin fixtures — el rector deadCode las desnuda).
- **Task 10 — `OrderByColumns::fromSorts`** endurecido: descarta un `id` en posición no-última para no duplicar el tie-break (ítem diferido del review 1.1). El round-trip de precisión sub-segundo queda cubierto por el property test (invariante 5).
- **Task 11 — gates:** `make php.stan` por archivo, `make php.unit` (786 tests verdes), `make php.behat` (65 escenarios — wire intacto), `make php.quality` **EXIT 0 sin baselines nuevas**. Docs AR18 actualizados.
- **DIFERIDO A PR3 (re-scope D-1):** Task 5 completa (repos por composición + delegación + borrado FR11 acoplado). Documentado arriba; no es trabajo incompleto sino alcance reasignado.

### File List

**Nuevos (src):**
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/DoctrineSearchEngine.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/RowUniquenessGuard.php`

**Nuevos (migración):**
- `api/migrations/2026/Version20260610195734.php`

**Nuevos (tests):**
- `api/tests/Functional/Shared/Persistence/KeysetOrderStabilityPropertyTest.php`
- `api/tests/Functional/Shared/Persistence/SortFieldMapIndexContractTest.php`
- `api/tests/Functional/Shared/Persistence/KeysetSqlSnapshotTest.php`
- `api/tests/Functional/Shared/Persistence/Fixtures/CapturingSqlLogger.php`
- `api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/RowUniquenessGuardTest.php`

**Modificados (src):**
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FilterApplier.php` (`apply(): void` → `: AppliedFilters`)
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/OrderByColumns.php` (dedup de `id` no-último)
- `api/src/Backoffice/Bank/Domain/Entity/Bank.php` (índices compuestos `#[ORM\Index]`)

**Modificados (tests):**
- `api/tests/Functional/Shared/Persistence/FilterApplierTest.php` (asserts del recibo)
- `api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/OrderByColumnsTest.php` (test del dedup)

**Modificados (docs, AR18):**
- `docs/architecture-api.md`, `docs/source-tree-analysis.md`, `api/docs/adding-endpoints.md`

**Modificados por el code review (2026-06-11):**
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/DoctrineSearchEngine.php` (P1 — reset ORDER BY)
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/RowUniquenessGuard.php` (P2 — `leadingAlias()`)
- `api/tests/Functional/Shared/Persistence/SortFieldMapIndexContractTest.php` (P3 — docblock duplicado)

### Review Findings

_Code review adversarial (Blind Hunter + Edge Case Hunter + Acceptance Auditor) sobre PR #214 — 2026-06-11. Verificados contra el código real de la rama. **3 patch aplicados, 12 defer, 6 descartados.** Tras los patches: `make php.stan` OK · `make php.quality` EXIT=0 · 38 unit + 28 funcional verdes (snapshot SQL idéntico, property gate normativo verde)._

#### Patch — aplicados y verificados

- [x] [Review][Patch] `applyOrderBy` ahora hace `$queryBuilder->resetDQLPart('orderBy')` antes de aplicar el orden keyset — un ORDER BY parásito del base QB ya no puede volverse clave primaria y corromper el walk (espejo de `countIfDetailed`) [DoctrineSearchEngine.php]
- [x] [Review][Patch] `RowUniquenessGuard` con helper `leadingAlias()` — caza el to-many traído por `addSelect('a.field')`/`PARTIAL a.{…}`/`a AS x`, no solo el alias entero [RowUniquenessGuard.php]
- [x] [Review][Patch] Eliminado el docblock `@return list<string>` duplicado en `SortFieldMapIndexContractTest::bankIndexDefinitionsFor()` [SortFieldMapIndexContractTest.php]

#### Defer — registrados en `deferred-work.md` (`code review of story-1.2`)

- [x] [Review][Defer] **AC3 (repos por composición) + AC7 "envelope viejo desde el motor nuevo" no entregados** — RECONCILIADO en esta revisión (título/Story/AC3/AC7/Status reescritos para reflejar el engine off-wire; sprint → `in-progress`). **Resuelto D-1: composición trasladada a PR3 (Story 1.3)** — reasignada en `epics.md`.
- [x] [Review][Defer] (era Patch P4) `KeysetSqlSnapshotTest` no cierra la conexión DBAL paralela de `setUp()` — el `tearDown()` idiomático dispara el conflicto sancionado rector↔psalm (desnuda `#[Override]` → exige baseline nueva), violando el AC7 "sin baselines nuevas". Leak *low* mitigado por refcounting; alternativa: cerrar dentro de `inRolledBackTransaction()`.
- [x] [Review][Defer] `resolveLimit` nunca aplica `policy.defaultLimit` (25); `limit` ausente (`SearchCriteria` default 1000) → `min(1000, maxLimit=100)`=100. Campo `defaultLimit` inerte. Decisión de wiring de PR3.
- [x] [Review][Defer] Página `before` vacía devuelve `hasNext=false` (debería ser `true`); off-wire y sin cursor accionable hoy — PR3.
- [x] [Review][Defer] `RowUniquenessGuard` falla-abierto en cartesiano multi-root y joins to-many no seleccionados (también multiplican filas bajo `LIMIT`) — hardening fuera del scope addSelect del AC2.
- [x] [Review][Defer] Walk `(col DESC, id ASC)` vs índice compuesto `(col, id)` ASC — gap de cobertura de índice (perf); el contract test asserta existencia, no dirección.
- [x] [Review][Defer] El engine no impone `nullable: false` en columnas sortables; solo lo verifica el contract test hardcodeado a Bank.
- [x] [Review][Defer] `SortFieldMapIndexContractTest` refleja los campos de Bank a mano en vez de derivarlos del `SortFieldMap` — AC4 dice "por cada entrada de `sortFieldMap()`".
- [x] [Review][Defer] AC5 invariante (3) frontera intra-empate asserted solo transitivamente (subsumida por la igualdad partición==oráculo).
- [x] [Review][Defer] `qualify()` reescribe el DQL por regex; acoplado al `id` bare — seguro hoy (Bank pre-cualifica), latente para paths bare; preferir pasar el alias al predicate builder.
- [x] [Review][Defer] `entityName()` usa nombre corto de clase — colisiona el fingerprint entre entidades homónimas de distintos contextos (single-tenant/Bank hoy).
- [x] [Review][Defer] Migración `down()` revierte collation a `pg_catalog."default"` en vez de la heredada original — no es inverso fiel.

#### Descartados como ruido (verificados falsos / ya manejados)

- `before` no-vacío `hasNext`/`hasPrev` "estructuralmente mal" — VERIFICADO CORRECTO (un walk `before` siempre lleva cursor → `hasNext=hadCursor=true` es la página real de la que vienes).
- Cursor con claves sobrantes → predicado malformado/500 — VERIFICADO FALSO (`KeysetPredicateBuilder::build()` itera `$columns->all()`; claves sobrantes inertes).
- Guard pierde to-many en joins anidados — VERIFICADO MANEJADO (`joinedAssociationsByAlias()` rellena `aliasToEntity` al caminar).
- Contradicción status/checkboxes — resuelta por esta reconciliación.
- Recomputo del COUNT / `limit=0` — confirmados manejados (`resolveLimit` clampa a ≥1).

## Change Log

| Fecha       | Versión | Descripción                                                                                  | Autor          |
|-------------|---------|----------------------------------------------------------------------------------------------|----------------|
| 2026-06-10  | 0.1     | Creación del contexto de la Story 1.2 (PR2: engine inyectable + repos por composición).      | create-story   |
| 2026-06-10  | 1.0     | Implementación PR2 re-scopeada por D-1: engine *specification* off-wire + guard + migración + suites directas (property/contract/snapshot) + dedup. Task 5 (repos por composición) diferida a PR3. Gates verdes, wire intacto. Status → review. | dev-story (Opus 4.8) |
| 2026-06-11  | 1.1     | Code review (Blind/Edge/Auditor): 3 patches aplicados (reset ORDER BY; guard `leadingAlias`; docblock dup), 12 defer, 6 descartados. **Reconciliación D-1:** título/Story/AC3/AC7 reescritos al entregable real (engine off-wire); AC3 fuera de alcance; Status → in-progress hasta cerrar el desalineamiento contrato↔entrega. | code-review (Opus 4.8) |
