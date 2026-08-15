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
 * It is best-effort by design: {@see withTryLock()} returns `false` without running the work when *another
 * session* already holds the lock, so a job already in flight is skipped rather than queued. The lock is
 * always released, even if the work throws. Within one session it is re-entrant — Postgres's behaviour,
 * not a guarantee this class adds.
 *
 * **The key space is 32 bits wide, and that is a property of this mapping and not of the lock.** `hashtext`
 * returns `int4`, so the single-argument `pg_try_advisory_lock(bigint)` overload is reached by widening it.
 * Measured: `pg_locks` reports `objsubid = 1` for that overload and `2` for the two-argument one, and a
 * negative hash sign-extends, so `audit_log_retention_prune` lands on `classid = 0xFFFFFFFF`,
 * `objid = 4017652434` — a high word carrying the sign bit and nothing else. At most 2^32 keys are
 * therefore reachable, and every caller of this class shares that one space.
 *
 * **A collision between two names is worse than silence, and it fails in opposite directions.** Across
 * sessions the loser skips, and the caller logs that skip *naming a cause* — another worker holding the
 * lock — which a collision makes false, so the failure is misattributed rather than unreported. On a single
 * connection it inverts: re-entrancy means two colliding names both acquire and both run, each believing it
 * holds exclusivity. Nothing guards either direction — `PostgresAdvisoryLockFunctionalTest` drives both of
 * its sessions through the *same* name, so it pins mutual exclusion and cannot, by construction, tell a
 * collision from a hit.
 *
 * **The trigger is a second lock name, and it is already committed**, so this is a scheduled revisit and
 * not a hypothetical one: `docs/adr/maintenance-job-execution-contract.md` records the
 * `handled_domain_event` prune as diverging from the single-executor invariant pending exactly this
 * retrofit. What makes the width acceptable meanwhile is that no two *reachable* names collide — weaker
 * than "there is one name", since the functional test above mints a second. The fix is
 * `hashtextextended(text, bigint)`, which returns `bigint` and fills the single-argument key. The
 * two-argument overload is not a substitute: it partitions the same 32-bit space under a caller-owned
 * first key, so two names owned by one caller collide exactly as often as they do today.
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
