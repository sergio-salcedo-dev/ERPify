# ADR — Test-id naming contract: a published QA interface, decoupled from BEM

> **Status:** accepted · **Date:** 2026-07-20 · **Scope:** `pwa/` every `data-testid`; the guard `pwa/tests/data-testid-uniqueness.test.ts`; the "Test ID rules" section of `pwa/CLAUDE.md`.

## Context

`data-testid` values in the PWA follow a **BEM-flavoured** `block__element--modifier` syntax, codified in `pwa/CLAUDE.md` ("Test ID rules": *"Use BEM-flavoured prefixes matching the entity/surface"*). Inventory: **458 occurrences across 116 files** (308 static literals, 138 dynamic).

The construction is, today, **functionally sound** — there is no active defect:

- Collection rows/items carry the backend entity **UUID v7** as a suffix (`audit-timeline__row-${entry.id}`, `bank-accounts-cards__item-${account.id}`) → unique by construction.
- Reusable primitives never hardcode an id; the consumer passes `testId` / `testIdPrefix` (`${testIdPrefix}__title`, `${prefix}__edit-${id}`).
- One-active-at-a-time surfaces disambiguate by variant (`access-wall--${variant}` → `access-wall--suspended`).
- A CI guard (`data-testid-uniqueness.test.ts`) fails the build on any **static** literal that appears in more than one file, or twice in one file.
- No index-based testids and no id-less dynamic templates exist.

The problem is **conceptual, not behavioural**. BEM's native cardinality *permits reuse* — `.card__title` legitimately renders N times — which is the exact opposite of a testid's contract (**app-wide uniqueness**). Sharing the `__`/`--` grammar across a CSS-structure concern and a QA-identity concern invites the wrong instinct: copying a `className` into a `data-testid`, producing N duplicates that break Playwright strict-mode locators. The uniqueness guard exists *because of* this footgun and only inspects **static literals** — a dynamic template that forgets its id slips through. Concrete smell in the current tree: `navbar__mobile-toggle` (a `className` on a wrapper `div`) vs `navbar__mobile-menu-toggle` (a `data-testid` on the button) — two near-identical BEM-shaped names for different concerns.

This ADR makes the testid **contract** explicit and CI-enforced, so intent stops being folklore.

## Decisions (settled — agreed by both the test-architecture and architecture reviews)

- **D1 — A testid is a first-class, published QA interface.** It is app-wide-unique, **independent of DOM/CSS structure**, and stable across CSS/layout refactors. It addresses *what an element is for QA*, never *where it sits in the markup*.
  - *Discarded:* leaving the contract implicit — it drifts back to structural naming under the next refactor.

- **D2 — Entity identity in a testid is the API UUID v7 suffix**, the same aggregate-id vocabulary already used by `Routes` entity-scoped paths (`/backoffice/banks/${id}`): one aggregate id addresses the URL, the testid, and the correlation id. **Array-index suffixes are banned.**
  - *Discarded:* index/position suffixes — unstable under reorder, filter, or pagination.

- **D3 — Reusable primitives never name a surface.** They accept `testId` / `testIdPrefix`; the consumer owns `<surface>`, the primitive owns `<element>`. (Already the pattern for `DataTable`, `CopyButton`, `DateField`; now a rule, not a habit.)

- **D4 — Enforcement is a ratchet, not a one-off.** The uniqueness guard stays and is **strengthened** (see the dynamic-token rule in the open decision); the human-readable contract lives in `pwa/CLAUDE.md` "Test ID rules" (extend, do not fork). **No `testId()` codegen/builder** — two construction patterns do not clear Rule of Three.
  - *Discarded:* codegen-enforced testids — YAGNI/Rule-of-Three; revisit only if a third pattern appears.

## Decision — grammar and enforcement (resolved: Option A)

Both internal reviews and an external second opinion converge on **keeping the characters and codifying the contract**. Resolved after weighing that the invariant is **semantic, not lexical** — architectural guarantees must not hinge on punctuation.

- **D5 — Keep `<surface>__<element>[--<state>]`; `__` and `--` are lexical separators with NO BEM semantics.** The ADR and `pwa/CLAUDE.md` state this explicitly: `<surface>` names a semantic feature (not a CSS block) and `--<state>` denotes a QA variant (not a BEM modifier). No rename of the 458 sites.
  - *Discarded — Option B (hyphen-flat `surface-role-key`, ban `__`/`--`, codemod app + E2E specs together):* it prevents one class of mistake **syntactically** rather than **detecting** it — but at a 458-site + spec churn plus an external-QA migration, for a footgun that has not bitten. Fails measured-cost / Rule-of-Three *today*. **Deferred until empirical evidence shows the current contract insufficient** (e.g. repeated production regressions, or recurring duplicate-testid defects attributable to BEM-style copying) — an evidence trigger, not a preference.

- **D6 — Protection is layered — partly CI-enforced, partly by design; no single rule is the whole gate.**
  1. The static-uniqueness guard stays, never weakened (catches cross-file / in-file static duplicates).
  2. **Design rule — reusable primitives never own surface identity:** reusable/looped components receive `testId` / `testIdPrefix` from the consumer (D3) rather than defining fixed testids themselves. This prevents repeated components from emitting the same identifier N times — the *primary* protection here, and a design constraint rather than an automated check.
  3. **Lint — no presentation-derived testid:** a `data-testid` value must never be mechanically derived from a presentation-layer expression (`className`, `cn()`, `clsx()`, CSS-module bindings, Tailwind/style helpers). Broader than "no className" so it survives future styling approaches. This is *defense-in-depth* — it stops **derivation**, not a hand-typed copy of the same text (that path is covered by D3 + the guard).
  4. **Lint — dynamic templates carry a uniqueness token (heuristic):** a dynamic `data-testid` template must interpolate at least one **non-constant** expression and must **not** interpolate a positional index (`index`/`i`/`idx`/`n`). *Honest scope:* a heuristic backstop, **not** a uniqueness proof — real keyed ids interpolate `${area}` / `${chip.key}` / `${segment.value}`, not only `${*.id}`, so the rule cannot require an `id`-named token and cannot guarantee runtime uniqueness for non-UUID keys; that still rests on the author choosing a unique key + review.

## Decision — coverage (which elements carry a testid)

- **D7 — Presence: every interactive/CTA control and every element that is a QA assertion target carries a stable testid — proactively, and review-enforced.** D1–D6 govern *how* a testid is shaped; D7 governs *whether one exists*. An absent testid is not evidence one isn't needed: QA targets outlive the presence of a current test, so coverage is decided by the element's role (a CTA, a state surface, a list row), not by whether a test consumes it today. The malformation bans (D6.3–D6.4) reject a *bad* testid but cannot require a *present* one — there is no reliable syntactic signal for "this element is a QA target" — so presence rests on the same load-bearing review the heuristics already lean on.
  - *Discarded:* gating presence on a current test consumer — couples a durable QA address to a transient test and deletes the affordance the next test would need; the "internal / no-target-yet" exemption is exactly how missing and index-keyed ids creep back.

## Consequences

- The uniqueness invariant becomes an *intended contract* with a written rationale, not an accident guarded by one test.
- **Coverage (D7) is review-enforced, not lint-enforced** — a deliberate asymmetry: a *malformed* testid is syntactically detectable, a *missing* one is not.
- Two new lint rules (D6.3, D6.4) join `make pwa.quality`; the guard test is never weakened. **No id churn.**
- **Accepted residual:** `--<state>` (e.g. `access-wall--suspended`) reads exactly like a BEM modifier even though D5 disclaims BEM semantics — a coherence wrinkle we accept as the price of not renaming. Harmless: one variant mounts at a time and the value stays app-wide-unique.
- **Accepted residual:** the dynamic-token lint (D6.4) is heuristic and cannot prove runtime uniqueness for non-UUID keys — D3 and review stay load-bearing.

## System Invariant

**A `data-testid` is an app-wide-unique, structure-independent QA address — CI-enforced, and never mechanically derived from a presentation-layer construct.**

## Discarded alternatives (global)

- **No ADR** — the uniqueness invariant is safe today, but intent stays folklore and re-drifts.
- **Full semantic rename of every id by hand** — massive blast radius, breaks external QA/Behat scripts; pure churn (Option B's codemod is the disciplined form of this, if chosen).
- **Aliasing/dual-emit two testids per element** — doubles the strict-mode surface and rots; a codemod removes the need.
