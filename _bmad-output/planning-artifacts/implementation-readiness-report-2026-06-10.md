---
stepsCompleted: ['step-01-document-discovery', 'step-02-prd-analysis', 'step-03-epic-coverage-validation', 'step-04-ux-alignment', 'step-05-epic-quality-review', 'step-06-final-assessment']
documentsIncluded:
  prd: null
  architecture:
    - architecture.md
    - architecture-keyset-pagination.md
  epics:
    - epics.md
  ux: null
  research:
    - research/technical-adopcion-de-jsonapi-en-erpify-sin-api-platform-research-2026-06-06.md
    - research/technical-php-criteria-vs-searchcriteria-erpify-research-2026-06-06.md
---

# Implementation Readiness Assessment Report

**Date:** 2026-06-10
**Project:** ERPify

## 1. Inventario de documentos

| Tipo | Archivo | Formato | Estado |
|------|---------|---------|--------|
| PRD | — | — | ⚠️ No encontrado |
| Arquitectura | `architecture.md` (42 KB, 2026-06-10) | Completo | ✅ |
| Arquitectura (ADR feature) | `architecture-keyset-pagination.md` (60 KB, 2026-06-10) | Completo | ✅ Fuente primaria de la feature |
| Épicas | `epics.md` (34 KB, 2026-06-10) | Completo | ✅ Objeto de la evaluación |
| UX | — | — | ⚠️ No encontrado |
| Investigación | `research/technical-adopcion-de-jsonapi-en-erpify-sin-api-platform-research-2026-06-06.md` | Completo | Apoyo |
| Investigación | `research/technical-php-criteria-vs-searchcriteria-erpify-research-2026-06-06.md` | Completo | Apoyo |

**Duplicados:** ninguno (no existen versiones fragmentadas).

**Decisiones del usuario:**
- Continuar sin PRD: la evaluación usará los documentos de arquitectura (en particular el ADR de paginación keyset) como fuente de requisitos.
- Ausencia de documento UX aceptada para esta evaluación.
- Ambos documentos de arquitectura incluidos; `architecture-keyset-pagination.md` es la fuente primaria de `epics.md`.

## 2. Análisis de requisitos (rol de PRD: ADR keyset pagination)

No existe PRD formal. El propio ADR lo declara: la base de requisitos es la especificación de paginación aportada en sesión (2026-06-10) + análisis de código, refinada por elicitación avanzada. `architecture.md` (ADR de filtros, 2026-06-06) está cerrado y su alcance ya implementado en `main` (`c16ff82`); entra solo como restricción heredada. El ADR keyset enumera los requisitos con numeración formal — se extraen íntegros.

### Requisitos funcionales

**Bloque A — Paginación keyset pura (contrato público):**

- **FR1 — Contrato wire cursor-only**: `limit` + cursores opacos; se eliminan `page`, `currentPage`, `pageCount` y `MAX_PAGE`. Navegación exclusivamente next/previous.
- **FR2 — Cursor corto firmado y ligado a su query**: payload = valores de claves de ordenación de la fila frontera + dirección + fingerprint (`tenant + entity + normalizedFilters + sort + direction + limit`); mismatch → 422 `invalid-cursor`; HMAC conservado; sin zlib (~100–150 chars).
- **FR3 — Conteo bajo demanda**: `PaginationMode` LIGHT (default, sin COUNT) / DETAILED (COUNT explícito) se conserva; `estimatedTotal` diferido.
- **FR4 — Ordenación estable**: tie-break por `id` en todo ORDER BY; `SortDirection` enum de punta a punta.
- **FR5 — "Ir a fecha" como cursor sintetizado**: el servidor fabrica posición de cursor desde un valor de la clave de ordenación; se diseña el seam; la UI es alcance posterior de la PWA.
- **FR6 — Wire envelope nuevo**: `PaginationMeta` → `{hasNext, hasPrev, count?, links: {next, prev}}` con shape constante (`null`, nunca omitido). Breaking change asumido (único consumidor: PWA propia).
- **FR7 — Exportaciones async**: el mismo motor keyset alimenta workers de exportación vía Messenger (batches por cursor, nunca OFFSET) — se diseña el seam; la feature es alcance futuro.
- **FR13 — Navegación direccional explícita `after`/`before`**: cada página emite dos cursores independientes (`links.next` con `?after=`, `links.prev` con `?before=`); fetch de `before` invierte ORDER BY en SQL y re-invierte en memoria, contenido en el ejecutor.
- **FR14 — Sin garantía de instantánea entre páginas (documentado)**: garantía dada = sin duplicados/saltos causados por la propia paginación + unicidad de ids intra-página.
- **FR15 — Versionado del formato de cursor**: payload firmado lleva `v`; bump ⇒ todos los cursores anteriores → 422 `invalid-cursor`; compatibilidad de cursores en vuelo = decisión explícita por release, observable.

**Bloque B — Reestructuración de repositorios (herencia → composición):**

- **FR8 — Motor de búsqueda inyectable**: `DoctrineSearchEngine` extrae `getPaginatedResults`/heurística composite-PK; el engine es el único punto que ve los `Filters` de dominio (fuente de verdad única para `FilterApplier` y fingerprint).
- **FR9 — Repositorios sin base class de framework**: solo puertos de dominio; `EntityManagerInterface` inyectado; mueren `ServiceEntityRepository`, `getEntityClassName()`, `QueryBuilderWithOptions`, `PaginatorOption` (→ `PaginatorConfig` readonly).
- **FR10 — Descomposición del `Paginator`**: `Cursor` y `Page<T>` inmutables; `paginate(): Page` explícito; colaboradores puros `CursorCodec`, `FingerprintCanonicalizer`, `KeysetPredicateBuilder`, `OrderByColumns`, `CursorPositionExtractor`. Resuelve Sonar S1448 estructuralmente.
- **FR11 — Eliminación de código muerto**: `addWhereInCaseInsensitive`, `addWhereBetweenDates`, `addWhereBetweenValues`, `sanitizeArray`, doble llamada de `generateUniqueParameterName`; preservar el why del naming estable de parámetros.
- **FR12 — Frontera transaccional → decisión separada, no bloqueante**: ADR aparte; restricción vinculante: FR8–FR9 no cierran la puerta (repos exponen `save()` sin flush implícito obligatorio en el puerto).

**Total FRs: 15** (FR1–FR15).

### Non-goals (vinculantes como los FRs)

1. Sin normalización semántica de filtros (canonicalizador sintáctico por diseño, no evolucionable sin ADR nuevo).
2. Sin snapshot consistency (FR14).
3. Sin abstracción de página (números de página fuera del sistema).
4. Sin paginación híbrida (legacy muere en PR4; válvula env-gated temporal).
5. Sin degradación silenciosa del cursor (toda invalidez es 422 observable).

### Requisitos no funcionales

- **NFR-SEC — Seguridad**: HMAC con `hash_equals`; cap de longitud pre-HMAC; allow-lists de identificadores ORDER BY y parámetros bindeados intactos; fingerprint con slot de tenant (Fase H).
- **NFR-ERR — Contrato de errores (NFR26)**: 4 causas de invalidez (firma, fingerprint, payload, versión) → mismo 422 `invalid-cursor` (familia `invalid-search-criteria`), indistinguibles. Obliga: fila en `docs/api-error-contract.md`, `MarkerStatusMapContractTest`, `make php.lint.error-contract` verde.
- **NFR-PERF — Rendimiento**: keyset O(1) por página; sortable ⇒ índice compuesto `(columna, id)`; doble gate: (a) `SortFieldMapIndexContractTest` en CI, (b) perf gate de staging con doble perfil (uniforme ~100k + sesgado 80/10); p95 del listado sin regresión.
- **NFR-QUAL — Calidad**: resolución estructural (no supresión) de Sonar `php:S1448`; gates `php.stan` + `php.psalm` + `php.quality` (PHPMD sin baseline); regla de pureza de capa (colaboradores deterministas, readonly, sin estado; solo `DoctrineSearchEngine` toca Doctrine).
- **NFR-DOM — Pureza de dominio**: 0 dependencias nuevas en `Domain/`; puerto evoluciona a `Page` sin imports de framework; desaparecen los arrays `firstItem`/`lastItem` del puerto.
- **NFR-COMPAT — Compatibilidad**: cero migraciones de BD; breaking change del envelope coordinado con la PWA en el mismo ciclo (PR3); cursores en vuelo invalidados explícitamente (422 + `v`).
- **NFR-TENANT — Multiempresa (forward-looking)**: slot de tenant reservado en fingerprint y posición líder en índices compuestos (Fase H).

**Total NFRs: 7 bloques.**

### Requisitos adicionales y restricciones

- **Keyset Edge-Case Contract (10 puntos vinculantes)**: cursor por valores; empates con predicado `col > :v OR (col = :v AND id > :i)`; envelope shape constante; página vacía → 200; descarte client-side de cursores al cambiar query; fingerprint completo; solo columnas NOT NULL sortables; toda invalidez → 422; `after`+`before` simultáneos → 422 `validation-failed`; precisión de serialización datetime a precisión de columna.
- **Decisiones K1–K15** con FQCNs exactos, patrones, anti-patterns (13) y orden de pipeline del motor fijo (8 pasos).
- **Row Uniqueness Contract**: sin fetch-join to-many en read-path paginado; orden total por NOT NULL + tie-break `id`; guard runtime anti to-many fetch-join.
- **Secuencia de implementación vinculante PR1–PR4** (cada PR en su worktree): PR1 piezas puras → PR2 motor + repos (wire intacto) → PR3 el switch (único flip observable: envelope + PWA + Behat + métricas + válvula) → PR4 borrado del legado.
- **Derogaciones explícitas del ADR de filtros**: (1) rediseño de `Paginator` y envelope; (2) fallo de firma silenciado → 422 explícito.
- **Restricciones**: `limit` default 25 / techo 100; `SearchQuery`/`SearchCriteria` `final`; verificar `SearchExceptionListener` (priority 32) en PR3; observabilidad nueva (`invalid_cursor_count{cause}`, `cursor_version_distribution`, `next/prev_navigation_count`) + dashboards en PR3; docs obligatorios por PR; 29 escenarios Behat actualizados dentro de PR3; cero dependencias Composer/npm nuevas.
- **Decisiones diferidas (post-MVP)**: `estimatedTotal`, frontera transaccional (FR12), feature de exportaciones (FR7), analysis-mode navigation, tenant real (Fase H), UI "ir a fecha", normalización semántica.

### Evaluación de completitud de la fuente de requisitos

El ADR está en estado **IMPLEMENTATION LOCKED** (cierre formal 2026-06-10) con validación interna propia: simulación de fallos en 3 capas, cobertura FR1–FR15 trazada a ficheros, checklist de completitud todo marcado, gap analysis sin críticos. Como sustituto de PRD es inusualmente fuerte: requisitos numerados, non-goals vinculantes, criterios verificables y mapeo requisito→estructura. Limitación inherente: no hay requisitos de negocio/usuario independientes del diseño técnico — la trazabilidad de épicas se validará contra FRs/NFRs/K-decisions del ADR.

## 3. Validación de cobertura de épicas

`epics.md` define **1 épica con 5 historias** alineadas a la secuencia vinculante PR1–PR4 (1.1=PR1, 1.2=PR2, 1.3=PR3-API, 1.4=PR3-PWA, 1.5=PR4). Su inventario de requisitos replica fielmente el ADR: FR1–FR15 textuales, non-goals, NFR1–NFR7 y además descompone las decisiones K1–K15/patrones en AR1–AR22 (requisitos adicionales trazables), incluyendo dos requisitos independientes correctos (AR21 Semantic Authority, AR22 trace como única fuente semántica).

### Matriz de cobertura FR

| FR | Requisito (resumen) | Cobertura en épicas | Estado |
|---|---|---|---|
| FR1 | Contrato wire cursor-only, sin páginas | Epic 1 — Story 1.3 (AC: eliminan `page`/`currentPage`/`pageCount`/`MAX_PAGE`) | ✅ |
| FR2 | Cursor firmado + fingerprint, 422 en mismatch | Epic 1 — Story 1.1 (codec/canonicalizador) → 1.3 (activación wire) | ✅ |
| FR3 | LIGHT/DETAILED conservado | Epic 1 — Story 1.3 (AC: `count` poblado en DETAILED, `null` en LIGHT) | ✅ |
| FR4 | Ordenación estable, tie-break `id` | Epic 1 — Story 1.1 (`KeysetPredicateBuilder`/`OrderByColumns`) + 1.2 (pipeline) | ✅ |
| FR5 | Seam "ir a fecha" | Epic 1 — AC en Story 1.3 (síntesis con maquinaria K3/K4) | ✅ (ver obs. 1) |
| FR6 | Envelope nuevo shape constante | Epic 1 — Stories 1.3 (API) + 1.4 (PWA) | ✅ |
| FR7 | Seam exportaciones async (policy) | Epic 1 — Story 1.1 (`WirePaginationPolicy` + AR11 kernel único); `BatchIterationPolicy` diferida a su consumidor (fiel al ADR) | ✅ |
| FR8 | `DoctrineSearchEngine` + trace | Epic 1 — Story 1.2 (pipeline 8 pasos, recibos, sellado) | ✅ |
| FR9 | Repos por composición | Epic 1 — Story 1.2 (Bank/BankAccount, sin base class) | ✅ |
| FR10 | Descomposición `Paginator` | Epic 1 — Story 1.1 (piezas) → 1.5 (muerte del viejo, S1448) | ✅ |
| FR11 | Código muerto | Epic 1 — Story 1.2 (parcial) → 1.5 (total, preserva el why) | ✅ |
| FR12 | Frontera transaccional no bloqueada | Epic 1 — Story 1.2 (AC: `save()` sin flush implícito en el puerto) | ✅ |
| FR13 | `after`/`before` direccionales | Epic 1 — Story 1.1 (dir como integrity binding) + 1.2 (inversión `before`) + 1.3 (wire) | ✅ |
| FR14 | Sin snapshot (documentado) + unicidad intra-página | Epic 1 — Stories 1.3 (página vacía → 200, affordance) + 1.4 (Behat) | ✅ (ver obs. 2) |
| FR15 | Versionado `v` del cursor | Epic 1 — Story 1.1 (`v: 1`, 4 causas → `InvalidCursor`) | ✅ |

**FRs en épicas que no están en la fuente:** ninguno — AR1–AR22 derivan todos de decisiones K1–K15, patrones, contratos (edge-case, row uniqueness, boundary) y reglas del ADR; trazabilidad inversa correcta.

### Requisitos sin cobertura

**Críticos: ninguno.** Los 15 FRs tienen historia(s) asignada(s) con acceptance criteria verificables.

**Observaciones menores (no bloqueantes):**

1. **FR5 — discrepancia de fase en el Coverage Map**: la tabla de `epics.md` dice "PR1–PR2 (seam only)" pero el acceptance criterion del seam vive en Story 1.3 (PR3). Inconsistencia interna de la tabla, no de cobertura: el seam queda cubierto. Recomendación: corregir la fila a "PR1 piezas → PR3 verificación del seam".
2. **FR14 — el aspecto "documentado" es implícito**: la garantía de no-instantánea debe quedar *documentada* según el FR; las historias cubren el comportamiento (página vacía → 200, affordance, Behat) y la actualización de docs vía AR18, pero ningún AC exige explícitamente documentar la ausencia de snapshot consistency en los docs de cara al consumidor. Recomendación: añadir una línea al AC de docs de Story 1.3 o 1.4.

### Estadísticas de cobertura

- Total FRs de la fuente de requisitos: **15**
- FRs cubiertos en épicas: **15**
- Cobertura: **100%**
- NFR1–NFR7 y AR1–AR22: declarados transversales y verificablemente embebidos en los ACs de las historias que tocan sus ficheros (verificación detallada de calidad de historias en pasos posteriores).

## 4. Alineación UX

### Estado del documento UX

**No encontrado** (ausencia aceptada por el usuario en el paso 1).

### ¿Hay UX implícita?

Sí, acotada: el cambio elimina la navegación por páginas numeradas en las listas del backoffice (bancos) y la sustituye por enlaces `next`/`prev`. Es un cambio de paradigma de navegación visible para la usuaria, aunque el grueso del alcance es contrato API + plumbing.

### Cómo lo tratan los documentos

- **ADR**: la decisión de producto está explícita en FR1 ("en un ERP se filtra/busca/ordena; los saltos a página arbitraria se sustituyen por filtros potentes e 'ir a fecha'"); la UI de "ir a fecha" queda **explícitamente diferida** como alcance posterior de la PWA; las reglas de cliente (descarte de cursores, opacidad, `links` tal cual) están pineadas en AR15.
- **Épicas**: `epics.md` declara "UX Design Requirements: N/A" con justificación coherente con el ADR; Story 1.4 cubre la adaptación de componentes de lista con criterios verificables (hard removal de conceptos de página, barrido de adapters, anti-example `getPageNumber`).

### Problemas de alineación

Ninguno entre ADR y épicas: ambos son consistentes en tratar la UI como adaptación mínima y diferir la UX nueva ("ir a fecha").

### Advertencias

- ⚠️ **Sin especificación visual del control de paginación nuevo**: Story 1.4 exige que las listas usen `links.next`/`links.prev`, pero ningún documento especifica el aspecto/comportamiento del control que sustituye al paginador numerado (estados disabled cuando `null`, posición, affordance para la usuaria). Riesgo bajo — el overhaul UI/UX del backoffice es un esfuerzo aparte ya en marcha (fases C/D pendientes) y puede absorberlo; pero el agente que implemente 1.4 tomará decisiones visuales sin guía. Recomendación: una nota breve en Story 1.4 (reutilizar patrones del toolbar/paginador existente o remitir a la fase C del overhaul).
- ℹ️ La arquitectura soporta las necesidades UX implícitas: latencia O(1), affordance flags con semántica definida (K10), página vacía → 200 (no error de cara a la usuaria), descarte de cursores como regla de cliente (la UX no depende del 422).

## 5. Revisión de calidad de épicas e historias

### Estructura de la épica

**Valor de usuario:** el goal de Epic 1 está redactado en términos de outcome del consumidor ("la PWA y sus usuarios de backoffice navegan… con latencia O(1)… errores siempre observables") — correcto. Matices: el título es técnico ("Sustitución del modelo de paginación") y el Bloque B (reestructuración de repositorios, FR8–FR12) es valor de mantenibilidad/calidad (Sonar S1448), no de usuario, embebido en la épica. En un brownfield con ADR IMPLEMENTATION LOCKED que fija la secuencia PR1–PR4, una única épica contenedora es la forma pragmática correcta; separarlo en una "épica técnica" habría sido la violación real.

**Independencia entre épicas:** trivialmente satisfecha (1 sola épica).

**Starter template:** N/A correcto (brownfield; el ADR descarta librerías con verificación en Packagist; la primera acción `make worktree.create` está embebida en el Given de Story 1.1).

**Indicadores brownfield:** presentes y fuertes — puntos de integración con la fundación existente (seam de filtros, pipeline RFC 9457), 29 escenarios Behat como red de compatibilidad, válvula de transición, estrategia de revert explícita (AC de revertibilidad en Story 1.3).

### Dependencias entre historias

| Historia | Depende de | Dirección | Veredicto |
|---|---|---|---|
| 1.1 (PR1 kernel) | — (completable sola; wire intacto, Behat sin tocar) | — | ✅ |
| 1.2 (PR2 engine/repos) | 1.1 | hacia atrás | ✅ |
| 1.3 (PR3 API flip) | 1.1, 1.2 **+ 1.4 (mismo PR)** | hacia atrás + **co-dependencia** | 🟠 ver hallazgo M1 |
| 1.4 (PR3 PWA/Behat/observabilidad) | 1.3 (mismo PR) | co-dependencia | 🟠 ver hallazgo M1 |
| 1.5 (PR4 borrado) | 1.1–1.4 | hacia atrás | ✅ |

**Creación de tablas/BD:** cero migraciones por diseño (NFR6); la verificación de índices compuestos vive en Story 1.2, donde se necesita — correcto. Ver hallazgo m3 sobre la contingencia de índice ausente.

### Calidad de acceptance criteria

Formato Given/When/Then consistente en las 5 historias; criterios excepcionalmente específicos y verificables (FQCNs exactos, formato wire del cursor carácter a carácter, las 4 causas de error con su type, gates de calidad nombrados como comandos, orden del pipeline de 8 pasos). Caminos de error cubiertos: 422 por causa, `validation-failed` en mapping, guard runtime con `LogicException` (con la distinción crítica "NUNCA es 422"), página vacía → 200. No hay criterios vagos ni resultados no medibles. Es de lo más fuerte que se ve en un breakdown.

### Hallazgos por severidad

#### 🔴 Violaciones críticas

Ninguna.

#### 🟠 Problemas mayores

- **M1 — Co-dependencia 1.3 ↔ 1.4 (mismo PR obligatorio):** Story 1.3 no es completable de forma independiente — su AC de revertibilidad dice explícitamente "se revierte el merge de PR3 (junto con Story 1.4, mismo PR)", y desplegar 1.3 sin 1.4 rompería la PWA (la válvula env-gated no cubre prod por construcción). Es una desviación **consciente, documentada y mandatada por el ADR** (breaking change sincronizado API↔PWA del mismo ciclo; la épica lo declara: "1.3 y 1.4 se coordinan en el mismo worktree/PR"). No exige rediseño, pero sí disciplina operativa. **Recomendación:** en sprint planning, tratar 1.3+1.4 como unidad atómica indivisible — ninguna de las dos puede marcarse "done" sin la otra; jamás planificar 1.3 sola en un sprint. El Risk Register del ADR ya identifica la coordinación de PR3 como el riesgo residual dominante.

#### 🟡 Observaciones menores

- **m1 — Historias en voz de desarrollador (1.1, 1.2, 1.5):** "As a desarrollador de ERPify" es formalmente una historia técnica habilitadora, no de usuario. Justificada aquí: la secuencia PR1–PR4 es vinculante del ADR y las historias 1.1/1.2 son enablers sin cambio observable por diseño (ese es precisamente su criterio de aceptación). Aceptable sin cambios.
- **m2 — Bloque B como valor técnico:** la reestructuración de repositorios aporta valor de calidad interna (S1448, composición), no de usuario final. Está correctamente subordinada a la épica de valor del consumidor en lugar de constituir épica propia. Aceptable.
- **m3 — Contingencia de índice ausente sin resolver:** Story 1.2 dice que si falta un índice compuesto en Bank "se trata como conflicto a señalar, no a resolver en silencio" — correcto frente al pin de cero migraciones del ADR, pero deja la implementación potencialmente bloqueada a mitad de PR2. **Recomendación:** verificar *antes de iniciar PR2* (o durante PR1) si los índices existentes de Bank tienen la variante compuesta `(columna, id)`; si falta, decidir ya si se admite una migración de solo-índices o se reabre el pin del ADR.
- **m4 — Coverage Map FR5 (heredado del paso 3):** corregir la fila a "PR1 piezas → PR3 verificación del seam".
- **m5 — FR14 "documentado" implícito (heredado del paso 3):** añadir mención explícita de la no-garantía de snapshot en el AC de docs de Story 1.3/1.4.

### Checklist de cumplimiento

- [x] La épica entrega valor de usuario (goal en términos de consumidor)
- [x] La épica funciona de forma independiente (única épica)
- [x] Historias dimensionadas adecuadamente (la pieza más grande, PR3, está dividida en 1.3/1.4 por superficie API/consumidor)
- [ ] Sin dependencias hacia delante — **excepción consciente: co-dependencia 1.3↔1.4 (M1)**
- [x] BD creada cuando se necesita (cero migraciones; índices verificados en 1.2)
- [x] Acceptance criteria claros y verificables
- [x] Trazabilidad a FRs mantenida (mapa FR→historia + AR/NFR referenciados por AC)

## 6. Resumen y recomendaciones

### Estado global de preparación

**READY** — listo para iniciar la implementación (Story 1.1 / PR1), con una condición operativa para PR3 y correcciones menores opcionales.

La cadena de planificación es inusualmente sólida: ADR en estado IMPLEMENTATION LOCKED con validación adversarial propia → épica única fiel al ADR → 15/15 FRs trazados a historias con acceptance criteria verificables al nivel de FQCN, formato wire y comandos de gate. No hay PRD ni documento UX, pero ambas ausencias son el precedente establecido del proyecto y están justificadas para este alcance (cambio de contrato API + adaptación mínima de PWA).

### Problemas que requieren acción

**Críticos: ninguno.**

**Mayor (condición operativa, no de rediseño):**

- **M1 — Co-dependencia Story 1.3 ↔ 1.4**: deben planificarse, implementarse y mergearse como unidad atómica (mismo worktree/PR), como ya declara la épica. Ninguna se marca "done" sin la otra. Es el riesgo residual dominante que el propio ADR identifica (coordinación de PR3).

**Menores (corregibles en minutos, ninguno bloquea PR1):**

1. **m3** — Verificar *ya* (antes de PR2) si los índices de Bank tienen la variante compuesta `(columna, id)`; si falta, decidir si se admite migración de solo-índices o se reabre el pin "cero migraciones" del ADR. Es la única contingencia que podría bloquear a un agente a mitad de historia.
2. **m4** — Corregir la fila FR5 del Coverage Map de `epics.md` ("PR1 piezas → PR3 verificación del seam").
3. **m5** — Añadir al AC de docs de Story 1.3/1.4 la documentación explícita de la no-garantía de snapshot (FR14 exige "documentado").
4. **UX** — Nota breve en Story 1.4 sobre el control de paginación que sustituye al numerado (estados disabled con `null`; reutilizar patrones del toolbar existente o remitir a la fase C del overhaul de backoffice).
5. **m1/m2** — Historias en voz de desarrollador y valor técnico del Bloque B: desviaciones formales aceptadas con justificación; sin acción.

### Próximos pasos recomendados

1. ~~Aplicar las correcciones m4 y m5 a `epics.md` (2 ediciones puntuales) y la nota UX a Story 1.4.~~ **✅ Aplicado el 2026-06-10 tras la evaluación**: fila FR5 del Coverage Map corregida; AC de docs de Story 1.3 exige documentar FR14 explícitamente; AC de Story 1.4 fija el comportamiento del control de paginación (`null` ⇒ deshabilitado, patrones del toolbar existente, rediseño diferido a fase C del overhaul).
2. Resolver la contingencia m3: inspeccionar los índices actuales de la entidad Bank contra las entradas de `sortFieldMap()`.
3. Iniciar la implementación: `make worktree.create BRANCH=feat/api-keyset-pagination` y ejecutar Story 1.1 (PR1) — sin cambio de contrato, riesgo confinado al mecanismo.
4. Al llegar a PR3: tratar 1.3+1.4 como unidad atómica (M1) y recordar las verificaciones pineadas (AR17: `SearchExceptionListener` priority 32).

### Verificación contra el código (2026-06-10, post-evaluación)

Verificación solicitada de m3 y de las asunciones pineadas, contra el working tree de `main`:

**m3 — Índices compuestos de Bank: HUECO CONFIRMADO.** Las 4 entradas de `sortFieldMap()` (`DoctrineBankRepository.php:124`) y sus índices reales:

| Entrada | Columna | Índice existente | ¿Cumple `(columna, id)`? |
|---|---|---|---|
| `name` | `name_normalized` (NOT NULL) | UNIQUE de una columna | ❌ formal — pero al ser UNIQUE no hay empates posibles: la rama `id` del predicado matchea ≤1 fila; el compuesto sería redundante |
| `shortName` | `short_name` (NOT NULL) | UNIQUE de una columna | ❌ formal — mismo caso |
| `createdAt` | `created_at` `TIMESTAMP(0)` NOT NULL | `idx_bank_created_at` (una columna, `Version20260608165844`) | ❌ **real** — `TIMESTAMP(0)` garantiza empates masivos: necesita `(created_at, id)` |
| `updatedAt` | `updated_at` `TIMESTAMP(0)` NOT NULL | `idx_bank_updated_at` (una columna) | ❌ **real** — necesita `(updated_at, id)` |

Consecuencias: (1) **PR2 necesita una migración de solo-índices** (añadir las 2 variantes compuestas de timestamps, que además pueden sustituir a las simples — la columna líder sigue sirviendo a los filtros de rango), en conflicto con el pin "cero migraciones de BD" del ADR — decisión a tomar antes de PR2, no a mitad de historia; (2) el `SortFieldMapIndexContractTest` debe definir si un UNIQUE de una columna **satisface** la regla para columnas únicas (recomendado: sí — exigir compuestos ahí crearía 2 índices redundantes) o si se exige compuesto literal en todas. Lo positivo: las 4 columnas sortables son NOT NULL ✓ (regla keyset verificada).

**✅ RESUELTO (decisión del usuario, 2026-06-10 — Opción C, regla de propiedad):** separadas las tres capas (semántica keyset / contrato del índice / política de migración). El hueco real es solo de performance/robustez bajo igualdad temporal, no de corrección funcional. Decisión adoptada y aplicada a `epics.md` (NFR3 refinado, Story 1.2, AR17):

1. **El contract test prescribe la propiedad, no la forma física del índice**: "estabilidad del orden bajo igualdad del sort key" — UNIQUE de una columna → válido; sortable no única → exige `(columna, id)`.
2. **PR2 añade `(created_at, id)` y `(updated_at, id)` como índices secundarios** (los simples se conservan), vía migración de solo-índices.
3. **El pin "cero migraciones" del ADR no se reabre**: su alcance correcto es cero migraciones funcionales/estructurales de dominio; una evolución de índices de performance no contractual (sin cambio de esquema lógico, entidad ni semántica) no lo viola — derogación de una lectura demasiado estricta, no del pin.

Los demás hallazgos de la verificación quedaron clasificados: listener obsoleto = solo cleanup de AR17 (aplicado); Behat 52 = capacidad de trabajo en Story 1.4, no arquitectura (cifras actualizadas en `epics.md`); AR7 y TIMESTAMP(0) = consistentes con el diseño. **Veredicto: sin bloqueo arquitectural; el diseño keyset no cambia.**

**Cierre definitivo (2026-06-10):** la regla de propiedad queda respaldada por un detector conductual especificado en Story 1.2 — `KeysetOrderStabilityPropertyTest` (funcional, Postgres real, contra `DoctrineSearchEngine` sin HTTP): dataset adversarial (inserción física aleatoria, grupo de empate dominante 80% a precisión de segundo generado desde microsegundos divergentes, alfabeto seguro, `limit` < grupo de empate) + 5 invariantes (oráculo determinista y total, partición exacta de la caminata `after`, reanudación exacta en frontera intra-empate, simetría `before`, precisión a segundos). El test es independiente del índice por construcción (pasa idéntico con o sin compuestos) y queda pineado en AR13 como gate normativo, con `SortFieldMapIndexContractTest` (forma) y el perf gate (plan) subordinados: **propiedad > forma > snapshot**. La validación de la propiedad ya no depende de la forma física del índice — m3 cerrado en las tres capas (semántica, contrato, política de migración).

**Matiz final (infra-asunciones del oráculo, AR23):** el oráculo `ORDER BY (col, id)` asume (a) `id` estable/único/totalmente ordenable — cumplido por contrato (PK UUID, comparación byte-wise) — y (b) collation de las columnas de texto sortables estable entre entornos. Verificado: `postgres:18-alpine` digest-pinned en todos los overlays **sin locale explícita** → estabilidad de facto, no por contrato.

**SELLADO (decisión de plataforma, 2026-06-10 — último axioma del sistema):** se detectó que AR23 quedaba híbrido ("documentado + recomendación"), dejando tres fuentes de verdad implícitas (imagen / initdb / default del clúster) — el patrón de triple-suposición que produce infra drift silencioso. Resolución: **Opción A a alcance de columna** — regla dura "columna de texto sortable ⇒ `COLLATE "C"` declarado en la columna"; **fuente de verdad única: el esquema**. Se descartó el pin de initdb a nivel de clúster como fuente de verdad porque solo afecta a clústeres nuevos (los volúmenes dev y la BD del VPS ya están inicializados): habría fabricado el drift que pretende evitar; queda como defensa en profundidad opcional. Enforcement: `SortFieldMapIndexContractTest` asserta la collation; la migración de infraestructura de PR2 aplica `COLLATE "C"` a `name_normalized`/`short_name` junto con los índices compuestos. Con el pin de columna, el oráculo en memoria del property test es exacto por contrato (el alfabeto seguro pasa a redundancia defensiva).

**Principio explicitado (AR4 ampliado + prohibición en AR20):** *el sistema es correct-by-result, no correct-by-plan-stability* — el plan físico del planner (índices elegidos, join order, decisiones cost-based) es derivado no normativo igual que el texto SQL; un cambio de plan que preserve la propiedad no es regresión y "estabilizar el plan" jamás es objetivo de corrección (se evalúa en el perf gate, no en los gates semánticos). Cierra el punto ciego estructural del property test: valida modelo-vs-resultado, no modelo-vs-plan, y eso ahora es doctrina declarada, no accidente.

Con esto, los axiomas del sistema quedan todos sellados: trace como fuente semántica (AR22), propiedad de orden como gate normativo (AR13), collation por contrato de esquema (AR23), correct-by-result (AR4). El resto del plan es ejecución mecánica de PR1–PR4.

**Hallazgos adicionales de la verificación:**

- **AR17 parcialmente obsoleto — `SearchExceptionListener` ya no existe**: fue retirado cuando `ProblemDetailsFactory` absorbió sus mappings (así lo documenta `NativeJsonEncodeContractTest`). `ExceptionResponder` confirma `PRIORITY = 16`. La verificación pineada a PR3 queda trivialmente satisfecha; el AC puede conservarse como comprobación de un minuto o anotarse como ya resuelta.
- **AR7 confirmado**: `SearchCriteria::MAX_LIMIT = 1_000` es a la vez techo y default en `SearchCriteria` y `SearchQuery` — el cambio a default 25/techo 100 impacta ambos + fixtures/Behat, como prevé el plan.
- **Cifra de escenarios Behat desactualizada**: `search.feature` contiene hoy **47 Scenario + 5 Scenario Outline (52 bloques)**, no los "29 escenarios" que citan ADR y épicas. Más red de seguridad de la asumida; el esfuerzo de actualización en Story 1.4 es mayor que el estimado nominalmente.
- **Story 1.2 verificada contra código**: `DoctrineBankAccountRepository extends AbstractDoctrineRepository` sin search — coincide exactamente con la descripción de la historia ("solo pierde la base") ✓. Precisión `TIMESTAMP(0)` confirmada en DDL → la regla de serialización del cursor a segundos aplica tal como se diseñó ✓.

### Nota final

Esta evaluación identificó **7 hallazgos en 4 categorías** (0 críticos, 1 mayor operativo, 6 menores). Ninguno impide comenzar la implementación de la Story 1.1; los hallazgos pueden aplicarse a los artefactos antes de empezar o asumirse tal cual con las condiciones anotadas.

---

*Evaluación realizada el 2026-06-10 por el workflow `bmad-check-implementation-readiness` (rol: Product Manager experto en trazabilidad de requisitos). Documentos evaluados: `architecture-keyset-pagination.md` (fuente de requisitos, precedente ADR-como-PRD), `architecture.md` (restricciones heredadas), `epics.md` (objeto de la evaluación).*
