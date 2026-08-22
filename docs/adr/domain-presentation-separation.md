# ADR — Domain–presentation separation (no display text in the inner layers)

> **Status:** proposed (→ accepted on landing) · **Date:** 2026-06-16 · **Scope:** every `Domain/` type and every `Application/` DTO/mapper, across all bounded contexts.

## Context

[`domain-enums.md`](./domain-enums.md) removed presentation from one inner-layer type — the enum — and
named the general rule (its D4) but mechanized it for enums only. The same signature recurs at other
boundaries: a value object with `format()`, an entity with `getStatusLabel()`, an `Application` DTO or
mapper emitting a localized string "to simplify the front". Each is the same defect under a different
name — presentation (display text, formatting, i18n) leaking into a layer whose job is identity and
business meaning. The enum case (`BankAccountStatus`) was the first instance; this ADR promotes D4 to a
standing architecture rule and gives it a starter gate.

The root problem is not any one type — it is **display text living where identity belongs**.

## Decision

### DPS1 — No `Domain/` type carries display text

Enums, value objects, entities, domain services: none expose readable labels, UI formatting, i18n
strings, or label-resolution by reflection. Identity and business meaning only.

### DPS2 — No `Application/` DTO or mapper carries display text or localization

A mapper translates identity → DTO shape, never identity → human prose. A use case orchestrates; it
does not format copy. A DTO is a transport record, not a view model.

### DPS3 — Presentation/i18n lives in the presentation layer, keyed by the identity value

- **PWA** → a `Record<Key, label>` map / i18n dictionary keyed by the wire value.
- **Backend that must localize** (PDF, email, export) → a catalog in `Application`/`Infrastructure`
  keyed by the value, an explicit presentation adapter — **never** back in the `Domain` type.

### DPS4 — The dividing line is display-text-OUT vs business-rules-IN

Mirrors [`domain-enums.md`](./domain-enums.md) D6. **Allowed** inside the inner layers: predicates,
invariants, state transitions (`isTerminal()`, `canTransitionTo()`), identity accessors. **Banned:**
`format()`, `*Label()`, `humanReadable()`, `display*()`, `caption()`, any localization, and
`#[*Label*]` / `#[HumanReadable*]` metadata on a member.

### DPS5 — Split enforcement: a static gate for the name-visible half, review for the semantic half

`DomainPresentationSeparationGateTest` (`api/tests/Unit/Gate/`) scans:

- every `/Domain/` type — presentation **method names** (per DPS4) + `#[*Label*]`/`#[HumanReadable*]`
  **attributes** + reintroduction of a `HumanReadable*` **abstraction** (`implements`/`use`);
- every `/Application/` type — the **attributes** + the **abstraction** only.

It scans code only (`token_get_all` strips comments, docblocks, and string literals) and matches the
bare method name precisely, so `reformat`/`displayOrder`/`formatVersion` do not false-trip while
`getStatusLabel` does. Display text smuggled under a neutral name (a method returning a translated
string, a `t()`-style call) is invisible to a name scan and stays **review-only** — the same division
of labour as the sibling architecture gates (deptrac, bounded-context, error-contract).

**Discarded alternatives:**

- *A full AST/deptrac rule over Domain + Application* — higher coverage, but it cannot tell a localized
  string from any other string, so the false-positive rate is high and disproportionate to a still-rare
  smell. The name-scan gate plus review is the proportionate ratchet; revisit if recurrence proves it
  too weak.
- *Method-name scanning in `Application/` too* — deferred. `Application` legitimately formats
  non-display data (queries, identifiers) often enough that a name ban there is FP-prone; only the
  unambiguous attribute/abstraction vectors are gated in `Application`, the rest is review.

## Consequences

**Positive:** one stated rule instead of an enum-specific footnote; the regression vector is closed
across value objects, entities, and mappers; CI fails the moment the clearest reintroductions appear.

**Negative:** the semantic half (a localized string under a neutral name) still relies on review; the
gate is a name heuristic, not a proof.

## Relation to other records

Generalizes [`domain-enums.md`](./domain-enums.md) (D4) — the enum is the first instance, and the
former enum-only guardrail is subsumed by the generalized gate. Rule entry:
[`../rules/architecture.md`](../rules/architecture.md).

## Result

Display text has exactly one home — the presentation layer, keyed by identity. Inner-layer types stay
pure identity + business rules; the "human-readable X" category cannot reappear as an enum, a value
object, an entity accessor, or a mapper.
