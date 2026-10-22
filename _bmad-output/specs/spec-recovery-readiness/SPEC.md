---
id: SPEC-recovery-readiness
companions:
  - ../../implementation-artifacts/rr-2-evict-first-guidance.md
sources:
  - ../../implementation-artifacts/rr-1-recovery-secret-readiness-warning.md
---

> **Canonical contract.** This SPEC and the files in `companions:` are the complete, preservation-validated contract for what to build, test, and validate. Source documents listed in frontmatter are for traceability — consult them only if you need narrative rationale or prose color this contract intentionally omits.

# Recovery readiness

## Why

A mandate before the first customer: the lockout composition of #602 lets someone holding a stolen session
deny the owner a credential rotation, and `PRODUCTION_SECURITY_CHECKLIST.md` §7 records what closes the
product half of it. Every administrator has to hold a live recovery secret and re-mint after each
redemption, and every surface that tells a user what to do next has to put evicting the other sessions
before changing the password, so a new password is never handed to a session that is still open.

## Capabilities

- **CAP-1** — shipped in #1073 (RR-1)
  - **intent:** An administrator with no live recovery secret is told so on every back-office page.
  - **success:** A lapsed row counts as none; the warning disappears once a secret is minted and reappears
    after a redemption.
- **CAP-2** — RR-2, contract in the companion `rr-2-evict-first-guidance.md`
  - **intent:** The password-changed mail, the change-password form and the post-redemption screen each put
    "sign the other devices out" before "change the password".
  - **success:** The companion's acceptance criteria 1–5 hold: both mail bodies and both PWA surfaces carry
    the copy, the stale §7 clause is rewritten, and `ui-copy-language` stays green.

## Constraints

- The copy never claims the account is secure after a redemption alone: redemption evicts the other sessions
  but changes no password.
- No behaviour change: CAP-2 is copy plus one documentation correction.

## Non-goals

- Changing what redemption, password reset or password change actually do.
- Amending ADR D7: an expired secret still occupies the slot and is revoked before re-minting.

## Success signal

- An administrator who suspects someone else is signed in is told, on whichever surface they reach first,
  to sign the other devices out before setting a new password.
