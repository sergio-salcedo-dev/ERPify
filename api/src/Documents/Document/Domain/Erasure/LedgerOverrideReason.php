<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Erasure;

/**
 * Why an operator lets a restore proceed without having read the erasure ledger in full: closed, because the record
 * the override leaves holds no free text and no name.
 */
enum LedgerOverrideReason: string
{
    case LedgerUnreachable = 'ledger_unreachable';
    case LedgerCorrupt = 'ledger_corrupt';
    case LedgerLost = 'ledger_lost';
}
