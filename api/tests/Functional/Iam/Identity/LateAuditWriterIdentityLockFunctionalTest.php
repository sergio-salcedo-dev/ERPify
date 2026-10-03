<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Application\IdentityRowSerialiser;
use Erpify\Iam\Identity\Application\RecordLockoutAuditBestEffort;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DbalIdentityRowLock;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A post-commit audit projection about a person contends on the same `identity_user` row the erasure holds,
 * over the real adapters: the container's recorder, its transaction manager, the DBAL lock and the audit
 * writer.
 *
 * **The erasure's hold is played by a second connection**, because the interleaving cannot be driven to its
 * end from one process — the image carries neither `pcntl` nor the procedural `pgsql` extension, so a writer
 * blocked behind a transaction this process also has to commit would never return. What CAN be asserted here
 * is the half that decides the guarantee: while another transaction holds the subject's row, the writer waits
 * on it rather than inserting past it (a `lock_timeout` turns the wait into an outcome, and the recorder
 * swallows it as it swallows any lost projection). The other half — once the holder deletes the row and
 * commits, the waiting `FOR UPDATE` comes back empty — is Postgres's `READ COMMITTED` re-check, and its
 * observable consequence is the third case: a subject whose row is gone gets no row at all.
 *
 * The subject is COMMITTED, because the second connection has to see it; it and every row written about it
 * are removed by hand in `tearDown()`.
 *
 * @internal
 */
#[CoversClass(IdentityRowSerialiser::class)]
#[CoversClass(DbalIdentityRowLock::class)]
final class LateAuditWriterIdentityLockFunctionalTest extends KernelTestCase
{
    use ResolvesContainerServices;

    /**
     * Short enough to keep the suite fast, long enough that the elapsed-time floor below cannot be met by a
     * writer that never waited.
     */
    private const int LOCK_TIMEOUT_MS = 400;

    private EntityManagerInterface $entityManager;

    private ?Connection $outside = null;

    private string $subjectId;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = $this->service(EntityManagerInterface::class);
        $this->subjectId = Uuid::generate();
    }

    protected function tearDown(): void
    {
        if (!isset($this->entityManager)) {
            parent::tearDown();

            return;
        }

        $outside = $this->outsideConnection();

        if ($outside->isTransactionActive()) {
            $outside->rollBack();
        }

        $this->entityManager->getConnection()->executeStatement('RESET lock_timeout');
        $outside->executeStatement(
            'DELETE FROM audit_log WHERE resource_type = :type AND resource_id = CAST(:id AS UUID)',
            ['type' => FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, 'id' => $this->subjectId],
        );
        $outside->executeStatement(
            'DELETE FROM identity_user WHERE id = CAST(:id AS UUID)',
            ['id' => $this->subjectId],
        );
        $outside->close();
        parent::tearDown();
    }

    #[Test]
    public function aLiveSubjectGetsItsRow(): void
    {
        $this->seedCommittedSubject();

        $this->recorder()->record($this->subjectId);

        $this->assertSame(1, $this->rowsNamingTheSubject(), 'the anti-vacuity half: the path does write');
    }

    #[Test]
    public function aWriterWaitsOnAHeldSubjectRowInsteadOfInsertingPastIt(): void
    {
        $this->seedCommittedSubject();
        $this->holdTheSubjectsRowElsewhere();
        $this->entityManager->getConnection()->executeStatement(
            \sprintf("SET lock_timeout = '%dms'", self::LOCK_TIMEOUT_MS),
        );

        $started = \hrtime(true);
        $this->recorder()->record($this->subjectId);
        $waitedMs = (\hrtime(true) - $started) / 1_000_000;

        $this->assertGreaterThanOrEqual(
            self::LOCK_TIMEOUT_MS * 0.9,
            $waitedMs,
            'the writer returned before the lock timeout, so it never queued behind the held row',
        );
        $this->assertSame(
            0,
            $this->rowsNamingTheSubject(),
            'A row naming the subject committed while another transaction held its identity row — inside an '
            . "erasure that is exactly the row the erasure's passes can no longer see.",
        );

        // Released, the same writer gets through: what stopped it was the hold, not a broken path.
        $this->outsideConnection()->rollBack();
        $this->recorder()->record($this->subjectId);

        $this->assertSame(1, $this->rowsNamingTheSubject());
    }

    #[Test]
    public function aSubjectWhoseRowIsGoneGetsNoRow(): void
    {
        // Never seeded: the shape a writer meets once an erasure it waited on has committed its DELETE.
        $this->recorder()->record($this->subjectId);

        $this->assertSame(0, $this->rowsNamingTheSubject());
    }

    #[Test]
    public function theLockRefusesToRunOutsideATransaction(): void
    {
        $this->seedCommittedSubject();
        $lock = new DbalIdentityRowLock($this->entityManager->getConnection());

        // Postgres releases a statement-level FOR UPDATE as the statement ends, so an answer given here would
        // be true and hold nothing by the time the caller wrote.
        $this->expectException(LogicException::class);

        $lock->lockIfLive($this->subjectId);
    }

    private function recorder(): RecordLockoutAuditBestEffort
    {
        return $this->service(RecordLockoutAuditBestEffort::class);
    }

    private function holdTheSubjectsRowElsewhere(): void
    {
        $outside = $this->outsideConnection();
        $outside->beginTransaction();
        $outside->fetchOne(
            'SELECT 1 FROM identity_user WHERE id = CAST(:id AS UUID) FOR UPDATE',
            ['id' => $this->subjectId],
        );
    }

    private function rowsNamingTheSubject(): int
    {
        $count = $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE resource_type = :type AND resource_id = CAST(:id AS UUID)',
            ['type' => FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, 'id' => $this->subjectId],
        );
        $this->assertIsNumeric($count);

        return (int) $count;
    }

    private function seedCommittedSubject(): void
    {
        $user = User::register(
            $this->subjectId,
            'late-audit-writer-' . $this->subjectId . '@erpify.test',
            HashedPassword::fromHash('hashed-password-placeholder'),
            Role::AUDIT_READER,
        );
        $user->pullDomainEvents();
        $this->service(UserRepository::class)->save($user);
        $this->entityManager->clear();
    }

    private function outsideConnection(): Connection
    {
        if (!$this->outside instanceof Connection) {
            $this->outside = DriverManager::getConnection($this->entityManager->getConnection()->getParams());
        }

        return $this->outside;
    }
}
