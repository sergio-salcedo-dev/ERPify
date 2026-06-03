# EnumType Validator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a reusable Symfony `#[EnumType]` property constraint that validates a value is a (subset-restricted, optionally nullable) case of a backed enum, and finish wiring it into `BankAccount`.

**Architecture:** A `Constraint` attribute (`EnumType`) plus its `ConstraintValidator` (`EnumTypeValidator`) live together in `Shared/Infrastructure/Validator/`, auto-registered as a tagged validator by the existing `Erpify\:` service glob. The validator is instance-based (faithful to the reference): it accepts a hydrated enum instance or `null` (when `allowNull`), never raw scalars, and renders a human-readable `{{ choices }}` list using `HumanReadableIntEnumInterface::getLabels()` when available. `BankAccount` consumes it the same way it already uses `#[Assert\*]`.

**Tech Stack:** PHP 8.5 · Symfony 8 Validator (`HasNamedArguments`, `ConstraintValidatorTestCase`) · Doctrine ORM · PHPUnit · PHPStan (level max) + Psalm.

**Spec:** `docs/superpowers/specs/2026-06-03-enum-type-validator-design.md`

---

## Commit policy (ERPify)

ERPify commits **only when the user explicitly asks**, using Conventional Commits. The `Commit` steps below are **checkpoints**: confirm with the user before running them, and first verify you are on the intended branch (currently `claude/sleepy-lovelace-iGWqm`). Never amend the user's existing commits. Stage files explicitly (no `git add -A`). If the user prefers to batch, skip the per-task commits and do one at the end.

## File structure

| Path | Responsibility | Action |
|------|----------------|--------|
| `api/src/Shared/Infrastructure/Validator/EnumType.php` | The constraint attribute (declarative: `enumClass`, `allowNull`, `cases`, `message`). | Create |
| `api/src/Shared/Infrastructure/Validator/EnumTypeValidator.php` | The validation logic + `{{ choices }}` formatting. | Create |
| `api/tests/Unit/Shared/Infrastructure/Validator/Fixtures/FixtureStringEnum.php` | Plain string enum (no labels) — exercises the value-fallback choices path. | Create |
| `api/tests/Unit/Shared/Infrastructure/Validator/Fixtures/FixtureLabeledEnum.php` | Int enum implementing `HumanReadableIntEnumInterface` — exercises the labels path. | Create |
| `api/tests/Unit/Shared/Infrastructure/Validator/EnumTypeValidatorTest.php` | Behavior + choices-formatting + guard tests. | Create |
| `api/src/Backoffice/BankAccount/Domain/Entity/BankAccount.php` | Add the `use` import, restore the FK `JoinColumn`, tidy `UniqueEntity`. | Modify |
| `api/migrations/2026/Version20260602120000.php:19` | `status INT` → `status SMALLINT`. | Modify (branch-only migration) |
| `api/tests/DataFixtures/Fixtures/BankAccount.yaml` | Hautelook Alice fixture persisting `BankAccount` rows (proves an account saves). | Create |

---

## Task 1: Build the `EnumType` constraint + validator (TDD)

**Files:**
- Create: `api/tests/Unit/Shared/Infrastructure/Validator/Fixtures/FixtureStringEnum.php`
- Create: `api/tests/Unit/Shared/Infrastructure/Validator/Fixtures/FixtureLabeledEnum.php`
- Create: `api/tests/Unit/Shared/Infrastructure/Validator/EnumTypeValidatorTest.php`
- Create: `api/src/Shared/Infrastructure/Validator/EnumType.php`
- Create: `api/src/Shared/Infrastructure/Validator/EnumTypeValidator.php`

- [ ] **Step 1: Create the two test-fixture enums**

`api/tests/Unit/Shared/Infrastructure/Validator/Fixtures/FixtureStringEnum.php`:

```php
<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Validator\Fixtures;

/**
 * Plain string-backed enum with no human-readable labels. Drives the
 * value-fallback branch of EnumTypeValidator::formatChoices().
 */
enum FixtureStringEnum: string
{
    case A = 'a';
    case B = 'b';
    case C = 'c';
}
```

`api/tests/Unit/Shared/Infrastructure/Validator/Fixtures/FixtureLabeledEnum.php`:

```php
<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Validator\Fixtures;

use Erpify\Shared\Domain\Enum\Abstraction\HumanReadableIntEnumInterface;
use Erpify\Shared\Domain\Enum\Abstraction\HumanReadableIntEnumTrait;
use Erpify\Shared\Domain\Enum\Attribute\HumanReadableIntEnumValue;

/**
 * Int-backed enum exposing human-readable labels. Drives the getLabels()
 * branch of EnumTypeValidator::formatChoices().
 */
enum FixtureLabeledEnum: int implements HumanReadableIntEnumInterface
{
    use HumanReadableIntEnumTrait;

    #[HumanReadableIntEnumValue(label: 'one')]
    case ONE = 1;

    #[HumanReadableIntEnumValue(label: 'two')]
    case TWO = 2;

    #[HumanReadableIntEnumValue(label: 'three')]
    case THREE = 3;
}
```

- [ ] **Step 2: Write the failing validator test**

`api/tests/Unit/Shared/Infrastructure/Validator/EnumTypeValidatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Validator;

use Erpify\Shared\Infrastructure\Validator\EnumType;
use Erpify\Shared\Infrastructure\Validator\EnumTypeValidator;
use Erpify\Tests\Unit\Shared\Infrastructure\Validator\Fixtures\FixtureLabeledEnum;
use Erpify\Tests\Unit\Shared\Infrastructure\Validator\Fixtures\FixtureStringEnum;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\ConstraintDefinitionException;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;
use Symfony\Component\Validator\Validator\ConstraintValidatorInterface;

/**
 * @internal
 */
final class EnumTypeValidatorTest extends ConstraintValidatorTestCase
{
    private const string MESSAGE = 'The value you selected is not a valid choice.';

    public function testValidEnumInstancePasses(): void
    {
        $this->validator->validate(FixtureStringEnum::A, new EnumType(FixtureStringEnum::class));

        $this->assertNoViolation();
    }

    public function testNullWithAllowNullPasses(): void
    {
        $this->validator->validate(null, new EnumType(FixtureStringEnum::class, allowNull: true));

        $this->assertNoViolation();
    }

    public function testNullWithoutAllowNullRaisesViolation(): void
    {
        $this->validator->validate(null, new EnumType(FixtureStringEnum::class));

        $this->buildViolation(self::MESSAGE)
            ->setParameter('{{ choices }}', '"a", "b", "c"')
            ->assertRaised();
    }

    public function testRawScalarRaisesViolation(): void
    {
        $this->validator->validate('a', new EnumType(FixtureStringEnum::class));

        $this->buildViolation(self::MESSAGE)
            ->setParameter('{{ choices }}', '"a", "b", "c"')
            ->assertRaised();
    }

    public function testDifferentEnumInstanceRaisesViolation(): void
    {
        $this->validator->validate(FixtureLabeledEnum::ONE, new EnumType(FixtureStringEnum::class));

        $this->buildViolation(self::MESSAGE)
            ->setParameter('{{ choices }}', '"a", "b", "c"')
            ->assertRaised();
    }

    public function testValueInSubsetPasses(): void
    {
        $this->validator->validate(
            FixtureStringEnum::A,
            new EnumType(FixtureStringEnum::class, cases: [FixtureStringEnum::A, FixtureStringEnum::B]),
        );

        $this->assertNoViolation();
    }

    public function testValidEnumOutsideSubsetRaisesViolation(): void
    {
        $this->validator->validate(
            FixtureStringEnum::C,
            new EnumType(FixtureStringEnum::class, cases: [FixtureStringEnum::A, FixtureStringEnum::B]),
        );

        $this->buildViolation(self::MESSAGE)
            ->setParameter('{{ choices }}', '"a", "b"')
            ->assertRaised();
    }

    public function testChoicesUseLabelsForHumanReadableEnum(): void
    {
        $this->validator->validate('nope', new EnumType(FixtureLabeledEnum::class));

        $this->buildViolation(self::MESSAGE)
            ->setParameter('{{ choices }}', '"one", "two", "three"')
            ->assertRaised();
    }

    public function testInvalidEnumClassRaisesConstraintDefinitionException(): void
    {
        /** @var class-string<\BackedEnum> $notAnEnum -- deliberately wrong to exercise the guard */
        $notAnEnum = \stdClass::class;

        $this->expectException(ConstraintDefinitionException::class);

        $this->validator->validate('x', new EnumType($notAnEnum));
    }

    public function testWrongConstraintTypeRaisesUnexpectedTypeException(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate('x', new NotBlank());
    }

    protected function createValidator(): ConstraintValidatorInterface
    {
        return new EnumTypeValidator();
    }
}
```

- [ ] **Step 3: Run the test — verify it fails**

Run: `make php.unit c='--filter EnumTypeValidatorTest'`
Expected: FAIL — `Class "Erpify\Shared\Infrastructure\Validator\EnumType" not found` (and `EnumTypeValidator`).

- [ ] **Step 4: Create the `EnumType` constraint**

`api/src/Shared/Infrastructure/Validator/EnumType.php`:

```php
<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Validator;

use Attribute;
use BackedEnum;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * Asserts that a value is a case of the given backed enum, optionally restricted
 * to a subset of cases. Behaves like any other property constraint and fires
 * during entity/DTO validation. Validates the hydrated enum instance (or null
 * when allowNull); it does not coerce raw scalars.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class EnumType extends Constraint
{
    public string $message = 'The value you selected is not a valid choice.';

    /**
     * @param class-string<BackedEnum> $enumClass
     * @param list<BackedEnum>         $cases     optional subset; empty means the whole enum is allowed
     */
    #[HasNamedArguments]
    public function __construct(
        public string $enumClass,
        public bool $allowNull = false,
        public array $cases = [],
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
```

- [ ] **Step 5: Create the `EnumTypeValidator`**

`api/src/Shared/Infrastructure/Validator/EnumTypeValidator.php`:

```php
<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Validator;

use BackedEnum;
use Erpify\Shared\Domain\Enum\Abstraction\HumanReadableIntEnumInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\ConstraintDefinitionException;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class EnumTypeValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof EnumType) {
            throw new UnexpectedTypeException($constraint, EnumType::class);
        }

        if (!is_a($constraint->enumClass, BackedEnum::class, true)) {
            throw new ConstraintDefinitionException(\sprintf(
                'The "enumClass" option of the %s constraint must be a backed enum, "%s" given.',
                $constraint::class,
                $constraint->enumClass,
            ));
        }

        if ($constraint->allowNull && null === $value) {
            return;
        }

        $isAllowedCase = is_a($value, $constraint->enumClass)
            && ([] === $constraint->cases || \in_array($value, $constraint->cases, true));

        if ($isAllowedCase) {
            return;
        }

        $this->context
            ->buildViolation($constraint->message)
            ->setParameter('{{ choices }}', $this->formatChoices($constraint))
            ->addViolation();
    }

    private function formatChoices(EnumType $constraint): string
    {
        if ([] !== $constraint->cases) {
            return $this->formatValues($this->labelsFromCases($constraint->cases));
        }

        $enumClass = $constraint->enumClass;

        if (is_a($enumClass, HumanReadableIntEnumInterface::class, true)) {
            return $this->formatValues($this->compact($enumClass::getLabels()));
        }

        return $this->formatValues(\array_map(
            static fn (BackedEnum $case): string => (string) $case->value,
            $enumClass::cases(),
        ));
    }

    /**
     * @param list<BackedEnum> $cases
     *
     * @return list<string>
     */
    private function labelsFromCases(array $cases): array
    {
        $labels = [];

        foreach ($cases as $case) {
            $labels[] = $case instanceof HumanReadableIntEnumInterface
                ? ($case->getLabel() ?? (string) $case->value)
                : (string) $case->value;
        }

        return $labels;
    }

    /**
     * @param array<int, string|null> $labels
     *
     * @return list<string>
     */
    private function compact(array $labels): array
    {
        return \array_values(\array_filter($labels, static fn (?string $label): bool => null !== $label));
    }
}
```

- [ ] **Step 6: Run the test — verify it passes**

Run: `make php.unit c='--filter EnumTypeValidatorTest'`
Expected: PASS (10 tests / OK).

- [ ] **Step 7: Static analysis on the new files**

Run: `make php.stan` then `make php.psalm`
Expected: no errors reported for the new `Validator/` files or the test. Fix anything reported before continuing (both must be clean — they narrow types differently).

- [ ] **Step 8: Commit (checkpoint — confirm per Commit policy)**

```bash
git add api/src/Shared/Infrastructure/Validator/ \
        api/tests/Unit/Shared/Infrastructure/Validator/
git commit -m "feat(shared): add EnumType validation constraint"
```

---

## Task 2: Finish `BankAccount` + migration

**Files:**
- Modify: `api/src/Backoffice/BankAccount/Domain/Entity/BankAccount.php`
- Modify: `api/migrations/2026/Version20260602120000.php:19`

- [ ] **Step 1: Add the import and fix the attributes in `BankAccount.php`**

Add this `use` line in the import block (alphabetical order — after `Erpify\Shared\Domain\Enum\Currency;`, before the `Symfony\…` group):

```php
use Erpify\Shared\Infrastructure\Validator\EnumType;
```

Replace the malformed/over-wrapped class attribute:

```php
#[UniqueEntity(
    fields: ['iban'],
    message: 'This IBAN is already in use.'),
]
```

with the single correct form:

```php
#[UniqueEntity(fields: ['iban'], message: 'This IBAN is already in use.')]
```

Restore the explicit join column on the `$bank` property (re-adds the `nullable: false` the working tree had silently dropped, matching `#[Assert\NotNull]` and the migration's `bank_id UUID NOT NULL`). Change:

```php
    #[ORM\ManyToOne(targetEntity: Bank::class)]
    #[ORM\JoinColumn]
    #[Assert\NotNull]
    private Bank $bank;
```

to:

```php
    #[ORM\ManyToOne(targetEntity: Bank::class)]
    #[ORM\JoinColumn(name: 'bank_id', referencedColumnName: 'id', nullable: false)]
    #[Assert\NotNull]
    private Bank $bank;
```

Leave the existing `#[EnumType(Currency::class)]` and `#[EnumType(BankAccountStatus::class)]` on the `currency`/`status` properties as-is — they now resolve to the new constraint.

- [ ] **Step 2: Edit the branch-only migration `INT` → `SMALLINT`**

In `api/migrations/2026/Version20260602120000.php` line 19, inside the `CREATE TABLE bank_account (...)` SQL, change `status INT NOT NULL` to `status SMALLINT NOT NULL`. Leave every other column and the `down()` (`DROP TABLE`) untouched. (This migration is unmerged/branch-only, so in-place editing is allowed.)

- [ ] **Step 3: Rebuild the dev DB from migrations and validate the mapping**

The dev DB currently has `status` as `integer`; re-apply the edited CREATE migration by resetting (destructive to the **dev** DB only — drops, migrates, reloads fixtures):

Run: `make db.reset`
Then: `make db.validate`
Expected: `[OK] The mapping files are correct.` and `[OK] The database schema is in sync with the mapping files.`

- [ ] **Step 4: Confirm there is no remaining schema drift**

Run: `make db.diff`
Expected: `No changes detected in your mapping information.` (If instead it generates a migration file, the entity and migration disagree — delete the generated file, reconcile, and re-run.)

- [ ] **Step 5: Static analysis on the entity**

Run: `make php.stan` then `make php.psalm`
Expected: no errors for `BankAccount.php`. Fix anything reported.

- [ ] **Step 6: Commit (checkpoint — confirm per Commit policy)**

```bash
git add api/src/Backoffice/BankAccount/Domain/Entity/BankAccount.php \
        api/migrations/2026/Version20260602120000.php
git commit -m "feat(backoffice): validate bank account enums and store status as smallint"
```

---

## Task 3: Seed a `BankAccount` via Hautelook fixture

**Files:**
- Create: `api/tests/DataFixtures/Fixtures/BankAccount.yaml`

- [ ] **Step 1: Create the fixture**

`api/tests/DataFixtures/Fixtures/BankAccount.yaml` (mirrors `Bank.yaml`'s
`__factory` style; passes only the four required `create()` args and lets
`currency`/`status` fall back to the `EUR`/`ACTIVE` defaults, so no enum is
expressed in YAML; `@bank_01` / `@bank_02` reference `Bank.yaml` in the same dir):

```yaml
Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount:
    bank_account_01:
        __factory:
            Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount::create:
                - '33333333-3333-7000-8000-000000000001'
                - '@bank_01'
                - 'Globex Corporation'
                - 'DE89370400440532013000'
    bank_account_02:
        __factory:
            Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount::create:
                - '33333333-3333-7000-8000-000000000002'
                - '@bank_02'
                - 'Initech LLC'
                - 'FR1420041010050500013M02606'
```

- [ ] **Step 2: Reload fixtures (purges + reloads all fixtures, incl. banks)**

Run: `make db.load.fixtures`
Expected: completes without error (e.g. `database is empty` purge notice then a
load summary). If it errors on an unresolved `@bank_01`, confirm `Bank.yaml` is in
the same `tests/DataFixtures/Fixtures` directory.

- [ ] **Step 3: Verify the rows actually persisted**

Run: `make sf c="dbal:run-sql \"SELECT holder_name, iban, currency, status FROM bank_account ORDER BY holder_name\""`
Expected: two rows —
`Globex Corporation | DE89370400440532013000 | EUR | 1` and
`Initech LLC | FR1420041010050500013M02606 | EUR | 1`
(`status = 1` is `BankAccountStatus::ACTIVE`; `currency = EUR`).

- [ ] **Step 4: Commit (checkpoint — confirm per Commit policy)**

```bash
git add api/tests/DataFixtures/Fixtures/BankAccount.yaml
git commit -m "test(backoffice): seed bank account fixtures"
```

---

## Task 4: Full quality + test sweep

**Files:** none (verification only).

- [ ] **Step 1: Run the full PHP quality sweep**

Run: `make php.quality`
Expected: green. Fixers (cs-fixer / psalm fixers) may rewrite files; if they do, re-stage and amend the relevant commit (or make a `style(...)` commit) per the Commit policy.

- [ ] **Step 2: Run the full unit suite**

Run: `make php.unit`
Expected: all green (including `EnumTypeValidatorTest`).

- [ ] **Step 3: Final commit only if the sweep changed files (checkpoint)**

```bash
git status            # verify branch + staged files first
git add -- <files the fixers touched>
git commit -m "style(api): apply linters after EnumType validator"
```

---

## Notes / guardrails

- **Error contract:** unchanged. `EnumType` emits standard `ConstraintViolation`s → existing RFC 9457 pipeline (422). No new marker interface, so `docs/api-error-contract.md` does **not** change (NFR26 untouched).
- **No raw-scalar coercion** is intentional (see spec "Decisions"). Do not add `tryFrom` handling.
- **`make` from repo root** so targets exec inside the correct container.
- If `make db.reset` is undesirable on your machine (shared dev data), an equivalent is `make db.drop && make db.migrate && make db.load.fixtures`.
