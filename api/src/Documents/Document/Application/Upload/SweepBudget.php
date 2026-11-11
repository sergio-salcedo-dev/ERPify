<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload;

/**
 * What one scheduled sweep may spend. `$limit` counts the items it finishes, but a page of candidates that never
 * leaves the candidate set (an object lost from the volume, a row whose handling keeps failing) would otherwise be
 * walked in full every tick, so the run also stops after examining a multiple of the limit and after a run of
 * failures in a row — the signature of a store or a database that is down rather than of one bad item.
 */
final class SweepBudget
{
    /** Candidates examined per unit of the limit before the run gives up looking for more to finish. */
    public const int EXAMINED_PER_LIMIT = 10;

    /** Failures in a row after which the cause is the store or the database, not one item. */
    public const int MAX_CONSECUTIVE_FAILURES = 5;

    private int $finished = 0;

    private int $examined = 0;

    private int $failuresInARow = 0;

    public function __construct(private readonly int $limit)
    {
    }

    public function exhausted(): bool
    {
        return $this->limit < 1
            || $this->finished >= $this->limit
            || $this->examined >= $this->limit * self::EXAMINED_PER_LIMIT
            || $this->failuresInARow >= self::MAX_CONSECUTIVE_FAILURES;
    }

    public function finished(): void
    {
        ++$this->finished;
        ++$this->examined;
        $this->failuresInARow = 0;
    }

    /** An item looked at and left as it is: nothing finished, nothing broken. */
    public function passed(): void
    {
        ++$this->examined;
        $this->failuresInARow = 0;
    }

    public function failed(): void
    {
        ++$this->examined;
        ++$this->failuresInARow;
    }

    public function finishedCount(): int
    {
        return $this->finished;
    }

    public function stoppedOnFailures(): bool
    {
        return $this->failuresInARow >= self::MAX_CONSECUTIVE_FAILURES;
    }
}
