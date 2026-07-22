# ADR — Authorization model boundaries: what will never be a `Role`

> **Status:** accepted · **Date:** 2026-07-22 · **Scope:** `api/src/Shared/Access`, `api/src/Iam/Identity/Infrastructure/Security`, `api/src/Organization` — and any future platform/tenancy work.

## Context

Authorization is `Permission = (resource, action)` resolved by a static policy over a flat role vocabulary
(`Shared/Access/Domain/Role`: `VIEWER`, `EDITOR`, `MANAGER`, `ADMIN`, `AUDIT_READER`). Business contexts couple to it
by string literal in `#[IsGranted('bank.read')]`; no context imports the core's types. Model and mechanism are
recorded in [`rbac-authorization-model.md`](rbac-authorization-model.md).

The product is single-tenant today — one organization per installation, `Membership.user_id` is `UNIQUE`, and a
successful login stamps exactly one organization on the session. The intended direction is multi-tenant SaaS:
several organizations, users belonging to more than one, billing, ownership transfer, and a support console
operated by people who are not members of any customer organization.

That direction produces recurring pressure to answer each new question by adding a role — `SUPER_ADMIN` for
platform staff, `OWNER` for whoever governs an organization. Issue #505 (any `ADMIN` can mint another `ADMIN`)
is where the pressure first surfaced, and the attempted fix is what exposed the shape of the problem: adding a
`users.grant-admin` permission would have been a **no-op**, because a vocabulary in which `ADMIN` means *every
action* cannot express *"`ADMIN` may not"*. The defect was never a missing role; it was a question being asked in
a language that cannot hold it.

This ADR introduces neither concept. It records what they will **not** be, so that the cheap shortcut is already
closed when the pressure returns — which is the only moment at which this document has any value.

## D1 — A platform operator is a principal, never a `Role`

A person operating the SaaS platform (support, migrations, incident response) is **not** a member of a customer
organization, and will never be represented by a member of the `Role` enum.

The repo already forbids it structurally, which is why this is a boundary and not a preference: `Membership.user_id`
is `UNIQUE` — one user belongs to exactly one organization, enforced by the database — and `SessionMintingSuccessListener`
resolves a single `organizationId` onto the session at login. A platform operator fits neither. Modelling them as a
role would force a member of every organization to exist for someone who is a member of none, and the wildcard
tier would hand them every tenant permission by default rather than by decision.

When platform operations arrive, access to a customer organization is **explicit, reason-bearing, time-bounded
impersonation**, audited as such — not a login carrying a tenant role.

*Discarded:* `Role::SUPER_ADMIN`. Cheap to write and immediately wrong: it makes the platform's authority
indistinguishable from a tenant's own, inside the tenant's own compliance record.

*Discarded:* Symfony's `switch_user`. It is the wrong primitive precisely because it succeeds — the request
becomes the impersonated user, so the trail attributes the operator's actions to the customer. That is
manufactured evidence in a record with a five-year retention floor.

**Consequence to design before any impersonation code:** `audit_log` attributes one actor
(`ActorContextFactory` seals it during `onFlush`). Impersonation needs two — the acting platform principal and the
tenant identity acted as — because collapsing them either forges authorship or drops the operator's accountability.
Both are worse than the column that avoids them.

## D2 — Ownership belongs to the membership, never to a global `Role`

Governing an organization (who may administer it, billing, subscription, transfer of ownership) is a property of
the **relationship between a user and an organization**, not of the user. When the domain requires it, it will be
modelled on `Membership` — as a membership type or an ownership flag alongside its roles — never as `Role::OWNER`.

Two reasons, in order of weight. First, ownership is inherently organization-scoped: a user who is an owner of one
organization is not thereby an owner of another, and a global role cannot express that distinction at all — it
would be a latent bug the day a second organization exists. Second, `Role` is vocabulary **shared with the PWA**
(`@/context/shared/access/domain/Role`); a global `OWNER` would oblige the client to reason about ownership, while
membership metadata stays server-side.

Adding `OWNER` above `ADMIN` also does not work mechanically in the current policy, for the same reason
`users.grant-admin` did not: `ADMIN` holds the `{*}` tier, so an `OWNER` tier would be its synonym unless `ADMIN`
were demoted. Ranking roles is not how this model separates governance from operation — the
[tier opt-out](rbac-authorization-model.md) is, and it binds every role including `ADMIN`.

*Discarded:* `Role::OWNER` as the next rung of the ladder. It reads naturally and buys nothing the opt-out does not
already give, while making the org-scoped concept globally scoped.

*Discarded:* a separate `Ownership` aggregate. There is one relationship to carry the fact and `Membership` already
is it; a second aggregate would need reverse-integrity rules against the first.

**Trigger.** Ownership is introduced when a real organizational actor exists who should operate the ERP but should
**not** govern who administers it — not when the first billing or subscription screen ships. A governance *feature*
is gateable with one explicit grant; a governance *role* is only justified once the actor sets genuinely diverge.
Until that person can be named, `OWNER` is a synonym for `ADMIN` with more ceremony, and `ADMIN` remains the highest
organization role.

**Known interaction:** roles are authoritative on `User` (#549). Genuinely organization-scoped ownership argues for
`Membership` as the role authority, so acting on this decision may reopen that one. That is a reason to record the
link, not a reason to pre-empt either.

## What this ADR does not decide

Whether `Owner` or platform operators are ever built; when multi-tenancy lands; how role authority migrates; and
whether `ADMIN` should retain `auditTrail.read` (a separation-of-duties question, deliberately left open). Those are
open questions with their own triggers. This document constrains only the *shape* of the answers.
