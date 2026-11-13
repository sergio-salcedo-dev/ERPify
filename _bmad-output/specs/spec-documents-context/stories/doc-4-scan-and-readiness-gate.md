---
title: 'doc-4: Scan and readiness gate'
type: 'feature'
created: '2026-11-11'
status: 'done'
baseline_commit: 'a591be6d2aaf218236497f7099f02c18cf1db0b2'
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

**Spike (2026-11-12, `clamav/clamav:1.4` = clamd 1.4.6, signatures 28181; scripts were in `tmp/`, not committed).** The final `api/clamav/clamd.conf` was run with `--memory 2g --memory-swap 2g`, `--cap-drop ALL` plus five caps and a tmpfs temporary directory.
- Memory (`memory.peak`): idle 0.99-1.0 GiB (signatures); one 100 MiB scan 1.21 GiB; three concurrent 100 MiB scans 1.45 GiB; a signature `RELOAD` with `ConcurrentDatabaseReload no` added nothing to the peak. No OOM at 2g, so `mem_limit: 2g` stands.
- Limits: exactly 104857600 bytes answers `stream: OK`; one byte more answers `INSTREAM size limit exceeded. ERROR` (the sender is not cut off first). With `AlertExceedsMax yes`: a zip of 10100 entries answers `stream: Heuristics.Limits.Exceeded.MaxFiles FOUND`, a zip nested 25 deep `...MaxRecursion FOUND`, a 509 KB gzip of 500 MiB of zeros `...MaxFileSize FOUND`. The adapter maps the prefix and the size answer to `LimitExceeded` (`ScanFailed`), never clean.
- Time: a 100 MiB INSTREAM took 4.8-11.0 s against a local clamd (with the sender generating random bytes), 1 MiB 0.1 s. The constants held as provisional: attempts 3, outage returns 10 (alarm at 5), deadline 10 minutes, lease 2 minutes, heartbeat 30 s, ladder 1/5/15/60 minutes with 20 percent jitter. `MaxScanTime` is 90 s (under the lease and the deadline); the read timeout is 20 s (under the heartbeat), so the wait for a verdict is a poll that heartbeats between reads.
- The image's healthcheck probes `localhost:3310`, which resolves to `::1` first: a `TCPAddr 0.0.0.0` leaves the container never healthy, so the config sets none. The image's init also waits for a local socket, hence `LocalSocket`. `LogFile /dev/stdout` is refused to the clamav user, so a foreground clamd logs to the container's stdout with no `LogFile`.
- `api/clamav/clamd.conf` is exercised by the opt-in `make php.scanner.integration` (4 tests green: probe, EICAR, the 100 MiB boundary and one byte more, the archive limits).

**Decisions and deviations, each argued in D8 of the ADR.**
- *The custody row is in the same TRANSACTION as the compare-and-set, not the same statement (accepted by the owner, 2026-11-12).* The contract says both "its custody row in the same statement" and "through `AppendCustody`"; the second is the only writer the custody gate and the sequence lock admit, and the compare-and-set already holds the row lock `AppendCustody` takes, so the pair commits together. A single `UPDATE ... INSERT` CTE would have bypassed the sequence computation.
- *`scan(callable $producer, callable $keepAlive)` rather than `scan(stream)`.* `unseal()` pushes plaintext and cannot be paused, so the port hands the producer a sink (as `DocumentStorage::stageWith()` does) and polls for the verdict with the caller's `keepAlive`. The plaintext goes to the socket and to no file.
- *The scanner profile is declared in `compose.dev.yaml`, not in the base file (accepted by the owner, 2026-11-12, with the narrowed `ComposeStackCommands` refusal).* A `profiles: !reset []` in the prod overlay is a YAML tag the gate readers do not parse. The base defines the service for every environment; dev opts out, prod always starts it. `ComposeStackCommands` now refuses `profiles:` only on a service that runs a `command` (the scanner has none), with a falsifying fixture.
- *The rescan reason is stored in custody* (owner decision, 2026-11-12): a nullable `document_custody.reason` column, set for `RescanRequested` and NULL for every other fact, enforced in the `DocumentCustody` constructor beside the actor pairing (a scan fact has a NULL actor, an operator decision a non-NULL one). It is also logged on the observability channel and is not a person reference.
- *The abort path does not release the claim* (owner sign-off, 2026-11-12). A superseded epoch, `Erased` seen by a heartbeat, a verdict refused by the clock and `DekDestroyed` end the pass with nothing counted and the claim live; if the lease lapses on a row that is not `Erased`, the expiry sweep returns it as a worker that died (an outage return before streaming, an attempt after), so a document that keeps ending this way reaches `ScanFailed` with its alarm; a row that is `Erased` by then matches no predicate. Both halves are pinned in `ScanLeaseAccountingFunctionalTest`.
- *A scan wake-up is published by the re-scan inside its transaction*, like the promotion's, so a reopen does not wait for the next tick.
- *A transient local read error before the first byte counts as an outage return* (streaming not started), per the contract's rule on the mark; it is not a verdict.
- *Pass bound.* `ScanDueDocuments` starts no new document after 60 s and takes at most 10 per pass: the scheduler worker also owns the upload reaper's one-minute cadence, whose staging margin is computed on it.
- *Fakes.* `Erpify\Tests\Double\Scanner\FakeMalwareScanner` is bound in dev and test and scripted by static state (the kernel reboots between requests); production binds `ClamdScanner`.
- Registries and docs updated: route manifest, credential-proof policy (`ordinary`), permission catalog (`document.rescan`, ADMIN wildcard only), step vocabulary, gate placement, the channel and log-retention gates, the error contract, the ADR (D1 amendment, D6 rows, D8), `docs/architecture-api.md`, `docs/deployment-guide.md`, `docs/vps-deployment.md` and the §7 residuals.

## Spec Change Log

## Review Triage Log

Layers: Blind Hunter (18), Edge Case Hunter (12), Acceptance Auditor with the verification-gap instructions (G1-G4 + 5). Run on `tmp/doc4.diff` against baseline `a591be6d`. Verdicts were rendered after reading the code, not from the layers' severity.

| # | Finding | Verdict | Route | Evidence |
|---|---|---|---|---|
| 1 | Outage horizon stated 7.4 h; the code gives 6.35 h and the test is loose | low | patch | Return 10 caps, so only 1-9 back off: 60+300+900+6×3600 = 22,860 s. `ScanPolicyTest` accepts 7 h ± 1 h. Prose and test corrected. |
| 2 | "counts NOTHING" contradicts the expiry sweep (`DekDestroyed`, deadline-refused verdict) | medium | owner decision | `afterFailure` returns Aborted and leaves the lease live; `ExpireScanLeases` then counts. Owner chose to correct the documentation (2026-11-12). |
| 3 | Sustained scanner outage silent for 10 h | medium | residual (§7) | `ScanDueDocuments::run` returns 0 on a failed probe by contract ("no epoch, attempt or custody row"); detection is the stuck bound. A per-minute warning would be buffered and invisible under `fingers_crossed`. |
| 4 | Quarantined alarm repeats hourly and never clears; ScanFailed reported twice | low | rejected | The hourly list-ids template is the existing reporter shape and the two reports are the two the contract asks for; the way out is erasure (doc-7). |
| 5 | Throughput capped at 10 documents per pass | low | rejected | A design parameter nothing in the contract fixes; wake-ups also run a pass. 600/h is above the evidence volume. |
| 6 | Pass bounds starts only, not the in-flight scan | medium | residual (§7) | Wait is bounded by `MaxScanTime` + 30 s margin and clamd's own read timeouts, not 10 min; recorded with the figure. |
| 7 | Limit gate runs against real clamd only in the opt-in target | low | rejected | By the owner's decision; the answer classification is covered over real sockets by `ClamdScannerTest`. |
| 8 | `ClamdSocket::answer` runs `$keepAlive` under a global error suppressor | medium | patch | Confirmed in the tree: the handler wraps the whole loop. Scoped to `fread`/`feof`. |
| 9 | Compose gate reads the base file, not the merged stack; `cpus` env-widenable | medium | patch | Gate reads `compose.yaml` only; the overlay can raise `mem_limit` unseen. Reads the merged stack now. |
| 10 | clamd command port reachable from `frontend` (pwa, php) | high | patch | `compose.prod.yaml` joins `scanner` to `frontend` for freshclam egress; clamd has no auth. Dedicated egress network. |
| 11 | Signature freshness unmonitored | medium | residual (§7) | Not in the contract; if freshclam fails, clamd answers from stale signatures. |
| 12 | No scanning-shape CHECK; missing indexes for the expiry and cap sweeps | low | rejected | Writers always set the lease; the table is small and nothing here is measured. Dropped CHECK is deviation (f), judged legitimate. |
| 13 | Docblocks overstate (handler "never reaches retry"; "ended in a verdict") | low | patch | `scanOne` is the only catch; `probe()`/`dueCandidates()` propagate. Docblocks made true. |
| 14 | NULL-actor rule not enforced at the constructor | medium | patch | `RescanRequested` with a NULL actor is accepted today. Event-to-actor pairing enforced. |
| 15 | `backoffSeconds()`/`reachesCap()` dead in production | low | patch | Only `ScanPolicyTest` calls them; the SQL takes `ScanPolicy::ladder()`. Deleted. |
| 16 | Re-scan reason not durable | medium | owner decision | Owner chose to store it in `document_custody` (2026-11-12). |
| 17 | Sizing contradictions in docs; `MaxThreads` wording; rehearsal doc | low | patch | `vps-deployment.md` row vs verification column; "at most two workers" holds for one replica. |
| 18 | Aggregate no longer owns lifecycle; receipt view reused; `UploadRoutes::RESCAN` placement | low | rejected | Database-clock compare-and-set is the contract; the rest is cosmetic. |
| 19 | Inline FQNs, plain EICAR string, orphan daemon/child, gate list order, long line, no CLAUDE.md "Required checks" row | low | patch (CLAUDE.md row: not applied) | Hygiene patched. The CLAUDE.md row is an agent-context file and is left for the owner. |
| 20 | Connect failing after a passing probe never marks health down | medium | patch | `$down = streamingStarted() && isDown()` never probes when streaming did not start, so `knownDown()` stays false and the run keeps claiming. `markDown()` added. |
| 21 | A DB fault in the wake-up handler reaches Messenger retry | low | patch | Idempotent pass re-run; docblock made true (see 13). |
| 22 | Poison archive killing clamd is accounted as outage returns | low | residual (§7) | It is the contract's accounting (re-probe finds the daemon down). |
| 23 | tmpfs 512m vs `MaxScanSize` 400M × 3 threads | maybe-false | residual (§7) | Only 100 MiB flat streams were measured; an expanding archive was not. |
| 24 | One vs two probes per run | low | rejected | A re-probe only after a return; the claim "one probe per run" is loose, not harmful. |
| 25 | `STUCK_BOUND` 10 h vs "> 7 h" | false | rejected | 10 h is above 7 h; it must clear the jittered ladder (≈7.6 h). |
| 26 | `#[IsGranted]` after payload resolution (422 before 403) | low | rejected | Same on every route in the codebase; the Behat 403 uses a valid body. |
| 27 | Terminating chunk fails after clamd answered a size limit | medium | patch | Falls to `ScanInterrupted`, so three attempts instead of a prompt alarmed `ScanFailed`. Reads the pending answer as `stream()` does. |
| 28 | `/ 1000 + 30` is a float if the constant is not a multiple of 1000 | low | patch | `intdiv`. |
| G1 | Lost settle/return/expiry CAS not shown to write no custody row | high | patch | Moving `append` ahead of the `settle` check keeps every test green. Tests added. |
| G2 | `ScanSession` heartbeat never exercised at an interval | medium | patch | Test added. |
| G3 | Cap through `ExpireScanLeases` and the outage-cap sweep branch untested | medium | patch | Tests added. |
| G4 | NULL-actor person-reference path and the reason unobserved | medium | patch | Tests added. |
| A | Custody in the same transaction, not the same statement | n/a | owner decision | Accepted (2026-11-12). |
| B | `scan(callable, callable)` instead of `scan(stream)` | n/a | rejected | Signature is in Tasks, not the frozen block; `unseal()` pushes. |
| C | `scanner` profile only in `compose.dev.yaml`; gate reader relaxed | n/a | owner decision | Accepted (2026-11-12). |

## Design Notes

- **Why ticks rather than the message.** The row is the obligation and the sweep is the only driver, so a lost or duplicate message changes nothing. The handler only runs a pass early.
- **Why the counters live on the snapshot.** Returns and re-claims write no custody row, so the counters are the only place that accounting can live.
- **Lock order.** Claim, heartbeat, verdict, return and re-scan are each a single-row `UPDATE` on `document`; the custody append then takes the same row lock through `AppendCustody`. They therefore add no new pair to ADR D6, beyond recording that they order like the erasure's document-row lock.

## Verification

**Commands:**
- `make php.stan` on every changed file, then `make php.quality` -- expected: exit 0.
- `make php.unit c='--filter Scan'` and `make php.behat c='features/documents'` -- expected: green.
- `make shell.lint` if any `.sh` changes -- expected: zero findings.
