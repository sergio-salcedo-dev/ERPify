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

**The corollary is already red on the invitation half, and that is why it belongs in the invariant.**
The six `Invitation` events carry the invitation id as the envelope's `aggregateId`
(`CarriesInvitationSnapshot.php:11-13`) — and that id *is* the accept link's selector — so
`DbalEventStore` writes it to `event_store.aggregate_id`, a table with no TTL and no erasure path
(`PRODUCTION_SECURITY_CHECKLIST.md` §7). `CreateInvitationCommand:70` additionally prints the whole
token, secret included. The reset half does satisfy the corollary: `PasswordResetToken` raises
`PasswordResetRequested($userId)`, so its envelope names the *user*, not the token row. Two token flows,
one convention each. Anything built under this ADR follows the reset convention; the invitation
divergence is a named residual, not a precedent.

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
and demotion consults `keepsAnActiveAdminWithout` (`ChangeUserRoles.php:128`, `ChangeUserStatus.php:85`).
With a floor of 2, neither of exactly two administrators is demotable and erasing one would need a
**third**. An invariant that radiates side effects into an unrelated process is mis-chosen: it should
close the problem that motivated it, and this one does not close #602 at all.

**What this ADR does and does not do about it.** It records that provisioning a second administrator is
sound hygiene and must stay unenforced. It does **not** claim the deployment guides say so — they
mention no administrator at all — and it does **not** close the "sole active administrator cannot be
erased" item in §7, whose own preamble requires striking the entry there and correcting whatever
describes the mitigated state. Both are follow-up work, named here so the gap is not read as closed.

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
  transition answers `2xx` and goes red when it is removed. That scenario proves the edge exists; only
  the static checks above prove it is keyed correctly. Both, or neither is a control.
