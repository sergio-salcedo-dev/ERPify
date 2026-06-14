# Read-side projection lifecycle

How long a *read-side projection policy* (an "enricher") stays the right tool, and the exact point at
which it must be replaced by an explicit or materialized read model. Complements the cross-context
read-model rules in [`database.md`](database.md) (which govern *where* foreign read data may come
from) and the per-aggregate persistence strategy in
[`../adr/bank-bankaccount-modeling.md`](../adr/bank-bankaccount-modeling.md) (which governs *how* a
materialized read side is built).

## Pattern: projection policy

A projection policy resolves a derived value and attaches it to a read result, without owning a model
of its own. **One derivation logic; one or more output adapters whose shape differs by consumer:**

- **materialized enrichment** — hangs the value on the read-projection field of a hydrated aggregate
  (a list page, a detail load);
- **scalar lookup** — returns the value to a consumer that has no aggregate (an event publisher
  composing a wire payload from primitives).

Same source of truth, two consumption shapes — *not* a fork. The adapters MUST share one derivation
path; duplicating the derivation logic is forbidden.

Canonical example: `Backoffice/Bank/Application/BankAccountCountEnricher` resolves `accountCount` via
the `BankAccountCounter` read-only port and exposes both shapes (`enrichAll` / `enrich` materialized,
`countFor` scalar).

## Invariants — a projection policy is valid ONLY while ALL hold simultaneously

1. **Low, homogeneous cardinality.** It decorates with one derived value (or a few tightly cohesive
   ones). Several heterogeneous derived attributes turn the aggregate into a projection carrier — a
   DTO disguised as an entity.
2. **No derived attribute participates in the query.** The value is display-only. The moment it must
   filter / order / group / paginate, it belongs *in* the query (keyset / `WHERE`): post-fetch
   decoration cannot paginate on a value it learns only after the page boundary is chosen.
3. **Read-time-fresh consistency is acceptable to the consumer.** Best-effort, recomputable per read;
   no temporal-stability or snapshot SLA.
4. **Single-aggregate scope.** Exactly one aggregate consumes the composition. A second consumer makes
   it a shared projection concern with its own data ownership.
5. **Bounded temporal fan-out (no latency amplification).** The derivation cost stays off the
   amplified critical path — it is not recomputed per item in a high-frequency request / event /
   stream path. High-frequency fan-out forces a materialized view, often before the other invariants
   trip.

## Promotion rule

Violating **any** invariant relocates the responsibility to a read model — **explicit** (a projection
DTO assembled by a composing query) or **materialized** (a denormalized column, an event-fed
projection). **No progressive degradation**: do not keep stapling fields or ports onto the policy past
a broken invariant.

Which materialization to choose is a per-aggregate persistence-strategy decision (state-oriented
default; event sourcing opt-in), presented to the user before modeling — see
[`../adr/bank-bankaccount-modeling.md`](../adr/bank-bankaccount-modeling.md).
