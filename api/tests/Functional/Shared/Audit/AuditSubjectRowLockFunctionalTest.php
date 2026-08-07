<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditSubjectRowLock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the two-axis row lock against REAL Postgres, because every clause of it is load-bearing and none of
 * them is observable from a double. The class it covers rewrites nothing and returns nothing: deleting the
 * resource half of its `WHERE`, or the `FOR UPDATE`, leaves the whole unit suite green and the erasure's
 * round-trip budget unchanged — a statement matching one axis, both, or none costs the same `+1`. Without
 * this test the docblock is the only thing asserting any of it.
 *
 * **How the lock is observed** — a second connection and a `FOR UPDATE NOWAIT` probe — is
 * {@see ObservesRowLocksOnASecondConnection}, which also owns the committed fixtures and their removal. The
 * fixture ids here are fixed and namespaced to this class, so a crashed run leaves four identifiable rows
 * rather than anything a later run would trip over.
 *
 * Three claims, and each fails for its own reason:
 *
 *  - Rows where the subject is only the RESOURCE are locked — the half a lock written against `actor_id`
 *    alone would miss, and the half that only came to exist when an administrator could act on a user.
 *  - Rows where the subject is only the ACTOR are locked.
 *  - Rows naming a different person are NOT — the predicate is about a subject, not a table lock that would
 *    serialise every erasure in the system against every other.
 *
 * **What it does not prove.** That the rows are ACQUIRED in `id` order. Acquisition order is only observable
 * against a concurrent contender, which needs two transactions racing rather than one holding and one
 * probing. That half rests on the plan shape — Postgres puts `LockRows` above `Sort` — and on the sibling
 * `DoctrineActiveAdministratorDirectory`, which has relied on the same idiom for longer.
 *
 * The LOCKING transaction is rolled back; the fixtures it locks are committed and removed in `tearDown()`,
 * so the shared dev DB is left as it was found by both halves rather than by one.
 *
 * @internal
 */
#[CoversClass(DbalAuditSubjectRowLock::class)]
final class AuditSubjectRowLockFunctionalTest extends KernelTestCase
{
    use ObservesRowLocksOnASecondConnection;

    private const string SUBJECT_ID = '0190f400-0000-7000-8000-0000000000c1';

    private const string OTHER_PERSON_ID = '0190f400-0000-7000-8000-0000000000c2';

    private const string BANK_ID = '0190f400-0000-7000-8000-0000000000c3';

    private const string PERSON_TYPE = 'User';

    protected function tearDown(): void
    {
        $this->releaseCommittedRows();

        parent::tearDown();
    }

    #[Test]
    public function itLocksBothAxesOfTheSubjectAndNothingElse(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $subject = ActorContext::forUser(self::SUBJECT_ID);
            $other = ActorContext::forUser(self::OTHER_PERSON_ID);

            // The subject only as RESOURCE: another administrator acted on them. This row is the one a lock
            // written against `actor_id` alone would leave free, and it is exactly half of the reciprocal
            // pair the deadlock needs.
            $namedRow = $this->seed($other, self::PERSON_TYPE, self::SUBJECT_ID);
            // The subject only as ACTOR: they acted on somebody else.
            $authoredRow = $this->seed($subject, self::PERSON_TYPE, self::OTHER_PERSON_ID);
            // The subject as actor over a non-person resource — still theirs, still on the actor axis.
            $bankRow = $this->seed($subject, 'Bank', self::BANK_ID);
            // Neither axis names the subject.
            $foreignRow = $this->seed($other, self::PERSON_TYPE, self::OTHER_PERSON_ID);

            (new DbalAuditSubjectRowLock($connection))
                ->lock(AuditResource::of(self::PERSON_TYPE, self::SUBJECT_ID))
            ;

            $this->assertTrue($this->isLocked($namedRow), 'the resource axis is outside the lock');
            $this->assertTrue($this->isLocked($authoredRow), 'the actor axis is outside the lock');
            $this->assertTrue($this->isLocked($bankRow), 'a non-person resource does not exempt the actor axis');
            // Without this the whole test is satisfied by a lock over the entire table, which would order the
            // two axes by serialising every erasure against every other one.
            $this->assertFalse($this->isLocked($foreignRow), 'a row naming neither axis of the subject is locked');
        });
    }

    /**
     * The clause the whole fix rests on, and the one nothing else can see: deleting `ORDER BY id` leaves the
     * assertions above green — the same rows are locked, in whatever order the planner picked — and reopens
     * the deadlock. Acquisition order is not observable from a single holder, so this asks the planner
     * instead, over the statement the class actually emits rather than a copy of it kept in a test.
     *
     * The assertion tolerates either shape that ESTABLISHES the order — an explicit `Sort` on `id`, or a scan
     * that already walks the primary key — because which one the planner picks is a function of table
     * statistics, and pinning one would be a test that goes red on an autovacuum rather than on a defect.
     */
    #[Test]
    public function theLockedRowsAreOrderedByIdInThePlan(): void
    {
        $plan = $this->planFor($this->statementTheLockEmits());

        $this->assertStringContainsString('LockRows', $plan, 'the statement no longer locks rows at all');
        $this->assertTrue(
            \str_contains($plan, '"Sort Key": ["id"]') || \str_contains($plan, 'audit_log_pkey'),
            'The lock no longer establishes an order over the rows it takes: the plan has neither a Sort on '
            . '`id` nor a primary-key walk. Two concurrent erasures can then acquire the shared rows in '
            . "opposite directions, which is the deadlock this class exists to prevent — and it is the one \n"
            . 'thing the sibling assertions in this class cannot see. Plan: ' . $plan,
        );
    }

    #[Test]
    public function theLockSurvivesTheStatementThatTookIt(): void
    {
        // A lock released at the end of its own statement protects nothing: the two UPDATEs it exists to
        // order both run afterwards. `FOR UPDATE` is what holds it to the transaction, and dropping that
        // clause turns the statement into a plain read that no other assertion here would notice.
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $row = $this->seed(
                ActorContext::forUser(self::SUBJECT_ID),
                self::PERSON_TYPE,
                self::OTHER_PERSON_ID,
            );

            (new DbalAuditSubjectRowLock($connection))
                ->lock(AuditResource::of(self::PERSON_TYPE, self::SUBJECT_ID))
            ;

            // Several statements later, still held.
            $connection->fetchOne('SELECT 1');
            $connection->fetchOne('SELECT count(*) FROM audit_log');

            $this->assertTrue($this->isLocked($row));
        });
    }
}
