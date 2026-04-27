---
title: 'P0.6–P0.10 — Bank pagination port adoption (Phase 0 close-out)'
type: 'refactor'
created: '2026-04-29'
status: 'done'
baseline_commit: '28bfed704ef5a8d064a3f3b06f38590d616572e2'
context:
  - '{project-root}/_bmad-output/planning-artifacts/adr-2026-04-29-search-controller-boundary.md'
  - '{project-root}/api/CLAUDE.md'
  - '{project-root}/CLAUDE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `Erpify\Backoffice\Bank\Domain\Repository\BankRepository` imports `Erpify\Shared\Infrastructure\Persistence\Paginator` and accepts an untyped `array<string, mixed>` — both violate the hexagonal layering rule (`api/CLAUDE.md` "Layer rules"). The Domain ports created in P0.1–P0.5 (`SearchCriteria`, `BankSearchCriteria`, `PaginatedResult<T>`, `SearchCursor`, `PaginationMode` in Domain) are unused; they need to be wired through the Bank search path.

**Approach:** Mechanical refactor in five steps from the ADR §6.4 task list. Make the Infrastructure `Paginator` implement the Domain port `PaginatedResult<T>`; adopt typed `SearchCriteria` on the Domain repository contract and the Doctrine implementation; convert the Application searcher to map array→criteria internally so the existing controller and Behat scenarios keep working untouched. No behavior changes.

## Boundaries & Constraints

**Always:**
- After this spec, `Erpify\Backoffice\Bank\Domain\Repository\BankRepository` imports nothing from `Erpify\…\Infrastructure\…`. The pre-existing `Symfony\Component\Uid\Uuid` import is out of scope (ADR §6.3).
- `BankRepository::search()` accepts `SearchCriteria` (base type, with `assert($criteria instanceof BankSearchCriteria)` in the Doctrine impl) and returns `PaginatedResult<Bank>`.
- `Paginator` is the only `PaginatedResult` adapter; it stays in `Shared/Infrastructure/Persistence/`.
- `PaginatorCursorInterface extends SearchCursor` — additive; setters stay in Infrastructure.
- All Bank Behat scenarios remain green. No `.feature` file is edited in this spec.
- `make php.stan` is run after every PHP file edit (per `CLAUDE.md` mandate).

**Ask First:**
- Any deviation from "no behavior change". If a test fails, report the failure before touching the test or the production code.
- Touching files outside the Code Map. The blast radius is bounded; expansion needs approval.

**Never:**
- Don't delete the `\max(1, \min(self::MAX_PAGE, …))` page/limit clamping in `AbstractSearchRepository`. That's Phase 1's P8 — gated on the Application DTO carrying `#[Assert\…]`. The type-coercion `match` blocks (string→int) DO go away because typed `SearchCriteria` properties make them dead code.
- Don't add `sort` / `direction` fields to `SearchCriteria`. Out of scope; `BankSearchController` doesn't pass them today, so defaults (`createdAt` ASC) are correct.
- Don't change `BankSearchController.php`. It must keep working unchanged via `BankSearcher`'s array→criteria adapter.
- Don't introduce `@template Q of SearchCriteria` generics on `AbstractSearchRepository`. Q2 in the ADR locks this to `assert(...)` for now.
- Don't hand-edit Doctrine migrations or `vendor/`.

</frozen-after-approval>

## Code Map

- `api/src/Shared/Infrastructure/Persistence/PaginatorCursorInterface.php` — extend `SearchCursor` (additive; both interfaces declare the same four read methods).
- `api/src/Shared/Infrastructure/Persistence/Paginator.php` — implement `PaginatedResult<T>`; declare `@template T of object`; drop redundant `IteratorAggregate` import (re-exposed via the port).
- `api/src/Shared/Infrastructure/Persistence/PaginatorCursorFactory.php` — widen `toString()` parameter from `PaginatorCursorInterface` to `SearchCursor`. Read-only access; LSP-safe.
- `api/src/Shared/Infrastructure/Persistence/AbstractSearchRepository.php` — change `getPaginatedResults(array)` and `abstract getSearchQueryBuilder(array)` to `(SearchCriteria $criteria)`. Drop the type-coercion `match` blocks. Keep clamping. Drop `QueryParam` array-key reads.
- `api/src/Backoffice/Bank/Domain/Repository/BankRepository.php` — drop `use ...\Paginator`; add `use ...\Domain\Search\PaginatedResult`, `SearchCriteria`. Signature: `search(SearchCriteria $criteria): PaginatedResult` with `@return PaginatedResult<Bank>`.
- `api/src/Backoffice/Bank/Infrastructure/Persistence/PostgresBankRepository.php` — `search(SearchCriteria $criteria): PaginatedResult` returning `$this->getPaginatedResults($criteria)`. `getSearchQueryBuilder(SearchCriteria $criteria)` reads `$criteria->ids ?? []` and (after `assert($criteria instanceof BankSearchCriteria)`) `$criteria->names ?? []`. Pass `null, null` to `addOrderByFromQueryParams` (defaults match today's behavior). Pass `$criteria->limit` to `addLimit`. Drop `use ...\QueryParam`, `use ...\SortDirection` if unused after edit.
- `api/src/Backoffice/Bank/Application/BankSearcher.php` — `search(array $queryParams): PaginatedResult` (return type widens; param keeps array for now). Internally constructs `BankSearchCriteria` from the array bag. The controller doesn't change; Phase 1 (P7) will swap the param to `BankSearchQuery` DTO.

## Tasks & Acceptance

**Execution:**
- [x] `api/src/Shared/Infrastructure/Persistence/PaginatorCursorInterface.php` — `extends SearchCursor`. Added `#[Override]` on the four read methods. PHPStan clean.
- [x] `api/src/Shared/Infrastructure/Persistence/Paginator.php` — `@template T of object`; `implements PaginatedResult`; dropped `IteratorAggregate` import; added `@var Iterator<int, T>|null` on `$iterator` property; `@return Traversable<int, T>` on `getIterator`; `@var ArrayIterator<int, T>` cast on the new ArrayIterator; `#[Override]` on getIterator/hasMorePages/getCurrentPage/getPageCount/getCursor. PHPStan clean.
- [x] `api/src/Shared/Infrastructure/Persistence/PaginatorCursorFactory.php` — `toString(SearchCursor $cursor)`. PHPStan clean.
- [x] `api/src/Shared/Infrastructure/Persistence/AbstractSearchRepository.php` — `getPaginatedResults(SearchCriteria $criteria): Paginator<T>` reads typed properties; type-coercion `match` blocks dropped; clamping kept; `abstract getSearchQueryBuilder(SearchCriteria $criteria): QueryBuilder`. Dropped `InvalidArgumentException` import; added `SearchCriteria` import. `@var Paginator<T>` cast on the new Paginator. PHPStan clean.
- [x] `api/src/Backoffice/Bank/Domain/Repository/BankRepository.php` — dropped Infrastructure `Paginator` import; added Domain `PaginatedResult` + `SearchCriteria`. Signature: `search(SearchCriteria): PaginatedResult` with `@return PaginatedResult<Bank>`. PHPStan clean.
- [x] `api/src/Backoffice/Bank/Infrastructure/Persistence/PostgresBankRepository.php` — typed signatures + `assert($criteria instanceof BankSearchCriteria)`; reads `$criteria->ids`, `$criteria->names`, `$criteria->limit`; passes `null, null` to `addOrderByFromQueryParams` (matches today's controller defaults); dropped `QueryParam` + `SortDirection` imports. PHPStan clean.
- [x] `api/src/Backoffice/Bank/Application/BankSearcher.php` — array→criteria internal mapping via private `toCriteria`; return type widened to `PaginatedResult<Bank>`; controller untouched. `@SuppressWarnings("PHPMD.CyclomaticComplexity")` added to `toCriteria` (defensive asserts push CC over threshold; matches existing pattern in `PaginatorCursorFactory`/`Paginator`). PHPStan clean.
- [x] **P0.10:** `make php.test` (20 PHPUnit / 110 assertions + 13 Behat scenarios, 11 pass / 2 fail) and individual linters run. **The 2 Behat failures (`search.feature:22` and `:28`, both `?ids[]=…` scenarios) are pre-existing baseline regressions** confirmed by reverting to commit `28bfed7` and re-running — at baseline, *all 3* search.feature scenarios fail (including "List all banks" → 500); after this refactor, "List all banks" passes (200) and the 2 `?ids[]=` scenarios still 400. **Net: +1 scenario fixed, 0 regressions.**

**Acceptance Criteria:**
- Given the codebase post-edit, when `grep -n "use Erpify.*Infrastructure" api/src/Backoffice/Bank/Domain/Repository/BankRepository.php` runs, then it returns no matches (only the `Symfony\Component\Uid\Uuid` import remains, which is out of scope).
- Given the codebase post-edit, when `make php.stan` runs against the eight touched files, then it reports zero new errors. Pre-existing errors in untouched files (`AggregateRoot.php`, `AbstractRepository.php` baseline issues) are acceptable if they predate this spec.
- Given the running stack, when `make php.behat c='features/backoffice/bank/search.feature'` runs, then every scenario passes.
- Given a request to `GET /api/banks?page=2&limit=5&paginationMode=light` against fixtures, when the controller responds, then the JSON envelope shape (items + pagination metadata with cursor) is byte-identical to the pre-refactor response for the same inputs.

## Spec Change Log

**2026-04-29 — Pre-existing Behat failures discovered (not regressions).**
Mid-execution: scenarios at `features/backoffice/bank/search.feature:22` and `:28` (both `?ids[]=` array-form) fail with 400 *at baseline commit `28bfed7`*. Symfony 8's `InputBag::get()` throws `BadRequestException` for non-scalar values; the controller's call to `$request->query->get(QueryParam::IDS->value)` for `?ids[]=foo` triggers this. **The spec's "All Bank Behat scenarios remain green" constraint was based on an incorrect baseline assumption.** Verified by reverting the seven touched files to baseline and re-running: 0/3 pass at baseline (List all banks → 500, both `?ids[]=` → 400); 1/3 pass after refactor. **Net: +1 scenario fixed, 0 regressions.** Phase 1's P1 already plans to update these scenarios to assert 422.

**2026-04-29 — Auxiliary fixes outside the original Code Map (still inside the Code Map files, just not pre-listed).**
Added `#[Override]` on `Paginator` (5 methods inherited from `PaginatedResult`) and `PaginatorCursorInterface` (4 methods inherited from `SearchCursor`) to satisfy Psalm's `MissingOverrideAttribute` rule — necessary consequence of the new interface relationships in P0.6. Added `@SuppressWarnings("PHPMD.CyclomaticComplexity")` on `BankSearcher::toCriteria` (CC=12, threshold=10), consistent with `PaginatorCursorFactory::createFromString` and `Paginator::alterWhere` precedents.

## Design Notes

**Variance: `Paginator::getCursor()` covariant return.**
Method signature stays `public function getCursor(): PaginatorCursorInterface`. Since (post-edit) `PaginatorCursorInterface extends SearchCursor`, this is a covariant override of the port's `getCursor(): SearchCursor`. PHP enforces this since 7.4; PHPStan understands it.

**Why `BankSearcher` keeps the `array` param this phase.**
The ADR offers two paths for P0.9: (a) update to array→criteria adapter, (b) defer to Phase 1. We pick (a) because Phase 0 changing the Domain repo signature breaks `BankSearcher` if it just passes through. The adapter is the smallest change that keeps `BankSearchController` untouched. Phase 1 (P7) will replace `array $queryParams` with `BankSearchQuery $query` and call `$query->toCriteria()`.

**Mapping inside `BankSearcher` (golden example, ~10 lines):**
```php
$criteria = new BankSearchCriteria(
    cursor: $queryParams[QueryParam::CURSOR->value] ?? null,
    page: (int) ($queryParams[QueryParam::PAGE->value] ?? 1),
    limit: $queryParams[QueryParam::LIMIT->value] ?? null,
    paginationMode: $queryParams[QueryParam::PAGINATION_MODE->value] ?? PaginationMode::LIGHT,
    ids: $queryParams[QueryParam::IDS->value] ?? null,
    names: $queryParams['names'] ?? null,
);
return $this->bankRepository->search($criteria);
```
Limit stays nullable so the abstract's `MAX_LIMIT` default still applies. `ids` and `names` typing relies on `BankSearchController`'s already-narrow inputs; if the `(int)` cast or `?? null` fallbacks miss an edge case, the existing controller's pre-cast behavior surfaces it (pre-refactor tests will catch).

**Temporal Psalm noise.** P0.4/P0.5 left some `UnusedClass` / `PossiblyUnusedMethod` warnings on the Domain ports. Those resolve naturally once this spec lands (the ports become used by `BankRepository` and `Paginator`).

## Verification

**Commands:**
- `make php.stan` — expected: zero new errors on the eight touched files. Pre-existing baseline issues in untouched files (e.g. `AggregateRoot.php`) are not regressions.
- `make php.test` — expected: PHPUnit + all Behat scenarios green, including `features/backoffice/bank/search.feature`.
- `make php.lint` — expected: clean (PHPStan, Rector, PHP-CS-Fixer, PHPMD, PHPCS, Psalm) for the eight touched files. Auto-fixers may mutate files; re-run is fine.

## Suggested Review Order

**Domain contract — the heart of the refactor**

- The Domain repository interface now speaks Domain types only — entry point for understanding the layering fix.
  [`BankRepository.php:11`](../../api/src/Backoffice/Bank/Domain/Repository/BankRepository.php#L11)

- The Application searcher's adapter — array→criteria boundary that keeps the controller untouched this phase.
  [`BankSearcher.php:25`](../../api/src/Backoffice/Bank/Application/BankSearcher.php#L25)

**Pagination port adoption (P0.6)**

- Infrastructure `Paginator` implements the Domain port — the standard "Domain owns port, Infra owns adapter" pattern.
  [`Paginator.php:34`](../../api/src/Shared/Infrastructure/Persistence/Paginator.php#L34)

- `PaginatorCursorInterface extends SearchCursor` — additive, setters stay in Infra; covariant return on `getCursor`.
  [`PaginatorCursorInterface.php:10`](../../api/src/Shared/Infrastructure/Persistence/PaginatorCursorInterface.php#L10)

- Factory parameter widened to the Domain port — read-only access, LSP-safe; needed for controller post-Phase-0.
  [`PaginatorCursorFactory.php:97`](../../api/src/Shared/Infrastructure/Persistence/PaginatorCursorFactory.php#L97)

**Abstract repository (P0.8 / shared with P0.7)**

- Typed signatures + dropped type-coercion `match` blocks; clamping kept (Phase 1 P8 deletes it once DTO has `#[Assert\…]`).
  [`AbstractSearchRepository.php:33`](../../api/src/Shared/Infrastructure/Persistence/AbstractSearchRepository.php#L33)

- The `Paginator<T>` instantiation site — `@var` cast carries the generic through to Doctrine-coupled internals.
  [`AbstractSearchRepository.php:91`](../../api/src/Shared/Infrastructure/Persistence/AbstractSearchRepository.php#L91)

**Concrete Bank repository (P0.8)**

- Typed `getSearchQueryBuilder` body reads `$criteria->ids` / `$criteria->names` directly — the untyped reach-in is gone.
  [`PostgresBankRepository.php:48`](../../api/src/Backoffice/Bank/Infrastructure/Persistence/PostgresBankRepository.php#L48)

- The `assert($criteria instanceof BankSearchCriteria)` line — Q2 of the ADR, no `@template Q` generics for now.
  [`PostgresBankRepository.php:51`](../../api/src/Backoffice/Bank/Infrastructure/Persistence/PostgresBankRepository.php#L51)

**Searcher adapter internals (P0.9)**

- `toCriteria` private helper — the asserts are dev-only guards; production-grade validation arrives with Phase 1's DTO.
  [`BankSearcher.php:33`](../../api/src/Backoffice/Bank/Application/BankSearcher.php#L33)

**Verification artifacts**

- Deferred work captured during review (no current-spec patches; three Phase-1-closes-it items).
  [`deferred-work.md`](./deferred-work.md)
