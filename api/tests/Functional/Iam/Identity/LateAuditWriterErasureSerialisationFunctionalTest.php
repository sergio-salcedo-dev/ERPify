<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Application\RecordLockoutAuditBestEffort;
use Erpify\Iam\Identity\Application\RecordRecoveryThrottleAuditBestEffort;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\DataFixtures\UserFixtureFactory;
use Erpify\Tests\Functional\Iam\Identity\Fixtures\SubjectRowLockProbingAuditLogger;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Erpify\Tests\Unit\Iam\Identity\Application\FixedRecoveryThrottleAuditBudget;
use Erpify\Tests\Unit\Iam\Identity\Application\RecordingLogger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * The two audit writers that name a person OUTSIDE any use case — the lockout projection, post-commit, and the
 * recovery-throttle projection, from `kernel.terminate` — each serialise on the subject's `identity_user` row,
 * so neither can commit a row naming the subject after an erasure's pass over the trail has run.
 *
 * What a unit double cannot say is that the wait is real, so this asks Postgres. The erasure's hold on the row is
 * played by a SECOND connection taking `SELECT … FOR UPDATE` on it and keeping its transaction open: that is
 * exactly the lock `FulfilIdentityErasure` holds from `holdsAdministratorRoleForUpdate()` to its commit. A
 * `lock_timeout` on the writers' connection turns "waits for the erasure" into an outcome a single process can
 * assert — `55P03`, swallowed, and no row — rather than a hung suite.
 *
 * The fixtures are COMMITTED, because the second connection has to see and lock the subject; `tearDown()` removes
 * the identity and every row these writers added, however the test ends.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(RecordLockoutAuditBestEffort::class)]
#[CoversClass(RecordRecoveryThrottleAuditBestEffort::class)]
final class LateAuditWriterErasureSerialisationFunctionalTest extends KernelTestCase
{
    use ResolvesContainerServices;

    private const array ACTIONS = ['USER_LOCKED', 'PASSWORD_RECOVERY_THROTTLED'];

    private EntityManagerInterface $entityManager;

    private ?Connection $outside = null;

    private string $subjectId;

    private string $subjectEmail;

    private RecordingLogger $logger;

    /** @var list<string> the writers' rows already present, so only the ones this test adds are removed */
    private array $rowsBefore = [];

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = $this->service(EntityManagerInterface::class);
        $this->subjectId = Uuid::generate();
        $this->subjectEmail = 'late-audit-' . $this->subjectId . '@erpify.test';
        $this->logger = new RecordingLogger();

        $this->rowsBefore = $this->writerRowIds();
        $this->seedCommittedSubject();
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

        $added = \array_values(\array_diff($this->writerRowIds(), $this->rowsBefore));

        if ([] !== $added) {
            $outside->executeStatement(
                'DELETE FROM audit_log WHERE id IN (:ids)',
                ['ids' => $added],
                ['ids' => ArrayParameterType::STRING],
            );
        }

        $outside->executeStatement(
            'DELETE FROM identity_user WHERE id = CAST(:id AS UUID)',
            ['id' => $this->subjectId],
        );
        $outside->close();
        $this->outside = null;
        parent::tearDown();
    }

    /**
     * The positive control: with nobody holding the row, both writers name the subject. Without it the two
     * negative cases below would pass just as well over writers that never write.
     */
    #[Test]
    public function bothWritersNameALiveSubject(): void
    {
        $this->runBothWriters();

        $this->assertSame([], $this->logger->records, 'neither writer reported a failure');
        $this->assertEqualsCanonicalizing(self::ACTIONS, $this->actionsNamingTheSubject());
    }

    /**
     * "Locked, then written" holds just as well when the lock is taken in one unit of work and released before
     * the INSERT runs in a second — the shape that reopens the window an erasure slips through. Only asking a
     * second connection at the instant of the write separates the two: `55P03` there means the writer's own
     * transaction still holds the subject row while it writes.
     */
    #[Test]
    public function eachWriterWritesWhileItsOwnTransactionStillHoldsTheSubjectRow(): void
    {
        $probing = new SubjectRowLockProbingAuditLogger(
            $this->service(AuditLogger::class),
            $this->outsideConnection(),
            $this->subjectId,
        );

        $this->runBothWriters($probing);

        $this->assertSame([], $this->logger->records, 'neither writer reported a failure');
        $this->assertSame(
            [
                ['action' => 'USER_LOCKED', 'rowHeld' => true],
                ['action' => 'PASSWORD_RECOVERY_THROTTLED', 'rowHeld' => true],
            ],
            $probing->writes,
            'each write ran while the subject row was locked by the transaction that makes it',
        );
    }

    #[Test]
    public function aWriterWaitsForTheTransactionHoldingTheSubjectRowAndWritesNothingMeanwhile(): void
    {
        $outside = $this->outsideConnection();
        $outside->beginTransaction();
        $outside->fetchOne(
            'SELECT id FROM identity_user WHERE id = CAST(:id AS UUID) FOR UPDATE',
            ['id' => $this->subjectId],
        );

        $writers = $this->entityManager->getConnection();
        $writers->executeStatement("SET lock_timeout = '300ms'");

        try {
            $this->runBothWriters();
        } finally {
            $writers->executeStatement('RESET lock_timeout');
        }

        // Anti-vacuity: each writer must have been REFUSED THE LOCK, not failed for some other reason and not
        // skipped the lock altogether — a writer that never waits would have written its row and logged nothing.
        $this->assertCount(2, $this->logger->records, 'both writers met the held row and reported it');

        foreach ($this->logger->records as $record) {
            $this->assertSame(LogLevel::ERROR, $record['level']);
            $this->assertSame('55P03', $this->sqlStateOf($record['context']['exception'] ?? null));
            $this->assertStringNotContainsString($this->subjectId, $record['message']);
            $this->assertStringNotContainsStringIgnoringCase($this->subjectEmail, $record['message']);
        }

        $outside->rollBack();

        $this->assertSame([], $this->actionsNamingTheSubject(), 'no row named the subject while it was held');
    }

    #[Test]
    public function aWriterArrivingAfterTheErasureCommittedNamesNobody(): void
    {
        $this->outsideConnection()->executeStatement(
            'DELETE FROM identity_user WHERE id = CAST(:id AS UUID)',
            ['id' => $this->subjectId],
        );
        $throttledWithoutSubjectBefore = $this->throttledRowsWithoutSubject();

        $this->runBothWriters();

        $this->assertSame([], $this->logger->records, 'an erased subject is an outcome, not a failure');
        $this->assertSame([], $this->actionsNamingTheSubject(), 'no row names the erased subject');
        // Anti-vacuity on the throttle side: it still ran and still reported the throttle — as a row for an
        // address that names nobody — so the absence above is the lock's answer, not a writer that stood down.
        $this->assertSame($throttledWithoutSubjectBefore + 1, $this->throttledRowsWithoutSubject());
    }

    private function runBothWriters(?AuditLogger $auditLogger = null): void
    {
        $users = $this->service(UserRepository::class);
        $transactionManager = $this->service(TransactionManager::class);
        $auditLogger ??= $this->service(AuditLogger::class);

        (new RecordLockoutAuditBestEffort($users, $transactionManager, $auditLogger, $this->logger))
            ->record($this->subjectId)
        ;
        (new RecordRecoveryThrottleAuditBestEffort(
            new FixedRecoveryThrottleAuditBudget(granted: true),
            $users,
            $transactionManager,
            $auditLogger,
            $this->logger,
        ))->record($this->subjectEmail);
    }

    /**
     * @return list<string>
     */
    private function actionsNamingTheSubject(): array
    {
        /** @var list<string> */
        return $this->outsideConnection()->fetchFirstColumn(
            'SELECT action FROM audit_log WHERE resource_id = CAST(:id AS UUID)',
            ['id' => $this->subjectId],
        );
    }

    private function throttledRowsWithoutSubject(): int
    {
        $count = $this->outsideConnection()->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'PASSWORD_RECOVERY_THROTTLED' AND resource_id IS NULL",
        );
        $this->assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * @return list<string>
     */
    private function writerRowIds(): array
    {
        /** @var list<string> */
        return $this->outsideConnection()->fetchFirstColumn(
            'SELECT id::text FROM audit_log WHERE action IN (:actions)',
            ['actions' => self::ACTIONS],
            ['actions' => ArrayParameterType::STRING],
        );
    }

    /**
     * The transaction manager may translate a lock timeout into a marker of its own; the SQLSTATE survives on the
     * driver exception somewhere down the `previous` chain.
     */
    private function sqlStateOf(mixed $throwable): ?string
    {
        while ($throwable instanceof Throwable) {
            if ($throwable instanceof DriverException) {
                return $throwable->getSQLState();
            }

            $throwable = $throwable->getPrevious();
        }

        return null;
    }

    private function seedCommittedSubject(): void
    {
        $user = UserFixtureFactory::create($this->subjectId, $this->subjectEmail, 'late-audit-password');
        $user->pullDomainEvents();
        // `save()` flushes; only the identity map needs clearing so the writers re-read the row.
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
