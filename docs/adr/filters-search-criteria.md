---
status: 'complete — shipped (stories 1.5–1.7, PR #180; fase contract ejecutada: names[]/ids[] retirados del wire)'
date: '2026-06-06'
---

# ADR — Vocabulario genérico de búsqueda `filters[]` (SearchQuery / SearchCriteria)

Registro de decisión: rationale, alternativas e inventario FR/NFR citado por ID desde los docs
vivos. El estado actual del sistema (y la receta "añadir una lista filtrable") vive en
[`architecture-api.md`](../architecture-api.md).

## Contexto

Sin PRD formal: la base de requisitos es el research técnico
`technical-php-criteria-vs-searchcriteria-erpify-research-2026-06-06.md`
(`_bmad-output/planning-artifacts/research/`), cuya decisión formal es la **opción C**: converger
con el patrón php-criteria mediante **reimplementación propia** — se absorben *ideas* (VOs
inmutables, enum de operadores, contrato HTTP genérico, object mothers), nunca código ni
dependencias. Brownfield sobre el seam existente
`AbstractDoctrineSearchRepository::getSearchQueryBuilder()`; consumidor piloto: Bank
(`GET /api/v1/backoffice/banks`, 29 escenarios Behat). Estrategia de migración: Strangler Fig
sobre el seam + Parallel Change (expand–migrate–contract) en fases 0→3, un PR por fase.

**Alternativas descartadas (verificadas en el research):**

| Candidato                                | Veredicto                                                                |
|------------------------------------------|---------------------------------------------------------------------------|
| `codelytv/criteria` (require)            | `lambdish/phunctional` en Domain, sin `IN`, 0.x sin licencia              |
| `codelytv/criteria-to-doctrine`          | Apunta a `Collections\Criteria` (API equivocada) + issue #2               |
| `codelytv/criteria-from-symfony-request` | Ininstalable (constraint `^v7` vs Symfony 8), sin validación              |
| Vendorizar/fork php-criteria             | Sin licencia declarada = jurídicamente dudoso                             |

## Requisitos

**Funcionales:**

- **FR1 — Vocabulario en dominio**: `Shared/Domain/Search` gana `Filter`, `FilterOperator` (enum)
  y `Filters`; `SearchCriteria` los transporta retrocompatible. Operadores solo con consumidor
  real (`EQ`, `IN`, `CONTAINS` normalizado); el enum es el punto de extensión.
- **FR2 — Contrato HTTP genérico validado**: `filters[N][field|operator|value]` como DTOs anidados
  (`FilterQuery[]`) vía `#[MapQueryString]` + `#[Assert]`; validación en mapping (4xx Problem
  Details, nunca `ValueError`/500).
- **FR3 — Applier con allow-list obligatoria**: applier de filtros sobre QueryBuilder con
  `SearchFieldMap` por repositorio como parámetro requerido (campo público → path DQL +
  normalizador opcional).
- **FR4 — Retrocompatibilidad Bank (Parallel Change)**: `names[]`/`ids[]` conviven con `filters[]`
  mapeando a `Filters`; el contrato de respuesta no cambia. *(La fase contract posterior retiró
  `names[]`/`ids[]`: `filters[]` es el vocabulario único.)*
- **FR5 — Búsqueda inválida**: campo desconocido/no filtrable → excepción de dominio → 400 RFC 9457.
- **FR6 — Cliente PWA (fase 2)**: builder tipado de query params en `pwa/src/context/shared`
  (espejo TS); banks pasa de filtrado client-side a server-driven; cursor siempre opaco.
- **FR7 — Generalización (fase 3)**: lista filtrable nueva = ≤ 2 clases + 1 field map, sin tocar
  `Shared/`.

**No funcionales:**

- **Pureza de dominio**: 0 dependencias nuevas en `Domain/`; sin `lambdish/phunctional`.
- **Seguridad**: allow-list impuesta por la firma del applier (no opcional); parámetros Doctrine
  siempre bindeados; escape de `%`/`_` antes del LIKE de CONTAINS; cursor HMAC opaco intacto.
- **Contrato de errores (NFR26)**: todo error de entrada → 4xx RFC 9457; marker nuevo exige fila
  en `docs/api-error-contract.md` + `make php.lint.error-contract` verde.
- **Rendimiento**: `Paginator` keyset y modos LIGHT/DETAILED intactos; todo campo expuesto en un
  field map respaldado por índice (`EXPLAIN ANALYZE` antes de exponer); p95 sin regresión.
- **Compatibilidad**: cero migraciones, cero cambios de esquema; el wire legacy de Bank no se
  rompe en ninguna fase.
- **Dependencias**: únicas adiciones runtime — promoción de `phpstan/phpdoc-parser` +
  `phpdocumentor/type-resolver` a `require` (requisito de `#[MapQueryString]` con arrays de DTOs).

## Decisiones

| #  | Decisión                       | Elección                                                                    |
|----|--------------------------------|------------------------------------------------------------------------------|
| D1 | Gramática HTTP de filtros      | Lista indexada `filters[N][field\|operator\|value]` (índices contiguos desde 0) |
| D2 | Valores wire del operador      | Tokens minúsculos `eq` · `in` · `contains` — el backing string del enum ES el contrato |
| D3 | Error de búsqueda inválida     | Marker nuevo `InvalidSearchCriteria` → 400, default type `invalid-search-criteria` |
| D4 | Ubicación de `filters[]`       | `SearchQuery` base — toda lista presente y futura lo hereda sin código extra |
| D5 | Tipado de `FilterQuery::value` | Polimórfico `string\|list<string>` validado por operador en mapping          |
| D6 | Semántica CONTAINS             | Normalizar → escapar `%`/`_` → `LIKE :param` bindeado; sin normalizador, `LOWER() LIKE LOWER()` |
| D7 | Tipado del normalizador        | Interface `FieldNormalizer` con implementaciones nombradas                   |
| D8 | Camino legacy Bank (fase 1)    | Camino único: `names[]`/`ids[]` → `Filters` → applier (equivalencia fijada en Behat) |

Rationale no obvio:

- **D3 — coste NFR26 asumido conscientemente**: se prefirió expresividad del type system a
  reutilizar `InvalidInput`; el marker exige entrada en los maps de `ProblemDetailsFactory`, fila
  de doc, `MarkerStatusMapContractTest` y gate de lint en el mismo PR.
- **D4 — riesgo de sobre-exposición neutralizado** por el field map obligatorio: campo sin
  entrada → 400. La traducción nombre público → path DQL es monopolio de Infrastructure; los
  repositorios nunca llaman al applier (auto-apply del seam) ni filtran ad hoc.
- **Capas de validación (pineado)**: shape (operador inexistente, value incoherente, caps,
  índices) → mapping → `validation-failed`; semántica (campo fuera de allow-list, operador no
  permitido para el campo) → applier → familia `invalid-search-criteria`. Nada en controllers ni
  use cases.
- **Caps**: `MAX_FILTERS = 20` · `MAX_IN_VALUES = 100`. El peor caso legal (20×100 ≈ 2.000 input
  vars) supera el default PHP `max_input_vars=1000`: el límite efectivo es
  `min(caps, max_input_vars, longitud de URL)` — documentado en la forma del endpoint. Los caps
  protegen el plan de query, no el transporte.
- **Composición**: varios filtros sobre el mismo campo → AND; `filters` ausente/vacío → no-op.
  El normalizador de un campo se aplica en TODOS sus operadores (equivalencia
  `names[]` ≡ `filters[name][in]` garantizada).
- **Convención de excepciones fijada como precedente**: clase = el fallo, sin sufijo `Exception`
  (`UnknownSearchField`, `UnsupportedSearchOperator`); `type` kebab-case del nombre.

**Diferidas:** composición OR/grupos (requiere decisión arquitectónica nueva); operadores
adicionales (`neq`/`gt`/`gte`/`lt`/`lte`/`not_in`/`is_null` — solo con consumidor real; los
temporales `gt`/`gte`/`lt`/`lte` se añadieron después con su consumidor); alineación JSON:API
(descartada — research separado).

## Secuencia ejecutada

Fase 0 (núcleo sin cambio de contrato; el wiring HTTP se movió a fase 1 para que fase 0 fuera
estrictamente inalcanzable desde HTTP) → fase 1 (Bank pilota, expand) → fase 2 (PWA, migrate) →
fase 3 (generalización). Cierre posterior: fase *contract* — `filters[]` como vocabulario único.
