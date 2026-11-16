<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application\Double;

use Erpify\Shared\Persistence\Application\TransactionManager;
use Override;
use RuntimeException;

/**
 * A {@see TransactionManager} whose Nth unit of work COMMITS and then reports a failure: the commit landed and its
 * acknowledgement was lost, the one outcome in which a thrown error does not mean the work was undone.
 *
 * @internal
 */
final class LostAcknowledgementTransactionManager implements TransactionManager
{
    private int $calls = 0;

    public function __construct(private readonly TransactionManager $inner, private readonly int $lostOnCall)
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
        $result = $this->inner->transactional($operation);

        if (++$this->calls === $this->lostOnCall) {
            throw new RuntimeException('the commit was acknowledged to nobody');
        }

        return $result;
    }
}
