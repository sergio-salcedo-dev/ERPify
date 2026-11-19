<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Closure;
use Erpify\Documents\Document\Domain\Erasure\DocumentErasures;
use Override;

/**
 * The real erasure statements that report, through `$probe`, each time the transaction starts and just before the
 * compare-and-set runs.
 *
 * @internal
 */
final readonly class LockProbingErasures implements DocumentErasures
{
    /**
     * @param Closure(string): void $probe called with `bound` and `before-cas`
     */
    public function __construct(private DocumentErasures $inner, private Closure $probe)
    {
    }

    #[Override]
    public function boundTransactionTime(): void
    {
        $this->inner->boundTransactionTime();
        ($this->probe)('bound');
    }

    #[Override]
    public function markErased(string $documentId): bool
    {
        ($this->probe)('before-cas');

        return $this->inner->markErased($documentId);
    }

    #[Override]
    public function awaitingObjectDeletion(int $limit, ?string $afterId = null): array
    {
        return $this->inner->awaitingObjectDeletion($limit, $afterId);
    }

    #[Override]
    public function markObjectDeleted(string $documentId): bool
    {
        return $this->inner->markObjectDeleted($documentId);
    }

    #[Override]
    public function reopenObjectDeletion(string $documentId): bool
    {
        return $this->inner->reopenObjectDeletion($documentId);
    }

    #[Override]
    public function objectsOverdue(int $olderThanSeconds, int $limit): array
    {
        return $this->inner->objectsOverdue($olderThanSeconds, $limit);
    }

    #[Override]
    public function awaitingDerivativeRelease(int $limit, ?string $afterId = null): array
    {
        return $this->inner->awaitingDerivativeRelease($limit, $afterId);
    }

    #[Override]
    public function markDerivativesReleased(string $documentId): bool
    {
        return $this->inner->markDerivativesReleased($documentId);
    }

    #[Override]
    public function derivativesOverdue(int $olderThanSeconds, int $limit): array
    {
        return $this->inner->derivativesOverdue($olderThanSeconds, $limit);
    }
}
