---
baseline_commit: 12dfe91eb15d8d9487152ca0a35cc61a021d6a51
---

# Story 1.1: Kernel keyset puro — VOs, colaboradores deterministas y suites unitarias (PR1)

Status: done

## Story

As a desarrollador de ERPify,
I want el kernel keyset completo como piezas puras verificadas (VOs inmutables + colaboradores deterministas + suites unitarias sin kernel), sin ningún cambio de contrato,
so that el flip posterior del contrato (PR3) se apoye exclusivamente en componentes ya probados y el riesgo quede confinado a la integración, no al mecanismo.

## Acceptance Criteria

1. **FQCNs exactos y pureza (AR12, NFR4, NFR5):** Given el monorepo en un worktree nuevo (`make worktree.create BRANCH=feat/api-keyset-pagination`), When la historia se completa, Then existen exactamente los FQCNs pineados en AR12: `Erpify\Shared\Domain\Search\Page` (final readonly, `@template T`), `Erpify\Shared\Domain\Search\Exception\InvalidCursor`, `Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\PaginatorConfig` y el subnamespace `Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\` con `Cursor`, `CursorCodec`, `QueryExecutionTrace`, `AppliedFilters`, `AppliedSort`, `AppliedLimit`, `FingerprintCanonicalizer`, `KeysetPredicateBuilder`, `OrderByColumns`, `CursorPositionExtractor`, `WirePaginationPolicy` — sin inventar variantes. And `Domain/` no gana ninguna dependencia nueva (NFR5); todos los colaboradores son readonly, deterministas, sin estado interno ni servicios instanciados dentro (NFR4).

2. **Formato y round-trip del cursor (AR2/K3, NFR1, AR17):** Given un cursor codificado por `CursorCodec`, When se decodifica, Then el round-trip es exacto y el formato wire es `base64url(json{v, dir, values, fp})` + `.` + HMAC-SHA256 truncado a 32 hex, con exactamente un punto separador. And la verificación de firma usa `hash_equals` y existe cap de longitud 512 pre-HMAC. And los datetimes de `values` se serializan en UTC `Y-m-d\TH:i:sP` a precisión de columna (`TIMESTAMP(0)` ⇒ segundos), con round-trip exacto cursor↔SQL.

3. **Invalidez con orden intrínseco-primero (AR10, K5, NFR2, AR21, FR15/K12):** Given un cursor con firma inválida, versión desconocida, payload corrupto o fingerprint discrepante, When se valida en orden firma → versión → payload → fingerprint, Then se lanza `InvalidCursor` con la causa correspondiente (`signature`/`version`/`payload`/`fingerprint`) y las cuatro causas comparten el type `invalid-cursor`. And el payload lleva `v: 1` y el campo `dir` se compara como integrity binding pero jamás decide lógica de navegación.

4. **Estabilidad canónica del trace (AR13, AR3/AR22, NFR7):** Given el mismo input (criteria + field maps + entity), When se construye el `QueryExecutionTrace` en ejecuciones repetidas, Then `TraceEquivalenceStabilityTest` verifica representación canónica byte-a-byte idéntica — el test más importante del sistema. And la cadena canónica es `tenant|entity|filters|sort|direction|limit` derivada de los recibos (jamás del input), con filtros ordenados por (field, operator, valor serializado), listas IN ordenadas y `fp = xxh128(canonical(trace))`. And el slot de tenant es la constante pineada.

5. **Predicado keyset (FR4, AR4, AR11):** Given columnas de ORDER BY con tie-break `id` y una posición de cursor, When `KeysetPredicateBuilder` construye el predicado, Then produce la cadena `col > :v OR (col = :v AND id > :i)` (extendida a N claves) con parámetros bindeados, pre-compilación. And el builder recibe configuración explícita de `WirePaginationPolicy` — nunca se comparte sin contexto entre policies.

6. **Cero cambio de contrato + gates verdes (AR13, AR16):** Given la suite completa del repo, When corre CI, Then los escenarios Behat existentes de `search.feature` (52 bloques a 2026-06-10: 47 Scenario + 5 Outline) pasan sin modificación alguna. And las suites unitarias nuevas viven en `api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/` sin necesitar el kernel, con object mothers `CursorMother`/`TraceMother`/`PageMother`. And `make php.stan` y `make php.quality` quedan verdes.

## Tasks / Subtasks

- [x] Task 0: Preparar el entorno aislado (AC: #1)
  - [x] `make worktree.create BRANCH=feat/api-keyset-pagination` desde el checkout primario; `cd` al worktree y `make app.dev` (regla dura del repo: jamás trabajar en `main`)
  - [x] Verificar que el stack del worktree responde (`make docker.info`, `make php.unit c='--filter SearchCriteriaTest'` como smoke)
- [x] Task 1: Puerto de dominio puro (AC: #1)
  - [x] `api/src/Shared/Domain/Search/Page.php` — `final readonly`, `@template T`, propiedades: `items` (list<T>), `hasNext`, `hasPrev`, `count` (?int), `nextCursor`/`prevCursor` (?string). Los campos de cursor son **tokens de transporte opacos**: el dominio NO asume estructura, decodificabilidad ni validez más allá de la identidad de string (el dominio trata el cursor como un id). `Page` no es un DTO de transporte — el envelope wire lo construye el responder en PR3. Cero imports de framework (NFR5)
  - [x] `api/src/Shared/Domain/Search/Exception/InvalidCursor.php` — implementa el marker existente `Erpify\Shared\Domain\Exception\InvalidSearchCriteria`, `TYPE = 'invalid-cursor'`, named constructors por causa: `signature()`, `version()`, `payload()`, `fingerprint()` (la causa viaja en la excepción para logging/métricas; el mensaje wire es indistinguible entre causas)
- [x] Task 2: Recibos y trace sellado (AC: #4)
  - [x] `Keyset/AppliedFilters.php`, `Keyset/AppliedSort.php`, `Keyset/AppliedLimit.php` — recibos inmutables readonly de lo realmente aplicado
  - [x] `Keyset/QueryExecutionTrace.php` — compone los recibos + entity + slot de tenant; sellado (se construye una vez, inmutable); expone la representación canónica
  - [x] Pinear el slot de tenant como UNA constante en un único sitio con comentario `// why:` (Fase H del roadmap SaaS; cambiarla tras PR3 = bump de `v`) (NFR7)
- [x] Task 3: Canonicalizador y fingerprint (AC: #4)
  - [x] `Keyset/FingerprintCanonicalizer.php` — cadena canónica `tenant|entity|filters|sort|direction|limit` desde los recibos del trace; filtros ordenados por (field, operator, valor serializado), listas IN ordenadas; numéricos serializados como strings normalizados post-validación; datetimes UTC `Y-m-d\TH:i:sP` a precisión de columna; `fp = xxh128(canonical)` (hash rápido no criptográfico es correcto: la integridad la da el HMAC del conjunto)
  - [x] Canonicalización SINTÁCTICA por diseño — non-goal vinculante: prohibido cualquier intento de equivalencia semántica (`amount > 100 ≢ amount >= 101`)
  - [x] **Estabilidad append-only**: la canonicalización debe ser estable bajo extensión aditiva — un `FilterOperator` nuevo (o expansión multi-valor futura) define sus reglas de ordenación SIN modificar la semántica de orden canónico de los operadores existentes. Cambiar el orden de lo ya existente = cambio del contrato de serialización = bump de `v` (FR15 ampliado). Diseñar el sort canónico de modo que añadir operadores no recoloque entradas existentes (orden lexicográfico sobre tokens estables, no sobre posición de enum)
  - [x] Suite propia con ejemplos documentados de la cadena canónica
- [x] Task 4: Codec del cursor (AC: #2, #3)
  - [x] `Keyset/Cursor.php` — VO infra `{v, dir, values, fp}`; `v: 1` inicial
  - [x] `Keyset/CursorCodec.php` — encode: `base64url sin padding` del JSON + `.` + `substr(hash_hmac('sha256', body, secret), 0, 32)`; decode con validación en orden intrínseco-primero: firma (`hash_equals`) → versión → payload → fingerprint. Cap de longitud 512 ANTES de tocar HMAC. SIN zlib (a diferencia del `PaginatorCursorFactory` legacy — no copiar su compresión)
  - [x] El check de fingerprint es un **hook de integridad diferido**: el codec recibe el fp esperado como `string` por parámetro y compara — JAMÁS importa ni conoce `QueryExecutionTrace`. En PR1 no existe trace en runtime; el cableado trace→fp esperado es responsabilidad exclusiva del `DoctrineSearchEngine` (PR2). Acoplar el codec al trace aquí es implementación prematura que rompe la secuencia AR16
  - [x] Secret por constructor `string` (DI: `#[Autowire('%kernel.secret%')]`, mismo patrón que `PaginatorCursorFactory`); los tests pasan el secret directamente — sin kernel
  - [x] `dir` se compara contra la dirección esperada como integrity binding (discrepancia → `InvalidCursor`); JAMÁS se usa para decidir navegación (AR21)
- [x] Task 5: Columnas, posición y predicado (AC: #2, #5)
  - [x] `Keyset/OrderByColumns.php` — VO columnas+dirección; tie-break `id` siempre presente como última clave
  - [x] `Keyset/CursorPositionExtractor.php` — extrae los valores frontera de una fila a precisión de columna (`TIMESTAMP(0)` ⇒ truncar a segundos; el `extractFields` legacy emite microsegundos: ese desajuste de frontera es exactamente lo que esta pieza corrige). Sin `PropertyAccess::createPropertyAccessor()` por llamada (anti-ejemplo de pureza citado por el ADR)
  - [x] `Keyset/KeysetPredicateBuilder.php` — cadena `col > :v OR (col = :v AND id > :i)` extendida a N claves (DQL no soporta tuplas), parámetros bindeados, pre-compilación; recibe configuración explícita por policy
  - [x] **Paréntesis explícitos obligatorios por nivel de clave** — el builder emite agrupación explícita `(col > :v) OR (col = :v AND (…))` en cada nivel; prohibido el flattening de operadores confiando en precedencia implícita OR/AND (la composición dinámica en DQL es ambigua sin agrupación). Los tests assertan los paréntesis en el string emitido
  - [x] `Keyset/WirePaginationPolicy.php` — configuración wire explícita (default 25, techo 100, semántica de frontera, emisión de cursores). Los valores viven aquí; NO tocar `SearchQuery`/`SearchCriteria` (eso es PR3)
  - [x] `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/PaginatorConfig.php` — readonly tipado, reemplazo del options-bag `PaginatorOption`; forma mínima (YAGNI — PR2 la refina cuando el engine la consuma)
- [x] Task 6: Suites unitarias sin kernel (AC: #2, #3, #4, #5, #6)
  - [x] Object mothers en `api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/Mother/`: `CursorMother`, `TraceMother`, `PageMother` — siguiendo el precedente `FilterMother` (estáticos con defaults)
  - [x] `CursorCodecTest` — round-trip exacto; exactamente un punto; las 4 causas de invalidez en orden; cap 512; cursor con `dir` discrepante; datetimes round-trip a segundos
  - [x] `FingerprintCanonicalizerTest` — determinismo, orden de filtros/IN, datetimes, ejemplos canónicos literales
  - [x] `TraceEquivalenceStabilityTest` — mismo input ⇒ trace canónico byte-a-byte idéntico en ejecuciones repetidas (el test más importante del sistema). Dos categorías explícitas: (a) **estabilidad positiva** — repetición exacta ⇒ bytes idénticos; (b) **negative drift** — alteraciones de representación que NO cambian semántica (orden de filtros en el *input*, permutación de listas IN de entrada, espacios/formato del valor pre-normalización) ⇒ mismo trace canónico byte-a-byte. Es la categoría donde los sistemas de canonicalización fallan históricamente — cada invariante de orden del canonicalizador tiene su caso negative-drift
  - [x] `KeysetPredicateBuilderTest` — 1, 2 y N claves; ASC/DESC; binds correctos
  - [x] `CursorPositionExtractorTest` — precisión de columna, truncado de microsegundos
  - [x] `OrderByColumnsTest` — tie-break `id` garantizado
  - [x] Criterio de review en cada test: *¿este test necesita el kernel?* — la respuesta debe ser no
- [x] Task 7: Gates y cierre (AC: #6)
  - [x] `make php.stan` por archivo cambiado durante el desarrollo
  - [x] `make php.unit` — suites nuevas + existentes verdes
  - [x] `make php.behat` — los 52 bloques de `search.feature` pasan SIN tocar (cero cambio de contrato wire)
  - [x] `make php.quality` al cierre (incluye error-contract gate) — verde sin baselines nuevas
  - [x] Self-review de seguridad del repo (checklist de CLAUDE.md, sección backend) antes del PR

### Review Findings

_Code review adversarial (Blind Hunter + Edge Case Hunter + Acceptance Auditor) — 2026-06-10. Acceptance Auditor: 0 violaciones de AC, 0 anti-patterns. Hallazgos residuales abajo._

- [x] [Review][Decision→Dismiss] Cursor over-length / sin separador `.` mapea a causa `payload` — **RESUELTO (usuario, 2026-06-10): mantener `payload`.** Over-length y estructura inválida (`.` ausente) son fallos intrínsecos de formato *previos* a cualquier verificación criptográfica; clasificarlos como `payload` respeta el orden intrínseco-primero (AR10) mejor que `signature`, mantiene el set de 4 causas exacto y deja wire/ACs/tests intactos. Se añade comentario `// why:` en `authenticBody()` documentando la decisión para PR3. [CursorCodec.php — authenticBody]
- [x] [Review][Patch] IN-list canonicaliza con `\sort()` (SORT_REGULAR) → inestabilidad byte para strings numéricamente-iguales-pero-distintas (`'1e3'`/`'1000'`, `'01'`/`'1'`); usar `SORT_STRING` + comparación de strings en el `usort` para honrar el contrato lexicográfico de AC4 [FingerprintCanonicalizer.php:105] — **APLICADO**: `SORT_STRING` + `strcmp` en el `usort`; test `itOrdersNumericLookingInValuesLexicographicallyAndStably`
- [x] [Review][Patch] Tests de HMAC del cursor y de nombres de parámetro re-implementan la expresión de producción (sólo auto-consistentes) → añadir un known-answer/golden vector que pinee los bytes exactos de un cursor y un nombre de parámetro, para cazar regresiones de firma/hash [CursorCodecTest.php, KeysetPredicateBuilderTest.php] — **APLICADO**: `itEncodesToTheKnownGoldenVector` + `itGeneratesTheKnownGoldenParameterNames` (literales hardcoded)
- [x] [Review][Patch] Sin cobertura del fallback no-temporal de `normalizeTemporalBound` (valor de operador de rango que no parsea como fecha cae a string crudo trimmeado — correcto para bounds numéricos, pero no testeado) → añadir caso negativo [FingerprintCanonicalizer.php:710] — **APLICADO**: test `itLeavesNonTemporalRangeBoundsAsTheirTrimmedString`
- [x] [Review][Defer] Codec acepta `values` estructuralmente válido con aridad incorrecta / columna ORDER BY ausente / elementos no-escalares → en el cableado de PR2, `KeysetPredicateBuilder` lanza `InvalidArgumentException` (500) en vez de `InvalidCursor` (422). El codec no puede validar aridad (no conoce las columnas — diferido al engine por AR16). PR2 debe garantizar aridad o mapear el fallo a `InvalidCursor` [CursorCodec.php / KeysetPredicateBuilder.php] — deferred, integración PR2
- [x] [Review][Defer] `OrderByColumns::fromSorts` sólo deduplica `id` cuando es la ÚLTIMA clave; un sort multi-clave con `id` no-último duplicaría la columna tie-break. Sin caller en PR1; guardar antes de que PR2 cablee sorts multi-clave [OrderByColumns.php:47] — deferred, pre-existing (sin caller en PR1)
- [x] [Review][Defer] Floor de microsegundos vs redondeo de Postgres `TIMESTAMP(0)` + drift de precisión JSON en columnas float en la frontera → verificar con round-trip real contra Postgres (Behat) en PR2/PR3 que las filas frontera no se saltan/duplican en empates sub-segundo [CursorPositionExtractor.php] — deferred, integración PR2/PR3

## Dev Notes

### Contexto arquitectónico imprescindible

- **Fuente única de requisitos:** ADR `_bmad-output/planning-artifacts/architecture-keyset-pagination.md` (status: IMPLEMENTATION LOCKED, 2026-06-10). Ante duda, el ADR manda; ante conflicto con CLAUDE.md/docs/rules, señalar el conflicto en vez de elegir.
- **Qué se construye:** no "paginación" — una máquina de transiciones de navegación validadas criptográficamente. PR1 entrega SOLO las piezas puras: nada se cablea al read-path todavía, el `Paginator` legacy queda intacto, el wire no cambia.
- **Secuencia vinculante AR16:** PR1 (esta historia) → PR2 (engine+repos) → PR3 (flip) → PR4 (borrado). Cualquier desviación del orden invalida el modelo de validación. No adelantar trabajo de PR2+ (ni `DoctrineSearchEngine`, ni tocar repositorios, ni `SortFieldMapIndexContractTest`, ni migraciones de índices).
- **Pipeline futuro (para entender el porqué de cada pieza, NO implementarlo ahora):** sort→`AppliedSort` · filtros→`AppliedFilters` · limit→`AppliedLimit` · sellado del trace+fp · validación cursor · predicado keyset · exec +1 · `Page`. Los invariantes se imponen PRE-compilación; el SQL compilado es derivado NO normativo (AR4: correct-by-result).
- **AR24 (gobernanza del trace):** el trace debe capturar completamente el espacio de decisión del orden. Diseñar `QueryExecutionTrace` de modo que añadir una dimensión futura (tenant real, soft-delete, scoping) sea un cambio obvio y central, no un parche.

### Ficheros NUEVOS (todo [N] — PR1 no modifica código de producción existente)

```text
api/src/Shared/
├── Domain/Search/Page.php
├── Domain/Search/Exception/InvalidCursor.php
└── Infrastructure/Persistence/Doctrine/Search/
    ├── PaginatorConfig.php
    └── Keyset/
        ├── Cursor.php · CursorCodec.php
        ├── QueryExecutionTrace.php · AppliedFilters.php · AppliedSort.php · AppliedLimit.php
        ├── FingerprintCanonicalizer.php · KeysetPredicateBuilder.php
        ├── OrderByColumns.php · CursorPositionExtractor.php
        └── WirePaginationPolicy.php

api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/
├── CursorCodecTest · FingerprintCanonicalizerTest · KeysetPredicateBuilderTest
├── CursorPositionExtractorTest · OrderByColumnsTest · TraceEquivalenceStabilityTest
└── Mother/CursorMother.php · TraceMother.php · PageMother.php
```

Colisiones verificadas 2026-06-10: ningún FQCN existente choca con los nuevos (solo `Cursor` en vendor — irrelevante).

### Código existente que DEBES leer antes de implementar (no se modifica, se entiende)

| Fichero | Por qué leerlo |
|---|---|
| `api/src/Shared/Infrastructure/Persistence/PaginatorCursorFactory.php` | Patrón HMAC existente: `#[Autowire('%kernel.secret%')]`, `hash_equals`, separador `.`. El codec nuevo lo replica PERO sin zlib y con HMAC truncado a 32 hex |
| `api/src/Shared/Infrastructure/Persistence/Doctrine/Paginator.php` | El legacy que el kernel sustituirá (PR4). Entender `buildCursorWhere()` y el bug de microsegundos de `extractFields` que `CursorPositionExtractor` corrige |
| `api/src/Shared/Domain/Search/` (`Filters`, `Filter`, `FilterOperator`, `SortDirection`, `SearchCriteria`, `PaginationMode`) | Inputs del dominio. `SortDirection` enum `ASC`/`DESC`; `FilterOperator` lowercase. Los recibos del trace se derivan de estos tipos |
| `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FilterApplier.php` | Precedente del seam y del naming estable de parámetros vía `hash('xxh128', …)` (caché SQL de Doctrine — preservar ese why). En PR2 su `apply()` devolverá `AppliedFilters` |
| `api/src/Shared/Domain/Exception/InvalidSearchCriteria.php` + `Domain/Search/Exception/InvalidPagination.php` | Marker 422 y patrón de named constructors + `TYPE` que `InvalidCursor` imita |
| `api/tests/Unit/Shared/Domain/Search/Mother/FilterMother.php` | Patrón object mother del repo (final class, métodos estáticos con defaults) |
| `api/features/backoffice/bank/search.feature` | La red de seguridad: 52 bloques que deben pasar intactos |

### Reglas de implementación no negociables

1. **Pureza (NFR4):** colaboradores deterministas (input → output), `final readonly`, sin estado interno, sin servicios instanciados dentro. Solo el futuro `DoctrineSearchEngine` (PR2) tocará Doctrine. `KeysetPredicateBuilder` produce DQL string + binds como datos — no necesita QueryBuilder para nada en sus tests.
2. **Pureza de dominio (NFR5):** `Page` e `InvalidCursor` con cero imports de framework. PHP puro + (si hiciera falta) `symfony/uid` que es la única excepción documentada del repo — aquí ni eso.
3. **Formato del cursor (K3):** `base64url` sin padding — usar `sodium_bin2base64($json, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)` (ext-sodium es requerida por el proyecto) o `rtrim(strtr(base64_encode(…), '+/', '-_'), '=')`. HMAC: `substr(hash_hmac('sha256', $body, $secret), 0, 32)` — 128 bits, 32 hex. Exactamente un `.`.
4. **Orden de validación (AR10):** firma → versión → payload → fingerprint. El fingerprint es el ÚNICO check extrínseco y va siempre el último — en PR1 es un hook diferido: el codec recibe el fp esperado como `string` y compara, sin conocer jamás el `QueryExecutionTrace` (el cableado trace→codec es del engine, PR2). Las 4 causas → `InvalidCursor` (mismo type wire `invalid-cursor`, familia `invalid-search-criteria`), causa interna distinguible para métricas/logs.
5. **AR21:** el `dir` del payload es integrity binding — se compara, jamás decide. No escribir ningún `if ($cursor->dir === …) { navegar… }`.
6. **Cadena canónica (AR3):** `tenant|entity|filters|sort|direction|limit` SIEMPRE desde los recibos, jamás desde el input crudo. Determinista byte-a-byte: orden de claves JSON fijo, filtros ordenados por (field, operator, valor serializado), listas IN ordenadas, numéricos como strings normalizados, datetimes UTC `Y-m-d\TH:i:sP`.
7. **Seguridad (NFR1):** cap 512 pre-HMAC; `hash_equals` para la firma; jamás loguear el cursor crudo (solo la causa). El cursor solo transporta valores de claves de ordenación de la fila frontera.
8. **Cero:** dependencias Composer nuevas, migraciones de BD, cambios de wire, cambios en `services.yaml` salvo que el autowiring no resuelva (no debería: constructor con `#[Autowire('%kernel.secret%')]` basta para el codec).
9. **Canonicalización append-only estable:** todo `FilterOperator` futuro define sus reglas de ordenación sin modificar la semántica de orden canónico existente; recolocar entradas existentes = cambio de contrato de serialización = bump de `v`.
10. **Paréntesis explícitos en el predicado keyset:** agrupación explícita por nivel de clave, prohibido el flattening por precedencia implícita OR/AND de DQL.

### Anti-patterns prohibidos (del ADR — el review los caza)

- ❌ Leer `dir` del payload para decidir dirección.
- ❌ Computar el fingerprint desde el input (criteria/query string) en vez de los recibos del trace.
- ❌ Mutar inputs del trace después del sellado.
- ❌ Aserciones sobre objetos `QueryBuilder`/`Query` en tests — solo strings DQL + binds.
- ❌ Compartir `KeysetPredicateBuilder` entre policies sin configuración explícita.
- ❌ Una segunda implementación de paginación (kernel único).
- ❌ Inventar variantes de FQCN, claves de payload (`v`, `dir`, `values`, `fp` — exactas) o causas (`signature`, `version`, `payload`, `fingerprint` — exactas, alimentan la métrica `invalid_cursor_count{cause}` de PR3).
- ❌ zlib/compresión en el cursor nuevo.

### Gotchas operativos del repo (aprendidos en sesiones previas — evitan ciclos de review)

- **Métodos sin consumidor de producción hasta PR2**: las clases de PR1 solo las consumen los tests hasta que PR2 las cablea. (Histórico: el análisis general de Psalm con `findUnusedCode` vigilaba `PossiblyUnusedMethod` aquí; ese gate fue retirado — PHPStan `level: max` es ahora la única autoridad de tipos y no exige baseline.)
- **Lint siempre vía `make` desde la raíz del repo** (ejecuta dentro del contenedor dev — un entorno sin ext-bcmath voltea cs-fixer).
- **`make app.dev` en el worktree reescribe `api/config/reference.php`** — nunca `git add -A` sin revisar; ese fichero es auto-generado y no se toca.
- **FrankenPHP puede volcar `core.N` (~1GB) en `api/` durante test runs en contenedor** — borrar, jamás commitear.
- **`make php.quality` incluye `php.lint.error-contract`**: `InvalidCursor` implementa un marker YA mapeado (`InvalidSearchCriteria` → 422), no añade marker nuevo, así que el gate no debería exigir cambios. Si el gate exigiera la fila de `invalid-cursor` en `docs/api-error-contract.md` ya en PR1, añadirla aquí (Story 1.3 la verifica/completa en PR3) — no suprimir el gate.
- **Commits:** Conventional Commits (`feat(api): …`), pre-commit hooks activos; nunca `--no-verify`, nunca amend tras fallo de hook.

### Testing

- Ubicación: `api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/` (espejo del src). PHPUnit 13, atributos `#[Test]`, `#[CoversClass]`, `#[DataProvider]`; `declare(strict_types=1);` en todo fichero; AAA; nombres por comportamiento (`it_rejects_cursor_with_tampered_signature`).
- **Sin kernel, sin contenedor, sin BD** — todo input por constructor. El secret del codec se pasa como string en el test.
- `TraceEquivalenceStabilityTest`: propiedad formal — mismo input (criteria + field maps + entity) ⇒ representación canónica byte-a-byte idéntica en ejecuciones repetidas. Cubrir permutaciones de orden de filtros de entrada que DEBEN canonicalizar igual, y diferencias mínimas (limit 25 vs 26, ASC vs DESC, un valor IN distinto) que DEBEN producir fp distinto.
- Comandos: `make php.unit c='--filter Keyset'` durante desarrollo; `make php.unit` + `make php.behat` + `make php.quality` al cierre.
- No tocar `search.feature` ni fixtures: si un escenario Behat falla, ES un bug de esta historia (algo cambió el contrato).

### Stack pineado (no renegociable; más allá del training data — leer código existente antes que memoria)

PHP 8.5 (no inventar sintaxis 8.5-specific; idioms 8.3 forward-compatible) · Symfony 8.0 componentes individuales · Doctrine ORM 3.6/DBAL 4.4 (irrelevante en PR1: nada toca Doctrine) · PHPUnit 13 · PostgreSQL 18 (solo conceptual aquí: `TIMESTAMP(0)`). Cero dependencias nuevas: `xxh128` y `hash_hmac('sha256')` son nativos de `ext-hash`; base64url vía ext-sodium ya requerida.

### Project Structure Notes

- Alineado con DDD+Hexagonal del repo: puerto (`Page`) y excepción de dominio en `Domain/Search/`; mecanismo (codec, trace, predicado) en `Infrastructure/Persistence/Doctrine/Search/Keyset/`; tests espejo en `tests/Unit/`.
- `PaginatorConfig` vive en `…\Doctrine\Search\` (raíz del seam de búsqueda), NO dentro de `Keyset/` — así lo pinea AR12.
- El legacy (`Paginator`, `PaginatorCursor*`, `AbstractDoctrine*Repository`, `PaginatedResult`, `SearchCursor`) convive intacto hasta PR4. Esta historia no borra ni modifica nada de eso.
- Sin conflictos detectados entre epics.md, el ADR y la estructura actual del repo (verificación de readiness 2026-06-10).

### References

- [Source: _bmad-output/planning-artifacts/architecture-keyset-pagination.md#Core Architectural Decisions] — K3 (formato cursor), K4 (fingerprint), K5 (InvalidCursor), K7 (Page), K12 (v:1)
- [Source: _bmad-output/planning-artifacts/architecture-keyset-pagination.md#Implementation Patterns & Consistency Rules] — FQCNs exactos, Format Patterns (cadena canónica, datetimes, serialización como frontera de seguridad), Process Patterns (DAG de validación, pipeline 8 pasos), Anti-Patterns
- [Source: _bmad-output/planning-artifacts/architecture-keyset-pagination.md#Project Structure & Boundaries] — árbol delta PR1
- [Source: _bmad-output/planning-artifacts/epics.md#Story 1.1] — acceptance criteria de origen; AR1–AR24 (en especial AR2, AR3, AR10, AR12, AR13, AR16, AR21, AR22, AR24)
- [Source: docs/project-context.md] — reglas PHP/testing/workflow del repo (strict_types, atributos PHPUnit, Make-first, Conventional Commits)
- [Source: api/src/Shared/Infrastructure/Persistence/PaginatorCursorFactory.php] — patrón HMAC/`hash_equals`/`%kernel.secret%` a replicar (sin zlib)
- [Source: api/tests/Unit/Shared/Domain/Search/Mother/FilterMother.php] — patrón object mother

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Opus 4.8, 1M context) — workflow `bmad-dev-story`.

### Debug Log References

- PHPStan: limpio sobre 334 ficheros.
- Psalm: `No errors found!` (EXIT=0); las 534 issues restantes son nivel info pre-existentes.
- PHPUnit: 748 tests, 3614 assertions, 3 skipped pre-existentes del entorno.
- Behat: 116 escenarios / 773 pasos verdes (incluye los 52 bloques de `search.feature` SIN modificar).
- `make php.quality`: EXIT=0 (stan, rector, cs-fixer, phpmd, phpcs, gherkin, `php.lint.doctrine`, `php.lint.error-contract`) — sin baselines nuevas.

### Completion Notes List

Implementado el kernel keyset completo como piezas puras (Tasks 0–7). Las 6 ACs
quedan satisfechas; cero cambio de contrato wire (Behat verde), cero dependencias
Composer, cero migraciones, cero cambios en `services.yaml`.

Decisiones de implementación y desviaciones menores (para el reviewer):

- **`InvalidCursorCause` (enum nuevo, no pineado en AR12):** la causa viaja en
  `InvalidCursor::$cause` como enum backed cuyos 4 casos son EXACTAMENTE las
  causas pineadas (`signature|version|payload|fingerprint`, label de la métrica
  `invalid_cursor_count{cause}` de PR3). Es un tipo de soporte (idioma del repo:
  enums para conjuntos cerrados), no una variante de un FQCN pineado. El wire es
  indistinguible entre causas (mismo `type`/título, `context` vacío); la causa
  solo sirve para logs/métricas.
- **Binding `dir`:** un `dir` discrepante mapea a la causa `payload` (es un
  integrity binding del payload). Las 4 causas siguen siendo exactamente las
  pineadas. `dir` jamás decide navegación (AR21) — solo se compara con
  `hash_equals` contra la dirección esperada que pasa el caller.
- **`OrderByColumns::fromSorts` (multi-clave):** generalizado para ejercitar el
  camino N-claves del `KeysetPredicateBuilder` (DQL no soporta tuplas → forma
  anidada con paréntesis explícitos por nivel). `fromPrimarySort` delega en él.
- **Fila `invalid-cursor` en `docs/api-error-contract.md`: NO añadida.**
  `InvalidCursor` implementa el marker YA mapeado `InvalidSearchCriteria` (→ 422),
  no añade marker nuevo y vive en `Domain/Search/Exception/`, así que el gate
  `php.lint.error-contract` queda verde sin tocar el doc. Se difiere a la Story
  1.3 (PR3) según las Dev Notes — gate jamás suprimido.
- **Nombres de métodos de test en camelCase:** el ECS/cs-fixer del repo convierte
  automáticamente los `it_...` snake_case a camelCase; la regla del linter manda
  sobre el ejemplo de `project-context.md`.

Self-review de seguridad (checklist backend de CLAUDE.md): **OK.** Inyección —
`KeysetPredicateBuilder` interpola solo identificadores de columna (autor:
repositorio) + nombres de parámetro generados; todos los valores frontera van
bindeados. HMAC con `hash_equals` (tiempo constante), cap 512 ANTES del HMAC
(guarda DoS), cursor crudo jamás logueado. Secret vía `%kernel.secret%`, nunca
serializado ni devuelto. Sin `unserialize`/`eval`; base64url vía ext-sodium;
`json_decode` con `JSON_THROW_ON_ERROR`. Sin entidades/setters (mass-assignment
N/A), sin migraciones, sin ampliación de CORS/Mercure.

### File List

**Nuevos — producción (`api/src/`):**

- `api/src/Shared/Domain/Search/Page.php`
- `api/src/Shared/Domain/Search/Exception/InvalidCursor.php`
- `api/src/Shared/Domain/Search/Exception/InvalidCursorCause.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/PaginatorConfig.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/AppliedFilters.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/AppliedSort.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/AppliedLimit.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/QueryExecutionTrace.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/FingerprintCanonicalizer.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/Cursor.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/CursorCodec.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/OrderByColumns.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/CursorPositionExtractor.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/KeysetPredicateBuilder.php`
- `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/WirePaginationPolicy.php`

**Nuevos — tests (`api/tests/`):**

- `api/tests/Unit/Shared/Domain/Search/PageTest.php`
- `api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/PaginatorConfigTest.php`
- `api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/CursorCodecTest.php`
- `api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/FingerprintCanonicalizerTest.php`
- `api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/TraceEquivalenceStabilityTest.php`
- `api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/KeysetPredicateBuilderTest.php`
- `api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/CursorPositionExtractorTest.php`
- `api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/OrderByColumnsTest.php`
- `api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/AppliedLimitTest.php`
- `api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/WirePaginationPolicyTest.php`
- `api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/Mother/CursorMother.php`
- `api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/Mother/TraceMother.php`
- `api/tests/Unit/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/Mother/PageMother.php`

**Modificados:**

- `_bmad-output/implementation-artifacts/1-1-kernel-keyset-puro-vos-colaboradores-deterministas-y-suites-unitarias-pr1.md` (frontmatter `baseline_commit`, checkboxes, Dev Agent Record, Change Log, Status)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (estado de la historia → review)

## Change Log

| Fecha       | Versión | Descripción                                                                                   | Autor          |
|-------------|---------|-----------------------------------------------------------------------------------------------|----------------|
| 2026-06-10  | 0.1     | Implementación PR1: kernel keyset puro (VOs + colaboradores deterministas + suites unitarias). | Amelia (dev)   |
