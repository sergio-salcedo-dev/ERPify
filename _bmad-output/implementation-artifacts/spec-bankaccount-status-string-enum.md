---
status: in-progress
slug: bankaccount-status-string-enum
title: "Commit 1 — atomic contract swap of BankAccountStatus to a string-backed identity enum"
created: 2026-06-16
---

# Commit 1 — BankAccountStatus → string-backed identity enum (atomic swap)

Contract record for the indivisible flip described in [`docs/adr/domain-enums.md`](../../docs/adr/domain-enums.md).
The app is **not in production** → no backward compat. Doctrine `enumType` binds the enum backing to
the column bytes, so enum + mapping + serializer + DB column + validator + the now-dead `HumanReadable*`
stack all fall in **one commit**. No int/string coexistence, no dual serialization path, no compat layer,
no validator fallback. PWA sync is a **separate later commit** — `pwa/` is untouched here.

## Wire contract (after)

`{ "status": "ACTIVE" }` — `->value` in `SCREAMING_SNAKE_CASE`, emitted by Symfony for the `BackedEnum`.
No labels anywhere in the domain.

## Tasks

1. **`api/src/Backoffice/BankAccount/Domain/Enum/BankAccountStatus.php`** — string-backed identity enum:
   `enum BankAccountStatus: string { case ACTIVE='ACTIVE'; case INACTIVE='INACTIVE'; case CLOSED='CLOSED'; }`.
   Drop `implements HumanReadableIntEnumInterface`, `use HumanReadableIntEnumTrait`, the three
   `#[HumanReadableIntEnumValue(...)]` attributes, and the three corresponding imports.

2. **`api/src/Backoffice/BankAccount/Domain/Entity/BankAccount.php`**:
   - `#[ORM\Column(type: Types::STRING, enumType: BankAccountStatus::class)]` (was `SMALLINT`); keep
     `#[EnumType(BankAccountStatus::class)]`.
   - Delete `getStatusLabel()` and its docblock. Expose `->value` under the `status` key. Cleanest:
     add `#[Groups([self::GROUP_READ])]` + `#[SerializedName('status')]` to the existing `getStatus()`
     (Symfony serializes a `BackedEnum` accessor as its `->value`). Exactly one accessor emits `status`.

3. **Migration** `api/migrations/2026/Version20260616120000.php` (hand-written, NOT `make db.diff`;
   transactional default — string `addSql`, not `isTransactional()=>false`):
   - `up()`: `ALTER TABLE bank_account ALTER COLUMN status TYPE text USING CASE status WHEN 1 THEN 'ACTIVE'
     WHEN 2 THEN 'INACTIVE' WHEN 3 THEN 'CLOSED' END;` — column type `text`, not `varchar(n)`.
   - `down()`: inverse `CASE` back to `smallint` with a `USING ... ::smallint`; the `ELSE` must **fail
     loud** on an unexpected value (raise, never silently `NULL`).

4. **`api/src/Shared/Infrastructure/Validator/EnumTypeValidator.php`** — remove every `HumanReadable*`
   branch: drop the import, the `is_a(..., HumanReadableIntEnumInterface)` branch in `formatChoices()`,
   and the whole `labelsFromCases()` method. `formatChoices()` formats by `->value` for both the
   subset (`$constraint->cases`) and the full-enum path.

5. **Delete the `HumanReadable*` stack** (grep for dangling imports first — done, full set below):
   - `src/Shared/Domain/Enum/Abstraction/HumanReadableIntEnumInterface.php`
   - `src/Shared/Domain/Enum/Abstraction/HumanReadableIntEnumTrait.php`
   - `src/Shared/Domain/Enum/Attribute/HumanReadableIntEnumValue.php`
   - `tests/Unit/Shared/Domain/Enum/Abstraction/HumanReadableIntEnumTraitTest.php`
   - `tests/Unit/Shared/Domain/Enum/Abstraction/Fixtures/FullyLabeledIntEnum.php`
   - `tests/Unit/Shared/Domain/Enum/Abstraction/Fixtures/MissingLabelIntEnum.php`
   - Remove the now-empty `Abstraction/` and `Attribute/` dirs (`Enum/Currency.php` stays).

6. **Validator test + fixtures**:
   - `tests/Unit/Shared/Infrastructure/Validator/EnumTypeValidatorTest.php` — drop the two
     `human-readable*` data-provider cases; repoint the `instance of another enum` case to a second
     plain string enum.
   - `tests/Unit/Shared/Infrastructure/Validator/Fixtures/FixtureLabeledEnum.php` — delete; replace with
     `FixtureOtherStringEnum` (plain string-backed) for the `instance of another enum` case.

7. **`features/backoffice/bank_account/search.feature`** (contract test of THIS commit) — flip status
   assertions to `SCREAMING_SNAKE` (`"inactive"`→`"INACTIVE"`, `"active"`→`"ACTIVE"`) and reword the
   scenario title that calls `status` a "human-readable label" (it's now the wire identity value).

## Delta from the original intent (discovered in investigation — for checkpoint)

Two **Behat support files** import the deleted `HumanReadableIntEnumInterface` and were NOT in the
original change list; leaving them dangling fails autoload and Behat won't boot. Both must change here:

- **`tests/Behat/Support/PostProcess/JsonToolTrait.php`** — drop the import and the
  `instanceof HumanReadableIntEnumInterface → getLabel()` branch. The existing `BackedEnum → ->value`
  branch already yields `"ACTIVE"`, which is exactly the new contract. Pure subtraction.
- **`tests/Behat/Support/Tool/TypeHint/EnumValueResolver.php`** — its sole reason for existing was
  label→case resolution for `HumanReadable*` enums (now none). **Decision (checkpoint, Option B):
  delete it** and drop it from the `TypeHintValueResolver` chain. The ADR makes `->value` the single
  explicit wire contract, so a dedicated enum resolver is redundant infrastructure over a rule now
  encoded in the type system: an enum-typed hint reaches `ValueObjectResolver`, which can't instantiate
  an enum and falls back to the raw wire value — which already equals the enum identity. No current
  Behat method type-hints a backed enum, so the suite stays green. Consequence (accepted): a future
  enum-typed step resolves to its raw `->value` string, not an enum instance.
- **`tests/Unit/Behat/Support/Tool/TypeHint/TypeHintValueResolverTest.php`** — the resolver's own unit
  test drove the now-deleted enum branch. Enum-resolution cases and the non-scalar-enum rejection tests
  removed; surviving coverage (null literal, raw/builtin passthrough, value-object construction +
  throw-fallback, non-scalar date rejection) retained. Its int-label fixture chain is gone with the
  `HumanReadable*` deletion.

Unchanged (verified): `BankAccountTest` (asserts case identity only), `BankAccount.yaml` (uses
`!php/enum ::INACTIVE` case names), `BoundedContextGateTest` (FQCN only inside a heredoc string fixture).

## Gates (all green before commit)

`make php.stan` (each touched file) · `make db.migrate` · `make php.quality` (deptrac + error-contract +
bounded-context + cs-fixer + rector) · `make php.behat` · `make php.unit`. Backend security self-review
(reversible PII-free migration, no secrets in diff).

## Commit

`refactor(backoffice): swap BankAccountStatus to string-backed identity enum` — then STOP for checkpoint
before Commit 2 (PWA). Do NOT merge to main.
