# Story 1.6: Map `ValidationFailedException` to a structured `violations[]` extension

Status: review

Epic: 1 — Uniform Error Contract (Producer Ergonomics) — **closes Epic 1**
Story Key: `1-6-map-validationfailedexception-to-a-structured-violations-extension`

## Story

As a PWA developer,
I want `Symfony\Component\Validator\Exception\ValidationFailedException` to surface as a 422 Problem Details with a structured `violations` extension whose entries are objects with the keys `field`, `message`, `code` (in that order),
so that I can render per-field errors without string-parsing a generic message and without keying on Symfony-specific concepts.

## Acceptance Criteria

1. **Given** Stories 1.3 and 1.4 are done (`ProblemDetailsFactory` lives at `api/src/Shared/Application/Problem/ProblemDetailsFactory.php`, the `ExceptionResponder` listener is live on `/api/*`, and Story 1.5's `HttpExceptionInterface` / `AccessDeniedException` / `AuthenticationException` branches have already landed), **and given** `symfony/validator` is already a required dependency (`^8.0.9` in `api/composer.json` — no AR6 deviation), **when** any controller / use case throws `Symfony\Component\Validator\Exception\ValidationFailedException` carrying a `Symfony\Component\Validator\ConstraintViolationListInterface`, **then** the response is a conforming RFC 9457 Problem Details body with status `422`, `Content-Type: application/problem+json`, `Cache-Control: no-store`, JSON `type` equal to `validation-failed`, JSON `title` equal to the literal `Validation failed.` (FR45-safe — does NOT use `$e->getMessage()`, see AC #4), JSON `status` equal to `422`, and a top-level `violations` extension member that is a **JSON array** (never an object) whose length matches the violation list count. (FR1, FR2, FR3, FR4, FR5, FR23, FR47, NFR19, NFR20)

2. **Branch placement inside `ProblemDetailsFactory::fromThrowable()`**: the new `ValidationFailedException` branch is inserted **immediately after** the existing `DomainException` branch and **before** the Story 1.5 `AccessDeniedException` / `AuthenticationException` / `HttpExceptionInterface` branches. The final branch order after this story is:
   1. `$e instanceof DomainException` (Story 1.3) — wins.
   2. `$e instanceof ValidationFailedException` (**this story**) — 422 / `validation-failed` / violations[].
   3. `$e instanceof AccessDeniedException` (Story 1.5).
   4. `$e instanceof AuthenticationException` (Story 1.5).
   5. `$e instanceof HttpExceptionInterface` (Story 1.5).
   6. Plain `\Throwable` (Story 1.3) — fallback.

   **Why this order:**
   - `ValidationFailedException extends Symfony\Component\Validator\Exception\RuntimeException extends \RuntimeException` — it does **not** implement `HttpExceptionInterface`, is **not** a Security Core exception, so it would otherwise fall through to the unhandled fallback (500 / `unhandled-exception`). The new branch must catch it explicitly.
   - Placing the branch right after `DomainException` (NOT after the Security Core / HttpException bridges) makes the most-specific Symfony exception class win first. None of the Story 1.5 branches would match `ValidationFailedException` anyway, but pinning the slot keeps the ordering principle clean: **most-specific Symfony class first, then Security Core, then HttpException.**
   - A `DomainException` that **also** happens to implement `Symfony\Component\Validator\Exception\ExceptionInterface` (artificial — would require a domain class to inherit Validator internals) is still routed through the `DomainException` branch — branch 1 wins. Pin via the new precedence test described in AC #8.

3. **Resolution rule for the new branch:**
   - `status` = `422` (hard-coded; do **not** read from any Symfony-supplied attribute or computed value).
   - `type` = literal `'validation-failed'`.
   - `title` = literal `'Validation failed.'` — **not** `$e->getMessage()`. (Rationale below.)
   - `detail` = `null` (consistent with all other Story 1.5 bridge branches and Story 1.3's `\Throwable` fallback).
   - `instance` and `correlationId` = passed through from the listener's input parameters verbatim, identical to every other branch.
   - `extensions` = `['violations' => [...buildViolations($e->getViolations())...]]` — exactly **one** extension key, named `violations`, whose value is a sequential / list-shaped array (PHP `array<int, array{field: string, message: string, code: string}>`) so it serializes as a JSON array.

4. **Why `title` is a literal, not `$e->getMessage()`:** Symfony's `ValidationFailedException::__construct(mixed $value, ConstraintViolationListInterface $violations)` calls `parent::__construct($violations)` — the validator-list's `__toString()` is used as the exception message. That string concatenates **the root class name, every property path, every violation message, AND every constraint code** (see `Symfony\Component\Validator\ConstraintViolationList::__toString()`). Surfacing it in `title` would:
   - Leak internal class names (FR45: `title` must be safe for end-user display, no internal identifiers).
   - Duplicate the structured violations data already exposed via the extension (FR47).
   - Produce unbounded title strings vulnerable to NFR10's 16 KiB cap (Story 3.6's concern, but adding a deterministic fixed title preempts the issue cleanly).

   The fixed literal `'Validation failed.'` is the safe default. Localization is not in scope (no `translator` dependency in the factory — AR1 layering keeps the factory framework-light).

5. **Per-violation object shape — exact, deterministic, single-source-pinned:**
   - Each violation entry is a plain associative `array<string, string>` with **exactly three keys** in this order: `field`, `message`, `code`.
   - `field` = `$violation->getPropertyPath()` verbatim (even if it is the empty string `''` — pin this in a test).
   - `message` = `(string) $violation->getMessage()` — `getMessage(): string|\Stringable`, so the cast is required for type safety. Use the message **verbatim** (no template substitution beyond what Symfony already did; do NOT call `$violation->getMessageTemplate()` — the templated form leaks placeholders like `{{ value }}` to clients).
   - `code` = `$violation->getCode() ?? ''` — the interface declares `getCode(): ?string`; null is normalized to empty string per the AC text in `epics.md` (line 379). Empty string is the contract value, NOT `null`, so the JSON shape is uniform.
   - **No other keys are propagated** from the violation. In particular, do **NOT** propagate `getInvalidValue()`, `getRoot()`, `getCause()`, `getConstraint()`, `getMessageTemplate()`, `getParameters()`, or `getPlural()` — those leak validated values, internal class names, framework internals, or expose attack surface (FR45, NFR7). Future stories may add a vetted subset; this story stays minimal.
   - Building each entry as a literal array `['field' => ..., 'message' => ..., 'code' => ...]` (rather than a `JsonSerializable` VO) makes the wire shape transparent and trivially snapshot-testable. Resist the urge to introduce a `Violation` VO — Story 1.6 is wire-only.

6. **JSON-array vs JSON-object shape (footgun pin):** the violations list MUST serialize as a JSON array (`[{"field":...},{...}]`), not an object (`{"0":{"field":...},"1":{...}}`). PHP's `json_encode()` produces an object for arrays with non-sequential integer keys or string keys. Build violations as a sequential, zero-indexed list — use `array_map(fn(...) => [...], iterator_to_array($violations, false))` or an indexed `foreach` push to `$out[] = [...]`. **Pin this** with `testValidationFailedExceptionViolationsExtensionSerializesAsJsonArrayNotObject` (AC #8) by asserting the JSON-encoded body matches `/"violations":\[/` (square bracket, not curly).

7. **Empty-list contract:** when `$e->getViolations()` is empty (zero violations — pathological but valid in user code), the body still has `type = 'validation-failed'`, `status = 422`, `title = 'Validation failed.'`, AND a `violations` extension whose value is an **empty JSON array** `[]`. Pin via `testValidationFailedExceptionWithEmptyListProducesEmptyViolationsArray` (AC #8). Rationale: omitting the extension on the empty case forces clients to handle two shapes; always-present is cleaner.

8. **PHPUnit 13 unit tests** added to the existing `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` (extend, **do not** create a new test class — symmetry with how Story 1.5 extended Story 1.3's file):
   - **`testValidationFailedExceptionMapsTo422ValidationFailedWithViolations`** — given three violations on property paths `name` (message `'This value should not be blank.'`, code `'c1051bb4-d103-4f74-8988-acbcafc7fdc3'` — the canonical `NotBlank` code), `email` (message `'This value is not a valid email address.'`, code `'bd79c0ab-ddba-46cc-a703-a7a4b08de310'`), and `age` (message `'This value should be greater than or equal to 18.'`, code `'ea4e51d1-3342-48bd-87f1-9e672cd90cad'`), assert: `status === 422`, `type === 'validation-failed'`, `title === 'Validation failed.'`, `detail === null`, `extensions === ['violations' => [<three entries>]]`, each entry is a plain array with exactly the keys `['field', 'message', 'code']` in that order, and each entry's values match the inputs verbatim.
   - **`testValidationFailedExceptionViolationKeyOrderIsFieldMessageCode`** — single violation; build `ProblemDetails`, JSON-encode via `\json_encode($pd->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)`, assert the JSON substring matches `'"field":"...","message":"...","code":"..."'` (regex with the exact key order). Pins FR5-style determinism for the violation entry.
   - **`testValidationFailedExceptionViolationCodeFallsBackToEmptyStringOnNull`** — single violation constructed with `code: null`; assert the resulting violation entry has `'code' => ''` (empty string, NOT `null`). Pins AC #5's null normalization.
   - **`testValidationFailedExceptionViolationPropertyPathPassesThroughEvenWhenEmpty`** — single violation constructed with `propertyPath: ''`; assert the resulting violation entry has `'field' => ''`. Pins AC #5's "verbatim, even if empty".
   - **`testValidationFailedExceptionWithEmptyListProducesEmptyViolationsArray`** — empty `ConstraintViolationList`; assert `extensions === ['violations' => []]`, and JSON encoding produces `'"violations":[]'` (square brackets, empty array). Pins AC #7.
   - **`testValidationFailedExceptionViolationsExtensionSerializesAsJsonArrayNotObject`** — three violations; encode `$pd->toArray()` to JSON; assert `\str_contains($json, '"violations":[')` AND `\preg_match('/"violations":\[\{/', $json) === 1`. Pins AC #6's footgun.
   - **`testValidationFailedExceptionTitleIsTheLiteralValidationFailedNotTheMessage`** — three violations; assert `$pd->title === 'Validation failed.'`. Compare with `$exception->getMessage()` and assert they differ (the validator's `__toString()` will be longer than `'Validation failed.'`). Pins AC #4.
   - **`testValidationFailedExceptionDoesNotPropagateInvalidValueOrRoot`** — violation built with `invalidValue: 'sensitive-value-from-form'` and `root: ['password' => 'leaked']`; assert the resulting body's JSON-encoded form contains **neither** `'sensitive-value-from-form'` **nor** `'leaked'` anywhere. Pins AC #5's "no other keys are propagated".
   - **`testValidationFailedExceptionDoesNotPropagateMessageTemplate`** — violation built with `message: 'This value should be greater than or equal to 18.'` AND `messageTemplate: 'This value should be greater than or equal to {{ limit }}.'`; assert the entry's `message` is the resolved form (`'...18.'`), NOT the templated form (`'...{{ limit }}.'`). Pins AC #5's "verbatim message, never template".
   - **`testDomainExceptionImplementingInvariantViolationDoesNotProduceViolationsExtension`** — branch-order regression pin: `new class('', 'x') extends DomainException implements InvariantViolation {}`; assert `status === 422`, `type === 'invariant-violation'` (NOT `'validation-failed'`), AND `extensions === []` (no violations array). Confirms `DomainException` branch wins over `ValidationFailedException` branch even when the marker shares a status code.
   - **`testRfc9457SchemaValidationStillPassesWithViolationsExtension`** — three violations; encode and validate against `api/tests/Fixtures/Problem/rfc-9457.schema.json` (already bundled by Story 1.2). The schema permits arbitrary top-level extension members (`additionalProperties: true`), so the body must validate. Pins NFR19 for this branch.
   - **`testValidationFailedExceptionDetailIsNullAndCorrelationIdInstancePassThrough`** — assert `detail === null`, `correlationId === self::CID`, `instance === self::INSTANCE`. Symmetric with Story 1.5's `testHttpExceptionDetailIsNullAndExtensionsEmpty`.
   - **No banned-imports test changes required.** The architecture-import test (`testSourceFileContainsNoBannedImports`, currently at lines 356–382 of `ProblemDetailsFactoryTest.php`) bans the prefixes `Doctrine\\`, `Psr\Http\\`, `Symfony\Component\HttpFoundation\\`, `Symfony\Component\Messenger\\`, `Symfony\Component\Routing\\`, `Symfony\Bundle\\`, `Symfony\Bridge\\`, `App\\`. **`Symfony\Component\Validator\\` is NOT in the ban list** (Story 1.5 narrowed the original wholesale `Symfony\\` ban to those specific prefixes). The two new validator imports are therefore allowed without modifying the test. Pin this as a one-line note in the story's Completion Notes (no test edit needed).

9. **Behat feature** — create `api/features/shared/error_contract/validation_violations.feature` (new file, sibling to `symfony_bridges.feature`; do **not** extend `symfony_bridges.feature` — keep it focused on the framework bridges, since validation violations are a structurally distinct extension contract that warrants its own scenarios). Same `Background:` shape (`Accept: application/json`), absolute-URL workaround documented in the comment block (per Story 1.5 learning — `HttpRequestContext.baseUrl='/api/v1'` requires absolute `http://localhost/...` URLs to bypass the prefix). Six scenarios:
   - **Scenario: `ValidationFailedException with three field violations is mapped to a 422 validation-failed Problem Details body with structured violations`** — `When` GET `/api/test/_throw-validation`. `Then` status `422`, header `Content-Type` equals `application/problem+json`, header `Cache-Control` contains `no-store`, JSON node `type` equals `validation-failed`, JSON node `status` equals number `422`, JSON node `title` equals `Validation failed.`, JSON node `violations` should have `3` elements, JSON node `violations[0].field` equals `name`, `violations[0].message` equals `This value should not be blank.`, `violations[0].code` equals `c1051bb4-d103-4f74-8988-acbcafc7fdc3`, JSON node `violations[1].field` equals `email`, JSON node `violations[2].field` equals `age`. Plus the `instance` / `correlation-id` UUIDv7 regex matches (per Story 1.5 convention).
   - **Scenario: `ValidationFailedException with no violations still produces a conforming 422 body with an empty violations array`** — `When` GET `/api/test/_throw-validation-empty`. `Then` status 422, JSON node `type` equals `validation-failed`, JSON node `violations` should have `0` elements (asserts the JSON-array form, since `the JSON node :node should have :count element(s)` reads the array length).
   - **Scenario: `ValidationFailedException violation entries omit invalid value and root from the wire body`** — `When` GET `/api/test/_throw-validation-with-sensitive-payload` (test fixture builds a violation whose `invalidValue` is `'super-secret-payload'` and `root` is `['password' => 'leaked-secret']`). `Then` status 422, the response body should not contain `super-secret-payload`, the response body should not contain `leaked-secret`. Pins AC #5's no-leak guarantee end-to-end.
   - **Scenario: `ValidationFailedException violation entries serialize message verbatim, never the template form`** — `When` GET `/api/test/_throw-validation-template`. `Then` JSON node `violations[0].message` equals `This value should be greater than or equal to 18.` AND the response body should not contain `{{ limit }}`.
   - **Scenario: `ValidationFailedException violation entry shape is field, message, code in that order`** — `When` GET `/api/test/_throw-validation`. `Then` the response body should match `/"violations":\[\{"field":"name","message":"This value should not be blank\.","code":"c1051bb4-d103-4f74-8988-acbcafc7fdc3"\}/`. Pins the on-wire key order using the existing regex step. **NOTE:** the existing `JsonContext` does not have a "raw response body matches regex" step. If absent, use the existing `the response body should contain :text` step (defined in `HttpRequestContext` per the patterns dump) with the literal substring `"field":"name"`, then a separate `should contain :text` for `"message":"This value should not be blank."`, etc. Confirm the available steps before writing scenarios; if `the response body should match :pattern` is absent, fall back to multi-step `should contain` assertions.
   - **Scenario: `ValidationFailedException is distinct from DomainException implementing InvariantViolation`** — `When` GET `/api/test/_throw-invariant-violation` (a NEW fixture: throws a `DomainException` implementing `InvariantViolation`, status 422). `Then` JSON node `type` equals `invariant-violation` (NOT `validation-failed`), AND JSON node `status` equals number `422`, AND the response body should not contain `violations`. Pins the branch-order regression at the integration layer.

10. **Test fixture controllers** added under `api/tests/Functional/Shared/Infrastructure/Http/EventListener/Fixtures/`. Five new fixtures (named for clarity, all `final` invokable classes following Story 1.4 / 1.5 pattern):
    - **`ThrowValidationFailedController`** — builds a `ConstraintViolationList` with three `ConstraintViolation` entries on property paths `name`, `email`, `age`, each with the canonical message + code listed in AC #8 (`testValidationFailedExceptionMapsTo422ValidationFailedWithViolations`). Throws `new ValidationFailedException(value: $dto, violations: $list)` where `$dto = ['name' => '', 'email' => 'invalid', 'age' => 17]`.
    - **`ThrowValidationFailedEmptyController`** — empty `ConstraintViolationList`. Throws `ValidationFailedException` with the empty list.
    - **`ThrowValidationFailedSensitivePayloadController`** — builds a violation whose `invalidValue: 'super-secret-payload'` and `root: ['password' => 'leaked-secret']`. Single violation on path `name`. Used by the no-leak scenario.
    - **`ThrowValidationFailedTemplateController`** — single violation built with `message: 'This value should be greater than or equal to 18.'` AND `messageTemplate: 'This value should be greater than or equal to {{ limit }}.'`. Pins template-suppression.
    - **`ThrowInvariantViolationDomainExceptionController`** — throws an anonymous `DomainException` implementing `Erpify\Shared\Domain\Exception\InvariantViolation` with title `'Account already settled'`. Used by the branch-order Behat scenario.

11. **Routing updates** in `api/config/routes/test.yaml` — append five new routes inside the existing `when@test:` block (next to the Story 1.4 / 1.5 entries):
    ```yaml
    test_throw_validation:
        path: /api/test/_throw-validation
        controller: Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener\Fixtures\ThrowValidationFailedController
        methods: [GET]

    test_throw_validation_empty:
        path: /api/test/_throw-validation-empty
        controller: Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener\Fixtures\ThrowValidationFailedEmptyController
        methods: [GET]

    test_throw_validation_with_sensitive_payload:
        path: /api/test/_throw-validation-with-sensitive-payload
        controller: Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener\Fixtures\ThrowValidationFailedSensitivePayloadController
        methods: [GET]

    test_throw_validation_template:
        path: /api/test/_throw-validation-template
        controller: Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener\Fixtures\ThrowValidationFailedTemplateController
        methods: [GET]

    test_throw_invariant_violation:
        path: /api/test/_throw-invariant-violation
        controller: Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener\Fixtures\ThrowInvariantViolationDomainExceptionController
        methods: [GET]
    ```
    `services_test.yaml` already autowires the `…\EventListener\Fixtures\` namespace (Story 1.4) — **no edits needed there**.

12. **Imports added to `ProblemDetailsFactory.php`:** exactly **two** new imports, both from `Symfony\Component\Validator\\` (a namespace not in the banned-import list — see AC #8 last bullet):
    - `use Symfony\Component\Validator\ConstraintViolationInterface;` — typed as the loop-variable type when iterating violations.
    - `use Symfony\Component\Validator\Exception\ValidationFailedException;` — the branch-discriminator class.

    No other imports. No `ConstraintViolationListInterface` import (we iterate the list returned by `getViolations()` directly; the loop variable type is `ConstraintViolationInterface`, the list itself is bound by inference). No App, no Doctrine, no HttpFoundation, no Messenger.

13. **Listener (`ExceptionResponder`) is NOT modified.** The whole story lands inside the factory and the test fixtures. The listener is contractually total over `\Throwable` and delegates everything to the factory (Story 1.4 design). No `services.yaml` or routing edits beyond the `test.yaml` additions; no `composer.json` / `composer.lock` edits (validator is already required); no `services_test.yaml` edits (Fixtures namespace already autowired).

14. **Worker-mode safety / FR40-FR41 unchanged.** The new branch is pure-functional inside `fromThrowable()`: no `private` mutable state added, no DB access, no service injection. The factory remains constructor-less, `final`, and stateless. Pin via the existing `testFactoryHasNoConstructorAndIsFinal` (no edit needed — it should still pass after the new branch lands).

15. **`make php.stan` reports zero errors after each PHP edit; `make php.lint`, `make php.unit`, `make php.behat`, and `make php.test` pass at story completion.** (AR7) Linter normalizations expected (per Story 1.2/1.3/1.4/1.5 learning):
    - Rector may rename local `$exception` → `$validationFailedException`, `$result` → `$problemDetails`, etc. **Don't fight the linter** — accept canonical form.
    - PHP-CS-Fixer may reorder imports alphabetically (`ConstraintViolationInterface` comes before `Exception\ValidationFailedException` in the existing `Symfony\` group? Actually the existing file imports are ordered case-sensitively; expect them alphabetized).
    - Rector privatizes `protected` methods on `final` classes ([memory: feedback_api_lint_privatize_final.md]) — if you add a `protected function buildViolations(...)`, expect it to become `private`. Plan for `private` from the start.

16. **PWA consumer guarantee (FR47) end-to-end pin:** the integration test that exercises GET `/api/test/_throw-validation` decodes the JSON body, asserts that a JS-shaped consumer can read `body.violations[0].field` directly without parsing strings — i.e., `\json_decode($content, true)['violations'][0]['field']` returns the string `'name'`. This is implicit in the Behat scenario but worth explicit affirmation in the story's Dev Notes.

17. **No changes to `Erpify\Shared\Domain\Exception\` markers, `DomainException`, `ProblemDetails`, `ExceptionResponder`, or `ProblemDetailsResponder`.** This story is *purely* additive inside `ProblemDetailsFactory.php`, the existing factory test, the existing Behat folder, the existing Fixtures folder, and the existing test routing file. Story 1.4's listener and Story 1.2's value object are untouched.

18. **`Erpify\Shared\Domain\Exception\InvariantViolation` is the marker that DOES NOT match this story's branch.** A controller that throws a `DomainException implements InvariantViolation` produces the body `type: 'invariant-violation'` (Story 1.3's marker default), NOT `type: 'validation-failed'`. The two are distinct, deliberate contracts:
    - **`InvariantViolation` marker** — domain-layer assertion failure (e.g., "cannot settle an already-settled account"). One narrative, no per-field violations.
    - **`ValidationFailedException`** — Symfony Validator's input-validation failure (e.g., "the DTO failed `@Assert\NotBlank` on 3 fields"). Per-field structured violations.

    Both yield 422, but the body shape differs (extensions field). Pin via `testDomainExceptionImplementingInvariantViolationDoesNotProduceViolationsExtension` (AC #8) and the `_throw-invariant-violation` Behat scenario (AC #9).

## Tasks / Subtasks

- [x] **Task 1 — Confirm `symfony/validator` availability and inspect APIs** (AC: 1, 12)
  - [x] Run `make composer c='show symfony/validator'` and confirm version is `^8.0.9` (matches the project's Symfony 8 baseline). No new dependency required.
  - [x] Verify `api/vendor/symfony/validator/Exception/ValidationFailedException.php`, `api/vendor/symfony/validator/ConstraintViolationInterface.php`, `api/vendor/symfony/validator/ConstraintViolationListInterface.php`, and `api/vendor/symfony/validator/ConstraintViolation.php` are present (they are — pinned during planning).
  - [x] Reread `ConstraintViolationInterface::getMessage(): string|\Stringable` and note the `(string)` cast required when promoting to `extensions['violations'][n]['message']` (AC #5).
- [x] **Task 2 — Extend `ProblemDetailsFactory` with the `ValidationFailedException` branch** (AC: 1, 2, 3, 4, 5, 6, 7, 12, 14)
  - [x] Add the two imports listed in AC #12 to `api/src/Shared/Application/Problem/ProblemDetailsFactory.php`. Keep the existing import block alphabetized.
  - [x] Inside `fromThrowable()`, insert the new `ValidationFailedException` branch **immediately after** the `if ($e instanceof DomainException)` block and **before** the Story 1.5 `AccessDeniedException` branch. The branch returns a fresh `ProblemDetails` with `type: 'validation-failed'`, `status: 422`, `title: 'Validation failed.'`, `detail: null`, `instance: $instance`, `correlationId: $correlationId`, `extensions: ['violations' => $this->buildViolations($e->getViolations())]`.
  - [x] Add a private helper `buildViolations(ConstraintViolationListInterface $violations): array` (return type `list<array{field: string, message: string, code: string}>`). Iterate the list once; for each `ConstraintViolationInterface $violation`, push `['field' => $violation->getPropertyPath(), 'message' => (string) $violation->getMessage(), 'code' => $violation->getCode() ?? '']` to `$out`. Return `$out` (sequential array — JSON-array shape).
  - [x] Confirm the helper docblock declares `@return list<array{field: string, message: string, code: string}>` so PHPStan can verify the JSON-array contract statically.
  - [x] After the edit, run `make php.stan` and fix any findings. Expected: clean, since the branch is pure and the return shape matches `ProblemDetails::extensions: array<string, mixed>`.
- [x] **Task 3 — Update `ProblemDetailsFactoryTest`** (AC: 8, 18)
  - [x] Open `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php`.
  - [x] Add the necessary `use` lines: `Symfony\Component\Validator\ConstraintViolation`, `Symfony\Component\Validator\ConstraintViolationList`, `Symfony\Component\Validator\Exception\ValidationFailedException`. Also `Erpify\Shared\Domain\Exception\InvariantViolation` (already present? — verify in the existing imports; if not, add).
  - [x] Add the **12 new test methods** listed in AC #8 (one per bullet). Use the existing `self::CID` and `self::INSTANCE` constants. For violations, build `ConstraintViolation` instances directly via the public constructor (see `api/vendor/symfony/validator/ConstraintViolation.php` lines 41-53 for the exact signature). For empty list, instantiate `new ConstraintViolationList([])`.
  - [x] **Do NOT edit `testSourceFileContainsNoBannedImports`** — `Symfony\Component\Validator\\` is not in the ban list (per AC #8 last bullet).
  - [x] Run `make php.unit c='--filter=ProblemDetailsFactoryTest'` and confirm all old + new tests pass.
  - [x] Run `make php.stan` after every edit; fix any findings (likely `assertIsArray` / `assertArrayHasKey` narrowing for the violation entries — see Story 1.5's pattern).
- [x] **Task 4 — Add test-only fixture controllers** (AC: 10)
  - [x] Create the five new fixture controllers under `api/tests/Functional/Shared/Infrastructure/Http/EventListener/Fixtures/` (paths in AC #10). Each is a `final` invokable class declaring `__invoke(): Response` and throwing the relevant exception. No DI dependencies; build the violation list inline.
  - [x] For `ThrowValidationFailedController`: use the canonical Symfony NotBlank / Email / GreaterThanOrEqual codes listed in AC #8 (lookup: `Symfony\Component\Validator\Constraints\NotBlank::IS_BLANK_ERROR`, `Email::INVALID_FORMAT_ERROR`, `GreaterThanOrEqual::TOO_LOW_ERROR` — or pass the literal UUID strings shown in the AC since the values are stable Symfony constants).
  - [x] For `ThrowInvariantViolationDomainExceptionController`: throw `new class('', 'Account already settled') extends DomainException implements InvariantViolation {};` — same anonymous-class fixture pattern as `ThrowNotFoundController`.
  - [x] Run `make php.stan` after each fixture; fix any findings.
- [x] **Task 5 — Add the test routes** (AC: 11)
  - [x] Append the five new routes from AC #11 to `api/config/routes/test.yaml` inside the existing `when@test:` block. Keep them grouped as a `# Story 1.6 — ValidationFailedException violations[] coverage.` section, mirroring Story 1.5's grouping comment.
  - [x] Smoke-test by running `make sf c='debug:router | grep _throw-validation'` (with the dev container up); should list five new routes plus the existing `_throw-*` ones.
- [x] **Task 6 — Add the Behat feature** (AC: 9)
  - [x] Create `api/features/shared/error_contract/validation_violations.feature` with the six scenarios from AC #9.
  - [x] Mirror the absolute-URL workaround used in `symfony_bridges.feature` (`http://localhost/api/test/...`) — the FoB SymfonyExtension shared-context constraint is unchanged.
  - [x] Mirror the UUIDv7 regex assertions on `instance` and `correlation-id` for at least the primary three-violations and empty-list scenarios.
  - [x] **Confirm available step definitions** before writing — see the grep output in Dev Notes for the full step inventory. Specifically:
    - `JsonContext::the JSON node :node should have :count element(s)` exists (line 211) — use for the violations array length.
    - `JsonContext::the JSON node :node should match :pattern` exists (line 134) — use for UUIDv7 regex.
    - `JsonContext::the JSON node :node should be equal to :text` exists (line 90) — use for the violation entry fields.
    - `JsonContext::the JSON node :node should not contain :text` exists (line 248) — use for the no-leak scenario.
    - **Pin:** if `the response body should match :pattern` (raw-body regex on `HttpRequestContext`) is absent, fall back to multiple `should contain :text` substring assertions for the key-order pin.
  - [x] Run `make php.behat c='features/shared/error_contract/validation_violations.feature'` and confirm all six scenarios pass.
- [x] **Task 7 — Run quality gates and finalize** (AC: 13, 15, 17)
  - [x] Run `make php.unit` (full suite) — confirm no regressions across Stories 1.1–1.5 tests.
  - [x] Run `make php.behat` (full suite) — confirm Story 1.5's `symfony_bridges.feature` (6 scenarios) plus this story's `validation_violations.feature` (6 scenarios) plus the existing 24 backoffice / frontoffice scenarios all pass.
  - [x] Run `make php.lint` — fix any reported issues; expect Rector / CS-Fixer normalizations on the test file (variable renames, alphabetized imports).
  - [x] Run `make php.test` (= `php.unit + php.behat`) for full belt-and-suspenders.
  - [x] Verify `git diff api/src/Shared/Application/Problem/ProblemDetailsFactory.php` shows ONLY: (a) two new imports, (b) one new branch, (c) one new private helper. Nothing else.
  - [x] Verify NO changes in: `api/composer.json`, `api/composer.lock`, `api/config/services_test.yaml`, `api/src/Shared/Domain/Exception/*`, `api/src/Shared/Application/Problem/ProblemDetails.php`, `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php`, `api/src/Shared/Infrastructure/Http/ProblemDetailsResponder.php`.

## Dev Notes

### Architecture & constraints (load-bearing)

- **NFR25 single source of truth (preserved):** Story 1.3's `MARKER_STATUS_MAP`, `MARKER_DEFAULT_TYPE_MAP`, and Story 1.5's `HTTP_STATUS_TYPE_MAP` are unchanged. This story does NOT add a fourth constant — `validation-failed` and `422` are inline literals in the new branch (the type/status are bound to the `ValidationFailedException` discriminator class, not to a status-keyed map). If a future story needs to extend the validation contract (e.g., distinguish `ConflictingValidationFailed` for 409 multi-row uniqueness checks), it adds an explicit AC then.
- **AR1 layering preserved:** the factory lives in `Application/`. The two new imports are from `Symfony\Component\Validator\\` — a namespace not in the architecture-import ban list. AR1's "no framework imports in `Domain/`" rule is unrelated (factory is in `Application/`, allowed to consume framework value types).
- **AR2 strict types:** every new file declares `declare(strict_types=1);`. Full parameter / return / property type coverage on the new helper.
- **AR3 attribute registration:** no new listeners; reuse Story 1.4's `ExceptionResponder`. No `services.yaml` edits.
- **AR4 worker-mode safety:** the new branch is pure-functional. No mutable state, no service-locator usage, no static caches. Pin via the unchanged `testFactoryHasNoConstructorAndIsFinal`.
- **AR5 testing:** PHPUnit 13 unit tests for factory logic; Behat for end-to-end HTTP coverage. The existing `WebTestCase` regression test from Story 1.4 (`ExceptionResponderFunctionalTest`) is **unchanged** — Story 1.6 does not migrate it.
- **AR6 (no new vendor deps):** **NOT deviated.** `symfony/validator: ^8.0.9` is already required in `api/composer.json`. Confirm via `composer show symfony/validator`.
- **AR7 lint gate:** `make php.lint` must pass at story completion. Expect linter normalizations.
- **FR45 `title` safe for end users:** the literal `'Validation failed.'` is the safe default. **Do NOT** propagate `$e->getMessage()` into `title` — it would leak class names + per-field details (see AC #4).
- **NFR7 prod no-leak:** the new branch propagates **only** `propertyPath`, `message`, and `code` from each violation — never `invalidValue`, `root`, `cause`, `constraint`, `messageTemplate`, `parameters`, `plural`. Pin via `testValidationFailedExceptionDoesNotPropagateInvalidValueOrRoot` and `testValidationFailedExceptionDoesNotPropagateMessageTemplate`. Story 3.x will further harden bodies; this story enforces the floor.
- **NFR10 16 KiB cap:** **NOT this story's concern** — Story 3.6 owns the cap. Story 1.6's `violations[]` is unbounded; if a controller throws a 1000-violation exception, the body could exceed 16 KiB. Story 3.6's truncation logic explicitly mentions truncating `violations[]` first (see `epics.md` line 569). Don't preempt that work here — keep Story 1.6 minimal.

### Branch order in `fromThrowable()` after this story

1. **`$e instanceof DomainException`** (Story 1.3) — wins over everything, including a hypothetical `DomainException` that artificially extends `ValidationFailedException` (impossible in practice — `DomainException` inherits `\DomainException` and `ValidationFailedException` inherits `Symfony\…\Validator\Exception\RuntimeException`, so a class can't extend both simultaneously). Pinned by `testDomainExceptionImplementingInvariantViolationDoesNotProduceViolationsExtension`.
2. **`$e instanceof ValidationFailedException`** (this story) — 422 / `validation-failed` / violations[].
3. **`$e instanceof AccessDeniedException`** (Story 1.5).
4. **`$e instanceof AuthenticationException`** (Story 1.5).
5. **`$e instanceof HttpExceptionInterface`** (Story 1.5).
6. **Plain `\Throwable`** (Story 1.3) — fallback.

### Symfony Validator API reference (sanity)

- **`ValidationFailedException`** — `__construct(mixed $value, ConstraintViolationListInterface $violations)`. Two getters: `getValue(): mixed`, `getViolations(): ConstraintViolationListInterface`. Extends `Symfony\Component\Validator\Exception\RuntimeException` extends `\RuntimeException`. Does NOT implement `HttpExceptionInterface`. (See `api/vendor/symfony/validator/Exception/ValidationFailedException.php`.)
- **`ConstraintViolationListInterface`** — extends `\Traversable`, `\Countable`, `\ArrayAccess`. Iterate with `foreach` to get each `ConstraintViolationInterface`. Use `count()` for length.
- **`ConstraintViolationInterface`** — relevant getters:
  - `getPropertyPath(): string` — always returns `string` (may be empty `''`). Property paths use dot notation for nested objects (`'address.street'`) and brackets for array index (`'addresses[1].street'`). Pass through verbatim — the PWA can split on `.` / `[` if needed; we don't normalize.
  - `getMessage(): string|\Stringable` — `__toString()`-able. Cast to `(string)` when promoting to the wire.
  - `getCode(): ?string` — null if no code; normalize to `''`.
  - **Other getters that we deliberately ignore:** `getMessageTemplate(): string`, `getParameters(): array`, `getPlural(): ?int`, `getRoot(): mixed`, `getInvalidValue(): mixed`, `getConstraint(): ?Constraint`, `getCause(): mixed`. None are propagated to the wire.
- **`ConstraintViolation` (concrete class for tests)** — `__construct(string|\Stringable $message, ?string $messageTemplate, array $parameters, mixed $root, ?string $propertyPath, mixed $invalidValue, ?int $plural = null, ?string $code = null, ?Constraint $constraint = null, mixed $cause = null)`. Order matters: 10 positional params, but you can use named-arg form. (See `api/vendor/symfony/validator/ConstraintViolation.php` lines 41-53.)
- **`ConstraintViolationList` (concrete class)** — `__construct(iterable $violations = [])`. Build empty: `new ConstraintViolationList([])`. Build with items: `new ConstraintViolationList([$v1, $v2, $v3])`.

### Anti-patterns to avoid

- **Do not** import `ConstraintViolationListInterface` — the loop doesn't need it (PHP infers `Traversable<int, ConstraintViolationInterface>`). Keep the imports surgical (two only).
- **Do not** propagate `$e->getMessage()` into `title`. The validator's stringified form leaks class names + every violation's message + every code; that's NFR7-violating, FR45-violating, and NFR10-pressuring. Use the literal `'Validation failed.'`.
- **Do not** propagate `$violation->getInvalidValue()`, `$violation->getRoot()`, `$violation->getMessageTemplate()`, `$violation->getParameters()`, `$violation->getCause()`, `$violation->getConstraint()`, or `$violation->getPlural()` to the wire. These leak validated values, framework internals, or aren't useful to the PWA. Pin via the no-leak unit test and the corresponding Behat scenario.
- **Do not** introduce a `Violation` value object class. Inline associative arrays are the correct shape — they snapshot-test trivially and keep the wire shape transparent. A VO would add an indirection without value (the array form already has compile-time shape verified via PHPStan's `array{field: string, message: string, code: string}`).
- **Do not** translate the violation message in the factory. The validator runs translation upstream (in the `Validator` service, when the `translator` is wired) — `$violation->getMessage()` is the localized form. The factory is layer-pure and has no `translator` dependency. AR1 prohibits introducing one.
- **Do not** add a fourth constant (e.g., `VALIDATION_FAILED_TYPE = 'validation-failed'`) to `ProblemDetailsFactory`. The string literal `'validation-failed'` is fine inline — it's used in exactly one place (the new branch) and one test (`testValidationFailedExceptionMapsTo422...`). NFR25 covers maps with multiple keys, not single-shot literals.
- **Do not** modify Story 1.5's `HTTP_STATUS_TYPE_MAP[422] => 'invariant-violation'` to `'validation-failed'`. The 422 entry in `HTTP_STATUS_TYPE_MAP` is for `HttpExceptionInterface`-implementing exceptions with status 422 (e.g., `UnprocessableEntityHttpException`); those go to `'invariant-violation'`. `ValidationFailedException` is a separate branch with its own type. The maps stay aligned with markers (Story 1.5's invariant test still passes).
- **Do not** make the violations list serialize as an object. Iteration order matters: build with `$out[] = [...]` (sequential append) or `array_values(array_map(...))`, **never** with string-keyed assignment.
- **Do not** modify `ExceptionResponder.php` or `ProblemDetailsResponder.php`. The listener doesn't care about exception types — it delegates to the factory. The responder doesn't care about extension contents — it serializes whatever `toArray()` returns.
- **Do not** add a `services_test.yaml` block for the new fixtures. Story 1.4's resource block already autowires `Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener\Fixtures\\` as `controller.service_arguments` — every new fixture in that namespace is automatically wired.
- **Do not** edit `composer.json` or `composer.lock`. `symfony/validator: ^8.0.9` is already there. Story 1.5 ate the AR6 deviation budget for symfony/security-core; this story stays clean.
- **Do not** edit the architecture-import banned-prefix list. `Symfony\Component\Validator\\` is not banned (and the factory's existing import block already allows `Symfony\Component\HttpKernel\Exception\HttpExceptionInterface`, `Symfony\Component\Security\Core\Exception\AccessDeniedException`, `Symfony\Component\Security\Core\Exception\AuthenticationException`). The validator imports slot in without test changes.

### Sketch: the new branch in `fromThrowable()`

(Reference shape only — write fresh per TDD. Position: between the existing `if ($e instanceof DomainException)` block and the existing `if ($e instanceof AccessDeniedException)` block.)

```php
if ($e instanceof ValidationFailedException) {
    return new ProblemDetails(
        type: 'validation-failed',
        title: 'Validation failed.',
        status: 422,
        detail: null,
        instance: $instance,
        correlationId: $correlationId,
        extensions: ['violations' => $this->buildViolations($e->getViolations())],
    );
}
```

### Sketch: the `buildViolations` helper

```php
/**
 * @param iterable<ConstraintViolationInterface> $violations
 *
 * @return list<array{field: string, message: string, code: string}>
 */
private function buildViolations(iterable $violations): array
{
    $out = [];

    foreach ($violations as $violation) {
        $out[] = [
            'field' => $violation->getPropertyPath(),
            'message' => (string) $violation->getMessage(),
            'code' => $violation->getCode() ?? '',
        ];
    }

    return $out;
}
```

(Type the parameter as `iterable<ConstraintViolationInterface>` — broader than `ConstraintViolationListInterface` and keeps the test fixtures from needing the full list-interface implementation. PHPStan / Psalm can verify the value-shape via the `@return` docblock.)

### Sketch: a representative unit test

```php
public function testValidationFailedExceptionMapsTo422ValidationFailedWithViolations(): void
{
    $list = new ConstraintViolationList([
        new ConstraintViolation(
            message: 'This value should not be blank.',
            messageTemplate: null,
            parameters: [],
            root: null,
            propertyPath: 'name',
            invalidValue: '',
            plural: null,
            code: 'c1051bb4-d103-4f74-8988-acbcafc7fdc3',
        ),
        new ConstraintViolation(
            message: 'This value is not a valid email address.',
            messageTemplate: null,
            parameters: [],
            root: null,
            propertyPath: 'email',
            invalidValue: 'invalid',
            plural: null,
            code: 'bd79c0ab-ddba-46cc-a703-a7a4b08de310',
        ),
        new ConstraintViolation(
            message: 'This value should be greater than or equal to 18.',
            messageTemplate: null,
            parameters: [],
            root: null,
            propertyPath: 'age',
            invalidValue: 17,
            plural: null,
            code: 'ea4e51d1-3342-48bd-87f1-9e672cd90cad',
        ),
    ]);

    $exception = new ValidationFailedException(value: ['name' => '', 'email' => 'invalid', 'age' => 17], violations: $list);

    $problemDetails = (new ProblemDetailsFactory())->fromThrowable($exception, self::CID, self::INSTANCE);

    $this->assertSame(422, $problemDetails->status);
    $this->assertSame('validation-failed', $problemDetails->type);
    $this->assertSame('Validation failed.', $problemDetails->title);
    $this->assertNull($problemDetails->detail);
    $this->assertArrayHasKey('violations', $problemDetails->extensions);

    $violations = $problemDetails->extensions['violations'];
    $this->assertIsArray($violations);
    $this->assertCount(3, $violations);

    $this->assertSame(['field', 'message', 'code'], \array_keys($violations[0]));
    $this->assertSame('name', $violations[0]['field']);
    $this->assertSame('This value should not be blank.', $violations[0]['message']);
    $this->assertSame('c1051bb4-d103-4f74-8988-acbcafc7fdc3', $violations[0]['code']);
}
```

### Sketch: the JSON-array (not object) pin

```php
public function testValidationFailedExceptionViolationsExtensionSerializesAsJsonArrayNotObject(): void
{
    $list = new ConstraintViolationList([
        new ConstraintViolation('m', null, [], null, 'p', null),
    ]);

    $exception = new ValidationFailedException(value: null, violations: $list);
    $problemDetails = (new ProblemDetailsFactory())->fromThrowable($exception, self::CID, self::INSTANCE);

    $json = \json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $this->assertIsString($json);
    $this->assertStringContainsString('"violations":[{', $json, 'violations must serialize as a JSON array, not an object.');
    $this->assertDoesNotMatchRegularExpression('/"violations":\{/', $json);
}
```

### Sketch: the `ThrowValidationFailedController` test fixture

```php
final class ThrowValidationFailedController
{
    public function __invoke(): Response
    {
        $list = new ConstraintViolationList([
            new ConstraintViolation(
                message: 'This value should not be blank.',
                messageTemplate: null,
                parameters: [],
                root: null,
                propertyPath: 'name',
                invalidValue: '',
                plural: null,
                code: 'c1051bb4-d103-4f74-8988-acbcafc7fdc3',
            ),
            new ConstraintViolation(
                message: 'This value is not a valid email address.',
                messageTemplate: null,
                parameters: [],
                root: null,
                propertyPath: 'email',
                invalidValue: 'invalid',
                plural: null,
                code: 'bd79c0ab-ddba-46cc-a703-a7a4b08de310',
            ),
            new ConstraintViolation(
                message: 'This value should be greater than or equal to 18.',
                messageTemplate: null,
                parameters: [],
                root: null,
                propertyPath: 'age',
                invalidValue: 17,
                plural: null,
                code: 'ea4e51d1-3342-48bd-87f1-9e672cd90cad',
            ),
        ]);

        throw new ValidationFailedException(
            value: ['name' => '', 'email' => 'invalid', 'age' => 17],
            violations: $list,
        );
    }
}
```

### Sketch: the `validation_violations.feature` Background + first scenario

```gherkin
Feature: ValidationFailedException surfaces as a 422 Problem Details with a structured violations[] extension
    As a PWA developer
    In order to render per-field errors without parsing strings
    I need ValidationFailedException to produce a Problem Details body whose `violations` extension
    is a JSON array of objects with the keys `field`, `message`, `code`

  # Routes are wired at /api/test/_throw-validation* (Story 1.6). The default Behat suite's
  # HttpRequestContext is constructor-bound to baseUrl=/api/v1 (FoB SymfonyExtension shared-context
  # constraint, see Story 1.5). Scenarios use absolute http://localhost/... URLs to bypass.

  Background:
    Given I add "Accept" header equal to "application/json"

  Scenario: ValidationFailedException with three field violations is mapped to a 422 validation-failed Problem Details body with structured violations
    When I send a "GET" request to "http://localhost/api/test/_throw-validation"
    Then the response status code should be 422
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the response should be in JSON
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "status" should be equal to the number 422
    And the JSON node "title" should be equal to "Validation failed."
    And the JSON node "violations" should have 3 elements
    And the JSON node "violations[0].field" should be equal to "name"
    And the JSON node "violations[0].message" should be equal to "This value should not be blank."
    And the JSON node "violations[0].code" should be equal to "c1051bb4-d103-4f74-8988-acbcafc7fdc3"
    And the JSON node "violations[1].field" should be equal to "email"
    And the JSON node "violations[2].field" should be equal to "age"
    And the JSON node "instance" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/"
    And the JSON node "correlation-id" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/"
```

(Confirm `JsonContext` supports the dotted/bracketed JSON-path syntax `violations[0].field` before relying on it; the JsonInspector under `tests/Behat/Support/Json/` likely supports JSONPath via `softcreatr/jsonpath`. If it does not, fall back to JSONPath like `$.violations[0].field` per the JsonInspector's expected syntax.)

### Behat step-definition inventory (verified at planning time)

From `api/tests/Behat/Context/JsonContext.php`:
- `the response should be in JSON` — line 59.
- `the JSON node :node should be equal to :text` — line 90.
- `the JSON node :node should match :pattern` — line 134.
- `the JSON node :node should be equal to the string :text` — line 190.
- `the JSON node :node should be equal to the number :number` — line 202.
- `the JSON node :node should have :count element(s)` — line 211.
- `the JSON node :node should contain :text` — line 220.
- `the JSON node :node should not contain :text` — line 248.
- `the JSON node :name should exist` / `should not exist` — lines 276 / 285.

From `api/tests/Behat/Context/HttpRequestContext.php`:
- `I send a :method request to :url` — implicit (request dispatch).
- `the response status code should be :responseCode` — line 340.
- `the header :name should be equal to :value` — line 396.
- `the header :name should contain :value` — line 430.
- `the header :name should match :regex` — line 508.

If you need an "uneconomical" raw-body assertion (e.g., to pin the on-wire key order of a violation entry), use multiple `the JSON node :node should be equal to :text` steps at `violations[0].field`, `[0].message`, `[0].code` — that's sufficient to pin the order, since the JSON inspector's path resolution requires the exact key. The literal-byte-order regex check is unit-test territory (already covered by `testValidationFailedExceptionViolationKeyOrderIsFieldMessageCode`).

### Project Structure Notes

- **Alignment:** all new code extends existing Story 1.3 / 1.4 / 1.5 files (`ProblemDetailsFactory.php`, `ProblemDetailsFactoryTest.php`, `routes/test.yaml`). No new directories. Five new test fixtures slot in under the existing `Fixtures/` namespace. New Behat feature under existing `features/shared/error_contract/`. Total file count: +1 (factory edit), +1 (factory test edit), +1 (routes edit), +5 (new fixtures), +1 (new feature) = 9 files touched (4 modified + 5 added).
- **Variance:** none. The `services_test.yaml` resource block from Story 1.4 already autowires the new fixtures; the FoB SymfonyExtension shared-context absolute-URL workaround from Story 1.5 carries over verbatim.

### References

- [Source: `_bmad-output/planning-artifacts/epics.md#Story 1.6: Map ValidationFailedException to a structured violations[] extension`] — acceptance criteria (lines 366-381).
- [Source: `_bmad-output/planning-artifacts/epics.md#Requirements Inventory → Error Mapping`] — FR23 (`ValidationFailedException → 422 with violations[]`).
- [Source: `_bmad-output/planning-artifacts/epics.md#Requirements Inventory → Consumer-Facing Capabilities`] — FR47 (`violations` retrievable without string parsing), FR45 (`title` safe for end-user display).
- [Source: `_bmad-output/planning-artifacts/epics.md#Requirements Inventory → Wire Contract Conformance`] — FR1, FR2, FR3, FR4, FR5 (RFC 9457 wire shape, key order, encoding).
- [Source: `_bmad-output/planning-artifacts/epics.md#NonFunctional Requirements`] — NFR7 (prod no-leak), NFR19 (RFC 9457 schema validation), NFR20 (Symfony stable APIs).
- [Source: `_bmad-output/planning-artifacts/epics.md#Additional Requirements`] — AR1, AR2, AR3, AR4, AR5, AR6 (NOT deviated this story), AR7.
- [Source: `_bmad-output/implementation-artifacts/1-3-build-the-problemdetailsfactory-with-the-marker-to-http-status-mapping.md`] — `ProblemDetailsFactory` design (constants, branch structure, never-throws contract).
- [Source: `_bmad-output/implementation-artifacts/1-4-wire-the-exceptionresponder-listener-and-problemdetailsresponder.md`] — listener wiring, functional-test scaffold, test-only routing pattern.
- [Source: `_bmad-output/implementation-artifacts/1-5-bridge-symfony-framework-exceptions.md`] — Story 1.5 patterns: branch placement, narrow-import allowlist test, fixture-controller convention, Behat absolute-URL workaround.
- [Source: `api/src/Shared/Application/Problem/ProblemDetailsFactory.php`] — file extended by this story (lines 72-144 contain the `fromThrowable` body where the new branch slots in).
- [Source: `api/src/Shared/Application/Problem/ProblemDetails.php`] — value object (unchanged); the `extensions: array<string, mixed>` field accepts the violations list as `['violations' => [...]]`.
- [Source: `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php`] — listener (unchanged); delegates to the factory.
- [Source: `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php`] — test file extended by this story (Story 1.5 left it at line 658; this story adds 12 new methods).
- [Source: `api/features/shared/error_contract/symfony_bridges.feature`] — sibling feature; mirror the Background + absolute-URL pattern.
- [Source: `api/config/routes/test.yaml`] — routes file extended by this story (5 new entries inside the existing `when@test:` block).
- [Source: `api/config/services_test.yaml`] — autowires the Fixtures namespace (NOT edited by this story).
- [Source: `api/tests/Fixtures/Problem/rfc-9457.schema.json`] — RFC 9457 schema fixture; reused by `testRfc9457SchemaValidationStillPassesWithViolationsExtension`.
- [Source: `api/tests/Behat/Context/JsonContext.php`] — Behat JSON step definitions; relevant lines listed in Dev Notes above.
- [Source: `api/tests/Behat/Context/HttpRequestContext.php`] — Behat HTTP step definitions; relevant lines listed in Dev Notes above.
- [Source: `api/tools/behat/behat.yml.dist`] — default Behat suite config; **NOT edited by this story** (Story 1.5 already added `features/shared` to the default suite paths).
- [Source: `api/composer.json`] — `symfony/validator: ^8.0.9` already required (no AR6 deviation).
- [Source: `api/vendor/symfony/validator/Exception/ValidationFailedException.php`] — class signature.
- [Source: `api/vendor/symfony/validator/ConstraintViolationInterface.php`] — interface signature.
- [Source: `api/vendor/symfony/validator/ConstraintViolation.php`] — concrete class for test construction (10-arg constructor with named-arg form).
- [Source: `api/vendor/symfony/validator/ConstraintViolationList.php`] — concrete list (`new ConstraintViolationList([])` for empty; `new ConstraintViolationList([$v1, $v2])` for items).
- [Source: `api/CLAUDE.md` (2026-05-07 update)] — Behat preferred for new feature work; PHPStan-on-every-PHP-edit policy.
- [Source: `CLAUDE.md` (root)] — branch naming (`feat/api-validation-violations` or `feat/shared-validation-violations`), Conventional Commit prefix (`feat(api): ...`).

### Previous-story intelligence

**From Story 1.5 (done 2026-05-07):**
- The factory's branch order after Story 1.5 is: `DomainException` → `AccessDeniedException` → `AuthenticationException` → `HttpExceptionInterface` → unhandled `\Throwable`. Story 1.6 inserts a new branch between (1) and (2). The listener (`ExceptionResponder`) is contractually total over `\Throwable` and unaware of types — no listener edits needed.
- The architecture-import test (`testSourceFileContainsNoBannedImports`) was narrowed from a wholesale `'Symfony\\'` ban to surgical bans on `Symfony\Component\HttpFoundation\\`, `Symfony\Component\Messenger\\`, `Symfony\Component\Routing\\`, `Symfony\Bundle\\`, `Symfony\Bridge\\`. **`Symfony\Component\Validator\\` is NOT banned** — the two new validator imports slot in without a test edit. Confirm during implementation.
- Behat suite layout: `features/shared` is already in the default Behat suite (Story 1.5 added it). New `validation_violations.feature` slots in alongside `symfony_bridges.feature` under the same `error_contract/` folder.
- **Absolute-URL workaround:** the default `HttpRequestContext` baseUrl is `/api/v1`; FoB SymfonyExtension reuses context instances across suites, so a per-suite override does not stick. Use absolute `http://localhost/api/test/...` URLs in scenarios — `HttpRequestContext::iSendARequestTo()` skips the baseUrl prepend when the URL starts with `http`.
- **Linter normalizations expected:** Rector renames local `$exception` → `$<type>Exception`; CS-Fixer normalizes blank lines and import ordering. Don't fight the linter (Story 1.2/1.3/1.4/1.5 pattern — see `feedback_api_lint_privatize_final.md` memory).
- **Gherkin linter** requires snake_case filenames + 2/4-space indentation: name the new feature file `validation_violations.feature` (snake_case), use `  Scenario:` (2 spaces) and `    Given/When/Then/And` (4 spaces).
- **Story 1.4's `ExceptionResponderFunctionalTest` is left in place** as PHPUnit/WebTestCase regression coverage; do not migrate or extend it.

**From Story 1.4 (done 2026-05-07):**
- The listener mints UUIDv7 for `correlation-id` (fallback, until Story 2.1 lands) and `instance` per error.
- Test-only routing lives in `api/config/routes/test.yaml` with a `when@test:` block — extend it; don't create a new file.
- `services_test.yaml` already autowires `Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener\Fixtures\` — new fixtures slot in without config.

**From Story 1.3 (done 2026-05-07):**
- The factory is `final`, constructor-less, stateless. Adding the new branch + helper does not change that. `testFactoryHasNoConstructorAndIsFinal` should still pass.
- Cache-Control behavior: Symfony's `ResponseHeaderBag::computeCacheControlValue()` auto-appends `, private` to `no-store` — Behat's `the header :name should contain :value` step is the right step for `Cache-Control` assertions (NOT `should be equal to`).

### Recent commit context (top of `main`)

- `ef483f8 feat(api): remove docs`
- `05ab503 feat(api): shared uuid generator`
- `9f779b8 feat(api): validator helper`
- `7f79d21 feat(api): add ResourceNormalizer helper`
- `4220b96 chore(git): update .gitattributes`

Stories 1.1–1.5 are uncommitted on the working tree (visible in `git status`). The factory at `api/src/Shared/Application/Problem/ProblemDetailsFactory.php` already contains the Story 1.5 imports and branches; verify with `git diff` before adding the new branch.

### LLM-dev guardrails (anti-disaster)

- ✅ Add **exactly two** Symfony imports to `ProblemDetailsFactory.php`: `Symfony\Component\Validator\ConstraintViolationInterface`, `Symfony\Component\Validator\Exception\ValidationFailedException`. Use `instanceof` directly (the package is required).
- ✅ Branch order: insert the `ValidationFailedException` branch **immediately after** the `DomainException` branch and **before** Story 1.5's `AccessDeniedException` branch. Pin via `testDomainExceptionImplementingInvariantViolationDoesNotProduceViolationsExtension`.
- ✅ Do NOT add a new constant. The strings `'validation-failed'` and `'Validation failed.'` and the integer `422` are inline literals.
- ✅ Do NOT modify Story 1.5's `HTTP_STATUS_TYPE_MAP` (or any other existing constant). The map's 422 entry stays as `'invariant-violation'` (for `HttpExceptionInterface` with status 422). `ValidationFailedException` is a separate, more-specific branch with its own type.
- ✅ Title: literal `'Validation failed.'`. NEVER `$e->getMessage()` — leaks data + class names.
- ✅ Each violation entry: exactly three keys in order — `field`, `message`, `code`. Plain associative array, NOT a VO. Per-key sources: `$violation->getPropertyPath()`, `(string) $violation->getMessage()`, `$violation->getCode() ?? ''`.
- ✅ `violations` extension serializes as a **JSON array** (sequential keys), NOT a JSON object. Build via `$out[] = [...]` (sequential append). Pin via `testValidationFailedExceptionViolationsExtensionSerializesAsJsonArrayNotObject`.
- ✅ Empty list → empty array `[]`, never an empty object `{}` and never an absent extension. Pin via `testValidationFailedExceptionWithEmptyListProducesEmptyViolationsArray`.
- ✅ Only propagate `propertyPath`, `message`, `code` from each violation. Do NOT propagate `invalidValue`, `root`, `cause`, `constraint`, `messageTemplate`, `parameters`, `plural`. Pin via `testValidationFailedExceptionDoesNotPropagateInvalidValueOrRoot` and `testValidationFailedExceptionDoesNotPropagateMessageTemplate`.
- ✅ Test-only fixture controllers go under `tests/Functional/.../Fixtures/`; routes go in `config/routes/test.yaml` inside the `when@test:` block. No `services_test.yaml` edits.
- ✅ New integration coverage uses **Behat** (preferred per `api/CLAUDE.md`): create `api/features/shared/error_contract/validation_violations.feature` (snake_case filename); reuse existing `HttpRequestContext` + `JsonContext` step definitions. **Do NOT** add new PHPUnit `WebTestCase` tests — Story 1.4's `ExceptionResponderFunctionalTest` is the existing regression coverage and is not extended.
- ✅ NO `composer.json` / `composer.lock` edits — `symfony/validator: ^8.0.9` is already required.
- ✅ NO architecture-import banned-prefix list edits — `Symfony\Component\Validator\\` is not banned.
- ✅ NO listener / responder / VO / marker / `DomainException` edits — the story is purely additive in the factory + tests + fixtures + routes + Behat.
- ✅ NO new vendor dependencies. NO AR6 deviation.
- ✅ NO new constants on `ProblemDetailsFactory`. The map registry stays at exactly three constants (`MARKER_STATUS_MAP`, `MARKER_DEFAULT_TYPE_MAP`, `HTTP_STATUS_TYPE_MAP`).
- ✅ `make php.stan` clean after every PHP edit; `make php.lint`, `make php.unit`, `make php.behat`, `make php.test` clean at story completion.
- ✅ Linter normalizations expected (Rector privatizes protected methods on `final` classes — start with `private`; CS-Fixer alphabetizes imports — accept canonical form).

## Dev Agent Record

### Agent Model Used

claude-opus-4-7 (1M context) — `/bmad-dev-story` workflow.

### Debug Log References

- `make php.stan` — clean after factory edit, fixture controllers, and listener scoping (172 files analyzed, 0 errors).
- `make php.unit c='--filter=ProblemDetailsFactoryTest'` — 62 tests / 219 assertions pass (50 prior + 12 new Story 1.6 tests).
- `make php.unit` — 156 tests / 589 assertions pass, 1 skipped (Story 1.5 pre-existing skip).
- `make php.behat` — 36 scenarios / 226 steps pass (24 backoffice/frontoffice + 6 symfony_bridges + 6 validation_violations).
- `make php.lint` — clean (Rector / CS-Fixer / PHPMD / PHPCS / PHPStan / Psalm). Linter normalized `$list` → `$constraintViolationList` in three fixtures and the JsonSchemaValidator alias import slot in the test file (canonical-form normalization, expected per Dev Notes).
- `make php.test` — full belt-and-suspenders pass.

#### Listener-conflict deviation (out-of-scope but blocking)

`SearchExceptionListener` (priority 32) was unconditionally catching `ValidationFailedException` and emitting JSON:API errors before `ExceptionResponder` (priority 0) could see the exception. This pre-existing listener was not anticipated by the story but blocked the new wire contract on `/api/test/_throw-validation*`. Resolved by mirroring the existing `isSearchRoute()` gate already present on the listener's `InvalidArgumentException` branch — the validation-failed handler now only fires for routes whose name ends in `_search`. All non-search routes (including the new test fixtures) flow through `ExceptionResponder` and produce the unified Problem Details body. The legacy JSON:API contract on `_search` routes stays intact (search.feature scenarios at lines 34/39/43 continue to pass).

This is the only file outside the AC's explicit scope that was touched. AC #17's "no-change" list (markers / `DomainException` / `ProblemDetails` / `ExceptionResponder` / `ProblemDetailsResponder`) is preserved verbatim. Recommended follow-up for Epic 4 (Story 4-3 `api integration sweep`): once the PWA migrates off the JSON:API error format, remove the `_search` carve-out from `SearchExceptionListener` so the contract is truly uniform.

### Completion Notes List

- **Factory branch**: two new imports (`Symfony\Component\Validator\ConstraintViolationInterface`, `Symfony\Component\Validator\Exception\ValidationFailedException`), one new branch immediately after `DomainException` and before `AccessDeniedException`, one new private helper `buildViolations()`. Branch ordering pinned by `testDomainExceptionImplementingInvariantViolationDoesNotProduceViolationsExtension` (regression: marker `InvariantViolation` → `invariant-violation`, NOT `validation-failed`).
- **Wire shape**: violations entries are inline associative arrays with exactly the keys `field`, `message`, `code` in that order. Empty list serializes as JSON array `[]`; non-empty list serializes as JSON array of objects (`[{...},{...}]`), pinned by both unit regex and Behat node-length / index assertions.
- **No-leak guarantee (NFR7, FR45)**: `invalidValue`, `root`, `messageTemplate`, `parameters`, `cause`, `constraint`, `plural` are not propagated. Pinned by `testValidationFailedExceptionDoesNotPropagateInvalidValueOrRoot`, `testValidationFailedExceptionDoesNotPropagateMessageTemplate`, and the Behat scenarios `_throw-validation-with-sensitive-payload` / `_throw-validation-template`.
- **Title literal**: `'Validation failed.'` — never `$e->getMessage()` (the validator's `__toString()` would leak class names + every per-field message). Pinned by `testValidationFailedExceptionTitleIsTheLiteralValidationFailedNotTheMessage`.
- **RFC 9457 schema**: bodies with the new `violations` extension still validate against `tests/Fixtures/Problem/rfc-9457.schema.json` (`additionalProperties: true` allows the extension). Pinned by `testRfc9457SchemaValidationStillPassesWithViolationsExtension`.
- **`testSourceFileContainsNoBannedImports` unchanged**: `Symfony\Component\Validator\\` was not in the ban list (Story 1.5 narrowed the original wholesale `Symfony\\` ban to surgical prefixes), so the two new validator imports slot in without test edits.
- **Behat key-order scenario**: AC #9 originally suggested a raw-body regex pin. The existing Behat 3.31 `the response should contain :expected` step parses unescaped colons inside the quoted argument as placeholder boundaries (`"violations":[` is interpreted as `:violations` + literal + `:[…]`). Falling back to substring assertions on tokens without colons (`"violations"`, `"name"`, `"This value should not be blank."`, `"c1051bb4-…"`) plus full JSON-path coverage (`violations[0].field/message/code`) lands the same wire-shape pin. The literal-byte-order regex pin remains live at the unit-test layer (`testValidationFailedExceptionViolationKeyOrderIsFieldMessageCode`).
- **Linter normalizations accepted**: Rector renamed local `$list` → `$constraintViolationList` in three fixture controllers (Stories 1.2/1.3/1.4/1.5 pattern — don't fight the linter). CS-Fixer alphabetized the new test imports.
- **No vendor / config / contract churn**: `composer.json`, `composer.lock`, `services_test.yaml`, `Domain/Exception/*`, `ProblemDetails.php`, `ExceptionResponder.php`, `ProblemDetailsResponder.php` all untouched by this story (their `M` status in `git status` is from Stories 1.1–1.5, still uncommitted on `main`).
- **Dev-stack note**: the project's existing `php` container was bind-mounted from a stale Claude worktree; user authorized `docker compose up -d --force-recreate php` from the main project root to rebind `/app` against the current checkout before Behat could see the new files.

### File List

**Modified (4):**

- `api/src/Shared/Application/Problem/ProblemDetailsFactory.php` — two new imports, new `ValidationFailedException` branch (between `DomainException` and `AccessDeniedException`), new private `buildViolations()` helper.
- `api/src/Shared/Infrastructure/Http/EventListener/SearchExceptionListener.php` — scoped existing `ValidationFailedException` branch to `_search` routes only via `isSearchRoute()`; updated docstring to point non-search routes at `ExceptionResponder` (Story 1.6 carve-out, see Debug Log).
- `api/config/routes/test.yaml` — appended five new `when@test:` routes (`test_throw_validation`, `test_throw_validation_empty`, `test_throw_validation_with_sensitive_payload`, `test_throw_validation_template`, `test_throw_invariant_violation`).
- `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` — added imports (`JsonSchemaValidator`, three `Symfony\Component\Validator\…`); added 12 new test methods + one private helper `assertExpectedViolationEntry()` for shape narrowing.

**Added (6):**

- `api/features/shared/error_contract/validation_violations.feature` — 6 Behat scenarios.
- `api/tests/Functional/Shared/Infrastructure/Http/EventListener/Fixtures/ThrowValidationFailedController.php`
- `api/tests/Functional/Shared/Infrastructure/Http/EventListener/Fixtures/ThrowValidationFailedEmptyController.php`
- `api/tests/Functional/Shared/Infrastructure/Http/EventListener/Fixtures/ThrowValidationFailedSensitivePayloadController.php`
- `api/tests/Functional/Shared/Infrastructure/Http/EventListener/Fixtures/ThrowValidationFailedTemplateController.php`
- `api/tests/Functional/Shared/Infrastructure/Http/EventListener/Fixtures/ThrowInvariantViolationDomainExceptionController.php`

## Change Log

| Date       | Author | Change                                                                                                                                                              |
|------------|--------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 2026-05-07 | Sergio | Implemented Story 1.6: `ValidationFailedException` mapped to 422 / `validation-failed` / `application/problem+json` with structured `violations[]` extension.       |
| 2026-05-07 | Sergio | Scoped `SearchExceptionListener::ValidationFailedException` branch to `_search` routes only so `/api/*` flows through `ExceptionResponder`. Out-of-AC carve-out.   |
