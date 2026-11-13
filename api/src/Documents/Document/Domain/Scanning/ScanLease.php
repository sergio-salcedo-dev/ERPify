<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Scanning;

/**
 * A `Scanning` row whose lease the database found expired, named with the epoch it held so the return that follows
 * is a compare-and-set on that very claim.
 */
final readonly class ScanLease
{
    public function __construct(
        public string $documentId,
        public int $epoch,
    ) {
    }
}
