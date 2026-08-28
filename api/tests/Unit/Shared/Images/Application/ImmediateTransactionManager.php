<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use Erpify\Shared\Persistence\Application\TransactionManager;
use Override;
use Throwable;

/**
 * Runs the unit of work without a database, recording whether it was entered and whether it completed, so
 * a test can assert what happened INSIDE the boundary rather than only what came out of it.
 *
 * @internal
 */
final class ImmediateTransactionManager implements TransactionManager
{
    public int $entered = 0;
    public int $committed = 0;

    /** @var list<string> */
    public array $order = [];

    #[Override]
    public function transactional(callable $operation): mixed
    {
        ++$this->entered;
        $this->order[] = 'transaction:begin';

        try {
            $result = $operation();
        } catch (Throwable $failure) {
            $this->order[] = 'transaction:rollback';

            throw $failure;
        }

        ++$this->committed;
        $this->order[] = 'transaction:commit';

        return $result;
    }
}
