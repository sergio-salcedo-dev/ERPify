# Deferred Work

Findings surfaced during work but consciously postponed. Each entry: **what**, **where it came from**, **why deferred**, **what closes it**.

## From spec-p0-6-to-10-bank-pagination-port (review 2026-04-29)

### D1. `BankSearcher::toCriteria` `assert()`-only validation regresses `?limit=abc` from 400 to 200 in prod
**Where:** `api/src/Backoffice/Bank/Application/BankSearcher.php:42-47`. `\assert()` is a no-op in production (`zend.assertions=-1`). At baseline, `AbstractSearchRepository::getPaginatedResults` had `match (true) { default => throw new InvalidArgumentException }` arms that ran in prod and were caught by the controller as 400. The Phase 0 refactor moves the conversion into `toCriteria` and gates it with `assert`, which silently casts `(int) 'abc' = 0` in prod, then the abstract clamps to 1.

**Impact at HTTP boundary:**
- `?page=abc` — unchanged (controller's `getInt` already coerces string→int).
- `?limit=abc` — old: 400. New (prod): 200 with 1 row. New (dev): 500 (AssertionError).
- Internal callers passing typed-violating arrays directly to `BankSearcher::search()` — same regression.

**Why deferred:** Phase 0's spec explicitly drops the `match` blocks because typed `SearchCriteria` properties make them dead code at the abstract layer. The new boundary at `BankSearcher::toCriteria` was not given equivalent runtime validation. Phase 1 P3 introduces `Erpify\Shared\Application\Http\Search\SearchQuery` with `#[Assert\Positive]` / `#[Assert\LessThanOrEqual]` on `page` / `limit`, validated by Symfony's `#[MapQueryString]` argument resolver. That replaces the entire `BankSearcher::toCriteria` adapter (Phase 1 P7 swaps the param to `BankSearchQuery $query`).

**Closure:** Phase 1 P3 + P7 + P9 land. Add a Behat scenario `?limit=abc → 422` to `features/backoffice/bank/search.feature` to lock in the post-Phase-1 contract.

---

### D2. `BankSearcher::toCriteria` does not enforce `list<string>` element type for `ids` / `names`
**Where:** `api/src/Backoffice/Bank/Application/BankSearcher.php:49-52`. The `@var list<string>|null` annotation is a PHPDoc-only contract. At runtime, `array_values($ids)` reindexes but does not coerce element types — a caller passing `['ids' => [123, null, ['nested']]]` produces a `BankSearchCriteria` with the same shape, which downstream `addWhereIdsIn` → `sanitizeArray` partially handles (filters empties/nulls) but does not type-validate. A `['nested']` element would reach Doctrine's `IN (:ids)` binding and fail with `ConversionException` → 500.

**Why deferred:** As with D1, Phase 1 P3 + P4 introduce `BankSearchQuery extends SearchQuery` with `#[Assert\All([new Assert\Uuid()])]` on `?array $ids`. Symfony validates each element before the DTO ever reaches `BankSearcher`, returning 422 with structured violations per ADR §2.1.

**Closure:** Phase 1 P3, P4. Add Behat scenarios `?ids[]=not-a-uuid → 422` (already planned in P1).

---

### D3. `?ids[]=…` (array-form) returns 400 (Symfony `InputBag::get` rejects non-scalar)
**Where:** `api/src/Backoffice/Bank/Infrastructure/Controller/BankSearchController.php:57`. The controller calls `$request->query->get(QueryParam::IDS->value)` (returns scalar only). `?ids[]=foo` is non-scalar → `BadRequestException` → 400.

**Status:** Pre-existing failure at baseline `28bfed7`. Verified by reverting all seven Phase 0 files and re-running `make php.behat c='features/backoffice/bank/search.feature'` — both `?ids[]=` scenarios fail with 400 at baseline too. Not introduced by Phase 0.

**Why deferred:** Phase 1 P5 introduces `#[MapQueryString] BankSearchQuery $query` with `?array $ids` parsed via Symfony's argument resolver, which handles `?ids[]=` natively. Phase 1 P1 explicitly updates the existing `?ids[]=invalid → 200` scenario to assert 422 instead.

**Closure:** Phase 1 P1, P5. Frontend impact must be flagged in the Phase 1 PR description (per ADR §5 P2 decision).

---

## Conventions

- Append-only. Do not edit existing entries; if a deferred item is closed, mark it with a strikethrough and a closure note rather than deleting.
- New entries get the next `D<n>` identifier (continue numbering across entire file, do not restart per spec).
- Each entry: `### Dn. Short title` → **Where**, **Impact / Status**, **Why deferred**, **Closure**.
