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
 * The counters make "how many boundaries" assertable, which is a real guarantee wherever a caller loops: one
 * transaction per item versus one around the loop is the difference between a resumable checkpoint and an
 * all-or-nothing batch. They count FRAMES, not logical failures — under nesting an inner failure increments
 * {@see self::$abandoned} once per frame it propagates through.
 *
 * What they cannot do is place an effect on one side of the boundary: a use case that publishes AFTER its
 * unit of work returns still leaves `committed` at 1. {@see self::isInUnitOfWork()} is what answers that, by
 * letting a collaborator record where it was called from — see `RecordingEventBus::$publishedInsideUnitOfWork`.
 */
final class ImmediateTransactionManager implements TransactionManager
{
    /** Units of work entered. */
    public int $opened = 0;

    /** Units of work whose operation returned. */
    public int $committed = 0;

    /** Units of work whose operation threw — the rollback path. */
    public int $abandoned = 0;

    /** Frames currently open, so nesting does not close the boundary early. */
    private int $depth = 0;

    /** True while an operation is running, which is what tells "inside the boundary" from "after it". */
    public function isInUnitOfWork(): bool
    {
        return $this->depth > 0;
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
        ++$this->opened;
        ++$this->depth;

        try {
            $result = $operation();
        } catch (Throwable $throwable) {
            ++$this->abandoned;

            throw $throwable;
        } finally {
            --$this->depth;
        }

        ++$this->committed;

        return $result;
    }
}
