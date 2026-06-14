<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountCounter;
use Override;

/**
 * In-memory {@see BankAccountCounter} returning a fixed bankId => count map and recording the ids it
 * was queried with, so a test can assert both the enrichment result and the batched call shape
 * (e.g. an empty page passes an empty id list).
 *
 * @internal
 */
final class InMemoryBankAccountCounter implements BankAccountCounter
{
    /** @var list<list<string>> every argument the port was called with, in order */
    public array $receivedBankIds = [];

    /**
     * @param array<string, int> $counts bankId => account count; ids absent from the map resolve to 0
     */
    public function __construct(private readonly array $counts = [])
    {
    }

    #[Override]
    public function countsByBankIds(array $bankIds): array
    {
        $this->receivedBankIds[] = $bankIds;

        return \array_intersect_key($this->counts, \array_flip($bankIds));
    }
}
