---
title: 'mfa-1: Second-factor port, sealed aggregate and enrolment'
type: 'feature'
created: '2026-11-28'
status: 'in-review'
baseline_commit: '0d3cbeb'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/specs/spec-optional-two-step-verification/SPEC.md'
  - '{project-root}/_bmad-output/specs/spec-optional-two-step-verification/integration-points.md'
  - '{project-root}/docs/adr/two-step-verification.md'
  - '{project-root}/docs/adr/administrative-recovery-channel.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** CAP-1 does not exist, nor the persistence halves of CAP-6 and CAP-9. Nothing can hold a second factor
for an identity, nothing seals one, and the identity erasure has nothing to shred.

**Approach:** A TOTP port in the Domain with an in-house RFC 6238 adapter; a state-oriented `TotpFactor` aggregate,
pending until a code confirms it, whose secret is sealed with the Shared/Crypto envelope under a per-identity scope
and an identity-bound associated data; an enrol route behind a password re-proof and a confirm route behind a code
budget; the identity erasure deleting the row and destroying its key in one transaction, with the scope named on the
erasure evidence; and every registry and gate the new table, routes and audit actions reach.

**Contract:** `stories.yaml` entry `mfa-1` is the owner's contract (description and `invoke_dev_with`), read with
`SPEC.md` and `integration-points.md`.

## Boundaries & Constraints

**Decided by the owner (do not reopen):** optional and off by default for everyone, administrators included;
TOTP first behind a port so WebAuthn can follow; state-oriented aggregate sealed under a per-identity scope; enabling
re-proves the current password; the decisions live in `docs/adr/two-step-verification.md` (2026-11-28). After the
review (owner, 2026-11-28): the random per-factor scope is confirmed; an activation revokes every session,
re-authenticates the asking device and mails the owner post-commit, as a password change does; the confirmation
stays `ordinary` under option 1b — a pending factor closes after 10 minutes or 5 wrong codes counted on its row and
committed although the request fails, answering 409 `second-factor-not-enrolled`, and only a new enrolment restarts
it; factors enabled before mfa-3 get recovery codes from mfa-3's generation route.

**Decided here, inside those bounds (named for review):**
- **The per-identity scope is a random id held on the factor row** (`EncryptionScopeId::of('TotpFactor', scopeId)`),
  not `EncryptionScopeId::of('User', userId)` as the first wording of `invoke_dev_with` said. Measured reason: a
  destroyed key leaves an eternal `dek_keystore` tombstone named by its scope, and `DbalSubjectErasureReconciler`
  requires a `GDPR_SUBJECT_ERASED` row whose `metadata.encryption_scope_id` equals it; with `User:<id>` both the
  tombstone and that metadata (never rewritten by any erasure, and held by the `erase.feature` witness) would keep the
  erased person's id for ever. Confirmed by the owner on 2026-11-28 (memlog, ADR D4, `invoke_dev_with`). The
  scope is held lower-cased and unique. The associated data stays bound to the identity id.
- **Routes:** `POST /api/v1/me/second-factor/totp` (`iam_me_enrol_totp`, `credential-affecting`, shared
  `CurrentPasswordProofThrottle`, 201 with `secret` and `provisioningUri`) and `POST /api/v1/me/second-factor/totp/confirm`
  (`iam_me_confirm_totp`, `ordinary`, new `SecondFactorCodeThrottle` over `second_factor_code_per_identity`, 10 per
  15 minutes as literals, 200 with `enabledAt`).
- **A pending enrolment may be restarted** (new secret, same scope); an enabled one is refused (409
  `second-factor-already-enabled`) at enrolment and at confirmation.
- **No domain event**; the audit trail records `SECOND_FACTOR_ENROLMENT_STARTED` and `SECOND_FACTOR_ENABLED`
  (`security`, resource `User`, no metadata) inside the transaction after the identity's locking read.
- **Opening the secret translates both crypto outcomes** (`dek-destroyed`, `decryption-failed`) into the marker-less
  500 `second-factor-integrity-failure`, because no path leaves a factor row behind its destroyed key.
- **The erasure evidence carries `totp_factors_deleted` and `encryption_scope_id` whenever a factor row was deleted**
  (on `GDPR_SUBJECT_ERASED`; `totp_factors_deleted` always on `GDPR_ERASURE_EXECUTED`), whether or not this run
  wrote the tombstone; the CLI's consent map gains the category.
- **The secret-bearing 201s are `Cache-Control: no-store`**: the enrolment's and, the same gap, the recovery-secret
  mint's.

## I/O & Edge-Case Matrix

| Case | Expected |
|---|---|
| Enrol, correct password, no factor | 201 `{secret, provisioningUri}`; a pending row sealed under a fresh random scope; one `SECOND_FACTOR_ENROLMENT_STARTED` row |
| Enrol, wrong password (factor enabled or not) | 403 `invalid-current-password` before the factor is read; nothing written |
| Enrol, budget spent | 429 `rate-limited` (shared password-proof bucket) |
| Enrol, pending factor | 201; the same row restarted under its scope with a new secret |
| Enrol, enabled factor | 409 `second-factor-already-enabled`; the secret is never shown again |
| Confirm, code of the current step or one either side | 200 `{enabledAt}`; the matched step becomes `last_accepted_step`; one `SECOND_FACTOR_ENABLED` row; post-commit every session revoked, the device signed back in, one mail to the owner |
| Confirm, wrong, two steps away, malformed | 403 `invalid-second-factor-code`; the failure counted and committed; no session revoked, no mail |
| Confirm, pending factor 10 minutes old or with 5 wrong codes (even the right code) | 409 `second-factor-not-enrolled`; a new enrolment restarts it |
| Confirm, code budget spent (10 per 15 minutes) | 429 `rate-limited` with `Retry-After`, before the use case; a 422 spends nothing |
| Confirm, no enrolment / already enabled | 409 `second-factor-not-enrolled` / `second-factor-already-enabled` |
| Enrol or confirm, identity not `ACTIVE` | 403 `account-suspended` / `account-deactivated`; nothing saved, no audit row (use-case tests: the firewall drops such a session before any route) |
| Two first enrolments at once | not reachable: both lock the identity row first, so the second restarts the first's row; `concurrent-unique-write` stays the translation of the unique index, untested at HTTP level |
| Confirm, key destroyed or ciphertext moved to another identity | 500 `second-factor-integrity-failure` |
| Erase an identity holding a factor | row deleted, key tombstoned `erasure`, `GDPR_SUBJECT_ERASED` carries the scope, no column holds the person's id |

</frozen-after-approval>

## Code Map

- Domain: `Domain/TotpAlgorithm.php` (port), `Domain/Entity/TotpFactor.php`, `Domain/Repository/TotpFactorRepository.php`,
  `Domain/Exception/{SecondFactorAlreadyEnabled,SecondFactorNotEnrolled,InvalidSecondFactorCode,SecondFactorIntegrityFailure}.php`.
- Application: `EnrolTotpFactor.php`, `ConfirmTotpFactor.php`, `TotpSecretVault.php` (the one seal/open site, registered
  crypto reader), `TotpEnrolment.php`, `Resource/{TotpEnrolmentResource,TotpFactorEnabledResource}.php`;
  `EraseIdentitySubject.php` (factor delete plus key destroy), `IdentityErasureResult.php`, `FulfilIdentityErasure.php`
  (evidence scope), `FulfilIdentityErasureResult.php` (consent category).
- Infrastructure: `Security/Rfc6238TotpAlgorithm.php`, `Security/SecondFactorCodeThrottle.php`,
  `Controller/{EnrolTotpFactor,ConfirmTotpFactor}Controller.php`, `Http/{EnrolTotpFactor,ConfirmTotpFactor}Request.php`,
  `Persistence/Doctrine/{DoctrineTotpFactorRepository,DbalTotpFactorPersonReferences}.php`.
- Shared: `Shared/Audit/Domain/AuditMetadataKey.php` (`totp_factors_deleted`, Count).
- Schema: `migrations/2026/Version20261128120000.php` (`identity_totp_factor`, `user_id` and `scope_id` unique,
  `enrolment_started_at`, `failed_confirm_attempts`).
- Activation side effects: `ConfirmTotpFactor` (post-commit `RevokeSessionsBestEffort`, mail), the controller's
  `ReauthenticateDeviceBestEffort`, `SecondFactorEnabledEmailSender` / `SendSecondFactorEnabledEmailBestEffort` /
  `Infrastructure/Mail/SymfonySecondFactorEnabledEmailSender.php`, bound to `observability` in `services.yaml`.
- Config: `config/packages/rate_limiter.yaml` (`second_factor_code_per_identity`).
- Registries: `.route-manifest.json`, `.credential-proof-policy`, `.person-reference-policy`,
  `.person-address-parameter-policy`, `.audit-evidence-actions`, `.artifact-gate-placement`,
  `.behat-step-vocabulary`; gates `SubjectAuditWriteSerialisationGateTest` (two `locking-read` writers),
  `CryptoReadBoundaryGateTest` (the vault), `SensitiveRecipientAddressGateTest` and `BestEffortReportChannelGateTest`
  (the mail), `CredentialProofGateTest` (enrolment pinned, ten routes).
- Records: ADR `docs/adr/two-step-verification.md` (new, in `docs/index.md`), `docs/api-error-contract.md` →
  "Two-step verification enrolment", `PRODUCTION_SECURITY_CHECKLIST.md` §7 (four items), `docs/architecture-api.md`,
  `docs/rules/security.md`, root `CLAUDE.md` (ten pinned routes), the erasure query budget in
  `features/backoffice/users/erase.feature` (27 → 28, measured green by Behat).

## Tests

- Unit: `Rfc6238TotpAlgorithmTest` (RFC 6238 appendix B vectors, window, replay, malformed codes, base32, URI),
  `TotpFactorTest`, `EnrolTotpFactorTest`, `ConfirmTotpFactorTest` (real libsodium envelope over the in-memory keystore),
  `EraseIdentitySubjectTest` (factor erased, key tombstoned, scope reported), `ErasureLockOrderTest` (factor after the
  recovery secret), the CLI categories test, the metadata vocabulary test; every `EraseIdentitySubject` construction
  site gained the two collaborators.
- Functional: `TotpEnrolmentFunctionalTest` (routes through the kernel: no-store, sessions revoked and mail, the
  rotated session of a real login, the refusals), `TotpConfirmationLimitsFunctionalTest` (the code budget spent
  before the use case and not by a refused body, the five-code cap, the window, the 500 integrity path), both over
  the shared `DrivesTotpEnrolment` trait, `TotpSecretVaultFunctionalTest` (both crypto outcomes against the real keystore — the crypto-read
  registration), `TotpFactorErasureFunctionalTest` (real erasure, tombstone, evidence scope, no column holding the
  person, and the real audit logger with the real subject-erasure reconciler in a rolled-back transaction).
- Behat: `features/backoffice/identity/two_step_verification_enrolment.feature` (refusals, the enrolment, the window
  and the cap; a code cannot be computed from the step vocabulary, so the successful confirmation stays functional).
- Unit additions: `TotpSecretVaultTest` (canonical scope), `SymfonySecondFactorEnabledEmailSenderTest`,
  `SecondFactorCodeBudgetTest` (10 / 15 minutes, literals).

## Spec Change Log

- 2026-11-28: the sealing scope refined from `User:<id>` to a random per-factor scope, for the reason above; confirmed
  by the owner the same day.
- 2026-11-28 (owner, after the review): activation side effects (sessions, re-login, mail); option 1b for the
  confirmation (window and cap on the pending factor, counted and committed on failure); recovery codes for factors
  enabled before mfa-3 through mfa-3's generation route; mfa-2 owes the persisted counter for the enabled factor.

## Review Triage Log

The three layers (Blind Hunter, Edge Case Hunter, Acceptance Auditor) ran over the branch in the coordinating
session on 2026-11-28; the coordinator deduplicated them into the list below without naming the layer of each, so
the layer column says "three-layer review". Every row was a `patch`, applied on the branch in the commits that follow
this log; the owner's decisions of the same day are the first four rows.

| # | Finding (layers) | Verdict | Evidence / route |
|---|---|---|---|
| D1 | Random per-factor scope awaited the owner | owner decision | confirmed; markers removed (memlog, ADR D4, this file) |
| D2 | Factors enabled before recovery codes exist | owner decision | mfa-3 generation route; memlog, ADR D6, `stories.yaml`, §7 |
| D3 | An activation left an attacker's parallel session alive and the owner uninformed | owner decision | sessions revoked, device signed back in, post-commit mail; tests unit and functional |
| D4 | The confirmation is `ordinary` and walled only by a lock-free, deploy-reset limiter | owner decision (1b) | pending factor closes at 10 minutes or 5 wrong codes, counted and committed; §7 accepted item; mfa-2 counter owed |
| 1 | No test proved the code budget runs before the use case | patch | 10 codes then the correct one → 429, factor pending; 422 spends nothing; `SecondFactorCodeBudgetTest` |
| 2 | A pending enrolment stayed confirmable for ever | patch | superseded by D4 (window and cap); unit, functional, Behat |
| 3 | The limiter was overstated as a bound ("30 chances in a million") | patch | `rate_limiter.yaml`, throttle docblock, ADR D3: an attenuator |
| 4 | The erasure test used a recording logger, so the reconciler was never asked | patch | real logger and real reconciler in a rolled-back transaction |
| 5 | A factor whose key was already tombstoned left its scope out of the evidence | patch | scope carried whenever a row is deleted; test inverted |
| 6 | `GDPR_ERASURE_EXECUTED` lacked `totp_factors_deleted` | patch | added; pinned arrays updated |
| 7 | An upper-case scope id would seal and destroy under different keystore rows | patch | lower-cased in the aggregate and in `scopeOf()`; `TotpSecretVaultTest` |
| 8 | Two factors could share a scope | patch | unique index and `#[ORM\UniqueConstraint]` on `scope_id` |
| 9 | The secret-bearing 201s could be cached (enrolment and recovery-secret mint) | patch | `Cache-Control: no-store`; functional and Behat |
| 10 | No tests for non-`ACTIVE` identities, the raced first enrolment or the HTTP 500 | patch | use-case tests; matrix row for the unreachable race; functional 500 |
| 11 | Scope randomness asserted for one identity only | patch | two identities, two fresh v7 scopes |
| 12 | `matchingStep` kept the first of two matching steps | patch | last match wins, constant work; collision vector found by search |
| 13 | The enrolment route was not pinned `credential-affecting` | patch | `CredentialProofGateTest`; policy and `CLAUDE.md` say ten |
| 14 | Future factor deletions (disable, reset) and their tombstones were unowned | patch | ADR D4; `stories.yaml` mfa-3 and mfa-4 |
| 15 | Two docblocks overclaimed (backup unreadability, keystore lock) | patch | reworded per ADR D4 residual and the real lock order |
| 16 | Roadmap sentences in code comments | patch | rewritten as contract; the erase.feature "derived" note dropped |
| 17 | `docs/architecture-api.md` counted five `/me*` routes | patch | seven, four password-proving writes |
| 18 | `SPEC.md` still listed Open Questions | patch | emptied, decisions in Constraints |
| 19 | Over-long lines in `ErasureLockOrderTest` | patch | rewrapped |
| 20 | No Behat coverage | patch | feature added with existing steps; one idle step spent |
| 21 | CI: PHPMD on the paging provider (71 lines) | patch | the identity cases split into a helper |

## Verification

Not run locally: the session had no Docker stack and PHP 8.3 only, so no `make php.*`, PHPUnit, Behat or console
command ran. `php -l` passed on every changed PHP file, and the RFC 6238 vectors, the base32 encoding, the URI and
the collision vector of the last-match test were checked by executing the adapter under PHP 8.3. CI run 46140792280
measured Behat green with the erasure budget at 28; the review patches are verified by the next CI run only.
