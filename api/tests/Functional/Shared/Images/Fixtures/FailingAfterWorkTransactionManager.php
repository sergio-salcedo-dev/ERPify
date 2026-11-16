<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Images\Fixtures;

use Erpify\Shared\Persistence\Application\TransactionManager;
use Override;
use RuntimeException;

/**
 * The real transaction manager, except that the Nth unit of work it runs fails AFTER its operation has done its work
 * and BEFORE the commit — the one place a rollback has something to take back: the holder's update and the queued
 * release are both already written when it throws.
 *
 * @internal
 */
final class FailingAfterWorkTransactionManager implements TransactionManager
{
    private int $calls = 0;

    public function __construct(private readonly TransactionManager $inner, private readonly int $failOnCall)
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
        if (++$this->calls !== $this->failOnCall) {
            return $this->inner->transactional($operation);
        }

        return $this->inner->transactional(static function () use ($operation): never {
            $operation();

            throw new RuntimeException('the unit of work failed after its work and before its commit');
        });
    }
}
