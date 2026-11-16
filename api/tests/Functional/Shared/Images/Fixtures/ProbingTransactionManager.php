<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Images\Fixtures;

use Closure;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Override;

/**
 * The real transaction manager, with a probe run inside the Nth unit of work AFTER its operation has done its work and
 * BEFORE the commit — the one moment at which "written, not yet visible to another connection" can be observed.
 *
 * @internal
 */
final class ProbingTransactionManager implements TransactionManager
{
    private int $calls = 0;

    /**
     * @param Closure(): void $beforeCommit
     */
    public function __construct(
        private readonly TransactionManager $inner,
        private readonly int $probeOnCall,
        private readonly Closure $beforeCommit,
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
        if (++$this->calls !== $this->probeOnCall) {
            return $this->inner->transactional($operation);
        }

        return $this->inner->transactional(function () use ($operation): mixed {
            $result = $operation();
            ($this->beforeCommit)();

            return $result;
        });
    }
}
