<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Shared\Persistence\Application\TransactionManager;
use Override;

/**
 * {@see TransactionManager} that runs the unit of work inline, with no real transaction — the boundary a use
 * case owns without any database. Lets a test exercise the commit path (save + publish) deterministically.
 *
 * @internal
 */
final class InlineTransactionManager implements TransactionManager
{
    #[Override]
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
