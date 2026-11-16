<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application\Double;

use Erpify\Shared\Persistence\Application\TransactionManager;
use Override;
use Throwable;

/**
 * A {@see TransactionManager} that undoes what the failed unit of work did to the state it was handed, which is the
 * one property the compensation protocol turns on: what a failed transaction published or attached is gone before
 * the next transaction starts. It is a double — atomicity itself is the functional test's, against Postgres.
 *
 * Every unit of work is numbered, so a collaborator can record WHICH one it ran in.
 *
 * @internal
 */
final class RollbackingTransactionManager implements TransactionManager
{
    /** The number of the unit of work now running; 0 outside any. */
    public int $current = 0;

    /** Units of work started so far. */
    public int $started = 0;

    /** @var list<Snapshottable> */
    private array $state = [];

    /**
     * Puts the state a failed unit of work is undone on; holders and buses built over this manager enrol here.
     */
    public function track(Snapshottable $part): void
    {
        $this->state[] = $part;
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
        $snapshots = \array_map(
            static fn (Snapshottable $part): array => [$part, $part->snapshot()],
            $this->state,
        );
        $this->current = ++$this->started;

        try {
            return $operation();
        } catch (Throwable $throwable) {
            foreach ($snapshots as [$part, $snapshot]) {
                $part->restore($snapshot);
            }

            throw $throwable;
        } finally {
            $this->current = 0;
        }
    }
}
