<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Erasure;

use DateTimeImmutable;

/**
 * One scope of the ledger and when its first entry was written. restic stamps the time on the WRITING host, which is
 * this application's, so the completion sweep compares a clock with itself across the minutes between the write and the
 * sweep; its margin covers drift in that interval, not skew between two machines.
 */
final readonly class LedgerEntry
{
    public function __construct(
        public string $scopeId,
        public DateTimeImmutable $recordedAt,
    ) {
    }
}
