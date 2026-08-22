<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ApiSourceFiles;
use Erpify\Tests\Support\PhpSource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static gate over the one statement that deletes from `audit_log`, for the one clause of it no behavioural
 * test reaches: `FOR UPDATE`.
 *
 * The prune is one of a closed set of three mutations on this table — with the actor-axis and resource-axis
 * erasures — and it is the only one that touches rows it did not choose by subject. Of the other two, only
 * {@see \Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditActorAnonymiser} orders its own
 * acquisition (`… ORDER BY id FOR UPDATE`);
 * {@see \Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditResourceAnonymiser} is a bare `UPDATE`
 * with no ordering at all and inherits one from the
 * {@see \Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditSubjectRowLock} taken ahead of it, on the
 * paths that take it. Ascending `id` is therefore the order the prune has to join.
 *
 * **`ORDER BY id` alone does not buy that, which is the trap this gate exists for.** `LIMIT` blocks sublink
 * pull-up, so the outer `DELETE` unique-ifies the subquery through a blocking node that discards its order
 * and then probes in that node's order. `FOR UPDATE` puts `LockRows` above the ordered scan and below it, so
 * the lock set is acquired ascending before the outer statement touches anything. Which node performs the
 * unique-ification is a costed choice, not a contract, and this gate deliberately pins none of it — pinning
 * a plan shape is a test that goes red on an autovacuum rather than on a defect.
 *
 * Nothing else goes red on deleting the clause: both functional cases assert which rows survive, which the
 * exemption in the inner `WHERE` decides on its own, and lock ORDER is only observable against a concurrent
 * contender. This is the weakest instrument in the repo and it is used here for the same reason
 * {@see AdministratorSetLockStatementGateTest} uses it. What proves `LockRows` actually appears where the
 * argument needs it is `AuditPrunePlanFunctionalTest`, against real Postgres.
 *
 * **What a green proves:** `src` deletes from `audit_log` in one statement, and that statement still carries
 * the ordering and the lock. Not that the prune runs, not that a caller reaches it.
 *
 * @internal
 */
#[CoversNothing]
final class AuditPruneStatementGateTest extends TestCase
{
    private const string PRUNER = 'Shared/Audit/Infrastructure/Persistence/DbalAuditLogPruner.php';

    #[Test]
    public function theBatchingSelectIsOrderedAndLocked(): void
    {
        // The clauses are pinned as a sequence: `FOR UPDATE` before `LIMIT` is a syntax error Postgres would
        // reject, but a sort over a statement that locks nothing — the shape that shipped — is silently
        // wrong, and so is a lock over an unordered scan.
        $this->assertMatchesRegularExpression(
            '/ORDER\s+BY\s+id\s+LIMIT\s+:batch\s+FOR\s+UPDATE/',
            $this->sourceOf(self::PRUNER),
            'The prune no longer batches as `… ORDER BY id LIMIT :batch FOR UPDATE`. Both clauses carry the '
            . 'invariant, and the lock is the half that is easy to lose: without it the ordered scan takes no '
            . 'locks at all, the outer DELETE re-locks in the order of whatever node unique-ified the '
            . 'subquery, and the prune is free to deadlock against the actor pass. Nothing else goes red.',
        );
    }

    /**
     * Scoped to `src`, not to the pruner's own file, because the property the closed set needs is that no
     * OTHER production class deletes from this table — a second delete path is how the ordering drifts, and
     * a gate that only reads the file it already trusts cannot see one arriving. Comments are stripped so a
     * docblock quoting the statement is not mistaken for a second one.
     */
    #[Test]
    public function srcDeletesFromTheAuditLogInExactlyOneStatement(): void
    {
        $deleters = [];
        $statements = 0;

        foreach (ApiSourceFiles::phpFiles() as $file) {
            $source = \file_get_contents($file->getPathname());

            if (!\is_string($source)) {
                continue;
            }

            $matches = \preg_match_all('/DELETE\s+FROM\s+audit_log/i', PhpSource::withoutComments($source));

            if (0 === $matches) {
                continue;
            }

            $deleters[] = \substr($file->getPathname(), \strlen(ApiSourceFiles::root()) + 1);
            $statements += $matches;
        }

        $this->assertSame([self::PRUNER], $deleters, \sprintf(
            'Exactly one class in src may delete from audit_log, and it is the pruner. Found: %s. A second '
            . 'delete path makes the closed set of three mutations four, with a member nobody ordered — and '
            . "it also ends a concurrent sweep early, because a row deleted under the pruner's FOR UPDATE "
            . 'drops out of its batch with no replacement.',
            \implode(', ', $deleters),
        ));
        $this->assertSame(1, $statements, 'The pruner issues more than one DELETE, so the two can drift.');
    }

    private function sourceOf(string $relativePath): string
    {
        $path = \realpath(ApiSourceFiles::root() . '/' . $relativePath);

        $this->assertIsString($path, 'The pruner this gate reads has moved; the gate is asserting nothing.');

        $source = \file_get_contents($path);

        $this->assertIsString($source, 'The pruner source could not be read; the gate is asserting nothing.');

        return $source;
    }
}
