# ADR — Optional two-step verification: where the second step sits, how it meets recovery, and how its secret is sealed and shredded

> **Status:** accepted (D1–D4 implemented by mfa-1; D5–D6 decided, built by later stories) · **Date:** 2026-11-28 · **Scope:** `api/src/Iam/Identity`, `api/config/packages/rate_limiter.yaml`, `api/.credential-proof-policy`, and every path that mints a session.

## Context

Anyone holding an administrator's password owns the back-office. The owner chose an **optional** second factor,
off by default for everyone including `ADMIN`, that each person turns on when they want: TOTP first, with
single-use recovery codes, behind a port so WebAuthn can follow without touching login. No SMS, no "remember
this device", no enforcement flag. It answers the MFA half of DW-42; the lockout-DoS half stays accepted risk,
because the email-keyed lockout fires on wrong passwords before any second factor exists to help.

Two facts of the tree shape the design. There is no partially-authenticated state: `IS_AUTHENTICATED_FULLY` is
the only level, and a session is minted in `LoginSuccessEvent` (`SessionMintingSuccessListener`, priority -128)
or through `ReauthenticateDevice` on four other paths. And crypto-shredding leaves an eternal `dek_keystore`
tombstone that `DbalSubjectErasureReconciler` anti-joins, with no date bound, against a `GDPR_SUBJECT_ERASED`
row whose `metadata.encryption_scope_id` names the same scope — `metadata` being the one `audit_log` column no
erasure rewrites.

## Decisions

**D1 — The factor sits behind a port with fixed parameters, implemented in house.** `Domain/TotpAlgorithm` draws
the secret, answers which time step a code matches, and builds the `otpauth://` URI; `Rfc6238TotpAlgorithm`
implements it over `hash_hmac` (HMAC-SHA1, six digits, 30 s step, one step either side), tested with the RFC 6238
appendix B vectors. The use cases only learn "this code matches step N", so a WebAuthn adapter verifies an
assertion beside it and the use cases keep their shape. The parameters are the port's contract, not
configuration: an enrolled authenticator computes codes with what it was given, so changing one invalidates every
enrolled identity. *Discarded:* a TOTP library — none is in `composer.lock`, the algorithm is forty lines against
published vectors, and a dependency adds supply-chain surface to the one module that guards login.

**D2 — A state-oriented aggregate, one per identity, pending until a code confirms it, and only for a while.**
`TotpFactor` (`identity_totp_factor`, `user_id` and `scope_id` unique) holds the sealed secret, `enabled_at`,
`last_accepted_step`, and for a pending enrolment `enrolment_started_at` and `failed_confirm_attempts`. An enrolment
writes a pending row that gates nothing; only a code computed from the secret enables it, so a factor its owner
never set up cannot lock them out. A pending enrolment closes 10 minutes after it started or at its 5th wrong code,
whichever comes first, and a closed one answers the confirmation exactly like no enrolment (409
`second-factor-not-enrolled`, no type of its own). Only a new enrolment — behind the password — restarts it: new
secret, same scope, new start, zeroed count. An enabled factor is never restarted (409
`second-factor-already-enabled`), since replacing it would remove the owner's authenticator without the code they
hold. The step a code matched is stored and a code at or before it is refused, the confirming code included; when
two steps of the window share a code the later one is accepted. It records **no domain event**: the audit trail
records each transition (`SECOND_FACTOR_ENROLMENT_STARTED`, `SECOND_FACTOR_ENABLED`, written inside the transaction
after the identity's locking read), and an event would put a person's id into `event_store` for no reader. **An
activation is treated like a password change (owner, 2026-11-28):** after the commit every session of the identity
is revoked, the asking device is signed back in, and the owner is mailed a notice with nothing exercisable in it,
all best effort and outside the transaction. *Discarded:* columns on `identity_user` (read on every admission, it
would carry ciphertext it never needs); event sourcing (the owner's call: the snapshot is the business); a TTL alone
without the cap, and the cap alone without the TTL (each leaves the other dimension of guessing unbounded).

**D3 — Enabling re-proves the password; the confirmation is walled by the enrolment, not by the rate limiter;
removing with a code is a proof class of its own.** `POST /me/second-factor/totp` creates a credential of the
caller's own, so it is `credential-affecting` (pinned in `CredentialProofGateTest`): it spends the shared
`CurrentPasswordProofThrottle` bucket and re-proves the password **before** the factor is read or locked, so a
stolen session without the password cannot enrol an authenticator of its own and a refused re-proof writes nothing.
The factor's existence is not a secret from the account's own session — the confirmation's answers state it — so
that order is about writes, not about an oracle. `POST …/totp/confirm` needs no password and is `ordinary`
(**accepted by the owner, 2026-11-28, option 1b**): the secret it confirms was issued behind that re-proof, so a
session reaching it can at most enable the **owner's** pending authenticator. Its wall is D2's cap — five wrong codes
in ten minutes, counted on the factor row under its lock and committed although each refused request fails (the
transaction returns and the refusal is thrown after the commit), at most 1.5×10⁻⁵ per enrolment.
`SecondFactorCodeThrottle` (`second_factor_code_per_identity`, 10 per 15 minutes, literals) runs in front of it and
is only an **attenuator**: `lock_factory: null` lets a burst overrun it and the filesystem pool resets on deploy.
Every later route that verifies a code needs a persisted counter of its own (D5), never this bucket alone.
**Reopen** the confirmation's classification if it ever returns recovery codes (it becomes `credential-affecting`,
or `code-proof` once that class exists), or if any route lets a session start or restart an enrolment without the
password. **Owner, 2026-11-28:** the route that disables the factor with a code is classified by a new `code-proof`
class of the credential-proof gate — the gate verifies that the action spends the code budget and verifies the code
before the use case runs — a mechanism a WebAuthn assertion also fits, never a pinned exception; mfa-3 creates the
class and moves the confirmation into it.

**D4 — The secret is sealed under a random scope with an identity-bound associated data, and every deletion of a
factor row destroys its key in the same transaction (random scope confirmed by the owner, 2026-11-28).**
`TotpSecretVault` is the one place a secret is sealed or opened: `EncryptionScopeId::of('TotpFactor', <scope id
minted with the row>)`, held lower-cased on the row and in the vault because keystore scopes compare exactly, and
associated data `identity_totp_factor.sealed_secret:<canonical user id>`. The associated data binds the ciphertext
to its identity: a sealed value written onto another identity's row does not authenticate there. The scope is random
because the tombstone and its evidence outlive the person: with `EncryptionScopeId::of('User', <id>)` — the first
design — both the keystore tombstone and the `GDPR_SUBJECT_ERASED` metadata the reconciler reads would keep the
erased person's id for ever, which the identity erasure's acceptance witness (`erase.feature`) forbids.
`EraseIdentitySubject` deletes the factor and tombstones its key with `DestroyReason::Erasure` in its transaction,
after the reset tokens and the recovery secret, and reports the deleted factor's scope whether or not this run wrote
the tombstone; `FulfilIdentityErasure` writes it as `encryption_scope_id` (with `totp_factors_deleted`) on the
`GDPR_SUBJECT_ERASED` entry, and `totp_factors_deleted` on `GDPR_ERASURE_EXECUTED`. A `DbalTotpFactorPersonReferences`
source reports a `user_id` that outlived its identity. Opening is a registered read of the crypto boundary: on this
path `dek-destroyed` is corruption, not an expected post-erasure outcome, so both crypto outcomes become the
marker-less `SecondFactorIntegrityFailure` (500). The secret is returned only in the enrolment's 201
(`Cache-Control: no-store`); no route returns it after, and the aggregate never holds it in clear. **Owed by every
later path that deletes a factor row** (mfa-3's disable, mfa-4's reset): destroy its key in the same transaction,
and since that destruction is not an erasure, either write evidence the subject-erasure reconciler accepts for it or
make the reconciler exclude non-erasure `TotpFactor:` tombstones — one of the two, decided by mfa-3. *Residual:* a
dump taken before the erasure restores the factor with the identity row; the identity erasure has no ledger replay
(`PRODUCTION_SECURITY_CHECKLIST.md` §7).

**D5 — The second step sits after a correct password and before any session is minted, on every minting path.**
Login, reset-password completion, invitation accept, change-password and recovery redeem all ask for it when the
identity has an enabled factor; an identity without one sees no change. The gate runs above priority -64 on
`LoginSuccessEvent` (so the lockout is not cleared and no `iam_session` row is written first) and inside
`ReauthenticateDevice`; a withheld mint clears the firewall token (`setToken(null)` plus `invalidate()`), because
the token is serialised into the native session even after `invalidate()`. The pending state is short-lived,
single-use, attempt-capped and held outside the firewall token. Every failure collapses into the uniform 401 and
pays `PreIdentityTimingFloor`, proven by a structural test, never a wall-clock assertion. **Owed (owner,
2026-11-28):** a persisted per-factor consecutive-failure counter for the ENABLED factor — on the factor row,
counted under its lock, committed even when the request fails, reset only by an accepted code (TOTP or recovery),
never by a correct password, a deploy or time, and never touching `failed_attempts`/`locked_until` — bounding a
sustained 30-day attack to ≤10⁻³ (an escalating lockout doubling from 15 minutes per tranche of 10 attempts, or a
hard lock after about 10 consecutive failures until a recovery code or an administrator reset). Whether its lock
notifies the owner, and whether mfa-3's disable route spends it, are decided at mfa-2's spec checkpoint. Built by
mfa-2, which amends this decision with what it measures.

**D6 — Recovery never bypasses an enabled factor; losing the device is recovered by codes, by another
administrator or by the console.** The recovery secret and the reset link may reach the second step, never past
it. Ten single-use recovery codes are issued at activation, stored as digests, consumed atomically and accepted at
the second step and to disable (mfa-3). **Owner, 2026-11-28:** mfa-3 also adds a recovery-code generation and
regeneration route behind a password re-proof, which serves the factors enabled before it existed; and signing in
with a recovery code mails the identity's owner — detective, nothing exercisable in the mail — sent post-commit,
outside the login request's timing, never inside it (mfa-3). Another administrator resets a factor after re-proving
their own password and never their own (409 before the transaction, the `SelfUnlockForbidden` pattern); an operator
command covers the last-administrator case (mfa-4).

## Consequences

Until mfa-2 ships, an enabled factor is asked for nowhere, no persisted wall bounds guessing an enabled factor's
code, and no route disables or resets one; a factor enabled before mfa-3 holds no recovery codes until mfa-3's
generation route serves it. All four are open in `PRODUCTION_SECURITY_CHECKLIST.md` §7, and the PWA panel waits for
mfa-2 and mfa-3. Every story of the epic amends this record rather than opening another.
