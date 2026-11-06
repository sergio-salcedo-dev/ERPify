---
title: 'doc-3: UploadEvidence ingest'
type: 'feature'
created: '2026-11-06'
status: 'in-progress'
baseline_commit: 'e4c35490eda3e180e0fdb51341d9f3688efedac9'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/specs/spec-documents-context/SPEC.md'
  - '{project-root}/_bmad-output/specs/spec-documents-context/evidence-lifecycle.md'
  - '{project-root}/docs/adr/images-vs-documents-conservation-contract.md'
  - '{project-root}/docs/adr/documents-custody-log.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Documents (epic #268) has a skeleton (doc-2) and a stream sealer (doc-1) but no way in: no route, no upload-intent record, no ingest, no reaper, and nothing keeps an original filename or a half-sent body out of logs, Sentry and the access log.

**Approach:** A declare step creates an `UploadIntent` (state machine Declared, Streaming, Committed, Expired); a raw-body PUT claims it by compare-and-set, seals the stream untransformed into staging, and one short transaction files the `Received` document and its first custody row. Reaper and sweeps own every crash path; the filename travels sealed and is redacted everywhere a URI is logged.

**Contract:** `stories.yaml` entry `doc-3` is the owner's contract and prevails over any summary below; this file adds the code map, the ordering of the work and the decisions recorded here. Nothing in it narrows that entry.

## Boundaries & Constraints

**Always:**
- The declare step refuses a zero or over-`MAX_ORIGINAL_BYTES` size before any keystore row exists; `MAX_ORIGINAL_BYTES` (100 MiB) is ONE constant in `src` that replaces the literal doc-1's tests carry.
- A durable intent precedes any sealed byte; no transaction spans the stream. The commit transaction locks intent row, then keystore row (`lockState` must be Live, never Absent), asserts Streaming with a live lease and deadline, reads actor and key hash from the LOCKED intent row, inserts `Received` (+ seq 1), sets the intent Committed. The reaper locks in the same order and rechecks both under both locks; a test interleaves them.
- The PUT dispatches on intent state before it claims: Content-Length (present, equal to the declared size) and the filename are checked only on Declared, so a failed request leaves the intent Declared. The PUT counts bytes to end of stream and aborts at the first extra byte, before it is sealed or staged. The PUT hashes the plaintext while sealing; a mismatch with the declared sha256 is an aborted upload; the computed digest is what is stored.
- The original filename is percent-encoded UTF-8 in the `filename` query parameter of the PUT, validated before the claim (rules in the contract), held in request memory only, sealed under the document's scope with its own associated data inside the commit transaction. No exception message, problem detail or log context quotes it; the intent row never holds it.
- Redaction: `filename` joins a new `QUERY_KEYS` set of `RequestUriRedaction` (substring, after the same padding and decoding reduction; URI paths only, never `RedactionDenylist::KEYS` or `IDENTITY_KEYS`); on the upload route those paths replace the whole query with the sentinel; the PWA mirror and `RedactionVocabularyParityTest` follow. Both Sentry bypasses close: `before_send_transaction` (dev and prod) and `max_request_body_size: never`.
- Key hash is nulled whenever an intent becomes Expired and by the person purge; the actor is a registered person reference with a detective source; the purge never deletes an intent row. An aborted upload writes an `aborted-upload` tombstone exactly when `claimed_at` is set and no Document holds the scope; tombstones are never pruned.
- Schedules: the transport of each new schedule is named in BOTH compose consume commands, `->stateful()` on `cache.scheduler_checkpoint`, one `scheduler_worker` replica. A final object with no row is alarmed on, never deleted. No sweep (the reaper and the staging age sweep alike) deletes a staged object whose scope has a `Document` row or a live intent: the age sweep deletes only an object older than its margin with neither, and a regression scenario keeps a `Received` row with `promoted_at` NULL and a staged object older than that margin, runs the sweep and expects the object to remain and the roll-forward to promote it. Every sweep is idempotent and safe under a duplicate tick; the single replica is an operating policy, never a correctness dependency.
- The scan message is a wake-up hint, never the obligation: the durable obligation is the row (`promoted_at` set, `next_scan_at`), which doc-4's sweep reads. A delivery to the no-op handler therefore loses nothing, and a test proves the handler leaves the row untouched.
- Hexagonal layering holds; no `UploadedFile`, transport type or caller-supplied path past Infrastructure (the Documents-tree scan excludes only the validated filename value object).
- Commits, PRs, code, docs in English.

**Owner decisions (2026-11-06, answers to the planning questions):**
1. Minimum accepted throughput is 256 KiB/s: `upload_deadline_at = claimed_at + ceil(declared bytes / 262144) s + 60 s` (460 s for 100 MiB).
2. Caps stay at 2 Streaming per actor and 4 globally; this story raises `num_threads`/`max_threads` and the PHP CPU limit in `compose.prod.yaml` so the global cap is at most half the worker threads, with the prod values measured here (not copied from the dev run).
3. `upload_tmp_dir` is a tmpfs with a boot guard that refuses to start when it is unusable or not writable by the container user; its memory counts against the container limit and is sized together with decision 2. It is sized from the spool files the worker threads can RETAIN (memlog T1-2: a spool lingers until the same thread serves another request), not from the in-flight cap, and `php` gets `memswap_limit` equal to its memory limit in `compose.prod.yaml` so the plaintext cannot be swapped. Sequential and concurrent maximum-size uploads on the prod image prove that thread count x retained spool fits the limit with peak memory (page cache included) measured; if it does not fit, the thread count is limited or the spool is cleaned explicitly, never the limit ignored. Plaintext spooled by PHP never reaches disk.
4. This story ships the proof record's path, format, validator test and runbook; the first record is committed by whoever proves a restore, and until then doc-5's gate keeps `documents.read_enabled` false. Where memlog R7-9 and SPEC/`stories.yaml` disagree on the path's owner, `stories.yaml` prevails: doc-3 fixes it.
5. This story defines the scan message, an Application port and an Infrastructure Messenger adapter routed to `async`; promotion dispatches it. A no-op handler is bound until doc-4 replaces it, so deliveries do not land on `failed`.
6. The spec stays whole: no split into further stories.
7. `documents.ingest_enabled` is a parameter independent of `documents.read_enabled`, default false outside dev and test; the declare and PUT routes answer 404 while it is false (read at runtime). This story's proof validator, run by a gate over the tracked configuration, fails when production config sets it true without a valid proof record whose `mechanism` equals the backup mechanism declared in that config; a historical record for another mechanism does not count.

**Never:** No read route (acceptance reads through the storage port and the decryptor). No scan implementation, verdicts or quarantine (doc-4). No successor versions: the declare DTO has no predecessor field and `series_id` equals the document's own id (doc-9). No erasure by key destruction (doc-7). No misfiling guard (UI only). No `#[AuditedEntity]` interface on `Document`, `UploadIntent` or `DocumentCustody`. No filename key in `AuditMetadataKey`. Never edit `api/config/reference.php` or a merged migration by hand.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Declare ok | size in (0, MAX], key + sha256 | intent Declared, id returned | N/A |
| Declare refused | size 0 or > MAX | no intent, no keystore row | 422 |
| Declare over cap / rate | > per-actor Declared cap or limiter spent | nothing created | rate-limit problem type |
| Declare replay | same actor-scoped key | answer by intent state (Declared intent, Streaming conflict + Retry-After, Expired as absent, Committed document); Erased original: tombstone | parameter mismatch = conflict, even against Erased (a document erased by doc-7 while its actor lives; after the ACTOR's own erasure the hash is NULL and no replay is matched) |
| PUT ok | Declared, CL = size, valid name | claim, seal, commit; `Received`, digest, seq 1 | N/A |
| PUT bad name / CL | invalid name, missing or unequal CL | intent stays Declared | 422 / CL problem type; sentinel in no body or log |
| PUT on other state | Streaming, Expired, 2nd PUT | nothing touched | conflict / gone; Committed answers its document, ignores a name |
| PUT as other actor | caller is not the intent's actor | absent and forbidden alike | gone |
| Body differs | short, long, digest mismatch, past deadline (a body longer than its Content-Length is cut by the transport and an unequal Content-Length is refused before the claim, so the counter's first-extra-byte abort is exercised by a unit test over the counting reader) | staged file deleted, intent expired, tombstone | aborted-upload problem type |
| Over a Streaming cap | per actor or global | intent stays Declared | rate-limit, no Retry-After |
| Crash after commit | `Received`, `promoted_at` NULL | roll-forward sweep renames, sets `promoted_at`, dispatches scan | reaper never touches it |
| Reaper vs commit | interleaved | exactly one wins under both locks | loser rechecks and stands down |
| Person erasure | actor's intents of any state | actor to marker, hash NULL, Declared/Streaming expired, rows kept | reaper still tombstones a minted scope |

</frozen-after-approval>

## Code Map

- `api/src/Documents/Document/**` -- doc-2 module (`Document::receive`, `IntakeMetadata`, `ReceiveDocument` takes no `IntakeMetadata` yet, `AppendCustody`, `DocumentStorage` port stage/promote/open/delete + `FilesystemDocumentStorage`, `PurgeUserDocumentReferences`, migration `Version20261105202636`). The storage port alias exists only in `config/services_test.yaml`: prod alias missing.
- `api/src/Shared/Crypto/**` -- `StreamEnvelopeEncryptor::seal` (returns nothing: digest and count are the caller's; key minted after the first chunk; outside any rollbackable transaction), `EnvelopeEncryptor::encrypt` for the filename, `EncryptionScopeId::forDocument`, `Keystore::{lockState,destroy}`, `DestroyReason::AbortedUpload`, `DekDestroyed` (marker-less, 500).
- `api/src/Backoffice/BankAccount/Infrastructure/Controller/BankAccountPostController.php`, `Shared/Http/Infrastructure/StrictRequestPayload.php` -- controller + JSON DTO pattern. `api/config/routes.yaml` -- Documents is not mounted: new resource, prefix `/api/v1` (model: `api_v1_shared_images`).
- `api/src/Iam/Identity/Infrastructure/Security/{Permission,PermissionVoter,PermissionCatalog,AuthorizationPolicy}.php`, `BankAccountPermission.php` -- permissions are strings in a catalog, one shared voter, no per-object voter: the PUT authorises the actor from the intent row itself.
- `api/src/Iam/Identity/Infrastructure/Security/CurrentPasswordProofThrottle.php`, `Iam/Invitation/Infrastructure/Security/InvitationResendThrottle.php` (HMAC keys), `api/config/packages/rate_limiter.yaml`, `api/.env` lines 89-111, `Shared/ErrorContract/Domain/Exception/RateLimitExceeded.php` -- rate-limit pattern. Markers Conflict/RateLimited/InvariantViolation exist; **Gone (410), CL-problem and aborted-upload markers do not**: `ProblemDetailsFactory::MARKER_STATUS_MAP`/`MARKER_DEFAULT_TYPE_MAP`, `docs/api-error-contract.md`, `ErrorContractGateTest`.
- `api/src/Shared/ErrorContract/Application/{RequestUriRedaction,RedactionDenylist}.php`, `Shared/Monitoring/Infrastructure/Monolog/RequestUriRedactionProcessor.php`, `ExceptionResponder.php` (lines 274, 370), `api/tests/Unit/Gate/RedactionVocabularyParityTest.php` (compares `IDENTITY_KEYS` only), `pwa/src/context/shared/observability/domain/redaction.ts` and its test.
- `api/config/packages/sentry.yaml`, `Shared/Monitoring/Infrastructure/Sentry/{SentryBeforeSend,SentryEventScrubber}.php` + `tests/Unit/Shared/Monitoring/Infrastructure/Sentry/*` -- `before_send_transaction` not wired, `max_request_body_size` unset.
- `api/frankenphp/Caddyfile` -- `request_body @frontController max_size 1MB` (lines ~156-174, comment wrong for this path); access log `format filter` already strips every query (`?REDACTED`) and drops Referer; `tests/Functional/Shared/Monitoring/AccessLogQueryContainmentGateTest.php` (GET-only `get()`, reads only `access.log`) and `tests/Unit/Gate/CaddyfileAccessLogRedactionGateTest.php`.
- `api/src/Shared/{Event,Audit}/Infrastructure/Messenger/Maintenance/*`, `Iam/Identity/.../IdentityMaintenanceSchedule.php` -- schedule + message + handler precedent; `ReportDeadLetterBacklogHandler` (threshold alarm, `monolog.logger.observability`); `compose.yaml:189`, `compose.prod.yaml:272-335` (`scheduler_worker` has NO `DOCUMENTS_STORAGE_PATH` nor `document_storage` mount), `api/frankenphp/docker-entrypoint.sh` (documents block only warns), `conf.d/10-app.ini` (no `max_execution_time`/`upload_tmp_dir`).
- `api/tests/Unit/Gate/{EventDispatchGate,DocumentCustodyMutation*,Schedule*}GateTest.php`, `tests/Support/DocumentCustodyMutations.php`, `make/php-quality.mk` (target + three prerequisite lists), `api/.artifact-gate-placement`, `.person-reference-policy`, `.credential-proof-policy`, `.route-manifest.json`, `.behat-step-vocabulary`, `.event-dispatch-allowlist`, `.persistent-transport-policy` -- gates and registries to extend.
- `Iam/Identity/Application/ErasureLockOrder*` tests -- add the upload-intent position (the ADR D4 line says it was untestable until this table existed). `AuditedEntity` is an interface: the test asserts it is not implemented (precedent `UserTest.php:94`).
- `api/tests/Double/Stream/SealedLayout.php:25` -- the only `MAX_ORIGINAL_BYTES` today.
- Docs: `docs/adr/documents-custody-log.md` (ends at D5; add D6 state machine + lock order, update D4), `docs/rules/cqrs-naming.md` row 6, `docs/architecture-api.md`, `docs/api-error-contract.md`, `PRODUCTION_SECURITY_CHECKLIST.md` §7, `docs/source-tree-analysis.md`, `CLAUDE.md` gate bullet.

## Tasks & Acceptance

**Execution** (increments, each verified before the next):
- [x] `api/src/Shared/ErrorContract/**`, `docs/api-error-contract.md` -- Gone, content-length, aborted-upload and replay-conflict markers/types -- contract first.
- [x] `api/migrations/2026/Version2026110*.php`, `api/src/Documents/Document/**` -- `upload_intent` table + `UploadIntent` entity/state machine, `promoted_at`/`next_scan_at` on `document`, `MAX_ORIGINAL_BYTES`, filename value object -- persistence.
- [x] `api/src/Documents/**` Application -- `DeclareUpload`, `ClaimUpload`, `CommitUpload`, `ExpireUpload`, extended `ReceiveDocument` -- orchestration; storage port prod alias; boot probe.
- [x] `api/src/Documents/**/Infrastructure/Controller`, `Security`, `routes.yaml`, `rate_limiter.yaml` -- declare + PUT routes, voter/permission, limiter, `.route-manifest.json`, `.credential-proof-policy`.
- [x] `api/frankenphp/Caddyfile`, `compose*.yaml`, PHP ini -- upload-route body bound, 1MB block excluded, pool/spool per answers 2 and 3; `scheduler_worker` gets `DOCUMENTS_STORAGE_PATH`, the `document_storage` mount and the same boot probe, read by a gate over `compose.prod.yaml`.
- [x] `RequestUriRedaction`, `redaction.ts`, Sentry config/scrubber -- `QUERY_KEYS`, whole-query sentinel on the route, `before_send_transaction`, `never`.
- [x] Messenger schedules (reaper, roll-forward, staging age sweep, retention sweep), keystore growth line, scan message per answer 5, both compose consume commands.
- [x] `PurgeUserDocumentReferences`, `.person-reference-policy`, `PersonReferenceSource`, lock-order tests -- erasure extension.
- [ ] Gates and tests -- Documents-tree scan + regression, `AuditedEntity` reflection test, parity extension, containment test with real PUTs, interleaving test, functional invalid-name test, acceptance scenarios; registries, ADR D4/D6, cqrs-naming, §7, proof record path/format/validator. (All done except Behat acceptance scenarios: the matrix is covered by kernel-level functional tests instead — see Design Notes.)

**Acceptance Criteria:**
- Given any matrix row, then it is covered by a test that fails when the behaviour is removed.
- Given an invalid filename (bidi character, 256 bytes, control character), then the 422 body and every captured log record lack the sentinel, and the intent row holds no plaintext name.
- Given `?filename=SECRET` on a transaction event and an error event on a 5-byte PUT body, then neither survives the Sentry hooks.
- Given the running Caddy, when PUTs are completed, aborted mid-body and past the bound, then neither `access.log` nor the container log holds the encoded name.
- Given each new schedule transport, then `make php.lint.schedule-consumption` is green and removing it from either compose file reds it.
- Given a crash at each point (before the commit; after it and before the rename; after the rename and before the directory sync; before `promoted_at`; after `promoted_at` and before the message), then recovery completes with no duplicate or lost object and a final object already present is accepted, never overwritten (one test per point).
- Given a claim, a commit, the reaper, the person purge and the roll-forward interleaved in pairs, then no deadlock and exactly one outcome per pair; ADR D6 carries the full lock-order table.
- Given the prod image with the raised pool, when four maximum-size uploads stream at the floor, then unrelated API requests answer inside their normal budget; the 100 MiB upload at 256 KiB/s through the real route is measured once and recorded in the PR (not a CI gate).
- Given repeated and concurrent maximum-size uploads on the prod image, when the worker threads retain and later release their spool files, then container memory stays within its limit, no spool falls back to persistent storage and no OOM occurs.
- Given production config without a valid restore proof for the declared backup mechanism, when `documents.ingest_enabled` is set true, then the gate test fails; and given the flag false, both routes answer 404.
- Given `make php.quality`, `php.unit`, `php.behat`, then all green from fresh runs.

## Implementation Notes

- The Gone (410) marker is implemented only by exceptions of the upload flow. `DekDestroyed` and `DecryptionFailed` stay marker-less: `PiiDiffSealer` shares them on the write path, where a 4xx marker would silence a real 500. Issue #418 (the read-side `dek-destroyed` status) is owned and closed by doc-5; doc-3 writes no code for it (memlog, 2026-11-06).

## Spec Change Log

## Review Triage Log

## Design Notes

Constants proposed from the transport spike (memlog T1-7), fixed here unless an answer changes them: Declared TTL 24 h; Committed retention 7 d; lease 90 s with heartbeat every 10 s checked per 64 KiB read, never beyond the deadline; PHP time limit re-armed per heartbeat (90) and 60 for the commit; reaper cadence 60 s; staging age sweep on mtime older than 20 min (above deadline 460 s + lease 90 s + reaper 60 s); per-actor Declared cap 10 (the deadline formula and the Streaming caps are owner decisions above). Not measured by the spike and therefore measured here: the prod image and `php.ini`, the real route behind firewall/voter/limiter, the real sealer plus staging in the request loop, Sentry capture of a 100 MiB body. Browser behaviour on an early 422/410 while the body is still sent was not measured: recorded as a section 7 residual, not a gate.

Clarifications from the external review (2026-11-06): an aborted claim with no key minted still tombstones, because `Keystore::destroy` mints a tombstone for a row-less scope so that a late writer cannot mint a live key under it. The declare insert uses DBAL `INSERT ... ON CONFLICT (key_hash) DO NOTHING`, then reads the winner in a new statement (a conflict that vanishes is retried once), because a Doctrine unique violation closes the entity manager. Proof records: one YAML per proof under `docs/runbooks/documents-restore-proofs/` with `kind` (restore or drill), `proved_by`, `proved_at`, `mechanism`, `backup_ref`, `originals_restored`, `metadata_restored`, `digest_set_verified`, `decryption_verified`, `result`; the validator rejects a missing field or a result other than passed. The filename in the query string, and its survival of a person's erasure, are owner decisions of 2026-11-04 and 2026-11-05 (SPEC, D7), not reopened.

Route names are decided here because the Caddy matcher is an anchored regex on the original path: `POST /api/v1/documents/uploads` (declare) and `PUT /api/v1/documents/uploads/{id}/content` (stream).

Implementation decisions (dev, 2026-11-06), none reopening a frozen line:
- Problem types: `gone` (410, new `Gone` marker), `length-required` (411, new `LengthRequired` marker), `content-length-mismatch` and `aborted-upload` and `invalid-filename` and `declared-size-out-of-range` (422, `InvariantViolation`), `idempotency-key-conflict` and `upload-in-progress` (409, `Conflict`; the latter with `Retry-After`), the caps on `rate-limited` (429, no `Retry-After`). Status 410/411 also join `HTTP_STATUS_TYPE_MAP`; the unmapped-status fixture moved to 418.
- `next_scan_at` is filed by the application (the commit passes its transaction's `NOW()`), with a literal epoch default for an older image's insert: a `CURRENT_TIMESTAMP` default trips a DBAL deprecation in the schema comparator (`MappedSchemaMatchesMigrationsTest`) and `MigrationColumnDefaultGateTest` refuses a dropped default.
- The filename is judged at the HTTP edge into a `FilenameSubmission` (valid name or refusal), so no raw caller string reaches `Application`; the use case raises the refusal only on a `Declared` intent.
- The writer's abort expires its own intent; the reaper tombstones (one place destroys an aborted scope). A sealing fault of the server's own (sink write, database) propagates as 5xx rather than `aborted-upload`.
- `RetentionReason` (`contract-execution`, `tax-and-accounting`, `technical-file`, `legal-claims`) and `LegalBasis` (the six of GDPR Art. 6(1)) are required at declare; the reason vocabulary is provisional until counsel's matrix.
- Prod pool fixed at `num_threads 12`, `max_threads 12`, worker `num 8`; `PHP_CPU_LIMIT` 4.0, `PHP_MEM_LIMIT` 2g with `memswap_limit` equal; spool tmpfs 900m. Derived, NOT measured on the prod image (AC 8 and 9 open, recorded in §7).

## Verification

**Commands:**
- `make php.stan` -- expected: no errors on changed files
- `make php.quality` -- expected: exit 0
- `make php.unit` and `make php.behat` -- expected: exit 0
