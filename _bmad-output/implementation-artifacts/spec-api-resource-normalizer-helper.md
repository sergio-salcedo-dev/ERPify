---
title: 'Introduce ResourceNormalizer helper and adopt it in Bank Get/Post/Put controllers'
type: 'refactor'
created: '2026-05-06'
status: 'done'
baseline_commit: '4220b96fd164b7a589b450f817a66c99859cf7c4'
context:
  - '{project-root}/api/CLAUDE.md'
  - '{project-root}/CLAUDE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `BankGetController`, `BankPostController`, and `BankPutController` each repeat a `\json_decode($this->serializer->serialize($entity, 'json', ['groups' => [...]]), true, 512, JSON_THROW_ON_ERROR)` block to turn an entity into an array for the `Result` envelope. It is a serialize-then-decode round-trip — Symfony's `Serializer::serialize('json')` is `json_encode(normalize('json'))`, so decoding it back is wasted work — and it duplicates the same six-line ceremony at every call site. `Shared/Infrastructure/Http/Controller/AbstractSearchController:42` already uses `NormalizerInterface::normalize(...)` directly, proving the canonical path; the three Bank controllers are inconsistent stragglers and other bounded contexts will copy them as the pattern spreads.

**Approach:** Add a thin `Erpify\Shared\Infrastructure\Serializer\ResourceNormalizer` service that wraps `NormalizerInterface` and exposes `toArray(object $resource, array $groups, string $format = 'json'): array<string, mixed>`. Swap the three Bank controllers to inject `ResourceNormalizer` in place of `SerializerInterface` and replace each `json_decode(serialize(...))` block with a single `$this->resourceNormalizer->toArray($bank, [...])` call. Behavior on the wire is unchanged — same normalizer pipeline, same `'json'` format, same groups.

## Boundaries & Constraints

**Always:**
- New service lives in `api/src/Shared/Infrastructure/Serializer/` — Shared, not Bank-scoped.
- `ResourceNormalizer` depends on `Symfony\Component\Serializer\Normalizer\NormalizerInterface` only — no `SerializerInterface`, no `json_*` calls.
- Method returns `array<string, mixed>`; if `normalize()` ever yields a non-array (defensive), throw `\UnexpectedValueException`.
- Three Bank controllers drop their `SerializerInterface` constructor argument and stop importing `JsonException` / serializer exception interfaces no longer thrown.
- Response payloads must be byte-identical before/after — verified by unchanged Behat scenarios and functional tests.

**Ask First:**
- If the helper grows beyond the single `toArray(object, array, string): array` shape during implementation (e.g. needing a `toList` for iterables), HALT — that is a scope expansion.

**Never:**
- Don't touch `BankSearchController` / `AbstractSearchController` — they already use `NormalizerInterface` correctly.
- Don't introduce a static facade or trait — DI service only.
- Don't widen the change to non-Bank controllers in this spec, even if they grow the same pattern later.
- Don't modify `JsonDecoder` (it solves a different problem: decoding HTTP response bodies).
- Don't change serializer groups or response shape.

## I/O & Edge-Case Matrix

| Scenario                                  | Input / State                                                | Expected Output / Behavior                                                            | Error Handling                                       |
|-------------------------------------------|--------------------------------------------------------------|---------------------------------------------------------------------------------------|------------------------------------------------------|
| Normalize a Bank entity with groups       | `Bank` instance, `['identifiable','timestamped','bank:get']` | `array<string, mixed>` matching the previous `json_decode(serialize(...))` output     | N/A                                                  |
| `normalize()` returns non-array (defensive)| Misconfigured normalizer pipeline returns scalar/null        | Throw `\UnexpectedValueException` with debug-typed actual return                      | Caller surface unchanged — no swallowing             |
| Empty groups list                         | Entity, `[]`                                                 | Delegates as-is — `['groups' => []]` context passed through                           | N/A                                                  |

</frozen-after-approval>

## Code Map

- `api/src/Shared/Infrastructure/Serializer/ResourceNormalizer.php` -- NEW: helper service wrapping `NormalizerInterface::normalize(...)`.
- `api/src/Shared/Infrastructure/Serializer/JsonDecoder.php` -- existing static helper (HTTP body decoding); used as a sibling-style reference, not modified.
- `api/src/Shared/Infrastructure/Http/Controller/AbstractSearchController.php:42` -- existing precedent of direct `normalize()` use; mirror its semantics.
- `api/src/Backoffice/Bank/Infrastructure/Controller/BankGetController.php:47-62` -- replace block + swap injection.
- `api/src/Backoffice/Bank/Infrastructure/Controller/BankPostController.php:88-110` -- replace `serializeBank()` body + swap injection (drop the private method or shrink it to a one-liner).
- `api/src/Backoffice/Bank/Infrastructure/Controller/BankPutController.php:38-65` -- replace block + swap injection.
- `api/tests/Unit/Shared/Infrastructure/Serializer/ResourceNormalizerTest.php` -- NEW: unit test covering happy path and non-array defense.
- `api/config/services.yaml` -- inspect; rely on autowiring/autoconfiguration (no manual binding expected).

## Tasks & Acceptance

**Execution:**
- [x] `api/src/Shared/Infrastructure/Serializer/ResourceNormalizer.php` -- create `final readonly class ResourceNormalizer` with constructor `__construct(private NormalizerInterface $normalizer)` and `public function toArray(object $resource, array $groups, string $format = 'json'): array` -- single-purpose helper, autowired by Symfony's container.
- [x] `api/tests/Unit/Shared/Infrastructure/Serializer/ResourceNormalizerTest.php` -- create unit tests: (a) delegates to `NormalizerInterface::normalize` with the right format/groups context and returns the array unchanged, (b) throws `\UnexpectedValueException` when the inner normalizer returns a non-array, (c) unwraps `\ArrayObject` returns (added during review).
- [x] `api/src/Backoffice/Bank/Infrastructure/Controller/BankGetController.php` -- swap `SerializerInterface` for `ResourceNormalizer`; replace the `json_decode(serialize(...))` block with `$this->resourceNormalizer->toArray($bank, ['identifiable','timestamped','bank:get','bank:read:urls'])`; drop unused imports (`SerializerInterface`, `JsonException` if no longer thrown).
- [x] `api/src/Backoffice/Bank/Infrastructure/Controller/BankPostController.php` -- same swap; collapse the private `serializeBank()` to a single `toArray` call (or inline at the call site, whichever stays under 120 chars and matches the surrounding style).
- [x] `api/src/Backoffice/Bank/Infrastructure/Controller/BankPutController.php` -- same swap; replace the inline block; drop unused imports.
- [x] Run `make php.stan` on every changed PHP file -- expected: zero new errors.
- [x] Run `make php.lint` -- expected: clean (fixers may rewrite; commit those rewrites separately if any).
- [x] Run `make php.unit` and `make php.behat` -- expected: green; the existing Bank functional and Behat scenarios pin the response shape and prove byte-equivalence.

**Acceptance Criteria:**
- Given the three Bank controllers, when grepped for `json_decode` and `serialize(`, then no occurrences remain in those three files.
- Given an existing Bank functional/Behat test suite, when run against the refactored controllers, then every previously-green scenario stays green with no fixture/assertion changes.
- Given `ResourceNormalizer`, when injected into a controller via autowiring, then no manual `services.yaml` binding is required.

## Verification

**Commands:**
- `make php.stan` -- expected: 0 errors on changed files.
- `make php.unit c='--filter ResourceNormalizerTest'` -- expected: new unit test green.
- `make php.unit` -- expected: full suite green.
- `make php.behat` -- expected: all `@bank` scenarios green (or unchanged status if any are still `@wip`).
- `make php.lint` -- expected: clean (commit any auto-fixer rewrites separately).
- `! grep -RnE "json_decode\\(\\s*\\\$this->serializer->serialize" api/src/Backoffice/Bank/Infrastructure/Controller` -- expected: no matches.

## Suggested Review Order

**Helper design (start here)**

- One new abstraction wrapping `NormalizerInterface::normalize` — returns `array<string, mixed>`, unwraps `\ArrayObject`, throws on other shapes.
  [`ResourceNormalizer.php:26`](../../api/src/Shared/Infrastructure/Serializer/ResourceNormalizer.php#L26)

- `\ArrayObject` unwrap added during review — Symfony's `NormalizerInterface` contract permits it as a legitimate return.
  [`ResourceNormalizer.php:30`](../../api/src/Shared/Infrastructure/Serializer/ResourceNormalizer.php#L30)

**Adoption in Bank controllers**

- GET: replaces the 16-line `json_decode(serialize(...))` block with one call; injection swaps `SerializerInterface → ResourceNormalizer`.
  [`BankGetController.php:46`](../../api/src/Backoffice/Bank/Infrastructure/Controller/BankGetController.php#L46)

- POST: collapses the private `serializeBank()` helper into an inline `toArray` call.
  [`BankPostController.php:63`](../../api/src/Backoffice/Bank/Infrastructure/Controller/BankPostController.php#L63)

- PUT: same swap; PHPDoc trimmed (`JsonException` and serializer `ExceptionInterface` no longer thrown).
  [`BankPutController.php:52`](../../api/src/Backoffice/Bank/Infrastructure/Controller/BankPutController.php#L52)

**Tests**

- Four cases: delegation with format/groups assertion, custom-format pass-through, `\ArrayObject` unwrap, non-array defensive throw.
  [`ResourceNormalizerTest.php:21`](../../api/tests/Unit/Shared/Infrastructure/Serializer/ResourceNormalizerTest.php#L21)
