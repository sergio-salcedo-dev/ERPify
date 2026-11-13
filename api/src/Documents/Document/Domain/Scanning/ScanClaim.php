<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Scanning;

/**
 * A claim the caller won: the epoch every later transition of this scan must carry, and whether the first claim
 * since the last operator decision had been recorded in custody before this one.
 */
final readonly class ScanClaim
{
    public function __construct(
        public string $documentId,
        public int $epoch,
        public bool $firstClaimWasRecorded,
    ) {
    }
}
