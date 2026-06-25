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
 */
final readonly class PostgresAdvisoryLock
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Runs $work while holding the advisory lock named $name; returns false without running it if the lock
     * is already held by another session. `hashtext` maps the name to the `bigint` key the lock takes, so
     * callers name the lock by intent rather than juggling magic integers.
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
