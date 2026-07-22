# ADR — Cross-cutting RBAC authorization model: permission-as-value, tier policy, edge voter

> **Status:** accepted — implemented · **Date:** 2026-07-06 · **Scope:** the cross-cutting authorization model that **every future ERP+CRM entity** inherits to govern *who may perform which action*. Extends the auth/RBAC subsystem ([`auth-rbac-subsystem.md`](./auth-rbac-subsystem.md)) **without revoking it**; `Backoffice/Bank` + `Backoffice/BankAccount` are the first validation slice, and the two `Backoffice/Audit` read routes migrate onto the same grammar.

## Context

The auth foundation shipped (session firewall, framework-free `User` in `Backoffice/Identity`, a static `Role` enum with a single case `AUDIT_READER`) and Epic 3 landed. Authorization today is a **pure role-check**: `#[IsGranted('ROLE_AUDIT_READER')]` on the two audit read routes, decided by Symfony's built-in `RoleVoter`; there is no permission abstraction, no custom voter, no `role_hierarchy`. Every other `/api` route sits behind the `access_control` catch-all `^/api → IS_AUTHENTICATED_FULLY` — any authenticated user reaches every business route, including all `Bank`/`BankAccount` CRUD. There is **no per-resource, per-action granularity yet**.

This ADR designs that granularity as one cross-cutting model for the whole ERP+CRM (invoicing, inventory, treasury, contacts, opportunities, products, warehouses, purchase orders, …), with banks/accounts first. It deliberately keeps **authorization** (*may the subject perform this action?*) separate from **data visibility / row-level scope** (*over which subset of data?*), so the base model stays RBAC and does not drift toward ABAC. The model was hardened in a ratified brainstorm (external consult + push-back + stress test).

The load-bearing frozen invariant it must respect is **SI-5** (roles are external authorization policy; no `Application`/`Domain` code branches on a role): [`../../_bmad-output/planning-artifacts/arch-addendum-auth-rbac.md`](../../_bmad-output/planning-artifacts/arch-addendum-auth-rbac.md).

## Decisions

### D1 — A permission is a derived value `(resource, action)`, never an entity

A permission is the canonical string `"<resource>.<action>"` (`bank.read`, `bankAccount.changeStatus`, `auditTrail.read`) modelled as a small `final readonly` value object, **not** a first-class entity (`Permission { id, … }`) and **not** a database table. This is load-bearing for D8: keeping the permission a *value* means the static→configurable migration swaps only the policy store (code → DB), never the model or any downstream contract.

*Discarded:* a `Permission`/`role_permission` table now — solves an absent problem (permission editor, tenancy, hierarchies), and inverts the dependency so every context couples to a persistence-backed authorization aggregate. Promote to a store only when D8's trigger fires.

### D2 — A resource is a governable business object, never a route or a context

A resource is an independently governable business object — an aggregate root (`bank`, `bankAccount`), occasionally a read model (`auditTrail`). Its canonical key is owned and declared by the module. A resource is **never** a route, a controller, or a bounded context: routes are enforcement points, not the thing being governed.

### D3 — An action is a capability relative to a resource; CRUD is the seed vocabulary, not the model

Actions are relative to their resource. The seed vocabulary is the tier verbs `read` / `write` / `delete` (`write` covers create+update; finer `create`≠`update` is added per-permission only when a caller needs it — YAGNI). Beyond CRUD, first-class domain operations are actions in their own right (`bank.close`, `bankAccount.changeStatus`, `invoice.approve`). CRUD is a starting alphabet, not a ceiling.

### D4 — Resolution is one custom permission voter over a `AuthorizationPolicy` port; role-checks are retired from business routes

A single `PermissionVoter` (the first custom voter in the codebase) supports attribute strings shaped `<resource>.<action>` and delegates the decision to a `AuthorizationPolicy` port. It coexists cleanly with Symfony's built-in voters, which keep serving the authentication tier (`IS_AUTHENTICATED_FULLY`): each voter abstains on the other's attribute shape. Enforcement stays at the HTTP edge via `#[IsGranted('bank.read')]`; 403/401 flow through the existing RFC 9457 pipeline unchanged (no new marker). **SI-5 is preserved and widened**: neither `Application` nor `Domain` ever branches on a role *or* a permission — the voter runs before the controller and the application code never learns either.

The two audit routes migrate `#[IsGranted('ROLE_AUDIT_READER')]` → `#[IsGranted('auditTrail.read')]` so the whole system speaks one grammar; `AUDIT_READER` survives as a specialized role that grants that permission.

*Supersedes* the sibling ADR's D4 YAGNI deferral of a custom voter: with banks+accounts × {read, write, delete, domain-ops} the Rule of Three is met, so the voter is now the justified abstraction rather than a `RoleVoter` clone. *Discarded:* a hand-rolled `JsonResponse` in the controller — forbidden by the error pipeline (`php.lint.error-contract`).

### D5 — Declaration seam: per-module permission constants + one central, declarative, tier-based policy

Each module declares its own permission constants co-located in its edge (`Backoffice/Bank/Infrastructure/.../BankPermission::READ = 'bank.read'`), referenced by that module's `#[IsGranted]`. A single central `StaticAuthorizationPolicy` implements the `AuthorizationPolicy` port with two declarative maps and one opt-out set. **This tier-verb shape is the policy chosen today — an implementation of the port, not part of the authorization model; a future system could swap it for a different `AuthorizationPolicy` without touching the model:**

- `tierVerbs`: `VIEWER → {read}`, `EDITOR → {read, write}`, `MANAGER → {read, write, delete}`, `ADMIN → {*}` — **resource-agnostic**, so a new CRUD-only resource is auto-covered with **zero policy edits**.
- `explicitGrants`: `permission → {roles}` for domain operations and sensitive reads only (`bank.close → {MANAGER, ADMIN}`, `auditTrail.read → {AUDIT_READER, ADMIN}`).
- `tierOptOut`: resources excluded from tier auto-grant (e.g. `auditTrail`, so a generic `VIEWER` cannot read the trail — sensitive access is explicit-only). The exclusion binds **every** role, `ADMIN` included: `ADMIN` is a tier holding `{*}`, not a superuser clause resolving ahead of the maps, so opting a resource out is what makes a governance or separation-of-duties surface fail-**closed** until a row names its grantee. Without that property, every capability invented later on such a resource would be granted to `ADMIN` by a default nobody chose.

Resolution: split the permission into `(resource, action)`; grant iff (`resource ∉ tierOptOut` and `action` ∈ any of the subject's tier verbs), or a role is listed in `explicitGrants[permission]`. Adding a resource is therefore additive: constants + `#[IsGranted]`, plus (only for domain-ops/sensitive reads) one `explicitGrants` line — **never** a change to the voter, the value, or the port.

*Discarded:* an explicit per-role permission list (`VIEWER: [bank.read, bankAccount.read, …]`) — dead-simple and maximally auditable, but every new resource edits N tier rows; the tier-verb map buys the strongest form of the extension goal below at the cost of a `(resource, action)` split that stays pure data. *Discarded:* a runtime registry/discovery mechanism — no second consumer justifies it (Rule of Three).

*Form — constants class, not an enum:* the per-module vocabularies (`BankPermission`, later `BankAccountPermission`) are `final class … public const string`, a deliberate carve-out from the repo's *enums-over-string-constants for closed sets* rule. A permission's value type is already the `Permission` VO (D1); the module constant is only the DRY authoring string, consumed verbatim by `#[IsGranted(…)]`, `PermissionVoter::supports(string)`, and `Permission::fromString(string)` — a string end to end, never held, matched, or `cases()`-iterated as a type. A backed enum is a legal attribute expression (`BankPermission::READ->value`) but every call site would immediately unwrap to `->value`, buying no consumed type while adding that ceremony and a forget-the-`->value` footgun. Same shape as the passive-`#[ORM]`-in-Domain exception: an argued deviation, revisited only if a real iterating consumer appears (e.g. enumerating a resource's actions to seed explicit rows).

### D6 — Placement: start in `Backoffice/Identity/Infrastructure/Security` behind neutral interfaces; promote later without redesign

The authorization model's artefacts — the `Permission` value, the `AuthorizationPolicy` port, its `StaticAuthorizationPolicy` implementation, and the `PermissionVoter` — are **packaged today** inside `Backoffice/Identity/Infrastructure/Security` (their current implementation location, not their conceptual home: the `Permission` value and the port belong to the authorization *model*; only the packaging is Infrastructure). This is deptrac-legal and mirrors the existing `ActorContextFactory` seam. The contracts are **neutral from day one**: the port speaks *permissions*, *roles-as-bare-tokens*, and *decisions* — **never** `User`, `Role`, or `SecurityUser`. The voter strips the `ROLE_` prefix at the edge (keeping SI-5's one-directional mapping) before consulting the policy. When a second clearly-transversal consumer appears (a protected CLI, API keys, `Frontoffice` reusing the model), promotion to `Shared/Authorization` is **re-packaging and composition, not a model or API redesign**.

*Discarded:* a top-level `Access`/`Authorization` context or a `Shared/Authorization` module **now** — a god-module every context couples to before a second consumer exists; Rule of Three.

### D7 — Subject is adopted as vocabulary only; no `AuthorizationSubject` type today

The subject of an authorization is the *bearer of attributable authority* (User/Role today; API client, service account, scheduled job tomorrow). This is adopted as **architectural vocabulary**, kept conceptually distinct from *identity* (authentication) and from the audit `ActorContext`. No `AuthorizationSubject` type is materialized: under edge-only enforcement the subject *is* the Symfony token, and one subject kind gives a class nothing but indirection. The existing `ActorType` (`USER | API_KEY | SYSTEM | ANONYMOUS`) already reserves the non-human future; the type is justified only when a second subject kind forces different code.

### D8 — Static now, shaped to become configurable without a redesign

Roles and the policy maps are code today (a deploy changes them). Because a permission is a value (D1) and resolution goes through the `AuthorizationPolicy` port (D4/D5), moving the maps to a database store later swaps only the port's adapter (`StaticAuthorizationPolicy` → `DbAuthorizationPolicy`) — the model, the voter, the `#[IsGranted]` call sites, and the port contract are untouched. Configurable RBAC is a store swap, not a rewrite.

### D9 — The row-level door stays open for free, unbuilt

Symfony's voter already receives the subject of `#[IsGranted('bank.read', subject: $bank)]`; the `PermissionVoter` accepts it and today **ignores** it. That is the entire minimal seam that lets row-level data scope be introduced later without redesigning the RBAC core — no `own/team/tenant`, ownership, sharing, or hierarchies are designed here. **Co-requisite:** issue **#437** (the keyset cursor fingerprint omits the base predicate) becomes a real privilege-scope-widening bypass the moment two routes over the same root entity get different access levels (the banks/accounts nested-vs-collection pair). Its fix (a base-query/route discriminant in the fingerprint) ships with this slice, before the divergent-access pair exists.

## Acceptance criterion (OCP) and the two tripwires

**The authorization subsystem shall be open for extension and closed for modification: adding a new resource or action may only require declaring new permissions and updating the authorization policy — never modifying the resolution algorithm, the `Permission` model, or the `AuthorizationPolicy` port contract.**

Operatively: **the authorization core is intentionally finite — new business capabilities extend the permission vocabulary and the policy, never the authorization algorithm.**

Two objective boundaries keep the model from drifting into ABAC; crossing either is a **new ADR, a different capability**:

1. **The policy stays policy, not mechanism** — whether coded or persisted it holds only data (`tier → [verbs]`, `permission → [roles]`, `resource` opt-out sets) and **no algorithm**. The first `if (…) then grant`, closure, or expression turns the policy into a policy *engine* — a different capability.
2. **The `subject:` gate stays unevaluated** — the day the voter reads the subject to decide, the model has entered data-scoping/ABAC.

*Executable gate:* both tripwires are enforced by architecture tests, not review notes. `StaticAuthorizationPolicyIsDataOnlyTest` tokenises the policy maps and rejects any executable token, keeping the policy data-not-mechanism (tripwire 1). `AuthorizationCoreIsClosedForModificationTest` drives a resource the policy has never listed through the unmodified voter+policy, asserting it is governed by tier verbs with zero policy rows, and freezes the `AuthorizationPolicy` port to its single `permits` method — so adding a resource cannot change the core (the OCP criterion). `PermissionVoterDoesNotEvaluateSubjectTest` reads the voter's source and asserts `subject:` is never read to decide (tripwire 2). A new CRUD-only resource costs zero policy rows; crossing either tripwire fails CI.
