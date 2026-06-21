# ADR — Per-view Resource DTOs: separating the HTTP wire contract from the domain

> **Status:** accepted · **Date:** 2026-06-21 · **Scope:** `api/src/Backoffice/{Bank,BankAccount}` + cross-cutting rule for any aggregate exposed over HTTP.

## Context

The wire contract used to be smeared over the domain entities. `Bank` and `BankAccount` carried
`#[Serializer\Groups]` and `GROUP_*` constants on their fields, the controllers serialized the entity
directly through the shared responders, and `Bank` even grew read-projection state that existed only for
the JSON — `$accountCount` + `assignAccountCount()`, plus a documented group hack so the write path would
not emit a stale `accountCount: 0`.

This contradicted the project's own rule, which simultaneously **allowed** passive `#[Groups]` on entities
and **forbade** exposing entities over HTTP ([`../rules/architecture.md`](../rules/architecture.md),
[`../project-context.md`](../project-context.md)). The cost: the domain was not pure, the HTTP contract was
invisible (you had to cross the controller plus the entity's attributes to know the shape of a response),
and a change to one view could leak into another through a shared group. With two aggregates already
affected — the Rule of Three nearly met — the pattern is fixed now, before a third arrives.

## Decisions

### D1 — The wire contract is a per-view Resource DTO, never the entity

Each exposed view serializes a dedicated DTO — flat `readonly` data, no logic — living in the context's
`Application/Resource/`. The domain entity never crosses the HTTP boundary; it may still flow internally
`Application → Infrastructure` up to the mapping step. A reviewer opens `BankDetailResource` and knows the
exact JSON of `GET /banks/{id}` without crossing the entity or any group.

*Discarded:* a per-context `Api/{Request,Response,Resource}` folder. The hexagonal three-layer model is
kept intact — DTOs are plain data in `Application/`, mappers are adapters in `Infrastructure/Http/`; no new
layer, no change to the `deptrac` layering. The input side is untouched: `CreateBankCommand` /
`UpdateBankCommand` and `SearchQuery` remain the request via `#[MapRequestPayload]` / `#[MapQueryString]`.

### D2 — DTOs by view, not an interface/inheritance hierarchy

There is one concrete DTO per view; serialization needs no polymorphism. The common fields (`id`, `name`,
`shortName`, timestamps) are duplicated across the views on purpose (Rule of Three) — no shared base or
value object is extracted yet.

*Discarded:* `interface BankResource` + implementors. It would add abstraction with no caller that varies
over the type, and ISP is already satisfied at the shape of each DTO.

### D3 — Bank has four views because POST ≠ PUT

`BankListResource` (6 keys, with `accountCount`, no URLs), `BankDetailResource` (8), `BankCreateResource`
(7, **with** logo/storedObject URLs, **no** `accountCount`), `BankUpdateResource` (5, no URLs, no count).
The code proves POST and PUT diverge: create serializes the URLs, update does not.

*Discarded:* a single `BankWriteResource`. Sharing it would force optional properties with per-endpoint
semantics and risk emitting `null` where a key is absent today (no `skip_null_values`), breaking
`update.feature` (which pins 5 keys and asserts `logoUrl`/`storedObjectUrl` *should not exist*).

### D4 — The account count rides a `BankWithAccountCount` wrapper, not the entity

`BankWithAccountCount` (`Application/`, `readonly`: `Bank $bank` + `int $accountCount`) is the transient
carrier from `Application` to `Infrastructure`. The `BankSearcher` returns `Page<BankWithAccountCount>` and
the `BankDetailFinder` returns one; the mapper consumes exactly that. `Bank::assignAccountCount()` is gone,
so the entity is never mutated for a read concern. `enrichAll`'s batching is preserved (no N+1 in list);
`countFor()` is kept because the realtime publisher `RefreshRealtimeOnBankChanged` depends on it — a
three-consumer seam. `BankCreateResource` / `BankUpdateResource` omit the `accountCount` key entirely
(read-side enrichment, foreign to the create/update contract).

*Discarded:* mutating the entity (couples a read projection into the aggregate) and a per-bank `countFor()`
call inside the mapper (reintroduces the N+1 the batch enricher exists to avoid).

### D5 — Mapping is an injectable Infrastructure service taking the URL ports

`BankResourceMapper` / `BankAccountResourceMapper` (`Infrastructure/Http/`) do entity(+count)+URLs → DTO.
They are services, not static factories on the DTO: the mapping needs infrastructure collaborators (the URL
generators), which would pollute a pure factory and hurt testability. They inject the **ports**
`MediaPublicUrlGenerator` and `StoredObjectPublicUrlGenerator` — the exact pair the old normalizer used.

*Discarded:* a static `fromEntity()` factory on the DTO (cannot reach the URL generators cleanly); and
injecting `ContentHashUrlGenerator` directly (the ports already wrap it and own the route names — dropping
to the concrete would duplicate that knowledge).

### D6 — `BankLogoUrlNormalizer` is deleted, not left dangling

URL synthesis (logo / storedObject) moves from serialization-time into the mapper. The old normalizer only
fired on `Bank` + `GROUP_READ_URLS`, which no longer happens once DTOs are serialized, so it is provably
dead: the class is removed **and** its `#[AutoconfigureTag('serializer.normalizer')]` service is
deregistered — no normalizer left registered against a contract nothing produces.

### D7 — Byte-stable wire, ATOM format pinned on the DTO

This is an internal refactor, not an API change: no field added, removed, or renamed in any response. The
mapper pre-formats `createdAt` / `updatedAt` to ATOM strings on the DTO, so the format the `Timestamped`
trait used to pin via `#[Serializer\Context]` survives without the entity being serialized. Nullable URL
fields stay typed nullable so the serializer still emits `null`. A unit gate (`BankResourceMapperTest`)
pins, per view, the exact ordered key set, the ATOM timestamp strings, and the synthesized URL strings (URL
ports stubbed to echo the content hash) — the one logic-bearing transformation. Coverage is scoped to the
mappers, not the logic-free DTOs.

### D8 — The contradictory governance rule is retired repo-wide

The "passive `#[Groups]` on entities" exception is removed and the per-view Resource DTO pattern stated as
the norm across every doc that asserted it: [`../rules/architecture.md`](../rules/architecture.md) (canonical
owner), [`../project-context.md`](../project-context.md), [`../../CLAUDE.md`](../../CLAUDE.md),
[`../../api/CLAUDE.md`](../../api/CLAUDE.md), [`../architecture-api.md`](../architecture-api.md),
[`bank-bankaccount-modeling.md`](bank-bankaccount-modeling.md) and
[`external-dependencies-in-domain.md`](external-dependencies-in-domain.md) (D4). Reconciling only some would
leave the docs split for/against the new pattern — worse than before. The `Vendor.PassiveMetadata` collector
in [`../../api/tools/deptrac/deptrac.yaml`](../../api/tools/deptrac/deptrac.yaml) still admits
`Symfony\Component\Serializer\Attribute` only for the dormant `#[Serializer\Context]` on `Timestamped`; the
documented target is to drop it once that pin lives on the DTO timestamps.

## Consequences

- `Bank.php` and `BankAccount.php` are pure domain: no `use Symfony\Component\Serializer\…`, no `#[Groups]`,
  no `GROUP_*`, no `accountCount`/`assignAccountCount()`. Domain tests need no serialization context.
- The only serializer attribute left inward is `Timestamped`'s `#[Serializer\Context]` (ATOM pin) — dormant
  while nothing serializes the entity.
- **Triggers to revisit:** (a) a third view or aggregate joins — re-evaluate extracting a shared base/VO
  (Rule of Three is now met); (b) `accountCount` needs to sort/filter/paginate, or read-time freshness stops
  being acceptable — promote it from a read-time enricher to a materialized read model (criteria in
  [`bank-bankaccount-modeling.md`](bank-bankaccount-modeling.md) and
  [`../rules/read-side-projections.md`](../rules/read-side-projections.md)); (c) IBAN/PII exposure review
  (out of scope here — the DTO reproduces today's exposure, adds none).
