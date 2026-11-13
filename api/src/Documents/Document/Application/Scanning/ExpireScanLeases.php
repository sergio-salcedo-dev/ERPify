<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Scanning;

use Erpify\Documents\Document\Application\AppendCustody;
use Erpify\Documents\Document\Domain\CustodyEvent;
use Erpify\Documents\Document\Domain\Scanning\DocumentScanLeases;
use Erpify\Documents\Document\Domain\Scanning\MalwareScanner;
use Erpify\Documents\Document\Domain\Scanning\ReturnOutcome;
use Erpify\Documents\Document\Domain\Scanning\ScanLease;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Takes back a scan whose worker died or stalled: a `Scanning` row whose lease the database finds lapsed returns to
 * `Received` under the same accounting as any other return with no verdict. Each return is a compare-and-set on
 * the stale epoch and on `lease_expires_at < NOW()`, evaluated inside the UPDATE, so a worker that heartbeats at the
 * last moment wins and this sweep changes nothing.
 *
 * The scanner is probed once per run, and only when there is a lapsed lease to account.
 */
final readonly class ExpireScanLeases
{
    public const int BATCH = 100;

    public function __construct(
        private DocumentScanLeases $leases,
        private MalwareScanner $scanner,
        private AppendCustody $custody,
        private TransactionManager $transactionManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int how many leases this run took back
     */
    public function run(int $limit = self::BATCH): int
    {
        $lapsed = $this->leases->expiredLeases($limit);

        if ([] === $lapsed) {
            return 0;
        }

        $health = new ScannerHealth($this->scanner);
        $returned = 0;

        foreach ($lapsed as $lease) {
            $returned += $this->expireOne($lease, $health) ? 1 : 0;
        }

        return $returned;
    }

    private function expireOne(ScanLease $lease, ScannerHealth $health): bool
    {
        try {
            // Asked before the transaction opens: a probe is a network wait, and no lock is held across it.
            $down = $health->isDown();
            $outcome = $this->transactionManager->transactional(
                function () use ($lease, $down): ReturnOutcome {
                    $outcome = $this->leases->returnToReceived($lease->documentId, $lease->epoch, $down, true);

                    if (ReturnOutcome::Failed === $outcome) {
                        $this->custody->append($lease->documentId, CustodyEvent::ScanFailed, null);
                    }

                    return $outcome;
                },
            );
        } catch (Throwable $throwable) {
            $this->logger->error('A lapsed scan lease could not be taken back; the batch goes on.', [
                'documentId' => $lease->documentId,
                'exceptionClass' => $throwable::class,
            ]);

            return false;
        }

        if (ReturnOutcome::Failed === $outcome) {
            $this->logger->error('A document reached its scan return cap and will not be retried.', [
                'documentId' => $lease->documentId,
                'reason' => 'return-cap-reached',
            ]);
        }

        return ReturnOutcome::Lost !== $outcome;
    }
}
