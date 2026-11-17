<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Erasure;

use RuntimeException;

/**
 * The ledger cannot be read in full. Carries no cause from the adapter, which can quote a repository URL.
 */
final class LedgerUnreadable extends RuntimeException
{
    public static function create(): self
    {
        return new self('LedgerUnreadable');
    }
}
