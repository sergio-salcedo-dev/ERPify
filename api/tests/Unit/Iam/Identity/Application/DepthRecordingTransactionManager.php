<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Shared\Persistence\Application\TransactionManager;
use Override;

/**
 * Inline {@see TransactionManager} that exposes how deeply nested the current call is, so a test can assert a
 * write happens inside the transaction a use case opened rather than after it.
 *
 * @internal
 */
final class DepthRecordingTransactionManager implements TransactionManager
{
    public int $depth = 0;

    #[Override]
    public function transactional(callable $operation): mixed
    {
        ++$this->depth;

        try {
            return $operation();
        } finally {
            --$this->depth;
        }
    }
}
