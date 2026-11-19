<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Erasure;

use RuntimeException;

/**
 * The entry is certainly not durable: restic exited at once (nothing configured, a refused credential, a missing
 * binary). Carries no cause from the adapter, which can quote a repository URL.
 */
final class LedgerWriteFailed extends RuntimeException
{
    public static function create(): self
    {
        return new self('LedgerWriteFailed');
    }
}
