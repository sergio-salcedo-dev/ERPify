<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Scanning;

use Erpify\Documents\Document\Application\AppendCustody;
use Erpify\Documents\Document\Domain\CustodyEvent;
use Erpify\Documents\Document\Domain\Scanning\DocumentScanLeases;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Ends in `ScanFailed` a `Received` row found at or above a cap: a constant lowered at a deploy leaves rows the
 * claim predicate no longer admits and no return would ever reach. The compare-and-set re-evaluates the cap, so a
 * row an operator reopened meanwhile is left alone.
 */
final readonly class SweepCappedDocuments
{
    public const int BATCH = 100;

    public function __construct(
        private DocumentScanLeases $leases,
        private AppendCustody $custody,
        private TransactionManager $transactionManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int how many documents this run ended
     */
    public function run(int $limit = self::BATCH): int
    {
        $failed = 0;

        foreach ($this->leases->cappedCandidates($limit) as $documentId) {
            try {
                $failed += $this->failOne($documentId) ? 1 : 0;
            } catch (Throwable $throwable) {
                $this->logger->error('A capped document could not be ended; the batch goes on.', [
                    'documentId' => $documentId,
                    'exceptionClass' => $throwable::class,
                ]);
            }
        }

        return $failed;
    }

    private function failOne(string $documentId): bool
    {
        return $this->transactionManager->transactional(function () use ($documentId): bool {
            if (!$this->leases->failCapped($documentId)) {
                return false;
            }

            $this->custody->append($documentId, CustodyEvent::ScanFailed, null);

            return true;
        });
    }
}
