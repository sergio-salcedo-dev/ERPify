---
stepsCompleted: [1, 2, 3, 4, 5, 6]
inputDocuments: []
workflowType: 'research'
lastStep: 6
research_type: 'technical'
research_topic: 'Adopción de JSON:API en ERPify sin API Platform'
research_goals: 'Evaluar la viabilidad y el coste de adoptar el estándar JSON:API en ERPify (API Symfony 8 hexagonal sin API Platform + PWA Next.js 16): librerías PHP standalone compatibles con PHP 8.5/Symfony 8, include/sparse fieldsets sobre Doctrine sin N+1, cliente de normalización [type,id] en Next.js/TypeScript con Mercure, reconciliación del contrato de errores RFC 9457 (NFR26) con JSON:API errors, estrategia de adopción incremental por bounded context, y comparativa con la alternativa de adoptar solo las convenciones de query params (filter/sort/page/fields/include) sobre el formato JSON actual.'
user_name: 'Sergio'
date: '2026-06-06'
last_updated: '2026-06-12'
web_research_enabled: true
source_verification: true
---

# Research Report: technical

**Date:** 2026-06-06
**Author:** Sergio
**Research Type:** technical
**Última actualización:** 2026-06-12 — estado de implementación sincronizado con `main`; decisiones pendientes acotadas a `fields[]` e `include`/`expand`

---

## Research Overview

Esta investigación evalúa la adopción del estándar JSON:API v1.1 en ERPify — API Symfony 8 con arquitectura DDD/hexagonal **sin API Platform** (restricción dura del usuario) y PWA Next.js 16 — frente a la alternativa de menor coste de adoptar solo sus convenciones de query params sobre JSON plano. Se ejecutó en cinco pasos (alcance → stack → integración → arquitectura → implementación) mediante **11 líneas de investigación web paralelas** con verificación contra fuentes primarias (jsonapi.org, Packagist, npm, GitHub, Drupal.org, OWASP, docs de Symfony/Doctrine/Stripe/Google/Zalando) el 2026-06-06, con niveles de confianza por dato y catálogo explícito de lagunas documentales.

**Conclusión central:** el valor de JSON:API para ERPify está en sus convenciones de consulta, no en su document format. El envelope completo presenta cinco bloqueos verificados (ecosistema PHP muerto sin API Platform, conflicto con el contrato RFC 9457/NFR26, sin cliente TS canónico para App Router, beneficio de latencia refutado con HTTP/2/3, y superficie BOLA/BOPLA elevada para un ERP multi-rol), mientras que las convenciones de query params son adoptables a bajo coste ancladas a Google AIP y Zalando Guidelines, con expansión de relaciones estilo Stripe. Resumen ejecutivo completo y recomendación en 7 puntos en la sección **Síntesis de la investigación** al final del documento.

**Actualización 2026-06-12:** la ruta recomendada (capturar las convenciones de consulta sin adoptar el envelope) está mayoritariamente implementada en `main`: `filters[]` como vocabulario único de búsqueda, paginación keyset con cursor opaco firmado, envelope plano `data`+`pagination` y contrato RFC 9457 intacto y ampliado. La sintaxis concreta shippeada **diverge deliberadamente de la sintaxis JSON:API** que proponía el punto 1 de la recomendación — decisión registrada en ADRs propios. Quedan pendientes de decisión los dos elementos restantes del alcance original: **sparse fieldsets (`fields[]`)** y **expansión de relaciones (`include`/`expand` estilo Stripe)**. Detalle en la sección **Estado de implementación (actualización 2026-06-12)**.

---

<!-- Content will be appended sequentially through research workflow steps -->

## Estado de implementación (actualización 2026-06-12)

_Verificado contra el código de `main` el 2026-06-12. Los hallazgos web del informe (verificados el 2026-06-06) siguen vigentes; esta sección sincroniza el informe con lo construido desde entonces y acota las decisiones pendientes._

### Recomendación en 7 puntos: estado por punto

| # | Recomendación (2026-06-06) | Estado en `main` (2026-06-12) |
|---|---|---|
| 1 | Query params con sintaxis JSON:API (`sort` con `-`, `filter[...]`, `page[...]`, `fields[...]`) | **Superada por decisión de diseño.** Se shippeó un vocabulario propio, más estructurado que el de JSON:API: `filters[N][field]`+`[operator]`+`[value]` (tripletas con operadores explícitos), `after`/`before`/`limit` top-level con cursor opaco, y `sort`+`direction` como params separados. Decisiones registradas en `docs/adr/filters-search-criteria.md` y `docs/adr/keyset-pagination.md`. El espíritu de la recomendación (cursor opaco server-driven, allowlists, no offset — AIP-158/Zalando 160) se conserva; la sintaxis literal JSON:API se descartó. |
| 2 | Expansión de relaciones estilo Stripe (`include`/`expand`) | **Pendiente — sin implementar ni stub.** Una de las dos decisiones abiertas (ver más abajo). |
| 3 | Mapeo a Criteria/Specification | **Implementado.** `SearchQuery` (`#[MapQueryString]`, `api/src/Shared/Application/Http/Search/`) → `SearchCriteria` de dominio (`api/src/Shared/Domain/Search/`) → `DoctrineSearchEngine` en Infrastructure. El dominio no conoce la sintaxis wire. |
| 4 | Allowlists + autorización por recurso | **Parcial.** Allowlists por repositorio implementadas (`SearchFieldMap`/`SortFieldMap` obligatorios, campo desconocido → 422) más un gate dual-marker en `FieldMapping` que rechaza en build-time combinaciones operador/tipo imposibles (LIKE sobre UUID, rangos sobre texto). El pre-filtrado por rol/tenant y los voters por recurso siguen fuera del alcance shippeado (no hay aún capa de autorización por roles en la API). |
| 5 | Errores: RFC 9457 intacto | **Implementado y ampliado.** Pipeline `application/problem+json` único; nuevos problem types: 422 `validation-failed` (mapping DTO), familia 422 `invalid-search-criteria` (`unknown-search-field`, `unsupported-search-operator`, `invalid-search-value`, `unknown-sort-field`, `invalid-pagination`, `invalid-cursor`) y 400 `invalid-uuid` (`Uuid::ensure()` en path ids). Catálogo en `docs/api-error-contract.md`. |
| 6 | Cliente PWA: TanStack Query | **Superada por decisión de diseño.** La PWA consume la búsqueda vía use case hexagonal + fetch (p. ej. `BackOfficeSearchBanks`) y navega siguiendo los links construidos por el servidor (`pagination.links.next`/`prev`) sin inspeccionar el cursor; cambio de filtro/sort descarta el cursor activo. TanStack Query no se adoptó; Mercure se integra por hook propio (`useBankRealtime`). |
| 7 | Gates de contrato | **Parcial.** El gate `make php.lint.error-contract` sigue cubriendo el contrato de errores; la convención de consulta está protegida por tests directos del motor y e2e cursor+sort, pero no consta lint de contrato OpenAPI (Spectral/oasdiff) sobre la convención. |

### Contrato de consulta shippeado (resumen citable)

- **Filtros:** `filters[N][field]`, `filters[N][operator]`, `filters[N][value]`; operadores `eq | in | contains | gt | gte | lt | lte`; máx. 20 filtros por petición, índices contiguos desde 0; `in` con máx. 100 valores; valores máx. 255 chars, trim Unicode-aware; valores siempre como parámetros bind de Doctrine con normalizadores por campo (accent-folding, ASCII-upper) y escapado de comodines LIKE.
- **Paginación keyset:** `after` / `before` (excluyentes) + `limit` (default 25, máx. 100) + `paginationMode=light|detailed` (`light` sin COUNT → `count: null`). Cursor opaco: `base64url(JSON{v, dir, values, fp})` + HMAC-SHA256 con `%kernel.secret%`, tope 512 bytes pre-HMAC; el fingerprint sella entidad+filtros+sort+limit y cualquier mismatch → 422 `invalid-cursor` (causa interna distinguible solo en logs). Tie-break implícito por `id` para orden total estable.
- **Orden:** `sort=<campo>` + `direction=asc|desc`, allowlist por `SortFieldMap`.
- **Envelope de colección:** `{ "data": [...], "pagination": { "hasNext", "hasPrev", "count", "links": { "next", "prev" } } }` — nulls explícitos (sin `skip_null_values`); los links preservan todos los params validados sustituyendo solo el cursor.

### Decisiones pendientes: `fields[]` e `include`/`expand`

Los dos elementos del alcance original aún sin decidir. La investigación de los pasos 2-4 sigue siendo la base de evidencia; el motor ya shippeado añade restricciones nuevas que la decisión debe tener en cuenta.

**Sparse fieldsets (`fields[]`)**

- Evidencia vigente del informe: proyección en la capa de serialización, no en SQL (PARTIAL frágil); superficie BOPLA — la respuesta debe ser la *intersección* entre la allowlist por rol y los campos pedidos; convenciones de referencia AIP-157 (field masks, default `*`) y Zalando Rule 157.
- Restricciones nuevas del motor shippeado: (a) la proyección actual son **serializer groups** — `fields[]` sería un refinamiento dinámico sobre ellos, no un mecanismo paralelo; (b) el responder ya propaga todos los query params a `links.next/prev`, así que `fields[]` viajaría en la paginación sin trabajo extra; (c) hay que decidir explícitamente si `fields[]` entra en el fingerprint del cursor — no altera el orden de filas, por lo que lo natural es dejarlo fuera (cursores estables al cambiar proyección), pero es una decisión de contrato, no un default; (d) sintaxis: el vocabulario propio ya divergió de JSON:API, y con un solo resource type por endpoint `fields=a,b` bastaría frente a `fields[type]=a,b`.
- Coste estimado: bajo (proyección en serialización + validación allowlist). Valor dependiente de presión real de payload — **diferible sin coste** hasta que una vista la demuestre.

**Expansión de relaciones (`include`/`expand`)**

- Evidencia vigente del informe: Stripe `expand[]` (allowlist + profundidad máx. 4), Drupal hardening (profundidad máx. 2 — cifra operativa recomendada), to-one por join barato vs. to-many por segunda query `WHERE IN`, autorización por **cada** recurso expandido (BOLA), y la laguna confirmada "cursor + include to-many sin receta publicada".
- Restricciones nuevas del repo y del motor: (a) la regla per-aggregate de ERPify — **ningún grafo de objetos cruza frontera de módulo**; la composición de lectura es un DQL JOIN explícito a un projection DTO por endpoint (`docs/adr/bank-bankaccount-modeling.md`) — hace que un motor genérico de `include` cross-módulo contradiga un ADR vigente: lo coherente es expansión por proyección explícita y acotada por endpoint, no un mecanismo genérico tipo Drupal; (b) keyset + to-many encaja con la receta inferida en el informe (el cursor pagina la colección raíz; los expandidos se resuelven por `WHERE IN` posterior y quedan fuera del fingerprint); (c) la autorización por recurso expandido depende de una capa de roles que la API aún no tiene — implementar `expand` antes que la autorización invertiría el orden de las mitigaciones BOLA del informe.
- Coste estimado: el más alto de los dos, con superficie de seguridad propia. **Candidata a ADR + spike dedicados** cuando aparezca el primer caso real de composición de lectura que el patrón actual (JOIN a projection DTO) no cubra bien; el spike benchmark expand-vs-N-requests sobre FrankenPHP sigue siendo el paso previo recomendado.

### Triggers de re-evaluación

Sin cambios respecto a los documentados en las recomendaciones (consumidores externos múltiples, batch transaccional, madurez del ecosistema Symfony 8 / extensión `problem-details`). Ninguno se ha activado a fecha de esta actualización.

---

## Confirmación del alcance de la investigación técnica

**Tema de investigación:** Adopción de JSON:API en ERPify sin API Platform
**Objetivos:** Evaluar viabilidad y coste de adoptar JSON:API en ERPify (API Symfony 8 hexagonal sin API Platform + PWA Next.js 16): librerías PHP standalone compatibles con PHP 8.5/Symfony 8; `include`/sparse fieldsets sobre Doctrine sin N+1; cliente de normalización `[type,id]` en Next.js/TypeScript con Mercure; reconciliación del contrato de errores RFC 9457 (NFR26) con JSON:API errors; adopción incremental por bounded context; comparativa con la alternativa de adoptar solo las convenciones de query params sobre el formato JSON actual.

**Alcance:**

- Análisis de arquitectura — adaptador en `Infrastructure/`, adopción incremental por bounded context, convivencia de formatos vía content-negotiation
- Enfoques de implementación — librerías PHP standalone (sin API Platform), serializador propio sobre `symfony/serializer`
- Stack tecnológico — cliente Next.js/TypeScript de normalización `[type,id]`, integración con Inversify y Mercure
- Patrones de integración — `include`/sparse fieldsets sobre Doctrine sin N+1; RFC 9457 ↔ JSON:API errors sin romper NFR26
- Consideraciones de rendimiento — compound documents vs. múltiples llamadas, paginación, coste de `include` profundos

**Restricciones fijadas:** API Platform descartado (decisión del usuario); la comparativa incluye la alternativa de menor coste (solo convenciones de query params JSON:API sobre el formato actual).

**Metodología:**

- Datos web actuales con verificación rigurosa de fuentes
- Validación multi-fuente para afirmaciones técnicas críticas
- Niveles de confianza para información incierta
- Cobertura técnica completa con insights específicos de arquitectura

**Alcance confirmado:** 2026-06-06

---

## Análisis del stack tecnológico

_Datos verificados el 2026-06-06 contra Packagist, registro npm (descargas semana 2026-05-27 → 2026-06-02), GitHub y jsonapi.org mediante tres líneas de investigación paralelas. Confianza por dato: [ALTA]/[MEDIA]/[BAJA]._

### Estado del estándar JSON:API

- **v1.1 es la versión vigente**, finalizada el 2022-09-30. Existe un milestone `v1.2` en GitHub pero con 0/2 issues cerrados y sin fecha — planificación abierta sin progreso material. [ALTA]
  _Fuente: https://jsonapi.org/format/_ · _Fuente: https://github.com/json-api/json-api/milestones_
- **El comité está en modo mantenimiento editorial**, no de evolución: la actividad reciente en `gh-pages` (última visible: marzo 2025) son correcciones de enlaces/typos/aclaraciones. Riesgo de abandono bajo; ritmo de evolución lento. [ALTA]
  _Fuente: https://github.com/json-api/json-api/commits/gh-pages_
- **La "Bulk Extension" está oficialmente DEPRECADA** ("New APIs should not use the old system or any extensions designed for it"). El mecanismo moderno para operaciones batch transaccionales es la extensión **Atomic Operations** (`https://jsonapi.org/ext/atomic`, namespace `atomic:operations` con `add`/`update`/`remove`, todo-o-nada). La sustitución es funcional/de facto: la página de Bulk no nombra a Atomic como sucesor 1:1. [ALTA en deprecación; MEDIA en la equivalencia]
  _Fuente: https://jsonapi.org/extensions/bulk/_ · _Fuente: https://jsonapi.org/ext/atomic/_ · _Fuente: https://github.com/json-api/json-api/pull/1437_
  > ⚠️ Corrección a la propuesta original: la "Bulk Extension" citada como ventaja está muerta; lo vigente es Atomic Operations.
- **Query params**: las cinco familias (`filter`, `sort`, `page`, `fields`, `include`) están **reservadas** (normativo), pero la semántica de `filter` y `page` es **agnóstica** — el spec no da una sintaxis de filtros lista (a diferencia de OData); la convención concreta de `filter[...]` y la estrategia de paginación hay que diseñarlas igualmente. Las "recommendations" de jsonapi.org son explícitamente no normativas. Existe el profile oficial "Cursor Pagination". [ALTA]
  _Fuente: https://jsonapi.org/recommendations/_ · _Fuente: https://jsonapi.org/extensions/_
- **Errores**: JSON:API exige `application/vnd.api+json` también para errores, con sobre `errors` (array) y miembros `id`/`links`/`status` (string)/`code`/`title`/`detail`/`source.pointer|parameter|header`/`meta`. Incompatible estructuralmente con RFC 9457 (objeto único, `status` numérico, `application/problem+json`). **No existe patrón oficial de mezcla ni convergencia**: el issue json-api/json-api#1787 (ene-2025) que pide alinear con Problem Details sigue abierto sin decisión. [ALTA]
  _Fuente: https://jsonapi.org/format/#errors_ · _Fuente: https://github.com/json-api/json-api/issues/1787_ · _Fuente: https://www.rfc-editor.org/rfc/rfc9457.html_

### Librerías servidor PHP (sin API Platform)

**Hallazgo transversal:** ninguna librería del ecosistema declara ni testea PHP 8.4/8.5; todas usan constraints abiertas (`>=8.1`, `^8.0`) que *permiten* instalar en 8.5 sin garantía del maintainer. [ALTA]

| Librería | Última versión (fecha) | PHP | Estado | Enfoque | Cobertura spec |
|---|---|---|---|---|---|
| `tobyz/json-api-server` | 1.0.0-rc.1 (2026-01-01) | `>=8.1` | **Activo** (RC, 24k instal.) | Framework servidor agnóstico (PSR-7/15) | include, sparse, sort, page, filter, **atomic ops** |
| `laravel-json-api/neomerx-json-api` | 5.0.3 (2024-11-29) | `^7.4\|^8.0` | Activo (fork mantenido) | **Encoder agnóstico** (solo `ext-json`) | Encoder v1.1: include, sparse |
| `woohoolabs/yin` | 4.3.0 (2024-04-20) | `^7.1\|\|^8.0` | **En pausa** (sin releases 2025-26) | Serialización + hidratación PSR-7 | include, sparse, page (offset/cursor) |
| `json-api-php/json-api` | 3.0.0 (2022-07-27) | `>=8.1` | Bajo (sin releases desde 2022) | Document builder puro | Solo construcción de documentos |
| `laravel-json-api/core` | 5.3.0 (2026-03-28) | `^8.2` | Muy activo | **Acoplado a Laravel** (`illuminate/*`) | Server completo — no usable en Symfony |
| `paknahad/jsonapi-bundle` | 5.0.0 (2021-12-06) | `>=7.1.3` | **Estancado** | Bundle Symfony 4.4–6.0 sobre yin | **No apto Symfony 8** (arrastra sensio/framework-extra-bundle deprecado) |
| `neomerx/json-api` | 4.0.1 (2020-03-03) | `>=7.1` | **ABANDONADO** | Encoder | Sustituido por el fork de laravel-json-api |
| `nilportugues/symfony-jsonapi` | 2.1.0 (2017) | `>=5.5` | **ABANDONADO** | Bundle Symfony 2/3 | Descartar |

_Fuentes: https://packagist.org/packages/tobyz/json-api-server · https://packagist.org/packages/laravel-json-api/neomerx-json-api · https://packagist.org/packages/woohoolabs/yin · https://packagist.org/packages/paknahad/jsonapi-bundle · https://packagist.org/packages/json-api-php/json-api · https://packagist.org/packages/neomerx/json-api · https://packagist.org/packages/nilportugues/jsonapi-bundle_

- **No existe bundle Symfony moderno para JSON:API**: las dos opciones históricas (paknahad, nilportugues) están muertas o estancadas. [ALTA]
- **Symfony core NO trae normalizers JSON:API** — los únicos del ecosistema pertenecen a API Platform (descartado). La vía nativa es escribir normalizers/encoders propios registrados con tags `serializer.normalizer`/`serializer.encoder`, que encajan como adaptador en `Infrastructure/`; el encoding de bajo nivel puede delegarse en `laravel-json-api/neomerx-json-api` (fork agnóstico vivo, solo `ext-json`). [MEDIA-ALTA]
  _Fuente: https://symfony.com/doc/current/serializer/custom_normalizer.html_
- **Trade-off central servidor**: `tobyz/json-api-server` es el único vivo con cobertura completa del spec (incl. atomic operations) pero es un *servidor schema-driven* que sustituye routing/parsing (`#[MapQueryString]`) y necesitaría un adaptador de persistencia para Doctrine; las librerías de serialización pura respetan los controladores Symfony puros pero dejan el parsing de `include`/`fields`/`sort`/`page`/`filter` como trabajo propio. [ALTA]

### Cliente JavaScript/TypeScript (PWA Next.js 16)

**Hallazgo transversal:** **no existe un cliente JSON:API canónico, tipado y mantenido para App Router/RSC.** Los clientes "completos" están abandonados o son incompatibles con el stack: `jsonapi-react` (v0.0.25, 2022, Context-based, sin RSC), `coloquent` (beta perpetua 2022, 405 desc/sem), `@datx/jsonapi` (estancado 2023, requiere MobX), `json-api-normalizer` (2021, **sin tipos TS**, era Redux). [ALTA]

| Librería | Última versión (fecha) | Desc/sem | TS | Estado | Rol |
|---|---|---|---|---|---|
| **jsona** | 1.14.0 (2026-03-14) | 46.278 | Nativos | **Activo**, 0 deps, 3,5 KB gzip | (De)serializa + denormaliza compound docs; server+cliente |
| **yayson** | 4.1.0 (2026-05-27) | 5.529 | Nativos | Activo, 0 deps, 2,6 KB gzip, ESM | (De)serializa + store; alternativa mínima |
| kitsu | 11.1.0 (2025-08-25) | 4.236 | Sin genéricos por recurso | Activo | Cliente todo-en-uno; **impone axios** (~18 KB gzip) |
| ts-japi | 1.12.3 (2026-03-11) | 33.578 | Genéricos 1ª clase | Activo | **Solo serializa (servidor Node)** — no aplica: el productor es Symfony |
| json-api-normalizer | 1.0.4 (2021-03-22) | 20.531 | **NINGUNO** | Estancado | Normaliza estilo Redux — descartado |
| jsonapi-react | 0.0.25 (2022-01-03) | 471 | Parcial | **Abandonado**, sin App Router | Hooks + caché normalizada — descartado |

_Fuentes: https://registry.npmjs.org/jsona · https://registry.npmjs.org/yayson · https://registry.npmjs.org/kitsu · https://registry.npmjs.org/ts-japi · https://registry.npmjs.org/json-api-normalizer · https://github.com/aribouius/jsonapi-react_

- **Patrón recomendado por la comunidad** para React/Next.js + JSON:API: deserializar el documento (jsona o parser propio tipado) y cachear **denormalizado por query key** en **TanStack Query** (5.101.0, ~54,6M desc/sem, soporte de primera clase para RSC/App Router). Los mantenedores de TanStack rechazan caché normalizada en el core ("react-query is not a data fetching library. It's an… async state manager"). [ALTA]
  _Fuente: https://github.com/TanStack/query/discussions/955_ · _Fuente: https://registry.npmjs.org/@tanstack/react-query_
- **Tiempo real (Mercure) sobre caché**: patrón estándar `EventSource` → `queryClient.setQueryData`/`setQueriesData` (merge inmutable por key) o `invalidateQueries` (más simple, un round-trip extra). Para normalización `[type,id]` real (un evento actualiza el recurso en todas las vistas), la opción emergente es **TanStack DB** (`@tanstack/db` 0.6.8, ~283k desc/sem, en desarrollo activo) con `upsert` por id y queries derivadas reactivas. No hay tutorial publicado que combine literalmente Mercure + JSON:API + caché normalizada; el patrón se ensambla por piezas. [MEDIA]
  _Fuente: https://tanstack.com/query/latest/docs/reference/QueryClient_ · _Fuente: https://registry.npmjs.org/@tanstack/db_
  > ⚠️ Matiz a la propuesta original: la "normalización automática incredibly easy" depende de librerías muertas (json-api-normalizer, jsonapi-react); el camino vivo exige ensamblaje propio.

### Tendencias de adopción

- JSON:API es una apuesta **estable, no en crecimiento**: anclada por Drupal core (módulo incluido, zero-config) y Ember Data; el momentum del mercado está en GraphQL (+340% enterprise desde 2023 según fuente secundaria) y REST plano (>50% de APIs externas). Sin señales de declive del spec, pero sin expansión. [MEDIA]
  _Fuente: https://www.drupal.org/docs/core-modules-and-themes/core-modules/jsonapi-module_ · _Fuente: https://nordicapis.com/the-top-8-api-specifications-to-know-in-2025/_ · _Fuente: https://jsonconsole.com/blog/rest-api-vs-graphql-statistics-trends-performance-comparison-2025_
- No se encontraron cifras cuantitativas de adopción de JSON:API 2024-2026 (las encuestas miden REST vs GraphQL); el artículo de Dries Buytaert frecuentemente citado es de 2019 y cualitativo. Uso por Fastly: no verificable. [BAJA]
- **Lagunas de evidencia**: comparativa reciente JSON:API vs OData (sin fuentes); hilo completo del issue #1787 (solo descripción recuperada, podría existir discusión posterior no capturada).

---

## Análisis de patrones de integración

_Tres líneas de investigación paralelas verificadas el 2026-06-06: (a) `include`/`fields` sobre Doctrine ORM 3.6/DBAL 4, (b) reconciliación de contratos de error, (c) tiempo real e interoperabilidad._

### `include` y sparse fieldsets sobre Doctrine sin N+1

**Mecánica base.** Traducir `?include=customer,lineItems.product` a QueryBuilder = `leftJoin` + `addSelect` encadenados por cada segmento del path (el spec exige *full linkage*: cada nivel intermedio necesita su propio `addSelect`). `leftJoin` sin `addSelect` NO hidrata y mantiene el N+1. [ALTA]
_Fuente: https://www.doctrine-project.org/projects/doctrine-orm/en/3.6/reference/dql-doctrine-query-language.html_ · _Fuente: https://jsonapi.org/format/_

**Límite duro de Doctrine: no se pueden fetch-joinear dos colecciones to-many en la misma query** (producto cartesiano, hidratación incorrecta). Patrón documentado: fetch join solo de to-one; las to-many se resuelven con segunda query batch (`WHERE IN`, batch size por defecto 100 vía `setFetchMode`/`setEagerFetchBatchSize`). Más joins ≠ más rápido: el coste de hidratación crece con las filas. [ALTA]
_Fuente: https://tideways.com/profiler/blog/5-doctrine-orm-performance-traps-you-should-avoid_ · _Fuente: https://www.doctrine-project.org/projects/doctrine-orm/en/3.6/reference/working-with-objects.html_

**Lecciones de API Platform (referencia de diseño, no para usar):** su `EagerLoadingExtension` joinea solo relaciones legibles por los serializer groups del contexto (allowlist por proyección); `max_joins: 30` por defecto con excepción al superarlo; las referencias circulares son la causa típica de explosión de joins (mitigación: `max_depth` del Serializer); `fetch_partial` desactivado por defecto con aviso de fragilidad. [ALTA]
_Fuente: https://api-platform.com/docs/core/performance/_ · _Fuente: https://github.com/api-platform/core/issues/1910_

**Sparse fieldsets (`fields[type]`).** `PARTIAL` reapareció en ORM ≥3.3 (no deprecado), pero la doc lo documenta solo para *array hydration*; partial objects sobre entidades gestionadas es frágil (campos no cargados devuelven null silencioso). El patrón robusto: **hidratar entidad completa (o DTO vía `SELECT NEW`) y filtrar campos en la capa de serialización** — `fields` es una preocupación de proyección de salida, no de SQL. [ALTA en los hechos; MEDIA en la recomendación (síntesis)]
_Fuente: https://github.com/doctrine/orm/blob/3.6.x/UPGRADE.md_ · _Fuente: https://www.doctrine-project.org/projects/doctrine-orm/en/3.6/reference/partial-hydration.html_

**Seguridad.** El spec obliga a responder 400 a include paths no soportados (base normativa para allowlist) pero NO impone límite de profundidad — ese control es responsabilidad del servidor. Patrón del spec para relaciones sensibles: exponer paths profundos como relación directa con nombre alternativo. **Ninguna librería PHP standalone trae allowlist semántica lista para usar**: `woohoolabs/yin` parsea `include`/`fields` y valida solo el *naming*; la política (allowlist por tipo, profundidad máxima, denylist, tope de joins, campos filtrables/ordenables con índice) es código propio. [ALTA]
_Fuente: https://jsonapi.org/format/_ · _Fuente: https://github.com/woohoolabs/yin/blob/master/README.md_ · _Fuente: https://www.drupal.org/docs/core-modules-and-themes/core-modules/jsonapi-module/includes_

**Paginación.** `page[...]` + fetch join de colecciones exige el `Paginator` de Doctrine (estrategia de 3 queries con `fetchJoinCollection: true`; 2 si solo hay to-one + `HINT_ENABLE_DISTINCT`). El profile oficial **Cursor Pagination** de JSON:API (`page[size]`/`page[after]`/`page[before]`, "aka keyset pagination") encaja con la regla del repo de evitar OFFSET en tablas grandes; librerías keyset sobre Doctrine: `silarhi/cursor-pagination`, `mention/fast-doctrine-paginator`, `paysera/lib-pagination`. ⚠️ La combinación cursor + include to-many no tiene receta publicada — diseño propio (cursor pagina la colección raíz; includes por WHERE-IN posterior). [ALTA/MEDIA]
_Fuente: https://www.doctrine-project.org/projects/doctrine-orm/en/3.6/tutorials/pagination.html_ · _Fuente: https://jsonapi.org/profiles/ethanresnick/cursor-pagination/_ · _Fuente: https://github.com/silarhi/cursor-pagination_

**Coste real estimado a mano:** include to-one = barato (join+addSelect+allowlist); include to-many = caro y delicado (batch loading + Paginator de 3 queries — es exactamente lo que API Platform encapsula); `fields` = barato si se hace en serialización; seguridad y paginación cursor = ingeniería propia guiada por patrones publicados. [ALTA]

### Reconciliación de contratos de error: RFC 9457 ↔ JSON:API

**Mapeo estructural canónico** (confirmado por el maintainer @jelhan en json-api/json-api#1787): `type↔links.type`, `title↔title`, `detail↔detail`, `instance↔links.about`(+`id`), extension members→`meta` (solo en dirección Problem→JSON:API). **Única incompatibilidad dura: `status` es number en RFC 9457 y string en JSON:API**; alinearlo sería breaking change del spec y el issue sigue abierto. **No existe librería publicada (PHP ni TS) que haga el puente** — mapeo custom. [ALTA]
_Fuente: https://github.com/json-api/json-api/issues/1787_ · _Fuente: https://www.rfc-editor.org/rfc/rfc9457.html_

**Las tres estrategias, contrastadas con evidencia:**

1. **Convivencia por content negotiation (`Accept`)** — avalada explícitamente por DOS editores del spec: Ethan Resnick ("JSON:API totally allows you to do this today; nothing in it prevents users from applying standard HTTP content negotiation", 2016) y Gabe Sullice ("technically the spec doesn't preclude a server from doing exactly what you suggested… In fact, I am doing exactly this in the wild for one of my APIs", 2025). Condición práctica: emitir `application/problem+json` solo si el cliente lo listó en `Accept`. ⚠️ Matiz: la cláusula "alternative media type for errors" citada en 2014 era de un draft pre-1.0 y NO está en el spec publicado — el permiso deriva de "no está prohibido", no de texto normativo. [ALTA]
   _Fuente: https://github.com/json-api/json-api/issues/307_ · _Fuente: https://github.com/json-api/json-api/issues/1787_
2. **Migrar el contrato a JSON:API errors** — es lo que hacen los adoptantes de referencia (Drupal: un error object por violación con `source.pointer` y 422; Ember Data lo exige; Laravel JSON:API convierte excepciones a errores JSON:API según `Accept`). Rompería el pipeline RFC 9457 normativo de ERPify (NFR26 + gate `make php.lint.error-contract`). [ALTA]
   _Fuente: https://www.drupal.org/project/drupal/issues/2832211_ · _Fuente: https://laraveljsonapi.io/2.x/responses/errors.html_
3. **Embedding** — la dirección viable según el comité es la INVERSA a la propuesta original: array `errors[]` JSON:API **dentro** del sobre `application/problem+json` (como extension member de RFC 9457, propuesta de extensión `jsonapi.org/ext/problem-details` esbozada por Gabe Sullice en #1787); hoy es solo conversación en issue abierto, sin extensión publicada. Problem Details dentro de `errors[].meta` no tiene precedentes. [ALTA en el estado; MEDIA en viabilidad]
   _Fuente: https://github.com/json-api/json-api/issues/1787_

**Implementación en Symfony 8:** el mecanismo nativo ya soporta errores por media type — `SerializerErrorRenderer` deriva el formato de `Request::getPreferredFormat()` y se puede registrar un renderer propio (`ErrorRendererInterface` + tag `error_renderer.renderer`) o un normalizer que decore `ProblemNormalizer` para `application/vnd.api+json`. Symfony NO trae renderer JSON:API de fábrica. Precedente de que funciona end-to-end (y de sus dolores): API Platform sirve Problem Details/Hydra/JSON:API errors por content negotiation, con issues documentados de drift de Content-Type entre rutas de error (#5961) — lección: si conviven dos formatos, hace falta un gate de contrato que cubra ambos. [ALTA]
_Fuente: https://github.com/symfony/error-handler/blob/7.2/ErrorRenderer/SerializerErrorRenderer.php_ · _Fuente: https://symfony.com/doc/current/controller/error_pages.html_ · _Fuente: https://api-platform.com/docs/core/errors/_ · _Fuente: https://github.com/api-platform/core/issues/5961_

### Tiempo real: Mercure + JSON:API

- **Mercure es agnóstico al formato del `data`** (recurso completo o parche JSON Patch/Merge Patch), pero su spec recomienda formatos hipermedia (JSON-LD, Atom…) y **no menciona JSON:API**; API Platform publica updates en JSON-LD/Hydra. **No existe patrón de referencia publicado ni adoptante documentado de JSON:API-sobre-Mercure en producción** — sería diseño propio (técnicamente válido). [ALTA en hechos; MEDIA en la inferencia]
  _Fuente: https://mercure.rocks/spec_ · _Fuente: https://api-platform.com/docs/core/mercure/_
- **Topics**: la recomendación de mercure.rocks encaja con ERPify — topic = IRI del recurso (`…/api/banks/{id}`) y suscripción por URI Template (RFC 6570) para colecciones con una sola conexión; patrón de topic alternativo por usuario + claim `mercure.subscribe` para autorización. [ALTA]
  _Fuente: https://mercure.rocks/spec_
- **Fiabilidad**: `Last-Event-ID` está bien especificado (cabecera o query param `lastEventID`), pero el replay exige historial habilitado en el hub; la spec no garantiza orden total ni semántica de entrega exacta — verificar contra el hub concreto. El sobre JSON:API infla el tamaño de los updates (sin estudio cuantitativo publicado). [ALTA/MEDIA]
  _Fuente: https://mercure.rocks/spec_

### Interoperabilidad con terceros y versionado

- **No existe OpenAPI/JSON Schema oficial de JSON:API** (la spec v1.1 prevé el link `describedby` pero no provee el documento); los esquemas disponibles son comunitarios y desactualizados (JSON:API 1.0, JSON Schema draft-06). [ALTA]
  _Fuente: https://apievangelist.com/2024/10/10/a-openapi-and-json-schema-for-jsonapi/_
- **El codegen genérico tropieza con JSON:API**: openapi-generator trata `application/vnd.api+json` como MIME no soportado (placeholder "Custom MIME type example not yet supported"); orval no documenta soporte específico. La envoltura `data/attributes/relationships/included` llega cruda a los clientes generados. ⚠️ Esto erosiona el beneficio de "menos fricción para integradores" de la propuesta original justo donde más se nota (generar SDKs). [ALTA]
  _Fuente: https://discuss.jsonapi.org/t/openapi-generator-and-the-json-api-mime-type/2893_
- **No hay evidencia empírica** (tiempo-a-primera-integración, tickets) de que JSON:API reduzca fricción frente a REST plano + OpenAPI de calidad; las fuentes a favor son argumentativas. El factor dominante hoy es la calidad del OpenAPI + tooling. [MEDIA]
- **Versionado**: JSON:API prohíbe parámetros de media type distintos de `ext`/`profile` → el versionado clásico por media type (`vnd.example.v1+json`) choca con el estándar; los profiles no pueden alterar semánticas (no sirven para breaking changes). En la práctica, **URI versioning** es la vía pragmática para una API JSON:API. [MEDIA]
  _Fuente: https://jsonapi.org/format/_

### Lagunas documentales confirmadas (paso 3)

(a) Sin cifra oficial de máximo de joins (solo el 30 de API Platform); (b) sin librería PHP de allowlist semántica de includes/fields; (c) cursor pagination + compound documents sin receta publicada; (d) sin extensión oficial ni librería puente RFC 9457↔JSON:API errors; (e) sin precedente público nombrado de "JSON:API éxitos + Problem Details errores" (solo testimonio de un editor); (f) sin patrón publicado de JSON:API sobre Mercure; (g) sin datos cuantitativos de fricción para integradores ni de overhead del sobre JSON:API.

---

## Patrones arquitectónicos y diseño

_Tres líneas de investigación paralelas verificadas el 2026-06-06: (a) encaje DDD/hexagonal y adopción incremental, (b) arquitectura de rendimiento y caching HTTP, (c) autorización sobre grafos de recursos._

### Encaje en DDD + Hexagonal

- **No existe un artículo/repo canónico "JSON:API en hexagonal"** — el patrón se compone de literatura CQRS+REST + hexagonal PHP. El consenso trasladable: Domain emite entidades/DTOs limpios → Application devuelve un response-DTO/read-model → un **Presenter/Responder/Serializer JSON:API en Infrastructure** lo transforma al documento. El resource object JSON:API es un DTO de salida del adaptador, nunca la entidad de dominio. En "Explicit Architecture" (Graca) los ViewModels/response-formatters son "delivery-specific concerns" fuera del núcleo. [ALTA en el patrón; MEDIA en su canonicidad]
  _Fuente: https://herbertograca.com/2017/11/16/explicit-architecture-01-ddd-hexagonal-onion-clean-cqrs-how-i-put-it-all-together/_ · _Fuente: https://medium.com/@stefanoalletti_40357/poc-of-clean-architecture-with-symfony-66933548b305_
- **Mapeo de query params sin acoplar el dominio** (patrón compuesto): query params JSON:API → DTO de request con `#[MapQueryString]` + Validator (capa externa, "límite del formato") → **Criteria/Specification de dominio** (Filter/Order/Pagination como value objects neutrales que no conocen JSON:API) → repositorio `searchByCriteria` (interfaz en Domain, impl. Doctrine en Infrastructure). `filter`/`sort`/`page` alimentan el Criteria; **`include`/`fields` se quedan en el adaptador de serialización** (proyección), nunca llegan al dominio. Repo de referencia del Criteria pattern: CodelyTV `php-ddd-example`. [MEDIA — patrón sólido pero ensamblado de varias fuentes]
  _Fuente: https://github.com/CodelyTV/php-ddd-example_ · _Fuente: https://jsonapi.org/format/_

### Diseño de resources: read models, no entidades 1:1

- La guía dominante: resource types diseñados **desde el cliente, respaldados por read models/proyecciones**, no 1:1 con aggregates/ORM. "Tu Domain Model no es tu Application Model"; en CQRS los resources de lectura y los comandos/procesos son recursos desacoplados "como siempre debería ser". Anti-patrón documentado: exponer el modelo de BD en el contrato (relaciones cíclicas, workarounds que contaminan entidades). [ALTA]
  _Fuente: https://www.infoq.com/articles/rest-api-on-cqrs/_ · _Fuente: https://shekhargulati.com/2021/10/15/web-api-design-anti-pattern-exposing-your-database-model/_
- **Drupal es el contraejemplo documentado** (mapeo automático 1:1 entidad→resource, zero-config) y sus críticas internas son el mejor argumento en contra: mayor superficie de ataque (SA-CORE-2019-003 tuvo mayor severidad en JSON:API que en REST), information disclosure del catálogo de entidades en `/jsonapi`, "conflicto directo entre zero-config y defensa en profundidad" (security team), y overhead por recurso expuesto no usado. [ALTA]
  _Fuente: https://www.drupal.org/project/jsonapi/issues/3035979_ · _Fuente: https://api.drupal.org/api/drupal/core!modules!jsonapi!jsonapi.api.php/group/jsonapi_architecture/11.x_

### Adopción incremental: estrategia y precedentes

- Marco: **Strangler Fig con fachada** que enruta por path/header/feature flag; ambos formatos coexisten durante la transición. [ALTA]
  _Fuente: https://learn.microsoft.com/en-us/azure/architecture/patterns/strangler-fig_
- **Vía A — content negotiation (`Accept: application/vnd.api+json`) sobre los mismos endpoints**: más alineada con HTTP, migración selectiva por request; contras: caching por `Vary` (riesgo de poisoning/hit ratio bajo), observabilidad y testeo más difíciles ("no se ve en la URL"). Precedente: GitHub REST (media types vendor por recurso + versión por header). [ALTA]
- **Vía B — prefijo dedicado (`/jsonapi`, `/api/v2`)**: enrutado/observabilidad/caching triviales, paths inmutables; contras: "URL bloat", el purismo REST objeta recursos duplicados. Precedente: **Drupal usa `/jsonapi` en producción a gran escala**. [ALTA]
  _Fuente: https://asoasis.tech/articles/2026-04-21-0254-rest-api-versioning-url-vs-header/_ · _Fuente: https://developer.github.com/v3/media//_
- ⚠️ **Lagunas importantes**: no existe experience report publicado del coste real de mantener JSON:API + JSON plano en paralelo (se infiere: duplicación de tests/docs/contratos), ni caso nombrado de "piloto JSON:API en un solo bounded context". Lo defendible: piloto acotado tras fachada, cubriendo un resource type **completo con sus relaciones** (no medio recurso) para no crear inconsistencia de contrato. [BAJA en evidencia directa]

### Arquitectura de rendimiento: ¿siguen valiendo los compound documents en 2026?

- ⚠️ **CONFLICTO DE BENCHMARKS (hallazgo central)**: Kevin Dunglas (autor de FrankenPHP, el runtime de ERPify) midió compound documents **4× más lentos** (894 ms vs 214 ms) que datos atómicos multiplexados — aunque su test usaba Server Push, hoy retirado de Chrome. Evert Pot midió lo contrario a escala: con 500 entidades el compound es ~3,3× más rápido en Chrome. Ambos coinciden en que para colecciones pequeñas el multiplexing HTTP/2 iguala o gana. **La afirmación de la propuesta original ("reduce drásticamente la latencia") no se sostiene como verdad general**: solo a escala de cientos de entidades, con margen modesto. Mark Nottingham: "HTTP/2 debería enterrar cualquier noción de necesitar minimizar el número de requests en las APIs". [MEDIA por el conflicto]
  _Fuente: https://github.com/dunglas/api-parallelism-benchmark_ · _Fuente: https://evertpot.com/h2-parallelism/_ · _Fuente: https://www.mnot.net/blog/2019/10/13/h2_api_multiplexing_
- **El argumento que sí sobrevive: waterfalls de datos dependientes** (no paralelos) — problema de aplicación, no de protocolo. Pero en ERPify ese waterfall se puede resolver en el **BFF/RSC de Next.js** (fetch agregado en servidor, red interna pwa→php de baja latencia, `Promise.all`/Suspense), sin pagar el sobre JSON:API ni el coste de servidor de los includes. Las guías 2025-26 favorecen RSC/route handlers como BFF para una PWA propia de un solo equipo. [ALTA en patrones; MEDIA en la aplicación al stack]
  _Fuente: https://nordicapis.com/does-http-multiplexing-make-graphql-obsolete/_ · _Fuente: https://www.epicreact.dev/server-waterfall-problem-rscs_ · _Fuente: https://dailydevpost.com/blog/backend-for-frontend-rsc-guide_
- **Caching HTTP**: la combinatoria `include`×`fields`×`sort`×`page` fragmenta la caché (entrada por variante); mitigaciones: normalización de params (`std.querysort()` en Varnish), allowlist de combinaciones, y cache tags por entidad incluida (modelo Drupal `CacheableJsonResponse`; gotcha: límite de 16 KB de cabecera de tags en Fastly; bug histórico de Page Cache MISS). En un compound document, un cambio en cualquier entidad incluida invalida el documento entero — la "eficiencia de caché atómica" favorece recursos pequeños separados. [ALTA]
  _Fuente: https://www.drupal.org/docs/8/api/cache-api/cache-api_ · _Fuente: https://www.drupal.org/docs/drupal-apis/cache-api/cache-tags-varnish_
- **Payload**: sin medición publicada del overhead del sobre JSON:API (laguna); cualitativamente "verboso/overkill para APIs simples", pero brotli/gzip comprimen muy bien sus claves repetidas, y la **deduplicación de `included` es una ventaja real** frente a JSON anidado naíf (la spec prohíbe duplicar `(type,id)`). [MEDIA]
  _Fuente: https://jsonapi.org/format/_
- **Datos de producción (Drupal)**: includes de 5-6 niveles con ~90-100 entidades → ~1,5 s incluso en instalación optimizada de 2-2,5M req/día; el patrón N+1 en includes profundos está diagnosticado; el módulo de hardening impone **profundidad máxima de `include` = 2 por defecto** (cifra operativa citable). [ALTA]
  _Fuente: https://www.drupal.org/project/jsonapi/issues/3060620_ · _Fuente: https://www.drupal.org/project/jsonapi_security_

### Arquitectura de autorización (ERP multi-rol)

- **La spec no prescribe cómo autorizar recursos incluidos** (laguna del estándar, debatida en el foro oficial). El patrón robusto es el de Drupal: **access check por CADA entidad incluida**, omitiendo las no autorizadas con `meta.omitted` (evita 403 que confirman existencia; trade-off: los links de `meta.omitted` revelan existencia). El patrón frágil es el de laravel-json-api por defecto: la policy de relación protege el endpoint de relación pero **no** el eager-load vía `include` — riesgo BOLA directo. [ALTA]
  _Fuente: https://www.drupal.org/node/2994489_ · _Fuente: https://laraveljsonapi.io/3.x/requests/authorization.html_
- **Vector real "filtro como oráculo"**: Drupal tuvo el advisory **SA-CONTRIB-2018-081** (access bypass en colecciones filtradas); la corrección introdujo hooks de field-filter-access — filtrar por `filter[customer.creditLimit]` revela información aunque el campo no se serialice. Control necesario: bloquear filtros sobre campos/relaciones que el rol no puede leer. [ALTA]
  _Fuente: https://www.drupal.org/sa-contrib-2018-081_
- **OWASP API Top 10 2023**: includes = superficie **BOLA** (API1: autorizar "en cada función que use input del cliente para acceder a un registro"); sparse fieldsets/atributos = superficie **BOPLA** (API3: allowlist de propiedades por rol, intersección con `fields[type]` — el cliente nunca amplía visibilidad pidiendo el campo). Cubrir BOLA no cubre BOPLA. OWASP no menciona JSON:API por nombre (mapeo conceptual). [ALTA]
  _Fuente: https://owasp.org/API-Security/editions/2023/en/0xa1-broken-object-level-authorization/_ · _Fuente: https://owasp.org/API-Security/editions/2023/en/0xa3-broken-object-property-level-authorization/_
- **Symfony**: cada `isGranted()` invoca todos los voters — la doc oficial advierte del coste en colecciones (N×voters). Patrón recomendado del ecosistema: **pre-filtrar en la query Doctrine** (repositorio/extension por rol/tenant — "no puedes usar Voters en el GET de colección, usa filtros de colección"), voters con subject solo para el recurso individual y cada include, y `CacheableVoterInterface` para reducir el factor constante. [ALTA]
  _Fuente: https://symfony.com/doc/current/security/voters.html_ · _Fuente: https://api-platform.com/docs/v4.0/symfony/security/_

### Lagunas documentales confirmadas (paso 4)

(a) Sin patrón canónico publicado de JSON:API en hexagonal (se compone); (b) sin experience report del coste de mantener dos formatos en paralelo; (c) sin caso nombrado de piloto por bounded context; (d) sin medición del overhead del sobre JSON:API; (e) cifra "130k queries SQL" de Drupal no verificada en fuente primaria; (f) sin benchmark FrankenPHP+Symfony de includes (datos de servidor provienen de Drupal, transferibles con cautela); (g) la spec no prescribe autorización de includes; (h) OWASP no nombra el vector "filter-as-oracle" (mapeo vía BOPLA + advisory Drupal).

---

## Enfoques de implementación y adopción

_Dos líneas de investigación paralelas verificadas el 2026-06-06: (a) la alternativa "solo convenciones de query params" con precedentes industriales, (b) testing/conformidad y lecciones de adopción reales._

### Las tres rutas de adopción identificadas

1. **JSON:API completo** (envelope + media type + query params) — lo que hacen Drupal/Ember; máxima estandarización, máximo coste (todo lo documentado en pasos 2-4).
2. **Subset disciplinado ("lightweight JSON:API")** — precedente publicado: **Worldline Tech (oct 2024)** adoptó deliberadamente solo `data` wrapping, errores básicos y resource identifiers, **rechazando** relationships formales, `included` y features opcionales. Razones textuales: "a transparent code base, no big learning curve, easy onboarding" y "keep stuff in the presentation layer, no leaking into services or business objects". Lección: "pragmatism over purity". [ALTA]
   _Fuente: https://blog.worldline.tech/2024/10/29/lightweight-json-api-for-internal-rest-apis.html_
3. **Solo convenciones de query params sobre JSON plano** — sin envelope ni `application/vnd.api+json`. Hallazgo estructural clave: **`filter[...]` y `page[...]` están sin definir en la propia spec** (familias reservadas, semántica agnóstica), así que esa implementación la haces igual con o sin envelope; `sort` (coma + prefijo `-`) es trivialmente reutilizable. Lo único que el envelope "ahorra" es decidir el shape de la respuesta — su valor declarado es **anti-bikeshedding multi-cliente**, que se diluye con un único frontend propio. [ALTA]
   _Fuente: https://jsonapi.org/format/_ · _Fuente: https://medium.com/apis-you-wont-hate/making-the-most-of-json-api-7fb51f4407aa_

### Precedentes industriales de la ruta 3 (convenciones sin JSON:API)

- **Stripe `expand[]`** — el precedente de referencia para expansión de relaciones sobre JSON plano: `expand[]=payment_intent.payment_method`, anidación con punto, **profundidad máxima 4 niveles** (límite duro documentado), solo propiedades marcadas "Expandible" (allowlist), y aviso de rendimiento en listas. Es funcionalmente el `include` de JSON:API sin el sobre. [ALTA]
  _Fuente: https://docs.stripe.com/expand_
- **GitHub REST**: `per_page`/`page` + `Link` headers, `sort`+`direction` por endpoint, JSON plano. **OData/Microsoft**: `$filter`/`$orderby`/`$top`/`$skip`/`$select` (con aviso contra paginación dirigida por cliente). **Shopify**: resolvió el mismo problema con GraphQL (no es precedente REST). [ALTA]
  _Fuente: https://docs.github.com/en/rest/search/search_ · _Fuente: https://learn.microsoft.com/en-us/aspnet/web-api/overview/odata-support-in-aspnet-web-api/supporting-odata-query-options_
- **Base de convención documentada para no inventar sintaxis**: Google AIP (AIP-132 `order_by` coma+` desc`, AIP-158 `page_size`+`page_token` opaco server-driven, AIP-157/161 field masks con default `*`) y Zalando RESTful Guidelines (Rule 137 `sort` con `+`/`-`, Rule 157 `fields` con paréntesis anidados, **Rule 160: preferir cursor opaco, evitar offset**). Las tres guías convergen en lo esencial; anclar la convención propia a AIP/Zalando elimina el riesgo de "reinventar mal" y aporta linters (Google `api-linter`). [ALTA]
  _Fuente: https://google.aip.dev/132_ · _Fuente: https://opensource.zalando.com/restful-api-guidelines/_
- **Tooling Symfony vivo para esta ruta**: `babdev/pagerfanta-bundle` v4.6 (declara Symfony `^8.0`, PHP `^8.1`, Doctrine ORM 3); `spiriitlabs/form-filter-bundle` (ex-Lexik, filtros declarativos QueryBuilder); `knplabs/knp-paginator-bundle` (`>=6.4`, Symfony 8 no declarado explícitamente). ⚠️ No existe librería PHP consolidada de `expand`/`include` sobre JSON plano — implementación propia acotada (allowlist + profundidad, estilo Stripe). [ALTA/MEDIA]
  _Fuente: https://packagist.org/packages/babdev/pagerfanta-bundle_ · _Fuente: https://github.com/SpiriitLabs/form-filter-bundle_

### Testing, conformidad y gates de contrato

- **El JSON Schema oficial de jsonapi.org es deliberadamente laxo** ("could yield false positives for the sake of flexibility") — insuficiente como gate estricto por sí solo; sin versión confirmada específica de v1.1 ni draft moderno. [ALTA]
  _Fuente: https://jsonapi.org/faq/_
- **PHP**: la única librería de asserts JSON:API framework-agnostic viva es `cloudcreativity/json-api-testing` (v6.4.0, abr-2026, PHPUnit ^10.5–^13, arrastra `illuminate/*` solo como utilidades; ~1,7M instalaciones). El resto del ecosistema de testing JSON:API está acoplado a Laravel. Para gates de contrato: `league/openapi-psr7-validator` (v0.24, may-2026, The PHP League, activo) valida respuestas PSR-7 contra OpenAPI. [ALTA]
  _Fuente: https://packagist.org/packages/cloudcreativity/json-api-testing_ · _Fuente: https://github.com/thephpleague/openapi-psr7-validator_
- **TS/Vitest**: no hay validador JSON:API maduro con types — la práctica real es **AJV + schema oficial como fixture**. [ALTA]
  _Fuente: https://github.com/ajv-validator/ajv_
- **Contract testing**: sin ejemplos documentados de Pact + JSON:API; el patrón probado del ecosistema es vía OpenAPI: **Spectral** (lint del spec) + **oasdiff** (diff de breaking changes en PR) + **Schemathesis** (property-based contra la API viva; GitHub Action `schemathesis/action@v2` con JUnit XML) — análogo directo al gate `php.lint.error-contract` que ERPify ya tiene. ⚠️ Dredd está archivado. ⚠️ OpenAPI modela mal la recursividad del sobre JSON:API (specs verbosos o incompletos) — punto extra a favor de JSON plano si el contrato OpenAPI es el gate. [ALTA/MEDIA]
  _Fuente: https://github.com/schemathesis/schemathesis_ · _Fuente: https://totalshiftleft.ai/blog/api-schema-validation-catching-drift_

### Lecciones de adopción documentadas

- **Crítica de mayor sustancia** (Jeremy W. Sherman, uso real con Ember + jsonapi-resources): JSON:API es "flexible to a fault" — la abundancia de MAY/SHOULD obliga a descubrir las decisiones de cada implementación; sparse fieldsets/filtering quedan "in the flexibility bin"; sirve más al **productor** que al **consumidor**. [ALTA]
  _Fuente: https://jeremywsherman.com/blog/2016/07/23/why-im-meh-about-json-api/_
- **Onboarding**: Worldline citó "no big learning curve, easy onboarding" como razón para NO implementar el spec completo. No hay retrospectivas con métricas cuantitativas (laguna). [ALTA/MEDIA]
- **Señales 2024-2026**: Drupal sigue comprometido (módulo core estable, "strict compliance"); EmberData se rebautizó **WarpDrive** (framework-agnostic) y mantiene JSON:API como formato de caché de primera clase aunque avanza hacia agnosticismo de formato; proliferan specs "simples" alternativas (señal de fricción persistente). **Ni migraciones notables HACIA JSON:API fuera de su ecosistema, ni abandonos de alto perfil documentados** — estable-maduro sin crecimiento. [ALTA/MEDIA]
  _Fuente: https://mainmatter.com/blog/2026/04/30/from-ember-data-to-warp-drive-1/_ · _Fuente: https://www.drupal.org/docs/core-modules-and-themes/core-modules/jsonapi-module_

## Recomendaciones de la investigación técnica

### Matriz de decisión (evidencia acumulada de pasos 2-5)

| Criterio | JSON:API completo | Subset Worldline | Solo convenciones (ancladas a AIP/Zalando) |
|---|---|---|---|
| Ecosistema PHP sin API Platform | ❌ Muerto o en pausa; sin bundle Symfony 8 | ⚠️ Encoder fork vivo para la parte usada | ✅ Pagerfanta/Spiriit vivos + código propio acotado |
| Contrato de errores NFR26 | ❌ Migrar o convivencia con drift-risk | ⚠️ Igual que completo si se adopta `errors` | ✅ Intacto (RFC 9457 se mantiene) |
| Cliente Next.js/RSC | ⚠️ Sin cliente canónico; jsona+TanStack ensamblado | ⚠️ Ídem | ✅ JSON plano + TanStack Query (patrón dominante) |
| Rendimiento (HTTP/2/3, FrankenPHP) | ⚠️ Compound docs sin ventaja general; coste servidor N+1 | ⚠️ Menor si se omite `included` | ✅ RSC/BFF agrega en red interna; recursos atómicos cacheables |
| Seguridad (BOLA/BOPLA, filter-oracle) | ❌ Máxima superficie (include+fields+filter dinámicos) | ⚠️ Reducida | ⚠️ Reducida (expand propio con allowlist+profundidad) |
| Interop terceros futuros | ⚠️ Codegen roto con vnd.api+json; sin OpenAPI oficial | ⚠️ No conforme (ni estándar ni plano) | ✅ OpenAPI de calidad = factor dominante hoy |
| Coste de implementación | ❌ Alto (todo manual: parser, allowlists, paginator, errores, cliente) | Medio | ✅ Bajo-medio e incremental |
| Estandarización/consistencia interna | ✅ Máxima | ⚠️ Parcial (riesgo "half JSON:API" sin nombre) | ✅ Si se documenta y se lintea como convención del repo |

### Recomendación principal

> **Estado 2026-06-12:** puntos 3 y 5 implementados; 1 y 6 superados por decisiones de diseño propias (vocabulario `filters[]`/keyset en ADRs; consumo hexagonal en la PWA); 4 y 7 parciales; 2 pendiente de decisión junto con `fields[]`. Detalle en **Estado de implementación (actualización 2026-06-12)**.

**No adoptar el document format completo de JSON:API en ERPify.** La evidencia: ecosistema PHP server-side muerto/en pausa sin API Platform, conflicto frontal con el contrato RFC 9457 (NFR26), ausencia de cliente TS canónico para App Router, beneficio de latencia refutado como verdad general con HTTP/2/3 (incl. benchmark del propio autor de FrankenPHP), superficie BOLA/BOPLA significativa para un ERP multi-rol, y codegen/OpenAPI degradados — mientras su beneficio central (anti-bikeshedding multi-cliente) no aplica a un único frontend propio.

**Sí adoptar, como convención documentada del repo (estilo "ERPify API Query Conventions")**:
1. **Query params con sintaxis JSON:API** (`sort` con `-`, `filter[...]`, `page[...]`, `fields[...]`) anclando la semántica no definida a AIP-132/158 y Zalando Rule 137/160 (cursor opaco server-driven, no offset — alineado con la regla existente del repo).
2. **Expansión de relaciones estilo Stripe** (`include`/`expand` sobre JSON plano): allowlist por recurso, profundidad máxima 2 (cifra operativa de Drupal hardening; Stripe usa 4), solo to-one + to-many acotadas con batch loading `WHERE IN`, gate de joins.
3. **Mapeo a Criteria/Specification** en Application (DTO `#[MapQueryString]` → Criteria de dominio; `fields`/`include` resueltos en el adaptador de serialización en `Infrastructure/`).
4. **Pre-filtrado de colecciones en Doctrine** por rol/tenant + voters con subject para recurso individual y cada recurso expandido + field-level access por serializer groups; bloquear filtros sobre campos no legibles por el rol (lección SA-CONTRIB-2018-081).
5. **Errores: mantener RFC 9457 intacto** (NFR26). Si en el futuro un cliente JSON:API estricto lo exige, la convivencia por `Accept` está avalada por dos editores del spec y es implementable con `ErrorRendererInterface` — decisión diferible sin coste presente.
6. **Cliente PWA**: TanStack Query con caché por query key + `prefetchQuery` en RSC; Mercure → `setQueriesData`/`invalidateQueries`; evaluar TanStack DB solo si la sincronización `[type,id]` entre vistas se vuelve requisito duro.
7. **Gates**: documentar la convención y protegerla con lint de contrato (Spectral sobre el OpenAPI + oasdiff en PR + opcionalmente Schemathesis), siguiendo el patrón del gate `php.lint.error-contract` existente.

### Condiciones que reabrirían la decisión (triggers de re-evaluación)

- Aparición de **consumidores externos múltiples** que pidan un estándar de facto (re-evaluar JSON:API completo o GraphQL).
- Necesidad real de **operaciones batch transaccionales** (la extensión Atomic Operations es el único mecanismo estandarizado encontrado; la alternativa es un endpoint de comando propio).
- Madurez futura del ecosistema: un bundle Symfony 8 JSON:API mantenido o la publicación de la extensión oficial `problem-details` (issue #1787).

### Requisitos de habilidades y esfuerzo

- Sin curva nueva significativa para la ruta recomendada: Symfony Validator/`#[MapQueryString]`, Criteria pattern (referencia CodelyTV), Pagerfanta/cursor, TanStack Query — todo dentro del stack actual del equipo.
- El esfuerzo se concentra en: diseño de la convención (documento + ejemplos), el parser/validador de query params con allowlists (código propio, sin librería disponible — laguna confirmada), y el módulo de expansión con su autorización por recurso expandido.

### Métricas de éxito sugeridas

- **Adopción**: % de endpoints de colección migrados a la convención (objetivo: 100% de los nuevos, migración oportunista de los existentes).
- **Rendimiento**: P95 de endpoints con `expand` ≤ presupuesto actual de los endpoints equivalentes sin expand + N+1 detectado en CI (asserts de query count en tests de integración).
- **Contrato**: 0 violaciones del lint de convención en main; oasdiff sin breaking changes no versionados.
- **Seguridad**: tests de autorización por recurso expandido y por filtro (BOLA/BOPLA) en la suite — patrón "write tests to evaluate the authorization mechanism" de OWASP API1.

---

# Síntesis de la investigación

## Adoptar el estándar sin pagar el sobre: JSON:API para ERPify

### Resumen ejecutivo

Esta investigación evaluó la adopción del estándar JSON:API v1.1 en ERPify (API Symfony 8 hexagonal **sin API Platform** + PWA Next.js 16) mediante 11 líneas de investigación web paralelas con verificación de fuentes primarias. La conclusión es asimétrica: **el valor de JSON:API para ERPify está en sus convenciones de query params, no en su document format.**

El envelope completo (`data/attributes/relationships/included` + `application/vnd.api+json`) presenta **cinco bloqueos verificados**:

1. **Ecosistema PHP server-side muerto o en pausa sin API Platform** — no existe bundle Symfony 8 vivo; ninguna librería del ecosistema declara o testea PHP 8.4/8.5; el único framework activo (`tobyz/json-api-server`, RC ene-2026) impone su modelo schema-driven sustituyendo los controladores puros del proyecto.
2. **Conflicto frontal con el contrato de errores RFC 9457 normativo (NFR26)** — formatos estructuralmente incompatibles (`status` string vs number, array vs objeto único), sin extensión oficial ni librería puente publicada; el issue json-api/json-api#1787 sigue abierto sin decisión.
3. **Ausencia de cliente TypeScript canónico para App Router/RSC** — los clientes "completos" están abandonados (jsonapi-react, coloquent) o sin tipos (json-api-normalizer); el camino vivo es ensamblaje propio (jsona + TanStack Query).
4. **Beneficio de latencia refutado como verdad general con HTTP/2/3** — incluido el benchmark del propio autor de FrankenPHP (compound documents 4× más lentos que recursos atómicos multiplexados); el waterfall de datos dependientes se resuelve igual con RSC/BFF en la red interna pwa→php; el coste real documentado es de servidor (N+1, ~1,5 s en includes profundos en producción Drupal).
5. **Superficie de seguridad BOLA/BOPLA significativa para un ERP multi-rol** — la spec no prescribe autorización de includes; precedente de advisory real de filter-as-oracle (SA-CONTRIB-2018-081); el coste de hacerlo bien (access check por recurso incluido, field-filter-access, pre-filtrado en query) es íntegramente código propio.

Y **tres habilitadores** que matizan el cierre de la puerta:

- La **convivencia de errores por content negotiation** (`Accept`) está avalada explícitamente por dos editores del spec y es implementable en Symfony con `ErrorRendererInterface` — decisión diferible sin coste presente.
- **Atomic Operations** es el único mecanismo batch transaccional estandarizado encontrado (la Bulk Extension de la propuesta original está deprecada).
- Las **convenciones de consulta son separables del envelope**: la propia spec deja `filter[...]` y `page[...]` semánticamente abiertos, así que esa parte se implementa igual con o sin document format.

Los precedentes industriales (Stripe `expand[]` con profundidad máxima 4 y allowlist; Google AIP-132/157/158; Zalando Rules 137/157/160) y el experience report de Worldline (2024, subset deliberado, "pragmatism over purity") validan la **vía recomendada**: una convención documentada del repo con sintaxis JSON:API y semántica anclada a guías publicadas, expansión de relaciones estilo Stripe con profundidad 2, Criteria pattern hacia el dominio, RFC 9457 intacto, TanStack Query en la PWA y gate de contrato en CI.

### Tabla de contenidos del informe

0. Estado de implementación (actualización 2026-06-12) — recomendación punto a punto vs. `main`; contrato de consulta shippeado; decisiones pendientes (`fields[]`, `include`/`expand`)
1. Confirmación del alcance de la investigación técnica
2. Análisis del stack tecnológico — estado del estándar; librerías servidor PHP; cliente JS/TS; tendencias de adopción
3. Análisis de patrones de integración — `include`/sparse fieldsets sobre Doctrine; reconciliación RFC 9457 ↔ JSON:API; Mercure; interoperabilidad y versionado
4. Patrones arquitectónicos y diseño — encaje DDD/hexagonal; diseño de resources; adopción incremental; rendimiento; autorización
5. Enfoques de implementación y adopción — las tres rutas; precedentes industriales; testing y gates; lecciones de adopción
6. Recomendaciones de la investigación técnica — matriz de decisión; recomendación en 7 puntos; triggers de re-evaluación; métricas
7. Síntesis de la investigación (esta sección) — resumen ejecutivo; metodología; riesgos consolidados; perspectiva futura; fuentes; conclusión

### Introducción y metodología

**Significancia.** La propuesta original planteaba JSON:API como solución a problemas reales de un ERP (N+1 de red, over-fetching, normalización de estado, errores estandarizados, interoperabilidad). Esta investigación somete cada beneficio declarado a verificación contra fuentes actuales — y varios resultaron desactualizados (Bulk Extension deprecada, API Platform inexistente en el proyecto, normalización cliente dependiente de librerías muertas, ventaja de latencia erosionada por HTTP/2/3) — mientras que otros se confirmaron con matices (deduplicación de `included`, valor de las convenciones, Atomic Operations).

**Metodología.**
- **Alcance:** arquitectura, implementación, stack, integración y rendimiento, según la confirmación del paso 1; restricción dura: sin API Platform.
- **Fuentes:** primarias siempre que fue posible — jsonapi.org (spec, FAQ, extensiones, profiles), repos y registros (Packagist, npm, GitHub incl. hilos de issues con posturas de los editores del spec), documentación oficial (Symfony, Doctrine, Mercure, Stripe, Google AIP, Zalando, OWASP, Microsoft), advisories de seguridad (Drupal SA), y experience reports nombrados (Worldline, Sherman, Buytaert).
- **Marco de análisis:** 11 subagentes de investigación paralelos en 4 tandas temáticas; validación multi-fuente para afirmaciones críticas; **niveles de confianza [ALTA]/[MEDIA]/[BAJA] por dato**; conflictos entre fuentes señalados explícitamente (no resueltos en silencio); lagunas documentales catalogadas como hallazgo.
- **Vigencia:** datos verificados el 2026-06-06 (descargas npm de la semana 2026-05-27→06-02; releases y commits comprobados a fecha de corte).

**Objetivos cumplidos frente a los planteados:** opciones server-side sin API Platform ✅ (tabla comparativa con estado real de mantenimiento); include/sparse sobre Doctrine sin N+1 ✅ (mecánica, límites y coste real); cliente de normalización con Mercure ✅ (patrón vivo + opción emergente TanStack DB); estrategia de errores ✅ (tres vías con evidencia de los editores del spec); incremental vs big-bang ✅ (Strangler Fig, dos vías de convivencia, lagunas declaradas); comparativa "solo convenciones" ✅ (precedentes y tooling). Hallazgos no previstos: el conflicto de benchmarks Dunglas/Pot, el advisory filter-as-oracle, y el experience report de Worldline.

### Evaluación de riesgos consolidada

| Riesgo | Evidencia | Mitigación recomendada |
|---|---|---|
| Bitrot al adoptar el envelope (librerías sin mantener, sin PHP 8.5 testeado) | Packagist verificado: todos los candidatos muertos, en pausa o acoplados a Laravel | No adoptar el envelope; si se usa encoding, solo el fork `laravel-json-api/neomerx-json-api` |
| Drift del contrato de errores al convivir dos formatos | API Platform #5961 (drift real de Content-Type entre rutas de error) | Mantener RFC 9457 único; el gate `php.lint.error-contract` sigue cubriendo un solo contrato |
| N+1 / DoS por expansiones profundas | Drupal producción: ~1,5 s con includes de 5-6 niveles; explosión de joins por relaciones circulares | Allowlist por recurso + profundidad máx. 2 + batch loading `WHERE IN` + tope de joins + asserts de query count en CI |
| BOLA/BOPLA: includes y filtros como vectores | SA-CONTRIB-2018-081 (filter-as-oracle); laravel-json-api no autoriza includes por defecto; OWASP API1/API3 | Pre-filtrado en query Doctrine por rol/tenant; voter con subject por recurso expandido; bloquear filtros sobre campos no legibles; tests de autorización dedicados |
| Fragmentación de caché HTTP por variantes de query | Docs Varnish (hit ratio destruido sin normalización) | Normalización de params + allowlist de combinaciones; recursos atómicos cacheables por defecto |
| Reinventar mal la sintaxis de consulta | "APIs decaen a inconsistencia sin principios" (lit. de API design) | Anclar la convención a AIP-132/157/158 + Zalando 137/157/160; documento de convención + lint en CI |
| Fricción futura con integradores terceros | openapi-generator roto con `vnd.api+json`; sin OpenAPI oficial de JSON:API | JSON plano + OpenAPI de calidad (factor dominante verificado); Spectral + oasdiff como gates |

### Perspectiva futura

- **Corto plazo (1-2 años):** la spec permanecerá estable — v1.1 desde 2022, milestone v1.2 al 0%, comité en mantenimiento editorial. Sin riesgo de abandono, sin evolución que cambie esta decisión.
- **Medio plazo:** WarpDrive (ex-EmberData) avanza hacia agnosticismo de formato manteniendo JSON:API como caché de primera clase; Drupal sigue comprometido; proliferan specs "simples" alternativas — el nicho es estable, el momentum sigue en GraphQL/REST plano. TanStack DB (en desarrollo activo) es la pieza a vigilar para normalización `[type,id]` con Mercure.
- **Triggers de re-evaluación documentados:** (a) múltiples consumidores externos exigiendo un estándar de facto; (b) necesidad real de batch transaccional (→ Atomic Operations o endpoint de comando propio); (c) aparición de un bundle Symfony 8 JSON:API mantenido o publicación de la extensión oficial `problem-details` (#1787).

### Documentación de fuentes y verificación

**Fuentes primarias por área** (todas las afirmaciones del informe llevan cita inline `_Fuente: URL_` y nivel de confianza):

- **Estándar:** jsonapi.org (format, FAQ, recommendations, extensions, ext/atomic, profiles/cursor-pagination), github.com/json-api/json-api (issues #307, #1787, PR #1437, milestones, commits gh-pages), RFC 9457 (rfc-editor.org).
- **Servidor PHP:** Packagist (8 paquetes verificados con versiones/fechas/constraints), GitHub de cada librería, doc de Symfony (serializer, custom normalizers, error_pages, ErrorRenderer), symfony/error-handler 7.2 (código fuente), doctrine-project.org (DQL, working-with-objects, pagination, partial-hydration, UPGRADE 3.6), api-platform.com (performance, errors, content-negotiation, mercure — solo como referencia de diseño).
- **Cliente JS/TS:** registry.npmjs.org + api.npmjs.org (10 paquetes con descargas semanales), GitHub (TanStack discussions #955, repos de cada librería), tanstack.com (QueryClient).
- **Producción y seguridad:** drupal.org (issues #3035979, #3060620, #2952160, change records meta.omitted, módulos jsonapi_security/mercure, SA-CONTRIB-2018-081, SA-CORE-2023-006), owasp.org (API1/API3 2023), laraveljsonapi.io (authorization).
- **Rendimiento:** github.com/dunglas/api-parallelism-benchmark, evertpot.com/h2-parallelism, mnot.net (multiplexing), Varnish/Wikimedia (normalización), epicreact.dev (server waterfall).
- **Convenciones e industria:** docs.stripe.com/expand, google.aip.dev (132/157/158/161), opensource.zalando.com/restful-api-guidelines, learn.microsoft.com (OData, Strangler Fig, CQRS reads), developer.github.com (media types), blog.worldline.tech (lightweight JSON:API), jeremywsherman.com, herbertograca.com, infoq.com (REST sobre CQRS), CodelyTV php-ddd-example.
- **Testing:** packagist (cloudcreativity/json-api-testing, league/openapi-psr7-validator), github (schemathesis, ajv), jsonapi.org/faq (schema laxo).

**Conflictos entre fuentes señalados (no resueltos en silencio):** (1) benchmarks Dunglas vs Evert Pot sobre compound documents; (2) la cláusula "alternative media type for errors" citada en 2014 que no existe en el spec publicado; (3) Bulk→Atomic como sustitución funcional no declarada 1:1; (4) `status` string vs number sin decisión del comité.

**Limitaciones y lagunas (catálogo completo en cada sección):** sin benchmark FrankenPHP+Symfony de includes; sin experience report del coste de dos formatos en paralelo; sin caso nombrado de piloto por bounded context; sin medición cuantitativa del overhead del sobre; Notion/Linear sin verificar; hilos de issues posiblemente incompletos al fetchear. Ninguna laguna afecta a la dirección de la recomendación; varias afectarían al *detalle* de una implementación y se resolverían con un spike propio (p. ej. benchmark interno de expand vs N requests sobre FrankenPHP).

### Conclusión y próximos pasos

**Síntesis:** ERPify no debe adoptar el document format de JSON:API: sus costes verificados (ecosistema, contrato de errores, cliente, rendimiento de servidor, seguridad) superan a un beneficio central — estandarización multi-cliente — que el proyecto no necesita hoy. Sí debe capturar el valor reutilizable del estándar: sintaxis de consulta probada, anclada a AIP/Zalando, con expansión estilo Stripe, preservando intactos RFC 9457, los controladores puros y la frontera hexagonal. La decisión queda protegida por triggers de re-evaluación explícitos.

**Próximos pasos (actualizados 2026-06-12 — el grueso de la ruta recomendada ya está en `main`):**
1. **Decisión `fields[]` (sparse fieldsets):** diferible sin coste hasta que una vista demuestre presión real de payload; cuando llegue, es un refinamiento de los serializer groups con allowlist por rol (BOPLA: intersección, nunca ampliación) — cabe en un spec de quick-dev, sin spike.
2. **Decisión `include`/`expand`:** candidata a **ADR + spike dedicados** cuando aparezca el primer caso de composición de lectura que el patrón vigente (DQL JOIN a projection DTO por endpoint, `docs/adr/bank-bankaccount-modeling.md`) no cubra bien; prerrequisito de seguridad: capa de autorización por roles (BOLA por recurso expandido). El spike benchmark expand-vs-N-requests sobre FrankenPHP/HTTP-3 sigue siendo el paso previo recomendado (cierra la laguna del conflicto Dunglas/Pot para este stack).
3. **Gate de convención (punto 7, parcial):** evaluar lint de contrato OpenAPI (Spectral + oasdiff) sobre la convención de consulta shippeada, siguiendo el patrón de `make php.lint.error-contract`.

---

**Fecha de finalización de la investigación:** 2026-06-06
**Última actualización:** 2026-06-12 — estado de implementación verificado contra el código de `main` (sin re-verificación web: los datos del 2026-06-06 siguen dentro de vigencia); decisiones pendientes acotadas a `fields[]` e `include`/`expand`
**Verificación de fuentes:** todas las afirmaciones citadas con fuentes vigentes a fecha de corte; niveles de confianza por dato
**Nivel de confianza global:** Alto en la dirección de la recomendación (multi-fuente, primarias); Medio en detalles señalados como [MEDIA]/[BAJA] o laguna

_Este documento sirve como referencia técnica autorizada sobre la adopción de JSON:API en ERPify y como insumo directo para la fase de solutioning (architecture → epics & stories)._
