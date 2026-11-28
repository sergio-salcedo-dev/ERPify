---
id: SPEC-optional-two-step-verification
companions:
  - integration-points.md
  - ../../../docs/adr/administrative-recovery-channel.md
  - ../../../docs/adr/identity-invitation-lifecycle.md
  - ../../../docs/adr/two-step-verification.md
sources: []
---

> **Canonical contract.** This SPEC and the files in `companions:` are the complete, preservation-validated contract for what to build, test, and validate. Source documents listed in frontmatter are for traceability — consult them only if you need narrative rationale or prose color this contract intentionally omits.

# Optional two-step verification (TOTP) — Iam/Identity

## Why

A pain and a mandate, bounded by an owner decision. Anyone holding an administrator's password owns the back-office, and nothing but the password stands in the way. The owner chose an **optional** second factor, off by default, that each person turns on when they want, so a stolen password stops being enough for the people who opt in. It resolves the MFA half of deferred item DW-42; the lockout-DoS half stays an accepted risk, because the lockout fires on wrong passwords before any second factor and so cannot be mitigated by one. No production date exists, which makes this the cheapest moment to add a login step.

## Capabilities

- **CAP-1**
  - **intent:** A signed-in user can enable TOTP: their current password is re-proved, a secret is shown once, and the factor becomes active only after a valid code confirms it.
  - **success:** Enrolling with a wrong password is refused and spends the shared password-proof budget; an unconfirmed enrolment never gates login; after a valid confirming code the identity is marked enabled, and the secret is unreadable through any API response.
- **CAP-2**
  - **intent:** An identity with TOTP enabled must complete a second step after a correct password on every path that would mint a session, while an identity without it sees no change and nothing observable reveals which kind an account is.
  - **success:** A correct password alone never yields a session for an enabled identity on login, reset-password completion, invitation accept, change-password or recovery redeem; a wrong, replayed or expired code gives the uniform 401; a structural test shows the not-found, wrong-password and no-MFA branches pay the same pre-identity floor and share one response shape.
- **CAP-3**
  - **intent:** Enabling issues single-use recovery codes, shown once, that are accepted at the second step and to disable.
  - **success:** Ten codes are returned only at activation; a used code is refused the second time even under concurrent requests; only digests are stored.
- **CAP-4**
  - **intent:** A user can disable TOTP by presenting a valid current code.
  - **success:** A session without a valid code cannot disable it; with a valid app or recovery code the factor and its recovery codes are removed and the identity returns to password-only login.
- **CAP-5**
  - **intent:** When device and recovery codes are lost, another administrator, or an operator on the console as last resort, can reset a user's TOTP.
  - **success:** The admin route re-proves the caller's password, refuses self-targeting with a defined problem type before any write, and is audited; the console command reads no secret from argv and works when no other administrator exists; after a reset the user signs in with password only and can enrol again.
- **CAP-6**
  - **intent:** Erasing an identity deletes its second-factor rows and destroys the key that sealed its secret.
  - **success:** After erasure no TOTP row or recovery code remains, the keystore row for the identity's scope is tombstoned, an evidence audit row exists, and the person-reference reconciliation reports no divergence.
- **CAP-7**
  - **intent:** Administrators are alerted when a privileged identity is locked out.
  - **success:** Within one maintenance tick of a lockout on a user holding the ADMIN role, the other active administrators get one notice per locked identity per day, carrying no token or link; the failing login request does no mail work.
- **CAP-8**
  - **intent:** The PWA offers the second step at login and a profile panel to enrol, see recovery codes once, and disable.
  - **success:** A user can complete enrolment, a login that needs a code, a login with a recovery code, and a disable, using only the UI, with persistent error surfaces and stable test ids.
- **CAP-9**
  - **intent:** Every enrolment, activation, disable, reset and refused second step is recorded in the audit trail without secrets or codes.
  - **success:** Each of those events produces an audit row whose metadata contains no secret, code or digest; the new action tokens are classified in the evidence registry.

## Constraints

- **Optional, off by default, for everyone including ADMIN (owner decision).** No enforcement flag and no grace period is built; a later per-role requirement must be a small policy change, not a redesign.
- **The second step exists only after a correct password and must not be an oracle.** The failure collapses into the uniform 401, pays `PreIdentityTimingFloor`, and is proven by a structural test, never a wall-clock assertion (D10, `docs/rules/security.md`).
- **Every session-minting path is covered** (see `integration-points.md`). A withheld mint must clear the firewall token (`setToken(null)` plus `invalidate()`): the token is serialised into the native session even after `invalidate()`, so dropping the session alone leaves it authenticated.
- **The recovery secret and the reset-password link never bypass an enabled second factor.** They may still be used to reach the second step.
- **Second-step attempts spend their own per-identity budget and never feed the persisted email-keyed lockout.** That budget (`second_factor_code_per_identity`) is an attenuator, not a wall: it has no lock factory and its pool resets on deploy. The wall on guessing the enrolment's confirmation is the pending factor itself — it closes after 10 minutes or 5 wrong codes, counted on its row under its lock and committed although each refused request fails, and only a new enrolment behind the password restarts it (owner, option 1b, 2026-11-28). The wall on guessing an ENABLED factor's code is a persisted per-factor consecutive-failure counter mfa-2 builds: on the factor row, under its lock, committed on failure, reset only by an accepted code, never by a correct password, a deploy or time, never touching the email-keyed lockout, and bounding a sustained 30-day attack to ≤10⁻³ (owner, 2026-11-28).
- **A code is accepted once.** RFC 6238: HMAC-SHA1, 6 digits, 30 s step, plus or minus one step; the last accepted step is stored and a code at or before it is refused. Changing algorithm, digits or period invalidates every enrolled user.
- **The secret is a state-oriented aggregate sealed with the Shared/Crypto envelope** under a per-identity scope and an identity-bound AAD; it is never logged, returned after activation, or placed in an event. The scope id is random, lower-cased and unique, held on the identity's factor row and never derived from the person's id, because a destroyed key's tombstone and the erasure evidence that answers it outlive the person (confirmed by the owner, 2026-11-28; ADR `two-step-verification.md` D4). Every path that deletes a factor row destroys its key in the same transaction; a deletion that is not an erasure (disable, reset) either writes evidence the subject-erasure reconciler accepts or the reconciler excludes such tombstones. Recovery codes are stored as digests only and consumed atomically.
- **Disable requires a code, enable requires the password, admin reset re-proves the caller's password and refuses self-targeting before the transaction.** The code-authenticated disable route is classified by a new proof class of the credential-proof gate (`code-proof` in `api/.credential-proof-policy`): the gate requires the action to spend its own per-identity second-factor code budget and to verify the code before the use case runs. It is a mechanism, not a pinned exception, and a WebAuthn assertion verified through the same port fits it (owner, 2026-11-28). The enrolment's confirmation stays `ordinary` until that class exists, then moves into it; it becomes `credential-affecting` (or `code-proof`) at once if it ever returns recovery codes (owner, 2026-11-28).
- **Enabling a factor is treated like a password change.** After the commit every session of the identity is revoked, the asking device is signed back in, and the owner is mailed a notice with nothing exercisable in it, all best effort and outside the request's transaction (owner, 2026-11-28).
- **Factors enabled before recovery codes exist get them later.** mfa-3 adds a recovery-code generation and regeneration route behind a password re-proof, which also serves factors enabled before it (owner, 2026-11-28).
- **Signing in with a recovery code mails the identity's owner** (detective, nothing exercisable: no token, link or code), sent post-commit and outside the login request's timing, never inside it (owner, 2026-11-28).
- **The decisions live in a new ADR, `docs/adr/two-step-verification.md`** (step-up placement, recovery interaction, sealing and shredding), listed in `docs/index.md`; the recovery-channel ADR is not amended for them (owner, 2026-11-28).
- **The factor sits behind a port** so a WebAuthn adapter can be added without touching login.
- **The privileged-lockout alert runs from the maintenance schedule only**, carries nothing exercisable, and never filters `locked_until` in the active-administrator directory (closed and rejected). Privileged means the ADMIN role.
- **Identity erasure stays hard-delete plus crypto-shred, in the existing lock order** (`ErasureLockOrderTest`); the new table joins the person-reference policy and gets a `PersonReferenceSource`.

## Non-goals

- Mandatory MFA for any role, a grace period, or a per-role policy engine.
- WebAuthn, passkeys, SMS or email codes, push approval; only the port that would host them.
- "Remember this device" or trusted-device bypass.
- Adaptive or per-privilege lockout thresholds; the lockout-DoS half of DW-42 stays accepted risk.
- Changing the recovery-secret lifecycle itself, or an in-app notification system.

## Success signal

An administrator enables TOTP from their profile, signs out, and signs back in only with password plus a code. A thief holding the password, or a reset-password link, or a redeemed recovery secret, still cannot open a session, and the responses are indistinguishable from those of an account without the factor. Losing the phone is recoverable by a recovery code, another administrator, or the console, each audited.

## Assumptions

- Story checkpoints were set by the assistant under the instruction to ask only for blockers (spec review on the login and admin-reset stories, a done checkpoint after login); the owner may change them.
- Reset-password completion and invitation accept are treated like recovery redeem: an enabled identity needs the second step there too (the same bypass reasoning as the owner's recovery decision).
- Privileged means `Role::ADMIN`; there is no separate flag.
- The alert goes by mail to the other active administrators, since no in-app notification model exists; the owner of the locked identity is already told by `NotifyLockedIdentities`.
- TOTP is implemented in-house over `hash_hmac` and tested with the RFC 6238 vectors, adding no runtime dependency; no TOTP library exists in `composer.lock`.

## Open Questions

None open: the three questions of 2026-11-22 were answered by the owner on 2026-11-28 and live in Constraints.
