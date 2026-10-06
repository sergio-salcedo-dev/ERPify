/bmad-build Continue PR #1037 (branch `ccr-19f35fd1-elkaj0`): implement option B — a deferred corrective re-sweep of the GDPR erasure's anonymising passes — chosen by the product owner (Sergio) on 2026-10-06 to replace the enumerated erasure-window acceptance in PRODUCTION_SECURITY_CHECKLIST.md §7. Speak Spanish to Sergio; write code, docs and commits in English.

WHY: three review rounds each found more writers that can commit a person's id after the erasure's anonymising passes (a request already in flight when the erasure starts: access log + 4 request-boundary audit listeners, StartSession, RevokeSession/RevokeOtherSessions/RevokeAllSessions — the last also after an admin role/status change). §7 accepts "every writer listed", an enumeration proven incomplete each round — the shape this repo rejects (CLAUDE.md: Caddy `query` filter, sensitive-command lists). B replaces "accepted for ever" with "bounded to N minutes and self-correcting", by mechanism, independent of who writes.

DONE (all pushed to origin/ccr-19f35fd1-elkaj0, PR #1037 open, CI green up to 177ff74):
- DW-16/22/24/30/39/46/48/63/65 resolved; three code-review rounds applied (last: bmad-code-review on e4f560b..24c50b6 → 70477c2 records, 177ff74 AuthProvider probe-race fix).
- §7 erasure-window item lists the writers and records Sergio's acceptance (2026-10-03 audit rows + sign-in; 2026-10-06 session revocations).
- New CLAUDE.md comment rule: residuals/acceptances go to a register (§7, the owning ADR, deferred-work.md), never a docblock.

IN PROGRESS: nothing uncommitted. Option B not started.

NEXT:
1. Before any code, present Sergio these design decisions with trade-offs (GDPR persistence calls are his) and wait:
   a. Trigger source: drive the re-sweep from what already survives erasure — check whether `dek_keystore` tombstones (`encryption_scope_id`, `destroyed_at`, index `dek_keystore_destroyed_idx`) identify the erased subject; if so no new table is needed. Otherwise a short-lived "pending re-sweep" table holding the subject id, classified `person :: <its sweeper>` in api/.person-reference-policy with `#[PersonSubjectReference]` and a PersonReferenceSource, deleted by the sweep.
   b. Pseudonym for late rows: a real-id→pseudonym table and a deterministic pseudonym are both VETOED (audit-activity-log.md D4; event-store-and-projections.md D12, lines ~91-92). So late rows get a FRESH pseudonym, which breaks stream linkability between the original pass and late rows — confirm that is acceptable.
   c. Delay N and cadence (a scheduled sweep, never a delayed Messenger message: queuing a person id is forbidden — CLAUDE.md "Queuing an event about a person", api/.persistent-transport-policy).
2. Implement: a scheduled maintenance message (new #[AsSchedule] or an existing schedule — then wire its transport in BOTH compose.yaml and compose.prod.yaml, `make php.lint.schedule-consumption`) that re-runs, for subjects erased in the window, the idempotent passes: AuditActorAnonymiser, AuditResourceAnonymiser, EventStoreSubjectAnonymiser (and confirm whether PurgeUserSessions needs a re-run). Any new UPDATE on audit_log/event_store must be added to SANCTIONED in SanctionedLogMutationGateTest + the ADR (D12 / D4).
3. Tests: functional — commit an erasure, then insert a late audit row and a late event naming the subject, run the sweep, assert both rewritten; idempotency (second run changes nothing); a subject outside the window untouched.
4. Records: rewrite the §7 item from "accepted for every writer listed" to the class, bounded by the re-sweep window; ADR decision (audit-activity-log.md D4 and/or event-store D12); docs/architecture-api.md; deferred-work.md entry if anything is left.
5. Gates: make php.stan, make php.quality, make php.unit, make php.behat (fresh runs, exit codes), then the three-layer review CLAUDE.md requires for GDPR surface (bmad-code-review on the new range), apply patches, commit + push, update the PR #1037 body.

ARTIFACTS (re-read first): CLAUDE.md (Required checks: "Persisting a person's id", "Queuing an event about a person", "Declaring an #[AsSchedule]", "Mutating event_store or audit_log beyond append", "Code comments"); PRODUCTION_SECURITY_CHECKLIST.md §7 (item "A row naming the subject, written by a request already in flight…"); docs/adr/audit-activity-log.md (D4, D7); docs/adr/event-store-and-projections.md (D12, D14); docs/adr/identity-invitation-lifecycle.md (D8); _bmad-output/implementation-artifacts/deferred-work.md (DW-24, DW-48, DW-64, DW-65); PR #1037 description.

KEY PATHS:
- api/src/Iam/Identity/Application/FulfilIdentityErasure.php (erasure orchestration: identity_user FOR UPDATE → passes → purges, one transaction)
- api/src/Iam/Identity/Application/EraseIdentitySubject.php, api/src/Iam/Identity/Infrastructure/Cli/EraseIdentitySubjectCommand.php
- api/src/Shared/Audit/Application/{AuditActorAnonymiser,AuditResourceAnonymiser}.php + Infrastructure/Persistence/Dbal*Anonymiser.php
- api/src/Shared/Event/Application/EventStoreSubjectAnonymiser.php + Infrastructure/Persistence/DbalEventStoreSubjectAnonymiser.php
- api/src/Iam/Session/Application/PurgeUserSessions.php
- api/src/Iam/Identity/Application/ReconcileErasedSubjectReferences.php, api/src/Shared/Audit/Infrastructure/Persistence/DbalSubjectErasureReconciler.php (reads dek_keystore)
- api/src/Shared/Crypto/Infrastructure/Persistence/KeystoreSchemaListener.php (dek_keystore)
- Schedules: api/src/Iam/Identity/Infrastructure/Messenger/Maintenance/IdentityMaintenanceSchedule.php, api/src/Shared/Audit/Infrastructure/Messenger/Maintenance/AuditLogMaintenanceSchedule.php
- Gates/registries: api/tests/Unit/Gate/SanctionedLogMutationGateTest.php, api/.person-reference-policy, api/.persistent-transport-policy

DECISIONS/CONSTRAINTS:
- Option B chosen by Sergio 2026-10-06; options A (lock at the sink — deadlock risk: writers reach the sink holding session-row locks while the erasure locks identity_user first) and C (completeness gate only) were declined.
- Same branch and PR (#1037); never push to main, never merge without explicit per-merge permission.
- Work in a worktree on the EXISTING branch (not `make worktree.create`, which mints a new branch): `git worktree add .claude/worktrees/erasure-resweep ccr-19f35fd1-elkaj0`.
- BMAD skills are gitignored: on a fresh clone install them first (`npx bmad-method@6.12.0 install --directory . --modules bmm --tools claude-code --yes --user-name Sergio --communication-language Spanish --document-output-language English --output-folder _bmad-output`) or `make bmad.skills.sync` on the primary.
- Delete this handoff file in the PR that implements B (it is a working artifact).
