<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static gate over the one statement that deletes from `audit_log`, for the one clause of it no behavioural
 * test reaches: `FOR UPDATE`.
 *
 * The prune is the third member of a closed set of three mutations over this table. The other two take their
 * rows in `id` order — {@see \Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditSubjectRowLock} and
 * {@see \Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditActorAnonymiser} both select
 * `… ORDER BY id FOR UPDATE` — so the prune taking its rows in any other order is an ABBA against either.
 *
 * **`ORDER BY id` alone does not buy that, which is the trap this gate exists for.** Measured on the shipped
 * statement: the outer `DELETE` plans as `Nested Loop ← HashAggregate ← <ordered subplan>`, and the aggregate
 * discards the subquery's order, so the probe runs in hash order however the subplan was sorted. With
 * `FOR UPDATE` the plan gains a `LockRows` node directly above the ordered scan and below the aggregate, so
 * every lock is taken in `id` order before the outer statement touches anything.
 *
 * Nothing else goes red on deleting the clause: both functional cases assert which rows survive, which the
 * exemption in the inner `WHERE` decides on its own, and lock ORDER is only observable against a concurrent
 * contender — two racing transactions, not one holding and one probing. This is the weakest instrument in
 * the repo and it is used here for the same reason {@see AdministratorSetLockStatementGateTest} uses it.
 *
 * **What a green proves:** the statement still carries the ordering and the lock, once. Not that the prune
 * runs, not that a caller reaches it, and not that the two erasure paths still order their own acquisitions
 * — that last one is the sibling gate's job.
 *
 * @internal
 */
#[CoversNothing]
final class AuditPruneStatementGateTest extends TestCase
{
    private const string PRUNER = __DIR__
        . '/../../../../src/Shared/Audit/Infrastructure/Persistence/DbalAuditLogPruner.php';

    #[Test]
    public function theBatchingSelectIsOrderedAndLocked(): void
    {
        // The clauses are pinned as a sequence: `FOR UPDATE` before `LIMIT` is a syntax error Postgres would
        // reject, but a sort over a statement that locks nothing — the shape that shipped — is silently
        // wrong, and so is a lock over an unordered scan.
        $this->assertMatchesRegularExpression(
            '/ORDER\s+BY\s+id\s+LIMIT\s+:batch\s+FOR\s+UPDATE/',
            $this->prunerSource(),
            'The prune no longer batches as `… ORDER BY id LIMIT :batch FOR UPDATE`. Both clauses carry the '
            . 'invariant, and the lock is the half that is easy to lose: without it the ordered scan takes no '
            . 'locks at all, the outer DELETE re-locks through a HashAggregate in hash order, and the prune '
            . 'is free to deadlock against either erasure path. Nothing else in the suite goes red on it.',
        );
    }

    #[Test]
    public function theTableIsDeletedFromByExactlyOneStatement(): void
    {
        // A second delete path is how the ordering drifts: one statement keeps the lock, the other does not,
        // and the closed set of three mutations quietly becomes four with a member nobody ordered.
        $this->assertSame(
            1,
            \preg_match_all('/DELETE\s+FROM\s+audit_log/i', $this->prunerSource()),
            'The pruner issues more than one DELETE against audit_log, so the two can drift apart on the '
            . 'ordering and the lock.',
        );
    }

    private function prunerSource(): string
    {
        $path = \realpath(self::PRUNER);

        $this->assertIsString($path, 'The pruner this gate reads has moved; the gate is asserting nothing.');

        $source = \file_get_contents($path);

        $this->assertIsString($source, 'The pruner source could not be read; the gate is asserting nothing.');

        return $source;
    }
}
