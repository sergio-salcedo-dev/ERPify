# EnumType validator — design spec

**Date:** 2026-06-03
**Status:** Approved (pending spec review)
**Scope:** API (`api/`) — Shared bounded context + `Backoffice/BankAccount`

## Problem

ERPify needs a reusable Symfony validation constraint that asserts a property holds
a valid backed-enum case — behaving like any other property constraint
(`#[Assert\Iban]`, `#[Assert\Length]`, …) and firing during entity/DTO validation.
It must support an optional allowed subset of cases and produce a human-readable
error message listing the valid choices.

The working tree already wires `#[EnumType(...)]` onto `BankAccount`'s `currency`
and `status` properties, but the constraint class does not yet exist (no `use`
import, no class), so the API currently does not pass static analysis. This spec
covers building the constraint and finishing the in-flight entity changes.

Reference implementation (another project, for inspiration only):
`extracted_chz_projects/be-utilities-dev/src/Validator/EnumType.php` and
`.../EnumTypeValidator.php`. We port its behavior and modernize the API.

## Decisions (resolved during brainstorming)

- **Behavior:** instance-based, faithful to the reference. The validator accepts an
  already-hydrated enum instance (or `null` when `allowNull`); it does **not**
  resolve raw backing scalars via `tryFrom`. On a typed enum property the value is
  always the enum instance, exactly like `Assert\Iban` receiving a string.
- **API style:** modern named arguments via `#[HasNamedArguments]` — not the
  legacy `options: ['allowNull' => …, 'specificCases' => …]` array.
- **Placement:** `api/src/Shared/Infrastructure/Validator/` for both the constraint
  and its validator (framework-coupled adapter; colocated so Symfony's default
  `+Validator` suffix resolution applies). The `BankAccount` Domain entity imports
  the constraint the same way it already imports `Assert\*` / `UniqueEntity`.
- **Subset option renamed:** `specificCases` → `cases`.
- **`bank_id` FK:** restore `nullable: false` (the working-tree edit had silently
  dropped it to bare `#[ORM\JoinColumn]`, contradicting the `#[Assert\NotNull]`).

## Components

### 1. `EnumType` constraint

`api/src/Shared/Infrastructure/Validator/EnumType.php`

```php
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
final class EnumType extends Constraint
{
    public string $message = 'The value you selected is not a valid choice.';

    /**
     * @param class-string<\BackedEnum> $enumClass
     * @param list<\BackedEnum>         $cases     optional subset; empty = whole enum
     */
    #[HasNamedArguments]
    public function __construct(
        public string $enumClass,
        public bool $allowNull = false,
        public array $cases = [],
        ?array $groups = null,
        mixed $payload = null,
    ) {
        // Misconfiguration guard: ConstraintDefinitionException when $enumClass
        // is not a backed enum (fail fast at container build / first validation).
        parent::__construct(null, $groups, $payload);
    }
}
```

Improvements over the reference: named arguments, `$message` as a configurable
public property (Symfony convention), a misconfiguration guard, `final`, and the
redundant `getTargets()` override removed (the base default is already
`PROPERTY_CONSTRAINT`). No `validatedBy()` override — Symfony resolves
`EnumTypeValidator` from the constraint FQCN by the `+Validator` suffix.

### 2. `EnumTypeValidator`

`api/src/Shared/Infrastructure/Validator/EnumTypeValidator.php`

Logic (faithful port):

1. `$constraint` not an `EnumType` → throw `UnexpectedTypeException`.
2. `allowNull` and `value === null` → valid, return.
3. Violation when the value is **not** an instance of `enumClass`
   (`is_a($value, $constraint->enumClass)` — `null`, scalars, and other enums all
   fail cleanly), **or** `cases` is non-empty and the value is not in `cases`
   (strict `in_array`).
4. The violation sets `{{ choices }}`:
   - `cases` non-empty → labels of those cases (each `HumanReadableIntEnumInterface`
     case → `getLabel()`, otherwise its backing value);
   - else if `enumClass` implements
     `Erpify\Shared\Domain\Enum\Abstraction\HumanReadableIntEnumInterface`
     → `enumClass::getLabels()`;
   - else → string-cast backing values of `enumClass::cases()`.
   - `null` labels are filtered out so the rendered choice list stays clean.
   Rendered through `ConstraintValidator::formatValues()`.

Service registration: none needed explicitly — the existing `Erpify\:` resource
glob in `api/config/services.yaml` plus `autoconfigure: true` auto-tags any
`ConstraintValidator` with `validator.constraint_validator`.

### 3. Finish `BankAccount` + `Currency`

`api/src/Backoffice/BankAccount/Domain/Entity/BankAccount.php`:

- Add `use Erpify\Shared\Infrastructure\Validator\EnumType;`.
- Restore `#[ORM\JoinColumn(name: 'bank_id', referencedColumnName: 'id', nullable: false)]`
  on `$bank` (undo the silent nullability regression).
- Reformat `#[UniqueEntity(...)]` back to a single correctly-formatted attribute.
- Keep `status` as `Types::SMALLINT` and the `#[EnumType(...)]` annotations on
  `currency` and `status`.

`api/src/Shared/Domain/Enum/Currency.php`: keep trimmed to `EUR` (in scope per the
finish-entity decision).

### 4. Migration

The `bank_account` table-creating migration
`api/migrations/2026/Version20260602120000.php` is **branch-only / unmerged**
(verified against `origin/main`) and already declares `status INT NOT NULL` and
`bank_id UUID NOT NULL`.

- The only schema delta is `status INT → SMALLINT`. Because the CREATE migration is
  on this branch, edit it in place (`status INT NOT NULL` → `status SMALLINT NOT NULL`)
  rather than stacking a redundant ALTER migration. Editing branch-local migrations
  is allowed by the project rule.
- Restoring `bank_id` `nullable: false` realigns the entity mapping with the existing
  migration — **no** schema drift, no migration change for the FK.
- After editing, run `make db.diff` to confirm it reports **no remaining changes**
  (entity mapping ⇄ migration are in sync), then `make db.migrate`.

### 5. Tests

`api/tests/Unit/Shared/Infrastructure/Validator/EnumTypeValidatorTest.php`, using
Symfony's `ConstraintValidatorTestCase`:

- valid full-enum instance → no violation;
- value that is not the enum (scalar `"EUR"`, an instance of a different enum,
  `null` without `allowNull`) → violation carrying `{{ choices }}`;
- `allowNull: true` + `null` → no violation;
- subset: a case in `cases` passes, a valid enum case **not** in `cases` → violation;
- `{{ choices }}` content: labels for a `HumanReadableIntEnumInterface` enum
  (e.g. `BankAccountStatus`), backing values for a plain string enum (e.g. `Currency`);
- wrong constraint type → `UnexpectedTypeException`.

### 6. Fixtures (Hautelook Alice)

A Hautelook Alice fixture persists `BankAccount` rows so `make db.reset` /
`make db.load.fixtures` seeds the table (proving an account saves end-to-end with
the finished mapping and the `#[EnumType]`-annotated enum columns).

`api/tests/DataFixtures/Fixtures/BankAccount.yaml` mirrors the existing
`Bank.yaml` convention: `__factory` calling
`Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount::create` with positional
args. It passes only the four required args (`id`, `@bank_xx` relation,
`holderName`, `iban`) and relies on the factory defaults (`currency = EUR`,
`status = ACTIVE`), so no enum needs to be expressed in YAML. References existing
`@bank_01` / `@bank_02` from `Bank.yaml` (loaded from the same directory).

## Verification

- `make php.stan` **and** `make php.psalm` on every changed PHP file (the stack runs
  both; they disagree on narrowing, so both must pass).
- `make php.unit` for the new test.
- `make db.load.fixtures` then a `SELECT` on `bank_account` confirms the seeded
  rows persisted.
- `make php.quality` at the end (cs-fixer / psalm fixers may mutate files — run
  before committing to keep diffs clean).

## Out of scope / non-effects

- **No new error-contract marker.** The constraint emits standard
  `ConstraintViolation`s that flow through the existing RFC 9457 (Problem Details)
  pipeline as a 422. `docs/api-error-contract.md` does not change (NFR26 untouched).
- **No raw-scalar coercion.** Deliberately instance-only (see Decisions).
- **No new endpoint.** `BankAccount` has no write controller yet; validation is
  exercised at the entity/validator level.

## Security review (per CLAUDE.md)

- Injection: none — no SQL/DQL, no interpolation; this is itself an input-validation
  tool.
- Input validation: the feature's purpose; enum class is validated for
  misconfiguration.
- Secrets / mass assignment: not applicable — no payload fields, no audit-field
  exposure.
- Migration: reversible `down()`; no PII/secrets seeded; no `DROP TABLE`.
