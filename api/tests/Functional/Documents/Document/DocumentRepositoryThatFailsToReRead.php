<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Entity\DocumentCustody;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Override;
use RuntimeException;

/**
 * Wraps the production document repository so that `findById()` answers once and then fails: the snapshot a read
 * begins with succeeds, and the re-read that decides whether an erasure explains a failure does not.
 *
 * @internal
 */
final class DocumentRepositoryThatFailsToReRead implements DocumentRepository
{
    private int $lookups = 0;

    public function __construct(private readonly DocumentRepository $inner)
    {
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
        return $this->inner->anonymiseUploader($subjectId);
    }

    #[Override]
    public function findById(string $id): ?Document
    {
        if (++$this->lookups > 1) {
            throw new RuntimeException('the database went away');
        }

        return $this->inner->findById($id);
    }

    #[Override]
    public function findByIdempotencyKeyHash(string $keyHash): ?Document
    {
        return $this->inner->findByIdempotencyKeyHash($keyHash);
    }

    #[Override]
    public function existsForScope(string $scopeId): bool
    {
        return $this->inner->existsForScope($scopeId);
    }

    #[Override]
    public function findIdByScope(string $scopeId): ?string
    {
        return $this->inner->findIdByScope($scopeId);
    }

    #[Override]
    public function markPromoted(string $id): bool
    {
        return $this->inner->markPromoted($id);
    }

    #[Override]
    public function awaitingPromotion(int $olderThanSeconds, int $limit, ?string $afterId = null): array
    {
        return $this->inner->awaitingPromotion($olderThanSeconds, $limit, $afterId);
    }
}
