# ADR — Domain enum design and i18n separation

> **Status:** proposed (→ accepted once the contract swap lands) · **Date:** 2026-06-16 · **Scope:** every enum under `*/Domain/Enum/`; first migration `api/src/Backoffice/BankAccount` + `pwa` sync.
>
> Temporal context: the application is **not in production**, so the wire- and persistence-contract
> change carries no backward compatibility. The flip is still **atomic**: Doctrine's `enumType` binds
> the enum backing to the column bytes (see D6), so no hybrid phase is possible.

## Context

`BankAccountStatus` drags a generic "human-readable enum" infrastructure:

```php
// api/src/Shared/Domain/Enum/Abstraction/
HumanReadableIntEnumInterface   // getLabel()/getLabels()/fromLabel() contract
HumanReadableIntEnumTrait       // label resolution by reflection + attribute, cached
HumanReadableIntEnumValue       // #[…(label: 'active')] per case
```

The evidence contradicts the name. The only domain consumer is `BankAccount::getStatusLabel()`, which
serializes the "label" `'active'` under the `status` key; and the PWA (`BankAccountsTable.tsx`)
**already** reimplements its presentation (`STATUS_VARIANT` + title-casing). In other words: the
domain label never reaches the user as human text — it reaches the front as a **wire code**
(`'active'`) that the front re-maps, and in a Spanish ERP it produces *"Active"*, not *"Activa"*. The
one piece that justifies the whole `HumanReadable*` apparatus does not even fulfil its name. Of the
trait's 7 methods only 2 are used; `fromLabel`/`fromLabelOrFail`/`getKeysFromValues`/`getValues`/
`getValuesNotIn` are dead code. `Currency` (`enum Currency: string { case EUR = 'EUR'; }`) is already
the correct anaemic pattern that this ADR generalizes.

The root problem is not the trait or the reflection: it is **presentation leaking into the domain**.

## Decision

### D1 — A domain enum is pure identity

A domain enum represents stable business identity, not text. Forbidden inside the enum: readable
labels, UI formatting, i18n strings, label resolution by reflection.

### D2 — The wire contract is `->value`

The enum `value` **is** the public contract, in `SCREAMING_SNAKE_CASE`:

```php
enum BankAccountStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case CLOSED = 'CLOSED';
}
```

It coincides with `->name`, removes upper/lower-case ambiguity, and avoids implicit transformation in
the front. The `value` is immutable: changing it breaks the contract (just like renumbering an int).
*Discarded alternative:* `->name` directly — free, but it couples the public API to how the PHP
identifier is spelled; an explicit `value` decouples contract from identifier.

### D3 — The API serializes `->value`, never labels

The serializer exposes `$status->value` (Symfony emits `->value` for every `BackedEnum`). Never
`getLabel()` or derived getters. Output: `{ "status": "ACTIVE" }`.

### D4 — Presentation lives outside the domain (generalized anti-regression rule)

Readable text is the presentation layer's responsibility, **keyed by `->value`**:

- **PWA** → a presentation-layer `Record<Status, label>` map (and UI mapping: badges, colors). A real
  i18n dictionary (`t(\`bankAccountStatus.${status}\`)`) slots in behind the same seam when locales land.
- **Backend that must localize** (PDF, email, exports) → an i18n catalog in
  `Application`/`Infrastructure`, **never** back in the enum. Identity is the key; translation is a
  presentation adapter, whether it lives in the PWA or in an API adapter.

This rule is **not enum-only**: it closes the full regression vector. No `Domain/` type (enum, Value
Object, entity) and no `Application/` DTO/mapper may carry display text, formatting, or localization
"to simplify the front". The day a VO has `format()` or a mapper drags a label, it is the same mistake
under another name.

### D5 — Backing per aggregate (not global)

Mirrors the repo's per-aggregate persistence strategy
([`bank-bankaccount-modeling.md`](./bank-bankaccount-modeling.md)):

- **Default: string-backed**, `value == wire code`. Self-describing DB, resilient to adding cases, no
  machinery.
- **Exception: int/`smallint`-backed** ONLY in *hot-path / high-cardinality* aggregates under real
  volume, write, and indexing pressure (not an abstract roadmap). The roadmap names the candidates
  —*stock movements*, *automatic ledger entries* of the Finance Layer— but none is shipped or near
  term, so the exception exists in the model without dominating the default. An `int`-backed enum
  exposes its wire code via a string `value` only if it needs to decouple it from the number.

### D6 — Business rules inside the enum, presentation outside

The dividing line is not "anaemic vs rich", it is **display-text-OUT vs business-rules-IN**. Allowed
in the enum: predicates and invariants (`isTerminal()`, `canTransitionTo()`), state transitions.
Forbidden: formatting, localization, labels.

## Consequences

**Positive:** separation of concerns aligned with DDD/hexagonal; single source of truth for i18n;
simpler domain; zero reflection; explicit, stable API contract.

**Negative:** an API contract change that requires a coordinated front sync in the same PR; a
`smallint → text` data migration; convenience backend helpers are lost (they were dead code).

## Migration strategy (atomic flip, not strangler)

Doctrine's `enumType` binds the enum backing to the column: a string-backed enum over a `smallint`
column throws `ValueError` on the first hydration. **There is no model coexistence**; the swap is
indivisible. Commit sequence within the single PR:

1. **Contract swap (one commit, indivisible):** enum → string-backed; `#[ORM\Column(type: Types::TEXT)]`;
   serializer → `->value` (delete `getStatusLabel()`); delete `HumanReadableIntEnum{Interface,Trait}` +
   `HumanReadableIntEnumValue`; simplify `EnumTypeValidator` (no `HumanReadable*` branches, format by
   `->value`); hand-written migration (not `make db.diff`):

   ```sql
   ALTER TABLE bank_account ALTER COLUMN status TYPE text
     USING CASE status WHEN 1 THEN 'ACTIVE' WHEN 2 THEN 'INACTIVE' WHEN 3 THEN 'CLOSED'
                       ELSE NULL END;
   ```

   `down()` is the inverse, with an `ELSE` that **fails loud** (an unexpected value must not silently
   degrade to `NULL`). Column type `text`, not `varchar(n)`: in PostgreSQL the storage is identical and
   the `n` only adds a check and a future `ALTER` risk — the enum is the single source of cardinality.
   The ORM mapping uses `Types::TEXT` to match (a bare `Types::STRING` would map to `varchar(255)` and
   drift from the column).

2. **PWA sync (same PR):** `STATUS_VARIANT` keys to `SCREAMING_SNAKE`; TS union uppercased; the
   title-casing replaced by a presentation-layer `Record<Status, label>` keyed by the wire value
   (display copy; localization deferred behind the same seam per D4).

3. **Guardrail:** an arch-test that forbids `getLabel`/`HumanReadable*` attributes in `*/Domain/`
   enums; contractual API/PWA assertions.

## Result

Domain enums = pure identity. API = explicit contract (`->value`). PWA = single source of i18n. DB =
store of stable codes. Validator = a simple membership check. The "human readable enum" category
disappears from the system, and rule D4 keeps it from reappearing as a VO or a mapper.
