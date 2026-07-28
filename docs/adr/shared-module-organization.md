# ADR — Organización de `Shared`: módulos vertical-slice por capacidad + kernel transversal

> **Status:** accepted · **Date:** 2026-06-21 · **Scope:** `api/src/Shared` + `pwa/src/context/shared`.
>
> Contexto temporal: la aplicación **no está en producción**. El cambio es puramente estructural
> (reubicación de ficheros + reescritura de FQCN/imports); sin cambio de comportamiento, sin migración
> de esquema, sin tocar el contrato HTTP.

## Contexto

`Shared` (en ambos deployables) había crecido con **dos patrones de organización conviviendo**:

- **Vertical-slice (módulo-primero):** una capacidad es una carpeta con su propia hexagonal interna
  `{Domain,Application,Infrastructure}` (solo las capas que necesita). Ya lo seguían `Event/` y
  `Monitoring/` (API) y `access/`, `dev-tools/`, `error/`, `resource/` (PWA).
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

> **Superseded by** D8 (partial — `Uuid`, the `Exception` taxonomy, and the `Http` error responders; the rest of D2 stands).

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

**D7 — PWA: disolución total del kernel (evolución de D5).** Tras completar el casing en #333, se revisó la
frontera kernel/capacidad de D2 *en el PWA* y se concluyó que ahí no sobrevive kernel transversal alguno: a
diferencia de la API (que sí conserva primitivas fundacionales — `AggregateRoot`, `Uuid`, taxonomía de
`Exception`…), el "kernel" PWA era casi todo capacidad mal ubicada bajo los buckets `domain/types` e
`infrastructure/`. Se disuelven por completo — `context/shared/` pasa a ser **solo capacidades hermanas**, sin
`domain/` ni `infrastructure/` horizontales:

- `ProblemDetails` → `error/domain` (contrato de error del front: vive con su capacidad, no en un bucket suelto).
- `http`/`HttpClient`/`HttpError`/`ApiEndpoints` → `http-client/` (puerto en `domain`, adaptadores en
  `infrastructure`; el fichero agrupado `HttpClient.ts` se parte para seguir el patrón hexagonal del resto).
- `types/{routes,theme,appEnv+nodeEnv,status,sorting,keyboard}` → `routing/`, `theme/`, `environment/`,
  `view-state/`, `search/` (`SortDirection` es vocabulario de búsqueda) y `keyboard/`.
- El contenedor Inversify (única infra-kernel real, raíz de composición) → capacidad infra-only
  `dependency-injection/infrastructure/`, igual que `Monitoring`/`Validation`.
- `ValueObject` se **borra**: cero referencias en todo el PWA (precedente D4 — no se reubica código muerto).

*Alternativa descartada:* dejar un `domain/` slim con las primitivas "sin identidad" (`HttpStatus`,
`SortDirection`, `KeyboardKey`, `ViewState`) — reintroduce el layer-first que D1 eliminó: cada una tiene un
dueño cohesivo. *Asimetría consciente:* la frontera de D2 es por-deployable — la API mantiene su trío kernel,
el PWA no (no tenía primitivas fundacionales propias).

## D8 — Shared kernel verticalization & the error-representation contract

> **Status:** Accepted · **Date:** 2026-06-21 · **Scope:** `api/src/Shared` restructuring (Kernel / ErrorContract / Uuid within the Domain layer) · **Supersedes:** D2 (partial — `Uuid`, the `Exception` taxonomy, and the `Http` error responders only)

### 0. Why this reopens D2

The API is the **authority of failure representation**: it owns a stable, versionable
error-representation contract (RFC 9457-aligned Problem Details) that must stay consistent
across multiple heterogeneous consumers — the PWA, external integrations, future tooling.
The PWA is the **first consumer of that contract, not the reason for it.**

This is why the PWA precedent (D7, which dissolved its shared kernel entirely) does **not**
transfer 1:1: the PWA *consumes* a failure contract, it does not *author* one. D2 is therefore
reopened only for the elements with a cohesive home of their own — `Uuid` (an identity VO with an
independent lifecycle) and the error protocol itself (`Exception` taxonomy + `Problem` + `Http`
responders, a semantic boundary module) — and **not** for the residual building blocks
(`AggregateRoot`, `Result`), which stay in a minimal kernel. The hybrid kernel is justified by this
authority role, never by analogy with the PWA.

### 1. Decision — Shared is layered, not module-structured

`Shared` is partitioned by exactly three deptrac-enforced layers:
`Shared.Domain`, `Shared.Application`, `Shared.Infrastructure`
(collectors `src/Shared/(.*/)?{Domain,Application,Infrastructure}/.*` — nested modules fold in).

Within those layers we keep a **semantic taxonomy that tooling does not represent**:
`Kernel`, `ErrorContract`, `Uuid` (all three live inside `Shared.Domain` for deptrac).

There is no viable structural axis for module-level governance within `Shared` under this
partition: deptrac cannot distinguish semantic modules inside a layer. Consequently **all
sub-layer governance is rule-scoped (test-driven), never graph-scoped.**

### 2. Kernel (closed set)

Residual building blocks with no cohesive owner and no protocol role, kept under their original DDD
sub-categories within the layer:

- **Domain** — `Aggregate/AggregateRoot`, entity contracts `Entity/{Identifiable, Timestamped}`,
  `ValueObject/NormalizedText`, and `Enum/Currency` (a PHP `enum`, low-usage, kept here until a second
  use). **`Currency` is classified as an enum, not a value object** — filing it under `ValueObject/`
  would misrepresent the type, so it lives in `Enum/`.
- **Application** — `Result`.

The `Aggregate/` / `Entity/` / `Enum/` / `ValueObject/` sub-folders are legitimate DDD sub-categories
preserved from the pre-move layout — not flattened. Minimal churn, no semantic loss; the only change is
the `Shared/Kernel/` prefix.

Invariants (convention): Kernel defines no workflow or protocol; no capability depends *solely*
on Kernel; Kernel depends on nothing in `Shared`.

### 3. ErrorContract (semantic boundary module)

The error-representation protocol, spanning three layers:

- **Domain** — `Exception` taxonomy (`DomainException`, `ClientError` + markers); `InvalidSearchCriteria`
  (a protocol marker, **not** Search feature logic).
- **Application** — `ProblemDetailsFactory`, `ProblemDetails`, `RedactionDenylist`, `ProblemBodyTooLargeException`.
- **Infrastructure** — under `Infrastructure/Http/`: `ProblemDetailsResponder` (a plain responder, kept
  flat) and `EventListener/{ExceptionResponder, RateLimitListener}` (Symfony event listeners, grouped
  under `EventListener/` so the tree states what they are); plus the `CorrelationIdListener::ATTRIBUTE_KEY`
  integration (use of the HTTP edge, not ownership). **The listener/responder split is deliberate** —
  only `ProblemDetailsResponder` is a non-listener responder, so flattening all three (as an earlier draft
  proposed) would erase that distinction.

#### 3.1 Dependency rules (by enforcement mechanism)

| Rule | Mechanism |
|---|---|
| `ErrorContract.Domain` carries no Infrastructure / non-allowlisted framework deps | **[deptrac]** |
| `ErrorContract.Application` carries no Infrastructure deps | **[deptrac]** |
| `ErrorContract.Infrastructure` may depend on `Http` infrastructure (the CorrelationId edge) — an intra-layer dependency, not explicitly constrained beyond the layer boundary | **[deptrac: layer-bounded, no edge rule]** |
| No `JsonResponse` catch-and-respond leakage anywhere in `api/src` | **[CI-test]** (`ErrorContractGateTest`, path-independent) |
| New marker under the exception dir requires a `docs/api-error-contract.md` update in the same diff | **[CI-test]** (`ErrorContractGateTest`, path-hardcoded) |
| `ErrorContract.Domain` depends on nothing but PHP + its own types (not even `Kernel`/`Uuid`) | **[convention]** (deptrac allows same-layer + vendor allowlist) |
| `ErrorContract.Application` depends only on its own Domain | **[convention]** |
| `ErrorContract ⊥ Kernel` (module separation) | **[convention]** |

### 4. Uuid module

Self-contained identity VO: `Uuid`, `InvalidUuidException`. Domain-scoped; no dependency on Kernel;
reused across multiple bounded contexts. It is a cross-cutting identity primitive with an independent
lifecycle. **Lives outside Kernel due to that independent lifecycle and cross-context reuse pressure —
not due to its classification as a primitive or a capability.**

### 5. Enforcement ledger

- **Structural integrity — [deptrac], hard.** Enforces only the layer axis
  (`Domain ← Application ← Infrastructure`: Infrastructure depends on Application, Application on Domain).
  Does **not** enforce Kernel / ErrorContract / Uuid separation (all fold into one layer).
- **Protocol integrity — [CI-test], partial.** `ErrorContractGateTest` enforces (a) no `JsonResponse`
  catch-and-respond leakage, and (b) doc-sync on additions under the exception dir. Real, brittle, string/path-based.
- **Semantic integrity — [convention], review-only.** Kernel/ErrorContract/Uuid clustering and module
  separation are design guidance, not CI guarantees.

### 6. Not enforced — no justification

- **E-2 (no exception type outside ErrorContract) is currently not justified as an enforcement
  invariant — there is neither observed violation pressure nor an architectural necessity requiring
  global exclusion; therefore no enforcement exists.** What remains is the capability-boundary rule
  as convention: a `ClientError`/`DomainException` subtype belongs in `ErrorContract/Domain/Exception/`.
  Should **both** signals appear (observed violation pressure *and* a structural need for global
  exclusion — e.g. a second contract module), the instrument is a **targeted contract test** — a
  file-scan for exception subtypes outside the module, allowlisted, same machinery as
  `ErrorContractGateTest` / `BoundedContextGateTest` — **not** a deptrac decomposition.
- **Discarded — per-module deptrac axis.** Splitting `Shared.Domain` into
  `Shared.{Kernel,ErrorContract,Uuid}.Domain` buys no enforcement: deptrac cannot distinguish
  semantic modules within a layer, and recreating the distinction reintroduces a hand-maintained
  dependency graph that grows with every Shared capability — the exact cost the fold exists to avoid.

### 7. Design implication

The architecture is intentionally **hybrid**: structural integrity by deptrac (layer axis),
protocol integrity by targeted CI tests, semantic integrity by convention and review. No single
mechanism governs all three; the ADR's job is to say which mechanism owns which invariant.

### 8. Blast radius (implementation constraints)

When the relocation is applied:

- `ErrorContractGateTest` was updated: the hardcoded exception-dir literal (now
  `src/Shared/ErrorContract/Domain/Exception/`) in its doc-citation sub-check — **only that sub-check
  is path-coupled; the `JsonResponse` scan is path-independent.** A stale path would silently no-op →
  green-but-blind.
- Tests that relocated a level deeper had their `dirname(__DIR__, N)` recomputed — path resolution is depth-coupled.
- Validate CI green *after* the path fix before considering enforcement intact.

**Migration sequencing is guidance, not invariant.** The step-by-step commit plan that drove this move
(promote one module per commit, keep each commit CI-green, co-locate the `docs/api-error-contract.md`
update with the exception move) was *execution guidance to minimise risk*, not part of the target
architecture. The implementation diverged from that exact sequence — modules were promoted in a different
commit grouping and the doc update landed in a separate commit. The final architecture above satisfies all
success criteria and CI gates; the sequencing therefore stays **migration guidance, not an architectural
invariant**, and is not reconstructed retroactively (no history rewrite of a shipped PR).

### 9. Decision summary

We choose **semantic taxonomy over structural over-splitting**, and **rule-scoped over graph-scoped**
enforcement. `Shared` is not decomposed into N×3 deptrac layers because tooling cannot enforce the
second axis without manual graph governance. The error contract is a CI-driven protocol, not a
structurally-enforced boundary — and D8 says so explicitly so the system is never *false-enforced*.

## Consecuencias y no-objetivos

- `InvalidSearchCriteria` es un marcador del contrato de error (no interno de Search) → con **D8** pasa a
  `ErrorContract/Domain/Exception/`, distinto de las excepciones de `Search/Domain/Exception/`.
- La verticalización de `Problem`/`Http`/`Serializer`/`RateLimit` **en la API**, diferida en D2, se ejecuta en
  **D8** (→ `ErrorContract`/`Http`/`Serialization` + kernel reducido); la justificación Regla-de-Tres que D2
  exigía es el precedente PWA (D7). En el PWA ya se ejecutó (D7) y el casing se completó en #333.
- El estado-actual estructural vive en [`architecture-api.md`](../architecture-api.md) /
  [`architecture-pwa.md`](../architecture-pwa.md); este ADR solo fija la decisión y sus alternativas.
