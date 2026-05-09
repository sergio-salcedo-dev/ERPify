# Story 1.2: Introduce the `ProblemDetails` value object

Status: done

Epic: 1 — Uniform Error Contract (Producer Ergonomics)
Story Key: `1-2-introduce-the-problemdetails-value-object`

## Story

As a backend developer,
I want a framework-free `ProblemDetails` value object with a stable, spec-compliant serialization,
so that the wire shape of any error body is owned by one class and trivially snapshot-tested.

## Acceptance Criteria

1. **Given** the `DomainException` base from Story 1.1 exists, **when** the story is complete, **then** `api/src/Shared/Application/Problem/ProblemDetails.php` is a `final readonly` class in namespace `Erpify\Shared\Application\Problem` with constructor-promoted properties — declared in this order: `string $type`, `string $title`, `int $status`, `?string $detail`, `string $instance`, `string $correlationId`, `array $extensions = []`. The `$extensions` parameter has phpdoc `@param array<string, mixed>`. (FR1, FR4)
2. The new file declares `declare(strict_types=1);` and provides full PHP 8.5 parameter/return/property type coverage. (AR2, PSR-12)
3. The class file contains **zero `use` statements** referencing `Symfony\*`, `Doctrine\*`, `Psr\Http\*`, `Symfony\Component\Messenger\*`, or any HTTP/framework/ORM namespace. The class is pure PHP — built-in types only. (FR9 by association — keeps the wire shape framework-free so the same class can be constructed and snapshot-tested from any layer.)
4. The class exposes a public method `toArray(): array<string, mixed>` that returns an associative array whose keys are emitted in **exactly this order**: `type`, `title`, `status`, `detail`, `instance`, `correlation-id`, then each extension key in its iteration order. The PHP property `correlationId` (camelCase) maps to the JSON key `correlation-id` (kebab-case) — this is the only field with a name skew. (FR5)
5. When the `$detail` property is `null`, the `detail` key is **omitted** from `toArray()` output entirely (RFC 9457 marks `detail` optional, and omitting null produces minimum-spec conforming bodies). All other core fields are always present. Extensions whose value is `null` are emitted verbatim (consumers may set explicit-null intentionally).
6. Extensions are merged at the **top level** of the output array — they are **not** nested under an `extensions` key. This matches RFC 9457's extension-member model. (FR5; the integration test in Story 1.4 will assert the wire form `type,title,status,detail,instance,correlation-id,<extensions>`.)
7. **When** a `ProblemDetails` is serialized via `\json_encode($p->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)`, **then** the resulting JSON validates against the authoritative RFC 9457 JSON Schema bundled as a test fixture at `api/tests/Fixtures/Problem/rfc-9457.schema.json`. (NFR19) Validation in tests uses `justinrainbow/json-schema` (already pinned at `^6.8.2` in `api/composer.json` — no new composer dep required per AR6).
8. The class itself does **not** pre-validate or sanitize values inside `$extensions` (no redaction, no unserializable sentinel, no truncation). Those concerns are explicit seams owned by Epic 3 stories (3.2 redaction, 3.3 sentinel, 3.6 16 KiB cap). On a non-encodable value inside `$extensions`, `\json_encode` is allowed to throw `\JsonException` — that throw is the test signal that something upstream skipped Epic 3 filtering. (FR6, NFR13 anchor)
9. PHPUnit 13 unit tests under `api/tests/Unit/Shared/Application/Problem/ProblemDetailsTest.php` cover:
   - **Minimum field set:** `detail = null` and `extensions = []` produces an array with exactly six keys in this order: `type`, `title`, `status`, `instance`, `correlation-id` (no `detail` — omitted because null).
   - **All-fields-present:** `detail = "..."` and a non-empty `extensions` (e.g. `['violations' => [...]]`) produces seven+ keys in spec order, with extensions following the core block.
   - **Extension ordering preserved:** given `extensions = ['a' => 1, 'b' => 2, 'c' => 3]` (PHP array order is insertion order), the output array iteration over the extension tail yields `a, b, c` in that order. Asserting via `array_keys($result)` slice.
   - **`correlation-id` kebab-case mapping:** the PHP property `correlationId` does NOT appear in the array; the key `correlation-id` does.
   - **JSON key-order round-trip:** `\json_decode(\json_encode($p->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), associative: true)` followed by a re-`\json_encode` of the decoded array produces a **byte-identical** string. (Pins that PHP json_decode preserves object-key insertion order for associative decoding.)
   - **UTF-8 fidelity:** a `title` containing non-ASCII characters (e.g., "Não encontrado", "資源未找到") survives `\json_encode` + `\json_decode` unchanged when `JSON_UNESCAPED_UNICODE` is set; the encoded JSON contains the literal multi-byte sequence (no `\uXXXX` escape).
   - **JSON_THROW_ON_ERROR contract:** constructing a `ProblemDetails` whose `extensions` contains a `resource` (e.g., `\fopen('php://memory', 'r')`) and serializing throws `\JsonException`. (Documents the upstream filtering contract — Epic 3's job.)
   - **RFC 9457 schema validation:** a representative body validates against the bundled schema fixture using `justinrainbow/json-schema`. Helper test base or trait acceptable; keep the schema-validation invocation a single line so it can be reused in Story 1.4's integration sweep.
10. `composer dump-autoload` (PSR-4) resolves the new class under the existing `Erpify\\` → `src/` map without edits to `api/composer.json`. The test namespace is `Erpify\Tests\Unit\Shared\Application\Problem\` → `api/tests/Unit/Shared/Application/Problem/` per the existing `Erpify\Tests\\` → `tests/` map. (AR6)
11. `make php.lint` and `make php.unit` pass.

## Tasks / Subtasks

- [x] **Task 1 — Create the `Shared/Application/Problem/` folder and the value object** (AC: 1, 2, 3, 4, 5, 6)
  - [x] Create `api/src/Shared/Application/Problem/` directory
  - [x] Add `api/src/Shared/Application/Problem/ProblemDetails.php`: `final readonly class ProblemDetails` with constructor-promoted properties in declared order (`type`, `title`, `status`, `detail`, `instance`, `correlationId`, `extensions = []`).
  - [x] Phpdoc `$extensions` as `array<string, mixed>`. Method `toArray(): array<string, mixed>`.
  - [x] Implement `toArray()` to build the result via explicit key assignment in spec order, then merge extensions at the top level using `+ $this->extensions` (NOT `array_merge` — `+` preserves the left-hand-side keys' order and rejects right-hand-side collisions silently in favour of the left, which is the safe default for a malformed extension).
  - [x] If `$detail === null`, skip the `detail` key. Otherwise emit `'detail' => $this->detail`.
- [x] **Task 2 — Bundle the RFC 9457 JSON Schema fixture** (AC: 7, 9)
  - [x] Create `api/tests/Fixtures/Problem/` directory.
  - [x] Add `api/tests/Fixtures/Problem/rfc-9457.schema.json` with the official RFC 9457 schema body (members `type`, `title`, `status`, `detail`, `instance` typed per RFC; extensions allowed under `additionalProperties`). Source: RFC 9457 §A.2.
  - [x] Sanity-check the schema validates a known-good body (`testRepresentativeBodyValidatesAgainstRfc9457Schema`) and rejects malformed bodies (the `status` integer constraint and `required` block in the schema cover this).
- [x] **Task 3 — Unit tests** (AC: 9)
  - [x] Create `api/tests/Unit/Shared/Application/Problem/ProblemDetailsTest.php` (PHPUnit 13).
  - [x] Implement the eight named cases listed in AC #9, plus a ninth `testSourceFileContainsNoBannedImports` to enforce AC #3 in the same file (avoiding a parallel architecture-test class per the previous-story note in Dev Notes). AAA pattern; behaviour-named methods.
  - [x] For schema validation, use `justinrainbow/json-schema`'s `Validator::validate()` against the loaded schema fixture, inline (no premature helper extraction).
  - [x] Use `#[CoversClass(ProblemDetails::class)]` on the test class.
- [x] **Task 4 — Lint & autoload sanity** (AC: 10, 11)
  - [x] Ran `make composer c='dump-autoload'`; `git status` shows no `composer.json` change.
  - [x] `make php.stan`: **No errors** (after refining `toArray()` return type back to `array<string, mixed>` and using `assertArrayHasKey` / structural `assertSame` in tests so PHPStan can prove offset access).
  - [x] `make php.unit`: 71 tests / 240 assertions / OK. `make php.lint`: green (Rector/CS-Fixer/PHPStan/PHPMD/Psalm/Gherkin all clean).

## Dev Notes

### Architecture & constraints (load-bearing)

- **Layering (AR1):** `Shared/Application/Problem/` is Application layer — Application can in general import Symfony, but this story's AC #3 restricts THIS file to framework-free PHP. Reason: the value object is the wire-shape contract; keeping it free of framework imports lets it be constructed and snapshot-tested from anywhere (including unit tests with no kernel) and lets us swap or remove the HTTP layer later without touching the shape. The actual HTTP response wrapping is Story 1.4's `ProblemDetailsResponder` (`Shared/Infrastructure/Http/`). [Source: api/CLAUDE.md → Layer rules; docs/architecture-api.md]
- **Strict types (AR2):** `declare(strict_types=1);` on every new file, full type coverage on constructor params and return types.
- **Namespace:** root PSR-4 map is `Erpify\\` → `api/src/`. New namespace: `Erpify\Shared\Application\Problem`. Test namespace: `Erpify\Tests\Unit\Shared\Application\Problem`. No `composer.json` edit needed. [Source: api/composer.json]
- **No composer dependencies (AR6):** `justinrainbow/json-schema` is already pinned at `^6.8.2` (used by other parts of the project). Use it. **Do not add** any other JSON-schema lib.
- **Lint gate (AR7):** `make php.lint` must pass. Watch for the same lint ping-pong Story 1.1's debug log flagged — if `DomainExceptionTest.php`-style stylistic adjustments re-appear here, accept the linter's normalized form (it owns the tooling) and don't fight it.
- **Worker-mode safety (AR4, NFR16):** `final readonly class` with no static state — already worker-safe by construction. Don't add static caches, lazy properties, or memoization.

### File layout to create

```
api/src/Shared/Application/Problem/
  ProblemDetails.php           # final readonly value object

api/tests/Unit/Shared/Application/Problem/
  ProblemDetailsTest.php

api/tests/Fixtures/Problem/
  rfc-9457.schema.json         # RFC 9457 §A.2 schema, verbatim
```

### Anti-patterns to avoid

- **Do not** import any Symfony, Doctrine, HTTP-foundation, or `Psr\Http` symbol in `ProblemDetails.php`. The whole point of this class is shape-only. If you find yourself reaching for a Symfony Response or status enum, you're in the wrong layer — that's Story 1.4.
- **Do not** add redaction (denylist key stripping), `[unserializable]` sentinel substitution, or 16 KiB truncation here. Those are Stories 3.2, 3.3, 3.6 respectively. Leave the seams open: `extensions` is whatever the caller supplied.
- **Do not** introduce a builder, fluent setter, or factory method on this class. The factory is Story 1.3 (`ProblemDetailsFactory`). This class is just data + `toArray()`.
- **Do not** wrap extensions inside an `"extensions"` JSON key. RFC 9457 is explicit: extension members live at the top level of the problem details object alongside the standard members.
- **Do not** use `array_merge($core, $this->extensions)` when building `toArray()` — it lets extensions silently overwrite core members. Use the `+` operator (`$core + $this->extensions`) which keeps the left-hand keys in collision, OR explicitly assert no key collision and throw — pick one and document. Recommended: `$core + $this->extensions` (non-throwing, deterministic, matches RFC 9457's "extensions are additive" intent).
- **Do not** serialize the property `correlationId` as `correlationId` in JSON. The wire key is `correlation-id` (kebab-case). This is the single property where the PHP name and the JSON key differ.
- **Do not** make this class `Stringable` or add `__toString`. Serialization is the caller's job via `\json_encode($p->toArray(), …)`.
- **Do not** validate inputs in the constructor (e.g., reject empty `type`, validate `status` is in 100–599). The factory (Story 1.3) is the single gate. Same principle Story 1.1 applied to `DomainException`.

### Reuse surfaces & cross-story hooks

- **Story 1.3** (`ProblemDetailsFactory`) is the sole construction site in production code. The factory's `fromThrowable(\Throwable $e, string $correlationId, string $instance): ProblemDetails` will instantiate this VO. Don't pre-empt the factory's responsibilities; just make the VO instantiable.
- **Story 1.4** (`ProblemDetailsResponder` and `ExceptionResponder` listener) consumes `toArray()` and feeds it into `\json_encode($p->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)` for the response body. Match that exact flag combination in Story 1.2's tests so the wire bytes are identical.
- **Story 1.6** (`ValidationFailedException` mapping) puts a `violations` array into `extensions`. Test the all-fields-present case with a `violations` key shaped as `[['field' => 'name', 'message' => 'NotBlank', 'code' => 'IS_BLANK']]` — that's the shape Story 1.6 will produce. The schema fixture must allow this under `additionalProperties`.
- **Stories 3.2 / 3.3 / 3.6** filter `extensions` upstream — the VO trusts what arrives. If a future PR adds filtering inside `ProblemDetails`, that's an architectural regression: filtering belongs in the factory.
- **`api/src/Shared/Domain/Exception/DomainException.php`** (Story 1.1) is a producer of the `context` map that the factory will translate into `extensions`. The shapes are deliberately identical (`array<string, mixed>`) so the factory pass-through is trivial.

### Testing standards

- **Unit test framework:** PHPUnit 13 (AR5). Tests live under `api/tests/Unit/...` mirroring `src/`.
- **Invocation:** `make php.unit` for the whole suite; `make php.unit c='--filter ProblemDetailsTest'` for this story's tests only.
- **No Symfony kernel / WebTestCase needed** — pure PHP unit tests. `KernelTestCase` would defeat the framework-free assertion AC #3 protects.
- **AAA pattern**, one behaviour per test, behaviour-named methods (`test_<observable_behaviour>`), per `docs/project-context.md` testing rules.
- **Schema validation library:** `justinrainbow/json-schema` ^6.8.2 (already in `api/composer.json`). Typical usage:
  ```php
  $validator = new \JsonSchema\Validator();
  $data = \json_decode($json, false); // schema validators expect objects, not assoc arrays
  $validator->validate($data, (object) ['$ref' => 'file://' . $schemaPath]);
  $this->assertTrue($validator->isValid(), \json_encode($validator->getErrors()));
  ```
- **Fixture loading:** `\file_get_contents(__DIR__ . '/../../../../Fixtures/Problem/rfc-9457.schema.json')` — keep the relative path explicit (Story 1.1's `dirname(__DIR__, 5)` was fine but a literal relative path reads clearer for a one-shot fixture load).
- **Round-trip byte equality:** assert with `assertSame($expectedJson, $reEncodedJson)`. PHP's `json_encode` is deterministic on associative arrays whose keys are in insertion order, so a round-trip should preserve everything when both encode passes use the same flags.

### RFC 9457 JSON Schema fixture

Source it directly from RFC 9457 Appendix A.2. The schema (paraphrased — copy verbatim from the RFC):

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "urn:ietf:params:rfc:9457",
  "title": "RFC 9457 Problem Details",
  "type": "object",
  "properties": {
    "type":     { "type": "string", "format": "uri-reference", "default": "about:blank" },
    "title":    { "type": "string" },
    "status":   { "type": "integer", "minimum": 100, "maximum": 599 },
    "detail":   { "type": "string" },
    "instance": { "type": "string", "format": "uri-reference" }
  },
  "additionalProperties": true
}
```

`additionalProperties: true` allows our `correlation-id`, `violations`, and any future extensions to validate.

### Project Structure Notes

- **Alignment:** `Shared/Application/Problem/` is a new sibling under existing `Shared/Application/` subfolders (`DomainEvent/`, `Mailer/`, `UseCase/`, `Validation/`, `Http/`). Conforms to the project's "small focused subfolders under `Shared/Application/`" pattern. [Source: `api/src/Shared/Application/`]
- **Variance:** the fixture folder `api/tests/Fixtures/Problem/` is new. Verified state of `api/tests/` is `{Behat, DataFixtures, Functional, Unit}` — there is no pre-existing top-level `Fixtures/` folder and no `__fixtures__` convention. Creating `api/tests/Fixtures/Problem/` for this schema is the right move; leave room for future fixture domains as siblings (e.g., `api/tests/Fixtures/Audit/`). The `Erpify\\Tests\\` → `tests/` PSR-4 map already covers the test sources; the JSON schema lives outside any namespace and is loaded with `\file_get_contents`, so PSR-4 doesn't apply.

### References

- [Source: `_bmad-output/planning-artifacts/epics.md#Story 1.2: Introduce the `ProblemDetails` value object`] — acceptance criteria source of truth
- [Source: `_bmad-output/planning-artifacts/epics.md#Requirements Inventory → Wire Contract Conformance`] — FR1, FR2, FR4, FR5, FR6
- [Source: `_bmad-output/planning-artifacts/epics.md#Additional Requirements`] — AR1, AR2, AR5, AR6, AR7
- [Source: `_bmad-output/planning-artifacts/prd.md`] — product rationale for the wire shape
- [Source: `api/CLAUDE.md#Layer rules (load-bearing)`] — domain/application purity
- [Source: `docs/architecture-api.md#Layer responsibilities`] — Application layer guidance
- [Source: `docs/project-context.md#Testing rules`] — AAA pattern, behaviour-named tests
- [Source: `api/composer.json`] — `justinrainbow/json-schema: ^6.8.2` already present
- RFC 9457 §A.2 — JSON Schema for HTTP Problem Details (source for the fixture)

### Previous story intelligence (Story 1.1 — completed 2026-05-07)

Code review of Story 1.1 surfaced patterns and gotchas that apply directly here:

- **Lint ping-pong on test files:** `make php.lint`'s PHPCBF and PHP-CS-Fixer disagree on a few cosmetic items in test files (Story 1.1 debug log noted this on `DomainExceptionTest.php`). The aggregate target still exits 0. Don't try to fight the linters — the final state after `make php.lint` is the canonical form. If you doubled string-escape backslashes for clarity (as Story 1.1's review attempted), expect the fixer to revert them; that's documented as a tooling-config follow-up in `_bmad-output/implementation-artifacts/deferred-work.md`.
- **Architecture guard test pattern:** Story 1.1 added `TaxonomyArchitectureTest.php` enforcing no banned imports under `Shared/Domain/Exception/`. AC #3 of THIS story imposes the same constraint on `ProblemDetails.php`. **Do not** invent a parallel architecture test here — the file is alone in its folder; a single `assertStringNotContainsString('Symfony\\', $contents)` style assertion inside `ProblemDetailsTest` is sufficient. Avoid duplicating Story 1.1's `TaxonomyArchitectureTest` machinery.
- **`type()` / `title()` accessor naming on `DomainException`:** Story 1.1 settled on terse method names (`type()`, `title()`, `context()`). Story 1.3's factory will copy from `DomainException::type()` / `title()` / `context()` directly into `ProblemDetails::$type` / `$title` / `$extensions`. Don't rename or alias these in Story 1.2 — the contract is fixed.
- **Anonymous-class fixtures vs named test doubles:** Story 1.1 used anonymous-class `DomainException` subclasses in tests. For Story 1.2's tests, no subclass is needed — just construct `new ProblemDetails(...)` with named constructor args.

### Recent commit context (last 5 on `main`)

- `ef483f8 feat(api): remove docs`
- `05ab503 feat(api): shared uuid generator` — reusable UUID helpers in `Shared/Infrastructure/Uuid/`. Story 2.3 will mint UUIDv7 instances; this helper exists now but is **not used in Story 1.2**.
- `9f779b8 feat(api): validator helper` — `Shared/Application/Validation/Validator.php`. Same `final readonly` style + multi-line constructor formatting that Story 1.2 should mirror.
- `7f79d21 feat(api): add ResourceNormalizer helper`
- `4220b96 chore(git): update .gitattributes`

The `Validator.php` helper is the closest stylistic precedent for `ProblemDetails.php`: `final readonly` class, constructor-promoted properties, multi-line method signatures with newline-after-`(` and trailing comma per the user's PHP formatting preference.

### LLM-dev guardrails (anti-disaster)

- ✅ Place file at `api/src/Shared/Application/Problem/ProblemDetails.php` — **not** under `Domain/`, **not** under `Infrastructure/`.
- ✅ Use `final readonly class` with promoted constructor properties — **not** a plain class with public mutable properties.
- ✅ Map PHP `correlationId` → JSON `correlation-id` in `toArray()` only — **not** by renaming the PHP property to `correlation-id` (illegal identifier) or `correlation_id` (snake_case mismatch).
- ✅ Bundle the RFC 9457 schema as a static fixture file — **not** by fetching it at test time (no network in tests, per project-context.md testing rules).
- ✅ Use `justinrainbow/json-schema` for validation — **not** by adding `opis/json-schema`, `swaggest/json-schema`, or any other lib (AR6 forbids new deps).
- ✅ Omit `detail` from `toArray()` when null — **not** emit `'detail' => null` (RFC 9457 prefers absence; minimum-spec body).
- ✅ Merge extensions at top level via `+` — **not** `array_merge` (silent overwrite of core), and **not** nest under `'extensions' => ...` (corrupts wire format).
- ✅ Trust caller-supplied `extensions` shape — **not** validate, redact, sanitize, or truncate (Epic 3's job; this story keeps the seam open).

## Dev Agent Record

### Agent Model Used

claude-opus-4-7 (1M ctx) — Claude Code dev-story agent.

### Debug Log References

- **PHPStan + array shape friction:** First implementation tried to express `toArray()`'s return as a precise unsealed shape `array{type: string, ..., 'correlation-id': string, ...<string, mixed>}`. PHPStan parsed it as a *closed* shape and complained that the actual return (`array<string, mixed>` after `+ $this->extensions`) didn't match. Reverted to plain `@return array<string, mixed>` and refactored tests to either compare the full array structurally via `assertSame($expected, $actual)` (PHPStan-safe) or use `assertArrayHasKey` before any offset access (PHPStan-recognised type guard). Net: cleaner tests, smaller phpdoc, no behavioural change.
- **Composer classmap caching:** First test run after creating `ProblemDetails.php` reported "Class not found" for every test. Cause: the docker container's classmap was cached from before the file was added. `make composer c='dump-autoload'` resolved it. Worth noting that PSR-4 alone is fine; only the optimised classmap needed regenerating.
- **PHP-CS-Fixer normalisations on the test file:** The lint sweep auto-applied three changes to `ProblemDetailsTest.php` — (1) added `use JsonException;` and dropped the `\` prefix on `\JsonException`; (2) dropped the `\` prefix on the `JSON_*` constants (they're root-namespace global constants and the project's CS-Fixer config prefers unprefixed); (3) added blank lines between consecutive `private const` declarations. All cosmetic; final canonical form. Tests still 9/9 green after the rewrite.

### Completion Notes List

- Ultimate context engine analysis completed — comprehensive developer guide created.
- Story 1.2 implemented in full. AC1–AC11 satisfied: `Erpify\Shared\Application\Problem\ProblemDetails` lives at the prescribed path as a `final readonly` class, framework-free (verified by `testSourceFileContainsNoBannedImports` and by inspecting the file's `use` block — only `JsonException`/`JsonSchemaValidator` in tests; the production file has zero imports). `toArray()` emits keys in the exact spec order with the camelCase→kebab-case mapping for `correlationId` and detail-when-null omission. Extension members merge at the top level via `$body + $this->extensions` (deterministic, no overwrite of core members).
- RFC 9457 schema fixture bundled at `api/tests/Fixtures/Problem/rfc-9457.schema.json` (Draft-07 schema, paraphrased from §A.2 with `additionalProperties: true` so extensions like `correlation-id` and `violations` validate). `justinrainbow/json-schema ^6.8.2` validation in `testRepresentativeBodyValidatesAgainstRfc9457Schema` passes.
- Quality gates: `make php.unit` 71 tests / 240 assertions / **OK**; `make php.stan` **No errors**; `make php.lint` exit 0. `composer dump-autoload --classmap-authoritative` resolves all classes with no `composer.json` mutation (existing PSR-4 `Erpify\\` → `src/` covers the new namespace).
- One judgment call worth flagging for review: the architecture-import guard for `ProblemDetails.php` lives **inside** `ProblemDetailsTest.php` (per the story's previous-story note suggesting we not duplicate Story 1.1's `TaxonomyArchitectureTest` machinery for a single file). If reviewers prefer a parallel `ApplicationProblemArchitectureTest` to keep the pattern uniform across folders, this is a single-method extraction.

### File List

- `api/src/Shared/Application/Problem/ProblemDetails.php` (new)
- `api/tests/Unit/Shared/Application/Problem/ProblemDetailsTest.php` (new)
- `api/tests/Fixtures/Problem/rfc-9457.schema.json` (new)
- `_bmad-output/implementation-artifacts/sprint-status.yaml` (modified — story 1.2 status + last_updated)
- `_bmad-output/implementation-artifacts/1-2-introduce-the-problemdetails-value-object.md` (modified — task ticks, status, dev agent record)

### Change Log

| Date       | Change                                                                                          |
|------------|-------------------------------------------------------------------------------------------------|
| 2026-05-07 | Implemented `ProblemDetails` value object + RFC 9457 schema fixture + 9 unit tests. Lint and full unit suite green. Status: review. |
| 2026-05-07 | Code review complete — 3 adversarial layers, ~46 raw findings → 2 patches applied (`realpath()` guard via shared `loadSchemaRef()` helper + new `testSchemaRejectsMalformedBodies` negative test), 5 deferred (recorded in deferred-work.md), 32 dismissed as anti-pattern violations or out-of-scope. Status: done. |

### Review Findings

_Code review run on 2026-05-07. Three adversarial layers produced ~46 raw findings; 32 dismissed as either by-design (anti-patterns: no constructor validation, `+` operator preferred over `array_merge`, `correlationId`-as-kebab-case is a contract decision) or out-of-scope (factory methods → Story 1.3, redaction → 3.2, sentinels → 3.3, truncation → 3.6). Auditor verified AC1–AC11 clean. 2 patches, 5 deferred._

- [x] [Review][Patch] Schema test should assert `realpath()` does not return false [api/tests/Unit/Shared/Application/Problem/ProblemDetailsTest.php] — Fixed by extracting `loadSchemaRef()` helper that calls `assertFileExists` then `assertNotFalse(realpath(...))` before constructing the `'$ref' => 'file://' . $resolvedPath` object. Both `testRepresentativeBodyValidatesAgainstRfc9457Schema` and the new negative test reuse it.
- [x] [Review][Patch] Add negative schema test (Task 2 sanity-check sub-bullet) [api/tests/Unit/Shared/Application/Problem/ProblemDetailsTest.php] — Added `testSchemaRejectsMalformedBodies` exercising two rejection paths: (1) body missing the required `status` member, (2) body whose `status` is a string instead of an integer. Closes Task 2's "rejects malformed bodies" wording.
- [x] [Review][Defer] Extension key colliding with a core member (especially `'detail' => '...'` while constructor `$detail = null`) silently produces a body with `detail` at the WRONG position [api/src/Shared/Application/Problem/ProblemDetails.php:55] — deferred, the spec's Dev Notes explicitly recommended `$core + $this->extensions` (non-throwing, deterministic). The gate against reserved-key collisions belongs in `ProblemDetailsFactory` (Story 1.3), which is the sole production constructor of this VO. Add a factory test there asserting the factory never propagates a reserved key from `DomainException::context()` into `extensions`.
- [x] [Review][Defer] Banned-imports test inherits Story 1.1's regex shortcomings [api/tests/Unit/Shared/Application/Problem/ProblemDetailsTest.php:297-314] — deferred; same coverage gaps as the Story 1.1 architecture test (multi-line `use`, inline FQCN, grouped `use Foo\{A, B}`, BOM-prefixed first lines). Already deferred from Story 1.1's review (`deferred-work.md`); applies identically here. The Erpify-wide hardening (a project-level `Deptrac` / `PHPat` rule) would close this for both stories at once.
- [x] [Review][Defer] Banned-imports test scope is single-file — future siblings under `Shared/Application/Problem/` not scanned [api/tests/Unit/Shared/Application/Problem/ProblemDetailsTest.php:299] — deferred, current folder has only `ProblemDetails.php`. When Story 1.3 adds `ProblemDetailsFactory.php`, that story's tests should include the same architecture guard, OR we extract a `glob()`-driven scanner once a third file lands. Premature now.
- [x] [Review][Defer] `format: uri-reference` in the JSON Schema fixture is advisory under `justinrainbow/json-schema` [api/tests/Fixtures/Problem/rfc-9457.schema.json:9, :22] — deferred, default behavior of the validator does not enforce `format` keywords. AC #7 only requires "validates against the authoritative RFC 9457 JSON Schema" — the schema's structural shape is what matters; URI shape is enforced by Story 1.3's factory contract for `type` values. Future hardening: enable format-checking via `Constraint::CHECK_MODE_VALIDATE_SCHEMA` if/when needed.
- [x] [Review][Defer] Numeric-string extension keys behave unexpectedly with array `+` and through `json_decode(..., associative: true)` [api/src/Shared/Application/Problem/ProblemDetails.php:55, api/tests/Unit/Shared/Application/Problem/ProblemDetailsTest.php:138-159] — deferred, spec's `array<string, mixed>` typing rules numeric-string keys out by contract. The factory (Story 1.3) is the gate — its tests should pin a sane behaviour or reject these at construction. Not actionable for this story.
