<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Scanning;

use Erpify\Documents\Document\Domain\Scanning\DocumentScanLeases;
use Erpify\Documents\Document\Domain\Scanning\MalwareScanner;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The scan sweep: the only driver of a scan. The row is the obligation (`state`, `promoted_at`, `next_scan_at` and
 * the two counters), and the {@see \Erpify\Documents\Document\Infrastructure\Messenger\ScanDocumentMessage} only wakes
 * a pass early, so a lost or duplicate message changes nothing.
 *
 * A scanner that fails its probe leaves every `Received` row untouched: no claim, no epoch, no attempt, no custody
 * row, so an outage never moves a document toward `ScanFailed`. Once a return finds the scanner down the run claims
 * nothing more.
 *
 * A pass is bounded in documents and in the time it may START another, because the scheduler worker that runs it
 * also owns the upload reaper's one-minute cadence, whose margin is computed on it.
 *
 * A document whose scan raises an unexpected fault is logged by id and passed over, so one poisoned row never stops
 * the batch; its lease lapses and the expiry sweep takes it back.
 */
final readonly class ScanDueDocuments
{
    public const int BATCH = 10;

    /** No new document is started after this many seconds of one pass. */
    public const int START_BUDGET_SECONDS = 60;

    public function __construct(
        private DocumentScanLeases $leases,
        private MalwareScanner $scanner,
        private ScanDocument $scanDocument,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int how many documents this pass left in a terminal state (`Ready`, `Quarantined` or `ScanFailed`)
     */
    public function run(int $limit = self::BATCH): int
    {
        if (!$this->scanner->probe()) {
            return 0;
        }

        $health = new ScannerHealth($this->scanner);
        $started = \hrtime(true);
        $decided = 0;

        foreach ($this->leases->dueCandidates($limit) as $documentId) {
            if ($health->knownDown() || (\hrtime(true) - $started) / 1_000_000_000 >= self::START_BUDGET_SECONDS) {
                break;
            }

            $decided += $this->scanOne($documentId, $health) ? 1 : 0;
        }

        return $decided;
    }

    private function scanOne(string $documentId, ScannerHealth $health): bool
    {
        try {
            $result = $this->scanDocument->scan($documentId, $health);
        } catch (Throwable $throwable) {
            $this->logger->error('A document could not be scanned; the batch goes on.', [
                'documentId' => $documentId,
                'exceptionClass' => $throwable::class,
            ]);

            return false;
        }

        return \in_array($result, [ScanResult::Ready, ScanResult::Quarantined, ScanResult::Failed], true);
    }
}
