---
status: 'complete — shipped (stories 1.5–1.7, PR #180)'
date: '2026-06-06'
---

# ADR — Generic `filters[]` search vocabulary (SearchQuery / SearchCriteria)

Decision record: rationale, alternatives weighed, and the FR/NFR inventory cited by ID from the living docs. The current-state description of the shipped system lives in [`architecture-api.md`](./architecture-api.md).

## Project Context Analysis

### Requirements Overview

**Functional Requirements:**

Sin PRD formal: la base de requisitos es el research técnico
`technical-php-criteria-vs-searchcriteria-erpify-research-2026-06-06.md`,
cuya decisión formal (opción C — converger mediante reimplementación propia
inspirada en php-criteria, sin requerir ningún paquete `codelytv/*`) define el
alcance funcional:

1. **FR1 — Vocabulario de filtros en dominio**: `Shared/Domain/Search` gana
   `Filter`, `FilterOperator` (enum) y `Filters`; `SearchCriteria` los
   transporta de forma retrocompatible (named args, default vacío). Operadores
   iniciales: solo los con consumidor real (`EQ`, `IN`, `CONTAINS`
   normalizado); el enum es el punto de extensión documentado.
2. **FR2 — Contrato HTTP genérico validado**: `filters[N][field/operator/value]`
   modelado como DTOs anidados (`FilterQuery[]`) vía `#[MapQueryString]` +
   `#[Assert]`, manteniendo la validación en mapping (4xx Problem Details,
   nunca `ValueError`/500).
3. **FR3 — Applier QueryBuilder con allow-list obligatoria**:
   `Shared/Infrastructure` gana el applier de filtros sobre QueryBuilder con
   `SearchFieldMap` por repositorio como parámetro requerido (campo público →
   path DQL + normalizador opcional). Issue #2 upstream irrelevante por
   construcción.
4. **FR4 — Retrocompatibilidad Bank (Parallel Change)**: `names[]`/`ids[]`
   conviven con `filters[]` y mapean internamente a `Filters`; el contrato de
   respuesta (items + pagination.cursor/hasMorePages) no cambia.
5. **FR5 — Error de búsqueda inválida**: campo desconocido/no filtrable →
   excepción de dominio mapeada a 400 en el pipeline RFC 9457.
6. **FR6 — Cliente PWA (fase 2)**: builder tipado de query params en
   `pwa/src/context/shared` (espejo TS de Criteria); banks pasa de filtrado
   client-side a server-driven; cursor siempre opaco.
7. **FR7 — Generalización (fase 3)**: nueva lista filtrable = ≤ 2 clases
   nuevas + 1 field map, sin tocar `Shared/`.

**Non-Functional Requirements:**

- **Pureza de dominio**: 0 dependencias nuevas en `Domain/` (la regla solo
  excepciona `symfony/uid`); sin `lambdish/phunctional`.
- **Seguridad**: allow-list impuesta por la firma del applier (no opcional);
  parámetros Doctrine siempre bindeados; cursor HMAC opaco intacto; fallo de
  firma silenciado (no oráculo).
- **Contrato de errores (NFR26)**: todo error de entrada → 4xx RFC 9457;
  marker nuevo exige actualizar `docs/api-error-contract.md` y pasar
  `make php.lint.error-contract`.
- **Rendimiento**: conservar `Paginator` keyset/HMAC y modos LIGHT/DETAILED
  tal cual; p95 del listado sin regresión tras fase 1; todo campo expuesto en
  field map respaldado por índice (`EXPLAIN ANALYZE`).
- **Compatibilidad**: cero migraciones de BD; cero cambios de esquema; el
  contrato existente de Bank no se rompe en ninguna fase.
- **Dependencias**: únicas adiciones de runtime permitidas:
  `phpstan/phpdoc-parser` + `phpdocumentor/type-resolver` (promoción
  dev → prod, requisito de `#[MapQueryString]` con arrays de DTOs).
- **Calidad**: gates del repo — `make php.stan`,
  `make php.quality` completo, Behat extendido; PHPMD sin baseline.

**Scale & Complexity:**

- Primary domain: full-stack API-céntrico (fases 0–1 `api/`, fase 2 `pwa/`,
  fase 3 transversal)
- Complexity level: media — mecanismo transversal de la fundación compartida,
  pero acotado (sin BD, un consumidor piloto, seam existente)
- Estimated architectural components: ~10 (3 VOs Domain + DTO(s) Application +
  applier + field map + marker de excepción en API; builder + tipos en PWA)

### Technical Constraints & Dependencies

- Stack fijado: PHP 8.5 · Symfony 8.0 (componentes) · Doctrine ORM 3.6 /
  DBAL 4.4 / collections 2.6 · PostgreSQL 18 · Next 16 / TS 6 estricto.
- DDD + Hexagonal load-bearing: `Infrastructure → Application → Domain`;
  el seam de inserción es `AbstractDoctrineSearchRepository::getSearchQueryBuilder()`.
- Prohibido: paquetes `codelytv/*` (ininstalable/incompleto/sin licencia),
  `Collections\Criteria`/`matching()` para el read-path, vendorizar código
  upstream (sin licencia declarada).
- Estrategia de migración fijada: Strangler Fig sobre el seam + Parallel
  Change (expand–migrate–contract) para el contrato HTTP, en fases 0→3 con
  PR independiente por fase.
- Estado actual relevante: único consumidor Bank
  (`GET /api/v1/backoffice/banks`, 29 escenarios Behat); la PWA aún filtra
  client-side; `SearchExceptionListener` (legacy, priority 32) convive con
  `ExceptionResponder` (priority 16).

### Cross-Cutting Concerns Identified

- **Contrato de errores RFC 9457**: cualquier validación nueva debe fluir por
  el pipeline existente (factory única, sin `JsonResponse` manual).
- **Contrato cross-deployable API↔PWA**: la gramática `filters[N][...]` es
  consumida por el builder TS; cambiar filtros invalida el cursor (el cliente
  lo descarta — precedente del race debounce+paginación de banks).
- **Documentación obligatoria por PR** (CLAUDE.md): `docs/architecture-api.md`
  (patrón nuevo + receta "añadir lista filtrable"), `api/docs/` (forma del
  endpoint), `docs/api-error-contract.md` (marker), `pwa/docs/` +
  `docs/architecture-pwa.md` (builder, fase 2).
- **Análisis estático** PHPStan `level: max` (única autoridad de tipos; el análisis
  general de Psalm fue retirado, solo queda taint) y PHPMD sin baseline: afecta a cómo se escriben fixtures y tests.
- **Tensión YAGNI vs tolerancia al cambio**: diseño admite operadores futuros
  (`NEQ/GT/GTE/LT/LTE/NOT_IN/IS_NULL`) y OR/grupos, pero solo se implementa
  lo consumido; OR requiere decisión arquitectónica explícita.

## Starter Template Evaluation

### Primary Technology Domain

Brownfield — evolución de la fundación compartida (`api/src/Shared/` +
`pwa/src/context/shared`) de un monorepo full-stack existente. No aplica
scaffolding de proyecto nuevo.

### Starter Options Considered

No se evalúan starters de proyecto: el stack está fijado y operativo
(verificado contra `api/composer.lock` y `pwa/package-lock.json` en el
research de 2026-06-06). La única "base externa" candidata — los paquetes
`codelytv/*` de php-criteria como fundación del mecanismo de filtros — fue
evaluada exhaustivamente en el research y descartada por hechos:

| Candidato                                | Veredicto                                                                  |
|------------------------------------------|----------------------------------------------------------------------------|
| `codelytv/criteria` (require)            | Descartado: `lambdish/phunctional` en Domain, sin `IN`, 0.x sin licencia   |
| `codelytv/criteria-to-doctrine`          | Descartado: apunta a `Collections\Criteria` (API equivocada) + issue #2    |
| `codelytv/criteria-from-symfony-request` | Descartado: ininstalable (constraint `^v7` vs Symfony 8), sin validación   |
| Vendorizar/fork php-criteria             | Descartado: sin licencia declarada = jurídicamente dudoso                  |

### Selected Foundation: fundación existente de ERPify (no starter)

**Rationale for Selection:**
La arquitectura se construye sobre los puntos de extensión ya operativos del
monorepo; el patrón de php-criteria se absorbe como *ideas* (VOs inmutables,
enum de operadores, contrato HTTP genérico, object mothers), nunca como
código o dependencia.

**Initialization Command:**

Ninguno (brownfield). El trabajo arranca con una rama estándar:

```bash
make worktree.create BRANCH=feat/shared-search-filters
```

**Architectural Decisions Provided by the Existing Foundation:**

**Language & Runtime:** PHP 8.5 / Symfony 8.0 (componentes) · TS 6 strict /
Next 16 — fijado por `docs/project-context.md`, no renegociable aquí.

**Persistence & Search plumbing:** Doctrine QueryBuilder-first;
`AbstractDoctrineSearchRepository<T>` como seam; `Paginator` keyset con
cursor HMAC y modos LIGHT/DETAILED — se conserva intacto.

**HTTP boundary:** `#[MapQueryString]` + `#[Assert]` sobre DTOs readonly
(`SearchQuery`) con errores vía pipeline RFC 9457 — patrón existente que el
contrato genérico de filtros extiende.

**Testing Framework:** PHPUnit 13 (unit/functional) + Behat (E2E API,
29 escenarios existentes en `search.feature`) + Vitest/Playwright (PWA) —
infraestructura ya configurada.

**Code Organization:** DDD + Hexagonal con bounded contexts
(`Backoffice/Frontoffice/Shared` · `context/<bc>/{domain,application,infrastructure}`)
— las piezas nuevas se ubican siguiendo este patrón.

**Development Experience:** Make-first desde repo root, stacks por worktree,
gates `php.stan`/`php.quality`/`pwa.quality`.

**Note:** No hay historia de inicialización de proyecto; la primera historia
de implementación es la fase 0 del roadmap (núcleo sin cambio de contrato).

## Core Architectural Decisions

### Decision Priority Analysis

**Critical Decisions (Block Implementation):**

| #  | Decisión                       | Elección                                                  |
|----|--------------------------------|------------------------------------------------------------|
| D1 | Gramática HTTP de filtros      | Lista indexada `filters[N][field\|operator\|value]`        |
| D2 | Valores wire del operador      | Tokens minúsculos: `eq` · `in` · `contains`                |
| D3 | Error de búsqueda inválida     | **Marker nuevo** `InvalidSearchCriteria` → 400             |
| D4 | Ubicación de `filters[]`       | `SearchQuery` base (todas las listas lo heredan)           |
| D5 | Tipado de `FilterQuery::value` | Polimórfico `string\|list<string>` validado por operador   |

**Important Decisions (Shape Architecture):**

| #  | Decisión                  | Elección                                                                  |
|----|----------------------------|----------------------------------------------------------------------------|
| D6 | Semántica CONTAINS         | LIKE parametrizado vía normalizador del field map (escape `%`/`_` siempre) |
| D7 | Tipado del normalizador    | Interface `FieldNormalizer` con implementaciones nombradas                 |
| D8 | Camino legacy Bank (fase 1) | Camino único: `names[]`/`ids[]` → `Filters` → applier                      |

**Deferred Decisions (Post-MVP):**

- Composición OR / grupos anidados — requiere decisión arquitectónica
  explícita futura; el diseño no la precluye.
- Operadores adicionales (`neq`, `gt`, `gte`, `lt`, `lte`, `not_in`,
  `is_null`) — el enum + la rama del applier son el punto de extensión;
  se añaden solo con consumidor real.
- Fase *contract* del Parallel Change (retirar `names[]`/`ids[]` del wire
  o dejarlos como azúcar permanente) — se decide cuando la PWA complete
  la migración (fase 2).
- Alineación con JSON:API — fuera de alcance por decisión explícita
  (research separado existente).

### Data Architecture

Heredada sin cambios: PostgreSQL 18 + Doctrine ORM 3.6 / DBAL 4.4,
QueryBuilder-first. **Cero migraciones, cero cambios de esquema.**
`Paginator` keyset (cursor HMAC, modos LIGHT/DETAILED) intacto. La única
novedad de datos es indirecta: todo campo expuesto en un `SearchFieldMap`
debe estar respaldado por índice (verificación `EXPLAIN ANALYZE` antes de
exponer — regla `docs/rules/database.md`).

### Authentication & Security

Sin cambios de autenticación/autorización. Decisiones de seguridad del
mecanismo:

- **Allow-list por construcción (D3 + FR3)**: `SearchFieldMap` es parámetro
  obligatorio del applier; campo fuera de la lista → `UnknownSearchField`
  (marker `InvalidSearchCriteria`) → 400 RFC 9457. Nunca se interpola `field` ni `value` en DQL: el path DQL
  sale del map, el valor va bindeado.
- **Escape de comodines (D6)**: el applier escapa `%` y `_` del valor antes
  del LIKE de CONTAINS — un filtro no puede convertirse en patrón arbitrario.
- **Validación en mapping (D5)**: la coherencia operador↔shape de `value` se
  valida con constraints en `FilterQuery` → `ValidationFailedException` →
  400 `validation-failed` con `violations[]`; un operador desconocido jamás
  llega a código de dominio.
- Cursor HMAC opaco y silenciamiento de fallo de firma: sin cambios.

### API & Communication Patterns

- **D1 — Gramática**: `filters[N][field]`, `filters[N][operator]`,
  `filters[N][value]` (o `value[]`), mapeada a `list<FilterQuery>` vía
  `#[MapQueryString]` con `@param list<FilterQuery> $filters` en el
  constructor de `SearchQuery`. Compatible con la convención JSON:API de
  prefijo de filtro sin adoptarla formalmente.
- **D2 — Operadores**: enum `FilterOperator: string` con `Eq = 'eq'`,
  `In = 'in'`, `Contains = 'contains'`. El backing string ES el contrato
  wire — consistente con `paginationMode=light` existente.
- **D3 — Contrato de errores**: nuevo marker `InvalidSearchCriteria` en
  `Shared/Domain/Exception/` mapeado a 400, default type
  `invalid-search-criteria`. **Coste NFR26 asumido conscientemente** (se
  prefirió expresividad del type system a reutilizar `InvalidInput`):
  - Entrada en `MARKER_STATUS_MAP` + `MARKER_DEFAULT_TYPE_MAP`
    (`ProblemDetailsFactory`).
  - Fila nueva en la tabla de `docs/api-error-contract.md`.
  - Actualización de `MarkerStatusMapContractTest`.
  - Gate `make php.lint.error-contract` en verde.
- **D4 — `filters[]` en la base**: toda lista (presente y futura) acepta el
  contrato genérico sin código adicional; el riesgo de sobre-exposición lo
  neutraliza el field map obligatorio (sin entrada → 400).
- **D5 — `value` polimórfico**: `string|list<string>`; IN exige lista no
  vacía, EQ/CONTAINS exigen string — validado por constraint compuesta en
  el propio DTO (en mapping, no después).
- Paginación (`page`/`limit`/`cursor`/`paginationMode`) y orden
  (`sort`/`direction`) **sin cambios** — fuera del vocabulario de filtros.

### Frontend Architecture

Fase 2 (diferida en detalle, fijada en ubicación y principios):

- Espejo TS del vocabulario en `pwa/src/context/shared/domain/Search/`
  (tipos `Filter`/`FilterOperator`) + builder de query params en
  `pwa/src/context/shared/infrastructure/` — named exports, sin default.
- El builder serializa exactamente la gramática D1/D2; el cursor permanece
  opaco (nunca se interpreta client-side).
- Regla de interacción ya aprendida: cambiar cualquier filtro descarta el
  cursor actual (precedente del race debounce+paginación de banks).
- La lista de banks pasa de filtrado client-side a server-driven en esa fase.

### Infrastructure & Deployment

Sin cambios de hosting, CI/CD, contenedores ni observabilidad. Las únicas
alteraciones de build:

- Promoción de `phpstan/phpdoc-parser` + `phpdocumentor/type-resolver` de
  `packages-dev` (transitivas) a `require` de `api/composer.json` —
  versiones las ya locked en `api/composer.lock`; requisito runtime de
  `#[MapQueryString]` con arrays de DTOs anidados (verificado contra doc
  Symfony 8.1 en el research).
- Gates existentes sin cambios: `make php.stan` +
  `make php.quality` + Behat; `make pwa.quality` + Vitest en fase 2.

### Decision Impact Analysis

**Implementation Sequence:**

1. **Fase 0** — D2 (enum), D5 (FilterQuery + constraints), D4 (SearchQuery
   base), D6+D7 (applier + SearchFieldMap + FieldNormalizer), D3 (marker +
   NFR26 completo). Sin cambio de contrato público; Bank intacto.
2. **Fase 1** — D8 (Bank pilota: camino único vía Filters, repo elimina
   `addWhereIn` ad hoc), D1 visible en el wire (`filters[]` junto a
   `names[]`/`ids[]`), Behat ampliado (equivalencia legacy≡genérico,
   400s, diacríticos).
3. **Fase 2** — Frontend (builder TS + banks server-driven).
4. **Fase 3** — Generalización: cada lista nueva = subclase opcional de
   `SearchQuery` + field map en su repositorio.

**Cross-Component Dependencies:**

- D3 ⇄ pipeline RFC 9457: el applier (Infrastructure) lanza la excepción
  de dominio con el marker; `ExceptionResponder`/`ProblemDetailsFactory`
  la resuelven sin wiring nuevo. El legacy `SearchExceptionListener`
  (priority 32) gana respuesta antes que `ExceptionResponder` (16) en sus
  rutas — verificar en fase 1 que no intercepta la excepción nueva.
- D4 ⇄ D8: como `filters[]` vive en la base, `BankSearchQuery` queda
  reducido al mapeo legacy→Filters; cuando la fase *contract* retire los
  params tipados, la subclase puede desaparecer.
- D6 ⇄ D7: CONTAINS depende del normalizador del field map; la primera
  implementación de `FieldNormalizer` envuelve `NormalizedText` (patrón
  diacrítico de Bank).
- D5 ⇄ D2: la constraint de shape de `value` se deriva del operador —
  añadir un operador futuro obliga a declarar su shape esperado en la
  misma constraint (punto de extensión único).

## Implementation Patterns & Consistency Rules

### Pattern Categories Defined

Las convenciones generales del repo (PSR-12, capas, naming, ubicación de
tests, Make-first) ya están fijadas por `docs/project-context.md` y
`docs/rules/*.md` — aquí solo se pinean los puntos donde agentes IA podrían
divergir implementando ESTE mecanismo: **8 áreas críticas**.

### Naming Patterns

**Nombres y namespaces exactos (API) — no inventar variantes:**

| Pieza                  | FQCN                                                                                            |
|------------------------|--------------------------------------------------------------------------------------------------|
| VO filtro              | `Erpify\Shared\Domain\Search\Filter`                                                            |
| Colección              | `Erpify\Shared\Domain\Search\Filters`                                                           |
| Enum operador          | `Erpify\Shared\Domain\Search\FilterOperator` (`Eq='eq'`, `In='in'`, `Contains='contains'`)      |
| Marker (D3)            | `Erpify\Shared\Domain\Exception\InvalidSearchCriteria` (junto a los 7 existentes)               |
| Excepciones concretas  | `Erpify\Shared\Domain\Search\Exception\UnknownSearchField` · `UnsupportedSearchOperator`        |
| DTO HTTP               | `Erpify\Shared\Application\Http\Search\FilterQuery` (junto a `SearchQuery`)                     |
| Applier                | `Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\FilterApplier`                        |
| Allow-list             | `…\Doctrine\Search\SearchFieldMap` (entradas: `…\Doctrine\Search\FieldMapping`)                 |
| Normalizador           | `…\Doctrine\Search\FieldNormalizer` (interface) · `NormalizedTextFieldNormalizer` (1ª impl)     |

- Excepciones concretas: clase = el fallo, sin sufijo `Exception` (patrón
  `BankNotFound`/`RateLimitExceeded`); `type` kebab-case del nombre:
  `unknown-search-field`, `unsupported-search-operator`.
- Campos públicos de filtro: camelCase idéntico a la propiedad serializada
  del recurso (`name`, `createdAt`) — singular; el legacy `names[]` mapea
  al campo público `name`. Las claves del `SearchFieldMap` SON esos nombres
  públicos.
- PWA: tipos en `pwa/src/context/shared/domain/Search/` (union type
  `FilterOperator = 'eq' | 'in' | 'contains'` + const — no TS `enum`);
  builder en `pwa/src/context/shared/infrastructure/`. Named exports
  siempre; ficheros según convención de los siblings existentes.

### Structure Patterns

- **Seam auto-apply**: `AbstractDoctrineSearchRepository` gana
  `abstract protected function searchFieldMap(): SearchFieldMap` y aplica
  `FilterApplier` automáticamente antes de paginar. Los repositorios NUNCA
  llaman al applier ni filtran ad hoc: solo declaran su field map.
- Piezas Doctrine nuevas en subdirectorio
  `Shared/Infrastructure/Persistence/Doctrine/Search/` (no flat).
- Tests espejo del src: unit puro para `Domain/Search` (sin contenedor/BD),
  unit para `FilterQuery`, integración del applier contra **Postgres real**
  (nunca SQLite) bajo `api/tests/` espejando el namespace.
- Object mothers: sufijo `Mother` (`FilterMother`, `FiltersMother`),
  espejo del namespace del VO en `api/tests/` — clases nombradas, jamás
  clases anónimas readonly (gotcha PHPMD/PDepend conocido).
- Behat: extender `api/features/backoffice/bank/search.feature`, no crear
  feature paralela.

### Format Patterns

- **Gramática wire exacta**: `filters[N][field]`, `filters[N][operator]`,
  `filters[N][value]` (escalar) o `filters[N][value][]` (lista). Índices
  contiguos desde 0; otra forma → 400 `validation-failed`.
- **Caps**: `SearchQuery::MAX_FILTERS = 20` · `FilterQuery::MAX_IN_VALUES
  = 100` — constantes públicas junto a `MAX_PAGE`/`MAX_LIMIT`, validadas
  con `#[Assert]` en mapping.
- `filters` ausente o vacío → sin filtrado (no es error).
- Varios filtros sobre el mismo campo → **AND** (se aplican todos);
  coherente con la composición solo-AND heredada.
- `value` no vacío (`NotBlank`); en IN, lista no vacía y cada item no vacío.
- Operador estrictamente lowercase (`in`, no `IN`) — el enum decide.
- **Capa de validación de cada error** (pineado para no duplicar):
  - Forma/shape (operador inexistente, value incoherente, caps, índices) →
    mapping `#[MapQueryString]` → 400 `validation-failed` + `violations[]`.
  - Semántica (campo fuera de allow-list, operador no permitido para el
    campo) → applier → 400 familia `invalid-search-criteria`.
  - Ninguna validación de filtros en controller ni en use case.

### Communication Patterns

- Sin eventos de dominio nuevos: Criteria es read-path síncrono. PROHIBIDO
  transportar `Filters`/`SearchCriteria` en payloads de Messenger o topics
  de Mercure.
- El builder TS serializa la gramática exacta D1/D2; el cursor es opaco y
  se descarta client-side al cambiar cualquier filtro.

### Process Patterns

- **Normalización**: el `FieldNormalizer` de un campo se aplica al valor en
  TODOS los operadores de ese campo (eq, in y contains) — equivalencia
  garantizada `names[]` ≡ `filters[name][in]`.
- **CONTAINS**: normalizar → escapar `%` y `_` → `LIKE :param` bindeado
  sobre el path DQL del map. Campos sin normalizador: `LOWER(path) LIKE
  LOWER(:param)`.
- **Operadores por campo**: `FieldMapping` declara sus operadores
  permitidos (default: los tres); violación → `UnsupportedSearchOperator`.
  Bank: `id` → solo `eq`/`in` (CONTAINS sobre UUID rompería a nivel SQL).
- Parámetros DQL con el naming hasheado (`xxh128`) heredado de
  `AbstractDoctrineRepository` — nunca nombres manuales.
- `Filters` vacío en el applier → no-op silencioso.
- `Filters`/`Filter` inmutables (`final readonly`), factoría estática
  (named constructors), cero dependencias externas.

### Enforcement Guidelines

**All AI Agents MUST:**

- Pasar `make php.stan` por archivo tocado;
  `make php.quality` completo al final (PHPMD sin baseline).
- Pasar `make php.lint.error-contract` tras tocar el marker (D3) y
  actualizar `docs/api-error-contract.md` + `MarkerStatusMapContractTest`
  en el mismo PR.
- Demostrar en Behat la equivalencia legacy≡genérico antes de eliminar
  los `addWhereIn` ad hoc de Bank (D8).
- `make pwa.quality` + Vitest del builder en fase 2.

**Pattern Enforcement:**

- La firma `apply(QueryBuilder, Filters, SearchFieldMap)` hace imposible
  invocar el applier sin allow-list; el método abstracto hace imposible un
  repo de búsqueda sin field map.
- La receta "añadir una lista filtrable" se documenta en
  `docs/architecture-api.md` (mismo PR de fase 1) — fuente única para
  humanos y agentes.

### Pattern Examples

**Good example — request completa:**

```text
GET /api/v1/backoffice/banks?filters[0][field]=name&filters[0][operator]=in
    &filters[0][value][]=BBVA&filters[0][value][]=Caixa
    &filters[1][field]=name&filters[1][operator]=contains&filters[1][value]=banc
    &limit=25&paginationMode=light
```

**Good example — repositorio nuevo (fase 3, código completo de filtrado):**

```php
protected function searchFieldMap(): SearchFieldMap
{
    return new SearchFieldMap([
        'name' => new FieldMapping('b.nameNormalized', $this->normalizedText),
        'id'   => new FieldMapping('b.id', operators: [FilterOperator::Eq, FilterOperator::In]),
    ]);
}
```

**Anti-Patterns (prohibidos):**

- ❌ `EntityRepository::matching()` / `Collections\Criteria` en el read-path.
- ❌ Requerir o copiar código de `codelytv/*`.
- ❌ Interpolar `field`/`value` en DQL; parámetros sin bindear.
- ❌ Filtrado ad hoc en repositorios (`addWhereIn` para campos ya mapeables).
- ❌ Validar filtros en controller o use case (duplica la capa pineada).
- ❌ `JsonResponse` manual para errores de filtro (bypass RFC 9457).
- ❌ TS `enum` para `FilterOperator` en la PWA (union + const).
- ❌ Clases anónimas readonly como fakes en tests del mecanismo.

## Project Structure & Boundaries

### Complete Project Directory Structure

Árbol **delta** sobre el monorepo existente (brownfield): solo ficheros
nuevos `[N]`, modificados `[M]` o eliminados `[D]`, organizados por fase.
Rutas verificadas contra el working tree el 2026-06-06.

**Fase 0 — Núcleo (PR `feat/shared-search-filters`), sin cambio de contrato HTTP:**

```text
api/src/Shared/
├── Domain/
│   ├── Exception/
│   │   └── InvalidSearchCriteria.php                 [N] marker → 400 (D3)
│   └── Search/
│       ├── Filter.php                                [N] VO readonly
│       ├── Filters.php                               [N] colección inmutable
│       ├── FilterOperator.php                        [N] enum eq|in|contains
│       ├── SearchCriteria.php                        [M] +filters (named arg, default vacío)
│       └── Exception/
│           ├── UnknownSearchField.php                [N] type unknown-search-field
│           └── UnsupportedSearchOperator.php         [N] type unsupported-search-operator
├── Application/Problem/
│   └── ProblemDetailsFactory.php                     [M] marker en STATUS/TYPE maps
└── Infrastructure/Persistence/Doctrine/Search/       [N] subdirectorio nuevo
    ├── FilterApplier.php                             [N] andWhere + binds (xxh128)
    ├── SearchFieldMap.php                            [N] allow-list obligatoria
    ├── FieldMapping.php                              [N] path DQL + operadores + normalizador
    ├── FieldNormalizer.php                           [N] interface
    └── NormalizedTextFieldNormalizer.php             [N] envuelve Shared/Domain/ValueObject/NormalizedText

api/tests/
├── Unit/Shared/Domain/Search/
│   ├── FilterTest.php · FiltersTest.php · FilterOperatorTest.php   [N]
│   └── Mother/FilterMother.php · FiltersMother.php   [N] primeros mothers del repo
├── Unit/Shared/Application/Problem/
│   └── MarkerStatusMapContractTest.php               [M] pin del marker nuevo
└── Functional/Shared/Persistence/
    └── FilterApplierTest.php                         [N] integración Postgres real

docs/api-error-contract.md                            [M] fila InvalidSearchCriteria (NFR26)
```

**Fase 1 — Bank pilota (expand del Parallel Change):**

```text
api/composer.json                                     [M] promoción phpdoc-parser + type-resolver a require
api/src/Shared/
├── Application/Http/Search/
│   ├── FilterQuery.php                               [N] DTO anidado + MAX_IN_VALUES=100
│   └── SearchQuery.php                               [M] +filters[] + MAX_FILTERS=20 + toCriteria()
└── Infrastructure/Persistence/Doctrine/
    └── AbstractDoctrineSearchRepository.php          [M] abstract searchFieldMap() + auto-apply

api/src/Backoffice/Bank/
├── Application/Http/BankSearchQuery.php              [M] names[]/ids[] → Filters (D8)
├── Application/BankSearcher.php                      [M] firma a SearchCriteria base
├── Domain/Repository/BankSearchRepository.php        [M] firma a SearchCriteria base
├── Domain/Search/BankSearchCriteria.php              [D] redundante tras D8 (dir Domain/Search/ desaparece)
└── Infrastructure/Persistence/Doctrine/
    └── DoctrineBankRepository.php                    [M] +searchFieldMap(); elimina addWhereIn ad hoc

api/tests/Unit/Shared/Application/Http/Search/
├── FilterQueryTest.php                               [N]
└── SearchQueryTest.php                               [M]
api/features/backoffice/bank/search.feature           [M] equivalencia legacy≡genérico, 400s, diacríticos

docs/architecture-api.md                              [M] receta "añadir una lista filtrable"
api/docs/                                             [M] forma del endpoint
```

**Fase 2 — Cliente PWA (migrate del Parallel Change):**

```text
pwa/src/context/shared/
├── domain/Search/
│   ├── Filter.ts                                     [N] type Filter + FilterOperator (union + const)
│   └── index.ts                                      [N]
└── infrastructure/Search/
    ├── buildSearchParams.ts                          [N] serializa gramática D1/D2
    └── index.ts                                      [N]

pwa/src/context/backoffice/bank/
├── domain/BankRepository.ts                          [M] firma acepta filtros
└── infrastructure/ApiBankRepository.ts               [M] server-driven (deja de filtrar client-side)

pwa/tests/context/shared/infrastructure/Search/
└── buildSearchParams.test.ts                         [N] Vitest

pwa/docs/ · docs/architecture-pwa.md                  [M]
```

**Fase 3 — Generalización:** cero ficheros en `Shared/`; cada lista nueva
aporta su `searchFieldMap()` (+ subclase opcional de `SearchQuery`).

> **Refinamiento de la Implementation Sequence (step 4):** el wiring HTTP
> (`SearchQuery`+`filters[]`, método abstracto, promoción de deps) se mueve
> de fase 0 a fase 1 — D4 en la base hace que cualquier endpoint exponga
> `filters[]` en cuanto se wirea, y fase 0 debe ser estrictamente
> sin-cambio-de-contrato. Fase 0 queda inalcanzable desde HTTP.

### Architectural Boundaries

**API Boundaries:**

- Endpoint afectado (única superficie pública): `GET /api/v1/backoffice/banks`.
  Expand en fase 1: `filters[N][...]` se suma a `names[]`/`ids[]`/paginación.
- Frontera de validación en dos capas (pineada en step 5): shape → mapping
  (`validation-failed`); semántica → applier (`unknown-search-field` /
  `unsupported-search-operator`). Nada entre medias.

**Component Boundaries:**

- `FilterApplier` es invocado EXCLUSIVAMENTE por
  `AbstractDoctrineSearchRepository` (auto-apply). Ningún controller, use
  case ni repositorio concreto lo llama.
- `SearchFieldMap` se construye EXCLUSIVAMENTE dentro de cada repositorio
  concreto (`searchFieldMap()`): la allow-list vive con el conocimiento del
  esquema, nunca en Application.
- `Domain/Search` no conoce DQL ni field maps: `Filter` transporta el nombre
  PÚBLICO del campo; la traducción a paths es monopolio de Infrastructure.

**Data Boundaries:**

- El applier solo añade `andWhere` + parámetros bindeados al QueryBuilder;
  paginación, orden, joins y COUNT siguen siendo monopolio del `Paginator`
  y de `getSearchQueryBuilder()` de cada repo (joins anti-N+1 intactos).
- Sin caché nueva; sin esquema nuevo; el cursor HMAC no cambia de formato.

### Requirements to Structure Mapping

| FR | Ubicación física |
|----|------------------|
| FR1 vocabulario | `Shared/Domain/Search/{Filter,Filters,FilterOperator}.php` |
| FR2 contrato HTTP | `Shared/Application/Http/Search/{FilterQuery,SearchQuery}.php` |
| FR3 applier+allow-list | `Shared/Infrastructure/Persistence/Doctrine/Search/` (5 ficheros) |
| FR4 retrocompat Bank | `Backoffice/Bank/Application/Http/BankSearchQuery.php` + `DoctrineBankRepository.php` |
| FR5 error 400 | `Shared/Domain/Exception/InvalidSearchCriteria.php` + `Search/Exception/` + `ProblemDetailsFactory.php` |
| FR6 cliente PWA | `pwa/src/context/shared/{domain,infrastructure}/Search/` |
| FR7 generalización | receta en `docs/architecture-api.md` + método abstracto del seam |

### Integration Points

**Internal Communication (flujo de datos del read-path):**

```text
HTTP query string
  → #[MapQueryString] → SearchQuery(+FilterQuery[]) [validación shape]
  → toCriteria() → SearchCriteria(+Filters)         [Domain, sin framework]
  → BankSearcher → BankSearchRepository
  → AbstractDoctrineSearchRepository::getPaginatedResults()
      ├─ getSearchQueryBuilder()   [joins por repo, intacto]
      ├─ FilterApplier::apply(qb, filters, searchFieldMap()) [allow-list+binds]
      └─ Paginator                 [cursor/keyset, intacto]
  → AbstractSearchController::buildResponse()       [envelope intacto]
```

**External Integrations:** ninguna nueva. La PWA consume el mismo endpoint
con query params adicionales construidos por `buildSearchParams`.

**Error flow:** excepciones del applier → `ExceptionResponder` (priority 16)
→ `ProblemDetailsFactory` → 400 RFC 9457. Verificar en fase 1 que el legacy
`SearchExceptionListener` (priority 32) no intercepta las excepciones nuevas
en rutas de búsqueda.

### File Organization Patterns

- Config: solo `api/composer.json` cambia (fase 1). Sin cambios en Compose,
  Make, CI ni `.env`.
- Tests espejo del src (convención existente verificada:
  `tests/Unit/Shared/Application/Http/Search/SearchQueryTest.php`).
- Mothers: subnamespace `Mother/` junto al test unit del VO — primer
  precedente del repo, queda fijado aquí.
- PWA: subdirectorios PascalCase con `index.ts` (convención verificada de
  `infrastructure/{HttpClient,Validation,RealTime}/`).
- Convención de excepciones fijada como precedente para `Shared/`: clase =
  el fallo, sin sufijo `Exception` (resuelve la inconsistencia detectada
  entre `BankNotFoundException` y `RateLimitExceeded` a favor del
  walk-through canónico de `docs/api-error-contract.md`).

### Development Workflow Integration

- Un PR por fase (fases 0 y 1 pueden compartir si el tamaño lo permite);
  rama por worktree: `make worktree.create BRANCH=feat/shared-search-filters`.
- Gates por fase: `make php.stan` por archivo, `make
  php.quality` + `make php.behat` al cierre; fase 0/1 además
  `make php.lint.error-contract`. Fase 2: `make pwa.quality` + `make pwa.test`.
- Cierre de la decisión del research: retirar `php-criteria-main/` del
  working tree (material de estudio, no se committea).

## Architecture Validation Results

### Coherence Validation ✅

**Decision Compatibility:**
Las 8 decisiones (D1–D8) son mutuamente compatibles y compatibles con el
stack locked (PHP 8.5 / Symfony 8.0 / Doctrine ORM 3.6 / collections 2.6).
Verificaciones específicas: el marker D3 requiere alta en la lista canónica
de `firstMatchingMarker` (cubierto por la entrada en `MARKER_STATUS_MAP`);
el refinamiento de fases del step 6 resuelve la tensión D4⇄fase 0 (wiring
HTTP movido a fase 1); el seam auto-apply y la implementación obligada de
`searchFieldMap()` en `DoctrineBankRepository` caen en el mismo PR (fase 1),
sin estado intermedio roto. Dirección de dependencias correcta en todas las
piezas (`FieldNormalizer` infra → `NormalizedText` domain; VOs sin imports).

**Pattern Consistency:**
Naming, capas de validación, normalización y enforcement (step 5) soportan
las decisiones sin contradicción. La convención sin-sufijo de excepciones
queda fijada explícitamente como precedente, resolviendo la inconsistencia
preexistente del repo. Nota: el union `string|list<string>` de
`FilterQuery::value` solía provocar discrepancias PHPStan↔Psalm; ese riesgo
desapareció al retirar el análisis general de Psalm — PHPStan `level: max` es
ahora el único gate de tipos.

**Structure Alignment:**
El árbol delta (step 6) materializa cada decisión en rutas verificadas
contra el working tree; los boundaries impiden por construcción los
anti-patterns pineados (applier solo invocable desde la base; allow-list
solo construible en el repositorio).

### Requirements Coverage Validation ✅

**Functional Requirements Coverage:**
FR1–FR7 mapeados a ubicaciones físicas concretas (tabla del step 6).
FR4 (equivalencia legacy) tiene mecanismo (D8 camino único) y verificación
(Behat equivalencia `names[]` ≡ `filters[name][in]`).

**Non-Functional Requirements Coverage:**

| NFR             | Cobertura                                                                                      |
|-----------------|--------------------------------------------------------------------------------------------------|
| Pureza dominio  | VOs con cero deps; marker vacío bajo guard de `TaxonomyArchitectureTest`                        |
| Seguridad       | Allow-list por firma + binds + escape `%`/`_` + caps + cursor intacto                           |
| NFR26           | Tareas explícitas en fases 0/1 (maps, doc, contract test, lint gate)                            |
| Rendimiento     | Paginator intacto; field map como checklist de índices + `EXPLAIN ANALYZE`; p95 sin regresión como KPI de fase 1 |
| Compatibilidad  | Cero migraciones; Parallel Change preserva el wire legacy                                       |
| Dependencias    | Solo 2 promociones dev→prod, versiones ya locked                                                |
| Calidad         | Gates por fase definidos en Development Workflow Integration                                    |

### Implementation Readiness Validation ✅

**Decision Completeness:** D1–D8 con rationale, elección y efectos; las
deferred quedan listadas con su disparador de reapertura.

**Structure Completeness:** árbol delta a nivel de fichero con marcadores
[N]/[M]/[D] por fase; sin placeholders genéricos.

**Pattern Completeness:** 8 áreas de conflicto entre agentes pineadas con
ejemplos positivos y anti-patterns; receta de generalización asignada a
`docs/architecture-api.md`.

### Gap Analysis Results

**Critical Gaps:** ninguno.

**Important Gaps (resueltos en esta validación):**

1. *Límites operativos del peor caso legal* — 20 filtros × 100 valores IN
   ≈ 2.000 input vars supera el default PHP `max_input_vars=1000` (y la
   longitud de URL acota antes). **Resolución:** el límite efectivo es
   `min(caps, max_input_vars, longitud de URL)`; documentarlo en la forma
   del endpoint (`api/docs/`), y añadir escenario Behat de frontera
   moderada (1 filtro IN × 100 valores) que fije el comportamiento real.
   No se cambian los caps: protegen el plan de query, no el transporte.

**Minor Gaps (resueltos en esta validación):**

2. *Obligación documental de directorio nuevo* — `Doctrine/Search/` exige
   también actualizar `docs/source-tree-analysis.md` y
   `docs/claude-code-quickref.md` (regla CLAUDE.md). **Resolución:**
   añadidos a los [M] documentales del PR de fase 1.

### Validation Issues Addressed

Ambos hallazgos incorporados arriba con resolución concreta; no queda
ningún issue abierto.

### Architecture Completeness Checklist

**Requirements Analysis**

- [x] Project context thoroughly analyzed
- [x] Scale and complexity assessed
- [x] Technical constraints identified
- [x] Cross-cutting concerns mapped

**Architectural Decisions**

- [x] Critical decisions documented with versions
- [x] Technology stack fully specified
- [x] Integration patterns defined
- [x] Performance considerations addressed

**Implementation Patterns**

- [x] Naming conventions established
- [x] Structure patterns defined
- [x] Communication patterns specified
- [x] Process patterns documented

**Project Structure**

- [x] Complete directory structure defined
- [x] Component boundaries established
- [x] Integration points mapped
- [x] Requirements to structure mapping complete

### Architecture Readiness Assessment

**Overall Status:** READY FOR IMPLEMENTATION

**Confidence Level:** Alta — la base es un research con verificación de
fuentes del mismo día, rutas comprobadas contra el working tree y
decisiones tomadas explícitamente con sus trade-offs.

**Key Strengths:**

- Decisión de fondo (opción C) respaldada por evidencia verificada, no
  por preferencia.
- Allow-list y consistencia impuestas por firmas y métodos abstractos —
  el compilador y PHPStan vigilan el patrón, no la disciplina del agente.
- Fases estrictamente incrementales: fase 0 inalcanzable desde HTTP,
  Parallel Change protege a la PWA, Behat fija la equivalencia.
- Issue #2 upstream estructuralmente imposible (QueryBuilder-first).

**Areas for Future Enhancement:**

- OR/grupos y operadores adicionales (deferred con disparador claro).
- Fase *contract*: retirar `names[]`/`ids[]` o consagrarlos como azúcar.
- Extender el guard de pureza arquitectónica a todo `Domain/` (debt
  preexistente documentada en el deep-dive, fuera de este alcance).

### Implementation Handoff

**AI Agent Guidelines:**

- Seguir las decisiones D1–D8 y los patrones del step 5 exactamente.
- Respetar el árbol delta y los boundaries del step 6.
- Ante cualquier duda arquitectónica, este documento manda; ante conflicto
  con CLAUDE.md o docs/rules, señalar el conflicto en vez de elegir.

**First Implementation Priority:**
Fase 0 — `make worktree.create BRANCH=feat/shared-search-filters`; VOs de
dominio + applier + field map + marker (NFR26 completo) + tests unit e
integración Postgres. Sin cambio de contrato HTTP.
