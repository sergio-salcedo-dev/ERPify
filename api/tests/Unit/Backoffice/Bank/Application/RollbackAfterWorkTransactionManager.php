<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Persistence\Domain\Exception\ReferentialIntegrityViolation;
use Erpify\Tests\Unit\Shared\Images\Application\Double\RollbackingTransactionManager;
use Override;
use RuntimeException;

/**
 * The flush-time foreign-key refusal over a manager that UNDOES what the refused unit did to the state enrolled with
 * it: the operation issues its writes and publications, the commit is refused, and what survives is what the rollback
 * left. The plain failing double leaves the effects in place, so it can prove the use case acted but not that a
 * rolled-back delete left no event and no release behind.
 *
 * @internal
 */
final readonly class RollbackAfterWorkTransactionManager implements TransactionManager
{
    public function __construct(private RollbackingTransactionManager $inner)
    {
    }

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
        return $this->inner->transactional(static function () use ($operation): never {
            $operation();

            throw new ReferentialIntegrityViolation(new RuntimeException('foreign key constraint'));
        });
    }
}
