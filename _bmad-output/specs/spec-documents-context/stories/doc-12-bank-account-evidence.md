---
title: 'doc-12: First consumer — BankAccount evidence and the PWA surface'
type: 'feature'
created: '2026-11-28'
status: 'done'
baseline_commit: '0d3cbeb7'
route: 'dispatch'
review_loop_iteration: 2
context:
  - '{project-root}/_bmad-output/specs/spec-documents-context/SPEC.md'
  - '{project-root}/docs/adr/images-vs-documents-conservation-contract.md'
  - '{project-root}/docs/adr/documents-custody-log.md'
---

## Intent

**Problem:** Documents had no consumer: nothing referenced a document, and no browser could file one.

**Approach:** A `BankAccountEvidence` link in the BankAccount module references a document by id. Documents publishes a seam that reports the state, retention and opened name of a set of documents. The PWA gets a shared uploader for the two-step raw upload, with progress, and an Evidence section on the bank-account detail.

**Contract:** `stories.yaml` entry `doc-12` and the memlog decisions of 2026-11-19 (2)–(7) and 2026-11-28 prevail over this summary.

## Boundaries & Constraints

**Decided by the owner (do not reopen):**

- **Persistence.** State-oriented link table `bank_account_evidence` with columns `id`, `bank_account_id`, `document_id`, `kind`, `attached_at`.
  - `UNIQUE (document_id)`: a document is the evidence of one account (owner, 2026-11-28; it replaced the account+document pair).
  - Both foreign keys have no cascade and are not deferrable.
  - No `attached_by` column and no event.
  - Three `non-person` policy lines.
  - An `AuditedEntity` whose resource type `BankAccountEvidence` is non-person.
- **Retention is fixed per kind and checked on the server** (422 on a mismatch). The ownership certificate is `contract-execution` / `legitimate-interests`, with no review date.
- **D3 guard.** The evidence control has its own label and help.
- **Erasure.** `EraseBankAccountSubject` answers the same 409 as the deletion and never erases documents (2026-11-28).
- **Access.** ADMIN only.
- **ic-5 is dropped** (2026-11-28), so no screen offers both entry points.

**Refined at implementation (memlog 2026-11-28).** The read port is `DescribeEvidenceDocuments` (with `EvidenceDocument`), which wraps `DocumentNameOpener`.
- `describe()` opens names and answers the override's 410.
- `statesOf()` opens nothing and serves the deletion guard.

## Spec Change Log

- **Read port.** The story text names `DocumentNameOpener`, but the code consumes a seam around it, because the retention check needs state and retention as well as the name. Recorded in the memlog.
- **ic-5 drop applied to the tree.**
  - The `bank-accounts/{id}/image` alternative is gone from Caddy's `@imageWrite` (both sites), from `UploadRoutePathAgreementGateTest` (two shapes), and from the rate-limiter comment, the deployment guide, the error-contract heading, the roadmap and the images SPEC.

## Review Triage Log

All three layers ran in parallel in the implementing session, read-only, over `0d3cbeb7..53b2c096`. None found anything GRAVE.

**Blind Hunter** — 1 MEDIUM, 4 LOW:
- **Orphaned document on a failed or uncertain attach.** Patched in the PWA (retry against the same id, honest copy for an unknown outcome). The residual is recorded in `PRODUCTION_SECURITY_CHECKLIST.md` §7.
- **The guard opened names.** Patched with `statesOf()`.
- **`scan_failed` accepted.** Kept on purpose and documented in the use case: it can be rescanned, while quarantined cannot.
- **A MANAGER sees a count they cannot act on.** Accepted and documented in `docs/api-error-contract.md`; the PWA copy points to an administrator.
- **The stage label flipped before the PUT resolved.** Patched in the PWA with an explicit stage callback.

**Edge Case Hunter** — 3 MEDIUM, 8 LOW:
- **Orphaned document and blind re-upload.** Patched (same as the Blind Hunter's).
- **502/503/504 treated as known.** Patched: they are now unknown.
- **The delete refusal pointed to an action the app lacks.** Copy patched.
- **Unknown attach outcome copy.** Patched.
- **Stage labels.** Patched.
- **Retry offered for non-retryable failures.** Patched.
- **Filename refused only after the declare.** Patched with a client pre-check.
- **No refresh in the ready state.** Patched.
- **One unknown state broke the list.** Patched (it renders as unknown).
- **One document can be linked to several accounts.** Decided by the owner (round 2): one account per document.
- **Attach allowed on a CLOSED account.** Kept on purpose and documented.

**Acceptance Auditor** — 5 MEDIUM, 6 LOW:
- **No story artifact.** This file.
- **ic-5 residue in the tree.** Patched.
- **Query-cost claim with no test.** Two scenarios added, falsified with a simulated N+1 (9 and 18 queries against 6).
- **Read-port deviation unrecorded.** Recorded in the memlog.
- **First-browser-client proofs.** See below.
- **No audit-row assertion.** Patched: Behat asserts the CREATED and DELETED rows.
- **Foreign-key properties argued, not asserted.** Accepted: the migration spells no `ON DELETE` and the listeners document it.
- **`EVIDENCE_RETENTION` hand-mirrored.** Accepted; parity is pinned by literals on both sides.
- **Story ids in durable docs.** Removed.
- **Truncated name.** Patched with `<TruncatedText>`.
- **410 on deletion untested.** Moot, since the guard no longer answers 410.

**First browser client: what is proven and what is only argued.**
- **Filename encoding: proven** by `ApiDocumentUploader.test.ts`. Redaction is proven by the existing mirrored gates.
- **Progress forwarding and transport parity: proven** against a fake XHR.
- **Streaming: argued only.** The `File` itself is the body and is never read into memory.
- **Exact `Content-Length`: argued only.** The browser sets it from the Blob.
- **Behaviour against the real Caddy bounds: not tested here.** There is no through-Caddy e2e spec, because the sandbox that built this story runs no full stack. Owed to a live-stack run before production, together with the drill that opens Documents in prod.

### Round 2 — `bmad-code-review`

Four layers (Blind Hunter, Edge Case Hunter, Verification Gap, Acceptance Auditor), read-only and context-free, ran over `0d3cbeb7..a23e1d8e` in the implementing session. Triage was verified against the tree.

**Patched:**
- **The listing answered 200 `[]` under the override when the account held no evidence.** It now goes through the seam every time.
- **The attach opened the document's name while holding the account's row lock.** It now checks with `statesOf()` behind `ensureTrustworthy()`. The answer carries no name; the listing gives it.
- **Unverified properties, now tested:**
  - the listing's 410 and its HEAD 405 (Behat);
  - the guard neither answers the override nor opens a sealed name (unit);
  - attach and deletion are serialised on the account row (two-connection functional test, red without `FOR UPDATE`);
  - the CLI's live-evidence refusal (unit);
  - the retention strings match the Documents vocabulary (unit).
- **PWA:**
  - a 2xx with a malformed body counts as an unknown outcome;
  - stale list writes are guarded (remount per account, request sequence);
  - the abort listener is removed when the request settles;
  - the `download` filename goes through an allowlist.
- **Records:**
  - the security-checklist sentence on what the subject erasure does;
  - the read port named in `stories.yaml`;
  - the Level 2 foreign key and the "a document row is never deleted" rule in ADR `documents-custody-log.md` D13, which also accepts that Documents' erase route does not know a document is evidence.

**Deferred into the epic:**
- **doc-13**, the through-Caddy proofs: streaming, exact `Content-Length`, real progress and the cookie-gated bound.

**Rejected:**
- **Redundant `bank_account_id` index.** The schema diff derives it from the injected foreign key; the cost per write is negligible, and dropping it means fighting the schema tool.
- **The XHR path ignores `RequestInit` fields other than method, headers and body.** No caller passes one on an upload.
- **No page-level test for the two-permission attach gate.** Both verbs are ADMIN-only today, so no role can tell them apart.

**Decided by the owner (2026-11-28, memlog):**
- A document is the evidence of one account. `UNIQUE (document_id)` replaces the account+document pair, and attaching it to another account answers 409 `bank-account-evidence-attached-elsewhere`, naming no account.
- A link pins the exact document attached, never its series.

## Verification

- `make php.quality`: green, except `composer.check.missing-deps`, which fails only where the `apcu` extension is absent.
- `make php.unit`: 6221 tests. The only failures are the 27 sandbox-only ones (Caddy/access-log gates, root-user storage tests).
- `make php.behat`: 652 scenarios, all green.
- PWA: ESLint, dependency-cruiser, Prettier, tsc and the full Vitest suite are green.
- **Falsified:** retention check removed → 4 red cases; guard call removed → 2 red; N+1 in the listing → both budget scenarios red.
