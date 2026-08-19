# ADR — Authorization model boundaries: what will never be a `Role`, and who may read the trail

> **Status:** accepted · **Date:** 2026-07-23 · **Scope:** `api/src/Shared/Access`, `api/src/Iam/Identity/Infrastructure/Security`, `api/src/Backoffice/Audit`, `api/src/Organization` — and any future platform/tenancy work.

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
platform staff, `OWNER` for whoever governs an organization. Issue #505 (any `ADMIN` can mint another `ADMIN`) is
where the pressure first surfaced, and it is worth stating precisely what it did and did not expose. A
permission-shaped answer is **not** a no-op: `TIER_OPT_OUT` lists `users`, so `grantedByTier()` returns `false` for
every `users.*` permission *before* the `ADMIN => ['*']` wildcard is consulted, and each one is therefore deniable
to `ADMIN` by a single data row. `users.grantAdmin` is that row. What the episode exposed is that a **role** was
the wrong instrument: a rung ranked above `ADMIN` cannot express *"`ADMIN` may not"*, because the wildcard makes
the higher rung its synonym. The defect was never a missing role.

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
manufactured evidence in the compliance record.

**Consequence to design before any impersonation code:** `audit_log` attributes one actor
(`ActorContextFactory` seals it during `onFlush`). Impersonation needs two — the acting platform principal and the
tenant identity acted as — because collapsing them either forges authorship or drops the operator's accountability.
Both are worse than the column that avoids them.

## D2 — Ownership belongs to the membership, never to a global `Role`

Governing an organization (who may administer it, billing, subscription, transfer of ownership) is a property of
the **relationship between a user and an organization**, not of the user. When the domain requires it, it will be
modelled on `Membership` — as a membership type or an ownership flag on the membership itself — never as `Role::OWNER`.

Two reasons, in order of weight. First, ownership is inherently organization-scoped: a user who is an owner of one
organization is not thereby an owner of another, and a global role cannot express that distinction at all — it
would be a latent bug the day a second organization exists. Second, `Role` is vocabulary **shared with the PWA**
(`@/context/shared/access/domain/Role`); a global `OWNER` would oblige the client to reason about ownership, while
membership metadata stays server-side.

Adding `OWNER` above `ADMIN` also does not work mechanically in the current policy: `ADMIN` holds the `{*}` tier,
so an `OWNER` tier would be its synonym unless `ADMIN` were demoted. Ranking roles is not how this model separates
governance from operation — the [tier opt-out](rbac-authorization-model.md) is, and it binds every role including
`ADMIN`. That asymmetry is the whole point: a *permission* on an opted-out resource can express "`ADMIN` may not",
where a higher *rung* cannot.

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

## D3 — `ADMIN` keeps `auditTrail.read`; separation of duties is a revisit trigger, not a default

The `auditTrail.read => [AUDIT_READER, ADMIN]` row in `StaticAuthorizationPolicy::EXPLICIT_GRANTS` stays: an
organization administrator may read the regulatory trail that records their own actions. This is a product and
compliance decision, not a mechanism one — the grant is one line of data whichever way it reads — and it is
recorded here because an *undocumented* default is the audit finding, not the access itself.

**Why the access is kept.** No organizational actor exists today who audits without operating. Withdrawing the
grant would not produce separation of duties: `users.invite` and `users.changeRoles` are themselves ADMIN-only,
so the administrator still decides who becomes the auditor. What it *would* produce is a standing operating
cost — at least one live `AUDIT_READER` per organization — and a bootstrap window in which nobody can read the
trail at all, because `CreateInitialAdministratorCommand` seeds exactly one identity holding exactly one role.
That window is incident response, which is when the trail matters most.

**What bounds the risk, stated as it exists.** The grant reaches two read-only routes
(`AuditTimelineSearchController`, `AuditEventDetailController`); no write, export or delete path sits behind it.
Every authorized read is written back as its own `AUDIT_TRAIL_READ` entry at `SECURITY` level, synchronously on
`kernel.response` before the response is sent (`AuditTrailReadAuditListener`), naming the route and — on the
detail route — the id of the event read. Erasure never deletes trail rows: it rewrites `actor_id` to one stable
pseudonym across all the subject's rows, redacts `ip`/`user_agent` and raises `actor_erased`, so the sequence of
actions stays correlatable while the link to the person is severed
([`regulatory-audit-trail.md`](regulatory-audit-trail.md) D6).

**The retention bound is narrower than the trail as a whole.** The five-year floor covers `change` rows only
(`AuditRetentionPolicy::COMPLIANCE_RETENTION_FLOOR`). `security` rows — which is what every row named above is:
`AUDIT_TRAIL_READ` and `USER_ROLES_CHANGED` — carry a privacy *ceiling* instead, 365 days by default, and are
deleted by the scheduled pruner. **The two erasure-evidence actions are the exception**: `GDPR_SUBJECT_ERASED`
and `GDPR_ERASURE_EXECUTED` are exempt from the prune (`AuditErasureEvidence`), because evidence may not
expire before the thing it attests — the `dek_keystore` tombstone is eternal and the reconciler anti-joins the
two with no date bound. So a year after the fact the
pseudonymised `change` rows survive four more years while the record of who *read* them has
aged out, and the record of who pseudonymised them survives indefinitely — carrying that administrator's `ip`
and `user_agent` with it on the HTTP path. That asymmetry is a property of the current retention policy, not of this decision, and it is the
first thing to re-examine if the trail is ever asked to answer an assessor about access rather than about data.

**What is deliberately not claimed.** The trail is **not tamper-evident**. No hash chain, signature or checksum
column exists in any migration; append-only is a property of the mutation paths, not a cryptographic guarantee —
which is precisely what that ADR's D5 files as a revisit trigger rather than as a shipped control. Holding
`ADMIN` confers no database credentials, so this widens nothing about what the decision costs; it bounds what
may be asserted to an assessor.

**Revisit trigger.** The policy is revisited if a customer requires contractual separation of duties. Keeping the
access is not an architectural commitment — no role, entity or boundary encodes it, so the decision can be
retaken without reopening the model. There is no per-installation configuration for it today, and this ADR does
not claim one.

*Discarded:* restrict the trail to `AUDIT_READER`. It buys the appearance of separation of duties while the
administrator still appoints the auditor, and pays for it with the bootstrap window and a role to keep alive per
organization. It becomes the right answer the moment the trigger above fires — and only then.

*Discarded:* emergency access with a declared reason and an expiry, or making an administrator's read raise the
record's level or fire an alert. Both are designs rather than rows, and neither is worth building before an
actor exists who would consume the signal.

**Consequence, closed alongside this decision.** Keeping the trail readable by the role that operates the system
makes the trail's attribution worth guarding on the write side. `users.erase` is ADMIN-only and pseudonymises the
subject's whole attribution irreversibly, and its only guards were self-erasure and "keep ≥1 active `ADMIN`" —
neither of which stops an administrator from erasing a peer. Erasure now refuses any subject still carrying
`ADMIN`, so the demotion has to happen first, as its own act.

**What that refusal buys, stated exactly.** An extra authorization step *and* an attributable record. The demotion
is audited: `ChangeUserRoles` writes an explicit `USER_ROLES_CHANGED` row at `security` level naming the subject in
the resource columns and carrying both role sets in metadata — no credential, no email, no aggregate diff. So
promote-act-demote-erase no longer reads, in `audit_log`, as one unexplained act by the erasing administrator.

That row was written once and withdrawn, because naming a person in `resource_id` while erasure rewrote only
`actor_id` leaves the subject's real id beside their own pseudonym whenever someone demotes *themselves* — the
reversible crosswalk [`audit-activity-log.md`](audit-activity-log.md) D4's policy exists to prevent. That ownership
question is settled: erasure now anonymises **both** axes inside one transaction, the resource axis driven by the
owning context's declared eraser, with a registry gate and a reconciler as the preventive and detective halves. The
trigger recorded here has fired, and the refusal is the traceability control it was written to be.

**What is still not claimed.** The record ages out before the data it describes — a `USER_ROLES_CHANGED` row is
`security`, so the 365-day ceiling above governs it while the pseudonymised `change` rows survive four more years.
And the row makes a role change *visible*, never *impossible*: the ≥1-admin guard refuses only when no **other**
active administrator would remain, so one principal may still demote peers one at a time until they are the only
one left. That is a separate invariant, and this ADR does not claim it.

It subsumes the ≥1-admin invariant on the erasure path (the last active administrator necessarily carries the
role), which now binds only on the transitions that can shrink the set.

**Those transitions take their two `identity_user` locks in one fixed order, and the order is the invariant.**
Each of them locks the target row and the active-admin set the guard reads, and the set is locked `ORDER BY id`
— so an ACTIVE administrator target (carrying `ADMIN` is not enough; the set filters on status too) is a member
of a set the transition would otherwise be holding one row of, out of order. Two concurrent transitions on
administrators X and Y (`X.id < Y.id`) then each hold one and wait for the other: a textbook ABBA, surfacing as
`40P01` and a 503 with nothing in the code to explain it. The set is therefore taken first, through
`ActiveAdministratorDirectory::lockActiveAdministrators()` — a named operation rather than a side effect of the
guard, because the guard is **conditional** (`ChangeUserRoles` asks it only when the change demotes an active
administrator) and an order that holds only when a branch happens to run is not an order.

*Scope, because the guarantee is conditional and stating it absolutely would make the residual unfalsifiable:*
the cycle is closed **between transitions that take this lock**. `AcceptInvitation` promotes an `INVITED`
identity carrying `ADMIN` to `ACTIVE` without it, so a set member can appear under a transition whose set
statement has already run — and the guard's later call is then a second acquisition rather than a re-read of
one already held. Left open deliberately: that use case holds its row across a password KDF, and holding the
whole administrator set across a KDF trades a rare deadlock for a routine one.

*Cost, both halves of it:* one round trip per transition, and — because the lock is unconditional, first, and
held to commit — role and status changes now serialise organization-wide instead of running in parallel when
their targets differ. Both operations are rare enough for that to be the cheaper side of the trade, but the
concurrency cost is the larger one and is not a latency figure.

Nothing behavioural goes red if `ORDER BY id` is deleted — the verdict is an unordered containment test, the
`pg_locks` mode is relation-level, and the statement count is unchanged — so a source gate
(`AdministratorSetLockStatementGateTest`) is what holds the clause, and its own docblock states what a textual
check can and cannot prove.

**Known gap, pre-existing and unchanged:** the *sole* active administrator has no path to erasure at all —
demotion is refused by the ≥1-admin invariant, self-erasure by its own guard, and no peer exists to erase them.
Satisfying their right to erasure requires onboarding a second administrator first. This decision neither
creates nor closes that; naming it here keeps the "demote, then erase" instruction honest.

*Discarded:* dual control — a second administrator approves the erasure. It is the control that actually
separates the duty, and it needs an approval aggregate with state, expiry and notification; disproportionate
before an organization exists with two administrators who are not the same person's accounts.

*Discarded:* auditing `User` through the write-capture CDC to record the demotion. It would put `password_hash`
in the trail, which is why the aggregate opted out in the first place. The demotion is recorded by an explicit,
field-selective row instead.

## What this ADR does not decide

Whether `Owner` or platform operators are ever built; when multi-tenancy lands; and how role authority migrates.
Those are open questions with their own triggers. This document constrains only the *shape* of the answers.
