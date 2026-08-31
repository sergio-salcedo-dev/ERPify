# ADR — The administrative recovery channel: what an attacker must not consume, and what the vendor must not reproduce

> **Status:** accepted · **Date:** 2026-08-06 · **Scope:** `api/src/Iam/{Identity,Invitation,Session}`, `api/config/packages/rate_limiter.yaml`, installation provisioning — and any future credential, key or capability minted during setup.

## Context

The product ships as a single-tenant installation on a **VPS the vendor operates**; the customer has
**no shell**. Initial setup and updates are the vendor's, and everything else the customer must do
unaided. Bootstrap provisions exactly one administrator, so the installation's defining property is
that it has **one** — and that administrator is the victim of
[#602](https://github.com/sergio-salcedo-dev/ERPify/issues/602).

The persisted lockout is keyed by **email address** (10 consecutive failures → `PT15M`,
`api/src/Iam/Identity/Domain/Entity/User.php:64,66`), deliberately carrying no source dimension so it
catches a distributed credential-stuffing run the per-IP throttle misses (`User.php:58-63`). That same
property lets anyone who merely knows an administrator's address hold it closed: a lapsed lock zeroes
the counter (`User.php:283-289`), so a sustained blackout costs 40 login attempts plus 5 recovery
requests per hour. The binding limiter is `login_throttling` (5 per minute per address+IP,
`api/config/packages/security.yaml:31-32`), not the 120-per-minute anonymous budget — either way the
attacker's cost is a small multiple of one source's allowance, and the only prerequisite is an email
address, which is not a secret.

While the lock holds, the state has **two** exits, not three. A successful login cannot be one:
`UserChecker::checkPostAuth` throws before authentication succeeds (`UserChecker.php:74-76`), so
`ClearLockoutOnLoginSuccess` → `LoginAttemptRegistrar.php:78` is post-expiry cleanup, never an escape.
What remains is a completed reset (`CompletePasswordReset.php:106`) and a password change from a live
session (`ChangeMyPassword.php:113`) — and the owner here is signed out by definition. No status
transition touches the lock: `activate()` (`User.php:142`), `suspend()` (`:216`) and `deactivate()`
(`:231`) leave it alone, and no un-suspend transition exists at all.

For a **sole ADMIN** the operator path is closed too: erasure refuses any subject still holding `ADMIN`
(`FulfilIdentityErasure.php:135-137`), no CLI command changes roles, and none of the 14 `AsCommand`
classes unlocks an identity or resets a credential. What is left there is raw SQL — the absence of an
edge rather than one. (For a *non*-ADMIN identity two shipped commands compose an edge:
`identity:gdpr:erase-subject` hard-deletes the row, and `iam:invitation:create` re-mints on the freed
address. That path exists; it just cannot reach this ADR's victim.)

This record does not choose a mechanism. It fixes the two properties a recovery channel must have, so
that the mechanism stays replaceable and the criterion does not.

## D1 — I-1: the channel is identified in a namespace the attacker cannot consume

> **I-1.** Every installation must have at least one recovery channel identified through a namespace
> that an attacker knowing only the administrative identity cannot consume. **Corollary:** no surface
> other than the one delivering the identifier to the customer may disclose it.

The defect is not weak authentication and not custody. It is **channel coupling**: recovery is indexed
by the same namespace the attack is. Login, the lockout and `password_recovery_per_email` are keyed by
the address; `password_change_per_identity` by the identity, which a stolen session also holds. An
adversary never has to defeat a credential — draining the channel suffices.

The repo contains the counter-example, which is why this is a property and not an aspiration:
`token_action_per_selector` is consumed by the **selector** half of the presented `<id>.<secret>` link
and by nothing else (`rate_limiter.yaml:59-68`, `PasswordRecoveryThrottle::allowCompletion()`,
`InvitationAcceptThrottle::allowAccept()` — no email or identity dimension anywhere in either path),
and its exhaustion folds into the same opaque wall as a dead link.

**The corollary was red on the invitation half, and that is why it belongs in the invariant.**
At `eventVersion` 1 the six `Invitation` events carried the invitation id as the envelope's `aggregateId`
— and that id *is* the accept link's selector (`AcceptInvitation::splitToken()` reads
`<invitationId>.<secret>`) — so `DbalEventStore` wrote it to `event_store.aggregate_id` with no TTL. The
table's one sanctioned mutation does not reach it: `EventStoreSubjectAnonymiser` erases **by value** and is
called only with a person's id, and a selector is not one. `CreateInvitationCommand` additionally printed the
whole token, secret included, on every success. The reset half does satisfy the corollary: `PasswordResetToken` raises
`PasswordResetRequested($userId)`, so its envelope names the *user*, not the token row. Two token flows,
one convention each. Anything built under this ADR follows the reset convention; the invitation
divergence is a named residual, not a precedent.

*Amendment (2026-08-07, on closing the residual).* The invitation half now follows the reset convention:
the six events name the invited user as their `aggregateId`, their payload is empty, and their
`eventVersion` is `2` — so no new publication writes the selector to `event_store`. `aggregateType()`
stays `Iam.Invitation`, and `api/.persistent-transport-policy` reclassifies it `person` accordingly, since
that registry judges what the `aggregate_id` denotes rather than what the type is called. The CLI half is
narrowed rather than removed: `iam:invitation:create` and `iam:invitation:resend` print the raw token only
under `--show-token`, or unconditionally when the mailer refused the send — out-of-band hand-over is then
the invitee's only remaining route to the link. **Rows written before this are not migrated**, so the
corollary holds for what the system writes from here, not retroactively for what it already wrote.

*Discarded:* a source/IP dimension on the persisted lockout. It re-derives `login_throttling`, abandons
the persistent counter's only documented job (`User.php:58-63`), and the key would be
attacker-controlled and free to rotate.

*Discarded:* making recovery-throttle exhaustion visible. A per-account 429 is an existence oracle; the
silence at `rate_limiter.yaml:47-52` is deliberate and stays.

## D2 — I-2: the channel is not reproducible by anyone but the customer

> **I-2.** At no point in the channel's life may an actor other than the customer be able to
> reconstruct the knowledge needed to exercise it **without modifying system state**.

Earlier drafts phrased this on *custody* and on *timing*. Both fail. Custody is unfalsifiable — two
parties can each claim to hold a secret, and only reproducibility is a property of the system. Timing
has a hole: material minted after the customer's first login but **derived** from something the vendor
sets (`APP_SECRET`, `POSTGRES_PASSWORD`, `CADDY_MERCURE_JWT_SECRET` are vendor-supplied prod
requirements) is vendor-reproducible forever while passing a chronological test. Timing was only ever a
proxy for reproducibility.

The quantifier is load-bearing: *at no point in its life*. Material unreadable today but printed to the
vendor's terminal when minted fails I-2 — which is exactly the invitation CLI above, and exactly what
`CreateInitialAdministratorCommand` already protects against for passwords by hashing in Infrastructure,
never printing or logging, and preferring the hidden prompt (`:64-68,102-114`).

**I-2 grants no delivery exemption, and that is a design constraint, not an oversight.** `MAILER_DSN` is
vendor-configured on a vendor-operated host, so anything emailed is readable at the relay with no write.
A channel satisfying I-2 therefore cannot be delivered by email — unlike the corollary of I-1, which
does exempt the delivery surface.

**What this ADR explicitly does NOT guarantee, stated at full strength.** The vendor holds root on the
VPS and the database and can always seize the channel by writing to it. This record neither prevents
that nor claims to. What I-2 buys is narrower than an earlier draft of it claimed: seizure requires an
**active write to persisted state** rather than the passive replay of knowledge the vendor already
holds. It does **not** buy detection. Measured: `User` deliberately stays out of the write-capture CDC
so `password_hash` never enters the trail (`User.php:38`), the generic hook audits only `GET`
(`AuditPolicy.php:33-53`), the capture listener is a Doctrine listener that raw SQL bypasses entirely,
and `clearLockout()` raises no domain event (`User.php:307-318`). A vendor `UPDATE` today leaves no row
anywhere. Making that write detectable is the neighbouring axis §7 records as blocked on #555; until it
lands, "a write is required" is the whole guarantee, and calling it "detectable" would be the same
overclaim §7 forbids about the trail's tamper-evidence.

*Discarded:* "the vendor can never exercise the channel". Unachievable under this deployment model, and
an invariant no mechanism can satisfy is a wish.

## D3 — The options, judged against I-1 and I-2 rather than against implementation cost

| Option | I-1 | I-2 | Verdict |
|---|---|---|---|
| **A** — a second administrator by policy | — | — | Its only edge destroys the identity (below) |
| **B1** — recovery secret, show-once, non-reissuable | ✓ | ✓ | Satisfies both |
| **B2** — secret reissuable from a live session | ✓ | ✗ | Reissue is a second acquisition path |
| **B3** — secret reissuable through another channel | depends | depends | Relocates the problem |
| **C** — vendor operator command | ✓ | ✗ | Governance, not continuity (D5) |
| **D** — accept as-is | ✗ | — | No edge exists for a signed-out sole ADMIN |

**A does not reach I-1, but not because no edge exists.** A second administrator holding
`users.changeRoles` + `users.erase` + `users.invite` can demote the locked administrator, erase them —
`EraseIdentitySubject` hard-deletes the `identity_user` row, taking `failed_attempts` and `locked_until`
with it — and re-invite the same address. That is a real, shipped, permission-governed path. It is not a
*recovery*: it destroys the identity, pseudonymises its whole audit attribution irreversibly, and the
attacker can re-lock the recreated row. And it is unavailable to this ADR's victim, whose erasure is
refused precisely because they hold `ADMIN` and who has no peer to run it. A's failure is that its edge
is identity destruction, not that the graph is empty.

**B2's defect is I-2, not I-1.** A reissue path is a second way to acquire the material, so whoever
reaches it — including a stolen session — acquires the channel. Whether that is *permanent* depends on
whether a credential rotation invalidates outstanding secrets, which is a mechanism question this ADR
does not settle; what is settled is that the reissue path must satisfy I-2 on its own terms, and a
session-reachable one does not.

**B3 relocates custody.** Email is back inside I-1's violation; another human is A; the vendor is C.

**B1 is what satisfies both today.** Its cost is real and is not implementation: mislaying it is
permanent loss of the channel — the same fact as a root CA, an HSM seed or a PKI key. What follows
contractually (vendor rescue, reinstallation, permanent loss) is a product decision this ADR does not
take.

This ADR does **not** prescribe B1. It prescribes I-1 and I-2. Any future mechanism satisfying both
replaces B1 without changing a line here.

## D4 — Two administrators is recommended, never an enforced invariant

Raising the floor to ≥2 is the only variant that breaks something. Erasure refuses any subject holding
`ADMIN` (`FulfilIdentityErasure.php:135-137`), so erasing an administrator requires demoting them first,
and demotion consults `survivesRemovalOf` (`ChangeUserRoles.php:186`, `ChangeUserStatus.php:91`).
With a floor of 2, neither of exactly two administrators is demotable and erasing one would need a
**third**. An invariant that radiates side effects into an unrelated process is mis-chosen: it should
close the problem that motivated it, and this one does not close #602 at all.

**What this ADR does and does not do about it.** Provisioning a second administrator is sound hygiene, is
now written into [`../deployment-guide.md`](../deployment-guide.md) § *Provisioning administrators*, and
must stay unenforced. It does **not** close the "sole active administrator cannot be erased" item in §7:
an unenforced recommendation cannot make an invariant hold in an installation that declined it, so the
entry stays open with a pointer to the guidance rather than a strike. Recording the mitigation and
claiming the closure are different acts, and only the first is warranted.

*Discarded:* enforcing ≥2 in schema or application. It makes an open GDPR item strictly worse,
contradicts the bootstrap command and the E2E seed, and buys no transition.

*Discarded:* treating "two independent custodians" as a control. The schema can represent two rows with
`ADMIN` and `ACTIVE`; it cannot represent *independence*. A control that cannot observe its own property
is not a control.

## D5 — The operator command removes an invisible capability; it does not add one

`identity:lockout:clear` is worth building, and the reason must be recorded or the next reader will read
it as "more privilege for support". The vendor already holds root, and already holds first-credential
material on both bootstrap paths — it types the password in `organization:administrator:create`, and the
invitation alternative has the customer type it but prints the accept token to the vendor's terminal
(`CreateInvitationCommand:70`). The command grants nothing new: it converts a power exercised as
unattributable raw SQL into one that is named and tested.

**Not "auditable and attributable", which would be the same overclaim as D2's.** A CLI run's actor is
`system`, which carries no id, so any record attributes the act to the *process*, never to the human
operator. The command is therefore **governance, not continuity**: it fails I-2 by construction and must
never be cited as the customer's recovery path.

## D6 — Observing the recovery throttle is detective-only, and adds no edge to the graph

`PASSWORD_RECOVERY_THROTTLED` records that an address's recovery budget was exhausted. It must be read as
**evidence, never as a lever**: it grants nothing, reaches nobody outside `auditTrail.read`, and leaves the
uniform 202 — status, body and latency — untouched. Wording it as "the recovery path is now monitored" would
invite the opposite reading, that an operator watching the trail is a continuity mechanism. They are not: the
row appears *after* the owner has already been denied, and nothing in it shortens that denial.

**It does not weaken I-1 either, and the reason is what it does not carry.** The row names the subject's
identity id when the address resolves, which rides an axis whose erasure already has an owner; it never
carries the address, and it never carries any recovery-channel identifier — the thing I-1 forbids on this
path. An observation keyed by the same address the attacker already consumes cannot become a channel, because
nothing exercisable travels on it.

## D7 — The mechanism built, and the four things it costs

*Amendment (2026-08-28, on delivering the channel.)* B1 is what shipped, as an aggregate of its own
(`identity_recovery_secret`, one row per identity): minted from a live session against a re-proof of the
current password, shown in clear exactly once in the minting response, redeemed anonymously to establish a
session and clear the lockout. **This does not promote B1 from mechanism to invariant** — D3 still stands,
and anything satisfying I-1 and I-2 replaces it without changing a line above.

How it meets the two invariants, concretely: the redemption path spends `token_action_per_selector` and
nothing else, so no budget on it is keyed by an address or an identity (I-1); and minting is reachable only
through an authenticated session that proves the current password, never by email and never from a terminal,
so no vendor-held knowledge reconstructs it (I-2). The corollary is enforced by construction rather than by
care — the selector is the row's primary key, so every event names the **user**, and it appears in no audit
row, log line or URL, and in no DTO but the minting response, which is the delivery surface the corollary
exempts. Two places do hold it at rest and are named rather than implied: the row itself, where it is the
primary key, and the rate-limiter pool, which stores the bucket key for the window's duration.

What it costs, none of which D1–D6 anticipated:

- **The secret is valid for ten years, and that is a decision rather than a consequence.** `SingleUseToken`
  makes "no expiry" unrepresentable, so `RECOVERY_SECRET_TTL = P10Y` is the honest spelling. A short window
  would reintroduce by the back door the silent destruction rejected when deciding a password change leaves a
  live secret standing — the holder is by construction someone with no shell to notice it went. Tracked as an
  accepted risk with an open issue ([#870](https://github.com/sergio-salcedo-dev/ERPify/issues/870)), not as
  a note here.
- **Possessing it equals possessing a recovery credential until one of four events.** It survives a password
  rotation, it is not rotated when spent, and it dies only by redemption, revocation, expiry or subject
  erasure. The profile surface that lists it — minted at, expires at, with an explicit revoke — is the whole
  of what makes that governable, and D8 is why that revoke re-proves the credential rather than trusting the
  session it arrives on.
- **The selector is a denial capability, which is why the corollary is not merely hygiene.** Whoever learns
  one can spend that selector's redemption budget and hold the channel shut in silence, without ever
  authenticating. That denial is dominated by the cheaper email-keyed attack this ADR opens with, which is
  what bounds it — not the selector's entropy, which is a UUID v7 and therefore not a per-id CSPRNG draw.
- **Three state machines, no transaction spanning them.** The secret, the lockout and the session commit
  separately, so "single use" means *at most one persisted consumption*, never *at most one authentication*.
  The session is established BEFORE the row is retired — inverted, a failed session mint would leave the
  secret spent and the administrator with nothing to present — so a partial failure is retryable rather than
  terminal, and the endpoint does not promise 204 through it.

## D8 — Revoking is a credential-affecting act, so it re-proves like the other two

*Amendment (2026-08-29, from an adversarial pass over the delivered branch.)* Revocation initially required
only a live session, on the argument that destroying the secret grants nothing and is the safe direction of
the endpoint's failure. **That argument reasons about the wrong axis.** An attacker holding a stolen session
can change nothing — the password change and the mint both re-prove — but could destroy the recovery secret
with one request, read the owner's address from `GET /me`, and hold the account shut with the email-keyed
lockout this whole ADR opens with. The forgot→reset detour runs on the budget that same attacker drains and
its exhaustion is silent by contract, so the owner meets a 202 and no email. The escape hatch is gone,
irreversibly, and the only remedy left is the vendor writing to the database.

**The decision: destroying a recovery capability is as sensitive as granting one, so every authenticated act
that creates, replaces or destroys a credential re-proves it and spends the same per-identity budget.** That
is one invariant instead of three endpoints justifying themselves separately, and unlike an enumeration of
exceptions it is a rule something can be built to check. Availability of the recovery edge is part of the
security boundary here, not a usability concern beside it.

Discarded, each for a measured reason:

- **A budget of its own for revocation.** A second bucket doubles the guesses per window against the same
  password, which is precisely why minting and the password change already share one.
- **Re-proving only when a row exists.** It breaks the idempotent 204 by making the response shape disclose
  existence, and it decides authorisation from the state of the resource it is authorising against.
- **Keeping `DELETE /me/recovery-secret` as a deprecated alias.** An ungated path is an ungated path; the
  route was new and unreleased, so nothing was owed compatibility.
- **`DELETE` carrying a JSON body.** The password may not ride a header (every request header but `Referer`
  reaches the container access log in clear) so it must be a body — but the client's shared HTTP port declares
  no body on `delete`, and widening a shared port for a single caller is the abstraction the Rule of Three
  refuses. Hence `POST /me/recovery-secret/revoke`, spelled as a verb like every other action sub-path here.

What it costs: an owner who has just exhausted the shared budget by mistyping waits out the window before
they can revoke a secret they believe is compromised. That residual is **self-inflicted only** — all three
routes that drain the bucket require a live session, so it cannot be induced from outside — and it is bounded
and self-healing, against a loss that was permanent.

A second-order consequence, named because it is the expensive one: reading the credential forces the user
row, so revocation becomes the third path taking both locks and must take them in the order minting and
redemption already take them (`User`, then `RecoverySecret`). The ABBA argument is re-run with it inside
rather than inherited.

## D9 — Exhausting the per-selector budget writes nothing, and the silence is the decision

*Amendment (2026-08-31, on a review asking where the evidence was.)*

D6 gives the ADDRESS axis its `PASSWORD_RECOVERY_THROTTLED` row. The SELECTOR axis has no counterpart, and a
review read that asymmetry as an omission. It is not: it is what I-1 costs, and the cost is worth naming
because the obvious repair reintroduces the thing the budget exists to prevent.

The redemption's limiter refuses **before** the selector is resolved, so at the moment of the refusal nothing
is known about which identity — if any — the presentation named. An audit row naming the subject would
therefore require resolving the selector on the refused path: a database lookup per guess, on the one endpoint
whose threat model is "somebody is sending many of these", which is the amplification the early refusal buys.
And a row naming the **selector** is refused outright by I-1's corollary — the selector is a denial capability,
and `audit_log` is readable by anyone holding `auditTrail.read`.

**So the budget's exhaustion is not a domain transition and produces no evidence.** Two consequences are
accepted rather than mitigated: a sustained attack against one account's recovery channel is invisible to the
trail, and the design's other detection property — the owner noticing their secret gone — does not apply here,
because a refused redemption consumes nothing and the row stays live. What bounds the attack is the budget
itself, not anybody watching.

A volumetric signal (a counter carrying no selector, no identity and no reversible transformation of either)
would be compatible with I-1 and is the shape to reach for if this ever needs observing. It is deliberately not
built now: the `observability` stream has no rotation, no TTL and no declared owner of deletion — the same
reason a caller's string was refused that stream elsewhere in this repository — so adding a channel there is a
decision of its own and not a detail of this one.

## Falsification

**I-1 is falsified statically, not by a scenario — and this is the correction the adversarial pass
forced.** A scenario asserting `2xx` on the new channel after draining the email-keyed budgets cannot go
red for the right reason: the drains are outside the assertion's truth conditions precisely *because*
I-1 holds, and under `cache.adapter.array` (`api/config/packages/test/cache.yaml`) budget priming is
visible only to the immediately following request anyway (`RateLimitContext`), so sequential HTTP drains
consume nothing and pass vacuously. What proves I-1 is a **static check**: no limiter gating the channel
may be keyed by the email address or the identity id, and no surface but delivery may emit the
identifier. Its shape is the registry-plus-lint pattern the repo already uses for person references and
persistent transports.

- **I-1 dies** if any budget on the channel's path takes an email or identity key, or if the identifier
  reaches an API response, `event_store`, `audit_log`, a log line, an email or a session-scoped view.
- **I-2 dies** if the material is derivable from vendor-supplied secrets, or is observable anywhere in
  its life without a write — including a terminal, a log, a backup or the mail relay.
- **The mechanism** still needs its scenario: seal `locked_until` in the future with an intermediate
  `SELECT … AND locked_until > NOW()` so a failed seed cannot pass vacuously, then assert the surviving
  transition answers `2xx`. That scenario proves the edge exists; only the static checks above prove it
  is keyed correctly. Both, or neither is a control. **What it cannot do is attribute the unlock, and
  that was measured rather than assumed:** deleting `clearLockout()` from the use case leaves every
  scenario green, because `ClearLockoutOnLoginSuccess` clears the counter as an effect of the session
  establishment the scenario already performs. The assertions only this use case can satisfy are the
  retirement of the row and the `RECOVERY_SECRET_REDEEMED` entry conditioned on a persisted consumption;
  the falsifier that goes red on the transition itself is `RedeemRecoverySecretTest`, which has no
  listener in its graph.
- **D7 dies** if any of its four costs stops being true and stops being recorded: the TTL constant moving
  without the issue moving with it, the profile surface losing the expiry it displays, the selector reaching
  a surface, or the session being minted after the row is retired rather than before.
