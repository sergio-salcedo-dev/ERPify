<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Persistence\Double;

use Erpify\Shared\Persistence\Application\TransactionManager;
use Override;
use Throwable;

/**
 * Test double for {@see TransactionManager} that runs the operation immediately, in no transaction. It lets
 * a unit test drive a use case's orchestration without a database — atomicity itself is covered by the
 * functional tests against real Postgres.
 *
 * The three counters are what make a boundary assertable at all. Without them a test can only say the writes
 * happened, which is equally true of a use case that opens no transaction and of one that opens a different
 * number than it should; and "how many" is a real guarantee where a caller loops (one transaction per item
 * versus one around the loop is the difference between a resumable checkpoint and an all-or-nothing batch).
 * {@see self::$committed} also separates "after the boundary closed" from "inside it" — a post-commit effect
 * asserted only as "it happened" passes just the same when it runs within the transaction.
 */
final class ImmediateTransactionManager implements TransactionManager
{
    /** Units of work entered. */
    public int $opened = 0;

    /** Units of work whose operation returned, so an effect can be pinned to the far side of the boundary. */
    public int $committed = 0;

    /** Units of work whose operation threw — the rollback path. */
    public int $abandoned = 0;

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
        ++$this->opened;

        try {
            $result = $operation();
        } catch (Throwable $throwable) {
            ++$this->abandoned;

            throw $throwable;
        }

        ++$this->committed;

        return $result;
    }
}
