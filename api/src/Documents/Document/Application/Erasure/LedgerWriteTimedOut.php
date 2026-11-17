<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Erasure;

use RuntimeException;

/**
 * The write ran out of time, so whether the entry landed is unknown. The erasure is not performed by the call that
 * raised it, and an entry that did land is completed forward by the completion sweep.
 */
final class LedgerWriteTimedOut extends RuntimeException
{
    public static function create(): self
    {
        return new self('LedgerWriteTimedOut');
    }
}
