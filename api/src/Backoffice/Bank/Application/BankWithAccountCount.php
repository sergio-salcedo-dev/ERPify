<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;

/**
 * Transient read-side carrier pairing a {@see Bank} aggregate with its derived account count, so the
 * count travels Application -> Infrastructure without being hung on the entity. The list searcher
 * returns a batch of these (count resolved in one port call, no N+1); the detail finder returns one.
 * The HTTP mapper reads `$bank` + `$accountCount` to build the resource DTO — the aggregate stays
 * unmutated and no parallel id-keyed map is threaded through the call chain.
 */
final readonly class BankWithAccountCount
{
    public function __construct(
        public Bank $bank,
        public int $accountCount,
    ) {
    }
}
