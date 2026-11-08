<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload;

use DateTimeImmutable;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Storage\ObjectNotFound;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finishes the promotion a writer did not: a `Received` row older than
 * {@see \Erpify\Documents\Document\Domain\UploadLimits::ROLL_FORWARD_AGE_SECONDS} whose `promoted_at` is still
 * NULL had its commit and lost its writer before the rename, the sync or the compare-and-set. A committed row
 * owns its staged object, so this — never the reaper — is what recovers it.
 *
 * Each row is taken under its own lock and rechecked there: still `Received`, still not promoted. An `Erased`
 * row is skipped, since its object belongs to the erasure's delete step. The move accepts a final object that
 * is already there and never overwrites it; only the run that set `promoted_at` wakes the scan.
 *
 * A row whose object is in neither namespace is left as it is and counted: the scheduled report alarms on it.
 * A row that fails for any other reason is logged and passed over, so it never stops the rows behind it.
 */
final readonly class RollForwardPromotions
{
    public function __construct(
        private DocumentRepository $documents,
        private PromoteDocument $promoteDocument,
        private DocumentScanWakeUp $scanWakeUp,
        private TransactionManager $transactionManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int how many rows this run promoted
     */
    public function run(int $olderThanSeconds, int $limit): int
    {
        $promoted = 0;

        foreach ($this->documents->awaitingPromotion($olderThanSeconds, $limit) as $documentId) {
            try {
                $marked = $this->rollForward($documentId);
            } catch (ObjectNotFound) {
                continue;
            } catch (Throwable $throwable) {
                $this->logger->error('A document could not be rolled forward; the batch goes on.', [
                    'documentId' => $documentId,
                    'exceptionClass' => $throwable::class,
                ]);

                continue;
            }

            if ($marked) {
                ++$promoted;
                $this->scanWakeUp->wake($documentId);
            }
        }

        return $promoted;
    }

    private function rollForward(string $documentId): bool
    {
        return $this->transactionManager->transactional(function () use ($documentId): bool {
            $document = $this->documents->lockForUpdate($documentId);

            $promoted = $document->promotedAt() instanceof DateTimeImmutable;

            if (DocumentState::Received !== $document->state() || $promoted) {
                return false;
            }

            $this->promoteDocument->moveToFinal($document->scopeId());

            return $this->documents->markPromoted($documentId);
        });
    }
}
