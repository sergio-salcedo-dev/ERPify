---
stepsCompleted: [1, 2, 3, 4, 5, 6]
status: 'complete'
completedAt: '2026-06-07'
overallReadiness: 'READY'
documentsIncluded:
  prd: '_bmad-output/planning-artifacts/research/technical-php-criteria-vs-searchcriteria-erpify-research-2026-06-06.md'
  architecture: '_bmad-output/planning-artifacts/architecture.md'
  epics: '_bmad-output/planning-artifacts/epics.md'
  ux: 'N/A'
---

# Implementation Readiness Assessment Report

**Date:** 2026-06-07
**Project:** ERPify

## Inventario de Documentos

### Documentos incluidos en la evaluación

| Rol | Fichero | Estado |
|---|---|---|
| PRD (rol asumido por research técnico) | `research/technical-php-criteria-vs-searchcriteria-erpify-research-2026-06-06.md` (50 KB, 2026-06-06) | Encontrado |
| Arquitectura | `architecture.md` (42 KB, 2026-06-06, `status: complete`, 8 pasos) | Encontrado |
| Épicas e Historias | `epics.md` (27 KB, 2026-06-07, `status: complete`, 4 pasos) | Encontrado |
| UX | — | N/A (sin superficie UI; carpeta `ux-designs/` vacía) |

### Documentos de contexto adicionales

- `research/technical-adopcion-de-jsonapi-en-erpify-sin-api-platform-research-2026-06-06.md` (64 KB) — research previo relacionado
- `implementation-artifacts/deferred-work.md` (8 KB) — trabajo diferido registrado

### Incidencias del descubrimiento

- **Sin PRD formal:** el frontmatter de `epics.md` declara que el research técnico actúa como PRD. Aceptado por el usuario.
- **Sin documento UX:** alcance puramente backend (mecanismo compartido de filtros); tratado como N/A. Aceptado por el usuario.
- **Sin duplicados:** no existen versiones completas + shardeadas en conflicto.

## Análisis del PRD

> El documento con rol de PRD es un research técnico (`technical-php-criteria-vs-searchcriteria-erpify-research-2026-06-06.md`). No numera requisitos FR/NFR formalmente; la numeración siguiente es extracción propia para trazabilidad, con la sección fuente citada.

### Requisitos Funcionales extraídos

- **FR1:** `Shared/Domain/Search` incorpora los VOs `Filter` (readonly; field string + operator + valor escalar o lista), `FilterOperator` (enum backed) y `Filters` (lista inmutable), con **cero dependencias** de terceros. _(Fuente: "Diseño de convergencia — Domain")_
- **FR2:** `SearchCriteria` transporta `Filters` manteniendo compatibilidad de constructor (named args, default vacío) — Bank intacto en fase 0. _(Fuente: "Hoja de fases — Fase 0")_
- **FR3:** Operadores iniciales implementados: `EQ`, `IN`, `CONTAINS` (normalizado con diacríticos vía `NormalizedText`); el enum queda diseñado como punto de extensión documentado para `NEQ/GT/GTE/LT/LTE/NOT_IN/IS_NULL` (añadir operador = 1 caso + 1 rama + tests). _(Fuente: "Alcance de operadores", "Diseño de convergencia")_
- **FR4:** Composición OR / grupos anidados queda **explícitamente fuera de alcance** hasta que un caso de uso real lo exija. _(Fuente: "Alcance de operadores", "Riesgos")_
- **FR5:** `Shared/Infrastructure` incorpora un applier de filtros sobre **QueryBuilder** (nunca `Collections\Criteria`), insertado en el seam `AbstractDoctrineSearchRepository::getSearchQueryBuilder()`, sin tocar `Paginator` ni contrato de cursor. _(Fuente: "Encaje hexagonal", "Diseño de convergencia — Infrastructure")_
- **FR6:** `SearchFieldMap` por repositorio como **allow-list obligatoria** (parámetro requerido del applier, verificado por la firma + PHPStan) que mapea campo público → path DQL + normalizador opcional tipado. _(Fuente: "Diseño de convergencia — Infrastructure", "Riesgos")_
- **FR7:** El applier genera `andWhere` con parámetros bindeados, reutilizando el naming hasheado (`xxh128`) de `AbstractDoctrineRepository`; dirección de orden como string `'ASC'/'DESC'` sobre `QueryBuilder::addOrderBy()` (issue #2 estructuralmente imposible). _(Fuente: "Diseño de convergencia — Infrastructure")_
- **FR8:** `Shared/Application/Http/Search` incorpora `FilterQuery` DTO con `#[Assert]` (operador validado contra el enum en mapping) y `SearchQuery` gana `list<FilterQuery> $filters` vía `#[MapQueryString]` con DTOs anidados. _(Fuente: "Diseño de convergencia — Application")_
- **FR9:** Campo desconocido/no filtrable → excepción de dominio con marker nuevo (p. ej. `InvalidSearchCriteria`) mapeado a **400** en el pipeline RFC 9457. _(Fuente: "Contrato de errores (NFR26)")_
- **FR10 (Fase 1):** `BankSearchQuery` añade `filters[]` manteniendo `names[]`/`ids[]`, que pasan a mapear internamente a `Filters` (Parallel Change / expand–contract). _(Fuente: "Hoja de fases — Fase 1")_
- **FR11 (Fase 2):** Builder tipado de query params en `pwa/src/context/shared` (espejo TS de Criteria); la lista de banks pasa de filtrado client-side a server-driven. El cursor permanece opaco para el cliente. _(Fuente: "Hoja de fases — Fase 2", "Contrato cross-deployable")_
- **FR12 (Fase 3):** Generalización — cada nueva lista de entidad consume el mecanismo con coste marginal ≈ 1 subclase opcional de `SearchQuery` + 1 field map, **sin tocar `Shared/`**. _(Fuente: "Hoja de fases — Fase 3", "KPIs")_
- **FR13:** El cliente descarta el cursor al cambiar cualquier filtro (interacción filtros ↔ cursor). _(Fuente: "Riesgos y mitigación")_
- **FR14:** No requerir ningún paquete `codelytv/*`; únicas dependencias nuevas de runtime: promoción de `phpstan/phpdoc-parser` + `phpdocumentor/type-resolver` de dev a `require`. _(Fuente: "Technology Stack Recommendations 1 y 3")_
- **FR15:** Conservar `Paginator` existente (keyset + cursor HMAC + fallback offset + modos LIGHT/DETAILED) **intacto**. _(Fuente: "Escalabilidad y rendimiento", "Recommendations 4")_
- **FR16:** Object mothers para los VOs de criteria en `Erpify\Tests` (absorción del patrón `criteria-test-mother`). _(Fuente: "Testing y QA — Unit")_
- **FR17:** Retirar `php-criteria-main/` del working tree una vez cerrada la decisión (material de estudio, no se committea). _(Fuente: "Próximos pasos 5")_

**Total FRs: 17**

### Requisitos No Funcionales extraídos

- **NFR1 (Pureza arquitectónica):** 0 dependencias nuevas en `Domain/`; gates de arquitectura verdes. _(Fuente: "KPIs — Pureza arquitectónica")_
- **NFR2 (Seguridad — allow-list):** allow-list obligatoria por recurso de campos filtrables y ordenables; nunca opcional (el fallo de diseño upstream). _(Fuente: "Seguridad arquitectónica 2")_
- **NFR3 (Seguridad — binding):** parámetros Doctrine siempre bindeados; nunca interpolar field/value en SQL/DQL. _(Fuente: "Seguridad arquitectónica 4")_
- **NFR4 (Seguridad — cursor):** cursor opaco firmado HMAC; fallo de firma se silencia a cursor vacío (no oráculo). _(Fuente: "Seguridad arquitectónica 3")_
- **NFR5 (Contrato de errores):** validación en mapping → 4xx Problem Details RFC 9457, nunca `ValueError`/500; **0 errores 500 por entrada de usuario**. Actualización obligatoria de `docs/api-error-contract.md` + `make php.lint.error-contract` verde (NFR26 del repo). _(Fuente: "Seguridad arquitectónica 1", "Contrato de errores", "KPIs")_
- **NFR6 (Rendimiento):** p95 del endpoint de listado sin regresión tras fase 1; todo campo expuesto en field map respaldado por índice, verificado con `EXPLAIN ANALYZE`. _(Fuente: "KPIs — Rendimiento", "Riesgos")_
- **NFR7 (Tolerancia al cambio):** nueva lista filtrable = ≤ 2 clases nuevas + 1 field map; nuevo operador = 1 caso de enum + 1 rama + tests en un solo PR. _(Fuente: "KPIs — Tolerancia al cambio")_
- **NFR8 (Calidad):** `make php.stan` por archivo tocado, `make php.quality` completo al final, **ambos** stan + psalm; `make pwa.quality` en fase 2; cada fase es un PR independiente con gate verde. _(Fuente: "Flujo de desarrollo", "Roadmap")_
- **NFR9 (Testing):** unit puro en Domain (sin contenedor/BD); integración del applier contra **Postgres real** (nunca SQLite) cubriendo binding, normalización y rechazo fuera de allow-list; Behat ampliado (operador inválido → 400, campo desconocido → 400, `filters[]` + cursor, equivalencia `names[]` ≡ `filters[name][IN]`, CONTAINS con diacríticos); Vitest para el builder PWA. _(Fuente: "Testing y QA")_
- **NFR10 (Sin esquema):** cero migraciones de BD en todas las fases. _(Fuente: "Flujo de desarrollo", "Roadmap")_
- **NFR11 (Documentación):** actualizar `docs/architecture-api.md` (patrón field-map/allow-list como receta escrita), `api/docs/` (forma del endpoint), `docs/api-error-contract.md` (marker), `pwa/docs/` + `docs/architecture-pwa.md` (builder, fase 2). _(Fuente: "Flujo de desarrollo", "Skill Development")_
- **NFR12 (Compatibilidad de contrato):** el contrato genérico `filters[N][field/operator/value]` es compatible con la convención JSON:API `filter[...]`; params tipados conviven con `filters[]` durante toda la migración (Parallel Change). _(Fuente: "Contrato HTTP", "Riesgos")_

**Total NFRs: 12**

### Requisitos y restricciones adicionales

- **Decisión arquitectónica vinculante:** opción C — converger; reimplementación propia inspirada en php-criteria. Las opciones A (require), B (vendorizar) y D (coexistir) quedan descartadas con evidencia. Decisión revisable solo si upstream publica 1.0+ con licencia, `IN` y fix del #2.
- **Restricción legal:** no copiar código de php-criteria (sin licencia = todos los derechos reservados); solo reimplementar el patrón.
- **Estrategia de entrega:** Strangler Fig sobre el seam existente + Parallel Change para el contrato HTTP; fases 0 y 1 pueden compartir PR; fase 2 es el tramo largo.
- **Fuera de alcance de Criteria:** payloads de Messenger y topics de Mercure (read-path síncrono exclusivamente); sin impacto en `messenger_worker`.
- **Opcional (no bloquea):** PR de goodwill upstream con el fix del #2 mapeando `OrderType` → enum `Order`.

### Evaluación de completitud del PRD

**Fortalezas:** decisión inequívoca con evidencia verificada (confianza ALTA en todo lo crítico); fases de entrega claras; invariantes de seguridad explícitos; KPIs medibles; alcance de operadores resuelto (YAGNI vs extensibilidad); riesgos con mitigación.

**Debilidades como PRD:**
1. No numera requisitos — la trazabilidad exige la extracción propia de arriba (riesgo de interpretación divergente).
2. Sin criterios de aceptación formales por requisito (los escenarios Behat de NFR9 los aproximan para la API).
3. La fase 2 (PWA) está menos especificada que las fases 0-1: el research no define la UI de filtros de la PWA (qué controles, qué campos expone banks server-driven) — sin documento UX que lo cubra.
4. No fija el orden/criterio de los valores por defecto del contrato genérico (p. ej. límites de `filters[]`: nº máximo de filtros por request no especificado).

**Veredicto preliminar:** apto como PRD para un mecanismo técnico interno, con los 4 huecos anotados para contrastar contra épicas y arquitectura en los pasos siguientes.

## Validación de Cobertura de Épicas

> Las épicas usan numeración propia (FR1–FR7, NFR1–NFR7) más densa que la extracción del PRD (FR1–FR17). La matriz traza ambas numeraciones.

### Matriz de cobertura

| FR (PRD) | Requisito (resumen) | FR/NFR épicas | Cobertura | Estado |
|---|---|---|---|---|
| FR1 | VOs `Filter`/`FilterOperator`/`Filters` en Domain, cero deps | FR1 | Epic 1 · Story 1.1 | ✓ Cubierto |
| FR2 | `SearchCriteria` transporta `Filters` retrocompatible | FR1 | Epic 1 · Story 1.1 (AC2) | ✓ Cubierto |
| FR3 | Operadores iniciales `eq`/`in`/`contains`; enum punto de extensión | FR1 | Epic 1 · Stories 1.1, 1.3 | ✓ Cubierto |
| FR4 | OR/grupos anidados fuera de alcance | — | "Decisiones diferidas explícitamente fuera de alcance" | ✓ Cubierto (exclusión explícita) |
| FR5 | Applier sobre QueryBuilder en el seam, sin tocar `Paginator` | FR3 | Epic 1 · Story 1.3 | ✓ Cubierto |
| FR6 | `SearchFieldMap` allow-list obligatoria por firma | FR3 | Epic 1 · Story 1.3 (AC1) | ✓ Cubierto |
| FR7 | `andWhere` bindeado, naming `xxh128`, orden string | NFR2 | Story 1.3 (AC4) + "Paginación y orden sin cambios" | ✓ Cubierto |
| FR8 | `FilterQuery` DTO + `SearchQuery.filters[]` vía `#[MapQueryString]` | FR2 | Epic 1 · Story 1.4 | ✓ Cubierto |
| FR9 | Campo desconocido/operador no permitido → marker → 400 RFC 9457 | FR5 | Epic 1 · Stories 1.2, 1.3 | ✓ Cubierto |
| FR10 | Bank pilota `filters[]` manteniendo `names[]`/`ids[]` (expand) | FR4 | Epic 1 · Stories 1.4, 1.5 | ✓ Cubierto |
| FR11 | Builder TS en `context/shared` + banks server-driven | FR6 | Epic 2 · Stories 2.1, 2.2 | ✓ Cubierto |
| FR12 | Generalización: ≤ 2 clases + 1 field map sin tocar `Shared/` | FR7 | Epic 1 · Story 1.6 | ✓ Cubierto |
| FR13 | Cursor descartado al cambiar cualquier filtro | FR6 | Story 2.2 (AC2) | ✓ Cubierto |
| FR14 | Sin `codelytv/*`; promoción `phpdoc-parser`+`type-resolver` | NFR6 | Story 1.4 (AC1) | ✓ Cubierto |
| FR15 | `Paginator` keyset/HMAC/LIGHT-DETAILED intacto | NFR4 | Story 1.3 (AC4) | ✓ Cubierto |
| FR16 | Object mothers para VOs de criteria | — (sección Testing) | Story 1.1 (AC3): `FilterMother`, `FiltersMother` | ✓ Cubierto |
| FR17 | Retirar `php-criteria-main/` del working tree | — (sección Documentación) | Story 1.6 (AC3) | ✓ Cubierto |

### Requisitos sin cobertura

**Ninguno.** Los 17 FRs extraídos del PRD tienen historia y criterio de aceptación asignables.

### Verificación inversa (épicas → PRD)

Sin FRs huérfanos: los FR1–FR7 de las épicas trazan íntegramente al research. Elementos de las épicas que **van más allá** del research — todos con origen legítimo en `architecture.md` (input declarado en el frontmatter):

- Caps `SearchQuery::MAX_FILTERS = 20` y `FilterQuery::MAX_IN_VALUES = 100` (D-estructura) — **cierra la debilidad nº 4 del PRD** (límites sin especificar).
- D8 — eliminación de `BankSearchCriteria` y `addWhereIn` ad hoc (camino único) — refinamiento coherente con la convergencia; el wire legacy no cambia.
- FQCNs exactos pineados, gramática wire D1/D2, capa de validación pineada (shape→mapping, semántica→applier).
- Verificación del legacy `SearchExceptionListener` (priority 32) — guardarraíl no presente en el research.
- Tokens de operador en minúscula (`eq`/`in`/`contains`) como contrato wire (D2) — el research usaba mayúsculas conceptuales (`EQ`, `IN`, `CONTAINS`); las épicas lo concretan sin conflicto.

### Estadísticas de cobertura

- FRs totales del PRD: **17**
- FRs cubiertos en épicas: **17**
- Porcentaje de cobertura: **100 %**

## Evaluación de Alineación UX

### Estado del documento UX

**No encontrado** (carpeta `ux-designs/` vacía). Ausencia **consciente y justificada**, no un olvido:

- El alcance es un mecanismo técnico API-céntrico (vocabulario de filtros + applier + contrato HTTP).
- Las épicas lo declaran explícitamente (sección "UX Design Requirements"): la fase 2 PWA **reutiliza la UI existente** de la lista de banks sin cambios visuales — solo cambia el origen del filtrado a server-driven. Story 2.2 lo fija como criterio de aceptación ("no hay cambios visuales").
- La UI de la lista de banks ya fue rediseñada con contrato UX propio (PRs #137/#150/#157) y ya incorpora filtro con debounce y badge de filtro activo.

### Problemas de alineación

Ninguno bloqueante. La cadena PRD → Arquitectura → Épicas es coherente respecto a UX:

- PRD (research): fase 2 = builder TS + banks server-driven, cursor opaco.
- Épicas: Story 2.2 traduce las reglas UX aprendidas del repo (descartar cursor al cambiar cualquier filtro — race debounce+paginación; esperar badge de filtro activo antes de paginar en e2e; mockear hook realtime en unit).

### Advertencias

- ⚠️ **Menor — latencia percibida del filtro:** pasar de filtrado client-side (instantáneo) a server-driven (roundtrip) cambia la percepción de la respuesta al teclear, y ningún documento especifica el estado de carga/pending durante el roundtrip. Mitigación parcial: la lista ya hace fetch server-side para paginación y ya existe debounce de 300 ms. Recomendación: que la Story 2.2 confirme en implementación que el estado de carga existente cubre el refetch por filtro (no requiere documento UX nuevo).

## Revisión de Calidad de Épicas

### Validación de estructura

| Criterio | Epic 1 | Epic 2 |
|---|---|---|
| Entrega valor de usuario | ✓ Consumidor de la API filtra banks con contrato genérico, legacy intacto | ✓ Usuario del backoffice filtra server-driven |
| Funciona de forma independiente | ✓ Standalone, verificable vía Behat | ✓ Solo consume el output de Epic 1 (sin requerir nada futuro) |
| Historias dimensionadas | ✓ con 1 observación (1.4, abajo) | ✓ |
| Sin dependencias adelantadas | ✓ Grafo estrictamente hacia atrás | ✓ |
| Creación de tablas cuando se necesitan | N/A — cero migraciones (coherente con NFR5) | N/A |
| ACs claros y testables | ✓ G/W/T, errores y edge cases incluidos | ✓ |
| Trazabilidad a FRs | ✓ FR coverage map + "FRs covered" por épica | ✓ |

### Grafo de dependencias (todas hacia atrás — sin violaciones)

```
1.1 (VOs Domain)        → standalone
1.2 (marker + errores)  → standalone
1.3 (applier)           → 1.1, 1.2
1.4 (HTTP + seam + Bank)→ 1.1–1.3
1.5 (D8 camino único)   → 1.4
1.6 (docs + cierre)     → 1.1–1.5
2.1 (builder TS)        → contrato Epic 1
2.2 (banks server-driven)→ 2.1
```

### Validaciones especiales

- **Starter template:** N/A — brownfield declarado explícitamente ("no hay historia de inicialización; el trabajo arranca con `make worktree.create`"). ✓
- **Indicadores brownfield presentes:** puntos de integración con lo existente (seam `getSearchQueryBuilder()`, verificación del legacy `SearchExceptionListener` priority 32), historias de compatibilidad/migración (1.4 expand del Parallel Change; 1.5 equivalencia demostrada en Behat **antes** de eliminar el código ad hoc). ✓
- **Secuencia fase 0 → fase 1 respetada a nivel de historia** (1.1–1.3 núcleo inalcanzable desde HTTP; 1.4–1.6 expand). ✓

### Hallazgos por severidad

#### 🔴 Violaciones críticas

Ninguna.

#### 🟠 Problemas mayores

Ninguno.

#### 🟡 Observaciones menores

1. **Historias con persona desarrollador (1.1, 1.3, 1.6, 2.1).** Las historias de fase 0 ("As a desarrollador del monorepo...") no entregan valor visible a usuario final por sí solas — por diseño consciente (fase 0 "inalcanzable desde HTTP", estrategia Strangler Fig). Aceptable porque: (a) viven dentro de una épica que sí entrega valor, (b) cada una es completable y verificable de forma independiente (unit/integración), (c) el orden garantiza que el valor llega en 1.4. No requiere acción.
2. **Dimensionado de Story 1.4.** Es la historia más densa: promoción de deps + DTO `FilterQuery` + `SearchQuery.filters[]` + método abstracto con auto-apply + field map de Bank + suite Behat + `api/docs/`. La atomicidad está justificada en el propio AC ("no existe ningún estado intermedio roto": método abstracto e implementación en el mismo cambio), pero conviene vigilar su tamaño en implementación. Mitigación posible sin romper atomicidad: extraer la documentación `api/docs/` a 1.6.
3. **Método de verificación de NFR4 (p95) sin procedimiento.** Story 1.5 exige "p95 del listado sin regresión" y `EXPLAIN ANALYZE`, pero ningún documento define con qué dataset/herramienta se mide el p95 localmente. Riesgo de AC no verificable de forma reproducible. Recomendación: fijar el procedimiento mínimo (p. ej. `EXPLAIN ANALYZE` sobre las queries generadas con el dataset de fixtures, y comparación informal de timings Behat) en la historia o en `docs/architecture-api.md`.
4. **Mapeo de controles UI existentes → filtros wire no pineado (Story 2.2).** Se asume que el filtro de texto existente de banks pasa a `filters[0][field]=name&operator=contains`, pero el AC no lo fija explícitamente (¿`contains` o `in`?). Recomendación: añadir una línea al AC de 2.2 fijando el mapeo del input de texto → `contains` sobre `name`.

### Cumplimiento del checklist de buenas prácticas

- [x] Épicas entregan valor de usuario
- [x] Épicas funcionan de forma independiente
- [x] Historias correctamente dimensionadas (con observación en 1.4)
- [x] Sin dependencias adelantadas
- [x] Sin violaciones de timing de BD (N/A — cero esquema)
- [x] Criterios de aceptación claros, testables, con errores y edge cases
- [x] Trazabilidad a FRs mantenida

## Verificación adicional contra Arquitectura

Verificación directa de `architecture.md` (no solo vía citas de las épicas): las decisiones D1–D8, FQCNs, caps (`MAX_FILTERS=20`, `MAX_IN_VALUES=100`), seam auto-apply, boundaries y el mapeo requisitos→estructura existen y coinciden con lo que las épicas referencian. El propio documento incluye validación de coherencia, cobertura y readiness (✅ en sus tres checks internos). Dos inconsistencias editoriales menores detectadas **dentro** de `architecture.md`:

1. **Naming con sufijo (línea ~239, sección Authentication & Security):** dice "campo fuera de la lista → `InvalidSearchCriteriaException`", contradiciendo la convención pineada en el resto del documento y en las épicas (marker `InvalidSearchCriteria` sin sufijo; la excepción concreta lanzada es `UnknownSearchField`). El naming canónico está correcto en la tabla de Naming Patterns, en la estructura de directorios y en las épicas — es un desliz editorial sin ambigüedad real.
2. **Casing del directorio PWA (sección Frontend Architecture):** dice `pwa/src/context/shared/domain/search/` (minúscula); las épicas dicen `Search/` (PascalCase) citando la convención de los siblings. **Verificado contra el árbol real:** los siblings existentes son PascalCase (`DateTimeProvider/`, `Notification/`, `Observability/`, `RealTime/`) → las épicas tienen razón; corregir la arquitectura.

Ninguna de las dos bloquea: las historias (fuente operativa para el dev) pinean los nombres correctos.

## Resumen y Recomendaciones

### Estado general de preparación

# ✅ READY — listo para implementación

La cadena Research(PRD) → Arquitectura → Épicas/Historias es trazable, coherente y excepcionalmente detallada. Cobertura FR 17/17 (100 %), cero violaciones críticas o mayores de calidad de épicas, secuencia de fases sin dependencias adelantadas, brownfield correctamente planteado.

### Problemas críticos que requieren acción inmediata

**Ninguno.**

### Inventario completo de hallazgos (todos menores)

| # | Hallazgo | Severidad | Estado/Acción |
|---|---|---|---|
| 1 | PRD sin requisitos numerados | 🟡 | Mitigado — extracción trazable en este informe + inventario propio de épicas |
| 2 | PRD sin ACs formales | 🟡 | Mitigado — las historias aportan ACs G/W/T completos |
| 3 | Fase 2 PWA menos especificada en el PRD | 🟡 | Mitigado — épicas fijan "sin cambios visuales" + reglas UX aprendidas |
| 4 | Límites de `filters[]` sin especificar en PRD | ✅ | Resuelto por arquitectura (caps D-estructura validados en mapping) |
| 5 | Estado de carga durante roundtrip del filtro server-driven sin especificar | 🟡 | Verificar en implementación de Story 2.2 (no requiere doc UX) |
| 6 | Historias 1.1/1.3/1.6/2.1 con persona desarrollador | 🟡 | Aceptado por diseño (fase 0 inalcanzable desde HTTP, Strangler Fig) |
| 7 | Story 1.4 densa | 🟡 | Vigilar tamaño; opcional: mover `api/docs/` a 1.6 sin romper atomicidad |
| 8 | Procedimiento de verificación del p95 (NFR4) sin definir | 🟡 | Fijar método mínimo reproducible antes o durante Story 1.5 |
| 9 | Mapeo control UI existente → filtro wire no pineado en Story 2.2 | 🟡 | Añadir 1 línea al AC (input de texto → `contains` sobre `name`) al crear la story file |
| 10 | `architecture.md`: `InvalidSearchCriteriaException` con sufijo (1 ocurrencia) | ✅ | Resuelto 2026-06-07 — corregido a `UnknownSearchField` (marker `InvalidSearchCriteria`) |
| 11 | `architecture.md`: `domain/search/` minúscula vs convención PascalCase real | ✅ | Resuelto 2026-06-07 — corregido a `Search/` en las 2 ocurrencias en prosa (el árbol ya era correcto) |

### Próximos pasos recomendados

1. ~~Corregir los 2 deslices editoriales de `architecture.md`~~ — **hecho** (hallazgos 10–11 resueltos el 2026-06-07, mismo día de la evaluación).
2. **Al crear las story files** (`bmad-create-story`), incorporar: el mapeo UI→wire en 2.2 (hallazgo 9) y el procedimiento p95/`EXPLAIN ANALYZE` en 1.5 (hallazgo 8).
3. **Proceder a sprint planning / creación de la primera historia** (Story 1.1) — no hay bloqueantes.

### Nota final

Esta evaluación identificó **11 hallazgos en 4 categorías (PRD, UX, calidad de épicas, consistencia documental)** — ninguno crítico ni mayor. Los artefactos están listos para la Fase 4; los hallazgos menores pueden corregirse sobre la marcha sin re-planificación.

---

**Evaluador:** Workflow `bmad-check-implementation-readiness` (PM expert) — ejecutado por Claude
**Fecha de evaluación:** 2026-06-07
**Documentos evaluados:** research técnico (rol PRD) · `architecture.md` · `epics.md` · UX N/A
