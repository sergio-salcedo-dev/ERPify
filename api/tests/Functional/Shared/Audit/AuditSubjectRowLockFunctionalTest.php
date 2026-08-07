<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogWriter;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditSubjectRowLock;
use Erpify\Shared\Uuid\Domain\Uuid;
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
 * **How the lock is observed.** A second, independent connection asks for each row with `FOR UPDATE NOWAIT`.
 * A row the locking connection holds answers `55P03 lock_not_available` instead of blocking, so "is this row
 * locked" becomes a question a single-process test can put to Postgres directly.
 *
 * That forces the one deviation from the sibling anonymiser tests, which seed inside a rolled-back
 * transaction: rows written that way are invisible to any other connection, so the probe would find nothing
 * and report every row unlocked. The fixtures are therefore COMMITTED on the probing connection and deleted
 * in a `finally`. Their ids are fixed and namespaced to this class, so a crashed run leaves four identifiable
 * rows rather than anything a later run would trip over.
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
    private const string SUBJECT_ID = '0190f400-0000-7000-8000-0000000000c1';

    private const string OTHER_PERSON_ID = '0190f400-0000-7000-8000-0000000000c2';

    private const string BANK_ID = '0190f400-0000-7000-8000-0000000000c3';

    private const string PERSON_TYPE = 'User';

    /** Committed rows, so they must be removed however the test ends. */
    private ?Connection $outside = null;

    /** @var list<string> */
    private array $seeded = [];

    protected function tearDown(): void
    {
        if ($this->outside instanceof Connection) {
            if ([] !== $this->seeded) {
                $this->outside->executeStatement(
                    'DELETE FROM audit_log WHERE id IN (:ids)',
                    ['ids' => $this->seeded],
                    ['ids' => ArrayParameterType::STRING],
                );
            }

            $this->outside->close();
            $this->outside = null;
            $this->seeded = [];
        }

        parent::tearDown();
    }

    #[Test]
    public function itLocksBothAxesOfTheSubjectAndNothingElse(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $writer = new DbalAuditLogWriter($this->outsideConnection());
            $subject = ActorContext::forUser(self::SUBJECT_ID);
            $other = ActorContext::forUser(self::OTHER_PERSON_ID);

            // The subject only as RESOURCE: another administrator acted on them. This row is the one a lock
            // written against `actor_id` alone would leave free, and it is exactly half of the reciprocal
            // pair the deadlock needs.
            $namedRow = $this->seed($writer, $other, self::PERSON_TYPE, self::SUBJECT_ID);
            // The subject only as ACTOR: they acted on somebody else.
            $authoredRow = $this->seed($writer, $subject, self::PERSON_TYPE, self::OTHER_PERSON_ID);
            // The subject as actor over a non-person resource — still theirs, still on the actor axis.
            $bankRow = $this->seed($writer, $subject, 'Bank', self::BANK_ID);
            // Neither axis names the subject.
            $foreignRow = $this->seed($writer, $other, self::PERSON_TYPE, self::OTHER_PERSON_ID);

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

    #[Test]
    public function theLockSurvivesTheStatementThatTookIt(): void
    {
        // A lock released at the end of its own statement protects nothing: the two UPDATEs it exists to
        // order both run afterwards. `FOR UPDATE` is what holds it to the transaction, and dropping that
        // clause turns the statement into a plain read that no other assertion here would notice.
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $row = $this->seed(
                new DbalAuditLogWriter($this->outsideConnection()),
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

    /**
     * Asks a SECOND connection to take the row. `NOWAIT` turns "somebody else holds it" into an immediate
     * `55P03` rather than a hang, which is the only reason this can run in one process.
     */
    private function isLocked(string $auditLogId): bool
    {
        $probe = $this->outsideConnection();
        $probe->beginTransaction();

        try {
            $probe->fetchOne(
                'SELECT id FROM audit_log WHERE id = CAST(:id AS UUID) FOR UPDATE NOWAIT',
                ['id' => $auditLogId],
            );

            return false;
        } catch (DriverException $driverException) {
            $this->assertSame('55P03', $driverException->getSQLState(), 'the probe failed for an unrelated reason');

            return true;
        } finally {
            if ($probe->isTransactionActive()) {
                $probe->rollBack();
            }
        }
    }

    /**
     * A connection of its own, built from the kernel's parameters, reused for seeding and probing. The
     * container's connection is the one holding the lock, so probing with it would always succeed and prove
     * nothing — and seeding on it would leave the fixtures uncommitted and invisible to any probe.
     */
    private function outsideConnection(): Connection
    {
        if (!$this->outside instanceof Connection) {
            $entityManager = self::getContainer()->get(EntityManagerInterface::class);
            $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

            $this->outside = DriverManager::getConnection($entityManager->getConnection()->getParams());
        }

        return $this->outside;
    }

    private function seed(
        DbalAuditLogWriter $writer,
        ActorContext $actor,
        string $resourceType,
        string $resourceId,
    ): string {
        $entry = AuditLogEntry::create(
            'USER_ROLES_CHANGED',
            AuditLevel::SECURITY,
            $actor,
            Uuid::generate(),
            new DateTimeImmutable('2026-07-01T10:00:00+00:00'),
            AuditResource::of($resourceType, $resourceId),
            [],
            '203.0.113.7',
            'Mozilla/5.0',
        );
        $writer->write($entry);
        $this->seeded[] = $entry->id;

        return $entry->id;
    }

    private function inRolledBackTransaction(callable $body): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $body($connection);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
