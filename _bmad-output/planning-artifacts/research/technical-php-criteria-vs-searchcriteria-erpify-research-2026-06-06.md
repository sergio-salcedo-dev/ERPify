---
stepsCompleted: [1, 2, 3, 4, 5, 6]
inputDocuments: []
workflowType: 'research'
lastStep: 6
research_type: 'technical'
research_topic: 'php-criteria (CodelyTV) vs SearchCriteria propio en ERPify: converger, reemplazar o coexistir'
research_goals: 'Decidir el tradeoff central (converger/reemplazar la abstracción SearchCriteria existente o coexistir con php-criteria) priorizando escalabilidad y tolerancia al cambio; incorporar el fix del issue #2 (formatOrder pasa el enum OrderType a doctrine/collections v2 que exige Order|string); encaje hexagonal (criteria framework-free en Shared/Domain, conversión Doctrine en Shared/Infrastructure, adaptador Symfony Request en Application/Http o Infrastructure); evitar requerir el paquete criteria-to-doctrine y su ruido'
user_name: 'Sergio'
date: '2026-06-06'
web_research_enabled: true
source_verification: true
---

# Research Report: technical

**Date:** 2026-06-06
**Author:** Sergio
**Research Type:** technical

---

## Research Overview

Esta investigación resuelve una decisión de adopción tecnológica en ERPify: qué hacer con [php-criteria de CodelyTV](https://github.com/CodelyTV/php-criteria) (descargado en `php-criteria-main/`) frente a la abstracción de búsqueda propia ya existente (`api/src/Shared/Domain/Search/SearchCriteria.php` + `AbstractDoctrineSearchRepository` + caso Bank), con el issue upstream [#2](https://github.com/CodelyTV/php-criteria/issues/2) como condicionante y dos criterios de decisión declarados: **escalabilidad y tolerancia al cambio**, con el coste de trabajo explícitamente fuera de la ecuación.

La metodología combinó lectura exhaustiva del código local (los 9 paquetes del monorepo php-criteria y las ~15 clases del mecanismo de búsqueda de ERPify, con subagentes paralelos), verificación contra fuentes vivas (repo y issue upstream, Packagist, doc oficial de Symfony 8.1 y Doctrine, catálogo de patrones de Fowler, API Platform, lockfile y vendor de ERPify) y un marco de niveles de confianza por afirmación. **Conclusión: converger — reimplementación propia inspirada en php-criteria, sin requerir ningún paquete `codelytv/*`** (opción C de la matriz arquitectónica); el issue #2 se vuelve estructuralmente imposible al apuntar la conversión a QueryBuilder en vez de a `Collections\Criteria`. El detalle completo está en el Executive Summary de la sección *Research Synthesis* y la matriz de opciones de *Architectural Patterns and Design*.

---

<!-- Content will be appended sequentially through research workflow steps -->

## Technical Research Scope Confirmation

**Research Topic:** php-criteria (CodelyTV) vs SearchCriteria propio en ERPify: converger, reemplazar o coexistir
**Research Goals:** Decidir el tradeoff central (converger/reemplazar la abstracción SearchCriteria existente o coexistir con php-criteria) priorizando escalabilidad y tolerancia al cambio; incorporar el fix del issue #2 (formatOrder pasa el enum OrderType a doctrine/collections v2 que exige Order|string); encaje hexagonal (criteria framework-free en Shared/Domain, conversión Doctrine en Shared/Infrastructure, adaptador Symfony Request en Application/Http o Infrastructure); evitar requerir el paquete criteria-to-doctrine y su ruido

**Technical Research Scope:**

- Architecture Analysis — patrón Criteria/Specification, comparativa estructural php-criteria vs SearchCriteria de ERPify, encaje DDD + Hexagonal
- Implementation Approaches — vendorizar vs require Composer vs reescritura inspirada; estrategia de convergencia/migración; conversión propia a Doctrine con fix del issue #2
- Technology Stack — compatibilidad PHP 8.5 / Doctrine ORM 3.6 / DBAL 4.4 / Collections v2; mantenimiento y licencia de php-criteria
- Integration Patterns — del HTTP request al Criteria de dominio sin contaminar Domain/; paginación, orden y filtros sobre Postgres
- Performance Considerations — coste de la abstracción en queries reales; tolerancia al cambio ante nuevos operadores, filtros compuestos y bounded contexts

**Research Methodology:**

- Current web data with rigorous source verification (repo upstream, issue #2, doctrine/collections v2)
- Lectura directa del código local: `php-criteria-main/` y `api/src/Shared/Domain/Search/`
- Multi-source validation for critical technical claims
- Confidence level framework for uncertain information

**Scope Confirmed:** 2026-06-06

## Technology Stack Analysis

### Stack objetivo de ERPify (verificado contra lockfile)

| Componente               | Versión ERPify                   | Fuente                                         |
|--------------------------|----------------------------------|------------------------------------------------|
| PHP                      | 8.5 (`"php": "^8.5"`)            | `api/composer.json`                            |
| Symfony                  | 8.0.x (componentes individuales) | `api/composer.json`, `docs/project-context.md` |
| Doctrine ORM             | `^3.6.7`                         | `api/composer.json`                            |
| Doctrine DBAL            | `^4.4.3`                         | `api/composer.json`                            |
| **doctrine/collections** | **2.6.0 (locked)**               | `api/composer.lock`                            |

_Confianza: ALTA — leído directamente del lockfile y composer.json._

### php-criteria (CodelyTV): paquetes y constraints

Monorepo de 9 paquetes Composer (gestión con Symplify monorepo-builder), versión publicada **0.1.3** (6 de agosto de 2024). Los relevantes para ERPify:

| Paquete                                  | require                                                                                                        | Contenido                                                                                                                               |
|------------------------------------------|----------------------------------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------|
| `codelytv/criteria`                      | `php ^8.2`, `lambdish/phunctional ^2.1`                                                                        | Núcleo framework-free: `Criteria`, `Filter(s)`, `FilterField/Operator/Value`, `Order(By/Type)`, `InvalidCriteria`                       |
| `codelytv/criteria-to-doctrine`          | `php ^8.2`, `codelytv/criteria ^0.1.3`, `doctrine/orm ^3`                                                      | Una sola clase: `CriteriaToDoctrineConverter` → `Doctrine\Common\Collections\Criteria`                                                  |
| `codelytv/criteria-from-symfony-request` | `php ^8.2`, `codelytv/criteria ^0.1.3`, `codelytv/criteria-from-url ^0.1.3`, **`symfony/http-foundation ^v7`** | Wrapper fino sobre `CriteriaFromUrlConverter` (parsea `filters[N][field/operator/value]`, `orderBy`, `order`, `pageSize`, `pageNumber`) |
| `codelytv/criteria-test-mother`          | `php ^8.2`, `codelytv/criteria ^0.1.3`                                                                         | Object mothers para tests                                                                                                               |

API del núcleo: `final readonly` value objects; `FilterOperator` enum backed con **6 operadores** (`=`, `!=`, `>`, `<`, `CONTAINS`, `NOT_CONTAINS`); `OrderType` enum (`asc`/`desc`/`none`); composición **solo AND** (sin OR ni grupos anidados); paginación **offset** (`pageSize`/`pageNumber`); factory `fromPrimitives()`.
_Fuente: lectura directa de `php-criteria-main/packages/*/composer.json` y `packages/criteria/src/*.php`. Confianza: ALTA._

### Compatibilidad con el stack de ERPify — hallazgos verificados

1. **Issue #2 confirmado contra el vendor local de ERPify.** `CriteriaToDoctrineConverter::formatOrder()` (`php-criteria-main/packages/criteria-to-doctrine/src/CriteriaToDoctrineConverter.php:63-69`) devuelve `[campo => OrderType]`. En doctrine/collections **2.6.0** (la versión locked de ERPify), `Criteria::orderBy()` tipa el closure de mapeo como `string|Order` (`api/vendor/doctrine/collections/src/Criteria.php`) → pasar el enum `CodelyTv\Criteria\OrderType` produce **TypeError en runtime**. Issue abierta el 2025-04-18 por @fredpalas, **sin respuesta de mantenedores ni PR vinculado** a fecha 2026-06-06. _Fuente: https://github.com/CodelyTV/php-criteria/issues/2 + vendor local. Confianza: ALTA._
2. **Matiz sobre el fix propuesto (`->value`)**: doctrine/collections 2.6 marca como **deprecated** pasar strings a `orderBy()` (deprecation https://github.com/doctrine/collections/pull/389, visible en `api/vendor/doctrine/collections/src/Criteria.php`). El fix tolerante al futuro no es `->orderType()->value` sino **mapear `OrderType` → enum `Doctrine\Common\Collections\Order`** (con cuidado: `OrderType::NONE` no tiene equivalente; `formatOrder()` ya devuelve `null` si no hay orden). _Confianza: ALTA — leído del código vendor._
3. **`criteria-from-symfony-request` es ininstalable en ERPify tal cual**: exige `symfony/http-foundation ^v7` y ERPify está en Symfony **8.0** → conflicto de Composer directo, independiente del encaje hexagonal. _Confianza: ALTA — constraint leída del composer.json del paquete._
4. **Dependencia transitiva en Domain**: el núcleo arrastra `lambdish/phunctional` (librería funcional). Si el núcleo viviera en `Shared/Domain`, introduce una dependencia de terceros en la capa de dominio (la regla de ERPify solo excepciona `symfony/uid`). _Confianza: ALTA._
5. **CI upstream prueba solo PHP 8.2 y 8.3** (`.github/workflows/ci.yml`), no 8.5. Y `criteria-to-doctrine` **no tiene ni un test automatizado ni linting** en el monorepo — por eso el bug del issue #2 nunca saltó en CI. _Confianza: ALTA._

### Estado de mantenimiento y licencia de php-criteria

- Última release: **0.1.3 — agosto 2024** (~22 meses sin release a fecha de hoy). 14 tags, 78 stars, 1 issue abierta (la #2), 2 PRs abiertos. Packagist: ~2.900 instalaciones totales. _Fuentes: https://github.com/CodelyTV/php-criteria, https://packagist.org/packages/codelytv/criteria. Confianza: ALTA._
- Versionado **0.x** (pre-1.0): sin garantía semver de estabilidad de API.
- **Sin licencia declarada**: no hay archivo LICENSE en el repo descargado, ni campo `license` en ningún `composer.json` del monorepo, ni licencia visible en GitHub/Packagist. Por defecto legal, código sin licencia = todos los derechos reservados → **vendorizar o copiar código literal es jurídicamente dudoso**; reimplementar el *patrón* (ideas, no código) no tiene esa restricción. _Confianza: ALTA en la ausencia observada; el intent de CodelyTV es claramente open source ("Codely Open Source"), pero el artefacto legal no existe._

### La abstracción existente de ERPify (radiografía)

- **Domain** (`api/src/Shared/Domain/Search/`): `SearchCriteria` (readonly; `cursor`, `page`, `limit`, `paginationMode`, `ids`), `PaginationMode` (LIGHT/DETAILED), `SearchCursor`, `PaginatedResult<T>`. Especialización por herencia: `BankSearchCriteria` añade `names`.
- **Application**: `SearchQuery` base con `#[Assert]` + `#[MapQueryString]` → `toCriteria()`; `BankSearchQuery`, `BankSearcher`.
- **Infrastructure**: `AbstractDoctrineSearchRepository` (QueryBuilder + helpers `addWhereIn`/`addWhereBetween*`/`addOrderByFromQueryParams`), `Paginator` con **paginación keyset por cursor firmado HMAC** (`base64(gzip(json)).hmac`), allow-list regex para order-by, modo LIGHT sin COUNT.
- **Capacidades hoy**: filtros `IN`/igualdad (con normalización diacrítica vía `NormalizedText`), multi-orden, paginación bimodal cursor/offset. **Sin** operadores `>`/`<`/`CONTAINS` genéricos, sin OR, los filtros se definen ad hoc por subclase + código en cada repositorio.
- **Consumidores reales**: solo el contexto Bank (`GET /api/v1/backoffice/banks`); la PWA ni siquiera manda query params de filtro aún (filtra client-side). Cobertura: 29 escenarios Behat (`api/features/backoffice/bank/search.feature`); sin unit tests dedicados del mecanismo.

_Fuente: exploración directa del código (rutas y líneas citadas en los informes de subagente). Confianza: ALTA._

### Tendencias de adopción relevantes

- El patrón Criteria/Specification con conversor por backend (Doctrine/Eloquent/Elasticsearch) es el enfoque canónico enseñado por CodelyTV y ampliamente adoptado en comunidades DDD-PHP; la implementación de referencia real es más el *patrón* que el paquete (las descargas de `codelytv/criteria` son modestas; muchos equipos lo reimplementan in-house).
- En Doctrine, la dirección del ecosistema es: `Collections\Criteria` para filtrado simple en memoria/repositorio, **QueryBuilder/DQL para búsqueda real con joins y rendimiento** — exactamente lo que ERPify ya hace. `EntityRepository::matching()` no cubre joins, hidratación parcial ni keyset pagination.
- doctrine/collections converge hacia el enum `Order` (strings deprecados) — cualquier conversor propio debe apuntar al enum, no a strings.

_Confianza: MEDIA-ALTA (tendencia inferida de fuentes primarias + dirección documentada de Doctrine)._

## Integration Patterns Analysis

Las tres costuras de integración que definen este encargo: contrato HTTP de filtrado, Request → Criteria de dominio, y Criteria → Doctrine. Más el contrato cross-deployable con la PWA.

### Contrato HTTP de filtrado (query params)

**php-criteria** define un contrato genérico y auto-descriptivo:

```text
GET /users?filters[0][field]=name&filters[0][operator]=CONTAINS&filters[0][value]=Javi
          &orderBy=name&order=asc&pageSize=10&pageNumber=2
```

**ERPify** define contratos tipados por recurso: `GET /api/v1/backoffice/banks?names[]=BBVA&ids[]=<uuid>&page=1&limit=25&paginationMode=light&cursor=...`.

- **JSON:API** (referencia normativa habitual) reserva `filter[...]` y es deliberadamente agnóstico sobre la estrategia: *"The base specification is agnostic about filtering strategies supported by a server"*. Ambos contratos son compatibles con esa convención; ninguno la viola. _Fuente: https://jsonapi.org/recommendations/ — Confianza: ALTA._
- **Tradeoff de seguridad**: el contrato genérico expone `field` y `operator` al cliente → exige **allow-list server-side de campos filtrables/ordenables** o se abre filtración de datos por campos no pensados para exponerse (p. ej. filtrar por una columna interna). php-criteria solo valida el operador (enum `FilterOperator::from` lanza `ValueError`); el nombre de campo viaja sin validar hasta el converter, donde `criteriaToDoctrineFields` actúa como mapeo opcional, **no** como allow-list obligatoria. El contrato tipado de ERPify es allow-list por construcción: cada campo filtrable es una propiedad del DTO con `#[Assert]`. _Confianza: ALTA (código leído)._

### Request → Criteria en Symfony 8

- `criteria-from-symfony-request` hace `parse_url($request->getRequestUri())` + `parse_str()` → `Criteria::fromPrimitives()`. **Sin validación de entrada, sin DTO, sin constraints**: un operador inválido revienta como `ValueError` (HTTP 500) en vez de un 4xx del pipeline RFC 9457 de ERPify. Esto incumple el checklist de seguridad del repo ("every DTO carries Symfony Validator constraints, enforced by `#[MapRequestPayload]`/`#[MapQueryString]` at mapping time"). Además es ininstalable (constraint `^v7`, hallazgo del paso 2). _Confianza: ALTA._
- **El camino de convergencia existe y está soportado oficialmente**: `#[MapQueryString]` soporta DTOs anidados en arrays (`@param FilterQuery[] $filters` con `phpstan/phpdoc-parser` + `phpdocumentor/type-resolver` instalados) y la opción `key:` para anidar bajo un prefijo. Es decir, **se puede modelar el contrato genérico `filters[N][field/operator/value]` como DTO tipado con `#[Assert]` completo**, manteniendo la validación en el mapping y el pipeline de errores intacto — lo mejor de ambos mundos. ERPify ya hace exactamente esto con `SearchQuery::toCriteria()`; extenderlo a filtros genéricos es el mismo patrón. _Fuente: https://symfony.com/doc/current/controller.html (doc Symfony 8.1) — Confianza: ALTA._

### Criteria → Doctrine: `matching()` vs QueryBuilder

- `criteria-to-doctrine` produce `Doctrine\Common\Collections\Criteria` para `EntityRepository::matching()`. La doc oficial de Doctrine advierte: *"The Criteria has a limited matching language that works both on the SQL and on the PHP collection level"* y *"criteria matching may happen at the database/SQL level or on objects in memory. This may lead to different results"*; desaconseja tipos custom y valores no escalares (`DateTime`). No documenta joins ni filtrado por campos de asociaciones. _Fuente: https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/working-with-associations.html — Confianza: ALTA._
- ERPify necesita lo que `matching()` no da: **joins explícitos con `addSelect`** (regla anti-N+1 del repo), **keyset pagination** (el `Paginator` con cursor HMAC opera sobre QueryBuilder), control de COUNT (modo LIGHT/DETAILED) e hidratación selectiva. La abstracción existente ya es QueryBuilder-first.
- **Hallazgo clave para el objetivo 4 (evitar `criteria-to-doctrine`)**: si la conversión propia apunta a **QueryBuilder** en vez de a `Collections\Criteria`, el issue #2 se vuelve irrelevante por construcción — `QueryBuilder::addOrderBy()` acepta la dirección como string (`'ASC'`/`'DESC'`), sin el tipado `Order|string` de Collections. El fix del enum solo importa si se usa `matching()`, que ERPify no necesita ni debería adoptar. El "ruido" del converter upstream (mapeo `criteriaToDoctrineFields` mixto, `hydrators` como callbacks sin tipar, sin tests) desaparece al escribir un conversor propio en `Shared/Infrastructure` con allow-list explícita por repositorio. _Confianza: ALTA._

### Contrato cross-deployable (PWA)

- Hoy la PWA llama `GET /api/v1/backoffice/banks` **sin query params** y filtra client-side (`pwa/src/context/backoffice/bank/infrastructure/ApiBankRepository.ts`); el único contrato vivo es el de paginación (`items` + `pagination.cursor/hasMorePages`).
- Adoptar un contrato genérico de filtros implica un mini-builder de query params en el cliente TS (espejo de Criteria). Ventaja: un solo contrato para todas las futuras listas de entidades (la memoria del proyecto registra que el rediseño de listas se replicará a otras entidades); el cliente se escribe una vez en `context/shared`. El cursor permanece opaco (HMAC server-side) — el cliente nunca lo interpreta. _Confianza: ALTA sobre el estado actual; MEDIA sobre la proyección._

### Mensajería / eventos

No aplica más allá de una frontera: Criteria es exclusivamente read-path síncrono (HTTP → repositorio). No debe filtrarse a payloads de Messenger ni a topics de Mercure (los eventos de dominio de ERPify transportan hechos, no consultas). Sin impacto en `messenger_worker`.

## Architectural Patterns and Design

### Naturaleza del patrón: Query Object, no Specification

php-criteria implementa el patrón **Query Object** de Fowler (PoEAA): *"an object that represents a database query"*, un intérprete que *"can form itself into a SQL query"* refiriéndose a clases y campos en vez de tablas y columnas. No es una Specification (regla de dominio reutilizable para validación/selección); es una gramática de consulta. El `SearchCriteria` de ERPify es también un query object, pero de **vocabulario cerrado**: un parameter object tipado por caso de uso. Ambos satisfacen el contrato del patrón **Repository**: los clientes *"construyen especificaciones de consulta de forma declarativa y las envían al Repositorio para su satisfacción"*. La decisión arquitectónica real no es "patrón sí/no" — ambos lados ya lo implementan — sino **vocabulario abierto vs cerrado**.
_Fuentes: https://martinfowler.com/eaaCatalog/queryObject.html, https://martinfowler.com/eaaCatalog/repository.html — Confianza: ALTA._

### Vocabulario abierto vs cerrado — el eje del tradeoff

| Dimensión                                            | Abierto (php-criteria)                                                                | Cerrado (SearchCriteria ERPify hoy)                      |
|------------------------------------------------------|---------------------------------------------------------------------------------------|----------------------------------------------------------|
| Añadir una lista de entidad nueva                    | Sin código nuevo de filtrado: solo allow-list de campos + repo                        | DTO + criteria subclase + lógica en repo (3 clases)      |
| Añadir un operador nuevo                             | 1 caso de enum + 1 rama en el applier (centralizado)                                  | Tocar cada DTO/repo afectado (disperso)                  |
| Verificación estática (PHPStan/Psalm nivel del repo) | Campos = strings en runtime; el análisis estático no puede verificar nombres de campo | Tipado extremo a extremo; un typo no compila el contrato |
| Seguridad                                            | Exige allow-list explícita obligatoria por recurso                                    | Allow-list por construcción (propiedades del DTO)        |
| Expresividad HTTP                                    | Cliente compone field/operator/value arbitrarios                                      | Solo lo previsto por el DTO                              |
| Riesgo de sobre-exposición                           | Alto si la allow-list se descuida                                                     | Bajo                                                     |

**Hallazgo crítico — el vocabulario de php-criteria no puede expresar la búsqueda actual de Bank**: `FilterOperator` solo tiene `=`, `!=`, `>`, `<`, `CONTAINS`, `NOT_CONTAINS` — **no existe `IN`** (ni `BETWEEN`, `IS NULL`, `>=`, `<=`). El único consumidor real de ERPify usa semántica `IN` en sus dos filtros (`ids[]`, `names[]` → `addWhereIn`). Con composición solo-AND (sin OR), `IN` ni siquiera puede emularse con N filtros de igualdad. Es decir: **adoptar php-criteria tal cual no solo añade riesgos — es funcionalmente regresivo frente a lo que ya existe**. Cualquier adopción exige extender el enum upstream (fork) o reimplementarlo. _Confianza: ALTA (código leído: `packages/criteria/src/FilterOperator.php:7-20`, `Filters.php`, `DoctrineBankRepository.php:67-79`)._

**Cómo lo resuelve el ecosistema a escala**: API Platform — la solución de referencia para colecciones filtrables en Symfony — usa exactamente el híbrido: **gramática genérica de query params** (`search[title]=Ring`, `startDate[gte]=...`) pero con **declaración explícita por recurso y propiedad** (`new QueryParameter(filter: ExactFilter::class)`, propiedades "listadas explícitamente"). Vocabulario abierto en sintaxis, cerrado en exposición. Esto valida la dirección híbrida para ERPify: contrato genérico + allow-list obligatoria por recurso.
_Fuente: https://api-platform.com/docs/core/filters/ — Confianza: ALTA._

### Encaje hexagonal (verificación del objetivo 3)

El mapeo propuesto es correcto, con matices que lo refuerzan:

| Capa                    | php-criteria (paquetes)                                                                                                              | Implementación propia convergida                                                                                  |
|-------------------------|--------------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------|
| `Shared/Domain/Search`  | `codelytv/criteria` encajaría, **pero** arrastra `lambdish/phunctional` (la regla de ERPify solo excepciona `symfony/uid` en Domain) | VOs `Filter`/`FilterOperator`/`Order` propios con **cero dependencias** — cumple la regla sin excepciones nuevas  |
| `Application/Http`      | `criteria-from-symfony-request` ininstalable (Symfony ^7) y sin validación                                                           | `SearchQuery` + `FilterQuery[]` anidados vía `#[MapQueryString]` con `#[Assert]` — patrón ya existente en el repo |
| `Shared/Infrastructure` | `criteria-to-doctrine` apunta a `Collections\Criteria` (API equivocada para ERPify) + issue #2                                       | Applier propio sobre **QueryBuilder** con allow-list por repositorio; issue #2 irrelevante por construcción       |

La arquitectura de ERPify ya tiene los puntos de extensión correctos: `AbstractDoctrineSearchRepository::getSearchQueryBuilder()` es el seam natural donde un applier genérico de filtros se enchufa sin tocar el `Paginator` ni el contrato de cursor. _Confianza: ALTA._

### Escalabilidad y rendimiento

- **Paginación**: php-criteria es offset-only (`pageSize`/`pageNumber`). La referencia canónica (Markus Winand) documenta por qué offset degrada: la BD debe *"fetch these rows from the disk and bring them in order"* antes de descartarlas, y produce anomalías (*"you'll get duplicates in case there were new rows inserted between fetching two pages"*); keyset es *"even faster than offset"* y consistente, a cambio de no saltar a páginas arbitrarias. ERPify ya tiene keyset bimodal (cursor HMAC + fallback offset + modo LIGHT sin COUNT). **Adoptar el modelo de paginación de php-criteria sería una regresión arquitectónica directa**; cualquier convergencia debe conservar el `Paginator` existente. _Fuente: https://use-the-index-luke.com/no-offset — Confianza: ALTA._
- **Joins y N+1**: la regla del repo exige `JOIN`/`addSelect` explícitos; eso vive en el QueryBuilder de cada repositorio, ortogonal a la gramática de filtros — otro motivo para que el applier propio opere sobre QueryBuilder y no sobre `matching()`.

### Seguridad arquitectónica

Invariantes que cualquier diseño resultante debe conservar (todos ya presentes en la abstracción actual):

1. **Validación en el mapping** (`#[Assert]` + `#[MapQueryString]`) → errores como 4xx Problem Details (RFC 9457), nunca `ValueError`/500.
2. **Allow-list por recurso** de campos filtrables y ordenables — el `Paginator` ya valida order-by contra regex (`ORDER_BY_IDENTIFIER_PATTERN`); un contrato genérico de filtros añade la obligación simétrica para `field`.
3. **Cursor opaco firmado** (HMAC) — el cliente nunca interpreta ni fabrica cursores; fallo de firma se silencia a cursor vacío (no oráculo).
4. **Parámetros Doctrine bindeados** — los helpers existentes (`addWhereIn` et al.) ya parametrizan; el applier genérico debe heredar esa disciplina (nunca interpolar field/value).

### Opciones arquitectónicas para el tradeoff central

| Opción                                        | Descripción                                                                                                                                                                                                        | Veredicto arquitectónico                                                                                                                                                                                                                                                                                              |
|-----------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **A. Require Composer**                       | `codelytv/criteria` (+ adaptadores propios)                                                                                                                                                                        | Bloqueada en la práctica: 0.x sin garantía semver, ~22 meses sin release, issue crítica sin respuesta, sin licencia declarada, `lambdish` en Domain, sin operador `IN`, `from-symfony-request` ininstalable. Se adoptaría la parte con menos valor (VOs triviales) y se reescribiría la difícil (conversión, request) |
| **B. Vendorizar / fork**                      | Copiar `packages/criteria/src` a `Shared/Domain`                                                                                                                                                                   | Legalmente dudosa (sin licencia = todos los derechos reservados). Hereda los huecos de diseño (sin IN/OR) que igualmente habría que reescribir                                                                                                                                                                        |
| **C. Converger (reimplementación inspirada)** | Evolucionar `SearchCriteria` absorbiendo las ideas buenas de php-criteria: VOs `Filter`/`FilterOperator` tipados, contrato HTTP genérico validado, applier QueryBuilder con allow-list; conservar Paginator/cursor | Máxima tolerancia al cambio: operadores centralizados, listas nuevas casi sin código, tipado estático intacto, cero deps en Domain, sin issue #2 por construcción                                                                                                                                                     |
| **D. Coexistir**                              | php-criteria para contextos nuevos, SearchCriteria para Bank                                                                                                                                                       | Dos vocabularios de consulta, dos pipelines de validación, dos modelos de paginación → deriva y doble mantenimiento. La peor opción frente al criterio declarado (tolerancia al cambio)                                                                                                                               |

_Evaluación preliminar (se formaliza en recomendaciones): **C** domina en los dos criterios declarados — escalabilidad y tolerancia al cambio — y satisface el objetivo 4 (cero dependencia de `criteria-to-doctrine`) por construcción. Confianza: ALTA en los hechos que sustentan la matriz; la recomendación final se cierra tras el análisis de implementación._

## Implementation Approaches and Technology Adoption

### Estrategia de adopción y migración

Dos patrones canónicos enmarcan la ejecución de la opción C:

- **Strangler Fig** (Fowler): la sustitución gradual sobre el big-bang — *"investment and returns occur gradually and visibly"* y exige identificar *"seams"* donde desacoplar. En ERPify el seam ya existe: `AbstractDoctrineSearchRepository::getSearchQueryBuilder()` es el punto donde un applier genérico de filtros se inserta sin tocar `Paginator`, cursor ni contrato de respuesta. Y el "legacy" a estrangular es mínimo (un consumidor: Bank), así que el coste de transición es el menor que jamás tendrá — cada lista de entidad futura que se construya sobre el mecanismo viejo encarece la convergencia. _Fuente: https://martinfowler.com/bliki/StranglerFigApplication.html — Confianza: ALTA._
- **Parallel Change / expand–contract** (Fowler) para el contrato HTTP: *"implement backward-incompatible changes to an interface in a safe manner"* en tres fases — expand (añadir `filters[N][...]` junto a `names[]`/`ids[]`), migrate (la PWA adopta el contrato genérico), contract (opcional: los params tipados quedan como azúcar sintáctico que mapea internamente a `Filters`, o se retiran). *"This can be done incrementally and, in the case of external clients, this will be the longest phase."* _Fuente: https://martinfowler.com/bliki/ParallelChange.html — Confianza: ALTA._

**Hoja de fases:**

1. **Fase 0 — Núcleo sin cambio de contrato.** `Shared/Domain/Search` gana `Filter`, `FilterOperator` (enum), `Filters`; `SearchCriteria` los transporta (constructor compatible vía named args, default vacío). `Shared/Infrastructure` gana el applier QueryBuilder + field map. Tests unitarios puros (Domain) e integración contra Postgres real (applier). Bank intacto.
2. **Fase 1 — Bank pilota.** `BankSearchQuery` añade `filters[]` (DTO anidado vía `#[MapQueryString]`) manteniendo `names[]`/`ids[]`, que pasan a mapear internamente a `Filters`. Extensión de `api/features/backoffice/bank/search.feature`.
3. **Fase 2 — Cliente PWA.** Builder tipado de query params en `pwa/src/context/shared` (espejo TS de Criteria); la lista de banks pasa de filtrado client-side a server-driven.
4. **Fase 3 — Generalización.** Cada nueva lista de entidad consume el mecanismo: coste marginal ≈ una subclase de `SearchQuery` (opcional) + un field map en su repositorio.

**Alcance de operadores (tensión YAGNI vs tolerancia al cambio):** se resuelve separando *diseño* de *implementación* — el enum + la rama del applier son el punto de extensión documentado (añadir un operador = 1 caso + 1 rama + tests), pero se implementan solo los operadores con consumidor real hoy (`EQ`, `IN`, `CONTAINS` normalizado). Composición OR/grupos anidados queda explícitamente fuera hasta que un caso de uso lo exija (regla del repo: *"Minimum code that solves the problem. Nothing speculative"*).

### Diseño de convergencia (boceto técnico)

- **Domain** (`Shared/Domain/Search`): `FilterOperator` enum backed (subset inicial; diseño admite `NEQ/GT/GTE/LT/LTE/NOT_IN/IS_NULL` como casos futuros), `Filter` readonly (field string + operator + valor escalar o lista), `Filters` lista inmutable. **Cero dependencias** — sin `lambdish/phunctional`; las ideas de inmutabilidad/factories de php-criteria se reproducen con PHP nativo (`readonly`, named constructors).
- **Application** (`Shared/Application/Http/Search`): `FilterQuery` DTO con `#[Assert]` (operador validado contra el enum en mapping → 4xx Problem Details, nunca `ValueError`); `SearchQuery` gana `/** @param list<FilterQuery> $filters */`. Requisito técnico verificado: promover `phpstan/phpdoc-parser` y `phpdocumentor/type-resolver` a `require` de producción — hoy están solo en `packages-dev` del lockfile como transitivas de PHPStan/Psalm (verificado en `api/composer.lock`), y el mapeo runtime de arrays de DTOs anidados los necesita (doc oficial de Symfony, paso 3).
- **Infrastructure** (`Shared/Infrastructure/Persistence/Doctrine`): `SearchFieldMap` por repositorio — **allow-list obligatoria** (parámetro requerido del applier, no opcional como el `criteriaToDoctrineFields` upstream) que mapea campo público → path DQL + normalizador opcional tipado (la versión disciplinada de los `hydrators` de php-criteria; p. ej. `names` → `b.nameNormalized` + `NormalizedText::normalize()`). El applier genera `andWhere` con parámetros bindeados reutilizando el naming hasheado (`xxh128`) de `AbstractDoctrineRepository`. Dirección de orden como string `'ASC'/'DESC'` sobre `QueryBuilder::addOrderBy()` → **issue #2 estructuralmente imposible**; si algún día se necesitara `Collections\Criteria`, mapear al enum `Order` (a prueba de la deprecación de strings).
- **Contrato de errores (NFR26):** campo desconocido/no filtrable → excepción de dominio con marker nuevo (p. ej. `InvalidSearchCriteria`) mapeado a 400 en el pipeline RFC 9457. Obligatorio: actualizar `docs/api-error-contract.md` y pasar `make php.lint.error-contract`.

### Flujo de desarrollo y tooling

- Rama `feat/shared-search-filters` desde `origin/main`; sin migraciones de BD (cero cambio de esquema). Gates del repo: `make php.stan` por archivo tocado, `make php.quality` completo al final (PHPMD sin baseline; evitar clases anónimas readonly en fixtures — usar mothers nombradas), y **ambos** `php.stan` + `php.psalm` (discrepancias conocidas entre ellos en este repo).
- Documentación obligatoria por CLAUDE.md: `docs/architecture-api.md` (patrón nuevo en Shared), `api/docs/` (forma del endpoint), `docs/api-error-contract.md` (marker), `pwa/docs/` + `docs/architecture-pwa.md` (builder cliente en fase 2).

### Testing y QA

- **Unit (Domain):** VOs puros sin contenedor ni BD. Replicar en `Erpify\Tests` la idea de los *object mothers* de `criteria-test-mother` — es la pieza de php-criteria que más merece absorberse.
- **Integración (Infrastructure):** applier contra **Postgres real** (regla del repo: nunca SQLite), cubriendo binding de parámetros, normalización y rechazo de campos fuera de allow-list.
- **Behat:** extender `search.feature` — operador inválido → 400 Problem Details; campo desconocido → 400; `filters[]` + cursor; equivalencia `names[]` ≡ `filters[name][IN]`; CONTAINS con diacríticos.
- **PWA:** Vitest para el builder; en e2e considerar los flakes conocidos del entorno local (pollución Mercure entre specs, timeout realtime local) y el patrón de espera del badge de filtro activo antes de paginar.

### Riesgos y mitigación

| Riesgo                                                              | Mitigación                                                                                                                                |
|---------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------|
| La gramática crece sin control (OR, operadores especulativos)       | Enum cerrado + applier como único punto de extensión; OR requiere decisión arquitectónica explícita, no un caso más                       |
| Allow-list tratada como opcional (el fallo de diseño upstream)      | `SearchFieldMap` es parámetro obligatorio del applier — la firma lo impone y PHPStan lo verifica                                          |
| Filtros sobre columnas sin índice → seq scans                       | El field map funciona como checklist de índices por recurso; `EXPLAIN ANALYZE` antes de exponer un campo (regla `docs/rules/database.md`) |
| Churn del contrato con la PWA                                       | Parallel Change: params tipados conviven con `filters[]` durante toda la migración                                                        |
| Interacción filtros ↔ cursor (cambiar filtros invalida la posición) | El cliente descarta el cursor al cambiar cualquier filtro — precedente ya aprendido en el race de debounce+paginación de banks            |
| Upstream php-criteria revive (licencia, `IN`, fix #2)               | La decisión es revisable: el contrato HTTP convergido es compatible conceptualmente; reevaluar solo si 1.0+ con licencia y semver         |

## Technical Research Recommendations

### Implementation Roadmap

Fases 0→3 descritas arriba; cada fase es un PR independiente con gate verde (`make php.quality` + Behat para API; `make pwa.quality` + Vitest/Playwright para PWA). Fase 0 y 1 pueden compartir PR si el tamaño lo permite; la fase 2 es el tramo largo (cliente externo, Parallel Change). Ninguna fase requiere migración de BD ni toca `main` sin PR.

### Technology Stack Recommendations

1. **No requerir ningún paquete `codelytv/*`** — ni núcleo (dep `lambdish` en Domain, sin `IN`, 0.x sin licencia) ni `criteria-to-doctrine` (API equivocada + issue #2) ni `criteria-from-symfony-request` (ininstalable en Symfony 8).
2. **Implementación propia en `Shared/`** absorbiendo las ideas valiosas de php-criteria: VOs inmutables tipados, enum de operadores, contrato HTTP genérico, object mothers, normalizadores por campo.
3. **Únicas dependencias nuevas de runtime:** `phpstan/phpdoc-parser` + `phpdocumentor/type-resolver` (promoción de dev a prod; mantenidas por el ecosistema PHPStan/phpDocumentor, ya presentes en el lockfile).
4. **QueryBuilder-first; `Collections\Criteria` nunca** para el read-path de búsqueda. Conservar `Paginator` (keyset + HMAC + LIGHT/DETAILED) tal cual.

### Skill Development Requirements

Mínimos: el equipo ya domina los bloques (enums PHP 8, QueryBuilder, MapQueryString, Behat). El único conocimiento nuevo que debe quedar escrito — no tácito — es el patrón field-map/allow-list: documentarlo en `docs/architecture-api.md` como receta para "añadir una lista filtrable", de modo que humanos y agentes la sigan idéntica.

### Success Metrics and KPIs

- **Tolerancia al cambio (criterio dominante):** añadir una lista filtrable nueva = ≤ 2 clases nuevas + 1 field map, sin tocar `Shared/`; añadir un operador = 1 caso de enum + 1 rama + tests, en un solo PR.
- **Pureza arquitectónica:** 0 dependencias nuevas en `Domain/`; `make php.lint.error-contract` y deptrac/arquitectura verdes.
- **Contrato de errores:** filtro inválido → 400 RFC 9457 verificado en Behat; 0 errores 500 por entrada de usuario.
- **Rendimiento:** p95 del endpoint de listado sin regresión tras fase 1; todo campo expuesto en field map respaldado por índice (verificado con `EXPLAIN ANALYZE`).
- **Adopción:** PWA filtra server-driven en banks (fase 2 cerrada); la siguiente entidad listada usa el mecanismo sin modificarlo (fase 3 validada).

## Research Synthesis

### Executive Summary

ERPify se planteaba adoptar php-criteria (CodelyTV) teniendo ya una abstracción de búsqueda propia, con un tradeoff declarado — converger/reemplazar vs coexistir — y dos criterios de decisión: escalabilidad y tolerancia al cambio. La investigación examinó el código de ambos lados en su totalidad, verificó cada afirmación crítica contra fuentes vivas, y produjo una conclusión inequívoca: **converger mediante reimplementación propia inspirada en php-criteria (opción C), sin requerir ningún paquete `codelytv/*`**.

La evidencia descartó las demás opciones por hechos, no por preferencia. *Requerir los paquetes* (A) está bloqueado en la práctica: `criteria-from-symfony-request` es ininstalable en Symfony 8 (constraint `^v7`), el núcleo arrastra `lambdish/phunctional` a `Domain/`, el proyecto está en 0.1.3 desde agosto de 2024 con la issue crítica #2 sin respuesta, no declara licencia, y — el hallazgo decisivo — su vocabulario de operadores **no puede expresar ni la búsqueda actual de Bank** (sin `IN`, composición solo-AND). *Vendorizar* (B) hereda esos huecos de diseño y es legalmente dudoso sin licencia. *Coexistir* (D) crea dos vocabularios de consulta y dos pipelines de validación — exactamente lo contrario de la tolerancia al cambio pedida. La convergencia (C), en cambio, absorbe lo valioso de php-criteria (VOs inmutables tipados, enum de operadores, contrato HTTP genérico, object mothers, normalizadores por campo) sobre la base ya superior de ERPify (keyset pagination con cursor HMAC, modo LIGHT, validación en mapping, pipeline RFC 9457).

Sobre el issue #2: queda **resuelto por construcción, no por parche** — el conversor propio opera sobre `QueryBuilder` (dirección de orden como string), no sobre `Collections\Criteria`, que es donde el `TypeError` existe. Si alguna vez se usara la API de Collections, el fix correcto no es `->value` (string deprecado en collections 2.6) sino mapear al enum `Doctrine\Common\Collections\Order`. Esto satisface además el objetivo de no requerir `criteria-to-doctrine` y elimina su ruido (mapeo de campos opcional, hydrators sin tipar, cero tests upstream).

**Hallazgos clave:**

- php-criteria no tiene operador `IN` ni OR: funcionalmente regresivo frente al caso Bank actual (`ids[]`, `names[]`).
- Issue #2 verificado contra el vendor real (collections 2.6.0 locked): `TypeError` garantizado con `matching()`; abierto upstream desde 2025-04-18 sin respuesta.
- `criteria-from-symfony-request`: conflicto Composer directo con Symfony 8 + parsea la URI sin validación (rompería el contrato de errores RFC 9457).
- Sin licencia declarada en todo el monorepo upstream → vendorizar/copiar es jurídicamente dudoso; reimplementar el patrón no.
- El híbrido validado por el ecosistema (API Platform): gramática genérica de filtros + declaración explícita por recurso (allow-list).
- El seam de integración ya existe (`getSearchQueryBuilder()`); el coste de converger es hoy el menor que jamás tendrá (un solo consumidor).

**Recomendaciones (resumen):**

1. Implementación propia en `Shared/` por fases 0→3 (núcleo → Bank pilota → cliente PWA → generalización), patrón expand–contract para el contrato HTTP.
2. Allow-list (`SearchFieldMap`) como parámetro obligatorio del applier; operadores mínimos hoy (`EQ`, `IN`, `CONTAINS`), enum como punto de extensión documentado.
3. Promover `phpstan/phpdoc-parser` + `phpdocumentor/type-resolver` a `require` (únicas deps nuevas de runtime).
4. Conservar `Paginator`/cursor intactos; marker de error nuevo → 400 con actualización de `docs/api-error-contract.md` (NFR26).

### Table of Contents

1. Technical Research Scope Confirmation — alcance y metodología confirmados
2. Technology Stack Analysis — stack ERPify verificado, radiografía php-criteria, compatibilidad, mantenimiento y licencia
3. Integration Patterns Analysis — contrato HTTP de filtrado, Request→Criteria en Symfony 8, Criteria→Doctrine, contrato PWA
4. Architectural Patterns and Design — Query Object vs Specification, vocabulario abierto vs cerrado, encaje hexagonal, matriz de opciones A–D
5. Implementation Approaches and Technology Adoption — estrategia de migración, boceto técnico, workflow, testing, riesgos
6. Technical Research Recommendations — roadmap, stack, skills, KPIs
7. Research Synthesis — resumen ejecutivo, metodología, conclusión

### Metodología y verificación de fuentes

**Fuentes primarias (web, consultadas 2026-06-06):**

- https://github.com/CodelyTV/php-criteria — estado del repo (0.1.3, 78 stars, 1 issue, 2 PRs)
- https://github.com/CodelyTV/php-criteria/issues/2 — bug report verificado (abierto, sin respuesta)
- https://packagist.org/packages/codelytv/criteria — releases e instalaciones
- https://symfony.com/doc/current/controller.html — `#[MapQueryString]`, DTOs anidados, validación (doc 8.1)
- https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/working-with-associations.html — límites de `matching()`
- https://github.com/doctrine/collections/pull/389 — deprecación de strings en `orderBy()`
- https://jsonapi.org/recommendations/ — convención `filter[...]`
- https://api-platform.com/docs/core/filters/ — filtros declarados por recurso
- https://martinfowler.com/eaaCatalog/queryObject.html, https://martinfowler.com/eaaCatalog/repository.html — patrones base
- https://martinfowler.com/bliki/StranglerFigApplication.html, https://martinfowler.com/bliki/ParallelChange.html — patrones de migración
- https://use-the-index-luke.com/no-offset — keyset vs offset

**Evidencia local (fuente de máxima autoridad para este encargo):** lectura completa de `php-criteria-main/packages/*` (9 paquetes, con subagente dedicado), del mecanismo de búsqueda de ERPify (`api/src/Shared/Domain/Search`, `Shared/Infrastructure/Persistence`, contexto Bank completo, con segundo subagente), y verificaciones puntuales de `api/composer.lock` (collections 2.6.0; phpdoc-parser/type-resolver en `packages-dev`) y `api/vendor/doctrine/collections/src/Criteria.php` (firma `string|Order`).

**Marco de confianza:** cada afirmación lleva nivel explícito; todas las críticas para la decisión son ALTA (código o fuente primaria leída). Las únicas MEDIA son proyecciones (adopción futura del contrato por la PWA, tendencia de reimplementación in-house del patrón).

**Limitaciones:** no se ejecutaron benchmarks empíricos (la comparativa de paginación se apoya en la referencia canónica y en el diseño ya operativo de ERPify); la ausencia de licencia upstream se verificó sobre la copia descargada + GitHub/Packagist, y podría cambiar; la decisión es revisable si upstream publica 1.0+ con licencia, `IN` y el fix del #2.

### Conclusión y próximos pasos

La decisión formal es **converger (opción C)**: evolucionar `SearchCriteria` absorbiendo las ideas correctas de php-criteria como implementación propia en `Shared/`, manteniendo intactos el `Paginator` keyset/HMAC y el pipeline de validación/errores, con conversión a Doctrine vía QueryBuilder que hace el issue #2 estructuralmente imposible y elimina la dependencia de `criteria-to-doctrine`. Es la única opción que maximiza los dos criterios declarados — escalabilidad (coste marginal por nueva lista ≈ un field map) y tolerancia al cambio (operadores centralizados en un enum propio, contrato HTTP genérico validado, cero acoplamiento a un 0.x sin mantenimiento).

**Próximos pasos sugeridos:**

1. PR fase 0 (`feat/shared-search-filters`): VOs + applier + field map + tests, sin cambio de contrato.
2. PR fase 1: `filters[]` en Bank con Behat ampliado y actualización de `docs/architecture-api.md`, `api/docs/` y `docs/api-error-contract.md`.
3. Fase 2 (PWA) y fase 3 (generalización) según roadmap.
4. Opcional (goodwill upstream): PR a CodelyTV con el fix del #2 mapeando `OrderType` → `Order` enum — beneficia a la comunidad aunque ERPify no consuma el paquete.
5. Retirar `php-criteria-main/` del working tree una vez cerrada la decisión (es material de estudio, no debe committearse).

---

**Technical Research Completion Date:** 2026-06-06
**Source Verification:** todas las afirmaciones críticas citadas contra fuente primaria (web viva o código local)
**Confidence Level:** ALTO en todos los hechos que sustentan la decisión
