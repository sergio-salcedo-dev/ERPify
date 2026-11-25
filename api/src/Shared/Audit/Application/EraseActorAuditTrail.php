<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Application;

use Erpify\Shared\Audit\Domain\AuditErasureEvidence;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Persistence\Application\TransactionManager;

/**
 * Erases the ACTOR axis of one person's audit trail and records that it did, as one unit.
 *
 * The anonymisation and its compliance self-audit commit together or not at all. They did not: the `UPDATE`
 * committed on its own and the `GDPR_ERASURE_EXECUTED` row was written afterwards, so a failure between the two
 * left the rows anonymised with nothing attesting it — and the original id now matched nothing, so a re-run
 * reported "nothing to erase" over a subject that had in fact been erased. Inside one transaction a failed
 * self-audit rolls the rewrite back, the original id still matches, and the operation is safe to repeat.
 *
 * The transaction is the {@see TransactionManager}'s, never a raw connection call: no savepoint nesting is
 * configured, and a use case that opens its own boundary through the port composes with a caller's.
 *
 * **The zero-rows guard lives HERE, beside the write it defends.** An `UPDATE` that matched nothing is not an
 * erasure, and `AuditErasureEvidence` exempts the self-audit action from the retention prune for ever, so a row
 * written for a subject that was already anonymised would be an immortal claim that an erasure happened. It is
 * reached from a `--force` run, which takes no preview, and from a confirmed run whose rows were pruned or
 * anonymised between the count and the statement.
 *
 * What it cannot rule out is the ambiguity of a connection lost at the `COMMIT` itself: the outcome is then
 * unknown to the caller, who verifies with the command's `--dry-run` (zero rows for the original id means it
 * committed).
 */
final readonly class EraseActorAuditTrail
{
    public function __construct(
        private AuditActorAnonymiser $anonymiser,
        private AuditLogger $auditLogger,
        private TransactionManager $transactionManager,
    ) {
    }

    public function erase(string $actorId): ActorAnonymisationResult
    {
        return $this->transactionManager->transactional(function () use ($actorId): ActorAnonymisationResult {
            $result = $this->anonymiser->anonymise($actorId);

            if (0 === $result->affectedRows) {
                return $result;
            }

            $this->auditLogger->log(AuditErasureEvidence::ACTOR_TRAIL_ERASED, AuditLevel::SECURITY, null, [
                'anonymized_actor_id' => $result->pseudonym,
                'affected_rows' => $result->affectedRows,
            ]);

            return $result;
        });
    }
}
