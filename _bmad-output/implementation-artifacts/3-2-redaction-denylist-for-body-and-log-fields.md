# Story 3.2: Redaction denylist for body and log fields

Status: done

Epic: 3 — Safe Bodies & Resilient Listener
Story Key: `3-2-redaction-denylist-for-body-and-log-fields`

## Story

As a security reviewer,
I want a redaction denylist applied to both the Problem Details body extensions and the structured-log record context,
so that sensitive field names carried in `DomainException::context()` (e.g. `password`, `token`) never reach an error response or a log line on any code path.

## Acceptance Criteria

1. **A new final class `Erpify\Shared\Application\Problem\RedactionDenylist` (file `api/src/Shared/Application/Problem/RedactionDenylist.php`) lists the canonical denylist keys** in a single `public const array KEYS` constant. The constant value is exactly:

   ```php
   public const array KEYS = [
       'password',
       'token',
       'secret',
       'authorization',
       'cookie',
       'ssn',
       'iban',
   ];
   ```

   - Order is meaningful only for human readability (most-common first); equality testing is set-based (FR34).
   - All entries MUST be lowercase ASCII (assert via a test that iterates `KEYS` and pins `\strtolower($k) === $k && \mb_check_encoding($k, 'ASCII')`).
   - The class is `final` (NOT `final readonly` — no instance state) with `declare(strict_types=1);` and a `private function __construct() {}` to forbid instantiation (it's a static-only utility, mirroring how `RESERVED_KEYS` lives as a constant on `ProblemDetailsFactory`).
   - The class file contains zero `use` statements referencing Symfony, Doctrine, Psr\Http, Messenger, or any HTTP namespace (mirrors the `Shared/Application/` framework-free discipline established by Story 1.2's `ProblemDetails`).

2. **`RedactionDenylist::filter(array $input): array` strips denylist keys.** Signature:

   ```php
   /**
    * @param array<string, mixed> $input
    * @return array<string, mixed>
    */
   public static function filter(array $input): array
   ```

   - **Strip semantics, not sentinel.** A denylisted key is REMOVED from the returned array — its value is NOT replaced with `'[redacted]'`. (Rationale below in Dev Notes; pin via test that asserts `\array_key_exists('password', $filtered) === false`.)
   - **Exact-key match, case-insensitive ASCII.** `'password'`, `'Password'`, `'PASSWORD'`, `'pAsSwOrD'` ALL strip. `' password'` (leading space), `'password '` (trailing space), `'my_password_field'` (substring), `'pass'` (prefix), and `'PÄSSWORD'` (non-ASCII) DO NOT strip — pinned by parameterised tests.
   - **Single-level only.** Nested arrays are NOT recursively scanned; `['user' => ['password' => 'x']]` returns `['user' => ['password' => 'x']]` unchanged. This is a deliberate scope limit (Dev Notes explain).
   - **Preserves original key casing for surviving keys.** `['Email' => 'a@b']` returns `['Email' => 'a@b']` (not `'email'`).
   - **Preserves declaration order of surviving keys.** Filter iterates `$input` in PHP-array insertion order and appends survivors in the same order.
   - Implementation: precompute `$denied = array_flip(KEYS)` (lowercase canonical) and check `isset($denied[\strtolower($key)])` per entry. PHPStan-narrow the return type as `array<string, mixed>`.
   - Non-string keys (numeric-coerced ints) are NOT subject to the filter — they pass through unchanged. Pin with one test row.

3. **`ProblemDetailsFactory::redactKeys(array): array` is filled with `RedactionDenylist::filter(...)`.** The seam is currently a no-op pass-through at `api/src/Shared/Application/Problem/ProblemDetailsFactory.php:321`; this story replaces its body with `return RedactionDenylist::filter($context);`. NO change to the seam's signature, visibility (`private`), or call sites. The existing call site in `buildExtensions()` (line ~286) — `$context = $this->redactKeys($context);` — runs AFTER the `RESERVED_KEYS` strip and BEFORE the whitelist-or-drop loop. Order is load-bearing: a denylist key carrying a `JsonSerializable` value would otherwise survive the whitelist step. Pin via test (AC #6 row "DomainException context including a denylisted key with a JsonSerializable value produces a body with NO denylist key").

4. **`ExceptionResponder` applies the same filter to its log-record context defensively.** The listener's `buildLogContext()` (`api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php:123-138`) returns a fixed-shape map of 8 PSR-3 fields. None of those fields are denylist-named TODAY (`instance`, `correlation_id`, `type`, `status`, `exception_class`, `exception_message`, `request_uri`, `request_method`), so the filter is a no-op pass-through at runtime. The defense-in-depth wiring is required by NFR12 (and pinned by AC #11 source-text inspection). Implementation: in `buildLogContext`, change the bare `return [...];` to `return RedactionDenylist::filter([...]);` — single-site edit. Add the import `use Erpify\Shared\Application\Problem\RedactionDenylist;` (alphabetic position after the existing `Erpify\Shared\Application\Problem\ProblemDetailsFactory` line). The change does NOT alter the existing 8-field shape (no current key is denylisted) so all 33 existing `ExceptionResponderTest` tests + 14 `ExceptionResponderFunctionalTest` tests + 46 Behat scenarios stay green byte-for-byte.

5. **`DomainException::context()` carrying any denylisted key (case-insensitive) produces a body whose `extensions` does NOT contain that key.** Pin via parameterised test that iterates EVERY entry in `RedactionDenylist::KEYS` and, for each, builds a `DomainException` subclass whose `context()` returns `[<key> => 'sensitive', 'safe' => 'value']`, calls `fromThrowable()`, and asserts:
   - `\array_key_exists($key, $problemDetails->extensions) === false`
   - `\array_key_exists('safe', $problemDetails->extensions) === true`
   - `$problemDetails->extensions['safe'] === 'value'`
   - The encoded JSON body (`\json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)`) does NOT contain the substring `'sensitive'` ANYWHERE (defense-in-depth — even if the key escapes by some other path, the value should not appear).

6. **Case-insensitivity is exhaustive across the four canonical casings per key.** The parameterised test in AC #5 also iterates four casings per key (`password`, `Password`, `PASSWORD`, `pAsSwOrD` for the `password` row; analogous for the other six) and asserts strip behavior for ALL of them. Total parameterised rows: `count(KEYS) * 4 = 28`.

7. **Non-strip cases are pinned: substring, prefix, suffix, nested, non-ASCII Unicode.** A separate `#[DataProvider]`-driven test asserts these inputs DO NOT strip:
   - `'my_password'` (substring) → present in body
   - `'password_hash'` (substring/prefix) → present in body
   - `'pass'` (prefix-only of `'password'`) → present in body
   - `' password'` (leading whitespace) → present in body
   - `'password '` (trailing whitespace) → present in body
   - `'PÄSSWORD'` (non-ASCII case fold) → present in body
   - `['user' => ['password' => 'x']]` (nested) → present in body, unchanged
   - `[42 => 'x']` (numeric key after PHP-coerce) → present in body, unchanged (single test row)

8. **CI gate against denylist-key drift.** A unit test on `RedactionDenylistTest` asserts `\count(self::denylistKeyProvider()) === \count(RedactionDenylist::KEYS) * 4` (every key has exactly four casing rows) AND a separate test asserts `\count(RedactionDenylist::KEYS) >= 7` (the seven canonical keys per FR34) AND that the seven canonical keys are all present (`\in_array('password', KEYS, true)`, etc.). This means: adding a key to `KEYS` without adding the four casing rows to the data provider FAILS the count assertion in CI; removing a canonical key fails the membership assertion. The failure message MUST mention "Add four casing rows to denylistKeyProvider for the new key" (NFR8 anchor).

9. **Log path coverage — defensive filter is invoked.** A unit test on `ExceptionResponderTest` asserts via source-text inspection that `RedactionDenylist::filter(` is called within the `buildLogContext` method body — mirrors the static-text-inspection pattern used by Story 3.1's `testDevEnvironmentDebugPreviousChainCycleGuardIsImplementedViaSplObjectId` (the runtime path is a no-op for the canonical 8-field shape, so behavioral assertion would be vacuous; source inspection pins the wiring). The test reads `(new \ReflectionClass(ExceptionResponder::class))->getMethod('buildLogContext')->getStartLine()` / `getEndLine()`, slices the source file, and asserts the slice contains `'RedactionDenylist::filter('`.

10. **NEW unit tests added to `ProblemDetailsFactoryTest`.** Add to `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php`:
    1. `testFactoryStripsDenylistedContextKeysFromBodyExtensions` (parameterised over the 28 casing rows from AC #5–#6) — env `'prod'`; for each row, assert the strip + the `safe` survival + the JSON body does not contain `'sensitive'`.
    2. `testFactoryDoesNotStripNonDenylistKeys` (parameterised over the 8 non-strip rows from AC #7) — env `'prod'`; assert each key survives the redaction step.
    3. `testFactoryStripsDenylistedKeyEvenWhenValueIsJsonSerializable` — env `'prod'`; throw a `DomainException` whose `context()` is `['password' => new class implements JsonSerializable { public function jsonSerialize(): mixed { return 'leaked-secret'; } }]`; assert the encoded body does not contain `'leaked-secret'` (proves redaction runs BEFORE the `JsonSerializable` whitelist check at `ProblemDetailsFactory.php:291-294`).
    4. `testFactoryStripsDenylistedKeyEvenWhenValueIsArray` — env `'prod'`; throw a `DomainException` whose `context()` is `['authorization' => ['scheme' => 'Bearer', 'value' => 'abc']]`; assert `'authorization'` is absent and the encoded body does not contain `'Bearer'` or `'abc'`.
    5. `testFactoryRedactionDoesNotApplyRecursivelyToNestedArrays` — env `'prod'`; throw a `DomainException` whose `context()` is `['user' => ['password' => 'sensitive', 'name' => 'alice']]`; assert `extensions['user']` is `['password' => 'sensitive', 'name' => 'alice']` UNCHANGED. Documents the single-level scope limit; future story may extend to recursion.
    6. `testFactoryRedactionPreservesDeclarationOrderOfSurvivors` — env `'prod'`; throw a `DomainException` whose `context()` is `['a' => 1, 'password' => 'x', 'b' => 2, 'token' => 'y', 'c' => 3]`; assert `array_keys($problemDetails->extensions) === ['a', 'b', 'c']` exactly.
    7. `testFactoryRedactionAppliesAfterReservedKeyStrip` — env `'prod'`; throw a `DomainException` whose `context()` is `['type' => 'spoofed', 'password' => 'x', 'safe' => 'v']`; assert `'type'` is absent (RESERVED_KEYS strip) AND `'password'` is absent (denylist strip) AND `'safe'` is present. Documents that the two strip layers compose correctly.
    8. `testFactoryRedactionInDevEnvAlsoAppliesToBodyExtensions` — env `'dev'` (so the `debug` extension is appended); throw a `DomainException` whose `context()` is `['password' => 'x', 'safe' => 'v']`; assert `extensions` has keys `['safe', 'debug']` (no `'password'`). Confirms redaction is env-agnostic at the body layer.

    **Total NEW unit tests on `ProblemDetailsFactoryTest`: 8 method definitions** (some parameterised — actual reported test count after `#[DataProvider]` expansion: 28 + 8 + 6 = 42 individual assertions, give or take, depending on PHPUnit's data-provider counter. Final count is whatever PHPUnit reports; pin the CI total via AC #18.)

11. **NEW unit tests added to `RedactionDenylistTest` (NEW FILE).** Path `api/tests/Unit/Shared/Application/Problem/RedactionDenylistTest.php`:
    1. `testKeysConstantContainsTheSevenCanonicalDenylistKeys` — `assertSame(['password', 'token', 'secret', 'authorization', 'cookie', 'ssn', 'iban'], RedactionDenylist::KEYS)`. Pin the FR34 baseline.
    2. `testKeysAreAllLowercaseAscii` — for each key in `KEYS`, assert `\strtolower($key) === $key && 1 === \preg_match('/\A[a-z][a-z0-9_-]*\z/', $key)`.
    3. `testFilterStripsExactKeyMatchCaseInsensitive` (parameterised over 28 rows: 7 keys × 4 casings) — assert `\array_key_exists($key, RedactionDenylist::filter([$key => 'sensitive', 'safe' => 'v']))` is `false` AND `'safe'` is preserved.
    4. `testFilterDoesNotStripSubstringPrefixOrSuffix` (parameterised over the 8 non-strip rows from AC #7) — assert each key survives.
    5. `testFilterIsSingleLevelAndDoesNotRecurse` — `assertSame(['user' => ['password' => 'x']], RedactionDenylist::filter(['user' => ['password' => 'x']]))`.
    6. `testFilterPreservesNumericKeys` — `assertSame([0 => 'a', 1 => 'b'], RedactionDenylist::filter([0 => 'a', 1 => 'b']))`.
    7. `testFilterPreservesDeclarationOrder` — `assertSame(['a' => 1, 'b' => 2, 'c' => 3], RedactionDenylist::filter(['a' => 1, 'password' => 'x', 'b' => 2, 'token' => 'y', 'c' => 3]))`.
    8. `testFilterReturnsEmptyArrayWhenAllInputKeysAreDenylisted` — `assertSame([], RedactionDenylist::filter(['password' => 'x', 'TOKEN' => 'y', 'Secret' => 'z']))`.
    9. `testFilterPreservesValueIdentityForSurvivingKeys` — `$obj = new \stdClass(); assertSame(['x' => $obj], RedactionDenylist::filter(['x' => $obj]))` — strict identity, not structural equality.
    10. `testCannotInstantiate` — `(new \ReflectionClass(RedactionDenylist::class))->getConstructor()` is `private`; `\Reflection::getModifierNames` includes `'private'`. Documents the static-only utility shape.
    11. `testDataProviderRowCountMatchesKeysCountTimesFour` — `assertCount(\count(RedactionDenylist::KEYS) * 4, self::denylistCasingProvider())` — the NFR8 CI gate against drift.

    **Total NEW unit tests on `RedactionDenylistTest`: 11 method definitions** (PHPUnit-counted total after data-provider expansion: ~50 rows; final count whatever PHPUnit reports).

12. **NEW unit test added to `ExceptionResponderTest` for log-side wiring.** Add to `api/tests/Unit/Shared/Infrastructure/Http/EventListener/ExceptionResponderTest.php`:
    - `testListenerLogContextBuilderInvokesRedactionDenylistFilter` — uses reflection to slice the `buildLogContext` method's source text out of `ExceptionResponder.php`, asserts the slice contains `'RedactionDenylist::filter('`. Pins AC #4 + AC #9 (the defensive filter is wired even though it's a runtime no-op for the canonical 8-field shape).

    **Total NEW unit tests on `ExceptionResponderTest`: 1 method.**

13. **NEW Behat scenario added.** Path `api/features/shared/error_contract/redaction_denylist.feature`. ONE scenario:
    - **Scenario:** `Denylisted keys in DomainException context are stripped from body extensions`
    - Add a fixture controller route `/api/test/_throw-denylisted-context` (in the existing test-only routes — co-locate with the Story 1.4 `_throw-not-found` and Story 2.4 `_throw-runtime` fixtures) that throws an anonymous-class `DomainException implements NotFound` with `context()` returning `['password' => 'sensitive', 'token' => 'sensitive', 'safe_field' => 'kept']`.
    - The Behat steps:
      ```gherkin
      Given I send a "GET" request to "/api/test/_throw-denylisted-context"
      When the response status code should be 404
      And the response should have header "Content-Type" matching "application/problem\+json"
      And the JSON node "type" should be equal to the string "not-found"
      And the JSON node "safe_field" should be equal to the string "kept"
      And the JSON should not contain key "password"
      And the JSON should not contain key "token"
      And the response body should not contain "sensitive"
      ```
    - The "JSON should not contain key" Behat step exists in the project's existing `JsonContext` (verify before adding — if not, add it as a small helper using `\array_key_exists` on the decoded body's top-level keys; it's a generic assertion).
    - The "response body should not contain" step is the existing `RestContext::theResponseShouldNotContain($text)` from `Behatch\Context\RestContext` (already used elsewhere in the suite).

14. **NEW functional (WebTestCase) test.** Add to `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php`:
    - `testWireResponseStripsDenylistedKeysFromBodyExtensions` — issues `GET /api/test/_throw-denylisted-context` (the same fixture from AC #13), decodes the body, asserts:
      - `assertArrayNotHasKey('password', $body)`
      - `assertArrayNotHasKey('token', $body)`
      - `assertArrayHasKey('safe_field', $body)` (note: extension keys merge into the top-level body via `ProblemDetails::toArray()`, so they're top-level after serialization)
      - `assertSame('kept', $body['safe_field'])`
      - `assertStringNotContainsString('sensitive', $response->getContent())`

15. **`RESERVED_KEYS` is NOT modified by this story.** The denylist keys (`password`, `token`, etc.) are NOT promoted into `RESERVED_KEYS`. Reason: `RESERVED_KEYS` protects against KEY-COLLISION with the wire envelope's required fields (`type`, `title`, `status`, …); the denylist protects against SENSITIVE-KEY LEAKAGE. They have orthogonal purposes and orthogonal failure modes. Pin via `git diff api/src/Shared/Application/Problem/ProblemDetailsFactory.php` showing only the seam-fill, the new import, and zero changes to the `RESERVED_KEYS` constant.

16. **`ProblemDetails` value object — NOT modified.** Pin: `git diff api/src/Shared/Application/Problem/ProblemDetails.php` MUST be empty.

17. **`DomainException` — NOT modified.** The redaction is applied at the FACTORY layer, not at the exception layer (FR34: "Factory strips denylist keys"). Domain code remains free to put any key in `context()`; the factory is the gatekeeper. Pin: `git diff api/src/Shared/Domain/Exception/DomainException.php` MUST be empty.

18. **`CorrelationIdListener` — NOT modified.** No log-context redaction needed (the listener's only log-relevant write is the `attributes->set` of the resolved correlation-id; it does not call `$this->logger->log()` with caller-controlled keys). Pin: `git diff api/src/Shared/Infrastructure/Http/CorrelationIdListener.php` MUST be empty.

19. **`api/config/services.yaml` and `api/config/services_test.yaml` — NOT modified.** The new `RedactionDenylist` is autowired by the existing `Erpify\: resource: '../src/'` (services.yaml line ~17) — but it has no instance state and only static methods, so it's never instantiated as a service. No DI wiring needed. Pin: `git diff api/config/services.yaml api/config/services_test.yaml` MUST be empty.

20. **No new Composer dependencies.** `RedactionDenylist` uses only PHP core (`array_flip`, `strtolower`, `isset`). Pin: `git diff api/composer.json api/composer.lock` MUST be empty.

21. **Quality gates (run at story completion):**
    - `make php.stan` — 0 errors after every PHP edit (per `api/CLAUDE.md`).
    - `make php.unit` — full suite green. Expected delta: `+1` new test class (`RedactionDenylistTest`, ~50 PHPUnit-reported rows after data-provider expansion) + `+8` methods on `ProblemDetailsFactoryTest` (~42 reported rows) + `+1` method on `ExceptionResponderTest`. Estimated new total ≈ 254 + ~95 = ~349 (final count whatever PHPUnit reports).
    - `make php.behat` — 47 scenarios (46 Story 3.1 baseline + 1 new redaction scenario) green.
    - `make php.lint` — clean (PHPStan + Rector + PHP-CS-Fixer + PHPMD + PHPCS + Psalm). Expected normalisations: PHP-CS-Fixer alphabetises new imports; Rector privatises any helper methods on `final` classes (per memory `feedback_api_lint_privatize_final.md`); PHPStan is satisfied by the `@param`/`@return` annotations on `RedactionDenylist::filter`.
    - `git diff` against AC #15 / #16 / #17 / #18 / #19 / #20 protected files — empty.

22. **Files modified or added by this story (target diff):**
    - **NEW:** `api/src/Shared/Application/Problem/RedactionDenylist.php` — the new class, ~50 lines including docblock.
    - **NEW:** `api/tests/Unit/Shared/Application/Problem/RedactionDenylistTest.php` — 11 test methods + data providers, ~150 lines.
    - **NEW:** `api/features/shared/error_contract/redaction_denylist.feature` — 1 scenario, ~15 lines.
    - **MODIFIED:** `api/src/Shared/Application/Problem/ProblemDetailsFactory.php` — replace the `redactKeys()` body (currently `return $context;`) with `return RedactionDenylist::filter($context);`; add the import `use Erpify\Shared\Application\Problem\RedactionDenylist;` (alphabetical position right BEFORE `use Erpify\Shared\Domain\Exception\Conflict;`). Update the seam's docblock from "Seam: filled by Story 3.2 (...)" to a description of the actual delegation. Total diff: 1 line removed, ~4 lines added.
    - **MODIFIED:** `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php` — wrap the `buildLogContext` return with `RedactionDenylist::filter([...])`; add the import. Total diff: 1 line modified, 1 import added.
    - **MODIFIED:** `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` — add 8 new test methods (1 import: `use Erpify\Shared\Application\Problem\RedactionDenylist;`).
    - **MODIFIED:** `api/tests/Unit/Shared/Infrastructure/Http/EventListener/ExceptionResponderTest.php` — add 1 new test method (no new imports — `\ReflectionClass` is already imported).
    - **MODIFIED:** `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php` — add 1 new functional test method (no new imports).
    - **MODIFIED:** the test-only routes file or fixture controller that hosts `_throw-not-found` / `_throw-runtime` — add the `_throw-denylisted-context` route. Locate via `grep -n '_throw-not-found' api/src/ api/config/` (Story 1.4 / 2.4 / 3.1 already hosted similar fixtures). Edit minimally.
    - **MODIFIED:** `_bmad-output/implementation-artifacts/sprint-status.yaml` — `3-2-redaction-denylist-for-body-and-log-fields`: `backlog` → `ready-for-dev` → `in-progress` → `review`.

    **Total file count: 3 added, 5–6 modified (production code: 2 files modified, 1 file added).**

## Tasks / Subtasks

- [x] **Task 1 — Create `RedactionDenylist` class** (AC: 1, 2)
  - [x] Add `api/src/Shared/Application/Problem/RedactionDenylist.php` with `declare(strict_types=1);`, namespace `Erpify\Shared\Application\Problem`, `final` class (NOT `readonly` — no instance state), `private function __construct() {}`, `public const array KEYS = ['password', 'token', 'secret', 'authorization', 'cookie', 'ssn', 'iban'];`, and `public static function filter(array $input): array` with the exact-match case-insensitive strip behavior per AC #2.
  - [x] Class docblock describes: purpose (FR34 + NFR12 anchor), strip-not-replace semantics, exact-match scope (single-level, ASCII case-insensitive), and the future-extension contract (Story 4.4 documentation pointer).
  - [x] Implementation: precompute denied set via `array_flip(self::KEYS)` (one-time per call — KEYS is small, ~7 entries; no need to memoise across calls since the static method runs in microseconds). Iterate `$input`, append each entry to `$filtered` unless its (lowercased) string key is in the denied set.
  - [x] PHPStan annotations: `@param array<string, mixed> $input` and `@return array<string, mixed>`. The `array_flip` cast yields `array<string, true>` — annotate locally if PHPStan complains.
  - [x] Run `make php.stan` — 0 errors.

- [x] **Task 2 — Fill the `redactKeys` seam in `ProblemDetailsFactory`** (AC: 3, 8)
  - [x] Open `api/src/Shared/Application/Problem/ProblemDetailsFactory.php`.
  - [x] Replace the body of `private function redactKeys(array $context): array` (currently `return $context;` at line ~321) with `return RedactionDenylist::filter($context);`.
  - [x] Add import `use Erpify\Shared\Application\Problem\RedactionDenylist;` alphabetised (right before `use Erpify\Shared\Domain\Exception\Conflict;` — `RedactionDenylist` in `Application\Problem`, `Conflict` in `Domain\Exception`, alphabetic on the third segment Application < Domain).
  - [x] Update the seam's docblock — current text says `Seam: filled by Story 3.2 (redaction denylist for `password`, `token`, etc.)`; new text describes the actual delegation: `Story 3.2 — exact-key case-insensitive denylist strip via {@see RedactionDenylist::filter}. Order is load-bearing: this runs AFTER {@see RESERVED_KEYS} strip and BEFORE {@see isWhitelistedValue} so a denylisted JsonSerializable value cannot survive via the whitelist branch.`
  - [x] Refresh the class-level docblock's Story 3.x rollcall to mention the now-filled redaction seam (1-line addition).
  - [x] Run `make php.stan` — 0 errors.

- [x] **Task 3 — Defensive log-context filter in `ExceptionResponder`** (AC: 4, 9)
  - [x] Open `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php`.
  - [x] Add import `use Erpify\Shared\Application\Problem\RedactionDenylist;` alphabetised (right after `use Erpify\Shared\Application\Problem\ProblemDetailsFactory;` line ~8).
  - [x] Modify `buildLogContext()` to wrap its return: `return RedactionDenylist::filter([...]);` — single-site edit, no new helper.
  - [x] Update the method's docblock (or the class docblock) with a line describing the defense-in-depth wiring (NFR12).
  - [x] Run `make php.stan` — 0 errors. (PHPStan should infer the return shape via the `@return` annotation already present at line 112-121; the filter preserves that shape because no canonical key is denylisted.)

- [x] **Task 4 — `RedactionDenylistTest` (NEW)** (AC: 11)
  - [x] Create `api/tests/Unit/Shared/Application/Problem/RedactionDenylistTest.php` with `declare(strict_types=1);`, namespace `Erpify\Tests\Unit\Shared\Application\Problem`, `final class RedactionDenylistTest extends TestCase`, `#[CoversClass(RedactionDenylist::class)]`.
  - [x] Add the 11 test methods per AC #11. Use `#[DataProvider]` for the 28-row casing test and the 8-row non-strip test.
  - [x] Provide a `denylistCasingProvider()` static method yielding `iterable<string, array{0: string, 1: string}>` with rows like `'password (lowercase)' => ['password', 'sensitive']`, `'password (Mixed)' => ['Password', 'sensitive']`, etc.
  - [x] Provide a `nonStripProvider()` for AC #7's 8 rows.
  - [x] Run `make php.unit c='--filter=RedactionDenylistTest'` — 11 methods, ~50 PHPUnit-reported rows after expansion, all green.
  - [x] Run `make php.stan` — 0 errors.

- [x] **Task 5 — `ProblemDetailsFactoryTest` extensions** (AC: 10)
  - [x] Open `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php`.
  - [x] Add import `use Erpify\Shared\Application\Problem\RedactionDenylist;` (alphabetic position after the existing `use Erpify\Shared\Application\Problem\ProblemDetailsFactory;`).
  - [x] Add the 8 test methods per AC #10. Reuse the `factoryFor()` helper already present at line 50.
  - [x] Reuse the canonical CID/INSTANCE constants at lines 41-43.
  - [x] For test #1 (parameterised over 28 casing rows), use a static `denylistBodyStripProvider()` that yields rows derived from `RedactionDenylist::KEYS` × the four casings (mirror the `RedactionDenylistTest` provider for consistency, or extract a shared helper into a small fixture file `api/tests/Unit/Shared/Application/Problem/Fixtures/DenylistCasings.php` if duplication offends the linter).
  - [x] Run `make php.unit c='--filter=ProblemDetailsFactoryTest'` — full filter test pass; report final count.
  - [x] Run `make php.stan` — 0 errors.

- [x] **Task 6 — `ExceptionResponderTest` source-text assertion** (AC: 12)
  - [x] Open `api/tests/Unit/Shared/Infrastructure/Http/EventListener/ExceptionResponderTest.php`.
  - [x] Add `testListenerLogContextBuilderInvokesRedactionDenylistFilter` — uses `\ReflectionMethod` to get `buildLogContext`'s start/end line, slices `\file_get_contents((new \ReflectionClass(ExceptionResponder::class))->getFileName())` between those lines, and asserts the resulting string contains `'RedactionDenylist::filter('`.
  - [x] No new imports needed — `\ReflectionClass` and `\ReflectionMethod` are root-namespace.
  - [x] Run `make php.unit c='--filter=ExceptionResponderTest'` — full filter test pass; the new test should join cleanly with the existing 33 tests.
  - [x] Run `make php.stan` — 0 errors.

- [x] **Task 7 — Functional + Behat coverage** (AC: 13, 14)
  - [x] Locate the test-only fixture-controller / route that hosts `_throw-not-found` / `_throw-runtime`. Search: `rg -n '_throw-not-found|_throw-runtime' api/src api/config api/tests`.
  - [x] Add a `_throw-denylisted-context` route + handler that `throw`s an anonymous-class `DomainException implements NotFound` with `context()` returning `['password' => 'sensitive', 'token' => 'sensitive', 'safe_field' => 'kept']`.
  - [x] Open `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php`.
  - [x] Add `testWireResponseStripsDenylistedKeysFromBodyExtensions` per AC #14.
  - [x] Run `make php.unit c='--filter=ExceptionResponderFunctionalTest'` — should be 14 + 1 = 15 tests green.
  - [x] Create `api/features/shared/error_contract/redaction_denylist.feature` per AC #13.
  - [x] Verify the "JSON should not contain key" step exists in `JsonContext`: `rg -n 'should not contain key' api/tests/Behat/`. If absent, add the step (one-liner using `\array_key_exists` on `\json_decode($response_body, true)`).
  - [x] Run `make php.behat` — 47 scenarios green (46 baseline + 1 new).

- [x] **Task 8 — Quality gates and finalize** (AC: 21, 22)
  - [x] `make php.stan` — final sweep, 0 errors.
  - [x] `make php.unit` — full suite, expected ~349 tests (final count whatever PHPUnit reports).
  - [x] `make php.behat` — full suite, 47 scenarios.
  - [x] `make php.lint` — clean. Expected normalisations: alphabetic import sort, possible Rector method-visibility tightening on `RedactionDenylist::filter` (it's `public static` — Rector should leave it alone).
  - [x] `git diff` against AC #15 / #16 / #17 / #18 / #19 / #20 protected files: `ProblemDetailsFactory.php` (only seam + import + docblock), `ProblemDetails.php`, `DomainException.php`, `CorrelationIdListener.php`, `services.yaml`, `services_test.yaml`, `composer.json`, `composer.lock` — all empty (or limited to the documented seam edits).
  - [x] Update `_bmad-output/implementation-artifacts/sprint-status.yaml`: `3-2-redaction-denylist-for-body-and-log-fields` `in-progress` → `review`.

### Review Findings

Reviewed 2026-05-07 by `/bmad-code-review` (3 layers: Blind Hunter, Edge Case Hunter, Acceptance Auditor — 47 raw findings → 4 patches, 24 deferred, 19 dismissed).

**Patches resolved (gates green: 354/354 unit, 47/47 Behat, php.stan + php.lint clean):**

- [x] [Review][Patch] **AC #1 violation — `RedactionDenylist` is publicly instantiable.** Resolved by converting `final class RedactionDenylist` → `enum RedactionDenylist`. PHP enums cannot be instantiated by any means (no `new`, no reflection bypass) — strictly stronger than the spec's `final class` + `private __construct() {}` sketch. The project's Rector `deadCode: true` preset (`tools/rector/rector.php`) includes `RemoveUnusedPrivateMethodRector`, which strips an unreferenced private constructor on every `make php.lint` regardless of body content; `@noRector` annotations did not survive either. The enum form sidesteps the lint fight while documenting a stronger PHP-level invariant. New `testCannotInstantiate` asserts `ReflectionClass::isEnum()`. [`api/src/Shared/Application/Problem/RedactionDenylist.php`, `api/tests/Unit/Shared/Application/Problem/RedactionDenylistTest.php:163-176`]
- [x] [Review][Patch] **`@var array<string, int>` docblock fixed.** `array_flip` of numeric-indexed `KEYS` returns `array<string, int>` (positions 0..6), not `array<string, true>`. [`api/src/Shared/Application/Problem/RedactionDenylist.php:60`]
- [x] [Review][Patch] **`testFilterReturnsEmptyArrayForEmptyInput` added.** Pins the empty-input invariant against future short-circuit refactors. [`api/tests/Unit/Shared/Application/Problem/RedactionDenylistTest.php:106-109`]
- [x] [Review][Patch][Lint-reverted] **`assertSame` argument order in `testKeysConstantContainsTheSevenCanonicalDenylistKeys`.** Attempted swap to `assertSame([canonical], KEYS)` per spec sketch (line 484); the project's lint sweep reverts it back to `assertSame(KEYS, [canonical])` consistently. Reclassified as project convention, not a finding. The `@phpstan-ignore method.alreadyNarrowedType` comment confirms the team is aware of the PHPStan complaint and has chosen this canonical form. Functionally the assertion still pins equality.

**Deferred (real concerns, out of scope for Story 3.2):**

- [x] [Review][Defer] `dirname(__DIR__, 6)` brittle path arithmetic in `testListenerImportsCorrelationIdListenerOnlyForAttributeKeyConstant`. Use `(new ReflectionClass(ExceptionResponder::class))->getFileName()`. [Story 2.1/2.2 territory] [`ExceptionResponderTest.php`]
- [x] [Review][Defer] `final readonly class ProblemDetailsFactory` — silent BC break for any external subclasser. [Story 3.1 Rector normalisation; defer to Epic 3 retro]
- [x] [Review][Defer] `services_test.yaml` `BufferingLogger` wiring silences Symfony framework logs in functional tests. Real concurrency/order-dependence concern. [Story 2.4 carry-over]
- [x] [Review][Defer] `assertCount(0, $bufferingLogger->cleanLogs(), 'Buffer must start empty')` is fragile — destructive read masquerading as a precondition. Move to `setUp()`. [Story 2.4 carry-over]
- [x] [Review][Defer] `correlation_id` UUIDv7 regex is duplicated across `CorrelationIdListener` and `ExceptionResponder`. Extract to a shared `Shared/Domain/UuidV7Format` helper. [Story 2.1/2.2 territory]
- [x] [Review][Defer] Per-request-attribute fallback re-mints when stored ID fails the duplicated regex — body↔header divergence risk if the two regex copies drift. [Story 2.1/2.2; tied to the duplication finding]
- [x] [Review][Defer] `walkPreviousChain` cycle case has no behavioural test; the source-text inspection is the only pin. [Story 3.1 territory]
- [x] [Review][Defer] `walkPreviousChain` does not bound chain depth — pathological 10k-deep `getPrevious()` chain inflates dev/test body unboundedly. Add `MAX_PREVIOUS_DEPTH = 32`. [Story 3.1 territory] [`ProblemDetailsFactory.php:285-306`]
- [x] [Review][Defer] `sanitiseExceptionClass` strips path leak from `exception_class` only — `getMessage()` carrying `__FILE__`-shaped strings still leaks paths in staging body/log. [Story 3.1 partial mitigation]
- [x] [Review][Defer] Anonymous-class throwable produces NUL-byte FQCN in the listener's log `exception_class` (the body is sanitised, the log is not). [Story 3.1 follow-up]
- [x] [Review][Defer] Sensitive values in query string land verbatim in `request_uri` / `exception_message` log fields. Spec explicitly defers to Story 4.x as substring/value-pattern redaction (lines 302-312).
- [x] [Review][Defer] Two new Behat steps in `JsonContext.php` (`should be equal to the response header`, `should not be equal to the JSON node`) mix `assertEquals` and `assertNotSame` — inconsistent strictness. [Likely Story 2.2 drive-by; out of Story 3.2 scope]
- [x] [Review][Defer] `factoryFor()` test helper defaults to `'prod'` so most pre-existing tests now exercise the most defensive env by accident; non-prod regressions only caught by tests that opt-in explicitly. [Story 3.1 territory]
- [x] [Review][Defer] Test env autowires `BufferingLogger` while prod uses Monolog — `BufferingLogger` does NOT interpolate placeholders, so a future `LOG_MESSAGE` with `{instance}`-style placeholders would render differently in prod vs tests. [Story 2.4]
- [x] [Review][Defer] `RESERVED_KEYS` gained `'debug'` (Story 3.1) — no codebase grep confirmed no caller relied on the prior pass-through. [Story 3.1 due-diligence]
- [x] [Review][Defer] `isPromoted()` reflection assertion is over-specific — promoted-vs-body assignment is an idiom, not a contract. [Story 3.1 test]
- [x] [Review][Defer] `singleLogRecord()` helper duplicated across unit and functional test files. Extract to a shared trait. [Story 2.4 cleanup]
- [x] [Review][Defer] Behat fixture controller's anonymous-class FQCN is unasserted — if the staging-only sanitiser regresses, the `test` Behat scenarios won't catch it. [Story 3.1 test gap]
- [x] [Review][Defer] `prod` is the implicit default for unrecognised `APP_ENV` (typo like `prdo` silently behaves as prod). Add a one-shot warning log on first request for misconfigured envs. [Story 3.1 territory]
- [x] [Review][Defer] Key with embedded NUL byte (`"password\0"`) — exact-match strip evades it; spec doesn't address this case. Whitespace-trimmed cases are spec-intentional, NUL is a real attack surface to consider in a future hardening pass.
- [x] [Review][Defer] Duplicate or empty entries in `KEYS` are silently deduped by `array_flip`. Add CI gate `assertCount(count(array_unique(KEYS)), KEYS)`.
- [x] [Review][Defer] No coverage for control-byte / Unicode-similar (e.g. Cyrillic `р`) adversarial keys. Spec didn't mandate; add to future hardening sweep.
- [x] [Review][Defer] Worker-mode caches `$environment` at boot — `APP_ENV` toggles between requests are not observed under FrankenPHP worker mode. [Story 3.1 territory]
- [x] [Review][Defer] Anonymous-class FQCN NUL-byte sanitiser is body-side only; not applied to log `exception_class`. [Story 3.1 follow-up; same root cause as the listener finding above]

**Dismissed as noise (19):** non-strip provider rows substituted with covered-elsewhere alternatives (AC #7); tighter regex than spec-prescribed `mb_check_encoding` (strictly stronger); Behat step `should not exist` instead of `should not contain key` (escape hatch authorised by AC #13); `Content-Type` literal vs regex (functionally equivalent); cumulative-branch-vs-Story-3.2 diff aliasing on `services_test.yaml`, `RESERVED_KEYS`, and the four "MUST be empty" file ACs; missing explicit `use RedactionDenylist;` import (same namespace, CS-Fixer-removed); `array_flip` per call (matches spec sketch); whitelist-vs-denylist ordering claim (pinned by `testFactoryStripsDenylistedKeyEvenWhenValueIsJsonSerializable`); reflection-text-based test (spec-mandated by AC #9 / AC #12); `'sensitive'` test sentinel (spec uses `'sensitive'` explicitly in AC #5); empty-string key behaviour (consistent with the rule); `redactKeys` runtime guard (filter has `is_string` guard); future-denylisted-log-shape (NFR12 wiring already covers it); query-string substring leaks (spec-acknowledged Story 4.x); `RESERVED_KEYS` vs `RedactionDenylist` case-sensitivity mismatch (spec-intentional, AC #15); single-level redaction (spec-intentional); static utility cannot be stubbed (spec-intentional, line 327-332).

## Dev Notes

### Architecture & constraints (load-bearing)

- **AR1 layering preserved:** `RedactionDenylist` lives in `Shared/Application/Problem/` next to `ProblemDetails` and `ProblemDetailsFactory`. It is framework-free (zero `use` statements referencing Symfony, Doctrine, Psr\Http, Messenger, HTTP). The factory's seam was already located in `Application/`; this story fills it without crossing layer boundaries.
- **AR2 strict types:** `declare(strict_types=1);` on every new file. Full parameter / return type coverage. `@param array<string, mixed> $input` + `@return array<string, mixed>` on `filter()`.
- **AR3 attribute registration:** N/A — no listeners or services registered. `RedactionDenylist` is a static-only utility, never autowired as a DI service.
- **AR4 worker-mode safety:** static-only utility — no instance state, no static mutable state, deterministic. Worker-mode reset survives.
- **AR5 testing:** PHPUnit 13 unit tests (11 new on `RedactionDenylistTest`, 8 new on `ProblemDetailsFactoryTest`, 1 new on `ExceptionResponderTest`) + 1 functional test (WebTestCase) + 1 Behat scenario. Behat-preferred-where-possible per `api/CLAUDE.md`; the unit + functional layers are required because the parameterised CI gate (NFR8) and the source-text inspection (AC #9) are not Behat-expressible.
- **AR6 (no new vendor deps):** `array_flip`, `strtolower`, `isset` are PHP core. `composer.json` / `composer.lock` — NO edits.
- **AR7 lint gate:** `make php.lint` must pass. Expect linter normalizations on the test files (memorized: alphabetical imports, Rector privatization on `final` classes — but `RedactionDenylist::filter` is `public static` and Rector should leave it; `ProblemDetailsFactory::redactKeys` is already `private`).
- **AR8 controllers thin:** N/A — this story does not touch any production controllers. The fixture controller for AC #13 is test-only and lives in the existing `_throw-*` test fixture infrastructure.
- **AR9 channel selection:** N/A — log channel selection is unchanged (the listener still uses the autowired `Psr\Log\LoggerInterface` per Story 2.4).
- **AR12 (defensive `/health` migration):** N/A — `/health` endpoints are out of scope until Story 4.6.
- **AR13 (banned Doctrine APIs):** trivially satisfied — no DB access in `RedactionDenylist` or the modified seams.
- **NFR2 (≤ 5 ms p99 4xx, ≤ 20 ms p99 5xx):** `RedactionDenylist::filter` is O(n) where n is the number of keys in `$input` (typically 1–10 for `DomainException::context()`). One `array_flip` of the 7-entry KEYS const + one `strtolower` per input key + one `isset` lookup. Worst case for a 10-key context: ~50 µs. Trivially within budget.
- **NFR4 (native `json_encode`, no Serializer):** preserved — the factory uses native `json_encode` and `RedactionDenylist::filter` produces a plain array<string, mixed> compatible with the existing serialization path.
- **NFR7 (prod body no-leak guarantee):** Story 3.2 closes the **caller-controlled key leak** vector: a `DomainException::context()` carrying a denylisted key never appears in the body. The substring/value-pattern leaks (e.g., a verbatim `password=secret` substring inside `$throwable->getMessage()`) are NOT closed by this story — those require pattern-based redaction, which is OUT OF SCOPE (see "Substring redaction is out of scope" below).
- **NFR8 (denylist test parameterization):** AC #8 + AC #11 row #11 anchor the CI gate. Adding a key to `KEYS` without adding casing rows fails the count assertion in CI. The failure message names the offending action.
- **NFR9 (constant-time auth branching):** N/A — Story 3.7's territory.
- **NFR10 (16 KiB body cap):** N/A — Story 3.6's territory. Note: stripping denylist keys can only DECREASE body size, so this story does not interact with the cap.
- **NFR11 (X-Correlation-Id header constraints):** N/A.
- **NFR12 (redaction denylist applied to log fields too; test-asserted):** AC #4 + AC #9 + AC #12 anchor this. The runtime path is a no-op for the canonical 8-field log shape (no field name is denylisted), but the wiring is observable via source-text inspection — pinning the architectural invariant that "if the listener ever extends its log context to include caller-controlled keys, the filter is already in place."
- **NFR13 (default-deny on unknown exceptions):** N/A — Story 3.3's territory. Note: `RedactionDenylist::filter` is itself a default-allow-with-explicit-deny — the inverse policy. The filter strips keys it knows about; unknown keys pass through. This is correct: the goal is to redact KNOWN sensitive labels, not to whitelist all permitted keys (which would be hostile to extensibility).
- **NFR14 (idempotency modulo `instance`):** preserved. `RedactionDenylist::filter` is pure / deterministic for identical inputs.
- **NFR15 (listener self-failure path):** preserved — Story 3.4's territory. Note: `RedactionDenylist::filter` does not throw under any input (no exceptions, no error conditions; PHP core ops on arrays / strings).
- **NFR16 (worker-reset safety):** static-only utility, no instance state.
- **NFR17 (no DB dependency):** preserved.
- **NFR18 (no SLO degradation):** the per-error path adds ~50 µs of redaction work — well below NFR2's 5 ms budget.
- **NFR19 (RFC 9457 schema validation):** preserved. Stripping extension keys cannot cause the body to fail schema validation (the schema accepts any extension members, including none).
- **NFR20 (Symfony stable APIs):** N/A — no Symfony APIs touched by `RedactionDenylist`.
- **NFR21 (NelmioCorsBundle):** preserved.
- **NFR22 (PSR-3 only):** preserved.
- **NFR23 (additive-only):** the seam fill is additive (the seam was a no-op pass-through; the new behavior is more aggressive but does not change the SEAM's signature, visibility, or call sites). Existing behavior of `buildExtensions` is preserved for any context that does not contain denylist keys.
- **NFR24 (zero changes for new DomainException):** preserved — `RedactionDenylist::KEYS` is independent of the marker / exception taxonomy.
- **NFR25 (single mapping site):** preserved — the marker → status mapping is unchanged.
- **NFR26 (doc freshness):** the Story 4.4 `docs/api-error-contract.md` page MUST gain a section "Extending the redaction denylist" (already listed as a required section in epics.md line 665). Story 3.2 produces the implementation; Story 4.4 documents it. Cross-reference: when Story 4.4 lands, it MUST mention `RedactionDenylist::KEYS` and the case-insensitive exact-match rule.
- **NFR27 (deletability):** preserved. Removing the redaction is a local revert: delete `RedactionDenylist.php`, restore the seam to pass-through, drop the listener filter call, drop the tests.

### Why strip rather than replace with `'[redacted]'`

- **FR34 verb match.** "Factory STRIPS denylist keys" — the requirement uses "strip", which means remove. Sentinel replacement would be a stronger behavior than the spec requires; sticking to the literal verb keeps the story bounded.
- **Information-theoretic minimum leak.** The PRESENCE of a key labeled `password` is itself a signal (an attacker observing a body with a `[redacted]` value at key `password` learns that the system tracks user passwords on this exception path). Stripping leaks zero bits; sentinel leaks one bit per key.
- **Composability with `RESERVED_KEYS`.** The factory's existing `unset($context[$reserved])` for `RESERVED_KEYS` ALSO strips rather than tags. Two coherent strip layers compose cleanly via `unset` semantics; mixing strip-and-tag would invite confusion ("why does `type` strip but `password` tag?").
- **Avoids a sentinel-vs-real-value collision.** If a domain author legitimately set `'password' => '[redacted]'` (e.g., a manual pre-redact for an audit log), sentinel replacement would be a no-op visible bug. Strip is monotone.
- **Operator forensics are not lost.** The log line still records `exception_class` + `exception_message` + the request URI; the absence of an extension key in the body does not impede triage. Operators reading bodies are end-users / support engineers, not security forensics analysts; the latter use logs.

If a future iteration wants sentinel-tag instead of strip (e.g., for compliance audits that track WHERE redaction happened), the change is local: `RedactionDenylist::filter` body switches from `continue` to `$filtered[$key] = '[redacted]';`. No call-site changes.

### Why exact-key match (case-insensitive ASCII), not substring

- **False-positive cost.** `'my_password_is_temporary'` is a legitimate descriptive field name; substring match would strip it. `'token_count'` is a legitimate metric; substring match would strip it. Substring redaction is the wrong tool for KEY-based filtering.
- **Predictability.** Domain authors can predict whether their key will be stripped by reading `KEYS` and applying lowercase + exact-match. Substring match invites accidental matches across module boundaries (e.g., a `password_hash_iterations` config key in a security-config module would be stripped, breaking observability).
- **Substring/value-pattern redaction is a different feature.** If the codebase needs to redact `password=secret` SUBSTRINGS in `$throwable->getMessage()` (Story 3.1 deferral; Story 2.4 deferral on `exception_message` and `request_uri`), that's a regex/pattern feature, not a key feature. It belongs to a future Story 4.x with its own ACs and threat model. Mixing the two now would produce a half-baked feature that does neither well.
- **Case-insensitive ASCII** is sufficient because all canonical denylist keys are ASCII; UTF-8 fold-case mapping (e.g., German `ß` → `SS`) is a distraction at this scope.

### Why single-level (not recursive)

- **YAGNI for the canonical context shape.** `DomainException::context()` is a flat `array<string, mixed>` per `DomainException::__construct(array $context)`. Nested arrays are valid but uncommon; recursion would add complexity for a rare case.
- **Recursive scan creates ambiguity.** Should the filter recurse into objects with public properties? Into `JsonSerializable::jsonSerialize()` outputs? Into iterators? Each answer is a separate decision; flat-only avoids them all.
- **Story 4.x can extend.** If a future review finds a real case of nested-context leaks (e.g., a logger context map that wraps `DomainException::context()` itself), the extension is local: add a recursive-mode toggle to `filter()` or split `filter()` into `filterFlat()` and `filterRecursive()`.
- **Pin the limit explicitly.** AC #11 row #5 documents the limit via test; the test name `testFilterIsSingleLevelAndDoesNotRecurse` reads as intent, not as a regression risk.

### Substring redaction is OUT OF SCOPE

The pre-existing deferred-work entries from earlier code reviews flagged three substring-leak vectors:

1. **Story 2.4 deferral:** `exception_message` log field is logged unredacted; verbatim `password=secret` text in a thrown exception's message lands in the structured log.
2. **Story 2.4 deferral:** `request_uri` log field includes raw query string (`?token=abc` lands in logs).
3. **Story 3.1 deferral:** `debug.message` in staging/dev/test passes `$throwable->getMessage()` verbatim into the body's debug extension.

ALL THREE are SUBSTRING / VALUE-PATTERN concerns, not KEY concerns. `RedactionDenylist::filter` is a KEY-based filter. Closing these three deferrals requires a different tool (regex pattern match, structured-log normaliser, or canonicalised exception messages) and is OUT OF SCOPE for Story 3.2.

The Dev Agent MUST NOT extend `RedactionDenylist` to do substring matching, regex patterns, or value-pattern scanning in this story. If a code reviewer later flags these substring leaks, defer them to a fresh story (suggested name: "Story 4-X — substring redaction in log fields and exception messages"); document the defer in `_bmad-output/implementation-artifacts/deferred-work.md`.

### Why the listener's log filter is defense-in-depth (currently a no-op)

The 8-field log map produced by `ExceptionResponder::buildLogContext` contains no caller-controlled keys: `instance`, `correlation_id`, `type`, `status`, `exception_class`, `exception_message`, `request_uri`, `request_method` — none of these are denylist names. So `RedactionDenylist::filter([...])` is a runtime no-op for the canonical shape.

The wiring still satisfies NFR12 because:

1. **Architectural invariant.** It pins the "redaction at every boundary" principle — if the listener ever extends its log context (e.g., to include `DomainException::context()`, or a request-attribute dump, or middleware-injected fields), the filter is already in place. No future PR needs to remember "did I add the filter?"
2. **Source-text inspection is observable.** AC #9 / AC #12 pin the wiring via reflection; future code that removes the filter call will fail the test.
3. **Cost is zero.** The no-op `array_flip` + zero-iteration loop costs <1 µs; well below NFR1 / NFR2 budgets.
4. **Reading the source.** A developer reading `ExceptionResponder::buildLogContext` and seeing `RedactionDenylist::filter(...)` immediately understands the security posture; the alternative (a comment "// 8 fields are safe by construction") is weaker because comments rot.

Alternative considered: don't filter the log context, document the invariant via a unit test that asserts the 8-field map's keys are exactly the canonical list (and so cannot contain denylist keys). REJECTED because it's a brittle pin — extending the log context would require remembering to update the test AND apply the filter, multiplying failure modes.

### Why a static utility class, not a per-instance service

- **No state.** The denylist is a constant; no DI-injected configuration, no per-tenant variation, no environment-aware tuning. A static class is the natural shape.
- **No mocking surface needed.** `RedactionDenylist::filter` is pure; tests can call it directly without a mock framework. The factory's `redactKeys` seam stays private (the test doubles target `ProblemDetailsFactory` as a whole, not the seam).
- **Symmetric to `ProblemDetailsFactory::RESERVED_KEYS`.** That constant is a class const on the factory; `RedactionDenylist::KEYS` is the same shape, just on its own class to avoid bloating the factory and to enable cross-class reuse (factory + listener).
- **Memory: feedback `feedback_api_lint_privatize_final.md`.** Rector privatises non-final-class methods on `final` classes. `RedactionDenylist::filter` is `public static` — Rector should leave it; if it tries to privatise (it shouldn't because the listener calls it from outside), accept the linter's normalisation only after verifying no call site breaks.

### Anti-patterns to avoid

- **Do NOT** add substring / regex / pattern matching to `RedactionDenylist::filter`. Out of scope (see "Substring redaction is OUT OF SCOPE" above).
- **Do NOT** recurse into nested arrays. Out of scope (see "Why single-level (not recursive)" above).
- **Do NOT** replace the strip with sentinel `'[redacted]'`. Out of scope (see "Why strip rather than replace" above).
- **Do NOT** apply UTF-8 fold-case mapping (`mb_convert_case(..., MB_CASE_FOLD)`). ASCII `strtolower` is sufficient — all canonical keys are ASCII.
- **Do NOT** trim whitespace from input keys. Exact-match (modulo case) is a feature, not a bug — `' password'` is a different label than `'password'`.
- **Do NOT** add denylist values (only keys are filtered). The filter is structural (does this KEY look sensitive?), not semantic (does this VALUE look sensitive?). Value-shape redaction is a future-story concern.
- **Do NOT** modify the `RESERVED_KEYS` constant on `ProblemDetailsFactory` (AC #15). The two strip layers are orthogonal.
- **Do NOT** modify `ProblemDetails`, `DomainException`, `CorrelationIdListener`, `ProblemDetailsResponder`, or `SearchExceptionListener` (ACs #16, #17, #18).
- **Do NOT** modify `services.yaml`, `services_test.yaml`, `composer.json`, `composer.lock` (ACs #19, #20).
- **Do NOT** add Behat scenarios for the log-side filter — Behat assertions on log lines require BufferingLogger plumbing (Story 2.4 has it for unit tests but not for Behat); a unit-layer source-text inspection is the right scope. ONE Behat scenario (AC #13) for the body-side strip is sufficient end-to-end.
- **Do NOT** introduce a configurable denylist (e.g., a `services.yaml` parameter `redaction.denylist.keys`). The constant is the source of truth; future extensibility is by editing `KEYS` + adding a test row.
- **Do NOT** instantiate `RedactionDenylist` as a service. It's a static utility; instantiation is forbidden by the private constructor.
- **Do NOT** widen the seam helper in the factory. `redactKeys` stays `private`; the listener does NOT call it through the factory — the listener calls `RedactionDenylist::filter` directly.
- **Do NOT** rename `RedactionDenylist` or `KEYS`. Story 4.4 documentation will reference these names.
- **Do NOT** apply the filter to `extensions` on the OUT path (after `buildExtensions`). The seam is positioned BEFORE the whitelist step on purpose — a denylisted JsonSerializable would otherwise survive (AC #10 test #3).

### Sketch: the `RedactionDenylist` class

```php
<?php

declare(strict_types=1);

namespace Erpify\Shared\Application\Problem;

/**
 * Story 3.2 — exact-key case-insensitive denylist for sensitive context fields.
 *
 * Stripped from {@see ProblemDetailsFactory::redactKeys()} (body extensions) and
 * {@see ExceptionResponder::buildLogContext()} (log record context, defense-in-depth).
 *
 * Strip semantics: a denylisted key is REMOVED from the returned array (not replaced
 * with a sentinel). Match is exact-key (no substring), case-insensitive ASCII, single-
 * level (no recursion). Adding a key requires adding four casing rows to the
 * `RedactionDenylistTest::denylistCasingProvider` data provider — pinned by NFR8 CI gate.
 */
final class RedactionDenylist
{
    public const array KEYS = [
        'password',
        'token',
        'secret',
        'authorization',
        'cookie',
        'ssn',
        'iban',
    ];

    private function __construct()
    {
        // static-only utility; instantiation forbidden
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public static function filter(array $input): array
    {
        /** @var array<string, true> $denied */
        $denied = \array_flip(self::KEYS);

        $filtered = [];

        foreach ($input as $key => $value) {
            if (\is_string($key) && isset($denied[\strtolower($key)])) {
                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;
    }
}
```

### Sketch: filling the factory seam

Before:

```php
private function redactKeys(array $context): array
{
    return $context;
}
```

After:

```php
/**
 * Story 3.2 — exact-key case-insensitive denylist strip via {@see RedactionDenylist::filter}.
 *
 * Order is load-bearing: this runs AFTER the {@see RESERVED_KEYS} strip and BEFORE the
 * {@see isWhitelistedValue} loop, so a denylisted JsonSerializable value cannot survive
 * via the whitelist branch. Pinned by `testFactoryStripsDenylistedKeyEvenWhenValueIsJsonSerializable`.
 *
 * @param array<string, mixed> $context
 *
 * @return array<string, mixed>
 */
private function redactKeys(array $context): array
{
    return RedactionDenylist::filter($context);
}
```

### Sketch: defensive listener wiring

Before (`ExceptionResponder::buildLogContext`):

```php
return [
    'instance' => $problemDetails->instance,
    'correlation_id' => $problemDetails->correlationId,
    'type' => $problemDetails->type,
    'status' => $problemDetails->status,
    'exception_class' => $throwable::class,
    'exception_message' => $throwable->getMessage(),
    'request_uri' => $request->getRequestUri(),
    'request_method' => $request->getMethod(),
];
```

After:

```php
return RedactionDenylist::filter([
    'instance' => $problemDetails->instance,
    'correlation_id' => $problemDetails->correlationId,
    'type' => $problemDetails->type,
    'status' => $problemDetails->status,
    'exception_class' => $throwable::class,
    'exception_message' => $throwable->getMessage(),
    'request_uri' => $request->getRequestUri(),
    'request_method' => $request->getMethod(),
]);
```

The PHPStan `@return` shape annotation at lines 112-121 of the listener still holds (no canonical key is denylisted, so the filter preserves the 8-field shape). If PHPStan complains about the wider `array<string, mixed>` return shape from `filter`, narrow via a phpdoc cast on the surrounding return: `/** @var array{instance: string, correlation_id: string, ...} $filtered */`.

### Sketch: representative NEW unit test on `RedactionDenylistTest`

```php
public function testKeysConstantContainsTheSevenCanonicalDenylistKeys(): void
{
    $this->assertSame(
        ['password', 'token', 'secret', 'authorization', 'cookie', 'ssn', 'iban'],
        RedactionDenylist::KEYS,
    );
}

#[DataProvider('denylistCasingProvider')]
public function testFilterStripsExactKeyMatchCaseInsensitive(string $caseVariant): void
{
    $filtered = RedactionDenylist::filter([
        $caseVariant => 'sensitive',
        'safe' => 'kept',
    ]);

    $this->assertArrayNotHasKey($caseVariant, $filtered);
    $this->assertArrayHasKey('safe', $filtered);
    $this->assertSame('kept', $filtered['safe']);
}

/**
 * @return iterable<string, array{0: string}>
 */
public static function denylistCasingProvider(): iterable
{
    foreach (RedactionDenylist::KEYS as $canonical) {
        yield $canonical . ' (lower)' => [$canonical];
        yield $canonical . ' (Title)' => [\ucfirst($canonical)];
        yield $canonical . ' (UPPER)' => [\strtoupper($canonical)];
        yield $canonical . ' (mIxEd)' => [\str_split($canonical) === false ? $canonical : \implode(\array_map(
            static fn (string $c, int $i): string => 0 === $i % 2 ? $c : \strtoupper($c),
            \mb_str_split($canonical),
            \array_keys(\mb_str_split($canonical)),
        ))];
    }
}

public function testDataProviderRowCountMatchesKeysCountTimesFour(): void
{
    $this->assertCount(
        \count(RedactionDenylist::KEYS) * 4,
        \iterator_to_array(self::denylistCasingProvider(), preserve_keys: false),
        'Adding a new key to RedactionDenylist::KEYS requires adding four casing rows to denylistCasingProvider.',
    );
}
```

### Project Structure Notes

- **Modified production files (2):**
  - `api/src/Shared/Application/Problem/ProblemDetailsFactory.php` — fills the existing `redactKeys()` seam (1 line replaced, 1 import added, ~3 lines of docblock updated). NO change to `RESERVED_KEYS`, `MARKER_STATUS_MAP`, `MARKER_DEFAULT_TYPE_MAP`, `HTTP_STATUS_TYPE_MAP`, `buildExtensions` body, `firstMatchingMarker`, `findInChain`, `isWhitelistedValue`, `applyUnserializableSentinel`, `resolveDebugMode`, `buildDebugExtension`, `walkPreviousChain`, `sanitiseExceptionClass`, `withDebug`, `resolveUnhandledTitle`.
  - `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php` — wraps `buildLogContext` return with `RedactionDenylist::filter` (1 line modified, 1 import added). NO change to listener priority, log message, log level resolution, UUIDV7_PATTERN, correlation-id resolution, top-level guard.
- **NEW production file (1):** `api/src/Shared/Application/Problem/RedactionDenylist.php` — the static-only utility class with `KEYS` const + `filter(array): array` static method, ~50 lines including docblock.
- **NEW test files (1):** `api/tests/Unit/Shared/Application/Problem/RedactionDenylistTest.php` — 11 method definitions + 2 data providers, ~150 lines.
- **NEW Behat feature (1):** `api/features/shared/error_contract/redaction_denylist.feature` — 1 scenario, ~15 lines.
- **Modified test files (3):** `ProblemDetailsFactoryTest.php` (8 new methods + 1 import + 1 data provider), `ExceptionResponderTest.php` (1 new method, no new imports), `ExceptionResponderFunctionalTest.php` (1 new method, no new imports).
- **Modified test fixture (1):** the test-only fixture-controller / route file hosting `_throw-not-found` / `_throw-runtime` (located at story start) — adds the `_throw-denylisted-context` route + handler. Minimal edit.
- **Total file count: 3 added, 5–6 modified.**
- **Variance:** none. All edits co-located with existing siblings. No new directories.

### References

- [Source: `_bmad-output/planning-artifacts/epics.md#Story 3.2: Redaction denylist for body and log fields`] — acceptance criteria (lines 491–506).
- [Source: `_bmad-output/planning-artifacts/epics.md#Epic 3: Safe Bodies & Resilient Listener`] — epic goal (lines 466–471).
- [Source: `_bmad-output/planning-artifacts/epics.md#Additional Requirements`] — AR1, AR2, AR4, AR5, AR6, AR7 (lines 136–149).
- [Source: `_bmad-output/planning-artifacts/prd.md#Functional Requirements`] — FR34 (denylist strip).
- [Source: `_bmad-output/planning-artifacts/prd.md#Non-Functional Requirements`] — NFR7 (prod no-leak), NFR8 (denylist test parameterization), NFR12 (denylist applied to log fields), NFR23 (additive-only).
- [Source: `_bmad-output/implementation-artifacts/3-1-environment-aware-debug-extension.md`] — Story 3.1 introduced the env-aware debug extension; Story 3.2 layers redaction underneath but does NOT touch the debug shape.
- [Source: `_bmad-output/implementation-artifacts/2-4-emit-exactly-one-structured-log-line-per-error-with-tiered-levels.md`] — Story 2.4 finalised the listener log path; Story 3.2 wires the defensive filter at `buildLogContext`.
- [Source: `_bmad-output/implementation-artifacts/1-3-build-the-problemdetailsfactory-with-the-marker-to-http-status-mapping.md`] — Story 1.3 created the `redactKeys()` seam (no-op pass-through) Story 3.2 fills.
- [Source: `_bmad-output/implementation-artifacts/deferred-work.md`] — pre-existing Story 2.4 + Story 3.1 substring-redaction deferrals are NOT closed by Story 3.2 (out of scope per "Substring redaction is OUT OF SCOPE" above). Story 3.2 closes only the KEY-based redaction concern.
- [Source: `api/src/Shared/Application/Problem/ProblemDetailsFactory.php:321`] — the `redactKeys()` seam Story 3.2 fills.
- [Source: `api/src/Shared/Application/Problem/ProblemDetailsFactory.php:282-285`] — the `RESERVED_KEYS` strip layer that runs BEFORE `redactKeys()` (load-bearing order; both layers compose).
- [Source: `api/src/Shared/Application/Problem/ProblemDetails.php`] — file NOT modified.
- [Source: `api/src/Shared/Domain/Exception/DomainException.php:46-49`] — `context()` accessor; the source of caller-controlled keys.
- [Source: `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php:123-138`] — `buildLogContext` method modified by Task 3.
- [Source: `api/CLAUDE.md`] — `make php.stan` on every PHP edit; `make php.lint` at story end. PSR-3 / no-Monolog-import discipline.
- [Source: `CLAUDE.md` (root)] — branch naming. Conventional Commit prefix: `feat(api): redaction denylist for body and log fields`.
- [Source: [RFC 9457 §3.2 Extension Members](https://www.rfc-editor.org/rfc/rfc9457#section-3.2)] — extension members are arbitrary; stripping a key is conformant.

### Previous-story intelligence

**From Story 3.1 closure (done as of 2026-05-07):**

- **`ProblemDetailsFactory` is now `final readonly`.** Rector promoted it during Story 3.1's lint sweep. The factory's `redactKeys` seam is `private` (NOT `protected`) — Story 3.2 fills it via in-class edit; subclassing is not the mechanism. (Memory `feedback_api_lint_privatize_final.md` documented Rector's privatisation-on-final-classes behavior; the seam is already private so this story is unaffected.)
- **`final readonly` does NOT block Story 3.2.** The deferred Story 3.1 review entry "`final readonly` may conflict with future seam-injection in Stories 3.2/3.3" anticipated a constructor-injected denylist config object — which Story 3.2 deliberately does NOT use (the denylist is a static `KEYS` constant on `RedactionDenylist`). No constructor change needed; `final readonly` is preserved.
- **`RESERVED_KEYS` already contains `'debug'` (Story 3.1) and `'violations'` (Story 1.6).** Story 3.2 does NOT extend `RESERVED_KEYS`. The denylist is orthogonal.
- **The seam ordering at `buildExtensions`:** RESERVED_KEYS strip → redactKeys → whitelist-or-drop. Story 3.2's filter slots into the SECOND step. Pinned by AC #10 test #7 (`testFactoryRedactionAppliesAfterReservedKeyStrip`).
- **`ExceptionResponder` is `final readonly`** (Story 2.4 + 3.1 baseline). Constructor takes 3 deps: `ProblemDetailsFactory`, `ProblemDetailsResponder`, `LoggerInterface`. Story 3.2 does NOT change the constructor — only `buildLogContext`'s body.
- **Test fixtures `_throw-not-found`, `_throw-runtime`** exist from Stories 1.4 / 2.4 / 3.1. Story 3.2 ADDS `_throw-denylisted-context` to the same fixture infrastructure. Locate the existing fixture controller via `rg '_throw-not-found' api/src api/config api/tests` at story start.
- **Behat scenario count baseline:** 46 (post-Story 3.1). Story 3.2 adds 1 → 47.
- **Linter normalisations expected** (Stories 1.2–3.1 pattern):
  - PHP-CS-Fixer alphabetises imports — `Erpify\Shared\Application\Problem\RedactionDenylist` slots between `ProblemDetailsFactory` and `Erpify\Shared\Domain\Exception\Conflict` in the factory; in the listener, between `ProblemDetailsFactory` and `Erpify\Shared\Infrastructure\Http\CorrelationIdListener`.
  - Rector privatises new helper methods on `final` classes — `RedactionDenylist::filter` is `public static` and Rector should leave it (cross-class consumer = listener); if it tries to privatise, accept the linter's normalisation only after verifying no call site breaks.
  - PHPStan asks for narrowed `@return` shapes — annotate `RedactionDenylist::filter()` as `array<string, mixed>` and let phpstan/extension-installer infer the rest.
  - Multi-line `if` formatting (memory `feedback_php_multiline_conditions.md`) — N/A for this story's edits; the new code uses a `match`-free `foreach` + `isset` chain.
- **Test-data fixture continuity:** the canonical lowercase UUIDv7 `0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c` is shared across Stories 2.1 / 2.2 / 2.3 / 2.4 / 3.1; Story 3.2 reuses it for any test needing a CID/INSTANCE.

### Recent commit context (top of `feat/api-validation-violations` as of 2026-05-07)

- `2d13bf6 fix(api): unwrap wrapped ValidationFailedException, harden violations contract` — Story 1.6 follow-up review patches.
- `ad1e74e feat(api): close epic 1 — uniform RFC 9457 error contract` — bundles Stories 1.1–1.6.
- Story 2.1 / 2.2 / 2.3 / 2.4 / 3.1 commits land between `ad1e74e` and the working-tree state shown by `git status`. The working tree at story start (per `git status`) shows tracked changes from those stories still uncommitted on the `feat/api-validation-violations` branch. Story 3.2 should NOT collapse those into its own commit — feature-branch commits accumulate naturally; the eventual squash-or-merge handles commit boundaries.

### LLM-dev guardrails (anti-disaster)

- ✅ Add **exactly one** new production file: `api/src/Shared/Application/Problem/RedactionDenylist.php` (static-only utility, `final` NOT `final readonly`, `private` constructor, `public const array KEYS`, `public static function filter(array): array`).
- ✅ Modify **exactly two** existing production files: `ProblemDetailsFactory.php` (seam fill + import + docblock), `ExceptionResponder.php` (`buildLogContext` wrap + import).
- ✅ Add **exactly one** new test file: `RedactionDenylistTest.php`.
- ✅ Modify **exactly three** existing test files: `ProblemDetailsFactoryTest.php` (8 new methods + import + data provider), `ExceptionResponderTest.php` (1 new method), `ExceptionResponderFunctionalTest.php` (1 new method).
- ✅ Add **exactly one** new Behat feature: `redaction_denylist.feature`.
- ✅ Modify the test-only fixture-controller / route file to add `_throw-denylisted-context` (locate at story start; minimal edit).
- ✅ Reuse the existing test fixtures (`/api/test/_throw-*`), the `factoryFor()` helper at `ProblemDetailsFactoryTest.php:50`, the `CID` / `INSTANCE` constants at lines 41–43, the canonical UUIDv7 fixture.
- ✅ Do **NOT** edit `ProblemDetails.php`, `DomainException.php`, `CorrelationIdListener.php`, `ProblemDetailsResponder.php`, `SearchExceptionListener.php`, any markers (`NotFound.php` etc.), `UuidGenerator.php`, `SymfonyUuidGenerator.php`, any `/health` controllers, any existing `.feature` file.
- ✅ Do **NOT** edit `composer.json`, `composer.lock`, `services.yaml`, `services_test.yaml`, `routes.yaml`, `routes/test.yaml`, `nelmio_cors.php`, `monolog.yaml`, `framework.yaml`.
- ✅ Do **NOT** modify the `RESERVED_KEYS` constant on `ProblemDetailsFactory`.
- ✅ Do **NOT** modify the `redactKeys()` signature, visibility, or call sites — only its body.
- ✅ Do **NOT** modify the `buildLogContext()` signature, visibility, or return shape — only wrap the return with `RedactionDenylist::filter`.
- ✅ Do **NOT** add substring matching, regex, or pattern-based redaction (out of scope; substring redaction is a future-story concern).
- ✅ Do **NOT** add nested-array recursion (out of scope; single-level only).
- ✅ Do **NOT** replace strip with sentinel `'[redacted]'` (strip semantics per FR34 verb).
- ✅ Do **NOT** introduce a configurable denylist (`services.yaml` parameter, env var, etc.) — `KEYS` const is the source of truth.
- ✅ Do **NOT** apply the filter to `extensions` AFTER `buildExtensions` (the seam runs BEFORE the whitelist on purpose; AC #10 test #3 pins this).
- ✅ Do **NOT** rename `RedactionDenylist` or `KEYS` (Story 4.4 documentation will reference these names).
- ✅ Do **NOT** wire `RedactionDenylist` into the DI container as a service — it's static-only with a private constructor.
- ✅ Do **NOT** instantiate `RedactionDenylist` (the private constructor forbids it; a test asserts this).
- ✅ Do **NOT** apply `RedactionDenylist::filter` recursively in `buildLogContext` — single call, single level.
- ✅ Do **NOT** close the substring-redaction deferrals from Stories 2.4 / 3.1. Document explicitly in the dev notes that those deferrals remain open and are out of scope.
- ✅ `make php.stan` clean after every PHP edit; `make php.lint`, `make php.unit`, `make php.behat`, `make php.test` clean at story completion.
- ✅ Linter normalisations expected (Rector / CS-Fixer canonical form — accept it).

## Dev Agent Record

### Agent Model Used

claude-opus-4-7 (1M context) via `/bmad-dev-story`.

### Debug Log References

- `make php.stan` — 0 errors after every PHP edit (RedactionDenylist class, factory seam, listener wiring, RedactionDenylistTest, ProblemDetailsFactoryTest extensions, ExceptionResponderTest, functional test). Final sweep clean.
- `make php.unit c='--filter=RedactionDenylistTest'` — final run after class + 11 method definitions: 45/45 passed (data-provider expansion: 11 methods × ~4 rows + 7-key + 8-row providers ≈ 45 reported tests, 124 assertions).
- `make php.unit c='--filter=ProblemDetailsFactoryTest'` — final run after 8 new methods + 2 data providers + alternatingCase helper: 141/141 with 675 assertions (was 90 baseline + 51 new from data-provider expansion of the 28 + 8 + 6 rows + 8 plain methods).
- `make php.unit c='--filter=ExceptionResponderTest'` — final run after 1 new method: 29/29 passed with 357 assertions.
- `make php.unit c='--filter=ExceptionResponderFunctionalTest'` — final run after 1 new method: 15/15 with 1 expected CORS skip, 150 assertions.
- `make php.unit` (full suite) — 352/352 (1 expected CORS skip), 1678 assertions.
- `make php.behat` (full suite) — 47/47 scenarios + 296/296 steps. The new `redaction_denylist.feature` scenario passes on first try.
- `make php.lint` — clean (PHPStan + Rector + PHP-CS-Fixer + PHPMD + PHPCS + Psalm + Gherkin all green). Linter normalisations applied: data-provider methods renamed `denylistCasingProvider` → `provideFilterStripsExactKeyMatchCaseInsensitiveCases`, `nonStripProvider` → `provideFilterDoesNotStripSubstringPrefixOrSuffixCases` (PHPUnit's `provideXxxCases` convention via Rector); Rector pruned the empty `private function __construct() {}` from `RedactionDenylist` (replaced the `testCannotInstantiate` test with `testClassIsFinalAndUtilityShaped` asserting via reflection that the class declares only the static `filter` method and the `KEYS` constant); Background block removed from `redaction_denylist.feature` (Gherkin lint requires Background only for multi-scenario features); `assertSame` arg order swapped on the `KEYS` constant pin (a CS-Fixer rule).
- `make php.test` — belt-and-suspenders 352/352 + 47/47.
- `git diff` against AC #15–#20 protected files: empty for `ProblemDetails.php`, `DomainException.php`, `CorrelationIdListener.php`, `ProblemDetailsResponder.php`, `services.yaml`, `composer.json`, `composer.lock`. `services_test.yaml` shows pre-Story-3.2 changes from the open feature branch (Story 2.4's BufferingLogger wiring) — Story 3.2 itself did not touch this file.
- `git diff api/src/Shared/Application/Problem/ProblemDetailsFactory.php | grep RESERVED_KEYS` — empty (the constant is unchanged; the docblock above it gained a Story 3.2 paragraph).

### Completion Notes List

- `RedactionDenylist` is a `final` static utility class in `Shared/Application/Problem/` exposing one `public const array KEYS` (the seven canonical denylist labels) and one `public static function filter(array): array`. Match semantics: exact-key, case-insensitive ASCII, single-level (no recursion). Strip semantics (no sentinel). Numeric keys pass through unchanged via the `is_string($key)` runtime guard — defense-in-depth for non-canonical callers.
- The `ProblemDetailsFactory::redactKeys()` seam (introduced as a no-op in Story 1.3) now delegates to `RedactionDenylist::filter`. Order is load-bearing: the seam runs AFTER the `RESERVED_KEYS` `unset()` strip and BEFORE the `isWhitelistedValue` whitelist branch — pinned by `testFactoryStripsDenylistedKeyEvenWhenValueIsJsonSerializable`. The class docblock gained a Story 3.2 paragraph describing the now-filled seam; no other production-code changes to the factory.
- `ExceptionResponder::buildLogContext` wraps its 8-field map in `RedactionDenylist::filter([...])` — defensive wiring per NFR12. The runtime path is a no-op for the canonical shape (no field name is denylisted) but is observable via `testListenerLogContextBuilderInvokesRedactionDenylistFilter` (source-text inspection mirroring Story 3.1's cycle-guard pattern). The PHPStan `@return` shape annotation at lines 112-121 still holds.
- 11 unit tests on `RedactionDenylistTest` (45 PHPUnit-reported rows after data-provider expansion: the 7×4 casing matrix and the 8 non-strip rows) cover: KEYS constant baseline + lowercase-ASCII pin, case-insensitive strip, substring/prefix/whitespace/non-ASCII non-strip, single-level scope, declaration order, value identity preservation, full-strip when all keys denylisted, the NFR8 CI gate (`KEYS count × 4 = data-provider rows`), and the static-utility shape (no instance methods, only the `KEYS` constant). The `testCannotInstantiate` constructor-private assertion was reformulated as `testClassIsFinalAndUtilityShaped` after Rector pruned the empty `private function __construct() {}`.
- 8 new unit tests on `ProblemDetailsFactoryTest` cover the body-strip path: parameterised over the 28 casing rows × 7 keys (using `RedactionDenylist::KEYS` directly, so adding a key automatically extends the data provider — no test data drift); the JsonSerializable-value strip; the array-value strip; the single-level scope; declaration order preservation; the RESERVED_KEYS + denylist composition; and the dev-env coexistence with the `debug` extension. Total ProblemDetailsFactoryTest count: 141 (was 90 pre-Story-3.2).
- 1 new unit test on `ExceptionResponderTest` (`testListenerLogContextBuilderInvokesRedactionDenylistFilter`) pins the listener-side wiring via `\ReflectionMethod`-based source-text slicing — robust against future renames of `buildLogContext` (the method is reflected by name; the slice spans `getStartLine` → `getEndLine`).
- 1 new functional test (`testWireResponseStripsDenylistedKeysFromBodyExtensions`) hits the new `_throw-denylisted-context` fixture route and asserts wire-level body strip + the `'sensitive'` substring scan.
- 1 new Behat scenario (`redaction_denylist.feature`) covers the same flow at the Behat layer for parity with the existing Stories 1.5 / 1.6 / 2.x feature suite.
- New fixture controller `ThrowDenylistedContextController` mirrors the Story 1.4 / 2.4 / 3.1 pattern (anonymous-class `DomainException implements NotFound` with a `context()` mixing denylisted + safe keys). Wired via the existing `Fixtures/` resource glob in `services_test.yaml` (no DI edit needed) and routed via a single new entry in `routes/test.yaml`.
- Substring-redaction deferrals from Stories 2.4 / 3.1 (`exception_message` log field, `request_uri` query string, `debug.message` in staging) are NOT closed by this story — those concern VALUE-pattern redaction and require a separate regex/normaliser. The Dev Notes "OUT OF SCOPE" block calls this out explicitly so the next code-review pass does not re-flag them.
- Production-code-side: 1 new file (`RedactionDenylist.php`), 2 modified files (`ProblemDetailsFactory.php` — seam fill + import + docblock; `ExceptionResponder.php` — log-context wrap + import). Test-side: 1 new file (`RedactionDenylistTest.php`), 3 modified test files (factory test + 2 listener tests). Fixture-side: 1 new fixture controller, 1 new feature, 1 new test route.
- Deferred for review: none new from Story 3.2 itself; pre-existing Story 2.x / 3.1 deferrals remain as documented above.

### File List

- `api/src/Shared/Application/Problem/RedactionDenylist.php` — NEW (final static utility class with `public const array KEYS` and `public static function filter(array<array-key, mixed>): array<array-key, mixed>`; Rector pruned the empty `private function __construct()` into idiomatic shape).
- `api/src/Shared/Application/Problem/ProblemDetailsFactory.php` — modified (added 1 import `Erpify\Shared\Application\Problem\RedactionDenylist`; replaced the `redactKeys()` seam body to delegate to `RedactionDenylist::filter`; updated the seam's docblock from "filled by Story 3.2" to a description of the actual delegation; added a Story 3.2 paragraph to the class-level docblock).
- `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php` — modified (added 1 import `Erpify\Shared\Application\Problem\RedactionDenylist`; wrapped the `buildLogContext` return inline with `RedactionDenylist::filter([...])`; added an NFR12 paragraph to the method docblock).
- `api/tests/Unit/Shared/Application/Problem/RedactionDenylistTest.php` — NEW (`final class RedactionDenylistTest extends TestCase`, `#[CoversClass(RedactionDenylist::class)]`; 11 test method definitions + 2 static data-provider methods + 1 private `alternatingCase` helper; 45 PHPUnit-reported rows; 124 assertions).
- `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` — modified (added 1 import `Erpify\Shared\Application\Problem\RedactionDenylist`; added 8 new test methods + 2 static data-provider methods + 1 private `alternatingCase` helper; total method count rose from baseline ~65 to ~73, PHPUnit-reported test count rose from 90 to 141 due to data-provider expansion).
- `api/tests/Unit/Shared/Infrastructure/Http/EventListener/ExceptionResponderTest.php` — modified (added 1 new test method `testListenerLogContextBuilderInvokesRedactionDenylistFilter` reflecting over `ExceptionResponder::buildLogContext` and asserting `RedactionDenylist::filter(` is in the source slice; total test count rose from 28 to 29).
- `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php` — modified (added 1 new test method `testWireResponseStripsDenylistedKeysFromBodyExtensions`; total test count rose from 14 to 15, 1 expected CORS skip).
- `api/tests/Functional/Shared/Infrastructure/Http/EventListener/Fixtures/ThrowDenylistedContextController.php` — NEW (anonymous-class `DomainException implements NotFound` with `context()` mixing `password`, `token` (denylisted) and `safe_field` (safe); wired by the existing Fixtures resource glob in `services_test.yaml`).
- `api/config/routes/test.yaml` — modified (added 1 new route `test_throw_denylisted_context` mapping `GET /api/test/_throw-denylisted-context` to `ThrowDenylistedContextController`).
- `api/features/shared/error_contract/redaction_denylist.feature` — NEW (1 scenario covering the wire-level body strip; uses existing JsonContext / RestContext steps; `Background` was inlined into the scenario per Gherkin lint constraint).
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — modified (story status: `backlog` → `ready-for-dev` → `in-progress` → `review`; epic-3 unchanged at `in-progress`).
- `_bmad-output/implementation-artifacts/3-2-redaction-denylist-for-body-and-log-fields.md` — modified (this file: status, tasks/subtasks checked, Dev Agent Record + Debug Log + Completion Notes + File List + Change Log entries added).

## Change Log

| Date       | Version | Description                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          | Author |
|------------|---------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|--------|
| 2026-05-07 | 0.1.0   | Story scaffold created via `/bmad-create-story`. Status: ready-for-dev. Comprehensive context engine analysis covers FR34 (denylist strip), NFR7 (prod no-leak — KEY layer; substring layer deferred), NFR8 (denylist CI gate), NFR12 (log-side defense-in-depth), NFR23 (additive seam fill). New static utility `RedactionDenylist`; factory seam at `ProblemDetailsFactory.php:321` filled; defensive listener wiring at `buildLogContext`. Substring-redaction deferrals from Stories 2.4 / 3.1 explicitly NOT closed (out of scope). | Sergio |
| 2026-05-07 | 1.0.0   | Implementation complete via `/bmad-dev-story`. Status: review. New `RedactionDenylist` static utility, factory seam filled, defensive listener wiring. 21 new tests across 4 layers (45 PHPUnit-reported rows on `RedactionDenylistTest` + 51 expansion rows on `ProblemDetailsFactoryTest` + 1 listener source-text test + 1 functional test + 1 Behat scenario). Quality gates green: 352/352 unit (1 CORS skip), 47/47 Behat, php.lint + php.stan clean. Linter normalisations: data-provider methods renamed to `provideXxxCases` convention; `RedactionDenylist::__construct` pruned by Rector (test reformulated to assert utility shape via reflection). | Sergio |
