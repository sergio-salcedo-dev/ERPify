---
title: 'doc-5: Audited read path'
type: 'feature'
created: '2026-11-16'
status: 'done'
baseline_commit: '162a1c7de488ab6afd23bd2acea239a7f6c2c2e8'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/specs/spec-documents-context/SPEC.md'
  - '{project-root}/docs/adr/documents-custody-log.md'
  - '{project-root}/docs/adr/image-read-route-contract.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A `Ready` document can be scanned and gated, but no route serves it, so CAP-9 (an authorised user reads, and the read is audited) does not exist. The first read of sealed bytes also owes issue #418: `dek-destroyed` and `decryption-failed` still map to a bare 500 because nothing translates them at a read boundary.

**Approach:** One `GET /api/v1/documents/{id}/content`, behind a voter and a `documents.read_enabled` switch, served by a `ReadDocument` use case that verifies the original end to end before the first byte leaves, then streams it with hardened headers and one `audit_log` row. The use case is the read boundary: it translates the crypto exceptions, which gain no marker.

**Contract:** `stories.yaml` entry `doc-5` is the owner's contract and prevails over any summary here. Every rule in its `description` and `invoke_dev_with` applies verbatim; this file adds the code map, the order of the work and the decisions below.

## Boundaries & Constraints

**Always:**
- **Check order** (after the firewall and the voter): `Uuid::ensure()` → `documents.read_enabled` false answers 404 (`NotFoundHttpException`, as `DocumentsIngestGate`) → the ledger-override flag set answers `gone` → `findById` (it re-hydrates) null answers `document-not-found` → `Erased` answers `gone`, no alarm → `assertReadable()` (409 `document-not-readable`; this route mints no type for it) → serve.
- **Verify before the first byte (two passes).** Pass 1 unseals into a hashing sink that discards the bytes and compares sha256 and size to the row. Pass 2 unseals into the response and re-hashes; a divergence aborts the transfer with an alarm (`Content-Length` is the row's size, so the client sees a short body). Nothing is buffered and no plaintext touches a file.
- **Read-boundary translation** (in `ReadDocument`, never in the controller): `DekDestroyed` → `gone` (410, marker `Gone`); `DecryptionFailed`, `ObjectNotFound` and a digest or size mismatch → a new 5xx type `document-integrity-failure` that carries no `ClientError` marker, so Sentry sees it. Before either, re-read the row: `Erased` answers `gone` and raises no alarm; any other state raises the alarm (`error` on the `observability` channel, `documentId` and a `reason` slug only). `StreamTransferFailed` stays untranslated. `DekDestroyed` and `DecryptionFailed` gain no marker (`PiiDiffSealer` shares them on the write path).
- **Response:** fixed `Content-Type: application/octet-stream` (the row has no media type), `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`, `Cross-Origin-Resource-Policy: same-origin`, `Cache-Control: no-store`, `Content-Disposition: attachment` with no `filename` parameter, `Content-Length`; no `ETag`, no `Last-Modified`, `Range` ignored.
- **Authorization (decided, the user, 2026-11-16): ADMIN only, through `document.download`.** The verb is not a tier verb, so no tier grants it and `StaticAuthorizationPolicy`'s tiers and `TIER_OPT_OUT` stay untouched; the ADMIN wildcard reaches it, and a declared `'document.download' => [ADMIN]` row in `EXPLICIT_GRANTS` records the intended grantee. It is added to `PermissionCatalog` (so the session's `data.permissions` carries it for ADMIN and the PWA can hide the control). It is reopened by the first non-ADMIN consumer, or by a rule that an uploader may download what they uploaded.
- **Audit (decided, the user, 2026-11-16): level `SECURITY`, fail closed.** The use case writes one row through `AuditLogger` (action const `DOCUMENT_READ`, resource `Document`, no metadata, never the filename) after pass 1 passes and before pass 2 starts, so a failed write answers 5xx and no byte is served. It runs on an autocommit connection: `RequestTransactionBoundaryListener` rolls back a leaked request transaction and would take the row with it, and a test proves the row survives. The row records that access was granted, not that the transfer completed; a refused read (410, 5xx) writes none. The route sets `_audit_canonical` so the generic `ROUTE_*` row yields, and its name does not start with `shared_`.
- **Switch:** `documents.read_enabled` is declared only in `documents.yaml` (false by default, true in dev, env-driven in test, as `ingest_enabled`). `DocumentsReadProofGateTest` fails when the tracked production configuration or the compiled prod container opens it without a valid `restore` record **and** a valid `drill` record whose `mechanism` equals `documents.backup_mechanism`. A `drill` record additionally requires `ledger_replay_verified: true` (runbook table and `DocumentsRestoreProofs` updated; no drill record exists yet, so the format change is free).
- **Registers in the same PR:** `ReadDocument` into `CryptoReadBoundaryGateTest::READERS` with its functional test, the contract line citing its path and `dek-destroyed`, the #418 row deleted from `PRODUCTION_SECURITY_CHECKLIST.md` §7, the deferred-decision block of `docs/api-error-contract.md` rewritten and a "Documents read" section added, `Document => non-person` and `DOCUMENT_READ => ordinary` in the two audit registries, the route in `.route-manifest.json` (generated) and `.credential-proof-policy`, `document.download` in `PermissionCatalog`, the `EXPLICIT_GRANTS` row and the tests that pin it. The PR body closes #418.

**Never:**
- A response header, log field or audit metadata that carries the sealed filename.
- A marker on `DekDestroyed`/`DecryptionFailed`, or an `Erased` read that alarms.
- Opening `documents.read_enabled` in tracked production configuration; serving a real document before doc-7's record exists.
- A per-document ACL (the voter is permission-level), a media-type column, `Range`/conditional support, or any change to doc-4's `assertReadable()`.
- Defining the ledger-override storage: doc-7 owns the flag. doc-5 owns only the port the controller asks and a never-set adapter bound until doc-7 replaces it.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Happy path | `Ready`, authorised, switch on | 200, original bytes, headers above, one `DOCUMENT_READ` row | N/A |
| Switch off | `documents.read_enabled` false | 404 `not-found`, no row | N/A |
| Override set | flag port returns true | 410 `gone`, whatever the switch says | N/A |
| Malformed id | `{id}` not a UUID | 400 `invalid-uuid`, no lookup | N/A |
| Unknown id | no row | 404 `document-not-found` | N/A |
| Not `Ready` | `Received`/`Scanning`/`Quarantined`/`ScanFailed` | 409 `document-not-readable` | N/A |
| Erased | row `Erased` | 410 `gone`, no alarm | N/A |
| Erasure race | key destroyed or object gone between snapshot and open; re-read says `Erased` | 410 `gone`, no alarm | N/A |
| Key destroyed, row live | `DekDestroyed`, row not `Erased` | 410 `gone` plus alarm | `observability` error |
| Bytes missing / tampered / digest or size mismatch | `ObjectNotFound`, `DecryptionFailed`, mismatch; row not `Erased` | 5xx `document-integrity-failure`, no bytes sent | alarm; reaches Sentry |
| Unauthorised | lacks `document.download` (every role but ADMIN) | 403, no row | N/A |
| Swap between passes | pass 2 hash differs | transfer aborted | alarm |

</frozen-after-approval>

## Code Map

- `api/src/Documents/Document/Infrastructure/Controller/RescanDocumentController.php` -- style to copy (`final readonly`, `#[IsGranted]`, `Uuid::ensure`, `ActorContextFactory`); the new controller sits beside it, so `routes.yaml` mounts it under `/api/v1` unchanged.
- `api/src/Documents/Document/Infrastructure/Http/UploadRoutes.php` -- add `READ = 'documents_read'`. `DocumentsIngestGate.php` beside it is the 404 precedent.
- `api/src/Documents/Document/Infrastructure/Security/DocumentPermission.php` -- add `DOWNLOAD = 'document.download'`; also `Iam/Identity/Infrastructure/Security/PermissionCatalog.php:56` and the `EXPLICIT_GRANTS` row in `StaticAuthorizationPolicy.php` (tiers and `TIER_OPT_OUT` unchanged).
- `api/src/Documents/Document/Application/Scanning/ScanDocument.php` and `WriteCallbackStream.php` -- the unseal-into-callback pattern and the `object-missing`/`authentication-failed` alarm to mirror; it does **not** verify the digest (`UploadMeter` hashes with `hash_init`). Its alarm is `observability`-wired in `config/services.yaml:~159`.
- `api/src/Documents/Document/Domain/Entity/Document.php` -- `assertReadable()` (l.392), `scopeId()`, `digest()`, `sizeBytes()`. `DocumentRepository::findById` re-hydrates (`HINT_REFRESH`), which is the "re-read the row" step.
- `api/src/Documents/Document/Domain/Storage/DocumentStorage.php` (`open($scopeId)`, `ObjectNotFound`), `api/src/Shared/Crypto/Application/StreamEnvelopeEncryptor.php` (`unseal($scope, $sealed, $plaintext, $aad)`, AAD `UploadEvidence::ORIGINAL_ASSOCIATED_DATA`), `EncryptionScopeId::forDocument()`.
- `api/src/Documents/Document/Domain/Exception/UploadGone.php` and `DocumentNotReadable.php` -- type-slug precedent; `Shared/ErrorContract/Domain/Exception/Gone.php` is the 410 marker. New: a read-side `gone` exception (slug `gone`) and `DocumentIntegrityFailure`.
- `api/src/Shared/Images/Infrastructure/Controller/ImageGetController.php` -- header model; its route name starts `shared_` precisely to dodge generic audit, which this route must not copy. No generic gate enforces the headers, so this story adds its own tests.
- `api/src/Backoffice/BankAccount/Application/BankAccountSearcher.php` -- the audit-from-use-case example; `BankAccountSearchController.php:32` shows `_audit_canonical`.
- `api/config/packages/documents.yaml`, `api/tests/Support/DocumentsRestoreProofs.php`, `api/tests/Unit/Gate/DocumentsIngestProofGateTest.php`, `make/php-quality.mk:276` (`php.lint.documents-ingest`), `docs/runbooks/documents-restore-proof.md` -- the switch-and-proof pattern to extend with a sibling `readViolations`.
- `api/tests/Unit/Gate/CryptoReadBoundaryGateTest.php:80` (`READERS`), `docs/api-error-contract.md` (l.285-346: Documents sections, the deferred-decision block to rewrite), `PRODUCTION_SECURITY_CHECKLIST.md:2500` (#418 row), `api/.audit-resource-types`, `api/.audit-evidence-actions`, `api/.credential-proof-policy:122`, `api/.route-manifest.json`, `api/.behat-step-vocabulary`, `api/.artifact-gate-placement`.
- Permission pins: `PermissionCatalogTest.php` (EXPECTED), `PermissionResolverTest.php`, `features/backoffice/identity/session.feature:~26-36` (count of `data.permissions`).
- Test support: `tests/Functional/Documents/Document/` (`PlantsScannableDocuments`, `InterleavesWithASecondConnection` for the erasure race, `RescanDocumentRouteFunctionalTest` as route-test pattern); `features/documents/` for Behat.

## Tasks & Acceptance

**Execution:**
- [x] `api/src/Documents/Document/Application/Read/ReadDocument.php` (+ port for the override flag, never-set adapter, `services.yaml`) -- check order, two-pass verification, translation, alarm, audit row -- the read boundary #418 asks for
- [x] `api/src/Documents/Document/Domain/Exception/` -- read-side `gone` and `DocumentIntegrityFailure` -- defined types, 5xx visible to Sentry
- [x] `api/src/Documents/Document/Infrastructure/Controller/ReadDocumentController.php`, `UploadRoutes.php`, `DocumentPermission.php`, `PermissionCatalog.php`, `StaticAuthorizationPolicy.php` -- the route, voter, grant row, headers, switch
- [x] `api/config/packages/documents.yaml`, `DocumentsRestoreProofs.php`, `DocumentsReadProofGateTest.php`, `make/php-quality.mk`, runbook -- `documents.read_enabled` and its proof gate
- [x] registries and docs listed under "Registers in the same PR" -- `make sf.routes.manifest`, `php.lint.error-contract`, `php.lint.crypto-read-boundary`, `php.lint.audit-resource`, `php.lint.audit-evidence`, `php.lint.credential-proof`, `php.lint.step-vocabulary`
- [x] tests: unit (use case branches, headers, route declaration, each I/O row), functional (`ReadDocumentFunctionalTest` holding `dek-destroyed` and `decryption-failed`; erasure race on a second connection), Behat `features/documents/read.feature`, plus the permission pins above

**Acceptance Criteria:**
- Given the switch is off or the override set, when an authorised user reads, then the answer is 404 or 410 and no audit row or byte exists.
- Given a `Ready` document whose object is tampered, when read, then 5xx `document-integrity-failure` is answered before any byte, an alarm is raised and Sentry receives it.
- Given a document erased between the snapshot and the open, when read, then 410 `gone` is answered with no alarm.
- Given tracked production configuration that opens `documents.read_enabled`, when either proof record is missing or invalid, then `make php.lint.documents-ingest` fails.
- Given an EDITOR or MANAGER session, when it reads a `Ready` document, then 403 is answered and `data.permissions` of that session does not carry `document.download`.
- Given the audit write fails, when an authorised user reads, then 5xx is answered and no byte is sent.
- Given #418's row removed, then `make php.lint.accepted-risk` and `php.lint.crypto-read-boundary` pass with `ReadDocument` registered.

## Implementation Notes

- **Check order, switch against override.** The frozen "Check order" line lists the switch first, while the I/O matrix row and `SPEC.md` (the erasure-ledger decision of 2026-11-05: "every read answers the gone problem type whatever `documents.read_enabled` says") put the override first. Step-03 resolves a code/matrix disagreement in favour of the matrix: the override is checked before the switch (`ReadDocumentControllerTest::theOverrideAnswersGoneWhateverTheSwitchSays`). The frozen "Check order" line is left as written and is stale on this one point; renegotiating it is the human's.
- **Pass 1 cost, measured** through Caddy on the worktree's dev stack (22-core host, 100 MiB original = `MAX_ORIGINAL_BYTES`, fast client): time to first byte 0.143, 0.147, 0.146 s (HTTP/2) and 0.140 s (HTTP/1.1); total 0.69 s over HTTP/2, 0.35 s over HTTP/1.1. The PHPUnit lane gave 0.13 s for pass 1. A dev-host figure; the wall time under a cold page cache was not measured.
- **Pass 2 abort is a withheld last byte, and the edge keeps it broken (measured through Caddy).** The transfer holds back the final byte until the whole stream matched the row, so any abort leaves the body one byte short of `Content-Length`. Ten runs, with and without `Accept-Encoding: gzip, br, zstd`, over HTTP/2 and HTTP/1.1: a tamper in the object (authentication failure, mid-stream and in the last chunk) and a swapped authentic object of the same size (digest mismatch, exactly one byte short) all ended as a broken transfer (curl exit 16 `PROTOCOL_ERROR` over HTTP/2, exit 18 `end of response with N bytes missing` over HTTP/1.1); none ended cleanly. Each wrote one `DOCUMENT_READ` row and one alarm with the reason (`authentication-failed`, `changed-between-passes`). Caddy's `encode` does not compress `application/octet-stream` (Caddy's default compressible set, not something the Caddyfile states), so the response keeps `Content-Length` and has no `Content-Encoding`; a future change of `Content-Type` to a compressible type would reopen that. Not measured: HTTP/3, and a client that ignores `Content-Length`.
- **PHP's wall-clock limit cut slow downloads (measured, then fixed).** `max_execution_time` is 30 s (`php.ini:402`) and counts socket back-pressure under FrankenPHP: a 100 MiB download at 2 MB/s was cut at 30.19 s, curl exit 16, with a fatal and no alarm. The controller now re-arms a 60 s stall budget (`UploadTimeBudget`) before the stream and after every emitted chunk; see Review Triage Log row 12. Re-measured through Caddy: a 100 MiB download at 2 MB/s completes (49.7 s, 104857600 bytes, sha256 equal, no fatal in the log), and a client stopped for 75 s is cut at about 60 s as a broken transfer (curl exit 92 `INTERNAL_ERROR`, 6291456 of 104857600 bytes). That cut is still a PHP fatal (`MaxExecutionTimeError`, `app.CRITICAL`, the FrankenPHP thread restarts), so it writes no `observability` alarm; the signals are the fatal line and the critical log, which reach the error tracker. Accepted: it only happens to a client that stops reading for a minute, and the recorded residual is that each read holds a PHP thread for its whole transfer.
- **`HEAD`** is routed to the `GET` route by the router and would run both passes and write an audit row for a body that is dropped. Review finding 1 of the Triage Log: refused in the controller before `open()`.
- **Done checkpoint (`stories.yaml` `done_checkpoint: true`).** doc-3's restore proof: there is none. `docs/runbooks/documents-restore-proofs/` holds no record, so there is nothing to cite and `documents.read_enabled` stays `false` in every tracked production configuration. The route ships dark. doc-7's `drill` record (`ledger_replay_verified: true`, the same `mechanism` as `documents.backup_mechanism`) plus a `restore` record is what lets `DocumentsReadProofGateTest` accept an opened switch, and replaces `NeverSetErasureLedgerOverride` with the real flag.

## Spec Change Log

## Review Triage Log

Layers (one pass, iteration 0): Blind Hunter (diff only), Edge Case Hunter (diff, then this file), Acceptance Auditor (tree against `stories.yaml`, `SPEC.md`, this file and the registries), all read-only subagents run in parallel on the worktree. No pull request existed, so there were no review threads to read.

| # | Finding | Verdict | Evidence / route |
|---|---------|---------|------------------|
| 1 | `HEAD` runs both passes and writes a `DOCUMENT_READ` row for a dropped body (Blind, Edge) | medium | The router sends `HEAD` to a `GET` route and the audit row is written in `open()`. patch: refuse `HEAD` before `open()`. |
| 2 | Pass 2 catches five types only; anything else escapes the `StreamedResponse` callback after the headers with no alarm (Blind, Edge) | medium | `transfer()` catch list. patch: catch `Throwable`, alarm, return false. |
| 3 | `isErased()` re-read can throw inside `abort()`/`refusal()` and replace the translated outcome (Edge) | low | Same re-read as above. patch with 2: a failed re-read counts as not erased. |
| 4 | Pass 1 has no size cap, so a swapped larger object is fully decrypted first (Edge) | low | `transfer()` already stops at overrun, `verify()` does not. patch: same cap. |
| 5 | Caller's id spelling reaches the audit `resource_id` and the logs (Blind, Edge) | medium | `findById` lower-cases, `ReadDocument` uses the raw string (#873 shape). patch: canonical id + route test. |
| 6 | AC "audit write fails → 5xx, no byte" proven at use-case level only (Blind, Auditor) | medium | `aFailedAuditWriteRefusesTheReadBeforeAnyTransferExists` only. patch: route test. |
| 7 | "Row survives" counted on the app's own connection in the route test (Blind) | low | The use-case test reads a second connection; the route test does not. patch: count through a second connection. |
| 8 | Header assertions use `assertStringContainsString` where the spec is exact (Auditor) | low | `ReadDocumentRouteFunctionalTest` l.83-85. patch: exact assertions. |
| 9 | `DocumentsReadContext` resets the switch without restoring it (Blind) | low | `@AfterScenario` sets `true`. patch: capture and restore. |
| 10 | `PRODUCTION_SECURITY_CHECKLIST.md` still says the stream-unseal item is open for doc-5, reads in the future tense, and lacks the doc-5 residuals (Blind, Auditor) | medium | Lines ~2586-2603 and ~2685 of the register. patch. |
| 11 | `docs/deployment-guide.md` mentions only the ingest switch (Auditor) | low | patch, one sentence. |
| 12 | Download outliving PHP's wall-clock limit dies mid-stream; `Caddyfile` has a site-wide `encode` that could turn a short body into a clean one (Blind, Edge, Auditor) | high (limit) / false (encode) | Measured through Caddy. The limit is real: 30 s `max_execution_time`, a 100 MiB download at 2 MB/s cut at 30.19 s with a fatal and no alarm. patch: re-arm a stall budget per chunk. The `encode` claim is refuted: `application/octet-stream` is not compressed, `Content-Length` survives, and ten aborted transfers (one byte short included) ended broken on HTTP/1.1 and HTTP/2, never clean. |
| 13 | Done checkpoint missing (Auditor) | medium | `stories.yaml` doc-5 `done_checkpoint: true`. patch: recorded under Implementation Notes. |
| 14 | Root `CLAUDE.md` line 122 and the required-checks bullet still say #418 stays open (Blind, Auditor) | low | Real once #418 closes, but the fix edits an agent-context file: not patched here, raised to the human. |
| 15 | Frozen "Check order" contradicts the matrix, `SPEC.md`, the code (Edge, Blind) | low | Already recorded in Implementation Notes; the frozen line is the human's to amend. Raised to the human. |
| 16 | The ledger override can stay `NeverSet` while production opens reads (Blind) | false | The control is the drill record with `ledger_replay_verified: true`, which exists only after doc-7's drill; a gate on the DI alias would only move the same human act. Residual recorded in the register (#10). |
| 17 | Early `session->save()` untested (Blind) | maybe-false | The same snippet ships in `UploadEvidenceController` with its tests green; would be low even if true. Rejected. |
| 18 | Sealed handle leak if `WriteCallbackStream::open()` throws (Edge) | false | It throws only on a programming error that no input reaches; the worker does not survive that class of fault. Rejected. |
| 19 | `sendTo()` callable twice (Edge) | low | One caller, no second call site. Rejected: a guard would be speculative. |
| 20 | Behat context overwrites `$this->sealed[$id]` (Edge) | low | No scenario seals one id twice. Rejected. |
| 21 | `open()` does not check that no transaction is active (Auditor) | low | A contract on the method docblock, which the rules allow to stay at the code site. Rejected. |
| 22 | Nothing asserts the tracker is called; erasure race tested for `ObjectNotFound` only at pass 1 (Auditor) | low | Both paths share `refusal()` and the absence of a `ClientError` marker is pinned. Rejected. |

## Design Notes

Two passes cost a second decrypt and a second read of the object. They are the only way to honour "fails digest verification fails closed" without buffering up to `MAX_ORIGINAL_BYTES` or persisting plaintext: a single streaming pass could only discover a mismatch after the headers are out. The second pass authenticates again, so a swap between passes cannot serve foreign bytes; it can only abort. A buffered or tmpfs variant was discarded: it breaks CAP-3's size-independent memory and the "plaintext never persists" rule. The cost of pass 1 at `MAX_ORIGINAL_BYTES` is measured and recorded in Implementation Notes, not assumed.

**Consumer contract (for doc-6).** Every refusal and every integrity failure is decided before the first byte, so a client that checks `response.ok` before saving can show the problem in the application; a plain `<a download>` would lose it to the browser's download tray. The sealed filename is never in a header, so the client names the file from the listing's JSON and must sanitise it. `not-found` (switch off) and `document-not-found` (unknown id) stay distinct slugs.

## Verification

**Commands:**
- `make php.stan` on every changed PHP file, then `make php.quality` -- expected: exit 0
- `make php.lint.documents-ingest php.lint.crypto-read-boundary php.lint.error-contract php.lint.audit-resource php.lint.audit-evidence php.lint.credential-proof php.lint.route-manifest php.lint.step-vocabulary php.lint.accepted-risk` -- expected: exit 0
- `make php.unit` and `make php.behat` (feature `features/documents/read.feature`) -- expected: green

**Manual checks (if no CLI):**
- Through the real FrankenPHP/Caddy stack (as the upload gates do): a pass-2 abort yields a broken transfer, never a short body that reads as success; the same stack measures the wall time of pass 1 at `MAX_ORIGINAL_BYTES`.
