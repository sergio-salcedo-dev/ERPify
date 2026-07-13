<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Application;

use Erpify\Shared\Persistence\Application\TransactionManager;
use Override;

/**
 * {@see TransactionManager} that runs the unit of work inline, so a use-case test drives the real
 * retire-then-act body without a database. Kept local to the Invitation module's tests.
 *
 * @internal
 */
final readonly class InlineTransactionManager implements TransactionManager
{
    #[Override]
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
