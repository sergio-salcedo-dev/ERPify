<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Read;

/**
 * Whether the erasure ledger's override is set: the one documented way a restore proceeds while the ledger cannot
 * be read in full. While it is, no document is served, whatever `documents.read_enabled` says, because a restored
 * database may hold a document whose erasure the ledger recorded and the restore lost.
 *
 * The read route only ASKS. What sets and clears the flag, and the record it leaves, belong to the erasure ledger;
 * until that exists the bound adapter never reports it set.
 */
interface ErasureLedgerOverride
{
    public function isActive(): bool;
}
