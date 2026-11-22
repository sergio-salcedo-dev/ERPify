# Integration points, read from the tree (2026-11-22)

Load-bearing facts the stories lean on; paths are under `api/` unless noted.

## Session-minting seams (CAP-2)

- `json_login` on `identity_login` (`POST /api/v1/backoffice/login`) has **no success handler**; the session is minted in `LoginSuccessEvent` by `SessionMintingSuccessListener` (priority -128), after `ClearLockoutOnLoginSuccess` (-64) and `RotateSessionIdOnLogin` (-96). A gate that withholds the mint must sit above these so the lockout is not cleared and no `iam_session` row is written first.
- Four other paths mint through `ReauthenticateDevice` → `Security::login(…, 'main')`: reset-password completion, invitation accept, `ChangeMyPassword`, and `RedeemRecoverySecret` (which establishes the session before its own transaction and compensates only on refusal). `LockedIdentityLoginRefusalListener` never runs on those paths.
- `SessionAdmissionGate` (kernel.request priority 7, `^/api`) reads the registry row only; it never looks at identity status, so the second-step state must be decided before the mint, not at admission.
- There is no existing challenge or pending-login concept; the second step needs its own short-lived, single-use, attempt-capped pending state held outside the firewall token.

## Credential proof (CAP-1, CAP-4, CAP-5)

- `.credential-proof-policy` lines are `<route> :: credential-affecting | ordinary | anonymous :: <reason>`; the gate requires a `credential-affecting` action to spend `CurrentPasswordProofThrottle` then verify the password before any use case that calls `ProveCurrentPassword::ensure()`. Template: `RevokeMyRecoverySecretController` (proof in the body, 204).
- Admin writes over another identity: `backoffice_user_unlock` is `ordinary`; role, status and ADMIN-invitation writes re-prove the caller (`ProveActorPassword`, ADR D11). Admin reset belongs with the latter. Template for self-target refusal: `UnlockUserAccount` (`SelfUnlockForbidden`, 409, before the transaction).
- Anonymous routes also need `.public-access-exemptions` and, for credential-bearing POSTs with CSRF, `config/packages/csrf.yaml` `stateless_token_ids`.

## Sealing and erasure (CAP-6)

- `EnvelopeEncryptor::encrypt/decrypt` over `EncryptionScopeId::of(type, id)`; no identity-level sealed field exists yet. `Keystore::destroy(scope, DestroyReason::Erasure)` is the shred; `EraseBankAccountSubject` is the pattern (lock the key row, delete the row, destroy the key, evidence audit).
- `EraseIdentitySubject` hard-deletes the user, reset tokens and recovery secrets in one transaction (lock order user then secret); a new table joins it with `deleteAllForUser` and a count in `IdentityErasureResult`.
- A new person-id column needs the `#[PersonSubjectReference]` attribute, a `.person-reference-policy` line and a `PersonReferenceSource` (auto-tagged; model: `DbalRecoverySecretPersonReferences`).

## Other

- `NotifyLockedIdentities` runs on the `identity_maintenance` schedule every 5 minutes and mails the owner at most once per day; the alert extends this pattern to administrators. Mail ports mark addresses `#[SensitiveParameter]` and need `.person-address-parameter-policy` lines.
- Operator command template: `CreateInitialAdministratorCommand` (`organization:administrator:create`, hidden prompt, nothing sensitive in argv).
- PWA: `LoginOutcomeKind` and `ApiLoginRepository` route on `problem.type`; `ChangePasswordForm` and the recovery-secret panel are the templates; endpoints live in `API_ENDPOINTS`; `PasswordInput` rule applies to secret fields.
