---
title: 'doc-12: First consumer — BankAccount evidence and the PWA surface'
type: 'feature'
created: '2026-11-28'
status: 'in-review'
baseline_commit: '0d3cbeb7277f256db549ce63bf2b86546cbd4bfb'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/specs/spec-documents-context/SPEC.md'
  - '{project-root}/docs/adr/images-vs-documents-conservation-contract.md'
  - '{project-root}/docs/adr/bank-bankaccount-modeling.md'
  - '{project-root}/docs/adr/documents-custody-log.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Documents files, scans, reads, versions and erases evidence, and nothing in the product consumes it: no aggregate refers to a document, no screen uploads one, and the ADR D3 misfiling guard was left to "the first UI that offers both entry points".

**Approach:** A bank account holds documents by id in a link table of its own module; what it learns about a document comes through a read seam Documents publishes. The PWA becomes the first browser client of the raw two-step upload and shows, uploads and downloads an account's evidence on its detail page, with the D3 guard decided there.

**Contract:** `stories.yaml` entry `doc-12` (description and `invoke_dev_with`) is the owner's contract and prevails over any summary here, together with the memlog decisions of 2026-11-19 (persistence in a separate link table, retention fixed per kind and verified on the server, the D3 guard as copy, the 409 on removal, ADMIN only, names through a published read port).

## Boundaries & Constraints

**Decided by the owner (do not reopen):** state-oriented `BankAccountEvidence` link table in the BankAccount module (`id`, `bank_account_id`, `document_id`, `kind`, `attached_at`; `UNIQUE (bank_account_id, document_id)`; both foreign keys without cascade and not deferrable, the one to `document` a justified Level 2); no `attached_by`; three non-person policy lines; an `AuditedEntity` with a new non-person audit type; no event. Retention fixed per kind by the consumer, verified on the server through a Documents seam (422 on mismatch); the ownership certificate is `contract-execution` under `legitimate-interests`, no `review_at`. The D3 guard: distinct English labels and help, and on the image control of that screen a notice that the image is re-encoded, pointing to Attach evidence; ic-5 inherits it. Removing an account with a linked document that is not `Erased` answers 409. ADMIN only. Names and states through a read port Documents publishes, allowlisted and mirrored in deptrac.

**Decided here, inside those bounds (named for review):**
- **The seam is an interface, `Documents\Document\Application\Evidence\EvidenceDocuments`** (`filingOf`, `filingsOf`, `describe`), implemented by `DocumentEvidenceReader` over `DocumentListingRepository` (+ `findMany`) and `DocumentNameOpener` — one keystore query per `describe()`, 410 `gone` while the erasure ledger's override is set. An interface rather than the concrete opener, because a consumer's unit tests must be able to stand in for it; its two read models (`EvidenceFiling`, `EvidenceDocument`) are used without being imported, so the allowlist names only the port, from three importers (`DescribeEvidence`, `AttachBankAccountEvidence`, `ReleaseBankAccountEvidence`).
- **Routes:** `POST /api/v1/backoffice/bank-accounts/{id}/evidence` (`backoffice_bank_account_evidence_attach`) and `GET` on the same path (`backoffice_bank_account_evidence_list`), both `ordinary` in the credential-proof policy. **No download route of the module's own:** the PWA downloads through the audited `GET /api/v1/documents/{id}/content` by `documentId`, so the byte read keeps its one audit row and its one registration in the crypto read boundary; a second read route would duplicate both for an audience (ADMIN) that already holds `document.download`.
- **Authorization:** each route carries its `bankAccount` verb **and** `document.custody` (two `#[IsGranted]`), through `BankAccountPermission::EVIDENCE_CUSTODY = 'document.custody'`. A dedicated `bankAccount.attachEvidence` would be one line in `Iam`'s `PermissionCatalog` and `StaticAuthorizationPolicy`, which this change did not touch; the cost is recorded in §7.
- **The attach takes the account's row lock** (`findByIdForUpdate`), the lock the account's delete and its subject erasure now also take before releasing the links, so a link and the account's removal serialise instead of meeting at the foreign key. Refusals: 422 `bank-account-evidence-document-not-found` (a body member, not the target), 409 `bank-account-evidence-document-erased`, 422 `bank-account-evidence-retention-mismatch`, 409 `bank-account-evidence-already-attached` (the unique constraint classified by name). Any non-`Erased` state may be attached — a just-uploaded document is `Received`.
- **Checkpoint decision — `EraseBankAccountSubject` takes the same 409** rather than erasing the documents first: a document is erased only by its own re-proven, ledger-written operator action, and an erasure never erases a document as a side effect (the invariant a person's erasure already keeps). With every linked document `Erased`, the links are removed (one ORM entity each, so the trail captures each deletion) in the same transaction, ahead of the account.
- **PWA:** a `context/backoffice/document` capability (`DocumentUploader` over the declare through `HttpClient` plus an `XMLHttpRequest` PUT with progress, `DocumentDownloader` over a `fetch` read as a `Blob`), `BankAccountEvidence` domain, repository and three use cases in `context/backoffice/bankaccount`, and `BankAccountEvidencePanel` on the detail page behind `document.custody` (attach behind `document.write`, download behind `document.download`). The upload sends no idempotency key and no sha256: either needs the whole file read in the tab.
- **The D3 guard is copy, not a confirmation step** — ADR D3 amended. This screen has no image control yet (ic-5 ships it), so the evidence half lands here and the image half is ic-5's inherited contract.

</frozen-after-approval>

## Code Map

- `api/src/Documents/Document/Application/Evidence/{EvidenceDocuments,EvidenceFiling,EvidenceDocument,DocumentEvidenceReader}.php`; `Domain/Repository/DocumentListingRepository.php` + `DoctrineDocumentListingRepository::findMany()`; alias in `api/config/services.yaml`.
- `api/src/Backoffice/BankAccount/Domain/{Entity/BankAccountEvidence,Enum/BankAccountEvidenceKind,Repository/BankAccountEvidenceRepository}.php` and five exceptions under `Domain/Exception/`.
- `api/src/Backoffice/BankAccount/Application/Evidence/{AttachBankAccountEvidence,ListBankAccountEvidence,ReleaseBankAccountEvidence,DescribeEvidence,ListedEvidence}.php`, `Application/Command/AttachBankAccountEvidenceCommand.php`, `Application/Resource/BankAccountEvidenceResource.php`; `BankAccountDeleter` and `EraseBankAccountSubject` call the release under the account lock.
- `api/src/Backoffice/BankAccount/Infrastructure/{Controller/BankAccountEvidenceAttachController,Controller/BankAccountEvidenceListController,Http/BankAccountEvidenceResourceMapper,Persistence/Doctrine/DoctrineBankAccountEvidenceRepository,Persistence/Doctrine/BankAccountEvidence*ForeignKeySchemaListener}.php`, `Security/BankAccountPermission::EVIDENCE_CUSTODY`.
- `api/migrations/2026/Version20261128130000.php` (hand-written: no PHP 8.5 in the authoring session; index names are DBAL's derived ones, computed).
- `api/src/Backoffice/Audit/Infrastructure/Http/AuditDiffDisclosure.php` (`BankAccountEvidence` clear fields).
- Registries: `api/.route-manifest.json`, `.credential-proof-policy`, `.person-reference-policy` (three lines, and the stale foreign-key count corrected), `.audit-resource-types`, `.bounded-context-allowlist` + `tools/deptrac/deptrac.yaml`, `tools/rector/rector.php` (the alias-importing file on the sort-direction skip).
- The eight `features/documents/*.feature` backgrounds and `PlantsDocumentRows` truncate `bank_account_evidence` with the Documents tables (the foreign key would refuse the truncate otherwise).
- PWA: `pwa/src/context/backoffice/document/**`, `pwa/src/context/backoffice/bankaccount/{domain/BankAccountEvidence*,application/*BankAccountEvidence,infrastructure/ApiBankAccountEvidenceRepository}.ts`, `pwa/src/app/backoffice/bank-accounts/{_components/BankAccountEvidencePanel.tsx,_lib/bankAccountEvidence.ts,[id]/page.tsx}`, `ApiEndpoints.ts`, `Permission.ts`, `Container.ts`, `BankAccountProblemType.ts`; `pwa/tests/api-endpoint-contract.test.ts` (`METHOD_BY_KEY`).
- Docs: `docs/api-error-contract.md`, `docs/architecture-api.md`, `docs/architecture-pwa.md`, `docs/rules/database.md`, `docs/source-tree-analysis.md`, ADR `images-vs-documents-conservation-contract.md` D3, `PRODUCTION_SECURITY_CHECKLIST.md` §7, root `CLAUDE.md` (foreign-key count).

## Acceptance Criteria → tests

| AC | Test |
|---|---|
| A filed document is attached by id and listed with its opened name and state | `BankAccountEvidenceRouteFunctionalTest::anAdministratorAttachesAFiledDocumentAndListsItWithItsNameAndState`; `AttachBankAccountEvidenceTest::aDocumentFiledUnderItsKindsTermsIsAttachedInsideOneTransaction` |
| Retention fixed per kind, verified on the server, 422 on a mismatch | `…FunctionalTest::aDocumentFiledUnderOtherRetentionTermsIsRefusedAndNothingIsLinked`; `AttachBankAccountEvidenceTest::aDocumentFiledUnderOtherTermsIsRefused` (four cases); `DocumentEvidenceReaderTest::everyEvidenceKindNamesTermsTheDocumentsVocabularyHolds` |
| Erased, unknown and duplicate documents refused | `…FunctionalTest::anErasedOrUnknownDocumentAndADuplicateAreRefused`; unit cases |
| Body mapping strict (no caller retention terms) | `…FunctionalTest::aBodyTheMappingRefusesIsUnprocessable` |
| 400 / 404 / 401 / 403 / 405 | `…FunctionalTest::aMalformedAccountIdIsABadRequestAndAnUnknownOneIsNotFound`, `aManagerIsRefusedBothRoutesAndNoSessionIsUnauthenticated`, `aHeadRequestIsRefusedBeforeAnythingIsOpened` |
| AuditedEntity, non-person type, no name in the row | `…FunctionalTest::theLinkWritesOneNonPersonAuditRowNamingTheLinkAndNoName`; `BankAccountEvidenceTest`; `AuditDiffDisclosureRegistryTest`, `AuditActionOperationAgreementGateTest` (existing, discover the entity) |
| Erased documents list as tombstones | `…FunctionalTest::anErasedDocumentListsAsItsTombstone`; `ReleaseAndListBankAccountEvidenceTest::theListingComposesEachLinkWithItsDocumentInOneCall` |
| Removing an account with a live linked document answers 409; with all erased, links go with it | `…FunctionalTest::aClosedAccountHoldingALiveDocumentIsNotDeletedUntilTheDocumentIsErased`; `BankAccountDeleterTest` (two cases); `ReleaseAndListBankAccountEvidenceTest` |
| Names through the published port, one keystore query, 410 under the override | `DocumentEvidenceReaderTest`; `AttachBankAccountEvidenceTest::theLedgerOverrideRefusesTheAttachBeforeTheLinkIsWritten` |
| PWA: streaming, exact Content-Length, filename in the query (NFC, encodeURIComponent), progress, cancel | `ApiDocumentUploader.test.ts` (11 cases) |
| PWA: list, upload, download on the account page, refusals in the persistent surface | `bankAccountEvidencePanel.test.tsx` (11 cases), `ApiBankAccountEvidenceRepository.test.ts`, `BankAccountEvidenceUseCases.test.ts`, `ApiDocumentDownloader.test.ts`, `checkDocumentFile.test.ts` |
| D3 guard: distinct label and help | `bankAccountEvidencePanel.test.tsx::says the file is kept as sent, as opposed to a re-encoded image`; ADR D3 |
| **Review (O1):** one document is evidence of one account; the detach frees it, audited, under the account lock | `…FunctionalTest::aDocumentIsEvidenceOfOneAccountAndADetachFreesIt`; `DetachBankAccountEvidenceTest` (four cases); `AttachBankAccountEvidenceTest::aDocumentAnotherAccountHoldsIsAConflict`; `…SubjectErasureFunctionalTest::aConcurrentAttachOfAHeldDocumentMeetsTheNamedConstraint`; `RepositoryUniqueViolationTest::aLostRaceOnTheEvidenceDocumentConstraintIsAnAttachConflictNamingNoValue`, `aLongerConstraintNameIsNotTheEvidenceDocumentOne` |
| **Review (O2):** the link's `documentId` is sealed under the account's scope | `PiiDiffSealerTest::aDependentRowIsSealedUnderItsOwnersScopeSoTheOwnersErasureShredsIt`; `…FunctionalTest::theLinkWritesOneNonPersonAuditRowNamingTheLinkAndNoName` (scope `BankAccount:<id>`, no document id in clear); `…SubjectErasureFunctionalTest::withEveryLinkedDocumentErasedTheLinksGoWithTheAccountAndTheKeyIsDestroyed` |
| **Review (director):** `Quarantined` and a `CLOSED` account refused, `ScanFailed` admitted | `…FunctionalTest::aQuarantinedDocumentAndAClosedAccountAreRefusedWhileAFailedScanIsAttached`; `AttachBankAccountEvidenceTest::aClosedAccountTakesNoNewEvidence`, `aQuarantinedDocumentIsNotAdmissibleWhileAFailedScanStaysAttachable` |
| **Review (P3):** the subject erasure refuses over a live document and removes the links with the account once all are erased | `BankAccountEvidenceSubjectErasureFunctionalTest` (three cases); `EraseBankAccountSubjectEvidenceTest` (two cases) |
| **Review (P1):** the deleter locks before it judges | `BankAccountDeleterTest::testThrowsNotClosedAndMutatesNothingWhenTheAccountIsNotClosed`, `testDeletesAccountAndDispatchesDeletedEventWhenClosed` (no plain read, one locked read) |
| **Review (P5):** every kind's terms are in the Documents vocabulary | `DocumentEvidenceReaderTest::everyEvidenceKindNamesTermsTheDocumentsVocabularyHolds` (`RetentionReason::from`, `LegalBasis::from` over `cases()`) |
| **Review (P15):** the listing answers 410 under the override with no link; a malformed id is 400 | `…FunctionalTest::theLedgerOverrideAnswersGoneOnTheListingWithNoLinkAndOnTheAttach`, `aMalformedAccountIdIsABadRequestAndAnUnknownOneIsNotFound`; `ReleaseAndListBankAccountEvidenceTest::anEmptyListingStillAsksTheSeamSoTheLedgerOverrideAnswersTheSame` |
| **Review (P16):** the dry run names every attached document that is not erased | `EraseBankAccountSubjectCommandDryRunTest` |
| **Review (PWA):** gating on three permissions, cancel only while sending, reload after a failure, unknown labels, status outside the busy subtree, detach | `bankAccountEvidencePanel.test.tsx` (18 cases); `ApiBankAccountEvidenceRepository.test.ts` (5); `BankAccountEvidenceUseCases.test.ts` (6) |
| **Review (P13):** the name is built before the declare; a refusal before the claim retries the same upload; refused characters | `ApiDocumentUploader.test.ts` (18 cases); `checkDocumentFile.test.ts` (6) |
| **Review (P10, P20, P21):** `saveBlob` order and revoke delay; mirrors equal the PHP declarations; the live-evidence delete links to the detail | `saveBlob.test.ts`; `bank-account-evidence-parity.test.ts`; `bankAccountsListDeleteRecovery.test.tsx`, `bankAccountsListMutations.test.tsx` |
| **Review (P17):** end to end through the real stack | `pwa/tests/e2e/backoffice/bank-account-evidence-real-api.spec.ts` (not run: no stack in the authoring session) |

## Verification

**Run in the authoring session (host node 22, no PHP 8.5, no stack):** `npx tsc --noEmit` 0, `npx eslint .` 0, `npx depcruise src` 0, `npx prettier --check .` 0, `npx vitest run` 314 files / 2627 tests green. `php -l` (8.3) on every changed PHP file.

**Owed to CI (not runnable here):** `make php.quality.dry-run` (PHPStan, cs-fixer, rector, deptrac, every `php.lint.*` including route-manifest, person-reference, audit-resource, bounded-context, credential-proof, orm-sort-direction), `make php.unit`, `make php.behat` (the eight Documents backgrounds), `make db.validate` for the hand-written migration.

**Owed to review:** the browser measurement of an early refusal during the PUT (§7) against the running stack. The three review layers ran (log below).

**Review fixes, run in the fixing session (host node 22, no PHP 8.5, no stack):** `npx tsc --noEmit` 0, `npx eslint .` 0, `npx depcruise src` 0, `npx prettier --check .` 0, `npx vitest run` 316 files / 2655 tests green (1 skipped). The Playwright e2e and every PHP lane are owed to CI.

## Review Triage Log

The three layers ran on 2026-11-28 in the coordinating session, as read-only subagents in parallel: Blind Hunter (BH), Edge Case Hunter (ECH) and Acceptance Auditor (AA). Their findings were deduplicated and relayed as one batch; the batch did not attribute each finding to a layer, so the layer column says so rather than guessing. The owner renegotiated the frozen intent on two points (O1: `UNIQUE (document_id)` and a detach instead of `UNIQUE (bank_account_id, document_id)`; O2: owner-scope sealing), recorded in the memlog; the frozen block above is left as approved.

| # | Finding | Layers | Verdict | Route |
|---|---|---|---|---|
| O1 | A document could prove several accounts, and no route removed a link | BH/ECH/AA (unattributed) | decision-needed → owner: one account per document, audited detach | patch: `UNIQUE (document_id)` (named, classified by name), `DELETE …/evidence/{evidenceId}`, PWA detach dialog, manifest, policy, docs |
| O2 | The link's `documentId` was in clear in audit diffs and survived the account's erasure | (unattributed) | decision-needed → owner: seal under the account's scope | patch: `SealedUnderOwnerScope` seam in `PiiDiffSealer`, `#[PersonalData]`, `CLEAR_FIELDS` narrowed, SPEC re-derived |
| O3 | Eight residuals (subject erasure 409, name port, permission reuse, any-ADMIN attach, XHR PUT, 100 MiB Blob, no idempotency key, Level 2 FK) | (unattributed) | accepted by the owner (no customer) | §7 rewritten with reopen conditions; memlog |
| O4 | No PWA way to erase a blocking document; a PUT refused before the claim holds a declared slot for 24 h | (unattributed) | decided by the owner: new story | `doc-13` appended last; meanwhile P21 |
| D1 | `Quarantined` documents were attachable; a `CLOSED` account took evidence | (unattributed) | decided by the director | patch: two 409 types, admitted states documented (ADR D13, §7) |
| D2 | An unknown kind or state failed the whole PWA list | (unattributed) | decided by the director | patch: `toBankAccountEvidence()` maps to `unknown`, neutral label |
| P1 | The deleter read the account, then locked it | (unattributed) | patch | lock first, `delete()` and events on the locked instance |
| P3 | No test of the subject erasure with evidence | (unattributed) | patch | functional (rolled back) and unit tests |
| P4 | No concurrent-attach test; constraint name matched by substring | (unattributed) | patch | functional race test, two `RepositoryUniqueViolationTest` cases, word-bounded match |
| P5 | The kind's docblock claimed a test that did not exist | (unattributed) | patch | `cases()` over `RetentionReason::from` / `LegalBasis::from`; docblock points at it |
| P6 | The PWA offered the attach without `bankAccount.write` | (unattributed) | patch | `BANK_ACCOUNT_WRITE`, three-permission gate |
| P7 | Cancel stayed offered after the original was filed | (unattributed) | patch | `onFiled`, phase `sending`/`attaching` |
| P8 | Retention-mismatch copy told the user to attach again | (unattributed) | patch | copy says client and server disagree, contact an administrator |
| P10 | `saveBlob` clicked a detached anchor and revoked at once | (unattributed) | patch | append, click, remove, revoke after 60 s |
| P11 | Long names overflowed | (unattributed) | patch | `<TruncatedText>` |
| P12 | The polite status sat inside the `aria-busy` subtree | (unattributed) | patch | moved outside |
| P13 | A name the query could not carry cost a declared upload; refused characters reached the server; a pre-claim refusal declared again | (unattributed) | patch | name built before the declare, `isWellFormed` + refused-character class, same-upload retry |
| P14 | A failed attach left the list stale; network and timeout copy claimed failure | (unattributed) | patch | reload on any non-abort failure, "outcome unknown" copy |
| P15 | The listing skipped the seam with no links (inconsistent 410); no 400 test on GET | (unattributed) | patch | always describe; functional 410 and 400 tests |
| P16 | `--dry-run` did not warn about live documents | (unattributed) | patch | one warning per non-erased document |
| P17 | No end-to-end proof through the real stack | (unattributed) | patch | Playwright spec (owed to CI) |
| P18 | ic-5 did not carry the D3 notice in its dispatch text | (unattributed) | patch | `spec-images-consumers` stories.yaml + memlog |
| P19 | Docs missed the `document/` context, the seams and the detach | (unattributed) | patch | architecture-pwa, quickref, architecture-api, error contract |
| P20 | PWA mirrors of the cap and the retention terms were unguarded | (unattributed) | patch | parity test reading the PHP source |
| P21 | `bank-account-has-live-evidence` offered no recovery in the account lists | (unattributed) | patch | "View evidence" link to the account's detail in both lists |
| P22 | This log and the matrix were empty | (unattributed) | patch | this section and the rows above |
