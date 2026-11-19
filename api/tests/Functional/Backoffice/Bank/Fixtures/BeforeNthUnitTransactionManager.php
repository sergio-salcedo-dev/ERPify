<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Bank\Fixtures;

use Closure;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Override;

/**
 * The real transaction manager, with a hook that runs ahead of the Nth unit of work and outside it: a competing
 * writer's whole transaction lands in the window between two units of one use case, which is where an image minted
 * by the first has not yet been attached by the second.
 *
 * @internal
 */
final class BeforeNthUnitTransactionManager implements TransactionManager
{
    private int $calls = 0;

    /**
     * @param Closure(): void $beforeUnit
     */
    public function __construct(
        private readonly TransactionManager $inner,
        private readonly int $unit,
        private readonly Closure $beforeUnit,
    ) {
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
        if (++$this->calls === $this->unit) {
            ($this->beforeUnit)();
        }

        return $this->inner->transactional($operation);
    }
}
