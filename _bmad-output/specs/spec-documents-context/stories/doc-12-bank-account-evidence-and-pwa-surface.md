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
- `api/migrations/2026/Version20261128120000.php` (hand-written: no PHP 8.5 in the authoring session; index names are DBAL's derived ones, computed).
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

## Verification

**Run in the authoring session (host node 22, no PHP 8.5, no stack):** `npx tsc --noEmit` 0, `npx eslint .` 0, `npx depcruise src` 0, `npx prettier --check .` 0, `npx vitest run` 314 files / 2627 tests green. `php -l` (8.3) on every changed PHP file.

**Owed to CI (not runnable here):** `make php.quality.dry-run` (PHPStan, cs-fixer, rector, deptrac, every `php.lint.*` including route-manifest, person-reference, audit-resource, bounded-context, credential-proof, orm-sort-direction), `make php.unit`, `make php.behat` (the eight Documents backgrounds), `make db.validate` for the hand-written migration.

**Owed to review:** the three review layers (none ran yet); the browser measurement of an early refusal during the PUT (§7) against the running stack.

## Review Triage Log

(empty — the three layers are owed)
