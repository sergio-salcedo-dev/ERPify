---
title: 'doc-4: Scan and readiness gate'
type: 'feature'
created: '2026-11-11'
status: 'ready-for-dev'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/specs/spec-documents-context/SPEC.md'
  - '{project-root}/_bmad-output/specs/spec-documents-context/evidence-lifecycle.md'
  - '{project-root}/docs/adr/documents-custody-log.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A document ends doc-3 in `Received` and nothing ever moves it. `ScanDocumentHandler` is a no-op, nothing scans an original, and nothing stops a later reader from serving an unscanned or infected file.

**Approach:** The authoritative driver is a PostgreSQL sweep on `next_scan_at`. The existing `ScanDocumentMessage` only wakes it. The sweep probes clamd, then claims a `Received` row under a leased, epoch-carrying compare-and-set. It unseals the original and streams the plaintext to clamd (INSTREAM), heartbeating the lease, and posts `Ready`, `Quarantined` or `ScanFailed` by compare-and-set. Failed claims return to `Received` under capped, backed-off counters. `Document::assertReadable()` is the only readability guard. An operator re-scan route reopens a `ScanFailed` document. Alarms report what stays stuck.

**Contract:** `stories.yaml` entry `doc-4` is the owner's contract and prevails over any summary below. Every rule in its `invoke_dev_with` applies verbatim; this file adds the code map, the order of the work and the decisions recorded here, and narrows nothing.

## Boundaries & Constraints

**Always:**
- **Every transition is one SQL compare-and-set** evaluated by the database clock.
  - It carries the monotonic `lease_epoch`, which grows on every claim and operator reset and is never reset.
  - `Erased` matches no predicate.
  - Claim, heartbeat, verdict, return, lease-expiry, cap sweep and re-scan each have one statement.
  - Verdict and heartbeat also require `NOW() < claimed_at + deadline`.
- **Claim predicate:** `state='received' AND promoted_at IS NOT NULL AND next_scan_at <= NOW()`, with both counters below their caps.
  - A down probe claims nothing: no epoch, no attempt, no custody row.
  - `scan_streaming_started_at` is set by compare-and-set immediately before the first byte goes to clamd, and cleared on every return to `Received`.
- **Return accounting:** follows the contract exactly.
  - Streaming not started counts as an outage return.
  - Streaming started counts an attempt, or an outage return when the sweep's single probe finds clamd down.
  - Reaching a cap goes to `ScanFailed` with its custody row in the same statement.
  - Otherwise `next_scan_at` comes from the ladder (1, 5, 15, 60 minutes, ±20% jitter): counted returns use the first two entries, and outage returns walk the whole ladder and then stay at 60.
- **Deterministic failures go straight to `ScanFailed` with an alarm**, without retries:
  - authentication failure (`DecryptionFailed`), a missing final chunk, or a missing final object;
  - any clamd limit answer, including a size beyond `MAX_ORIGINAL_BYTES`.
  - A limit answer is never `Ready`.
- **Aborts that count nothing:** `DekDestroyed` mid-scan, a superseded epoch, or `Erased` seen by the heartbeat. A transient local I/O error is no verdict.
- **Custody rows:**
  - written for the first claim since the last operator decision (`first_claim_recorded`), every verdict (cap-driven ones included) and every operator decision, through `AppendCustody`;
  - never written for a lease return or a re-claim;
  - the operator of an operator decision is that row's actor.
- **Plaintext never persists:**
  - clamd's temporary directory is a tmpfs;
  - `memswap_limit == mem_limit`;
  - core dumps are off for clamd and PHP;
  - partial `unseal` output is discarded on any exception.
  - The memory, tmpfs and host `core_pattern` residual goes to `PRODUCTION_SECURITY_CHECKLIST.md` §7.
- **Provisional constants are fixed by the spike's measurement** and live in one `ScanPolicy` domain constant class:
  - caps: attempts 3, outage returns 10, with an alarm at 5;
  - deadline 10 minutes;
  - lease 2 minutes with a 30-second heartbeat.
  - clamd's `MaxScanTime` stays below the lease, and below the deadline.
  - A gate compares `MAX_ORIGINAL_BYTES` with clamd's configured stream limit.
- **New sweeps are ticks of the existing `documents_maintenance` schedule**, so no new transport is consumed. Each is idempotent under a duplicate tick.
- **Repository registries and docs:**
  - the route manifest, the credential-proof policy, the permission catalog and the error contract;
  - the `BestEffortReportChannelGateTest` reporters list, and the `BoundedContainerLogRetentionGateTest` expected services;
  - the Documents ADR (scan accounting and the lock-order rows for claim, heartbeat, verdict, return and re-scan), `docs/architecture-api.md` (messages and ticks) and `docs/deployment-guide.md` (clamd).
- Code, docs and commits in English.

**Owner decisions (2026-11-11, answers to the planning questions):**
- **clamd memory and the VPS.**
  - The production VPS is sized up to at least 6–8 GB.
  - clamd gets a literal `mem_limit` of about 2g with an equal `memswap_limit`, and `ConcurrentDatabaseReload no`, so a signature reload pauses scanning rather than doubling memory.
  - The image's `freshclam` keeps its outbound access to the ClamAV mirror.
  - The spike's measured peak sets the final limit. The box size, the egress and the reload pause go to `docs/vps-deployment.md` and §7.
- **System actor.**
  - `document_custody.actor_id` becomes nullable, and NULL means the system. It is NULL for the first claim, every verdict and the cap sweep.
  - An operator decision always carries the operator.
  - The person-reference source skips NULL, and ADR D1 is amended.
  - The constructor still refuses the erased-person marker and an empty string.
- **Re-scan permission and reasons.**
  - The new non-tier permission `document.rescan` is reached only by ADMIN, through its wildcard. EDITOR does not get it.
  - The reason is a closed enum: `scanner_outage_resolved`, `scanner_limits_changed`, `failure_investigated`.
- **clamd in dev and CI.**
  - clamd is declared in every compose file behind a `scanner` profile.
  - Dev and test bind a fake `MalwareScanner` by default. It answers clean, except for an EICAR-marked stream, which it answers infected, and it can be scripted down or answering a limit.
  - A real-clamd integration test runs only in an opt-in make target, which is documented.
  - Production binds `ClamdScanner`, and its stack starts the profile.
- **Size.** One story and one PR. The re-scan route and the alarms stay in doc-4, as the contract bundles them.

**Never:**
- Rescanning `Ready`.
- A circuit breaker.
- A console command for the re-scan.
- A marker on `DekDestroyed` or `DecryptionFailed`.
- Any read or serve route (doc-5) or erase route (doc-7).
- A version slot or predecessor walk (doc-9).
- A custody `UPDATE` or `DELETE`.
- A Messenger retry as a scan driver.
- Writing plaintext to disk.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|---|---|---|---|
| Clean file | Received, promoted, due | Claim (custody: first claim), stream, `Ready` (custody: verdict) | N/A |
| EICAR | as above | `Quarantined` + custody verdict | quarantine alarm after its bound |
| Over a clamd limit | as above | `ScanFailed` + custody + alarm, no retry | never `Ready` |
| clamd down before claim | Received due | row untouched | sweep retries next tick |
| Connect fails after claim | Scanning, streaming not started | `Received`, outage +1, `next_scan_at` from ladder | at cap: `ScanFailed` |
| Reset mid-stream, clamd up | streaming started | `Received`, attempts +1, backoff | at 3: `ScanFailed` |
| Worker dies | Scanning, lease expired | lease-expiry compare-and-set returns it | same accounting |
| Heartbeat finds Erased / new epoch | Scanning | abort, no verdict, nothing counted | — |
| `DekDestroyed` mid-scan | Scanning | abort, nothing counted | — |
| `DecryptionFailed` / missing object | Scanning | `ScanFailed` + alarm | deterministic |
| Verdict after deadline | Scanning, deadline passed | compare-and-set loses, no verdict | lease expiry returns it |
| Verdict on Erased | Erased (planted) | compare-and-set matches nothing | test pins it |
| Unpromoted row | Received, `promoted_at` NULL | not claimed, nothing counted | roll-forward promotes |
| Cap lowered at deploy | Received, counter ≥ new cap | sweep: `ScanFailed` + custody | — |
| Operator re-scan | ScanFailed + reason | `Received`, counters 0, epoch+1, `next_scan_at=NOW()`, custody (actor = operator) | other state → 409 problem |
| `assertReadable()` | each non-Ready state | domain exception, own problem type | — |

</frozen-after-approval>

## Code Map

All paths below are under `api/src/Documents/Document/`.

**Domain**
- `Domain/Entity/Document.php`
  - Today: no transition methods; `promoted_at` is read-only to the ORM; `next_scan_at` is set at construction.
  - Add: `assertReadable()` and the new columns, mapped read-only like `promoted_at`, since every transition is DBAL compare-and-set.
- `Domain/DocumentState.php` -- already has the six states; no change.
- `Domain/CustodyEvent.php` -- add `ScanClaimed`, `ScanReady`, `ScanQuarantined`, `ScanFailed` and `RescanRequested`. There is no DB CHECK, so no migration.
- `Domain/Entity/DocumentCustody.php` -- `actorId` becomes `?string`; NULL means the system; the constructor still refuses the erased-person marker and an empty string.
- `Domain/UploadLimits.php` -- `MAX_ORIGINAL_BYTES`. Add `Domain/ScanPolicy.php` beside it for the scan constants.

**Application**
- `Application/AppendCustody.php` -- the only custody writer. It locks the document, computes `MAX(seq)+1` and retries a collision 3 times. Reuse it.
- `Application/Upload/DocumentScanWakeUp.php`, `Application/Upload/PromoteDocument.php` and `Application/Upload/RollForwardPromotions.php` -- the wake-up is already published inside the `promoted_at` transaction. Keep it.

**Infrastructure**
- `Infrastructure/Messenger/ScanDocumentHandler.php` -- replace the no-op with "run one sweep pass".
- `Infrastructure/Messenger/ScanDocumentMessage.php` and `Infrastructure/Messenger/MessengerDocumentScanWakeUp.php` -- reuse unchanged.
- `Infrastructure/Messenger/Maintenance/DocumentsMaintenanceSchedule.php` -- add ticks for the scan sweep, lease expiry and cap sweep, and for the reporters.
- `Infrastructure/Messenger/Maintenance/ReportDocumentStorageHandler.php` -- the alarm template: DBAL, `SCAN_LIMIT`, ids per line, `observability` logger at `error`.
- `Infrastructure/Persistence/Doctrine/DbalDocumentPromotions.php:100` -- the compare-and-set style to mirror in a new `DbalDocumentScanLeases`.
- `Infrastructure/Controller/` and `Infrastructure/Http/UploadRoutes.php` -- add the re-scan controller and its route constant.
- `Infrastructure/Security/DocumentPermission.php` -- add the new permission.

**Outside the Document module**
- `api/src/Documents/Document/Domain/Storage/DocumentStorage.php:113` (`open`) and `api/src/Shared/Crypto/Application/StreamEnvelopeEncryptor.php:71` (`unseal`, AAD `UploadEvidence::ORIGINAL_ASSOCIATED_DATA`) -- the decrypt stream to clamd.
- `api/src/Iam/Identity/Infrastructure/Controller/UserPatchStatusController.php` and `api/src/Iam/Identity/Infrastructure/Http/ChangeUserStatusRequest.php` -- the template for an enum-typed reason with `#[StrictRequestPayload]`.
- `api/src/Iam/Identity/Infrastructure/Security/PermissionCatalog.php` and `api/src/Iam/Identity/Infrastructure/Security/StaticAuthorizationPolicy.php` -- register the permission and its grant.
- `api/migrations/2026/Version20261106084306.php` -- the `next_scan_at` / `promoted_at` precedent. The new migration is generated with `make db.diff`.
- `compose.yaml`, `compose.prod.yaml`, `compose.rehearsal.yaml` and `compose.dev.yaml` -- the clamd service. Conventions to follow:
  - the `x-logging` anchor in the base file, with an overlay-introduced service spelling its own block;
  - `ulimits: core: 0`;
  - an image pinned by digest;
  - `tmpfs`;
  - `memswap_limit`.
- `api/config/services_test.yaml` -- alias the fake scanner.
- `api/.credential-proof-policy` and `api/.route-manifest.json` -- the latter regenerated with `make sf.routes.manifest`, never by hand.
- `docs/api-error-contract.md` -- Documents section, `:285`.
- `docs/adr/documents-custody-log.md` -- D1 and the D6 lock order.
- `PRODUCTION_SECURITY_CHECKLIST.md:2577` -- the open residuals "partial delivery to a scanner" and "unseal racing destroy", which are owned by the first reader: this story.

**Do not touch:** the ingest flow, `UploadIntent` and the intent reaper; `RequireTransactionalPublishMiddleware`.

## Tasks & Acceptance

**Execution:**
- [ ] Spike (`tmp/`, not committed):
  - run pinned `clamav/clamav` and measure `memory.peak` at idle, during a scan and during a signature reload;
  - confirm that `StreamMaxLength`/`MaxFileSize`/`MaxScanSize` ≥ 100 MiB, and `MaxFiles`/`MaxRecursion` with `AlertExceedsMax yes`, answer a limit as non-clean;
  - time a 100 MiB INSTREAM.
  - Record the numbers in the Implementation Notes and fix the `ScanPolicy` constants from them.
- [ ] `Domain/ScanPolicy.php` and `Domain/Entity/Document.php` -- constants, the new mapped columns, `assertReadable()` throwing `DocumentNotReadable` (marker `Conflict`, type `document-not-readable`).
- [ ] Migration (`make db.diff`, then hand-checked):
  - `scan_attempts`, `scan_outage_returns`, `lease_epoch` (default 0);
  - `scan_streaming_started_at`, `claimed_at`, `lease_expires_at`;
  - `first_claim_recorded`;
  - a partial index for due `Received` rows;
  - `document_custody.actor_id` made nullable, and its `PersonReferenceSource` query filtered to `IS NOT NULL`.
- [ ] `Domain/Scanning/MalwareScanner.php` (port: `probe(): bool`, `scan(stream): ScanVerdict`) and `Infrastructure/Scanning/ClamdScanner.php`:
  - INSTREAM over TCP;
  - a read timeout below the heartbeat interval;
  - classifies `OK`, `FOUND`, a limit or size answer, and an error.
- [ ] `Infrastructure/Persistence/Doctrine/DbalDocumentScanLeases.php` -- one compare-and-set per transition, as listed in Boundaries.
- [ ] `Application/Scanning/ScanDueDocuments.php` and `Application/Scanning/ScanDocument.php` -- the sweep (one probe per run), claim, streaming flag, heartbeat during the stream, verdict, return accounting and the classification of local failures.
- [ ] `Application/Scanning/ExpireScanLeases.php` and `Application/Scanning/SweepCappedDocuments.php`, plus their ticks.
- [ ] `Application/Scanning/RescanDocument.php`, a controller, a request DTO with the reason enum, the permission and voter grant, the manifest, the credential-proof line (`ordinary`), and the problem types.
- [ ] Reporters, each added to the `REPORTERS` list of the channel gate:
  - stuck documents (bound > 7 h, from the last custody-bearing event);
  - `Quarantined`/`ScanFailed` older than 24 h;
  - outage returns ≥ 5;
  - deterministic failures.
- [ ] The clamd compose service in every file behind the `scanner` profile, started by the prod stack; the fake scanner bound in dev and test; an opt-in make target for the real-clamd integration test; and the gate test comparing `MAX_ORIGINAL_BYTES` with the clamd config.
- [ ] Tests:
  - unit tests of the ladder and caps, and of verdict classification;
  - functional tests on real Postgres for every row of the I/O matrix: claim races, a lease expiry, a heartbeat losing to `Erased`, a cap sweep and the alarm at 5 outage returns;
  - a use-case test of `assertReadable()` for each non-`Ready` state;
  - a Behat scenario for the re-scan route (403, 409, 422 and 200).
- [ ] Docs: the ADR, `docs/architecture-api.md`, `docs/deployment-guide.md`, `docs/api-error-contract.md`, and the §7 residuals.

**Acceptance Criteria:**
- Given a promoted `Received` document and a reachable scanner, when the sweep runs, then the document ends `Ready` with exactly two new custody rows (claim, verdict), and `assertReadable()` passes only then.
- Given any transition statement, when it runs against a row whose epoch or state moved, then it changes nothing and reports the loss, and no custody row is written.
- Given the full suite and `make php.quality`, then both are green, including `php.lint.schedule-consumption`, `log-retention`, `person-reference`, `credential-proof`, `route-manifest`, `error-contract` and `document-custody`.

## Implementation Notes

## Spec Change Log

## Review Triage Log

## Design Notes

- **Why ticks rather than the message.** The row is the obligation and the sweep is the only driver, so a lost or duplicate message changes nothing. The handler only runs a pass early.
- **Why the counters live on the snapshot.** Returns and re-claims write no custody row, so the counters are the only place that accounting can live.
- **Lock order.** Claim, heartbeat, verdict, return and re-scan are each a single-row `UPDATE` on `document`; the custody append then takes the same row lock through `AppendCustody`. They therefore add no new pair to ADR D6, beyond recording that they order like the erasure's document-row lock.

## Verification

**Commands:**
- `make php.stan` on every changed file, then `make php.quality` -- expected: exit 0.
- `make php.unit c='--filter Scan'` and `make php.behat c='features/documents'` -- expected: green.
- `make shell.lint` if any `.sh` changes -- expected: zero findings.
