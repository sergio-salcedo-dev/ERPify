---
title: 'Introduce Validator::ensure() helper and adopt it in current call sites'
type: 'refactor'
created: '2026-05-06'
status: 'done'
baseline_commit: 'bf36819db24da0add14a510df7280e8a2d83331a'
context:
  - '{project-root}/api/CLAUDE.md'
  - '{project-root}/CLAUDE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `BankFinder::find():24-28` and `BankPostController::assertValidUpload():77-87` repeat the same six-line pattern: call `$this->validator->validate($value, $constraints)`, count violations, and throw `ValidationFailedException` when non-zero. The chiliz `be-utilities` `ValidatorTrait` solves this elsewhere with a setter-injected trait, but its `#[Required]` setter conflicts with Erpify's `final readonly` constructor-injection convention, and its custom `ValidationException` wrapper is redundant — Erpify controllers already catch Symfony's native `ValidationFailedException` directly. As more bounded contexts adopt input-level validation in their Application services, the boilerplate will multiply.

**Approach:** Add a thin `Erpify\Shared\Application\Validation\Validator` service that constructor-injects Symfony's `ValidatorInterface` and exposes a single method `ensure(mixed $value, Constraint|array|null $constraints = null, string|GroupSequence|array|null $groups = null): void`. It runs `validate(...)` and throws Symfony's **native** `ValidationFailedException` when violations exist (no custom wrapper). Adopt it in the two current call sites — `BankFinder` and `BankPostController::assertValidUpload` — replacing the manual count/throw with a single `ensure(...)` call. Behavior on the wire is unchanged: same constraints, same exception type, same controller catch sites.

## Boundaries & Constraints

**Always:**
- Service lives at `api/src/Shared/Application/Validation/Validator.php` — Shared, Application layer.
- Constructor injection only: `final readonly class Validator { __construct(private ValidatorInterface $validator) }`. No `#[Required]` setter — must compose with `final readonly` consumers.
- Throws Symfony's native `Symfony\Component\Validator\Exception\ValidationFailedException` — no custom wrapper exception, no translation layer.
- Method signature mirrors `ValidatorInterface::validate(...)` so callers keep all expressivity (groups, GroupSequence, single Constraint, array of Constraints, null).
- No-op when zero violations; identical exception payload to today's manual code (same `$value`, same `ConstraintViolationList`).

**Ask First:**
- If a third call site needs to adopt the helper but has materially different semantics (e.g. wants violations returned without throwing, or wants a wrapped exception), HALT — that's a scope expansion or a separate helper.

**Never:**
- Don't introduce a `ValidatorTrait` or `#[Required]` setter — those break `final readonly` constructor injection.
- Don't add a custom `ValidationException` class. Symfony's `ValidationFailedException` is already the project's lingua franca.
- Don't widen the change to call sites that don't currently do the count-and-throw dance (e.g. don't refactor `BankSearcher` or anything that uses `MapRequestPayload` / `MapQueryString` since Symfony already validates those before they reach Application code).
- Don't move this into `Shared/Domain/` — it imports `Symfony\Component\Validator\*` and `Application/` is the correct layer per `api/CLAUDE.md`.
- Don't change the constraints used in either call site (`[NotBlank, Uuid]` for `BankFinder`, `[File(...)]` for the upload guard).

## I/O & Edge-Case Matrix

| Scenario                                          | Input / State                                                  | Expected Output / Behavior                                                                 | Error Handling                                                |
|---------------------------------------------------|----------------------------------------------------------------|--------------------------------------------------------------------------------------------|---------------------------------------------------------------|
| Valid value                                       | `'7d4d…'` (UUID), `[NotBlank, Uuid]`                           | Returns `void`; no exception                                                               | N/A                                                           |
| Invalid value                                     | `'not-a-uuid'`, `[NotBlank, Uuid]`                             | Throws `ValidationFailedException` carrying `$value` and the full `ConstraintViolationList`| Caller surface unchanged — controllers already catch this    |
| `null` constraints (defer to `Valid` default)     | Any value, `null`                                              | Delegates to `ValidatorInterface::validate` with `null` constraints (Symfony default)      | Same as Symfony default                                       |
| Groups + GroupSequence pass-through               | Any value, constraints, `'Default'` or `new GroupSequence(...)`| Passes groups through unchanged to `ValidatorInterface::validate`                          | N/A                                                           |
| Validator returns empty list                      | Any                                                            | No throw                                                                                   | N/A                                                           |

</frozen-after-approval>

## Code Map

- `api/src/Shared/Application/Validation/Validator.php` -- NEW: helper service wrapping `ValidatorInterface::validate`.
- `api/src/Shared/Infrastructure/Serializer/ResourceNormalizer.php` -- existing sibling-style reference (constructor-injected, single method, Shared layer).
- `api/src/Backoffice/Bank/Application/BankFinder.php:22-28` -- replace 5-line check with `$this->validator->ensure($id, [new Assert\NotBlank(), new Assert\Uuid()]);`.
- `api/src/Backoffice/Bank/Infrastructure/Controller/BankPostController.php:71-88` -- swap manual count/throw inside `assertValidUpload()` for `$this->validator->ensure($file, [new File(...)]);`.
- `api/tests/Unit/Shared/Application/Validation/ValidatorTest.php` -- NEW: unit test (no-throw on empty violations, throw on non-empty, groups pass-through).
- `api/config/services.yaml` -- inspect; rely on autowiring (no manual binding expected).

## Tasks & Acceptance

**Execution:**
- [x] `api/src/Shared/Application/Validation/Validator.php` -- create `final readonly class Validator` with `__construct(private ValidatorInterface $validator)` and `public function ensure(mixed $value, Constraint|array|null $constraints = null, string|GroupSequence|array|null $groups = null): void`.
- [x] `api/tests/Unit/Shared/Application/Validation/ValidatorTest.php` -- create unit tests: (a) zero violations → no throw; (b) one or more violations → throws `ValidationFailedException` carrying the original `$value` and the violation list; (c) groups argument is passed through to the inner validator.
- [x] `api/src/Backoffice/Bank/Application/BankFinder.php` -- swap injection from `ValidatorInterface $validator` to `Validator $validator`; replace lines 24-28 with a single `ensure` call; drop the now-unused `ValidationFailedException` import (still thrown transitively, but not referenced in this file).
- [x] `api/src/Backoffice/Bank/Infrastructure/Controller/BankPostController.php` -- swap injection in `assertValidUpload()`'s validator dependency from `ValidatorInterface` to `Validator`; replace lines 77-87 with a single `ensure` call; keep the rest of the controller untouched.
- [x] Run `make php.stan` on every changed PHP file -- expected: zero new errors.
- [x] Run `make php.unit` and `make php.behat` -- expected: green; existing scenarios pin behavior equivalence.
- [x] Run `make php.lint` -- expected: clean.

**Acceptance Criteria:**
- Given the two adopters, when grepped for `count($constraintViolationList)` and `new ValidationFailedException(`, then no occurrences remain in those two files.
- Given the existing Bank Behat + functional suites, when run, then every scenario stays green with no fixture/assertion changes.
- Given `Validator`, when injected via autowiring, then no manual `services.yaml` binding is required.

## Verification

**Commands:**
- `make php.stan` -- expected: 0 errors on changed files.
- `make php.unit c='--filter ValidatorTest'` -- expected: new unit test green.
- `make php.unit` -- expected: full suite green.
- `make php.behat` -- expected: all `@bank` scenarios green.
- `make php.lint` -- expected: clean.
- `! grep -nE "count\\(\\\$constraintViolationList\\)" api/src/Backoffice/Bank/Application/BankFinder.php api/src/Backoffice/Bank/Infrastructure/Controller/BankPostController.php` -- expected: no matches.

## Suggested Review Order

**Helper design (start here)**

- The single new abstraction: wraps `ValidatorInterface::validate`, throws Symfony's native `ValidationFailedException` on non-empty violations, no-ops otherwise.
  [`Validator.php:28`](../../api/src/Shared/Application/Validation/Validator.php#L28)

- Why a service, not a trait: constructor injection composes with `final readonly` consumers; `#[Required]` setter (the chiliz pattern) doesn't.
  [`Validator.php:14`](../../api/src/Shared/Application/Validation/Validator.php#L14)

**Adoption in Bank**

- Replaces 5-line manual count/throw with one `ensure` call; `@throws` PHPDoc added so downstream tooling sees the transitively-thrown exception.
  [`BankFinder.php:28`](../../api/src/Backoffice/Bank/Application/BankFinder.php#L28)

- Same swap in the upload guard inside `assertValidUpload()`; controller's `__invoke` and the `InvalidImageException` path are untouched.
  [`BankPostController.php:76`](../../api/src/Backoffice/Bank/Infrastructure/Controller/BankPostController.php#L76)

**Tests**

- Seven cases: zero violations, throw with original value+list, groups passthrough, null-default forwarding, single-`Constraint` shape, single-string group, `GroupSequence` group.
  [`ValidatorTest.php:24`](../../api/tests/Unit/Shared/Application/Validation/ValidatorTest.php#L24)
