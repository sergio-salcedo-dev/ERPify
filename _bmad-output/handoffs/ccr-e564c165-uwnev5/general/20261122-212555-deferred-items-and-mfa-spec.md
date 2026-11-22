# Handoff: deferred items sweep and optional MFA spec (ERPify)

```
/bmad-agent-dev continue ERPify deferred-items work: answer the 3 open questions of the optional-MFA spec, then build it story by story; also the verification still owed on DW-74 and DW-72.

BRANCH: work is on `main` (everything below is merged); start a fresh branch from `origin/main` (name it after the task; the cloud tooling may impose its own, e.g. `ccr-…`). No PR is open.

DONE (all merged into main, PRs #1277 to #1281):
- #1277: DW-76 sign-out clears the `mercureAuthorization` cookie and `mercure.default_cookie_lifetime` is 900; DW-40 `PermissionCatalogCoversEveryGatedRouteTest` now sweeps `IsGranted` subclasses, non-string arguments and `access_control` on `/api`; DW-41 invitation query budget is a ceiling (`at most 30 requests …`); DW-43 one bounded realtime re-arm after a terminal denial (5 min); DW-72 `any-pointer-coarse:` 44 px in button/input variants and the h-9 selects; DW-35 closed as rejected (ESLint carve-out would only move the failure to the dependency-cruiser gate).
- #1278: DW-75 per-request nonce CSP in `pwa/src/proxy.ts` (`script-src 'self' 'nonce-…' 'strict-dynamic'`); every route is now dynamic (67 static before, 0 after; latency/CPU not measured).
- #1279: DW-74 the forgot-password work runs on `kernel.terminate` (`api/src/Iam/Identity/Infrastructure/Http/PasswordResetRequestListener.php`); functional test pinning the Mercure cookie/JWT lifetime.
- #1280: gate `PasswordResetRequestCallerGateTest` (only the listener may call `RequestPasswordReset`); terminate-listener priorities analysed, no change needed.
- #1281: spec folder `_bmad-output/specs/spec-optional-two-step-verification/` (SPEC.md, integration-points.md, stories.yaml mfa-1..mfa-6, .memlog.md); DW-42 ledger entry updated. No code written for it.

IN PROGRESS: nothing uncommitted.

NEXT, in order:
1. Get the owner's answers to the 3 open questions in the spec (see DECISIONS), append them to `.memlog.md` with `uv run _bmad/scripts/memlog.py append --workspace _bmad-output/specs/spec-optional-two-step-verification …`, re-derive SPEC.md, PR it.
2. Build the stories (`bmad-loop run --spec _bmad-output/specs/spec-optional-two-step-verification`, from a worktree on the working branch, never the primary). `mfa-2` (login second step) has a spec checkpoint and a done checkpoint; `mfa-4` has a spec checkpoint.
3. DW-74 verification still owed: a paired timing run (active vs unknown address) against a real mail relay, and a check that FrankenPHP flushes the response before `kernel.terminate`. A recording-logger test of the listener's failure report is also owed.
4. DW-72 still owed: a Playwright touch-profile measurement of the 44 px targets (needs the back-office with a session, i.e. the full stack).
5. Review leftovers deferred on purpose: `access_control` sweep ignores rules whose path lacks a literal `/api` and `when@prod` blocks; the `catch (Throwable)` in `RevokeCurrentSessionController::clearMercureCookie` has no test; the DW-72 touch test lists 9 files by hand and reads source text.
6. The other open deferred items (DW-2, 7, 14, 17, 18, 19, 27, 45, 49, 51, 78) wait for a trigger, not work: leave them.

ARTIFACTS (re-read first):
- `_bmad-output/specs/spec-optional-two-step-verification/SPEC.md`, `integration-points.md`, `stories.yaml`, `.memlog.md`
- `_bmad-output/implementation-artifacts/deferred-work.md` (DW-42, DW-72, DW-74 entries carry the latest notes)
- `PRODUCTION_SECURITY_CHECKLIST.md` section 7 (Mercure cookie, CSP, forgot-password latency), `docs/adr/identity-invitation-lifecycle.md` D10, `docs/adr/administrative-recovery-channel.md`
- Root `CLAUDE.md` and `api/CLAUDE.md` (gates, registries, the three review layers owed to security changes)

KEY PATHS:
- API: `api/src/Iam/Identity/Infrastructure/Http/PasswordResetRequestListener.php`, `RequestPasswordResetController.php`, `api/src/Iam/Identity/Application/RequestPasswordReset.php`, `LoginAttemptRegistrar.php`, `NotifyLockedIdentities.php`, `api/src/Iam/Session/Infrastructure/Controller/RevokeCurrentSessionController.php`, `api/config/packages/mercure.yaml`
- Registries: `api/.credential-proof-policy`, `api/.route-manifest.json`, `api/.person-reference-policy`, `api/.audit-evidence-actions`, `api/.artifact-gate-placement`, `api/.behat-step-vocabulary`
- PWA: `pwa/src/proxy.ts`, `pwa/src/context/shared/content-security-policy/domain/contentSecurityPolicy.ts`, `pwa/src/context/shared/real-time/infrastructure/useMercureRealtime.ts`, `pwa/src/components/ui/button-variants.ts`
- Gates: `api/tests/Unit/Gate/PasswordResetRequestCallerGateTest.php`, `api/tests/Functional/Shared/Realtime/MercureSubscriberCookieLifetimeFunctionalTest.php`

DECISIONS / CONSTRAINTS:
- Owner decisions on MFA (do not reopen): optional for everyone including ADMIN, off by default, no enforcement flag; TOTP plus single-use recovery codes behind a port (WebAuthn later, no SMS); secret is a state-oriented aggregate sealed with the Shared/Crypto envelope per identity; enabling re-proves the password, disabling needs a valid code; the second step appears only after a correct password and must not reveal whether an account has MFA; the recovery secret must not bypass MFA; lost device = recovery codes, admin reset (caller password re-proved, self-reset forbidden), operator console command; alert admins when a privileged (ADMIN) identity is locked out; lockout-DoS half of DW-42 is accepted risk, adaptive throttling stays rejected.
- Open questions for the owner: (1) how the code-authenticated disable route fits the credential-proof gate (extend it vs pinned exception); (2) mail the owner on a recovery-code sign-in?; (3) new ADR vs addendum to the recovery-channel ADR.
- Assistant assumptions in the spec (owner may change): story checkpoints; reset-password and invitation accept also need the second step; in-house TOTP with RFC 6238 vectors; alert by mail to the other admins.
- Process facts: the cloud container has no Docker, no `api/vendor`, PHP 8.3 only, so API changes are verified only by CI (read failures with `mcp__github__get_job_logs`, the tail is mostly worker noise: save the output and filter it). The proxy blocks the GitHub log blob host. BMad is gitignored and absent on a fresh clone; here it was installed with `npx bmad-method install --yes --modules bmm --tools claude-code` (6.12.4).
- Rules from the owner/repo: never merge without explicit per-merge permission; open PRs as draft; every change touching security/GDPR/audit owes the three review layers (blind, edge case, acceptance) before merge; commit only the files you changed; docs and specs in English, chat in Spanish; no secrets or personal data in handoffs.
```
