---
title: 'ADR — Search controller HTTP boundary: query-param validation, filter DTOs, response envelope'
status: 'accepted'
date: '2026-04-29'
acceptedOn: '2026-04-29'
author: 'Sergio (with Winston, architect)'
scope: 'API — `Backoffice/**` and `Frontoffice/**` search endpoints; includes a Phase 0 Domain purity prerequisite (Pagination port)'
supersedes: []
related:
  - '_bmad-output/implementation-artifacts/code-review-2026-04-28-pagination.md'
  - 'docs/architecture-api.md'
  - 'docs/project-context.md'
---

# ADR — Search controller HTTP boundary

## 1. Context

`BankSearchController` (`api/src/Backoffice/Bank/Infrastructure/Controller/BankSearchController.php`) is the first of an expected **~100 search endpoints** for new entities in the next quarter. The current shape duplicates four concerns per controller:

1. **Query-param parsing** — manual reads from `Request::query`, `getInt`, `get(...)`.
2. **Validation / coercion** — `PaginationMode::tryFrom(...) ?? throw`, `max(1, ...)` page floor, ad-hoc `BadRequestException` catch on `paginationMode[]=…` arrays. `AbstractSearchRepository::getPaginatedResults` then re-validates `page`/`limit` (PHP `match` + `InvalidArgumentException`), so the same rules live in two places with subtly different error messages.
3. **Error → HTTP mapping** — `InvalidArgumentException` from the searcher is hand-converted to `BadRequestHttpException`; the symfony `BadRequestException` from the query bag is hand-rewrapped.
4. **Response envelope** — every controller hand-builds the `{ items, pagination: { currentPage, pageCount, count, hasMorePages, cursor } }` shape via `$this->responder->respond(Result::ok([...]))`.

Replicating this verbatim 99 more times is a **non-trivial maintenance liability**: a single bug-fix in the response envelope or an addition to the shared param surface (e.g. `?sort=…` allow-list per the deferred D1 from `code-review-2026-04-28-pagination.md`) requires 100 patches.

There is also a **typing gap downstream**: `BankSearcher::search(array<string, mixed>)` and `BankRepository::search(array<string, mixed>)` accept an untyped bag, which forces re-validation in `AbstractSearchRepository` and lets entity-specific filters sneak in via untyped string keys (`$queryParams['names']` in `PostgresBankRepository::getSearchQueryBuilder`). That's tolerable for one entity, painful at 100.

### Constraints that shape the decision

- **`docs/project-context.md` rules** — Validator attributes belong on DTOs in `Application/` or request-layer DTOs, **not** on domain entities. Domain stays free of Symfony/Doctrine/HTTP imports.
- **Symfony 8.0** — `#[MapQueryString]` argument resolver is available and emits `UnprocessableEntityHttpException` (422) with structured violations on validation failure. Pairs with `ValidationFailedException` and Symfony's standard error normalizer.
- **Existing infra to reuse** — `Result`, `ResponderInterface`/`JsonResponder`, `JsonApiErrorBuilder`, `PaginatorCursorFactory`, `PaginationMode`, `QueryParam`, `AbstractSearchRepository`. None of this should change shape; this ADR is additive.
- **Pre-applied lessons** from `code-review-2026-04-28-pagination.md` — H4 (invalid `paginationMode` → 400), H5 (page upper-bound clamp), L3 (malformed cursor → 400), D1 (sort allow-list). The new boundary must enforce all of these declaratively.

---

## 2. Decision

Centralize the search HTTP boundary on **four shared building blocks** and migrate controllers to consume them. Per-controller code shrinks to ~10 lines and contains only entity-specific wiring (searcher + serializer groups + filter DTO).

### 2.1 The four building blocks

1. **`SearchQuery` base DTO** — `Erpify\Shared\Application\Http\Search\SearchQuery` (or `Shared/Infrastructure/Http/Search/` if we conclude validator attributes are HTTP-boundary infra; see open question Q1). Carries the universal pagination params with `#[Assert\…]` attributes:
   - `?string $cursor` — `#[Assert\Length(max: 8192)]` to cap zip-bomb surface even before `PaginatorCursorFactory` decodes.
   - `int $page = 1` — `#[Assert\Positive]`, `#[Assert\LessThanOrEqual(AbstractSearchRepository::MAX_PAGE)]`.
   - `?int $limit = null` — `#[Assert\Positive]`, `#[Assert\LessThanOrEqual({entity-max})]`. Default null lets the repository apply its `MAX_LIMIT`.
   - `PaginationMode $paginationMode = PaginationMode::LIGHT` — Symfony 8 binds string → enum natively; unknown values → 422 automatically.
   - `?list<string> $ids = null` — `#[Assert\All([new Assert\Uuid()])]` for UUID-keyed entities (overridable per entity if a non-UUID id is used).

2. **Per-entity filter DTO** — extends `SearchQuery` and adds only the filters the entity exposes. Example for Bank:

   ```php
   final class BankSearchQuery extends SearchQuery
   {
       /** @var list<string>|null */
       #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 255)])]
       public ?array $names = null;
   }
   ```

   One DTO per entity. Discoverable, type-safe, self-documenting. Filter shape becomes part of the controller's signature rather than a string-keyed array reach-in.

3. **`AbstractSearchController`** — `Erpify\Shared\Infrastructure\Http\Controller\AbstractSearchController`. Templates the request/response cycle:

   ```php
   abstract class AbstractSearchController
   {
       public function __construct(
           protected NormalizerInterface $normalizer,
           protected ResponderInterface $responder,
           protected PaginatorCursorFactory $cursorFactory,
       ) {}

       /** @param list<string> $serializerGroups */
       protected function buildResponse(Paginator $paginator, array $serializerGroups): Response
       {
           /** @var array<int, mixed> $items */
           $items = $this->normalizer->normalize(
               array_values(iterator_to_array($paginator)),
               'json',
               ['groups' => $serializerGroups],
           );

           return $this->responder->respond(Result::ok([
               'items' => $items,
               'pagination' => [
                   'currentPage' => $paginator->getCurrentPage(),
                   'pageCount' => $paginator->getPageCount(),
                   'count' => $paginator->getCursor()->getCount(),
                   'hasMorePages' => $paginator->hasMorePages(),
                   'cursor' => $this->cursorFactory->toString($paginator->getCursor()),
               ],
           ]));
       }
   }
   ```

   Concrete controllers extend it and use `#[MapQueryString]` to receive the typed filter DTO. `__invoke` shrinks to the entity-specific lines.

4. **Cross-cutting error normalization** — handled by an `ExceptionListener` (or extension to existing `JsonApiErrorBuilder` flow) that maps:
   - `Symfony\…\ValidationFailedException` → 422 with field-level violations (Symfony already emits `UnprocessableEntityHttpException`; we just ensure `JsonApiErrorBuilder` envelope is used uniformly).
   - `InvalidArgumentException` from `Paginator`/`PaginatorCursorFactory`/`AbstractSearchRepository` → 400. This removes the per-controller `try/catch InvalidArgumentException` and the `BadRequestException`-rewrap.

   With this listener, `BankSearchController::__invoke` no longer contains a `try`.

### 2.2 What `BankSearchController` becomes

```php
#[Route('/banks', name: 'backoffice_bank_search', methods: ['GET'])]
final readonly class BankSearchController extends AbstractSearchController
{
    public function __construct(
        private BankSearcher $bankSearcher,
        NormalizerInterface $normalizer,
        ResponderInterface $responder,
        PaginatorCursorFactory $cursorFactory,
    ) {
        parent::__construct($normalizer, $responder, $cursorFactory);
    }

    public function __invoke(#[MapQueryString] BankSearchQuery $query = new BankSearchQuery()): Response
    {
        return $this->buildResponse(
            $this->bankSearcher->search($query),
            ['aggregate:default', 'bank:search'],
        );
    }
}
```

~12 lines including imports, no `try/catch`, no `tryFrom`, no `max(1, …)`, no envelope plumbing. The 99 future endpoints follow the exact same skeleton; only the route, searcher, filter DTO, and serializer groups change.

### 2.3 Searcher / repository signature

`BankSearcher::search()` and `BankRepository::search()` change from `array<string, mixed>` to **`SearchQuery $query`** (the base type). Per-entity searchers may narrow to the concrete subtype if they need entity-specific filters (PHP allows covariant parameter-type widening per LSP only with care; in practice we keep the parameter as the base `SearchQuery` and have the searcher cast/use the concrete subclass — see Q2).

`AbstractSearchRepository::getPaginatedResults(SearchQuery $query)` reads typed properties — the `match` blocks that re-validate `page`/`limit` go away. The abstract `getSearchQueryBuilder(SearchQuery $query)` is the only place per-entity code runs; concrete repos type-hint the concrete subclass:

```php
public function getSearchQueryBuilder(SearchQuery $query): QueryBuilderWithOptions
{
    assert($query instanceof BankSearchQuery);
    // ... read $query->names, $query->ids, etc.
}
```

This is the pragmatic LSP-respecting pattern. PHPStan won't complain with the `assert`; alternatively we add a generic `@template Q of SearchQuery` to `AbstractSearchRepository` (Q2).

---

## 3. Alternatives considered

### A. **`#[MapQueryString]` + DTO** (chosen)

- **Pro:** idiomatic Symfony 8; free 422 with field violations; declarative validation; type-safe DTOs travel through Application/Infrastructure; centralization is *additive* — no magic, no inheritance gymnastics.
- **Con:** introduces a class per searchable entity (filter DTO). At 100 entities that's 100 small files — but each is ~5 lines and replaces ~30 lines of duplicated controller code.

### B. Centralized `SearchQueryParser` service

A `SearchQueryParser::parse(Request $request, string $filterDtoClass): SearchQuery` service called from each controller.

- **Pro:** explicit, no Symfony attribute magic, easier to debug.
- **Con:** reinvents what `#[MapQueryString]` already does; loses the automatic 422 with violation list; every controller still needs the call boilerplate (~3 lines instead of 0). At 100 endpoints that's 300 redundant lines. Rejected.

### C. Abstract base controller + template-method only (no DTO)

`AbstractSearchController::__invoke(Request $request)` parses query params using `Request::query` and a hook `protected abstract function buildSearcherInput(Request $request): array;` for entity-specific filters.

- **Pro:** zero new classes per entity beyond the controller subclass.
- **Con:** keeps the array-bag downstream (no typing gain); per-entity filter validation is still hand-rolled per controller; inheritance owns request parsing, which fights the repo's "thin controllers" rule. Rejected.

### D. Single generic `SearchController` routed by path/segment

One controller routed at `/banks`, `/customers`, etc. via attribute config that wires the right searcher + groups.

- **Pro:** truly DRY — 0 new controllers per entity.
- **Con:** magic registry; serializer-group config drifts away from entity code; harder to grep for an endpoint; per-entity auth (`#[IsGranted]`) becomes awkward. Rejected for legibility.

### E. Status quo (do nothing, copy-paste controllers)

- **Pro:** no upfront work.
- **Con:** the next 99 endpoints inherit every quirk and bug. A change to the response envelope = 100 PRs. Rejected.

---

## 4. Consequences

### Positive

- **Per-controller surface drops to ~12 lines.** New search endpoint cost: filter DTO (~5 lines) + searcher (~10 lines) + controller (~12 lines).
- **Validation is single-sourced** at the HTTP boundary. The `match` re-validation in `AbstractSearchRepository::getPaginatedResults` is deleted — the DTO already guarantees `page`/`limit` are positive ints within bounds.
- **422 responses are uniform**, with field-level violation envelopes via `JsonApiErrorBuilder`. Today, the same bad input can return 400 (controller) or 400 (repository) with different bodies; this collapses to one shape.
- **Filter contracts are discoverable.** `BankSearchQuery` documents the supported filters. Today the answer requires reading `PostgresBankRepository::getSearchQueryBuilder`.
- **Sort allow-list (D1)** lands declaratively as `#[Assert\Choice]` on a `?string $sort` property in entity-specific DTOs (or a per-entity allow-list constant referenced by the assertion). One enforcement point.
- **Unblocks the hardening already on the backlog:** cursor-length cap (H9 follow-up), explicit per-entity `MAX_LIMIT`, and `paginationMode[]=…` rejection all become DTO concerns.

### Negative / cost

- **~100 filter DTO classes** to be authored over time (one per entity). Each is small but real.
- **Migration touches the searcher/repository contract.** `BankSearcher::search()` and `BankRepository::search()` change type. Behat scenarios for each entity need to keep passing — pilot on Bank first to de-risk before fanning out.
- **`BankSearcher` becomes anaemic.** Already a one-line passthrough today (`return $this->bankRepository->search($queryParams);`). With a typed DTO, we should consider whether the Application-layer searcher should still exist or whether the controller should call the repository directly via the Domain interface. Keep for now (deletion is reversible; preserves a hook for use-case logic later).
- **Symfony version coupling.** `#[MapQueryString]` is Symfony 6.3+. Project is on 8.0 — fine — but the boundary is now Symfony-shaped. Acceptable since this is `Infrastructure/`, not `Domain/`.
- **`#[Assert\…]` imports in Application.** `Application/Http/Search/SearchQuery.php` will import `Symfony\Component\Validator\Constraints`. Per `project-context.md`, validator attributes on Application DTOs are *explicitly allowed*. Confirmed compliant.

### Risk register

| Risk | Likelihood | Impact | Mitigation |
| --- | --- | --- | --- |
| `#[MapQueryString]` doesn't bind `?ids[]=...&ids[]=...` array form as expected | Medium | High (breaks listed Behat scenarios) | Pilot on `BankSearchController` first; verify against `features/backoffice/bank/search.feature` array-form scenarios before fanning out. Add a Behat scenario for `?ids=` (single) and `?ids[]=` (array) before refactor. |
| `ValidationFailedException` → 422 envelope diverges from `JsonApiErrorBuilder` shape currently used by `BankGetController` | Medium | Medium | Add or extend an `ExceptionListener` that normalizes `ValidationFailedException` through `JsonApiErrorBuilder::fromViolations(...)`. |
| Per-entity `MAX_LIMIT` lives where? Today `AbstractSearchRepository::MAX_LIMIT` is referenced but *commented out* (line 24) | High | Medium | Decide as part of pilot: either restore the constant on the abstract with a default (e.g. 500) and override per concrete repo, or move the cap onto the entity's filter DTO via `#[Assert\LessThanOrEqual]`. Recommend the DTO route — caps become part of the public API contract. |
| Inheritance lock-in for filter DTOs | Low | Medium | If a filter DTO ever needs to be reused outside HTTP, refactor that single one to composition with a `PaginationParams` trait. The base→concrete inheritance is a default, not a mandate. |
| Existing `code-review-2026-04-28-pagination` follow-ups (cursor HMAC, sort allow-list, `?paginationMode[]=…`) get rolled into this scope | High | Low/Positive | They *should* be — this is the right moment. Track each in the migration checklist below. |

---

## 5. Decisions (accepted 2026-04-29)

All six previously-open questions are now locked. Rationale is preserved below in case future readers need it; **the bold "Decision" line is the binding answer**.

- **Q1. DTO location.**
  **Decision:** base `SearchQuery` lives at `Erpify\Shared\Application\Http\Search\SearchQuery`. Per-entity filter DTOs live at `Erpify\<Office>\<Module>\Application\Http\<Entity>SearchQuery` (e.g. `Erpify\Backoffice\Bank\Application\Http\BankSearchQuery`).
  *Rationale:* `project-context.md` explicitly places validator-decorated DTOs in `Application/`. Mirrors the existing pattern for request DTOs.

- **Q2. Generic typing on `AbstractSearchRepository`.**
  **Decision:** start with `assert($query instanceof BankSearchCriteria)` inside concrete `getSearchQueryBuilder`. No `@template Q` introduced now.
  *Rationale:* PHPStan-clean, minimum code, reversible. Upgrade to generics only if a second consumer of the abstract appears with a real need.

- **Q3. Domain repository signature.** *(reasoning corrected — see "Phase 0" below)*
  **Decision:** Domain repositories accept a Domain-layer `SearchCriteria` (per-entity subclass), **not** the Application-layer `SearchQuery` DTO. Application's `BankSearcher` maps DTO → criteria at the boundary. Domain emits a Domain-layer `PaginatedResult<T>` interface, not the Infrastructure `Paginator` directly.
  *Rationale (revised):* the original draft argued `Domain` already imports Infrastructure (`Paginator`) and Symfony (`Uuid`), so layering a validator-decorated DTO on top was consistent. Sergio (correctly) flagged that the existing `Paginator` import in `BankRepository` is itself a violation that needs fixing — so the precedent is being repaired, not relied upon. Strict layering is the standard. The Application DTO never crosses into Domain; it converts to a Domain `SearchCriteria`. The Application searcher (`BankSearcher`) is the conversion point. See **Section 6 — Phase 0** for the prerequisite Pagination port that this decision depends on.

- **Q4. Should `BankSearcher` survive?**
  **Decision:** keep it. Its role strengthens under the Phase 0 / Q3 layering: it owns the **`SearchQuery` DTO → `SearchCriteria` mapping** and the conversion of the Domain `PaginatedResult` back to whatever the controller needs. It is no longer an anaemic passthrough.
  *Rationale:* Application-layer use case classes are the right home for HTTP-DTO-to-Domain-criteria conversion. Aligns with hexagonal discipline.

- **Q5. Default for `paginationMode`.**
  **Decision:** `PaginationMode::LIGHT` on `SearchQuery::$paginationMode` (the DTO default).
  *Rationale:* matches today's controller behavior and the cheaper query. The `PaginationMode` enum docblock claiming `DETAILED` is "default" is stale — to be cleaned up when the enum moves to Domain in Phase 0. No behavior change for existing clients.

- **Q6. Behat coverage for new failure modes.**
  **Decision:** scenarios authored in P1 *before* the refactor; they must fail-the-right-way on the current code so they prove the migration's correctness.
  *Rationale:* standard regression-net hygiene; especially needed since the migration deletes the controller's hand-rolled validation.

- **P2 (in §6 below). `?ids[]=invalid` contract.**
  **Decision: 422 with structured violation list** via `JsonApiErrorBuilder::fromViolations(...)`. The existing Behat scenario at `features/backoffice/bank/search.feature:28-32` will be **updated** as part of P1 to assert 422, not 200.
  *Rationale:* invalid input is invalid input. Aligns with the new uniform error envelope. Frontend impact is real but contained — flag in the migration PR description so any consumer dependency is caught in review.

---

## 6. Phase 0 — Domain purity prerequisite (Pagination port)

This phase exists because **Sergio flagged that `Erpify\Backoffice\Bank\Domain\Repository\BankRepository` imports `Erpify\Shared\Infrastructure\Persistence\Paginator`**, which violates the project's hexagonal layering rule (`docs/project-context.md` §"Architecture anti-patterns": *"Importing Symfony / Doctrine / HTTP / DI-container types inside `Domain/`"*).

The search-controller migration changes `BankRepository::search()`'s signature anyway. That makes this the right moment to also fix the return-type leak rather than ship a clean parameter type alongside a still-leaky return type.

### 6.1 Scope of Phase 0

Phase 0 introduces three Domain-side abstractions in `Erpify\Shared\Domain\Search\`:

1. **`PaginationMode` enum** — *moves* from `Erpify\Shared\Infrastructure\Persistence\PaginationMode` to `Erpify\Shared\Domain\Search\PaginationMode`. Pure enum, no Doctrine deps. Update all imports.

2. **`SearchCriteria`** — Domain-layer base value object (final readonly class) with the universal pagination fields (`cursor`, `page`, `limit`, `paginationMode`, `ids`). No Symfony imports. Per-entity subclasses (e.g. `BankSearchCriteria` in `Erpify\Backoffice\Bank\Domain\Search\`) add filter fields (`names`, etc.).

3. **`PaginatedResult<T>`** — Domain-layer port (interface). Methods mirror what callers use today on `Paginator`: `getCurrentPage(): int`, `getPageCount(): ?int`, `hasMorePages(): bool`, `getCursor(): SearchCursor`, plus `IteratorAggregate<int, T>`. **Does not extend `Countable`** — the original ADR draft mentioned `count(): int` here, but adding `Countable` would create a confusing two-counts API alongside `getCursor()->getCount()` (cursor's optional total dataset count vs. collection's current-page count); no current caller needs it. Decision corrected during P0.4 implementation. The Infrastructure `Paginator` *implements* this interface — `Paginator` itself stays in `Shared/Infrastructure/Persistence/` (Doctrine-coupled, can't move).

   This is the standard hexagonal pattern: **Domain owns the port, Infrastructure owns the adapter.**

4. **`SearchCursor`** — Domain-layer interface for the cursor's read surface. Exposes `getCurrentPage(): ?int`, `getCount(): ?int`, `getFirstItem(): array<string, mixed>`, `getLastItem(): array<string, mixed>` — the four methods that `PaginatorCursorFactory::toString()` reads to serialize the cursor and that the response envelope reads to render counts. Setters stay on the existing Infrastructure `PaginatorCursorInterface`, which extends `SearchCursor` in P0.6 (composition by extension keeps Infrastructure's mutability without polluting Domain).

### 6.2 Why this is in scope of *this* ADR

Two reasons:

1. **The search-controller refactor edits the same files** — `BankRepository`, `PostgresBankRepository`, `BankSearcher`, `AbstractSearchRepository`. Bundling Phase 0 avoids two passes over the same code.
2. **Q3's resolution depends on it.** Without the Domain `SearchCriteria`/`PaginatedResult` abstractions, the only choices are "Domain depends on Application DTO" (dependency inversion) or "Domain stays untyped with `array<string, mixed>`" (no typing gain). Neither is acceptable.

### 6.3 Out of scope for this ADR

- **Doctrine `QueryBuilder` exposure to Application/Domain.** Today `getSearchQueryBuilder()` returns a Doctrine `QueryBuilder` to `AbstractSearchRepository::getQueryBuilderPaginatedResults()` *within Infrastructure*. That's fine — both ends are Infrastructure. Don't expose `QueryBuilder` upward.
- **Pre-existing `Symfony\Component\Uid\Uuid` import in `BankRepository`** (`findById(Uuid $uuid)`). Sergio flagged Infrastructure imports specifically; the `Uuid` import is a separate concern (Symfony's Uuid is a common ID-VO substitute). Leaving as-is for this ADR; can be revisited as a follow-up if desired.
- **A full Pagination ADR** with detailed interface signatures. The skeleton in §6.1 is enough to start; concrete shapes get pinned in code review during P0.4–P0.5.

### 6.4 Phase 0 task list

- [x] **P0.1.** ~~Create `Erpify\Shared\Domain\Search\PaginationMode` (move from Infrastructure). Update all importers (`BankSearchController`, `AbstractSearchRepository`, `Paginator`, etc.). Delete the old file. Run `make php.stan` + `make php.lint`.~~ **Done 2026-04-29.** New file at `api/src/Shared/Domain/Search/PaginationMode.php` (namespace `Erpify\Shared\Domain\Search`); old file deleted. Three importers updated: `BankSearchController` (existing `use` statement, also reordered alphabetically per CS-Fixer), `AbstractSearchRepository` and `Paginator` (added explicit `use` — both previously relied on same-namespace pickup). Docblock cleaned up: removed stale "(default)" claim on `DETAILED` and made the example HTTP-agnostic. PHPStan: zero errors in the four touched files (the 17 errors in `make php.stan` output are all pre-existing in `AggregateRoot.php`, `AbstractRepository.php`, `PostgresBankRepository.php` — files not touched by P0.1). CS-Fixer / Rector / PHPCS / Psalm: clean for touched files (the one CS-Fixer flag on `AbstractSearchRepository.php` is a pre-existing comment-style issue on the commented-out `MAX_LIMIT` line, unrelated to the move). Bank unit tests: 3 tests, 41 assertions, all green — confirms autoloading works under the new namespace.
- [x] **P0.2.** ~~Create `Erpify\Shared\Domain\Search\SearchCriteria` base value object (readonly, final).~~ **Done 2026-04-29.** Created at `api/src/Shared/Domain/Search/SearchCriteria.php`. Declared as `readonly class` (not `final` — the ADR §6.1 "(final readonly class)" wording was inconsistent with P0.3 mandating extension; corrected to `readonly class` non-final, non-abstract — extendable, instantiable, immutable, and PHP 8.2+ enforces subclasses must also be readonly). Properties exposed as public promoted constructor params with sensible defaults (`page=1`, `paginationMode=LIGHT`). PHPStan / CS-Fixer / Rector / PHPCS: clean.
- [x] **P0.3.** ~~Create `Erpify\Backoffice\Bank\Domain\Search\BankSearchCriteria extends SearchCriteria` with `?list<string> $names`.~~ **Done 2026-04-29.** Created at `api/src/Backoffice/Bank/Domain/Search/BankSearchCriteria.php` as `final readonly class` extending `SearchCriteria`. Adds promoted `?array $names` (PHPDoc `list<string>|null`); other params pass through to `parent::__construct(...)`. Named-argument call sites stay clean (`new BankSearchCriteria(names: ['Foo'])`). PHPStan / CS-Fixer / Rector / PHPCS: clean. **Temporal Psalm warnings remain** (`BankSearchCriteria::UnusedClass`, `SearchCriteria::PossiblyUnusedProperty` × 5) — these are not defects; they resolve naturally when P0.7 (`BankRepository::search(SearchCriteria)`) and P0.8 (`PostgresBankRepository` reads the fields) land. Acceptable interim state during a multi-step refactor.
- [x] **P0.4.** ~~Create `Erpify\Shared\Domain\Search\PaginatedResult<T>` interface.~~ **Done 2026-04-29.** Created at `api/src/Shared/Domain/Search/PaginatedResult.php`. Surface: `getCurrentPage(): int`, `getPageCount(): ?int`, `hasMorePages(): bool`, `getCursor(): SearchCursor`, plus `extends IteratorAggregate<int, T>` via `@template T of object`. **Deviation from ADR draft:** dropped `Countable` / `count(): int` to avoid the two-counts API confusion with `getCursor()->getCount()` — §6.1 corrected. PHPStan / Rector / CS-Fixer / PHPCS clean. Psalm `UnusedClass` is temporal — resolves at P0.6 when `Paginator implements PaginatedResult`.
- [x] **P0.5.** ~~Create `Erpify\Shared\Domain\Search\SearchCursor` interface. Implementation: `Erpify\Shared\Infrastructure\Persistence\PaginatorCursor` adopts it.~~ **Done 2026-04-29.** Created at `api/src/Shared/Domain/Search/SearchCursor.php` exposing the four read methods that `PaginatorCursorFactory::toString()` and the response envelope rely on (`getCurrentPage`, `getCount`, `getFirstItem`, `getLastItem`). Setters intentionally excluded — they stay in Infrastructure's `PaginatorCursorInterface`, which will extend `SearchCursor` in P0.6 (additive, non-breaking). PHPStan / Rector / CS-Fixer / PHPCS clean. Psalm `PossiblyUnusedMethod × 4` is temporal — resolves at P0.6 when `PaginatorCursorInterface extends SearchCursor` and concrete consumers (`PaginatorCursorFactory::toString`) accept the Domain port.
- [x] **P0.6.** ~~Make `Erpify\Shared\Infrastructure\Persistence\Paginator` implement `PaginatedResult<T>`. No behavior changes; type contract change only.~~ **Done 2026-04-29** (commit `a8fa5bc`). `Paginator` now declares `@template T of object` and `implements PaginatedResult<T>`; the explicit `IteratorAggregate` import + implements were dropped (re-exposed via the port). `getIterator()` carries `@return Traversable<int, T>` plus `@var ArrayIterator<int, T>` cast on the boundary; the cached `?Iterator $iterator` property typed `Iterator<int, T>|null`. **Two adjacent changes were required and bundled here:** `PaginatorCursorInterface extends SearchCursor` (P0.5's prerequisite — additive, the four read methods now carry `#[Override]`; setters stay in Infrastructure) and `PaginatorCursorFactory::toString()` widened from `PaginatorCursorInterface` to `SearchCursor` (read-only access; required so `BankSearchController`'s `$paginator->getCursor()` typechecks once `BankSearcher` returns the Domain port). PHPStan clean for all three files; Psalm `MissingOverrideAttribute` cleared via `#[Override]` on the five Paginator methods inherited from `PaginatedResult`.
- [x] **P0.7.** ~~Update `BankRepository::search()` signature: `search(SearchCriteria $criteria): PaginatedResult` (or `BankSearchCriteria` parameter if Q2's generic upgrade ever happens; for now, base type + `assert` in the impl).~~ **Done 2026-04-29** (commit `a8fa5bc`). `Erpify\Backoffice\Bank\Domain\Repository\BankRepository` now imports only `Bank`, `Erpify\Shared\Domain\Search\PaginatedResult`, `Erpify\Shared\Domain\Search\SearchCriteria`, and the out-of-scope `Symfony\Component\Uid\Uuid` (per §6.3). `search(SearchCriteria $criteria): PaginatedResult` with `@return PaginatedResult<Bank>`. **Acceptance §8 #1 met:** `grep -n "use Erpify.*Infrastructure" api/src/Backoffice/Bank/Domain/Repository/BankRepository.php` returns no matches.
- [x] **P0.8.** ~~Update `PostgresBankRepository::search(SearchCriteria $criteria)` and `getSearchQueryBuilder(SearchCriteria $criteria)`. Body reads typed properties via `assert($criteria instanceof BankSearchCriteria)`. Removes the untyped `$queryParams['names']` reach-in.~~ **Done 2026-04-29** (commit `a8fa5bc`). Both `PostgresBankRepository::search` and `getSearchQueryBuilder` typed; `assert($criteria instanceof BankSearchCriteria)` only inside `getSearchQueryBuilder` where entity-specific filters are read (`$criteria->ids`, `$criteria->names`). `QueryParam` and `SortDirection` imports dropped (no longer needed). **Cascading change:** the abstract `AbstractSearchRepository::getPaginatedResults` and abstract `getSearchQueryBuilder` also took the `SearchCriteria $criteria` signature; the type-coercion `match` blocks for `page`/`limit` were dropped (typed `int`/`?int` make them dead code) — the `\max(1, \min(self::MAX_PAGE, …))` clamping was kept as defense-in-depth (Phase 1 P8 will delete it once the DTO carries `#[Assert\…]`). Sort/direction defaults are now `null, null` to `addOrderByFromQueryParams`, which is identical to today's controller behavior — the controller never passed `sort`/`direction` through the array bag, and the prior `SortDirection::tryFrom($queryParams[QueryParam::DIRECTION->value])` would have `TypeError`'d on the missing key.
- [x] **P0.9.** ~~Update `BankSearcher` to a temporary signature that still accepts `array<string, mixed>` and converts internally to `BankSearchCriteria`. (Keeps the controller working until Phase 1 swaps the DTO in.) Alternatively, defer this step until Phase 1 lands and skip directly.~~ **Done 2026-04-29** (commit `a8fa5bc`). Picked option (a) — option (b) wasn't viable because changing `BankRepository::search`'s parameter type without updating `BankSearcher` would fail typecheck. Added a private `toCriteria(array $queryParams): BankSearchCriteria` helper that asserts each input shape and constructs the criteria via named arguments. Return type widened to `PaginatedResult<Bank>`. `BankSearchController` is untouched. `@SuppressWarnings("PHPMD.CyclomaticComplexity")` annotation added on `toCriteria` (CC=12, threshold=10) consistent with `PaginatorCursorFactory::createFromString` and `Paginator::alterWhere` precedents. **Caveat:** `\assert(...)` is a no-op in production (`zend.assertions=-1`), so malformed inputs at this internal boundary silently coerce; the controller's `$request->query->getInt('page', 1)` and `$request->query->get('limit')` already filter the worst cases at the HTTP edge, but this is a known transition state — Phase 1 P3+P7 replace this adapter with `BankSearchQuery` (DTO with `#[Assert\Positive]` / `#[Assert\LessThanOrEqual]`). Tracked as **D1** in `_bmad-output/implementation-artifacts/deferred-work.md`.
- [x] **P0.10.** ~~Run full test suite (`make php.test`) + lint (`make php.lint`). All Bank Behat scenarios must remain green — Phase 0 is a refactor, not a behavior change.~~ **Done 2026-04-29** (commit `a8fa5bc`). PHPUnit: 20 tests, 110 assertions, all green. PHPStan: 10 errors remain, all pre-existing baseline noise in `AggregateRoot.php` and `AbstractRepository.php` (untouched by this phase) — zero new errors on the seven files Phase 0 touched. PHPMD: 3 violations, 2 pre-existing on `BankPostController` / `BankPutController` and 1 suppressed on `BankSearcher::toCriteria` (see P0.9 note). Psalm: all `MissingOverrideAttribute` findings on touched files closed via `#[Override]`; the remaining 342 errors project-wide are pre-existing baseline (DI auto-registration `PossiblyUnusedMethod`, `ClassMustBeFinal`, etc.) confirmed unchanged from baseline. **Behat surprise:** "remain green" was based on an incorrect baseline assumption — at commit `28bfed7`, **all 3 search.feature scenarios were already failing** ("List all banks" → 500 from `SortDirection::tryFrom(null)` `TypeError`; both `?ids[]=` scenarios → 400 from Symfony's `InputBag::get()` rejecting non-scalar values). Verified by reverting all seven Phase 0 files and re-running the suite. **Net behaviour change: +1 scenario fixed (List all banks: 500→200), 0 regressions.** The two `?ids[]=` failures are pre-existing and tracked as **D3** for closure in Phase 1 P5 (`#[MapQueryString]` argument resolver handles array-form natively); array element validation is **D2** for Phase 1 P3 (`#[Assert\All([new Assert\Uuid()])]`). All three deferred items are documented in `_bmad-output/implementation-artifacts/deferred-work.md`. Spec: `_bmad-output/implementation-artifacts/spec-p0-6-to-10-bank-pagination-port.md` (status `done`).

After Phase 0 lands, `Erpify\Backoffice\Bank\Domain\Repository\BankRepository` no longer imports anything from `Infrastructure`. Layer purity restored.

---

## 7. Phase 1 — Pilot migration plan (Bank)

**Depends on Phase 0 completion** (§6.4). Once `BankRepository` accepts `BankSearchCriteria` and returns `PaginatedResult<Bank>`, this phase wires the HTTP boundary on top.

Pilot on **Bank** to validate the design before fanning out. Each step is a separate commit; PR can bundle them.

- [ ] **P1.** Add Behat scenarios to `features/backoffice/bank/search.feature` covering: `?paginationMode=bogus` → 422, `?paginationMode[]=light` → 422, `?page=0` → 422, `?page=-1` → 422, `?limit=0` → 422, `?limit=999999` → 422, `?ids[]=invalid` → 422 (per P2 below — supersedes existing 200-asserting scenario), `?cursor=invalidbase64` → 400. Update the existing `?ids[]=invalid` scenario at lines 28-32 to assert 422 instead of 200. Run the suite; new scenarios must fail-the-right-way pre-refactor.
- [ ] **P2.** ~~Stakeholder decision~~ **LOCKED: 422 with violation list** (per §5 P2 decision). The existing Behat scenario at `features/backoffice/bank/search.feature:28-32` is updated in P1 to assert 422. Frontend impact must be called out in the migration PR description.
- [ ] **P3.** Create `Erpify\Shared\Application\Http\Search\SearchQuery` with the five universal properties + `#[Assert\…]` attributes. Add `toCriteria(): SearchCriteria` method (pure mapping). Unit-test the validator catches each failure mode from P1.
- [ ] **P4.** Create `Erpify\Backoffice\Bank\Application\Http\BankSearchQuery extends SearchQuery` with `?array $names`. Override `toCriteria(): BankSearchCriteria`.
- [ ] **P5.** Create `Erpify\Shared\Infrastructure\Http\Controller\AbstractSearchController` with the `buildResponse(PaginatedResult, list<string>)` template method (signature uses the Domain port from P0.4, not the concrete `Paginator`). Unit-test the envelope shape against a fixture `PaginatedResult`.
- [ ] **P6.** Add an exception listener (or extend whatever currently emits `JsonApiErrorBuilder` envelopes) that normalizes `ValidationFailedException` → 422 with `JsonApiErrorBuilder::fromViolations(...)`, and `InvalidArgumentException` from search infra → 400. Cover with a functional test.
- [ ] **P7.** Update `BankSearcher::search(BankSearchQuery $query): PaginatedResult` to convert DTO → `BankSearchCriteria` via `$query->toCriteria()`, then call `BankRepository::search($criteria)`.
- [ ] **P8.** Delete the `match`-based `page`/`limit` re-validation in `AbstractSearchRepository::getPaginatedResults`. The DTO is now the single validator. Restore `MAX_LIMIT` as a constant with a sane default (currently commented out at line 24) or move it onto the DTO via `#[Assert\LessThanOrEqual]`. **Recommend the DTO route** — caps become part of the public API contract.
- [ ] **P9.** Refactor `BankSearchController` to extend `AbstractSearchController` and use `#[MapQueryString] BankSearchQuery`. Delete the `try/catch BadRequestException`, the `tryFrom ?? throw`, and the `max(1, …)` clamp. Final controller ≤ ~25 lines including imports.
- [ ] **P10.** Run full Behat sweep + `make php.lint`. Confirm `search.feature` and the new failure-mode scenarios are green. Confirm `make php.stan` is clean for every touched file.
- [ ] **P11.** Document the pattern in `api/CLAUDE.md` (one short paragraph + a code skeleton) so the next 99 endpoints have a recipe to copy.

### Rollout to the other endpoints

Each subsequent entity is mechanical: Domain `<Entity>SearchCriteria` + Application `<Entity>SearchQuery` DTO + searcher signature update + controller stub. The model is "DTO + criteria + 12-line controller, no novel decisions per entity." If a given entity introduces a filter type not yet supported (e.g. date range), update the base `SearchQuery`/`SearchCriteria` once and reuse.

---

## 8. Acceptance criteria

The ADR is implementable and the pilot is "done" when:

1. `Erpify\Backoffice\Bank\Domain\Repository\BankRepository` imports **nothing** from `Erpify\…\Infrastructure\…`. (The `Symfony\Component\Uid\Uuid` import is out of scope per §6.3.)
2. `BankRepository::search()` accepts a Domain `BankSearchCriteria` and returns a Domain `PaginatedResult<Bank>`.
3. `BankSearchController` is ≤ ~25 lines including imports and contains no `try/catch`, no `tryFrom`, and no manual envelope assembly.
4. A request with `?paginationMode=bogus` returns 422 with a structured violation list (not 400, not a 500).
5. The abstract repository no longer re-validates `page`/`limit` — the DTO is the single source of truth.
6. Behat scenarios in `features/backoffice/bank/search.feature` pass, including the new failure-mode scenarios and the updated `?ids[]=invalid` → 422 scenario.
7. `make php.stan` and `make php.lint` are clean for every touched file.
8. A short recipe exists in `api/CLAUDE.md` for adding the next search endpoint.

---

## 9. Decision log

| Date | Status | Note |
| --- | --- | --- |
| 2026-04-29 | proposed | Drafted with Winston as a focused ADR (instead of the full `bmad-create-architecture` workflow). Awaiting Sergio's resolution of Q1–Q6 and approval to proceed with the pilot on Bank. |
| 2026-04-29 | accepted | Sergio accepted all six recommendations (Q1–Q6) and the P2 contract decision (422 for invalid `ids[]`). Sergio additionally flagged that `BankRepository.php` imports `Erpify\Shared\Infrastructure\Persistence\Paginator` — a Domain-purity violation that must be fixed. ADR expanded with **§6 Phase 0 — Domain purity prerequisite** introducing `SearchCriteria`, `PaginatedResult<T>`, `SearchCursor` Domain ports and moving `PaginationMode` to Domain. Q3 reasoning corrected (the previous "precedent already accepts the violation" argument is replaced by "the violation is being repaired"). Q4 strengthened (`BankSearcher` now owns DTO→criteria mapping, no longer anaemic). |
| 2026-04-29 | phase-0-complete | Phase 0 (P0.1–P0.10) landed across commits `15760b6`, `8bbb215`, `efbf209`, `28bfed7`, and `a8fa5bc`. **§8 acceptance #1 met:** `BankRepository` no longer imports anything from `Erpify\…\Infrastructure\…`. `Paginator implements PaginatedResult<T>` and `PaginatorCursorInterface extends SearchCursor` adopt the Domain ports without behavior change. `BankSearcher` is a temporary array→criteria adapter (Phase 1 P7 replaces with `BankSearchQuery $query`). Three known transition-state issues deferred to Phase 1: `assert()`-only validation in the adapter (closes via P3), `list<string>` element checks (closes via P3), and pre-existing `?ids[]=` 400 (closes via P5) — see `_bmad-output/implementation-artifacts/deferred-work.md`. Net Behat: +1 scenario fixed, 0 regressions. **Phase 1 (§7) is now unblocked.** |
