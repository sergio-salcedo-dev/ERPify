<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use Erpify\Shared\Persistence\Application\TransactionManager;
use Override;

/**
 * {@see TransactionManager} that runs the unit of work inline, so a use-case test drives the real
 * save-and-publish body without a database. Kept local to the Session module's tests.
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
