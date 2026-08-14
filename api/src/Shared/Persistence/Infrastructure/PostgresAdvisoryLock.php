<?php

declare(strict_types=1);

namespace Erpify\Shared\Persistence\Infrastructure;

use Doctrine\DBAL\Connection;
use Throwable;

/**
 * A named, cooperative Postgres advisory lock for serialising background maintenance jobs (e.g. retention
 * prunes) across workers. It is **session**-level (`pg_try_advisory_lock`), not transaction-level, on
 * purpose: a chunked job runs many short autocommitting statements, and a transaction-scoped lock would
 * drop the moment the first chunk commits — defeating the "one sweep at a time" guarantee.
 *
 * It is best-effort by design: {@see withTryLock()} returns `false` without running the work when the lock
 * is already held, so a job that is already in flight is skipped rather than queued. The lock is always
 * released, even if the work throws.
 *
 * **The key space is 32 bits wide, and that is a property of this mapping and not of the lock.** `hashtext`
 * returns `int4`, so the single-argument `pg_try_advisory_lock(bigint)` overload is reached by widening it:
 * a negative hash sign-extends, leaving the high word carrying the sign bit and nothing else (measured,
 * `classid = 0xFFFFFFFF` and `objsubid = 1`). Only 2^32 keys are therefore reachable, and every caller of
 * this class shares that one space. A collision would be silent and would read as ordinary contention —
 * a job skipping because an *unrelated* one holds the lock, which is indistinguishable from the case the
 * skip exists to express. Nothing guards this: the functional test drives both of its sessions through the
 * *same* name, so it pins mutual exclusion and cannot, by construction, tell a collision from a hit.
 *
 * **Revisit when a second lock name lands.** One name cannot collide with itself, which is the only reason
 * the width above is acceptable. The answer at that point is the two-argument
 * `pg_try_advisory_lock(int, int)` overload, which namespaces the hash under a caller-owned first key
 * rather than widening what `hashtext` can produce.
 */
final readonly class PostgresAdvisoryLock
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Runs $work while holding the advisory lock named $name; returns false without running it if the lock
     * is already held by another session. `hashtext` maps the name to the lock key, so callers name the lock
     * by intent rather than juggling magic integers — at the cost in key space stated on the class.
     *
     * @param callable(): void $work
     */
    public function withTryLock(string $name, callable $work): bool
    {
        $acquired = (bool) $this->connection->fetchOne(
            'SELECT pg_try_advisory_lock(hashtext(:name))',
            ['name' => $name],
        );

        if (!$acquired) {
            return false;
        }

        try {
            $work();
        } finally {
            $this->release($name);
        }

        return true;
    }

    private function release(string $name): void
    {
        try {
            $this->connection->fetchOne('SELECT pg_advisory_unlock(hashtext(:name))', ['name' => $name]);
        } catch (Throwable) {
            // A lost connection already dropped the session lock; nothing left to release.
        }
    }
}
