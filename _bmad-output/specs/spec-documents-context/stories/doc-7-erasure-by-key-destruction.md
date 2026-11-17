---
title: 'doc-7: Erasure by key destruction'
type: 'feature'
created: '2026-11-17'
status: 'in-review'
baseline_commit: '801caedb4160b4c9e9f2b6d57d8e65b5b38378b0'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/specs/spec-documents-context/SPEC.md'
  - '{project-root}/docs/adr/documents-custody-log.md'
  - '{project-root}/docs/vps-deployment.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** CAP-4 does not exist. Nothing can erase a document: there is no `Erased` transition, no key destruction path for a Document scope, no way out for a `Quarantined`/`ScanFailed` document, no object deletion, and nothing stops a restored database from bringing a destroyed key back (the ADR's key-resurrection hazard). `ErasureLedgerOverride` is bound to a never-set stub, so doc-5's read gate has no real flag.

**Approach:** One ADMIN-only erase route over one use case that writes an erasure-ledger entry (restic repository on a second host, append-only, behind an application port), then in ONE transaction takes the locks, compare-and-sets the row to `Erased` (name ciphertext nulled), appends its custody row and destroys the key. Sweeps finish what a crash left (ledger completion, sealed-object deletion) and a Document-driven reconciler alarms on a tombstoned non-`Erased` row. `restore.prod` replays the ledger before anything is served, and a DB-flag override is the only way past a lost ledger.

**Contract:** `stories.yaml` entry `doc-7` is the owner's contract and prevails over any summary here; every rule in its `description` and `invoke_dev_with` applies verbatim. This file adds the code map, the decisions below and the order of the work.

## Boundaries & Constraints

**Always (decided, the owner and the assistant, 2026-11-17; the first three confirmed by the user in this session):**
- **One story, one PR** (the user): the erase use case, the ledger, the sweeps, the reconciler, the `restore.prod` change and the runbook ship together. The real restore drill needs the owner's second host: this PR delivers the code, the tests against a local rest-server and the drill runbook; **the `drill` proof record is committed by the owner after running it**, and the story is not `done` until it is (doc-5's `Done checkpoint` waits on it).
- **The erase route accepts every non-terminal state** (the user): `Received`, `Scanning`, `Ready`, `Quarantined`, `ScanFailed`. Only `Erased` is terminal; a repeat answers 200 as the first call did, writes no ledger entry and appends no custody row.
- **Replay and completion rows carry `actor_id` NULL = the system** (the user, after a consultation with codex that agreed): `ErasedPersonMarker` keeps its one meaning, "a person who was erased", and `DocumentCustody` keeps refusing it. SPEC (ledger bullet), ADR D1 and `stories.yaml` are amended in this PR; the reasons `ledger-replay` / `ledger-completion` are stored in `document_custody.reason`.
- **Route:** `POST /api/v1/documents/{id}/erase`, name `documents_erase` (constant in `UploadRoutes`, never `shared_`-prefixed), `#[IsGranted(DocumentPermission::ERASE)]`, `StrictRequestPayload` DTO `{reason, currentPassword}`. `document.erase` is a `PermissionCatalog` entry reached through the ADMIN wildcard, exactly as `document.rescan` (no `EXPLICIT_GRANTS` row, no tier touched). The operator is the custody `actor`. **Re-proof (renegotiated by the user, 2026-11-17, after Winston's review: the act is irreversible even from a backup, enumerable through doc-6's listing, and evicting a stolen session undoes nothing):** the controller spends the shared `CurrentPasswordProofThrottle` budget and re-proves the caller's current password (`ProveActorPassword`, as the authority routes do) before the use case and before any ledger write; the route is classified `documents_erase :: credential-affecting` in `api/.credential-proof-policy`, the gate's pinned set of fixed routes grows from six to seven, and one sentence is added to the root `CLAUDE.md` rule and to the ADR. `backoffice_user_erase` (also `ordinary`, no proof) is named as the same gap and is NOT fixed here. `api/.route-manifest.json` regenerated.
- **Two reason enums.** `ErasureReason` (HTTP-bound, closed, no free text): `data_subject_request`, `legal_obligation`, `uploaded_in_error`, `retention_expired`. `ErasureSystemReason`: `ledger-replay`, `ledger-completion`. A test asserts the HTTP enum contains neither system value. `CustodyEvent` gains `Erased = 'erased'` (no migration: the column has no CHECK); `DocumentCustody::ensureActorAndReasonFitTheEvent()` requires, for `Erased`, a reason from exactly one enum and the actor to be non-null iff that reason is `ErasureReason`.
- **Order inside the use case:** voter → `Uuid::ensure` → load (404) → `Erased`? answer → **ledger write** (no DB lock held, bounded timeout) → one transaction: `SET LOCAL lock_timeout`/`statement_timeout` to the bound below, lock the intent by scope (new `lockByScope`, if one exists), lock the document row, `Keystore::lockState()`, the **Erased compare-and-set** (its own class beside `DbalDocumentScanLeases`: `UPDATE … SET state='erased', filename_ciphertext=NULL … WHERE id=:id AND state IN (non-terminal)`, `=== 1` is the only decider; it never touches lease or scan columns and never sets a slot), `AppendCustody(Erased, actor, reason)`, `Keystore::destroy(scope, Erasure)` (its boolean decides nothing; an `Absent` scope is tombstoned too, which `EraseBankAccountSubject`'s early return must not be copied for). Lock order: intent, document, keystore. It cannot cycle with the existing writers: the upload commit and the reaper take intent then keystore (the document row does not exist yet in the commit, `CommitUpload`/`ReapUploadIntents`), so sharing the intent lock first serializes every path that could meet the keystore row, and the scan CAS takes only the document row; `lockByScope` therefore runs BEFORE the document lock, and a functional test asks the database which locks are held at each step. `lockState()` on an `Absent` scope locks nothing, so the tombstone's safety against a concurrent `store()` rests on `destroy()`'s atomic upsert (first writer wins) — a test races a seal against the erase for an unminted scope and expects `DekDestroyed` or a tombstone, never a live key. `AppendCustody` re-locks the document inside the outer transaction through a nested unit (a savepoint, `DoctrineTransactionManager`): the outer transaction keeps the row lock, and a test asserts a custody-append failure rolls back the CAS and the tombstone together. Erasure never waits on, steals or counts a scan.
- **Ledger semantics:** the port `ErasureLedger` (Application) and the restic adapter (Infrastructure) hold scope ids only; entries are at-least-once and deduplicated by scope id; a failure before the entry is durable leaves the document untouched (a retryable problem type); a timed-out write is outcome-unknown (a distinct problem type telling the caller the erasure may still complete) and a landed entry completes forward. Restic runs in array form through `Process`, with the repository password and REST credentials from environment variables or secret files, never URL or argv; the exception that leaves the adapter is sanitised.
- **restic ships in the php image** (decided here, as the story asks): the erase request, the completion sweep and the restore command all run PHP, a sidecar would need its own exec/HTTP surface for no gain, and the binary is pinned by version and checksum in `api/Dockerfile`. Discarded: a sidecar service.
- **Provisional constants** (spike-confirmed, each a named constant with its justification): ledger write timeout 10 s; erasure transaction bound `lock_timeout` 5 s and `statement_timeout` 10 s; completion sweep ignores entries younger than write timeout + transaction bound + 60 s margin (80 s) judged from the snapshot timestamp (VPS clock); sealed-object alarm bound 1 h measured from the `Erased` custody row; object-deletion sweep every 60 s, completion sweep every 5 min, tombstone reconciler hourly.
- **The replay service is separate** (`ReplayErasure`, never a parameter of the controller's use case): skips the ledger write, records `actor NULL` and the system reason, deletes an object under a ledger scope with no row. A reflection gate test asserts the classes whose constructors depend on it are exactly the restore command and the completion-sweep handler.
- **Sealed object:** the object-deletion sweep is the single owner (staged path first, then final, ENOENT tolerated on each, a row done only when neither exists), recorded by a new nullable `document.object_deleted_at` (read-only mapped like `promoted_at`; one migration; partial index over `state='erased' AND object_deleted_at IS NULL`). A failed delete never rolls the erasure back. It is the one deletion in the final namespace besides the replay's, and it is disabled while a restore holds the stack.
- **Override:** a table `erasure_ledger_override` (id, set_at, cleared_at, `reason` closed enum, `record_ref` a random UUID the script mints and prints, classified `non-person` in `api/.person-reference-policy`) holds **no name, label, OS user or key fingerprint** (decided after a second consultation with codex, 2026-11-17: any of those is personal data about an operator in a table no erasure path owns, and a label typed on a restored host proves nothing about who typed it): the operator writes the printed `record_ref`, their identity and the date in the off-VPS operator log that `docs/vps-deployment.md` gate 3 already prescribes (personal data, deleted when the last snapshot older than its entries has expired), which is where "who" lives. Discarded: a typed `operator_label`, the host user plus SSH fingerprint, an authenticated HTTP identity (php is stopped in recovery mode). The table replaces `NeverSetErasureLedgerOverride` with a DB adapter bound to the same alias, so no controller changes: doc-5's read route and doc-6's two routes answer 410 `gone` while an uncleared row exists. It clears only when a completion run replayed the whole ledger AND the Document-driven reconciler reports no non-`Erased` row whose scope is in the ledger — a necessary condition, never a sufficient one (renegotiated by the user, 2026-11-17, after codex and Winston agreed that for a new empty repository it holds vacuously and reopens reads over keys nobody replayed): **no reason clears by itself.** An explicit `documents:erasure:clear-override` command, with no person or scope on argv, checks those same preconditions, clears only the rows it saw set before it read the ledger (never an unbounded `UPDATE`, so a `set-override` racing it is not cleared unchecked) and prints their `record_ref`; and while any override is uncleared the completion tick writes one `error` line (age and count only) so a standing 410 is never silent.
- **Registries and docs in this PR:** `api/.audit-evidence-actions` decision if an action is audited, `AuditMetadataKey` only if a key is added; `docs/api-error-contract.md` for every new problem type (`make php.lint.error-contract`); `BestEffortReportChannelGateTest::REPORTERS` for each new report; `api/.behat-step-vocabulary` for each new step; `ErasureLockOrderTest` is NOT extended (the content erase is not the person purge) — a new lock-order functional test is; `docs/adr/documents-custody-log.md` gains D11 (ledger, replay seam, override, lock order, constants) and its status line; `docs/architecture-api.md`, `docs/source-tree-analysis.md`, `docs/claude-code-quickref.md`, `api/CLAUDE.md`, root `CLAUDE.md` Required-checks bullet for the new `php.lint.*` target; `docs/vps-deployment.md` (gate 3 "Re-erase after restoring", "The future Documents module widens this", the restore/drill sections) and `PRODUCTION_SECURITY_CHECKLIST.md` §7 (the five residuals in the story, and the item "A restore from an older backup resurrects keys destroyed since" rewritten for a ledger on a second host), found by heading.
- **Registry decisions made rather than left to CI:** `record_ref` (and any other GUID column of the override table, if it is an entity) is classified `non-person` in `api/.person-reference-policy` and `object_deleted_at` is a timestamp, not a GUID, so it needs no line; `api/.persistent-transport-policy` is untouched (no `DomainEvent` is declared by `Document`); the restic adapter's `Symfony\Component\Process` import needs a `Vendor.Process` layer allowed for `Documents.Infrastructure` in `api/tools/deptrac/deptrac.yaml` (none exists today), added with the adapter; `make php.lint.document-custody` stays green because the only `document_custody` write is the `AppendCustody` insert.
- **Contract deviation, disclosed:** `stories.yaml` doc-7 names the nil-UUID marker as the replay/completion actor; this story replaces it with NULL (see above) and edits that line of `stories.yaml`, SPEC and ADR D1 in the same PR. The completion sweep deletes no object of a scope without a row (the contract assigns that to the replay only), and the operator of a decision is the custody actor, with no operator column anywhere.
- **Compose gates hold:** every new service/worker keeps `logging:` bounded, `ulimits core 0` where it unseals, schedule transports named in both consume commands (`php.lint.schedule-consumption`), new `${VAR:?}` in `.env.prod.example`.

**Never:**
- A console command or argv carrying a person's id or name; a person event on a persisted transport; the Document aggregate on `async`.
- The ledger write inside a transaction or under a lock; `destroyScope()`'s boolean as the erasure verdict; a second deleter of the sealed object (no inline delete in the erase request); relaxing `DocumentCustody` to admit the marker.
- Opening `documents.read_enabled` or `documents.ingest_enabled`, or writing a `documents.backup_mechanism` value: those are human acts after the drill.
- Marking a ledger entry, the override or the drill as proven by a test alone: the real drill is the owner's.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Erase Ready | ADMIN, `Ready` doc, ledger up | ledger entry durable, then one tx: `Erased`, name NULL, custody row, tombstone; 200 | N/A |
| Erase from each non-terminal state | `Received`, `Scanning`, `Quarantined`, `ScanFailed` | same as Ready; a `Scanning` worker's later verdict matches no predicate | N/A |
| Repeat | already `Erased` | 200, no ledger write, no custody row, no second tombstone | N/A |
| Unminted scope | `Received` doc with no key yet | tombstone written for the absent scope, row `Erased` | N/A |
| Ledger unreachable | write fails before durable | document untouched | retryable problem type (503) |
| Ledger write times out | outcome unknown | document untouched by this call | distinct problem type: "erasure may still complete"; the sweep completes a landed entry |
| Crash after ledger, before tx | entry landed, row not `Erased` | completion sweep (after the margin) replays: `Erased`, custody `ledger-completion`, actor NULL | N/A |
| Fault inside tx (after CAS, before destroy) | injected | whole tx rolls back; row unchanged; retry succeeds | N/A |
| Crash after commit | object still on disk | object sweep deletes staged then final, sets `object_deleted_at`; alarm only past 1 h | failed delete: row stays pending, never rolls back |
| Wrong role / no session | EDITOR/VIEWER/none | 403 / 401, nothing written | existing contract |
| Reason outside the enum, or a system reason | `ledger-replay` over HTTP | 422 `validation-failed` | N/A |
| Malformed / unknown id | `x` / random | 400 `invalid-uuid` / 404 `document-not-found` | existing contract |
| Restore, ledger readable | `restore.prod` | stack held, ledger replayed (rows `Erased`, scopes tombstoned, objects deleted), only then writers/sweeps restart | N/A |
| Restore, ledger unreadable or one entry corrupt | `restic check --read-data` non-zero or an `Ignoring` line | stays in recovery mode, writers and sweeps off | operator must use the override procedure |
| Override active | uncleared row | doc-5 read and doc-6 list/custody answer 410 `gone` for any well-formed id | N/A |
| Tombstoned non-`Erased` row | scope destroyed, state not `Erased` | one `error` line, ids and counts only | alarm, whatever the ledger says |

</frozen-after-approval>

## Code Map

- `api/src/Documents/Document/Domain/CustodyEvent.php`, `Domain/Entity/DocumentCustody.php:77-89,148` -- add `Erased`; extend the actor/reason fit rule; the marker stays refused.
- `api/src/Documents/Document/Domain/DocumentState.php`, migration `Version20261105202636` -- `Erased` already in the state CHECK; no state migration.
- `api/src/Documents/Document/Infrastructure/Persistence/Doctrine/DbalDocumentScanLeases.php:30,225,243,263` -- template for the Erased CAS (`executeStatement === 1`); put the new CAS in its own class, since the repository's single `UPDATE document` belongs to the person-purge lock-order gate.
- `api/src/Documents/Document/Application/AppendCustody.php`, `Scanning/RescanDocument.php`, `Scanning/ScanDocument.php:258-268` -- custody append under the row lock and the operator-action template (CAS then append with actor and reason); the transaction manager nests.
- `api/src/Documents/Document/Infrastructure/Controller/RescanDocumentController.php`, `Infrastructure/Http/RescanDocumentRequest.php`, `UploadRoutes.php:20`, `Infrastructure/Security/DocumentPermission.php`, `api/src/Iam/Identity/Infrastructure/Security/PermissionCatalog.php`, `StaticAuthorizationPolicy.php` -- route, DTO and permission pattern; tests `PermissionCatalogTest` (~38-41) and a `StaticAuthorizationPolicyDocument*Test`; comment in `api/features/backoffice/identity/session.feature` (~20) lists ADMIN-only permissions.
- `api/src/Documents/Document/Domain/Exception/DocumentNotRescannable.php`, `DocumentGone.php`, `Shared/ErrorContract/Domain/Exception/{Conflict,Gone,NotFound}` -- problem-type pattern; `docs/api-error-contract.md` Documents tables (~320-380).
- `api/src/Shared/Crypto/Application/Keystore.php`, `Infrastructure/Persistence/DbalKeystore.php:114,142`, `Domain/DestroyReason.php`, `Domain/EncryptionScopeId::forDocument` -- `destroy()` first-writer-wins upsert, `lockState()` needs a transaction; `Backoffice/BankAccount/Application/EraseBankAccountSubject.php:60-95` is the pattern, minus its Absent early return.
- `api/src/Documents/Document/Infrastructure/Persistence/Doctrine/DoctrineDocumentRepository.php:68`, `UploadIntentRepository` -- `lockForUpdate`; add `lockByScope`.
- `api/src/Documents/Document/Domain/Storage/DocumentStorage.php`, `Infrastructure/Storage/FilesystemDocumentStorage.php:295,320` -- `delete(area, scope)` is idempotent; only staging callers exist today (`SweepStagedOrphans` never deletes an `Erased` row's object; `RollForwardPromotions:24` skips `Erased`).
- `api/src/Documents/Document/Infrastructure/Messenger/Maintenance/{DocumentsMaintenanceSchedule,ReportDocumentStorageHandler,SweepStagedOrphans*}`, `Application/Upload/{SweepBudget,ReapUploadIntents}.php`; `api/src/Shared/Event/Infrastructure/Messenger/Maintenance/ReportDeadLetterBacklogHandler.php` -- schedule, sweep and alarm templates (one transaction per item, stop after consecutive failures).
- `api/src/Shared/Audit/Infrastructure/Persistence/DbalSubjectErasureReconciler.php:38-60` -- already excludes `aborted-upload` and `document:` scopes; the new reconciler is Document-driven and lives beside `ReportDocumentStorageHandler` (which already filters `state <> 'erased'` and reads `dek_keystore` by the `document:` prefix).
- `api/src/Documents/Document/Application/Read/ErasureLedgerOverride.php`, `Infrastructure/Read/NeverSetErasureLedgerOverride.php`, `Infrastructure/Controller/{ReadDocument,ListDocuments,DocumentCustody}Controller.php` -- the port and its three consumers; replace the alias target only.
- `scripts/deploy/restore-prod.sh` (L52-64 lock, L116-140 confirmation, L147-159 writers trap/stop, L169 `pg_restore`, L171 restart), `scripts/deploy/lib/{prod-env,common}.sh`, `make/deploy.mk:40-44`, `ProdEnvProjectGateTest:205-230` -- do not reformat the `IS_PROD` lines; `scheduler_worker` is not stopped today; the trap restarts writers on every exit and must become conditional; `make shell.lint` zero findings.
- `compose.yaml:150-201,221-235`, `compose.prod.yaml:199-247,291-366`, `api/Dockerfile:20-45` -- workers and the apt block; gates `BoundedContainerLogRetentionGateTest`, `ScheduleConsumptionGateTest`, `DocumentsSchedulerStorageGateTest`, `ProdEnvTemplateGateTest`.
- `api/tests/Support/DocumentsRestoreProofs.php`, `docs/runbooks/documents-restore-proof.md`, `docs/runbooks/documents-restore-proofs/` -- proof record format: a `drill` record needs `ledger_replay_verified: true` and `mechanism == documents.backup_mechanism`; only `.gitkeep` exists.
- `api/tests/Unit/Gate/CryptoReadBoundaryGateTest.php`, `DocumentCustodyMutationGateTest.php`, `make/php-quality.mk` (target per class with `--filter`, appended to `php.quality` ~725 and `php.quality.dry-run` ~750 and `.PHONY`), `api/.artifact-gate-placement` -- gate pattern; `tests/Functional/Documents/Document/UploadRecoveryFunctionalTest.php`, `FaultingScanLeases`, `ThrowingKeystore`, `ScriptedDocumentStorage` -- crash-point test model; `features/documents/rescan.feature` -- Behat template.
- `docs/adr/documents-custody-log.md` (D1 amendment at L19, lock-order table L43/59-70, D9 L124-133), `docs/vps-deployment.md` (headings "Re-erase after restoring", "The future Documents module widens this", Gate 2 credential bullets), `PRODUCTION_SECURITY_CHECKLIST.md` §7 (item "A restore from an older backup resurrects keys destroyed since", doc-5/doc-6 items naming doc-7 as owner).

## Tasks & Acceptance

**Execution:**
- [x] Spike first (`tmp/` scratch, deleted after): rest-server `--append-only` locally with restic — a refused `forget`, `check --read-data` exit codes, whether an unreadable snapshot file exits 0 with an `Ignoring` line, whether a read-only credential can exist, restic in the php image — and record the outcomes in the ADR D11 before building on them.
- [x] `api/migrations/` (via `make db.diff`) -- `document.object_deleted_at` + partial index; `erasure_ledger_override` table -- the sweep's progress marker and the override flag
- [x] `CustodyEvent`, `DocumentCustody`, `ErasureReason`, `ErasureSystemReason` -- the event, the fit rule and the two enums, with unit tests (marker refused, actor/reason pairing, HTTP enum excludes both system values)
- [x] `Erased` CAS class + `EraseDocument` use case + `ReplayErasure` service + `lockByScope` -- the transactional core, lock order intent/document/keystore, bounded `SET LOCAL` timeouts
- [x] `ErasureLedger` port (Application) + restic adapter (Infrastructure) + a fake for the default suite + Dockerfile/compose/env wiring -- durable-before-destroy, dedupe by scope id, sanitised exceptions, no secret on argv or in a URL
- [x] `EraseDocumentController`, DTO, route constant, `DocumentPermission::ERASE`, `PermissionCatalog` -- the operator route; problem types for ledger-unavailable and outcome-unknown
- [x] Object-deletion sweep, ledger completion sweep (alarms when the ledger cannot be read) and the Document-driven tombstone reconciler, registered in `DocumentsMaintenanceSchedule` with reports in `REPORTERS`
- [x] DB-backed `ErasureLedgerOverride` adapter + `scripts/deploy/restore-prod.sh` recovery mode, replay step, scheduler stop, conditional restart, documented override procedure; `ReplayErasure` reachable only from the restore command and the completion handler (reflection gate `+ Rules` classes, `php.lint.*` target wired into both quality targets, `api/.artifact-gate-placement`)
- [x] Functional tests per crash point (ledger written / tx fault / post-commit) and a lock-order test; Behat `erase.feature` for the route; the step-vocabulary lines
- [x] Docs, registries and §7 residuals listed under Always; `docs/runbooks/` drill runbook (restore from the independent credential copy, planted corrupted entry holds recovery mode, no resurrected document served before replay, override exercised) and the proof-record steps for the owner

**Acceptance Criteria:**
- Given an `Erased` document, when its tombstone is read, then it holds id, digest, size, retention fields and timestamps and no filename ciphertext, and a repeat erase adds no custody row.
- Given a ledger write that cannot be made durable, when an operator erases, then the document, its key and its custody are untouched and the problem type says to retry.
- Given an entry that landed and a transaction that never committed, when the completion sweep runs after the margin, then the row is `Erased` with a `ledger-completion` custody row, actor NULL, and the key is destroyed.
- Given an unreadable or corrupt ledger, when `restore.prod` runs, then writers and sweeps stay stopped and the read routes answer 410 only through the override flag once an operator sets it.
- Given the override is set, when any well-formed id is read, listed or its trail requested, then the answer is 410 `gone`, and the flag clears only after a full replay and a clean reconciler.
- Given the gate tests, when a class other than the restore command and the completion handler depends on `ReplayErasure`, then `make php.lint.<target>` fails.

## Implementation Notes

- Contract deviations beyond the disclosed actor change, found by review and not amended in `stories.yaml`/SPEC (the user's approval covered the actor line only): the override record holds no "who" (`record_ref` points at the off-host operator log; stories.yaml says "who, when, why"), and the lock order is intent, document, keystore (stories.yaml says "document, then keystore"). Both are recorded in ADR D11.
- Review layers, this PR (no PR existed when they ran): Blind Hunter, Edge Case Hunter, verification-gap and an Acceptance Auditor (the CLAUDE.md third layer), four read-only subagents over the diff at baseline `801caedb`, 2026-11-17; plus two consultations before approval (codex on the replay actor, on the override's "who" and on the spec; codex and Winston on the override's clearing and the erase route's re-proof).
- Open for the owner: the real restore drill and its `drill` proof record; `restore-prod.sh` was never run end to end against a prod stack.

## Spec Change Log

- 2026-11-17, user renegotiation of the frozen block after the review (not a `bad_spec` loopback): (1) the erase route re-proves the caller's current password and is `credential-affecting`; (2) no override reason clears by itself, an explicit `documents:erasure:clear-override` does, bounded to the ids it saw. Triggers: Winston (irreversible, enumerable, evicting a session undoes nothing) and codex + Winston (vacuous clear on a new empty repository). KEEP: the override table shape (no name, `record_ref` only), the NULL system actor, the one-transaction erasure.

## Review Triage Log

| # | Finding (layers) | Verdict | Evidence / route |
|---|---|---|---|
| 1 | `hold_stack=1` set before `pg_restore`; a failed restore leaves the app stopped (Edge, Blind, Acceptance) | high | `restore-prod.sh:186` vs `:197`; patch |
| 2 | `compose config … \| grep -qx` under `pipefail` can drop `scheduler_worker` (Edge, Acceptance) | high | `restore-prod.sh:165`; patch |
| 3 | Override clears vacuously, and the clear is unbounded (Edge, Blind) | high | `DbalErasureLedgerOverride::clear()`, handler; user decision, patch |
| 4 | `entryOf` accepts non-UUID tags; one poisoned tag wedges the sweep and the restore (Edge, Blind) | high | regex `[0-9a-f-]{36}`; patch |
| 5 | A failure after the entry is durable answers a generic 5xx instead of "may still complete" (Blind) | medium | only `LedgerWriteTimedOut` is mapped; patch |
| 6 | `reopenObjectDeletion` in a separate transaction and untested (verification-gap, Blind) | high | restored `Erased` row keeps its marker; patch |
| 7 | Missing tests: unminted race, `AppendCustody` fault, rowless completion, keyset multi-page, two commands, 401, restore script (Acceptance, verification-gap) | medium | spec Always and matrix; patch |
| 8 | No real rest-server test (verification-gap, Acceptance) | medium | spec Always names it; patch (opt-in) |
| 9 | Completion margin omits `lock_timeout` and counts one statement (Edge, Blind) | medium | `ErasureLimits.php:33-35`; patch |
| 10 | "Ledger host's clock" is wrong, restic stamps client-side (Edge, Blind) | low | docblocks and ADR; patch |
| 11 | Gate pins `ReplayErasure` holders but not `ApplyErasure`'s (Blind) | medium | `ApplyErasure::apply()` is public; patch |
| 12 | Erase route without re-proof (Blind) | high | user decision (Winston): patch |
| 13 | 503 docs contradict, log cannot tell causes apart (Blind) | low | patch with #5 |
| 14 | Ledger listing grows linearly under `--no-cache` (Edge, Blind) | maybe-false | no measurement exists; patch = measure and record the residual |
| 15 | `compose run … php php bin/console` form unconfirmed (verification-gap) | maybe-false | settled by running `bin/console list` in dev |
| 16 | Docblock wraps, garbled sentence, change-relative doc wording, ADR D1 sentence misplaced, SPEC parentheses, Dockerfile COPY placement, `@return` shape | low | boy scout, patch |
| 17 | Overdue-object alarm measured from the original `Erased` row, noisy while a restore backlog drains (Blind) | low | rejected: transient, and the fix needs a new column |
| 18 | Dockerfile pins restic by digest, not "version and checksum" (Acceptance) | low | rejected: a digest is the stronger pin, ADR D11 states it |
| 19 | `Vendor.Process` deptrac layer not added (Acceptance) | false | `make php.deptrac` is green; `Documents.Infrastructure` already allows `Vendor.Symfony` |
| 20 | Behat hooks truncate the override table on every scenario (Blind) | low | rejected: one cheap statement, and it keeps the flag from leaking across features |
| 21 | Repeat on an `Erased` row never re-records a lost ledger entry (verification-gap) | false | spec: a repeat writes no ledger entry |
| 22 | Object sweep silently re-probes passing rows every tick (Edge) | low | rejected: the hourly alarm names a row stuck past the bound |
| 23 | Spec task boxes unticked, layers not recorded in the artifact (Blind) | low | done in this section; boxes ticked after the patches |

## Design Notes

- **Why one transaction covers the "re-drivable intent" clause:** the document row, its custody row and the keystore tombstone are all in PostgreSQL, so a single transaction makes the Erased transition, its custody row and the key destruction atomic; the only thing that can be left over is outside the database (the ledger entry before it, the sealed object after it), and each has its own forward driver.
- **Why NULL and not the marker:** `ErasedPersonMarker` is what a person's erasure writes over a real actor; a system fact written with it would be indistinguishable from an erased operator in the listing, in `PersonReferenceSource` (which excludes it) and in the actor anonymisation. Discarded: relaxing the constructor for two events.

## Verification

**Commands:**
- `make php.stan` on every changed PHP file, then `make php.quality` -- expected: exit 0
- `make php.unit c='--filter Document'`, `make php.behat` for `features/documents/` -- expected: green
- `make php.lint.route-manifest php.lint.error-contract php.lint.schedule-consumption php.lint.gate-placement php.lint.step-vocabulary` -- expected: exit 0
- `make shell.lint` -- expected: zero findings
- `make php.prepush` last, from a fresh run -- expected: exit 0

**Manual checks:**
- Local rest-server drill: refused `forget`, `check --read-data` on a planted corrupt entry holds recovery mode, override set and cleared; paste the printed exit codes in the PR body.
