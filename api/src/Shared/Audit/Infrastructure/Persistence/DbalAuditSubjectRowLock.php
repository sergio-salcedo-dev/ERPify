<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Audit\Application\AuditSubjectRowLock;
use Erpify\Shared\Audit\Domain\AuditResource;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@link AuditSubjectRowLock} over `audit_log` via plain DBAL: one `SELECT … ORDER BY id FOR UPDATE` across
 * the union of the two axes, which is the whole mechanism — the rows come back in primary-key order, so two
 * concurrent erasures acquire their shared rows in the same sequence and one blocks instead of deadlocking.
 *
 * **The `ORDER BY` is load-bearing, not tidiness.** Postgres promises no stable scan order across plans, and
 * the two axes are served by different indexes (`audit_log_actor_idx` and `audit_log_resource_idx`), so the
 * union is a bitmap-or whose output order is a planner decision. Without the clause two callers can walk the
 * same rows in opposite directions and reproduce exactly the cycle this class exists to prevent.
 *
 * **The result is fetched, not merely executed.** Row locks are taken as rows are produced, so leaving them
 * unread would make the guarantee depend on the driver's buffering. `fetchFirstColumn()` materialises the
 * set and makes the lock unconditional; the ids themselves are discarded, because the anonymisers re-derive
 * their own row sets from their own predicates — this class fixes the ORDER, never the membership.
 *
 * **Why no `AND` narrowing by `resource_erased`/`actor_erased`.** Excluding already-anonymised rows would
 * make the two callers' lock sets diverge, which is the same defect in a new place: the set has to be a
 * function of the subject alone. Idempotency stays where it already is, in the anonymisers' own predicates.
 *
 * What it does NOT cover: rows another transaction commits between this lock and the anonymisers' UPDATEs.
 * Under `READ COMMITTED` those are visible to the UPDATE and were never locked here, and they CAN still form a
 * cycle — two erasures whose locked sets did not overlap can each acquire one of a newly committed reciprocal
 * pair from their own statements, in opposite orders. "A committed row is held by nobody" is true at commit
 * time and beside the point, because each erasure then acquires it. What keeps that unreachable is not this
 * class: it is the administrator refusal, since a reciprocal pair requires both subjects to have acted on each
 * other as administrators, and `AdministratorErasureRequiresDemotion` refuses to erase an identity that still
 * carries the role. Read this as the boundary of the guarantee — a lock over the erasure's own pair of
 * statements, never a claim about the table.
 */
#[AsAlias(AuditSubjectRowLock::class)]
final readonly class DbalAuditSubjectRowLock implements AuditSubjectRowLock
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function lock(AuditResource $subject): void
    {
        $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT id
                FROM audit_log
                WHERE actor_id = CAST(:subject_id AS UUID)
                   OR (resource_type = :resource_type AND resource_id = CAST(:subject_id AS UUID))
                ORDER BY id
                FOR UPDATE
                SQL,
            [
                'subject_id' => $subject->id,
                'resource_type' => $subject->type,
            ],
        );
    }
}
