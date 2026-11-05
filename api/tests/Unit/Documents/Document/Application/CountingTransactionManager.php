<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Application;

use Erpify\Shared\Persistence\Application\TransactionManager;
use Override;

/**
 * Runs the unit of work inline and counts how many times it was entered: the number of attempts a retry loop
 * made is the observable, and a loop that re-ran only an INSERT instead of the whole closure would not show
 * in the result.
 *
 * @internal
 */
final class CountingTransactionManager implements TransactionManager
{
    public int $entered = 0;

    #[Override]
    public function transactional(callable $operation): mixed
    {
        ++$this->entered;

        return $operation();
    }
}
