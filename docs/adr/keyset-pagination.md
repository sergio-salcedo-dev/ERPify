---
status: 'IMPLEMENTATION LOCKED 2026-06-10 — shipped (epic-1, stories 1.1–1.5, PR1–PR4)'
date: '2026-06-10'
scope: >-
  Rediseño de la paginación a cursor/keyset puro (contrato API limit+cursor,
  prev/next sin números de página, modos LIGHT/DETAILED, cursor HMAC corto con
  fingerprint de query, ordenación estable, índices, "ir a fecha", exportaciones
  async) + reestructuración de AbstractDoctrineRepository y
  AbstractDoctrineSearchRepository de herencia a composición.
relatedDecisions:
  - 'docs/adr/filters-search-criteria.md (filtros genéricos filters[], 2026-06-06 — cerrado, restricciones heredadas)'
---

# ADR — Keyset Pagination & Repository Restructuring

Registro de decisión: rationale e inventario FR/K citado por ID desde los docs vivos
([`architecture-api.md`](../architecture-api.md) y
[`runbooks/cursor-pagination.md`](../runbooks/cursor-pagination.md) describen el sistema shipped).

> **Post-freeze overrides (ciclo D-1, 2026-06-11)** — la verdad de planificación divergió de este
> snapshot congelado en tres puntos, todos implementados: repos-por-composición movidos de PR2 a
> PR3; NFR3 refinado (una columna UNIQUE no exige índice compuesto); collation a alcance de
> columna (AR23, con los refinamientos AR23/AR24). En esos puntos manda el código shipped, no este
> documento.

## Contexto

Base de requisitos: especificación de paginación aportada en sesión (2026-06-10) + análisis de
código de la misma sesión (`Paginator.php` — Sonar `php:S1448`, 21 métodos; jerarquía
`AbstractDoctrineRepository`/`AbstractDoctrineSearchRepository`; `PaginatorCursor` mutable con
acoplamiento temporal), refinado con edge-case sweep y pre-mortem. El `Paginator` era un port de
`chiliz/doctrine-bundle`: se preservan sus garantías (keyset, HMAC, LIGHT/DETAILED), no su forma.

**Librerías evaluadas y descartadas (Packagist, 2026-06-10):** `silarhi/cursor-pagination` (viva,
pero iteración batch server-side: sin cursor serializado para clientes, sin `before`/`after`, sin
HMAC/fingerprint); `paysera/lib-pagination` (ininstalable: property-access ^3–^6 vs Symfony 8);
`mention/fast-doctrine-paginator` (sin release en ~2,5 años). Ninguna cubre cursores direccionales
firmados + fingerprint + versionado + envelope: mismo veredicto que el ADR de filtros — absorber
*ideas* publicadas (profile JSON:API Cursor Pagination, Zalando Rule 160, AIP-158,
use-the-index-luke), nunca código de terceros para el mecanismo central.

**Derogaciones explícitas del ADR de filtros (2026-06-06):**

1. *"Conservar `Paginator` y el contrato de respuesta tal cual"* → este ADR rediseña ambos. Se
   conservan: LIGHT/DETAILED, el seam `FilterApplier`/`SearchFieldMap`/`SortFieldMap` y la
   gramática `filters[]` (input de este diseño, no objeto de cambio).
2. *"Fallo de firma silenciado (no oráculo)"* → 422 `invalid-cursor` explícito. `hash_equals` ya
   elimina el oráculo de timing; el silencio fabrica datos aparentemente correctos (cursor
   corrupto → página 1 sin avisar), peor que la excepción.

## Requisitos

**Bloque A — paginación keyset pura (contrato público):**

- **FR1 — Wire cursor-only**: `limit` + cursores opacos; mueren `page`/`currentPage`/`pageCount`/
  `MAX_PAGE`. Decisión de producto: en un ERP se filtra/busca/ordena; el salto a página arbitraria
  se sustituye por filtros + "ir a fecha".
- **FR2 — Cursor corto firmado y ligado a su query**: payload = valores de las claves de
  ordenación de la fila frontera + dirección + fingerprint
  `hash(tenant + entity + normalizedFilters + sort + direction + limit)` — `limit` incluido;
  `normalizedFilters` canónico **sintáctico** sobre los `Filters` de dominio ya parseados, nunca
  sobre la query string. Slot de tenant constante hasta Fase H. Mismatch → 422 `invalid-cursor`.
  HMAC conservado; sin zlib (~100–150 chars).
- **FR3 — Conteo bajo demanda**: `PaginationMode` LIGHT (default, sin COUNT) / DETAILED se
  conserva; `estimatedTotal` diferido (sin consumidor).
- **FR4 — Ordenación estable**: tie-break por `id` en todo ORDER BY; `SortDirection` enum de punta
  a punta.
- **FR5 — "Ir a fecha"**: el servidor sintetiza una posición de cursor desde un valor de clave de
  ordenación — capacidad inherente del modelo; se diseña el seam, la UI es alcance posterior.
- **FR6 — Envelope nuevo**: `{hasNext, hasPrev, count?, links: {next, prev}}` con shape constante
  (`links.*` siempre presentes, `null` cuando no aplican). Breaking change asumido: el único
  consumidor es la PWA propia.
- **FR7 — Exportaciones async**: el mismo motor alimenta workers vía Messenger (batches por
  cursor, nunca OFFSET) — se diseña el seam; la feature es futura.
- **FR13 — Navegación direccional explícita**: cada página emite dos cursores independientes
  (`links.next` → `?after=`, `links.prev` → `?before=`); el cliente nunca compone
  dirección + posición. El fetch de `before` invierte el ORDER BY en SQL y re-invierte en memoria,
  contenido en el ejecutor.
- **FR14 — Sin garantía de instantánea entre páginas (documentado)**: mutaciones entre peticiones
  alteran el dataset visible. Garantía que sí se da: sin duplicados ni saltos *causados por la
  propia paginación* (la anomalía de OFFSET) y unicidad de ids intra-página.
- **FR15 — Versionado del cursor**: payload firmado con `v` (schema version). `v` = versión del
  contrato completo de serialización + canonicalización; bump ⇒ cursores anteriores → 422 ⇒
  reinicio. La compatibilidad de cursores en vuelo es decisión explícita por release, observable
  (422 en métricas vs reinicios fantasma).

**Bloque B — repositorios (herencia → composición):**

- **FR8 — Motor de búsqueda inyectable** (`DoctrineSearchEngine`): aplica siempre sort
  (`SortFieldMap`), limit y filtros; el repositorio solo aporta su query builder base con joins.
  Fuente de verdad única: el engine es el único punto que ve los `Filters` y entrega el mismo
  objeto a `FilterApplier` y al canonicalizador — el fingerprint jamás se computa re-parseando el
  wire.
- **FR9 — Repositorios sin base class de framework**: solo sus puertos de dominio +
  `EntityManagerInterface` inyectado; mueren `ServiceEntityRepository` (30+ métodos públicos fuera
  del puerto), `getEntityClassName()`, `QueryBuilderWithOptions`, `PaginatorOption`.
- **FR10 — Descomposición del `Paginator`**: `Cursor` y `Page<T>` inmutables, `paginate(): Page`
  explícito — elimina el `IteratorAggregate` perezoso que mutaba el cursor al iterar. Resuelve
  Sonar S1448 estructuralmente.
- **FR11 — Código muerto**: helpers sin llamadores eliminados; se preserva el *why* del naming
  estable de parámetros (caché SQL de Doctrine).
- **FR12 — Frontera transaccional → decisión separada, no bloqueante**: dirección preferida =
  flush en Application Service; este ADR solo garantiza no cerrar la puerta (los puertos exponen
  `save()` sin flush implícito obligatorio).

**Non-goals (tan vinculantes como los FRs):** sin normalización **semántica** de filtros (la
equivalencia semántica es incorrecta en el dominio — `amount > 100 ≢ amount >= 101` sobre DECIMAL
— y la asimetría de fallos la condena: falso positivo = 422 barato, falso negativo = datos
incorrectos con apariencia válida; evolucionarla exige ADR nuevo); sin snapshot consistency
(FR14); sin abstracción de página; sin paginación híbrida (el legacy muere en PR4); sin
degradación silenciosa del cursor.

**No funcionales:** HMAC con `hash_equals` + cap de longitud pre-HMAC; NFR26 — las cuatro causas
de invalidez (firma, fingerprint, payload, versión) producen el mismo 422 `invalid-cursor`,
indistinguibles para el cliente (fila de doc + contract test + lint gate); rendimiento — keyset
O(1) por página; sortable ⇒ índice compuesto `(columna, id)` con doble gate: contract test en CI
+ perf gate de staging con perfil uniforme y sesgado (porque `EXPLAIN` sobre tablas diminutas
miente) *(refinado post-D-1: UNIQUE exime del compuesto)*; calidad — colaboradores deterministas
readonly sin estado (criterio de review: *¿este test necesita el kernel?*); pureza — `Page` sin
imports de framework; compatibilidad — cero migraciones, breaking del envelope coordinado con la
PWA en PR3; multiempresa — slot de tenant en fingerprint y posición líder en índices reservados
para Fase H sin segundo rediseño.

## Contrato de extremos keyset (vinculante)

1. Cursor por **valores**, no por referencia: borrar la fila frontera no lo invalida.
2. Empates: el cursor transporta todas las claves del ORDER BY incluida `id`; predicado en cadena
   `col > :v OR (col = :v AND id > :i)` (DQL no soporta tuplas) ⇒ índice compuesto.
3. Envelope de shape constante: `links.*` siempre presentes, `null` cuando no aplican.
4. Página vacía (filtro, fin de dataset o fuera de rango) → 200 `items: []`, nunca error.
5. Descarte client-side de ambos cursores al cambiar `sort`/`direction`/`filters`/`limit` —
   defensa en profundidad sobre el fingerprint.
6. Fingerprint completo: tenant + entity + normalizedFilters + sort + direction + limit.
7. Solo columnas **NOT NULL** son sortables (`NULL > x` es unknown — omitiría filas en silencio).
8. Toda invalidez → 422 `invalid-cursor`, cuatro causas indistinguibles.
9. `after`+`before` simultáneos → 422 `validation-failed` en mapping; cursor sobredimensionado →
   cap en `#[Assert]` antes de tocar HMAC.
10. Valores datetime del cursor serializados a la precisión de la columna (`TIMESTAMP(0)` ⇒
    segundos) — round-trip exacto cursor↔SQL.

## Decisiones

> Lo que estas decisiones construyen no es "paginación": es una **máquina de transiciones de
> navegación validadas criptográficamente sobre una relación ordenada mutable**. La propiedad
> residual — *navigation correctness ≠ dataset stability* — no es un bug; está acotada por FR14 y
> la semántica de affordance de K10.

| #   | Decisión | Elección |
|-----|----------|----------|
| K1  | Modelo | Keyset puro, sin números de página; next/prev + filtros + "ir a fecha" |
| K2  | Navegación wire | `after=`/`before=` mutuamente excluyentes. El param es la **única autoridad semántica**; el `dir` del payload es *integrity binding only* — se compara (discrepancia → 422), jamás se consulta como fallback |
| K3  | Formato de cursor | `base64url(json{v, dir, values, fp})` + `.` + HMAC-SHA256 truncado a 128 bits; sin zlib; cap 512 pre-HMAC |
| K4  | Fingerprint | `xxh128(canonical(QueryExecutionTrace))` — canónico sintáctico derivado de los **recibos** del trace, jamás del input; hash rápido correcto porque la integridad la da el HMAC del conjunto |
| K5  | Invalidez | 4 causas → un solo 422 `invalid-cursor`; `InvalidCursor` en familia `InvalidSearchCriteria` (NFR26 completo) |
| K6  | Envelope | Shape constante; **`null` = "navegación no posible"**, nunca omitido (`string \| null`, no opcional; prohibido `skip_null_values`; contract test del shape) |
| K7  | Puerto de dominio | `Page<T>` readonly reemplaza `PaginatedResult`+`SearchCursor`; cursores como strings opacos nullable (el dominio los trata como ids) |
| K8  | Motor | `DoctrineSearchEngine` + colaboradores puros. **Equivalencia por applied trace, no identidad por referencia**: cada etapa devuelve un recibo inmutable (`AppliedFilters`/`AppliedSort`/`AppliedLimit`) compuesto en un `QueryExecutionTrace` sellado; el fingerprint deriva solo del trace — imposible por flujo de datos fingerprint-ear algo distinto de lo aplicado |
| K9  | Repositorios | Composición pura (puertos + EM inyectado); mueren las bases y los canales laterales |
| K10 | Flags | **Semántica de affordance**: `hasNext`/`hasPrev` afirman disponibilidad de enlace, no existencia de filas (dirección navegada → trick +1; contraria → derivada). Prohibición operativa: los flags NO infieren completitud del dataset — eso es DETAILED o el seam analítico |
| K11 | Límites | `limit` default 25, techo 100. Gate semántico: el techo wire es UX, no analítica — una necesidad de lote grande dispara el seam analítico, nunca sube el techo |
| K12 | Versionado | `v: 1`; bump = decisión explícita por release |
| K13 | Válvula de transición | `dev`/`staging` only (`#[When(env)]`), vida = ventana PR3→PR4, cero tests propios |
| K14 | "Ir a fecha" | Seam server-side sin estado, misma maquinaria K3/K4 |
| K15 | `before` interno | Inversión ORDER BY + re-reverse en memoria, contenida en el ejecutor, testeable pura |

**Doctrine Boundary Contract (amplía K8/K9):** *Doctrine es un motor de ejecución con efectos, no
una función determinista.* Todos los invariantes se imponen **pre-compilación**; el SQL compilado
es un **derivado NO normativo** — verificación de regresión en CI (snapshot de SQL string +
binds + ordering, nunca objetos Doctrine), jamás compatibilidad runtime. El criterio de bump de
`v` es "¿cambió la semántica observable?", nunca "¿cambió el texto SQL?".

**Row Uniqueness Contract (segundo contrato del sistema):** la corrección keyset exige que la
query produzca **cada fila lógica exactamente una vez, en un orden total determinista**. Reglas:
(a) el query builder base NO hace fetch-join de colecciones to-many en el read-path paginado
(to-one sí; to-many → segunda query batch) — guard runtime en el engine al sellar el trace
(`LogicException`, programmer error); (b) orden total = columnas NOT NULL + tie-break `id`;
(c) el engine jamás añade DISTINCT. "Row identity instability under stable trace" es el único
fallo que rompe keyset silenciosamente sin producir error.

**Pipeline del motor (fijo):** resolver sort + tie-break → aplicar filtros → aplicar limit →
**sellar trace** + fingerprint → validar cursor (intrínseco primero: firma → versión → payload →
fingerprint) → construir predicado keyset → ejecutar con trick +1 → construir `Page` + codificar
ambos cursores. Invariantes hasta el predicado; nada posterior forma parte de las garantías. Dos
capas de validación en DAG estricta: shape en mapping (`validation-failed`) → cursor contra el
trace sellado (ningún output de la capa 1 muta post-fingerprint).

**Kernel único + policies**: `KeysetPredicateBuilder` no se comparte "sin contexto" — cada policy
(`WirePaginationPolicy` hoy; `BatchIterationPolicy` cuando FR7 tenga consumidor) entrega su
configuración explícita, congelando el riesgo de *accidental coupling through reuse*.

**Diferidas:** `estimatedTotal`; frontera transaccional (FR12, ADR separado); exportaciones (FR7
deja el seam); analysis-mode navigation (trigger de reapertura: cualquier petición de subir el
techo wire); tenant real (Fase H); UI de "ir a fecha"; normalización semántica (exige ADR nuevo).

## Validación (failure-mode simulation sobre PR3, resumen)

Hallazgos encontrados y cerrados: guard runtime anti to-many fetch-join (la prohibición era solo
documental); `SortFieldMapIndexContractTest` asserta también `nullable: false` vía ClassMetadata;
serialización numérica canónica pineada (strings normalizados post-validación); verificación de
que el `SearchExceptionListener` legacy (priority 32) no intercepta `InvalidCursor` antes que
`ExceptionResponder` (16), pineada a PR3. El **`TraceEquivalenceStabilityTest`** es el test más
importante del sistema: mismo input ⇒ trace canónico byte-a-byte entre ejecuciones y refactors —
el drift en la *construcción* del trace era el único modo de corrupción silenciosa restante.

Riesgos residuales aceptados: disciplina humana del bump de `v` (runbook + review, no eliminable
técnicamente); semántica de affordance dependiente de review (K10); coordinación de ejecución de
PR3 (operativo, no arquitectónico).

## Secuencia ejecutada y freeze

PR1 (kernel puro: VOs + colaboradores + suites, sin contrato) → PR2 (motor + repositorios, wire
intacto) → PR3 (el único flip observable: envelope + PWA + Behat + observabilidad + válvula) →
PR4 (borrado del legado, puramente sustractivo; PR3 revertible sin tocar PR4). Los 29 escenarios
Behat se actualizaron dentro de PR3 — si Behat pasa en PR3, PR4 es solo resta.

**IMPLEMENTATION LOCKED (2026-06-10):** kernel único · trace como única fuente semántica del
cursor · SQL no normativo · envelope affordance-only · policies wire/batch como extensión
controlada. Sin nuevas decisiones estructurales sin reabrir el workflow. *(Overrides post-freeze
del ciclo D-1: ver nota de cabecera.)*
