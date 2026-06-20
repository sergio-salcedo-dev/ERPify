<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Application;

/**
 * Persists, per projection, the last `sequence` applied. The checkpoint makes catch-up idempotent
 * and ordered in both live and rebuild — the same mechanism — by never applying a `sequence` at or
 * below it. {@see lockAndRead()} serialises concurrent runs of the same projection.
 */
interface ProjectionCheckpointStore
{
    /**
     * Locks the projection's checkpoint row `FOR UPDATE` (creating it at 0 if absent) and returns its
     * `last_sequence`. The lock is held until the surrounding transaction commits.
     */
    public function lockAndRead(string $name): int;

    public function advance(string $name, int $sequence): void;

    public function reset(string $name): void;
}
