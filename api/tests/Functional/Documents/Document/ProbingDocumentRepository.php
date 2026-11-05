<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Closure;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Entity\DocumentCustody;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Override;

/**
 * Wraps the production document repository so a test can ask, at the instant the uploader rewrite is about to
 * run, what the transaction already holds.
 *
 * @internal
 */
final readonly class ProbingDocumentRepository implements DocumentRepository
{
    /**
     * @param Closure(): void $beforeAnonymise
     */
    public function __construct(
        private DocumentRepository $inner,
        private Closure $beforeAnonymise,
    ) {
    }

    #[Override]
    public function add(Document $document, DocumentCustody $firstCustody): void
    {
        $this->inner->add($document, $firstCustody);
    }

    #[Override]
    public function lockForUpdate(string $id): Document
    {
        return $this->inner->lockForUpdate($id);
    }

    #[Override]
    public function anonymiseUploader(string $subjectId): int
    {
        ($this->beforeAnonymise)();

        return $this->inner->anonymiseUploader($subjectId);
    }
}
