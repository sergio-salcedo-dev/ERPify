# Story 1.3: Build the `ProblemDetailsFactory` with the marker → HTTP status mapping

Status: done

Epic: 1 — Uniform Error Contract (Producer Ergonomics)
Story Key: `1-3-build-the-problemdetailsfactory-with-the-marker-to-http-status-mapping`

## Story

As a backend developer,
I want a single `ProblemDetailsFactory` that translates any `\Throwable` into a `ProblemDetails`,
so that the marker-interface → HTTP status mapping lives in exactly one place and can be unit-tested exhaustively.

## Acceptance Criteria

1. **Given** Stories 1.1 and 1.2 are complete, **when** the story is complete, **then** `api/src/Shared/Application/Problem/ProblemDetailsFactory.php` exists in namespace `Erpify\Shared\Application\Problem` as a `final class` (not readonly — see AC #11 about seam methods) with a single public method:
   ```
   public function fromThrowable(\Throwable $e, string $correlationId, string $instance): ProblemDetails
   ```
   Strict types, full PHP 8.5 type coverage, PSR-12. (AR2)

2. The factory's marker-to-status mapping is declared as a single `private const array MARKER_STATUS_MAP = [...]` with **exactly seven entries** in the canonical iteration order: `NotFound::class => 404`, `Conflict::class => 409`, `Forbidden::class => 403`, `Unauthenticated::class => 401`, `InvariantViolation::class => 422`, `InvalidInput::class => 400`, `RateLimited::class => 429`. (FR14–FR20, NFR25 — the single source-of-truth for this mapping in the entire codebase.)

3. The factory declares a parallel `private const array MARKER_DEFAULT_TYPE_MAP = [...]` with the same seven keys, mapping each marker to a kebab-case opaque identifier: `NotFound::class => 'not-found'`, `Conflict::class => 'conflict'`, `Forbidden::class => 'forbidden'`, `Unauthenticated::class => 'unauthenticated'`, `InvariantViolation::class => 'invariant-violation'`, `InvalidInput::class => 'invalid-input'`, `RateLimited::class => 'rate-limited'`. (Used as fallback when the throwable's `type()` returns the empty string — see AC #6.)

4. **Given** a `DomainException` subclass implementing exactly one marker `M`, **when** `fromThrowable($e, $cid, $i)` is called, **then** the returned `ProblemDetails` has `status = MARKER_STATUS_MAP[M]`. (FR14–FR20)

5. **Given** a `DomainException` subclass implementing markers in declared order `[NotFound, Conflict]` (in the same `implements` clause), **when** `fromThrowable` is called, **then** the returned `ProblemDetails` has `status = 404` and the `type` resolved per AC #6 with `firstMarker = NotFound::class`. The first-declared marker wins (FR12). The factory must use `\class_implements($e)` filtered by `array_keys(self::MARKER_STATUS_MAP)` to compute the marker order; this honours Story 1.1's pinned ordering behaviour (`testMarkerOrderingFollowsImplementsClause`).

6. **Type resolution rule:** the factory sets `ProblemDetails::type` as follows:
   - If `$e` is a `DomainException` and `$e->type()` returns a **non-empty string**, use it verbatim. (FR13 — subclass override path.)
   - Else if `$e` is a `DomainException` with at least one marker, use `MARKER_DEFAULT_TYPE_MAP[$firstMarker]`.
   - Else if `$e` is a `DomainException` with no marker, use `'domain-error'`. (FR21)
   - Else (any other `\Throwable`, e.g. plain `\RuntimeException`), use `'unhandled-exception'`. (FR26 anchor — Stories 1.5 and 1.6 will refine specific Symfony bridge cases.)

7. **Status resolution rule:** the factory sets `ProblemDetails::status` as follows:
   - If `$e` is a `DomainException` with at least one marker, `MARKER_STATUS_MAP[$firstMarker]`.
   - Else, `500`. (Plain `DomainException` per FR21; non-`DomainException` per FR26 anchor.)

8. **Title and detail handling:**
   - For a `DomainException`: `ProblemDetails::title = $e->title()` verbatim. `ProblemDetails::detail = null`.
   - For a non-`DomainException` `\Throwable`: `ProblemDetails::title = $e->getMessage()` (or, if empty, the literal string `'An unexpected error occurred.'` — pick the safe fallback over leaking nothing). `ProblemDetails::detail = null`.
   - **Do not** populate `detail` from `$context['detail']` or anywhere else in this story; leave it `null`. Stories 1.5 / 1.6 / 3.x will refine `detail` semantics if needed.

9. **`correlationId` and `instance` pass-through:** the factory writes the provided `$correlationId` argument into `ProblemDetails::correlationId` and the provided `$instance` argument into `ProblemDetails::instance` **without modification**. The factory does **not** mint UUIDs, validate them, or check format — minting and validation happen upstream in Epic 2 (Stories 2.1 / 2.3). (FR27, FR28 anchors — observability-side; this story respects the input contract.)

10. **`context` → `extensions` handling:** the factory builds `ProblemDetails::extensions` from `$e->context()` (when `$e instanceof DomainException`) by applying, in order:
    - **Reserved-key filter:** drop entries whose keys are in the set `{'type', 'title', 'status', 'detail', 'instance', 'correlation-id'}` (case-sensitive — RFC 9457 keys are lower-case). This forecloses the wire-shape collision flagged in Story 1.2's review and pushes the contract to its proper home (the factory).
    - **Type-whitelist:** keep entries whose value is a scalar (`int`, `float`, `string`, `bool`), an `array`, or a `\JsonSerializable`. Drop everything else **silently** (no sentinel substitution in this story — Story 3.3 will replace "drop" with `"[unserializable]"`). The redaction denylist (`password`, `token`, etc.) is **also a no-op** in this story — Story 3.2 will fill it in.
    - **Seam methods (no-op now, filled by Epic 3):** expose two `protected` methods so subclasses can extend without overrides on the constructor — `protected function redactKeys(array $context): array { return $context; }` (Story 3.2) and `protected function applyUnserializableSentinel(mixed $value): mixed { return $value; }` (Story 3.3, which will return `'[unserializable]'` and emit a log). For Story 1.3 these methods exist and are called in the pipeline but return their inputs unchanged.
    For non-`DomainException` throwables, `extensions` is `[]`.

11. **Why `final class` not `final readonly`:** the seam methods (`redactKeys`, `applyUnserializableSentinel`) are designed for Epic 3 to override in a derived class. `final readonly` would forbid extension. Mark the class `final` for now; if Epic 3 prefers composition over inheritance for the seams, change to `final readonly` and inject seam strategies via constructor — that's a Story 3.2/3.3 concern. **Do not** add a constructor in this story; the factory has no dependencies yet.

12. The class file may import `Erpify\Shared\Domain\Exception\DomainException` and the seven marker interfaces, plus `Erpify\Shared\Application\Problem\ProblemDetails` and `Throwable` (and `JsonSerializable` if the type-whitelist check uses the FQCN). The factory may use built-in PHP types and the project's own classes — **no Symfony, Doctrine, or HTTP-foundation imports** in this story. `JsonSerializable` is a global SPL interface, not a framework type. (Soft-AC; AC #15's architecture test enforces it.)

13. PHPUnit 13 unit tests under `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` cover, at minimum:
    - **Status mapping per marker** (`#[DataProvider]` yielding seven cases — one per marker FQCN — that construct an anonymous-class `DomainException` subclass with that marker, call `fromThrowable`, and assert `status === <expected>`). FR53 anchor.
    - **Default-type per marker** (data-provided, seven cases) — the subclass passes empty string `''` as the constructor's `$type`, the factory falls through to `MARKER_DEFAULT_TYPE_MAP[$firstMarker]`, and the test asserts `type === '<expected>'`.
    - **Type override (FR13)** — anonymous subclass passes `'bank-not-found'` as constructor `$type`; factory must return `type === 'bank-not-found'`.
    - **Multi-marker first-declared precedence (FR12)** — anonymous subclass `implements NotFound, Conflict`; factory returns `status === 404` and `type === 'not-found'` (since `$type` is empty). A second case with `implements Conflict, NotFound` returns `status === 409` and `type === 'conflict'` — pins that class-implements ordering really drives the resolution.
    - **Plain `DomainException` (no marker) → 500 + `'domain-error'`** (FR21).
    - **Non-`DomainException` `\Throwable` → 500 + `'unhandled-exception'`** with title from `getMessage()` (and the empty-message fallback to the safe literal string).
    - **`correlationId` / `instance` pass-through** — verbatim, including degenerate inputs like an empty string (the factory does not validate; Epic 2 owns validation).
    - **Title pass-through** for `DomainException` — `title()` value verbatim; `detail` is `null`.
    - **Context → extensions: scalars, arrays, `\JsonSerializable`** copied through (one test with a representative mixed-shape context).
    - **Context → extensions: non-whitelisted types dropped** — closure, `fopen` resource, plain `stdClass` (without `JsonSerializable`) — all silently dropped from `extensions` (no key written, no exception thrown). Pin the silent-drop behaviour explicitly so Story 3.3 can refactor it into sentinel substitution with a clear regression signal.
    - **Reserved-key filter** — context with keys `['type', 'title', 'status', 'detail', 'instance', 'correlation-id', 'safe_key']` produces `extensions === ['safe_key' => <value>]`. (Closes the deferred Story 1.2 finding.)
    - **`MARKER_STATUS_MAP` invariants (NFR25 anchor)** — reflection-based test asserting the constant exists, contains exactly the seven marker FQCNs, has seven entries (no dupes, no extras), and that `MARKER_DEFAULT_TYPE_MAP` has the same seven keys.
    - **Architecture import guard for `ProblemDetailsFactory.php`** — same inline pattern Story 1.2 used (`testSourceFileContainsNoBannedImports`): grep the source for `'use Symfony\\'`, `'use Doctrine\\'`, `'use Psr\\Http\\'`, `'use Symfony\\Component\\Messenger\\'` — assert none. (AC #12 enforcement.)

14. `composer dump-autoload` (PSR-4) resolves the new class without edits to `api/composer.json`. The existing PSR-4 map covers `Erpify\Shared\Application\Problem\` already (Story 1.2 verified this). (AR6)

15. `make php.stan` reports no errors after each PHP edit (per project policy). `make php.lint` and `make php.unit` pass at story completion. (AR7)

## Tasks / Subtasks

- [x] **Task 1 — Implement `ProblemDetailsFactory`** (AC: 1, 2, 3, 6, 7, 8, 9, 10, 11, 12)
  - [x] Add `api/src/Shared/Application/Problem/ProblemDetailsFactory.php`: `final class ProblemDetailsFactory` in namespace `Erpify\Shared\Application\Problem`. `declare(strict_types=1);`.
  - [x] Declare `private const array MARKER_STATUS_MAP` and `private const array MARKER_DEFAULT_TYPE_MAP` with the seven entries each, in the canonical order listed in AC #2 / #3.
  - [x] Implement `public function fromThrowable(\Throwable $e, string $correlationId, string $instance): ProblemDetails` per the resolution rules in AC #6, #7, #8.
  - [x] Implement marker resolution helper: `private function firstMatchingMarker(\Throwable $e): ?string` returning the FQCN of the first marker (in `class_implements` order, intersected with `array_keys(self::MARKER_STATUS_MAP)`), or `null`. Pin the use of `\class_implements` (matches Story 1.1's `testMarkerOrderingFollowsImplementsClause`).
  - [x] Implement context filter: `private function buildExtensions(DomainException $e): array` applying the reserved-key filter and the type-whitelist (calling `$this->redactKeys()` and `$this->applyUnserializableSentinel()` as no-op seams).
  - [x] Add `redactKeys(array $context): array` and `applyUnserializableSentinel(mixed $value): mixed` no-op seams for Stories 3.2 / 3.3 with one-line phpdoc each. **Visibility note:** declared `protected` per AC #10 but Rector privatized them (rule: protected methods on `final` classes are unreachable). Accepted the canonical lint form — Stories 3.2/3.3 will relax `final` or switch to composition when they need true extension.
  - [x] After each edit run `make php.stan` and fix findings.
- [x] **Task 2 — Test fixtures and core resolution tests** (AC: 4, 5, 6, 7, 8, 13)
  - [x] Add `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` (PHPUnit 13). `final class ProblemDetailsFactoryTest extends TestCase`, `#[CoversClass(ProblemDetailsFactory::class)]`, `@internal`.
  - [x] Implement `testStatusMappingForEachMarker` with `#[DataProvider]` yielding seven cases, one per marker. Each case constructs `new class('', '') extends DomainException implements <Marker> {}` and asserts `$factory->fromThrowable($e, 'cid', 'inst')->status === <expected>`.
  - [x] Implement `testDefaultTypeForEachMarker` (mirror, asserting `type === '<expected-default>'`).
  - [x] Implement `testTypeOverrideWinsWhenNonEmpty` — anonymous subclass with constructor `$type='bank-not-found'`, assert `type === 'bank-not-found'`.
  - [x] Implement `testMultiMarkerFirstDeclaredWinsBothOrders` — two anonymous subclasses, `implements NotFound, Conflict` and `implements Conflict, NotFound`; assert each picks the first-declared marker's status + default-type.
  - [x] Implement `testPlainDomainExceptionMapsToFiveHundredDomainError`.
  - [x] Implement `testNonDomainThrowableMapsToFiveHundredUnhandledException` (and a sibling test for the empty-message fallback).
  - [x] Implement `testCorrelationIdAndInstancePassThroughVerbatim` — pass exotic values (empty string, very long string), assert verbatim.
  - [x] Implement `testTitlePassThroughFromDomainException`.
- [x] **Task 3 — Test the context → extensions pipeline** (AC: 10, 13)
  - [x] Implement `testContextScalarArrayJsonSerializableValuesPassThrough` — context has int, float, string, bool, null, array, and a `\JsonSerializable` instance; all appear in `extensions`.
  - [x] Implement `testContextNonWhitelistedValuesAreSilentlyDropped` — context has a `\Closure`, an `fopen` resource (use `php://memory` and `try/finally` for cleanup as Story 1.2 did), and a plain `\stdClass`. Each is dropped from `extensions`. Pin `'safe' => 1` survives alongside.
  - [x] Implement `testReservedKeysAreFilteredFromExtensions` — context has keys `['type'=>'x','title'=>'y','status'=>'z','detail'=>'w','instance'=>'v','correlation-id'=>'u','safe_key'=>'ok']`. Assert `extensions === ['safe_key' => 'ok']`.
- [x] **Task 4 — Architecture & invariants tests** (AC: 13)
  - [x] Implement `testMarkerStatusMapHasExactlyTheCanonicalSevenEntries` via `\ReflectionClass(ProblemDetailsFactory::class)->getReflectionConstant('MARKER_STATUS_MAP')`. Assert keys are exactly `[NotFound::class, Conflict::class, Forbidden::class, Unauthenticated::class, InvariantViolation::class, InvalidInput::class, RateLimited::class]` and values are `[404, 409, 403, 401, 422, 400, 429]`. Same shape test for `MARKER_DEFAULT_TYPE_MAP`.
  - [x] Implement `testSourceFileContainsNoBannedImports` — same pattern as Story 1.2's test, scanning `api/src/Shared/Application/Problem/ProblemDetailsFactory.php`. Banned prefixes: `Symfony\\`, `Doctrine\\`, `Psr\\Http\\`, `Symfony\\Component\\Messenger\\`, `App\\`. (Note: `JsonSerializable` is a root-namespace SPL interface — **not** banned.)
- [x] **Task 5 — Lint & autoload sanity** (AC: 14, 15)
  - [x] Run `make composer c='dump-autoload'`; verify `git status` shows no `composer.json` change.
  - [x] Run `make php.unit` and `make php.lint`; fix any findings without weakening tests.

## Dev Notes

### Architecture & constraints (load-bearing)

- **Layering (AR1):** `Shared/Application/Problem/ProblemDetailsFactory.php` is Application layer — same folder as the value object from Story 1.2. The factory **may** import the project's own domain types (`DomainException`, marker interfaces) and Application types (`ProblemDetails`), plus PHP/SPL globals (`Throwable`, `JsonSerializable`, `Closure`, `stdClass`, `ReflectionClass`). It **must not** import Symfony, Doctrine, HTTP-foundation, or Messenger types — the factory is the single mapping site, kept transport-free so it remains framework-portable. AC #12 makes this enforceable; AC #13's architecture test pins it. [Source: api/CLAUDE.md → Layer rules; docs/architecture-api.md]
- **Strict types (AR2):** `declare(strict_types=1);` on every new file, full type coverage on parameters, return types, and properties. PHP 8.5 idioms are fine but stick to 8.3 forward-compat; do **not** invent 8.5-specific syntax that isn't already battle-tested in the repo.
- **Composer hygiene (AR6):** zero new vendor dependencies. `Erpify\\` PSR-4 map covers the new namespace. Run `make composer c='dump-autoload'` once after creating the file (the docker classmap caches as Story 1.2 learned).
- **Lint gate (AR7):** `make php.lint` must pass. Expect the same auto-fixes as Stories 1.1 / 1.2 — accept the linter's canonical form.
- **Worker-mode safety (AR4, NFR16):** the factory has no constructor, no mutable properties, no static state. The seam methods are pure. Worker-safe by construction.

### File layout to create

```
api/src/Shared/Application/Problem/
  ProblemDetails.php           # Story 1.2 (already done — do not modify)
  ProblemDetailsFactory.php    # Story 1.3 (new)

api/tests/Unit/Shared/Application/Problem/
  ProblemDetailsTest.php             # Story 1.2 (already done — do not modify)
  ProblemDetailsFactoryTest.php      # Story 1.3 (new)
```

### Anti-patterns to avoid

- **Do not** modify `ProblemDetails.php` (Story 1.2's value object) — the factory consumes it as-is. The constructor positional/named-arg call site is `new ProblemDetails(type: ..., title: ..., status: ..., detail: ..., instance: ..., correlationId: ..., extensions: ...)`. Use named arguments for clarity.
- **Do not** mint UUIDs for `correlationId` or `instance` inside the factory. Those are inputs (Epic 2 owns minting). The factory's `fromThrowable` signature explicitly takes them as parameters.
- **Do not** add Symfony / Doctrine / HTTP imports. Keep the factory framework-free. Story 1.5 (Symfony bridge cases) will live in a **different file** or in a strategy injected into this one — not by importing Symfony exception classes here. For Story 1.3, non-`DomainException` `\Throwable` is handled with the generic 500 / `'unhandled-exception'` fallback, no Symfony-specific branches.
- **Do not** hardcode the marker → status mapping in conditionals (`if instanceof NotFound: 404; elseif instanceof Conflict: 409; ...`). Use the constant array (NFR25 — single source of truth). Conditional chains scatter the mapping and guarantee a future drift between code and `docs/api-error-contract.md` (Story 4.4).
- **Do not** use `array_merge` to combine reserved-key-filtered context with extensions — there is nothing to merge here; the factory builds `extensions` from a single source (`$e->context()`) and passes it whole.
- **Do not** populate `detail` in this story. Tempting to use `getMessage()` for `detail` and `title()` for title — but `title` and `detail` mean different things in RFC 9457 (short summary vs. occurrence-specific explanation). Keep `detail = null` until a downstream story explicitly defines its semantics.
- **Do not** validate `$correlationId` / `$instance` for UUIDv7 shape. Epic 2 owns format validation. The factory accepts any string (including empty) — it's a pure transformation, not a gate.
- **Do not** throw from `fromThrowable`. The factory must always return a `ProblemDetails`, even for the most malformed input. Story 3.4 (last-resort static body) is the only place a `try/catch` lives — at the listener boundary, not in the factory.

### Reserved-key filter (closes the deferred Story 1.2 finding)

Story 1.2's review deferred a real concern: an extension key colliding with a core RFC 9457 member (`type`, `title`, `status`, `detail`, `instance`, `correlation-id`) silently drops via the `+` operator in `ProblemDetails::toArray()`, AND when `$detail` is null the colliding `'detail'` extension slips into the body at the **wrong** position (after `correlation-id` instead of slot 4).

This story's AC #10 forecloses that footgun by filtering reserved keys out of `$e->context()` **before** they reach `ProblemDetails::extensions`. Match exactly the six core keys: `'type'`, `'title'`, `'status'`, `'detail'`, `'instance'`, `'correlation-id'`. Case-sensitive (RFC 9457 keys are all lower-case).

The test `testReservedKeysAreFilteredFromExtensions` is the regression pin. If Story 3.2's denylist key strip happens to also remove these (because someone adds `'instance'` to the security denylist), the regression should still pass — the factory is the single gate.

### Marker resolution: trust `class_implements()` order

Story 1.1's `testMarkerOrderingFollowsImplementsClause` pinned that `\class_implements($e)` returns the directly-declared markers in the order they appear in the `implements` clause. The factory exploits this:

```php
$implementedMarkers = \array_values(\array_intersect(
    \class_implements($e),
    \array_keys(self::MARKER_STATUS_MAP),
));
$firstMarker = $implementedMarkers[0] ?? null;
```

This is the same `array_intersect(class_implements, [...])` shape as Story 1.1's test — the precedence Story 1.1 pinned **is** what this story relies on. Add a comment in the helper noting the cross-story dependency.

### Type-whitelist for `context` → `extensions`

Spec phrase: "copies whitelisted scalar / array / `JsonSerializable` values through to `extensions`, leaving redaction and sentinel behavior as explicit seams filled by Epic 3 stories (no-ops for now)."

Concrete pipeline (in order):

1. Start with `$e->context()` (any `array<string, mixed>`).
2. **Reserved-key filter** (this story): drop entries whose key is in `RESERVED_KEYS` (the six RFC 9457 core members).
3. **Redaction** (Story 3.2): no-op now. Call `$this->redactKeys(...)` so the seam exists.
4. **Type-whitelist** (this story): for each remaining entry, keep if value is `is_scalar($v) || is_array($v) || $v instanceof \JsonSerializable`; otherwise drop the entry silently. Apply `$this->applyUnserializableSentinel($v)` to any dropped value before discarding (no-op now; Story 3.3 will return a `'[unserializable]'` string and emit a log — which is a **substitution**, not a drop).
5. Return the resulting array as `ProblemDetails::extensions`.

For Story 3.3's transition to be clean, the factory should NOT drop the value — instead, call `applyUnserializableSentinel` and **always include** its return value. For Story 1.3 (no-op seam returning `$value` unchanged), this means non-encodable values would slip through and break `json_encode`. That defeats the type-whitelist.

**Resolved approach**: in Story 1.3, the type-whitelist DROPS non-whitelisted entries silently. The seam `applyUnserializableSentinel` is wired but unused. When Story 3.3 fills it, the factory pipeline changes from "drop on type miss" to "substitute with sentinel" — that's a Story 3.3 PR concern, not a backwards-compat shim today. Test `testContextNonWhitelistedValuesAreSilentlyDropped` pins the current behaviour; Story 3.3 will rewrite it.

### Anonymous-class fixtures (matches Story 1.1 / 1.2 patterns)

For tests, construct `DomainException` subclasses inline:

```php
$e = new class ('', 'Bank not found') extends DomainException implements NotFound {};
```

For data providers yielding marker cases, return `[$exception, $expectedStatus, $expectedType]`. Static data providers can construct anonymous classes — PHPUnit 13 handles that fine.

For the type-override test (FR13), use a subclass that ALSO overrides `type()`:

```php
$e = new class ('does-not-matter', 'x') extends DomainException implements NotFound {
    public function type(): string { return 'bank-not-found'; }
};
```

This proves the override path even when the constructor's `$type` is non-empty — `type()` is the API the factory consults, not the constructor's stored value.

### Reuse surfaces & cross-story hooks

- **Story 1.4** (`ExceptionResponder` listener) consumes the factory: `$problemDetails = $this->factory->fromThrowable($e, $correlationId, $instance)`. The factory's return type is the contract.
- **Story 1.5** (Symfony framework exceptions) refines the non-`DomainException` fallback: `HttpExceptionInterface` → status from `getStatusCode()` and `type='http-error'`; `AccessDeniedException` → 403 / `'forbidden'`; `AuthenticationException` → 401 / `'unauthenticated'`. Story 1.5 may add explicit Symfony-aware branches **either** by extending the marker map (with Symfony-aware adapters that translate framework exceptions into virtual marker classes) **or** by adding explicit `instanceof` checks for the three Symfony classes. Either approach is fine; Story 1.3 doesn't dictate.
- **Story 1.6** (`ValidationFailedException` → `violations[]`) adds another Symfony-aware branch and surfaces a structured extension. Story 1.6 will likely augment `buildExtensions` to populate `violations` for that specific exception type. The seam architecture introduced here makes that straightforward.
- **Story 3.2** fills `redactKeys()` with the denylist (`password`, `token`, `secret`, `authorization`, `cookie`, `ssn`, `iban`).
- **Story 3.3** fills `applyUnserializableSentinel()` with `'[unserializable]'` substitution + log.
- **Story 4.2** depends on this story's `MARKER_STATUS_MAP` constant being a single source-of-truth (NFR25). Story 4.2's test reads the constant via reflection to assert "the mapping has exactly the seven canonical markers." This story's `testMarkerStatusMapHasExactlyTheCanonicalSevenEntries` is a strict subset of what Story 4.2 will rebuild — keep them consistent so 4.2 can extend rather than rewrite.

### Testing standards

- **Framework:** PHPUnit 13 (AR5). Tests live under `api/tests/Unit/Shared/Application/Problem/`.
- **Invocation:** `make php.unit c='--filter=ProblemDetailsFactoryTest'` for this story's tests; `make php.unit` for full suite to check regressions.
- **No Symfony kernel / WebTestCase** — pure PHP unit tests.
- **AAA pattern**, behaviour-named camelCase methods (`testCamelCase` — matches Story 1.1 / 1.2 in-repo style). Each test asserts one behaviour.
- **Anonymous classes for fixtures** — same pattern as Story 1.1's `DomainExceptionTest`. Don't introduce named test-double classes; they'd live in the production tree and bloat the autoload. Anonymous subclasses are scoped to the test method.
- **Data providers** are static methods returning `iterable`. Use named keys (`yield 'NotFound' => [...]`) so failures point at the marker by name.

### Project Structure Notes

- **Alignment:** `Shared/Application/Problem/` is the established home for this contract — Story 1.2 created it. The factory is its second resident. Test mirror under `api/tests/Unit/Shared/Application/Problem/`.
- **Variance:** none. The schema fixture from Story 1.2 (`api/tests/Fixtures/Problem/rfc-9457.schema.json`) is unrelated to this story (no JSON-Schema validation needed in the factory tests). Do not modify it.

### References

- [Source: `_bmad-output/planning-artifacts/epics.md#Story 1.3: Build the ProblemDetailsFactory with the marker → HTTP status mapping`] — acceptance criteria source of truth (lines 305-326)
- [Source: `_bmad-output/planning-artifacts/epics.md#Requirements Inventory → Exception Taxonomy / Error Mapping`] — FR8–FR26
- [Source: `_bmad-output/planning-artifacts/epics.md#Additional Requirements`] — AR1, AR2, AR5, AR6, AR7
- [Source: `api/CLAUDE.md#Layer rules (load-bearing)`] — Application-layer purity for the factory
- [Source: `_bmad-output/implementation-artifacts/1-1-declare-the-domain-exception-taxonomy.md`] — `DomainException::type()` / `title()` / `context()` contract
- [Source: `_bmad-output/implementation-artifacts/1-2-introduce-the-problemdetails-value-object.md`] — `ProblemDetails` constructor signature; `ProblemDetailsTest` patterns to mirror
- [Source: `api/src/Shared/Domain/Exception/DomainException.php`] — base class
- [Source: `api/src/Shared/Application/Problem/ProblemDetails.php`] — VO consumed by this factory
- [Source: `api/tests/Unit/Shared/Domain/Exception/DomainExceptionTest.php`] — `testMarkerOrderingFollowsImplementsClause` is the precedence behaviour this factory relies on
- [Source: `_bmad-output/implementation-artifacts/deferred-work.md` → "Reserved-key collision in ProblemDetails::toArray()"] — closes here

### Previous-story intelligence

**From Story 1.1 (done 2026-05-07):**
- `DomainException` exposes `type(): string`, `title(): string`, `context(): array<string, mixed>`. The `type()` method is intentionally non-final so subclasses can override (FR13). The default `type()` returns whatever the constructor stored — empty string is allowed.
- `\class_implements($e)` returns directly-declared markers in `implements`-clause order **after** parent-inherited interfaces (`Throwable`, `Stringable` come from `\Exception`). Filter via `array_intersect` against `array_keys(MARKER_STATUS_MAP)` to get only the markers, in the right relative order.
- Test pattern: `new class('t', 'x') extends DomainException implements NotFound {}` — anonymous-class subclasses are the idiomatic test fixture. Don't create named subclasses just for tests.

**From Story 1.2 (done 2026-05-07):**
- `ProblemDetails` constructor: `(string $type, string $title, int $status, ?string $detail, string $instance, string $correlationId, array $extensions = [])`. `final readonly` class. Public properties.
- `toArray()` returns `array<string, mixed>` in spec order with `correlationId` mapped to the JSON key `correlation-id`. Extensions merge at the top level via `+`.
- `array<string, mixed>` is the chosen return-type phpdoc for `toArray()` after PHPStan rejected the precise unsealed shape — the wire shape is asserted by tests, not phpdoc. Use the same approach for `buildExtensions(): array` here: `@return array<string, mixed>`.
- The lint sweep auto-applies CS-Fixer normalisations to test files: drops `\` prefix on root-namespace global constants (`JSON_*`), adds `use stdClass;` when a test casts via `(object) [...]`, adds blank lines between consecutive `private const` declarations. Don't fight the linter — accept the canonical form.
- The architecture-import guard for `ProblemDetails.php` lives **inside** `ProblemDetailsTest.php` rather than a parallel `ApplicationProblemArchitectureTest`. Mirror that placement here: the guard for `ProblemDetailsFactory.php` lives inside `ProblemDetailsFactoryTest.php`. (When a third file lands in the folder, factor the guard into a `glob()`-driven scanner — flagged in `deferred-work.md`.)

**From Story 1.2's review (deferred items still relevant here):**
- Reserved-key collision in `extensions`: closed by THIS story's AC #10. The factory is the gate.
- Banned-imports test regex limitations (multi-line `use`, FQCN, grouped `use Foo\{A, B}`): same gaps inherited; defer is still defer.
- Numeric-string keys in extensions: defer is still defer; not actionable in this story.

### Recent commit context (top of `main`)

- `ef483f8 feat(api): remove docs`
- `05ab503 feat(api): shared uuid generator` — `Shared/Infrastructure/Uuid/`. **Not used in Story 1.3** (Epic 2 owns minting).
- `9f779b8 feat(api): validator helper` — `Shared/Application/Validation/Validator.php`: stylistic precedent for `final readonly` Application-layer helpers.
- `7f79d21 feat(api): add ResourceNormalizer helper`

### LLM-dev guardrails (anti-disaster)

- ✅ Place file at `api/src/Shared/Application/Problem/ProblemDetailsFactory.php` — same folder as `ProblemDetails.php`. **Not** under `Domain/`, **not** under `Infrastructure/`.
- ✅ Use `final class` (not `final readonly`) — protected seam methods need overridability.
- ✅ Single `MARKER_STATUS_MAP` private const array as the only place the seven mappings live (NFR25). **Don't** scatter `if instanceof NotFound: 404` chains.
- ✅ Marker resolution via `\class_implements($e)` filtered by `array_keys(self::MARKER_STATUS_MAP)` — honours Story 1.1's pinned ordering. **Don't** iterate the constant and use `instanceof` per key — that gives map-order precedence, not implements-order precedence.
- ✅ `correlationId` and `instance` are inputs — the factory writes them verbatim. **Don't** validate, don't mint, don't normalize.
- ✅ Reserved-key filter on `$e->context()` BEFORE building extensions — closes the Story 1.2 deferred footgun.
- ✅ Type-whitelist (scalar | array | `\JsonSerializable`) — **drop** non-matching values silently. **Don't** sentinel-substitute (Story 3.3's job).
- ✅ Seam methods `redactKeys()` and `applyUnserializableSentinel()` exist as `protected` no-op pass-throughs — Stories 3.2 / 3.3 will fill them.
- ✅ Test fixtures via anonymous-class subclasses of `DomainException`. Data-provider-driven for the seven-marker matrix.
- ✅ Architecture import guard inline in `ProblemDetailsFactoryTest.php` — same pattern as Story 1.2.
- ✅ `make php.stan` clean after every PHP edit; `make php.lint` clean at story completion.

## Dev Agent Record

### Agent Model Used

Claude Opus 4.7 (1M context) — `claude-opus-4-7[1m]`.

### Debug Log References

- `make php.unit c='--filter=ProblemDetailsFactoryTest'` → 30 tests, 66 assertions, all passing.
- `make php.unit` (full suite) → 102 tests, 311 assertions, no regressions.
- `make php.stan` → 0 errors after one collateral fix in Story 1.2's pre-existing test (see Completion Notes).
- `make php.lint` → "No errors found" (PHPStan + Psalm + CS-Fixer + Rector + PHPCS).
- `make composer c='dump-autoload'` → no `composer.json` / `composer.lock` modifications.

### Completion Notes List

- Implemented `ProblemDetailsFactory` per AC #1–#12. The marker→status and marker→default-type mappings live as the two `private const array` declarations on the factory itself — single source of truth (NFR25). Marker resolution uses `\class_implements($e)` filtered by `array_keys(self::MARKER_STATUS_MAP)`, mirroring Story 1.1's `testMarkerOrderingFollowsImplementsClause` precedence.
- Reserved-key filter on `$e->context()` runs before the type-whitelist, closing the deferred Story 1.2 footgun: extension keys colliding with `type`/`title`/`status`/`detail`/`instance`/`correlation-id` are dropped before they reach `ProblemDetails::extensions`. Pin: `testReservedKeysAreFilteredFromExtensions`.
- Type-whitelist accepts `null`, scalars, arrays, and `\JsonSerializable`; everything else is silently dropped (Story 3.3 will substitute via `applyUnserializableSentinel`). `null` was added to the whitelist because Task 3 explicitly requires it to pass through and it's a valid JSON value.
- `applyUnserializableSentinel` is wired in the dropping branch with a `@phpstan-ignore method.resultUnused` so Story 3.3's substitution-vs-drop transition becomes a one-line change (replace the discarded call site with an assignment to `$extensions[$key]`).
- **Linter normalization caveat:** AC #10 specifies `protected` for the seam methods, but Rector's `PrivatizeFinalClassMethodRector` privatized them because the class is `final`. Accepted the canonical form per Story 1.2's "don't fight the linter" learning. Functional intent is preserved: the methods exist as identifiable seams; Stories 3.2/3.3 will either drop `final` or inject seam strategies via constructor — that's already noted in AC #11. Test `testFactoryHasNoConstructorAndIsFinal` was updated to assert `isPrivate()` with a comment explaining the rationale.
- **Out-of-scope cleanup:** Story 1.2's `ProblemDetailsTest::loadSchemaRef()` had a pre-existing PHPStan complaint (`@return object{$ref: string}` incompatible with native `stdClass`). Removed the redundant phpdoc — native return type stands. This was a one-line fix to unblock my story's `make php.stan` gate; flagging here for transparency.
- File-level architecture import guard for `ProblemDetailsFactory.php` lives **inside** `ProblemDetailsFactoryTest.php` (test `testSourceFileContainsNoBannedImports`), matching Story 1.2's placement convention. The folder-level scanner (parallel to `TaxonomyArchitectureTest`) is still deferred per `deferred-work.md`.
- Dropped my originally-written `testReturnedProblemDetailsIsValueObjectInstance` after the linter privatization discussion — the assertion is redundant given the native return type; PHPStan flagged `assertInstanceOf` as `method.alreadyNarrowedType`.

### File List

- `api/src/Shared/Application/Problem/ProblemDetailsFactory.php` (added)
- `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` (added)
- `api/tests/Unit/Shared/Application/Problem/ProblemDetailsTest.php` (modified — pre-existing PHPStan unblock; removed stale `@return object{$ref: string}` phpdoc on `loadSchemaRef`)

### Change Log

| Date       | Change                                                                                                       |
|------------|--------------------------------------------------------------------------------------------------------------|
| 2026-05-07 | Implemented `ProblemDetailsFactory` with seven-marker constant mapping and the context → extensions pipeline. |
| 2026-05-07 | Added comprehensive PHPUnit 13 coverage: status/type matrices, multi-marker precedence, reserved-key filter, type-whitelist drop, architecture import guard, constant-shape invariants. |
| 2026-05-07 | Removed stale `@return object{$ref: string}` phpdoc on `ProblemDetailsTest::loadSchemaRef` to clear pre-existing PHPStan issue. |
