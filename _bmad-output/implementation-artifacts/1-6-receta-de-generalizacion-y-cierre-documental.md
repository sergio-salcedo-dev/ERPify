---
baseline_commit: ef8a8d8f3f29a96d6de79a8cccbd692732d3ef34
---

# Story 1.6: Receta de generalización y cierre documental

Status: done

_Ultimate context engine analysis completed — comprehensive developer guide created (2026-06-07)._

## Story

As a futuro desarrollador (humano o agente) que añade una lista filtrable,
I want una receta canónica documentada y el árbol documental al día,
so that la siguiente entidad use el mecanismo sin modificar `Shared/` (≤ 2 clases + 1 field map — FR7).

## Contexto de ejecución (leer antes de empezar)

- **Dónde se trabaja:** worktree `.claude/worktrees/shared-search-filters-aj0w/`, rama
  `feat/shared-search-filters-aj0w` (limpia, 11 commits sobre `main`, SIN mergear, SIN PR abierta).
  Las historias 1.1–1.5 viven SOLO ahí — `main` no tiene `Doctrine/Search/` ni el contrato `filters[]`.
  Documentar este mecanismo en `main` describiría código inexistente: **todas las ediciones de docs van a la rama**.
  Excepción única: la Task 4 (retirada de `php-criteria-main/`) opera sobre el **checkout principal**
  (`/home/dev/Projects/ERPify`), porque el material de estudio solo existe allí (ignorado por git, nunca trackeado).
- **Historia documental, cero código:** no hay PHP/TS que tocar, no hay tests que escribir, no aplica
  red-green-refactor. El equivalente a "tests" son los greps de verificación de la Task 5 (definidos como
  comandos exactos). No ejecutes `make php.stan`/`php.quality` salvo que acabes tocando un `.php` (no deberías).
- **Esta historia cierra la fase 1 y la épica 1 a nivel de contenido.** Tras ella queda la decisión de PR
  (única para fases 0+1 o troceada) y el merge — ambas son del usuario, no de esta historia
  (regla `Protected main` de CLAUDE.md).

## Acceptance Criteria

1. **Given** `docs/architecture-api.md`
   **When** se documenta el patrón de búsqueda filtrable y la receta "añadir una lista filtrable"
   **Then** incluye el ejemplo canónico de `searchFieldMap()` (código real de `DoctrineBankRepository`,
   incl. `requiresUuidValues`) y los anti-patterns prohibidos (`matching()`/`Collections\Criteria`,
   filtrado ad hoc en repositorios, validación de filtros fuera de la capa pineada, `JsonResponse` manual,
   subclases/wire params per-entity post-pivote)
   **And** es la fuente única del patrón para humanos y agentes (con cross-link coherente a
   `api/docs/adding-endpoints.md`, que conserva el walkthrough del skeleton sin duplicación).

2. **Given** el subdirectorio nuevo `Doctrine/Search/` y la desaparición de `Bank/Domain/Search/` (story 1.5)
   **When** se actualizan `docs/source-tree-analysis.md` y `docs/claude-code-quickref.md`
   **Then** reflejan la estructura real del árbol en la rama: alta visible de
   `Shared/Infrastructure/Persistence/Doctrine/Search/` y CERO menciones de `Search` bajo `Bank/Domain/`
   (regla CLAUDE.md de docs por PR).

3. **Given** `php-criteria-main/` presente en el working tree del checkout principal como material de estudio
   **When** se cierra la decisión del research (opción C ya implementada)
   **Then** se retira del working tree previa confirmación explícita del usuario (regla destructive-delete)
   **And** se resuelve con el usuario la línea `/php-criteria-main/` añadida sin commitear a `.gitignore`
   (recomendación: revertirla — queda muerta tras el borrado).

4. **Given** los flecos documentales registrados por la 1.5 (Project Structure Notes)
   **When** se revisan `docs/deep-dive-api-shared-foundation.md` y el enlace al ADR retirado
   **Then** el deep-dive deja de describir el flujo pre-pivote (5 puntos concretos listados en Task 3 —
   `ids` en `SearchQuery`/`SearchCriteria`, "subclass per entity", diagrama del pipeline sin `FilterApplier`,
   Modification Guidance §3, fila "Build a search endpoint" del Quick Index)
   **And** `api/docs/adding-endpoints.md` deja de enlazar al fichero inexistente
   `_bmad-output/planning-artifacts/adr-2026-04-29-search-controller-boundary.md` (regla Markdown link style:
   solo enlaces a ficheros concretos que resuelven).

## Tasks / Subtasks

- [x] Task 0: Preparación (AC: —)
  - [x] Verificar worktree y rama: `git -C .claude/worktrees/shared-search-filters-aj0w branch --show-current`
        → `feat/shared-search-filters-aj0w`; working tree limpio. NO crear rama nueva desde main.
  - [x] No hace falta stack Docker (historia docs-only).
  - [x] Baseline de referencias obsoletas (desde el worktree):
        `grep -rn -E "BankSearchQuery|BankSearchCriteria|Bank/Domain/Search|adr-2026-04-29" docs/ api/docs/ pwa/docs/`
        — anotar los hits en el Debug Log para contrastar en Task 5.
- [x] Task 1: Patrón + receta canónica en `docs/architecture-api.md` (AC: 1)
  - [x] Añadir sección `## Filterable search (generic filters[] contract)` (o título equivalente coherente con
        el doc, que está en inglés) tras `## API design`. Contenido mínimo:
        flujo end-to-end (query string → `#[MapQueryString] SearchQuery` → `toCriteria()` →
        `AbstractDoctrineSearchRepository` auto-aplica `FilterApplier::apply(qb, filters, searchFieldMap())`
        antes de paginar → `Paginator` intacto); las dos capas de validación pineadas (shape → mapping →
        400 `validation-failed`; semántica → applier → familia 400 `invalid-search-criteria`:
        `unknown-search-field` / `unsupported-search-operator` / `invalid-search-value`); caps
        (`MAX_FILTERS = 20`, `MAX_IN_VALUES = 100`); link a `api-error-contract.md` para el marker.
  - [x] Receta "añadir una lista filtrable" (FR7): pasos numerados — el coste real es ≤ 2 clases nuevas
        (searcher + controller; el repo ya existe si la entidad ya pagina) + 1 `searchFieldMap()`,
        CERO ficheros en `Shared/`. Referenciar `api/docs/adding-endpoints.md#search-endpoints` para el
        skeleton detallado (NO duplicarlo — un solo lugar para el código del controller).
  - [x] Ejemplo canónico REAL de `searchFieldMap()` — copiar de
        `api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/DoctrineBankRepository.php:91-102`
        (en la rama), que incluye normalizador + `operators:` restringidos + `requiresUuidValues: true`
        y el comentario del porqué (no `contains` sobre UUID). No usar el ejemplo del ADD de planificación
        (carece de `requiresUuidValues`, añadido en el review de la 1.4).
  - [x] Bloque de anti-patterns prohibidos (los 6): ❌ `EntityRepository::matching()`/`Collections\Criteria`
        en el read-path; ❌ filtrado ad hoc en repositorios (el applier entra SOLO por el seam);
        ❌ validar filtros en controller o use case; ❌ `JsonResponse` manual para errores de filtro
        (bypass RFC 9457); ❌ subclases de `SearchQuery`/`SearchCriteria` o wire params per-entity
        (ambas clases son `final` a propósito desde la 1.5 — campos nuevos via field map);
        ❌ invocar `FilterApplier` desde un repositorio concreto, controller o use case.
  - [x] Verificar coherencia del árbol "Bounded contexts" del propio doc (no requiere cambio:
        `Shared/Domain/Search` sigue existiendo; Bank se muestra genérico) — confirmar, no asumir.
- [x] Task 2: Árbol documental — `source-tree-analysis.md` + `claude-code-quickref.md` (AC: 2)
  - [x] `docs/source-tree-analysis.md` línea ~13: el comentario de `Bank/Domain/` lista
        `Entity, Event, Exception, Repository, Search` — **eliminar `Search`** (el dir desapareció en 1.5).
  - [x] `docs/source-tree-analysis.md` línea ~37: `Persistence/  # Entity, Paginator, Repository` bajo
        `Shared/Infrastructure/` — añadir mención del subárbol `Doctrine/Search/` (applier + field maps).
        La fila de la tabla "Critical folders" (línea ~143) YA existe — verificar, no duplicar.
  - [x] `docs/claude-code-quickref.md`: la fila de layout (línea ~134, "Shared search-filter plumbing") y el
        link al walkthrough (línea ~174) YA existen — verificar que nada del quickref menciona
        `Bank/Domain/Search`, `BankSearchQuery` ni wire params legacy; corregir si aparece algo.
- [x] Task 3: Flecos 1.5 — deep-dive + enlace roto al ADR (AC: 4)
  - [x] `docs/deep-dive-api-shared-foundation.md` — corregir exactamente estos 5 puntos (alcance quirúrgico;
        NO reescribir el doc ni añadir inventario per-file del mecanismo nuevo — basta un puntero):
        1. Entrada `SearchQuery.php` (líneas ~51-56): constraints ya sin `ids`, ahora con `filters[]`
           (`MAX_FILTERS = 20`); LOC desactualizado — refrescar; la Contributor note "Subclass per entity…"
           es hoy un anti-pattern → sustituir por "final a propósito; campos filtrables via
           `searchFieldMap()` del repositorio — ver receta en `architecture-api.md`".
        2. Entrada `SearchCriteria.php` (líneas ~154-155): constructor ya sin `?ids`, con `filters`
           (default vacío); clase `final`.
        3. Diagrama "Persistence / Paginator Pipeline" (líneas ~404-433): la caja `SearchCriteria` dice
           `← cursor | page | limit | mode | ids` → cambiar `ids` por `filters`; añadir el paso
           `FilterApplier::apply(qb, criteria->filters, searchFieldMap())` dentro de
           `getQueryBuilderPaginatedResults()` (se aplica antes de construir el `Paginator`
           — verificar el punto exacto en `AbstractDoctrineSearchRepository` de la rama antes de dibujarlo).
        4. Modification Guidance §"Adding a new bounded context" paso 3 (línea ~602): "entity-specific
           `SearchQuery` extends … and overrides `toCriteria()`" → mapear la base directamente +
           implementar `searchFieldMap()` (obligatorio, abstracto).
        5. Contributor Quick Index, fila "Build a search endpoint" (línea ~636): quitar "(subclass)" →
           base `SearchQuery` + `searchFieldMap()` + link a `adding-endpoints.md`.
  - [x] `api/docs/adding-endpoints.md` (sección "Search endpoints", primer párrafo): el enlace
        `[…](../../_bmad-output/planning-artifacts/adr-2026-04-29-search-controller-boundary.md)` apunta a un
        fichero eliminado del repo (borrado en `ef483f8`) → el linter de Markdown lo rechaza. Sustituir el
        enlace por código inline (`` `adr-2026-04-29-search-controller-boundary.md` ``, recuperable del
        historial git) conservando la frase sobre el retiro de las subclases per-entity, o reescribir el
        párrafo apuntando a la nueva sección de `architecture-api.md` como fuente del patrón. Ese doc se está
        editando por otra razón → aplica la regla "fix violations you spot".
- [x] Task 4: Retirar `php-criteria-main/` del checkout principal (AC: 3)
  - [x] **PEDIR CONFIRMACIÓN EXPLÍCITA al usuario ANTES de borrar** (regla repo: nunca destructive-delete
        sin confirmación). Es material de estudio descargado, ignorado por git — irrecuperable tras el rm.
  - [x] Tras confirmación: `rm -rf /home/dev/Projects/ERPify/php-criteria-main/` (checkout PRINCIPAL,
        no el worktree — el worktree no lo tiene).
  - [x] `.gitignore` del checkout principal tiene la línea `/php-criteria-main/` añadida SIN commitear
        (working tree del usuario). Tras el borrado queda muerta — proponer al usuario revertirla
        (`git checkout -- .gitignore` solo si .gitignore no tiene otros cambios del usuario — verificar el
        diff completo antes) o conservarla committeada como blindaje si prefiere re-descargar el material.
        Decisión del usuario; default recomendado: revertir.
- [x] Task 5: Verificación, File List y cierre (AC: 1-4)
  - [x] Greps de verificación (desde el worktree; deben devolver CERO hits, salvo menciones históricas
        deliberadas tipo "the old `names[]`/`ids[]` were retired" en `adding-endpoints.md`, que es correcta):
        - `grep -rn "Bank/Domain/Search" docs/ api/docs/`
        - `grep -rn -E "BankSearchQuery|BankSearchCriteria" docs/ api/docs/`
        - `grep -rn "adr-2026-04-29" docs/ api/docs/ | grep -v '`'` (el nombre solo puede sobrevivir como
          código inline, jamás como link)
        - `grep -rn "Subclass per entity" docs/`
  - [x] Verificación de enlaces: todo link `[...](...)` añadido/tocado en los 5 docs editados resuelve a un
        fichero concreto existente (regla Markdown link style; sin hrefs de directorio con slash final,
        sin globs). Comprobar a mano o con un grep de los targets.
  - [x] Releer la sección nueva de `architecture-api.md` contra el código real de la rama: cada FQCN,
        constante y firma citados deben existir tal cual (`FilterApplier::apply(...)`, caps, markers).
  - [x] Confirmar que `docs/index.md` no requiere alta (no se crea ningún `.md` nuevo — solo ediciones).
  - [x] Actualizar este story file (checkboxes, File List, Dev Agent Record, Change Log) + sprint-status
        → `review`. Los artefactos `_bmad-output` se commitean a la rama (precedente fijado en el review
        de la 1.5 por decisión de usuario) — ojo: el checkout principal y el worktree tienen copias
        divergentes de `sprint-status.yaml`/`epics.md`; sincronizar la versión final en la rama.
  - [x] Commit(s) en la rama con scope docs: p. ej. `docs(api): add filterable-search recipe and close epic 1 doc tree`.
        NO mergear, NO abrir PR sin que el usuario lo pida.

## Dev Notes

### Estado real del mecanismo (verificado contra la rama, 2026-06-07)

El contrato final post-pivote 1.5 que la receta debe reflejar (NO el del ADD de planificación, que quedó
parcialmente obsoleto):

- `SearchQuery` y `SearchCriteria` son **`final`** y **sin `ids`**. La especialización per-entity está
  PROHIBIDA (ni subclases ni wire params nuevos); los campos filtrables se exponen SOLO via
  `searchFieldMap()` del repositorio. El wire legacy `names[]`/`ids[]` no existe (params desconocidos,
  ignorados — escenario Behat lápida lo pinea).
- Flujo canónico: `#[MapQueryString] SearchQuery` (base) → `toCriteria()` en el controller →
  `<Entity>Searcher::search(SearchCriteria)` → repo extiende `AbstractDoctrineSearchRepository` →
  seam auto-aplica `FilterApplier::apply(qb, filters, searchFieldMap())` antes de paginar → `Paginator`
  keyset/HMAC intacto. El applier es invocado EXCLUSIVAMENTE por la base; el field map se construye
  EXCLUSIVAMENTE en cada repo concreto.
- `FieldMapping` (constructor real): `(string $dqlPath, ?FieldNormalizer $normalizer = null,
  array $operators = [los tres], bool $requiresUuidValues = false)`; rechaza en construcción
  `requiresUuidValues` + `Contains` (LogicException). `requiresUuidValues` pre-valida formato UUID →
  400 `invalid-search-value` con `{field, position}` (0-based, sin echo del valor) en vez de un 500 de
  Postgres (22P02).
- Errores: marker `InvalidSearchCriteria` → 400; concretas `UnknownSearchField`,
  `UnsupportedSearchOperator`, `InvalidSearchValue` (types kebab-case). Shape → mapping →
  `validation-failed` + `violations[]`. Todo ya documentado en `docs/api-error-contract.md` (1.2) —
  la sección nueva ENLAZA, no duplica.
- Caps: `SearchQuery::MAX_FILTERS = 20`, `FilterQuery::MAX_IN_VALUES = 100`, 255 chars/valor; límite
  efectivo `min(caps, max_input_vars=1000, longitud URL)` — ya documentado en `adding-endpoints.md`;
  la sección nueva puede citarlo brevemente y enlazar.

### Reparto de responsabilidades entre docs (evitar duplicación — fuente única)

| Doc | Rol tras esta historia |
|---|---|
| `docs/architecture-api.md` | **Patrón + receta canónica** (FR7) — la fuente única; qué es el mecanismo, por qué, anti-patterns, ejemplo de field map |
| `api/docs/adding-endpoints.md` | Walkthrough operativo del skeleton (controller/searcher/repo paso a paso + gramática wire + caps) — ya está al día (1.4/1.5); solo se arregla el link al ADR |
| `docs/api-error-contract.md` | Contrato de errores (ya al día desde 1.2/1.4 — NO tocar) |
| `docs/source-tree-analysis.md` / `docs/claude-code-quickref.md` | Layout del árbol (Task 2) |
| `docs/deep-dive-api-shared-foundation.md` | Deep-dive point-in-time de `Shared/` — solo se corrigen las 5 afirmaciones hoy falsas (Task 3); cobertura exhaustiva del mecanismo nuevo NO es alcance de esta historia |

### Inteligencia de la historia previa (1.5)

- La lista de flecos de Task 2/3 viene literal de las Project Structure Notes de la 1.5 ("Para la story 1.6,
  anotar al crearla…") — es la obligación registrada, no alcance inventado.
- El ADR `adr-2026-04-29-search-controller-boundary.md` ya NO existe en el repo (eliminado en
  `ef483f8 feat(api): remove docs`); la nota sobre el retiro de subclases ya vive en `adding-endpoints.md`.
  Solo queda el enlace roto.
- Lección de proceso 1.4/1.5: ediciones quirúrgicas, jamás re-serializar/reformatear ficheros enteros.
  Aplica igual a Markdown: diffs mínimos, no re-envolver párrafos vecinos.
- Los docs están redactados en inglés — las secciones nuevas se escriben en inglés, coherentes con el
  fichero anfitrión (la config BMAD pide artefactos en español, pero los docs del repo no son artefactos BMAD).

### Inteligencia git (rama `feat/shared-search-filters-aj0w`, 11 commits)

`18e1a2f` (1.1 vocabulario) → `f53e3b8`+`b59a28f` (1.2 errores) → `753e051`+`2a3ad6c`+`96682ba` (1.3 applier)
→ `f1ed854`+`1c108d6` (1.4 contrato filters[]) → `31d508c`+`6c9aaab` (1.5 retirada legacy, **breaking**:
`feat(api)!`) → `ef8a8d8` (artefactos bmad 1.5). Patrón de commits: Conventional Commits con scope `api`;
para esta historia el scope natural es `docs(api)` o `docs`.

### Project Structure Notes

- Delta esperado de esta historia: 5 ficheros `.md` modificados en la rama
  (`docs/architecture-api.md`, `docs/source-tree-analysis.md`, `docs/claude-code-quickref.md`,
  `docs/deep-dive-api-shared-foundation.md`, `api/docs/adding-endpoints.md`) + artefactos `_bmad-output`
  + 1 borrado fuera de git (`php-criteria-main/` en el checkout principal) + decisión sobre la línea
  no committeada de `.gitignore`. Cero PHP, cero TS, cero migraciones, cero config.
- Si al editar se detecta OTRA referencia pre-pivote no listada aquí, corregirla y anotarla en el
  Dev Agent Record (regla "fix violations you spot"), sin abrir refactors documentales nuevos.

### Investigación técnica externa

N/A — historia documental sin librerías nuevas ni APIs externas; toda la verdad técnica está en el código
de la rama (leerlo antes de citarlo es el gate de la Task 5).

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 1.6] — ACs base (1-3) + FR7.
- [Source: _bmad-output/planning-artifacts/architecture.md#Pattern Enforcement] — "la receta se documenta
  en docs/architecture-api.md — fuente única"; anti-patterns pineados en #Pattern Examples; cierre del
  research (retirada de php-criteria-main) en #Development Workflow Integration.
- [Source: _bmad-output/implementation-artifacts/1-5-camino-unico-legacy-filters-en-bank-d8.md#Project Structure Notes]
  — lista de flecos documentales que origina las Tasks 2-3 (AC 4).
- [Source: api/docs/adding-endpoints.md#Search endpoints] — skeleton + gramática wire ya documentados (no duplicar).
- [Source: api/src/Backoffice/Bank/Infrastructure/Persistence/Doctrine/DoctrineBankRepository.php:91-102 (rama)]
  — ejemplo canónico real de `searchFieldMap()`.
- [Source: api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FieldMapping.php (rama)] — firma real
  del constructor + invariante UUID/contains.
- [Source: CLAUDE.md#Markdown link style / #Keeping docs up to date] — reglas que gobiernan AC 2 y AC 4.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8[1m] (Claude Opus 4.8, 1M context) — Claude Code

### Debug Log References

- Historia docs-only: cero PHP/TS, cero tests de código, no aplica red-green. El gate de verificación
  son los greps de la Task 5 (definidos como comandos exactos) + resolución de enlaces.
- Baseline de referencias obsoletas (Task 0, desde el worktree): hits pre-pivote confirmados en
  `docs/deep-dive-api-shared-foundation.md` (entradas `SearchQuery`/`SearchCriteria`, diagrama del pipeline,
  Modification Guidance §"Adding a new bounded context" puntos 1 y 3, Quick Index "Build a search endpoint")
  y `api/docs/adding-endpoints.md` (enlace al ADR eliminado). `claude-code-quickref.md` y
  `source-tree-analysis.md` (tabla Critical folders) ya estaban al día desde la 1.3 (alta de `Doctrine/Search/`).
- Verificación de firmas contra la rama ANTES de citar: `FilterApplier::apply(QueryBuilder, Filters, SearchFieldMap): void`;
  `AbstractDoctrineSearchRepository::getPaginatedResults()` auto-aplica el applier entre `getSearchQueryBuilder()`
  y la paginación; `SearchQuery` LOC 87 (`final readonly`, `MAX_FILTERS = 20`, callback `validateFilterIndexes`);
  `SearchCriteria` LOC 25 (`final readonly`, `filters = new Filters()`); `FieldMapping(dqlPath, normalizer?, operators?, requiresUuidValues?)`.
- Punto extra (regla "fix violations you spot"): el deep-dive listaba `Search` también en el layering genérico
  de `Bank/Domain/` (Modification Guidance §1) — corregido junto a los 5 flecos pineados.
- Task 5 greps (worktree): `Bank/Domain/Search` → 0; `BankSearchQuery|BankSearchCriteria` → 0;
  `adr-2026-04-29` como link → 0 (sobrevive solo como código inline); `Subclass per entity` → 0. Enlaces nuevos
  (#anchor incluidos) resuelven a ficheros existentes; anchor `#filterable-search-generic-filters-contract` verificado.

### Completion Notes List

- **AC1 (receta canónica):** sección nueva `## Filterable search (generic filters[] contract)` en
  `docs/architecture-api.md` — flujo end-to-end del seam, dos capas de validación pineadas, caps, receta FR7
  (≤ 2 clases + 1 field map, cero ficheros en `Shared/`), ejemplo real de `searchFieldMap()` (incl.
  `requiresUuidValues`) y los 6 anti-patterns. Fuente única; cross-link bidireccional con
  `api/docs/adding-endpoints.md` (skeleton) sin duplicar el código del controller.
- **AC2 (árbol documental):** `source-tree-analysis.md` — `Search` retirado del comentario de `Bank/Domain/`,
  `Doctrine/Search` añadido al de `Shared/Infrastructure/Persistence/`. `claude-code-quickref.md` ya reflejaba
  la estructura real desde la 1.3 (fila de layout + link al walkthrough; cero menciones pre-pivote) → verificado
  sin cambios, no es un skip.
- **AC4 (flecos 1.5):** `deep-dive-api-shared-foundation.md` — 5 afirmaciones pre-pivote corregidas (entradas
  `SearchQuery`/`SearchCriteria`, diagrama del pipeline con el paso `FilterApplier`, Modification Guidance §1+§3,
  Quick Index) + el punto extra detectado. `api/docs/adding-endpoints.md` — enlace al ADR inexistente convertido
  en código inline (regla Markdown link style) y redirigido a la nueva sección de `architecture-api.md`.
- **AC3 (cierre research):** `php-criteria-main/` retirado del checkout principal previa confirmación explícita
  del usuario (material ignorado por git, irrecuperable); línea muerta `/php-criteria-main/` revertida de
  `.gitignore` (también por decisión del usuario; el diff era solo esa línea).
- **Seguridad (checklist CLAUDE.md):** cambio exclusivamente documental — sin código, sin SQL, sin endpoints,
  sin authn/authz, sin secretos, sin migraciones, sin CORS/Mercure. No aplica ninguna clase de ataque; los docs
  no introducen ni amplían superficie. Declarar "docs-only, N/A" en el PR.
- **Pendiente de decisión del usuario:** PR (fases 0+1+cierre en la rama `feat/shared-search-filters-aj0w`,
  12 commits + el de esta historia) única o troceada; el merge a `main` es del usuario. La épica 1 queda
  cerrada a nivel de contenido.

### File List

Cambios en la rama `feat/shared-search-filters-aj0w` (worktree):

- `docs/architecture-api.md` (modificado — sección "Filterable search" + receta FR7 + anti-patterns)
- `docs/source-tree-analysis.md` (modificado — `Doctrine/Search` alta, `Bank/Domain/Search` baja)
- `docs/deep-dive-api-shared-foundation.md` (modificado — 5 flecos pre-pivote + 1 extra)
- `api/docs/adding-endpoints.md` (modificado — enlace roto al ADR → inline + redirección a la receta)
- `_bmad-output/implementation-artifacts/1-6-receta-de-generalizacion-y-cierre-documental.md` (nuevo — story file)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (modificado — estados 1.6)

Cambios en el checkout principal (fuera de git, no commiteables):

- `php-criteria-main/` (eliminado — material de estudio ignorado por git)
- `.gitignore` (revertido a su estado de `main` — línea `/php-criteria-main/` retirada)

## Change Log

- 2026-06-07: Story 1.6 creada (create-story) — cierre documental de la épica 1: receta FR7 en
  architecture-api.md, árbol documental al día, flecos 1.5 (deep-dive + ADR link) y retirada de
  php-criteria-main/. Status: ready-for-dev.
- 2026-06-08: Implementación (dev-story). 4 docs editados en la rama (architecture-api receta + anti-patterns;
  source-tree; deep-dive 5+1 flecos; adding-endpoints enlace al ADR). `php-criteria-main/` retirado y
  `.gitignore` revertido, ambos con confirmación explícita del usuario. Greps de verificación en verde
  (0 referencias pre-pivote), enlaces y anchors resueltos. Status → review.

## Review Findings (code review — 2026-06-08)

Revisión adversarial en 3 capas (Blind Hunter solo-diff / Edge Case Hunter / Acceptance Auditor, Opus 4.8).
Las dos capas con acceso al código de la rama verificaron que los docs son **fieles al código**: ejemplo
`searchFieldMap()` byte-a-byte vs `DoctrineBankRepository`, firma de `FieldMapping`, backing values del enum
`FilterOperator` (`eq`/`in`/`contains`), read-path (`getPaginatedResults` → `getSearchQueryBuilder` →
`FilterApplier::apply` → paginar), caps (`MAX_FILTERS = 20`), split de validación, anchors/enlaces relativos,
greps de la Task 5 en verde, File List exacta y cero scope creep (sin tocar `api-error-contract.md`).
18 sospechas del Blind Hunter (sin acceso al código) resultaron falsos positivos (anchors correctos, rutas
correctas, LOC correctos, ADR recuperable de git, `❌` es estilo de casa ya presente en los docs) y se
desestiman.

- [x] [Review][Patch] Aclarar que el set de operadores por defecto de `FieldMapping` es `eq`/`in`/`contains` [docs/architecture-api.md — sección "Filterable search (generic filters[] contract)", párrafo del normalizer] — la receta muestra el campo UUID `id` con `operators: [Eq, In]` y la prosa dice que `requiresUuidValues: true` "is rejected at construction if combined with contains", pero NO indica que el set por defecto sean los tres operadores. Un autor que añada un campo UUID nuevo y omita `operators:` chocará con un `LogicException` en construcción (el default incluye `Contains`, verificado en `FieldMapping.php:31`). Fix: una cláusula breve — el default son los tres; los campos UUID deben restringir operadores (como hace el ejemplo).
