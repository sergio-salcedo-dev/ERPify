# ADR — Organización de `Shared`: módulos vertical-slice por capacidad + kernel transversal

> **Status:** accepted · **Date:** 2026-06-21 · **Scope:** `api/src/Shared` + `pwa/src/context/shared`.
>
> Contexto temporal: la aplicación **no está en producción**. El cambio es puramente estructural
> (reubicación de ficheros + reescritura de FQCN/imports); sin cambio de comportamiento, sin migración
> de esquema, sin tocar el contrato HTTP.

## Contexto

`Shared` (en ambos deployables) había crecido con **dos patrones de organización conviviendo**:

- **Vertical-slice (módulo-primero):** una capacidad es una carpeta con su propia hexagonal interna
  `{Domain,Application,Infrastructure}` (solo las capas que necesita). Ya lo seguían `Event/`, `Media/`,
  `Monitoring/`, `Storage/` (API) y `access/`, `dev-tools/`, `error/`, `resource/` (PWA).
- **Layer-first (capa-primero):** la capacidad está troceada y dispersa por los buckets globales
  `Shared/{Domain,Application,Infrastructure}`. Lo seguían `Clock`, `Mailer`, `Validation`, `Search`
  (API) y `DateTimeProvider`, `DebugToken`, `Notification`, `Observability`, `RateLimit`, `RealTime`,
  `Search`, `Validation` (PWA), más la anomalía `Shared/Guzzle/Enum` (carpeta sin estratificar).

La inconsistencia es el problema: para entender "qué hace Clock" había que saltar entre `Domain/Clock`
e `Infrastructure/Clock`; la mitad de las capacidades vivían de una forma y la otra mitad de otra.

## Decisiones

**D1 — El patrón es vertical-slice por capacidad.** Toda capacidad cohesiva de `Shared` es una carpeta
propia con su `{Domain,Application,Infrastructure}` interno, materializando solo las capas que necesita
(p. ej. `Monitoring` es infra-only; `Validation` en PWA es infra-only). *Alternativa descartada:*
mantener el layer-first — disgrega cada capacidad por 2-3 árboles y obliga a navegar por capa, no por
intención.

**D2 — Frontera kernel/capacidad (alcance conservador).** No todo lo que vivía en los buckets layer-first
es una capacidad: parte es el **kernel transversal** que todo módulo y contexto importa. El trío
`Shared/{Domain,Application,Infrastructure}` se conserva para esas primitivas fundacionales:
`AggregateRoot`, contratos de entidad (`Identifiable`/`Timestamped`), la taxonomía base/marcadores de
`Exception` (la espina del contrato RFC 9457), `Result`, `Currency`, `NormalizedText`, el VO `Uuid`, y los
helpers de `Http`/`Persistence`/`Serializer`. Regla de Tres: se promueve una capacidad solo cuando es una
sub-capacidad cohesiva, no una primitiva base. *Alternativa descartada:* verticalizar también
`Problem`/`Http`/`Serializer`/`RateLimit` — son el kernel HTTP/error transversal; modularizarlos
fragmentaría el único sitio de mapeo de errores (`ProblemDetailsFactory`) sin ganancia de cohesión.

**D3 — Promovidas en API:** `Clock`, `Mailer`, `Validation`, `Search`. `Search` es el hub (~40 ficheros:
filtros genéricos en `Domain`, DTOs de wire en `Application/Http`, motor keyset + `Keyset/` en
`Infrastructure/Persistence/Doctrine`). `SearchResponder` y `SearchObservabilityListener` salen de las
carpetas Http genéricas a `Search/Infrastructure/Http`, importando ahora explícitamente sus hermanos del
kernel (`PaginationMeta`, `ResourceResponder`) que antes resolvían por mismo-namespace.

**D4 — Borrado de `Shared/Guzzle/Enum/GuzzleContextTypeEnum`.** Era código muerto (cero referencias en
todo el repo) y la única carpeta sin estratificar. *Alternativa descartada:* reubicarlo bajo una capa —
mover código muerto a un sitio más bonito es churn; borrar un placeholder con nombre de vendor mantiene
el árbol honesto (recuperable por git si vuelve a hacer falta).

**D5 — Espejo en PWA.** Las 8 capacidades troceadas se consolidan en
`context/shared/<Cap>/{domain,infrastructure}`, igual que `access`/`error`/`resource`/`dev-tools`. El
kernel se queda: `domain/{types,ProblemDetails,ValueObject}` e `infrastructure/{api,DependencyInjection,HttpClient}`.
Se **preserva el PascalCase** del nombre de capacidad (mínimo churn sobre ~109 sitios de import); unificar
el casing con los módulos en minúscula (`access`) es un retoque cosmético diferido, ortogonal al concepto.

**D6 — Sin registro nuevo de boundaries.** Deptrac no necesita registrar los módulos nuevos: sus
colectores `src/Shared/(.*/)?{Domain,Application,Infrastructure}` auto-pliegan los módulos anidados. El
`deptrac.baseline.yaml` solo renombra las claves FQCN de las deudas reubicadas (no añade deuda nueva); el
gate sigue en verde (`Violations 0`).

## Consecuencias y no-objetivos

- `InvalidSearchCriteria` se queda en el kernel `Domain/Exception/` (es un marcador del contrato de error,
  no interno de Search) — distinto de las excepciones de `Search/Domain/Exception/`.
- Diferido explícitamente: la verticalización agresiva de `Problem`/`Http`/`Serializer`/`RateLimit`, y la
  unificación de casing en PWA. Promover una de estas exige nueva justificación (Regla de Tres).
- El estado-actual estructural vive en [`architecture-api.md`](../architecture-api.md) /
  [`architecture-pwa.md`](../architecture-pwa.md); este ADR solo fija la decisión y sus alternativas.
