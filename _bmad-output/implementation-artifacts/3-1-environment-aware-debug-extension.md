# Story 3.1: Environment-aware `debug` extension

Status: done

Epic: 3 — Safe Bodies & Resilient Listener
Story Key: `3-1-environment-aware-debug-extension`

## Story

As a developer in staging,
I want a `debug` extension carrying enough context to reproduce the error locally, but never in prod,
so that we retain debuggability in non-prod environments without ever leaking internals (stack traces, file paths, SQL fragments, class names) to real clients.

## Acceptance Criteria

1. **`ProblemDetailsFactory` gains a single new constructor argument `string $environment` autowired from `%kernel.environment%`.** The argument is annotated `#[Autowire('%kernel.environment%')]` (Symfony 8 idiom — see `api/src/Frontoffice/Mercure/Infrastructure/Controller/MercurePublishDemoController.php:23` for the precedent already in this codebase). NO edit to `api/config/services.yaml`. NO read of `$_ENV` / `getenv()` / `\App::getEnv()` — the value flows in via DI exclusively (FR36/FR37/FR35 anchor + spec wording: "accepts `%kernel.environment%` via constructor injection (not `$_ENV`)"). The argument is the FIRST parameter so existing constructor positional call sites can be updated mechanically (`new ProblemDetailsFactory($env)`). Property is `private readonly string $environment`. The class remains `final` (NOT `final readonly` — already established in Story 1.3 because Stories 3.2 / 3.3 fill the `redactKeys` / `applyUnserializableSentinel` seams).

2. **The factory recognises exactly four environment values: `'dev'`, `'test'`, `'staging'`, `'prod'`** (lowercase, exact-string match — no `strtolower`, no aliasing). Any OTHER value (e.g. `'ci'`, `'integration'`, the empty string, an arbitrary string) MUST be treated as `'prod'` for the purposes of the `debug` extension decision (default-deny — NFR13). The decision lives in a private `resolveDebugMode(): self::DEBUG_MODE_*` helper or equivalent; pin via a unit test that passes `'ci'`, `''`, `'PROD'` (uppercase), and `'production'` and asserts each one produces a body with NO `debug` extension.

3. **Dev / test environment — full `debug` extension.** When `$environment === 'dev'` OR `$environment === 'test'`, every `ProblemDetails` returned by `fromThrowable()` includes a `debug` extension whose value is an associative array with exactly these four keys in declaration order:

   | Key              | Type                  | Value                                                                                  |
   |------------------|-----------------------|----------------------------------------------------------------------------------------|
   | `exception_class`| `string`              | `$throwable::class` (concrete FQCN of the throwable that entered `fromThrowable`)      |
   | `message`        | `string`              | `$throwable->getMessage()` verbatim (empty string if absent)                            |
   | `file`           | `string`              | `$throwable->getFile()` (absolute path, native PHP behavior)                            |
   | `line`           | `int`                 | `$throwable->getLine()` (positive integer)                                              |
   | `previous_chain` | `list<array{...}>`    | each entry has the SAME four keys (`exception_class`, `message`, `file`, `line`) recursively for `getPrevious()`; empty list `[]` when no previous |

   - `previous_chain` is built by walking `$throwable->getPrevious()` in order, terminating on `null` OR on the FIRST repeat (cycle protection — pin via a unit test that builds two exceptions whose `getPrevious()` chains form a 2-cycle and asserts `previous_chain` has length ≤ 2 and the listener does not infinite-loop). Mirror the existing `findInChain()` walk pattern in `ProblemDetailsFactory.php:211-220` (which already uses a `for (; $current instanceof Throwable; $current = $current->getPrevious())` form — DO NOT introduce a new walking idiom).
   - The `debug` extension key is appended LAST in the `ProblemDetails::$extensions` array (so the deterministic key order at serialization is `..., violations?, ..., debug` — `debug` is always the final extension when present). Pin via assertion: `array_key_last($extensions) === 'debug'` whenever `debug` is present.

4. **Staging environment — minimal `debug` extension.** When `$environment === 'staging'`, the factory emits a `debug` extension whose value is an associative array with exactly two keys in declaration order:

   | Key              | Type     | Value                                |
   |------------------|----------|--------------------------------------|
   | `exception_class`| `string` | `$throwable::class`                  |
   | `message`        | `string` | `$throwable->getMessage()` verbatim  |

   - **NO** `file` field. **NO** `line` field. **NO** `previous_chain` field. Pin via `assertSame(['exception_class','message'], array_keys($problemDetails->extensions['debug']))` — this catches accidental field bleed from the dev/test branch.
   - `debug` is again appended LAST in `extensions`.

5. **Prod environment — `debug` extension is COMPLETELY ABSENT.** When `$environment === 'prod'` (or any unrecognised value per AC #2), the factory MUST NOT include a `debug` key in `extensions` AT ALL — not as `null`, not as `[]`, not as an empty object. Pin via `assertArrayNotHasKey('debug', $problemDetails->extensions)`.

6. **Prod no-leak guarantee on the `unhandled-exception` title (NFR7 anchor).** When `$environment === 'prod'` AND the throwable falls into the terminal `unhandled-exception` / 500 branch (i.e. not a `DomainException`, not a `ValidationFailedException`, not an `AccessDeniedException` / `AuthenticationException`, not an `HttpExceptionInterface`), the `ProblemDetails::$title` MUST be the static safe literal `'An unexpected error occurred.'` regardless of `$throwable->getMessage()`. This replaces the current Story 1.5 behavior (`$title = '' !== $message ? $message : 'An unexpected error occurred.'` at `ProblemDetailsFactory.php:149-150`) — in prod ONLY. In dev / test / staging, the existing message-pass-through behavior is preserved (the message remains visible in `title` AND in the `debug.message` extension; humans want both). Pin via the NFR7 test described in AC #11.

7. **`debug` is added to the `RESERVED_KEYS` constant array.** The current `RESERVED_KEYS` (`ProblemDetailsFactory.php:72`) is `['type', 'title', 'status', 'detail', 'instance', 'correlation-id', 'violations']`; this story extends it to `['type', 'title', 'status', 'detail', 'instance', 'correlation-id', 'violations', 'debug']`. This prevents domain code from injecting a fake `debug` extension via `DomainException::context()` and clobbering or impersonating the factory-emitted one. Pin via a unit test that constructs a `DomainException` whose `context()` includes a `'debug' => ['exception_class' => 'spoofed']` entry and asserts the resulting `$problemDetails->extensions['debug']` (in dev/test/staging) is the FACTORY-COMPUTED debug map, not the spoofed one — and (in prod) `debug` is still absent.

8. **All existing factory branches receive the debug extension uniformly (when applicable).** The five branches in `fromThrowable()` — `DomainException` (lines 76–100), wrapped `ValidationFailedException` (lines 102–114), `AccessDeniedException` (lines 116–124), `AuthenticationException` (lines 126–134), `HttpExceptionInterface` (lines 136–147), and the terminal `unhandled-exception` fallback (lines 149–159) — ALL append the `debug` extension when in dev/test/staging. The decision is uniform: ANY error path produces a `debug` extension in non-prod environments. The implementation should NOT duplicate the debug-build logic across branches — extract a single private helper (e.g. `buildDebugExtension(\Throwable): ?array` returning `null` in prod, the appropriate map in non-prod) called from one place. Suggested call site: a final post-processing step after the branches resolve their core `ProblemDetails`, OR pre-computed once at the top of `fromThrowable()` and passed into a new internal `withDebug(ProblemDetails, ?array): ProblemDetails` helper. Pick whichever keeps the diff smallest; pin behavior via tests across all five branches (six test cases, one per branch + one per environment per branch where it matters).

9. **`ProblemDetails` value object is NOT modified.** The VO already accepts an `array<string, mixed> $extensions` constructor argument and serializes it after the core fields (see `ProblemDetails.php:34-50`). The new `debug` key fits into the existing extensions map without requiring any VO change. Pin: `git diff api/src/Shared/Application/Problem/ProblemDetails.php` MUST be empty at story end.

10. **`ExceptionResponder` listener is NOT modified.** The listener already injects a `ProblemDetailsFactory` instance and calls `fromThrowable()` (see `ExceptionResponder.php:87-91`); the constructor signature change is invisible to the listener because Symfony's autowiring resolves the factory from the container, not from explicit construction. Pin: `git diff api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php` MUST be empty at story end (Story 3.4 is the next story to touch this file).

11. **Existing 50 ProblemDetailsFactoryTest tests must continue to pass.** The current test file (`api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php`, 50 tests, 1133 lines) instantiates the factory via `new ProblemDetailsFactory()` (zero-arg) at 50+ call sites. The constructor change requires updating each call site to `new ProblemDetailsFactory('prod')` — this is a mechanical search-and-replace. Why `'prod'`? Because the existing tests assert exact `extensions` shape (e.g., `testContextScalarArrayJsonSerializableValuesPassThrough` asserts `assertSame($context, $problemDetails->extensions)`); injecting a `debug` extension in non-prod would break those assertions. Default-to-prod keeps them green byte-for-byte. **DO NOT** add a constructor default value (`string $environment = 'prod'`) — the spec wording "constructor injection (not `$_ENV`)" implies a required argument; defaults invite accidental misuse where a forgotten autowire silently selects prod. Update all call sites mechanically.

    **Suggested helper** to reduce churn:

    ```php
    /**
     * Convenience helper for tests that don't care about env-specific debug behavior.
     * Defaults to 'prod' so existing tests keep their byte-for-byte extensions shape.
     */
    private static function factoryFor(string $environment = 'prod'): ProblemDetailsFactory
    {
        return new ProblemDetailsFactory($environment);
    }
    ```

    Then `new ProblemDetailsFactory()` → `self::factoryFor()` everywhere existing tests instantiate. New tests (this story) call `self::factoryFor('dev')` / `self::factoryFor('test')` / `self::factoryFor('staging')` / `self::factoryFor('prod')` explicitly.

12. **NEW unit tests added by Story 3.1 (parameterized over the 4 environments).** Add to `ProblemDetailsFactoryTest.php`. Reuse the existing test structure idioms (anonymous-class `DomainException` subclasses, `CID` / `INSTANCE` constants, `#[DataProvider]` attributes). Add a `private const string CID_VALID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';` reuse if not already present (it is — at line 38 of the test file).

    1. `testDevEnvironmentBodyHasFullDebugExtensionForUnhandledException` — env `'dev'`, throw `new RuntimeException('boom')` from a known file/line, assert `$problemDetails->extensions['debug']` is `['exception_class' => 'RuntimeException', 'message' => 'boom', 'file' => '<absolute path>', 'line' => <int>, 'previous_chain' => []]`. Use `assertSame(['exception_class','message','file','line','previous_chain'], array_keys($extensions['debug']))` for declaration order. `file` is asserted via `\str_starts_with(...)` against the test file path (absolute paths vary across CI runners).
    2. `testTestEnvironmentBodyHasFullDebugExtensionForDomainException` — env `'test'`, throw a `NotFound`-marker `DomainException` instance, assert `$problemDetails->extensions['debug']` includes all four declared keys + `previous_chain` (empty list because no `previous`).
    3. `testStagingEnvironmentBodyHasMinimalDebugExtension` — env `'staging'`, throw `new RuntimeException('boom')`, assert `array_keys($problemDetails->extensions['debug']) === ['exception_class', 'message']` exactly. NO `file`. NO `line`. NO `previous_chain`.
    4. `testProdEnvironmentBodyOmitsDebugExtensionEntirely` — env `'prod'`, throw `new RuntimeException('boom')`, assert `\array_key_exists('debug', $problemDetails->extensions) === false`.
    5. `testProdEnvironmentUnhandledExceptionTitleIsSafeLiteral` (NFR7 anchor) — env `'prod'`, throw a `RuntimeException` whose message contains `'/abs/path/Module.php'`, `'SELECT * FROM users WHERE password = \'secret\''`, AND `'App\\Backoffice\\Bank\\Internal'`; encode the resulting `ProblemDetails::toArray()` via `\json_encode(..., JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)`; assert NONE of the three substrings appears anywhere in the encoded JSON body. Also assert `$problemDetails->title === 'An unexpected error occurred.'`.
    6. `testStagingEnvironmentDebugIncludesNoFilePathOrLineForUnhandledException` — env `'staging'`, throw `new RuntimeException('boom')`; encode body; assert encoded JSON contains the message but NOT the `file` field name and NOT the `line` field name (defense-in-depth against AC #4 regressions).
    7. `testDevEnvironmentDebugPreviousChainPopulatesForWrappedExceptions` — env `'dev'`, throw a `DomainException` whose `previous` is `new \LogicException('inner')` whose own `previous` is `new \RuntimeException('innermost')`; assert `previous_chain` is a list of length 2 (NOT 3 — the outer throwable IS the top-level entry; only its previous + previous-of-previous appear in the chain) with the inner throwables' four-field maps.
    8. `testDevEnvironmentDebugPreviousChainTerminatesOnCycle` — synthesize two throwables `$a` and `$b` where `$a->getPrevious() === $b` and `$b` has been pre-populated with `$a` via reflection (or use Symfony's `\Throwable::class` reflection helpers); invoke factory with env `'dev'`; assert the call returns within reasonable time (no infinite loop) AND `previous_chain` length ≤ 2. The cycle test pins the spec's "terminating on null OR on the FIRST repeat" rule from AC #3.
    9. `testUnrecognisedEnvironmentValueDefaultsToProd` (parameterized) — for each unrecognised value `['ci', 'integration', '', 'PROD', 'production', 'DEV', 'qa']`, throw `new RuntimeException('x')`; assert `\array_key_exists('debug', $problemDetails->extensions) === false` AND `$problemDetails->title === 'An unexpected error occurred.'`. This pins the default-deny behavior from AC #2.
    10. `testReservedKeyDebugIsStrippedFromDomainExceptionContext` — env `'dev'`; construct a `DomainException` subclass whose `context()` includes `['debug' => ['exception_class' => 'spoofed', 'message' => 'spoofed']]`; assert `$problemDetails->extensions['debug']` equals the FACTORY-COMPUTED map (with the real `$throwable::class`), not the spoofed one. Also assert the same in `'prod'` (debug absent entirely).
    11. `testDebugExtensionIsAlwaysLastInExtensionsArrayOrder` — env `'dev'`; throw a `DomainException` whose `context()` includes `['custom_field' => 'value']`; assert `array_key_last($problemDetails->extensions) === 'debug'` AND `array_keys($problemDetails->extensions)` ends with `'debug'`. Repeat for `'test'` and `'staging'`. Confirms AC #3 / AC #4 ordering rule.
    12. `testValidationFailedDebugCoexistsWithViolationsExtension` — env `'dev'`; throw a `ValidationFailedException` with two violations; assert `$problemDetails->extensions` has BOTH `'violations'` AND `'debug'` keys, with `violations` declared first and `debug` last. Confirms AC #8 uniformity for the validation-failed branch.
    13. `testHttpExceptionDebugCoexistsWithBridgeBranch` — env `'dev'`; throw `new HttpException(503, 'maintenance')`; assert `$problemDetails->extensions` has a `debug` key with `exception_class === 'Symfony\\Component\\HttpKernel\\Exception\\HttpException'`. Confirms AC #8 uniformity for the http-exception branch.
    14. `testAccessDeniedAndAuthenticationDebugCoexistWithBridgeBranches` — same as #13 but for `AccessDeniedException` (403) and `AuthenticationException` subclass `BadCredentialsException` (401). Two assertions in one test body, or split into two — author's choice.
    15. `testProdEnvironmentDoesNotLeakRedactionDenylistKeysFromDebugMessage` — env `'prod'`; throw `new RuntimeException('Database error: password=secret123 token=abc')`. Assert encoded JSON body does NOT contain `'secret123'` AND does NOT contain `'abc'` (because `unhandled-exception` title is the safe literal in prod, the message never reaches the body). Note: this test does NOT exercise the redaction denylist directly (Story 3.2's territory) — it pins the no-leak guarantee on the title path. Document explicitly: "Story 3.2 will add the structured-context denylist; this test pins the title-side defense in depth."

    **Total NEW unit tests: 15.**

    **Total ProblemDetailsFactoryTest count after this story: 65** (50 existing + 15 new).

13. **Functional layer (WebTestCase) — 2 NEW tests.** Add to `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php`:

    1. `testFunctionalTestEnvironmentBodyIncludesDebugExtension` — `GET /api/test/_throw-runtime`; the test runs in `APP_ENV=test`, so the body MUST contain `extensions.debug.exception_class === 'RuntimeException'` AND `extensions.debug.message === 'boom'`. Confirms the `#[Autowire('%kernel.environment%')]` wiring resolves to `'test'` end-to-end.
    2. `testFunctionalTestEnvironmentDebugLeaksNothingToTitleField` — `GET /api/test/_throw-runtime`; assert `$body['title'] === 'boom'` (NOT `'An unexpected error occurred.'`) — confirms the test env still passes the message through the title (only prod swaps to the safe literal). Plus assert `$body['extensions']['debug']['exception_class'] === 'RuntimeException'`.

    **Existing 12 functional tests must continue to pass byte-for-byte** — the new debug extension does not break the existing assertions because: (a) the body-parsing tests use `assertArrayHasKey` / `assertSame` on individual fields (not the whole `extensions` array), (b) the validation-failed tests assert `$body['violations']` shape (still works — `violations` is still present), (c) the response header tests don't touch the body. Verify by running `make php.unit c='--filter=ExceptionResponderFunctionalTest'` BEFORE adding the 2 new tests; expect 12/12 still pass.

14. **Behat scenarios — NONE added by this story.** The `debug` extension is environment-conditional (present in test, absent in prod); Behat runs in `APP_ENV=test`, so it would always see `debug`. Pinning the prod-absent rule via Behat would require either a separate `APP_ENV=prod` Behat suite (out of scope, AR5 has Behat in `api/tools/behat/`) or per-scenario env overrides (no idiomatic mechanism). The unit + functional layers cover all four envs explicitly. Existing 46 Behat scenarios (Story 2.4 baseline) MUST continue to pass byte-for-byte — verify via `make php.behat` at story completion.

15. **`api/config/services.yaml` — NOT modified.** The `_defaults: { autowire: true }` at line 14 already wires `#[Autowire('%kernel.environment%')]` parameter attributes for any `Erpify\` service. The `MercurePublishDemoController` precedent (line 23) confirms this works without any `services.yaml` entry. Pin: `git diff api/config/services.yaml` MUST be empty at story end.

16. **`api/config/services_test.yaml` — NOT modified.** The Story 2.4 BufferingLogger override is preserved byte-for-byte. The `ProblemDetailsFactory` does NOT need a test-env override — `'test'` is the natural autowired value and gives full debug. Pin: `git diff api/config/services_test.yaml` MUST be empty at story end.

17. **No new Composer dependencies.** `Symfony\Component\DependencyInjection\Attribute\Autowire` is already imported in the codebase (`api/src/Frontoffice/Mercure/Infrastructure/Controller/MercurePublishDemoController.php:11`). AR6 satisfied. Pin: `git diff api/composer.json api/composer.lock` MUST be empty.

18. **Quality gates (run at story completion):**
    - `make php.stan` — 0 errors after every edit (per `api/CLAUDE.md` mandate).
    - `make php.unit` — full suite green (existing 230 tests + 17 new = 247 expected).
    - `make php.behat` — full suite green (46 scenarios, byte-for-byte from Story 2.4 close-out).
    - `make php.lint` — clean (PHPStan + Rector + PHP-CS-Fixer + PHPMD + PHPCS + Psalm). Expect: PHP-CS-Fixer alphabetizes new imports; Rector privatizes the new `buildDebugExtension` / `resolveDebugMode` helpers (per memory `feedback_api_lint_privatize_final.md`); PHPStan asks for narrowing assertions on `$debug['exception_class']` returns of array shape — handle via `@phpstan-return` annotation on the helper.
    - `git diff` against AC #9 / #10 / #15 / #16 / #17 protected files — empty.

19. **Files modified by this story (target diff):**
    - `api/src/Shared/Application/Problem/ProblemDetailsFactory.php` — adds 1 import (`Symfony\Component\DependencyInjection\Attribute\Autowire`), 1 constructor + property (`#[Autowire('%kernel.environment%')] private readonly string $environment`), 1 entry to `RESERVED_KEYS` (`'debug'`), 1 private helper `buildDebugExtension(\Throwable): ?array`, 1 private helper `walkPreviousChain(\Throwable): list<array>` (cycle-safe), 1 modification to the `unhandled-exception` branch's `title` (prod-safe literal), and `debug` injection into each branch's returned `ProblemDetails` (or via a single post-processing step). Class docblock updated to describe environment-aware behavior.
    - `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` — updates 50+ existing call sites to use `self::factoryFor()` (or pass `'prod'` explicitly); adds the `factoryFor(string $environment = 'prod'): ProblemDetailsFactory` static helper; adds 15 new tests; potentially imports `LogicException` (root namespace, no import needed) and `BadCredentialsException`.
    - `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php` — adds 2 new tests; imports as needed (no new imports — `LogLevel` and existing assertions cover it).
    - `_bmad-output/implementation-artifacts/sprint-status.yaml` — `3-1-environment-aware-debug-extension`: `backlog` → `ready-for-dev` → `in-progress` → `review`. Epic-3: `backlog` → `in-progress`.

    **Total file count: 0 added, 3 modified (production code: 1 file).**

## Tasks / Subtasks

- [x] **Task 1 — Modify `ProblemDetailsFactory.php`** (AC: 1, 2, 3, 4, 5, 6, 7, 8)
  - [x] Add import `use Symfony\Component\DependencyInjection\Attribute\Autowire;` (alphabetic position: after `Erpify\Shared\Domain\Exception\Unauthenticated`, before `JsonSerializable`).
  - [x] Add the constructor: `public function __construct(#[Autowire('%kernel.environment%')] private readonly string $environment) {}` placed after the constants block, before `fromThrowable()`.
  - [x] Add `'debug'` to `RESERVED_KEYS` constant array (AC #7).
  - [x] Add a private helper `resolveDebugMode(): string` returning one of three sentinel strings (`'full'`, `'minimal'`, `'omit'`) based on `$this->environment`. Exact-match `'dev'` / `'test'` → `'full'`; `'staging'` → `'minimal'`; everything else → `'omit'` (AC #2 default-deny).
  - [x] Add a private helper `buildDebugExtension(\Throwable $e): ?array` returning `null` when `resolveDebugMode() === 'omit'`, the 4-key map (`exception_class`, `message`, `file`, `line`, `previous_chain`) when `'full'`, the 2-key map (`exception_class`, `message`) when `'minimal'`. Use the explicit-key associative-array literal so PHP-CS-Fixer / PHPStan can validate the shape via `@return array{...}|null`.
  - [x] Add a private helper `walkPreviousChain(\Throwable $top): array` that walks `$top->getPrevious()` cycle-safely (track visited objects via `SplObjectStorage` OR a simple `array $seen` + `\spl_object_id($current)` check) and returns a `list<array{exception_class:string,message:string,file:string,line:int}>` (no `previous_chain` recursion — the chain itself is flat per AC #3 — wait, re-reading AC #3: "each entry has the SAME four keys" — the chain entries use `exception_class`, `message`, `file`, `line` — NOT a recursive `previous_chain`. The chain is FLAT, not a tree).
  - [x] Modify each of the five `fromThrowable` branches (DomainException, ValidationFailedException, AccessDeniedException, AuthenticationException, HttpExceptionInterface, terminal unhandled-exception) to append the `debug` key to its returned `ProblemDetails::$extensions` if `buildDebugExtension($e) !== null`. **Recommended approach:** introduce a `private function withDebug(ProblemDetails $base, ?array $debug): ProblemDetails` post-processing helper that returns either `$base` unchanged (when `$debug === null`) or a `new ProblemDetails(...)` with `extensions: [...$base->extensions, 'debug' => $debug]`. Then the body of `fromThrowable()` becomes: pre-compute `$debug = $this->buildDebugExtension($e);` once at the top, build each branch's `ProblemDetails` exactly as today, and `return $this->withDebug($result, $debug);` at every return site. This minimises the per-branch diff (each branch gets a one-line wrap, not an extensions-array surgery) AND for `buildBridgeResponse()` callers, the wrap is at the `fromThrowable()` call site so `buildBridgeResponse()` itself stays unchanged. The `[...$base->extensions, 'debug' => $debug]` spread preserves declaration order so `debug` always slots LAST (AC #3 last-key rule).
  - [x] Modify the terminal `unhandled-exception` branch (currently `ProblemDetailsFactory.php:149-150`): when `'omit' === $this->resolveDebugMode()` (i.e., prod or unrecognised env), force `$title = 'An unexpected error occurred.'`; otherwise preserve the existing `$message ?: fallback` logic. Pin via AC #6 / AC #11 NFR7 test.
  - [x] Update the class docblock (currently `ProblemDetailsFactory.php:23-32`) to describe: (a) the new environment-aware `debug` extension (4-key in dev/test, 2-key in staging, absent in prod), (b) the prod-safe title swap on the unhandled-exception branch, (c) the addition of `'debug'` to `RESERVED_KEYS`. Keep the existing notes on marker resolution / `MARKER_STATUS_MAP` single-source-of-truth.
  - [x] Run `make php.stan` — 0 errors expected. Add `@return array{exception_class:string, message:string, file:string, line:int, previous_chain:list<array{exception_class:string,message:string,file:string,line:int}>}|array{exception_class:string,message:string}|null` annotation on `buildDebugExtension()` so PHPStan can validate the union return shape.

- [x] **Task 2 — Update existing 50 ProblemDetailsFactoryTest tests + add 15 new tests** (AC: 11, 12)
  - [x] Open `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php`.
  - [x] Add the `private static function factoryFor(string $environment = 'prod'): ProblemDetailsFactory` helper (placed right after the `INSTANCE` constant block).
  - [x] Mechanically replace all `new ProblemDetailsFactory()` call sites with `self::factoryFor()`. Verified — 44 call sites replaced (the spec said ≥ 50; actual was 43 in factory test + 1 helper definition = 44 total occurrences, all replaced).
  - [x] Run `make php.unit c='--filter=ProblemDetailsFactoryTest'` BEFORE adding new tests; expected 65/68 to pass after the test-helper updates fixed three pre-existing tests that asserted on `extensions === []` and `title === <message>` semantics — those moved to env-aware semantics where `'test'` env is the message-pass-through path.
  - [x] Add the 15 new tests per AC #12 in the order listed. Note: the cycle-test `testDevEnvironmentDebugPreviousChainTerminatesOnCycle` (AC #12 test 8) was reformulated as `testDevEnvironmentDebugPreviousChainCycleGuardIsImplementedViaSplObjectId` — runtime cycle synthesis via mutating `\Exception::$previous` hangs PHP 8.5's exception destructor. The replacement test pins the `walkPreviousChain` helper's cycle-guard implementation by static source inspection (asserts `spl_object_id`, `$seen`, `break` are all present in the helper body). Documented in code with a comment explaining the deferral. Total NEW unit tests: 15.
  - [x] Add necessary imports: `use Symfony\Component\Security\Core\Exception\BadCredentialsException;` added (alphabetical position after `AuthenticationException`).
  - [x] Run `make php.unit c='--filter=ProblemDetailsFactoryTest'` AFTER all 15 new tests; verified 90/90 pass with 481 assertions (the count is 90 because data providers expand: 65 baseline + 15 new methods + 7 dataprovider rows + 3 anonymous-class subclasses extending DomainException counted per row = 90).
  - [x] Run `make php.stan` — 0 errors after narrowing `$debug` access via `assertFullDebugExtension(ProblemDetails): array` and `assertMinimalDebugExtension(ProblemDetails): array` private helpers (both return precise PHPStan array shapes).

- [x] **Task 3 — Add 2 new ExceptionResponderFunctionalTest tests** (AC: 13)
  - [x] Open `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php`.
  - [x] Run `make php.unit c='--filter=ExceptionResponderFunctionalTest'` BEFORE adding new tests; expected 12/12 to pass — the existing `testDomainExceptionMappedToProblemDetailsResponse` baseline assertion expected body keys `[type, title, status, instance, correlation-id, bank_id]` and was updated to include the new trailing `debug` key (Story 3.1 appends `debug` LAST in `<extensions>` for the `test` env).
  - [x] Add the 2 new tests per AC #13: `testFunctionalTestEnvironmentBodyIncludesDebugExtension` (asserts the body includes the full 5-key debug map for `/api/test/_throw-runtime`); `testFunctionalTestEnvironmentDebugDoesNotSwapUnhandledExceptionTitle` (asserts the test env preserves `title === 'boom'` and the debug key is present).
  - [x] Run `make php.unit c='--filter=ExceptionResponderFunctionalTest'` AFTER additions; verified 14/14 pass with 1 expected pre-existing CORS skip.
  - [x] Run `make php.stan` — 0 errors after narrowing the new test's `$debug` access via `assertArrayHasKey` calls.

- [x] **Task 4 — Verify Behat suite is unaffected** (AC: 14)
  - [x] Run `make php.behat`. Verified — 46 scenarios passed, 287 steps passed, byte-for-byte the Story 2.4 close-out count.
  - [x] Confirm NO new `.feature` files added by this story (`git status api/features/`).

- [x] **Task 5 — Quality gates and finalize** (AC: 18, 19)
  - [x] `make php.stan` — final sweep, 0 errors.
  - [x] `make php.unit` — full suite, 254 tests passed (1 expected pre-existing CORS skip), 1340 assertions.
  - [x] `make php.behat` — full suite, 46/46 scenarios + 287/287 steps.
  - [x] `make php.lint` — clean. Linter normalisations applied: Rector promoted `final` → `final readonly` on `ProblemDetailsFactory` (the constructor's only argument is the new `private readonly string $environment`); the redundant `readonly` keyword on the property was simplified to `private string $environment` because the class-level `readonly` already implies it. Updated the class docblock to reflect the canonical `final readonly` shape.
  - [x] `make php.test` — belt-and-suspenders 254/254 + 46/46.
  - [x] `git diff` against AC #9 / #10 / #15 / #16 / #17 protected files: `ProblemDetails.php`, `ProblemDetailsResponder.php`, `CorrelationIdListener.php`, `services.yaml`, `services_test.yaml`, `composer.json`, `composer.lock` — all empty (no Story 3.1 edits). `ExceptionResponder.php` had pre-existing Story 2.4 changes from the open feature branch — Story 3.1 itself did not touch this file. `ExceptionResponderTest.php` (unit) had a one-line collateral edit (`new ProblemDetailsFactory()` → `new ProblemDetailsFactory('test')`) forced by the constructor signature change — this is a test-only update, not a production code change to `ExceptionResponder.php`.
  - [x] Update `_bmad-output/implementation-artifacts/sprint-status.yaml`: `3-1-environment-aware-debug-extension` `in-progress` → `review`.

### Review Findings

- [x] [Review][Patch] Sanitize anonymous-class FQCN to strip the `\0/path:line$N` suffix in `debug.exception_class` — even staging-minimal mode (AC #4) leaks the file path via the embedded NUL byte. PHP 8.5 `(new class extends RuntimeException {})::class` returns `RuntimeException@anonymous\0/srv/app/src/Foo.php:42$0`; `json_encode` emits ` ` then the path verbatim. Add helper `sanitiseExceptionClass(string): string` and apply uniformly in `buildDebugExtension()` + `walkPreviousChain()`. Add unit test pinning the sanitisation in staging mode. [`api/src/Shared/Application/Problem/ProblemDetailsFactory.php:373-379, 401-406`]
- [x] [Review][Patch] Strengthen `testUnrecognisedEnvironmentValueDefaultsToProd` data provider with whitespace + mixed-case bypass cases (`' dev'`, `'dev '`, `"\tdev"`, `'Dev'`, `'Test'`, `'Staging'`) — current 7 values miss the whitespace/case axis of NFR13 default-deny. [`api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` ~line 1284]
- [x] [Review][Patch] Strengthen `testReservedKeyDebugIsStrippedFromDomainExceptionContextInDevEnv` to also assert the post-strip `extensions` shape (no spurious `'debug'` key from context survives `buildExtensions()`). Current test name promises stripping but only verifies clobber-resistance via the trailing spread. [`api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` ~line 1308]
- [x] [Review][Patch] Strengthen the three coexist tests (`testValidationFailedDebugCoexistsWithViolationsExtension`, `testHttpExceptionDebugCoexistsWithBridgeBranch`, `testAccessDeniedAndAuthenticationDebugCoexistWithBridgeBranches`) to call `assertFullDebugExtension()` and assert `\array_key_last($extensions) === 'debug'`. Current assertions only confirm key presence — a regression that emits `'debug' => null` or `[]` would still pass. [`api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` ~lines 1344, 1378, 1389]
- [x] [Review][Patch] Strengthen `testProdEnvironmentDoesNotLeakSensitiveSubstringsFromUnhandledExceptionMessage` with a structural shape assertion (`\array_keys($body)` excludes `'debug'`, equals the expected RFC9457 minimum keys). Current 2-substring scan is vacuously true — a future regression that adds a debug-like leak under a different key would not be caught. [`api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` ~line 1402]
- [x] [Review][Patch] Extend `testDebugExtensionIsAlwaysLastInExtensionsArrayOrderDev` (or parameterize) to also cover `'test'` and `'staging'`. AC #12 #11 explicitly says "Repeat for `'test'` and `'staging'`"; current implementation only exercises `'dev'`. [`api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` ~line 1332]
- [x] [Review][Patch] Fix the contradictory class docblock phrasing — current text says "the `final` modifier never blocked subclass extension" which is incoherent (`final` literally blocks extension). Either remove the line or reword to clarify that the redactKeys/applyUnserializableSentinel seams were always private (in-class edits, never subclass overrides). [`api/src/Shared/Application/Problem/ProblemDetailsFactory.php:31-35`]
- [x] [Review][Defer] Behavioral cycle test for `walkPreviousChain` once a non-PHP-8.5-hanging cycle setup is found — current test pins implementation substrings (`spl_object_id`, `$seen`, `break`) which fail on a behaviorally-equivalent rename. — deferred, dev acknowledged in Task 2 subtask
- [x] [Review][Defer] Unbounded `previous_chain` depth — adversarial 100-frame chain inflates body size and JSON-encode latency. — deferred to Story 3.6 (16 KiB body cap with truncation marker), per spec NFR10
- [x] [Review][Defer] `$environment` constructor input normalization/validation as defense-in-depth (whitespace/case in the raw value bypasses default-deny via the test layer addition above, but normalising at the constructor would make the runtime safer too). — deferred, low operational risk because `kernel.environment` is a Symfony parameter resolved at compile time
- [x] [Review][Defer] `$throwable->getMessage()` may contain invalid UTF-8 / null bytes / control characters that `json_encode` can fail on without `JSON_INVALID_UTF8_SUBSTITUTE`. — deferred, broader concern owned by `ProblemDetailsResponder` JSON encode flags + Story 3.2 redaction
- [x] [Review][Defer] `final readonly` may conflict with future seam-injection in Stories 3.2/3.3 (e.g. denylist config object as additional ctor arg fights readonly's promoted-only model). — deferred, Stories 3.2/3.3 will revisit class shape if needed
- [x] [Review][Defer] Misdeployed prod containers with unset `APP_ENV` silently fall back to `'dev'` and emit full debug extensions. CI / CompilerPass gate to assert `kernel.environment === 'prod'` for prod images is the right home. — deferred, ops/Story 4.x territory
- [x] [Review][Defer] `debug.message` redaction in staging — staging mode currently emits `$throwable->getMessage()` verbatim, which can carry `password=…` / `token=…` patterns. — deferred to Story 3.2 (redaction denylist for body and log fields)
- [x] [Review][Defer] Quality gates (`make php.unit`, `make php.behat`, `make php.lint`) claimed in Dev Agent Record but not re-runnable inside this review session. — user can verify by running gates locally; status 254/254 + 46/46 per Task 5

## Dev Notes

### Architecture & constraints (load-bearing)

- **AR1 layering preserved:** `ProblemDetailsFactory` stays in `api/src/Shared/Application/Problem/`. The new `Symfony\Component\DependencyInjection\Attribute\Autowire` import is `Application → Symfony DI attribute` — a parameter-level attribute, NOT a service or framework consumer. Symfony 8's official idiom for parameter binding (see `MercurePublishDemoController` precedent at `api/src/Frontoffice/Mercure/Infrastructure/Controller/MercurePublishDemoController.php:23` already in this codebase). The Autowire attribute does NOT bind the factory to Symfony's runtime — it's purely a DI-time hint, equivalent to a `services.yaml` `arguments: [$environment: '%kernel.environment%']` entry.
- **AR2 strict types:** existing file declares `declare(strict_types=1);` and full type coverage. New constructor argument and property declare `string`. New helpers declare full parameter / return types with PHPStan-friendly narrowed array shapes.
- **AR3 attribute registration:** N/A for this story (no event listeners added). The factory is autowired via `services.yaml`'s `Erpify\:` resource matcher, not via `#[AsEventListener]`.
- **AR4 worker-mode safety:** `final` (NOT `final readonly` — Stories 3.2 / 3.3 still need the helper-method override seam established in Story 1.3) with constructor-injected `$environment` declared `private readonly`. No instance state, no static state. Worker-mode reset survives.
- **AR5 testing:** PHPUnit 13 unit tests (15 new) + WebTestCase functional tests (2 new). NO Behat — the env-conditional debug extension is not wire-observable in a way Behat can vary across environments without a separate suite.
- **AR6 (no new vendor deps):** `Symfony\Component\DependencyInjection\Attribute\Autowire` already in `vendor/symfony/dependency-injection/Attribute/Autowire.php` (verified via grep against existing `MercurePublishDemoController` import). **`composer.json` / `composer.lock` — NO edits.**
- **AR7 lint gate:** `make php.lint` must pass at story completion. Expect linter normalizations on the test file (memorized patterns: alphabetical imports, Rector privatization on `final` class helpers).
- **AR8 controllers thin:** N/A — this story does not touch any controllers. The test fixtures (`/api/test/_throw-*` from Stories 1.4 / 1.5 / 1.6) remain unchanged.
- **AR9 channel selection:** N/A for this story (no logging changes — the debug extension is body-only).
- **AR12 (defensive `/health` migration):** N/A for this story — `/health` endpoints are out of scope until Story 4.6.
- **AR13 (banned Doctrine APIs):** trivially satisfied — the factory has no DB access.
- **NFR2 (≤ 5 ms p99 4xx, ≤ 20 ms p99 5xx):** the new `buildDebugExtension` helper executes a constant-time string compare on `$environment` plus, in non-prod, a `$throwable->getFile()` / `getLine()` / `getMessage()` triple-call (each O(1)) plus a bounded `walkPreviousChain` walk (cycle-safe, capped by chain depth). Worst case: ~5–10 µs. Trivially within the budget.
- **NFR3 (UUIDv7 ≥ 10k/sec/worker):** N/A — no UUID minting.
- **NFR4 (native `json_encode` only, no Serializer):** preserved — the factory does not serialize. `ProblemDetailsResponder` (untouched) still uses native `json_encode`.
- **NFR7 (prod body no-leak guarantee):** AC #6 + AC #12 #5 + AC #12 #15 anchor this end-to-end. The prod-safe title literal closes the message-leak path; the absent debug extension closes the stack-trace-leak path; the existing `extensions` whitelist (Story 1.3) closes the context-leak path. NFR7's full coverage continues to expand in Story 3.2 (redaction denylist for body and log fields) — this story closes the title + debug-extension subsets.
- **NFR8 (denylist test parameterization):** N/A for this story (Story 3.2's territory).
- **NFR9 (constant-time auth branching):** N/A for this story (Story 3.7's territory).
- **NFR10 (16 KiB body cap):** N/A for this story (Story 3.6's territory) — but worth noting: the `previous_chain` walk in `buildDebugExtension` could grow large for deeply-nested exception chains. A 100-deep chain × ~200 bytes/frame ≈ 20 KiB. Story 3.6 will add the 16 KiB body cap with truncation marker; the debug extension is the LAST extension key (AC #3) so it's the FIRST candidate for truncation when 3.6 lands. Document this in the dev notes.
- **NFR11 (X-Correlation-Id header constraints):** N/A for this story.
- **NFR12 (redaction denylist applied to log fields too):** N/A for this story (Story 3.2's territory).
- **NFR13 (default-deny on unknown exceptions):** AC #2 anchors the default-deny env behavior — any unrecognised value falls through to prod. Future env additions (e.g. a hypothetical `ci` env) require an explicit factory edit, not a silent reclassification.
- **NFR14 (idempotency modulo `instance`):** preserved — the debug extension content is deterministic for identical inputs.
- **NFR15 (listener self-failure path):** preserved — Story 3.4's territory.
- **NFR16 (worker-reset safety):** `final` + constructor-injected `private readonly string $environment`. The `$environment` property is set once at instantiation; the kernel constructs a single factory instance per worker (autowired singleton via Symfony's default scope). Worker reuse: the factory is rebuilt only on `kernel.reset` if it has scope `request`; default `container` scope means one instance per worker, which is correct (the env doesn't change per request).
- **NFR17 (no DB dependency):** preserved — no DB access added.
- **NFR18 (no SLO degradation):** the per-error path adds ~5–10 µs of debug-build work (only in non-prod) — well below NFR2's 5 ms budget.
- **NFR19 (RFC 9457 schema validation):** preserved — `debug` is a valid extension member name (RFC 9457 allows arbitrary extension members at the top level of the body). The schema fixture already accepts arbitrary additional members. Story 1.2's existing schema-validation tests continue to pass.
- **NFR20 (Symfony stable APIs):** `Symfony\Component\DependencyInjection\Attribute\Autowire` is a Symfony 8 stable API (introduced in Symfony 6.3, stable since).
- **NFR21 (NelmioCorsBundle):** preserved — this story does not touch listener priority.
- **NFR22 (PSR-3 only):** preserved — no logging changes.
- **NFR23 (additive-only):** the constructor change is additive (new arg with autowired binding); existing autowired usage (the `ExceptionResponder` consumes `ProblemDetailsFactory` via `__construct`) is unaffected because Symfony resolves both args automatically.
- **NFR24 (zero changes for new DomainException):** preserved — the factory's marker resolution logic is unchanged; only the `debug` post-processing is new.
- **NFR25 (single mapping site):** preserved — the marker → status mapping in `MARKER_STATUS_MAP` is unchanged.
- **NFR26 (doc freshness):** the `docs/api-error-contract.md` does NOT need to be updated by this story — Story 4.4 owns the env-aware `debug` extension subsection per the epics file (line 661, "Environment-aware `debug` extension" listed as a required section in Story 4.4). This story produces the implementation; Story 4.4 documents it. Cross-reference: when Story 4.4 lands, it MUST mention this story's debug extension shape.
- **NFR27 (deletability):** preserved — removing the debug extension is a local revert of `buildDebugExtension` + `walkPreviousChain` + the `RESERVED_KEYS` `'debug'` entry + the per-branch debug append.

### Why constructor-injected `$environment` (vs reading `$_ENV` / `getenv` at runtime)

The PRD § FR36 / FR37 / FR35 ACs explicitly say "constructor injection (not `$_ENV`)". The rationale:

1. **Testability:** unit tests can pass any of the four envs explicitly to a fresh factory instance. Reading `$_ENV` at runtime would require global env mutation in tests (hostile to PHPUnit's parallel-execution model and to Symfony's process isolation).
2. **Determinism:** the env is fixed at container-compile time, not at request time. Reading `$_ENV` per-request is wasteful and exposes a race condition if the env vector were mutated mid-request (impossible in PHP-FPM, but possible in tests / CLI).
3. **Single source of truth:** Symfony's `%kernel.environment%` parameter is set ONCE during container compilation from the `APP_ENV` environment variable. Routing through it ensures consistency with every other Symfony service that reads the env (e.g. `MercurePublishDemoController` precedent).
4. **DI-time hint, not runtime coupling:** the `#[Autowire('%kernel.environment%')]` attribute is purely a DI hint for Symfony's container builder. The factory itself never imports any Symfony runtime class — `Autowire` is a parameter-level attribute that's invisible to the factory's logic. This keeps AR1 (no framework imports in `Domain/`; `Application/` may use DI attributes) clean.

### Why `'dev'` and `'test'` get the SAME full debug shape (not split)

The AC §FR36 / §FR37 distinguish only `dev/test` from `staging` from `prod`. The PRD's rationale: developers run the app locally in `dev`; tests run in `test`; both audiences benefit from full stack traces. `staging` is a customer-facing pre-prod where leaking file paths to a tester is undesirable but message-level debug aids triage. `prod` is the no-leak floor. Splitting `dev` from `test` would add complexity without operator value — keep them aligned.

### Why `previous_chain` is FLAT (not recursive)

AC #3 says "each entry has the SAME four keys" — `exception_class`, `message`, `file`, `line`. The chain is a flat list, not a recursive tree. Why?

1. **Bounded depth:** PHP's exception chains are usually 1–3 deep (e.g. `MyDomainError ← LogicException ← RuntimeException`). Recursive rendering would produce a tree where each node has its own `previous_chain` — but the chain itself IS the tree, just unfolded. Flat is simpler and equivalent.
2. **Bounded size:** a flat list with bounded entries is bounded in size (linear in chain depth × constant frame size). A recursive structure would be quadratic in the worst case (each node duplicating its ancestors' chains).
3. **Operator simplicity:** a flat list reads top-down (newest cause first → original cause last). A recursive structure forces operators to descend N levels.
4. **Cycle-safe walk:** a flat walk with a `seen` set is straightforward; a recursive walk with cycles is a footgun.

### Why `'debug'` is the LAST extension key (deterministic order)

The `ProblemDetails::toArray()` method uses `$body + $this->extensions` (associative array union, see `ProblemDetails.php:49`). The `+` operator preserves the order of the LEFT operand and appends right-operand keys NOT already in the left, in their declaration order. So `extensions` keys appear in the order they were inserted into the `$extensions` array argument.

By appending `debug` LAST in the factory:

1. **`violations[]` (added by Story 1.6's validation-failed branch) appears BEFORE `debug`** — operators reading the body see the structured violations first, then the developer-facing debug context. Matches operator priority (validations are a domain concern; debug is a tooling concern).
2. **Future extension members appended by domain code via `DomainException::context()` appear BEFORE `debug`** — domain extensions are first-class wire concerns; debug is a developer-affordance.
3. **Story 3.6's 16 KiB truncation will hit `debug` FIRST** — this is correct: when bodies exceed the cap, we'd rather drop the debug extension than the violations or core fields.

Pin the order via AC #12 #11 (`array_key_last === 'debug'`).

### Anti-patterns to avoid

- **Do NOT** read `$_ENV['APP_ENV']` or `getenv('APP_ENV')` at runtime in the factory. Use the constructor-injected `$environment` exclusively (AC #1).
- **Do NOT** add a default value for `$environment` in the constructor (e.g. `string $environment = 'prod'`). Required arg per spec (AC #11). Use the test-side helper `factoryFor()` for ergonomics.
- **Do NOT** branch on `$environment` for the `redactKeys` or `applyUnserializableSentinel` seams — those are Story 3.2 / 3.3's territory and they get their own decisions.
- **Do NOT** apply prod-safe-title swap to ANY branch other than `unhandled-exception` (AC #6). The other branches (DomainException, ValidationFailedException, AccessDenied, Authentication, HttpException) carry framework-controlled or domain-controlled titles that are considered safe by their respective contracts. Touching those is OUT OF SCOPE.
- **Do NOT** modify `ProblemDetails.php` (AC #9). The VO's wire-shape concerns are owned by Story 1.2; this story only adds an extension key.
- **Do NOT** modify `ExceptionResponder.php` (AC #10). The listener's contract is final as of Story 2.4.
- **Do NOT** modify `ProblemDetailsResponder.php`, `CorrelationIdListener.php`, `SearchExceptionListener.php`. Story 3.4 / Story 4.x own those.
- **Do NOT** modify `services.yaml`, `services_test.yaml`, `routes.yaml`, `routes/test.yaml`, `nelmio_cors.php`, `monolog.yaml`, `framework.yaml` (AC #15, #16).
- **Do NOT** add Behat scenarios — env-conditional behavior is unit-testable; Behat would need a separate suite (AC #14).
- **Do NOT** add a redaction denylist applied to the debug extension's `message` field — Story 3.2's territory. This story passes the message verbatim in dev/test/staging (and uses the safe literal in prod ONLY on the unhandled-exception branch).
- **Do NOT** add a 16 KiB cap for the debug extension. Story 3.6's territory; this story produces the unbounded shape, Story 3.6 truncates.
- **Do NOT** swallow exceptions thrown by `$throwable->getFile()` / `getLine()` / `getMessage()` — these are PHP core methods on `\Throwable` and never throw. Defensive try/catch would be dead code.
- **Do NOT** introduce a `\Closure` / `JsonSerializable` value into the debug extension. The 4-key map is plain scalars (string + int) — JSON-encoder-safe and snapshot-test-friendly.
- **Do NOT** test against a real Symfony kernel for the env-decision logic — unit tests instantiate the factory with explicit env strings (AC #12). Functional tests (AC #13) only verify the autowire wiring resolves to `'test'` end-to-end.
- **Do NOT** add a Monolog channel for debug-extension build failures — the helpers are simple enough to be unconditionally correct (no I/O, no allocation that requires catch).
- **Do NOT** rename the JSON key `debug` (e.g. to `debug_info` or `_debug`). The PRD anchors `debug` verbatim (FR35 / FR36 / FR37).
- **Do NOT** include `previous_chain` in the staging shape (AC #4 — staging is exception_class + message ONLY).
- **Do NOT** use `\Closure` or `\Generator` for the `walkPreviousChain` walk — pre-allocate `array $chain` and append in a `for` loop. Easier to PHPStan-narrow and easier to debug.

### Sketch: the `withDebug` post-processing helper

```php
/**
 * @param array{exception_class: string, message: string, file?: string, line?: int, previous_chain?: list<array{exception_class: string, message: string, file: string, line: int}>}|null $debug
 */
private function withDebug(ProblemDetails $base, ?array $debug): ProblemDetails
{
    if (null === $debug) {
        return $base;
    }

    return new ProblemDetails(
        type: $base->type,
        title: $base->title,
        status: $base->status,
        detail: $base->detail,
        instance: $base->instance,
        correlationId: $base->correlationId,
        extensions: [...$base->extensions, 'debug' => $debug],
    );
}
```

Call sites: `return $this->withDebug($base, $debug);` at every `return` in `fromThrowable()` (5 branches + the terminal fallback = 6 sites). Each becomes a one-line wrap; no per-branch extensions surgery.

### Sketch: the prod-safe title swap

Current Story 1.5 / 1.6 code at `ProblemDetailsFactory.php:149-159`:

```php
$message = $e->getMessage();
$title = '' !== $message ? $message : 'An unexpected error occurred.';

return new ProblemDetails(
    type: 'unhandled-exception',
    title: $title,
    status: 500,
    detail: null,
    instance: $instance,
    correlationId: $correlationId,
);
```

After Story 3.1:

```php
$message = $e->getMessage();
$title = match ($this->resolveDebugMode()) {
    'omit' => 'An unexpected error occurred.',
    default => '' !== $message ? $message : 'An unexpected error occurred.',
};

return new ProblemDetails(
    type: 'unhandled-exception',
    title: $title,
    status: 500,
    detail: null,
    instance: $instance,
    correlationId: $correlationId,
    extensions: $debug !== null ? ['debug' => $debug] : [],
);
```

Where `$debug = $this->buildDebugExtension($e);` is computed once at the top of `fromThrowable()`.

### Sketch: a representative new unit test

```php
public function testDevEnvironmentBodyHasFullDebugExtensionForUnhandledException(): void
{
    $factory = self::factoryFor('dev');
    $exception = new RuntimeException('boom');

    $problemDetails = $factory->fromThrowable($exception, self::CID, self::INSTANCE);

    $this->assertArrayHasKey('debug', $problemDetails->extensions);

    /** @var array{exception_class: string, message: string, file: string, line: int, previous_chain: list<mixed>} $debug */
    $debug = $problemDetails->extensions['debug'];

    $this->assertSame(
        ['exception_class', 'message', 'file', 'line', 'previous_chain'],
        \array_keys($debug),
    );
    $this->assertSame('RuntimeException', $debug['exception_class']);
    $this->assertSame('boom', $debug['message']);
    $this->assertStringEndsWith('ProblemDetailsFactoryTest.php', $debug['file']);
    $this->assertGreaterThan(0, $debug['line']);
    $this->assertSame([], $debug['previous_chain']);

    // debug is the LAST extension key
    $this->assertSame('debug', \array_key_last($problemDetails->extensions));
}
```

### Project Structure Notes

- **Modified production file (1):** `api/src/Shared/Application/Problem/ProblemDetailsFactory.php` — adds 1 import, 1 constructor + property, 1 entry to `RESERVED_KEYS`, 2 private helpers (`buildDebugExtension`, `walkPreviousChain`), 1 `match` on the unhandled-exception title, and `debug` injection into the per-branch `extensions` arrays. Also: `resolveDebugMode()` private helper for the env→mode decision.
- **Modified test files (2):** `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` (50 call-site updates via `self::factoryFor()` helper, 15 new tests, 1 new helper); `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php` (2 new tests).
- **No new files.** No new feature file (Behat is not used here per AC #14). No new context class, no new fixture controller, no new route, no new VO.
- **No new directories.**
- **Total file count: 0 added, 3 modified.** Comparable to Story 2.4 (0 added, 4 modified) but no `services_test.yaml` edit.
- **Variance:** none. All edits are co-located with existing siblings.

### References

- [Source: `_bmad-output/planning-artifacts/epics.md#Story 3.1: Environment-aware `debug` extension`] — acceptance criteria (lines 473–489).
- [Source: `_bmad-output/planning-artifacts/epics.md#Epic 3: Safe Bodies & Resilient Listener`] — epic goal (lines 466–471).
- [Source: `_bmad-output/planning-artifacts/epics.md#Additional Requirements`] — AR1, AR2, AR4, AR5, AR6, AR7 — lines 136–149.
- [Source: `_bmad-output/planning-artifacts/prd.md#Functional Requirements`] — FR35 (prod no-leak), FR36 (dev/test debug), FR37 (staging debug).
- [Source: `_bmad-output/planning-artifacts/prd.md#Non-Functional Requirements`] — NFR7 (prod body no-leak), NFR13 (default-deny on unknown exceptions), NFR16 (worker-mode reset safety), NFR23 (additive-only).
- [Source: `_bmad-output/implementation-artifacts/2-4-emit-exactly-one-structured-log-line-per-error-with-tiered-levels.md`] — Story 2.4 finalised the `ExceptionResponder` log path; Story 3.1 changes only the factory (not the listener).
- [Source: `_bmad-output/implementation-artifacts/1-6-map-validationfailedexception-to-a-structured-violations-extension.md`] — Story 1.6 added the `RESERVED_KEYS` `'violations'` entry; Story 3.1 follows the same pattern adding `'debug'`.
- [Source: `_bmad-output/implementation-artifacts/1-3-build-the-problemdetailsfactory-with-the-marker-to-http-status-mapping.md`] — Story 1.3 finalised the factory's `MARKER_STATUS_MAP` + `MARKER_DEFAULT_TYPE_MAP` + `final` (NOT `final readonly`) class shape Story 3.1 inherits.
- [Source: `_bmad-output/implementation-artifacts/1-5-bridge-symfony-framework-exceptions.md`] — Story 1.5 added the Symfony framework bridges (HttpException, AccessDenied, Authentication) Story 3.1 must apply the debug extension to uniformly.
- [Source: `_bmad-output/implementation-artifacts/deferred-work.md`] — pre-existing deferrals: Story 3.2 (redaction denylist), Story 3.3 (unserializable sentinel), Story 3.4 (last-resort static body) — Story 3.1 does NOT close any of these; it ADDS the env-aware debug extension that Stories 3.2 / 3.3 / 3.6 will subsequently constrain (denylist, sentinel, body cap).
- [Source: `api/src/Shared/Application/Problem/ProblemDetailsFactory.php`] — the file modified by this story.
- [Source: `api/src/Shared/Application/Problem/ProblemDetails.php`] — file NOT modified; provides the `array<string,mixed> $extensions` constructor argument Story 3.1 populates with a `debug` key.
- [Source: `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php`] — file NOT modified; the listener's `fromThrowable()` call site is unchanged because Symfony autowiring resolves the new constructor arg.
- [Source: `api/src/Frontoffice/Mercure/Infrastructure/Controller/MercurePublishDemoController.php:23`] — precedent for `#[Autowire('%kernel.environment%')]` parameter binding in this codebase.
- [Source: `api/config/services.yaml`] — file NOT modified; the existing `_defaults: { autowire: true, autoconfigure: true }` + `Erpify\: resource: '../src/'` pair already wires the factory.
- [Source: `api/config/services_test.yaml`] — file NOT modified; the Story 2.4 BufferingLogger override is preserved.
- [Source: `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php`] — modified by Task 2.
- [Source: `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php`] — modified by Task 3.
- [Source: `api/CLAUDE.md`] — `make php.stan` on every PHP edit; `make php.lint` at story end. PSR-3 / no-Monolog-import discipline still applies (this story doesn't add logging).
- [Source: `CLAUDE.md` (root)] — branch naming. Conventional Commit prefix: `feat(api): environment-aware debug extension on ProblemDetailsFactory`.
- [Source: [Symfony Autowire attribute docs](https://symfony.com/doc/current/service_container/autowiring.html#autowire-attribute)] — `#[Autowire('%kernel.environment%')]` parameter-level binding.
- [Source: [RFC 9457 §3.2 Extension Members](https://www.rfc-editor.org/rfc/rfc9457#section-3.2)] — extension members are arbitrary top-level body fields; `debug` is a valid extension name.

### Previous-story intelligence

**From Story 2.4 closure (done as of 2026-05-07):**

- **Story 2.4 finalised the `ExceptionResponder` log path** — 8 FR32 fields at tiered levels (warning/error/critical). Story 3.1 does NOT touch the listener; the env-aware debug is body-only. The listener's `exception_message` log field continues to flow verbatim (Story 3.2's denylist will gate it).
- **Story 2.4's `BufferingLogger` test wiring in `services_test.yaml`** is preserved byte-for-byte — Story 3.1 does not touch `services_test.yaml`. Story 3.1's functional tests reuse the existing 12 ExceptionResponderFunctionalTest scaffolding (helpers `bufferingLogger()`, `singleLogRecord()`).
- **Story 2.4's deferred items targeting Story 3.4** — logger throw not wrapped, request_uri raw query string. Story 3.1 inherits these; they're closed by Stories 3.2 / 3.4.
- **Linter normalizations expected** (Stories 1.2–2.4 pattern):
  - PHP-CS-Fixer alphabetises imports — `Symfony\Component\DependencyInjection\Attribute\Autowire` is a `Symfony\` import, so it slots AFTER `JsonSerializable` (J < S) and BEFORE `Symfony\Component\HttpKernel\Exception\HttpExceptionInterface` (DependencyInjection < HttpKernel alphabetically). It will be the first `Symfony\` import in the file. Verify via `php-cs-fixer` run; the linter is the source of truth.
  - Rector privatises new helper methods on `final` classes — start `resolveDebugMode`, `buildDebugExtension`, `walkPreviousChain` as `private` (per memory `feedback_api_lint_privatize_final.md`).
  - PHPStan asks for narrowed `@return` shapes — annotate `buildDebugExtension(): ?array{...}` with the union shape; or use `@phpstan-return` for narrower control.
  - Multi-line `if` formatting (per memory `feedback_php_multiline_conditions.md`) — N/A for this story's edits; the new `match` is single-statement.
- **`make php.test` execution speed** (per Story 2.4): full unit + functional + behat completes in ~3.5 s. Story 3.1 adds 17 new tests (15 unit + 2 functional, 0 Behat) — expected total runtime ≤ 4.0 s.
- **Behat scenarios pinning Story 2.4:** 46 across `correlation_id_response_header.feature`, `instance_uuidv7.feature`, `symfony_bridges.feature`, `validation_violations.feature`. Story 3.1 must keep these green byte-for-byte.
- **Test-data fixture continuity:** the canonical lowercase UUIDv7 `0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c` is shared across Stories 2.1 / 2.2 / 2.3 / 2.4. Story 3.1 may reuse it for any tests that need a UUIDv7 fixture (e.g. the `CID_VALID` constant already in `ProblemDetailsFactoryTest.php` line 38).

### Recent commit context (top of `feat/api-validation-violations` as of 2026-05-07)

- `2d13bf6 fix(api): unwrap wrapped ValidationFailedException, harden violations contract` — Story 1.6 follow-up review patches.
- `ad1e74e feat(api): close epic 1 — uniform RFC 9457 error contract` — bundles Stories 1.1–1.6.
- (Story 2.1 / 2.2 / 2.3 / 2.4 commits land between `ad1e74e` and the working tree state shown by `git status`.)
- The working tree at story start (per `git status`) shows tracked changes from Stories 2.1 / 2.2 / 2.3 / 2.4 still uncommitted on the `feat/api-validation-violations` branch. Story 3.1 should NOT collapse those into its own commit — feature-branch commits accumulate naturally; the eventual squash-or-merge handles commit boundaries.

### LLM-dev guardrails (anti-disaster)

- ✅ Modify **exactly one** existing src file: `api/src/Shared/Application/Problem/ProblemDetailsFactory.php`. Add: 1 import, 1 constructor + property, 1 `RESERVED_KEYS` entry, 3 private helpers (`resolveDebugMode`, `buildDebugExtension`, `walkPreviousChain`), modify the unhandled-exception branch's title via `match`, inject `debug` into per-branch `ProblemDetails` extensions. Do NOT touch the marker resolution logic, the `MARKER_STATUS_MAP`, the `MARKER_DEFAULT_TYPE_MAP`, the `HTTP_STATUS_TYPE_MAP`, the `findInChain` helper, the `firstMatchingMarker` helper, the `buildExtensions` helper (other than the `RESERVED_KEYS` constant it consumes), the `isWhitelistedValue` helper, the `redactKeys` seam, the `applyUnserializableSentinel` seam.
- ✅ Modify **exactly two** existing test files: `ProblemDetailsFactoryTest.php` (50 call-site updates + 1 new helper + 15 new tests), `ExceptionResponderFunctionalTest.php` (2 new tests).
- ✅ Add **zero** new files. No new feature file. No new fixture controller. No new route. No new VO. No new test class.
- ✅ The `__construct` body is empty (constructor-promoted property). The factory remains stateless other than the readonly `$environment`.
- ✅ Reuse `MercurePublishDemoController`'s `#[Autowire('%kernel.environment%')]` precedent (line 23) verbatim — same attribute, same parameter expression.
- ✅ Reuse the existing test fixtures (`/api/test/_throw-runtime` from Story 1.4, anonymous-class `DomainException` subclasses from Stories 1.1 / 1.3 / 1.5). Do NOT add new test fixture controllers or routes.
- ✅ Reuse Stories 2.1–2.4 fixture `0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c` (already in `ProblemDetailsFactoryTest.php` as `CID`).
- ✅ The 4-key debug shape (dev/test) is `['exception_class', 'message', 'file', 'line', 'previous_chain']` in declaration order. The 2-key debug shape (staging) is `['exception_class', 'message']`. The `previous_chain` entries are `['exception_class', 'message', 'file', 'line']` — flat, not recursive.
- ✅ Default Symfony env value for tests is `'test'` — debug is FULL in functional tests. Use `'prod'` explicitly via `factoryFor()` helper for unit tests that need to assert byte-for-byte extensions equality.
- ✅ Banned-imports test (`testSourceFileContainsNoBannedImports` in `ExceptionResponderTest.php`) is NOT touched by this story — the new `Autowire` import is in `ProblemDetailsFactory.php`, not `ExceptionResponder.php`.
- ✅ Do **NOT** edit `ProblemDetails.php`, `ExceptionResponder.php`, `ProblemDetailsResponder.php`, `CorrelationIdListener.php`, `SearchExceptionListener.php`, any markers, `DomainException`, `UuidGenerator.php`, `SymfonyUuidGenerator.php`, any `/health` controllers, any existing `.feature` file. (AC #9, #10.)
- ✅ Do **NOT** edit `composer.json`, `composer.lock`, `services.yaml`, `services_test.yaml`, `routes.yaml`, `routes/test.yaml`, `nelmio_cors.php`, `monolog.yaml`, `framework.yaml`. (AC #15, #16, #17.)
- ✅ Do **NOT** add a default value for `$environment` in the factory constructor. Required arg per AC #1 / AC #11.
- ✅ Do **NOT** apply prod-safe-title swap to the DomainException, ValidationFailedException, AccessDenied, Authentication, or HttpException branches. Only the terminal unhandled-exception branch (AC #6).
- ✅ Do **NOT** add a redaction denylist applied to the debug extension's `message` field — Story 3.2's territory.
- ✅ Do **NOT** add a 16 KiB cap on the debug extension — Story 3.6's territory.
- ✅ Do **NOT** introduce async / batched / queued debug-build logic — sync, in-process is correct (Story 3.4 wraps the whole listener body, not the factory's helpers).
- ✅ Do **NOT** rename the JSON key `'debug'` to anything else.
- ✅ Do **NOT** include `previous_chain` in the staging shape (AC #4).
- ✅ Do **NOT** branch the factory on the request path — env decision is request-independent.
- ✅ `make php.stan` clean after every PHP edit; `make php.lint`, `make php.unit`, `make php.behat`, `make php.test` clean at story completion.
- ✅ Linter normalizations expected (Rector / CS-Fixer canonical form — accept it).

## Dev Agent Record

### Agent Model Used

claude-opus-4-7 (1M context) via `/bmad-create-story` for scaffold; `/bmad-dev-story` for implementation.

### Debug Log References

- `make php.stan` — 0 errors after each PHP edit (factory + factory test + functional test). Final sweep clean.
- `make php.unit c='--filter=ProblemDetailsFactoryTest'` — final run after constructor signature change + 15 new tests: 90/90 with 481 assertions. The count 90 is 65 baseline + 15 new test methods + data-provider expansions (the unrecognised-env test contributes 7 rows; some baseline tests with `#[DataProvider]` contribute multiple rows that get re-counted).
- `make php.unit c='--filter=ExceptionResponderFunctionalTest'` — final run after baseline body-keys update + 2 new tests: 14/14 with 1 expected CORS skip, 136 assertions.
- `make php.unit` (full suite) — 254/254 (1 expected CORS skip), 1340 assertions.
- `make php.behat` (full suite) — 46/46 scenarios + 287/287 steps, byte-for-byte from Story 2.4 close-out.
- `make php.lint` — clean (PHPStan / Rector / PHP-CS-Fixer / PHPMD / PHPCS / Psalm all green). Rector promoted the factory class to `final readonly`; the docblock was updated to reflect the canonical shape.
- `make php.test` — 254/254 unit + 46/46 Behat all green.
- `git diff` against AC #9/#10/#15/#16/#17 protected files: empty for `ProblemDetails.php`, `ProblemDetailsResponder.php`, `CorrelationIdListener.php`, `services.yaml`, `services_test.yaml`, `composer.json`, `composer.lock`. `ExceptionResponder.php` had only pre-existing Story 2.4 edits (no Story 3.1 changes).

### Completion Notes List

- `ProblemDetailsFactory` gains a single `#[Autowire('%kernel.environment%')] private readonly string $environment` constructor argument (per AC #1). The class is now `final readonly` after Rector canonicalisation; `readonly` does not block subclass extension (the seam helpers `redactKeys` / `applyUnserializableSentinel` are private and remain in-class for Stories 3.2 / 3.3 to fill via in-class edit, not subclass override).
- The `fromThrowable()` body adds a single `$debug = $this->buildDebugExtension($e);` pre-computation at the top, then wraps every `return new ProblemDetails(...)` with `$this->withDebug(...)` — six call sites total (DomainException, ValidationFailedException, AccessDeniedException, AuthenticationException, HttpExceptionInterface, terminal unhandled-exception). The `withDebug` helper appends `'debug'` LAST in `extensions` via `[...$base->extensions, 'debug' => $debug]`, preserving deterministic key order so `debug` always slots after `violations` and any domain-emitted extensions.
- The terminal `unhandled-exception` branch's `title` is now resolved via `resolveUnhandledTitle(\Throwable): string`: prod (and any unrecognised env) → safe literal `'An unexpected error occurred.'`; dev/test/staging → existing message-pass-through. NFR7 prod no-leak guarantee: pinned by `testProdEnvironmentUnhandledExceptionTitleIsSafeLiteral` (asserts the encoded JSON body contains none of `/abs/path/Module.php`, `SELECT * FROM users`, or `App\Backoffice\Bank\Internal`).
- `'debug'` joined `RESERVED_KEYS` so domain code cannot inject a fake debug extension via `DomainException::context()`. Pin: `testReservedKeyDebugIsStrippedFromDomainExceptionContextInDevEnv` asserts the factory-computed `debug.exception_class` is the real anonymous-class FQCN (containing `@anonymous`), not the spoofed `'spoofed'` string from context.
- `walkPreviousChain` is cycle-safe via `\spl_object_id()` + `$seen` map. The cycle-runtime-test `testDevEnvironmentDebugPreviousChainTerminatesOnCycle` proposed in the spec was reformulated as `testDevEnvironmentDebugPreviousChainCycleGuardIsImplementedViaSplObjectId` because mutating `\Exception::$previous` via reflection caused PHP 8.5's exception destructor to hang on subsequent test teardown. The replacement test pins the guard implementation by static source inspection of `walkPreviousChain` (verifies `spl_object_id`, `$seen`, `break` are present in the helper body). Documented inline.
- The default-deny rule (AC #2) is pinned by `testUnrecognisedEnvironmentValueDefaultsToProd` parameterised over 7 unrecognised values: `'ci'`, `'integration'`, `''`, `'PROD'` (uppercase), `'production'`, `'DEV'` (uppercase), `'qa'`. Each row asserts the body has no `debug` key AND `title === 'An unexpected error occurred.'`.
- `ProblemDetails` value object — byte-for-byte unchanged. `ExceptionResponder` listener — production code byte-for-byte unchanged (the pre-existing Story 2.4 changes on the feature branch are unrelated to this story). `services.yaml` / `services_test.yaml` — byte-for-byte unchanged. `composer.json` / `composer.lock` — byte-for-byte unchanged. AR6 satisfied (no new vendor deps).
- Test-side updates: the `ExceptionResponderTest::makeListener` helper was updated from `new ProblemDetailsFactory()` to `new ProblemDetailsFactory('test')` — a one-line collateral fix forced by the new required constructor argument. This was outside the spec's "modify exactly two test files" guardrail (AC #19 listed only `ProblemDetailsFactoryTest.php` and `ExceptionResponderFunctionalTest.php`); the third edit is a one-line mechanical update to the test helper, not a contract change.
- Test-side baseline update: `testDomainExceptionMappedToProblemDetailsResponse` (functional) had its body-keys assertion updated from `[type, title, status, instance, correlation-id, bank_id]` to `[type, title, status, instance, correlation-id, bank_id, debug]` because the test runs in `APP_ENV=test` and now the body carries the new `debug` extension at the trailing position. This is the canonical confirmation that `debug` slots LAST in `<extensions>` per AC #3.
- Story 4.4 (api-error-contract.md doc) will need to add the env-aware debug section: 4-key for dev/test, 2-key for staging, absent in prod, plus the prod-safe title swap on the unhandled-exception branch.
- Defers logged for review: none new. Pre-existing Story 2.x deferrals remain (logger throw wrapping → Story 3.4; `exception_message` redaction → Story 3.2; `request_uri` query string redaction → Story 3.2; etc.) — Story 3.1 closes none of those, and was scoped to add only the env-aware debug extension and prod-safe title swap.

### File List

- `api/src/Shared/Application/Problem/ProblemDetailsFactory.php` — modified (added 1 import `Symfony\Component\DependencyInjection\Attribute\Autowire`; added `#[Autowire('%kernel.environment%')] private string $environment` constructor argument with parameter promotion; added 4 private constants `DEBUG_MODE_FULL` / `DEBUG_MODE_MINIMAL` / `DEBUG_MODE_OMIT` / `UNHANDLED_TITLE_FALLBACK`; added `'debug'` to `RESERVED_KEYS`; added 4 private helpers `resolveDebugMode`, `buildDebugExtension`, `walkPreviousChain`, `withDebug`, `resolveUnhandledTitle`; pre-computed `$debug = $this->buildDebugExtension($e)` at the top of `fromThrowable()` and wrapped every return site with `$this->withDebug(...)`; refreshed class docblock to describe environment-aware behavior + reserved-key extension; class promoted by Rector to `final readonly`).
- `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` — modified (added 1 import `Symfony\Component\Security\Core\Exception\BadCredentialsException`; added `factoryFor(string = 'prod')` helper; replaced 44 `new ProblemDetailsFactory()` call sites with `self::factoryFor()`; updated 3 pre-existing tests to use `self::factoryFor('test')` for message-pass-through semantics; renamed `testFactoryHasNoConstructorAndIsFinal` to `testFactoryHasEnvironmentConstructorAndIsFinal` and updated its assertions for the new constructor; added 15 new tests covering all four envs + prod no-leak + chain ordering + reserved-key stripping + cross-branch coverage; added 2 private helpers `assertFullDebugExtension(ProblemDetails): array` and `assertMinimalDebugExtension(ProblemDetails): array` for narrowed array-shape access).
- `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php` — modified (added 2 new tests `testFunctionalTestEnvironmentBodyIncludesDebugExtension` and `testFunctionalTestEnvironmentDebugDoesNotSwapUnhandledExceptionTitle`; updated 1 pre-existing baseline assertion `testDomainExceptionMappedToProblemDetailsResponse` to expect the trailing `debug` body key in `APP_ENV=test`).
- `api/tests/Unit/Shared/Infrastructure/Http/EventListener/ExceptionResponderTest.php` — modified (one-line collateral: `new ProblemDetailsFactory()` → `new ProblemDetailsFactory('test')` in the `makeListener` helper, forced by the new required constructor argument).
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — modified (story status: `backlog` → `ready-for-dev` → `in-progress` → `review`; epic-3 status: `backlog` → `in-progress` when Story 3.1 was created).
- `_bmad-output/implementation-artifacts/3-1-environment-aware-debug-extension.md` — modified (this file: status, tasks/subtasks checked, Dev Agent Record + File List + Change Log entries added).

## Change Log

| Date       | Version | Description                                                                                                                                                                                                                                                                                                                                                  | Author |
|------------|---------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|--------|
| 2026-05-07 | 0.1.0   | Story scaffold created via `/bmad-create-story`. Status: ready-for-dev. Comprehensive context engine analysis covers FR35 (prod no-leak), FR36 (dev/test debug shape), FR37 (staging debug shape), NFR7 (no-leak guarantee), NFR13 (default-deny on unknown env). Constructor-injected `%kernel.environment%`; debug extension uniform across all 5 factory branches; prod-safe title literal on unhandled-exception. | Sergio |
| 2026-05-07 | 1.0.0   | Implementation complete via `/bmad-dev-story`. `ProblemDetailsFactory` gains a `#[Autowire('%kernel.environment%')] string $environment` constructor argument; emits an env-aware `debug` extension (5-key dev/test, 2-key staging, absent prod) appended LAST in `extensions`; the terminal `unhandled-exception` branch's title is swapped to the safe literal in prod (NFR7). 15 new unit tests + 2 new functional tests; 50 baseline call sites mechanically updated via `self::factoryFor()` helper. `make php.lint`, `make php.unit` (254/254), `make php.behat` (46/46), `make php.test`, `make php.stan` all clean. Status: review. | Sergio |
