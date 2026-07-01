<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Persistence\Double;

use Erpify\Shared\Persistence\Application\TransactionManager;
use Override;

/**
 * Test double for {@see TransactionManager} that runs the operation immediately, in no transaction. It lets
 * a unit test drive a use case's orchestration without a database — atomicity itself is covered by the
 * functional tests against real Postgres.
 */
final class ImmediateTransactionManager implements TransactionManager
{
    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    #[Override]
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
