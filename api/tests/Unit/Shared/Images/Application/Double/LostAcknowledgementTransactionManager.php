<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application\Double;

use Closure;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Override;
use RuntimeException;

/**
 * A {@see TransactionManager} whose Nth unit of work COMMITS and then reports a failure: the commit landed and its
 * acknowledgement was lost, the one outcome in which a thrown error does not mean the work was undone.
 * `$afterCommit` runs between the commit and the error, which is where a concurrent writer gets in.
 *
 * @internal
 */
final class LostAcknowledgementTransactionManager implements TransactionManager
{
    private int $calls = 0;

    /**
     * @param (Closure(): void)|null $afterCommit
     */
    public function __construct(
        private readonly TransactionManager $inner,
        private readonly int $lostOnCall,
        private readonly ?Closure $afterCommit = null,
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
        $result = $this->inner->transactional($operation);

        if (++$this->calls === $this->lostOnCall) {
            if ($this->afterCommit instanceof Closure) {
                ($this->afterCommit)();
            }

            throw new RuntimeException('the commit was acknowledged to nobody');
        }

        return $result;
    }
}
