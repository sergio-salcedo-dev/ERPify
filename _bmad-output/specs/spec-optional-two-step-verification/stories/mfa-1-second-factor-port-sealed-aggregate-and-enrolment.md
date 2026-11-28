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
re-proves the current password; the decisions live in `docs/adr/two-step-verification.md` (2026-11-28).

**Decided here, inside those bounds (named for review):**
- **The per-identity scope is a random id held on the factor row** (`EncryptionScopeId::of('TotpFactor', scopeId)`),
  not `EncryptionScopeId::of('User', userId)` as the first wording of `invoke_dev_with` said. Measured reason: a
  destroyed key leaves an eternal `dek_keystore` tombstone named by its scope, and `DbalSubjectErasureReconciler`
  requires a `GDPR_SUBJECT_ERASED` row whose `metadata.encryption_scope_id` equals it; with `User:<id>` both the
  tombstone and that metadata (never rewritten by any erasure, and held by the `erase.feature` witness) would keep the
  erased person's id for ever. Recorded in the memlog as a decision awaiting the owner's confirmation, in ADR D4, and
  in the re-derived `invoke_dev_with`. The associated data stays bound to the identity id.
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
- **The erasure evidence carries `totp_factors_deleted` and `encryption_scope_id` only when a factor was erased**,
  so existing compliance entries keep their exact shape; the CLI's consent map gains the category.

## I/O & Edge-Case Matrix

| Case | Expected |
|---|---|
| Enrol, correct password, no factor | 201 `{secret, provisioningUri}`; a pending row sealed under a fresh random scope; one `SECOND_FACTOR_ENROLMENT_STARTED` row |
| Enrol, wrong password (factor enabled or not) | 403 `invalid-current-password` before the factor is read; nothing written |
| Enrol, budget spent | 429 `rate-limited` (shared password-proof bucket) |
| Enrol, pending factor | 201; the same row restarted under its scope with a new secret |
| Enrol, enabled factor | 409 `second-factor-already-enabled`; the secret is never shown again |
| Confirm, code of the current step or one either side | 200 `{enabledAt}`; the matched step becomes `last_accepted_step`; one `SECOND_FACTOR_ENABLED` row |
| Confirm, wrong, two steps away, malformed | 403 `invalid-second-factor-code`; nothing written |
| Confirm, no enrolment / already enabled | 409 `second-factor-not-enrolled` / `second-factor-already-enabled` |
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
- Schema: `migrations/2026/Version20261128120000.php` (`identity_totp_factor`).
- Config: `config/packages/rate_limiter.yaml` (`second_factor_code_per_identity`).
- Registries: `.route-manifest.json`, `.credential-proof-policy`, `.person-reference-policy`,
  `.person-address-parameter-policy`, `.audit-evidence-actions`; gates `SubjectAuditWriteSerialisationGateTest`
  (two `locking-read` writers) and `CryptoReadBoundaryGateTest` (the vault).
- Records: ADR `docs/adr/two-step-verification.md` (new, in `docs/index.md`), `docs/api-error-contract.md` →
  "Two-step verification enrolment", `PRODUCTION_SECURITY_CHECKLIST.md` §7 (three items), `docs/architecture-api.md`,
  `docs/rules/security.md`, the erasure query budget in `features/backoffice/users/erase.feature` (27 → 28).

## Tests

- Unit: `Rfc6238TotpAlgorithmTest` (RFC 6238 appendix B vectors, window, replay, malformed codes, base32, URI),
  `TotpFactorTest`, `EnrolTotpFactorTest`, `ConfirmTotpFactorTest` (real libsodium envelope over the in-memory keystore),
  `EraseIdentitySubjectTest` (factor erased, key tombstoned, scope reported), `ErasureLockOrderTest` (factor after the
  recovery secret), the CLI categories test, the metadata vocabulary test; every `EraseIdentitySubject` construction
  site gained the two collaborators.
- Functional: `TotpEnrolmentFunctionalTest` (routes through the kernel), `TotpSecretVaultFunctionalTest` (both crypto
  outcomes against the real keystore — the crypto-read registration), `TotpFactorErasureFunctionalTest` (real erasure,
  tombstone, evidence scope, no column holding the person).

## Spec Change Log

- 2026-11-28: the sealing scope refined from `User:<id>` to a random per-factor scope, for the reason above; logged in
  `.memlog.md` as an assistant decision awaiting the owner's confirmation.

## Review Triage Log

The three review layers (Blind Hunter, Edge Case Hunter, Acceptance Auditor) are owed and not yet run.

## Verification

Not run locally: the session had no Docker stack and PHP 8.3 only, so no `make php.*`, PHPUnit, Behat or console
command ran. `php -l` passed on every changed PHP file, and the RFC 6238 vectors, the base32 encoding and the URI
were checked by executing the adapter under PHP 8.3. CI is the verifier; the erasure query budget (28) was derived
from the code and must be confirmed by the Behat run.
