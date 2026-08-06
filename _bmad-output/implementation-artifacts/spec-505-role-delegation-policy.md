---
title: 'Inviter role-delegation policy, attributable role changes, and a console lever to revoke an invitation (#505)'
type: 'feature'
created: '2026-08-06'
status: 'in-review'
baseline_commit: '58a081a8d34f4cadc69a118f1038e75bef400bc6'
review_loop_iteration: 0
context:
  - '{project-root}/docs/adr/authorization-model-boundaries.md'
  - '{project-root}/tmp/pr-body-505-argument.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Any `ADMIN` can mint unlimited further `ADMIN`s — via invite or role change — with no permission naming
the act, no attributable record that it happened, and no console lever to undo it. A rogue invite outlives its
author: the identity sits at `status=INVITED, roles=["ADMIN"]`, the token lives three days, `AcceptInvitation` never
re-checks the inviter's standing, and revocation exists only as a container-shell CLI.

**Approach:** Name the act with a `users.grantAdmin` permission granted to `[ADMIN]`, checked at the two HTTP
enforcement points and only when the payload carries `Role::ADMIN`; re-land the `USER_ROLES_CHANGED` audit row now
that its erasure-ownership blocker is closed; and give revocation an HTTP surface plus a console action, keyed by
user id and resolved inside the Invitation context so no bounded-context seam is minted.

## Boundaries & Constraints

**Always:**
- The grant row starts at `[ADMIN]`, never empty — both `users.invite` and `users.changeRoles` are ADMIN-only, so
  forbidding an `ADMIN` to grant `ADMIN` would make a second administrator impossible to create, stranding the GDPR
  erasure of the sole administrator. Tightening later is a one-line data edit.
- Enforcement lives in the controllers, never in `SendInvitation`/`ChangeUserRoles`: the CLI drives the same use
  cases with no session, and an Application layer branching on a role breaks SI-5.
- Each new permission goes in **both** `PermissionCatalog::PERMISSIONS` and `EXPLICIT_GRANTS` — `users` is in
  `TIER_OPT_OUT`, so the row is what grants it. The UI gate is convenience; the API is the control.

**Ask First:** any change to the `AuthorizationPolicy` port, to `PermissionVoter`, or to `.audit-resource-types`.

**Never:**
- Pass requested roles to the voter as `subject:` (live gates: `PermissionVoterDoesNotEvaluateSubjectTest`,
  `AuthorizationCoreIsClosedForModificationTest`).
- Re-propose an `OWNER` rung, a delegation matrix, or type-to-confirm as *the* control — rejected in #505.
- Close peer-draining to a floor of one (A demotes B, then C). The row makes it visible, not impossible.
- Claim a five-year evidentiary window: `security` rows carry a 365-day ceiling and are pruned; the 5-year floor
  covers `change` rows only.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|---|---|---|---|
| Admin invites with ADMIN | actor holds `users.invite` + `users.grantAdmin` | 201; identity + membership + invitation created | N/A |
| May invite, may not grant ADMIN | actor lacks `users.grantAdmin`; payload has `"ADMIN"` | 403 `forbidden`; nothing written | `AccessDeniedException` → RFC 9457 bridge; existing listener writes `ACCESS_DENIED` |
| Same actor, non-admin roles | payload `["EDITOR"]` | 201 — the check does not fire | N/A |
| Role change grants ADMIN without the permission | `PATCH …/roles` body has `"ADMIN"` | 403 `forbidden`; roles unchanged, no audit row | as above |
| Role change succeeds | requested set differs from held | 200 detail resource + one `USER_ROLES_CHANGED` row at `SECURITY` | N/A |
| Redundant role set | requested set equals held set | 200, no write, **no audit row**, no session teardown | N/A |
| Refused demotion | last active administrator demoted | 409 `last-active-administrator-protected`, **no audit row** | existing guard |
| Revoke a pending invitation | user has ≥1 `SENT` invitation | 204; every `SENT` invitation → `REVOKED`, one `InvitationRevoked` each, all in one transaction | N/A |
| Concurrent accept during revoke | invitee accepts between the lock and the write | the accepted row leaves the locked set (predicate re-evaluated after the lock); the rest still revoke; 404 only if none remained | no `InvalidInvitationTransition`, no rollback of the others |
| Nothing revocable | user is ACTIVE, already revoked/expired, or unknown | 404 `revocable-invitation-not-found`, context `{userId}` | new `NotFound` |
| Malformed user id | non-UUID path segment | 400 `invalid-uuid` | `Uuid::ensure` before any lookup |

</frozen-after-approval>

## Code Map

- `PermissionCatalog.php` (13 entries) + `StaticAuthorizationPolicy.php:69` -- the two data edits; the completeness gate asserts catalog ⊇ gated routes, so declaring ahead of a route is legal.
- `Iam/Invitation/Infrastructure/Http/CreateInvitationController.php` · `Iam/Identity/Infrastructure/Controller/UserPatchRolesController.php` -- enforcement points; both already map wire→enum in `rolesFrom()`.
- `Iam/Identity/Application/FulfilIdentityErasure.php:100-107` -- owns the public `SUBJECT_RESOURCE_TYPE` the audit row must reach through.
- `Iam/Invitation/Domain/Repository/InvitationRepository.php` -- 4 methods; `invited_user_id` is indexed (`idx_iam_invitation_invited_user_id`) but **not unique**.
- `api/config/routes.yaml:26-31` -- already mounts the Invitation `Http/` dir at `/api/v1/backoffice`; no routing change.
- `pwa/.../access/domain/Permission.ts` -- closed list; the `/me` adapter silently drops anything not declared here.
- `pwa/src/components/erpify/DeleteResourceButton.tsx` -- destructive-confirm primitive; `BankRowActions.tsx` + `useResourceList` are the reference wiring.

## Tasks & Acceptance

**Execution:**

- [ ] `PermissionCatalog.php` -- add `users.grantAdmin`, `users.revokeInvitation`.
- [ ] `StaticAuthorizationPolicy.php` -- both rows `=> [Role::ADMIN->value]`.
- [ ] `CreateInvitationController.php` -- inject `AuthorizationCheckerInterface`; add `private function grantsAdmin(array $roles): bool` beside `rolesFrom()` and, when it holds and `users.grantAdmin` is not granted, throw `AccessDeniedException('You may not grant the ADMIN role.')` -- the named predicate is what documents *why* the check fires; the message travels as the RFC 9457 `title`, so it names no internals.
- [ ] `UserPatchRolesController.php` -- same guard, same predicate name, same message.
- [ ] `ChangeUserRoles.php` -- inject `AuditLogger`; inside the transaction, after `publish()`, log `USER_ROLES_CHANGED` at `AuditLevel::SECURITY` with `AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $userId)` and metadata **exactly** `{previous_roles, new_roles}`, both `canonical()`-normalised (sorted, deduped) -- a literal `'User'` turns `php.lint.audit-resource` red (Design Notes).
- [ ] `InvitationRepository.php` + Doctrine adapter -- add `findSentByInvitedUserForUpdate(string $userId): list<Invitation>` -- `SENT` is the only revocable state, and the read **takes a pessimistic lock**, mirroring `AcceptInvitation`'s `findByIdForUpdate`; see Design Notes for why an unlocked read is worse than partial revocation.
- [ ] `Iam/Invitation/Domain/Exception/RevocableInvitationNotFound.php` -- new `NotFound`, context `{userId}`; reusing `InvitationNotFound` would report an id the caller never supplied.
- [ ] `RevokeInvitation.php` -- add `revokeForInvitedUser(string $userId): void`: `Uuid::ensure`, then **one** transaction revoking every locked `SENT` invitation of that user and publishing each aggregate's events; empty set → `RevocableInvitationNotFound`. Partial revocation must be impossible — a failure on the n-th rolls back the previous ones.
- [ ] `Iam/Invitation/Infrastructure/Http/RevokeUserInvitationController.php` -- `DELETE /users/{userId}/invitation`, `#[IsGranted('users.revokeInvitation')]`, `ROUTE_NAME` const, 204 empty body.
- [ ] `tests/Unit/.../Security/{PermissionCatalogTest,StaticAuthorizationPolicyUsersResourceTest,PermissionResolverTest}.php` -- extend the hard-coded vocabularies; add the missing `changeRoles` case to the users-resource provider (boy-scout — name it in the commit).
- [ ] `tests/Unit/Iam/Identity/Application/ChangeUserRolesTest.php` -- restore the four audit assertions from `89e9f466` via `RecordingAuditLogger`, and **freeze the metadata contract** with one `assertSame` over the whole array (exact keys, canonical order) so a later `oldRoles`/`roles_before` variant fails the build: both sets recorded; no credential/email; a reordered-but-equal set writes nothing; refused demotion writes nothing.
- [ ] `tests/Unit/Iam/Invitation/Application/{RevokeInvitationTest,InMemoryInvitationRepository}.php` -- cover the user-keyed path: N=0 (404), N>1 (all revoked, one transaction), and a mid-set failure leaving nothing revoked.
- [ ] `tests/Functional/Iam/Identity/.../{UserPatchRolesFunctionalTest,PermissionVoterAccessDecisionTest}.php` -- 403 for an actor holding `users.changeRoles` but not `users.grantAdmin`; non-ADMIN payload still succeeds.
- [ ] `features/backoffice/users/roles.feature` -- re-add the audit-row SQL assertion (happy path) and the zero-row assertion (refusal); re-measure the budget at `:42`.
- [ ] `features/backoffice/identity/invitation_create.feature` -- add the "may invite, may not grant ADMIN" 403 scenario; **fix the false budget justification at `:34-37`** — nothing on this path writes an audit row (only `Bank`/`BankAccount` implement `AuditedEntity`; `AuditPolicy` audits `GET` only); re-measure the 26.
- [ ] `features/backoffice/identity/invitation_revoke.feature` -- new: 204 from the seeded `SENT` fixture, 404 nothing revocable, 403 non-administrator, 401 anonymous, plus a query budget.
- [ ] `pwa/.../access/domain/Permission.ts` -- add `USERS_GRANT_ADMIN`, `USERS_REVOKE_INVITATION`; without these the gates never open.
- [ ] `InviteUserForm.tsx` -- render the ADMIN checkbox only when the session holds `users.grantAdmin`.
- [ ] `pwa/src/context/backoffice/user/{domain/RevokeInvitationRepository.ts,infrastructure/ApiRevokeInvitationRepository.ts,application/RevokeInvitation.ts}` + `ApiEndpoints.ts` + `Container.ts` -- the five-file adapter shape the sibling use cases follow.
- [ ] `UserRowActions.tsx` (+ `UsersTable`/`UsersCards`/`UsersStackedList`/`UsersListView`) -- overflow menu with a destructive "Revoke invitation", gated on `users.revokeInvitation` **and** `status === INVITED`; confirm via the `DeleteResourceButton` shape; success → `silentReload()` (the row stays, its status changes); failure → the list-level `MutationError`. Rewrite the docblock, which currently asserts the row affords no capabilities.
- [ ] `pwa/eslint.config.mjs` -- extend the row-menu race selector so `__revoke-` is covered like `__delete-`.
- [ ] `pwa/tests/app/backoffice/users/` -- gate truth-table tests for the ADMIN checkbox (copy the `usersListPage.test.tsx` `AuthProvider` fixture); row-action tests via the retrying `openRowDeleteItem` helper; adapter test mirroring `ApiInviteUserRepository.test.ts`.
- [ ] `pwa/tests/e2e/backoffice/users-revoke-invitation-real-api.spec.ts` -- invite → revoke → the row reflects it, against the live stack.
- [ ] `IdentityStatus.php` · `User.php` · `UserInvitationRevoked.php` · `UserChecker.php` · `RevokedAccountException.php` -- add the terminal `INVITED → REVOKED` state and its event; wall it pre-authentication with the same timing floor as `INVITED`, enumerated rather than defaulted so a state added later fails the build instead of falling through into admission.
- [ ] `RevokeInvitation.php` -- withdraw the identity in the same transaction as the invitations, once per call; allowlist + deptrac entries in the published `Invitation → Identity` direction.
- [ ] `pwa/.../UserStatus.ts` · `UserStatusBadge.tsx` · `userLabels.ts` -- the status vocabulary; the `Record<UserStatus, T>` maps make an omission a build failure.
- [ ] `docs/adr/identity-invitation-lifecycle.md` -- amend D3 (the machine gains a fifth case, with why it is neither `DEACTIVATED` nor a deletion), D4 and the error-contract list (both credential-less states are walled uniformly pre-identity), and D5 (revocation is one transaction over both aggregates).
- [ ] `docs/adr/authorization-model-boundaries.md` -- amend the three defects (Design Notes).
- [ ] `PRODUCTION_SECURITY_CHECKLIST.md`, `docs/architecture-api.md`, `docs/index.md`, `api/docs/` -- restore what `50ed6708` downgraded (true again) and document the new endpoint; keep the retention claim at 365 days.

**Acceptance Criteria:**
- Given an actor holding `users.invite` and `users.changeRoles` but not `users.grantAdmin`, when they submit any payload containing `ADMIN` to either endpoint, then the response is 403 `forbidden`, nothing is persisted, and an `ACCESS_DENIED` row exists.
- Given the same actor, when the payload carries only non-ADMIN roles, then the request succeeds — the check is conditional on the payload, never on the route.
- Given a completed role change, when the transaction commits, then exactly one `USER_ROLES_CHANGED` row exists at `security` level with `resource_type='User'`, `resource_id` = the subject, and metadata carrying both role sets and no credential or email.
- Given an erased identity, when the erasure commits, then no `audit_log` row still names that person on either axis — `erase.feature`'s existing assertions stay green with the new row in play.
- Given an administrator viewing an `INVITED` row, when they confirm "Revoke invitation", then every `SENT` invitation of that user becomes `REVOKED`, the accept token stops working, and the row refreshes without a full page reload.
- Given a user with several `SENT` invitations, when revocation fails on any of them, then none is revoked and no `InvitationRevoked` event is published — the operation is all-or-nothing.

## Spec Change Log

- **Revocation left an identity stranded at `INVITED` (found during implementation, resolved by the human).**
  Nothing subscribes to `InvitationRevoked`, so pulling an invitation killed the token — verified, `AcceptInvitation`
  refuses any status other than `SENT` — while `identity_user` kept a row at `INVITED` carrying the roles being
  granted, `ADMIN` included, permanently. The console could not tell a live invitation from a revoked one, because
  the register row carries only `status`. Three options were measured: hard-delete (drags in the person-reference
  erasure axis, and `FulfilIdentityErasure` refuses subjects holding `ADMIN` — precisely this case), a lifecycle
  state, or leave it. **The human chose the lifecycle state**, so `IdentityStatus::REVOKED` and the guarded
  `INVITED → REVOKED` transition are in scope. KEEP: the seam direction is unchanged — the identity is withdrawn
  from inside `Iam/Invitation` through `UserRepository`, the shape the accept path already uses, so no
  `Identity → Invitation` dependency was created. KEEP: the pre-authentication wall enumerates every state rather
  than defaulting, which is what stops the next state added from silently reaching admission.
  **Known deviation from the frozen block:** its revoke rows describe only the invitation side. The frozen intent is
  human-owned and was not edited; the behaviour now also flips the identity to `REVOKED`.

## Design Notes

**First imperative authorization check in `api/src`** (zero uses of `AuthorizationCheckerInterface` / `isGranted(`
today). Two controllers get a two-line guard, injected directly, with **no shared guard type** — it would have to
live where both `Iam/Invitation` and `Iam/Identity` can import it, minting a seam for a string literal, and Rule of
Three says two call sites need no abstraction. Accepted consequence:
`PermissionCatalogCoversEveryGatedRouteTest` discovers routes by reading `#[IsGranted]`, so it is blind to an
imperative check; `users.grantAdmin` is pinned by functional/Behat 403 tests instead. File a follow-up issue so a
later sweep also detects imperative `isGranted()` authorizations; do not build it here.

**The revoke read takes a pessimistic lock, and that is not a micro-optimisation.** `AcceptInvitation` already
locks (`findByIdForUpdate`); `RevokeInvitation` does not. With an unlocked read, an invitee accepting concurrently
does not cause a *partial* revocation — it causes `InvalidInvitationTransition` and a full rollback, so the
incident responder revokes **nothing**. Under `FOR UPDATE` in READ COMMITTED, Postgres re-evaluates the predicate
after the lock, so a row that became `ACCEPTED` simply drops out of the `SENT` set and the rest still revoke.

**The audit metadata contract is frozen at `{previous_roles, new_roles}`**, both canonical (sorted, deduped). Role
sets are already compared as normalised sets by `ChangeUserRoles::alreadyHolds()`, and the row is only written when
that comparison found a real change — so a reordered set can never produce a spurious entry. There is no central
registry for audit metadata schemas; the convention is an exact-key assertion in the unit test, and that is what
holds this one still.

**The gate trap on the audit row.** `PersonResourceErasureGateTest` asserts a person type is carried only by its
declared erasure owner, matching the single-quoted literal over `src`. Re-landing `AuditResource::of('User', …)`
verbatim makes `ChangeUserRoles` a second carrier and turns `make php.lint.audit-resource` red — hence the
constant, which is `public` for exactly this reason and already used that way by the reconciler.

**Revoke keyed by user id, revoking all `SENT` invitations.** Resolving inside `Iam/Invitation` costs zero seams;
surfacing the invitation id through the Identity-owned register would mint an `Identity → Invitation` read seam and
break the register's pinned 6-field contract and query budget. Nothing guarantees one invitation per user —
`invited_user_id` has a plain index and no uniqueness, and the port's docblock says so. Under incident response,
refusing on N>1 would leave live tokens. An invitation accepted between click and request yields 404; the reload
then shows `ACTIVE`, which is the true state and the responder's cue. Full measured comparison of the three options
is in `tmp/pr-body-505-argument.md`, which also carries the #549-ordering measurement and the security-review notes
for the PR body.

**ADR amendment — three defects, one of which changes sign:**
1. `:19-22` "would have been a no-op" is true of the pre-#550 superuser ADMIN and false on `main` (`TIER_OPT_OUT`
   makes `grantedByTier()` return false before any wildcard). **The same claim is reused as a premise in D2
   (`:65-68`)** — correct that occurrence too; D2's conclusion survives on its org-scoping argument alone.
2. `:111` lists `USER_ROLES_CHANGED` among emitted rows while `:143-146` says the demotion leaves no attributable
   record. Before this PR the second was right; **after it the first becomes right** — rewrite D3's tail
   (`:136-158`): its revisit trigger has fired and the erasure refusal becomes the traceability control it was
   written to be.
3. Keep `:109-115` intact — `security`, pruned at 365 days. Do not reintroduce the five-year overclaim.

## Verification

From inside the worktree (it has its own Compose stack); report each exit code from a fresh run:
- `make php.stan` -- exit 0 on every changed PHP file.
- `make php.lint.audit-resource` -- exit 0; proves `'User'` is still carried only by its declared erasure owner.
- `make php.lint.person-reference`, `make php.lint.bounded-context`, `make php.deptrac` -- exit 0 with **no new
  `Identity → Invitation` entry** in the allowlist or in deptrac's `skip_violations`; one in that direction means
  the seam decision was violated. Entries in the published `Invitation → Identity` direction are expected — the
  revocation withdraws the identity through `UserRepository`, exactly as the accept path already does.
- `make php.unit`, `make php.behat` -- exit 0; report the **re-measured** query budgets rather than assuming.
- `make php.quality`, `make pwa.quality`, `make pwa.test` -- exit 0.
