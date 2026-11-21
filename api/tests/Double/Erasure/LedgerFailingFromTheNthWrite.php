<?php

declare(strict_types=1);

namespace Erpify\Tests\Double\Erasure;

use Erpify\Documents\Document\Application\Erasure\ErasureLedger;
use Erpify\Documents\Document\Application\Erasure\LedgerWriteFailed;
use Override;

/**
 * A ledger that records every write it is asked for and refuses, as certainly not durable, from the `$failing`-th on:
 * what a run erasing several scopes meets when the ledger host goes away part-way.
 *
 * @internal
 */
final class LedgerFailingFromTheNthWrite implements ErasureLedger
{
    /** @var list<string> */
    private array $attempts = [];

    public function __construct(
        private readonly int $failing,
    ) {
    }

    #[Override]
    public function record(string $scopeId): void
    {
        $this->attempts[] = $scopeId;

        if (\count($this->attempts) >= $this->failing) {
            throw LedgerWriteFailed::create();
        }
    }

    /**
     * @return list<string> every scope a write was asked for, in order, including the refused ones
     */
    public function attempts(): array
    {
        return $this->attempts;
    }

    #[Override]
    public function entries(): array
    {
        return [];
    }

    #[Override]
    public function verifyReadableInFull(): void
    {
    }
}
