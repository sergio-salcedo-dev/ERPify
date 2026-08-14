# ADR — Identity & Invitation lifecycle: multi-tenant-ready identity, invitation-first onboarding, session registry

> **Status:** accepted — implemented (tenancy operation deliberately deferred, D2) · **Date:** 2026-07-06 · **Scope:** promotes the shipped `Backoffice/Identity` into a top-level `Iam/` context (`Identity` + `Invitation` + `Session`) and a new `Organization/` context (`Organization` + `Membership`); adds the four identity/auth state machines — identity lifecycle, invitation, authentication lockout, session — behind two transversal invariants (pre-identity indistinguishability, token opacity). Extends the auth/RBAC subsystem ([`auth-rbac-subsystem.md`](./auth-rbac-subsystem.md)) and the RBAC authorization model ([`rbac-authorization-model.md`](./rbac-authorization-model.md)) **without revoking either**: the `Role`/permission grammar is the authorization plane and stays orthogonal to this identity plane. Domain input: the UX run decision-log (`ux-ERPify-2026-07-06` — four state machines + two invariants + the three-moments rule) and its adversarial security review.

## Context

The auth foundation shipped: a stateful session firewall, a framework-free `User` in `Iam/Identity`, and CLI-only user creation (`organization:administrator:create`). There is no public onboarding, no invitation, no email verification, no password recovery, no account lifecycle, no lockout state, and no server-side session registry — a user is either created by CLI or absent, and a session is a bare Symfony session with no revocation surface. The UX run reframed access as a first-class public surface and ratified a domain backbone of **four orthogonal state machines** (identity, invitation, authentication, session), **two transversal invariants** (pre-identity indistinguishability; total token opacity) and the **three-moments rule** (`credentials → identity → admission → session`, never `credentials → session`). Its security review hardened the invariants into concrete backend contracts (constant-time, token-in-URL hygiene, CSRF + session regeneration, revoke-all-on-reset, stateless walls). This ADR fixes the backend that realizes that model. It keeps the **identity plane** (who you are, whether you are admitted) separate from the **authorization plane** (what you may do — RBAC, sibling ADR) and from the **tenancy plane** (org self-signup, tenant switching, cross-tenant data scope), which is modelled as ready but not operated (D2).

## Decisions

### D1 — Promote the identity subsystem to a top-level `Iam/` context and a sibling `Organization/` context

`auth-rbac-subsystem.md` D2 set an explicit promotion trigger: move `User` out of `Backoffice/Identity` "when IAM capabilities emerge (password reset, login attempts, sessions, …)". They now emerge at once, so it fires. `Backoffice/Identity` → **`Iam/Identity`** (the `User` aggregate + identity lifecycle + lockout state); new sibling modules **`Iam/Invitation`** and **`Iam/Session`**; a separate top-level **`Organization/`** context owns **`Organization`** and **`Membership`**. Identity, tenancy, and authorization are three planes with different reasons to change; collapsing them is the smell. Each module keeps the ERPify per-aggregate isolation — no object graph crosses a module boundary; cross-module references are by id (`private string $userId`, `organizationId`), deptrac-registered per module.

*Discarded:* keeping the subsystem under `Backoffice/` — accretes an entire IAM subsystem under a business-area context (a naming lie) and only defers the promotion churn until Frontoffice client login forces a larger move. *Discarded:* one `Iam/` umbrella that also holds `Organization`+`Membership` — conflates tenancy with identity, so every future business context referencing an organization would couple to `Iam`.

**Sequencing note (coordinate with RBAC):** the RBAC slice packages its authorization core in `Backoffice/Identity/Infrastructure/Security` and its epic is already cut. Promotion moves that target — either this promotion lands first and RBAC targets `Iam/Identity/Infrastructure/Security`, or RBAC ships to `Backoffice/Identity` and the promotion moves both. The order is a cut-time decision the addendum flags.

### D2 — Multi-tenant-ready domain, tenancy operation deferred to its own ADR

The domain is born knowing tenancy: every aggregate carries an `organizationId` (an id, never a typed relation), and `Membership(userId, organizationId, roles)` is the authoritative user↔organization link — so a user is never "global" and roles are always org-scoped. There is exactly **one organization per installation today**, bootstrapped by CLI (D3). Deferred to a dedicated tenancy ADR: public org self-signup/provisioning, tenant switching / workspace selection (no tenant surface in the UI), the lifecycle of multiple organizations per installation, and **enforcement** of cross-tenant row-level scope — the RBAC `subject:` gate stays unevaluated (consistent with `rbac-authorization-model.md` D9). Building strictly single-tenant and migrating later is disproportionately painful (ownership, URLs, invitations, audit, RBAC, APIs); baking the id-level seam now is near-free and forward-compatible.

*Discarded:* strictly single-tenant with no organization concept — the later single→multi migration is the exact pain this avoids. *Discarded:* full multi-tenant operation now — out of scope; self-signup is a deferred extension point, and modelling readiness ≠ operating tenancy.

### D3 — Two parallel state machines force `User` to be born `INVITED`

The identity machine (`INVITED → ACTIVE ↔ SUSPENDED ↔ DEACTIVATED`, plus the terminal `INVITED → REVOKED`; no `PENDING`) and the invitation machine (`CREATED → SENT → …`) are **live simultaneously** (the accept surface projects `Invitation=SENT ∧ Identity=INVITED`). For both to hold state at once — and for roles to be assigned before acceptance (a user is never active without already belonging to the org) — the `User` and its `Membership` must exist in `INVITED` before the token is accepted. Consequences: `User.status` is an enum with those cases — `REVOKED` was added later, when invitation revocation gained a console surface: withdrawing the delivery record has to withdraw the identity it provisioned, or the register keeps a person at `INVITED`, carrying the roles that were being granted, for as long as the installation lives. It is deliberately distinct from `DEACTIVATED` (a withdrawn invitation is not a retired member) and deliberately a state rather than a deletion (the invitation already put that person's id in the reproducible log and in the membership that admitted them, so removing the row would strand references whose erasure has a declared owner); `HashedPassword` is **nullable until `ACTIVE`** (`INVITED` = identity + membership provisioned, credential not yet set); assigned roles live on `Membership`, leaving `Invitation` a pure token/delivery aggregate.

*Discarded:* creating the `User` only on acceptance (always-complete User) — cannot hold the pre-assigned membership/roles the model requires, nor the two machines in parallel.

### D4 — Admission is a `UserChecker` that makes the three-moments rule mechanical

The rule `credentials → identity → admission → session` (never `credentials → session`) is realized by Symfony's `UserCheckerInterface`, which runs between credential verification and token/session minting. `checkPreAuth` rejects `INVITED` and `REVOKED` **uniformly as pre-identity** (neither holds a password; the attempt is indistinguishable from "no such user", and answering a withdrawn invitation differently would report the outcome of somebody else's revocation decision to whoever holds the address). `checkPostAuth` — reached only once credentials are valid, i.e. identity is demonstrated — rejects `SUSPENDED`/`DEACTIVATED`/`LockedUntil>now` with a **post-identity account-status wall that mints no session**. The wall is **stateless**: rendered from the login POST response body, with no session, cookie, or resumable token. A session exists only if admission passes.

*Discarded:* minting the session then checking status — violates the three-moments rule and leaks a partial-auth artifact.

### D5 — `Invitation` aggregate; acceptance mints identity + first session under CSRF + session regeneration

`Iam/Invitation` owns `Invitation` (`organizationId`, `invitedUserId`, `tokenHash`, `expiresAt`, `status: CREATED→SENT→ACCEPTED|REVOKED|EXPIRED`). The raw token never persists — only its hash (D6). Acceptance is a **net-new POST outside the login firewall** that validates the token, flips `User INVITED→ACTIVE`, sets the password, marks `Invitation ACCEPTED`, **regenerates the session id** (anti-fixation on the privilege jump), and mints the first `Session`. As an unauthenticated state-changing POST that mints a session, it carries a same-origin `Origin` check (the `LoginOriginListener` pattern) **and** a CSRF token — it is the first consumer that wires the stateless double-submit token the sibling ADR left `wire-on-consumer`. Resend invalidates the prior token and issues a new one (in place, on the same row); revoke → `REVOKED`, in one transaction with the identity's own `INVITED → REVOKED`, so a pulled invitation cannot leave a live-looking member behind; TTL lapse → `EXPIRED`.

*Discarded:* carrying roles on the `Invitation` — roles belong to `Membership` (D3); the invitation is delivery only.

### D6 — A shared `SingleUseToken` building block in `Shared/Token`

Invitation acceptance and password reset need the identical mechanism: a high-entropy, single-use, TTL-bound, hashed-at-rest, constant-time-verified opaque token. Both consume one **`Shared/Token`** capability (`Domain/SingleUseToken` value + `Infrastructure/` CSPRNG generator + hasher) rather than two hand-rolled verifiers. This is security-critical code where a subtle divergence (a non-constant-time compare, a mis-applied TTL) is a vulnerability, so the "must not diverge" argument overrides the plain Rule-of-Three that would wait for a third consumer. `Shared/` is the placement because two modules (`Iam/Invitation`, `Iam/Identity`) consume it and cross-module code lives in `Shared/` — mirroring `Shared/Uuid`, `Shared/Clock`.

*Discarded:* two independent token verifiers extracted only at a third use — the divergence risk in security code is the cost the Rule-of-Three does not price.

### D7 — Authentication lockout is a persisted domain `LockedUntil`, complementary to the existing rate-limiter

`login_throttling` (Symfony's per-IP+email cache-backed rate-limiter) already defends against spray; it stays. The authentication machine's `LockedUntil(T)` is a **different, complementary** concept: an **observable, persisted** account lock on the identity, set after N failed attempts for a resolved identity, enforced at `checkPostAuth` as a post-identity "temporarily locked" wall, and **cleared by a successful reset** (D-b) or by TTL / successful login. The ephemeral throttler cannot carry observable account state, cannot be shown as a post-identity wall, and cannot be "cleared by reset"; the two are orthogonal and both required.

*Discarded:* collapsing to the throttler alone — it is not persistible, observable, post-identity, nor reset-clearable, so it cannot express the ratified machine.

### D8 — Server-side `Session` registry with a fail-closed admission gate; native storage, logical revocation

`Iam/Session` owns a framework-free `Session` aggregate (`id` UUID v7, `userId`, `organizationId`, `createdAt`, device label, ip, `status: Active|Revoked`, and an absolute non-null `expiresAt`) as the **single source of truth** of the session lifecycle. Symfony keeps its **native session storage**; the domain never learns the framework session id — Infrastructure stores the domain `SessionId` in the Symfony session bag (`iamSessionId`, an adapter detail, never part of the domain contract) and the per-request **Session Admission Gate** reads it back, loading the record and forcing logout unless `status=Active` **and** the session has not passed its `expiresAt`. The gate is **fail-closed and part of the auth TCB**: every authenticated request traverses it; if it cannot decide (store unavailable), the request does not proceed (D12). Logical revocation (marking `Revoked`) suffices precisely because the gate is the trust boundary — a revoked session is inert on its next request; physical payload deletion is left to the handler's GC. Two enforcement paths: credential-change de-authentication rides the firewall's `refreshUser`/`hasUserChanged` (the `Session` record is updated in step, for "my sessions" / audit coherence — complementary, not redundant); administrative revocation ("log out this device / all others", suspend/deactivate) uses the registry + gate. The comparison behind the first path is **ours, not Symfony's default**: `SecurityUser` implements `EquatableInterface`, whose sole consumer in the security stack is that hook, because the default reads the credential straight off the copy deserialised from the session — a copy that reaches the comparison through no user provider, so a stored hash `HashedPassword` refuses escapes from inside the firewall as a marker-less 500 on every request the cookie is sent with, where the provider's fail-closed guards only the row it loads. Owning it means owning what it guarantees, so each clause it compares is pinned by its own failing test: the credential (on which both replacement flows rest when they swallow a failed revoke), the role set (so a revocation bites on the next request, compared order-insensitively since column order is not a fact about the identity), and an unreadable credential, which is equal to nothing and takes the token with it. Reset revokes **all** sessions; an authenticated "my account" change revokes **all but the current**.

*Discarded:* unifying the aggregate with the storage backend (a custom `SessionHandler` over the table) — SRP: it fuses framework session mechanics (serialization, GC, locking) with a domain aggregate, concerns that change for different reasons. *Discarded:* `PdoSessionHandler`/Redis now — YAGNI on a single node; the domain is unchanged if a shared store is later needed, which is itself an explicit re-decision (below). *Discarded:* no registry (native sessions only) — cannot enumerate, distinguish "current", or revoke. *Discarded (II-7):* a persisted `Expired` status — expiry is a **temporal predicate** (`expiresAt <= now`) that the gate and the "my sessions" projection both apply, so persisted `status` carries only the actor-driven lifecycle (`Active→Revoked`); materializing `Expired` would need a writer (a write on the read path, or a scheduled sweeper) for a state redundant with `expiresAt`. Reintroduce `Expired` only if a real transition ever materializes it. *Discarded:* a policy-free port (`findById` in place of `findActiveById`, every caller applying `Session::isActive()` itself) — the **port method name is what makes the predicate impossible to omit**. A bare lookup demotes a TCB rule from enforced-by-construction to enforced-by-convention at every call site, where a future consumer that forgets it earns a silent authentication bypass that compiles and passes PHPStan, deptrac and the suite; and caducity is exactly the axis the aggregate does not guard (`revoke()` guards `status`, never expiry). `Iam/Invitation`'s pure `findById` is no counter-example: its accept flow is safe **by design**, not by luck — the emailed token is a selector-verifier, so a caller necessarily holds a secret and the only thing to do with one is `Invitation::verify()`, and that flow reaches the row through a separate `findByIdForUpdate()`. Neither lever exists here: a `SessionId` arrives from a cookie carrying no secret, and there is no second read method to carry the obligation. `findActiveById` is then the cheapest must-use encoding available — it obliges the caller to resolve admissibility without inventing a type to carry it.

**Forward-path clause:** stable for single-node deployments. If the operating model evolves to shared session storage (multi-node, blue/green, autoscaling), a **new ADR** reopens the `PdoSessionHandler`-plus-registry vs unified-handler trade-off — not an automatic drift to `PdoSessionHandler`.

### D9 — Password reset is uniform, revokes all sessions, and clears the lock

Forgot-password responds **uniformly for every identity state** (same status, same observable work, whether or not the account exists) and issues a `SingleUseToken` (D6). A successful reset sets the new password, **revokes all sessions** (not "the others" — reset follows credential compromise and starts from no current session, so a literal "all but current" could spare the attacker), **clears `LockedUntil`** (D-b, recovery bridges the lock), and if the identity is non-`ACTIVE` lands on the post-identity wall (D-c) granting nothing.

### D10 — Invariant 1 (pre-identity indistinguishability) is a timing + status + shape contract

Indistinguishability is not copy parity. Login walks the **same path** whether or not the account exists — always hashing a dummy password (the existing timing-equalised `UserProvider`, elevated to contract) — so latency does not leak. HTTP **status and response shape are uniform** across the four pre-identity cases {non-existent, wrong password, non-eligible identity (`INVITED`), withdrawn identity (`REVOKED`)}. Forgot-password uses an **invariant status** and identical observable work regardless of account existence or state. The rate-limiter must not break neutrality: a throttled response stays within the uniform pre-identity failure shape rather than surfacing a distinguishable "too many attempts".

### D11 — Invariant 2 (token opacity) is URL-hygiene plus one opaque message

The token is high-entropy, single-use, TTL-bound, hashed at rest (D6). The ratified `?token=` URL is kept but hardened: token screens send **`Referrer-Policy: no-referrer`** (overriding the global `strict-origin-when-cross-origin`) so the token does not ride the `Referer` to same-origin subrequests; the client **strips the token from the URL** after reading it (`history.replaceState`); the token is **redacted in access logs** (infra handoff). Every token death (used / revoked / expired / already-accepted / non-existent) collapses to a single opaque message — never the reason — and the accept screen never reveals the invitee email.

### D12 — Error contract: specificity graded by the trust level reached

Error specificity is a function of the trust level attained, not of the error kind: **pre-identity, everything is indistinguishable; post-identity, a response may be specific when it aids resolution without materially widening the attack surface.** The security boundary is the demonstration of identity — admission states are **deliberately observable** once identity is proven (not "enumerable"; the model protects against anonymous enumeration, not against a party who already controls valid credentials). Mapping, all through the RFC 9457 pipeline (never a hand-rolled body):

- **Pre-identity:** `unauthorized` (401, uniform for non-existent / wrong password / `INVITED` / `REVOKED`) and `invalid-token` (a single opaque marker for every token death).
- **Post-identity:** `account-locked` and `account-suspended` (403, specific — they carry a real next step); `DEACTIVATED` uses a **generic** response (no user-actionable step — semantic, not merely anti-enumeration).
- **Infrastructure:** when the admission gate cannot decide (store down/timeout), a distinct **operational** type (503-family) — never reusing an identity type, so an availability failure never projects as an identity outcome.

Adding the `invalid-token` marker and the account-status types updates [`../api-error-contract.md`](../api-error-contract.md) (NFR26).

### D13 — One acquisition order over the identity plane's three write tables, and the erasure is the member that conforms

Five write paths lock more than one of `iam_invitation`, `identity_user` and `identity_password_reset_token`
inside one transaction, and two pairs disagreed — an ABBA in each, surfacing as `40P01` and a 503 with nothing
in the code to explain it. The order is fixed at **`iam_invitation` → `identity_user` →
`identity_password_reset_token`**, and what fixes it is not a probability estimate about which race is likelier
but which path **cannot move**, which is a different reason per pair. `AcceptInvitation` arrives holding a
token and learns which identity the invitation concerns only from the row it has already locked — zero degrees
of freedom, so it pins the first pair and `RevokeInvitation` follows it. In the second pair every participant
knows both ids up front, so freedom selects nobody; what pins the reset paths is their own correctness — the
user row lock **is** the forgot path's supersede mutex (taken after the delete+insert, two concurrent requests
leave both tokens live) and the status re-read under it is what walls a completion an administrator has just
suspended. `FulfilIdentityErasure` is pinned by neither: it holds the subject's id before its transaction
opens. It therefore conforms to both, and never defines. *Discarded:* keeping the erasure's order and demanding
the other four yield — three of them cannot, and the fourth has no reason to. *Discarded:* a declared total
order over all seven resources the erasure touches — only two pairs close a cycle, and nothing can enforce a
total order (neither deptrac nor PHPStan sees statement order), so declaring one would be unfalsifiable.

The purge that leads still follows the administrator refusal — the **unlocked** one. In front of it, a
transaction about to abort would take write locks on `iam_invitation`, and the contention would fall on the
very pair the order exists to keep apart. The rollback hides the damage; the waiting does not.

**A second, locking refusal was added behind the purge (#655), and it is a deliberate narrowing of that
property rather than an abandonment of it.** The unlocked reading decides nothing: it takes no lock, so a
concurrent grant commits between it and the `DELETE` it guards and an administrator is erased having never
been demoted — with no `USER_ROLES_CHANGED` ahead of it in the trail, which is the entire reason the refusal
exists. Deciding requires holding at commit; holding requires locking the subject's `identity_user` row; and
that row cannot be acquired before `iam_invitation` without inverting the order the accept path is unable to
reverse. So the deciding guard goes behind the purge, and a doomed transaction takes invitation write locks
**only in the race case** — the ordinary refusal, where the subject was already an administrator when the
request arrived, is still served by the unlocked reading in front and still costs nothing. Keeping both is
what buys that, and both are pinned: deleting the locked one reds
`AdministratorErasureRaceFunctionalTest`, deleting the unlocked one reds `FulfilIdentityErasureTest`.

*Enforcement, because nothing in the pre-existing suite went red in either direction:* `ErasureLockOrderTest`
asserts the acquisition **sequence** over the use cases (a call-count assertion cannot see an order), and
`ErasureLockOrderFunctionalTest` measures it against the real adapters — a second connection asking, with
`FOR UPDATE NOWAIT`, what the transaction holds at each acquisition instant. Two instruments because neither
implies the other: an adapter that stopped locking leaves the first green, a reordering leaves a per-adapter
lock test green.

*Within `iam_invitation` the same reasoning applies to the two statements that lock a set rather than a row,
and they reach one direction by different routes:* `findSentByInvitedUserForUpdate` carries `ORDER BY id`,
while `deleteAllForInvitedUser` — a bulk `DELETE`, which admits no ordering — takes its rows through an ordered
locking read first. Without that read a revocation and an erasure of one invitee could walk shared rows in
opposite directions; the cost is one round trip on an operation that runs once per person. The lock read is
unfiltered by status because the delete is, and locking a narrower set than the one being deleted would hand
the difference back to the plan.

*What none of this proves:* **no `40P01` is ever observed.** The image carries neither `pcntl` nor the
procedural `pgsql` extension, so two transactions cannot be made to run *concurrently* inside a test process,
and every ordering claim here rests on acquisition instants and statement order instead.

That is a bound on concurrency, not on two-transaction tests, and the distinction became load-bearing with
#655: a contender only has to be *blocked* when the first transaction holds a lock, so where it holds none the
interleaving is deterministic on two connections in one process, and where it does hold one the contender's
`lock_timeout` turns "blocked" into an assertable outcome.
`AdministratorErasureRaceFunctionalTest` drives both. What stays out of reach is a genuine deadlock, which
needs each side to hold and wait at once.

### D14 — Lockout observability is detective-only, and its delivery channel is why it may carry nothing exercisable

D7's lock is observable in principle and was observed by nobody: `UserLocked` reached `event_store` and had no
consumer, so an administrator whose account is driven into the lock by anyone who knows their address learned
about it by failing to sign in. Two projections close that, and **both only report**: a `security` row in
`audit_log` per committed lockout, and a notice to the account owner.

**Neither adds an edge to the recovery graph, and that is derived rather than asserted.** The notice is
delivered by email — the same namespace an attacker consumes to trip the lock in the first place — so by D1 of
[`administrative-recovery-channel.md`](./administrative-recovery-channel.md) it may carry no token, selector,
unlock link or recovery-channel identifier. It states the fact and the order to recover in (*evict first,
rotate after*). The recovery graph therefore still has exactly the two edges #602 counted; what changes is that
the subject is told, not what they can do about it.

**The notice is emitted by a scheduler tick, never by the failing request**, and that placement is the security
property rather than a performance one. On the request, the tenth attempt against a resolved identity would
cost an SMTP round trip while an unknown address returned immediately — a timing oracle against D10. Off the
request the channel is closed by construction, not by a latency property a later listener could erode.

**The audit row is written post-commit and best-effort.** `event_store` already holds the durable `UserLocked`
in the lock's own transaction, so the row is a projection of a fact that survives without it; writing it inside
that transaction would let a failed `INSERT` roll back the brute-force defence, and silently, since PostgreSQL
answers `COMMIT` on an aborted transaction with a `ROLLBACK` tag. The consequences are accepted and named: the
projection is eventual, and concurrent threshold crossings produce one row per committed event rather than one
per logical transition.

Suppression is one notice per identity per day, persisted on the subject's own row (`lockout_notified_at`) so
it survives redeployment, is shared by every worker, and dies with the `DELETE` that erases the person —
minting no new person reference. It is stamped only behind a send that reported success: reserving the window
first would turn a mailer outage into a day of silence about a live lock.

*Discarded:* an administrative unlock — it presupposes a second reachable administrator, which is exactly what
the vulnerable installation does not have. *Discarded:* closing the unlocked-read race behind the counter to
make "exactly one row" true — it makes the denial cheaper without adding a recovery lever. *Discarded:* a
cache-backed suppression bucket — a 24-hour guarantee that dies on redeploy, does not hold between workers, and
has no seedable clock to test against.

## Load-bearing implementation challenges

- **Gate coverage is a security invariant, not a feature:** the Session Admission Gate must run inside the firewall's authenticated context on every request and fail closed; a route that authenticates but bypasses it re-opens revoked sessions.
- **Constant-time across identity states:** the dummy-hash path must also cover the credential-less states `INVITED` and `REVOKED` (no stored password) and forgot-password, or timing re-enumerates what the copy hides.
- **Session regeneration at two privilege jumps:** invitation-accept and reset both mint a session and must regenerate the id; neither goes through `json_login`.
- **Promotion churn:** moving shipped `Backoffice/Identity` code (`User`, `SecurityUser`, provider, authenticator, `SecurityActorContextFactory`) to `Iam/` touches `security.yaml`, deptrac, and coordinates with the in-flight RBAC placement.

## Implementation

Cut as its own epic with `bmad-create-epics-and-stories`; the scoped addendum ([`../../_bmad-output/planning-artifacts/arch-addendum-identity-invitation.md`](../../_bmad-output/planning-artifacts/arch-addendum-identity-invitation.md)) localizes each decision to a PR and gives the dependency DAG. Bootstrap of the first organization + first admin stays CLI-only; credentials are never seeded in migrations; dev/test via Alice fixtures.
