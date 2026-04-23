# Story 1.1: Declare the domain exception taxonomy

Status: ready-for-dev

Epic: 1 — Uniform Error Contract (Producer Ergonomics)
Story Key: `1-1-declare-the-domain-exception-taxonomy`

## Story

As a backend developer,
I want a set of marker interfaces and a `DomainException` base class in `Shared/Domain/Exception/`,
so that I can signal the semantic intent of a failure without coupling my domain code to HTTP, ORM, or framework concerns.

## Acceptance Criteria

1. **Given** an empty `api/src/Shared/Domain/Exception/` folder, **when** the story is complete, **then** seven marker interfaces exist in that namespace: `NotFound`, `Conflict`, `Forbidden`, `Unauthenticated`, `InvariantViolation`, `InvalidInput`, `RateLimited`. (FR8)
2. Every marker interface file contains **zero `use` statements** referencing `Symfony\*`, `Doctrine\*`, `Psr\Http\*`, `Symfony\Component\Messenger\*`, or any other HTTP/framework/ORM/transport namespace. Interfaces are empty (no methods, no constants) — pure marker role. (FR9)
3. An abstract `DomainException` class exists at `api/src/Shared/Domain/Exception/DomainException.php`:
   - Extends native `\DomainException`.
   - Constructor signature: `__construct(string $type, string $title, array $context = [], ?\Throwable $previous = null)`.
   - `$context` is typed `array<string,mixed>` (phpdoc) and stored as a `private readonly array` property.
   - Exposes `public function type(): string` returning the opaque identifier (non-final — subclasses may override per FR13). Default implementation returns the `$type` passed to the constructor.
   - Exposes `public function title(): string` and `public function context(): array` (the latter returning the context map).
   - Calls `parent::__construct($title, 0, $previous)` so `getMessage()` returns the `title`.
4. The new file declares `declare(strict_types=1);`, uses namespace `Erpify\Shared\Domain\Exception`, and provides full PHP 8.5 parameter/return/property type coverage. (AR2, PSR-12)
5. A PHPStan or Psalm architecture rule (add a new `phpstan.neon` / `psalm.xml` rule, or a dedicated unit test reflecting over `Shared/Domain/Exception/*.php`) asserts that no file in `api/src/Shared/Domain/Exception/` imports any symbol from a disallowed namespace prefix (`Symfony\`, `Doctrine\`, `Psr\Http\`, `Symfony\Component\Messenger\`, `App\`). Prefer the reflection-based unit test — cheapest to maintain and lives alongside the taxonomy. (FR9)
6. A PHPUnit unit test constructs a throwaway anonymous `DomainException` subclass whose `implements` list declares `NotFound` **before** `Conflict`, then asserts `\class_implements($instance)` returns the marker FQCNs in that declared order. This pins the precedence **fixture** used by Story 1.3; the precedence **behavior** itself is not exercised here. (FR12)
7. `composer dump-autoload` (PSR-4) resolves all new classes/interfaces under the `Erpify\Shared\Domain\Exception\` prefix without edits to `composer.json` (existing PSR-4 map `Erpify\\` → `src/` already covers it — verify, do not modify). (AR6)
8. `make php.lint` passes (PHPStan, Rector dry-run, PHP-CS-Fixer dry-run, PHPMD, PHPCS, Psalm) and `make php.unit` passes.

## Tasks / Subtasks

- [ ] **Task 1 — Scaffold the folder and marker interfaces** (AC: 1, 2, 4, 7)
  - [ ] Create `api/src/Shared/Domain/Exception/` directory
  - [ ] Add 7 files, each `<Marker>.php`, namespace `Erpify\Shared\Domain\Exception`, `declare(strict_types=1);`, empty `interface <Marker> {}` — no `use` statements, no methods. Filenames: `NotFound.php`, `Conflict.php`, `Forbidden.php`, `Unauthenticated.php`, `InvariantViolation.php`, `InvalidInput.php`, `RateLimited.php`.
- [ ] **Task 2 — Implement `DomainException` abstract base** (AC: 3, 4)
  - [ ] `api/src/Shared/Domain/Exception/DomainException.php`: `abstract class DomainException extends \DomainException`
  - [ ] Constructor stores `$type`, `$title`, `$context` as `private readonly` properties; calls `parent::__construct($title, 0, $previous)`
  - [ ] Expose `type(): string`, `title(): string`, `context(): array` (non-final `type()` so FR13 override works in Story 1.3)
  - [ ] Phpdoc the context: `@param array<string,mixed> $context`
- [ ] **Task 3 — Architecture guard test** (AC: 5)
  - [ ] Create `api/tests/Unit/Shared/Domain/Exception/TaxonomyArchitectureTest.php`
  - [ ] Glob all `api/src/Shared/Domain/Exception/*.php`, parse each via `token_get_all` (or a simple regex on `^use ` lines), assert no `use` matches any banned prefix
  - [ ] Banned prefixes: `Symfony\\`, `Doctrine\\`, `Psr\\Http\\`, `Symfony\\Component\\Messenger\\`, `App\\`
- [ ] **Task 4 — Marker-precedence fixture test** (AC: 6)
  - [ ] Add `api/tests/Unit/Shared/Domain/Exception/DomainExceptionTest.php`
  - [ ] Use an anonymous class `new class('t','x') extends DomainException implements NotFound, Conflict {}`; assert `array_values(class_implements($e))` begins with `NotFound::class, Conflict::class` in that order
  - [ ] Also assert: `type() === 't'`, `title() === 'x'`, `context() === []`, `getMessage() === 'x'`, and that `DomainException` is abstract via `ReflectionClass`
- [ ] **Task 5 — Lint & autoload sanity** (AC: 7, 8)
  - [ ] Run `make composer c='dump-autoload'` (or rely on the Composer post-install hook) and verify no `composer.json` changes needed
  - [ ] Run `make php.lint` and `make php.unit`, fix any lint findings

## Dev Notes

### Architecture & constraints (load-bearing)

- **Layering (AR1):** `Shared/Domain/Exception/` is pure domain. No Symfony/Doctrine/HTTP imports in any file touched by this story. The HTTP mapping lives in Epic 1 Stories 1.3–1.4 under `Shared/Application/Problem/` and `Shared/Infrastructure/Http/` — not here. [Source: api/CLAUDE.md → Layer rules, docs/architecture-api.md]
- **Strict types (AR2):** `declare(strict_types=1);` on every new file; full type coverage on constructors, properties, methods.
- **Namespace:** root PSR-4 map is `Erpify\\` → `api/src/`. New namespace: `Erpify\Shared\Domain\Exception`. No `composer.json` edit needed. [Source: api/composer.json]
- **Existing convention reuse:** An abstract `Erpify\Shared\Domain\DomainError` already exists (`api/src/Shared/Domain/DomainError.php`) extending `\DomainException`. **Do not modify or delete it** in this story — it is referenced elsewhere (bounded-context domain errors extend it). The new `DomainException` introduced here is a **different** contract (carries opaque `type` + `context` for the RFC 9457 factory) and lives alongside `DomainError`. Existing `DomainError` subclasses will be bridged in a later story, not this one. If you see naming collision risk, prefer keeping the new class in the dedicated `Exception/` subfolder — fully-qualified references make it unambiguous.
- **Worker-mode safety (AR4, NFR16):** `DomainException` instances are per-request objects; no static mutable state is introduced. No action needed beyond "don't add statics."
- **No composer dependencies (AR6):** This story adds zero vendor dependencies. `symfony/uid` (needed later in Epic 2) is already transitively available — irrelevant here.
- **Lint gate (AR7):** `make php.lint` must pass. PHPMD may flag abstract classes without concrete methods; if it does, prefer a narrowly-scoped `@SuppressWarnings` on the class with a one-line justification over adjusting the global ruleset.

### File layout to create

```
api/src/Shared/Domain/Exception/
  DomainException.php          # abstract base
  NotFound.php                 # marker interface
  Conflict.php                 # marker interface
  Forbidden.php                # marker interface
  Unauthenticated.php          # marker interface
  InvariantViolation.php       # marker interface
  InvalidInput.php             # marker interface
  RateLimited.php              # marker interface

api/tests/Unit/Shared/Domain/Exception/
  TaxonomyArchitectureTest.php
  DomainExceptionTest.php
```

### Anti-patterns to avoid

- **Do not** add `getType()` / `getTitle()` Java-style getters — use the concise `type()` / `title()` naming per the epic spec; this matches the method signature Story 1.3 expects.
- **Do not** give marker interfaces any methods or constants. They are markers only; adding members would require every new `DomainException` subclass to implement them, breaking the FR11 "one-class-add-new-error" ergonomics.
- **Do not** import any Symfony HTTP-status constant here. Status mapping is the factory's job in Story 1.3; the domain must not know HTTP.
- **Do not** make `type()` final — Story 1.3 (FR13) requires subclasses to override it (e.g. `bank-not-found`).
- **Do not** put validation logic inside the constructor (e.g. non-empty `$type`). Keep it permissive; the factory is the single gate. Adding validation now creates a test-matrix burden for later stories.

### Reuse surfaces & cross-story hooks

- Story 1.2 consumes nothing from this file directly, but the `context` array shape (`array<string,mixed>`) is the input that Story 1.2's `ProblemDetails::$extensions` and Story 3.2's redaction denylist will filter. Keep the type alias phpdoc consistent: `array<string,mixed>` everywhere.
- Story 1.3 will build `ProblemDetailsFactory::fromThrowable()` against the `type()` / `title()` / `context()` surface defined here — do not rename these without updating the epics doc.
- Epic 3 Story 3.1 will inject `%kernel.environment%` into the factory — nothing to do here, but do not bake environment awareness into `DomainException` (anti-pattern: the domain must stay env-agnostic).

### Testing standards

- **Unit test framework:** PHPUnit 13 (AR5). Tests live under `api/tests/Unit/...` mirroring `src/` layout.
- **Invocation:** `make php.unit` runs the whole suite; `make php.unit c='--filter DomainExceptionTest'` runs a single test.
- **No Symfony kernel / WebTestCase needed for this story** — these are pure PHP unit tests. `KernelTestCase` would pull in DI and defeat the domain-purity assertion.
- **Architecture test strategy:** glob + file-read + regex for `^use ` lines is fine (cheap, self-contained). Avoid `nikic/php-parser` — it's not already a dep and AR6 forbids adding one.

### Project Structure Notes

- Alignment: `Shared/Domain/Exception/` is a new subfolder under the existing `Shared/Domain/` tree. Conforms to api/CLAUDE.md's "Put truly reusable code in `Shared/`" rule.
- Variance: none. The existing `DomainError.php` sits one level up (`Shared/Domain/`) for historical reasons; the new taxonomy lives deliberately in the `Exception/` subfolder to keep it separable and to signal its different contract.

### References

- [Source: _bmad-output/planning-artifacts/epics.md#Story 1.1: Declare the domain exception taxonomy] — acceptance criteria source of truth
- [Source: _bmad-output/planning-artifacts/epics.md#Requirements Inventory → Exception Taxonomy] — FR8–FR13
- [Source: _bmad-output/planning-artifacts/epics.md#Additional Requirements] — AR1, AR2, AR3, AR6, AR7
- [Source: _bmad-output/planning-artifacts/prd.md] — product rationale for the taxonomy
- [Source: api/CLAUDE.md#Layer rules (load-bearing)] — domain purity rule
- [Source: docs/architecture-api.md] — DDD + Hexagonal layering
- [Source: api/src/Shared/Domain/DomainError.php] — existing abstract to coexist with, not replace

## Dev Agent Record

### Agent Model Used

_to be filled by dev agent_

### Debug Log References

### Completion Notes List

- Ultimate context engine analysis completed — comprehensive developer guide created.

### File List
