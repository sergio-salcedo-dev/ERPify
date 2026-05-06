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

## From spec-api-resource-normalizer-helper (review 2026-05-06)

> Context: review of branch `feat/api-resource-normalizer` surfaced findings against the **pre-existing dirty tree** that was uncommitted on `main` at baseline `4220b96` and carried into this branch. Those changes (Bank controller modernization: `MapRequestPayload`, `BankPostPayload`/`BankPutPayload`, removal of `BankInput`/`ValidationTrait`, `.feature` and functional-test edits) are **not** in this spec's scope but were visible in the diff and reviewed alongside the helper work. They need their own spec / commit.

### D4. `BankPutController` ValidationFailedException property path forces `'uuid'` for body-level violations
**Where:** `api/src/Backoffice/Bank/Infrastructure/Controller/BankPutController.php:43-46`. Field-level violations (name/shortName) get mislabeled as `'uuid'` errors in the JSON-API envelope.

**Why deferred:** Pre-existing controller behavior; carried forward into the dirty-tree refactor unchanged. Fixing it requires deciding whether path-id validation should produce `'id'` or `'uuid'` and unifying with `BankGetController` (which uses `'id'`). Out of scope for the normalizer refactor.

**Closure:** Address in the Bank-controller-modernization spec; pick one property path convention and apply consistently. Add a feature test asserting `name`/`shortName` violations carry their actual property path, not `'uuid'`.

---

### D5. `BankPostController::assertValidUpload` throws `ValidationFailedException` with no local catch
**Where:** `api/src/Backoffice/Bank/Infrastructure/Controller/BankPostController.php:71-87`. The exception bubbles past the controller; relies on `SearchExceptionListener` (or similar global handler) to map it to a 4xx envelope.

**Why deferred:** Pre-existing pattern. Whether this is acceptable depends on how the global exception listener formats `ValidationFailedException` — needs verification that the response shape matches `JsonApiErrorBuilder` envelopes used elsewhere in the same controller.

**Closure:** In the Bank-modernization spec, audit the `SearchExceptionListener::onValidationFailed` path and add a Behat scenario for "POST /banks with too-large file → 422 with envelope".

---

### D6. Multipart form with snake_case keys (`short_name`, `stored_object`) regresses to 422 / silently null
**Where:** `BankPostController.php:38-47`. `MapRequestPayload` deserializes into `BankPostPayload->shortName` (camelCase only). `MapUploadedFile(name: 'storedObject')` only accepts the camelCase key. Legacy clients using `short_name` / `stored_object` will hit NotBlank or get a silently-missing file.

**Why deferred:** Pre-existing dirty-tree change (the controller switched from manual parsing + snake_case fallback to attribute-based mapping). Whether this is a breaking change depends on real client behavior; needs a deliberate decision (alias, dual-key support, or accept the break).

**Closure:** In the Bank-modernization spec, decide the policy and either add `#[SerializedName]` aliases or document the breaking change in release notes. Add Behat scenarios covering both the camelCase and snake_case paths.

---

### D7. Path-id UUID validation moved into `BankFinder`/`BankUpdater` — cascade across other callers
**Where:** `BankGetController.php`, `BankPutController.php` (and by extension any other caller of `BankFinder::find`/`BankUpdater::update`). The previous `Assert\Uuid()` controller-level validation was removed; validation responsibility now lives in the application layer.

**Why deferred:** Pre-existing dirty-tree shift. Other callers (e.g. `BankDeleteController`, internal services) need to be audited to ensure they all handle `ValidationFailedException` consistently and that the property path conveys the right field name.

**Closure:** In the Bank-modernization spec, list every caller of `BankFinder` / `BankUpdater` / `BankDeleter`, verify each catches `ValidationFailedException` (or is OK letting it bubble), and standardize the property path.

---

### D8. `BankPutController` retains `ValidatorInterface` injection and `MessengerExceptionInterface` `@throws` after refactor
**Where:** `BankPutController.php`. The Messenger throw declaration is technically still valid (BankUpdater dispatches via Messenger), but the documentation surface should be re-validated against the actual call path now that the controller body is shorter and intent is clearer.

**Why deferred:** Pre-existing PHPDoc; not introduced by the normalizer refactor. Trim or re-document as part of the broader Bank-modernization cleanup.

**Closure:** Decide whether `MessengerExceptionInterface` should propagate as a 500 (current behavior) or be caught and translated; document explicitly.

---

### D9. PHP-CS-Fixer / lint-time only: duplicated JSON-API envelope construction in `BankPostController`
**Where:** `BankPostController.php` `InvalidImageException` handler builds an envelope inline; pattern repeats elsewhere in the module.

**Why deferred:** Style / DRY concern. Not a correctness issue. Better tackled when more controllers exhibit the same pattern (rule of three).

**Closure:** Extract `JsonApiErrorBuilder::singleFieldEnvelope($field, $message, int $status)` once a third call site appears.

---

## Conventions

- Append-only. Do not edit existing entries; if a deferred item is closed, mark it with a strikethrough and a closure note rather than deleting.
- New entries get the next `D<n>` identifier (continue numbering across entire file, do not restart per spec).
- Each entry: `### Dn. Short title` → **Where**, **Impact / Status**, **Why deferred**, **Closure**.
