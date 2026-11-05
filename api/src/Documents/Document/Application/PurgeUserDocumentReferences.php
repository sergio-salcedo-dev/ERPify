<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application;

use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Documents\Document\Domain\Repository\DocumentCustodyRepository;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Shared\Privacy\Application\PersonReferenceErasure;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;

/**
 * Removes a person's id from everything Documents persists, and is the named owner of that erasure for every
 * person-id column of the context (`api/.person-reference-policy`): `document.uploader_id` and
 * `document_custody.actor_id`.
 *
 * **It anonymises references and never erases a document.** The uploader is not the subject of the evidence,
 * and erasing an employee must not destroy the company's records; content erasure is per document and an
 * authorised decision of its own. So the uploader and the actor become one constant marker, the idempotency
 * hash is nulled (replay protection for that actor ends, accepted), and the digest, the timestamps and the
 * state stay.
 *
 * Two statements in the caller's transaction, in the lock order the context declares: the subject's documents
 * first, then the custody rows naming them, each in ascending id. A document's custody rows travel with it, so
 * a flow that locks a document and then appends to its custody takes the same two tables in the same order.
 * Neither statement filters on state, so a document in `Erased` is rewritten like any other.
 *
 * Reached through {@see PersonReferenceErasure}, the seam both the identity erasure and its re-sweep call,
 * so neither imports this context. A failure here propagates and aborts the erasure whole.
 */
final readonly class PurgeUserDocumentReferences implements PersonReferenceErasure
{
    public function __construct(
        private DocumentRepository $documents,
        private DocumentCustodyRepository $documentCustody,
    ) {
    }

    #[Override]
    public function eraseReferencesTo(string $subjectId): int
    {
        Uuid::ensure($subjectId);

        // The marker names nobody: "erasing" it would re-rewrite every anonymised row and report them as a count.
        if (ErasedPersonMarker::UUID === \strtolower($subjectId)) {
            return 0;
        }

        $documentsRewritten = $this->documents->anonymiseUploader($subjectId);
        $custodyRowsRewritten = $this->documentCustody->anonymiseActor($subjectId);

        return $documentsRewritten + $custodyRowsRewritten;
    }
}
