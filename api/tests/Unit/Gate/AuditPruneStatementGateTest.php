<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ApiSourceFiles;
use Erpify\Tests\Support\PhpSource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static gate over the prune's two statements on `audit_log` — the one `DELETE`, and the `UPDATE` that clears
 * the request metadata of the rows it exempts — for the one clause of each no behavioural test reaches:
 * `FOR UPDATE`.
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
 * Nothing else goes red on deleting the clause: the functional cases assert which rows survive and which are
 * cleared, which the inner `WHERE` decides on its own, and lock ORDER is only observable against a concurrent
 * contender. The `UPDATE` carries no `LIMIT`, so there is no blocking node to discard its order — but it is
 * still a sublink the planner may turn into a semi-join probed in hash order, so the lock is what orders it.
 * This is the weakest instrument in the repo and it is used here for the same reason
 * {@see AdministratorSetLockStatementGateTest} uses it. What proves `LockRows` actually appears where the
 * argument needs it is `AuditPrunePlanFunctionalTest`, against real Postgres.
 *
 * **What a green proves:** `src` deletes from `audit_log` in one statement, and that statement and the
 * pruner's metadata clear still carry the ordering and the lock, the clear writes `NULL` rather than the
 * erasure sentinel and stays off rows either erasure pass redacted. Not that the prune runs, not that a caller
 * reaches it, and not which rows the clear's predicate admits — that is `ExemptRowRequestMetadataFunctionalTest`.
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
            $this->statementsOf(self::PRUNER),
            'The prune no longer batches as `… ORDER BY id LIMIT :batch FOR UPDATE`. Both clauses carry the '
            . 'invariant, and the lock is the half that is easy to lose: without it the ordered scan takes no '
            . 'locks at all, the outer DELETE re-locks in the order of whatever node unique-ified the '
            . 'subquery, and the prune is free to deadlock against the actor pass. Nothing else goes red.',
        );
    }

    /**
     * Counted rather than matched once, because the pruner holds two locking statements and a single match
     * would stay green with either of them stripped of its lock.
     */
    #[Test]
    public function theMetadataClearIsOrderedAndLockedAndWritesNull(): void
    {
        $statements = $this->statementsOf(self::PRUNER);

        $this->assertSame(
            2,
            \preg_match_all('/FOR\s+UPDATE/', $statements),
            'The pruner should lock in exactly two statements — the DELETE batch and the metadata clear.',
        );
        $this->assertMatchesRegularExpression(
            '/UPDATE\s+audit_log\s+SET\s+ip\s*=\s*NULL,\s*user_agent\s*=\s*NULL\s+WHERE\s+id\s+IN\s+\(/',
            $statements,
            'The metadata clear no longer writes NULL into both columns through an `id IN (subquery)`. NULL is '
            . 'what says "past retention"; the sentinel says "an erasure axis anonymised this", and a retention '
            . 'statement writing it would manufacture evidence of an erasure that never ran.',
        );
        // The one term no behavioural test can redden: the clear runs after its level's DELETE, which leaves
        // only exempt rows past the threshold, so dropping it changes nothing observable today.
        $this->assertMatchesRegularExpression(
            '/AND\s+action\s+IN\s+\(:exemptActions\)/',
            $statements,
            'The metadata clear no longer limits itself to the exempt actions. It would then clear any row a '
            . 'short DELETE batch left behind instead of only the ones retention keeps on purpose.',
        );
        $this->assertMatchesRegularExpression(
            '/actor_erased\s*=\s*FALSE/',
            $statements,
            'The metadata clear no longer skips rows the actor-axis erasure resolved, so it would overwrite '
            . "that pass's sentinel — its only evidence on the row — with NULL.",
        );
        $this->assertMatchesRegularExpression(
            '/NOT\s+\(actor_type\s*=\s*:anonymous\s+AND\s+resource_erased\)/',
            $statements,
            'The metadata clear no longer skips rows the resource-axis erasure redacted — anonymous-actor rows '
            . "with `resource_erased` — so it would overwrite that pass's sentinel, its only evidence, with NULL.",
        );
        $this->assertMatchesRegularExpression(
            "/'ORDER\\s+BY\\s+id\\s+FOR\\s+UPDATE'/",
            $statements,
            'The metadata clear no longer selects its rows as `… ORDER BY id FOR UPDATE`. Without the lock the '
            . 'ordered scan takes nothing and the outer UPDATE locks in whatever order the planner probes; '
            . 'without the order, the lock set is acquired in scan order. Either way the clear can deadlock '
            . 'against the actor-axis erasure, which acquires ascending by `id`.',
        );
        $this->assertStringNotContainsString(
            'AuditRedaction::SENTINEL',
            $statements,
            'The pruner binds the erasure sentinel. It is reserved to the two erasure axes.',
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

    /**
     * The pruner's source with its comments stripped, so a docblock quoting a clause is never mistaken for the
     * statement that carries it.
     */
    private function statementsOf(string $relativePath): string
    {
        $path = \realpath(ApiSourceFiles::root() . '/' . $relativePath);

        $this->assertIsString($path, 'The pruner this gate reads has moved; the gate is asserting nothing.');

        $source = \file_get_contents($path);

        $this->assertIsString($source, 'The pruner source could not be read; the gate is asserting nothing.');

        return PhpSource::withoutComments($source);
    }
}
